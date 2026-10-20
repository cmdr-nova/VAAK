//! Settings profile form copied from ap_profile_get, ap_profile_normalize_badges,
//! ap_local_avatar_url and the profile view's save_profile form. PHP retains the
//! session, CSRF injection, uploads, the save itself and every other section.
//! Profile text PHP would repair (ap_fix_utf8 mojibake) or reduce with
//! strip_tags / html_entity_decode beyond the proven subset renders the PHP form
//! instead. Spaces stand where the PHP template has whitespace.
use super::*;
use crate::library::card::{boolean,php_trim};
use sha2::{Digest,Sha256};
pub(super) struct Collected {key:String,actor_id:String,username:String,email:String,profile:Value,source:&'static str}
/// ap_redis_json_get on the PHP cache key, with the validity checks ap_profile_get applies.
async fn cached(cfg:&crate::config::Config,key:&str)->Option<Value>{
 if std::env::var("VAAK_REDIS_ENABLED").is_ok_and(|v|matches!(php_trim(&v).to_ascii_lowercase().as_str(),"0"|"false"|"off"|"no")){return None;}
 let mut conn=tokio::time::timeout(std::time::Duration::from_millis(80),crate::redis_util::connect(&cfg.redis_url)).await.ok()?.ok()?;
 let cache_key=format!("vaak:profile:v1:{}",hex::encode(Sha256::digest(php_trim(key).as_bytes())));
 let v=tokio::time::timeout(std::time::Duration::from_millis(80),crate::redis_util::json_get(&mut conn,&cache_key)).await.ok()?.ok()??;
 let present=|k:&str|v.get(k).is_some_and(|x|!x.is_null());
 (v.is_object()&&present("actor_key")&&present("name")&&present("summary")&&v.get("trending_enabled").is_some_and(Value::is_boolean)).then_some(v)
}
/// The ap_profile_get row branch. Missing columns take PHP's defaults.
fn from_row(row:&Value)->Result<Value>{
 let has=|k:&str|row.get(k).is_some();
 let text=|k:&str|->Result<String>{match row.get(k).unwrap_or(&Value::Null){Value::Null=>Ok(String::new()),Value::String(s)=>Ok(s.clone()),_=>bail!("unproven profile column type")}};
 let attachment=serde_json::from_str::<Value>(&if has("attachment_json")&&!row["attachment_json"].is_null(){text("attachment_json")?}else{"[]".into()}).ok().filter(|v|v.is_array()||v.is_object());
 let Some(attachment)=attachment else{bail!("PHP default attachment")};
 let flag=|k:&str,default:bool|if has(k){boolean(row,k)}else{default};
 // PHP checks `?? 'anyone'` but returns the raw cast, so NULL or a missing column becomes ''.
 let policy=|k:&str|->Result<String>{if row.get(k).is_none_or(Value::is_null){return Ok(String::new());}let v=text(k)?;Ok(if matches!(v.as_str(),"anyone"|"followers"|"nobody"){v}else{"anyone".into()})};
 let media=|k:&str|->Result<Value>{let v=text(k)?;Ok(if v.is_empty(){Value::Null}else{json!(v)})};
 Ok(json!({
  "actor_key":text("actor_key")?,"name":text("name")?,"summary":text("summary")?,"attachment":attachment,
  "icon_url":media("icon_url")?,"image_url":media("image_url")?,
  "manually_approves":boolean(row,"manually_approves"),"discoverable":boolean(row,"discoverable"),"indexable":flag("indexable",true),
  "collection_consent":flag("collection_consent",true),"vanity_verified":flag("vanity_verified",false),"auto_follow_back":flag("auto_follow_back",false),
  "auto_unblur_sensitive":flag("auto_unblur_sensitive",false),"auto_delete_posts_7d":flag("auto_delete_posts_7d",false),"automated":flag("automated",false),
  "reply_policy":policy("reply_policy")?,"quote_policy":policy("quote_policy")?,"forum_signature":php_trim(&text("forum_signature")?),
  "profile_badges":row.get("profile_badges").cloned().unwrap_or(Value::Null),
  "hide_profile_replies":flag("hide_profile_replies",false),"hide_profile_boosts":flag("hide_profile_boosts",false),
  "algorithm_enabled":flag("algorithm_enabled",true),"trending_enabled":if row.get("trending_enabled").is_some_and(|v|!v.is_null()){boolean(row,"trending_enabled")}else{true},
  "downranking_enabled":flag("downranking_enabled",true),"asks_enabled":flag("asks_enabled",true),"webmentions_enabled":flag("webmentions_enabled",true),
  "updated_at":row.get("updated_at").cloned().unwrap_or(Value::Null),
 }))
}
pub(super) async fn collect(db:&tokio_postgres::Client,cfg:&crate::config::Config,owner:i64)->Result<Collected>{
 let row=db.query_one("SELECT actor_key::text,actor_id::text,username::text,email::text FROM ap_users WHERE id::bigint=$1",&[&owner]).await?;
 let key:String=row.get::<_,Option<String>>(0).unwrap_or_else(||"cmdr_nova".into());
 let actor_id=row.get::<_,Option<String>>(1).unwrap_or_else(||format!("https://mkultra.monster/users/{key}")).trim_end_matches('/').to_string();
 let username=php_trim(&row.get::<_,Option<String>>(2).unwrap_or_default()).to_string();
 let username=if username.is_empty(){php_trim(&key).to_string()}else{username};
 let email=row.get::<_,Option<String>>(3).unwrap_or_default();
 let (profile,source)=if let Some(v)=cached(cfg,&key).await{(v,"cache")}else{
  let Some(p)=db.query_opt("SELECT to_jsonb(p) FROM actor_profile p WHERE actor_key=$1",&[&key]).await? else{bail!("PHP profile defaults")};
  (from_row(&p.get::<_,Value>(0))?,"db")
 };
 Ok(Collected{key,actor_id,username,email,profile,source})
}
fn mojibake(s:&str)->bool{
 static RE:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"â.|Ã.|Â.|ðŸ").unwrap());
 RE.is_match(s)
}
fn string(v:&Value,k:&str)->Result<String>{match v.get(k).unwrap_or(&Value::Null){Value::Null=>Ok(String::new()),Value::String(s)=>Ok(s.clone()),Value::Bool(true)=>Ok("1".into()),Value::Bool(false)=>Ok(String::new()),Value::Number(n) if n.is_i64()=>Ok(n.to_string()),_=>bail!("unproven PHP string cast")}}
/// html_entity_decode(ENT_QUOTES|ENT_HTML5) for the entities profile text is proven to use.
/// An '&' that does not start `name;` or `#digits;` stays literal, as in PHP.
fn decode(s:&str)->Result<String>{
 static REF:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"^&(#[xX][0-9A-Fa-f]+|#[0-9]+|[A-Za-z][A-Za-z0-9]*);").unwrap());
 let mut out=String::new();let mut rest=s;
 while let Some(i)=rest.find('&'){
  out.push_str(&rest[..i]);rest=&rest[i..];
  let Some(m)=REF.captures(rest) else{out.push('&');rest=&rest[1..];continue};
  let name=m.get(1).unwrap().as_str();
  let ch=match name{"amp"=>'&',"lt"=>'<',"gt"=>'>',"quot"=>'"',"apos"=>'\'',"nbsp"=>'\u{a0}',_=>{
   let code=if let Some(hex)=name.strip_prefix("#x").or_else(||name.strip_prefix("#X")){u32::from_str_radix(hex,16).ok()}else if let Some(dec)=name.strip_prefix('#'){dec.parse::<u32>().ok()}else{None};
   match code.filter(|c|*c>=0x20&&*c!=0x7f&&!(0x80..0xa0).contains(c)&&!(0xfdd0..=0xfdef).contains(c)&&(c&0xfffe)!=0xfffe).and_then(char::from_u32){Some(c)=>c,None=>bail!("PHP-only entity")}
  }};
  out.push(ch);rest=&rest[m.get(0).unwrap().end()..];
 }
 out.push_str(rest);Ok(out)
}
/// strip_tags for well-formed tags; comments, PHP tags, bare '<' and open tags are PHP-only.
fn strip_tags(s:&str)->Result<String>{
 let mut out=String::new();let mut chars=s.chars().peekable();
 while let Some(c)=chars.next(){
  if c!='<'{if c=='>'{bail!("PHP-only stray >");}out.push(c);continue;}
  match chars.peek(){Some(n) if n.is_ascii_alphabetic()||*n=='/'=>{},_=>bail!("PHP-only tag start")}
  let mut quote=None;let mut closed=false;
  for t in chars.by_ref(){match(quote,t){(None,'>')=>{closed=true;break},(_,'\\')=>bail!("PHP-only escaped quote"),(None,'"'|'\'')=>quote=Some(t),(Some(q),_) if q==t=>quote=None,(None,'<')=>bail!("PHP-only nested tag"),_=>{}}}
  if !closed{bail!("PHP-only open tag");}
 }
 Ok(out)
}
fn summary_for_form(raw:&str)->Result<String>{
 static RICH:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"(?i)<(a|code|strong|em|b|i|ul|ol|li)\b").unwrap());
 static GAPS:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r"\n{3,}").unwrap());
 if RICH.is_match(raw){return Ok(raw.to_string());}
 let mut s=raw.to_string();
 for (a,b) in [("<br>","\n"),("<br/>","\n"),("<br />","\n"),("</p><p>","\n\n"),("</p>",""),("<p>","")]{s=s.replace(a,b);}
 let s=php_trim(&decode(&strip_tags(&s)?)?).to_string();
 Ok(GAPS.replace_all(&s,"\n\n").into_owned())
}
fn checked(on:bool)->&'static str{if on{"checked"}else{""}}
fn selected(on:bool)->&'static str{if on{"selected"}else{""}}
pub(super) fn paint(c:Collected,r:&Request)->Result<(String,usize,Value)>{
 let p=&c.profile;
 let name=string(p,"name")?;let summary=string(p,"summary")?;
 if c.source=="db"&&(mojibake(&name)||mojibake(&summary)){bail!("PHP would repair profile text");}
 let handle=if c.username.is_empty(){String::new()}else{format!("@{}@mkultra.monster",c.username)};
 let icon=string(p,"icon_url")?;let image=string(p,"image_url")?;
 let mut h=format!(r#"<form class="composer profile-form" method="post" action="?view=profile" enctype="multipart/form-data"> <input type="hidden" name="action" value="save_profile"> <div class="profile-preview"> <img class="av" src="{}" alt="" loading="lazy" referrerpolicy="no-referrer"> <div> <div class="who">{}"#,esc(&crate::profile_identity::local_avatar(&icon)),esc(&name));
 if boolean(p,"vanity_verified"){h.push_str(r#" <span class="vanity-verified" title="Vanity verified (just for fun)" aria-label="Verified">✓</span>"#);}
 if boolean(p,"automated"){h.push_str(r#" <span class="automated-badge" title="Automated account" aria-label="Automated account">🤖</span>"#);}
 h.push_str(&format!(r#"</div> <div class="meta">{}</div> "#,esc(&handle)));
 if boolean(p,"updated_at"){h.push_str(&format!(r#"<div class="meta">Updated {} ago</div> "#,esc(&relative(&string(p,"updated_at")?,r.now)?)));}
 h.push_str("</div> </div> ");
 if !empty(&image){h.push_str(&format!(r#"<div class="profile-header-preview" style="margin:.75rem 0 1rem;border-radius:12px;overflow:hidden;border:1px solid var(--border);height:180px;max-height:180px"> <img src="{}" alt="" loading="lazy" referrerpolicy="no-referrer" style="display:block;width:100%;height:100%;object-fit:cover"> </div> "#,esc(&image)));}
 let key=esc(&c.key);
 h.push_str(&format!(r#"<label for="pf-name">Display name</label> <input id="pf-name" type="text" name="name" maxlength="100" required value="{}"> <label for="pf-email">Email</label> <input id="pf-email" type="email" name="email" maxlength="200" autocomplete="email" placeholder="you@example.com" value="{}"> <div class="meta" style="margin:.25rem 0 .75rem"> Used for VAAK login (username <em>or</em> email). Not shown on your public profile. Leave blank to clear. </div> <label for="vaak-accent-select">VAAK accent color</label> <select id="vaak-accent-select" style="max-width:18rem"> <option value="green">Green</option> <option value="yellow">Yellow</option> <option value="blue">Blue</option> <option value="red">Red</option> <option value="purple">Purple</option> <option value="orange">Orange</option> <option value="pink">Pink</option> <option value="bone">Bone</option> <option value="cyan">Cyan</option> <option value="teal">Teal</option> <option value="indigo">Indigo</option> <option value="lime">Lime</option> <option value="sunset">Sunset</option> <option value="rainbow">Night City</option> </select> <script> (function () {{ const select = document.getElementById('vaak-accent-select'); if (!select) return; const key = 'vaak-accent-{key}'; const allowed = ['green', 'yellow', 'blue', 'red', 'purple', 'orange', 'pink', 'bone', 'cyan', 'teal', 'indigo', 'lime', 'sunset', 'rainbow']; const current = localStorage.getItem(key); select.value = allowed.includes(current) ? current : 'green'; select.addEventListener('change', function () {{ const value = allowed.includes(select.value) ? select.value : 'green'; document.documentElement.dataset.accent = value; localStorage.setItem(key, value); }}); }}()); </script> <div class="meta" style="margin:.25rem 0 .75rem">Changes the accent used throughout VAAK. Night City also adds a subtle multicolor app background. This is private to your account and browser.</div> <label for="pf-summary">Bio (plain text or simple HTML: p, br, a, code, strong, em)</label> <textarea id="pf-summary" name="summary" maxlength="4000" required>{}</textarea> <label for="pf-forum-signature">Forum signature <span class="meta">(optional, max 500 characters)</span></label> <textarea id="pf-forum-signature" name="forum_signature" maxlength="500" rows="3" placeholder="Shown below your Discuss posts…">{}</textarea> <div class="meta" style="margin:.25rem 0 .75rem">Plain text only. HTML, scripts, and control characters are rejected.</div> "#,
  esc(&name),esc(&c.email),esc(&summary_for_form(&summary)?),esc(&string(p,"forum_signature")?)));
 let raw_badges=p.get("profile_badges").cloned().unwrap_or(Value::Null);
 let decoded=raw_badges.as_str().map(|s|serde_json::from_str::<Value>(s).unwrap_or(Value::Null)).unwrap_or_else(||raw_badges.clone());
 if decoded.is_object(){bail!("unproven badge map");}
 let badges=crate::profile_header::normalize_selected_badges(&raw_badges,false);
 h.push_str(&format!(r#"<details style="border:1px solid var(--border);border-radius:10px;padding:.55rem .75rem;margin:0 0 1rem"> <summary style="cursor:pointer;color:var(--text);font-weight:650">Profile badges <span class="meta">(up to 6 · {} selected)</span></summary> <fieldset style="border:0;padding:.55rem 0 0;margin:0"> <div class="meta" style="margin:0 0 .65rem">Optional flair shown on your HTML profile only. These badges are not federated.</div> <div class="meta" style="margin:0 0 .65rem">Admin and Supporter badges are assigned automatically from your account role and earned Supporter reward. They cannot be selected here.</div> <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(10rem,1fr));gap:.45rem .75rem"> "#,badges.len()));
 for (bkey,label,emoji) in crate::profile_header::BADGES.iter().filter(|b|!matches!(b.0,"admin"|"supporter")){
  h.push_str(&format!(r#"<label style="display:flex;align-items:center;gap:.4rem;margin:0;cursor:pointer"> <input type="checkbox" name="profile_badges[]" value="{}" {}> <span aria-hidden="true">{}</span> <span>{}</span> </label> "#,esc(bkey),checked(badges.iter().any(|b|b==bkey)),esc(emoji),esc(label)));
 }
 h.push_str(&format!(r#"</div> </fieldset> </details> <label for="pf-icon-file">Upload avatar (JPEG / PNG / WebP / GIF · max 2&nbsp;MB)</label> <input id="pf-icon-file" type="file" name="icon_file" accept="image/jpeg,image/png,image/webp,image/gif"> <label for="pf-icon">Or avatar image URL (https)</label> <input id="pf-icon" type="url" name="icon_url" placeholder="https://…" value="{}"> <div class="meta" style="margin:.25rem 0 .75rem">Upload stores on <code>/img/profile/</code> (survives site deploy) and overrides the URL for this save. Federates via ActivityPub <code>Update</code>.</div> <label for="pf-image-file">Upload header / banner (JPEG / PNG / WebP / GIF · max 5&nbsp;MB)</label> <input id="pf-image-file" type="file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif"> <label for="pf-image">Or header / banner image URL (https)</label> <input id="pf-image" type="url" name="image_url" placeholder="https://…" value="{}"> <div class="meta" style="margin:.25rem 0 .75rem">Same storage rules as avatar. Remotes may cache until they process the profile Update (Bridgy: use ↻ Update profile below).</div> <label>Verified creator links / profile fields (up to 8)</label> <div class="meta" style="margin:0 0 .55rem"> Add a website or post URL you control. For a verified creator check, the linked page must include a <code>rel="me"</code> link back to either <code>{}</code> (ActivityPub) or <code>https://mkultra.monster/vaak/users/{}</code> (on-VAAK profile). Ice Cubes / Mastodon show a green check when <code>verified_at</code> is set. </div> "#,
  esc(&icon),esc(&image),esc(&c.actor_id),esc(&query(&c.key))));
 let atts=match p.get("attachment"){Some(Value::Array(a))=>a.clone(),None|Some(Value::Null)=>Vec::new(),_=>bail!("unproven attachment map")};
 static HREF:std::sync::LazyLock<regex::Regex>=std::sync::LazyLock::new(||regex::Regex::new(r#"href=['"](https://[^'"]+)"#).unwrap());
 for i in 0..8{
  let att=atts.get(i).cloned().unwrap_or_else(||json!({"name":"","value":""}));
  if !att.is_object(){bail!("unproven attachment entry");}
  let an=string(&att,"name")?;let av=string(&att,"value")?;
  let mut plain=php_trim(&decode(&strip_tags(&av)?)?).to_string();
  if plain.is_empty(){if let Some(m)=HREF.captures(&av){plain=m[1].to_string();}}
  h.push_str(&format!(r#"<div class="field-row" style="align-items:center"> <input type="text" name="field_name[]" maxlength="64" placeholder="Label" value="{}"> <input type="text" name="field_value[]" maxlength="500" placeholder="Value or https://…" value="{}"> "#,esc(&an),esc(&plain)));
  if !an.is_empty()||!plain.is_empty(){
   if boolean(&att,"verified_at"){h.push_str(&format!(r#"<span class="tag field-status" style="background:var(--primary-dim);color:var(--primary)" title="{}">✓ verified</span> "#,esc(&string(&att,"verified_at")?)));}
   else{h.push_str(r#"<span class="meta field-status" title="No rel=me backlink found yet">unverified</span> "#);}
  }else{h.push_str(r#"<span class="field-status" aria-hidden="true"></span> "#);}
  h.push_str("</div> ");
 }
 let policy=|k:&str|->Result<(String,bool)>{match p.get(k){None|Some(Value::Null)=>Ok(("anyone".into(),true)),Some(Value::String(s))=>Ok((s.clone(),false)),_=>bail!("unproven policy value")}};
 h.push_str(r#"<div class="profile-policy-block"> "#);
 for (k,label) in [("reply_policy","Who can reply"),("quote_policy","Who can quote")]{
  let (v,missing)=policy(k)?;
  h.push_str(&format!(r#"<label class="profile-policy-row">{label} <select name="{k}"> <option value="anyone" {}>Anyone</option> <option value="followers" {}>Followers</option> <option value="nobody" {}>Nobody</option> </select> </label> "#,selected(v=="anyone"),selected(!missing&&v=="followers"),selected(!missing&&v=="nobody")));
 }
 h.push_str(r#"<div class="meta profile-policy-note">These defaults are published with your posts and are enforced for incoming quote requests. You can still choose a post's visibility when composing.</div> </div> "#);
 h.push_str(&format!(r#"<div class="profile-policy-block" style="margin-top:.85rem"> <label class="profile-policy-row"><span><input type="checkbox" name="hide_profile_replies" value="1" {}> Hide replies from my profile</span></label> <label class="profile-policy-row"><span><input type="checkbox" name="hide_profile_boosts" value="1" {}> Hide boosts from my profile</span></label> <div class="meta profile-policy-note">This only changes your public profile. Replies and boosts remain available in VAAK timelines and ActivityPub.</div> </div> "#,checked(boolean(p,"hide_profile_replies")),checked(boolean(p,"hide_profile_boosts"))));
 let default_on=|k:&str|p.get(k).is_none()||boolean(p,k);
 let on=|k:&str|checked(boolean(p,k));
 h.push_str(&format!(r#"<div class="checks"> <label><input type="checkbox" name="algorithm_enabled" value="1" {}> Use Home recommendations and algorithmic ranking</label> <input type="hidden" name="trending_enabled_present" value="1"> <label><input type="checkbox" name="trending_enabled" value="1" {}> Show trending tags and posts in the sidebar</label> <label><input type="checkbox" name="downranking_enabled" value="1" {}> Temporarily downrank posts matching admin-managed harassment vocabulary</label> <label><input type="checkbox" name="asks_enabled" value="1" {}> Allow incoming Wafrn-compatible Asks</label> <label><input type="checkbox" name="webmentions_enabled" value="1" {}> Receive Webmentions on public post notifications</label> <label><input type="checkbox" name="discoverable" value="1" {}> Show in profile directories / discovery</label> <label><input type="checkbox" name="indexable" value="1" {}> Allow fediverse search indexing</label> <label><input type="checkbox" name="manually_approves" value="1" {}> Private account (manually approve followers)</label> <label><input type="checkbox" name="auto_follow_back" value="1" {}> Automatically follow back new followers</label> <label><input type="checkbox" name="auto_unblur_sensitive" value="1" {}> Automatically show sensitive media</label> <label><input type="checkbox" name="auto_delete_posts_7d" value="1" {}> Automatically delete my posts older than 7 days</label> <label><input type="checkbox" name="automated" value="1" {}> Mark this account as automated <span class="automated-badge" aria-hidden="true">🤖</span></label> <label><input type="checkbox" name="collection_consent" value="1" {}> Allow featuring in Collections</label> <label><input type="checkbox" name="vanity_verified" value="1" {}> Vanity verified checkmark <span class="vanity-verified" aria-hidden="true">✓</span></label> </div> "#,
  on("algorithm_enabled"),checked(default_on("trending_enabled")),on("downranking_enabled"),checked(default_on("asks_enabled")),checked(default_on("webmentions_enabled")),on("discoverable"),on("indexable"),on("manually_approves"),on("auto_follow_back"),on("auto_unblur_sensitive"),on("auto_delete_posts_7d"),on("automated"),on("collection_consent"),on("vanity_verified")));
 h.push_str(&format!(r#"<div class="meta" style="margin:.35rem 0 .75rem"> <b style="color:var(--primary)">Home recommendations</b> — when enabled, Home may rank followed activity and add personalized recommendations, RSS, and connected Bluesky content. Turn it off for a simpler chronological Home made from followed Fediverse activity, followed hashtags, and followed Bluesky accounts. Privacy, mute, and block rules always apply. </div> <div class="meta" style="margin:.35rem 0 .75rem"> <b style="color:var(--primary)">Temporary downranking</b> — when enabled, posts matching the administrator’s private vocabulary may be placed lower in Home for a limited time. This is only a soft ranking signal; it does not hide posts or affect your blocks, mutes, DMs, or federation. </div> <div class="meta" style="margin:.35rem 0 .75rem"> <b style="color:var(--primary)">Follow back</b> — when on, accepting a Follow also sends a Follow to them (same as the old single-user habit). Off by default for every account — turn on only if you want that behavior. </div> <div class="meta" style="margin:.35rem 0 .75rem"> <b style="color:var(--primary)">Private account</b> — new followers remain pending until you approve them. Your ActivityPub actor is advertised as locked/private. </div> <div class="meta" style="margin:.35rem 0 .75rem"> <b style="color:var(--primary)">Sensitive media</b> — when enabled, media marked sensitive is shown without the blur gate in your timelines. Content warnings with custom text still remain collapsible. </div> <div class="meta" style="margin:.35rem 0 .75rem"> <b style="color:var(--primary)">Post retention</b> — when enabled, VAAK permanently deletes this account’s local posts after 7 days and sends ActivityPub <code>Delete</code> activities. Off by default; remote posts and other users are never affected. </div> <div class="meta" style="margin:.35rem 0 .75rem"> <b style="color:var(--primary)">Automated account</b> — show a 🤖 next to your name (beside any vanity ✓), an automated-account indicator on your public profile, and the bot flag to compatible remote clients. </div> <div class="meta" style="margin:.35rem 0 .75rem"> <b style="color:var(--primary)">Vanity verified</b> — a silly blue ✓ next to your name on the HTML profile and in Ice Cubes (appended to display name). Not real platform verification — just for the bit. Toggle anytime. </div> <div class="meta" style="margin:.35rem 0 .75rem"> <b style="color:var(--primary)">Discovery</b> — <em>directories</em> controls whether remotes may list you in profile directories / suggestions (<code>discoverable</code>). <em>Search indexing</em> sets ActivityPub/Mastodon <code>indexable</code> (public posts eligible for fediverse full-text / search indexes). Turn either off if you want to stay harder to find. </div> <div class="meta" style="margin:.35rem 0 .75rem"> <b style="color:var(--primary)">Collections consent</b> — when on, others may include <code>{}</code> in starter-pack style Collections (FEP-7aa9 <code>canFeature</code>). When off, your actor advertises an explicit opt-out and new “you’re featured in” imports are rejected. </div> <div class="composer-actions"> <span class="meta">Saves to the shared database and sends an ActivityPub <code>Update</code> (no <code>movedTo</code>). Handle stays <b>{}</b>.</span> <a class="btn btn-ghost" href="/users/{}">View public profile</a> <button class="btn btn-primary" type="submit">Save profile</button> </div> </form>"#,
  esc(&handle),esc(&c.username),esc(&query(&c.key))));
 // h() runs ap_fix_utf8 on every value it prints; text it would repair stays PHP.
 if mojibake(&h){bail!("PHP would repair printed text");}
 let rows=json!([{"actor_key":c.key,"source":c.source}]);
 Ok((h,1,rows))
}
#[cfg(test)]mod tests{
 use super::*;
 #[test]fn entities_follow_php_or_refuse(){
  assert_eq!(decode("a & b &amp; &#39;&#x27;&apos; &#128512; &nbsp;x &unknownish").unwrap(),"a & b & ''' 😀 \u{a0}x &unknownish");
  for s in["&hellip;","&#0;","&#x80;","&#xFFFE;"]{assert!(decode(s).is_err(),"{s}");}
 }
 #[test]fn tags_strip_only_when_well_formed(){
  assert_eq!(strip_tags(r#"<a href="x>y">kept</a><br/>"#).unwrap(),"kept");
  for s in["I <3 cats","a <!-- c --> b","open <a","<a title=\"\\\"\">","stray >"]{assert!(strip_tags(s).is_err(),"{s}");}
 }
 #[test]fn summary_keeps_rich_html_and_flattens_plain(){
  assert_eq!(summary_for_form("<p>x <a href=\"/\">y</a></p>").unwrap(),"<p>x <a href=\"/\">y</a></p>");
  assert_eq!(summary_for_form("<p>One</p><p>Two<br>three</p>\n\n\n\n<p> &amp; </p>").unwrap(),"One\n\nTwo\nthree\n\n &");
 }
}
