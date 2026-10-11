#!/usr/bin/env python3
"""Actual PHPcurl -> actual Rust Library bridge with isolated durable PG facts."""
import json,os,pathlib,re,subprocess,threading,time,urllib.request,urllib.error
from http.server import BaseHTTPRequestHandler,ThreadingHTTPServer
root=pathlib.Path(__file__).resolve().parents[2]
worker=os.environ['LIBRARY_REVIEW_WORKER'];container=os.environ.get('LIBRARY_FIXTURE_CONTAINER','vaak-library-facts-1010');socket=os.environ.get('LIBRARY_FIXTURE_SOCKET','/tmp/vaak-library-facts-pg-socket')
source=subprocess.check_output(['python3',str(root/'api/bin/library-facts-postgres-fixture.py'),'--emit-rust-harness'],text=True)
records=[];output=[]
class Proxy(BaseHTTPRequestHandler):
 def log_message(self,*args):pass
 def do_POST(self):
  raw=self.rfile.read(int(self.headers['Content-Length']));req=urllib.request.Request('http://127.0.0.1:18835'+self.path,data=raw,headers={'Content-Type':'application/json','X-VAAK-Library-Token':self.headers.get('X-VAAK-Library-Token','')})
  try:
   with urllib.request.urlopen(req,timeout=10) as r:code=r.status;body=r.read()
  except urllib.error.HTTPError as r:code=r.code;body=r.read()
  records.append({'width':current_width,'request':json.loads(raw),'code':code,'reply':json.loads(body)})
  self.send_response(code);self.end_headers();self.wfile.write(body)
proxy=ThreadingHTTPServer(('127.0.0.1',18834),Proxy);threading.Thread(target=proxy.serve_forever,daemon=True).start()
try:
 for current_width in ['integer','bigint']:
  old='library_facts_'+current_width;schema='search_context_review_'+current_width
  sql=next(json.loads(x) for x in re.findall(r'db.batch_execute\(("(?:[^"\\]|\\.)*")\)\.await',source) if 'CREATE SCHEMA '+old in json.loads(x)).replace(old,schema)
  sql="DROP SCHEMA IF EXISTS "+schema+" CASCADE;"+sql+";DELETE FROM ap_user_blocks;DELETE FROM ap_mutes;DELETE FROM ap_muted_words;DELETE FROM ap_deprioritized_actors;INSERT INTO remote_media_cache VALUES('https://quote.test/users/p','avatar','https://mkultra.monster/img/avatar/default.webp');INSERT INTO remote_media_cache VALUES('https://quote.test/users/q','avatar','https://mkultra.monster/img/avatar/default.webp');"
  subprocess.run(['docker','exec','-i',container,'psql','-U','postgres','-d','vaak_search_context_fixture','-q'],input=sql,text=True,check=True,stdout=subprocess.DEVNULL)
  env={**os.environ,'VAAK_ENV_FILE':'/tmp/nonexistent-library-fixture-env','VAAK_PHYRIAN_BRIDGE_ENV_FILE':'/tmp/nonexistent-library-fixture-env','VAAK_DATABASE_URL':f"host={socket} user=postgres dbname=vaak_search_context_fixture options='-c search_path={schema}'",'VAAK_LIBRARY_NATIVE_RENDER':'1','VAAK_LIBRARY_NATIVE_SECRET':'library-quote-review-secret-0000000001','VAAK_LIBRARY_NATIVE_URL':'http://127.0.0.1:18834/internal/library/render','LIBRARY_ACCEPT_BIND':'127.0.0.1:18835','RUST_LOG':'error'}
  proc=subprocess.Popen([worker],env=env,stdout=subprocess.DEVNULL,stderr=subprocess.PIPE)
  try:
   for _ in range(100):
    try:urllib.request.urlopen('http://127.0.0.1:18835/internal/library/render',timeout=1)
    except urllib.error.HTTPError:break
    except urllib.error.URLError:time.sleep(.02)
   cmd=[str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so',str(root/'api/bin/library-quote-preview-independent.php'),schema,'1']
   result=subprocess.run(cmd,input='{}',text=True,capture_output=True,env=env);assert result.returncode==0,result.stderr
   output.extend(json.loads(result.stdout))
  finally:
   proc.terminate();proc.wait(timeout=5);pathlib.Path("/tmp/vaak-library-quote-server-"+current_width+".log").write_bytes(proc.stderr.read())
   subprocess.run(['docker','exec',container,'psql','-U','postgres','-d','vaak_search_context_fixture','-q','-c','DROP SCHEMA '+schema+' CASCADE'],check=True,stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
finally:proxy.shutdown()
pathlib.Path('/tmp/vaak-library-quote-preview-pairs.json').write_text(json.dumps(output,ensure_ascii=False,indent=2))
pathlib.Path('/tmp/vaak-library-quote-preview-http.json').write_text(json.dumps(records,ensure_ascii=False,indent=2))
print(json.dumps({'pairs':len(output),'http_requests':len(records),'http_codes':[r['code'] for r in records],'effects_mismatches':[{'schema':p['schema'],'case':p['case'],'view':p['view'],'php':p['php']['effects'],'native':p['native']['effects']}for p in output if p['php']['effects']!=p['native']['effects']]},indent=2))
# Executable exact caller contract: significant DOM bytes plus all IO counters.
# Native responses are from the real bridge, never generated HTML from this proxy.
import runpy
Tokens=runpy.run_path(str(root/'api/bin/search-native-acceptance-review.py'))['Tokens']
failures=[]
for index,pair in enumerate(output):
 if Tokens(pair['php']['html']).tokens!=Tokens(pair['native']['html']).tokens or pair['php']['effects']!=pair['native']['effects']:
  failures.append({'index':index,'schema':pair['schema'],'case':pair['case'],'view':pair['view'],'lazy':pair['lazy_media']})
 if pair['case'].startswith('private_media_'):
  visible=pair['case'] in ('private_media_follower','private_media_recipient')
  for painter in ['php','native']:
   assert ('Plain complete quoted marker' in pair[painter]['html'])==visible,(pair['case'],painter)
 if pair['case'].startswith('cached_'):
  for painter in ['php','native']:assert 'Actual cached card' in pair[painter]['html']
assert len(output)==96 and len(records)==48,(len(output),len(records))
for record in records:
 assert record['code']==200 and record['reply'].get('ok') is True
 body=record['request'];assert body['owner_id']==1 and body['csrf']=='canonical-current-session'
 assert body['return_view'] in ('bookmarks','favourites')
 child=body['statuses'][0]['quote']['quoted_status']
 assert child.get('media_attachments') or isinstance(child.get('card'),dict),'dynamic preview sent an RPC'
pathlib.Path('/tmp/vaak-library-quote-preview-comparison.json').write_text(json.dumps({'pairs':96,'matching':96-len(failures),'failures':failures,'native_requests':48,'native_codes':[r['code'] for r in records]},indent=2))
assert not failures,failures
print('library quote-preview independent PASS 96 exact DOM+effects; 48 actual native200; dynamic complete quotes preserve canonical PHP; current grants and withdrawal checked')
