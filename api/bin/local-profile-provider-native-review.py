#!/usr/bin/env python3
"""Independent private source-selection review against immutable actual PHP."""
import argparse,collections,json,pathlib
p=argparse.ArgumentParser();p.add_argument('native');p.add_argument('--reference',required=True);p.add_argument('--report',required=True);a=p.parse_args();php=json.loads(pathlib.Path(a.reference).read_text());native=json.loads(pathlib.Path(a.native).read_text());expected=collections.defaultdict(collections.deque)
def add(key,page):expected[key].append({'ids':[str(r['id']) for r in page['items']],'source_count':len(page['items']),'has_more':page['has_more'],'next_offset':page['next_offset']})
for r in php['captures']:
 if r['requested_tab']=='boosts':continue
 for caller,page,limit,offset,tab in [('provider',r['producer'],r['paging']['limit'],r['paging']['offset'],r['requested_tab']),('full',r['full_selected'],12,0,r['requested_tab']),('append',r['append']['page'],max(10,min(50,r['paging']['limit'])),r['paging']['offset'],r['append']['effective_tab'])]:
  add((r['width'],r['owner'],r['actor'],r['requested_tab'],tab,caller,limit,offset),page)
for r in php['alias_controls']:
 if r['tab']=='boosts':continue
 add((r['width'],json.dumps(r['input'],sort_keys=True),r['tab'],'provider_alias'),r['page'])
failures=[];matches=0
for r in native['captures']:
 key=(r['width'],json.dumps(r['input'],sort_keys=True),r['selected_tab'],'provider_alias') if r['caller']=='provider_alias' else (r['width'],r['owner'],r['actor'],r['requested_tab'],r['selected_tab'],r['caller'],r['limit'],r['offset'])
 if not expected[key]:failures.append({'key':key,'error':'unbound or duplicate native row'});continue
 target=expected[key].popleft();actual={k:r[k] for k in target}
 if actual!=target:failures.append({'key':key,'expected':target,'actual':actual})
 else:matches+=1
missing=sum(len(v) for v in expected.values());assert not missing,missing
report={'native_records':len(native['captures']),'exact_source_order_count_cursor_matches':matches,'failures':failures,'scope':'actual private fetch source selection only; no whole profile painter/header/admission/HTTP or Boosts replacement claim'};pathlib.Path(a.report).write_text(json.dumps(report,indent=2));print(json.dumps(report,indent=2));raise SystemExit(bool(failures))
