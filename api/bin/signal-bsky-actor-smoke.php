<?php
// Bluesky like/repost/bookmark signals must resolve to the author DID the
// Home rankers key Bluesky authors by (they used to be skipped).
declare(strict_types=1);
require_once __DIR__ . '/../ap-signals.php';

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fail++;
};
$check(ap_signal_did_from_at_uri('at://did:plc:abc123/app.bsky.feed.post/3kq') === 'did:plc:abc123', 'plc DID from post URI');
$check(ap_signal_did_from_at_uri('at://did:web:x.example.com/app.bsky.feed.post/1') === 'did:web:x.example.com', 'web DID from post URI');
$check(ap_signal_did_from_at_uri('https://r.test/notes/1') === '', 'fediverse id gives nothing');
$check(ap_signal_did_from_at_uri('at://alice.bsky.social/app.bsky.feed.post/1') === '', 'handle authority gives nothing');
$check(ap_signal_did_from_at_uri("at://did:plc:abc\n/x/y") === '', 'newline refused');
$check(ap_signal_meta_actor(['uri' => 'at://did:plc:abc123/app.bsky.feed.post/3kq', 'object_id' => 'at://did:plc:abc123/app.bsky.feed.post/3kq']) === 'did:plc:abc123', 'old Bluesky like row (uri only) resolves');
$check(ap_signal_meta_actor(['target_actor' => 'https://r.test/users/a/', 'uri' => 'at://did:plc:zz/app.bsky.feed.post/1']) === 'https://r.test/users/a', 'target_actor wins');
$check(ap_signal_meta_actor(['author_handle' => '@al.example']) === 'https://bsky.app/profile/al.example', 'handle fallback kept');
$check(ap_signal_meta_actor([]) === '', 'empty meta gives nothing');
exit($fail ? 1 : 0);
