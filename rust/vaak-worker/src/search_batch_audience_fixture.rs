// Append/include as a cfg(test) child module of audience.rs. Source-only; no test runner.
use super::*;
use serde_json::json;

fn exact_records(records:&HashMap<String,Audience>)->Value {
    let mut entries=records.iter().collect::<Vec<_>>();entries.sort_by(|a,b|a.0.cmp(b.0));
    Value::Array(entries.into_iter().map(|(uri,a)| {
        let mut recipients=a.recipients.iter().cloned().collect::<Vec<_>>();recipients.sort();
        json!({"uri":uri,"denied":a.denied,"visibility":a.visibility,"author":a.author,"recipients":recipients})
    }).collect())
}
fn effective_records(records:&HashMap<String,Audience>)->Value {
    let mut out=exact_records(records);
    for record in out.as_array_mut().unwrap(){record["visibility"]=json!(record["visibility"].as_str().unwrap().trim().to_ascii_lowercase());}
    out
}
async fn verify_only_equal_alias_visibility(db:&Client,old:&HashMap<String,Audience>,new:&HashMap<String,Audience>,label:&str)->Result<()> {
    anyhow::ensure!(effective_records(old)==effective_records(new),"complete effective audience records differ: {label}");
    for (uri,a) in old {
        let b=new.get(uri).ok_or_else(||anyhow::anyhow!("candidate audience row missing: {label}"))?;
        if a.visibility==b.visibility{continue}
        // The only permitted raw-value difference is the original unspecified
        // canonical/slash outbox winner, proven equal by actual current rows.
        let variants=vec![uri.clone(),format!("{uri}/")];
        let rows=db.query("SELECT id,COALESCE(visibility,'public'),COALESCE(to_json,'[]'),COALESCE(cc_json,'[]') FROM outbox_notes WHERE id=ANY($1)",&[&variants]).await?;
        anyhow::ensure!(rows.len()==2,"raw visibility differs without two actual alias rows: {label}");
        let ids=rows.iter().map(|r|r.get::<_,String>(0)).collect::<HashSet<_>>();
        anyhow::ensure!(ids==variants.into_iter().collect(),"alias pair identity differs: {label}");
        let raw_visibilities=rows.iter().map(|r|r.get::<_,String>(1)).collect::<HashSet<_>>();
        anyhow::ensure!(raw_visibilities.contains(&a.visibility)&&raw_visibilities.contains(&b.visibility),"raw visibility winner not present in actual alias rows: {label}");
        let mut previous_recipients:Option<HashSet<String>>=None;
        for row in rows {
            let visibility=row.get::<_,String>(1);
            anyhow::ensure!(visibility.trim().to_ascii_lowercase()==a.visibility.trim().to_ascii_lowercase(),"alias visibility not equivalent: {label}");
            let mut recipients=HashSet::new();
            for column in [2,3]{if let Ok(Value::Array(values))=serde_json::from_str::<Value>(&row.get::<_,String>(column)){for value in values{if let Some(v)=value.as_str(){recipients.insert(key(v));}}}}
            // Delivered owner recipients may supplement the durable set. Compare
            // the two actual durable row sets, not that supplemented map.
            if let Some(previous)=previous_recipients.replace(recipients.clone()){anyhow::ensure!(previous==recipients,"actual alias recipient sets conflict: {label}");}
            let first=&old.get(uri).unwrap().recipients;
            anyhow::ensure!(recipients.is_subset(first),"alias recipients escape actual original record: {label}");
        }
    }
    Ok(())
}
fn exact_following(values:&HashSet<String>)->Vec<String>{let mut out=values.iter().cloned().collect::<Vec<_>>();out.sort();out}

/// Caller owns lifecycle AND a REPEATABLE READ READ ONLY transaction.
/// Input statuses are unchanged actual hydrated fixture DTOs; no constructed card oracle.
/// Conflict mode is a refusal assertion, never an arbitrary original winner assertion.
pub(crate) async fn run(db:&Client,owner:i64,statuses:&[Value],label:&str,expect_conflict:bool)->Result<Value>{
    anyhow::ensure!(!statuses.is_empty(),"empty audience differential case {label}");
    let before=statuses.to_vec();
    let timing=crate::search_context::selection::fixture_performance::DifferentialTimer::new("audience_original",statuses.len());
    let original=audience_records(db,owner,statuses).await;timing.finish(&original);
    let (old,old_viewer,old_follows)=original?;
    if owner>0 {
        let row=db.query_opt("SELECT actor_id FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&owner]).await?;
        let expected=row.map(|r|key(&r.get::<_,String>(0))).unwrap_or_default();
        anyhow::ensure!(old_viewer==expected,"old viewer did not use exactly one original key normalization: {label}");
    }
    // A controlled failure is caught inside an owned SAVEPOINT so old/new query errors
    // do not poison the caller transaction or trigger fixture cleanup in this module.
    db.batch_execute("SAVEPOINT batch_differential_candidate").await?;
    let timing=crate::search_context::selection::fixture_performance::DifferentialTimer::new("audience_candidate",statuses.len());
    let candidate=search_audience_records(db,owner,statuses).await;timing.finish(&candidate);
    if candidate.is_err(){db.batch_execute("ROLLBACK TO SAVEPOINT batch_differential_candidate").await?;}
    db.batch_execute("RELEASE SAVEPOINT batch_differential_candidate").await?;
    anyhow::ensure!(statuses==before.as_slice(),"audience differential mutated DTOs: {label}");
    if expect_conflict {
        let error=candidate.err().ok_or_else(||anyhow::anyhow!("candidate accepted conflicting canonical/slash audiences: {label}"))?;
        anyhow::ensure!(error.to_string()=="PHP fallback: conflicting canonical/slash Search audience rows","unexpected conflict failure {label}: {error}");
        return Ok(json!({"case":label,"owner":owner,"statuses":statuses.len(),"safe_refusal":true,"old_viewer":old_viewer}));
    }
    let(new,new_viewer,new_follows)=candidate?;
    let old_records=exact_records(&old);let new_records=exact_records(&new);
    if old_records!=new_records {verify_only_equal_alias_visibility(db,&old,&new,label).await?;}
    anyhow::ensure!(old_viewer==new_viewer,"once-normalized viewer differs: {label}");
    anyhow::ensure!(old_follows==new_follows,"complete following sets differ: {label}");
    let old_mask=positional_decisions(statuses,&old,&old_viewer,&old_follows,Surface::Search);
    let new_mask=positional_decisions(statuses,&new,&new_viewer,&new_follows,Surface::Search);
    anyhow::ensure!(old_mask==new_mask,"actual Search tree decisions differ: {label}");
    Ok(json!({"case":label,"owner":owner,"statuses":statuses.len(),"original_records":old_records,"candidate_records":new_records,"viewer":old_viewer,"following":exact_following(&old_follows),"mask":old_mask,"safe_refusal":false}))
}
