//! Redis helpers (cache DB + queue DB).

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

/// PHP `ap_redis_lock` shape: key `vaak:lock:{name}` SET NX EX.
pub async fn lock(
    conn: &mut redis::aio::MultiplexedConnection,
    name: &str,
    ttl_secs: u64,
    holder: &str,
) -> Result<bool> {
    let key = format!("vaak:lock:{name}");
    let ok: Option<String> = redis::cmd("SET")
        .arg(&key)
        .arg(holder)
        .arg("NX")
        .arg("EX")
        .arg(ttl_secs.max(1))
        .query_async(conn)
        .await
        .with_context(|| format!("redis LOCK {key}"))?;
    Ok(ok.as_deref() == Some("OK"))
}

pub async fn unlock(conn: &mut redis::aio::MultiplexedConnection, name: &str, holder: &str) -> Result<()> {
    let key = format!("vaak:lock:{name}");
    let cur: Option<String> = conn.get(&key).await.ok().flatten();
    if cur.as_deref() == Some(holder) {
        let _: () = conn.del(&key).await.unwrap_or(());
    }
    Ok(())
}

/// PHP `ap_redis_queue_push` → `vaak:queue:{name}` LPUSH + EXPIRE 86400.
pub async fn queue_push(
    conn: &mut redis::aio::MultiplexedConnection,
    queue: &str,
    item: &str,
) -> Result<()> {
    let key = format!("vaak:queue:{queue}");
    let _: () = conn.lpush(&key, item).await.with_context(|| format!("LPUSH {key}"))?;
    let _: () = conn.expire(&key, 86400).await.unwrap_or(());
    Ok(())
}

/// BRPOP one item from `vaak:queue:{queue}`. Returns None on timeout.
pub async fn queue_brpop(
    conn: &mut redis::aio::MultiplexedConnection,
    queue: &str,
    timeout_secs: f64,
) -> Result<Option<String>> {
    let key = format!("vaak:queue:{queue}");
    let row: Option<(String, String)> = redis::cmd("BRPOP")
        .arg(&key)
        .arg(timeout_secs)
        .query_async(conn)
        .await
        .with_context(|| format!("BRPOP {key}"))?;
    Ok(row.map(|(_, item)| item))
}

pub async fn queue_llen(conn: &mut redis::aio::MultiplexedConnection, queue: &str) -> Result<i64> {
    let key = format!("vaak:queue:{queue}");
    let n: i64 = conn.llen(&key).await.unwrap_or(0);
    Ok(n)
}
