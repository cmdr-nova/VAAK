#!/usr/bin/env python3
"""Compare preserved pending-card DOM and separately changed denial effects."""
import importlib.util,json,pathlib
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');mod=importlib.util.module_from_spec(spec);spec.loader.exec_module(mod)
before=json.load(open('/tmp/vaak-profile-pending-policy-reference.json'))['captures']
after=json.load(open('/tmp/vaak-profile-pending-policy-after-reference.json'))['captures']
native=json.load(open('/tmp/vaak-profile-pending-quote-native.json'))
key=lambda row:(row['width'],row['case'])
b={key(row):row for row in before};a={key(row):row for row in after};n={key(row):row for row in native}
assert len(b)==len(before)==52 and len(a)==len(after)==52 and len(n)==len(native)==2
failures=[];matched=0;effects=[]
for k,row in n.items():
 old=b[k];new=a[k]
 if old['selected_source']!=new['selected_source'] or old['current_tables']!=new['current_tables'] or old['php']['html']!=new['php']['html']:failures.append([k,'baseline source/SQL/HTML changed'])
 if mod.Tokens(new['php']['html']).tokens!=mod.Tokens(''.join(card['html'] for card in row['cards'])).tokens:failures.append([k,'native whole-card DOM differs'])
 else:matched+=1
 old_warm=old['php']['effects']['fixture_quote_warm_requests']['value'];new_warm=new['php']['effects']['fixture_quote_warm_requests']['value']
 if len(old_warm)!=1 or new_warm or row['quote_warm']:failures.append([k,'known denied target warming not suppressed',old_warm,new_warm,row['quote_warm']])
 if row['quote_state']!=[0,0,0]:failures.append([k,'quote state changed'])
 effects.append({'key':k,'before_quote_warm':old_warm,'after_quote_warm':new_warm,'native_quote_warm':row['quote_warm']})
report={'records':2,'whole_card_dom_matches':matched,'privacy_effects':effects,'failures':failures,'scope':'actual known denied local pending child; unchanged DOM, intentionally suppressed warm dispatch; representative card/current selected page, not full caller'}
pathlib.Path('/tmp/vaak-profile-pending-denial-review.json').write_text(json.dumps(report,indent=2));print(json.dumps(report));assert not failures
