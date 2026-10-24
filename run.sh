#!/usr/bin/env bash
# Called by cron:
#   * * * * *     run.sh            four ticks 15 s apart, so whatever someone
#                                   just opened, scrolled or searched is handled
#                                   within ~15 s
#   */30 * * * *  run.sh trending   trending posts (slow, so it has its own lock)
#   17 * * * *    run.sh index      account directory crawl (runs once a day)
#
# Events come from the web container's access log; the web UI and apps request:
#   open a profile     GET /api/(pixelfed/)v1/accounts/<id>
#   scroll a profile   GET /api/(pixelfed/)v1/accounts/<id>/statuses?...cursor=|max_id=
#   open a post        GET /api/(pixelfed/)v1/statuses/<id>[/context]
#   liked/shared by    GET /api/v1/statuses/<id>/favourited_by|reblogged_by
#   search             GET /api/v2/search?q=...
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Optional settings, see README: PF_SYNC_WEB_CONTAINER, PF_SYNC_APP_ROOT, PF_SYNC_*
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

# Hand every PF_SYNC_* setting to the script inside the container.
envargs=()
for var in $(compgen -v PF_SYNC_); do
    envargs+=(-e "$var=${!var}")
done

pf_sync() {
    docker exec -i -u www-data "${envargs[@]}" "$WEB" php -- "$@" < "$DIR/pf-sync.php" \
        | grep -v -e DEPRECATED -e '^\s*$' || true
}

docker inspect -f '{{.State.Running}}' "$WEB" 2>/dev/null | grep -q true || exit 0

if [ "$MODE" = trending ] || [ "$MODE" = index ]; then
    pf_sync "$MODE"
    exit 0
fi

for i in 1 2 3 4; do
    start=$(date +%s)
    now=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    since=$(cat "$STATE/log-since" 2>/dev/null || date -u -d '-1 min' +%Y-%m-%dT%H:%M:%SZ)

    # Only successful requests: logged-out visitors get 401/403 on these.
    requests=$(docker logs --since "$since" --until "$now" "$WEB" 2>&1 \
        | grep -oE '"GET /api/[^ ]+ HTTP/[0-9.]+" 200' | sed -E 's/^"GET //; s/ HTTP.*$//' || true)
    echo "$now" > "$STATE/log-since"

    mapfile -t events < <(
        {
            grep -E '^/api/(pixelfed/)?v1/accounts/[0-9]+$' <<<"$requests" | sed -E 's#.*/##; s#^#open:#' || true
            grep -E '^/api/(pixelfed/)?v1/accounts/[0-9]+/statuses\?.*(cursor|max_id)=' <<<"$requests" \
                | sed -E 's#^.*/accounts/([0-9]+)/.*#scroll:\1#' || true
            grep -E '^/api/(pixelfed/)?v1/statuses/[0-9]+(/context)?$' <<<"$requests" \
                | sed -E 's#^.*/statuses/([0-9]+).*#post:\1#' || true
            grep -E '^/api/v1/statuses/[0-9]+/(favourited_by|reblogged_by)' <<<"$requests" \
                | sed -E 's#^.*/statuses/([0-9]+)/.*#likers:\1#' || true
            grep -E '^/api/v2/search\?' <<<"$requests" | grep -oE '[?&]q=[^&]+' | sed -E 's#^.q=#search:#' || true
        } | sort -u
    )

    pf_sync tick "${events[@]}"

    if [ "$i" -lt 4 ]; then
        left=$(( 15 - ($(date +%s) - start) ))
        [ "$left" -gt 0 ] && sleep "$left"
    fi
done
