#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."

mode="${1:-smoke}"
case "$mode" in
    smoke|run) ;;
    *) echo "Usage: $0 [smoke|run]; run requires QUALIFY_FULL=1 and an idle host" >&2; exit 2 ;;
esac
if [[ "$mode" == run && "${QUALIFY_FULL:-}" != 1 ]]; then
    echo "Full qualification requires QUALIFY_FULL=1; coordinate an idle host first." >&2
    exit 2
fi
[[ -f vendor/autoload.php && ! -L vendor && -f public/build/manifest.json ]] || {
    echo "Copy vendor (not a symlink), migrate/build with disposable SQLite first; see qualification docs." >&2
    exit 2
}
export QUALIFY_ROOT="$PWD" HOST_UID="$(id -u)" HOST_GID="$(id -g)"
export QUALIFY_ENV_FILE="$PWD/.scratch/qualification/runtime.env"
project="dream107-$(printf '%s' "$PWD" | sha256sum | cut -c1-12)"
compose=(docker compose --project-name "$project" --env-file "$QUALIFY_ENV_FILE" -f scripts/qualification/compose.yaml)
mkdir -p .scratch/qualification
chmod 700 .scratch/qualification
exec 9>.scratch/qualification/lock
flock -n 9 || { echo "This worktree already has a qualification run." >&2; exit 2; }
if [[ -n "$(docker ps -aq --filter "label=com.docker.compose.project=$project")" ]] ||
    [[ -n "$(docker volume ls -q --filter "label=com.docker.compose.project=$project")" ]] ||
    [[ -n "$(docker network ls -q --filter "label=com.docker.compose.project=$project")" ]]; then
    echo "Existing resources for $project; inspect and remove only that project's resources before retrying." >&2
    exit 2
fi
umask 077
cat >"$QUALIFY_ENV_FILE" <<EOF
APP_ENV=production
APP_DEBUG=false
APP_KEY=base64:$(openssl rand -base64 32)
APP_URL=http://localhost
DB_CONNECTION=mysql
DB_URL=
DB_HOST=mysql
DB_PORT=3306
DB_DATABASE=ticket107_qualification
DB_USERNAME=qualification
DB_PASSWORD=$(openssl rand -hex 24)
QUALIFY_ROOT_PASSWORD=$(openssl rand -hex 24)
QUALIFY_DISPOSABLE=1
SESSION_DRIVER=database
SESSION_LIFETIME=120
CACHE_STORE=database
QUEUE_CONNECTION=sync
MAIL_MAILER=log
LOG_CHANNEL=stderr
LOG_LEVEL=error
APP_CONFIG_CACHE=/app/.scratch/qualification/config.php
APP_ROUTES_CACHE=/app/.scratch/qualification/routes.php
EOF
unset DB_PASSWORD QUALIFY_ROOT_PASSWORD
cleanup() {
    result=$?
    trap - EXIT
    if [[ "$result" != 0 ]]; then
        "${compose[@]}" logs --no-color --tail 100 >.scratch/qualification/container-errors.log 2>&1 || true
    fi
    if ! "${compose[@]}" down --volumes --remove-orphans >&2; then
        echo "Cleanup failed for $project; retain runtime.env for targeted recovery." >&2
        exit 1
    fi
    rm -f "$QUALIFY_ENV_FILE" .scratch/qualification/{config.php,routes.php} .scratch/qualification-sessions.json
    exit "$result"
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
"${compose[@]}" build >&2
"${compose[@]}" up -d --wait --wait-timeout 200 >&2
cli() { "${compose[@]}" exec -T --user "$HOST_UID:$HOST_GID" php "$@"; }
cli php artisan migrate --force --no-interaction >&2
cli php artisan config:cache >&2
cli php artisan route:cache >&2
organizations=100
[[ "$mode" == smoke ]] && organizations=2
cli env QUALIFY_ORGANIZATIONS="$organizations" php -d memory_limit=1G scripts/qualify-group-reports.php seed >.scratch/qualification/seed.json
"${compose[@]}" exec -T mysql sh -c 'MYSQL_PWD="$MYSQL_PASSWORD" mysql -uqualification ticket107_qualification -e "ANALYZE TABLE postings, folios, reservations, reservation_price_lines, inventory_records, housekeeping_room_statuses, rooms, properties, sessions"' >&2
port="$("${compose[@]}" port nginx 80)"
[[ "$port" =~ ^127\.0\.0\.1:[1-9][0-9]*$ ]] || { echo "Missing loopback HTTP publication" >&2; exit 1; }
url="http://$port"
for attempt in {1..60}; do
    if curl --fail --silent --max-time 2 "$url/up" >/dev/null; then break; fi
    [[ "$attempt" != 60 ]] || { echo "HTTP readiness failed" >&2; exit 1; }
    sleep 1
done
{
    git rev-parse HEAD
    "${compose[@]}" images
    cli php --version
    cli php -r 'echo "OPcache enabled: ".ini_get("opcache.enable").PHP_EOL;'
    "${compose[@]}" exec -T nginx nginx -v
    "${compose[@]}" exec -T mysql mysql --version
    docker info --format 'Docker CPUs={{.NCPU}} memory={{.MemTotal}}'
} >.scratch/qualification/stack.txt 2>&1
cli php scripts/qualify-group-reports.php http http://nginx 32 32 >.scratch/qualification/burst.json
if [[ "$mode" == smoke ]]; then
    cli php scripts/qualify-group-reports.php steady http://nginx 2 20 >.scratch/qualification/steady.json
else
    cli php scripts/qualify-group-reports.php steady http://nginx 300 1000 >.scratch/qualification/steady.json
fi
cat .scratch/qualification/{burst,steady}.json
