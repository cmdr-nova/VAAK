//! Actual post-image enqueue policy, separate from actor avatar/header warming.
use super::*;
#[derive(Default,Debug,PartialEq,Eq)]
pub(crate) struct PostMediaWarmEffects {pub(crate) queued:bool,pub(crate) nudged:bool}
async fn enqueue(db:&Client,cfg:&crate::config::Config,source_url:&str,transport:&dyn crate::profile_warm::WarmTransport,grant:&dyn crate::profile_enrichment::CurrentEnrichmentGrant)->Result<PostMediaWarmEffects>{
    use sha2::{Digest,Sha256};use std::time::Duration;
    let mut effects=PostMediaWarmEffects::default();let Some(url)=crate::profile_enrichment::sanitize_profile_url(source_url)?else{return Ok(effects)};
    grant.require_current(db).await?;
    let enabled=std::env::var("VAAK_REDIS_ENABLED").map(|value|!matches!(crate::library::card::php_trim(&value).to_ascii_lowercase().as_str(),"0"|"false"|"off"|"no")).unwrap_or(true);
    if enabled{
        let queue=tokio::time::timeout(Duration::from_millis(80),crate::redis_util::connect(&cfg.redis_queue_url)).await;
        grant.require_current(db).await?;
        if let Ok(Ok(mut queue))=queue{
            let key=format!("media-warm:post:{}",hex::encode(Sha256::digest(url.as_bytes())));
            let locked=tokio::time::timeout(Duration::from_millis(80),crate::redis_util::lock(&mut queue,&key,20,&std::process::id().to_string())).await;
            grant.require_current(db).await?;
            if !matches!(locked,Ok(Ok(true))){return Ok(effects)}
        }
    }
    grant.require_current(db).await?;let now=chrono::Utc::now().to_rfc3339_opts(chrono::SecondsFormat::Secs,false);
    let inserted=db.execute("INSERT INTO ap_media_warm_queue (job_type,target_key,media_kind,status,attempts,max_attempts,next_attempt_at,created_at,updated_at) VALUES ('post_media',$1,'image','pending',0,5,$2,$2,$2) ON CONFLICT(job_type,target_key,media_kind) DO NOTHING",&[&url,&now]).await;
    grant.require_current(db).await?;if !matches!(inserted,Ok(count)if count>0){return Ok(effects)}
    effects.queued=true;grant.require_current(db).await?;effects.nudged=transport.media_process().await;grant.require_current(db).await?;Ok(effects)
}

/// Search-built post media: the same queue and nudge as profile cards.
pub(super) async fn enqueue_search(db:&Client,cfg:&crate::config::Config,source_url:&str,transport:&dyn crate::profile_warm::WarmTransport,grant:&dyn crate::profile_enrichment::CurrentEnrichmentGrant)->Result<PostMediaWarmEffects>{enqueue(db,cfg,source_url,transport,grant).await}

impl MaterializedProfilePage{
    pub(crate) async fn apply_source_post_media_warm(&self,db:&Client,effect:&DeferredProjectionEffect,status:&Value,grant:&crate::profile_context::AdmittedProjectionSources,cfg:&crate::config::Config,transport:&dyn crate::profile_warm::WarmTransport)->Result<PostMediaWarmEffects>{
        if !self.effects.contains(effect)||grant.disposition(effect.source_index,status)!=Some(true){bail!("PHP fallback: post-media effect differs from admitted native source")}
        let ProjectionEffect::PostMediaWarm{source_url}=effect.effect()else{bail!("PHP fallback: post-media enqueue requires native warm descriptor")};
        let current=self.effect_current_grant(status,grant,effect.source_index);enqueue(db,cfg,source_url,transport,&current).await
    }
}
impl ProjectedQuoteTarget{
    pub(crate) async fn apply_quote_post_media_warm(&self,db:&Client,effect:&DeferredProjectionEffect,status:&Value,grant:&crate::profile_context::AdmittedQuoteProjection,cfg:&crate::config::Config,transport:&dyn crate::profile_warm::WarmTransport)->Result<PostMediaWarmEffects>{
        if !self.effects.contains(effect)||!grant.permits(&self.requested,status){bail!("PHP fallback: quote post-media effect differs from native admitted descriptor")}
        let ProjectionEffect::PostMediaWarm{source_url}=effect.effect()else{bail!("PHP fallback: quote post-media enqueue requires native warm descriptor")};
        let current=self.effect_current_grant(status,grant);enqueue(db,cfg,source_url,transport,&current).await
    }
}
impl surface::CanonicalProjection{
    pub(crate) async fn apply_post_media_warm(&self,db:&Client,effect:&DeferredProjectionEffect,cfg:&crate::config::Config,transport:&dyn crate::profile_warm::WarmTransport)->Result<PostMediaWarmEffects>{
        if !self.effects().contains(effect){bail!("PHP fallback: surface post-media descriptor differs from native source")}
        let ProjectionEffect::PostMediaWarm{source_url}=effect.effect()else{bail!("PHP fallback: surface post-media enqueue requires native warm descriptor")};
        let(current,_)=self.current_grant_with_preview_receipts();enqueue(db,cfg,source_url,transport,current.as_ref()).await
    }
}
#[cfg(test)]mod tests{
    use super::*;use std::sync::{Arc,Mutex,atomic::{AtomicBool,Ordering}};
    struct Recorder{calls:Mutex<usize>,allow:bool}
    impl crate::profile_warm::WarmTransport for Recorder{
        fn actor_process<'a>(&'a self,_:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'a>>{Box::pin(async{panic!("post-media must not use actor process")})}
        fn media_process(&self)->std::pin::Pin<Box<dyn std::future::Future<Output=bool>+Send+'_>>{Box::pin(async move{*self.calls.lock().unwrap()+=1;self.allow})}
    }
    struct Current(Arc<AtomicBool>);
    impl crate::profile_enrichment::CurrentEnrichmentGrant for Current{
        fn require_current<'a>(&'a self,_:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async move{anyhow::ensure!(self.0.load(Ordering::SeqCst),"post source withdrawn");Ok(())})}
    }
    fn cfg(port:u16)->crate::config::Config{crate::config::Config{database_url:String::new(),redis_url:String::new(),redis_queue_url:format!("redis://127.0.0.1:{port}/0"),jetstream_state:Default::default(),default_owner_id:1,bsky_public_api:String::new(),notif_cache_dir:Default::default(),phyrian_mutation_token:None,phyrian_bridge_url:String::new(),phyrian_bridge_secret:None}}
    async fn listener(allowed:bool,revoke:Option<Arc<AtomicBool>>)->(u16,Arc<Mutex<Vec<Vec<String>>>>,tokio::task::JoinHandle<()>){
        use tokio::io::{AsyncBufReadExt,AsyncReadExt,AsyncWriteExt,BufReader};
        let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await.unwrap();let port=listener.local_addr().unwrap().port();let calls=Arc::new(Mutex::new(vec![]));let recorded=calls.clone();
        let server=tokio::spawn(async move{let(stream,_)=listener.accept().await.unwrap();let(read,mut write)=stream.into_split();let mut reader=BufReader::new(read);
            loop{let mut head=String::new();if reader.read_line(&mut head).await.unwrap_or(0)==0{return}let count=head.trim().trim_start_matches('*').parse::<usize>().unwrap();let mut args=vec![];
                for _ in 0..count{head.clear();reader.read_line(&mut head).await.unwrap();let len=head.trim().trim_start_matches('$').parse::<usize>().unwrap();let mut bytes=vec![0;len+2];reader.read_exact(&mut bytes).await.unwrap();args.push(String::from_utf8_lossy(&bytes[..len]).to_string());}
                let answer=if args[0]=="SET"{if let Some(live)=&revoke{live.store(false,Ordering::SeqCst)}recorded.lock().unwrap().push(args);if allowed{"+OK\r\n"}else{"$-1\r\n"}}else{"+OK\r\n"};if write.write_all(answer.as_bytes()).await.is_err(){return}
            }});(port,calls,server)
    }
    fn comparable(rows:&[Value])->Vec<Value>{rows.iter().map(|row|{let mut row=row.clone();for key in ["id","next_attempt_at","created_at","updated_at"]{row.as_object_mut().unwrap().remove(key);}row}).collect()}
    #[tokio::test]
    #[ignore="actual selected PHP cold image normalizer status and admitted native post-media effects"]
    async fn actual_event_cold_image_projection(){
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-event-cold-image-reference.json").unwrap()).unwrap();let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();let mut keys=std::collections::HashSet::new();let mut negatives=0;
        for width in ["INTEGER","BIGINT"]{let schema=format!("post_media_selected_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            // Captured columns are portable width metadata; preserve the real
            // production queue uniqueness needed by the unchanged insert.
            let helper:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-post-media-warm-reference.json").unwrap()).unwrap();
            db.batch_execute("DROP TABLE ap_media_warm_queue").await.unwrap();db.batch_execute(&helper["actual_ddl"]["ap_media_warm_queue"].as_str().unwrap().replace("INTEGER PRIMARY KEY AUTOINCREMENT",&format!("{width} GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY"))).await.unwrap();
            for capture in reference["captures"].as_array().unwrap().iter().filter(|capture|capture["width"]==width){assert!(keys.insert((width.to_owned(),s(capture,"case").to_owned())));
                for(table,rows)in capture["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let owner=i(capture,"owner");let actor=s(capture,"actor");let guest=GuestProfileSession::from_php_guest_profile("fixture".into(),actor.into(),false,0).unwrap();let auth=if owner>0{Some(AuthenticatedProfileSession::from_php_session(owner,"fixture-csrf-token".into(),"fixture".into(),actor.into(),false,0).unwrap())}else{None};let viewer=auth.as_ref().map(ProfileViewer::Auth).unwrap_or(ProfileViewer::Guest(&guest));
                let selected=crate::profile_source::select(&db,"fixture",actor,"boosts",10,0).await.unwrap();assert_eq!(json!(selected.rows()),capture["page"]["items"]);let page=materialize(&db,&viewer,selected).await.unwrap();assert_eq!(page.page().source_count(),1);assert_eq!(page.sources()[0].source_index(),0);
                let expected=&capture["statuses"][0];let grant=crate::profile_context::admit_projection_sources(&db,&viewer,&page).await.unwrap();let transport=Recorder{calls:Mutex::new(0),allow:true};let actual=match page.sources()[0].source(){ProfileSourceKind::Shared(status)=>Some(status.clone()),ProfileSourceKind::Omitted(_)=>None,_=>panic!("actual selected event stays native")};assert_eq!(actual.as_ref(),expected.as_object().map(|_|expected),"{width} {}",capture["case"]);
                let effects=page.effects().iter().filter(|effect|matches!(effect.effect(),ProjectionEffect::PostMediaWarm{..})).cloned().collect::<Vec<_>>();
                if let Some(status)=actual.as_ref(){for effect in &effects{page.apply_source_post_media_warm(&db,effect,status,&grant,&cfg(1),&transport).await.unwrap();}}
                let rows:Vec<Value>=db.query("SELECT to_jsonb(q) FROM ap_media_warm_queue q ORDER BY target_key",&[]).await.unwrap().iter().map(|row|row.get(0)).collect();let mut expected_queue=capture["after_tables"]["ap_media_warm_queue"].as_array().unwrap().clone();expected_queue.sort_by_key(|row|s(row,"target_key").to_owned());assert_eq!(comparable(&rows),comparable(&expected_queue),"{width} {}",capture["case"]);assert_eq!(*transport.calls.lock().unwrap(),capture["effects"]["post_media_nudge_requests"].as_array().unwrap().len());
                if expected.is_null(){assert!(effects.is_empty());assert!(rows.is_empty());}
                if let(Some(status),Some(effect))=(actual.as_ref(),effects.first()){
                    let before=*transport.calls.lock().unwrap();let mut wrong=effect.clone();if let ProjectionEffect::PostMediaWarm{source_url}=&mut wrong.effect{source_url.push_str("?substituted")};assert!(page.apply_source_post_media_warm(&db,&wrong,status,&grant,&cfg(1),&transport).await.is_err());let mut wrong_status=status.clone();wrong_status["content"]=json!("substituted");assert!(page.apply_source_post_media_warm(&db,effect,&wrong_status,&grant,&cfg(1),&transport).await.is_err());
                    db.batch_execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn'").await.unwrap();assert!(page.apply_source_post_media_warm(&db,effect,status,&grant,&cfg(1),&transport).await.is_err());assert_eq!(*transport.calls.lock().unwrap(),before);negatives+=3;
                }
            }db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(keys.len(),30);assert_eq!(negatives,66);
    }
    #[tokio::test]
    #[ignore="genuine PHP cold post-media enqueue/nudge and actual guarded Redis wire on disposable SQL"]
    async fn actual_post_media_warm_helper(){
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-post-media-warm-reference.json").unwrap()).unwrap();let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();let mut keys=std::collections::HashSet::new();let mut output=vec![];
        for width in ["INTEGER","BIGINT"]{let schema=format!("post_media_warm_native_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for ddl in reference["actual_ddl"].as_object().unwrap().values(){db.batch_execute(&ddl.as_str().unwrap().replace("INTEGER PRIMARY KEY AUTOINCREMENT",&format!("{width} GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY"))).await.unwrap();}
            for capture in reference["captures"].as_array().unwrap().iter().filter(|capture|capture["width"]==width&&capture["case"]!="cached"){
                let case=s(capture,"case");assert!(keys.insert((width.to_owned(),case.to_owned())));db.batch_execute("DELETE FROM ap_media_warm_queue;DELETE FROM remote_post_media_cache").await.unwrap();
                for(table,rows)in capture["before"].as_object().unwrap(){for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let live=Arc::new(AtomicBool::new(true));let current=Current(live);let mut server=None;let mut wire=None;let port=if capture["redis_available"]==true{let(p,w,j)=listener(case!="redis_contended",None).await;server=Some(j);wire=Some(w);p}else{1};
                if case=="sql_error"{db.batch_execute("ALTER TABLE ap_media_warm_queue RENAME TO unavailable_queue").await.unwrap();}
                let transport=Recorder{calls:Mutex::new(0),allow:case!="nudge_locked"};let effects=enqueue(&db,&cfg(port),s(capture,"url"),&transport,&current).await.unwrap();
                if case=="sql_error"{db.batch_execute("ALTER TABLE unavailable_queue RENAME TO ap_media_warm_queue").await.unwrap();}
                let actual:Vec<Value>=db.query("SELECT to_jsonb(q) FROM ap_media_warm_queue q ORDER BY id",&[]).await.unwrap().iter().map(|row|row.get(0)).collect();assert_eq!(comparable(&actual),comparable(capture["after"]["ap_media_warm_queue"].as_array().unwrap()),"{width} {case}");
                let expected_nudged=!capture["nudge_argv"].as_array().unwrap().is_empty();assert_eq!(effects.nudged,expected_nudged,"{width} {case}");let expected_queued=capture["before"]["ap_media_warm_queue"].as_array().unwrap().is_empty()&&!actual.is_empty();assert_eq!(effects.queued,expected_queued);assert_eq!(*transport.calls.lock().unwrap(),usize::from(expected_queued));
                if expected_queued{let row=&actual[0];assert!(i(row,"id")>0);assert_eq!(row["created_at"],row["updated_at"]);assert_eq!(row["created_at"],row["next_attempt_at"]);chrono::DateTime::parse_from_rfc3339(s(row,"created_at")).unwrap();}
                else{assert_eq!(&actual,capture["after"]["ap_media_warm_queue"].as_array().unwrap(),"duplicate/denied queue must remain byte-for-byte current");}
                if let Some(wire)=wire{let calls=wire.lock().unwrap();assert_eq!(calls.len(),1);let call=&calls[0];assert_eq!(call[1],s(&capture["locks"][0],"key"));assert!(call.iter().any(|arg|arg=="NX"));assert!(call.windows(2).any(|pair|pair==["EX","20"]));}
                if let Some(server)=server{server.abort()};output.push(json!({"width":width,"case":case,"queued":effects.queued,"nudged":effects.nudged}));
            }
            for allowed in [false,true]{db.batch_execute("DELETE FROM ap_media_warm_queue").await.unwrap();let live=Arc::new(AtomicBool::new(true));let(port,wire,server)=listener(allowed,Some(live.clone())).await;let transport=Recorder{calls:Mutex::new(0),allow:true};assert!(enqueue(&db,&cfg(port),"https://media.test/withdrawn.jpg",&transport,&Current(live)).await.unwrap_err().to_string().contains("post source withdrawn"));assert_eq!(wire.lock().unwrap().len(),1);assert_eq!(db.query_one("SELECT COUNT(*) FROM ap_media_warm_queue",&[]).await.unwrap().get::<_,i64>(0),0);assert_eq!(*transport.calls.lock().unwrap(),0);server.abort();}
            db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }assert_eq!(keys.len(),26);std::fs::write("/tmp/vaak-profile-post-media-warm-native.json",serde_json::to_vec_pretty(&json!({"captures":output,"withdrawals":4,"scope":"actual queue field parity except generated surrogate id/clocks; real Redis wire; process effect recorder, actual PHP lock/argv separately captured; not source caller proof"})).unwrap()).unwrap();
    }
}
