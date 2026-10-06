//! Read-only Phyrian parity projection.
//!
//! PHP remains the write owner for Phyrian in 10.6.  This module deliberately
//! exposes only a stable read model so the Axum implementation can be compared
//! with `ap_phyrian_dossier()` and `ap_phyrian_local_directory()` before any
//! mutation or OpenSim bridge ownership moves.

use anyhow::{Context, Result};
use serde::Serialize;
use serde_json::Value;

use crate::{config::Config, db};

const DAILY_DECAY: i64 = 5;
const DECAY_GRACE_SECS: i64 = 86_400;
const MAX_RESONANCE: i64 = 100;
const MAX_GENERATION: i64 = 10;
const MAX_LEVEL: i64 = 80;
const MAX_BANKED: i64 = 300;

#[derive(Debug, Serialize)]
pub struct Projection {
    pub owner_user_id: i64,
    pub username: String,
    pub actor_id: String,
    pub player: Player,
    pub pending_requests: Vec<Request>,
    pub directory: Vec<DirectoryEntry>,
    pub source: &'static str,
    pub mutation_owner: &'static str,
}

#[derive(Debug, Serialize)]
pub struct Player {
    pub status: String,
    pub strain: String,
    pub resonance: i64,
    pub generation: i64,
    pub level: i64,
    pub imprinted_by_owner_id: Option<i64>,
    pub imprinted_at: String,
    pub last_checkin_at: String,
    pub last_decay_at: String,
    pub stability: String,
    pub decay_applied_in_projection: i64,
}

#[derive(Debug, Serialize)]
pub struct Request {
    pub id: i64,
    pub kind: String,
    pub from_owner_id: i64,
    pub from_username: String,
    pub from_actor_key: String,
    pub status: String,
    pub created_at: String,
}

#[derive(Debug, Serialize)]
pub struct DirectoryEntry {
    pub id: i64,
    pub username: String,
    pub actor_key: String,
    pub status: String,
    pub strain: String,
    pub resonance: i64,
    pub generation: i64,
    pub stability: String,
}

/// Canonicalized OpenSim player state for a future mutation hand-off.
///
/// This deliberately does not write to PostgreSQL or call the Strains bridge.
/// PHP currently owns those side effects; keeping this pure lets Axum prove
/// payload/clamp parity before ownership is moved and prevents duplicate
/// OpenSim mutations during the migration.
#[derive(Debug, Clone, PartialEq, Eq, Serialize, serde::Deserialize)]
pub struct OpenSimPlayerPlan {
    pub status: String,
    pub strain: String,
    pub resonance: i64,
    pub generation: i64,
    pub level: i64,
    pub banked_resonance: i64,
    pub resonance_exchanges: i64,
    pub inductions_given: i64,
    pub lineage_depth: i64,
    pub last_decay_at: Option<String>,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RequestOfferPlan {
    pub kind: String,
    pub child_generation: i64,
}

/// PHP `ap_phyrian_request_create` validation, kept pure for parity tests.
pub fn plan_request_offer(
    kind: &str,
    from_owner: i64,
    to_owner: i64,
    from_imprinted: bool,
    to_imprinted: bool,
    from_is_origin: bool,
    from_generation: i64,
) -> Result<RequestOfferPlan, &'static str> {
    let kind = kind.trim().to_ascii_lowercase();
    if !matches!(kind.as_str(), "imprint" | "resonance") {
        return Err("Unknown request kind");
    }
    if from_owner < 1 || to_owner < 1 || from_owner == to_owner {
        return Err("Invalid players");
    }
    if kind == "imprint" {
        if !from_imprinted && !from_is_origin {
            return Err("Only imprinted players (or the origin) can offer imprint");
        }
        if to_imprinted {
            return Err("They already have a strain");
        }
    } else if !from_imprinted || !to_imprinted {
        return Err("Both players must be imprinted to exchange resonance");
    }
    Ok(RequestOfferPlan {
        kind,
        child_generation: (from_generation.max(1) + 1).min(99),
    })
}

/// PHP imprint resolution for a non-origin peer. Origin rolls use the same
/// catalog in the authenticated mutation path; this helper remains strict so
/// callers cannot accidentally treat an origin as a peer.
pub fn resolve_peer_imprint(
    from_is_origin: bool,
    from_strain: &str,
    from_generation: i64,
) -> Result<(String, i64), &'static str> {
    if from_is_origin {
        return Err("Origin imprint requires the PHP catalog roll");
    }
    let strain = from_strain.trim();
    if strain.is_empty() {
        return Err("Imprinter has no strain");
    }
    Ok((strain.to_string(), (from_generation.max(1) + 1).min(99)))
}

/// Same cosmetic catalog as PHP `ap_phyrian_strain_catalog()`.
///
/// The catalog is retained as a parity fixture, but Rust must not select from
/// it during a mutation: PHP's `random_int()` result is the canonical origin
/// roll until a cross-runtime fixture/seed protocol exists.
const ORIGIN_STRAINS: &[&str] = &[
    "Cosmic Alien", "Voidborne", "Signal Choir", "Astral Parasite", "Eventide Spore",
    "Starless Brood", "Null Communion", "Blacklight Kin", "Quasar Wound", "Eclipse Vessel",
    "Deep Signal", "Bio-Horror Alien", "Chitin Bloom", "Marrow Signal", "Vessel Rot",
    "Bone Orchid", "Spine Choir", "Flesh Static", "Suture Bloom", "Moltborn",
    "Cartilage Saint", "Hemolymph Crown", "Symbiotic Alien", "Lumen Host", "Soft Colony",
    "Rootmind", "Amber Symbiote", "Velvet Mycelium", "Twin Pulse", "Murmur Host",
    "Kindred Spore", "Halo Larva", "Second Skin", "Synthetic Alien", "Nanite Choir",
    "Glass Protocol", "Machine Spore", "Chrome Mycelium", "Static Engine", "Signal Lattice",
    "Nullware Host", "Prism Circuit", "Ghost Firmware", "Iron Dream", "Post-Human Mutant",
    "Ash Gene", "Static Flesh", "Chrome Wound", "Afterbody", "Morrow Gene", "Splice Saint",
    "Grey Bloom", "Hollow Kin", "Burnt Genome", "Neon Marrow", "Phyrian", "Red Tower Echo",
    "Obsidian Root", "Rose Static", "Violet Drift", "Cinder Halo", "Witchlight Signal",
    "Moonless Colony", "Grave Neon", "Sable Current",
];

fn configured_origin_owner() -> i64 {
    std::env::var("VAAK_PHYRIAN_ORIGIN_OWNER_ID")
        .ok()
        .and_then(|raw| raw.trim().parse::<i64>().ok())
        .filter(|id| *id > 0)
        .unwrap_or(1)
}

/// Create a local-only request using the PHP schema. This is intentionally a
/// library function, not a public route yet; callers must explicitly opt into
/// the migration after comparing its result with PHP.
pub async fn create_local_request(
    cfg: &Config,
    from_owner: i64,
    to_owner: i64,
    kind: &str,
) -> Result<i64> {
    if from_owner < 1 || to_owner < 1 || from_owner == to_owner {
        anyhow::bail!("Invalid players");
    }
    let db = db::connect(&cfg.database_url).await?;
    let rows = db
        .query(
            "SELECT id, COALESCE(actor_id,''), (disabled_at IS NULL)
             FROM ap_users WHERE id = $1 OR id = $2",
            &[&from_owner, &to_owner],
        )
        .await
        .context("load Phyrian request players")?;
    if rows.len() != 2 || rows.iter().any(|r| !r.get::<_, bool>(2)) {
        anyhow::bail!("Players unavailable");
    }
    let mut actor_by_id = std::collections::HashMap::new();
    for row in rows {
        let id: i64 = row.get(0);
        let actor: String = row.get(1);
        if !actor.starts_with("https://mkultra.monster/users/") {
            anyhow::bail!("Phyrian requests are local-only");
        }
        actor_by_id.insert(id, actor);
    }
    for (owner, actor) in [(from_owner, actor_by_id[&from_owner].clone()), (to_owner, actor_by_id[&to_owner].clone())] {
        let owner_i32 = i32::try_from(owner).context("owner id outside PostgreSQL integer range")?;
        db.execute(
            "INSERT INTO phyrian_players (owner_user_id, actor_id, status, resonance, generation, level)
             VALUES ($1,$2,'unknown',0,1,1) ON CONFLICT (owner_user_id) DO NOTHING",
            &[&owner_i32, &actor],
        )
        .await
        .map_err(|e| anyhow::anyhow!("ensure Phyrian player: {e}"))?;
    }
    let state = db
        .query(
            "SELECT owner_user_id, status, COALESCE(strain,''), generation
             FROM phyrian_players WHERE owner_user_id = $1 OR owner_user_id = $2",
            &[&(from_owner as i32), &(to_owner as i32)],
        )
        .await
        .context("load Phyrian player state")?;
    let mut from_imprinted = false;
    let mut to_imprinted = false;
    let mut generation = 1;
    for row in state {
        let id: i64 = row.get(0);
        let imprinted: String = row.get(1);
        let strain: String = row.get(2);
        if id == from_owner {
            from_imprinted = imprinted == "imprinted" && !strain.trim().is_empty();
            generation = row.get::<_, i64>(3);
        } else if id == to_owner {
            to_imprinted = imprinted == "imprinted" && !strain.trim().is_empty();
        }
    }
    let origin = from_owner == configured_origin_owner();
    plan_request_offer(kind, from_owner, to_owner, from_imprinted, to_imprinted, origin, generation)
        .map_err(|e| anyhow::anyhow!(e))?;
    let dup: Option<i64> = db
        .query_opt(
            "SELECT id FROM phyrian_requests
             WHERE from_owner_id=$1 AND to_owner_id=$2 AND kind=$3 AND status='pending' LIMIT 1",
            &[&from_owner, &to_owner, &kind.trim().to_ascii_lowercase()],
        )
        .await?
        .map(|r| r.get(0));
    if dup.is_some() {
        anyhow::bail!("Request already pending");
    }
    let id: i64 = db
        .query_one(
            "INSERT INTO phyrian_requests (kind, from_owner_id, to_owner_id, status)
             VALUES ($1,$2,$3,'pending') RETURNING id",
            &[&kind.trim().to_ascii_lowercase(), &from_owner, &to_owner],
        )
        .await?
        .get(0);
    Ok(id)
}

/// Resolve a local request using the PHP schema. Origin imprint acceptance is
/// intentionally refused here until the PHP catalog roll is migrated; peer
/// imprints and resonance exchanges are deterministic and safe to mirror.
pub async fn resolve_local_request(
    cfg: &Config,
    owner: i64,
    request_id: i64,
    accept: bool,
) -> Result<Option<String>> {
    if owner < 1 || request_id < 1 {
        anyhow::bail!("Invalid request");
    }
    let owner_i32 = i32::try_from(owner).context("owner id outside PostgreSQL integer range")?;
    let request_i64 = request_id;
    let mut db = db::connect(&cfg.database_url).await?;
    let row = db
        .query_opt(
            "SELECT kind, from_owner_id FROM phyrian_requests
             WHERE id=$1 AND to_owner_id=$2 AND status='pending' LIMIT 1",
            &[&request_i64, &owner_i32],
        )
        .await?
        .ok_or_else(|| anyhow::anyhow!("Request not found"))?;
    let kind: String = row.get(0);
    let from_owner: i64 = row.get(1);
    if kind == "resonance" {
        let linked_row = db
            .query_one(
                "SELECT EXISTS(
                   SELECT 1 FROM phyrian_bridge_links
                   WHERE owner_user_id IN ($1,$2)
                     AND status='verified' AND unlinked_at IS NULL
                 )",
                &[&(from_owner as i32), &owner_i32],
            )
            .await?;
        let is_linked: bool = linked_row.get(0);
        if is_linked {
            anyhow::bail!("Linked OpenSim bodies must exchange resonance in OpenSim");
        }
    }
    // PHP owns `random_int()` origin selection. Returning the deliberate
    // conflict signal lets the caller execute the canonical PHP path instead
    // of silently producing a different strain in Rust.
    if kind == "imprint" && from_owner == configured_origin_owner() {
        anyhow::bail!("Origin imprint requires the PHP catalog roll");
    }
    let tx = db.transaction().await?;
    if !accept {
        tx.execute(
            "UPDATE phyrian_requests SET status='denied', resolved_at=NOW() WHERE id=$1",
            &[&request_i64],
        )
        .await?;
        tx.commit().await?;
        return Ok(None);
    }
    if kind == "imprint" {
        let rows = tx
            .query(
                "SELECT owner_user_id, COALESCE(strain,''), generation, status
                 FROM phyrian_players WHERE owner_user_id = $1 OR owner_user_id = $2",
                &[&(from_owner as i32), &owner_i32],
            )
            .await?;
        let mut from_strain = String::new();
        let mut from_generation = 1;
        let mut to_imprinted = false;
        for r in rows {
            let id: i64 = r.get(0);
            let strain: String = r.get(1);
            let status: String = r.get(3);
            if id == from_owner {
                from_strain = strain;
                from_generation = r.get(2);
            } else if id == owner {
                to_imprinted = status == "imprinted" && !strain.trim().is_empty();
            }
        }
        if to_imprinted {
            anyhow::bail!("They already have a strain");
        }
        let (strain, generation) = resolve_peer_imprint(false, &from_strain, from_generation)
            .map_err(|e| anyhow::anyhow!(e))?;
        tx.execute(
            "UPDATE phyrian_players
             SET status='imprinted', strain=$1, resonance=GREATEST(resonance,50),
                 generation=$2, imprinted_by_owner_id=$3, imprinted_at=NOW(),
                 last_decay_at=NOW(), updated_at=NOW() WHERE owner_user_id=$4",
            &[&strain, &(generation as i32), &(from_owner as i32), &owner_i32],
        )
        .await?;
        tx.execute(
            "UPDATE phyrian_players SET inductions_given=inductions_given+1, updated_at=NOW()
             WHERE owner_user_id=$1",
            &[&(from_owner as i32)],
        )
        .await?;
        tx.execute(
            "UPDATE phyrian_requests SET status='accepted', resolved_at=NOW() WHERE id=$1",
            &[&request_i64],
        )
        .await?;
        tx.commit().await?;
        return Ok(Some(strain));
    }
    if kind != "resonance" {
        anyhow::bail!("Unknown request kind");
    }
    let count = tx
        .execute(
            "UPDATE phyrian_players SET resonance=LEAST(100,resonance+5),
             last_decay_at=NOW(), updated_at=NOW() WHERE owner_user_id IN ($1,$2)
             AND status='imprinted' AND COALESCE(strain,'') <> ''",
            &[&(from_owner as i32), &owner_i32],
        )
        .await?;
    if count != 2 {
        anyhow::bail!("Both players must be imprinted to exchange resonance");
    }
    tx.execute(
        "UPDATE phyrian_requests SET status='accepted', resolved_at=NOW() WHERE id=$1",
        &[&request_i64],
    )
    .await?;
    tx.commit().await?;
    Ok(None)
}

fn nonnegative(v: Option<i64>) -> i64 {
    v.unwrap_or(0).max(0)
}

fn int_field(input: &Value, key: &str) -> Option<i64> {
    input.get(key).and_then(|v| {
        v.as_i64().or_else(|| v.as_u64().and_then(|n| i64::try_from(n).ok()))
    })
}

/// Match PHP `ap_phyrian_bridge_apply_opensim_player` normalization exactly.
pub fn normalize_opensim_player(input: &Value) -> OpenSimPlayerPlan {
    let strain = input
        .get("strain")
        .and_then(|v| v.as_str())
        .unwrap_or("")
        .trim()
        .to_string();
    let imprinted = !strain.is_empty();
    OpenSimPlayerPlan {
        status: if imprinted { "imprinted" } else { "unknown" }.into(),
        strain,
        resonance: int_field(input, "resonance").unwrap_or(0).clamp(0, MAX_RESONANCE),
        generation: int_field(input, "generation").unwrap_or(1).clamp(1, MAX_GENERATION),
        level: int_field(input, "level").unwrap_or(1).clamp(1, MAX_LEVEL),
        banked_resonance: int_field(input, "banked_resonance").unwrap_or(0).clamp(0, MAX_BANKED),
        resonance_exchanges: nonnegative(int_field(input, "resonance_exchanges")),
        inductions_given: nonnegative(int_field(input, "inductions_given")),
        lineage_depth: nonnegative(int_field(input, "lineage_depth")),
        last_decay_at: input
            .get("last_decay_at")
            .and_then(|v| v.as_str())
            .map(str::trim)
            .filter(|s| !s.is_empty())
            .map(str::to_string),
    }
}

/// Build the same authenticated OpenSim bridge envelope as PHP. The Rust
/// worker does not send it yet; this pure contract is what lets us compare
/// payloads before moving bridge side effects.
pub fn bridge_request_payload(action: &str, body: &Value, secret: &str) -> Value {
    let mut payload = body.as_object().cloned().unwrap_or_default();
    payload.insert("action".into(), Value::String(action.to_string()));
    payload.insert("bridge_secret".into(), Value::String(secret.to_string()));
    Value::Object(payload)
}

pub async fn dossier(cfg: &Config, owner: i64, include_directory: bool, limit: i64) -> Result<Projection> {
    if owner < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let client = db::connect(&cfg.database_url).await?;
    let owner_i32 = i32::try_from(owner).context("owner_id outside PostgreSQL integer range")?;
    let row = client
        .query_opt(
            "SELECT u.username, u.actor_key, p.actor_id, p.status, p.strain,
                    p.resonance, p.generation, p.level, p.imprinted_by_owner_id,
                    COALESCE(to_char(p.imprinted_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'), ''),
                    COALESCE(to_char(p.last_checkin_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'), ''),
                    COALESCE(to_char(p.last_decay_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"'), ''),
                    EXTRACT(EPOCH FROM (NOW() - COALESCE(p.imprinted_at, p.created_at)))::bigint AS age_secs,
                    EXTRACT(EPOCH FROM (NOW() - GREATEST(COALESCE(p.last_decay_at, p.imprinted_at + INTERVAL '1 day'), p.imprinted_at + INTERVAL '1 day')))::bigint AS decay_secs
             FROM ap_users u
             LEFT JOIN phyrian_players p ON p.owner_user_id = u.id
             WHERE u.id = $1 AND u.disabled_at IS NULL
             LIMIT 1",
            &[&owner],
        )
        .await
        .context("query Phyrian dossier")?
        .ok_or_else(|| anyhow::anyhow!("owner not found"))?;

    let username: String = row.try_get(0).unwrap_or_default();
    let actor_key: String = row.try_get(1).unwrap_or_default();
    let actor_id: String = row.try_get(2).unwrap_or_else(|_| String::new());
    let status: String = row.try_get(3).unwrap_or_else(|_| "unknown".into());
    let strain: String = row.try_get::<_, Option<String>>(4).unwrap_or(None).unwrap_or_default();
    let imprinted = status == "imprinted" && !strain.trim().is_empty();
    let stored_resonance: i64 = row.try_get(5).unwrap_or(0);
    let age_secs: i64 = row.try_get(13).unwrap_or(0);
    let decay_secs: i64 = row.try_get(14).unwrap_or(0);
    let decay_days = if imprinted && age_secs >= DECAY_GRACE_SECS {
        (decay_secs.max(0) / 86_400).max(0)
    } else {
        0
    };
    // Projection only: PHP remains responsible for persisting lazy decay.
    let (resonance, applied) = projected_decay(&status, &strain, stored_resonance, age_secs, decay_days * 86_400);

    let player = Player {
        status: status.clone(),
        strain: if imprinted { strain.clone() } else { String::new() },
        resonance,
        generation: row.try_get(6).unwrap_or(1),
        level: row.try_get(7).unwrap_or(1),
        imprinted_by_owner_id: row.try_get(8).unwrap_or(None),
        imprinted_at: row.try_get(9).unwrap_or_default(),
        last_checkin_at: row.try_get(10).unwrap_or_default(),
        last_decay_at: row.try_get(11).unwrap_or_default(),
        stability: stability(imprinted, resonance),
        decay_applied_in_projection: applied,
    };

    let pending_requests = client
        .query(
            "SELECT r.id, r.kind, r.from_owner_id,
                    COALESCE(u.username, u.actor_key, ''), COALESCE(u.actor_key, ''),
                    r.status, to_char(r.created_at AT TIME ZONE 'UTC', 'YYYY-MM-DD\"T\"HH24:MI:SS\"Z\"')
             FROM phyrian_requests r
             LEFT JOIN ap_users u ON u.id = r.from_owner_id
             WHERE r.to_owner_id = $1::integer AND r.status = 'pending'
             ORDER BY r.created_at DESC LIMIT 40",
            &[&owner_i32],
        )
        .await
        .map_err(|e| anyhow::anyhow!("query pending Phyrian requests: {e}"))?
        .into_iter()
        .map(|r| Request {
            id: r.try_get(0).unwrap_or(0),
            kind: r.try_get(1).unwrap_or_default(),
            from_owner_id: r.try_get(2).unwrap_or(0),
            from_username: r.try_get(3).unwrap_or_default(),
            from_actor_key: r.try_get(4).unwrap_or_default(),
            status: r.try_get(5).unwrap_or_default(),
            created_at: r.try_get(6).unwrap_or_default(),
        })
        .collect();

    let directory = if include_directory {
        let lim = limit.clamp(1, 80);
        client
            .query(
                "SELECT u.id, COALESCE(u.username, u.actor_key, ''), COALESCE(u.actor_key, ''),
                        COALESCE(p.status, 'unknown'), COALESCE(p.strain, ''),
                        COALESCE(p.resonance, 0), COALESCE(p.generation, 1)
                 FROM ap_users u LEFT JOIN phyrian_players p ON p.owner_user_id = u.id
                 WHERE u.disabled_at IS NULL AND u.id <> $1
                 ORDER BY lower(COALESCE(u.username, u.actor_key, '')) ASC LIMIT $2",
                &[&owner, &lim],
            )
            .await
            .context("query Phyrian directory")?
            .into_iter()
            .map(|r| {
                let s: String = r.try_get(3).unwrap_or_else(|_| "unknown".into());
                let strain: String = r.try_get(4).unwrap_or_default();
                let imprinted = s == "imprinted" && !strain.trim().is_empty();
                let resonance: i64 = r.try_get(5).unwrap_or(0);
                DirectoryEntry { id: r.try_get(0).unwrap_or(0), username: r.try_get(1).unwrap_or_default(), actor_key: r.try_get(2).unwrap_or_default(), status: s, strain, resonance, generation: r.try_get(6).unwrap_or(1), stability: stability(imprinted, resonance) }
            })
            .collect()
    } else { Vec::new() };

    Ok(Projection { owner_user_id: owner, username: if username.is_empty() { actor_key.clone() } else { username }, actor_id, player, pending_requests, directory, source: "vaak-worker-phyrian-read", mutation_owner: "php" })
}

fn stability(imprinted: bool, resonance: i64) -> String {
    if !imprinted { return "Unmarked".into(); }
    match resonance { r if r <= 0 => "Dormant", r if r < 25 => "Critical", r if r < 50 => "Fading", _ => "Stable" }.into()
}

fn projected_decay(status: &str, strain: &str, resonance: i64, age_secs: i64, decay_secs: i64) -> (i64, i64) {
    let imprinted = status == "imprinted" && !strain.trim().is_empty();
    if !imprinted || age_secs < DECAY_GRACE_SECS { return (resonance, 0); }
    let days = (decay_secs.max(0) / 86_400).max(0);
    let amount = (days * DAILY_DECAY).min(resonance.max(0));
    ((resonance - amount).max(0), amount)
}

#[cfg(test)]
mod tests {
    use super::{bridge_request_payload, configured_origin_owner, normalize_opensim_player, plan_request_offer, projected_decay, resolve_peer_imprint, stability, OpenSimPlayerPlan, ORIGIN_STRAINS};
    #[test] fn php_stability_boundaries_match() {
        assert_eq!(stability(false, 99), "Unmarked");
        assert_eq!(stability(true, 0), "Dormant");
        assert_eq!(stability(true, 24), "Critical");
        assert_eq!(stability(true, 49), "Fading");
        assert_eq!(stability(true, 50), "Stable");
    }

    #[test]
    fn php_parity_fixture_decay_and_stability() {
        #[derive(serde::Deserialize)]
        struct Case { status: String, strain: String, resonance: i64, age_secs: i64, decay_secs: i64, expected_resonance: i64, expected_stability: String, expected_decay: i64 }
        let cases: Vec<Case> = serde_json::from_str(include_str!("../fixtures/phyrian/php-parity.json")).expect("fixture JSON");
        for case in cases {
            let (resonance, decay) = projected_decay(&case.status, &case.strain, case.resonance, case.age_secs, case.decay_secs);
            assert_eq!(resonance, case.expected_resonance);
            assert_eq!(decay, case.expected_decay);
            assert_eq!(stability(case.status == "imprinted" && !case.strain.is_empty(), resonance), case.expected_stability);
        }
    }

    #[test]
    fn opensim_player_normalization_matches_php_clamps() {
        let input = serde_json::json!({
            "strain": "  Voidborne ", "resonance": 999, "generation": 0,
            "level": 999, "banked_resonance": -4,
            "resonance_exchanges": -2, "inductions_given": 7,
            "lineage_depth": -1, "last_decay_at": " 2026-10-05T00:00:00Z "
        });
        let got = normalize_opensim_player(&input);
        assert_eq!(got.status, "imprinted");
        assert_eq!(got.strain, "Voidborne");
        assert_eq!(got.resonance, 100);
        assert_eq!(got.generation, 1);
        assert_eq!(got.level, 80);
        assert_eq!(got.banked_resonance, 0);
        assert_eq!(got.resonance_exchanges, 0);
        assert_eq!(got.inductions_given, 7);
        assert_eq!(got.lineage_depth, 0);
        assert_eq!(got.last_decay_at.as_deref(), Some("2026-10-05T00:00:00Z"));
    }

    #[test]
    fn opensim_unmarked_player_uses_php_defaults() {
        let got = normalize_opensim_player(&serde_json::json!({"strain": ""}));
        assert_eq!(got.status, "unknown");
        assert_eq!(got.resonance, 0);
        assert_eq!(got.generation, 1);
        assert_eq!(got.level, 1);
        assert_eq!(got.last_decay_at, None);
    }

    #[test]
    fn opensim_normalization_matches_shared_php_fixtures() {
        #[derive(serde::Deserialize)]
        struct Case {
            input: serde_json::Value,
            expected: OpenSimPlayerPlan,
        }
        let cases: Vec<Case> = serde_json::from_str(include_str!("../fixtures/phyrian/opensim-normalization.json"))
            .expect("OpenSim normalization fixture JSON");
        for case in cases {
            assert_eq!(normalize_opensim_player(&case.input), case.expected);
        }
    }

    #[test]
    fn bridge_request_envelope_matches_php_contract() {
        let payload = bridge_request_payload(
            "vaak_player_pull",
            &serde_json::json!({"avatar_uuid": "abc"}),
            "fixture-secret",
        );
        assert_eq!(
            payload,
            serde_json::json!({
                "avatar_uuid": "abc",
                "action": "vaak_player_pull",
                "bridge_secret": "fixture-secret"
            })
        );
    }

    #[test]
    fn request_offer_rules_match_php() {
        let plan = plan_request_offer(" IMPRINT ", 2, 3, true, false, false, 2).unwrap();
        assert_eq!(plan.kind, "imprint");
        assert_eq!(plan.child_generation, 3);
        assert_eq!(
            plan_request_offer("resonance", 2, 3, true, false, false, 1),
            Err("Both players must be imprinted to exchange resonance")
        );
        assert_eq!(
            plan_request_offer("imprint", 2, 3, false, false, false, 1),
            Err("Only imprinted players (or the origin) can offer imprint")
        );
        assert_eq!(
            plan_request_offer("imprint", 2, 2, true, false, false, 1),
            Err("Invalid players")
        );
    }

    #[test]
    fn peer_imprint_resolution_preserves_strain_and_generation() {
        assert_eq!(
            resolve_peer_imprint(false, "  Voidborne ", 4).unwrap(),
            ("Voidborne".to_string(), 5)
        );
        assert_eq!(
            resolve_peer_imprint(true, "Voidborne", 4),
            Err("Origin imprint requires the PHP catalog roll")
        );
        assert_eq!(
            resolve_peer_imprint(false, "", 4),
            Err("Imprinter has no strain")
        );
    }

    #[test]
    fn origin_roll_is_always_deferred_to_php() {
        // The default and configured operator IDs must both take the guarded
        // origin path; Rust never invents a catalog result independently.
        assert_eq!(configured_origin_owner(), 1);
        assert_eq!(
            resolve_peer_imprint(true, "Phyrian", 3),
            Err("Origin imprint requires the PHP catalog roll")
        );
    }

    #[test]
    fn origin_catalog_order_matches_php_fixture_shape() {
        assert_eq!(ORIGIN_STRAINS.len(), 65);
        assert_eq!(ORIGIN_STRAINS.first(), Some(&"Cosmic Alien"));
        assert_eq!(ORIGIN_STRAINS[55], "Phyrian");
        assert_eq!(ORIGIN_STRAINS.last(), Some(&"Sable Current"));
    }
}
