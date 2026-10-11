#!/usr/bin/env python3
"""Actual full-controller local Bookmarks: SQL resolver facts, never normalized stubs."""
import pathlib,os,json,subprocess,time,urllib.request,urllib.parse,http.cookiejar,re,hashlib,threading,argparse,shutil,socket
from http.server import ThreadingHTTPServer,BaseHTTPRequestHandler
args=argparse.ArgumentParser();args.add_argument('--integer',action='store_true');args.add_argument('--worker');opt=args.parse_args()
R=pathlib.Path(__file__).resolve().parents[2]; suffix='integer' if opt.integer else 'bigint';O=R/'rust/target/parity-evidence/library-canonical-local'/suffix;A=O/'app';O.mkdir(parents=True,exist_ok=True)
# Before-wire isolation also covers real shared bootstrap subprocesses.
os.environ['LD_PRELOAD']=str(R/'rust/target/parity-evidence/library-controller-native-phase/loopback-only.so')
os.environ['VAAK_FIXTURE_NET_DENIAL_LOG']=str(O/'external-before-wire-denials.log')
if (O/'checks.json').exists():
 archive=O/'history'/str(time.time_ns());archive.mkdir(parents=True)
 for f in ['checks.json','captures.json','native-trace.json','worker.log','counters.jsonl','sapi-18591.log','sapi-18592.log']:
  if (O/f).exists():shutil.copy2(O/f,archive/f)
if (O/'counters.jsonl').exists():(O/'counters.jsonl').unlink()
# Shared actual bootstrap/provision recipe; stop before its unrelated action suite.
s=(R/'api/bin/library-settings-whole-flow.py').read_text().split("log=(O/'server.log')")[0]
s=s.replace("library-settings-whole-flow'","library-canonical-local/"+suffix+"'").replace("library_settings_whole_flow'","library_canonical_"+suffix+"'")
if opt.integer:s=s.replace("(O/'provision.php').write_text(provision)","provision=provision.replace('BIGSERIAL','SERIAL').replace('BIGINT','INTEGER');(O/'provision.php').write_text(provision)")
# Relative parent computation intentionally remains actual repository recipe path.
exec(compile(s,str(R/'api/bin/library-settings-whole-flow.py'),'exec'),{'__file__':str(R/'api/bin/library-settings-whole-flow.py'),'__name__':'__setup__'})
e=os.environ.copy();e.update(json.loads((O/'env.json').read_text()));schema='library_canonical_'+suffix;e.update(AP_DB_DSN='pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=--search_path='+schema,VAAK_SUPPORTER_CODES='0',VAAK_REGISTRATION_APPROVALS='0',VAAK_LIBRARY_NATIVE_DATA='0',LD_PRELOAD=str(R/'rust/target/parity-evidence/library-controller-native-phase/loopback-only.so'),VAAK_FIXTURE_NET_DENIAL_LOG=str(O/'external-before-wire-denials.log'))
cmd=[str(R/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so']
def sql(code):
 p=A/'control.php';p.write_text("<?php require __DIR__.'/api/ap-auth.php';ap_auth_bootstrap();require __DIR__.'/api/ap-masto-entities.php';require __DIR__.'/api/ap-bookmark-folders.php';vaak_bookmark_folders_ensure_schema();require __DIR__.'/api/ap-bsky.php';ap_bsky_migrate();ap_bsky_crossposts_migrate();ap_bsky_posts_migrate();require __DIR__.'/api/ap-rss.php';ap_rss_migrate();$d=ap_db();"+code)
 return subprocess.check_output(cmd+[str(p)],env=e).decode()
# Optional current event quote column: absent-schema fallback remains a separate vector.
sql("$d->exec('ALTER TABLE events ADD COLUMN IF NOT EXISTS quote_object TEXT');")
if opt.integer:
 sql("foreach($d->query(\"SELECT column_name FROM information_schema.columns WHERE table_schema='"+schema+"' AND table_name='masto_statuses' AND data_type='bigint'\")->fetchAll() as $r){$d->exec('ALTER TABLE masto_statuses ALTER COLUMN '. $r['column_name'].' TYPE INTEGER USING '.$r['column_name'].'::integer');}")
# Entry counters around genuine copied declarations. Original function bodies unchanged.
for name,file in [('ap_masto_interaction_row_to_status','ap-masto-entities.php'),('admin_library_status_enrich','ap-admin.php')]:
 p=A/'api'/file;x=p.read_text();m=re.search(r'^function '+name+r'\([^\n]*\n\{',x,re.M);assert m,name
 x=x[:m.end()]+"\n    $GLOBALS['canonical_mapper_counters']['"+name+"'] = ($GLOBALS['canonical_mapper_counters']['"+name+"'] ?? 0) + 1;"+x[m.end():];p.write_text(x)
if os.environ.get('CANONICAL_DIAGNOSTIC_TIMEOUT')=='1':
 p=A/'api/ap-library-native.php';p.write_text(p.read_text().replace('CURLOPT_TIMEOUT_MS => 3000','CURLOPT_TIMEOUT_MS => 30000'))
(A/'router.php').write_text("<?php register_shutdown_function(static function(){file_put_contents('"+str(O/'counters.jsonl')+"',json_encode(['uri'=>$_SERVER['REQUEST_URI']??'', 'counters'=>$GLOBALS['canonical_mapper_counters']??[]]).\"\\n\",FILE_APPEND);});require __DIR__.'/vaak/index.php';")
seed=sql("""$d->exec('DELETE FROM masto_bookmarks');$d->exec('DELETE FROM outbox_notes');$d->exec('DELETE FROM masto_statuses');$when='2026-10-11T12:00:00+00:00';$ids=[];for($i=1;$i<=45;$i++){$actor=$i%2?'cmdr_nova':'librarysecond';$note='https://mkultra.monster/users/'.$actor.'/notes/canonical'.$i;$content='Canonical body '.$i.' café & <safe>'; $html='<p>Canonical body '.$i.' café &amp; &lt;safe&gt;</p>';$d->prepare('INSERT INTO masto_statuses(local_id,note_id,create_id,published,content_text,visibility,spoiler_text,sensitive) VALUES(?,?,?,?,?,?,?,?)')->execute([$i,$note,$note.'/activity',$when,$content,'public','',0]);$raw=json_encode(['id'=>$note.'/activity','type'=>'Create','object'=>['id'=>$note,'type'=>'Note','attributedTo'=>'https://mkultra.monster/users/'.$actor,'content'=>$html,'to'=>['https://www.w3.org/ns/activitystreams#Public']]]);$d->prepare('INSERT INTO outbox_notes(id,create_id,published,content,to_json,cc_json,raw_create_json,kind,visibility) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$note,$note.'/activity',$when,$html,'["https://www.w3.org/ns/activitystreams#Public"]','[]',$raw,'compose','public']);$sid=$i===1?(string)$i:ap_masto_snowflake_id($when,$i,0);$d->prepare('INSERT INTO masto_bookmarks(owner_user_id,status_id,object_id,created_at) VALUES(?,?,?,?)')->execute([1,$sid,$note,sprintf('2026-10-11T12:00:%02d+00:00',46-$i)]);$ids[]=['sid'=>$sid,'note'=>$note];}echo json_encode($ids);""")
(O/'seed.json').write_text(seed)
trace=[]
class Proxy(BaseHTTPRequestHandler):
 def setup(self):
  super().setup();self.connection.setsockopt(socket.IPPROTO_TCP,socket.TCP_NODELAY,1)
 def do_POST(self):
  started=time.monotonic();raw=self.rfile.read(int(self.headers['Content-Length']));q=urllib.request.Request('http://127.0.0.1:18593'+self.path,data=raw,headers={'Content-Type':'application/json','X-VAAK-Library-Token':self.headers.get('X-VAAK-Library-Token','')})
  try:v=urllib.request.urlopen(q,timeout=30)
  except urllib.error.HTTPError as err:v=err
  body=v.read();trace.append({'request':json.loads(raw),'status':v.status,'response':body.decode(),'elapsed_seconds':time.monotonic()-started});self.send_response(v.status);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(body)
 def log_message(self,*a):pass
servers=[];worker=None;proxy=None;checks=[];captures=[]
def check(v,label):
 checks.append({'ok':bool(v),'check':label})
 if not v:raise AssertionError(label)
def req(c,port,path,data=None):
 q=urllib.request.Request('http://127.0.0.1:'+str(port)+path,data=urllib.parse.urlencode(data).encode() if data is not None else None)
 try:v=c.open(q,timeout=30)
 except urllib.error.HTTPError as err:v=err
 h=v.read().decode();captures.append({'path':path,'port':port,'status':v.status,'body':h});return h
try:
 if opt.worker:
  proxy=ThreadingHTTPServer(('127.0.0.1',18594),Proxy);threading.Thread(target=proxy.serve_forever,daemon=True).start()
  we=dict(e,VAAK_DATABASE_URL='host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=-csearch_path='+schema,VAAK_ENV_FILE=str(O/'fixture.env'),VAAK_PHYRIAN_BRIDGE_ENV_FILE=str(O/'absent.env'),VAAK_LIBRARY_NATIVE_RENDER='1',VAAK_LIBRARY_NATIVE_SECRET='canonical-local-fixture-secret-00001',VAAK_API_ROOT=str(A/'api'),VAAK_PHP_BIN=str(R/'api/bin/php-policy-test'))
  worker=subprocess.Popen([opt.worker,'serve','--bind','127.0.0.1:18593'],env=we,stdout=open(O/'worker.log','w'),stderr=subprocess.STDOUT)
 for port,native in [(18591,False)]+([(18592,True)] if opt.worker else []):
  pe=dict(e,VAAK_SHADOW_HTTP='http://127.0.0.1:18596',VAAK_LIBRARY_NATIVE_RENDER='1' if native else '0',VAAK_LIBRARY_NATIVE_SECRET='canonical-local-fixture-secret-00001',VAAK_LIBRARY_NATIVE_URL='http://127.0.0.1:18594/internal/library/render')
  servers.append(subprocess.Popen(cmd+['-d','opcache.enable=0','-d','disable_functions=exec,shell_exec','-d','allow_url_fopen=0','-S','127.0.0.1:'+str(port),str(A/'router.php')],env=pe,stdout=open(O/('sapi-'+str(port)+'.log'),'w'),stderr=subprocess.STDOUT))
 c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 for i in range(30):
  try:h=req(c,18591,'/vaak/?mode=login');break
  except OSError:time.sleep(.1)
 token=re.search('name="csrf" value="([^"]+)"',h)[1];req(c,18591,'/vaak/?mode=login',dict(action='login',csrf=token,login='cmdr_nova',password='fixture-admin-password'))
 def compare(label,offset=0,limit=10,expected_native=True):
  path='/vaak/?ajax=bookmarks_fedi&offset='+str(offset)+'&limit='+str(limit)
  php=req(c,18591,path);counter=json.loads((O/'counters.jsonl').read_text().splitlines()[-1])['counters'];check(counter.get('ap_masto_interaction_row_to_status',0)>0,'actual PHP mapper baseline '+label)
  if not opt.worker:return
  if os.environ.get('CANONICAL_DIRECT_DIAGNOSTIC')=='1':
   rows=json.loads(sql("echo json_encode($d->query('SELECT id FROM masto_bookmarks ORDER BY created_at DESC,id DESC OFFSET "+str(offset)+" LIMIT "+str(limit)+"')->fetchAll());"))
   body={'protocol_version':1,'canonical_saved_only':True,'status_preview_budget':3,'owner_id':1,'csrf':token,'return_view':'bookmarks','bsky_enabled':False,'lazy_media':False,'saved_sources':[dict(source_index=i,selected_ordinal=i,producer_kind='bookmarks_fedi',selected_kind='fedi',reference=str(row['id']),folder_id=0,offset=offset,limit=limit) for i,row in enumerate(rows)]}
   started=time.monotonic();q=urllib.request.Request('http://127.0.0.1:18593/internal/library/render',data=json.dumps(body).encode(),headers={'Content-Type':'application/json','X-VAAK-Library-Token':'canonical-local-fixture-secret-00001'})
   try:v=urllib.request.urlopen(q,timeout=30)
   except urllib.error.HTTPError as err:v=err
   trace.append({'kind':'direct-native','status':v.status,'response':v.read().decode(),'elapsed_seconds':time.monotonic()-started,'request':body});check(v.status==200,'direct private serving200 '+label)
   if os.environ.get('CANONICAL_DIRECT_ONLY')=='1':return
  start=len(trace);native=req(c,18592,path);counts=json.loads((O/'counters.jsonl').read_text().splitlines()[-1])['counters'] or {}
  # Time labels can differ; compare genuine tokenized HTML with existing shared actual parser.
  from importlib.util import spec_from_file_location,module_from_spec
  sp=spec_from_file_location('tokens',R/'api/bin/search-native-acceptance-review.py');m=module_from_spec(sp);sp.loader.exec_module(m)
  check(m.Tokens(php).tokens==m.Tokens(native).tokens,'actual full caller card/action/cursor parity '+label)
  calls=trace[start:];check(bool(calls),'actual native request '+label)
  if expected_native:
   check(calls[0]['status']==200 and calls[0]['request'].get('canonical_saved_only') is True,'descriptor-only canonical HTTP200 '+label)
   check(counts.get('ap_masto_interaction_row_to_status',0)==0 and counts.get('admin_library_status_enrich',0)==0,'zero actual PHP interaction/enrichment calls '+label)
  else:check(counts.get('ap_masto_interaction_row_to_status',0)>0,'unsupported full PHP fallback '+label)
 if os.environ.get('CANONICAL_BOUNDARY_ONLY')=='1':
  rows=json.loads(sql("echo json_encode($d->query('SELECT id FROM masto_bookmarks ORDER BY created_at DESC,id DESC LIMIT 10')->fetchAll());"))
  original=dict(protocol_version=1,canonical_saved_only=True,status_preview_budget=3,owner_id=1,csrf=token,return_view='bookmarks',bsky_enabled=False,lazy_media=False,saved_sources=[dict(source_index=i,selected_ordinal=i,producer_kind='bookmarks_fedi',selected_kind='fedi',reference=str(row['id']),folder_id=0,offset=0,limit=10) for i,row in enumerate(rows)])
  def boundary(label,body,status=(400,422)):
   q=urllib.request.Request('http://127.0.0.1:18593/internal/library/render',data=json.dumps(body).encode(),headers={'Content-Type':'application/json','X-VAAK-Library-Token':'canonical-local-fixture-secret-00001'})
   started=time.monotonic()
   try:v=urllib.request.urlopen(q,timeout=30)
   except urllib.error.HTTPError as err:v=err
   trace.append({'kind':'boundary','case':label,'request':body,'status':v.status,'response':v.read().decode(),'elapsed_seconds':time.monotonic()-started});check(v.status in status,'whole batch denies '+label)
  body=json.loads(json.dumps(original));body['saved_sources']=[];boundary('empty descriptor vector',body,(400,))
  body=json.loads(json.dumps(original));body['saved_sources'].pop();boundary('omitted selected raw row',body,(422,))
  body=json.loads(json.dumps(original));body['saved_sources'][1]['selected_ordinal']=2;boundary('noncontiguous raw ordinal',body,(400,))
  body=json.loads(json.dumps(original));body['owner_id']=2;boundary('foreign owner saved references',body,(422,))
  body=json.loads(json.dumps(original));body['saved_sources'][0]['grant']=True;boundary('deserialized authority field',body,(400,))
  sql("$d->exec(\"UPDATE masto_statuses SET visibility='private' WHERE local_id=2\");$d->exec(\"UPDATE outbox_notes SET visibility='private',to_json='[]',cc_json='[]' WHERE id LIKE '%/canonical2'\");")
  boundary('current peer private withdrawal',original,(422,));h=req(c,18591,'/vaak/?ajax=bookmarks_fedi&offset=0&limit=10');check('data-thread-note=\"https://mkultra.monster/users/librarysecond/notes/canonical2' not in h and '?view=status&amp;object=https%3A%2F%2Fmkultra.monster%2Fusers%2Flibrarysecond%2Fnotes%2Fcanonical2' not in h,'fresh canonical PHP private withdrawal cannot revive card')
  sql("$d->exec(\"UPDATE masto_statuses SET visibility='public' WHERE local_id=2\");$d->exec(\"UPDATE outbox_notes SET visibility='public',to_json='[\\\"https://www.w3.org/ns/activitystreams#Public\\\"]' WHERE id LIKE '%/canonical2'\");$d->exec(\"UPDATE ap_users SET disabled_at='2026-10-11' WHERE id=1\");")
  boundary('disabled current owner',original,(422,));sql("$d->exec('UPDATE ap_users SET disabled_at=NULL WHERE id=1');")
  sql("$d->exec('DELETE FROM masto_bookmarks WHERE id='.(string)"+str(rows[0]['id'])+");")
  boundary('removed saved row shifts whole current window',original,(422,))
  # Real duplicate logical URLs: PHP resolves the exact saved local SID first.
  sql("$note='https://mkultra.monster/users/cmdr_nova/notes/canonical1/';$when='2026-10-11T12:00:00+00:00';$d->prepare('INSERT INTO masto_statuses(local_id,note_id,create_id,published,content_text,visibility,spoiler_text,sensitive) VALUES(?,?,?,?,?,?,?,?)')->execute([99,$note,$note.'activity',$when,'Exact saved alias ninety nine','public','',0]);$raw=json_encode(['id'=>$note.'activity','type'=>'Create','object'=>['id'=>$note,'type'=>'Note','attributedTo'=>'https://mkultra.monster/users/cmdr_nova','content'=>'<p>Exact saved alias ninety nine</p>','to'=>['https://www.w3.org/ns/activitystreams#Public']]]);$d->prepare('INSERT INTO outbox_notes(id,create_id,published,content,to_json,cc_json,raw_create_json,kind,visibility) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$note,$note.'activity',$when,'<p>Exact saved alias ninety nine</p>','[\"https://www.w3.org/ns/activitystreams#Public\"]','[]',$raw,'compose','public']);$d->prepare('INSERT INTO masto_bookmarks(owner_user_id,status_id,object_id,created_at) VALUES(?,?,?,?)')->execute([1,ap_masto_snowflake_id($when,99,0),$note,'2026-10-11T12:02:00+00:00']);")
  rows=json.loads(sql("echo json_encode($d->query('SELECT id FROM masto_bookmarks ORDER BY created_at DESC,id DESC LIMIT 10')->fetchAll());"))
  original['saved_sources']=[dict(source_index=i,selected_ordinal=i,producer_kind='bookmarks_fedi',selected_kind='fedi',reference=str(row['id']),folder_id=0,offset=0,limit=10) for i,row in enumerate(rows)]
  if os.environ.get('CANONICAL_ALIAS_BEFORE')=='1':
   boundary('retained alias-before native wrong-source control',original,(200,));check('1791720000000000001' in trace[-1]['response'],'retained native before uses wrong resolved canonical SID')
  else:boundary('duplicate slash note cannot override exact saved SID',original,(422,))
  mapper=json.loads(sql("echo json_encode(ap_masto_interaction_row_to_status($d->query('SELECT * FROM masto_bookmarks ORDER BY created_at DESC,id DESC LIMIT 1')->fetch(),'bookmarked'));"));check(mapper['id']=='1791720000000000099' and 'Exact saved alias ninety nine' in mapper['content'],'genuine PHP mapper retains exact saved SID resolver precedence')
  php=req(c,18591,'/vaak/?ajax=bookmarks_fedi&offset=0&limit=10');check(re.search('name=\"status_id\" value=\"([^\"]+)\"',php)[1]=='1791720000000000099','actual PHP painted action preserves resolved alias SID')
  native=req(c,18592,'/vaak/?ajax=bookmarks_fedi&offset=0&limit=10')
  if os.environ.get('CANONICAL_ALIAS_BEFORE')!='1':
   from importlib.util import spec_from_file_location,module_from_spec
   sp=spec_from_file_location('tokens',R/'api/bin/search-native-acceptance-review.py');m=module_from_spec(sp);sp.loader.exec_module(m)
   check(m.Tokens(php).tokens==m.Tokens(native).tokens,'ambiguous complete PHP fallback preserves action/body/raw cursor')

 else:
  compare('plain local and snowflake same page')
  if os.environ.get('CANONICAL_DIRECT_ONLY')!='1':compare('next raw page',10)
  if os.environ.get('CANONICAL_DIRECT_ONLY')!='1':compare('maximum current page',0,40,expected_native=False) if os.environ.get('CANONICAL_MAX_FALLBACK')=='1' else compare('maximum current page',0,40)
  sql("$d->exec(\"UPDATE masto_statuses SET spoiler_text='A warning',sensitive=1 WHERE local_id=1\");")
  if os.environ.get('CANONICAL_DIRECT_ONLY')!='1':compare('sensitive CW current')
  sql("$d->exec(\"UPDATE masto_statuses SET content_text='Mention @unknown' WHERE local_id=1\");")
  if os.environ.get('CANONICAL_DIRECT_ONLY')!='1':compare('unsupported mention preserves whole page',expected_native=False)

finally:
 (O/'checks.json').write_text(json.dumps(checks,indent=2));(O/'captures.json').write_text(json.dumps(captures,indent=2));(O/'native-trace.json').write_text(json.dumps(trace,indent=2))
 for p in servers:p.terminate();p.wait(timeout=5)
 if worker:worker.terminate();worker.wait(timeout=5)
 if proxy:proxy.shutdown();proxy.server_close()
print(json.dumps({'checks':len(checks),'passed':all(x['ok'] for x in checks),'width':suffix}))
