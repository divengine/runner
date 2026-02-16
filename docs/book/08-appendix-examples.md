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

## Notes

- Keep reserved runner keys separate from business keys.
- Prefer explicit step ids that describe business intent.
- Keep activities focused and side effects explicit.
