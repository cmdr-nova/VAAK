//! Offline fixture replay for the PHP/Rust parity harness.
//!
//! Each producer reads `{ "cases": [{ "id", "input" }] }` and writes
//! `{ "cases": [{ "id", "output" }] }`. Producers call the same Rust functions
//! the in-crate parity tests call. They do not open Postgres, Redis, or a
//! socket. A producer error fails the process; output differences are the
//! harness's concern and are not treated as failures here.

use std::collections::HashMap;
use std::fs;
use std::io::{self, Write};
use std::path::Path;

use anyhow::{anyhow, bail, Context, Result};
use serde::Deserialize;
use serde_json::{json, Value};

use crate::library::card::CardFacts;
use crate::library::actions::{Counts, ModerationContext};

#[derive(Deserialize)]
struct InputFile {
    cases: Vec<InputCase>,
}

#[derive(Deserialize)]
struct InputCase {
    id: String,
    input: Value,
}

pub fn run(producer: &str, input: &Path) -> Result<()> {
    let raw = fs::read(input).with_context(|| format!("read {}", input.display()))?;
    let file: InputFile = serde_json::from_slice(&raw).context("parse replay input")?;
    let cases = produce(producer, &file.cases).with_context(|| format!("producer {producer}"))?;
    let mut stdout = io::stdout().lock();
    serde_json::to_writer(&mut stdout, &json!({"cases": cases}))?;
    stdout.write_all(b"\n")?;
    Ok(())
}

fn produce(producer: &str, cases: &[InputCase]) -> Result<Vec<Value>> {
    cases.iter().map(|case| {
        let output = match producer {
            "search-render" => search_render(&case.input)?,
            "search-query" => search_query(&case.input)?,
            "library-components" => library_components(&case.input)?,
            "profile-identity" => profile_identity(&case.input)?,
            "profile-markdown" => profile_markdown(&case.input)?,
            "audience" => audience(&case.input)?,
            "phyrian-opensim" => phyrian_opensim(&case.input)?,
            "phyrian-bridge" => phyrian_bridge(&case.input)?,
            "settings-fields" => settings_fields(&case.id)?,
            "follow-fallback" => follow_fallback(&case.input)?,
            "inbox-origin" => crate::inbox_origin::replay(&case.input)?,
            "profile-local-chrome" => profile_local_chrome(&case.input)?,
            "profile-remote-chrome" => profile_remote_chrome(&case.input)?,
            "timeline-cursor" => timeline_cursor(&case.input)?,
            "profile-bsky-chrome" => profile_bsky_chrome(&case.input)?,
            "profile-guest-actions" => profile_guest_actions(&case.input)?,
            "wafrn-crosspost" => crate::wafrn_crosspost::replay(&case.input),
            other => bail!("unknown producer {other}"),
        };
        Ok(json!({"id": case.id, "output": output}))
    }).collect()
}

fn search_render(input: &Value) -> Result<Value> {
    let kind = str_field(input, "kind")?;
    let body = input.get("input").cloned().unwrap_or(Value::Null);
    let facts = input.get("facts").filter(|value| !value.is_null());
    let html = crate::search_render::parity_render(kind, &body, facts).map_err(anyhow::Error::msg)?;
    Ok(Value::String(html))
}

fn search_query(input: &Value) -> Result<Value> {
    let query = str_field(input, "query")?;
    let tag = str_field(input, "tag")?;
    let matched = if tag.is_empty() {
        crate::search::parity_text_match(query)
    } else {
        crate::search::parity_tag_match(tag)
    };
    let postgres = matched.as_deref().map(crate::search::parity_postgres_match);
    Ok(json!({"match": matched, "postgres": postgres}))
}

fn library_components(input: &Value) -> Result<Value> {
    let section = str_field(input, "section")?;
    match section {
        "reply_cw" => {
            let mentions = vec![];
            Ok(Value::String(crate::library::actions::reply_suffix(
                &mentions,
                str_field(input, "cw")?,
                bool_field(input, "sensitive")?,
            )))
        }
        "cw" => Ok(Value::String(crate::library::card::cw_gate(
            str_field(input, "cw")?,
            bool_field(input, "sensitive")?,
            "<div>Body</div>",
            bool_field(input, "auto")?,
        ))),
        "time" => {
            let now = unix_time(input, "now")?;
            Ok(Value::String(crate::library::card::relative_time(str_field(input, "input")?, now)))
        }
        "media" => {
            let items = input.get("items").and_then(Value::as_array).ok_or_else(|| anyhow!("media items"))?;
            Ok(Value::String(crate::library::card::media_row(items, bool_field(input, "lazy")?)))
        }
        "account_ref" => Ok(Value::String(crate::library::card::account_actor_ref(
            input.get("account").ok_or_else(|| anyhow!("account"))?,
        ))),
        "reply_meta" => Ok(Value::String(crate::library::card::parity_reply_meta(
            input.get("status").ok_or_else(|| anyhow!("status"))?,
            "bookmarks",
        )?)),
        "ask" => Ok(Value::String(crate::library::card::ask_html(
            input.get("row").ok_or_else(|| anyhow!("ask row"))?,
        )?)),
        "preview" => Ok(Value::String(crate::library::card::link_preview_mode(
            input.get("card").ok_or_else(|| anyhow!("card"))?,
            bool_field(input, "interactive")?,
        )?)),
        "quote" => {
            let facts = quote_facts(input)?;
            Ok(Value::String(crate::library::card::parity_quote_card(
                input.get("status").ok_or_else(|| anyhow!("status"))?,
                &facts,
            )?))
        }
        "rss_title" => Ok(Value::String(crate::library::rss::clean_feed_title(str_field(input, "input")?))),
        "rss_summary" => Ok(Value::String(crate::library::rss::parity_clean_summary(str_field(input, "input")?))),
        "rss_status" => {
            let row = input.get("row").ok_or_else(|| anyhow!("rss row"))?;
            let owner = input.get("owner").and_then(Value::as_i64).ok_or_else(|| anyhow!("rss owner"))?;
            Ok(crate::library::rss::normalize(row, owner)?)
        }
        "width" => {
            let text = str_field(input, "text")?;
            Ok(json!({
                "width": crate::library::rss::parity_width_sum(text),
                "trim": crate::library::rss::parity_trim_width(text, 500),
            }))
        }
        "alias" => Ok(Value::Bool(crate::library::card::status_urls_equivalent(
            str_field(input, "a")?,
            str_field(input, "b")?,
        ))),
        "emoji" => {
            let map = string_map(input.get("map").unwrap_or(&Value::Null))?;
            Ok(Value::String(crate::library::body::emojis(
                str_field(input, "text")?,
                &map,
                bool_field(input, "display")?,
            )))
        }
        "count" => {
            let n = input.get("n").and_then(Value::as_i64).ok_or_else(|| anyhow!("count"))?;
            Ok(Value::String(crate::library::actions::compact_count(n)))
        }
        "reply_handle" => Ok(Value::String(crate::library::actions::reply_mention_handle(
            str_field(input, "actor")?,
            str_field(input, "hint")?,
            str_field(input, "owner_actor")?,
        ))),
        other => bail!("unsupported library section {other}"),
    }
}

fn quote_facts(input: &Value) -> Result<CardFacts> {
    let now = unix_time(input, "now")?;
    let row = input.get("row").filter(|value| !value.is_null()).cloned();
    Ok(CardFacts {
        bsky: None,
        owner_id: 1,
        owner_actor: "https://mkultra.monster/users/fixture".into(),
        return_view: "bookmarks".into(),
        auto_unblur_sensitive: bool_field(input, "auto").unwrap_or(false),
        resolved_avatar: String::new(),
        csrf: String::new(),
        favourited: false,
        bookmarked: false,
        boosted: false,
        own: None,
        current_action_counts: None::<Counts>,
        rss_row: None,
        rss_quote_row: row,
        rss_quoted: false,
        emoji_by_actor: HashMap::new(),
        reply_mentions: Vec::new(),
        moderation: ModerationContext::default(),
        poll_row: None,
        ask_row: None,
        now,
        lazy_media: false,
    })
}

fn profile_identity(input: &Value) -> Result<Value> {
    match str_field(input, "kind")? {
        "label" => {
            let actor = str_field(input, "actor")?;
            let row = input.get("row").filter(|value| !value.is_null());
            Ok(crate::profile_identity::parity_identity(actor, row))
        }
        "avatar" => Ok(Value::String(crate::profile_identity::local_avatar(str_field(input, "icon")?))),
        other => bail!("unsupported profile identity kind {other}"),
    }
}

fn profile_markdown(input: &Value) -> Result<Value> {
    let text = str_field(input, "text")?;
    let html = match str_field(input, "kind")? {
        "markdown" => crate::profile_header::parity_markdown(text),
        "field" => crate::profile_header::parity_field_html(text),
        other => bail!("unsupported profile markdown kind {other}"),
    };
    Ok(Value::String(html))
}

fn audience(input: &Value) -> Result<Value> {
    let surface = crate::audience::replay_surface(str_field(input, "surface")?)
        .ok_or_else(|| anyhow!("unknown audience surface"))?;
    let status = input.get("status").ok_or_else(|| anyhow!("status"))?;
    let records = replay_records(input.get("records").unwrap_or(&Value::Null))?;
    let viewer = input.get("viewer").and_then(Value::as_str).unwrap_or("");
    let following = input.get("following").and_then(Value::as_array).map(|items| {
        items.iter().filter_map(|value| value.as_str().map(str::to_string)).collect::<Vec<_>>()
    }).unwrap_or_default();
    Ok(Value::Bool(crate::audience::replay_decision(status, &records, viewer, &following, surface)))
}

fn replay_records(value: &Value) -> Result<HashMap<String, crate::audience::ReplayRecord>> {
    let mut records = HashMap::new();
    let Some(object) = value.as_object() else { return Ok(records); };
    for (id, record) in object {
        records.insert(id.clone(), crate::audience::ReplayRecord {
            denied: record.get("denied").and_then(Value::as_bool).unwrap_or(false),
            visibility: record.get("visibility").and_then(Value::as_str).unwrap_or("public").to_string(),
            author: record.get("author").and_then(Value::as_str).unwrap_or("").to_string(),
            recipients: record.get("recipients").and_then(Value::as_array).map(|items| {
                items.iter().filter_map(|value| value.as_str().map(str::to_string)).collect()
            }).unwrap_or_default(),
        });
    }
    Ok(records)
}

fn phyrian_opensim(input: &Value) -> Result<Value> {
    Ok(serde_json::to_value(crate::phyrian::normalize_opensim_player(input))?)
}

fn phyrian_bridge(input: &Value) -> Result<Value> {
    match str_field(input, "kind")? {
        "envelope" => Ok(crate::phyrian::bridge_request_payload(
            str_field(input, "action")?,
            input.get("body").unwrap_or(&Value::Null),
            str_field(input, "secret")?,
        )),
        "decode" => {
            let http = input.get("http").and_then(Value::as_i64).ok_or_else(|| anyhow!("http"))?;
            Ok(crate::phyrian::decode_bridge_response(http, str_field(input, "raw")?))
        }
        other => bail!("unsupported phyrian bridge kind {other}"),
    }
}

fn follow_fallback(input: &Value) -> Result<Value> {
    crate::follow_fallback::replay(input)
}

fn profile_local_chrome(input: &Value) -> Result<Value> {
    let painted = crate::profile_local::paint_local(input).map_err(anyhow::Error::msg)?;
    Ok(json!({"chrome": painted.chrome, "secondary": painted.secondary}))
}

fn profile_remote_chrome(input: &Value) -> Result<Value> {
    let painted = crate::profile_remote::paint_remote(input).map_err(anyhow::Error::msg)?;
    Ok(json!({"chrome": painted.chrome, "secondary": painted.secondary}))
}

fn timeline_cursor(input: &Value) -> Result<Value> {
    use crate::timeline_cursor::{self, TlCursor, TlRow};
    let op = input.get("op").and_then(Value::as_str).unwrap_or("");
    match op {
        "pack" => {
            let Some(cursor) = input.get("cursor").filter(|v| !v.is_null()) else {
                return Ok(Value::String(String::new()));
            };
            let cursor = TlCursor {
                at: cursor.get("at").and_then(Value::as_str).unwrap_or("").to_string(),
                source: cursor.get("source").and_then(Value::as_str).unwrap_or("").to_string(),
                id: cursor.get("id").and_then(Value::as_str).unwrap_or("").to_string(),
            };
            Ok(Value::String(timeline_cursor::pack(&cursor)))
        }
        "unpack" => {
            let token = input.get("token").and_then(Value::as_str).unwrap_or("");
            Ok(match timeline_cursor::unpack(token) {
                Some(cursor) => json!({"at": cursor.at, "source": cursor.source, "id": cursor.id}),
                None => Value::Null,
            })
        }
        "older" => {
            let token = input.get("cursor").and_then(Value::as_str).unwrap_or("");
            let cursor = timeline_cursor::unpack(token);
            let row = input.get("row").cloned().unwrap_or(Value::Null);
            Ok(Value::Bool(timeline_cursor::is_older(
                row.get("at").and_then(Value::as_str).unwrap_or(""),
                row.get("source").and_then(Value::as_str).unwrap_or(""),
                row.get("id").and_then(Value::as_str).unwrap_or(""),
                cursor.as_ref(),
            )))
        }
        "bound" => {
            let token = input.get("cursor").and_then(Value::as_str).unwrap_or("");
            let cursor = timeline_cursor::unpack(token);
            let source = input.get("source").and_then(Value::as_str).unwrap_or("");
            let bound = timeline_cursor::source_bound(cursor.as_ref(), source);
            Ok(json!({"op": bound.op, "at": bound.at, "id": bound.id}))
        }
        "collect" => {
            let rows = input.get("rows").and_then(Value::as_array).cloned().unwrap_or_default();
            let stored: Vec<TlRow> = rows
                .iter()
                .map(|row| TlRow {
                    at: row.get("at").and_then(Value::as_str).unwrap_or("").to_string(),
                    source: row.get("source").and_then(Value::as_str).unwrap_or("").to_string(),
                    id: row.get("id").and_then(Value::as_str).unwrap_or("").to_string(),
                    keep: row.get("keep").and_then(Value::as_bool).unwrap_or(false),
                })
                .collect();
            let fetch = input.get("fetch").and_then(Value::as_u64).unwrap_or(10) as usize;
            let limit = input.get("limit").and_then(Value::as_u64).unwrap_or(1) as usize;
            let max_windows = input.get("max_windows").and_then(Value::as_u64).unwrap_or(5) as usize;
            let page = timeline_cursor::collect(
                |cursor| timeline_cursor::memory_window(&stored, cursor, fetch),
                None,
                limit,
                max_windows,
            );
            let ids: Vec<String> = page.kept.iter().map(|row| row.id.clone()).collect();
            Ok(json!({
                "ids": ids,
                "has_more": page.has_more,
                "windows": page.windows,
                "next": page.next.as_ref().map(timeline_cursor::pack).unwrap_or_default(),
            }))
        }
        other => bail!("unsupported timeline cursor op {other}"),
    }
}

fn profile_bsky_chrome(input: &Value) -> Result<Value> {
    let painted = crate::profile_bsky_chrome::paint_bsky(input).map_err(anyhow::Error::msg)?;
    Ok(json!({"chrome": painted.chrome, "secondary": painted.secondary}))
}

fn profile_guest_actions(input: &Value) -> Result<Value> {
    let kind = input.get("kind").and_then(Value::as_str).unwrap_or("local");
    let (chrome, page_back, header, secondary, feed_open, feed_close) = match kind {
        "remote" => {
            let painted = crate::profile_remote::paint_remote(input).map_err(anyhow::Error::msg)?;
            (painted.chrome, painted.page_back, painted.header, painted.secondary, None, None)
        }
        "bsky" => {
            let painted = crate::profile_bsky_chrome::paint_bsky(input).map_err(anyhow::Error::msg)?;
            (painted.chrome, painted.page_back, painted.header, painted.secondary, None, None)
        }
        _ => {
            let painted = crate::profile_local::paint_local(input).map_err(anyhow::Error::msg)?;
            (painted.chrome, painted.page_back, painted.header, painted.secondary, painted.feed_open, painted.feed_close)
        }
    };
    Ok(json!({
        "chrome": chrome,
        "page_back": page_back,
        "header": header,
        "secondary": secondary,
        "feed_open": feed_open,
        "feed_close": feed_close,
    }))
}

fn settings_fields(id: &str) -> Result<Value> {
    match id {
        "canonical-fields" => Ok(serde_json::to_value(crate::settings::CANONICAL_FIELDS)?),
        "declared-fixture" => Ok(serde_json::from_str(include_str!("../fixtures/settings/profile-fields.json"))?),
        other => bail!("unsupported settings id {other}"),
    }
}

fn str_field<'a>(value: &'a Value, key: &str) -> Result<&'a str> {
    value.get(key).and_then(Value::as_str).ok_or_else(|| anyhow!("missing string {key}"))
}

fn bool_field(value: &Value, key: &str) -> Result<bool> {
    value.get(key).and_then(Value::as_bool).ok_or_else(|| anyhow!("missing bool {key}"))
}

fn unix_time(value: &Value, key: &str) -> Result<chrono::DateTime<chrono::Utc>> {
    let stamp = value.get(key).and_then(Value::as_i64).ok_or_else(|| anyhow!("missing unix {key}"))?;
    chrono::DateTime::from_timestamp(stamp, 0).ok_or_else(|| anyhow!("invalid unix {key}"))
}

fn string_map(value: &Value) -> Result<HashMap<String, String>> {
    let Some(object) = value.as_object() else { return Ok(HashMap::new()); };
    Ok(object.iter().filter_map(|(key, item)| item.as_str().map(|text| (key.clone(), text.to_string()))).collect())
}
