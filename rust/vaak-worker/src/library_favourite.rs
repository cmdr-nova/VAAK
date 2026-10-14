//! Favourite cards for mixed-network likes and cold URL stubs.
//!
//! PHP `ap_masto_interaction_row_to_status` with the `favourited` flag is the
//! bookmark projector with that flag flipped, plus a Bluesky like record.
//! `VAAK_LIBRARY_NATIVE_RENDER` still has to be on before this painter runs.
//! While it is off, PHP remains the favourites page. Quotes, polls, Asks,
//! announce chrome, and reply parents still fall back to PHP.

use anyhow::Result;
use serde_json::Value;

use super::bookmark::{project_saved_interaction, BookmarkCache, SavedFlag};

pub(crate) fn project_saved_favourite(row: &Value, cache: &BookmarkCache, now: chrono::DateTime<chrono::Utc>) -> Result<Option<Value>> {
    project_saved_interaction(row, cache, now, SavedFlag::Favourited)
}

#[cfg(test)]
mod tests {
    use super::*;
    use crate::audience::{replay_decision, ReplayRecord, Surface};
    use serde_json::json;
    use crate::hidden::{ordinary_library_status_hidden, HiddenSets};
    use crate::library::bookmark::{bookmark_path, project_bsky_favourite, project_saved_bookmark, BookmarkPath};
    use crate::library::card::render;
    use crate::library::mixed::{favourite_network_markup, Network};
    use crate::library::source::reject_thin_source;
    use std::collections::HashMap;

    fn fixture() -> Value {
        serde_json::from_str(include_str!("../fixtures/library/favourite-paths.json")).unwrap()
    }

    fn now() -> chrono::DateTime<chrono::Utc> {
        chrono::DateTime::parse_from_rfc3339("2024-07-01T00:00:00Z").unwrap().with_timezone(&chrono::Utc)
    }

    fn cache_from(local: Option<Value>, event: Option<Value>, actor: Option<Value>) -> BookmarkCache {
        let mut cache = BookmarkCache::default();
        if let Some(local) = local {
            let note = local.get("note_id").and_then(Value::as_str).unwrap_or("").trim_end_matches('/').to_owned();
            let id = local.get("local_id").and_then(Value::as_i64).unwrap_or(0);
            cache.local_by_note.insert(note, local.clone());
            if id > 0 {
                cache.local_by_id.insert(id, local);
            }
        }
        if let Some(event) = event {
            let object = event.get("object_id").and_then(Value::as_str).unwrap_or("").trim_end_matches('/').to_owned();
            let id = event.get("id").and_then(Value::as_i64).unwrap_or(0);
            cache.events_by_object.insert(object, event.clone());
            if id > 0 {
                cache.events_by_id.insert(id, event);
            }
        }
        if let Some(actor) = actor {
            let id = actor.get("actor_id").and_then(Value::as_str).unwrap_or("").trim_end_matches('/').to_owned();
            cache.actors.insert(id, actor);
        }
        cache
    }

    fn facts(status: &Value, auto: bool) -> crate::library::card::CardFacts {
        crate::library::card::CardFacts {
            current_action_counts: None,
            bsky: None,
            owner_id: 1,
            owner_actor: "https://mkultra.monster/users/fixture".into(),
            return_view: "favourites".into(),
            auto_unblur_sensitive: auto,
            resolved_avatar: "https://mkultra.monster/img/avatar/default.webp".into(),
            csrf: "fixture-csrf-token".into(),
            favourited: status.get("favourited").and_then(Value::as_bool) == Some(true),
            bookmarked: status.get("bookmarked").and_then(Value::as_bool) == Some(true),
            boosted: false,
            own: None,
            rss_row: None,
            rss_quote_row: None,
            rss_quoted: false,
            emoji_by_actor: Default::default(),
            reply_mentions: Vec::new(),
            moderation: Default::default(),
            poll_row: None,
            ask_row: None,
            now: now(),
            lazy_media: true,
        }
    }

    fn text<'a>(value: &'a Value, key: &str) -> &'a str {
        value.get(key).and_then(Value::as_str).unwrap_or("")
    }

    /// PHP drops Bluesky rows from the Fediverse favourites tab after hydration.
    fn fedi_tab(status: &Value) -> bool {
        let sid = text(status, "id");
        let uri = text(status, "uri");
        let reference = if uri.is_empty() { text(status, "url") } else { uri };
        !(sid.starts_with("bsky:") || reference.starts_with("at://") || reference.contains("bsky.app/"))
    }

    fn audience(visibility: &str, author: &str, recipients: &[&str], denied: bool) -> ReplayRecord {
        ReplayRecord {
            denied,
            visibility: visibility.into(),
            author: author.into(),
            recipients: recipients.iter().map(|item| (*item).to_owned()).collect(),
        }
    }

    #[test]
    fn fixture_covers_local_remote_bluesky_and_cold_favourites() {
        let fixture = fixture();
        let local_cache = cache_from(Some(fixture["local"]["local"].clone()), None, None);
        let local = project_saved_favourite(&fixture["local"]["row"], &local_cache, now()).unwrap().unwrap();
        assert_eq!(bookmark_path(&local), BookmarkPath::Local);
        assert_eq!(local["visibility"], "unlisted");
        assert_eq!(local["favourited"], true);
        assert_eq!(local["bookmarked"], false);
        assert_ne!(local["id"], "42");
        let local_html = render(&local, &facts(&local, false)).unwrap();
        assert!(local_html.contains("hello local favourite"));
        assert!(local_html.contains("unfavourite_status"));
        assert!(local_html.contains("name=\"return_view\" value=\"favourites\""));
        assert!(local_html.contains("cw-gate"), "sensitive favourites stay behind the CW unless auto-unblur is on");
        let open_html = render(&local, &facts(&local, true)).unwrap();
        assert!(!open_html.contains("cw-gate"));

        let remote_cache = cache_from(None, Some(fixture["remote"]["event"].clone()), Some(fixture["remote"]["actor"].clone()));
        let remote = project_saved_favourite(&fixture["remote"]["row"], &remote_cache, now()).unwrap().unwrap();
        let bookmark = project_saved_bookmark(&fixture["remote"]["row"], &remote_cache, now()).unwrap().unwrap();
        assert_eq!(bookmark_path(&remote), BookmarkPath::RemoteActivityPub);
        assert_eq!(remote["id"], "77", "object lookup keeps the saved interaction id");
        assert_eq!(remote["favourited"], true);
        assert_eq!(remote["bookmarked"], false);
        assert_eq!(bookmark["bookmarked"], true);
        assert_eq!(bookmark["favourited"], false);
        assert_eq!(remote["account"]["id"], bookmark["account"]["id"]);
        assert_eq!(remote["account"]["display_name"], "Alice Remote");
        assert_eq!(remote["account"]["acct"], "alice@remote.test");
        assert_eq!(remote["account"]["avatar"], "https://mkultra.monster/img/avatar/default.jpg");
        let remote_html = render(&remote, &facts(&remote, false)).unwrap();
        assert!(remote_html.contains("hello remote favourite"));
        assert!(remote_html.contains("unfavourite_status"));
        assert!(remote_html.contains("bookmark_status"));
        assert!(remote_html.contains(&format!("name=\"object_id\" value=\"{}\"", text(&remote, "uri"))));

        let bsky = project_bsky_favourite(&fixture["bluesky"]).unwrap();
        assert_eq!(bookmark_path(&bsky), BookmarkPath::Bluesky);
        assert_eq!(bsky["favourited"], true);
        assert_eq!(bsky["bookmarked"], false);
        assert_eq!(bsky["favourites_count"], 4);
        assert!(bsky["content"].as_str().unwrap().contains("hello bluesky favourite"));
        let bsky_html = render(&bsky, &facts(&bsky, false)).unwrap();
        assert!(bsky_html.contains("hello bluesky favourite"));
        assert!(bsky_html.contains("unfavourite_status"));
        assert!(bsky_html.contains("data-eng-count=\"4\""));
        assert!(bsky_html.contains("tweet-bsky"));
        let unlike = project_bsky_favourite(&fixture["bluesky_unlike"]).unwrap();
        assert_eq!(unlike["favourited"], false, "a non-string viewer.like is not a like record");
        assert!(render(&unlike, &facts(&unlike, false)).unwrap().contains("favourite_status"));

        let cold_author = project_bsky_favourite(&fixture["bluesky_cold"]).unwrap();
        assert_eq!(cold_author["account"]["display_name"], "cold.bsky.social");
        assert_eq!(cold_author["account"]["avatar"], "https://mkultra.monster/img/avatar/default.webp");
        assert_eq!(cold_author["favourited"], true);
        assert!(render(&cold_author, &facts(&cold_author, false)).unwrap().contains("cold.bsky.social"));

        let cold = project_saved_favourite(&fixture["cold"]["row"], &BookmarkCache::default(), now()).unwrap().unwrap();
        assert_eq!(bookmark_path(&cold), BookmarkPath::Cold);
        assert_eq!(cold["vaak_library_stub"], true);
        assert_eq!(cold["id"], "cold-1");
        assert_eq!(cold["sensitive"], false);
        assert_eq!(cold["favourited"], true);
        assert_eq!(cold["bookmarked"], false);
        assert_eq!(cold["account"]["uri"], "https://remote.test/users/alice");
        let cold_html = render(&cold, &facts(&cold, false)).unwrap();
        assert!(cold_html.contains("https://remote.test/users/alice/statuses/missing"));
        assert!(cold_html.contains("unfavourite_status"));
        assert!(reject_thin_source(&cold, true).is_none());
        assert!(reject_thin_source(&cold, false).is_some());

        let bsky_stub = project_saved_favourite(&fixture["cold_bsky_url"]["row"], &BookmarkCache::default(), now()).unwrap().unwrap();
        assert_eq!(bookmark_path(&bsky_stub), BookmarkPath::Cold);
        assert!(!fedi_tab(&bsky_stub), "https bsky.app favourites stay off the Fediverse tab");
        assert!(project_saved_favourite(&fixture["at_only"]["row"], &BookmarkCache::default(), now()).unwrap().is_none());
        let mut http_row = fixture["cold"]["row"].clone();
        http_row["object_id"] = json!("http://remote.test/users/alice/statuses/1");
        assert!(project_saved_favourite(&http_row, &BookmarkCache::default(), now()).unwrap().is_none());
    }

    #[test]
    fn favourite_time_order_and_current_pagination_slice() {
        let fixture = fixture();
        let mut rows = [fixture["cold_older"]["row"].clone(), fixture["cold"]["row"].clone(), fixture["cold_bsky_url"]["row"].clone()];
        rows.sort_by(|left, right| text(right, "created_at").cmp(text(left, "created_at")));
        let projected: Vec<Value> = rows.iter().map(|row| project_saved_favourite(row, &BookmarkCache::default(), now()).unwrap().unwrap()).collect();
        assert_eq!(projected[0]["id"], "bsky:uncached");
        assert_eq!(projected[1]["id"], "cold-1");
        assert_eq!(projected[2]["id"], "cold-0");
        let fedi: Vec<Value> = projected.iter().filter(|status| fedi_tab(status)).cloned().collect();
        assert_eq!(fedi.iter().map(|status| text(status, "id")).collect::<Vec<_>>(), vec!["cold-1", "cold-0"]);
        let offset = 0usize;
        let limit = 1usize;
        let has_more = fedi.len() > offset + limit;
        let page = &fedi[offset..offset + limit];
        assert!(has_more);
        assert_eq!(page[0]["id"], "cold-1");
        assert_eq!(fedi.len(), 2, "hydrated count before the Bluesky exclusion is not this filtered length");
    }

    #[test]
    fn empty_and_refresh_fragments_match_the_php_favourites_chrome() {
        let fedi_empty = favourite_network_markup(Network::Fedi, &[], 0, 20, false, true, false);
        assert_eq!(fedi_empty, "<div data-fedi-favourites-fragment data-offset=\"0\" data-limit=\"20\" data-has-more=\"0\"></div>");
        assert!(!fedi_empty.contains("empty"));
        assert!(!fedi_empty.contains("Fediverse favourites"));
        let bsky_empty = favourite_network_markup(Network::Bsky, &[], 0, 20, false, true, false);
        assert!(bsky_empty.contains("data-bsky-favourites-fragment"));
        assert!(bsky_empty.contains("data-bsky-favourite-empty"));
        assert!(bsky_empty.contains("No cached Bluesky favourites yet. They will appear after the background sync completes."));
        let refreshing = favourite_network_markup(Network::Bsky, &[], 0, 20, false, true, true);
        assert!(refreshing.contains("Bluesky favourites are refreshing in the background. Reload shortly."));
        let populated = favourite_network_markup(Network::Bsky, &["<article>liked</article>".into()], 1, 20, false, true, true);
        assert!(populated.contains("Showing cached Bluesky favourites while they refresh in the background."));
        assert!(populated.contains("Bluesky favourites</h3><article>liked</article>"));
        assert!(!populated.contains("data-bsky-favourite-empty"));
    }

    #[test]
    fn cached_hit_keeps_the_saved_favourite_and_unsupported_shapes_fall_back() {
        let fixture = fixture();
        let cache = cache_from(None, Some(fixture["remote"]["event"].clone()), Some(fixture["remote"]["actor"].clone()));
        let saved = project_saved_favourite(&fixture["remote"]["row"], &cache, now()).unwrap().unwrap();
        assert_eq!(saved["id"], "77");
        assert_eq!(saved["favourited"], true);
        assert!(saved.get("vaak_library_stub").is_none());
        assert!(saved["content"].as_str().unwrap().contains("hello remote favourite"));

        let mut quoted = fixture["remote"]["event"].clone();
        quoted["summary"] = json!("hello ↪ QT there");
        let cache = cache_from(None, Some(quoted), None);
        assert!(project_saved_favourite(&fixture["remote"]["row"], &cache, now()).unwrap_err().to_string().contains("quote"));

        let mut announce = fixture["remote"]["event"].clone();
        announce["type"] = json!("Announce");
        announce["summary"] = json!("");
        let cache = cache_from(None, Some(announce), None);
        assert!(project_saved_favourite(&fixture["remote"]["row"], &cache, now()).unwrap_err().to_string().contains("announce"));

        let mut embed = fixture["bluesky"].clone();
        embed["post"]["embed"] = json!({"$type": "app.bsky.embed.images"});
        assert!(project_bsky_favourite(&embed).unwrap_err().to_string().contains("structured"));
        let mut clock = fixture["bluesky"].clone();
        clock["post"]["record"]["createdAt"] = json!("");
        clock["post"]["indexedAt"] = json!("");
        assert!(project_bsky_favourite(&clock).unwrap_err().to_string().contains("created_at"));

        let mut local = fixture["local"]["local"].clone();
        local["content_text"] = json!("(media)");
        let cache = cache_from(Some(local), None, None);
        assert!(project_saved_favourite(&fixture["local"]["row"], &cache, now()).unwrap_err().to_string().contains("media"));

        let mut rss = fixture["cold"]["row"].clone();
        rss["status_id"] = json!("rss:11");
        rss["object_id"] = json!("rss:11");
        assert!(project_saved_favourite(&rss, &BookmarkCache::default(), now()).unwrap_err().to_string().contains("RSS"));
    }

    #[test]
    fn deleted_favourites_are_not_revived_and_audiences_follow_the_library_surface() {
        let fixture = fixture();
        let cache = cache_from(None, Some(fixture["deleted"]["event"].clone()), None);
        let deleted = project_saved_favourite(&fixture["deleted"]["row"], &cache, now()).unwrap().unwrap();
        assert_eq!(deleted["vaak_library_stub"], true);
        assert!(!deleted["content"].as_str().unwrap().contains("must not be revived"));
        let viewer = fixture["privacy"]["viewer"].as_str().unwrap();
        let mut withdrawn = HashMap::new();
        withdrawn.insert(text(&deleted, "uri").to_owned(), audience("public", text(&deleted["account"], "uri"), &[], true));
        assert!(!replay_decision(&deleted, &withdrawn, viewer, &[], Surface::Library), "a denied durable row stays unavailable");

        let local_cache = cache_from(Some(fixture["private_local"]["local"].clone()), None, None);
        let private_post = project_saved_favourite(&fixture["private_local"]["row"], &local_cache, now()).unwrap().unwrap();
        assert_eq!(private_post["visibility"], "private");
        let private_html = render(&private_post, &facts(&private_post, false)).unwrap();
        assert!(private_html.contains("Followers-only"));
        assert!(private_html.contains("unfavourite_status"));
        let author = fixture["privacy"]["author"].as_str().unwrap();
        let uri = text(&private_post, "uri").to_owned();
        assert!(!replay_decision(&private_post, &HashMap::new(), viewer, &[], Surface::Library), "a missing local audience cannot be rescued by the saved card");
        let mut records = HashMap::new();
        records.insert(uri.clone(), audience("private", author, &[], false));
        assert!(!replay_decision(&private_post, &records, viewer, &[], Surface::Library));
        assert!(replay_decision(&private_post, &records, viewer, &[author.to_owned()], Surface::Library));
        records.insert(uri, audience("direct", author, &[viewer], false));
        assert!(replay_decision(&private_post, &records, viewer, &[], Surface::Library));
        records.insert(text(&private_post, "uri").to_owned(), audience("direct", author, &[], false));
        assert!(!replay_decision(&private_post, &records, viewer, &[author.to_owned()], Surface::Library));

        let direct_cache = cache_from(None, Some(fixture["direct"]["event"].clone()), Some(fixture["remote"]["actor"].clone()));
        let direct = project_saved_favourite(&fixture["direct"]["row"], &direct_cache, now()).unwrap().unwrap();
        assert_eq!(direct["visibility"], "direct");
        assert_eq!(direct["favourited"], true);
        let direct_uri = text(&direct, "uri").to_owned();
        let mut direct_records = HashMap::new();
        direct_records.insert(direct_uri.clone(), audience("direct", "https://remote.test/users/alice", &[], false));
        assert!(!replay_decision(&direct, &direct_records, viewer, &[], Surface::Library));
        direct_records.insert(direct_uri, audience("direct", "https://remote.test/users/alice", &[viewer], false));
        assert!(replay_decision(&direct, &direct_records, viewer, &[], Surface::Library));
    }

    #[test]
    fn privacy_settings_and_downranking_still_apply_on_the_favourites_view() {
        let fixture = fixture();
        let cold = project_saved_favourite(&fixture["cold"]["row"], &BookmarkCache::default(), now()).unwrap().unwrap();
        let local_cache = cache_from(Some(fixture["local"]["local"].clone()), None, None);
        let local = project_saved_favourite(&fixture["local"]["row"], &local_cache, now()).unwrap().unwrap();
        let remote_cache = cache_from(None, Some(fixture["remote"]["event"].clone()), Some(fixture["remote"]["actor"].clone()));
        let remote = project_saved_favourite(&fixture["remote"]["row"], &remote_cache, now()).unwrap().unwrap();
        let bsky = project_bsky_favourite(&fixture["bluesky"]).unwrap();
        let open = HiddenSets::default();
        for status in [&cold, &local, &remote, &bsky] {
            assert!(!ordinary_library_status_hidden(status, &open), "{status}");
        }

        let mut blocked = HiddenSets::default();
        blocked.user_blocked_actors.insert(fixture["privacy"]["blocked_actor"].as_str().unwrap().to_owned());
        assert!(ordinary_library_status_hidden(&cold, &blocked));
        assert!(ordinary_library_status_hidden(&remote, &blocked));
        assert!(!ordinary_library_status_hidden(&local, &blocked));

        let mut domain = HiddenSets::default();
        domain.user_blocked_domains.insert(fixture["privacy"]["blocked_domain"].as_str().unwrap().to_owned());
        let mut subdomain = cold.clone();
        subdomain["uri"] = json!("https://sub.remote.test/users/alice/statuses/missing");
        subdomain["url"] = subdomain["uri"].clone();
        subdomain["account"]["uri"] = json!("https://sub.remote.test/users/alice");
        assert!(ordinary_library_status_hidden(&subdomain, &domain));
        assert!(!ordinary_library_status_hidden(&local, &domain));

        let phrase = fixture["privacy"]["phrase"].as_str().unwrap();
        let mut words = HiddenSets::default();
        words.muted_phrases.push(phrase.into());
        let mut muttered = cold.clone();
        muttered["content"] = json!(format!("<p>see {phrase} please</p>"));
        assert!(ordinary_library_status_hidden(&muttered, &words));
        let own_note = fixture["privacy"]["own_note"].as_str().unwrap();
        let own_actor = fixture["privacy"]["own_actor"].as_str().unwrap();
        let mut own = muttered.clone();
        own["uri"] = json!(own_note);
        own["account"]["uri"] = json!(own_actor);
        assert!(ordinary_library_status_hidden(&own, &words));
        words.ordinary_owned_notes.insert(own_note.trim_end_matches('/').to_owned(), own_actor.trim_end_matches('/').to_owned());
        assert!(!ordinary_library_status_hidden(&own, &words), "own-note word exemption stays on the ordinary library surface");

        let mut bsky_hidden = HiddenSets::default();
        bsky_hidden.muted_actors.insert("did:plc:abc".into());
        assert!(ordinary_library_status_hidden(&bsky, &bsky_hidden));

        let mut menu_facts = facts(&remote, false);
        menu_facts.moderation.muted = true;
        menu_facts.moderation.deprioritized = true;
        let menu = render(&remote, &menu_facts).unwrap();
        assert!(menu.contains("unmute_remote"));
        assert!(menu.contains("undeprioritize_remote"));
        assert!(menu.contains("name=\"return_view\" value=\"favourites\""));
        assert!(menu.contains("value=\"favourites\""));
    }
}
