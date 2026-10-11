#!/usr/bin/env python3
"""Actual saved collector, mapper, complete caller -> native handler replay.

This requires the owned PostgreSQL/loopback fixture worker. External lookup,
session and worker delivery remain declared. No original reference is rewritten.
"""
import hashlib, base64, collections, datetime, importlib.util, io, json, os, pathlib, subprocess, threading, time, urllib.request, urllib.error
from http.server import ThreadingHTTPServer
root=pathlib.Path(__file__).resolve().parents[2]
spec=importlib.util.spec_from_file_location('transport',root/'api/bin/preview-state-response-fixture.py')
transport=importlib.util.module_from_spec(spec);spec.loader.exec_module(transport)
token_spec=importlib.util.spec_from_file_location('tokens',root/'api/bin/search-native-acceptance-review.py')
tokens=importlib.util.module_from_spec(token_spec);token_spec.loader.exec_module(tokens)
reference=pathlib.Path(os.environ['SEARCH_NATIVE_REFERENCE'])
prefix=os.environ.get('SEARCH_NATIVE_PREFIX','/tmp/vaak-search-collector-native')
env={**os.environ,'ROOT_NATIVE_ACCEPT':'1','LIBRARY_FIXTURE_SOCKET':'/tmp/vaak-library-facts-pg-socket',
 'VAAK_SEARCH_NATIVE_SECRET':'independent-preview-fixture-token-0001'}
servers=[];record_paths=[];negative_checks=[];native_timings=[]
class ProducerHandler(transport.Handler):
 def do_POST(self):
  raw=self.rfile.read(int(self.headers.get('Content-Length','0')));body=json.loads(raw)
  mutations=[]
  for label,field,value in [('wrong actual query','normalized_query','wrong-query-no-window'),('wrong ordinal','selected_ordinals',[99999]),('wrong digest','cache_sha256','0'*64),('client-supplied path','cache_location','/tmp/arbitrary'),('wrong resolve','resolve',True)]:
   wrong=json.loads(raw);wrong['collector'][field]=value;mutations.append((label,wrong))
  wrong=json.loads(raw);wrong['owner_id']=2;mutations.append(('foreign owner window',wrong))
  wrong=json.loads(raw);wrong['statuses'][0]['content']='supplied replacement body';mutations.append(('different supplied source',wrong))
  if os.environ.get('SEARCH_NATIVE_SKIP_NEGATIVES')=='1':mutations=[]
  for label,mutated in mutations:
   effect_path=pathlib.Path(env.get('ROOT_NATIVE_WARM_EFFECTS_PREFIX','/tmp/vaak-library-search-warm-effects')+'-'+self.server.width.lower()+'.json')
   before=json.loads(effect_path.read_text()) if effect_path.exists() else {'actor_warm':[],'media_warm':[]}
   before={key:before[key] for key in ('actor_warm','media_warm')}
   request=urllib.request.Request(self.server.upstream+self.path,data=json.dumps(mutated).encode(),headers={'Content-Type':'application/json','X-VAAK-Search-Token':env['VAAK_SEARCH_NATIVE_SECRET']})
   try:
    with urllib.request.urlopen(request,timeout=4) as reply:status,payload=reply.status,reply.read()
   except urllib.error.HTTPError as reply:status,payload=reply.code,reply.read()
   after=json.loads(effect_path.read_text()) if effect_path.exists() else {'actor_warm':[],'media_warm':[]}
   after={key:after[key] for key in ('actor_warm','media_warm')}
   assert status==422 and before==after,(label,status,'no enrichment effect allowed')
   negative_checks.append({'width':self.server.width,'case':label,'status':status,'recorded_effects_unchanged':before==after,'request':mutated,'response_base64':base64.b64encode(payload).decode()})
  self.rfile=io.BytesIO(raw);started=time.monotonic();super().do_POST();native_timings.append({'width':self.server.width,'elapsed_seconds':time.monotonic()-started,'sources':len(body['statuses'])})
for width,port in [('INTEGER',18874),('BIGINT',18875)]:
 path=pathlib.Path(prefix+'-'+width.lower()+'-requests.jsonl');path.write_text('');record_paths.append(path)
 server=ThreadingHTTPServer(('127.0.0.1',0),ProducerHandler);server.width=width
 server.mode='forward';server.record=str(path);server.upstream=f'http://127.0.0.1:{port}'
 threading.Thread(target=server.serve_forever,daemon=True).start();servers.append(server)
 env['ROOT_SEARCH_URL_'+width]=f'http://127.0.0.1:{server.server_port}/internal/search/render'
 env['ROOT_NATIVE_TRACE_'+width]=str(path)
try:
 actual_path=pathlib.Path(prefix+'-actual.json')
 expected=json.loads(reference.read_text())
 env["SEARCH_FIXTURE_QUERY"]=expected["captures"][0]["query"]
 command=[str(root/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so','-d','extension=/tmp/vaak-php-curl-test/extracted/usr/lib/php/20230831/curl.so',str(root/'api/bin/search-collector-producer-reference.php')]
 run=subprocess.run(command+[str(actual_path)],env=env,capture_output=True,text=True)
 pathlib.Path(prefix+'-php.log').write_text(run.stdout+run.stderr)
 assert run.returncode==0,(run.stdout,run.stderr)
 actual=json.loads(actual_path.read_text())
 key=lambda row:(row['width'],row['case'])
 expected_rows={key(row):row for row in expected['captures']};actual_rows={key(row):row for row in actual['captures']}
 assert expected_rows.keys()==actual_rows.keys(),'actual complete producer keyset'
 failures=[];counts=collections.Counter();cards=0;empty=0;cases=[]
 def action_clock_projection(value,action,execution):
  # Actual PHP gmdate('c') is the only dynamic action field. Preserve raw
  # artifacts, validate its execution interval, and compare every other value.
  if isinstance(value,list):return [action_clock_projection(item,action,execution) for item in value]
  if not isinstance(value,dict):return value
  result={key:action_clock_projection(item,action,execution) for key,item in value.items() if key not in ('execution_wall_start','execution_wall_end')}
  if all(key in value for key in ('account_id','actor_id','updated_at')):
   stamp=datetime.datetime.fromisoformat(value['updated_at']).timestamp()
   assert execution['execution_wall_start']<=stamp<=execution['execution_wall_end'],('actual account mapping clock escaped execution',value)
   result['updated_at']='<actual account mapping effect clock within execution>'
  if action and all(key in value for key in ('status_id','object_id','owner_user_id','created_at')):
   stamp=datetime.datetime.fromisoformat(value['created_at']).timestamp()
   # Seeded pre-action baseline rows retain their literal timestamp. Only
   # inserted action rows are bounded by the recorded actual execution clock.
   if value['created_at'] not in ('2026-10-10T12:00:00Z','2026-10-10T11:00:00Z','2026-10-10T11:30:00Z'):
    assert action['execution_wall_start']<=stamp<=action['execution_wall_end'],('actual action clock escaped execution',value)
    result['created_at']='<actual PHP gmdate within recorded action execution>'
  return result
 for name,wanted in expected_rows.items():
  got=actual_rows[name]
  for captured in [wanted,got]:
   cache_bytes=base64.b64decode(captured["actual_cache_base64"]);cached=json.loads(cache_bytes)
   assert len(cached["statuses"])==captured["actual_cache_candidates"]==min(40,captured["actual_raw_count"]),"actual sanitizer count"
   assert hashlib.sha256(cache_bytes).hexdigest()==captured["collector_calls"][0]["bundle"]["_native_collector"]["cache_sha256"],"actual cache receipt digest"
  for field in ['php','before_tables','after_tables','collector_calls']:
   left,right=wanted[field],got[field]
   left=action_clock_projection(left,{},expected);right=action_clock_projection(right,{},actual)
   if field=='php':
    left=json.loads(json.dumps(left));right=json.loads(json.dumps(right))
    left['result']['html']=tokens.Tokens(left['result']['html']).tokens
    right['result']['html']=tokens.Tokens(right['result']['html']).tokens
   if left!=right:failures.append({'key':name,'field':field})
  trace=got['native_trace'];data=pathlib.Path(trace['path']).read_bytes()[trace['start']:trace['end']]
  requests=[json.loads(line) for line in data.splitlines()]
  emitted=sum(len(call['bundle']['statuses']) for call in wanted['collector_calls'])
  if emitted==0:
   assert not requests,(name,'empty collector has no renderer call');empty+=1;continue
  assert len(requests)==1,(name,'one actual complete batch',len(requests))
  request=requests[0];counts[request['response_status']]+=1
  cases.append({'key':name,'emitted':emitted,'response_status':request['response_status']})
  if request['response_status']==200:
   reply=json.loads(base64.b64decode(request['response_base64']));cards+=len(reply['statuses'])
   body=json.loads(base64.b64decode(request['request_base64']))
   assert body['collector']['cache_sha256']==hashlib.sha256(base64.b64decode(got['actual_cache_base64'])).hexdigest(),(name,'worker exact cache digest')
   assert len(body['collector']['selected_ordinals'])==len(body['statuses']),(name,'producer capability per emitted source')
 pathlib.Path(prefix+'-negative-controls.json').write_text(json.dumps(negative_checks,indent=2))
 report={'native_timings':native_timings,'checks':len(actual_rows),'negative_controls':len(negative_checks),'empty_collector_cases':empty,'response_counts':dict(counts),'native_cards':cards,'cases':cases,'failures':failures,
  'passed':not failures and counts[200]+empty==len(actual_rows),
  'scope':'Actual Search SQL collector/current filter/cache window/slice/complete caller DOM/state/effects; session/external lookup/worker delivery declared'}
 pathlib.Path(prefix+'-review.json').write_text(json.dumps(report,indent=2));print(json.dumps(report,indent=2))
 assert report['passed'],'actual Search producer native parity incomplete'
finally:
 for server in servers:server.shutdown();server.server_close()
