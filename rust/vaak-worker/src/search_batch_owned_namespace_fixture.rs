// Caller supplies namespace/OID only AFTER the existing supervisor proves ownership.
// This guard supplements that proof; it cannot substitute for marker/catalog/lifecycle receipts.
use anyhow::Result;
use tokio_postgres::Client;
pub(crate) async fn require(db:&Client,namespace:&str,oid:i64)->Result<()> {
    anyhow::ensure!(namespace!="public"&&!namespace.starts_with("pg_")&&oid>0,"unsafe differential namespace");
    let row=db.query_one("SELECT current_schema(), current_schemas(false), (SELECT oid::bigint FROM pg_namespace WHERE nspname=current_schema()), current_setting('transaction_isolation'), current_setting('transaction_read_only')",&[]).await?;
    anyhow::ensure!(row.get::<_,String>(0)==namespace,"wrong current differential namespace");
    anyhow::ensure!(row.get::<_,Vec<String>>(1)==vec![namespace.to_owned()],"unexpected search_path includes unowned schemas");
    anyhow::ensure!(row.get::<_,Option<i64>>(2)==Some(oid),"differential namespace OID changed");
    anyhow::ensure!(row.get::<_,String>(3)=="repeatable read"&&row.get::<_,String>(4)=="on","differential requires one read-only repeatable-read snapshot");
    Ok(())
}
