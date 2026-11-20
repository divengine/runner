# Reserved Context Keys

This file is the source of truth for context keys reserved by `divengine\runner\runner`.

## Rules

- Keys prefixed with `_` or `__` are reserved for runner internals.
- Do not reuse reserved keys for business data.
- If a flow defines block ids in YAML, runner also reserves dynamic keys named `_<block_id>`.
- Keys marked *run-scoped* exist only while `runner::run()` executes; they are removed
  when it returns, so the context left behind is plain data (JSON-serializable unless
  your own code stored objects or closures in it).

## Reserved Keys

| Key / Pattern | Type | Set By | Purpose | User Guidance |
|---|---|---|---|---|
| `_root_folder` | `string` | User or flow activity | Base folder for importer path resolution at runtime. | Safe to set/update intentionally. |
| `_modules` | `array<string, string>` | User | Optional folder per module name for module-qualified functions; a module without an entry resolves to `<root>/<module>`. | Safe to set; module folders are trusted code. |
| `_runner_started_at` | `string` (ISO-8601) | Runner | Run start timestamp. | Read-only metadata. |
| `_runner_ended_at` | `string` (ISO-8601) | Runner | Run end timestamp. | Read-only metadata. |
| `_runner_state` | `string` (`processing`,`done`,`paused`,`error`) | Runner | Current/final execution state. | Read-only metadata. |
| `_runner_result` | `string` | Runner | Human-readable execution summary. | Read-only metadata. |
| `_runner_error` | `string|null` | Runner | Exception dump when state is `error`. | Read-only metadata. |
| `_runner_logs` | `array<int, array<string,mixed>>` | Runner | Collected structured logs for the run. | Read-only metadata. |
| `_logger` | `callable` | Runner | Logger callback: `($level, $message, $context = [])`. | Run-scoped; activities may call it. |
| `_importer` | `callable` | Runner | `($name, $module = null)`: resolves a function like `runner::importer()` with the current context. | Run-scoped; activities may call it. |
| `_update_context` | `callable` | Runner | Wrapper helper to merge step `context` data. | Run-scoped internal helper. |
| `_wrap_condition` | `callable` | Runner | Wrapper helper for condition execution/tracing. | Run-scoped internal helper. |
| `_wrap_activity` | `callable` | Runner | Wrapper helper for activity execution/tracing. | Run-scoped internal helper. |
| `_wrap_call` | `callable` | Runner | Wrapper helper for block `call` transitions. | Run-scoped internal helper. |
| `_wrap_jump` | `callable` | Runner | Wrapper helper for block `jump` transitions. | Run-scoped internal helper. |
| `_wrap_pause` | `callable` | Runner | Wrapper helper for pause control. | Run-scoped internal helper. |
| `_check_pause` | `callable` | Runner | Helper that decides whether a step should run after resume. | Run-scoped internal helper. |
| `__flow_loop` | `callable` | Runner | Main block-loop runtime entrypoint used by generated flows. | Run-scoped internal helper. |
| `_resume_after_call` | `callable` | Runner | Helper that tells a calling step it is re-entered after its call returned (resume). | Run-scoped internal helper. |
| `_flow_state` | `array{block?:?string,step_index?:int|null,after_call?:int}` | Runner and wrappers | Internal pointer for resume/jump/call continuation. `after_call` marks a calling step re-entered after its call returned. | Can be preloaded for advanced resume control only. |
| `_flow_stack` | `array<int, array{block:string,step_index:int}>` | `wrapCall` and `flowLoop` | Return frames of the calls in progress (calling block and step). Persisted with the context so a pause inside a called block resumes back into its caller. | Read-only metadata; keep it when storing a paused context. |
| `_current_block_id` | `string` | `flowLoop` and `wrapCall` | Block currently executing. | Trace/debug metadata. |
| `_flow_blocks` | `array<int, string>` | Generated YAML flow runtime | Block ids registered by the running generated flow; scopes nested flows. | Run-scoped; restored after a flow used as an activity. |
| `_max_call_depth` | `int` | User | Maximum nesting of `call`, and of flows used as activities, before the run fails (default `runner::MAX_CALL_DEPTH`, 256). | Optional control setting. |
| `_flow_depth` | `int` | `flowLoop` | Nesting level of flows running in this context (a flow used as an activity runs at depth 1+). | Run-scoped. |
| `_nested_flow_paused` | `bool` | `flowLoop` / `wrapActivity` | Marks a pause raised inside a nested flow, which ends that nested flow early. | Run-scoped; removed once handled. |
| `_current_step_id` | `string` | Runner wrappers | Last step id touched by wrappers. | Trace/debug metadata. |
| `_current_step_index` | `int` | Runner wrappers | Last step index touched by wrappers. | Trace/debug metadata. |
| `_autoresume` | `bool` | User/flow and runner | If true on pause, runner immediately loops and resumes once. | Optional control flag; runner resets it to `false` after use. |
| `_exception_pause_token` | `string` | Runner | Unique token used to classify generic exceptions as pause. | Internal token; can be preseeded for deterministic integration. |
| `_exception_jump_token` | `string` | Runner | Unique token used to classify generic exceptions as jump. | Internal token; can be preseeded for deterministic integration. |
| `_exception_pause` | `string` | Runner | Runtime alias for pause token consumed by flow code. | Internal helper value. |
| `_exception_jump` | `string` | Runner | Runtime alias for jump token consumed by flow code. | Internal helper value. |
| `_<block_id>` | `callable` | Generated YAML flow runtime | Dynamic callable slot for each block declared in YAML (`main`, `audit`, etc.). | Run-scoped; restored after a flow used as an activity. |
| `<step_id>` | `mixed` | `wrapActivity` | Result of the step's activity. | Business data; choose step ids that do not clash with your own keys. |
