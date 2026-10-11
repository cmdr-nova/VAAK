#!/usr/bin/env python3
"""Actual private Users route remains disabled separately from accepted Admin flag."""
import os,json,time,subprocess,urllib.request,urllib.error
from pathlib import Path
base=Path(os.environ.get('ADMIN_USERS_PHASE','rust/target/parity-evidence/admin-users-native-phase'));ref=json.loads((base/'php-final-integer.json').read_text());env=os.environ.copy();env.update(VAAK_ENV_FILE='/nonexistent',VAAK_PHYRIAN_BRIDGE_ENV_FILE='/nonexistent',VAAK_ADMIN_READ_NATIVE='1',VAAK_ADMIN_USERS_NATIVE='0',VAAK_ADMIN_READ_SECRET='users-native-test-token-0123456789',VAAK_REGISTRATION_APPROVALS='0',VAAK_DATABASE_URL="host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options='-c search_path="+ref['schema']+"'")
log=open(base/'disabled-http.log','w');p=subprocess.Popen([str(base/'worker-http-v2'),'serve','--bind','127.0.0.1:18990'],env=env,stdout=log,stderr=log);checks=[]
def call(r):
 q=urllib.request.Request('http://127.0.0.1:18990/internal/admin/read',json.dumps(r).encode(),{'Content-Type':'application/json','X-Vaak-Admin-Token':env['VAAK_ADMIN_READ_SECRET']})
 try:
  with urllib.request.urlopen(q,timeout=4)as x:return x.status,dict(x.headers),x.read().decode()
 except urllib.error.HTTPError as x:return x.code,dict(x.headers),x.read().decode()
try:
 for _ in range(80):
  try:call(ref['results'][0]['request']);break
  except OSError:time.sleep(.05)
 else:raise AssertionError('worker readiness')
 for mode in ['full','shell']:
  status,headers,raw=call(ref['results'][0]['request']|{'mode':mode});assert status==422 and 'html' not in raw and 'no-store' in {k.lower():v for k,v in headers.items()}.get('cache-control','');checks.append(mode+' native Users dedicated flag disabled')
finally:p.terminate();p.wait(timeout=5);log.close()
(base/'disabled-http-proof.json').write_text(json.dumps({'checks':checks,'passed':len(checks),'scope':'Actual private Users read refusal with general accepted Admin read flag enabled, dedicated Users flag off; no activation.'},indent=2));print(len(checks),'actual native Users disabled gates passed')
