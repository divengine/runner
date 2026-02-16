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

The runner injects helpers into the context for flow control:

- `_wrap_condition`, `_wrap_activity`, `_wrap_call`, `_wrap_jump`, `_wrap_pause`
- `_check_pause`, `_update_context`
- `__flow_loop` for block-style execution

These helpers mirror the logic in the original Python runner.

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
- `activity` and `condition` values can omit `.php` extension.
- `call` and `jump` references use `block.step` syntax.
- `activity` and `condition` are imported as PHP callables through the standard importer.
- YAML parsing is handled internally by runner for flow DSL inputs (no external YAML package at runtime).

Function root precedence during YAML execution:

1. context `_root_folder` (evaluated at each import call)
2. `DIV_RUNNER_ROOT_FOLDER` constant (default `./`)
3. fallback `./`

Notes:

- `_root_folder` is a reserved runner context key.
- Importer first tries PHP files, and if no file resolves it also accepts callable identifiers (global or namespaced functions already available in runtime/autoload).
