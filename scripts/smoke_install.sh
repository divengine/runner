#!/usr/bin/env bash
# Installs divengine/runner into a clean temporary Composer project and runs
# examples/order against the installed package, comparing its output.
#
# Usage:
#   scripts/smoke_install.sh               # package built from `git archive HEAD`
#                                          # (dist contents: export-ignore applied;
#                                          # uncommitted changes are not included)
#   scripts/smoke_install.sh packagist     # composer require divengine/runner:^0.1
#   scripts/smoke_install.sh packagist '^0.2'
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mode="${1:-local}"
constraint="${2:-^0.1}"

work="$(mktemp -d)"
trap 'rm -rf "$work"' EXIT
project="$work/project"
mkdir -p "$project"

if [[ "$mode" == "local" ]]; then
    package="$work/package"
    mkdir -p "$package"
    git -C "$repo_root" archive --format=tar HEAD | tar -x -C "$package"

    # The distributed package must contain the runtime and no development files.
    test -f "$package/src/runner.php"
    test -f "$package/LICENSE"
    for excluded in tests docs .github bench scripts examples phpstan.neon phpunit.xml; do
        if [[ -e "$package/$excluded" ]]; then
            echo "dist contains development path: $excluded" >&2
            exit 1
        fi
    done

    cat > "$project/composer.json" <<JSON
{
    "name": "smoke/runner-install",
    "type": "project",
    "repositories": [
        {
            "type": "path",
            "url": "$package",
            "options": {"symlink": false, "versions": {"divengine/runner": "0.0.1"}}
        }
    ],
    "require": {}
}
JSON
    composer --working-dir="$project" require --no-interaction --no-progress "divengine/runner:0.0.1"
elif [[ "$mode" == "packagist" ]]; then
    # No custom repositories: resolved from Packagist only.
    echo '{"name": "smoke/runner-install", "type": "project", "require": {}}' > "$project/composer.json"
    composer --working-dir="$project" require --no-interaction --no-progress "divengine/runner:$constraint"
    composer --working-dir="$project" show divengine/runner | grep -E '^(name|versions|source|dist) '
else
    echo "unknown mode: $mode (expected local or packagist)" >&2
    exit 2
fi

cp -R "$repo_root/examples/order" "$project/example"
actual="$(RUNNER_AUTOLOAD="$project/vendor/autoload.php" php "$project/example/run.php")"
expected="$(cat "$repo_root/examples/order/expected-output.txt")"

if [[ "$actual" != "$expected" ]]; then
    echo "unexpected output:" >&2
    echo "$actual" >&2
    exit 1
fi

echo "$actual"
echo "smoke install OK ($mode)"
