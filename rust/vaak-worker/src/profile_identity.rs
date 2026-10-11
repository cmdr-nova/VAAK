//! Warm-aware identity for ap_ask_actor_identity / ap_remote_actor_label.
//! These are presentation/cache helpers, never source or audience authority.
//! Callers must establish a native source grant before enabling warming.
use anyhow::Result;
use serde_json::{json,Value};
use sha2::{Digest,Sha256};
use std::time::Duration;
use tokio_postgres::Client;
use crate::{config::Config,profile_enrichment::WarmContext,redis_util,library::card};
const FIELDS:[&str;9]=["actor_id","username","display_name","host","icon_source_url","image_source_url","updated_at","summary","profile_json"];
fn string(value:&Value)->String{match value{Value::String(v)=>v.clone(),Value::Null=>String::new(),Value::Bool(v)=>if *v{"1".into()}else{String::new()},Value::Number(n)=>n.to_string(),_=>"Array".into()}}
fn field(value:&Value,key:&str)->String{string(&value[key])}
fn normalize(raw:&str)->Option<String>{let raw=card::php_trim(card::php_trim(raw).trim_start_matches('@').split('@').next().unwrap_or(""));(!raw.is_empty()).then(||raw.into())}
pub(crate) fn placeholder(raw:&str)->bool{let raw=card::php_trim(raw).trim_start_matches('@');raw.is_empty()||raw=="user"||raw.to_ascii_lowercase().starts_with("did:")||raw.to_ascii_lowercase().starts_with("did%3a")||(raw.len()>=6&&raw.bytes().all(|b|b.is_ascii_digit()))}
fn flat_key(actor:&str)->String{format!("vaak:actor:v1:ap:{}",hex::encode(Sha256::digest(actor.to_ascii_lowercase().as_bytes())))}
fn flat_row(flat:&Value)->Value{let mut row=json!({});for key in FIELDS{row[key]=flat.get(key).cloned().unwrap_or(Value::Null);}row["actor_id"]=json!(field(flat,"actor_id").trim_end_matches('/'));row}
fn flat_from_row(row:&Value)->Value{let mut flat=json!({});for key in FIELDS{flat[key]=if row[key].is_null(){Value::Null}else{json!(field(row,key))};}flat["actor_id"]=json!(field(row,"actor_id").trim_end_matches('/'));flat}
async fn cache(cfg:&Config)->Option<redis::aio::MultiplexedConnection>{
 if std::env::var("VAAK_REDIS_ENABLED").is_ok_and(|v|matches!(card::php_trim(&v).to_ascii_lowercase().as_str(),"0"|"false"|"off"|"no")){return None;}
 tokio::time::timeout(Duration::from_millis(80),redis_util::connect(&cfg.redis_url)).await.ok()?.ok()
}
/// Actual flat Redis precedence, followed by exact/base-slash durable PG.
/// PG hits refresh the same flat cache for 2700 seconds. Cached presentation
/// identity is not evidence that a post or actor is allowed for this viewer.
pub(crate) async fn lookup_actor(db:&Client,cfg:&Config,raw:&str)->Result<Option<Value>>{
 let actor=card::php_trim(raw).trim_end_matches('/');if actor.is_empty(){return Ok(None);}
 let mut conn=if actor.starts_with("https://"){cache(cfg).await}else{None};
 if let Some(conn)=conn.as_mut(){
  if let Ok(Ok(Some(flat)))=tokio::time::timeout(Duration::from_millis(80),redis_util::json_get(conn,&flat_key(actor))).await{
   if flat.is_object()&&!field(&flat,"actor_id").trim_end_matches('/').is_empty(){return Ok(Some(flat_row(&flat)));}
  }
 }
 let row=db.query_opt("SELECT to_jsonb(a) FROM remote_actors a WHERE actor_id=$1 OR actor_id=$2 LIMIT 1",&[&actor,&format!("{actor}/")]).await?.map(|r|r.get::<_,Value>(0));
 if let(Some(row),Some(conn))=(&row,conn.as_mut()){
  if !field(row,"actor_id").trim_end_matches('/').is_empty(){
   let flat=flat_from_row(row);let _=tokio::time::timeout(Duration::from_millis(80),redis_util::json_set(conn,&flat_key(actor),&flat,2700)).await;
   let rid=field(&flat,"actor_id");if !rid.is_empty()&&rid!=actor{let _=tokio::time::timeout(Duration::from_millis(80),redis_util::json_set(conn,&flat_key(&rid),&flat,2700)).await;}
  }
 }
 Ok(row)
}
// Preserve PHP parse_url's raw path bytes: URL normalization would collapse
// dot segments and decode/repair actor path identity before basename selection.
fn host_path(actor:&str)->(String,String){
 let rest=actor.split_once("://").map(|(_,r)|r).unwrap_or(actor);
 let boundary=rest.find(['/', '?','#']).unwrap_or(rest.len());let auth=rest[..boundary].rsplit('@').next().unwrap_or("");
 let host=if auth.starts_with('['){auth.find(']').map(|i|&auth[..=i]).unwrap_or("")}else{auth.split(':').next().unwrap_or("")};
 let path=if rest[boundary..].starts_with('/'){rest[boundary..].split(['?','#']).next().unwrap_or("")}else{""};
 (host.to_ascii_lowercase(),path.trim_matches('/').into())
}
pub(crate) fn sanitized_profile_url(raw:&str)->Option<String>{
 let icon=card::php_trim(raw);let(host,_)=host_path(icon);
 let authority=icon.strip_prefix("https://").unwrap_or("").split(['/', '?','#']).next().unwrap_or("");
 let private=host.parse::<std::net::Ipv4Addr>().is_ok_and(|ip|{let[a,b,_,_]=ip.octets();a==0||a==10||a==127||a>=240||(a==169&&b==254)||(a==172&&(16..=31).contains(&b))||(a==192&&b==168)});
 let port_bad=if authority.starts_with('['){authority.find(']').and_then(|i|authority[i+1..].strip_prefix(':'))}else{authority.split_once(':').map(|(_,p)|p)}.is_some_and(|p|{
  if p.is_empty(){return false;}if p.len()>5||p.contains(':'){return true;}
  let digits=p.trim_start_matches(['+','-']).bytes().take_while(u8::is_ascii_digit).count();let sign=usize::from(p.starts_with('+')||p.starts_with('-'));
  digits==0||p[..sign+digits].parse::<i32>().map_or(true,|p|!(0..=65535).contains(&p))
 });
 if !icon.starts_with("https://")||icon.len()>800||host.is_empty()||authority.contains('@')||icon.bytes().any(|b|b<32||b==127)||matches!(host.as_str(),"localhost"|"127.0.0.1"|"::1"|"[::1]")||private||port_bad{None}else{Some(icon.into())}
}
pub(crate) fn local_avatar(raw:&str)->String{sanitized_profile_url(raw).unwrap_or_else(||"https://mkultra.monster/img/avatar/local-default.webp".into())}
pub(crate) fn label(actor:&str,row:Option<&Value>)->Value{
 let(mut host,path)=host_path(actor);let seg=path.rsplit('/').next().filter(|v|!v.is_empty()&&*v!="0").unwrap_or("user");
 let mut username=normalize(seg).unwrap_or_else(||"user".into());let mut display=username.clone();
 if let Some(row)=row{
  if let Some(user)=normalize(&field(row,"username")).filter(|v|!placeholder(v)){username=user;}
  let name=field(row,"display_name");display=if card::boolean(row,"display_name")&&!card::php_trim(&name).is_empty(){name}else{username.clone()};
  if card::boolean(row,"host"){host=field(row,"host").to_ascii_lowercase();}
 }
 username=normalize(&username).unwrap_or_else(||"user".into());let interim=placeholder(&username);
 if interim{username="user".into();if display.is_empty()||placeholder(&display){display=if host.is_empty(){"user".into()}else{host.clone()};}}
 let(handle,acct)=if !host.is_empty()&&(host.ends_with("brid.gy")||host=="brid.gy")&&username.contains('.')&&!username.to_ascii_lowercase().starts_with("did:")&&!interim{(format!("@{username}"),format!("{username}@{host}"))}
 else if interim&&!host.is_empty(){(format!("@{host}"),host.clone())}
 else if host.is_empty(){(format!("@{username}"),username.clone())}else{(format!("@{username}@{host}"),format!("{username}@{host}"))};
 json!({"username":username,"display_name":display,"host":host,"handle":handle,"acct":acct})
}
pub(crate) async fn ask_identity(db:&Client,context:&WarmContext,mut ask:Value)->Result<Value>{
 let actor=card::php_trim(&field(&ask,"asker_actor")).trim_end_matches('/').to_owned();let fallback="https://mkultra.monster/img/avatar/default.webp";
 let(display,handle,avatar):(String,String,String)=if !actor.starts_with("https://"){
  ask["asker_actor"]=json!("");("Anonymous".into(),String::new(),fallback.into())
 }else if let Some(row)=db.query_opt("SELECT u.actor_key,p.name,p.icon_url FROM ap_users u LEFT JOIN actor_profile p ON p.actor_key=u.actor_key WHERE $2 AND u.disabled_at IS NULL AND (u.actor_id=$1 OR u.actor_id=$3 OR lower(u.actor_key)=lower($4)) LIMIT 1",&[&actor,&actor.starts_with("https://mkultra.monster/users/"),&format!("{actor}/"),&crate::hidden::raw_percent_decode(host_path(&actor).1.rsplit('/').next().unwrap_or(""))]).await?{
  let key:String=row.get(0);let name=row.get::<_,Option<String>>(1).unwrap_or_default();let icon=row.get::<_,Option<String>>(2).unwrap_or_default();
  let display=if !name.is_empty()&&name!="0"{name}else if key.is_empty(){"Local user".into()}else{key.clone()};
  let avatar=local_avatar(&icon);
  (card::php_trim(&display).into(),if key.is_empty(){String::new()}else{format!("@{key}@mkultra.monster")},avatar)
 }else{
  let row=lookup_actor(db,context.cfg(),&actor).await?;
  if row.as_ref().is_none_or(|r|placeholder(&field(r,"username"))){context.actor_checked(db,&actor).await?;}
  let label=label(&actor,row.as_ref());
  let mut icon=if matches!(actor.as_str(),"https://waffles.baeddel.social/fediverse/blog/admin"|"https://app.wafrn.net/fediverse/blog/admin"){"https://mkultra.monster/img/avatar/wafrn-approvals.webp".into()}else{row.as_ref().map(|r|field(r,"icon_source_url")).unwrap_or_default()};
  if icon.is_empty(){if let Some(row)=db.query_opt("SELECT public_url FROM remote_media_cache WHERE actor_id=$1 AND kind='avatar' LIMIT 1",&[&actor]).await?{icon=row.get::<_,Option<String>>(0).unwrap_or_default();}}
  (card::php_trim(&field(&label,"display_name")).into(),field(&label,"handle"),if icon.starts_with("https://"){icon}else{fallback.into()})
 };
 ask["asker_display"]=json!(display);ask["asker_handle"]=json!(handle);ask["asker_avatar"]=json!(avatar);
 ask["_body_answer"]=ask.get("answer").or_else(||ask.get("ask_answer")).cloned().unwrap_or(Value::Null);ask["answer"]=json!("");Ok(ask)
}
#[cfg(test)]
mod tests{
 use super::*;
 struct Recorder(std::sync::Mutex<Vec<String>>);
 impl crate::profile_warm::WarmTransport for Recorder{
  fn actor_process<'a>(&'a self,actor:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'a>>{Box::pin(async move{self.0.lock().unwrap().push(actor.into());true})}
  fn media_process(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'_>>{Box::pin(async move{panic!("Ask identity must not warm media")})}
 }
 fn cfg()->Config{Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None}}
 #[test]
 fn actual_php_label_and_avatar_components()->Result<()>{
  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).parent().unwrap().parent().unwrap();
  let output=std::process::Command::new(root.join("api/bin/php-policy-test")).arg(root.join("api/bin/profile-identity-components-reference.php")).output()?;
  anyhow::ensure!(output.status.success(),"{}",String::from_utf8_lossy(&output.stderr));
  let reference:Value=serde_json::from_slice(&output.stdout)?;
  for capture in reference["captures"].as_array().unwrap(){
   let actor=capture["actor"].as_str().unwrap();let row=capture.get("row").filter(|v|!v.is_null());
   assert_eq!(label(actor,row),capture["label"],"{capture}");
   assert_eq!(flat_key(actor),capture["flat_key"],"{capture}");
   assert_eq!(row.is_none_or(|r|placeholder(&field(r,"username"))),!capture["warms"].as_array().unwrap().is_empty(),"{capture}");
  }
  for capture in reference["avatars"].as_array().unwrap(){assert_eq!(local_avatar(capture["input"].as_str().unwrap()),capture["output"],"{capture}");}
  Ok(())
 }
 #[tokio::test]
 #[ignore="requires genuine Ask identity capture, isolated PostgreSQL and primary-off recorder"]
 async fn actual_php_ask_identity_pg_and_effects()->Result<()>{
  use tokio::io::{AsyncBufReadExt,AsyncReadExt,AsyncWriteExt,BufReader};
  anyhow::ensure!(std::env::var("VAAK_AP_ACTOR_WARM_RUST_PRIMARY").as_deref()==Ok("0"),"explicit primary-off recording required; no real worker launches");
  let url=std::env::var("PROFILE_FIXTURE_DB")?;anyhow::ensure!(url.contains("/tmp/"),"isolated socket required");
  let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-local-profile-ask-identity-reference.json")?)?;
  let db=crate::db::connect(&url).await?;let mut output=Vec::new();
  for width in ["INTEGER","BIGINT"]{
   let schema=format!("profile_ask_identity_{}_{}",width.to_ascii_lowercase(),std::process::id());
   db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await?;
   let result:Result<()>=async{
    for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){
     let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",field(c,"column_name"),field(c,"data_type"))).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await?;
    }
    for capture in reference["captures"].as_array().unwrap().iter().filter(|c|c["width"]==width){
     for(table,rows)in capture["current_tables_before"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await?;for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await?;}}
     let mut config=cfg();let mut server=None;
     // Transport substitution only: replay the declared actual PHP flat memo
     // payload through native Redis GET, never expected identity or label.
     if !capture["flat_transport_payload"].is_null(){
      let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;config.redis_url=format!("redis://127.0.0.1:{}/0",listener.local_addr()?.port());
      let payload=capture["flat_transport_payload"].to_string();
      server=Some(tokio::spawn(async move{
       let Ok((stream,_))=listener.accept().await else{return};let(read,mut write)=stream.into_split();let mut reader=BufReader::new(read);
       loop{let mut head=String::new();if reader.read_line(&mut head).await.unwrap_or(0)==0{break;}let Ok(count)=head.trim().trim_start_matches('*').parse::<usize>()else{break;};let mut args=Vec::new();
        for _ in 0..count{head.clear();if reader.read_line(&mut head).await.is_err(){return;}let Ok(len)=head.trim().trim_start_matches('$').parse::<usize>()else{return;};let mut bytes=vec![0;len+2];if reader.read_exact(&mut bytes).await.is_err(){return;}args.push(String::from_utf8_lossy(&bytes[..len]).into_owned());}
        let answer=if args.first().is_some_and(|v|v=="GET"){format!("${}\r\n{payload}\r\n",payload.len())}else{"+OK\r\n".into()};if write.write_all(answer.as_bytes()).await.is_err(){break;}
       }
      }));
     }
     let recorder=std::sync::Arc::new(Recorder(std::sync::Mutex::new(Vec::new())));let context=WarmContext::new(config,recorder.clone());
     let ask=ask_identity(&db,&context,json!({"asker_actor":capture["actor"],"question":"Original fixture question","answer":"Current body"})).await?;
     let identity=json!({"display_name":ask["asker_display"],"handle":ask["asker_handle"],"avatar":ask["asker_avatar"]});
     let effects=json!({"actor_warm_requests":*recorder.0.lock().unwrap(),"media_warm_requests":[]});
     if let Some(server)=server{server.abort();}
     assert_eq!(identity,capture["php"]["identity"],"{} {width}",capture["case"]);
     assert_eq!(effects,capture["php"]["effects"],"{} {width}",capture["case"]);
     assert_eq!(ask["_body_answer"],"Current body");assert_eq!(ask["answer"],"");
     output.push(json!({"width":width,"case":capture["case"],"identity":identity,"effects":effects,"scope":"current PG Ask identity and process-boundary warm requests; declared flat Redis transport; no source admission or actual process delivery"}));
    }Ok(())
   }.await;
   db.batch_execute("SET search_path=public").await?;db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;result?;
  }
  std::fs::write("/tmp/vaak-local-profile-ask-identity-native.json",serde_json::to_vec(&json!({"captures":output}))?)?;Ok(())
 }
}
