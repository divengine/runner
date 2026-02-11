# Divengine Runner Documentation

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

After execution, the runner stores metadata in the shared context:

- `_runner_state`: processing | done | paused | error
- `_runner_result`: summary message
- `_runner_error`: full error details (if any)
- `_runner_logs`: structured log entries
- `_runner_started_at`: ISO-8601 start time
- `_runner_ended_at`: ISO-8601 end time

## Flow Helpers

The runner injects helpers into the context for flow control:

- `_wrap_condition`, `_wrap_activity`, `_wrap_call`, `_wrap_jump`, `_wrap_pause`
- `_check_pause`, `_update_context`
- `__flow_loop` for strider-style execution

These helpers mirror the logic in the original Python runner.
