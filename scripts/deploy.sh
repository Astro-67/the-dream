#!/usr/bin/env bash
# Runs on the test server. .github/workflows/deploy.yml uploads the built
# assets to public/build.new, copies this script to the server, and runs it.
set -euo pipefail

sha=${1:?usage: deploy.sh <commit-sha> <app-dir>}
cd "${2:?usage: deploy.sh <commit-sha> <app-dir>}"

php artisan down --retry=15 || true
trap 'php artisan up' EXIT

git fetch --quiet origin
git checkout --quiet --force "$sha"
composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader --quiet

rm -rf public/build.old
if [ -d public/build ]; then mv public/build public/build.old; fi
mv public/build.new public/build

php artisan migrate --force
php artisan organizations:sync-roles
php artisan optimize
php artisan queue:restart

echo "Deployed $(git rev-parse --short HEAD)"
