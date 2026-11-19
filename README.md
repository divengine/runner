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
- The PHP counterpart of the Python `vvm_strider_jobs` execution runtime (see
  [SFM compatibility](docs/sfm-compatibility.md)).

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

Blocks do not fall through: the flow ends when the running block finishes.
`block.` targets step 0 of a block and `.step` the current block. Unknown keys
and wrongly typed values are rejected when the flow is compiled.

### Compile to PHP

```php
$code = runner::generateFlowCodeFromYaml("flows/order.yml");  // PHP source (deterministic)
file_put_contents("order_flow.php", "<?php\n" . $code);         // a regular flow file

$flow = runner::flowFromYaml("flows/order.yml");                // callable closure
runner::runYaml("flows/order.yml", $context);                   // compile and run
```

Option `function_map` (`["name" => "other_name_or_path"]`) renames function
references at compile time.

### Function resolution

`activity` and `condition` names (and `runner::importer()`) resolve in this order:

1. a PHP file under `_root_folder` (context, read at each import), else under
   `DIV_RUNNER_ROOT_FOLDER` (default `./`); the `.php` extension is optional;
2. the reference as a path (absolute or relative to the working directory);
3. a callable name such as `strlen` or `vendor\package\fn` (e.g. Composer-autoloaded).

A file must `return` a callable. A missing function fails the run with
`Function import failed: <name>` and an error log entry.

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
- Resume by calling `runner::run()` again with the stored context: execution
  continues **after** the paused step. Keep `_flow_state` and `_flow_stack` when
  you store it (both are plain arrays and JSON-serializable).
- A pause inside a called block resumes back into its caller (the call return
  frames are kept in `_flow_stack`).
- `_autoresume: true` makes the runner resume immediately once.
- A pause inside a flow used as an activity ends that nested flow early (its
  result is `null`); the parent flow continues.

Limitations: closures and objects in the context are not serializable, so they
must be re-provided when you resume from storage; a resumed run does not
re-apply your initial context for you; jump loops are not limited (recursive
calls are, by `_max_call_depth`, default 256).

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
- [docs/sfm-compatibility.md](docs/sfm-compatibility.md): SFM / `vvm_strider_jobs` compatibility matrix
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
