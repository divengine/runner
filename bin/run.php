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
 * @return array{flow: string, context: ?string, root: ?string, save: ?string, json: bool, help: bool}
 */
function parseArguments(array $args): array
{
    $options = ["flow" => null, "context" => null, "root" => null, "save" => null, "json" => false, "help" => false];
    $valueOptions = ["context" => "context", "root" => "root", "save-context" => "save"];
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

        $context = $options["context"] !== null ? readContext(absolutePath($options["context"])) : [];

        if ($options["root"] !== null) {
            $root = realpath(absolutePath($options["root"]));
            if ($root === false || !is_dir($root)) {
                throw new UsageError("--root is not a folder: {$options["root"]}");
            }
            $context["_root_folder"] = $root;
        }

        $saveTarget = $options["save"] !== null ? saveTarget($options["save"]) : null;
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

    try {
        runner::run($flow, $context);
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

    fwrite(STDOUT, $options["json"] && $json !== null ? $json : $state . "\n");

    return match ($state) {
        "done" => EXIT_DONE,
        "paused" => EXIT_PAUSED,
        default => EXIT_ERROR,
    };
}

exit(main(array_values(array_map("strval", $_SERVER["argv"] ?? []))));
