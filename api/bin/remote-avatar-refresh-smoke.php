#!/usr/bin/env php
<?php
declare(strict_types=1);

function vaak_extract_function(string $source, string $name): string
{
    if (!preg_match('/^function ' . preg_quote($name, '/') . '\(/m', $source, $m, PREG_OFFSET_CAPTURE)) {
        throw new RuntimeException('missing ' . $name);
    }
    $start = (int) $m[0][1];
    $line = strrpos(substr($source, 0, $start), "\n");
    $start = $line === false ? 0 : $line + 1;
    $len = strlen($source);
    $i = strpos($source, '{', $start);
    if ($i === false) {
        throw new RuntimeException('no body ' . $name);
    }
    $depth = 0;
    $state = '';
    for ($j = $i; $j < $len; $j++) {
        $c = $source[$j];
        $next = $j + 1 < $len ? $source[$j + 1] : '';
        if ($state === '') {
            if ($c === "'" || $c === '"') {
                $state = $c;
                continue;
            }
            if ($c === '/' && $next === '/') {
                $state = 'line';
                continue;
            }
            if ($c === '/' && $next === '*') {
                $state = 'block';
                $j++;
                continue;
            }
            if ($c === '{') {
                $depth++;
            } elseif ($c === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $j - $start + 1);
                }
            }
        } elseif ($state === 'line') {
            if ($c === "\n") {
                $state = '';
            }
        } elseif ($state === 'block') {
            if ($c === '*' && $next === '/') {
                $state = '';
                $j++;
            }
        } else {
            if ($c === '\\') {
                $j++;
                continue;
            }
            if ($c === $state) {
                $state = '';
            }
        }
    }
    throw new RuntimeException('unclosed ' . $name);
}

function vaak_eval_functions(string $path, array $names): void
{
    $source = (string) file_get_contents($path);
    foreach ($names as $name) {
        eval(vaak_extract_function($source, $name));
    }
}

/*
 * Remote avatars: "Refresh profile" and failed actor-document fetches.
 *
 * Before the fix the media warm only ran when no cached row existed, never
 * with force, so Refresh profile could not replace a cached avatar; and when
 * the warmer's own actor-document fetch failed it gave up ("Actor has no
 * cached avatar/header") even though remote_actors already had icon_source_url.
 */
$root = dirname(__DIR__);
define('AP_MEDIA_WARM_FORCE_KIND', 'avatar_header_force');
vaak_eval_functions($root . '/ap-r2.php', [
    'ap_remote_media_known_source', 'ap_remote_media_ensure', 'ap_media_warm_enqueue_actor',
    'ap_remote_media_warm_async', 'ap_media_warm_worker_run',
]);
$r2 = (string) file_get_contents($root . '/ap-r2.php');

$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec("CREATE TABLE ap_media_warm_queue (id INTEGER PRIMARY KEY AUTOINCREMENT, job_type TEXT NOT NULL,
  target_key TEXT NOT NULL, media_kind TEXT NOT NULL, status TEXT NOT NULL, attempts INTEGER NOT NULL DEFAULT 0,
  max_attempts INTEGER NOT NULL DEFAULT 5, next_attempt_at TEXT NOT NULL, claimed_at TEXT, last_error TEXT,
  created_at TEXT NOT NULL, updated_at TEXT NOT NULL, UNIQUE(job_type, target_key, media_kind))");
$db->exec("CREATE TABLE remote_media_cache (id INTEGER PRIMARY KEY AUTOINCREMENT, actor_id TEXT, kind TEXT,
  source_url TEXT, s3_key TEXT, public_url TEXT, content_type TEXT, byte_size INTEGER, fetched_at TEXT,
  last_used_at TEXT, UNIQUE(actor_id, kind))");
$GLOBALS['fixture_db'] = $db;
$GLOBALS['fixture_actors'] = [];
$GLOBALS['fixture_downloads'] = [];
$GLOBALS['fixture_force_seen'] = [];
$GLOBALS['fixture_nudges'] = 0;

function ap_db(): PDO { return $GLOBALS['fixture_db']; }
function ap_db_now(): string { return gmdate('c'); }
function ap_redis_client(string $kind): ?object { return null; }
function ap_feature_enabled(string $name, bool $default): bool { return true; }
function ap_media_warm_worker_nudge(): void { $GLOBALS['fixture_nudges']++; }
function ap_profile_sanitize_https_url(?string $url): ?string { return is_string($url) && str_starts_with($url, 'https://') ? $url : null; }
function ap_account_media_resolve(mixed $url, ?string $actor = null): ?string { return is_string($url) ? ap_profile_sanitize_https_url($url) : null; }
function ap_remote_actor_get(string $actor): ?array { return $GLOBALS['fixture_actors'][$actor] ?? null; }
function ap_remote_actor_upsert(string $actor, array $fields): void { $GLOBALS['fixture_upserts'][] = $actor; }
function ap_remote_actor_ensure(string $actor, bool $allowFetch = true): ?array
{
    $GLOBALS['fixture_force_seen'][] = !empty($GLOBALS['ap_remote_actor_force_refresh']);
    return ap_remote_actor_get($actor);
}
function ap_fetch_actor_doc(string $actor): ?array { return null; } // remote host refuses the fetch
function ap_remote_media_get(string $actor, string $kind): ?array
{
    $st = ap_db()->prepare('SELECT * FROM remote_media_cache WHERE actor_id = ? AND kind = ?');
    $st->execute([$actor, $kind]);
    return $st->fetch() ?: null;
}
function ap_remote_media_touch(string $actor, string $kind): void {}
function ap_remote_media_download_slot(string $host): ?array { return ['global' => null, 'host' => null]; }
function ap_remote_media_download_slot_release(?array $slot): void {}
function ap_remote_media_download(string $url, float $timeout = 4.0): array
{
    $GLOBALS['fixture_downloads'][] = $url;
    return ['ok' => true, 'body' => 'jpegbytes', 'content_type' => 'image/jpeg'];
}
function ap_r2_put_object(string $key, string $body, string $type): array
{
    return ['ok' => true, 'key' => 'mkultra/' . $key, 'public_url' => 'https://media.example/mkultra/' . $key];
}
function ap_r2_delete_object(string $key): array { return ['ok' => true]; }
function ap_media_warm_lock_path(): string { return sys_get_temp_dir() . '/vaak-remote-avatar-refresh-smoke-' . getmypid() . '.lock'; }

$n = 0;
$check = static function (bool $ok, string $label) use (&$n): void {
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $n++;
};
$jobs = static function (string $actor) use ($db): array {
    $st = $db->prepare('SELECT media_kind, status FROM ap_media_warm_queue WHERE target_key = ? ORDER BY media_kind');
    $st->execute([$actor]);
    return $st->fetchAll();
};
$runAll = static function (): void {
    $db = ap_db();
    $db->exec("UPDATE ap_media_warm_queue SET next_attempt_at = '2000-01-01T00:00:00+00:00' WHERE status = 'pending'");
    ap_media_warm_worker_run(10);
};

$tag = getmypid() . '-' . bin2hex(random_bytes(3));
$actor = 'https://wafrn.example/fediverse/blog/cat' . $tag;
$icon = 'https://media.wafrn.example/avatar-' . $tag . '.webp';
$GLOBALS['fixture_actors'][$actor] = ['icon_source_url' => $icon, 'image_source_url' => null, 'updated_at' => gmdate('c')];

// 1) Actor document fetch fails, remote_actors knows the icon: it is cached.
ap_remote_media_warm_async($actor);
$check($jobs($actor) === [['media_kind' => 'avatar_header', 'status' => 'pending']], 'plain warm queues the normal job');
$runAll();
$a = ap_remote_media_get($actor, 'avatar');
$check(is_array($a) && $a['source_url'] === $icon, 'avatar cached from the stored icon URL when the actor doc fetch fails');
$check(str_starts_with((string) $a['public_url'], 'https://media.example/mkultra/cache/avatar/'), 'avatar has an R2 copy');
$h = ap_remote_media_get($actor, 'header');
$check(is_array($h) && $h['source_url'] === $icon, 'missing header still falls back to the avatar');
$check($GLOBALS['fixture_force_seen'] === [false], 'a plain warm does not force the actor refetch');

// 2) A plain warm with a fresh cached copy downloads nothing.
$GLOBALS['fixture_downloads'] = [];
ap_remote_media_warm_async($actor);
$runAll();
$check($GLOBALS['fixture_downloads'] === [], 'fresh cache with the same source is reused without force');

// 3) Refresh profile: forced job, forced actor refetch, both images downloaded again.
$GLOBALS['fixture_force_seen'] = [];
ap_remote_media_warm_async($actor, true);
$kinds = array_column($jobs($actor), 'media_kind');
$check(in_array('avatar_header_force', $kinds, true), 'refresh queues the forced job kind');
$runAll();
$check($GLOBALS['fixture_force_seen'] === [true], 'forced job refetches the actor past the 12 h gate');
$check(count($GLOBALS['fixture_downloads']) === 2, 'forced job downloads avatar and header again, got ' . count($GLOBALS['fixture_downloads']));
$check(empty($GLOBALS['ap_remote_actor_force_refresh']), 'force flag does not leak into later jobs');

// 4) A finished forced job can be queued again by the next refresh.
ap_remote_media_warm_async($actor, true);
$forced = array_values(array_filter($jobs($actor), static fn ($r) => $r['media_kind'] === 'avatar_header_force'));
$check(($forced[0]['status'] ?? '') === 'pending', 'second refresh re-arms the forced job');

// 5) Nothing known anywhere: no download, nothing cached.
$bare = 'https://wafrn.example/fediverse/blog/none' . $tag;
$GLOBALS['fixture_downloads'] = [];
$check(ap_remote_media_ensure($bare, 'avatar', false) === null, 'no doc and no stored icon returns null');
$check($GLOBALS['fixture_downloads'] === [], 'nothing downloaded without a source');

// 6) Remote profile wiring: Refresh forces both warmers; a replaced avatar re-warms.
$admin = (string) file_get_contents($root . '/ap-admin.php');
$check(str_contains($admin, 'ap_remote_actor_warm_async($rpActor, $rpForceRefresh);'), 'Refresh profile forces the actor warm');
$check(str_contains($admin, 'ap_remote_media_warm_async($rpActor, $rpForceRefresh);'), 'Refresh profile forces the media warm');
$check(str_contains($admin, '$rpForceRefresh || !$ca || !$ch || $rpAvatarStale'), 'refresh or a stale avatar queues the media warm');

// 7) Wafrn documents without icon/image borrow the linked Bluesky avatar.
vaak_eval_functions($root . '/ap-db.php', ['ap_remote_actor_doc_dids', 'ap_remote_actor_did_twin_media']);
$wafrnDoc = ['type' => 'Person', 'preferredUsername' => 'cat',
    'alsoKnownAs' => ['https://wafrn.example/fediverse/blog/cat', 'at://did:plc:abc123xyz']];
$check(ap_remote_actor_doc_dids($wafrnDoc) === ['did:plc:abc123xyz'], 'at:// alias yields the DID');
$check(ap_remote_actor_doc_dids(['alsoKnownAs' => 'https://evil.example/did:plc:abc']) === [], 'a DID buried in a URL path is not trusted');
$check(ap_remote_actor_doc_dids(['type' => 'Person']) === [], 'no alsoKnownAs, no DIDs');
$GLOBALS['fixture_did_fetches'] = [];
$GLOBALS['ap_remote_actor_did_profile_fetch'] = static function (string $did): ?array {
    $GLOBALS['fixture_did_fetches'][] = $did;
    return ['did' => $did, 'avatar' => 'https://cdn.bsky.example/img/avatar/plain/' . $did . '/a@jpeg',
        'banner' => 'https://cdn.bsky.example/img/banner/plain/' . $did . '/b@jpeg'];
};
$twin = ap_remote_actor_did_twin_media($wafrnDoc);
$check($twin['avatar'] === 'https://cdn.bsky.example/img/avatar/plain/did:plc:abc123xyz/a@jpeg', 'twin avatar comes from the linked Bluesky profile');
$check(is_string($twin['banner']), 'twin banner comes along');
$check($GLOBALS['fixture_did_fetches'] === ['did:plc:abc123xyz'], 'one profile lookup for one DID');
$GLOBALS['ap_remote_actor_did_profile_fetch'] = static fn (string $did): ?array => ['did' => $did];
$check(ap_remote_actor_did_twin_media($wafrnDoc) === ['avatar' => null, 'banner' => null], 'linked profile without an avatar gives nothing');
unset($GLOBALS['ap_remote_actor_did_profile_fetch']);
$dbSrc = (string) file_get_contents($root . '/ap-db.php');
$ensure = vaak_extract_function($dbSrc, 'ap_remote_actor_ensure');
$check(str_contains($ensure, '$twin = ap_remote_actor_did_twin_media($doc);'), 'actor refresh falls back to the Bluesky twin when the doc has no icon');
$check(str_contains(vaak_extract_function($r2, 'ap_remote_media_ensure'), "if (\$iconSrc === null) {"), 'media warm uses the stored twin avatar when the doc has none');

@unlink(ap_media_warm_lock_path());
echo "remote-avatar-refresh-smoke ok checks={$n}\n";
