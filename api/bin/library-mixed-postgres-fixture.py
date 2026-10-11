#!/usr/bin/env python3
"""Emit isolated PostgreSQL differential tests for the mixed bookmark key stage.

The PHP reference SQL is captured by executing selected canonical functions with
only the query transport stubbed. The Rust SQL is read from MIXED_KEYS_SQL.
No application bootstrap, credentials, live DB, network or cache is loaded.
This verifies source ordering/selection, NOT card, authorization, action, or full
Library parity. Run output through psql -v ON_ERROR_STOP=1 in a disposable DB.
"""
import json
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[2]
# Historical ingress proof: before the separately justified guard fix, the
# current audience helper denied three cases that Library still dispatched.
# Renderer output remains stubbed; use this only to reproduce that boundary.
if '--emit-ingress-proof' in sys.argv:
    print(r"""<?php
declare(strict_types=1);
require '/home/cmdr-nova/vaak-public/api/bin/migration-reference-functions.php';
$db = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$db->exec("CREATE TABLE ap_users(id INTEGER,actor_id TEXT);
CREATE TABLE events(id INTEGER,object_id TEXT,type TEXT,action_taken TEXT,visibility TEXT,actor_id TEXT);
CREATE TABLE outbox_notes(id TEXT,visibility TEXT,to_json TEXT,cc_json TEXT);
CREATE TABLE mentions(owner_user_id INTEGER,deleted_at TEXT,activity_type TEXT,object_id TEXT);
CREATE TABLE followers(owner_actor_id TEXT,actor_id TEXT);
CREATE TABLE following(owner_actor_id TEXT,actor_id TEXT);
INSERT INTO ap_users VALUES(201,'https://mkultra.monster/users/viewer'),(202,'https://mkultra.monster/users/other');
INSERT INTO events VALUES(1,'https://remote.test/private','Create','log','private','https://remote.test/users/author');
INSERT INTO following VALUES('https://mkultra.monster/users/viewer','https://remote.test/users/author');
INSERT INTO outbox_notes VALUES('https://mkultra.monster/users/author/notes/direct','direct','[\"https://mkultra.monster/users/viewer\"]','[]');");
function ap_db(): PDO { return $GLOBALS['db']; }
function admin_owner_user_id(): int { return 201; }
function admin_render_masto_status_card(array $status, ...$args): void { $GLOBALS['painted'][] = $status; }
reference_load('/home/cmdr-nova/vaak-public/api/ap-db.php',['ap_db_owner_actor_id_for_user_id']);
reference_load('/home/cmdr-nova/vaak-public/api/ap-visibility.php',['ap_visibility_status_audience_allowed']);
reference_load('/home/cmdr-nova/vaak-public/api/ap-admin.php',['admin_library_status_is_thin','admin_library_status_enrich','admin_render_library_status_card']);
function check(bool $v,string $label): void { if (!$v) throw new RuntimeException($label); }
$private=['id'=>'interaction','uri'=>'https://remote.test/private','content'=>'saved private body','visibility'=>'public','bookmarked'=>true,'account'=>['uri'=>'https://remote.test/users/author']];
check(ap_visibility_status_audience_allowed($private,201),'accepted follower allowed');
check(!ap_visibility_status_audience_allowed($private,202),'other owner excluded');
$GLOBALS['painted']=[]; admin_render_library_status_card($private,'bookmarks');
check(count($GLOBALS['painted'])===1,'allowed saved post passed to shared renderer');
$db->exec('DELETE FROM following');
check(!ap_visibility_status_audience_allowed($private,201),'revoked follower rejected by actual current SQL');
$GLOBALS['painted']=[]; admin_render_library_status_card($private,'bookmarks');
check(count($GLOBALS['painted'])===1,'PROOF: Library still passed revoked card to renderer');
$direct=$private; $direct['uri']='https://mkultra.monster/users/author/notes/direct';
check(ap_visibility_status_audience_allowed($direct,201),'explicit direct recipient allowed');
check(!ap_visibility_status_audience_allowed($direct,202),'unrelated direct viewer excluded');
$db->exec('DELETE FROM outbox_notes');
check(!ap_visibility_status_audience_allowed($direct,201),'deleted local note rejected by actual current SQL');
$GLOBALS['painted']=[]; admin_render_library_status_card($direct,'favourites');
check(count($GLOBALS['painted'])===1,'PROOF: Library still passed deleted local card to renderer');
$db->exec("INSERT INTO events VALUES(2,'https://remote.test/public','Create','log','public','https://remote.test/users/author');");
$nested=$private; $nested['uri']='https://remote.test/public'; $nested['quote']=$private;
check(!ap_visibility_status_audience_allowed($nested,201),'recursive revoked quote excluded');
$GLOBALS['painted']=[]; admin_render_library_status_card($nested,'bookmarks');
check(count($GLOBALS['painted'])===1,'PROOF: Library still passed restricted nested card to renderer');
echo "library-ingress-proof: CONFIRMED (actual SQLite audience SQL rejects revoked follower/deleted local/restricted quote; current Library ingress still dispatches all three to shared card renderer; renderer itself stubbed, not full HTML proof)\n";
""".replace('/home/cmdr-nova/vaak-public', str(ROOT)))
    sys.exit(0)
NATIVE = ROOT / 'rust/vaak-worker/src/library_mixed.rs'
SQL = re.search(r'pub const MIXED_KEYS_SQL: &str = r#"(.*?)"#;', NATIVE.read_text(), re.S)[1]
# id, owner, status, object, timestamp. Equal timestamps deliberately test
# lexical source-ref order rather than numerical bookmark-id ordering.
rows = [
    [2,201,'s2','https://remote.test/2','2026-10-10T12:00:00Z'],
    [9,201,'s9','https://remote.test/9','2026-10-10T12:00:00Z'],
    [10,201,'s10','https://remote.test/10','2026-10-10T12:00:00Z'],
    [11,201,'bsky:cached','https://bsky.app/profile/did:plc:a/post/cached?query=1','2026-10-10T13:00:00Z'],
    [12,201,'bsky:uncached','at://did:plc:a/app.bsky.feed.post/uncached','2026-10-10T14:00:00Z'],
    [13,201,'bsky:direct','at://did:plc:a/app.bsky.feed.post/direct','2026-10-10T15:00:00Z'],
    [14,201,'bsky:handle','https://bsky.app/profile/a.example/post/handle','2026-10-10T16:00:00Z'],
    [15,201,'null-object',None,'2026-10-10T17:00:00Z'],
    [16,201,'bsky:null',None,'2026-10-10T18:00:00Z'],
    [17,201,'https-shape','https://bsky.app/profile/did:plc:a/post/https','2026-10-10T19:00:00Z'],
    [18,201,'rss:201:1','rss:201:1','2026-10-10T20:00:00Z'],
    [19,201,'private-saved','https://remote.test/private','2026-10-10T21:00:00Z'],
    [20,202,'other-owner','https://remote.test/2','2026-10-10T22:00:00Z'],
    [21,202,'bsky:other-owner','at://did:plc:a/app.bsky.feed.post/uncached','2026-10-10T23:00:00Z'],
]
caches = [
    [201,'at://did:plc:a/app.bsky.feed.post/cached','2026-10-10T13:30:00Z','{"post":{"viewer":{"liked":true}}}'],
    [201,'at://did:plc:a/app.bsky.feed.post/direct','2026-10-10T15:30:00Z','{"post":{}}'],
    [201,'at://did:plc:a/app.bsky.feed.post/https','2026-10-10T19:30:00Z','not JSON'],
    [201,'at://did:plc:a/app.bsky.feed.post/empty','2026-10-10T22:00:00Z','{"post":[]}'],
    [202,'at://did:plc:a/app.bsky.feed.post/uncached','2026-10-10T23:30:00Z','{"post":{}}'],
]
folders = [[7,201,'s2'],[7,201,'bsky:cached'],[7,201,'bsky:uncached'],[7,201,'bsky:null'],
           [8,201,'https://remote.test/9'],[8,201,'bsky:direct'],
           [9,202,'other-owner'],[9,202,'bsky:other-owner'],
           [10,201,'https-shape'],[10,201,'at://did:plc:a/app.bsky.feed.post/direct']]
cases = [(owner,folder,offset,limit) for owner,folder in [(201,0),(201,7),(201,8),(201,9),(201,10),(201,99),(202,0),(202,9)]
         for offset,limit in [(0,10),(2,10),(30,10)]]
# Reference transport simulates only folder-query rows and captures built SQL;
# SQL itself is executed below against PostgreSQL, never by this fake transport.
php = r'''
require_once dirname($argv[1]) . '/bin/migration-reference-functions.php';
reference_load($argv[1], ['admin_bookmark_bsky_at_uri','admin_bookmark_folder_bsky_uris','admin_bookmark_mixed_markup','admin_bookmark_mixed_fragment']);
class CaptureDB { function prepare($sql) { return new CaptureQuery($sql); } }
class CaptureQuery {
 function __construct(private string $sql) {}
 function execute($p) { $GLOBALS['captures'][] = ['sql'=>$this->sql, 'params'=>$p]; }
 function fetchAll() { return str_contains($this->sql,'SELECT mb.object_id') ? $GLOBALS['folder_rows'] : []; }
}
function ap_db() { return new CaptureDB(); }
$cases = json_decode(stream_get_contents(STDIN), true); $out=[];
foreach ($cases as $c) {
 $GLOBALS['captures']=[]; $GLOBALS['folder_rows']=$c['folder_rows'];
 $fragment=admin_bookmark_mixed_fragment(...$c['args']);
 foreach ($GLOBALS['captures'] as $q) if (str_contains($q['sql'],'SELECT kind, ref FROM')) { $q['fragment']=$fragment; $out[]=$q; }
}
echo json_encode($out,JSON_THROW_ON_ERROR);
'''
inputs=[]
uris=[]
for owner,folder,offset,limit in cases:
    alias_rows = [{'object_id': row[3]} for row in rows if row[1]==owner and row[2].startswith('bsky:')
                  and [folder,owner,row[2]] in folders]
    inputs.append({'args':[owner,folder,offset,limit],'folder_rows':alias_rows})
    aliases=[]
    for r in alias_rows:
        value=(r['object_id'] or '').strip()
        m=re.match(r'^https://bsky\.app/profile/(did:[^/]+)/post/([A-Za-z0-9._~:-]+)',value)
        uri=value if value.startswith('at://') else (f'at://{m[1]}/app.bsky.feed.post/{m[2]}' if m else None)
        if uri is not None and uri not in aliases: aliases.append(uri)
    uris.append(aliases)
refs=json.loads(subprocess.run(['php','-r',php,str(ROOT/'api/ap-admin.php')], input=json.dumps(inputs),text=True,capture_output=True,check=True).stdout)
if len(refs)!=len(cases): raise RuntimeError('PHP query capture count differs')
def quote(v):
    if v is None: return 'NULL'
    if isinstance(v,int): return str(v)
    return "'"+v.replace("'","''")+"'"
def array(values): return 'ARRAY['+','.join(quote(x) for x in values)+']::text[]'
def bind(sql,params,pattern):
    if pattern=='?':
        parts=sql.split('?')
        if len(parts)!=len(params)+1: raise RuntimeError('PHP placeholder count differs')
        return ''.join(p+quote(v) for p,v in zip(parts,params))+parts[-1]
    return re.sub(r'\$(\d+)',lambda m: params[int(m[1])-1],sql)
if '--emit-rust-harness' in sys.argv:
    # Compile this output in a disposable Cargo project with the existing
    # worker dependency versions, then execute inside the fixture container.
    # Include the actual native module and DB connection helper; config only
    # supplies the single field used by project (no application env bootstrap).
    print('mod config { pub struct Config { pub database_url: String } }')
    print('#[path = '+json.dumps(str(ROOT/'rust/vaak-worker/src/db.rs'))+'] mod db;')
    print('#[path = '+json.dumps(str(NATIVE))+'] mod mixed;')
    print('#[tokio::main] async fn main() -> anyhow::Result<()> {')
    print('let cfg=config::Config{database_url:"host=/var/run/postgresql user=postgres".into()};')
    print('let db=db::connect(&cfg.database_url).await?;')
    for width in ['INTEGER','BIGINT']:
        setup = 'CREATE TABLE masto_bookmarks (id '+width+',owner_user_id '+width+',status_id TEXT NOT NULL,object_id TEXT,created_at TEXT NOT NULL);'
        setup += 'CREATE TABLE bsky_bookmark_cache (owner_user_id '+width+',bookmark_uri TEXT NOT NULL,bookmarked_at TEXT NOT NULL,post_json TEXT);'
        setup += 'CREATE TABLE vaak_bookmark_folder_items (folder_id '+width+',owner_user_id '+width+',status_id TEXT NOT NULL);'
        for table,data in [('masto_bookmarks',rows),('bsky_bookmark_cache',caches),('vaak_bookmark_folder_items',folders)]:
            for row in data: setup += 'INSERT INTO '+table+' VALUES ('+','.join(map(quote,row))+');'
        setup += "INSERT INTO bsky_bookmark_cache VALUES (201,'at://did:plc:a/app.bsky.feed.post/null-json','2026-10-10T11:00:00Z',NULL);"
        print('db.batch_execute('+json.dumps(setup)+').await?;')
        for owner,folder,offset,limit in cases:
            print('let p=mixed::project(&cfg,'+','.join(map(str,[owner,folder,offset,limit]))+').await?;')
            print('assert!(!p.promotion_allowed); assert_eq!(p.next_offset, '+str(offset)+'+p.keys.len() as i64);')
            print('assert!(p.fedi_rows.iter().all(|r| r["owner_user_id"] == '+str(owner)+'));')
            print('assert!(p.bsky_rows.iter().all(|r| r["item"]["post"]["viewer"]["bookmarked"] == true));')
            if owner==201 and folder in [9,99]: print('assert!(p.keys.is_empty());')
            if owner==201 and folder==0 and offset==0:
                print('assert!(p.has_more); assert_eq!(p.keys.len(),10); assert_eq!(p.bsky_rows.len(),3);')
                print('assert!(p.keys.iter().any(|k|k.reference=="12")); assert!(!p.keys.iter().any(|k|k.kind=="fedi" && ["11","13","17","20","21"].contains(&k.reference.as_str())));')
        print('let p=mixed::project(&cfg,201,0,0,40).await?; assert_eq!(p.keys.len(),13); assert_eq!(p.bsky_rows.len(),3);')
        print('assert!(mixed::project(&cfg,0,0,0,20).await.is_err());')
        print('db.batch_execute("DROP TABLE masto_bookmarks,bsky_bookmark_cache,vaak_bookmark_folder_items;").await?;')
    print('println!("library-mixed-runtime: PASS (actual Rust project; 48 owner/folder/page combinations, INTEGER/BIGINT, NULL refs/JSON, malformed/list JSON, source cursor, privacy non-promotion)"); Ok(()) }')
    sys.exit(0)
print('-- Generated by api/bin/library-mixed-postgres-fixture.py; disposable DB only.')
print('BEGIN;')
for width in ['INTEGER','BIGINT']:
    print('CREATE TABLE masto_bookmarks (id '+width+',owner_user_id '+width+',status_id TEXT NOT NULL,object_id TEXT,created_at TEXT NOT NULL);')
    print('CREATE TABLE bsky_bookmark_cache (owner_user_id '+width+',bookmark_uri TEXT NOT NULL,bookmarked_at TEXT NOT NULL,post_json TEXT NOT NULL);')
    print('CREATE TABLE vaak_bookmark_folder_items (folder_id '+width+',owner_user_id '+width+',status_id TEXT NOT NULL);')
    for table,data in [('masto_bookmarks',rows),('bsky_bookmark_cache',caches),('vaak_bookmark_folder_items',folders)]:
        for row in data: print('INSERT INTO '+table+' VALUES ('+','.join(map(quote,row))+');')
    for n,((owner,folder,offset,limit),ref,aliases) in enumerate(zip(cases,refs,uris)):
        psql=bind(ref['sql'],ref['params'],'?')
        rsql=bind(SQL,[str(owner),str(folder),array(aliases),str(offset),str(limit+1)],'$')
        print('CREATE TEMP TABLE canonical AS SELECT row_number() OVER () AS ord,* FROM ('+psql+') q;')
        print('CREATE TEMP TABLE native AS SELECT row_number() OVER () AS ord,* FROM ('+rsql+') q;')
        print("DO $case$ BEGIN IF EXISTS ((SELECT * FROM canonical EXCEPT ALL SELECT * FROM native) UNION ALL (SELECT * FROM native EXCEPT ALL SELECT * FROM canonical)) THEN RAISE EXCEPTION 'mixed differential mismatch: %',"+quote(f'{width} owner={owner} folder={folder} offset={offset}')+"; END IF; END $case$;")
        if owner==201 and folder==0 and offset==0:
            print("DO $case$ BEGIN IF EXISTS (SELECT 1 FROM native WHERE kind='fedi' AND ref IN ('11','13','17','20','21','15')) THEN RAISE EXCEPTION 'cached duplicate/other-owner/null exclusion'; END IF; IF NOT EXISTS (SELECT 1 FROM native WHERE kind='fedi' AND ref='12') THEN RAISE EXCEPTION 'uncached Bluesky stub omitted'; END IF; END $case$;")
        if owner==201 and folder in [9,99]:
            print("DO $case$ BEGIN IF EXISTS (SELECT 1 FROM native) THEN RAISE EXCEPTION 'foreign/missing folder exposed rows'; END IF; END $case$;")
        print('DROP TABLE canonical,native;')
    print('DROP TABLE masto_bookmarks,bsky_bookmark_cache,vaak_bookmark_folder_items;')
print("SELECT 'library-mixed-postgres: PASS (48 canonical PHP/Rust query comparisons; INTEGER/BIGINT, chronology, dedupe, stubs, folders/owners, nullable objects; source stage only)' AS result; ROLLBACK;")
