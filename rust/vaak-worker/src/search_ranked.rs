//! Actual PostgreSQL ranked candidates, before Search source serialization.
use anyhow::{Context, Result};
use tokio_postgres::Client;
use super::SearchRow;

pub(super) async fn network_hits(
    db: &Client, owner: i64, query: &str, tag: &str, limit: i64, bluesky: bool,
) -> Result<Vec<SearchRow>> {
    let source = if bluesky { "d.source = 'bsky_post'" } else { "d.source IN ('event', 'mention', 'status')" };
    let live = if bluesky {
        "EXISTS (SELECT 1 FROM bsky_posts b WHERE b.bsky_uri = d.object_id)"
    } else {
        "((d.source = 'event' AND EXISTS (SELECT 1 FROM events e WHERE e.id = d.source_pk))
        OR (d.source = 'mention' AND EXISTS (SELECT 1 FROM mentions m WHERE m.id = d.source_pk AND m.deleted_at IS NULL))
        OR (d.source = 'status' AND EXISTS (SELECT 1 FROM masto_statuses s WHERE s.local_id = d.source_pk)))"
    };
    let safe_tag = super::query::normalized_tag(tag);
    let tag_like = if safe_tag.is_empty() { None } else { Some(format!("%#{}%", safe_tag.replace('%', "\\%").replace('_', "\\_"))) };
    let tag_sql = if tag_like.is_some() { " AND d.body ILIKE $4 ESCAPE '\\'" } else { "" };
    let sql = format!("SELECT d.source, d.source_pk::bigint, d.object_id, d.created_at,
        ts_rank_cd(d.body_tsv, to_tsquery('simple', $1)) AS rank
        FROM ap_search_docs d WHERE d.body_tsv @@ to_tsquery('simple', $1)
        AND {source} AND {live}
        AND NOT EXISTS (SELECT 1 FROM ap_users u JOIN actor_profile p ON p.actor_key=u.actor_key
          WHERE COALESCE(p.indexable,1)=0 AND u.id<>$3::bigint
          AND (substr(d.object_id,1,length(rtrim(u.actor_id,'/') || '/notes/'))=rtrim(u.actor_id,'/') || '/notes/'
            OR (d.source='event' AND EXISTS (SELECT 1 FROM events e WHERE e.id=d.source_pk AND rtrim(e.actor_id,'/')=rtrim(u.actor_id,'/')))))
        {tag_sql} ORDER BY rank DESC, d.created_at DESC LIMIT $2");
    let tsquery = super::query::postgres_match(query);
    let params: Vec<&(dyn tokio_postgres::types::ToSql + Sync)> = if let Some(tag_like) = tag_like.as_ref() {
        vec![&tsquery, &limit, &owner, tag_like]
    } else { vec![&tsquery, &limit, &owner] };
    db.query(&sql, &params).await.context("query canonical Search ranked candidates")?
        .into_iter().map(|row| Ok(SearchRow { source:row.try_get(0)?, source_pk:row.try_get(1)?,
            object_id:row.try_get(2)?, created_at:row.try_get(3)?, rank:row.try_get::<_, f32>(4)?.to_string().parse().context("decode canonical PDO Search rank")?, status:None })).collect()
}

pub(super) fn blend(fediverse: Vec<SearchRow>, bluesky: Vec<SearchRow>, limit: usize) -> Vec<SearchRow> {
    let mut fedi = fediverse.into_iter().peekable();
    let mut bsky = bluesky.into_iter().peekable();
    let mut turn_fedi = match (fedi.peek(), bsky.peek()) {
        (Some(a),Some(b)) => a.rank >= b.rank, (Some(_),None) => true, _ => false,
    };
    let mut output = Vec::new();
    while output.len() < limit {
        let row = if turn_fedi { fedi.next().or_else(|| bsky.next()) } else { bsky.next().or_else(|| fedi.next()) };
        let Some(row) = row else { break };
        output.push(row); turn_fedi = !turn_fedi;
    }
    output
}

pub(super) async fn candidates(db: &Client, owner: i64, text: &str, tag: &str, limit: i64) -> Result<Vec<SearchRow>> {
    let matched = if tag.is_empty() { super::query::text_match(text) } else { super::query::tag_match(tag) };
    let Some(matched) = matched else { return Ok(Vec::new()) };
    let limit = limit.clamp(1,180);
    // A missing optional Bsky relation is an explicit PHP fallback boundary.
    // This read-only projection cannot reproduce canonical migration/backfill.
    let fedi = network_hits(db, owner, &matched, tag, limit, false).await?;
    let bsky = network_hits(db, owner, &matched, tag, limit, true).await?;
    Ok(blend(fedi,bsky,limit as usize))
}
