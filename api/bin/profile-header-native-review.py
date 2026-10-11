#!/usr/bin/env python3
"""Actual PHP fragment vs independently queried native header; no whole-page claim."""
import json, pathlib, importlib.util, sys, hashlib
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
rpath,npath,out=map(pathlib.Path,sys.argv[1:4]);reference=json.loads(rpath.read_text());native=json.loads(npath.read_text());expected={(r['width'],r['case']):r for r in reference['captures']};seen=set();failures=[];matches=0;secondary_matches=0;invalid_auth_denials=[];guest_legacy_gaps=[]
for row in native['captures']:
 key=(row['width'],row['case'])
 if key not in expected or key in seen: failures.append({'key':key,'error':'unexpected or duplicate'});continue
 seen.add(key);original=expected[key]
 owner=original['context']['owner']
 current_owner=next((u for u in original['current_tables']['ap_users'] if int(u['id'])==owner and not u.get('disabled_at')),None)
 if owner>0 and current_owner is None:
  if 'error' in row:invalid_auth_denials.append(key);continue
  failures.append({'key':key,'error':'inactive auth viewer accepted'});continue
 if 'error' in row:failures.append({'key':key,'error':row['error']});continue
 a=mod.Tokens(original['php']['html']).tokens;b=mod.Tokens(row['html']).tokens
 if a==b:matches+=1
 else:failures.append({'key':key,'first_dom':next(((x,y) for x,y in zip(a,b) if x!=y),('length',len(a),len(b)))})
 if original['php'].get('secondary_html') is not None:
  asecondary=mod.Tokens(original['php']['secondary_html']).tokens;bsecondary=mod.Tokens(row.get('secondary_html') or '').tokens
  if asecondary==bsecondary:secondary_matches+=1
  else:failures.append({'key':key,'secondary_first_dom':next(((x,y) for x,y in zip(asecondary,bsecondary) if x!=y),('length',len(asecondary),len(bsecondary)))})
 for field in (() if original['context'].get('blocked') else ('requested_tab','effective_tab','blog_slug','tab_href','counts')):
  if original['php'][field]!=row[field]:failures.append({'key':key,'field':field,'php':original['php'][field],'native':row[field]})
 if original['context']['guest']:
  if any(t[0]=='open' and t[1]=='form' and dict(t[2]).get('method','get').lower()!='get' for t in b):failures.append({'key':key,'error':'guest authenticated form'})
if seen!=set(expected):failures.append({'error':'missing captures','count':len(set(expected)-seen)})
report={'captures':len(seen),'complete_significant_dom_matches':matches,'published_blog_secondary_matches':secondary_matches,'failures':failures,'invalid_auth_denials':invalid_auth_denials,'php_sha256':hashlib.sha256(rpath.read_bytes()).hexdigest(),'native_sha256':hashlib.sha256(npath.read_bytes()).hexdigest(),'scope':'current SQL facts and local header/tab fragments including measured denied-root/read-only guest controls, connected/disconnected Bsky graph and header fields; empty declared source buckets; no source admission, Redis transport, queue effect closure, non-blog secondary content or full HTTP assertion; published blog finite controls compared separately when present; absent/disabled auth owner cases explicitly require denial because fixture painter bypasses real auth bootstrap'}
out.write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps({'captures':len(seen),'matches':matches,'failures':len(failures)}));sys.exit(bool(failures))
