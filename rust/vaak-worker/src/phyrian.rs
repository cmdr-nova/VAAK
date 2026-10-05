//! Read-only Phyrian parity projection.
//!
//! PHP remains the write owner for Phyrian in 10.6.  This module deliberately
//! exposes only a stable read model so the Axum implementation can be compared
//! with `ap_phyrian_dossier()` and `ap_phyrian_local_directory()` before any
//! mutation or OpenSim bridge ownership moves.

use anyhow::{Context, Result};
use serde::Serialize;

use crate::{config::Config, db};

const DAILY_DECAY: i64 = 5;
const DECAY_GRACE_SECS: i64 = 86_400;

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

pub async fn dossier(cfg: &Config, owner: i64, include_directory: bool, limit: i64) -> Result<Projection> {
    if owner < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let client = db::connect(&cfg.database_url).await?;
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
    let mut resonance: i64 = row.try_get(5).unwrap_or(0);
    let age_secs: i64 = row.try_get(13).unwrap_or(0);
    let decay_secs: i64 = row.try_get(14).unwrap_or(0);
    let decay_days = if imprinted && age_secs >= DECAY_GRACE_SECS {
        (decay_secs.max(0) / 86_400).max(0)
    } else {
        0
    };
    // Projection only: PHP remains responsible for persisting lazy decay.
    let applied = (decay_days * DAILY_DECAY).min(resonance).max(0);
    resonance -= applied;

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
             WHERE r.to_owner_id = $1 AND r.status = 'pending'
             ORDER BY r.created_at DESC LIMIT 40",
            &[&owner],
        )
        .await
        .context("query pending Phyrian requests")?
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

#[cfg(test)]
mod tests {
    use super::stability;
    #[test] fn php_stability_boundaries_match() {
        assert_eq!(stability(false, 99), "Unmarked");
        assert_eq!(stability(true, 0), "Dormant");
        assert_eq!(stability(true, 24), "Critical");
        assert_eq!(stability(true, 49), "Fading");
        assert_eq!(stability(true, 50), "Stable");
    }
}
