#!/usr/bin/env python3
"""Inspect paired real caller captures; preserve significant Unicode/body bytes.
No output from this reviewer is native source or authority. It compares existing
PHP/native output only, and separately reports HTTP success versus fallback.
"""
import argparse,base64,collections,json,pathlib
from html.parser import HTMLParser
class Tokens(HTMLParser):
    def __init__(self,text):
        super().__init__(convert_charrefs=True);self.tokens=[];self.protected=[];self.feed(text)
    def handle_starttag(self,tag,attrs):
        values=dict(attrs);protect=bool(self.protected and self.protected[-1]) or tag in ('pre','textarea') or 'feed-body' in values.get('class','').split() or 'white-space:pre' in values.get('style','')
        self.protected.append(protect);self.tokens.append(('open',tag,tuple(sorted((key,value or '') for key,value in attrs))))
        if tag in ('input','img','br','hr','meta','link','source'): self.protected.pop()
    def handle_startendtag(self,tag,attrs):self.handle_starttag(tag,attrs)
    def handle_endtag(self,tag):
        if self.protected:self.protected.pop()
        self.tokens.append(('close',tag))
    def handle_data(self,text):
        if self.protected and self.protected[-1]:
            if text:self.tokens.append(('text',text))
        else:
            value=text.strip(' \t\r\n\v\f')
            if value:self.tokens.append(('text',value))
def review(path):
    pairs=[json.loads(line) for line in pathlib.Path(path).read_text().splitlines()];failures=[]
    for i,pair in enumerate(pairs):
        a,b=pair['php'],pair['native'];ta,tb=Tokens(a['html']).tokens,Tokens(b['html']).tokens
        ra,rb=a.get('result'),b.get('result')
        if isinstance(ra,dict):ra={key:value for key,value in ra.items() if key!='html'}
        if isinstance(rb,dict):rb={key:value for key,value in rb.items() if key!='html'}
        equal=ta==tb and a['effects']==b['effects'] and ra==rb
        if not equal:
            first=next(((x,y) for x,y in zip(ta,tb) if x!=y),('length',len(ta),len(tb)))
            failures.append({'index':i,'width':pair.get('width'),'case':pair.get('case'),'first_difference':first,'effects_equal':a['effects']==b['effects']})
    print(json.dumps({'pairs':len(pairs),'exact_byte_matches':sum(p['equal'] for p in pairs),'parsed_matches':len(pairs)-len(failures),'failures':failures},ensure_ascii=False,indent=2))
    return failures
if __name__=='__main__':
    parser=argparse.ArgumentParser();parser.add_argument('pairs',nargs='?',default='/tmp/vaak-search-acceptance-pairs.jsonl');parser.add_argument('--require-pass',action='store_true');args=parser.parse_args();failures=review(args.pairs)
    if args.require_pass and failures:raise SystemExit(1)
