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
use Divengine\Runner\Runner;

$context = [
    "name" => "World",
];

$flow = function (array &$context): void {
    $context["greeting"] = "Hello " . $context["name"];
};

Runner::run($flow, $context);

echo $context["greeting"]; // Hello World
```

## Documentation

See `docs/README.md` for extended notes on context keys and helpers.

Powered by [Divengine Software Solutions](https://divengine.com)