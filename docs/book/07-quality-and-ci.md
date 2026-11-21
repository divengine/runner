# Quality and CI

## Validation Layers

- Unit tests: `vendor/bin/phpunit` (`tests/CliTest.php` installs the working tree as a Composer package offline and runs `bin/run.php` in fresh processes)
- Static analysis: `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G`
- Installation smoke test: `composer smoke` (installs the `git archive` dist in a clean project and runs `examples/order` through the API and through `bin/run.php`)
- Optional benchmark suite: `composer bench`

CI runs Composer validation, PHP syntax checks, PHPUnit and the smoke test on PHP 8.1 to 8.5, and PHPStan on PHP 8.1.

## Releases

A release is created only by pushing a `vMAJOR.MINOR.PATCH` tag that points to a commit on `main`. The release workflow checks the tag, the release notes (`releases/<tag>.md`), that `composer.json` has no `version` field and that the release does not exist yet; it reruns tests and static analysis on the tagged revision and then publishes the GitHub release. Packagist reads versions from tags.

## Documentation Build Workflow

Repository includes a dedicated workflow that builds PDF documentation from markdown chapters.

Workflow responsibilities:

- install Mermaid CLI
- install Pandoc + LaTeX
- build `build/book.md`
- render `build/runner-documentation.pdf`
- upload artifacts

## Local Build

Generate markdown book only:

```bash
python scripts/build_pdf.py --markdown-only --no-mermaid
```

Generate full PDF (requires mermaid, pandoc, xelatex):

```bash
python scripts/build_pdf.py --output runner-documentation.pdf
```
