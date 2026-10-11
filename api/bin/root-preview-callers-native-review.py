#!/usr/bin/env python3
"""Full actual caller differential; preserved PHP oracle and original sources."""
import argparse, importlib.util, json, pathlib, sys
parser=argparse.ArgumentParser(description=__doc__)
parser.add_argument('actual')
parser.add_argument('--reference',default='/tmp/vaak-root-preview-callers-production-warm-effects-reference.json')
parser.add_argument('--report',default='/tmp/vaak-root-preview-callers-native-review.json')
parser.add_argument('--allow-subset',action='store_true',help='Explicit declared reduced fault cohort; original case keys still required.')
args=parser.parse_args()
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py')
module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
def load(path):return json.loads(pathlib.Path(path).read_text())['captures']
def key(row):return (row['width'],row.get('case',(row.get('class'),row.get('variant'))),row['entry'],json.dumps(row['initial_status_budget'],sort_keys=True))
expected={key(row):row for row in load(args.reference)}
failures=[];dom_matches=0;state_matches=0;actual=load(args.actual)
for row in actual:
    original=expected.pop(key(row))
    for field in ('source','source_sha256','clock','initial_status_budget'):
        if row[field]!=original[field]:failures.append({'key':key(row),'error':'immutable '+field+' changed'})
    left,right=module.Tokens(original['php']['html']).tokens,module.Tokens(row['php']['html']).tokens
    if left!=right:
        failures.append({'key':key(row),'first_dom':next(((a,b) for a,b in zip(left,right) if a!=b),('length',len(left),len(right)))})
    else:dom_matches+=1
    effects=('status_budget','quote_budget','fetch_budget','http','warm','lookup','actor_warm','media_warm','quote_warm')
    differences={field:[original['php']['effects'][field],row['php']['effects'][field]] for field in effects if original['php']['effects'][field]!=row['php']['effects'][field]}
    if differences:failures.append({'key':key(row),'effects':differences})
    else:state_matches+=1
    for field in ('headers','collector_calls'):
        if row['php'].get(field)!=original['php'].get(field):
            failures.append({'key':key(row),'error':'caller '+field+' changed'})
    left_result,right_result=original['php']['result'],row['php']['result']
    if isinstance(left_result,dict) and isinstance(right_result,dict):
        left_result={k:(module.Tokens(v).tokens if k=='html' and isinstance(v,str) else v) for k,v in left_result.items()}
        right_result={k:(module.Tokens(v).tokens if k=='html' and isinstance(v,str) else v) for k,v in right_result.items()}
    elif isinstance(left_result,str) and isinstance(right_result,str):
        left_result,right_result=module.Tokens(left_result).tokens,module.Tokens(right_result).tokens
    if left_result!=right_result:
        failures.append({'key':key(row),'error':'fragment result/metadata changed'})
    if 'FORBIDDEN PREVIEW PROTOCOL PARTIAL OUTPUT' in row['php']['html']:
        failures.append({'key':key(row),'error':'malformed transport published partial output'})
if expected and not args.allow_subset:failures.append({'error':'missing actual original cases','count':len(expected)})
report={'captures':len(actual),'whole_caller_dom_matches':dom_matches,'state_and_recorded_effect_matches':state_matches,'failures':failures,'scope':'actual caller HTML and declared preview/lookup/queue effects; unavailable Redis attempt count retained diagnostically; native HTTP response provenance must be reviewed separately'}
pathlib.Path(args.report).write_text(json.dumps(report,ensure_ascii=False,indent=2))
print(json.dumps({**report,'failures':failures[:5]},ensure_ascii=False,indent=2));sys.exit(bool(failures))
