//! Opt-in genuine current-PHP secondary source comparison. No source facts or
//! admission are synthesized; only declared fixture SQL is restored.
use super::*;
#[tokio::test]
#[ignore="requires disposable PostgreSQL and genuine header secondary reference"]
async fn actual_profile_secondary_source_replay(){
 let raw=std::fs::read("/tmp/vaak-local-profile-header-secondary-selector-reference.json").unwrap();let reference:Value=serde_json::from_slice(&raw).unwrap();
 let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();let(db,task)=tokio_postgres::connect(&connection,tokio_postgres::NoTls).await.unwrap();tokio::spawn(async move{task.await.unwrap()});
 let mut output=Vec::new();
 for width in ["INTEGER","BIGINT"]{
  let schema=format!("profile_secondary_{}_{}",std::process::id(),width.to_ascii_lowercase());db.batch_execute(&format!("CREATE SCHEMA {schema}; SET search_path TO {schema}")).await.unwrap();
  for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let definition=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({definition})")).await.unwrap();}
  for capture in reference["captures"].as_array().unwrap().iter().filter(|capture|capture["width"]==width){
   for(table,rows)in capture["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
   let key=capture["context"]["key"].as_str().unwrap();let actor=capture["context"]["actor"].as_str().unwrap();
   for(kind,expected)in [("pinned","pinned_rows"),("asks","answered_outbox")]{
    let selected=select_secondary(&db,key,actor,kind).await.unwrap();assert_eq!(Value::Array(selected.rows().to_vec()),capture["selected_secondary"][expected],"{width} {} {kind}",capture["case"]);
    let count=if kind=="asks"{capture["selected_secondary"]["answered_rows"].as_array().unwrap().len()}else{capture["selected_secondary"][expected].as_array().unwrap().len()};assert_eq!(selected.page().source_count(),count);
    assert_eq!(selected.page().next_offset(),count as i64);assert!(!selected.page().has_more());selected.page().require_current_ledger(&db).await.unwrap();
    let selected_rows=selected.rows().to_vec();let ordinals=selected.ordinals.clone();
    if kind=="asks"&&count==3{assert_eq!(ordinals,vec![0,2]);assert_eq!(selected_rows[0],selected_rows[1]);}
    output.push(serde_json::json!({"width":width,"case":capture["case"],"kind":kind,"rows":selected_rows,"source_count":count,"ordinals":ordinals,"has_more":false,"next_offset":count}));
   }
   if capture["selected_secondary"]["answered_rows"].as_array().unwrap().len()==3{
    let selected=select_secondary(&db,key,actor,"asks").await.unwrap();db.execute("UPDATE ap_asks SET question=question||' later current question' WHERE answer_note_id LIKE '%missing%'",&[]).await.unwrap();assert!(selected.page().require_current_ledger(&db).await.is_err(),"omitted answered source mutation must invalidate selection");
   }
  }
  db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
 }
 assert_eq!(output.len(),348);std::fs::write("/tmp/vaak-profile-secondary-source-native.json",serde_json::to_vec(&output).unwrap()).unwrap();println!("174 genuine captures, 348 exact secondary selections, sparse duplicate ordinals and omitted-source invalidation passed");
}
