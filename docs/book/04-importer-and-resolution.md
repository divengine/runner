# Importer and Function Resolution

## Resolution Strategy

Importer receives a reference and resolves it in this order:

1. If reference is already callable, return it.
2. Build candidate file paths using runtime root rules.
3. Require the first existing file that returns a callable.
4. If no file resolved, retry callable lookup by identifier.
5. If unresolved, return `null` and emit error log.

## Runtime Root Rules

Importer root folder precedence:

1. `_root_folder` in current context.
2. `DIV_RUNNER_ROOT_FOLDER` constant.
3. `./` fallback.

This is evaluated for every import call, so activities can change `_root_folder` mid-flow.

## Supported Reference Shapes

- relative path with or without `.php`
- absolute path
- namespaced callable (`vendor\\package\\function_name`)
- global callable (`strlen`, etc.)

## Importer Contract

- File imports must `return` a callable.
- Callable signature validation for flow entrypoint is enforced by runner.
