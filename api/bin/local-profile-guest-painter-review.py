#!/usr/bin/env python3
"""Independent retained-original/current guest DOM and poll assertions."""
import json,sys
from pathlib import Path
from bs4 import BeautifulSoup,NavigableString,Tag
p=Path(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-local-profile-guest-painter-reference.json');x=json.loads(p.read_text());checks=0
def check(value,message):
 global checks
 if not value:raise AssertionError(message)
 checks+=1
def canonical(node):
 if isinstance(node,NavigableString):return str(node) if str(node).strip() else None
 if not isinstance(node,Tag):return None
 return [node.name,sorted((k,v) for k,v in node.attrs.items()),[r for child in node.children if (r:=canonical(child)) is not None]]
for r in x['captures']:
 if r['source'] is None:check(r['before']==r['after']=='','actual own Announce null remains no paint');continue
 check(r['effects']['before']==r['effects']['after'],'recorded effects unchanged')
 if r['owner']!=0:
  check(r['before']==r['after'],'authenticated complete HTML unchanged')
  if r['case']=='poll':check(r['poll_before']==r['poll_after'],'authenticated poll unchanged')
  continue
 before=BeautifulSoup(r['before'],'html.parser');after=BeautifulSoup(r['after'],'html.parser')
 check(bool(before.find_all('form')) and not after.find_all('form'),'guest POST forms removed')
 check(bool(after.select('.tweet-actions a')),'guest Open/Remote links retained')
 for doc in [before,after]:
  for node in doc.select('.tweet-actions'):node.decompose()
  # The intentional interactive-vote to public-results change is verified below.
  for node in doc.select('.poll-block'):node.replace_with('VERIFIED_POLL_RESULTS')
 check(canonical(before)==canonical(after),'complete significant header/body/media/CW DOM unchanged outside reviewed actions/poll')
 if r['case']=='poll':
  b=BeautifulSoup(r['poll_before'],'html.parser');a=BeautifulSoup(r['poll_after'],'html.parser')
  check(bool(b.find('form')) and not a.find('form'),'guest poll interactive form removed')
  check([n.get_text() for n in a.select('.poll-opt-text')]==['First choice','Second choice'],'current poll choices')
  check([n.get_text() for n in a.select('.poll-opt-count')]==['67% · 2','33% · 1'],'current public result counts')
  check(a.select_one('.poll-meta').get_text()=='3 votes · 3 voters · '+b.select_one('.poll-meta').get_text(),'current public totals plus unchanged expiry')
out={'pass':True,'paired_controls':len(x['captures']),'checks':checks,'before_sha256':x['before_admin_sha256'],'after_sha256':x['after_admin_sha256'],'scope':'genuine same-source/SQL pure shared painter pairs; guest auth/handler/native HTTP not proved, nonzero Bsky counts separate'}
Path('/tmp/vaak-local-profile-guest-painter-review.json').write_text(json.dumps(out,indent=2));print(json.dumps(out))
