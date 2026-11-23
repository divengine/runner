# Security and Trust Assumptions

Runner executes code. Treat everything it runs as trusted application code.

## Trust boundary

- **Flows, activities and conditions are PHP code.** `importer()` uses `require`
  on files and calls any callable name it resolves. A flow definition that names
  a function or a path runs that code with the privileges of the PHP process.
- **YAML flows are compiled to PHP and executed with `eval()`** in
  `runner::flowFromYaml()` (and so in `runYaml()` and `run()` with a `.yml` path).
  Generated code embeds YAML values only through `var_export()`, so a value cannot
  inject PHP syntax. But the flow chooses which functions and files run, so a
  YAML flow is as trusted as PHP code. **Runner is not a sandbox: do not run
  flows from untrusted sources.**
- **Unqualified references are not confined.** They may be absolute paths or
  contain `..`, and may name any callable; `_root_folder` is a resolution base,
  not a jail. If users can influence function names, map them to an allow-list
  yourself (for example with the `function_map` option) before running.
- **Module-qualified references stay in their module folder.** Module names
  (letters, digits, `_`, `-`) and function names inside a module (relative, no
  `.` or `..` segments) are checked at compile time and again by `importer()`.
  The folders themselves (`_modules`, root folder) are trusted code; symbolic
  links inside them are followed.
- **The command line runs the same trusted code.** `bin/run.php` calls
  `runner::run()` with the flow file and the context file it is given; it adds
  no isolation. A context file can set reserved keys such as `_root_folder` and
  `_modules`, so it decides which function files load: treat it as trusted input.
- **Simulation does not load functions, but it is not a sandbox.**
  `runner::simulate()` and `--simulate` resolve every condition and activity
  to a declared double: no function file is required and no callable name is
  called. The flow and any nested flow named in the doubles are still compiled
  by Runner and run in the same PHP process. Treat flows and doubles files as
  trusted inputs; there is no OS isolation.
- **Traces redact values by key and leave messages out.** Values under
  secret-looking keys are redacted by default. Error and log messages are free
  text, so traces replace them with a redaction marker unless
  `capture_messages` (`--trace-messages`) is set. Context and session files
  still hold `_runner_error` and `_runner_result` as written (see
  [tracing-and-simulation.md](tracing-and-simulation.md)).
- **The context is shared and mutable.** Any activity can read and change any key,
  including reserved ones (`_flow_state`, `_root_folder`, helpers).

## Recommendations

- Load flows only from your own code base or a reviewed store.
- Prefer `runner::generateFlowCodeFromYaml()` at build time and commit the
  generated PHP when you want flows reviewed like any other code.
- When storing a paused context, store only data (JSON-serializable values);
  closures, objects and resources are not restorable.
- Do not put secrets in contexts that you log: `_runner_logs` and error dumps may
  contain context summaries and exception traces.

## Reporting

Report vulnerabilities privately to the maintainers through
[divengine.com](https://divengine.com), not in public issues.
