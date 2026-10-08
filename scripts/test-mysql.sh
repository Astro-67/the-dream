#!/usr/bin/env bash
# Run Pest against a throwaway MySQL 8 container, the database CI uses.
# SQLite (phpunit.xml) hides MySQL-only behaviour such as JSON key order.
# Never touches the dev database in .env. Extra args go to Pest.
set -euo pipefail

name="the-dream-test-mysql-$$"

# Docker picks a free host port unless TEST_MYSQL_PORT is set, so parallel worktrees don't collide.
docker run -d --rm --name "$name" -p "127.0.0.1:${TEST_MYSQL_PORT:-}:3306" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=testing mysql:8.0 >/dev/null
trap 'docker stop "$name" >/dev/null' EXIT
port="$(docker port "$name" 3306/tcp | head -n1 | sed 's/.*://')"

until docker exec "$name" mysqladmin ping -h 127.0.0.1 -uroot -proot --silent 2>/dev/null; do sleep 1; done

export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$port" DB_DATABASE=testing \
    DB_USERNAME=root DB_PASSWORD=root DB_URL=

# Migrate once up front: a broken migration fails here in seconds instead of
# every test retrying migrate:fresh, which looks like a hang.
php artisan migrate --force --env=testing --no-interaction
vendor/bin/pest "$@"
