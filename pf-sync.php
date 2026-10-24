<?php

/**
 * pf-sync: fills the gaps Pixelfed leaves on small servers.
 *
 *   - backfills the last public media posts of remote accounts you open or follow
 *   - shows the follower/following/post counts the remote server publishes
 *   - re-syncs followed accounts periodically to catch posts federation missed
 *   - pulls trending posts from large servers into the network timeline
 *
 * Runs inside the Pixelfed web container, fed on stdin so nothing has to be
 * mounted or patched:
 *
 *   docker exec -i -u www-data <web-container> php -- tick [profileId ...] < pf-sync.php
 *
 * Commands: tick [ids...] | trending [--force] | backfill <id|@user@domain> | status
 *
 * Only public posts can be fetched: remote servers serve nothing else without
 * an accepted follow. Fetching goes through Pixelfed's own Helpers::statusFetch,
 * so Pixelfed handles storage, the network timeline and home feed inserts.
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
use App\Models\Profile;
use App\Models\Status;
use App\Services\AccountService;
use App\Services\ActivityPubFetchService;
use App\Services\HomeTimelineService;
use App\Util\ActivityPub\Helpers;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;

const BACKFILL_LIMIT = 20;              // posts per account, as discussed
const BACKFILL_COOLDOWN = 6 * 3600;     // re-sync an account at most this often
const MAX_BACKFILLS_PER_TICK = 5;       // keeps one tick short
const MAX_COUNT_FETCHES_PER_TICK = 10;
const COUNTS_TTL = 12 * 3600;           // how long remote counts are trusted
const TRACK_DAYS = 30;                  // keep showing counts for opened profiles this long
const OUTBOX_MAX_PAGES = 5;

const TRENDING_INTERVAL = 30 * 60;
const TRENDING_MAX_PER_RUN = 80;
const TRENDING_SOURCES = [
    // [name, url] - both return Mastodon-style status JSON lists
    ['pixelfed.social', 'https://pixelfed.social/api/pixelfed/v2/discover/posts/trending?range=daily'],
    ['mastodon.social', 'https://mastodon.social/api/v1/trends/statuses?limit=40'],
];

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

function already_stored(string $uri): bool
{
    return Status::withTrashed()
        ->where(fn ($q) => $q->where('uri', $uri)->orWhere('object_url', $uri)->orWhere('url', $uri))
        ->exists();
}

/** Mastodon-style status JSON → AP id if it is a public, non-reply post with media. */
function wanted_api_status(array $s, bool $skipSensitive): ?string
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

    return is_string($uri) && str_starts_with($uri, 'https://') ? $uri : null;
}

/**
 * Lists up to BACKFILL_LIMIT recent public media posts of a remote account,
 * newest first. Tries, in order: Pixelfed's public profile API (Pixelfed has
 * no usable outbox), Mastodon's public account API, then the ActivityPub outbox.
 */
function remote_recent_posts(Profile $p): array
{
    $host = remote_host($p);
    $acct = http_json("https://{$host}/api/v1/accounts/lookup?acct=".rawurlencode(local_part($p)));
    $rid = is_array($acct) ? ($acct['id'] ?? null) : null;

    if ($rid) {
        $sources = [
            "https://{$host}/api/pixelfed/v1/accounts/{$rid}/statuses?limit=".BACKFILL_LIMIT,
            "https://{$host}/api/v1/accounts/{$rid}/statuses?only_media=true&exclude_replies=true&exclude_reblogs=true&limit=".BACKFILL_LIMIT,
        ];
        foreach ($sources as $url) {
            $list = http_json($url);
            if (is_array($list) && array_is_list($list)) {
                $uris = array_values(array_filter(array_map(fn ($s) => is_array($s) ? wanted_api_status($s, false) : null, $list)));
                if ($uris) {
                    return array_slice($uris, 0, BACKFILL_LIMIT);
                }
            }
        }
    }

    return outbox_recent_posts($p);
}

function outbox_recent_posts(Profile $p): array
{
    $outbox = ap_json($p->outbox_url);
    if (! $outbox) {
        return [];
    }
    $page = $outbox['first'] ?? null;
    $page = is_string($page) ? ap_json($page) : (is_array($page) ? $page : $outbox);

    $uris = [];
    for ($i = 0; $page && $i < OUTBOX_MAX_PAGES && count($uris) < BACKFILL_LIMIT; $i++) {
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
                $uris[] = $obj['id'];
            }
        }
        $next = $page['next'] ?? null;
        $page = is_string($next) ? ap_json($next) : null;
    }

    return array_slice($uris, 0, BACKFILL_LIMIT);
}

/** Fetches the given AP ids through Pixelfed, oldest first so feed order stays right. */
function fetch_posts(array $uris): array
{
    $new = $failed = 0;
    foreach (array_reverse($uris) as $uri) {
        if (already_stored($uri)) {
            continue;
        }
        try {
            Helpers::statusFetch($uri) ? $new++ : $failed++;
        } catch (Throwable) {
            $failed++;
        }
    }

    return [$new, $failed];
}

/** Remote follower/following/post counts from the actor's collections, cached. */
function remote_counts(Profile $p, bool $refresh = false): ?array
{
    $key = KEY.'counts:'.$p->id;
    if (! $refresh && is_array($cached = Cache::get($key))) {
        return $cached;
    }
    $actor = ap_json($p->remote_url);
    if (! $actor) {
        return null;
    }
    $total = function ($url) {
        $c = is_string($url) ? ap_json($url) : (is_array($url) ? $url : null);

        return is_int($c['totalItems'] ?? null) ? $c['totalItems'] : null;
    };
    $counts = array_filter([
        'followers_count' => $total($actor['followers'] ?? null),
        'following_count' => $total($actor['following'] ?? null),
        'status_count' => $total($actor['outbox'] ?? null),
    ], fn ($v) => $v !== null);
    Cache::put($key, $counts, COUNTS_TTL);

    return $counts;
}

/**
 * Writes the remote counts onto the local profile. Pixelfed itself never reads
 * them and periodically resets status_count to the locally stored posts, so this
 * is re-applied on every tick for tracked profiles.
 */
function apply_counts(Profile $p, ?array $counts): bool
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

function backfill(Profile $p, string $why): void
{
    $uris = remote_recent_posts($p);
    [$new, $failed] = fetch_posts($uris);
    $counts = remote_counts($p, true);
    apply_counts($p->fresh(), $counts);
    Cache::put(KEY.'backfilled:'.$p->id, time(), BACKFILL_COOLDOWN);

    // Pixelfed only pushes posts younger than 7 days into an existing home
    // feed, so rebuild the feeds of local followers to include the backfill.
    if ($new > 0) {
        $local = Profile::whereNull('domain')->whereNotNull('user_id')->pluck('id');
        $followers = Follower::where('following_id', $p->id)->whereIn('profile_id', $local)->pluck('profile_id');
        foreach ($followers as $pid) {
            if (HomeTimelineService::cacheKeyExists($pid)) {
                HomeTimelineService::warmCache($pid, true, 400);
            }
        }
    }

    out(sprintf(
        'backfill %s (%s): %d listed, %d new, %d failed; counts %s',
        $p->username, $why, count($uris), $new, $failed, json_encode($counts)
    ));
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

function cmd_tick(array $inspected): void
{
    $now = time();
    $tracked = array_filter(Cache::get(KEY.'tracked', []), fn ($ts) => $ts > $now - TRACK_DAYS * 86400);
    $followed = followed_remote_ids();

    $inspectedProfiles = array_filter(array_map('remote_profile', array_unique($inspected)));
    foreach ($inspectedProfiles as $p) {
        $tracked[(string) $p->id] = $now;
    }
    foreach ($followed as $id) {
        $tracked[$id] = $now;
    }

    // Opened profiles first (someone is looking at them), then follows.
    $queue = [];
    foreach ($inspectedProfiles as $p) {
        $queue[(string) $p->id] = 'opened';
    }
    foreach ($followed as $id) {
        $queue[$id] ??= 'followed';
    }
    $done = 0;
    foreach ($queue as $id => $why) {
        if ($done >= MAX_BACKFILLS_PER_TICK) {
            break;
        }
        if (Cache::has(KEY.'backfilled:'.$id) || ! ($p = remote_profile($id))) {
            continue;
        }
        backfill($p, $why);
        $done++;
    }

    $fetches = 0;
    foreach (array_keys($tracked) as $id) {
        if (! ($p = remote_profile($id))) {
            unset($tracked[$id]);

            continue;
        }
        $cached = Cache::has(KEY.'counts:'.$id);
        if (! $cached && $fetches++ >= MAX_COUNT_FETCHES_PER_TICK) {
            continue;
        }
        if (apply_counts($p, remote_counts($p)) && ! $cached) {
            out("counts {$p->username}: ".json_encode(remote_counts($p)));
        }
    }

    Cache::forever(KEY.'tracked', $tracked);
}

function cmd_trending(bool $force): void
{
    if (! $force && Cache::has(KEY.'trending')) {
        return;
    }
    Cache::put(KEY.'trending', time(), TRENDING_INTERVAL - 30);

    $summary = [];
    $budget = TRENDING_MAX_PER_RUN;
    foreach (TRENDING_SOURCES as [$name, $url]) {
        $list = http_json($url);
        if (! is_array($list) || ! array_is_list($list)) {
            $summary[] = "{$name}: unavailable";

            continue;
        }
        $uris = array_values(array_filter(array_map(fn ($s) => is_array($s) ? wanted_api_status($s, true) : null, $list)));
        $uris = array_slice(array_values(array_filter($uris, fn ($u) => ! already_stored($u))), 0, max(0, $budget));
        [$new, $failed] = fetch_posts($uris);
        $budget -= $new + $failed;
        $summary[] = sprintf('%s: %d candidates, %d new, %d failed', $name, count($list), $new, $failed);
    }
    out('trending '.implode('; ', $summary));
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

function cmd_status(): void
{
    $tracked = Cache::get(KEY.'tracked', []);
    $followed = array_flip(followed_remote_ids());
    echo count($tracked).' tracked profiles'.PHP_EOL;
    foreach ($tracked as $id => $ts) {
        $p = remote_profile($id);
        if (! $p) {
            continue;
        }
        $last = Cache::get(KEY.'backfilled:'.$id);
        printf(
            "  %-40s %s  followers=%d following=%d posts=%d (stored %d)  last sync %s\n",
            $p->username,
            isset($followed[$id]) ? 'followed' : 'opened  ',
            $p->followers_count, $p->following_count, $p->status_count,
            Status::where('profile_id', $id)->count(),
            $last ? gmdate('Y-m-d H:i', (int) $last).'Z' : '-'
        );
    }
    $t = Cache::get(KEY.'trending');
    echo 'last trending run: '.($t ? gmdate('Y-m-d H:i', (int) $t).'Z' : '-').PHP_EOL;
}

$args = array_slice($argv, 1);
$cmd = array_shift($args) ?? 'status';
match ($cmd) {
    'tick' => cmd_tick(array_values(array_filter($args, 'ctype_digit'))),
    'trending' => cmd_trending(in_array('--force', $args, true)),
    'backfill' => isset($args[0]) ? cmd_backfill($args[0]) : out('usage: backfill <id|@user@domain>'),
    'status' => cmd_status(),
    default => out("unknown command {$cmd}"),
};
