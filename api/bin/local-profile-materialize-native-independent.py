#!/usr/bin/env python3
"""Disposable actual selection→projection runner; no PHP status injection."""
import pathlib,subprocess,re,json,sys
root=pathlib.Path(__file__).resolve().parents[2];src=root/'rust/vaak-worker/src';runtime=pathlib.Path('/tmp/vaak-profile-materialize-independent-runtime');(runtime/'src').mkdir(parents=True,exist_ok=True)
tail=r'''
async fn restore(db:&tokio_postgres::Client,table:&str,rows:&serde_json::Value)->anyhow::Result<()>{db.batch_execute(&format!("DELETE FROM {table}")).await?;for row in rows.as_array().into_iter().flatten(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await?;}Ok(())}
#[tokio::main]async fn main()->anyhow::Result<()>{use anyhow::bail;use serde_json::{Value,json};let url=std::env::var("LIBRARY_FIXTURE_DB")?;if !url.contains("/tmp/"){bail!("disposable local PostgreSQL only")}
 let db=crate::db::connect(&url).await?;db.batch_execute("SET synchronous_commit=off").await?;let input:Value=serde_json::from_slice(&std::fs::read(std::env::var("PROFILE_CARD_REFERENCE").unwrap_or("/tmp/vaak-local-profile-shared-card-genuine-lookup-reference.json".into()))?)?;let mut output=Vec::new();
 for width in ["INTEGER","BIGINT"]{let schema=format!("profile_materialize_native_{}_{}",width.to_ascii_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await?;
  for(table,columns)in input["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",c["column_name"].as_str().unwrap(),c["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await?;}
  for row in input["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){for(table,rows)in row["current_tables"].as_object().unwrap(){restore(&db,table,rows).await?;}let result=crate::profile_materialize::independent_project(&db,row).await?;output.push(json!({"width":width,"case":row["case"],"branch":row["branch"],"original_source":row["source"],"caller_provenance":row["caller_provenance"],"result":result,"php":row["php"]}));}
  db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await?;
 }
 let out=std::env::var("PROFILE_CARD_NATIVE_OUTPUT").unwrap_or("/tmp/vaak-profile-materialize-native-projection.json".into());std::fs::write(&out,serde_json::to_vec(&output)?)?;println!("Profile representative native diagnostic {} cards: {out}",output.len());Ok(())}
'''
code=subprocess.check_output([sys.executable,str(root/'api/bin/remote-direct-native-independent.py')]).decode();code=code[:code.index('async fn restore(')]
code=re.sub(r'^#\[path=.*\] mod preview_fixture_server;\n','',code,flags=re.M)
body=re.sub(r'^//!','//',(src/'profile_materialize.rs').read_text(),flags=re.M)
body+=r'''
pub(crate) async fn independent_project(db:&Client,r:&Value)->Result<Value>{
 let actor=r["caller_provenance"]["actor"].as_str().unwrap();let key=actor.rsplit('/').next().unwrap();
 let selected=crate::profile_source::select(db,key,actor,r["caller_provenance"]["tab"].as_str().unwrap(),10,0).await?;
 let index=r["source_index"].as_u64().unwrap() as usize;let original=selected.rows()[index].clone();
 let session=AuthenticatedProfileSession::from_php_session(r["context"]["owner"].as_i64().unwrap(),"fixture-csrf-token".into(),key.into(),actor.into(),false,0)?;
 let page=match materialize(db,&ProfileViewer::Auth(&session),selected).await{Ok(page)=>page,Err(e)=>return Ok(json!({"fallback":e.to_string(),"selected_source":original}))};
 page.require_current(db).await?;
 let source=page.sources().iter().find(|s|s.source_index()==index).unwrap();
 let status=match source.source(){ProfileSourceKind::Shared(s)=>json!({"status":s}),ProfileSourceKind::Raw(s)=>json!({"raw":s}),ProfileSourceKind::Omitted(_)=>json!({"omitted":true})};
 let mut result=status;result["selected_source"]=original;result["source_count"]=json!(page.page().source_count());result["next_offset"]=json!(page.page().next_offset());result["has_more"]=json!(page.page().has_more());
 Ok(result)
}
'''
code=re.sub(r'^#\[path=.*\] mod profile_materialize;\n',lambda _: 'mod profile_materialize {\n'+body+'\n}\n',code,flags=re.M)
(runtime/'src/main.rs').write_text(code+tail)

manifest=(root/'rust/vaak-worker/Cargo.toml').read_text().replace('name = "vaak-worker"','name = "local-profile-materialize-independent"').replace('path = "../vaak-types"','path = '+json.dumps(str(root/'rust/vaak-types')))
(runtime/'Cargo.toml').write_text(manifest);print(runtime)
