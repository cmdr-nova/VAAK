#!/usr/bin/env python3
"""Independent complete raw profile DOM and retained source/oracle comparison."""
import hashlib,importlib.util,json,pathlib,sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('raw_tokens',here/'search-native-acceptance-review.py');m=importlib.util.module_from_spec(spec);spec.loader.exec_module(m)
reference=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-local-profile-raw-card-presentation28-reference.json')
native=pathlib.Path(sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-local-profile-raw-card-presentation28-native.json')
original=pathlib.Path('/tmp/vaak-local-profile-raw-card-facts-reference.json')
a=json.loads(reference.read_text());b=json.loads(native.read_text());old=json.loads(original.read_text());key=lambda r:(r['width'],r['case']);expected={key(r):r for r in a['captures']};prior={key(r):r for r in old['captures']};fail=[];matched=unchanged=0
if len(expected)!=len(a['captures']):fail.append('duplicate reference identity')
if len({key(r) for r in b['captures']})!=len(b['captures']):fail.append('duplicate native identity')
if set(expected)!={key(r) for r in b['captures']}:fail.append('native source identity coverage changed')
for r in b['captures']:
 k=key(r)
 if k not in expected:continue
 x=expected[k]
 if m.Tokens(x['php']['html']).tokens==m.Tokens(r['html']).tokens:matched+=1
 else:fail.append({'key':k,'error':'complete significant DOM/forms/text differs'})
 if x['source']==prior[k]['source'] and x['php']['html']==prior[k]['php']['html']:unchanged+=1
 else:fail.append({'key':k,'error':'original source or expected PHP output changed'})
report={'records':len(expected),'native_records':len(b['captures']),'complete_significant_dom_matches':matched,'unchanged_original_php_html_and_selected_source':unchanged,'failures':fail,'php_sha256':hashlib.sha256(reference.read_bytes()).hexdigest(),'native_sha256':hashlib.sha256(native.read_bytes()).hexdigest(),'scope':'independent pure raw presentation comparison; actual supplemental facts separate; no native admission/current-SQL resolver/HTTP or effects proof'}
out=pathlib.Path(sys.argv[3] if len(sys.argv)>3 else '/tmp/vaak-profile-raw28-independent-review.json');out.write_text(json.dumps(report,indent=2));print(json.dumps(report));sys.exit(bool(fail))
