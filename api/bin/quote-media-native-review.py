#!/usr/bin/env python3
"""Compare immutable actual PHP/native full cards and significant forms/DOM."""
import collections,importlib.util,json,pathlib,sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
path=sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-quote-media-full66-native.json';rows=json.load(open(path));counts=collections.Counter();fail=[];matched=0
for row in rows:
 r=row['result'];key=(row['width'],row['case'],row['context']['view'])
 if r.get('original_sources')!=[row['original_source']]:fail.append({'key':key,'error':'original source changed'})
 if 'fallback' in r:
  counts['declared_fallback']+=1
  if r.get('output_count')!=0:fail.append({'key':key,'error':'fallback emitted partial output'})
  if row['case'] in {'quote_only','parent_and_quote','distinct_parent','same_thumbnail','multiple_types','child_cw','parent_cw'}:fail.append({'key':key,'error':'mandatory complete quoted-media card was not genuinely native','fallback':r['fallback']})
  continue
 cards=r.get('cards',[]);counts['native' if cards else 'denied']+=1
 html=''.join(c['html'] for c in cards);a=m.Tokens(row['php']['html']).tokens;b=m.Tokens(html).tokens
 if a!=b:
  pos=next((i for i,(x,y)in enumerate(zip(a,b))if x!=y),min(len(a),len(b)))
  fail.append({'key':key,'error':'significant full-card DOM mismatch','index':pos,'php':a[max(0,pos-2):pos+3],'native':b[max(0,pos-2):pos+3]})
 else:matched+=1
 if [c['source_index'] for c in cards]!=[0]:fail.append({'key':key,'error':'positive source position lost'})
 if r.get('status_link_preview_budget')!=0:fail.append({'key':key,'error':'zero budget changed'})
report={'records':len(rows),'classification':dict(counts),'whole_card_dom_matches':matched,'failures':fail,'scope':'Full shared painter via genuine private Direct tokens and current SQL. Home/Search origin presentation tested; live Home collector/HTTP not tested. Fallbacks are explicit and not counted as native parity.'}
out=sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-quote-media-full66-native-review.json';json.dump(report,open(out,'w'),indent=2,ensure_ascii=False);print(json.dumps(report,ensure_ascii=False));sys.exit(bool(fail))
