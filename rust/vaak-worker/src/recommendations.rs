//! Owner-scoped explicit interactions consume discovery slots. Passive views do not.
use anyhow::{Context, Result};
use serde_json::Value;
use std::collections::HashSet;
use tokio_postgres::Client;

pub async fn algorithm_enabled(db: &Client, owner: i64) -> Result<bool> {
    let row = db.query_opt("SELECT COALESCE(p.algorithm_enabled,1)::integer FROM ap_users u LEFT JOIN actor_profile p ON p.actor_key=u.actor_key WHERE u.id=$1::bigint", &[&owner]).await?;
    Ok(row.map(|r| r.get::<_, i32>(0) != 0).unwrap_or(true))
}

/// Which of these objects the owner has explicitly interacted with.
///
/// The previous statement materialized every note the owner had ever published
/// (and every positive signal) before joining the candidate list. With
/// work_mem at 16MB that hash spilled hundreds of gigabytes of temp files.
/// Each branch below is an index probe for one candidate: owner (or the
/// actor's `/notes/` prefix) is applied inside the lookup, and LIMIT 1 stops
/// at the first hit. Quote JSON is parsed only when the document contains
/// "quote". `^@` is starts-with, so an underscore in the actor name stays literal.
/// Same rows as the old join: a candidate is returned when any branch matches
/// after trailing slashes are stripped. The full-set cache that rebuilt this
/// for every owner is gone; it was the statement that spilled.
pub const INTERACTED_SQL: &str = r#"
WITH candidates AS (SELECT rtrim(object_id,'/') AS object_id FROM unnest($2::text[]) AS t(object_id)),
viewer AS (SELECT id, rtrim(actor_id,'/') AS actor FROM ap_users WHERE id=$1::bigint)
SELECT DISTINCT c.object_id FROM candidates c
WHERE EXISTS (SELECT 1 FROM masto_favourites f, viewer v WHERE f.owner_user_id=v.id AND rtrim(f.object_id,'/')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM masto_bookmarks b, viewer v WHERE b.owner_user_id=v.id AND rtrim(b.object_id,'/')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM masto_reblogs b, viewer v WHERE b.owner_user_id=v.id AND rtrim(b.object_id,'/')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM outbox_notes o, viewer v WHERE o.id ^@ (v.actor || '/notes/') AND rtrim(o.in_reply_to,'/')=c.object_id LIMIT 1)
OR (c.object_id<>'' AND EXISTS (SELECT 1 FROM outbox_notes o, viewer v WHERE o.id ^@ (v.actor || '/notes/') AND strpos(o.raw_create_json,'quote')>0 AND vaak_reco_quote_key(o.raw_create_json)<>'' AND vaak_reco_quote_key(o.raw_create_json)=c.object_id LIMIT 1))
OR (c.object_id='' AND EXISTS (SELECT 1 FROM outbox_notes o, viewer v WHERE o.id ^@ (v.actor || '/notes/') AND vaak_reco_quote_key(o.raw_create_json)='' LIMIT 1))
OR EXISTS (SELECT 1 FROM quote_authorizations q, viewer v WHERE rtrim(q.requester_actor,'/')=v.actor AND rtrim(q.quoted_note_id,'/')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM ap_user_signals s, viewer v WHERE s.owner_user_id=v.id AND s.weight>0 AND s.signal_type IN ('like','favourite','boost','reblog','reply','quote','bookmark') AND rtrim(s.target_key,'/')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM ap_user_signals s, viewer v WHERE s.owner_user_id=v.id AND s.weight>0 AND s.signal_type IN ('like','favourite','boost','reblog','reply','quote','bookmark') AND vaak_reco_signal_key(s.metadata_json,'object_id')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM ap_user_signals s, viewer v WHERE s.owner_user_id=v.id AND s.weight>0 AND s.signal_type IN ('like','favourite','boost','reblog','reply','quote','bookmark') AND vaak_reco_signal_key(s.metadata_json,'uri')=c.object_id LIMIT 1)
"#;

fn canonical_object_id(object: &str) -> String {
    object.trim_end_matches('/').to_string()
}

pub async fn interacted_objects(db: &Client, owner: i64, objects: &[String]) -> Result<HashSet<String>> {
    if owner < 1 || objects.is_empty() { return Ok(HashSet::new()); }
    let objects: Vec<String> = objects.iter().map(|s| canonical_object_id(s)).collect();
    Ok(db.query(INTERACTED_SQL, &[&owner, &objects]).await.context("select consumed recommendations")?.into_iter().map(|r| r.get(0)).collect())
}

pub async fn filter_statuses(db: &Client, owner: i64, statuses: &mut Vec<Value>) -> Result<()> {
    let candidates: Vec<String> = statuses.iter().filter(|s| recommended(s)).filter_map(|s| s.get("uri").and_then(Value::as_str).map(str::to_string)).collect();
    let consumed = interacted_objects(db, owner, &candidates).await?;
    retain_unconsumed(statuses, &consumed);
    Ok(())
}
fn recommended(status: &Value) -> bool { status["vaak_home_source"] == "recommendation" }
fn retain_unconsumed(statuses: &mut Vec<Value>, consumed: &HashSet<String>) {
    statuses.retain(|s| !recommended(s) || !s.get("uri").and_then(Value::as_str).is_some_and(|uri| consumed.contains(uri.trim_end_matches('/'))));
}
#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;
    #[test]
    fn interacted_sql_probes_each_source_without_materializing_notes() {
        let sql = INTERACTED_SQL;
        assert!(!sql.contains("MATERIALIZED"));
        assert!(!sql.contains("JOIN interactions"));
        assert!(sql.contains("EXISTS"));
        assert!(sql.contains("LIMIT 1"));
        assert!(sql.contains("masto_favourites") && sql.contains("masto_bookmarks") && sql.contains("masto_reblogs"));
        assert!(sql.contains("in_reply_to"));
        assert!(sql.contains("vaak_reco_quote_key(o.raw_create_json)"));
        assert!(sql.contains("vaak_reco_signal_key(s.metadata_json,'object_id')"));
        assert!(sql.contains("vaak_reco_signal_key(s.metadata_json,'uri')"));
        assert!(sql.contains("quote_authorizations"));
        assert!(sql.contains("target_key"));
        assert!(sql.contains("o.id ^@ (v.actor || '/notes/')"));
        assert!(sql.contains("$1::bigint") && sql.contains("$2::text[]"));
        assert_eq!(sql.matches("signal_type IN ('like','favourite','boost','reblog','reply','quote','bookmark')").count(), 3);
    }
    #[test]
    fn canonical_object_id_strips_every_trailing_slash() {
        assert_eq!(canonical_object_id("https://test/post/1/"), "https://test/post/1");
        assert_eq!(canonical_object_id("https://test/post/1///"), "https://test/post/1");
        assert_eq!(canonical_object_id("https://test/post/2"), "https://test/post/2");
    }
    #[test]
    fn interaction_only_removes_discovery_copy() {
        let mut statuses=vec![json!({"uri":"https://test/post/1/","vaak_home_source":"recommendation"}), json!({"uri":"https://test/post/1"}), json!({"uri":"https://test/post/2","vaak_home_source":"recommendation"})];
        retain_unconsumed(&mut statuses, &HashSet::from(["https://test/post/1".to_string()]));
        assert_eq!(statuses.len(),2);
        assert_eq!(statuses[0]["uri"],"https://test/post/1");
        assert_eq!(statuses[1]["uri"],"https://test/post/2");
    }
}
