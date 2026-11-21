# Command Line

## Entry Points

Runner has two entry points with the same execution semantics:

- the PHP API, `runner::run($flow, $context)`, called from your code;
- the command-line bootstrap `bin/run.php`, run by the PHP binary from a terminal.

The bootstrap is a thin layer. It reads arguments and files, calls
`runner::run()` once and reports the result. It does not parse YAML, execute
steps, handle pauses or import functions itself; the library does that.

```bash
php vendor/divengine/runner/bin/run.php flows/order.yml
php vendor/divengine/runner/bin/run.php flows/order.yml --context input.json --root functions
php vendor/divengine/runner/bin/run.php flows/order.yml --context state.json --save-context state.json
```

## Arguments

| Argument | Meaning |
|---|---|
| `<flow>` | A `.yml`, `.yaml` or `.php` flow file. |
| `--context <file>` | Initial context: a JSON object. |
| `--root <dir>` | Folder of activity and condition files; stored as an absolute `_root_folder`. |
| `--save-context <file>` | Write the resulting context as JSON, replacing the file atomically. |
| `--json` | Print the resulting context as JSON instead of the state. |
| `-h`, `--help` | Usage. |

Values can also be written as `--root=functions`. Relative paths are relative to
the current working directory: the flow file, the option values, and relative
`_root_folder` or `_modules` values read from the context file. Modules are
configured in the context file:

```json
{"_modules": {"billing": "lib/billing", "shipping": "lib/shipping"}}
```

## Output Protocol

| Exit code | stdout | Meaning |
|---|---|---|
| `0` | `done` | The flow finished. |
| `3` | `paused` | The flow paused and can be resumed. |
| `1` | `error` | The flow ended in the `error` state (failing step or setup error). |
| `2` | (empty) | Invocation error: bad arguments or unreadable input (the flow did not run), or the context could not be saved or printed as JSON. |

With `--json`, stdout carries the resulting context instead of the state line;
the exit code is the same. Diagnostics go to stderr. Output printed by the flow,
PHP warnings and output left in open buffers are also sent to stderr, so stdout
only carries the result.

The context is encoded with `json_encode()` and `JSON_PRESERVE_ZERO_FRACTION`,
so `25.0` stays a float when it is read back. Before encoding, the bootstrap
checks that every value would come back unchanged: only `null`, booleans,
integers, floats, strings and arrays of them, at most 512 levels deep. This
matters because `json_encode()` silently turns objects and closures into JSON
objects, which would not restore the original values on resume. Objects,
closures and resources are rejected wherever they are nested; the limit also
stops recursive (reference) arrays. Values that `json_encode()` itself cannot
encode (`NAN`, `INF`, invalid UTF-8) fail through `JSON_THROW_ON_ERROR`.

In every such case the command prints a diagnostic on stderr, for example
`runner: flow ended done, but the context is not JSON-serializable:
context["data"]["callback"]: object of class Closure cannot be restored from
JSON`, prints nothing on stdout and exits with code `2`. The existing
`--save-context` file is not modified.

## Pause and Resume in the Terminal

```console
$ php vendor/divengine/runner/bin/run.php flows/order.yml --context input.json --root functions --save-context state.json
paused
$ php vendor/divengine/runner/bin/run.php flows/order.yml --context state.json --save-context state.json
done
```

Every invocation is a new PHP process. The saved `_flow_state` and `_flow_stack`
carry the position and the pending call return frames, so a pause inside nested
calls resumes into every caller. Steps that already ran are not repeated. An
error clears the resume state, as described in the Context Contract chapter.

## Composer Autoloading

The bootstrap loads, in order, the first autoloader it finds:

1. the project autoloader, when installed as `<vendor-dir>/divengine/runner/bin/run.php`;
2. the autoloader of a repository checkout (`vendor/autoload.php` next to `src/`);
3. otherwise `src/runner.php` directly, since Runner has no dependencies.

Flows can therefore use the project's classes and Composer-autoloaded
functions without an application bootstrap.

## Project Shortcut

A project may add its own `run` file to type `php run flows/order.yml`:

```php
<?php
require __DIR__ . "/vendor/divengine/runner/bin/run.php";
```

The shortcut exists only in projects that add this file; PHP does not install a
global `run` command.

## Limits

- The command line runs trusted code, exactly like the API; it is not a sandbox.
- It has no scheduler, queue, worker, daemon or job database.
- A flow that calls `exit()` or hits a fatal PHP error ends the process with
  PHP's own exit status; nothing is saved.
- JSON objects become PHP arrays, so an empty object `{}` is saved back as `[]`.
