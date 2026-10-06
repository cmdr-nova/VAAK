<?php
declare(strict_types=1);

/**
 * Phyrian Strains — VAAK web game (Phase 1).
 *
 * Consent-based imprint + local resonance exchange + daily decay.
 * OpenSim ↔ VAAK Resonant bridge: ap-phyrian-bridge.php (Phase 3).
 * See Projects/NovaLandia/Phyrian Strains/Plan.md.
 */

/** Web daily decay (OpenSim uses generation-scaled ~7–8; Phase-1 web is slightly gentler). */
const AP_PHYRIAN_DAILY_DECAY = 5;
const AP_PHYRIAN_MAX_RESONANCE = 100;
const AP_PHYRIAN_IMPRINT_START_RESONANCE = 50;
/** One UTC day of grace after imprint before decay starts. */
const AP_PHYRIAN_DECAY_GRACE_SECONDS = 86400;

/**
 * OpenSim origin mirror for the VAAK seed account (val3r1e flux on strains.novalandia.online).
 * Phase-1 web seed should match this body; live bridge sync is Phase 3.
 */
const AP_PHYRIAN_ORIGIN_STRAIN = 'Phyrian';
const AP_PHYRIAN_ORIGIN_GENERATION = 3;
const AP_PHYRIAN_ORIGIN_LEVEL = 80;
const AP_PHYRIAN_ORIGIN_RESONANCE = 93;
const AP_PHYRIAN_MAX_LEVEL = 80;
const AP_PHYRIAN_MAX_BANKED = 300;
const AP_PHYRIAN_MAX_GENERATION = 10;

/**
 * Optional Rust mutation hand-off. Disabled by default so PHP remains the
 * write owner while the Axum route is being verified in production.
 *
 * A null return means the Rust worker was unavailable; callers must fall back
 * to the canonical PHP implementation. A decoded array (including an error)
 * means Rust handled the request and its result should be preserved.
 *
 * @param array<string,mixed> $payload
 * @return array<string,mixed>|null
 */
function ap_phyrian_rust_mutation(string $action, array $payload): ?array
{
    $enabled = strtolower(trim((string) (getenv('VAAK_PHYRIAN_RUST_MUTATIONS') ?: '')));
    if (!in_array($enabled, ['1', 'true', 'yes', 'on'], true)
        || !function_exists('curl_init')) {
        return null;
    }
    $token = trim((string) (getenv('VAAK_PHYRIAN_MUTATION_TOKEN') ?: ''));
    if ($token === '') {
        return null;
    }
    $body = json_encode(array_merge($payload, ['action' => $action]), JSON_UNESCAPED_SLASHES);
    if (!is_string($body)) {
        return null;
    }
    $ch = curl_init('http://127.0.0.1:8787/internal/phyrian/mutate');
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 1,
        CURLOPT_TIMEOUT => 3,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
            'X-VAAK-Internal-Token: ' . $token,
        ],
        CURLOPT_POSTFIELDS => $body,
    ]);
    $raw = curl_exec($ch);
    $http = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    // 409 is the deliberate Rust→PHP fallback signal for origin randomness
    // and other behavior that still belongs to the canonical PHP path.
    if ($http === 409) {
        return null;
    }
    if (!is_string($raw) || $raw === '' || $http < 200 || ($http >= 300 && $http !== 422)) {
        return null;
    }
    $decoded = json_decode($raw, true);
    return is_array($decoded) ? $decoded : null;
}

function ap_phyrian_migrate(?PDO $db = null): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $db ??= ap_db();
    $db->exec(
        "CREATE TABLE IF NOT EXISTS phyrian_players (
            owner_user_id INTEGER PRIMARY KEY,
            actor_id TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'unknown',
            strain TEXT,
            resonance INTEGER NOT NULL DEFAULT 0,
            generation INTEGER NOT NULL DEFAULT 1,
            level INTEGER NOT NULL DEFAULT 1,
            imprinted_by_owner_id INTEGER,
            imprinted_at TIMESTAMPTZ,
            last_checkin_at TIMESTAMPTZ,
            last_decay_at TIMESTAMPTZ,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            updated_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
        )"
    );
    $db->exec(
        "CREATE TABLE IF NOT EXISTS phyrian_requests (
            id BIGSERIAL PRIMARY KEY,
            kind TEXT NOT NULL,
            from_owner_id INTEGER NOT NULL,
            to_owner_id INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            payload_json TEXT,
            created_at TIMESTAMPTZ NOT NULL DEFAULT NOW(),
            resolved_at TIMESTAMPTZ
        )"
    );
    $db->exec(
        'CREATE INDEX IF NOT EXISTS phyrian_requests_to_pending_idx
         ON phyrian_requests (to_owner_id, status, created_at DESC)'
    );
    // OpenSim-parity counters for the dossier readout (safe if already present).
    foreach ([
        'banked_resonance INTEGER NOT NULL DEFAULT 0',
        'resonance_exchanges INTEGER NOT NULL DEFAULT 0',
        'inductions_given INTEGER NOT NULL DEFAULT 0',
        'lineage_depth INTEGER',
        'daily_resonance_decay INTEGER NOT NULL DEFAULT 5',
    ] as $colDef) {
        try {
            $db->exec('ALTER TABLE phyrian_players ADD COLUMN IF NOT EXISTS ' . $colDef);
        } catch (Throwable $e) {
            // Older PG without IF NOT EXISTS — ignore duplicate_column.
            try {
                $db->exec('ALTER TABLE phyrian_players ADD COLUMN ' . $colDef);
            } catch (Throwable $e2) {
                // Column already exists.
            }
        }
    }
    $done = true;
}

/**
 * OpenSim ORIGIN_RANDOM_STRAINS catalog (cosmetic labels).
 *
 * @return list<string>
 */
function ap_phyrian_strain_catalog(): array
{
    return [
        'Cosmic Alien', 'Voidborne', 'Signal Choir', 'Astral Parasite', 'Eventide Spore',
        'Starless Brood', 'Null Communion', 'Blacklight Kin', 'Quasar Wound', 'Eclipse Vessel',
        'Deep Signal',
        'Bio-Horror Alien', 'Chitin Bloom', 'Marrow Signal', 'Vessel Rot', 'Bone Orchid',
        'Spine Choir', 'Flesh Static', 'Suture Bloom', 'Moltborn', 'Cartilage Saint',
        'Hemolymph Crown',
        'Symbiotic Alien', 'Lumen Host', 'Soft Colony', 'Rootmind', 'Amber Symbiote',
        'Velvet Mycelium', 'Twin Pulse', 'Murmur Host', 'Kindred Spore', 'Halo Larva',
        'Second Skin',
        'Synthetic Alien', 'Nanite Choir', 'Glass Protocol', 'Machine Spore', 'Chrome Mycelium',
        'Static Engine', 'Signal Lattice', 'Nullware Host', 'Prism Circuit', 'Ghost Firmware',
        'Iron Dream',
        'Post-Human Mutant', 'Ash Gene', 'Static Flesh', 'Chrome Wound', 'Afterbody',
        'Morrow Gene', 'Splice Saint', 'Grey Bloom', 'Hollow Kin', 'Burnt Genome', 'Neon Marrow',
        'Phyrian', 'Red Tower Echo', 'Obsidian Root', 'Rose Static', 'Violet Drift',
        'Cinder Halo', 'Witchlight Signal', 'Moonless Colony', 'Grave Neon', 'Sable Current',
    ];
}

function ap_phyrian_is_origin_owner(int $ownerUserId): bool
{
    // Mid-Phase-1 default: operator account id 1 (cmdr_nova) seeds origin imprints.
    $configured = (int) (getenv('VAAK_PHYRIAN_ORIGIN_OWNER_ID') ?: 0);
    if ($configured > 0) {
        return $ownerUserId === $configured;
    }
    return $ownerUserId === 1;
}

function ap_phyrian_player_is_imprinted(array $player): bool
{
    return (string) ($player['status'] ?? '') === 'imprinted'
        && trim((string) ($player['strain'] ?? '')) !== '';
}

/** Stability label for HUD (DB status stays unknown/imprinted). */
function ap_phyrian_stability(array $player): string
{
    if (!ap_phyrian_player_is_imprinted($player)) {
        return 'Unmarked';
    }
    $r = (int) ($player['resonance'] ?? 0);
    if ($r <= 0) {
        return 'Dormant';
    }
    if ($r < 25) {
        return 'Critical';
    }
    if ($r < 50) {
        return 'Fading';
    }
    return 'Stable';
}

function ap_phyrian_pick_strain(): string
{
    $catalog = ap_phyrian_strain_catalog();
    if ($catalog === []) {
        return 'Phyrian';
    }
    return $catalog[random_int(0, count($catalog) - 1)] ?? 'Phyrian';
}

/**
 * @return array<string,mixed>
 */
function ap_phyrian_ensure_player(int $ownerUserId, string $actorId): array
{
    ap_phyrian_migrate();
    $ownerUserId = max(0, $ownerUserId);
    $actorId = rtrim(trim($actorId), '/');
    if ($ownerUserId < 1 || $actorId === '') {
        return [];
    }
    $db = ap_db();
    $st = $db->prepare('SELECT * FROM phyrian_players WHERE owner_user_id = ? LIMIT 1');
    $st->execute([$ownerUserId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (is_array($row)) {
        return ap_phyrian_apply_decay_row($row);
    }
    $ins = $db->prepare(
        "INSERT INTO phyrian_players (owner_user_id, actor_id, status, resonance, generation, level)
         VALUES (?, ?, 'unknown', 0, 1, 1)
         ON CONFLICT (owner_user_id) DO NOTHING
         RETURNING *"
    );
    $ins->execute([$ownerUserId, $actorId]);
    $created = $ins->fetch(PDO::FETCH_ASSOC);
    if (is_array($created)) {
        return $created;
    }
    $st->execute([$ownerUserId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? ap_phyrian_apply_decay_row($row) : [];
}

/**
 * Lazy daily resonance decay (OpenSim-shaped: grace after imprint, then −N/day).
 * Keeps DB status as imprinted; UI uses {@see ap_phyrian_stability()}.
 *
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function ap_phyrian_apply_decay_row(array $row): array
{
    if (!ap_phyrian_player_is_imprinted($row)) {
        return $row;
    }
    // Linked Resonant bodies decay on OpenSim; VAAK mirrors via bridge sync.
    $ownerId = (int) ($row['owner_user_id'] ?? 0);
    if ($ownerId > 0 && function_exists('ap_phyrian_bridge_is_linked') && ap_phyrian_bridge_is_linked($ownerId)) {
        return $row;
    }
    $imprintedAt = (string) ($row['imprinted_at'] ?? '');
    $anchor = $imprintedAt !== '' ? strtotime($imprintedAt) : false;
    if ($anchor === false) {
        $created = (string) ($row['created_at'] ?? '');
        $anchor = $created !== '' ? strtotime($created) : false;
    }
    if ($anchor === false) {
        return $row;
    }
    $now = time();
    $graceEnds = $anchor + AP_PHYRIAN_DECAY_GRACE_SECONDS;
    if ($now < $graceEnds) {
        return $row;
    }
    $lastDecayRaw = (string) ($row['last_decay_at'] ?? '');
    $lastDecay = $lastDecayRaw !== '' ? strtotime($lastDecayRaw) : false;
    if ($lastDecay === false) {
        $lastDecay = $graceEnds;
    }
    $lastDecay = max($lastDecay, $graceEnds);
    $days = (int) floor(($now - $lastDecay) / 86400);
    if ($days < 1) {
        return $row;
    }
    $old = (int) ($row['resonance'] ?? 0);
    $new = max(0, $old - ($days * AP_PHYRIAN_DAILY_DECAY));
    $newLast = gmdate('c', $lastDecay + ($days * 86400));
    try {
        ap_db()->prepare(
            'UPDATE phyrian_players
             SET resonance = ?, last_decay_at = ?, updated_at = NOW()
             WHERE owner_user_id = ?'
        )->execute([$new, $newLast, (int) ($row['owner_user_id'] ?? 0)]);
    } catch (Throwable $e) {
        return $row;
    }
    $row['resonance'] = $new;
    $row['last_decay_at'] = $newLast;
    return $row;
}

/**
 * Batch decay for cron. Returns counts.
 *
 * @return array{scanned:int,changed:int}
 */
function ap_phyrian_decay_all(int $limit = 500): array
{
    ap_phyrian_migrate();
    $limit = max(1, min(2000, $limit));
    $scanned = 0;
    $changed = 0;
    try {
        $st = ap_db()->query(
            "SELECT * FROM phyrian_players
             WHERE status = 'imprinted' AND strain IS NOT NULL AND strain <> ''
             ORDER BY owner_user_id ASC
             LIMIT " . (int) $limit
        );
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $scanned++;
            $before = (int) ($row['resonance'] ?? 0);
            $after = ap_phyrian_apply_decay_row($row);
            if ((int) ($after['resonance'] ?? 0) !== $before) {
                $changed++;
            }
        }
    } catch (Throwable $e) {
        // cron should not throw
    }
    return ['scanned' => $scanned, 'changed' => $changed];
}

function ap_phyrian_username_for_owner(int $ownerUserId): string
{
    if ($ownerUserId < 1) {
        return '';
    }
    try {
        $st = ap_db()->prepare(
            'SELECT COALESCE(NULLIF(username, \'\'), NULLIF(actor_key, \'\'), \'\')
             FROM ap_users WHERE id = ? LIMIT 1'
        );
        $st->execute([$ownerUserId]);
        return trim((string) ($st->fetchColumn() ?: ''));
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * Walk imprinted_by chain (child → parent → …).
 *
 * @return list<array{owner_user_id:int,username:string,strain:string,generation:int,is_self:bool}>
 */
function ap_phyrian_lineage(int $ownerUserId, int $maxHops = 4): array
{
    ap_phyrian_migrate();
    $maxHops = max(1, min(8, $maxHops));
    $out = [];
    $seen = [];
    $current = $ownerUserId;
    for ($i = 0; $i < $maxHops && $current > 0; $i++) {
        if (isset($seen[$current])) {
            break;
        }
        $seen[$current] = true;
        try {
            $st = ap_db()->prepare(
                'SELECT owner_user_id, strain, generation, imprinted_by_owner_id, status
                 FROM phyrian_players WHERE owner_user_id = ? LIMIT 1'
            );
            $st->execute([$current]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            break;
        }
        if (!is_array($row) || !ap_phyrian_player_is_imprinted($row)) {
            break;
        }
        $out[] = [
            'owner_user_id' => (int) ($row['owner_user_id'] ?? 0),
            'username' => ap_phyrian_username_for_owner((int) ($row['owner_user_id'] ?? 0)),
            'strain' => trim((string) ($row['strain'] ?? '')),
            'generation' => (int) ($row['generation'] ?? 1),
            'is_self' => $i === 0,
        ];
        $parent = (int) ($row['imprinted_by_owner_id'] ?? 0);
        if ($parent < 1 || $parent === $current) {
            break;
        }
        $current = $parent;
    }
    return $out;
}

/**
 * Origin-only: imprint with the OpenSim-mirrored origin body (no peer required).
 * Re-running syncs strain/generation/level/resonance to the OpenSim canon values
 * so a bad random seed (Soft Colony, etc.) can be corrected.
 *
 * @return array{ok:bool,error?:string,strain?:string,resonance?:int,generation?:int,level?:int}
 */
function ap_phyrian_origin_self_seed(int $ownerUserId): array
{
    ap_phyrian_migrate();
    if (!ap_phyrian_is_origin_owner($ownerUserId)) {
        return ['ok' => false, 'error' => 'Only the origin can self-seed'];
    }
    $player = ap_phyrian_ensure_player($ownerUserId, ap_phyrian_actor_id_for_owner($ownerUserId));
    if ($player === []) {
        return ['ok' => false, 'error' => 'Player unavailable'];
    }
    $strain = AP_PHYRIAN_ORIGIN_STRAIN;
    $generation = AP_PHYRIAN_ORIGIN_GENERATION;
    $level = AP_PHYRIAN_ORIGIN_LEVEL;
    $resonance = AP_PHYRIAN_ORIGIN_RESONANCE;
    $already = ap_phyrian_player_is_imprinted($player);
    try {
        // Preserve imprinted_at / last_checkin when re-syncing an existing origin body.
        if ($already) {
            ap_db()->prepare(
                "UPDATE phyrian_players
                 SET status = 'imprinted', strain = ?,
                     resonance = ?,
                     generation = ?,
                     level = ?,
                     imprinted_by_owner_id = NULL,
                     updated_at = NOW()
                 WHERE owner_user_id = ?"
            )->execute([$strain, $resonance, $generation, $level, $ownerUserId]);
        } else {
            ap_db()->prepare(
                "UPDATE phyrian_players
                 SET status = 'imprinted', strain = ?,
                     resonance = ?,
                     generation = ?,
                     level = ?,
                     imprinted_by_owner_id = NULL,
                     imprinted_at = NOW(),
                     last_decay_at = NOW(),
                     updated_at = NOW()
                 WHERE owner_user_id = ?"
            )->execute([$strain, $resonance, $generation, $level, $ownerUserId]);
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not seed origin'];
    }
    return [
        'ok' => true,
        'strain' => $strain,
        'resonance' => $resonance,
        'generation' => $generation,
        'level' => $level,
    ];
}

/**
 * @return list<array<string,mixed>>
 */
function ap_phyrian_pending_for(int $ownerUserId): array
{
    ap_phyrian_migrate();
    try {
        $st = ap_db()->prepare(
            "SELECT r.*, u.username AS from_username, u.actor_key AS from_actor_key
             FROM phyrian_requests r
             LEFT JOIN ap_users u ON u.id = r.from_owner_id
             WHERE r.to_owner_id = ? AND r.status = 'pending'
             ORDER BY r.created_at DESC
             LIMIT 40"
        );
        $st->execute([$ownerUserId]);
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Incoming imprint/resonance offers waiting on this owner (nav badge). */
function ap_phyrian_pending_count(int $ownerUserId): int
{
    if ($ownerUserId < 1) {
        return 0;
    }
    ap_phyrian_migrate();
    try {
        $st = ap_db()->prepare(
            "SELECT COUNT(*) FROM phyrian_requests
             WHERE to_owner_id = ? AND status = 'pending'"
        );
        $st->execute([$ownerUserId]);
        return max(0, (int) $st->fetchColumn());
    } catch (Throwable $e) {
        return 0;
    }
}

/**
 * @return array{ok:bool,error?:string,id?:int}
 */
function ap_phyrian_request_create(int $fromOwnerId, int $toOwnerId, string $kind): array
{
    ap_phyrian_migrate();
    $kind = strtolower(trim($kind));
    if (!in_array($kind, ['imprint', 'resonance'], true)) {
        return ['ok' => false, 'error' => 'Unknown request kind'];
    }
    if ($fromOwnerId < 1 || $toOwnerId < 1 || $fromOwnerId === $toOwnerId) {
        return ['ok' => false, 'error' => 'Invalid players'];
    }
    // Phyrian requests stay on-instance — never notify remote/federated actors.
    foreach ([$fromOwnerId, $toOwnerId] as $localOwnerId) {
        $actorId = ap_phyrian_actor_id_for_owner($localOwnerId);
        if ($actorId === '' || !str_starts_with($actorId, 'https://mkultra.monster/users/')) {
            return ['ok' => false, 'error' => 'Phyrian requests are local-only'];
        }
    }
    $rust = ap_phyrian_rust_mutation('create', [
        'from_owner_id' => $fromOwnerId,
        'to_owner_id' => $toOwnerId,
        'kind' => $kind,
    ]);
    if (is_array($rust)) {
        if (!empty($rust['ok'])) {
            if (function_exists('ap_masto_notifications_unread_invalidate')) {
                ap_masto_notifications_unread_invalidate($toOwnerId);
            }
            return ['ok' => true, 'id' => (int) ($rust['id'] ?? 0)];
        }
        return ['ok' => false, 'error' => (string) ($rust['error'] ?? 'Could not create request')];
    }
    $from = ap_phyrian_ensure_player($fromOwnerId, ap_phyrian_actor_id_for_owner($fromOwnerId));
    $to = ap_phyrian_ensure_player($toOwnerId, ap_phyrian_actor_id_for_owner($toOwnerId));
    if ($from === [] || $to === []) {
        return ['ok' => false, 'error' => 'Players unavailable'];
    }
    if ($kind === 'imprint') {
        $fromImprinted = ap_phyrian_player_is_imprinted($from);
        if (!$fromImprinted && !ap_phyrian_is_origin_owner($fromOwnerId)) {
            return ['ok' => false, 'error' => 'Only imprinted players (or the origin) can offer imprint'];
        }
        if (ap_phyrian_player_is_imprinted($to)) {
            return ['ok' => false, 'error' => 'They already have a strain'];
        }
    } else {
        foreach ([$from, $to] as $p) {
            if (!ap_phyrian_player_is_imprinted($p)) {
                return ['ok' => false, 'error' => 'Both players must be imprinted to exchange resonance'];
            }
        }
    }
    try {
        $dup = ap_db()->prepare(
            "SELECT id FROM phyrian_requests
             WHERE from_owner_id = ? AND to_owner_id = ? AND kind = ? AND status = 'pending'
             LIMIT 1"
        );
        $dup->execute([$fromOwnerId, $toOwnerId, $kind]);
        if ($dup->fetchColumn()) {
            return ['ok' => false, 'error' => 'Request already pending'];
        }
        $ins = ap_db()->prepare(
            "INSERT INTO phyrian_requests (kind, from_owner_id, to_owner_id, status)
             VALUES (?, ?, ?, 'pending') RETURNING id"
        );
        $ins->execute([$kind, $fromOwnerId, $toOwnerId]);
        $id = (int) $ins->fetchColumn();
        if (function_exists('ap_masto_notifications_unread_invalidate')) {
            ap_masto_notifications_unread_invalidate($toOwnerId);
        }
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create request'];
    }
}

/**
 * Pending imprint/resonance offers as Mentions-shaped notification entities.
 *
 * @return list<array<string,mixed>>
 */
function ap_phyrian_notification_entities(int $ownerUserId): array
{
    if ($ownerUserId < 1) {
        return [];
    }
    $pending = ap_phyrian_pending_for($ownerUserId);
    if ($pending === []) {
        return [];
    }
    $out = [];
    foreach ($pending as $row) {
        if (!is_array($row)) {
            continue;
        }
        $reqId = (int) ($row['id'] ?? 0);
        $fromId = (int) ($row['from_owner_id'] ?? 0);
        $kind = strtolower(trim((string) ($row['kind'] ?? '')));
        if ($reqId < 1 || $fromId < 1 || !in_array($kind, ['imprint', 'resonance'], true)) {
            continue;
        }
        $account = null;
        try {
            $ust = ap_db()->prepare(
                'SELECT id, actor_key, username, actor_id FROM ap_users
                 WHERE id = ? AND disabled_at IS NULL LIMIT 1'
            );
            $ust->execute([$fromId]);
            $user = $ust->fetch(PDO::FETCH_ASSOC);
            if (is_array($user) && function_exists('ap_masto_account_from_user')) {
                $account = ap_masto_account_from_user($user);
            }
        } catch (Throwable $e) {
            $account = null;
        }
        if (!is_array($account)) {
            $uname = (string) ($row['from_username'] ?? $row['from_actor_key'] ?? ('user' . $fromId));
            $actorKey = (string) ($row['from_actor_key'] ?? $uname);
            $actorId = 'https://mkultra.monster/users/' . preg_replace('/[^a-z0-9_]/', '', strtolower($actorKey));
            $account = [
                'id' => (string) $fromId,
                'username' => $uname,
                'acct' => $uname,
                'display_name' => $uname,
                'url' => $actorId,
                'uri' => $actorId,
                'avatar' => '',
                'avatar_static' => '',
            ];
        }
        $created = (string) ($row['created_at'] ?? '');
        $createdAt = $created;
        if ($created !== '' && function_exists('ap_masto_format_time')) {
            $createdAt = ap_masto_format_time($created);
        } elseif ($created !== '' && str_contains($created, ' ') && !str_contains($created, 'T')) {
            $createdAt = str_replace(' ', 'T', $created) . 'Z';
        }
        $out[] = [
            'id' => 'phyrian' . $reqId,
            'type' => $kind === 'imprint' ? 'phyrian_imprint' : 'phyrian_resonance',
            'group_key' => 'phyrian-' . $reqId,
            'created_at' => $createdAt,
            'account' => $account,
            'status' => null,
            'phyrian_request_id' => $reqId,
            'phyrian_kind' => $kind,
        ];
    }
    return $out;
}

function ap_phyrian_actor_id_for_owner(int $ownerUserId): string
{
    try {
        $st = ap_db()->prepare('SELECT actor_id FROM ap_users WHERE id = ? AND disabled_at IS NULL LIMIT 1');
        $st->execute([$ownerUserId]);
        return rtrim((string) ($st->fetchColumn() ?: ''), '/');
    } catch (Throwable $e) {
        return '';
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_phyrian_request_resolve(int $ownerUserId, int $requestId, bool $accept): array
{
    ap_phyrian_migrate();
    if ($ownerUserId < 1 || $requestId < 1) {
        return ['ok' => false, 'error' => 'Invalid request'];
    }
    $rust = ap_phyrian_rust_mutation('resolve', [
        'owner_id' => $ownerUserId,
        'request_id' => $requestId,
        'accept' => $accept,
    ]);
    if (is_array($rust)) {
        if (!empty($rust['ok'])) {
            if (function_exists('ap_masto_notifications_unread_invalidate')) {
                ap_masto_notifications_unread_invalidate($ownerUserId);
            }
            $out = ['ok' => true];
            if (array_key_exists('strain', $rust) && is_string($rust['strain'])) {
                $out['strain'] = $rust['strain'];
            }
            return $out;
        }
        return ['ok' => false, 'error' => (string) ($rust['error'] ?? 'Could not resolve request')];
    }
    $db = ap_db();
    $st = $db->prepare(
        "SELECT * FROM phyrian_requests WHERE id = ? AND to_owner_id = ? AND status = 'pending' LIMIT 1"
    );
    $st->execute([$requestId, $ownerUserId]);
    $req = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($req)) {
        return ['ok' => false, 'error' => 'Request not found'];
    }
    $kind = (string) ($req['kind'] ?? '');
    $fromId = (int) ($req['from_owner_id'] ?? 0);
    if ($accept && $kind === 'resonance'
        && function_exists('ap_phyrian_bridge_is_linked')
        && (ap_phyrian_bridge_is_linked($ownerUserId) || ap_phyrian_bridge_is_linked($fromId))) {
        return ['ok' => false, 'error' => 'Linked OpenSim bodies must exchange resonance in OpenSim'];
    }
    if (!$accept) {
        $db->prepare(
            "UPDATE phyrian_requests SET status = 'denied', resolved_at = NOW() WHERE id = ?"
        )->execute([$requestId]);
        if (function_exists('ap_masto_notifications_unread_invalidate')) {
            ap_masto_notifications_unread_invalidate($ownerUserId);
        }
        return ['ok' => true];
    }

    $assignedStrain = null;
    $originRandom = false;
    if ($kind === 'imprint') {
        $from = ap_phyrian_ensure_player($fromId, ap_phyrian_actor_id_for_owner($fromId));
        $to = ap_phyrian_ensure_player($ownerUserId, ap_phyrian_actor_id_for_owner($ownerUserId));
        if ($from === [] || $to === []) {
            return ['ok' => false, 'error' => 'Players unavailable'];
        }
        // Origin unmarked → seed own Phyrian body first (OpenSim mirror).
        // Recipient strain is assigned separately below.
        $fromIsOrigin = ap_phyrian_is_origin_owner($fromId);
        if ($fromIsOrigin && !ap_phyrian_player_is_imprinted($from)) {
            $seed = ap_phyrian_origin_self_seed($fromId);
            if (empty($seed['ok'])) {
                return ['ok' => false, 'error' => (string) ($seed['error'] ?? 'Origin seed failed')];
            }
            $from = ap_phyrian_ensure_player($fromId, ap_phyrian_actor_id_for_owner($fromId));
        }
        // OpenSim rule: origin imprint → random catalog strain; peer → transmit own.
        if ($fromIsOrigin) {
            $strain = ap_phyrian_pick_strain();
            $originRandom = true;
        } else {
            $strain = trim((string) ($from['strain'] ?? ''));
        }
        if ($strain === '') {
            return ['ok' => false, 'error' => 'Imprinter has no strain'];
        }
        $assignedStrain = $strain;
        $parentGen = max(1, (int) ($from['generation'] ?? 1));
        $childGen = min(99, $parentGen + 1);
        $db->prepare(
            "UPDATE phyrian_players
             SET status = 'imprinted', strain = ?,
                 resonance = GREATEST(resonance, ?),
                 generation = ?,
                 imprinted_by_owner_id = ?,
                 imprinted_at = NOW(),
                 last_decay_at = NOW(),
                 updated_at = NOW()
             WHERE owner_user_id = ?"
        )->execute([
            $strain,
            AP_PHYRIAN_IMPRINT_START_RESONANCE,
            $childGen,
            $fromId,
            $ownerUserId,
        ]);
        try {
            $db->prepare(
                'UPDATE phyrian_players
                 SET inductions_given = inductions_given + 1, updated_at = NOW()
                 WHERE owner_user_id = ?'
            )->execute([$fromId]);
        } catch (Throwable $e) {
            // Counter column may be mid-migrate on a hot path.
        }
    } elseif ($kind === 'resonance') {
        // Exchange fights decay: bump resonance and refresh last_decay_at.
        $db->prepare(
            'UPDATE phyrian_players
             SET resonance = LEAST(?, resonance + 5),
                 last_decay_at = NOW(),
                 updated_at = NOW()
             WHERE owner_user_id IN (?, ?)'
        )->execute([AP_PHYRIAN_MAX_RESONANCE, $fromId, $ownerUserId]);
        try {
            $db->prepare(
                'UPDATE phyrian_players
                 SET resonance_exchanges = resonance_exchanges + 1, updated_at = NOW()
                 WHERE owner_user_id IN (?, ?)'
            )->execute([$fromId, $ownerUserId]);
        } catch (Throwable $e) {
            // Counter column may be mid-migrate.
        }
    } else {
        return ['ok' => false, 'error' => 'Unknown kind'];
    }

    $db->prepare(
        "UPDATE phyrian_requests SET status = 'accepted', resolved_at = NOW() WHERE id = ?"
    )->execute([$requestId]);
    if (function_exists('ap_masto_notifications_unread_invalidate')) {
        ap_masto_notifications_unread_invalidate($ownerUserId);
    }
    $out = ['ok' => true];
    if ($assignedStrain !== null) {
        $out['strain'] = $assignedStrain;
        $out['origin_random'] = $originRandom;
    }
    return $out;
}

/**
 * @return list<array{id:int,username:string,actor_key:string,status:string,strain:?string,resonance:int,generation:int,stability:string}>
 */
function ap_phyrian_local_directory(int $viewerOwnerId, int $limit = 40, int $offset = 0, string $search = ''): array
{
    ap_phyrian_migrate();
    $limit = max(1, min(80, $limit));
    $offset = max(0, $offset);
    $search = trim($search);
    try {
        $whereSearch = '';
        $params = [$viewerOwnerId];
        if ($search !== '') {
            $whereSearch = ' AND (u.username ILIKE ? OR u.actor_key ILIKE ?)';
            $needle = '%' . $search . '%';
            $params[] = $needle;
            $params[] = $needle;
        }
        $params[] = $limit;
        $params[] = $offset;
        $st = ap_db()->prepare(
            "SELECT u.id, u.username, u.actor_key, u.actor_id,
                    COALESCE(p.status, 'unknown') AS status,
                    p.strain,
                    COALESCE(p.resonance, 0) AS resonance,
                    COALESCE(p.generation, 1) AS generation,
                    p.imprinted_at, p.last_decay_at, p.created_at
             FROM ap_users u
             LEFT JOIN phyrian_players p ON p.owner_user_id = u.id
             WHERE u.disabled_at IS NULL AND u.id <> ?
             {$whereSearch}
             ORDER BY lower(u.username) ASC
             LIMIT ? OFFSET ?"
        );
        $st->execute($params);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            // Lazy decay so directory resonance stays honest.
            if (ap_phyrian_player_is_imprinted($row)) {
                $row['owner_user_id'] = (int) ($row['id'] ?? 0);
                $row = ap_phyrian_apply_decay_row($row);
            }
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'username' => (string) ($row['username'] ?? $row['actor_key'] ?? ''),
                'actor_key' => (string) ($row['actor_key'] ?? ''),
                'status' => (string) ($row['status'] ?? 'unknown'),
                'strain' => isset($row['strain']) ? (string) $row['strain'] : null,
                'resonance' => (int) ($row['resonance'] ?? 0),
                'generation' => (int) ($row['generation'] ?? 1),
                'stability' => ap_phyrian_stability($row),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Daily check-in: +resonance once per UTC day when imprinted.
 *
 * @return array{ok:bool,error?:string,resonance?:int}
 */
function ap_phyrian_checkin(int $ownerUserId): array
{
    ap_phyrian_migrate();
    // Linked bodies: OpenSim monolith is the daily claim; VAAK mirrors the result.
    if (function_exists('ap_phyrian_bridge_is_linked') && ap_phyrian_bridge_is_linked($ownerUserId)
        && function_exists('ap_phyrian_bridge_checkin_via_opensim')) {
        return ap_phyrian_bridge_checkin_via_opensim($ownerUserId);
    }
    $player = ap_phyrian_ensure_player($ownerUserId, ap_phyrian_actor_id_for_owner($ownerUserId));
    if ($player === [] || !ap_phyrian_player_is_imprinted($player)) {
        return ['ok' => false, 'error' => 'Imprint first'];
    }
    $last = (string) ($player['last_checkin_at'] ?? '');
    if ($last !== '' && substr($last, 0, 10) === gmdate('Y-m-d')) {
        return ['ok' => false, 'error' => 'Already checked in today'];
    }
    $bonus = 3;
    ap_db()->prepare(
        'UPDATE phyrian_players
         SET resonance = LEAST(?, resonance + ?),
             last_checkin_at = NOW(),
             last_decay_at = NOW(),
             updated_at = NOW()
         WHERE owner_user_id = ?'
    )->execute([AP_PHYRIAN_MAX_RESONANCE, $bonus, $ownerUserId]);
    $st = ap_db()->prepare('SELECT resonance FROM phyrian_players WHERE owner_user_id = ?');
    $st->execute([$ownerUserId]);
    return ['ok' => true, 'resonance' => (int) $st->fetchColumn()];
}

/** Rank label for HUD / dossier (OpenSim-shaped; cosmetic in Phase 1). */
function ap_phyrian_rank_title(array $player, bool $isOrigin = false): string
{
    if ($isOrigin && ap_phyrian_player_is_imprinted($player)) {
        return 'Phyrian Origin';
    }
    if (!ap_phyrian_player_is_imprinted($player)) {
        return 'None';
    }
    $level = (int) ($player['level'] ?? 1);
    if ($level >= 60) {
        return 'Deep Signal';
    }
    if ($level >= 40) {
        return 'Marked Host';
    }
    if ($level >= 20) {
        return 'Colony Node';
    }
    return 'Newly Marked';
}

/**
 * OpenSim-style public readout for one VAAK owner.
 *
 * @return array<string,mixed>|null
 */
function ap_phyrian_dossier(int $ownerUserId): ?array
{
    if ($ownerUserId < 1) {
        return null;
    }
    ap_phyrian_migrate();
    $actorId = ap_phyrian_actor_id_for_owner($ownerUserId);
    if (function_exists('ap_phyrian_bridge_sync_from_opensim')
        && (!function_exists('ap_phyrian_bridge_sync_on_page') || ap_phyrian_bridge_sync_on_page())) {
        ap_phyrian_bridge_sync_from_opensim($ownerUserId, false);
    }
    $player = ap_phyrian_ensure_player($ownerUserId, $actorId);
    if ($player === []) {
        return null;
    }
    if (ap_phyrian_player_is_imprinted($player)) {
        $player = ap_phyrian_apply_decay_row($player);
    }
    $isOrigin = ap_phyrian_is_origin_owner($ownerUserId);
    $imprinted = ap_phyrian_player_is_imprinted($player);
    $linked = function_exists('ap_phyrian_bridge_is_linked') && ap_phyrian_bridge_is_linked($ownerUserId);
    $username = ap_phyrian_username_for_owner($ownerUserId);
    if ($username === '') {
        $username = 'user' . $ownerUserId;
    }
    $parentId = (int) ($player['imprinted_by_owner_id'] ?? 0);
    $parentName = $parentId > 0 ? ap_phyrian_username_for_owner($parentId) : '';
    $lineage = $imprinted ? ap_phyrian_lineage($ownerUserId, 8) : [];
    $storedDepth = (int) ($player['lineage_depth'] ?? 0);
    $lineageDepth = $storedDepth > 0 ? $storedDepth : max(1, count($lineage));
    return [
        'owner_user_id' => $ownerUserId,
        'username' => $username,
        'actor_id' => $actorId,
        'status' => $imprinted ? ap_phyrian_stability($player) : 'Unmarked',
        'strain' => $imprinted ? trim((string) ($player['strain'] ?? '')) : '',
        'generation' => $imprinted ? (int) ($player['generation'] ?? 1) : null,
        'max_generation' => AP_PHYRIAN_MAX_GENERATION,
        'lineage_depth' => $imprinted ? $lineageDepth : null,
        'opensim_synced' => $linked,
        'level' => (int) ($player['level'] ?? 1),
        'max_level' => AP_PHYRIAN_MAX_LEVEL,
        'rank_title' => ap_phyrian_rank_title($player, $isOrigin),
        'resonance' => (int) ($player['resonance'] ?? 0),
        'max_resonance' => AP_PHYRIAN_MAX_RESONANCE,
        'banked_resonance' => (int) ($player['banked_resonance'] ?? 0),
        'max_banked_resonance' => AP_PHYRIAN_MAX_BANKED,
        // Linked OpenSim bodies carry the authoritative generation-scaled
        // decay; unlinked VAAK bodies retain the local Phase-1 default.
        'daily_resonance_decay' => max(0, (int) ($player['daily_resonance_decay'] ?? AP_PHYRIAN_DAILY_DECAY)),
        'resonance_exchanges' => (int) ($player['resonance_exchanges'] ?? 0),
        'inductions_given' => (int) ($player['inductions_given'] ?? 0),
        'stability' => ap_phyrian_stability($player),
        'last_decay_at' => (string) ($player['last_decay_at'] ?? ''),
        'last_checkin_at' => (string) ($player['last_checkin_at'] ?? ''),
        'imprinted_at' => (string) ($player['imprinted_at'] ?? ''),
        'imprinted_by_owner_id' => $parentId > 0 ? $parentId : null,
        'imprinted_by_username' => $parentName !== '' ? $parentName : null,
        'is_origin' => $isOrigin,
        'lineage' => $lineage,
    ];
}
