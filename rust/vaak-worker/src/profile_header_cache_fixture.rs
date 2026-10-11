//! Declared offline Redis transport for actual PHP helper comparisons.
use anyhow::Result;
use serde_json::{json,Value};
use std::{collections::HashMap,sync::{Arc,Mutex}};
use tokio::io::{AsyncBufReadExt,AsyncReadExt,AsyncWriteExt,BufReader};
pub(super) struct RedisFixture{pub(super) url:String,pub(super) effects:Arc<Mutex<Vec<Value>>>,task:tokio::task::JoinHandle<()>}
impl RedisFixture{pub(super) async fn start(input:&Value)->Result<Self>{
 let listener=tokio::net::TcpListener::bind("127.0.0.1:0").await?;let url=format!("redis://127.0.0.1:{}/0",listener.local_addr()?.port());
 let values=Arc::new(Mutex::new(input.as_object().into_iter().flatten().map(|(k,v)|(k.clone(),v.to_string())).collect::<HashMap<_,_>>()));let effects=Arc::new(Mutex::new(Vec::new()));let recorder=effects.clone();
 let task=tokio::spawn(async move{loop{let Ok((stream,_))=listener.accept().await else{break};let values=values.clone();let effects=recorder.clone();tokio::spawn(async move{
  let (read,mut write)=stream.into_split();let mut reader=BufReader::new(read);loop{let mut head=String::new();if reader.read_line(&mut head).await.unwrap_or(0)==0{break}let Ok(count)=head.trim().trim_start_matches('*').parse::<usize>()else{break};let mut args=Vec::new();
   for _ in 0..count{head.clear();if reader.read_line(&mut head).await.is_err(){return}let Ok(len)=head.trim().trim_start_matches('$').parse::<usize>()else{return};let mut bytes=vec![0;len+2];if reader.read_exact(&mut bytes).await.is_err(){return}args.push(String::from_utf8_lossy(&bytes[..len]).into_owned());}
   let answer=match args.first().map(|s|s.as_str()){
    Some("GET") if args.len()==2=>{effects.lock().unwrap().push(json!({"get":args[1]}));let value=values.lock().unwrap().get(&args[1]).cloned();if let Some(v)=value{format!("${}\r\n{v}\r\n",v.len())}else{"$-1\r\n".into()}},
    Some("SET") if args.len()==5&&args[3]=="EX"=>{effects.lock().unwrap().push(json!({"set":args[1],"ttl":args[4].parse::<u64>().ok(),"value":serde_json::from_str::<Value>(&args[2]).ok()}));values.lock().unwrap().insert(args[1].clone(),args[2].clone());"+OK\r\n".into()},
    _=>"+OK\r\n".into(),
   };if write.write_all(answer.as_bytes()).await.is_err(){break}
  }
 });}});Ok(Self{url,effects,task})
}}
impl Drop for RedisFixture{fn drop(&mut self){self.task.abort();}}
pub(super) struct CountFileFixture{path:std::path::PathBuf}
impl CountFileFixture{pub(super) fn start(input:&Value)->Result<Self>{use sha2::{Digest,Sha256};let handle=input["handle"].as_str().unwrap_or("");anyhow::ensure!(handle=="vaak-header-file-fixture.test","only dedicated count file fixture handle is allowed");let dir=std::env::temp_dir().join("vaak-bsky-counts");std::fs::create_dir_all(&dir)?;let path=dir.join(format!("{}.json",hex::encode(Sha256::digest(handle.as_bytes()))));anyhow::ensure!(!path.exists(),"count file fixture is already in use");std::fs::write(&path,serde_json::to_vec(&input["payload"])?)?;if input["stale"]==true{let file=std::fs::File::open(&path)?;file.set_times(std::fs::FileTimes::new().set_modified(std::time::SystemTime::now()-std::time::Duration::from_secs(3600)))?;}Ok(Self{path})}}
impl Drop for CountFileFixture{fn drop(&mut self){let _=std::fs::remove_file(&self.path);}}
