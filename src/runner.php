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
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;
use Throwable;

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
     * Sets a global logger callback used when no per-run logger is provided.
     *
     * @param callable|null $logger
     */
    public static function setLogger(?callable $logger): void
    {
        self::$loggerSink = $logger;
    }

    /**
     * Imports a callable from a PHP file path.
     *
     * The target file must `return` a callable. The `.php` extension is optional.
     *
     * @param string        $path   Absolute or relative path to the PHP file.
     * @param callable|null $logger Optional logger callback.
     *
     * @return callable|null
     */
    public static function importer(string $path, ?callable $logger = null): ?callable
    {
        $logger = $logger ?? self::$loggerSink;

        $resolvedPath = trim($path);
        if ($resolvedPath === "") {
            self::emitLog($logger, "ERROR", "Import path is empty.");
            return null;
        }

        if (!str_ends_with(strtolower($resolvedPath), ".php")) {
            $resolvedPath .= ".php";
        }

        if (!is_file($resolvedPath)) {
            self::emitLog($logger, "ERROR", "Import file not found: {$resolvedPath}");
            return null;
        }

        try {
            $func = require $resolvedPath;
        } catch (Throwable $e) {
            self::emitLog($logger, "ERROR", "Error requiring file: {$resolvedPath}", [
                "exception" => self::dumpException($e),
            ]);
            return null;
        }

        if (!is_callable($func)) {
            self::emitLog($logger, "ERROR", "Import file must return a callable: {$resolvedPath}");
            return null;
        }

        return $func;
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
        $blocks = self::normalizeFlowBlocks($flowDef);
        self::validateFlowBlocks($blocks);
        $functionsRoot = self::resolveFlowFunctionsRoot($flowDef, $yamlPath, $options);

        $initialBlock = $blocks[0]["id"];
        $stepIndexMap = self::buildStepIndexMap($blocks);

        $functionRefs = self::collectFunctionReferences($blocks);
        sort($functionRefs);

        $resolvedFunctionPaths = [];
        foreach ($functionRefs as $functionRef) {
            $resolvedFunctionPaths[$functionRef] = self::resolveFunctionImportPath(
                $functionRef,
                $yamlPath,
                $functionsRoot,
                $options
            );
        }

        $lines = [];
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
        $lines[] = "    ) {";
        $lines[] = "        throw new \\RuntimeException(\"Missing flow helpers in context.\");";
        $lines[] = "    }";
        $lines[] = "";
        $lines[] = "    \$functions = [];";

        foreach ($resolvedFunctionPaths as $functionRef => $functionPath) {
            $refCode = var_export($functionRef, true);
            $pathCode = var_export($functionPath, true);
            $lines[] = "    \$functions[{$refCode}] = \$importer({$pathCode});";
            $lines[] = "    if (!is_callable(\$functions[{$refCode}])) {";
            $lines[] = "        throw new \\RuntimeException(\"Function import failed: \" . {$refCode});";
            $lines[] = "    }";
        }

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
            $lines[] = "        \$functions";
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
                if ($conditionRef !== "") {
                    $conditionRefCode = var_export($conditionRef, true);
                    $lines[] = "            if (\$wrapCondition(\$functions[{$conditionRefCode}], {$stepIdCode}, {$stepIdx}, \$context)) {";
                    $indent = "                ";
                }

                if (isset($step["context"]) && is_array($step["context"])) {
                    $stepContextCode = var_export($step["context"], true);
                    $lines[] = "{$indent}\$updateContext(\$context, {$stepContextCode}, {$stepIdCode}, {$stepIdx});";
                }

                $callRef = isset($step["call"]) && is_string($step["call"]) ? trim($step["call"]) : "";
                if ($callRef !== "") {
                    [$targetBlock, $targetIndex] = self::resolveBlockTarget($callRef, $blockId, $stepIndexMap, "call");
                    $targetCallableKeyCode = var_export("_" . $targetBlock, true);
                    $targetBlockCode = var_export($targetBlock, true);
                    $lines[] = "{$indent}\$targetCallable = \$context[{$targetCallableKeyCode}] ?? null;";
                    $lines[] = "{$indent}if (!is_callable(\$targetCallable)) {";
                    $lines[] = "{$indent}    throw new \\RuntimeException(\"Missing block callable for \" . {$targetCallableKeyCode});";
                    $lines[] = "{$indent}}";
                    $lines[] = "{$indent}\$wrapCall(\$targetCallable, {$stepIdCode}, {$stepIdx}, \$context, {$targetBlockCode}, {$targetIndex});";
                }

                $activityRef = isset($step["activity"]) && is_string($step["activity"])
                    ? trim($step["activity"])
                    : "";
                if ($activityRef !== "") {
                    $activityRefCode = var_export($activityRef, true);
                    $lines[] = "{$indent}\$wrapActivity(\$functions[{$activityRefCode}], {$stepIdCode}, {$stepIdx}, \$context);";
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
        $yamlOptions = self::withYamlRuntimeOptions($options, $context);
        $flow = self::flowFromYaml($yamlPath, $yamlOptions);
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
            $logger
        ): ?callable {
            return self::importer($path, $logger);
        };

        $context["_update_context"] = [self::class, "updateContext"];
        $context["_wrap_condition"] = [self::class, "wrapCondition"];
        $context["_wrap_activity"] = [self::class, "wrapActivity"];
        $context["_wrap_call"] = [self::class, "wrapCall"];
        $context["_wrap_jump"] = [self::class, "wrapJump"];
        $context["_wrap_pause"] = [self::class, "wrapPause"];
        $context["_check_pause"] = [self::class, "checkPause"];
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

        $func = $flow;
        if (is_string($flow)) {
            $extension = strtolower(pathinfo($flow, PATHINFO_EXTENSION));
            if ($extension === "yml" || $extension === "yaml") {
                $yamlOptions = self::withYamlRuntimeOptions($options, $context);
                $func = self::flowFromYaml($flow, $yamlOptions);
            } else {
                $func = self::importer($flow, $logger);
            }
        }

        if (!$func || !is_callable($func)) {
            throw new RuntimeException(
                "[divengine.runner] Flow '{$flowName}' must resolve to a callable."
            );
        }

        self::validateCallable($func, $requireContextParamName);

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
        $jumpToken = $tokens["jump"];
        $pauseToken = $tokens["pause"];

        self::logContext(
            $context,
            "INFO",
            "[divengine.runner] Starting flow execution with initial block: {$initialBlock}"
        );

        while (true) {
            $state = $context["_flow_state"] ?? [];
            $savedBlock = $state["block"] ?? null;

            if ($savedBlock) {
                $initialBlock = $savedBlock;
            }

            $key = "_" . $initialBlock;
            if (!isset($context[$key]) || !is_callable($context[$key])) {
                throw new RuntimeException("Missing block callable for {$key}");
            }

            try {
                return ($context[$key])($context);
            } catch (Throwable $e) {
                $message = $e->getMessage();
                if ($jumpToken !== "" && str_contains($message, $jumpToken)) {
                    continue;
                }
                if ($pauseToken !== "" && str_contains($message, $pauseToken)) {
                    throw $e;
                }
                throw $e;
            }
        }
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
        $context[$stepId] = $func($context);
    }

    /**
     * Wraps a flow call and updates flow state for continuation handling.
     *
     * @param callable $func
     * @param string   $stepId
     * @param int      $stepIdx
     * @param array    $context
     * @param string   $call
     * @param int      $targetIndex
     */
    public static function wrapCall(
        callable $func,
        string $stepId,
        int $stepIdx,
        array &$context,
        string $call,
        int $targetIndex
    ): void {
        self::traceStep("call", self::callableName($func), $stepId, $stepIdx, $context, $call);
        $targetBlock = self::extractBlockTarget($call, self::callableName($func));
        if ($targetBlock === "") {
            $targetBlock = self::callableName($func);
        }

        $context["_flow_state"] = ["block" => $targetBlock, "step_index" => $targetIndex];
        $func($context);
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
    ): void {
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
    ): void {
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
     * @param array<string, mixed> $options
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function withYamlRuntimeOptions(array $options, array $context): array
    {
        $yamlOptions = $options;
        $hasRootInOptions = isset($yamlOptions["root_folder"])
            && is_string($yamlOptions["root_folder"])
            && trim($yamlOptions["root_folder"]) !== "";
        $hasLegacyInOptions = isset($yamlOptions["functions_path"])
            && is_string($yamlOptions["functions_path"])
            && trim($yamlOptions["functions_path"]) !== "";

        if (
            !$hasRootInOptions
            && !$hasLegacyInOptions
            && isset($context["root_folder"])
            && is_string($context["root_folder"])
            && trim($context["root_folder"]) !== ""
        ) {
            $yamlOptions["root_folder"] = trim($context["root_folder"]);
        }

        return $yamlOptions;
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

        try {
            $parsed = Yaml::parse($contents);
        } catch (ParseException $e) {
            throw new RuntimeException(
                "[divengine.runner] Invalid YAML in '{$path}': " . $e->getMessage(),
                0,
                $e
            );
        }

        if (!is_array($parsed)) {
            throw new RuntimeException(
                "[divengine.runner] YAML flow root must be a mapping: {$path}"
            );
        }

        return $parsed;
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
     * @return array<int, string>
     */
    private static function collectFunctionReferences(array $blocks): array
    {
        $references = [];
        foreach ($blocks as $block) {
            foreach ($block["steps"] as $step) {
                $conditionRef = isset($step["condition"]) && is_string($step["condition"])
                    ? trim($step["condition"])
                    : "";
                $activityRef = isset($step["activity"]) && is_string($step["activity"])
                    ? trim($step["activity"])
                    : "";

                if ($conditionRef !== "") {
                    $references[$conditionRef] = true;
                }
                if ($activityRef !== "") {
                    $references[$activityRef] = true;
                }
            }
        }

        return array_keys($references);
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
     * @param array<string, mixed> $flowDef
     * @param array<string, mixed> $options
     */
    private static function resolveFlowFunctionsRoot(array $flowDef, string $yamlPath, array $options): string
    {
        $yamlDir = dirname($yamlPath);

        $fromOptions = isset($options["root_folder"]) && is_string($options["root_folder"])
            ? trim($options["root_folder"])
            : "";
        if ($fromOptions !== "") {
            return self::isAbsolutePath($fromOptions)
                ? $fromOptions
                : self::joinPath($yamlDir, $fromOptions);
        }

        $legacyFunctionsPath = isset($options["functions_path"]) && is_string($options["functions_path"])
            ? trim($options["functions_path"])
            : "";
        if ($legacyFunctionsPath !== "") {
            return self::isAbsolutePath($legacyFunctionsPath)
                ? $legacyFunctionsPath
                : self::joinPath($yamlDir, $legacyFunctionsPath);
        }

        $fromYaml = isset($flowDef["root_folder"]) && is_string($flowDef["root_folder"])
            ? trim($flowDef["root_folder"])
            : "";
        if ($fromYaml !== "") {
            return self::isAbsolutePath($fromYaml)
                ? $fromYaml
                : self::joinPath($yamlDir, $fromYaml);
        }

        return $yamlDir;
    }

    private static function resolveFunctionImportPath(
        string $reference,
        string $yamlPath,
        string $functionsRoot,
        array $options
    ): string
    {
        $map = [];
        if (isset($options["function_map"]) && is_array($options["function_map"])) {
            $map = $options["function_map"];
        }
        if (isset($map[$reference]) && is_string($map[$reference]) && trim($map[$reference]) !== "") {
            $mappedPath = trim($map[$reference]);
            if (self::isAbsolutePath($mappedPath)) {
                return $mappedPath;
            }
            return self::joinPath(dirname($yamlPath), $mappedPath);
        }

        $trimmedReference = trim($reference);
        if ($trimmedReference === "") {
            return $trimmedReference;
        }

        if (self::isAbsolutePath($trimmedReference)) {
            return $trimmedReference;
        }

        return self::joinPath($functionsRoot, $trimmedReference);
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

        if ($requireContextParamName) {
            if ($param->getName() !== "context") {
                throw new RuntimeException(
                    "[divengine.runner] Flow function first parameter must be named 'context'."
                );
            }
        }

        if (!$param->isPassedByReference()) {
            throw new RuntimeException(
                "[divengine.runner] Flow function must pass 'context' by reference as the first parameter."
            );
        }

        $type = $param->getType();
        if ($type instanceof ReflectionNamedType && $type->getName() !== "array") {
            throw new RuntimeException(
                "[divengine.runner] Flow function 'context' parameter type must be array when declared."
            );
        }
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
