#!/usr/bin/env python3
"""Complete ordered mixed-page PHP/native comparison; no expected rewrites."""
import hashlib,importlib.util,json,pathlib,sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
a=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-profile-mixed-page-reference.json');b=pathlib.Path(sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-profile-mixed-page-native.json');ref=json.loads(a.read_text());native=json.loads(b.read_text());key=lambda r:(r['width'],r['case'],r['owner']);expected={key(r):r for r in ref['captures']};fail=[];dom=state=source=effects=0
if len(expected)!=8 or len(native)!=8 or {key(r) for r in native}!=set(expected):fail.append('exact eight request identities required')
for r in native:
 x=expected[key(r)]
 if not r['native']:fail.append({'key':key(r),'fallback':r.get('error')});continue
 if m.Tokens(x['php']['html']).tokens==m.Tokens(''.join(card['html'] for card in r['cards'])).tokens:dom+=1
 else:fail.append({'key':key(r),'error':'whole ordered significant DOM/forms/text differs'})
 if [card['source_index'] for card in r['cards']]==list(range(4)) and r['source_count']==4 and r['next_offset']==x['page']['next_offset'] and r['has_more']==x['page']['has_more']:source+=1
 else:fail.append({'key':key(r),'error':'original source count/order/cursor differs'})
 if r['status_state']=={'initial':0,'remaining':0,'attempts':0} and r['quote_state']==[0,0,0] and all(x['php']['effects'][name]['value']==0 for name in ['admin_status_link_preview_budget','admin_quote_link_preview_budget']):state+=1
 else:fail.append({'key':key(r),'error':'request preview states differ'})
 if not r['actor_warm'] and not r['media_warm'] and all(not x['php']['effects'][name]['value'] for name in ['preview_http_requests','preview_warm_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests']):effects+=1
 else:fail.append({'key':key(r),'error':'declared request IO/effects differ'})
report={'requests':8,'whole_ordered_dom_matches':dom,'source_order_count_cursor_matches':source,'status_quote_state_matches':state,'zero_declared_enrichment_effect_matches':effects,'failures':fail,'reference_sha256':hashlib.sha256(a.read_bytes()).hexdigest(),'native_sha256':hashlib.sha256(b.read_bytes()).hexdigest(),'scope':'four-card single-request append PHP baseline vs native SQL selection/ordered capabilities, repeated local actor, two shared/raw orders, guest/auth; no header/HTTP/positive budgets/quote cold IO/menu effect coverage'}
output=pathlib.Path(sys.argv[3] if len(sys.argv)>3 else '/tmp/vaak-profile-mixed-page-review.json');output.write_text(json.dumps(report,indent=2));print(json.dumps(report));sys.exit(bool(fail))
