<?php
declare(strict_types=1);
/**
 * Link preview cards (Open Graph / Twitter tags) on shared post links.
 * No database: the lookups the resolver calls are stubbed with fixtures,
 * so this checks the card builders, the audience gate and the head wiring.
 */
$root = dirname(__DIR__, 2);
$checks = 0;
function share_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

// --- Stubs for the cache-only lookups (fixtures keyed by object URL) ---
$GLOBALS['fx_status'] = [];
$GLOBALS['fx_allowed'] = [];
$GLOBALS['fx_blocked'] = [];
$GLOBALS['fx_bsky'] = [];
$GLOBALS['fx_fetch'] = [];
function ap_masto_lookup_status_by_object_url(string $url, int $depth = 1, bool $fetch = false, bool $hidden = false): ?array
{
    $GLOBALS['fx_fetch'][] = $fetch;
    return $GLOBALS['fx_status'][$url] ?? null;
}
function admin_direct_status_allowed(array $status, int $owner, int $depth = 0): bool
{
    return $owner === 0 && !empty($GLOBALS['fx_allowed'][$status['uri'] ?? '']);
}
function admin_direct_status_content_blocked(array $status): bool
{
    return !empty($GLOBALS['fx_blocked'][$status['account']['uri'] ?? '']);
}
function ap_actor_is_content_blocked(?string $actor, ?string $host = null, ?int $owner = null): bool
{
    return !empty($GLOBALS['fx_blocked'][$actor ?? '']);
}
function admin_object_is_bluesky_post(string $o): bool
{
    return str_starts_with($o, 'at://') || str_starts_with($o, 'https://bsky.app/');
}
function admin_bsky_thread_target_uri(string $o, bool $allowNetwork = true): ?string
{
    if ($allowNetwork) {
        throw new RuntimeException('Bluesky lookup must be DB-only');
    }
    if (preg_match('~^https://bsky\.app/profile/(did:[^/]+)/post/([^/]+)$~', $o, $m)) {
        return 'at://' . $m[1] . '/app.bsky.feed.post/' . $m[2];
    }
    return str_starts_with($o, 'at://') ? $o : null;
}
function ap_masto_object_url_lookup_candidates(string $u): array
{
    // Trimmed copy of the real helper: Mastodon web permalink -> AS2 id.
    $c = [rtrim($u, '/')];
    if (preg_match('#^(https://[^/]+)/@([^/]+)/([A-Za-z0-9_-]+)$#', $u, $m)) {
        $c[] = $m[1] . '/users/' . $m[2] . '/statuses/' . $m[3];
    }
    return $c;
}
function ap_bsky_post_item_by_uri(string $at): ?array
{
    $post = $GLOBALS['fx_bsky'][$at] ?? null;
    return is_array($post) ? ['post' => $post, 'bsky_uri' => $at] : null;
}

require $root . '/api/ap-vaak-status-route.php';
require $root . '/api/ap-vaak-status-share.php';

$local = 'https://mkultra.monster/users/cmdr_nova/notes/abc123';
$GLOBALS['fx_status'][$local] = [
    'uri' => $local,
    'created_at' => '2026-11-05T20:00:00.000Z',
    'content' => '<p>Hello &amp; welcome &lt;3</p><p>second <a href="https://x.test">line</a></p>',
    'spoiler_text' => '',
    'sensitive' => false,
    'account' => ['uri' => 'https://mkultra.monster/users/cmdr_nova', 'display_name' => 'Valerie', 'acct' => 'cmdr_nova',
        'avatar' => 'https://mkultra.monster/img/profile/a.png', 'avatar_static' => 'https://mkultra.monster/img/profile/a.png'],
    'media_attachments' => [
        ['type' => 'image', 'url' => '/img/media/pic.webp', 'preview_url' => '/img/media/pic-s.webp', 'description' => 'A "cat" on a desk'],
    ],
];
$GLOBALS['fx_allowed'][$local] = true;

// Local public post: title, text, first image, short permalink.
$m = ap_vaak_status_share_meta($local);
share_check(is_array($m), 'public local post gets a card');
share_check($m['title'] === 'Valerie (@cmdr_nova@mkultra.monster)', 'title is name + full handle: ' . $m['title']);
share_check($m['description'] === 'Hello & welcome <3 second line 📎', 'description is plain text: ' . $m['description']);
share_check($m['image'] === 'https://mkultra.monster/img/media/pic.webp', 'first image, made absolute');
share_check($m['url'] === 'https://mkultra.monster/vaak/@cmdr_nova/abc123', 'og:url is the short permalink');
share_check(!in_array(true, $GLOBALS['fx_fetch'], true), 'lookup never fetches remotely');
$tags = ap_vaak_share_meta_tags($m);
share_check(str_contains($tags, '<meta property="og:title" content="Valerie (@cmdr_nova@mkultra.monster)">'), 'og:title tag');
share_check(str_contains($tags, '<meta property="og:description" content="Hello &amp; welcome &lt;3 second line 📎">'), 'description escaped');
share_check(str_contains($tags, '<meta property="og:image:alt" content="A &quot;cat&quot; on a desk">'), 'alt text escaped');
share_check(str_contains($tags, '<meta name="twitter:card" content="summary_large_image">'), 'large card with media');
share_check(str_contains($tags, '<meta property="og:site_name" content="VAAK">'), 'site name');
share_check(str_contains($tags, '<meta property="article:published_time" content="2026-11-05T20:00:00Z">'), 'published time');

// Followers-only / direct: no card at all.
$GLOBALS['fx_allowed'][$local] = false;
share_check(ap_vaak_status_share_meta($local) === null, 'private post gets no card');
$GLOBALS['fx_allowed'][$local] = true;

// Server-blocked author: no card.
$GLOBALS['fx_blocked']['https://mkultra.monster/users/cmdr_nova'] = true;
share_check(ap_vaak_status_share_meta($local) === null, 'blocked author gets no card');
$GLOBALS['fx_blocked'] = [];

// CW: description shows only the warning, media stays out, avatar used.
$GLOBALS['fx_status'][$local]['spoiler_text'] = 'spoilers for a show';
$m = ap_vaak_status_share_meta($local);
share_check($m['description'] === 'CW: spoilers for a show', 'CW hides the text');
share_check($m['image'] === 'https://mkultra.monster/img/profile/a.png' && $m['large'] === '', 'CW hides the media');
share_check(str_contains(ap_vaak_share_meta_tags($m), 'content="summary">'), 'small card under CW');
$GLOBALS['fx_status'][$local]['spoiler_text'] = '';
$GLOBALS['fx_status'][$local]['sensitive'] = true;
$m = ap_vaak_status_share_meta($local);
share_check($m['image'] === 'https://mkultra.monster/img/profile/a.png', 'sensitive media stays out');
share_check(str_starts_with($m['description'], 'Hello'), 'sensitive without CW keeps the text');

// Remote fediverse post via ?object=, web permalink candidate.
$remote = 'https://example.social/users/bob/statuses/9';
$GLOBALS['fx_status'][$remote] = [
    'uri' => $remote,
    'content' => '<p>' . str_repeat('word ', 120) . '</p>',
    'account' => ['uri' => 'https://example.social/users/bob', 'display_name' => '', 'acct' => 'bob@example.social', 'avatar' => 'https://example.social/a.png'],
    'media_attachments' => [['type' => 'video', 'url' => 'https://example.social/v.mp4', 'preview_url' => 'https://example.social/v.jpg']],
];
$GLOBALS['fx_allowed'][$remote] = true;
$m = ap_vaak_status_share_meta('https://example.social/@bob/9');
share_check(is_array($m) && $m['title'] === '@bob@example.social', 'web permalink resolves to the stored note; handle-only title');
share_check($m['image'] === 'https://example.social/v.jpg', 'video uses its poster');
share_check(mb_strlen($m['description']) <= AP_VAAK_SHARE_DESC_MAX && str_ends_with($m['description'], '… 📎'), 'long text capped');
share_check($m['url'] === 'https://mkultra.monster/vaak/?view=status&object=' . rawurlencode('https://example.social/@bob/9'), 'remote og:url keeps the shared link');
share_check(ap_vaak_status_share_meta('https://example.social/users/nobody/statuses/1') === null, 'unknown post gets no card');

// Bluesky post from the cache.
$at = 'at://did:plc:abc/app.bsky.feed.post/3k';
$GLOBALS['fx_bsky'][$at] = [
    'uri' => $at,
    'author' => ['did' => 'did:plc:abc', 'handle' => 'luna.bsky.social', 'displayName' => 'Luna', 'avatar' => 'https://cdn.bsky.app/av.jpg'],
    'record' => ['text' => "I <3 this\nso much", 'createdAt' => '2026-11-05T10:00:00Z'],
    'embed' => ['$type' => 'app.bsky.embed.recordWithMedia#view', 'media' => ['$type' => 'app.bsky.embed.images#view',
        'images' => [['thumb' => 'https://cdn.bsky.app/t.jpg', 'fullsize' => 'https://cdn.bsky.app/f.jpg', 'alt' => 'sky']]]],
];
$m = ap_vaak_status_share_meta('https://bsky.app/profile/did:plc:abc/post/3k');
share_check(is_array($m) && $m['title'] === 'Luna (@luna.bsky.social)', 'Bluesky title');
share_check($m['description'] === 'I <3 this so much', 'Bluesky plain text keeps "<3"');
share_check($m['image'] === 'https://cdn.bsky.app/f.jpg' && $m['image_alt'] === 'sky', 'Bluesky image from recordWithMedia');
$GLOBALS['fx_bsky'][$at]['labels'] = [['val' => 'porn']];
share_check(ap_vaak_status_share_meta($at)['image'] === 'https://cdn.bsky.app/av.jpg', 'adult label keeps media out');
$GLOBALS['fx_bsky'][$at]['author']['labels'] = [['val' => '!no-unauthenticated']];
share_check(ap_vaak_status_share_meta($at) === null, 'logged-out opt-out gets no card');
unset($GLOBALS['fx_bsky'][$at]['author']['labels']);
$GLOBALS['fx_blocked']['did:plc:abc'] = true;
share_check(ap_vaak_status_share_meta($at) === null, 'blocked Bluesky author gets no card');
$GLOBALS['fx_blocked'] = [];
share_check(ap_vaak_status_share_meta('at://did:plc:zzz/app.bsky.feed.post/1') === null, 'uncached Bluesky post gets no card');

// Nothing that is not a post.
share_check(ap_vaak_status_share_meta('') === null, 'empty object');
share_check(ap_vaak_status_share_meta('https://mkultra.monster/users/x/bites-received/1') === null, 'bite');
share_check(ap_vaak_share_image_url('javascript:alert(1)') === '' && ap_vaak_share_image_url('//evil.test/x') === '', 'only https images');

// Head wiring: guests only, cache-only, falls back to the plain title.
$admin = (string) file_get_contents($root . '/api/ap-admin.php');
share_check(str_contains($admin, "require_once __DIR__ . '/ap-vaak-status-share.php';"), 'ap-admin loads the share helpers');
$head = substr($admin, (int) strpos($admin, '<!DOCTYPE html>'), 6000);
share_check(str_contains($head, "if (\$view === 'status' && !empty(\$GLOBALS['vaak_guest_status']) && function_exists('ap_vaak_status_share_meta'))"), 'tags only on the guest status page');
share_check(str_contains($head, '<?= ap_vaak_share_meta_tags($vaakShareMeta) ?>'), 'tags printed in <head>');
share_check(strpos($head, 'ap_vaak_share_meta_tags') < strpos($head, '</head>'), 'tags come before </head>');
share_check(str_contains($head, "VAAK · <?= h(view_title(\$view)) ?></title>"), 'plain title kept when there is no card');

echo "status-share-meta-smoke: {$checks} checks passed\n";
