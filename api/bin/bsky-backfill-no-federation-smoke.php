<?php
declare(strict_types=1);
/**
 * Own Bluesky AppView commits backfill into local rows and sync queues.
 * They must not call ActivityPub delete or delivery.
 * SQLite in memory; no network.
 */
$api = getenv('VAAK_BSKY_BACKFILL_API') ?: dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$GLOBALS['pdo']->exec('CREATE TABLE bsky_sessions (owner_user_id INTEGER, did TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE outbox_notes (id TEXT PRIMARY KEY, raw_create_json TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE masto_statuses (note_id TEXT, local_id INTEGER)');
$GLOBALS['pdo']->exec('CREATE TABLE bsky_crossposts (note_id TEXT, owner_user_id INTEGER, bsky_uri TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE bsky_post_links (fediverse_id TEXT, ap_object_id TEXT, bsky_uri TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE ap_publish_delivery_queue (id INTEGER PRIMARY KEY, note_id TEXT)');
$GLOBALS['pdo']->exec('CREATE TABLE ap_fanout_delivery_queue (id INTEGER PRIMARY KEY, activity_key TEXT)');
$GLOBALS['delivery'] = [];
$GLOBALS['sync'] = [];

function ap_db(): PDO
{
    return $GLOBALS['pdo'];
}
function ap_delete_local_status(int $id): array
{
    $GLOBALS['delivery'][] = 'ap_delete_local_status';
    return ['ok' => true];
}
function ap_deliver_public_activity(): void
{
    $GLOBALS['delivery'][] = 'ap_deliver_public_activity';
}
function ap_deliver_fanout(): void
{
    $GLOBALS['delivery'][] = 'ap_deliver_fanout';
}
function ap_deliver_signed_json(): void
{
    $GLOBALS['delivery'][] = 'ap_deliver_signed_json';
}
function ap_deliver_fanout_background(): void
{
    $GLOBALS['delivery'][] = 'ap_deliver_fanout_background';
}
function ap_publish_delivery_enqueue(): bool
{
    $GLOBALS['delivery'][] = 'ap_publish_delivery_enqueue';
    return true;
}
function ap_outbox_enqueue(): bool
{
    $GLOBALS['delivery'][] = 'ap_outbox_enqueue';
    return true;
}
function ap_bsky_import_own_post_as_local(int $owner, array $post, int $depth = 0): array
{
    $GLOBALS['sync'][] = 'import_post';
    return ['ok' => true, 'created' => true, 'note_id' => 'https://mkultra.monster/users/example/notes/imported'];
}
function ap_bsky_local_note_id_for_at_uri(string $uri, int $owner = 0, bool $allowFetch = true): ?string
{
    return str_contains($uri, '/app.bsky.feed.post/gone')
        ? 'https://mkultra.monster/users/example/notes/imported'
        : null;
}
function ap_bsky_own_repost_profile_sync(int $owner, string $subject, bool $reposted): void
{
    $GLOBALS['sync'][] = $reposted ? 'repost_sync' : 'repost_undo';
}
function ap_bsky_background_sync_enqueue(int $owner, string $collection, bool $force = false): bool
{
    $GLOBALS['sync'][] = 'queue:' . $collection . ($force ? ':force' : '');
    return true;
}
function ap_bsky_follow_sync_enqueue(int $owner, ?PDO $db = null, bool $force = false): void
{
    $GLOBALS['sync'][] = 'queue:follows' . ($force ? ':force' : '');
}
function ap_bsky_actor_refresh_enqueue(int $owner, string $actor, bool $force = false, ?PDO $db = null): void
{
    $GLOBALS['sync'][] = 'profile_refresh';
}
function ap_bsky_record_embed_view(?array $embed, string $did): ?array
{
    return ['$type' => 'app.bsky.embed.images#view', 'images' => [['thumb' => 'https://cdn.bsky.app/img/feed_thumbnail/plain/did:plc:self/cid@jpeg']]];
}

reference_load($api . '/ap-bsky.php', [
    'ap_bsky_jetstream_own_session_owners',
    'ap_bsky_imported_note_origin',
    'ap_bsky_retract_imported_note',
    'ap_bsky_jetstream_backfill_own',
]);

$checks = 0;
function backfill_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}
function function_source(string $file, string $name): string
{
    $tokens = token_get_all((string) file_get_contents($file));
    for ($i = 0, $n = count($tokens); $i < $n; $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) {
            continue;
        }
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) {
            $j++;
        }
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING || $tokens[$j][1] !== $name) {
            continue;
        }
        $code = '';
        $depth = 0;
        $body = false;
        for ($k = $i; $k < $n; $k++) {
            $token = $tokens[$k];
            $code .= is_array($token) ? $token[1] : $token;
            if ($token === '{') {
                $depth++;
                $body = true;
            }
            if ($token === '}') {
                $depth--;
            }
            if ($body && $depth === 0) {
                return $code;
            }
        }
    }
    throw new RuntimeException('missing function ' . $name);
}

$bsky = $api . '/ap-bsky.php';
$forbidden = [
    'ap_delete_local_status',
    'ap_deliver_public_activity',
    'ap_deliver_fanout',
    'ap_deliver_signed_json',
    'ap_deliver_fanout_background',
    'ap_fanout_delivery',
    'ap_publish_delivery_enqueue',
    'ap_outbox_enqueue',
];
foreach (['ap_bsky_jetstream_backfill_own', 'ap_bsky_retract_imported_note', 'ap_bsky_import_own_post_as_local'] as $name) {
    $source = function_source($bsky, $name);
    foreach ($forbidden as $call) {
        backfill_check(!str_contains($source, $call), $name . ' does not call ' . $call);
    }
}
$reconcile = function_source($bsky, 'ap_bsky_reconcile_deleted_crossposts');
$originAt = strpos($reconcile, "ap_bsky_imported_note_origin(\$noteId) === 'bluesky'");
$deleteAt = strpos($reconcile, 'ap_delete_local_status');
backfill_check($originAt !== false && $deleteAt !== false && $originAt < $deleteAt, 'bluesky-origin crossposts retract locally before any federating delete');
$ingest = function_source($bsky, 'ap_bsky_jetstream_ingest_event');
backfill_check(str_contains($ingest, 'ap_bsky_jetstream_backfill_own('), 'jetstream ingest backfills own commits');

$pdo = $GLOBALS['pdo'];
$pdo->exec("INSERT INTO bsky_sessions VALUES (7, 'did:plc:self')");
backfill_check(ap_bsky_jetstream_own_session_owners('did:plc:self') === [7], 'own session DID maps to its owner');
backfill_check(ap_bsky_jetstream_own_session_owners('did:plc:other') === [], 'another DID is not this session');

$imported = 'https://mkultra.monster/users/example/notes/imported';
$mirror = 'https://mkultra.monster/users/example/notes/mirror';
$ins = $pdo->prepare('INSERT INTO outbox_notes VALUES (?, ?)');
$ins->execute([$imported, '{"object":{"vaakOrigin":"bluesky"}}']);
$ins->execute([$mirror, '{"object":{"vaakOrigin":"vaak"}}']);
$pdo->prepare('INSERT INTO masto_statuses VALUES (?, ?)')->execute([$imported, 11]);
$pdo->prepare('INSERT INTO masto_statuses VALUES (?, ?)')->execute([$mirror, 12]);
$pdo->prepare('INSERT INTO bsky_crossposts VALUES (?, ?, ?)')->execute([$imported, 7, 'at://did:plc:self/app.bsky.feed.post/gone']);
$pdo->prepare('INSERT INTO bsky_crossposts VALUES (?, ?, ?)')->execute([$mirror, 7, 'at://did:plc:self/app.bsky.feed.post/mirror']);

backfill_check(ap_bsky_imported_note_origin($imported) === 'bluesky', 'imported note origin is bluesky');
backfill_check(ap_bsky_imported_note_origin($mirror) === 'vaak', 'mirror note origin stays vaak');
$retracted = ap_bsky_retract_imported_note(7, $imported);
backfill_check(!empty($retracted['retracted']), 'bluesky-origin note is removed locally');
backfill_check($pdo->query('SELECT COUNT(*) FROM outbox_notes WHERE id = ' . $pdo->quote($imported))->fetchColumn() === 0, 'imported outbox row is gone');
backfill_check($pdo->query('SELECT COUNT(*) FROM masto_statuses WHERE note_id = ' . $pdo->quote($imported))->fetchColumn() === 0, 'imported status row is gone');
backfill_check($pdo->query('SELECT COUNT(*) FROM bsky_crossposts WHERE note_id = ' . $pdo->quote($imported))->fetchColumn() === 0, 'imported crosspost map is gone');
$kept = ap_bsky_retract_imported_note(7, $mirror);
backfill_check(!empty($kept['skipped']), 'vaak-origin mirror is not retracted here');
backfill_check($pdo->query('SELECT COUNT(*) FROM outbox_notes WHERE id = ' . $pdo->quote($mirror))->fetchColumn() === 1, 'mirror outbox row remains');

$did = 'did:plc:self';
$commit = static function (string $collection, string $action, array $record = []) use ($did): array {
    return ap_bsky_jetstream_backfill_own($did, $collection, $action, 'at://' . $did . '/' . $collection . '/' . ($action === 'delete' && $collection === 'app.bsky.feed.post' ? 'gone' : 'rkey'), ['record' => $record, 'cid' => 'cid']);
};
backfill_check(in_array('import_post', $commit('app.bsky.feed.post', 'create', ['text' => 'hello', 'embed' => ['$type' => 'app.bsky.embed.images']])['actions'], true), 'own post create imports locally');
backfill_check(in_array('retract_post', $commit('app.bsky.feed.post', 'delete')['actions'], true), 'own post delete retracts the import');
$repost = $commit('app.bsky.feed.repost', 'create', ['subject' => ['uri' => 'at://did:plc:other/app.bsky.feed.post/1']]);
backfill_check(in_array('repost_sync', $repost['actions'], true) && in_array('repost_refresh', $repost['actions'], true), 'own repost updates the local boost row and queues refresh');
$undo = $commit('app.bsky.feed.repost', 'delete', ['subject' => ['uri' => 'at://did:plc:other/app.bsky.feed.post/1']]);
backfill_check(in_array('repost_undo', $undo['actions'], true), 'own repost delete drops the local boost row');
backfill_check(in_array('like_refresh', $commit('app.bsky.feed.like', 'create', ['subject' => ['uri' => 'at://did:plc:other/app.bsky.feed.post/1']])['actions'], true), 'own like queues favourites sync');
backfill_check(in_array('unlike_refresh', $commit('app.bsky.feed.like', 'delete')['actions'], true), 'own unlike queues favourites sync');
backfill_check(in_array('follow_refresh', $commit('app.bsky.graph.follow', 'create', ['subject' => 'did:plc:other'])['actions'], true), 'own follow queues follow sync');
backfill_check(in_array('unfollow_refresh', $commit('app.bsky.graph.follow', 'delete')['actions'], true), 'own unfollow queues follow sync');
backfill_check(in_array('profile_refresh', $commit('app.bsky.actor.profile', 'update', ['displayName' => 'Example'])['actions'], true), 'own profile edit queues actor refresh');
backfill_check($commit('app.bsky.graph.block', 'create', ['subject' => 'did:plc:other'])['actions'] === [], 'blocks are not given an ActivityPub delivery action');
backfill_check($GLOBALS['delivery'] === [], 'backfill and retract called no ActivityPub delivery');
backfill_check((int) $pdo->query('SELECT COUNT(*) FROM ap_publish_delivery_queue')->fetchColumn() === 0, 'publish delivery queue stays empty');
backfill_check((int) $pdo->query('SELECT COUNT(*) FROM ap_fanout_delivery_queue')->fetchColumn() === 0, 'fan-out delivery queue stays empty');
backfill_check(in_array('queue:reposts:force', $GLOBALS['sync'], true) && in_array('queue:favourites:force', $GLOBALS['sync'], true) && in_array('queue:follows:force', $GLOBALS['sync'], true), 'repost like and follow syncs are forced');

echo "bsky-backfill-no-federation-smoke: {$GLOBALS['checks']} checks passed\n";
