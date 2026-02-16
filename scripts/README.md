# Scripts

Automation scripts for release and documentation.

## Documentation PDF

`build_pdf.py` builds a combined markdown book and optional PDF artifact.

Examples:

```bash
python scripts/build_pdf.py --markdown-only --no-mermaid
python scripts/build_pdf.py --output runner-documentation.pdf
```
