# Simulation example

An invoice flow, simulated in two separate PHP processes with test doubles.
The real functions in `functions/` write `payments.log` and `outbox.log`. A
simulation never loads them, so neither file appears.

| File | Content |
|---|---|
| `flows/invoice.yml` | Validate (`orders` module), price (`billing`), call `approval.check` (pauses when approval is needed), call `payment.charge` (retries once through `jump` when declined), send the receipt. |
| `functions/` | The real functions, for `runner::run()`. |
| `context.json` | Initial context. `order.card_token` is redacted in traces. |
| `doubles.json` | Test doubles, plus a budget: the first charge is declined, the retry is captured. |
| `expected/segment-1.json`, `expected/segment-2.json` | The deterministic part of both traces (`meta`, `sources` and `fingerprint` removed). |

Copy the files of this folder into a project where `composer require
divengine/runner` ran, and run from the project root:

```console
$ php vendor/divengine/runner/bin/run.php flows/invoice.yml --simulate doubles.json \
      --context context.json --session session.json --trace trace-1.json
paused
$ php vendor/divengine/runner/bin/run.php flows/invoice.yml --simulate doubles.json \
      --session session.json --trace trace-2.json
done
```

What the traces show:

- **First segment:** the steps up to `approval.check`, which pauses inside the
  call. `run.end.checkpoint` holds the `_flow_state` and `_flow_stack` the
  resume continues from.
- **Second segment:** it resumes in `approval`, returns into `main.approval`
  (`call.return` with `resumed: true`) and calls `payment`. There the first
  charge is declined, `retry` jumps back to `charge`, and the second charge is
  captured. Then `main.receipt` runs.

`tests/CliTest.php` runs exactly these two commands against the
Composer-installed package and compares the traces with `expected/`.
