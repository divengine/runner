#!/usr/bin/env bash
# Installs divengine/runner into a clean temporary Composer project and runs
# examples/order against the installed package, comparing its output, both
# through the PHP API (examples/order/run.php) and through the packaged
# command-line bootstrap (vendor/divengine/runner/bin/run.php).
#
# Usage:
#   scripts/smoke_install.sh               # package built from `git archive HEAD`
#                                          # (dist contents: export-ignore applied;
#                                          # uncommitted changes are not included)
#   scripts/smoke_install.sh packagist     # composer require divengine/runner:^0.2
#   scripts/smoke_install.sh packagist '^0.1'
set -euo pipefail

repo_root="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
mode="${1:-local}"
constraint="${2:-^0.2}"

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
    test -f "$package/bin/run.php"
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

# Command line: pause, then resume from the saved context in a new process.
# The bootstrap ships since 0.2.0, so a missing bin/run.php fails the test. The
# only exception is an explicitly requested legacy release (packagist '^0.1').
if [[ ! -f "$project/vendor/divengine/runner/bin/run.php" ]]; then
    installed="$(composer --working-dir="$project" show divengine/runner --format=json \
        | php -r 'echo ltrim(json_decode(stream_get_contents(STDIN), true)["versions"][0] ?? "", "v");')"
    if [[ "$mode" == "packagist" && -n "${2:-}" ]] \
        && php -r 'exit(preg_match("/^\d+\.\d+\.\d+$/", $argv[1]) && version_compare($argv[1], "0.2.0", "<") ? 0 : 1);' "$installed"; then
        echo "cli: skipped, legacy release $installed has no bin/run.php"
        echo "smoke install OK ($mode)"
        exit 0
    fi
    echo "installed divengine/runner ${installed:-?} has no bin/run.php" >&2
    exit 1
fi
cli=(php vendor/divengine/runner/bin/run.php flows/order.yml)
cd "$project/example"
ln -s ../vendor vendor
echo '{"items": [{"price": 10, "qty": 2}, {"price": 5, "qty": 1}]}' > input.json

set +e
first="$("${cli[@]}" --context input.json --root functions --save-context state.json)"; first_exit=$?
second="$("${cli[@]}" --context state.json --save-context state.json)"; second_exit=$?
set -e

receipt="$(php -r 'echo json_decode(file_get_contents("state.json"), true)["receipt"];')"
if [[ "$first/$first_exit $second/$second_exit $receipt" != "paused/3 done/0 Total: 25.00 (reviewed)" ]]; then
    echo "unexpected CLI result: $first/$first_exit $second/$second_exit $receipt" >&2
    exit 1
fi
echo "cli: $first ($first_exit), $second ($second_exit), $receipt"
echo "smoke install OK ($mode)"
