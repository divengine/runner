<?php

/**
 * Divengine Runner command-line bootstrap.
 *
 *     php vendor/divengine/runner/bin/run.php <flow> [options]
 *
 * Runs one flow with `runner::run()` and nothing else: no YAML parsing,
 * execution, pause handling or function importing happens here.
 *
 * Protocol:
 * - stdout carries only the result: the final state (`done`, `paused`, `error`)
 *   on one line, or the resulting context as JSON with `--json`.
 * - stderr carries diagnostics and anything the flow prints.
 * - Exit codes: 0 done, 1 error (the flow ended in the `error` state),
 *   2 invocation error (bad arguments, unreadable input, context not saved),
 *   3 paused.
 * - `--trace <file>` writes a semantic trace (`runner::runTraced()`), and
 *   `--simulate <doubles.json>` simulates a YAML flow with test doubles
 *   (`runner::simulate()`); neither changes stdout or the exit codes.
 *   `--trace-messages` keeps error and log messages in the trace.
 *
 * Relative paths (flow, --context, --root, --save-context) are relative to the
 * current working directory.
 *
 * This file is part of divengine/runner, GPL-3.0-or-later.
 */

declare(strict_types=1);

namespace divengine\runner\cli;

use divengine\runner\runner;
use ErrorException;
use InvalidArgumentException;
use JsonException;
use RuntimeException;
use Throwable;

const EXIT_DONE = 0;
const EXIT_ERROR = 1;
const EXIT_USAGE = 2;
const EXIT_PAUSED = 3;

/** Nesting limit of saved contexts; the same depth `--context` reads back. */
const JSON_DEPTH = 512;

/** Invocation errors: reported on stderr, exit code 2. */
final class UsageError extends RuntimeException
{
}

function usage(): string
{
    return <<<'TXT'
        Usage: php vendor/divengine/runner/bin/run.php <flow> [options]

        Runs a YAML (.yml, .yaml) or PHP (.php) flow with divengine/runner.

        Options:
          --context <file>       Read the initial context from a JSON object file.
          --root <dir>           Folder of activity and condition files (sets _root_folder).
          --save-context <file>  Write the resulting context as JSON (atomic replace).
          --json                 Print the resulting context as JSON instead of the state.
          --trace <file>         Write a semantic trace of the run as JSON (atomic replace).
          --trace-messages       Keep error and log messages in the trace (left out by
                                 default: they may hold sensitive data).
          --simulate <file>      Simulate a YAML flow with the test doubles in this JSON file;
                                 no function file is loaded.
          --session <file>       Simulation state: resumed when the file exists, then rewritten.
          -h, --help             Show this help.

        stdout: the final state (done, paused, error) or, with --json, the context.
        stderr: diagnostics and anything the flow prints.
        Exit codes: 0 done, 1 error, 2 invocation error, 3 paused.

        Resume a paused flow by passing the saved context back:
          php vendor/divengine/runner/bin/run.php flows/order.yml --save-context state.json
          php vendor/divengine/runner/bin/run.php flows/order.yml --context state.json --save-context state.json

        Flows, activities and conditions are trusted PHP code; Runner is not a sandbox.

        TXT;
}

/**
 * @param list<string> $args Arguments without the script name.
 *
 * @return array{flow: string, context: ?string, root: ?string, save: ?string, trace: ?string,
 *     simulate: ?string, session: ?string, json: bool, trace_messages: bool, help: bool}
 */
function parseArguments(array $args): array
{
    $options = [
        "flow" => null, "context" => null, "root" => null, "save" => null,
        "trace" => null, "simulate" => null, "session" => null, "json" => false, "trace_messages" => false,
        "help" => false,
    ];
    $valueOptions = [
        "context" => "context", "root" => "root", "save-context" => "save",
        "trace" => "trace", "simulate" => "simulate", "session" => "session",
    ];
    $onlyPositional = false;

    for ($i = 0, $count = count($args); $i < $count; $i++) {
        $arg = $args[$i];

        if (!$onlyPositional && $arg === "--") {
            $onlyPositional = true;
            continue;
        }

        if (!$onlyPositional && ($arg === "-h" || $arg === "--help")) {
            $options["help"] = true;
            continue;
        }

        if (!$onlyPositional && $arg === "--json") {
            $options["json"] = true;
            continue;
        }

        if (!$onlyPositional && $arg === "--trace-messages") {
            $options["trace_messages"] = true;
            continue;
        }

        if (!$onlyPositional && str_starts_with($arg, "-") && $arg !== "-") {
            $name = substr($arg, 2);
            $value = null;
            if (str_contains($name, "=")) {
                [$name, $value] = explode("=", $name, 2);
            }
            if (!str_starts_with($arg, "--") || !isset($valueOptions[$name])) {
                throw new UsageError("unknown option: {$arg}");
            }
            if ($value === null) {
                if ($i + 1 >= $count) {
                    throw new UsageError("option --{$name} needs a value");
                }
                $value = $args[++$i];
            }
            if ($value === "") {
                throw new UsageError("option --{$name} needs a non-empty value");
            }
            $key = $valueOptions[$name];
            if ($options[$key] !== null) {
                throw new UsageError("option --{$name} given more than once");
            }
            $options[$key] = $value;
            continue;
        }

        if ($options["flow"] !== null) {
            throw new UsageError("only one flow can be run, got also: {$arg}");
        }
        $options["flow"] = $arg;
    }

    if (!$options["help"] && ($options["flow"] === null || $options["flow"] === "")) {
        throw new UsageError("missing flow path");
    }
    $options["flow"] = (string) $options["flow"];

    return $options;
}

function absolutePath(string $path): string
{
    $isAbsolute = str_starts_with($path, "/") || str_starts_with($path, "\\")
        || preg_match('/^[A-Za-z]:[\\\\\/]/', $path) === 1;
    if ($isAbsolute) {
        return $path;
    }
    $cwd = getcwd();
    if ($cwd === false) {
        throw new UsageError("cannot determine the current working directory");
    }
    return $cwd . DIRECTORY_SEPARATOR . $path;
}

/**
 * Loads the Composer autoloader of the project that installed this package
 * (so flows can use the project's classes), else this package's own.
 */
function loadRunner(): void
{
    $candidates = [];
    if (isset($GLOBALS["_composer_autoload_path"]) && is_string($GLOBALS["_composer_autoload_path"])) {
        $candidates[] = $GLOBALS["_composer_autoload_path"];
    }

    // Installed as <vendor-dir>/divengine/runner/bin/run.php.
    $vendorDir = dirname(__DIR__, 3);
    if (is_dir($vendorDir . "/composer")) {
        $candidates[] = $vendorDir . "/autoload.php";
    }

    // Repository checkout with its own dependencies installed.
    $candidates[] = dirname(__DIR__) . "/vendor/autoload.php";

    foreach ($candidates as $autoload) {
        if (is_file($autoload)) {
            require_once $autoload;
            break;
        }
    }

    // Runner has no dependencies: its single source file is enough.
    if (!class_exists(runner::class)) {
        require_once dirname(__DIR__) . "/src/runner.php";
    }
}

/**
 * @return array<string, mixed>
 */
function readContext(string $path): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new UsageError("context file not found or not readable: {$path}");
    }
    $json = file_get_contents($path);
    if ($json === false) {
        throw new UsageError("cannot read context file: {$path}");
    }
    try {
        // json_decode() needs one level more than json_encode() for the same arrays.
        $context = json_decode($json, true, JSON_DEPTH + 1, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new UsageError("invalid JSON in context file {$path}: " . $e->getMessage());
    }
    if (!is_array($context) || ($context !== [] && array_is_list($context))) {
        throw new UsageError("context file must contain a JSON object: {$path}");
    }
    return $context;
}

/**
 * Returns why a value would not come back unchanged from JSON, or null.
 *
 * Only null, booleans, integers, floats, strings and arrays of them are
 * restorable. `json_encode()` turns objects and closures into JSON objects
 * without error, which would lose them silently, so they are rejected here, as
 * are resources. The depth limit also stops recursive (reference) arrays.
 */
function unrestorableValue(mixed $value, string $path = "context", int $depth = 1): ?string
{
    if (is_array($value)) {
        if ($depth > JSON_DEPTH) {
            return "{$path}: nested deeper than " . JSON_DEPTH . " levels or recursive";
        }
        foreach ($value as $key => $item) {
            $label = is_int($key) ? (string) $key
                : (string) json_encode($key, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
            $problem = unrestorableValue($item, "{$path}[{$label}]", $depth + 1);
            if ($problem !== null) {
                return $problem;
            }
        }
        return null;
    }
    if ($value === null || is_scalar($value)) {
        return null;
    }
    return is_object($value)
        ? "{$path}: object of class " . get_class($value)
        : "{$path}: " . get_debug_type($value);
}

/**
 * Encodes the context for --save-context and --json.
 *
 * @param array<string, mixed> $context
 *
 * @throws JsonException When a value cannot be restored or encoded.
 */
function encodeContext(array $context): string
{
    $problem = unrestorableValue($context);
    if ($problem !== null) {
        throw new JsonException("{$problem} cannot be restored from JSON");
    }

    // Floats keep a fraction (25.0) so they decode as floats on resume.
    return json_encode($context, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES
        | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION, JSON_DEPTH) . "\n";
}

/**
 * Validates the save target before the flow runs, so that a flow with side
 * effects does not run when its context could not be saved.
 */
function saveTarget(string $path): string
{
    $absolute = absolutePath($path);
    $dir = realpath(dirname($absolute));
    if ($dir === false || !is_dir($dir)) {
        throw new UsageError("folder for --save-context does not exist: " . dirname($absolute));
    }
    if (!is_writable($dir)) {
        throw new UsageError("folder for --save-context is not writable: {$dir}");
    }
    $target = $dir . DIRECTORY_SEPARATOR . basename($absolute);
    if (is_dir($target)) {
        throw new UsageError("--save-context points to a folder: {$target}");
    }
    return $target;
}

/**
 * Writes the file through a temporary file in the same folder and a rename, so
 * that readers never see a partially written file.
 */
function writeAtomically(string $target, string $contents): void
{
    set_error_handler(static function (int $severity, string $message): bool {
        if ((error_reporting() & $severity) === 0) {
            return false;
        }
        throw new ErrorException($message, 0, $severity);
    });

    $temp = null;
    try {
        $dir = dirname($target);
        $temp = tempnam($dir, "." . basename($target) . ".");
        if ($temp === false || realpath(dirname($temp)) !== realpath($dir)) {
            throw new RuntimeException("cannot create a temporary file in {$dir}");
        }

        $handle = fopen($temp, "wb");
        if ($handle === false) {
            throw new RuntimeException("cannot open {$temp}");
        }
        try {
            $written = fwrite($handle, $contents);
            if ($written !== strlen($contents) || !fflush($handle)) {
                throw new RuntimeException("cannot write {$temp}");
            }
            fsync($handle);
        } finally {
            fclose($handle);
        }

        if (is_file($target)) {
            chmod($temp, fileperms($target) & 0777);
        }
        if (!rename($temp, $target)) {
            throw new RuntimeException("cannot replace {$target}");
        }
        $temp = null;
    } finally {
        if ($temp !== null && is_file($temp)) {
            @unlink($temp);
        }
        restore_error_handler();
    }
}

const SESSION_PROTOCOL = "divengine.runner.simulation-session";
const SESSION_VERSION = 1;

/**
 * Reads a JSON object file for an option.
 *
 * @return array<string, mixed>
 */
function readJsonObject(string $path, string $option): array
{
    if (!is_file($path) || !is_readable($path)) {
        throw new UsageError("{$option} file not found or not readable: {$path}");
    }
    try {
        $data = json_decode((string) file_get_contents($path), true, JSON_DEPTH + 1, JSON_THROW_ON_ERROR);
    } catch (JsonException $e) {
        throw new UsageError("invalid JSON in {$option} file {$path}: " . $e->getMessage());
    }
    if (!is_array($data) || ($data !== [] && array_is_list($data))) {
        throw new UsageError("{$option} file must contain a JSON object: {$path}");
    }
    return $data;
}

/**
 * Reads the doubles file of --simulate: {"doubles": {...}, "budget": {...}, "trace": {...}}.
 *
 * @return array{doubles: array<string, mixed>, budget: array<string, mixed>, trace: array<string, mixed>}
 */
function readDoubles(string $path): array
{
    $spec = readJsonObject($path, "--simulate");
    $unknown = array_diff(array_keys($spec), ["doubles", "budget", "trace", "description"]);
    if ($unknown !== []) {
        throw new UsageError("unknown key in --simulate file: " . implode(", ", $unknown));
    }
    foreach (["doubles", "budget", "trace"] as $key) {
        $value = $spec[$key] ?? [];
        if (!is_array($value) || ($value !== [] && array_is_list($value))) {
            throw new UsageError("'{$key}' in --simulate file must be a JSON object");
        }
        $spec[$key] = $value;
    }
    return ["doubles" => $spec["doubles"], "budget" => $spec["budget"], "trace" => $spec["trace"]];
}

/**
 * Reads an existing simulation session file, or null when it does not exist.
 * A file that is not a session (a normal-run context, for example) is refused,
 * so a simulation never overwrites it.
 *
 * @return array{fingerprint: string, double_calls: array<string, int>, context: array<string, mixed>}|null
 */
function readSession(string $path): ?array
{
    if (!file_exists($path)) {
        return null;
    }
    $session = readJsonObject($path, "--session");
    if (($session["protocol"] ?? null) !== SESSION_PROTOCOL || ($session["version"] ?? null) !== SESSION_VERSION) {
        throw new UsageError("--session file is not a simulation session (protocol " . SESSION_PROTOCOL
            . " version " . SESSION_VERSION . "); it was left untouched: {$path}");
    }
    $context = $session["context"] ?? null;
    $calls = $session["double_calls"] ?? null;
    if (!is_string($session["fingerprint"] ?? null) || !is_array($context) || !is_array($calls)
        || ($context !== [] && array_is_list($context))) {
        throw new UsageError("--session file is incomplete or invalid: {$path}");
    }
    return ["fingerprint" => $session["fingerprint"], "double_calls" => $calls, "context" => $context];
}

/**
 * Refuses an output path that is also another input or output of the run.
 *
 * @param array<string, ?string> $others Option name => path given for it.
 */
function assertDistinctPath(string $option, string $target, array $others): void
{
    foreach ($others as $name => $path) {
        if ($path === null) {
            continue;
        }
        $other = realpath(absolutePath($path));
        $other = $other === false ? absolutePath($path) : $other;
        if ($other === $target) {
            throw new UsageError("{$option} must not be the same file as {$name}: {$path}");
        }
    }
}

/**
 * @param array<string, mixed> $trace
 */
function encodeTrace(array $trace): string
{
    // Trace values are already JSON-safe and bounded by the trace caps.
    return json_encode($trace, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
        | JSON_PRESERVE_ZERO_FRACTION | JSON_INVALID_UTF8_SUBSTITUTE, JSON_DEPTH) . "\n";
}

/**
 * @param list<string> $argv
 */
function main(array $argv): int
{
    ini_set("display_errors", "stderr");

    if (PHP_SAPI !== "cli") {
        fwrite(STDERR, "runner: run this file with the PHP command-line binary\n");
        return EXIT_USAGE;
    }

    try {
        $options = parseArguments(array_values(array_slice($argv, 1)));
        if ($options["help"]) {
            fwrite(STDOUT, usage());
            return EXIT_DONE;
        }

        $flow = absolutePath($options["flow"]);
        if (!is_file($flow)) {
            throw new UsageError("flow file not found: {$options["flow"]}");
        }
        $extension = strtolower(pathinfo($flow, PATHINFO_EXTENSION));
        if (!in_array($extension, ["yml", "yaml", "php"], true)) {
            throw new UsageError("flow must be a .yml, .yaml or .php file: {$options["flow"]}");
        }

        $simulate = $options["simulate"] !== null;
        if (!$simulate && $options["session"] !== null) {
            throw new UsageError("--session needs --simulate");
        }
        if ($options["trace_messages"] && $options["trace"] === null) {
            throw new UsageError("--trace-messages needs --trace");
        }
        if ($simulate) {
            if ($extension === "php") {
                throw new UsageError("--simulate runs YAML flows only: {$options["flow"]}");
            }
            foreach (["save" => "--save-context", "root" => "--root"] as $key => $name) {
                if ($options[$key] !== null) {
                    throw new UsageError("{$name} cannot be used with --simulate: a simulation loads no functions"
                        . " and writes no run context (use --session)");
                }
            }
        }
        $spec = $simulate ? readDoubles(absolutePath((string) $options["simulate"])) : null;

        $sessionTarget = $options["session"] !== null ? saveTarget($options["session"]) : null;
        $session = $sessionTarget !== null ? readSession($sessionTarget) : null;
        if ($session !== null && $options["context"] !== null) {
            throw new UsageError("--context cannot be used to resume an existing --session");
        }

        $context = $session !== null ? $session["context"]
            : ($options["context"] !== null ? readContext(absolutePath($options["context"])) : []);

        if ($options["root"] !== null) {
            $root = realpath(absolutePath($options["root"]));
            if ($root === false || !is_dir($root)) {
                throw new UsageError("--root is not a folder: {$options["root"]}");
            }
            $context["_root_folder"] = $root;
        }

        $saveTarget = $options["save"] !== null ? saveTarget($options["save"]) : null;
        $traceTarget = $options["trace"] !== null ? saveTarget($options["trace"]) : null;

        $inputs = ["the flow" => $options["flow"], "--context" => $options["context"], "--simulate" => $options["simulate"]];
        if ($traceTarget !== null) {
            assertDistinctPath("--trace", $traceTarget, $inputs + [
                "--save-context" => $options["save"],
                "--session" => $options["session"],
            ]);
        }
        if ($sessionTarget !== null) {
            assertDistinctPath("--session", $sessionTarget, $inputs);
        }
        if ($saveTarget !== null) {
            assertDistinctPath("--save-context", $saveTarget, ["the flow" => $options["flow"]]);
        }
    } catch (UsageError $e) {
        fwrite(STDERR, "runner: " . $e->getMessage() . "\n");
        fwrite(STDERR, "Try --help for usage.\n");
        return EXIT_USAGE;
    }

    loadRunner();

    // Anything the flow prints goes to stderr, so stdout stays parseable.
    $level = ob_get_level();
    ob_start(static function (string $buffer): string {
        if ($buffer !== "") {
            fwrite(STDERR, $buffer);
        }
        return "";
    }, 1);

    $trace = null;
    try {
        if ($spec !== null) {
            $trace = runner::simulate($flow, $context, $spec["doubles"], [
                "budget" => $spec["budget"],
                "trace" => $options["trace_messages"] ? ["capture_messages" => true] + $spec["trace"] : $spec["trace"],
                "double_calls" => $session["double_calls"] ?? [],
            ] + ($session !== null ? ["fingerprint" => $session["fingerprint"]] : []));
        } elseif ($traceTarget !== null) {
            $trace = runner::runTraced($flow, $context, $options["trace_messages"] ? ["trace" => ["capture_messages" => true]] : []);
        } else {
            runner::run($flow, $context);
        }
    } catch (InvalidArgumentException $e) {
        // Invalid doubles, budget or trace options, or a changed source: nothing ran.
        while (ob_get_level() > $level) {
            ob_end_flush();
        }
        fwrite(STDERR, "runner: " . $e->getMessage() . "\n");
        return EXIT_USAGE;
    } catch (Throwable $e) {
        // Setup errors are thrown after Runner records them in the context.
        if (($context["_runner_state"] ?? null) !== "error") {
            $context["_runner_state"] = "error";
            $context["_runner_result"] = "Error: " . $e->getMessage();
        }
    } finally {
        while (ob_get_level() > $level) {
            ob_end_flush();
        }
    }

    $state = $context["_runner_state"] ?? null;
    $state = in_array($state, ["done", "paused"], true) ? $state : "error";

    if ($state === "error") {
        $result = $context["_runner_result"] ?? "";
        fwrite(STDERR, "runner: " . (is_string($result) && $result !== "" ? $result : "flow failed") . "\n");
    }

    $json = null;
    if ($saveTarget !== null || $options["json"]) {
        try {
            $json = encodeContext($context);
        } catch (JsonException $e) {
            fwrite(STDERR, "runner: flow ended {$state}, but the context is not JSON-serializable: "
                . $e->getMessage() . "\n");
            return EXIT_USAGE;
        }
    }

    if ($saveTarget !== null && $json !== null) {
        try {
            writeAtomically($saveTarget, $json);
        } catch (Throwable $e) {
            fwrite(STDERR, "runner: flow ended {$state}, but the context was not saved: "
                . $e->getMessage() . "\n");
            return EXIT_USAGE;
        }
    }

    if ($sessionTarget !== null && $trace !== null) {
        $document = [
            "protocol" => SESSION_PROTOCOL,
            "version" => SESSION_VERSION,
            "fingerprint" => $trace["fingerprint"],
            "double_calls" => $trace["simulation"]["double_calls"] ?? [],
            "context" => $context,
        ];
        try {
            writeAtomically($sessionTarget, encodeContext($document));
        } catch (Throwable $e) {
            fwrite(STDERR, "runner: simulation ended {$state}, but the session was not saved: "
                . $e->getMessage() . "\n");
            return EXIT_USAGE;
        }
    }

    if ($traceTarget !== null && $trace !== null) {
        try {
            writeAtomically($traceTarget, encodeTrace($trace));
        } catch (Throwable $e) {
            fwrite(STDERR, "runner: flow ended {$state}, but the trace was not saved: " . $e->getMessage() . "\n");
            return EXIT_USAGE;
        }
    }

    fwrite(STDOUT, $options["json"] && $json !== null ? $json : $state . "\n");

    return match ($state) {
        "done" => EXIT_DONE,
        "paused" => EXIT_PAUSED,
        default => EXIT_ERROR,
    };
}

exit(main(array_values(array_map("strval", $_SERVER["argv"] ?? []))));
