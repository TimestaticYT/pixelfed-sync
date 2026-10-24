<?php

/**
 * pf-sync: fills the gaps Pixelfed leaves on small servers.
 *
 *   - backfills recent public media posts of remote accounts you open or follow
 *   - loads older posts when you scroll to the end of a remote profile
 *   - fetches comments and like/repost/comment counts when you open a remote post
 *   - shows the follower/following/post counts the remote server publishes
 *   - re-syncs followed accounts periodically to catch posts federation missed
 *   - pulls trending posts from large servers into the network timeline
 *
 * Runs inside the Pixelfed web container, fed on stdin so nothing has to be
 * mounted or patched:
 *
 *   docker exec -i -u www-data <web-container> php -- tick [open:ID scroll:ID post:ID ...] < pf-sync.php
 *
 * Commands: tick [...] | events [...] | trending [--force] | index [--force] | backfill <id|@user@domain>
 *           | post <id> | likers <id> | search <name> | clean-global | status
 *
 * Settings are PF_SYNC_* environment variables (see README / pf-sync.env).
 * Only public posts can be fetched: remote servers serve nothing else without
 * an accepted follow. Fetching goes through Pixelfed's own Helpers::statusFetch,
 * so Pixelfed handles storage, avatars, the network timeline and home feeds.
 */

declare(strict_types=1);

// jippi/docker-pixelfed uses /var/www, the official image /var/www/html.
$root = getenv('PF_SYNC_APP_ROOT') ?: (is_file('/var/www/artisan') ? '/var/www' : '/var/www/html');
if (! is_file("{$root}/artisan")) {
    fwrite(STDERR, "pf-sync: no Pixelfed install at {$root}, set PF_SYNC_APP_ROOT\n");
    exit(1);
}
require "{$root}/vendor/autoload.php";
$app = require "{$root}/bootstrap/app.php";
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();

use App\Models\Follower;
use App\Models\FollowRequest;
use App\Models\Like;
use App\Models\Profile;
use App\Models\Status;
use App\Services\AccountService;
use App\Services\ActivityPubFetchService;
use App\Services\HomeTimelineService;
use App\Services\NetworkTimelineService;
use App\Services\StatusService;
use App\Util\ActivityPub\Helpers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/** Reads PF_SYNC_<key>, typed after its default. */
function cfg(string $key, int|bool|string $default): int|bool|string
{
    $v = getenv('PF_SYNC_'.$key);
    if ($v === false || $v === '') {
        return $default;
    }

    return match (true) {
        is_bool($default) => filter_var($v, FILTER_VALIDATE_BOOLEAN),
        is_int($default) => (int) $v,
        default => $v,
    };
}

define('BACKFILL_LIMIT', cfg('BACKFILL_LIMIT', 20));
define('RESYNC_SECONDS', cfg('RESYNC_HOURS', 6) * 3600);
define('SCROLL_BACKFILL', cfg('SCROLL_BACKFILL', true));
define('SCROLL_BATCH', cfg('SCROLL_BATCH', 20));
define('SCROLL_MAX_POSTS', cfg('SCROLL_MAX_POSTS', 240));
define('POST_DETAILS', cfg('POST_DETAILS', true));
define('POST_REPLIES_MAX', cfg('POST_REPLIES_MAX', 40));
define('POST_COOLDOWN', cfg('POST_COOLDOWN_MINUTES', 2) * 60);
define('PARALLEL', max(1, cfg('PARALLEL', 4)));
define('TRENDING', cfg('TRENDING', true));
define('TRENDING_INTERVAL', cfg('TRENDING_INTERVAL_MINUTES', 30) * 60);
define('TRENDING_MAX_PER_RUN', cfg('TRENDING_MAX_PER_RUN', 80));
define('TRENDING_SKIP_SENSITIVE', cfg('TRENDING_SKIP_SENSITIVE', true));
define('TRENDING_MIN_LIKES', cfg('TRENDING_MIN_LIKES', 10));
// Pixelfed servers' public trending lists. Mastodon's is photo-light, so it's
// opt-in: add https://mastodon.social/api/v1/trends/statuses?limit=40
define('TRENDING_SOURCES', preg_split('/\s+/', trim((string) cfg(
    'TRENDING_SOURCES',
    implode(' ', array_map(
        fn ($h) => "https://{$h}/api/pixelfed/v2/discover/posts/trending?range=daily",
        ['pixelfed.social', 'pixelfed.de', 'pixelfed.art', 'pixelfed.fr', 'pixelfed.au', 'pixelfed.uno', 'metapixl.com']
    ))
)), -1, PREG_SPLIT_NO_EMPTY));
// Backfilled posts (opened/followed/scrolled accounts) stay out of the global
// timeline; it is meant for trending and normally federated posts.
define('BACKFILL_IN_GLOBAL', cfg('BACKFILL_IN_GLOBAL', false));
define('LIKERS', cfg('LIKERS', true));
define('LIKERS_MAX', cfg('LIKERS_MAX', 40));

define('INDEX', cfg('INDEX', true));
define('INDEX_INTERVAL', cfg('INDEX_INTERVAL_HOURS', 24) * 3600);
define('INDEX_PAGES', cfg('INDEX_PAGES', 10));
define('INDEX_MIN_FOLLOWERS', cfg('INDEX_MIN_FOLLOWERS', 1000));
define('INDEX_MAX_PER_RUN', cfg('INDEX_MAX_PER_RUN', 200));
define('INDEX_SOURCES', preg_split('/\s+/', trim((string) cfg(
    'INDEX_SOURCES',
    'https://mastodon.social https://mastodon.online https://mstdn.social https://mas.to https://fosstodon.org'
)), -1, PREG_SPLIT_NO_EMPTY));
define('SEARCH_LOOKUP', cfg('SEARCH_LOOKUP', true));
define('SEARCH_SERVERS', preg_split('/\s+/', trim((string) cfg('SEARCH_SERVERS', 'https://mastodon.social')), -1, PREG_SPLIT_NO_EMPTY));
define('SEARCH_MAX_IMPORTS', cfg('SEARCH_MAX_IMPORTS', 5));
define('SEARCH_MIN_FOLLOWERS', cfg('SEARCH_MIN_FOLLOWERS', 10));

const MAX_BACKFILLS_PER_TICK = 5;       // keeps one tick short
const MAX_SEARCHES_PER_TICK = 3;
const SEARCH_COOLDOWN = 3600;           // per query
const PENDING_COUNTS_SECONDS = 300;
const DELIVERY_GRACE = 30;              // seconds a local like/follow may take to reach the remote server
const POST_BASE_TTL = 90 * 86400;
const MAX_COUNT_FETCHES_PER_TICK = 10;
const COUNTS_TTL = 12 * 3600;           // how long remote profile counts are trusted
const TRACK_DAYS = 30;                  // keep showing counts for opened profiles this long
const CURSOR_TTL = 7 * 86400;
const SCROLL_COOLDOWN = 15;
const SCROLL_MAX_PAGES = 10;            // pages to skip over when they hold nothing new
const OUTBOX_MAX_PAGES = 5;
const AS_PUBLIC = ['https://www.w3.org/ns/activitystreams#Public', 'as:Public', 'Public'];
const KEY = 'pfsync:';

function out(string $msg): void
{
    echo gmdate('Y-m-d\TH:i:s\Z').' '.$msg.PHP_EOL;
}

function http_json(string $url): mixed
{
    try {
        $res = Http::timeout(15)
            ->withHeaders(['Accept' => 'application/json', 'User-Agent' => 'pf-sync (+'.config('app.url').')'])
            ->get($url);

        return $res->successful() ? $res->json() : null;
    } catch (Throwable) {
        return null;
    }
}

/** Signed ActivityPub GET through Pixelfed, for servers with authorized fetch. */
function ap_json(?string $url): ?array
{
    if (! $url) {
        return null;
    }
    try {
        $body = ActivityPubFetchService::get($url);
    } catch (Throwable) {
        return null;
    }
    $data = is_string($body) ? json_decode($body, true) : null;

    return is_array($data) ? $data : null;
}

function local_part(Profile $p): string
{
    return explode('@', ltrim($p->username, '@'))[0];
}

function remote_host(Profile $p): string
{
    return parse_url($p->remote_url ?? '', PHP_URL_HOST) ?: $p->domain;
}

/** The account's id on its own server, needed for its public APIs. */
function remote_account_id(Profile $p): ?string
{
    $key = KEY.'rid:'.$p->id;
    if ($rid = Cache::get($key)) {
        return (string) $rid;
    }
    $acct = http_json('https://'.remote_host($p).'/api/v1/accounts/lookup?acct='.rawurlencode(local_part($p)));
    $rid = is_array($acct) && isset($acct['id']) ? (string) $acct['id'] : null;
    if ($rid) {
        Cache::put($key, $rid, CURSOR_TTL);
    }

    return $rid;
}

function stored_status(string $uri): ?Status
{
    return Status::withTrashed()
        ->where(fn ($q) => $q->where('uri', $uri)->orWhere('object_url', $uri)->orWhere('url', $uri))
        ->first();
}

/** Like/repost/comment counts from Mastodon-style status JSON (Pixelfed uses reply_count). */
function api_counts(array $s): array
{
    return array_filter([
        'likes_count' => $s['favourites_count'] ?? null,
        'reblogs_count' => $s['reblogs_count'] ?? null,
        'reply_count' => $s['replies_count'] ?? $s['reply_count'] ?? null,
    ], 'is_int');
}

/** Follower/following/post counts from Mastodon-style account JSON. */
function account_counts(mixed $a): array
{
    return is_array($a) ? array_filter([
        'followers_count' => $a['followers_count'] ?? null,
        'following_count' => $a['following_count'] ?? null,
        'status_count' => $a['statuses_count'] ?? null,
    ], 'is_int') : [];
}

/** Mastodon-style status JSON → item if it is a public, non-reply post with media. */
function wanted_api_status(array $s, bool $skipSensitive): ?array
{
    if (! empty($s['reblog']) || ! empty($s['in_reply_to_id'])) {
        return null;
    }
    if (($s['visibility'] ?? null) !== 'public' || empty($s['media_attachments'])) {
        return null;
    }
    if ($skipSensitive && ! empty($s['sensitive'])) {
        return null;
    }
    $uri = $s['uri'] ?? $s['url'] ?? null;

    return is_string($uri) && str_starts_with($uri, 'https://')
        ? ['uri' => $uri, 'counts' => api_counts($s), 'author' => account_counts($s['account'] ?? null)]
        : null;
}

function api_url(array $c, int $limit): string
{
    $page = $c['max_id'] ? '&max_id='.$c['max_id'] : '';

    return $c['kind'] === 'pixelfed'
        ? "https://{$c['host']}/api/pixelfed/v1/accounts/{$c['rid']}/statuses?limit=".min($limit, 24).$page
        : "https://{$c['host']}/api/v1/accounts/{$c['rid']}/statuses?only_media=true&exclude_replies=true&exclude_reblogs=true&limit=".min($limit, 40).$page;
}

function api_page(array $c, array $list, int $limit): array
{
    $items = array_values(array_filter(array_map(fn ($s) => is_array($s) ? wanted_api_status($s, false) : null, $list)));
    $last = end($list);
    $next = count($list) >= min($limit, $c['kind'] === 'pixelfed' ? 24 : 40) && is_array($last) && isset($last['id'])
        ? [...$c, 'max_id' => (string) $last['id']]
        : null;

    return [$items, $next];
}

/**
 * One page of an account's public media posts, newest first: [items, next cursor or null].
 * The first page probes, in order: Pixelfed's public profile API (Pixelfed has
 * no usable outbox), Mastodon's public account API, then the ActivityPub outbox.
 */
function remote_page(Profile $p, ?array $cursor, int $limit): array
{
    if ($cursor === null) {
        $rid = remote_account_id($p);
        foreach ($rid ? ['pixelfed', 'mastodon'] : [] as $kind) {
            $c = ['kind' => $kind, 'host' => remote_host($p), 'rid' => $rid, 'max_id' => null];
            $list = http_json(api_url($c, $limit));
            if (is_array($list) && array_is_list($list)) {
                return api_page($c, $list, $limit);
            }
        }
        $cursor = ['kind' => 'outbox', 'next' => null];
    }

    if ($cursor['kind'] !== 'outbox') {
        $list = http_json(api_url($cursor, $limit));

        // Keep the cursor on a failed request so the next scroll retries it.
        return is_array($list) && array_is_list($list) ? api_page($cursor, $list, $limit) : [[], $cursor];
    }

    return outbox_page($p, $cursor['next'], $limit);
}

function outbox_page(Profile $p, ?string $pageUrl, int $limit): array
{
    if ($pageUrl) {
        $page = ap_json($pageUrl);
    } else {
        $outbox = ap_json($p->outbox_url);
        $first = $outbox['first'] ?? null;
        $page = is_string($first) ? ap_json($first) : (is_array($first) ? $first : $outbox);
    }

    $items = [];
    $next = null;
    for ($i = 0; $page && $i < OUTBOX_MAX_PAGES && count($items) < $limit; $i++) {
        foreach ($page['orderedItems'] ?? $page['items'] ?? [] as $act) {
            $obj = is_array($act) && ($act['type'] ?? null) === 'Create' ? ($act['object'] ?? null) : null;
            if (! is_array($obj) || ! empty($obj['inReplyTo']) || ! is_string($obj['id'] ?? null)) {
                continue;
            }
            $to = array_merge((array) ($obj['to'] ?? []), (array) ($act['to'] ?? []));
            $hasMedia = collect($obj['attachment'] ?? [])->contains(
                fn ($a) => is_array($a) && preg_match('#^(image|video)/#', (string) ($a['mediaType'] ?? ''))
            );
            if ($hasMedia && array_intersect(AS_PUBLIC, $to)) {
                $items[] = ['uri' => $obj['id'], 'counts' => []];
            }
        }
        $next = is_string($page['next'] ?? null) ? $page['next'] : null;
        $page = $next && count($items) < $limit ? ap_json($next) : null;
    }

    return [array_slice($items, 0, $limit), $next ? ['kind' => 'outbox', 'next' => $next] : null];
}

/*
 * Counts shown = remote count + what this server's own users did since it was
 * fetched. Likes, reposts, comments and follows by local users are federated,
 * so the next remote fetch already includes them; until then (and after an
 * unlike or unfollow) the local difference keeps the number honest. Each fetch
 * stores a "base": the remote numbers plus the local numbers at that moment,
 * minus DELIVERY_GRACE so actions still in flight count as local.
 */

/** Profile ids of this server's users. */
function local_ids(): array
{
    static $ids;

    return $ids ??= Profile::whereNull('domain')->whereNotNull('user_id')->pluck('id')->all();
}

function created_before(mixed $query, mixed $before): mixed
{
    return $before ? $query->where('created_at', '<', $before) : $query;
}

/** Likes, reposts and comments this server's users gave a post. */
function local_post_counts(int|string $id, mixed $before = null): array
{
    return [
        'likes_count' => created_before(Like::where('status_id', $id)->whereIn('profile_id', local_ids()), $before)->count(),
        'reblogs_count' => created_before(Status::where('reblog_of_id', $id)->whereIn('profile_id', local_ids()), $before)->count(),
        'reply_count' => created_before(Status::where('in_reply_to_id', $id)->whereIn('profile_id', local_ids()), $before)->count(),
    ];
}

/** Follows between this server's users and a remote account. */
function local_follow_counts(Profile $p, mixed $before = null): array
{
    return [
        'followers_count' => created_before(Follower::where('following_id', $p->id)->whereIn('profile_id', local_ids()), $before)->count(),
        'following_count' => created_before(Follower::where('profile_id', $p->id)->whereIn('following_id', local_ids()), $before)->count(),
        'status_count' => 0,
    ];
}

/** Stores freshly fetched remote numbers with the local numbers at this moment. */
function new_base(?array $base, array $remote, array $localThen): array
{
    $base = is_array($base) && isset($base['r']) ? $base : ['r' => [], 'l0' => []];
    foreach ($remote as $field => $value) {
        $base['r'][$field] = $value;
        $base['l0'][$field] = $localThen[$field] ?? 0;
    }
    $base['at'] = time();

    return $base;
}

function wanted_counts(array $base, array $localNow): array
{
    $want = [];
    foreach ($base['r'] as $field => $value) {
        $want[$field] = max(0, $value + ($localNow[$field] ?? 0) - ($base['l0'][$field] ?? 0));
    }

    return $want;
}

function set_post_remote_counts(Status $s, array $remote): void
{
    if (! $remote) {
        return;
    }
    $key = KEY.'pb:'.$s->id;
    $base = new_base(Cache::get($key), $remote, local_post_counts($s->id, now()->subSeconds(DELIVERY_GRACE)));
    Cache::put($key, $base, POST_BASE_TTL);
    write_post_counts($s, wanted_counts($base, local_post_counts($s->id)));
}

/** Re-applies a post's stored base against current local activity. */
function apply_post_base(Status $s): bool
{
    $base = Cache::get(KEY.'pb:'.$s->id);

    return is_array($base) && isset($base['r'])
        && write_post_counts($s, wanted_counts($base, local_post_counts($s->id)));
}

/** Writes like/repost/comment counts onto a stored post and busts its cache. */
function write_post_counts(Status $s, array $counts): bool
{
    $changes = [];
    foreach ($counts as $field => $value) {
        if ((int) $s->{$field} !== $value) {
            $changes[$field] = $value;
        }
    }
    if (! $changes) {
        return false;
    }
    Status::whereKey($s->id)->update($changes);
    StatusService::del($s->id);

    return true;
}

/** Closes database and Redis connections; they reopen on next use. */
function disconnect_all(): void
{
    foreach (array_keys(DB::getConnections()) as $name) {
        DB::disconnect($name);
    }
    $redis = app('redis');
    foreach (array_keys($redis->connections() ?? []) as $name) {
        $redis->purge($name);
    }
}

/**
 * Runs $fn for every item in up to PARALLEL forked workers, so one slow or
 * dead remote server (Pixelfed waits up to 15 s per request) only holds up
 * its own worker. Connections are closed first so each worker opens its own.
 */
function parallel(array $items, callable $fn): void
{
    $items = array_values($items);
    $workers = min(PARALLEL, count($items));
    if ($workers <= 1 || ! function_exists('pcntl_fork')) {
        foreach ($items as $item) {
            try {
                $fn($item);
            } catch (Throwable) {
            }
        }

        return;
    }

    disconnect_all();
    $pids = [];
    for ($w = 0; $w < $workers; $w++) {
        $pid = pcntl_fork();
        if ($pid === 0) {
            for ($i = $w; $i < count($items); $i += $workers) {
                try {
                    $fn($items[$i]);
                } catch (Throwable) {
                }
            }
            disconnect_all();
            exit(0);
        }
        if ($pid > 0) {
            $pids[] = $pid;
        } else {
            // Fork failed: do this worker's share here.
            for ($i = $w; $i < count($items); $i += $workers) {
                try {
                    $fn($items[$i]);
                } catch (Throwable) {
                }
            }
        }
    }
    foreach ($pids as $pid) {
        pcntl_waitpid($pid, $status);
    }
}

/**
 * Fetches items through Pixelfed in parallel, then applies their counts (to
 * already stored posts too). Pixelfed adds new public posts to the global
 * timeline; with $global false they're taken out again. Returns [new, failed].
 */
function fetch_items(array $items, bool $global): array
{
    $todo = array_values(array_filter(array_reverse($items), fn ($i) => ! stored_status($i['uri'])));
    parallel($todo, fn ($item) => Helpers::statusFetch($item['uri']));

    $new = $failed = 0;
    foreach ($todo as $item) {
        if (! ($status = stored_status($item['uri']))) {
            $failed++;

            continue;
        }
        $new++;
        if (! $global) {
            NetworkTimelineService::rem($status->id);
        }
    }
    foreach ($items as $item) {
        if (! ($s = stored_status($item['uri']))) {
            continue;
        }
        if ($item['counts']) {
            set_post_remote_counts($s, $item['counts']);
        }
        // Real author counts make them rank properly in search and Discover.
        if (! empty($item['author']) && ($author = remote_profile($s->profile_id))) {
            set_profile_remote_counts($author, $item['author']);
        }
    }

    return [$new, $failed];
}

/**
 * Makes a remote account known to this server so Pixelfed's own search and
 * "Popular accounts" (both ranked by followers) can find it.
 * Directory crawls only take accounts that opted into discovery; an explicit
 * search takes any account, like the remote server's own search does.
 * Returns new, known, skipped or failed.
 */
function import_account(array $a, bool $fromDirectory): string
{
    $uri = $a['uri'] ?? null;
    if (! is_string($uri) || ! str_starts_with($uri, 'https://') || ! empty($a['suspended']) || ! empty($a['moved'])
        || ($fromDirectory && (($a['discoverable'] ?? null) !== true || ! empty($a['noindex'])))) {
        return 'skipped';
    }
    $p = Profile::where('remote_url', $uri)->whereNotNull('domain')->first();
    $status = $p ? 'known' : 'new';
    if (! $p) {
        try {
            $p = Helpers::profileFetch($uri);
        } catch (Throwable) {
            $p = null;
        }
    }
    if (! $p || ! $p->domain) {
        return 'failed';
    }
    set_profile_remote_counts($p, account_counts($a));

    return $status;
}

/** Periodic crawl of public account directories (opt-in "discoverable" accounts only). */
function cmd_index(bool $force): void
{
    if (! INDEX || (! $force && Cache::has(KEY.'index'))) {
        return;
    }
    Cache::put(KEY.'index', time(), max(3600, INDEX_INTERVAL - 300));

    $budget = INDEX_MAX_PER_RUN;
    $summary = [];
    foreach (INDEX_SOURCES as $base) {
        $base = rtrim($base, '/');
        $tally = ['new' => 0, 'known' => 0, 'skipped' => 0, 'failed' => 0];
        $seen = 0;
        for ($page = 0; $page < INDEX_PAGES && $budget > 0; $page++) {
            $list = http_json("{$base}/api/v1/directory?order=active&local=true&limit=80&offset=".($page * 80));
            if (! is_array($list) || ! array_is_list($list) || ! $list) {
                break;
            }
            $seen += count($list);
            foreach ($list as $a) {
                if (! is_array($a) || ($a['followers_count'] ?? 0) < INDEX_MIN_FOLLOWERS) {
                    continue;
                }
                if ($budget <= 0) {
                    break;
                }
                $r = import_account($a, true);
                $tally[$r]++;
                if ($r === 'new' || $r === 'failed') {
                    $budget--;
                }
            }
        }
        $summary[] = sprintf('%s: %d listed, %d new, %d updated, %d skipped, %d failed',
            parse_url($base, PHP_URL_HOST), $seen, $tally['new'], $tally['known'], $tally['skipped'], $tally['failed']);
    }
    out('index '.implode('; ', $summary));
}

/**
 * Someone searched for a name: look it up on large servers and import the most
 * followed matches, so a search a few seconds later finds them here.
 */
function lookup_search(string $q): void
{
    $q = trim(ltrim(trim($q), '@'));
    if (! SEARCH_LOOKUP || mb_strlen($q) < 3 || str_contains($q, '@') || str_starts_with($q, '#') || preg_match('#^https?://#', $q)
        || ! Cache::add(KEY.'search:'.md5(mb_strtolower($q)), 1, SEARCH_COOLDOWN)) {
        return;
    }
    // Pixelfed's search only matches the start of a handle, so other matches
    // (display names, bios) would never show up for this query.
    $prefix = mb_strtolower($q);
    $found = [];
    foreach (SEARCH_SERVERS as $base) {
        $res = http_json(rtrim($base, '/').'/api/v2/search?type=accounts&limit=20&q='.rawurlencode($q));
        foreach ($res['accounts'] ?? [] as $a) {
            if (is_array($a) && is_string($a['uri'] ?? null) && ($a['followers_count'] ?? 0) >= SEARCH_MIN_FOLLOWERS
                && str_starts_with(mb_strtolower((string) ($a['acct'] ?? '')), $prefix)) {
                $found[$a['uri']] = $a;
            }
        }
    }
    usort($found, fn ($a, $b) => ($b['followers_count'] ?? 0) <=> ($a['followers_count'] ?? 0));
    $tally = ['new' => 0, 'known' => 0, 'skipped' => 0, 'failed' => 0];
    foreach (array_slice($found, 0, SEARCH_MAX_IMPORTS) as $a) {
        $tally[import_account($a, false)]++;
    }
    if ($tally['new'] || $tally['failed']) {
        out(sprintf('search "%s": %d new, %d known, %d failed (%s)', $q, $tally['new'], $tally['known'], $tally['failed'],
            implode(', ', array_map(fn ($a) => $a['acct'].' '.$a['followers_count'], array_slice($found, 0, SEARCH_MAX_IMPORTS)))));
    }
}

/**
 * A profile's count base, refetched from the actor's collections when missing
 * or older than COUNTS_TTL (or when $refresh). Returns null if never fetched.
 */
function profile_base(Profile $p, bool $refresh = false): ?array
{
    $base = Cache::get(KEY.'pc:'.$p->id);
    if (! $refresh && is_array($base) && $base['at'] > time() - COUNTS_TTL) {
        return $base;
    }
    $actor = ap_json($p->remote_url);
    if (! $actor) {
        return is_array($base) ? $base : null;
    }
    $total = function ($url) {
        $c = is_string($url) ? ap_json($url) : (is_array($url) ? $url : null);

        return is_int($c['totalItems'] ?? null) ? $c['totalItems'] : null;
    };
    $remote = array_filter([
        'followers_count' => $total($actor['followers'] ?? null),
        'following_count' => $total($actor['following'] ?? null),
        'status_count' => $total($actor['outbox'] ?? null),
    ], fn ($v) => $v !== null);

    return set_profile_remote_counts($p, $remote, false);
}

function set_profile_remote_counts(Profile $p, array $remote, bool $write = true): ?array
{
    if (! $remote) {
        return Cache::get(KEY.'pc:'.$p->id);
    }
    $key = KEY.'pc:'.$p->id;
    $base = new_base(Cache::get($key), $remote, local_follow_counts($p, now()->subSeconds(DELIVERY_GRACE)));
    Cache::put($key, $base, TRACK_DAYS * 86400);
    if ($write) {
        apply_profile_base($p, $base);
    }

    return $base;
}

/**
 * Applies a profile's base against current local follows. Pixelfed never reads
 * remote counts, resets status_count to the locally stored posts and, on some
 * unfollows, followers/following to local follows only, so this is re-applied
 * on every tick for tracked profiles.
 */
function apply_profile_base(Profile $p, ?array $base): bool
{
    return is_array($base) && isset($base['r'])
        && write_profile_counts($p, wanted_counts($base, local_follow_counts($p)));
}

/** Writes follower/following/post counts onto a remote profile and busts its cache. */
function write_profile_counts(Profile $p, array $counts): bool
{
    $changes = [];
    foreach ($counts ?? [] as $field => $value) {
        if ((int) $p->{$field} !== $value) {
            $changes[$field] = $value;
        }
    }
    if (! $changes) {
        return false;
    }
    Profile::whereKey($p->id)->update($changes);
    AccountService::del($p->id);

    return true;
}

/** Pixelfed only pushes posts younger than 7 days into an existing home feed. */
function rebuild_follower_feeds(Profile $p): void
{
    $local = Profile::whereNull('domain')->whereNotNull('user_id')->pluck('id');
    $followers = Follower::where('following_id', $p->id)->whereIn('profile_id', $local)->pluck('profile_id');
    foreach ($followers as $pid) {
        if (HomeTimelineService::cacheKeyExists($pid)) {
            HomeTimelineService::warmCache($pid, true, 400);
        }
    }
}

function save_cursor(Profile $p, ?array $next): void
{
    Cache::put(KEY.'cursor:'.$p->id, $next ?? ['kind' => 'done'], CURSOR_TTL);
}

function backfill(Profile $p, string $why): void
{
    [$items, $next] = remote_page($p, null, BACKFILL_LIMIT);
    [$new, $failed] = fetch_items($items, BACKFILL_IN_GLOBAL);
    // Keep an older scroll position: re-syncs only need the newest page.
    if ($why !== 'followed' || ! Cache::has(KEY.'cursor:'.$p->id)) {
        save_cursor($p, $next);
    }
    $base = profile_base($p, true);
    apply_profile_base($p->fresh(), $base);
    $counts = $base['r'] ?? null;
    Cache::put(KEY.'backfilled:'.$p->id, time(), RESYNC_SECONDS);
    if ($new > 0) {
        rebuild_follower_feeds($p);
    }

    out(sprintf(
        'backfill %s (%s): %d listed, %d new, %d failed; counts %s',
        $p->username, $why, count($items), $new, $failed, json_encode($counts)
    ));
}

/** Someone reached the end of a remote profile: load the next older batch. */
function scroll(Profile $p): void
{
    if (! SCROLL_BACKFILL || ! Cache::add(KEY.'scrolled:'.$p->id, 1, SCROLL_COOLDOWN)) {
        return;
    }
    $stored = Status::where('profile_id', $p->id)->whereNull('in_reply_to_id')->count();
    if ($stored >= SCROLL_MAX_POSTS) {
        return;
    }
    $cursor = Cache::get(KEY.'cursor:'.$p->id);
    if (($cursor['kind'] ?? null) === 'done') {
        return;
    }

    // Without a saved position, walk from the newest page past what is stored.
    $limit = min(SCROLL_BATCH, SCROLL_MAX_POSTS - $stored);
    $new = $failed = $pages = 0;
    do {
        [$items, $next] = remote_page($p, $cursor, $limit);
        [$n, $f] = fetch_items($items, BACKFILL_IN_GLOBAL);
        $new += $n;
        $failed += $f;
        $retry = $cursor !== null && $next === $cursor;
        $cursor = $next;
        $pages++;
    } while ($new === 0 && $cursor && ! $retry && $pages < SCROLL_MAX_PAGES);
    if (! $retry) {
        save_cursor($p, $cursor);
    }

    out(sprintf(
        'scroll %s: %d new, %d failed over %d page(s)%s',
        $p->username, $new, $failed, $pages, $cursor ? '' : '; reached the oldest public post'
    ));
}

/**
 * A remote post was opened: fetch its comments (Mastodon: the reply thread,
 * Pixelfed: its public comments API) and refresh like/repost/comment counts.
 * Pixelfed fetches avatars of the comment authors itself.
 */
function open_post(int|string $id): void
{
    if (! POST_DETAILS || ! Cache::add(KEY.'post:'.$id, 1, POST_COOLDOWN)) {
        return;
    }
    $s = Status::whereKey($id)->where('local', false)->first();
    $author = $s ? remote_profile($s->profile_id) : null;
    $uri = $s?->uri ?? $s?->object_url;
    if (! $author || ! $uri || ! preg_match('#/(\d+)/?$#', (string) parse_url($uri, PHP_URL_PATH), $m)) {
        return;
    }
    $host = parse_url($uri, PHP_URL_HOST);
    $rid = $m[1];

    $replies = [];
    $counts = [];
    $private = 0;
    $remote = http_json("https://{$host}/api/v1/statuses/{$rid}");
    if (is_array($remote) && isset($remote['id'])) {
        $counts = api_counts($remote);
        $ctx = http_json("https://{$host}/api/v1/statuses/{$rid}/context");
        foreach ($ctx['descendants'] ?? [] as $r) {
            if (is_string($r['uri'] ?? null) && in_array($r['visibility'] ?? null, ['public', 'unlisted'], true)) {
                $replies[] = ['uri' => $r['uri'], 'counts' => api_counts($r)];
            }
        }
    } elseif ($arid = remote_account_id($author)) {
        // Pixelfed's single-post API needs a login; its public profile listing
        // starting just above this post's id returns the post with its counts.
        $one = http_json(api_url(['kind' => 'pixelfed', 'host' => $host, 'rid' => $arid, 'max_id' => (string) ((int) $rid + 1)], 1));
        if (is_array($one) && is_array($one[0] ?? null) && (string) ($one[0]['id'] ?? '') === $rid) {
            $counts = api_counts($one[0]);
        }
        $url = "https://{$host}/api/v2/comments/{$arid}/status/{$rid}";
        while ($url && count($replies) < POST_REPLIES_MAX) {
            $page = http_json($url);
            foreach ($page['data'] ?? [] as $r) {
                // Comments of private Pixelfed accounts are listed but refused
                // to anyone who isn't an approved follower.
                if (! empty($r['account']['locked'])) {
                    $private++;
                } elseif (is_string($r['uri'] ?? null)) {
                    $replies[] = ['uri' => $r['uri'], 'counts' => api_counts($r)];
                }
            }
            $total = $page['meta']['pagination']['total'] ?? null;
            if (is_int($total)) {
                $counts['reply_count'] = $total;
            }
            $url = $page['meta']['pagination']['links']['next'] ?? null;
        }
    }

    // Counts first, then comments in batches in the order the remote lists
    // them, so a reload shows the first ones after a few seconds.
    set_post_remote_counts($s->fresh(), $counts);
    $replies = array_slice($replies, 0, POST_REPLIES_MAX);
    $new = $failed = 0;
    foreach (array_chunk($replies, PARALLEL * 2) as $chunk) {
        [$n, $f] = fetch_items(array_reverse($chunk), false);
        $new += $n;
        $failed += $f;
    }
    // Pixelfed's queue bumps reply_count for every fetched reply, partly after
    // this returns, so the base is also re-applied by the next ticks.
    set_post_remote_counts($s->fresh(), $counts);
    if ($counts) {
        $pending = Cache::get(KEY.'pending-counts', []);
        $pending[(string) $s->id] = time() + PENDING_COUNTS_SECONDS;
        Cache::forever(KEY.'pending-counts', $pending);
    } else {
        // Remote unreachable or rate limited: let the next open retry.
        Cache::forget(KEY.'post:'.$id);
    }

    out(sprintf(
        'post %s: %d comments listed, %d new, %d failed%s; counts %s',
        $uri, count($replies), $new, $failed, $private ? ", {$private} from private accounts skipped" : '', json_encode($counts)
    ));
}

/**
 * Someone opened "Liked by" or "Shared by" on a remote post: import who liked
 * and shared it, stored the way Pixelfed stores likes and shares it receives
 * from other servers (no notifications, counts unchanged). Mastodon publishes
 * both lists; Pixelfed only reveals one liker, so that's all it can show.
 */
function open_likers(int|string $id): void
{
    if (! LIKERS || ! Cache::add(KEY.'likers:'.$id, 1, POST_COOLDOWN)) {
        return;
    }
    $s = Status::whereKey($id)->where('local', false)->first();
    $author = $s ? remote_profile($s->profile_id) : null;
    $uri = $s?->uri ?? $s?->object_url;
    if (! $author || ! $uri || ! preg_match('#/(\d+)/?$#', (string) parse_url($uri, PHP_URL_PATH), $m)) {
        return;
    }
    $host = parse_url($uri, PHP_URL_HOST);
    $rid = $m[1];

    $likers = http_json("https://{$host}/api/v1/statuses/{$rid}/favourited_by?limit=".min(LIKERS_MAX, 80));
    $sharers = http_json("https://{$host}/api/v1/statuses/{$rid}/reblogged_by?limit=".min(LIKERS_MAX, 80));
    if (! is_array($likers) && ($arid = remote_account_id($author))) {
        // Pixelfed names one liker by its id on that server, which may be an
        // account from yet another server: resolve id -> handle -> actor.
        $one = http_json(api_url(['kind' => 'pixelfed', 'host' => $host, 'rid' => $arid, 'max_id' => (string) ((int) $rid + 1)], 1));
        $by = $one[0]['liked_by'] ?? null;
        $acct = is_array($by) && isset($by['id']) ? (http_json("https://{$host}/api/pixelfed/v1/accounts/{$by['id']}")['acct'] ?? null) : null;
        $actor = is_string($acct) ? webfinger_actor(str_contains($acct, '@') ? $acct : "{$acct}@{$host}") : null;
        $likers = $actor ? [['uri' => $actor]] : null;
    }
    if (! is_array($likers) && ! is_array($sharers)) {
        Cache::forget(KEY.'likers:'.$id);

        return;
    }

    $lists = [
        'likes' => array_slice(array_filter(is_array($likers) ? $likers : [], 'is_array'), 0, LIKERS_MAX),
        'shares' => array_slice(array_filter(is_array($sharers) ? $sharers : [], 'is_array'), 0, LIKERS_MAX),
    ];
    $unique = [];
    foreach ([...$lists['likes'], ...$lists['shares']] as $a) {
        if (is_string($a['uri'] ?? null)) {
            $unique[$a['uri']] = $a;
        }
    }
    parallel($unique, fn ($a) => import_account($a, false));

    $tally = ['likes' => 0, 'shares' => 0, 'failed' => 0];
    foreach ($lists as $kind => $accounts) {
        foreach ($accounts as $a) {
            if (! is_string($a['uri'] ?? null)
                || ! ($p = Profile::where('remote_url', $a['uri'])->whereNotNull('domain')->first())) {
                $tally['failed']++;

                continue;
            }
            $row = $kind === 'likes'
                ? Like::firstOrCreate(
                    ['profile_id' => $p->id, 'status_id' => $s->id],
                    ['status_profile_id' => $s->profile_id, 'is_comment' => (bool) $s->in_reply_to_id]
                )
                : Status::firstOrCreate(['profile_id' => $p->id, 'reblog_of_id' => $s->id, 'type' => 'share', 'local' => false]);
            if ($row->wasRecentlyCreated) {
                $tally[$kind]++;
            }
        }
    }
    StatusService::del($s->id);

    out(sprintf(
        'likers %s: %d likes, %d shares added, %d failed (remote lists: %s likes, %s shares)',
        $uri, $tally['likes'], $tally['shares'], $tally['failed'],
        is_array($likers) ? count($likers) : '-', is_array($sharers) ? count($sharers) : '-'
    ));
}

/** Actor URI for user@host via webfinger. */
function webfinger_actor(string $acct): ?string
{
    [$user, $host] = array_pad(explode('@', ltrim($acct, '@'), 2), 2, null);
    if (! $user || ! $host) {
        return null;
    }
    $wf = http_json("https://{$host}/.well-known/webfinger?resource=acct:".rawurlencode($user)."@{$host}");
    foreach ($wf['links'] ?? [] as $link) {
        if (($link['rel'] ?? null) === 'self' && is_string($link['href'] ?? null)) {
            return $link['href'];
        }
    }

    return null;
}

function remote_profile(int|string $id): ?Profile
{
    $p = Profile::whereKey($id)->whereNotNull('domain')->first();

    return $p && $p->remote_url ? $p : null;
}

/** Remote profile ids that local users follow or have requested to follow. */
function followed_remote_ids(): array
{
    $local = Profile::whereNull('domain')->whereNotNull('user_id')->pluck('id');
    $ids = Follower::whereIn('profile_id', $local)->pluck('following_id')
        ->merge(FollowRequest::whereIn('follower_id', $local)->where('is_rejected', false)->pluck('following_id'))
        ->unique();

    return Profile::whereIn('id', $ids)->whereNotNull('domain')->pluck('id')->map(fn ($v) => (string) $v)->all();
}

/**
 * Marks profiles as tracked (their counts are kept applied for TRACK_DAYS).
 * Re-reads right before writing because several event handlers may run at once.
 */
function track(array $ids): void
{
    if (! $ids) {
        return;
    }
    $tracked = Cache::get(KEY.'tracked', []);
    foreach ($ids as $id) {
        $tracked[(string) $id] = time();
    }
    Cache::forever(KEY.'tracked', $tracked);
}

/**
 * Handles what someone just did (search, opened post/likers, scrolled or
 * opened profile). The live watcher calls this per event ("events" command);
 * cron's "tick" also does the periodic work.
 *
 * @param array<string, list<string>> $events
 */
function cmd_tick(array $events, bool $periodic): void
{
    foreach (final_queries($events['search']) as $q) {
        lookup_search($q);
    }
    foreach (array_unique($events['post']) as $id) {
        open_post($id);
    }
    foreach (array_unique($events['likers']) as $id) {
        open_likers($id);
    }
    $scrolled = array_filter(array_map('remote_profile', array_unique($events['scroll'])));
    track(array_map(fn ($p) => $p->id, $scrolled));
    foreach ($scrolled as $p) {
        scroll($p);
    }
    $opened = array_filter(array_map('remote_profile', array_unique($events['open'])));
    track(array_map(fn ($p) => $p->id, $opened));
    foreach ($opened as $p) {
        if (! Cache::has(KEY.'backfilled:'.$p->id)) {
            backfill($p, 'opened');
        }
    }

    if ($periodic) {
        periodic_work();
    }
}

/** Follow re-syncs, keeping counts applied, and post count re-application. */
function periodic_work(): void
{
    $now = time();
    $followed = followed_remote_ids();
    track($followed);
    $done = 0;
    foreach ($followed as $id) {
        if ($done >= MAX_BACKFILLS_PER_TICK) {
            break;
        }
        if (Cache::has(KEY.'backfilled:'.$id) || ! ($p = remote_profile($id))) {
            continue;
        }
        backfill($p, 'followed');
        $done++;
    }

    $tracked = Cache::get(KEY.'tracked', []);
    $drop = array_keys(array_filter($tracked, fn ($ts) => $ts <= $now - TRACK_DAYS * 86400));
    $fetches = 0;
    foreach (array_diff(array_keys($tracked), $drop) as $id) {
        if (! ($p = remote_profile($id))) {
            $drop[] = $id;

            continue;
        }
        $base = Cache::get(KEY.'pc:'.$id);
        $stale = ! is_array($base) || ($base['at'] ?? 0) <= $now - COUNTS_TTL;
        if ($stale && $fetches++ < MAX_COUNT_FETCHES_PER_TICK) {
            $base = profile_base($p, true);
            if (apply_profile_base($p, $base)) {
                out("counts {$p->username}: ".json_encode($base['r'] ?? null));
            }
        } else {
            apply_profile_base($p, $base);
        }
    }

    if ($drop) {
        $current = Cache::get(KEY.'tracked', []);
        foreach ($drop as $id) {
            unset($current[(string) $id]);
        }
        Cache::forever(KEY.'tracked', $current);
    }

    $pending = array_filter(Cache::get(KEY.'pending-counts', []), fn ($until) => is_int($until) && $until > $now);
    foreach (array_keys($pending) as $id) {
        if ($s = Status::find($id)) {
            apply_post_base($s);
        }
    }
    Cache::forever(KEY.'pending-counts', $pending);
}

function cmd_trending(bool $force): void
{
    if (! TRENDING || (! $force && Cache::has(KEY.'trending'))) {
        return;
    }
    Cache::put(KEY.'trending', time(), max(60, TRENDING_INTERVAL - 30));

    $summary = [];
    $budget = TRENDING_MAX_PER_RUN;
    foreach (TRENDING_SOURCES as $url) {
        $name = parse_url($url, PHP_URL_HOST) ?: $url;
        $list = http_json($url);
        if (! is_array($list) || ! array_is_list($list)) {
            $summary[] = "{$name}: unavailable";

            continue;
        }
        $items = array_values(array_filter(
            array_map(fn ($s) => is_array($s) ? wanted_api_status($s, TRENDING_SKIP_SENSITIVE) : null, $list),
            fn ($i) => $i && ($i['counts']['likes_count'] ?? 0) >= TRENDING_MIN_LIKES
        ));
        $fresh = array_values(array_filter($items, fn ($i) => ! stored_status($i['uri'])));
        $known = array_filter($items, fn ($i) => ! in_array($i, $fresh, true));
        // Known posts only get their counts refreshed.
        [$new, $failed] = fetch_items([...array_slice($fresh, 0, max(0, $budget)), ...$known], true);
        $budget -= $new + $failed;
        mark_trending($items);
        $summary[] = sprintf('%s: %d listed, %d popular enough, %d new, %d failed', $name, count($list), count($items), $new, $failed);
    }
    out('trending '.implode('; ', $summary));
}

/**
 * Remembers trending picks and puts them in the global timeline if Pixelfed
 * considers them eligible (e.g. a post first stored by a backfill).
 */
function mark_trending(array $items): void
{
    $picked = Cache::get(KEY.'trending-ids', []);
    foreach ($items as $item) {
        if (! ($s = stored_status($item['uri']))) {
            continue;
        }
        $picked[(string) $s->id] = time();
        if (Helpers::isEligibleForNetwork($s)) {
            NetworkTimelineService::add($s->id);
        }
    }
    Cache::forever(KEY.'trending-ids', array_filter($picked, fn ($ts) => $ts > time() - TRACK_DAYS * 86400));
}

/**
 * One-off cleanup: takes everything out of the global timeline that isn't a
 * trending pick or a post this server's users interacted with. Normally
 * federated posts arriving later are left alone.
 */
function cmd_clean_global(): void
{
    $picked = Cache::get(KEY.'trending-ids', []);
    $removed = $kept = 0;
    foreach (NetworkTimelineService::get(0, 2000) as $id) {
        $s = Status::find($id);
        $interacted = $s && array_sum(local_post_counts($id)) > 0;
        if ($s && (isset($picked[(string) $id]) || $s->local || $interacted)) {
            $kept++;

            continue;
        }
        NetworkTimelineService::rem($id);
        $removed++;
    }
    out("clean-global: kept {$kept}, removed {$removed}");
}

function cmd_backfill(string $who): void
{
    $p = ctype_digit($who)
        ? remote_profile($who)
        : Profile::where('username', '@'.ltrim($who, '@'))->whereNotNull('domain')->first();
    if (! $p) {
        out("unknown remote profile {$who} (search for it in Pixelfed first)");

        return;
    }
    Cache::forget(KEY.'backfilled:'.$p->id);
    backfill($p, 'manual');
    $tracked = Cache::get(KEY.'tracked', []);
    $tracked[(string) $p->id] = time();
    Cache::forever(KEY.'tracked', $tracked);
}

function cmd_search(string $q): void
{
    Cache::forget(KEY.'search:'.md5(mb_strtolower(trim(ltrim(trim($q), '@')))));
    lookup_search($q);
}

function cmd_likers(string $id): void
{
    Cache::forget(KEY.'likers:'.$id);
    open_likers($id);
}

function cmd_post(string $id): void
{
    Cache::forget(KEY.'post:'.$id);
    open_post($id);
}

function cmd_status(): void
{
    $tracked = Cache::get(KEY.'tracked', []);
    $followed = array_flip(followed_remote_ids());
    printf(
        "settings: backfill %d, resync %dh, scroll %s (batch %d, max %d), post details %s (max %d comments), likers %s\n"
        ."          trending %s every %d min (%d sources, >= %d likes), backfill in global %s\n",
        BACKFILL_LIMIT, RESYNC_SECONDS / 3600, SCROLL_BACKFILL ? 'on' : 'off', SCROLL_BATCH, SCROLL_MAX_POSTS,
        POST_DETAILS ? 'on' : 'off', POST_REPLIES_MAX, LIKERS ? 'on' : 'off',
        TRENDING ? 'on' : 'off', TRENDING_INTERVAL / 60, count(TRENDING_SOURCES), TRENDING_MIN_LIKES, BACKFILL_IN_GLOBAL ? 'on' : 'off'
    );
    printf(
        "          index %s (>= %d followers, %d sources), search lookup %s via %s\n",
        INDEX ? 'on' : 'off', INDEX_MIN_FOLLOWERS, count(INDEX_SOURCES),
        SEARCH_LOOKUP ? 'on' : 'off', implode(', ', array_map(fn ($u) => parse_url($u, PHP_URL_HOST), SEARCH_SERVERS))
    );
    $i = Cache::get(KEY.'index');
    echo 'remote profiles known: '.Profile::whereNotNull('domain')->count()
        .', last index run: '.($i ? gmdate('Y-m-d H:i', (int) $i).'Z' : '-').PHP_EOL;
    echo count($tracked).' tracked profiles'.PHP_EOL;
    foreach ($tracked as $id => $ts) {
        $p = remote_profile($id);
        if (! $p) {
            continue;
        }
        $last = Cache::get(KEY.'backfilled:'.$id);
        $cursor = Cache::get(KEY.'cursor:'.$id);
        printf(
            "  %-40s %s  followers=%d following=%d posts=%d (stored %d%s)  last sync %s\n",
            $p->username,
            isset($followed[$id]) ? 'followed' : 'opened  ',
            $p->followers_count, $p->following_count, $p->status_count,
            Status::where('profile_id', $id)->whereNull('in_reply_to_id')->count(),
            ($cursor['kind'] ?? null) === 'done' ? ', all public' : '',
            $last ? gmdate('Y-m-d H:i', (int) $last).'Z' : '-'
        );
    }
    $t = Cache::get(KEY.'trending');
    echo 'last trending run: '.($t ? gmdate('Y-m-d H:i', (int) $t).'Z' : '-').PHP_EOL;
}

/** tick arguments: open:ID, scroll:ID, post:ID, likers:ID, search:<url-encoded query> (a bare ID means open). */
function parse_events(array $args): array
{
    $events = ['open' => [], 'scroll' => [], 'post' => [], 'likers' => [], 'search' => []];
    foreach ($args as $arg) {
        [$kind, $val] = str_contains($arg, ':') ? explode(':', $arg, 2) : ['open', $arg];
        if ($kind === 'search') {
            $events['search'][] = urldecode($val);
        } elseif (isset($events[$kind]) && ctype_digit($val)) {
            $events[$kind][] = $val;
        }
    }

    return $events;
}

/** Drops queries that are prefixes of longer ones (typing "xk", "xkc", "xkcd"). */
function final_queries(array $queries): array
{
    $queries = array_values(array_unique(array_map(fn ($q) => mb_strtolower(trim($q)), $queries)));
    usort($queries, fn ($a, $b) => mb_strlen($b) <=> mb_strlen($a));
    $keep = [];
    foreach ($queries as $q) {
        if (! array_filter($keep, fn ($k) => str_starts_with($k, $q))) {
            $keep[] = $q;
        }
    }

    return array_slice($keep, 0, MAX_SEARCHES_PER_TICK);
}

$args = array_slice($argv, 1);
$cmd = array_shift($args) ?? 'status';
match ($cmd) {
    'tick' => cmd_tick(parse_events($args), true),
    'events' => cmd_tick(parse_events($args), false),
    'trending' => cmd_trending(in_array('--force', $args, true)),
    'index' => cmd_index(in_array('--force', $args, true)),
    'search' => isset($args[0]) ? cmd_search(implode(' ', $args)) : out('usage: search <name>'),
    'backfill' => isset($args[0]) ? cmd_backfill($args[0]) : out('usage: backfill <id|@user@domain>'),
    'post' => isset($args[0]) ? cmd_post($args[0]) : out('usage: post <local status id>'),
    'likers' => isset($args[0]) ? cmd_likers($args[0]) : out('usage: likers <local status id>'),
    'clean-global' => cmd_clean_global(),
    'status' => cmd_status(),
    default => out("unknown command {$cmd}"),
};
