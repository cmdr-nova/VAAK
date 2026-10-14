//! Keyset cursors for Home, Local, and Federated web timelines.
//!
//! Mirrors `api/ap-timeline-cursor.php`. Older pages use created_at + source +
//! id. The cursor row is excluded. Filtered rows advance the cursor instead of
//! ending the timeline.

pub const OLDER_MAX_WINDOWS: usize = 5;

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct TlCursor {
    pub at: String,
    pub source: String,
    pub id: String,
}

#[derive(Debug, Clone)]
pub struct TlRow {
    pub at: String,
    pub source: String,
    pub id: String,
    pub keep: bool,
}

#[derive(Debug, Clone, PartialEq, Eq)]
pub struct SourceBound {
    pub op: &'static str,
    pub at: String,
    pub id: String,
}

#[derive(Debug, Clone)]
pub struct RawWindow<T> {
    pub rows: Vec<T>,
    pub full: bool,
}

#[derive(Debug, Clone)]
pub struct CollectResult<T> {
    pub kept: Vec<T>,
    pub has_more: bool,
    pub next: Option<TlCursor>,
    pub windows: usize,
}

pub fn source_rank(source: &str) -> i32 {
    match source {
        "*" => -1,
        "event" => 0,
        "outbox" => 1,
        "boost" => 2,
        "bsky" => 3,
        "rss" => 4,
        _ => 9,
    }
}

pub fn source_id_is_numeric(source: &str) -> bool {
    matches!(source, "event" | "boost" | "rss")
}

pub fn id_less(source: &str, left: &str, right: &str) -> bool {
    if right == "*" {
        return left != "*";
    }
    if source_id_is_numeric(source) {
        let l = left.parse::<i64>().unwrap_or(0);
        let r = right.parse::<i64>().unwrap_or(0);
        return l < r;
    }
    left < right
}

pub fn pack(cursor: &TlCursor) -> String {
    let at = cursor.at.replace(['\r', '\n', '|'], "");
    let source = cursor.source.replace(['\r', '\n', '|'], "");
    let id = cursor.id.replace(['\r', '\n'], "");
    if at.is_empty() || source.is_empty() || id.is_empty() {
        return String::new();
    }
    format!("v1|{at}|{source}|{id}")
}

pub fn unpack(token: &str) -> Option<TlCursor> {
    let token = token.trim();
    if !token.starts_with("v1|") {
        return None;
    }
    let mut parts = token.splitn(4, '|');
    let _ver = parts.next()?;
    let at = parts.next()?.to_string();
    let source = parts.next()?.to_string();
    let id = parts.next()?.to_string();
    if at.is_empty() || source.is_empty() || id.is_empty() {
        return None;
    }
    Some(TlCursor { at, source, id })
}

pub fn is_older(row_at: &str, row_source: &str, row_id: &str, cursor: Option<&TlCursor>) -> bool {
    let Some(cursor) = cursor else {
        return true;
    };
    if row_at.is_empty() || cursor.at.is_empty() {
        return false;
    }
    if row_at < cursor.at.as_str() {
        return true;
    }
    if row_at > cursor.at.as_str() {
        return false;
    }
    if cursor.source == "*" || cursor.id == "*" {
        return true;
    }
    let rs = source_rank(row_source);
    let cs = source_rank(&cursor.source);
    if rs > cs {
        return true;
    }
    if rs < cs {
        return false;
    }
    id_less(&cursor.source, row_id, &cursor.id)
}

pub fn row_is_older(row: &TlRow, cursor: Option<&TlCursor>) -> bool {
    is_older(&row.at, &row.source, &row.id, cursor)
}

pub fn source_bound(cursor: Option<&TlCursor>, source: &str) -> SourceBound {
    let Some(cursor) = cursor else {
        return SourceBound { op: "none", at: String::new(), id: String::new() };
    };
    if cursor.source == "*" || cursor.id == "*" {
        return SourceBound { op: "lte", at: cursor.at.clone(), id: String::new() };
    }
    if source == cursor.source {
        return SourceBound { op: "lt_id", at: cursor.at.clone(), id: cursor.id.clone() };
    }
    if source_rank(source) > source_rank(&cursor.source) {
        return SourceBound { op: "lte", at: cursor.at.clone(), id: String::new() };
    }
    SourceBound { op: "lt", at: cursor.at.clone(), id: String::new() }
}

pub fn sql_older_predicate(op: &str, at_column: &str, id_column: &str) -> String {
    match op {
        "lt" => format!("{at_column} < ?"),
        "lte" => format!("{at_column} <= ?"),
        "lt_id" => format!("({at_column}, {id_column}) < (?, ?)"),
        _ => "1=1".to_string(),
    }
}

/// Walk raw windows. `fetch` returns the next newest-first window strictly
/// older than the cursor. `full` means the SQL LIMIT was hit.
pub fn collect<T, F>(mut fetch: F, cursor: Option<TlCursor>, limit: usize, max_windows: usize) -> CollectResult<T>
where
    T: CursorRow,
    F: FnMut(Option<&TlCursor>) -> RawWindow<T>,
{
    let limit = limit.max(1);
    let max_windows = max_windows.max(1);
    let mut kept = Vec::new();
    let mut windows = 0usize;
    let mut has_more = false;
    let mut next = cursor;
    let mut exhausted = false;
    while windows < max_windows && kept.len() < limit {
        windows += 1;
        let page = fetch(next.as_ref());
        if page.rows.is_empty() {
            exhausted = true;
            has_more = false;
            break;
        }
        let row_count = page.rows.len();
        let mut advanced = false;
        let mut consumed = 0usize;
        for row in page.rows {
            if !row.is_older_than(next.as_ref()) {
                continue;
            }
            next = Some(row.cursor());
            advanced = true;
            consumed += 1;
            let keep = row.keep();
            if keep && kept.len() < limit {
                kept.push(row);
            }
            if kept.len() >= limit {
                break;
            }
        }
        if !advanced {
            exhausted = true;
            has_more = false;
            break;
        }
        if kept.len() >= limit {
            has_more = consumed < row_count || page.full;
            break;
        }
        if !page.full {
            exhausted = true;
            has_more = false;
            break;
        }
    }
    if !exhausted && kept.len() < limit {
        has_more = true;
    }
    CollectResult { kept, has_more, next, windows }
}

pub trait CursorRow {
    fn cursor(&self) -> TlCursor;
    fn is_older_than(&self, cursor: Option<&TlCursor>) -> bool;
    fn keep(&self) -> bool;
}

impl CursorRow for TlRow {
    fn cursor(&self) -> TlCursor {
        TlCursor { at: self.at.clone(), source: self.source.clone(), id: self.id.clone() }
    }
    fn is_older_than(&self, cursor: Option<&TlCursor>) -> bool {
        row_is_older(self, cursor)
    }
    fn keep(&self) -> bool {
        self.keep
    }
}

/// Async twin of [`collect`]. The fetcher owns its cursor clone so the
/// returned future does not borrow the caller's cursor.
pub async fn collect_async<T, F, Fut>(
    mut fetch: F,
    cursor: Option<TlCursor>,
    limit: usize,
    max_windows: usize,
) -> CollectResult<T>
where
    T: CursorRow,
    F: FnMut(Option<TlCursor>) -> Fut,
    Fut: std::future::Future<Output = RawWindow<T>>,
{
    let limit = limit.max(1);
    let max_windows = max_windows.max(1);
    let mut kept = Vec::new();
    let mut windows = 0usize;
    let mut has_more = false;
    let mut next = cursor;
    let mut exhausted = false;
    while windows < max_windows && kept.len() < limit {
        windows += 1;
        let page = fetch(next.clone()).await;
        if page.rows.is_empty() {
            exhausted = true;
            has_more = false;
            break;
        }
        let row_count = page.rows.len();
        let mut advanced = false;
        let mut consumed = 0usize;
        for row in page.rows {
            if !row.is_older_than(next.as_ref()) {
                continue;
            }
            next = Some(row.cursor());
            advanced = true;
            consumed += 1;
            let keep = row.keep();
            if keep && kept.len() < limit {
                kept.push(row);
            }
            if kept.len() >= limit {
                break;
            }
        }
        if !advanced {
            exhausted = true;
            has_more = false;
            break;
        }
        if kept.len() >= limit {
            has_more = consumed < row_count || page.full;
            break;
        }
        if !page.full {
            exhausted = true;
            has_more = false;
            break;
        }
    }
    if !exhausted && kept.len() < limit {
        has_more = true;
    }
    CollectResult { kept, has_more, next, windows }
}

pub fn memory_window(rows: &[TlRow], cursor: Option<&TlCursor>, fetch_limit: usize) -> RawWindow<TlRow> {
    let fetch_limit = fetch_limit.max(1);
    let mut older: Vec<TlRow> = rows
        .iter()
        .filter(|row| row_is_older(row, cursor))
        .cloned()
        .collect();
    older.sort_by(|a, b| {
        if row_is_older(a, Some(&b.cursor())) {
            std::cmp::Ordering::Greater
        } else if row_is_older(b, Some(&a.cursor())) {
            std::cmp::Ordering::Less
        } else {
            std::cmp::Ordering::Equal
        }
    });
    let full = older.len() > fetch_limit;
    if full {
        older.truncate(fetch_limit);
    }
    RawWindow { rows: older, full }
}

#[cfg(test)]
mod tests {
    use super::*;

    fn row(at: &str, source: &str, id: &str, keep: bool) -> TlRow {
        TlRow { at: at.into(), source: source.into(), id: id.into(), keep }
    }

    #[test]
    fn boundary_excludes_cursor_row_and_includes_next_id() {
        let cursor = unpack("v1|2026-01-02T00:00:00Z|event|10").unwrap();
        assert!(!is_older("2026-01-02T00:00:00Z", "event", "10", Some(&cursor)));
        assert!(is_older("2026-01-02T00:00:00Z", "event", "9", Some(&cursor)));
        assert!(!is_older("2026-01-02T00:00:00Z", "event", "11", Some(&cursor)));
        assert!(is_older("2026-01-01T00:00:00Z", "event", "99", Some(&cursor)));
    }

    #[test]
    fn ties_keep_same_timestamp_lower_id() {
        let rows = vec![
            row("2026-01-02T00:00:00Z", "event", "10", true),
            row("2026-01-02T00:00:00Z", "event", "9", true),
            row("2026-01-02T00:00:00Z", "event", "8", true),
        ];
        let first = collect(|cursor| memory_window(&rows, cursor, 10), None, 1, OLDER_MAX_WINDOWS);
        assert_eq!(first.kept[0].id, "10");
        assert!(first.has_more);
        let second = collect(
            |cursor| memory_window(&rows, cursor, 10),
            first.next.clone(),
            1,
            OLDER_MAX_WINDOWS,
        );
        assert_eq!(second.kept[0].id, "9");
        assert_eq!(second.next.as_ref().unwrap().id, "9");
    }

    #[test]
    fn filtered_empty_window_does_not_end_the_timeline() {
        let rows = vec![
            row("2026-01-05T00:00:00Z", "event", "5", false),
            row("2026-01-04T00:00:00Z", "event", "4", false),
            row("2026-01-03T00:00:00Z", "event", "3", false),
            row("2026-01-02T00:00:00Z", "event", "2", true),
        ];
        let page = collect(|cursor| memory_window(&rows, cursor, 2), None, 1, OLDER_MAX_WINDOWS);
        assert_eq!(page.kept.len(), 1);
        assert_eq!(page.kept[0].id, "2");
        assert!(page.windows > 1);
        assert!(!page.has_more);
    }

    #[test]
    fn full_filtered_windows_still_report_more_when_the_cap_is_hit() {
        let rows = vec![
            row("2026-01-05T00:00:00Z", "event", "5", false),
            row("2026-01-04T00:00:00Z", "event", "4", false),
            row("2026-01-03T00:00:00Z", "event", "3", false),
            row("2026-01-02T00:00:00Z", "event", "2", false),
        ];
        let page = collect(|cursor| memory_window(&rows, cursor, 2), None, 1, 1);
        assert!(page.kept.is_empty());
        assert!(page.has_more);
        assert_eq!(page.windows, 1);
    }
}
