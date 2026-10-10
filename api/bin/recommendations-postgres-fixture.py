#!/usr/bin/env python3
"""Emit SQL fixtures for the actual Rust query; pipe into an isolated test database."""
import json, pathlib, re
root=pathlib.Path(__file__).resolve().parents[2]
f=json.loads((root/'api/fixtures/recommendations/interactions.json').read_text())
s=(root/'rust/vaak-worker/src/recommendations.rs').read_text()
sql=re.search(r'pub const INTERACTED_SQL: &str = r#"(.*?)"#;',s,re.S)[1]
def quote(v):
    if v is None: return 'NULL'
    if isinstance(v,(int,float)): return str(v)
    return "'"+v.replace("'","''")+"'"
print('BEGIN;')
print('''CREATE TABLE ap_users (id BIGINT,actor_id TEXT);
CREATE TABLE masto_favourites (owner_user_id BIGINT,object_id TEXT);
CREATE TABLE masto_bookmarks (owner_user_id BIGINT,object_id TEXT);
CREATE TABLE masto_reblogs (owner_user_id BIGINT,object_id TEXT);
CREATE TABLE outbox_notes (id TEXT,in_reply_to TEXT,raw_create_json TEXT);
CREATE TABLE quote_authorizations (requester_actor TEXT,quoted_note_id TEXT);
CREATE TABLE ap_user_signals (owner_user_id BIGINT,signal_type TEXT,target_key TEXT,weight DOUBLE PRECISION,metadata_json TEXT);''')
for field,table in [('users','ap_users'),('favourites','masto_favourites'),('bookmarks','masto_bookmarks'),('reblogs','masto_reblogs'),('outbox','outbox_notes'),('authorizations','quote_authorizations'),('signals','ap_user_signals')]:
    for row in f[field]: print('INSERT INTO '+table+' VALUES ('+','.join(map(quote,row))+');')
print('CREATE FUNCTION fixture_consumed(BIGINT,TEXT[]) RETURNS TABLE(object_id TEXT) LANGUAGE SQL AS $fixture$'+sql+'$fixture$;')
expected=','.join(quote('https://remote.test/posts/'+x) for x in sorted(f['consumed']))
candidates=','.join(quote('https://remote.test/posts/'+x) for x in f['consumed']+f['unseen'])
print("DO $test$ DECLARE hits TEXT[]; BEGIN SELECT array_agg(object_id ORDER BY object_id) INTO hits FROM fixture_consumed(201,ARRAY["+candidates+"]); IF hits IS DISTINCT FROM ARRAY["+expected+"] THEN RAISE EXCEPTION 'recommendation mismatch: %',hits; END IF; END $test$;")
print("SELECT 'recommendations-postgres: PASS (18 action/owner cases)' AS result; ROLLBACK;")
