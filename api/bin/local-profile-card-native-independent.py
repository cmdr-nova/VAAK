#!/usr/bin/env python3
"""Isolated actual private profile admission/painter diagnostic CLI."""
import json,pathlib,re,subprocess,sys,hashlib,os,uuid
root=pathlib.Path(__file__).resolve().parents[2];src=root/'rust/vaak-worker/src'
runtime=pathlib.Path('/tmp/vaak-profile-card-independent-runtime');(runtime/'src').mkdir(parents=True,exist_ok=True)
code=subprocess.check_output([sys.executable,str(root/'api/bin/remote-direct-native-independent.py')]).decode()
code=re.sub(r'^#\[path=.*\] mod (?:preview_fixture_server);\n','',code,flags=re.M)
code=code[:code.index('async fn restore(')]
pos=code.rfind('\n}\n');code=code[:pos]+'''\n pub(crate) fn independent_set_clock(card:&mut AdmittedDirectStatus,clock:i64){card.facts.now=chrono::DateTime::from_timestamp(clock,0).unwrap();}\n'''+code[pos:]
profile_original=(src/'profile_context.rs').read_text();profile=profile_original;profile=re.sub(r'^//!','//',profile,flags=re.M)
profile+=r'''
 struct IndependentWarmTransport;
 impl crate::profile_warm::WarmTransport for IndependentWarmTransport{
  fn actor_process<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'a>>{Box::pin(async{true})}
  fn media_process(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'_>>{Box::pin(async{true})}
 }
 pub(crate) async fn independent_profile_run(db:&Client,row:&Value)->Result<Value>{
  let o=&row["context"];let actor=row["caller_provenance"]["actor"].as_str().unwrap();let key=actor.rsplit('/').next().unwrap();
  let quote_initial=if row.get("initial_quote_budget").is_some(){let initial=&row["initial_quote_budget"];if initial["present"]==true&&initial["type"]=="int"{initial["value"].as_i64().unwrap()}else{2}}else{0};
  let mut session=AuthenticatedProfileSession::from_php_session(o["owner"].as_i64().unwrap(),o["csrf"].as_str().unwrap().into(),key.into(),actor.into(),false,0)?.with_quote_preview_budget(quote_initial);
  let warm=if std::env::var("PROFILE_CARD_WARM").ok().as_deref()==Some("1"){
   let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:o["owner"].as_i64().unwrap(),bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};
   let warm=std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(IndependentWarmTransport)));session=session.with_warm_context(warm.clone());Some(warm)
  }else{None};
  let original_page=&row["caller_provenance"]["actual_append_selected"];let count=original_page["items"].as_array().unwrap().len();
  let page=crate::profile_source::select(db,key,actor,row["caller_provenance"]["tab"].as_str().unwrap(),10,0).await?.into_parts().1;
  let inputs=vec![ProfileCardSource::shared(row["source_index"].as_u64().unwrap() as usize,row["source"].clone())?];
  let dns=crate::direct_context::IndependentDns{expected:None,count:std::sync::atomic::AtomicUsize::new(0)};
  match admit_shared_with_dns(db,&session,page,inputs,&dns).await{Err(error)=>Ok(serde_json::json!({"fallback":error.to_string(),"cards":[],"original_source":row["source"]})),Ok(mut batch)=>{
   for card in batch.cards.iter_mut(){crate::direct_context::independent_set_clock(card,o["rendered_at"].as_i64().unwrap());}
   let quote_state=batch.quote_state().map(|state|serde_json::json!({"initial":state.initial(),"remaining":state.remaining(),"attempts":state.attempts()}));
   let mut rendered=Vec::new();for(index,card)in batch.cards(){match crate::direct_render::render(card){Ok(html)=>rendered.push(serde_json::json!({"source_index":index,"html":html})),Err(error)=>return Ok(serde_json::json!({"fallback":error.to_string(),"cards":[],"original_source":row["source"]}))}}
   Ok(serde_json::json!({"cards":rendered,"original_source":row["source"],"source_count":batch.page().source_count(),"next_offset":batch.page().next_offset(),"has_more":batch.page().has_more(),"status_budget":batch.remaining_preview_budget(),"budget_changed":batch.preview_budget_changed(),"quote_state":quote_state,"warm_requests":warm.as_ref().map(|w|serde_json::json!({"actors":w.requested_actors(),"media":w.requested_media()}))}))
  }}
 }
'''
# Declared test-only DNS/clock transport, never facts or permission overrides.
code=code.replace('struct IndependentDns', 'pub(crate) struct IndependentDns').replace('expected:Option<&\'a str>,count:', 'pub(crate) expected:Option<&\'a str>,pub(crate) count:')
code=re.sub(r'^#\[path=.*\] mod profile_context;\n',lambda _: 'mod profile_context {\n'+profile+'\n}\n',code,flags=re.M)
code+=r'''
async fn restore(db:&tokio_postgres::Client,table:&str,rows:&serde_json::Value)->anyhow::Result<()>{db.batch_execute(&format!("DELETE FROM {table}")).await?;for row in rows.as_array().into_iter().flatten(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await?;}Ok(())}
#[tokio::main]async fn main()->anyhow::Result<()>{use anyhow::bail;use serde_json::{Value,json};let url=std::env::var("LIBRARY_FIXTURE_DB")?;if !url.contains("/tmp/"){bail!("disposable local PostgreSQL only")}
 let db=crate::db::connect(&url).await?;db.batch_execute("SET synchronous_commit=off").await?;let input:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_CARD_REFERENCE").unwrap_or("/tmp/vaak-local-profile-shared-card-reference.json".into()))?)?;let mut output=Vec::new();
 for width in ["INTEGER","BIGINT"]{let schema=format!("local_profile_card_native_{}_{}",width.to_ascii_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await?;
  for(table,columns)in input["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await?;}
  for row in input["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){for(table,rows)in row["current_tables"].as_object().unwrap(){restore(&db,table,rows).await?;}let result=crate::profile_context::independent_profile_run(&db,row).await?;output.push(json!({"width":width,"case":row["case"],"branch":row["branch"],"original_source":row["source"],"caller_provenance":row["caller_provenance"],"result":result,"php":row["php"]}));}
  db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;
 }
 let out=std::env::var("PROFILE_CARD_NATIVE_OUTPUT").unwrap_or("/tmp/vaak-local-profile-card-native-diagnostic.json".into());std::fs::write(&out,serde_json::to_vec(&output)?)?;println!("Profile representative native diagnostic {} cards: {out}",output.len());Ok(())}
'''
manifest=(root/'rust/vaak-worker/Cargo.toml').read_text().replace('name = "vaak-worker"','name = "local-profile-card-independent"').replace('path = "../vaak-types"','path = '+json.dumps(str(root/'rust/vaak-types')))
# Snapshot every transitive worker source before compiling. Later concurrent
# owner edits cannot change the fixed binary's module input or provenance.
snapshot=runtime/('source-freeze-'+uuid.uuid4().hex);snapshot.mkdir(parents=True,exist_ok=False)
freeze={}
for path in sorted(path for path in src.rglob('*') if path.is_file()):
    relative=path.relative_to(src);data=path.read_bytes();dest=snapshot/relative;dest.parent.mkdir(parents=True,exist_ok=True);dest.write_bytes(data)
    freeze[str(path.relative_to(root))]=hashlib.sha256(data).hexdigest()
if (snapshot/'profile_context.rs').read_text()!=profile_original:
    raise RuntimeError('context changed during snapshot; rerun the generator')
for path in sorted(path for path in src.rglob('*') if path.is_file()):
    if hashlib.sha256(path.read_bytes()).hexdigest()!=freeze[str(path.relative_to(root))]:
        raise RuntimeError('source changed during snapshot; rerun the generator')
code=code.replace(str(src),str(snapshot))
freeze['generated_main_sha256']=hashlib.sha256(code.encode()).hexdigest()
freeze['source_snapshot']=str(snapshot)
(runtime/'source-freeze.json').write_text(json.dumps(freeze,indent=2)+'\n')
(runtime/'Cargo.toml').write_text(manifest);(runtime/'src/main.rs').write_text(code);print(runtime)
