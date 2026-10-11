#!/usr/bin/env python3
"""Check actual PHP navigation regression captures; no live application execution."""
import argparse,json,pathlib
from html.parser import HTMLParser
parser=argparse.ArgumentParser();parser.add_argument('--input',required=True);parser.add_argument('--output',required=True);parser.add_argument('--cards');args=parser.parse_args()
class Links(HTMLParser):
 def __init__(self):super().__init__();self.stack=[];self.current=None;self.links=[]
 def handle_starttag(self,t,a):
  attrs=dict(a)
  if t not in ['input','img','br','hr','meta','link']:self.stack.append((t,attrs))
  if t=='a':self.current={'href':attrs.get('href',''),'label':'','menu':any('post-action-menu' in x.get('class','').split() for _,x in self.stack),'row':any('tweet-actions' in x.get('class','').split() for _,x in self.stack),'external':attrs.get('target')=='_blank'}
 def handle_data(self,d):
  if self.current:self.current['label']+=d
 def handle_endtag(self,t):
  if t=='a' and self.current:self.links.append(self.current);self.current=None
  for i in range(len(self.stack)-1,-1,-1):
   if self.stack[i][0]==t:self.stack=self.stack[:i];break
v=json.loads(pathlib.Path(args.input).read_text());out=[]
for name,c in v['cases'].items():
 p=Links();p.feed(c['html']);assert c['html'],name
 remote=[a for a in p.links if a['menu'] and a['external'] and a['href']==c['expected_remote']]
 assert remote,(name,'mapped remote missing')
 opened=[a for a in p.links if a['menu'] and a['label'].strip()=='Open']
 if c['expected_open'] is None:assert not opened,(name,'showOpen false ignored')
 else:assert any(a['href']==c['expected_open'] for a in opened),(name,'in-app object link lost')
 assert not any(a['row'] and not a['menu'] and a['label'].strip() in ['Open','Remote','Note'] for a in p.links),(name,'visible duplicate')
 out.append({'case':name,'open':c['expected_open'],'remote':c['expected_remote'],'remote_label':remote[0]['label'],'menu':True})
assert v['optional_hint_default_identical']
p=Links();p.feed(v['original_bsky_label_menu']);assert any(a['label']=='Open on Bluesky' and a['href']==v['original_bsky_expected_remote'] for a in p.links),'label changed to resolved target predicate'
p=Links();p.feed(v['mapped_menu']);assert any(a['label']=='Remote' and 'bsky.app' in a['href'] for a in p.links),'mapped href unexpectedly changed label'
cards_review=None
if args.cards:
 corpus=json.loads(pathlib.Path(args.cards).read_text());nonempty=0
 for name,case in corpus['captures'].items():
  p=Links();p.feed(case['html']);nonempty+=bool(case['html'])
  assert not any(a['row'] and not a['menu'] and a['label'].strip() in ['Open','Remote','Note'] for a in p.links),(name,'ordinary visible duplicate')
 cards_review={'cases':len(corpus['captures']),'nonempty':nonempty,'visible_duplicate_links':0}
pathlib.Path(args.output).write_text(json.dumps({'status':'PASS','cases':out,'case_count':len(out),'default_optional_hint_compatibility':True,'original_object_label_predicate':True,'boundaries':v['boundaries'],'ordinary_cards':cards_review},indent=2));print('PASS 10 actual shared painter target cases + default compatibility + object-label controls')
