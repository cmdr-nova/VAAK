//! Owner-scoped explicit interactions consume discovery slots. Passive views do not.
use anyhow::{Context, Result};
use serde_json::Value;
use std::collections::HashSet;
use tokio_postgres::Client;

pub async fn algorithm_enabled(db: &Client, owner: i64) -> Result<bool> {
    let row = db.query_opt("SELECT COALESCE(p.algorithm_enabled,1) FROM ap_users u LEFT JOIN actor_profile p ON p.actor_key=u.actor_key WHERE u.id=$1", &[&owner]).await?;
    Ok(row.map(|r| r.get::<_, i32>(0) != 0).unwrap_or(true))
}

pub const INTERACTED_SQL: &str = r#"
WITH candidates AS (SELECT unnest($2::text[]) AS object_id),
viewer AS (SELECT id,actor_id FROM ap_users WHERE id=$1),
own_notes AS MATERIALIZED (
 SELECT o.in_reply_to, NULLIF(o.raw_create_json,'')::jsonb AS doc FROM outbox_notes o, viewer v
 WHERE substr(o.id,1,length(rtrim(v.actor_id,'/') || '/notes/'))=rtrim(v.actor_id,'/') || '/notes/'
),
signals AS MATERIALIZED (
 SELECT s.target_key,NULLIF(s.metadata_json,'')::jsonb AS doc FROM ap_user_signals s, viewer v
 WHERE s.owner_user_id=v.id AND s.weight>0
   AND s.signal_type IN ('like','favourite','boost','reblog','reply','quote','bookmark')
),
interactions AS MATERIALIZED (
 SELECT rtrim(f.object_id,'/') AS object_id FROM masto_favourites f, viewer v WHERE f.owner_user_id=v.id
 UNION ALL SELECT rtrim(b.object_id,'/') FROM masto_bookmarks b, viewer v WHERE b.owner_user_id=v.id
 UNION ALL SELECT rtrim(b.object_id,'/') FROM masto_reblogs b, viewer v WHERE b.owner_user_id=v.id
 UNION ALL SELECT rtrim(n.in_reply_to,'/') FROM own_notes n
 UNION ALL SELECT rtrim(COALESCE(n.doc #>> '{object,quote}',n.doc #>> '{object,quoteUrl}',n.doc #>> '{object,_misskey_quote}',''),'/') FROM own_notes n
 UNION ALL SELECT rtrim(q.quoted_note_id,'/') FROM quote_authorizations q, viewer v WHERE rtrim(q.requester_actor,'/')=rtrim(v.actor_id,'/')
 UNION ALL SELECT rtrim(s.target_key,'/') FROM signals s
 UNION ALL SELECT rtrim(s.doc ->> 'object_id','/') FROM signals s
 UNION ALL SELECT rtrim(s.doc ->> 'uri','/') FROM signals s
)
SELECT DISTINCT c.object_id FROM candidates c JOIN interactions i ON i.object_id=c.object_id
"#;

pub async fn interacted_objects(db: &Client, owner: i64, objects: &[String]) -> Result<HashSet<String>> {
    if owner < 1 || objects.is_empty() { return Ok(HashSet::new()); }
    let objects: Vec<String> = objects.iter().map(|s| s.trim_end_matches('/').to_string()).collect();
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
    fn interaction_only_removes_discovery_copy() {
        let mut statuses=vec![json!({"uri":"https://test/post/1/","vaak_home_source":"recommendation"}), json!({"uri":"https://test/post/1"}), json!({"uri":"https://test/post/2","vaak_home_source":"recommendation"})];
        retain_unconsumed(&mut statuses, &HashSet::from(["https://test/post/1".to_string()]));
        assert_eq!(statuses.len(),2);
        assert_eq!(statuses[0]["uri"],"https://test/post/1");
        assert_eq!(statuses[1]["uri"],"https://test/post/2");
    }
}
