#!/usr/bin/env python3
"""Verify actual quote-budget captures against retained genuine PHP source/HTML."""
import json,pathlib,sys
path=pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-local-profile-quote-budget-reference.json');d=json.loads(path.read_text());base=json.loads(pathlib.Path('/tmp/vaak-local-profile-shared-card-genuine-quote-opts-reference.json').read_text());original={(r['width'],r['case']):r for r in base['captures']};fail=[];same=0;changes=[]
for r in d['captures']:
 key=(r['width'],r['case'].rsplit('_qbudget_',1)[0]);old=original[key]
 if all(r[k]==old[k] for k in ['source','selected_source']) and r['php']['html']==old['php']['html']:same+=1
 else:fail.append({'key':key,'mode':r['quote_budget_mode'],'error':'original source or expected HTML changed'})
 initial=r['initial_quote_budget'];final=r['php']['effects']['admin_quote_link_preview_budget'];attempt=bool(r['actual_quote_opts'] and r['actual_quote_opts'][0]['opts'].get('text'))
 expected=initial
 if attempt:
  value=initial['value'] if initial['present'] and initial['type']=='int' else 2
  value=value-1 if value>0 else value
  expected={'present':True,'type':'int','value':value}
 if final!=expected:fail.append({'key':key,'mode':r['quote_budget_mode'],'error':'actual plain quote lazy transition differs','initial':initial,'final':final})
 for field in ['preview_http_requests','preview_warm_requests']:
  if r['php']['effects'][field]['value']:fail.append({'key':key,'error':'unexpected external preview effects'})
 changes.append({'width':key[0],'case':key[1],'mode':r['quote_budget_mode'],'initial':initial,'final':final,'actual_quote_helper_calls':len(r['actual_quote_opts'])})
report={'records':len(d['captures']),'unchanged_original_selected_projected_source_html':same,'failures':fail,'transitions':changes,'scope':'actual plain cache-only quoted-preview lazy state; no native or HTTP acceptance'}
out=pathlib.Path(sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-local-profile-quote-budget-review.json');out.write_text(json.dumps(report,indent=2));print(json.dumps({k:v for k,v in report.items() if k!='transitions'}));sys.exit(bool(fail))
