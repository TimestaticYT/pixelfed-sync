#!/usr/bin/env bash
# Called by cron:
#   * * * * *     run.sh            four ticks 15 s apart, so an opened profile
#                                   is backfilled within ~15 s
#   */30 * * * *  run.sh trending   trending posts (slow, so it has its own lock)
#
# Profiles "opened" in the web UI or an app are taken from the web container's
# access log: both request GET /api/(pixelfed/)v1/accounts/<id> for a profile.
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Optional settings: PF_SYNC_WEB_CONTAINER, PF_SYNC_APP_ROOT
[ -f "$DIR/pf-sync.env" ] && . "$DIR/pf-sync.env"
STATE="$DIR/state"
LOG="$DIR/logs/sync.log"
MODE="${1:-tick}"
mkdir -p "$STATE" "$DIR/logs"

exec 9>"$STATE/$MODE.lock"
flock -n 9 || exit 0

if [ -f "$LOG" ] && [ "$(stat -c %s "$LOG")" -gt 5000000 ]; then
    mv -f "$LOG" "$LOG.1"
fi
exec >>"$LOG" 2>&1

# Without PF_SYNC_WEB_CONTAINER, use the one running compose service named
# "web" (jippi/docker-pixelfed) or "pixelfed" (official compose file).
WEB="${PF_SYNC_WEB_CONTAINER:-}"
if [ -z "$WEB" ]; then
    mapfile -t found < <(docker ps --format '{{.Names}}\t{{.Image}}\t{{.Label "com.docker.compose.service"}}' \
        | awk -F '\t' '($3 == "web" || $3 == "pixelfed") && tolower($2) ~ /pixelfed/ {print $1}')
    if [ "${#found[@]}" -ne 1 ]; then
        echo "$(date -u +%FT%TZ) found ${#found[@]} Pixelfed web containers (${found[*]:-none}), set PF_SYNC_WEB_CONTAINER in pf-sync.env"
        exit 1
    fi
    WEB="${found[0]}"
fi

pf_sync() {
    docker exec -i -u www-data ${PF_SYNC_APP_ROOT:+-e PF_SYNC_APP_ROOT="$PF_SYNC_APP_ROOT"} \
        "$WEB" php -- "$@" < "$DIR/pf-sync.php" \
        | grep -v -e DEPRECATED -e '^\s*$' || true
}

docker inspect -f '{{.State.Running}}' "$WEB" 2>/dev/null | grep -q true || exit 0

if [ "$MODE" = trending ]; then
    pf_sync trending
    exit 0
fi

for i in 1 2 3 4; do
    start=$(date +%s)
    now=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    since=$(cat "$STATE/log-since" 2>/dev/null || date -u -d '-1 min' +%Y-%m-%dT%H:%M:%SZ)

    mapfile -t opened < <(docker logs --since "$since" --until "$now" "$WEB" 2>&1 \
        | grep -oE '"GET /api/(pixelfed/)?v1/accounts/[0-9]+ HTTP' \
        | grep -oE '[0-9]{6,}' | sort -u)
    echo "$now" > "$STATE/log-since"

    pf_sync tick "${opened[@]}"

    if [ "$i" -lt 4 ]; then
        left=$(( 15 - ($(date +%s) - start) ))
        [ "$left" -gt 0 ] && sleep "$left"
    fi
done
