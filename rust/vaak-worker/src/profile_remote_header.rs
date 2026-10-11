//! Remote AP header metadata is presentation only. Target/source authority is
//! retained in the separate native remote session and selected source ledger.
use super::*;
pub(crate) trait RemoteApHeaderSession:RemoteApSourceSession{fn csrf(&self)->&str;}
#[derive(Default)]
pub(crate) struct RemoteHeaderRequest{pub(crate) from:String,pub(crate) refresh:bool,pub(crate) intent:String}
pub(crate) struct RemoteHeaderFacts{
 requested:String,canonical:String,owner:i64,own_actor:String,csrf:String,tab:String,from:String,intent:String,
 metadata:Value,avatar:Value,header:Value,bio:String,fields:Vec<Value>,needs_warm:bool,
 following:bool,follows_you:bool,display:String,handle:String,fallback_avatar:String,web:String,emoji:std::collections::HashMap<String,String>,moderation:crate::library::actions::ModerationContext,catalog_env:Option<String>,catalog_files:Vec<(std::path::PathBuf,Option<Vec<u8>>)>,ledger:ProjectionLedger,effects:Vec<ProjectionEffect>,counts:[usize;4],
}
impl RemoteHeaderFacts{
 pub(crate) fn actor(&self)->&str{&self.canonical}
 pub(crate) fn requested_actor(&self)->&str{&self.requested}
 pub(crate) fn effects(&self)->&[ProjectionEffect]{&self.effects}
 pub(crate) async fn require_current(&self,db:&Client,session:&dyn RemoteApHeaderSession,selected:&SelectedRemoteApPage)->Result<()>{
  if session.owner_id()!=self.owner||session.own_actor()!=self.own_actor||session.csrf()!=self.csrf||selected.requested_actor()!=self.requested||selected.canonical_actor()!=self.canonical||selected.tab()!=self.tab{bail!("remote header source/session binding changed")}
  if std::env::var("VAAK_WAFRN_FRIEND_HOSTS").ok()!=self.catalog_env||self.catalog_files.iter().any(|(path,bytes)|std::fs::read(path).ok()!=*bytes){bail!("remote header trusted catalog changed")};
  selected.require_current(db,session).await?;if crate::profile_context::root_content_blocked_for(db,self.owner,&self.canonical).await?{bail!("PHP fallback: canonical remote header currently blocked")};self.ledger.require_current(db).await?;session.require_current(db).await
 }
 #[cfg(test)] fn presentation(&self)->Value{json!({"metadata":self.metadata,"avatar":self.avatar,"header":self.header,"bio":self.bio,"fields":self.fields,"needs_warm":self.needs_warm,"following":self.following,"follows_you":self.follows_you,"canonical":self.canonical,"tab":self.tab,"from":self.from})}
}
pub(crate) async fn resolve_remote_header(db:&Client,session:&dyn RemoteApHeaderSession,selected:&SelectedRemoteApPage,request:&RemoteHeaderRequest)->Result<RemoteHeaderFacts>{
 selected.require_current(db,session).await?;if session.owner_id()>0&&session.csrf().is_empty(){bail!("PHP fallback: trusted remote header CSRF missing")}
 let requested=selected.requested_actor();let actor=selected.canonical_actor();
 if crate::profile_context::root_content_blocked_for(db,session.owner_id(),actor).await?{bail!("PHP fallback: canonical remote header currently blocked")}
 let mut deps=vec![];
 async fn read(db:&Client,deps:&mut Vec<Dependency>,sql:&'static str,key:Value)->Result<Vec<Value>>{let rows=Dependency::read(db,sql,&key).await?;deps.push(Dependency::new(sql,key,rows.clone()));Ok(rows)}
 let metadata=read(db,&mut deps,"SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id=$1::jsonb->>'actor' OR actor_id=($1::jsonb->>'actor')||'/' LIMIT 1",json!({"actor":requested})).await?.into_iter().next().unwrap_or(Value::Null);
 let media=read(db,&mut deps,"SELECT to_jsonb(m) FROM remote_media_cache m WHERE actor_id=$1::jsonb->>'actor' AND kind IN('avatar','header') ORDER BY kind",json!({"actor":requested})).await?;
 let slot=|kind:&str|media.iter().find(|row|s(row,"kind")==kind);
 let avatar=slot("avatar").map(|row|row["public_url"].clone()).filter(truth).unwrap_or_else(||metadata["icon_source_url"].clone());
 let header=slot("header").map(|row|row["public_url"].clone()).filter(truth).or_else(||truth(&metadata["image_source_url"]).then(||metadata["image_source_url"].clone())).unwrap_or_else(||avatar.clone());
 let user=crate::library::card::php_trim(s(&metadata,"username"));let updated=chrono::DateTime::parse_from_rfc3339(s(&metadata,"updated_at")).ok().map(|v|v.timestamp()).unwrap_or(0);
 let needs_warm=metadata.is_null()||user.is_empty()||crate::profile_identity::placeholder(user)||updated<chrono::Utc::now().timestamp()-12*3600||request.refresh;
 let summary=crate::library::card::php_trim(s(&metadata,"summary"));let bio=if summary.is_empty(){String::new()}else{crate::library::body::html_to_plain(summary)};
 let fields=if metadata["profile_json"].is_string(){serde_json::from_str::<Value>(s(&metadata,"profile_json")).unwrap_or(Value::Null)}else{metadata["profile_json"].clone()};
 if fields.is_object(){bail!("PHP fallback: associative remote profile fields require exact iteration")}
 let fields=fields.as_array().cloned().unwrap_or_default();
 let key=json!({"viewer":session.own_actor().trim_end_matches('/'),"actor":actor.trim_end_matches('/')});
 let following=!read(db,&mut deps,"SELECT to_jsonb(f) FROM following f WHERE owner_actor_id IN($1::jsonb->>'viewer',($1::jsonb->>'viewer')||'/') AND actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') LIMIT 1",key.clone()).await?.is_empty();
 let follows_you=!read(db,&mut deps,"SELECT to_jsonb(f) FROM followers f WHERE owner_actor_id IN($1::jsonb->>'viewer',($1::jsonb->>'viewer')||'/') AND actor_id IN($1::jsonb->>'actor',($1::jsonb->>'actor')||'/') LIMIT 1",key).await?.is_empty();
 let canonical_meta=read(db,&mut deps,"SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id=$1::jsonb->>'actor' OR actor_id=($1::jsonb->>'actor')||'/' LIMIT 1",json!({"actor":actor})).await?.into_iter().next();
 let label=crate::profile_identity::label(actor,canonical_meta.as_ref());let host=reqwest::Url::parse(actor)?.host_str().unwrap_or("").to_ascii_lowercase();
 if host.ends_with("brid.gy")||host=="bsky.app"||host=="threads.net"{bail!("PHP fallback: remote header protocol web URL metadata incomplete")}
 let display=if truth(&metadata["display_name"]){crate::library::card::scalar_string(&metadata,"display_name")}else{s(&label,"handle").into()};
 let supplied=crate::library::card::php_trim(s(&metadata,"username")).trim_start_matches('@').split('@').next().unwrap_or("");
 let handle=if !supplied.is_empty()&&!crate::profile_identity::placeholder(supplied){format!("@{supplied}@{host}")}else{s(&label,"handle").into()};
 let username=if supplied.is_empty(){s(&label,"username")}else{supplied};let web=if !username.is_empty()&&username!="user"&&!crate::profile_identity::placeholder(username){format!("https://{host}/@{}",crate::library::actions::query(username))}else{actor.into()};
 let mut fallback_avatar="https://mkultra.monster/img/avatar/default.webp".to_owned();
 if !truth(&avatar){
  let aliases=effects::aliases(db,&mut deps,actor).await?;for alias in std::iter::once(actor.to_owned()).chain(aliases.into_iter().filter(|alias|alias.trim_end_matches('/')!=actor)){
   let cached=read(db,&mut deps,"SELECT to_jsonb(m) FROM remote_media_cache m WHERE actor_id=$1::jsonb->>'actor' AND kind='avatar' LIMIT 1",json!({"actor":alias.trim_end_matches('/')})).await?;
   if let Some(url)=cached.first().and_then(|r|crate::profile_identity::sanitized_profile_url(s(r,"public_url"))){fallback_avatar=url;break;}
  }
  if fallback_avatar.ends_with("/default.webp"){if let Some(url)=canonical_meta.as_ref().and_then(|row|crate::profile_identity::sanitized_profile_url(s(row,"icon_source_url"))){fallback_avatar=url;}}
 }
 let mut emoji=std::collections::HashMap::new();for row in read(db,&mut deps,"SELECT to_jsonb(e) FROM remote_custom_emojis e WHERE host=$1::jsonb->>'host' ORDER BY shortcode",json!({"host":host})).await?{if !s(&row,"shortcode").is_empty()&&s(&row,"url").starts_with("https://"){emoji.insert(s(&row,"shortcode").into(),s(&row,"url").into());}}
 let owners=read(db,&mut deps,"SELECT to_jsonb(u) FROM ap_users u WHERE id=($1::jsonb->>'owner')::bigint LIMIT 1",json!({"owner":session.owner_id()})).await?;
 let mut moderation=crate::library::actions::ModerationContext{is_admin:owners.first().is_some_and(|u|truth(&u["is_admin"])),..Default::default()};
 if session.owner_id()>0{
 let blocks=read(db,&mut deps,"SELECT to_jsonb(b) FROM ap_user_blocks b WHERE owner_user_id=($1::jsonb->>'owner')::bigint ORDER BY created_at DESC,id DESC",json!({"owner":session.owner_id()})).await?;moderation.personal_block_id=crate::library::facts::personal_block(&blocks,actor).map(|row|i(row,"id"));
 for (sql,is_mute) in [("SELECT to_jsonb(m) FROM ap_mutes m WHERE owner_user_id=($1::jsonb->>'owner')::bigint ORDER BY actor_id",true),("SELECT to_jsonb(m) FROM ap_deprioritized_actors m WHERE owner_user_id=($1::jsonb->>'owner')::bigint ORDER BY actor_id",false)]{
 let rows=read(db,&mut deps,sql,json!({"owner":session.owner_id()})).await?;let matches=rows.iter().any(|row|if is_mute{crate::hidden::actor_aliases(s(row,"actor_id")).iter().any(|a|crate::hidden::actor_aliases(actor).contains(a))}else{s(row,"actor_id").trim_end_matches('/')==actor.trim_end_matches('/')});if is_mute{moderation.muted=matches}else{moderation.deprioritized=matches}
 }
 }
 let controls=read(db,&mut deps,"SELECT to_jsonb(b) FROM ap_blocks b ORDER BY created_at DESC,id DESC",json!({})).await?;
 for row in controls.iter().filter(|r|s(r,"scope")=="actor"){if crate::hidden::actor_aliases(s(row,"value")).iter().any(|a|crate::hidden::actor_aliases(actor).contains(a)){moderation.global_kind=s(row,"kind").into();moderation.global_id=i(row,"id");}}
 let catalog_env=std::env::var("VAAK_WAFRN_FRIEND_HOSTS").ok();let catalog_files=[std::path::PathBuf::from("/var/lib/mkultra/ap/wafrn-friend-hosts.txt"),std::path::PathBuf::from(env!("CARGO_MANIFEST_DIR")).join("../../api/deploy/wafrn-friend-hosts.txt")].into_iter().map(|path|{let bytes=std::fs::read(&path).ok();(path,bytes)}).collect::<Vec<_>>();
 read(db,&mut deps,"SELECT to_jsonb(f) FROM ap_wafrn_friend_servers f WHERE COALESCE(enabled,0)::bigint=1 ORDER BY host ASC",json!({})).await?;
 moderation.is_wafrn=crate::library::facts::wafrn_host_known(db,actor).await?;
 let mut effects=vec![];if needs_warm{effects.push(ProjectionEffect::ActorWarm(requested.into()))}if slot("avatar").is_none()||slot("header").is_none(){effects.push(ProjectionEffect::MediaWarm(requested.into()))}
 let from=request.from.chars().filter(|c|c.is_ascii_lowercase()||*c=='_').collect();
 let result=RemoteHeaderFacts{requested:requested.into(),canonical:actor.into(),owner:session.owner_id(),own_actor:session.own_actor().into(),csrf:session.csrf().into(),tab:selected.tab().into(),from,intent:request.intent.clone(),metadata,avatar,header,bio,fields,needs_warm,following,follows_you,display,handle,fallback_avatar,web,emoji,moderation,catalog_env,catalog_files,ledger:ProjectionLedger{dependencies:deps,static_counts:vec![]},effects,counts:[selected.bucket_count("posts"),selected.bucket_count("replies"),selected.bucket_count("boosts"),selected.bucket_count("media")]};
 result.require_current(db,session,selected).await?;Ok(result)
}
fn header_html(f:&RemoteHeaderFacts)->String{
 use crate::library::{actions as a,actions::{FediActionContext,Counts}};
 let h=a::esc;let q=a::query;let form_q=|value:&str|q(value).replace("%20","+").replace('~',"%7E");let mut out=String::new();
 let (view,label,soft)=match f.from.as_str(){"mentions"=>("mentions","← Notifications","mentions"),"search"=>("search","← Search","search"),"followers"=>("followers","← Followers","followers"),"home"=>("home","← Home","home"),"feed"=>("feed","← Federated","feed"),"blocks"=>("blocks","← Blocks & mutes",""),"about"=>("about","← About VAAK",""),_=>("following","← Following","following")};
 if f.owner>0{out.push_str(&format!("<div class=\"page-back\"><a class=\"btn btn-ghost\" href=\"?view={view}\"{}>{label}</a><a class=\"btn btn-ghost\" href=\"?view=remote_profile&amp;actor={}&amp;from={}&amp;refresh=1\" title=\"Queue a background profile and post refresh\">↻ Refresh profile</a></div>",if soft.is_empty(){String::new()}else{format!(" data-vaak-soft-nav=\"{soft}\"")},form_q(&f.canonical),form_q(&f.from)));}
 if !f.following&&f.intent=="follow"{out.push_str("<div class=\"notice\" style=\"margin:0 0 .75rem;padding:.65rem .8rem;border:1px solid var(--border);border-radius:10px;background:var(--panel-2)\">Remote follow request — confirm with <b>Follow</b> below to follow this account from your VAAK actor.</div>");}
 if truth(&f.header){out.push_str(&format!("<div style=\"height:120px;border-radius:12px;overflow:hidden;margin-bottom:.75rem;background:#111\"><img src=\"{}\" alt=\"\" style=\"width:100%;height:100%;object-fit:cover\" loading=\"lazy\" referrerpolicy=\"no-referrer\"></div>",h(f.header.as_str().unwrap_or(""))));}
 out.push_str("<article class=\"tweet\"><div class=\"tweet-hd remote-profile-hd\"><div class=\"remote-profile-hd-main\">");
 if truth(&f.avatar){out.push_str(&format!("<img src=\"{}\" alt=\"\" width=\"64\" height=\"64\" style=\"border-radius:50%;object-fit:cover\" loading=\"lazy\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='https://mkultra.monster/img/avatar/default.webp'\">",h(f.avatar.as_str().unwrap_or(""))));}
 else{out.push_str(&format!("<img class=\"tweet-av\" src=\"{}\" alt=\"\" width=\"40\" height=\"40\" data-profile-hover-actor=\"{}\" loading=\"lazy\" decoding=\"async\" referrerpolicy=\"no-referrer\" onerror=\"this.onerror=null;this.src='https://mkultra.monster/img/avatar/default.webp'\">",h(&f.fallback_avatar),h(&f.canonical)));}
 out.push_str(&format!("<div style=\"min-width:0\"><div class=\"who\">{}</div><div class=\"meta\">{}</div></div></div></div>",crate::library::body::emojis(&f.display,&f.emoji,true),h(&f.handle)));
 if f.owner==0{out.push_str("<div class=\"remote-profile-guest-follow\"><button type=\"button\" class=\"btn btn-primary\" id=\"rp-guest-follow-toggle\" aria-expanded=\"false\" aria-controls=\"rp-guest-follow-panel\">Follow</button><div class=\"guest-follow-panel\" id=\"rp-guest-follow-panel\" hidden><form id=\"rp-guest-follow-form\" action=\"#\" method=\"get\"><label for=\"rp-guest-follow-handle\">Follow from your fediverse account</label><div class=\"guest-follow-row\"><input id=\"rp-guest-follow-handle\" name=\"handle\" type=\"text\" inputmode=\"email\" autocomplete=\"username\" spellcheck=\"false\" placeholder=\"@you@your.instance\" required><button type=\"submit\">Go</button></div><p class=\"guest-follow-hint\" id=\"rp-guest-follow-hint\">Opens your instance’s follow dialog (Mastodon, Akkoma, GoToSocial, etc.).</p></form></div></div><script>");out.push_str(&include_str!("profile_guest_follow.js").replace("__NATIVE_ACTOR_JSON__",&serde_json::to_string(&f.canonical).unwrap()));out.push_str("</script>");}
 if !f.bio.is_empty(){out.push_str(&format!("<div class=\"body\" style=\"margin-top:.75rem;white-space:pre-wrap\">{}</div>",h(&f.bio)));}else{out.push_str("<div class=\"meta\" style=\"margin-top:.75rem\">No bio is cached yet; profile details are being refreshed in the background.</div>");}
 out.push_str(&crate::profile_header::fields_html(&f.fields));out.push_str("<div class=\"tweet-actions remote-profile-actions\" style=\"flex-wrap:wrap;align-items:center;gap:.45rem\">");
 let hidden=|name:&str,value:&str|format!("<input type=\"hidden\" name=\"{name}\" value=\"{}\">",h(value));
 let post=|action:&str,attrs:&str,extra:&str,button:&str,actor_field:bool|format!("<form method=\"post\" action=\"?view=remote_profile&amp;actor={}&amp;from={}\" style=\"display:inline\"{attrs}>{}{}{}{}{}{extra}{button}</form>",form_q(&f.canonical),form_q(&f.from),hidden("action",action),hidden("return_view","remote_profile"),hidden("return_actor",&f.canonical),hidden("return_from",&f.from),if actor_field{hidden("actor_id",&f.canonical)}else{String::new()});
 if f.owner>0{
 out.push_str("<span class=\"remote-profile-follow-inline\">");if f.following{out.push_str(&post("unfollow_remote"," onsubmit=\"return confirm('Unfollow this account?');\"","","<button class=\"btn btn-following\" type=\"submit\" title=\"Following — click to unfollow\">Following</button>",true));}else{out.push_str(&post("follow_remote"," data-follow-locked=\"0\"","",&format!("<button class=\"btn btn-primary\" type=\"submit\">{}</button>",if f.follows_you{"Follow back"}else{"Follow"}),true));}
 out.push_str(&format!("<span id=\"remote-profile-rel-state\" data-following=\"{}\" data-followed-by=\"{}\">",u8::from(f.following),u8::from(f.follows_you)));if f.following&&f.follows_you{out.push_str("<span class=\"tag\" title=\"You follow each other\">mutuals</span>");}else if f.follows_you{out.push_str("<span class=\"tag\" title=\"This account follows you\">follows you</span>");}out.push_str("</span></span>");
 }
 if f.moderation.muted{out.push_str("<span class=\"tag\" title=\"Hidden from your timelines &amp; notifications\">muted for me</span>");}
 if f.owner>0{
 out.push_str(&post("bite_remote"," onsubmit=\"return confirm('Bite this account? (Wafrn-compatible 🦷)');\"",&(hidden("bite_kind","user")+&hidden("target",&f.canonical)),"<button class=\"btn btn-ghost\" type=\"submit\" title=\"Wafrn-compatible bite\"><i class=\"ph ph-tooth\" aria-hidden=\"true\"></i> Bite</button>",false));
 let ctx=FediActionContext{owner_id:f.owner,status_id:String::new(),object_id:String::new(),actor_id:f.canonical.clone(),remote_url:f.web.clone(),return_view:"remote_profile".into(),reply_mentions:vec![],spoiler:String::new(),sensitive:false,favourited:false,bookmarked:false,boosted:false,counts:Counts::default(),csrf:f.csrf.clone(),moderation:f.moderation.clone()};out.push_str(&a::render_moderation_with_from(&ctx,&f.from));
 out.push_str(&format!("<a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\">Open remote</a><a href=\"{}\" target=\"_blank\" rel=\"noopener noreferrer\" title=\"Opens their instance’s profile — use Follow Remotely there with your @user@mkultra.monster address\">Follow on their instance</a>",h(&f.web),h(&f.web)));
 }
 out.push_str("</div></article><nav class=\"remote-profile-tabs\" aria-label=\"Profile posts\"><button type=\"button\" class=\"remote-profile-tabs-scroll-btn\" data-profile-tabs-scroll=\"left\" aria-label=\"Scroll profile tabs left\">&lt;</button><div class=\"remote-profile-tabs-scroll\">");
 for(index,(tab,label))in[("posts","Posts"),("replies","Replies"),("boosts","Boosts"),("media","Media")].iter().enumerate(){out.push_str(&format!("<a href=\"?view=remote_profile&amp;actor={}&amp;from={}&amp;tab={tab}\" class=\"{}\"{}>{label}<span class=\"tab-count\">{}</span></a>",q(&f.canonical),q(&f.from),if f.tab==*tab{"is-active"}else{""},if f.tab==*tab{" aria-current=\"page\""}else{""},f.counts[index]));}out.push_str("</div><button type=\"button\" class=\"remote-profile-tabs-scroll-btn\" data-profile-tabs-scroll=\"right\" aria-label=\"Scroll profile tabs right\">&gt;</button></nav>");out
}
#[cfg(test)]
mod tests{
 use super::*;
 struct Session{owner:i64,actor:String,own:String,user:Option<Value>}
 impl RemoteApSourceSession for Session{
 fn requested_actor(&self)->&str{&self.actor}fn owner_id(&self)->i64{self.owner}fn own_actor(&self)->&str{&self.own}
 fn require_current<'a>(&'a self,db:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async move{if self.owner>0{let row=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&self.owner]).await?.map(|r|r.get::<_,Value>(0));if row!=self.user{bail!("remote header viewer withdrawn")}}Ok(())})}
 }
 impl RemoteApHeaderSession for Session{fn csrf(&self)->&str{if self.owner>0{"fixture-csrf-token"}else{""}}}
 #[tokio::test]
 #[ignore="actual PHP remote header metadata/source binding, SQL snapshots and canonical policy; DOM/effect execution separate"]
 async fn actual_remote_header_metadata(){
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(connection.contains("/tmp/"));let db=crate::db::connect(&connection).await.unwrap();let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("REMOTE_AP_HEADER_REFERENCE").unwrap_or_else(|_|"/tmp/vaak-remote-profile-ap-header-reference.json".into())).unwrap()).unwrap();let mut keys=std::collections::HashSet::new();let mut matches=0;let mut denied=0;let mut negatives=0;let mut output=vec![];
 for width in ["INTEGER","BIGINT"]{
 let schema=format!("remote_header_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
 for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|column|format!("{} {}",s(column,"column_name"),s(column,"data_type"))).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
 for capture in reference["captures"].as_array().unwrap().iter().filter(|capture|capture["width"]==width){assert!(keys.insert((width.to_owned(),s(capture,"case").to_owned(),i(capture,"owner"))));
 for(table,rows)in capture["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
 let owner=i(capture,"owner");let user=if owner>0{db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint",&[&owner]).await.unwrap().map(|r|r.get::<_,Value>(0))}else{None};let session=Session{owner,actor:s(&capture["request"],"actor").into(),own:s(&capture["context"],"own_actor").into(),user};
 let selected=select_remote_ap(&db,&session,s(&capture["request"],"tab")).await.unwrap();let request=RemoteHeaderRequest{from:s(&capture["request"],"from").into(),refresh:s(&capture["request"],"refresh")=="1",intent:s(&capture["request"],"intent").into()};let result=resolve_remote_header(&db,&session,&selected,&request).await;
 if truth(&capture["php"]["blocked"]){assert!(result.is_err(),"blocked {} {} {}",width,capture["case"],owner);output.push(json!({"width":width,"case":capture["case"],"owner":owner,"error":"current canonical root blocked"}));denied+=1;continue}
 let facts=result.unwrap();let native=facts.presentation();output.push(json!({"width":width,"case":capture["case"],"owner":owner,"html":header_html(&facts),"metadata":native}));for(key,value)in native.as_object().unwrap(){assert_eq!(value,&capture["php"][key],"{width} {} owner{owner} field {key}",capture["case"]);}matches+=1;
 facts.require_current(&db,&session,&selected).await.unwrap();let wrong=Session{owner,actor:format!("{}/other",session.actor),own:session.own.clone(),user:session.user.clone()};assert!(facts.require_current(&db,&wrong,&selected).await.is_err());negatives+=1;
 db.execute("UPDATE remote_actors SET summary=COALESCE(summary,'')||' withdrawal' WHERE actor_id=$1",&[&session.actor]).await.unwrap();if capture["php"]["metadata"].is_object(){assert!(facts.require_current(&db,&session,&selected).await.is_err());negatives+=1;}
 }
 db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 assert_eq!(keys.len(),132);assert_eq!(keys.len(),reference["captures"].as_array().unwrap().len());std::fs::write(std::env::var("REMOTE_AP_HEADER_NATIVE_OUTPUT").unwrap_or_else(|_|"/tmp/vaak-remote-header-native.json".into()),serde_json::to_vec(&json!({"captures":output})).unwrap()).unwrap();println!("remote header exact metadata {matches}, explicit blocked fallback {denied}, binding/dependency negatives {negatives}");
 }
}
