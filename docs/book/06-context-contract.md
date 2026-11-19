# Context Contract

## Shared Mutable State

All activities and conditions operate on the same context array by reference. This enables cross-step data flow without copying.

## Reserved Namespace

Runner reserves keys prefixed with `_` or `__` for runtime mechanics.

- See full table: `docs/context-keys.md`
- User domain data should avoid reserved prefixes.

## Resume and Pause

Pause behavior stores continuation state in `_flow_state` (and pending call return frames in `_flow_stack`). On next run:

- runner reuses persisted state
- `_check_pause` skips already executed steps
- execution continues after the paused step, then returns through pending calls

An activity can pause the flow by throwing an exception whose message contains `$context["_exception_pause"]`; its result is then not stored. Inside a flow used as an activity, a pause ends only that nested flow.

Runner does not persist anything itself: store the context (data only) and pass it back to `runner::run()`.

## Error Classification

Runner uses per-run random tokens to classify generic exceptions as:

- pause (`_exception_pause_token`)
- jump (`_exception_jump_token`)

This avoids requiring custom exception classes in user activities.
