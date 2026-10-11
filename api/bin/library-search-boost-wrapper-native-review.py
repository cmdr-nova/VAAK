#!/usr/bin/env python3
"""Independent actual wrapper card/denial comparison, excluding preview HTTP."""
import importlib.util, json, pathlib, re, sys
here = pathlib.Path(__file__).resolve().parent
spec = importlib.util.spec_from_file_location('tokens', here/'search-native-acceptance-review.py')
tokens = importlib.util.module_from_spec(spec); spec.loader.exec_module(tokens)
reference = json.loads(pathlib.Path('/tmp/vaak-library-search-boost-wrapper-reference.json').read_text())['captures']
actual = json.loads(pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-library-search-boost-wrapper-native.json').read_text())
def key(row): return tuple(row[x] for x in ('width','owner','shape','state','caller'))
refs = {key(row): row for row in reference}; assert len(refs)==300
failures = []; cards_matched = 0; denials_matched = 0
for row in actual:
    expected = refs.pop(key(row))
    if row['original_source'] != expected['source']:
        failures.append({'key':key(row), 'error':'original source changed'})
    cards = re.findall(r'<article\b[^>]*>.*?</article>', expected['php']['html'], re.S)
    if expected['caller'].startswith('search'):
        if cards or row['admitted'] or row['html']:
            failures.append({'key':key(row), 'error':'actual collector denial changed'})
        else: denials_matched += 1
        continue
    assert len(cards)==1
    left,right = tokens.Tokens(cards[0]).tokens,tokens.Tokens(row['html']).tokens
    if left != right:
        failures.append({'key':key(row),'first':next(((a,b) for a,b in zip(left,right) if a!=b),('length',len(left),len(right)))})
    else: cards_matched += 1
    if str(row['admitted_sid']) != str(expected['displayed_inner_source']['id']):
        failures.append({'key':key(row),'error':'displayed child ID changed'})
assert not refs
report={'captures':len(actual),'library_complete_dom_matches':cards_matched,'search_current_collector_denials':denials_matched,'failures':failures,'scope':'actual wrapper producer and context; ordinary preview effects and HTTP excluded'}
pathlib.Path('/tmp/vaak-library-search-boost-wrapper-native-review.json').write_text(json.dumps(report,ensure_ascii=False,indent=2))
print(json.dumps({**report,'failures':failures[:5]},ensure_ascii=False,indent=2));sys.exit(bool(failures))
