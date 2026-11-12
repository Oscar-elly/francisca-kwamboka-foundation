#!/usr/bin/env bash
set -euo pipefail

deploy_path="${1:?Pass the cPanel Git checkout path}"
php_cli="${2:-php}"

if [[ ! -d "$deploy_path/.git" ]]; then
    echo "The configured deployment path is not a Git checkout." >&2
    exit 1
fi

branch="$(git -C "$deploy_path" branch --show-current)"
if [[ "$branch" != "main" ]]; then
    echo "The cPanel checkout must be on main (currently: ${branch:-detached})." >&2
    exit 1
fi

if [[ -n "$(git -C "$deploy_path" status --porcelain --untracked-files=no)" ]]; then
    echo "The cPanel checkout has tracked local changes; refusing to overwrite them." >&2
    exit 1
fi

if [[ ! -f "$deploy_path/config.php" ]]; then
    echo "Create config.php with the cPanel database credentials before deploying." >&2
    exit 1
fi

git -C "$deploy_path" pull --ff-only origin main
cd "$deploy_path"
"$php_cli" -v
"$php_cli" scripts/migrate.php
