#!/usr/bin/env python3
"""Actual isolated application SAPI; only DB/session paths and login/media provisioning are fixtures."""
import sys, sqlite3, pathlib, shutil, subprocess, os, json, time, urllib.request, urllib.parse, http.cookiejar, hashlib
ROOT=pathlib.Path(__file__).resolve().parents[2]
PG='--postgres' in sys.argv
BEFORE='--before' in sys.argv
EVIDENCE=pathlib.Path(os.environ.get('POSTING_THREADING_EVIDENCE',str(ROOT/'rust/target/parity-evidence/posting-threading-immediate')))
OUT=EVIDENCE/('sapi-pg' if PG else 'sapi')
if BEFORE:OUT=pathlib.Path(str(OUT)+'-before')
OUT.mkdir(parents=True,exist_ok=True)
APP=OUT/'app'; (APP/'api').mkdir(parents=True,exist_ok=True)
API_SOURCE=pathlib.Path(os.environ.get('POSTING_THREADING_SOURCE',str(ROOT/'api')))
for p in API_SOURCE.glob('*.php'): shutil.copy2(p,APP/'api'/p.name)
if BEFORE:
 for name in ['ap-admin.php','ap-db.php','ap-inbox.php']:(APP/'api'/name).write_bytes(subprocess.check_output(['git','show','bc6079a:api/'+name],cwd=ROOT))
for name in ['ap-db.php','ap-auth.php']:
 p=APP/'api'/name;s=p.read_text();s=s.replace('/var/lib/mkultra/ap/ap.sqlite',str(OUT/'fixture.sqlite')) if not PG else s;s=s.replace('/var/lib/mkultra/ap/sessions',str(OUT/'sessions'));p.write_text(s)
if not PG:
 p=APP/'api/ap-rss.php';s=p.read_text();s=s.replace('DEFAULT NOW()', 'DEFAULT CURRENT_TIMESTAMP').replace('DESC NULLS LAST', 'DESC');p.write_text(s)
(OUT/'sessions').mkdir(exist_ok=True)
for p in [OUT/'fixture.sqlite',OUT/'fixture.sqlite-wal',OUT/'fixture.sqlite-shm']:p.unlink(missing_ok=True)
db=sqlite3.connect(OUT/'fixture.sqlite');db.execute('CREATE TABLE direct_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,peer_actor_id TEXT NOT NULL,direction TEXT NOT NULL,content TEXT,created_at TEXT NOT NULL,read_at TEXT,activity_id TEXT,object_id TEXT NOT NULL UNIQUE,in_reply_to TEXT,media_urls TEXT,deleted_at TEXT)');db.commit();db.close()
router=APP/'router.php';router.write_text('''<?php
if (!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)) {http_response_code(404);exit;}
if (parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/fixture-session') {
 require __DIR__.'/api/ap-auth.php';ap_auth_bootstrap();$db=ap_db();
 foreach ([201=>'posting_fixture_a',202=>'posting_fixture_b'] as $id=>$key) {
 $db->prepare('INSERT OR IGNORE INTO ap_users(id,username,password_hash,actor_key,actor_id,created_at,updated_at) VALUES(?,?,?, ?,?,?,?)')->execute([$id,$key,'fixture-disabled-login',$key,'https://mkultra.monster/users/'.$key,ap_db_now(),ap_db_now()]);
 }
 $db->exec("INSERT OR IGNORE INTO masto_media(local_id,owner_user_id,created_at,s3_key,public_url,media_type,mime) VALUES(901,201,'2026-10-11T00:00:00Z','fixture','https://media.invalid/fixture-media.webp','image','image/webp')");
 ap_auth_start_session();$_SESSION['vaak_user_id']=(int)($_GET['owner']??201);header('Content-Type: application/json');echo json_encode(['csrf'=>ap_auth_csrf_token()]);exit;
}
if (parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH)==='/fixture-control') {
 require __DIR__.'/api/ap-auth.php';ap_auth_bootstrap();$db=ap_db();$cmd=$_POST['cmd']??'';$actor='https://mkultra.monster/users/posting_fixture_a';
 if($cmd==='mute')$db->prepare('INSERT INTO ap_mutes(owner_user_id,actor_id,created_at) VALUES(202,?,?)')->execute([$actor,ap_db_now()]);
 if($cmd==='unmute')$db->exec('DELETE FROM ap_mutes');
 if($cmd==='word')$db->prepare('INSERT INTO ap_muted_words(owner_user_id,phrase,created_at) VALUES(202,?,?)')->execute(['sapi-tip-media',ap_db_now()]);
 if($cmd==='unword')$db->exec('DELETE FROM ap_muted_words');
 if($cmd==='block')$db->prepare("INSERT INTO ap_blocks(created_at,updated_at,scope,value,kind) VALUES(?,?,'actor',?,'block')")->execute([ap_db_now(),ap_db_now(),$actor]);
 if($cmd==='unblock')$db->exec('DELETE FROM ap_blocks');
 if($cmd==='delete-parent'){$id=(string)$_POST['note'];$db->prepare('DELETE FROM outbox_notes WHERE id=?')->execute([$id]);$db->prepare('DELETE FROM masto_statuses WHERE note_id=?')->execute([$id]);ap_events_mark_object_deleted($id);}
 header('Content-Type: application/json');echo json_encode(['durable_delivery'=>(int)$db->query('SELECT count(*) FROM ap_publish_delivery_queue')->fetchColumn(),'published'=>(int)$db->query('SELECT count(*) FROM outbox_notes')->fetchColumn(),'scheduled'=>(int)$db->query('SELECT count(*) FROM ap_post_queue')->fetchColumn(),'notes'=>$db->query('SELECT id,published,content FROM outbox_notes')->fetchAll(PDO::FETCH_ASSOC)]);exit;
}
require __DIR__.'/api/ap-admin.php';
''')
env=os.environ.copy();env.update(AP_ALLOW_SQLITE='1',AP_DB_DSN='',VAAK_REDIS_ENABLED='0',VAAK_FEATURE_BLUESKY='0')
if PG:
 env['AP_DB_DSN']='pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=--search_path=posting_threading_sapi'
 env['POSTING_SAPI_API_SOURCE']=str(API_SOURCE)
 env['POSTING_SAPI_SQLITE']=os.environ.get('POSTING_SAPI_SQLITE',str(ROOT/'rust/target/parity-evidence/posting-threading-immediate/sapi/fixture.sqlite'))
 subprocess.run([str(ROOT/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so',str(ROOT/'api/bin/posting-threading-sapi-pg-provision.php')],env=env,check=True)
 s=router.read_text().replace('INSERT OR IGNORE INTO ap_users','INSERT INTO ap_users').replace('VALUES(?,?,?, ?,?,?,?)','VALUES(?,?,?, ?,?,?,?) ON CONFLICT(id) DO NOTHING').replace('INSERT OR IGNORE INTO masto_media','INSERT INTO masto_media').replace("'image/webp')","'image/webp') ON CONFLICT(local_id) DO NOTHING");router.write_text(s)
log=open(OUT/'server.log','w')
server=subprocess.Popen([str(ROOT/'api/bin/php-policy-test'),*( ['-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so'] if PG else []),'-d','disable_functions=exec,shell_exec,curl_init','-d','allow_url_fopen=0','-S','127.0.0.1:18994',str(router)],env=env,stdout=log,stderr=log)
checks=[];responses=[]
def check(v,msg):
 checks.append({'check':msg,'ok':bool(v)})
 if not v:raise AssertionError(msg)
def client(owner):
 c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 for i in range(30):
  try:r=c.open('http://127.0.0.1:18994/fixture-session?owner='+str(owner));break
  except Exception:time.sleep(.1)
 if 'r' not in locals():raise RuntimeError('fixture session failed; see server.log')
 return c,json.load(r)['csrf']
def req(c,path,data=None):
 r=c.open(urllib.request.Request('http://127.0.0.1:18994'+path,data=urllib.parse.urlencode(data).encode() if data else None,headers={'Accept':'application/json' if data else 'text/html'}));body=r.read().decode();responses.append({'path':path,'status':r.status,'headers':dict(r.headers),'body':body});return body
def post(c,csrf,content,**extra):
 d=dict(action='reply',ajax='1',csrf=csrf,content=content,visibility='public',return_view='home');d.update(extra);j=json.loads(req(c,'/vaak/?view=home',d));check(j.get('ok'),content+' published: '+str(j));
 if BEFORE:
  rows=json.loads(req(c,'/fixture-control',{'cmd':'state'}))['notes'];row=next(r for r in rows if content in r['content']);j.update(note_id=row['id'],published=row['published'])
 return j
def poll(c,j,view):
 ts=int(__import__('datetime').datetime.fromisoformat(j['published'].replace('Z','+00:00')).timestamp());return req(c,'/vaak/?view='+view+'&partial=1&newer=1&fresh_post=1&since='+str(ts-1 if BEFORE else ts))
try:
 a,csrf=client(201);b,bcsrf=client(202)
 parent=post(a,csrf,'sapi-parent-public');tip=post(a,csrf,'sapi-tip-media',in_reply_to=parent['note_id'],draft_media_ids='901')
 for view in ['home','local']:
  h=poll(a,tip,view);check('sapi-tip-media' in h,view+' saved tip immediate');check(('tweet-thread' in h)!=BEFORE,view+(' committed before misses self thread' if BEFORE else ' self thread wrapper'));check('fixture-media.webp' in h,view+' actual media painter')
  check('sapi-parent-public' in h,view+' ancestor visible')
 if BEFORE:
  (OUT/'result.json').write_text(json.dumps({'checks':checks,'count':len(checks),'responses':responses,'before_commit':'bc6079a','scope':'actual committed controller/publication/newer; current unchanged dependency files and declared fixture schema/session binding'},indent=2));print(json.dumps({'ok':True,'before':True,'checks':len(checks)}));sys.exit(0)
 private=post(a,csrf,'sapi-private-sentinel',visibility='private')
 check('sapi-private-sentinel' in poll(a,private,'home'),'own private Home')
 check('sapi-private-sentinel' not in poll(a,private,'local'),'own private excluded Local')
 check('sapi-private-sentinel' not in poll(b,private,'home'),'peer private excluded Home')
 for vis in ['unlisted','local']:
  p=post(a,csrf,'sapi-'+vis+'-sentinel',visibility=vis);check('sapi-'+vis+'-sentinel' in poll(a,p,'home'),'own '+vis+' Home');check('sapi-'+vis+'-sentinel' not in poll(b,p,'local'),vis+' excluded Local')
 q=json.loads(req(a,'/vaak/?view=home',dict(action='queue_post',ajax='1',csrf=csrf,content='sapi-scheduled-sentinel',visibility='public')));check(q.get('ok'),'scheduled queued');check('sapi-scheduled-sentinel' not in poll(a,tip,'home'),'scheduled absent Home')
 for cmd,clear in [('mute','unmute'),('word','unword'),('block','unblock')]:
  req(b,'/fixture-control',{'cmd':cmd});check('sapi-tip-media' not in poll(b,tip,'local'),cmd+' current policy excludes local saved tip');req(b,'/fixture-control',{'cmd':clear})
 req(a,'/fixture-control',{'cmd':'delete-parent','note':parent['note_id']});h=poll(a,tip,'local');check('sapi-tip-media' in h and 'sapi-parent-public' not in h,'deleted ancestor suppressed but visible tip preserved')
 state=json.loads(req(a,'/fixture-control',{'cmd':'state'}));check(state['durable_delivery']>=5 and state['scheduled']==1,'delivery durable while scheduled remains unpublished')
 (OUT/'result.json').write_text(json.dumps({'checks':checks,'count':len(checks),'responses':responses,'source_sha256':{p.name:hashlib.sha256(p.read_bytes()).hexdigest() for p in (APP/'api').glob('*.php')},'adaptations':['PG runtime/controller unmodified; fixture schema from empty column/unique-key inventory plus native RSS migration' if PG else 'SQLite bootstrap DM provisioning and RSS NOW/NULLS LAST dialect adaptations', 'DB/session path isolation','fixture sessions and two users; pre-existing media metadata','Redis and outbound HTTP/exec disabled; workers not run']},indent=2));print(json.dumps({'ok':True,'checks':len(checks)}))
finally:
 server.terminate();server.wait(timeout=5);log.close()
 if PG:
  env['POSTING_SAPI_DROP_ONLY']='1';subprocess.run([str(ROOT/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so',str(ROOT/'api/bin/posting-threading-sapi-pg-provision.php')],env=env,check=True,stderr=subprocess.DEVNULL)
 (OUT/'partial-results.json').write_text(json.dumps({'checks':checks,'responses':responses},indent=2))
