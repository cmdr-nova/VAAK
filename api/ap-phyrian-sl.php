<?php
/**
 * Phyrian Strains: Second Life as a third body (one VAAK user = one strain).
 *
 * A VAAK user who already linked a NovaLandia grid avatar (ap-phyrian-bridge.php)
 * can link a Second Life avatar too. Strains then plays that SL avatar on the
 * grid avatar's row: same strain, resonance, level and lineage everywhere.
 *
 * Flow (same shape as the grid link):
 *  1. User wears the SL Phyrian Strains HUD and touches Register.
 *  2. User enters their SL username or UUID here.
 *  3. VAAK resolves it via strains (must be an SL-world player), queues a
 *     one-time code with vaak_claim_deliver; the SL HUD prints it.
 *  4. User pastes the code; VAAK calls strains vaak_alias_set
 *     {avatar_uuid: grid avatar, alias_uuid: SL avatar, world: "sl"}.
 *
 * Uses the bridge env/secret from ap-phyrian-bridge.php. Tables come from
 * api/migrations/20261109_phyrian_sl_links_postgres.sql (no runtime DDL).
 */
declare(strict_types=1);

const AP_PHYRIAN_SL_TTL_SEC = 600;
const AP_PHYRIAN_SL_CODE_LEN = 6;
const AP_PHYRIAN_SL_MAX_ATTEMPTS = 8;

/**
 * Pure check of a strains vaak_resolve avatar for SL linking.
 * Returns an error string, or null when the avatar can be linked.
 *
 * @param array<string,mixed>|null $avatar
 */
function ap_phyrian_sl_resolved_error(?array $avatar): ?string
{
    if ($avatar === null || empty($avatar['avatar_uuid'])) {
        return 'No Phyrian Strains avatar matched that input.';
    }
    if (!array_key_exists('world', $avatar)) {
        return 'Second Life support is not live on the Strains server yet.';
    }
    if (empty($avatar['registered'])) {
        return 'That Second Life avatar has not registered yet. Wear the SL Phyrian Strains HUD, touch Register, then try again.';
    }
    if ((string) $avatar['world'] !== 'sl') {
        return 'That is a NovaLandia grid avatar. Enter your Second Life username or UUID instead.';
    }
    return null;
}

/** Code hash bound to the SL avatar, the grid avatar and the VAAK user. */
function ap_phyrian_sl_code_hash(string $code, string $slUuid, string $gridUuid, int $userId): string
{
    return hash('sha256', strtoupper($code) . '|' . strtolower($slUuid) . '|' . strtolower($gridUuid) . '|' . $userId);
}

function ap_phyrian_sl_new_code(): string
{
    $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $code = '';
    for ($i = 0; $i < AP_PHYRIAN_SL_CODE_LEN; $i++) {
        $code .= $alphabet[random_int(0, strlen($alphabet) - 1)];
    }
    return $code;
}

/**
 * @return array<string,mixed>|null
 */
function ap_phyrian_sl_link_for_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    try {
        $st = ap_db()->prepare('SELECT * FROM phyrian_sl_links WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        // Table not migrated yet: SL linking simply looks unlinked.
        return null;
    }
}

/**
 * @return array<string,mixed>|null
 */
function ap_phyrian_sl_pending(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    try {
        $st = ap_db()->prepare(
            'SELECT sl_uuid, sl_name, expires_at FROM phyrian_sl_challenges
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
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_phyrian_sl_start(int $userId, string $identifyRaw): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    $grid = ap_phyrian_bridge_link_for_user($userId);
    $gridUuid = is_array($grid) ? strtolower((string) ($grid['avatar_uuid'] ?? '')) : '';
    if (!ap_phyrian_bridge_valid_uuid($gridUuid)) {
        return ['ok' => false, 'error' => 'Link your NovaLandia avatar first. Your Second Life avatar joins that strain.'];
    }
    $parsed = ap_phyrian_bridge_parse_identify($identifyRaw);
    if ($parsed === null) {
        return ['ok' => false, 'error' => 'Enter your Second Life username or avatar UUID.'];
    }
    $call = ap_phyrian_bridge_strains_call('vaak_resolve', [
        'query' => $parsed['value'],
        'query_kind' => $parsed['kind'],
    ]);
    if (empty($call['ok'])) {
        return ['ok' => false, 'error' => (string) ($call['error'] ?? 'Could not resolve that avatar.')];
    }
    $avatar = is_array($call['data']['avatar'] ?? null) ? $call['data']['avatar'] : null;
    $err = ap_phyrian_sl_resolved_error($avatar);
    if ($err !== null) {
        return ['ok' => false, 'error' => $err];
    }
    $slUuid = strtolower((string) $avatar['avatar_uuid']);
    if (!ap_phyrian_bridge_valid_uuid($slUuid)) {
        return ['ok' => false, 'error' => 'Strains returned an invalid avatar UUID.'];
    }
    $slName = (string) ($avatar['avatar_name'] ?? 'Unknown');

    $code = ap_phyrian_sl_new_code();
    $expires = gmdate('c', time() + AP_PHYRIAN_SL_TTL_SEC);
    try {
        $db = ap_db();
        $taken = $db->prepare('SELECT owner_user_id FROM phyrian_sl_links WHERE sl_uuid = ? AND owner_user_id != ? LIMIT 1');
        $taken->execute([$slUuid, $userId]);
        if ($taken->fetch()) {
            return ['ok' => false, 'error' => 'That Second Life avatar is already linked to another VAAK account.'];
        }
        $db->prepare('DELETE FROM phyrian_sl_challenges WHERE owner_user_id = ? OR expires_at < NOW()')
            ->execute([$userId]);
        $db->prepare(
            'INSERT INTO phyrian_sl_challenges
             (owner_user_id, sl_uuid, sl_name, grid_avatar_uuid, code_hash, attempts, expires_at)
             VALUES (?,?,?,?,?,0,?::timestamptz)'
        )->execute([$userId, $slUuid, $slName, $gridUuid, ap_phyrian_sl_code_hash($code, $slUuid, $gridUuid, $userId), $expires]);
    } catch (Throwable $e) {
        error_log('[phyrian-sl] start: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not create verification challenge.'];
    }

    $deliver = ap_phyrian_bridge_strains_call('vaak_claim_deliver', [
        'avatar_uuid' => $slUuid,
        'avatar_name' => $slName,
        'code' => $code,
        'expires_at' => $expires,
        'ttl_seconds' => AP_PHYRIAN_SL_TTL_SEC,
    ]);
    $notice = 'Code queued for ' . $slName . '. In Second Life, wear the Phyrian Strains HUD and touch Status '
        . 'to receive it, then paste it here. Valid about 10 minutes.';
    if (empty($deliver['ok'])) {
        $notice = 'Code created, but the strains deliverer reported: ' . (string) ($deliver['error'] ?? 'delivery failed') . '.';
    }
    if (ap_phyrian_bridge_env_get('PHYRIAN_BRIDGE_DEBUG') === '1') {
        $notice .= ' Debug code: ' . $code;
    }
    return ['ok' => true, 'notice' => $notice];
}

/**
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_phyrian_sl_verify(int $userId, string $codeRaw): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    $code = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $codeRaw) ?? '');
    if (strlen($code) < 4) {
        return ['ok' => false, 'error' => 'Enter the code from your Second Life HUD.'];
    }
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM phyrian_sl_challenges
             WHERE owner_user_id = ? AND expires_at >= NOW()
             ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$userId]);
        $ch = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Database error.'];
    }
    if (!is_array($ch)) {
        return ['ok' => false, 'error' => 'No active code. Request a new one.'];
    }
    if ((int) ($ch['attempts'] ?? 0) >= AP_PHYRIAN_SL_MAX_ATTEMPTS) {
        return ['ok' => false, 'error' => 'Too many attempts. Request a new code.'];
    }
    $slUuid = strtolower((string) ($ch['sl_uuid'] ?? ''));
    $gridUuid = strtolower((string) ($ch['grid_avatar_uuid'] ?? ''));
    try {
        ap_db()->prepare('UPDATE phyrian_sl_challenges SET attempts = attempts + 1 WHERE id = ?')
            ->execute([(int) $ch['id']]);
    } catch (Throwable $e) {
    }
    if (!hash_equals((string) ($ch['code_hash'] ?? ''), ap_phyrian_sl_code_hash($code, $slUuid, $gridUuid, $userId))) {
        return ['ok' => false, 'error' => 'That code does not match. Check the HUD message and try again.'];
    }
    // The grid link must still be the one the code was issued for.
    $grid = ap_phyrian_bridge_link_for_user($userId);
    if (!is_array($grid) || strtolower((string) ($grid['avatar_uuid'] ?? '')) !== $gridUuid) {
        return ['ok' => false, 'error' => 'Your NovaLandia link changed. Request a new code.'];
    }

    $alias = ap_phyrian_bridge_strains_call('vaak_alias_set', [
        'avatar_uuid' => $gridUuid,
        'alias_uuid' => $slUuid,
        'world' => 'sl',
    ]);
    if (empty($alias['ok'])) {
        return ['ok' => false, 'error' => 'Strains refused the link: ' . (string) ($alias['error'] ?? 'unknown error') . '.'];
    }

    $slName = (string) ($ch['sl_name'] ?? '');
    try {
        $db = ap_db();
        $db->beginTransaction();
        $db->prepare('DELETE FROM phyrian_sl_challenges WHERE owner_user_id = ?')->execute([$userId]);
        $db->prepare('DELETE FROM phyrian_sl_links WHERE owner_user_id = ? OR sl_uuid = ?')->execute([$userId, $slUuid]);
        $db->prepare(
            'INSERT INTO phyrian_sl_links (owner_user_id, sl_uuid, sl_name, grid_avatar_uuid, verified_at, created_at)
             VALUES (?,?,?,?,NOW(),NOW())'
        )->execute([$userId, $slUuid, $slName, $gridUuid]);
        $db->commit();
    } catch (Throwable $e) {
        if (isset($db) && $db instanceof PDO && $db->inTransaction()) {
            $db->rollBack();
        }
        error_log('[phyrian-sl] verify save: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Linked on Strains, but saving the link here failed. Try again.'];
    }

    $notice = 'Linked Second Life avatar ' . $slName . '. It now plays your NovaLandia strain.';
    if (!empty($alias['data']['superseded_sl_player'])) {
        $notice .= ' Progress it made on its own in Second Life is archived.';
    }
    return ['ok' => true, 'notice' => $notice];
}

/**
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_phyrian_sl_unlink(int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    $link = ap_phyrian_sl_link_for_user($userId);
    try {
        ap_db()->prepare('DELETE FROM phyrian_sl_links WHERE owner_user_id = ?')->execute([$userId]);
        ap_db()->prepare('DELETE FROM phyrian_sl_challenges WHERE owner_user_id = ?')->execute([$userId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not unlink.'];
    }
    $slUuid = is_array($link) ? strtolower((string) ($link['sl_uuid'] ?? '')) : '';
    if ($slUuid !== '' && ap_phyrian_bridge_valid_uuid($slUuid)) {
        ap_phyrian_bridge_strains_call('vaak_alias_clear', ['alias_uuid' => $slUuid]);
    }
    return ['ok' => true, 'notice' => 'Second Life avatar unlinked.'];
}

/**
 * Called after the grid link changes. A grid unlink drops the SL link too;
 * a re-link to a different grid avatar drops it as well, since the code was
 * proven against the old one.
 */
function ap_phyrian_sl_after_grid_change(int $userId): void
{
    $link = ap_phyrian_sl_link_for_user($userId);
    if (!is_array($link)) {
        return;
    }
    $grid = ap_phyrian_bridge_link_for_user($userId);
    $gridUuid = is_array($grid) ? strtolower((string) ($grid['avatar_uuid'] ?? '')) : '';
    if ($gridUuid === '' || $gridUuid !== strtolower((string) ($link['grid_avatar_uuid'] ?? ''))) {
        ap_phyrian_sl_unlink($userId);
    }
}
