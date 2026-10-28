//! Phyrian Strains card in the local profile header.
//! PHP oracle: api/ap-phyrian-profile-card.php (ap_phyrian_profile_card_enabled,
//! _normalize, the shared Redis value read by _snapshot, and _html). Both sides
//! replay api/fixtures/phyrian-profile-card.json. Display only: the player row
//! is read, never written; OpenSim sync stays with the PHP game module.
use anyhow::{bail, Result};
use serde_json::{json, Value};

use crate::library::actions::esc;
use crate::library::card;

/// Same as AP_PHYRIAN_PROFILE_CARD_TTL.
pub(crate) const TTL: u64 = 60;
/// AP_PHYRIAN_MAX_RESONANCE, AP_PHYRIAN_MAX_LEVEL, AP_PHYRIAN_DAILY_DECAY.
const MAX_RESONANCE: i64 = 100;
const MAX_LEVEL: i64 = 80;
const DAILY_DECAY: i64 = 5;
/// Columns the PHP snapshot selects. PHP migrates them on every request, so a
/// missing one here means this database is not the one PHP would paint from.
pub(crate) const COLUMNS: [&str; 11] = ["owner_user_id", "status", "strain", "resonance", "generation", "level", "banked_resonance", "resonance_exchanges", "inductions_given", "lineage_depth", "daily_resonance_decay"];
/// The same primary-key read as ap_phyrian_profile_card_snapshot.
pub(crate) const PLAYER_SQL: &str = "SELECT to_jsonb(p) FROM (SELECT status,strain,resonance,generation,level,banked_resonance,resonance_exchanges,inductions_given,lineage_depth,daily_resonance_decay FROM phyrian_players WHERE owner_user_id=$1::bigint LIMIT 1) p";

pub(crate) fn cache_key(owner: i64) -> String { format!("vaak:phyrian:profile-card:v1:{owner}") }

fn flag_on(raw: Option<&str>) -> bool { raw.is_some_and(|v| matches!(card::php_trim(v).to_ascii_lowercase().as_str(), "1" | "true" | "yes" | "on")) }

/// ap_phyrian_profile_card_enabled. Off: the header does no card work at all.
pub(crate) fn enabled() -> bool { flag_on(std::env::var("VAAK_PHYRIAN_PROFILE_CARD").ok().as_deref()) }

/// The page's card owner for a local profile: the signed-in owner on their own
/// profile, else the profile's ap_users id ($rpAskOwnerId, $rpFeaturedOwner and
/// $rpOwnerForBoosts all resolve the same key; the header uses that id for them).
pub(crate) fn owner(is_own: bool, viewer: i64, profile: i64) -> i64 { if is_own && viewer > 0 { viewer } else { profile.max(0) } }

/// ap_phyrian_is_origin_owner's id: `(int) (getenv(..) ?: 0)`, then 1 unless positive.
/// None for a value whose PHP integer cast is not a plain integer.
fn origin_from(raw: Option<&str>) -> Option<i64> {
    let Some(raw) = raw.filter(|v| !v.is_empty() && *v != "0") else { return Some(1) };
    let id = plain_int(raw)?;
    Some(if id > 0 { id } else { 1 })
}

pub(crate) fn origin_owner() -> Option<i64> {
    match std::env::var("VAAK_PHYRIAN_ORIGIN_OWNER_ID") { Ok(v) => origin_from(Some(&v)), Err(std::env::VarError::NotPresent) => Some(1), Err(_) => None }
}

/// PHP 8 numeric-string integer: optional surrounding whitespace, sign, digits.
fn plain_int(raw: &str) -> Option<i64> {
    let t = raw.trim_matches([' ', '\t', '\n', '\r', '\u{b}', '\u{c}']);
    if t.is_empty() { return Some(0) }
    let digits = t.strip_prefix(['+', '-']).unwrap_or(t);
    if digits.is_empty() || !digits.bytes().all(|b| b.is_ascii_digit()) { return None }
    t.trim_start_matches('+').parse().ok()
}

/// `(int) $v` for the shapes a database row or PHP's own cache value carries.
fn php_int(v: &Value) -> Result<i64> {
    match v {
        Value::Bool(b) => Ok(i64::from(*b)),
        Value::Number(n) if n.is_i64() => Ok(n.as_i64().unwrap_or(0)),
        Value::String(s) => match plain_int(s) { Some(i) => Ok(i), None => bail!("PHP fallback: phyrian card integer is not a plain integer") },
        _ => bail!("PHP fallback: phyrian card integer has an unsupported type"),
    }
}

/// `(string) $v` for the same shapes.
fn php_str(v: &Value) -> Result<String> {
    match v {
        Value::Null => Ok(String::new()),
        Value::String(s) => Ok(s.clone()),
        Value::Bool(b) => Ok(if *b { "1".into() } else { String::new() }),
        Value::Number(n) if n.is_i64() || n.is_u64() => Ok(n.to_string()),
        _ => bail!("PHP fallback: phyrian card text has an unsupported type"),
    }
}

/// ap_phyrian_profile_card_normalize. `?? default` applies to absent and null.
pub(crate) fn normalize(row: &Value) -> Result<Value> {
    let get = |k: &str| row.get(k).filter(|v| !v.is_null());
    let int = |k: &str, d: i64| get(k).map(php_int).transpose().map(|v| v.unwrap_or(d));
    let text = |k: &str| get(k).map(php_str).transpose().map(|v| card::php_trim(&v.unwrap_or_default()).to_owned());
    let depth = match get("lineage_depth") { None => Value::Null, Some(Value::String(s)) if s.is_empty() => Value::Null, Some(v) => json!(php_int(v)?) };
    Ok(json!({
        "status": text("status")?, "strain": text("strain")?, "resonance": int("resonance", 0)?, "generation": int("generation", 1)?, "level": int("level", 1)?,
        "banked_resonance": int("banked_resonance", 0)?, "resonance_exchanges": int("resonance_exchanges", 0)?, "inductions_given": int("inductions_given", 0)?,
        "lineage_depth": depth, "daily_resonance_decay": int("daily_resonance_decay", DAILY_DECAY)?,
    }))
}

/// A shared cache value as _snapshot reads it: None is a miss (go to the
/// database), Some(row) a hit, where row None means no player row.
pub(crate) fn shared_row(cached: &Value) -> Result<Option<Option<Value>>> {
    if !cached.as_object().is_some_and(|m| m.contains_key("found")) { return Ok(None) }
    let row = &cached["row"];
    Ok(Some(if card::boolean(cached, "found") && (row.is_object() || row.is_array()) { Some(normalize(row)?) } else { None }))
}

/// The value _snapshot stores after a database read.
pub(crate) fn shared_value(row: Option<&Value>) -> Value { json!({"found": row.is_some(), "row": row}) }

/// `avatar_name` context: None when the profile has no verified bridge link.
pub(crate) fn linked_avatar(bridge: Option<&Value>) -> Result<Option<String>> { bridge.map(|b| php_str(&b["avatar_name"])).transpose() }

/// ap_phyrian_profile_card_html for an already normalized row (None: no row).
pub(crate) fn html(row: Option<&Value>, owner: i64, is_self: bool, avatar: Option<&str>, origin: Option<i64>) -> Result<String> {
    let player = row.cloned().unwrap_or_else(|| json!({"status": "", "strain": "", "resonance": 0, "level": 1}));
    let n = |k: &str| card::integer(&player, k);
    let strain = player["strain"].as_str().unwrap_or("");
    let imprinted = player["status"].as_str() == Some("imprinted") && !card::php_trim(strain).is_empty();
    let mut parts = vec!["<span class=\"phyrian-profile-card-title\" style=\"color:var(--text);font-weight:650;letter-spacing:.04em;text-transform:uppercase;font-size:.72rem\">Phyrian Strains</span>".to_owned()];
    if imprinted {
        let is_origin = owner > 0 && match origin { Some(id) => owner == id, None => bail!("PHP fallback: VAAK_PHYRIAN_ORIGIN_OWNER_ID is not a plain integer") };
        let resonance = n("resonance");
        let stability = match resonance { ..=0 => "Dormant", 1..=24 => "Critical", 25..=49 => "Fading", _ => "Stable" };
        let rank = if is_origin { "Phyrian Origin" } else { match n("level") { 60.. => "Deep Signal", 40..=59 => "Marked Host", 20..=39 => "Colony Node", _ => "Newly Marked" } };
        parts.push("<span class=\"phyrian-badge\">Imprinted</span>".into());
        if is_origin { parts.push("<span class=\"phyrian-badge phyrian-badge--origin\">Origin</span>".into()); }
        parts.push(format!("<span class=\"who\">{}</span>", esc(strain)));
        parts.push(format!("<span>{stability}</span>"));
        parts.push(format!("<span>Gen <b>{}</b></span>", n("generation")));
        parts.push(format!("<span>Level <b>{}</b>/{MAX_LEVEL}</span>", n("level")));
        parts.push(format!("<span>Rank <b>{rank}</b></span>"));
        parts.push(format!("<span>Resonance <b>{resonance}</b>/{MAX_RESONANCE}</span>"));
        if n("banked_resonance") > 0 { parts.push(format!("<span>Banked <b>{}</b></span>", n("banked_resonance"))); }
        parts.push(format!("<span>Decay <b>\u{2212}{}</b>/day</span>", n("daily_resonance_decay")));
        if n("resonance_exchanges") > 0 { parts.push(format!("<span>Exchanges <b>{}</b></span>", n("resonance_exchanges"))); }
        if n("inductions_given") > 0 { parts.push(format!("<span>Inductions <b>{}</b></span>", n("inductions_given"))); }
        if !player["lineage_depth"].is_null() && n("lineage_depth") > 0 { parts.push(format!("<span>Lineage <b>{}</b></span>", n("lineage_depth"))); }
    } else {
        parts.push("<span class=\"phyrian-badge phyrian-badge--unknown\">Unmarked</span>".into());
        parts.push("<span>Not imprinted yet.</span>".into());
        if is_self { parts.push("<span>Start playing to receive a strain.</span>".into()); }
        if row.is_some() {
            parts.push(format!("<span>Level <b>{}</b></span>", n("level")));
            parts.push(format!("<span>Resonance <b>{}</b>/{MAX_RESONANCE}</span>", n("resonance")));
        }
    }
    if let Some(avatar) = avatar {
        let avatar = card::php_trim(avatar);
        parts.push(if avatar.is_empty() { "<span>OpenSim linked</span>".into() } else { format!("<span>OpenSim linked as <b>{}</b></span>", esc(avatar)) });
    }
    let link = if is_self { Some(("?view=phyrian".to_owned(), if imprinted { "Open game" } else { "Start playing" })) } else if imprinted && owner > 0 { Some((format!("?view=phyrian&page=dossier&owner={owner}"), "Readout")) } else { None };
    if let Some((href, label)) = link { parts.push(format!("<a href=\"{}\" style=\"margin-left:auto;color:var(--primary);text-decoration:none\">{}</a>", esc(&href), esc(label))); }
    Ok(format!(
        "<section class=\"phyrian-profile-card\" data-phyrian-card=\"{}\" aria-label=\"Phyrian Strains\" style=\"display:flex;flex-wrap:wrap;align-items:center;gap:.35rem .85rem;width:100%;box-sizing:border-box;margin:.85rem 0 0;padding:.5rem .7rem;border-radius:12px;border:1px solid color-mix(in srgb, #5ee7ff 28%, var(--border));background:linear-gradient(160deg, rgba(94,231,255,.05), transparent 55%), var(--panel);color:var(--muted);font-size:.85rem;line-height:1.35;overflow-wrap:anywhere\">{}</section>\n",
        if imprinted { "imprinted" } else { "unmarked" },
        parts.concat()
    ))
}

#[cfg(test)]
mod tests {
    use super::*;

    fn fixture() -> Value { serde_json::from_str(include_str!("../../../api/fixtures/phyrian-profile-card.json")).unwrap() }

    #[test]
    fn shared_fixture_matches_php() {
        let fixture = fixture();
        let origin = fixture["origin_owner_user_id"].as_i64();
        let cards = fixture["cards"].as_array().unwrap();
        assert!(cards.len() >= 25);
        for case in cards {
            let id = case["id"].as_str().unwrap();
            let owner = case["owner"].as_i64().unwrap();
            let is_self = case["is_self"].as_bool().unwrap();
            let bridge = Some(&case["bridge"]).filter(|b| !b.is_null());
            let avatar = linked_avatar(bridge).unwrap();
            // Database path: the raw row through normalize, no row when the owner is unknown.
            let row = if owner < 1 { None } else { Some(&case["row"]).filter(|r| !r.is_null()).map(|r| normalize(r).unwrap()) };
            assert!(html(row.as_ref(), owner, is_self, avatar.as_deref(), origin).unwrap() == case["html"].as_str().unwrap(), "{id} card markup differs from PHP");
            // Shared cache path: the value PHP itself stored for this row.
            if owner > 0 {
                let cached = shared_row(&json!({"found": !case["row"].is_null(), "row": case["row"]})).unwrap().expect("found key is a hit");
                assert!(html(cached.as_ref(), owner, is_self, avatar.as_deref(), origin).unwrap() == case["html"].as_str().unwrap(), "{id} cached card markup differs from PHP");
                if let Some(row) = &row { assert!(shared_row(&shared_value(Some(row))).unwrap() == Some(Some(row.clone())), "{id} stored value round-trips"); }
            }
        }
        for case in fixture["cache"].as_array().unwrap() {
            let id = case["id"].as_str().unwrap();
            let read = serde_json::from_str::<Value>(case["cached"].as_str().unwrap()).ok().map(|v| shared_row(&v).unwrap()).unwrap_or(None);
            assert!(read.is_some() == case["hit"].as_bool().unwrap(), "{id} cache hit differs from PHP");
            assert!(read.flatten().unwrap_or(Value::Null) == case["row"], "{id} cached row differs from PHP");
        }
        for case in fixture["flags"].as_array().unwrap() {
            assert!(flag_on(case["raw"].as_str()) == case["enabled"].as_bool().unwrap(), "switch value {:?} differs from PHP", case["raw"]);
        }
    }

    #[test]
    fn owner_and_origin_follow_php() {
        assert_eq!(owner(true, 4, 9), 4);
        assert_eq!(owner(false, 4, 9), 9);
        assert_eq!(owner(true, 0, 9), 9);
        assert_eq!(owner(false, 4, 0), 0);
        for (raw, id) in [(None, Some(1)), (Some(""), Some(1)), (Some("0"), Some(1)), (Some("7"), Some(7)), (Some(" 7\n"), Some(7)), (Some("+3"), Some(3)), (Some("-2"), Some(1)), (Some("-0"), Some(1)), (Some("  "), Some(1)), (Some("7x"), None), (Some("1e3"), None), (Some("99999999999999999999"), None)] {
            assert_eq!(origin_from(raw), id, "{raw:?}");
        }
        let row = normalize(&json!({"status": "imprinted", "strain": "Phyrian"})).unwrap();
        assert!(html(Some(&row), 4, false, None, None).is_err(), "an unreadable origin id only matters for an imprinted card");
        assert!(html(None, 4, false, None, None).is_ok());
        assert!(html(Some(&row), 0, true, None, None).is_ok(), "no owner never asks for the origin id");
    }

    #[test]
    fn unusual_values_fall_back_to_php() {
        for row in [json!({"level": 1.5}), json!({"resonance": [1]}), json!({"status": {"a": 1}}), json!({"generation": "2 kids"}), json!({"strain": 2.5})] {
            assert!(normalize(&row).unwrap_err().to_string().starts_with("PHP fallback:"));
        }
        assert_eq!(normalize(&json!({"level": " 4 ", "resonance": true, "strain": 7, "lineage_depth": "", "status": null})).unwrap(), json!({"status": "", "strain": "7", "resonance": 1, "generation": 1, "level": 4, "banked_resonance": 0, "resonance_exchanges": 0, "inductions_given": 0, "lineage_depth": null, "daily_resonance_decay": 5}));
        assert!(linked_avatar(Some(&json!({"avatar_name": ["x"]}))).is_err());
        assert_eq!(linked_avatar(None).unwrap(), None);
    }
}
