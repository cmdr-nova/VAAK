//! VAAK Rust workers — shadow + live cutover (notif badge/list, ranked warm, thin-media, actor warm).

mod action_queue;
mod ap_actor_warm;
mod config;
mod db;
mod hidden;
mod http;
mod jetstream;
mod account_switch;
mod admin_health;
mod home_html;
mod home_hydrate_ranked;
mod interaction_flags;
mod link_preview;
mod mentions_html;
mod notif;
mod notif_embed;
mod notif_list;
mod profile_html;
mod phyrian;
mod ranked;
mod ranked_warm;
mod redis_util;
mod self_thread;
mod actor_warm;
mod thin_media;
mod thin_media_warm;
mod timeline;
mod timeline_fanout;
mod you;
mod relationships;
mod settings;
mod library;
mod ml_ranker;
mod integrations;
mod private_surfaces;
mod search_contract;
mod search;
mod cleanup;

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
    /// Mentions / Ice Cubes notification list warm (Redis list keys via PHP hydrate).
    NotifList {
        /// Warm one owner (or default) once, then exit.
        #[arg(long, default_value_t = false)]
        once: bool,
        /// Loop forever (multi-user when --owner-id 0).
        #[arg(long, default_value_t = false)]
        r#loop: bool,
        /// Local ap_users.id. `0` refreshes every non-disabled account.
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 45)]
        interval_secs: u64,
        /// First-page limit for Mentions-shaped keys (also warms limit=40 all).
        #[arg(long, default_value_t = 30)]
        limit: i64,
    },
    /// Home / Local / Federated ranked ID-cache warm (native Redis; PHP fallback).
    RankedWarm {
        #[arg(long, default_value_t = false)]
        once: bool,
        #[arg(long, default_value_t = false)]
        r#loop: bool,
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 60)]
        interval_secs: u64,
        /// Comma list: home,local,feed
        #[arg(long, default_value = "home,local,feed")]
        views: String,
    },
    RankedNewer {
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 900)]
        since_secs: i64,
        #[arg(long, default_value_t = 20)]
        limit: i64,
    },
    /// Train the explainable Home ranker in shadow mode from durable signals.
    MlTrain {
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
    },
    /// Compare the shadow model with the current heuristic actor ranking.
    MlCompare {
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 20)]
        limit: usize,
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
    /// Live actor warm: drain Redis queue + optional PG refresh enqueue (flat DID Redis).
    ActorWarm {
        /// Pull pending actors from bsky_actor_refresh_queue onto Redis once.
        #[arg(long, default_value_t = false)]
        enqueue_once: bool,
        /// Drain `vaak:queue:bsky_actor_warm` forever (periodic PG scan).
        #[arg(long, default_value_t = false)]
        r#loop: bool,
        /// Warm specific actors once (DID / handle / profile URL), then exit.
        #[arg(long)]
        actor: Vec<String>,
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        #[arg(long, default_value_t = 20)]
        limit: usize,
        #[arg(long, default_value_t = 180)]
        scan_interval_secs: u64,
    },
    /// Fediverse (AP) actor warm: drain Redis queue + PG flat backfill (signed fetch via PHP).
    ApActorWarm {
        /// Backfill flat Redis from `remote_actors` once (also enqueues thin actors).
        #[arg(long, default_value_t = false)]
        backfill_once: bool,
        /// Drain `vaak:queue:ap_actor_warm` forever (periodic PG flat backfill).
        #[arg(long, default_value_t = false)]
        r#loop: bool,
        /// Warm specific actor URLs once (spawns PHP ap-actor-warm.php), then exit.
        #[arg(long)]
        actor: Vec<String>,
        #[arg(long, default_value_t = 40)]
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
    /// Materialize timeline hydrate envelopes from ranked IDs.
    ///
    /// Views: `home` (rss/bsky/event/outbox), `local` (outbox/boost), `feed` (event).
    /// Use `--view` for one view (default home) or `--views home,local,feed`.
    HomeHydrateWarm {
        #[arg(long, default_value_t = 0)]
        owner_id: i64,
        /// Comma list of envelope limits (default includes 160/240 for deep Home scroll).
        #[arg(long, default_value = "15,40,50,80,160,240")]
        limits: String,
        /// Single view: home | local | feed (default home).
        #[arg(long, default_value = "home")]
        view: String,
        /// Optional comma list of views (overrides --view when non-empty).
        #[arg(long, default_value = "")]
        views: String,
    },
    /// Drain `vaak:queue:timeline_fanout` (ranked prepend + Home hydrate).
    TimelineFanout {
        /// Process forever (BRPOP on Redis queue DB).
        #[arg(long, default_value_t = false)]
        r#loop: bool,
        /// Process one job JSON once (smoke / debug), then exit.
        #[arg(long)]
        once_json: Option<String>,
    },
    Serve {
        #[arg(long, default_value = "127.0.0.1:8787")]
        bind: String,
    },
    /// Print the cleanup retention plan. PHP still owns deletes.
    CleanupShadow {
        /// Refused. Present so a cutover attempt fails closed.
        #[arg(long, default_value_t = false)]
        execute: bool,
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
        Command::NotifList {
            once,
            r#loop,
            owner_id,
            interval_secs,
            limit,
        } => {
            if r#loop && !once {
                notif_list::run_loop(&cfg, owner_id, interval_secs, limit).await?;
            } else {
                let owner = if owner_id > 0 {
                    owner_id
                } else {
                    cfg.default_owner_id
                };
                notif_list::run_once(&cfg, owner, limit).await?;
            }
        }
        Command::RankedWarm {
            once,
            r#loop,
            owner_id,
            interval_secs,
            views,
        } => {
            if r#loop && !once {
                ranked_warm::run_loop(&cfg, owner_id, interval_secs, &views).await?;
            } else {
                let owner = if owner_id > 0 {
                    owner_id
                } else {
                    cfg.default_owner_id
                };
                ranked_warm::run_once(&cfg, owner, &views).await?;
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
        Command::MlTrain { owner_id } => {
            let owner = if owner_id > 0 { owner_id } else { cfg.default_owner_id };
            ml_ranker::run(&cfg, owner).await?;
        }
        Command::MlCompare { owner_id, limit } => {
            let owner = if owner_id > 0 { owner_id } else { cfg.default_owner_id };
            ml_ranker::compare(&cfg, owner, limit).await?;
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
        Command::ActorWarm {
            enqueue_once,
            r#loop,
            actor,
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
                actor_warm::run_worker_loop(&cfg, scan_interval_secs).await?;
            } else if !actor.is_empty() {
                actor_warm::run_once_cli(&cfg, &actor, owner).await?;
            } else if enqueue_once {
                actor_warm::run_enqueue_cli(&cfg, limit).await?;
            } else {
                anyhow::bail!("actor-warm requires --loop, --enqueue-once, or --actor");
            }
        }
        Command::ApActorWarm {
            backfill_once,
            r#loop,
            actor,
            limit,
            scan_interval_secs,
        } => {
            if r#loop {
                ap_actor_warm::run_worker_loop(&cfg, scan_interval_secs).await?;
            } else if !actor.is_empty() {
                ap_actor_warm::run_once_cli(&cfg, &actor).await?;
            } else if backfill_once {
                ap_actor_warm::run_backfill_cli(&cfg, limit).await?;
            } else {
                anyhow::bail!("ap-actor-warm requires --loop, --backfill-once, or --actor");
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
        Command::HomeHydrateWarm {
            owner_id,
            limits,
            view,
            views,
        } => {
            let owner = if owner_id > 0 {
                owner_id
            } else {
                cfg.default_owner_id
            };
            let parsed: Vec<i64> = limits
                .split(',')
                .filter_map(|p| p.trim().parse().ok())
                .filter(|n| (1..=240).contains(n))
                .collect();
            let mut wanted: Vec<&str> = Vec::new();
            if !views.trim().is_empty() {
                for part in views.split(',') {
                    match part.trim().to_ascii_lowercase().as_str() {
                        "home" if !wanted.contains(&"home") => wanted.push("home"),
                        "local" if !wanted.contains(&"local") => wanted.push("local"),
                        "feed" if !wanted.contains(&"feed") => wanted.push("feed"),
                        _ => {}
                    }
                }
            }
            if wanted.is_empty() {
                wanted.push(match view.trim().to_ascii_lowercase().as_str() {
                    "local" => "local",
                    "feed" => "feed",
                    _ => "home",
                });
            }
            let mut reports = Vec::new();
            for v in wanted {
                let report = if parsed.is_empty() {
                    home_hydrate_ranked::warm_view_now(&cfg, owner, v).await?
                } else {
                    home_hydrate_ranked::warm_view(&cfg, owner, v, &parsed).await?
                };
                reports.push(home_hydrate_ranked::report_json(&report));
            }
            if reports.len() == 1 {
                println!("{}", serde_json::to_string_pretty(&reports[0])?);
            } else {
                println!("{}", serde_json::to_string_pretty(&reports)?);
            }
        }
        Command::TimelineFanout { r#loop, once_json } => {
            if r#loop {
                timeline_fanout::run_worker_loop(&cfg).await?;
            } else if let Some(raw) = once_json {
                timeline_fanout::run_once_cli(&cfg, &raw).await?;
            } else {
                anyhow::bail!("timeline-fanout requires --loop or --once-json");
            }
        }
        Command::Serve { bind } => {
            let addr: SocketAddr = bind.parse()?;
            http::serve(cfg, addr).await?;
        }
        Command::CleanupShadow { execute } => {
            match cleanup::shadow_report(execute) {
                Ok(report) => println!("{report}"),
                Err(message) => anyhow::bail!(message),
            }
        }
    }
    Ok(())
}
