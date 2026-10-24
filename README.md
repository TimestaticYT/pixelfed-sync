# pixelfed-sync

Makes a small or single-user [Pixelfed](https://pixelfed.org) server feel populated.

Out of the box, a new Pixelfed server:
- shows **no posts** on remote profiles, and only receives posts made *after* you follow someone
  ([#1745](https://github.com/pixelfed/pixelfed/issues/1745), [#5294](https://github.com/pixelfed/pixelfed/issues/5294); backfill is on the [roadmap](https://github.com/pixelfed/pixelfed/issues/6750) as "a seriously hard thing to resolve")
- shows **0 followers / 0 following / 0 posts** for every remote account, even though the remote server publishes the real numbers
- has an **empty global timeline** and no relay support ([#4472](https://github.com/pixelfed/pixelfed/issues/4472))

pf-sync fills those gaps without patching Pixelfed:

| Gap | What pf-sync does |
|---|---|
| Opening a remote profile shows no posts | Fetches its last 20 public media posts within ~15 s |
| Following someone shows nothing until they post | Same backfill on follow (also for pending requests), and adds the posts to your home feed |
| Federation sometimes misses posts | Re-syncs followed accounts every 6 h |
| Remote follower/following/post counts show 0 | Reads the counts the remote server publishes and keeps them applied |
| Empty global/network timeline | Pulls trending posts from pixelfed.social and mastodon.social every 30 min (sensitive posts skipped) |

Only **public** posts can be fetched: remote servers don't serve followers-only
posts to anyone who isn't an accepted follower.

## How it works

`pf-sync.php` runs inside the Pixelfed web container and uses Pixelfed's own
code (`Helpers::statusFetch`, signed ActivityPub fetches, the timeline
services). So storage, the network timeline and home feeds behave as if the
posts had federated normally. It's piped in on stdin, so nothing is mounted
or patched, and Pixelfed upgrades leave it alone.

Where an account's recent posts come from, in order:
1. Pixelfed's public profile API (`/api/pixelfed/v1/accounts/{id}/statuses`).
   Pixelfed's ActivityPub outbox is intentionally empty, so this is the only
   way to list a Pixelfed account's posts.
2. Mastodon's public account API (`only_media`, no replies, no boosts).
3. The ActivityPub outbox (other software).

`run.sh` is the cron entry point. It finds profiles someone *opened* in the
container's access log: the web UI and apps request
`GET /api/(pixelfed/)v1/accounts/<id>` when a profile is opened.

State (tracked profiles, cooldowns, cached counts) lives in Pixelfed's Redis
under `pfsync:*`, plus `state/` and `logs/` next to `run.sh`.

## Requirements

- Pixelfed running in Docker. Tested with
  [jippi/docker-pixelfed](https://github.com/jippi/docker-pixelfed) `v0.15.2-docker0`.
  The official compose setup should work too (the app root is detected), but
  that's untested, and opened-profile detection needs the web server's access
  log on the container's stdout.
- ActivityPub federation enabled (`ACTIVITY_PUB="true"` etc.).
- A host user that can run `docker` and has `cron`, `flock` and bash.

## Install

```bash
git clone https://github.com/TimestaticYT/pixelfed-sync.git ~/pixelfed-sync
crontab -e
```

```cron
* * * * *     $HOME/pixelfed-sync/run.sh
*/30 * * * *  $HOME/pixelfed-sync/run.sh trending
```

`run.sh` uses the one running container whose Compose service is `web` or
`pixelfed` and whose image name contains `pixelfed`. If that's ambiguous, or
your setup differs, create `pf-sync.env` next to `run.sh`:

```bash
PF_SYNC_WEB_CONTAINER="my-pixelfed-web"
# PF_SYNC_APP_ROOT="/var/www/html"   # only if auto-detection fails
```

Limits (posts per account, cooldowns) and trending sources are constants at
the top of `pf-sync.php`.

## Commands

```bash
cd ~/pixelfed-sync
WEB=my-pixelfed-web   # your web container
run() { docker exec -i -u www-data "$WEB" php -- "$@" < pf-sync.php; }

run status                        # tracked profiles, counts, last syncs
run backfill @user@example.social # backfill now (search the account in Pixelfed first)
run trending --force              # trending run now
tail -f logs/sync.log
```

## Uninstall

Remove the two crontab lines and `~/pixelfed-sync`. Posts that were fetched
stay; remote counts drift back to Pixelfed's own values.

## Caveats

- It calls internal Pixelfed classes, so a future Pixelfed release may need
  small adjustments. Errors show up in `logs/sync.log`.
- Backfilled posts get new local IDs, so in ID-ordered views they sort as if
  just received (within a batch the order stays chronological).
- Pixelfed itself only puts posts from the last 7 days into home feeds.
  pf-sync rebuilds a local follower's home feed after a backfill so older
  posts show up too.
- Trending sources are public, unauthenticated endpoints of other servers.
  Keep the interval reasonable.

## License

MIT
