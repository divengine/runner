# Context Contract

## Shared Mutable State

All activities and conditions operate on the same context array by reference. This enables cross-step data flow without copying.

## Reserved Namespace

Runner reserves keys prefixed with `_` or `__` for runtime mechanics.

- See full table: `docs/context-keys.md`
- User domain data should avoid reserved prefixes.

## Resume and Pause

Pause behavior stores continuation state in `_flow_state`. On next run:

- runner reuses persisted state
- `_check_pause` skips already executed steps
- execution continues from stored block and index

## Error Classification

Runner uses per-run random tokens to classify generic exceptions as:

- pause (`_exception_pause_token`)
- jump (`_exception_jump_token`)

This avoids requiring custom exception classes in user activities.
