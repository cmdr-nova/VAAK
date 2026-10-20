//! Fill Mastodon `emojis` on statuses and accounts from the host emoji cache.
//!
//! Remote AS2 posts are stored without their Emoji tags (events keep only the
//! summary), so `:shortcode:` text is resolved against `remote_custom_emojis`
//! at hydrate time. Lean painters then read `status.emojis` and
//! `account.emojis` through `vaak_types::emoji`, never the database.

use std::collections::{HashMap, HashSet};

use anyhow::Result;
use serde_json::Value;
use tokio_postgres::Client;
use vaak_types::emoji::{emoji_entry, merge_emojis, shortcodes_in};

const MAX_DEPTH: usize = 4;

fn host_of(url: &str) -> Option<String> {
    reqwest::Url::parse(url)
        .ok()
        .filter(|u| u.scheme() == "https")
        .and_then(|u| u.host_str().map(str::to_ascii_lowercase))
}

fn is_status(value: &Value) -> bool {
    value.get("account").is_some_and(Value::is_object) && value.get("content").is_some_and(Value::is_string)
}

fn status_host(status: &Value) -> Option<String> {
    let account = status.get("account")?;
    ["uri", "url"]
        .iter()
        .filter_map(|k| account.get(*k).and_then(Value::as_str))
        .chain(status.get("uri").and_then(Value::as_str))
        .find_map(host_of)
}

fn str_of<'a>(value: &'a Value, key: &str) -> &'a str {
    value.get(key).and_then(Value::as_str).unwrap_or("")
}

/// Codes a status still needs: body + CW against `emojis`, name against `account.emojis`.
fn wanted(status: &Value) -> (Vec<String>, Vec<String>) {
    let have = vaak_types::emoji::status_emoji_map(status);
    let mut body: Vec<String> = shortcodes_in(str_of(status, "content"));
    for code in shortcodes_in(str_of(status, "spoiler_text")) {
        if !body.contains(&code) {
            body.push(code);
        }
    }
    body.retain(|c| !have.contains_key(c));
    let account = &status["account"];
    let have_name = vaak_types::emoji::account_emoji_map(account);
    let mut name = shortcodes_in(str_of(account, "display_name"));
    name.retain(|c| !have_name.contains_key(c));
    (body, name)
}

fn visit<'a>(value: &'a Value, depth: usize, out: &mut Vec<&'a Value>) {
    if depth > MAX_DEPTH {
        return;
    }
    match value {
        Value::Object(map) => {
            if is_status(value) {
                out.push(value);
            }
            for (key, child) in map {
                if key == "account" || key == "emojis" || key == "media_attachments" {
                    continue;
                }
                if child.is_object() || child.is_array() {
                    visit(child, depth + 1, out);
                }
            }
        }
        Value::Array(items) => items.iter().for_each(|child| visit(child, depth + 1, out)),
        _ => {}
    }
}

fn visit_mut(value: &mut Value, depth: usize, apply: &dyn Fn(&mut Value)) {
    if depth > MAX_DEPTH {
        return;
    }
    if is_status(value) {
        apply(value);
    }
    match value {
        Value::Object(map) => {
            for (key, child) in map.iter_mut() {
                if key == "account" || key == "emojis" || key == "media_attachments" {
                    continue;
                }
                if child.is_object() || child.is_array() {
                    visit_mut(child, depth + 1, apply);
                }
            }
        }
        Value::Array(items) => items.iter_mut().for_each(|child| visit_mut(child, depth + 1, apply)),
        _ => {}
    }
}

/// (host, shortcode) pairs the page still needs, grouped for one query.
pub(crate) fn wanted_pairs(statuses: &[Value]) -> (Vec<String>, Vec<String>) {
    let mut hosts = HashSet::new();
    let mut codes = HashSet::new();
    for root in statuses {
        let mut found = Vec::new();
        visit(root, 0, &mut found);
        for status in found {
            let (body, name) = wanted(status);
            if body.is_empty() && name.is_empty() {
                continue;
            }
            let Some(host) = status_host(status) else { continue };
            hosts.insert(host);
            codes.extend(body);
            codes.extend(name);
        }
    }
    let mut hosts: Vec<String> = hosts.into_iter().collect();
    let mut codes: Vec<String> = codes.into_iter().collect();
    hosts.sort();
    codes.sort();
    (hosts, codes)
}

/// Pure half of [`attach_custom_emojis`]; `cache` is host -> shortcode -> (url, static).
pub(crate) fn apply_cache(statuses: &mut [Value], cache: &HashMap<String, HashMap<String, (String, String)>>) {
    let apply = |status: &mut Value| {
        let (body, name) = wanted(status);
        if body.is_empty() && name.is_empty() {
            return;
        }
        let Some(map) = status_host(status).and_then(|h| cache.get(&h)) else { return };
        let pick = |codes: &[String]| -> Vec<Value> {
            codes
                .iter()
                .filter_map(|c| map.get(c).and_then(|(url, st)| emoji_entry(c, url, Some(st))))
                .collect()
        };
        let body_entries = pick(&body);
        let name_entries = pick(&name);
        if !body_entries.is_empty() {
            let mut list = status.get("emojis").cloned().unwrap_or(Value::Null);
            merge_emojis(&mut list, body_entries);
            status["emojis"] = list;
        }
        if !name_entries.is_empty() {
            let account = &mut status["account"];
            let mut list = account.get("emojis").cloned().unwrap_or(Value::Null);
            merge_emojis(&mut list, name_entries);
            account["emojis"] = list;
        }
    };
    for root in statuses.iter_mut() {
        visit_mut(root, 0, &apply);
    }
}

/// Batch-resolve `:shortcode:` text on a page of statuses (reblogs and quotes
/// included) with one cache query. Cache-only: never fetches remotely.
pub async fn attach_custom_emojis(db: &Client, statuses: &mut [Value]) -> Result<()> {
    let (hosts, codes) = wanted_pairs(statuses);
    if hosts.is_empty() || codes.is_empty() {
        return Ok(());
    }
    let rows = db
        .query(
            "SELECT host, shortcode, url, COALESCE(static_url, '') FROM remote_custom_emojis
             WHERE host = ANY($1) AND shortcode = ANY($2)",
            &[&hosts, &codes],
        )
        .await?;
    let mut cache: HashMap<String, HashMap<String, (String, String)>> = HashMap::new();
    for row in rows {
        let host: String = row.get(0);
        let code: String = row.get(1);
        let url: String = row.get(2);
        let st: String = row.get(3);
        cache.entry(host).or_default().insert(code, (url, st));
    }
    apply_cache(statuses, &cache);
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    fn cache() -> HashMap<String, HashMap<String, (String, String)>> {
        let mut host = HashMap::new();
        host.insert("menhera_happy_hearts".to_string(), ("https://cdn.transfem.social/e/m.webp".to_string(), String::new()));
        host.insert("verifiedtrans".to_string(), ("https://cdn.transfem.social/e/v.webp".to_string(), String::new()));
        HashMap::from([("transfem.social".to_string(), host)])
    }

    #[test]
    fn fills_body_name_reblog_and_quote() {
        let note = json!({
            "uri": "https://transfem.social/notes/asjpkdwk5a7i2ob1",
            "content": "<p>You're all wonderful :menhera_happy_hearts: :unknown:</p>",
            "spoiler_text": "",
            "emojis": [],
            "account": {"uri": "https://transfem.social/users/9x", "display_name": "Ari :verifiedtrans:", "emojis": []}
        });
        let mut page = vec![
            json!({"content": "", "account": {"uri": "https://mkultra.monster/users/cmdr_nova"}, "reblog": note.clone()}),
            json!({"content": "q", "account": {"uri": "https://mkultra.monster/users/cmdr_nova"}, "quote": {"quoted_status": note}}),
        ];
        let (hosts, codes) = wanted_pairs(&page);
        assert_eq!(hosts, vec!["transfem.social"]);
        assert_eq!(codes, vec!["menhera_happy_hearts", "unknown", "verifiedtrans"]);
        apply_cache(&mut page, &cache());
        for inner in [&page[0]["reblog"], &page[1]["quote"]["quoted_status"]] {
            assert_eq!(inner["emojis"].as_array().map(Vec::len), Some(1));
            assert_eq!(inner["emojis"][0]["shortcode"], "menhera_happy_hearts");
            assert_eq!(inner["account"]["emojis"][0]["shortcode"], "verifiedtrans");
        }
        assert!(page[0].get("emojis").is_none());
    }

    #[test]
    fn keeps_existing_entries() {
        let mut page = vec![json!({
            "content": ":menhera_happy_hearts:",
            "emojis": [{"shortcode": "menhera_happy_hearts", "url": "https://own.test/x.png"}],
            "account": {"uri": "https://transfem.social/users/9x"}
        })];
        assert!(wanted_pairs(&page).0.is_empty());
        apply_cache(&mut page, &cache());
        assert_eq!(page[0]["emojis"][0]["url"], "https://own.test/x.png");
    }
}
