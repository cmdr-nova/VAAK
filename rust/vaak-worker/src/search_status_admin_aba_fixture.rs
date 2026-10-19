// cfg(test) child of search_selection.rs; actual PHP noURL one-status capture.
use super::*;
use std::sync::{Arc,atomic::{AtomicUsize,Ordering}};
struct AdminAba{owner:i64,stage:AtomicUsize}
impl AdminAba {
 async fn restore(&self,db:&Client)->Result<()> {
  if self.stage.load(Ordering::SeqCst)==1{anyhow::ensure!(db.execute("UPDATE ap_users SET is_admin=0 WHERE id=$1::bigint AND is_admin=1",&[&self.owner]).await?<=1,"exact owned admin cleanup cardinality");self.stage.store(2,Ordering::SeqCst);}Ok(())
 }
}
impl final_facts::boundary_fixture::Hook for AdminAba {
 fn at<'a>(&'a self,db:&'a Client,phase:&'a str)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{
  Box::pin(async move{
   if phase=="before_status_card_facts" {
    anyhow::ensure!(self.stage.load(Ordering::SeqCst)==0,"exact one status facts control");
    self.stage.store(1,Ordering::SeqCst);anyhow::ensure!(db.execute("UPDATE ap_users SET is_admin=1 WHERE id=$1::bigint AND is_admin=0",&[&self.owner]).await?==1,"owned typed admin mutation before real facts query");self.stage.store(1,Ordering::SeqCst);
   }else if phase=="after_status_card_facts"{self.restore(db).await?;}Ok(())
  })
 }
}
#[tokio::test]
#[ignore="separate reviewed owned PG lifecycle and pinned actual PHP noURL status capture required"]
async fn actual_bridge_rejects_painted_admin_aba_but_accepts_fresh_roles()->Result<()> {
 let input:Value=serde_json::from_slice(&std::fs::read(std::env::var("VAAK_SEARCH_STATUS_ADMIN_ABA_INPUT")?)?)?;
 let schema=input["schema"].as_str().context("original owned schema")?;anyhow::ensure!(regex::Regex::new(r"^search_settings_final_boundary_(integer|bigint)_[0-9]+_[0-2]$")?.is_match(schema));
 let token=input["token"].as_str().context("original UUID marker")?;anyhow::ensure!(regex::Regex::new(r"^[a-f0-9-]{36}$")?.is_match(token));
 let app=format!("search_paint_admin_{}",std::process::id());let base_database=format!("host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path={schema}'");let database=format!("{base_database} application_name={app}");let db=crate::db::connect(&base_database).await?;
 let owned=db.query_one("SELECT oid::text,pg_get_userbyid(nspowner),obj_description(oid,'pg_namespace'),current_schema(),current_user FROM pg_namespace WHERE nspname=$1",&[&schema]).await?;
 anyhow::ensure!(owned.get::<_,String>(0)==input["oid"].as_str().context("original OID")?&&owned.get::<_,String>(1)=="postgres"&&owned.get::<_,Option<String>>(2).as_deref()==Some(token)&&owned.get::<_,String>(3)==schema&&owned.get::<_,String>(4)=="postgres","original owned OID/owner/UUID guard");
 let typed:bool=db.query_one("SELECT EXISTS(SELECT 1 FROM pg_attribute WHERE attrelid='ap_users'::regclass AND attname='is_admin' AND atttypid IN (20,23) AND NOT attisdropped)",&[]).await?.get(0);anyhow::ensure!(typed,"actual INTEGER/BIGINT role column only");
 let bytes=std::fs::read(input["request_file"].as_str().context("actual PHP source capture")?)?;use sha2::Digest;
 anyhow::ensure!(bytes.len()<=2*1024*1024&&hex::encode(sha2::Sha256::digest(&bytes))==input["request_sha256"].as_str().context("capture SHA")?);
 let request:Value=serde_json::from_slice(&bytes)?;let owner=request["owner_id"].as_i64().context("actual owner")?;let statuses=request["statuses"].as_array().context("actual selected status")?;
 anyhow::ensure!(statuses.len()==1&&request["accounts"].as_array().is_some_and(Vec::is_empty)&&request["hashtags"].as_array().is_some_and(Vec::is_empty),"one genuine status-only window");
 anyhow::ensure!(crate::direct_preview::extract_url(statuses[0]["content"].as_str().context("genuine noURL text")?)?.is_none()&&statuses[0]["media_attachments"].as_array().is_none_or(Vec::is_empty)&&statuses[0]["card"].is_null(),"actual noURL preview branch");
 let identity=db.query_one("SELECT actor_id,actor_key,to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&owner]).await?;let original_owner:Value=identity.get(2);anyhow::ensure!(original_owner["is_admin"]==0,"initial exact nonadmin typed row");
 let descriptor:SearchCollectorDescriptor=serde_json::from_value(request["collector"].clone())?;let grant=SearchWindowGrant::load(&db,owner,descriptor,statuses).await?;let snapshot=grant.raw_snapshot.clone();
 async fn bridge(cleanup:&Client,database:&str,app:&str,bytes:Vec<u8>)->Result<(u16,Value)>{
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
 let baseline=bridge(&db,&database,&app,bytes.clone()).await?;anyhow::ensure!(baseline.0==200,"genuine baseline native200");let ordinary_html=baseline.1["statuses"][0]["html"].as_str().context("actual ordinary actions")?.to_owned();
 // A new, fully valid admin request is a separate action oracle; no fabricated
 // CardFacts or expected admin bit is passed to the production comparison.
 anyhow::ensure!(db.execute("UPDATE ap_users SET is_admin=1 WHERE id=$1::bigint AND is_admin=0",&[&owner]).await?==1);
 let fresh_admin=bridge(&db,&database,&app,bytes.clone()).await;require_backend_absent(&db,&app).await?;let restored=db.execute("UPDATE ap_users SET is_admin=0 WHERE id=$1::bigint AND is_admin=1",&[&owner]).await;anyhow::ensure!(restored?==1);let fresh_admin=fresh_admin?;anyhow::ensure!(fresh_admin.0==200,"fresh admin is lawful separate request");
 let admin_html=fresh_admin.1["statuses"][0]["html"].as_str().context("actual admin action oracle")?.to_owned();anyhow::ensure!(ordinary_html!=admin_html&&admin_html.contains("(server)"),"actual status admin menu differs");
 // Typed fresh role controls use actual SQL/native rendering. NULL is tried
 // only when the original INTEGER/BIGINT column is nullable; no DDL is changed.
 let nullable:bool=db.query_one("SELECT NOT attnotnull FROM pg_attribute WHERE attrelid='ap_users'::regclass AND attname='is_admin' AND NOT attisdropped",&[]).await?.get(0);
 let mut roles=vec![Some(0i64),Some(2i64),Some(-1i64)];if nullable{roles.push(None);}let mut fresh_roles=Vec::new();
 for role in roles{
  anyhow::ensure!(db.execute("UPDATE ap_users SET is_admin=$1::bigint WHERE id=$2::bigint",&[&role,&owner]).await?==1);
  let fresh=bridge(&db,&database,&app,bytes.clone()).await;
  require_backend_absent(&db,&app).await?;
  let restored=db.execute("UPDATE ap_users SET is_admin=0 WHERE id=$1::bigint",&[&owner]).await;anyhow::ensure!(restored?==1);let fresh=fresh?;
  let expected=role.unwrap_or(0)!=0;let html=fresh.1["statuses"][0]["html"].as_str().context("fresh typed role HTML")?;
  anyhow::ensure!(fresh.0==200&&html.contains("(server)")==expected,"original COALESCE(role,0)!=0 action semantics");fresh_roles.push(serde_json::json!({"role":role,"admin":expected,"status":fresh.0}));
 }
 let hook=Arc::new(AdminAba{owner,stage:AtomicUsize::new(0)});let attempted=final_facts::boundary_fixture::run(hook.clone(),bridge(&db,&database,&app,bytes)).await;require_backend_absent(&db,&app).await?;hook.restore(&db).await?;let attempted=attempted?;
 let after=grant.raw_scope.read(&db).await?;let owner_after:Value=db.query_one("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint",&[&owner]).await?.get(0);
 anyhow::ensure!(after==snapshot&&owner_after==original_owner&&hook.stage.load(Ordering::SeqCst)==2,"full typed owner/raw witness restores after actual facts query");
 anyhow::ensure!(attempted.0==422&&attempted.1["fallback"]==true&&attempted.1.get("statuses").is_none(),"restored final role must reject painted intermediate admin actions/zeroHTML");
 use std::io::Write;let mut file=std::fs::OpenOptions::new().create_new(true).write(true).open(std::env::var("VAAK_SEARCH_STATUS_ADMIN_ABA_OUTPUT")?)?;
 file.write_all(&serde_json::to_vec_pretty(&serde_json::json!({"scope":"actual bridge/native status action admin ABA, owned raw SQL control not UI action coverage","baseline_status":baseline.0,"fresh_admin_status":fresh_admin.0,"aba_status":attempted.0,"ordinary_html":ordinary_html,"fresh_admin_html":admin_html,"owner_before":original_owner,"owner_after":owner_after,"raw_witness_restored":true,"fresh_roles":fresh_roles,"nullable_role_tested":nullable,"native_html_bytes":0}))?)?;Ok(())
}
