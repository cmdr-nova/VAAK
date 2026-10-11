#!/usr/bin/env python3
"""Exact-key remote metadata and significant header DOM comparison."""
import hashlib, importlib.util, json, pathlib, sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');tokens=importlib.util.module_from_spec(spec);spec.loader.exec_module(tokens)
rpath,npath,out=map(pathlib.Path,sys.argv[1:4]);reference=json.loads(rpath.read_text());native=json.loads(npath.read_text());expected={};seen=set();failures=[];matches=0;denied=0
for row in reference['captures']:
 key=(row['width'],row['case'],row['owner'])
 if key in expected:raise RuntimeError('duplicate PHP capture '+str(key))
 expected[key]=row
assert len(expected)==132
for row in native['captures']:
 key=(row['width'],row['case'],row['owner'])
 if key not in expected or key in seen:failures.append({'key':key,'error':'unexpected/duplicate native key'});continue
 seen.add(key);original=expected[key]
 if original['php']['blocked']:
  if row.get('error')=='current canonical root blocked':denied+=1;continue
  failures.append({'key':key,'error':'blocked canonical header must whole fallback'});continue
 if 'error' in row:failures.append({'key':key,'error':row['error']});continue
 a,b=tokens.Tokens(original['php']['html']).tokens,tokens.Tokens(row['html']).tokens
 if a==b:matches+=1
 else:failures.append({'key':key,'first_dom':next(((x,y) for x,y in zip(a,b) if x!=y),('length',len(a),len(b)))})
 for field,value in row['metadata'].items():
  if value!=original['php'][field]:failures.append({'key':key,'field':field,'php':original['php'][field],'native':value})
 if original['context']['guest'] and any(t[0]=='open' and t[1]=='form' and dict(t[2]).get('method','get').lower()!='get' for t in b):failures.append({'key':key,'error':'guest write form'})
if seen!=set(expected):failures.append({'error':'missing native keys','keys':sorted(set(expected)-seen)})
report={'captures':len(seen),'significant_header_dom_matches':matches,'explicit_blocked_fallback':denied,'failures':failures,'php_sha256':hashlib.sha256(rpath.read_bytes()).hexdigest(),'native_sha256':hashlib.sha256(npath.read_bytes()).hexdigest(),'scope':'current native SQL metadata and pure remote header/tab DOM against genuine fresh-process PHP; header admission/effect execution/full timeline/HTTP unresolved'}
out.write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps({'captures':len(seen),'matches':matches,'fallback':denied,'failures':len(failures)}));sys.exit(bool(failures))
