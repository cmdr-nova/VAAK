#!/usr/bin/env php
<?php
declare(strict_types=1);

/*
 * Image/video-only Bluesky quotes carry "📷 Image" / "🎬 Video" as a stand-in
 * body. When the quoted media paints, that label must not show as stray text
 * above the picture. With no media it still stands in so the card isn't empty.
 *
 * Run: php api/bin/quote-media-placeholder-smoke.php
 */

require_once __DIR__ . '/migration-reference-functions.php';

$checks = 0;
function qp_check(bool $ok, string $why): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: $why\n");
        exit(1);
    }
    $GLOBALS['checks']++;
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function admin_linkify_body_html(string $text, string $view = 'home', array $mentions = [], ?string $actor = null): string { return h($text); }
function admin_quote_media_html(array $media): string { return '<div class="quote-media">' . h((string) ($media[0]['url'] ?? '')) . '</div>'; }
function admin_media_row_html(array $media): string { return admin_quote_media_html($media); }
function admin_library_quote_policy_key(string $url): string { return $url; }
function admin_quote_https_avatar(array $account): string { return 'https://cdn.example/a.jpg'; }
function relative_time(string $created): string { return '1h'; }

reference_load(dirname(__DIR__) . '/ap-admin.php', ['admin_quote_placeholder_hidden', 'admin_quote_card_html', 'admin_feed_quote_embed_html']);

$img = 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:q/bafkq@jpeg';
foreach (['📷 Image', '🎬 Video'] as $label) {
    $opts = ['acct' => 'someone.bsky.social', 'text' => $label, 'media' => [['url' => $img, 'mediaType' => 'image/jpeg']]];
    foreach ([admin_quote_card_html($opts, 'home'), admin_feed_quote_embed_html(['account' => ['acct' => 'someone.bsky.social']], 'home', $opts)] as $html) {
        qp_check(str_contains($html, $img), "quoted media paints ($label)");
        qp_check(!str_contains($html, $label), "media-only quote hides the $label placeholder: $html");
    }
}
$bare = admin_quote_card_html(['acct' => 'someone.bsky.social', 'text' => '📷 Image', 'media' => []], 'home');
qp_check(str_contains($bare, '📷 Image'), 'no media: placeholder still stands in');
$real = admin_quote_card_html(['acct' => 'x', 'text' => 'look at this 📷 Image', 'media' => [['url' => $img]]], 'home');
qp_check(str_contains($real, 'look at this 📷 Image'), 'real captions are never touched');

echo "quote-media-placeholder-smoke ok checks=$checks\n";
