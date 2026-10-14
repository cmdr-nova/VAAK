<?php
declare(strict_types=1);
/**
 * Mastodon API reply content must be the reply, not the parent excerpt.
 *
 * Ice Cubes paints status.content. The mention serializer used to prepend
 * "↩ " + ap_masto_reply_parent_preview() (the same one-line parent teaser the
 * web Mentions stripper removes). That bake is gone. A stored body that is
 * exactly that bake of the same parent unwraps; already-built reply HTML drops
 * the leading ↩ paragraph. Quote targets stay on the quote URL extractor.
 * No live bootstrap, database, or network.
 */
$api = getenv('VAAK_NOTIF_PREVIEW_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
stream_wrapper_unregister('http');
stream_wrapper_unregister('https');
reference_load($api . '/ap-masto-entities.php', [
    'ap_masto_reply_api_text',
    'ap_masto_clean_mention_text',
    'ap_masto_reply_parent_preview',
    'ap_masto_api_strip_reply_context',
    'ap_masto_status_is_reply',
    'ap_masto_drop_leading_machine_paragraph',
    'ap_masto_drop_leading_reply_bake_paragraph',
    'ap_masto_content_with_mentions',
    'ap_masto_repair_at_hash_tags',
    'ap_masto_unglue_bare_handles',
    'ap_masto_unglue_host_mentions',
    'ap_masto_resolve_bare_username',
    'ap_masto_resolve_bsky_handle_actor',
    'ap_masto_mention_from_actor',
    'ap_masto_linkify_hashtags_escaped',
    'ap_masto_tag_url',
    'ap_masto_quote_url_from_event_summary',
    'ap_url_looks_like_status_object',
]);
$checks = 0;
function reply_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

$parent = 'hell yup, the goat as usual';
$reply = '@cmdr_nova big ups https://youtu.be/Z0Uh3OJCx3o?si=-2MIb_0WE3_AACdV';
$note = 'https://mkultra.monster/users/cmdr_nova/notes/abc123';
$bskyPost = 'https://bsky.app/profile/alice.test/post/abc123';

$serializer = (string) file_get_contents($api . '/ap-masto-entities.php');
$fnAt = strpos($serializer, 'function ap_masto_status_from_mention');
$fnEnd = strpos($serializer, "\nfunction ", $fnAt === false ? 0 : $fnAt + 10);
reply_check($fnAt !== false && $fnEnd !== false, 'mention serializer is present');
$mentionFn = substr($serializer, (int) $fnAt, (int) $fnEnd - (int) $fnAt);
reply_check(
    preg_match('/ap_masto_reply_api_text\(\(string\) \(\$row\[\'content\'\] \?\? \'\'\)\);/', $mentionFn) === 1,
    'mention serializer formats reply text only'
);
reply_check(!str_contains($mentionFn, 'ap_masto_reply_parent_preview'), 'mention serializer no longer builds a parent teaser');
reply_check(!preg_match('/[\'"]↩[^\'"]*[\'"]\s*\./', $mentionFn), 'mention serializer does not concatenate a ↩ prefix');
$normalize = (string) file_get_contents($api . '/ap-normalize.php');
reply_check(str_contains($normalize, 'ap_masto_api_strip_reply_context'), 'status normalize strips legacy reply context');

reply_check(ap_masto_reply_parent_preview($parent) === $parent, 'screenshot parent preview is the single visible line');
$plainReply = ap_masto_reply_api_text($reply);
reply_check($plainReply === $reply, 'reply text is unchanged when no bake was stored');
reply_check(!str_contains($plainReply, '↩') && !str_contains($plainReply, 'hell yup'), 'fresh reply does not gain the parent line');

$legacy = '↩ ' . ap_masto_reply_parent_preview($parent) . "\n\n" . $reply;
reply_check(ap_masto_reply_api_text($legacy, $parent) === $reply, 'exact ↩ parent bake unwraps to the screenshot reply');
reply_check(
    ap_masto_reply_api_text($legacy, ap_masto_reply_parent_preview($parent)) === $reply,
    'htmlspecialchars preview round-trips through the unwrap'
);
reply_check(
    ap_masto_reply_api_text($legacy, 'a different parent') === $legacy,
    'a ↩ line that is not this parent preview stays in the stored reply'
);
$ampParent = 'hell yup, the goat & usual';
$ampLegacy = '↩ ' . ap_masto_reply_parent_preview($ampParent) . "\n\n" . $reply;
reply_check(ap_masto_reply_api_text($ampLegacy, $ampParent) === $reply, 'encoded parent preview still unwraps only the bake');

$fresh = ap_masto_content_with_mentions($plainReply);
reply_check(!str_contains($fresh['content'], 'hell yup'), 'formatted reply omits the parent excerpt');
reply_check(!str_contains($fresh['content'], '↩'), 'formatted reply omits the reply-context marker');
reply_check(str_contains($fresh['content'], 'big ups'), 'formatted reply keeps the commentary');
reply_check(str_contains($fresh['content'], 'https://youtu.be/Z0Uh3OJCx3o?si=-2MIb_0WE3_AACdV'), 'formatted reply keeps the video link');
reply_check(str_contains($fresh['content'], '@<span>cmdr_nova</span>'), 'bare @cmdr_nova becomes a mention');
$mentionIds = array_column($fresh['mentions'], 'id');
reply_check(in_array('1', $mentionIds, true), '@cmdr_nova resolves without a database');

$baked = ap_masto_content_with_mentions($legacy);
reply_check(str_contains($baked['content'], '↩'), 'legacy formatter still shows the old two-paragraph shape');
reply_check(str_contains($baked['content'], 'hell yup, the goat as usual'), 'legacy first paragraph is the parent text');
$quote = [
    'state' => 'accepted',
    'quoted_status_id' => '1791368209200009999',
    'quoted_status' => [
        'id' => '1791368209200009999',
        'content' => '<p>the quoted post body stays</p>',
        'in_reply_to_id' => null,
    ],
];
$status = [
    'id' => '1791368209200001230',
    'content' => $baked['content'],
    'in_reply_to_id' => '1791368209200001230',
    'in_reply_to_account_id' => '1',
    'in_reply_to' => $note,
    'vaak_in_reply_to_url' => $note,
    'mentions' => $fresh['mentions'],
    'quote' => $quote,
];
$stripped = ap_masto_api_strip_reply_context($status);
reply_check($stripped['content'] === $fresh['content'], '↩ parent paragraph is removed; reply HTML is the reply alone');
reply_check($stripped['in_reply_to_id'] === '1791368209200001230', 'in_reply_to_id stays on the status');
reply_check($stripped['in_reply_to_account_id'] === '1', 'in_reply_to_account_id stays on the status');
reply_check($stripped['in_reply_to'] === $note, 'in_reply_to URL stays on the status');
reply_check($stripped['vaak_in_reply_to_url'] === $note, 'reply parent URL stays on the status');
reply_check($stripped['quote'] === $quote, 'quote entity is not rewritten while the bake is removed');
reply_check($stripped['mentions'] === $fresh['mentions'], 'mentions survive the content strip');

$kept = ap_masto_api_strip_reply_context([
    'content' => $baked['content'],
    'in_reply_to_id' => null,
    'in_reply_to_account_id' => null,
]);
reply_check($kept['content'] === $baked['content'], 'a non-reply keeps an authored ↩ paragraph');

$onlyArrow = ap_masto_content_with_mentions('↩ user wrote only this');
$onlyStatus = ap_masto_api_strip_reply_context([
    'content' => $onlyArrow['content'],
    'in_reply_to_id' => '42',
]);
reply_check($onlyStatus['content'] === $onlyArrow['content'], 'a reply that is only an authored ↩ line is kept');

$longPreview = str_repeat('界', 141);
$longHtml = ap_masto_content_with_mentions('↩ ' . $longPreview . "\n\n" . $reply);
$longStatus = ap_masto_api_strip_reply_context([
    'content' => $longHtml['content'],
    'in_reply_to_id' => 42,
]);
reply_check(str_contains($longStatus['content'], '界'), 'a leading ↩ paragraph longer than 140 characters stays');
reply_check(ap_masto_status_is_reply(['in_reply_to_id' => 42]), 'positive integer reply id counts as a reply');
reply_check(!ap_masto_status_is_reply(['in_reply_to_id' => 0, 'in_reply_to' => '']), 'empty reply fields are not a reply');

$repeated = ap_masto_reply_api_text('hell yup, the goat as usual');
reply_check($repeated === 'hell yup, the goat as usual', 'a reply that repeats the parent words without ↩ is kept');
$typed = ap_masto_reply_api_text('@cmdr_nova big ups hell yup, the goat as usual https://youtu.be/Z0Uh3OJCx3o?si=-2MIb_0WE3_AACdV');
reply_check(str_contains($typed, 'hell yup, the goat as usual'), 'parent words the author actually typed stay in the reply');

$mastodon = '<p><span class="h-card"><a href="https://mkultra.monster/users/cmdr_nova" class="u-url mention">@<span>cmdr_nova</span></a></span> thanks, that tracks</p>';
$mastodonStatus = ap_masto_api_strip_reply_context([
    'content' => $mastodon,
    'in_reply_to_id' => '1791368209200001230',
    'in_reply_to_account_id' => '1',
    'quote' => null,
]);
reply_check($mastodonStatus['content'] === $mastodon, 'Mastodon reply HTML without a parent excerpt stays');
reply_check($mastodonStatus['quote'] === null, 'absent quote stays absent');

$misskey = "nice thread\n\nRE: {$note}\nhell yup, the goat as usual";
reply_check(ap_masto_clean_mention_text($misskey) === 'nice thread', 'Misskey trailing RE fallback drops the quoted lines');
reply_check(ap_masto_quote_url_from_event_summary($misskey) === $note, 'Misskey RE URL is still the quote target');
$leading = "RE: {$note}\nI disagree with the quote";
reply_check(ap_masto_clean_mention_text($leading) === 'I disagree with the quote', 'leading RE keeps the following commentary');
$crlf = "nice thread\r\n\r\nRE: {$note}\r\nhell yup, the goat as usual";
reply_check(ap_masto_clean_mention_text($crlf) === 'nice thread', 'CRLF Misskey fallback drops through the end');

$inline = '<p>@cmdr_nova big ups https://youtu.be/Z0Uh3OJCx3o?si=-2MIb_0WE3_AACdV</p>'
    . '<span class="quote-inline">hell yup, the goat as usual<br>RE: <a href="' . $note . '">' . $note . '</a></span>';
$inlineClean = ap_masto_clean_mention_text($inline);
reply_check(str_contains($inlineClean, '@cmdr_nova big ups'), 'quote-inline commentary remains');
reply_check(str_contains($inlineClean, 'youtu.be/Z0Uh3OJCx3o'), 'quote-inline cleanup keeps the video link');
reply_check(!str_contains($inlineClean, 'hell yup'), 'quote-inline excerpt is not reply text');
reply_check(!str_contains($inlineClean, 'notes/abc123'), 'quote-inline cite URL is not reply text');
reply_check(ap_masto_quote_url_from_event_summary($inline) === $note, 'quote-inline cite is the quote target, not YouTube');

$fedibird = '<p>nice thread</p><blockquote>RE: ' . $note . '<br>hell yup, the goat as usual</blockquote>';
reply_check(ap_masto_clean_mention_text($fedibird) === 'nice thread', 'Fedibird RE blockquote is dropped');
reply_check(ap_masto_quote_url_from_event_summary($fedibird) === $note, 'Fedibird blockquote URL is the quote target');
$userQuote = '<p>see this</p><blockquote>a user quote without a cite url</blockquote>';
reply_check(str_contains(ap_masto_clean_mention_text($userQuote), 'a user quote without a cite url'), 'user blockquote without RE stays');
$userStatus = ap_masto_api_strip_reply_context([
    'content' => $userQuote,
    'in_reply_to_id' => '1791368209200001230',
]);
reply_check($userStatus['content'] === $userQuote, 'reply HTML keeps a user blockquote');

$bsky = "↩ re: {$bskyPost}\n\n{$reply}";
$bskyClean = ap_masto_reply_api_text($bsky);
reply_check($bskyClean === $reply, 'Bluesky ↩ re: prefix is removed from the reply');
reply_check(!str_contains($bskyClean, 'bsky.app'), 'Bluesky parent URL is not left in the reply body');
$bskyHtml = ap_masto_content_with_mentions($bsky);
$bskyStatus = ap_masto_api_strip_reply_context(['content' => $bskyHtml['content']]);
reply_check($bskyStatus['content'] === $fresh['content'], 'already-built Bluesky prefix paragraph is dropped');
reply_check(!ap_masto_status_is_reply($bskyStatus), 'machine prefix drop does not require reply ids');

reply_check(ap_masto_quote_url_from_event_summary($reply) === null, 'a YouTube link in the reply is not a quote');
reply_check(ap_url_looks_like_status_object('https://youtu.be/Z0Uh3OJCx3o') === false, 'YouTube is not a status object');
reply_check(ap_url_looks_like_status_object($note) === true, 'a note URL is a status object');
reply_check(ap_url_looks_like_status_object($bskyPost) === true, 'a Bluesky post URL is a status object');
reply_check(ap_masto_clean_mention_text($reply) === $reply, 'URL-only commentary is not discarded');

$quotedReply = ap_masto_api_strip_reply_context([
    'content' => $baked['content'],
    'in_reply_to_id' => '7',
]);
reply_check($quotedReply['content'] === $fresh['content'], 'a quoted reply loses only its own ↩ parent paragraph');
$quotedAuthor = ap_masto_api_strip_reply_context([
    'content' => $baked['content'],
]);
reply_check($quotedAuthor['content'] === $baked['content'], 'quoted post text that is not a reply keeps a ↩ line');

echo "notif-reply-content-smoke PASS {$checks}; screenshot ↩ parent line stays out of reply content\n";
