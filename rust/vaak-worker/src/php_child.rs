//! Hard limit for a PHP process this worker spawned.
//!
//! On 2026-11-18 a provider disk stall left Redis accepts hanging. Two PHP
//! workers blocked forever on that socket:
//!
//! * `bin/notif-projection-fill.php`, which `vaak-worker notif-list` waits on.
//!   One hung child froze notification list warming for every owner.
//! * `api/ap-listenbrainz-worker.php`, whose cron `flock` then skipped every
//!   later run. That one is capped in cron; this module caps the children
//!   Rust itself starts.
//!
//! `output_bounded` is for a child the caller waits on. `supervise` is for a
//! child already detached (nohup / flock): the parent returns, and a task
//! still kills the process group if the child outlives its budget. Both send
//! SIGTERM, then SIGKILL after `Budget::grace`.
//!
//! `profile_featured::retained_media` is not routed through here. That helper
//! already stops at 15 seconds, kills the child on drop, and refuses more
//! than 64 KiB of stdout. A shared reader would drop that bound.

use std::time::Duration;

use anyhow::{Context, Result};
use tokio::io::{AsyncRead, AsyncReadExt};
use tokio::process::{Child, Command};
use tokio::time::timeout;

const SIGTERM: i32 = 15;
const SIGKILL: i32 = 9;
const MAX_CAPTURE: usize = 1024 * 1024;

extern "C" {
    fn kill(pid: i32, sig: i32) -> i32;
}

/// The child was still alive when `limit` elapsed. It has been signalled.
#[derive(Debug)]
pub struct TimeoutExceeded {
    pub pid: u32,
    pub limit: Duration,
}

impl std::fmt::Display for TimeoutExceeded {
    fn fmt(&self, f: &mut std::fmt::Formatter<'_>) -> std::fmt::Result {
        write!(
            f,
            "php child pid={} exceeded {}s and was killed",
            self.pid,
            self.limit.as_secs()
        )
    }
}

impl std::error::Error for TimeoutExceeded {}

impl TimeoutExceeded {
    pub fn is(err: &anyhow::Error) -> bool {
        err.chain().any(|cause| cause.is::<TimeoutExceeded>())
    }
}

#[derive(Clone, Copy)]
pub struct Budget {
    pub limit: Duration,
    pub grace: Duration,
    pub label: &'static str,
}

impl Budget {
    /// `specific` wins, then `VAAK_PHP_CHILD_TIMEOUT_SECS` when `honor_global`
    /// is set, then `default_limit_secs`. Values are clamped to 5..=3600.
    /// Grace is `VAAK_PHP_CHILD_KILL_GRACE_SECS` (default 5, clamp 1..=60).
    pub fn from_env(
        label: &'static str,
        specific: Option<&str>,
        default_limit_secs: u64,
        honor_global: bool,
    ) -> Self {
        let specific_val = specific.and_then(env_u64);
        let global = if honor_global {
            env_u64("VAAK_PHP_CHILD_TIMEOUT_SECS")
        } else {
            None
        };
        Self {
            limit: Duration::from_secs(resolve_limit_secs(
                specific_val,
                global,
                default_limit_secs,
            )),
            grace: Duration::from_secs(resolve_grace_secs(env_u64(
                "VAAK_PHP_CHILD_KILL_GRACE_SECS",
            ))),
            label,
        }
    }
}

/// Budget for a background PHP helper. Media warm downloads a small batch and
/// keeps a longer default; everything else shares the 120s notif-fill cap.
pub fn detached_budget(script: &str) -> Budget {
    if script.contains("ap-media-warm-worker.php") {
        Budget::from_env(
            "media-warm",
            Some("VAAK_MEDIA_WARM_TIMEOUT_SECS"),
            600,
            false,
        )
    } else if script.contains("ap-actor-warm.php") {
        Budget::from_env(
            "actor-warm",
            Some("VAAK_AP_ACTOR_WARM_TIMEOUT_SECS"),
            120,
            true,
        )
    } else if script.contains("ap-bsky-post-warm.php") {
        Budget::from_env("bsky-post-warm", None, 120, true)
    } else if script.contains("ap-quote-target-warm.php") {
        Budget::from_env("quote-warm", None, 120, true)
    } else if script.contains("ap-link-preview-warm.php") {
        Budget::from_env("link-preview-warm", None, 120, true)
    } else if script.contains("bsky-bluemoji-warm.php") {
        Budget::from_env("bluemoji-warm", None, 120, true)
    } else {
        Budget::from_env("php-child", None, 120, true)
    }
}

pub(crate) fn resolve_limit_secs(specific: Option<u64>, global: Option<u64>, default: u64) -> u64 {
    specific.or(global).unwrap_or(default).clamp(5, 3600)
}

pub(crate) fn resolve_grace_secs(raw: Option<u64>) -> u64 {
    raw.unwrap_or(5).clamp(1, 60)
}

fn env_u64(name: &str) -> Option<u64> {
    std::env::var(name)
        .ok()
        .and_then(|raw| raw.trim().parse::<u64>().ok())
}

/// Make the child a process-group leader so a later signal reaches
/// grandchildren (php under `flock` or `nohup`).
pub fn prepare(cmd: &mut Command) {
    cmd.process_group(0);
}

/// Spawn is the caller's. This task SIGTERMs, then SIGKILLs, if the child
/// outlives `budget`. Dropping the task does not kill a healthy child, so a
/// worker restart still leaves an in-flight warm to finish on its own.
pub fn supervise(mut child: Child, budget: Budget) {
    tokio::spawn(async move {
        let Some(pid) = child.id() else {
            return;
        };
        if timeout(budget.limit, child.wait()).await.is_err() {
            terminate(&mut child, pid, &budget).await;
        }
    });
}

/// Run `cmd` and collect stdout/stderr. On expiry the process group is
/// killed and the error is [`TimeoutExceeded`]. The caller chooses stdio;
/// piped stdout/stderr are drained while the child runs so a full pipe
/// cannot stall it past the limit.
pub async fn output_bounded(cmd: &mut Command, budget: Budget) -> Result<std::process::Output> {
    cmd.kill_on_drop(true);
    prepare(cmd);
    let mut child = cmd.spawn().context("spawn php child")?;
    let pid = child.id().context("php child pid")?;
    let stdout = child.stdout.take();
    let stderr = child.stderr.take();
    let out_task = tokio::spawn(read_to_cap(stdout));
    let err_task = tokio::spawn(read_to_cap(stderr));
    let status = match timeout(budget.limit, child.wait()).await {
        Ok(Ok(status)) => status,
        Ok(Err(e)) => {
            out_task.abort();
            err_task.abort();
            return Err(e).context("wait php child");
        }
        Err(_) => {
            terminate(&mut child, pid, &budget).await;
            out_task.abort();
            err_task.abort();
            return Err(TimeoutExceeded {
                pid,
                limit: budget.limit,
            }
            .into());
        }
    };
    let stdout = out_task.await.unwrap_or_default();
    let stderr = err_task.await.unwrap_or_default();
    Ok(std::process::Output {
        status,
        stdout,
        stderr,
    })
}

async fn read_to_cap<R: AsyncRead + Unpin>(pipe: Option<R>) -> Vec<u8> {
    let mut buf = Vec::new();
    let Some(mut pipe) = pipe else {
        return buf;
    };
    let mut chunk = [0u8; 8192];
    loop {
        match pipe.read(&mut chunk).await {
            Ok(0) | Err(_) => break,
            Ok(n) => {
                if buf.len() < MAX_CAPTURE {
                    let take = n.min(MAX_CAPTURE - buf.len());
                    buf.extend_from_slice(&chunk[..take]);
                }
            }
        }
    }
    buf
}

async fn terminate(child: &mut Child, pid: u32, budget: &Budget) {
    if matches!(child.try_wait(), Ok(Some(_))) {
        return;
    }
    tracing::warn!(
        label = budget.label,
        pid,
        limit_secs = budget.limit.as_secs(),
        grace_secs = budget.grace.as_secs(),
        "php child exceeded timeout; sending SIGTERM"
    );
    signal_group(pid, SIGTERM);
    match timeout(budget.grace, child.wait()).await {
        Ok(Ok(_)) => {}
        Ok(Err(e)) => {
            tracing::warn!(
                label = budget.label,
                pid,
                error = %e,
                "php child wait after SIGTERM failed"
            );
        }
        Err(_) => {
            tracing::warn!(
                label = budget.label,
                pid,
                "php child ignored SIGTERM; sending SIGKILL"
            );
            signal_group(pid, SIGKILL);
            let _ = child.wait().await;
        }
    }
}

fn signal_group(pid: u32, sig: i32) {
    let pid = pid as i32;
    if pid <= 0 {
        return;
    }
    // Negative pid signals the whole process group (leader pid == pgid).
    let rc = unsafe { kill(-pid, sig) };
    if rc != 0 {
        unsafe { kill(pid, sig) };
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use std::process::Stdio;
    use std::time::Instant;

    fn short(label: &'static str, limit_ms: u64, grace_ms: u64) -> Budget {
        Budget {
            limit: Duration::from_millis(limit_ms),
            grace: Duration::from_millis(grace_ms),
            label,
        }
    }

    #[test]
    fn limit_prefers_specific_then_global_then_default() {
        assert_eq!(resolve_limit_secs(Some(30), Some(90), 120), 30);
        assert_eq!(resolve_limit_secs(None, Some(90), 120), 90);
        assert_eq!(resolve_limit_secs(None, None, 120), 120);
        assert_eq!(resolve_limit_secs(Some(1), None, 120), 5);
        assert_eq!(resolve_limit_secs(Some(9_999), None, 120), 3600);
        assert_eq!(resolve_grace_secs(None), 5);
        assert_eq!(resolve_grace_secs(Some(0)), 1);
        assert_eq!(resolve_grace_secs(Some(90)), 60);
    }

    #[test]
    fn detached_media_budget_is_longer_than_a_single_fetch() {
        let media = detached_budget("ap-media-warm-worker.php");
        let quote = detached_budget("ap-quote-target-warm.php");
        assert_eq!(media.label, "media-warm");
        assert_eq!(quote.label, "quote-warm");
        // Defaults when the operator has not overridden the env knobs.
        if std::env::var_os("VAAK_MEDIA_WARM_TIMEOUT_SECS").is_none() {
            assert_eq!(media.limit, Duration::from_secs(600));
        }
        if std::env::var_os("VAAK_PHP_CHILD_TIMEOUT_SECS").is_none() {
            assert_eq!(quote.limit, Duration::from_secs(120));
        }
    }

    #[tokio::test]
    async fn finished_child_returns_stdout() {
        let mut cmd = Command::new("echo");
        cmd.arg("ok")
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped());
        let out = output_bounded(&mut cmd, short("echo", 2_000, 200))
            .await
            .expect("echo");
        assert!(out.status.success());
        assert_eq!(out.stdout, b"ok\n");
    }

    #[tokio::test]
    async fn chatty_child_does_not_deadlock_the_pipe() {
        let mut cmd = Command::new("seq");
        cmd.arg("1")
            .arg("20000")
            .stdin(Stdio::null())
            .stdout(Stdio::piped())
            .stderr(Stdio::piped());
        let out = output_bounded(&mut cmd, short("seq", 5_000, 200))
            .await
            .expect("seq");
        assert!(out.status.success());
        assert!(out.stdout.len() > 60_000);
    }

    #[tokio::test]
    async fn ignored_sigterm_is_sigkilled_after_grace() {
        let mut cmd = Command::new("bash");
        cmd.args(["-c", "trap '' TERM; while true; do sleep 30; done"])
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null());
        let started = Instant::now();
        let err = output_bounded(&mut cmd, short("stuck", 300, 400))
            .await
            .expect_err("hung child must time out");
        let elapsed = started.elapsed();
        assert!(TimeoutExceeded::is(&err), "{err:#}");
        let exceeded = err.downcast_ref::<TimeoutExceeded>().expect("timeout type");
        assert!(
            elapsed >= Duration::from_millis(550),
            "SIGTERM was enough, elapsed {elapsed:?}"
        );
        assert!(
            elapsed < Duration::from_secs(4),
            "kill took too long: {elapsed:?}"
        );
        assert!(
            !std::path::Path::new(&format!("/proc/{}", exceeded.pid)).exists(),
            "pid {} still alive",
            exceeded.pid
        );
    }

    #[tokio::test]
    async fn supervise_kills_a_detached_child() {
        let mut cmd = Command::new("bash");
        cmd.args(["-c", "trap '' TERM; while true; do sleep 30; done"])
            .stdin(Stdio::null())
            .stdout(Stdio::null())
            .stderr(Stdio::null());
        prepare(&mut cmd);
        let child = cmd.spawn().expect("spawn");
        let pid = child.id().expect("pid");
        supervise(child, short("detached", 200, 300));
        let mut gone = false;
        for _ in 0..40 {
            if !std::path::Path::new(&format!("/proc/{pid}")).exists() {
                gone = true;
                break;
            }
            tokio::time::sleep(Duration::from_millis(50)).await;
        }
        if !gone {
            super::signal_group(pid, super::SIGKILL);
        }
        assert!(gone, "detached pid {pid} still alive");
    }
}
