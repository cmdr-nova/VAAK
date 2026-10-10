#!/usr/bin/env python3
"""Verify actual Rust queries return int4 even with legacy mixed-width flags."""
import json,pathlib,re
root=pathlib.Path(__file__).resolve().parents[2]
fields=json.loads((root/'rust/vaak-worker/fixtures/settings/profile-fields.json').read_text())['fields']
text_fields={'name','summary','attachment_json','icon_url','image_url','reply_policy','quote_policy','forum_signature','profile_badges','updated_at'}
profile=(root/'rust/vaak-worker/src/home_hydrate_ranked.rs').read_text()
settings=(root/'rust/vaak-worker/src/settings.rs').read_text()
pquery=re.search(r'"(SELECT lower\(actor_key\).*?WHERE lower\(actor_key\) = ANY\(\$1\))"',profile,re.S)[1]
squery=re.search(r'"(SELECT u.actor_key,.*?u.disabled_at IS NULL)"',settings,re.S)[1]
cols=['actor_key TEXT']+[f+(' TEXT' if f in text_fields else (' BIGINT' if i%2 else ' INTEGER')) for i,f in enumerate(fields)]
print('BEGIN; CREATE TABLE actor_profile ('+','.join(cols)+');')
print("CREATE TABLE ap_users (id BIGINT,actor_key TEXT,disabled_at TEXT); INSERT INTO ap_users VALUES (201,'alice',NULL);")
values=["'alice'"]+["'fixture'" if f in text_fields else ('1' if f=='automated' else '0') for f in fields]
print('INSERT INTO actor_profile VALUES ('+','.join(values)+');')
print('CREATE FUNCTION fixture_profiles(TEXT[]) RETURNS TABLE(actor_key TEXT,name TEXT,icon_url TEXT,automated INTEGER,discoverable INTEGER,indexable INTEGER) LANGUAGE SQL AS $fixture$'+pquery+'$fixture$;')
returns=['actor_key TEXT']+[f+(' TEXT' if f in text_fields else ' INTEGER') for f in fields]
print('CREATE FUNCTION fixture_settings(BIGINT) RETURNS TABLE('+','.join(returns)+') LANGUAGE SQL AS $fixture$'+squery+'$fixture$;')
print("""DO $test$ BEGIN
 IF (SELECT count(*) FROM fixture_profiles(ARRAY['alice']))<>1 THEN RAISE EXCEPTION 'local profile mismatch'; END IF;
 IF (SELECT automated FROM fixture_profiles(ARRAY['alice']))<>1 THEN RAISE EXCEPTION 'automated mismatch'; END IF;
 IF (SELECT discoverable FROM fixture_settings(201))<>0 THEN RAISE EXCEPTION 'discoverable mismatch'; END IF;
 IF (SELECT indexable FROM fixture_settings(201))<>0 THEN RAISE EXCEPTION 'indexable mismatch'; END IF;
 IF (SELECT algorithm_enabled FROM fixture_settings(201))<>0 THEN RAISE EXCEPTION 'algorithm mismatch'; END IF;
 END $test$;
 SELECT 'profile-preferences-postgres: PASS (mixed widths, 26 canonical fields)' AS result;
 ROLLBACK;""")
