//! Bluemoji: custom emoji on Bluesky posts (`blue.moji.richtext.facet`).
//!
//! A Bluemoji is one more feature on an `app.bsky.richtext.facet`. It names
//! the emoji (`:blobcat:`), the DID whose repo holds the image, and the CIDs
//! of its renditions. Wafrn and other Bluemoji clients write them; without
//! this module a VAAK card showed the bare `:shortcode:` text.
//!
//! Paint stays cache-only, like fediverse emoji. Rows live in
//! `remote_custom_emojis` with `host` = the DID (DIDs never collide with DNS
//! hosts), so PHP's painter reads the same rows. A miss starts
//! `api/bin/bsky-bluemoji-warm.php` in the background, which resolves the
//! DID's PDS, reads the owner's `blue.moji.collection.item` records (the
//! verified formats and `adultOnly`), and stores `getBlob` URLs. The page
//! never waits on that, and a DID is asked for at most once per cooldown.
//!
//! Spec: <https://github.com/aendra-rininsland/bluemoji> (`RENDERING.md`,
//! `lexicons/blue/moji/richtext/facet.json`).

use std::collections::HashMap;
use std::sync::Mutex;
use std::time::{Duration, Instant};

use serde_json::Value;
use vaak_types::emoji::valid_shortcode;

/// The facet feature `$type` (the lexicon's `main` def, so `#main` is the same).
const FEATURE: &str = "blue.moji.richtext.facet";
/// DIDs one painted page may ask the warm script for.
const MAX_DIDS_PER_PAGE: usize = 3;
/// Claims handed to one warm process.
const MAX_CLAIMS_PER_SPAWN: usize = 40;
/// Per-DID quiet time in this worker between warm requests.
const DID_COOLDOWN: Duration = Duration::from_secs(15 * 60);
/// Global cap on warm processes per minute, so a busy page burst stays cheap.
const MAX_SPAWNS_PER_MINUTE: usize = 6;

/// One Bluemoji a post uses, as the post claims it.
#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct Claim {
    pub did: String,
    /// Shortcode as it appears in the post text, without colons.
    pub code: String,
    /// Rendition for `url` (animated first).
    pub cid: String,
    /// Rendition for `static_url` (still image first).
    pub static_cid: String,
}

/// did:plc / did:web only, the same rule as PHP `ap_bsky_did_is_resolvable`.
pub(crate) fn valid_did(did: &str) -> bool {
    if let Some(rest) = did.strip_prefix("did:plc:") {
        return rest.len() == 24 && rest.bytes().all(|b| matches!(b, b'a'..=b'z' | b'2'..=b'7'));
    }
    if let Some(host) = did.strip_prefix("did:web:") {
        let labels: Vec<&str> = host.split('.').collect();
        return labels.len() >= 2
            && host.len() <= 253
            && labels.iter().all(|l| {
                !l.is_empty()
                    && l.len() <= 63
                    && l.bytes().all(|b| b.is_ascii_alphanumeric() || b == b'-')
                    && !l.starts_with('-')
                    && !l.ends_with('-')
            });
    }
    false
}

/// CIDv1 base32 (what ATproto blobs use): `b` + lowercase base32.
pub(crate) fn valid_cid(cid: &str) -> bool {
    (8..=128).contains(&cid.len())
        && cid.starts_with('b')
        && cid.bytes().all(|b| matches!(b, b'a'..=b'z' | b'2'..=b'7'))
}

/// A format value: a CID string (facet `formats_v1`) or a blob ref
/// (`{"ref": {"$link": cid}}`, or a bare `{"$link": cid}`). Booleans
/// (`formats_v0` apng/lottie) carry no CID.
fn cid_of(value: Option<&Value>) -> Option<String> {
    let value = value?;
    let raw = match value {
        Value::String(s) => s.as_str(),
        Value::Object(_) => value
            .get("ref")
            .and_then(|r| r.get("$link").or(Some(r)))
            .and_then(Value::as_str)
            .or_else(|| value.get("$link").and_then(Value::as_str))?,
        _ => return None,
    };
    let raw = raw.trim();
    valid_cid(raw).then(|| raw.to_string())
}

/// (url cid, static cid) from a `formats` object. Lottie is never an `<img>`.
pub(crate) fn pick_formats(formats: &Value) -> Option<(String, String)> {
    let get = |key: &str| cid_of(formats.get(key));
    let animated = get("gif_128").or_else(|| get("apng_128"));
    let still = get("png_128").or_else(|| get("webp_128"));
    let url = animated
        .clone()
        .or_else(|| get("webp_128"))
        .or_else(|| still.clone())?;
    let static_cid = still.unwrap_or_else(|| url.clone());
    Some((url, static_cid))
}

/// True when the emoji says it needs a content warning. Inline emoji have no
/// CW gate of their own, so these stay as text.
fn adult(feature: &Value) -> bool {
    if feature.get("adultOnly").and_then(Value::as_bool) == Some(true) {
        return true;
    }
    feature
        .get("labels")
        .and_then(|l| l.get("values"))
        .and_then(Value::as_array)
        .is_some_and(|values| !values.is_empty())
}

fn shortcode_from(raw: &str) -> Option<String> {
    let code = raw.trim().trim_matches(':').trim();
    valid_shortcode(code).then(|| code.to_string())
}

/// Bluemoji claims in a facet list, first per shortcode.
///
/// The shortcode is the facet's visible text when that is `:code:`, since
/// painters swap the text they see; otherwise the feature's `name`.
pub(crate) fn claims_in(text: &str, facets: &[Value]) -> Vec<Claim> {
    let mut out: Vec<Claim> = Vec::new();
    for facet in facets {
        let Some(features) = facet.get("features").and_then(Value::as_array) else {
            continue;
        };
        for feature in features {
            let kind = feature.get("$type").and_then(Value::as_str).unwrap_or("");
            if kind != FEATURE && kind != "blue.moji.richtext.facet#main" {
                continue;
            }
            if adult(feature) {
                continue;
            }
            let did = feature
                .get("did")
                .and_then(Value::as_str)
                .unwrap_or("")
                .trim();
            if !valid_did(did) {
                continue;
            }
            let Some((cid, static_cid)) = feature.get("formats").and_then(pick_formats) else {
                continue;
            };
            let index = facet.get("index");
            let at = |k: &str| {
                index
                    .and_then(|i| i.get(k))
                    .and_then(Value::as_u64)
                    .map(|n| n as usize)
            };
            let visible = match (at("byteStart"), at("byteEnd")) {
                (Some(s), Some(e))
                    if s < e
                        && e <= text.len()
                        && text.is_char_boundary(s)
                        && text.is_char_boundary(e) =>
                {
                    let slice = &text[s..e];
                    (slice.starts_with(':') && slice.ends_with(':') && slice.len() > 2)
                        .then_some(slice)
                }
                _ => None,
            };
            let name = feature.get("name").and_then(Value::as_str).unwrap_or("");
            let Some(code) = visible
                .and_then(shortcode_from)
                .or_else(|| shortcode_from(name))
            else {
                continue;
            };
            if out.iter().any(|c| c.code == code) {
                continue;
            }
            out.push(Claim {
                did: did.to_string(),
                code,
                cid,
                static_cid,
            });
        }
    }
    out
}

/// Record text + facets for a status, from wherever this surface keeps them:
/// the stamp `attach_bsky_link_facets` adds, a raw record, or a PostView.
pub(crate) fn status_claims(status: &Value) -> Vec<Claim> {
    let record = status.get("record").or_else(|| {
        status.get("bsky_post").and_then(|p| {
            p.get("record")
                .or_else(|| p.get("post").and_then(|q| q.get("record")))
        })
    });
    let sources = [
        record.map(|r| (r.get("text"), r.get("facets"))),
        Some((status.get("vaak_bsky_text"), status.get("vaak_bsky_facets"))),
    ];
    for (text, facets) in sources.into_iter().flatten() {
        let (Some(text), Some(facets)) = (
            text.and_then(Value::as_str),
            facets.and_then(Value::as_array),
        ) else {
            continue;
        };
        if facets.is_empty() {
            continue;
        }
        let found = claims_in(text, facets);
        if !found.is_empty() {
            return found;
        }
    }
    Vec::new()
}

fn disabled() -> bool {
    std::env::var("VAAK_BLUEMOJI").is_ok_and(|v| matches!(v.trim(), "0" | "off" | "false" | "no"))
}

struct Gate {
    dids: HashMap<String, Instant>,
    spawns: Vec<Instant>,
}

static GATE: Mutex<Option<Gate>> = Mutex::new(None);

/// Claims allowed through the cooldowns right now (and mark them asked).
fn admit(misses: &[Claim], now: Instant) -> Vec<Claim> {
    let Ok(mut guard) = GATE.lock() else {
        return Vec::new();
    };
    let gate = guard.get_or_insert_with(|| Gate {
        dids: HashMap::new(),
        spawns: Vec::new(),
    });
    gate.spawns
        .retain(|t| now.duration_since(*t) < Duration::from_secs(60));
    if gate.spawns.len() >= MAX_SPAWNS_PER_MINUTE {
        return Vec::new();
    }
    gate.dids
        .retain(|_, t| now.duration_since(*t) < DID_COOLDOWN);
    let mut picked: Vec<String> = Vec::new();
    for claim in misses {
        if picked.contains(&claim.did) || gate.dids.contains_key(&claim.did) {
            continue;
        }
        if picked.len() >= MAX_DIDS_PER_PAGE {
            break;
        }
        picked.push(claim.did.clone());
    }
    if picked.is_empty() {
        return Vec::new();
    }
    for did in &picked {
        gate.dids.insert(did.clone(), now);
    }
    gate.spawns.push(now);
    misses
        .iter()
        .filter(|c| picked.contains(&c.did))
        .take(MAX_CLAIMS_PER_SPAWN)
        .cloned()
        .collect()
}

/// JSON argument for the warm script.
pub(crate) fn claims_arg(claims: &[Claim]) -> String {
    let list: Vec<Value> = claims
        .iter()
        .map(|c| serde_json::json!({"did": c.did, "code": c.code, "cid": c.cid, "static_cid": c.static_cid}))
        .collect();
    Value::Array(list).to_string()
}

/// Ask PHP to cache emoji this page painted as text. Never blocks the paint.
pub(crate) fn kick(misses: &[Claim]) {
    if misses.is_empty() || disabled() {
        return;
    }
    let claims = admit(misses, Instant::now());
    if claims.is_empty() {
        return;
    }
    let api_root = std::env::var_os("VAAK_API_ROOT")
        .map(std::path::PathBuf::from)
        .unwrap_or_else(|| "/srv/mkultra/html/api".into());
    let script = api_root.join("bin/bsky-bluemoji-warm.php");
    if !script.is_file() {
        return;
    }
    let mut php = std::env::var_os("VAAK_PHP_BIN")
        .map(std::path::PathBuf::from)
        .unwrap_or_else(crate::profile_quote_warm::php_cli_binary);
    if php.as_os_str().is_empty() || php.to_string_lossy().contains("php-fpm") {
        php = crate::profile_quote_warm::php_cli_binary();
    }
    let mut cmd = tokio::process::Command::new(php);
    cmd.arg(script)
        .arg(format!("--claims={}", claims_arg(&claims)))
        .stdin(std::process::Stdio::null())
        .stdout(std::process::Stdio::null())
        .stderr(std::process::Stdio::null())
        .kill_on_drop(false);
    match cmd.spawn() {
        Ok(mut child) => {
            tokio::spawn(async move {
                let _ = child.wait().await;
            });
        }
        Err(e) => tracing::debug!(error = %e, "bluemoji warm spawn"),
    }
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    const DID: &str = "did:plc:abcdefghijklmnopqrstuvwx";
    const PNG: &str = "bafkreigh2akiscaildcqabsyg3dfr6chu3fgpregiymsck7e7aqa4s52zy";
    const GIF: &str = "bafkreibme22gw2h7y2h7tg2fhqotaqjucnbc24deqo72b6mkl2egezxhvy";

    fn post(text: &str, feature: Value) -> Value {
        let start = text.find(':').unwrap();
        let end = text.rfind(':').unwrap() + 1;
        json!({"vaak_bsky_text": text, "vaak_bsky_facets": [{"index": {"byteStart": start, "byteEnd": end}, "features": [feature]}]})
    }

    #[test]
    fn reads_a_v1_facet() {
        let st = post(
            "Speaking of bluemoji\n\n:cat_smoke_by_gabboman:",
            json!({"$type": FEATURE, "did": DID, "name": ":cat_smoke_by_gabboman:", "formats": {"$type": "blue.moji.richtext.facet#formats_v1", "png_128": PNG}}),
        );
        assert_eq!(
            status_claims(&st),
            vec![Claim {
                did: DID.into(),
                code: "cat_smoke_by_gabboman".into(),
                cid: PNG.into(),
                static_cid: PNG.into()
            }]
        );
    }

    #[test]
    fn animated_first_and_v0_booleans_ignored() {
        let f = json!({"$type": FEATURE, "did": DID, "name": ":a:", "formats": {"gif_128": GIF, "png_128": PNG, "apng_128": true}});
        assert_eq!(status_claims(&post(":a:", f))[0].cid, GIF);
        let v0 = json!({"$type": FEATURE, "did": DID, "name": ":a:", "formats": {"apng_128": true, "lottie": true}});
        assert!(status_claims(&post(":a:", v0)).is_empty());
    }

    #[test]
    fn blob_refs_read_like_records() {
        let formats = json!({"png_128": {"$type": "blob", "ref": {"$link": PNG}, "mimeType": "image/png", "size": 900}});
        assert_eq!(pick_formats(&formats), Some((PNG.into(), PNG.into())));
    }

    #[test]
    fn skips_adult_bad_did_bad_cid_and_other_features() {
        let base = |extra: Value| {
            let mut f =
                json!({"$type": FEATURE, "did": DID, "name": ":a:", "formats": {"png_128": PNG}});
            for (k, v) in extra.as_object().unwrap() {
                f[k] = v.clone();
            }
            status_claims(&post(":a:", f))
        };
        assert_eq!(base(json!({})).len(), 1);
        assert!(base(json!({"adultOnly": true})).is_empty());
        assert!(base(json!({"labels": {"$type": "com.atproto.label.defs#selfLabels", "values": [{"val": "porn"}]}})).is_empty());
        assert!(base(json!({"did": "did:key:zabc"})).is_empty());
        assert!(base(json!({"did": "did:web:localhost"})).is_empty());
        assert!(base(json!({"formats": {"png_128": "https://evil.test/x.png"}})).is_empty());
        assert!(base(json!({"$type": "app.bsky.richtext.facet#link"})).is_empty());
    }

    #[test]
    fn name_used_when_visible_text_is_not_a_shortcode() {
        let st = json!({"vaak_bsky_text": "x", "vaak_bsky_facets": [{"index": {"byteStart": 0, "byteEnd": 99}, "features": [
            {"$type": FEATURE, "did": DID, "name": ":Blob-Cat:", "formats": {"png_128": PNG}},
            {"$type": FEATURE, "did": DID, "name": ":blob.cat:", "formats": {"png_128": PNG}}
        ]}]});
        let got = status_claims(&st);
        assert_eq!(got.len(), 1);
        assert_eq!(got[0].code, "Blob-Cat");
    }

    #[test]
    fn record_facets_win_over_stamp() {
        let f = json!({"$type": FEATURE, "did": DID, "name": ":r:", "formats": {"png_128": PNG}});
        let st = json!({"bsky_post": {"record": {"text": ":r:", "facets": [{"index": {"byteStart": 0, "byteEnd": 3}, "features": [f]}]}}});
        assert_eq!(status_claims(&st)[0].code, "r");
    }

    #[test]
    fn gate_limits_dids_and_cooldown() {
        let now = Instant::now();
        let claim = |n: u8| Claim {
            did: format!(
                "did:plc:{}",
                std::iter::repeat_n(char::from(b'a' + n), 24).collect::<String>()
            ),
            code: format!("c{n}"),
            cid: PNG.into(),
            static_cid: PNG.into(),
        };
        let misses: Vec<Claim> = (0..5).map(claim).collect();
        let first = admit(&misses, now);
        assert_eq!(first.len(), MAX_DIDS_PER_PAGE);
        // Same DIDs inside the cooldown are not asked again; the rest are.
        let second = admit(&misses, now);
        assert_eq!(
            second.iter().map(|c| c.code.as_str()).collect::<Vec<_>>(),
            vec!["c3", "c4"]
        );
        assert!(admit(&misses, now).is_empty());
    }

    #[test]
    fn did_web_rules() {
        assert!(valid_did("did:web:gabboman.xyz"));
        assert!(!valid_did("did:web:-bad.xyz"));
        assert!(!valid_did("did:plc:short"));
    }
}
