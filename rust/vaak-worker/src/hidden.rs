//! Mute / block sets for notification filtering (parity with ap_row_is_hidden).

use anyhow::{Context, Result};
use std::collections::HashSet;
use tokio_postgres::Client;

#[derive(Debug, Default, Clone)]
pub struct HiddenSets {
    pub muted_actors: HashSet<String>,
    pub user_blocked_actors: HashSet<String>,
    pub user_blocked_domains: HashSet<String>,
    pub instance_blocked_actors: HashSet<String>,
    pub instance_blocked_domains: HashSet<String>,
}

impl HiddenSets {
    pub fn is_hidden(&self, actor_id: &str) -> bool {
        let actor = actor_id.trim_end_matches('/');
        if actor.is_empty() {
            return false;
        }
        if self.muted_actors.contains(actor) || self.user_blocked_actors.contains(actor) {
            return true;
        }
        if self.instance_blocked_actors.contains(actor) {
            return true;
        }
        let host = host_of(actor);
        if let Some(host) = host {
            let host_l = host.to_ascii_lowercase();
            if self.user_blocked_domains.contains(&host_l)
                || self.instance_blocked_domains.contains(&host_l)
            {
                return true;
            }
        }
        false
    }
}

fn host_of(actor: &str) -> Option<String> {
    let rest = actor.strip_prefix("https://")?;
    let host = rest.split('/').next()?;
    if host.is_empty() {
        None
    } else {
        Some(host.to_ascii_lowercase())
    }
}

fn norm_actor(s: &str) -> String {
    s.trim().trim_end_matches('/').to_string()
}

pub async fn load_hidden_sets(db: &Client, owner_user_id: i64) -> Result<HiddenSets> {
    let mut sets = HiddenSets::default();

    let mute_rows = db
        .query(
            "SELECT actor_id FROM ap_mutes WHERE owner_user_id = $1",
            &[&owner_user_id],
        )
        .await
        .context("select ap_mutes")?;
    for row in mute_rows {
        let a: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let a = norm_actor(&a);
        if !a.is_empty() {
            sets.muted_actors.insert(a);
        }
    }

    let user_block_rows = db
        .query(
            "SELECT scope, value FROM ap_user_blocks WHERE owner_user_id = $1",
            &[&owner_user_id],
        )
        .await
        .context("select ap_user_blocks")?;
    for row in user_block_rows {
        let scope: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let value: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        match scope.as_str() {
            "actor" => {
                let a = norm_actor(&value);
                if !a.is_empty() {
                    sets.user_blocked_actors.insert(a);
                }
            }
            "domain" => {
                let h = value.trim().trim_start_matches('.').to_ascii_lowercase();
                if !h.is_empty() {
                    sets.user_blocked_domains.insert(h);
                }
            }
            _ => {}
        }
    }

    let inst_rows = db
        .query("SELECT scope, value FROM ap_blocks", &[])
        .await
        .context("select ap_blocks")?;
    for row in inst_rows {
        let scope: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let value: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        match scope.as_str() {
            "actor" => {
                let a = norm_actor(&value);
                if !a.is_empty() {
                    sets.instance_blocked_actors.insert(a);
                }
            }
            "domain" => {
                let h = value.trim().trim_start_matches('.').to_ascii_lowercase();
                if !h.is_empty() {
                    sets.instance_blocked_domains.insert(h);
                }
            }
            _ => {}
        }
    }

    Ok(sets)
}

pub async fn is_favourited(db: &Client, owner_user_id: i64, object_id: &str) -> Result<bool> {
    let object_id = object_id.trim_end_matches('/');
    if object_id.is_empty() || !object_id.starts_with("https://") {
        return Ok(false);
    }
    let row = db
        .query_opt(
            "SELECT 1 FROM masto_favourites
             WHERE owner_user_id = $1 AND (object_id = $2 OR object_id = $3)
             LIMIT 1",
            &[&owner_user_id, &object_id, &format!("{object_id}/")],
        )
        .await
        .context("select masto_favourites")?;
    Ok(row.is_some())
}

pub async fn load_post_subscriptions(db: &Client, owner_user_id: i64) -> Result<HashSet<String>> {
    let rows = db
        .query(
            "SELECT target_actor_id FROM ap_post_subscriptions WHERE owner_user_id = $1",
            &[&owner_user_id],
        )
        .await
        .context("select ap_post_subscriptions")?;
    let mut out = HashSet::new();
    for row in rows {
        let a: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let a = norm_actor(&a);
        if !a.is_empty() {
            out.insert(a);
        }
    }
    Ok(out)
}

pub fn content_addresses_local(content: &str, local_actor_id: &str) -> bool {
    let local = local_actor_id.trim_end_matches('/');
    if local.is_empty() || content.is_empty() {
        return false;
    }
    if content.contains(local) {
        return true;
    }
    let key = local.rsplit('/').next().unwrap_or("");
    if key.is_empty() || !key.chars().all(|c| c.is_ascii_alphanumeric() || c == '_') {
        return false;
    }
    let host = host_of(local).unwrap_or_else(|| "mkultra.monster".into());
    let plain = strip_tags_light(content);
    // @key or @key@host
    let needle1 = format!("@{key}");
    let needle2 = format!("@{key}@{host}");
    plain.to_ascii_lowercase().contains(&needle1.to_ascii_lowercase())
        || plain.to_ascii_lowercase().contains(&needle2.to_ascii_lowercase())
}

fn strip_tags_light(s: &str) -> String {
    let mut out = String::with_capacity(s.len());
    let mut in_tag = false;
    for c in s.chars() {
        match c {
            '<' => in_tag = true,
            '>' => in_tag = false,
            _ if !in_tag => out.push(c),
            _ => {}
        }
    }
    out
}
