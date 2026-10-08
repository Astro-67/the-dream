#!/usr/bin/env bash
# Run Pest against a throwaway MySQL 8 container, the database CI uses.
# SQLite (phpunit.xml) hides MySQL-only behaviour such as JSON key order.
# Never touches the dev database in .env. Extra args go to Pest.
set -euo pipefail

name="the-dream-test-mysql-$$"
port="${TEST_MYSQL_PORT:-33061}"

docker run -d --rm --name "$name" -p "127.0.0.1:${port}:3306" \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=testing mysql:8.0 >/dev/null
trap 'docker stop "$name" >/dev/null' EXIT

until docker exec "$name" mysqladmin ping -h 127.0.0.1 -uroot -proot --silent 2>/dev/null; do sleep 1; done

DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$port" DB_DATABASE=testing \
DB_USERNAME=root DB_PASSWORD=root DB_URL= \
    vendor/bin/pest "$@"
