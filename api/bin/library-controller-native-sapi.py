#!/usr/bin/env python3
"""Actual full controller actions followed by PHP/native-preferred fragments.
All application handlers/hydration execute. Before-wire deny boundary is explicit.
"""
import pathlib,subprocess,os,json,re,urllib.request,urllib.parse,http.cookiejar,time,threading,hashlib
from http.server import ThreadingHTTPServer,BaseHTTPRequestHandler
R=pathlib.Path(__file__).resolve().parents[2];O=R/'rust/target/parity-evidence/library-controller-native-phase';A=O/'app'
# Actual earlier whole-controller gate seeds real logins/schema with isolated paths.
setup=(R/'api/bin/library-settings-whole-flow.py').read_text().replace('library-settings-whole-flow','library-controller-native-phase').replace('library_settings_whole_flow','library_controller_native_phase')
(O/'setup-controller.py').write_text(setup)
# __file__ must retain actual repo parent calculation, not evidence directory.
exec(compile(setup,str(R/'api/bin/library-settings-whole-flow.py'),'exec'),{'__file__':str(R/'api/bin/library-settings-whole-flow.py'),'__name__':'__setup__'})
e=os.environ.copy();e.update(json.loads((O/'env.json').read_text()));e.update(AP_DB_DSN='pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=--search_path=library_controller_native_phase',VAAK_SUPPORTER_CODES='0',VAAK_REGISTRATION_APPROVALS='0',VAAK_LIBRARY_NATIVE_DATA='0',LD_PRELOAD=str(O/'loopback-only.so'),VAAK_FIXTURE_NET_DENIAL_LOG=str(O/'external-before-wire-denials.log'))
cmd=[str(R/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so']
# Fixture facts, not manufactured normalized statuses. Genuine event mapper is authoritative.
(A/'seed-native.php').write_text("""<?php require __DIR__.'/api/ap-auth.php';ap_auth_bootstrap();$d=ap_db();$d->exec('DELETE FROM masto_bookmarks');$when=gmdate('c',time()-300);$d->prepare('INSERT INTO remote_post_media_cache(source_url,s3_key,public_url,content_type,fetched_at,last_used_at) VALUES(?,?,?,?,?,?)')->execute(['https://media.savedcontroller.invalid/image.webp','fixture/image.webp','https://cache.savedcontroller.invalid/image.webp','image/webp',$when,$when]);$actor='https://savedcontroller.invalid/users/alice';$note='https://savedcontroller.invalid/users/alice/statuses/123';$d->prepare('INSERT INTO events(id,created_at,type,actor_id,object_id,host,action_taken,summary,media_urls,spoiler_text,sensitive,visibility) VALUES(100,?,?,?,?,?,?,?,?,?,?,?)')->execute([$when,'Create',$actor,$note,'savedcontroller.invalid','log','Real controller saved post','["https://media.savedcontroller.invalid/image.webp"]','',1,'public']);$d->prepare('INSERT INTO remote_actors(actor_id,username,display_name,host,updated_at,profile_json) VALUES(?,?,?,?,?,?)')->execute([$actor,'alice','Alice','savedcontroller.invalid',gmdate('c'),json_encode(['id'=>$actor,'type'=>'Person','preferredUsername'=>'alice','name'=>'Alice'])]);echo json_encode(['note'=>$note,'actor'=>$actor,'status'=>ap_masto_event_status_id(100)]);""")
# Event public-ID helper is in actual entities module.
s=(A/'seed-native.php').read_text().replace("ap_auth_bootstrap();$d=ap_db();","ap_auth_bootstrap();require __DIR__.'/api/ap-masto-entities.php';$d=ap_db();");(A/'seed-native.php').write_text(s)
seed=json.loads(subprocess.check_output(cmd+[str(A/'seed-native.php')],env=e))
trace=[];data_trace=[]
class Proxy(BaseHTTPRequestHandler):
 def do_GET(self):
  try:v=urllib.request.urlopen('http://127.0.0.1:18483'+self.path,timeout=10)
  except urllib.error.HTTPError as err:v=err
  body=v.read();data_trace.append({'path':self.path,'status':v.status,'response':body.decode()});self.send_response(v.status);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(body)
 def do_POST(self):
  raw=self.rfile.read(int(self.headers['Content-Length']));q=urllib.request.Request('http://127.0.0.1:18483'+self.path,data=raw,headers={'Content-Type':'application/json','X-VAAK-Library-Token':self.headers.get('X-VAAK-Library-Token','')})
  try:v=urllib.request.urlopen(q,timeout=10)
  except urllib.error.HTTPError as err:v=err
  body=v.read();trace.append({'request':json.loads(raw),'status':v.status,'headers':dict(v.headers),'response':body.decode()});self.send_response(v.status);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(body)
 def log_message(self,*a):pass
proxy=ThreadingHTTPServer(('127.0.0.1',18484),Proxy);threading.Thread(target=proxy.serve_forever,daemon=True).start()
we=dict(e,VAAK_DATABASE_URL='host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=-csearch_path=library_controller_native_phase',VAAK_ENV_FILE=str(O/'fixture.env'),VAAK_PHYRIAN_BRIDGE_ENV_FILE=str(O/'absent-bridge.env'),VAAK_LIBRARY_NATIVE_RENDER='1',VAAK_LIBRARY_NATIVE_SECRET='library-controller-native-secret-00001',VAAK_API_ROOT=str(A/'api'),VAAK_PHP_BIN=str(R/'api/bin/php-policy-test'))
worker=subprocess.Popen([str(O/'immutable-repaired-serving-worker'),'serve','--bind','127.0.0.1:18483'],env=we,stdout=open(O/'native-worker.log','w'),stderr=subprocess.STDOUT)
servers=[];checks=[];captures=[]
def check(v,m):
 checks.append({'ok':bool(v),'check':m})
 if not v:raise AssertionError(m)
def request(c,port,path,data=None):
 q=urllib.request.Request('http://127.0.0.1:'+str(port)+path,data=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None)
 try:x=c.open(q,timeout=30)
 except urllib.error.HTTPError as err:x=err
 h=x.read().decode();captures.append({'port':port,'path':path,'post':data,'status':x.status,'headers':dict(x.headers),'body':h});return h
try:
 for port,native in [(18481,False),(18485,True)]:
  pe=dict(e,VAAK_SHADOW_HTTP='http://127.0.0.1:18484' if native else 'http://127.0.0.1:18486',VAAK_LIBRARY_NATIVE_RENDER='1' if native else '0',VAAK_LIBRARY_NATIVE_SECRET='library-controller-native-secret-00001',VAAK_LIBRARY_NATIVE_URL='http://127.0.0.1:18484/internal/library/render')
  servers.append(subprocess.Popen(cmd+['-d','opcache.enable=0','-d','disable_functions=exec,shell_exec','-d','allow_url_fopen=0','-S','127.0.0.1:'+str(port),str(A/'router.php')],env=pe,stdout=open(O/('sapi-'+str(port)+'.log'),'w'),stderr=subprocess.STDOUT))
 c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 for i in range(30):
  try:h=request(c,18481,'/vaak/?mode=login');urllib.request.urlopen('http://127.0.0.1:18483/healthz');break
  except OSError:time.sleep(.1)
 token=re.search('name="csrf" value="([^"]+)"',h)[1]
 h=request(c,18481,'/vaak/?mode=login',dict(action='login',csrf=token,login='cmdr_nova',password='fixture-admin-password'));h=request(c,18481,'/vaak/?view=profile');token=re.search('name="csrf" value="([^"]+)"',h)[1]
 def action(action_name,**kw):return request(c,18481,'/vaak/?view=profile',dict(action=action_name,csrf=token,return_view='profile',**kw))
 action('bookmark_status',status_id=seed['status'],object_id=seed['note'],actor_id=seed['actor'],ajax='1')
 from importlib.util import spec_from_file_location,module_from_spec
 spec=spec_from_file_location('tokens',R/'api/bin/search-native-acceptance-review.py');m=module_from_spec(spec);spec.loader.exec_module(m)
 def compare(label,folder=0,offset=0):
  path='/vaak/?ajax=bookmarks_fedi&offset='+str(offset)+'&limit=20&folder='+str(folder)
  before=len(trace);data_before=len(data_trace);php=request(c,18481,path);native=request(c,18485,path)
  left,right=m.Tokens(php).tokens,m.Tokens(native).tokens
  check(left==right,'complete actual fragment HTML parity '+label)
  check(len(data_trace)>data_before and data_trace[-1]['status']==200,'actual serving Rust SQL collector HTTP200 '+label)
  if re.search('<article',php):check(len(trace)>before and all(v['status']==200 for v in trace[before:]),'real native private HTTP200 reached '+label)
  else:check(not re.search('<article',native),'actual empty PHP/native-preferred collector '+label)
  captures.append({'cohort':label,'native_trace_start':before,'native_trace_end':len(trace),'cards':len(re.findall('<article',native))})
 compare('initial save')
 for value in [True,False]:
  fields=dict(name='Library operator',summary='Isolated Library fixture biography',email='',reply_policy='anyone',quote_policy='anyone',downranking_enabled='1')
  if value:fields['auto_unblur_sensitive']='1'
  action('save_profile',**fields);compare('actual sensitive Settings '+str(value))
 r=json.loads(action('bookmark_folder_create',title='Native folder',ajax='1'));fid=r['id'];action('bookmark_folder_add',folder_id=fid,status_id=seed['status'],object_id=seed['note'],ajax='1');compare('actual folder add',fid)

 def sql(code):
  script=A/'control-native.php';script.write_text("<?php require __DIR__.'/api/ap-auth.php';ap_auth_bootstrap();require __DIR__.'/api/ap-bookmark-folders.php';$d=ap_db();"+code)
  return subprocess.check_output(cmd+[str(script)],env=e).decode()
 canonical=json.loads(sql("echo json_encode($d->query('SELECT status_id FROM masto_bookmarks ORDER BY id DESC LIMIT 1')->fetchColumn());"))
 # Restore raw legacy memberships that predate the canonical-write repair; reads must retain them.
 sql("$d->exec('DELETE FROM vaak_bookmark_folder_items');$d->prepare('INSERT INTO vaak_bookmark_folder_items(folder_id,owner_user_id,status_id,added_at) VALUES(?,?,?,?)')->execute(["+str(fid)+",1,'"+seed['status']+"',gmdate('c')]);")
 compare('historical legacy membership',fid)
 sql("$d->prepare('INSERT INTO vaak_bookmark_folder_items(folder_id,owner_user_id,status_id,added_at) VALUES(?,?,?,?)')->execute(["+str(fid)+",1,'"+canonical+"',gmdate('c')]);$d->prepare('INSERT INTO vaak_bookmark_folder_items(folder_id,owner_user_id,status_id,added_at) VALUES(?,?,?,?)')->execute(["+str(fid)+",1,'"+seed['note']+"/',gmdate('c')]);")
 compare('historical duplicate membership aliases',fid)
 counts=json.loads(sql("echo json_encode(['members'=>$d->query('SELECT COUNT(*) FROM vaak_bookmark_folder_items')->fetchColumn(),'folders'=>vaak_bookmark_folders_list(1)]);"))
 check(int(counts['members'])==3 and int(counts['folders'][0]['item_count'])==1,'historical alias memberships retained, one owned saved-row count')
 check(captures[-1].get('cards')==1,'historical aliases paint one card')
 compare('historical aliases preserve raw offset one',fid,1)
 check(captures[-1]['cards']==0,'alias memberships do not advance saved-row cursor twice')
 action('bookmark_folder_remove',folder_id=fid,status_id=seed['status'],ajax='1');compare('actual folder remove',fid);compare('flat save retained')
 check(int(sql("echo $d->query('SELECT COUNT(*) FROM vaak_bookmark_folder_items')->fetchColumn();"))==0,'legacy removal clears all historical aliases')
 for alias in [canonical,seed['note'],seed['note']+'/']:
  action('bookmark_folder_add',folder_id=fid,status_id=alias,object_id=seed['note'],ajax='1');compare('accepted alias add '+alias,fid)
  action('bookmark_folder_remove',folder_id=fid,status_id=alias,ajax='1');compare('accepted alias remove '+alias,fid)
  check(int(sql("echo $d->query('SELECT COUNT(*) FROM vaak_bookmark_folder_items')->fetchColumn();"))==0,'accepted alias removal durable '+alias)
 action('bookmark_folder_add',folder_id=fid,status_id=seed['status'],object_id=seed['note'],ajax='1')
 # Existing foreign-owner metadata cannot contribute to this owner's folder/count.
 sql("$d->prepare('INSERT INTO vaak_bookmark_folder_items(folder_id,owner_user_id,status_id,added_at) VALUES(?,?,?,?)')->execute(["+str(fid)+",2,'foreign-owner-save',gmdate('c')]);")
 compare('foreign-owner membership excluded',fid)
 check(int(json.loads(sql("echo json_encode(vaak_bookmark_folders_list(1));"))[0]['item_count'])==1,'foreign-owner membership excluded from count')
 # Current audience/deletion state remains authoritative after historical association.
 sql("$d->exec(\"UPDATE events SET visibility='direct' WHERE id=100\");")
 compare('historical saved source now direct',fid)
 check(captures[-1]['cards']==0,'historical association cannot grant direct audience')
 sql("$d->exec(\"UPDATE events SET visibility='public',action_taken='deleted' WHERE id=100\");")
 compare('historical saved source deleted',fid)
 check(captures[-1]['cards']==0,'historical association cannot resurrect deleted source')
 sql("$d->exec(\"UPDATE events SET visibility='public',action_taken='log' WHERE id=100\");")
 compare('restored current public source',fid)
 action('deprioritize_remote',actor_id=seed['actor']);compare('actual Home deprioritize preserved Library')
 action('undeprioritize_remote',actor_id=seed['actor']);compare('actual Home priority restored')

 action('subscribe_posts',actor_id=seed['actor']);compare('post subscription on');action('unsubscribe_posts',actor_id=seed['actor']);compare('post subscription off')
 action('mute_remote',actor_id=seed['actor']+'/');compare('personal trailing-slash mute');action('unmute_remote',actor_id=seed['actor']+'/');compare('personal unmute')
 action('user_block_add',target='savedcontroller.invalid');compare('personal domain block')
 (O/'native-final-sql-state.json').write_text(subprocess.check_output(cmd+[str(A/'state.php')],env=e).decode())

finally:
 (O/'native-controller-checks.json').write_text(json.dumps(checks,indent=2));(O/'native-controller-captures.json').write_text(json.dumps(captures,indent=2));(O/'native-http-trace.json').write_text(json.dumps(trace,indent=2));(O/'native-data-trace.json').write_text(json.dumps(data_trace,indent=2))
 for s in servers:s.terminate();s.wait(timeout=5)
 worker.terminate();worker.wait(timeout=5);proxy.shutdown();proxy.server_close()
print(json.dumps({'checks':len(checks),'passed':all(x['ok'] for x in checks),'native_statuses':[v['status'] for v in trace]}))
