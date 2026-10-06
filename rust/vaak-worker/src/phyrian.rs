//! Read-only Phyrian parity projection.
//!
//! PHP remains the write owner for Phyrian in 10.6.  This module deliberately
//! exposes only a stable read model so the Axum implementation can be compared
//! with `ap_phyrian_dossier()` and `ap_phyrian_local_directory()` before any
//! mutation or OpenSim bridge ownership moves.

use anyhow::{Context, Result};
use reqwest::Client;
use serde::Serialize;
use serde_json::Value;
use rand::Rng;
use sha2::{Digest, Sha256};

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
    pub daily_resonance_decay: i64,
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

fn pick_origin_strain() -> String {
    let index = rand::thread_rng().gen_range(0..ORIGIN_STRAINS.len());
    ORIGIN_STRAINS[index].to_string()
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
        // ap_users.id is BIGINT, matching the public account table.
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
        // Phyrian owner columns are INTEGER in the canonical PostgreSQL schema.
        let id: i32 = row.get(0);
        let imprinted: String = row.get(1);
        let strain: String = row.get(2);
        if i64::from(id) == from_owner {
            from_imprinted = imprinted == "imprinted" && !strain.trim().is_empty();
            generation = i64::from(row.get::<_, i32>(3));
        } else if i64::from(id) == to_owner {
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
            &[&(from_owner as i32), &(to_owner as i32), &kind.trim().to_ascii_lowercase()],
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
            &[&kind.trim().to_ascii_lowercase(), &(from_owner as i32), &(to_owner as i32)],
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
    let from_owner: i64 = i64::from(row.get::<_, i32>(1));
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
            let id: i32 = r.get(0);
            let strain: String = r.get(1);
            let status: String = r.get(3);
            if i64::from(id) == from_owner {
                from_strain = strain;
                from_generation = i64::from(r.get::<_, i32>(2));
            } else if i64::from(id) == owner {
                to_imprinted = status == "imprinted" && !strain.trim().is_empty();
            }
        }
        if to_imprinted {
            anyhow::bail!("They already have a strain");
        }
        let from_is_origin = from_owner == configured_origin_owner();
        if from_is_origin && from_strain.trim().is_empty() {
            // Seed the canonical origin body in the same transaction as the
            // offer resolution. This removes the last local-only PHP fallback
            // while keeping linked OpenSim bodies protected elsewhere.
            tx.execute(
                "UPDATE phyrian_players
                 SET status='imprinted', strain='Phyrian', resonance=GREATEST(resonance,93),
                     generation=3, level=80, last_decay_at=NOW(), updated_at=NOW()
                 WHERE owner_user_id=$1",
                &[&(from_owner as i32)],
            ).await?;
            from_strain = "Phyrian".to_string();
            from_generation = 3;
        }
        let strain = if from_is_origin {
            pick_origin_strain()
        } else {
            resolve_peer_imprint(false, &from_strain, from_generation)
                .map_err(|e| anyhow::anyhow!(e))?.0
        };
        let generation = (from_generation.max(1) + 1).min(99);
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
        "UPDATE phyrian_players SET resonance_exchanges=resonance_exchanges+1, updated_at=NOW()
         WHERE owner_user_id IN ($1,$2)",
        &[&(from_owner as i32), &owner_i32],
    )
    .await?;
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

/// Classify the same bridge response cases as PHP, without performing I/O.
pub fn decode_bridge_response(http: i64, raw: &str) -> Value {
    if raw.is_empty() {
        return serde_json::json!({"ok": false, "http": http, "error": "Empty response from strains API."});
    }
    let Ok(data) = serde_json::from_str::<Value>(raw) else {
        return serde_json::json!({"ok": false, "http": http, "error": "Invalid JSON from strains API."});
    };
    let Some(object) = data.as_object() else {
        return serde_json::json!({"ok": false, "http": http, "error": "Invalid JSON from strains API."});
    };
    if (200..300).contains(&http) && object.get("ok").and_then(Value::as_bool).unwrap_or(false) {
        return serde_json::json!({"ok": true, "http": http, "data": data});
    }
    let message = object
        .get("error")
        .or_else(|| object.get("message"))
        .and_then(Value::as_str)
        .map(str::to_string)
        .unwrap_or_else(|| format!("Strains HTTP {http}"));
    serde_json::json!({"ok": false, "http": http, "data": data, "error": message})
}

/// Read-only OpenSim bridge call. Mutation actions are intentionally not
/// exposed here until the Rust client has a canary and rollback path.
pub async fn bridge_call(cfg: &Config, action: &str, body: Value) -> Result<Value> {
    let secret = cfg.phyrian_bridge_secret.as_deref()
        .filter(|s| !s.is_empty())
        .context("OpenSim bridge secret is not configured")?;
    let payload = bridge_request_payload(action, &body, secret);
    let response = Client::builder()
        .timeout(std::time::Duration::from_secs(12))
        .build()?
        .post(format!("{}/", cfg.phyrian_bridge_url.trim_end_matches('/')))
        .header("X-Strains-Bridge-Secret", secret)
        .header("User-Agent", "VAAK-Phyrian-Bridge/1.0 (+https://vaak.monster)")
        .json(&payload)
        .send()
        .await
        .context("OpenSim bridge request")?;
    let status = response.status().as_u16() as i64;
    let raw = response.text().await.context("OpenSim bridge response")?;
    let decoded = decode_bridge_response(status, &raw);
    if decoded.get("ok").and_then(Value::as_bool) != Some(true) {
        anyhow::bail!(decoded.get("error").and_then(Value::as_str).unwrap_or("OpenSim bridge request failed").to_string());
    }
    Ok(decoded.get("data").cloned().unwrap_or(Value::Null))
}

pub async fn bridge_read(cfg: &Config, action: &str, body: Value) -> Result<Value> {
    if !matches!(action, "vaak_resolve" | "vaak_player_pull") {
        anyhow::bail!("Bridge action is not read-only");
    }
    bridge_call(cfg, action, body).await
}

/// OpenSim bridge mutation client. This is deliberately separate from the
/// read-only client so callers cannot accidentally turn a read path into a
/// write. PHP remains the active owner until each action has a canary.
pub async fn bridge_write(cfg: &Config, action: &str, body: Value) -> Result<Value> {
    if !matches!(action, "vaak_claim_deliver" | "vaak_link_set" | "vaak_link_clear" | "vaak_daily_claim") {
        anyhow::bail!("Bridge action is not an approved mutation");
    }
    bridge_call(cfg, action, body).await
}

fn identify_kind(raw: &str) -> Option<(&'static str, String)> {
    let value = raw.trim();
    if value.is_empty() { return None; }
    // Keep parity with PHP's accepted Strains profile URL format.
    // The public UI commonly submits https://strains.novalandia.online/?uuid=…
    // rather than the UUID itself.
    if let Some((_, query)) = value.split_once("?uuid=") {
        let candidate = query.split('&').next().unwrap_or("").trim();
        if uuid::Uuid::parse_str(candidate).is_ok() {
            return Some(("uuid", candidate.to_ascii_lowercase()));
        }
    }
    if uuid::Uuid::parse_str(value).is_ok() {
        return Some(("uuid", value.to_ascii_lowercase()));
    }
    let normalized = value.replace(['+', '.'], " ");
    let normalized = normalized.split_whitespace().collect::<Vec<_>>().join(" ");
    let valid_name = !normalized.is_empty()
        && normalized.chars().count() <= 80
        && !normalized.contains('@')
        && !normalized.contains('/')
        && normalized.chars().all(|c| c.is_alphanumeric() || matches!(c, ' ' | '_' | '\'' | '-'));
    if valid_name {
        return Some(("name", normalized));
    }
    None
}

fn sha256_hex(value: &str) -> String {
    let mut h = Sha256::new();
    h.update(value.as_bytes());
    hex::encode(h.finalize())
}

pub async fn bridge_challenge_start(cfg: &Config, owner: i64, identify: &str) -> Result<Value> {
    if owner < 1 { anyhow::bail!("Not signed in."); }
    let (kind, query) = identify_kind(identify).context("Enter a NovaLandia avatar name or UUID")?;
    let resolved = bridge_call(cfg, "vaak_resolve", serde_json::json!({"query": query, "query_kind": kind})).await?;
    let avatar = resolved.get("avatar").filter(|v| v.is_object()).cloned().context("No NovaLandia Strains avatar matched that input")?;
    let uuid = avatar.get("avatar_uuid").and_then(Value::as_str).unwrap_or("").trim().to_ascii_lowercase();
    let name = avatar.get("avatar_name").and_then(Value::as_str).unwrap_or("Unknown").trim().to_string();
    if uuid::Uuid::parse_str(&uuid).is_err() { anyhow::bail!("OpenSim returned an invalid avatar UUID"); }
    let code: String = (0..8).map(|_| {
        const ALPHABET: &[u8] = b"ABCDEFGHJKLMNPQRSTUVWXYZ23456789";
        ALPHABET[rand::thread_rng().gen_range(0..ALPHABET.len())] as char
    }).collect();
    let expires = chrono::Utc::now() + chrono::Duration::seconds(600);
    let hash = sha256_hex(&format!("{code}|{uuid}|{owner}"));
    let db = db::connect(&cfg.database_url).await?;
    db.execute("DELETE FROM phyrian_bridge_challenges WHERE owner_user_id=$1 OR expires_at<NOW()", &[&(owner as i32)]).await?;
    db.execute("INSERT INTO phyrian_bridge_challenges (owner_user_id,avatar_uuid,avatar_name,code_hash,attempts,expires_at) VALUES ($1,$2,$3,$4,0,$5)", &[&(owner as i32), &uuid, &name, &hash, &expires]).await?;
    let deliver = bridge_write(cfg, "vaak_claim_deliver", serde_json::json!({
        "avatar_uuid": uuid, "avatar_name": name, "code": code,
        "expires_at": expires.to_rfc3339(), "ttl_seconds": 600
    })).await;
    Ok(serde_json::json!({
        "ok": true, "avatar": avatar,
        "challenge": {"avatar_uuid": uuid, "avatar_name": name, "expires_at": expires.to_rfc3339(), "deliver_ok": deliver.is_ok()},
        "notice": format!("Claim code queued for {name}. Wear the Phyrian Strains HUD and touch Status (or Register), then paste it here. Valid ~10 minutes.")
    }))
}

pub async fn bridge_challenge_verify(cfg: &Config, owner: i64, raw_code: &str) -> Result<Value> {
    if owner < 1 { anyhow::bail!("Not signed in."); }
    let code: String = raw_code.chars().filter(|c| c.is_ascii_alphanumeric()).collect::<String>().to_ascii_uppercase();
    if code.len() < 4 { anyhow::bail!("Enter the code from your Phyrian Strains HUD."); }
    let mut db = db::connect(&cfg.database_url).await?;
    let row = db.query_opt("SELECT id,avatar_uuid,avatar_name,code_hash,attempts FROM phyrian_bridge_challenges WHERE owner_user_id=$1 AND expires_at>=NOW() ORDER BY id DESC LIMIT 1", &[&(owner as i32)]).await?.context("No active code — request a new one")?;
    let id: i64 = row.get(0); let uuid: String = row.get(1); let name: String = row.get(2); let expected: String = row.get(3); let attempts: i32 = row.get(4);
    if attempts >= 8 { anyhow::bail!("Too many attempts — request a new code."); }
    db.execute("UPDATE phyrian_bridge_challenges SET attempts=attempts+1 WHERE id=$1", &[&id]).await?;
    if sha256_hex(&format!("{code}|{uuid}|{owner}")).as_str() != expected { anyhow::bail!("That code does not match. Check the HUD message and try again."); }
    let resolved = bridge_call(cfg, "vaak_resolve", serde_json::json!({"query": uuid, "query_kind": "uuid"})).await.unwrap_or(Value::Null);
    let avatar = resolved.get("avatar").filter(|v| v.is_object());
    let avatar_name = avatar.and_then(|a| a.get("avatar_name")).and_then(Value::as_str).unwrap_or(&name).to_string();
    let strain = avatar.and_then(|a| a.get("strain")).and_then(Value::as_str).map(str::to_string);
    let actor_row = db.query_one("SELECT actor_key,COALESCE(NULLIF(username,''),actor_key) FROM ap_users WHERE id=$1 LIMIT 1", &[&(owner as i64)]).await?;
    let actor_key: String = actor_row.get(0); let handle: String = actor_row.get(1);
    let tx = db.transaction().await?;
    tx.execute("DELETE FROM phyrian_bridge_challenges WHERE owner_user_id=$1", &[&(owner as i32)]).await?;
    tx.execute("UPDATE phyrian_bridge_links SET status='revoked',unlinked_at=NOW(),updated_at=NOW() WHERE (owner_user_id=$1 OR avatar_uuid=$2) AND status='verified' AND unlinked_at IS NULL", &[&(owner as i32), &uuid]).await?;
    tx.execute("DELETE FROM phyrian_bridge_links WHERE owner_user_id=$1 OR avatar_uuid=$2", &[&(owner as i32), &uuid]).await?;
    tx.execute("INSERT INTO phyrian_bridge_links (owner_user_id,avatar_uuid,avatar_name,strain_snapshot,status,verified_at,created_at,updated_at) VALUES ($1,$2,$3,$4,'verified',NOW(),NOW(),NOW())", &[&(owner as i32), &uuid, &avatar_name, &strain]).await?;
    tx.commit().await?;
    let link_set = bridge_write(cfg, "vaak_link_set", serde_json::json!({"avatar_uuid": uuid, "vaak_owner_id": owner, "vaak_actor_key": actor_key, "vaak_handle": handle})).await;
    Ok(serde_json::json!({"ok": true, "notice": format!("Linked OpenSim avatar {avatar_name}. OpenSim body sync is active."), "link_set_ok": link_set.is_ok(), "link": {"avatar_uuid": uuid, "avatar_name": avatar_name, "strain_snapshot": strain, "status": "verified"}}))
}

pub async fn bridge_unlink(cfg: &Config, owner: i64) -> Result<Value> {
    if owner < 1 { anyhow::bail!("Not signed in."); }
    let db = db::connect(&cfg.database_url).await?;
    let uuid: Option<String> = db.query_opt("SELECT avatar_uuid FROM phyrian_bridge_links WHERE owner_user_id=$1 AND status='verified' AND unlinked_at IS NULL LIMIT 1", &[&(owner as i32)]).await?.map(|r| r.get(0));
    db.execute("UPDATE phyrian_bridge_links SET status='revoked',unlinked_at=NOW(),updated_at=NOW() WHERE owner_user_id=$1 AND status='verified'", &[&(owner as i32)]).await?;
    db.execute("DELETE FROM phyrian_bridge_links WHERE owner_user_id=$1", &[&(owner as i32)]).await?;
    db.execute("DELETE FROM phyrian_bridge_challenges WHERE owner_user_id=$1", &[&(owner as i32)]).await?;
    if let Some(uuid) = uuid.filter(|u| uuid::Uuid::parse_str(u).is_ok()) {
        let _ = bridge_write(cfg, "vaak_link_clear", serde_json::json!({"avatar_uuid": uuid})).await;
    }
    Ok(serde_json::json!({"ok": true, "notice": "OpenSim avatar unlinked. Resonant perk removed."}))
}

/// Claim the linked avatar's daily OpenSim resonance and mirror the returned
/// body into VAAK atomically. This is implemented behind the internal route;
/// PHP remains the public check-in owner until the dedicated feature gate is
/// enabled after canary review.
pub async fn bridge_daily_claim(cfg: &Config, owner: i64) -> Result<Value> {
    if owner < 1 { anyhow::bail!("Not signed in."); }
    let mut client = db::connect(&cfg.database_url).await?;
    let row = client.query_opt(
        "SELECT avatar_uuid FROM phyrian_bridge_links WHERE owner_user_id=$1 AND status='verified' AND unlinked_at IS NULL LIMIT 1",
        &[&(owner as i32)],
    ).await?.context("Not linked to OpenSim")?;
    let uuid: String = row.get(0);
    let data = bridge_write(cfg, "vaak_daily_claim", serde_json::json!({"avatar_uuid": uuid})).await?;
    let player = data.get("player").filter(|v| v.is_object()).cloned()
        .context("OpenSim claim succeeded but returned no player")?;
    let plan = normalize_opensim_player(&player);
    let strain = if plan.strain.is_empty() { None } else { Some(plan.strain.as_str()) };
    let tx = client.transaction().await?;
    let updated = tx.execute(
        "UPDATE phyrian_players
         SET status=$1, strain=$2, resonance=$3, generation=$4, level=$5,
             banked_resonance=$6, resonance_exchanges=$7, inductions_given=$8,
             lineage_depth=$9, daily_resonance_decay=$10,
             last_checkin_at=NOW(), last_decay_at=NOW(), updated_at=NOW()
         WHERE owner_user_id=$11",
        &[
            &plan.status, &strain, &(plan.resonance as i32), &(plan.generation as i32),
            &(plan.level as i32), &(plan.banked_resonance as i32),
            &(plan.resonance_exchanges as i32), &(plan.inductions_given as i32),
            &(plan.lineage_depth as i32),
            &(int_field(&player, "daily_resonance_decay").unwrap_or(DAILY_DECAY).max(0) as i32),
            &(owner as i32),
        ],
    ).await?;
    if updated == 0 { anyhow::bail!("Linked player row not found"); }
    tx.commit().await?;
    Ok(serde_json::json!({
        "ok": true,
        "resonance": plan.resonance,
        "granted": data.get("granted").cloned().unwrap_or(Value::from(0)),
        "message": data.get("message").cloned().unwrap_or(Value::String(String::new())),
        "player": player,
    }))
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
                    COALESCE(p.daily_resonance_decay, 5),
                    EXISTS (SELECT 1 FROM phyrian_bridge_links l
                            WHERE l.owner_user_id = u.id
                              AND l.status = 'verified' AND l.unlinked_at IS NULL),
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
    let stored_resonance: i64 = i64::from(row.try_get::<_, i32>(5).unwrap_or(0));
    let daily_decay: i64 = i64::from(row.try_get::<_, i32>(9).unwrap_or(DAILY_DECAY as i32)).max(0);
    let linked: bool = row.try_get(10).unwrap_or(false);
    let age_secs: i64 = row.try_get(15).unwrap_or(0);
    let decay_secs: i64 = row.try_get(16).unwrap_or(0);
    let decay_days = if imprinted && age_secs >= DECAY_GRACE_SECS {
        (decay_secs.max(0) / 86_400).max(0)
    } else {
        0
    };
    // Projection only: PHP remains responsible for persisting lazy decay.
    let (resonance, applied) = if linked {
        (stored_resonance, 0)
    } else {
        projected_decay(&status, &strain, stored_resonance, age_secs, decay_days * 86_400, daily_decay)
    };

    let player = Player {
        status: status.clone(),
        strain: if imprinted { strain.clone() } else { String::new() },
        resonance,
        generation: i64::from(row.try_get::<_, i32>(6).unwrap_or(1)),
        level: i64::from(row.try_get::<_, i32>(7).unwrap_or(1)),
        imprinted_by_owner_id: row.try_get::<_, Option<i32>>(8).unwrap_or(None).map(i64::from),
        imprinted_at: row.try_get(9).unwrap_or_default(),
        last_checkin_at: row.try_get(10).unwrap_or_default(),
        last_decay_at: row.try_get(11).unwrap_or_default(),
        stability: stability(imprinted, resonance),
        decay_applied_in_projection: applied,
        daily_resonance_decay: daily_decay,
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
            from_owner_id: i64::from(r.try_get::<_, i32>(2).unwrap_or(0)),
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
                let resonance: i64 = i64::from(r.try_get::<_, i32>(5).unwrap_or(0));
                let generation: i64 = i64::from(r.try_get::<_, i32>(6).unwrap_or(1));
                DirectoryEntry { id: r.try_get(0).unwrap_or(0), username: r.try_get(1).unwrap_or_default(), actor_key: r.try_get(2).unwrap_or_default(), status: s, strain, resonance, generation, stability: stability(imprinted, resonance) }
            })
            .collect()
    } else { Vec::new() };

    Ok(Projection { owner_user_id: owner, username: if username.is_empty() { actor_key.clone() } else { username }, actor_id, player, pending_requests, directory, source: "vaak-worker-phyrian-read", mutation_owner: "php" })
}

fn stability(imprinted: bool, resonance: i64) -> String {
    if !imprinted { return "Unmarked".into(); }
    match resonance { r if r <= 0 => "Dormant", r if r < 25 => "Critical", r if r < 50 => "Fading", _ => "Stable" }.into()
}

fn projected_decay(status: &str, strain: &str, resonance: i64, age_secs: i64, decay_secs: i64, daily_decay: i64) -> (i64, i64) {
    let imprinted = status == "imprinted" && !strain.trim().is_empty();
    if !imprinted || age_secs < DECAY_GRACE_SECS { return (resonance, 0); }
    let days = (decay_secs.max(0) / 86_400).max(0);
    let amount = (days * daily_decay.max(0)).min(resonance.max(0));
    ((resonance - amount).max(0), amount)
}

#[cfg(test)]
mod tests {
    use super::{bridge_request_payload, configured_origin_owner, decode_bridge_response, identify_kind, normalize_opensim_player, pick_origin_strain, plan_request_offer, projected_decay, resolve_peer_imprint, stability, OpenSimPlayerPlan, ORIGIN_STRAINS, DAILY_DECAY};
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
            let (resonance, decay) = projected_decay(&case.status, &case.strain, case.resonance, case.age_secs, case.decay_secs, DAILY_DECAY);
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
    fn mocked_bridge_responses_match_php_contract() {
        let ok = decode_bridge_response(200, r#"{"ok":true,"player":{"resonance":94}}"#);
        assert_eq!(ok["ok"], true);
        assert_eq!(ok["data"]["player"]["resonance"], 94);
        assert_eq!(decode_bridge_response(200, r#"{"ok":false,"error":"Already checked in today"}"#)["error"], "Already checked in today");
        assert_eq!(decode_bridge_response(502, r#"{"message":"upstream unavailable"}"#)["error"], "upstream unavailable");
        assert_eq!(decode_bridge_response(200, "")["error"], "Empty response from strains API.");
        assert_eq!(decode_bridge_response(200, "not-json")["error"], "Invalid JSON from strains API.");
    }

    #[test]
    fn bridge_mutation_fixtures_match_php_envelopes_and_errors() {
        let cases: Vec<serde_json::Value> = serde_json::from_str(
            include_str!("../fixtures/phyrian/bridge-mutations.json"),
        ).expect("bridge mutation fixtures");
        for case in cases {
            let action = case["action"].as_str().expect("fixture action");
            let body = case["body"].clone();
            let envelope = bridge_request_payload(action, &body, "fixture-secret");
            assert_eq!(envelope["action"], action);
            assert_eq!(envelope["bridge_secret"], "fixture-secret");
            for key in body.as_object().expect("fixture body").keys() {
                assert_eq!(envelope[key], body[key]);
            }
            let ok = decode_bridge_response(200, &case["response_ok"].to_string());
            assert_eq!(ok["ok"], true);
            let already = decode_bridge_response(409, &case["response_already"].to_string());
            assert_eq!(already["ok"], false);
            assert!(already["error"].as_str().is_some());
        }
        assert_eq!(decode_bridge_response(503, r#"{"message":"bridge unavailable"}"#)["error"], "bridge unavailable");
        assert_eq!(decode_bridge_response(200, "")["error"], "Empty response from strains API.");
        assert_eq!(decode_bridge_response(200, "{}") ["error"], "Strains HTTP 200");
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
    fn origin_roll_uses_the_shared_catalog() {
        assert_eq!(configured_origin_owner(), 1);
        for _ in 0..32 {
            assert!(ORIGIN_STRAINS.contains(&pick_origin_strain().as_str()));
        }
    }

    #[test]
    fn origin_catalog_order_matches_php_fixture_shape() {
        assert_eq!(ORIGIN_STRAINS.len(), 65);
        assert_eq!(ORIGIN_STRAINS.first(), Some(&"Cosmic Alien"));
        assert_eq!(ORIGIN_STRAINS[55], "Phyrian");
        assert_eq!(ORIGIN_STRAINS.last(), Some(&"Sable Current"));
    }

    #[test]
    fn bridge_identify_inputs_match_php_url_and_name_rules() {
        assert_eq!(
            identify_kind("https://strains.novalandia.online/?uuid=803bdca1-f996-4620-bea8-6177e1c3dfbe&foo=1"),
            Some(("uuid", "803bdca1-f996-4620-bea8-6177e1c3dfbe".to_string()))
        );
        assert_eq!(identify_kind("Val3r1e.Flux"), Some(("name", "Val3r1e Flux".to_string())));
        assert!(identify_kind("bad/name").is_none());
        assert!(identify_kind("@bad").is_none());
    }
}
