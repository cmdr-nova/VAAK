//! Downranked review page copied from admin_home_downranked_actor_rows,
//! admin_home_downrank_evidence_rows, ap_downrank_text_categories,
//! actor_display_name, admin_remote_actor_href, admin_status_href,
//! admin_home_downrank_audit_rows and the ap-admin `downranked` view. Admin only.
//! PHP runs the stale-signal cleanup DELETE before asking, keeps the search
//! filter (a search renders PHP), CSRF, and every clear, mute and block action.
//! Text PHP's strip_tags would change, Bluesky/Threads profile links and
//! non-URL actors render the PHP page instead. Spaces stand where the PHP
//! template has whitespace.
use super::*;
use crate::library::card::php_trim;
pub(super) struct Evidence {object_id:String,summary:String,categories:Vec<String>}
pub(super) struct Actor {actor:String,name:String,profile:Option<String>,score:i64,viewers:i64,until:i64,categories:Vec<String>,evidence:Vec<Evidence>}
pub(super) struct Audit {action:String,phrase:String,admin:String,created_at:String}
pub(super) struct Collected {actors:Vec<Actor>,audit:Vec<Audit>,pub(super) warm:Vec<String>}
pub(super) const MAX_ACTORS:usize=300;
const MAX_SIGNALS:i64=20000;
const SCRIPT:&str=r#"<script> (function () { var panel = document.getElementById('downranked-panel'); if (!panel) return; var perPage = Math.max(1, parseInt(panel.getAttribute('data-per-page') || '25', 10) || 25); var items = Array.prototype.slice.call(panel.querySelectorAll('.downranked-item')); var total = items.length; var pages = Math.max(1, Math.ceil(total / perPage)); var page = 1; var pager = document.getElementById('downranked-pager'); var label = document.getElementById('downranked-page-label'); var prev = document.getElementById('downranked-prev'); var next = document.getElementById('downranked-next'); var list = document.getElementById('downranked-list'); function render() { var start = (page - 1) * perPage; var end = start + perPage; for (var i = 0; i < items.length; i++) { items[i].style.display = (i >= start && i < end) ? '' : 'none'; } if (label) label.textContent = 'Page ' + page + ' of ' + pages; if (prev) prev.disabled = page <= 1; if (next) next.disabled = page >= pages; if (pager) pager.style.display = pages > 1 ? 'flex' : 'none'; if (list) list.scrollTop = 0; } if (prev) prev.addEventListener('click', function () { if (page > 1) { page -= 1; render(); } }); if (next) next.addEventListener('click', function () { if (page < pages) { page += 1; render(); } }); render(); })(); </script>"#;
fn word(c:char)->bool{
 static W:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"^[\p{L}\p{N}_]$").unwrap());
 c=='_'||c.is_ascii_alphanumeric()||(!c.is_ascii()&&W.is_match(c.encode_utf8(&mut [0;4])))
}
/// Text PHP's strip_tags, its /u whitespace class or its ASCII-only patterns could
/// read differently from these copies: a tag opener, a NUL, or U+180E (PCRE2 \s
/// under UCP, not Unicode White_Space).
fn unproven_text(s:&str)->bool{s.contains(['<','\0','\u{180E}'])}
/// preg_replace('/\s+/u', ' ', ...): every Unicode whitespace run becomes one space.
pub(crate) fn squash(s:&str)->String{
 static WS:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"\s+").unwrap());
 WS.replace_all(s," ").into_owned()
}
/// ap_downrank_phrase_matches_text on text already strip_tags'd: whole-token,
/// caseless (/iu), with complete hashtag tokens blanked first.
pub(crate) fn phrase_matches(text:&str,phrase:&str)->bool{
 let phrase=php_trim(&squash(phrase)).to_string();
 if phrase.is_empty(){return false;}
 let chars:Vec<char>=text.chars().collect();
 let mut search=String::with_capacity(text.len());
 let mut i=0;
 while i<chars.len(){
  if chars[i]=='#'&&(i==0||!word(chars[i-1])){
   let mut j=i+1;while j<chars.len()&&word(chars[j]){j+=1;}
   if j>i+1{search.push(' ');i=j;continue;}
  }
  search.push(chars[i]);i+=1;
 }
 let Ok(any)=regex::Regex::new(&format!("(?i){}",regex::escape(&phrase))) else {return false};
 if !any.is_match(&search){return false;}
 let Ok(anchored)=regex::Regex::new(&format!("^(?i:{})",regex::escape(&phrase))) else {return false};
 let mut prev:Option<char>=None;
 for (at,c) in search.char_indices(){
  if prev.is_none_or(|p|!word(p)){
   if let Some(m)=anchored.find(&search[at..]){
    if search[at+m.end()..].chars().next().is_none_or(|n|!word(n)){return true;}
   }
  }
  prev=Some(c);
 }
 false
}
/// ap_downrank_text_categories, in PHP's order: the built-in ASCII patterns, then
/// enabled admin phrases by id. The input is free of tags (unproven_text).
fn categories(text:&str,terms:&[(String,String)])->Vec<String>{
 let text=php_trim(text).to_ascii_lowercase();
 if text.is_empty(){return Vec::new();}
 static BUILTIN:std::sync::LazyLock<Vec<(&str,regex::bytes::Regex)>>=std::sync::LazyLock::new(||[
  ("direct_abuse",r"(?i-u)\b(?:fuck\s+you|suck\s+my\s+dick|go\s+fuck\s+yourself)\b"),
  ("ai_slogan",r"(?i-u)\b(?:slop|ai\s+trash|clanker)\b"),
  ("misogyny",r"(?i-u)\b(?:women\s+belong\s+in\s+the\s+kitchen|women\s+are\s+property|go\s+back\s+to\s+the\s+kitchen)\b"),
 ].into_iter().map(|(c,p)|(c,regex::bytes::Regex::new(p).unwrap())).collect());
 let mut out:Vec<String>=Vec::new();
 for (cat,re) in BUILTIN.iter(){if re.is_match(text.as_bytes()){out.push((*cat).into());}}
 for (phrase,cat) in terms{if phrase_matches(&text,phrase){out.push(cat.clone());}}
 let mut seen=Vec::new();out.retain(|c|if seen.contains(c){false}else{seen.push(c.clone());true});
 out
}
async fn terms(db:&tokio_postgres::Client)->Result<Vec<(String,String)>>{
 let mut out=Vec::new();
 for r in db.query("SELECT phrase::text,category::text FROM ap_home_downrank_terms WHERE enabled = 1 ORDER BY id ASC LIMIT 500",&[]).await?{
  let raw=r.get::<_,Option<String>>(0).unwrap_or_default();
  if unproven_text(&raw){bail!("PHP strip_tags phrase");}
  let phrase=php_trim(&squash(&raw)).to_string();
  if phrase.is_empty()||phrase.chars().count()>120{continue;}
  let cat:String=r.get::<_,Option<String>>(1).unwrap_or_else(||"custom".into()).chars().filter(|c|c.is_ascii_alphanumeric()||*c=='_'||*c=='-').collect();
  out.push((phrase,if cat.is_empty(){"custom".into()}else{cat}));
 }
 Ok(out)
}
/// ap_downrank_actor_exempt on a trimmed actor.
fn exempt(actor:&str)->bool{
 let lower=actor.to_ascii_lowercase();
 actor.is_empty()||lower.starts_with("https://mkultra.monster/users/")||lower.starts_with("https://mkultra.monster/@")
  ||lower.ends_with("/users/cmdr_nova")||lower.ends_with("/@cmdr_nova")||lower=="cmdr_nova"||lower=="cmdr_nova@mkultra.monster"
}
/// strtotime for the formats relative() proves; anything else stays PHP.
fn timestamp(s:&str)->Result<i64>{
 chrono::DateTime::parse_from_rfc3339(s).map(|v|v.timestamp())
  .or_else(|_|chrono::NaiveDateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S").map(|v|v.and_utc().timestamp()))
  .or_else(|_|chrono::DateTime::parse_from_str(s,"%Y-%m-%d %H:%M:%S%.f%#z").map(|v|v.timestamp()))
  .map_err(|_|anyhow::anyhow!("unproven PHP date format"))
}
fn authority(actor:&str)->Result<String>{
 let auth=actor.strip_prefix("https://").unwrap_or("").split(['/','?','#']).next().unwrap_or("");
 if auth.is_empty()||auth.contains(['@',':','[','%']){bail!("unproven actor host");}
 Ok(auth.to_ascii_lowercase())
}
/// admin_status_href($objectId, 'downranked') for the https ids collect admits.
fn status_href(object:&str)->String{
 static ANNOUNCE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"#announce-\d+$").unwrap());
 let mut url=php_trim(object).to_string();
 let stripped=ANNOUNCE.replace(&url,"").to_string();
 if stripped!=url{url=stripped.trim_end_matches('/').to_string();}
 if url.is_empty()||!url.starts_with("https://"){return "#".into();}
 crate::library::actions::in_app_status_href(&url, "downranked")
}
async fn evidence(db:&tokio_postgres::Client,actor:&str,wanted:&[String],last:&str,terms:&[(String,String)])->Result<Vec<Evidence>>{
 if wanted.is_empty(){return Ok(Vec::new());}
 let mut out=Vec::new();
 // Ties on created_at break by id, in PHP's query too, so the 100-row window is stable.
 for r in db.query("SELECT object_id::text,summary::text,spoiler_text::text FROM events WHERE rtrim(actor_id,'/')=rtrim($1,'/') AND visibility IN ('public','unlisted') ORDER BY created_at DESC, id DESC LIMIT 100",&[&actor]).await?{
  let object=php_trim(&r.get::<_,Option<String>>(0).unwrap_or_default()).trim_end_matches('/').to_string();
  let parts:Vec<String>=[r.get::<_,Option<String>>(1),r.get::<_,Option<String>>(2)].into_iter().map(Option::unwrap_or_default).filter(|v|!php_trim(v).is_empty()).collect();
  let summary=php_trim(&parts.join("\n")).to_string();
  if object.is_empty()||summary.is_empty(){continue;}
  if unproven_text(&summary){bail!("PHP strip_tags evidence");}
  let found=categories(&summary,terms);
  let mut matched:Vec<String>=wanted.iter().filter(|w|found.contains(w)).cloned().collect();
  if matched.is_empty()&&!last.is_empty()&&object==last{matched=wanted.to_vec();}
  if matched.is_empty(){continue;}
  if object.starts_with("at://")||object.starts_with("bsky:"){bail!("PHP-only AT URI link");}
  out.push(Evidence{object_id:object,summary,categories:matched});
  if out.len()>=5{break;}
 }
 Ok(out)
}
pub(super) async fn collect(db:&tokio_postgres::Client,cfg:&crate::config::Config,now:i64)->Result<Collected>{
 // Same order as PHP, with the primary key as its tiebreak (in PHP's query too).
 let rows=db.query("SELECT actor_id::text,score::bigint,categories_json::text,suppressed_until::text,last_object_id::text FROM ap_home_suppression ORDER BY suppressed_until DESC, score DESC, actor_id ASC, owner_user_id ASC LIMIT $1",&[&(MAX_SIGNALS+1)]).await?;
 if rows.len() as i64>MAX_SIGNALS{bail!("signal bound");}
 struct Agg {actor:String,score:i64,viewers:i64,until:i64,last:String,categories:Vec<String>}
 let mut agg:Vec<Agg>=Vec::new();
 for r in rows{
  let actor=php_trim(&r.get::<_,Option<String>>(0).unwrap_or_default()).trim_end_matches('/').to_string();
  if exempt(&actor){continue;}
  let until=timestamp(&r.get::<_,Option<String>>(3).unwrap_or_default())?;
  if until<=now{continue;}
  let cats:Vec<String>=match serde_json::from_str::<Value>(&r.get::<_,Option<String>>(2).unwrap_or_default()){
   Ok(Value::Array(items))=>items.into_iter().map(|v|match v{Value::String(s)=>Ok(s),_=>Err(anyhow::anyhow!("PHP strval category"))}).collect::<Result<_>>()?,
   Ok(Value::Object(_))=>bail!("PHP keyed category array"),
   _=>Vec::new(),
  };
  let i=match agg.iter().position(|a|a.actor==actor){Some(i)=>i,None=>{agg.push(Agg{actor,score:0,viewers:0,until:0,last:String::new(),categories:Vec::new()});agg.len()-1}};
  let a=&mut agg[i];
  a.score=a.score.max(r.get::<_,i64>(1));a.viewers+=1;
  if until>a.until{a.until=until;a.last=r.get::<_,Option<String>>(4).unwrap_or_default();}
  for c in cats{if !a.categories.contains(&c){a.categories.push(c);}}
 }
 if agg.len()>MAX_ACTORS{bail!("actor bound");}
 let terms=if agg.is_empty(){Vec::new()}else{terms(db).await?};
 let (mut actors,mut warm)=(Vec::new(),Vec::new());
 for a in agg{
  if !a.actor.starts_with("https://"){bail!("PHP-only actor label");}
  let host=authority(&a.actor)?;
  if matches!(host.as_str(),"mkultra.monster"|"www.mkultra.monster"|"vaak.monster"|"www.vaak.monster"){bail!("PHP-only local actor label");}
  // Bluesky actors get handle/post enrichment that only PHP renders.
  if host=="bsky.app"||host=="bsky.brid.gy"||matches!(host.as_str(),"threads.net"|"www.threads.net"|"threads.com"|"www.threads.com"){bail!("PHP-only profile link");}
  let cached=crate::profile_identity::lookup_actor(db,cfg,&a.actor).await?;
  if cached.as_ref().is_none_or(|r|crate::profile_identity::placeholder(r["username"].as_str().unwrap_or(""))){warm.push(a.actor.clone());}
  let label=crate::profile_identity::label(&a.actor,cached.as_ref());
  let display=php_trim(label["display_name"].as_str().unwrap_or("")).to_string();
  let name=if display.is_empty()||crate::profile_identity::placeholder(&display){label["handle"].as_str().unwrap_or("").to_string()}else{display};
  // admin_remote_actor_href: https://host/@user once the label has a real username.
  let user=php_trim(label["username"].as_str().unwrap_or("")).trim_start_matches('@').split('@').next().unwrap_or("").trim().to_string();
  let profile=if !user.is_empty()&&user!="user"&&!crate::profile_identity::placeholder(&user){format!("https://{host}/@{}",query(&user))}else{a.actor.clone()};
  let wanted:Vec<String>=a.categories.iter().filter(|c|!c.is_empty()&&c.as_str()!="0").cloned().collect();
  let evidence=evidence(db,&a.actor,&wanted,php_trim(&a.last).trim_end_matches('/'),&terms).await?;
  actors.push(Actor{name:if name.is_empty(){a.actor.clone()}else{name},profile:Some(profile),actor:a.actor,score:a.score,viewers:a.viewers,until:a.until,categories:wanted,evidence});
 }
 let mut audit=Vec::new();
 for r in db.query("SELECT admin_actor::text,action::text,actor_id::text,phrase::text,created_at::text FROM ap_home_downrank_audit ORDER BY created_at DESC, id DESC LIMIT 50",&[]).await?{
  let phrase=r.get::<_,Option<String>>(3).or_else(||r.get::<_,Option<String>>(2)).unwrap_or_default();
  audit.push(Audit{admin:r.get::<_,Option<String>>(0).unwrap_or_default(),action:r.get::<_,Option<String>>(1).unwrap_or_default(),phrase,created_at:r.get::<_,Option<String>>(4).unwrap_or_default()});
 }
 Ok(Collected{actors,audit,warm})
}
/// admin_downranked_expires_label: "in 31m" for a future expiry.
fn expires_label(until:i64,now:i64)->String{
 let d=until-now;
 if d<=0{"now".into()}else if d<60{format!("in {d}s")}else if d<3600{format!("in {}m",d/60)}
 else if d<86400{format!("in {}h {}m",d/3600,d%3600/60)}else{format!("in {}d {}h",d/86400,d%86400/3600)}
}
pub(super) fn paint(c:Collected,r:&Request)->Result<(String,usize,Value)>{
 let mut h=String::new();let csrf=esc(&r.csrf);
 if c.actors.is_empty(){h.push_str(r#"<div class="empty">No active temporary downranking signals.</div> "#);}
 else{
  let n=c.actors.len();
  h.push_str(&format!(r#"<div id="downranked-panel" class="downranked-panel" data-per-page="25" data-total="{n}" style="border:1px solid var(--border);border-radius:12px;padding:.75rem;background:rgba(0,0,0,.12)"> <div class="meta" style="margin-bottom:.55rem">{n} active signal{}</div> <div id="downranked-list" class="downranked-list" style="display:flex;flex-direction:column;gap:.7rem;max-height:min(70vh,42rem);overflow:auto;padding-right:.15rem"> "#,if n==1{""}else{"s"}));
  for (i,a) in c.actors.iter().enumerate(){
   let actor=esc(&a.actor);
   let cats=a.categories.join(", ");
   h.push_str(&format!(r#"<article class="tweet downranked-item" data-dr-index="{i}" style="padding:.85rem 1rem;display:none"> <div class="tweet-hd"> <div style="min-width:0"> <div class="who" style="overflow-wrap:anywhere">{}</div> <div class="meta" style="overflow-wrap:anywhere">{actor}</div> </div> <span class="tag">active</span> </div> <div class="meta" style="margin-top:.55rem"> Categories: {} · score {} · {} viewer{} "#,
    esc(&a.name),esc(if cats.is_empty(){"uncategorized"}else{&cats}),a.score,a.viewers,if a.viewers==1{""}else{"s"}));
   if a.until>0{h.push_str(&format!("· expires {} ",esc(&expires_label(a.until,r.now))));}
   h.push_str("</div> ");
   if !a.evidence.is_empty(){
    h.push_str(r#"<div class="meta" style="margin-top:.55rem">Recent matching evidence:</div> <div style="display:flex;flex-direction:column;gap:.25rem;margin-top:.2rem"> "#);
    for e in &a.evidence{
     let summary=php_trim(&squash(&e.summary)).to_string();
     let label:String=summary.chars().take(140).collect();
     h.push_str(&format!(r#"<a class="meta" href="{}" style="overflow-wrap:anywhere;text-decoration:underline" title="{}"> {} · {} </a> "#,
      esc(&status_href(&e.object_id)),esc(&summary),esc(if label.is_empty(){"Open matching status"}else{&label}),esc(&e.categories.join(", "))));
    }
    h.push_str("</div> ");
   }
   h.push_str(r#"<div class="tweet-actions" style="flex-wrap:wrap;margin-top:.55rem"> "#);
   if let Some(p)=&a.profile{h.push_str(&format!(r#"<a href="{}" target="_blank" rel="noopener noreferrer">Open profile</a> "#,esc(p)));}
   h.push_str(&format!(r#"<form method="post" action="?view=downranked" style="display:inline"> <input type="hidden" name="action" value="downrank_signal_clear"> <input type="hidden" name="csrf" value="{csrf}"> <input type="hidden" name="actor_id" value="{actor}"> <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Clear signal</button> </form> <form method="post" action="?view=downranked" style="display:inline"> <input type="hidden" name="action" value="block_actor"> <input type="hidden" name="csrf" value="{csrf}"> <input type="hidden" name="actor_id" value="{actor}"> <input type="hidden" name="kind" value="mute"> <input type="hidden" name="reason" value="Admin review of temporary Home downranking signal"> <input type="hidden" name="return_view" value="downranked"> <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Server mute</button> </form> <form method="post" action="?view=downranked" style="display:inline" onsubmit="return confirm('Apply a server-wide block to this actor?');"> <input type="hidden" name="action" value="block_actor"> <input type="hidden" name="csrf" value="{csrf}"> <input type="hidden" name="actor_id" value="{actor}"> <input type="hidden" name="kind" value="block"> <input type="hidden" name="reason" value="Admin review of temporary Home downranking signal"> <input type="hidden" name="return_view" value="downranked"> <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem;color:var(--danger)">Server block</button> </form> </div> </article> "#));
  }
  h.push_str(r#"</div> <nav id="downranked-pager" class="composer-actions" aria-label="Downranked pages" style="margin-top:.75rem;justify-content:center;gap:.5rem;display:none"> <button type="button" class="btn btn-ghost" id="downranked-prev" style="padding:.25rem .7rem;font-size:.8rem">Previous</button> <span class="meta" id="downranked-page-label">Page 1 of 1</span> <button type="button" class="btn btn-ghost" id="downranked-next" style="padding:.25rem .7rem;font-size:.8rem">Next</button> </nav> </div> "#);
  h.push_str(SCRIPT);h.push(' ');
 }
 h.push_str(r#"<details class="blocks-section" style="margin-top:1.5rem"> <summary><span class="who">Recent audit trail</span><span class="meta">admin vocabulary and escalation actions</span></summary> <div class="blocks-section-body"> "#);
 if c.audit.is_empty(){h.push_str(r#"<div class="empty">No audit entries yet.</div> "#);}
 for a in &c.audit{
  h.push_str(&format!(r#"<div class="meta" style="padding:.45rem 0;border-bottom:1px solid var(--border)"> <strong>{}</strong> · {} · {} "#,esc(&a.action),esc(&a.phrase),esc(&relative(&a.created_at,r.now)?)));
  if !empty(&a.admin){h.push_str(&format!("· by {} ",esc(&a.admin)));}
  h.push_str("</div> ");
 }
 h.push_str("</div> </details>");
 // h() runs ap_fix_utf8 on every value it prints; text it would repair stays PHP.
 static MOJIBAKE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"â.|Ã.|Â.|ðŸ").unwrap());
 if MOJIBAKE.is_match(&h){bail!("PHP would repair printed text");}
 let rows:Vec<Value>=c.actors.iter().map(|a|json!({"actor_id":a.actor,"viewers":a.viewers,"evidence":a.evidence.len()})).collect();
 Ok((h,rows.len(),Value::Array(rows)))
}
#[cfg(test)]mod tests{
 use super::*;
 #[test]fn ascii_word_boundaries_match_pcre_without_u(){
  // PCRE without /u treats the bytes of ñ as non-word, so \b fires before "slop".
  assert_eq!(categories("ñslop",&[]),vec!["ai_slogan"]);
  assert!(categories("sloppy",&[]).is_empty());
  assert_eq!(categories("SLOP and Fuck  You",&[]),vec!["direct_abuse","ai_slogan"]);
 }
 #[test]fn phrases_are_whole_unicode_tokens(){
  let t=vec![("bad word".to_string(),"custom".to_string())];
  assert_eq!(categories("a BAD  word here",&t),vec![] as Vec<String>,"squashed text keeps two spaces; PHP's phrase has one");
  assert_eq!(categories("a bad word here",&t),vec!["custom"]);
  assert!(categories("ñbad word",&t).is_empty());
  assert!(categories("#bad word",&t).is_empty());
  assert_eq!(categories("x#bad word",&t),vec!["custom"]);
  assert!(categories("bad wordbad word",&t).is_empty(),"neither copy is a whole token");
  assert_eq!(categories("bad word, bad word",&t),vec!["custom"]);
 }
}
