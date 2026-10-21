//! Wafrn friends read page copied from the ap-admin friend_servers branch.
//! PHP retains authentication, the session CSRF value and both friend mutations.
//! Native only while VAAK_WAFRN_FRIEND_HOSTS is unset on both sides: PHP-FPM and
//! the worker read different env files, so only the catalog file is a shared source.
//! Spaces stand where the PHP template has whitespace, so the two match after
//! collapsing whitespace runs.
use super::*;
const RUNTIME_CATALOG:&str="/var/lib/mkultra/ap/wafrn-friend-hosts.txt";
#[derive(Debug,Clone,Deserialize,Serialize)]
#[serde(deny_unknown_fields)]
pub(super) struct Friend {host:String,note:Option<String>,updated_at:Option<String>}
pub(super) struct Collected {defaults:Vec<String>,friends:Vec<Friend>}
/// PHP trim()'s default set; a blank override counts as unset there too.
fn php_blank(raw:&std::ffi::OsStr)->bool{raw.as_encoded_bytes().iter().all(|b|matches!(b,b' '|b'\t'|b'\n'|b'\r'|0|0x0B))}
/// A missing file falls through like PHP's is_file; any other stat or read error is a fallback.
fn read_if_file(path:&std::path::Path)->Result<Option<String>>{
 match std::fs::metadata(path){
  Ok(m) if m.is_file()=>{if m.len()>1024*1024{bail!("Wafrn catalog bound");}Ok(Some(String::from_utf8(std::fs::read(path)?)?))},
  Ok(_)=>Ok(None),
  Err(e) if e.kind()==std::io::ErrorKind::NotFound=>Ok(None),
  Err(e)=>Err(e.into()),
 }
}
fn catalog()->Result<String>{
 if let Some(raw)=read_if_file(std::path::Path::new(RUNTIME_CATALOG))?{return Ok(raw);}
 let root=std::env::var_os("VAAK_API_ROOT").map(std::path::PathBuf::from).unwrap_or_else(||"/srv/mkultra/html/api".into());
 Ok(read_if_file(&root.join("deploy/wafrn-friend-hosts.txt"))?.unwrap_or_else(||"waffles.baeddel.social".into()))
}
/// The hostname PHP keeps for one catalog token, or None when PHP skips it.
/// Only plain hostnames (optionally https:// and one trailing slash) are proven;
/// anything parse_url or FILTER_VALIDATE_DOMAIN might read differently is a fallback.
fn host(token:&str)->Result<Option<String>>{
 let rest=token.strip_prefix("https://").unwrap_or(token);
 let host=rest.strip_suffix('/').unwrap_or(rest).to_ascii_lowercase();
 let valid=!host.is_empty()&&host.len()<=253&&host.split('.').all(|label|!label.is_empty()&&label.len()<=63&&!label.starts_with('-')&&!label.ends_with('-')&&label.bytes().all(|b|b.is_ascii_lowercase()||b.is_ascii_digit()||b==b'-'));
 if !valid{bail!("unproven Wafrn catalog token");}
 Ok((host!="mkultra.monster").then_some(host))
}
fn defaults(raw:&str)->Result<Vec<String>>{
 let mut hosts=std::collections::BTreeSet::new();
 // preg_split('/[,\s]+/') without /u splits on ASCII whitespace bytes and commas.
 for token in raw.split([',',' ','\t','\n','\r','\x0B','\x0C']).filter(|t|!t.is_empty()){
  if let Some(h)=host(token)?{hosts.insert(h);}
  if hosts.len()>2000{bail!("Wafrn catalog bound");}
 }
 // sort(SORT_STRING) is byte order, which BTreeSet iteration already is.
 Ok(hosts.into_iter().collect())
}
pub(super) async fn collect(db:&tokio_postgres::Client)->Result<Collected>{
 if std::env::var_os("VAAK_WAFRN_FRIEND_HOSTS").is_some_and(|v|!php_blank(&v)){bail!("Wafrn host override set in worker env");}
 let defaults=defaults(&catalog()?)?;
 // PHP swallows a missing table (it is created lazily) and lists no overrides.
 let exists:bool=db.query_one("SELECT to_regclass('ap_wafrn_friend_servers') IS NOT NULL",&[]).await?.get(0);
 let mut friends=Vec::new();
 if exists{
  for row in db.query("SELECT jsonb_build_object('host',host::text,'note',note::text,'updated_at',updated_at::text) FROM ap_wafrn_friend_servers WHERE enabled=1 ORDER BY host LIMIT 101",&[]).await?{friends.push(serde_json::from_value::<Friend>(row.get(0))?);}
  if friends.len()>100{bail!("friend rows beyond bridge bound");}
 }
 Ok(Collected{defaults,friends})
}
pub(super) fn paint(c:Collected,r:&Request)->Result<(String,usize,Value)>{
 let csrf=esc(&r.csrf);
 let mut h=format!(r#"<h2 style="font-size:1.05rem;margin:0 0 .5rem">Wafrn friend servers</h2> <p class="meta">Public posts are delivered to these Wafrn-compatible servers even without a local follower. Normal federation, blocks, and local-only privacy remain unchanged.</p> <details style="margin:.8rem 0 1rem"> <summary style="cursor:pointer;font-weight:600">Default Wafrn instances ({})</summary> "#,c.defaults.len());
 if c.defaults.is_empty(){h.push_str(r#"<div class="empty" style="margin-top:.55rem">No default Wafrn instances are currently available.</div> "#);}
 else{
  h.push_str(r#"<div class="meta" style="margin-top:.55rem">These instances come from the refreshed Wafrn directory/catalog and are included automatically. They are not admin overrides.</div> <ul style="margin:.5rem 0 0 1.2rem;padding:0;columns:2;column-gap:2rem"> "#);
  for host in &c.defaults{h.push_str(&format!(r#"<li style="overflow-wrap:anywhere;margin:.2rem 0">{}</li>"#,esc(host)));}
  h.push_str(" </ul> ");
 }
 h.push_str(&format!(r#"</details> <form class="composer" method="post" action="?view=friend_servers" style="margin:.8rem 0 1rem"> <input type="hidden" name="csrf" value="{csrf}"><input type="hidden" name="action" value="wafrn_friend_add"> <label>Hostname</label><input name="host" required placeholder="wafrn.example"> <label style="margin-top:.5rem">Note (optional)</label><input name="note" maxlength="240" placeholder="Why this server is trusted"> <button class="btn btn-primary" type="submit" style="margin-top:.6rem">Add / enable friend server</button> </form> "#));
 if c.friends.is_empty(){h.push_str(r#"<div class="empty">No admin overrides. The official Wafrn directory is still used.</div> "#);}
 for f in &c.friends{
  let host=esc(&f.host);
  h.push_str(&format!(r#"<div class="tweet" style="display:flex;align-items:center;gap:.75rem;justify-content:space-between"><div><b>{host}</b><div class="meta">{}</div></div><form method="post" action="?view=friend_servers"><input type="hidden" name="csrf" value="{csrf}"><input type="hidden" name="action" value="wafrn_friend_remove"><input type="hidden" name="host" value="{host}"><button class="btn btn-ghost" type="submit">Remove</button></form></div>"#,esc(f.note.as_deref().unwrap_or(""))));
 }
 // Rows carry the overrides only; the default hosts are in the HTML.
 Ok((h,c.friends.len(),serde_json::to_value(c.friends)?))
}
#[cfg(test)]mod tests{
 use super::*;
 #[test]fn catalog_tokens_match_php_or_fall_back(){
  assert_eq!(defaults("b.example\nA.Example\r\nhttps://c.example/,mkultra.monster\t a.example\n").unwrap(),vec!["a.example","b.example","c.example"]);
  for bad in["http://x.example","x.example:443","x..example","-x.example","x.example.","https://","x.example/path","é.example","x_y.example"]{assert!(defaults(bad).is_err(),"{bad}");}
  assert!(defaults("").unwrap().is_empty());
 }
 #[test]fn blank_override_is_unset(){assert!(php_blank(std::ffi::OsStr::new(" \t\n")));assert!(!php_blank(std::ffi::OsStr::new(" x ")));}
}
