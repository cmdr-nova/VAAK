#!/usr/bin/env python3
"""Prepare an isolated CLI using genuine private admission/painter modules.
No native runtime edits, expected HTML edits, current facts or permission injection.
"""
import json,pathlib,re,subprocess,sys
root=pathlib.Path(__file__).resolve().parents[2]
runtime=pathlib.Path('/tmp/vaak-quote-media-independent-runtime');(runtime/'src').mkdir(parents=True,exist_ok=True)
code=subprocess.check_output([sys.executable,str(root/'api/bin/remote-direct-native-independent.py')]).decode()
# The generic emitter reads main's declaration list but not its cfg(test)
# annotation. This separate CLI does not import the unrelated HTTP test server.
code=re.sub(r'^#\[path=.*\] mod preview_fixture_server;\n','',code,flags=re.M)
code=code[:code.index('async fn restore(')]+r'''
async fn restore(db:&tokio_postgres::Client,table:&str,rows:&serde_json::Value)->anyhow::Result<()>{
 db.batch_execute(&format!("DELETE FROM {table}")).await?;
 for row in rows.as_array().into_iter().flatten(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await?;}Ok(())
}
#[tokio::main]async fn main()->anyhow::Result<()>{
 use anyhow::{bail,Context};use serde_json::{Value,json};
 let url=std::env::var("LIBRARY_FIXTURE_DB")?;if !url.contains("/tmp/"){bail!("disposable PostgreSQL required")}
 let db=crate::db::connect(&url).await?;db.batch_execute("SET synchronous_commit=off").await?;
 let original:Value=serde_json::from_slice(&std::fs::read(std::env::var("QUOTE_MEDIA_REFERENCE").unwrap_or("/tmp/vaak-quote-media-placement-final-reference.json".into()))?)?;
 let metadata:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-quote-media-placement-schema-reference.json")?)?;
 let mut output=Vec::new();
 for width in ["INTEGER","BIGINT"]{
  let schema=format!("quote_media_independent_{}_{}",width.to_ascii_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await?;
  let tables=metadata["current_schema_by_width"][width].as_object().context("actual schema missing")?;
  for(table,columns)in tables{let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await?;}
  for row in original["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
   for(table,rows)in row["current_tables"].as_object().unwrap(){restore(&db,table,rows).await?;}
   let mut intent=row.clone();let view=row["context"]["view"].as_str().unwrap();
   intent["view"]=json!(view);intent["context"]["route"]=json!(if view=="remote_profile"{"remote_profile"}else{"status"});intent["context"]["rendered_at"]=row["context"]["clock"].clone();intent["initial_status_budget"]=json!(0);
   let result=crate::direct_context::independent_run(&db,&intent).await?;
   output.push(json!({"width":width,"case":row["case"],"context":row["context"],"original_source":row["source"],"result":result,"php":row["php"],"intent_mapping":"Actual Home/Search nonfocused shared painter return view; private Direct Status token used only as existing supported admission capability; profile uses RemoteProfile token. No HTTP/caller integration claim."}));
  }
  db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;
 }
 let path=std::env::var("QUOTE_MEDIA_NATIVE_OUTPUT").unwrap_or("/tmp/vaak-quote-media-full66-native.json".into());std::fs::write(&path,serde_json::to_vec(&output)?)?;println!("Actual full-card replay {} records: {path}",output.len());Ok(())
}
'''
manifest=(root/'rust/vaak-worker/Cargo.toml').read_text().replace('name = "vaak-worker"','name = "quote-media-independent"').replace('path = "../vaak-types"','path = '+json.dumps(str(root/'rust/vaak-types')))
(runtime/'Cargo.toml').write_text(manifest);(runtime/'src/main.rs').write_text(code);print(runtime)
