#!/usr/bin/env python3
"""Real loopback HTTP sessions against current extracted PHP caller. No live billing."""
import json,urllib.request,urllib.parse,http.cookiejar,sys,re
base='http://127.0.0.1:18991'; codes=json.load(open('/tmp/supporter-sapi-812-codes.json')); jar=http.cookiejar.CookieJar(); op=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar)); checks=[]
def request(path,data=None):
 req=urllib.request.Request(base+path,None if data is None else urllib.parse.urlencode(data).encode())
 try:
  with op.open(req) as r:return r.status,dict(r.headers),r.read().decode()
 except urllib.error.HTTPError as e:return e.code,dict(e.headers),e.read().decode()
def check(v,label):
 if not v:raise AssertionError(label)
 checks.append(label)
status,headers,html=request('/register');check(status==200 and 'Supporter code (optional)' in html and 'Apply for an account' in html,'actual optional reward registration UI');check('Every application needs administrator approval.' in html and 'email they paid with' in html,'approval and payment email copy');check('required autocomplete="off"' not in html,'code optional');check(headers.get('Cache-Control')=='no-store','private form no-store');csrf=re.search(r'name="csrf" value="([^"]+)"',html)[1]
def apply(name,email,code='',token=csrf):return request('/register',{'username':name,'email':email,'password':'long-password','invite':code,'csrf':token,'action':'register'})
_,_,bad=apply('badcsrf','bad@example.test','', 'bad');check('Session expired' in bad,'real session bad csrf denied');
_,_,paid=apply('paid','paid@example.test',codes['paid']);check('Application submitted' in paid,'actual configured paid application pending');state=json.loads(request('/fixture-state')[2]);check(state['accounts']==1 and state['applications']==[{'username':'paid','status':'pending'}],'paid code cannot bypass approval');
_,_,legacy=apply('legacy','legacy@example.test','LEGACY-CODE');check('That supporter code is not valid' in legacy,'actual legacy invite cannot bypass open approvals');
_,_,ordinary=apply('ordinary','','');check('Application submitted' in ordinary,'actual unpaid email-optional application pending');
status,headers,_=request('/api/ap-supporter.php');check(status==403 and headers.get('Cache-Control')=='no-store','actual issuance helper direct HTTP forbidden');
r=json.loads(request('/approve',{'id':1,'decision':'approved','csrf':csrf})[2]);check(not r['ok'],'real guest decision denied');request('/fixture-admin');r=json.loads(request('/approve',{'id':1,'decision':'approved','csrf':'bad'})[2]);check(not r['ok'],'real admin bad csrf denied');r=json.loads(request('/approve',{'id':1,'decision':'approved','csrf':csrf})[2]);check(r['ok'],'real admin approval');state=json.loads(request('/fixture-state')[2]);check(state['accounts']==2,'account appears only after approve');
r=json.loads(request('/redeem',{'supporter_code':codes['existing'],'csrf':'bad'})[2]);check(not r['ok'],'real redemption bad csrf');r=json.loads(request('/redeem',{'supporter_code':codes['existing'],'csrf':csrf})[2]);check(r['ok'],'existing paying account HTTP redemption');r=json.loads(request('/redeem',{'supporter_code':codes['existing'],'csrf':csrf})[2]);check(r['ok'] and r.get('already_redeemed'),'HTTP redemption idempotent');
for i in range(4):_,_,limited=apply('ordinary','','')
check('Too many application attempts' in limited,'actual HTTP admission limit before hashing branch');state=json.loads(request('/fixture-state')[2]);check(state['accounts']==2 and len(state['applications'])==2,'limited attempts no account or pending flood');
json.dump({'passed':len(checks),'checks':checks,'boundary':'Real PHP cli-server HTTP/session/PDO. Current registration controller and form extracted verbatim; fixture database/auth bootstrap and postcommit delivery declared. Not entire production index.php bootstrap or live Stripe.'},open(sys.argv[1],'w'),indent=2)
print(f'{len(checks)} HTTP checks passed')
