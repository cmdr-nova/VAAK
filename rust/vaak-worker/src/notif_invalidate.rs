//! Notification cache invalidation after mention rows change (cutover F4).
//!
//! Ports what PHP runs when `mentions` rows are soft-deleted:
//! - `ap_notification_projection_invalidate_all`: bump
//!   `vaak:notifications:epoch:v1:server` (before the projection rows go, so a
//!   hydrate already in flight skips its write-back), delete every
//!   `ap_notification_projection` row (done by the caller inside its
//!   transaction), then drop `vaak:notifications:v1..v4:*`.
//! - `ap_notification_unread_invalidate_owners` /
//!   `ap_masto_notifications_unread_invalidate`: per owner, drop
//!   `vaak:notifications:v1:unread:{owner}:*`,
//!   `vaak:shadow:notifications:v1:unread:{owner}:*` and the
//!   `notif_unread_{owner}_*.json` files in the notification cache directory.
//!
//! All of it is best effort, like PHP: a failure is logged, never returned.

use std::path::Path;

use redis::aio::ConnectionManager;

/// `ap_notification_list_epoch_bump(null)`.
pub(crate) async fn bump_server_epoch(redis: &mut ConnectionManager) {
    let key = "vaak:notifications:epoch:v1:server";
    let r: redis::RedisResult<()> = redis::pipe().incr(key, 1).ignore().expire(key, 86_400 * 30).ignore().query_async(redis).await;
    if let Err(e) = r {
        tracing::warn!(error = %e, "notification epoch bump failed");
    }
}

/// `ap_redis_delete_pattern`: SCAN in batches of 100 and DEL what matches.
pub(crate) async fn delete_pattern(redis: &mut ConnectionManager, pattern: &str) {
    let mut cursor: u64 = 0;
    loop {
        let r: redis::RedisResult<(u64, Vec<String>)> =
            redis::cmd("SCAN").arg(cursor).arg("MATCH").arg(pattern).arg("COUNT").arg(100).query_async(redis).await;
        let (next, keys) = match r {
            Ok(v) => v,
            Err(e) => {
                tracing::warn!(error = %e, pattern, "notification cache pattern delete failed");
                return;
            }
        };
        if !keys.is_empty() {
            let _: redis::RedisResult<()> = redis::cmd("DEL").arg(&keys).query_async(redis).await;
        }
        if next == 0 {
            return;
        }
        cursor = next;
    }
}

/// The Redis side of `ap_notification_projection_invalidate_all`, after the
/// projection rows are gone.
pub(crate) async fn drop_list_caches(redis: &mut ConnectionManager) {
    for v in 1..=4 {
        delete_pattern(redis, &format!("vaak:notifications:v{v}:*")).await;
    }
}

/// `ap_notification_unread_invalidate_owners`.
pub(crate) async fn unread_owners(redis: &mut ConnectionManager, cache_dir: &Path, owners: &[i64]) {
    let mut seen: Vec<i64> = Vec::new();
    for &owner in owners {
        if owner < 1 || seen.contains(&owner) {
            continue;
        }
        seen.push(owner);
        delete_pattern(redis, &format!("vaak:notifications:v1:unread:{owner}:*")).await;
        delete_pattern(redis, &format!("vaak:shadow:notifications:v1:unread:{owner}:*")).await;
        remove_unread_files(cache_dir, owner);
    }
}

/// `glob($dir . '/notif_unread_' . $owner . '_*.json')`, unlinked.
fn remove_unread_files(dir: &Path, owner: i64) {
    let prefix = format!("notif_unread_{owner}_");
    let Ok(entries) = std::fs::read_dir(dir) else { return };
    for entry in entries.flatten() {
        let name = entry.file_name();
        let Some(name) = name.to_str() else { continue };
        if unread_file_matches(name, &prefix) {
            let _ = std::fs::remove_file(entry.path());
        }
    }
}

fn unread_file_matches(name: &str, prefix: &str) -> bool {
    name.starts_with(prefix) && name.ends_with(".json")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn unread_files_are_per_owner() {
        let p = "notif_unread_7_";
        assert!(unread_file_matches("notif_unread_7_abc.json", p));
        assert!(!unread_file_matches("notif_unread_77_abc.json", p));
        assert!(!unread_file_matches("notif_unread_7_abc.json.tmp", p));
        assert!(!unread_file_matches("other_7_abc.json", p));
    }

    #[test]
    fn removes_only_matching_files() {
        let dir = std::env::temp_dir().join(format!("vaak-notif-inv-{}", std::process::id()));
        std::fs::create_dir_all(&dir).unwrap();
        for n in ["notif_unread_3_a.json", "notif_unread_3_b.json", "notif_unread_33_a.json", "keep.json"] {
            std::fs::write(dir.join(n), "{}").unwrap();
        }
        remove_unread_files(&dir, 3);
        let mut left: Vec<String> = std::fs::read_dir(&dir).unwrap().flatten().map(|e| e.file_name().to_string_lossy().into_owned()).collect();
        left.sort();
        assert_eq!(left, vec!["keep.json", "notif_unread_33_a.json"]);
        let _ = std::fs::remove_dir_all(&dir);
    }
}
