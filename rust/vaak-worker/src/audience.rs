//! Recheck durable audiences before serving cached or newly hydrated cards.
use anyhow::Result;
use serde_json::Value;
use std::collections::{HashMap, HashSet};
use tokio_postgres::Client;

// Request-owned SQL plans only. Every lookup still executes against current
// rows, and fresh backend/schema identity prevents cross-fixture plan reuse.
tokio::task_local! { static PROFILE_PLANS: std::cell::RefCell<HashMap<(usize,i32,Vec<String>),tokio_postgres::Statement>>; }
pub(crate) async fn profile_reader_scope<F:std::future::Future>(future:F)->F::Output{
 PROFILE_PLANS.scope(std::cell::RefCell::new(HashMap::new()),future).await
}
async fn profile_plan(db:&Client,sql:&str)->Result<Option<tokio_postgres::Statement>>{
 if PROFILE_PLANS.try_with(|_|()).is_err(){return Ok(None)}
 let identity=db.query_one("SELECT pg_backend_pid(),current_schemas(true)",&[]).await?;
 let key=(db as *const Client as usize,identity.get::<_,i32>(0),identity.get::<_,Vec<String>>(1));
 if let Some(plan)=PROFILE_PLANS.with(|plans|plans.borrow().get(&key).cloned()){return Ok(Some(plan))}
 let plan=db.prepare(sql).await?;
 PROFILE_PLANS.with(|plans|{let mut plans=plans.borrow_mut();if plans.len()<8{plans.insert(key,plan.clone());}});Ok(Some(plan))
}

#[derive(Clone, Copy, PartialEq, Eq)]
pub enum Surface {
    Home, Profile, Local, Federated,
    /// Saved posts are direct references, not public discovery. Authorized
    /// private/direct/unlisted posts remain readable; stale cache metadata
    /// never overrides current durable recipients or accepted followers.
    Library,
    /// Search reuses direct-reference durable audiences only. Current indexing
    /// consent, source freshness and Search actor policy are separate gates.
    Search,
    /// Explicit profile/detail references: durable grants without discovery suppression.
    Direct,
}

impl Surface {
    pub fn timeline(view: &str) -> Self {
        match view { "local" => Self::Local, "feed" => Self::Federated, _ => Self::Home }
    }
}

#[derive(Default)]
struct Audience {
    denied: bool,
    visibility: String,
    author: String,
    recipients: HashSet<String>,
}

fn key(uri: &str) -> String { uri.trim().split('#').next().unwrap_or("").trim_end_matches('/').to_string() }

/// Offline decision used by the parity harness. Records are the durable map
/// `audience_records` would have built; this does not open a database.
pub(crate) fn replay_surface(name: &str) -> Option<Surface> {
    Some(match name {
        "home" => Surface::Home,
        "profile" => Surface::Profile,
        "local" => Surface::Local,
        "federated" => Surface::Federated,
        "library" => Surface::Library,
        "search" => Surface::Search,
        "direct" => Surface::Direct,
        _ => return None,
    })
}

pub(crate) struct ReplayRecord {
    pub denied: bool,
    pub visibility: String,
    pub author: String,
    pub recipients: Vec<String>,
}

pub(crate) fn replay_decision(
    status: &Value,
    records: &HashMap<String, ReplayRecord>,
    viewer: &str,
    following: &[String],
    surface: Surface,
) -> bool {
    let records = records.iter().map(|(id, record)| {
        (key(id), Audience {
            denied: record.denied,
            visibility: record.visibility.clone(),
            author: key(&record.author),
            recipients: record.recipients.iter().map(|recipient| key(recipient)).collect(),
        })
    }).collect();
    let following = following.iter().map(|actor| key(actor)).collect();
    tree_allowed(status, &records, &key(viewer), &following, surface, 0)
}

fn allowed(a: &Audience, viewer: &str, following: &HashSet<String>, surface: Surface, nested: bool) -> bool {
    if a.denied { return false; }
    let vis = a.visibility.trim().to_ascii_lowercase();
    // Public timeline discovery excludes unlisted posts. A quote is a direct
    // reference, so its public/unlisted target remains readable.
    if !nested && matches!(surface, Surface::Local | Surface::Federated | Surface::Profile) {
        return vis == "public";
    }
    if matches!(vis.as_str(), "public" | "unlisted") { return true; }
    if viewer.is_empty() { return false; }
    if a.author == viewer { return true; }
    if vis == "local" { return a.author.starts_with("https://mkultra.monster/users/"); }
    if a.recipients.contains(viewer) { return true; }
    matches!(vis.as_str(), "private" | "followers" | "followers_only") && following.contains(&a.author)
}

fn children(st: &Value) -> Vec<&Value> {
    let mut out = Vec::new();
    if let Some(v) = st.get("reblog").filter(|v| v.is_object()) { out.push(v); }
    for field in ["quote", "vaak_quote_preview"] {
        if let Some(v) = st.get(field).filter(|v| v.is_object()) {
            out.push(v.get("quoted_status").or_else(|| v.get("status")).filter(|v| v.is_object()).unwrap_or(v));
        }
    }
    out
}

fn requested_quote_aliases(st: &Value) -> Vec<String> {
    st.get("vaak_quote_requested_uri").and_then(Value::as_str)
        .filter(|uri| uri.starts_with("https://"))
        .map(crate::home_hydrate_ranked::quote_object_candidates).unwrap_or_default()
}

fn collect_requested_quote_aliases(st: &Value, aliases: &mut HashSet<String>, depth: usize) {
    if depth > 16 { return; }
    aliases.extend(requested_quote_aliases(st));
    for child in children(st) { collect_requested_quote_aliases(child, aliases, depth + 1); }
}

fn collect(st: &Value, ids: &mut HashSet<String>, depth: usize) {
    if depth > 16 { return; }
    if let Some(uri) = st.get("uri").or_else(|| st.get("url")).and_then(Value::as_str) {
        let id = key(uri);
        if id.starts_with("https://") || id.starts_with("http://") { ids.insert(id); }
    }
    for alias in requested_quote_aliases(st) { ids.insert(alias); }
    for child in children(st) { collect(child, ids, depth + 1); }
}

fn tree_allowed(st: &Value, records: &HashMap<String, Audience>, viewer: &str, following: &HashSet<String>, surface: Surface, depth: usize) -> bool {
    if depth > 16 { return false; }
    // Requested aliases only add constraints. They never provide a fallback
    // audience or grant to a different canonical object.
    for alias in requested_quote_aliases(st) {
        if records.get(&alias).is_some_and(|record| !allowed(record, viewer, following, surface, depth > 0)) { return false; }
    }
    let id = key(st.get("uri").or_else(|| st.get("url")).and_then(Value::as_str).unwrap_or(""));
    if let Some(a) = records.get(&id) {
        if !allowed(a, viewer, following, surface, depth > 0) { return false; }
    } else if id.starts_with("https://mkultra.monster/users/") && id.contains("/notes/") {
        // Deleted local notes and missing durable audiences cannot be rescued
        // by an old Redis card that still claims to be public.
        return false;
    } else {
        let a = Audience {
            visibility: st.get("visibility").and_then(Value::as_str).unwrap_or("public").into(),
            author: st.get("account").and_then(|a| a.get("uri")).and_then(Value::as_str).map(key).unwrap_or_default(),
            ..Default::default()
        };
        if !allowed(&a, viewer, following, surface, depth > 0) { return false; }
    }
    children(st).into_iter().all(|child| tree_allowed(child, records, viewer, following, surface, depth + 1))
}

async fn audience_records(db: &Client, owner: i64, statuses: &[Value]) -> Result<(HashMap<String, Audience>, String, HashSet<String>)> {
    let mut ids = HashSet::new();
    for st in statuses.iter() { collect(st, &mut ids, 0); }
    let ids: Vec<String> = ids.into_iter().collect();
    let slashed: Vec<String> = ids.iter().map(|id| format!("{id}/")).collect();
    let variants: Vec<String> = ids.iter().flat_map(|id| [id.clone(), format!("{id}/")]).collect();
    let mut quote_aliases = HashSet::new();
    for status in statuses { collect_requested_quote_aliases(status, &mut quote_aliases, 0); }
    let quote_aliases: Vec<String> = quote_aliases.into_iter().collect();
    // The rtrim equality is the idx_events_object_key_id seek. The original
    // equality list stays as a filter so a multi-slash row, which trims to the
    // same key, is still excluded. Quote-source probes seek the same index.
    let events = async {
        db.query(
            "SELECT DISTINCT ON (rtrim(object_id, '/')) rtrim(object_id, '/'),
                    COALESCE(visibility, 'public'), rtrim(COALESCE(actor_id, ''), '/')
             FROM events e WHERE (e.type IN ('Create','Update') OR (e.type IN ('Quote','QuotePost') AND NOT EXISTS (
                  SELECT 1 FROM events note_source
                  WHERE rtrim(note_source.object_id, '/') = rtrim(e.object_id, '/')
                    AND note_source.object_id IN (rtrim(e.object_id, '/'), rtrim(e.object_id, '/') || '/')
                    AND note_source.type IN ('Create','Update'))))
               AND action_taken = ANY(ARRAY['log','local_observe','local_fav_update'])
               AND rtrim(object_id, '/') = ANY($1)
               AND (object_id = ANY($1) OR object_id = ANY($2))
             ORDER BY rtrim(object_id, '/'), id DESC",
            &[&ids, &slashed],
        ).await
    };
    let outbox = async {
        db.query(
            "SELECT id, COALESCE(visibility, 'public'), COALESCE(to_json, '[]'), COALESCE(cc_json, '[]')
             FROM outbox_notes WHERE id = ANY($1)", &[&variants],
        ).await
    };
    let aliases = async {
        if quote_aliases.is_empty() {
            Ok(Vec::new())
        } else {
            db.query(
                "SELECT DISTINCT rtrim(e.object_id,'/') FROM events e WHERE rtrim(e.object_id,'/') = ANY($1)
                 AND (e.type IN ('Delete','Tombstone') OR (e.type IN ('Create','Update') AND e.action_taken = 'deleted')
                   OR (e.type = 'Announce' AND COALESCE(e.action_taken,'') <> 'deleted' AND COALESCE(e.visibility,'public') <> 'public'
                     AND NOT EXISTS (SELECT 1 FROM events original WHERE rtrim(original.object_id,'/') = rtrim(e.object_id,'/') AND original.type IN ('Create','Update'))))",
                &[&quote_aliases],
            ).await
        }
    };
    let viewer_row = async {
        if owner > 0 {
            db.query_opt("SELECT actor_id FROM ap_users WHERE id = $1::bigint AND disabled_at IS NULL", &[&owner]).await
        } else {
            Ok(None)
        }
    };
    let (event_rows, outbox_rows, alias_rows, viewer_row) = tokio::try_join!(events, outbox, aliases, viewer_row)?;
    let mut records = HashMap::new();
    for row in event_rows {
        records.insert(row.get::<_, String>(0), Audience { visibility: row.get(1), author: row.get(2), ..Default::default() });
    }
    // Outbox overwrites an events row for the same trimmed id. Row order inside
    // one query is unchanged, so two slash forms of one note still last-write-win.
    for row in outbox_rows {
        let id = key(&row.get::<_, String>(0));
        let mut recipients = HashSet::new();
        for col in [2, 3] {
            if let Ok(Value::Array(values)) = serde_json::from_str::<Value>(&row.get::<_, String>(col)) {
                for v in values { if let Some(s) = v.as_str() { recipients.insert(key(s)); } }
            }
        }
        let author = id.split("/notes/").next().unwrap_or("").to_string();
        records.insert(id, Audience { visibility: row.get(1), author, recipients, denied:false });
    }
    for row in alias_rows {
        records.insert(row.get(0), Audience { denied:true, ..Default::default() });
    }
    let mut viewer = String::new();
    let mut following = HashSet::new();
    if let Some(row) = viewer_row {
        viewer = key(&row.get::<_, String>(0));
    }
    if owner > 0 && !viewer.is_empty() {
        let mentions = async {
            db.query(
                "SELECT object_id FROM mentions WHERE owner_user_id = $1::bigint AND deleted_at IS NULL AND activity_type IN ('Create','Update','Quote','QuotePost') AND object_id = ANY($2)",
                &[&owner, &variants],
            ).await
        };
        // Accepted local followers are authoritative. Following a private
        // local account while its request is pending grants no audience.
        let follows = async {
            db.query(
                "SELECT rtrim(owner_actor_id, '/') FROM followers WHERE rtrim(actor_id, '/') = $1
                 UNION SELECT rtrim(actor_id, '/') FROM following WHERE rtrim(owner_actor_id, '/') = $1
                   AND actor_id NOT LIKE 'https://mkultra.monster/users/%'", &[&viewer],
            ).await
        };
        let (mention_rows, follow_rows) = tokio::try_join!(mentions, follows)?;
        for row in mention_rows {
            let id = key(&row.get::<_, String>(0));
            if let Some(a) = records.get_mut(&id) { a.recipients.insert(viewer.clone()); }
        }
        for row in follow_rows { following.insert(row.get::<_, String>(0)); }
    }
    Ok((records, viewer, following))
}

/// Fresh full-page Search audience reads. This shares the original predicates
/// and positional decoder; no permission results or prepared plans are cached.
async fn search_audience_records(db:&Client,owner:i64,statuses:&[Value])->Result<(HashMap<String,Audience>,String,HashSet<String>)>{
    // Guest/ownerless callers retain the original conditional-table contract.
    if owner<=0{return audience_records(db,owner,statuses).await}
    // Preserve the original conditional relation contract for inactive/empty
    // viewers. This row is not permission: the batched statement rereads the
    // viewer and its recipients/follows freshly in the same SQL snapshot.
    let owner_row=db.query_opt("SELECT actor_id FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL",&[&owner]).await?;
    if owner_row.as_ref().is_none_or(|row|key(&row.get::<_,String>(0)).is_empty()){return audience_records(db,owner,statuses).await}
    let mut ids=HashSet::new();let mut aliases=HashSet::new();
    for status in statuses{collect(status,&mut ids,0);collect_requested_quote_aliases(status,&mut aliases,0);}
    let ids=ids.into_iter().collect::<Vec<_>>();
    let variants=ids.iter().flat_map(|id|[id.clone(),format!("{id}/")]).collect::<Vec<_>>();
    let aliases=aliases.into_iter().collect::<Vec<_>>();
    const SEARCH_SQL:&str="WITH viewer AS (SELECT rtrim(split_part(btrim(actor_id,$4),'#',1),'/') AS actor FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL AND $1::bigint>0), event_rows AS (SELECT DISTINCT ON (rtrim(object_id,'/')) rtrim(object_id,'/') AS object_id,COALESCE(visibility,'public') AS visibility,rtrim(COALESCE(actor_id,''),'/') AS actor_id FROM events e WHERE (e.type IN ('Create','Update') OR (e.type IN ('Quote','QuotePost') AND NOT EXISTS(SELECT 1 FROM events note_source WHERE note_source.object_id IN (rtrim(e.object_id,'/'),rtrim(e.object_id,'/')||'/') AND note_source.type IN ('Create','Update')))) AND action_taken=ANY(ARRAY['log','local_observe','local_fav_update']) AND object_id=ANY($2) ORDER BY rtrim(object_id,'/'),id DESC), outbox_rows AS (SELECT id,COALESCE(visibility,'public') AS visibility,COALESCE(to_json,'[]') AS to_json,COALESCE(cc_json,'[]') AS cc_json FROM outbox_notes WHERE id=ANY($2)), alias_rows AS (SELECT DISTINCT rtrim(e.object_id,'/') AS object_id FROM events e WHERE rtrim(e.object_id,'/')=ANY($3) AND (e.type IN ('Delete','Tombstone') OR (e.type IN ('Create','Update') AND e.action_taken='deleted') OR (e.type='Announce' AND COALESCE(e.action_taken,'')<>'deleted' AND COALESCE(e.visibility,'public')<>'public' AND NOT EXISTS(SELECT 1 FROM events original WHERE rtrim(original.object_id,'/')=rtrim(e.object_id,'/') AND original.type IN ('Create','Update'))))), mention_rows AS (SELECT object_id FROM mentions WHERE owner_user_id=$1::bigint AND deleted_at IS NULL AND activity_type IN ('Create','Update','Quote','QuotePost') AND object_id=ANY($2) AND EXISTS(SELECT 1 FROM viewer WHERE actor<>'')), followed AS (SELECT rtrim(owner_actor_id,'/') AS actor FROM followers WHERE rtrim(actor_id,'/')=(SELECT actor FROM viewer LIMIT 1) AND EXISTS(SELECT 1 FROM viewer WHERE actor<>'') UNION SELECT rtrim(actor_id,'/') FROM following WHERE rtrim(owner_actor_id,'/')=(SELECT actor FROM viewer LIMIT 1) AND actor_id NOT LIKE 'https://mkultra.monster/users/%' AND EXISTS(SELECT 1 FROM viewer WHERE actor<>'')) SELECT (SELECT COALESCE(jsonb_agg(jsonb_build_array(object_id,visibility,actor_id)),'[]'::jsonb) FROM event_rows),(SELECT COALESCE(jsonb_agg(jsonb_build_array(id,visibility,to_json,cc_json)),'[]'::jsonb) FROM outbox_rows),(SELECT COALESCE(jsonb_agg(object_id),'[]'::jsonb) FROM alias_rows),(SELECT actor FROM viewer LIMIT 1),(SELECT COALESCE(jsonb_agg(object_id),'[]'::jsonb) FROM mention_rows),(SELECT COALESCE(jsonb_agg(actor),'[]'::jsonb) FROM followed)";
    let whitespace=" \t\n\r\u{b}\u{c}\u{85}\u{a0}\u{1680}\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}\u{2006}\u{2007}\u{2008}\u{2009}\u{200a}\u{2028}\u{2029}\u{202f}\u{205f}\u{3000}";
    // Execute anew on the current Client/schema for every original boundary.
    // No profile_plan invocation: relation replacement/errors remain observable.
    #[cfg(test)] let _timing=crate::search_context::selection::fixture_performance::span("search_audience_one_SQL",statuses.len());
    let row=db.query_one(SEARCH_SQL,&[&owner,&variants,&aliases,&whitespace]).await?;
    let mut records=HashMap::new();
    let events:Value=row.try_get(0)?;
    for event in events.as_array().ok_or_else(||anyhow::anyhow!("invalid Search event audience rows"))?{
        records.insert(event[0].as_str().ok_or_else(||anyhow::anyhow!("invalid Search event URI"))?.into(),Audience{visibility:event[1].as_str().ok_or_else(||anyhow::anyhow!("invalid Search event visibility"))?.into(),author:event[2].as_str().ok_or_else(||anyhow::anyhow!("invalid Search event author"))?.into(),..Default::default()});
    }
    let outbox:Value=row.try_get(1)?;
    let mut local_aliases=HashMap::<String,(String,HashSet<String>)>::new();
    // Equal canonical/slash audiences preserve original semantics regardless
    // of unspecified whole-table scan order. Conflicting audiences explicitly
    // refuse native admission and preserve the genuine PHP fallback winner.
    for note in outbox.as_array().ok_or_else(||anyhow::anyhow!("invalid Search outbox audience rows"))?{
        let id=key(note[0].as_str().ok_or_else(||anyhow::anyhow!("invalid Search outbox URI"))?);let mut recipients=HashSet::new();
        for column in [2,3]{if let Ok(Value::Array(values))=serde_json::from_str::<Value>(note[column].as_str().ok_or_else(||anyhow::anyhow!("invalid Search recipient text"))?){for value in values{if let Some(recipient)=value.as_str(){recipients.insert(key(recipient));}}}}
        let visibility=note[1].as_str().ok_or_else(||anyhow::anyhow!("invalid Search local visibility"))?;
        let effective=(visibility.trim().to_ascii_lowercase(),recipients.clone());
        if let Some(previous)=local_aliases.insert(id.clone(),effective.clone()){anyhow::ensure!(previous==effective,"PHP fallback: conflicting canonical/slash Search audience rows");}
        let author=id.split("/notes/").next().unwrap_or("").to_owned();records.insert(id,Audience{visibility:visibility.into(),author,recipients,denied:false});
    }
    let denied:Value=row.try_get(2)?;
    for alias in denied.as_array().ok_or_else(||anyhow::anyhow!("invalid Search requested aliases"))?{records.insert(alias.as_str().ok_or_else(||anyhow::anyhow!("invalid Search denied URI"))?.into(),Audience{denied:true,..Default::default()});}
    let viewer=row.try_get::<_,Option<String>>(3)?.unwrap_or_default();
    let mentions:Value=row.try_get(4)?;
    for mention in mentions.as_array().ok_or_else(||anyhow::anyhow!("invalid Search delivered audiences"))?{if let Some(record)=records.get_mut(&key(mention.as_str().ok_or_else(||anyhow::anyhow!("invalid Search delivered URI"))?)){record.recipients.insert(viewer.clone());}}
    let followed:Value=row.try_get(5)?;
    let following=followed.as_array().ok_or_else(||anyhow::anyhow!("invalid Search following audiences"))?.iter().map(|actor|actor.as_str().map(str::to_owned).ok_or_else(||anyhow::anyhow!("invalid Search following URI"))).collect::<Result<HashSet<_>>>()?;
    Ok((records,viewer,following))
}

/// Positional decisions from the same bounded current-record engine. No source
/// JSON is stamped or matched back to another snapshot.
pub(crate) async fn admission_mask(db:&Client,owner:i64,surface:Surface,statuses:&[Value])->Result<Vec<bool>> {
    if statuses.is_empty(){return Ok(Vec::new())}
    let (records,viewer,following)=if matches!(surface,Surface::Search){search_audience_records(db,owner,statuses).await?}else{audience_records(db,owner,statuses).await?};
    Ok(positional_decisions(statuses,&records,&viewer,&following,surface))
}
fn positional_decisions(statuses:&[Value],records:&HashMap<String,Audience>,viewer:&str,following:&HashSet<String>,surface:Surface)->Vec<bool>{
    statuses.iter().map(|status|tree_allowed(status,records,viewer,following,surface,0)).collect()
}

pub async fn filter_statuses(db: &Client, owner: i64, surface: Surface, statuses: &mut Vec<Value>) -> Result<()> {
    if statuses.is_empty() { return Ok(()); }
    let (records,viewer,following)=if matches!(surface,Surface::Search){search_audience_records(db,owner,statuses).await?}else{audience_records(db,owner,statuses).await?};
    statuses.retain(|st| tree_allowed(st, &records, &viewer, &following, surface, 0));
    // Direct-reference painters preserve canonical source labels/actions.
    // Current records above still decide every grant, including nested targets.
    if !matches!(surface, Surface::Library | Surface::Search | Surface::Direct) {
        for st in statuses { stamp_visibility(st, &records, 0); }
    }
    Ok(())
}

/// Private profile stage reader. It uses the same current-record predicates as
/// audience_records, in one fresh SQL snapshot rather than five round trips.
/// It never retains an authority decision across an enrichment await.
pub(crate) async fn filter_profile_statuses(db:&Client,owner:i64,surface:Surface,statuses:&mut Vec<Value>)->Result<()>{
    anyhow::ensure!(matches!(surface,Surface::Direct),"profile audience reader requires explicit profile policy");
    if statuses.is_empty(){return Ok(())}
    let mut ids=HashSet::new();let mut aliases=HashSet::new();for status in statuses.iter(){collect(status,&mut ids,0);collect_requested_quote_aliases(status,&mut aliases,0);}
    let ids=ids.into_iter().collect::<Vec<_>>();let variants=ids.iter().flat_map(|id|[id.clone(),format!("{id}/")]).collect::<Vec<_>>();let aliases=aliases.into_iter().collect::<Vec<_>>();
    const SQL:&str="WITH viewer AS (SELECT rtrim(split_part(btrim(actor_id,$4),'#',1),'/') AS actor FROM ap_users WHERE id=$1::bigint AND disabled_at IS NULL AND $1::bigint>0), event_rows AS (SELECT DISTINCT ON (rtrim(object_id,'/')) rtrim(object_id,'/') AS object_id,COALESCE(visibility,'public') AS visibility,rtrim(COALESCE(actor_id,''),'/') AS actor_id FROM events e WHERE (e.type IN ('Create','Update') OR (e.type IN ('Quote','QuotePost') AND NOT EXISTS(SELECT 1 FROM events note_source WHERE note_source.object_id IN (rtrim(e.object_id,'/'),rtrim(e.object_id,'/')||'/') AND note_source.type IN ('Create','Update')))) AND action_taken=ANY(ARRAY['log','local_observe','local_fav_update']) AND object_id=ANY($2) ORDER BY rtrim(object_id,'/'),id DESC), outbox_rows AS (SELECT id,COALESCE(visibility,'public') AS visibility,COALESCE(to_json,'[]') AS to_json,COALESCE(cc_json,'[]') AS cc_json FROM outbox_notes WHERE id=ANY($2)), alias_rows AS (SELECT DISTINCT rtrim(e.object_id,'/') AS object_id FROM events e WHERE rtrim(e.object_id,'/')=ANY($3) AND (e.type IN ('Delete','Tombstone') OR (e.type IN ('Create','Update') AND e.action_taken='deleted') OR (e.type='Announce' AND COALESCE(e.action_taken,'')<>'deleted' AND COALESCE(e.visibility,'public')<>'public' AND NOT EXISTS(SELECT 1 FROM events original WHERE rtrim(original.object_id,'/')=rtrim(e.object_id,'/') AND original.type IN ('Create','Update'))))), mention_rows AS (SELECT object_id FROM mentions WHERE owner_user_id=$1::bigint AND deleted_at IS NULL AND activity_type IN ('Create','Update','Quote','QuotePost') AND object_id=ANY($2) AND EXISTS(SELECT 1 FROM viewer WHERE actor<>'')), followed AS (SELECT rtrim(owner_actor_id,'/') AS actor FROM followers WHERE rtrim(actor_id,'/')=(SELECT actor FROM viewer LIMIT 1) AND EXISTS(SELECT 1 FROM viewer WHERE actor<>'') UNION SELECT rtrim(actor_id,'/') FROM following WHERE rtrim(owner_actor_id,'/')=(SELECT actor FROM viewer LIMIT 1) AND actor_id NOT LIKE 'https://mkultra.monster/users/%' AND EXISTS(SELECT 1 FROM viewer WHERE actor<>'')) SELECT (SELECT COALESCE(jsonb_agg(jsonb_build_array(object_id,visibility,actor_id)),'[]'::jsonb) FROM event_rows),(SELECT COALESCE(jsonb_agg(jsonb_build_array(id,visibility,to_json,cc_json)),'[]'::jsonb) FROM outbox_rows),(SELECT COALESCE(jsonb_agg(object_id),'[]'::jsonb) FROM alias_rows),(SELECT actor FROM viewer LIMIT 1),(SELECT COALESCE(jsonb_agg(object_id),'[]'::jsonb) FROM mention_rows),(SELECT COALESCE(jsonb_agg(actor),'[]'::jsonb) FROM followed)";
    let plan=profile_plan(db,SQL).await?;
    let whitespace=" \t\n\r\u{b}\u{c}\u{85}\u{a0}\u{1680}\u{2000}\u{2001}\u{2002}\u{2003}\u{2004}\u{2005}\u{2006}\u{2007}\u{2008}\u{2009}\u{200a}\u{2028}\u{2029}\u{202f}\u{205f}\u{3000}";
    let parameters:&[&(dyn tokio_postgres::types::ToSql+Sync)]=&[&owner,&variants,&aliases,&whitespace];
    let row=if let Some(plan)=plan{db.query_one(&plan,parameters).await?}else{db.query_one(SQL,parameters).await?};
    let mut records=HashMap::new();let events:Value=row.get(0);for event in events.as_array().expect("SQL array"){records.insert(event[0].as_str().unwrap().into(),Audience{visibility:event[1].as_str().unwrap().into(),author:event[2].as_str().unwrap().into(),..Default::default()});}
    let outbox:Value=row.get(1);let mut local_aliases=HashMap::<String,(String,HashSet<String>)>::new();for note in outbox.as_array().expect("SQL array"){
        let id=key(note[0].as_str().unwrap());let mut recipients=HashSet::new();for column in [2,3]{if let Ok(Value::Array(values))=serde_json::from_str::<Value>(note[column].as_str().unwrap()){for value in values{if let Some(recipient)=value.as_str(){recipients.insert(key(recipient));}}}}
        let effective=(note[1].as_str().unwrap().trim().to_ascii_lowercase(),recipients.clone());if let Some(previous)=local_aliases.insert(id.clone(),effective.clone()){anyhow::ensure!(previous==effective,"PHP fallback: conflicting canonical/slash current audience rows");}
        let author=id.split("/notes/").next().unwrap_or("").into();records.insert(id,Audience{visibility:note[1].as_str().unwrap().into(),author,recipients,denied:false});
    }
    let denied:Value=row.get(2);for alias in denied.as_array().expect("SQL array"){records.insert(alias.as_str().unwrap().into(),Audience{denied:true,..Default::default()});}
    let viewer=key(&row.get::<_,Option<String>>(3).unwrap_or_default());let mentions:Value=row.get(4);for mention in mentions.as_array().expect("SQL array"){if let Some(record)=records.get_mut(&key(mention.as_str().unwrap())){record.recipients.insert(viewer.clone());}}
    let followed:Value=row.get(5);let following=followed.as_array().expect("SQL array").iter().map(|actor|actor.as_str().unwrap().to_owned()).collect::<HashSet<_>>();
    statuses.retain(|status|tree_allowed(status,&records,&viewer,&following,surface,0));Ok(())
}

fn stamp_visibility(st: &mut Value, records: &HashMap<String, Audience>, depth: usize) {
    if depth > 16 { return; }
    let id = key(st.get("uri").or_else(|| st.get("url")).and_then(Value::as_str).unwrap_or(""));
    if let Some(a) = records.get(&id) { st["visibility"] = Value::String(a.visibility.clone()); }
    if let Some(v) = st.get_mut("reblog").filter(|v| v.is_object()) { stamp_visibility(v, records, depth + 1); }
    for field in ["quote", "vaak_quote_preview"] {
        if let Some(v) = st.get_mut(field).filter(|v| v.is_object()) {
            if v.get("quoted_status").is_some() {
                if let Some(child) = v.get_mut("quoted_status") { stamp_visibility(child, records, depth + 1); }
            } else if v.get("status").is_some() {
                if let Some(child) = v.get_mut("status") { stamp_visibility(child, records, depth + 1); }
            } else { stamp_visibility(v, records, depth + 1); }
        }
    }
}

/// Off-page self-thread parents must pass the same rules as visible rows.
pub async fn filter_parents(db: &Client, owner: i64, surface: Surface, parents: &mut HashMap<String, Value>) -> Result<()> {
    if parents.is_empty() { return Ok(()); }
    let keys: Vec<String> = parents.keys().cloned().collect();
    let mut values: Vec<Value> = keys.iter().filter_map(|k| parents.remove(k)).collect();
    let mut hidden = crate::hidden::load_hidden_sets(db, owner).await?;
    let selected:Vec<String> = values.iter().filter_map(|status|status.get("uri").and_then(Value::as_str).map(str::to_owned)).collect();
    crate::hidden::load_ordinary_owned_notes(db, owner, &selected, &mut hidden).await?;
    filter_statuses(db, owner, surface, &mut values).await?;
    let moderation = crate::hidden::load_viewer_moderation(db, owner).await?;
    crate::notif_embed::stamp_viewer_moderation(&mut values, &moderation);
    let selected:Vec<String> = values.iter().filter_map(|status|status.get("uri").and_then(Value::as_str).map(str::to_owned)).collect();
    crate::hidden::load_ordinary_owned_notes(db, owner, &selected, &mut hidden).await?;
    for st in values {
        if !(if surface == Surface::Profile { crate::hidden::ordinary_status_hidden_on_profile(&st, &hidden) } else { crate::hidden::ordinary_status_hidden(&st, &hidden) }) {
            let id = key(st.get("uri").and_then(Value::as_str).unwrap_or(""));
            if let Some(k) = keys.iter().find(|k| key(k) == id) { parents.insert(k.clone(), st); }
        }
    }
    Ok(())
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::json;

    #[tokio::test]
    #[ignore="opt-in actual PHP audience and request-owned plan schema proof"]
    async fn actual_profile_audience_php_and_plan_schema(){
        let connection=std::env::var("LIBRARY_FIXTURE_DB").unwrap();assert!(connection.contains("/tmp/"));let db=crate::db::connect(&connection).await.unwrap();
        let reference:Value=serde_json::from_slice(&std::fs::read("/tmp/vaak-profile-audience-current-reference.json").unwrap()).unwrap();
        let first=format!("profile_audience_plan_{}_a",std::process::id());let second=format!("profile_audience_plan_{}_b",std::process::id());
        for schema in [&first,&second]{db.batch_execute(&format!("CREATE SCHEMA {schema};SET search_path={schema};CREATE TABLE ap_users(id BIGINT,actor_id TEXT,disabled_at TEXT);CREATE TABLE events(id BIGINT,object_id TEXT,visibility TEXT,actor_id TEXT,type TEXT,action_taken TEXT);CREATE TABLE outbox_notes(id TEXT,visibility TEXT,to_json TEXT,cc_json TEXT);CREATE TABLE mentions(owner_user_id BIGINT,object_id TEXT,deleted_at TEXT,activity_type TEXT);CREATE TABLE followers(owner_actor_id TEXT,actor_id TEXT);CREATE TABLE following(owner_actor_id TEXT,actor_id TEXT);INSERT INTO ap_users VALUES(1,'https://mkultra.monster/users/fixture',NULL),(2,'https://mkultra.monster/users/other',NULL)")).await.unwrap();}
        profile_reader_scope(async {
            db.batch_execute(&format!("SET search_path={first}")).await.unwrap();
            for capture in reference["captures"].as_array().unwrap(){
                db.batch_execute("TRUNCATE outbox_notes,mentions,followers,following,events").await.unwrap();
                for note in capture["outbox"].as_array().unwrap(){db.execute("INSERT INTO outbox_notes VALUES($1,$2,$3,$4)",&[&note["id"].as_str().unwrap(),&note["visibility"].as_str().unwrap(),&note["to_json"].as_str().unwrap(),&note["cc_json"].as_str().unwrap()]).await.unwrap();}
                let uri=capture["source"]["uri"].as_str().unwrap();let case=capture["case"].as_str().unwrap();
                if case=="private_follower"{db.batch_execute("INSERT INTO followers VALUES('https://mkultra.monster/users/fixture','https://mkultra.monster/users/other')").await.unwrap();}
                if case=="direct_mention"{db.execute("INSERT INTO mentions VALUES(2,$1,NULL,'Create')",&[&uri]).await.unwrap();}
                for owner in [0,1,2]{let mut statuses=vec![capture["source"].clone()];let result=filter_profile_statuses(&db,owner,Surface::Direct,&mut statuses).await;if case.starts_with("canonical_"){assert!(result.is_err(),"conflicting alias must fall back");}else{result.unwrap();assert_eq!(statuses.len()==1,capture["decisions"][owner as usize].as_bool().unwrap(),"actual PHP {case}/{owner}");}}
            }
            // Identical effective recipients remain native despite JSON order,
            // whitespace, duplicate recipient and to/cc placement differences.
            db.batch_execute("TRUNCATE outbox_notes;INSERT INTO outbox_notes VALUES('https://mkultra.monster/users/fixture/notes/audience','direct','[\"https://mkultra.monster/users/other/\",\"https://remote.test/recipient\"]','[]'),('https://mkultra.monster/users/fixture/notes/audience/',' DIRECT ','[]','[\"https://remote.test/recipient\",\"https://mkultra.monster/users/other\",\"https://remote.test/recipient\"]')").await.unwrap();
            let mut same=vec![reference["captures"][0]["source"].clone()];filter_profile_statuses(&db,2,Surface::Direct,&mut same).await.unwrap();assert_eq!(same.len(),1);
            // Same request plan cache, same client, different current schema:
            // the first schema allows and the second denies the same URI.
            let source=reference["captures"][0]["source"].clone();let uri=source["uri"].as_str().unwrap();
            db.batch_execute("TRUNCATE outbox_notes").await.unwrap();db.execute("INSERT INTO outbox_notes VALUES($1,'public','[]','[]')",&[&uri]).await.unwrap();
            let mut statuses=vec![source.clone()];filter_profile_statuses(&db,0,Surface::Direct,&mut statuses).await.unwrap();assert_eq!(statuses.len(),1);
            db.batch_execute(&format!("SET search_path={second}")).await.unwrap();db.execute("INSERT INTO outbox_notes VALUES($1,'private','[]','[]')",&[&uri]).await.unwrap();
            let mut statuses=vec![source];filter_profile_statuses(&db,0,Surface::Direct,&mut statuses).await.unwrap();assert!(statuses.is_empty());
        }).await;
        db.batch_execute(&format!("SET search_path=public;DROP SCHEMA {first},{second} CASCADE")).await.unwrap();
    }

    #[test]
    fn positional_admission_preserves_duplicates_and_independent_nested_grants() {
        let uri="https://remote.example/notes/public";
        let private="https://remote.example/notes/private";
        let records=HashMap::from([(uri.into(),Audience{visibility:"public".into(),..Default::default()}),(private.into(),Audience{visibility:"direct".into(),..Default::default()})]);
        let statuses=vec![json!({"id":"same","uri":uri,"content":"first"}),json!({"id":"same","uri":uri,"content":"second","quote":{"quoted_status":{"uri":private}}}),json!({"id":"same","uri":uri,"content":"third"}),json!({"id":"same","uri":uri,"content":"first"})];
        let original=statuses.clone();
        assert_eq!(positional_decisions(&statuses,&records,"",&HashSet::new(),Surface::Library),vec![true,false,true,true]);
        assert_eq!(statuses,original);
    }

    #[test]
    fn private_audiences_require_accepted_follow_or_explicit_recipient() {
        let a = Audience { visibility: "private".into(), author: "https://mkultra.monster/users/alice".into(), ..Default::default() };
        let viewer = "https://mkultra.monster/users/bob";
        assert!(!allowed(&a, "", &HashSet::new(), Surface::Home, false));
        assert!(!allowed(&a, viewer, &HashSet::new(), Surface::Home, false));
        let following = HashSet::from([a.author.clone()]);
        assert!(allowed(&a, viewer, &following, Surface::Home, false));
        assert!(!allowed(&a, viewer, &following, Surface::Local, false));
        let mut direct = Audience { visibility: "direct".into(), ..a };
        assert!(!allowed(&direct, viewer, &following, Surface::Home, false));
        direct.recipients.insert(viewer.into());
        assert!(allowed(&direct, viewer, &following, Surface::Home, false));
    }

    #[test]
    fn unlisted_is_directly_readable_but_not_public_discovery() {
        let a = Audience { visibility: "unlisted".into(), ..Default::default() };
        assert!(allowed(&a, "", &HashSet::new(), Surface::Home, false));
        assert!(allowed(&a, "", &HashSet::new(), Surface::Federated, true));
        assert!(!allowed(&a, "", &HashSet::new(), Surface::Federated, false));
        assert!(!allowed(&a, "", &HashSet::new(), Surface::Profile, false));
    }

    #[test]
    fn stale_public_card_cannot_override_durable_private_quote_or_deleted_note() {
        let uri = "https://mkultra.monster/users/alice/notes/secret";
        let st = json!({"uri":"at://friend/post/x", "quote":{"quoted_status":{"uri":uri,"visibility":"public"}}});
        let records = HashMap::from([(uri.into(), Audience {visibility:"private".into(), ..Default::default()})]);
        assert!(!tree_allowed(&st, &records, "", &HashSet::new(), Surface::Home, 0));
        assert!(!tree_allowed(&json!({"uri":uri,"visibility":"public"}), &HashMap::new(), "", &HashSet::new(), Surface::Home, 0));
    }
    #[test]
    fn library_preserves_authorized_saved_audiences_and_rejects_revocation() {
        let viewer = "https://mkultra.monster/users/viewer";
        let author = "https://mkultra.monster/users/author";
        let mut a = Audience { visibility: "private".into(), author: author.into(), ..Default::default() };
        assert!(!allowed(&a, viewer, &HashSet::new(), Surface::Library, false));
        assert!(allowed(&a, viewer, &HashSet::from([author.into()]), Surface::Library, false));
        a.visibility = "direct".into();
        assert!(!allowed(&a, viewer, &HashSet::from([author.into()]), Surface::Library, false));
        a.recipients.insert(viewer.into());
        assert!(allowed(&a, viewer, &HashSet::new(), Surface::Library, false));
        a.visibility = "unlisted".into();
        assert!(allowed(&a, viewer, &HashSet::new(), Surface::Library, false));
        assert!(!allowed(&a, viewer, &HashSet::new(), Surface::Profile, false));
    }
}

/// Recheck cached notification cards against current viewer rules.
pub async fn filter_notifications(db: &Client, owner: i64, items: &mut Vec<Value>) -> Result<()> {
    crate::home_hydrate_ranked::refresh_local_accounts(db, items).await?;
    let mut statuses = Vec::new();
    for (idx, item) in items.iter().enumerate() {
        if let Some(st) = item.get("status").filter(|s| s.is_object()) {
            let mut st = st.clone();
            st["_vaak_policy_index"] = Value::from(idx);
            statuses.push(st);
        }
    }
    // Hidden sets and audience grants are independent. Both finish before a
    // row is kept, and neither decision is reused after a later await.
    let hidden_fut = async {
        let mut hidden = crate::hidden::load_hidden_sets(db, owner).await?;
        crate::hidden::expand_bsky_identities(db, &mut hidden).await;
        Ok(hidden)
    };
    let admit_fut = async {
        filter_statuses(db, owner, Surface::Home, &mut statuses).await?;
        Ok::<_, anyhow::Error>(())
    };
    let (hidden, ()) = tokio::try_join!(hidden_fut, admit_fut)?;
    let missing_local = crate::notif::missing_local_notes_in_items(db, items).await;
    let mut allowed = HashMap::new();
    for mut st in statuses {
        let idx = st["_vaak_policy_index"].as_u64().unwrap() as usize;
        st.as_object_mut().unwrap().remove("_vaak_policy_index");
        allowed.insert(idx, st);
    }
    let mut idx = 0;
    items.retain_mut(|item| {
        let original_idx = idx;
        idx += 1;
        if crate::notif::item_targets_missing_local(item, &missing_local) { return false; }
        if crate::hidden::status_hidden(item, &hidden) { return false; }
        if item.get("status").is_some_and(Value::is_object) {
            let Some(st) = allowed.remove(&original_idx) else { return false; };
            if crate::hidden::status_hidden(&st, &hidden) { return false; }
            item["status"] = st;
        }
        true
    });
    Ok(())
}

#[cfg(test)]
#[path="search_batch_audience_fixture.rs"]
pub(crate) mod batch_differential;
