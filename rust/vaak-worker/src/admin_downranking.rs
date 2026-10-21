//! Home downranking vocabulary list copied from the ap-admin `downranking` view:
//! the admin-managed phrases with their category, added time and Remove form.
//! Admin only. PHP keeps the intro, the add and preview forms, the preview
//! result, and every vocabulary action. Spaces stand where the PHP template has
//! whitespace.
use super::*;
pub(super) struct Term {id:i64,phrase:String,category:String,created_at:String}
pub(super) const MAX_TERMS:usize=1000;
pub(super) async fn collect(db:&tokio_postgres::Client)->Result<Vec<Term>>{
 // phrase is UNIQUE, so (category, phrase) is a total order; a missing table stays PHP's notice.
 let rows=db.query("SELECT id::bigint,phrase::text,category::text,created_at::text FROM ap_home_downrank_terms ORDER BY category ASC,phrase ASC LIMIT 1001",&[]).await?;
 if rows.len()>MAX_TERMS{bail!("vocabulary bound");}
 Ok(rows.into_iter().map(|r|Term{id:r.get(0),phrase:r.get::<_,Option<String>>(1).unwrap_or_default(),category:r.get::<_,Option<String>>(2).unwrap_or_else(||"custom".into()),created_at:r.get::<_,Option<String>>(3).unwrap_or_default()}).collect())
}
pub(super) fn paint(terms:Vec<Term>,r:&Request)->Result<(String,usize,Value)>{
 let mut h=String::new();
 if terms.is_empty(){h.push_str(r#"<div class="empty">No admin-managed phrases yet.</div>"#);}
 else{
  h.push_str(r#"<div style="display:flex;flex-direction:column;gap:.6rem"> "#);
  for t in &terms{
   h.push_str(&format!(r#"<article class="tweet" style="padding:.8rem 1rem"> <div class="tweet-hd"> <div class="who" style="overflow-wrap:anywhere">{}</div> <span class="tag">{}</span> </div> <div class="tweet-actions" style="margin-top:.5rem"> <span class="meta">Added {}</span> <form method="post" action="?view=downranking" style="display:inline;margin-left:auto" onsubmit="return confirm('Remove this downranking phrase?');"> <input type="hidden" name="action" value="downrank_term_delete"> <input type="hidden" name="csrf" value="{}"> <input type="hidden" name="term_id" value="{}"> <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Remove</button> </form> </div> </article> "#,
    esc(&t.phrase),esc(&t.category),esc(&relative(&t.created_at,r.now)?),esc(&r.csrf),t.id));
  }
  h.push_str("</div>");
 }
 // h() runs ap_fix_utf8 on every value it prints; text it would repair stays PHP.
 static MOJIBAKE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"â.|Ã.|Â.|ðŸ").unwrap());
 if MOJIBAKE.is_match(&h){bail!("PHP would repair printed text");}
 let rows:Vec<Value>=terms.iter().map(|t|json!({"id":t.id})).collect();
 Ok((h,rows.len(),Value::Array(rows)))
}
