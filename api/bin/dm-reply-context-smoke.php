<?php
declare(strict_types=1);

/**
 * DM reply context names a real post once, then stays quiet.
 * A private reply to a public, unlisted, or visible followers-only post shows
 * the "In reply to" block and its Open referenced post link on the first DM
 * that points at that post. A reply to a DM, a later mention of the same post,
 * and any post the viewer could not already open stay blank — no block, no
 * link, and no text from that post. SQLite only; no network and no bootstrap
 * of ap-admin.php.
 */
require __DIR__ . '/migration-reference-functions.php';

$checks = 0;
function dm_reply_check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

function h(mixed $s): string
{
    if ($s === null || $s === false) {
        return '';
    }
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function admin_status_href(string $objectUrl, string $from = 'home'): string
{
    return '?view=status&object=' . rawurlencode($objectUrl) . '&from=' . rawurlencode($from);
}

function actor_handle(?string $actorId, ?string $username = null, bool $allowFetch = false): string
{
    if ($allowFetch) {
        throw new RuntimeException('DM reply context fetched a profile');
    }
    $path = trim((string) (parse_url((string) $actorId, PHP_URL_PATH) ?? ''), '/');
    $base = $path === '' ? '' : basename($path);
    return $base !== '' ? '@' . $base : (string) $actorId;
}

function vaak_actor_id(): string
{
    return 'https://mkultra.monster/users/nova';
}

function admin_owner_user_id(): int
{
    return 7;
}

function ap_db(): PDO
{
    return $GLOBALS['db'];
}

final class DmReplyCountingPdo extends PDO
{
    public int $prepares = 0;

    public function prepare(string $query, array $options = []): PDOStatement|false
    {
        $this->prepares++;
        return parent::prepare($query, $options);
    }
}

reference_load(dirname(__DIR__) . '/ap-admin.php', [
    'admin_dm_reply_contexts_html',
    'admin_dm_visible_reply_targets',
    'admin_dm_grant_followers_only_targets',
    'admin_dm_reply_context_block_html',
]);

$db = new DmReplyCountingPdo('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['db'] = $db;
$db->exec(<<<'SQL'
CREATE TABLE direct_messages (id INTEGER PRIMARY KEY, owner_user_id INTEGER, object_id TEXT, deleted_at TEXT);
CREATE TABLE events (
    id INTEGER PRIMARY KEY, object_id TEXT, type TEXT, actor_id TEXT,
    summary TEXT, visibility TEXT, action_taken TEXT
);
CREATE TABLE outbox_notes (id TEXT PRIMARY KEY, content TEXT, visibility TEXT, to_json TEXT, cc_json TEXT);
CREATE TABLE masto_statuses (note_id TEXT PRIMARY KEY, content_text TEXT, visibility TEXT);
CREATE TABLE following (owner_actor_id TEXT, actor_id TEXT);
CREATE TABLE followers (owner_actor_id TEXT, actor_id TEXT);
CREATE TABLE mentions (owner_user_id INTEGER, object_id TEXT, deleted_at TEXT, activity_type TEXT);
SQL);

$viewer = 'https://mkultra.monster/users/nova';
$public = 'https://mkultra.monster/users/nova/notes/public1';
$remote = 'https://remote.test/users/ada/statuses/1';
$unlisted = 'https://remote.test/posts/unlisted';
$ownFollowers = 'https://mkultra.monster/users/nova/notes/followers1';
$bea = 'https://remote.test/users/bea/statuses/9';
$cy = 'https://remote.test/users/cy/statuses/3';
$erin = 'https://mkultra.monster/users/erin/notes/f1';
$fay = 'https://remote.test/users/fay/statuses/2';
$direct = 'https://remote.test/posts/direct';
$mastoDirect = 'https://remote.test/posts/masto-direct';
$dmObject = 'https://remote.test/users/ada/dm/9';
$dmSlash = 'https://remote.test/users/ada/dm/slash';
$deleted = 'https://remote.test/posts/deleted';
$gone = 'https://remote.test/posts/gone';
$local = 'https://remote.test/posts/local';
$unknown = 'https://unknown.test/posts/1';
$empty = 'https://remote.test/posts/empty';
$mastoOnly = 'https://remote.test/posts/masto-only';
$mastoWins = 'https://remote.test/posts/masto-wins';
$outboxBody = 'https://remote.test/posts/outbox-body';

$db->exec("INSERT INTO outbox_notes VALUES ('$public', 'Visible & \"public\" post', 'public', '[]', '[]')");
$db->exec("INSERT INTO events VALUES (1, '$remote', 'Create', 'https://remote.test/users/ada', 'Remote public post', 'public', 'log')");
$db->exec("INSERT INTO events VALUES (2, '$unlisted', 'Create', 'https://remote.test/users/ada', 'Unlisted hello', 'unlisted', 'log')");
$db->exec("INSERT INTO outbox_notes VALUES ('$ownFollowers', 'Followers only own post', 'private', '[]', '[]')");
$db->exec("INSERT INTO events VALUES (3, '$bea', 'Create', 'https://remote.test/users/bea', 'SECRET-BEA', 'private', 'log')");
$db->exec("INSERT INTO events VALUES (4, '$cy', 'Create', 'https://remote.test/users/cy', 'Cy followers post', 'followers', 'log')");
$db->exec("INSERT INTO following VALUES ('$viewer', 'https://remote.test/users/cy')");
$db->exec("INSERT INTO outbox_notes VALUES ('$erin', 'Erin followers post', 'followers_only', '[]', '[]')");
$db->exec("INSERT INTO followers VALUES ('https://mkultra.monster/users/erin/', '$viewer/')");
$db->exec("INSERT INTO events VALUES (5, '$fay', 'Create', 'https://remote.test/users/fay', 'Fay mentioned post', 'private', 'log')");
$db->exec("INSERT INTO mentions VALUES (7, '$fay/', NULL, 'Create')");
$db->exec("INSERT INTO events VALUES (6, '$direct', 'Create', 'https://remote.test/users/dee', 'SECRET-DIRECT', 'direct', 'log')");
$db->exec("INSERT INTO events VALUES (7, '$mastoDirect', 'Create', 'https://remote.test/users/dee', 'SECRET-MASTO-DIRECT', 'public', 'log')");
$db->exec("INSERT INTO masto_statuses VALUES ('$mastoDirect', 'SECRET-MASTO-DIRECT', 'direct')");
$db->exec("INSERT INTO direct_messages VALUES (1, 7, '$dmObject', NULL)");
$db->exec("INSERT INTO events VALUES (8, '$dmObject', 'Create', 'https://remote.test/users/ada', 'SECRET-DM-OBJECT', 'public', 'log')");
$db->exec("INSERT INTO direct_messages VALUES (2, 7, '$dmSlash/', NULL)");
$db->exec("INSERT INTO events VALUES (9, '$deleted', 'Create', 'https://remote.test/users/ada', 'SECRET-DELETED', 'public', 'log')");
$db->exec("INSERT INTO events VALUES (10, '$deleted', 'Delete', 'https://remote.test/users/ada', '', 'public', 'log')");
$db->exec("INSERT INTO events VALUES (11, '$gone', 'Create', 'https://remote.test/users/ada', 'SECRET-GONE', 'public', 'deleted')");
$db->exec("INSERT INTO events VALUES (12, '$local', 'Create', 'https://remote.test/users/ada', 'SECRET-LOCAL', 'local', 'log')");
$db->exec("INSERT INTO events VALUES (13, '$empty', 'Create', 'https://remote.test/users/ada', '', 'public', 'log')");
$db->exec("INSERT INTO masto_statuses VALUES ('$mastoOnly', 'Masto only text', 'public')");
$db->exec("INSERT INTO events VALUES (14, '$mastoWins', 'Create', 'https://remote.test/users/ada', '', 'public', 'log')");
$db->exec("INSERT INTO masto_statuses VALUES ('$mastoWins', 'From masto', 'public')");
$db->exec("INSERT INTO outbox_notes VALUES ('$mastoWins', 'From outbox', 'public', '[]', '[]')");
$db->exec("INSERT INTO events VALUES (15, '$outboxBody', 'Create', 'https://remote.test/users/ada', '', 'public', 'log')");
$db->exec("INSERT INTO outbox_notes VALUES ('$outboxBody', '<p>From outbox</p>', 'public', '[]', '[]')");

$dmA = 'https://peer.test/dm/a';
$dmB = 'https://peer.test/dm/b';
$thread = [
    ['object_id' => $dmA, 'in_reply_to' => $public],
    ['object_id' => $dmB, 'in_reply_to' => $dmA],
    ['object_id' => 'https://peer.test/dm/c', 'in_reply_to' => $public],
    ['object_id' => 'https://peer.test/dm/d', 'in_reply_to' => $remote],
    ['object_id' => 'https://peer.test/dm/e', 'in_reply_to' => $unlisted],
    ['object_id' => 'https://peer.test/dm/f', 'in_reply_to' => $ownFollowers],
    ['object_id' => 'https://peer.test/dm/g', 'in_reply_to' => $bea],
    ['object_id' => 'https://peer.test/dm/h', 'in_reply_to' => $cy],
    ['object_id' => 'https://peer.test/dm/i', 'in_reply_to' => $cy],
    ['object_id' => 'https://peer.test/dm/j', 'in_reply_to' => $erin],
    ['object_id' => 'https://peer.test/dm/k', 'in_reply_to' => $fay],
    ['object_id' => 'https://peer.test/dm/l', 'in_reply_to' => $direct],
    ['object_id' => 'https://peer.test/dm/m', 'in_reply_to' => $mastoDirect],
    ['object_id' => 'https://peer.test/dm/n', 'in_reply_to' => $dmObject],
    ['object_id' => 'https://peer.test/dm/o', 'in_reply_to' => $dmSlash],
    ['object_id' => 'https://peer.test/dm/p', 'in_reply_to' => $deleted],
    ['object_id' => 'https://peer.test/dm/q', 'in_reply_to' => $gone],
    ['object_id' => 'https://peer.test/dm/r', 'in_reply_to' => $local],
    ['object_id' => 'https://peer.test/dm/s', 'in_reply_to' => $unknown],
    ['object_id' => 'https://peer.test/dm/t', 'in_reply_to' => $empty],
    ['object_id' => 'https://peer.test/dm/u', 'in_reply_to' => $mastoOnly],
    ['object_id' => 'https://peer.test/dm/v', 'in_reply_to' => $mastoWins],
    ['object_id' => 'https://peer.test/dm/w', 'in_reply_to' => $outboxBody],
    ['object_id' => 'https://peer.test/dm/x', 'in_reply_to' => ''],
    ['object_id' => 'https://peer.test/dm/y', 'in_reply_to' => $dmB],
];
$expectLink = [
    0 => true,  // first private reply to the viewer's public post
    1 => false, // reply to the previous DM
    2 => false, // same public post again
    3 => true,  // first reference to a different public post
    4 => true,
    5 => true,  // followers-only post the viewer wrote
    6 => false, // followers-only post the viewer does not follow
    7 => true,  // followers-only author the viewer follows
    8 => false, // that post again
    9 => true,  // local followers-only, viewer is a follower
    10 => true, // followers-only post that mentions the viewer
    11 => false,
    12 => false,
    13 => false,
    14 => false,
    15 => false,
    16 => false,
    17 => false,
    18 => false,
    19 => true,
    20 => true,
    21 => true,
    22 => true,
    23 => false,
    24 => false,
];

$before = $db->prepares;
$html = admin_dm_reply_contexts_html($thread, 'dms');
dm_reply_check(count($html) === count($thread), 'one result per DM');
$linked = 0;
foreach ($expectLink as $index => $show) {
    $piece = (string) ($html[$index] ?? '');
    $hasLink = str_contains($piece, 'Open referenced post');
    dm_reply_check($hasLink === $show, ($show ? 'expected' : 'unexpected') . " reference on message $index");
    if ($show) {
        $linked++;
        dm_reply_check(str_contains($piece, 'dm-reply-context'), "message $index keeps the reply block");
        dm_reply_check(str_contains($piece, 'In reply to '), "message $index keeps the reply label");
        dm_reply_check(str_contains($piece, 'from=dms'), "message $index link stays in the DM return view");
    } else {
        dm_reply_check($piece === '', "message $index renders nothing");
    }
}
dm_reply_check(substr_count(implode("\n", $html), 'Open referenced post') === $linked, 'link count matches the first visible references');
dm_reply_check(str_contains($html[0], 'Visible &amp; &quot;public&quot; post'), 'visible public summary is escaped once');
dm_reply_check(str_contains($html[0], 'In reply to @nova'), 'own post is labeled with its author');
dm_reply_check(str_contains($html[3], 'Remote public post') && str_contains($html[3], 'In reply to @ada'), 'remote public post is labeled once');
dm_reply_check(str_contains($html[19], 'Referenced post') && !str_contains($html[19], 'dm-reply-context__text'), 'a post with no text still gets the block');
dm_reply_check(str_contains($html[21], 'From masto') && !str_contains($html[21], 'From outbox'), 'cached status text still wins over the outbox body');
dm_reply_check(str_contains($html[22], 'From outbox'), 'outbox text fills in when nothing else is cached');
foreach ([
    'SECRET-BEA', 'SECRET-DIRECT', 'SECRET-MASTO-DIRECT', 'SECRET-DM-OBJECT',
    'SECRET-DELETED', 'SECRET-GONE', 'SECRET-LOCAL', $unknown, $dmObject, $direct,
] as $secret) {
    dm_reply_check(!str_contains(implode("\n", $html), $secret), 'hidden target did not leak: ' . $secret);
}
dm_reply_check($db->prepares - $before <= 8, 'the thread resolves targets in a handful of lookups, not one per message');

$dmOnlyBefore = $db->prepares;
$dmOnly = admin_dm_reply_contexts_html([
    ['object_id' => 'https://peer.test/only/1', 'in_reply_to' => ''],
    ['object_id' => 'https://peer.test/only/2', 'in_reply_to' => 'https://peer.test/only/1'],
    ['object_id' => 'https://peer.test/only/3', 'in_reply_to' => 'https://peer.test/only/2'],
], 'dms');
dm_reply_check($dmOnly === ['', '', ''], 'a DM-only thread has no reference blocks');
dm_reply_check($db->prepares === $dmOnlyBefore, 'a DM-only thread does not look up posts');

$saved = $GLOBALS['db'];
$broken = new PDO('sqlite::memory:');
$broken->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['db'] = $broken;
$failed = admin_dm_reply_contexts_html([
    ['object_id' => 'https://peer.test/dm/z', 'in_reply_to' => $public],
], 'dms');
$GLOBALS['db'] = $saved;
dm_reply_check($failed === [''], 'an unreadable parent does not invent a status link');

$root = dirname(__DIR__, 2);
$admin = (string) file_get_contents($root . '/api/ap-admin.php');
dm_reply_check(substr_count($admin, 'Open referenced post') === 1, 'the link text exists only in the DM reply helper');
dm_reply_check(!str_contains($admin, 'function admin_dm_reply_context_html'), 'per-message DM context helper is gone');
$start = strpos($admin, "<?php elseif (\$view === 'dms'): ?>");
$end = strpos($admin, "<?php elseif (\$view === 'report'): ?>", (int) $start);
dm_reply_check($start !== false && $end !== false && $end > $start, 'DM view located');
$view = substr($admin, (int) $start, (int) $end - (int) $start);
dm_reply_check(str_contains($view, 'admin_dm_reply_contexts_html($thread, \'dms\')'), 'DM view asks for thread context once');
dm_reply_check(!str_contains($view, 'admin_status_href'), 'DM view does not build its own status link');
dm_reply_check(!str_contains($view, 'Open referenced post'), 'DM view does not inline the link');
dm_reply_check(str_contains($view, 'value="dm_send"') && str_contains($view, 'value="dm_reply"'), 'DM send and reply forms stay in place');
dm_reply_check(str_contains($view, 'id="dm-message-last"') && str_contains($view, 'focusLatestDm'), 'scroll-to-latest stays in place');
dm_reply_check(str_contains($admin, 'ajax=notif_unread') && str_contains($admin, 'data.dm_count'), 'unread badge poll stays in place');

$elsewhere = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $path = $file->getPathname();
    if (str_contains($path, '/.git/') || str_contains($path, '/vendor/')) {
        continue;
    }
    $ext = strtolower($file->getExtension());
    if (!in_array($ext, ['php', 'js', 'mjs', 'rs', 'css', 'html'], true)) {
        continue;
    }
    if (str_ends_with($path, '/api/ap-admin.php') || str_ends_with($path, '/api/bin/dm-reply-context-smoke.php')) {
        continue;
    }
    $text = (string) file_get_contents($path);
    if (str_contains($text, 'Open referenced post') || str_contains($text, 'dm-reply-context')) {
        $elsewhere[] = substr($path, strlen($root) + 1);
    }
}
dm_reply_check($elsewhere === [], 'no JS, Rust, or other renderer emits the DM reference link');

echo "dm-reply-context-smoke: {$checks} checks passed\n";
