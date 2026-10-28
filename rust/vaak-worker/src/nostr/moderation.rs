//! Who a VAAK user doesn't see on Nostr: their mutes (`nostr_mutes`, from
//! their NIP-51 list or VAAK-only hides) and keys an admin blocked for
//! everyone (`nostr_blocks`). Home leaves those keys out when it picks
//! notes, and every hydrated timeline drops cards by them, so boosts by
//! other VAAK users and quotes are covered too. Nothing applies until the
//! moderation migration has run.

use std::collections::HashSet;

use serde_json::Value;
use tokio_postgres::Client;

/// Keys hidden from `owner`: their mutes and hides, plus admin blocks.
pub async fn hidden_keys(db: &Client, owner: i64) -> HashSet<String> {
    if owner < 1 || !super::sync::moderation_ready(db).await {
        return HashSet::new();
    }
    let rows = db
        .query(
            "SELECT pubkey FROM nostr_mutes WHERE owner_user_id = $1 AND (on_nostr OR hidden_vaak)
             UNION SELECT pubkey FROM nostr_blocks",
            &[&owner],
        )
        .await;
    match rows {
        Ok(rows) => rows.iter().map(|r| r.get(0)).collect(),
        Err(e) => {
            tracing::warn!(owner, error = %format!("{e:#}"), "nostr hidden keys skipped");
            HashSet::new()
        }
    }
}

fn quoted_status(v: &Value) -> Option<&Value> {
    v.get("quote").and_then(|q| q.get("quoted_status"))
}

/// Every Nostr key a card shows: its author, the reposted or boosted
/// note's author, and a quoted note's author.
fn card_keys(st: &Value) -> Vec<&str> {
    let mut cards: Vec<&Value> = vec![st];
    if let Some(inner) = st.get("reblog").filter(|v| v.is_object()) {
        cards.push(inner);
        cards.extend(quoted_status(inner));
    }
    cards.extend(quoted_status(st));
    cards
        .into_iter()
        .filter_map(|v| v.get("vaak_nostr_pubkey").and_then(Value::as_str))
        .collect()
}

pub fn card_hidden(st: &Value, hidden: &HashSet<String>) -> bool {
    !hidden.is_empty() && card_keys(st).iter().any(|pk| hidden.contains(*pk))
}

/// Drop cards by keys `owner` muted or hid, or an admin blocked.
pub async fn filter_statuses(db: &Client, owner: i64, statuses: &mut Vec<Value>) {
    if !statuses.iter().any(super::render::is_nostr_status) && !statuses.iter().any(|s| s.get("reblog").is_some_and(super::render::is_nostr_status)) {
        return;
    }
    let hidden = hidden_keys(db, owner).await;
    statuses.retain(|st| !card_hidden(st, &hidden));
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[test]
    fn hides_authors_reposted_and_quoted_keys() {
        let hidden: HashSet<String> = ["bad".to_string()].into();
        assert!(card_hidden(&json!({"vaak_nostr_pubkey": "bad"}), &hidden));
        assert!(card_hidden(&json!({"vaak_nostr_pubkey": "ok", "reblog": {"vaak_nostr_pubkey": "bad"}}), &hidden));
        assert!(card_hidden(&json!({"id": "x", "reblog": {"vaak_nostr_pubkey": "ok", "quote": {"quoted_status": {"vaak_nostr_pubkey": "bad"}}}}), &hidden));
        assert!(card_hidden(&json!({"vaak_nostr_pubkey": "ok", "quote": {"quoted_status": {"vaak_nostr_pubkey": "bad"}}}), &hidden));
        assert!(!card_hidden(&json!({"vaak_nostr_pubkey": "ok", "reblog": null}), &hidden));
        assert!(!card_hidden(&json!({"id": "fedi"}), &hidden));
        assert!(!card_hidden(&json!({"vaak_nostr_pubkey": "bad"}), &HashSet::new()));
    }
}
