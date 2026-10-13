//! Test-only request-local timing. No policy facts or cached allowances.
//! Diagnostic reporters use bounded numeric-only owned-fixture output IO.
use std::sync::{Arc,Mutex};
use std::collections::BTreeMap;
#[derive(Default,serde::Serialize)]
pub(crate) struct Stage{calls:u64,items:u64,inclusive_seconds:f64}
pub(crate) type Metrics=Arc<Mutex<BTreeMap<&'static str,Stage>>>;
tokio::task_local!{static CURRENT:Metrics;}
pub(crate) fn new()->Metrics{Arc::new(Mutex::new(BTreeMap::new()))}
pub(crate) async fn run<F:std::future::Future>(metrics:Metrics,future:F)->F::Output{CURRENT.scope(metrics,future).await}
pub(crate) struct Span{metrics:Option<Metrics>,stage:&'static str,started:std::time::Instant}
pub(crate) fn span(stage:&'static str,items:usize)->Span{
 let metrics=CURRENT.try_with(Arc::clone).ok();
 if let Some(metrics)=&metrics{if let Ok(mut all)=metrics.lock(){let entry=all.entry(stage).or_default();entry.calls+=1;entry.items+=items as u64;}}
 Span{metrics,stage,started:std::time::Instant::now()}
}
impl Drop for Span{fn drop(&mut self){if let Some(metrics)=&self.metrics{if let Ok(mut all)=metrics.lock(){all.entry(self.stage).or_default().inclusive_seconds+=self.started.elapsed().as_secs_f64();}}}}
pub(crate) fn snapshot(metrics:&Metrics)->serde_json::Value{serde_json::to_value(&*metrics.lock().expect("fixture metrics")).expect("fixture metrics JSON")}

// Numeric-only diagnostic IO, enabled solely by existing trusted owned fixture paths.
// Neither timer alters a future, SQL, grants, task-local policy or production code.
fn write_numeric_line(path:&str,line:&serde_json::Value){
 use std::io::Write;
 if path.is_empty()||path.len()>4096{return}
 let Ok(mut bytes)=serde_json::to_vec(line)else{return};if bytes.len()>16*1024{return}bytes.push(b'\n');
 if let Ok(mut file)=std::fs::OpenOptions::new().create(true).append(true).open(path){let _=file.write_all(&bytes);}
}
pub(crate) struct DifferentialTimer{stage:&'static str,items:usize,started:std::time::Instant,elapsed:Option<f64>,success:Option<bool>,path:Option<String>}
impl DifferentialTimer{
 pub(crate) fn new(stage:&'static str,items:usize)->Self{Self{stage,items,started:std::time::Instant::now(),elapsed:None,success:None,path:std::env::var("VAAK_SEARCH_BATCH_DIFFERENTIAL_OUTPUT").ok().filter(|s|!s.is_empty()&&s.len()<4000).map(|s|format!("{s}.timings.jsonl"))}}
 pub(crate) fn finish<T,E>(mut self,result:&std::result::Result<T,E>){self.elapsed=Some(self.started.elapsed().as_secs_f64());self.success=Some(result.is_ok());}
}
impl Drop for DifferentialTimer{fn drop(&mut self){if let Some(path)=&self.path{write_numeric_line(path,&serde_json::json!({"stage":self.stage,"items":self.items,"elapsed_seconds":self.elapsed.unwrap_or_else(||self.started.elapsed().as_secs_f64()),"completed":self.success.is_some(),"success":self.success}));}}}

/// Owned by the handler OUTSIDE its task-local guarded future. Dropping a
/// cancelled future first updates Span metrics, then this reporter emits them.
pub(crate) struct RequestReport{metrics:Metrics,selected:Option<usize>,started:std::time::Instant,path:Option<String>,outcome:&'static str,status:Option<u16>}
impl RequestReport{
 pub(crate) fn new(metrics:Metrics,selected:Option<usize>,started:std::time::Instant)->Self{Self{metrics,selected,started,path:std::env::var("VAAK_SEARCH_CROSS_OUTPUT").ok().filter(|s|!s.is_empty()&&s.len()<4000).map(|s|format!("{s}.performance.jsonl")),outcome:"handler_dropped",status:None}}
 pub(crate) fn cancelled(&mut self){self.outcome="supervisor_cancelled";}
 pub(crate) fn complete(&mut self,status:u16){if self.outcome!="supervisor_cancelled"{self.outcome="completed";}self.status=Some(status);}
}
impl Drop for RequestReport{fn drop(&mut self){
 let Some(path)=&self.path else{return};let mut stages=serde_json::Map::new();let mut truncated=false;let mut unavailable=false;
 match self.metrics.try_lock(){Ok(all)=>{for(stage,value)in all.iter(){if stages.len()==64||stage.len()>96{truncated=true;continue}stages.insert((*stage).into(),serde_json::json!({"calls":value.calls,"items":value.items,"inclusive_seconds":value.inclusive_seconds}));}},Err(_)=>unavailable=true}
 write_numeric_line(path,&serde_json::json!({"selected_status_count":self.selected,"response_status":self.status,"elapsed_seconds":self.started.elapsed().as_secs_f64(),"outcome":self.outcome,"partial":self.outcome!="completed","inclusive_nested_stages_do_not_sum":true,"stages":stages,"stages_truncated":truncated,"stages_unavailable":unavailable}));
}}
