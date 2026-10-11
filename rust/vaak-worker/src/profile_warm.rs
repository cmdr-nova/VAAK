//! Profile warming effects copied from PHP ap-db.php::ap_remote_actor_warm_async
//! and ap-r2.php::ap_media_warm_enqueue_actor/ap_media_warm_worker_nudge.
//! The signed actor fetch and media downloader still run in the existing PHP
//! workers. Callers must establish audience/source admission before invoking us.

use std::{future::Future, pin::Pin, path::PathBuf, process::Stdio, time::Duration};
use serde::{Deserialize, Serialize};
use sha2::{Digest, Sha256};
use tokio::process::Command;
use tokio_postgres::Client;
use crate::{config::Config, redis_util};

type EffectFuture<'a> = Pin<Box<dyn Future<Output = bool> + Send + 'a>>;

/// Private server-owned process boundary; never supplied by an HTTP DTO.
pub(crate) trait WarmTransport: Sync {
    fn actor_process<'a>(&'a self, actor: &'a str) -> EffectFuture<'a>;
    fn media_process(&self) -> EffectFuture<'_>;
}

#[derive(Debug, Default, Clone, Serialize, Deserialize, PartialEq, Eq)]
pub(crate) struct WarmEffects {
    pub actor_queued: bool,
    pub actor_process: bool,
    pub media_queued: bool,
    pub media_process: bool,
}

pub(crate) fn actor_key(raw: &str) -> Option<String> {
    let actor = raw.trim_matches([' ', '\t', '\n', '\r', '\0', '\u{b}']).trim_end_matches('/');
    // FILTER_VALIDATE_URL uses ASCII RFC host validation, not WHATWG IDNA or
    // hostname repair. Keep the original bytes for locks/payloads after PHP's
    // trim/rtrim; accepting a parser-normalized URL would change identity.
    if !actor.to_ascii_lowercase().starts_with("https://")
        || !actor.is_ascii() || actor.bytes().any(|b| b <= 32 || b == 127) {
        return None;
    }
    let authority=actor[8..].split(['/', '?', '#']).next()?;
    let host_port=if let Some((userinfo,host))=authority.rsplit_once('@') {
        let mut chars=userinfo.bytes();
        while let Some(byte)=chars.next() {
            if byte==b'%' {
                if !chars.next().is_some_and(|b|b.is_ascii_hexdigit()) || !chars.next().is_some_and(|b|b.is_ascii_hexdigit()) { return None; }
            } else if !byte.is_ascii_alphanumeric() && !b"-._~!$&'()*+,;=:".contains(&byte) { return None; }
        }
        host
    } else { authority };
    let (host,port)=if host_port.starts_with('[') {
        let close=host_port.find(']')?;
        host_port[1..close].parse::<std::net::Ipv6Addr>().ok()?;
        let suffix=&host_port[close+1..];
        if !suffix.is_empty() && !suffix.starts_with(':') { return None; }
        (&host_port[..close+1],suffix.strip_prefix(':'))
    } else {
        let (host,port)=host_port.split_once(':').map_or((host_port,None),|(h,p)|(h,Some(p)));
        let hostname=host.strip_suffix('.').unwrap_or(host);
        if hostname.is_empty() || hostname.len()>253 || hostname.split('.').any(|label|
            label.is_empty() || label.len()>63 || !label.as_bytes()[0].is_ascii_alphanumeric()
            || !label.as_bytes()[label.len()-1].is_ascii_alphanumeric()
            || label.bytes().any(|b|!b.is_ascii_alphanumeric()&&b!=b'-')) { return None; }
        (host,port)
    };
    if host.is_empty() || port.is_some_and(|p| {
        if p.is_empty() { return false; }
        // PHP parse_url accepts a signed numeric prefix (e.g. :80x, :-0),
        // but rejects more than five port bytes and additional colons.
        if p.len()>5 || p.contains(':') { return true; }
        let digits=p.trim_start_matches(['+','-']).bytes().take_while(u8::is_ascii_digit).count();
        if digits==0 { return true; }
        let signed=usize::from(p.starts_with('+')||p.starts_with('-'));
        p[..signed+digits].parse::<i32>().map_or(true,|n|!(0..=65535).contains(&n))
    }) { return None; }
    Some(actor.into())
}

fn digest(actor: &str) -> String { hex::encode(Sha256::digest(actor.as_bytes())) }
fn enabled(name: &str) -> bool {
    std::env::var(name).map(|v| !matches!(v.trim().to_ascii_lowercase().as_str(), "0" | "false" | "off" | "no")).unwrap_or(true)
}

async fn queue_connection(cfg: &Config) -> Option<redis::aio::MultiplexedConnection> {
    if !enabled("VAAK_REDIS_ENABLED") { return None; }
    tokio::time::timeout(Duration::from_millis(80), redis_util::connect(&cfg.redis_queue_url)).await.ok()?.ok()
}

pub(crate) async fn actor_warm(cfg: &Config, raw: &str, transport: &dyn WarmTransport) -> WarmEffects {
    actor_warm_at(cfg, raw, transport, chrono::Utc::now().timestamp(), enabled("VAAK_AP_ACTOR_WARM_RUST_PRIMARY")).await
}

async fn actor_warm_at(cfg: &Config, raw: &str, transport: &dyn WarmTransport, ts: i64, primary: bool) -> WarmEffects {
    let mut effects = WarmEffects::default();
    let Some(actor) = actor_key(raw) else { return effects; };
    if primary {
        // PHP's ap_redis_lock returns false for unavailable Redis as well as a
        // contended lock. Both return immediately, without spawning a process.
        let Some(mut queue) = queue_connection(cfg).await else { return effects; };
        let holder = std::process::id().to_string();
        let locked = tokio::time::timeout(Duration::from_millis(80), redis_util::lock(&mut queue, &format!("ap-actor-warm:{}", digest(&actor)), 45, &holder)).await;
        if !matches!(locked, Ok(Ok(true))) { return effects; }
        let payload = serde_json::json!({"actor_id": actor, "ts": ts, "source": "warm-async"}).to_string();
        // Unlike redis_util::queue_push, PHP treats EXPIRE failure as push
        // failure (even after a successful LPUSH) and then spawns a worker.
        let pushed = tokio::time::timeout(Duration::from_millis(80), async {
            let _: i64 = redis::cmd("LPUSH").arg("vaak:queue:ap_actor_warm").arg(&payload).query_async(&mut queue).await?;
            let _: i64 = redis::cmd("EXPIRE").arg("vaak:queue:ap_actor_warm").arg(86400).query_async(&mut queue).await?;
            Ok::<(), redis::RedisError>(())
        }).await;
        if matches!(pushed, Ok(Ok(()))) { effects.actor_queued = true; return effects; }
    }
    effects.actor_process = transport.actor_process(&actor).await;
    effects
}

/// Bound profile effects recheck the native source after every awaited wire or
/// SQL operation, before a failed transport can trigger a later fallback.
pub(crate) async fn actor_warm_guarded(cfg:&Config,db:&Client,raw:&str,transport:&dyn WarmTransport,grant:&dyn crate::profile_enrichment::CurrentEnrichmentGrant)->anyhow::Result<WarmEffects>{
 actor_warm_guarded_mode(cfg,db,raw,transport,grant,enabled("VAAK_AP_ACTOR_WARM_RUST_PRIMARY")).await
}
async fn actor_warm_guarded_mode(cfg:&Config,db:&Client,raw:&str,transport:&dyn WarmTransport,grant:&dyn crate::profile_enrichment::CurrentEnrichmentGrant,primary:bool)->anyhow::Result<WarmEffects>{
 let mut effects=WarmEffects::default();let Some(actor)=actor_key(raw)else{return Ok(effects)};grant.require_current(db).await?;
 if primary{
  let queue=queue_connection(cfg).await;grant.require_current(db).await?;let Some(mut queue)=queue else{return Ok(effects)};
  let locked=tokio::time::timeout(Duration::from_millis(80),redis_util::lock(&mut queue,&format!("ap-actor-warm:{}",digest(&actor)),45,&std::process::id().to_string())).await;grant.require_current(db).await?;if !matches!(locked,Ok(Ok(true))){return Ok(effects)}
  let payload=serde_json::json!({"actor_id":actor,"ts":chrono::Utc::now().timestamp(),"source":"warm-async"}).to_string();
  let wire_deadline=tokio::time::Instant::now()+Duration::from_millis(80);
  let pushed=tokio::time::timeout_at(wire_deadline,redis::cmd("LPUSH").arg("vaak:queue:ap_actor_warm").arg(&payload).query_async::<i64>(&mut queue)).await;grant.require_current(db).await?;
  if matches!(pushed,Ok(Ok(_))){let expired=tokio::time::timeout_at(wire_deadline,redis::cmd("EXPIRE").arg("vaak:queue:ap_actor_warm").arg(86400).query_async::<i64>(&mut queue)).await;grant.require_current(db).await?;if matches!(expired,Ok(Ok(_))){effects.actor_queued=true;return Ok(effects)}}
 }
 grant.require_current(db).await?;effects.actor_process=transport.actor_process(&actor).await;grant.require_current(db).await?;Ok(effects)
}
pub(crate) async fn media_warm_guarded(cfg:&Config,db:&Client,raw:&str,transport:&dyn WarmTransport,grant:&dyn crate::profile_enrichment::CurrentEnrichmentGrant)->anyhow::Result<WarmEffects>{
 let mut effects=WarmEffects::default();let actor=crate::library::card::php_trim(raw).trim_end_matches('/');if actor.is_empty()||!actor.starts_with("https://"){return Ok(effects)}grant.require_current(db).await?;
 let queue=queue_connection(cfg).await;grant.require_current(db).await?;if let Some(mut queue)=queue{let locked=tokio::time::timeout(Duration::from_millis(80),redis_util::lock(&mut queue,&format!("media-warm:actor:{}",digest(actor)),20,&std::process::id().to_string())).await;grant.require_current(db).await?;if !matches!(locked,Ok(Ok(true))){return Ok(effects)}}
 let now=chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs,false);
 let inserted=db.execute("INSERT INTO ap_media_warm_queue (job_type,target_key,media_kind,status,attempts,max_attempts,next_attempt_at,created_at,updated_at) VALUES ('actor_media',$1,'avatar_header','pending',0,8,$2,$2,$2) ON CONFLICT(job_type,target_key,media_kind) DO NOTHING",&[&actor,&now]).await;grant.require_current(db).await?;if inserted.is_err(){return Ok(effects)}
 let updated=db.execute("UPDATE ap_media_warm_queue SET status='pending',attempts=0,next_attempt_at=$1,claimed_at=NULL,last_error=NULL,updated_at=$1 WHERE job_type='actor_media' AND target_key=$2 AND media_kind='avatar_header' AND status IN ('succeeded','failed')",&[&now,&actor]).await;grant.require_current(db).await?;if updated.is_err(){return Ok(effects)}effects.media_queued=true;
 effects.media_process=transport.media_process().await;grant.require_current(db).await?;Ok(effects)
}

pub(crate) async fn media_warm(cfg: &Config, db: &Client, raw: &str, transport: &dyn WarmTransport) -> WarmEffects {
    let now = chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs, false);
    let mut effects = WarmEffects::default();
    if media_enqueue_at(cfg, db, raw, &now).await {
        effects.media_queued = true;
        effects.media_process = transport.media_process().await;
    }
    effects
}

pub(crate) async fn media_enqueue_at(cfg: &Config, db: &Client, raw: &str, now: &str) -> bool {
    let actor = crate::library::card::php_trim(raw).trim_end_matches('/');
    // Intentionally matches this PHP helper's case-sensitive prefix-only
    // validation, which differs from the actor queue's FILTER_VALIDATE_URL.
    if actor.is_empty() || !actor.starts_with("https://") { return false; }
    if let Some(mut queue) = queue_connection(cfg).await {
        let lock = tokio::time::timeout(Duration::from_millis(80), redis_util::lock(&mut queue, &format!("media-warm:actor:{}", digest(actor)), 20, &std::process::id().to_string())).await;
        if !matches!(lock, Ok(Ok(true))) { return false; }
    }
    if db.execute("INSERT INTO ap_media_warm_queue (job_type, target_key, media_kind, status, attempts, max_attempts, next_attempt_at, created_at, updated_at) VALUES ('actor_media', $1, 'avatar_header', 'pending', 0, 8, $2, $2, $2) ON CONFLICT(job_type, target_key, media_kind) DO NOTHING", &[&actor, &now]).await.is_err() { return false; }
    db.execute("UPDATE ap_media_warm_queue SET status = 'pending', attempts = 0, next_attempt_at = $1, claimed_at = NULL, last_error = NULL, updated_at = $1 WHERE job_type = 'actor_media' AND target_key = $2 AND media_kind = 'avatar_header' AND status IN ('succeeded', 'failed')", &[&now, &actor]).await.is_ok()
}

/// Safe argv-only PHP worker launches. Paths are operator configuration, and
/// the existing workers perform their own signed fetch/download validation.
pub(crate) struct ProcessWarmTransport { php: PathBuf, api_root: PathBuf, temp_root: PathBuf }
impl ProcessWarmTransport {
    pub(crate) fn configured() -> Self {
        Self { php: std::env::var_os("VAAK_PHP_BIN").map(PathBuf::from).unwrap_or_else(|| "/usr/bin/php".into()), api_root: std::env::var_os("VAAK_API_ROOT").map(PathBuf::from).unwrap_or_else(|| "/srv/mkultra/html/api".into()), temp_root: std::env::temp_dir() }
    }
    fn launch(&self, script: &str, argument: &str) -> bool {
        let mut command = Command::new("nohup");
        command.arg(&self.php).arg(self.api_root.join(script)).arg(argument).stdin(Stdio::null()).stdout(Stdio::null()).stderr(Stdio::null());
        match command.spawn() {
            Ok(mut child) => { tokio::spawn(async move { let _ = child.wait().await; }); true }
            Err(_) => false,
        }
    }
}
impl WarmTransport for ProcessWarmTransport {
    fn actor_process<'a>(&'a self, actor: &'a str) -> EffectFuture<'a> { Box::pin(async move {
        if !self.api_root.join("ap-actor-warm.php").is_file() { return false; }
        let Ok(lock) = std::fs::OpenOptions::new().read(true).write(true).create(true).truncate(false).open(self.temp_root.join(format!("vaak-actor-{}.lock", digest(actor)))) else { return false; };
        if lock.try_lock().is_err() { return false; }
        let count = tokio::time::timeout(Duration::from_millis(250), Command::new("pgrep").args(["-fc", "ap-actor-warm\\.php"]).kill_on_drop(true).output()).await;
        if let Ok(Ok(result)) = count {
            if String::from_utf8_lossy(&result.stdout).trim().parse::<usize>().is_ok_and(|n| n >= 3) { return false; }
        }
        drop(lock); // PHP releases its actor lock before spawning.
        self.launch("ap-actor-warm.php", actor)
    }) }
    fn media_process(&self) -> EffectFuture<'_> { Box::pin(async move {
        if !self.api_root.join("ap-media-warm-worker.php").is_file() { return false; }
        let Ok(lock) = std::fs::OpenOptions::new().read(true).write(true).create(true).truncate(false).open(self.temp_root.join("vaak-media-warm-worker.lock")) else { return false; };
        if lock.try_lock().is_err() { return false; }
        self.launch("ap-media-warm-worker.php", "--limit=3")
    }) }
}

#[cfg(test)] mod tests {
    use super::*;
    #[test] fn actor_validation_keeps_case_and_trims_slash() {
        assert_eq!(actor_key(" HTTPS://Remote.test/users/A/ "), Some("HTTPS://Remote.test/users/A".into()));
        for invalid in ["invalid", "https://", "http://remote.test/a", "https://remote.test/a b", "https://remote_test/a", "https://é.test/a", "https://remote.test/a?x=é", "https://-remote.test/a", "https://remote-.test/a", "https://a..test/a", "https://remote.test:65536/a", "https://u%zz@remote.test/a", "\u{a0}https://remote.test/a"] { assert!(actor_key(invalid).is_none(),"{invalid}"); }
        for valid in ["https://xn--9ca.test/a", "https://remote.test/a%20b", "https://[::1]:443/a", "https://remote.test:+80/a", "https://a./a"] { assert_eq!(actor_key(valid).as_deref(),Some(valid)); }
    }
    fn fixture_config() -> Config {
        Config { database_url:String::new(), redis_url:"redis://127.0.0.1:1/0".into(), redis_queue_url:"redis://127.0.0.1:1/1".into(), jetstream_state:PathBuf::new(), default_owner_id:1, bsky_public_api:String::new(), notif_cache_dir:PathBuf::new(), phyrian_mutation_token:None, phyrian_bridge_url:String::new(), phyrian_bridge_secret:None }
    }
    struct Recorder(std::sync::Mutex<Vec<String>>);
    impl WarmTransport for Recorder {
        fn actor_process<'a>(&'a self, actor: &'a str) -> EffectFuture<'a> { Box::pin(async move { self.0.lock().unwrap().push(format!("actor:{actor}")); true }) }
        fn media_process(&self) -> EffectFuture<'_> { Box::pin(async move { self.0.lock().unwrap().push("media".into()); true }) }
    }
    #[tokio::test]
    #[ignore = "requires genuine PHP FILTER_VALIDATE_URL differential capture"]
    async fn actual_php_actor_url_validation_replay() -> anyhow::Result<()> {
        let reference:serde_json::Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-warm-url-reference-final.json")?)?;
        let recorder=Recorder(std::sync::Mutex::new(Vec::new()));
        for capture in reference["captures"].as_array().unwrap() {
            let input=capture["input"].as_str().unwrap();
            let normalized=actor_key(input);
            assert_eq!(normalized.is_some(),capture["accepted"].as_bool().unwrap(),"{input}");
            if let Some(actor)=normalized { assert_eq!(actor,capture["normalized"].as_str().unwrap()); }
            else { assert_eq!(actor_warm_at(&fixture_config(),input,&recorder,100,false).await,WarmEffects::default()); }
        }
        assert!(recorder.0.lock().unwrap().is_empty());
        Ok(())
    }
    #[tokio::test] async fn actor_unavailable_redis_does_not_spawn_but_primary_off_does() {
        let recorder = Recorder(std::sync::Mutex::new(Vec::new()));
        let cfg = fixture_config();
        assert_eq!(actor_warm_at(&cfg, "https://remote.test/users/asker/", &recorder, 100, true).await, WarmEffects::default());
        assert!(recorder.0.lock().unwrap().is_empty());
        assert!(actor_warm_at(&cfg, "https://remote.test/users/asker/", &recorder, 100, false).await.actor_process);
        assert_eq!(*recorder.0.lock().unwrap(), vec!["actor:https://remote.test/users/asker"]);
        assert_eq!(actor_warm_at(&cfg, "invalid", &recorder, 100, false).await, WarmEffects::default());
    }
    #[tokio::test] async fn process_transport_missing_scripts_and_contended_file_locks_are_noops() -> anyhow::Result<()> {
        let root=std::env::temp_dir().join(format!("profile-warm-process-{}", uuid::Uuid::new_v4()));
        std::fs::create_dir(&root)?;
        let transport=ProcessWarmTransport{php:"/usr/bin/php".into(),api_root:root.clone(),temp_root:root.clone()};
        let actor="https://warm-reference.test/users/actor";
        assert!(!transport.actor_process(actor).await);
        assert!(!transport.media_process().await);
        std::fs::write(root.join("ap-actor-warm.php"), "<?php")?;
        std::fs::write(root.join("ap-media-warm-worker.php"), "<?php")?;
        let actor_lock=std::fs::OpenOptions::new().write(true).create(true).truncate(false).open(root.join(format!("vaak-actor-{}.lock",digest(actor))))?;
        let media_lock=std::fs::OpenOptions::new().write(true).create(true).truncate(false).open(root.join("vaak-media-warm-worker.lock"))?;
        actor_lock.try_lock()?; media_lock.try_lock()?;
        assert!(!transport.actor_process(actor).await);
        assert!(!transport.media_process().await);
        drop(actor_lock); drop(media_lock);
        std::fs::remove_dir_all(root)?;
        Ok(())
    }
    #[tokio::test]
    #[ignore = "requires isolated local RESP listener and genuine PHP actor reference"]
    async fn actual_actor_transport_replay() -> anyhow::Result<()> {
        use tokio::io::{AsyncBufReadExt, AsyncReadExt, AsyncWriteExt, BufReader};
        let reference:serde_json::Value = serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-actor-warm-reference.json")?)?;
        let mut output = Vec::new();
        for capture in reference["captures"].as_array().unwrap() {
            let scenario = &capture["scenario"];
            let listener = tokio::net::TcpListener::bind("127.0.0.1:0").await?;
            let port = listener.local_addr()?.port();
            let commands = std::sync::Arc::new(std::sync::Mutex::new(Vec::<Vec<String>>::new()));
            let recorded = commands.clone();
            let lock = scenario["lock"].as_bool().unwrap();
            let push = scenario["push"].as_bool().unwrap();
            let server = tokio::spawn(async move {
                let Ok((stream,_)) = listener.accept().await else { return; };
                let (read, mut write) = stream.into_split();
                let mut reader = BufReader::new(read);
                loop {
                    let mut head = String::new();
                    if reader.read_line(&mut head).await.unwrap_or(0) == 0 { break; }
                    let Ok(count) = head.trim().trim_start_matches('*').parse::<usize>() else { break; };
                    let mut args = Vec::new();
                    for _ in 0..count {
                        head.clear(); if reader.read_line(&mut head).await.is_err() { return; }
                        let Ok(len) = head.trim().trim_start_matches('$').parse::<usize>() else { return; };
                        let mut value=vec![0;len+2]; if reader.read_exact(&mut value).await.is_err() { return; }
                        args.push(String::from_utf8_lossy(&value[..len]).into_owned());
                    }
                    let answer = match args[0].as_str() {
                        "SET" => if lock { "+OK\r\n" } else { "$-1\r\n" },
                        "LPUSH" => if push { ":1\r\n" } else { "-ERR fixture push failure\r\n" },
                        "EXPIRE" => ":1\r\n",
                        _ => "+OK\r\n",
                    };
                    if matches!(args[0].as_str(), "SET"|"LPUSH"|"EXPIRE") { recorded.lock().unwrap().push(args); }
                    if write.write_all(answer.as_bytes()).await.is_err() { return; }
                }
            });
            struct ScenarioProcess { running:i64, calls:std::sync::Mutex<Vec<String>> }
            impl WarmTransport for ScenarioProcess {
                fn actor_process<'a>(&'a self, actor:&'a str)->EffectFuture<'a> { Box::pin(async move { if self.running >= 3 { return false; } self.calls.lock().unwrap().push(actor.into()); true }) }
                fn media_process(&self)->EffectFuture<'_> { Box::pin(async { false }) }
            }
            let recorder = ScenarioProcess {running:scenario["running"].as_i64().unwrap(),calls:std::sync::Mutex::new(Vec::new())};
            let mut cfg=fixture_config(); cfg.redis_queue_url=format!("redis://127.0.0.1:{port}/1");
            let effects = actor_warm_at(&cfg,capture["input"].as_str().unwrap(),&recorder,reference["clock"].as_i64().unwrap(),scenario["primary"]!="0").await;
            let expected=capture["effects"].as_array().unwrap();
            let expects_queue=expected.iter().any(|e|e["kind"]=="redis_push"&&e["result"]==true);
            let expects_process=expected.iter().any(|e|e["kind"]=="process_spawn_record_only");
            assert_eq!(effects.actor_queued,expects_queue,"{}",capture["case"]);
            assert_eq!(effects.actor_process,expects_process,"{}",capture["case"]);
            let calls=commands.lock().unwrap().clone();
            if let Some(expected_lock)=expected.iter().find(|e|e["kind"]=="redis_lock") {
                let actual=calls.iter().find(|a|a[0]=="SET").unwrap();
                assert_eq!(actual[1],format!("vaak:lock:{}",expected_lock["key"].as_str().unwrap()));
                assert_eq!(&actual[3..], &["NX", "EX", "45"]);
            }
            if let Some(expected_push)=expected.iter().find(|e|e["kind"]=="redis_push") {
                let actual=calls.iter().find(|a|a[0]=="LPUSH").unwrap();
                assert_eq!(actual[1],"vaak:queue:ap_actor_warm");
                assert_eq!(serde_json::from_str::<serde_json::Value>(&actual[2])?,expected_push["payload"]);
            }
            if expects_queue { assert!(calls.iter().any(|a|a==&vec!["EXPIRE".to_string(),"vaak:queue:ap_actor_warm".to_string(),"86400".to_string()])); }
            output.push(serde_json::json!({"case":capture["case"],"effects":effects,"redis_commands":calls,"process_actors":*recorder.calls.lock().unwrap()}));
            server.abort();
        }
        std::fs::write("/tmp/vaak-local-profile-actor-warm-native.json",serde_json::to_vec_pretty(&serde_json::json!({"checks":output.len(),"captures":output,"scope":"actual Redis wire commands with local fault transport; PHP process effect recorder only; no signed AS2 fetch proof"}))?)?;
        Ok(())
    }
    #[tokio::test]
    #[ignore = "requires disposable PostgreSQL connection and owned RESP fault listener"]
    async fn actual_guarded_actor_wire_revocations() -> anyhow::Result<()> {
        use tokio::io::{AsyncBufReadExt,AsyncReadExt,AsyncWriteExt,BufReader};
        use std::sync::{Arc,atomic::{AtomicBool,Ordering}};
        let (db,connection)=tokio_postgres::connect(&std::env::var("LIBRARY_FIXTURE_DB")?,tokio_postgres::NoTls).await?;
        tokio::spawn(async move{let _=connection.await;});
        struct Grant(Arc<AtomicBool>);
        impl crate::profile_enrichment::CurrentEnrichmentGrant for Grant {
            fn require_current<'a>(&'a self,_:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=anyhow::Result<()>>+Send+'a>> {
                Box::pin(async move{anyhow::ensure!(self.0.load(Ordering::SeqCst),"source withdrawn");Ok(())})
            }
        }
        let mut output=Vec::new();
        for case in ["expire_error","push_error","silent_push","revoke_push_error","revoke_expire_error","revoke_lock"] {
            let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;
            let port=listener.local_addr()?.port();
            let live=Arc::new(AtomicBool::new(true));let changed=live.clone();
            let commands=Arc::new(std::sync::Mutex::new(Vec::<String>::new()));let recorded=commands.clone();
            let server=tokio::spawn(async move {
                let (stream,_)=listener.accept().await.unwrap();let(read,mut write)=stream.into_split();let mut reader=BufReader::new(read);
                loop {
                    let mut head=String::new();if reader.read_line(&mut head).await.unwrap_or(0)==0{return}
                    let Ok(count)=head.trim().trim_start_matches('*').parse::<usize>()else{return};let mut args=Vec::new();
                    for _ in 0..count {head.clear();reader.read_line(&mut head).await.unwrap();let len=head.trim().trim_start_matches('$').parse::<usize>().unwrap();let mut bytes=vec![0;len+2];reader.read_exact(&mut bytes).await.unwrap();args.push(String::from_utf8_lossy(&bytes[..len]).to_string());}
                    let command=args[0].as_str();recorded.lock().unwrap().push(command.into());
                    if (case=="revoke_lock"&&command=="SET")||(case=="revoke_push_error"&&command=="LPUSH")||(case=="revoke_expire_error"&&command=="EXPIRE") {changed.store(false,Ordering::SeqCst);}
                    if case=="silent_push"&&command=="LPUSH" {std::future::pending::<()>().await;}
                    let answer=match command {"SET"=>"+OK\r\n","LPUSH" if case.contains("push_error")=>"-ERR push\r\n","EXPIRE" if case.contains("expire_error")=>"-ERR expire\r\n","LPUSH"|"EXPIRE"=>":1\r\n",_=>"+OK\r\n"};
                    if write.write_all(answer.as_bytes()).await.is_err(){return}
                }
            });
            let mut cfg=fixture_config();cfg.redis_queue_url=format!("redis://127.0.0.1:{port}/1");
            let recorder=Recorder(std::sync::Mutex::new(Vec::new()));let started=std::time::Instant::now();
            let result=tokio::time::timeout(Duration::from_secs(1),actor_warm_guarded_mode(&cfg,&db,"https://remote.test/users/a",&recorder,&Grant(live),true)).await?;
            let calls=commands.lock().unwrap().clone();let process=recorder.0.lock().unwrap().clone();
            if case.starts_with("revoke") {assert!(result.is_err());assert!(process.is_empty());if case=="revoke_lock"{assert!(!calls.contains(&"LPUSH".to_string()));}if case=="revoke_push_error"{assert!(!calls.contains(&"EXPIRE".to_string()));}}
            else {let effects=result?;assert!(effects.actor_process);assert!(!effects.actor_queued);assert_eq!(process.len(),1);}
            output.push(serde_json::json!({"case":case,"commands":calls,"process":process,"elapsed_ms":started.elapsed().as_millis()}));server.abort();
        }
        std::fs::write("/tmp/vaak-profile-guarded-actor-wire-native.json",serde_json::to_vec_pretty(&output)?)?;Ok(())
    }
    #[tokio::test]
    #[ignore = "requires isolated genuine PHP media queue reference and PostgreSQL socket"]
    async fn actual_media_enqueue_replay() -> anyhow::Result<()> {
        let url = std::env::var("PROFILE_WARM_FIXTURE_DB")?;
        anyhow::ensure!(url.contains("/tmp/"), "isolated Unix socket required");
        let db = crate::db::connect(&url).await?;
        let reference: serde_json::Value = serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_WARM_MEDIA_REFERENCE").unwrap_or_else(|_| "/tmp/vaak-local-profile-warm-dependency-reference.json".into()))?)?;
        let mut output = Vec::new();
        for width in ["INTEGER", "BIGINT"] {
            let schema = format!("profile_warm_native_{}_{}", width.to_ascii_lowercase(), std::process::id());
            db.batch_execute(&format!("CREATE SCHEMA {schema}; SET search_path={schema}")).await?;
            let ddl = reference["media_queue_ddl"].as_str().unwrap().replace("INTEGER PRIMARY KEY AUTOINCREMENT", &format!("{width} GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY"));
            db.batch_execute(&ddl).await?;
            for capture in reference["captures"].as_array().unwrap().iter().filter(|c| c["width"] == width) {
                if capture["before"].is_null() {
                    db.batch_execute("DROP TABLE ap_media_warm_queue").await?;
                    assert!(!media_enqueue_at(&fixture_config(), &db, capture["input"].as_str().unwrap(), capture["clock"].as_str().unwrap()).await);
                    output.push(serde_json::json!({"width":width,"case":capture["case"],"result":false,"before":null,"after":null}));
                    db.batch_execute(&ddl).await?;
                    continue;
                }
                db.batch_execute("DELETE FROM ap_media_warm_queue; ALTER TABLE ap_media_warm_queue ALTER COLUMN id RESTART WITH 1").await?;
                for row in capture["before"].as_array().unwrap() { db.execute("INSERT INTO ap_media_warm_queue SELECT * FROM jsonb_populate_record(NULL::ap_media_warm_queue,$1)", &[row]).await?; }
                let now = capture["clock"].as_str().or_else(||capture["after"][0]["updated_at"].as_str()).unwrap_or("2026-10-11T07:49:17+00:00");
                let result = media_enqueue_at(&fixture_config(), &db, capture["input"].as_str().unwrap(), now).await;
                let rows = db.query("SELECT to_jsonb(q) FROM ap_media_warm_queue q ORDER BY id", &[]).await?.into_iter().map(|r| r.get::<_,serde_json::Value>(0)).collect::<Vec<_>>();
                assert_eq!(result, capture["result"].as_bool().unwrap());
                assert_eq!(serde_json::json!(rows), capture["after"]);
                output.push(serde_json::json!({"width":width,"input":capture["input"],"result":result,"after":rows}));
            }
            db.batch_execute(&format!("SET search_path=public; DROP SCHEMA {schema} CASCADE")).await?;
        }
        std::fs::write(std::env::var("PROFILE_WARM_MEDIA_OUTPUT").unwrap_or_else(|_| "/tmp/vaak-local-profile-warm-dependency-native.json".into()), serde_json::to_vec_pretty(&serde_json::json!({"checks":output.len(),"captures":output,"scope":"actual durable media SQL effects; actor signed fetch/media downloader remain PHP; process transport separate"}))?)?;
        Ok(())
    }
}
