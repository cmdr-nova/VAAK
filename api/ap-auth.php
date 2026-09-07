<?php
/**
 * VAAK multi-user auth — sessions, invites, password login.
 * Secrets: /etc/mkultra/vaak.env (VAAK_SECRET for CSRF/cookie hardening).
 * cmdr_nova password is seeded from app_auth (Ice Cubes app password).
 */
declare(strict_types=1);
// Refuse direct HTTP hits (include/require only)
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Library only';
    exit;
}


require_once __DIR__ . '/ap-db.php';

const AP_AUTH_SESSION_NAME = 'vaak_sess';
const AP_AUTH_CSRF_KEY = '_vaak_csrf';
/** Keep users signed in for 90 days (cookie + server session GC). */
const AP_AUTH_SESSION_TTL = 7776000; // 90 * 24 * 60 * 60
/** Re-issue the session cookie at most this often while the tab is used. */
const AP_AUTH_COOKIE_REFRESH_EVERY = 86400; // 1 day

/**
 * @return array{secret:string}
 */
function ap_auth_env(): array
{
    static $cfg = null;
    if (is_array($cfg)) {
        return $cfg;
    }
    $secret = getenv('VAAK_SECRET') ?: '';
    $path = '/etc/mkultra/vaak.env';
    if (is_readable($path)) {
        $raw = @file_get_contents($path);
        if (is_string($raw) && $raw !== '') {
            foreach (preg_split("/\r\n|\n|\r/", $raw) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = array_map('trim', explode('=', $line, 2));
                $v = trim($v, " \t\"'");
                if ($k === 'VAAK_SECRET' && $v !== '') {
                    $secret = $v;
                }
            }
        }
    }
    if ($secret === '') {
        // Stable fallback from host identity — rotate by setting VAAK_SECRET
        $secret = hash('sha256', 'vaak|' . (string) gethostname() . '|' . AP_DB_PATH);
    }
    $cfg = ['secret' => $secret];
    return $cfg;
}

function ap_auth_cookie_secure(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || ((string) ($_SERVER['SERVER_PORT'] ?? '') === '443')
        || str_starts_with(strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')), 'https');
}

/**
 * @return array{lifetime:int,path:string,secure:bool,httponly:bool,samesite:string}
 */
function ap_auth_cookie_params(): array
{
    return [
        'lifetime' => AP_AUTH_SESSION_TTL,
        'path' => '/',
        'secure' => ap_auth_cookie_secure(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

/** Send / refresh the vaak_sess cookie with a long Max-Age (sliding expiry). */
function ap_auth_emit_session_cookie(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    $params = ap_auth_cookie_params();
    $opts = [
        'expires' => time() + (int) $params['lifetime'],
        'path' => $params['path'],
        'secure' => !empty($params['secure']),
        'httponly' => true,
        'samesite' => $params['samesite'],
    ];
    setcookie(session_name(), session_id(), $opts);
}

function ap_auth_start_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        ap_auth_touch_session_cookie();
        return;
    }
    // Must exceed cookie lifetime or PHP GC deletes the session file while the
    // browser still holds the cookie — that felt like "random logouts".
    @ini_set('session.gc_maxlifetime', (string) AP_AUTH_SESSION_TTL);
    @ini_set('session.cookie_lifetime', (string) AP_AUTH_SESSION_TTL);
    session_name(AP_AUTH_SESSION_NAME);
    session_set_cookie_params(ap_auth_cookie_params());
    session_start();
    ap_auth_touch_session_cookie();
}

/** Sliding renewal: while the user keeps visiting, push cookie expiry forward. */
function ap_auth_touch_session_cookie(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return;
    }
    // Only when actually logged in
    if ((int) ($_SESSION['vaak_user_id'] ?? 0) < 1) {
        return;
    }
    $now = time();
    $last = (int) ($_SESSION['_vaak_cookie_touched'] ?? 0);
    if ($last > 0 && ($now - $last) < AP_AUTH_COOKIE_REFRESH_EVERY) {
        return;
    }
    $_SESSION['_vaak_cookie_touched'] = $now;
    ap_auth_emit_session_cookie();
}

function ap_auth_csrf_token(): string
{
    ap_auth_start_session();
    if (empty($_SESSION[AP_AUTH_CSRF_KEY]) || !is_string($_SESSION[AP_AUTH_CSRF_KEY])) {
        $_SESSION[AP_AUTH_CSRF_KEY] = bin2hex(random_bytes(32));
    }
    return (string) $_SESSION[AP_AUTH_CSRF_KEY];
}

function ap_auth_csrf_ok(?string $token): bool
{
    ap_auth_start_session();
    $expect = (string) ($_SESSION[AP_AUTH_CSRF_KEY] ?? '');
    if ($expect === '' || $token === null || $token === '') {
        return false;
    }
    return hash_equals($expect, $token);
}

/**
 * Ensure schema + seed admin user from app_auth / actor_profile.
 */
function ap_auth_bootstrap(): void
{
    ap_db(); // migrate
    $row = ap_db()->query("SELECT id FROM ap_users WHERE actor_key = 'cmdr_nova' LIMIT 1")->fetch();
    if (is_array($row)) {
        return;
    }
    $hash = '';
    try {
        $auth = ap_db()->query('SELECT password_hash FROM app_auth WHERE id = 1')->fetch();
        if (is_array($auth) && !empty($auth['password_hash'])) {
            $hash = (string) $auth['password_hash'];
        }
    } catch (Throwable $e) {
        $hash = '';
    }
    if ($hash === '') {
        // Placeholder — login disabled until app password / set_password
        $hash = password_hash(bin2hex(random_bytes(24)), PASSWORD_DEFAULT) ?: '';
    }
    $now = ap_db_now();
    ap_db()->prepare(
        'INSERT INTO ap_users
         (username, email, password_hash, actor_key, actor_id, is_admin, created_at, updated_at)
         VALUES (?, NULL, ?, ?, ?, 1, ?, ?)'
    )->execute([
        'cmdr_nova',
        $hash,
        'cmdr_nova',
        'https://mkultra.monster/users/cmdr_nova',
        $now,
        $now,
    ]);
}

/**
 * @return array<string,mixed>|null
 */
function ap_auth_user_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }
    $st = ap_db()->prepare('SELECT * FROM ap_users WHERE id = ? AND disabled_at IS NULL LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

/** @return list<array<string,mixed>> */
function ap_auth_users_list(int $limit = 200): array
{
    $limit = max(1, min(500, $limit));
    $st = ap_db()->query(
        'SELECT u.id, u.username, u.email, u.actor_key, u.actor_id, u.is_admin,
                u.created_at, u.updated_at, u.disabled_at,
                p.name AS profile_name, p.icon_url
           FROM ap_users u
           LEFT JOIN actor_profile p ON p.actor_key = u.actor_key
          ORDER BY u.created_at DESC, u.id DESC
          LIMIT ' . $limit
    );
    $rows = $st->fetchAll() ?: [];
    return is_array($rows) ? $rows : [];
}

/** @return array{ok:bool,error?:string,disabled?:bool} */
function ap_auth_user_set_disabled(int $id, bool $disabled): array
{
    if ($id < 1) {
        return ['ok' => false, 'error' => 'Invalid user.'];
    }
    $st = ap_db()->prepare('SELECT actor_key, is_admin FROM ap_users WHERE id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'User not found.'];
    }
    if ((string) ($row['actor_key'] ?? '') === 'cmdr_nova' || !empty($row['is_admin'])) {
        return ['ok' => false, 'error' => 'The operator account cannot be banned here.'];
    }
    $now = ap_db_now();
    $when = $disabled ? $now : null;
    ap_db()->prepare('UPDATE ap_users SET disabled_at = ?, updated_at = ? WHERE id = ?')
        ->execute([$when, $now, $id]);
    if ($disabled) {
        // Cut off API clients immediately as well as blocking future web logins.
        ap_db()->prepare('UPDATE oauth_tokens SET revoked_at = ? WHERE user_id = ? AND revoked_at IS NULL')
            ->execute([$now, $id]);
    }
    return ['ok' => true, 'disabled' => $disabled];
}

/**
 * Update account email (login alias). Empty clears it.
 *
 * @return array{ok:bool,error?:string}
 */
function ap_auth_user_set_email(int $userId, string $email): array
{
    if ($userId < 1) {
        return ['ok' => false, 'error' => 'Invalid user.'];
    }
    $email = trim($email);
    if ($email === '') {
        ap_db()->prepare('UPDATE ap_users SET email = NULL, updated_at = ? WHERE id = ?')
            ->execute([ap_db_now(), $userId]);
        return ['ok' => true];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 200) {
        return ['ok' => false, 'error' => 'Enter a valid email address.'];
    }
    $st = ap_db()->prepare(
        'SELECT id FROM ap_users WHERE lower(email) = lower(?) AND id != ? AND disabled_at IS NULL LIMIT 1'
    );
    $st->execute([$email, $userId]);
    if ($st->fetch()) {
        return ['ok' => false, 'error' => 'That email is already used by another account.'];
    }
    try {
        ap_db()->prepare('UPDATE ap_users SET email = ?, updated_at = ? WHERE id = ?')
            ->execute([$email, ap_db_now(), $userId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('[ap-auth] set_email: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save email.'];
    }
}

/**
 * Change VAAK login password for a user (their own account).
 *
 * @return array{ok:bool,error?:string}
 */
function ap_auth_user_set_password(int $userId, string $newPassword): array
{
    if ($userId < 1) {
        return ['ok' => false, 'error' => 'Invalid user.'];
    }
    if (strlen($newPassword) < 10) {
        return ['ok' => false, 'error' => 'Password must be at least 10 characters.'];
    }
    if (strlen($newPassword) > 200) {
        return ['ok' => false, 'error' => 'Password is too long.'];
    }
    $hash = password_hash($newPassword, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        return ['ok' => false, 'error' => 'Could not hash password.'];
    }
    try {
        ap_db()->prepare('UPDATE ap_users SET password_hash = ?, updated_at = ? WHERE id = ?')
            ->execute([$hash, ap_db_now(), $userId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('[ap-auth] set_password: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save password.'];
    }
}

/**
 * @return array<string,mixed>|null
 */
function ap_auth_user_by_login(string $login): ?array
{
    $login = trim($login);
    if ($login === '') {
        return null;
    }
    if (str_contains($login, '@') && !str_starts_with($login, '@')) {
        $st = ap_db()->prepare(
            'SELECT * FROM ap_users WHERE lower(email) = lower(?) AND disabled_at IS NULL LIMIT 1'
        );
        $st->execute([$login]);
    } else {
        $login = ltrim($login, '@');
        if (str_contains($login, '@')) {
            $login = explode('@', $login, 2)[0];
        }
        $st = ap_db()->prepare(
            'SELECT * FROM ap_users WHERE lower(username) = lower(?) AND disabled_at IS NULL LIMIT 1'
        );
        $st->execute([$login]);
    }
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

/**
 * @return array<string,mixed>|null currently logged-in user
 */
function ap_auth_current_user(): ?array
{
    ap_auth_bootstrap();
    ap_auth_start_session();
    $id = (int) ($_SESSION['vaak_user_id'] ?? 0);
    if ($id < 1) {
        return null;
    }
    return ap_auth_user_by_id($id);
}

function ap_auth_login_user(array $user): void
{
    ap_auth_start_session();
    session_regenerate_id(true);
    $_SESSION['vaak_user_id'] = (int) ($user['id'] ?? 0);
    $_SESSION['vaak_actor_key'] = (string) ($user['actor_key'] ?? '');
    $_SESSION['vaak_is_admin'] = !empty($user['is_admin']) ? 1 : 0;
    $_SESSION['_vaak_cookie_touched'] = time();
    ap_auth_csrf_token(); // refresh present
    ap_auth_emit_session_cookie(); // persistent cookie (90d)
}

function ap_auth_logout(): void
{
    ap_auth_start_session();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = ap_auth_cookie_params();
        setcookie(session_name(), '', [
            'expires' => time() - 42000,
            'path' => $p['path'],
            'secure' => !empty($p['secure']),
            'httponly' => true,
            'samesite' => $p['samesite'],
        ]);
    }
    session_destroy();
}

/**
 * Verify username/email + password without creating a web session.
 * Admins may also use the Ice Cubes /admin app password.
 *
 * @return array<string,mixed>|null user row on success
 */
function ap_auth_verify_credentials(string $login, string $password): ?array
{
    ap_auth_bootstrap();
    $user = ap_auth_user_by_login($login);
    if ($user === null) {
        return null;
    }
    $hash = (string) ($user['password_hash'] ?? '');
    if ($hash !== '' && password_verify($password, $hash)) {
        return $user;
    }
    // Legacy Ice Cubes / app password — only for cmdr_nova (never other is_admin rows)
    $actorKey = strtolower((string) ($user['actor_key'] ?? ''));
    if ($actorKey === 'cmdr_nova'
        && function_exists('ap_app_password_verify')
        && ap_app_password_verify($password)
    ) {
        $newHash = password_hash($password, PASSWORD_DEFAULT);
        if (is_string($newHash)) {
            ap_db()->prepare('UPDATE ap_users SET password_hash = ?, updated_at = ? WHERE id = ?')
                ->execute([$newHash, ap_db_now(), (int) $user['id']]);
            $user['password_hash'] = $newHash;
        }
        return $user;
    }
    return null;
}

function ap_auth_login_rate_path(): string
{
    $ip = (string) ($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
    $dir = '/tmp/ap-vaak-login-rate';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    return $dir . '/' . hash('sha256', $ip) . '.json';
}

/** @return bool true if attempts are still allowed */
function ap_auth_login_rate_ok(bool $clear = false): bool
{
    $path = ap_auth_login_rate_path();
    if ($clear) {
        @unlink($path);
        return true;
    }
    if (!is_file($path)) {
        return true;
    }
    $raw = @file_get_contents($path);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data)) {
        @unlink($path);
        return true;
    }
    $fails = (int) ($data['fails'] ?? 0);
    $first = (int) ($data['first'] ?? 0);
    $window = 900; // 15 minutes
    if ($first > 0 && (time() - $first) > $window) {
        @unlink($path);
        return true;
    }
    return $fails < 8;
}

function ap_auth_login_rate_fail(): void
{
    $path = ap_auth_login_rate_path();
    $fails = 0;
    $first = time();
    if (is_file($path)) {
        $raw = @file_get_contents($path);
        $data = is_string($raw) ? json_decode($raw, true) : null;
        if (is_array($data)) {
            $fails = (int) ($data['fails'] ?? 0);
            $first = (int) ($data['first'] ?? time());
            if ((time() - $first) > 900) {
                $fails = 0;
                $first = time();
            }
        }
    }
    $fails++;
    @file_put_contents(
        $path,
        json_encode(['fails' => $fails, 'first' => $first], JSON_UNESCAPED_SLASHES),
        LOCK_EX
    );
    @chmod($path, 0600);
}

/**
 * @return array{ok:bool,error?:string,user?:array}
 */
function ap_auth_attempt_login(string $login, string $password): array
{
    if (!ap_auth_login_rate_ok()) {
        return ['ok' => false, 'error' => 'Too many login attempts. Try again in a few minutes.'];
    }
    $user = ap_auth_verify_credentials($login, $password);
    if ($user === null) {
        ap_auth_login_rate_fail();
        return ['ok' => false, 'error' => 'Invalid username/email or password.'];
    }
    ap_auth_login_rate_ok(true);
    ap_auth_login_user($user);
    return ['ok' => true, 'user' => $user];
}

function ap_auth_username_ok(string $username): bool
{
    $username = strtolower(trim($username));
    if ($username === '' || strlen($username) < 2 || strlen($username) > 30) {
        return false;
    }
    if (!preg_match('/^[a-z][a-z0-9_]*$/', $username)) {
        return false;
    }
    $reserved = ['cmdr_nova', 'admin', 'administrator', 'vaak', 'api', 'inbox', 'actor', 'null', 'undefined', 'root', 'support', 'www'];
    return !in_array($username, $reserved, true);
}

/**
 * @return array{ok:bool,error?:string,invite?:array}
 */
function ap_auth_invite_lookup(string $code): array
{
    $code = strtoupper(trim($code));
    if ($code === '' || strlen($code) < 6) {
        return ['ok' => false, 'error' => 'Invite code required.'];
    }
    $st = ap_db()->prepare('SELECT * FROM ap_invite_codes WHERE upper(code) = ? LIMIT 1');
    $st->execute([$code]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'Unknown invite code.'];
    }
    if (!empty($row['used_at'])) {
        return ['ok' => false, 'error' => 'Invite code already used.'];
    }
    $exp = (string) ($row['expires_at'] ?? '');
    if ($exp !== '' && strtotime($exp) !== false && strtotime($exp) < time()) {
        return ['ok' => false, 'error' => 'Invite code expired.'];
    }
    return ['ok' => true, 'invite' => $row];
}

/**
 * @return array{ok:bool,error?:string,code?:string,id?:int}
 */
function ap_auth_invite_create(?int $createdBy, string $note = '', ?string $expiresAt = null): array
{
    $code = strtoupper(bin2hex(random_bytes(4)) . '-' . bin2hex(random_bytes(2)));
    $now = ap_db_now();
    try {
        ap_db()->prepare(
            'INSERT INTO ap_invite_codes (code, created_by, created_at, expires_at, note)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([
            $code,
            $createdBy,
            $now,
            $expiresAt,
            $note !== '' ? mb_substr($note, 0, 200) : null,
        ]);
        return ['ok' => true, 'code' => $code, 'id' => ap_db_last_insert_id('ap_invite_codes')];
    } catch (Throwable $e) {
        error_log('[ap-auth] invite_create: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not create invite.'];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function ap_auth_invites_list(int $limit = 100): array
{
    $limit = max(1, min(200, $limit));
    return ap_db()->query(
        'SELECT * FROM ap_invite_codes ORDER BY created_at DESC, id DESC LIMIT ' . (int) $limit
    )->fetchAll() ?: [];
}

/**
 * PEM paths for a local actor key (matches cmdr_nova_*.pem convention).
 *
 * @return array{private:string,public:string}
 */
function ap_actor_key_paths(string $actorKey): array
{
    $actorKey = strtolower(trim($actorKey));
    $actorKey = preg_replace('/[^a-z0-9_]/', '', $actorKey) ?? '';
    if ($actorKey === '') {
        $actorKey = 'invalid';
    }
    $dir = '/etc/mkultra/ap-inbox';
    return [
        'private' => $dir . '/' . $actorKey . '_private.pem',
        'public' => $dir . '/' . $actorKey . '_public.pem',
    ];
}

/**
 * Ensure RSA keypair exists for actor. Creates with openssl_pkey_new when missing.
 * Private: 640 root:www-data (or www-data:www-data). Public: 644.
 *
 * @return array{ok:bool,error?:string,created?:bool,paths?:array{private:string,public:string}}
 */
function ap_actor_ensure_keypair(string $actorKey): array
{
    $actorKey = strtolower(trim($actorKey));
    if ($actorKey === '' || !preg_match('/^[a-z][a-z0-9_]{1,29}$/', $actorKey)) {
        return ['ok' => false, 'error' => 'Invalid actor key.'];
    }
    $paths = ap_actor_key_paths($actorKey);
    $privPath = $paths['private'];
    $pubPath = $paths['public'];
    $dir = dirname($privPath);

    if (is_file($privPath) && is_file($pubPath)) {
        $priv = @file_get_contents($privPath);
        $pub = @file_get_contents($pubPath);
        if (is_string($priv) && $priv !== '' && is_string($pub) && $pub !== '') {
            return ['ok' => true, 'created' => false, 'paths' => $paths];
        }
    }

    if (!is_dir($dir)) {
        return ['ok' => false, 'error' => 'Key directory missing: ' . $dir];
    }
    if (!is_writable($dir)) {
        return ['ok' => false, 'error' => 'Key directory not writable by PHP user (need g+w on ' . $dir . ').'];
    }

    $config = [
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ];
    $res = openssl_pkey_new($config);
    if ($res === false) {
        return ['ok' => false, 'error' => 'openssl_pkey_new failed.'];
    }
    $privPem = '';
    if (!openssl_pkey_export($res, $privPem) || $privPem === '') {
        return ['ok' => false, 'error' => 'openssl_pkey_export failed.'];
    }
    $details = openssl_pkey_get_details($res);
    $pubPem = is_array($details) ? (string) ($details['key'] ?? '') : '';
    if ($pubPem === '') {
        return ['ok' => false, 'error' => 'Could not extract public key.'];
    }

    $tmpPriv = $privPath . '.tmp.' . bin2hex(random_bytes(4));
    $tmpPub = $pubPath . '.tmp.' . bin2hex(random_bytes(4));
    if (@file_put_contents($tmpPriv, $privPem) === false || @file_put_contents($tmpPub, $pubPem) === false) {
        @unlink($tmpPriv);
        @unlink($tmpPub);
        return ['ok' => false, 'error' => 'Could not write key temp files.'];
    }
    @chmod($tmpPriv, 0640);
    @chmod($tmpPub, 0644);
    if (!@rename($tmpPriv, $privPath) || !@rename($tmpPub, $pubPath)) {
        @unlink($tmpPriv);
        @unlink($tmpPub);
        return ['ok' => false, 'error' => 'Could not install key files.'];
    }
    @chmod($privPath, 0640);
    @chmod($pubPath, 0644);
    // Best-effort ownership when running as root (CLI); FPM stays www-data-owned.
    if (function_exists('posix_geteuid') && posix_geteuid() === 0) {
        $www = function_exists('posix_getpwnam') ? posix_getpwnam('www-data') : false;
        if (is_array($www) && isset($www['uid'], $www['gid'])) {
            @chown($privPath, (int) $www['uid']);
            @chgrp($privPath, (int) $www['gid']);
            @chown($pubPath, (int) $www['uid']);
            @chgrp($pubPath, (int) $www['gid']);
        }
    }

    return ['ok' => true, 'created' => true, 'paths' => $paths];
}

/**
 * Register with invite. Provisions actor PEM keypair (Slice E).
 *
 * @return array{ok:bool,error?:string,user?:array}
 */
function ap_auth_register(string $inviteCode, string $username, string $password, string $email = ''): array
{
    ap_auth_bootstrap();
    $inv = ap_auth_invite_lookup($inviteCode);
    if (empty($inv['ok'])) {
        return ['ok' => false, 'error' => $inv['error'] ?? 'Bad invite.'];
    }
    $username = strtolower(trim($username));
    if (!ap_auth_username_ok($username)) {
        return ['ok' => false, 'error' => 'Username must be 2–30 chars, start with a letter, a-z 0-9 _ only (not reserved).'];
    }
    $password = trim($password);
    if (strlen($password) < 10) {
        return ['ok' => false, 'error' => 'Password must be at least 10 characters.'];
    }
    $email = trim($email);
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'Invalid email address.'];
    }
    $exists = ap_db()->prepare('SELECT 1 FROM ap_users WHERE lower(username) = lower(?) LIMIT 1');
    $exists->execute([$username]);
    if ($exists->fetchColumn()) {
        return ['ok' => false, 'error' => 'Username taken.'];
    }
    if ($email !== '') {
        $ee = ap_db()->prepare('SELECT 1 FROM ap_users WHERE email IS NOT NULL AND lower(email) = lower(?) LIMIT 1');
        $ee->execute([$email]);
        if ($ee->fetchColumn()) {
            return ['ok' => false, 'error' => 'Email already in use.'];
        }
    }
    $hash = password_hash($password, PASSWORD_DEFAULT);
    if (!is_string($hash)) {
        return ['ok' => false, 'error' => 'Could not hash password.'];
    }
    $now = ap_db_now();
    $actorId = 'https://mkultra.monster/users/' . rawurlencode($username);
    $db = ap_db();
    try {
        $db->beginTransaction();
        $db->prepare(
            'INSERT INTO ap_users
             (username, email, password_hash, actor_key, actor_id, is_admin, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, 0, ?, ?)'
        )->execute([
            $username,
            $email !== '' ? $email : null,
            $hash,
            $username,
            $actorId,
            $now,
            $now,
        ]);
        $uid = ap_db_last_insert_id('ap_users', 'id', $db);
        // Minimal profile row
        $db->prepare(
            'INSERT INTO actor_profile (actor_key, name, summary, attachment_json, updated_at)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT(actor_key) DO NOTHING'
        )->execute([$username, $username, '', '[]', $now]);
        $inviteId = (int) (($inv['invite']['id'] ?? 0));
        $inviteUse = $db->prepare(
            'UPDATE ap_invite_codes SET used_by = ?, used_at = ? WHERE id = ? AND used_at IS NULL'
        );
        $inviteUse->execute([$uid, $now, $inviteId]);
        if ($inviteUse->rowCount() < 1) {
            throw new RuntimeException('Invite race — already used.');
        }
        $db->commit();
        $keys = ap_actor_ensure_keypair($username);
        if (empty($keys['ok'])) {
            error_log('[ap-auth] register keygen failed for ' . $username . ': ' . ($keys['error'] ?? 'unknown'));
        }
        // Operator monitoring: cmdr_nova follows the new account (not the reverse).
        ap_auth_operator_follow_new_user($username);
        $user = ap_auth_user_by_id($uid);
        if ($user === null) {
            return ['ok' => false, 'error' => 'Account created but could not load user.'];
        }
        ap_auth_login_user($user);
        return ['ok' => true, 'user' => $user];
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[ap-auth] register: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Registration failed.'];
    }
}

/**
 * After invite registration: cmdr_nova follows the new local actor so the
 * operator sees them in Following / Home. Does not make the invitee follow back.
 */
function ap_auth_operator_follow_new_user(string $newActorKey): void
{
    $newActorKey = strtolower(trim($newActorKey));
    $newActorKey = preg_replace('/[^a-z0-9_]/', '', $newActorKey) ?? '';
    if ($newActorKey === '' || $newActorKey === 'cmdr_nova') {
        return;
    }
    $cmdr = 'https://mkultra.monster/users/cmdr_nova';
    $target = 'https://mkultra.monster/users/' . $newActorKey;
    try {
        // cmdr follows target
        ap_following_upsert($target, $cmdr);
        // target's followers list includes cmdr (accepted local follow)
        ap_follower_upsert(
            $cmdr,
            $cmdr . '/inbox',
            null,
            'cmdr_nova',
            $target
        );
        if (function_exists('ap_metrics_record')) {
            ap_metrics_record(
                'Follow',
                $cmdr,
                $cmdr . '/follows/register-' . $newActorKey,
                $target,
                0,
                'operator_auto_follow_register',
                $target
            );
        }
        error_log('[ap-auth] operator auto-follow: cmdr_nova → ' . $newActorKey);
    } catch (Throwable $e) {
        error_log('[ap-auth] operator auto-follow failed for ' . $newActorKey . ': ' . $e->getMessage());
    }
}

/**
 * Sync cmdr_nova web password when app password changes in Security UI.
 */
function ap_auth_sync_admin_password_hash(string $passwordHash): void
{
    ap_auth_bootstrap();
    ap_db()->prepare(
        "UPDATE ap_users SET password_hash = ?, updated_at = ? WHERE actor_key = 'cmdr_nova'"
    )->execute([$passwordHash, ap_db_now()]);
}

function ap_auth_is_admin(?array $user = null): bool
{
    $user ??= ap_auth_current_user();
    return is_array($user) && !empty($user['is_admin']);
}

/**
 * Base path for VAAK UI links (always /vaak after cutover).
 */
function ap_auth_vaak_base(): string
{
    return '/vaak';
}

/* ----------------- Encrypted secrets (per-user xAI key, etc.) ----------------- */

/**
 * AES-256-GCM encrypt using VAAK_SECRET-derived key.
 * Returns base64(iv | tag | ciphertext) or empty string on failure.
 */
function ap_auth_secret_encrypt(string $plaintext): string
{
    $plaintext = trim($plaintext);
    if ($plaintext === '') {
        return '';
    }
    $key = hash('sha256', ap_auth_env()['secret'], true);
    $iv = random_bytes(12);
    $tag = '';
    $cipher = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
    if (!is_string($cipher) || $cipher === '' || strlen($tag) !== 16) {
        return '';
    }
    return base64_encode($iv . $tag . $cipher);
}

/**
 * Decrypt blob from ap_auth_secret_encrypt. Returns null on failure.
 */
function ap_auth_secret_decrypt(string $blob): ?string
{
    $blob = trim($blob);
    if ($blob === '') {
        return null;
    }
    $raw = base64_decode($blob, true);
    if (!is_string($raw) || strlen($raw) < 29) {
        return null;
    }
    $iv = substr($raw, 0, 12);
    $tag = substr($raw, 12, 16);
    $cipher = substr($raw, 28);
    $key = hash('sha256', ap_auth_env()['secret'], true);
    $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
    if (!is_string($plain) || $plain === '') {
        return null;
    }
    return $plain;
}

/**
 * Save or clear encrypted xAI API key for a user.
 *
 * @return array{ok:bool,error?:string,configured?:bool}
 */
function ap_auth_user_set_xai_key(int $userId, ?string $plaintextKey): array
{
    if ($userId < 1) {
        return ['ok' => false, 'error' => 'Missing user.'];
    }
    ap_auth_bootstrap();
    $enc = null;
    if ($plaintextKey !== null) {
        $plaintextKey = trim($plaintextKey);
        if ($plaintextKey === '') {
            $enc = null;
        } else {
            $enc = ap_auth_secret_encrypt($plaintextKey);
            if ($enc === '') {
                return ['ok' => false, 'error' => 'Could not encrypt API key.'];
            }
        }
    }
    try {
        ap_db()->prepare(
            'UPDATE ap_users SET xai_api_key_enc = ?, updated_at = ? WHERE id = ?'
        )->execute([$enc, ap_db_now(), $userId]);
        return ['ok' => true, 'configured' => $enc !== null && $enc !== ''];
    } catch (Throwable $e) {
        error_log('[ap-auth] set_xai_key: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save API key.'];
    }
}

/**
 * Decrypted xAI API key for user, or null if unset/invalid.
 *
 * @param array<string,mixed>|null $user
 */
function ap_auth_user_get_xai_key(?array $user = null): ?string
{
    $user ??= ap_auth_current_user();
    if (!is_array($user)) {
        return null;
    }
    $blob = (string) ($user['xai_api_key_enc'] ?? '');
    if ($blob === '') {
        // Row may be stale — re-read
        $id = (int) ($user['id'] ?? 0);
        if ($id < 1) {
            return null;
        }
        $fresh = ap_auth_user_by_id($id);
        $blob = is_array($fresh) ? (string) ($fresh['xai_api_key_enc'] ?? '') : '';
    }
    if ($blob === '') {
        return null;
    }
    return ap_auth_secret_decrypt($blob);
}

function ap_auth_user_has_xai_key(?array $user = null): bool
{
    $user ??= ap_auth_current_user();
    if (!is_array($user)) {
        return false;
    }
    $blob = (string) ($user['xai_api_key_enc'] ?? '');
    if ($blob === '') {
        $id = (int) ($user['id'] ?? 0);
        if ($id > 0) {
            $fresh = ap_auth_user_by_id($id);
            $blob = is_array($fresh) ? (string) ($fresh['xai_api_key_enc'] ?? '') : '';
        }
    }
    return $blob !== '';
}

/**
 * Optional per-user OpenAI-compatible API root + model (Profile).
 *
 * @return array{ok:bool,error?:string}
 */
function ap_auth_user_set_ai_endpoint(int $userId, ?string $apiRoot, ?string $model): array
{
    if ($userId < 1) {
        return ['ok' => false, 'error' => 'Missing user.'];
    }
    ap_auth_bootstrap();
    $root = null;
    if ($apiRoot !== null) {
        $apiRoot = trim($apiRoot);
        if ($apiRoot === '') {
            $root = null;
        } else {
            $apiRoot = rtrim($apiRoot, '/');
            if (str_ends_with($apiRoot, '/chat/completions')) {
                $apiRoot = substr($apiRoot, 0, -strlen('/chat/completions'));
            }
            if (str_ends_with($apiRoot, '/responses')) {
                $apiRoot = substr($apiRoot, 0, -strlen('/responses'));
            }
            if (!preg_match('#^https://[A-Za-z0-9.-]+(?::\d+)?(?:/[A-Za-z0-9._~:/?&=+%-]*)?$#', $apiRoot)) {
                return ['ok' => false, 'error' => 'API base URL must be https://…'];
            }
            $root = $apiRoot;
        }
    }
    $mod = null;
    if ($model !== null) {
        $model = trim($model);
        if ($model === '') {
            $mod = null;
        } elseif (mb_strlen($model) > 120 || !preg_match('/^[A-Za-z0-9._:\/+-]+$/', $model)) {
            return ['ok' => false, 'error' => 'Invalid model name.'];
        } else {
            $mod = $model;
        }
    }
    try {
        ap_db()->prepare(
            'UPDATE ap_users SET ai_api_root = ?, ai_model = ?, updated_at = ? WHERE id = ?'
        )->execute([$root, $mod, ap_db_now(), $userId]);
        return ['ok' => true];
    } catch (Throwable $e) {
        error_log('[ap-auth] set_ai_endpoint: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save AI endpoint settings.'];
    }
}
