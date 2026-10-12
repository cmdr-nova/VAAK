#!/usr/bin/env python3
"""Finite actual serving-HTTP reserved badge authority fixture. Derived from the Supporter fixture; no activation."""
import pathlib, json, os, subprocess, shutil, hashlib, threading, socketserver, socket, time, urllib.request, urllib.error, struct, re, html as html_module
R=pathlib.Path(__file__).resolve().parents[2]
BASE=R/'rust/target/parity-evidence/reserved-badge-authority/source'
O=R/'rust/target/parity-evidence/reserved-badge-authority/native';O.mkdir(parents=True,exist_ok=True)
repaired=os.environ.get('SUPPORTER_INDEXED_SERVING')=='1'
O=O/'indexed-replay' if repaired else O;O.mkdir(parents=True,exist_ok=True)
S=O/'source';S.mkdir(exist_ok=True)
L=R/'rust/target/parity-evidence/library-controller-native-phase'
def sha(p):return hashlib.sha256(p.read_bytes()).hexdigest()
inputs=[]
for base in ['api','vaak','rust/vaak-worker','rust/vaak-types']:
 for p in (BASE/base).rglob('*'):
  if not p.is_file() or '__pycache__' in p.parts:continue
  q=S/p.relative_to(BASE);q.parent.mkdir(parents=True,exist_ok=True)
  if p.resolve()!=q.resolve():shutil.copy2(p,q)
  inputs.append(dict(path=str(p.relative_to(BASE)),sha256=sha(p),mode=p.stat().st_mode&511))
for name in ['Cargo.toml','Cargo.lock']:
 p=BASE/'rust'/name;q=S/'rust'/name
 if p.resolve()!=q.resolve():shutil.copy2(p,q)
 inputs.append(dict(path=str(p.relative_to(BASE)),sha256=sha(p),mode=p.stat().st_mode&511))
frozen=json.loads((BASE.parent/'inputs.json').read_text())
for i in frozen:
 p=BASE/i['path'];assert sha(p)==i['sha256'] and p.stat().st_mode&511==i['mode'],i['path']
worker=BASE.parent/'serving-worker'
assert sha(worker)==json.loads((BASE.parent/'artifact.json').read_text())['sha256']
(O/'inputs-before.json').write_text(json.dumps(inputs,indent=2))
subprocess.run(['cc','-shared','-fPIC','-o',str(O/'loopback-only.so'),str(S/'api/bin/library-fixture-loopback-only.c'),'-ldl'],check=True)
env=dict(os.environ,LD_PRELOAD=str(O/'loopback-only.so'),VAAK_FIXTURE_NET_DENIAL_LOG=str(O/'denials.log'))
original_connect=socket.socket.connect;original_dns=socket.getaddrinfo
def guarded_connect(self,address):
 if isinstance(address,tuple) and address[0] not in ['localhost','::1'] and not address[0].startswith('127.'):raise RuntimeError('Python outbound transport denied before wire')
 return original_connect(self,address)
def guarded_dns(host,*args,**kwargs):
 if host not in ['localhost','::1'] and not str(host).startswith('127.'):raise RuntimeError('Python external DNS denied before wire')
 return original_dns(host,*args,**kwargs)
socket.socket.connect=guarded_connect;socket.getaddrinfo=guarded_dns
php=[str(S/'api/bin/php-supporter-isolated'),'-d','extension=/tmp/vaak-php-mbstring-test/usr/lib/php/20230831/mbstring.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so']
control=O/'control.php'
control.write_text("<?php $d=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$x=json_decode(stream_get_contents(STDIN),true);if(isset($x['schema']))$d->exec('SET search_path='.$x['schema']);if(isset($x['sql']))$d->exec($x['sql']);if(isset($x['query']))echo json_encode($d->query($x['query'])->fetchAll(PDO::FETCH_ASSOC));")
canonical=O/'php-current-profile.php'
canonical.write_text("""<?php
require __DIR__.'/source/api/bin/migration-reference-functions.php';
$input=json_decode(stream_get_contents(STDIN),true);$d=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$d->exec('SET search_path='.$input['schema']);
function ap_db():PDO{return $GLOBALS['d'];}
function ap_redis_json_get(string $key):mixed{return $GLOBALS['input']['cache'];}
reference_load(__DIR__.'/source/api/ap-db.php',['ap_profile_get','ap_profile_authorized_badges','ap_profile_supporter_awarded','ap_profile_normalize_badges','ap_profile_badge_catalog']);
echo json_encode(['badges'=>ap_profile_get('fixture')['profile_badges'],'catalog'=>ap_profile_badge_catalog()]);
""")
def db(sql=None,query=None,schema=None):
 x={k:v for k,v in dict(sql=sql,query=query,schema=schema).items() if v is not None}
 p=subprocess.run(php+[str(control)],input=json.dumps(x),text=True,capture_output=True,env=env,check=True)
 assert not p.stderr,p.stderr
 return json.loads(p.stdout) if p.stdout else None
def lit(v):return 'NULL' if v is None else "'"+str(v).replace("'","''")+"'"
ref=json.loads((R/'rust/target/parity-evidence/profile-featured-current-ui/header-production-media-current-reference.json').read_text())
shutil.copy2(R/'rust/target/parity-evidence/profile-featured-current-ui/header-production-media-current-reference.json',O/'schema-reference.json')
values={};wire=[];hook=None
sqlwire=[]
class Pg(socketserver.StreamRequestHandler):
 def handle(self):
  self.request.setsockopt(socket.IPPROTO_TCP,socket.TCP_NODELAY,1)
  upstream=socket.socket(socket.AF_UNIX,socket.SOCK_STREAM);upstream.connect('/tmp/vaak-library-facts-pg-socket/.s.PGSQL.5432')
  def back():
   try:
    while True:
     data=upstream.recv(65536)
     if not data:return
     self.request.sendall(data)
   except OSError:pass
  threading.Thread(target=back,daemon=True).start()
  try:
   head=self.rfile.read(4)
   if len(head)!=4:return
   n=struct.unpack('!I',head)[0];upstream.sendall(head+self.rfile.read(n-4))
   while True:
    kind=self.rfile.read(1)
    if not kind:return
    head=self.rfile.read(4);n=struct.unpack('!I',head)[0];payload=self.rfile.read(n-4)
    if kind==b'P':sqlwire.append(payload.split(b'\0',2)[1].decode())
    elif kind==b'Q':sqlwire.append(payload[:-1].decode())
    upstream.sendall(kind+head+payload)
  except OSError:pass
  finally:upstream.close()
class Redis(socketserver.StreamRequestHandler):
 def handle(self):
  global hook
  while True:
   a=self.rfile.readline()
   if not a:return
   n=int(a.strip()[1:]);args=[]
   for _ in range(n):
    z=int(self.rfile.readline().strip()[1:]);args.append(self.rfile.read(z).decode());self.rfile.read(2)
   wire.append(args);op=args[0].upper();answer=b'+OK\r\n'
   if op=='GET':
    if args[1].startswith('vaak:profile:v1:') and hook:
     action=hook;hook=None;action()
    v=values.get(args[1]);answer=b'$-1\r\n' if v is None else ('$'+str(len(v.encode()))+'\r\n'+v+'\r\n').encode()
   elif op=='SET':values[args[1]]=args[2]
   elif op in ['EXISTS','DEL','EXPIRE','INCR','HINCRBY','LPUSH','LTRIM']:answer=b':1\r\n'
   self.wfile.write(answer);self.wfile.flush()
class Server(socketserver.ThreadingTCPServer):allow_reuse_address=True;daemon_threads=True
server=Server(('127.0.0.1',19021),Redis);threading.Thread(target=server.serve_forever,daemon=True).start()
pgserver=Server(('127.0.0.1',19020),Pg);threading.Thread(target=pgserver.serve_forever,daemon=True).start()
checks=[];captures=[];workers=[];schemas=[]
def check(ok,label):
 checks.append(dict(ok=bool(ok),check=label))
 if not ok:raise AssertionError(label)
try:
 for width in ['INTEGER','BIGINT']:
  schema='reserved_serving_'+width.lower();schemas.append(schema)
  db(sql='CREATE SCHEMA '+schema)
  for table,cols in ref['current_schema_by_width'][width].items():
   db(sql='CREATE TABLE '+table+'('+','.join(c['column_name']+' '+c['data_type'] for c in cols)+')',schema=schema)
  seed=next(c for c in ref['captures'] if c['width']==width and c['case']=='baseline_owner1')
  for table in ['ap_users','actor_profile']:
   for row in seed['current_tables'][table]:db(sql='INSERT INTO '+table+'('+','.join(row)+') VALUES('+','.join(map(lit,row.values()))+')',schema=schema)
  db(sql="CREATE TABLE ap_supporter_codes(code_hash TEXT PRIMARY KEY,source_ref TEXT UNIQUE NOT NULL,email_hash TEXT NOT NULL,receipt_hash TEXT NOT NULL,created_at TEXT NOT NULL,expires_at TEXT,used_by BIGINT,used_at TEXT);CREATE INDEX idx_supporter_used_by ON ap_supporter_codes(used_by) WHERE used_at IS NOT NULL; INSERT INTO ap_supporter_codes VALUES('paid','source','email','receipt','now',NULL,1,'now')",schema=schema)
  actual=db(query="SELECT data_type FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='ap_users' AND column_name='id'",schema=schema)
  check(actual[0]['data_type']==width.lower(),width+' actual subject ID inventory')
  key='vaak:profile:v1:'+hashlib.sha256(b'fixture').hexdigest()
  profile=dict(actor_key='fixture',name='Cached subject',summary='<p>Cached bio</p>',profile_badges=['cat','dog','coffee','tea','bookworm','gamer'],attachment=[])
  values.clear();values[key]=json.dumps(profile)
  we=dict(env,VAAK_ENV_FILE=str(O/'absent.env'),VAAK_PHYRIAN_BRIDGE_ENV_FILE=str(O/'absent-bridge.env'),VAAK_DATABASE_URL="host=127.0.0.1 port=19020 sslmode=disable user=postgres dbname=postgres options='-c search_path="+schema+"'",VAAK_PROFILE_COMPLETE_NATIVE='1',VAAK_PROFILE_COMPLETE_SECRET='supporter-native-isolated-token-00001',VAAK_REDIS_ENABLED='1',VAAK_REDIS_URL='redis://127.0.0.1:19021/0',VAAK_REDIS_QUEUE_URL='redis://127.0.0.1:19021/1',RUST_LOG='debug',VAAK_API_ROOT=str(S/'api'),VAAK_PHP_BIN=str(S/'api/bin/php-supporter-isolated'))
  log=open(O/(width.lower()+'-worker.log'),'w');p=subprocess.Popen([str(worker),'serve','--bind','127.0.0.1:19022'],env=we,stdout=log,stderr=subprocess.STDOUT);workers.append(p)
  for _ in range(100):
   try:urllib.request.urlopen('http://127.0.0.1:19022/healthz',timeout=.5);break
   except OSError:time.sleep(.05)
  body=dict(protocol_version=1,viewer=dict(kind='authenticated',owner_id=2,csrf='fixture-csrf'),actor_key='fixture',actor='https://mkultra.monster/users/fixture',tab='posts',post='',shell=True,lazy_media=True,status_preview_budget=0,quote_preview_budget=0)
  body['from']='home'
  def request(label,viewer=None):
   b=dict(body)
   if viewer is not None:b['viewer']=viewer
   query_start=len(sqlwire);start=time.perf_counter();q=urllib.request.Request('http://127.0.0.1:19022/internal/profile/full',data=json.dumps(b).encode(),headers={'Content-Type':'application/json','X-VAAK-Profile-Token':we['VAAK_PROFILE_COMPLETE_SECRET']})
   try:r=urllib.request.urlopen(q,timeout=10)
   except urllib.error.HTTPError as e:r=e
   out=json.loads(r.read());queries=sqlwire[query_start:];captures.append(dict(width=width,case=label,status=r.status,headers=dict(r.headers),response=out,ms=(time.perf_counter()-start)*1000,queries=queries,supporter_query_count=sum('ap_supporter_codes' in x for x in queries)));check(r.headers.get('Cache-Control')=='no-store',width+' '+label+' no-store');return r.status,out
  def badge(label,earned,viewer=None):
   status,out=request(label,viewer);check(status==200,(width,label,status,out));html=out['html'];check(('title="Supporter"' in html)==earned,width+' '+label+' current earned badge');
   current=subprocess.run(php+[str(canonical)],input=json.dumps(dict(schema=schema,cache=json.loads(values[key]))),text=True,capture_output=True,env=env,check=True);check(not current.stderr,width+' '+label+' actual PHP canonical zero warnings');oracle=json.loads(current.stdout);captures[-1]['actual_php_profile']=oracle
   check(('supporter' in oracle['badges'])==earned,width+' '+label+' actual current PHP grant parity')
   expected=[oracle['catalog'][b] for b in oracle['badges']]
   painted=re.findall('<span class="tag" title="([^"]+)">([^<]+)</span>',html)
   check([(html_module.unescape(t),html_module.unescape(text)) for t,text in painted]==[(b['label'],b['emoji']+' '+b['label']) for b in expected],width+' '+label+' complete actual PHP earned and six-free badge rendering parity')
   for label in ['Cat person','Dog person','Coffee enthusiast','Tea enthusiast','Bookworm','Gamer']:check('title="'+label+'"' in html,width+' '+label+' free flair preserved')
  # Actual constructor, genuine warm cache, current SQL roles and grants.
  profile['profile_badges']=['admin','supporter','cat','dog','coffee','tea','bookworm','gamer'];values[key]=json.dumps(profile)
  db(sql='UPDATE ap_users SET is_admin=0 WHERE id=1',schema=schema)
  badge('forged-admin-earned-supporter',True)
  check('title="Admin"' not in captures[-1]['response']['html'],width+' forged Admin removed')
  db(sql='UPDATE ap_users SET is_admin=1 WHERE id=1',schema=schema)
  profile['profile_badges']=['cat','dog','coffee','tea','bookworm','gamer'];values[key]=json.dumps(profile)
  badge('omitted-current-admin-and-supporter',True)
  check('title="Admin"' in captures[-1]['response']['html'],width+' Admin assigned from current role')
  badge('guest-current-admin-and-supporter',True,dict(kind='guest'))
  hook=lambda:db(sql='UPDATE ap_users SET is_admin=0 WHERE id=1',schema=schema)
  status,out=request('admin-withdraw-during-redis-enrichment');check(status==422 and 'html' not in out,width+' late role withdrawal denies partial fragment');check(hook is None,width+' actual role hook executed')
  badge('admin-withdrawn-next-request',True)
  check('title="Admin"' not in captures[-1]['response']['html'],width+' current role withdrawal suppresses Admin')
  badge('omitted-warm-earned',True);badge('repeat-warm-earned',True);badge('guest-warm-earned',True,dict(kind='guest'))
  db(sql='UPDATE ap_supporter_codes SET used_by=NULL,used_at=NULL',schema=schema);badge('withdrawn-warm',False)
  profile['profile_badges'].insert(0,'supporter');values[key]=json.dumps(profile);badge('forged-warm',False)
  db(sql="UPDATE ap_supporter_codes SET used_by=2,used_at='now'",schema=schema);badge('viewer-grant-not-subject',False)
  hook=lambda:db(sql="UPDATE ap_supporter_codes SET used_by=1,used_at='now'",schema=schema)
  status,out=request('award-during-redis-enrichment');check(status==422 and 'html' not in out,width+' late award refuses inconsistent whole fragment');check(hook is None,width+' award actual Redis hook executed')
  badge('awarded-next-current-request',True)
  db(sql="UPDATE ap_supporter_codes SET used_by=1,used_at='now'",schema=schema)
  hook=lambda:db(sql='UPDATE ap_supporter_codes SET used_by=NULL,used_at=NULL',schema=schema)
  status,out=request('withdraw-during-redis-enrichment');check(status==422 and 'html' not in out,width+' late withdrawal refuses whole fragment');check(hook is None,width+' actual Redis hook executed')
  db(sql="UPDATE ap_supporter_codes SET used_by=1,used_at='now'",schema=schema)
  hook=lambda:db(sql="UPDATE ap_users SET disabled_at='disabled' WHERE id=1",schema=schema)
  status,out=request('disable-subject-during-redis-enrichment');check(status==422 and 'html' not in out,width+' late subject disable refuses whole fragment');check(hook is None,width+' disable actual Redis hook executed')
  db(sql='UPDATE ap_users SET disabled_at=NULL WHERE id=1',schema=schema)
  hook=lambda:db(sql='DROP TABLE ap_supporter_codes',schema=schema)
  status,out=request('drop-table-during-redis-enrichment');check(status==422 and 'html' not in out,width+' late missing table refuses whole fragment');check(hook is None,width+' drop actual Redis hook executed')
  badge('missing-table-warm-forgery',False);badge('missing-table-repeat',False)
  p.terminate();p.wait(timeout=5);workers.remove(p);log.close()
 (O/'captures.json').write_text(json.dumps(captures,indent=2));(O/'checks.json').write_text(json.dumps(checks,indent=2));(O/'redis-wire.json').write_text(json.dumps(wire,indent=2))
 print(json.dumps(dict(checks=len(checks),requests=len(captures),sha256=sha(worker))))
finally:
 for p in workers:p.terminate();p.wait(timeout=5)
 server.shutdown();server.server_close();pgserver.shutdown();pgserver.server_close()
 for schema in schemas:db(sql='DROP SCHEMA '+schema+' CASCADE')
 (O/'checks-partial.json').write_text(json.dumps(checks,indent=2));(O/'captures-partial.json').write_text(json.dumps(captures,indent=2))
 (O/'inputs-after.json').write_text(json.dumps([dict(path=i['path'],sha256=sha(BASE/i['path']),mode=(BASE/i['path']).stat().st_mode&511) for i in inputs],indent=2))
