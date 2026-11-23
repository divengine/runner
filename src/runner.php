<?php

declare(strict_types=1);

/**
 * Divengine PHP Runner.
 *
 * Lightweight job runner for flow functions that share a mutable context array.
 * Flow/activity/condition files are imported by path and must return callables.
 *
 * @package divengine/runner
 * @author  Rafa Rodriguez @rafageist
 * @link    https://github.com/divengine/runner
 */

namespace divengine\runner;

use DateTimeImmutable;
use DateTimeInterface;
use InvalidArgumentException;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use ReflectionNamedType;
use RuntimeException;
use Throwable;

defined('DIV_RUNNER_ROOT_FOLDER') or define('DIV_RUNNER_ROOT_FOLDER', './');

final class runner
{
    /**
     * Optional logger sink set via `setLogger()`.
     *
     * Signature: `function(string $level, string $message, array $context = []): void`
     *
     * @var callable|null
     */
    private static $loggerSink = null;

    /**
     * Default limit of nested calls, overridable with `_max_call_depth`.
     */
    public const MAX_CALL_DEPTH = 256;

    /**
     * Identifier and version of the trace document returned by `runTraced()`
     * and `simulate()`. See docs/tracing-and-simulation.md.
     */
    public const TRACE_PROTOCOL = "divengine.runner.trace";
    public const TRACE_VERSION = 1;

    /**
     * Trace caps and value capture defaults.
     */
    private const TRACE_DEFAULTS = [
        "max_events" => 10000,
        "max_bytes" => 4194304,
        "max_string" => 1024,
        "max_items" => 100,
        "max_depth" => 8,
        "max_nodes" => 2000,
        "capture_values" => true,
        "capture_messages" => false,
        "redact_keys" => [],
        "exclude_keys" => [],
    ];

    /**
     * Simulation budget defaults: entered steps, control transfers (calls and
     * jumps) and wall-clock seconds of one simulated invocation.
     */
    /**
     * Smallest accepted `max_bytes`. A quarter of `max_bytes` is held back for
     * the forced events (`budget.exhausted`, `run.end`, `trace.truncated`),
     * and their compact forms always fit in it.
     */
    private const TRACE_MIN_BYTES = 8192;

    /**
     * Events a trace keeps even when full, once each.
     */
    private const TRACE_FORCED = ["budget.exhausted", "run.end", "trace.truncated"];

    /**
     * Event fields a trace replaces with a marker when an event does not fit
     * in the bytes left; the compact form of a forced event also drops these.
     */
    private const TRACE_BULKY = ["context", "changes", "result", "value"];
    private const TRACE_BULKY_FORCED = ["checkpoint"];

    private const BUDGET_DEFAULTS = ["steps" => 10000, "transfers" => 10000, "seconds" => 10.0];

    /**
     * Keys whose values a trace replaces with a redaction marker, at any depth.
     */
    private const REDACT_PATTERN = '/password|passwd|passphrase|secret|token|api[-_]?key|access[-_]?key|private[-_]?key'
        . '|authorization|bearer|credential|cookie|session[-_]?id|signature|cvv|card[-_]?number/i';

    /**
     * Trace (and simulation) state of the innermost traced `run()`, or null.
     * Kept outside the context so that no trace state can be saved with it.
     *
     * @var array<string, mixed>|null
     */
    private static ?array $trace = null;

    /**
     * Keys describing where the running flow is; restored after any activity.
     */
    private const SCOPE_KEYS = ["_current_block_id", "_current_step_id", "_current_step_index", "_flow_stack", "_flow_blocks"];

    /**
     * Keys an activity may set on purpose; restored only after a nested pause.
     */
    private const FLOW_CONTROL_KEYS = ["_flow_state", "_autoresume"];

    /**
     * Keys accepted in a YAML flow definition (root, block and step level).
     */
    private const FLOW_KEYS = ["id", "description", "blocks"];
    private const BLOCK_KEYS = ["description", "steps"];
    private const STEP_KEYS = ["description", "module", "condition", "context", "call", "activity", "jump", "pause"];

    /**
     * Module names: letters, digits, `_` and `-`, not starting with `-`.
     */
    private const MODULE_PATTERN = '/^[A-Za-z0-9_][A-Za-z0-9_-]*$/';

    /**
     * Helpers the runner puts in the context for the duration of a run.
     */
    private const HELPER_KEYS = [
        "_logger", "_importer", "_update_context", "_wrap_condition", "_wrap_activity", "_wrap_call", "_wrap_jump",
        "_wrap_pause", "_check_pause", "_resume_after_call", "__flow_loop",
    ];

    /**
     * Context keys owned by the runner; a block id must not map onto them.
     */
    private const RESERVED_KEYS = [
        "_root_folder", "_logger", "_importer", "_update_context", "_wrap_condition", "_wrap_activity", "_wrap_call",
        "_wrap_jump", "_wrap_pause", "_check_pause", "_resume_after_call", "__flow_loop", "_exception_pause",
        "_exception_jump", "_exception_pause_token", "_exception_jump_token", "_flow_state", "_flow_stack",
        "_flow_depth", "_nested_flow_paused", "_current_block_id", "_current_step_id", "_current_step_index",
        "_autoresume", "_max_call_depth", "_flow_blocks", "_modules",
    ];

    /**
     * Sets a global logger callback used when no per-run logger is provided.
     *
     * @param callable|null $logger
     */
    public static function setLogger(?callable $logger): void
    {
        self::$loggerSink = $logger;
    }

    /**
     * Imports a function by name, optionally from a module.
     *
     * Without a module, `$name` resolves to the first match of:
     * 1. `<root>/<name>.php`, where root is `_root_folder` from `$context`, else `DIV_RUNNER_ROOT_FOLDER`;
     * 2. `<name>.php` as a path (absolute or relative to the working directory);
     * 3. a callable name such as `strlen` or `vendor\package\fn`.
     *
     * With a module, `$name` resolves only to `<module folder>/<name>.php`, where the
     * module folder is `_modules[<module>]` from `$context`, else `<root>/<module>`.
     * Nothing outside the module folder is tried, so equal function names in
     * different modules never collide. Inside a module, `$name` is a relative
     * name (`compute`, `tax/compute`) without `.` or `..` segments.
     *
     * The `.php` extension is optional; a file must return a callable.
     *
     * @param string               $name    Function name, path or callable name.
     * @param string|null          $module  Module name, or null for an unqualified reference.
     * @param array<string, mixed> $context Context read for `_root_folder` and `_modules`.
     * @param callable|null        $logger  Defaults to the logger set with `setLogger()`.
     *
     * @return callable|null Null, with an error logged, when nothing resolves.
     */
    public static function importer(
        string $name,
        ?string $module = null,
        array $context = [],
        ?callable $logger = null
    ): ?callable {
        return self::importWithSource($name, $module, $context, $logger)[0];
    }

    /**
     * `importer()` that also returns the file a callable was loaded from.
     *
     * @param array<string, mixed> $context
     *
     * @return array{0: ?callable, 1: ?string}
     */
    private static function importWithSource(string $name, ?string $module, array $context, ?callable $logger): array
    {
        $logger = $logger ?? self::$loggerSink;

        $reference = trim($name);
        if ($reference === "") {
            self::emitLog($logger, "ERROR", "Import name is empty.");
            return [null, null];
        }

        if ($module !== null) {
            $problem = self::moduleReferenceProblem($module, $reference);
            if ($problem !== null) {
                self::emitLog($logger, "ERROR", "Invalid module reference: {$problem}");
                return [null, null];
            }
            $folder = self::resolveModuleFolder($module, $context);
            $candidates = [self::ensurePhpExtension(self::joinPath($folder, $reference))];
        } else {
            $candidates = self::buildImportPathCandidates($reference, $context);
        }

        foreach ($candidates as $candidate) {
            if (!is_file($candidate)) {
                continue;
            }

            try {
                $func = require $candidate;
            } catch (Throwable $e) {
                self::emitLog($logger, "ERROR", "Error requiring file: {$candidate}", [
                    "exception" => self::dumpException($e),
                ]);
                return [null, null];
            }

            if (!is_callable($func)) {
                self::emitLog($logger, "ERROR", "Import file must return a callable: {$candidate}");
                return [null, null];
            }

            return [$func, $candidate];
        }

        $callableReference = ltrim($reference, "\\");
        if ($module === null && is_callable($callableReference)) {
            return [$callableReference, null];
        }

        $label = self::qualifiedName($reference, $module);
        self::emitLog($logger, "ERROR", "Import target not found or not callable: {$label}");
        return [null, null];
    }

    /**
     * Generates the PHP source of a flow closure from a YAML flow file.
     *
     * The output is deterministic and is a valid flow file body (prefix it with
     * `<?php`). Options:
     * - `function_map`: `["<qualified name>" => "<qualified name>"]` renames
     *   activity and condition references at compile time. A qualified name is
     *   `module:name` for a step with a `module`, else the plain name.
     *
     * @param string               $yamlPath
     * @param array<string, mixed> $options
     *
     * @return string
     */
    public static function generateFlowCodeFromYaml(string $yamlPath, array $options = []): string
    {
        $flowDef = self::parseYamlFlowFile($yamlPath);
        self::validateFlowSchema($flowDef, $yamlPath);
        $blocks = self::normalizeFlowBlocks($flowDef);
        self::validateFlowBlocks($blocks);

        $initialBlock = $blocks[0]["id"];
        $stepIndexMap = self::buildStepIndexMap($blocks);
        $functionMap = self::normalizeFunctionMap($options["function_map"] ?? []);

        $helpers = [
            "flowLoop" => "__flow_loop",
            "importer" => "_importer",
            "checkPause" => "_check_pause",
            "updateContext" => "_update_context",
            "wrapCondition" => "_wrap_condition",
            "wrapActivity" => "_wrap_activity",
            "wrapCall" => "_wrap_call",
            "wrapJump" => "_wrap_jump",
            "wrapPause" => "_wrap_pause",
            "resumeAfterCall" => "_resume_after_call",
        ];

        $lines = [];
        $lines[] = "return function (array &\$context): void {";
        foreach ($helpers as $variable => $key) {
            $lines[] = "    \${$variable} = \$context[\"{$key}\"] ?? null;";
        }
        $lines[] = "";
        $checks = array_map(fn (string $variable): string => "!is_callable(\${$variable})", array_keys($helpers));
        $lines[] = "    if (" . implode(" || ", $checks) . ") {";
        $lines[] = "        throw new \\RuntimeException(\"Missing flow helpers in context: run this flow with runner::run().\");";
        $lines[] = "    }";
        $lines[] = "";
        $lines[] = "    \$resolveFunction = function (string \$name, ?string \$module) use (\$importer): callable {";
        $lines[] = "        \$callable = \$importer(\$name, \$module);";
        $lines[] = "        if (!is_callable(\$callable)) {";
        $lines[] = "            throw new \\RuntimeException(\"Function import failed: \" . (\$module === null ? \$name : \$module . \":\" . \$name));";
        $lines[] = "        }";
        $lines[] = "";
        $lines[] = "        return \$callable;";
        $lines[] = "    };";
        $lines[] = "";

        $blockListCode = var_export(array_column($blocks, "id"), true);
        $lines[] = "    \$context[\"_flow_blocks\"] = " . str_replace("\n", " ", $blockListCode) . ";";

        foreach ($blocks as $block) {
            $blockId = $block["id"];
            $blockIdCode = var_export($blockId, true);
            $contextBlockKeyCode = var_export("_" . $blockId, true);
            $lines[] = "";
            $lines[] = "    \$context[{$contextBlockKeyCode}] = function (array &\$context) use (";
            $lines[] = "        \$checkPause, \$updateContext, \$wrapCondition, \$wrapActivity, \$wrapCall, \$wrapJump, \$wrapPause,";
            $lines[] = "        \$resumeAfterCall, \$resolveFunction";
            $lines[] = "    ): void {";

            foreach ($block["steps"] as $stepPosition => $step) {
                $stepIdx = $stepPosition + 1;
                $stepIdCode = var_export($step["id"], true);
                $module = isset($step["module"]) && is_string($step["module"]) ? trim($step["module"]) : null;
                $lines[] = "        if (\$checkPause({$stepIdx}, \$context, {$stepIdCode})) {";
                $indent = "            ";

                $conditionRef = isset($step["condition"]) && is_string($step["condition"])
                    ? trim($step["condition"])
                    : "";
                $callRef = isset($step["call"]) && is_string($step["call"]) ? trim($step["call"]) : "";
                $hasCall = $callRef !== "";

                // A step with a call is re-entered after the call returns from a
                // resumed pause: condition, context update and call already ran,
                // so only activity, jump and pause remain.
                if ($hasCall) {
                    $lines[] = "            \$afterCall = \$resumeAfterCall({$stepIdx}, \$context);";
                }

                if ($conditionRef !== "") {
                    $resolveCode = self::resolveFunctionCode($conditionRef, $module, $functionMap);
                    $guard = $hasCall ? "\$afterCall || " : "";
                    $lines[] = "            if ({$guard}\$wrapCondition({$resolveCode}, {$stepIdCode}, {$stepIdx}, \$context)) {";
                    $indent = "                ";
                }

                $beforeCallIndent = $indent;
                if ($hasCall) {
                    $lines[] = "{$indent}if (!\$afterCall) {";
                    $beforeCallIndent = $indent . "    ";
                }

                if (isset($step["context"]) && is_array($step["context"])) {
                    $stepContextCode = var_export($step["context"], true);
                    $lines[] = "{$beforeCallIndent}\$updateContext(\$context, {$stepContextCode}, {$stepIdCode}, {$stepIdx});";
                }

                if ($hasCall) {
                    [$targetBlock, $targetIndex] = self::resolveBlockTarget($callRef, $blockId, $stepIndexMap, "call");
                    $targetBlockCode = var_export($targetBlock, true);
                    $lines[] = "{$beforeCallIndent}\$wrapCall({$stepIdCode}, {$stepIdx}, \$context, {$targetBlockCode}, {$targetIndex}, {$blockIdCode});";
                    $lines[] = "{$indent}}";
                }

                $activityRef = isset($step["activity"]) && is_string($step["activity"])
                    ? trim($step["activity"])
                    : "";
                if ($activityRef !== "") {
                    $resolveCode = self::resolveFunctionCode($activityRef, $module, $functionMap);
                    $lines[] = "{$indent}\$wrapActivity({$resolveCode}, {$stepIdCode}, {$stepIdx}, \$context);";
                }

                $jumpRef = isset($step["jump"]) && is_string($step["jump"]) ? trim($step["jump"]) : "";
                if ($jumpRef !== "") {
                    [$targetBlock, $targetIndex] = self::resolveBlockTarget($jumpRef, $blockId, $stepIndexMap, "jump");
                    $targetBlockCode = var_export($targetBlock, true);
                    $lines[] = "{$indent}\$wrapJump({$stepIdCode}, {$stepIdx}, \$context, {$targetBlockCode}, {$targetIndex});";
                }

                if (($step["pause"] ?? false) === true) {
                    $lines[] = "{$indent}\$wrapPause({$blockIdCode}, {$stepIdCode}, {$stepIdx}, \$context);";
                }

                if ($conditionRef !== "") {
                    $lines[] = "            }";
                }

                $lines[] = "        }";
                $lines[] = "";
            }

            $lines[] = "    };";
        }

        $initialBlockCode = var_export($initialBlock, true);
        $lines[] = "";
        $lines[] = "    \$flowLoop({$initialBlockCode}, \$context);";
        $lines[] = "};";

        return implode("\n", $lines);
    }

    /**
     * PHP code that resolves an activity or condition reference, after `function_map`.
     *
     * @param array<string, array{0:string,1:string|null}> $functionMap
     */
    private static function resolveFunctionCode(string $name, ?string $module, array $functionMap): string
    {
        $qualified = self::qualifiedName($name, $module);
        if (isset($functionMap[$qualified])) {
            [$name, $module] = $functionMap[$qualified];
        }

        return "\$resolveFunction(" . var_export($name, true) . ", " . var_export($module, true) . ")";
    }

    /**
     * @return array<string, array{0:string,1:string|null}>
     */
    private static function normalizeFunctionMap(mixed $map): array
    {
        if (!is_array($map)) {
            throw new RuntimeException("[divengine.runner] Option 'function_map' must be an array.");
        }

        $normalized = [];
        foreach ($map as $from => $to) {
            if (!is_string($to) || trim($to) === "") {
                throw new RuntimeException(
                    "[divengine.runner] Option 'function_map' entry '{$from}' must map to a non-empty string."
                );
            }
            $normalized[trim((string) $from)] = self::parseQualifiedName(trim($to));
        }

        return $normalized;
    }

    /**
     * Splits `module:name` into `[name, module]`. Anything else (a plain name, a
     * path such as `C:\fn.php` or a callable such as `Class::method`) is an
     * unqualified reference `[reference, null]`.
     *
     * @return array{0:string,1:string|null}
     */
    private static function parseQualifiedName(string $reference): array
    {
        $parts = explode(":", $reference, 2);
        if (count($parts) === 2 && self::moduleReferenceProblem($parts[0], $parts[1]) === null) {
            return [$parts[1], $parts[0]];
        }

        return [$reference, null];
    }

    private static function qualifiedName(string $name, ?string $module): string
    {
        return $module === null ? $name : "{$module}:{$name}";
    }

    /**
     * Why `$module`/`$name` is not a valid module-qualified reference, or null.
     */
    private static function moduleReferenceProblem(string $module, string $name): ?string
    {
        if (preg_match(self::MODULE_PATTERN, $module) !== 1) {
            return "module name '{$module}' must use letters, digits, '_' or '-'";
        }

        foreach (explode("/", $name) as $segment) {
            if ($segment === "." || $segment === ".." || preg_match('/^[A-Za-z0-9_.-]+$/', $segment) !== 1) {
                return "function '{$name}' of module '{$module}' must be a relative name "
                    . "(letters, digits, '_', '-', '.' and '/' between segments, no '.' or '..' segments)";
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function resolveModuleFolder(string $module, array $context): string
    {
        $modules = $context["_modules"] ?? null;
        if (is_array($modules) && isset($modules[$module]) && is_string($modules[$module]) && trim($modules[$module]) !== "") {
            return trim($modules[$module]);
        }

        return self::joinPath(self::resolveImporterRootFolder($context), $module);
    }

    /**
     * Compiles a YAML flow file into a callable closure.
     *
     * @param string $yamlPath
     * @param array  $options
     *
     * @return callable
     */
    public static function flowFromYaml(string $yamlPath, array $options = []): callable
    {
        $code = self::generateFlowCodeFromYaml($yamlPath, $options);

        try {
            $flow = eval($code);
        } catch (Throwable $e) {
            throw new RuntimeException(
                "[divengine.runner] Failed to compile generated YAML flow from '{$yamlPath}': " . $e->getMessage(),
                0,
                $e
            );
        }

        if (!is_callable($flow)) {
            throw new RuntimeException(
                "[divengine.runner] Generated YAML flow from '{$yamlPath}' is not callable."
            );
        }

        return $flow;
    }

    /**
     * Generates and runs a flow directly from a YAML definition file.
     *
     * @param string $yamlPath
     * @param array  $context
     * @param array  $options
     */
    public static function runYaml(string $yamlPath, array &$context, array $options = []): void
    {
        $flow = self::flowFromYaml($yamlPath, $options);
        self::run($flow, $context, $options);
    }

    /**
     * Executes a flow callable (or imports one from path) against a shared context.
     *
     * This method mutates `$context` in place and stores execution metadata under
     * `_runner_*` keys, including logs, timestamps, state and error details.
     *
     * @param string|callable $flow    Flow callable or PHP file path.
     * @param array           $context Shared context passed by reference.
     * @param array           $options Optional runtime options.
     *
     * @throws RuntimeException
     */
    public static function run(string|callable $flow, array &$context, array $options = []): void
    {
        $trace = null;
        self::execute($flow, $context, $options, $trace);
    }

    /**
     * Runs a flow exactly like `run()` and returns a semantic trace of it.
     *
     * Tracing observes the run without changing it: the context ends as it
     * would after `run()`, and no trace state is stored in it. The returned
     * document follows the protocol `runner::TRACE_PROTOCOL`, version
     * `runner::TRACE_VERSION` (see docs/tracing-and-simulation.md).
     *
     * Options: those of `run()`, plus `trace` (caps and value capture:
     * `max_events`, `max_bytes`, `max_string`, `max_items`, `max_depth`,
     * `max_nodes`, `capture_values`, `capture_messages`, `redact_keys`,
     * `redact_defaults`, `exclude_keys`). Error and log messages are left out
     * of the trace unless `capture_messages` is true.
     *
     * Unlike `run()`, setup errors (unreadable or invalid flow) are not thrown:
     * they are recorded in the context and in the trace result.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException On invalid trace options.
     */
    public static function runTraced(string|callable $flow, array &$context, array $options = []): array
    {
        $traceOptions = self::normalizeTraceOptions($options["trace"] ?? []);
        unset($options["trace"]);

        $trace = self::newTrace("run", $traceOptions, null);
        if (is_string($flow) && is_file($flow)) {
            $trace["sources"]["flow"] = self::fileSource("flow", $flow, $flow);
        }
        try {
            $trace["sources"] += self::functionMapSource($options["function_map"] ?? []);
        } catch (RuntimeException $e) {
            // An invalid map is a setup error of the run, recorded in the trace.
        }
        $fingerprint = self::fingerprint($trace["sources"]);

        self::executeTraced($flow, $context, $options, $trace);

        return self::traceDocument($trace, $flow, $fingerprint);
    }

    /**
     * Simulates a YAML flow with test doubles instead of its functions.
     *
     * The flow runs through Runner's own compiler and runtime (blocks, steps,
     * call, jump, pause, `_flow_state`, `_flow_stack`), but every condition and
     * activity reference resolves to a declared double. No function file is
     * imported and no callable name is resolved; a reference without a double
     * fails the simulation. Returns the trace document (see `runTraced()`).
     *
     * `$doubles` maps qualified function names (`module:name` or `name`) to:
     * - an outcome: `["returns" => value]`, `["throws" => "message"]` or
     *   `["pause" => true]` (activities only), each optionally with
     *   `"context" => [...]` merged into the context first;
     * - `["sequence" => [outcome, ...]]`: one outcome per call, in order;
     * - `["flow" => "child.yml"]`: a nested YAML flow used as the activity,
     *   simulated with the same doubles.
     *
     * Options: `budget` (`steps`, `transfers`, `seconds`), `cancel` (callable
     * returning true to stop), `double_calls` (calls made so far per double,
     * from a previous trace, to resume), `fingerprint` (expected fingerprint:
     * refuse to resume if the flow, doubles, nested flows or `function_map`
     * changed), `trace`, `function_map`, `logger`, `shared_context`.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $doubles
     * @param array<string, mixed> $options
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException On invalid doubles or options, a flow
     *                                  that is not YAML, or a fingerprint mismatch.
     */
    public static function simulate(string $yamlPath, array &$context, array $doubles, array $options = []): array
    {
        if (!self::isYamlPath($yamlPath)) {
            throw new InvalidArgumentException("[divengine.runner] Simulation runs YAML flows only: {$yamlPath}");
        }
        $allowed = ["budget", "cancel", "double_calls", "fingerprint", "trace", "function_map", "logger", "shared_context"];
        $unknown = array_diff(array_keys($options), $allowed);
        if ($unknown !== []) {
            throw new InvalidArgumentException("[divengine.runner] Unknown simulation option: " . implode(", ", $unknown));
        }
        $cancel = $options["cancel"] ?? null;
        if ($cancel !== null && !is_callable($cancel)) {
            throw new InvalidArgumentException("[divengine.runner] Simulation option 'cancel' must be callable.");
        }

        $normalized = self::normalizeDoubles($doubles);
        $simulation = [
            "doubles" => $normalized,
            "calls" => self::normalizeDoubleCalls($options["double_calls"] ?? []),
            "compiled" => [],
            "budget" => self::normalizeBudget($options["budget"] ?? []),
            "counters" => ["steps" => 0, "transfers" => 0],
            "started" => hrtime(true),
            "cancel" => $cancel,
            "function_map" => $options["function_map"] ?? [],
        ];

        $trace = self::newTrace("simulation", self::normalizeTraceOptions($options["trace"] ?? []), $simulation);
        if (is_file($yamlPath)) {
            $trace["sources"]["flow"] = self::fileSource("flow", $yamlPath, $yamlPath);
        }
        $trace["sources"]["doubles"] = [
            "role" => "doubles",
            "name" => "doubles",
            "path" => null,
            "sha256" => hash("sha256", self::canonicalJson(self::doublesForFingerprint($normalized))),
        ];
        foreach ($normalized as $name => $double) {
            if ($double["kind"] === "flow") {
                $trace["sources"]["nested_flow:" . $name] = self::fileSource("nested_flow", $name, $double["path"]);
            }
        }
        try {
            $trace["sources"] += self::functionMapSource($simulation["function_map"]);
        } catch (RuntimeException $e) {
            throw new InvalidArgumentException($e->getMessage(), 0, $e);
        }

        $fingerprint = self::fingerprint($trace["sources"]);
        if (isset($options["fingerprint"]) && $options["fingerprint"] !== $fingerprint) {
            throw new InvalidArgumentException(
                "[divengine.runner] The flow, its doubles, its nested flows or its function_map changed: expected fingerprint "
                . (is_string($options["fingerprint"]) ? $options["fingerprint"] : "?") . ", found {$fingerprint}."
            );
        }

        $runOptions = array_intersect_key($options, array_flip(["function_map", "logger", "shared_context"]));
        self::executeTraced($yamlPath, $context, $runOptions, $trace);

        return self::traceDocument($trace, $yamlPath, $fingerprint);
    }

    /**
     * Runs `run()` with `$trace` as the active trace (null: untraced). Nested
     * `run()` calls made by activities are separate, untraced invocations.
     *
     * @param array<string, mixed>      $context
     * @param array<string, mixed>      $options
     * @param array<string, mixed>|null $trace
     */
    private static function execute(string|callable $flow, array &$context, array $options, ?array &$trace): void
    {
        $previous = self::$trace;
        self::$trace = $trace;
        try {
            self::executeRun($flow, $context, $options);
        } finally {
            $trace = self::$trace;
            self::$trace = $previous;
        }
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     */
    private static function executeRun(string|callable $flow, array &$context, array $options): void
    {
        $loggerSink = $options["logger"] ?? self::$loggerSink;
        if ($loggerSink !== null && !is_callable($loggerSink)) {
            throw new RuntimeException("Logger must be a callable.");
        }
        $requireContextParamName = $options["require_context_param_name"] ?? true;

        $records = [];
        $logger = self::makeLogger($loggerSink, $records);

        if (isset($options["shared_context"]) && is_array($options["shared_context"])) {
            $context = array_replace($options["shared_context"], $context);
        }

        $initialSnapshot = $context;

        $startedAt = new DateTimeImmutable();
        $context["_runner_started_at"] = $startedAt->format(DateTimeInterface::ATOM);
        $context["_runner_state"] = "processing";
        $context["_runner_result"] = "";
        $context["_runner_error"] = null;

        $tokens = self::ensureTokens($context);
        $pauseToken = $tokens["pause"];
        $jumpToken = $tokens["jump"];

        // Expose helper callbacks inside context for flow/activity/condition files.
        $context["_logger"] = self::$trace === null ? $logger : self::traceLogger($logger, $context);
        $context["_importer"] = function (string $name, ?string $module = null) use (
            $logger,
            &$context
        ): ?callable {
            if (self::$trace !== null && self::$trace["simulation"] !== null) {
                return self::resolveDouble($name, $module, $context);
            }
            [$func, $file] = self::importWithSource($name, $module, $context, $logger);
            self::traceFunction(self::qualifiedName(trim($name), $module), $func, $file);
            return $func;
        };

        $context["_update_context"] = [self::class, "updateContext"];
        $context["_wrap_condition"] = [self::class, "wrapCondition"];
        $context["_wrap_activity"] = [self::class, "wrapActivity"];
        $context["_wrap_call"] = [self::class, "wrapCall"];
        $context["_wrap_jump"] = [self::class, "wrapJump"];
        $context["_wrap_pause"] = [self::class, "wrapPause"];
        $context["_check_pause"] = [self::class, "checkPause"];
        $context["_resume_after_call"] = [self::class, "resumeAfterCall"];
        $context["_exception_pause"] = $pauseToken;
        $context["_exception_jump"] = $jumpToken;
        $context["__flow_loop"] = [self::class, "flowLoop"];

        $flowName = is_string($flow) ? $flow : self::callableName($flow);
        $logger("INFO", "[divengine.runner] Starting job execution for flow: {$flowName}");

        try {
            $summary = self::contextSummary($initialSnapshot);
            $logger("INFO", "[divengine.runner] Initial context summary: {$summary}");
        } catch (Throwable $e) {
            $logger("INFO", "[divengine.runner] Initial context summary: unavailable");
        }

        if (($context["_flow_state"]["block"] ?? null) !== null) {
            $logger("INFO", "[divengine.runner] Resuming paused job execution");
        }
        self::traceRunStart($context);

        // Setup failures (unreadable/invalid YAML, unresolved flow, bad signature)
        // are thrown to the caller, with the context left in the `error` state.
        try {
            $func = $flow;
            if (self::$trace !== null && self::$trace["simulation"] !== null && !self::isYamlPath($flow)) {
                throw new RuntimeException("[divengine.runner] Simulation runs YAML flows only.");
            }
            if (is_string($flow)) {
                if (self::isYamlPath($flow)) {
                    $func = self::flowFromYaml($flow, $options);
                } else {
                    [$func, $file] = self::importWithSource($flow, null, $context, $logger);
                    self::traceSource("flow", $flow, $file);
                }
            }

            if (!$func || !is_callable($func)) {
                throw new RuntimeException(
                    "[divengine.runner] Flow '{$flowName}' must resolve to a callable."
                );
            }

            self::validateCallable($func, $requireContextParamName);
        } catch (Throwable $e) {
            $context["_runner_state"] = "error";
            $context["_runner_error"] = self::dumpException($e);
            $context["_runner_result"] = "Error: " . $e->getMessage();
            $logger("ERROR", "[divengine.runner] Flow setup failed - " . $e->getMessage());
            self::clearResumeState($context);
            self::traceRunEnd($context, $e, true);
            self::finishRun($context, $records);
            throw $e;
        }

        // Pause may request auto-resume, so execution can loop until fully done/error.
        $resume = true;
        while ($resume) {
            $resume = false;

            try {
                $logger("INFO", "[divengine.runner] Executing flow function... " . self::callableName($func));
                $func($context);

                $context["_runner_state"] = "done";
                $context["_runner_result"] = "Processed successfully at " . (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
                $context["_runner_error"] = null;
            } catch (Throwable $e) {
                $classification = self::classifyException($e, $context);
                if ($classification === "pause") {
                    unset($context["_nested_flow_paused"]);
                    $context["_runner_state"] = "paused";
                    $context["_runner_result"] = "Paused execution at " . (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
                    $context["_runner_error"] = null;

                    if (!empty($context["_autoresume"])) {
                        $logger("INFO", "[divengine.runner] Auto-resuming enabled, will continue execution.");
                        self::traceEvent("run.autoresume", ["checkpoint" => self::traceCheckpoint($context)], $context);
                        $resume = true;
                        $context["_runner_state"] = "processing";
                        $context["_runner_result"] .= " - will resume automatically.";
                        $context["_autoresume"] = false;
                    }
                    continue;
                }

                $context["_runner_state"] = "error";
                $context["_runner_error"] = self::dumpException($e);
                $context["_runner_result"] = "Error: " . $e->getMessage();
                self::clearResumeState($context);
                $logger("ERROR", "[divengine.runner] Error processing job - " . $context["_runner_error"]);
                $runError = $e;
            }
        }

        self::traceRunEnd($context, $runError ?? null, false);
        self::finishRun($context, $records);
    }

    /**
     * After an error, the next run starts at the first block: the saved
     * position, call return frames and pending resume markers are dropped.
     * Business data and the error diagnostics stay.
     *
     * @param array<string, mixed> $context
     */
    private static function clearResumeState(array &$context): void
    {
        $context["_flow_state"] = [];
        $context["_flow_stack"] = [];
        unset($context["_nested_flow_paused"]);
    }

    /**
     * Ends a run: stores logs and end time, and removes the runner's helpers and
     * block callables, so that what remains is the caller's data, the `_runner_*`
     * metadata and the resume state (`_flow_state`, `_flow_stack`).
     *
     * @param array<string, mixed>             $context
     * @param array<int, array<string, mixed>> $records
     */
    private static function finishRun(array &$context, array $records): void
    {
        foreach (array_merge(self::HELPER_KEYS, self::blockKeys($context)) as $key) {
            unset($context[$key]);
        }
        unset($context["_flow_blocks"], $context["_flow_depth"], $context["_nested_flow_paused"]);

        $context["_runner_logs"] = $records;
        $context["_runner_ended_at"] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
    }

    /**
     * Runs block callables and keeps looping on jump markers.
     *
     * The block started here is the running block. Blocks do not fall through:
     * the flow ends when the running block returns and no call continuation is
     * pending in `_flow_stack`.
     *
     * - A jump that escapes the running block restarts at its target. If the
     *   target block is suspended in `_flow_stack` (a resumed call), the nearest
     *   such frame becomes the running block again and the frames above it are
     *   dropped; otherwise every pending call continuation is dropped.
     * - When the running block returns and `_flow_stack` is not empty, the top
     *   frame is popped and its calling step continues after the call. This is
     *   how a pause inside a called block resumes back into its caller.
     *
     * @param string $initialBlock Initial block key (without leading `_`).
     * @param array  $context        Shared context by reference.
     *
     * @return mixed
     *
     * @throws RuntimeException
     * @throws Throwable
     *
     * @internal Called by generated flow code through `__flow_loop`.
     */
    public static function flowLoop(string $initialBlock, array &$context): mixed
    {
        $tokens = self::ensureTokens($context);

        self::logContext(
            $context,
            "INFO",
            "[divengine.runner] Starting flow execution with initial block: {$initialBlock}"
        );

        $depth = is_int($context["_flow_depth"] ?? null) ? $context["_flow_depth"] : 0;
        $maxDepth = is_int($context["_max_call_depth"] ?? null) ? $context["_max_call_depth"] : self::MAX_CALL_DEPTH;
        if ($depth >= $maxDepth) {
            throw new RuntimeException(
                "[divengine.runner] Nested flow depth exceeds {$maxDepth} (a flow using itself as an activity?)."
            );
        }
        $context["_flow_depth"] = $depth + 1;
        if ($depth > 0) {
            self::traceEvent("flow.enter", ["initial_block" => $initialBlock], $context);
        }
        $outcome = "error";

        try {
            $result = self::runBlocks($initialBlock, $context, $tokens);
            $outcome = "done";
            return $result;
        } catch (Throwable $e) {
            if ($depth > 0 && self::isPauseException($e, $tokens)) {
                // Tells the enclosing activity that this nested flow paused.
                $context["_nested_flow_paused"] = true;
                $outcome = "paused";
            }
            throw $e;
        } finally {
            if ($depth > 0) {
                self::traceEvent("flow.exit", ["outcome" => $outcome], $context);
            }
            $context["_flow_depth"] = $depth;
        }
    }

    /**
     * @param array{pause:string,jump:string} $tokens
     */
    private static function runBlocks(string $initialBlock, array &$context, array $tokens): mixed
    {
        $reason = null;
        while (true) {
            $state = $context["_flow_state"] ?? [];
            $savedBlock = is_array($state) ? ($state["block"] ?? null) : null;

            if (is_string($savedBlock) && $savedBlock !== "") {
                $initialBlock = $savedBlock;
                $reason ??= "resume";
            }

            $key = "_" . $initialBlock;
            if (!isset($context[$key]) || !is_callable($context[$key])) {
                throw new RuntimeException("Missing block callable for {$key}");
            }

            $context["_current_block_id"] = $initialBlock;
            self::traceBlockEnter($initialBlock, $reason ?? "start", $context);

            try {
                $result = ($context[$key])($context);
            } catch (Throwable $e) {
                if (!self::isJumpException($e, $tokens)) {
                    throw $e;
                }
                $reason = "jump";

                $target = $context["_flow_state"]["block"] ?? null;
                if ($target !== $initialBlock) {
                    // Nearest suspended invocation of the target keeps the frames
                    // below it; without one, all call continuations are dropped.
                    $stack = self::flowStack($context);
                    $kept = [];
                    for ($i = count($stack) - 1; $i >= 0; $i--) {
                        if ($stack[$i]["block"] === $target) {
                            $kept = array_slice($stack, 0, $i);
                            break;
                        }
                    }
                    if (count($kept) < count($stack)) {
                        self::traceEvent("call.drop", ["frames" => array_slice($stack, count($kept))], $context);
                    }
                    $context["_flow_stack"] = $kept;
                }
                continue;
            }

            self::traceEvent("block.exit", ["block" => $initialBlock], $context);
            $stack = self::flowStack($context);
            if ($stack === []) {
                $context["_flow_stack"] = [];
                $context["_flow_state"] = [];
                return $result;
            }

            $frame = array_pop($stack);
            $context["_flow_stack"] = $stack;
            $context["_flow_state"] = [
                "block" => $frame["block"],
                "step_index" => $frame["step_index"] - 1,
                "after_call" => $frame["step_index"],
            ];
            $reason = "return";
            self::traceEvent("call.return", [
                "from" => $initialBlock,
                "to" => ["block" => $frame["block"], "index" => $frame["step_index"]],
                "resumed" => true,
            ], $context);

            self::logContext(
                $context,
                "INFO",
                "[divengine.runner] Returning from call into {$frame["block"]}[{$frame["step_index"]}]"
            );
        }
    }

    /**
     * Tells a step with a call whether it is being re-entered after that call
     * returned (resume inside the called block). Consumes the marker.
     *
     * @param int   $stepIdx Current step index.
     * @param array $context Shared context by reference.
     *
     * @return bool
     *
     * @internal Called by generated flow code through `_resume_after_call`.
     */
    public static function resumeAfterCall(int $stepIdx, array &$context): bool
    {
        $state = $context["_flow_state"] ?? null;
        if (!is_array($state) || ($state["after_call"] ?? null) !== $stepIdx) {
            return false;
        }

        unset($state["after_call"]);
        $context["_flow_state"] = $state;

        return true;
    }

    /**
     * Merges step data into the shared context and traces the step.
     *
     * @param array  $context Shared context by reference.
     * @param array  $data    Data to merge.
     * @param string $stepId  Step identifier.
     * @param int    $stepIdx Step index.
     *
     * @internal Called by generated flow code through `_update_context`.
     */
    public static function updateContext(array &$context, array $data, string $stepId, int $stepIdx): void
    {
        $before = self::$trace !== null ? $context : [];
        $context = array_replace($context, $data);
        self::traceStep("context.update", "updateContext", $stepId, $stepIdx, $context);
        if (self::$trace !== null) {
            self::traceEvent("context.update", self::traceStepRef($context, $stepId, $stepIdx) + [
                "changes" => self::traceChanges($before, $context),
            ], $context);
        }
    }

    /**
     * Decides if the current step should execute or be skipped after a pause.
     *
     * @param int   $stepIdx Current step index.
     * @param array $context Shared context by reference.
     *
     * @return bool
     *
     * @internal Called by generated flow code through `_check_pause`.
     */
    public static function checkPause(int $stepIdx, array &$context, ?string $stepId = null): bool
    {
        if (self::$trace === null) {
            return self::checkPausePosition($stepIdx, $context);
        }

        $continuation = (($context["_flow_state"]["after_call"] ?? null) === $stepIdx);
        $enter = self::checkPausePosition($stepIdx, $context);
        $ref = ["block" => self::currentBlock($context), "step" => $stepId, "index" => $stepIdx];
        if (!$enter) {
            // Steps before the position a block was entered at: the target of a
            // call or jump, or the checkpoint of a resume (already executed).
            $entry = self::$trace["entry"] ?? null;
            self::traceEvent("step.skip", $ref + [
                "reason" => $entry === "call" || $entry === "jump" ? "before_target" : "before_checkpoint",
            ], $context);
            return false;
        }

        self::traceEvent("step.start", $ref + ["continuation" => $continuation], $context);
        self::simulationTick("steps", $context);
        return true;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function checkPausePosition(int $stepIdx, array &$context): bool
    {
        if (!isset($context["_flow_state"])) {
            $context["_flow_state"] = [];
        }

        $flowState = $context["_flow_state"];
        if (!array_key_exists("step_index", $flowState)) {
            $flowState["step_index"] = -1;
        }

        if (!array_key_exists("block", $flowState)) {
            $flowState["block"] = null;
        }

        $savedIndex = $flowState["step_index"] ?? -1;

        if ($flowState["block"] === null) {
            $context["_flow_state"] = $flowState;
            return true;
        }

        if ($flowState["step_index"] === null || $flowState["step_index"] === -1) {
            $context["_flow_state"] = $flowState;
            return true;
        }

        if ($stepIdx <= $savedIndex) {
            $context["_flow_state"] = $flowState;
            return false;
        }

        if ($stepIdx === $savedIndex + 1) {
            unset($flowState["step_index"], $flowState["block"]);
        }

        $context["_flow_state"] = $flowState;
        return true;
    }

    /**
     * Wraps a condition callable with trace logging.
     *
     * @param callable $func
     * @param string   $stepId
     * @param int      $stepIdx
     * @param array    $context
     *
     * @return mixed
     *
     * @internal Called by generated flow code through `_wrap_condition`.
     */
    public static function wrapCondition(callable $func, string $stepId, int $stepIdx, array &$context): mixed
    {
        self::traceStep("condition", self::callableName($func), $stepId, $stepIdx, $context);
        if (self::$trace === null) {
            return $func($context);
        }

        $ref = self::traceStepRef($context, $stepId, $stepIdx);
        $function = self::traceFunctionName($func);
        self::simulationGuardCondition($function);
        self::$trace["phase"] = "condition";
        self::$trace["function_call_depth"] = count(self::flowStack($context));
        try {
            $value = $func($context);
        } catch (Throwable $e) {
            self::traceEvent("condition.error", $ref + ["function" => $function, "error" => self::traceError($e)], $context);
            throw $e;
        }

        $event = $ref + ["function" => $function, "result" => (bool) $value];
        if (!is_bool($value)) {
            $event["value"] = self::traceValue($value, null);
        }
        self::traceEvent("condition", $event, $context);
        if (!$value) {
            self::traceEvent("step.skip", $ref + ["reason" => "condition_false"], $context);
        }
        return $value;
    }

    /**
     * Wraps an activity callable, stores its result in context and traces the step.
     *
     * @param callable $func
     * @param string   $stepId
     * @param int      $stepIdx
     * @param array    $context
     *
     * @internal Called by generated flow code through `_wrap_activity`.
     */
    public static function wrapActivity(callable $func, string $stepId, int $stepIdx, array &$context): void
    {
        self::traceStep("activity", self::callableName($func), $stepId, $stepIdx, $context);
        $tokens = self::ensureTokens($context);
        $traced = self::$trace !== null;
        $ref = $traced ? self::traceStepRef($context, $stepId, $stepIdx) : [];
        $function = $traced ? self::traceFunctionName($func) : "";
        if ($traced) {
            self::traceEvent("activity.start", $ref + ["function" => $function], $context);
            self::$trace["phase"] = "activity";
            // Events raised by the function itself (doubles, logs) belong to this
            // step, although the activity runs with an empty call stack.
            self::$trace["function_call_depth"] = count(self::flowStack($context));
        }

        // A flow used as an activity has its own control state: it starts with
        // an empty call stack, and the parent's scope comes back afterwards.
        $snapshot = $context;
        $context["_flow_stack"] = [];
        unset($context["_nested_flow_paused"]);

        try {
            $result = $func($context);
        } catch (Throwable $e) {
            if (!self::isPauseException($e, $tokens)) {
                $context["_flow_stack"] = $snapshot["_flow_stack"] ?? [];
                if ($traced) {
                    self::traceEvent("activity.error", $ref + ["function" => $function, "error" => self::traceError($e)], $context);
                }
                throw $e;
            }

            if (!empty($context["_nested_flow_paused"])) {
                // A pause inside a nested flow is its early return: the parent's
                // whole control state comes back and the result is null.
                unset($context["_nested_flow_paused"]);
                self::restoreControl($context, $snapshot, false);
                $context[$stepId] = null;
                if ($traced) {
                    self::traceEvent("activity.end", $ref + [
                        "function" => $function,
                        "result" => null,
                        "nested_flow_paused" => true,
                        "changes" => self::traceChanges($snapshot, $context),
                    ], $context);
                }
                return;
            }

            // The activity asked to pause the flow: pause at this step, without
            // storing a result.
            self::restoreControl($context, $snapshot, true);
            if ($traced) {
                self::traceEvent("activity.pause", $ref + [
                    "function" => $function,
                    "changes" => self::traceChanges($snapshot, $context),
                ], $context);
            }
            $block = $context["_current_block_id"] ?? null;
            self::wrapPause(is_string($block) ? $block : "", $stepId, $stepIdx, $context);
        }

        // Completed: the parent's blocks, position and call stack come back.
        // `_flow_state` and `_autoresume` stay as the activity left them.
        self::restoreControl($context, $snapshot, true);
        $context[$stepId] = $result;
        if ($traced) {
            self::traceEvent("activity.end", $ref + [
                "function" => $function,
                "result" => self::traceValue($result, $stepId),
                "changes" => self::traceChanges($snapshot, $context),
            ], $context);
        }
    }

    /**
     * Runs a `call`: the target block from the target step, then back into the calling step.
     *
     * While the call runs, the return frame `{block, step_index}` of the calling
     * step is on `_flow_stack`. The stack is plain data stored with the context,
     * so a pause at any depth resumes through every pending caller. A jump to
     * the called block restarts it at the target step (nearest invocation); any
     * other jump unwinds past this call and drops its continuation.
     *
     * @internal Called by generated flow code through `_wrap_call`.
     *
     * @param array<string, mixed> $context
     */
    public static function wrapCall(
        string $stepId,
        int $stepIdx,
        array &$context,
        string $targetBlock,
        int $targetIndex,
        string $fromBlock
    ): void {
        $func = $context["_" . $targetBlock] ?? null;
        if (!is_callable($func)) {
            throw new RuntimeException("Missing block callable for _{$targetBlock}");
        }
        self::traceStep("call", $targetBlock, $stepId, $stepIdx, $context, "{$targetBlock}[{$targetIndex}]");

        $stack = self::flowStack($context);
        $maxDepth = is_int($context["_max_call_depth"] ?? null) ? $context["_max_call_depth"] : self::MAX_CALL_DEPTH;
        if (count($stack) >= $maxDepth) {
            throw new RuntimeException(
                "[divengine.runner] Call depth exceeds {$maxDepth} at {$fromBlock}.{$stepId} -> {$targetBlock} (recursive call?)."
            );
        }
        self::traceEvent("call", self::traceStepRef($context, $stepId, $stepIdx) + [
            "target" => ["block" => $targetBlock, "index" => $targetIndex + 1],
            "returns" => true,
        ], $context);
        self::simulationTick("transfers", $context);
        $stack[] = ["block" => $fromBlock, "step_index" => $stepIdx];
        $context["_flow_stack"] = $stack;
        $depth = count($stack);
        $tokens = self::ensureTokens($context);

        $context["_flow_state"] = ["block" => $targetBlock, "step_index" => $targetIndex];

        $reason = "call";
        while (true) {
            $context["_current_block_id"] = $targetBlock;
            self::traceBlockEnter($targetBlock, $reason, $context);
            try {
                $func($context);
                break;
            } catch (Throwable $e) {
                if (self::isJumpException($e, $tokens)) {
                    if (($context["_flow_state"]["block"] ?? null) === $targetBlock) {
                        $reason = "jump";
                        continue;
                    }
                    $stack = self::flowStack($context);
                    self::traceEvent("call.drop", ["frames" => array_slice($stack, $depth - 1)], $context);
                    $context["_flow_stack"] = array_slice($stack, 0, $depth - 1);
                }
                // Pause and errors keep the frame: it is the persisted continuation.
                throw $e;
            }
        }

        self::traceEvent("block.exit", ["block" => $targetBlock], $context);
        $context["_flow_stack"] = array_slice(self::flowStack($context), 0, $depth - 1);
        $context["_current_block_id"] = $fromBlock;
        self::traceEvent("call.return", [
            "from" => $targetBlock,
            "to" => ["block" => $fromBlock, "step" => $stepId, "index" => $stepIdx],
            "resumed" => false,
        ], $context);

        // A pointer into the called block that no step consumed (empty block).
        if (($context["_flow_state"]["block"] ?? null) === $targetBlock) {
            $context["_flow_state"] = [];
        }
    }

    /**
     * Runs a `jump`: records the target and unwinds to the block loop.
     *
     * @internal Called by generated flow code through `_wrap_jump`.
     *
     * @param array<string, mixed> $context
     *
     * @throws RuntimeException Always: the jump marker.
     */
    public static function wrapJump(
        string $stepId,
        int $stepIdx,
        array &$context,
        string $targetBlock,
        int $targetIndex
    ): never {
        self::traceStep("jump", $targetBlock, $stepId, $stepIdx, $context, "{$targetBlock}[{$targetIndex}]");

        self::traceEvent("jump", self::traceStepRef($context, $stepId, $stepIdx) + [
            "target" => ["block" => $targetBlock, "index" => $targetIndex + 1],
            "returns" => false,
        ], $context);
        self::simulationTick("transfers", $context);
        $context["_flow_state"] = ["block" => $targetBlock, "step_index" => $targetIndex];
        $tokens = self::ensureTokens($context);
        throw new RuntimeException("{$tokens["jump"]} Jump to {$targetBlock}[{$targetIndex}]");
    }

    /**
     * Runs a `pause`: records the position of the step and unwinds the run.
     *
     * @internal Called by generated flow code through `_wrap_pause`.
     *
     * @param array<string, mixed> $context
     *
     * @throws RuntimeException Always: the pause marker.
     */
    public static function wrapPause(string $block, string $stepId, int $stepIdx, array &$context): never
    {
        $tokens = self::ensureTokens($context);

        self::traceStep("pause", $block, $stepId, $stepIdx, $context);

        $context["_flow_state"] = ["block" => $block, "step_index" => $stepIdx];
        self::traceEvent("pause", ["block" => $block, "step" => $stepId, "index" => $stepIdx,
            "checkpoint" => self::traceCheckpoint($context)], $context);

        self::logContext(
            $context,
            "INFO",
            "[divengine.runner] Pausing execution at {$block}.{$stepId}[{$stepIdx}]"
        );

        throw new RuntimeException("{$tokens["pause"]} Pause at {$block}.{$stepId}[{$stepIdx}]");
    }

    /**
     * Safely serializes arrays to JSON, with fallback sanitization.
     *
     * @param array $data
     *
     * @return string
     */
    public static function safeSerialize(array $data): string
    {
        $encoded = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($encoded !== false) {
            return $encoded;
        }

        $sanitized = self::sanitizeForJson($data);
        $encoded = json_encode($sanitized, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);

        return $encoded !== false ? $encoded : "{}";
    }

    /**
     * Converts a throwable chain to a plain-text diagnostic dump.
     *
     * @param Throwable $e
     *
     * @return string
     */
    public static function dumpException(Throwable $e): string
    {
        $lines = [];
        $lines[] = "[Exception Type] " . get_class($e);
        $lines[] = "[Message] " . $e->getMessage();
        $lines[] = "[Code] " . $e->getCode();
        $lines[] = "[File] " . $e->getFile() . ":" . $e->getLine();
        $lines[] = "[Traceback]";
        $lines[] = $e->getTraceAsString();

        $previous = $e->getPrevious();
        if ($previous) {
            $lines[] = "[Previous]";
            $lines[] = self::dumpException($previous);
        }

        return implode("\n", $lines);
    }

    /**
     * @param string $yamlPath
     *
     * @return array<string, mixed>
     */
    private static function parseYamlFlowFile(string $yamlPath): array
    {
        $path = trim($yamlPath);
        if ($path === "") {
            throw new RuntimeException("[divengine.runner] YAML path is empty.");
        }

        if (!is_file($path)) {
            throw new RuntimeException("[divengine.runner] YAML flow file not found: {$path}");
        }

        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new RuntimeException("[divengine.runner] YAML flow file could not be read: {$path}");
        }

        $lines = self::tokenizeYaml($contents, $path);
        if ($lines === []) {
            throw new RuntimeException(
                "[divengine.runner] YAML flow root must be a mapping: {$path}"
            );
        }

        $index = 0;
        $parsed = self::parseYamlNode($lines, $index, $lines[0]["indent"]);
        if ($index !== count($lines)) {
            $line = $lines[$index]["line"];
            throw new RuntimeException(
                "[divengine.runner] Invalid YAML in '{$path}' near line {$line}: unexpected trailing content."
            );
        }

        if (!is_array($parsed) || array_is_list($parsed)) {
            throw new RuntimeException(
                "[divengine.runner] YAML flow root must be a mapping: {$path}"
            );
        }

        return $parsed;
    }

    /**
     * Converts YAML text into indentation-aware logical lines.
     *
     * Block scalars (`|`, `>`, with optional `-`/`+` chomping) are read here,
     * raw, and attached to the line that introduces them as `block`.
     *
     * @param string $contents
     * @param string $yamlPath
     *
     * @return array<int, array{indent:int,content:string,line:int,block?:string}>
     */
    private static function tokenizeYaml(string $contents, string $yamlPath): array
    {
        $rawLines = preg_split('/\r\n|\n|\r/', $contents);
        if (!is_array($rawLines)) {
            return [];
        }

        if (isset($rawLines[0]) && str_starts_with($rawLines[0], "\xEF\xBB\xBF")) {
            $rawLines[0] = substr($rawLines[0], 3);
        }

        $lines = [];
        $count = count($rawLines);
        for ($lineNumber = 0; $lineNumber < $count; $lineNumber++) {
            $rawLine = $rawLines[$lineNumber];

            $withoutComments = self::stripYamlComments($rawLine);
            $trimmed = trim($withoutComments);
            if ($trimmed === "" || $trimmed === "---" || $trimmed === "...") {
                continue;
            }

            preg_match('/^([ \t]*)/', $withoutComments, $matches);
            if (str_contains($matches[1], "\t")) {
                $line = $lineNumber + 1;
                throw new RuntimeException(
                    "[divengine.runner] Invalid YAML in '{$yamlPath}' line {$line}: tabs are not supported for indentation."
                );
            }

            $indent = strlen($matches[1]);
            $content = rtrim(ltrim($withoutComments));

            // `- - x` is a sequence item whose value is a sequence: split it
            // into `-` and `- x` one level deeper.
            while (preg_match('/^(- +)(-(?: .*)?)$/', $content, $nested) === 1) {
                $lines[] = ["indent" => $indent, "content" => "-", "line" => $lineNumber + 1];
                $indent += strlen($nested[1]);
                $content = $nested[2];
            }

            $entry = [
                "indent" => $indent,
                "content" => $content,
                "line" => $lineNumber + 1,
            ];

            if (preg_match('/(?:^|:\s+|^-\s+)([|>])([-+]?)$/', $entry["content"], $block) === 1) {
                // The block belongs to the node that introduces it: a key's column,
                // or the dash of a sequence item (`- |`).
                preg_match('/^((?:- +)*)/', $entry["content"], $dashes);
                $prefix = strlen($dashes[1]);
                $rest = substr($entry["content"], $prefix);
                $parentIndent = preg_match('/^[|>]/', $rest) === 1 ? $indent + max(0, $prefix - 2) : $indent + $prefix;

                $blockLines = [];
                while ($lineNumber + 1 < $count) {
                    $next = $rawLines[$lineNumber + 1];
                    if (trim($next) !== "" && strspn($next, " ") <= $parentIndent) {
                        break;
                    }
                    $blockLines[] = $next;
                    $lineNumber++;
                }
                $atEndWithoutBreak = $lineNumber === $count - 1 && !preg_match('/[\\r\\n]$/', $contents);
                $entry["block"] = self::foldYamlBlock($blockLines, $block[1], $block[2], $entry["line"], !$atEndWithoutBreak);
            }

            $lines[] = $entry;
        }

        return $lines;
    }

    /**
     * Builds the value of a block scalar from its raw lines.
     *
     * @param array<int, string> $rawLines
     */
    private static function foldYamlBlock(
        array $rawLines,
        string $style,
        string $chomping,
        int $lineNumber,
        bool $finalBreak = true
    ): string {
        $blockIndent = null;
        foreach ($rawLines as $rawLine) {
            if (trim($rawLine) !== "") {
                $blockIndent = strspn($rawLine, " ");
                break;
            }
        }

        $contentLines = [];
        foreach ($rawLines as $offset => $rawLine) {
            if ($blockIndent !== null && trim($rawLine) !== "" && strspn($rawLine, " ") < $blockIndent) {
                $line = $lineNumber + $offset + 1;
                throw new RuntimeException(
                    "[divengine.runner] Invalid YAML in line {$line}: block scalar line is less indented than its first line."
                );
            }
            $contentLines[] = $blockIndent === null || trim($rawLine) === ""
                ? ""
                : (string) substr($rawLine, $blockIndent);
        }

        $trailing = 0;
        for ($i = count($contentLines) - 1; $i >= 0 && $contentLines[$i] === ""; $i--) {
            $trailing++;
        }
        $body = array_slice($contentLines, 0, count($contentLines) - $trailing);

        if ($style === "|") {
            $text = implode("\n", $body);
        } else {
            // Folded: lines of a paragraph are joined by spaces, empty lines and
            // more-indented lines keep their line breaks.
            $text = "";
            $previous = null;
            foreach ($body as $line) {
                if ($previous === null) {
                    $text = $line;
                } elseif ($line === "" || $previous === "" || $line[0] === " " || $previous[0] === " ") {
                    $text .= "\n" . $line;
                } else {
                    $text .= " " . $line;
                }
                $previous = $line;
            }
            $text = preg_replace('/\n(?=\n)/', "", $text) ?? $text;
        }

        if ($body === []) {
            return $chomping === "+" ? str_repeat("\n", $trailing) : "";
        }

        $break = $finalBreak || $trailing > 0 ? "\n" : "";

        return match ($chomping) {
            "-" => $text,
            "+" => $text . $break . str_repeat("\n", max(0, $trailing - ($finalBreak ? 0 : 1))),
            default => $text . $break,
        };
    }

    private static function stripYamlComments(string $line): string
    {
        $length = strlen($line);
        $quote = null;
        $escaped = false;
        $previous = "";

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($quote === '"') {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }

                if ($char === "\\") {
                    $escaped = true;
                    continue;
                }

                if ($char === '"') {
                    $quote = null;
                    $previous = $char;
                }
                continue;
            }

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                    $previous = $char;
                }
                continue;
            }

            // A quote opens a quoted scalar only where a scalar starts (`it's` is plain).
            if (($char === '"' || $char === "'") && ($previous === "" || str_contains(":-,[{?", $previous))) {
                $quote = $char;
                continue;
            }

            if ($char === "#" && ($i === 0 || $line[$i - 1] === " " || $line[$i - 1] === "\t")) {
                return rtrim(substr($line, 0, $i));
            }

            if ($char !== " " && $char !== "\t") {
                $previous = $char;
            }
        }

        return $line;
    }

    /**
     * @param array<int, array{indent:int,content:string,line:int}> $lines
     * @param int                                                    $index
     * @param int                                                    $indent
     *
     * @return mixed
     */
    private static function parseYamlNode(array $lines, int &$index, int $indent): mixed
    {
        if (!isset($lines[$index])) {
            return null;
        }

        $entry = $lines[$index];
        if ($entry["indent"] < $indent) {
            return null;
        }

        if ($entry["indent"] !== $indent) {
            throw new RuntimeException(
                "[divengine.runner] Invalid YAML indentation at line {$entry["line"]}."
            );
        }

        if (self::isYamlSequenceLine($entry["content"])) {
            return self::parseYamlSequence($lines, $index, $indent);
        }

        return self::parseYamlMapping($lines, $index, $indent);
    }

    private static function isYamlSequenceLine(string $content): bool
    {
        return $content === "-" || str_starts_with($content, "- ");
    }

    /**
     * Value of a key with nothing after its colon: a deeper node, an indentless
     * sequence at the key's column (`key:` followed by `- item`), or null.
     *
     * @param array<int, array{indent:int,content:string,line:int,block?:string}> $lines
     */
    private static function parseYamlKeyNode(array $lines, int &$index, int $keyIndent): mixed
    {
        if (!isset($lines[$index])) {
            return null;
        }

        $next = $lines[$index];
        if ($next["indent"] > $keyIndent) {
            return self::parseYamlNode($lines, $index, $next["indent"]);
        }

        if ($next["indent"] === $keyIndent && self::isYamlSequenceLine($next["content"])) {
            return self::parseYamlSequence($lines, $index, $keyIndent, true);
        }

        return null;
    }

    /**
     * @param array<int, array{indent:int,content:string,line:int}> $lines
     * @param int                                                    $index
     * @param int                                                    $indent
     *
     * @return array<string, mixed>
     */
    private static function parseYamlMapping(array $lines, int &$index, int $indent): array
    {
        $result = [];

        while (isset($lines[$index])) {
            $entry = $lines[$index];
            if ($entry["indent"] < $indent) {
                break;
            }

            if ($entry["indent"] > $indent) {
                throw new RuntimeException(
                    "[divengine.runner] Invalid YAML indentation at line {$entry["line"]}."
                );
            }

            if (self::isYamlSequenceLine($entry["content"])) {
                throw new RuntimeException(
                    "[divengine.runner] Invalid YAML in line {$entry["line"]}: sequence item found where mapping is expected."
                );
            }

            [$key, $valuePart] = self::splitYamlKeyValue($entry["content"], $entry["line"]);
            if (array_key_exists($key, $result)) {
                throw new RuntimeException(
                    "[divengine.runner] Duplicate YAML key '{$key}' at line {$entry["line"]}."
                );
            }

            $index++;
            if (isset($entry["block"])) {
                $result[$key] = $entry["block"];
                continue;
            }

            if ($valuePart !== "") {
                $result[$key] = self::parseYamlScalar($valuePart, $entry["line"]);
                continue;
            }

            $result[$key] = self::parseYamlKeyNode($lines, $index, $indent);
        }

        return $result;
    }

    /**
     * @param array<int, array{indent:int,content:string,line:int}> $lines
     * @param int                                                    $index
     * @param int                                                    $indent
     *
     * @return array<int, mixed>
     */
    private static function parseYamlSequence(array $lines, int &$index, int $indent, bool $indentless = false): array
    {
        $result = [];

        while (isset($lines[$index])) {
            $entry = $lines[$index];
            if ($entry["indent"] < $indent) {
                break;
            }

            if ($entry["indent"] > $indent) {
                throw new RuntimeException(
                    "[divengine.runner] Invalid YAML indentation at line {$entry["line"]}."
                );
            }

            if (!self::isYamlSequenceLine($entry["content"])) {
                if ($indentless) {
                    break;
                }
                throw new RuntimeException(
                    "[divengine.runner] Invalid YAML in line {$entry["line"]}: mapping found where sequence item is expected."
                );
            }

            $itemLine = ltrim(substr($entry["content"], 1));
            $index++;

            if ($itemLine === "") {
                if (isset($lines[$index]) && $lines[$index]["indent"] > $indent) {
                    $result[] = self::parseYamlNode($lines, $index, $lines[$index]["indent"]);
                } else {
                    $result[] = null;
                }
                continue;
            }

            if (isset($entry["block"]) && preg_match('/^[|>][-+]?$/', $itemLine) === 1) {
                $result[] = $entry["block"];
                continue;
            }

            if (self::isYamlMappingItem($itemLine)) {
                // Keys of the item mapping sit at the column after the dash.
                $keyIndent = $indent + strlen($entry["content"]) - strlen($itemLine);
                $item = [];
                [$key, $valuePart] = self::splitYamlKeyValue($itemLine, $entry["line"]);
                if (isset($entry["block"])) {
                    $item[$key] = $entry["block"];
                } elseif ($valuePart !== "") {
                    $item[$key] = self::parseYamlScalar($valuePart, $entry["line"]);
                } else {
                    $item[$key] = self::parseYamlKeyNode($lines, $index, $keyIndent);
                }

                if (isset($lines[$index]) && $lines[$index]["indent"] > $indent) {
                    if ($lines[$index]["indent"] !== $keyIndent) {
                        throw new RuntimeException(
                            "[divengine.runner] Invalid YAML indentation at line {$lines[$index]["line"]}."
                        );
                    }
                    $extra = self::parseYamlMapping($lines, $index, $keyIndent);
                    foreach ($extra as $extraKey => $extraValue) {
                        if (array_key_exists($extraKey, $item)) {
                            $line = $entry["line"];
                            throw new RuntimeException(
                                "[divengine.runner] Duplicate YAML key '{$extraKey}' in sequence item near line {$line}."
                            );
                        }
                        $item[$extraKey] = $extraValue;
                    }
                }

                $result[] = $item;
                continue;
            }

            $result[] = self::parseYamlScalar($itemLine, $entry["line"]);
        }

        return $result;
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function splitYamlKeyValue(string $line, int $lineNumber): array
    {
        $length = strlen($line);
        $quote = null;
        $escaped = false;

        for ($i = 0; $i < $length; $i++) {
            $char = $line[$i];

            if ($quote === '"') {
                if ($escaped) {
                    $escaped = false;
                    continue;
                }
                if ($char === "\\") {
                    $escaped = true;
                    continue;
                }
                if ($char === '"') {
                    $quote = null;
                }
                continue;
            }

            if ($quote === "'") {
                if ($char === "'") {
                    $quote = null;
                }
                continue;
            }

            if ($char === '"' || $char === "'") {
                $quote = $char;
                continue;
            }

            if ($char !== ":") {
                continue;
            }

            if ($i + 1 < $length && $line[$i + 1] !== " ") {
                continue;
            }

            $key = trim(substr($line, 0, $i));
            if (
                strlen($key) >= 2
                && (($key[0] === '"' && str_ends_with($key, '"')) || ($key[0] === "'" && str_ends_with($key, "'")))
            ) {
                $key = (string) self::parseYamlScalar($key, $lineNumber);
            }
            if ($key === "") {
                throw new RuntimeException(
                    "[divengine.runner] Invalid YAML in line {$lineNumber}: empty mapping key."
                );
            }

            $value = trim(substr($line, $i + 1));
            return [$key, $value];
        }

        throw new RuntimeException(
            "[divengine.runner] Invalid YAML in line {$lineNumber}: expected 'key: value'."
        );
    }

    /**
     * Whether a sequence item such as `key: value` starts a mapping.
     */
    private static function isYamlMappingItem(string $itemLine): bool
    {
        if ($itemLine === "" || str_contains("[{\"'", $itemLine[0])) {
            return $itemLine !== "" && ($itemLine[0] === '"' || $itemLine[0] === "'")
                && preg_match('/^("(?:[^"\\\\]|\\\\.)*"|\'(?:[^\']|\'\')*\'):(\s|$)/', $itemLine) === 1;
        }

        return preg_match('/:(\s|$)/', $itemLine) === 1;
    }

    private static function parseYamlScalar(string $value, int $lineNumber = 0): mixed
    {
        $trimmed = trim($value);
        if ($trimmed === "") {
            return "";
        }

        if ($trimmed[0] === "[" || $trimmed[0] === "{") {
            $position = 0;
            $parsed = self::parseYamlFlowValue($trimmed, $position, $lineNumber);
            if (trim(substr($trimmed, $position)) !== "") {
                throw new RuntimeException(
                    "[divengine.runner] Invalid YAML in line {$lineNumber}: unexpected content after flow collection."
                );
            }
            return $parsed;
        }

        if (str_contains("&*!%@`", $trimmed[0])) {
            throw new RuntimeException(
                "[divengine.runner] Invalid YAML in line {$lineNumber}: anchors, aliases, tags and reserved indicators "
                . "are not supported; quote the value."
            );
        }

        if (
            strlen($trimmed) >= 2
            && ((str_starts_with($trimmed, '"') && str_ends_with($trimmed, '"'))
            || (str_starts_with($trimmed, "'") && str_ends_with($trimmed, "'")))
        ) {
            $unquoted = substr($trimmed, 1, -1);
            if (str_starts_with($trimmed, '"')) {
                return self::decodeYamlDoubleQuoted($unquoted);
            }
            return str_replace("''", "'", $unquoted);
        }

        if ($trimmed[0] === '"' || $trimmed[0] === "'") {
            throw new RuntimeException(
                "[divengine.runner] Invalid YAML in line {$lineNumber}: unterminated or malformed quoted scalar."
            );
        }

        return self::resolveYamlPlainScalar($trimmed);
    }

    /**
     * Resolves a plain scalar with the YAML 1.1 rules of PyYAML `safe_load`,
     * the loader of the Python reference modeler (so `yes`/`on` are booleans, `0x1F`
     * and `017` are integers). Timestamps and sexagesimal numbers stay strings.
     */
    private static function resolveYamlPlainScalar(string $value): mixed
    {
        if (preg_match('/^(?:yes|Yes|YES|true|True|TRUE|on|On|ON)$/', $value) === 1) {
            return true;
        }
        if (preg_match('/^(?:no|No|NO|false|False|FALSE|off|Off|OFF)$/', $value) === 1) {
            return false;
        }
        if (preg_match('/^(?:~|null|Null|NULL)$/', $value) === 1) {
            return null;
        }

        if (preg_match('/^([-+]?)(0b[01_]+|0x[0-9a-fA-F_]+|0[0-7_]+|0|[1-9][0-9_]*)$/', $value, $m) === 1) {
            $digits = str_replace("_", "", $m[2]);
            if (str_starts_with($digits, "0b")) {
                $number = bindec(substr($digits, 2));
            } elseif (str_starts_with($digits, "0x")) {
                $number = hexdec(substr($digits, 2));
            } elseif (strlen($digits) > 1 && $digits[0] === "0") {
                $number = octdec(substr($digits, 1));
            } else {
                $number = (int) $digits;
            }
            $number = is_float($number) ? (int) $number : $number;
            return $m[1] === "-" ? -$number : $number;
        }

        if (preg_match('/^[-+]?(?:[0-9][0-9_]*\\.[0-9_]*|\\.[0-9_]+)(?:[eE][-+][0-9]+)?$/', $value) === 1) {
            return (float) str_replace("_", "", $value);
        }
        if (preg_match('/^([-+]?)\\.(?:inf|Inf|INF)$/', $value, $m) === 1) {
            return $m[1] === "-" ? -INF : INF;
        }
        if (preg_match('/^\\.(?:nan|NaN|NAN)$/', $value) === 1) {
            return NAN;
        }

        return $value;
    }

    /**
     * Decodes the escapes of a YAML double-quoted string.
     */
    private static function decodeYamlDoubleQuoted(string $text): string
    {
        $map = ["n" => "\n", "t" => "\t", "r" => "\r", "0" => "\0", "\\" => "\\", '"' => '"', "/" => "/", " " => " ",
            "a" => "\x07", "b" => "\x08", "e" => "\x1B", "f" => "\f", "v" => "\v"];

        return preg_replace_callback(
            '/\\\\(x[0-9A-Fa-f]{2}|u[0-9A-Fa-f]{4}|U[0-9A-Fa-f]{8}|.)/',
            function (array $m) use ($map): string {
                $code = $m[1];
                if (isset($map[$code])) {
                    return $map[$code];
                }
                if (strlen($code) > 1) {
                    return self::utf8Chr((int) hexdec(substr($code, 1)));
                }
                return "\\" . $code;
            },
            $text
        ) ?? $text;
    }

    /**
     * Encodes a Unicode code point as UTF-8 (no mbstring dependency).
     */
    private static function utf8Chr(int $code): string
    {
        if ($code < 0x80) {
            return chr($code);
        }
        if ($code < 0x800) {
            return chr(0xC0 | ($code >> 6)) . chr(0x80 | ($code & 0x3F));
        }
        if ($code < 0x10000) {
            return chr(0xE0 | ($code >> 12)) . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
        }
        if ($code <= 0x10FFFF) {
            return chr(0xF0 | ($code >> 18)) . chr(0x80 | (($code >> 12) & 0x3F))
                . chr(0x80 | (($code >> 6) & 0x3F)) . chr(0x80 | ($code & 0x3F));
        }

        return "";
    }

    /**
     * Parses a one-line flow collection (`[a, b]`, `{k: v}`) or a scalar inside one.
     */
    private static function parseYamlFlowValue(string $text, int &$position, int $lineNumber): mixed
    {
        $length = strlen($text);
        $skip = function () use ($text, $length, &$position): void {
            while ($position < $length && $text[$position] === " ") {
                $position++;
            }
        };
        $fail = function (string $why) use ($lineNumber): never {
            throw new RuntimeException("[divengine.runner] Invalid YAML in line {$lineNumber}: {$why}.");
        };

        $skip();
        if ($position >= $length) {
            $fail("unterminated flow collection (multi-line flow collections are not supported)");
        }

        $char = $text[$position];
        if ($char === "[" || $char === "{") {
            $isMap = $char === "{";
            $close = $isMap ? "}" : "]";
            $position++;
            $result = [];
            while (true) {
                $skip();
                if ($position >= $length) {
                    $fail("unterminated flow collection (multi-line flow collections are not supported)");
                }
                if ($text[$position] === $close) {
                    $position++;
                    return $result;
                }

                if ($isMap) {
                    $keyStart = $position;
                    $key = self::parseYamlFlowValue($text, $position, $lineNumber);
                    if (!str_contains("\"'[{", $text[$keyStart])) {
                        $key = trim(substr($text, $keyStart, $position - $keyStart));
                    }
                    $skip();
                    $value = null;
                    if ($position < $length && $text[$position] === ":") {
                        $position++;
                        $skip();
                        if ($position < $length && $text[$position] !== "," && $text[$position] !== "}") {
                            $value = self::parseYamlFlowValue($text, $position, $lineNumber);
                        }
                    }
                    $result[is_scalar($key) ? (string) $key : ""] = $value;
                } else {
                    $result[] = self::parseYamlFlowValue($text, $position, $lineNumber);
                }

                $skip();
                if ($position < $length && $text[$position] === ",") {
                    $position++;
                    continue;
                }
                if ($position < $length && $text[$position] === $close) {
                    continue;
                }
                $fail("expected ',' or '{$close}' in flow collection");
            }
        }

        if ($char === '"' || $char === "'") {
            $end = $position + 1;
            while ($end < $length) {
                if ($char === '"' && $text[$end] === "\\") {
                    $end += 2;
                    continue;
                }
                if ($text[$end] === $char) {
                    if ($char === "'" && $end + 1 < $length && $text[$end + 1] === "'") {
                        $end += 2;
                        continue;
                    }
                    break;
                }
                $end++;
            }
            if ($end >= $length) {
                $fail("unterminated quoted string");
            }
            $quoted = substr($text, $position, $end - $position + 1);
            $position = $end + 1;
            return self::parseYamlScalar($quoted, $lineNumber);
        }

        $start = $position;
        while ($position < $length) {
            $c = $text[$position];
            if ($c === "," || $c === "]" || $c === "}" || ($c === ":" && ($position + 1 >= $length || str_contains(" ,]}", $text[$position + 1])))) {
                break;
            }
            $position++;
        }

        return self::parseYamlScalar(substr($text, $start, $position - $start), $lineNumber);
    }

    /**
     * @param array<string, mixed> $flowDef
     *
     * @return array<int, array{id:string,steps:array<int, array<string,mixed>>}>
     */
    private static function normalizeFlowBlocks(array $flowDef): array
    {
        $blocksRaw = $flowDef["blocks"] ?? null;
        if (!is_array($blocksRaw) || ($blocksRaw !== [] && array_is_list($blocksRaw))) {
            throw new RuntimeException(
                "[divengine.runner] Invalid YAML flow format: 'blocks' must be a mapping of block id to block."
            );
        }

        $normalized = [];
        foreach ($blocksRaw as $blockId => $blockData) {
            if (!is_array($blockData)) {
                throw new RuntimeException("[divengine.runner] Block '{$blockId}' must be a mapping.");
            }

            $stepsRaw = $blockData["steps"] ?? [];
            if (!is_array($stepsRaw) || ($stepsRaw !== [] && array_is_list($stepsRaw))) {
                throw new RuntimeException(
                    "[divengine.runner] 'steps' of block '{$blockId}' must be a mapping of step id to step."
                );
            }

            $steps = [];
            foreach ($stepsRaw as $stepId => $stepData) {
                if (!is_array($stepData)) {
                    throw new RuntimeException("[divengine.runner] Step '{$blockId}.{$stepId}' must be a mapping.");
                }
                $steps[] = ["id" => trim((string) $stepId)] + $stepData;
            }

            $normalized[] = ["id" => trim((string) $blockId), "steps" => $steps];
        }

        return $normalized;
    }

    /**
     * @param array<int, array{id:string,steps:array<int, array<string,mixed>>}> $blocks
     */
    private static function validateFlowBlocks(array $blocks): void
    {
        if ($blocks === []) {
            throw new RuntimeException("[divengine.runner] YAML flow must contain at least one block.");
        }

        $blockIds = [];
        foreach ($blocks as $block) {
            $blockId = $block["id"];
            if ($blockId === "") {
                throw new RuntimeException("[divengine.runner] Block id cannot be empty.");
            }
            if (str_contains($blockId, ".")) {
                throw new RuntimeException("[divengine.runner] Block id '{$blockId}' cannot contain a dot.");
            }
            if (str_starts_with($blockId, "_") || in_array("_" . $blockId, self::RESERVED_KEYS, true) || str_starts_with($blockId, "runner_")) {
                throw new RuntimeException(
                    "[divengine.runner] Block id '{$blockId}' is reserved: its callable key '_{$blockId}' is a runner key."
                );
            }
            if (isset($blockIds[$blockId])) {
                throw new RuntimeException("[divengine.runner] Duplicate block id '{$blockId}'.");
            }

            $blockIds[$blockId] = true;
            $stepIds = [];
            foreach ($block["steps"] as $step) {
                $stepId = trim((string) ($step["id"] ?? ""));
                if ($stepId === "") {
                    throw new RuntimeException("[divengine.runner] Step id cannot be empty in block '{$blockId}'.");
                }
                if (str_starts_with($stepId, "_")) {
                    throw new RuntimeException(
                        "[divengine.runner] Step id '{$stepId}' in block '{$blockId}' cannot start with '_': "
                        . "activity results are stored under the step id and '_' keys are reserved."
                    );
                }
                if (str_contains($stepId, ".")) {
                    throw new RuntimeException(
                        "[divengine.runner] Step id '{$stepId}' in block '{$blockId}' cannot contain a dot."
                    );
                }
                if (isset($stepIds[$stepId])) {
                    throw new RuntimeException(
                        "[divengine.runner] Duplicate step id '{$stepId}' in block '{$blockId}'."
                    );
                }
                $stepIds[$stepId] = true;

                $callRef = isset($step["call"]) && is_string($step["call"]) ? trim($step["call"]) : "";
                if ($callRef !== "") {
                    self::validateReferenceSyntax($callRef, "call");
                }

                $jumpRef = isset($step["jump"]) && is_string($step["jump"]) ? trim($step["jump"]) : "";
                if ($jumpRef !== "") {
                    self::validateReferenceSyntax($jumpRef, "jump");
                }
            }
        }
    }

    /**
     * Rejects unknown keys and wrongly typed values in a flow definition, so a
     * typo such as `activty:` fails instead of silently skipping the action.
     * All problems are reported together.
     *
     * @param array<string, mixed> $flowDef
     */
    private static function validateFlowSchema(array $flowDef, string $yamlPath): void
    {
        $errors = [];

        foreach (array_keys($flowDef) as $key) {
            if (!in_array($key, self::FLOW_KEYS, true)) {
                $errors[] = "unknown flow key '{$key}'";
            }
        }

        $blocks = $flowDef["blocks"] ?? null;
        if (is_array($blocks) && !array_is_list($blocks)) {
            foreach ($blocks as $blockId => $block) {
                if (!is_array($block)) {
                    continue;
                }
                foreach (array_keys($block) as $key) {
                    if (!in_array($key, self::BLOCK_KEYS, true)) {
                        $errors[] = "unknown key '{$key}' in block '{$blockId}'";
                    }
                }

                $steps = $block["steps"] ?? null;
                if (!is_array($steps) || array_is_list($steps)) {
                    continue;
                }
                foreach ($steps as $stepId => $step) {
                    if (is_array($step)) {
                        array_push($errors, ...self::stepSchemaErrors($step, "step '{$blockId}.{$stepId}'"));
                    }
                }
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(
                "[divengine.runner] Invalid YAML flow '{$yamlPath}': " . implode("; ", $errors) . "."
            );
        }
    }

    /**
     * @param array<mixed> $step
     *
     * @return array<int, string>
     */
    private static function stepSchemaErrors(array $step, string $where): array
    {
        $errors = [];
        foreach ($step as $key => $value) {
            if (!in_array($key, self::STEP_KEYS, true)) {
                $errors[] = "unknown key '{$key}' in {$where}";
                continue;
            }
            if ($value === null || $key === "description") {
                continue;
            }
            if ($key === "pause" && !is_bool($value)) {
                $errors[] = "'pause' in {$where} must be true or false";
            } elseif ($key === "context" && (!is_array($value) || ($value !== [] && array_is_list($value)))) {
                $errors[] = "'context' in {$where} must be a mapping";
            } elseif (in_array($key, ["module", "condition", "activity", "call", "jump"], true)
                && (!is_string($value) || trim($value) === "")) {
                $errors[] = "'{$key}' in {$where} must be a non-empty string";
            }
        }

        $module = $step["module"] ?? null;
        if (!is_string($module) || trim($module) === "") {
            return $errors;
        }

        $functions = array_filter(
            [$step["condition"] ?? null, $step["activity"] ?? null],
            fn (mixed $ref): bool => is_string($ref) && trim($ref) !== ""
        );
        if ($functions === []) {
            $errors[] = "'module' in {$where} needs an 'activity' or a 'condition'";
        }
        foreach ($functions as $ref) {
            $problem = self::moduleReferenceProblem(trim($module), trim($ref));
            if ($problem !== null) {
                $errors[] = "{$problem} in {$where}";
            }
        }

        return $errors;
    }

    private static function validateReferenceSyntax(string $reference, string $kind): void
    {
        if (!str_contains($reference, ".")) {
            throw new RuntimeException(
                "[divengine.runner] '{$kind}' reference must contain a dot: {$reference}"
            );
        }
    }

    /**
     * @param array<int, array{id:string,steps:array<int, array<string,mixed>>}> $blocks
     *
     * @return array<string, array<string, int>>
     */
    private static function buildStepIndexMap(array $blocks): array
    {
        $stepIndexMap = [];
        foreach ($blocks as $block) {
            $blockId = $block["id"];
            $stepIndexMap[$blockId] = [];
            foreach ($block["steps"] as $index => $step) {
                $stepIndexMap[$blockId][$step["id"]] = $index;
            }
        }

        return $stepIndexMap;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array<int, string>
     */
    private static function buildImportPathCandidates(string $reference, array $context): array
    {
        $rootFolder = self::resolveImporterRootFolder($context);
        $rawCandidates = [];

        if (self::isAbsolutePath($reference)) {
            $rawCandidates[] = $reference;
        } else {
            $rawCandidates[] = self::joinPath($rootFolder, $reference);
            $rawCandidates[] = $reference;
        }

        $candidates = [];
        foreach ($rawCandidates as $candidate) {
            $withExtension = self::ensurePhpExtension($candidate);
            if (!in_array($withExtension, $candidates, true)) {
                $candidates[] = $withExtension;
            }
        }

        return $candidates;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function resolveImporterRootFolder(array $context): string
    {
        $contextRoot = isset($context["_root_folder"]) && is_string($context["_root_folder"])
            ? trim($context["_root_folder"])
            : "";
        if ($contextRoot !== "") {
            return $contextRoot;
        }

        if (defined('DIV_RUNNER_ROOT_FOLDER') && is_string(DIV_RUNNER_ROOT_FOLDER) && trim(DIV_RUNNER_ROOT_FOLDER) !== '') {
            return trim(DIV_RUNNER_ROOT_FOLDER);
        }

        return './';
    }

    private static function ensurePhpExtension(string $path): string
    {
        if (str_ends_with(strtolower($path), ".php")) {
            return $path;
        }

        return $path . ".php";
    }

    /**
     * @param array<string, array<string, int>> $stepIndexMap
     *
     * @return array{0:string,1:int}
     */
    private static function resolveBlockTarget(
        string $reference,
        string $currentBlock,
        array $stepIndexMap,
        string $kind
    ): array {
        [$targetBlock, $targetStep] = self::parseBlockReference($reference);

        if ($targetBlock === "") {
            $targetBlock = $currentBlock;
        }

        if (!isset($stepIndexMap[$targetBlock])) {
            throw new RuntimeException(
                "[divengine.runner] {$kind} target block '{$targetBlock}' not found."
            );
        }

        if ($targetStep === "") {
            return [$targetBlock, 0];
        }

        if (!isset($stepIndexMap[$targetBlock][$targetStep])) {
            throw new RuntimeException(
                "[divengine.runner] {$kind} target step '{$targetStep}' not found in block '{$targetBlock}'."
            );
        }

        return [$targetBlock, $stepIndexMap[$targetBlock][$targetStep]];
    }

    /**
     * @return array{0:string,1:string}
     */
    private static function parseBlockReference(string $reference): array
    {
        $parts = explode(".", $reference, 2);
        if (count($parts) !== 2) {
            throw new RuntimeException(
                "[divengine.runner] Invalid block reference syntax: {$reference}"
            );
        }

        return [trim($parts[0]), trim($parts[1])];
    }

    private static function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, "/")
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1
            || preg_match('/^[\\\\\\/]{2}/', $path) === 1;
    }

    private static function joinPath(string $basePath, string $relativePath): string
    {
        $base = rtrim($basePath, "\\/");
        $relative = ltrim($relativePath, "\\/");
        if ($base === "") {
            return $relative;
        }

        return $base . DIRECTORY_SEPARATOR . $relative;
    }

    /**
     * Validates flow callable signature requirements.
     *
     * @param callable $func
     * @param bool     $requireContextParamName
     *
     * @throws RuntimeException
     */
    private static function validateCallable(callable $func, bool $requireContextParamName): void
    {
        $ref = self::reflectCallable($func);
        if ($ref->getNumberOfParameters() < 1) {
            throw new RuntimeException(
                "[divengine.runner] Flow function must accept 'context' as the first parameter."
            );
        }

        $param = $ref->getParameters()[0];

        if ($requireContextParamName && $param->getName() !== "context") 
            throw new RuntimeException(
                "[divengine.runner] Flow function first parameter must be named 'context'."
            );

        if (!$param->isPassedByReference())
            throw new RuntimeException(
                "[divengine.runner] Flow function must pass 'context' by reference as the first parameter."
            );

        $type = $param->getType();
        if ($type instanceof ReflectionNamedType && $type->getName() !== "array")
            throw new RuntimeException(
                "[divengine.runner] Flow function 'context' parameter type must be array when declared."
            );
    }

    /**
     * Builds a reflection object for any supported callable shape.
     *
     * @param callable $func
     *
     * @return ReflectionFunctionAbstract
     */
    private static function reflectCallable(callable $func): ReflectionFunctionAbstract
    {
        if (is_array($func)) {
            return new ReflectionMethod($func[0], $func[1]);
        }

        if (is_string($func) && str_contains($func, "::")) {
            [$class, $method] = explode("::", $func, 2);
            return new ReflectionMethod($class, $method);
        }

        if (is_object($func) && method_exists($func, "__invoke")) {
            return new ReflectionMethod($func, "__invoke");
        }

        return new ReflectionFunction($func);
    }

    /**
     * Returns a human-friendly callable identifier for logs/state.
     *
     * @param callable $func
     *
     * @return string
     */
    private static function callableName(callable $func): string
    {
        if (is_string($func)) {
            return $func;
        }

        if (is_array($func)) {
            $class = is_object($func[0]) ? get_class($func[0]) : (string) $func[0];
            return $class . "::" . $func[1];
        }

        if (is_object($func)) {
            return get_class($func) . "::__invoke";
        }

        return "callable";
    }

    /**
     * Creates the effective logger that records in-memory and forwards to sink.
     *
     * @param callable|null $sink
     * @param array         $records
     *
     * @return callable
     */
    private static function makeLogger(?callable $sink, array &$records): callable
    {
        return function (string $level, string $message, array $context = []) use (&$records, $sink): void {
            $record = [
                "level" => $level,
                "message" => $message,
                "timestamp" => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
                "context" => $context,
            ];

            $records[] = $record;

            if ($sink) {
                $sink($level, $message, $context);
            }
        };
    }

    /**
     * Emits a prefixed log message either to explicit logger or global sink.
     *
     * @param callable|null $logger
     * @param string        $level
     * @param string        $message
     * @param array         $context
     */
    private static function emitLog(?callable $logger, string $level, string $message, array $context = []): void
    {
        $message = "[divengine.runner] " . $message;
        if (is_callable($logger)) {
            $logger($level, $message, $context);
            return;
        }

        if (is_callable(self::$loggerSink)) {
            (self::$loggerSink)($level, $message, $context);
        }
    }

    /**
     * Sends a log record through the context logger when available.
     *
     * @param array  $context
     * @param string $level
     * @param string $message
     * @param array  $logContext
     */
    private static function logContext(array $context, string $level, string $message, array $logContext = []): void
    {
        $logger = $context["_logger"] ?? null;
        if (is_callable($logger)) {
            $logger($level, $message, $logContext);
        }
    }

    /**
     * Ensures pause/jump tokens exist in context to classify generic exceptions.
     *
     * @param array $context
     *
     * @return array{pause:string,jump:string}
     */
    private static function ensureTokens(array &$context): array
    {
        $pauseKey = "_exception_pause_token";
        $jumpKey = "_exception_jump_token";

        if (!isset($context[$pauseKey]) || !is_string($context[$pauseKey]) || $context[$pauseKey] === "") {
            $context[$pauseKey] = self::generateToken("pause");
        }

        if (!isset($context[$jumpKey]) || !is_string($context[$jumpKey]) || $context[$jumpKey] === "") {
            $context[$jumpKey] = self::generateToken("jump");
        }

        return [
            "pause" => $context[$pauseKey],
            "jump" => $context[$jumpKey],
        ];
    }

    /**
     * Returns the call return stack from context, normalized.
     *
     * @param array $context
     *
     * @return array<int, array{block:string,step_index:int}>
     */
    private static function flowStack(array $context): array
    {
        $raw = $context["_flow_stack"] ?? [];
        if (!is_array($raw)) {
            return [];
        }

        $stack = [];
        foreach ($raw as $frame) {
            if (!is_array($frame)) {
                continue;
            }
            $stack[] = [
                "block" => is_string($frame["block"] ?? null) ? $frame["block"] : "",
                "step_index" => is_int($frame["step_index"] ?? null) ? $frame["step_index"] : 0,
            ];
        }

        return $stack;
    }

    /**
     * @param Throwable                     $e
     * @param array{pause:string,jump:string} $tokens
     */
    private static function isJumpException(Throwable $e, array $tokens): bool
    {
        return $tokens["jump"] !== "" && str_contains($e->getMessage(), $tokens["jump"]);
    }

    /**
     * @param Throwable                     $e
     * @param array{pause:string,jump:string} $tokens
     */
    private static function isPauseException(Throwable $e, array $tokens): bool
    {
        return $tokens["pause"] !== "" && str_contains($e->getMessage(), $tokens["pause"]);
    }

    /**
     * Context keys of the block callables a generated flow registered.
     *
     * @param array<string, mixed> $context
     *
     * @return array<int, string>
     */
    private static function blockKeys(array $context): array
    {
        $blocks = $context["_flow_blocks"] ?? [];
        if (!is_array($blocks)) {
            return [];
        }

        $keys = [];
        foreach ($blocks as $block) {
            if (is_string($block) && $block !== "") {
                $keys[] = "_" . $block;
            }
        }

        return $keys;
    }

    /**
     * Restores the parent's scope after an activity: position, call stack,
     * the registered block list and every block callable of the parent or of
     * a nested flow (`_flow_blocks`). Unless `$scopeOnly`, `_flow_state` and
     * `_autoresume` come back too. Other keys keep what the activity wrote.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $snapshot Context as it was before the activity.
     */
    private static function restoreControl(array &$context, array $snapshot, bool $scopeOnly): void
    {
        $keys = array_merge(
            self::SCOPE_KEYS,
            $scopeOnly ? [] : self::FLOW_CONTROL_KEYS,
            self::blockKeys($snapshot),
            self::blockKeys($context)
        );

        foreach (array_unique($keys) as $key) {
            if (array_key_exists($key, $snapshot)) {
                $context[$key] = $snapshot[$key];
            } else {
                unset($context[$key]);
            }
        }
    }

    /**
     * Classifies generic exception by looking for pause/jump tokens in message.
     *
     * @param Throwable $e
     * @param array     $context
     *
     * @return string|null
     */
    private static function classifyException(Throwable $e, array $context): ?string
    {
        $message = $e->getMessage();
        if ($message === "") {
            return null;
        }

        $pauseToken = $context["_exception_pause"] ?? $context["_exception_pause_token"] ?? null;
        $jumpToken = $context["_exception_jump"] ?? $context["_exception_jump_token"] ?? null;

        if (is_string($pauseToken) && $pauseToken !== "" && str_contains($message, $pauseToken)) {
            return "pause";
        }

        if (is_string($jumpToken) && $jumpToken !== "" && str_contains($message, $jumpToken)) {
            return "jump";
        }

        return null;
    }

    /**
     * Generates a unique token for pause/jump exception markers.
     *
     * @param string $prefix
     *
     * @return string
     */
    private static function generateToken(string $prefix): string
    {
        try {
            return $prefix . "-" . bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return $prefix . "-" . uniqid("", true);
        }
    }

    /**
     * Tracks the current step and writes a debug trace entry.
     *
     * @param string $type
     * @param string $funcName
     * @param string $stepId
     * @param int    $stepIdx
     * @param array  $context
     * @param string $info
     */
    private static function traceStep(
        string $type,
        string $funcName,
        string $stepId,
        int $stepIdx,
        array &$context,
        string $info = ""
    ): void {
        $context["_current_step_id"] = $stepId;
        $context["_current_step_index"] = $stepIdx;

        $extra = $info !== "" ? " -> {$info}" : "";
        self::logContext(
            $context,
            "DEBUG",
            "[divengine.runner] Block flow {$type} -> {$funcName}.{$stepId} [{$stepIdx}]{$extra}"
        );
    }

    /**
     * Builds a compact context summary for startup logging.
     *
     * @param array $context
     * @param int   $maxItems
     *
     * @return string
     */
    private static function contextSummary(array $context, int $maxItems = 20): string
    {
        if ($context === []) {
            return "empty";
        }

        $keys = array_keys($context);
        $parts = [];
        foreach (array_slice($keys, 0, $maxItems) as $key) {
            $parts[] = $key . "=" . self::describeValue($context[$key]);
        }

        $remaining = count($keys) - $maxItems;
        if ($remaining > 0) {
            $parts[] = "...+{$remaining} more";
        }

        return implode(", ", $parts);
    }

    /**
     * Describes a value in one line for context summaries.
     *
     * @param mixed $value
     *
     * @return string
     */
    private static function describeValue(mixed $value): string
    {
        if (is_string($value)) {
            $snippet = substr($value, 0, 100);
            $snippet = str_replace(["\n", "\r"], ["\\n", "\\r"], $snippet);
            if (strlen($value) > 100) {
                $snippet .= "...";
            }
            return $snippet;
        }

        if (is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return var_export($value, true);
        }

        if (is_array($value)) {
            return "array(" . count($value) . ")";
        }

        if (is_object($value)) {
            return get_class($value);
        }

        if (is_resource($value)) {
            return "resource(" . get_resource_type($value) . ")";
        }

        return gettype($value);
    }

    /**
     * Converts non-JSON-safe values into serializable representations.
     *
     * @param mixed $value
     * @param int   $depth
     *
     * @return mixed
     */
    private static function sanitizeForJson(mixed $value, int $depth = 0): mixed
    {
        if ($depth > 8) {
            return null;
        }

        if (is_array($value)) {
            $out = [];
            foreach ($value as $key => $item) {
                $out[$key] = self::sanitizeForJson($item, $depth + 1);
            }
            return $out;
        }

        if (is_object($value)) {
            if ($value instanceof DateTimeInterface) {
                return $value->format(DateTimeInterface::ATOM);
            }
            if (method_exists($value, "__toString")) {
                return (string) $value;
            }
            return get_class($value);
        }

        if (is_resource($value)) {
            return "resource(" . get_resource_type($value) . ")";
        }

        if (is_string($value) || is_int($value) || is_float($value) || is_bool($value) || $value === null) {
            return $value;
        }

        return null;
    }

    // ---------------------------------------------------------------------
    // Tracing and simulation. Everything below is inert unless a trace is
    // active (`self::$trace`), which only `runTraced()` and `simulate()` do.
    // ---------------------------------------------------------------------

    private const TRACE_JSON = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION
        | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PARTIAL_OUTPUT_ON_ERROR;

    private static function isYamlPath(mixed $flow): bool
    {
        return is_string($flow) && in_array(strtolower(pathinfo($flow, PATHINFO_EXTENSION)), ["yml", "yaml"], true);
    }

    /**
     * Runs with an active trace. Setup errors are recorded, not thrown; any
     * failure before the run started (an invalid logger) is rethrown.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $options
     * @param array<string, mixed> $trace
     */
    private static function executeTraced(string|callable $flow, array &$context, array $options, array &$trace): void
    {
        try {
            self::execute($flow, $context, $options, $trace);
        } catch (Throwable $e) {
            if ($trace["result"] === null) {
                throw $e;
            }
        }
    }

    /**
     * @param array<string, mixed>      $options
     * @param array<string, mixed>|null $simulation
     *
     * @return array<string, mixed>
     */
    private static function newTrace(string $mode, array $options, ?array $simulation): array
    {
        return [
            "mode" => $mode,
            "options" => $options,
            "simulation" => $simulation,
            "events" => [],
            "seq" => 0,
            "bytes" => 0,
            "truncated" => null,
            "dropped" => 0,
            "compacted" => 0,
            "forced" => [],
            "sources" => [],
            "last_function" => null,
            "phase" => null,
            "function_call_depth" => null,
            "entry" => null,
            "result" => null,
            "started_at" => (new DateTimeImmutable())->format(DateTimeInterface::ATOM),
            "started" => hrtime(true),
        ];
    }

    /**
     * @param array<string, mixed> $trace
     *
     * @return array<string, mixed>
     */
    private static function traceDocument(array $trace, string|callable $flow, string $fingerprint): array
    {
        $flowId = null;
        if (self::isYamlPath($flow) && is_string($flow) && is_file($flow)) {
            try {
                $definition = self::parseYamlFlowFile($flow);
                $flowId = is_string($definition["id"] ?? null) ? $definition["id"] : null;
            } catch (Throwable $e) {
                $flowId = null;
            }
        }

        $options = $trace["options"];
        $simulation = $trace["simulation"];

        return [
            "protocol" => self::TRACE_PROTOCOL,
            "version" => self::TRACE_VERSION,
            "mode" => $trace["mode"],
            "flow" => [
                "path" => is_string($flow) ? $flow : null,
                "id" => $flowId,
                "callable" => is_string($flow) ? null : self::callableName($flow),
            ],
            "fingerprint" => $fingerprint,
            "sources" => array_values($trace["sources"]),
            "limits" => array_diff_key($options, array_flip(["redact_keys", "exclude_keys"])),
            "budget" => $simulation !== null ? $simulation["budget"] : null,
            "result" => $trace["result"],
            "simulation" => $simulation !== null ? ["double_calls" => $simulation["calls"]] : null,
            "truncated" => self::traceIsTruncated($trace),
            "truncation" => self::traceIsTruncated($trace) ? self::traceTruncation($trace) : null,
            "events" => $trace["events"],
            "meta" => [
                "started_at" => $trace["started_at"],
                "duration_ms" => round((hrtime(true) - $trace["started"]) / 1e6, 3),
                "php_version" => PHP_VERSION,
            ],
        ];
    }

    /**
     * Appends an event unless the trace is full.
     *
     * The kept events never exceed `max_bytes` (one compact JSON line each).
     * Ordinary events use at most three quarters of it; an event that does not
     * fit in what is left has its bulky fields replaced by markers, and is
     * dropped if it still does not fit, which ends the trace. Forced events
     * (`TRACE_FORCED`, once each) use the last quarter and are compacted
     * further until they fit, so `run.end` is always present. Forced events
     * may exceed `max_events` by up to three.
     *
     * @param array<string, mixed> $data
     * @param array<string, mixed> $context
     */
    private static function traceEvent(string $type, array $data, array $context, bool $force = false): void
    {
        if (self::$trace === null) {
            return;
        }
        if ($force) {
            $force = in_array($type, self::TRACE_FORCED, true) && !isset(self::$trace["forced"][$type]);
        }
        $options = self::$trace["options"];
        if (!$force && (self::$trace["truncated"] !== null || count(self::$trace["events"]) >= $options["max_events"])) {
            self::$trace["truncated"] ??= "max_events";
            self::$trace["dropped"]++;
            return;
        }

        $flowDepth = is_int($context["_flow_depth"] ?? null) ? $context["_flow_depth"] : 1;
        $callDepth = $data["call_depth"] ?? count(self::flowStack($context));
        unset($data["call_depth"]);
        $event = [
            "seq" => self::$trace["seq"] + 1,
            "type" => $type,
            "flow_depth" => max(0, $flowDepth - 1),
            "call_depth" => $callDepth,
        ] + $data;

        $limit = $force ? $options["max_bytes"] : $options["max_bytes"] - intdiv($options["max_bytes"], 4);
        $room = $limit - self::$trace["bytes"];
        $size = self::traceSize($event);
        if ($size > $room) {
            $event = self::traceCompact($event, self::TRACE_BULKY);
            $size = self::traceSize($event);
            if ($size > $room && $force) {
                $event = self::traceCompact($event, self::TRACE_BULKY_FORCED, true);
                $size = self::traceSize($event);
            }
            if ($size > $room) {
                // Only an ordinary event can get here: compact forced events
                // fit in the quarter held back (TRACE_MIN_BYTES).
                self::$trace["truncated"] ??= "max_bytes";
                self::$trace["dropped"]++;
                return;
            }
            self::$trace["compacted"]++;
        }

        if ($force) {
            self::$trace["forced"][$type] = true;
        }
        self::$trace["seq"]++;
        self::$trace["events"][] = $event;
        self::$trace["bytes"] += $size;
    }

    /**
     * @param array<string, mixed> $event
     */
    private static function traceSize(array $event): int
    {
        return strlen((string) json_encode($event, self::TRACE_JSON)) + 1;
    }

    /**
     * Replaces the given fields of an event with `max_bytes` markers; with
     * `$minimal`, also the error message, and cuts the error class.
     *
     * @param array<string, mixed> $event
     * @param list<string>         $fields
     *
     * @return array<string, mixed>
     */
    private static function traceCompact(array $event, array $fields, bool $minimal = false): array
    {
        $marker = ['$type' => "truncated", "reason" => "max_bytes"];
        foreach ($fields as $field) {
            if (!array_key_exists($field, $event)) {
                continue;
            }
            $event[$field] = $field === "changes"
                ? [$marker + ["count" => is_array($event[$field]) ? count($event[$field]) : 0]]
                : $marker;
        }
        if ($minimal && is_array($event["error"] ?? null)) {
            $event["error"]["class"] = self::utf8Prefix((string) $event["error"]["class"], 200);
            $event["error"]["message"] = $marker;
        }
        return $event;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function traceBlockEnter(string $block, string $reason, array $context): void
    {
        if (self::$trace === null) {
            return;
        }
        self::$trace["entry"] = $reason;
        self::traceEvent("block.enter", ["block" => $block, "reason" => $reason], $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function traceRunStart(array $context): void
    {
        if (self::$trace === null) {
            return;
        }
        $state = $context["_flow_state"] ?? [];
        self::traceEvent("run.start", [
            "mode" => self::$trace["mode"],
            "resume" => is_array($state) && is_string($state["block"] ?? null) && $state["block"] !== "",
            "checkpoint" => self::traceCheckpoint($context),
            "context" => self::traceSnapshot($context),
        ], $context);
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function traceRunEnd(array $context, ?Throwable $error, bool $setup): void
    {
        if (self::$trace === null) {
            return;
        }
        $state = is_string($context["_runner_state"] ?? null) ? $context["_runner_state"] : "error";
        $result = [
            "state" => $state,
            "error" => $error !== null ? self::traceError($error) : null,
            "setup_error" => $setup,
        ];
        self::$trace["result"] = $result;

        self::traceEvent("run.end", $result + [
            "checkpoint" => self::traceCheckpoint($context),
            "context" => self::traceSnapshot($context),
        ], $context, true);

        if (self::traceIsTruncated(self::$trace)) {
            self::traceEvent("trace.truncated", self::traceTruncation(self::$trace), $context, true);
        }
    }

    /**
     * @param array<string, mixed> $trace
     */
    private static function traceIsTruncated(array $trace): bool
    {
        return $trace["truncated"] !== null || $trace["compacted"] > 0;
    }

    /**
     * Why and how much of the trace was cut: events dropped once it was full,
     * and kept events whose bulky fields became `max_bytes` markers.
     *
     * @param array<string, mixed> $trace
     *
     * @return array{reason: string, dropped_events: int, compacted_events: int}
     */
    private static function traceTruncation(array $trace): array
    {
        return [
            "reason" => $trace["truncated"] ?? "max_bytes",
            "dropped_events" => $trace["dropped"],
            "compacted_events" => $trace["compacted"],
        ];
    }

    /**
     * Position and call return frames as Runner stores them for a resume.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function traceCheckpoint(array $context): array
    {
        $state = $context["_flow_state"] ?? [];
        $checkpoint = [];
        if (is_array($state)) {
            foreach (["block", "step_index", "after_call"] as $key) {
                if (array_key_exists($key, $state) && (is_string($state[$key]) || is_int($state[$key]) || $state[$key] === null)) {
                    $checkpoint[$key] = $state[$key];
                }
            }
        }

        return ["flow_state" => $checkpoint, "flow_stack" => self::flowStack($context)];
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function currentBlock(array $context): ?string
    {
        $block = $context["_current_block_id"] ?? null;
        return is_string($block) ? $block : null;
    }

    /**
     * @param array<string, mixed> $context
     *
     * @return array{block: ?string, step: string, index: int}
     */
    private static function traceStepRef(array $context, string $stepId, int $stepIdx): array
    {
        return ["block" => self::currentBlock($context), "step" => $stepId, "index" => $stepIdx];
    }

    /**
     * @return array{class: string, message: string|array{"$type": string}}
     */
    private static function traceError(Throwable $e): array
    {
        return ["class" => get_class($e), "message" => self::traceMessage($e->getMessage())];
    }

    /**
     * An error or log message as the trace keeps it: a redaction marker
     * unless `capture_messages` is set, then capped by `max_string`.
     * Messages are free text and may hold any value, so key-based redaction
     * cannot protect them.
     *
     * @return string|array{"$type": string}
     */
    private static function traceMessage(string $message): string|array
    {
        $options = self::$trace["options"] ?? self::TRACE_DEFAULTS;
        if (!$options["capture_messages"]) {
            return ['$type' => "redacted"];
        }
        if (strlen($message) > $options["max_string"]) {
            return self::utf8Prefix($message, $options["max_string"]) . "...";
        }
        return $message;
    }

    /**
     * Logger that also records the flow's own log messages (not Runner's).
     *
     * @param array<string, mixed> $context
     */
    private static function traceLogger(callable $logger, array &$context): callable
    {
        return function (string $level, string $message, array $logContext = []) use ($logger, &$context): void {
            $logger($level, $message, $logContext);
            if (self::$trace !== null && !str_starts_with($message, "[divengine.runner]")) {
                self::traceEvent("log", [
                    "call_depth" => self::$trace["function_call_depth"] ?? count(self::flowStack($context)),
                    "block" => self::currentBlock($context),
                    "step" => is_string($context["_current_step_id"] ?? null) ? $context["_current_step_id"] : null,
                    "level" => $level,
                    "message" => self::traceMessage($message),
                ], $context);
            }
        };
    }

    private static function traceFunction(string $name, mixed $func, ?string $file): void
    {
        if (self::$trace === null) {
            return;
        }
        self::$trace["last_function"] = ["name" => $name, "callable" => $func, "kind" => "function"];
        self::traceSource("function", $name, $file);
    }

    private static function traceSource(string $role, string $name, ?string $file): void
    {
        if (self::$trace === null || $file === null) {
            return;
        }
        $source = self::fileSource($role, $name, $file);
        self::$trace["sources"][$role . ":" . $source["path"]] ??= $source;
    }

    /**
     * @return array{role: string, name: string, path: string, sha256: ?string}
     */
    private static function fileSource(string $role, string $name, string $file): array
    {
        $path = realpath($file);
        $path = $path === false ? $file : $path;
        $hash = is_file($path) ? hash_file("sha256", $path) : false;

        return ["role" => $role, "name" => $name, "path" => $path, "sha256" => $hash === false ? null : $hash];
    }

    /**
     * Fingerprint of the sources known before a run: the flow, and in a
     * simulation its doubles and nested flows, and a non-empty `function_map`.
     * Paths do not take part.
     *
     * @param array<string, array<string, mixed>> $sources
     */
    private static function fingerprint(array $sources): string
    {
        $parts = [];
        foreach ($sources as $source) {
            $parts[] = [$source["role"], $source["role"] === "flow" ? "" : $source["name"], $source["sha256"]];
        }
        sort($parts);

        return "sha256:" . hash("sha256", (string) json_encode([self::TRACE_VERSION, $parts]));
    }

    /**
     * The `function_map` option as a source: it decides which function each
     * reference resolves to, so a changed map changes the fingerprint. An
     * empty map is no source, as if the option were absent.
     *
     * @return array<string, array{role: string, name: string, path: null, sha256: string}>
     *
     * @throws RuntimeException On an invalid map.
     */
    private static function functionMapSource(mixed $map): array
    {
        $normalized = self::normalizeFunctionMap($map);
        if ($normalized === []) {
            return [];
        }
        ksort($normalized, SORT_STRING);

        return ["function_map" => [
            "role" => "function_map",
            "name" => "function_map",
            "path" => null,
            "sha256" => hash("sha256", self::canonicalJson($normalized)),
        ]];
    }

    private static function canonicalJson(mixed $value): string
    {
        $canonical = static function (mixed $value) use (&$canonical): mixed {
            if (!is_array($value)) {
                return $value;
            }
            if (!array_is_list($value)) {
                ksort($value, SORT_STRING);
            }
            return array_map($canonical, $value);
        };

        return (string) json_encode($canonical($value), self::TRACE_JSON);
    }

    private static function traceFunctionName(callable $func): string
    {
        $last = self::$trace["last_function"] ?? null;
        if (is_array($last) && $last["callable"] === $func) {
            return $last["name"];
        }
        return self::callableName($func);
    }

    /**
     * Business keys of the context (not `_`-prefixed), as trace values. Keys
     * share one `max_nodes` allowance; keys past it and keys longer than
     * `max_string` are counted in `$more`.
     *
     * @param array<string, mixed> $context
     *
     * @return array<int|string, mixed>
     */
    private static function traceSnapshot(array $context): array
    {
        $options = self::$trace["options"] ?? self::TRACE_DEFAULTS;
        $nodes = $options["max_nodes"];
        $snapshot = [];
        $more = 0;
        foreach ($context as $key => $value) {
            if (!self::isBusinessKey($key, $options)) {
                continue;
            }
            if ($nodes <= 0 || self::isLongKey($key, $options)) {
                $more++;
                continue;
            }
            $snapshot[$key] = self::traceValue($value, $key, $nodes);
        }
        if ($more > 0) {
            $snapshot['$more'] = $more;
        }

        return $snapshot;
    }

    /**
     * Changes of business keys between two contexts, in key order of `$after`.
     * Changes share one `max_nodes` allowance; changes past it and changes of
     * keys longer than `max_string` are counted in a final
     * `{"$type": "more", "count": n}` item.
     *
     * @param array<string, mixed> $before
     * @param array<string, mixed> $after
     *
     * @return list<array<string, mixed>>
     */
    private static function traceChanges(array $before, array $after): array
    {
        if (self::$trace === null || self::$trace["truncated"] !== null) {
            return []; // The event that would hold them is dropped.
        }
        $options = self::$trace["options"];
        $nodes = $options["max_nodes"];
        $changes = [];
        $more = 0;
        foreach ($after as $key => $value) {
            if (!self::isBusinessKey($key, $options)
                || (array_key_exists($key, $before) && $before[$key] === $value)) {
                continue;
            }
            if ($nodes <= 0 || self::isLongKey($key, $options)) {
                $more++;
            } elseif (!array_key_exists($key, $before)) {
                $changes[] = ["key" => $key, "op" => "added", "after" => self::traceValue($value, $key, $nodes)];
            } else {
                $changes[] = [
                    "key" => $key,
                    "op" => "changed",
                    "before" => self::traceValue($before[$key], $key, $nodes),
                    "after" => self::traceValue($value, $key, $nodes),
                ];
            }
        }
        foreach ($before as $key => $value) {
            if (!self::isBusinessKey($key, $options) || array_key_exists($key, $after)) {
                continue;
            }
            if ($nodes-- <= 0 || self::isLongKey($key, $options)) {
                $more++; // A removal holds no value but still costs a node.
            } else {
                $changes[] = ["key" => $key, "op" => "removed"];
            }
        }
        if ($more > 0) {
            $changes[] = ['$type' => "more", "count" => $more];
        }

        return $changes;
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function isBusinessKey(int|string $key, array $options): bool
    {
        return !(is_string($key) && str_starts_with($key, "_")) && !self::isExcludedKey($key, $options);
    }

    /**
     * Keys longer than `max_string` are left out of trace values and counted.
     *
     * @param array<string, mixed> $options
     */
    private static function isLongKey(int|string $key, array $options): bool
    {
        return is_string($key) && strlen($key) > $options["max_string"];
    }

    /**
     * A JSON-safe, bounded and redacted copy of a value. Values JSON cannot
     * hold, and values cut by the caps, become markers `{"$type": ...}`.
     */
    private static function traceValue(mixed $value, int|string|null $key, ?int &$nodes = null): mixed
    {
        $options = self::$trace["options"] ?? self::TRACE_DEFAULTS;
        if ($nodes === null) {
            $nodes = $options["max_nodes"];
        }
        return self::traceNode($value, $key, 0, $nodes, $options);
    }

    /**
     * @param array<string, mixed> $options
     */
    private static function traceNode(mixed $value, int|string|null $key, int $depth, int &$nodes, array $options): mixed
    {
        if ($key !== null && self::isRedactedKey($key, $options)) {
            return ['$type' => "redacted"];
        }
        if (--$nodes < 0) {
            return ['$type' => "truncated"];
        }
        if (!$options["capture_values"]) {
            return ['$type' => get_debug_type($value)];
        }

        if ($value === null || is_bool($value) || is_int($value)) {
            return $value;
        }
        if (is_float($value)) {
            if (is_finite($value)) {
                return $value;
            }
            return ['$type' => "float", "value" => is_nan($value) ? "NAN" : ($value > 0 ? "INF" : "-INF")];
        }
        if (is_string($value)) {
            if (preg_match('//u', $value) !== 1) {
                return ['$type' => "binary", "length" => strlen($value)];
            }
            if (strlen($value) > $options["max_string"]) {
                return [
                    '$type' => "string",
                    "truncated" => true,
                    "length" => strlen($value),
                    "value" => self::utf8Prefix($value, $options["max_string"]),
                ];
            }
            return $value;
        }
        if (is_array($value)) {
            if ($depth >= $options["max_depth"]) {
                return ['$type' => "array", "truncated" => true, "count" => count($value)];
            }
            $isList = array_is_list($value);
            $out = [];
            $kept = 0;
            $skipped = 0;
            foreach ($value as $itemKey => $item) {
                if (self::isExcludedKey($itemKey, $options)) {
                    $skipped++;
                    continue;
                }
                if ($kept >= $options["max_items"] || $nodes <= 0) {
                    break;
                }
                if (self::isLongKey($itemKey, $options)) {
                    continue; // Counted in `$more` below.
                }
                $out[$itemKey] = self::traceNode($item, $itemKey, $depth + 1, $nodes, $options);
                $kept++;
            }
            $more = count($value) - $kept - $skipped;
            if ($more > 0) {
                if ($isList) {
                    $out[] = ['$type' => "more", "count" => $more];
                } else {
                    $out['$more'] = $more;
                }
            }
            return $out;
        }
        if ($value instanceof \Closure) {
            return ['$type' => "closure"];
        }
        if (is_object($value)) {
            return ['$type' => "object", "class" => get_class($value)];
        }

        return ['$type' => "resource", "kind" => is_resource($value) ? get_resource_type($value) : "closed"];
    }

    /**
     * @param array<string, mixed>|null $options
     */
    private static function isRedactedKey(int|string $key, ?array $options = null): bool
    {
        $options = $options ?? self::$trace["options"] ?? self::TRACE_DEFAULTS;
        $key = (string) $key;
        if (in_array(strtolower($key), $options["redact_keys"], true)) {
            return true;
        }
        return ($options["redact_defaults"] ?? true) && preg_match(self::REDACT_PATTERN, $key) === 1;
    }

    /**
     * @param array<string, mixed>|null $options
     */
    private static function isExcludedKey(int|string $key, ?array $options = null): bool
    {
        $options = $options ?? self::$trace["options"] ?? self::TRACE_DEFAULTS;
        return in_array((string) $key, $options["exclude_keys"], true);
    }

    private static function utf8Prefix(string $value, int $bytes): string
    {
        $prefix = substr($value, 0, $bytes);
        for ($i = 0; $i < 4 && $prefix !== "" && preg_match('//u', $prefix) !== 1; $i++) {
            $prefix = substr($prefix, 0, -1);
        }
        return $prefix;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeTraceOptions(mixed $options): array
    {
        if (!is_array($options)) {
            throw new InvalidArgumentException("[divengine.runner] Trace options must be an array.");
        }
        $defaults = self::TRACE_DEFAULTS + ["redact_defaults" => true];
        $unknown = array_diff(array_keys($options), array_keys($defaults));
        if ($unknown !== []) {
            throw new InvalidArgumentException("[divengine.runner] Unknown trace option: " . implode(", ", $unknown));
        }
        $options += $defaults;

        foreach (["max_events", "max_bytes", "max_string", "max_items", "max_depth", "max_nodes"] as $key) {
            if (!is_int($options[$key]) || $options[$key] < 1) {
                throw new InvalidArgumentException("[divengine.runner] Trace option '{$key}' must be a positive integer.");
            }
        }
        if ($options["max_bytes"] < self::TRACE_MIN_BYTES) {
            throw new InvalidArgumentException(
                "[divengine.runner] Trace option 'max_bytes' must be at least " . self::TRACE_MIN_BYTES . "."
            );
        }
        foreach (["capture_values", "capture_messages", "redact_defaults"] as $key) {
            if (!is_bool($options[$key])) {
                throw new InvalidArgumentException("[divengine.runner] Trace option '{$key}' must be a boolean.");
            }
        }
        foreach (["redact_keys", "exclude_keys"] as $key) {
            if (!is_array($options[$key]) || !array_is_list($options[$key])
                || array_filter($options[$key], fn (mixed $k): bool => !is_string($k) || $k === "") !== []) {
                throw new InvalidArgumentException("[divengine.runner] Trace option '{$key}' must be a list of key names.");
            }
        }
        $options["redact_keys"] = array_map("strtolower", $options["redact_keys"]);

        return $options;
    }

    /**
     * @return array{steps: int, transfers: int, seconds: float}
     */
    private static function normalizeBudget(mixed $budget): array
    {
        if (!is_array($budget)) {
            throw new InvalidArgumentException("[divengine.runner] Simulation budget must be an array.");
        }
        $unknown = array_diff(array_keys($budget), array_keys(self::BUDGET_DEFAULTS));
        if ($unknown !== []) {
            throw new InvalidArgumentException("[divengine.runner] Unknown simulation budget: " . implode(", ", $unknown));
        }
        $budget += self::BUDGET_DEFAULTS;
        foreach (["steps", "transfers"] as $key) {
            if (!is_int($budget[$key]) || $budget[$key] < 1) {
                throw new InvalidArgumentException("[divengine.runner] Simulation budget '{$key}' must be a positive integer.");
            }
        }
        if (!(is_int($budget["seconds"]) || is_float($budget["seconds"])) || $budget["seconds"] <= 0) {
            throw new InvalidArgumentException("[divengine.runner] Simulation budget 'seconds' must be a positive number.");
        }

        return ["steps" => $budget["steps"], "transfers" => $budget["transfers"], "seconds" => (float) $budget["seconds"]];
    }

    /**
     * @return array<string, int>
     */
    private static function normalizeDoubleCalls(mixed $calls): array
    {
        if (!is_array($calls)) {
            throw new InvalidArgumentException("[divengine.runner] Option 'double_calls' must be an array.");
        }
        foreach ($calls as $name => $count) {
            if (!is_string($name) || !is_int($count) || $count < 0) {
                throw new InvalidArgumentException("[divengine.runner] Option 'double_calls' maps double names to call counts.");
            }
        }

        return $calls;
    }

    /**
     * @param array<mixed> $doubles
     *
     * @return array<string, array<string, mixed>>
     */
    private static function normalizeDoubles(array $doubles): array
    {
        $normalized = [];
        foreach ($doubles as $name => $spec) {
            $label = is_string($name) ? $name : (string) $name;
            if (!is_string($name) || trim($name) === "" || trim($name) !== $name) {
                throw new InvalidArgumentException("[divengine.runner] Invalid double name: '{$label}'.");
            }
            // `module:name` qualifies a function; `Class::method` is a plain callable name.
            if (str_contains($name, ":") && !str_contains($name, "::")) {
                [$module, $function] = explode(":", $name, 2);
                $problem = self::moduleReferenceProblem($module, $function);
                if ($problem !== null) {
                    throw new InvalidArgumentException("[divengine.runner] Invalid module in double name '{$name}': {$problem}.");
                }
            }
            if (!is_array($spec) || ($spec !== [] && array_is_list($spec))) {
                throw new InvalidArgumentException("[divengine.runner] Double '{$name}' must be a mapping.");
            }

            $kinds = array_values(array_intersect(["returns", "throws", "pause", "sequence", "flow"], array_keys($spec)));
            if (count($kinds) !== 1) {
                throw new InvalidArgumentException(
                    "[divengine.runner] Double '{$name}' needs exactly one of returns, throws, pause, sequence, flow."
                );
            }

            if ($kinds[0] === "flow") {
                $unknown = array_diff(array_keys($spec), ["flow", "description"]);
                $path = $spec["flow"];
                if ($unknown !== [] || !is_string($path) || !self::isYamlPath($path)) {
                    throw new InvalidArgumentException("[divengine.runner] Double '{$name}': 'flow' takes only a .yml or .yaml path.");
                }
                $real = realpath($path);
                if ($real === false || !is_file($real)) {
                    throw new InvalidArgumentException("[divengine.runner] Double '{$name}': nested flow not found: {$path}");
                }
                $normalized[$name] = ["kind" => "flow", "path" => $real];
                continue;
            }

            if ($kinds[0] === "sequence") {
                $unknown = array_diff(array_keys($spec), ["sequence", "description"]);
                if ($unknown !== [] || !is_array($spec["sequence"]) || $spec["sequence"] === [] || !array_is_list($spec["sequence"])) {
                    throw new InvalidArgumentException("[divengine.runner] Double '{$name}': 'sequence' must be a non-empty list of outcomes.");
                }
                $outcomes = [];
                foreach ($spec["sequence"] as $i => $outcome) {
                    $outcomes[] = self::normalizeOutcome($outcome, "{$name}.sequence[{$i}]");
                }
                $normalized[$name] = ["kind" => "outcomes", "sequence" => true, "outcomes" => $outcomes];
                continue;
            }

            $outcome = $spec;
            unset($outcome["description"]);
            $normalized[$name] = ["kind" => "outcomes", "sequence" => false, "outcomes" => [self::normalizeOutcome($outcome, $name)]];
        }

        return $normalized;
    }

    /**
     * @return array<string, mixed>
     */
    private static function normalizeOutcome(mixed $outcome, string $label): array
    {
        if (!is_array($outcome) || ($outcome !== [] && array_is_list($outcome))) {
            throw new InvalidArgumentException("[divengine.runner] Double outcome {$label} must be a mapping.");
        }
        $unknown = array_diff(array_keys($outcome), ["returns", "throws", "pause", "context"]);
        $kinds = array_values(array_intersect(["returns", "throws", "pause"], array_keys($outcome)));
        if ($unknown !== [] || count($kinds) !== 1) {
            throw new InvalidArgumentException(
                "[divengine.runner] Double outcome {$label} needs exactly one of returns, throws, pause, and optionally context."
            );
        }
        if ($kinds[0] === "throws" && (!is_string($outcome["throws"]) || trim($outcome["throws"]) === "")) {
            throw new InvalidArgumentException("[divengine.runner] Double outcome {$label}: 'throws' takes an error message.");
        }
        if ($kinds[0] === "pause" && $outcome["pause"] !== true) {
            throw new InvalidArgumentException("[divengine.runner] Double outcome {$label}: 'pause' must be true.");
        }
        if (array_key_exists("context", $outcome)) {
            $context = $outcome["context"];
            if ($kinds[0] === "throws" || !is_array($context) || ($context !== [] && array_is_list($context))) {
                throw new InvalidArgumentException(
                    "[divengine.runner] Double outcome {$label}: 'context' is a mapping, used with returns or pause."
                );
            }
            foreach (array_keys($context) as $key) {
                if (!is_string($key) || str_starts_with($key, "_")) {
                    throw new InvalidArgumentException(
                        "[divengine.runner] Double outcome {$label}: context key '{$key}' is reserved or invalid."
                    );
                }
            }
        }

        return $outcome;
    }

    /**
     * @param array<string, array<string, mixed>> $doubles
     *
     * @return array<string, mixed>
     */
    private static function doublesForFingerprint(array $doubles): array
    {
        // A nested flow takes part through its own source hash, not its path.
        return array_map(
            fn (array $double): array => $double["kind"] === "flow" ? ["kind" => "flow"] : $double,
            $doubles
        );
    }

    /**
     * Resolves a function reference to its declared double. Never imports.
     *
     * @param array<string, mixed> $context
     */
    private static function resolveDouble(string $name, ?string $module, array $context): callable
    {
        $qualified = self::qualifiedName(trim($name), $module);
        $double = self::$trace["simulation"]["doubles"][$qualified] ?? null;
        if ($double === null) {
            self::traceEvent("function.missing", [
                "block" => self::currentBlock($context),
                "function" => $qualified,
            ], $context);
            throw new RuntimeException(
                "[divengine.runner] Simulation has no double for function '{$qualified}'; functions are never imported in simulation."
            );
        }

        if ($double["kind"] === "flow") {
            $compiled = self::$trace["simulation"]["compiled"][$qualified] ?? null;
            if ($compiled === null) {
                $functionMap = self::$trace["simulation"]["function_map"];
                $compiled = self::flowFromYaml($double["path"], ["function_map" => $functionMap]);
                self::$trace["simulation"]["compiled"][$qualified] = $compiled;
            }
            self::$trace["last_function"] = ["name" => $qualified, "callable" => $compiled, "kind" => "flow"];
            return $compiled;
        }

        $callable = static function (array &$context) use ($qualified): mixed {
            return self::invokeDouble($qualified, $context);
        };
        self::$trace["last_function"] = ["name" => $qualified, "callable" => $callable, "kind" => "double"];

        return $callable;
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function invokeDouble(string $qualified, array &$context): mixed
    {
        $double = self::$trace["simulation"]["doubles"][$qualified];
        $call = self::$trace["simulation"]["calls"][$qualified] ?? 0;
        self::$trace["simulation"]["calls"][$qualified] = $call + 1;
        $outcome = $double["sequence"] ? ($double["outcomes"][$call] ?? null) : $double["outcomes"][0];
        $ref = [
            "call_depth" => self::$trace["function_call_depth"] ?? count(self::flowStack($context)),
            "block" => self::currentBlock($context),
            "step" => is_string($context["_current_step_id"] ?? null) ? $context["_current_step_id"] : null,
            "index" => is_int($context["_current_step_index"] ?? null) ? $context["_current_step_index"] : null,
            "function" => $qualified,
            "call" => $call + 1,
        ];

        if ($outcome === null) {
            self::traceEvent("double", $ref + ["outcome" => "exhausted"], $context);
            throw new RuntimeException(
                "[divengine.runner] Double '{$qualified}' has no outcome for call " . ($call + 1)
                . " (its sequence has " . count($double["outcomes"]) . ")."
            );
        }

        $kind = array_key_exists("returns", $outcome) ? "returns" : (isset($outcome["throws"]) ? "throws" : "pause");
        self::traceEvent("double", $ref + ["outcome" => $kind], $context);

        if ($kind === "pause" && self::$trace["phase"] === "condition") {
            throw new RuntimeException("[divengine.runner] Double '{$qualified}' pauses, but only activities can pause.");
        }
        if (isset($outcome["context"])) {
            $context = array_replace($context, $outcome["context"]);
        }
        if ($kind === "throws") {
            throw new RuntimeException($outcome["throws"]);
        }
        if ($kind === "pause") {
            $tokens = self::ensureTokens($context);
            throw new RuntimeException("{$tokens["pause"]} Pause requested by double {$qualified}");
        }

        return $outcome["returns"];
    }

    private static function simulationGuardCondition(string $function): void
    {
        $last = self::$trace["last_function"] ?? null;
        if (self::$trace !== null && self::$trace["simulation"] !== null && is_array($last)
            && $last["kind"] === "flow" && $last["name"] === $function) {
            throw new RuntimeException("[divengine.runner] Double '{$function}' is a nested flow; use it as an activity only.");
        }
    }

    /**
     * Counts a step or a transfer against the simulation budget.
     *
     * @param array<string, mixed> $context
     */
    private static function simulationTick(string $counter, array $context): void
    {
        if (self::$trace === null || self::$trace["simulation"] === null) {
            return;
        }
        $used = ++self::$trace["simulation"]["counters"][$counter];
        $budget = self::$trace["simulation"]["budget"];

        if ($used > $budget[$counter]) {
            self::exhaustBudget($counter, $budget[$counter], $used, $context);
        }
        $elapsed = (hrtime(true) - self::$trace["simulation"]["started"]) / 1e9;
        if ($elapsed > $budget["seconds"]) {
            self::exhaustBudget("seconds", $budget["seconds"], round($elapsed, 3), $context);
        }
        $cancel = self::$trace["simulation"]["cancel"];
        if ($cancel !== null && $cancel() === true) {
            self::exhaustBudget("cancel", null, null, $context);
        }
    }

    /**
     * @param array<string, mixed> $context
     */
    private static function exhaustBudget(string $budget, int|float|null $limit, int|float|null $used, array $context): never
    {
        self::traceEvent("budget.exhausted", ["budget" => $budget, "limit" => $limit, "used" => $used], $context, true);
        throw new RuntimeException(
            $budget === "cancel"
                ? "[divengine.runner] Simulation cancelled."
                : "[divengine.runner] Simulation budget exhausted: {$budget} (limit {$limit})."
        );
    }
}
