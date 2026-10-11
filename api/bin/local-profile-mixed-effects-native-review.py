#!/usr/bin/env python3
"""Whole request comparison with actual PHP helper call lifecycle; no normalization."""
import hashlib,importlib.util,json,pathlib,sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
a=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-profile-mixed-effects-reference.json');b=pathlib.Path(sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-profile-mixed-effects-native.json');ref=json.loads(a.read_text());native=json.loads(b.read_text());key=lambda r:(r['width'],r['case'],r['owner']);expected={key(r):r for r in ref['captures']};fail=[]
assert len(ref['captures'])==len(native)==len(expected) and len(native)>0 and {key(r) for r in native}==set(expected)
for r in native:
 x=expected[key(r)];e=x['php']['effects']
 if not r['native']:fail.append([key(r),r.get('error')]);continue
 if m.Tokens(''.join(c['html'] for c in r['cards'])).tokens!=m.Tokens(x['php']['html']).tokens:fail.append([key(r),'complete DOM/forms/text'])
 initial_status=x.get('initial_status_budget',0);initial_quote=x.get('initial_quote_budget',0)
 final_status=e['admin_status_link_preview_budget']['value'];final_quote=e['admin_quote_link_preview_budget']['value']
 if r['status_state']!={'initial':initial_status,'remaining':final_status,'attempts':initial_status-final_status} or r['quote_state']!=[initial_quote,final_quote,initial_quote-final_quote]:fail.append([key(r),'both budget states'])
 if r['actor_warm']!=e['fixture_actor_warm_requests']['value'] or r['media_warm']!=e['fixture_media_warm_requests']['value']:fail.append([key(r),'ordered warm helper calls'])
 if any(e[k]['value'] for k in ['preview_http_requests','preview_warm_requests','fixture_quote_warm_requests']):fail.append([key(r),'unproved additional IO'])
 if [c['source_index'] for c in r['cards']]!=list(range(len(x['page']['items']))) or r['source_count']!=len(x['page']['items']) or r['next_offset']!=x['page']['next_offset'] or r['has_more']!=x['page']['has_more']:fail.append([key(r),'source ledger/cursor'])
report={'requests':len(native),'whole_request_matches':len(native)-len({tuple(f[0]) for f in fail}),'failures':fail,'request_elapsed_ms':[{ 'key':key(r),'ms':r['elapsed_ms']} for r in native],'reference_sha256':hashlib.sha256(a.read_bytes()).hexdigest(),'native_sha256':hashlib.sha256(b.read_bytes()).hexdigest(),'scope':ref.get('scope','actual single-request PHP append vs ordered native capabilities; exact DOM, captured budgets and helper calls; external warm delivery substituted on both sides; no full header/HTTP/quoted cold URL coverage')}
pathlib.Path(sys.argv[3] if len(sys.argv)>3 else '/tmp/vaak-profile-mixed-effects-review.json').write_text(json.dumps(report,indent=2));print(json.dumps(report));sys.exit(bool(fail))
