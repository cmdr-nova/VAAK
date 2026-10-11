#!/usr/bin/env python3
"""Independent source-binding/media/CW/denial checks for actual native Home stage."""
import json,sys
from html.parser import HTMLParser
asset='https://cdn2.miau.pub/media_attachments/files/117/418/821/758/171/621/original/71535357c1f9fd5e.jpg'
class Tree(HTMLParser):
 def __init__(self):super().__init__();self.stack=[];self.assets=[]
 def handle_starttag(self,tag,attrs):
  a=dict(attrs);classes=set(a.get('class','').split());ancestors=[c for _,c in self.stack]
  if tag=='img' and a.get('src')==asset:self.assets.append({'quote':any('quote-block'in c for c in ancestors),'cw':any('cw-body'in c for c in ancestors)})
  if tag not in {'img','input','source','br','hr','meta','link'}:self.stack.append((tag,classes))
 def handle_endtag(self,tag):
  if tag in {'img','input','source','br','hr','meta','link'}:return
  for i in range(len(self.stack)-1,-1,-1):
   if self.stack[i][0]==tag:del self.stack[i:];break
j=json.load(open(sys.argv[1] if len(sys.argv)>1 else '/tmp/vaak-quote-media-hydration-audience-native.json'));production=json.load(open('/tmp/vaak-quote-media-production-sanitized.json'));rows=[];fail=[]
deny={'private_announce','canonical_delete','alias_delete','exact_create_deleted','alias_private_create','canonical_private_create'}
complete={'production_public','active_plus_undone','create_plus_undone','unlisted_undone','quoted_cw','parent_cw'}
ambiguous={'underscore_target_mismatch','undo_only'}
for r in j['captures']:
 try:
  name=r['case'];s=r['hydrated_status'];original=r['original_status'];assert s['uri']==original['uri']==production['events'][0]['object_id']
  assert s['media_attachments']==original['media_attachments'],'hydration mutated outer original media'
  if name=='production_public':assert r['current_events']==production['events'],'original production events changed'
  if name in deny:assert r['admitted'] is False and r['html']=='','denied input emitted body/media';assert any(e.get('visibility')=='private'or e.get('type')=='Delete'or e.get('action_taken')=='deleted' for e in r['current_events'])
  else:
   assert r['admitted'] is True;tree=Tree();tree.feed(r['html']);assert len(tree.assets)==1,'asset lost/duplicated'
   child=s['quote']['quoted_status']
   if name in complete:
    assert child['uri']=='https://lgbtqia.space/users/alice/statuses/117418829058105216';assert child['account']['uri']=='https://lgbtqia.space/users/alice';assert child['media_attachments'][0]['url']==asset;assert tree.assets[0]['quote'],'known quoted asset painted outer'
    if name in {'quoted_cw','parent_cw'}:assert tree.assets[0]['cw'],'CW hierarchy lost'
   if name in ambiguous:assert not child['media_attachments'];assert not tree.assets[0]['quote'],'unknown child asset blindly moved'
  rows.append({'width':r['width'],'case':name,'admitted':r['admitted'],'checked':True})
 except (AssertionError,KeyError)as e:fail.append({'width':r['width'],'case':r['case'],'error':str(e)})
out={'records':len(j['captures']),'verified':len(rows),'denials':sum(r['case']in deny for r in rows),'complete_quoted_assets':sum(r['case']in complete for r in rows),'ambiguous_assets_retained':sum(r['case']in ambiguous for r in rows),'failures':fail,'scope':'Actual native materialization/hydration/current audience filter and lean Home painter artifact. Not live authenticated HTTP or original raw AS2 proof.'};path=sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-quote-media-hydration-independent-review.json';json.dump(out,open(path,'w'),indent=2);print(json.dumps(out));sys.exit(bool(fail))
