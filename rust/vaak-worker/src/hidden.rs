//! Mute / block sets for notification filtering (parity with ap_row_is_hidden).

use anyhow::{Context, Result};
use serde_json::Value;
use std::collections::{HashMap, HashSet};
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
        let actor = actor_id.trim().trim_end_matches('/');
        if actor.is_empty() {
            return false;
        }
        for alias in actor_aliases(actor) {
            if self.muted_actors.contains(&alias)
                || self.user_blocked_actors.contains(&alias)
                || self.instance_blocked_actors.contains(&alias)
            {
                return true;
            }
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

/// Apply viewer moderation to already-hydrated cards as well as ranked IDs.
/// Cached envelopes can outlive a mute/block change, so both the card author
/// and a boost/reblog's underlying author must be checked at read time.
pub fn status_hidden(status: &Value, hidden: &HiddenSets) -> bool {
    let actor_hidden = |value: &Value| {
        value
            .get("account")
            .and_then(|a| a.get("uri").and_then(|v| v.as_str()).or_else(|| a.get("url").and_then(|v| v.as_str())))
            .map(|actor| hidden.is_hidden(actor))
            .unwrap_or(false)
    };
    actor_hidden(status)
        || status
            .get("reblog")
            .filter(|v| v.is_object())
            .map(actor_hidden)
            .unwrap_or(false)
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
    s.trim().trim_end_matches('/').to_ascii_lowercase()
}

fn actor_aliases(s: &str) -> Vec<String> {
    let actor = norm_actor(s);
    if actor.is_empty() {
        return Vec::new();
    }
    let mut aliases = vec![actor.clone()];
    if let Some(rest) = actor.strip_prefix("https://") {
        let mut parts = rest.splitn(2, '/');
        let host = parts.next().unwrap_or("");
        let path = parts.next().unwrap_or("").trim_matches('/');
        if !host.is_empty() {
            if let Some(name) = path.strip_prefix("users/") {
                if !name.is_empty() {
                    aliases.push(format!("https://{host}/@{name}"));
                }
            } else if let Some(name) = path.strip_prefix('@') {
                if !name.is_empty() {
                    aliases.push(format!("https://{host}/users/{name}"));
                }
            }
        }
    }
    aliases.sort();
    aliases.dedup();
    aliases
}

fn insert_actor_aliases(set: &mut HashSet<String>, actor: &str) {
    for alias in actor_aliases(actor) {
        set.insert(alias);
    }
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
            insert_actor_aliases(&mut sets.muted_actors, &a);
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
                    insert_actor_aliases(&mut sets.user_blocked_actors, &a);
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
                    insert_actor_aliases(&mut sets.instance_blocked_actors, &a);
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

/// Personal mute/block state for lean ⋯ menus (PHP `block_quick_actions` labels).
#[derive(Debug, Default, Clone)]
pub struct ViewerModeration {
    pub muted_actors: HashSet<String>,
    /// actor_id → ap_user_blocks.id (scope=actor) for Unblock forms.
    pub blocked_actors: HashMap<String, i64>,
}

impl ViewerModeration {
    pub fn is_muted(&self, actor_id: &str) -> bool {
        let a = norm_actor(actor_id);
        !a.is_empty() && self.muted_actors.contains(&a)
    }

    pub fn block_id(&self, actor_id: &str) -> Option<i64> {
        let a = norm_actor(actor_id);
        if a.is_empty() {
            return None;
        }
        self.blocked_actors.get(&a).copied()
    }
}

pub async fn load_viewer_moderation(db: &Client, owner_user_id: i64) -> Result<ViewerModeration> {
    let mut out = ViewerModeration::default();
    if owner_user_id < 1 {
        return Ok(out);
    }
    let mute_rows = db
        .query(
            "SELECT actor_id FROM ap_mutes WHERE owner_user_id = $1",
            &[&owner_user_id],
        )
        .await
        .context("select ap_mutes for moderation menu")?;
    for row in mute_rows {
        let a: String = row.try_get::<_, Option<String>>(0)?.unwrap_or_default();
        let a = norm_actor(&a);
        if !a.is_empty() {
            out.muted_actors.insert(a);
        }
    }
    let block_rows = db
        .query(
            "SELECT id, value FROM ap_user_blocks
             WHERE owner_user_id = $1 AND scope = 'actor'",
            &[&owner_user_id],
        )
        .await
        .context("select ap_user_blocks for moderation menu")?;
    for row in block_rows {
        let id: i64 = row.try_get(0)?;
        let value: String = row.try_get::<_, Option<String>>(1)?.unwrap_or_default();
        let a = norm_actor(&value);
        if !a.is_empty() && id > 0 {
            out.blocked_actors.insert(a, id);
        }
    }
    Ok(out)
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

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn moderation_matches_actor_url_aliases_case_insensitively() {
        let mut hidden = HiddenSets::default();
        insert_actor_aliases(&mut hidden.muted_actors, "https://mas.corq.co/users/rogue_corq");
        assert!(hidden.is_hidden("https://mas.corq.co/users/rogue_corq/"));
        assert!(hidden.is_hidden("https://MAS.CORQ.CO/@ROGUE_CORQ"));
        assert!(!hidden.is_hidden("https://mas.corq.co/users/other"));
    }
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
