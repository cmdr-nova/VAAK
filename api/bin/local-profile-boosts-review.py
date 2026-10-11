#!/usr/bin/env python3
"""Independent source/cursor/current-caller assertions, not a native oracle."""
import hashlib,json,sys
from pathlib import Path
path=Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-local-profile-boosts-reference.json')
x=json.loads(path.read_text()); checks=0
def check(value, message):
    global checks
    if not value: raise AssertionError(message)
    checks+=1
def page(tables,actor,key,limit,offset):
    actor=actor.strip().rstrip('/') or 'https://mkultra.monster/users/'+key.strip().lower()
    limit=max(1,min(50,limit));offset=max(0,offset)
    selected={}
    for row in tables['events']:
        if row['type']!='Announce' or row['actor_id'] not in (actor,actor+'/'):continue
        rank=(row['action_taken']=='boost_ok',row['created_at'] or '',int(row['id']))
        previous=selected.get(row['object_id'])
        if previous is None or rank>previous[0]:selected[row['object_id']]=(rank,row)
    ordered=sorted([v[1] for v in selected.values()],key=lambda r:(r['created_at'] or '',int(r['id'])),reverse=True)
    rows=ordered[offset:offset+limit]
    return {'items':rows,'has_more':len(ordered)>offset+limit,'next_offset':offset+len(rows)}
for r in x['captures']:
    p=page(r['current_tables'],r['input']['actor'],r['input']['key'],**r['paging'])
    check(r['page']==p,'exact selected full rows/order/cursor '+str((r['width'],r['state'],r['input'],r['paging'])))
    check(r['selected_ids']==[str(a['id']) for a in p['items']],'source IDs')
    if r['state']=='reblogs_only':check(not p['items'],'saved reblog must not become ledger Announce')
    if r['state']=='private_announce' and r['input']['actor'].lower().rstrip('/')!=r['input']['actor'] and r['input']['actor']:continue
    if r['state']=='private_announce' and r['paging']['offset']==0 and not r['input']['actor'].isupper():check(any(a['visibility']=='private' for a in p['items']),'private source selection is retained')
for r in x['callers']:
    check(r['effective_tab']==('posts' if r['hide_boosts'] else 'boosts'),'current target hide flag')
    blocked=r['state']=='server_block' or (r['state']=='personal_block' and r['owner']==1)
    check(r['full_root_blocked']==blocked,'current root block predicate')
    if blocked:
        check(not r['full_html'] and not r['append_html'] and r['append']['page'] is None,'blocked no body/provider')
        check(not r['calls']['page'] and not r['calls']['axum'],'blocked before page/Axum')
        check('X-Has-More: 0' in r['headers'] and 'X-Next-Offset: 3' in r['headers'],'blocked preserves normalized cursor')
    elif not r['hide_boosts']:
        check(r['full_selected']==page(r['current_tables'],r['actor'],r['profile_key'],12,0),'full12 original page')
        check(r['append']['page']==page(r['current_tables'],r['actor'],r['profile_key'],10,3),'append clamp10 offset3 source page')
        check(r['append']['effective_tab']=='boosts','append current tab')
guest=[r for r in x['callers'] if r['owner']==0 and r['state']=='baseline' and not r['hide_boosts']]
check(all('name="action"' in r['append_html'] for r in guest),'guest Boost action-form discrepancy remains actual; no handler authorization claim')
report={'checks':checks,'provider_captures':len(x['captures']),'caller_captures':len(x['callers']),'sha256':hashlib.sha256(path.read_bytes()).hexdigest(),'pass':True,'scope':'exact producer row/order/raw cursor, current hide tab and root block assertions; no full SAPI/native/painter parity or valid Undo lineage claim'}
out=Path(sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-local-profile-boosts-review.json');out.write_text(json.dumps(report,indent=2));print(json.dumps(report))
