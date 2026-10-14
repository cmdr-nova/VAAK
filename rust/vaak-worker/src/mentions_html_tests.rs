use super::*;
use serde_json::json;
use crate::notif_list::{full_page_after_cursor, items_after_cursor, notif_id_digits, warm_head_limits};

fn fav(id: &str, acct: &str, uri: &str, status: &str) -> Value {
    json!({
        "id": id,
        "type": "favourite",
        "created_at": "2026-10-05T00:00:00Z",
        "account": {
            "id": acct,
            "acct": acct,
            "display_name": acct,
            "uri": uri,
            "url": uri,
            "avatar": "https://mkultra.monster/img/avatar/default.webp"
        },
        "status": {
            "id": "1",
            "uri": status,
            "url": status,
            "content": "<p>hi</p>",
            "account": {"acct": "you", "uri": "https://mkultra.monster/users/you"}
        }
    })
}

#[test]
fn groups_likes_on_same_status() {
    let rows = vec![
        fav("10", "a", "https://example.com/users/a", "https://mkultra.monster/users/you/notes/1"),
        fav("11", "b", "https://example.com/users/b", "https://mkultra.monster/users/you/notes/1"),
        fav("12", "c", "https://example.com/users/c", "https://mkultra.monster/users/you/notes/2"),
    ];
    let grouped = group_notification_rows(&rows);
    assert_eq!(grouped.len(), 2);
    assert_eq!(grouped[0].count, 2);
    assert_eq!(grouped[0].accounts.len(), 2);
    assert_eq!(grouped[1].count, 1);
    let html = paint_grouped_card(&grouped[0], "");
    assert!(html.contains("tweet-notif-grouped"));
    assert!(html.contains("2 people liked your post"));
    assert!(html.contains("notification-avatar-stack"));
    assert!(html.contains("data-profile-hover-actor=\"https://example.com/users/a\""));
    assert!(html.contains("data-profile-hover-actor=\"https://example.com/users/b\""));
    let single = paint_notif_card(&rows[0], "");
    assert!(single.contains("data-profile-hover-actor=\"https://example.com/users/a\""));
    assert!(single.matches("data-profile-hover-actor=\"https://example.com/users/a\"").count() >= 3);
}

#[test]
fn does_not_group_mentions() {
    let rows = vec![json!({
        "id": "1",
        "type": "mention",
        "account": {"acct": "a", "uri": "https://example.com/users/a"},
        "status": {"uri": "https://example.com/notes/1", "content": "hi"}
    })];
    let grouped = group_notification_rows(&rows);
    assert_eq!(grouped.len(), 1);
    assert_eq!(grouped[0].count, 1);
}

#[test]
fn status_notifications_keep_full_interaction_bar() {
    let row = json!({
        "id": "99",
        "type": "mention",
        "created_at": "2026-10-06T00:00:00Z",
        "account": {
            "acct": "alice@example.test",
            "display_name": "Alice",
            "uri": "https://example.test/users/alice",
            "url": "https://example.test/@alice",
            "avatar": "https://example.test/avatar.webp"
        },
        "status": {
            "id": "123",
            "uri": "https://mkultra.monster/users/you/notes/123",
            "url": "https://mkultra.monster/users/you/notes/123",
            "content": "<p>Hello</p>",
            "account": {
                "acct": "you@mkultra.monster",
                "uri": "https://mkultra.monster/users/you"
            },
            "favourited": false,
            "reblogged": false,
            "bookmarked": false
        }
    });
    let html = paint_notif_card(&row, "https://mkultra.monster/users/cmdr_nova");
    assert!(html.contains("name=\"action\" value=\"favourite_status\""), "missing favourite action");
    assert!(html.contains("name=\"action\" value=\"reblog_status\""), "missing boost action");
    assert!(html.contains("quote_object="), "missing quote action");
    assert!(html.contains("name=\"action\" value=\"bookmark_status\""), "missing bookmark action");
    assert!(html.contains("post-action-menu"), "missing overflow");
    assert!(html.contains(">Block</button>"), "missing block");
    assert!(html.contains(">Mute</button>"), "missing mute");
    assert!(html.contains(">Report user</a>"), "missing report");
    assert_eq!(html.matches("title=\"Reply\"").count(), 1, "reply action duplicated");
}

#[test]
fn bluesky_reply_keeps_boost_and_overflow_without_at_uri() {
    let row = json!({
        "id": "1225",
        "type": "mention",
        "created_at": "2026-10-06T00:00:00Z",
        "account": {
            "acct": "foxgrrl.northsky.social",
            "display_name": "Fox",
            "uri": "https://bsky.app/profile/foxgrrl.northsky.social",
            "url": "https://bsky.app/profile/foxgrrl.northsky.social",
            "avatar": "https://example.test/a.webp"
        },
        "status": {
            "id": "1791355324200001225",
            "uri": "https://bsky.app/profile/foxgrrl.northsky.social/post/3mxbdtfyqac2z",
            "url": "https://bsky.app/profile/foxgrrl.northsky.social/post/3mxbdtfyqac2z",
            "content": "<p>reply</p>",
            "account": {
                "acct": "foxgrrl.northsky.social",
                "uri": "https://bsky.app/profile/foxgrrl.northsky.social"
            }
        }
    });
    let html = paint_notif_card(&row, "https://mkultra.monster/users/cmdr_nova");
    assert!(html.contains("data-bsky-action=\"repost\""), "missing bluesky boost");
    assert!(html.contains("data-object-ref=\"https://bsky.app/profile/foxgrrl.northsky.social/post/3mxbdtfyqac2z\""));
    assert!(html.contains("data-bsky-action=\"like\""));
    assert!(html.contains("data-bsky-action=\"bookmark\""));
    assert!(html.contains("post-action-menu"), "missing overflow");
    assert!(html.contains(">Block</button>"));
    assert!(html.contains("Open on Bluesky"));
    assert!(!html.contains(">DM</a>"), "bluesky actors must not get a fediverse DM");
}

#[test]
fn bluesky_reply_uses_stored_at_uri_for_the_boost_target() {
    let row = json!({
        "id": "1225",
        "type": "mention",
        "account": {
            "acct": "foxgrrl.northsky.social",
            "uri": "https://bsky.app/profile/foxgrrl.northsky.social"
        },
        "status": {
            "id": "1791355324200001225",
            "uri": "https://bsky.app/profile/foxgrrl.northsky.social/post/3mxbdtfyqac2z",
            "url": "https://bsky.app/profile/foxgrrl.northsky.social/post/3mxbdtfyqac2z",
            "vaak_bsky_uri": "at://did:plc:g6upn5vo6kbqffgtdo32hnoi/app.bsky.feed.post/3mxbdtfyqac2z",
            "content": "<p>reply</p>",
            "account": {
                "acct": "foxgrrl.northsky.social",
                "uri": "https://bsky.app/profile/foxgrrl.northsky.social"
            }
        }
    });
    let html = paint_notif_card(&row, "https://mkultra.monster/users/cmdr_nova");
    assert!(html.contains("data-uri=\"at://did:plc:g6upn5vo6kbqffgtdo32hnoi/app.bsky.feed.post/3mxbdtfyqac2z\""));
    assert!(html.contains("data-bsky-uri=\"at://did:plc:g6upn5vo6kbqffgtdo32hnoi/app.bsky.feed.post/3mxbdtfyqac2z\""));
}

#[test]
fn fediverse_quote_boost_actions_stay_on_the_quoting_post() {
    let row = json!({
        "id": "80",
        "type": "quote",
        "account": {
            "acct": "alice@example.test",
            "uri": "https://example.test/users/alice"
        },
        "status": {
            "id": "80",
            "uri": "https://example.test/users/alice/statuses/80",
            "url": "https://example.test/users/alice/statuses/80",
            "content": "<p>quoting you</p>",
            "account": {
                "acct": "alice@example.test",
                "uri": "https://example.test/users/alice"
            },
            "quote": {
                "id": "1",
                "uri": "https://labyrinth.zone/users/scooter/statuses/9",
                "url": "https://labyrinth.zone/users/scooter/statuses/9",
                "content": "<p>original</p>",
                "account": {
                    "acct": "scooter@labyrinth.zone",
                    "uri": "https://labyrinth.zone/users/scooter"
                }
            }
        }
    });
    let html = paint_notif_card(&row, "https://mkultra.monster/users/cmdr_nova");
    assert_eq!(
        html.matches("name=\"action\" value=\"reblog_status\"").count(),
        1,
        "only the quote-boost gets a boost action"
    );
    assert_eq!(
        html.matches("class=\"post-action-menu\"").count(),
        1,
        "only the quote-boost gets an overflow menu"
    );
    let nested_at = html
        .find("data-timeline-key=\"1\"")
        .expect("quoted post");
    let nested_end = html[nested_at..]
        .find("</article>")
        .map(|n| nested_at + n)
        .unwrap_or(html.len());
    let nested = &html[nested_at..nested_end];
    assert!(
        !nested.contains("tweet-actions") && !nested.contains("reblog_status"),
        "quoted post has no action bar"
    );
    assert!(html.contains("quoting you"));
    assert!(html.contains("original"), "quoted post stays visible");
    assert!(html.contains("https://labyrinth.zone/users/scooter"));
    assert!(!html.contains("Follow back"), "quote cards do not follow back");
}

fn follow_row(uri: &str, url: &str) -> Value {
    json!({
        "id": "1002001",
        "type": "follow",
        "created_at": "2026-10-06T12:00:00Z",
        "account": {
            "acct": "alice@example.test",
            "display_name": "Alice",
            "uri": uri,
            "url": url,
            "avatar": "https://example.test/a.webp"
        }
    })
}

#[test]
fn already_following_matches_aliases_and_bsky_dids() {
    let mut set = HashSet::new();
    remember_follow(&mut set, "https://example.test/users/alice");
    assert!(already_following(&set, "https://example.test/@alice"));
    assert!(already_following(&set, "https://example.test/users/alice/"));
    assert!(!already_following(&set, "https://example.test/users/bob"));

    let mut bsky = HashSet::new();
    remember_follow(&mut bsky, "did:plc:abc123");
    assert!(already_following(
        &bsky,
        "https://bsky.app/profile/did:plc:abc123"
    ));
    assert!(!already_following(
        &bsky,
        "https://bsky.app/profile/someone.bsky.social"
    ));

    let mut snow = HashSet::new();
    remember_follow(&mut snow, "https://mastodon.social/users/1168123");
    assert!(already_following(
        &snow,
        "https://mastodon.social/users/1168123"
    ));
    assert!(!already_following(&snow, "https://mastodon.social/@1168123"));
}

#[test]
fn new_follower_offers_follow_back() {
    let html = paint_notif_card_follows(
        &follow_row(
            "https://example.test/users/alice",
            "https://example.test/@alice",
        ),
        "https://mkultra.monster/users/cmdr_nova",
        &HashSet::new(),
    );
    assert!(html.contains("name=\"action\" value=\"follow_remote\""));
    assert!(html.contains("name=\"return_view\" value=\"mentions\""));
    assert!(
        html.contains("name=\"actor_id\" value=\"https://example.test/users/alice\"")
    );
    assert!(html.contains(">Follow back</button>"));
    assert!(html.contains(">Profile</a>"));
}

#[test]
fn web_profile_follow_posts_the_users_actor() {
    let html = paint_notif_card_follows(
        &follow_row("https://example.test/@alice", "https://example.test/@alice"),
        "https://mkultra.monster/users/cmdr_nova",
        &HashSet::new(),
    );
    assert!(
        html.contains("name=\"actor_id\" value=\"https://example.test/users/alice\"")
    );
}

#[test]
fn follow_back_hidden_when_already_following() {
    let mut set = HashSet::new();
    remember_follow(&mut set, "https://example.test/@alice");
    let html = paint_notif_card_follows(
        &follow_row(
            "https://example.test/users/alice",
            "https://example.test/@alice",
        ),
        "https://mkultra.monster/users/cmdr_nova",
        &set,
    );
    assert!(!html.contains("follow_remote"));
    assert!(!html.contains("Follow back"));
    assert!(html.contains(">Profile</a>"));
}

#[test]
fn bluesky_follow_back_hidden_when_did_is_followed() {
    let mut set = HashSet::new();
    remember_follow(&mut set, "did:plc:g6upn5vo6kbqffgtdo32hnoi");
    let html = paint_notif_card_follows(
        &follow_row(
            "https://bsky.app/profile/did:plc:g6upn5vo6kbqffgtdo32hnoi",
            "https://bsky.app/profile/foxgrrl.northsky.social",
        ),
        "https://mkultra.monster/users/cmdr_nova",
        &set,
    );
    assert!(!html.contains("Follow back"));
    assert!(html.contains(">Profile</a>"));
}

#[test]
fn mention_cards_do_not_offer_follow_back() {
    let row = json!({
        "id": "99",
        "type": "mention",
        "account": {
            "acct": "alice@example.test",
            "uri": "https://example.test/users/alice",
            "url": "https://example.test/@alice"
        },
        "status": {
            "id": "123",
            "uri": "https://mkultra.monster/users/you/notes/123",
            "url": "https://mkultra.monster/users/you/notes/123",
            "content": "<p>Hello</p>",
            "account": {"acct": "you", "uri": "https://mkultra.monster/users/you"}
        }
    });
    let html = paint_notif_card_follows(
        &row,
        "https://mkultra.monster/users/cmdr_nova",
        &HashSet::new(),
    );
    assert!(!html.contains("Follow back"));
    assert!(!html.contains("follow_remote"));
}

#[test]
fn does_not_offer_follow_back_to_self() {
    let html = paint_notif_card_follows(
        &follow_row(
            "https://mkultra.monster/users/cmdr_nova",
            "https://mkultra.monster/@cmdr_nova",
        ),
        "https://mkultra.monster/users/cmdr_nova/",
        &HashSet::new(),
    );
    assert!(!html.contains("Follow back"));
}

fn row(id: &str) -> Value {
    json!({"id": id, "type": "follow"})
}

#[test]
fn scroll_slice_keeps_rows_after_the_cursor() {
    let items = vec![row("30"), row("20"), row("10"), row("9"), row("8")];
    let page = full_page_after_cursor(&items, "20", 2).expect("full page");
    assert_eq!(
        page.iter().map(notif_id_digits).collect::<Vec<_>>(),
        vec!["10", "9", "8"]
    );
}

#[test]
fn scroll_slice_matches_digit_cursor_against_decorated_ids() {
    let items = vec![row("id:30"), row("id:20"), row("id:10")];
    let page = full_page_after_cursor(&items, "20", 1).expect("full page");
    assert_eq!(notif_id_digits(&page[0]), "10");
}

#[test]
fn scroll_slice_misses_when_the_cursor_is_absent() {
    let items = vec![row("30"), row("20"), row("10")];
    assert!(full_page_after_cursor(&items, "15", 1).is_none());
    assert!(items_after_cursor(&items, "15").is_none());
}

#[test]
fn scroll_slice_misses_a_short_tail() {
    let items = vec![row("30"), row("20"), row("10")];
    assert!(items_after_cursor(&items, "20").unwrap().len() == 1);
    assert!(full_page_after_cursor(&items, "20", 2).is_none());
}

#[test]
fn scroll_slice_excludes_the_cursor_row() {
    let items = vec![row("5"), row("4"), row("4"), row("3")];
    let tail = items_after_cursor(&items, "4").unwrap();
    assert_eq!(
        tail.iter().map(notif_id_digits).collect::<Vec<_>>(),
        vec!["4", "3"]
    );
}

#[test]
fn grouped_favourites_need_php_cards_before_any_lookup() {
    let rows = vec![
        fav("3", "a@ex", "https://ex/users/a", "https://mkultra.monster/users/you/notes/1"),
        fav("2", "b@ex", "https://ex/users/b", "https://mkultra.monster/users/you/notes/1"),
    ];
    assert!(page_needs_php_grouped_cards(&rows));
}

#[test]
fn distinct_statuses_stay_on_the_html_path() {
    let rows = vec![
        fav("3", "a@ex", "https://ex/users/a", "https://mkultra.monster/users/you/notes/1"),
        fav("2", "b@ex", "https://ex/users/b", "https://mkultra.monster/users/you/notes/2"),
    ];
    assert!(!page_needs_php_grouped_cards(&rows));
}

#[test]
fn warm_heads_are_tried_deepest_first() {
    assert_eq!(warm_head_limits(12), vec![40, 30, 20, 12]);
    assert_eq!(warm_head_limits(40), vec![40, 30, 20]);
}

#[test]
fn html_raw_selection_keeps_consumption_after_admission_removes_tail() {
    let mut page = raw_page(json!({"items": [row("30"), row("20"), row("10")]}), "fixture".into(), "exact-raw", 3, None, false).unwrap().unwrap();
    page.items.clear();
    assert_eq!(page.primary_count, 3);
    assert_eq!(page.next_max_id, "10");
}

#[test]
fn html_head_selection_requires_a_complete_raw_window() {
    let payload = json!({"items": [row("30"), row("20"), row("10")]});
    assert!(raw_page(payload.clone(), "fixture".into(), "max-raw", 2, Some("20"), true).unwrap().is_none());
    assert!(raw_page(payload.clone(), "fixture".into(), "max-raw", 1, Some("15"), true).unwrap().is_none());
    let page = raw_page(payload, "fixture".into(), "max-raw", 1, Some("20"), true).unwrap().unwrap();
    assert_eq!(page.next_max_id, "10");
    assert_eq!(page.primary_count, 1);
}

#[test]
fn html_exact_short_page_remains_authoritative() {
    let page = raw_page(json!({"items": [row("10")]}), "fixture".into(), "exact-raw", 12, None, false).unwrap().unwrap();
    assert_eq!(page.primary_count, 1);
    assert_eq!(page.next_max_id, "10");
    assert!(raw_page(json!({"items": [{"type": "follow"}]}), "fixture".into(), "exact-raw", 12, None, false).unwrap().is_none());
}
