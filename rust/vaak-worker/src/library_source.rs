//! Final source hydration happens before admission. Unsupported IO returns an
//! error to the canonical PHP caller, never a smaller native card.
use anyhow::{bail, Context, Result};
use serde_json::Value;
use tokio_postgres::Client;

fn s<'a>(v: &'a Value, key: &str) -> &'a str { v.get(key).and_then(Value::as_str).unwrap_or("") }

/// PHP's enrichment decision; preserving the saved interaction ID is essential
/// when a cached Announce resolves to a different inner status.
pub(crate) fn thin(status: &Value) -> bool {
    if status.get("vaak_library_stub").and_then(Value::as_bool) == Some(true) { return true; }
    let content = crate::home_hydrate_ranked::html_entity_decode(&regex::Regex::new(r"<[^>]*>").unwrap().replace_all(s(status, "content"), ""));
    let content = content.trim();
    let media = status.get("media_attachments").and_then(Value::as_array).is_some_and(|m| !m.is_empty());
    if media { return false; }
    let acct = s(&status["account"], "acct").trim();
    if content.is_empty() || acct.is_empty() || acct == "?" { return true; }
    let uri = s(status, "uri").trim_end_matches('/');
    content == uri || content == format!("{uri}/") || (content.starts_with("http") && !content.chars().any(char::is_whitespace))
}

pub(crate) fn preserve_saved(source: &Value, mut fresh: Value) -> Value {
    if fresh.get("reblog").is_some_and(Value::is_object) { fresh = fresh["reblog"].clone(); }
    if !s(source, "id").is_empty() && s(source, "id") != "0" { fresh["id"] = source["id"].clone(); }
    for flag in ["favourited", "bookmarked"] { if source.get(flag).and_then(Value::as_bool) == Some(true) { fresh[flag] = Value::Bool(true); } }
    if let Some(object) = fresh.as_object_mut() { object.remove("vaak_library_stub"); }
    fresh
}

/// Sources are server-owned normalized rows. The PHP bridge currently supplies
/// complete AS2/legacy PostView normalization; native PostView refresh is read
/// from durable cache. Signed network fetch and OG preview recovery remain PHP.
pub(crate) async fn hydrate(db: &Client,statuses:Vec<Value>)->Result<Vec<Value>>{hydrate_depth(db,statuses,0).await}
async fn hydrate_depth(db: &Client, mut statuses: Vec<Value>,depth:usize) -> Result<Vec<Value>> {
    if depth>16{bail!("PHP fallback: nested source depth exceeds canonical limit");}
    for status in &mut statuses{
        if status.get("post").is_some_and(Value::is_object){
            let original=status.clone();let post=&original["post"];
            let uri=s(post,"uri");let did=s(&post["author"],"did");let handle=s(&post["author"],"handle");
            if super::mixed::bsky_at_uri(uri).is_none()||!did.starts_with("did:")||handle.is_empty()||!post.get("record").is_some_and(Value::is_object){bail!("PHP fallback: legacy FeedView needs canonical current author/PostView normalization");}
            // Admission metadata only: legacy rendering consumes the untouched
            // full FeedView item, not this scaffold as a normalized card.
            use sha2::{Digest,Sha256};
            status["id"]=serde_json::json!(format!("bsky:{}",&hex::encode(Sha256::digest(uri.as_bytes()))[..32]));
            status["uri"]=serde_json::json!(uri);status["source"]=serde_json::json!("bluesky");status["visibility"]=serde_json::json!("public");
            status["content"]=serde_json::json!(super::actions::esc(s(&post["record"],"text")));
            status["account"]=serde_json::json!({"uri":format!("https://bsky.app/profile/{did}"),"url":format!("https://bsky.app/profile/{handle}"),"acct":handle,"avatar":s(&post["author"],"avatar")});
            status["bsky_post"]=original;
        }
    }
    let targets = statuses.iter().filter_map(|st| super::mixed::bsky_at_uri(s(st, "uri"))).collect::<Vec<_>>();
    let mut bsky=std::collections::HashMap::<String,String>::new();
    if !targets.is_empty() {for row in db.query("SELECT bsky_uri,raw_json FROM bsky_posts WHERE bsky_uri=ANY($1)",&[&targets]).await?{bsky.insert(row.get(0),row.get(1));}}
    for status in &mut statuses {
        if let Some(uri) = super::mixed::bsky_at_uri(s(status, "uri")) {
            let Some(raw) = bsky.get(&uri) else { bail!("PHP fallback: Bluesky source needs AppView hydration (shared fetch budget 3)"); };
            let raw:Value=serde_json::from_str(raw).context("PHP fallback: malformed Bluesky cache source")?;
            let post=raw.get("post").unwrap_or(&raw);
            if !post.get("record").is_some_and(Value::is_object) || !post.get("author").is_some_and(Value::is_object) {
                bail!("PHP fallback: legacy Bluesky cache needs canonical PostView normalization");
            }
            if !status.get("post").is_some_and(Value::is_object)&&thin(status) {bail!("PHP fallback: native PostView-to-Library normalization parity not yet established");}
            let prior=status.get("bsky_post");
            if !prior.is_some_and(|source|same_source_content(source,&raw)) {bail!("PHP fallback: changed or unproven FeedView/PostView source needs full current Library normalization (reason/author/text/CID/facets/labels/media/reply/counts)");}
            if !status.get("post").is_some_and(Value::is_object) && super::bsky::sensitive(post) && status.get("sensitive").and_then(Value::as_bool)!=Some(true) {
                bail!("PHP fallback: current Bluesky labels require canonical sensitive/CW normalization");
            }
            // Do not replace the complete canonical PHP normalized status with
            // Home's lean projection. Policy retains the full FeedView wrapper;
            // display readers receive the separate inner PostView, including
            // native CID/facets/labels/reply/embeds and their provenance.
            status["bsky_post_view"]=post.clone();
            status["bsky_post"]=raw;
        } else if !s(status, "id").starts_with("rss:") && s(status, "source") != "rss" && thin(status)
            && !(s(status,"content").is_empty() && status.get("reblog").filter(|value|value.is_object()).is_some_and(|inner|!thin(inner)) && !matches!(s(&status["account"],"acct"),""|"?")) {
            bail!("PHP fallback: thin AS2 source needs canonical cached/signed hydration (shared fetch budget 3)");
        }
    }
    for status in &mut statuses {
        let uri=s(status,"uri").trim_end_matches('/').to_owned();
        if uri.starts_with("https://mkultra.monster/users/") && uri.contains("/notes/") {
            if !db.query_one("SELECT EXISTS(SELECT 1 FROM masto_statuses WHERE rtrim(note_id,'/')=$1)",&[&uri]).await?.get::<_,bool>(0){
                bail!("PHP fallback: local quote/source row missing current normalization");
            }
            // Complete canonical Library snapshots retain their body, CW and
            // presentation visibility. Current own/Ask facts are applied later
            // to the actual displayed source and rechecked before painting.
        }
    }
    for status in &mut statuses {
        for key in ["reblog","quoted_status"] {
            let child=if key=="reblog"{status.get(key)}else{status.get("quote").and_then(|q|q.get(key))}.filter(|v|v.is_object()).cloned();
            if let Some(child)=child {
                let mut fresh=Box::pin(hydrate_depth(db,vec![child],depth+1)).await?;
                let fresh=fresh.pop().context("PHP fallback: nested canonical source missing")?;
                if key=="reblog"{status[key]=fresh;}else{status["quote"][key]=fresh;}
            }
        }
    }
    for status in &statuses { require_source_authority(status)?; }
    Ok(statuses)
}

fn same_source_content(a:&Value,b:&Value)->bool{
    fn scrub(value:&Value)->Value{
        let mut value=value.clone();let post=if value.get("post").is_some_and(Value::is_object){value.get_mut("post").unwrap()}else{&mut value};
        if let Some(viewer)=post.get_mut("viewer").and_then(Value::as_object_mut){viewer.remove("like");viewer.remove("repost");}
        value
    }
    scrub(a)==scrub(b)
}

fn require_source_authority(status: &Value) -> Result<()> {
    if status.get("vaak_quote_preview").is_some_and(Value::is_object) {
        bail!("PHP fallback: compact quote preview needs retained AS2/PostView/RSS authority and cached recovery (link budget 3)");
    }
    if let Some(quote)=status.get("quote").filter(|q|q.is_object()) {
        if s(quote,"state") == "accepted" && !quote.get("quoted_status").is_some_and(Value::is_object) {
            bail!("PHP fallback: accepted quote needs canonical cached/signed source recovery (fetch budget 3)");
        }
        if let Some(child)=quote.get("quoted_status").filter(|v|v.is_object()) { require_source_authority(child)?; }
        if s(quote,"state")=="pending"&&!s(status,"quote_url").is_empty()&&!quote.get("quoted_status").is_some_and(Value::is_object){bail!("PHP fallback: pending quote URL requires canonical current AS2 cache normalization before final admission");}
    }
    if let Some(child)=status.get("reblog").filter(|v|v.is_object()) { require_source_authority(child)?; }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    #[test] fn enrichment_keeps_saved_undo_target() {
        let fresh = preserve_saved(&serde_json::json!({"id":"77", "bookmarked":true}), serde_json::json!({"id":"99", "reblog":{"id":"88", "content":"body"}}));
        assert_eq!(fresh["id"], "77"); assert_eq!(fresh["bookmarked"], true); assert!(fresh.get("reblog").is_none());
    }
    #[test] fn url_only_and_unknown_authors_require_php_enrichment() {
        for content in ["", "https://remote.test/n/1"] { assert!(thin(&serde_json::json!({"uri":"https://remote.test/n/1", "content":content,"account":{"acct":"alice"}}))); }
        assert!(!thin(&serde_json::json!({"content":"body", "account":{"acct":"alice"}})));
    }
    #[test] fn current_policy_source_is_not_flattened_or_weakened_for_owner_actions(){
        let a=serde_json::json!({"post":{"record":{"text":"same"},"viewer":{"like":"at://did:plc:old/app.bsky.feed.like/1"}},"reason":{"by":{"did":"did:plc:hidden"}}});
        let mut b=a.clone();b["post"]["viewer"]["like"]=serde_json::json!("at://did:plc:new/app.bsky.feed.like/2");assert!(same_source_content(&a,&b));
        b["post"]["labels"]=serde_json::json!([{"val":"porn"}]);assert!(!same_source_content(&a,&b));
        assert!(!same_source_content(&a,&a["post"]));
        let mut b=a.clone();b["post"]["viewer"]["blockedBy"]=Value::Bool(true);assert!(!same_source_content(&a,&b));
    }
}
