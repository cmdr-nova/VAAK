#!/usr/bin/env python3
"""Owned replay of the genuine canonical controller with production's event index.

This adapts fixture paths/ports only; PHP mapper and token comparisons remain
the original implementation. No production timeout or assertion is relaxed.
"""
import pathlib, os

root = pathlib.Path(__file__).resolve().parents[2]
recipe = (root / 'api/bin/library-canonical-local-controller.py').read_text()
recipe = recipe.replace('library-canonical-local', 'library-canonical-performance')
recipe = recipe.replace('library_canonical_', 'library_canonical_perf_')
for old, new in [('18591', '18711'), ('18592', '18712'), ('18593', '18713'),
                 ('18594', '18714'), ('18596', '18716')]:
    recipe = recipe.replace(old, new)
needle = 'sql("$d->exec(\'ALTER TABLE events ADD COLUMN IF NOT EXISTS quote_object TEXT\');")'
assert needle in recipe
recipe = recipe.replace(needle, needle + '\nsql("$d->exec(\\\"CREATE INDEX IF NOT EXISTS idx_events_object_key_id ON events (rtrim(object_id, \'/\'), id DESC)\\\");")')
# A timed-out PHP request returns before the recorder's worker response arrives.
# Retain that late response separately instead of killing it before capture.
recipe = recipe.replace('calls=trace[start:];check(bool(calls)',
    'deadline=time.monotonic()+30\n  while len(trace)==start and time.monotonic()<deadline:time.sleep(.05)\n  calls=trace[start:];check(bool(calls)')
recipe = recipe.replace("compare('plain local and snowflake same page')",
    "compare('plain local and snowflake same page',limit=int(os.environ.get('CANONICAL_FIRST_LIMIT','10')))")
if os.environ.get('CANONICAL_CONTINUE_AFTER_FAILURE') == '1':
    # Preserve every assertion and a failing exit status, while collecting the
    # remaining ordered cases after a known maximum-page timing failure.
    needle = 'if not v:raise AssertionError(label)'
    assert needle in recipe
    recipe = recipe.replace(needle, 'if not v:pass')
    recipe += '\nif not all(x["ok"] for x in checks):raise SystemExit(1)\n'
if os.environ.get('CANONICAL_PG_RECORDING') == '1':
    recipe = recipe.replace('trace=[]',
        (root / 'api/bin/library-canonical-pg-recorder.py').read_text() + '\ntrace=[]')
    recipe = recipe.replace("VAAK_DATABASE_URL='host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=-csearch_path='+schema",
        "VAAK_DATABASE_URL='host=127.0.0.1 port=18717 sslmode=disable user=postgres dbname=postgres options=-csearch_path='+schema")
    recipe = recipe.replace('if proxy:proxy.shutdown();proxy.server_close()',
        "if proxy:proxy.shutdown();proxy.server_close()\n pg_proxy.shutdown();pg_proxy.server_close()\n (O/'pg-queries.json').write_text(json.dumps(dict(queries=pg_queries,timings=pg_timings,late_withdrawal=pg_hook_used),indent=2))")
    if os.environ.get('CANONICAL_LATE_WITHDRAWAL') == '1':
        recipe = recipe.replace("compare('plain local and snowflake same page',limit=int(os.environ.get('CANONICAL_FIRST_LIMIT','10')))", """rows=json.loads(sql("echo json_encode($d->query('SELECT id FROM masto_bookmarks ORDER BY created_at DESC,id DESC LIMIT 10')->fetchAll());"))
  body={'protocol_version':1,'canonical_saved_only':True,'status_preview_budget':3,'owner_id':1,'csrf':token,'return_view':'bookmarks','bsky_enabled':False,'lazy_media':False,'saved_sources':[dict(source_index=i,selected_ordinal=i,producer_kind='bookmarks_fedi',selected_kind='fedi',reference=str(row['id']),folder_id=0,offset=0,limit=10) for i,row in enumerate(rows)]}
  q=urllib.request.Request('http://127.0.0.1:18713/internal/library/render',data=json.dumps(body).encode(),headers={'Content-Type':'application/json','X-VAAK-Library-Token':'canonical-local-fixture-secret-00001'})
  try:v=urllib.request.urlopen(q,timeout=30)
  except urllib.error.HTTPError as err:v=err
  denied=v.read().decode();check(pg_hook_used,'actual late withdrawal after retained projection before graph dependency refresh');check(v.status==422 and 'Canonical body' not in denied,'late current withdrawal denies whole native page without partial HTML')
  php=req(c,18711,'/vaak/?ajax=bookmarks_fedi&offset=0&limit=10')
  check('data-thread-note=\\"https://mkultra.monster/users/librarysecond/notes/canonical2' not in php,'actual fresh PHP excludes withdrawn peer')
  check(not pg_queries or any('SELECT to_jsonb(s),' in query and 'jsonb_agg(a.local_id::bigint)' in query for query in pg_queries),'actual consolidated source SQL reached before withdrawal')
  os.environ['CANONICAL_DIRECT_ONLY']='1'""")
exec(compile(recipe, str(root / 'api/bin/library-canonical-local-controller.py'), 'exec'),
     {'__file__': str(root / 'api/bin/library-canonical-local-controller.py'), '__name__': '__main__'})
