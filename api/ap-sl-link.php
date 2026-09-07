<?php
/**
 * VAAK ↔ Second Life avatar linking (Primfeed-style).
 *
 * Flow:
 *  1. User enters SL username (not display name)
 *  2. Resolve agent UUID via Linden Name2Key API (optional key) or pasted UUID
 *  3. Create one-time code; ask in-world verifier to IM the avatar
 *  4. User pastes code → verified link stored on their VAAK account
 *
 * Env file: /etc/mkultra/sl-api.env
 *   SL_API_KEY=...                 # from accounts.secondlife.com/manage_api_key
 *   SL_VERIFIER_URL=http(s)://simhost-….secondlife.io:12046/cap/…  # LSL HTTP-in URL
 *   SL_VERIFIER_SECRET=…           # shared bearer/HMAC secret
 *   SL_LINK_DEBUG=1                # include code in notice when verifier missing (dev)
 */
declare(strict_types=1);

const AP_SL_ENV_PATH = '/etc/mkultra/sl-api.env';
const AP_SL_CHALLENGE_TTL_SEC = 600;
const AP_SL_CODE_LEN = 6;

/**
 * @return array<string,string>
 */
function ap_sl_env(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    if (!is_readable(AP_SL_ENV_PATH)) {
        return $cache;
    }
    $raw = @file_get_contents(AP_SL_ENV_PATH);
    if (!is_string($raw) || $raw === '') {
        return $cache;
    }
    foreach (preg_split('/\R/', $raw) ?: [] as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
            continue;
        }
        [$k, $v] = array_map('trim', explode('=', $line, 2));
        $v = trim($v, " \t\"'");
        if ($k !== '') {
            $cache[$k] = $v;
        }
    }
    return $cache;
}

function ap_sl_env_get(string $key, string $default = ''): string
{
    $env = ap_sl_env();
    return isset($env[$key]) && $env[$key] !== '' ? (string) $env[$key] : $default;
}

function ap_sl_schema_ensure(): void
{
    $db = ap_db();
    if (ap_db_driver($db) === 'pgsql') {
        // PostgreSQL schema is provisioned by the migration/import runbook.
        return;
    }
    $db->exec(
        "CREATE TABLE IF NOT EXISTS ap_sl_links (
            owner_user_id INTEGER NOT NULL UNIQUE,
            sl_username TEXT NOT NULL,
            sl_agent_id TEXT NOT NULL,
            sl_display_name TEXT,
            sl_profile_image_url TEXT,
            verified_at TEXT NOT NULL,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_ap_sl_links_agent ON ap_sl_links(sl_agent_id);
        CREATE TABLE IF NOT EXISTS ap_sl_challenges (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            owner_user_id INTEGER NOT NULL,
            sl_username TEXT NOT NULL,
            sl_agent_id TEXT NOT NULL,
            code_hash TEXT NOT NULL,
            attempts INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            expires_at TEXT NOT NULL
        );
        CREATE INDEX IF NOT EXISTS idx_ap_sl_challenges_owner ON ap_sl_challenges(owner_user_id, expires_at);"
    );
}

/**
 * Normalize "First Last", "first.last", "FirstResident", "first" → username + lastname parts.
 *
 * @return array{username:string,lastname:string,legacy:string}|null
 */
function ap_sl_parse_username(string $raw): ?array
{
    $raw = trim(preg_replace('/\s+/u', ' ', $raw) ?? $raw);
    if ($raw === '' || strlen($raw) > 64) {
        return null;
    }
    // Reject obvious display-name junk
    if (str_contains($raw, '@') || str_contains($raw, '/')) {
        return null;
    }
    $first = '';
    $last = 'Resident';
    if (str_contains($raw, ' ')) {
        $parts = explode(' ', $raw, 2);
        $first = $parts[0];
        $last = $parts[1] !== '' ? $parts[1] : 'Resident';
    } elseif (str_contains($raw, '.')) {
        $parts = explode('.', $raw, 2);
        $first = $parts[0];
        $last = $parts[1] !== '' ? $parts[1] : 'Resident';
    } else {
        $first = $raw;
    }
    $first = strtolower(preg_replace('/[^a-z0-9]/i', '', $first) ?? '');
    $last = strtolower(preg_replace('/[^a-z0-9]/i', '', $last) ?? '');
    if ($first === '' || strlen($first) < 2) {
        return null;
    }
    if ($last === '') {
        $last = 'resident';
    }
    return [
        'username' => $first,
        'lastname' => $last,
        'legacy' => $first . '.' . $last,
    ];
}

function ap_sl_valid_agent_id(string $id): bool
{
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        trim($id)
    );
}

/**
 * Resolve username → agent UUID via Linden Name2Key API.
 *
 * @return array{ok:bool,agent_id?:string,username?:string,lastname?:string,error?:string}
 */
function ap_sl_name2key(string $username, string $lastname = 'Resident'): array
{
    $apiKey = ap_sl_env_get('SL_API_KEY');
    if ($apiKey === '') {
        return ['ok' => false, 'error' => 'Second Life API key not configured on this server yet.'];
    }
    $username = strtolower(trim($username));
    $lastname = strtolower(trim($lastname));
    if ($lastname === '') {
        $lastname = 'resident';
    }
    $payload = json_encode(['username' => $username, 'lastname' => $lastname], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload)) {
        return ['ok' => false, 'error' => 'Could not encode Name2Key request.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl extension required for Name2Key.'];
    }
    $ch = curl_init('https://api.secondlife.com/get_agent_id');
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'api-key: ' . $apiKey,
            'User-Agent: VAAK-SL-Link/1.0 (+https://mkultra.monster)',
        ],
        CURLOPT_POSTFIELDS => $payload,
    ]);
    $body = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if (!is_string($body) || $body === '') {
        return ['ok' => false, 'error' => 'Name2Key request failed (empty response).'];
    }
    $data = json_decode($body, true);
    if ($code === 200 && is_array($data) && !empty($data['agent_id']) && ap_sl_valid_agent_id((string) $data['agent_id'])) {
        return [
            'ok' => true,
            'agent_id' => strtolower((string) $data['agent_id']),
            'username' => (string) ($data['username'] ?? $username),
            'lastname' => (string) ($data['lastname'] ?? $lastname),
        ];
    }
    $msg = is_array($data) ? (string) ($data['message'] ?? $data['error'] ?? '') : '';
    if ($code === 404) {
        return ['ok' => false, 'error' => $msg !== '' ? $msg : 'No Second Life account with that username.'];
    }
    if ($code === 403) {
        return ['ok' => false, 'error' => 'Name2Key rate-limited — try again in a moment.'];
    }
    return ['ok' => false, 'error' => $msg !== '' ? $msg : ('Name2Key HTTP ' . $code)];
}

/**
 * Best-effort public profile image from world.secondlife.com/resident/<uuid>.
 */
function ap_sl_fetch_profile_image(string $agentId): ?string
{
    if (!ap_sl_valid_agent_id($agentId) || !function_exists('curl_init')) {
        return null;
    }
    $url = 'https://world.secondlife.com/resident/' . strtolower($agentId);
    $ch = curl_init($url);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 6,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_USERAGENT => 'VAAK-SL-Link/1.0 (+https://mkultra.monster)',
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!is_string($html) || $html === '') {
        return null;
    }
    // Common patterns on the public resident page
    if (preg_match('#https://[^"\']+(?:secondlife|amazonaws)[^"\']+\.(?:jpg|jpeg|png|webp)#i', $html, $m)) {
        $img = html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (str_starts_with($img, 'https://')) {
            return $img;
        }
    }
    if (preg_match('#<img[^>]+src=["\'](https://[^"\']+)["\'][^>]*(?:profile|image|photo)#i', $html, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * @return array<string,mixed>|null
 */
function ap_sl_link_for_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    ap_sl_schema_ensure();
    try {
        $st = ap_db()->prepare('SELECT * FROM ap_sl_links WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return array{ok:bool,error?:string,notice?:string,debug_code?:string,challenge?:array<string,mixed>}
 */
function ap_sl_challenge_start(int $userId, string $usernameRaw, string $agentIdOverride = ''): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    ap_sl_schema_ensure();
    $parsed = ap_sl_parse_username($usernameRaw);
    if ($parsed === null) {
        return ['ok' => false, 'error' => 'Enter your Second Life username (e.g. first.last or First Last), not your display name.'];
    }

    $agentId = strtolower(trim($agentIdOverride));
    if ($agentId !== '' && !ap_sl_valid_agent_id($agentId)) {
        return ['ok' => false, 'error' => 'Agent UUID looks invalid.'];
    }

    if ($agentId === '') {
        $n2k = ap_sl_name2key($parsed['username'], $parsed['lastname']);
        if (empty($n2k['ok'])) {
            return ['ok' => false, 'error' => $n2k['error'] ?? 'Could not resolve that username.'];
        }
        $agentId = (string) $n2k['agent_id'];
        if (!empty($n2k['username'])) {
            $parsed['username'] = strtolower((string) $n2k['username']);
        }
        if (!empty($n2k['lastname'])) {
            $parsed['lastname'] = strtolower((string) $n2k['lastname']);
        }
        $parsed['legacy'] = $parsed['username'] . '.' . $parsed['lastname'];
    }

    // One agent → one VAAK account
    try {
        $taken = ap_db()->prepare(
            'SELECT owner_user_id FROM ap_sl_links WHERE sl_agent_id = ? AND owner_user_id != ? LIMIT 1'
        );
        $taken->execute([$agentId, $userId]);
        if ($taken->fetch()) {
            return ['ok' => false, 'error' => 'That Second Life avatar is already linked to another VAAK account.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error checking existing links.'];
    }

    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789'; // no 0/O/1/I
    $code = '';
    for ($i = 0; $i < AP_SL_CODE_LEN; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $now = gmdate('c');
    $expires = gmdate('c', time() + AP_SL_CHALLENGE_TTL_SEC);
    $hash = hash('sha256', $code . '|' . $agentId . '|' . $userId);

    try {
        $db = ap_db();
        $db->prepare('DELETE FROM ap_sl_challenges WHERE owner_user_id = ? OR expires_at < ?')
            ->execute([$userId, $now]);
        $db->prepare(
            'INSERT INTO ap_sl_challenges (owner_user_id, sl_username, sl_agent_id, code_hash, attempts, created_at, expires_at)
             VALUES (?,?,?,?,0,?,?)'
        )->execute([$userId, $parsed['legacy'], $agentId, $hash, $now, $expires]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create verification challenge.'];
    }

    $im = ap_sl_send_inworld_code($agentId, $code, $parsed['legacy']);
    $notice = 'Check Second Life for an IM with your VAAK verification code (valid ~10 minutes).';
    $out = [
        'ok' => true,
        'notice' => $notice,
        'challenge' => [
            'sl_username' => $parsed['legacy'],
            'sl_agent_id' => $agentId,
            'expires_at' => $expires,
            'im_ok' => !empty($im['ok']),
        ],
    ];
    if (empty($im['ok'])) {
        $out['notice'] = 'Code created, but the in-world messenger could not be reached yet. '
            . ($im['error'] ?? 'Verifier offline') . '.';
        if (ap_sl_env_get('SL_LINK_DEBUG') === '1') {
            $out['debug_code'] = $code;
            $out['notice'] .= ' Debug code (verifier offline): ' . $code;
        }
    }
    return $out;
}

/**
 * Ask the in-world LSL object to IM the avatar.
 *
 * @return array{ok:bool,error?:string}
 */
/**
 * Linden HTTP-in caps are usually http://simhost-….secondlife.io:12046/cap/…
 * (not HTTPS). Only allow those hosts — never arbitrary http.
 */
function ap_sl_verifier_url_ok(string $url): bool
{
    $url = trim($url);
    if ($url === '') {
        return false;
    }
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return false;
    }
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = (string) ($parts['path'] ?? '');
    if ($host === '' || !str_contains($path, '/cap/')) {
        return false;
    }
    $slHost = str_ends_with($host, '.secondlife.io')
        || str_ends_with($host, '.secondlife.com')
        || str_ends_with($host, '.lindenlab.com')
        || str_contains($host, 'agni.secondlife');
    if (!$slHost) {
        return false;
    }
    if ($scheme === 'https') {
        return true;
    }
    // SL caps are commonly plain HTTP on :12046
    return $scheme === 'http';
}

function ap_sl_send_inworld_code(string $agentId, string $code, string $username): array
{
    $url = ap_sl_env_get('SL_VERIFIER_URL');
    $secret = ap_sl_env_get('SL_VERIFIER_SECRET');
    if ($url === '' || !ap_sl_verifier_url_ok($url)) {
        return ['ok' => false, 'error' => 'In-world verifier URL not configured.'];
    }
    if ($secret === '') {
        return ['ok' => false, 'error' => 'Verifier secret not configured.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => 'curl required.'];
    }
    $body = http_build_query([
        'agent_id' => $agentId,
        'code' => $code,
        'username' => $username,
        'secret' => $secret,
    ]);
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'error' => 'curl_init failed.'];
    }
    $isHttps = str_starts_with(strtolower($url), 'https://');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_PROTOCOLS => $isHttps ? CURLPROTO_HTTPS : (CURLPROTO_HTTP | CURLPROTO_HTTPS),
        CURLOPT_SSL_VERIFYPEER => false, // SL HTTP-in / odd certs
        CURLOPT_SSL_VERIFYHOST => 0,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/x-www-form-urlencoded',
            'User-Agent: VAAK-SL-Link/1.0',
        ],
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if ($http >= 200 && $http < 300) {
        return ['ok' => true];
    }
    return [
        'ok' => false,
        'error' => $err !== '' ? $err : ('Verifier HTTP ' . $http . (is_string($resp) ? (': ' . substr($resp, 0, 80)) : '')),
    ];
}

/**
 * @return array{ok:bool,error?:string,notice?:string,link?:array<string,mixed>}
 */
function ap_sl_challenge_verify(int $userId, string $codeRaw): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    ap_sl_schema_ensure();
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codeRaw) ?? '');
    if (strlen($code) < 4) {
        return ['ok' => false, 'error' => 'Enter the code from your Second Life IM.'];
    }
    $now = gmdate('c');
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM ap_sl_challenges WHERE owner_user_id = ? AND expires_at >= ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$userId, $now]);
        $ch = $st->fetch();
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error.'];
    }
    if (!is_array($ch)) {
        return ['ok' => false, 'error' => 'No active code — request a new one.'];
    }
    $attempts = (int) ($ch['attempts'] ?? 0);
    if ($attempts >= 8) {
        return ['ok' => false, 'error' => 'Too many attempts — request a new code.'];
    }
    $agentId = (string) ($ch['sl_agent_id'] ?? '');
    $expect = hash('sha256', $code . '|' . $agentId . '|' . $userId);
    $ok = hash_equals((string) ($ch['code_hash'] ?? ''), $expect);
    try {
        ap_db()->prepare('UPDATE ap_sl_challenges SET attempts = attempts + 1 WHERE id = ?')
            ->execute([(int) $ch['id']]);
    } catch (Throwable $e) {
    }
    if (!$ok) {
        return ['ok' => false, 'error' => 'That code does not match. Check the IM and try again.'];
    }

    $username = (string) ($ch['sl_username'] ?? '');
    $img = ap_sl_fetch_profile_image($agentId);
    $ts = gmdate('c');
    try {
        $db = ap_db();
        $db->prepare('DELETE FROM ap_sl_challenges WHERE owner_user_id = ?')->execute([$userId]);
        $db->prepare('DELETE FROM ap_sl_links WHERE owner_user_id = ? OR sl_agent_id = ?')
            ->execute([$userId, $agentId]);
        $db->prepare(
            'INSERT INTO ap_sl_links
             (owner_user_id, sl_username, sl_agent_id, sl_display_name, sl_profile_image_url, verified_at, created_at, updated_at)
             VALUES (?,?,?,?,?,?,?,?)'
        )->execute([$userId, $username, $agentId, null, $img, $ts, $ts, $ts]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Verified, but saving the link failed.'];
    }
    $link = ap_sl_link_for_user($userId);
    return [
        'ok' => true,
        'notice' => 'Second Life avatar linked: ' . $username,
        'link' => is_array($link) ? $link : null,
    ];
}

/**
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_sl_unlink(int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    ap_sl_schema_ensure();
    try {
        ap_db()->prepare('DELETE FROM ap_sl_links WHERE owner_user_id = ?')->execute([$userId]);
        ap_db()->prepare('DELETE FROM ap_sl_challenges WHERE owner_user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not unlink.'];
    }
    return ['ok' => true, 'notice' => 'Second Life avatar unlinked.'];
}

function ap_sl_profile_url(string $agentId): string
{
    $agentId = strtolower(trim($agentId));
    if (!ap_sl_valid_agent_id($agentId)) {
        return 'https://secondlife.com/';
    }
    return 'https://world.secondlife.com/resident/' . $agentId;
}

function ap_sl_maps_name_url(string $username): string
{
    // my.secondlife.com uses First.Last style
    $u = trim($username);
    if ($u === '') {
        return 'https://secondlife.com/';
    }
    return 'https://my.secondlife.com/' . rawurlencode($u);
}

/**
 * Lookup verified SL link by local actor_key (public profile badge).
 *
 * @return array<string,mixed>|null
 */
function ap_sl_link_for_actor_key(string $actorKey): ?array
{
    $actorKey = strtolower(preg_replace('/[^a-z0-9_]/', '', $actorKey) ?? '');
    if ($actorKey === '') {
        return null;
    }
    ap_sl_schema_ensure();
    try {
        $st = ap_db()->prepare(
            'SELECT l.* FROM ap_sl_links l
             INNER JOIN ap_users u ON u.id = l.owner_user_id
             WHERE u.actor_key = ? AND u.disabled_at IS NULL
             LIMIT 1'
        );
        $st->execute([$actorKey]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}
