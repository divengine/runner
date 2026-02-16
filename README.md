# Divengine Runner

[![Latest Stable Version](https://poser.pugx.org/divengine/runner/v)](https://packagist.org/packages/divengine/runner)
[![Total Downloads](https://poser.pugx.org/divengine/runner/downloads)](https://packagist.org/packages/divengine/runner)
[![Latest Unstable Version](https://poser.pugx.org/divengine/runner/v/unstable)](https://packagist.org/packages/divengine/runner)
[![License](https://poser.pugx.org/divengine/runner/license)](https://packagist.org/packages/divengine/runner)
[![PHP Version Require](https://poser.pugx.org/divengine/runner/require/php)](https://packagist.org/packages/divengine/runner)

**Divengine Runner** is a lightweight engine for executing PHP jobs and flows using a shared context array. A job is a PHP function that receives the shared context by reference and mutates it as needed.

## Install

```bash
composer require divengine/runner
```

## Basic Usage

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

## YAML Usage

```php
use divengine\runner\runner;

$context = [];
runner::run(__DIR__ . "/flows/sample.yml", $context);
```

`runner::run()` auto-compiles `.yml`/`.yaml` files to callable flows, and you can also use `runner::runYaml()` explicitly.

In YAML flows, `activity`/`condition` names can omit `.php`. Function lookup uses `_root_folder` in context first, then `DIV_RUNNER_ROOT_FOLDER` (default `./`) at import time. If no file is found, importer also accepts callable identifiers (e.g. `strlen` or namespaced functions loaded by Composer).
YAML parsing for flow definitions is built into runner (no third-party runtime parser dependency).

## Documentation

See `docs/README.md` for extended notes on context keys and helpers.
Reserved context key reference: `docs/context-keys.md`.

## Performance Benchmarks

Install dev dependencies and run:

```bash
composer bench
```

This runs baseline phpbench subjects for callable flow, importer-based flow, and direct flow loop execution.

Powered by [Divengine Software Solutions](https://divengine.com)
