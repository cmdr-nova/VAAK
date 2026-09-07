<?php
/**
 * Mastodon-style featured accounts (endorsements) on local profiles.
 * Stored per VAAK user; shown on HTML profile "Featured" tab.
 */
declare(strict_types=1);

const AP_FEATURED_ACCOUNTS_MAX = 12;

/** Load remote media helpers (avatar warm/cache) once when needed. */
function ap_featured_media_lib_ensure(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    if (!function_exists('ap_remote_media_ensure') && is_file(__DIR__ . '/ap-r2.php')) {
        require_once __DIR__ . '/ap-r2.php';
    }
}

function ap_featured_schema_ensure(): void
{
    $db = ap_db();
    if (ap_db_driver($db) === 'pgsql') {
        // PostgreSQL schema is provisioned by the migration/import runbook.
        return;
    }
    $idColumn = 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db->exec(
        "CREATE TABLE IF NOT EXISTS ap_featured_accounts (
            id {$idColumn},
            owner_user_id INTEGER NOT NULL,
            target_actor_id TEXT NOT NULL,
            position INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL,
            UNIQUE(owner_user_id, target_actor_id)
        );
        CREATE INDEX IF NOT EXISTS idx_ap_featured_owner
            ON ap_featured_accounts(owner_user_id, position ASC, id ASC);"
    );
}

/**
 * @return list<array<string,mixed>>
 */
function ap_featured_list(int $ownerUserId): array
{
    if ($ownerUserId <= 0) {
        return [];
    }
    ap_featured_schema_ensure();
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM ap_featured_accounts
             WHERE owner_user_id = ?
             ORDER BY position ASC, id ASC
             LIMIT 40'
        );
        $st->execute([$ownerUserId]);
        $rows = $st->fetchAll() ?: [];
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        return [];
    }
}

function ap_featured_count(int $ownerUserId): int
{
    return count(ap_featured_list($ownerUserId));
}

/**
 * Resolve display fields + avatar URL for a featured actor.
 *
 * @return array{actor_id:string,acct:string,display_name:string,avatar:string,is_local:bool}
 */
function ap_featured_card_for_actor(string $actorId): array
{
    $actorId = rtrim(trim($actorId), '/');
    $isLocal = (bool) preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $actorId, $lm);
    $acct = '';
    $display = '';
    $avatar = '';

    if ($isLocal) {
        $key = strtolower($lm[1]);
        $acct = $key . '@mkultra.monster';
        $prof = function_exists('ap_profile_get') ? ap_profile_get($key) : [];
        $display = trim((string) ($prof['name'] ?? $key));
        if ($display === '') {
            $display = $key;
        }
        $avatar = function_exists('ap_local_avatar_url')
            ? ap_local_avatar_url($prof['icon_url'] ?? null)
            : (string) ($prof['icon_url'] ?? 'https://mkultra.monster/img/avatar/local-default.webp');
    } else {
        $meta = function_exists('ap_remote_actor_get') ? ap_remote_actor_get($actorId) : null;
        $host = '';
        $user = '';
        if (is_array($meta)) {
            $user = (string) ($meta['username'] ?? '');
            $host = (string) ($meta['host'] ?? '');
            $display = trim((string) ($meta['display_name'] ?? ''));
        }
        if ($host === '') {
            $h = parse_url($actorId, PHP_URL_HOST);
            $host = is_string($h) ? strtolower($h) : '';
        }
        if ($user === '' && preg_match('#/(?:users|@)([^/]+)/?$#', $actorId, $um)) {
            $user = rawurldecode($um[1]);
        }
        $acct = ($user !== '' && $host !== '') ? ($user . '@' . $host) : $actorId;
        if ($display === '') {
            $display = $user !== '' ? $user : $acct;
        }
        // Prefer cached R2/public avatar; warm if missing
        ap_featured_media_lib_ensure();
        $cached = null;
        if (function_exists('ap_remote_media_get')) {
            $cached = ap_remote_media_get($actorId, 'avatar');
        }
        if (is_array($cached) && !empty($cached['public_url'])) {
            $avatar = (string) $cached['public_url'];
        } elseif (function_exists('ap_remote_media_ensure')) {
            $warmed = ap_remote_media_ensure($actorId, 'avatar', false);
            if (is_string($warmed) && $warmed !== '') {
                $avatar = $warmed;
            }
        }
        if ($avatar === '' && is_array($meta) && !empty($meta['icon_source_url'])) {
            $avatar = (string) $meta['icon_source_url'];
        }
        if ($avatar === '') {
            $avatar = defined('AP_REMOTE_AVATAR_FALLBACK')
                ? AP_REMOTE_AVATAR_FALLBACK
                : 'https://mkultra.monster/img/avatar/default.webp';
        }
    }

    return [
        'actor_id' => $actorId,
        'acct' => $acct,
        'display_name' => $display,
        'avatar' => $avatar,
        'is_local' => $isLocal,
    ];
}

/**
 * @return list<array{actor_id:string,acct:string,display_name:string,avatar:string,is_local:bool,id:int,position:int}>
 */
function ap_featured_cards(int $ownerUserId): array
{
    $out = [];
    foreach (ap_featured_list($ownerUserId) as $row) {
        $aid = rtrim((string) ($row['target_actor_id'] ?? ''), '/');
        if ($aid === '' || !str_starts_with($aid, 'https://')) {
            continue;
        }
        $card = ap_featured_card_for_actor($aid);
        $card['id'] = (int) ($row['id'] ?? 0);
        $card['position'] = (int) ($row['position'] ?? 0);
        $out[] = $card;
    }
    return $out;
}

/**
 * @return list<array{actor_id:string,acct:string,display_name:string,avatar:string,is_local:bool}>
 */
function ap_featured_cards_for_actor_key(string $actorKey): array
{
    $actorKey = strtolower(preg_replace('/[^a-z0-9_]/', '', $actorKey) ?? '');
    if ($actorKey === '') {
        return [];
    }
    try {
        $st = ap_db()->prepare(
            'SELECT id FROM ap_users WHERE actor_key = ? AND disabled_at IS NULL LIMIT 1'
        );
        $st->execute([$actorKey]);
        $id = (int) ($st->fetchColumn() ?: 0);
    } catch (Throwable $e) {
        return [];
    }
    if ($id <= 0) {
        return [];
    }
    return ap_featured_cards($id);
}

/**
 * Fetch remote actor doc into remote_actors + warm avatar.
 *
 * @return array{ok:bool,error?:string,actor_id?:string}
 */
function ap_featured_ensure_actor(string $actorId): array
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid actor URL.'];
    }
    // Local peers: nothing to warm remotely
    if (preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $actorId)) {
        return ['ok' => true, 'actor_id' => $actorId];
    }
    if (!function_exists('ap_fetch_actor_doc')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    $doc = function_exists('ap_fetch_actor_doc') ? ap_fetch_actor_doc($actorId) : null;
    if (!is_array($doc) && function_exists('ap_fetch_as2_object')) {
        $doc = ap_fetch_as2_object($actorId);
    }
    if (is_array($doc) && function_exists('ap_remote_actor_upsert')) {
        $icon = null;
        if (isset($doc['icon']['url']) && is_string($doc['icon']['url'])) {
            $icon = $doc['icon']['url'];
        } elseif (isset($doc['icon']) && is_string($doc['icon'])) {
            $icon = $doc['icon'];
        }
        $header = null;
        if (isset($doc['image']['url']) && is_string($doc['image']['url'])) {
            $header = $doc['image']['url'];
        }
        $uname = (string) ($doc['preferredUsername'] ?? '');
        $dname = (string) ($doc['name'] ?? $uname);
        $host = parse_url($actorId, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : '';
        try {
            ap_remote_actor_upsert($actorId, [
                'username' => $uname !== '' ? $uname : null,
                'display_name' => $dname !== '' ? $dname : null,
                'host' => $host !== '' ? $host : null,
                'icon_source_url' => $icon,
                'image_source_url' => $header,
            ]);
        } catch (Throwable $e) {
            // continue — still try media warm
        }
    }
    try {
        ap_featured_media_lib_ensure();
        if (function_exists('ap_remote_media_ensure')) {
            ap_remote_media_ensure($actorId, 'avatar', true);
        }
    } catch (Throwable $e) {
        // SQLite locks / R2 blips must not block featuring the account
    }
    return ['ok' => true, 'actor_id' => $actorId];
}

/**
 * @return array{ok:bool,error?:string,notice?:string,card?:array<string,mixed>}
 */
/**
 * Normalize pasted handles / URLs (ZWSP, fullwidth @, profile page URLs).
 */
function ap_featured_normalize_ref(string $raw): string
{
    $raw = trim($raw);
    // Strip BOM / zero-width / bidi marks common in mobile copy-paste
    $raw = preg_replace('/[\x{200B}-\x{200D}\x{FEFF}\x{2060}\x{202A}-\x{202E}]/u', '', $raw) ?? $raw;
    // Fullwidth ＠ → ASCII @
    $raw = str_replace(['＠', '：'], ['@', ':'], $raw);
    $raw = trim($raw);
    // HTML profile pages → @user@host for WebFinger
    if (preg_match('#^https://([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/@([A-Za-z0-9_.\-]+)/?$#', $raw, $m)) {
        return '@' . $m[2] . '@' . strtolower($m[1]);
    }
    if (preg_match('#^https://([A-Za-z0-9.\-]+\.[A-Za-z]{2,})/users/([A-Za-z0-9_.\-]+)/?$#', $raw, $m)) {
        // Only treat as handle form when path looks like a username (not numeric GoToSocial ids)
        if (!ctype_digit($m[2])) {
            return '@' . $m[2] . '@' . strtolower($m[1]);
        }
    }
    return $raw;
}

function ap_featured_add(int $ownerUserId, string $rawRef): array
{
    if ($ownerUserId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    ap_featured_schema_ensure();
    $raw = ap_featured_normalize_ref($rawRef);
    if ($raw === '') {
        return ['ok' => false, 'error' => 'Enter @user@host or an actor URL.'];
    }
    if (count(ap_featured_list($ownerUserId)) >= AP_FEATURED_ACCOUNTS_MAX) {
        return ['ok' => false, 'error' => 'You can feature at most ' . AP_FEATURED_ACCOUNTS_MAX . ' accounts.'];
    }

    if (!function_exists('ap_resolve_actor_ref')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    $actorId = null;
    if (str_starts_with($raw, 'https://')) {
        // Already an actor URL (or /ap/users/… numeric id) — use as-is
        $actorId = rtrim($raw, '/');
        // If it's clearly a non-actor profile page we missed, try resolve
        if (preg_match('#^https://[^/]+/@[^/]+$#', $actorId) && function_exists('ap_resolve_actor_ref')) {
            $resolved = ap_resolve_actor_ref(ap_featured_normalize_ref($actorId));
            if (is_string($resolved) && str_starts_with($resolved, 'https://')) {
                $actorId = $resolved;
            }
        }
    } elseif (function_exists('ap_resolve_actor_ref')) {
        $actorId = ap_resolve_actor_ref($raw);
    }
    if (!is_string($actorId) || $actorId === '' || !str_starts_with($actorId, 'https://')) {
        return ['ok' => false, 'error' => 'Could not resolve that account (WebFinger failed — check the handle or try the actor URL).'];
    }
    $actorId = rtrim($actorId, '/');

    // Don't feature yourself
    try {
        $me = ap_db()->prepare('SELECT actor_id FROM ap_users WHERE id = ? LIMIT 1');
        $me->execute([$ownerUserId]);
        $myActor = rtrim((string) ($me->fetchColumn() ?: ''), '/');
        if ($myActor !== '' && $myActor === $actorId) {
            return ['ok' => false, 'error' => 'You can’t feature yourself.'];
        }
    } catch (Throwable $e) {
    }

    try {
        $ens = ap_featured_ensure_actor($actorId);
        if (empty($ens['ok'])) {
            return ['ok' => false, 'error' => $ens['error'] ?? 'Could not fetch that account.'];
        }
    } catch (Throwable $e) {
        // Avatar warm can hit SQLite locks under load — still allow featuring
        // if we at least have an actor id; card will use fallbacks.
    }

    try {
        $db = ap_db();
        $exists = $db->prepare(
            'SELECT id FROM ap_featured_accounts WHERE owner_user_id = ? AND target_actor_id = ? LIMIT 1'
        );
        $exists->execute([$ownerUserId, $actorId]);
        if ($exists->fetch()) {
            return ['ok' => false, 'error' => 'That account is already featured.'];
        }
        $maxPos = (int) ($db->query(
            'SELECT COALESCE(MAX(position), 0) FROM ap_featured_accounts WHERE owner_user_id = ' . (int) $ownerUserId
        )->fetchColumn() ?: 0);
        $insSql = 'INSERT INTO ap_featured_accounts (owner_user_id, target_actor_id, position, created_at)
             VALUES (?,?,?,?)';
        $insParams = [$ownerUserId, $actorId, $maxPos + 1, gmdate('c')];
        if (function_exists('ap_db_execute_retry')) {
            if (ap_db_execute_retry($insSql, $insParams) === false) {
                return ['ok' => false, 'error' => 'Could not save featured account (database busy — try again).'];
            }
        } else {
            $db->prepare($insSql)->execute($insParams);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save featured account.'];
    }

    try {
        $card = ap_featured_card_for_actor($actorId);
    } catch (Throwable $e) {
        $card = [
            'actor_id' => $actorId,
            'acct' => $actorId,
            'display_name' => $actorId,
            'avatar' => defined('AP_REMOTE_AVATAR_FALLBACK') ? AP_REMOTE_AVATAR_FALLBACK : 'https://mkultra.monster/img/avatar/default.webp',
            'is_local' => false,
        ];
    }
    return [
        'ok' => true,
        'notice' => 'Featured @' . $card['acct'],
        'card' => $card,
    ];
}

/**
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_featured_remove(int $ownerUserId, int $rowId): array
{
    if ($ownerUserId <= 0 || $rowId <= 0) {
        return ['ok' => false, 'error' => 'Missing featured account.'];
    }
    ap_featured_schema_ensure();
    try {
        $st = ap_db()->prepare(
            'DELETE FROM ap_featured_accounts WHERE id = ? AND owner_user_id = ?'
        );
        $st->execute([$rowId, $ownerUserId]);
        if ($st->rowCount() < 1) {
            return ['ok' => false, 'error' => 'Featured account not found.'];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not remove.'];
    }
    return ['ok' => true, 'notice' => 'Removed from Featured.'];
}

/**
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_featured_move(int $ownerUserId, int $rowId, string $dir): array
{
    $dir = $dir === 'up' ? 'up' : 'down';
    $list = ap_featured_list($ownerUserId);
    $idx = -1;
    foreach ($list as $i => $row) {
        if ((int) ($row['id'] ?? 0) === $rowId) {
            $idx = $i;
            break;
        }
    }
    if ($idx < 0) {
        return ['ok' => false, 'error' => 'Not found.'];
    }
    $swap = $dir === 'up' ? $idx - 1 : $idx + 1;
    if ($swap < 0 || $swap >= count($list)) {
        return ['ok' => true, 'notice' => 'Already at the edge.'];
    }
    try {
        $db = ap_db();
        $a = $list[$idx];
        $b = $list[$swap];
        $db->prepare('UPDATE ap_featured_accounts SET position = ? WHERE id = ? AND owner_user_id = ?')
            ->execute([(int) ($b['position'] ?? $swap), (int) $a['id'], $ownerUserId]);
        $db->prepare('UPDATE ap_featured_accounts SET position = ? WHERE id = ? AND owner_user_id = ?')
            ->execute([(int) ($a['position'] ?? $idx), (int) $b['id'], $ownerUserId]);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not reorder.'];
    }
    return ['ok' => true, 'notice' => 'Order updated.'];
}

/**
 * HTML for Featured tab cards (avatar + display name + @acct).
 *
 * @param list<array<string,mixed>> $cards
 */
function ap_featured_cards_html(array $cards): string
{
    if ($cards === []) {
        return '<p class="muted">No featured accounts yet.</p>';
    }
    $fallback = defined('AP_REMOTE_AVATAR_FALLBACK')
        ? AP_REMOTE_AVATAR_FALLBACK
        : 'https://mkultra.monster/img/avatar/default.webp';
    $fbEsc = htmlspecialchars($fallback, ENT_QUOTES, 'UTF-8');
    $html = '<ul class="featured-accounts" aria-label="Featured accounts">';
    foreach ($cards as $fc) {
        if (!is_array($fc)) {
            continue;
        }
        $actorId = rtrim((string) ($fc['actor_id'] ?? ''), '/');
        if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
            continue;
        }
        $acct = (string) ($fc['acct'] ?? '');
        $display = (string) ($fc['display_name'] ?? $acct);
        $avatar = (string) ($fc['avatar'] ?? $fallback);
        if ($avatar === '') {
            $avatar = $fallback;
        }
        $isLocal = !empty($fc['is_local']);
        $href = $actorId;
        if ($isLocal && preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $actorId, $lm)) {
            $href = '/users/' . rawurlencode(strtolower($lm[1]));
        }
        $html .= '<li class="featured-account">';
        $html .= '<a class="featured-account-link" href="'
            . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '"'
            . ($isLocal ? '' : ' rel="noopener noreferrer nofollow"')
            . '>';
        $html .= '<img class="featured-av" src="'
            . htmlspecialchars($avatar, ENT_QUOTES, 'UTF-8')
            . '" alt="" width="48" height="48" loading="lazy" referrerpolicy="no-referrer"'
            . ' onerror="this.onerror=null;this.src=\'' . $fbEsc . '\'">';
        $html .= '<span class="featured-meta">';
        $html .= '<span class="featured-name">'
            . htmlspecialchars($display, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        if ($acct !== '') {
            $html .= '<span class="featured-acct">@'
                . htmlspecialchars($acct, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>';
        }
        $html .= '</span></a></li>';
    }
    $html .= '</ul>';
    return $html;
}
