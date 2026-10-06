//! Read-only contract for the remaining You integration panels.
//!
//! This is intentionally a capability manifest, not a settings or secrets
//! endpoint. PHP owns Security, Import/Export, and Phyrian writes (including
//! CSRF, password/2FA, OAuth, migration, and OpenSim bridge operations).

use anyhow::{Context, Result};
use serde::Serialize;

#[derive(Debug, Serialize)]
pub struct IntegrationProjection {
    pub owner_id: i64,
    pub actor_key: String,
    pub panels: Vec<Panel>,
    pub source: &'static str,
    pub note: &'static str,
}

#[derive(Debug, Serialize)]
pub struct Panel {
    pub id: &'static str,
    pub read_only: bool,
    pub mutation_owner: &'static str,
    pub csrf_owner: &'static str,
    pub sensitive_data_exposed: bool,
    pub route: &'static str,
}

pub async fn project(
    cfg: &crate::config::Config,
    owner_id: i64,
) -> Result<IntegrationProjection> {
    if owner_id < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let db = crate::db::connect(&cfg.database_url)
        .await
        .context("connect integration projection")?;
    let row = db
        .query_opt(
            "SELECT actor_key FROM ap_users WHERE id = $1 AND disabled_at IS NULL",
            &[&owner_id],
        )
        .await
        .context("load integration owner")?;
    let Some(row) = row else {
        anyhow::bail!("integration owner not found");
    };
    let actor_key: String = row.try_get(0).context("integration actor key")?;

    Ok(IntegrationProjection {
        owner_id,
        actor_key,
        panels: vec![
            Panel {
                id: "security",
                read_only: true,
                mutation_owner: "php",
                csrf_owner: "php",
                sensitive_data_exposed: false,
                route: "?view=security",
            },
            Panel {
                id: "import_export",
                read_only: true,
                mutation_owner: "php",
                csrf_owner: "php",
                sensitive_data_exposed: false,
                route: "?view=import_export",
            },
            Panel {
                id: "phyrian",
                read_only: true,
                mutation_owner: "php/opensim",
                csrf_owner: "php",
                sensitive_data_exposed: false,
                route: "?view=phyrian",
            },
        ],
        source: "vaak-worker-shadow",
        note: "Capability manifest only; PHP remains renderer, CSRF, secret, and mutation owner.",
    })
}
