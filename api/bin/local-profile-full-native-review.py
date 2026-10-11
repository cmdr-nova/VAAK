#!/usr/bin/env python3
"""Full/shell inner fragment exact DOM comparison; no arbitrary attribute masking."""
import hashlib,importlib.util,json,pathlib,sys
here=pathlib.Path(__file__).resolve().parent;s=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');m=importlib.util.module_from_spec(s);s.loader.exec_module(m)
a,b,out=map(pathlib.Path,sys.argv[1:4]);reference=json.loads(a.read_text());native=json.loads(b.read_text());key=lambda x:(x['width'],x['case'],x['owner'],x['shell']);expected={key(x):x for x in reference['captures']};seen=set();fail=[];matches=0
assert len(expected)==len(reference['captures']),'duplicate genuine reference keys'
for row in native:
 k=key(row)
 if k not in expected or k in seen:fail.append({'key':k,'error':'unexpected or duplicate native key'});continue
 seen.add(k);original=expected[k]
 if not row['native']:fail.append({'key':k,'fallback':row.get('error')});continue
 x=m.Tokens(original['php']['html']).tokens;y=m.Tokens(row['html']).tokens
 if x==y:matches+=1
 else:fail.append({'key':k,'first_dom':next(((i,u,v)for i,(u,v)in enumerate(zip(x,y))if u!=v),('length',len(x),len(y)))})
 if 'reply' in row:
  reply=row['reply'];count=0 if original['case'] in ['server_block','blog_list'] else len(original['source_page']['items'])
  if reply['effective_tab']!=original['php']['effective_tab'] or reply['source_count']!=count:fail.append({'key':k,'error':'effective tab/source count differs'})
  page=reply['source_page'];expected_page={'source_count':count,'next_offset':original['source_page']['next_offset'] if count else 0,'has_more':original['source_page']['has_more'] if count else False}
  if page!=expected_page:fail.append({'key':k,'error':'source cursor/has-more differs'})
  for field in ['status_preview_state','quote_preview_state']:
   if reply[field]!={'initial':0,'remaining':0,'attempts':0}:fail.append({'key':k,'error':'raw zero preview state differs','field':field})
  if reply['request_binding']!={'actor_key':original['key'],'actor':original['actor'],**original['request'],'shell':original['shell']}:fail.append({'key':k,'error':'full request binding differs'})
  for consumer in row['php_consumer']['captures']:
   if consumer['before']!=consumer['after'] or m.Tokens(consumer['html']).tokens!=x:fail.append({'key':k,'error':'actual PHP dispatch HTML/raw state differs','mode':consumer['mode']})
  if 'after_tables' in row:
   canon=lambda rows:sorted(json.dumps(value,sort_keys=True,ensure_ascii=False)for value in rows)
   for table,rows in original['after_tables'].items():
    if canon(rows)!=canon(row['after_tables'].get(table,[])):fail.append({'key':k,'error':'whole current storage differs','table':table})
 if original['owner']==0 and any(t[0]=='open' and t[1]=='form' and dict(t[2]).get('method','get').lower()!='get'for t in y):fail.append({'key':k,'error':'guest authenticated form'})
if seen!=set(expected):fail.append({'error':'missing native keyset'})
report={'captures':len(expected),'whole_inner_fragment_matches':matches,'failures':fail,'reference_sha256':hashlib.sha256(a.read_bytes()).hexdigest(),'native_sha256':hashlib.sha256(b.read_bytes()).hexdigest(),'scope':'actual header/body/empty/loader/blocked/blog inner fragments, real private full HTTP and PHP outer dispatch when reply present; exact source cursor/binding/raw states and captured storage; auth bootstrap/outer navigation, secondary nonblog content and saved Settings excluded'};out.write_text(json.dumps(report,indent=2));print(json.dumps(report));sys.exit(bool(fail))
