<?php
declare(strict_types=1);
/** Actual PDO exception path; application bootstrap/network IO is not executed. */
require __DIR__ . '/migration-reference-functions.php';
$options = getopt('', ['source:', 'expect-before']);
$source = (string) ($options['source'] ?? dirname(__DIR__) . '/ap-masto-entities.php');
reference_load($source, ['ap_masto_remote_account_acct_key']);
reference_load(dirname(__DIR__) . '/ap-db.php', ['ap_remote_actor_username_is_placeholder']);
$db = new PDO('sqlite::memory:');
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
function ap_db(): PDO { return $GLOBALS['db']; }
$checks = 0;
function acct_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
try {
    $db->prepare('SELECT username, host FROM remote_actors WHERE actor_id = ?');
    throw new RuntimeException('PDO did not reject the absent account table');
} catch (PDOException $e) {
    acct_check(str_contains($e->getMessage(), 'remote_actors'), 'genuine PDO table error');
}
$cache = [1 => [['name' => 'owner-one-tag']], 2 => [['name' => 'owner-two-tag']]];
$GLOBALS['ap_followed_tags_cache'] = $cache;
$warnings = [];
set_error_handler(static function (int $severity, string $message) use (&$warnings): bool {
    $warnings[] = $message;
    return true;
});
$values = [];
foreach (['https://remote.test/@Alice', 'https://remote.test/users/Alice',
    'https://remote.test/usersAlice', 'https://remote.test/@123456',
    'https://remote.test/@Alice?x=1', 'http://remote.test/@Alice'] as $actor) {
    $values[] = ap_masto_remote_account_acct_key($actor);
}
restore_error_handler();
acct_check($values === ['alice@remote.test', null, 'alice@remote.test', null, 'alice?x=1@remote.test', null],
    'canonical path fallback, regex quirk, numeric/query/HTTPS boundaries');
if (array_key_exists('expect-before', $options)) {
    acct_check(count(array_filter($warnings, static fn ($v) => str_contains($v, 'Undefined variable $ownerUserId'))) === 5,
        'before reproduces undefined owner warning for every attempted lookup');
    acct_check($GLOBALS['ap_followed_tags_cache'] !== $cache
        && array_key_exists('', $GLOBALS['ap_followed_tags_cache']), 'before mutates unrelated cache');
    echo "account-acct-key-error: BEFORE REPRO PASS ($checks checks)\n";
    exit;
}
acct_check($warnings === [], 'candidate has no warning on actual caught PDO error');
acct_check($GLOBALS['ap_followed_tags_cache'] === $cache, 'unrelated owner tag cache untouched');
$db->exec('CREATE TABLE remote_actors (actor_id TEXT, username TEXT, host TEXT)');
$insert = $db->prepare('INSERT INTO remote_actors VALUES (?, ?, ?)');
$insert->execute(['https://remote.test/ap/users/123456', 'Bob', 'cache.test']);
acct_check(ap_masto_remote_account_acct_key('https://remote.test/ap/users/123456') === 'bob@cache.test',
    'successful canonical account cache lookup unchanged');
acct_check($GLOBALS['ap_followed_tags_cache'] === $cache, 'successful lookup also leaves tag cache intact');
echo "account-acct-key-error: PASS ($checks actual PDO/fallback/cache checks)\n";
