<?php
declare(strict_types=1);

/**
 * Phyrian Strains — VAAK web game (Phase 1 scaffold).
 *
 * Consent-based imprint + local resonance exchange. OpenSim bridge is Phase 3.
 * See Projects/NovaLandia/Phyrian Strains/Plan.md.
 */

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
    $done = true;
}

/** @return list<string> */
function ap_phyrian_strain_catalog(): array
{
    return [
        'Cosmic Alien', 'Voidborne', 'Signal Choir', 'Astral Parasite', 'Eventide Spore',
        'Bio-Horror Alien', 'Chitin Bloom', 'Marrow Signal', 'Vessel Rot', 'Bone Orchid',
        'Symbiotic Alien', 'Lumen Host', 'Soft Colony', 'Rootmind', 'Amber Symbiote',
        'Synthetic Alien', 'Chrome Chorus', 'Nullframe', 'Circuit Bloom', 'Static Saint',
        'Post-Human Alien', 'Echo Kin', 'Second Dawn', 'Remnant Pulse', 'Phyrian',
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
        return $row;
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
    return is_array($row) ? $row : [];
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
    $from = ap_phyrian_ensure_player($fromOwnerId, ap_phyrian_actor_id_for_owner($fromOwnerId));
    $to = ap_phyrian_ensure_player($toOwnerId, ap_phyrian_actor_id_for_owner($toOwnerId));
    if ($from === [] || $to === []) {
        return ['ok' => false, 'error' => 'Players unavailable'];
    }
    if ($kind === 'imprint') {
        $fromImprinted = (string) ($from['status'] ?? '') === 'imprinted'
            || trim((string) ($from['strain'] ?? '')) !== '';
        if (!$fromImprinted && !ap_phyrian_is_origin_owner($fromOwnerId)) {
            return ['ok' => false, 'error' => 'Only imprinted players (or the origin) can offer imprint'];
        }
        if ((string) ($to['status'] ?? '') === 'imprinted' && trim((string) ($to['strain'] ?? '')) !== '') {
            return ['ok' => false, 'error' => 'They already have a strain'];
        }
    } else {
        // Resonance: both must be imprinted.
        foreach ([$from, $to] as $p) {
            if ((string) ($p['status'] ?? '') !== 'imprinted' || trim((string) ($p['strain'] ?? '')) === '') {
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
        return ['ok' => true, 'id' => $id];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not create request'];
    }
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
    if (!$accept) {
        $db->prepare(
            "UPDATE phyrian_requests SET status = 'denied', resolved_at = NOW() WHERE id = ?"
        )->execute([$requestId]);
        return ['ok' => true];
    }

    if ($kind === 'imprint') {
        $from = ap_phyrian_ensure_player($fromId, ap_phyrian_actor_id_for_owner($fromId));
        $to = ap_phyrian_ensure_player($ownerUserId, ap_phyrian_actor_id_for_owner($ownerUserId));
        $catalog = ap_phyrian_strain_catalog();
        $strain = trim((string) ($from['strain'] ?? ''));
        if ($strain === '' && ap_phyrian_is_origin_owner($fromId)) {
            $strain = $catalog[random_int(0, max(0, count($catalog) - 1))] ?? 'Phyrian';
        }
        if ($strain === '') {
            return ['ok' => false, 'error' => 'Imprinter has no strain'];
        }
        $db->prepare(
            "UPDATE phyrian_players
             SET status = 'imprinted', strain = ?, resonance = GREATEST(resonance, 50),
                 imprinted_by_owner_id = ?, imprinted_at = NOW(), updated_at = NOW()
             WHERE owner_user_id = ?"
        )->execute([$strain, $fromId, $ownerUserId]);
        // Origin self-seed: if origin was still Unknown, imprint them with a random strain too.
        if (ap_phyrian_is_origin_owner($fromId)
            && ((string) ($from['status'] ?? '') !== 'imprinted' || trim((string) ($from['strain'] ?? '')) === '')) {
            $originStrain = $catalog[random_int(0, max(0, count($catalog) - 1))] ?? 'Phyrian';
            $db->prepare(
                "UPDATE phyrian_players
                 SET status = 'imprinted', strain = ?, resonance = GREATEST(resonance, 50),
                     imprinted_at = COALESCE(imprinted_at, NOW()), updated_at = NOW()
                 WHERE owner_user_id = ?"
            )->execute([$originStrain, $fromId]);
        }
    } elseif ($kind === 'resonance') {
        $db->prepare(
            'UPDATE phyrian_players SET resonance = LEAST(100, resonance + 5), updated_at = NOW()
             WHERE owner_user_id IN (?, ?)'
        )->execute([$fromId, $ownerUserId]);
    } else {
        return ['ok' => false, 'error' => 'Unknown kind'];
    }

    $db->prepare(
        "UPDATE phyrian_requests SET status = 'accepted', resolved_at = NOW() WHERE id = ?"
    )->execute([$requestId]);
    return ['ok' => true];
}

/**
 * @return list<array{id:int,username:string,actor_key:string,status:string,strain:?string,resonance:int}>
 */
function ap_phyrian_local_directory(int $viewerOwnerId, int $limit = 40): array
{
    ap_phyrian_migrate();
    $limit = max(1, min(80, $limit));
    try {
        $st = ap_db()->prepare(
            "SELECT u.id, u.username, u.actor_key, u.actor_id,
                    COALESCE(p.status, 'unknown') AS status,
                    p.strain,
                    COALESCE(p.resonance, 0) AS resonance
             FROM ap_users u
             LEFT JOIN phyrian_players p ON p.owner_user_id = u.id
             WHERE u.disabled_at IS NULL AND u.id <> ?
             ORDER BY lower(u.username) ASC
             LIMIT ?"
        );
        $st->execute([$viewerOwnerId, $limit]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) ?: [] as $row) {
            $out[] = [
                'id' => (int) ($row['id'] ?? 0),
                'username' => (string) ($row['username'] ?? $row['actor_key'] ?? ''),
                'actor_key' => (string) ($row['actor_key'] ?? ''),
                'status' => (string) ($row['status'] ?? 'unknown'),
                'strain' => isset($row['strain']) ? (string) $row['strain'] : null,
                'resonance' => (int) ($row['resonance'] ?? 0),
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
    $player = ap_phyrian_ensure_player($ownerUserId, ap_phyrian_actor_id_for_owner($ownerUserId));
    if ($player === [] || (string) ($player['status'] ?? '') !== 'imprinted') {
        return ['ok' => false, 'error' => 'Imprint first'];
    }
    $last = (string) ($player['last_checkin_at'] ?? '');
    if ($last !== '' && substr($last, 0, 10) === gmdate('Y-m-d')) {
        return ['ok' => false, 'error' => 'Already checked in today'];
    }
    $bonus = 3;
    if (trim((string) ($player['strain'] ?? '')) !== '') {
        // Linked OpenSim Resonant perk lands in Phase 3; stub hook only.
        $bonus += 0;
    }
    ap_db()->prepare(
        'UPDATE phyrian_players
         SET resonance = LEAST(100, resonance + ?), last_checkin_at = NOW(), updated_at = NOW()
         WHERE owner_user_id = ?'
    )->execute([$bonus, $ownerUserId]);
    $st = ap_db()->prepare('SELECT resonance FROM phyrian_players WHERE owner_user_id = ?');
    $st->execute([$ownerUserId]);
    return ['ok' => true, 'resonance' => (int) $st->fetchColumn()];
}
