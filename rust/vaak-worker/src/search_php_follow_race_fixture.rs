#[tokio::test]
#[ignore="owned genuine PHP follow race; new explicit root build and sole PG lease required"]
 async fn real_php_follow_action_fallback()->Result<()>{
  use tokio::io::AsyncBufReadExt;
  let caller=std::env::var("VAAK_SEARCH_FOLLOW_CALLER")?;let family=std::env::var("VAAK_SEARCH_FOLLOW_FAMILY")?;anyhow::ensure!(matches!(caller.as_str(),"full"|"append")&&matches!(family.as_str(),"accounts"|"hashtags"));
  let tool=std::env::var("VAAK_SEARCH_SETTINGS_RUNTIME_TOOL")?;let output=std::env::var("VAAK_SEARCH_SETTINGS_FINAL_OUTPUT")?;
  // Each prescribed case launches as a separate native process with the same
  // trusted startup TMPDIR as its SAPI. No global environment changes in tests.
  let width_filter=std::env::var("VAAK_SEARCH_SETTINGS_WIDTH").ok();let phase_filter=std::env::var("VAAK_SEARCH_SETTINGS_PHASE").ok();
  anyhow::ensure!(width_filter.is_some()==phase_filter.is_some(),"both Settings case filters required together");
  if let Some(width)=width_filter.as_deref(){anyhow::ensure!(matches!(width,"INTEGER"|"BIGINT"),"invalid Settings width filter")}
  if let Some(phase)=phase_filter.as_deref(){anyhow::ensure!(matches!(phase,"after_viewer_before_components"|"after_components_before_status"|"after_status_before_final_raw"),"invalid Settings phase filter")}

  let root=std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../..");let suffix=std::process::id();let mut results=Vec::new();let mut schemas=Vec::new();
  for (width_index,width) in ["INTEGER","BIGINT"].iter().enumerate(){for (phase_index,phase) in ["after_viewer_before_components","after_components_before_status","after_status_before_final_raw"].iter().enumerate(){
   if width_filter.as_deref().is_some_and(|selected|selected!=*width)||phase_filter.as_deref().is_some_and(|selected|selected!=*phase){continue}
   let schema=format!("search_settings_final_boundary_{}_{}_{}",width.to_ascii_lowercase(),suffix,phase_index);schemas.push(schema.clone());let database=format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres application_name={schema} options='-c search_path={schema}'");
   let negative="follow_action_post".to_owned();
   let hook=Arc::new(FinalBoundaryMutation{phase:(*phase).into(),case:negative.clone(),schema:schema.clone(),root:root.clone(),hits:std::sync::atomic::AtomicUsize::new(0),armed:std::sync::atomic::AtomicBool::new(false),settings:Mutex::new(None)});
   let base=23080+width_index*40+phase_index*10;let directory=format!("{output}.{}-{phase}",width.to_ascii_lowercase());std::fs::create_dir_all(&directory)?;
   let (cancel_tx,cancel_rx)=tokio::sync::watch::channel(false);let (shutdown_tx,shutdown_rx)=tokio::sync::oneshot::channel();
   let stop=Arc::new(MixedStop{key:format!("settings-stop-{schema}"),receipt:format!("{directory}/native-stop.json"),cancel:cancel_tx.clone(),shutdown:Mutex::new(Some(shutdown_tx)),active:std::sync::atomic::AtomicUsize::new(0),listener:Mutex::new(None)});
   let receipts=Arc::new(Mutex::new(Vec::<Value>::new()));let state=Fixture{database,receipts:receipts.clone(),fault:None,boundary:Some(hook.clone()),cancel:Some(cancel_rx),stop:Some(stop.clone())};
   let app=axum::Router::new().route("/internal/search/render",axum::routing::post(handler)).route("/internal/search/stop",axum::routing::post(stop_handler)).with_state(state).layer(axum::extract::DefaultBodyLimit::max(2*1024*1024));
   let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;let address=listener.local_addr()?;*stop.listener.lock().unwrap()=Some(address);
   let mut server=tokio::spawn(async move{axum::serve(listener,app).with_graceful_shutdown(async{let _=shutdown_rx.await;}).await});
   let _owned_server=OwnedTask(server.abort_handle());
   let mut child=tokio::process::Command::new("python3").args([tool.as_str(),schema.as_str(),*width,&base.to_string(),&directory]).env("SEARCH_SETTINGS_NATIVE_URL",format!("http://{address}/internal/search/render")).env("SEARCH_SETTINGS_NATIVE_STOP_URL",format!("http://{address}/internal/search/stop")).env("SEARCH_CROSS_STOP_RECEIPT",&stop.receipt).env("SEARCH_CROSS_STOP_KEY",&stop.key).kill_on_drop(true).stdin(std::process::Stdio::piped()).stdout(std::process::Stdio::piped()).stderr(std::process::Stdio::from(std::fs::File::create(format!("{directory}/runtime.stderr"))?)).spawn()?;
   let mut stdin=child.stdin.take().context("runtime stdin")?;let mut reader=tokio::io::BufReader::new(child.stdout.take().context("runtime stdout")?);let run=async{
    let mut ready_line=String::new();tokio::time::timeout(std::time::Duration::from_secs(30),reader.read_line(&mut ready_line)).await??;let ready:Value=serde_json::from_str(&ready_line)?;anyhow::ensure!(ready["ready"]==true,"actual runtime bootstrap");
    let opened=runtime_command(&mut stdin,&mut reader,json!({"command":"open"})).await?;anyhow::ensure!(opened["source"]["type"]=="Create","actual Open producer source");
    let prepared=runtime_command(&mut stdin,&mut reader,json!({"command":"race_prepare","family":family,"caller":caller})).await?;
    let path=prepared["path"].as_str().context("genuine selected caller path")?.to_owned();
    let writer=runtime_command(&mut stdin,&mut reader,json!({"command":"writer_session","family":family})).await?;
    anyhow::ensure!(writer["cookie"].as_str()!=Some(format!("vaak_sess={}",ready["session_cookie"].as_str().unwrap()).as_str()),"distinct legitimate same-owner writer session");
    *hook.settings.lock().unwrap()=Some(writer);
    let baseline=runtime_command(&mut stdin,&mut reader,json!({"command":"get","path":path})).await?;
    let baseline_records=receipts.lock().unwrap().clone();std::fs::write(format!("{directory}/baseline-native-receipts.json"),serde_json::to_vec_pretty(&baseline_records)?)?;anyhow::ensure!(baseline_records.len()==1&&baseline_records[0]["status"]==200,"mandatory genuine baseline native200");
    anyhow::ensure!(baseline_records[0]["request"]["statuses"].as_array().is_some_and(Vec::is_empty),"component-only genuine input; no status fixture variant");
    let selected=baseline_records[0]["request"][&family].as_array().context("real selected component")?;anyhow::ensure!(selected.len()==1,"exact single genuine selected source");
    anyhow::ensure!(baseline_records[0]["request"]["csrf"]!=hook.settings.lock().unwrap().as_ref().unwrap()["fields"]["csrf"],"distinct CSRF tokens");
    let baseline_original=runtime_command(&mut stdin,&mut reader,json!({"command":"get","path":path,"original":true})).await?;anyhow::ensure!(baseline_original["status"]==200&&receipts.lock().unwrap().len()==1,"native-disabled genuine baseline");
    runtime_command(&mut stdin,&mut reader,json!({"command":"race_checkpoint"})).await?;
    hook.armed.store(true,std::sync::atomic::Ordering::SeqCst);
    let changed=runtime_command(&mut stdin,&mut reader,json!({"command":"get","path":path})).await?;
    let records=receipts.lock().unwrap().clone();std::fs::write(format!("{directory}/withdrawal-native-receipts.json"),serde_json::to_vec_pretty(&records)?)?;anyhow::ensure!(records.len()==2&&records[1]["status"]==422&&records[1]["native_html_bytes"]==0&&hook.hits.load(std::sync::atomic::Ordering::SeqCst)==1,"actual final Settings withdrawal must refuse zero HTML");
    for record in &records {anyhow::ensure!(record["elapsed_seconds"].as_f64().is_some_and(|v|v<3.0)&&record["preview_dns_calls"]==0&&record["preview_transport_calls"].as_array().is_some_and(Vec::is_empty),"original3s and component-only zero preview IO");}
    let original=runtime_command(&mut stdin,&mut reader,json!({"command":"get","path":path,"original":true})).await?;anyhow::ensure!(original["status"]==200&&receipts.lock().unwrap().len()==2,"fresh original PHP caller has no native authority");
    runtime_command(&mut stdin,&mut reader,json!({"command":"race_review","family":family,"baseline":baseline["body_file"],"baseline_original":baseline_original["body_file"],"fallback":changed["body_file"],"original":original["body_file"]})).await?;
    runtime_command(&mut stdin,&mut reader,json!({"command":"race_restore"})).await?;
    std::fs::write(format!("{directory}/native-receipts.json"),serde_json::to_vec_pretty(&records)?)?;
    results.push(json!({"width":width,"caller":caller,"family":family,"baseline":200,"withdrawal":422,"native_HTML_bytes":0,"actual_fresh_PHP_follow_action":true,"full104_restored":true}));Ok::<_,anyhow::Error>(())
   }.await;
   // Drain the exact native listener before allowing the runtime's guarded DROP.
   // This runs even when bootstrap or a preserved control assertion failed.
   let _=cancel_tx.send(true);if let Some(sender)=stop.shutdown.lock().unwrap().take(){let _=sender.send(());}
   let drained=tokio::time::timeout(std::time::Duration::from_secs(5),&mut server).await;
   let listener_released=tokio::net::TcpListener::bind(address).await.is_ok();
   let fully_drained=matches!(&drained,Ok(Ok(Ok(()))))&&stop.active.load(std::sync::atomic::Ordering::SeqCst)==0&&listener_released;
   if drained.is_err(){server.abort();let _=server.await;}
   let receipt_result=if fully_drained&&!std::path::Path::new(&stop.receipt).exists(){
    use std::io::Write;std::fs::OpenOptions::new().create_new(true).write(true).open(&stop.receipt).and_then(|mut file|file.write_all(&serde_json::to_vec(&json!({"key":stop.key,"active_renderers":0,"listener_closed":true})).unwrap()))
   }else{Ok(())};
   use tokio::io::AsyncWriteExt;
   let stop_runtime=async{stdin.write_all(b"{\"command\":\"stop\"}\n").await?;stdin.flush().await?;Ok::<_,anyhow::Error>(())};
   let _=tokio::time::timeout(std::time::Duration::from_secs(5),stop_runtime).await;drop(stdin);
   let waited=tokio::time::timeout(std::time::Duration::from_secs(25),child.wait()).await;
   let status=if waited.is_err(){child.start_kill()?;tokio::time::timeout(std::time::Duration::from_secs(5),child.wait()).await??}else{waited.unwrap()?};
   std::fs::write(format!("{directory}/listener-cleanup.json"),serde_json::to_vec_pretty(&json!({"drained":fully_drained,"listener_released":listener_released,"runtime_reaped":true,"runtime_exit":status.code()}))?)?;
   receipt_result?;anyhow::ensure!(fully_drained&&status.success(),"owned Settings runtime/native cleanup failed; retain namespace on missing drain proof");run?;
  }}
  let db=crate::db::connect("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres").await?;let cleanup=db.query("SELECT schema_name FROM information_schema.schemata WHERE schema_name=ANY($1::text[])",&[&schemas]).await?;anyhow::ensure!(cleanup.is_empty(),"owned Settings boundary schemas remain");
  std::fs::write(output,serde_json::to_vec_pretty(&json!({"controls":results,"cleanup_owned_schemas":0,"scope":"actual component-only PHP full/append follow fallback; raw account-follow seed and actual distinct-session unfollow POST; no remote Undo delivery claim","whole_search_parity":false}))?)?;Ok(())
 }
