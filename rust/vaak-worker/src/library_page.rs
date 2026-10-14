//! Library page windows, cursors, and the Favourites end-of-list line.
//!
//! PHP still runs the queries and writes the Link header. These helpers match
//! that paging so a later activation can keep the same offsets. The native
//! Library switch stays off.

use std::collections::BTreeSet;

#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct PageKey {
    pub kind: String,
    pub ts: String,
    pub reference: String,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct SourcePage {
    pub offset: i64,
    pub limit: i64,
    pub keys: Vec<PageKey>,
    pub next_offset: i64,
    pub has_more: bool,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct ApiRow {
    pub owner_id: i64,
    pub status_id: String,
    pub created_at: String,
}

/// Web Library pages: offset stays at zero or above, and a page is 10 to 40 rows.
pub(crate) fn page_bounds(offset: i64, limit: i64) -> (i64, i64) {
    (offset.max(0), limit.clamp(10, 40))
}

/// Same window as `admin_library_source_page`: time descending, then reference
/// descending, one extra row to decide whether another page exists. The next
/// offset counts source keys, not cards that actually painted.
pub(crate) fn source_page(mut keys: Vec<PageKey>, offset: i64, limit: i64) -> SourcePage {
    let (offset, limit) = page_bounds(offset, limit);
    keys.sort_by(|left, right| right.ts.cmp(&left.ts).then_with(|| right.reference.cmp(&left.reference)));
    let start = offset.max(0) as usize;
    let window: Vec<PageKey> = if start >= keys.len() { Vec::new() } else { keys[start..].to_vec() };
    let has_more = window.len() > limit as usize;
    let keys: Vec<PageKey> = window.into_iter().take(limit as usize).collect();
    let next_offset = offset + keys.len() as i64;
    SourcePage { offset, limit, keys, next_offset, has_more }
}

/// The full Favourites page asks whether hydration passed 20 before Bluesky
/// rows are removed. Soft-nav and AJAX ask after that filter.
pub(crate) fn favourite_has_more(surface: &str, hydrated: i64, filtered: i64, offset: i64, limit: i64) -> bool {
    if surface == "page" {
        return hydrated > 20;
    }
    let (offset, limit) = page_bounds(offset, limit);
    filtered > offset + limit
}

pub(crate) fn favourite_sentinel(has_more: bool) -> &'static str {
    if has_more { "Scroll for more…" } else { "End of Fediverse favourites" }
}

/// Mastodon library lists clamp to 1..=80 and fetch twice that, at most 160.
pub(crate) fn api_bounds(limit: i64) -> (i64, i64) {
    let limit = limit.clamp(1, 80);
    (limit, (limit * 2).min(160))
}

/// Favourites and bookmarks read limit and max_id. since_id and min_id do not
/// move those lists. A missing limit stays 40, which is the API default.
/// A non-numeric limit becomes 0, then the row query clamps it to 1.
pub(crate) fn library_list_request(query: &serde_json::Map<String, serde_json::Value>) -> (i64, Option<String>) {
    let limit = query.get("limit").map(|value| match value {
        serde_json::Value::String(text) => text.parse::<i64>().unwrap_or(0),
        serde_json::Value::Number(number) => number.as_i64().unwrap_or(0),
        _ => 0,
    });
    let max_id = query.get("max_id").map(|value| value.as_str().unwrap_or("").to_owned());
    (limit.unwrap_or(40), max_id)
}

fn created_before(rows: &[ApiRow], owner: i64, max_id: Option<&str>) -> Option<String> {
    let max_id = max_id.filter(|id| !id.is_empty())?;
    rows.iter()
        .find(|row| row.owner_id == owner && row.status_id == max_id)
        .map(|row| row.created_at.clone())
        .filter(|created| !created.is_empty())
}

/// `created_at < cursor`, newest first, no second sort key. Rows that share the
/// cursor timestamp are dropped, including the cursor row itself.
pub(crate) fn api_rows(rows: &[ApiRow], owner: i64, limit: i64, max_id: Option<&str>) -> Vec<ApiRow> {
    let (_limit, fetch) = api_bounds(limit);
    let before = created_before(rows, owner, max_id);
    let mut selected: Vec<ApiRow> = rows.iter().filter(|row| row.owner_id == owner).filter(|row| {
        before.as_ref().map(|cut| row.created_at.as_str() < cut.as_str()).unwrap_or(true)
    }).cloned().collect();
    selected.sort_by(|left, right| right.created_at.cmp(&left.created_at));
    selected.truncate(fetch as usize);
    selected
}

fn php_urlencode(value: &str) -> String {
    let mut encoded = String::new();
    for byte in value.bytes() {
        match byte {
            b'A'..=b'Z' | b'a'..=b'z' | b'0'..=b'9' | b'-' | b'_' | b'.' => encoded.push(byte as char),
            b' ' => encoded.push('+'),
            _ => encoded.push_str(&format!("%{byte:02X}")),
        }
    }
    encoded
}

pub(crate) fn timeline_base(host: &str, path: &str) -> String {
    let cleaned: String = host.chars().filter(|ch| ch.is_ascii_alphanumeric() || matches!(ch, '.' | ':' | '-')).collect();
    let host = if cleaned.is_empty() { "vaak.monster" } else { cleaned.as_str() };
    format!("https://{host}{path}")
}

/// next is max_id of the last id. prev is min_id of the first id. The limit is
/// the requested limit, before the list clamp. An empty id omits that relation.
pub(crate) fn timeline_link(statuses: &[&str], host: &str, path: &str, limit: i64) -> String {
    if statuses.is_empty() {
        return String::new();
    }
    let first = statuses[0];
    let last = statuses[statuses.len() - 1];
    let base = timeline_base(host, path);
    let mut parts = Vec::new();
    if !last.is_empty() {
        parts.push(format!("<{base}?limit={limit}&max_id={}>; rel=\"next\"", php_urlencode(last)));
    }
    if !first.is_empty() {
        parts.push(format!("<{base}?limit={limit}&min_id={}>; rel=\"prev\"", php_urlencode(first)));
    }
    parts.join(", ")
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::Value;

    fn number(value: &Value) -> i64 {
        value.as_i64().or_else(|| value.as_str().and_then(|text| text.parse().ok())).unwrap_or(0)
    }

    fn text<'a>(value: &'a Value, key: &str) -> &'a str {
        value.get(key).and_then(Value::as_str).unwrap_or("")
    }

    fn capture() -> Value {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/library-page-capture.php");
        let output = std::process::Command::new(file.parent().unwrap().join("php-policy-test")).arg(&file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        serde_json::from_slice(&output.stdout).unwrap()
    }

    fn page_key(value: &Value) -> PageKey {
        PageKey { kind: text(value, "kind").to_owned(), ts: text(value, "ts").to_owned(), reference: text(value, "ref").to_owned() }
    }

    fn api_row(value: &Value) -> ApiRow {
        ApiRow { owner_id: number(&value["owner_id"]), status_id: text(value, "status_id").to_owned(), created_at: text(value, "created_at").to_owned() }
    }

    fn groups(rows: &[ApiRow]) -> Vec<(String, BTreeSet<String>)> {
        let mut grouped: Vec<(String, BTreeSet<String>)> = Vec::new();
        for row in rows {
            if grouped.last().map(|(created, _)| created == &row.created_at).unwrap_or(false) {
                grouped.last_mut().unwrap().1.insert(row.status_id.clone());
            } else {
                grouped.push((row.created_at.clone(), BTreeSet::from([row.status_id.clone()])));
            }
        }
        grouped
    }

    #[test]
    fn library_pages_cursors_and_sentinels_match_php() {
        let captured = capture();
        for bound in captured["bounds"].as_array().unwrap() {
            let (offset, limit) = page_bounds(number(&bound["offset"]), number(&bound["limit"]));
            assert_eq!((offset, limit), (number(&bound["clamped_offset"]), number(&bound["clamped_limit"])));
        }
        let keys: Vec<PageKey> = captured["keys"].as_array().unwrap().iter().map(page_key).collect();
        let mut saw_boundary = false;
        let mut previous_refs = BTreeSet::new();
        for page in captured["pages"].as_array().unwrap() {
            let actual = source_page(keys.clone(), number(&page["offset"]), number(&page["limit"]));
            let expected: Vec<PageKey> = page["keys"].as_array().unwrap().iter().map(page_key).collect();
            assert_eq!(actual.keys, expected, "page {} {}", page["offset"], page["limit"]);
            assert_eq!(actual.next_offset, number(&page["next_offset"]));
            assert_eq!(actual.has_more, page["has_more"].as_bool().unwrap());
            assert_eq!(actual.offset, number(&page["clamped_offset"]));
            assert_eq!(actual.limit, number(&page["clamped_limit"]));
            let painted = number(&page["painted"]);
            if painted != actual.keys.len() as i64 {
                assert_ne!(actual.next_offset, number(&page["offset"]).max(0) + painted, "cursor followed painted cards");
            }
            if number(&page["offset"]) == 0 && number(&page["limit"]) == 10 {
                assert_eq!(actual.keys.last().map(|key| key.reference.as_str()), Some("b"));
                assert_eq!(actual.keys.last().map(|key| key.kind.as_str()), Some("fedi"));
                saw_boundary = true;
            }
            if number(&page["offset"]) == 10 && number(&page["limit"]) == 10 {
                assert_eq!(actual.keys.first().map(|key| (key.kind.as_str(), key.reference.as_str())), Some(("bsky", "a")));
                for key in &actual.keys {
                    assert!(previous_refs.insert(format!("{}:{}", key.kind, key.reference)), "page repeated a source key");
                }
            }
            if number(&page["offset"]) == 0 && number(&page["limit"]) == 10 {
                previous_refs = actual.keys.iter().map(|key| format!("{}:{}", key.kind, key.reference)).collect();
            }
        }
        assert!(saw_boundary, "mixed page boundary was not captured");
        assert!(captured["pages"].as_array().unwrap().iter().any(|page| number(&page["offset"]) == 41 && !page["has_more"].as_bool().unwrap() && page["keys"].as_array().unwrap().is_empty()));

        for row in captured["favourites"].as_array().unwrap() {
            let has_more = favourite_has_more(text(row, "surface"), number(&row["hydrated"]), number(&row["filtered"]), number(&row["offset"]), number(&row["limit"]));
            assert_eq!(has_more, row["has_more"].as_bool().unwrap(), "{row}");
            assert_eq!(favourite_sentinel(has_more), text(row, "sentinel"));
        }

        for bound in captured["api_bounds"].as_array().unwrap() {
            let (limit, fetch) = api_bounds(number(&bound["limit"]));
            assert_eq!((limit, fetch), (number(&bound["clamped"]), number(&bound["fetch"])));
        }
        for request in captured["requests"].as_array().unwrap() {
            let query = request["query"].as_object().unwrap();
            assert!(query.get("since_id").is_none() || request.get("max_id") != query.get("since_id"));
            assert!(query.get("min_id").is_none() || request.get("max_id") != query.get("min_id"));
            let (actual_limit, actual_max) = library_list_request(query);
            assert_eq!(actual_limit, number(&request["limit"]));
            let expected_max = if request["max_id"].is_null() { None } else { Some(text(request, "max_id").to_owned()) };
            assert_eq!(actual_max, expected_max);
        }

        let favourites: Vec<ApiRow> = captured["favourites_rows"].as_array().unwrap().iter().map(api_row).collect();
        let bookmarks: Vec<ApiRow> = captured["bookmarks_rows"].as_array().unwrap().iter().map(api_row).collect();
        for page in captured["api_pages"].as_array().unwrap() {
            assert!(!text(page, "since_id").is_empty(), "since_id was not offered to PHP");
            let owner = if page["owner"].is_null() { 1 } else { number(&page["owner"]) };
            let max_id = if page["max_id"].is_null() { None } else { Some(text(page, "max_id")) };
            let source = if text(page, "table") == "bookmarks" { &bookmarks } else { &favourites };
            let actual = api_rows(source, owner, number(&page["limit"]), max_id);
            let expected: Vec<ApiRow> = page["rows"].as_array().unwrap().iter().map(api_row).collect();
            assert_eq!(groups(&actual), groups(&expected), "{page}");
            assert!(!actual.iter().any(|row| row.owner_id != owner));
        }

        for link in captured["links"].as_array().unwrap() {
            let statuses: Vec<String> = link["statuses"].as_array().unwrap().iter().map(|status| text(status, "id").to_owned()).collect();
            let refs: Vec<&str> = statuses.iter().map(String::as_str).collect();
            let actual = timeline_link(&refs, text(link, "host"), text(link, "path"), number(&link["limit"]));
            assert_eq!(actual, text(link, "link"));
            assert!(!actual.contains("since_id"));
        }
    }
}
