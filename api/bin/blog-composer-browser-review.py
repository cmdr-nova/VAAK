#!/usr/bin/env python3
"""Local headless Chromium CDP gate; uses extracted source fixture, not live auth."""
import subprocess,tempfile,time,json,urllib.request,websocket,argparse,pathlib
parser=argparse.ArgumentParser();parser.add_argument('--fixture',default='/tmp/vaak-blog-composer-browser.html');parser.add_argument('--baseline',default='/tmp/vaak-blog-before-browser.html');parser.add_argument('--output',default='/tmp/vaak-blog-browser-review.json');args=parser.parse_args()
chrome='/snap/chromium/current/usr/lib/chromium-browser/chrome'
profile=tempfile.mkdtemp(prefix='vaak-blog-chrome-')
p=subprocess.Popen([chrome,'--headless','--no-sandbox','--disable-gpu','--remote-debugging-port=18993','--remote-allow-origins=*','--user-data-dir='+profile,'about:blank'],stdout=subprocess.DEVNULL,stderr=open('/tmp/vaak-blog-chrome.log','w'))
try:
 for _ in range(100):
  try: tabs=json.load(urllib.request.urlopen('http://127.0.0.1:18993/json'));break
  except Exception:time.sleep(.1)
 ws=websocket.create_connection(next(t for t in tabs if t['type']=='page')['webSocketDebuggerUrl'],timeout=15);seq=0
 def cmd(method,params={}):
  global seq
  seq+=1;ws.send(json.dumps({'id':seq,'method':method,'params':params}))
  while True:
   v=json.loads(ws.recv())
   if v.get('id')==seq:return v
 frame=cmd('Page.getFrameTree')['result']['frameTree']['frame']['id']
 html=open(args.fixture).read()
 rows=[]
 for width,height,touch in [(390,844,True),(844,390,True),(1366,900,False)]:
  cmd('Emulation.setDeviceMetricsOverride',{'width':width,'height':height,'deviceScaleFactor':1,'mobile':touch})
  cmd('Emulation.setTouchEmulationEnabled',{'enabled':touch})
  cmd('Page.setDocumentContent',{'frameId':frame,'html':html});time.sleep(.3)

  r=cmd('Runtime.evaluate',{'expression':'runBlogControls()','awaitPromise':True,'returnByValue':True})
  rows.append({'viewport':[width,height],'touch':touch,'result':r})
 # Actual resize closes a currently open drawer when crossing breakpoint.
 cmd('Emulation.setDeviceMetricsOverride',{'width':390,'height':844,'deviceScaleFactor':1,'mobile':True});cmd('Page.setDocumentContent',{'frameId':frame,'html':html});time.sleep(.3)
 cmd('Runtime.evaluate',{'expression':"document.getElementById('mobile-menu-btn').click()"})
 cmd('Emulation.setDeviceMetricsOverride',{'width':1366,'height':900,'deviceScaleFactor':1,'mobile':False});time.sleep(.4)
 rows.append({'resize':cmd('Runtime.evaluate',{'expression':"({closed:!document.body.classList.contains('mobile-nav-open'),expanded:document.getElementById('mobile-menu-btn').getAttribute('aria-expanded'),hidden:document.getElementById('mobile-nav-backdrop').hidden})",'returnByValue':True})})
 cmd('Emulation.setDeviceMetricsOverride',{'width':390,'height':844,'deviceScaleFactor':1,'mobile':True});cmd('Page.setDocumentContent',{'frameId':frame,'html':open(args.baseline).read()});time.sleep(.3)
 rows.append({'baseline_css':cmd('Runtime.evaluate',{'expression':"document.getElementById('mobile-menu-btn').click();({display:getComputedStyle(document.getElementById('admin-rail-left')).display,expanded:document.getElementById('mobile-menu-btn').getAttribute('aria-expanded'),locked:document.body.classList.contains('mobile-nav-open')})",'returnByValue':True})})
 for row in rows[:3]:
  assert 'exceptionDetails' not in row['result'].get('result',{}),row
 assert rows[3]['resize']['result']['result']['value']=={'closed':True,'expanded':'false','hidden':True},rows[3]
 assert rows[4]['baseline_css']['result']['result']['value']=={'display':'none','expanded':'true','locked':True},rows[4]
 print(json.dumps(rows,indent=2));open(args.output,'w').write(json.dumps(rows,indent=2))
finally:p.terminate();p.wait(timeout=10)
