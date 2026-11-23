# Tracing and Simulation

Runner can record what a flow does as a sequence of **semantic events**, and it
can **simulate** a YAML flow with test doubles instead of its functions. Both
use Runner's own compiler and runtime: the same blocks, steps, `call`, `jump`,
`pause`, `_flow_state` and `_flow_stack` as `runner::run()`. There is no second
interpreter.

| | `runner::run()` | `runner::runTraced()` | `runner::simulate()` |
|---|---|---|---|
| Runs the flow's functions | yes | yes | **no**, only declared doubles |
| Flow kinds | callable, PHP file, YAML | callable, PHP file, YAML | YAML only |
| Returns | nothing (context) | trace document | trace document |
| Budget (steps, transfers, time) | none | none | yes |
| CLI | `bin/run.php <flow>` | `--trace <file>` | `--simulate <doubles.json>` |

## Tracing a run

```php
use divengine\runner\runner;

$context = ["_root_folder" => __DIR__ . "/functions", "items" => [/* ... */]];
$trace = runner::runTraced(__DIR__ . "/flows/order.yml", $context, [
    "trace" => ["max_events" => 5000, "redact_keys" => ["iban"]],
]);

echo $context["_runner_state"];        // as after runner::run()
echo count($trace["events"]);          // semantic events
```

Tracing only observes:

- **Same result.** The context ends exactly as after `runner::run()`: same keys,
  values, order, pause position and call frames.
- **Nothing saved in the context.** The trace state lives outside the context,
  so nothing of it can be stored with a checkpoint.
- **Off by default.** `runner::run()` stays untraced.

Two differences from `run()` are part of the API:

- **Setup errors.** Errors such as invalid YAML are recorded in the context and
  in `result.setup_error` instead of being thrown.
- **Nested `runner::run()` calls.** A `run()` call made by an activity is a
  separate, untraced invocation. A flow used as an activity *is* traced, as a
  nested flow scope.

```bash
php vendor/divengine/runner/bin/run.php flows/order.yml --context state.json --save-context state.json --trace trace.json
```

`--trace` adds a file and changes nothing else. stdout, exit codes and the saved
context stay the same. A traced real run on the command line uses the default
trace options. A simulation reads its trace options from the `--simulate` file.

## Simulating with test doubles

```php
$context = json_decode(file_get_contents("context.json"), true);
$doubles = [
    "orders:validate_order" => ["returns" => true],
    "billing:compute_total" => ["returns" => 1250.0],
    "needs_approval" => ["returns" => true],
    "billing:charge_card" => ["sequence" => [
        ["returns" => ["status" => "declined"]],
        ["returns" => ["status" => "captured"], "context" => ["last_charge" => "captured"]],
    ]],
    "payment_declined" => ["sequence" => [["returns" => true], ["returns" => false]]],
    "send_receipt" => ["returns" => "receipt sent"],
];

$trace = runner::simulate("flows/invoice.yml", $context, $doubles, ["budget" => ["steps" => 200]]);
// paused at approval.check; resume with the same context:
$trace = runner::simulate("flows/invoice.yml", $context, $doubles, [
    "double_calls" => $trace["simulation"]["double_calls"],
    "fingerprint" => $trace["fingerprint"],
]);
```

### No function is ever loaded

In a simulation every `condition` and `activity` reference resolves to its
double:

- **Nothing is imported.** No function file is `require`d, and no callable name
  (such as `strlen` or `vendor\fn`) is called. `_root_folder`, `_modules` and
  `DIV_RUNNER_ROOT_FOLDER` play no part.
- **A reference without a double fails the step.** It adds a `function.missing`
  event and ends the run in the `error` state: `Simulation has no double for
  function 'billing:total'`. There is no fallback to real code.
- **Only YAML flows run.** PHP flow files and callables are refused before
  anything runs, because loading them would execute their code.

### Doubles

Keys are qualified function names as the step references them: `module:name`
for a step with `module`, otherwise the plain name. A `function_map` option is
applied first, as at compile time.

| Double | Meaning |
|---|---|
| `{"returns": value}` | Returns `value` (a condition uses its PHP truthiness). |
| `{"throws": "message"}` | Throws a `RuntimeException`; the step fails. |
| `{"pause": true}` | Asks to pause the flow at this step (activities only). |
| `{"sequence": [outcome, ...]}` | Outcome *n* answers call *n*; a call past the end fails. |
| `{"flow": "child.yml"}` | A nested YAML flow used as the activity, simulated with the same doubles. |

- **Context merge.** An outcome may add `"context": {...}`, merged into the
  context before it returns or pauses, as a real activity would. Keys starting
  with `_` are refused.
- **Pause semantics.** A pause outcome follows Runner's activity-pause rule: the
  step pauses without storing a result, and the resume continues after it.
- **Pause inside a nested flow.** It is the nested flow's early return (result
  `null`), as in `runner::run()`.
- **Call counts across resumes.** `double_calls` in the trace counts the calls
  made to each double. Pass it back to continue sequences after a resume.
- **Shared context.** Doubles share the context of one simulated invocation,
  like real functions.

### Budget and cancellation

A simulation stops on the first exhausted budget. It records a
`budget.exhausted` event, and the run ends in the `error` state with the usual
reset of `_flow_state` and `_flow_stack`.

| Budget | Default | Counts |
|---|---|---|
| `steps` | 10000 | steps entered (`step.start`) |
| `transfers` | 10000 | `call` and `jump` transfers |
| `seconds` | 10 | wall-clock time of the invocation |

- **Scope.** Budgets apply to one invocation; a resumed segment starts again
  from zero.
- **Cancellation.** The `cancel` option is a callable, checked at every step and
  transfer. Returning `true` stops the simulation with budget `cancel`.
- **Depth.** Recursion depth stays limited by `_max_call_depth`, as in every run.

Normal runs (`run()` and `runTraced()`) have no budget; see issue #31 for an
execution budget of real runs.

### Command line and sessions

```bash
php vendor/divengine/runner/bin/run.php flows/invoice.yml --simulate doubles.json \
    --context context.json --session session.json --trace trace-1.json        # paused, exit 3
php vendor/divengine/runner/bin/run.php flows/invoice.yml --simulate doubles.json \
    --session session.json --trace trace-2.json                               # done, exit 0
```

**The `--simulate` file:**

```json
{
    "description": "optional",
    "doubles": {"billing:compute_total": {"returns": 1250.0}},
    "budget": {"steps": 200, "transfers": 50, "seconds": 5},
    "trace": {"max_events": 5000, "redact_keys": ["iban"]}
}
```

**The session file.** `--session` keeps a simulation's state between processes:
the simulated context, the double call counts and the fingerprint.

```json
{"protocol": "divengine.runner.simulation-session", "version": 1,
 "fingerprint": "sha256:...", "double_calls": {"needs_approval": 1}, "context": {...}}
```

- **Resuming.** When the file exists, the simulation resumes from it. The
  `--context` option cannot be combined with an existing session.
- **When it is written.** The file is (re)written atomically after every
  segment, including errors.
- **Real checkpoints are safe.** A file that is not a session, such as a
  normal-run context, is refused and left untouched.
- **Changed sources.** If the flow, the doubles or a nested flow changed since
  the session was saved, the command exits with `2` and does not touch the
  session.

**Rules that keep a simulation separate from real runs:**

- **Refused options.** `--save-context` and `--root` cannot be combined with
  `--simulate`.
- **Distinct paths.** `--trace` and `--session` cannot be any of the input files
  or each other.

**Exit codes** are the usual ones:

| Code | Meaning |
|---|---|
| `0` | done |
| `3` | paused |
| `1` | error, including an unmocked function, a double that throws or an exhausted budget |
| `2` | invocation error: invalid doubles file, budget or session; a changed source; a trace or session that could not be written |

stdout keeps its contract: the state, or the context with `--json`.

## Trace document

Protocol `divengine.runner.trace`, version `1`. The JSON Schema is
[`trace.schema.json`](trace.schema.json).

```json
{
    "protocol": "divengine.runner.trace",
    "version": 1,
    "mode": "simulation",
    "flow": {"path": "flows/invoice.yml", "id": "invoice", "callable": null},
    "fingerprint": "sha256:5c0e…",
    "sources": [
        {"role": "flow", "name": "flows/invoice.yml", "path": "/app/flows/invoice.yml", "sha256": "…"},
        {"role": "doubles", "name": "doubles", "path": null, "sha256": "…"}
    ],
    "limits": {"max_events": 10000, "max_bytes": 4194304, "max_string": 1024, "max_items": 100,
               "max_depth": 8, "max_nodes": 2000, "capture_values": true, "redact_defaults": true},
    "budget": {"steps": 200, "transfers": 50, "seconds": 5.0},
    "result": {"state": "paused", "error": null, "setup_error": false},
    "simulation": {"double_calls": {"orders:validate_order": 1, "billing:compute_total": 1, "needs_approval": 1}},
    "truncated": false,
    "truncation": null,
    "events": [ ... ],
    "meta": {"started_at": "2026-11-23T10:00:00+00:00", "duration_ms": 1.9, "php_version": "8.3.14"}
}
```

### Source identity

- **`sources`** lists every source file with its SHA-256: the flow, the function
  files a traced run loaded (role `function`), and in a simulation the doubles
  and nested flows.
- **`fingerprint`** covers the sources known before the run starts: the flow,
  and in a simulation the doubles and nested flows. It covers file content and
  roles, not paths, so it stays the same when the project moves.
  - `simulate()` takes an expected `fingerprint` and refuses to resume when it
    differs.
  - For traced real runs, function files are discovered while running. Compare
    their `sources` entries between segments to detect a change.

### Determinism

The same flow, initial context and doubles give the same `events`, `result`,
`simulation`, `fingerprint` and `sources` hashes. Two fields are excluded from
comparisons:

- **`meta`** (start time, duration, PHP version).
- **`sources[].path`**, which depends on the installation.

Events carry no timestamps and never the internal pause and jump tokens.

### Events

Every event has these fields:

| Field | Meaning |
|---|---|
| `seq` | Position in the trace, starting at 1, with no gaps. |
| `type` | Event type (below). |
| `flow_depth` | `0` for the flow being run, `1+` inside a flow used as an activity. |
| `call_depth` | Pending `call` return frames in the current flow scope. |

Step events also carry `block`, `step` (the step id) and `index` (the 1-based
position in the block). In `step.start` and `step.skip`, `step` is `null` when
the flow is PHP code generated by an earlier Runner version. That code does not
pass step ids, but it still runs unchanged.

| Type | Fields | Meaning |
|---|---|---|
| `run.start` | `mode`, `resume`, `checkpoint`, `context` | An invocation starts; `resume` is true when it continues a checkpoint. |
| `run.autoresume` | `checkpoint` | `_autoresume` continues after a pause in the same invocation. |
| `run.end` | `state`, `error`, `setup_error`, `checkpoint`, `context` | The invocation ends (always recorded). |
| `flow.enter` / `flow.exit` | `initial_block` / `outcome` (`done`, `paused`, `error`) | A flow used as an activity starts or ends. |
| `block.enter` | `block`, `reason` | A block runs; `reason`: `start`, `resume`, `call`, `jump`, `return`. |
| `block.exit` | `block` | The block's steps are finished. |
| `step.start` | `continuation` | A step runs; `continuation` is true when its call returns into it after a resume. |
| `step.skip` | `reason` | The step does not run: `condition_false`, `before_target` (before the target of a call or jump) or `before_checkpoint` (already executed before a pause). |
| `condition` | `function`, `result`, `value`? | Condition outcome; `value` is present when the condition did not return a boolean. |
| `condition.error` | `function`, `error` | The condition threw. |
| `context.update` | `changes` | The step's `context` mapping was merged. |
| `activity.start` | `function` | An activity starts. |
| `activity.end` | `function`, `result`, `changes`, `nested_flow_paused`? | It returned; `result` is stored under the step id. |
| `activity.error` | `function`, `error` | It threw; the run fails. |
| `activity.pause` | `function`, `changes` | It asked to pause; a `pause` event follows. |
| `call` | `target`, `returns: true` | A call transfers to `target` (`block`, `index`) and will return. |
| `call.return` | `from`, `to`, `resumed` | Control returns into the calling step; `resumed` when the return frame came from a checkpoint. |
| `call.drop` | `frames` | A jump left called blocks; these return frames are dropped. |
| `jump` | `target`, `returns: false` | A jump transfers to `target` without returning. |
| `pause` | `checkpoint` | The run pauses after this step. |
| `double` | `function`, `call`, `outcome` | A double answered (`returns`, `throws`, `pause`, `exhausted`). Simulation only. |
| `function.missing` | `function` | No double for a reference. Simulation only. |
| `budget.exhausted` | `budget`, `limit`, `used` | A simulation budget stopped the run (always recorded). |
| `log` | `level`, `message`, `block`, `step` | A message the flow's functions logged through `_logger`; Runner's own log lines are not repeated. |
| `trace.truncated` | `reason`, `dropped_events` | The trace hit a cap (always recorded, last). |

**Reconstructing the execution:**

- **Visited and current.** A step is visited when it has a `step.start`. The
  current step is the last one started without a following `step.start`,
  `block.exit` or `run.end`.
- **Skipped and failed.** Skipped steps are `step.skip` events. Failed steps end
  in `activity.error` or `condition.error`.
- **Active blocks.** The call stack is visible through `call` / `call.return` /
  `call.drop` and `call_depth`.
- **Paused.** A paused step is the `pause` event; `run.end.checkpoint` holds the
  exact `_flow_state` and `_flow_stack` a resume continues from.
- **Transfers.** `call` and `jump` are separate events, and `returns` makes the
  difference explicit.

**Changes.** `changes` lists business keys (keys not starting with `_`) that
differ before and after the step: `{"key", "op": "added" | "changed" |
"removed", "before"?, "after"?}`. `context` snapshots in `run.start` and
`run.end` hold the business keys too.

### Values, caps and redaction

Trace values are JSON-safe copies, bounded by `limits` (trace option names):

| Option | Default | Effect |
|---|---|---|
| `max_events` | 10000 | Events kept; later ones are dropped and counted. |
| `max_bytes` | 4 MiB | Encoded size of the kept events. |
| `max_string` | 1024 | Bytes of a string (cut on a UTF-8 boundary). |
| `max_items` | 100 | Items of an array. |
| `max_depth` | 8 | Nesting levels of an array; also stops recursive arrays. |
| `max_nodes` | 2000 | Values copied per event, overall. |
| `capture_values` | true | `false` keeps only value types. |
| `redact_keys` | `[]` | Extra key names to redact (case-insensitive). |
| `redact_defaults` | true | Redact keys that look secret (below). |
| `exclude_keys` | `[]` | Keys left out of snapshots, changes and values. |

**Markers.** Values a cap cut, values JSON cannot hold and redacted values
become marker objects:

| Marker | Stands for |
|---|---|
| `{"$type": "redacted"}` | A redacted value. |
| `{"$type": "closure"}` | A closure. |
| `{"$type": "object", "class": …}` | Any other object. |
| `{"$type": "resource", "kind": …}` | A resource; `kind` is `closed` for a closed one. |
| `{"$type": "float", "value": "NAN"}` | A `NAN` or infinite float. |
| `{"$type": "binary", "length": n}` | Invalid UTF-8. |
| `{"$type": "string", "truncated": true, "length": n, "value": prefix}` | A string cut by `max_string`. |
| `{"$type": "array", "truncated": true, "count": n}` | An array cut by `max_depth`. |
| `{"$type": "more", "count": n}` | Last item of a list cut by `max_items`. |
| `"$more": n` | Key added to a map cut by `max_items`. |
| `{"$type": "truncated"}` | A value cut by `max_nodes`. |

User data that itself holds a `$type` key reads like a marker.

**Truncation is explicit.** `truncated` and `truncation` describe it in the
document. `run.end`, `budget.exhausted` and `trace.truncated` are always kept,
so a trace may exceed `max_events` by those three events. Tracing never stops or
changes the run.

**Secrets.** By default, values under keys matching `password`, `passwd`,
`passphrase`, `secret`, `token`, `api_key`, `access_key`, `private_key`,
`authorization`, `bearer`, `credential`, `cookie`, `session_id`, `signature`,
`cvv` and `card_number` are redacted at any depth. Variants with `-` and
capitals match too, as do keys that contain these words. This covers context
snapshots, changes, step `context` mappings and activity results (under the
step id).

What redaction does not cover:

- **Messages.** Error messages (`error.message`) and `log` messages are kept as
  written, capped by `max_string`. Do not put secrets in exception or log
  messages.
- **`_runner_logs` and `_runner_error`.** These context keys are written by
  Runner, not by the trace, and are not redacted.

Redact or exclude more keys with `redact_keys` and `exclude_keys`, or set
`capture_values: false`.

## Compatibility policy

- **Versions.** `version` changes only for incompatible changes: removing or
  renaming a field or event type, or changing the meaning of a field.
- **Additions.** New event types, fields and enum values may be added within a
  version. Consumers must ignore unknown fields and unknown event types.
- **Session files** (`divengine.runner.simulation-session`) follow the same
  policy. A session can only resume with an unchanged fingerprint, so a newer
  Runner may refuse an old session; start a new one then.
- **Tracing never changes run semantics.** If Runner's semantics change in a
  release, traces show the change; the protocol version only describes the
  document.

## Security model

- **A simulation guarantees one thing:** it does not load or call the flow's
  functions. The YAML is compiled by Runner, which embeds values only through
  `var_export()`, and the doubles are data.
- **It is not a sandbox.** The PHP process still runs with its own privileges,
  and there is no OS isolation. A child process alone isolates nothing.
- **Inputs must be controlled.** Simulation does not make hostile input safe.
  A doubles file chooses which nested YAML files are compiled, and the YAML
  compiler and parser are not hardened against hostile input. Treat flows and
  doubles files as trusted inputs.
- **Real runs are unchanged.** `runner::run()` and `runner::runTraced()` execute
  trusted PHP code, as described in [security.md](security.md).

## Limitations

- **Supported flows.** Simulation runs YAML flows only; nested flows must be
  YAML too (`{"flow": "child.yml"}`).
- **Not simulated.** A double cannot be a PHP callable, and a condition double
  cannot pause, because Runner defines no condition pause.
- **Granularity.** Diffs compare top-level business keys: a change inside a
  nested array shows the whole key before and after (bounded by the caps).
- **Budgets.** CPU time is not measured; the wall-clock budget is checked at
  every step and transfer. Normal runs have no budget (issue #31).
- **Empty objects.** An empty JSON object appears as `[]`, as everywhere in
  Runner's JSON (PHP arrays).
