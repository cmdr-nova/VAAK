//! NIP-46 remote signing, for browsers with no signer extension (a site
//! installed to the iPhone Home Screen never loads Safari extensions).
//!
//! The user runs a bunker that holds their key somewhere else
//! (ops/nostr-bunker/vaak_bunker.py) and pastes its `bunker://` link in
//! Settings > Profile. VAAK makes a client key of its own for that pairing
//! and keeps it in nostr_bunkers: it can only *ask* the bunker to sign, and
//! the bunker decides which kinds it signs and can revoke it. VAAK still
//! never holds the user's Nostr key.
//!
//! Signing reuses the NIP-07 flow: `act::prepare` parks the unsigned event,
//! the page asks [`sign`] instead of an extension, and the signed copy goes
//! through `act::publish` and its checks like any other.

use std::time::Duration;

use anyhow::{Context, Result};
use futures_util::stream::{FuturesUnordered, StreamExt};
use serde_json::{json, Value};
use tokio::time::Instant;
use tokio_postgres::Client;

use super::act::{self, Pending};
use super::event::{self, Event};
use super::{nip44, relay};
use crate::config::Config;

pub const KIND: i64 = 24133;
const MAX_RELAYS: usize = 4;
/// A bunker on a phone may need a moment to wake; a server one answers in
/// well under a second.
const SIGN_DEADLINE: Duration = Duration::from_secs(20);

fn refuse(message: &str) -> Result<Value> {
    Ok(json!({"ok": false, "error": message}))
}

/// What a `bunker://<signer pubkey>?relay=…&secret=…` link names.
#[derive(Debug, Clone, PartialEq)]
pub struct BunkerUri {
    pub signer: String,
    pub relays: Vec<String>,
    pub secret: String,
}

fn percent_decode(raw: &str) -> String {
    let bytes = raw.as_bytes();
    let mut out = Vec::with_capacity(bytes.len());
    let mut i = 0;
    while i < bytes.len() {
        match bytes[i] {
            b'%' if i + 2 < bytes.len() => {
                match std::str::from_utf8(&bytes[i + 1..i + 3]).ok().and_then(|h| u8::from_str_radix(h, 16).ok()).ok_or(()) {
                    Ok(b) => {
                        out.push(b);
                        i += 3;
                        continue;
                    }
                    Err(_) => out.push(b'%'),
                }
            }
            b'+' => out.push(b' '),
            b => out.push(b),
        }
        i += 1;
    }
    String::from_utf8_lossy(&out).into_owned()
}

pub fn parse_uri(raw: &str) -> Result<BunkerUri, &'static str> {
    let raw = raw.trim();
    if raw.len() > 2000 {
        return Err("That bunker link is too long.");
    }
    let rest = raw
        .get(..9)
        .filter(|p| p.eq_ignore_ascii_case("bunker://"))
        .map(|_| &raw[9..])
        .ok_or("Paste the whole bunker:// link from your bunker.")?;
    let (key, query) = rest.split_once('?').unwrap_or((rest, ""));
    let signer = key.trim_end_matches('/').to_ascii_lowercase();
    if !event::is_hex64(&signer) {
        return Err("That bunker link has no signer key in it.");
    }
    let mut relays = Vec::new();
    let mut secret = String::new();
    for pair in query.split('&').filter(|p| !p.is_empty()) {
        let (name, value) = pair.split_once('=').unwrap_or((pair, ""));
        let value = percent_decode(value);
        match name {
            "relay" => {
                if let Some(url) = relay::normalize_relay_url(&value) {
                    if !relays.contains(&url) && relays.len() < MAX_RELAYS {
                        relays.push(url);
                    }
                }
            }
            "secret" => secret = value.chars().filter(|c| c.is_ascii_graphic()).take(200).collect(),
            _ => {}
        }
    }
    if relays.is_empty() {
        return Err("That bunker link names no usable relay (VAAK needs wss:// relays).");
    }
    Ok(BunkerUri { signer, relays, secret })
}

/// VAAK's side of one pairing.
struct Session {
    secret: [u8; 32],
    pubkey: String,
    signer: String,
    relays: Vec<String>,
}

fn pubkey_of(secret: &[u8; 32]) -> Result<String> {
    let sk = k256::schnorr::SigningKey::from_slice(secret).map_err(|_| anyhow::anyhow!("bad client key"))?;
    Ok(hex::encode(sk.verifying_key().to_bytes()))
}

fn new_client_key() -> Result<[u8; 32]> {
    use rand::TryRng;
    loop {
        let mut secret = [0u8; 32];
        rand::rngs::SysRng.try_fill_bytes(&mut secret).map_err(|_| anyhow::anyhow!("no randomness"))?;
        if k256::schnorr::SigningKey::from_slice(&secret).is_ok() {
            return Ok(secret);
        }
    }
}

fn random_hex(n: usize) -> String {
    use rand::TryRng;
    let mut b = vec![0u8; n];
    rand::rngs::SysRng.try_fill_bytes(&mut b).expect("os rng");
    hex::encode(b)
}

/// Sign an event as the client key (the request envelope, never a note).
fn sign_as(secret: &[u8; 32], kind: i64, tags: Vec<Vec<String>>, content: String, created_at: i64) -> Result<Event> {
    let sk = k256::schnorr::SigningKey::from_slice(secret).map_err(|_| anyhow::anyhow!("bad client key"))?;
    let mut ev = Event {
        id: String::new(),
        pubkey: hex::encode(sk.verifying_key().to_bytes()),
        created_at,
        kind,
        tags,
        content,
        sig: String::new(),
    };
    ev.id = ev.computed_id();
    let id = hex::decode(&ev.id)?;
    let mut aux = [0u8; 32];
    {
        use rand::TryRng;
        rand::rngs::SysRng.try_fill_bytes(&mut aux).map_err(|_| anyhow::anyhow!("no randomness"))?;
    }
    let sig = sk.sign_raw(&id, &aux).map_err(|_| anyhow::anyhow!("signing failed"))?;
    ev.sig = hex::encode(sig.to_bytes());
    Ok(ev)
}

/// The signer's answer: Ok(result) or Err(its error text).
type Answer = std::result::Result<String, String>;

/// One request to the bunker over every relay at once; the first answer wins.
async fn request(session: &Session, method: &str, params: Vec<String>, deadline: Instant) -> Result<Answer> {
    let id = random_hex(16);
    let conversation = nip44::conversation_key(&session.secret, &session.signer).map_err(|e| anyhow::anyhow!(e))?;
    let body = json!({"id": id, "method": method, "params": params}).to_string();
    let content = nip44::encrypt(&conversation, &body).map_err(|e| anyhow::anyhow!(e))?;
    let now = chrono::Utc::now().timestamp();
    let envelope = sign_as(&session.secret, KIND, vec![vec!["p".into(), session.signer.clone()]], content, now)?;
    let filter = json!({"kinds": [KIND], "#p": [session.pubkey], "authors": [session.signer], "since": now - 10});
    let accept = |ev: &Event| -> Option<Answer> {
        if ev.kind != KIND
            || ev.pubkey != session.signer
            || !ev.tags.iter().any(|t| t.first().map(String::as_str) == Some("p") && t.get(1) == Some(&session.pubkey))
            || ev.verify().is_err()
        {
            return None;
        }
        let text = nip44::decrypt(&conversation, &ev.content).ok()?;
        let reply: Value = serde_json::from_str(&text).ok()?;
        if reply.get("id").and_then(Value::as_str) != Some(id.as_str()) {
            return None;
        }
        let result = reply.get("result").and_then(Value::as_str).unwrap_or("").to_string();
        let error = reply.get("error").and_then(Value::as_str).unwrap_or("").to_string();
        Some(if result == "auth_url" {
            Err(format!("Your bunker wants you to approve VAAK first: {error}"))
        } else if !error.is_empty() {
            Err(error)
        } else {
            Ok(result)
        })
    };
    let mut pending: FuturesUnordered<_> = session
        .relays
        .iter()
        .map(|url| {
            let envelope = &envelope;
            let filter = &filter;
            let accept = &accept;
            async move { (url.clone(), relay::rpc(url, envelope, filter, deadline, accept).await) }
        })
        .collect();
    let mut problems = Vec::new();
    while let Some((url, res)) = pending.next().await {
        match res {
            Ok(answer) => return Ok(answer),
            Err(e) => problems.push(format!("{url}: {e:#}")),
        }
    }
    anyhow::bail!("{}", problems.join(" | "))
}

pub async fn tables_ready(db: &Client) -> bool {
    db.query_one("SELECT to_regclass('nostr_bunkers') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false)
}

async fn session_for(db: &Client, owner: i64) -> Result<Option<Session>> {
    let Some(row) = db
        .query_opt("SELECT client_secret, signer_pubkey, relays FROM nostr_bunkers WHERE owner_user_id = $1", &[&owner])
        .await
        .context("nostr bunker")?
    else {
        return Ok(None);
    };
    let secret_hex: String = row.get(0);
    let relays: Value = row.get(2);
    let mut secret = [0u8; 32];
    if hex::decode_to_slice(secret_hex.trim(), &mut secret).is_err() {
        return Ok(None);
    }
    Ok(Some(Session {
        pubkey: pubkey_of(&secret)?,
        secret,
        signer: row.get(1),
        relays: relays.as_array().into_iter().flatten().filter_map(Value::as_str).filter_map(relay::normalize_relay_url).collect(),
    }))
}

async fn note_result(db: &Client, owner: i64, error: &str) {
    let now = chrono::Utc::now().timestamp();
    let sql = if error.is_empty() {
        "UPDATE nostr_bunkers SET last_used_at = $2, last_error = $3 WHERE owner_user_id = $1"
    } else {
        "UPDATE nostr_bunkers SET last_error = $3, last_error_at = $2 WHERE owner_user_id = $1"
    };
    let error: String = error.chars().take(300).collect();
    let _ = db.execute(sql, &[&owner, &now, &error]).await;
}

/// Pair with the bunker in `uri`: `connect` (with its one-time secret), then
/// `get_public_key`, which must be the npub linked in Settings > Profile.
pub async fn connect(cfg: &Config, owner: i64, uri: &str) -> Result<Value> {
    if super::sync::disabled_by_env() {
        return refuse("Nostr is turned off on this server.");
    }
    let db = crate::db::connect(&cfg.database_url).await?;
    if !super::sync::tables_ready(&db).await || !tables_ready(&db).await {
        return refuse("Nostr bunkers aren't set up on this server yet.");
    }
    let Some((me, _, _)) = act::linked_pubkey(&db, owner).await? else {
        return refuse("Link your npub in Settings > Profile first.");
    };
    let parsed = match parse_uri(uri) {
        Ok(p) => p,
        Err(e) => return refuse(e),
    };
    let secret = new_client_key()?;
    let session = Session { pubkey: pubkey_of(&secret)?, secret, signer: parsed.signer.clone(), relays: parsed.relays.clone() };
    let deadline = Instant::now() + SIGN_DEADLINE;
    let perms = "sign_event:1,sign_event:3,sign_event:5,sign_event:6,sign_event:7,sign_event:1984,sign_event:10000".to_string();
    match request(&session, "connect", vec![parsed.signer.clone(), parsed.secret.clone(), perms], deadline).await {
        Ok(Ok(result)) if result == "ack" || (!parsed.secret.is_empty() && result == parsed.secret) => {}
        Ok(Ok(other)) => {
            tracing::warn!(owner, answer = %other.chars().take(80).collect::<String>(), "nostr bunker connect: unexpected answer");
            return refuse("The bunker answered, but not with an OK. Make a fresh link with `pair` and try again.");
        }
        Ok(Err(e)) => return refuse(&format!("The bunker said no: {}", e.chars().take(200).collect::<String>())),
        Err(e) => {
            tracing::warn!(owner, "nostr bunker connect: {e:#}");
            return refuse("The bunker didn't answer on its relays. Is it running?");
        }
    }
    let user = match request(&session, "get_public_key", Vec::new(), Instant::now() + SIGN_DEADLINE).await {
        Ok(Ok(pk)) => pk.trim().to_ascii_lowercase(),
        Ok(Err(e)) => return refuse(&format!("The bunker wouldn't share your key: {}", e.chars().take(200).collect::<String>())),
        Err(e) => {
            tracing::warn!(owner, "nostr bunker get_public_key: {e:#}");
            return refuse("The bunker stopped answering. Try again.");
        }
    };
    if user != me {
        return refuse("That bunker holds a different key than the npub linked in Settings > Profile.");
    }
    let now = chrono::Utc::now().timestamp();
    db.execute(
        "INSERT INTO nostr_bunkers (owner_user_id, signer_pubkey, relays, client_secret, user_pubkey, connected_at)
         VALUES ($1, $2, $3, $4, $5, $6)
         ON CONFLICT (owner_user_id) DO UPDATE SET signer_pubkey = EXCLUDED.signer_pubkey, relays = EXCLUDED.relays,
             client_secret = EXCLUDED.client_secret, user_pubkey = EXCLUDED.user_pubkey, connected_at = EXCLUDED.connected_at,
             last_used_at = 0, last_error = '', last_error_at = 0",
        &[&owner, &parsed.signer, &json!(parsed.relays), &hex::encode(secret), &user, &now],
    )
    .await
    .context("nostr bunker save")?;
    tracing::info!(owner, relays = parsed.relays.len(), "nostr bunker paired");
    Ok(json!({"ok": true, "signer": super::bech32::npub(&parsed.signer), "client": super::bech32::npub(&session.pubkey)}))
}

/// Sign the event `act::prepare` parked under `nonce` through the user's
/// bunker. `content` is the user's text for a reply or quote and ignored
/// otherwise. The answer is checked here and again by `act::publish`.
pub async fn sign(cfg: &Config, owner: i64, nonce: &str, content: &str) -> Result<Value> {
    if super::sync::disabled_by_env() {
        return refuse("Nostr is turned off on this server.");
    }
    let db = crate::db::connect(&cfg.database_url).await?;
    if !tables_ready(&db).await {
        return Ok(json!({"ok": false, "no_bunker": true, "error": "No Nostr bunker is connected."}));
    }
    let Some(session) = session_for(&db, owner).await? else {
        return Ok(json!({"ok": false, "no_bunker": true, "error": "No Nostr bunker is connected."}));
    };
    let Some((me, _, _)) = act::linked_pubkey(&db, owner).await? else {
        return refuse("Link your npub in Settings > Profile first.");
    };
    let nonce: String = nonce.chars().filter(char::is_ascii_hexdigit).take(32).collect();
    let mut redis = crate::redis_util::connect(&cfg.redis_url).await?;
    let raw: Option<String> = redis::cmd("GET")
        .arg(act::pending_key(owner, &nonce))
        .query_async(&mut redis)
        .await
        .context("nostr pending read")?;
    let Some(pending) = raw.and_then(|r| serde_json::from_str::<Pending>(&r).ok()) else {
        return refuse("That action expired. Try again.");
    };
    let now = chrono::Utc::now().timestamp();
    let mut unsigned = Event {
        id: String::new(),
        pubkey: me.clone(),
        created_at: now,
        kind: pending.kind,
        tags: pending.tags.clone(),
        content: pending.content.clone().unwrap_or_else(|| content.to_string()),
        sig: String::new(),
    };
    if let Err(e) = act::check_template(&unsigned, &pending, now) {
        return refuse(&e);
    }
    unsigned.id = unsigned.computed_id();
    let template = json!({"kind": unsigned.kind, "content": unsigned.content, "tags": unsigned.tags, "created_at": unsigned.created_at});
    let answer = match request(&session, "sign_event", vec![template.to_string()], Instant::now() + SIGN_DEADLINE).await {
        Ok(Ok(signed)) => signed,
        Ok(Err(e)) => {
            note_result(&db, owner, &e).await;
            return refuse(&format!("Your bunker didn't sign it: {}", e.chars().take(200).collect::<String>()));
        }
        Err(e) => {
            tracing::warn!(owner, purpose = %pending.purpose, "nostr bunker sign: {e:#}");
            note_result(&db, owner, "no answer").await;
            return refuse("Your Nostr bunker didn't answer. Is it running?");
        }
    };
    let Ok(signed) = serde_json::from_str::<Event>(&answer) else {
        note_result(&db, owner, "bad answer").await;
        return refuse("Your bunker answered with something that isn't a signed event.");
    };
    if signed.verify().is_err()
        || signed.pubkey != me
        || signed.id != unsigned.id
        || signed.kind != unsigned.kind
        || signed.tags != unsigned.tags
        || signed.content != unsigned.content
        || signed.created_at != unsigned.created_at
    {
        note_result(&db, owner, "changed event").await;
        return refuse("Your bunker signed something other than what VAAK asked for.");
    }
    note_result(&db, owner, "").await;
    Ok(json!({"ok": true, "event": signed}))
}

#[cfg(test)]
mod tests {
    use super::*;

    const SIGNER: &str = "fa984bd7dbb282f07e16e7ae87b26a2a7b9b90b7246a44771f0cf5ae58018f52";

    #[test]
    fn parses_bunker_links() {
        let uri = format!("bunker://{SIGNER}?relay=wss%3A%2F%2Frelay.nsec.app&relay=wss://nos.lol/&secret=abc123");
        let parsed = parse_uri(&uri).unwrap();
        assert_eq!(parsed.signer, SIGNER);
        assert_eq!(parsed.relays, vec!["wss://relay.nsec.app".to_string(), "wss://nos.lol".to_string()]);
        assert_eq!(parsed.secret, "abc123");
    }

    #[test]
    fn refuses_bad_links() {
        assert!(parse_uri("nostrconnect://abc").is_err());
        assert!(parse_uri("bunker://nothex?relay=wss://nos.lol").is_err());
        // Plain ws:// and private hosts never reach the relay client.
        assert!(parse_uri(&format!("bunker://{SIGNER}?relay=ws://nos.lol")).is_err());
        assert!(parse_uri(&format!("bunker://{SIGNER}?relay=wss://localhost")).is_err());
    }

    /// Against a real bunker through a fake relay (debug builds send relays
    /// to VAAK_NOSTR_TEST_RELAY_BASE): set VAAK_BUNKER_E2E_URI to a fresh
    /// `pair` link and VAAK_BUNKER_E2E_USER to the bunker's user hex key,
    /// then `cargo test bunker_e2e -- --ignored`.
    #[tokio::test]
    #[ignore]
    async fn bunker_e2e() {
        let uri = std::env::var("VAAK_BUNKER_E2E_URI").expect("VAAK_BUNKER_E2E_URI");
        let user = std::env::var("VAAK_BUNKER_E2E_USER").expect("VAAK_BUNKER_E2E_USER");
        let parsed = parse_uri(&uri).unwrap();
        let secret = new_client_key().unwrap();
        let session = Session { pubkey: pubkey_of(&secret).unwrap(), secret, signer: parsed.signer.clone(), relays: parsed.relays.clone() };
        let deadline = || Instant::now() + SIGN_DEADLINE;
        let ack = request(&session, "connect", vec![parsed.signer.clone(), parsed.secret.clone(), String::new()], deadline()).await.unwrap();
        assert_eq!(ack, Ok("ack".to_string()));
        // The pairing secret works once.
        let other = Session { pubkey: String::new(), secret: new_client_key().unwrap(), signer: parsed.signer.clone(), relays: parsed.relays.clone() };
        let other = Session { pubkey: pubkey_of(&other.secret).unwrap(), ..other };
        let again = request(&other, "connect", vec![parsed.signer.clone(), parsed.secret.clone(), String::new()], deadline()).await.unwrap();
        assert!(again.is_err(), "{again:?}");
        assert!(request(&other, "sign_event", vec!["{}".into()], deadline()).await.unwrap().is_err());
        assert_eq!(request(&session, "get_public_key", vec![], deadline()).await.unwrap(), Ok(user.clone()));
        let now = chrono::Utc::now().timestamp();
        let tpl = json!({"kind": 1, "content": "hello from the e2e test", "tags": [["t", "vaak"]], "created_at": now});
        let signed = request(&session, "sign_event", vec![tpl.to_string()], deadline()).await.unwrap().unwrap();
        let ev: Event = serde_json::from_str(&signed).unwrap();
        assert!(ev.verify().is_ok());
        assert_eq!(ev.pubkey, user);
        assert_eq!((ev.kind, ev.created_at, ev.content.as_str()), (1, now, "hello from the e2e test"));
        let profile = json!({"kind": 0, "content": "{}", "tags": [], "created_at": now});
        let refused = request(&session, "sign_event", vec![profile.to_string()], deadline()).await.unwrap();
        assert!(refused.is_err(), "{refused:?}");
    }

    #[test]
    fn envelopes_verify() {
        let secret = new_client_key().unwrap();
        let ev = sign_as(&secret, KIND, vec![vec!["p".into(), SIGNER.into()]], "x".into(), 1_700_000_000).unwrap();
        assert!(ev.verify().is_ok());
        assert_eq!(ev.pubkey, pubkey_of(&secret).unwrap());
    }
}
