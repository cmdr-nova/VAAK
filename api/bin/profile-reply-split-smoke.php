<?php
declare(strict_types=1);
/**
 * Local profile Posts/Replies split.
 * A reply is only a note with in_reply_to. Hashtags and top-level @mentions
 * are posts. Counts use the public visibility the profile tabs already list.
 * No Postgres, Redis, or worker.
 */
require_once __DIR__ . '/migration-reference-functions.php';

$root = dirname(__DIR__, 2);
$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

function ap_db(): PDO
{
    return $GLOBALS['db'];
}
function ap_redis_json_get(string $key): ?array
{
    $GLOBALS['redis_gets'][] = $key;
    if (empty($GLOBALS['redis_pass'])) {
        return null;
    }
    $value = $GLOBALS['redis'][$key] ?? null;
    return is_array($value) ? $value : null;
}
function ap_redis_json_set(string $key, array $value, int $ttlSeconds): bool
{
    $GLOBALS['redis_sets'][] = [$key, $ttlSeconds];
    $GLOBALS['redis'][$key] = $value;
    return true;
}

reference_load($root . '/api/ap-db.php', [
    'ap_outbox_note_is_reply',
    'ap_outbox_note_on_public_profile',
    'ap_outbox_replies_list_page',
    'ap_outbox_replies_count',
    'ap_outbox_count_for_actor',
    'ap_outbox_count_all_for_actor',
    'ap_outbox_cached_count',
]);

function profile_sql(string $source, string $marker): string
{
    $at = strpos($source, $marker);
    if ($at === false) {
        throw new RuntimeException('missing ' . $marker);
    }
    $select = strpos($source, 'SELECT COUNT(*)', $at);
    if ($select === false) {
        throw new RuntimeException('missing count SQL after ' . $marker);
    }
    $quote = null;
    for ($i = $select - 1; $i > $at; $i--) {
        $ch = $source[$i];
        if ($ch === '"' || $ch === "'") {
            $quote = $ch;
            break;
        }
        if (!ctype_space($ch)) {
            break;
        }
    }
    if ($quote === null) {
        throw new RuntimeException('SQL quote missing after ' . $marker);
    }
    $end = strpos($source, $quote, $select);
    if ($end === false) {
        throw new RuntimeException('unterminated SQL after ' . $marker);
    }
    return substr($source, $select, $end - $select);
}
function profile_sql_norm(string $sql): string
{
    return preg_replace('/\s+/', '', str_replace('$1', '?', $sql)) ?? $sql;
}

$php = (string) file_get_contents($root . '/api/ap-db.php');
$rust = (string) file_get_contents($root . '/rust/vaak-worker/src/profile_header.rs');
$replySql = profile_sql_norm(profile_sql($php, 'vaak:profile:reply-count:v2:'));
$totalSql = profile_sql_norm(profile_sql($php, 'vaak:profile:outbox-count:v2:'));
$allSql = profile_sql_norm(profile_sql($php, 'vaak:profile:outbox-count:v1:'));
$check($replySql === profile_sql_norm(profile_sql($rust, 'vaak:profile:reply-count:v2:')), 'PHP and Rust reply counts use the same SQL');
$check($totalSql === profile_sql_norm(profile_sql($rust, 'vaak:profile:outbox-count:v2:')), 'PHP and Rust public totals use the same SQL');
$check(str_contains($replySql, "TRIM(COALESCE(in_reply_to,''))<>''"), 'reply count keeps real in_reply_to rows');
$check(str_contains($replySql, "COALESCE(visibility,'public')='public'"), 'reply count is public-only');
$check(str_contains($totalSql, "COALESCE(visibility,'public')='public'"), 'profile total is public-only');
$check(!str_contains($replySql, 'Mention') && !str_contains($replySql, 'mention'), 'reply count drops mention and hashtag scans');
$check(!str_contains($totalSql, 'in_reply_to'), 'public total does not filter replies itself');
$check($allSql === "SELECTCOUNT(*)FROMoutbox_notesWHEREidLIKE?", 'Your Posts still counts every visibility');
$check(!str_contains($php, 'ap_outbox_note_is_reply_or_mention'), 'mention classifier is gone');
$check(!str_contains($php, 'reply-count:v1'), 'reply cache key moved to v2');

$user = (string) file_get_contents($root . '/api/ap-user.php');
$cmdr = (string) file_get_contents($root . '/api/ap-cmdr-nova.php');
$admin = (string) file_get_contents($root . '/api/ap-admin.php');
$header = (string) file_get_contents($root . '/api/bin/local-profile-header-reference.php');
$check(str_contains($user, 'ap_outbox_note_is_reply') && !str_contains($user, 'ap_outbox_note_is_reply_or_mention'), 'HTML profile tabs use real replies');
$check(str_contains($user, "is_array(\$post['record']['reply'] ?? null)"), 'Bluesky reply split stays on record.reply');
$check(str_contains($cmdr, 'ap_outbox_note_is_reply') && str_contains($cmdr, 'standalone public answer'), 'cmdr profile keeps ask answers on Posts');
$check(str_contains($admin, 'ap_outbox_count_all_for_actor') && str_contains($admin, '$rpOutboxTotal - $rpOutboxRepliesTotal'), 'header posts are public total minus replies; Your Posts keeps every note');
$check(str_contains($header, 'vaak:profile:reply-count:v2:fixture') && str_contains($header, 'vaak:profile:outbox-count:v2:fixture'), 'header fixture seeds the v2 count keys');
$check(!str_contains($header, 'reply-count:v1') && !str_contains($header, 'outbox-count:v1'), 'header fixture does not seed the old count keys');
$masto = (string) file_get_contents($root . '/api/ap-masto-entities.php');
$check(!str_contains($masto, 'ap_outbox_replies_count') && !str_contains($masto, 'ap_outbox_note_is_reply'), 'Mastodon statuses_count does not use the profile reply split');

$actor = 'juno_flux';
$prefix = 'https://mkultra.monster/users/' . $actor . '/notes/';
$hashtag = '<p><a href="https://mkultra.monster/tags/flux" class="mention hashtag" rel="tag">#<span>flux</span></a></p>'
    . '<p><a href="https://mkultra.monster/tags/nova" class=\'mention hashtag\'>#nova</a></p>';
$mention = '<p>Hello <a href="https://mkultra.monster/users/ada" class="u-url mention">@ada</a></p>'
    . '<p><a href="https://mkultra.monster/users/bea" class=\'mention\'>@bea</a></p>';
$mentionJson = '{"type":"Create","object":{"tag":[{"type":"Mention","href":"https://mkultra.monster/users/ada","name":"@ada"},{"type": "Mention","name":"@bea"}]}}';
$hashtagJson = '{"type":"Create","object":{"tag":[{"type":"Hashtag","name":"#flux"},{"type": "Hashtag","name":"#nova"}]}}';
$rows = [
    'hashtag' => [$prefix . 'hashtag', $hashtag, null, 'public', $hashtagJson, '2026-10-16T12:00:00Z'],
    'mention' => [$prefix . 'mention', $mention, '', 'public', $mentionJson, '2026-10-16T12:01:00Z'],
    'reply' => [$prefix . 'reply', 'A plain reply', ' https://mkultra.monster/users/ada/notes/parent ', 'public', '{}', '2026-10-16T12:02:00Z'],
    'reply_hashtag' => [$prefix . 'reply-hashtag', $hashtag, 'https://mkultra.monster/users/ada/notes/parent', 'public', $hashtagJson, '2026-10-16T12:02:30Z'],
    'reply_mention' => [$prefix . 'reply-mention', $mention, 'https://mkultra.monster/users/ada/notes/parent', 'public', $mentionJson, '2026-10-16T12:02:45Z'],
    'local' => [$prefix . 'local', $hashtag . $mention, null, 'local', $mentionJson, '2026-10-16T12:03:00Z'],
    'private_reply' => [$prefix . 'private-reply', 'secret reply', 'https://mkultra.monster/users/ada/notes/secret', 'private', '{}', '2026-10-16T12:04:00Z'],
    'other' => ['https://mkultra.monster/users/other/notes/post', $mention, null, 'public', $mentionJson, '2026-10-16T12:05:00Z'],
];

$GLOBALS['db'] = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
$GLOBALS['db']->exec('CREATE TABLE outbox_notes (id TEXT PRIMARY KEY, content TEXT, in_reply_to TEXT, visibility TEXT, raw_create_json TEXT, published TEXT)');
$insert = $GLOBALS['db']->prepare('INSERT INTO outbox_notes (id, content, in_reply_to, visibility, raw_create_json, published) VALUES (?, ?, ?, ?, ?, ?)');
$loaded = [];
foreach ($rows as $name => $row) {
    $insert->execute($row);
    $loaded[$name] = [
        'id' => $row[0],
        'content' => $row[1],
        'in_reply_to' => $row[2],
        'visibility' => $row[3],
        'raw_create_json' => $row[4],
        'published' => $row[5],
    ];
}

$check(ap_outbox_note_is_reply($loaded['hashtag']) === false, 'hashtag-only post is not a reply');
$check(ap_outbox_note_is_reply($loaded['mention']) === false, 'mention-only post is not a reply');
$check(ap_outbox_note_is_reply($loaded['reply']) === true, 'real reply is a reply');
$check(ap_outbox_note_is_reply($loaded['reply_hashtag']) === true, 'reply with hashtags is still a reply');
$check(ap_outbox_note_is_reply($loaded['reply_mention']) === true, 'reply with mentions is still a reply');
$check(ap_outbox_note_is_reply($loaded['local']) === false, 'local-only note is not a reply');
$check(ap_outbox_note_on_public_profile($loaded['hashtag']) === true, 'hashtag post is public');
$check(ap_outbox_note_on_public_profile($loaded['mention']) === true, 'mention post is public');
$check(ap_outbox_note_on_public_profile($loaded['reply']) === true, 'real reply is public');
$check(ap_outbox_note_on_public_profile($loaded['reply_hashtag']) === true, 'reply with hashtags stays public');
$check(ap_outbox_note_on_public_profile($loaded['reply_mention']) === true, 'reply with mentions stays public');
$check(ap_outbox_note_on_public_profile($loaded['local']) === false, 'local-only note is off the public tabs');
$check(ap_outbox_note_on_public_profile($loaded['private_reply']) === false && ap_outbox_note_is_reply($loaded['private_reply']) === true, 'private reply does not join the public tabs');
$check(ap_outbox_note_on_public_profile(['in_reply_to' => null]) === true, 'missing visibility counts as public');

$ask = ['in_reply_to' => 'https://mkultra.monster/users/juno_flux/asks/1', 'visibility' => 'public'];
$isAskAnswer = true;
$isReply = ap_outbox_note_is_reply($ask);
$check($isReply === true && (!$isAskAnswer && $isReply) === false, 'ask answers stay on Posts even with in_reply_to');

$GLOBALS['redis'] = [];
$GLOBALS['redis_gets'] = [];
$GLOBALS['redis_sets'] = [];
$GLOBALS['redis_pass'] = false;
$check(ap_outbox_replies_count('') === 0 && ap_outbox_count_for_actor(' ') === 0 && ap_outbox_replies_list_page('', 10, 0) === [], 'blank actor counts are zero');
$replies = ap_outbox_replies_count('Juno_Flux');
$total = ap_outbox_count_for_actor('Juno_Flux');
$profileGets = $GLOBALS['redis_gets'];
$posts = max(0, $total - $replies);
$check($replies === 3, 'public reply count includes plain, hashtag, and mention replies, got ' . $replies);
$check($total === 5, 'public total skips local-only and private notes, got ' . $total);
$check($posts === 2, 'Posts keeps top-level hashtag and mention posts, got ' . $posts);
$all = ap_outbox_count_all_for_actor($actor);
$check($all === 7, 'Your Posts count keeps local-only and private notes, got ' . $all);
$listed = array_map(static fn(array $row): string => (string) $row['id'], ap_outbox_replies_list_page($actor, 20, 0));
$check($listed === [$prefix . 'reply-mention', $prefix . 'reply-hashtag', $prefix . 'reply'], 'replies tab lists real replies that also have hashtags or mentions');
$check(!in_array($prefix . 'hashtag', $listed, true) && !in_array($prefix . 'mention', $listed, true), 'top-level hashtag and mention posts stay off the replies list');
$check(!in_array('vaak:profile:reply-count:v1:' . $actor, $profileGets, true), 'reply count does not read the v1 cache');
$check(!in_array('vaak:profile:outbox-count:v1:' . $actor, $profileGets, true), 'public total does not read the v1 cache');
$check(in_array('vaak:profile:reply-count:v2:' . $actor, $profileGets, true), 'reply count reads v2');
$check(in_array('vaak:profile:outbox-count:v2:' . $actor, $profileGets, true), 'public total reads v2');
$setKeys = array_column($GLOBALS['redis_sets'], 0);
$check(in_array('vaak:profile:reply-count:v2:' . $actor, $setKeys, true), 'reply count writes v2');
$check(in_array('vaak:profile:outbox-count:v2:' . $actor, $setKeys, true), 'public total writes v2');
$check(in_array('vaak:profile:outbox-count:v1:' . $actor, $setKeys, true), 'Your Posts writes v1');
$check(($GLOBALS['redis']['vaak:profile:reply-count:v2:' . $actor]['count'] ?? null) === 3, 'cached reply count includes hashtag and mention replies');

$GLOBALS['redis_pass'] = true;
$insert->execute([$prefix . 'later-reply', 'later', 'https://mkultra.monster/users/ada/notes/later', 'public', '{}', '2026-10-16T12:06:00Z']);
$check(ap_outbox_replies_count($actor) === 3 && ap_outbox_count_for_actor($actor) === 5, 'v2 cache holds the public counts');
$GLOBALS['redis_pass'] = false;
unset($GLOBALS['redis']['vaak:profile:reply-count:v2:' . $actor], $GLOBALS['redis']['vaak:profile:outbox-count:v2:' . $actor]);
$check(ap_outbox_replies_count($actor) === 4 && ap_outbox_count_for_actor($actor) === 6, 'cache miss sees the new public reply');

if ($failures !== []) {
    fwrite(STDERR, implode("\n", $failures) . "\n");
    exit(1);
}
echo $checks . " profile reply split checks passed\n";
