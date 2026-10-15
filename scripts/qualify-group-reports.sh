#!/usr/bin/env bash
# Group reports qualification (#107) against a throwaway MySQL 8 container. Never touches the dev database.
# Usage: scripts/qualify-group-reports.sh [http requests per level] [server workers] [concurrency levels...]
# Results go to stdout as JSON; seeding progress to stderr.
set -euo pipefail
cd "$(dirname "$0")/.."

requests="${1:-200}"
workers="${2:-8}"
shift $(( $# > 2 ? 2 : $# )) || true
levels=("${@:-1 8 32}")
name="the-dream-qualify-107-$$"
port_http="${QUALIFY_HTTP_PORT:-8107}"

docker run -d --rm --name "$name" -p "127.0.0.1::3306" --tmpfs /var/lib/mysql \
    -e MYSQL_ROOT_PASSWORD=root -e MYSQL_DATABASE=ticket107_qualification mysql:8.0 \
    --innodb-flush-log-at-trx-commit=0 --sync-binlog=0 --skip-log-bin --innodb-buffer-pool-size=1G >/dev/null
server=""
trap '[ -n "$server" ] && pkill -f "php.*-S 127.0.0.1:$port_http" 2>/dev/null; docker stop "$name" >/dev/null; rm -f .scratch/qualification-config.php .scratch/qualification-routes.php' EXIT
port="$(docker port "$name" 3306/tcp | head -n1 | sed 's/.*://')"
until docker exec "$name" mysql -uroot -proot -e 'select 1' ticket107_qualification >/dev/null 2>&1; do sleep 1; done

export DB_CONNECTION=mysql DB_HOST=127.0.0.1 DB_PORT="$port" DB_DATABASE=ticket107_qualification DB_USERNAME=root DB_PASSWORD=root DB_URL= \
    APP_ENV=production APP_DEBUG=false LOG_LEVEL=error SESSION_DRIVER=database CACHE_STORE=database QUEUE_CONNECTION=sync \
    APP_CONFIG_CACHE="$PWD/.scratch/qualification-config.php" APP_ROUTES_CACHE="$PWD/.scratch/qualification-routes.php"

php artisan migrate --force --no-interaction >/dev/null
php artisan config:cache >/dev/null
php artisan route:cache >/dev/null
php -d memory_limit=4G scripts/qualify-group-reports.php seed
# Bulk inserts leave optimizer statistics stale; production tables are analyzed by normal growth.
docker exec "$name" mysql -uroot -proot ticket107_qualification -e 'analyze table postings, folios, reservations, reservation_price_lines, inventory_records, housekeeping_room_statuses, rooms, properties, sessions' >/dev/null 2>&1
php -d memory_limit=2G scripts/qualify-group-reports.php query 3

# Laravel's router for the built-in server resolves public/ from the working directory.
(cd public && exec env PHP_CLI_SERVER_WORKERS="$workers" php -d opcache.enable_cli=1 -S "127.0.0.1:$port_http" \
    ../vendor/laravel/framework/src/Illuminate/Foundation/resources/server.php >/dev/null 2>&1) &
server=$!
until curl -s -o /dev/null "http://127.0.0.1:$port_http/up"; do sleep 0.5; done
curl -s -o /dev/null "http://127.0.0.1:$port_http/login"
php scripts/qualify-group-reports.php http "http://127.0.0.1:$port_http" "$requests" ${levels[@]}
