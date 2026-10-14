//! Bookmark folder lists and the Bookmarks chrome.
//!
//! PHP still creates, deletes, and moves memberships. This module paints the
//! same list, counts, and HTML `vaak_bookmark_folders_list` plus the Bookmarks
//! shell produce. `VAAK_LIBRARY_NATIVE_RENDER` has to be on before a later
//! activation may replace the shell; while it is off, PHP keeps painting it.
use std::collections::BTreeSet;

use super::actions::esc;

#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct FolderRow {
    pub id: i64,
    pub owner_id: i64,
    pub title: String,
    pub position: i64,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct FolderItem {
    pub folder_id: i64,
    pub owner_id: i64,
    pub status_id: String,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct SavedBookmark {
    pub id: i64,
    pub owner_id: i64,
    pub status_id: String,
    pub object_id: String,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct FolderEvent {
    pub id: i64,
    pub object_id: String,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct LocalNote {
    pub local_id: i64,
    pub note_id: String,
}

#[derive(Clone, Debug, PartialEq, Eq)]
pub(crate) struct ListedFolder {
    pub id: i64,
    pub owner_id: i64,
    pub title: String,
    pub position: i64,
    pub item_count: i64,
}

/// SQLite `CAST(x AS INTEGER)` for the folder-match snowflake branch.
fn sqlite_integer(text: &str) -> i64 {
    let text = text.trim_start_matches([' ', '\t', '\n', '\r', '\u{0b}', '\u{0c}']);
    let (negative, rest) = if let Some(rest) = text.strip_prefix('-') {
        (true, rest)
    } else if let Some(rest) = text.strip_prefix('+') {
        (false, rest)
    } else {
        (false, text)
    };
    let digits: String = rest.chars().take_while(|ch| ch.is_ascii_digit()).collect();
    if digits.is_empty() {
        return 0;
    }
    let mut number: i64 = 0;
    for byte in digits.bytes() {
        number = number.saturating_mul(10).saturating_add((byte - b'0') as i64);
    }
    if negative { number.saturating_neg() } else { number }
}

fn slash(value: &str) -> &str {
    value.trim_end_matches('/')
}

/// Same association as the SQLite branch of `vaak_bookmark_folder_fedi_match_sql`.
fn fedi_match(status_id: &str, bookmark: &SavedBookmark, events: &[FolderEvent], locals: &[LocalNote]) -> bool {
    if status_id == bookmark.status_id || status_id == bookmark.object_id {
        return true;
    }
    if status_id.to_ascii_lowercase().starts_with("https://") && slash(status_id) == slash(&bookmark.object_id) {
        return true;
    }
    let object = slash(&bookmark.object_id);
    let status_n = sqlite_integer(status_id);
    for event in events {
        if slash(&event.object_id) != object {
            continue;
        }
        if event.id >= 0 && event.id < 1_000_000 && status_id == (3_000_000 + event.id).to_string() {
            return true;
        }
        if status_n >= 1_000_000_000_000_000_000
            && (status_n % 1_000_000_000) / 100_000_000 == 1
            && status_n % 100_000_000 == event.id
        {
            return true;
        }
    }
    locals.iter().any(|local| {
        local.local_id > 0
            && local.local_id < 2_000_000
            && status_id == local.local_id.to_string()
            && slash(&local.note_id) == object
    })
}

/// Owner folders in position, id order. One saved bookmark counts once even
/// when several stored keys point at it. A key with no bookmark stays raw.
pub(crate) fn list_folders(
    owner_id: i64,
    folders: &[FolderRow],
    items: &[FolderItem],
    bookmarks: &[SavedBookmark],
    events: &[FolderEvent],
    locals: &[LocalNote],
) -> Vec<ListedFolder> {
    if owner_id < 1 {
        return Vec::new();
    }
    let mut owned: Vec<&FolderRow> = folders.iter().filter(|folder| folder.owner_id == owner_id).collect();
    owned.sort_by_key(|folder| (folder.position, folder.id));
    owned.into_iter().map(|folder| {
        let mut keys = BTreeSet::new();
        for item in items.iter().filter(|item| item.folder_id == folder.id && item.owner_id == folder.owner_id) {
            let mut matched = false;
            for bookmark in bookmarks.iter().filter(|bookmark| bookmark.owner_id == item.owner_id) {
                if fedi_match(&item.status_id, bookmark, events, locals) {
                    keys.insert(format!("saved:{}", bookmark.id));
                    matched = true;
                }
            }
            if !matched {
                keys.insert(format!("raw:{}", item.status_id));
            }
        }
        ListedFolder {
            id: folder.id,
            owner_id: folder.owner_id,
            title: folder.title.clone(),
            position: folder.position,
            item_count: keys.len() as i64,
        }
    }).collect()
}

/// Exact stored status id. PHP does not expand aliases here.
pub(crate) fn folders_for_status(status_id: &str, owner_id: i64, items: &[FolderItem]) -> Vec<i64> {
    if status_id.is_empty() || owner_id < 1 {
        return Vec::new();
    }
    items.iter()
        .filter(|item| item.owner_id == owner_id && item.status_id == status_id && item.folder_id > 0)
        .map(|item| item.folder_id)
        .collect()
}

fn button(primary: bool) -> &'static str {
    if primary { "btn-primary" } else { "btn-ghost" }
}

/// Full Bookmarks page chrome, including a zero count and the delete form.
pub(crate) fn page_html(folders: &[ListedFolder], selected: i64, csrf: &str) -> String {
    let mut links = format!(
        "<a class=\"btn {}\" href=\"?view=bookmarks\" data-vaak-soft-nav=\"bookmarks\" style=\"padding:.3rem .7rem;font-size:.82rem\">All</a>",
        button(selected < 1)
    );
    for folder in folders {
        links.push_str(&format!(
            "<a class=\"btn {}\" href=\"?view=bookmarks&amp;folder={}\" data-vaak-soft-nav=\"bookmarks\" data-bm-folder=\"{}\" style=\"padding:.3rem .7rem;font-size:.82rem\">{} <span class=\"meta\">({})</span></a>",
            button(selected == folder.id),
            folder.id,
            folder.id,
            esc(&folder.title),
            folder.item_count
        ));
    }
    let mut html = format!(
        "<section class=\"side-card\" style=\"margin-bottom:1rem\"><div style=\"display:flex;flex-wrap:wrap;gap:.45rem;align-items:center;margin-bottom:.65rem\">{links}</div><form method=\"post\" action=\"?view=bookmarks\" class=\"composer\" style=\"margin:0;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center\"><input type=\"hidden\" name=\"csrf\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"bookmark_folder_create\"><input type=\"hidden\" name=\"return_view\" value=\"bookmarks\"><input name=\"title\" maxlength=\"80\" placeholder=\"New folder name…\" required style=\"flex:1;min-width:10rem\"><button class=\"btn btn-ghost\" type=\"submit\">Create folder</button></form><div class=\"meta\" style=\"margin-top:.55rem\">Folders are VAAK-only.</div></section>",
        esc(csrf)
    );
    if selected > 0 {
        html.push_str(&format!(
            "<form method=\"post\" action=\"?view=bookmarks\" style=\"margin:0 0 1rem\" onsubmit=\"return confirm('Delete this folder? Bookmarks stay saved under All.');\"><input type=\"hidden\" name=\"csrf\" value=\"{}\"><input type=\"hidden\" name=\"action\" value=\"bookmark_folder_delete\"><input type=\"hidden\" name=\"return_view\" value=\"bookmarks\"><input type=\"hidden\" name=\"folder_id\" value=\"{}\"><button class=\"btn btn-ghost\" type=\"submit\" style=\"color:var(--danger)\">Delete this folder</button></form>",
            esc(csrf),
            selected
        ));
    }
    html
}

/// Soft-nav tabs. Folder ids below 1 are omitted and counts are not shown.
pub(crate) fn soft_nav_html(folders: &[ListedFolder], selected: i64) -> String {
    let mut html = format!(
        "<nav class=\"notification-tabs\" aria-label=\"Bookmark folders\" style=\"display:flex;gap:.5rem;flex-wrap:wrap;margin:0 0 1rem\"><a class=\"btn {}\" href=\"?view=bookmarks\" data-vaak-soft-nav=\"bookmarks\" style=\"padding:.3rem .7rem;font-size:.82rem\">All</a>",
        button(selected < 1)
    );
    for folder in folders.iter().filter(|folder| folder.id > 0) {
        html.push_str(&format!(
            "<a class=\"btn {}\" href=\"?view=bookmarks&amp;folder={}\" data-vaak-soft-nav=\"bookmarks\" data-bm-folder=\"{}\" style=\"padding:.3rem .7rem;font-size:.82rem\">{}</a>",
            button(selected == folder.id),
            folder.id,
            folder.id,
            esc(&folder.title)
        ));
    }
    html.push_str("</nav>");
    html
}

#[cfg(test)]
mod tests {
    use super::*;
    use serde_json::Value;

    fn number(value: &Value) -> i64 {
        value.as_i64().or_else(|| value.as_str().and_then(|text| text.parse().ok())).unwrap_or(0)
    }

    fn text<'a>(value: &'a Value, key: &str) -> &'a str {
        value.get(key).and_then(Value::as_str).unwrap_or("")
    }

    fn rows(case: &Value, key: &str) -> Vec<Value> {
        case.get(key).and_then(Value::as_array).cloned().unwrap_or_default()
    }

    fn capture() -> Value {
        let file = std::path::Path::new(env!("CARGO_MANIFEST_DIR")).join("../../api/bin/library-folder-capture.php");
        let output = std::process::Command::new(file.parent().unwrap().join("php-policy-test")).arg(&file).output().unwrap();
        assert!(output.status.success(), "{}", String::from_utf8_lossy(&output.stderr));
        serde_json::from_slice(&output.stdout).unwrap()
    }

    #[test]
    fn folder_lists_counts_and_chrome_match_php() {
        let captured = capture();
        let cases = captured["cases"].as_array().unwrap();
        let mut seen = Vec::new();
        for case in cases {
            let id = text(case, "id");
            seen.push(id.to_owned());
            let folders = rows(case, "items").into_iter().map(|row| FolderItem {
                folder_id: number(&row["folder_id"]),
                owner_id: number(&row["owner_user_id"]),
                status_id: text(&row, "status_id").to_owned(),
            }).collect::<Vec<_>>();
            let bookmarks = rows(case, "bookmarks").into_iter().map(|row| SavedBookmark {
                id: number(&row["id"]),
                owner_id: number(&row["owner_user_id"]),
                status_id: text(&row, "status_id").to_owned(),
                object_id: text(&row, "object_id").to_owned(),
            }).collect::<Vec<_>>();
            let events = rows(case, "events").into_iter().map(|row| FolderEvent {
                id: number(&row["id"]),
                object_id: text(&row, "object_id").to_owned(),
            }).collect::<Vec<_>>();
            let locals = rows(case, "locals").into_iter().map(|row| LocalNote {
                local_id: number(&row["local_id"]),
                note_id: text(&row, "note_id").to_owned(),
            }).collect::<Vec<_>>();
            let stored = rows(case, "folders").into_iter().map(|row| FolderRow {
                id: number(&row["id"]),
                owner_id: number(&row["owner_id"]),
                title: text(&row, "title").to_owned(),
                position: number(&row["position"]),
            }).collect::<Vec<_>>();
            let listed = list_folders(number(&case["owner_id"]), &stored, &folders, &bookmarks, &events, &locals);
            let expected: Vec<ListedFolder> = rows(case, "folders").into_iter().map(|row| ListedFolder {
                id: number(&row["id"]),
                owner_id: number(&row["owner_id"]),
                title: text(&row, "title").to_owned(),
                position: number(&row["position"]),
                item_count: number(&row["item_count"]),
            }).collect();
            assert_eq!(listed, expected, "{id} folder list");
            let membership = folders_for_status(text(case, "status_id"), number(&case["owner_id"]), &folders);
            let php_membership: Vec<i64> = case["for_status"].as_array().unwrap().iter().map(number).collect();
            assert_eq!(membership, php_membership, "{id} folder membership");
            let selected = number(&case["selected"]);
            let csrf = text(case, "csrf");
            assert_eq!(page_html(&listed, selected, csrf), text(case, "page_html"), "{id} page chrome");
            assert_eq!(soft_nav_html(&listed, selected), text(case, "soft_html"), "{id} soft-nav chrome");
        }
        for id in ["guest", "empty", "renamed_empty", "filled", "moved", "other_owner"] {
            assert!(seen.iter().any(|seen| seen == id), "required PHP folder case {id}");
        }
        let renamed = cases.iter().find(|case| text(case, "id") == "renamed_empty").unwrap();
        let page = text(renamed, "page_html");
        assert!(page.contains("Tom &amp; &quot;Jerry&#039;s&quot; &lt;folder&gt;"));
        assert!(page.contains("<span class=\"meta\">(0)</span>"));
        assert!(page.contains("name=\"csrf\" value=\"csrf&amp;&lt;&gt;&quot;&#039;\""));
        assert!(page.contains("Delete this folder"));
        assert!(!text(renamed, "soft_html").contains("meta\">("));
        let moved = cases.iter().find(|case| text(case, "id") == "moved").unwrap();
        assert!(text(moved, "page_html").contains("btn-primary"));
        assert!(text(moved, "soft_html").contains("data-bm-folder="));
        let guest = cases.iter().find(|case| text(case, "id") == "guest").unwrap();
        assert!(guest["folders"].as_array().unwrap().is_empty());
        assert!(!text(guest, "page_html").contains("Delete this folder"));
    }
}
