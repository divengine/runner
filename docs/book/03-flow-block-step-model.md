# Flow, Blocks, and Steps

## Model

A flow is defined as `flow -> blocks -> steps`.

- Blocks are named execution sections.
- Steps execute in order unless control transfer occurs (`call`, `jump`, `pause`).
- Step ids are unique per block.

## Step Behavior Summary

- `condition`: gate step execution.
- `context`: merge literal values into shared context.
- `activity`: invoke callable and store result under step id key.
- `call`: invoke another block and preserve continuation state.
- `jump`: transfer control to another block/step via jump token.
- `pause`: persist state and stop execution with pause token.

## Control Transfer Example

```mermaid
sequenceDiagram
    participant Main as block:main
    participant Audit as block:audit
    participant Final as block:finalize
    Main->>Main: step: sum
    Main->>Main: step: condition
    alt condition true
        Main->>Audit: jump audit.audit_stamp
        Audit->>Audit: activity steps
        Audit->>Final: jump finalize.set_read_key
    else condition false
        Main->>Final: jump finalize.set_read_key
    end
    Final->>Final: remaining steps
```
