#!/usr/bin/env python3
"""Real copied full PHP document in Chromium; controlled idle scheduling/network only."""
import pathlib,subprocess,tempfile,time,json,urllib.request,urllib.parse,http.cookiejar,re,os,websocket,hashlib
R=pathlib.Path(__file__).resolve().parents[2];O=R/'rust/target/parity-evidence/library-settings-whole-flow';A=O/'app'
e=os.environ.copy();e.update(json.loads((O/'env.json').read_text()));e.update(AP_DB_DSN='pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=--search_path=library_settings_whole_flow',VAAK_SUPPORTER_CODES='0',VAAK_REGISTRATION_APPROVALS='0',VAAK_LIBRARY_NATIVE_RENDER='0',VAAK_LIBRARY_NATIVE_DATA='0')
cmd=[str(R/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so']
server=subprocess.Popen(cmd+['-d','opcache.enable=0','-d','disable_functions=exec,shell_exec,curl_init','-d','allow_url_fopen=0','-S','127.0.0.1:18481',str(A/'router.php')],env=e,stdout=open(O/'browser-sapi.log','w'),stderr=subprocess.STDOUT)
checks=[];runs=[]
def check(v,msg):
 checks.append({'ok':bool(v),'check':msg})
 if not v:raise AssertionError(msg)
try:
 jar=http.cookiejar.CookieJar();client=urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
 for i in range(30):
  try:h=client.open('http://127.0.0.1:18481/vaak/?mode=login').read().decode();break
  except OSError:time.sleep(.1)
 token=re.search('name="csrf" value="([^"]+)"',h)[1]
 client.open('http://127.0.0.1:18481/vaak/?mode=login',data=urllib.parse.urlencode(dict(action='login',csrf=token,login='cmdr_nova',password='fixture-admin-password')).encode()).read()
 with tempfile.TemporaryDirectory(prefix='vaak-library-warm-cdp-') as tmp:
  chrome=subprocess.Popen(['/snap/chromium/current/usr/lib/chromium-browser/chrome','--headless','--no-sandbox','--disable-gpu','--disable-background-networking','--remote-allow-origins=*','--remote-debugging-port=18482','--user-data-dir='+tmp,'about:blank'],stdout=subprocess.DEVNULL,stderr=open(O/'chromium.log','w'))
  try:
   for i in range(100):
    try:tabs=json.load(urllib.request.urlopen('http://127.0.0.1:18482/json'));break
    except OSError:time.sleep(.05)
   ws=websocket.create_connection(next(t for t in tabs if t.get('type')=='page')['webSocketDebuggerUrl'],timeout=20);seq=0;events=[]
   def command(method,params={}):
    global seq
    seq+=1;ident=seq;ws.send(json.dumps({'id':ident,'method':method,'params':params}))
    while True:
     x=json.loads(ws.recv())
     if x.get('id')==ident:
      if 'error' in x:raise RuntimeError(x)
      return x.get('result',{})
     events.append(x)
     if x.get('method')=='Fetch.requestPaused':
      seq+=1;ws.send(json.dumps({'id':seq,'method':'Fetch.fulfillRequest','params':{'requestId':x['params']['requestId'],'responseCode':204,'responseHeaders':[]}}))
   def js(code):
    x=command('Runtime.evaluate',dict(expression=code,returnByValue=True,awaitPromise=True))
    if 'exceptionDetails' in x:raise RuntimeError(x)
    return x.get('result',{}).get('value')
   def wait(code):
    for i in range(100):
     if js(code):return
     time.sleep(.05)
    raise AssertionError('timeout '+code)
   command('Network.enable');command('Page.enable');command('Runtime.enable');command('Fetch.enable',{'patterns':[{'urlPattern':'https://*','requestStage':'Request'}]})
   for c in jar:command('Network.setCookie',dict(name=c.name,value=c.value,url='http://127.0.0.1:18481',path=c.path))
   for mode,view,save,remove in [('before','home',False,False),('before-privacy','bookmarks',False,False),('after','home',False,False),('after','bookmarks',False,False),('after','bookmarks',True,False),('after','bookmarks',False,True)]:
    source=(O/'before-media-warm-ap-admin.php').read_text() if mode=='before' else ((O/'before-referrer-policy-ap-admin.php').read_text() if mode=='before-privacy' else (R/'api/ap-admin.php').read_text())
    source=source.replace('/var/lib/mkultra/ap',str(O/'storage')).replace('/etc/mkultra/ap-inbox',str(O/'keys')).replace('/etc/mkultra/vaak.env',str(O/'fixture.env'));(A/'api/ap-admin.php').write_text(source)
    runid=str(len(runs))+mode+view+str(save)+str(remove)
    hook="window.__warmRun="+json.dumps(runid)+";window.__idle=[];window.requestIdleCallback=(f)=>{__idle.push(f);return __idle.length};Object.defineProperty(navigator,'connection',{value:{saveData:"+str(save).lower()+"},configurable:true});window.__images=[];"
    registration=command('Page.addScriptToEvaluateOnNewDocument',{'source':hook})['identifier'];start=len(events)
    command('Page.navigate',{'url':'http://127.0.0.1:18481/vaak/?view='+view});wait("window.__warmRun==="+json.dumps(runid)+" && document.readyState==='complete' && !!document.querySelector('section.main')")
    time.sleep(.1);js('0')
    errors=[v['params']['exceptionDetails'] for v in events[start:] if v.get('method')=='Runtime.exceptionThrown']; (O/'media-browser-current-errors.json').write_text(json.dumps(errors,indent=2))
    if mode=='before':check(any('viewName is not defined' in str(v) for v in errors),'genuine full Home document reproduces undefined viewName')
    else:check(not errors,'full '+view+' document no runtime exception saveData='+str(save)+' detached='+str(remove))
    if view=='bookmarks':check(js("!!document.getElementById('bookmarks-slot')"),'actual PHP Bookmarks slot exists')
    js("(()=>{const slot=document.getElementById('bookmarks-slot')||document.querySelector('section.main');for(const u of ['https://warm.invalid/a','https://warm.invalid/a','http://warm.invalid/b','https://warm.invalid/c','https://warm.invalid/d','https://warm.invalid/e']){const n=document.createElement('i');n.dataset.mediaWarmUrl=u;slot.append(n)}const outside=document.createElement('i');outside.dataset.mediaWarmUrl='https://warm.invalid/outside';document.body.append(outside)})()")
    if remove:js("document.getElementById('bookmarks-slot').remove()")
    js('for(const f of __idle.splice(0))f()');time.sleep(.25);js('0')
    media=[v['params']['request'] for v in events[start:] if v.get('method')=='Fetch.requestPaused' and v['params']['request']['url'].startswith('https://warm.invalid/')]
    images=[v['url'] for v in media]
    if mode=='before-privacy':check(bool(media) and all(any(k.lower()=='referer' for k in v['headers']) for v in media),'actual pre-repair image HTTP requests include Referer')
    else:check(all(not any(k.lower()=='referer' for k in v['headers']) for v in media),'actual repaired image HTTP requests omit Referer')
    wanted=['https://warm.invalid/a','https://warm.invalid/c','https://warm.invalid/d'] if view=='bookmarks' and not save and not remove else []
    check(images==wanted,'actual idle media requests bounded/current-slot/SaveData '+view+' '+str(save)+' '+str(remove))
    runs.append({'mode':mode,'view':view,'save_data':save,'detached':remove,'runtime_errors':errors,'images':images,'intercepted_image_requests':media,'admin_copy_sha256':hashlib.sha256(source.encode()).hexdigest()});command('Page.removeScriptToEvaluateOnNewDocument',{'identifier':registration})
   ws.close()
  finally:chrome.terminate();chrome.wait(timeout=10)
finally:
 server.terminate();server.wait(timeout=5);(O/'media-browser.json').write_text(json.dumps({'checks':checks,'runs':runs,'boundary':'Actual full PHP document/session/DOM/Chromium; idle callback scheduling controlled; real Image requests intercepted before network and fulfilled204, actual HTTP headers recorded; eligible URL nodes deliberately inserted inside real PHP Bookmarks slot.'},indent=2))
print(json.dumps({'passed':all(x['ok'] for x in checks),'checks':len(checks)}))
