<?php
declare(strict_types=1);
/** Offline PHP reference execution. Loads selected function declarations only;
 * never bootstrap ap-admin.php, credentials, HTTP, Redis, or a real database.
 * Stubbed boundaries deliberately do NOT establish complete page/card parity.
 */
require_once __DIR__ . '/migration-reference-functions.php';
require_once __DIR__ . '/../ap-library-native.php';
putenv('VAAK_LIBRARY_NATIVE_RENDER=0');
function check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
function admin_render_library_status_card(array $st, string $view): void { echo '<fixture-fedi id="' . htmlspecialchars((string)($st['id'] ?? ''), ENT_QUOTES) . '" view="' . $view . '"></fixture-fedi>'; }
function admin_render_bsky_feed_item(array $st, string $context, string $view): void { echo '<fixture-bsky></fixture-bsky>'; }
function ap_masto_lookup_status_by_object_url(string $uri, int $unused, bool $fetch): ?array { $GLOBALS['lookup_uri'] = $uri; return $GLOBALS['cached'] ?? null; }
function ap_masto_ensure_remote_note_event(string $uri): ?array { $GLOBALS['fetch_calls']++; return $GLOBALS['remote'] ?? null; }
function ap_normalize_from_activitypub_event(array $event): array { return $event; }
function ap_is_blocked_actor(string $a): bool { return $a === ($GLOBALS['server_block'] ?? ''); }
function ap_is_globally_muted_actor(string $a): bool { return $a === ($GLOBALS['server_mute'] ?? ''); }
function ap_user_is_blocked(string $a, ?string $host, int $owner): bool { return $owner === 2 && $a === 'https://remote.test/users/blocked'; }
function ap_is_muted_actor(string $a, int $owner): bool { return $owner === 2 && $a === 'https://remote.test/users/muted'; }
function ap_muted_words_match(int $owner, string $content, string $cw): ?array { return $owner === 2 && str_contains($content . $cw, 'forbidden') ? ['id' => 1] : null; }
function ap_db_owner_actor_id_for_user_id(int $id): string { return 'https://mkultra.monster/users/viewer' . $id; }
class ReferenceQuery {
    function __construct(private string $sql) {}
    function execute(array $params): bool { return true; }
    function fetch(): mixed {
        if (str_contains($this->sql, 'FROM outbox_notes')) return $GLOBALS['policy']['local'] ?? false;
        if (str_contains($this->sql, 'FROM events')) return $GLOBALS['policy']['event'] ?? false;
        return false;
    }
    function fetchColumn(): mixed {
        if (str_contains($this->sql, 'FROM mentions')) return $GLOBALS['policy']['mention'] ?? false;
        return $GLOBALS['policy']['follows'] ?? false;
    }
}
class ReferenceDatabase {
    function prepare(string $sql): ReferenceQuery {
        if (!empty($GLOBALS['policy']['unavailable'])) throw new RuntimeException('fixture unavailable');
        return new ReferenceQuery($sql);
    }
}
function ap_db(): ReferenceDatabase { return new ReferenceDatabase(); }

$GLOBALS['checks'] = 0; $GLOBALS['fetch_calls'] = 0;
$api = dirname(__DIR__);
reference_load($api . '/ap-admin.php', ['admin_render_library_entries', 'admin_render_library_network_items', 'admin_library_status_is_thin', 'admin_library_status_enrich', 'admin_bookmark_fragment_markup', 'admin_bookmark_mixed_markup', 'admin_bookmark_bsky_at_uri']);
reference_load($api . '/ap-visibility.php', ['ap_visibility_actor_hidden', 'ap_visibility_status_hidden', 'ap_visibility_status_key', 'ap_visibility_filter_statuses', 'ap_visibility_public_status', 'ap_visibility_status_audience_allowed']);
$full = ['id'=>'remote-id', 'uri'=>'https://remote.test/notes/1', 'content'=>'actual body', 'account'=>['acct'=>'alice@remote.test']];
check(!admin_library_status_is_thin($full), 'full library status');
check(admin_library_status_is_thin(['content'=>'https://remote.test/notes/1', 'uri'=>'https://remote.test/notes/1']), 'URL stub');
check(!admin_library_status_is_thin(['media_attachments'=>[['id'=>'media']]]), 'media-only post');
$stub = ['id'=>'interaction-7', 'uri'=>'https://remote.test/notes/1/#announce-42', 'vaak_library_stub'=>true, 'favourited'=>true, 'bookmarked'=>true];
$GLOBALS['cached'] = ['reblog'=>$full];
$enriched = admin_library_status_enrich($stub);
check($enriched['id'] === 'interaction-7' && $enriched['favourited'] && $enriched['bookmarked'], 'stable interaction id and flags');
check($GLOBALS['lookup_uri'] === 'https://remote.test/notes/1' && !isset($enriched['vaak_library_stub']), 'announce strip and unwrap');
$GLOBALS['cached'] = null; $GLOBALS['remote'] = null; $GLOBALS['admin_library_fetch_budget'] = 1;
admin_library_status_enrich($stub); admin_library_status_enrich($stub);
check($GLOBALS['fetch_calls'] === 1 && $GLOBALS['admin_library_fetch_budget'] === 0, 'remote hydrate bounded');
check(admin_library_status_enrich(['vaak_library_stub'=>true,'uri'=>'http://remote.test/1'])['vaak_library_stub'], 'non-HTTPS never remote fetched');
$samples = [];
foreach (['fedi','bsky'] as $network) foreach ([0,4] as $folder) foreach ([false,true] as $refresh) {
    $html = admin_bookmark_fragment_markup($network, [], 20, 20, false, $folder, true, $refresh);
    check(str_contains($html, 'data-offset="20"') && str_contains($html, 'class="empty"'), 'first-page empty envelope');
    $samples[$network . ':folder=' . $folder . ':refresh=' . (int)$refresh] = $html;
}
check(!str_contains(admin_bookmark_fragment_markup('fedi', [], 40, 20, false, 0, false, false), 'class="empty"'), 'later empty page has no first-page notice');
check(admin_bookmark_bsky_at_uri('https://bsky.app/profile/did:plc:abc/post/key') === 'at://did:plc:abc/app.bsky.feed.post/key', 'DID URL conversion');
check(admin_bookmark_bsky_at_uri('https://bsky.app/profile/handle.test/post/key') === '', 'handle URL not guessed as DID');
$samples['mixed:populated'] = admin_bookmark_mixed_markup([['kind'=>'fedi','item'=>$full],['kind'=>'bsky','item'=>[]]], 23, 20, true, 0, true);
check(str_contains($samples['mixed:populated'], 'data-offset="23"') && !$GLOBALS['admin_bookmark_lazy_media'], 'source cursor and lazy-media reset');
$status = $full + ['visibility'=>'public'];
$status['account']['uri'] = 'https://remote.test/users/blocked';
check(!ap_visibility_status_hidden($status, 1) && ap_visibility_status_hidden($status, 2), 'same cached card per-owner block');
$status['account']['uri'] = 'https://remote.test/users/muted';
check(ap_visibility_status_hidden($status, 2), 'owner actor mute');
$GLOBALS['server_block'] = $status['account']['uri'];
check(ap_visibility_status_hidden($status, 0), 'server block for guest'); $GLOBALS['server_block'] = '';
$GLOBALS['server_mute'] = $status['account']['uri'];
check(ap_visibility_status_hidden($status, 0), 'server mute for guest'); $GLOBALS['server_mute'] = '';
check(ap_visibility_status_hidden($full + ['spoiler_text'=>'forbidden'], 2), 'CW muted phrase');
foreach (['quote','reblog','vaak_quote_preview'] as $field) check(ap_visibility_status_hidden($full + [$field=>$status], 2), 'nested moderation ' . $field);
check(!ap_visibility_public_status($full + ['visibility'=>'private']), 'private discovery excluded');
check(!ap_visibility_public_status($full + ['quote'=>['visibility'=>'direct']]), 'restricted quote discovery excluded');
check(ap_visibility_public_status($full + ['quote'=>['visibility'=>'unlisted']]), 'unlisted nested allowed under public parent');
check(count(ap_visibility_filter_statuses([$full, $full], 1)) === 1, 'dedupe');
$GLOBALS['policy'] = ['event'=>['visibility'=>'private','actor_id'=>'https://remote.test/users/author']];
check(!ap_visibility_status_audience_allowed($full, 0) && !ap_visibility_status_audience_allowed($full, 1), 'cached public cannot override durable private');
$GLOBALS['policy']['follows'] = true;
check(ap_visibility_status_audience_allowed($full, 1), 'current follower grant');
$GLOBALS['policy']['follows'] = false; $GLOBALS['policy']['mention'] = true;
check(ap_visibility_status_audience_allowed($full, 1), 'owner delivered mention grant');
$GLOBALS['policy'] = ['local'=>['visibility'=>'direct','to_json'=>'["https://mkultra.monster/users/viewer1"]','cc_json'=>'[]']];
check(ap_visibility_status_audience_allowed($full, 1) && !ap_visibility_status_audience_allowed($full, 2), 'direct recipient ownership');
$GLOBALS['policy'] = [];
check(!ap_visibility_status_audience_allowed(['uri'=>'https://mkultra.monster/users/local/notes/deleted'], 1), 'deleted local note cannot resurrect');
$GLOBALS['policy'] = ['unavailable'=>true];
check(!ap_visibility_status_audience_allowed($full, 1), 'policy DB failure closed');
if (($argv[1] ?? '') === '--json') echo json_encode(['checks'=>$GLOBALS['checks'], 'coverage'=>'selected PHP functions; renderer/database boundaries stubbed; not complete parity', 'bookmark_envelopes'=>$samples], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
else echo 'migration-php-reference: PASS (' . $GLOBALS['checks'] . " checks; partial reference coverage, no full parity claim)\n";
