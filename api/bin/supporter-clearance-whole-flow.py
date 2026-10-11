#!/usr/bin/env python3
"""Actual copied Clearance bootstrap/HTTP/magic-link and signed Stripe webhook.
Only Stripe GET and mail delivery transports return declared synthetic fixtures.
"""
import socket
import pathlib,os,json,subprocess,re,time,urllib.request,urllib.parse,http.cookiejar,hashlib,hmac,importlib.util,sys,shutil
R=pathlib.Path(__file__).resolve().parents[2];O=R/'rust/target/parity-evidence/supporter-whole-flow';A=O/'app';W=pathlib.Path('/home/cmdr-nova/Documents/Website/NovaLandia');C=A/'clearance';C.mkdir(exist_ok=True)
manifest=[]
for p in (W/'clearance').rglob('*.php'):
 if p.name=='config.local.php' or 'data' in p.parts:continue
 q=C/p.relative_to(W/'clearance');q.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,q)
 manifest.append({'path':str(p.relative_to(W)),'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'mode':oct(p.stat().st_mode&0o777)})
for p in (R/'rust/target/parity-evidence/supporter-registration-phase/clearance').rglob('*.php'):shutil.copy2(p,C/p.relative_to(R/'rust/target/parity-evidence/supporter-registration-phase/clearance'))
(O/'clearance-originals.json').write_text(json.dumps(manifest,indent=2))
# Replacement functions are exclusively outbound transports; auth/storage/eligibility/handlers remain complete.
def replace(p,name,body):
 s=p.read_text();pattern=r'(?ms)^function '+re.escape(name)+r'\([^\n]*\)[^\n]*\n\{.*?^\}'
 m=re.search(pattern,s);assert m,name
 signature=m[0].split('\n{',1)[0];s=s[:m.start()]+signature+'\n{\n'+body+'\n}'+s[m.end():];p.write_text(s)
replace(C/'lib/stripe.php','clearance_stripe_request',"$f=json_decode(file_get_contents(__DIR__.'/../../stripe-responses.json'),true);file_put_contents(__DIR__.'/../../stripe-calls.jsonl',json_encode([$method,$path,$query,$timeoutMs]).\"\\n\",FILE_APPEND);$key=$path==='customers' ? $path.':'.($query['email']??'') : ($path==='subscriptions'||$path==='charges'||$path==='checkout/sessions' ? $path.':'.($query['customer']??'') : $path);if(!isset($f[$key]))throw new RuntimeException('Undeclared Stripe GET fixture');return $f[$key];")
replace(C/'lib/mail.php','clearance_send_magic_link_email',"file_put_contents(__DIR__.'/../../captured-mail.jsonl',json_encode(['email'=>$toEmail,'url'=>$magicUrl]).\"\\n\",FILE_APPEND);")
config={'dev_mode':False,'site_url':'http://127.0.0.1:18998','db_path':str(O/'clearance.sqlite'),'session_name':'isolated_clearance','enroll_secret':'isolated-enroll-secret','stripe_secret_key':'sk_live_fixture_only','opensim_invite_url':'','opensim_invite_secret':'','discord_invite_api_secret':'','vaak_api_root':str(A/'api'),'allow_any_stripe_customer':True}
(C/'config.local.php').write_text('<?php return '+ '['+','.join(repr(k)+'=>'+('true' if v is True else 'false' if v is False else repr(v)) for k,v in config.items())+'];')
for p in [O/'clearance.sqlite',A/'captured-mail.jsonl',A/'stripe-calls.jsonl']:p.unlink(missing_ok=True)
responses={}
for name in ['Paid','Unpaid']:
 cus='cus_'+name;email=name.lower()+'@example.invalid';responses['customers:'+email]={'data':[{'id':cus,'email':email}]};responses['subscriptions:'+cus]={'data':[]}
 responses['checkout/sessions:'+cus]={'data':([{'id':'cs_Paid','customer':cus,'subscription':'sub_Paid','livemode':True,'status':'complete','payment_status':'paid','mode':'subscription','metadata':{'type':'monthly_subscription'}}] if name=='Paid' else []),'has_more':False}
responses['charges:cus_Paid']={'data':[{'id':'ch_Paid','customer':'cus_Paid','invoice':'in_Paid','livemode':True,'status':'succeeded','paid':True,'captured':True,'amount':500,'amount_refunded':0,'refunded':False,'disputed':False}],'has_more':False}
responses['invoices/in_Paid']={'id':'in_Paid','customer':'cus_Paid','subscription':'sub_Paid','charge':'ch_Paid','livemode':True,'paid':True,'status':'paid','amount_paid':500}
(A/'stripe-responses.json').write_text(json.dumps(responses))
(A/'clearance-router.php').write_text("<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(404);exit;}$p=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);$allowed=['/clearance/'=>'index.php','/clearance/home.php'=>'home.php','/clearance/request-link.php'=>'request-link.php','/clearance/auth.php'=>'auth.php','/clearance/enroll.php'=>'enroll.php'];if(!isset($allowed[$p])){http_response_code(404);exit;}require __DIR__.'/clearance/'.$allowed[$p];")
e=os.environ.copy();e.update(json.loads((O/'env.json').read_text()));e['AP_DB_DSN']='pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=--search_path=supporter_clearance_flow'
cmd=[str(R/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so']
provision=(O/'provision.php').read_text().replace("$schema='supporter_whole_flow'","$schema='supporter_clearance_flow'");(O/'clearance-provision.php').write_text(provision)
subprocess.run(cmd+[str(O/'clearance-provision.php')],env=dict(e,POSTING_SAPI_SQLITE=str(O/'storage/ap.sqlite'),POSTING_SAPI_API_SOURCE=str(A/'api')),check=True)
log=(O/'clearance-sapi.log').open('w');server=subprocess.Popen(cmd+['-d','session.save_path='+str(O/'storage/sessions'),'-d','disable_functions=exec,shell_exec,curl_init,stream_socket_client,fsockopen,pfsockopen,socket_connect','-d','allow_url_fopen=0','-S','127.0.0.1:18998',str(A/'clearance-router.php')],env=e,stdout=log,stderr=log)
checks=[];captures=[];sdk_gets=[]
# Fail before DNS/wire for every undeclared Python network destination.
real_connect=socket.socket.connect;real_getaddrinfo=socket.getaddrinfo
def isolated_connect(sock,address):
 if isinstance(address,tuple) and address[0] not in ['127.0.0.1','::1','localhost']:raise RuntimeError('External fixture socket denied')
 return real_connect(sock,address)
def isolated_resolve(host,*args,**kwargs):
 if host not in ['127.0.0.1','::1','localhost']:raise RuntimeError('External fixture DNS denied')
 return real_getaddrinfo(host,*args,**kwargs)
socket.socket.connect=isolated_connect;socket.getaddrinfo=isolated_resolve
def check(v,m):
 checks.append({'check':m,'ok':bool(v)})
 if not v:raise AssertionError(m)
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None,headers=None):
 q=urllib.request.Request('http://127.0.0.1:18998'+path,data=data if isinstance(data,bytes) else urllib.parse.urlencode(data).encode() if data is not None else None,headers=headers or {})
 try:r=c.open(q,timeout=15)
 except urllib.error.HTTPError as x:r=x
 h=r.read().decode();captures.append({'path':path,'status':r.status,'body':h});return h,r.status
try:
 c=client()
 for _ in range(30):
  try:req(c,'/clearance/');break
  except OSError:time.sleep(.1)
 h,st=req(c,'/clearance/enroll.php',json.dumps({'email':'paid@example.invalid','customer_id':'cus_Paid'}).encode(),{'Content-Type':'application/json','Authorization':'Bearer bad'});check(st==401,'real enrollment denies unauthorized bearer')
 # Import exact full application and use actual SDK signature verification. Its HTTP delivery goes to real isolated enroll handler.
 sys.path.insert(0,str(O/'webhook-runtime'));os.environ.update(STRIPE_SECRET_KEY='sk_live_fixture_only',STRIPE_WEBHOOK_SECRET='whsec_isolated_fixture',CLEARANCE_ENROLL_SECRET='isolated-enroll-secret',CLEARANCE_ENROLL_URL='http://127.0.0.1:18998/clearance/enroll.php',DOMAIN='http://127.0.0.1:18998')
 shutil.copy2(W/'stripe-service/app.py',O/'stripe-service-app.py');spec=importlib.util.spec_from_file_location('isolated_stripe_service',O/'stripe-service-app.py');module=importlib.util.module_from_spec(spec);spec.loader.exec_module(module)
 class FixtureStripeHTTPClient(module.stripe.http_client.HTTPClient):
  name='isolated-declared-transport'
  def request(self,method,url,headers,post_data=None):
   if method.lower()!='get' or url!='https://api.stripe.com/v1/customers/cus_Paid':raise RuntimeError('Undeclared SDK transport blocked')
   sdk_gets.append({'method':method,'url':url,'boundary':'declared fixture HTTPClient; no socket'})
   return json.dumps({'id':'cus_Paid','object':'customer','email':'paid@example.invalid'}),200,{}
 module.stripe.default_http_client=FixtureStripeHTTPClient()
 fc=module.app.test_client()
 def webhook(kind,obj,valid=True):
  event={'id':'evt_'+str(len(checks)),'object':'event','type':kind,'data':{'object':obj}};payload=json.dumps(event,separators=(',',':'));timestamp=str(int(time.time()));signature=hmac.new(b'whsec_isolated_fixture',(timestamp+'.'+payload).encode(),hashlib.sha256).hexdigest() if valid else '00'*32
  return fc.post('/webhook',data=payload,content_type='application/json',headers={'Stripe-Signature':'t='+timestamp+',v1='+signature})
 event={'id':'cs_Paid','mode':'subscription','customer':'cus_Paid','customer_email':'paid@example.invalid','customer_details':{'email':'paid@example.invalid'},'metadata':{'type':'monthly_subscription'}}
 check(webhook('checkout.session.completed',event,False).status_code==400,'actual Stripe SDK rejects invalid signed webhook')
 check(webhook('checkout.session.completed',event).status_code==200,'actual signed monthly checkout enrolls via actual HTTP handler')
 import sqlite3
 def member(email):
  d=sqlite3.connect(O/'clearance.sqlite');d.row_factory=sqlite3.Row;r=d.execute('SELECT * FROM members WHERE email=?',(email,)).fetchone();d.close();return dict(r) if r else None
 check(member('paid@example.invalid') is not None,'signed webhook actual permanent Clearance row')
 donation=dict(event,amount_total=500,mode='payment',customer_email='donation@example.invalid',customer='cus_Donation',metadata={'type':'one_time_donation','amount':'500'})
 check(webhook('checkout.session.completed',donation).status_code==200 and member('donation@example.invalid') is None,'one-time donation does not enroll monthly Clearance')
 for name in ['paid','unpaid']:
  cc=client();h,st=req(cc,'/clearance/request-link.php',{'email':name+'@example.invalid'});check(st==200,'actual '+name+' request-link form redirect')
  mail=[json.loads(x) for x in (A/'captured-mail.jsonl').read_text().splitlines() if json.loads(x)['email']==name+'@example.invalid'][-1]
  path=urllib.parse.urlparse(mail['url']);h,st=req(cc,path.path+'?'+path.query);row=member(name+'@example.invalid')
  check(st==200 and 'VAAK' in h,'actual '+name+' magic link authenticates portal and renders full template')
  if name=='paid':check(str(row.get('vaak_invite_code','')).startswith('SUP-') and 'Supporter' in h,'actual portal paid selector cross-app issuer grants reward');paidcode=row['vaak_invite_code']
  else:check(not str(row.get('vaak_invite_code','')).startswith('SUP-'),'customer-only portal receives no reward')
  calls=(A/'stripe-calls.jsonl').read_text().splitlines();h,st=req(cc,'/clearance/home.php');check(len((A/'stripe-calls.jsonl').read_text().splitlines())==len(calls),'actual '+name+' repeat portal navigation does not repeat eligibility I/O')
  outsider=client();h,st=req(outsider,path.path+'?'+path.query);check('already used' in h,'real magic link one-use replay denied')
 check(webhook('customer.subscription.deleted',{'id':'sub_Paid','customer':'cus_Paid','status':'canceled'}).status_code==200,'signed cancellation updates existing permanent membership')
 check(member('paid@example.invalid')['vaak_invite_code']==paidcode,'cancellation preserves paid reward permanence')
 (O/'clearance-result.json').write_text(json.dumps({'checks':checks,'captures':captures,'sdk_gets':sdk_gets,'stripe_calls':[json.loads(x) for x in (A/'stripe-calls.jsonl').read_text().splitlines()],'boundaries':['only PHP Stripe REST GET, Python Stripe SDK HTTPClient customer GET and mail delivery transports replaced with declared synthetic responses','OpenSim/Discord configurations empty: actual handlers return unavailable and no external mutation','copied actual Flask app and installed exact Flask 2.3.3/stripe6.6.0 dependency majors; actual SDK HMAC signature validation','real HTTP internal enrollment bearer and real SQLite auth/session/link/portal/selector; cross-app actual PHP issuer and unique PG schema','synthetic Checkout/charge/invoice fixture does not prove live response compatibility']},indent=2));print(json.dumps({'ok':True,'checks':len(checks)}))
finally:
 server.terminate();server.wait(timeout=5);log.close();socket.socket.connect=real_connect;socket.getaddrinfo=real_getaddrinfo;(O/'clearance-partial-result.json').write_text(json.dumps({'checks':checks,'captures':captures},indent=2))
