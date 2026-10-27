<?php
declare(strict_types=1);
/**
 * PHP selection versus the Rust complete-profile page for one local actor.
 *
 * Covers the four timeline tabs, pagination, a quote, pins, and audience.
 * Followers-only, private, and direct bodies must not appear for a guest or
 * a stranger. Bluesky-origin notes use the same public tab queries as other
 * local notes and must not be queued for ActivityPub delivery.
 *
 * Requires the CI database and a worker started with VAAK_PROFILE_COMPLETE_NATIVE=1.
 * The secret is read from the environment and is never printed.
 */
$dsn = getenv('AP_DB_DSN');
$url = getenv('VAAK_PROFILE_PARITY_RUST_URL');
$secret = getenv('VAAK_PROFILE_COMPLETE_SECRET');
if (!is_string($dsn) || $dsn === '' || !is_string($url) || $url === '' || !is_string($secret) || strlen($secret) < 32) {
    throw new RuntimeException('profile parity worker is not configured');
}
if (!str_starts_with($url, 'http://127.0.0.1:')) {
    throw new RuntimeException('profile parity worker must be loopback');
}

$pdo = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$key = 'profile_parity';
$actor = 'https://mkultra.monster/users/' . $key;
$ownerId = 7494;
$fanId = 7495;
$fanActor = 'https://mkultra.monster/users/parity_fan';
$checks = 0;

function parity_check(bool $ok, string $message): void
{
    if (!$ok) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

/** Include Rust's fallback reason code. The body is not printed. */
function parity_http_detail(string $label, array $result): string
{
    $reason = $result['json']['reason'] ?? null;
    $suffix = is_string($reason) && preg_match('/^[a-z0-9_]{1,40}$/', $reason) === 1 ? ' reason ' . $reason : '';
    return $label . ' returned HTTP ' . $result['code'] . $suffix;
}

$prefix = $actor . '/notes/';
$insertNote = $pdo->prepare(
    'INSERT INTO outbox_notes (id, create_id, published, content, in_reply_to, to_json, cc_json, raw_create_json, kind, visibility)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)'
);
$insertStatus = $pdo->prepare(
    'INSERT INTO masto_statuses (note_id, create_id, published, content_text, visibility, spoiler_text, sensitive)
     VALUES (?, ?, ?, ?, ?, ?, 0) RETURNING local_id'
);
$notes = [];
$add = static function (string $slug, string $published, string $body, string $visibility, ?string $reply, string $raw, string $kind = 'compose') use ($insertNote, $insertStatus, $prefix, &$notes): int {
    $id = $prefix . $slug;
    $insertNote->execute([$id, $id . '/create', $published, '<p>' . $body . '</p>', $reply, '[]', '[]', $raw, $kind, $visibility]);
    $insertStatus->execute([$id, $id . '/create', $published, $body, $visibility, '']);
    $local = (int) $insertStatus->fetchColumn();
    $insertStatus->closeCursor();
    $notes[$slug] = ['id' => $id, 'local_id' => $local, 'body' => $body, 'visibility' => $visibility];
    return $local;
};

try {
    $pdo->prepare(
        'INSERT INTO ap_users (id, username, email, password_hash, actor_key, actor_id, is_admin, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)'
    )->execute([$ownerId, $key, 'profile-parity@vaak.test', 'unused', $key, $actor, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z']);
    $pdo->prepare(
        'INSERT INTO ap_users (id, username, email, password_hash, actor_key, actor_id, is_admin, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)'
    )->execute([$fanId, 'parity_fan', 'profile-parity-fan@vaak.test', 'unused', 'parity_fan', $fanActor, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z']);
    $pdo->prepare('INSERT INTO actor_profile (actor_key, name, summary, updated_at) VALUES (?, ?, ?, ?)')
        ->execute([$key, 'Parity', '', '2026-01-01T00:00:00Z']);
    $pdo->prepare('INSERT INTO actor_profile (actor_key, name, summary, updated_at) VALUES (?, ?, ?, ?)')
        ->execute(['parity_fan', 'Fan', '', '2026-01-01T00:00:00Z']);
    $pdo->prepare(
        'INSERT INTO followers (owner_actor_id, actor_id, followed_at, last_seen) VALUES (?, ?, ?, ?)'
    )->execute([$actor, $fanActor, '2026-01-01T00:00:00Z', '2026-01-01T00:00:00Z']);

    $add('quoted', '2026-09-01T00:00:00Z', 'QUOTED-BODY', 'public', null, '{"object":{"id":"' . $prefix . 'quoted","type":"Note","content":"<p>QUOTED-BODY</p>"}}');
    $quoteRaw = json_encode([
        'type' => 'Create',
        'object' => [
            'id' => $prefix . 'quote',
            'type' => 'Note',
            'content' => '<p>QUOTE-MARKER</p>',
            'quote' => $prefix . 'quoted',
            'quoteUri' => $prefix . 'quoted',
            '_misskey_quote' => $prefix . 'quoted',
        ],
    ], JSON_UNESCAPED_SLASHES);
    $add('quote', '2026-10-27T12:00:09Z', 'QUOTE-MARKER', 'public', null, (string) $quoteRaw, 'quote');
    $bskyRaw = json_encode([
        'type' => 'Create',
        'object' => [
            'id' => $prefix . 'bsky-post',
            'type' => 'Note',
            'content' => '<p>BSKY-POST</p>',
            'vaakOrigin' => 'bluesky',
            'blueskyUri' => 'at://did:plc:parity/app.bsky.feed.post/post',
            'attachment' => [['type' => 'Document', 'mediaType' => 'image/jpeg', 'url' => 'https://cdn.bsky.app/img/feed_fullsize/plain/did:plc:parity/cid@jpeg']],
        ],
    ], JSON_UNESCAPED_SLASHES);
    $add('bsky-post', '2026-10-27T12:00:08Z', 'BSKY-POST', 'public', null, (string) $bskyRaw);
    $replyRaw = json_encode(['object' => ['vaakOrigin' => 'bluesky', 'content' => '<p>BSKY-REPLY</p>', 'inReplyTo' => $prefix . 'bsky-post']], JSON_UNESCAPED_SLASHES);
    $add('bsky-reply', '2026-10-27T12:00:07Z', 'BSKY-REPLY', 'public', $prefix . 'bsky-post', (string) $replyRaw);
    $mediaRaw = json_encode(['object' => ['content' => '<p>MEDIA-POST</p>', 'attachment' => [['type' => 'Document', 'url' => 'https://cdn.example.test/parity.jpg']]]], JSON_UNESCAPED_SLASHES);
    $add('media', '2026-10-27T12:00:06Z', 'MEDIA-POST', 'public', null, (string) $mediaRaw);
    $add('pin-public', '2026-10-27T12:00:05Z', 'PIN-PUBLIC', 'public', null, '{"object":{"content":"<p>PIN-PUBLIC</p>"}}');
    $add('pin-private', '2026-10-27T11:00:01Z', 'PIN-FOLLOWERS', 'private', null, '{"object":{"content":"<p>PIN-FOLLOWERS</p>"}}');
    $add('pin-direct', '2026-10-27T11:00:02Z', 'PIN-DIRECT', 'direct', null, '{"object":{"content":"<p>PIN-DIRECT</p>","to":["' . $actor . '"]}}');
    $add('hidden-private', '2026-10-27T11:00:03Z', 'HIDDEN-PRIVATE', 'private', null, '{"object":{"content":"<p>HIDDEN-PRIVATE</p>"}}');
    for ($i = 0; $i < 11; $i++) {
        $stamp = sprintf('2026-10-01T00:00:%02dZ', $i);
        $add('filler-' . $i, $stamp, 'FILLER-' . $i, 'public', null, '{"object":{"content":"<p>FILLER-' . $i . '</p>"}}');
    }
    $pin = $pdo->prepare('INSERT INTO masto_pins (owner_user_id, status_local_id, pinned_at) VALUES (?, ?, ?)');
    $pin->execute([$ownerId, $notes['pin-public']['local_id'], '2026-10-27T12:00:05Z']);
    $pin->execute([$ownerId, $notes['pin-private']['local_id'], '2026-10-27T12:00:04Z']);
    $pin->execute([$ownerId, $notes['pin-direct']['local_id'], '2026-10-27T12:00:03Z']);

    $pdo->prepare(
        'INSERT INTO events (created_at, type, actor_id, object_id, action_taken, summary, bytes, visibility)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute(['2026-10-27T12:00:02Z', 'Announce', $actor, $notes['pin-public']['id'], 'boost_ok', 'BOOST-PUBLIC', 0, 'public']);
    $pdo->prepare(
        'INSERT INTO masto_reblogs (owner_user_id, owner_actor_id, status_id, boost_status_id, object_id, target_actor, announce_activity_id, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    )->execute([
        $ownerId,
        $actor,
        'bsky-repost-parity',
        'bsky-repost-parity',
        'https://bsky.app/profile/example/post/parity',
        'https://bsky.app/profile/example',
        'bsky-repost-parity',
        '2026-10-27T12:00:00Z',
    ]);

    $phpPosts = $pdo->prepare(
        "SELECT id FROM outbox_notes WHERE id LIKE ? AND COALESCE(visibility,'public') = 'public' AND TRIM(COALESCE(in_reply_to, '')) = '' ORDER BY published DESC"
    );
    $phpPosts->execute([$actor . '/%']);
    $postIds = $phpPosts->fetchAll(PDO::FETCH_COLUMN);
    $phpReplies = $pdo->prepare(
        "SELECT id FROM outbox_notes WHERE id LIKE ? AND COALESCE(visibility,'public') = 'public' AND TRIM(COALESCE(in_reply_to, '')) <> '' ORDER BY published DESC"
    );
    $phpReplies->execute([$actor . '/%']);
    $replyIds = $phpReplies->fetchAll(PDO::FETCH_COLUMN);
    $phpMedia = $pdo->prepare(
        "SELECT id FROM outbox_notes WHERE id LIKE ? AND COALESCE(visibility,'public') = 'public' AND raw_create_json LIKE '%\"attachment\"%' ORDER BY published DESC"
    );
    $phpMedia->execute([$actor . '/%']);
    $mediaIds = $phpMedia->fetchAll(PDO::FETCH_COLUMN);
    $phpBoosts = $pdo->prepare(
        "SELECT COUNT(*) FROM (SELECT DISTINCT ON (object_id) id FROM events WHERE type = 'Announce' AND actor_id IN (?, ?) ORDER BY object_id, CASE WHEN action_taken = 'boost_ok' THEN 0 ELSE 1 END, created_at DESC, id DESC) boosts"
    );
    $phpBoosts->execute([$actor, $actor . '/']);
    $boostCount = (int) $phpBoosts->fetchColumn();
    $phpPins = $pdo->prepare(
        'SELECT s.content_text, s.visibility FROM masto_pins p INNER JOIN masto_statuses s ON s.local_id = p.status_local_id WHERE p.owner_user_id = ? AND s.note_id LIKE ? ORDER BY p.pinned_at DESC LIMIT 5'
    );
    $phpPins->execute([$ownerId, $prefix . '%']);
    $pinRows = $phpPins->fetchAll();
    $boostTotal = (int) $pdo->query('SELECT COUNT(*) FROM masto_reblogs WHERE owner_user_id = ' . (int) $ownerId)->fetchColumn();

    parity_check(in_array($notes['bsky-post']['id'], $postIds, true), 'PHP posts include the Bluesky-origin note');
    parity_check(in_array($notes['bsky-reply']['id'], $replyIds, true), 'PHP replies include the Bluesky-origin reply');
    parity_check(in_array($notes['bsky-post']['id'], $mediaIds, true) && in_array($notes['media']['id'], $mediaIds, true), 'PHP media includes Bluesky and local attachments');
    parity_check(!in_array($notes['hidden-private']['id'], $postIds, true), 'PHP public posts omit a private note');
    parity_check($boostCount === 1, 'PHP boosts tab selects the Announce');
    parity_check($boostTotal === 1, 'PHP boost count includes the Bluesky repost row');
    parity_check(array_column($pinRows, 'content_text') === ['PIN-PUBLIC', 'PIN-FOLLOWERS', 'PIN-DIRECT'], 'PHP pin selection keeps public, followers-only, and direct pins');

    $request = static function (string $path, array $body) use ($url, $secret): array {
        $ch = curl_init($url . $path);
        if ($ch === false) {
            throw new RuntimeException('curl unavailable');
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($body, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 2000,
            CURLOPT_TIMEOUT_MS => 20000,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'X-VAAK-Profile-Token: ' . $secret],
        ]);
        $raw = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $decoded = is_string($raw) ? json_decode($raw, true) : null;
        return ['code' => $code, 'json' => is_array($decoded) ? $decoded : [], 'html' => ''];
    };
    $page = static function (string $tab, int $offset, array $viewer) use ($request, $key, $actor): array {
        $result = $request('/internal/profile/page', [
            'protocol_version' => 1,
            'viewer' => $viewer,
            'actor_key' => $key,
            'actor' => $actor,
            'tab' => $tab,
            'mode' => 'append',
            'offset' => $offset,
            'limit' => 10,
            'lazy_media' => false,
            'status_preview_budget' => 0,
            'quote_preview_budget' => 0,
        ]);
        parity_check($result['code'] === 200 && ($result['json']['ok'] ?? false) === true, parity_http_detail($tab . ' offset ' . $offset, $result));
        $html = '';
        foreach ($result['json']['cards'] ?? [] as $card) {
            $html .= (string) ($card['html'] ?? '');
        }
        $result['html'] = $html;
        return $result;
    };
    $guest = ['kind' => 'guest'];
    $owner = ['kind' => 'authenticated', 'owner_id' => $ownerId, 'csrf' => 'parity-csrf-token'];
    $fan = ['kind' => 'authenticated', 'owner_id' => $fanId, 'csrf' => 'parity-csrf-token'];
    $stranger = ['kind' => 'authenticated', 'owner_id' => 1, 'csrf' => 'parity-csrf-token'];

    $posts = $page('posts', 0, $guest);
    parity_check($posts['json']['page']['source_count'] === 10, 'first posts page matches the PHP page size');
    parity_check($posts['json']['page']['has_more'] === true && $posts['json']['page']['next_offset'] === 10, 'posts page reports another PHP page');
    parity_check(count($postIds) > 10, 'PHP posts continue past the first page');
    $postsMore = $page('posts', 10, $guest);
    parity_check($postsMore['json']['page']['source_count'] === count($postIds) - 10, 'second posts page is the PHP remainder');
    parity_check($postsMore['json']['page']['has_more'] === false, 'second posts page is the last PHP page');
    $ownerPosts = $page('posts', 0, $owner);
    $postsHtml = $posts['html'] . $postsMore['html'];
    foreach (['BSKY-POST', 'QUOTE-MARKER', 'MEDIA-POST', 'PIN-PUBLIC'] as $marker) {
        parity_check(str_contains($postsHtml, $marker), 'posts HTML includes ' . $marker);
    }
    parity_check(!in_array($notes['quoted']['id'], array_slice($postIds, 0, 10), true), 'the quoted note itself is outside the first PHP page');
    parity_check(str_contains($ownerPosts['html'], 'QUOTED-BODY'), 'the quote card includes the quoted post');
    foreach (['BSKY-REPLY', 'PIN-FOLLOWERS', 'PIN-DIRECT', 'HIDDEN-PRIVATE'] as $marker) {
        parity_check(!str_contains($postsHtml, $marker), 'posts HTML omits ' . $marker);
    }

    $replies = $page('replies', 0, $guest);
    parity_check($replies['json']['page']['source_count'] === count($replyIds), 'replies count matches PHP');
    parity_check(str_contains($replies['html'], 'BSKY-REPLY') && !str_contains($replies['html'], 'BSKY-POST'), 'replies tab is the Bluesky reply');
    $media = $page('media', 0, $guest);
    parity_check($media['json']['page']['source_count'] === count($mediaIds), 'media count matches PHP');
    parity_check(str_contains($media['html'], 'BSKY-POST') && str_contains($media['html'], 'MEDIA-POST'), 'media tab shows both attachments');
    $boosts = $page('boosts', 0, $owner);
    parity_check($boosts['json']['page']['source_count'] === $boostCount, 'boosts count matches the PHP Announce selection');
    parity_check(str_contains($boosts['html'], 'PIN-PUBLIC') && !str_contains($boosts['html'], 'HIDDEN-PRIVATE'), 'boost card shows the public original only');

    $full = static function (array $viewer) use ($request, $key, $actor): string {
        $result = $request('/internal/profile/full', [
            'protocol_version' => 1,
            'viewer' => $viewer,
            'actor_key' => $key,
            'actor' => $actor,
            'tab' => 'pinned',
            'from' => '',
            'post' => '',
            'page' => 1,
            'shell' => false,
            'lazy_media' => false,
            'status_preview_budget' => 0,
            'quote_preview_budget' => 0,
        ]);
        parity_check($result['code'] === 200 && ($result['json']['ok'] ?? false) === true, parity_http_detail('pinned profile', $result));
        return (string) ($result['json']['html'] ?? '');
    };
    $guestPins = $full($guest);
    $ownerPins = $full($owner);
    $fanPins = $full($fan);
    $strangerPins = $full($stranger);
    foreach ([$guestPins, $strangerPins] as $html) {
        parity_check(str_contains($html, 'PIN-PUBLIC') && str_contains($html, 'Public'), 'unauthorized pinned view keeps the public pin and its audience label');
        parity_check(!str_contains($html, 'PIN-FOLLOWERS') && !str_contains($html, 'PIN-DIRECT') && !str_contains($html, 'Followers-only') && !str_contains($html, '>Direct<'), 'unauthorized pinned view drops followers-only and direct pins');
    }
    parity_check(str_contains($ownerPins, 'PIN-PUBLIC') && str_contains($ownerPins, 'PIN-FOLLOWERS') && str_contains($ownerPins, 'PIN-DIRECT'), 'owner sees every selected pin');
    parity_check(str_contains($ownerPins, 'Followers-only') && str_contains($ownerPins, '>Direct<') && str_contains($ownerPins, 'Public'), 'owner pin cards keep audience labels');
    parity_check(str_contains($fanPins, 'PIN-PUBLIC') && str_contains($fanPins, 'PIN-FOLLOWERS') && str_contains($fanPins, 'Followers-only'), 'follower sees the followers-only pin and its label');
    parity_check(!str_contains($fanPins, 'PIN-DIRECT') && !str_contains($fanPins, '>Direct<'), 'follower does not see the direct pin');
    parity_check(!str_contains($guestPins . $ownerPins . $fanPins . $strangerPins, 'HIDDEN-PRIVATE'), 'a private timeline note is not pinned into view');

    $queued = $pdo->prepare('SELECT COUNT(*) FROM ap_publish_delivery_queue WHERE note_id LIKE ?');
    $queued->execute([$actor . '/%']);
    parity_check((int) $queued->fetchColumn() === 0, 'Bluesky-origin and local fixture notes are not in the publish queue');
    $fanout = $pdo->prepare('SELECT COUNT(*) FROM ap_fanout_delivery_queue WHERE activity_json LIKE ? OR activity_key LIKE ?');
    $fanout->execute(['%' . $key . '%', '%' . $key . '%']);
    parity_check((int) $fanout->fetchColumn() === 0, 'fixture notes are not in the fan-out queue');
} finally {
    $pdo->prepare('DELETE FROM masto_pins WHERE owner_user_id = ?')->execute([$ownerId]);
    $pdo->prepare('DELETE FROM masto_reblogs WHERE owner_user_id = ?')->execute([$ownerId]);
    $pdo->prepare('DELETE FROM masto_statuses WHERE note_id LIKE ?')->execute([$actor . '/%']);
    $pdo->prepare('DELETE FROM outbox_notes WHERE id LIKE ?')->execute([$actor . '/%']);
    $pdo->prepare('DELETE FROM events WHERE actor_id IN (?, ?)')->execute([$actor, $actor . '/']);
    $pdo->prepare('DELETE FROM followers WHERE actor_id = ?')->execute([$fanActor]);
    $pdo->prepare('DELETE FROM actor_profile WHERE actor_key IN (?, ?)')->execute([$key, 'parity_fan']);
    $pdo->prepare('DELETE FROM ap_users WHERE id IN (?, ?)')->execute([$ownerId, $fanId]);
}

echo "profile-complete-parity-smoke: {$GLOBALS['checks']} checks passed\n";
