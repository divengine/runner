# Divengine Runner

[![Latest Stable Version](https://poser.pugx.org/divengine/runner/v)](https://packagist.org/packages/divengine/runner)
[![Total Downloads](https://poser.pugx.org/divengine/runner/downloads)](https://packagist.org/packages/divengine/runner)
[![License](https://poser.pugx.org/divengine/runner/license)](https://packagist.org/packages/divengine/runner)
[![PHP Version Require](https://poser.pugx.org/divengine/runner/require/php)](https://packagist.org/packages/divengine/runner)

**Divengine Runner** is a lightweight PHP engine that executes flows of the
[Sequential Flow Model (SFM)](https://github.com/rafageist/book-sequential-flow-model):
ordered blocks of steps that share one mutable context array, with explicit
`call`, `jump` and `pause` transitions.

It follows the Divengine philosophy: one source file (`src/runner.php`), one
class (`divengine\runner\runner`), no runtime dependencies and a small static API.
A separate entry file, `bin/run.php`, runs flows from the terminal through that
API.

**What it is**

- An execution engine for PHP callables and YAML flow definitions, used from
  PHP code or from the terminal (`php vendor/divengine/runner/bin/run.php`).
- A YAML-to-PHP compiler: a YAML flow becomes a real PHP closure you can run or save.
- A PHP implementation of the SFM execution semantics of the Python reference
  runtime (see [SFM compatibility](docs/sfm-compatibility.md)).

**What it is not**

- Not a job queue, scheduler or persistence layer: storing a paused context and
  running it again is up to you.
- Not a visual modeler, validator or simulator.
- Not a sandbox: flows, activities and conditions are trusted PHP code
  (see [Security](docs/security.md)).

## Requirements and installation

PHP 8.1 or newer (tested on 8.1–8.5). No PHP extensions beyond the default ones.

```bash
composer require divengine/runner
```

## Callable flows

A flow is any callable whose first parameter is `array &$context`:

```php
use divengine\runner\runner;

$context = ["name" => "World"];

runner::run(function (array &$context): void {
    $context["greeting"] = "Hello " . $context["name"];
}, $context);

echo $context["greeting"];       // Hello World
echo $context["_runner_state"];  // done
```

`runner::run()` also accepts a path to a PHP file that returns such a callable.

## YAML flows

```yaml
# flows/order.yml
id: order
blocks:
  main:
    steps:
      total:
        activity: compute_total      # functions/compute_total.php
      review:
        call: review.check           # run review from step check, then come back
      receipt:
        activity: format_receipt
  review:
    steps:
      check:
        condition: needs_review      # false skips the rest of the step
        context:
          reviewed: true
        pause: true
```

Each activity and condition is a PHP file that returns a callable:

```php
<?php // functions/compute_total.php
return function (array &$context): float {
    $total = 0.0;
    foreach ($context["items"] as $item) {
        $total += $item["price"] * $item["qty"];
    }
    return $total;   // stored as $context["total"] (the step id)
};
```

```php
<?php // functions/needs_review.php
return fn (array &$context): bool => $context["total"] >= 20 && empty($context["reviewed"]);
```

```php
<?php // functions/format_receipt.php
return fn (array &$context): string =>
    sprintf("Total: %.2f%s", $context["total"], empty($context["reviewed"]) ? "" : " (reviewed)");
```

Run it, pause, and resume with the same context:

```php
use divengine\runner\runner;

$context = [
    "items" => [["price" => 10, "qty" => 2], ["price" => 5, "qty" => 1]],
    "_root_folder" => __DIR__ . "/functions",
];

runner::run(__DIR__ . "/flows/order.yml", $context);
echo $context["_runner_state"], PHP_EOL;   // paused

runner::run(__DIR__ . "/flows/order.yml", $context);
echo $context["_runner_state"], PHP_EOL;   // done
echo $context["receipt"], PHP_EOL;         // Total: 25.00 (reviewed)
```

Expected output:

```text
paused
done
Total: 25.00 (reviewed)
```

This example lives in [`examples/order`](examples/order) and runs in CI.

### Step semantics

A step may combine these keys; they run in this order:

1. `condition`: callable; a false result skips the rest of the step.
2. `context`: mapping merged into the context.
3. `call`: `block.step`; runs the target block from that step, then returns into this step.
4. `activity`: callable; its return value is stored in `$context[<step id>]`.
5. `jump`: `block.step`; transfers control without returning.
6. `pause`: `true`; stops the run so it can be resumed after this step.

`module` (optional) names the module that the step's `condition` and `activity`
belong to (see [Function resolution](#function-resolution)).

`blocks` and `steps` are mappings keyed by id, in execution order; the first
block is the entry. Blocks do not fall through: the flow ends when the running
block finishes. `block.` targets step 0 of a block and `.step` the current block. Block and step
ids must not start with `_` (reserved keys) or contain `.`. Unknown keys and
wrongly typed values are rejected when the flow is compiled. Conditions use PHP
truthiness, so return booleans.

### Compile to PHP

```php
$code = runner::generateFlowCodeFromYaml("flows/order.yml");  // PHP source (deterministic)
file_put_contents("order_flow.php", "<?php\n" . $code);         // a regular flow file

$flow = runner::flowFromYaml("flows/order.yml");                // callable closure
runner::runYaml("flows/order.yml", $context);                   // compile and run
```

Option `function_map` renames activity and condition references at compile
time. Keys and values are qualified names, `module:name` or a plain `name`:
`["billing:total" => "billing_v2:total", "total" => "billing:total"]`.

### Function resolution

The root folder is `_root_folder` from the context (read at each import), else
`DIV_RUNNER_ROOT_FOLDER` (default `./`). The `.php` extension is optional and a
file must `return` a callable.

**Without `module`**, a name resolves to the first match of:

1. `<root>/<name>.php`;
2. `<name>.php` as a path (absolute or relative to the working directory);
3. a callable name such as `strlen` or `vendor\package\fn` (e.g. Composer-autoloaded).

**With `module`**, a name resolves only inside the module folder:
`_modules[<module>]` from the context when set, else `<root>/<module>`. Nothing
outside that folder is tried, so the same function name in two modules never
collides:

```yaml
steps:
  invoice_total:
    module: billing          # <root>/billing/total.php
    activity: total
  shipping_total:
    module: shipping         # <root>/shipping/total.php
    activity: total
  rounded:
    activity: round_total    # <root>/round_total.php, a path or a callable
```

```php
$context["_modules"] = ["shipping" => "/srv/app/vendor-flows/shipping"];  // optional override
```

Module names use letters, digits, `_` and `-`; inside a module, a function name
is relative (`total`, `rates/total`) without `.` or `..` segments. Both are
checked at compile time. Activities import other functions with
`$context["_importer"]("total", "shipping")` (module optional).

A missing function fails the run with `Function import failed: <module>:<name>`
(or `<name>`) and an error log entry.

## Command line

The package ships a small PHP entry file that runs one flow with
`runner::run()`. PHP itself is the executable; there is nothing else to
install or build:

```bash
php vendor/divengine/runner/bin/run.php flows/order.yml --root functions
php vendor/divengine/runner/bin/run.php flows/order.yml --context input.json --root functions
php vendor/divengine/runner/bin/run.php flows/order.yml --context state.json --save-context state.json
```

| Option | Meaning |
|---|---|
| `<flow>` | A `.yml`, `.yaml` or `.php` flow file. |
| `--context <file>` | Initial context: a JSON object. |
| `--root <dir>` | Folder of activity and condition files; sets `_root_folder`. |
| `--save-context <file>` | Write the resulting context as JSON. The file is replaced atomically (temporary file in the same folder, then rename). |
| `--json` | Print the resulting context as JSON instead of the state. |
| `--trace <file>` | Also write a semantic trace of the run (see [Tracing and simulation](#tracing-and-simulation)). |
| `--trace-messages` | Keep error and log messages in the trace; they are left out by default. |
| `--simulate <file>` | Simulate a YAML flow with the test doubles of this JSON file instead of running its functions. |
| `--session <file>` | Simulation state across processes: resumed when the file exists, then rewritten. |
| `-h`, `--help` | Usage. |

Relative paths are relative to the current working directory, so run it from
your project folder. This also applies to relative `_root_folder` and `_modules`
values inside the context file. Without `--root` or `_root_folder`, functions
resolve from the working directory, as with the PHP API.

**Output and exit codes.** stdout carries only the result: one line with the
final state, or the context as JSON with `--json`. Diagnostics go to stderr, and
so does anything the flow prints, including PHP warnings, so the JSON on stdout
stays intact.

| Exit code | stdout | Meaning |
|---|---|---|
| `0` | `done` | The flow finished. |
| `3` | `paused` | The flow paused; resume it with the saved context. |
| `1` | `error` | The flow ended in the `error` state: a failing step, or a setup error such as invalid YAML. The message is on stderr. |
| `2` | (empty) | Invocation error. Either the arguments are bad or an input is missing or unreadable, and the flow did not run. Or the context could not be saved or printed as JSON (see below); then stderr says how the flow ended and which value failed, and an existing `--save-context` file is left unchanged. |

**Pause and resume across processes.** Save the context of the first run and
pass the same file back. Each run is a new PHP process, and steps that already
ran are not repeated:

```console
$ php vendor/divengine/runner/bin/run.php flows/order.yml --context input.json --root functions --save-context state.json
paused
$ echo $?
3
$ php vendor/divengine/runner/bin/run.php flows/order.yml --context state.json --save-context state.json
done
$ php -r 'echo json_decode(file_get_contents("state.json"), true)["receipt"], PHP_EOL;'
Total: 25.00 (reviewed)
```

Before `--save-context` or `--json` writes anything, the context is checked to
contain only values that come back unchanged from JSON: `null`, booleans,
integers, floats, strings and arrays of them, at most 512 levels deep. Objects
(closures included) and resources are rejected, even nested inside arrays,
because `json_encode()` would turn them into plain JSON objects or fail. The same
happens with values that `json_encode()` cannot encode, such as `NAN` or invalid
UTF-8. One remaining limitation: an empty JSON object `{}` comes back as an
empty array `[]`.

`--root` is stored in the context as an absolute path, so the resume run does not
need it again. On an error, Runner clears the resume state as it does with the
API (see [Pause and resume](#pause-and-resume)), and the saved context then
starts at the first block on the next run.

In a shell script, test for the codes explicitly:

```bash
php vendor/divengine/runner/bin/run.php flows/order.yml --context state.json --save-context state.json
case $? in
  0) echo "done" ;;
  3) echo "paused, run again later" ;;
  *) echo "failed" >&2; exit 1 ;;
esac
```

The entry file loads your project's Composer autoloader, so flows can use your
classes and Composer-autoloaded functions. It does nothing else: no framework
bootstrap, scheduler, queue or worker. Flows, activities and conditions are
trusted PHP code; the command line does not sandbox them (see
[Security](docs/security.md)).

**Optional project shortcut.** PHP does not install a `run` command. If you want
`php run flows/order.yml`, add this file named `run` to your project root:

```php
<?php
require __DIR__ . "/vendor/divengine/runner/bin/run.php";
```

```bash
php run flows/order.yml --root functions
```

## Context contract

All callables receive the same `array &$context`. Keys starting with `_` or `__`
are reserved; the runner writes `_runner_state` (`processing`, `done`, `paused`,
`error`), `_runner_result`, `_runner_error`, `_runner_logs`, `_runner_started_at`
and `_runner_ended_at`. The full list is in [docs/context-keys.md](docs/context-keys.md).

Errors raised by a flow are caught and recorded (`_runner_state = error`).
Setup errors (unreadable or invalid YAML, unresolved flow, wrong signature) are
thrown to the caller and also recorded in the context.

## Pause and resume

- `pause: true`, or an activity that throws an exception whose message contains
  `$context["_exception_pause"]`, pauses the run at that step.
- Resume by calling `runner::run()` again with the stored context, in the same
  or another process: execution continues **after** the paused step.
- Pauses work at any depth of `call`: the return frames of every pending call
  are kept in `_flow_stack`, so after a pause in C (called from B, called from A)
  the resumed run finishes C, returns into B's calling step, then into A's.
  Steps that already ran are not repeated.
- `_autoresume: true` makes the runner resume immediately once.

**Called blocks and nested flows pause differently.** A block reached through
`call` is part of the same flow: a pause inside it pauses the whole run, and the
resumed run continues through every pending caller. A *flow* used as an
activity is a separate invocation with its own control scope: a pause inside it
ends that child invocation early, its activity result is `null`, and the parent
flow continues without pausing. The child is not resumed later.

**After an error**, `_runner_state` is `error`, `_runner_error` and
`_runner_result` describe it, and the resume state is cleared (`_flow_state`
and `_flow_stack` become `[]`). Your business data stays as the flow left it,
and the next run starts at the first block. This holds for errors inside steps,
inside called blocks and before a saved position was reached (for example a
resumed flow whose paused block no longer exists), and for setup errors that
`run()` throws.

When `runner::run()` returns, the runner has removed its own runtime helpers
and block callables from the context. What remains is your data, the
`_runner_*` metadata and the resume state (`_flow_state`, `_flow_stack`). The
runner's part is plain arrays and scalars; whether the whole context can be
stored with `json_encode()` depends on the values your code put in it, which
Runner does not check or convert. With data-only values it round-trips through
`json_decode($json, true)`:

```php
file_put_contents("job.json", json_encode($context, JSON_THROW_ON_ERROR));
// later, in any process:
$context = json_decode(file_get_contents("job.json"), true, 512, JSON_THROW_ON_ERROR);
runner::run("flows/order.yml", $context);
```

Limitations: JSON serialization of user-supplied values is not guaranteed
(closures, objects, resources, non-UTF-8 strings and floats such as `NAN` are
yours to remove or re-provide when you resume from storage); a resumed run does
not re-apply your initial context for you; jump loops are not limited (recursive calls and nested flows are, by `_max_call_depth`, default 256).

## Public API

| Method | Purpose |
|---|---|
| `runner::run($flow, &$context, $options)` | Run a callable, a PHP flow file or a YAML flow. Options: `logger`, `shared_context`, `function_map`, `require_context_param_name`. |
| `runner::runYaml($path, &$context, $options)` | Compile and run a YAML flow. |
| `runner::flowFromYaml($path, $options)` | Compile a YAML flow to a closure. |
| `runner::generateFlowCodeFromYaml($path, $options)` | Compile a YAML flow to PHP source. |
| `runner::importer($name, $module, $context, $logger)` | Resolve a function as described above. |
| `runner::runTraced($flow, &$context, $options)` | Run like `run()` and return a semantic trace of the run (option `trace`: caps and redaction). |
| `runner::simulate($yamlPath, &$context, $doubles, $options)` | Simulate a YAML flow with test doubles; no function is loaded. Returns the trace. |
| `runner::setLogger($logger)` | Global log sink. |
| `runner::safeSerialize($data)`, `runner::dumpException($e)` | Diagnostic helpers. |

The other public static methods (`flowLoop`, `checkPause`, `updateContext`,
`resumeAfterCall`, `wrapCondition`, `wrapActivity`, `wrapCall`, `wrapJump`,
`wrapPause`) are called by generated flow code and are marked `@internal`.

## Tracing and simulation

`runner::runTraced()` runs a flow exactly like `run()` and also returns a
versioned JSON trace: block entries, started and skipped steps, condition
outcomes, activity results and context changes, `call`, `jump` and return
transfers, pauses with their checkpoint, and nested flow scopes. Values in the
trace are bounded, JSON-safe and redacted by key (`password`, `token`, …);
error and log messages are left out unless requested, and the trace never
exceeds `max_bytes`.

`runner::simulate()` runs a YAML flow through the same runtime, but every
condition and activity answers from a declared test double: fixed values,
sequences, errors or pauses. No function file is loaded and no callable name is
called; a reference without a double fails clearly. Budgets for steps,
transfers and time stop endless loops.

```bash
php vendor/divengine/runner/bin/run.php flows/invoice.yml --simulate doubles.json \
    --context context.json --session session.json --trace trace.json
```

See [docs/tracing-and-simulation.md](docs/tracing-and-simulation.md) for the
trace protocol, the doubles format, sessions, exit codes and the security
model, and [`examples/simulation`](examples/simulation) for a complete example.

## Logging

```php
runner::setLogger(function (string $level, string $message, array $context = []): void {
    error_log("[$level] $message");
});
// or per run: runner::run($flow, $context, ["logger" => $callable]);
```

Logs of each run are also stored in `$context["_runner_logs"]`.

## Documentation

- [docs/README.md](docs/README.md): overview and YAML API
- [docs/sfm-compatibility.md](docs/sfm-compatibility.md): SFM compatibility matrix
- [docs/tracing-and-simulation.md](docs/tracing-and-simulation.md): trace protocol and simulation
- [docs/security.md](docs/security.md): trust boundary
- [docs/context-keys.md](docs/context-keys.md): reserved context keys
- [docs/book/](docs/book): book chapters (`composer docs:pdf` builds a PDF)

## Development

```bash
composer install
composer test       # PHPUnit
composer phpstan    # static analysis
composer smoke      # install the dist archive in a clean project and run the example
composer bench      # phpbench baseline
```

Releases are created by pushing a `vX.Y.Z` tag on `main`; see [releases/README.md](releases/README.md).

## License

GPL-3.0-or-later. See [LICENSE](LICENSE).

Powered by [Divengine Software Solutions](https://divengine.com)
