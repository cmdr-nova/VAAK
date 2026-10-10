#!/usr/bin/env php
<?php
declare(strict_types=1);
// Isolated policy fixtures: no database, Redis, network or production state.
function ap_user_is_blocked(string $actor, ?string $host, int $owner): bool {
    return $owner === 2 && $host === 'blocked.test';
}
function ap_muted_words_match(int $owner, string ...$texts): ?string {
    return $owner === 2 && str_contains(strtolower(strip_tags(html_entity_decode(implode(' ', $texts)))), 'muted phrase') ? 'muted phrase' : null;
}
function ap_db(): PDO {
    static $db;
    if (!$db) {
        $db = new PDO('sqlite::memory:');
        $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
        $db->exec("CREATE TABLE events (id INTEGER, object_id TEXT, visibility TEXT, actor_id TEXT, type TEXT, action_taken TEXT);
            CREATE TABLE outbox_notes (id TEXT, visibility TEXT, to_json TEXT, cc_json TEXT);
            CREATE TABLE mentions (owner_user_id INTEGER, deleted_at TEXT, activity_type TEXT, object_id TEXT);
            CREATE TABLE followers (owner_actor_id TEXT, actor_id TEXT);
            CREATE TABLE following (owner_actor_id TEXT, actor_id TEXT);");
    }
    return $db;
}
function ap_db_owner_actor_id_for_user_id(int $owner): string {
    return $owner === 2 ? 'https://mkultra.monster/users/bob' : '';
}
require_once dirname(__DIR__) . '/ap-visibility.php';
$count = 0;
$check = static function (bool $ok, string $name) use (&$count): void {
    if (!$ok) { throw new RuntimeException($name); }
    $count++;
};
$clean = ['uri'=>'https://friend.test/posts/1','account'=>['uri'=>'https://friend.test/users/a'],'content'=>'hello','visibility'=>'public'];
$blocked = ['account'=>['url'=>'https://blocked.test/@b'],'content'=>'quote'];
foreach (['quote', 'vaak_quote_preview', 'reblog'] as $field) {
    $st = $clean;
    $st[$field] = $field === 'reblog' ? $blocked : ['quoted_status'=>$blocked];
    $check(ap_visibility_filter_statuses([$st], 2) === [], 'blocked nested ' . $field);
    $check(count(ap_visibility_filter_statuses([$st], 3)) === 1, 'owner isolation ' . $field);
}
$st = $clean;
$st['reblog'] = ['quote'=>['quoted_status'=>['content'=>'<p>MUTED phrase</p>']]];
$check(ap_visibility_filter_statuses([$st], 2) === [], 'muted nested body');
$st = $clean;
$st['spoiler_text'] = 'Muted phrase';
$check(ap_visibility_filter_statuses([$st], 2) === [], 'muted warning');
$check(count(ap_visibility_filter_statuses([$clean, $clean], 2)) === 1, 'deduplication');
foreach (['private', 'direct', 'local', 'unlisted'] as $visibility) {
    $st = $clean;
    $st['visibility'] = $visibility;
    $check(!ap_visibility_public_status($st), 'public discovery excludes ' . $visibility);
    $st = $clean;
    $st['quote'] = ['quoted_status'=>['visibility'=>$visibility]];
    $check(ap_visibility_public_status($st) === ($visibility === 'unlisted'), 'quoted audience ' . $visibility);
}
$check(ap_visibility_public_status($clean), 'public post');
$author = 'https://mkultra.monster/users/alice';
$viewer = 'https://mkultra.monster/users/bob';
$db = ap_db();
$uri = $author . '/notes/private';
$query = $db->prepare('INSERT INTO outbox_notes VALUES (?, ?, ?, ?)');
$query->execute([$uri, 'private', '[]', '[]']);
$private = ['uri'=>$uri, 'visibility'=>'public']; // intentionally stale cache
$check(!ap_visibility_status_audience_allowed($private, 0), 'private guest');
$check(!ap_visibility_status_audience_allowed($private, 2), 'pending follower');
$db->prepare('INSERT INTO followers VALUES (?, ?)')->execute([$author, $viewer]);
$check(ap_visibility_status_audience_allowed($private, 2), 'accepted follower');
$check(!ap_visibility_status_audience_allowed($private, 3), 'private owner isolation');
$directUri = $author . '/notes/direct';
$query->execute([$directUri, 'direct', json_encode([$viewer]), '[]']);
$direct = ['uri'=>$directUri, 'visibility'=>'public'];
$check(ap_visibility_status_audience_allowed($direct, 2), 'explicit recipient');
$check(!ap_visibility_status_audience_allowed($direct, 0), 'direct guest');
$check(!ap_visibility_status_audience_allowed(['uri'=>$author . '/notes/deleted'], 2), 'deleted local cache');
$st = $clean;
$st['quote'] = ['quoted_status'=>$private];
$check(!ap_visibility_status_audience_allowed($st, 0), 'private nested guest');
$check(ap_visibility_status_audience_allowed($st, 2), 'private nested recipient');
$query->execute([$author . '/notes/unlisted', 'unlisted', '[]', '[]']);
$check(ap_visibility_status_audience_allowed(['uri'=>$author . '/notes/unlisted'], 0), 'unlisted direct access');
$remoteUri = 'https://remote.test/posts/direct';
$db->prepare('INSERT INTO events VALUES (?, ?, ?, ?, ?, ?)')->execute([1,$remoteUri,'direct','https://remote.test/users/a','Create','local_observe']);
$remote = ['uri'=>$remoteUri, 'visibility'=>'public'];
$check(!ap_visibility_status_audience_allowed($remote, 2), 'remote direct nonrecipient');
$db->prepare('INSERT INTO mentions VALUES (?, ?, ?, ?)')->execute([2,null,'Create',$remoteUri]);
$check(ap_visibility_status_audience_allowed($remote, 2), 'delivered direct recipient');
echo "PASS privacy moderation: {$count} checks\n";
