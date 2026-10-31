#!/usr/bin/env bash
# Prepare a fresh worktree to run checks: .env, dependencies, migrated scratch SQLite, built assets.
# Usage: scripts/worktree-setup.sh [main-checkout]   (run from the worktree root)
# Copies vendor/ and node_modules/ from the main checkout when present (a symlinked vendor/
# autoloads the main checkout's app/), then installs to match this worktree's lockfiles.
# Never reads the main checkout's .env or touches the shared dev database.
set -euo pipefail

main="${1:-$(git worktree list --porcelain | sed -n '1s/^worktree //p')}"
[ "$main" != "$PWD" ] || { echo "Run this inside a worktree, not the main checkout." >&2; exit 1; }

[ -f .env ] || { cp .env.example .env && php artisan key:generate --ansi -q; }

for dir in vendor node_modules; do
    [ -d "$dir" ] || [ ! -d "$main/$dir" ] || cp -a "$main/$dir" "$dir"
done
composer install --no-interaction --quiet
bun install --frozen-lockfile >/dev/null

# Wayfinder reads the live schema during the build, so build against a disposable SQLite file.
mkdir -p .scratch && touch .scratch/build.sqlite
export DB_CONNECTION=sqlite DB_DATABASE="$PWD/.scratch/build.sqlite"
php artisan migrate --force --no-interaction -q
bun run build >/dev/null
echo "Worktree ready: run composer check:fast."
