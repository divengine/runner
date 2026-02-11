<?php
declare(strict_types=1);

namespace divengine\runner;

use DateTimeImmutable;
use DateTimeInterface;
use ReflectionFunction;
use ReflectionFunctionAbstract;
use ReflectionMethod;
use RuntimeException;
use Throwable;

final class runner
{
    public const DEFAULT_MODULE = "divengine_runner";
    private static $loggerSink = null;

    public static function setLogger(?callable $logger): void
    {
        self::$loggerSink = $logger;
    }

    public static function importer(
        string $name,
        string $moduleName = self::DEFAULT_MODULE,
        ?string $basePath = null,
        ?callable $logger = null
    ): ?callable {
        $logger = $logger ?? self::$loggerSink;
        $basePath = $basePath ?? self::defaultBasePath();

        if (is_callable($name)) {
            return $name;
        }

        if (function_exists($name)) {
            return $name;
        }

        $paths = self::candidateImportPaths($name, $moduleName, $basePath);

        foreach ($paths as $path) {
            if (!is_file($path)) {
                continue;
            }

            try {
                require_once $path;
            } catch (Throwable $e) {
                self::emitLog($logger, "ERROR", "Error requiring file: " . $path, [
                    "exception" => self::dumpException($e),
                ]);
                return null;
            }

            if (function_exists($name)) {
                return $name;
            }

            $shortName = self::shortName($name);
            if ($shortName !== $name && function_exists($shortName)) {
                return $shortName;
            }
        }

        if (is_callable($name)) {
            return $name;
        }

        self::emitLog($logger, "ERROR", "Error importing {$name} from module {$moduleName}");
        return null;
    }

    public static function run(string|callable $flow, array &$context, array $options = []): void
    {
        $loggerSink = $options["logger"] ?? self::$loggerSink;
        if ($loggerSink !== null && !is_callable($loggerSink)) {
            throw new RuntimeException("Logger must be a callable.");
        }
        $basePath = $options["base_path"] ?? self::defaultBasePath();
        $moduleName = $options["module"] ?? self::DEFAULT_MODULE;
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

        $context["_logger"] = $logger;
        $context["_importer"] = function (string $name, ?string $module = null) use (
            $basePath,
            $moduleName,
            $logger
        ): ?callable {
            return self::importer($name, $module ?? $moduleName, $basePath, $logger);
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
            $func = self::importer($flow, $moduleName, $basePath, $logger);
        }

        if (!$func || !is_callable($func)) {
            throw new RuntimeException(
                "[divengine.runner] Flow function {$moduleName}.{$flowName} must be callable."
            );
        }

        self::validateCallable($func, $requireContextParamName);

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

    public static function flowLoop(string $initialStrider, array &$context): mixed
    {
        $tokens = self::ensureTokens($context);
        $jumpToken = $tokens["jump"];
        $pauseToken = $tokens["pause"];

        self::logContext(
            $context,
            "INFO",
            "[divengine.runner] Starting flow execution with initial strider: {$initialStrider}"
        );

        $loop = true;
        while ($loop) {
            $state = $context["_flow_state"] ?? [];
            $savedStrider = $state["strider"] ?? null;

            if ($savedStrider) {
                $initialStrider = $savedStrider;
            }

            $key = "_" . $initialStrider;
            if (!isset($context[$key]) || !is_callable($context[$key])) {
                throw new RuntimeException("Missing strider callable for {$key}");
            }

            try {
                $result = ($context[$key])($context);
                $loop = false;
                return $result;
            } catch (Throwable $e) {
                $message = $e->getMessage();
                if ($jumpToken !== "" && str_contains($message, $jumpToken)) {
                    $loop = true;
                    continue;
                }
                if ($pauseToken !== "" && str_contains($message, $pauseToken)) {
                    throw $e;
                }
                throw $e;
            }
        }

        return null;
    }

    public static function updateContext(array &$context, array $data, string $stepId, int $stepIdx): void
    {
        $context = array_merge($context, $data);
        self::traceStep("context.update", "updateContext", $stepId, $stepIdx, $context);
    }

    public static function checkPause(int $stepIdx, array &$context): bool
    {
        if (!isset($context["_flow_state"])) {
            $context["_flow_state"] = [];
        }

        $flowState = $context["_flow_state"];
        if (!array_key_exists("step_index", $flowState)) {
            $flowState["step_index"] = -1;
        }

        if (!array_key_exists("strider", $flowState)) {
            $flowState["strider"] = null;
        }

        $savedIndex = $flowState["step_index"] ?? -1;

        if ($flowState["strider"] === null) {
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
            unset($flowState["step_index"], $flowState["strider"]);
        }

        $context["_flow_state"] = $flowState;
        return true;
    }

    public static function wrapCondition(callable $func, string $stepId, int $stepIdx, array &$context): mixed
    {
        self::traceStep("condition", self::callableName($func), $stepId, $stepIdx, $context);
        return $func($context);
    }

    public static function wrapActivity(callable $func, string $stepId, int $stepIdx, array &$context): void
    {
        self::traceStep("activity", self::callableName($func), $stepId, $stepIdx, $context);
        $context[$stepId] = $func($context);
    }

    public static function wrapCall(
        callable $func,
        string $stepId,
        int $stepIdx,
        array &$context,
        string $call,
        int $targetIndex
    ): void {
        self::traceStep("call", self::callableName($func), $stepId, $stepIdx, $context, $call);
        $context["_flow_state"] = ["strider" => self::callableName($func), "step_index" => $targetIndex];
        $func($context);
    }

    public static function wrapJump(
        callable $func,
        string $stepId,
        int $stepIdx,
        array &$context,
        string $jump,
        int $targetIndex
    ): void {
        self::traceStep("jump", self::callableName($func), $stepId, $stepIdx, $context, $jump);
        $context["_flow_state"] = ["strider" => self::callableName($func), "step_index" => $targetIndex];
        $tokens = self::ensureTokens($context);
        $token = $tokens["jump"];
        throw new RuntimeException("{$token} Jump to {$jump}");
    }

    public static function wrapPause(
        ?string $strider = null,
        ?string $stepId = null,
        ?int $stepIdx = null,
        array &$context = []
    ): void {
        $tokens = self::ensureTokens($context);
        $token = $tokens["pause"];

        $strider = $strider ?? ($context["_flow_state"]["strider"] ?? "unknown");
        $stepId = $stepId ?? ($context["_current_step_id"] ?? "unknown");
        $stepIdx = $stepIdx ?? ($context["_current_step_index"] ?? -1);

        self::traceStep("pause", $strider, $stepId, $stepIdx, $context);

        $context["_flow_state"] = ["strider" => $strider, "step_index" => $stepIdx];

        self::logContext(
            $context,
            "INFO",
            "[divengine.runner] Pausing execution at {$strider}.{$stepId}[{$stepIdx}]"
        );

        throw new RuntimeException("{$token} Pause at {$strider}.{$stepId}[{$stepIdx}]");
    }

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
    private static function defaultBasePath(): string
    {
        $envBase = getenv("DIVENGINE_RUNNER_BASE");
        if (is_string($envBase) && $envBase !== "") {
            return rtrim($envBase, "/\\");
        }

        $cwd = getcwd();
        if (is_string($cwd) && $cwd !== "") {
            return $cwd;
        }

        return dirname(__DIR__);
    }

    private static function candidateImportPaths(string $name, string $moduleName, string $basePath): array
    {
        $paths = [];

        $namePath = str_replace("\\", DIRECTORY_SEPARATOR, $name) . ".php";

        $dirs = [
            $basePath,
            $basePath . DIRECTORY_SEPARATOR . "functions",
            $basePath . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "functions",
            $basePath . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "functions",
            $basePath . DIRECTORY_SEPARATOR . "jobs",
            $basePath . DIRECTORY_SEPARATOR . "app" . DIRECTORY_SEPARATOR . "functions",
            $basePath . DIRECTORY_SEPARATOR . "src",
            $basePath . DIRECTORY_SEPARATOR . "lib",
        ];

        if ($moduleName !== "") {
            $dirs[] = $basePath . DIRECTORY_SEPARATOR . $moduleName;
            $dirs[] = $basePath . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . "functions";
            $dirs[] = $basePath . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . "lib" . DIRECTORY_SEPARATOR . "functions";
            $dirs[] = $basePath . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . "src" . DIRECTORY_SEPARATOR . "functions";
            $dirs[] = $basePath . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . "jobs";
            $dirs[] = $basePath . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . "src";
            $dirs[] = $basePath . DIRECTORY_SEPARATOR . $moduleName . DIRECTORY_SEPARATOR . "lib";
        }

        foreach ($dirs as $dir) {
            $paths[] = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $name . ".php";
            $paths[] = rtrim($dir, "/\\") . DIRECTORY_SEPARATOR . $namePath;
        }

        return array_values(array_unique($paths));
    }

    private static function validateCallable(callable $func, bool $requireContextParamName): void
    {
        $ref = self::reflectCallable($func);
        if ($ref->getNumberOfParameters() < 1) {
            throw new RuntimeException(
                "[divengine.runner] Flow function must accept 'context' as the first parameter."
            );
        }

        if ($requireContextParamName) {
            $param = $ref->getParameters()[0];
            if ($param->getName() !== "context") {
                throw new RuntimeException(
                    "[divengine.runner] Flow function must accept 'context' as the only parameter."
                );
            }
        }
    }

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

    private static function shortName(string $name): string
    {
        $pos = strrpos($name, "\\");
        if ($pos === false) {
            return $name;
        }
        return substr($name, $pos + 1);
    }

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

    private static function logContext(array $context, string $level, string $message, array $logContext = []): void
    {
        $logger = $context["_logger"] ?? null;
        if (is_callable($logger)) {
            $logger($level, $message, $logContext);
        }
    }

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

    private static function generateToken(string $prefix): string
    {
        try {
            return $prefix . "-" . bin2hex(random_bytes(16));
        } catch (Throwable $e) {
            return $prefix . "-" . uniqid("", true);
        }
    }

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
            "[divengine.runner] Strider flow {$type} -> {$funcName}.{$stepId} [{$stepIdx}]{$extra}"
        );
    }

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
