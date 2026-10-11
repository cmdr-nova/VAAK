#!/usr/bin/env python3
"""Independent significant card DOM comparison; no preview/HTTP claim."""
import importlib.util,json,pathlib,re,sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
reference=json.loads(pathlib.Path('/tmp/vaak-library-search-boost-state-reference.json').read_text())['captures']
actual=json.loads(pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-library-search-boost-native.json').read_text())
def key(r):return tuple(r[x] for x in ('width','owner','shape','state','caller'))
refs={key(r):r for r in reference if r['caller']!='raw_explicit_prefetch'};assert len(refs)==300
fail=[];matches=0
for r in actual:
 expected=refs.pop(key(r));cards=re.findall(r'<article\b[^>]*>.*?</article>',expected['php']['html'],re.S);assert len(cards)==1
 a,b=m.Tokens(cards[0]).tokens,m.Tokens(r['html']).tokens
 if a!=b:fail.append({'key':key(r),'first':next(((x,y) for x,y in zip(a,b) if x!=y),('length',len(a),len(b)))})
 else:matches+=1
 if r['admitted_sid']!=str(expected['source']['id']):fail.append({'key':key(r),'error':'admitted incoming ID changed'})
assert not refs
report={'captures':len(actual),'significant_whole_card_dom_matches':matches,'failures':fail,'scope':'actual source/context modules; preview budgets and HTTP are unclaimed'}
pathlib.Path('/tmp/vaak-library-search-boost-native-review.json').write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps({**report,'failures':fail[:5]},ensure_ascii=False,indent=2));sys.exit(bool(fail))
