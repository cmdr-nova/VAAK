//! Native ap_normalize_from_bsky_post shared-card source projection. Selected
//! owner cache association remains a separate caller capability.
use anyhow::{Result,bail};use serde_json::{Value,json};
fn s<'a>(v:&'a Value,k:&str)->&'a str{v.get(k).and_then(Value::as_str).unwrap_or("")}
pub(crate) fn complete_plain(item:&Value)->Result<Value>{
 let post=&item["post"];let author=&post["author"];let record=&post["record"];
 let uri=s(post,"uri").trim();let did=s(author,"did").trim();let handle=s(author,"handle").trim();
 if super::mixed::bsky_at_uri(uri).is_none()||did.is_empty()||handle.is_empty()||!record.is_object(){bail!("PHP fallback: thin Bluesky shared author/PostView normalization")}
 if item.get("reason").is_some_and(|v|!v.is_null())||item.get("reply").is_some_and(|v|!v.is_null())||record.get("reply").is_some_and(|v|!v.is_null())||record.get("embed").is_some_and(|v|!v.is_null())||post.get("embed").is_some_and(|v|!v.is_null()){bail!("PHP fallback: Bluesky structured shared projection remains incomplete")}
 let text=super::card::php_trim(if s(record,"text").is_empty(){s(post,"text")}else{s(record,"text")});
 if text.is_empty(){bail!("PHP fallback: empty Bluesky source shared/legacy branch")}
 let display=super::card::php_trim(s(author,"displayName"));
 if display.is_empty()||s(author,"avatar").is_empty(){bail!("PHP fallback: Bluesky author needs current cache enrichment")}
 let key=uri.rsplit('/').next().unwrap_or("");let account_url=format!("https://bsky.app/profile/{}",super::actions::query(handle));
 let created=if s(record,"createdAt").is_empty(){s(post,"indexedAt")}else{s(record,"createdAt")};
 if created.is_empty(){bail!("PHP fallback: Bluesky source needs effect-clock created_at")}
 let mut status=json!({"id":uri,"url":format!("{account_url}/post/{}",super::actions::query(key)),"uri":uri,
 "content":super::actions::esc(text).replace('\n',"<br>\n"),"created_at":created,"visibility":"public","language":"en",
 "account":{"id":did,"url":account_url,"username":handle,"acct":handle,"display_name":display,"avatar":s(author,"avatar"),"avatar_static":s(author,"avatar"),"uri":format!("https://bsky.app/profile/{did}")},
 "reblogs_count":super::card::integer(post,"repostCount"),"favourites_count":super::card::integer(post,"likeCount"),"replies_count":super::card::integer(post,"replyCount"),
 "media_attachments":[],"reblog":null,"quote":null,"source":"bluesky","author_did":did,"trend_actors":[did],"bsky_post":post,"bsky_cid":s(post,"cid"),
 "sensitive":super::bsky::sensitive(post),"spoiler_text":"","in_reply_to_id":null,"in_reply_to_account_id":null});
 if let Some(value)=item.get("_vaak_feed_source"){status["vaak_feed_source"]=value.clone()}
 Ok(status)
}
