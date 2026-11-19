# Importer and Function Resolution

## Resolution Strategy

Importer receives a reference and resolves it in this order:

1. Build candidate file paths using runtime root rules (`.php` is appended when missing).
2. Require the first existing file; it must return a callable.
3. If no file resolved, accept the reference as a callable identifier (`strlen`, `vendor\\pkg\\fn`).
4. If unresolved, return `null` and emit an error log.

A file therefore wins over a same-named PHP function.

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
