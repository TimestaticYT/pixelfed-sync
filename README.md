# pixelfed-sync

Makes a small or single-user [Pixelfed](https://pixelfed.org) server feel populated.

Out of the box, a new Pixelfed server:
- shows **no posts** on remote profiles, and only receives posts made *after* you follow someone
  ([#1745](https://github.com/pixelfed/pixelfed/issues/1745), [#5294](https://github.com/pixelfed/pixelfed/issues/5294); backfill is on the [roadmap](https://github.com/pixelfed/pixelfed/issues/6750) as "a seriously hard thing to resolve")
- shows **0 followers / 0 following / 0 posts** for every remote account, and near-zero likes and comments on remote posts
- has an **empty global timeline** and no relay support ([#4472](https://github.com/pixelfed/pixelfed/issues/4472))
- only **finds remote accounts in search** if you already know their full handle

pf-sync fills those gaps without patching Pixelfed:

| You… | pf-sync… |
|---|---|
| open a remote profile | fetches its newest 20 public media posts and its real follower/following/post counts (~15 s) |
| scroll to the end of it | loads the next 20 older posts, continuing on the remote server where the last batch ended |
| follow someone | does the same backfill and adds the posts to your home feed; re-syncs followed accounts every 6 h |
| open a remote post | fetches its comments and replies to comments (commenters' avatars follow automatically) and the real like/repost/comment counts |
| open "Liked by" / "Shared by" | imports who liked and shared it. Mastodon publishes both lists; Pixelfed only reveals one liker, so on Pixelfed-hosted posts the rest stay unknown |
| search for a name, e.g. `xkcd` | looks it up on mastodon.social and imports the most followed accounts whose handle starts with it; search again a few seconds later and they're listed, most followed first |
| open the global timeline | finds trending posts from 7 Pixelfed servers with at least 10 likes, pulled in every 30 min (sensitive ones skipped). Backfilled posts stay out of it |
| open Discover → Popular accounts | finds popular accounts from a daily crawl of public account directories |

Only **public** content can be fetched: remote servers don't serve
followers-only posts to anyone who isn't an accepted follower. The daily
account index only takes accounts that opted into being discoverable and
aren't marked `noindex`. An explicit search imports what the remote server's
own search returns for that name.

## How it works

`pf-sync.php` runs inside the Pixelfed web container and uses Pixelfed's own
code (`Helpers::statusFetch`/`profileFetch`, signed ActivityPub fetches, the
timeline services). So storage, avatars, the network timeline and home feeds
behave as if everything had federated normally. It's piped in on stdin, so
nothing is mounted or patched, and Pixelfed upgrades leave it alone.

`run.sh watch` streams the container's access log and reacts to what people
do within about a second, using the requests the web UI and apps make anyway.
Each event runs in its own process (4 at a time), so what someone is looking at
never waits for background work. Searches wait for 2 s of typing quiet. Only
successful (HTTP 200) requests count, so logged-out visitors can't trigger
fetches. Remote posts, comments and accounts are fetched by 4 parallel workers,
so a slow or dead remote server (Pixelfed waits up to 15 s) only holds up its
own worker. If the watcher isn't running, the per-minute cron job reads the
log every 15 s instead.

| Event | Request |
|---|---|
| profile opened | `GET /api/(pixelfed/)v1/accounts/<id>` |
| profile scrolled | `GET /api/(pixelfed/)v1/accounts/<id>/statuses?…cursor=…` (or `max_id=`) |
| post opened | `GET /api/(pixelfed/)v1/statuses/<id>[/context]` |
| comments / replies opened, comment refresh | `GET /api/v2/statuses/<post or comment id>/replies` |
| likes/shares list opened | `GET /api/v1/statuses/<id>/favourited_by` or `/reblogged_by` |
| search | `GET /api/v2/search?q=…` |

Where remote data comes from:
- **Posts:** Pixelfed's public profile API (`/api/pixelfed/v1/accounts/{id}/statuses`).
  Pixelfed's ActivityPub outbox is intentionally empty, so this is the only way
  to list a Pixelfed account's posts. Then Mastodon's public account API
  (`only_media`, no replies, no boosts), then the ActivityPub outbox.
- **Comments:** Mastodon's `/api/v1/statuses/{id}/context` (whole thread), or
  Pixelfed's public `/api/v2/comments/{account}/status/{id}`, including replies
  to comments (2 levels deep). Pixelfed lists comments that came from other
  servers under its own URL, which can't be fetched. pf-sync finds the original
  on the author's server by timestamp if that server has a Mastodon API.
  Otherwise (Pixelfed hides comments from its public listing) it stores the
  comment from the data given, the way Pixelfed stores remote text comments.
  After each batch, pf-sync clears Pixelfed's replies cache (1 h, and the refresh
  button only clears it every 5 min), so new comments show on the next load.
- **Likers/sharers:** Mastodon's `/api/v1/statuses/{id}/favourited_by` and `/reblogged_by`.
  They are stored exactly as Pixelfed stores likes and shares it receives from
  other servers, without notifications or count changes.
- **Trending:** Pixelfed's public `/api/pixelfed/v2/discover/posts/trending`.
- **Counts:** the remote APIs, or the actor's ActivityPub collections for profiles.

### How remote and local numbers combine

Your likes, reposts, comments and follows are federated as usual. The other
server receives them and counts them, so they show up there too. pf-sync shows:

> **remote count + what users on this server did since that count was fetched**

At every fetch it stores the remote numbers together with this server's own
likes/reposts/comments (or follows) at that moment. Afterwards it adds the
difference. So your like counts immediately, and an unlike or unfollow takes it
away immediately. Once the next fetch already includes your action, the
difference is 0 again, so nothing is counted twice. Actions from the last 30 s
before a fetch count as local, because they may not have arrived remotely yet.

Pixelfed itself adds +1/−1 for local likes, reposts and comments, but on some
unfollows it resets a remote account to "followers on this server only", and
it periodically resets post counts. pf-sync re-applies the combined numbers
for tracked accounts and recently opened posts.
- **Accounts:** Mastodon's public `/api/v1/directory` and `/api/v2/search`.
  Pixelfed's own search and Popular accounts both rank by follower count, so
  the real counts put popular accounts first.

State (tracked profiles, scroll positions, cooldowns, cached counts) lives in
Pixelfed's Redis under `pfsync:*`, plus `state/` and `logs/` next to `run.sh`.

## Requirements

- Pixelfed running in Docker. Tested with
  [jippi/docker-pixelfed](https://github.com/jippi/docker-pixelfed) `v0.15.2-docker0`.
  The official compose setup should work too (the app root is detected), but
  that's untested, and event detection needs the web server's access log on
  the container's stdout.
- ActivityPub federation enabled (`ACTIVITY_PUB="true"` etc.).
- A host user that can run `docker` and has `cron`, `flock` and bash.

## Install

```bash
git clone https://github.com/TimestaticYT/pixelfed-sync.git ~/pixelfed-sync
crontab -e
```

```cron
* * * * *     $HOME/pixelfed-sync/run.sh watch
* * * * *     $HOME/pixelfed-sync/run.sh
*/30 * * * *  $HOME/pixelfed-sync/run.sh trending
17 * * * *    $HOME/pixelfed-sync/run.sh index
```

`watch` keeps running; the per-minute entry only (re)starts it if it isn't
running, e.g. after a container restart. `index` only crawls once per
`PF_SYNC_INDEX_INTERVAL_HOURS`; the hourly entry just retries a missed run.

## Configuration

Copy `pf-sync.env.example` to `pf-sync.env` and uncomment what you want to
change. Every feature can be switched off and its limits adjusted. Main settings:

| Setting | Default | |
|---|---|---|
| `PF_SYNC_WEB_CONTAINER` | auto | the one running compose service `web`/`pixelfed` with a `pixelfed` image |
| `PF_SYNC_BACKFILL_LIMIT` | `20` | posts fetched when a profile is opened or followed |
| `PF_SYNC_SCROLL_BACKFILL` / `_BATCH` / `_MAX_POSTS` | `true` / `20` / `240` | older posts on scroll |
| `PF_SYNC_POST_DETAILS` / `_REPLIES_MAX` / `_COOLDOWN_MINUTES` | `true` / `40` / `2` | comments + counts when a post is opened; reopening after the cooldown checks for new comments |
| `PF_SYNC_PARALLEL` / `PF_SYNC_WATCH_JOBS` | `4` / `4` | parallel fetch workers / events handled at once |
| `PF_SYNC_TRENDING` / `_SOURCES` / `_MIN_LIKES` | `true` / 7 Pixelfed servers / `10` | global timeline |
| `PF_SYNC_BACKFILL_IN_GLOBAL` | `false` | also show backfilled posts in the global timeline |
| `PF_SYNC_LIKERS` / `_MAX` | `true` / `40` | "Liked by" / "Shared by" lists |
| `PF_SYNC_INDEX` / `_MIN_FOLLOWERS` / `_SOURCES` | `true` / `1000` / 5 large servers | account index |
| `PF_SYNC_SEARCH_LOOKUP` / `_SERVERS` | `true` / mastodon.social | search lookup |

**Privacy:** search lookup sends what users type into the search bar (3+
characters, no full handles) to `PF_SYNC_SEARCH_SERVERS`. Set
`PF_SYNC_SEARCH_LOOKUP=false` to keep searches local; the directory index still
works without it.

## Commands

```bash
cd ~/pixelfed-sync
WEB=my-pixelfed-web   # your web container
run() { docker exec -i -u www-data "$WEB" php -- "$@" < pf-sync.php; }

run status                        # settings, tracked profiles, counts, last runs
run backfill @user@example.social # backfill now (search the account in Pixelfed first)
run post <local post id>          # fetch comments and counts for a post now
run search xkcd                   # search lookup now
run trending --force              # trending run now
run likers <local post id>        # import who liked/shared a post now
run clean-global                  # one-off: drop non-trending posts from the global timeline
run index --force                 # directory crawl now
tail -f logs/sync.log
```

## Uninstall

Remove the crontab lines and `~/pixelfed-sync`. Fetched posts and accounts
stay; counts drift back to Pixelfed's own values.

## Caveats

- It calls internal Pixelfed classes, so a future Pixelfed release may need
  small adjustments. Errors show up in `logs/sync.log`.
- Things appear on the *next* load: Pixelfed's web UI doesn't refresh by
  itself. Comments arrive in batches of 8 about every 2 s (40 comments took
  ~11 s in testing), so reload the post after a few seconds.
- New comments on remote posts aren't pushed to a small server: Pixelfed
  delivers a comment to the commenter's followers, mentioned accounts and the
  post's author, but doesn't forward it to the author's followers. Large
  servers get them because nearly every commenter has a follower there.
  pf-sync checks again when the post is reopened after the cooldown.
- Comments by private Pixelfed accounts are listed by the remote server but
  refused to non-followers, so they're skipped.
- Backfilled posts get new local IDs, so ID-ordered views (home, global) sort
  them as if just received. Profiles sort by date and aren't affected.
- Pixelfed itself only puts posts from the last 7 days into home feeds.
  pf-sync rebuilds a local follower's home feed after a backfill so older
  posts show up too.
- All remote endpoints used are public and unauthenticated. Keep intervals
  and limits reasonable.

## License

MIT
