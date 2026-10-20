<?php
declare(strict_types=1);
/** Remote custom emoji: Note tags, Misskey/Sharkey catalogs and the missing-code refresh guard. */
require __DIR__ . '/migration-reference-functions.php';
reference_load(dirname(__DIR__) . '/ap-db.php', ['ap_profile_sanitize_https_url', 'ap_as2_emoji_items', 'ap_remote_emoji_upsert_batch',
    'ap_remote_emoji_ingest_actor_doc', 'ap_remote_emoji_map_for_host', 'ap_remote_emoji_ensure_host', 'ap_remote_emoji_ensure_codes',
    'ap_emoji_shortcodes_in']);
reference_load(dirname(__DIR__) . '/ap-inbox.php', ['ap_fetched_note_emoji_ingest']);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$db->exec('CREATE TABLE remote_custom_emojis (host TEXT NOT NULL, shortcode TEXT NOT NULL, url TEXT NOT NULL, static_url TEXT, updated_at TEXT NOT NULL, PRIMARY KEY (host, shortcode))');
$db->exec("CREATE TABLE remote_emoji_host_meta (host TEXT PRIMARY KEY, fetched_at TEXT NOT NULL, source TEXT NOT NULL DEFAULT 'none')");
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_db_now(): string { return gmdate('c'); }
function ap_as_id(mixed $v): ?string { return is_string($v) ? $v : (is_array($v) && is_string($v['id'] ?? null) ? $v['id'] : null); }
function ap_remote_emoji_http_json(string $url): ?array { $GLOBALS['http'][] = $url; return $GLOBALS['http_docs'][$url] ?? null; }
function ap_remote_emoji_ensure_codes_deferred(string $host, array $codes): void { $GLOBALS['deferred'][] = [$host, $codes]; }
$checks = 0;
function re_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
try {
    // Sharkey-style Note tags: colons in names, icon as object or list; bad rows dropped.
    $items = ap_as2_emoji_items(['tag' => [
        ['type' => 'Emoji', 'name' => ':menhera_happy_hearts:', 'icon' => ['type' => 'Image', 'url' => 'https://cdn.transfem.social/m.webp']],
        ['type' => 'Emoji', 'name' => 'listicon', 'icon' => [['url' => 'https://cdn.transfem.social/l.png']]],
        ['type' => 'Emoji', 'name' => ':plain:', 'icon' => ['url' => 'http://cdn.transfem.social/p.png']],
        ['type' => 'Emoji', 'name' => ':bad code:', 'icon' => ['url' => 'https://cdn.transfem.social/b.png']],
        ['type' => 'Mention', 'name' => '@x'],
    ]]);
    re_check(array_column($items, 'shortcode') === ['menhera_happy_hearts', 'listicon'], 'tag parse');
    re_check(ap_emoji_shortcodes_in('<p>hi :a: 12:30 :b: :a:</p>') === ['a', 'b'], 'shortcode scan');

    // Every fetched Note caches its tags under the author host and defers a catalog check for untagged codes.
    ap_fetched_note_emoji_ingest(['type' => 'Create', 'object' => [
        'type' => 'Note', 'attributedTo' => 'https://transfem.social/users/9x',
        'content' => '<p>You\'re all wonderful :menhera_happy_hearts: :blobcat:</p>',
        'tag' => [['type' => 'Emoji', 'name' => ':menhera_happy_hearts:', 'icon' => ['url' => 'https://cdn.transfem.social/m.webp']]],
    ]]);
    $map = ap_remote_emoji_map_for_host('transfem.social');
    re_check(($map['menhera_happy_hearts'] ?? '') === 'https://cdn.transfem.social/m.webp', 'note tag cached by author host');
    re_check(($GLOBALS['deferred'][0] ?? null) === ['transfem.social', ['menhera_happy_hearts', 'blobcat']], 'catalog check deferred');
    ap_fetched_note_emoji_ingest(['type' => 'Person', 'attributedTo' => 'https://x.test/u', 'content' => ':zz:']);
    re_check(count($GLOBALS['deferred']) === 1, 'non-notes ignored');

    // Masto route empty -> Misskey /api/emojis fills the cache.
    $GLOBALS['http_docs']['https://transfem.social/api/emojis'] = ['emojis' => [
        ['name' => 'blobcat', 'url' => 'https://cdn.transfem.social/blobcat.png', 'aliases' => []],
        ['name' => 'nohttps', 'url' => 'http://cdn.transfem.social/x.png'],
    ]];
    $left = ap_remote_emoji_ensure_codes('transfem.social', ['menhera_happy_hearts', 'blobcat', 'neverheard'], true);
    re_check($left === ['neverheard'], 'misskey catalog resolved blobcat');
    re_check(in_array('https://transfem.social/api/v1/custom_emojis', $GLOBALS['http'], true), 'masto route tried first');
    $meta = $db->query("SELECT source FROM remote_emoji_host_meta WHERE host = 'transfem.social'")->fetchColumn();
    re_check($meta === 'misskey', 'source recorded');

    // Within the hour a still-unknown code does not refetch the catalog.
    $before = count($GLOBALS['http']);
    re_check(ap_remote_emoji_ensure_codes('transfem.social', ['neverheard'], true) === ['neverheard'], 'still missing');
    re_check(count($GLOBALS['http']) === $before, 'hourly guard');
    // Cache-only call never fetches.
    re_check(ap_remote_emoji_ensure_codes('other.test', ['x'], false) === ['x'] && count($GLOBALS['http']) === $before, 'render path offline');
    // After the hour it refetches.
    $db->exec("UPDATE remote_emoji_host_meta SET fetched_at = '2020-01-01T00:00:00+00:00'");
    ap_remote_emoji_ensure_codes('transfem.social', ['neverheard'], true);
    re_check(count($GLOBALS['http']) > $before, 'stale meta refetches');

    // Mastodon catalogs still win when they answer.
    $GLOBALS['http_docs']['https://masto.test/api/v1/custom_emojis'] = [['shortcode' => 'blobfox', 'url' => 'https://files.masto.test/f.png', 'static_url' => 'https://files.masto.test/f-s.png']];
    re_check(ap_remote_emoji_ensure_codes('masto.test', ['blobfox'], true) === [], 'masto catalog');
    re_check(!in_array('https://masto.test/api/emojis', $GLOBALS['http'], true), 'misskey route skipped when masto answers');
} catch (Throwable $e) {
    fwrite(STDERR, 'remote-emoji-smoke: FAIL ' . $e->getMessage() . "\n");
    exit(1);
}
echo "remote-emoji-smoke: OK ({$checks} checks)\n";
