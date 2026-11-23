# Tracing and Simulation

## Purpose

A Runner execution is a sequence of semantic decisions: which block runs, which
steps start or are skipped, what a condition decided, what an activity returned
and changed, where control transferred and whether it will return, where the
flow paused. Runner can record these decisions as a **trace** and can replay the
control flow of a YAML flow with **test doubles** instead of its functions.

Both features reuse the runtime described in the previous chapters. The
wrappers that run conditions, activities, calls, jumps and pauses also emit the
events, and the importer is the only place where simulation differs: it
answers from doubles instead of loading files.

## Entry Points

| API | Command line | Effect |
|---|---|---|
| `runner::runTraced($flow, $context, $options)` | `--trace <file>` | Normal run plus a trace document. |
| `runner::simulate($yaml, $context, $doubles, $options)` | `--simulate <doubles.json>` | Run with doubles; never loads a function. |
| options `double_calls`, `fingerprint` | `--session <file>` | Resume a simulation in another process. |

```bash
php vendor/divengine/runner/bin/run.php flows/invoice.yml --simulate doubles.json \
    --context context.json --session session.json --trace trace-1.json
php vendor/divengine/runner/bin/run.php flows/invoice.yml --simulate doubles.json \
    --session session.json --trace trace-2.json
```

## Guarantees

- **Untraced runs are unchanged.** `runner::run()` is untraced, and tracing
  changes neither the result, the order of execution, the pause position nor
  the call frames. Trace state is kept outside the context.
- **Simulation never imports.** No function file is required and no callable
  name is called. A reference without a double ends the run in `error`, with a
  `function.missing` event.
- **Determinism.** The same flow, context and doubles produce the same events.
  Only `meta` and source paths vary.
- **Bounded traces.** Caps for events, bytes, strings, items, depth and copied
  values are explicit; truncation is reported, never silent. The kept events
  never exceed `max_bytes`, and `run.end` is always kept, compacted if needed.
- **Messages stay out.** Error and log messages can hold anything, so a trace
  keeps them only with `capture_messages` (`--trace-messages`).
- **Resumes check their sources.** The fingerprint covers the flow, the
  doubles, nested flows and `function_map`; a changed one refuses to resume.
- **Bounded simulations.** Budgets for steps, transfers and wall-clock time
  stop endless loops with a `budget.exhausted` event.

## Example

`examples/simulation` contains a complete fixture for this chapter:
- `flows/invoice.yml`: modules, a condition, a call that pauses for approval,
  and a payment retry through `jump`;
- `functions/`: real functions with side effects;
- `doubles.json` and `context.json`;
- `expected/`: the deterministic part of both traces.

The protocol, every event type, the doubles format and the compatibility
policy are specified in `docs/tracing-and-simulation.md`, which follows this
chapter in the book.
