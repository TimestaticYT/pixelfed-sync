#!/usr/bin/env bash
# Called by cron:
#   * * * * *     run.sh watch      live watcher: reacts to the web log within ~1 s
#                                   (starts it if it isn't running, else exits)
#   * * * * *     run.sh            periodic work four times a minute; also handles
#                                   events itself if the watcher isn't running
#   */30 * * * *  run.sh trending   trending posts (slow, so it has its own lock)
#   17 * * * *    run.sh index      account directory crawl (runs once a day)
#
# Events come from the web container's access log (successful requests only);
# the web UI and apps request:
#   open a profile     GET /api/(pixelfed/)v1/accounts/<id>
#   scroll a profile   GET /api/(pixelfed/)v1/accounts/<id>/statuses?...cursor=|max_id=
#   open a post        GET /api/(pixelfed/)v1/statuses/<id>[/context]
#   comments/replies   GET /api/v2/statuses/<post or comment id>/replies
#   search             GET /api/v2/search?q=...
set -euo pipefail

DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# Optional settings, see README: PF_SYNC_WEB_CONTAINER, PF_SYNC_APP_ROOT, PF_SYNC_*
[ -f "$DIR/pf-sync.env" ] && . "$DIR/pf-sync.env"
STATE="$DIR/state"
LOG="$DIR/logs/sync.log"
MODE="${1:-tick}"
MAX_JOBS="${PF_SYNC_WATCH_JOBS:-4}"
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

# Prints the event for one access log line (open:ID, scroll:ID, post:ID,
# search:QUERY), or nothing.
line_event() {
    local re='"GET (/api/[^ ]+) HTTP/[0-9.]+" 200 '
    [[ $1 =~ $re ]] || return 0
    local path=${BASH_REMATCH[1]}
    if [[ $path =~ ^/api/(pixelfed/)?v1/accounts/([0-9]+)$ ]]; then
        echo "open:${BASH_REMATCH[2]}"
    elif [[ $path =~ ^/api/(pixelfed/)?v1/accounts/([0-9]+)/statuses\?.*(cursor|max_id)= ]]; then
        echo "scroll:${BASH_REMATCH[2]}"
    elif [[ $path =~ ^/api/(pixelfed/)?v1/statuses/([0-9]+)(/context)?$ ]]; then
        echo "post:${BASH_REMATCH[2]}"
    elif [[ $path =~ ^/api/v2/statuses/([0-9]+)/replies ]]; then
        echo "post:${BASH_REMATCH[1]}"
    elif [[ $path =~ ^/api/v2/search\?(.*\&)?q=([^\&]+) ]]; then
        echo "search:${BASH_REMATCH[2]}"
    fi
}

docker inspect -f '{{.State.Running}}' "$WEB" 2>/dev/null | grep -q true || exit 0

case "$MODE" in
trending | index)
    pf_sync "$MODE"
    exit 0
    ;;
watch)
    # Each event runs in its own process (at most MAX_JOBS at once), so what
    # someone is looking at never waits for other work. Searches wait for 2 s
    # of quiet so typing "x", "xk", "xkc", "xkcd" becomes one lookup.
    echo "$(date -u +%FT%TZ) watcher started for $WEB"
    run_event() {
        while [ "$(jobs -rp | wc -l)" -ge "$MAX_JOBS" ]; do
            wait -n || true
        done
        pf_sync events "$1" &
    }
    search="" search_at=0
    while IFS= read -r -t 1 line || [ $? -gt 128 ]; do
        if [ -n "${line:-}" ]; then
            event=$(line_event "$line")
            case "$event" in
            "") ;;
            search:*) search="$event" search_at=$SECONDS ;;
            *) run_event "$event" ;;
            esac
            line=""
        fi
        if [ -n "$search" ] && [ $((SECONDS - search_at)) -ge 2 ]; then
            run_event "$search"
            search=""
        fi
    done < <(docker logs -f --since 0s "$WEB" 2>&1)
    wait
    echo "$(date -u +%FT%TZ) watcher stopped (container restarted?), cron restarts it"
    exit 0
    ;;
esac

# tick: if the watcher runs, it handles events and this only does periodic work.
watching=false
flock -n "$STATE/watch.lock" true || watching=true

for i in 1 2 3 4; do
    start=$(date +%s)
    now=$(date -u +%Y-%m-%dT%H:%M:%SZ)
    since=$(cat "$STATE/log-since" 2>/dev/null || date -u -d '-1 min' +%Y-%m-%dT%H:%M:%SZ)
    echo "$now" > "$STATE/log-since"

    events=()
    if [ "$watching" = false ]; then
        while IFS= read -r line; do
            event=$(line_event "$line")
            [ -n "$event" ] && events+=("$event")
        done < <(docker logs --since "$since" --until "$now" "$WEB" 2>&1)
        mapfile -t events < <(printf '%s\n' "${events[@]}" | sort -u | grep -v '^$' || true)
    fi

    pf_sync tick "${events[@]}"

    if [ "$i" -lt 4 ]; then
        left=$(( 15 - ($(date +%s) - start) ))
        [ "$left" -gt 0 ] && sleep "$left"
    fi
done
