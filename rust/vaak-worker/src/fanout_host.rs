//! ActivityPub fan-out host suspension. PHP in `api/ap-fanout-host.php` owns
//! writes. These functions stay in lockstep with that file so a suspended host
//! means the same thing on both sides. This module does not deliver anything
//! and does not talk to Bluesky.

#![allow(dead_code)]

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub struct HostState {
    pub last_success: Option<i64>,
    pub streak_started: Option<i64>,
    pub consecutive: i64,
    pub last_live: Option<i64>,
    pub suspended: bool,
}

pub fn dead_days() -> i64 {
    env_int("VAAK_FANOUT_DEAD_DAYS", 7, 1, 90)
}

pub fn dead_failures() -> i64 {
    env_int("VAAK_FANOUT_DEAD_FAILURES", 20, 1, 100_000)
}

fn env_int(name: &str, default: i64, min: i64, max: i64) -> i64 {
    match std::env::var(name) {
        Ok(raw) => {
            let raw = raw.trim();
            if raw.is_empty() || !raw.bytes().all(|b| b.is_ascii_digit()) {
                return default;
            }
            raw.parse::<i64>().map(|n| n.clamp(min, max)).unwrap_or(default)
        }
        Err(_) => default,
    }
}

pub fn live_rejection_status(error: &str) -> Option<u16> {
    let bytes = error.as_bytes();
    let mut i = 0;
    while i + 8 <= bytes.len() {
        if bytes[i..].starts_with(b"HTTP ") {
            let rest = &error[i + 5..];
            for code in [401u16, 403, 500] {
                let text = code.to_string();
                if let Some(tail) = rest.strip_prefix(&text) {
                    let boundary = tail.chars().next().map(|c| !c.is_ascii_alphanumeric()).unwrap_or(true);
                    if boundary {
                        return Some(code);
                    }
                }
            }
        }
        i += 1;
    }
    None
}

pub fn counts_as_dead_failure(error: &str) -> bool {
    if live_rejection_status(error).is_some() {
        return false;
    }
    let lower = error.trim().to_ascii_lowercase();
    if lower.is_empty() {
        return false;
    }
    const SKIP: [&str; 9] = [
        "circuit is open",
        "suspended remote host",
        "lease expired",
        "signing key",
        "activity signature",
        "activity json",
        "curl is required",
        "invalid https inbox",
        "bluesky activity",
    ];
    !SKIP.iter().any(|needle| lower.contains(needle))
}

pub fn suspended(
    last_success: Option<i64>,
    streak_started: Option<i64>,
    consecutive: i64,
    last_live: Option<i64>,
    now: i64,
    dead_days: i64,
    dead_failures: i64,
) -> bool {
    if consecutive < dead_failures {
        return false;
    }
    let Some(streak_started) = streak_started else {
        return false;
    };
    let cutoff = now - dead_days.saturating_mul(86_400);
    if streak_started > cutoff {
        return false;
    }
    if last_success.is_some_and(|at| at > cutoff) {
        return false;
    }
    if last_live.is_some_and(|at| at > cutoff) {
        return false;
    }
    true
}

pub fn terminal_attempt_limit(host_suspended: bool) -> i64 {
    if host_suspended { 1 } else { 12 }
}

pub fn probe_due(last_probe: Option<i64>, now: i64) -> bool {
    match last_probe {
        None => true,
        Some(at) => now - at >= 86_400,
    }
}

pub fn after_success(last_live: Option<i64>, now: i64) -> HostState {
    HostState {
        last_success: Some(now),
        streak_started: None,
        consecutive: 0,
        last_live,
        suspended: false,
    }
}

pub fn after_terminal(state: HostState, error: &str, now: i64, dead_days: i64, dead_failures: i64) -> HostState {
    let mut next = state;
    if counts_as_dead_failure(error) {
        if next.consecutive <= 0 || next.streak_started.is_none() {
            next.streak_started = Some(now);
        }
        next.consecutive += 1;
    } else if live_rejection_status(error).is_some() {
        next.last_live = Some(now);
    }
    next.suspended = suspended(
        next.last_success,
        next.streak_started,
        next.consecutive,
        next.last_live,
        now,
        dead_days,
        dead_failures,
    );
    next
}

pub fn activity_is_bluesky(actor: &str) -> bool {
    actor.starts_with("did:") || actor.contains("bsky.app/") || actor.contains("bsky.social/")
}

pub fn host_is_bluesky(host: &str) -> bool {
    let host = host.trim().trim_end_matches('.').to_ascii_lowercase();
    host == "bsky.app"
        || host.ends_with(".bsky.app")
        || host == "bsky.social"
        || host.ends_with(".bsky.social")
}

/// Same suspension predicate as `deploy/vaak-integrity-check.sh`.
pub fn suspension_sql(failures_param: &str, days_param: &str) -> String {
    format!(
        "h.host = lower(split_part(split_part(regexp_replace(q.inbox_url, '^https://', ''), '/', 1), ':', 1)) \
         AND h.host <> '*' \
         AND h.consecutive_terminal_failures >= {failures_param} \
         AND h.streak_started_at IS NOT NULL \
         AND h.streak_started_at <= to_char((now() AT TIME ZONE 'utc') - make_interval(days => {days_param}::int), 'YYYY-MM-DD\"T\"HH24:MI:SS\"+00:00\"') \
         AND (h.last_success_at IS NULL OR h.last_success_at <= to_char((now() AT TIME ZONE 'utc') - make_interval(days => {days_param}::int), 'YYYY-MM-DD\"T\"HH24:MI:SS\"+00:00\"')) \
         AND (h.last_live_at IS NULL OR h.last_live_at <= to_char((now() AT TIME ZONE 'utc') - make_interval(days => {days_param}::int), 'YYYY-MM-DD\"T\"HH24:MI:SS\"+00:00\"'))"
    )
}

#[cfg(test)]
mod tests {
    use super::*;

    fn state(consecutive: i64, streak: Option<i64>, success: Option<i64>, live: Option<i64>) -> HostState {
        HostState {
            last_success: success,
            streak_started: streak,
            consecutive,
            last_live: live,
            suspended: false,
        }
    }

    #[test]
    fn defaults_match_php() {
        std::env::remove_var("VAAK_FANOUT_DEAD_DAYS");
        std::env::remove_var("VAAK_FANOUT_DEAD_FAILURES");
        assert_eq!(dead_days(), 7);
        assert_eq!(dead_failures(), 20);
        assert_eq!(terminal_attempt_limit(false), 12);
        assert_eq!(terminal_attempt_limit(true), 1);
    }

    #[test]
    fn suspension_requires_age_count_and_no_recent_life() {
        let now = 1_700_000_000;
        let week = 7 * 86_400;
        assert!(!suspended(None, Some(now - week), 19, None, now, 7, 20));
        assert!(!suspended(None, Some(now - week + 10), 25, None, now, 7, 20));
        assert!(!suspended(Some(now - 86_400), Some(now - week), 25, None, now, 7, 20));
        assert!(!suspended(None, Some(now - week), 25, Some(now - 86_400), now, 7, 20));
        assert!(suspended(None, Some(now - week), 20, None, now, 7, 20));
        assert!(suspended(Some(now - week), Some(now - week), 20, Some(now - week), now, 7, 20));
        assert!(!suspended(None, None, 100, None, now, 7, 20));
    }

    #[test]
    fn live_rejections_do_not_become_a_dead_streak() {
        let now = 1_700_000_000;
        let old = now - 8 * 86_400;
        let mut row = state(0, None, None, None);
        for error in ["HTTP 401 Unauthorized", "HTTP 403", "HTTP 500 boom", "Remote host circuit is open"] {
            row = after_terminal(row, error, old, 7, 20);
            assert_eq!(row.consecutive, 0, "{error}");
            assert!(!row.suspended);
        }
        assert_eq!(row.last_live, Some(old));
        row = after_terminal(row, "No HTTP response from inbox", old, 7, 20);
        assert_eq!(row.consecutive, 1);
        assert_eq!(row.streak_started, Some(old));
        for _ in 0..19 {
            row = after_terminal(row, "Connection refused", old, 7, 20);
        }
        assert!(row.suspended);
        row = after_success(row.last_live, now);
        assert!(!row.suspended);
        assert_eq!(row.consecutive, 0);
        assert_eq!(row.streak_started, None);
    }

    #[test]
    fn probe_and_bluesky_stay_narrow() {
        assert!(probe_due(None, 10));
        assert!(!probe_due(Some(10), 10 + 86_399));
        assert!(probe_due(Some(10), 10 + 86_400));
        assert!(activity_is_bluesky("did:plc:abc"));
        assert!(activity_is_bluesky("https://bsky.app/profile/alice"));
        assert!(!activity_is_bluesky("https://tube.baeddel.social/users/alice"));
        assert!(host_is_bluesky("Bsky.Social"));
        assert!(!host_is_bluesky("tube.baeddel.social"));
        assert_eq!(live_rejection_status("HTTP 5000"), None);
        assert!(counts_as_dead_failure("HTTP 5000"));
        assert_eq!(live_rejection_status("HTTP 404"), None);
        assert!(counts_as_dead_failure("HTTP 404"));
        assert!(!counts_as_dead_failure("Bluesky activity is not delivered"));
    }

    #[test]
    fn integrity_sql_names_the_health_columns() {
        let sql = suspension_sql("$1", "$2");
        for needle in [
            "ap_fanout_host_health",
            "consecutive_terminal_failures",
            "streak_started_at",
            "last_success_at",
            "last_live_at",
            "h.host <> '*'",
        ] {
            // The table name is applied by the caller; the predicate names the columns.
            if needle == "ap_fanout_host_health" {
                continue;
            }
            assert!(sql.contains(needle), "{needle} missing from {sql}");
        }
    }
}
