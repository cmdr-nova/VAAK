#!/usr/bin/env python3
"""Emit isolated fixture SQL for the actual Rust search query."""
import pathlib,re
root=pathlib.Path(__file__).resolve().parents[2]
s=(root/'rust/vaak-worker/src/search.rs').read_text()
query=re.search(r'"(SELECT source, source_pk, object_id, created_at,\s*ts_rank_cd.*?LIMIT \$2)"',s,re.S)[1]
print('''BEGIN;
CREATE TABLE ap_users (id BIGINT,actor_key TEXT,actor_id TEXT);
CREATE TABLE actor_profile (actor_key TEXT,indexable INTEGER);
CREATE TABLE events (id BIGINT,actor_id TEXT);
CREATE TABLE ap_search_docs (source TEXT,source_pk BIGINT,object_id TEXT,created_at TEXT,body_tsv TSVECTOR);
INSERT INTO ap_users VALUES (201,'alice_1','https://mkultra.monster/users/alice_1'),(202,'alicex1','https://mkultra.monster/users/alicex1');
INSERT INTO actor_profile VALUES ('alice_1',0),('alicex1',1);
INSERT INTO events VALUES (3,'https://mkultra.monster/users/alice_1');
INSERT INTO ap_search_docs VALUES ('status',1,'https://mkultra.monster/users/alice_1/notes/old','2026-10-10',to_tsvector('simple','fixture keyword')),('status',2,'https://mkultra.monster/users/alicex1/notes/other','2026-10-10',to_tsvector('simple','fixture keyword')),('event',3,'https://remote.test/cached-copy','2026-10-10',to_tsvector('simple','fixture keyword'));
''')
print('CREATE FUNCTION fixture_search(TEXT,BIGINT,BIGINT) RETURNS TABLE(source TEXT,source_pk BIGINT,object_id TEXT,created_at TEXT,rank DOUBLE PRECISION) LANGUAGE SQL AS $fixture$'+query+'$fixture$;')
print("""DO $test$ BEGIN
 IF (SELECT array_agg(source_pk) FROM fixture_search('keyword:*',40,202)) IS DISTINCT FROM ARRAY[2::BIGINT] THEN RAISE EXCEPTION 'opt-out failed'; END IF;
 IF (SELECT count(*) FROM fixture_search('keyword:*',40,201))<>3 THEN RAISE EXCEPTION 'own search failed'; END IF;
 UPDATE actor_profile SET indexable=1 WHERE actor_key='alice_1';
 IF (SELECT count(*) FROM fixture_search('keyword:*',40,202))<>3 THEN RAISE EXCEPTION 're-enable failed'; END IF;
 END $test$;
SELECT 'search-indexing-postgres: PASS (3 checks)' AS result; ROLLBACK;""")
