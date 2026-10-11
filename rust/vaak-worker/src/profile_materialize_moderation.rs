//! Current event normalizer actor policy. Its null result precedes account
//! projection and does not grant any source tree or discard its source ordinal.
use super::*;
fn domain_matches(host:&str,value:&str)->bool{let value=value.to_ascii_lowercase();!value.is_empty()&&(host==value||host.ends_with(&format!(".{value}")))}
fn actor_matches(actor:&str,value:&str,insensitive:bool)->bool{
 let target=crate::hidden::actor_aliases(actor);crate::hidden::actor_aliases(value).iter().any(|value|target.iter().any(|actor|if insensitive{actor.eq_ignore_ascii_case(value)}else{actor==value}))
}
fn actor_host(actor:&str)->String{reqwest::Url::parse(actor).ok().and_then(|u|u.host_str().map(|s|s.to_ascii_lowercase())).unwrap_or_default()}
impl Projector<'_>{
 pub(super) async fn event_hidden(&mut self,row:&Value)->Result<bool>{
  // Only the actual current row can carry PHP's internal bypass field.
  if truth(&row["_allow_hidden_actor"]){return Ok(false)}
  let actor=s(row,"actor_id").trim_end_matches('/');let source_host=s(row,"host");let parsed_host=actor_host(actor);let raw_host=if source_host.is_empty(){parsed_host.as_str()}else{source_host};let global_host=crate::library::card::php_trim(raw_host).to_ascii_lowercase();let personal_host=raw_host.to_ascii_lowercase();
  let blocks=self.rows("SELECT to_jsonb(b) FROM ap_blocks b ORDER BY created_at DESC",json!({})).await?;
  for block in &blocks{
   let kind=block.get("kind").and_then(Value::as_str).unwrap_or("block");let value=s(block,"value");
   if kind=="mute"{
    if (s(block,"scope")=="actor"&&actor_matches(actor,value,true))||(s(block,"scope")=="domain"&&domain_matches(&global_host,value)){return Ok(true)}
   }else if (s(block,"scope")=="actor"&&actor_matches(actor,value,false))||(s(block,"scope")=="domain"&&(domain_matches(&parsed_host,value)||(!source_host.is_empty()&&domain_matches(&source_host.to_ascii_lowercase(),value)))){return Ok(true)}
  }
  if self.owner<1{return Ok(false)}
  let mutes=self.rows("SELECT to_jsonb(m) FROM ap_mutes m WHERE owner_user_id=($1::jsonb->>'owner')::bigint ORDER BY to_jsonb(m)::text",json!({"owner":self.owner})).await?;
  if mutes.iter().any(|row|actor_matches(actor,s(row,"actor_id"),false)){return Ok(true)}
  let personal=self.rows("SELECT to_jsonb(b) FROM ap_user_blocks b WHERE owner_user_id=($1::jsonb->>'owner')::bigint ORDER BY to_jsonb(b)::text",json!({"owner":self.owner})).await?;
  Ok(personal.iter().any(|row|match s(row,"scope"){"actor"=>actor_matches(actor,s(row,"value"),false),"domain"=>domain_matches(&personal_host,s(row,"value")),_=>false}))
 }
}
