// cfg(test) child of search_component_admission.rs. Requires an independently
// owned actual PHP accounts-only request capture; no producer rows are seeded.
use super::*;
use std::sync::{Arc,Mutex,atomic::{AtomicUsize,Ordering}};
struct FollowAba {owner:String,provider:String,host:String,stage:AtomicUsize,intermediate_html:Mutex<Option<String>>,accounts:Vec<Value>,target:usize,owner_id:i64}
impl FollowAba {
 async fn restore(&self,db:&Client)->Result<()> {
  if self.stage.load(Ordering::SeqCst)==1 {
   anyhow::ensure!(db.execute("DELETE FROM following WHERE owner_actor_id=$1 AND actor_id=$2",&[&self.owner,&self.provider]).await?<=1,"exact fixture follow cleanup cardinality");
   self.stage.store(2,Ordering::SeqCst);
  }Ok(())
 }
}
impl crate::search_context::selection::final_facts::boundary_fixture::Hook for FollowAba {
 fn at<'a>(&'a self,db:&'a Client,phase:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>> {
  Box::pin(async move {
   if phase=="after_component_expected_before_account_paint" {
    anyhow::ensure!(self.stage.load(Ordering::SeqCst)==0,"one ABA insertion");
    self.stage.store(1,Ordering::SeqCst);anyhow::ensure!(db.execute("INSERT INTO following(owner_actor_id,actor_id,followed_at,host) VALUES($1,$2,'2026-10-13T00:00:00Z',$3)",&[&self.owner,&self.provider,&self.host]).await?==1,"actual durable following mutation");self.stage.store(1,Ordering::SeqCst);
   }else if phase=="after_account_paint_before_status_admission" {
    anyhow::ensure!(self.stage.load(Ordering::SeqCst)==1,"paint must see inserted follow");
    let context=super::super::ComponentContext::load(db,Some(self.owner_id),&self.accounts).await?;
    let token=context.resolve_account(&self.accounts[self.target])?;
    anyhow::ensure!(token.following(),"intermediate actual reader must follow target");
    *self.intermediate_html.lock().unwrap()=Some(crate::search_render::render_account(&token));self.restore(db).await?;
   }Ok(())
  })
 }
}
#[tokio::test]
#[ignore="reviewed actual PHP account capture, sole owned PG lifecycle and separate diagnostic artifact required"]
async fn actual_bridge_rejects_account_following_aba_but_keeps_unchanged()->Result<()> {
 let input:Value=serde_json::from_slice(&std::fs::read(std::env::var("VAAK_SEARCH_ACCOUNT_PAINT_ABA_INPUT")?)?)?;
 let schema=input["schema"].as_str().context("owned schema")?;
 anyhow::ensure!(regex::Regex::new(r"^search_settings_final_boundary_(integer|bigint)_[0-9]+_[0-2]$")?.is_match(schema),"exact owned fixture schema");
 let token=input["token"].as_str().context("original UUID marker")?;anyhow::ensure!(regex::Regex::new(r"^[a-f0-9-]{36}$")?.is_match(token),"original fixture UUID marker");
 let db=crate::db::connect(&format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path={schema}'")).await?;
 let owned=db.query_one("SELECT oid::text,pg_get_userbyid(nspowner),obj_description(oid,'pg_namespace'),current_schema(),current_user FROM pg_namespace WHERE nspname=$1",&[&schema]).await?;
 anyhow::ensure!(owned.get::<_,String>(0)==input["oid"].as_str().context("original OID receipt")?&&owned.get::<_,String>(1)=="postgres"&&owned.get::<_,Option<String>>(2).as_deref()==Some(token)&&owned.get::<_,String>(3)==schema&&owned.get::<_,String>(4)=="postgres","original OID/owner/UUID marker ownership");
 let bytes=std::fs::read(input["request_file"].as_str().context("actual PHP bridge capture")?)?;
 use sha2::Digest;anyhow::ensure!(hex::encode(sha2::Sha256::digest(&bytes))==input["request_sha256"].as_str().context("pinned PHP capture bytes")?,"actual capture changed");
 let request:Value=serde_json::from_slice(&bytes)?;let owner=request["owner_id"].as_i64().context("actual owner")?;
 let accounts=request["accounts"].as_array().context("actual accounts")?.clone();let target=input["target_index"].as_u64().context("actual selected target index")? as usize;
 anyhow::ensure!(target<accounts.len()&&request["statuses"].as_array().is_some_and(Vec::is_empty)&&request["hashtags"].as_array().is_some_and(Vec::is_empty),"genuine bounded accounts-only branch");
 let descriptor:ComponentCollectorDescriptor=serde_json::from_value(request["component_collector"].clone())?;
 let provider=descriptor.account_sources.get(target).context("actual selected provider")?.clone();let host=reqwest::Url::parse(&provider)?.host_str().context("provider host")?.to_owned();
 let identity=db.query_one("SELECT actor_id,actor_key FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&owner]).await?;let actor:String=identity.get(0);let key:String=identity.get(1);
 let rows_before:Value=db.query_one("SELECT COALESCE(jsonb_agg(to_jsonb(f) ORDER BY to_jsonb(f)::text),'[]'::jsonb) FROM following f WHERE owner_actor_id=$1",&[&actor]).await?.get(0);
 anyhow::ensure!(!rows_before.as_array().context("original following rows")?.iter().any(|row|row["actor_id"]==provider),"ABA inserts only absent exact row");
 let context=super::super::ComponentContext::load(&db,Some(owner),&accounts).await?;let token=context.resolve_account(&accounts[target])?;
 anyhow::ensure!(!token.is_me()&&!token.following(),"actual nonself initially unfollowed account");
 let grant=ComponentsGrant::load(&db,owner,actor.clone(),key,&accounts,&[],descriptor).await?;let(scope,snapshot)=grant.raw_binding();
 let baseline_html=crate::search_render::render_account(&token);
 async fn bridge(cleanup:&Client,database:&str,app:&str,bytes:Vec<u8>)->Result<(u16,Value)> {
  let mut headers=axum::http::HeaderMap::new();headers.insert("X-VAAK-Search-Token",axum::http::HeaderValue::from_static("independent-component-collector-fixture-secret-0001"));
  let request=tokio::time::timeout(std::time::Duration::from_secs(3),crate::search_bridge::fixture_request(database,"independent-component-collector-fixture-secret-0001",headers,bytes.into(),&crate::direct_preview::PreviewDependencies::production())).await;
  // Timeout drops the actual render future. Wait for only its exact tagged
  // private backend before restoring fixture rows; never extend acceptance.
  tokio::time::timeout(std::time::Duration::from_secs(5),async{
   loop{let absent:bool=cleanup.query_one("SELECT NOT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name=$1 AND pid<>pg_backend_pid())",&[&app]).await?.get(0);if absent{break}tokio::time::sleep(std::time::Duration::from_millis(10)).await;}Ok::<(),anyhow::Error>(())
  }).await.context("exact fixture backend drain timeout")??;
  let response=request.context("original3s native fixture deadline exceeded (no middleware coverage claim)")?;
  let status=response.status().as_u16();let body=axum::body::to_bytes(response.into_body(),8*1024*1024).await?;Ok((status,serde_json::from_slice(&body)?))
 }
 async fn require_backend_absent(db:&Client,app:&str)->Result<()>{
  let absent:bool=db.query_one("SELECT NOT EXISTS(SELECT 1 FROM pg_stat_activity WHERE application_name=$1 AND pid<>pg_backend_pid())",&[&app]).await?.get(0);
  anyhow::ensure!(absent,"exact native backend still active; preserve owned fixture rows for external lifecycle cleanup");Ok(())
 }
 let app=format!("search_paint_account_{}",std::process::id());let database=format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres application_name={app} options='-c search_path={schema}'");
 let unchanged=bridge(&db,&database,&app,bytes.clone()).await?;anyhow::ensure!(unchanged.0==200&&unchanged.1["accounts"][target].as_str()==Some(baseline_html.as_str()),"unchanged actual bridge account/action parity");
 let hook=Arc::new(FollowAba{owner:actor.clone(),provider,host,stage:AtomicUsize::new(0),intermediate_html:Mutex::new(None),accounts,target,owner_id:owner});
 let attempted=crate::search_context::selection::final_facts::boundary_fixture::run(hook.clone(),bridge(&db,&database,&app,bytes)).await;
 // Restore on all Result failures only after exact native backend absence.
 require_backend_absent(&db,&app).await?;
 let restored=hook.restore(&db).await;restored?;let attempted=attempted?;
 let after=scope.read(&db).await?;let rows_after:Value=db.query_one("SELECT COALESCE(jsonb_agg(to_jsonb(f) ORDER BY to_jsonb(f)::text),'[]'::jsonb) FROM following f WHERE owner_actor_id=$1",&[&actor]).await?.get(0);
 anyhow::ensure!(&after==snapshot&&rows_before==rows_after,"full raw witness and exact following rows restore despite intermediate paint");
 let intermediate=hook.intermediate_html.lock().unwrap().clone().context("actual intermediate action HTML")?;
 anyhow::ensure!(intermediate!=baseline_html&&hook.stage.load(Ordering::SeqCst)==2,"real false/true/false action counterexample");
 anyhow::ensure!(attempted.0==422&&attempted.1["fallback"]==true&&attempted.1.get("accounts").is_none()&&attempted.1.get("statuses").is_none(),"actual bridge must refuse zero HTML even when final raw witness restored");
 let output=std::env::var("VAAK_SEARCH_ACCOUNT_PAINT_ABA_OUTPUT")?;use std::io::Write;
 let mut file=std::fs::OpenOptions::new().create_new(true).write(true).open(output)?;
 file.write_all(&serde_json::to_vec_pretty(&json!({"scope":"actual native bridge; durable following ABA; selected PHP capture bytes pinned externally; not PHP UI action coverage","unchanged_status":unchanged.0,"aba_status":attempted.0,"baseline_html":baseline_html,"intermediate_html":intermediate,"raw_witness_restored":true,"following_rows_before":rows_before,"following_rows_after":rows_after,"native_html_bytes":0}))?)?;Ok(())
}
