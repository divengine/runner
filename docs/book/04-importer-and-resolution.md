# Importer and Function Resolution

## Resolution Strategy

`runner::importer(string $name, ?string $module = null, array $context = [], ?callable $logger = null)`
receives a function name and an optional module.

Without a module, it resolves in this order:

1. Build candidate file paths using runtime root rules (`.php` is appended when missing).
2. Require the first existing file; it must return a callable.
3. If no file resolved, accept the reference as a callable identifier (`strlen`, `vendor\\pkg\\fn`).
4. If unresolved, return `null` and emit an error log.

A file therefore wins over a same-named PHP function.

With a module, the only candidate is `<module folder>/<name>.php`. The module
folder is `_modules[<module>]` from the context, else `<root>/<module>`. Nothing
outside the module folder is tried: no root-level file, no path, no callable
name. The same function name in two modules therefore never collides, and a
missing module function fails as `module:name`.

## Modules in YAML

A step names its module with the optional `module` key; it applies to the
step's `condition` and `activity`:

```yaml
steps:
  invoice_total:
    module: billing
    condition: has_lines      # <root>/billing/has_lines.php
    activity: total           # <root>/billing/total.php
  shipping_total:
    module: shipping
    activity: total           # <root>/shipping/total.php
```

Module names use letters, digits, `_` and `-`. Function names inside a module
are relative (`total`, `rates/total`) without `.` or `..` segments. Both rules
are checked when the flow is compiled. The `function_map` compile option maps
qualified names (`module:name`, or `name` for steps without a module) to
qualified names. Activities can import functions themselves with
`$context["_importer"]($name, $module)`.

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
