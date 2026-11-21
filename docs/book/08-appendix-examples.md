# Appendix: Example Structure

## Suggested Layout

```text
project/
  flows/
    business_flow.yml
  activities/
    add_values.php
    uppercase_text.php
    write_file_line.php
  bootstrap.php
```

## Activity File Pattern

```php
<?php
return function (array &$context): void {
    $context["sum"] = (int) ($context["left"] ?? 0) + (int) ($context["right"] ?? 0);
};
```

## Running a YAML Flow

```php
<?php
use divengine\runner\runner;

$context = [
    "_root_folder" => __DIR__ . "/activities",
    "left" => 8,
    "right" => 5,
];

runner::run(__DIR__ . "/flows/business_flow.yml", $context);
```

## Running the Same Flow from a Terminal

```bash
echo '{"left": 8, "right": 5}' > input.json
php vendor/divengine/runner/bin/run.php flows/business_flow.yml \
    --context input.json --root activities --save-context state.json --json
```

The exit code is `0` (done), `3` (paused), `1` (error) or `2` (invocation
error). When the flow paused, resume it with `--context state.json
--save-context state.json`.

## Notes

- Keep reserved runner keys separate from business keys.
- Prefer explicit step ids that describe business intent.
- Keep activities focused and side effects explicit.
