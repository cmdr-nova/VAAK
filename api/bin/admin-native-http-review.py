#!/usr/bin/env python3
"""Isolated actual worker HTTP against retained owned PG schemas + genuine PHP references."""
import json,os,re,subprocess,time,urllib.request,urllib.error,hashlib,concurrent.futures
from pathlib import Path
from html.parser import HTMLParser
base=Path('rust/target/parity-evidence/admin-native-invites-approvals-phase')
checks=[];receipts=[]
def check(ok,name):
 if not ok: raise AssertionError(name)
 checks.append(name)
def norm(s):
 class Dom(HTMLParser):
  def __init__(self):super().__init__(convert_charrefs=True);self.nodes=[]
  def handle_starttag(self,tag,attrs):self.nodes.append(('start',tag,sorted(attrs)))
  def handle_endtag(self,tag):self.nodes.append(('end',tag))
  def handle_data(self,data):
   data=re.sub(r'\s+',' ',data).strip()
   if data:self.nodes.append(('text',data))
 d=Dom();d.feed(s);return d.nodes
def sql(schema,query):
 code='$d=new PDO("pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres");$d->exec("SET search_path TO '+schema+'");$d->exec('+json.dumps(query)+');'
 subprocess.run(['api/bin/php-policy-test','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-r',code],check=True,capture_output=True)
def native_read_waiting():
 code="""$d=new PDO("pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres");echo $d->query("SELECT count(*) FROM pg_stat_activity WHERE datname=current_database() AND wait_event_type='Lock' AND query LIKE 'SELECT jsonb_build_object%FROM ap_invite_codes%'")->fetchColumn();"""
 return int(subprocess.check_output(['api/bin/php-policy-test','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-r',code],text=True))>0
def fingerprints(schema):
 code='$d=new PDO("pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres");$d->exec("SET search_path TO '+schema+'");$out=[];foreach(["ap_invite_codes","ap_registration_applications"]as $t)$out[$t]=hash("sha256",json_encode($d->query("SELECT * FROM ".$t." ORDER BY id")->fetchAll(PDO::FETCH_ASSOC)));echo json_encode($out);'
 return json.loads(subprocess.check_output(['api/bin/php-policy-test','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-r',code],text=True))
def call(request,token='native-admin-test-token-0123456789'):
 data=json.dumps(request).encode();q=urllib.request.Request('http://127.0.0.1:18789/internal/admin/read',data,{'Content-Type':'application/json','X-Vaak-Admin-Token':token})
 try:
  with urllib.request.urlopen(q,timeout=4) as r:return r.status,dict(r.headers),r.read().decode()
 except urllib.error.HTTPError as e:return e.code,dict(e.headers),e.read().decode()
for width in ['integer','bigint']:
 reference=json.loads((base/('php-pg-'+width+'.json')).read_text());schema=reference['schema']
 before=fingerprints(schema)
 for enabled in [True,False]:
  env=os.environ.copy();env.update(VAAK_ENV_FILE='/nonexistent',VAAK_PHYRIAN_BRIDGE_ENV_FILE='/nonexistent',VAAK_DATABASE_URL='host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=\'-c search_path='+schema+'\'',VAAK_ADMIN_READ_NATIVE='1',VAAK_ADMIN_READ_SECRET='native-admin-test-token-0123456789',VAAK_REGISTRATION_APPROVALS='1' if enabled else '0')
  log=open(base/('http-'+width+'-'+str(enabled)+'.log'),'w');p=subprocess.Popen([str(base/'worker-http-v2'),'serve','--bind','127.0.0.1:18789'],env=env,stdout=log,stderr=log)
  try:
   for _ in range(80):
    try:
     q=reference['results'][0]['request'].copy();q['php_timezone']='UTC';call(q);break
    except OSError:time.sleep(.05)
   else:raise AssertionError('owned worker readiness')
   sql(schema,'UPDATE ap_users SET is_admin=1,disabled_at=NULL WHERE id=1')
   for case in reference['results']:
    if case['case'] not in (['active','offset'] if enabled else ['closed']):continue
    q=case['request'].copy();q['php_timezone']='UTC';status,headers,raw=call(q)
    check(status==200,'native200 '+width+case['view']+case['mode']+case['case']);reply=json.loads(raw)
    (base/'last-html-comparison.json').write_text(json.dumps({'expected':norm(case['html']),'actual':norm(reply['html'])},indent=2));check(norm(reply['html'])==norm(case['html']),'genuineHTML '+width+case['view']+case['mode']+case['case'])
    check('no-store' in {k.lower():v for k,v in headers.items()}.get('cache-control',''),'no-store '+width+case['view']+case['mode']+case['case'])
    check('NEVER_RETURN_HASH' not in raw,'nohash '+width+case['view']+case['mode']+case['case']);receipts.append({'width':width,'case':case['case'],'view':q['view'],'mode':q['mode'],'response':reply})
   consumer_env=env.copy();consumer_env['VAAK_ADMIN_READ_URL']='http://127.0.0.1:18789/internal/admin/read'
   for view in ['invites','approvals']:
    for mode in ['full','shell']:
     raw=subprocess.check_output(['api/bin/php-policy-test','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so','api/bin/admin-native-http-consumer.php',schema,view,mode],env=consumer_env,text=True)
     captured=json.loads(raw);check(norm(captured['reference'])==norm(captured['native']),'actual PHP HTTP bridge+dispatch '+width+view+mode+str(enabled));receipts.append({'width':width,'enabled':enabled,'consumer':captured})
   q=reference['results'][0]['request'].copy();q['php_timezone']='UTC';q['approvals_enabled']=enabled
   for change,label in [({'owner_id':2},'ordinary'),({'owner_id':0},'guest'),({'php_timezone':'America/New_York'},'unproven timezone'),({'is_admin':True},'client grant'),({'approvals_enabled':not enabled},'flag drift')]:
    bad=q|change;status,_,raw=call(bad);check(status in [400,422] and 'private@example' not in raw,'failclosed '+width+label+str(enabled))
   status,_,_=call(q,'wrong');check(status==401,'private token '+width+str(enabled))
   for query,label in [('UPDATE ap_users SET is_admin=0 WHERE id=1','revoked'),("UPDATE ap_users SET is_admin=1,disabled_at='now' WHERE id=1",'disabled')]:
    sql(schema,query);status,_,raw=call(q);check(status==422 and 'html' not in raw,'current role '+width+label+str(enabled))
   sql(schema,'UPDATE ap_users SET is_admin=1,disabled_at=NULL WHERE id=1')
   # Hold only this fixture table: revoke role while real native read is blocked on I/O.
   lockcode='$d=new PDO("pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres");$d->exec("SET search_path TO '+schema+'");$d->beginTransaction();$d->exec("LOCK TABLE ap_invite_codes IN ACCESS EXCLUSIVE MODE");echo "locked\\n";fflush(STDOUT);fgets(STDIN);$d->commit();'
   locker=subprocess.Popen(['api/bin/php-policy-test','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-r',lockcode],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
   check(locker.stdout.readline().strip()=='locked','owned table lock '+width+str(enabled))
   try:
    with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
     future=pool.submit(call,q)
     for _ in range(30):
      if native_read_waiting():break
      time.sleep(.02)
     else:raise AssertionError('native table read lock not observed')
     check(not future.done(),'actual native I/O held '+width+str(enabled))
     sql(schema,'UPDATE ap_users SET is_admin=0 WHERE id=1');locker.communicate('\n',timeout=2);status,_,raw=future.result(timeout=4)
     check(status==422 and 'html' not in raw,'role withdrawn during real I/O '+width+str(enabled))
   finally:
    if locker.poll() is None:locker.communicate('\n',timeout=2)
    sql(schema,'UPDATE ap_users SET is_admin=1,disabled_at=NULL WHERE id=1')
  finally:p.terminate();p.wait(timeout=5);log.close()
 check(before==fingerprints(schema),'effect-free private rows '+width)
(base/'http-proof-v2.json').write_text(json.dumps({'checks':checks,'passed':len(checks),'receipts':receipts},indent=2));print(json.dumps({'passed':len(checks),'receipts':len(receipts)}))
