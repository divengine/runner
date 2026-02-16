# Quality and CI

## Validation Layers

- Unit tests: `vendor/bin/phpunit`
- Static analysis: `vendor/bin/phpstan analyse -c phpstan.neon --no-progress --memory-limit=1G`
- Optional benchmark suite: `composer bench`

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
