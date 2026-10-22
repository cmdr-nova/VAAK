<?php
declare(strict_types=1);
/**
 * Status-page quote embed vs the compact library quote card.
 * No network. Boots the same in-memory painters as the library quote capture.
 */
if (!extension_loaded('mbstring')) {
    fwrite(STDERR, "Status quote embed capture requires mbstring\n");
    exit(2);
}
ob_start();
require __DIR__ . '/migration-card-reference-capture.php';
ob_end_clean();

$GLOBALS['view'] = 'status';
$now = time();

/**
 * @param array<string,mixed> $status
 * @return array<string,mixed>
 */
function status_quote_case(string $id, array $status, int $now): array
{
    $opts = admin_quote_opts_from_status($status, (string) ($status['uri'] ?? $status['url'] ?? ''));
    $html = admin_feed_quote_embed_html($status, 'local', $opts);
    $legacy = admin_quote_card_html($opts, 'favourites');
    if (!str_contains($html, 'class="tweet-av"') || !str_contains($html, 'notif-status-embed')) {
        throw new RuntimeException($id . ' status embed is missing the feed avatar chrome');
    }
    if (str_contains($html, 'qt-label')) {
        throw new RuntimeException($id . ' status embed kept the compact Quoted label');
    }
    if (str_contains($legacy, 'class="tweet-av"')) {
        throw new RuntimeException($id . ' library quote card changed');
    }
    $avatar = trim((string) ($status['account']['avatar'] ?? ''));
    if (str_starts_with($avatar, 'https://')) {
        if (!str_contains($html, $avatar)) {
            throw new RuntimeException($id . ' status embed dropped the stored avatar');
        }
        if (str_contains($legacy, $avatar)) {
            throw new RuntimeException($id . ' library quote card started printing avatars');
        }
    } else if (!str_contains($html, 'https://mkultra.monster/img/avatar/default.webp')) {
        throw new RuntimeException($id . ' missing avatar did not use the feed fallback');
    }
    $display = trim((string) ($status['account']['display_name'] ?? ''));
    if ($display !== '' && !str_contains($html, $display)) {
        throw new RuntimeException($id . ' status embed dropped the display name');
    }
    return ['id' => $id, 'status' => $status, 'now' => $now, 'html' => $html, 'legacy' => $legacy];
}

$quoted = [
    'id' => 'https://remote.test/users/quoted/statuses/9',
    'uri' => 'https://remote.test/users/quoted/statuses/9',
    'url' => 'https://remote.test/users/quoted/statuses/9',
    'created_at' => '2020-01-01T00:00:00Z',
    'content' => '<p>hello quote</p>',
    'visibility' => 'public',
    'account' => [
        'uri' => 'https://remote.test/users/quoted',
        'url' => 'https://remote.test/users/quoted',
        'acct' => 'quoted@remote.test',
        'display_name' => 'Quoted Person',
        'avatar' => 'https://cdn.example/avatars/quoted.webp',
        'avatar_static' => 'https://cdn.example/avatars/quoted.webp',
    ],
    'media_attachments' => [],
];
$bare = $quoted;
unset($bare['account']['avatar'], $bare['account']['avatar_static']);
$bare['id'] = 'https://remote.test/users/quoted/statuses/10';
$bare['uri'] = $bare['id'];
$bare['url'] = $bare['id'];
$card = $quoted;
$card['id'] = 'https://remote.test/users/quoted/statuses/11';
$card['uri'] = $card['id'];
$card['url'] = $card['id'];
$card['content'] = '<p>Quoted with a card</p>';
$card['card'] = [
    'url' => 'https://given.test/card',
    'title' => 'Given card',
    'description' => 'Already attached',
    'provider_name' => 'Given',
];

$cases = [
    status_quote_case('avatar', $quoted, $now),
    status_quote_case('fallback_avatar', $bare, $now),
    status_quote_case('supplied_card', $card, $now),
];

echo json_encode(['now' => $now, 'cases' => $cases], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), "\n";
