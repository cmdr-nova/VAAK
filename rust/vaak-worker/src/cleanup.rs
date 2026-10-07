//! Cleanup decisions shared with the PHP maintenance gate.
#![allow(dead_code)]
//!
//! PHP still owns the daily cron, account-delete worker, and R2 deletes.
//! This module only decides which rows and object keys those jobs may touch.

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct RetentionJob {
    pub table: &'static str,
    pub time_column: &'static str,
    pub states: &'static [&'static str],
}

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum MediaKeyDecision {
    Cache,
    Local,
    Refuse,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct ArchiveRow {
    pub id: String,
    pub status: String,
    pub occurred_at: String,
    pub owner_user_id: String,
    pub actor_ref: String,
}

pub fn queue_retention_plan() -> &'static [RetentionJob] {
    &[
        RetentionJob {
            table: "ap_publish_delivery_queue",
            time_column: "updated_at",
            states: &["succeeded", "failed"],
        },
        RetentionJob {
            table: "ap_fanout_delivery_queue",
            time_column: "updated_at",
            states: &["succeeded", "failed"],
        },
        RetentionJob {
            table: "ap_media_warm_queue",
            time_column: "updated_at",
            states: &["succeeded", "failed"],
        },
        RetentionJob {
            table: "bsky_actor_refresh_queue",
            time_column: "queued_at",
            states: &["succeeded", "failed"],
        },
    ]
}

fn archive_table_allowed(table: &str) -> bool {
    matches!(
        table,
        "ap_publish_delivery_queue"
            | "ap_fanout_delivery_queue"
            | "ap_media_warm_queue"
            | "bsky_actor_refresh_queue"
            | "events"
    )
}

/// Same predicate as the retention DELETE: status first, then the age cutoff.
/// An empty status list keeps the events path, which filters on time only.
pub fn row_is_expired(status: &str, occurred_at: &str, states: &[&str], cutoff: &str) -> bool {
    let status_ok = states.is_empty() || states.iter().any(|state| *state == status);
    status_ok && occurred_at < cutoff
}

pub fn archive_source_id(row: &ArchiveRow) -> Option<String> {
    if !row.id.is_empty() {
        return Some(row.id.clone());
    }
    if !row.owner_user_id.is_empty() && !row.actor_ref.is_empty() {
        return Some(format!("{}:{}", row.owner_user_id, row.actor_ref));
    }
    None
}

/// Ids that would be cold-archived before the matching delete. Unknown tables
/// return nothing. The order follows the input rows.
pub fn archive_source_ids(table: &str, rows: &[ArchiveRow], states: &[&str], cutoff: &str) -> Vec<String> {
    if !archive_table_allowed(table) {
        return Vec::new();
    }
    rows.iter()
        .filter(|row| row_is_expired(&row.status, &row.occurred_at, states, cutoff))
        .filter_map(archive_source_id)
        .collect()
}

fn normalize_object_key(key: &str, prefix: &str) -> String {
    let key = if !prefix.is_empty() && !key.starts_with(prefix) {
        format!("{}{}", prefix.trim_start_matches('/'), key.trim_start_matches('/'))
    } else {
        key.to_string()
    };
    key.trim_start_matches('/').to_string()
}

pub fn cleanup_key_decision(key: &str, allow_local_media: bool) -> MediaKeyDecision {
    let key = normalize_object_key(key, "mkultra/");
    if key.is_empty() || key.contains("..") {
        return MediaKeyDecision::Refuse;
    }
    if key.starts_with("mkultra/cache/") {
        return MediaKeyDecision::Cache;
    }
    if allow_local_media && key.starts_with("mkultra/media/") {
        return MediaKeyDecision::Local;
    }
    MediaKeyDecision::Refuse
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct RelationshipRow {
    pub owner: String,
    pub other: String,
}

/// Followers and following rows that remain after removing one actor.
/// A row goes when either side is the deleted actor.
pub fn relationships_kept(rows: &[RelationshipRow], gone_actor: &str) -> Vec<RelationshipRow> {
    rows.iter()
        .filter(|row| row.owner != gone_actor && row.other != gone_actor)
        .cloned()
        .collect()
}

pub fn profiles_kept(keys: &[String], gone_key: &str) -> Vec<String> {
    keys.iter()
        .filter(|key| key.as_str() != gone_key)
        .cloned()
        .collect()
}

/// Prints the retention plan. This command never deletes.
pub fn shadow_report(execute: bool) -> Result<String, &'static str> {
    if execute {
        return Err("PHP still owns cleanup. Refusing to delete.");
    }
    let jobs: Vec<String> = queue_retention_plan()
        .iter()
        .map(|job| {
            format!(
                "{} {} states={}",
                job.table,
                job.time_column,
                job.states.join(",")
            )
        })
        .collect();
    Ok(format!(
        "owner=php execute=refused jobs={}",
        jobs.join("; ")
    ))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(
        id: &str,
        status: &str,
        at: &str,
        owner: &str,
        actor: &str,
    ) -> ArchiveRow {
        ArchiveRow {
            id: id.to_string(),
            status: status.to_string(),
            occurred_at: at.to_string(),
            owner_user_id: owner.to_string(),
            actor_ref: actor.to_string(),
        }
    }

    #[test]
    fn cleanup_gate_matches_php_rollback() {
        let cases = [
            ("cache/post-media/abc.jpg", false, MediaKeyDecision::Cache),
            ("mkultra/cache/avatars/actor.webp", false, MediaKeyDecision::Cache),
            ("mkultra/media/local.jpg", false, MediaKeyDecision::Refuse),
            ("mkultra/media/local.jpg", true, MediaKeyDecision::Local),
            (
                "mkultra/media/cache/post-media/hidden.jpg",
                false,
                MediaKeyDecision::Refuse,
            ),
            ("mkultra/cache/../media/secret.jpg", false, MediaKeyDecision::Refuse),
            ("", false, MediaKeyDecision::Refuse),
        ];
        for (key, allow_local, expect) in cases {
            assert_eq!(cleanup_key_decision(key, allow_local), expect, "{key}");
        }

        let tables: Vec<&str> = queue_retention_plan().iter().map(|job| job.table).collect();
        for required in [
            "ap_publish_delivery_queue",
            "ap_fanout_delivery_queue",
            "ap_media_warm_queue",
            "bsky_actor_refresh_queue",
        ] {
            assert!(tables.contains(&required), "missing {required}");
        }

        let old = "2026-09-01T00:00:00+00:00";
        let fresh = "2026-10-07T00:00:00+00:00";
        let cutoff = "2026-09-30T00:00:00+00:00";
        let states = ["succeeded", "failed"];
        let publish = [
            row("1", "succeeded", old, "", ""),
            row("2", "failed", old, "", ""),
            row("3", "pending", old, "", ""),
            row("4", "succeeded", fresh, "", ""),
        ];
        let bsky = [
            row("", "succeeded", old, "9", "did:plc:old"),
            row("", "pending", old, "9", "did:plc:pending"),
        ];
        let users = [row("1", "succeeded", old, "", "")];

        assert_eq!(
            archive_source_ids("ap_publish_delivery_queue", &publish, &states, cutoff),
            vec!["1".to_string(), "2".to_string()]
        );
        assert_eq!(
            archive_source_ids("bsky_actor_refresh_queue", &bsky, &states, cutoff),
            vec!["9:did:plc:old".to_string()]
        );
        assert!(archive_source_ids("ap_users", &users, &states, cutoff).is_empty());

        let kept_publish: Vec<&str> = publish
            .iter()
            .filter(|row| !row_is_expired(&row.status, &row.occurred_at, &states, cutoff))
            .map(|row| row.id.as_str())
            .collect();
        assert_eq!(kept_publish, vec!["3", "4"]);

        let gone = "https://mkultra.monster/users/gone";
        let kept = "https://mkultra.monster/users/kept";
        let other = "https://mkultra.monster/users/other";
        let followers = vec![
            RelationshipRow { owner: gone.into(), other: other.into() },
            RelationshipRow { owner: kept.into(), other: other.into() },
        ];
        let following = followers.clone();
        assert_eq!(
            relationships_kept(&followers, gone),
            vec![RelationshipRow { owner: kept.into(), other: other.into() }]
        );
        assert_eq!(relationships_kept(&following, gone).len(), 1);
        assert_eq!(
            profiles_kept(&["gone".into(), "kept".into()], "gone"),
            vec!["kept".to_string()]
        );
        assert_eq!(
            shadow_report(true),
            Err("PHP still owns cleanup. Refusing to delete.")
        );
        assert!(shadow_report(false).unwrap().contains("owner=php"));
    }
}
