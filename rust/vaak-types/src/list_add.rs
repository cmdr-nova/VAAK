//! "Add to list" (+) button shared by every Rust card painter.
//!
//! PHP twin: `admin_list_add_button_html` in api/ap-admin.php. Keep the
//! markup identical so a PHP fallback paints the same action row. The click
//! opens the list picker (`?ajax=list_picker`), which offers the viewer's
//! VAAK lists, their Bluesky lists and their moderation lists.

/// True for an author reference the picker can put on a list: an https actor
/// (fediverse, local, `https://bsky.app/profile/…`), a Bluesky DID, or a
/// Nostr key as `nostr:<64 hex>`.
pub fn list_add_actor_ok(actor: &str) -> bool {
    let actor = actor.trim();
    if let Some(hex) = actor.strip_prefix("nostr:") {
        return hex.len() == 64 && hex.bytes().all(|b| b.is_ascii_hexdigit());
    }
    (actor.starts_with("https://") && actor.len() > 8 && !actor.contains(['"', '<', '>', ' ']))
        || (actor.starts_with("did:") && actor.len() > 4 && !actor.contains(['"', '<', '>', ' ', '/']))
}

/// The + button, or "" when `actor` is not a reference the picker accepts.
pub fn list_add_button_html(actor: &str) -> String {
    let actor = actor.trim().trim_end_matches('/');
    if !list_add_actor_ok(actor) {
        return String::new();
    }
    let attr = actor.replace('&', "&amp;").replace('"', "&quot;").replace('<', "&lt;").replace('>', "&gt;");
    format!("<button type=\"button\" class=\"icon-btn list-add-btn\" data-list-add=\"{attr}\" title=\"Add to list\" aria-label=\"Add to list\"><i class=\"ph ph-plus\" aria-hidden=\"true\"></i></button>")
}

#[cfg(test)]
mod tests {
    use super::*;

    #[test]
    fn paints_for_every_network() {
        for actor in [
            "https://mastodon.social/users/alice",
            "https://bsky.app/profile/did:plc:abc",
            "did:plc:abc",
            "nostr:0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef",
        ] {
            let html = list_add_button_html(actor);
            assert!(html.contains(&format!("data-list-add=\"{actor}\"")), "{html}");
            assert!(html.contains("ph-plus"), "{html}");
        }
    }

    #[test]
    fn refuses_junk_and_trims_slash() {
        assert_eq!(list_add_button_html(""), "");
        assert_eq!(list_add_button_html("nostr:npub1xyz"), "");
        assert_eq!(list_add_button_html("javascript:alert(1)"), "");
        assert_eq!(list_add_button_html("https://x/\"onclick=1"), "");
        assert!(list_add_button_html("https://a.example/users/b/").contains("data-list-add=\"https://a.example/users/b\""));
    }
}
