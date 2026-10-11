#!/usr/bin/env python3
"""Emit actual Search bridge HTTP+DB checks for disposable schema widths only."""
import pathlib, subprocess
root = pathlib.Path(__file__).resolve().parents[2]
source = subprocess.check_output(['python3', str(root/'api/bin/search-context-postgres-fixture.py')], text=True)
bridge = (root/'rust/vaak-worker/src/search_bridge.rs').read_text().replace('//!', '//')
fixture = r'''
pub(crate) async fn fixture(db:&tokio_postgres::Client,remote:&Value,own:&Value)->anyhow::Result<()> {
 db.batch_execute("ALTER TABLE ap_users ADD username TEXT DEFAULT 'viewer';ALTER TABLE following ADD host TEXT;ALTER TABLE actor_profile ADD vanity_verified BIGINT DEFAULT 0;CREATE TABLE remote_actors(actor_id TEXT,username TEXT,host TEXT);CREATE TABLE masto_account_actors(account_id TEXT,actor_id TEXT);CREATE TABLE bsky_graph_sync(owner_user_id BIGINT,kind TEXT,target_did TEXT);CREATE TABLE bsky_actor_profiles(actor_ref TEXT,did TEXT,profile_json TEXT,updated_at TEXT);CREATE TABLE masto_followed_tags(owner_user_id BIGINT,name TEXT);").await?;
 let schema:String=db.query_one("SELECT current_schema()",&[]).await?.get(0);
 let url=format!("{} options='-c search_path={schema}'",std::env::var("LIBRARY_FIXTURE_DB")?);
 let app=axum::Router::new().route("/internal/search/render",axum::routing::post(move |headers:HeaderMap,bytes:Bytes|{let url=url.clone();async move{render_request(&url,Some("isolated-search-fixture-token"),headers,bytes).await}})).layer(axum::extract::DefaultBodyLimit::max(MAX_BYTES));
 let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;let address=listener.local_addr()?;let server=tokio::spawn(async move{axum::serve(listener,app).await.unwrap()});
 let client=reqwest::Client::builder().no_proxy().build()?;let endpoint=format!("http://{address}/internal/search/render");
 let account=json!({"id":"0","uri":"https://other.test/@person","url":"https://other.test/@person","acct":"person@other.test","display_name":"Person"});let tag=json!({"name":"rust"});
 let mut denied=remote.clone();denied["uri"]=json!("https://unknown.test/no-source");
 let request=json!({"owner_id":1,"csrf":"fixture-csrf","bsky_enabled":false,"accounts":[account.clone()],"hashtags":[tag.clone()],"statuses":[remote.clone(),denied,remote.clone(),own.clone()]});
 let response=client.post(&endpoint).header("x-vaak-search-token","isolated-search-fixture-token").json(&request).send().await?;assert_eq!(response.status(),200);let output:Value=response.json().await?;
 assert_eq!(output["source_counts"],json!({"accounts":1,"hashtags":1,"statuses":4}));assert_eq!(output["accounts"].as_array().unwrap().len(),1);assert_eq!(output["hashtags"].as_array().unwrap().len(),1);assert_eq!(output["statuses"].as_array().unwrap().iter().map(|v|v["source_index"].as_u64().unwrap()).collect::<Vec<_>>(),vec![0,2,3]);assert_eq!(output["statuses"][0]["html"],output["statuses"][1]["html"]);
 let components=crate::search_components::ComponentContext::load(db,Some(1),&[account.clone()]).await?;assert_eq!(output["accounts"][0],crate::search_render::render_account(&components.resolve_account(&account)?));assert_eq!(output["hashtags"][0],crate::search_render::render_tag(&components.resolve_tag(&tag)?));
 let session=crate::search_context::SearchSession::from_php_session(1,"fixture-csrf".into(),false)?;let tokens=crate::search_context::admit_for_render(db,&session,vec![remote.clone(),own.clone()]).await?;assert_eq!(output["statuses"][0]["html"],crate::library::card::render_search_admitted(&tokens[0])?);assert_eq!(output["statuses"][2]["html"],crate::library::card::render_search_admitted(&tokens[1])?);
 let mut unsupported=request.clone();unsupported["statuses"]=json!([{"uri":"https://remote.test/thin","account":{}}]);let response=client.post(&endpoint).header("x-vaak-search-token","isolated-search-fixture-token").json(&unsupported).send().await?;assert_eq!(response.status(),422);assert_eq!(response.json::<Value>().await?,json!({"fallback":true}),"earlier account/tag output must not escape failure");
 let mut empty=request.clone();empty["statuses"]=json!([]);empty["owner_id"]=json!(2);let response=client.post(&endpoint).header("x-vaak-search-token","isolated-search-fixture-token").json(&empty).send().await?;assert_eq!(response.status(),200);
 let uri="at://did:plc:author/app.bsky.feed.post/key";let mut provenance=request.clone();provenance["statuses"]=json!([{"uri":uri,"content":"Native body","account":{"uri":"https://bsky.app/profile/did:plc:author","acct":"author.test"}}]);provenance["bsky_bookmarks"]=json!([{"uri":uri,"did":"did:plc:foreign","bookmarked":true}]);let response=client.post(&endpoint).header("x-vaak-search-token","isolated-search-fixture-token").json(&provenance).send().await?;assert_eq!(response.status(),422,"current owner DID must bind supplied state");
 db.batch_execute("UPDATE ap_users SET disabled_at='disabled' WHERE id=2;").await?;let response=client.post(&endpoint).header("x-vaak-search-token","isolated-search-fixture-token").json(&empty).send().await?;assert_eq!(response.status(),422,"account-only batches still require active owner");db.batch_execute("UPDATE ap_users SET disabled_at=NULL WHERE id=2;").await?;
 server.abort();println!("SEARCH BRIDGE HTTP/DB PASS: exact component/status tokens, source counts/gap/duplicate slots, all-or-nothing unsupported, owner switch/inactive and provenance");Ok(())
}
'''
line = next(line for line in source.splitlines() if line.endswith('mod search_bridge;'))
source = source.replace(line, 'mod search_bridge {\n'+bridge+'\n'+fixture+'\n}')
source = source.replace(' db.batch_execute("UPDATE ap_users SET disabled_at=\'disabled\' WHERE id=1;', ' crate::search_bridge::fixture(db,&remote,&own).await?;\n db.batch_execute("UPDATE ap_users SET disabled_at=\'disabled\' WHERE id=1;', 1)
print(source)
