#!/usr/bin/env python3
"""Strict independent actual PHP/native DOM, state and admission review."""
import collections,importlib.util,json,pathlib,sys
here=pathlib.Path(__file__).resolve().parent
spec=importlib.util.spec_from_file_location('tokens',here/'search-native-acceptance-review.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
rows=json.loads(pathlib.Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-remote-direct-independent-native.json').read_text());fail=[];counts={};dom=state=0
for r in rows:
 corpus=r['corpus'];result=r['result'];counts.setdefault(corpus,collections.Counter());kind='fallback' if 'fallback' in result else 'native' if result.get('cards') else 'denied';counts[corpus][kind]+=1
 original=r.get('review_sources') if isinstance(r.get('review_sources'),list) else [r['source']]
 if result.get('original_sources')!=original:fail.append({'corpus':corpus,'case':r['case'],'error':'immutable original sources changed'})
 if corpus=='shape' and ('uri_' in r['case']):
  if kind!='fallback' or result.get('output_count')!=0:fail.append({'corpus':corpus,'case':r['case'],'error':'URI shape did not preserve whole PHP request'})
 if corpus=='mutation':
  if result.get('output_count')!=0 or result.get('mutation_dns_count',0)<1:fail.append({'corpus':corpus,'case':r['case'],'error':'late authority mutation not safely exercised'})
  continue
 if corpus=='preview' and r['case'] in ['plain_yt','media_yt','supplied_yt','poll_yt','own_current_to_yt','own_current_from_yt','own_ask_current_to_yt'] and kind!='native':fail.append({'corpus':corpus,'case':r['case'],'error':'mandatory supported genuine YouTube branch not native'})
 if corpus=='policy' and r['case'].rsplit('_',1)[0] in ['local_missing_row','remote_create_deleted','remote_true_delete','remote_tombstone','remote_legacy_quote_undo']:
  variant=r['case'].rsplit('_',1)[1]
  if variant=='cold' and kind!='fallback':fail.append({'corpus':corpus,'case':r['case'],'error':'late denied public cold IO branch not whole PHP fallback'})
  if variant in ['plain','cached','media','card'] and kind!='denied':fail.append({'corpus':corpus,'case':r['case'],'error':'safe late denial budget planner not genuine native denial'})
 if kind=='fallback':
  if result.get('output_count')!=0:fail.append({'corpus':corpus,'case':r['case'],'error':'whole-batch fallback leaked output'})
  continue
 html=''.join(card['html'] for card in result['cards']);a,b=module.Tokens(r['php']['html']).tokens,module.Tokens(html).tokens
 if a!=b:fail.append({'corpus':corpus,'case':r['case'],'width':r['width'],'mode':r.get('mode'),'view':r.get('view'),'first':next(((x,y) for x,y in zip(a,b) if x!=y),('length',len(a),len(b)))})
 else:dom+=1
 if corpus=='primary' and isinstance(r['php']['effects'].get('status_link_preview_budget'),dict):
  effects=r['php']['effects'];budget=effects['status_link_preview_budget'];changed=result['status_preview_budget_changed'];value=result['status_link_preview_budget'] if changed else None;present=bool(changed);typ='int' if changed else 'null'
  if (value,present,typ)!=(budget['value'],budget['present'],budget['type']):fail.append({'corpus':corpus,'case':r['case'],'mode':r.get('mode'),'error':'genuine primary lazy budget disagreement','native':(value,present,typ),'php':budget})
  elif effects.get('fixture_status_lookup_requests'):fail.append({'corpus':corpus,'case':r['case'],'mode':r.get('mode'),'error':'native success on substituted status lookup boundary'})
  elif any(effects.get(k) for k in ['preview_http','preview_warm','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests']):fail.append({'corpus':corpus,'case':r['case'],'mode':r.get('mode'),'error':'native success on PHP external IO/queue branch'})
  else:state+=1
 if corpus=='policy' and isinstance(r['php']['effects'].get('status_link_preview_budget'),dict):
  budget=r['php']['effects']['status_link_preview_budget']
  if result['status_link_preview_budget']!=budget['value']:fail.append({'corpus':corpus,'case':r['case'],'error':'policy ordinary status budget disagreement','native':result['status_link_preview_budget'],'php':budget})
  else:state+=1
 if corpus=='policy' and bool(html)!=bool(r['php']['html']):fail.append({'corpus':corpus,'case':r['case'],'error':'current policy admission disagreement'})
 if corpus=='preview':
  initial=r['initial_status_budget'];changed=result['status_preview_budget_changed'];present=True if changed else r['initial_status_budget_present'];value=result['status_link_preview_budget'] if changed else (None if initial=='unset' else 'not-an-integer' if initial=='malformed' else initial);typ='int' if changed or isinstance(value,int) else 'null' if value is None else 'string';effects=r['php']['effects']
  if (value,present,typ)!=(effects['status_link_preview_budget'],effects['status_budget_present'],effects['status_budget_type']):fail.append({'corpus':corpus,'case':r['case'],'error':'budget value/presence/type disagreement','initial':initial,'native':(value,present,typ),'php':effects})
  elif effects['preview_http'] or effects['preview_warm']:fail.append({'corpus':corpus,'case':r['case'],'error':'native success on actual PHP external IO branch'})
  else:state+=1
 if corpus=='batch':
  effects=r['php']['effects']
  if result['status_link_preview_budget']!=effects['status_link_preview_budget']:fail.append({'corpus':corpus,'case':r['case'],'error':'ordered batch status budget disagreement'})
  elif effects.get('preview_http') or effects.get('preview_warm') or any(effects.get(k) for k in ['fixture_status_lookup_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests']):fail.append({'corpus':corpus,'case':r['case'],'error':'native ordered batch on actual PHP IO/lookup branch'})
  else:state+=1
  idx=[c['source_index'] for c in result['cards']];wanted=[1] if r['case']=='denied_leading' else [0,1] if r['case']=='duplicate_supported' else [0] if r['case']=='mixed_synthetic' else None
  if wanted is None or idx!=wanted:fail.append({'corpus':corpus,'case':r['case'],'error':'batch source positions/unsupported boundary','indexes':idx})
# Mandatory private grants must be genuine native successes; unsupported cannot
# be used to disguise loss of author/follower/recipient access or revocation.
for r in rows:
 if r['corpus']=='policy':
  must_allow=r['case'] in ['local_private_author','local_private_follower','local_direct_recipient','remote_private_follower','remote_direct_recipient','remote_quote_undo','remote_announce_undo','remote_legacy_quote_only']
  must_deny=r['case'] in ['local_private_revoked','local_direct_revoked','local_no_outbox','local_no_outbox_with_event','local_no_outbox_author','local_no_outbox_author_with_event','local_missing_row','remote_private_revoked','remote_direct_revoked','remote_create_deleted','remote_true_delete','remote_tombstone','remote_private_with_public_quote','remote_legacy_quote_undo']
  if must_allow and not r['result'].get('cards'):fail.append({'corpus':'policy','case':r['case'],'error':'mandatory allowed card not native'})
  if must_deny and ('fallback' in r['result'] or r['result'].get('cards')):fail.append({'corpus':'policy','case':r['case'],'error':'mandatory denial not proven'})
report={'captures':len(rows),'corpora':counts,'significant_dom_matches':dom,'preview_state_matches':state,'failures':fail};path=pathlib.Path('/tmp/vaak-remote-direct-independent-review.json');path.write_text(json.dumps(report,ensure_ascii=False,indent=2));print(json.dumps(report,ensure_ascii=False,indent=2));sys.exit(bool(fail))
