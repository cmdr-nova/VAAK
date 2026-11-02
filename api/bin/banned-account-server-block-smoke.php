<?php
declare(strict_types=1);
/**
 * Banned local accounts show only "banned" on public routes, and server-wide
 * actor blocks no longer send a Block in every local user's name.
 * Needs a throwaway Postgres database; all tables live in a private schema
 * (vaak_banned_smoke) that is dropped at the end:
 *   VAAK_SMOKE_DSN='pgsql:host=/tmp;dbname=vaaksmoke' php api/bin/banned-account-server-block-smoke.php
 * No network, no Redis.
 */
$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$dsn = (string) getenv('VAAK_SMOKE_DSN');
if ($dsn === '' || str_contains($dsn, 'novalandia')) {
    fwrite(STDERR, "set VAAK_SMOKE_DSN to a throwaway pgsql database (never production)\n");
    exit(2);
}
// Functions under test take this connection, so ap_db() (and its full
// production schema check) is never opened.
$raw = new PDO($dsn, null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$raw->exec('DROP SCHEMA IF EXISTS vaak_banned_smoke CASCADE');
$raw->exec('CREATE SCHEMA vaak_banned_smoke');
$raw->exec('SET search_path TO vaak_banned_smoke');
$raw->exec('CREATE TABLE ap_users (id BIGSERIAL PRIMARY KEY, username TEXT, email TEXT, password_hash TEXT, actor_key TEXT, actor_id TEXT, is_admin INTEGER, created_at TEXT, updated_at TEXT, disabled_at TEXT)');
$raw->exec('CREATE TABLE events (id BIGSERIAL PRIMARY KEY, created_at TEXT, type TEXT, actor_id TEXT, object_id TEXT, target_actor TEXT)');
$raw->exec("CREATE TABLE ap_user_blocks (id BIGSERIAL PRIMARY KEY, owner_user_id BIGINT, scope TEXT, value TEXT, kind TEXT DEFAULT 'block', reason TEXT, created_at TEXT)");
define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-db.php';
require_once dirname(__DIR__) . '/ap-inbox.php';
require_once dirname(__DIR__) . '/ap-banned-account.php';
$db = $raw;
$now = gmdate('c');
foreach (['cmdr_nova' => null, 'shinhati' => $now, 'luna' => null, 'juno' => null] as $name => $disabled) {
    $db->prepare('INSERT INTO ap_users (username, email, password_hash, actor_key, actor_id, is_admin, created_at, updated_at, disabled_at) VALUES (?,?,?,?,?,?,?,?,?)')
        ->execute([$name, $name . '@example.test', 'x', $name, 'https://mkultra.monster/users/' . $name, $name === 'cmdr_nova' ? 1 : 0, $now, $now, $disabled]);
}

// Banned lookup.
$check(ap_local_banned_user_by_username('shinhati', $GLOBALS['raw']) !== null, 'banned user found');
$check(ap_local_banned_user_by_username('luna', $GLOBALS['raw']) === null, 'active user is not banned');
$check(ap_local_banned_user_by_username('cmdr_nova', $GLOBALS['raw']) === null, 'operator never treated as banned');

$serve = static function (string $sub, string $accept, string $method = 'GET'): array {
    $_SERVER['REQUEST_METHOD'] = $method;
    $_SERVER['HTTP_ACCEPT'] = $accept;
    http_response_code(200);
    ob_start();
    ap_user_serve_banned(ap_local_banned_user_by_username('shinhati', $GLOBALS['raw']) ?? [], $sub);
    return [http_response_code(), (string) ob_get_clean()];
};
[$code, $body] = $serve('', 'application/activity+json');
$doc = json_decode($body, true);
$check($code === 200, 'actor doc is 200');
$check(is_array($doc) && ($doc['suspended'] ?? null) === true, 'actor doc is marked suspended');
$check(is_array($doc) && ($doc['id'] ?? '') === 'https://mkultra.monster/users/shinhati', 'actor doc keeps its id');
$check(is_array($doc) && !isset($doc['icon']) && !isset($doc['image']), 'no avatar or header');
$check(is_array($doc) && str_contains((string) ($doc['summary'] ?? ''), 'banned'), 'summary says banned');
[$code, $body] = $serve('outbox', 'application/activity+json');
$col = json_decode($body, true);
$check(is_array($col) && ($col['totalItems'] ?? -1) === 0, 'outbox is empty');
[$code] = $serve('notes/abc123', 'application/activity+json');
$check($code === 410, 'notes are 410 Gone');
[$code] = $serve('feed.atom', 'application/atom+xml');
$check($code === 410, 'feeds are 410 Gone');
[$code] = $serve('inbox', 'application/activity+json', 'POST');
$check($code === 410, 'inbox deliveries are refused');
[$code, $body] = $serve('', 'text/html');
$check($code === 410 && str_contains($body, '@shinhati@mkultra.monster') && str_contains($body, 'banned'), 'browser page says banned');
$check(!str_contains($body, 'shinhati@example.test'), 'browser page has no email');

// Server-wide block: blocking sends nothing.
$fed = ap_instance_federate_actor_block('https://remote.example/users/troll', true);
$check(($fed['attempted'] ?? -1) === 0, 'server block sends no Block fan-out');

// Unblock: Undo only from accounts that really sent a Block, minus personal blocks.
$target = 'https://remote.example/users/troll';
$ins = $db->prepare('INSERT INTO events (created_at, type, actor_id, object_id, target_actor) VALUES (?,?,?,?,?)');
foreach (['cmdr_nova', 'luna', 'juno'] as $i => $name) {
    $ins->execute([$now, 'Block', 'https://mkultra.monster/users/' . $name, 'https://mkultra.monster/users/' . $name . '/blocks/' . $i, $target]);
}
$ins->execute([$now, 'Block', 'https://mkultra.monster/users/luna', 'https://mkultra.monster/users/luna/blocks/x', 'https://remote.example/users/other']);
$db->prepare('INSERT INTO ap_user_blocks (owner_user_id, scope, value, kind, created_at) VALUES ((SELECT id FROM ap_users WHERE username = ?), ?, ?, ?, ?)')
    ->execute(['juno', 'actor', $target, 'block', $now]);
$who = ap_local_actors_for_instance_block_fanout($target, $db);
sort($who);
$check($who === ['https://mkultra.monster/users/cmdr_nova', 'https://mkultra.monster/users/luna'], 'unblock undoes only logged Blocks, keeps personal blocks: ' . json_encode($who));
$check(ap_local_actors_for_instance_block_fanout('https://remote.example/users/nobody', $db) === [], 'never-blocked target sends nothing');

$raw->exec('DROP SCHEMA IF EXISTS vaak_banned_smoke CASCADE');
if ($failures !== []) {
    fwrite(STDERR, "FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "ok {$checks} checks\n";
