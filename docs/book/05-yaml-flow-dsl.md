# YAML Flow DSL

## Supported Root Shape

The expected root is a mapping containing `blocks`.

```yaml
id: sample-flow
blocks:
  main:
    steps:
      step_one:
        activity: add_values
```

Blocks and steps can be expressed as mapping or list forms. Normalization converts both to the same runtime model.

## Step Fields

- `condition`: callable reference
- `activity`: callable reference
- `context`: inline key/value merge
- `call`: target in `block.step` syntax
- `jump`: target in `block.step` syntax
- `pause`: boolean

## Compiler Output

`runner::generateFlowCodeFromYaml` emits PHP closure code that:

- loads runner helper callbacks from context
- resolves callable references through importer at runtime
- registers dynamic block callables as `_<block_id>`
- starts execution via `__flow_loop`

## Parser Notes

Runner includes an internal YAML parser for flow DSL inputs. It supports:

- indentation-based mappings and sequences
- scalar conversion for bool/null/int/float/string
- comment stripping
- line-aware parse errors
