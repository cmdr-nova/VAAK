<?php
declare(strict_types=1);

// Composer reply summaries: local rows only, hidden posts stay hidden, and
// stored HTML never comes back as markup.
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo'] = $db;
$GLOBALS['profiles'] = [
    'cmdr_nova' => ['name' => 'Nova', 'icon_url' => 'https://cdn.example/nova.webp'],
    'shana_flux' => ['name' => 'Shana Flux', 'icon_url' => 'https://cdn.example/shana.webp'],
];
$GLOBALS['actors'] = [
    'https://remote.example/users/ada' => [
        'username' => 'ada',
        'display_name' => 'Ada <b>Lovelace</b>',
        'host' => 'remote.example',
        'icon_source_url' => 'https://cdn.example/ada.webp',
    ],
];
$GLOBALS['blocked'] = [];
$GLOBALS['muted'] = [];
$GLOBALS['global_muted'] = [];
$GLOBALS['mute_word'] = '';

function ap_db(): PDO { return $GLOBALS['pdo']; }
function vaak_actor_id(): string { return 'https://mkultra.monster/users/cmdr_nova'; }
function admin_owner_user_id(): int { return 7; }
function vaak_is_own_url(string $url): bool
{
    return str_starts_with(rtrim($url, '/'), 'https://mkultra.monster/users/cmdr_nova');
}
function ap_profile_display_name(string $key, bool $vanity = true): string
{
    return (string) ($GLOBALS['profiles'][$key]['name'] ?? '');
}
function ap_profile_get(string $key): ?array
{
    return $GLOBALS['profiles'][$key] ?? null;
}
function ap_remote_actor_get(string $id): ?array
{
    return $GLOBALS['actors'][rtrim($id, '/')] ?? null;
}
function ap_is_blocked_actor(?string $id): bool
{
    return in_array(rtrim((string) $id, '/'), $GLOBALS['blocked'], true);
}
function ap_is_muted_actor(?string $id, int $owner): bool
{
    return $owner === 7 && in_array(rtrim((string) $id, '/'), $GLOBALS['muted'], true);
}
function ap_is_globally_muted_actor(?string $id, ?string $host = null): bool
{
    return in_array(rtrim((string) $id, '/'), $GLOBALS['global_muted'], true);
}
function ap_is_blocked_host(?string $host): bool { return false; }
function ap_muted_words_match(int $owner, string ...$texts): ?string
{
    $word = (string) ($GLOBALS['mute_word'] ?? '');
    if ($owner !== 7 || $word === '') {
        return null;
    }
    return str_contains(strtolower(implode("\n", $texts)), strtolower($word)) ? $word : null;
}
function ap_bsky_post_is_sensitive(array $post): bool
{
    foreach ((array) ($post['labels'] ?? []) as $label) {
        if (is_array($label) && ($label['val'] ?? '') === 'sexual') {
            return true;
        }
    }
    return false;
}
function ap_masto_actor_url_from_object_url(string $url): ?string
{
    if (preg_match('#^(https://[^/]+)/users/([^/]+)/(?:statuses|notes)/#', rtrim($url, '/'), $m)) {
        return $m[1] . '/users/' . $m[2];
    }
    return null;
}
function ap_fetch_as2_object(string $url): array { throw new RuntimeException('remote fetch ' . $url); }
function ap_bsky_resolve_handle_did(string $handle, int $owner = 0): ?string { throw new RuntimeException('bsky fetch ' . $handle); }
function ap_remote_actor_warm_async(string $id): void { throw new RuntimeException('warm ' . $id); }

require dirname(__DIR__) . '/ap-reply-preview.php';

$db->exec(<<<'SQL'
CREATE TABLE masto_statuses (
  local_id INTEGER PRIMARY KEY,
  note_id TEXT,
  content_text TEXT,
  visibility TEXT,
  spoiler_text TEXT,
  sensitive INTEGER
);
CREATE TABLE masto_media (local_id INTEGER PRIMARY KEY, status_local_id INTEGER, public_url TEXT);
CREATE TABLE outbox_notes (id TEXT PRIMARY KEY, content TEXT, to_json TEXT, cc_json TEXT);
CREATE TABLE events (
  id INTEGER PRIMARY KEY,
  type TEXT,
  actor_id TEXT,
  object_id TEXT,
  summary TEXT,
  media_urls TEXT,
  spoiler_text TEXT,
  sensitive INTEGER,
  visibility TEXT,
  target_actor TEXT,
  action_taken TEXT
);
CREATE TABLE following (owner_actor_id TEXT, actor_id TEXT);
CREATE TABLE mentions (
  id INTEGER PRIMARY KEY,
  owner_user_id INTEGER,
  actor_id TEXT,
  object_id TEXT,
  content TEXT,
  media_urls TEXT,
  spoiler_text TEXT,
  sensitive INTEGER,
  deleted_at TEXT,
  activity_id TEXT
);
CREATE TABLE bsky_posts (
  bsky_uri TEXT PRIMARY KEY,
  author_did TEXT,
  author_handle TEXT,
  author_display TEXT,
  author_avatar TEXT,
  text TEXT,
  embed_json TEXT,
  raw_json TEXT
);
CREATE TABLE bsky_crossposts (note_id TEXT, bsky_uri TEXT);
SQL);

$checks = 0;
function preview_check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

$own = 'https://mkultra.monster/users/cmdr_nova/notes/own1';
$peer = 'https://mkultra.monster/users/shana_flux/notes/peer1';
$remote = 'https://remote.example/users/ada/statuses/9';
$direct = 'https://remote.example/users/ada/statuses/dm';
$private = 'https://remote.example/users/ada/statuses/followers';
$quote = 'https://remote.example/users/ada/statuses/quote';
$boost = 'https://remote.example/users/ada/statuses/boosted';
$xss = 'https://remote.example/users/ada/statuses/xss';
$mutedWord = 'https://remote.example/users/ada/statuses/word';
$bsky = 'at://did:plc:ada/app.bsky.feed.post/backfill1';
$bskyHttps = 'https://bsky.app/profile/ada.bsky.social/post/backfill1';

$db->prepare('INSERT INTO masto_statuses (local_id, note_id, content_text, visibility, spoiler_text, sensitive) VALUES (?,?,?,?,?,?)')
    ->execute([1, $own, 'continuing the thread', 'public', '', 0]);
$db->prepare('INSERT INTO masto_media (status_local_id, public_url) VALUES (?,?)')->execute([1, 'https://cdn.example/pic.webp']);
$db->prepare('INSERT INTO masto_statuses (local_id, note_id, content_text, visibility, spoiler_text, sensitive) VALUES (?,?,?,?,?,?)')
    ->execute([2, $peer, '<img src=x onerror=alert(1)>hello from shana', 'private', 'spoilers ahead', 1]);
$db->prepare('INSERT INTO following (owner_actor_id, actor_id) VALUES (?,?)')
    ->execute(['https://mkultra.monster/users/cmdr_nova', 'https://mkultra.monster/users/shana_flux']);

$db->prepare('INSERT INTO events (id, type, actor_id, object_id, summary, media_urls, spoiler_text, sensitive, visibility, target_actor, action_taken) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([1, 'Create', 'https://remote.example/users/ada', $remote, '<p>Hello <b>there</b></p>', '["https://cdn.example/a.jpg"]', '', 0, 'public', '', '']);
$db->prepare('INSERT INTO events (id, type, actor_id, object_id, summary, media_urls, spoiler_text, sensitive, visibility, target_actor, action_taken) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([2, 'Create', 'https://remote.example/users/ada', $direct, 'secret dm', '[]', '', 0, 'direct', '', '']);
$db->prepare('INSERT INTO events (id, type, actor_id, object_id, summary, media_urls, spoiler_text, sensitive, visibility, target_actor, action_taken) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([3, 'Create', 'https://remote.example/users/ada', $private, 'followers only body', '[]', '', 0, 'private', '', '']);
$db->prepare('INSERT INTO events (id, type, actor_id, object_id, summary, media_urls, spoiler_text, sensitive, visibility, target_actor, action_taken) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([4, 'Announce', 'https://remote.example/users/booster', $boost, 'should not win', '[]', '', 0, 'public', '', '']);
$db->prepare('INSERT INTO events (id, type, actor_id, object_id, summary, media_urls, spoiler_text, sensitive, visibility, target_actor, action_taken) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([5, 'Create', 'https://remote.example/users/ada', $boost, 'the boosted post', '[]', '', 0, 'public', '', '']);
$db->prepare('INSERT INTO events (id, type, actor_id, object_id, summary, media_urls, spoiler_text, sensitive, visibility, target_actor, action_taken) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([6, 'Create', 'https://remote.example/users/ada', $quote, "look at this\n\n↪ QT @other@host: quoted secret", '[]', '', 0, 'public', '', '']);
$db->prepare('INSERT INTO events (id, type, actor_id, object_id, summary, media_urls, spoiler_text, sensitive, visibility, target_actor, action_taken) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([7, 'Create', 'https://remote.example/users/ada', $xss, '<img src=x onerror=alert(1)>plain &amp; safe', '[]', '', 0, 'public', '', '']);
$db->prepare('INSERT INTO events (id, type, actor_id, object_id, summary, media_urls, spoiler_text, sensitive, visibility, target_actor, action_taken) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([8, 'Create', 'https://remote.example/users/ada', $mutedWord, 'contains the forbidden phrase', '[]', '', 0, 'public', '', '']);
$db->prepare('INSERT INTO events (id, type, actor_id, object_id, summary, media_urls, spoiler_text, sensitive, visibility, target_actor, action_taken) VALUES (?,?,?,?,?,?,?,?,?,?,?)')
    ->execute([9, 'Announce', 'https://remote.example/users/booster', 'https://remote.example/users/ada/statuses/announce-only', 'boosted without a create row', '["https://cdn.example/b.jpg"]', 'cw on boost', 1, 'public', '', '']);

$bskyRaw = json_encode([
    'uri' => $bsky,
    'labels' => [['val' => 'sexual']],
    'record' => ['text' => ''],
    'embed' => ['$type' => 'app.bsky.embed.images', 'images' => [['alt' => 'sky']]],
], JSON_UNESCAPED_SLASHES);
$db->prepare('INSERT INTO bsky_posts (bsky_uri, author_did, author_handle, author_display, author_avatar, text, embed_json, raw_json) VALUES (?,?,?,?,?,?,?,?)')
    ->execute([$bsky, 'did:plc:ada', 'ada.bsky.social', 'Ada Sky', 'https://cdn.example/bsky.webp', 'backfilled bluesky text', '', $bskyRaw]);

$db->prepare('INSERT INTO bsky_crossposts (note_id, bsky_uri) VALUES (?,?)')
    ->execute([$own, 'at://did:plc:nova/app.bsky.feed.post/mirror1']);

$ownPreview = admin_reply_preview_lookup($own);
preview_check(is_array($ownPreview), 'own local post resolves');
preview_check(($ownPreview['name'] ?? '') === 'Nova', 'own display name');
preview_check(($ownPreview['handle'] ?? '') === '@cmdr_nova', 'own handle');
preview_check(($ownPreview['avatar'] ?? '') === 'https://cdn.example/nova.webp', 'own avatar from profile');
preview_check(($ownPreview['snippet'] ?? '') === 'continuing the thread', 'own snippet');
preview_check(!empty($ownPreview['media']) && empty($ownPreview['cw']), 'own media note, no cw');

$peerPreview = admin_reply_preview_lookup($peer);
preview_check(is_array($peerPreview), 'followed local peer resolves');
preview_check(($peerPreview['name'] ?? '') === 'Shana Flux', 'peer display name');
preview_check(($peerPreview['handle'] ?? '') === '@shana_flux', 'peer bare local handle');
preview_check(($peerPreview['snippet'] ?? '') === 'hello from shana', 'peer html stripped to text');
preview_check(!empty($peerPreview['cw']), 'peer content warning');
preview_check(!str_contains((string) ($peerPreview['snippet'] ?? ''), '<script>'), 'peer snippet has no tag');

$GLOBALS['following_drop'] = true;
$db->exec('DELETE FROM following');
preview_check(admin_reply_preview_lookup($peer) === null, 'followers-only peer hidden when not followed');
$db->prepare('INSERT INTO following (owner_actor_id, actor_id) VALUES (?,?)')
    ->execute(['https://mkultra.monster/users/cmdr_nova', 'https://mkultra.monster/users/shana_flux']);

$remotePreview = admin_reply_preview_lookup($remote);
preview_check(is_array($remotePreview), 'remote public event resolves');
preview_check(($remotePreview['name'] ?? '') === 'Ada Lovelace', 'remote name strips stored html');
preview_check(($remotePreview['handle'] ?? '') === '@ada@remote.example', 'remote handle');
preview_check(($remotePreview['avatar'] ?? '') === 'https://cdn.example/ada.webp', 'remote cached avatar');
preview_check(($remotePreview['snippet'] ?? '') === 'Hello there', 'remote snippet');
preview_check(!empty($remotePreview['media']), 'remote media note');

preview_check(admin_reply_preview_lookup($direct) === null, 'direct post hidden without audience');
$db->prepare('INSERT INTO outbox_notes (id, content, to_json, cc_json) VALUES (?,?,?,?)')
    ->execute([$direct, 'secret dm', '["https://mkultra.monster/users/cmdr_nova"]', '[]']);
$directPreview = admin_reply_preview_lookup($direct);
preview_check(is_array($directPreview) && ($directPreview['snippet'] ?? '') === 'secret dm', 'direct post visible to its recipient');
$db->exec('DELETE FROM outbox_notes');
preview_check(admin_reply_preview_lookup($direct) === null, 'direct post hidden again');
$db->prepare('INSERT INTO mentions (owner_user_id, actor_id, object_id, content, media_urls, spoiler_text, sensitive, deleted_at) VALUES (?,?,?,?,?,?,?,?)')
    ->execute([7, 'https://remote.example/users/ada', $direct, 'secret dm', '[]', '', 0, null]);
preview_check(is_array(admin_reply_preview_lookup($direct)), 'direct post visible once delivered as a mention');
$db->exec('DELETE FROM mentions');

preview_check(admin_reply_preview_lookup($private) === null, 'followers-only remote hidden');
$db->prepare('INSERT INTO following (owner_actor_id, actor_id) VALUES (?,?)')
    ->execute(['https://mkultra.monster/users/cmdr_nova', 'https://remote.example/users/ada']);
$privatePreview = admin_reply_preview_lookup($private);
preview_check(is_array($privatePreview) && ($privatePreview['snippet'] ?? '') === 'followers only body', 'followers-only remote visible to a follower');
$db->exec("DELETE FROM following WHERE actor_id = 'https://remote.example/users/ada'");

$boostPreview = admin_reply_preview_lookup($boost);
preview_check(is_array($boostPreview), 'boost target resolves');
preview_check(($boostPreview['name'] ?? '') === 'Ada Lovelace', 'boost summarizes the boosted author');
preview_check(($boostPreview['snippet'] ?? '') === 'the boosted post', 'boost summarizes the boosted text');
preview_check(!str_contains((string) ($boostPreview['name'] ?? ''), 'booster'), 'booster is not the preview author');

$announceOnly = admin_reply_preview_lookup('https://remote.example/users/ada/statuses/announce-only');
preview_check(is_array($announceOnly), 'announce-only boost still summarizes the object');
preview_check(($announceOnly['handle'] ?? '') === '@ada@remote.example', 'announce-only author parsed from the object url');
preview_check(($announceOnly['snippet'] ?? '') === 'boosted without a create row', 'announce-only snippet');
preview_check(!empty($announceOnly['media']) && !empty($announceOnly['cw']), 'announce-only media and cw');

$quotePreview = admin_reply_preview_lookup($quote);
preview_check(is_array($quotePreview), 'quote post resolves');
preview_check(($quotePreview['snippet'] ?? '') === 'look at this', 'quote snippet is the commentary');
preview_check(!str_contains((string) ($quotePreview['snippet'] ?? ''), 'quoted secret'), 'quoted body stays out of the snippet');
preview_check(!empty($quotePreview['quote']), 'quote note');

$xssPreview = admin_reply_preview_lookup($xss);
preview_check(is_array($xssPreview), 'xss row resolves');
preview_check(($xssPreview['snippet'] ?? '') === 'plain & safe', 'markup and entities become plain text');
$html = admin_reply_preview_card_html($xssPreview);
preview_check(!str_contains($html, '<img src=x'), 'preview html does not echo the payload tag');
preview_check(str_contains($html, 'plain &amp; safe'), 'preview html escapes the snippet');
foreach (['javascript:alert(1)', 'data:text/html,hi', 'vbscript:msgbox(1)'] as $badAvatar) {
    $scheme = strstr($badAvatar, ':', true);
    $evil = admin_reply_preview_card_html([
        'name' => '<script>alert(1)</script>',
        'handle' => '@ada',
        'avatar' => $badAvatar,
        'snippet' => '<b>nope</b>',
        'media' => true,
        'cw' => true,
        'quote' => false,
    ]);
    $src = '';
    if (preg_match('/<img\b[^>]*\bsrc="([^"]*)"/', $evil, $srcMatch) === 1) {
        $src = (string) $srcMatch[1];
    }
    $srcParts = parse_url($src);
    preview_check(
        is_array($srcParts)
        && strtolower((string) ($srcParts['scheme'] ?? '')) === 'https'
        && ($srcParts['host'] ?? '') !== ''
        && $src === 'https://mkultra.monster/img/avatar/default.webp',
        $scheme . ' avatar uses the https default'
    );
    preview_check(!str_contains($evil, '<script>'), $scheme . ' card does not emit a script tag');
    preview_check(str_contains($evil, 'Content warning') && str_contains($evil, '>Media<'), $scheme . ' card keeps the cw and media notes');
}

$GLOBALS['blocked'] = ['https://remote.example/users/ada'];
preview_check(admin_reply_preview_lookup($remote) === null, 'blocked actor hidden');
$GLOBALS['blocked'] = [];
$GLOBALS['muted'] = ['https://remote.example/users/ada'];
preview_check(admin_reply_preview_lookup($remote) === null, 'muted actor hidden');
$GLOBALS['muted'] = [];
$GLOBALS['global_muted'] = ['https://remote.example/users/ada'];
preview_check(admin_reply_preview_lookup($remote) === null, 'globally muted actor hidden');
$GLOBALS['global_muted'] = [];
$GLOBALS['mute_word'] = 'forbidden';
preview_check(admin_reply_preview_lookup($mutedWord) === null, 'word-muted post hidden');
$GLOBALS['mute_word'] = '';
preview_check(is_array(admin_reply_preview_lookup($remote)), 'public remote returns after mutes clear');

$bskyPreview = admin_reply_preview_lookup($bskyHttps);
preview_check(is_array($bskyPreview), 'backfilled bluesky permalink resolves locally');
preview_check(($bskyPreview['name'] ?? '') === 'Ada Sky', 'bluesky display name');
preview_check(($bskyPreview['handle'] ?? '') === '@ada.bsky.social', 'bluesky handle');
preview_check(($bskyPreview['avatar'] ?? '') === 'https://cdn.example/bsky.webp', 'bluesky avatar');
preview_check(($bskyPreview['snippet'] ?? '') === 'backfilled bluesky text', 'bluesky text column used when record text is empty');
preview_check(!empty($bskyPreview['media']) && !empty($bskyPreview['cw']), 'bluesky media and label');
$GLOBALS['blocked'] = ['did:plc:ada'];
preview_check(admin_reply_preview_lookup($bsky) === null, 'blocked bluesky did hidden');
$GLOBALS['blocked'] = [];

$mirror = admin_reply_preview_lookup('at://did:plc:nova/app.bsky.feed.post/mirror1');
preview_check(is_array($mirror) && ($mirror['snippet'] ?? '') === 'continuing the thread', 'bluesky mirror summarizes the local note');

preview_check(admin_reply_preview_lookup('https://unknown.example/users/ghost/statuses/1') === null, 'unknown post falls back');
preview_check(admin_reply_preview_lookup('at://did:plc:missing/app.bsky.feed.post/nope') === null, 'unknown bluesky post falls back');
preview_check(admin_reply_preview_lookup('') === null, 'empty target falls back');

$fallback = admin_reply_preview_context_html('Replying to', $remote, null, false);
preview_check(str_contains($fallback, 'Replying to') && str_contains($fallback, '@ada'), 'missing summary keeps the handle label');
preview_check(!str_contains($fallback, 'compose-reply-preview'), 'missing summary does not invent a card');
$selfFallback = admin_reply_preview_context_html('Replying to your post', $own, null, true);
preview_check(str_contains($selfFallback, 'Replying to your post') && str_contains($selfFallback, $own), 'own fallback keeps the note url');
$withCard = admin_reply_preview_context_html('Replying to', $remote, $remotePreview, false);
preview_check(str_contains($withCard, 'compose-reply-preview') && str_contains($withCard, 'Hello there'), 'known summary renders inside the reply context');

$plain = admin_reply_preview_plain("<p>one</p>\n\n↪ QT @x@y: hidden", 180);
preview_check($plain === 'one', 'plain helper drops the quote tail');
$long = admin_reply_preview_plain(str_repeat('a', 200), 180);
preview_check(str_ends_with($long, '…') && mb_strlen($long) === 180, 'plain helper trims with an ellipsis');

echo "reply preview smoke: {$checks} checks\n";
