//! Read-only indexed Search projection.
//!
//! This intentionally stops before Mastodon account/status serialization.  It
//! mirrors PHP's ap_search_fts query construction and ordering, applies the
//! viewer's current block/mute sets to event/mention candidates, and leaves
//! account resolution and remote URL redirects on PHP until those fixtures
//! have exact parity.

use anyhow::{Context, Result};
use serde::Serialize;
use tokio_postgres::Client;

use crate::hidden::HiddenSets;

#[derive(Debug, Serialize)]
pub struct SearchRow {
    pub source: String,
    pub source_pk: i64,
    pub object_id: Option<String>,
    pub created_at: String,
    pub rank: f64,
}

#[derive(Debug, Serialize)]
pub struct SearchResults {
    pub owner_id: i64,
    pub actor_key: String,
    pub query: String,
    pub query_type: String,
    pub normalized_query: Option<String>,
    pub rows: Vec<SearchRow>,
    pub privacy_filtered: bool,
    pub fallback: &'static str,
    pub source: &'static str,
    pub mutation_enabled: bool,
}

/// Exact PHP ap_search_fts_match_query behavior for text queries.
pub fn text_match_query(q: &str) -> Option<String> {
    let q = q.trim();
    if q.chars().count() < 2 {
        return None;
    }
    let stripped: String = q
        .chars()
        .map(|c| match c {
            '"' | '\'' | '*' | '(' | ')' | ':' | '^' => ' ',
            _ => c,
        })
        .collect();
    let mut tokens = Vec::new();
    for part in stripped.split_whitespace() {
        let token: String = part
            .chars()
            .filter(|c| c.is_alphanumeric() || *c == '_')
            .flat_map(char::to_lowercase)
            .collect();
        if token.chars().count() < 2 || token.is_empty() {
            continue;
        }
        tokens.push(format!("{token}*"));
        if tokens.len() >= 8 {
            break;
        }
    }
    (!tokens.is_empty()).then(|| tokens.join(" AND "))
}

/// Exact PHP tag query behavior (`htag_NAME OR NAME`).
pub fn tag_match_query(tag: &str) -> Option<String> {
    let token: String = tag
        .chars()
        .filter(|c| c.is_alphanumeric() || *c == '_')
        .flat_map(char::to_lowercase)
        .collect();
    (!token.is_empty()).then(|| format!("htag_{token} OR {token}"))
}

fn postgres_tsquery(match_query: &str) -> String {
    match_query
        .split_whitespace()
        .map(|part| part.strip_suffix('*').map(|p| format!("{p}:*")).unwrap_or_else(|| part.to_string()))
        .collect::<Vec<_>>()
        .join(" ")
        .replace(" AND ", " & ")
        .replace(" OR ", " | ")
}

async fn candidate_actor(db: &Client, source: &str, source_pk: i64) -> Result<(String, String)> {
    match source {
        "event" => {
            let row = db.query_opt(
                "SELECT COALESCE(actor_id,''), COALESCE(target_actor,'') FROM events WHERE id = $1",
                &[&source_pk],
            ).await?;
            Ok(row.map(|r| (r.get(0), r.get(1))).unwrap_or_default())
        }
        "mention" => {
            let row = db.query_opt(
                "SELECT COALESCE(actor_id,'') FROM mentions WHERE id = $1 AND deleted_at IS NULL",
                &[&source_pk],
            ).await?;
            Ok(row.map(|r| (r.get(0), String::new())).unwrap_or_default())
        }
        // Local status rows are hydrated by the PHP serializer and have no
        // independent remote actor to apply here.
        _ => Ok((String::new(), String::new())),
    }
}

pub async fn project(
    cfg: &crate::config::Config,
    owner_id: i64,
    query: &str,
    query_type: Option<&str>,
    tag: Option<&str>,
    limit: i64,
) -> Result<SearchResults> {
    if owner_id < 1 {
        anyhow::bail!("owner_id must be positive");
    }
    let db = crate::db::connect(&cfg.database_url).await.context("connect search")?;
    let owner = db.query_opt(
        "SELECT actor_key FROM ap_users WHERE id = $1 AND disabled_at IS NULL",
        &[&owner_id],
    ).await.context("load search owner")?;
    let Some(owner) = owner else { anyhow::bail!("search owner not found") };
    let actor_key: String = owner.get(0);
    let kind = query_type.unwrap_or("text").trim().to_ascii_lowercase();
    let limit = limit.clamp(1, 80);
    if !matches!(kind.as_str(), "text" | "hashtags" | "accounts" | "remote_url") {
        anyhow::bail!("unsupported search type")
    }
    if matches!(kind.as_str(), "accounts" | "remote_url") {
        return Ok(SearchResults {
            owner_id, actor_key, query: query.to_string(), query_type: kind,
            normalized_query: None, rows: Vec::new(), privacy_filtered: false,
            fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false,
        });
    }
    let match_query = if kind == "hashtags" {
        tag_match_query(tag.filter(|v| !v.trim().is_empty()).unwrap_or(query))
    } else {
        text_match_query(query)
    };
    let Some(match_query) = match_query else {
        return Ok(SearchResults { owner_id, actor_key, query: query.to_string(), query_type: kind,
            normalized_query: None, rows: Vec::new(), privacy_filtered: true,
            fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false });
    };
    let tsquery = postgres_tsquery(&match_query);
    let candidates = db.query(
        "SELECT source, source_pk, object_id, created_at,
                ts_rank_cd(body_tsv, to_tsquery('simple', $1))::double precision AS rank
         FROM ap_search_docs
         WHERE body_tsv @@ to_tsquery('simple', $1)
         ORDER BY rank DESC, created_at DESC
         LIMIT $2",
        &[&tsquery, &limit],
    ).await.context("query search FTS")?;
    let hidden = crate::hidden::load_hidden_sets(&db, owner_id).await.unwrap_or_else(|_| HiddenSets::default());
    let mut rows = Vec::with_capacity(candidates.len());
    for row in candidates {
        let source: String = row.get(0);
        let source_pk: i64 = row.get(1);
        let (actor, target) = candidate_actor(&db, &source, source_pk).await.unwrap_or_default();
        if hidden.is_hidden(&actor) || (source == "event" && !target.is_empty() && hidden.is_hidden(&target)) {
            continue;
        }
        rows.push(SearchRow {
            source, source_pk, object_id: row.get(2), created_at: row.get(3), rank: row.get(4),
        });
    }
    Ok(SearchResults { owner_id, actor_key, query: query.to_string(), query_type: kind,
        normalized_query: Some(match_query), rows, privacy_filtered: true,
        fallback: "php", source: "vaak-worker-shadow", mutation_enabled: false })
}

#[cfg(test)]
mod tests {
    use super::{tag_match_query, text_match_query};

    #[test]
    fn text_matches_php_prefix_normalization() {
        assert_eq!(text_match_query("Hello, VAAK! #Rust"), Some("hello* AND vaak* AND rust*".into()));
        assert_eq!(text_match_query("a"), None);
        assert_eq!(text_match_query("one two three four five six seven eight nine"),
            Some("one* AND two* AND three* AND four* AND five* AND six* AND seven* AND eight*".into()));
    }

    #[test]
    fn tag_matches_php_marker_query() {
        assert_eq!(tag_match_query("#Rust"), Some("htag_rust OR rust".into()));
        assert_eq!(tag_match_query("!!!"), None);
    }
}
