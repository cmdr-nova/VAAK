// Notification nudge stream (window.VAAK_NOTIF_STREAM_TOKEN + /vaak/notif-stream):
// while the stream is live the badge polls slowly and refreshes on "notif";
// a closed stream (401/no route) falls back to the 12 s poll.
const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
const file=process.argv[2]||path.join(__dirname,'..','ap-admin.php');
const source=fs.readFileSync(file,'utf8');
const begin=source.indexOf('  // Notification + DM badges and tab title');const end=source.indexOf('\n})();\n\n// VAAK browser Web Push',begin);
assert(begin>0&&end>begin);
const js='(function(){\n'+source.slice(begin,end).replace(/<\?=([\s\S]*?)\?>/g,(_,expr)=>{
 const s=expr.trim();
 if(s.includes("$view === 'mentions'")||s.includes("$view === 'dms'"))return 'false';
 if(s.includes('array_combine'))return JSON.stringify({home:'Home',mentions:'Notifications',dms:'Messages'});
 if(s.includes('view_title'))return JSON.stringify('VAAK · Home');
 if(s.includes('$notifLastReadIdNav'))return '"0"';
 if(s.includes('$notifLatestUnreadIdNav'))return '"100"';
 if(s.includes('$dmLatestIdNav'))return '""';
 if(s.includes('$notifUnreadNav'))return '2';
 if(s.includes('$dmUnreadNav')||s.includes('$phyrianPendingNav'))return '0';
 throw Error('Undeclared PHP fixture binding '+s);
})+'\n})();';

function run(token){
 function target(){const l=new Map();return {addEventListener(k,f){const a=l.get(k)||[];a.push(f);l.set(k,a)},emit(k,e={}){for(const f of l.get(k)||[])f(e)}}}
 const win=target(),doc=target(),sw=target();win.VAAK_ACTOR_KEY='one';win.vaakGuestProfile=false;win.VAAK_NOTIF_STREAM_TOKEN=token;
 const elements={};for(const id of ['notif-badge','dm-badge','nav-phyrian-badge','nav-gaming-phyrian-badge'])elements[id]={id,hidden:true,textContent:'0',hasAttribute(){return false},setAttribute(){}};
 doc.getElementById=id=>elements[id];doc.visibilityState='visible';doc.title='';
 const sources=[];
 class EventSource{constructor(url){this.url=url;this.readyState=0;this.closed=false;const t=target();this.addEventListener=t.addEventListener;this.fire=(k)=>{this.readyState=1;t.emit(k)};sources.push(this)}close(){this.closed=true;this.readyState=2}}
 win.EventSource=EventSource;
 let now=0,serial=0;const timers=new Map(),calls=[];
 const fetch=(url,opts)=>{calls.push(url);return Promise.resolve({ok:true,json:async()=>({count:5,dm_count:0,latest_unread_id:'300',last_read_id:'0',latest_dm_id:'',phyrian_pending:0})})};
 const ctx=vm.createContext({window:win,document:doc,navigator:{serviceWorker:sw},fetch,AbortController,Promise,Number,String,Object,Array,Math,parseInt,encodeURIComponent,setTimeout:(f,ms)=>{const id=++serial;timers.set(id,{f,at:now+ms});return id},clearTimeout:id=>timers.delete(id)});
 vm.runInContext(js,ctx);
 const flush=async()=>{for(let n=0;n<20;n++)await Promise.resolve()};
 const advance=async(ms)=>{const till=now+ms;while(true){const due=[...timers].filter(([,t])=>t.at<=till).sort((a,b)=>a[1].at-b[1].at)[0];if(!due)break;now=due[1].at;timers.delete(due[0]);due[1].f();await flush()}now=till;await flush()};
 return {win,sources,calls,advance,flush,elements};
}
const done=[];function check(ok,label){assert(ok,label);done.push(label)}
(async()=>{
 const none=run('');check(none.sources.length===0,'no token opens no stream');
 await none.advance(12000);check(none.calls.length===1,'no token keeps the 12 s poll');

 const token='ab'.repeat(24);const t=run(token);
 check(t.sources.length===1&&t.sources[0].url==='/vaak/notif-stream?t='+token,'token opens the nudge stream');
 const s=t.sources[0];s.fire('ready');await t.flush();
 check(t.calls.length===0,'first ready does not poll');
 await t.advance(60000);check(t.calls.length===0,'live stream stops the 12 s poll');
 await t.advance(60000);check(t.calls.length===1,'live stream keeps a slow safety poll');
 s.fire('notif');await t.flush();check(t.calls.length===2&&t.elements['notif-badge'].textContent==='5','notif nudge refreshes the badge');
 let trendsRefreshed=0;t.win.novaRefreshDeferredTrends=()=>{trendsRefreshed++};
 s.fire('trends');await t.flush();check(trendsRefreshed===1&&t.calls.length===2,'trends nudge refreshes the trends sidebar, not the badge');
 s.readyState=0;s.onerror();s.fire('ready');await t.flush();check(t.calls.length===3,'ready after a reconnect polls once for missed changes');
 s.readyState=2;s.onerror();await t.flush();
 check(t.win.VAAK_NOTIF_STREAM_TOKEN==='','closed stream drops its token');
 const before=t.calls.length;await t.advance(12000);check(t.calls.length===before+1,'closed stream falls back to the 12 s poll');
 t.win.emit('pageshow');await t.flush();check(t.sources.length===1,'no reopen without a token');

 const p=run(token);p.win.emit('pagehide');check(p.sources[0].closed,'pagehide closes the stream');
 p.win.emit('pageshow');await p.flush();check(p.sources.length===2,'pageshow reopens the stream');
 console.log(JSON.stringify({file,checks:done.length,passed:true,labels:done},null,2));
})().catch(e=>{console.error(e);process.exit(1)});
