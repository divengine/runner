# Runtime Architecture

## Execution Pipeline

```mermaid
flowchart TD
    A[run flow input] --> B{flow type}
    B -->|callable| C[validate callable signature]
    B -->|php path| D[importer load callable]
    B -->|yaml path| E[compile yaml to flow closure]
    D --> C
    E --> C
    C --> F[initialize runtime helpers in context]
    F --> G[execute flow loop]
    G --> H{state}
    H -->|done| I[set _runner_* result metadata]
    H -->|paused| J[persist _flow_state]
    H -->|error| K[set _runner_error]
```

## Main Components

- `run`: prepares state, injects helpers, resolves flow input type, controls resume loop.
- `flowLoop`: executes block callables and handles jump/pause control flow.
- wrappers: `wrapCondition`, `wrapActivity`, `wrapCall`, `wrapJump`, `wrapPause`.
- importer: resolves runtime references into callables.
- YAML compiler: parses DSL and generates executable closure code.

## Mutable Context Principle

Every executable unit receives `array &$context` and mutates the same memory object. There is no immutable snapshot contract for activities.
