//! Current remote AP first-paint source provider. Remote LIMIT30 membership,
//! gateway replacement and classification are separate from local paging.
use super::*;
pub(crate) trait RemoteApSourceSession:Sync{
    fn requested_actor(&self)->&str;
    fn owner_id(&self)->i64;
    fn own_actor(&self)->&str;
    fn require_current<'a>(&'a self,db:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>;
}
pub(crate) struct SelectedRemoteApPage{
    requested:String,canonical:String,own_actor:String,owner:i64,tab:String,aliases:Vec<String>,
    collector:Vec<Value>,rows:Vec<(usize,Value)>,buckets:std::collections::BTreeMap<String,Vec<(usize,Value)>>,ledger:ProjectionLedger,
}
impl SelectedRemoteApPage{
    pub(crate) fn requested_actor(&self)->&str{&self.requested}
    pub(crate) fn canonical_actor(&self)->&str{&self.canonical}
    pub(crate) fn own_actor(&self)->&str{&self.own_actor}
    pub(crate) fn tab(&self)->&str{&self.tab}
    pub(crate) fn aliases(&self)->&[String]{&self.aliases}
    pub(crate) fn collector(&self)->&[Value]{&self.collector}
    pub(crate) fn rows(&self)->&[(usize,Value)]{&self.rows}
    pub(crate) fn bucket_count(&self,tab:&str)->usize{self.buckets.get(tab).map_or(0,Vec::len)}
    pub(crate) fn next_offset(&self)->usize{self.rows.len()}
    pub(crate) fn has_more(&self)->bool{false}
    pub(crate) fn require_source_row(&self,index:usize,row:&Value)->Result<()>{if self.rows.iter().find(|(ordinal,_)|*ordinal==index).map(|(_,row)|row)!=Some(row){bail!("PHP fallback: remote AP source differs from original collector ordinal")};Ok(())}
    pub(crate) async fn require_current(&self,db:&Client,session:&dyn RemoteApSourceSession)->Result<()>{
        if session.requested_actor()!=self.requested||session.own_actor()!=self.own_actor||session.owner_id()!=self.owner{bail!("PHP fallback: remote AP source session identity differs")}
        session.require_current(db).await?;self.ledger.require_current(db).await?;session.require_current(db).await
    }
    pub(crate) fn into_parts(self)->(String,String,String,Vec<(usize,Value)>,Vec<Value>,ProjectionLedger){(self.requested,self.canonical,self.tab,self.rows,self.collector,self.ledger)}
}
fn remote_parts(actor:&str)->Result<(String,String)>{
    // PHP parse_url preserves raw host case and path spelling; URL parsers
    // which lowercase hosts would change gateway path equality and LIKE keys.
    let expression=regex::Regex::new(r"^[A-Za-z][A-Za-z0-9+.-]*://(?:[^/?#]*@)?(\[[^\]]+\]|[^:/?#]+)(?::[^/?#]*)?([^?#]*)").unwrap();
    let captures=expression.captures(actor).context("PHP fallback: remote AP actor parse unsupported")?;
    Ok((captures[1].to_owned(),captures[2].trim_matches('/').to_owned()))
}
fn classify(collector:&[Value],own_actor:&str)->std::collections::BTreeMap<String,Vec<(usize,Value)>>{
    let mut buckets=["posts","replies","boosts","media","asks"].into_iter().map(|key|(key.to_owned(),vec![])).collect::<std::collections::BTreeMap<_,_>>();
    for(index,row)in collector.iter().enumerate(){
        let kind=row.get("type").filter(|value|!value.is_null()).map(|_|crate::library::card::scalar_string(row,"type")).unwrap_or_else(||"create".into()).to_ascii_lowercase();
        let tab=if kind=="announce"{"boosts"}else if truth(&row["in_reply_to"])||truth(&row["in_reply_to_id"]){
            let reply=if !row["in_reply_to"].is_null(){crate::library::card::scalar_string(row,"in_reply_to")}else{crate::library::card::scalar_string(row,"in_reply_to_id")};let reply=reply.trim_end_matches('/');
            let own=reply!=""&&(reply==own_actor||reply.starts_with(&format!("{own_actor}/")));
            if own&&reply.contains("/notes/"){"posts"}else{"replies"}
        }else{"posts"};buckets.get_mut(tab).unwrap().push((index,row.clone()));
        if truth(&row["media_urls"])&&row["media_urls"]!="[]"{buckets.get_mut("media").unwrap().push((index,row.clone()));}
    }buckets
}
pub(crate) async fn select_remote_ap(db:&Client,session:&dyn RemoteApSourceSession,tab:&str)->Result<SelectedRemoteApPage>{
    session.require_current(db).await?;let requested=session.requested_actor().to_owned();let own_actor=session.own_actor().to_owned();
    // The separate HTTP/session owner validates and resolves the request. This
    // selector cannot manufacture an owner from a request value or cached card.
    if requested.is_empty(){bail!("PHP fallback: remote AP collector target empty")}
    let mut dependencies=vec![];let aliases=effects::aliases(db,&mut dependencies,&requested).await?;
    let canonical=aliases.first().map(|actor|actor.trim_end_matches('/')).unwrap_or(&requested).to_owned();
    let roots=aliases.iter().map(|actor|actor.trim_end_matches('/')).filter(|actor|!actor.is_empty()).flat_map(|actor|[actor.to_owned(),format!("{actor}/")]).collect::<Vec<_>>();
    const ORDINARY:&str="SELECT to_jsonb(e) FROM events e WHERE type IN ('Create','Announce') AND actor_id IN(SELECT jsonb_array_elements_text($1::jsonb->'actors')) AND ((summary IS NOT NULL AND summary!='') OR (media_urls IS NOT NULL AND media_urls!='' AND media_urls!='[]') OR (spoiler_text IS NOT NULL AND spoiler_text!='') OR (sensitive IS NOT NULL AND sensitive!=0)) ORDER BY created_at DESC,id DESC LIMIT 30";
    let mut collector=if roots.is_empty(){vec![]}else{let key=json!({"actors":roots});let rows=Dependency::read(db,ORDINARY,&key).await?;dependencies.push(Dependency::new(ORDINARY,key,rows.clone()));rows};
    let(host,path)=remote_parts(&canonical)?;
    if path.is_empty()||path==host||path=="actor"{
        const GATEWAY:&str="SELECT to_jsonb(e) FROM events e WHERE type IN ('Create','Announce') AND actor_id LIKE $1::jsonb->>'prefix' AND (type='Announce' OR summary IS NOT NULL AND summary!='' OR media_urls IS NOT NULL AND media_urls!='' AND media_urls!='[]') ORDER BY id DESC LIMIT 30";
        let key=json!({"prefix":format!("https://{}/%",host.to_ascii_lowercase())});collector=Dependency::read(db,GATEWAY,&key).await?;dependencies.push(Dependency::new(GATEWAY,key,collector.clone()));
    }
    let buckets=classify(&collector,&own_actor);let normalized=crate::library::card::php_trim(tab).to_ascii_lowercase();let tab=if matches!(normalized.as_str(),"posts"|"replies"|"boosts"|"media"){normalized}else{"posts".into()};let rows=buckets[&tab].clone();
    let page=SelectedRemoteApPage{requested,canonical,own_actor,owner:session.owner_id(),tab,aliases,collector,rows,buckets,ledger:ProjectionLedger{dependencies,static_counts:vec![]}};page.require_current(db,session).await?;Ok(page)
}
/// Normalizer/caller dispositions do not confer audience permission.
/// A legacy fallback is retained explicitly; it cannot be consumed as omitted.
#[derive(Debug,PartialEq)]
pub(crate) enum RemoteApEventBranch{Shared,PrivateStub,AnnounceOmitted,EmptyShellOmitted,LegacyFallback}
pub(crate) struct MaterializedRemoteApSource{index:usize,original:Value,status:Option<Value>,branch:RemoteApEventBranch}
impl MaterializedRemoteApSource{
 pub(crate) fn source_index(&self)->usize{self.index}
 pub(crate) fn original(&self)->&Value{&self.original}
 pub(crate) fn status(&self)->Option<&Value>{self.status.as_ref()}
 pub(crate) fn branch(&self)->&RemoteApEventBranch{&self.branch}
}
pub(crate) struct MaterializedRemoteApPage{selected:SelectedRemoteApPage,sources:Vec<MaterializedRemoteApSource>,ledger:ProjectionLedger,effects:Vec<DeferredProjectionEffect>,cache:Vec<DeferredCacheProjection>,graphs:Vec<DeferredFollowGraph>}
impl MaterializedRemoteApPage{
 pub(crate) fn selected(&self)->&SelectedRemoteApPage{&self.selected}
 pub(crate) fn sources(&self)->&[MaterializedRemoteApSource]{&self.sources}
 pub(crate) fn effects(&self)->&[DeferredProjectionEffect]{&self.effects}
 pub(crate) async fn require_current(&self,db:&Client,session:&dyn RemoteApSourceSession)->Result<()>{self.selected.require_current(db,session).await?;self.ledger.require_current(db).await?;self.selected.require_current(db,session).await}
 // Presentation completion/effects remain a separate admitted remote context
 // phase; no local profile root mute exemption or grant is reused here.
}
fn private_stub(row:&Value)->bool{
 let visibility=crate::library::card::php_trim(s(row,"visibility")).to_ascii_lowercase();if !matches!(visibility.as_str(),"private"|"direct"){return false}
 let decoded=crate::home_hydrate_ranked::html_entity_decode(s(row,"summary"));let text=crate::library::card::php_trim(&decoded);if !text.is_empty()&&!matches!(text,"(no text preview)"|"(attachment)"){return false}
 let media:Value=serde_json::from_str(s(row,"media_urls")).unwrap_or(Value::Null);let has_media=match media{Value::Array(v)=>v.iter().any(|v|v.as_str().is_some_and(|u|u.starts_with("https://"))),Value::Object(v)=>v.values().any(|v|v.as_str().is_some_and(|u|u.starts_with("https://"))),_=>false};!has_media
}
async fn normalized_event(projector:&mut Projector<'_>,row:&Value)->Result<Option<Value>>{
 let status=projector.event_status(row,false,"").await?;if status.is_some(){return Ok(status)}
 if !matches!(s(row,"type").to_ascii_lowercase().as_str(),"create"|"update"){return Ok(None)}
 let actor=s(row,"actor_id").trim_end_matches('/');let object=s(row,"object_id").trim_end_matches('/');if actor.is_empty()||object.is_empty()||!object.starts_with("https://"){return Ok(None)}
 let raw=crate::library::card::php_trim(s(row,"summary"));let reason=if !raw.is_empty()&&!matches!(raw,"(media)"|"(attachment)"|"(poll)"|"(quote)"|"(boost)"){"half_parsed"}else{"empty_shell"};
 let mut text=if raw.contains('<'){crate::library::body::html_to_plain(raw)}else{raw.to_owned()};text=regex::Regex::new(r"\s+").unwrap().replace_all(&text," ").chars().take(2000).collect();if matches!(text.as_str(),"(attachment)"|"(media)"|"(poll)"|"(quote)"|"(boost)"){text.clear()}
 let account=projector.remote_account(actor).await?;let created=crate::library::card::php_trim(s(row,"created_at"));if created.is_empty(){bail!("PHP fallback: remote degraded event current clock required")}
 let mut status=json!({"id":snowflake(&format_time(created)?,i(row,"id"),1)?,"uri":object,"url":object,"content":if text.is_empty(){String::new()}else{format!("<p>{}</p>",crate::library::actions::esc(&text))},"created_at":created,"sensitive":truth(&row["sensitive"]),"spoiler_text":s(row,"spoiler_text"),"visibility":row.get("visibility").and_then(Value::as_str).unwrap_or("public"),"account":account,"media_attachments":[],"reblog":null,"quote":null,"vaak_degraded":true,"vaak_degraded_reason":reason});
 let reply=s(row,"in_reply_to").trim_end_matches('/');if reply.starts_with("https://"){status["vaak_in_reply_to_url"]=json!(reply)}Ok(Some(status))
}
pub(crate) async fn materialize_remote_ap(db:&Client,session:&dyn RemoteApSourceSession,selected:SelectedRemoteApPage)->Result<MaterializedRemoteApPage>{
 selected.require_current(db,session).await?;
 let mut projector=Projector{db,owner:session.owner_id(),dependencies:vec![],static_counts:vec![],effects:vec![],source_index:0,account_memo:Default::default(),bare_memo:Default::default(),cache:vec![],graphs:vec![],path:vec![]};let mut sources=vec![];
 for(index,row)in selected.rows(){projector.source_index=*index;selected.require_source_row(*index,row)?;
  let(status,branch)=if private_stub(row){(None,RemoteApEventBranch::PrivateStub)}else{
   let status=normalized_event(&mut projector,row).await?;
   let branch=match status.as_ref(){None if s(row,"type").eq_ignore_ascii_case("Announce")=>RemoteApEventBranch::AnnounceOmitted,None=>RemoteApEventBranch::LegacyFallback,Some(status) if truth(&status["vaak_degraded"])&&s(status,"vaak_degraded_reason")=="empty_shell"&&crate::library::card::php_trim(&crate::library::body::html_to_plain(s(status,"content"))).is_empty()&&status["media_attachments"].as_array().is_none_or(Vec::is_empty)=>RemoteApEventBranch::EmptyShellOmitted,Some(_)=>RemoteApEventBranch::Shared};(status,branch)
  };sources.push(MaterializedRemoteApSource{index:*index,original:row.clone(),status,branch});
 }
 let page=MaterializedRemoteApPage{selected,sources,ledger:ProjectionLedger{dependencies:projector.dependencies,static_counts:projector.static_counts},effects:projector.effects,cache:projector.cache,graphs:projector.graphs};page.require_current(db,session).await?;Ok(page)
}
#[cfg(test)]mod tests{
    use super::*;
    struct FixtureSession{requested:String,owner:i64,own:String,user:Option<Value>}
    impl FixtureSession{
        async fn current(db:&Client,owner:i64,requested:&str)->Self{let user=if owner>0{Some(db.query_one("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&owner]).await.unwrap().get::<_,Value>(0))}else{None};Self{requested:requested.into(),owner,own:user.as_ref().map(|user|s(user,"actor_id").trim_end_matches('/').to_owned()).unwrap_or_default(),user}}
    }
    impl RemoteApSourceSession for FixtureSession{
        fn owner_id(&self)->i64{self.owner}
        fn requested_actor(&self)->&str{&self.requested}fn own_actor(&self)->&str{&self.own}
        fn require_current<'a>(&'a self,db:&'a Client)->std::pin::Pin<Box<dyn std::future::Future<Output=Result<()>>+Send+'a>>{Box::pin(async move{if self.owner>0{let current=db.query_opt("SELECT to_jsonb(u) FROM ap_users u WHERE id=$1::bigint AND disabled_at IS NULL",&[&self.owner]).await?.map(|row|row.get::<_,Value>(0));if current!=self.user{bail!("remote source fixture owner withdrawn")}}Ok(())})}
    }
    #[tokio::test]
    #[ignore="actual PHP remote AP aliases/LIMIT30/gateway/classifier and exact current source ledger"]
    async fn actual_remote_ap_sources(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();let mut keys=std::collections::HashSet::new();let mut matched=0;let mut negatives=0;let mut output=vec![];
        for(path,kind)in [("/tmp/vaak-remote-profile-ap-source-reference.json","uppercase"),("/tmp/vaak-remote-profile-ap-source-lower-reference.json","lowercase")]{
            let reference:Value=serde_json::from_slice(&std::fs::read(path).unwrap()).unwrap();for width in ["INTEGER","BIGINT"]{
                let schema=format!("remote_ap_source_native_{kind}_{}_{}",width.to_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
                for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|column|format!("{} {}",column["column_name"].as_str().unwrap(),column["data_type"].as_str().unwrap())).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
                for capture in reference["captures"].as_array().unwrap().iter().filter(|capture|capture["width"]==width){assert!(keys.insert((kind,width.to_owned(),s(capture,"case").to_owned(),i(capture,"owner"))));
                    for(table,rows)in capture["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                    let session=FixtureSession::current(&db,i(capture,"owner"),s(capture,"requested_actor")).await;assert_eq!(session.own_actor(),s(capture,"viewer_own_actor"));
                    for tab in ["posts","replies","boosts","media"]{
                        let page=select_remote_ap(&db,&session,tab).await.unwrap();assert_eq!(page.requested_actor(),s(capture,"requested_actor"));assert_eq!(page.canonical_actor(),s(&capture["source"],"canonical_actor"),"{kind} {width} {}",capture["case"]);assert_eq!(json!(page.aliases()),capture["source"]["aliases"]);assert_eq!(json!(page.collector()),capture["source"]["collector"]);
                        let rows=page.rows().iter().map(|(_,row)|row.clone()).collect::<Vec<_>>();assert_eq!(json!(rows),capture["source"]["buckets"][tab],"{kind} {width} {} {tab}",capture["case"]);assert_eq!(page.next_offset(),i(&capture["firstpaint"]["next_offset_by_tab"],tab) as usize);assert!(!page.has_more());
                        for(ordinal,row)in page.rows(){assert_eq!(page.collector()[*ordinal],*row);page.require_source_row(*ordinal,row).unwrap();let mut altered=row.clone();altered["summary"]=json!("substituted row");assert!(page.require_source_row(*ordinal,&altered).is_err());assert!(page.require_source_row(ordinal+100,row).is_err());}
                        for bucket in ["posts","replies","boosts","media"]{assert_eq!(page.bucket_count(bucket),capture["source"]["buckets"][bucket].as_array().unwrap().len());}page.require_current(&db,&session).await.unwrap();matched+=1;
                        if tab=="posts"{
                            let wrong=FixtureSession{requested:format!("{}/different",session.requested_actor()),owner:session.owner,own:session.own.clone(),user:session.user.clone()};assert!(page.require_current(&db,&wrong).await.is_err());negatives+=1;
                            if let Some(row)=page.collector().last(){let id=i(row,"id");db.execute("UPDATE events SET summary=COALESCE(summary,'')||' source withdrawal' WHERE id=$1::bigint",&[&id]).await.unwrap();assert!(page.require_current(&db,&session).await.is_err());db.execute("UPDATE events SET summary=$1::jsonb->'summary'->>0 WHERE id=($1::jsonb->>'id')::bigint",&[&json!({"id":id,"summary":[row["summary"].clone()]})]).await.unwrap();page.require_current(&db,&session).await.unwrap();negatives+=1;}
                            if session.owner>0{db.execute("UPDATE ap_users SET disabled_at='withdrawn' WHERE id=$1::bigint",&[&session.owner]).await.unwrap();assert!(page.require_current(&db,&session).await.is_err());db.execute("UPDATE ap_users SET disabled_at=NULL WHERE id=$1::bigint",&[&session.owner]).await.unwrap();page.require_current(&db,&session).await.unwrap();negatives+=1;}
                        }
                    }
                    let invalid=select_remote_ap(&db,&session," PINNED ").await.unwrap();assert_eq!(invalid.tab(),"posts");assert_eq!(json!(invalid.rows().iter().map(|(_,row)|row).collect::<Vec<_>>()),capture["source"]["buckets"]["posts"]);
                    output.push(json!({"corpus":kind,"width":width,"case":capture["case"],"owner":capture["owner"],"canonical_actor":invalid.canonical_actor(),"collector":invalid.collector(),"buckets":invalid.buckets,"next_offset":invalid.next_offset(),"has_more":invalid.has_more()}));
                }db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
            }
        }assert_eq!(keys.len(),144);assert_eq!(matched,576);std::fs::write("/tmp/vaak-remote-profile-ap-source-native.json",serde_json::to_vec_pretty(&json!({"captures":output,"four_tab_selections":matched,"durable_mutation_checks":negatives,"scope":"actual source collector/classifier only; native current owner fixture and all collector rows/aliases bound; header/normalizer/auth route/effects separate"})).unwrap()).unwrap();
    }
    #[tokio::test]
    #[ignore="actual selected remote AP native event normalizer in disposable SQL"]
    async fn actual_remote_ap_event_projection(){
        let url=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(url.contains("/tmp/"));let db=crate::db::connect(&url).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read(std::env::var("REMOTE_AP_EVENT_REFERENCE").unwrap_or_else(|_|"/tmp/vaak-remote-profile-ap-event-reference.json".into())).unwrap()).unwrap();let mut outputs=vec![];let mut mismatches=0;let mut keys=std::collections::HashSet::new();let mut expected_count=0;let mut withdrawals=0;
        for width in ["INTEGER","BIGINT"]{
            let schema=format!("remote_ap_event_native_{}_{}",width.to_ascii_lowercase(),std::process::id());db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema}")).await.unwrap();
            for(table,columns)in reference["current_schema_by_width"][width].as_object().unwrap(){let defs=columns.as_array().unwrap().iter().map(|c|format!("{} {}",s(c,"column_name"),s(c,"data_type"))).collect::<Vec<_>>().join(",");db.batch_execute(&format!("CREATE TABLE {table}({defs})")).await.unwrap();}
            for capture in reference["captures"].as_array().unwrap().iter().filter(|r|r["width"]==width){
                for(table,rows)in capture["current_tables"].as_object().unwrap(){db.batch_execute(&format!("DELETE FROM {table}")).await.unwrap();for row in rows.as_array().unwrap(){db.execute(&format!("INSERT INTO {table} SELECT * FROM jsonb_populate_record(NULL::{table},$1)"),&[row]).await.unwrap();}}
                let session=FixtureSession::current(&db,i(capture,"owner"),s(capture,"requested_actor")).await;
                for tab in ["posts","replies","boosts","media"]{
                    expected_count+=capture["source"]["buckets"][tab].as_array().unwrap().len();
                    let selection=select_remote_ap(&db,&session,tab).await.unwrap();assert_eq!(json!(selection.rows().iter().map(|(_,row)|row).collect::<Vec<_>>()),capture["source"]["buckets"][tab]);let page=materialize_remote_ap(&db,&session,selection).await.unwrap();
                    for source in page.sources(){
                        assert!(keys.insert((width.to_owned(),s(capture,"case").to_owned(),i(capture,"owner"),tab.to_owned(),source.source_index())));
                        let event=capture["events"].as_array().unwrap().iter().find(|event|event["row"]==*source.original()).expect("selected native event must have original actual PHP projection");
                        let status=source.status().cloned().unwrap_or(Value::Null);let branch=match source.branch(){RemoteApEventBranch::Shared=>"shared",RemoteApEventBranch::PrivateStub=>"private_stub",RemoteApEventBranch::AnnounceOmitted=>"announce_omitted",RemoteApEventBranch::EmptyShellOmitted=>"empty_shell_omitted",RemoteApEventBranch::LegacyFallback=>"legacy_fallback"};
                        let equal=status==event["status"]&&branch==s(event,"branch");if !equal{mismatches+=1}outputs.push(json!({"width":width,"case":capture["case"],"owner":capture["owner"],"tab":tab,"source_index":source.source_index(),"native_status":status,"native_branch":branch,"equal":equal}));
                        page.selected.require_source_row(source.source_index(),source.original()).unwrap();let mut changed=source.original().clone();changed["summary"]=json!("different");assert!(page.selected.require_source_row(source.source_index(),&changed).is_err());
                    }
                    page.require_current(&db,&session).await.unwrap();
                    if let Some(row)=page.selected.collector().last(){let id=i(row,"id");db.execute("UPDATE events SET summary=COALESCE(summary,'')||' withdrawn' WHERE id=$1::bigint",&[&id]).await.unwrap();assert!(page.require_current(&db,&session).await.is_err());withdrawals+=1;let original=json!({"id":id,"summary":[row["summary"]]});db.execute("UPDATE events SET summary=$1::jsonb->'summary'->>0 WHERE id=($1::jsonb->>'id')::bigint",&[&original]).await.unwrap();}
                }

            }db.batch_execute(&format!("DROP SCHEMA {schema} CASCADE")).await.unwrap();
        }
        std::fs::write("/tmp/vaak-remote-profile-ap-event-native.json",serde_json::to_vec(&json!({"captures":outputs,"mismatches":mismatches,"expected_count":expected_count,"withdrawals":withdrawals})).unwrap()).unwrap();assert_eq!(keys.len(),expected_count);assert_eq!(mismatches,0);
    }

}
