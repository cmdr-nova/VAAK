<?php
/**
 * A quote of a local post notifies once: the quoting Create and the Update that
 * follows it (FEP-044f approval stamp, or a later edit) share one mentions row
 * key, so the quoted account sees one "quoted your post", not two.
 * No database and no network.
 */
declare(strict_types=1);

define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$n = 0;
$check = static function (bool $ok, string $why) use (&$n): void {
    if (!$ok) {
        fwrite(STDERR, "inbox-quote-notify-dedupe: {$why}\n");
        exit(1);
    }
    $n++;
};

$shana = 'https://mkultra.monster/users/shana_flux';
$cmdr = 'https://mkultra.monster/users/cmdr_nova';
$quote = $cmdr . '/notes/abc123';
$remote = 'https://example.social/users/alice';
$remoteQuote = $remote . '/statuses/42';

$create = ap_local_observe_store_object_id('Create', 'Quote', $quote, $cmdr, $cmdr . '/activities/create-1', $shana);
$approved = ap_local_observe_store_object_id('Update', 'Quote', $quote, $cmdr, $cmdr . '/updates/u1', $shana);
$edited = ap_local_observe_store_object_id('Update', 'Quote', $quote . '/', $cmdr, $cmdr . '/updates/u2', $shana);
$check(str_contains($create, '#quote-'), 'quote Create uses the stable quote key');
$check($approved === $create, 'approval Update of a local quote shares the Create row');
$check($edited === $create, 'later edit of the quote shares the Create row');

$rCreate = ap_local_observe_store_object_id('Create', 'Quote', $remoteQuote, $remote, $remoteQuote . '/activity', $shana);
$rUpdate = ap_local_observe_store_object_id('Update', 'Quote', $remoteQuote, $remote, $remoteQuote . '#updates/1', $shana);
$check($rUpdate === $rCreate, 'remote (Mastodon) quote Create and approval Update share one row');
$check($rCreate !== $create, 'different quoting notes stay separate');

// Non-quote Updates keep per-edit keys ("edited a post you liked").
$u1 = ap_local_observe_store_object_id('Update', 'Update', $remoteQuote, $remote, 'https://example.social/u/1', $shana);
$u2 = ap_local_observe_store_object_id('Update', 'Update', $remoteQuote, $remote, 'https://example.social/u/2', $shana);
$check(str_contains($u1, '#update-') && $u1 !== $u2, 'plain Updates stay per-edit');

// Existing like / boost / plain mention keys are unchanged.
$like = ap_local_observe_store_object_id('Like', 'Like', $shana . '/notes/n1', $remote, 'https://example.social/likes/1', $shana);
$check(str_contains($like, '#like-'), 'likes keep the stable like key');
$boost = ap_local_observe_store_object_id('Announce', 'Announce', $shana . '/notes/n1', $remote, 'https://example.social/boosts/1', $shana);
$check(str_contains($boost, '#reblog-'), 'boosts of our notes keep the stable reblog key');
$check(ap_local_observe_store_object_id('Create', 'Create', $remoteQuote, $remote, 'x', $shana) === $remoteQuote, 'plain mention Create keeps the note id');
$check(ap_local_observe_store_object_id('Create', 'Create', null, $remote, 'https://example.social/act/9', $shana) === 'https://example.social/act/9#obj', 'missing object falls back to the activity id');

// The inbox hook skips a second Web Push for a quote Update that hit an existing row.
$src = (string) file_get_contents(dirname(__DIR__) . '/ap-inbox.php');
$check(str_contains($src, "&& !\$quoteRowExisted) {"), 'quote Update on an existing row does not push again');

echo "inbox-quote-notify-dedupe-smoke: {$n} checks passed\n";
