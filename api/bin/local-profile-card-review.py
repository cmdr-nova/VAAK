#!/usr/bin/env python3
"""Finite actual PHP profile paint assertions; no native parity inference."""
import json,sys
from pathlib import Path
from bs4 import BeautifulSoup
before=json.loads(Path('/tmp/vaak-local-profile-card-remote-boosts-before-own-profile-repair-reference.json').read_text())
p=Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-local-profile-card-after-own-boost-reference.json');after=json.loads(p.read_text());checks=0
def key(r):return r['width'],r['case'],r['owner'],r['tab']
def check(v,msg):
 global checks
 if not v:raise AssertionError(msg)
 checks+=1
old={key(r):r for r in before['captures']}
for r in after['captures']:
 if key(r) in old:
  b=old[key(r)]
  check(r['full_selected']==b['full_selected'] and r['append']['page']==b['append']['page'],'immutable actual source rows/cursors')
  for table in ['outbox_notes','masto_statuses','events','masto_polls','actor_profile','ap_users','ap_user_blocks','ap_mutes','ap_blocks']:
   check(sorted(json.dumps(v,sort_keys=True) for v in r['current_tables'][table])==sorted(json.dumps(v,sort_keys=True) for v in b['current_tables'][table]),'immutable unordered current SQL rows '+table)
 d=BeautifulSoup(r['append_html'],'html.parser')
 if r['owner']==0:check(not d.find_all('form'),'established guest selected card contains no POST forms')
 if r['case']=='own_remote_boost_public':
  check(bool(d.select('article')),'lawful public remote original now paints for profile owner/peer/guest')
  if r['owner']==1:
   n=r['normalizations'][0]
   check(n['normalized'] is None,'Home/default own outbound suppression unchanged')
   check(n['trusted_profile_normalized'] is not None,'trusted profile only enables existing own Announce')
   check(n['mismatched_object_normalized'] is None,'current object mismatch cannot enable own override')
   check(not old[key(r)]['append_html'],'original own profile Boost bug was empty')
 if r['case']=='own_remote_boost_private':check(not d.select('article'),'private underlying original remains denied')
 if r['case']=='poll':check('First choice' in r['append_html'] and 'Second choice' in r['append_html'],'actual poll choices visible')
 if r['case']=='ask_current':check('Current profile question Ω?' in r['append_html'] and 'Old profile question' not in r['append_html'],'actual latest canonical Ask metadata')
 if r['case']=='bsky_import_raw':check(bool(r['calls']['raw']),'actual imported outbox fallback reached')
 if r['case']=='raw_missing_masto':check(len(r['calls']['raw'])==len(r['append']['page']['items']),'all selected missing-masto rows use actual raw fallback')
 # Current caller selection admits only Announce ledger rows for Boosts;
 # legacy Create event/local reblog painters are not fabricated into it.
 if r['tab']=='boosts':check(all(a[0]['type']=='Announce' for a in r['calls']['event']),'actual event-call provenance Announce only')
 if r['case'] in ['quote_private_child','quote_deleted_child']:check('Profile actual original 2' not in r['append_html'],'private/deleted child body excluded')
report={'pass':True,'captures':len(after['captures']),'checks':checks,'scope':'actual selected source/currentSQL/current full+append paint assertions; no native HTTP or complete bootstrap, quoted media and all ordinary-preview budgets still separate; 78 original controls immutable'}
Path('/tmp/vaak-local-profile-card-review.json').write_text(json.dumps(report,indent=2));print(json.dumps(report))
