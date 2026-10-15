//! Owner/post observation writes shared with PHP `ap_bsky_post_observation_touch`
//! and `ap_bsky_post_observations_refresh_batch`.
//!
//! A row whose `last_seen_at` is inside the fresh window is not rewritten.
//! The bulk statement only considers posts seen inside that window, in batches,
//! and the conflict `WHERE` drops a no-op update. PHP CLI calls the bulk
//! statement. Rust upserts call [`touch`].

use anyhow::{Context, Result};
use chrono::{DateTime, Duration, SecondsFormat, Utc};
use tokio_postgres::Client;

pub const OBSERVATION_FRESH_SECS: i64 = 600;

pub const TOUCH_SQL: &str = "\
INSERT INTO bsky_post_observations (bsky_uri, owner_user_id, first_seen_at, last_seen_at)
VALUES ($1, $2, $3, $3)
ON CONFLICT (bsky_uri, owner_user_id) DO UPDATE
SET last_seen_at = excluded.last_seen_at
WHERE bsky_post_observations.last_seen_at < $4";

/// One page of the PHP `INSERT … SELECT … LIMIT` sync. `$1` is the stamp,
/// `$2` the cutoff, `$3` the exclusive uri cursor, `$4` the batch size.
pub const REFRESH_SQL: &str = "\
INSERT INTO bsky_post_observations (bsky_uri, owner_user_id, first_seen_at, last_seen_at)
SELECT p.bsky_uri, p.owner_user_id, COALESCE(p.seen_at, $1), COALESCE(p.seen_at, $1)
FROM bsky_posts p
WHERE p.owner_user_id IS NOT NULL
  AND p.seen_at >= $2
  AND p.bsky_uri > $3
  AND NOT EXISTS (
    SELECT 1 FROM bsky_post_observations o
    WHERE o.bsky_uri = p.bsky_uri
      AND o.owner_user_id = p.owner_user_id
      AND o.last_seen_at >= $2
  )
ORDER BY p.bsky_uri
LIMIT $4
ON CONFLICT (bsky_uri, owner_user_id) DO UPDATE
SET last_seen_at = excluded.last_seen_at
WHERE bsky_post_observations.last_seen_at < excluded.last_seen_at
  AND bsky_post_observations.last_seen_at < $2
RETURNING bsky_uri";

/// Match PHP `gmdate('c')` in UTC: `2026-10-15T23:30:00+00:00`.
pub fn observation_stamp(when: DateTime<Utc>) -> String {
    when.to_rfc3339_opts(SecondsFormat::Secs, true)
        .replace('Z', "+00:00")
}

pub async fn touch(db: &Client, uri: &str, owner: i64) -> Result<u64> {
    if owner < 1 || !uri.starts_with("at://") {
        return Ok(0);
    }
    let instant = Utc::now();
    let now = observation_stamp(instant);
    let cutoff = observation_stamp(instant - Duration::seconds(OBSERVATION_FRESH_SECS));
    db.execute(TOUCH_SQL, &[&uri, &owner, &now, &cutoff])
        .await
        .context("touch bsky_post_observations")
}

/// Batch the same way PHP `ap_bsky_post_observations_refresh_batch` does.
/// Returns the written uris so the caller can advance the cursor.
pub async fn refresh_batch(db: &Client, after_uri: &str, limit: i64) -> Result<Vec<String>> {
    let instant = Utc::now();
    let now = observation_stamp(instant);
    let cutoff = observation_stamp(instant - Duration::seconds(OBSERVATION_FRESH_SECS));
    let limit = limit.clamp(1, 500);
    let rows = db
        .query(REFRESH_SQL, &[&now, &cutoff, &after_uri, &limit])
        .await
        .context("refresh bsky_post_observations")?;
    let mut uris = Vec::with_capacity(rows.len());
    for row in rows {
        let uri: String = row.get(0);
        if !uri.is_empty() {
            uris.push(uri);
        }
    }
    uris.sort();
    Ok(uris)
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn stamp_matches_php_gmdate_c() {
        let when = DateTime::parse_from_rfc3339("2026-10-15T23:30:00+00:00")
            .unwrap()
            .with_timezone(&Utc);
        assert_eq!(observation_stamp(when), "2026-10-15T23:30:00+00:00");
    }

    #[test]
    fn touch_and_refresh_skip_fresh_rows() {
        assert_eq!(OBSERVATION_FRESH_SECS, 600);
        for sql in [TOUCH_SQL, REFRESH_SQL] {
            assert!(sql.contains("ON CONFLICT (bsky_uri, owner_user_id) DO UPDATE"));
            assert!(sql.contains("SET last_seen_at = excluded.last_seen_at"));
            assert!(sql.contains("bsky_post_observations.last_seen_at <"));
        }
        assert!(REFRESH_SQL.contains("p.seen_at >= $2"));
        assert!(REFRESH_SQL.contains("p.bsky_uri > $3"));
        assert!(REFRESH_SQL.contains("LIMIT $4"));
        assert!(REFRESH_SQL.contains("NOT EXISTS"));
        assert!(REFRESH_SQL.contains("RETURNING bsky_uri"));
        assert!(TOUCH_SQL.contains("WHERE bsky_post_observations.last_seen_at < $4"));
        let _ = refresh_batch;
    }
}
