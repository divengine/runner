# Introduction

## What Runner Does

`divengine/runner` executes jobs represented as PHP callables sharing one mutable context array passed by reference.

The package supports:

- direct callables
- imported callables from PHP files
- YAML flow definitions compiled into executable PHP closures

## Core Terms

- Flow: runtime callable that orchestrates block execution.
- Block: named execution segment.
- Step: operation inside a block (`activity`, `condition`, `context`, `call`, `jump`, `pause`).
- Activity: callable that mutates shared context.
- Condition: callable returning boolean-like value for gated execution.

## Design Constraints

- Runtime behavior is deterministic based on context and flow definition.
- Reserved keys in context are prefixed with `_` or `__`.
- Import resolution is deferred to runtime, allowing dynamic root changes.
