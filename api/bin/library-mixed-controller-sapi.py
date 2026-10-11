#!/usr/bin/env python3
"""Actual full controller actions followed by PHP/native-preferred fragments.
All application handlers/hydration execute. Before-wire deny boundary is explicit.
"""
import pathlib,subprocess,os,json,re,urllib.request,urllib.parse,http.cookiejar,time,threading,hashlib
from http.server import ThreadingHTTPServer,BaseHTTPRequestHandler
R=pathlib.Path(__file__).resolve().parents[2];O=R/'rust/target/parity-evidence/library-mixed-controller-phase';A=O/'app'
O.mkdir(parents=True,exist_ok=True)
# Actual earlier whole-controller gate seeds real logins/schema with isolated paths.
setup=(R/'api/bin/library-settings-whole-flow.py').read_text().split("log=(O/'server.log')")[0].replace('library-settings-whole-flow','library-mixed-controller-phase').replace('library_settings_whole_flow','library_mixed_controller_phase')
(O/'setup-controller.py').write_text(setup)
# __file__ must retain actual repo parent calculation, not evidence directory.
exec(compile(setup,str(R/'api/bin/library-settings-whole-flow.py'),'exec'),{'__file__':str(R/'api/bin/library-settings-whole-flow.py'),'__name__':'__setup__'})
e=os.environ.copy();e.update(json.loads((O/'env.json').read_text()));e.update(AP_DB_DSN='pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=--search_path=library_mixed_controller_phase',VAAK_SUPPORTER_CODES='0',VAAK_REGISTRATION_APPROVALS='0',VAAK_LIBRARY_NATIVE_DATA='0',LD_PRELOAD=str(R/'rust/target/parity-evidence/library-controller-native-phase/loopback-only.so'),VAAK_FIXTURE_NET_DENIAL_LOG=str(O/'external-before-wire-denials.log'))
cmd=[str(R/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so']
# Fixture facts, not manufactured normalized statuses. Genuine event mapper is authoritative.
(A/'seed-native.php').write_text("""<?php require __DIR__.'/api/ap-auth.php';ap_auth_bootstrap();$d=ap_db();$d->exec('DELETE FROM masto_bookmarks');$when=gmdate('c',time()-300);$d->prepare('INSERT INTO remote_post_media_cache(source_url,s3_key,public_url,content_type,fetched_at,last_used_at) VALUES(?,?,?,?,?,?)')->execute(['https://media.savedcontroller.invalid/image.webp','fixture/image.webp','https://cache.savedcontroller.invalid/image.webp','image/webp',$when,$when]);$actor='https://savedcontroller.invalid/users/alice';$note='https://savedcontroller.invalid/users/alice/statuses/123';$d->prepare('INSERT INTO events(id,created_at,type,actor_id,object_id,host,action_taken,summary,media_urls,spoiler_text,sensitive,visibility) VALUES(100,?,?,?,?,?,?,?,?,?,?,?)')->execute([$when,'Create',$actor,$note,'savedcontroller.invalid','log','Real controller saved post','["https://media.savedcontroller.invalid/image.webp"]','',1,'public']);$d->prepare('INSERT INTO remote_actors(actor_id,username,display_name,host,updated_at,profile_json) VALUES(?,?,?,?,?,?)')->execute([$actor,'alice','Alice','savedcontroller.invalid',gmdate('c'),json_encode(['id'=>$actor,'type'=>'Person','preferredUsername'=>'alice','name'=>'Alice'])]);echo json_encode(['note'=>$note,'actor'=>$actor,'status'=>ap_masto_event_status_id(100)]);""")
# Event public-ID helper is in actual entities module.
s=(A/'seed-native.php').read_text().replace("ap_auth_bootstrap();$d=ap_db();","ap_auth_bootstrap();require __DIR__.'/api/ap-masto-entities.php';$d=ap_db();");(A/'seed-native.php').write_text(s)
seed=json.loads(subprocess.check_output(cmd+[str(A/'seed-native.php')],env=e))
trace=[];data_trace=[]
class Proxy(BaseHTTPRequestHandler):
 def do_GET(self):
  try:v=urllib.request.urlopen('http://127.0.0.1:18493'+self.path,timeout=10)
  except urllib.error.HTTPError as err:v=err
  body=v.read();data_trace.append({'path':self.path,'status':v.status,'response':body.decode()});self.send_response(v.status);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(body)
 def do_POST(self):
  raw=self.rfile.read(int(self.headers['Content-Length']));q=urllib.request.Request('http://127.0.0.1:18493'+self.path,data=raw,headers={'Content-Type':'application/json','X-VAAK-Library-Token':self.headers.get('X-VAAK-Library-Token','')})
  try:v=urllib.request.urlopen(q,timeout=10)
  except urllib.error.HTTPError as err:v=err
  body=v.read();trace.append({'request':json.loads(raw),'status':v.status,'headers':dict(v.headers),'response':body.decode()});self.send_response(v.status);self.send_header('Content-Type','application/json');self.end_headers();self.wfile.write(body)
 def log_message(self,*a):pass
proxy=ThreadingHTTPServer(('127.0.0.1',18494),Proxy);threading.Thread(target=proxy.serve_forever,daemon=True).start()
we=dict(e,VAAK_DATABASE_URL='host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=-csearch_path=library_mixed_controller_phase',VAAK_ENV_FILE=str(O/'fixture.env'),VAAK_PHYRIAN_BRIDGE_ENV_FILE=str(O/'absent-bridge.env'),VAAK_LIBRARY_NATIVE_RENDER='1',VAAK_LIBRARY_NATIVE_SECRET='library-controller-native-secret-00001',VAAK_API_ROOT=str(A/'api'),VAAK_PHP_BIN=str(R/'api/bin/php-policy-test'))
worker=subprocess.Popen([str(R/'rust/target/parity-evidence/library-canonical-local/serving-sqlonly'),'serve','--bind','127.0.0.1:18493'],env=we,stdout=open(O/'native-worker.log','w'),stderr=subprocess.STDOUT)
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
 for port,native in [(18491,False),(18495,True)]:
  pe=dict(e,VAAK_SHADOW_HTTP='http://127.0.0.1:18494' if native else 'http://127.0.0.1:18496',VAAK_LIBRARY_NATIVE_RENDER='1' if native else '0',VAAK_LIBRARY_NATIVE_SECRET='library-controller-native-secret-00001',VAAK_LIBRARY_NATIVE_URL='http://127.0.0.1:18494/internal/library/render')
  servers.append(subprocess.Popen(cmd+['-d','opcache.enable=0','-d','disable_functions=exec,shell_exec','-d','allow_url_fopen=0','-S','127.0.0.1:'+str(port),str(A/'router.php')],env=pe,stdout=open(O/('sapi-'+str(port)+'.log'),'w'),stderr=subprocess.STDOUT))
 c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 for i in range(30):
  try:h=request(c,18491,'/vaak/?mode=login');urllib.request.urlopen('http://127.0.0.1:18493/healthz');break
  except OSError:time.sleep(.1)
 token=re.search('name="csrf" value="([^"]+)"',h)[1]
 h=request(c,18491,'/vaak/?mode=login',dict(action='login',csrf=token,login='cmdr_nova',password='fixture-admin-password'));h=request(c,18491,'/vaak/?view=profile');token=re.search('name="csrf" value="([^"]+)"',h)[1]
 def action(action_name,**kw):return request(c,18491,'/vaak/?view=profile',dict(action=action_name,csrf=token,return_view='profile',**kw))
 action('bookmark_status',status_id=seed['status'],object_id=seed['note'],actor_id=seed['actor'],ajax='1')
 from importlib.util import spec_from_file_location,module_from_spec
 spec=spec_from_file_location('tokens',R/'api/bin/search-native-acceptance-review.py');m=module_from_spec(spec);spec.loader.exec_module(m)
 def compare(label,folder=0,offset=0):
  path='/vaak/?ajax=bookmarks_page&offset='+str(offset)+'&limit=10&folder='+str(folder)
  before=len(trace);data_before=len(data_trace);php=request(c,18491,path);native=request(c,18495,path)
  left,right=m.Tokens(php).tokens,m.Tokens(native).tokens
  check(left==right,'complete actual fragment HTML parity '+label)
  if re.search('<article',php):check(len(trace)>before and all(v['status']==200 and 'no-store' in v['headers'].get('cache-control','') for v in trace[before:]),'real native private no-store HTTP200 reached '+label)
  else:check(not re.search('<article',native),'actual empty PHP/native-preferred collector '+label)
  captures.append({'cohort':label,'native_trace_start':before,'native_trace_end':len(trace),'cards':len(re.findall('<article',native))})


 def sql(code):
  script=A/'control-mixed.php';script.write_text("<?php require __DIR__.'/api/ap-auth.php';ap_auth_bootstrap();require __DIR__.'/api/ap-bookmark-folders.php';require __DIR__.'/api/ap-bsky.php';$d=ap_db();"+code)
  return subprocess.check_output(cmd+[str(script)],env=e).decode()
 sql("$d->exec(file_get_contents('"+str(R/'api/migrations/20260915_bsky_bookmark_cache_postgres.sql')+"'));ap_bsky_graph_sync_migrate();ap_bsky_crossposts_migrate();ap_bsky_post_links_migrate();ap_bsky_posts_migrate();ap_bsky_favourite_cache_migrate();")
 r=json.loads(action('bookmark_folder_create',title='Mixed historical folder',ajax='1'));fid=r['id'];action('bookmark_folder_add',folder_id=fid,status_id=seed['status'],object_id=seed['note'],ajax='1')
 compare('repaired canonical mixed folder',fid)
 check(captures[-1]['cards']==1,'repaired canonical folder paints genuine saved source')
 sql("$d->exec('DELETE FROM vaak_bookmark_folder_items');$d->prepare('INSERT INTO vaak_bookmark_folder_items(folder_id,owner_user_id,status_id,added_at) VALUES(?,?,?,?)')->execute(["+str(fid)+",1,'"+seed['status']+"',gmdate('c')]);")
 compare('historical mixed folder',fid)
 did='did:plc:mixedlibraryfixture00001';uri='at://'+did+'/app.bsky.feed.post/cached';url='https://bsky.app/profile/'+did+'/post/cached'
 post={'post':{'uri':uri,'cid':'mixed-fixture-cid','author':{'did':did,'handle':'mixed-library.invalid','displayName':'Mixed Library','avatar':'https://cache.savedcontroller.invalid/mixed-avatar.webp'},'record':{'$type':'app.bsky.feed.post','text':'Genuine cached Bluesky Library source','createdAt':'2026-10-10T12:00:00Z'},'indexedAt':'2026-10-10T12:00:00Z','viewer':{}}}
 payload=json.dumps(post)
 sql("$d->prepare('INSERT INTO bsky_bookmark_cache(owner_user_id,bookmark_uri,bookmarked_at,post_json,updated_at) VALUES(?,?,?,?,?)')->execute([1,'"+uri+"','2026-10-10T12:00:00Z',base64_decode('"+__import__('base64').b64encode(payload.encode()).decode()+"'),gmdate('c')]);")
 action('bookmark_folder_add',folder_id=fid,status_id='bsky:mixed-cached',object_id=url,ajax='1')
 compare('historical fedi and DID HTTPS cached Bluesky',fid)
 check(captures[-1]['cards']==2,'both protocols painted in actual mixed folder')
 compare('both protocols all saved')
 # Owner cache facts, not normalized status replacements: malformed selected row
 # must consume its source ordinal without painting or stealing the next card.
 bad_uri='at://'+did+'/app.bsky.feed.post/z-malformed';bad_url='https://bsky.app/profile/'+did+'/post/z-malformed'
 sql("$d->prepare('INSERT INTO bsky_bookmark_cache(owner_user_id,bookmark_uri,bookmarked_at,post_json,updated_at) VALUES(?,?,?,?,?)')->execute([1,'"+bad_uri+"','2030-01-01T00:00:00Z','{malformed',gmdate('c')]);")
 action('bookmark_folder_add',folder_id=fid,status_id='bsky:mixed-malformed',object_id=bad_url,ajax='1')
 compare('malformed cache preserves source ordinal',fid)
 check(captures[-1]['cards']==2,'malformed selected cache omits only its card')
 last=captures[-2]['body'];check('data-offset="3"' in last,'raw mixed cursor includes omitted selected row')
 bindings=trace[-1]['request']['saved_sources'];check([v['selected_ordinal'] for v in bindings]==[1,2],'painted source indices preserve raw selected ordinals')
 sql("$d->prepare('INSERT INTO bsky_bookmark_cache(owner_user_id,bookmark_uri,bookmarked_at,post_json,updated_at) SELECT 2,bookmark_uri,?,post_json,updated_at FROM bsky_bookmark_cache WHERE owner_user_id=1 AND bookmark_uri=?')->execute(['2031-01-01T00:00:00Z','"+uri+"']);")
 compare('foreign cache cannot enter owned mixed window',fid)
 check(captures[-1]['cards']==2,'foreign cache leaves owned cards unchanged')
 sql("$d->prepare('DELETE FROM bsky_bookmark_cache WHERE owner_user_id=1 AND bookmark_uri=?')->execute(['"+bad_uri+"']);$d->prepare('DELETE FROM masto_bookmarks WHERE owner_user_id=1 AND object_id=?')->execute(['"+bad_url+"']);$d->prepare('DELETE FROM vaak_bookmark_folder_items WHERE owner_user_id=1 AND status_id=?')->execute(['bsky:mixed-malformed']);")

 compare('both protocols mixed offset one',fid,1)
 check(captures[-1]['cards']==1,'mixed raw offset advances one source')
 for enabled in [True,False]:
  fields=dict(name='Library operator',summary='Isolated mixed fixture',email='',reply_policy='anyone',quote_policy='anyone',downranking_enabled='1')
  if enabled:fields['auto_unblur_sensitive']='1'
  action('save_profile',**fields);compare('sensitive noCW Settings '+str(enabled),fid)
 def cache_post(value):
  raw=__import__('base64').b64encode(json.dumps(value).encode()).decode()
  sql("$d->prepare('UPDATE bsky_bookmark_cache SET post_json=? WHERE owner_user_id=1 AND bookmark_uri=?')->execute([base64_decode('"+raw+"'),'"+uri+"']);")
 post['post']['labels']=[{'val':'porn'}];cache_post(post);compare('current cached sensitive Bluesky label',fid)
 post['post']['labels']=[];post['post']['viewer']['muted']=True;cache_post(post);compare('stale cached mute flag uses current owner table',fid)
 check(captures[-1]['cards']==2,'stale cached mute does not replace current personal settings')
 post['post']['viewer']['blockedBy']=True;cache_post(post);compare('cached remote blockedBy enforced',fid)
 check(captures[-1]['cards']==1,'remote blockedBy removes its containing source')
 post['post']['viewer']={};cache_post(post);compare('restored current cached source',fid)
 action('mute_remote',actor_id=seed['actor']+'/');compare('current fedi trailing alias muted',fid)
 check(captures[-1]['cards']==1,'fedi mute leaves cached Bluesky visible')
 action('unmute_remote',actor_id=seed['actor']+'/');compare('current fedi unmuted',fid)
 action('mute_remote',actor_id='https://bsky.app/profile/'+did);compare('current DID muted',fid)
 action('unmute_remote',actor_id='https://bsky.app/profile/'+did);compare('current DID unmuted',fid)
 action('user_block_add',target='savedcontroller.invalid');compare('current fedi domain blocked',fid)
 (O/'mixed-final-sql-state.json').write_text(subprocess.check_output(cmd+[str(A/'state.php')],env=e).decode())

finally:
 (O/'native-controller-checks.json').write_text(json.dumps(checks,indent=2));(O/'native-controller-captures.json').write_text(json.dumps(captures,indent=2));(O/'native-http-trace.json').write_text(json.dumps(trace,indent=2));(O/'native-data-trace.json').write_text(json.dumps(data_trace,indent=2))
 for s in servers:s.terminate();s.wait(timeout=5)
 worker.terminate();worker.wait(timeout=5);proxy.shutdown();proxy.server_close()
print(json.dumps({'checks':len(checks),'passed':all(x['ok'] for x in checks),'native_statuses':[v['status'] for v in trace]}))
