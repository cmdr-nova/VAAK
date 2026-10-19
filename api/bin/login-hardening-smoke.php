<?php
/**
 * Login hardening: the IP and per-account failure counters, the two-factor
 * attempt limits, and password change (current password required, other
 * sessions and app tokens revoked, current session kept). SQLite in memory,
 * a private session directory, and an in-memory Redis stand-in. No network.
 */
declare(strict_types=1);
require __DIR__ . '/migration-reference-functions.php';

$api = dirname(__DIR__);
$authSource = (string) file_get_contents($api . '/ap-auth.php');
preg_match_all('/^const (AP_AUTH_[A-Z0-9_]+) = (\d+);/m', $authSource, $consts, PREG_SET_ORDER);
foreach ($consts as [, $name, $value]) {
    define($name, (int) $value);
}

$GLOBALS['redis'] = [];
function ap_redis_rate_count(string $bucket, int $window = 900): ?int { return $GLOBALS['redis'][$bucket] = ($GLOBALS['redis'][$bucket] ?? 0) + 1; }
function ap_redis_rate_get(string $bucket): ?int { return $GLOBALS['redis'][$bucket] ?? 0; }
function ap_redis_rate_clear(string $bucket): void { unset($GLOBALS['redis'][$bucket]); }
function ap_db(): PDO { return $GLOBALS['db']; }
function ap_db_now(): string { return gmdate('c'); }
function ap_auth_bootstrap(): void {}
function ap_auth_emit_session_cookie(): void {}
function ap_auth_secret_decrypt(string $blob): ?string { return $blob !== '' ? $blob : null; }
function ap_auth_start_session(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        session_save_path($GLOBALS['sessionDir']);
        session_id('currentsession0001');
        session_start();
    }
}
function ap_auth_login_user(array $user): void { $_SESSION['vaak_user_id'] = (int) $user['id']; }

reference_load($api . '/ap-auth.php', [
    'ap_auth_rate_path', 'ap_auth_rate_fails', 'ap_auth_rate_fail', 'ap_auth_rate_clear',
    'ap_auth_login_ip', 'ap_auth_login_account_bucket', 'ap_auth_login_rate_ok', 'ap_auth_login_rate_fail',
    'ap_auth_user_by_id', 'ap_auth_user_by_login', 'ap_auth_verify_credentials',
    'ap_auth_verify_credentials_limited', 'ap_auth_2fa_verify_code_limited',
    'ap_auth_attempt_login', 'ap_auth_complete_2fa_login', 'ap_auth_2fa_pending_clear',
    'ap_auth_2fa_row', 'ap_auth_2fa_enabled', 'ap_auth_2fa_verify_code', 'ap_auth_2fa_verify_recovery',
    'ap_auth_totp_verify', 'ap_auth_totp_base32_decode', 'ap_auth_totp_base32_encode',
    'ap_auth_user_set_password', 'ap_auth_change_password',
    'ap_auth_revoke_user_credentials', 'ap_auth_revoke_user_sessions',
]);

$n = 0;
function check(bool $ok, string $why): void
{
    if (!$ok) {
        fwrite(STDERR, "login-hardening: {$why}\n");
        exit(1);
    }
    $GLOBALS['n']++;
}

function totp_now(string $secret): string
{
    $key = ap_auth_totp_base32_decode($secret);
    $hash = hash_hmac('sha1', pack('N2', 0, (int) floor(time() / 30)), (string) $key, true);
    $i = ord($hash[19]) & 0x0f;
    $num = ((ord($hash[$i]) & 0x7f) << 24) | (ord($hash[$i + 1]) << 16) | (ord($hash[$i + 2]) << 8) | ord($hash[$i + 3]);
    return str_pad((string) ($num % 1000000), 6, '0', STR_PAD_LEFT);
}

$run = bin2hex(random_bytes(4));
$GLOBALS['sessionDir'] = sys_get_temp_dir() . '/vaak-login-hardening-' . $run;
mkdir($GLOBALS['sessionDir'], 0700);

$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$GLOBALS['db'] = $db;
$db->exec('CREATE TABLE ap_users (id INTEGER PRIMARY KEY, username TEXT, email TEXT, password_hash TEXT, actor_key TEXT, is_admin INTEGER DEFAULT 0, updated_at TEXT, disabled_at TEXT);
CREATE TABLE ap_user_2fa (user_id INTEGER PRIMARY KEY, secret_enc TEXT, pending_secret_enc TEXT DEFAULT \'\', recovery_codes_json TEXT DEFAULT \'[]\', enabled_at TEXT, updated_at TEXT);
CREATE TABLE oauth_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, revoked_at TEXT);
CREATE TABLE oauth_codes (code TEXT PRIMARY KEY, user_id INTEGER, used_at TEXT);');
$pw = static fn (string $p): string => (string) password_hash($p, PASSWORD_BCRYPT, ['cost' => 4]);
$users = ['alice', 'bob', 'carol', 'dave', 'erin'];
$ins = $db->prepare('INSERT INTO ap_users (id, username, email, password_hash, actor_key) VALUES (?, ?, ?, ?, ?)');
foreach ($users as $i => $name) {
    $ins->execute([$i + 1, $name . $run, $name . $run . '@example.test', $pw('right-' . $name), $name . $run]);
}
[$alice, $bob, $carol, $dave, $erin] = array_map(static fn (string $u): string => $u . $run, $users);
$secret = ap_auth_totp_base32_encode(random_bytes(20));
$db->prepare('INSERT INTO ap_user_2fa (user_id, secret_enc, enabled_at, updated_at) VALUES (?, ?, ?, ?), (?, ?, ?, ?)')
    ->execute([3, $secret, ap_db_now(), ap_db_now(), 4, $secret, ap_db_now(), ap_db_now()]);

ap_auth_start_session();
$buckets = [];
// User ids are fixed, so their counters are reset before and after the run.
$fixedBuckets = ['acct:u1', 'acct:u2', 'acct:u3', 'acct:u4', 'acct:u5', '2fa:u3', '2fa:u4', 'pwchange:u1', 'pwchange:u5'];
foreach ($fixedBuckets as $bucket) {
    ap_auth_rate_clear($bucket);
}
register_shutdown_function(static function () use (&$buckets, $fixedBuckets, $run): void {
    foreach (array_merge($buckets, $fixedBuckets, ['acct:lnobody' . $run]) as $bucket) {
        ap_auth_rate_clear($bucket);
    }
    array_map('unlink', glob($GLOBALS['sessionDir'] . '/*') ?: []);
    @rmdir($GLOBALS['sessionDir']);
});
$ip = static function (string $addr) use (&$buckets, $run): void {
    $_SERVER['REMOTE_ADDR'] = $addr . '-' . $run;
    $buckets[] = $_SERVER['REMOTE_ADDR'];
};

// (b) A success does not wipe this IP's failures against other accounts.
$ip('ip-a');
for ($i = 0; $i < 3; $i++) {
    check(ap_auth_attempt_login($bob, 'wrong')['error'] === 'Invalid username/email or password.', 'generic failure text');
}
check(!empty(ap_auth_attempt_login($alice, 'right-alice')['ok']), 'alice signs in');
check(ap_auth_rate_fails($_SERVER['REMOTE_ADDR']) === 3, 'success keeps the IP failure count');
for ($i = 0; $i < 5; $i++) {
    ap_auth_attempt_login($bob, 'wrong');
}
$res = ap_auth_attempt_login($alice, 'right-alice');
check(empty($res['ok']) && str_starts_with((string) $res['error'], 'Too many login attempts'), 'IP limit holds after a success');

// (b) Per-account counter across many IPs; email alias shares it.
check(ap_auth_rate_fails('acct:u2') === 8, 'bob failures counted per account');
ap_auth_rate_clear('acct:u2');
for ($i = 0; $i < AP_AUTH_LOGIN_ACCOUNT_MAX; $i++) {
    $ip('spread-' . $i);
    $login = $i % 2 ? $bob : $bob . '@example.test';
    check(ap_auth_attempt_login($login, 'wrong')['error'] === 'Invalid username/email or password.', 'spread failure ' . $i);
}
$ip('fresh-1');
$res = ap_auth_attempt_login($bob, 'right-bob');
check(empty($res['ok']) && str_starts_with((string) $res['error'], 'Too many login attempts'), 'account locked across IPs');
check(ap_auth_rate_fails($_SERVER['REMOTE_ADDR']) === 0, 'account lock did not charge the fresh IP');
// Unknown names are limited the same way and get the same text.
$ip('fresh-2');
check(ap_auth_attempt_login('nobody' . $run, 'x')['error'] === 'Invalid username/email or password.', 'unknown account uses generic text');
check(ap_auth_rate_fails('acct:lnobody' . $run) === 1, 'unknown account counted by normalized login');
check(ap_auth_login_account_bucket('@Nobody' . $run . '@remote.example') === 'acct:lnobody' . $run, 'login normalization');

// (a) Two-factor: a correct code completes sign-in.
$ip('twofa-ok');
check(!empty(ap_auth_attempt_login($dave, 'right-dave')['requires_2fa']), 'dave needs 2FA');
check(!empty(ap_auth_complete_2fa_login(totp_now($secret))['ok']), 'correct code signs in');

// (a) Per pending sign-in: the fifth wrong code discards it.
$ip('twofa');
check(!empty(ap_auth_attempt_login($carol, 'right-carol')['requires_2fa']), 'carol needs 2FA');
for ($i = 1; $i < AP_AUTH_2FA_PENDING_MAX; $i++) {
    check(ap_auth_complete_2fa_login('000000')['error'] === 'Invalid two-factor code.', 'wrong code ' . $i);
}
$res = ap_auth_complete_2fa_login('000000');
check(str_starts_with((string) $res['error'], 'Too many invalid codes'), 'pending sign-in discarded');
check(!isset($_SESSION['vaak_2fa_pending_user_id']), 'pending state cleared');
check(str_contains((string) ap_auth_complete_2fa_login(totp_now($secret))['error'], 'expired'), 'correct code after discard needs a new sign-in');

// (a) Per account: a new password step does not reset the allowance.
check(!empty(ap_auth_attempt_login($carol, 'right-carol')['requires_2fa']), 'carol signs in again');
for ($i = AP_AUTH_2FA_PENDING_MAX; $i < AP_AUTH_2FA_ACCOUNT_MAX; $i++) {
    ap_auth_complete_2fa_login('000000');
    if (!isset($_SESSION['vaak_2fa_pending_user_id'])) {
        ap_auth_attempt_login($carol, 'right-carol');
    }
}
check(ap_auth_rate_fails('2fa:u3') >= AP_AUTH_2FA_ACCOUNT_MAX, 'account 2FA counter reached');
if (!isset($_SESSION['vaak_2fa_pending_user_id'])) {
    ap_auth_attempt_login($carol, 'right-carol');
}
$res = ap_auth_complete_2fa_login(totp_now($secret));
check(empty($res['ok']) && str_starts_with((string) $res['error'], 'Too many two-factor attempts'), 'account 2FA cooldown blocks even a correct code');
$limited = false;
check(!ap_auth_2fa_verify_code_limited(3, totp_now($secret), $limited) && $limited, 'add-account 2FA path shares the cooldown');

// (c) Password change: current password required; others revoked.
$_SESSION = ['vaak_user_id' => 5, 'keep_me' => 'yes'];
$oldId = session_id();
$write = static function (string $id, array $data): void {
    $_SESSION = $data;
    file_put_contents($GLOBALS['sessionDir'] . '/sess_' . $id, (string) session_encode());
};
$mine = $_SESSION;
$write('erinother', ['vaak_user_id' => 5]);
$write('erinpending', ['vaak_2fa_pending_user_id' => 5]);
$write('bobswitch', ['vaak_user_id' => 2, 'vaak_account_ids' => [2, 5]]);
$write('bobplain', ['vaak_user_id' => 2, 'vaak_account_ids' => [2]]);
$_SESSION = $mine;
$db->exec("INSERT INTO oauth_tokens (id, user_id) VALUES (1, 5), (2, 5), (3, 2), (4, NULL);
INSERT INTO oauth_codes (code, user_id) VALUES ('c5', 5), ('c2', 2);");

$res = ap_auth_change_password(5, 'not-it', 'brand-new-password');
check(empty($res['ok']) && $res['error'] === 'Current password is incorrect.', 'wrong current password refused');
check(is_file($GLOBALS['sessionDir'] . '/sess_erinother'), 'refused change revokes nothing');
check(ap_auth_change_password(5, '', 'brand-new-password')['error'] === 'Current password is incorrect.', 'empty current password refused');

$res = ap_auth_change_password(5, 'right-erin', 'brand-new-password');
check(!empty($res['ok']), 'change with current password succeeds');
check(ap_auth_verify_credentials($erin, 'brand-new-password') !== null, 'new password works');
check(ap_auth_verify_credentials($erin, 'right-erin') === null, 'old password no longer works');
check(!is_file($GLOBALS['sessionDir'] . '/sess_erinother'), 'other session signed out');
check(!is_file($GLOBALS['sessionDir'] . '/sess_erinpending'), 'pending 2FA session discarded');
check(is_file($GLOBALS['sessionDir'] . '/sess_bobplain'), 'unrelated session untouched');
$mine = $_SESSION;
session_decode((string) file_get_contents($GLOBALS['sessionDir'] . '/sess_bobswitch'));
check(($_SESSION['vaak_user_id'] ?? 0) === 2 && ($_SESSION['vaak_account_ids'] ?? null) === [2], 'switch list no longer offers the account');
$_SESSION = $mine;
check(($_SESSION['vaak_user_id'] ?? 0) === 5 && ($_SESSION['keep_me'] ?? '') === 'yes', 'current session kept');
check(session_id() !== $oldId, 'current session id rotated');
$revoked = $db->query('SELECT id FROM oauth_tokens WHERE revoked_at IS NOT NULL ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
check(array_map('intval', $revoked) === [1, 2], 'only this user\'s tokens revoked');
check($db->query("SELECT used_at FROM oauth_codes WHERE code = 'c5'")->fetchColumn() !== null, 'pending authorization code spent');
check($db->query("SELECT used_at FROM oauth_codes WHERE code = 'c2'")->fetchColumn() === null, 'other user code untouched');

// Legacy NULL-owner tokens belong to the operator account.
$db->exec("UPDATE ap_users SET actor_key = 'cmdr_nova' WHERE id = 2");
ap_auth_revoke_user_credentials(2);
check((int) $db->query('SELECT COUNT(*) FROM oauth_tokens WHERE revoked_at IS NULL')->fetchColumn() === 0, 'operator revoke covers legacy tokens');

// Wrong current passwords are rate limited per account.
for ($i = 0; $i < AP_AUTH_PASSWORD_CHANGE_MAX; $i++) {
    ap_auth_change_password(1, 'nope', 'another-password');
}
check(str_starts_with((string) ap_auth_change_password(1, 'right-alice', 'another-password')['error'], 'Too many attempts'), 'password change attempts limited');

// The settings forms ask for the current password.
$admin = (string) file_get_contents($api . '/ap-admin.php');
check(substr_count($admin, 'name="current_password"') === 3, 'all password forms ask for the current password');
check(!str_contains($admin, 'ap_auth_user_set_password('), 'settings never set a password without the current one');
check(str_contains($admin, "ap_auth_change_password(\$vaakOwnerId, (string) (\$_POST['current_password'] ?? ''), \$pass)"), 'change_password checks current password');

session_write_close();
echo "login-hardening: {$n} checks passed\n";
