//! Search > Nostr: stored notes from the people a linked user follows on
//! Nostr, matched against the search box. Reads only `nostr_events` (the
//! notes `sync` already keeps for Home), so it adds no relay traffic and
//! reaches back only as far as retention does. Users without a linked npub,
//! or with "Show in Home" off, get nothing. Mutes, hides, admin blocks and
//! muted words apply the same way they do on Home.

use std::collections::HashSet;

use anyhow::{Context, Result};
use serde_json::Value;
use tokio_postgres::Client;

/// Rows read per search before filtering; pages past this come back empty.
const SCAN_LIMIT: i64 = 400;
/// Words in one query. Extra words are ignored.
const MAX_TERMS: usize = 6;

/// What the search box asked for.
#[derive(Debug, Clone, PartialEq)]
pub enum Query {
    /// `#tag`: notes with that hashtag (lowercase, no `#`).
    Tag(String),
    /// Plain words: notes containing every one of them, any case.
    Words(Vec<String>),
}

/// Parse the search box. None when there is nothing searchable, or when the
/// query is a handle, link or npub (People and links cover those).
pub fn parse(raw: &str) -> Option<Query> {
    let q = raw.trim();
    if q.is_empty() || q.contains("://") || q.starts_with('@') || q.starts_with("npub1") || q.starts_with("nostr:") {
        return None;
    }
    if let Some(tag) = q.strip_prefix('#') {
        let tag = tag.trim().to_lowercase();
        let ok = (2..=100).contains(&tag.chars().count()) && tag.chars().all(|c| c.is_alphanumeric() || c == '_');
        return ok.then_some(Query::Tag(tag));
    }
    let words: Vec<String> = q
        .split_whitespace()
        .map(|w| w.trim_matches(|c: char| c == '"' || c == '\'').to_lowercase())
        .filter(|w| w.chars().count() >= 2)
        .take(MAX_TERMS)
        .collect();
    (!words.is_empty()).then_some(Query::Words(words))
}

/// `%term%` for ILIKE, with LIKE wildcards in the term taken literally.
fn like_pattern(term: &str) -> String {
    let mut out = String::with_capacity(term.len() + 2);
    out.push('%');
    for c in term.chars() {
        if matches!(c, '%' | '_' | '\\') {
            out.push('\\');
        }
        out.push(c);
    }
    out.push('%');
    out
}

/// True when `content` uses `#tag` as a whole hashtag (not `#tagged`).
pub fn has_hashtag(content: &str, tag: &str) -> bool {
    let lower = content.to_lowercase();
    let needle = format!("#{tag}");
    let mut from = 0;
    while let Some(at) = lower[from..].find(&needle) {
        let end = from + at + needle.len();
        let next = lower[end..].chars().next();
        if !next.is_some_and(|c| c.is_alphanumeric() || c == '_') {
            return true;
        }
        from = from + at + 1;
    }
    false
}

/// A note's `t` tags, lowercased.
fn t_tags(tags: &Value) -> Vec<String> {
    tags.as_array()
        .map(|a| {
            a.iter()
                .filter_map(Value::as_array)
                .filter(|t| t.first().and_then(Value::as_str) == Some("t"))
                .filter_map(|t| t.get(1).and_then(Value::as_str))
                .map(str::to_lowercase)
                .collect()
        })
        .unwrap_or_default()
}

/// Whether search may show Nostr to `owner`: linked npub with Home on.
pub async fn enabled_for(db: &Client, owner: i64) -> bool {
    if owner < 1 || super::sync::disabled_by_env() || !super::sync::tables_ready(db).await {
        return false;
    }
    db.query_opt("SELECT show_in_home FROM nostr_links WHERE owner_user_id = $1", &[&owner])
        .await
        .ok()
        .flatten()
        .is_some_and(|r| r.get::<_, bool>(0))
}

/// Matching note ids, newest first, already filtered for this viewer.
/// The bool says whether more matches exist past this page.
pub async fn note_ids(db: &Client, owner: i64, raw: &str, offset: i64, limit: i64) -> Result<(Vec<String>, bool)> {
    let Some(query) = parse(raw) else { return Ok((Vec::new(), false)) };
    if !enabled_for(db, owner).await {
        return Ok((Vec::new(), false));
    }
    // The viewer's follows plus their own key (their own notes are stored
    // when someone else linked follows them).
    let scope = "e.kind = 1 AND e.pubkey IN (
                   SELECT pubkey FROM nostr_follows WHERE owner_user_id = $1
                   UNION SELECT pubkey FROM nostr_links WHERE owner_user_id = $1)";
    let rows = match &query {
        Query::Tag(tag) => {
            let pattern = like_pattern(&format!("#{tag}"));
            let t = serde_json::json!([["t", tag]]);
            db.query(
                &format!(
                    "SELECT e.id, e.pubkey, e.content, e.tags FROM nostr_events e
                     WHERE {scope} AND (e.content ILIKE $2 OR e.tags @> $3)
                     ORDER BY e.created_at DESC, e.id DESC LIMIT $4"
                ),
                &[&owner, &pattern, &t, &SCAN_LIMIT],
            )
            .await
        }
        Query::Words(words) => {
            let patterns: Vec<String> = words.iter().map(|w| like_pattern(w)).collect();
            db.query(
                &format!(
                    "SELECT e.id, e.pubkey, e.content, e.tags FROM nostr_events e
                     WHERE {scope} AND e.content ILIKE ALL($2)
                     ORDER BY e.created_at DESC, e.id DESC LIMIT $3"
                ),
                &[&owner, &patterns, &SCAN_LIMIT],
            )
            .await
        }
    }
    .context("nostr search")?;
    let hidden: HashSet<String> = super::moderation::hidden_keys(db, owner).await;
    let muted = crate::ranked_warm::load_muted_phrases(db, owner).await;
    let mut ids = Vec::new();
    for r in &rows {
        let pubkey: String = r.get(1);
        let content: String = r.get(2);
        let tags: Value = r.get(3);
        if hidden.contains(&pubkey) || crate::ranked_warm::text_matches_muted(&content, &muted) {
            continue;
        }
        if let Query::Tag(tag) = &query {
            if !has_hashtag(&content, tag) && !t_tags(&tags).iter().any(|t| t == tag) {
                continue;
            }
        }
        ids.push(r.get::<_, String>(0));
    }
    let offset = offset.max(0) as usize;
    let limit = limit.max(1) as usize;
    let more = ids.len() > offset + limit;
    Ok((ids.into_iter().skip(offset).take(limit).collect(), more))
}

/// One page of Search > Nostr as timeline statuses (viewer flags stamped).
pub async fn statuses(db: &Client, owner: i64, raw: &str, offset: i64, limit: i64) -> Result<(Vec<Value>, bool)> {
    let (ids, more) = note_ids(db, owner, raw, offset, limit).await?;
    let mut cards = super::share::cards(db, &ids).await;
    let mut out: Vec<Value> = ids.iter().filter_map(|id| cards.remove(id)).collect();
    super::moderation::filter_statuses(db, owner, &mut out).await;
    super::act::stamp_viewer_flags(db, owner, &mut out).await;
    Ok((out, more))
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn parses_tags_and_words() {
        assert_eq!(parse(" #Nostr "), Some(Query::Tag("nostr".into())));
        assert_eq!(parse("#a"), None);
        assert_eq!(parse("#no-dash"), None);
        assert_eq!(parse("Cozy  GAMES"), Some(Query::Words(vec!["cozy".into(), "games".into()])));
        assert_eq!(parse("a"), None);
        assert_eq!(parse("@someone@example.social"), None);
        assert_eq!(parse("https://example.com/x"), None);
        assert_eq!(parse("npub1abc"), None);
        assert_eq!(parse("one two three four five six seven"), Some(Query::Words(
            ["one", "two", "three", "four", "five", "six"].iter().map(|s| s.to_string()).collect()
        )));
    }

    #[test]
    fn like_escapes_wildcards() {
        assert_eq!(like_pattern("50%_off\\"), "%50\\%\\_off\\\\%");
    }

    #[test]
    fn hashtag_needs_whole_tag() {
        assert!(has_hashtag("hello #Nostr world", "nostr"));
        assert!(has_hashtag("end #nostr", "nostr"));
        assert!(has_hashtag("#nostrich then #nostr.", "nostr"));
        assert!(!has_hashtag("only #nostrich here", "nostr"));
        assert!(!has_hashtag("no tags", "nostr"));
        assert!(has_hashtag("Ünïcode #Café!", "café"));
    }

    #[test]
    fn reads_t_tags() {
        let tags = json!([["t", "Nostr"], ["p", "abc"], ["t"]]);
        assert_eq!(t_tags(&tags), vec!["nostr".to_string()]);
        assert!(t_tags(&json!(null)).is_empty());
    }
}
