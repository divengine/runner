# Reserved Context Keys

This file is the source of truth for context keys reserved by `divengine\runner\runner`.

## Rules

- Keys prefixed with `_` or `__` are reserved for runner internals.
- Do not reuse reserved keys for business data.
- If a flow defines block ids in YAML, runner also reserves dynamic keys named `_<block_id>`.

## Reserved Keys

| Key / Pattern | Type | Set By | Purpose | User Guidance |
|---|---|---|---|---|
| `_root_folder` | `string` | User or flow activity | Base folder for importer path resolution at runtime. | Safe to set/update intentionally. |
| `_runner_started_at` | `string` (ISO-8601) | Runner | Run start timestamp. | Read-only metadata. |
| `_runner_ended_at` | `string` (ISO-8601) | Runner | Run end timestamp. | Read-only metadata. |
| `_runner_state` | `string` (`processing`,`done`,`paused`,`error`) | Runner | Current/final execution state. | Read-only metadata. |
| `_runner_result` | `string` | Runner | Human-readable execution summary. | Read-only metadata. |
| `_runner_error` | `string|null` | Runner | Exception dump when state is `error`. | Read-only metadata. |
| `_runner_logs` | `array<int, array<string,mixed>>` | Runner | Collected structured logs for the run. | Read-only metadata. |
| `_logger` | `callable` | Runner | Logger callback used by wrappers/helpers. | Internal helper; do not overwrite during run. |
| `_importer` | `callable` | Runner | Import callback used by flow/activity/condition runtime. | Internal helper; do not overwrite during run. |
| `_update_context` | `callable` | Runner | Wrapper helper to merge step `context` data. | Internal helper. |
| `_wrap_condition` | `callable` | Runner | Wrapper helper for condition execution/tracing. | Internal helper. |
| `_wrap_activity` | `callable` | Runner | Wrapper helper for activity execution/tracing. | Internal helper. |
| `_wrap_call` | `callable` | Runner | Wrapper helper for block `call` transitions. | Internal helper. |
| `_wrap_jump` | `callable` | Runner | Wrapper helper for block `jump` transitions. | Internal helper. |
| `_wrap_pause` | `callable` | Runner | Wrapper helper for pause control. | Internal helper. |
| `_check_pause` | `callable` | Runner | Helper that decides whether a step should run after resume. | Internal helper. |
| `__flow_loop` | `callable` | Runner | Main block-loop runtime entrypoint used by generated flows. | Internal helper. |
| `_flow_state` | `array{block:?string,step_index:int|null}` | Runner and wrappers | Internal pointer for resume/jump/call continuation. | Can be preloaded for advanced resume control only. |
| `_current_step_id` | `string` | Runner wrappers | Last step id touched by wrappers. | Trace/debug metadata. |
| `_current_step_index` | `int` | Runner wrappers | Last step index touched by wrappers. | Trace/debug metadata. |
| `_autoresume` | `bool` | User/flow and runner | If true on pause, runner immediately loops and resumes once. | Optional control flag; runner resets it to `false` after use. |
| `_exception_pause_token` | `string` | Runner | Unique token used to classify generic exceptions as pause. | Internal token; can be preseeded for deterministic integration. |
| `_exception_jump_token` | `string` | Runner | Unique token used to classify generic exceptions as jump. | Internal token; can be preseeded for deterministic integration. |
| `_exception_pause` | `string` | Runner | Runtime alias for pause token consumed by flow code. | Internal helper value. |
| `_exception_jump` | `string` | Runner | Runtime alias for jump token consumed by flow code. | Internal helper value. |
| `_<block_id>` | `callable` | Generated YAML flow runtime | Dynamic callable slot for each block declared in YAML (`main`, `audit`, etc.). | Reserved dynamic pattern; avoid using keys that match your block ids. |

## Compatibility Key

`flow_paused` (without underscore) is checked/reset by runner for backward compatibility resume behavior. Treat it as reserved operational state.
