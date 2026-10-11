#!/usr/bin/env python3
"""Independent structural checks on actual captured PHP, never rewrite HTML."""
import json,sys
from html.parser import HTMLParser
class Media(HTMLParser):
    def __init__(self):super().__init__();self.stack=[];self.assets=[];self.warnings=[]
    def handle_starttag(self,tag,attrs):
        a=dict(attrs);quote=any(self.stack) or 'quote-block' in a.get('class','').split()
        if tag not in {'img','source','input','br','hr','meta','link'}:self.stack.append(quote)
        if tag in {'img','source','video','audio'} and a.get('src','').startswith('https://media.test/'):
            self.assets.append({'url':a['src'],'nested':quote,'tag':tag})
    def handle_endtag(self,tag):
        if tag not in {'img','source','input','br','hr','meta','link'} and self.stack:self.stack.pop()
j=json.load(open(sys.argv[1]));rows=[]
for r in j['captures']:
    p=Media();p.feed(r['php']['html']);name=r['case']
    parent=[a for a in p.assets if a['url']=='https://media.test/parent-full.jpg'];quoted=[a for a in p.assets if a['url']=='https://media.test/quoted-full.jpg']
    if name in {'parent_and_quote','distinct_parent','same_thumbnail','multiple_types','child_cw','parent_cw'}:
        # same_thumbnail primary source is the thumbnail but data-full still owns parent.
        if name!='same_thumbnail':assert len(parent)==1 and not parent[0]['nested'],(name,parent)
    if name in {'quote_only','parent_and_quote','distinct_parent','same_thumbnail','multiple_types','child_cw','parent_cw','preview_only'}:
        assert any(a['nested'] for a in quoted),(name,quoted)
        if name not in {'same_thumbnail','preview_only'}:assert all(a['nested'] for a in quoted),(name,quoted)
    if name in {'empty_child','envelope_only','pending_empty'}:assert len(quoted)==1 and not quoted[0]['nested'],(name,quoted)
    if name=='child_cw':assert 'Quoted warning marker' in r['php']['html']
    if name=='parent_cw':assert 'Parent warning marker' in r['php']['html']
    rows.append({'width':r['width'],'case':name,'view':r['context']['view'],'assets':p.assets})
production=[]
for r in j['production_projection_captures']:
    h=r['php']['html'];asset='71535357c1f9fd5e.jpg';positions=[i for i in range(len(h)) if h.startswith(asset,i)];q=h.find('quote-block')
    if r['state']=='public':assert positions and all(i>q>=0 for i in positions)
    if r['state']=='private_child':assert not positions and q==-1
    production.append({'width':r['width'],'state':r['state'],'asset_count':len(positions),'asset_before_quote':any(i<q for i in positions),'direct_authority_allowed':r['direct_authority_allowed']})
out={'placement_comparisons':len(rows),'production_public_private_comparisons':4,'diagnostic_production_cases':production,'records':rows,'scope':'Actual PHP shared painter structural placement only; native DOM replay and live Home collector admission not claimed.'}
path=sys.argv[2] if len(sys.argv)>2 else '/tmp/vaak-quote-media-placement-review.json';json.dump(out,open(path,'w'),indent=2);print('PASS',len(rows),'structural placement comparisons + 4 public/private production projections')
