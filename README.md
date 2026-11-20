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

**What it is**

- An execution engine for PHP callables and YAML flow definitions.
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
- A pause inside a flow used as an activity ends that nested flow early (its
  result is `null`); the parent flow continues.

When `runner::run()` returns, the runner has removed its helpers and block
callables from the context. What remains is your data, the `_runner_*` metadata
and the resume state (`_flow_state`, `_flow_stack`), all plain arrays and
scalars, so the context can be stored with `json_encode()` and resumed after
`json_decode($json, true)`:

```php
file_put_contents("job.json", json_encode($context, JSON_THROW_ON_ERROR));
// later, in any process:
$context = json_decode(file_get_contents("job.json"), true, 512, JSON_THROW_ON_ERROR);
runner::run("flows/order.yml", $context);
```

Limitations: closures and objects that *your* code puts in the context are not
serializable, so re-provide them when you resume from storage; a resumed run
does not re-apply your initial context for you; a failed run drops pending call
frames, so running it again starts from the first block; jump loops are not
limited (recursive calls and nested flows are, by `_max_call_depth`, default 256).

## Public API

| Method | Purpose |
|---|---|
| `runner::run($flow, &$context, $options)` | Run a callable, a PHP flow file or a YAML flow. Options: `logger`, `shared_context`, `function_map`, `require_context_param_name`. |
| `runner::runYaml($path, &$context, $options)` | Compile and run a YAML flow. |
| `runner::flowFromYaml($path, $options)` | Compile a YAML flow to a closure. |
| `runner::generateFlowCodeFromYaml($path, $options)` | Compile a YAML flow to PHP source. |
| `runner::importer($name, $module, $context, $logger)` | Resolve a function as described above. |
| `runner::setLogger($logger)` | Global log sink. |
| `runner::safeSerialize($data)`, `runner::dumpException($e)` | Diagnostic helpers. |

The other public static methods (`flowLoop`, `checkPause`, `updateContext`,
`resumeAfterCall`, `wrapCondition`, `wrapActivity`, `wrapCall`, `wrapJump`,
`wrapPause`) are called by generated flow code and are marked `@internal`.

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
