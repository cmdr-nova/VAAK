#!/usr/bin/env python3
"""Actual timeline reconciliation declarations in Chromium; recorded fetch transport."""
import argparse, pathlib, re, json, tempfile, subprocess, html, hashlib
p=argparse.ArgumentParser();p.add_argument('--output', required=True);p.add_argument('--source', default='api/ap-admin.php');p.add_argument('--before', action='store_true');a=p.parse_args()
s=pathlib.Path(a.source).read_text()
key=s[s.index('  function seenKeys() {' if a.before else '  function timelineCardKey('):s.index('  function nearTop()',s.index('  function seenKeys() {' if a.before else '  function timelineCardKey('))]
insert=s[s.index('  function insertPending('):s.index('  function armStickToTop()',s.index('  function insertPending('))]
script=key+insert
setup=r'''
let items=document.getElementById('timeline-items'), offset=12, pendingHtml='',pendingCount=0,pendingSourceCount=0,pollBusy=false,timelineGeneration=0,newestTs=1791720000,viewName='home',limit=12,isNotifTimeline=false,isOutboxTimeline=false,isBskyTimeline=false;
let topValue=300;const sc={height:()=>items.children.length*100,top:()=>topValue,setTop:v=>{topValue=v;}};function nearTop(){return false;}function updateNewBtn(){}
const calls=[];window.fetch=(url,options)=>new Promise(resolve=>calls.push({url,options,resolve}));
const checks=[];function check(v,n){if(!v)throw Error(n);checks.push(n);}const flush=()=>new Promise(r=>setTimeout(r,0));
const card=(id,body='body')=>`<article class="tweet"><a href="?view=post&object=${encodeURIComponent('https://mkultra.monster/users/a/notes/'+id)}">post</a><p>${body}</p></article>`;
const thread=(parent,tip,media='')=>`<article class="tweet tweet-thread"><div class="tweet-thread-seg tweet-thread-seg--ancestor" data-note-id="https://mkultra.monster/users/a/notes/${parent}"><a href="?object=${encodeURIComponent('https://mkultra.monster/users/a/notes/'+parent)}">parent</a></div><div class="tweet-thread-rail"></div><div class="tweet-thread-seg tweet-thread-seg--tip" data-note-id="https://mkultra.monster/users/a/notes/${tip}"><a href="?object=${encodeURIComponent('https://mkultra.monster/users/a/notes/'+tip)}">tip</a>${media}</div></article>`;
const good=(body,newest=1791720000)=>({ok:true,status:200,headers:{get:n=>n==='X-Newest'?String(newest):null},text:()=>Promise.resolve(body)});
'''
controls=r'''
(async()=>{
for(const media of ['', '<img src="https://media.test/one.webp" alt="one">']){
 items.innerHTML=card('parent');pendingHtml='';pendingCount=0;offset=12;topValue=300;
 const fresh=filterNewHtml(thread('parent','tip',media));check(fresh.count===1,'new self reply retained despite known parent '+!!media);
 pendingHtml=fresh.html;pendingCount=fresh.count;pendingSourceCount=fresh.sourceCount;check(filterNewHtml(thread('parent','tip',media)).count===0,'pending duplicate suppressed '+!!media);
 insertPending({scrollToTop:false});check(items.children.length===1&&items.firstElementChild.classList.contains('tweet-thread'),'parent replaced on insertion '+!!media);
 check(items.querySelectorAll('.tweet-thread-seg').length===2,'both thread segments retained '+!!media);
 check(items.querySelectorAll('img').length===(media?1:0),'media retained '+!!media);
 check(offset===13,'new source entry advances existing offset once '+!!media);check(topValue===300,'scroll anchor retained for equal height replacement '+!!media);
 check(filterNewHtml(card('parent')).count===0,'later parent suppressed '+!!media);
}
 items.innerHTML='';pendingHtml='';pendingCount=0;pendingSourceCount=0;offset=12;
 const two=filterNewHtml(thread('new-parent','new-tip')+card('new-parent'));check(two.count===1&&two.sourceCount===2,'new parent plus reply consumes two sources but paints one thread');
 pendingHtml=two.html;pendingCount=two.count;pendingSourceCount=two.sourceCount;insertPending({scrollToTop:false});check(offset===14&&items.children.length===1,'raw source offset survives thread fold');
 items.innerHTML=card('parent');pendingHtml='';pendingCount=0;pendingSourceCount=0;viewName='home';
 const ordinary=pollNewer();check(calls.length===1,'ordinary request starts');
 const forced=pollNewer({freshPost:true,published:new Date(newestTs*1000).toISOString()});check(calls.length===1,'publish waits for in-flight poll');
 calls[0].resolve(good(''));await ordinary;await flush();check(calls.length===2,'publish issues its own fresh request');
 check(calls[1].url.includes('fresh_post=1'),'fresh current-SQL request flag');check(calls[1].options.cache==='no-store','fresh request bypasses HTTP cache');
 check(calls[1].url.includes('since=1791720000')===false,'published same-second overlap included');
 calls[1].resolve(good(card('new')));await forced;check(pendingCount===1,'fresh posted card buffered');insertPending({scrollToTop:false});check(items.children.length===2,'new card visible');
 const late=pollNewer();const c=calls.at(-1);const oldItems=items;items=document.createElement('div');items.id='timeline-items';oldItems.replaceWith(items);viewName='local';
 c.resolve(good(card('wrong-view'),1791720999));await late;check(items.children.length===0&&pendingCount===0,'old view response cannot paint after navigation');check(newestTs===1791720000,'old view response cannot advance new cursor');
 viewName='home';const aba=pollNewer();const abaCall=calls.at(-1);timelineGeneration++;viewName='local';timelineGeneration++;viewName='home';
 abaCall.resolve(good(card('old-generation'),1791720999));await aba;check(items.children.length===0&&newestTs===1791720000,'same-node Home to Local to Home rejects old generation');
 document.getElementById('result').textContent=JSON.stringify({passed:checks.length,checks});
})().catch(e=>document.getElementById('result').textContent=JSON.stringify({error:String(e),checks}));
'''
if a.before:
 controls=r"""
 (async()=>{items.innerHTML=card('parent');
 check(filterNewHtml(thread('parent','tip','<img src="https://media.test/one.webp">')).count===0,'BEFORE actual filter drops new self reply because parent already painted');
 pollBusy=true;await pollNewer({freshPost:true,published:new Date(newestTs*1000).toISOString()});check(calls.length===0,'BEFORE actual busy poll drops publication refresh');
 document.getElementById('result').textContent=JSON.stringify({passed:checks.length,checks});
 })().catch(e=>document.getElementById('result').textContent=JSON.stringify({error:String(e),checks}));
 """
with tempfile.TemporaryDirectory(prefix='vaak-post-thread-browser-') as d:
 f=pathlib.Path(d)/'fixture.html';f.write_text('<!doctype html><div id="timeline-items"></div><pre id="result"></pre><script>'+setup+script+controls+'</script>')
 r=subprocess.run(['/snap/chromium/current/usr/lib/chromium-browser/chrome','--headless','--no-sandbox','--disable-gpu','--disable-background-networking','--user-data-dir='+d+'/profile','--virtual-time-budget=3500','--dump-dom',f.as_uri()],capture_output=True,text=True,timeout=30)
 m=re.search(r'<pre id="result">(.*?)</pre>',r.stdout,re.S)
 if not m:raise RuntimeError(r.stderr[-1500:])
 result=json.loads(html.unescape(m[1]));result['javascript_sha256']=hashlib.sha256(script.encode()).hexdigest();result['boundary']='Actual extracted declarations; Chromium DOM and recorded HTTP fetch. No real publication/server request.'
 pathlib.Path(a.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result));
 if 'error' in result:raise RuntimeError(result['error'])
