//! Redis helpers for shadow keys only.

use anyhow::{Context, Result};
use redis::AsyncCommands;

pub async fn connect(redis_url: &str) -> Result<redis::aio::MultiplexedConnection> {
    let client = redis::Client::open(redis_url).context("parse redis url")?;
    client
        .get_multiplexed_async_connection()
        .await
        .context("connect redis")
}

pub async fn json_set(
    conn: &mut redis::aio::MultiplexedConnection,
    key: &str,
    value: &serde_json::Value,
    ttl_secs: u64,
) -> Result<()> {
    let payload = serde_json::to_string(value)?;
    let _: () = redis::cmd("SET")
        .arg(key)
        .arg(payload)
        .arg("EX")
        .arg(ttl_secs)
        .query_async(conn)
        .await
        .with_context(|| format!("redis SET {key}"))?;
    Ok(())
}

pub async fn json_get(
    conn: &mut redis::aio::MultiplexedConnection,
    key: &str,
) -> Result<Option<serde_json::Value>> {
    let raw: Option<String> = conn.get(key).await.with_context(|| format!("redis GET {key}"))?;
    match raw {
        None => Ok(None),
        Some(s) => Ok(serde_json::from_str(&s).ok()),
    }
}
