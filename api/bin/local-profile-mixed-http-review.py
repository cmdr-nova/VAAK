#!/usr/bin/env python3
"""Verify actual native wire replies and unchanged PHP consumer against PHP pages."""
import hashlib,importlib.util,json,pathlib,sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
a=pathlib.Path('/tmp/vaak-profile-mixed-page-reference.json');b=pathlib.Path('/tmp/vaak-profile-mixed-http-native.json');ref=json.loads(a.read_text());native=json.loads(b.read_text());key=lambda r:(r['width'],r['case'],r['owner']);expected={key(r):r for r in ref['captures']};fail=[]
assert len(ref['captures'])==len(native)==len(expected)==8 and {key(r) for r in native}==set(expected)
for r in native:
 x=expected[key(r)];reply=r['reply'];consumer=r['php_consumer'];html=''.join(c['html'] for c in reply['cards']);binding={k:r['request'][k] for k in ['actor_key','actor','tab','mode','offset','limit']}
 if r['status']!=200 or reply['request_binding']!=binding:fail.append([key(r),'wire/binding'])
 if m.Tokens(html).tokens!=m.Tokens(x['php']['html']).tokens:fail.append([key(r),'complete PHP DOM'])
 if consumer['reply']['html']!=html or consumer['reply']['complete_native'] is not True:fail.append([key(r),'PHP consumer'])
 if reply['page']!={'source_count':4,'next_offset':x['page']['next_offset'],'has_more':x['page']['has_more']} or [c['source_index'] for c in reply['cards']]!=[0,1,2,3]:fail.append([key(r),'source ledger'])
 for field in ['status_preview_state','quote_preview_state']:
  if reply[field]!={'initial':0,'remaining':0,'attempts':0}:fail.append([key(r),field])
 if consumer['status_budget']!=0 or consumer['quote_budget']!=0:fail.append([key(r),'both PHP state globals'])
report={'requests':8,'http200_php_consumer_dom_matches':8-len({tuple(f[0]) for f in fail}),'failures':fail,'reference_sha256':hashlib.sha256(a.read_bytes()).hexdigest(),'native_sha256':hashlib.sha256(b.read_bytes()).hexdigest(),'scope':'actual native HTTP auth/decode/select/pipeline/reply and production PHP fetch/validator, private fixture transport/clock; current four-card append only; not full outer header, positive enrichment, or production activation'}
pathlib.Path('/tmp/vaak-profile-mixed-http-review.json').write_text(json.dumps(report,indent=2));print(json.dumps(report));sys.exit(bool(fail))
