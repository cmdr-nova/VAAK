#!/usr/bin/env python3
"""Compare immutable actual PHP Boosts rows with real private native fetch."""
import hashlib,json,sys
from pathlib import Path
p=Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-local-profile-boosts-reference.json')
n=Path(sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-local-profile-boost-contract-native.json')
php=json.loads(p.read_text());native=json.loads(n.read_text());expected={}
def key(width,state,actor_key,actor,owner,limit,offset,caller):return (width,state,actor_key,actor,owner,limit,offset,caller)
for r in php['captures']:
    k=key(r['width'],r['state'],r['input']['key'],r['input']['actor'],None,**r['paging'],caller='provider')
    if k in expected:raise AssertionError('duplicate source intent')
    expected[k]=r['page']
for r in php['callers']:
    if r['effective_tab']!='boosts':continue
    for caller,page,limit,offset in [('full',r['full_selected'],12,0),('append',r['append']['page'],10,3)]:
        if page is None:continue
        expected[key(r['width'],r['state'],r['profile_key'],r['actor'],r['owner'],limit,offset,caller)]=page
seen=set()
for r in native['captures']:
    k=key(r['width'],r['state'],r['actor_key'],r['requested_actor'],r['owner'],r['limit'],r['offset'],r['caller'])
    if k in seen or k not in expected:raise AssertionError(('unexpected intent',k))
    seen.add(k);page=expected[k]
    for value,actual in [('items',r['selected_sources']),('has_more',r['has_more']),('next_offset',r['next_offset'])]:
        if json.dumps(page[value],sort_keys=True)!=json.dumps(actual,sort_keys=True):raise AssertionError((k,value))
    if r['source_count']!=len(page['items']):raise AssertionError(('source_count',k))
if seen!=set(expected):raise AssertionError('missing intents')
report={'pass':True,'provider':216,'full_source_pages':54,'append_source_pages':46,'exact_complete_rows_and_types':len(seen),'php_sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'native_sha256':hashlib.sha256(n.read_bytes()).hexdigest(),'scope':'real private fetch only; full blocked source pages are diagnostic prepaint selection; no card/HTTP/authority parity claim'}
out=Path(sys.argv[3] if len(sys.argv)>3 else '/tmp/vaak-local-profile-boosts-native-review.json');out.write_text(json.dumps(report,indent=2));print(json.dumps(report))
