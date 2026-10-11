#!/usr/bin/env python3
"""Compare genuine representative guest PHP cards to actual current-SQL constructor."""
import collections,importlib.util,json,pathlib,sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
reference=json.loads(pathlib.Path(sys.argv[1]).read_text());expected={(r['width'],r['case']):r for r in reference['captures'] if r['context']['owner']>0};actual=json.loads(pathlib.Path(sys.argv[2]).read_text());counts=collections.Counter();fail=[];matched=0
for r in actual:
 key=(r['width'],r['case']);e=expected[key];result=r['result'];page=e['caller_provenance']['page']
 if r['original_source']!=e['source']:fail.append({'key':key,'error':'original representative source changed'})
 if result.get('selected_sources')!=page['items']:fail.append({'key':key,'error':'actual selected whole-page rows differ'})
 if 'fallback' in result:
  counts['declared_fallback']+=1
  if result.get('cards'):fail.append({'key':key,'error':'partial fallback HTML'})
  continue
 counts['native_success']+=1
 if (result['source_count'],result['next_offset'],result['has_more'])!=(len(page['items']),page['next_offset'],page['has_more']):fail.append({'key':key,'error':'cursor/count mismatch'})
 cards=result['cards'];first=[c for c in cards if c['source_index']==0]
 if [c['source_index'] for c in cards]!=sorted(set(c['source_index'] for c in cards)):fail.append({'key':key,'error':'source ordinal ordering'})
 a=m.Tokens(e['php']['html']).tokens;b=m.Tokens(''.join(c['html'] for c in first)).tokens
 if a!=b:
  i=next((i for i,(x,y) in enumerate(zip(a,b)) if x!=y),min(len(a),len(b)));fail.append({'key':key,'error':'complete representative DOM differs','index':i,'php':a[max(0,i-2):i+3],'native':b[max(0,i-2):i+3]})
 else:matched+=1
report={'records':len(actual),'classification':dict(counts),'representative_whole_dom_matches':matched,'failures':fail,'scope':'genuine whole source page/currentSQL authenticated raw admission with post-admission clock only; complete representative index0 PHP DOM; other page cards have no full expected painter corpus, no HTTP or all-effects acceptance'}
pathlib.Path(sys.argv[3]).write_text(json.dumps(report,indent=2,ensure_ascii=False));print(json.dumps(report,ensure_ascii=False));sys.exit(bool(fail))
