<?php
/**
 * Phyrian Strains OpenSim ↔ VAAK identity bridge (Phase 3).
 *
 * Flow (mirrors ap-sl-link.php, NovaLandia grid instead of Second Life):
 *  1. User identifies avatar (name / strains URL / UUID)
 *  2. VAAK resolves via strains.novalandia.online bridge API
 *  3. Short-lived single-use code queued for HUD delivery in-world
 *  4. User pastes code → durable vaak_actor ↔ avatar_uuid bind
 *
 * Perks when verified:
 *  - +1 daily resonance on VAAK check-in
 *  - +1 on OpenSim monolith claim (strains side)
 *  - public profile badge "Resonant"
 *
 * Env: /etc/mkultra/phyrian-bridge.env
 *   PHYRIAN_STRAINS_API_URL=https://strains.novalandia.online/
 *   PHYRIAN_STRAINS_BRIDGE_SECRET=…
 *   PHYRIAN_BRIDGE_DEBUG=0
 */
declare(strict_types=1);

const AP_PHYRIAN_BRIDGE_ENV_PATH = '/etc/mkultra/phyrian-bridge.env';
const AP_PHYRIAN_BRIDGE_TTL_SEC = 600;
const AP_PHYRIAN_BRIDGE_CODE_LEN = 6;
const AP_PHYRIAN_BRIDGE_ONLINE_SECS = 1800; // soft "recently active" window

/**
 * @return array<string,string>
 */
function ap_phyrian_bridge_env(): array
{
    static $cache = null;
    if (is_array($cache)) {
        return $cache;
    }
    $cache = [];
    if (!is_readable(AP_PHYRIAN_BRIDGE_ENV_PATH)) {
        return $cache;
    }
    $raw = @file_get_contents(AP_PHYRIAN_BRIDGE_ENV_PATH);
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

function ap_phyrian_bridge_env_get(string $key, string $default = ''): string
{
    $env = ap_phyrian_bridge_env();
    return isset($env[$key]) && $env[$key] !== '' ? (string) $env[$key] : $default;
}

function ap_phyrian_bridge_migrate(?PDO $db = null): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $db = $db ?? ap_db();
    try {
        $db->exec(
            "CREATE TABLE IF NOT EXISTS phyrian_bridge_links (
                owner_user_id INTEGER PRIMARY KEY,
                avatar_uuid TEXT NOT NULL UNIQUE,
                avatar_name TEXT NOT NULL DEFAULT '',
                strain_snapshot TEXT,
                status TEXT NOT NULL DEFAULT 'verified',
                verified_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                unlinked_at TIMESTAMPTZ
            )"
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS phyrian_bridge_links_uuid_idx
             ON phyrian_bridge_links (avatar_uuid)'
        );
        $db->exec(
            "CREATE TABLE IF NOT EXISTS phyrian_bridge_challenges (
                id BIGSERIAL PRIMARY KEY,
                owner_user_id INTEGER NOT NULL,
                avatar_uuid TEXT NOT NULL,
                avatar_name TEXT NOT NULL DEFAULT '',
                code_hash TEXT NOT NULL,
                attempts INTEGER NOT NULL DEFAULT 0,
                created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
                expires_at TIMESTAMPTZ NOT NULL
            )"
        );
        $db->exec(
            'CREATE INDEX IF NOT EXISTS phyrian_bridge_challenges_owner_idx
             ON phyrian_bridge_challenges (owner_user_id, expires_at DESC)'
        );
    } catch (Throwable $e) {
        // Tolerate missing CREATE privilege when tables already exist.
        error_log('[phyrian-bridge] migrate: ' . $e->getMessage());
    }
    $done = true;
}

function ap_phyrian_bridge_valid_uuid(string $id): bool
{
    return (bool) preg_match(
        '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
        trim($id)
    );
}

/**
 * Normalize identify field: URL / UUID / avatar name.
 *
 * @return array{kind:string,value:string}|null
 */
function ap_phyrian_bridge_parse_identify(string $raw): ?array
{
    $raw = trim($raw);
    if ($raw === '' || strlen($raw) > 200) {
        return null;
    }
    // strains profile URL
    if (preg_match('#strains\.novalandia\.online/[^\s]*[?&]uuid=([0-9a-f-]{36})#i', $raw, $m)) {
        return ['kind' => 'uuid', 'value' => strtolower($m[1])];
    }
    if (preg_match('#^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$#i', $raw)) {
        return ['kind' => 'uuid', 'value' => strtolower($raw)];
    }
    // Avatar name: "First Last" or "First.Last"
    $name = str_replace(['+', '%20', '.'], ' ', $raw);
    $name = trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    if ($name === '' || strlen($name) > 80 || str_contains($name, '@') || str_contains($name, '/')) {
        return null;
    }
    if (!preg_match('/^[\p{L}\p{N}][\p{L}\p{N} _\'-]{0,78}$/u', $name)) {
        return null;
    }
    return ['kind' => 'name', 'value' => $name];
}

/**
 * @param array<string,mixed> $body
 * @return array{ok:bool,http:int,data?:array<string,mixed>,error?:string}
 */
function ap_phyrian_bridge_strains_call(string $action, array $body): array
{
    $url = rtrim(ap_phyrian_bridge_env_get('PHYRIAN_STRAINS_API_URL', 'https://strains.novalandia.online/'), '/') . '/';
    $secret = ap_phyrian_bridge_env_get('PHYRIAN_STRAINS_BRIDGE_SECRET');
    if ($secret === '') {
        return ['ok' => false, 'http' => 0, 'error' => 'OpenSim bridge is not configured on this server yet.'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'http' => 0, 'error' => 'curl extension required.'];
    }
    $payload = array_merge($body, [
        'action' => $action,
        'bridge_secret' => $secret,
    ]);
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json)) {
        return ['ok' => false, 'http' => 0, 'error' => 'Could not encode bridge request.'];
    }
    $ch = curl_init($url);
    if ($ch === false) {
        return ['ok' => false, 'http' => 0, 'error' => 'curl_init failed.'];
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 4,
        CURLOPT_TIMEOUT => 12,
        CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: VAAK-Phyrian-Bridge/1.0 (+https://vaak.monster)',
            'X-Strains-Bridge-Secret: ' . $secret,
        ],
        CURLOPT_POSTFIELDS => $json,
    ]);
    $resp = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    if (!is_string($resp) || $resp === '') {
        return ['ok' => false, 'http' => $http, 'error' => $err !== '' ? $err : 'Empty response from strains API.'];
    }
    $data = json_decode($resp, true);
    if (!is_array($data)) {
        return ['ok' => false, 'http' => $http, 'error' => 'Invalid JSON from strains API.'];
    }
    if ($http >= 200 && $http < 300 && !empty($data['ok'])) {
        return ['ok' => true, 'http' => $http, 'data' => $data];
    }
    $msg = (string) ($data['error'] ?? $data['message'] ?? ('Strains HTTP ' . $http));
    return ['ok' => false, 'http' => $http, 'data' => $data, 'error' => $msg];
}

/**
 * @return array<string,mixed>|null
 */
function ap_phyrian_bridge_link_for_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    ap_phyrian_bridge_migrate();
    try {
        $st = ap_db()->prepare(
            "SELECT * FROM phyrian_bridge_links
             WHERE owner_user_id = ? AND status = 'verified' AND unlinked_at IS NULL
             LIMIT 1"
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ap_phyrian_bridge_is_linked(int $userId): bool
{
    return ap_phyrian_bridge_link_for_user($userId) !== null;
}

/**
 * @return array<string,mixed>|null
 */
function ap_phyrian_bridge_link_for_actor_key(string $actorKey): ?array
{
    $actorKey = strtolower(preg_replace('/[^a-z0-9_]/', '', $actorKey) ?? '');
    if ($actorKey === '') {
        return null;
    }
    ap_phyrian_bridge_migrate();
    try {
        $st = ap_db()->prepare(
            "SELECT l.* FROM phyrian_bridge_links l
             INNER JOIN ap_users u ON u.id = l.owner_user_id
             WHERE u.actor_key = ? AND u.disabled_at IS NULL
               AND l.status = 'verified' AND l.unlinked_at IS NULL
             LIMIT 1"
        );
        $st->execute([$actorKey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Pending (unexpired) challenge for UI.
 *
 * @return array<string,mixed>|null
 */
function ap_phyrian_bridge_pending_challenge(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    ap_phyrian_bridge_migrate();
    try {
        $st = ap_db()->prepare(
            'SELECT avatar_uuid, avatar_name, expires_at, created_at
             FROM phyrian_bridge_challenges
             WHERE owner_user_id = ? AND expires_at >= NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Resolve identify input to a confirmation card payload (no token yet).
 *
 * @return array{ok:bool,error?:string,avatar?:array<string,mixed>}
 */
function ap_phyrian_bridge_resolve(int $userId, string $identifyRaw): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    $parsed = ap_phyrian_bridge_parse_identify($identifyRaw);
    if ($parsed === null) {
        return ['ok' => false, 'error' => 'Enter a NovaLandia avatar name, strains profile URL, or avatar UUID.'];
    }
    $call = ap_phyrian_bridge_strains_call('vaak_resolve', [
        'query' => $parsed['value'],
        'query_kind' => $parsed['kind'],
    ]);
    if (empty($call['ok'])) {
        return ['ok' => false, 'error' => (string) ($call['error'] ?? 'Could not resolve that avatar.')];
    }
    $data = $call['data'] ?? [];
    $avatar = is_array($data['avatar'] ?? null) ? $data['avatar'] : null;
    if ($avatar === null || empty($avatar['avatar_uuid'])) {
        return ['ok' => false, 'error' => 'No NovaLandia Strains avatar matched that input.'];
    }
    $uuid = strtolower((string) $avatar['avatar_uuid']);
    if (!ap_phyrian_bridge_valid_uuid($uuid)) {
        return ['ok' => false, 'error' => 'Strains returned an invalid avatar UUID.'];
    }
    if (empty($avatar['registered'])) {
        return [
            'ok' => false,
            'error' => 'That avatar exists but is not registered with Phyrian Strains yet. '
                . 'Wear the HUD in NovaLandia and touch Register, then try again.',
            'avatar' => $avatar,
        ];
    }
    // One OpenSim avatar → one VAAK account
    try {
        ap_phyrian_bridge_migrate();
        $taken = ap_db()->prepare(
            "SELECT owner_user_id FROM phyrian_bridge_links
             WHERE avatar_uuid = ? AND owner_user_id != ?
               AND status = 'verified' AND unlinked_at IS NULL
             LIMIT 1"
        );
        $taken->execute([$uuid, $userId]);
        if ($taken->fetch()) {
            return ['ok' => false, 'error' => 'That OpenSim avatar is already linked to another VAAK account.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error checking existing links.'];
    }
    return ['ok' => true, 'avatar' => $avatar];
}

/**
 * @return array{ok:bool,error?:string,notice?:string,debug_code?:string,challenge?:array<string,mixed>,avatar?:array<string,mixed>}
 */
function ap_phyrian_bridge_challenge_start(int $userId, string $identifyRaw): array
{
    $resolved = ap_phyrian_bridge_resolve($userId, $identifyRaw);
    if (empty($resolved['ok'])) {
        return $resolved;
    }
    /** @var array<string,mixed> $avatar */
    $avatar = $resolved['avatar'];
    $uuid = strtolower((string) $avatar['avatar_uuid']);
    $name = (string) ($avatar['avatar_name'] ?? 'Unknown');

    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < AP_PHYRIAN_BRIDGE_CODE_LEN; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    $expiresUnix = time() + AP_PHYRIAN_BRIDGE_TTL_SEC;
    $expires = gmdate('c', $expiresUnix);
    $hash = hash('sha256', $code . '|' . $uuid . '|' . $userId);

    try {
        ap_phyrian_bridge_migrate();
        $db = ap_db();
        $db->prepare('DELETE FROM phyrian_bridge_challenges WHERE owner_user_id = ? OR expires_at < NOW()')
            ->execute([$userId]);
        $db->prepare(
            'INSERT INTO phyrian_bridge_challenges
             (owner_user_id, avatar_uuid, avatar_name, code_hash, attempts, expires_at)
             VALUES (?,?,?,?,0,?::timestamptz)'
        )->execute([$userId, $uuid, $name, $hash, $expires]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create verification challenge.'];
    }

    $deliver = ap_phyrian_bridge_strains_call('vaak_claim_deliver', [
        'avatar_uuid' => $uuid,
        'avatar_name' => $name,
        'code' => $code,
        'expires_at' => $expires,
        'ttl_seconds' => AP_PHYRIAN_BRIDGE_TTL_SEC,
    ]);

    $recent = !empty($avatar['recently_active']);
    $notice = 'Claim code queued for ' . $name . '. '
        . 'In NovaLandia, wear the Phyrian Strains HUD and touch Status (or Register) to receive the code, '
        . 'then paste it here. Valid ~10 minutes.';
    if (!$recent) {
        $notice = 'That avatar has not been active recently. Log into NovaLandia as ' . $name
            . ', wear the HUD, touch Status to pull the code, then paste it here.';
    }
    if (empty($deliver['ok'])) {
        $notice = 'Code created, but the strains deliverer reported: '
            . (string) ($deliver['error'] ?? 'delivery failed') . '.';
    }

    $out = [
        'ok' => true,
        'notice' => $notice,
        'avatar' => $avatar,
        'challenge' => [
            'avatar_uuid' => $uuid,
            'avatar_name' => $name,
            'expires_at' => $expires,
            'deliver_ok' => !empty($deliver['ok']),
            'recently_active' => $recent,
        ],
    ];
    if (ap_phyrian_bridge_env_get('PHYRIAN_BRIDGE_DEBUG') === '1') {
        $out['debug_code'] = $code;
        $out['notice'] .= ' Debug code: ' . $code;
    }
    return $out;
}

/**
 * @return array{ok:bool,error?:string,notice?:string,link?:array<string,mixed>}
 */
function ap_phyrian_bridge_challenge_verify(int $userId, string $codeRaw): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    ap_phyrian_bridge_migrate();
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codeRaw) ?? '');
    if (strlen($code) < 4) {
        return ['ok' => false, 'error' => 'Enter the code from your Phyrian Strains HUD.'];
    }
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM phyrian_bridge_challenges
             WHERE owner_user_id = ? AND expires_at >= NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$userId]);
        $ch = $st->fetch(PDO::FETCH_ASSOC);
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
    $uuid = strtolower((string) ($ch['avatar_uuid'] ?? ''));
    $expect = hash('sha256', $code . '|' . $uuid . '|' . $userId);
    $ok = hash_equals((string) ($ch['code_hash'] ?? ''), $expect);
    try {
        ap_db()->prepare('UPDATE phyrian_bridge_challenges SET attempts = attempts + 1 WHERE id = ?')
            ->execute([(int) $ch['id']]);
    } catch (Throwable $e) {
    }
    if (!$ok) {
        return ['ok' => false, 'error' => 'That code does not match. Check the HUD message and try again.'];
    }

    $name = (string) ($ch['avatar_name'] ?? '');
    $strain = null;
    // Refresh strain snapshot from strains
    $resolve = ap_phyrian_bridge_strains_call('vaak_resolve', [
        'query' => $uuid,
        'query_kind' => 'uuid',
    ]);
    if (!empty($resolve['ok']) && is_array($resolve['data']['avatar'] ?? null)) {
        $av = $resolve['data']['avatar'];
        if (!empty($av['avatar_name'])) {
            $name = (string) $av['avatar_name'];
        }
        if (isset($av['strain']) && is_string($av['strain']) && $av['strain'] !== '') {
            $strain = $av['strain'];
        }
    }

    $actorKey = '';
    $handle = '';
    try {
        $ust = ap_db()->prepare(
            'SELECT actor_key, COALESCE(NULLIF(username, \'\'), actor_key) AS handle
             FROM ap_users WHERE id = ? LIMIT 1'
        );
        $ust->execute([$userId]);
        $urow = $ust->fetch(PDO::FETCH_ASSOC);
        if (is_array($urow)) {
            $actorKey = (string) ($urow['actor_key'] ?? '');
            $handle = (string) ($urow['handle'] ?? $actorKey);
        }
    } catch (Throwable $e) {
    }

    try {
        $db = ap_db();
        $db->beginTransaction();
        $db->prepare('DELETE FROM phyrian_bridge_challenges WHERE owner_user_id = ?')->execute([$userId]);
        // Re-link: clear previous binds for this user or this avatar
        $db->prepare(
            "UPDATE phyrian_bridge_links
             SET status = 'revoked', unlinked_at = NOW(), updated_at = NOW()
             WHERE (owner_user_id = ? OR avatar_uuid = ?)
               AND status = 'verified' AND unlinked_at IS NULL"
        )->execute([$userId, $uuid]);
        $db->prepare('DELETE FROM phyrian_bridge_links WHERE owner_user_id = ? OR avatar_uuid = ?')
            ->execute([$userId, $uuid]);
        $db->prepare(
            "INSERT INTO phyrian_bridge_links
             (owner_user_id, avatar_uuid, avatar_name, strain_snapshot, status, verified_at, created_at, updated_at)
             VALUES (?,?,?,?, 'verified', NOW(), NOW(), NOW())"
        )->execute([$userId, $uuid, $name, $strain]);
        $db->commit();
    } catch (Throwable $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'Verified, but saving the link failed.'];
    }

    $linkSet = ap_phyrian_bridge_strains_call('vaak_link_set', [
        'avatar_uuid' => $uuid,
        'vaak_owner_id' => $userId,
        'vaak_actor_key' => $actorKey,
        'vaak_handle' => $handle,
    ]);
    if (empty($linkSet['ok'])) {
        error_log('[phyrian-bridge] link_set failed: ' . (string) ($linkSet['error'] ?? ''));
    }

    $link = ap_phyrian_bridge_link_for_user($userId);
    return [
        'ok' => true,
        'notice' => 'Linked OpenSim avatar ' . $name . '. Resonant badge and +1 daily resonance are active.',
        'link' => is_array($link) ? $link : null,
    ];
}

/**
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_phyrian_bridge_unlink(int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    ap_phyrian_bridge_migrate();
    $link = ap_phyrian_bridge_link_for_user($userId);
    $uuid = is_array($link) ? (string) ($link['avatar_uuid'] ?? '') : '';
    try {
        ap_db()->prepare(
            "UPDATE phyrian_bridge_links
             SET status = 'revoked', unlinked_at = NOW(), updated_at = NOW()
             WHERE owner_user_id = ? AND status = 'verified'"
        )->execute([$userId]);
        ap_db()->prepare('DELETE FROM phyrian_bridge_links WHERE owner_user_id = ?')->execute([$userId]);
        ap_db()->prepare('DELETE FROM phyrian_bridge_challenges WHERE owner_user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not unlink.'];
    }
    if ($uuid !== '' && ap_phyrian_bridge_valid_uuid($uuid)) {
        ap_phyrian_bridge_strains_call('vaak_link_clear', ['avatar_uuid' => $uuid]);
    }
    return ['ok' => true, 'notice' => 'OpenSim avatar unlinked. Resonant perk removed.'];
}

/** Public strains profile URL for a linked avatar. */
function ap_phyrian_bridge_profile_url(string $avatarUuid): string
{
    $avatarUuid = strtolower(trim($avatarUuid));
    if (!ap_phyrian_bridge_valid_uuid($avatarUuid)) {
        return 'https://strains.novalandia.online/';
    }
    return 'https://strains.novalandia.online/?uuid=' . rawurlencode($avatarUuid);
}

/**
 * HTML snippet for the Resonant profile badge (empty string when not linked).
 */
function ap_phyrian_bridge_resonant_badge_html(?array $link): string
{
    if (!is_array($link) || empty($link['avatar_name'])) {
        return '';
    }
    $name = htmlspecialchars((string) $link['avatar_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $strain = trim((string) ($link['strain_snapshot'] ?? ''));
    $title = 'Linked NovaLandia OpenSim avatar: ' . $name;
    if ($strain !== '') {
        $title .= ' · ' . $strain;
    }
    $titleEsc = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    return ' <span class="resonant-badge" title="' . $titleEsc . '" aria-label="Resonant — linked OpenSim avatar">'
        . '◈ Resonant</span>';
}
