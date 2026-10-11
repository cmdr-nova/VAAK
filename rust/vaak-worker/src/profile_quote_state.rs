//! Private quote-preview effects, distinct from ordinary root preview budget.
//! The PHP caller retains raw absence/type until the complete batch validates.
use anyhow::{bail,Result};
use serde_json::Value;
struct BoundQuotePreviewGrant<'a>{db:&'a tokio_postgres::Client,warm:&'a crate::profile_enrichment::WarmContext}
impl crate::profile_quote_warm::CurrentQuoteGrant for BoundQuotePreviewGrant<'_>{fn accept_preview_write(&self,write:&crate::profile_preview_fetch::PreviewCacheWrite)->Result<()>{self.warm.accept_preview_write(write)}fn require_current(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'_>>{Box::pin(self.warm.check_current(self.db))}}
pub(crate) struct QuotePreviewState {initial:i64,remaining:i64,attempts:usize}
impl QuotePreviewState {
 pub(crate) fn new(effective_budget:i64)->Self{Self{initial:effective_budget,remaining:effective_budget,attempts:0}}
 pub(crate) fn initial(&self)->i64{self.initial}
 pub(crate) fn remaining(&self)->i64{self.remaining}
 pub(crate) fn attempts(&self)->usize{self.attempts}
 /// PHP performs the second resolver call even for URL-free nonempty text.
 /// No attempt means the caller's original raw global must stay untouched.
 pub(crate) fn apply_pending_text(&mut self,plain:&str)->Result<()>{if crate::library::card::php_trim(plain).is_empty(){return Ok(())}if crate::direct_preview::extract_url(plain)?.is_some(){bail!("PHP fallback: pending text URL requires current resolver")}if self.remaining>0{self.remaining-=1;self.attempts+=1;}Ok(())}
 pub(crate) fn apply_plain_quote(&mut self,quoted:&Value)->Result<()> {
  if quoted.get("media_attachments").and_then(Value::as_array).is_some_and(|items|!items.is_empty())||quoted.get("card").is_some_and(Value::is_object){return Ok(())}
  let content=quoted.get("content").and_then(Value::as_str).unwrap_or("");
  let plain=crate::library::body::scrub_as2(&crate::library::body::html_to_plain(content));
  if crate::library::card::php_trim(&plain).is_empty(){return Ok(())}
  if crate::direct_preview::extract_url(&plain)?.is_some(){bail!("PHP fallback: quoted URL requires current cache/fetch/warm resolver")}
  if self.remaining>0{self.remaining-=1;self.attempts+=1;}
  Ok(())
 }
 pub(crate) async fn prepare_status(&mut self,db:&tokio_postgres::Client,dns:&dyn crate::direct_preview::PreviewDns,status:&mut Value,now:chrono::DateTime<chrono::Utc>)->Result<()> {
  self.prepare_status_with_context(db,dns,status,now,None).await
 }
 pub(crate) async fn prepare_status_with_context(&mut self,db:&tokio_postgres::Client,dns:&dyn crate::direct_preview::PreviewDns,status:&mut Value,now:chrono::DateTime<chrono::Utc>,warm:Option<&crate::profile_enrichment::WarmContext>)->Result<()> {
  let inner=status.get("content").and_then(Value::as_str)==Some("")&&status.get("reblog").is_some_and(Value::is_object);
  let displayed=if inner{status.get_mut("reblog").unwrap()}else{status};
  if let Some(quote)=displayed.get_mut("quote").filter(|quote|quote.is_object()){
   let quoted=quote.get_mut("quoted_status").filter(|child|child.is_object()).ok_or_else(||anyhow::anyhow!("PHP fallback: pending profile quote requires current recovery"))?;
   if quoted.get("media_attachments").and_then(Value::as_array).is_some_and(|items|!items.is_empty())||quoted.get("card").is_some_and(Value::is_object){return Ok(())}
   let plain=crate::library::body::scrub_as2(&crate::library::body::html_to_plain(quoted.get("content").and_then(Value::as_str).unwrap_or("")));
   if crate::direct_preview::extract_url(&plain)?.is_some(){
    if let Some((warm,preview))=warm.and_then(|warm|warm.preview_context().map(|preview|(warm,preview))){
     let grant=BoundQuotePreviewGrant{db,warm};
     let mut card=preview.resolve_text(db,dns,&plain,false,now,&grant).await?;
     if card.is_none()&&self.remaining>0{self.remaining-=1;self.attempts+=1;card=preview.resolve_text(db,dns,&plain,true,now,&grant).await?;}
     if let Some(card)=card{quoted["card"]=card;}else{preview.warm_text(&plain,&grant).await?;}
     warm.check_current(db).await?;return Ok(())
    }
    if let Some(card)=crate::direct_preview::resolve_cache_only(db,dns,&plain,now).await?{
     // Presentation only, derived from current cache after the quoted source
     // passed admission. No source identity/audience field is altered.
     quoted["card"]=card;return Ok(())
    }
    bail!("PHP fallback: quoted cold URL requires exact fetch/warm effects")
   }
   self.apply_plain_quote(quoted)?;
  }
  Ok(())
 }
 pub(crate) fn apply_status(&mut self,status:&Value)->Result<()> {
  let displayed=if status.get("content").and_then(Value::as_str)==Some(""){status.get("reblog").filter(|child|child.is_object()).unwrap_or(status)}else{status};
  if let Some(quote)=displayed.get("quote").filter(|quote|quote.is_object()){
   let quoted=quote.get("quoted_status").filter(|child|child.is_object()).ok_or_else(||anyhow::anyhow!("PHP fallback: pending profile quote requires current recovery"))?;
   self.apply_plain_quote(quoted)?;
  }
  Ok(())
 }
}
#[cfg(test)]mod tests{
 use super::*;
 #[test]fn plain_quote_state_preserves_zero_negative_and_consumes_only_real_second_calls(){
  let plain=serde_json::json!({"content":"Actual current quote body","media_attachments":[]});
  for (initial,remaining,attempts)in [(-1,-1,0),(0,0,0),(1,0,1),(2,1,1),(3,2,1)]{
   let mut state=QuotePreviewState::new(initial);state.apply_plain_quote(&plain).unwrap();assert_eq!((state.remaining(),state.attempts()),(remaining,attempts));
  }
  let mut state=QuotePreviewState::new(3);state.apply_plain_quote(&serde_json::json!({"content":"","media_attachments":[]})).unwrap();assert_eq!((state.remaining(),state.attempts()),(3,0));
  state.apply_plain_quote(&serde_json::json!({"content":"https://article.test/one","media_attachments":[{"url":"https://media.test/one.jpg"}]})).unwrap();assert_eq!(state.remaining(),3);
  assert!(state.apply_plain_quote(&serde_json::json!({"content":"https://article.test/one","media_attachments":[]})).is_err());assert_eq!(state.remaining(),3);
 }
}
