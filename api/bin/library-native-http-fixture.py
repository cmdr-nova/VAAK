#!/usr/bin/env python3
"""Actual inactive HTTP adapter controls; explicit disposable PG container only.
No production env/credentials. Worker remains default off outside subprocess.
"""
import json, os, pathlib, re, subprocess, time, urllib.request, urllib.error
root=pathlib.Path(__file__).resolve().parents[2]
container=os.environ['LIBRARY_FIXTURE_CONTAINER']
socket=os.environ['LIBRARY_FIXTURE_SOCKET']
source=subprocess.check_output(['python3',str(root/'api/bin/library-facts-postgres-fixture.py'),'--emit-rust-harness'],text=True)
sql=next(json.loads(m) for m in re.findall(r'db.batch_execute\(("(?:[^"\\]|\\.)*")\)\.await',source) if 'CREATE SCHEMA library_facts_integer' in json.loads(m))
subprocess.run(['docker','exec','-i',container,'psql','-U','postgres'],input=sql,text=True,check=True,stdout=subprocess.DEVNULL)
token='fixture-internal-token-32-bytes-long'
env={**os.environ,'VAAK_ENV_FILE':'/tmp/nonexistent-library-fixture-env','VAAK_PHYRIAN_BRIDGE_ENV_FILE':'/tmp/nonexistent-library-fixture-env','VAAK_DATABASE_URL':f"host={socket} user=postgres dbname=postgres options='-c search_path=library_facts_integer'",'VAAK_LIBRARY_NATIVE_RENDER':'1','VAAK_LIBRARY_NATIVE_SECRET':token,'RUST_LOG':'error'}
worker=root/'rust/target/debug/vaak-worker'
port=18789
proc=subprocess.Popen([str(worker),'serve','--bind',f'127.0.0.1:{port}'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
url=f'http://127.0.0.1:{port}/internal/library/render'
def request(body,secret=token):
 data=json.dumps(body).encode() if not isinstance(body,bytes) else body
 headers={'Content-Type':'application/json'}
 if secret is not None:headers['X-VAAK-Library-Token']=secret
 try:
  with urllib.request.urlopen(urllib.request.Request(url,data=data,headers=headers),timeout=5) as r:return r.status,r.read()
 except urllib.error.HTTPError as r:return r.code,r.read()
try:
 for _ in range(100):
  try:request(b'{}',None);break
  except urllib.error.URLError:time.sleep(.02)
 assert request(b'not json',None)[0]==401,'auth before decode'
 assert request(b'not json','incorrect')[0]==401
 assert request(b'not json')[0]==400
 batch={'owner_id':1,'csrf':'canonical-server-csrf','return_view':'bookmarks','bsky_enabled':False,'statuses':[]}
 assert request({**batch,'is_admin':True})[0]==400
 assert request({**batch,'owner_id':0})[0]==400
 assert request({**batch,'statuses':[{}]*101})[0]==400
 assert request(b' '* (2*1024*1024+1))[0]==413
 code,reply=request(batch);assert code==200 and json.loads(reply)['cards']==[]
 assert request({**batch,'owner_id':999})[0]==422,'inactive/missing DB owner'
 own={'id':'9','uri':'https://mkultra.monster/users/viewer/notes/own','content':'Cached stale body','visibility':'private','account':{'uri':'https://mkultra.monster/users/viewer','acct':'viewer@mkultra.monster'}}
 code,reply=request({**batch,'statuses':[own]});assert code==200,(code,reply)
 cards=json.loads(reply)['cards'];assert len(cards)==1 and 'Current private body' in cards[0] and 'canonical-server-csrf' in cards[0]
 code,reply=request({**batch,'owner_id':2,'statuses':[own]});assert code==200 and json.loads(reply)['cards']==[],'foreign owner cannot see private body'
 unsupported={'id':'thin','uri':'https://remote.test/n','content':'','account':{'acct':'?'}}
 code,reply=request({**batch,'statuses':[own,unsupported]});assert code==422 and 'cards' not in json.loads(reply),'whole batch fallback has no partial HTML'
 proc.terminate();proc.wait(timeout=5)
 env['VAAK_LIBRARY_NATIVE_RENDER']='0'
 proc=subprocess.Popen([str(worker),'serve','--bind',f'127.0.0.1:{port}'],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
 for _ in range(100):
  try:code,_=request(batch);break
  except urllib.error.URLError:time.sleep(.02)
 assert code==404,'default-off route absent'
 refused=subprocess.run([str(worker),'serve','--bind','0.0.0.0:18790'],env=env,capture_output=True,timeout=5)
 assert refused.returncode!=0 and b'refuse non-loopback' in refused.stderr
 print('library-native-http: PASS (actual HTTP auth/decode/limits, defaultoff/nonloopback, current owner private render, foreign owner denial, wholebatch no partial HTML)')
finally:
 proc.terminate();proc.wait(timeout=5)
 subprocess.run(['docker','exec',container,'psql','-U','postgres','-c','DROP SCHEMA IF EXISTS library_facts_integer CASCADE'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
