//! Private PHP-request provenance for profile protocol presentation. Neither
//! a PostView nor a connected account can enable the server feature.
use anyhow::{bail,Result};
use serde::Deserialize;
use serde_json::Value;
#[derive(Clone,Deserialize,Default)]
#[serde(deny_unknown_fields)]
pub(crate) struct PhpProfileBsky {
 pub enabled:bool,
 pub did:String,
 pub bookmarks:Vec<PhpBookmark>,
}
#[derive(Clone,Deserialize)]
#[serde(deny_unknown_fields)]
pub(crate) struct PhpBookmark {uri:String,bookmarked:bool}
#[derive(Clone)]
pub(crate) struct ProfileBskyPresentation {owner:i64,enabled:bool,did:String,bookmarks:std::collections::HashMap<String,bool>}
impl ProfileBskyPresentation {
 pub(crate) async fn bind(db:&tokio_postgres::Client,owner:i64,input:PhpProfileBsky)->Result<Self>{
  if owner<1||input.did.len()>2048||input.bookmarks.len()>256{bail!("invalid profile Bluesky provenance")}
  let did=current_did(db,owner).await?;
  if did!=input.did{bail!("profile Bluesky connection differs from PHP request")}
  let mut bookmarks=std::collections::HashMap::new();
  for bookmark in input.bookmarks {
   if did.is_empty()||bookmark.uri.len()>2048||!regex::Regex::new(r"^at://[^/]+/app\.bsky\.feed\.post/[^/?#]+$").unwrap().is_match(&bookmark.uri)||bookmarks.insert(bookmark.uri,bookmark.bookmarked).is_some(){bail!("invalid owner-bound profile bookmark provenance")}
  }
  Ok(Self{owner,enabled:input.enabled,did,bookmarks})
 }
 pub(crate) fn enabled(&self)->bool{self.enabled}
 pub(crate) fn owner(&self)->i64{self.owner}
 pub(crate) fn bookmark(&self,status:&Value)->Option<bool>{
  let post=if status["post"].is_object(){&status["post"]}else{status.get("bsky_post_view").or_else(||status.get("bsky_post").map(|post|post.get("post").unwrap_or(post)))?};
  self.bookmarks.get(post.get("uri")?.as_str()?).copied()
 }
 pub(crate) async fn require_current(&self,db:&tokio_postgres::Client)->Result<()>{if current_did(db,self.owner).await?!=self.did{bail!("profile Bluesky connection changed during rendering")}Ok(())}
}
async fn current_did(db:&tokio_postgres::Client,owner:i64)->Result<String>{
 let row=db.query_one("SELECT EXISTS(SELECT 1 FROM ap_users WHERE id::bigint=$1 AND disabled_at IS NULL),(SELECT did FROM bsky_sessions WHERE owner_user_id::bigint=$1 LIMIT 1)",&[&owner]).await?;
 if !row.get::<_,bool>(0){bail!("inactive profile protocol owner")}
 Ok(crate::library::card::php_trim(&row.get::<_,Option<String>>(1).unwrap_or_default()).into())
}
#[cfg(test)]
mod tests {
 use super::*;
 #[tokio::test]
 #[ignore="actual PHP owner/session provenance on disposable PostgreSQL"]
 async fn actual_profile_bsky_owner_provenance()->Result<()>{
  let db=crate::db::connect(&std::env::var("LIBRARY_FIXTURE_DB")?).await?;
  let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-bsky-owner-current-reference.json")?)?;
  let captures=reference["captures"].as_array().unwrap();assert_eq!(captures.len(),16);
  for width in ["INTEGER","BIGINT"]{
   let schema=format!("profile_bsky_provenance_{}_{}",std::process::id(),width.to_ascii_lowercase());
   db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path TO {schema};CREATE TABLE ap_users(id {width},disabled_at TEXT);INSERT INTO ap_users VALUES(2,NULL);CREATE TABLE bsky_sessions(owner_user_id {width},did TEXT);")).await?;
   let result:Result<()>=async{
    for capture in captures{
     db.batch_execute("DELETE FROM bsky_sessions;UPDATE ap_users SET disabled_at=NULL").await?;
     if capture["connected"]==true{db.execute("INSERT INTO bsky_sessions VALUES(2,'did:plc:profile-owner')",&[]).await?;}
     let input:PhpProfileBsky=serde_json::from_value(capture["provenance"].clone())?;
     let context=ProfileBskyPresentation::bind(&db,2,input.clone()).await?;
     let other=crate::direct_context::DirectSession::from_php_session(1,"csrf".into(),crate::direct_context::DirectRoute::RemoteProfile,"remote_profile".into(),false,true,false,false,"https://mkultra.monster/users/fixture".into())?;
     assert!(other.with_profile_bsky(context.clone()).is_err());
     assert_eq!(context.enabled(),capture["enabled"].as_bool().unwrap());
     let expected=input.bookmarks.first().map(|bookmark|bookmark.bookmarked);
     assert_eq!(context.bookmark(&capture["source"]),expected);
     context.require_current(&db).await?;
     let mut forged=input.clone();forged.did="did:plc:foreign".into();assert!(ProfileBskyPresentation::bind(&db,2,forged).await.is_err());
     db.batch_execute("DELETE FROM bsky_sessions;INSERT INTO bsky_sessions VALUES(2,'did:plc:replacement')").await?;
     assert!(context.require_current(&db).await.is_err());
     db.batch_execute("UPDATE ap_users SET disabled_at='withdrawn'").await?;
     assert!(ProfileBskyPresentation::bind(&db,2,input).await.is_err());
    }Ok(())
   }.await;
   db.batch_execute(&format!("SET search_path TO public;DROP SCHEMA {schema} CASCADE")).await?;result?;
  }Ok(())
 }
}
