#!/usr/bin/env python3
import os,json,subprocess,tempfile,shutil,time,urllib.request,urllib.error
from pathlib import Path
b=Path('rust/target/parity-evidence/admin-native-invites-approvals-phase');results=[]
for width in ['integer','bigint']:
 schema=json.loads((b/('php-pg-'+width+'.json')).read_text())['schema']
 for view in ['invites','approvals']:
  for mode in ['full','shell']:
   for fault in ['default_off','short_secret','unavailable','remote_url']:
    env=os.environ.copy();env.update(VAAK_ADMIN_READ_SECRET='native-admin-test-token-0123456789',VAAK_REGISTRATION_APPROVALS='1')
    r=subprocess.run(['api/bin/php-policy-test','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so','api/bin/admin-native-http-consumer.php',schema,view,mode,fault],env=env,capture_output=True,text=True,timeout=4)
    assert r.returncode==0,r.stderr;j=json.loads(r.stdout);assert j['reference']==j['native'],(width,view,mode,fault);results.append({'width':width,'view':view,'mode':mode,'fault':fault,'dispatch_sha256':j['dispatch_sha256']})
p=tempfile.mkdtemp(prefix='vaak-admin-library-');log=open(b/'direct-library-http.log','w');server=None
try:
 shutil.copy2('api/ap-admin-native.php',Path(p)/'ap-admin-native.php');server=subprocess.Popen(['php','-S','127.0.0.1:18791','-t',p],stdout=log,stderr=log)
 for _ in range(40):
  try:
   urllib.request.urlopen('http://127.0.0.1:18791/ap-admin-native.php',timeout=1);raise AssertionError('library exposed')
  except urllib.error.HTTPError as e:
   assert e.code==403 and 'no-store' in e.headers.get('Cache-Control','') and e.read().decode()=='Library only';results.append({'direct_library_status':403,'no_store':True});break
  except OSError:time.sleep(.05)
 else:raise AssertionError('library fixture readiness')
finally:
 if server:server.terminate();server.wait(timeout=3)
 log.close();shutil.rmtree(p)
(b/'fallback-proof.json').write_text(json.dumps({'passed':len(results),'results':results},indent=2));print(json.dumps({'passed':len(results)}))
