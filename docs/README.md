# Divengine Runner Documentation

## Book

Book chapters and order file:

- chapters: `docs/book/`
- order file: `docs/book-order.txt`

Generate book markdown:

```bash
python scripts/build_pdf.py --markdown-only --no-mermaid
```

Generate book PDF (requires Mermaid CLI + Pandoc + XeLaTeX):

```bash
python scripts/build_pdf.py --output runner-documentation.pdf
```

## Further reading

- `docs/sfm-compatibility.md`: compatibility matrix with the SFM runtime
- `docs/security.md`: trust boundary (`eval()`, imports)
- `docs/context-keys.md`: reserved context keys

## Overview

Divengine Runner is a lightweight execution engine for PHP jobs and flows. A job is just a PHP function that receives a shared associative context array. The runner injects helpers into the context and tracks execution state, logs, and timing.

## Install

```bash
composer require divengine/runner
```

## Quick Start

```php
use divengine\runner\runner;

$context = [
    "name" => "World",
];

$flow = function (array &$context): void {
    $context["greeting"] = "Hello " . $context["name"];
};

runner::run($flow, $context);

echo $context["greeting"]; // Hello World
```

## Command Line

```bash
php vendor/divengine/runner/bin/run.php flows/order.yml --context input.json --root functions --save-context state.json
```

Exit codes: `0` done, `3` paused, `1` error, `2` invocation error. See the
README and `docs/book/09-command-line.md`.

## Context Keys

The canonical reserved-key reference is maintained in:

- `docs/context-keys.md`

Common runtime metadata includes:

- `_runner_state`
- `_runner_result`
- `_runner_error`
- `_runner_logs`
- `_runner_started_at`
- `_runner_ended_at`

## Flow Helpers

While a flow runs, the runner injects helpers into the context for generated flow code:

- `_wrap_condition`, `_wrap_activity`, `_wrap_call`, `_wrap_jump`, `_wrap_pause`
- `_check_pause`, `_resume_after_call`, `_update_context`
- `__flow_loop` for block-style execution
- `_importer` and `_logger`, which activities may use

They follow the helper contract of the Python SFM reference runtime (see
`docs/sfm-compatibility.md`) and are removed from the context, together with the
block callables, when `runner::run()` returns. The stored context is then plain
data that can be serialized with `json_encode()` and resumed in another process.

## YAML Flows

The runner can generate and execute flow callables directly from YAML definitions:

```php
use divengine\runner\runner;

$context = [
    "left" => 4,
    "right" => 6,
];

runner::runYaml(__DIR__ . "/flows/sample.yml", $context);
```

You can also call `runner::run()` with a `.yml`/`.yaml` path and it will compile the flow on the fly:

```php
runner::run(__DIR__ . "/flows/sample.yml", $context);
```

Available YAML APIs:

- `runner::generateFlowCodeFromYaml(string $yamlPath, array $options = []): string`
- `runner::flowFromYaml(string $yamlPath, array $options = []): callable`
- `runner::runYaml(string $yamlPath, array &$context, array $options = []): void`

Flow format notes:

- Root uses `blocks` as the container of flow blocks.
- `blocks` and `steps` are mappings keyed by id; mapping order is execution order.
- `activity` and `condition` values can omit `.php` extension.
- Allowed keys: flow `id`, `description`, `blocks`; block `description`, `steps`; step `description`, `module`, `condition`, `context`, `call`, `activity`, `jump`, `pause`. Anything else is a compile error.
- `call` and `jump` references use `block.step` syntax.
- `activity` and `condition` are imported as PHP callables through the standard importer, from the step's `module` when it has one.

Control-flow semantics (aligned with the Python SFM reference runtime):

- A step runs in this order: condition (false skips the rest of the step), context update, call, activity (its result is stored in `$context[<step_id>]`), jump, pause.
- `call` runs the target block from the target step and then returns into the calling step, which continues with its activity, jump and pause.
- `jump` transfers control without returning. The nearest invocation running the target block restarts at the target step; otherwise the target block becomes the running block and pending call continuations are dropped.
- Blocks do not fall through: the flow ends when the running block returns.
- `pause` stores the position in `_flow_state`; running the flow again with the same context resumes after the paused step. Pending call return frames are kept in `_flow_stack`, so a pause at any call depth resumes back through every caller. This differs from the Python reference runtime, where call continuations are not persisted and a resume inside a called block ends the flow when that block returns.
- YAML parsing is handled internally by runner for flow DSL inputs (no external YAML package at runtime). Supported: block mappings and sequences (including indentless and `- - x` nested sequences), plain/quoted scalars with YAML 1.1 resolution as PyYAML `safe_load`, block scalars (`|`, `>`, `-`/`+` chomping), one-line flow collections (`[a, b]`, `{k: v}`), comments. Anchors, aliases, tags and multi-line flow collections are rejected.
- Block and step ids must not start with `_` or contain `.`.

Function resolution (`runner::importer(string $name, ?string $module = null, array $context = [], ?callable $logger = null)`):

- Root folder: context `_root_folder` (evaluated at each import call), else the `DIV_RUNNER_ROOT_FOLDER` constant (default `./`).
- Without a module: `<root>/<name>.php`, then `<name>.php` as a path, then a callable identifier (global or namespaced function available through autoload).
- With a module: only `<module folder>/<name>.php`, where the module folder is `_modules[<module>]` from the context, else `<root>/<module>`. There is no fallback outside the module, so equal names in different modules do not collide.
- Module names use letters, digits, `_` and `-`; function names inside a module are relative, without `.` or `..` segments.
- `function_map` (compile option) maps qualified names (`module:name` or `name`) to qualified names.
