#!/usr/bin/env python3
"""Exercise the source SQL with an isolated in-memory audience fixture.

SQLite adapts casts/placeholders only; PostgreSQL runtime checks remain separate.
"""
import re
import sqlite3
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
db = sqlite3.connect(":memory:")
db.create_function("btrim", 1, lambda value: value.strip() if value else value)
db.executescript("""
CREATE TABLE outbox_notes (id TEXT, published TEXT, content TEXT,
    raw_create_json TEXT, in_reply_to TEXT, visibility TEXT);
CREATE TABLE events (id INTEGER, actor_id TEXT, summary TEXT, media_urls TEXT,
    created_at TEXT, visibility TEXT, type TEXT, action_taken TEXT);
""")
prefix = "https://mkultra.monster/users/alice/notes/"
for index, visibility in enumerate(["public", "unlisted", "private", "direct", "local"]):
    for kind in ["post", "reply"]:
        db.execute("INSERT INTO outbox_notes VALUES (?,?,?,?,?,?)", (
            prefix + visibility + kind, f"2026-10-{index + 1:02d}", visibility,
            '{"attachment": []}', "https://remote.test/posts/parent" if kind == "reply" else "",
            visibility))
    db.execute("INSERT INTO events VALUES (?,?,?,?,?,?,?,?)", (
        index, "https://remote.test/users/a", "nonempty private text", '["private.jpg"]',
        f"2026-10-{index + 1:02d}", visibility, "Create", "log"))

def source_queries(path, start, end):
    text = (ROOT / path).read_text().split(start, 1)[1].split(end, 1)[0]
    return re.findall(r'"(SELECT(?:[^"\\]|\\.)*)"', text)

def adapt(sql):
    sql = sql.replace('\\"', '"').replace('::text', '')
    sql = re.sub(r'\$(\d+)', r':p\1', sql)
    return re.sub(r'= ANY\(ARRAY\[([^]]+)\]\)', r'IN (\1)', sql)

checks = 0
queries = source_queries("rust/vaak-worker/src/profile_html.rs", "async fn fetch_outbox_tab(", "let mut out =")
assert len(queries) == 3, "expected media, replies, posts source SQL"
for sql in queries:
    args = (prefix + "%", 10, 0) if "$4" not in sql else (prefix + "%", prefix + "%", 10, 0)
    rows = db.execute(adapt(sql), {f"p{i + 1}": v for i, v in enumerate(args)}).fetchall()
    assert rows and all("/public" in row[0] for row in rows), rows
    checks += 1
for start, end, table in [
    ("async fn fetch_local_timeline(", "async fn fetch_federated_events(", "outbox_notes"),
    ("async fn fetch_federated_events(", "pub async fn", "events"),
]:
    sql = next(q for q in source_queries("rust/vaak-worker/src/ranked_warm.rs", start, end)
               if "FROM " + table in q)
    rows = db.execute(adapt(sql)).fetchall()
    assert rows and all(("/public" in row[0] if table == "outbox_notes" else row[5] == "public") for row in rows), rows
    checks += 1
# The PHP profile fallback must use the same audience restriction.
queries = source_queries("api/ap-db.php", "function ap_local_profile_tab_page(", "function ")
queries = [q for q in queries if "FROM outbox_notes" in q]
assert len(queries) == 3
for sql in queries:
    rows = db.execute(adapt(sql), (prefix + "%", 10, 0)).fetchall()
    assert rows and all("/public" in row[0] for row in rows), rows
    checks += 1
print(f"PASS audience source queries: {checks} checks")
