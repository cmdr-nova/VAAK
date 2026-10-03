//! VAAK Rust workers — shadow + live cutover (notif badge, thin-media warm).

mod action_queue;
mod config;
mod db;
mod hidden;
mod http;
mod jetstream;
mod notif;
mod ranked;
mod redis_util;
mod thin_media;
mod thin_media_warm;
mod timeline;

use std::net::SocketAddr;

use anyhow::Result;
use clap::{Parser, Subcommand};
use tracing_subscriber::EnvFilter;

#[derive(Debug, Parser)]
#[command(name = "vaak-worker", about = "VAAK Rust workers (shadow + live cutover)")]
struct Cli {
    #[command(subcommand)]
    cmd: Command,
}

#[derive(Debug, Subcommand)]
enum Command {
    /// Notification unread badge (shadow and/or live Redis).
    NotifBadge {
        #[arg(long, default_value_t = false)]
        once: bool,
        #[arg(long, default_value_t = false)]
        compare: bool,
        /// Write production Redis key + file cache (Rust-primary cutover).
        #[arg(long, default_value_t = false)]
        live: bool,
        /// Local ap_users.id. `0` (default in loop) refreshes every non-disabled account.
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 30)]
        interval_secs: u64,
        #[arg(long, default_value_t = false)]
        r#loop: bool,
    },
    RankedNewer {
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 900)]
        since_secs: i64,
        #[arg(long, default_value_t = 20)]
        limit: i64,
    },
    ActionQueue {
        #[arg(long, default_value_t = true)]
        list: bool,
        #[arg(long, default_value_t = 25)]
        limit: i64,
    },
    JetstreamHydrate {
        #[arg(long, default_value_t = true)]
        scan_once: bool,
        #[arg(long, default_value_t = 20)]
        max_files: usize,
        #[arg(long, default_value_t = 25)]
        max_thin: usize,
    },
    /// Dry-run thin-media candidates (never writes).
    ThinMediaRepair {
        #[arg(long, default_value_t = true)]
        dry_run: bool,
        #[arg(long, default_value_t = false)]
        fetch: bool,
        #[arg(long, default_value_t = 20)]
        limit: usize,
    },
    /// Live thin-media warm: enqueue scan and/or drain queue (writes bsky_posts).
    ThinMediaWarm {
        /// Enqueue a thin-post scan once, then exit.
        #[arg(long, default_value_t = false)]
        enqueue_once: bool,
        /// Drain Redis warm queue forever (also periodic scan).
        #[arg(long, default_value_t = false)]
        r#loop: bool,
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 15)]
        limit: usize,
        #[arg(long, default_value_t = 180)]
        scan_interval_secs: u64,
    },
    TimelineHome {
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 20)]
        limit: usize,
    },
    Serve {
        #[arg(long, default_value = "127.0.0.1:8787")]
        bind: String,
    },
}

#[tokio::main]
async fn main() -> Result<()> {
    tracing_subscriber::fmt()
        .with_env_filter(EnvFilter::try_from_default_env().unwrap_or_else(|_| EnvFilter::new("info")))
        .with_target(false)
        .init();

    let cli = Cli::parse();
    let cfg = config::Config::load()?;
    tracing::info!(env = ?config::env_snapshot(), "vaak-worker starting");

    match cli.cmd {
        Command::NotifBadge {
            once,
            compare,
            live,
            owner_id,
            interval_secs,
            r#loop,
        } => {
            // Loop with owner_id=0 → all local accounts. One-shot with 0 still
            // falls back to VAAK_SHADOW_OWNER_ID for quick single-user smoke.
            if r#loop && !once {
                notif::run_loop(&cfg, owner_id, interval_secs, compare, live).await?;
            } else {
                let owner = if owner_id > 0 {
                    owner_id
                } else {
                    cfg.default_owner_id
                };
                notif::run_once(&cfg, owner, compare, live).await?;
            }
        }
        Command::RankedNewer {
            owner_id,
            since_secs,
            limit,
        } => {
            let owner = if owner_id > 0 {
                owner_id
            } else {
                cfg.default_owner_id
            };
            ranked::run(&cfg, owner, since_secs, limit).await?;
        }
        Command::ActionQueue { list: _, limit } => {
            action_queue::list_pending(&cfg, limit).await?;
        }
        Command::JetstreamHydrate {
            scan_once: _,
            max_files,
            max_thin,
        } => {
            jetstream::scan_once(&cfg, max_files, max_thin).await?;
        }
        Command::ThinMediaRepair {
            dry_run: _,
            fetch,
            limit,
        } => {
            thin_media::run(&cfg, limit, fetch).await?;
        }
        Command::ThinMediaWarm {
            enqueue_once,
            r#loop,
            owner_id,
            limit,
            scan_interval_secs,
        } => {
            let owner = if owner_id > 0 {
                owner_id
            } else {
                cfg.default_owner_id
            };
            if r#loop {
                thin_media_warm::run_worker_loop(&cfg, scan_interval_secs).await?;
            } else if enqueue_once {
                thin_media_warm::run_enqueue_cli(&cfg, owner, limit).await?;
            } else {
                anyhow::bail!("thin-media-warm requires --enqueue-once or --loop");
            }
        }
        Command::TimelineHome { owner_id, limit } => {
            let owner = if owner_id > 0 {
                owner_id
            } else {
                cfg.default_owner_id
            };
            timeline::run(&cfg, owner, limit).await?;
        }
        Command::Serve { bind } => {
            let addr: SocketAddr = bind.parse()?;
            http::serve(cfg, addr).await?;
        }
    }
    Ok(())
}
