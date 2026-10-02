//! Shared config for shadow-mode VAAK workers.

use anyhow::{Context, Result};
use std::collections::HashMap;
use std::env;
use std::fs;
use std::path::{Path, PathBuf};

#[derive(Debug, Clone)]
pub struct Config {
    pub database_url: String,
    pub redis_url: String,
    pub jetstream_state: PathBuf,
    pub default_owner_id: i64,
}

impl Config {
    pub fn load() -> Result<Self> {
        let env_file = env::var("VAAK_ENV_FILE").unwrap_or_else(|_| "/etc/mkultra/vaak.env".to_string());
        if Path::new(&env_file).is_file() {
            load_dotenv_file(&env_file)?;
        }

        let database_url = env::var("VAAK_DATABASE_URL").unwrap_or_else(|_| {
            // Prod PHP uses peer auth via local socket as www-data.
            "postgresql:///novalandia?host=/var/run/postgresql".to_string()
        });
        let redis_url = env::var("VAAK_REDIS_URL").unwrap_or_else(|_| "redis://127.0.0.1/".to_string());
        let jetstream_state = PathBuf::from(
            env::var("VAAK_JETSTREAM_STATE").unwrap_or_else(|_| "/var/lib/mkultra/ap/jetstream".to_string()),
        );
        let default_owner_id = env::var("VAAK_SHADOW_OWNER_ID")
            .ok()
            .and_then(|s| s.parse().ok())
            .unwrap_or(1);

        Ok(Self {
            database_url,
            redis_url,
            jetstream_state,
            default_owner_id,
        })
    }
}

fn load_dotenv_file(path: &str) -> Result<()> {
    let raw = fs::read_to_string(path).with_context(|| format!("read env file {path}"))?;
    for line in raw.lines() {
        let line = line.trim();
        if line.is_empty() || line.starts_with('#') || !line.contains('=') {
            continue;
        }
        let (k, v) = line.split_once('=').unwrap();
        let k = k.trim();
        let v = v.trim().trim_matches(|c| c == '"' || c == '\'');
        if k.is_empty() {
            continue;
        }
        // Do not override an already-exported process env.
        if env::var_os(k).is_none() {
            env::set_var(k, v);
        }
    }
    Ok(())
}

/// Best-effort map of interesting env keys (values redacted in Debug).
pub fn env_snapshot() -> HashMap<String, String> {
    let mut out = HashMap::new();
    for key in [
        "VAAK_DATABASE_URL",
        "VAAK_REDIS_URL",
        "VAAK_JETSTREAM_STATE",
        "VAAK_SHADOW_OWNER_ID",
        "VAAK_JETSTREAM_URL",
        "AP_DB_DSN",
    ] {
        if let Ok(v) = env::var(key) {
            out.insert(key.to_string(), if v.is_empty() { String::new() } else { "***".to_string() });
        }
    }
    out
}
