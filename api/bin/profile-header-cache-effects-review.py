#!/usr/bin/env python3
"""Bounded cache payload/TTL and queue comparisons, separate from HTTP parity."""
import json,sys,pathlib,datetime,collections
rpath,npath,out=map(pathlib.Path,sys.argv[1:4]);r=json.loads(rpath.read_text());n=json.loads(npath.read_text());expected={(x['width'],x['case']):x for x in r['captures']};failures=[];cache_matches=0;queue_matches=0;reads=[]
def headerkey(k):return k.startswith('vaak:profile:') or ('-count:' in k and k.startswith('vaak:follow-graph:v1:'))
for row in n['captures']:
 original=expected[row['width'],row['case']]
 if 'error' in row:continue
 if original.get('redis_transport_effects') is not None and not original['context'].get('blocked'):
  a=original['redis_transport_effects'];b=row.get('redis_transport_effects',[])
  aset={e['set']:{'ttl':e['ttl'],'value':e['value']} for e in a if 'set' in e and headerkey(e['set'])};bset={e['set']:{'ttl':e['ttl'],'value':e['value']} for e in b if 'set' in e and headerkey(e['set'])}
  aget=collections.Counter(e['get'] for e in a if 'get' in e and headerkey(e['get']));bget=collections.Counter(e['get'] for e in b if 'get' in e and headerkey(e['get']))
  if aset!=bset:failures.append({'key':[row['width'],row['case']],'cache_sets':{'php':aset,'native':bset}})
  elif set(aget)!=set(bget):failures.append({'key':[row['width'],row['case']],'cache_get_keys':{'php':dict(aget),'native':dict(bget)}})
  else:cache_matches+=1
  if aget!=bget:reads.append({'key':[row['width'],row['case']],'php_get_counts':dict(aget),'native_get_counts':dict(bget)})
 if row['case'].startswith('bsky_') and 'after_queue' in row:
  aq=original['after_tables']['bsky_actor_refresh_queue'];bq=row['after_queue'];a={str(x['owner_user_id'])+'|'+x['actor_ref']:x for x in aq};b={str(x['owner_user_id'])+'|'+x['actor_ref']:x for x in bq}
  okay=set(a)==set(b)
  for key in set(a)&set(b):
   aa=a[key].copy();bb=b[key].copy()
   if aa.get('attempts')==0 and aa.get('status')=='pending':
    for v in (aa,bb):
     try:datetime.datetime.fromisoformat(v['queued_at'].replace('Z','+00:00'))
     except Exception:okay=False
     if v['queued_at']!=v['next_attempt_at']:okay=False
     v.pop('queued_at',None);v.pop('next_attempt_at',None)
   okay=okay and aa==bb
  if okay:queue_matches+=1
  else:failures.append({'key':[row['width'],row['case']],'queue':{'php':aq,'native':bq}})
report={'cache_payload_ttl_unique_get_key_matches':cache_matches,'queue_matches':queue_matches,'request_read_count_differences':reads,'failures':failures,'scope':'actual declared Redis input -> payload/TTL/unique GET keys; repeated request GET counts reported separately, not asserted equal; current SQL Bluesky queue effects with distinct wall-clock timestamp format/equality checks, no frozen boundary or delivery proof; blocked header cache IO deliberately excluded from ordinary effect comparison'}
out.write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps({'cache_matches':cache_matches,'queue_matches':queue_matches,'read_count_differences':len(reads),'failures':len(failures)}));sys.exit(bool(failures))
