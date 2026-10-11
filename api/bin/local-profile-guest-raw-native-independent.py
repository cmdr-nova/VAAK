#!/usr/bin/env python3
"""Actual native guest raw constructor with current SQL and clock transport only."""
import json,pathlib,subprocess,sys,hashlib
root=pathlib.Path(__file__).resolve().parents[2]
subprocess.check_output([sys.executable,str(root/'api/bin/local-profile-card-native-independent.py')])
base=pathlib.Path('/tmp/vaak-profile-card-independent-runtime');runtime=pathlib.Path('/tmp/vaak-profile-guest-raw-independent-runtime');(runtime/'src').mkdir(parents=True,exist_ok=True)
code=(base/'src/main.rs').read_text()
method=r'''
 pub(crate) async fn independent_guest_raw_run(db:&Client,row:&Value)->Result<Value>{
  let context=&row["context"];let actor=context["actor"].as_str().unwrap();let key=context["actor_key"].as_str().unwrap();let tab=row["caller_provenance"]["tab"].as_str().unwrap();
  let session=GuestProfileSession::from_php_guest_profile(key.into(),actor.into(),false,0)?;
  let warm=if std::env::var("PROFILE_RAW_WARM").ok().as_deref()==Some("1"){
   let cfg=crate::config::Config{database_url:String::new(),redis_url:"redis://127.0.0.1:1/0".into(),redis_queue_url:"redis://127.0.0.1:1/1".into(),jetstream_state:std::path::PathBuf::new(),default_owner_id:context["owner"].as_i64().unwrap(),bsky_public_api:String::new(),notif_cache_dir:std::path::PathBuf::new(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None};Some(std::sync::Arc::new(crate::profile_enrichment::WarmContext::new(cfg,std::sync::Arc::new(IndependentWarmTransport))))
  }else{None};
  let session=if let Some(warm)=warm.as_ref(){session.with_warm_context(warm.clone())}else{session};
  let selection=crate::profile_source::select(db,key,actor,tab,10,0).await?;let selected=selection.rows().to_vec();
  match admit_guest_raw(db,&session,selection).await{
   Err(error)=>Ok(serde_json::json!({"fallback":error.to_string(),"cards":[],"selected_sources":selected})),
   Ok(mut batch)=>{let count=batch.page.source_count();let next=batch.page.next_offset();let more=batch.page.has_more();let mut rendered=Vec::new();
    for (index,card) in batch.cards.iter_mut(){card.facts.card.now=chrono::DateTime::from_timestamp(context["rendered_at"].as_i64().unwrap(),0).unwrap();match crate::profile_raw::render(card){Ok(html)=>rendered.push(serde_json::json!({"source_index":index,"html":html})),Err(error)=>return Ok(serde_json::json!({"fallback":error.to_string(),"cards":[],"selected_sources":selected}))}}
    Ok(serde_json::json!({"cards":rendered,"selected_sources":selected,"source_count":count,"next_offset":next,"has_more":more,"warm_requests":warm.as_ref().map(|w|serde_json::json!({"actors":w.requested_actors(),"media":w.requested_media()}))}))
   }
  }
 }
'''
code=code.replace(' pub(crate) async fn independent_profile_run',method+'\n pub(crate) async fn independent_profile_run')
code=code.replace('"/tmp/vaak-local-profile-shared-card-reference.json"','"/tmp/vaak-local-profile-raw-card-guest-sql-reference.json"').replace('"PROFILE_CARD_REFERENCE"','"PROFILE_GUEST_RAW_REFERENCE"').replace('"PROFILE_CARD_NATIVE_OUTPUT"','"PROFILE_GUEST_RAW_NATIVE_OUTPUT"').replace('"/tmp/vaak-local-profile-card-native-diagnostic.json"','"/tmp/vaak-local-profile-guest-raw-native-diagnostic.json"')
code=code.replace('.filter(|r|r["width"]==width)', '.filter(|r|r["width"]==width&&r["context"]["owner"]==0)').replace('crate::profile_context::independent_profile_run(&db,row)', 'crate::profile_context::independent_guest_raw_run(&db,row)').replace('row["branch"]','row["branch"]').replace('Profile representative native diagnostic','Guest raw profile current SQL diagnostic')
freeze=json.loads((base/'source-freeze.json').read_text());freeze['generated_main_sha256']=hashlib.sha256(code.encode()).hexdigest();(runtime/'source-freeze.json').write_text(json.dumps(freeze,indent=2)+'\n')
(runtime/'Cargo.toml').write_text((base/'Cargo.toml').read_text().replace('name = "local-profile-card-independent"','name = "local-profile-guest-raw-independent"'));(runtime/'src/main.rs').write_text(code);print(runtime)
