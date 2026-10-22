//! Redis helpers (cache DB + queue DB).

use anyhow::{Context, Result};
use redis::AsyncCommands;

pub async fn connect(redis_url: &str) -> Result<redis::aio::MultiplexedConnection> {
    let client = redis::Client::open(redis_url).context("parse redis url")?;
    // redis 1.0 defaults these to 1s and 500ms. 0.27 left both unset, and
    // queue workers block in BRPOP for 5s. Restore the unlimited timeouts.
    let config = redis::AsyncConnectionConfig::new()
        .set_connection_timeout(None)
        .set_response_timeout(None);
    client
        .get_multiplexed_async_connection_with_config(&config)
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

/// PHP `ap_redis_delete_pattern`: SCAN in batches of 100 and DEL each batch.
pub async fn delete_pattern(conn: &mut redis::aio::MultiplexedConnection, pattern: &str) -> Result<usize> {
    let mut cursor: u64 = 0;
    let mut deleted = 0usize;
    loop {
        let (next, keys): (u64, Vec<String>) = redis::cmd("SCAN")
            .arg(cursor)
            .arg("MATCH")
            .arg(pattern)
            .arg("COUNT")
            .arg(100)
            .query_async(conn)
            .await
            .with_context(|| format!("redis SCAN {pattern}"))?;
        if !keys.is_empty() {
            let n: usize = redis::cmd("DEL").arg(&keys).query_async(conn).await.unwrap_or(0);
            deleted += n;
        }
        if next == 0 {
            return Ok(deleted);
        }
        cursor = next;
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

/// Non-blocking RPOP from `vaak:queue:{queue}`.
pub async fn queue_rpop(
    conn: &mut redis::aio::MultiplexedConnection,
    queue: &str,
) -> Result<Option<String>> {
    let key = format!("vaak:queue:{queue}");
    let item: Option<String> = conn.rpop(&key, None).await.with_context(|| format!("RPOP {key}"))?;
    Ok(item)
}

pub async fn queue_llen(conn: &mut redis::aio::MultiplexedConnection, queue: &str) -> Result<i64> {
    let key = format!("vaak:queue:{queue}");
    let n: i64 = conn.llen(&key).await.unwrap_or(0);
    Ok(n)
}

#[cfg(test)]
mod tests {
    use super::*;
    use redis::AsyncCommands;
    use std::time::{Duration, Instant};

    fn redis_test_url() -> Option<String> {
        match std::env::var("VAAK_TEST_REDIS_URL") {
            Ok(url) if !url.trim().is_empty() => Some(url),
            _ if std::env::var_os("CI").is_some() => {
                panic!("VAAK_TEST_REDIS_URL is required when CI is set");
            }
            _ => None,
        }
    }

    /// Commands the worker actually sends, against a real Redis.
    /// `VAAK_TEST_REDIS_URL` points at a disposable database (CI uses DB 15).
    #[tokio::test]
    async fn production_commands_round_trip_on_redis() {
        let Some(url) = redis_test_url() else {
            return;
        };
        let mut conn = connect(&url).await.expect("connect");
        let prefix = format!(
            "vaak:redis17:{}:{}",
            std::process::id(),
            std::time::SystemTime::now()
                .duration_since(std::time::UNIX_EPOCH)
                .expect("clock")
                .as_nanos()
        );
        let outcome = exercise(&mut conn, &prefix).await;
        let _ = delete_pattern(&mut conn, &format!("{prefix}*")).await;
        // Lock and queue helpers store keys outside the test prefix.
        let _: Result<i64, _> = redis::cmd("DEL")
            .arg(format!("vaak:lock:{prefix}:lock"))
            .arg(format!("vaak:queue:{prefix}-q"))
            .query_async(&mut conn)
            .await;
        outcome.expect("redis command exercise");
    }

    async fn exercise(conn: &mut redis::aio::MultiplexedConnection, prefix: &str) -> Result<()> {
        let key = format!("{prefix}:json");
        let value = serde_json::json!({"n": 1, "s": "ok"});
        json_set(conn, &key, &value, 60).await?;
        assert_eq!(json_get(conn, &key).await?, Some(value));
        assert_eq!(json_get(conn, &format!("{prefix}:missing")).await?, None);
        let ttl: i64 = redis::cmd("TTL").arg(&key).query_async(conn).await?;
        assert!(ttl > 0 && ttl <= 60, "ttl {ttl}");

        let lock_name = format!("{prefix}:lock");
        assert!(lock(conn, &lock_name, 30, "holder-a").await?);
        assert!(!lock(conn, &lock_name, 30, "holder-b").await?);
        unlock(conn, &lock_name, "holder-b").await?;
        assert!(!lock(conn, &lock_name, 30, "holder-b").await?);
        unlock(conn, &lock_name, "holder-a").await?;
        assert!(lock(conn, &lock_name, 30, "holder-a").await?);

        let nx = format!("{prefix}:nx");
        let first: Option<String> = redis::cmd("SET")
            .arg(&nx)
            .arg("holder-a")
            .arg("NX")
            .arg("EX")
            .arg(30u64)
            .query_async(conn)
            .await?;
        assert_eq!(first.as_deref(), Some("OK"));
        let second: Option<String> = redis::cmd("SET")
            .arg(&nx)
            .arg("holder-b")
            .arg("NX")
            .arg("EX")
            .arg(30u64)
            .query_async(conn)
            .await?;
        assert_eq!(second, None);

        let exists_key = format!("{prefix}:exists");
        let _: () = redis::cmd("SET")
            .arg(&exists_key)
            .arg("1")
            .arg("EX")
            .arg(30u64)
            .query_async(conn)
            .await?;
        let present: i64 = redis::cmd("EXISTS").arg(&exists_key).query_async(conn).await?;
        let absent: i64 = redis::cmd("EXISTS")
            .arg(format!("{prefix}:nope"))
            .query_async(conn)
            .await?;
        assert!(present > 0 && absent == 0);

        let scan_a = format!("{prefix}:scan:a");
        let scan_b = format!("{prefix}:scan:b");
        let keep = format!("{prefix}:keep");
        for k in [&scan_a, &scan_b, &keep] {
            json_set(conn, k, &serde_json::json!("x"), 60).await?;
        }
        let removed = delete_pattern(conn, &format!("{prefix}:scan:*")).await?;
        assert!(removed >= 2, "deleted {removed}");
        assert!(json_get(conn, &scan_a).await?.is_none());
        assert!(json_get(conn, &keep).await?.is_some());

        let matched: Vec<String> = redis::cmd("KEYS")
            .arg(format!("{prefix}:keep"))
            .query_async(conn)
            .await?;
        assert_eq!(matched, vec![keep]);

        let counter = format!("{prefix}:counter");
        let n: i64 = redis::cmd("INCR").arg(&counter).query_async(conn).await?;
        let _: i64 = redis::cmd("EXPIRE").arg(&counter).arg(30i64).query_async(conn).await?;
        assert_eq!(n, 1);
        let by: i64 = redis::cmd("INCRBY")
            .arg(format!("{prefix}:by"))
            .arg(1i64)
            .query_async(conn)
            .await?;
        let _: i64 = redis::cmd("EXPIRE")
            .arg(format!("{prefix}:by"))
            .arg(30i64)
            .query_async(conn)
            .await?;
        assert_eq!(by, 1);

        let hash = format!("{prefix}:hash");
        let _: redis::Value = redis::cmd("HINCRBY")
            .arg(&hash)
            .arg("count")
            .arg(1i64)
            .query_async(conn)
            .await?;
        let _: redis::Value = redis::cmd("HINCRBYFLOAT")
            .arg(&hash)
            .arg("total_ms")
            .arg("1.500")
            .query_async(conn)
            .await?;
        let max: Option<String> = redis::cmd("HGET")
            .arg(&hash)
            .arg("max_ms")
            .query_async(conn)
            .await?;
        assert_eq!(max, None);
        let _: redis::Value = redis::cmd("HSET")
            .arg(&hash)
            .arg("max_ms")
            .arg("1.500")
            .query_async(conn)
            .await?;
        let _: i64 = redis::cmd("EXPIRE").arg(&hash).arg(30i64).query_async(conn).await?;

        let queue = format!("{prefix}-q");
        queue_push(conn, &queue, "first").await?;
        queue_push(conn, &queue, "second").await?;
        assert_eq!(queue_llen(conn, &queue).await?, 2);
        assert_eq!(queue_rpop(conn, &queue).await?, Some("first".to_string()));
        let popped = queue_brpop(conn, &queue, 1.0).await?;
        assert_eq!(popped.as_deref(), Some("second"));
        let started = Instant::now();
        assert_eq!(queue_brpop(conn, &queue, 0.8).await?, None);
        let waited = started.elapsed();
        assert!(
            waited >= Duration::from_millis(700),
            "BRPOP returned in {waited:?}; a 500ms client timeout would have failed it"
        );

        let sample = format!("{prefix}:samples");
        let _: i64 = redis::cmd("LPUSH")
            .arg(&sample)
            .arg("1.500")
            .query_async(conn)
            .await?;
        let _: redis::Value = redis::cmd("LTRIM")
            .arg(&sample)
            .arg(0i64)
            .arg(255i64)
            .query_async(conn)
            .await?;
        let _: i64 = redis::cmd("EXPIRE").arg(&sample).arg(30i64).query_async(conn).await?;

        let embed_a = format!("{prefix}:embed:a");
        let embed_b = format!("{prefix}:embed:b");
        crate::notif_embed::set_embed_html_many(conn, &[]).await?;
        crate::notif_embed::set_embed_html_many(
            conn,
            &[(embed_a.clone(), "<p>a</p>".into()), (embed_b.clone(), "<p>b</p>".into())],
        )
        .await?;
        let html: Option<String> = conn.get(&embed_a).await?;
        assert_eq!(html.as_deref(), Some("<p>a</p>"));
        let html: Option<String> = conn.get(&embed_b).await?;
        assert_eq!(html.as_deref(), Some("<p>b</p>"));

        let del_a = format!("{prefix}:del:a");
        let del_b = format!("{prefix}:del:b");
        json_set(conn, &del_a, &serde_json::json!(1), 60).await?;
        json_set(conn, &del_b, &serde_json::json!(1), 60).await?;
        let deleted: i64 = redis::cmd("DEL")
            .arg(&del_a)
            .arg(&del_b)
            .query_async(conn)
            .await?;
        assert_eq!(deleted, 2);

        // MultiplexedConnection does not reconnect. A killed socket must
        // surface an error so the fan-out and thin-media loops can dial again.
        let mut other = connect(&std::env::var("VAAK_TEST_REDIS_URL").expect("url")).await?;
        let id: i64 = redis::cmd("CLIENT").arg("ID").query_async(&mut other).await?;
        let killed: i64 = redis::cmd("CLIENT")
            .arg("KILL")
            .arg("ID")
            .arg(id)
            .query_async(conn)
            .await?;
        assert_eq!(killed, 1);
        let after: Result<Option<String>, _> = other.get(format!("{prefix}:missing")).await;
        assert!(after.is_err(), "killed connection returned {after:?}");
        Ok(())
    }
}
