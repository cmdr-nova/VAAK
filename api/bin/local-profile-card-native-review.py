#!/usr/bin/env python3
"""Compare complete genuine PHP cards; fallback is not native acceptance."""
import collections,importlib.util,json,pathlib,sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('profile_tokens',here/'search-native-acceptance-review.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
reference_path=pathlib.Path(sys.argv[3]) if len(sys.argv)>3 else None
reference_rows=json.loads(reference_path.read_text())['captures'] if reference_path else []
reference={(r['width'],r['case']):r for r in reference_rows}
path=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-local-profile-card-native-diagnostic.json');rows=json.loads(path.read_text());counts=collections.Counter();failures=[];fallbacks=[];matched=0
for r in rows:
 result=r['result'];key=(r['width'],r['case']);cards=result.get('cards',[])
 if result.get('original_source')!=r['original_source']:failures.append({'key':key,'error':'source changed'})
 if 'fallback' in result:
  counts['declared_fallback']+=1;fallbacks.append({'key':key,'reason':result['fallback']})
  if cards:failures.append({'key':key,'error':'fallback returned partial HTML'})
  continue
 counts['native_positive' if cards else 'native_omission']+=1
 a=m.Tokens(r['php']['html']).tokens;b=m.Tokens(''.join(c['html'] for c in cards)).tokens
 if a!=b:
  index=next((i for i,(x,y)in enumerate(zip(a,b))if x!=y),min(len(a),len(b)))
  failures.append({'key':key,'error':'significant whole-card DOM/forms/text mismatch','index':index,'php':a[max(0,index-2):index+3],'native':b[max(0,index-2):index+3]})
 else:matched+=1
 if cards and [c['source_index'] for c in cards]!=[0]:failures.append({'key':key,'error':'source ordinal changed'})
 page=r['caller_provenance']['actual_append_selected']
 if (result['source_count'],result['next_offset'],result['has_more'])!=(len(page['items']),page['next_offset'],page['has_more']):failures.append({'key':key,'error':'original page count/cursor lost'})
 ref=reference.get(key,{})
 initial=ref.get('initial_quote_budget',{'present':True,'type':'int','value':0})
 effective=initial['value'] if initial['present'] and initial['type']=='int' else 2
 state=result.get('quote_state')
 if not isinstance(state,dict) or state.get('initial')!=effective or state.get('remaining')!=effective-state.get('attempts',0) or not isinstance(state.get('attempts'),int) or state['attempts']<0:
  failures.append({'key':key,'error':'invalid private quote state','state':state,'effective_initial':effective})
 else:
  final={'present':True,'type':'int','value':state['remaining']} if state['attempts'] else initial
  expected=r['php']['effects']['admin_quote_link_preview_budget']
  if final!=expected:failures.append({'key':key,'error':'quote raw presence/type/value transition differs','php':expected,'native':final})
 if result['status_budget']!=0 or result['budget_changed']:failures.append({'key':key,'error':'zero preview state changed'})
report={'records':len(rows),'classification':dict(counts),'whole_card_dom_matches':matched,'failures':failures,'unimplemented_supported_branches':fallbacks,'gate_closed':not failures and not fallbacks,'scope':'private actual authenticated profile admission/currentSQL and complete renderer diagnostic; 8 raw/omission boundaries excluded and explicitly separately implemented; no whole-page/guest/native HTTP or all-effects acceptance'}
out=pathlib.Path(sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-local-profile-card-native-review.json');out.write_text(json.dumps(report,indent=2,ensure_ascii=False));print(json.dumps(report,ensure_ascii=False));sys.exit(bool(failures))
