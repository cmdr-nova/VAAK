#!/usr/bin/env python3
"""Actual sidebar JavaScript in Chromium; recorded fetch transport, real DOM/events."""
import pathlib,tempfile,subprocess,json,re,hashlib,argparse
parser=argparse.ArgumentParser();parser.add_argument('--output',required=True);args=parser.parse_args()
source=pathlib.Path('api/ap-admin.php').read_text()
start=source.index('// Cached global candidates are cheap;');end=source.index('  // Bookmarks are an intentionally selective hot set:',start);script=source[start:end]+'})();'
start=source.index('  function updateChrome(view) {');end=source.index('  function setStatusLoading(',start);chrome=source[start:end]
controls=r'''
const checks=[]; function check(v,n){if(!v)throw new Error(n);checks.push(n);}
const flush=()=>new Promise(r=>setTimeout(r,0));
const good=(html)=>({ok:true,headers:{get:()=>null},text:()=>Promise.resolve(html)});
(async()=>{
 check(calls.length===1,'initial current policy fetch');check(calls[0].options.cache==='no-store','fetch cache disabled');check(!calls[0].url.includes('refresh=1'),'no forced global refresh');
 check(!sessionStorage.getItem('vaak.trends.html.v3'),'old unscoped store removed');
 check(!document.getElementById('trends-sidebar').innerHTML.includes('OLD-OTHER-VIEWER'),'old browser HTML never replayed');
 calls[0].resolve(good('FIRST'));await flush();await flush();check(document.getElementById('trends-sidebar').innerHTML==='FIRST','authorized response painted');
 const a=window.novaLoadDeferredTrends(false);const old=calls.at(-1);const b=window.novaLoadDeferredTrends(false);const newer=calls.at(-1);
 check(old.options.signal.aborted,'new request aborts previous');newer.resolve(good('NEW'));await b;old.resolve(good('OLD'));await a;
 check(document.getElementById('trends-sidebar').innerHTML==='NEW','late older response rejected');
 const detach=window.novaLoadDeferredTrends(false);const detachedCall=calls.at(-1);document.getElementById('trends-sidebar').outerHTML='<div id="trends-sidebar" data-from="profile" data-deferred="1">REPLACEMENT</div>';detachedCall.resolve(good('DETACHED'));await detach;
 check(document.getElementById('trends-sidebar').innerHTML==='REPLACEMENT','detached response rejected');
 const fail=window.novaLoadDeferredTrends(false);calls.at(-1).reject(new Error('fixture failed'));await fail;check(document.getElementById('trends-sidebar').innerHTML==='','failed current policy leaves no stale cards');
 updateChrome('search');const nav=calls.at(-1);check(nav.url.includes('from=search'),'actual soft navigation revalidates current view');nav.resolve(good('SEARCH'));await flush();await flush();
 const n=calls.length;window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));check(calls.length===n+1,'bfcache restore revalidates');calls.at(-1).resolve(good('RESTORED'));await flush();await flush();
 document.dispatchEvent(new Event('visibilitychange'));check(calls.length===n+2,'visible page revalidates changed policy');calls.at(-1).resolve(good('VISIBLE'));await flush();await flush();
 check(!sessionStorage.getItem('vaak.trends.html.v3'),'current HTML never persisted in browser store');
 const beforeHide=window.novaLoadDeferredTrends(false);const hideCall=calls.at(-1);
 window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true}));check(document.getElementById('trends-sidebar').innerHTML==='','pagehide clears bfcache cards');
 check(hideCall.options.signal.aborted,'pagehide aborts inflight response');hideCall.resolve(good('OLD-HIDDEN'));await beforeHide;check(document.getElementById('trends-sidebar').innerHTML==='','pagehide late response cannot paint');
 document.getElementById('result').textContent=JSON.stringify({passed:checks.length,checks});
})().catch(e=>{document.getElementById('result').textContent=JSON.stringify({error:String(e),checks});});
'''
html='<!doctype html><div id="trends-sidebar" data-from="home" data-deferred="1" data-paint-after="0">SKELETON</div><pre id="result"></pre><script>const calls=[];const TL_TITLES={};sessionStorage.setItem("vaak.trends.html.v3",JSON.stringify({html:"OLD-OTHER-VIEWER",at:Date.now()}));window.fetch=(url,options)=>new Promise((resolve,reject)=>calls.push({url,options,resolve,reject}));</script><script>'+script+'</script><script>'+chrome+'</script><script>'+controls+'</script>'
with tempfile.TemporaryDirectory(prefix='vaak-trends-browser-') as tmp:
 path=pathlib.Path(tmp)/'fixture.html';path.write_text(html)
 proc=subprocess.run(['/snap/chromium/current/usr/lib/chromium-browser/chrome','--headless','--no-sandbox','--disable-gpu','--disable-background-networking','--user-data-dir='+tmp+'/profile','--virtual-time-budget=2500','--dump-dom',path.as_uri()],text=True,capture_output=True,timeout=30)
 match=re.search(r'<pre id="result">(.*?)</pre>',proc.stdout,re.S)
 if not match:raise RuntimeError('Chromium produced no results: '+proc.stderr[-1000:])
 import html as htmlmodule
 result=json.loads(htmlmodule.unescape(match.group(1)))
 result.update({'javascript_sha256':hashlib.sha256(script.encode()).hexdigest(),'soft_navigation_sha256':hashlib.sha256(chrome.encode()).hexdigest(),'boundary':'actual extracted JavaScript and soft-navigation update; Chromium DOM/events, recorded fetch and fixed sidebar shell'})
 pathlib.Path(args.output).write_text(json.dumps(result,indent=2)+'\n')
 print(json.dumps({k:result[k] for k in ['passed'] if k in result}))
 if 'error' in result:raise RuntimeError(result['error'])
