#!/usr/bin/env python3
"""Extract current/retained genuine loader into Chromium; transport and idle are recorded boundaries."""
import argparse,pathlib,json,hashlib,tempfile,subprocess,re,html
p=argparse.ArgumentParser();p.add_argument('--source',default='api/ap-admin.php');p.add_argument('--output',required=True);p.add_argument('--baseline',action='store_true');p.add_argument('--fallback',action='store_true');p.add_argument('--navigation',action='store_true');p.add_argument('--tabs',action='store_true');p.add_argument('--races',action='store_true');a=p.parse_args()
if a.races:a.navigation=True
s=pathlib.Path(a.source).read_text();start=s.index('// Cached global candidates are cheap;');end=s.index('  // Bookmarks are an intentionally selective hot set:',start);loader=s[start:end]+'})();'
start=s.index('  function updateChrome(view) {');end=s.index('  function setStatusLoading(',start);chrome=s[start:end]
if a.navigation:
 start=s.index('// Soft-nav for primary left-rail / timeline destinations.');end=s.index('</script>',start);chrome=s[start:end]
if a.tabs:
 start=s.index('  async function swapTimelineView(');end=s.index('  window.vaakSwapTimelineView =',start);chrome=s[start:end]
 chrome='''let tabSwapBusy=false,items,status,sentinel,newBtn,topBtn,viewName='home',limit=15,hasMore=false,offset=0,timelineGeneration=0,pendingHtml='',pendingCount=0,pendingSourceCount=0,newestTs=0,loading=false,pollBusy=false;const sc={setTop:()=>{}};const syncTimelineKindFlags=()=>{},stopTimelineStream=()=>{},stopTimelinePollTimer=()=>{},updateNewBtn=()=>{},loadDeferredSuggestions=()=>{},bindTopBtn=()=>{},bindNewPostsBtn=()=>{},ensureTimelinePollTimer=()=>{},syncTimelineStreamVisibility=()=>{};'''+chrome
common=r'''
const checks=[];const check=(v,n)=>{if(!v)throw Error(n);checks.push(n);};
const wait=(ms=75)=>new Promise(r=>setTimeout(r,ms));
const box=()=>document.getElementById('trends-sidebar');
const ok=html=>({ok:true,headers:{get:()=>null},text:()=>Promise.resolve(html)});
const idle=async()=>{await wait();while(idles.length)idles.shift()({didTimeout:false,timeRemaining:()=>20});await wait(0);};
const settle=async html=>{calls.at(-1).resolve(ok(html));await wait(0);await wait(0);};
'''
baseline=r'''
(async()=>{
check(calls.length===1,'baseline starts fetch synchronously before idle');
window.novaLoadDeferredTrends(false);window.novaLoadDeferredTrends(false);
check(calls.length===3,'baseline identical overlapping triggers start three fetches');
document.body.classList.add('blog-fullscreen');updateChrome('blog');
check(calls.length===4,'baseline hidden Blog still fetches');
document.body.classList.remove('blog-fullscreen');document.body.classList.add('dm-fullscreen');updateChrome('dms');
check(calls.length===5,'baseline hidden DM still fetches');
document.body.classList.remove('dm-fullscreen');window.resizeTo(600,800);document.body.classList.add('fixture-mobile');window.novaLoadDeferredTrends(false);
check(calls.length===6,'baseline hidden mobile still fetches');
document.getElementById('result').textContent=JSON.stringify({passed:checks.length,checks,fetches:calls.length});
})().catch(e=>document.getElementById('result').textContent=JSON.stringify({error:String(e),checks}));
'''
after=r'''
(async()=>{
check(calls.length===0,'all-page initial fetch deferred');await wait();check(calls.length===0&&idles.length===1,'two paints reach idle before network');
window.novaLoadDeferredTrends(false);window.novaLoadDeferredTrends(false);await idle();
check(calls.length===1,'same pending context coalesces');check(calls[0].options.cache==='no-store','no-store preserved');check(!calls[0].url.includes('refresh=1'),'no forced global collect');
check(!sessionStorage.getItem('vaak.trends.html.v3'),'old private browser HTML removed');await settle('HOME');
window.novaLoadDeferredTrends(false);window.novaLoadDeferredTrends(false);await idle();check(calls.length===1&&box().innerHTML==='HOME','same loaded context and reply refresh retain authorized cards without fetch');
document.querySelector('[name=action]').value='compose';document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));await idle();check(calls.length===1&&box().innerHTML==='HOME','compose does not invalidate sidebar');
updateChrome('search');check(box().innerHTML==='','context switch clears immediately');check(calls.length===1,'soft navigation network deferred');await idle();check(calls.length===2&&calls.at(-1).url.includes('from=search'),'soft navigation reads current context once');await settle('SEARCH');
updateChrome('blog');await idle();check(calls.length===2&&box().innerHTML==='','hidden Blog zero requests');updateChrome('dms');await idle();check(calls.length===2,'hidden DM zero requests');
updateChrome('home');document.body.classList.add('fixture-mobile');await idle();check(calls.length===2,'hidden mobile zero requests');document.body.classList.remove('fixture-mobile');window.dispatchEvent(new Event('resize'));await idle();check(calls.length===3,'hidden to visible requests lazily');await settle('VISIBLE');
box().dataset.from='status';window.novaLoadDeferredTrends(false);await idle();check(calls.length===4,'Open post page receives same lazy scheduling');await settle('STATUS');
box().dataset.from='home';window.novaLoadDeferredTrends(false);await idle();check(calls.length===5,'post detail to timeline context requests once');await settle('BACK');
window.novaLoadDeferredTrends(true);await idle();const withdrawn=calls.at(-1);window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true}));check(box().innerHTML===''&&withdrawn.options.signal.aborted,'pagehide clears and aborts');withdrawn.resolve(ok('STALE'));await wait(0);check(box().innerHTML==='','late pagehide response denied');
window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));check(calls.length===6,'history restore fetch deferred');await idle();check(calls.length===7,'bfcache revalidates once');await settle('RESTORED');
document.dispatchEvent(new Event('visibilitychange'));await idle();check(calls.length===7,'redundant visible event does not refetch');
Object.defineProperty(document,'hidden',{configurable:true,value:true});document.dispatchEvent(new Event('visibilitychange'));check(box().innerHTML==='','actual hidden transition clears');Object.defineProperty(document,'hidden',{configurable:true,value:false});document.dispatchEvent(new Event('visibilitychange'));await idle();check(calls.length===8,'actual background return revalidates lazily');await settle('RETURN');
window.novaLoadDeferredTrends(true);await idle();const old=calls.at(-1);updateChrome('local');check(old.options.signal.aborted,'context navigation aborts inflight');old.resolve(ok('OLD'));await wait(0);check(box().innerHTML==='','outdated context cannot paint');await idle();await settle('LOCAL');
window.novaLoadDeferredTrends(true);await idle();calls.at(-1).reject(Error('transport'));await wait(0);await wait(0);check(box().querySelector('[data-trends-retry]')&&!box().innerHTML.includes('LOCAL'),'failure shows accessible retry no stale cards');box().querySelector('button').click();await idle();await settle('RETRY');check(box().innerHTML==='RETRY','explicit retry works lazily');
document.querySelector('[name=action]').value='user_block_add';document.querySelector('form').dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));check(box().innerHTML==='','privacy mutation clears before POST');const n=calls.length;window.novaLoadDeferredTrends(false);await idle();check(calls.length===n,'privacy submission cannot race old-policy GET');
window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true}));window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));await idle();await settle('POLICY');check(box().innerHTML==='POLICY','history after mutation gets current policy');
const form=document.querySelector('form');form.addEventListener('submit',e=>e.preventDefault(),{once:true});form.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));await idle();await settle('CANCELLED');check(box().innerHTML==='CANCELLED','cancelled policy form safely revalidates');
const actions=['user_block_add','user_block_remove','block_actor','block_domain','block_add','unblock','mute_remote','unmute_remote','muted_word_add','muted_word_remove','deprioritize_remote','undeprioritize_remote','downrank_signal_clear','downrank_term_add','downrank_term_delete','list_mod_action','user_ban','user_unban','save_profile','switch_account','switch_account_add'];
form.target='native-policy-post';form.action='about:blank';
for(const action of actions){document.querySelector('[name=action]').value=action;form.requestSubmit();check(box().innerHTML==='',action+' actual native requestSubmit clears immediately');const count=calls.length;await idle();check(calls.length===count,action+' native POST cannot race old policy');window.dispatchEvent(new PageTransitionEvent('pagehide',{persisted:true}));window.dispatchEvent(new PageTransitionEvent('pageshow',{persisted:true}));await idle();await settle('CURRENT');}
const logout=document.createElement('a');logout.href='?logout=1';logout.textContent='Logout';document.body.appendChild(logout);logout.addEventListener('click',e=>e.preventDefault());logout.click();check(box().innerHTML==='','actual logout anchor clears prior account');
check(!sessionStorage.getItem('vaak.trends.html.v3'),'no private HTML persisted');
document.getElementById('result').textContent=JSON.stringify({passed:checks.length,checks,fetches:calls.length});
})().catch(e=>document.getElementById('result').textContent=JSON.stringify({error:String(e),checks,fetches:calls.length,idles:idles.length}));
'''
shell='''<!doctype html><style>.rail-right{width:300px;min-height:150px}.blog-fullscreen .rail-right,.dm-fullscreen .rail-right,.fixture-mobile .rail-right{display:none}</style><aside class="rail-right"><div id="trends-sidebar" data-from="home" data-deferred="1" data-paint-after="0">SKELETON</div></aside><iframe name="native-policy-post" hidden></iframe><form method="post"><input name="action" value="compose"></form><pre id="result"></pre><script>const calls=[],idles=[],TL_TITLES={};window.requestAnimationFrame=cb=>setTimeout(cb,0);window.requestIdleCallback=cb=>idles.push(cb);window.fetch=(url,options)=>new Promise((resolve,reject)=>calls.push({url,options,resolve,reject}));sessionStorage.setItem('vaak.trends.html.v3','OLD-OTHER-VIEWER');</script>'''
tabs=r'''(async()=>{await idle();await settle('INITIAL');const p=swapTimelineView('local',false);const main=calls.at(-1);check(box().innerHTML===''&&window.__vaakNavigationPending,'actual tab swap invalidates immediately');const count=calls.length;check(await swapTimelineView('feed',false)===false&&calls.length===count,'actual busy tab refuses duplicate fetch');await idle();check(calls.length===count,'actual pending tab main has priority');main.resolve(ok('<article class="tweet" data-sort="123">LOCAL</article>'));check(await p===true,'actual tab main succeeds');await idle();check(calls.at(-1).url.includes('from=local')&&!window.__vaakNavigationPending,'actual tab success schedules current context after main');await settle('LOCAL');const detached=swapTimelineView('feed',false);const old=calls.at(-1);document.getElementById('timeline-items').outerHTML='<div id="timeline-items" data-view="home"></div>';window.__vaakNavigationPending=false;window.novaSetDeferredTrendsView('home');await idle();await settle('CURRENT');old.resolve(ok('<article class="tweet">OLD FEED</article>'));await detached;await idle();check(box().dataset.from==='home'&&box().innerHTML==='CURRENT','detached old tab completion cannot overwrite current rail context');window.vaakRedirectToLogin=()=>{window.loginRedirected=true;};const denied=swapTimelineView('local',false);calls.at(-1).resolve({ok:false,status:401,headers:{get:()=>null}});check(await denied===false,'actual tab auth denial fails');await idle();check(window.loginRedirected&&box().innerHTML===''&&!window.__vaakNavigationPending,'actual tab denial clears prior rail and resets flag');const sameView=String(document.getElementById('timeline-items').dataset.view);const beforeDenied=calls.length;const sameDenied=swapTimelineView(sameView,true);calls.at(-1).resolve({ok:false,status:401,headers:{get:()=>null}});check(await sameDenied===false,'same-view push auth denial fails');await idle();check(calls.length===beforeDenied+1&&box().innerHTML==='','same-view auth denial starts no unauthenticated sidebar GET');document.getElementById('timeline-items').remove();const n=calls.length;check(await swapTimelineView('local',false)===false&&calls.length===n,'actual missing main early return starts no work');document.getElementById('result').textContent=JSON.stringify({passed:checks.length,checks,fetches:calls.length});})().catch(e=>document.getElementById('result').textContent=JSON.stringify({error:String(e),checks,fetches:calls.length}));'''
if a.tabs:shell=shell.replace('<aside class="rail-right">','<section class="main"><div id="timeline-items" data-view="home"></div><div id="timeline-status"></div><div id="timeline-sentinel"></div></section><aside class="rail-right">')
navigation=r'''(async()=>{
await idle();await settle('INITIAL');
const mainResponse=(view,content)=>({ok:true,status:200,headers:{get:k=>k==='X-VAAK-View'?view:null},text:()=>Promise.resolve(content||'<div class="feed"><div class="topbar"><h1>'+view+'</h1></div></div>')});
const start=calls.length;const search=window.vaakSoftNavTo('search',false,'',{q:'test'});check(window.__vaakNavigationPending===true&&box().innerHTML==='','actual main navigation clears prior sidebar');await idle();check(calls.length===start+1&&calls.at(-1).url.includes('view=search'),'main shell request alone precedes sidebar');
const searchCall=calls.at(-1);window.vaakSoftNavTo('followers',false,'',{network:'all'});check(calls.length===start+1,'queued latest destination does not race main fetch');searchCall.resolve(mainResponse('search'));await search;await wait(0);check(calls.at(-1).url.includes('view=followers'),'actual queued destination starts');await idle();check(calls.length===start+2,'queued main network blocks intermediate sidebar');calls.at(-1).resolve(mainResponse('followers'));await wait(0);await idle();check(calls.length===start+3&&calls.at(-1).url.includes('from=followers'),'only completed latest main paints sidebar');check(window.__vaakNavigationPending===false,'actual queued success clears navigation flag');await settle('FOLLOWERS');
const profile=window.vaakSoftNavProfile({actor:'https://example.test/users/alice',historyHref:'?view=remote_profile'},false);check(box().innerHTML==='','profile navigation invalidates before fetch');await idle();const profileCall=calls.at(-1);check(profileCall.url.includes('view=remote_profile'),'actual profile main starts first');profileCall.resolve(mainResponse('remote_profile'));await profile;await idle();check(calls.at(-1).url.includes('from=remote_profile')&&window.__vaakNavigationPending===false,'actual profile completion lazy-loads own sidebar context');await settle('PROFILE');
const home=window.vaakSoftNavTo('home',false);const homeCall=calls.at(-1);homeCall.resolve(mainResponse('home'));await home;await idle();check(calls.at(-1).url.includes('from=home')&&window.__vaakNavigationPending===false,'actual timeline shell completion defers sidebar');await settle('HOME');
window.vaakRedirectToLogin=()=>{window.loginRedirected=true;};const deniedCount=calls.length;const denied=window.vaakSoftNavTo('search',false);calls.at(-1).resolve({ok:false,status:401,headers:{get:()=>null}});await denied;await idle();check(window.loginRedirected&&box().innerHTML==='','actual auth failure clears sidebar and does not repaint');check(calls.length===deniedCount+1,'soft auth failure starts no sidebar GET');const profileDeniedCount=calls.length;const profileDenied=window.vaakSoftNavProfile({actor:'https://example.test/users/alice',historyHref:'?view=remote_profile'},false);calls.at(-1).resolve({ok:false,status:401,headers:{get:()=>null}});await profileDenied;await idle();check(calls.length===profileDeniedCount+1&&box().innerHTML==='','profile auth failure starts no sidebar GET');
document.getElementById('result').textContent=JSON.stringify({passed:checks.length,checks,fetches:calls.length});})().catch(e=>document.getElementById('result').textContent=JSON.stringify({error:String(e),checks,fetches:calls.length}));'''
races=r'''(async()=>{
await idle();await settle('INITIAL');
const mainResponse=view=>({ok:true,status:200,headers:{get:k=>k==='X-VAAK-View'?view:null},text:()=>Promise.resolve('<div class="feed"><div id="timeline-items" data-view="'+view+'"></div></div>')});
history.replaceState({vaakPosition:{top:0,window:0,offset:15}},'',location.href);
let releaseRestore;window.vaakRestoreTimelinePosition=()=>new Promise(resolve=>{releaseRestore=resolve;});
const started=calls.length;const nav=window.vaakSoftNavTo('home',false);calls.at(-1).resolve(mainResponse('home'));await wait();
check(typeof releaseRestore==='function'&&window.__vaakNavigationPending,'actual history navigation awaits timeline restoration');
await idle();check(calls.length===started+1,'idle consumed during pending restoration sends no background request');
releaseRestore();await nav;await idle();
check(calls.length===started+2&&calls.at(-1).url.includes('ajax=trends'),'completion re-arms sidebar after idle was consumed');await settle('HOME');
check(box().innerHTML==='HOME','current sidebar paints after history restoration');
window.novaLoadDeferredTrends(true);await wait();const staleIdle=idles.shift();check(typeof staleIdle==='function','scheduled idle held before context invalidation');
window.novaInvalidateDeferredTrends();box().dataset.from='search';window.novaLoadDeferredTrends(false);await wait();
const count=calls.length;staleIdle({didTimeout:true,timeRemaining:()=>0});await wait(0);check(calls.length===count,'invalidated old idle cannot dispatch in new context');
await idle();check(calls.length===count+1&&calls.at(-1).url.includes('from=search'),'new context has its own lazy schedule');await settle('SEARCH');
window.novaLoadDeferredTrends(true);await idle();const inflight=calls.at(-1);document.body.classList.add('fixture-mobile');
// Response text runs before the rail becomes visible again. Resize arrives
// while pending still coalesces; completion must not leave the rail empty.
const rects=box().getClientRects.bind(box());
box().getClientRects=()=>{box().getClientRects=rects;queueMicrotask(()=>{document.body.classList.remove('fixture-mobile');window.dispatchEvent(new Event('resize'));});return [];};
inflight.resolve(ok('HIDDEN'));
await wait(0);await idle();
check(box().innerHTML!=='HIDDEN','offscreen response cannot paint when discarded');
check(calls.length===count+3,'visible rail recovers after response/resize completion race');await settle('VISIBLE');check(box().innerHTML==='VISIBLE','recovered rail displays current-policy response');
document.getElementById('result').textContent=JSON.stringify({passed:checks.length,checks,fetches:calls.length});
})().catch(e=>document.getElementById('result').textContent=JSON.stringify({error:String(e),checks,fetches:calls.length,idles:idles.length}));'''
if a.navigation:shell=shell.replace('<aside class="rail-right">','<section class="main"><div class="feed"></div></section><aside class="rail-right">').replace('window.fetch=(url,options)=>', 'window.vaakBootTimeline=()=>true;window.fetch=(url,options)=>')
fallback=r'''(async()=>{check(calls.length===0,'fallback initial fetch deferred');await wait(300);check(calls.length===1,'no idle/paint/observer APIs still lazy loads visible rail');await settle('FIRST');document.body.classList.add('fixture-mobile');updateChrome('local');await wait(300);check(calls.length===1&&box().innerHTML==='','fallback hidden mobile never fetches');document.body.classList.remove('fixture-mobile');window.dispatchEvent(new Event('resize'));await wait(300);check(calls.length===2,'fallback resize loads visible rail');await settle('LOCAL');window.__vaakNavigationPending=true;updateChrome('search');await wait(300);check(calls.length===2,'pending main navigation takes priority');window.__vaakNavigationPending=false;updateChrome('search');await wait(300);check(calls.length===3,'completed main scheduling loads sidebar');await settle('SEARCH');document.getElementById('result').textContent=JSON.stringify({passed:checks.length,checks,fetches:calls.length});})().catch(e=>document.getElementById('result').textContent=JSON.stringify({error:String(e),checks}));'''
if a.fallback:shell=shell.replace('window.requestAnimationFrame=cb=>setTimeout(cb,0);window.requestIdleCallback=cb=>idles.push(cb);','delete window.requestAnimationFrame;delete window.requestIdleCallback;delete window.IntersectionObserver;')
page=shell+'<script>'+loader+'</script><script>'+chrome+'</script><script>'+common+(baseline if a.baseline else races if a.races else tabs if a.tabs else navigation if a.navigation else fallback if a.fallback else after)+'</script>'
with tempfile.TemporaryDirectory(prefix='vaak-sidebar-review-') as t:
 f=pathlib.Path(t)/'fixture.html';f.write_text(page)
 r=subprocess.run(['/snap/chromium/current/usr/lib/chromium-browser/chrome','--headless','--no-sandbox','--disable-gpu','--disable-background-networking','--user-data-dir='+t+'/profile','--virtual-time-budget=8000','--dump-dom',f.as_uri()],capture_output=True,text=True,timeout=30)
 m=re.search(r'<pre id="result">(.*?)</pre>',r.stdout,re.S)
 if not m:raise RuntimeError(r.stderr[-1500:])
 result=json.loads(html.unescape(m.group(1)));result.update(source_sha256=hashlib.sha256(s.encode()).hexdigest(),loader_sha256=hashlib.sha256(loader.encode()).hexdigest(),chrome_sha256=hashlib.sha256(chrome.encode()).hexdigest(),boundary='Actual extracted loader/updateChrome in Chromium; shell CSS, fetch transport, paint/idle clocks and document.hidden overrides are declared fixtures; no live server/production latency proof.')
 pathlib.Path(a.output).write_text(json.dumps(result,indent=2)+'\n');print(json.dumps(result));
 if 'error' in result:raise SystemExit(1)
