//! VAAK wave-1/2 Rust workers — shadow mode only.
//!
//! Live owners remain PHP/Python. These commands mirror hot paths for soak/parity.

mod action_queue;
mod config;
mod db;
mod http;
mod jetstream;
mod notif;
mod ranked;
mod redis_util;

use std::net::SocketAddr;

use anyhow::Result;
use clap::{Parser, Subcommand};
use tracing_subscriber::EnvFilter;

#[derive(Debug, Parser)]
#[command(name = "vaak-worker", about = "VAAK shadow-mode Rust workers (wave 1–2)")]
struct Cli {
    #[command(subcommand)]
    cmd: Command,
}

#[derive(Debug, Subcommand)]
enum Command {
    /// Recompute notification unread badge into vaak:shadow:* Redis keys.
    NotifBadge {
        #[arg(long, default_value_t = false)]
        once: bool,
        #[arg(long, default_value_t = false)]
        compare: bool,
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 30)]
        interval_secs: u64,
        #[arg(long, default_value_t = false)]
        r#loop: bool,
    },
    /// Dump Home newer-poll candidates (JSON) for parity with PHP newer=1.
    RankedNewer {
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 900)]
        since_secs: i64,
        #[arg(long, default_value_t = 20)]
        limit: i64,
    },
    /// List pending/processing action-queue rows (never claims).
    ActionQueue {
        #[arg(long, default_value_t = true)]
        list: bool,
        #[arg(long, default_value_t = 25)]
        limit: i64,
    },
    /// Read-only Jetstream spool scan + thin-media candidate list.
    JetstreamHydrate {
        #[arg(long, default_value_t = true)]
        scan_once: bool,
        #[arg(long, default_value_t = 20)]
        max_files: usize,
        #[arg(long, default_value_t = 25)]
        max_thin: usize,
    },
    /// Localhost Axum shadow HTTP (/healthz + /shadow/*).
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
    tracing::info!(env = ?config::env_snapshot(), "vaak-worker shadow starting");

    match cli.cmd {
        Command::NotifBadge {
            once,
            compare,
            owner_id,
            interval_secs,
            r#loop,
        } => {
            let owner = if owner_id > 0 {
                owner_id
            } else {
                cfg.default_owner_id
            };
            if r#loop && !once {
                notif::run_loop(&cfg, owner, interval_secs, compare).await?;
            } else {
                notif::run_once(&cfg, owner, compare).await?;
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
        Command::Serve { bind } => {
            let addr: SocketAddr = bind.parse()?;
            http::serve(cfg, addr).await?;
        }
    }
    Ok(())
}
