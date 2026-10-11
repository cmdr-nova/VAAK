#!/usr/bin/env python3
"""Actual frozen worker HTTP + PHP Users bridge/dispatch on owned disposable PG schemas."""
import os,json,re,time,subprocess,urllib.request,urllib.error,concurrent.futures
from pathlib import Path
from html.parser import HTMLParser
base=Path(os.environ.get('ADMIN_USERS_PHASE','rust/target/parity-evidence/admin-users-native-phase'));base.mkdir(parents=True,exist_ok=True)
checks=[];receipts=[]
def check(ok,name):
 if not ok:raise AssertionError(name)
 checks.append(name)
def norm(s):
 class D(HTMLParser):
  def __init__(self):super().__init__(convert_charrefs=True);self.nodes=[]
  def handle_starttag(self,t,a):self.nodes.append(('start',t,sorted(a)))
  def handle_endtag(self,t):self.nodes.append(('end',t))
  def handle_data(self,d):
   d=re.sub(r'\s+',' ',d).strip()
   if d:self.nodes.append(('text',d))
 d=D();d.feed(s);return d.nodes
php=['api/bin/php-policy-test','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so']
def code(schema,body):return '$d=new PDO("pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres");$d->exec("SET search_path TO '+schema+'");'+body
def sql(schema,q):subprocess.run(php+['-r',code(schema,'$d->exec('+json.dumps(q)+');')],check=True,capture_output=True)
def fingerprints(schema):return subprocess.check_output(php+['-r',code(schema,'echo hash("sha256",json_encode([$d->query("SELECT * FROM ap_users ORDER BY id")->fetchAll(PDO::FETCH_ASSOC),$d->query("SELECT * FROM actor_profile ORDER BY actor_key")->fetchAll(PDO::FETCH_ASSOC)]));')],text=True)
def call(r,token='users-native-test-token-0123456789'):
 q=urllib.request.Request('http://127.0.0.1:18989/internal/admin/read',json.dumps(r).encode(),{'Content-Type':'application/json','X-Vaak-Admin-Token':token})
 try:
  with urllib.request.urlopen(q,timeout=5) as x:return x.status,dict(x.headers),x.read().decode()
 except urllib.error.HTTPError as x:return x.code,dict(x.headers),x.read().decode()
for width in ['integer','bigint']:
 ref=json.loads((base/('php-final-'+width+'.json')).read_text());schema=ref['schema'];app='admin_users_native_'+width
 before=fingerprints(schema)
 env=os.environ.copy();env.update(VAAK_ENV_FILE='/nonexistent',VAAK_PHYRIAN_BRIDGE_ENV_FILE='/nonexistent',VAAK_DATABASE_URL="host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path="+schema+' -c application_name='+app+"'",VAAK_ADMIN_READ_NATIVE='1',VAAK_ADMIN_USERS_NATIVE='1',VAAK_ADMIN_READ_SECRET='users-native-test-token-0123456789',VAAK_REGISTRATION_APPROVALS='0',VAAK_ADMIN_READ_URL='http://127.0.0.1:18989/internal/admin/read')
 log=open(base/('http-'+width+'.log'),'w');worker=subprocess.Popen([str(base/'worker-http'),'serve','--bind','127.0.0.1:18989'],env=env,stdout=log,stderr=log)
 try:
  for _ in range(80):
   try:call(ref['results'][0]['request']);break
   except OSError:time.sleep(.05)
  else:raise AssertionError('worker readiness')
  for c in ref['results']:
   if 'request' not in c:continue
   if c['case']=='disabled_operator':sql(schema,"UPDATE ap_users SET disabled_at='2026-10-10 13:00:00' WHERE id=2")
   r=c['request'];status,headers,raw=call(r);check(status==200,'native200 '+width+c['mode']+c['case']);reply=json.loads(raw)
   (base/'last-html-comparison.json').write_text(json.dumps({'expected':norm(c['html']),'actual':norm(reply['html']),'case':c['case']},indent=2))
   check(norm(c['html'])==norm(reply['html']),'actual PHP DOM '+width+c['mode']+c['case']);check(reply['rows']==c['rows'],'actual PHP rows '+width+c['mode']+c['case']);check('NEVER_RETURN_HASH' not in raw,'nohash '+width+c['case']);check('no-store' in {k.lower():v for k,v in headers.items()}.get('cache-control',''),'no-store '+width+c['case']);receipts.append({'width':width,'case':c['case'],'mode':c['mode'],'response':reply})
   raw=subprocess.check_output(php+['-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so','api/bin/admin-users-native-http-consumer.php',schema,c['mode'],json.dumps(c['get']),json.dumps(c['post'])],env=env,text=True);consumer=json.loads(raw)
   check(norm(consumer['reference'])==norm(consumer['native']),'actual PHP HTTP bridge+dispatch '+width+c['mode']+c['case']);receipts.append({'width':width,'case':c['case'],'consumer':consumer})
   if c['case']=='disabled_operator':sql(schema,'UPDATE ap_users SET disabled_at=NULL WHERE id=2')
  r=ref['results'][0]['request'].copy()
  for change,label in [({'owner_id':2},'ordinary'),({'owner_id':0},'guest'),({'is_admin':True},'clientgrant'),({'search':None},'missingsearch'),({'search':' x '},'untrimmed'),({'search':'x'*4097},'large search'),({'offset':0},'zero page'),({'php_timezone':'America/New_York'},'unproven timezone')]:
   if label=='ordinary':sql(schema,'UPDATE ap_users SET is_admin=0 WHERE id=2')
   status,_,raw=call(r|change);check(status in [400,422] and 'html' not in raw,'failclosed '+width+label)
   if label=='ordinary':sql(schema,'UPDATE ap_users SET is_admin=1 WHERE id=2')
  status,_,_=call(r,'wrong');check(status==401,'private token '+width)
  for mode in ['full','shell']:
   for mutation,label in [('UPDATE ap_users SET is_admin=0 WHERE id=1','revoked'),("UPDATE ap_users SET disabled_at='now' WHERE id=1",'disabled')]:
    locker=subprocess.Popen(php+['-r',code(schema,'$d->beginTransaction();$d->exec("LOCK TABLE actor_profile IN ACCESS EXCLUSIVE MODE");echo "locked\\n";fflush(STDOUT);fgets(STDIN);$d->commit();')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    check(locker.stdout.readline().strip()=='locked','owned profile lock '+width+mode+label)
    try:
     with concurrent.futures.ThreadPoolExecutor(max_workers=1) as pool:
      future=pool.submit(call,r|{'mode':mode})
      query="SELECT COUNT(*) FROM pg_stat_activity WHERE application_name='"+app+"' AND wait_event_type='Lock' AND query LIKE '%actor_profile%'"
      for _ in range(60):
       n=int(subprocess.check_output(php+['-r',code(schema,'echo $d->query('+json.dumps(query)+')->fetchColumn();')],text=True))
       if n:break
       time.sleep(.02)
      else:raise AssertionError('Users read lock wait not observed after active admin check')
      check(not future.done(),'actual main Users read held '+width+mode+label);sql(schema,mutation);locker.communicate('\n',timeout=3);status,_,raw=future.result(timeout=5);check(status==422 and 'html' not in raw,'current role withdrawn during Users SQL '+width+mode+label)
    finally:
     if locker.poll() is None:locker.communicate('\n',timeout=3)
     sql(schema,'UPDATE ap_users SET is_admin=1,disabled_at=NULL WHERE id=1')
  for mode in ['full','shell']:
   for mutation,label in [('UPDATE ap_users SET is_admin=0 WHERE id=1','revoked'),("UPDATE ap_users SET disabled_at='now' WHERE id=1",'disabled')]:
    locker=subprocess.Popen(php+['-r',code(schema,'$d->beginTransaction();$d->exec("LOCK TABLE actor_profile IN ACCESS EXCLUSIVE MODE");echo "locked\\n";fflush(STDOUT);fgets(STDIN);$d->commit();')],stdin=subprocess.PIPE,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    check(locker.stdout.readline().strip()=='locked','owned PHP consumer profile lock '+width+mode+label)
    consumer=subprocess.Popen(php+['-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so','api/bin/admin-users-native-http-consumer.php',schema,mode,'{}','{}','role_withdrawal'],env=env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,text=True)
    try:
     query="SELECT COUNT(*) FROM pg_stat_activity WHERE application_name='"+app+"' AND wait_event_type='Lock' AND query LIKE '%actor_profile%'"
     for _ in range(60):
      n=int(subprocess.check_output(php+['-r',code(schema,'echo $d->query('+json.dumps(query)+')->fetchColumn();')],text=True))
      if n:break
      time.sleep(.02)
     else:raise AssertionError('actual PHP consumer native SQL wait not observed')
     check(consumer.poll() is None,'actual PHP consumer/native I/O held '+width+mode+label);sql(schema,mutation);locker.communicate('\n',timeout=3);out,err=consumer.communicate(timeout=5);check(consumer.returncode==0,'consumer role denial '+width+mode+label+err);result=json.loads(out)
     check(norm(result['native'])==norm('<div class="empty">Admin only.</div>'),'PHP fallback fresh current role '+width+mode+label);receipts.append({'width':width,'mode':mode,'role_withdrawal':label,'consumer':result})
    finally:
     if locker.poll() is None:locker.communicate('\n',timeout=3)
     if consumer.poll() is None:consumer.terminate();consumer.wait(timeout=3)
     sql(schema,'UPDATE ap_users SET is_admin=1,disabled_at=NULL WHERE id=1')
  for fault in ['users_off','default_off','short_secret','unavailable','remote_url']:
   c=ref['results'][0];raw=subprocess.check_output(php+['-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so','api/bin/admin-users-native-http-consumer.php',schema,'full',json.dumps(c['get']),json.dumps(c['post']),fault],env=env,text=True);x=json.loads(raw);check(x['reference']==x['native'],'byte-identical PHP fallback '+width+fault)
 finally:worker.terminate();worker.wait(timeout=5);log.close()
 check(fingerprints(schema)==before,'Users and profile read-only fingerprints '+width)
(base/'http-proof.json').write_text(json.dumps({'checks':checks,'passed':len(checks),'receipts':receipts,'scope':'Actual private HTTP/native rows/DOM and genuine extracted PHP bridge dispatch; owned role revocation during real table Lock; no whole SAPI auth/controller or production deployment.'},indent=2));print(json.dumps({'passed':len(checks),'receipts':len(receipts)}))
