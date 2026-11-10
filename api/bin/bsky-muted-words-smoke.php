<?php
declare(strict_types=1);

// Muted words must hide Bluesky posts on PHP-painted timelines (Home fallback,
// live polls, Bluesky tab). Bluesky rows keep their text in post.record, which
// the event-column check never read.
$api = dirname(__DIR__);
$admin = (string) file_get_contents($api . '/ap-admin.php');
$db = (string) file_get_contents($api . '/ap-db.php');
function smoke_fn(string $src, string $name): string
{
    if (!preg_match('/\nfunction ' . preg_quote($name, '/') . '\(.*?\n}\n/s', $src, $m)) {
        fwrite(STDERR, "missing {$name}\n");
        exit(1);
    }
    return $m[0];
}
function admin_owner_user_id(): int { return 1; }
function ap_muted_words_phrases_cached(int $owner, bool $refresh = false): array { return $owner === 1 ? ['slop'] : []; }
function ap_row_matches_muted_words(array $row, string $kind = 'event', array $extra = [], ?int $owner = null): bool { return false; }
function admin_masto_row_for_note(string $id): ?array { return null; }
function ap_ordinary_outbox_matches_muted_words(array $row, array $extra, int $owner): bool { return false; }
eval(smoke_fn($db, 'ap_muted_words_normalize_haystack'));
eval(smoke_fn($db, 'ap_muted_words_match'));
foreach (['admin_bsky_item_muted_texts', 'admin_bsky_item_muted_by_words', 'admin_timeline_item_muted_by_words'] as $fn) {
    eval(smoke_fn($admin, $fn));
}

$fail = 0;
$check = static function (bool $ok, string $label) use (&$fail): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fail++;
};
$item = static fn(array $post): array => ['kind' => 'bsky', 'row' => ['post' => $post]];
$check(admin_timeline_item_muted_by_words($item(['record' => ['text' => 'all the horrible SLOP scenes']])), 'post text matches');
$check(admin_timeline_item_muted_by_words($item(['record' => ['text' => 'fine'], 'embed' => ['record' => ['value' => ['text' => 'ai slop']]]])), 'quoted post matches');
$check(admin_timeline_item_muted_by_words($item(['record' => ['text' => 'fine'], 'embed' => ['record' => ['record' => ['value' => ['text' => 'slop']]]]])), 'recordWithMedia quote matches');
$check(!admin_timeline_item_muted_by_words($item(['record' => ['text' => 'a clean post']])), 'clean post stays');
$check(admin_bsky_item_muted_by_words(['post' => ['record' => ['text' => 'slop']]]), 'feed item (Bluesky tab) matches');
exit($fail === 0 ? 0 : 1);
