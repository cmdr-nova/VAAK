//! Separate remote AP source session. No local profile-key relaxation and no
//! source/card DTO may manufacture a viewer or remote source grant.
use anyhow::{bail,Context,Result};
use serde_json::Value;
use tokio_postgres::Client;
pub(crate) struct AuthenticatedRemoteProfileSession { owner:i64,requested:String,original:String,own_actor:String,owner_snapshot:Value,csrf:String }
pub(crate) struct GuestRemoteProfileSession { requested:String,original:String }
fn resolved_actor(raw:String)->Result<String>{
 let actor=crate::library::card::php_trim(&raw).trim_end_matches('/').to_owned();
 if actor.is_empty()||actor.len()>8192||!(actor.starts_with("https://")||actor.starts_with("http://")){bail!("PHP fallback: unresolved remote profile actor")}
 if actor.starts_with("https://mkultra.monster/users/"){bail!("remote source session cannot replace local profile session")}
 Ok(actor)
}
impl AuthenticatedRemoteProfileSession {
 pub(crate) async fn from_php_remote_profile(db:&Client,owner:i64,original:String,resolved:String)->Result<Self>{
  if owner<1||original.len()>8192{bail!("invalid authenticated remote profile caller")}
  let row=db.query_opt("SELECT to_jsonb(u),actor_id FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&owner]).await?.context("inactive remote profile viewer")?;
  let session=Self{owner,requested:resolved_actor(resolved)?,original,own_actor:row.get::<_,String>(1).trim_end_matches('/').to_owned(),owner_snapshot:row.get(0),csrf:String::new()};
  crate::profile_materialize::RemoteApSourceSession::require_current(&session,db).await?;Ok(session)
 }
 pub(crate) fn original_request(&self)->&str{&self.original}
 pub(crate) fn with_php_csrf(mut self,csrf:String)->Result<Self>{if csrf.is_empty()||csrf.len()>4096{bail!("invalid trusted remote header CSRF")};self.csrf=csrf;Ok(self)}
 pub(crate) fn csrf(&self)->&str{&self.csrf}
}
impl GuestRemoteProfileSession {
 pub(crate) async fn from_php_guest_remote_profile(db:&Client,original:String,resolved:String)->Result<Self>{
  if original.len()>8192{bail!("invalid established guest remote request")}
  let session=Self{requested:resolved_actor(resolved)?,original};crate::profile_materialize::RemoteApSourceSession::require_current(&session,db).await?;Ok(session)
 }
 pub(crate) fn original_request(&self)->&str{&self.original}
}
impl crate::profile_materialize::RemoteApSourceSession for AuthenticatedRemoteProfileSession {
 fn owner_id(&self)->i64{self.owner}
 fn requested_actor(&self)->&str{&self.requested} fn own_actor(&self)->&str{&self.own_actor}
 fn require_current<'a>(&'a self,db:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async move{
  let row=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&self.owner]).await?.map(|row|row.get::<_,Value>(0));
  if row.as_ref()!=Some(&self.owner_snapshot){bail!("remote profile current viewer changed")}
  if crate::profile_context::root_content_blocked_for(db,self.owner,&self.requested).await?{bail!("remote profile root currently blocked")};Ok(())
 })}
}
impl crate::profile_materialize::RemoteApSourceSession for GuestRemoteProfileSession {
 fn owner_id(&self)->i64{0}
 fn requested_actor(&self)->&str{&self.requested} fn own_actor(&self)->&str{""}
 fn require_current<'a>(&'a self,db:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async move{
  if crate::profile_context::root_content_blocked_for(db,0,&self.requested).await?{bail!("guest remote profile root currently blocked")};Ok(())
 })}
}
impl crate::profile_materialize::RemoteApHeaderSession for AuthenticatedRemoteProfileSession {
 fn csrf(&self)->&str{&self.csrf}
}
impl crate::profile_materialize::RemoteApHeaderSession for GuestRemoteProfileSession {
 fn csrf(&self)->&str{""}
}
