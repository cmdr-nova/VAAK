#!/usr/bin/env python3
"""Compare actual selected native quote URL cards/state and declared transports."""
import importlib.util,json,pathlib,sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
reference=json.load(open(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-local-profile-quote-url-budget-reference.json'))['captures'];native=json.load(open(sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-profile-quote-url24-integrated-native.json'))
key=lambda row:(row['width'],row['case']);refs={key(row):row for row in reference};rows={key(row):row for row in native};assert len(refs)==len(reference)==len(rows)==len(native)==len(reference) and refs.keys()==rows.keys()
failures=[];matches=0
for k,row in rows.items():
 ref=refs[k]
 positive='positive_php' in ref
 php=ref.get('positive_php',ref['php'])
 if not row['native']:failures.append([k,'native fallback',row]);continue
 if mod.Tokens(php['html']).tokens!=mod.Tokens(''.join(card['html'] for card in row['cards'])).tokens:failures.append([k,'whole card DOM mismatch'])
 else:matches+=1
 effects=php['effects'];raw_initial=ref['initial_quote_budget'];initial=raw_initial['value'] if raw_initial.get('type')=='int' else 2;final_meta=effects['admin_quote_link_preview_budget'];remaining=final_meta if positive else (final_meta['value'] if final_meta.get('type')=='int' else initial);attempts=max(0,initial-remaining)
 if row['quote_state']!=[initial,remaining,attempts]:failures.append([k,'quote state mismatch',row['quote_state']])
 if not positive:
  committed={'present':True,'type':'int','value':row['quote_state'][1]} if row['quote_state'][2]>0 else raw_initial
  if committed!=final_meta:failures.append([k,'untouched raw presence/type or attempted integer commit differs',committed,final_meta])
 if row['preview_warm']!=(effects['preview_warm_requests'] if positive else effects['preview_warm_requests']['value']):failures.append([k,'warm intent mismatch',row['preview_warm']])
 http=effects['preview_http_requests'] if positive else effects['preview_http_requests']['value'];urls=[entry if isinstance(entry,str) else entry.get('url') for entry in http]
 if row['preview_http']!=urls:failures.append([k,'HTTP intent mismatch',row['preview_http'],urls])
 if positive and row['cache_rows']!=ref['after_tables']['link_preview_cards']:failures.append([k,'actual cache row differs',row['cache_rows'],ref['after_tables']['link_preview_cards']])
report={'records':len(native),'whole_card_dom_matches':matches,'failures':failures,'scope':'selected native projector/admission/render, actual complete PHP DOM and state/HTTP/warm intents; positive captures additionally compare exact real cache row with declared clock/body; no live network or whole HTTP caller claim'}
pathlib.Path(sys.argv[3] if len(sys.argv)>3 else '/tmp/vaak-profile-quote-url24-integrated-review.json').write_text(json.dumps(report,indent=2));print(json.dumps(report));assert not failures
