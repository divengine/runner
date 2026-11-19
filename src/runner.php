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
     * Keys describing where the running flow is; restored after any activity.
     */
    private const SCOPE_KEYS = ["_current_block_id", "_current_step_id", "_current_step_index", "_flow_stack"];

    /**
     * Keys an activity may set on purpose; restored only after a nested pause.
     */
    private const FLOW_CONTROL_KEYS = ["_flow_state", "_autoresume"];

    /**
     * Runner services stored as closures that are never block callables.
     */
    private const SERVICE_KEYS = ["_logger", "_importer"];

    /**
     * Keys accepted in a YAML flow definition (root, block and step level).
     */
    private const FLOW_KEYS = ["id", "description", "blocks"];
    private const BLOCK_KEYS = ["id", "description", "steps"];
    private const STEP_KEYS = ["id", "description", "condition", "context", "call", "activity", "jump", "pause"];

    /**
     * Context keys owned by the runner; a block id must not map onto them.
     */
    private const RESERVED_KEYS = [
        "_root_folder", "_logger", "_importer", "_update_context", "_wrap_condition", "_wrap_activity", "_wrap_call",
        "_wrap_jump", "_wrap_pause", "_check_pause", "_resume_after_call", "__flow_loop", "_exception_pause",
        "_exception_jump", "_exception_pause_token", "_exception_jump_token", "_flow_state", "_flow_stack",
        "_flow_depth", "_nested_flow_paused", "_current_block_id", "_current_step_id", "_current_step_index",
        "_autoresume", "_max_call_depth",
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
     * Imports a callable from file path or callable identifier.
     *
     * File resolution happens at runtime using `_root_folder` from context
     * (if present), otherwise `DIV_RUNNER_ROOT_FOLDER`.
     *
     * @param string              $path
     * @param callable|null       $logger
     * @param array<string, mixed> $context
     *
     * @return callable|null
     */
    public static function importer(string $path, ?callable $logger = null, array $context = []): ?callable
    {
        $logger = $logger ?? self::$loggerSink;

        $reference = trim($path);
        if ($reference === "") {
            self::emitLog($logger, "ERROR", "Import path is empty.");
            return null;
        }

        // Resolution order: PHP files first (root folder, then the raw path),
        // and only then callable identifiers such as `strlen` or `ns\fn`.
        $callableReference = ltrim($reference, "\\");

        $candidates = self::buildImportPathCandidates($reference, $context);
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
                return null;
            }

            if (!is_callable($func)) {
                self::emitLog($logger, "ERROR", "Import file must return a callable: {$candidate}");
                return null;
            }

            return $func;
        }

        if (is_callable($callableReference)) {
            return $callableReference;
        }

        self::emitLog($logger, "ERROR", "Import target not found or not callable: {$reference}");
        return null;
    }

    /**
     * Generates PHP closure code from a YAML flow file.
     *
     * @param string $yamlPath
     * @param array  $options
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
        $functionMap = isset($options["function_map"]) && is_array($options["function_map"])
            ? $options["function_map"]
            : [];

        $lines = [];
        $functionMapCode = var_export($functionMap, true);
        $lines[] = "return function (array &\$context): void {";
        $lines[] = "    \$importer = \$context[\"_importer\"] ?? null;";
        $lines[] = "    \$flowLoop = \$context[\"__flow_loop\"] ?? null;";
        $lines[] = "    \$checkPause = \$context[\"_check_pause\"] ?? null;";
        $lines[] = "    \$updateContext = \$context[\"_update_context\"] ?? null;";
        $lines[] = "    \$wrapCondition = \$context[\"_wrap_condition\"] ?? null;";
        $lines[] = "    \$wrapActivity = \$context[\"_wrap_activity\"] ?? null;";
        $lines[] = "    \$wrapCall = \$context[\"_wrap_call\"] ?? null;";
        $lines[] = "    \$wrapJump = \$context[\"_wrap_jump\"] ?? null;";
        $lines[] = "    \$wrapPause = \$context[\"_wrap_pause\"] ?? null;";
        $lines[] = "    \$resumeAfterCall = \$context[\"_resume_after_call\"] ?? null;";
        $lines[] = "";
        $lines[] = "    if (";
        $lines[] = "        !is_callable(\$importer)";
        $lines[] = "        || !is_callable(\$flowLoop)";
        $lines[] = "        || !is_callable(\$checkPause)";
        $lines[] = "        || !is_callable(\$updateContext)";
        $lines[] = "        || !is_callable(\$wrapCondition)";
        $lines[] = "        || !is_callable(\$wrapActivity)";
        $lines[] = "        || !is_callable(\$wrapCall)";
        $lines[] = "        || !is_callable(\$wrapJump)";
        $lines[] = "        || !is_callable(\$wrapPause)";
        $lines[] = "        || !is_callable(\$resumeAfterCall)";
        $lines[] = "    ) {";
        $lines[] = "        throw new \\RuntimeException(\"Missing flow helpers in context.\");";
        $lines[] = "    }";
        $lines[] = "";
        $lines[] = "    \$functionMap = {$functionMapCode};";
        $lines[] = "    \$resolveFunction = function (string \$reference) use (\$importer, \$functionMap): callable {";
        $lines[] = "        \$mapped = \$reference;";
        $lines[] = "        if (isset(\$functionMap[\$reference]) && is_string(\$functionMap[\$reference]) && trim(\$functionMap[\$reference]) !== \"\") {";
        $lines[] = "            \$mapped = trim(\$functionMap[\$reference]);";
        $lines[] = "        }";
        $lines[] = "";
        $lines[] = "        \$callable = \$importer(\$mapped);";
        $lines[] = "        if (!is_callable(\$callable)) {";
        $lines[] = "            throw new \\RuntimeException(\"Function import failed: \" . \$reference);";
        $lines[] = "        }";
        $lines[] = "";
        $lines[] = "        return \$callable;";
        $lines[] = "    };";
        $lines[] = "";

        foreach ($blocks as $block) {
            $blockId = $block["id"];
            $blockIdCode = var_export($blockId, true);
            $contextBlockKeyCode = var_export("_" . $blockId, true);
            $lines[] = "";
            $lines[] = "    \$context[{$contextBlockKeyCode}] = function (array &\$context) use (";
            $lines[] = "        \$checkPause,";
            $lines[] = "        \$updateContext,";
            $lines[] = "        \$wrapCondition,";
            $lines[] = "        \$wrapActivity,";
            $lines[] = "        \$wrapCall,";
            $lines[] = "        \$wrapJump,";
            $lines[] = "        \$wrapPause,";
            $lines[] = "        \$resumeAfterCall,";
            $lines[] = "        \$resolveFunction";
            $lines[] = "    ): mixed {";

            $steps = $block["steps"];
            foreach ($steps as $stepPosition => $step) {
                $stepIdx = $stepPosition + 1;
                $stepIdCode = var_export($step["id"], true);
                $lines[] = "        if (\$checkPause({$stepIdx}, \$context)) {";
                $indent = "            ";

                $conditionRef = isset($step["condition"]) && is_string($step["condition"])
                    ? trim($step["condition"])
                    : "";
                $callRef = isset($step["call"]) && is_string($step["call"]) ? trim($step["call"]) : "";
                $hasCall = $callRef !== "";

                // A step with a call can be re-entered after the call returns (resume
                // inside the called block): condition, context update and call already
                // ran, so only activity, jump and pause remain.
                if ($hasCall) {
                    $lines[] = "            \$afterCall = \$resumeAfterCall({$stepIdx}, \$context);";
                }

                if ($conditionRef !== "") {
                    $conditionRefCode = var_export($conditionRef, true);
                    $guard = $hasCall ? "\$afterCall || " : "";
                    $lines[] = "            if ({$guard}\$wrapCondition(\$resolveFunction({$conditionRefCode}), {$stepIdCode}, {$stepIdx}, \$context)) {";
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
                    $targetCallableKeyCode = var_export("_" . $targetBlock, true);
                    $targetBlockCode = var_export($targetBlock, true);
                    $lines[] = "{$beforeCallIndent}\$targetCallable = \$context[{$targetCallableKeyCode}] ?? null;";
                    $lines[] = "{$beforeCallIndent}if (!is_callable(\$targetCallable)) {";
                    $lines[] = "{$beforeCallIndent}    throw new \\RuntimeException(\"Missing block callable for \" . {$targetCallableKeyCode});";
                    $lines[] = "{$beforeCallIndent}}";
                    $lines[] = "{$beforeCallIndent}\$wrapCall(\$targetCallable, {$stepIdCode}, {$stepIdx}, \$context, {$targetBlockCode}, {$targetIndex}, {$blockIdCode});";
                    $lines[] = "{$indent}}";
                }

                $activityRef = isset($step["activity"]) && is_string($step["activity"])
                    ? trim($step["activity"])
                    : "";
                if ($activityRef !== "") {
                    $activityRefCode = var_export($activityRef, true);
                    $lines[] = "{$indent}\$wrapActivity(\$resolveFunction({$activityRefCode}), {$stepIdCode}, {$stepIdx}, \$context);";
                }

                $jumpRef = isset($step["jump"]) && is_string($step["jump"]) ? trim($step["jump"]) : "";
                if ($jumpRef !== "") {
                    [$targetBlock, $targetIndex] = self::resolveBlockTarget($jumpRef, $blockId, $stepIndexMap, "jump");
                    $targetCallableKeyCode = var_export("_" . $targetBlock, true);
                    $targetBlockCode = var_export($targetBlock, true);
                    $lines[] = "{$indent}\$targetCallable = \$context[{$targetCallableKeyCode}] ?? null;";
                    $lines[] = "{$indent}if (!is_callable(\$targetCallable)) {";
                    $lines[] = "{$indent}    throw new \\RuntimeException(\"Missing block callable for \" . {$targetCallableKeyCode});";
                    $lines[] = "{$indent}}";
                    $lines[] = "{$indent}\$wrapJump(\$targetCallable, {$stepIdCode}, {$stepIdx}, \$context, {$targetBlockCode}, {$targetIndex});";
                }

                $pause = isset($step["pause"]) && $step["pause"] === true;
                if ($pause) {
                    $lines[] = "{$indent}\$wrapPause({$blockIdCode}, {$stepIdCode}, {$stepIdx}, \$context);";
                }

                if ($conditionRef !== "") {
                    $lines[] = "            }";
                }

                $lines[] = "        }";
                $lines[] = "";
            }

            $lines[] = "        return null;";
            $lines[] = "    };";
        }

        $initialBlockCode = var_export($initialBlock, true);
        $lines[] = "";
        $lines[] = "    \$flowLoop({$initialBlockCode}, \$context);";
        $lines[] = "};";

        return implode("\n", $lines);
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
        $loggerSink = $options["logger"] ?? self::$loggerSink;
        if ($loggerSink !== null && !is_callable($loggerSink)) {
            throw new RuntimeException("Logger must be a callable.");
        }
        $requireContextParamName = $options["require_context_param_name"] ?? true;

        $records = [];
        $logger = self::makeLogger($loggerSink, $records);

        if (isset($options["shared_context"]) && is_array($options["shared_context"])) {
            $context = array_merge($options["shared_context"], $context);
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
        $context["_logger"] = $logger;
        $context["_importer"] = function (string $path, ?string $unused = null) use (
            $logger,
            &$context
        ): ?callable {
            return self::importer($path, $logger, $context);
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

        if (!empty($context["flow_paused"])) {
            $context["flow_paused"] = false;
            $logger("INFO", "[divengine.runner] Resuming paused job execution");
        }

        // Setup failures (unreadable/invalid YAML, unresolved flow, bad signature)
        // are thrown to the caller, with the context left in the `error` state.
        try {
            $func = $flow;
            if (is_string($flow)) {
                $extension = strtolower(pathinfo($flow, PATHINFO_EXTENSION));
                if ($extension === "yml" || $extension === "yaml") {
                    $func = self::flowFromYaml($flow, $options);
                } else {
                    $func = self::importer($flow, $logger, $context);
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
            $context["_runner_logs"] = $records;
            $context["_runner_ended_at"] = (new DateTimeImmutable())->format(DateTimeInterface::ATOM);
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
                $logger("ERROR", "[divengine.runner] Error processing job - " . $context["_runner_error"]);
            }
        }

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
        $context["_flow_depth"] = $depth + 1;

        try {
            return self::runBlocks($initialBlock, $context, $tokens);
        } catch (Throwable $e) {
            if ($depth > 0 && self::isPauseException($e, $tokens)) {
                // Tells the enclosing activity that this nested flow paused.
                $context["_nested_flow_paused"] = true;
            }
            throw $e;
        } finally {
            $context["_flow_depth"] = $depth;
        }
    }

    /**
     * @param array{pause:string,jump:string} $tokens
     */
    private static function runBlocks(string $initialBlock, array &$context, array $tokens): mixed
    {
        while (true) {
            $state = $context["_flow_state"] ?? [];
            $savedBlock = is_array($state) ? ($state["block"] ?? null) : null;

            if (is_string($savedBlock) && $savedBlock !== "") {
                $initialBlock = $savedBlock;
            }

            $key = "_" . $initialBlock;
            if (!isset($context[$key]) || !is_callable($context[$key])) {
                throw new RuntimeException("Missing block callable for {$key}");
            }

            $context["_current_block_id"] = $initialBlock;

            try {
                $result = ($context[$key])($context);
            } catch (Throwable $e) {
                if (!self::isJumpException($e, $tokens)) {
                    throw $e;
                }

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
                    $context["_flow_stack"] = $kept;
                }
                continue;
            }

            $stack = self::flowStack($context);
            // A frame without a known calling block cannot be resumed.
            while ($stack !== [] && $stack[count($stack) - 1]["block"] === "") {
                array_pop($stack);
                self::logContext($context, "WARNING", "[divengine.runner] Dropped call continuation without a calling block.");
            }

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
     */
    public static function updateContext(array &$context, array $data, string $stepId, int $stepIdx): void
    {
        $context = array_merge($context, $data);
        self::traceStep("context.update", "updateContext", $stepId, $stepIdx, $context);
    }

    /**
     * Decides if the current step should execute or be skipped after a pause.
     *
     * @param int   $stepIdx Current step index.
     * @param array $context Shared context by reference.
     *
     * @return bool
     */
    public static function checkPause(int $stepIdx, array &$context): bool
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
     */
    public static function wrapCondition(callable $func, string $stepId, int $stepIdx, array &$context): mixed
    {
        self::traceStep("condition", self::callableName($func), $stepId, $stepIdx, $context);
        return $func($context);
    }

    /**
     * Wraps an activity callable, stores its result in context and traces the step.
     *
     * @param callable $func
     * @param string   $stepId
     * @param int      $stepIdx
     * @param array    $context
     */
    public static function wrapActivity(callable $func, string $stepId, int $stepIdx, array &$context): void
    {
        self::traceStep("activity", self::callableName($func), $stepId, $stepIdx, $context);
        $tokens = self::ensureTokens($context);

        // A flow used as an activity has its own control state: it starts with
        // an empty call stack, and the parent's scope comes back afterwards.
        $snapshot = self::controlSnapshot($context);
        $context["_flow_stack"] = [];

        try {
            $result = $func($context);
        } catch (Throwable $e) {
            if (!self::isPauseException($e, $tokens)) {
                $context["_flow_stack"] = $snapshot["_flow_stack"] ?? [];
                throw $e;
            }

            if (!empty($context["_nested_flow_paused"])) {
                // A pause inside a nested flow is its early return: the parent's
                // whole control state comes back and the result is null.
                unset($context["_nested_flow_paused"]);
                self::restoreControl($context, $snapshot, false);
                $context[$stepId] = null;
                return;
            }

            // The activity asked to pause the flow: pause at this step, without
            // storing a result.
            self::restoreControl($context, $snapshot, true);
            $block = $context["_current_block_id"] ?? null;
            self::wrapPause(is_string($block) ? $block : null, $stepId, $stepIdx, $context);
        }

        // Completed: the parent's blocks, position and call stack come back.
        // `_flow_state` and `_autoresume` stay as the activity left them.
        self::restoreControl($context, $snapshot, true);
        $context[$stepId] = $result;
    }

    /**
     * Wraps a flow call: runs the target block and returns into the calling step.
     *
     * While the call runs, a return frame `{block, step_index}` of the calling
     * step is kept on `_flow_stack`, so a pause inside the called block can
     * resume back into the caller. A jump whose target is the called block
     * restarts it at the target step (nearest invocation); any other jump
     * unwinds past this call and drops its continuation.
     *
     * @param callable    $func
     * @param string      $stepId
     * @param int         $stepIdx
     * @param array       $context
     * @param string      $call
     * @param int         $targetIndex
     * @param string|null $fromBlock Calling block; defaults to `_current_block_id`.
     */
    public static function wrapCall(
        callable $func,
        string $stepId,
        int $stepIdx,
        array &$context,
        string $call,
        int $targetIndex,
        ?string $fromBlock = null
    ): void {
        self::traceStep("call", self::callableName($func), $stepId, $stepIdx, $context, $call);
        $targetBlock = self::extractBlockTarget($call, self::callableName($func));
        if ($targetBlock === "") {
            $targetBlock = self::callableName($func);
        }

        $callerBlock = $fromBlock;
        if ($callerBlock === null && isset($context["_current_block_id"]) && is_string($context["_current_block_id"])) {
            $callerBlock = $context["_current_block_id"];
        }

        $stack = self::flowStack($context);
        $maxDepth = is_int($context["_max_call_depth"] ?? null) ? $context["_max_call_depth"] : self::MAX_CALL_DEPTH;
        if (count($stack) >= $maxDepth) {
            throw new RuntimeException(
                "[divengine.runner] Call depth exceeds {$maxDepth} at {$stepId}[{$stepIdx}] -> {$call} (recursive call?)."
            );
        }
        $stack[] = ["block" => $callerBlock ?? "", "step_index" => $stepIdx];
        $context["_flow_stack"] = $stack;
        $depth = count($stack);
        $tokens = self::ensureTokens($context);

        $context["_flow_state"] = ["block" => $targetBlock, "step_index" => $targetIndex];

        while (true) {
            $context["_current_block_id"] = $targetBlock;
            try {
                $func($context);
                break;
            } catch (Throwable $e) {
                if (self::isJumpException($e, $tokens)) {
                    if (($context["_flow_state"]["block"] ?? null) === $targetBlock) {
                        continue;
                    }
                    $context["_flow_stack"] = array_slice(self::flowStack($context), 0, $depth - 1);
                }
                // Pause and errors keep the frame: it is the persisted continuation.
                throw $e;
            }
        }

        $context["_flow_stack"] = array_slice(self::flowStack($context), 0, $depth - 1);
        $context["_current_block_id"] = $callerBlock;
    }

    /**
     * Wraps a jump operation and throws a jump marker exception.
     *
     * @param callable $func
     * @param string   $stepId
     * @param int      $stepIdx
     * @param array    $context
     * @param string   $jump
     * @param int      $targetIndex
     *
     * @throws RuntimeException
     */
    public static function wrapJump(
        callable $func,
        string $stepId,
        int $stepIdx,
        array &$context,
        string $jump,
        int $targetIndex
    ): never {
        self::traceStep("jump", self::callableName($func), $stepId, $stepIdx, $context, $jump);
        $targetBlock = self::extractBlockTarget($jump, self::callableName($func));
        if ($targetBlock === "") {
            $targetBlock = self::callableName($func);
        }

        $context["_flow_state"] = ["block" => $targetBlock, "step_index" => $targetIndex];
        $tokens = self::ensureTokens($context);
        $token = $tokens["jump"];
        throw new RuntimeException("{$token} Jump to {$jump}");
    }

    /**
     * Persists pause position and throws a pause marker exception.
     *
     * @param string|null $block
     * @param string|null $stepId
     * @param int|null    $stepIdx
     * @param array       $context
     *
     * @throws RuntimeException
     */
    public static function wrapPause(
        ?string $block = null,
        ?string $stepId = null,
        ?int $stepIdx = null,
        array &$context = []
    ): never {
        if (func_num_args() < 4) {
            throw new RuntimeException(
                "[divengine.runner] wrapPause requires shared context as the fourth argument."
            );
        }

        $tokens = self::ensureTokens($context);
        $token = $tokens["pause"];

        $block = $block ?? ($context["_flow_state"]["block"] ?? "unknown");
        $stepId = $stepId ?? ($context["_current_step_id"] ?? "unknown");
        $stepIdx = $stepIdx ?? ($context["_current_step_index"] ?? -1);

        self::traceStep("pause", $block, $stepId, $stepIdx, $context);

        $context["_flow_state"] = ["block" => $block, "step_index" => $stepIdx];

        self::logContext(
            $context,
            "INFO",
            "[divengine.runner] Pausing execution at {$block}.{$stepId}[{$stepIdx}]"
        );

        throw new RuntimeException("{$token} Pause at {$block}.{$stepId}[{$stepIdx}]");
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
            $entry = [
                "indent" => $indent,
                "content" => rtrim(ltrim($withoutComments)),
                "line" => $lineNumber + 1,
            ];

            if (preg_match('/(?:^|:\s+|^-\s+)([|>])([-+]?)$/', $entry["content"], $block) === 1) {
                $blockLines = [];
                while ($lineNumber + 1 < $count) {
                    $next = $rawLines[$lineNumber + 1];
                    if (trim($next) !== "" && strspn($next, " ") <= $indent) {
                        break;
                    }
                    $blockLines[] = $next;
                    $lineNumber++;
                }
                $entry["block"] = self::foldYamlBlock($blockLines, $block[1], $block[2]);
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
    private static function foldYamlBlock(array $rawLines, string $style, string $chomping): string
    {
        $blockIndent = null;
        foreach ($rawLines as $rawLine) {
            if (trim($rawLine) !== "") {
                $blockIndent = strspn($rawLine, " ");
                break;
            }
        }

        $contentLines = [];
        foreach ($rawLines as $rawLine) {
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

        return match ($chomping) {
            "-" => $text,
            "+" => $text . "\n" . str_repeat("\n", $trailing),
            default => $text . "\n",
        };
    }

    private static function stripYamlComments(string $line): string
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

            if ($char === "#" && ($i === 0 || $line[$i - 1] === " " || $line[$i - 1] === "\t")) {
                return rtrim(substr($line, 0, $i));
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

        if (str_starts_with($entry["content"], "-")) {
            return self::parseYamlSequence($lines, $index, $indent);
        }

        return self::parseYamlMapping($lines, $index, $indent);
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

            if (str_starts_with($entry["content"], "-")) {
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

            if (!isset($lines[$index]) || $lines[$index]["indent"] <= $indent) {
                $result[$key] = null;
                continue;
            }

            $result[$key] = self::parseYamlNode($lines, $index, $lines[$index]["indent"]);
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
    private static function parseYamlSequence(array $lines, int &$index, int $indent): array
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

            if (!str_starts_with($entry["content"], "-")) {
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
                $item = [];
                [$key, $valuePart] = self::splitYamlKeyValue($itemLine, $entry["line"]);
                if (isset($entry["block"])) {
                    $item[$key] = $entry["block"];
                } elseif ($valuePart !== "") {
                    $item[$key] = self::parseYamlScalar($valuePart, $entry["line"]);
                } elseif (isset($lines[$index]) && $lines[$index]["indent"] > $indent) {
                    $item[$key] = self::parseYamlNode($lines, $index, $lines[$index]["indent"]);
                } else {
                    $item[$key] = null;
                }

                if (isset($lines[$index]) && $lines[$index]["indent"] > $indent) {
                    $extra = self::parseYamlMapping($lines, $index, $lines[$index]["indent"]);
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

        $lower = strtolower($trimmed);
        if ($lower === "true") {
            return true;
        }
        if ($lower === "false") {
            return false;
        }
        if ($lower === "null" || $trimmed === "~") {
            return null;
        }

        if (preg_match('/^-?[0-9]+$/', $trimmed) === 1) {
            return (int) $trimmed;
        }

        if (preg_match('/^-?[0-9]+\\.[0-9]+$/', $trimmed) === 1) {
            return (float) $trimmed;
        }

        return $trimmed;
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
                    $key = self::parseYamlFlowValue($text, $position, $lineNumber);
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
        if (!is_array($blocksRaw)) {
            throw new RuntimeException(
                "[divengine.runner] Invalid YAML flow format: 'blocks' must be defined."
            );
        }

        $normalized = [];

        if (array_is_list($blocksRaw)) {
            foreach ($blocksRaw as $blockData) {
                if (!is_array($blockData)) {
                    throw new RuntimeException("[divengine.runner] Invalid block entry in flow list.");
                }

                $blockId = trim((string) ($blockData["id"] ?? ""));
                $stepsRaw = $blockData["steps"] ?? [];
                $steps = self::normalizeFlowSteps($stepsRaw);
                $normalized[] = [
                    "id" => $blockId,
                    "steps" => $steps,
                ];
            }

            return $normalized;
        }

        foreach ($blocksRaw as $blockId => $blockData) {
            if (!is_array($blockData)) {
                throw new RuntimeException(
                    "[divengine.runner] Block '{$blockId}' must be a mapping."
                );
            }

            $stepsRaw = $blockData["steps"] ?? [];
            $steps = self::normalizeFlowSteps($stepsRaw);
            $normalized[] = [
                "id" => trim((string) $blockId),
                "steps" => $steps,
            ];
        }

        return $normalized;
    }

    /**
     * @param mixed $stepsRaw
     *
     * @return array<int, array<string,mixed>>
     */
    private static function normalizeFlowSteps(mixed $stepsRaw): array
    {
        if (!is_array($stepsRaw)) {
            throw new RuntimeException("[divengine.runner] Block 'steps' must be a list or mapping.");
        }

        $normalized = [];

        if (array_is_list($stepsRaw)) {
            foreach ($stepsRaw as $stepData) {
                if (!is_array($stepData)) {
                    throw new RuntimeException("[divengine.runner] Invalid step entry in step list.");
                }

                $stepId = trim((string) ($stepData["id"] ?? ""));
                $stepCopy = $stepData;
                $stepCopy["id"] = $stepId;
                $normalized[] = $stepCopy;
            }

            return $normalized;
        }

        foreach ($stepsRaw as $stepId => $stepData) {
            if (!is_array($stepData)) {
                throw new RuntimeException(
                    "[divengine.runner] Step '{$stepId}' must be a mapping."
                );
            }

            $stepCopy = $stepData;
            $stepCopy["id"] = trim((string) $stepId);
            $normalized[] = $stepCopy;
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
            if (in_array("_" . $blockId, self::RESERVED_KEYS, true) || str_starts_with($blockId, "runner_")) {
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
        if (is_array($blocks)) {
            $isList = array_is_list($blocks);
            foreach ($blocks as $blockKey => $block) {
                if (!is_array($block)) {
                    continue;
                }
                $blockId = $isList ? (string) ($block["id"] ?? "#{$blockKey}") : (string) $blockKey;
                foreach (array_keys($block) as $key) {
                    if (!in_array($key, self::BLOCK_KEYS, true) || ($key === "id" && !$isList)) {
                        $errors[] = "unknown key '{$key}' in block '{$blockId}'";
                    }
                }

                $steps = $block["steps"] ?? null;
                if (!is_array($steps)) {
                    continue;
                }
                $stepsAreList = array_is_list($steps);
                foreach ($steps as $stepKey => $step) {
                    if (!is_array($step)) {
                        continue;
                    }
                    $stepId = $stepsAreList ? (string) ($step["id"] ?? "#{$stepKey}") : (string) $stepKey;
                    $where = "step '{$blockId}.{$stepId}'";
                    foreach ($step as $key => $value) {
                        if (!in_array($key, self::STEP_KEYS, true) || ($key === "id" && !$stepsAreList)) {
                            $errors[] = "unknown key '{$key}' in {$where}";
                            continue;
                        }
                        if ($value === null || $key === "id" || $key === "description") {
                            continue;
                        }
                        if ($key === "pause" && !is_bool($value)) {
                            $errors[] = "'pause' in {$where} must be true or false";
                        } elseif ($key === "context" && (!is_array($value) || ($value !== [] && array_is_list($value)))) {
                            $errors[] = "'context' in {$where} must be a mapping";
                        } elseif (in_array($key, ["condition", "activity", "call", "jump"], true)
                            && (!is_string($value) || trim($value) === "")) {
                            $errors[] = "'{$key}' in {$where} must be a non-empty string";
                        }
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

    private static function extractBlockTarget(string $reference, string $fallback): string
    {
        $trimmedReference = trim($reference);
        if ($trimmedReference === "") {
            return $fallback;
        }

        if (!str_contains($trimmedReference, ".")) {
            return $trimmedReference;
        }

        [$block] = explode(".", $trimmedReference, 2);
        $block = trim($block);
        if ($block === "") {
            return $fallback;
        }

        return $block;
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
     * Whether a context key belongs to the scope of the running flow: its
     * position, call stack and block callables (`_<block_id>` closures).
     */
    private static function isScopeKey(string|int $key, mixed $value): bool
    {
        if (!is_string($key)) {
            return false;
        }

        if (in_array($key, self::SCOPE_KEYS, true)) {
            return true;
        }

        return $value instanceof \Closure
            && str_starts_with($key, "_")
            && !str_starts_with($key, "__")
            && !in_array($key, self::SERVICE_KEYS, true);
    }

    /**
     * Snapshot of the control keys a nested flow may change.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function controlSnapshot(array $context): array
    {
        $snapshot = [];
        foreach ($context as $key => $value) {
            if (self::isScopeKey($key, $value) || in_array($key, self::FLOW_CONTROL_KEYS, true)) {
                $snapshot[$key] = $value;
            }
        }

        return $snapshot;
    }

    /**
     * Restores the scope keys (and, unless `$scopeOnly`, also the flow control
     * keys) from a snapshot, removing the ones the activity added.
     *
     * @param array<string, mixed> $context
     * @param array<string, mixed> $snapshot
     */
    private static function restoreControl(array &$context, array $snapshot, bool $scopeOnly): void
    {
        $selected = function (string|int $key, mixed $value) use ($scopeOnly): bool {
            return self::isScopeKey($key, $value)
                || (!$scopeOnly && in_array($key, self::FLOW_CONTROL_KEYS, true));
        };

        foreach ($context as $key => $value) {
            if ($selected($key, $value)) {
                unset($context[$key]);
            }
        }

        foreach ($snapshot as $key => $value) {
            if ($selected($key, $value)) {
                $context[$key] = $value;
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
}
