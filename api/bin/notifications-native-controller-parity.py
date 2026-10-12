#!/usr/bin/env python3
"""Actual login/controller/PDO/shared Redis versus real native worker. Fixture only.
Dependencies are extracted into /tmp, never installed. Cache entities come from
actual PHP notification producer, not manufactured native HTTP replies.
"""
import argparse,hashlib,html,http.cookiejar,importlib.util,json,os,pathlib,re,subprocess,threading,time,socket,urllib.error,urllib.parse,urllib.request
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
R=pathlib.Path(__file__).resolve().parents[2]
p=argparse.ArgumentParser();p.add_argument('--integer',action='store_true');p.add_argument('--allow-mismatches',action='store_true',help='discovery only: retain mismatches without failing the command');p.add_argument('--worker',default=str(R/'rust/target/parity-evidence/reserved-badge-independent-review/combined/serving-worker'));p.add_argument('--port',type=int,default=19140);args=p.parse_args()
width='INTEGER' if args.integer else 'BIGINT';schema='notifications_native_controller_'+width.lower();O=R/'rust/target/parity-evidence/notifications-native-completion'/width.lower();O.mkdir(parents=True,exist_ok=True)
os.environ['SUPPORTER_WHOLE_SOURCE']=str(R)
setup=(R/'api/bin/supporter-whole-flow-setup.py').read_text().replace("o=r/'rust/target/parity-evidence/supporter-whole-flow'","o=r/'"+str(O.relative_to(R))+"'")
(O/'setup.py').write_text(setup);exec(compile(setup,str(R/'api/bin/supporter-whole-flow-setup.py'),'exec'))
A=O/'app';base=args.port;D=pathlib.Path('/tmp/vaak-notifications-deps/extracted');e=os.environ.copy();e.update(json.loads((O/'env.json').read_text()));e.update(VAAK_SUPPORTER_CODES='0',VAAK_REGISTRATION_APPROVALS='0',VAAK_REDIS_ENABLED='1',VAAK_REDIS_DSN=f'redis://127.0.0.1:{base}/0',VAAK_NOTIF_AXUM_PRIMARY='0',VAAK_NOTIF_UNREAD_AXUM='0',VAAK_NOTIF_EMBED_AXUM='0',VAAK_FEATURE_BLUESKY='0')
php=[str(R/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d',f'extension={D}/usr/lib/php/20230831/igbinary.so','-d',f'extension={D}/usr/lib/php/20230831/redis.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so']
provision=O/'provision.php';provision.write_text((R/'api/bin/posting-threading-sapi-pg-provision.php').read_text().replace("$schema='posting_threading_sapi'","$schema='"+schema+"'").replace("'BIGINT'","'"+width+"'").replace("'BIGSERIAL'","'SERIAL'" if args.integer else "'BIGSERIAL'"))
subprocess.run(php+[str(provision)],env=dict(e,POSTING_SAPI_SQLITE=str(O/'storage/ap.sqlite'),POSTING_SAPI_API_SOURCE=str(A/'api')),check=True)
subprocess.run(['cc','-shared','-fPIC','-o',str(O/'loopback-only.so'),str(R/'api/bin/library-fixture-loopback-only.c'),'-ldl'],check=True)
e['LD_PRELOAD']=str(O/'loopback-only.so');e['VAAK_FIXTURE_NET_DENIAL_LOG']=str(O/'denials.log')
e['NOTIF_PHP_TRACE']=str(O/'php-producer.trace')
e['AP_DB_DSN']='pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=--search_path='+schema
# Entry-only instrumentation: records execution, leaves arguments/returns intact.
for filename,signatures in {'ap-admin.php':['function admin_render_notification_stream(array $rows, array $followingIds, array $followerIds): void','function admin_render_notification_card(array $n, array $followingIds, array $followerIds): void'],'ap-masto-entities.php':['function ap_masto_notification_entity(array $item): ?array']}.items():
 f=A/'api'/filename;s=f.read_text()
 for signature in signatures:
  needle=signature+'\n{';assert s.count(needle)==1
  s=s.replace(needle,needle+'\n    file_put_contents(getenv("NOTIF_PHP_TRACE"), '+"'"+signature.replace("'", "\\'")+"'"+'."\\n", FILE_APPEND);')
 f.write_text(s)
control=A/'control.php'
def ctl(code):
 control.write_text("<?php require __DIR__.'/api/ap-auth.php';ap_auth_bootstrap();require __DIR__.'/api/ap-masto-entities.php';$d=ap_db();$fixtureRedis=ap_redis_client();if(!$fixtureRedis)throw new RuntimeException('owned Redis absent');$info=$fixtureRedis->info('server');$dir=$fixtureRedis->config('GET','dir');if((string)($info['process_id']??'')!==getenv('NOTIF_OWNED_REDIS_PID')||($dir['dir']??'')!==getenv('NOTIF_OWNED_REDIS_DIR'))throw new RuntimeException('Redis ownership mismatch; no cache mutation permitted');"+code)
 x=subprocess.run(php+[str(control)],env=e,capture_output=True,text=True)
 if x.returncode:
  (O/'control-failure.json').write_text(json.dumps(dict(stdout=x.stdout,stderr=x.stderr,code=code),indent=2));raise RuntimeError(x.stderr+x.stdout)
 if x.stderr:raise RuntimeError(x.stderr)
 return json.loads(x.stdout) if x.stdout else None
trace=[];captures=[];results=[];processes=[];logs=[]
class Proxy(BaseHTTPRequestHandler):
 def do_GET(self):
  started=time.monotonic()
  try:r=urllib.request.urlopen(f'http://127.0.0.1:{base+1}'+self.path,timeout=8)
  except urllib.error.HTTPError as x:r=x
  body=r.read();trace.append(dict(path=self.path,status=r.status,headers=dict(r.headers),body=body.decode(),ms=round((time.monotonic()-started)*1000,2)))
  self.send_response(r.status)
  for k,v in r.headers.items():
   if k.lower() not in ['connection','transfer-encoding','content-length']:self.send_header(k,v)
  self.end_headers();self.wfile.write(body)
 def log_message(self,*a):pass
proxy=None
spec=importlib.util.spec_from_file_location('tokens',R/'api/bin/search-native-acceptance-review.py');tokenmod=importlib.util.module_from_spec(spec);spec.loader.exec_module(tokenmod)
def start(command,env,name):
 log=open(O/name,'w');logs.append(log);x=subprocess.Popen(command,env=env,stdout=log,stderr=log);processes.append(x);return x
def req(c,port,path,data=None):
 q=urllib.request.Request(f'http://127.0.0.1:{port}'+path,data=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None)
 try:r=c.open(q,timeout=30)
 except urllib.error.HTTPError as x:r=x
 out=dict(status=r.status,headers=dict(r.headers),body=r.read().decode(),path=path);captures.append(out);return out
try:
 reenv=dict(e,LD_LIBRARY_PATH=str(D/'usr/lib/x86_64-linux-gnu'))
 probe=socket.socket();probe.bind(('127.0.0.1',base));probe.close()
 owned_redis=start([str(D/'usr/bin/redis-server'),'--bind','127.0.0.1','--port',str(base),'--save','','--appendonly','no','--dir',str(O)],reenv,'redis.log')
 e['NOTIF_OWNED_REDIS_PID']=str(owned_redis.pid);e['NOTIF_OWNED_REDIS_DIR']=str(O.resolve())
 (O/'redis-owner.json').write_text(json.dumps(dict(pid=owned_redis.pid,port=base,directory=str(O.resolve()),command='owned extracted redis-server, persistence disabled',proof='Every PHP control verifies INFO process_id and CONFIG GET dir against this Popen PID and fixture directory before any cache operation'),indent=2))
 for _ in range(50):
  try:ctl('$r=ap_redis_client();if(!$r)throw new RuntimeException("Redis unavailable");echo json_encode($r->ping());');break
  except Exception:time.sleep(.1)
 # Local seeded users and genuine follow entities avoid any network account hydration.
 ctl("$d->prepare('UPDATE ap_users SET password_hash=? WHERE actor_key=?')->execute([password_hash('fixture-admin-password',PASSWORD_DEFAULT),'cmdr_nova']);$d->beginTransaction();for($i=1;$i<=16;$i++){ap_auth_create_account_row($d,'notifpeer'.$i,'peer'.$i.'@example.invalid',password_hash('fixture-peer',PASSWORD_DEFAULT),ap_db_now());}$d->commit();")
 rows=ctl("$items=[];for($i=16;$i>=1;$i--){$actor='https://mkultra.monster/users/notifpeer'.$i;$row=['id'=>$i,'created_at'=>gmdate('c',time()-100+$i),'actor_id'=>$actor,'target_actor'=>'https://mkultra.monster/users/cmdr_nova','action_taken'=>'local_accept_followback'];$items[]=ap_masto_notification_entity(['kind'=>'follow','row'=>$row]);}echo json_encode($items);")
 assert len(rows)==16 and all(x and x['type']=='follow' for x in rows)
 # Exact cache/source and loaded runtime provenance, including trace-only copy edits.
 (O/'runtime-manifest.json').write_text(json.dumps(dict(worker_sha256=hashlib.sha256(pathlib.Path(args.worker).read_bytes()).hexdigest(),harness_sha256=hashlib.sha256(pathlib.Path(__file__).read_bytes()).hexdigest(),width=width,schema=schema,php_runtime=subprocess.check_output(php+['-r','echo json_encode([PHP_VERSION,phpversion(\"redis\"),phpversion(\"igbinary\")]);'],env=e,text=True),copied=[dict(path=str(f.relative_to(A)),sha256=hashlib.sha256(f.read_bytes()).hexdigest(),mode=f.stat().st_mode&511) for d in ['api','vaak'] for f in (A/d).glob('*.php')],adaptations=['literal storage/config/key/session path isolation','entry-only trace in two painters and entity mapper'],packages=[dict(path=str(f),sha256=hashlib.sha256(f.read_bytes()).hexdigest()) for f in D.parent.glob('*.deb')]),indent=2))
 (O/'genuine-php-produced-entities.json').write_text(json.dumps(rows,indent=2))
 worker=pathlib.Path(args.worker);we=dict(e,VAAK_DATABASE_URL='host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=-csearch_path='+schema,VAAK_ENV_FILE=str(O/'absent.env'),VAAK_PHYRIAN_BRIDGE_ENV_FILE=str(O/'absent-bridge.env'),VAAK_REDIS_URL=f'redis://127.0.0.1:{base}/0',VAAK_REDIS_QUEUE_URL=f'redis://127.0.0.1:{base}/1',VAAK_API_ROOT=str(A/'api'),VAAK_PHP_BIN=str(R/'api/bin/php-supporter-isolated'))
 start([str(worker),'serve','--bind',f'127.0.0.1:{base+1}'],we,'worker.log')
 proxy=ThreadingHTTPServer(('127.0.0.1',base+2),Proxy);threading.Thread(target=proxy.serve_forever,daemon=True).start()
 for offset,native in [(3,False),(4,True)]:
  pe=dict(e,VAAK_MENTIONS_HTML_AXUM='1' if native else '0',VAAK_SHADOW_HTTP=f'http://127.0.0.1:{base+2}',NOTIF_PHP_TRACE=str(O/('php-native.trace' if native else 'php-reference.trace')))
  start(php+['-d','disable_functions=exec,shell_exec,system,passthru,proc_open,popen','-d','allow_url_fopen=0','-S',f'127.0.0.1:{base+offset}',str(A/'router.php')],pe,'sapi-'+str(offset)+'.log')
 c=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
 for _ in range(50):
  try:h=req(c,base+3,'/vaak/?mode=login');urllib.request.urlopen(f'http://127.0.0.1:{base+1}/healthz');break
  except OSError:time.sleep(.1)
 token=re.search('name="csrf" value="([^"]+)"',h['body'])[1]
 h=req(c,base+3,'/vaak/?mode=login',dict(action='login',csrf=token,login='cmdr_nova',password='fixture-admin-password'));assert 'name="action" value="login"' not in h['body']
 def cache(items,max_id=None,limit=12):
  payload=json.dumps(items);raw=__import__('base64').b64encode(payload.encode()).decode();cursor='null' if max_id is None else json.dumps(max_id)
  return ctl("$r=ap_redis_client();$r->flushDB();$items=json_decode(base64_decode('"+raw+"'),true);$key=ap_masto_notifications_list_redis_key(1,"+str(limit)+","+cursor+",null,ap_masto_notifications_want_types([],[]),[]);$r->setex($key,300,json_encode(['ts'=>time(),'items'=>$items,'source'=>'vaak-worker-projection']));echo json_encode($key);")
 def compare(label,items,blocked=(),max_id=None,limit=12):
  ctl("ap_redis_client()->flushDB();$d->exec('DELETE FROM ap_user_blocks');$d->exec('DELETE FROM ap_mutes');$d->exec('DELETE FROM masto_markers');"+''.join("$m=ap_mute_upsert('https://mkultra.monster/users/notifpeer"+str(i)+"',true,1);if(!$m['ok'])throw new RuntimeException('fixture mute failed');" for i in blocked))
  cache(items,max_id,limit)
  path='/vaak/?view=mentions&partial=1&limit='+str(limit)+(('&notifications_max_id='+max_id) if max_id else '')
  values=[];effects=[];starttrace=len(trace)
  for offset in [3,4]:
   ctl("$d->exec('DELETE FROM masto_markers');")
   audit=O/('php-reference.trace' if offset==3 else 'php-native.trace');audit.write_text('')
   values.append(req(c,base+offset,path));effects.append(ctl("echo json_encode($d->query('SELECT timeline,last_read_id FROM masto_markers ORDER BY timeline')->fetchAll());"))
  left,right=values;keys=['X-Has-More','X-Next-Max-Id'];result=dict(case=label,native_200=any(t['status']==200 for t in trace[starttrace:]),native_source=right['headers'].get('X-VAAK-Mentions-Html'),html_equal=tokenmod.Tokens(left['body']).tokens==tokenmod.Tokens(right['body']).tokens,metadata_equal=all(left['headers'].get(k)==right['headers'].get(k) for k in keys),read_equal=effects[0]==effects[1],reference_metadata={k:left['headers'].get(k) for k in keys},native_metadata={k:right['headers'].get(k) for k in keys},read_effects=effects,reference_cards=len(re.findall('<article',left['body'])),native_cards=len(re.findall('<article',right['body'])),native_php_trace=(O/'php-native.trace').read_text(),trace_start=starttrace,trace_end=len(trace))
  result['reference_php_trace']=(O/'php-reference.trace').read_text()
  result['native_zero_php_painter']=not result['native_php_trace']
  for side,response in [('reference',left),('native',right)]:
   tokens=tokenmod.Tokens(response['body']).tokens
   result[side+'_action_tokens']=[t for t in tokens if t[0]=='open' and t[1] in ['form','input','button','select','textarea']]
   result[side+'_group_labels']=re.findall(r'\d+ people (?:liked|boosted) your post',html.unescape(re.sub('<[^>]*>',' ',response['body'])))
  result['actions_equal']=result['reference_action_tokens']==result['native_action_tokens']
  result['group_labels_equal']=result['reference_group_labels']==result['native_group_labels']
  results.append(result)
 compare('warm-full-follow',rows[:12])
 compare('hidden-tail-keeps-raw-cursor',rows[:12],[5])
 compare('all-hidden-full-window',rows[:12],range(5,17))
 compare('short-exact-terminal',rows[-3:])
 compare('older-exact-page',rows[12:],[1],max_id=rows[11]['id'],limit=4)
 # Genuine linked identity fixtures: raw SQL facts, then actual PHP entity mapper.
 ctl("$d->exec('DELETE FROM ap_mutes');require_once __DIR__.'/api/ap-bsky.php';ap_bsky_migrate();$when=gmdate('c',time()-300);$note='https://mkultra.monster/users/cmdr_nova/notes/notif-parity';$d->prepare('INSERT INTO outbox_notes(id,create_id,published,content,to_json,cc_json,raw_create_json,visibility) VALUES(?,?,?,?,?,?,?,?)')->execute([$note,$note.'/create',$when,'<p>Genuine notification grouped fixture</p>','[\"https://www.w3.org/ns/activitystreams#Public\"]','[]','{}','public']);$d->prepare('INSERT INTO masto_statuses(note_id,published,content_text,visibility) VALUES(?,?,?,?)')->execute([$note,$when,'Genuine notification grouped fixture','public']);$uid=(int)$d->query(\"SELECT id FROM ap_users WHERE actor_key='notifpeer16'\")->fetchColumn();$d->prepare('INSERT INTO bsky_sessions(owner_user_id,handle,did,access_jwt_enc,refresh_jwt_enc,connected_at,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([$uid,'notifpeer16.example.invalid','did:plc:notificationsfixture16','fixture-no-real-token','fixture-no-real-token',$when,$when]);$actor='https://bsky.app/profile/did:plc:notificationsfixture16';$d->prepare('INSERT INTO remote_actors(actor_id,username,display_name,host,updated_at,profile_json) VALUES(?,?,?,?,?,?)')->execute([$actor,'notifpeer16','Linked peer16','bsky.app',$when,json_encode(['id'=>$actor,'type'=>'Person','preferredUsername'=>'notifpeer16','name'=>'Linked peer16'])]);")
 groups=ctl("$items=[];$n=100;foreach(['https://bsky.app/profile/did:plc:notificationsfixture16','https://mkultra.monster/users/notifpeer16','https://mkultra.monster/users/notifpeer15'] as $actor){$row=['id'=>$n++,'created_at'=>gmdate('c',time()-80),'actor_id'=>$actor,'object_id'=>'https://mkultra.monster/users/cmdr_nova/notes/notif-parity','owner_actor_id'=>'https://mkultra.monster/users/cmdr_nova','owner_user_id'=>1,'activity_type'=>'Like','type'=>'Like'];$items[]=ap_masto_notification_entity(['kind'=>'mention','row'=>$row]);}echo json_encode($items);")
 assert len(groups)==3 and all(x and x['status'] for x in groups)
 (O/'genuine-php-grouped-entities.json').write_text(json.dumps(groups,indent=2))
 compare('linked-local-Bluesky-favourite-group',groups,limit=3)
 shell=[];shell_trace_start=len(trace)
 for offset in [3,4]:
  ctl("$d->exec('DELETE FROM masto_markers');")
  response=req(c,base+offset,'/vaak/?view=mentions&partial=1&shell=1&notifications_max_id='+rows[11]['id'])
  shell.append(dict(response=response,markers=ctl("echo json_encode($d->query('SELECT timeline,last_read_id FROM masto_markers')->fetchAll());")))
 shell_checks=dict(no_native_request=len(trace)==shell_trace_start,no_mark_read=all(not x['markers'] for x in shell),html_equal=tokenmod.Tokens(shell[0]['response']['body']).tokens==tokenmod.Tokens(shell[1]['response']['body']).tokens)
 (O/'shell-checks.json').write_text(json.dumps(shell_checks,indent=2));assert all(shell_checks.values()),shell_checks
 print(json.dumps({'width':width,'cases':results,'shell_checks':shell_checks},indent=2))
finally:
 (O/'captures.json').write_text(json.dumps(captures,indent=2));(O/'native-http-trace.json').write_text(json.dumps(trace,indent=2));(O/'results.json').write_text(json.dumps(results,indent=2))
 if proxy:proxy.shutdown();proxy.server_close()
 for x in reversed(processes):
  x.terminate()
  try:x.wait(timeout=5)
  except subprocess.TimeoutExpired:x.kill();x.wait(timeout=5)
 for x in logs:x.close()
 subprocess.run(php+[str(provision)],env=dict(e,POSTING_SAPI_DROP_ONLY='1'),check=True)

if not args.allow_mismatches and any(not all(x[k] for k in ['native_200','native_zero_php_painter','html_equal','metadata_equal','read_equal','actions_equal','group_labels_equal']) for x in results):raise SystemExit(1)
