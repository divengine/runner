import argparse
import json
import os
import re
import shutil
import subprocess
from datetime import date
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
DOCS_DIR = ROOT / "docs"
BUILD_DIR = ROOT / "build"
MERMAID_DIR = BUILD_DIR / "mermaid"


def read_order_file(order_path: Path) -> list[Path]:
    files: list[Path] = []
    seen: set[Path] = set()

    for raw_line in order_path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue

        path = (ROOT / line).resolve()
        if line.endswith("/") or path.is_dir():
            for md in sorted(path.rglob("*.md")):
                if md not in seen:
                    files.append(md)
                    seen.add(md)
            continue

        if path.is_file() and path.suffix.lower() == ".md" and path not in seen:
            files.append(path)
            seen.add(path)

    return files


def collect_markdown_files() -> list[Path]:
    files = sorted(DOCS_DIR.rglob("*.md"))
    readme = DOCS_DIR / "README.md"
    if readme in files:
        files.remove(readme)
        files.insert(0, readme)
    return files


def load_version() -> str:
    composer = ROOT / "composer.json"
    if not composer.is_file():
        return ""

    data = json.loads(composer.read_text(encoding="utf-8"))
    return str(data.get("version", "")).strip()


def combine_markdown(files: list[Path], version: str) -> str:
    header = [
        "---",
        "title: Divengine Runner",
        "subtitle: Workflow Runtime and Flow DSL",
        "author: Rafa Rodriguez (@rafageist)",
        f"date: {date.today().isoformat()}",
        "---",
        "",
        f"Version: {version or 'development'}",
        "",
    ]

    parts = ["\n".join(header)]

    for idx, path in enumerate(files):
        parts.append(path.read_text(encoding="utf-8").strip())
        if idx != len(files) - 1:
            parts.append("\n\\newpage\n")

    return "\n\n".join(parts).strip() + "\n"


def resolve_mermaid_cli() -> str:
    cli = os.environ.get("MERMAID_CLI", "mmdc")
    if os.name == "nt":
        if cli.lower() in {"mmdc", "mmdc.exe"}:
            cli = "mmdc.cmd"
        else:
            cli_path = Path(cli)
            if cli_path.name.lower() == "mmdc" and cli_path.suffix == "":
                cli = str(cli_path.with_suffix(".cmd"))

    resolved = shutil.which(cli)
    if resolved:
        return resolved

    raise SystemExit(
        "Mermaid CLI not found. Install @mermaid-js/mermaid-cli or pass --no-mermaid."
    )


def render_mermaid(markdown: str, mermaid_cli: str, puppeteer_config: Path) -> str:
    pattern = re.compile(r"```mermaid\s*(.*?)```", re.S)
    MERMAID_DIR.mkdir(parents=True, exist_ok=True)
    counter = 0

    def repl(match: re.Match[str]) -> str:
        nonlocal counter
        counter += 1

        code = match.group(1).strip() + "\n"
        mmd_path = MERMAID_DIR / f"diagram_{counter}.mmd"
        png_path = MERMAID_DIR / f"diagram_{counter}.png"
        mmd_path.write_text(code, encoding="utf-8")

        cmd = [mermaid_cli, "-i", str(mmd_path), "-o", str(png_path)]
        if puppeteer_config.is_file():
            cmd.extend(["-p", str(puppeteer_config)])

        subprocess.run(cmd, check=True)
        return f"![]({(Path('mermaid') / png_path.name).as_posix()})"

    return pattern.sub(repl, markdown)


def build_pdf(input_md: Path, output_pdf: Path, paper_size: str) -> None:
    if not shutil.which("pandoc"):
        raise SystemExit("Pandoc is required to build PDF.")

    cmd = [
        "pandoc",
        str(input_md),
        "-o",
        str(output_pdf),
        "--from=gfm",
        "--pdf-engine=xelatex",
        "--toc",
        "--toc-depth=3",
        "-V",
        f"papersize={paper_size}",
        "-V",
        "geometry:left=0.8in,right=0.8in,top=0.9in,bottom=0.9in",
    ]
    subprocess.run(cmd, check=True)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--output", default="runner-documentation.pdf")
    parser.add_argument("--order-file", default="docs/book-order.txt")
    parser.add_argument("--paper-size", default="letter")
    parser.add_argument("--no-mermaid", action="store_true")
    parser.add_argument("--markdown-only", action="store_true")
    args = parser.parse_args()

    BUILD_DIR.mkdir(parents=True, exist_ok=True)

    order_path = (ROOT / args.order_file).resolve()
    if order_path.is_file():
        files = read_order_file(order_path)
    else:
        files = collect_markdown_files()

    if not files:
        raise SystemExit("No markdown files found for documentation build.")

    version = load_version()
    markdown = combine_markdown(files, version)

    if not args.no_mermaid:
        markdown = render_mermaid(
            markdown,
            resolve_mermaid_cli(),
            ROOT / "scripts" / "puppeteer.json",
        )

    book_md = BUILD_DIR / "book.md"
    book_md.write_text(markdown, encoding="utf-8", newline="\n")

    if args.markdown_only:
        return

    output_pdf = BUILD_DIR / args.output
    build_pdf(book_md, output_pdf, args.paper_size)


if __name__ == "__main__":
    main()
