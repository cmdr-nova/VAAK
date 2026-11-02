//! NIP-57 zaps: Lightning tips on Nostr notes.
//!
//! Where the sats go: a Nostr author names a Lightning address (`lud16`,
//! like `name@wallet.example`) or an LNURL (`lud06`) in their kind 0
//! profile. Profile sync keeps the matching LNURL-pay URL in
//! `nostr_zap_addrs`. That wallet provider is who gets paid.
//!
//! Sending: `act::prepare` (purpose "zap") asks the provider for its pay
//! info and builds a kind 9734 zap request naming the note, its author and
//! the amount. The user's signer signs it, and `act::publish` hands it to the
//! provider's callback instead of to relays. The provider answers with a
//! bolt11 invoice for exactly that amount, which the payer's own wallet pays
//! (WebLN in the browser, or a QR code / `lightning:` link). VAAK never holds
//! a key, a wallet secret or any money.
//!
//! Paid yet? `publish` keeps a short-lived watch on each invoice (Redis,
//! act::zap_status). The pay panel asks until it hears yes: from the
//! provider's LUD-21 `verify` URL when it gave one, otherwise from the
//! provider-signed receipt for that exact invoice on the receipt relays.
//!
//! Receiving: once paid, the provider publishes a kind 9735 receipt that
//! tags the recipient. `notif` reads those for linked keys. A receipt counts
//! only when the recipient's provider signed it (its LNURL `nostrPubkey`),
//! the zap request inside it is validly signed, names the recipient and the
//! same note, and the invoice amount matches the request's.

use std::net::{IpAddr, SocketAddr};
use std::time::Duration;

use anyhow::{anyhow, bail, Context, Result};
use serde_json::Value;
use tokio_postgres::Client;

use super::bech32;
use super::event::{self, Event};

pub const RECEIPT_KIND: i64 = 9735;
pub const REQUEST_KIND: i64 = 9734;
const FETCH_TIMEOUT: Duration = Duration::from_secs(10);
const CONNECT_TIMEOUT: Duration = Duration::from_secs(5);
const MAX_BODY_BYTES: usize = 64 * 1024;
const MAX_URL_LEN: usize = 1000;
/// Pay info older than this is asked for again before a zap is prepared.
pub const SEND_MAX_AGE_SECS: i64 = 3600;
/// Receipt checks reuse the provider key this long.
pub const RECEIPT_MAX_AGE_SECS: i64 = 6 * 3600;
/// When the provider can't be reached, a cached answer this old still does.
const STALE_OK_SECS: i64 = 7 * 86_400;

/// The LNURL-pay endpoint a profile names: `lud16` first, then `lud06`.
pub fn pay_url(lud16: &str, lud06: &str) -> Option<String> {
    lud16_url(lud16).or_else(|| lud06_url(lud06))
}

/// LUD-16: `user@domain` → `https://domain/.well-known/lnurlp/user`.
fn lud16_url(addr: &str) -> Option<String> {
    let addr = addr.trim().to_ascii_lowercase();
    let (user, domain) = addr.split_once('@')?;
    let user_ok = !user.is_empty()
        && user.len() <= 64
        && user.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || matches!(b, b'-' | b'_' | b'.' | b'+'));
    let domain_ok = domain.len() <= 253
        && domain.contains('.')
        && domain.split('.').all(|label| {
            !label.is_empty()
                && label.len() <= 63
                && !label.starts_with('-')
                && !label.ends_with('-')
                && label.bytes().all(|b| b.is_ascii_lowercase() || b.is_ascii_digit() || b == b'-')
        });
    (user_ok && domain_ok).then(|| format!("https://{domain}/.well-known/lnurlp/{user}"))
}

/// LUD-06: a bech32 `lnurl1…` holding an https URL.
fn lud06_url(lnurl: &str) -> Option<String> {
    let s = lnurl.trim();
    let s = s.strip_prefix("lightning:").or_else(|| s.strip_prefix("LIGHTNING:")).unwrap_or(s);
    let (hrp, bytes) = bech32::decode(s)?;
    let url = String::from_utf8(bytes).ok().filter(|_| hrp == "lnurl")?;
    https_url(&url)
}

fn https_url(raw: &str) -> Option<String> {
    let parsed = reqwest::Url::parse(raw.trim()).ok()?;
    (parsed.scheme() == "https"
        && parsed.host_str().is_some_and(|h| !h.is_empty())
        && parsed.username().is_empty()
        && parsed.password().is_none()
        && raw.len() <= MAX_URL_LEN)
        .then(|| parsed.to_string())
}

/// The `lnurl` tag value for a zap request (bech32 of the pay URL).
pub fn lnurl_of(url: &str) -> String {
    bech32::encode("lnurl", url.as_bytes())
}

/// The amount of a BOLT-11 invoice in millisatoshis. None for an invoice
/// without an amount, or one that isn't a Lightning invoice.
pub fn bolt11_msats(invoice: &str) -> Option<i64> {
    let inv = invoice.trim().to_ascii_lowercase();
    let inv = inv.strip_prefix("lightning:").unwrap_or(&inv);
    let sep = inv.rfind('1')?;
    let rest = inv[..sep].strip_prefix("ln")?;
    // Longest currency prefixes first (bcrt before bc).
    let amount = ["bcrt", "tbs", "bc", "tb", "sb"].iter().find_map(|c| rest.strip_prefix(c))?;
    let (digits, mult) = match amount.as_bytes().last()? {
        b @ (b'm' | b'u' | b'n' | b'p') => (&amount[..amount.len() - 1], Some(*b)),
        _ => (amount, None),
    };
    if digits.is_empty() || digits.starts_with('0') || !digits.bytes().all(|b| b.is_ascii_digit()) || digits.len() > 15 {
        return None;
    }
    let n: i64 = digits.parse().ok()?;
    // 1 BTC = 10^11 msat.
    let msats = match mult {
        None => n.checked_mul(100_000_000_000)?,
        Some(b'm') => n.checked_mul(100_000_000)?,
        Some(b'u') => n.checked_mul(100_000)?,
        Some(b'n') => n.checked_mul(100)?,
        _ if n % 10 == 0 => n / 10,
        _ => return None,
    };
    Some(msats)
}

/// The parts of an LNURL-pay answer (LUD-06, LUD-12 comments, NIP-57).
#[derive(Debug, Clone, PartialEq, Eq, Default)]
pub struct PayInfo {
    pub callback: String,
    pub min_msats: i64,
    pub max_msats: i64,
    pub allows_nostr: bool,
    /// The key that signs this provider's zap receipts.
    pub zap_pubkey: String,
    pub comment_max: i32,
}

pub fn parse_pay_info(v: &Value) -> Option<PayInfo> {
    if v.get("tag").and_then(Value::as_str) != Some("payRequest") {
        return None;
    }
    let num = |k: &str| v.get(k).and_then(|n| n.as_i64().or_else(|| n.as_f64().map(|f| f as i64))).unwrap_or(0);
    let callback = https_url(v.get("callback").and_then(Value::as_str).unwrap_or(""))?;
    let (min, max) = (num("minSendable"), num("maxSendable"));
    if min < 1 || max < min {
        return None;
    }
    let zap_pubkey = v.get("nostrPubkey").and_then(Value::as_str).unwrap_or("").trim().to_ascii_lowercase();
    let allows_nostr = v.get("allowsNostr").and_then(Value::as_bool).unwrap_or(false) && event::is_hex64(&zap_pubkey);
    Some(PayInfo {
        callback,
        min_msats: min,
        max_msats: max,
        allows_nostr,
        zap_pubkey: if allows_nostr { zap_pubkey } else { String::new() },
        comment_max: num("commentAllowed").clamp(0, 2000) as i32,
    })
}

/// A checked receipt: who zapped, which note, how much.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Receipt {
    pub sender: String,
    pub target: String,
    pub msats: i64,
}

fn first_tag<'a>(ev: &'a Event, name: &str) -> Option<&'a str> {
    ev.tags
        .iter()
        .find(|t| t.first().map(String::as_str) == Some(name))
        .and_then(|t| t.get(1))
        .map(String::as_str)
}

/// A kind 9735 receipt for a zap of one of `me`'s notes. Everything but
/// the receipt's signer, which the caller checks against the provider key.
/// Profile zaps (no `e` tag) are left out.
pub fn receipt(ev: &Event, me: &str) -> Option<Receipt> {
    if ev.kind != RECEIPT_KIND || first_tag(ev, "p")? != me {
        return None;
    }
    let request: Event = serde_json::from_str(first_tag(ev, "description")?).ok()?;
    if request.kind != REQUEST_KIND || request.verify().is_err() {
        return None;
    }
    let named: Vec<&str> = request
        .tags
        .iter()
        .filter(|t| t.first().map(String::as_str) == Some("p"))
        .filter_map(|t| t.get(1).map(String::as_str))
        .collect();
    if named != [me] {
        return None;
    }
    let target = first_tag(ev, "e").filter(|id| event::is_hex64(id))?;
    if first_tag(&request, "e") != Some(target) {
        return None;
    }
    let msats = bolt11_msats(first_tag(ev, "bolt11")?)?;
    if msats < 1000 {
        return None;
    }
    if let Some(asked) = first_tag(&request, "amount") {
        if asked.trim().parse::<i64>().ok() != Some(msats) {
            return None;
        }
    }
    Some(Receipt { sender: request.pubkey.clone(), target: target.to_string(), msats })
}

/// The unsigned zap request's tags (NIP-57 appendix A).
pub fn request_tags(note: &str, author: &str, msats: i64, pay_url: &str, relays: &[String]) -> Vec<Vec<String>> {
    let mut relay_tag = vec!["relays".to_string()];
    relay_tag.extend(relays.iter().take(8).cloned());
    vec![
        relay_tag,
        vec!["amount".into(), msats.to_string()],
        vec!["lnurl".into(), lnurl_of(pay_url)],
        vec!["p".into(), author.to_string()],
        vec!["e".into(), note.to_string()],
        vec!["k".into(), "1".into()],
    ]
}

/// Debug builds only: `VAAK_NOSTR_TEST_LNURL_BASE=http://127.0.0.1:PORT`
/// sends `https://host/path` to `PORT/host/path`, without TLS or the address
/// guard. Release builds never read it.
fn test_base() -> Option<String> {
    if !cfg!(debug_assertions) {
        return None;
    }
    std::env::var("VAAK_NOSTR_TEST_LNURL_BASE")
        .ok()
        .map(|s| s.trim().trim_end_matches('/').to_string())
        .filter(|s| !s.is_empty())
}

/// Public addresses for `host`, or an error when any answer is private
/// (same rule as the link previews and relays).
async fn public_addrs(host: &str) -> Result<Vec<SocketAddr>> {
    if let Ok(ip) = host.trim_start_matches('[').trim_end_matches(']').parse::<IpAddr>() {
        if !crate::direct_preview::public_ip(ip)? {
            bail!("refused non-public address");
        }
        return Ok(vec![SocketAddr::new(ip, 443)]);
    }
    let addrs: Vec<SocketAddr> = tokio::time::timeout(CONNECT_TIMEOUT, tokio::net::lookup_host((host, 443)))
        .await
        .map_err(|_| anyhow!("dns timeout"))?
        .context("dns")?
        .collect();
    if addrs.is_empty() {
        bail!("no address");
    }
    for addr in &addrs {
        if !crate::direct_preview::public_ip(addr.ip())? {
            bail!("refused non-public address");
        }
    }
    Ok(addrs)
}

/// GET a provider's JSON: https only, public addresses only (pinned, so a
/// second lookup can't swap them), no redirects, 64 KiB cap. An LNURL
/// `{"status":"ERROR","reason":…}` answer comes back as the error.
async fn get_json(url: &reqwest::Url) -> Result<Value> {
    let builder = reqwest::Client::builder()
        .redirect(reqwest::redirect::Policy::none())
        .timeout(FETCH_TIMEOUT)
        .connect_timeout(CONNECT_TIMEOUT)
        .user_agent("VAAK zaps (+https://mkultra.monster)");
    let host = url.host_str().ok_or_else(|| anyhow!("no host"))?.to_string();
    let (client, target) = match test_base() {
        Some(base) => {
            let path = url.path().trim_start_matches('/');
            let query = url.query().map(|q| format!("?{q}")).unwrap_or_default();
            (builder.build()?, reqwest::Url::parse(&format!("{base}/{host}/{path}{query}"))?)
        }
        None => {
            if url.scheme() != "https" || url.port_or_known_default() != Some(443) {
                bail!("not an https URL");
            }
            let addrs = public_addrs(&host).await?;
            (builder.https_only(true).resolve_to_addrs(&host, &addrs).build()?, url.clone())
        }
    };
    let mut resp = client.get(target).header(reqwest::header::ACCEPT, "application/json").send().await.context("lnurl request")?;
    let status = resp.status();
    let mut body = Vec::new();
    while let Some(chunk) = resp.chunk().await.context("lnurl body")? {
        if body.len() + chunk.len() > MAX_BODY_BYTES {
            bail!("answer too large");
        }
        body.extend_from_slice(&chunk);
    }
    let v: Value = serde_json::from_slice(&body).map_err(|_| anyhow!("HTTP {} without JSON", status.as_u16()))?;
    if v.get("status").and_then(Value::as_str).is_some_and(|s| s.eq_ignore_ascii_case("error")) {
        let reason: String = v.get("reason").and_then(Value::as_str).unwrap_or("error").chars().filter(|c| !c.is_control()).take(200).collect();
        bail!("{reason}");
    }
    if !status.is_success() {
        bail!("HTTP {}", status.as_u16());
    }
    Ok(v)
}

pub async fn fetch_pay_info(pay_url: &str) -> Result<PayInfo> {
    let url = reqwest::Url::parse(pay_url).context("pay url")?;
    parse_pay_info(&get_json(&url).await?).ok_or_else(|| anyhow!("not an LNURL-pay answer"))
}

/// Ask the provider for an invoice for `request` (a signed 9734). The
/// invoice must be for exactly `msats`.
/// What a provider's callback answered: the invoice, and its LUD-21
/// `verify` URL when the provider offers one.
#[derive(Debug, Clone, PartialEq, Eq)]
pub struct Invoice {
    pub pr: String,
    pub verify: Option<String>,
}

pub async fn fetch_invoice(callback: &str, msats: i64, request: &Event, pay_url: &str) -> Result<Invoice> {
    let mut url = reqwest::Url::parse(callback).context("callback")?;
    url.query_pairs_mut()
        .append_pair("amount", &msats.to_string())
        .append_pair("nostr", &serde_json::to_string(request)?)
        .append_pair("lnurl", &lnurl_of(pay_url));
    let v = get_json(&url).await?;
    let pr = v.get("pr").and_then(Value::as_str).unwrap_or("").trim().to_string();
    if pr.is_empty() || pr.len() > 4000 {
        bail!("no invoice in the answer");
    }
    if bolt11_msats(&pr) != Some(msats) {
        bail!("the invoice is for a different amount");
    }
    let verify = v.get("verify").and_then(Value::as_str).and_then(https_url);
    Ok(Invoice { pr, verify })
}

/// A zap invoice the payer's browser waits on, so the pay panel can close
/// itself once the sats arrive (act::zap_status).
#[derive(Debug, Clone, PartialEq, Eq, serde::Serialize, serde::Deserialize)]
pub struct Watch {
    pub note: String,
    pub invoice: String,
    /// The provider key that signs its receipts.
    pub zap_pubkey: String,
    pub verify: Option<String>,
    /// The relays the zap request asked receipts to go to.
    pub relays: Vec<String>,
    pub since: i64,
}

/// A receipt for exactly this invoice, signed by the provider.
pub fn receipt_pays(ev: &Event, invoice: &str, zap_pubkey: &str) -> bool {
    ev.kind == RECEIPT_KIND
        && !zap_pubkey.is_empty()
        && ev.pubkey == zap_pubkey
        && first_tag(ev, "bolt11").is_some_and(|b| b.trim().eq_ignore_ascii_case(invoice.trim()))
        && ev.verify().is_ok()
}

const WATCH_RELAYS: usize = 4;
const WATCH_DEADLINE: Duration = Duration::from_secs(5);

/// Whether the watched invoice was paid: the provider's LUD-21 verify URL
/// says settled, or its receipt for this invoice is on a receipt relay.
pub async fn watch_paid(w: &Watch) -> bool {
    if let Some(url) = w.verify.as_deref().and_then(|v| reqwest::Url::parse(v).ok()) {
        if let Ok(ans) = get_json(&url).await {
            if ans.get("settled").and_then(Value::as_bool) == Some(true) {
                return true;
            }
        }
    }
    if w.zap_pubkey.is_empty() || w.relays.is_empty() {
        return false;
    }
    let deadline = tokio::time::Instant::now() + WATCH_DEADLINE;
    let queries = [super::relay::Query::zap_receipts(&w.note, w.since - 120)];
    let fetches = w.relays.iter().take(WATCH_RELAYS).map(|r| super::relay::fetch(r, &queries, deadline));
    futures_util::future::join_all(fetches)
        .await
        .into_iter()
        .flatten()
        .flat_map(|r| r.events)
        .any(|ev| receipt_pays(&ev, &w.invoice, &w.zap_pubkey))
}

pub async fn ready(db: &Client) -> bool {
    db.query_one("SELECT to_regclass('nostr_zap_addrs') IS NOT NULL", &[])
        .await
        .map(|r| r.get::<_, bool>(0))
        .unwrap_or(false)
}

/// Profile sync: remember a key's zap address from its kind 0, or that it
/// has none (an empty row, so the zap backfill in `sync` knows the profile
/// was checked). A changed address is asked about again.
pub async fn store_addr(db: &Client, pubkey: &str, url: &str, profile_at: i64) -> Result<()> {
    db.execute(
        "INSERT INTO nostr_zap_addrs (pubkey, pay_url, profile_at) VALUES ($1, $2, $3)
         ON CONFLICT (pubkey) DO UPDATE SET pay_url = EXCLUDED.pay_url, profile_at = EXCLUDED.profile_at,
             checked_at = CASE WHEN nostr_zap_addrs.pay_url = EXCLUDED.pay_url THEN nostr_zap_addrs.checked_at ELSE 0 END,
             callback = CASE WHEN EXCLUDED.pay_url = '' THEN '' ELSE nostr_zap_addrs.callback END,
             allows_nostr = CASE WHEN EXCLUDED.pay_url = '' THEN FALSE ELSE nostr_zap_addrs.allows_nostr END,
             zap_pubkey = CASE WHEN EXCLUDED.pay_url = '' THEN '' ELSE nostr_zap_addrs.zap_pubkey END
         WHERE nostr_zap_addrs.profile_at <= EXCLUDED.profile_at",
        &[&pubkey, &url, &profile_at],
    )
    .await
    .context("nostr zap address")?;
    Ok(())
}

/// `pubkey`'s pay URL and provider info, asked again when older than
/// `max_age`. None when the key names no Lightning address.
pub async fn pay_info_for(db: &Client, pubkey: &str, now: i64, max_age: i64) -> Result<Option<(String, PayInfo)>> {
    let Some(row) = db
        .query_opt(
            "SELECT pay_url, callback, min_msats, max_msats, allows_nostr, zap_pubkey, comment_max, checked_at
             FROM nostr_zap_addrs WHERE pubkey = $1",
            &[&pubkey],
        )
        .await
        .context("nostr zap address read")?
    else {
        return Ok(None);
    };
    let pay_url: String = row.get(0);
    if pay_url.is_empty() {
        return Ok(None);
    }
    let cached = PayInfo {
        callback: row.get(1),
        min_msats: row.get(2),
        max_msats: row.get(3),
        allows_nostr: row.get(4),
        zap_pubkey: row.get(5),
        comment_max: row.get(6),
    };
    let checked_at: i64 = row.get(7);
    if !cached.callback.is_empty() && checked_at >= now - max_age {
        return Ok(Some((pay_url, cached)));
    }
    match fetch_pay_info(&pay_url).await {
        Ok(info) => {
            db.execute(
                "UPDATE nostr_zap_addrs SET callback = $2, min_msats = $3, max_msats = $4, allows_nostr = $5, zap_pubkey = $6,
                     comment_max = $7, checked_at = $8
                 WHERE pubkey = $1 AND pay_url = $9",
                &[&pubkey, &info.callback, &info.min_msats, &info.max_msats, &info.allows_nostr, &info.zap_pubkey, &info.comment_max, &now, &pay_url],
            )
            .await
            .context("nostr zap address check")?;
            Ok(Some((pay_url, info)))
        }
        Err(e) if !cached.callback.is_empty() && checked_at >= now - STALE_OK_SECS => {
            tracing::debug!(pubkey, error = %format!("{e:#}"), "nostr zap pay info refresh failed; using cached");
            Ok(Some((pay_url, cached)))
        }
        Err(e) => Err(e),
    }
}

/// Keys among `pubkeys` that can be zapped (they name a Lightning address).
pub async fn zappable(db: &Client, pubkeys: &[String]) -> std::collections::HashSet<String> {
    if pubkeys.is_empty() {
        return Default::default();
    }
    db.query("SELECT pubkey FROM nostr_zap_addrs WHERE pubkey = ANY($1) AND pay_url <> ''", &[&pubkeys])
        .await
        .map(|rows| rows.iter().map(|r| r.get(0)).collect())
        .unwrap_or_default()
}

/// Sats each of `owner`'s notes got in zaps (from the receipts that notified).
pub async fn sats_by_note(db: &Client, owner: i64, notes: &[String]) -> std::collections::HashMap<String, i64> {
    if notes.is_empty() {
        return Default::default();
    }
    db.query(
        "SELECT target_id, SUM(COALESCE(substring(mention->>'object_id' from '#zap-([0-9]{1,15})s')::bigint, 0))::bigint
         FROM nostr_notifs WHERE owner_user_id = $1 AND kind = 9735 AND target_id = ANY($2) GROUP BY target_id",
        &[&owner, &notes],
    )
    .await
    .map(|rows| rows.iter().map(|r| (r.get(0), r.get(1))).collect())
    .unwrap_or_default()
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::nostr::event::tests::signed;

    #[test]
    fn a_watched_invoice_is_paid_only_by_its_providers_receipt() {
        let provider = signed(9, 100, RECEIPT_KIND, vec![vec!["bolt11".into(), "LNBC210N1ABC".into()]], "");
        let key = provider.pubkey.clone();
        assert!(receipt_pays(&provider, "lnbc210n1abc", &key), "same invoice, any case");
        assert!(!receipt_pays(&provider, "lnbc210n1other", &key), "another invoice");
        assert!(!receipt_pays(&provider, "lnbc210n1abc", &"aa".repeat(32)), "signed by someone else");
        assert!(!receipt_pays(&provider, "lnbc210n1abc", ""), "no provider key");
        let mut forged = provider.clone();
        forged.tags = vec![vec!["bolt11".into(), "lnbc210n1zzz".into()]];
        assert!(!receipt_pays(&forged, "lnbc210n1zzz", &key), "tampered receipt");
        let note = signed(9, 100, 1, vec![vec!["bolt11".into(), "lnbc210n1abc".into()]], "");
        assert!(!receipt_pays(&note, "lnbc210n1abc", &key), "not a receipt");
    }

    #[test]
    fn lightning_addresses_become_pay_urls() {
        assert_eq!(pay_url("Nova@Wallet.Example", "").as_deref(), Some("https://wallet.example/.well-known/lnurlp/nova"));
        assert_eq!(pay_url("bad", ""), None);
        assert_eq!(pay_url("a@localhost", ""), None, "needs a dotted domain");
        assert_eq!(pay_url("a b@x.io", ""), None);
        assert_eq!(pay_url("a@-x.io", ""), None);
        let lnurl = lnurl_of("https://pay.example/lnurlp/abc");
        assert!(lnurl.starts_with("lnurl1"));
        assert_eq!(pay_url("", &lnurl).as_deref(), Some("https://pay.example/lnurlp/abc"));
        assert_eq!(pay_url("", &format!("lightning:{}", lnurl.to_uppercase())).as_deref(), Some("https://pay.example/lnurlp/abc"));
        assert_eq!(pay_url("", &lnurl_of("http://pay.example/x")), None, "https only");
        assert_eq!(pay_url("x@pay.example", &lnurl).as_deref(), Some("https://pay.example/.well-known/lnurlp/x"), "lud16 wins");
    }

    #[test]
    fn bolt11_amounts() {
        // BOLT-11 spec examples.
        assert_eq!(bolt11_msats("lnbc2500u1pvjluezpp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypq"), Some(250_000_000));
        assert_eq!(bolt11_msats("lnbc20m1pvjluezpp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypq"), Some(2_000_000_000));
        assert_eq!(bolt11_msats("lnbc1pvjluezpp5qqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqqqsyqcyq5rqwzqfqypq"), None, "no amount");
        assert_eq!(bolt11_msats("LIGHTNING:LNBC210N1PJ"), Some(21_000));
        assert_eq!(bolt11_msats("lntb1500n1abc"), Some(150_000));
        assert_eq!(bolt11_msats("lnbcrt10u1abc"), Some(1_000_000));
        assert_eq!(bolt11_msats("lnbc10p1abc"), Some(1));
        assert_eq!(bolt11_msats("lnbc15p1abc"), None, "pico must be a whole msat");
        assert_eq!(bolt11_msats("lnbc010u1abc"), None);
        assert_eq!(bolt11_msats("bc1qxyz"), None);
    }

    #[test]
    fn pay_info_needs_a_key_for_nostr() {
        let key = "ab".repeat(32);
        let v = serde_json::json!({"tag": "payRequest", "callback": "https://pay.example/cb?x=1", "minSendable": 1000, "maxSendable": 100000000,
            "allowsNostr": true, "nostrPubkey": key.to_uppercase(), "commentAllowed": 255});
        let info = parse_pay_info(&v).unwrap();
        assert!(info.allows_nostr && info.zap_pubkey == key && info.comment_max == 255);
        let mut v2 = v.clone();
        v2["nostrPubkey"] = serde_json::json!("nope");
        assert!(!parse_pay_info(&v2).unwrap().allows_nostr);
        v2["callback"] = serde_json::json!("http://pay.example/cb");
        assert!(parse_pay_info(&v2).is_none());
        assert!(parse_pay_info(&serde_json::json!({"tag": "withdrawRequest"})).is_none());
    }

    fn zap_pair(amount_tag: Option<&str>, bolt11: &str, receipt_e: &str) -> (Event, String, String) {
        let note = "ee".repeat(32);
        let me_ev = signed(9, 1, 1, vec![], "");
        let me = me_ev.pubkey.clone();
        let mut tags = vec![vec!["p".to_string(), me.clone()], vec!["e".to_string(), note.clone()], vec!["relays".into(), "wss://r.example".into()]];
        if let Some(a) = amount_tag {
            tags.push(vec!["amount".into(), a.into()]);
        }
        let request = signed(3, 1_700_000_000, REQUEST_KIND, tags, "great post");
        let receipt_ev = signed(
            4,
            1_700_000_010,
            RECEIPT_KIND,
            vec![
                vec!["p".into(), me.clone()],
                vec!["e".into(), receipt_e.to_string()],
                vec!["bolt11".into(), bolt11.into()],
                vec!["description".into(), serde_json::to_string(&request).unwrap()],
            ],
            "",
        );
        (receipt_ev, me, request.pubkey)
    }

    #[test]
    fn receipts_are_checked_against_their_request() {
        let note = "ee".repeat(32);
        let (ev, me, sender) = zap_pair(Some("21000"), "lnbc210n1abc", &note);
        assert_eq!(receipt(&ev, &me), Some(Receipt { sender: sender.clone(), target: note.clone(), msats: 21_000 }));
        let (ev, me, _) = zap_pair(Some("21000"), "lnbc1u1abc", &note);
        assert_eq!(receipt(&ev, &me), None, "invoice differs from the request");
        let (ev, me, _) = zap_pair(None, "lnbc1u1abc", &note);
        assert_eq!(receipt(&ev, &me).map(|r| r.msats), Some(100_000), "amount tag is optional");
        let (ev, me, _) = zap_pair(None, "lnbc1u1abc", &"ff".repeat(32));
        assert_eq!(receipt(&ev, &me), None, "receipt and request name different notes");
        let (ev, _, _) = zap_pair(None, "lnbc1u1abc", &note);
        assert_eq!(receipt(&ev, &"aa".repeat(32)), None, "not for this key");
        let (mut ev, me, _) = zap_pair(None, "lnbc1u1abc", &note);
        let desc = ev.tags.iter_mut().find(|t| t[0] == "description").unwrap();
        desc[1] = desc[1].replace("great post", "forged");
        assert_eq!(receipt(&ev, &me), None, "request signature must hold");
        let (ev, me, _) = zap_pair(None, "lnbc5p1abc", &note);
        assert_eq!(receipt(&ev, &me), None, "under a sat");
    }

    #[test]
    fn request_tags_name_note_author_and_amount() {
        let tags = request_tags(&"ee".repeat(32), &"aa".repeat(32), 21_000, "https://pay.example/x", &["wss://a".into(), "wss://b".into()]);
        assert_eq!(tags[0], vec!["relays", "wss://a", "wss://b"]);
        assert_eq!(tags[1], vec!["amount", "21000"]);
        assert_eq!(tags[2][0], "lnurl");
        assert_eq!(pay_url("", &tags[2][1]).as_deref(), Some("https://pay.example/x"));
        assert_eq!(tags[3], vec!["p".to_string(), "aa".repeat(32)]);
        assert_eq!(tags[4], vec!["e".to_string(), "ee".repeat(32)]);
    }
}
