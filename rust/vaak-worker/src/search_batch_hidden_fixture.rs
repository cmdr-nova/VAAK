// Append/include as a cfg(test) child module of hidden.rs. Source-only; no test runner.
use super::*;
use serde_json::json;
fn set(values:&HashSet<String>)->Vec<String>{let mut out=values.iter().cloned().collect::<Vec<_>>();out.sort();out}
fn exact(h:&HiddenSets)->Value {
    // SQL row order is unspecified. Sort phrase MULTISET only: retain every value
    // and duplicate count. Never modify either production loader or painter input.
    let mut phrases=h.muted_phrases.clone();phrases.sort();
    let mut own=h.ordinary_owned_notes.iter().collect::<Vec<_>>();own.sort_by(|a,b|a.0.cmp(b.0));
    json!({"muted_actors":set(&h.muted_actors),"user_blocked_actors":set(&h.user_blocked_actors),"user_blocked_domains":set(&h.user_blocked_domains),"instance_blocked_actors":set(&h.instance_blocked_actors),"instance_muted_actors":set(&h.instance_muted_actors),"instance_blocked_domains":set(&h.instance_blocked_domains),"muted_phrases_multiset":phrases,"ordinary_owned_notes":own})
}
async fn original(db:&Client,owner:i64)->Result<HiddenSets>{
    db.batch_execute("SAVEPOINT batch_hidden_original").await?;
    let timing=crate::search_context::selection::fixture_performance::DifferentialTimer::new("hidden_original",0);
    let result=load_hidden_sets(db,owner).await;timing.finish(&result);
    if result.is_err(){db.batch_execute("ROLLBACK TO SAVEPOINT batch_hidden_original").await?;}
    db.batch_execute("RELEASE SAVEPOINT batch_hidden_original").await?;result
}
async fn candidate(db:&Client,owner:i64)->Result<HiddenSets>{
    db.batch_execute("SAVEPOINT batch_hidden_candidate").await?;
    let timing=crate::search_context::selection::fixture_performance::DifferentialTimer::new("hidden_candidate",0);
    let result=load_search_hidden_sets(db,owner).await;timing.finish(&result);
    if result.is_err(){db.batch_execute("ROLLBACK TO SAVEPOINT batch_hidden_candidate").await?;}
    db.batch_execute("RELEASE SAVEPOINT batch_hidden_candidate").await?;result
}
fn sqlstate(error:&anyhow::Error)->Option<String>{error.chain().find_map(|cause|cause.downcast_ref::<tokio_postgres::Error>().and_then(|e|e.code()).map(|c|c.code().to_owned()))}
/// For errors caller gives the SQLSTATE proved by its owned relation mutation;
/// missing word table is 42P01 for authenticated users, even when Search clears phrases.
pub(crate) async fn run(db:&Client,owner:i64,statuses:&[Value],actors:&[String],label:&str,expected_error:Option<&str>)->Result<Value>{
    let before=statuses.to_vec();let old=original(db,owner).await;let new=candidate(db,owner).await;
    if let Some(expected)=expected_error {
        let old=old.err().ok_or_else(||anyhow::anyhow!("original moderation did not fail: {label}"))?;
        let new=new.err().ok_or_else(||anyhow::anyhow!("candidate moderation did not fail: {label}"))?;
        anyhow::ensure!(sqlstate(&old).as_deref()==Some(expected),"wrong original SQLSTATE: {label}");
        anyhow::ensure!(sqlstate(&new).as_deref()==Some(expected),"wrong candidate SQLSTATE: {label}");
        return Ok(json!({"case":label,"owner":owner,"error_sqlstate":expected,"both_fail_closed":true}));
    }
    let mut old=old?;let mut new=new?;let old_exact=exact(&old);let new_exact=exact(&new);
    anyhow::ensure!(old_exact==new_exact,"complete moderation values differ: {label}");
    let old_actors=actors.iter().map(|a|old.is_hidden(a)).collect::<Vec<_>>();
    let new_actors=actors.iter().map(|a|new.is_hidden(a)).collect::<Vec<_>>();
    anyhow::ensure!(old_actors==new_actors,"actual actor moderation differs: {label}");
    let old_before=statuses.iter().map(|s|status_hidden(s,&old)).collect::<Vec<_>>();
    let new_before=statuses.iter().map(|s|status_hidden(s,&new)).collect::<Vec<_>>();
    anyhow::ensure!(old_before==new_before,"pre-clear full-tree moderation differs: {label}");
    old.muted_phrases.clear();new.muted_phrases.clear();
    let old_after=statuses.iter().map(|s|status_hidden(s,&old)).collect::<Vec<_>>();
    let new_after=statuses.iter().map(|s|status_hidden(s,&new)).collect::<Vec<_>>();
    anyhow::ensure!(old_after==new_after,"actual Search phrase-clear tree moderation differs: {label}");
    anyhow::ensure!(statuses==before.as_slice(),"moderation differential mutated DTOs: {label}");
    Ok(json!({"case":label,"owner":owner,"sets":old_exact,"actor_mask":old_actors,"before_phrase_clear":old_before,"after_phrase_clear":old_after}))
}
