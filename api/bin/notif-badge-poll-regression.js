const fs=require('fs'),vm=require('vm'),assert=require('assert'),path=require('path');
const args=process.argv.slice(2);
const flag=args.find((arg)=>arg.startsWith('--'))||'';
const abortCapable=flag!=='--without-abort-controller';
const file=args.find((arg)=>!arg.startsWith('--'))||path.join(__dirname,'..','ap-admin.php');
const source=fs.readFileSync(file,'utf8');
const begin=source.indexOf('  // Notification + DM badges and tab title');const end=source.indexOf('\n})();\n\n// VAAK browser Web Push',begin);
assert(begin>0&&end>begin);
let js='(function(){\n'+source.slice(begin,end)+'\n})();';
const bindings=[];
js=js.replace(/<\?=([\s\S]*?)\?>/g,(_,expr)=>{
 const s=expr.trim();let value;
 if(s.includes("$view === 'mentions'")||s.includes("$view === 'dms'"))value=false;
 else if(s.includes('array_combine'))value={home:'Home',local:'Local',mentions:'Notifications',dms:'Messages',rss:'RSS'};
 else if(s.includes('view_title'))value='VAAK · Home';
 else if(s.includes('$notifLastReadIdNav'))value='0';
 else if(s.includes('$notifLatestUnreadIdNav'))value='100';
 else if(s.includes('$dmLatestIdNav'))value='';
 else if(s.includes('$notifUnreadNav'))value=2;
 else if(s.includes('$dmUnreadNav')||s.includes('$phyrianPendingNav'))value=0;
 else throw Error('Undeclared PHP fixture binding '+s);
 bindings.push({expression:s,value});return JSON.stringify(value);
});
function target(){const listeners=new Map();return {addEventListener(k,f){const a=listeners.get(k)||[];a.push(f);listeners.set(k,a)},emit(k,e={}){for(const f of listeners.get(k)||[])f(e)}}}
const win=target(),doc=target(),sw=target();win.VAAK_ACTOR_KEY='one';win.vaakGuestProfile=false;
const elements={};for(const id of ['notif-badge','dm-badge','nav-phyrian-badge','nav-you-phyrian-badge'])elements[id]={id,hidden:true,textContent:'0',hasAttribute(){return false},setAttribute(){}};
doc.getElementById=id=>elements[id];doc.visibilityState='visible';doc.title='';
let now=0,serial=0;const timers=new Map(),calls=[],queue=[];const flush=async()=>{for(let n=0;n<20;n++)await Promise.resolve()};
function defer(){let resolve,reject;const promise=new Promise((a,b)=>{resolve=a;reject=b});return{promise,resolve,reject}}
const fetch=(url,opts)=>{const d=defer();calls.push({url,opts,d});const action=queue.shift();if(action)action(d);return d.promise};
const ctx=vm.createContext({window:win,document:doc,navigator:{serviceWorker:sw},fetch,AbortController:abortCapable?AbortController:undefined,Promise,Number,String,Object,Array,Math,parseInt,setTimeout:(f,ms)=>{const id=++serial;timers.set(id,{f,at:now+ms});return id},clearTimeout:id=>timers.delete(id)});vm.runInContext(js,ctx);
const advance=async(ms)=>{const till=now+ms;while(true){const due=[...timers].filter(([id,t])=>t.at<=till).sort((a,b)=>a[1].at-b[1].at)[0];if(!due)break;now=due[1].at;timers.delete(due[0]);due[1].f();await flush()}now=till;await flush()};
const response=(count=3,dm=1,extra={})=>({ok:true,json:async()=>({count,dm_count:dm,latest_unread_id:'200',last_read_id:'0',latest_dm_id:'4',phyrian_pending:0,...extra})});
const result=[];function check(ok,label){assert(ok,label);result.push(label)}
(async()=>{
 if(flag==='--baseline-hang'){await advance(12000);await advance(60000);check(calls.length===1,'one unresolved periodic request prevents every later scheduled poll');console.log(JSON.stringify({file,baseline_hang_reproduced:true,elapsed_fake_ms:now,requests:calls.length}));return;}
 check(elements['notif-badge'].textContent==='2','genuine initial seed retained');
 const a=win.vaakPollNotif(),b=win.vaakPollNotif();sw.emit('message',{data:{type:'vaak-push'}});win.emit('vaak:mobile-nav-open');check(calls.length===1&&a===b,'manual/push/mobile coalesce same in-flight request');
 await advance(5000);check((abortCapable?calls[0].opts.signal.aborted:calls[0].opts.signal===undefined),'deadline releases never resolving transport with or without abort support');check(elements['notif-badge'].textContent==='2','timeout preserves seeded badge');
 await a;await flush();queue.push(d=>d.resolve(response()));await win.vaakPollNotif();check(elements['notif-badge'].textContent==='3'&&!elements['notif-badge'].hidden,'next successful request recovers after never resolving fetch');
 calls[0].d.resolve(response(70,70));await flush();check(elements['notif-badge'].textContent==='3','late timed-out result cannot overwrite recovered state');
 const before=calls.length;queue.push(d=>d.resolve(response(4,2)));await advance(12000-now);check(calls.length===before+1&&elements['notif-badge'].textContent==='4','scheduled foreground loop survives timeout');
 queue.push(d=>d.resolve({ok:false}));await win.vaakPollNotif();check(elements['notif-badge'].textContent==='4','HTTP failure preserves last valid state');
 queue.push(d=>d.reject(Error('network')));await win.vaakPollNotif();check(elements['notif-badge'].textContent==='4','network rejection preserves last valid state');
 queue.push(d=>d.resolve({ok:true,json:async()=>({count:undefined})}));await win.vaakPollNotif();check(elements['notif-badge'].textContent==='4','malformed JSON shape preserves valid badge');
 queue.push(d=>d.resolve({ok:true,json:async()=>{throw Error('json')}}));await win.vaakPollNotif();check(elements['notif-badge'].textContent==='4','JSON decoding rejection preserves badge');
 const body=defer();queue.push(d=>d.resolve({ok:true,json:()=>body.promise}));const j=win.vaakPollNotif();await flush();await advance(5000);await j;check((abortCapable?calls.at(-1).opts.signal.aborted:calls.at(-1).opts.signal===undefined),'deadline also bounds never resolving JSON body');body.resolve({count:80,dm_count:80});await flush();check(elements['notif-badge'].textContent==='4','late JSON body cannot overwrite badge');
 const old=win.vaakPollNotif();const oldCall=calls.at(-1);win.vaakSetCurrentViewTitle('mentions');await old;check((abortCapable?oldCall.opts.signal.aborted:oldCall.opts.signal===undefined),'soft view change cancels old flight');check(elements['notif-badge'].hidden,'Notifications view suppresses seeded badge');oldCall.d.resolve(response(90,1));await flush();check(elements['notif-badge'].hidden,'cancelled previous-view response cannot repaint badge');
 queue.push(d=>d.resolve(response(9,5)));await win.vaakPollNotif();check(elements['notif-badge'].hidden&&doc.title.startsWith('(✉5)'),'active Notifications suppresses only notification badge');win.vaakSetCurrentViewTitle('dms');check(elements['dm-badge'].hidden&&!elements['notif-badge'].hidden,'active Messages suppresses only DM badge');
 win.vaakSetCurrentViewTitle('home');const own=win.vaakPollNotif();win.VAAK_ACTOR_KEY='two';calls.at(-1).d.resolve(response(77,77));await own;check(elements['notif-badge'].textContent==='9','changed-owner response cannot repaint badges');
 const start=calls.length;win.vaakGuestProfile=true;await win.vaakPollNotif();check(calls.length===start,'guest performs no badge request');win.vaakGuestProfile=false;
 const p=win.vaakPollNotif();const pc=calls.at(-1);win.emit('pagehide');await p;check((abortCapable?pc.opts.signal.aborted:pc.opts.signal===undefined),'pagehide cancels active request');const n=calls.length;await advance(60000);check(calls.length===n,'pagehide stops owned timer');
 queue.push(d=>d.resolve(response(6,0)));win.emit('pageshow');await flush();check(calls.length===n+1&&elements['notif-badge'].textContent==='6','pageshow restarts and refreshes badge');
 queue.push(d=>d.resolve(response(7,0)));doc.emit('visibilitychange');await flush();check(elements['notif-badge'].textContent==='7','visible return refreshes badge');
 queue.push(d=>d.resolve(response(8,0)));win.emit('vaak:mobile-nav-open');await flush();check(elements['notif-badge'].textContent==='8','mobile menu trigger refreshes badge');
 queue.push(d=>d.resolve(response(10,0)));sw.emit('message',{data:{type:'vaak-push'}});await flush();check(elements['notif-badge'].textContent==='10','service worker push refreshes badge');
 win.__vaakPendingPostCount=3;win.vaakRenderInboxTitle();check(doc.title.startsWith('(3'),'pending timeline posts prefix the tab title');
 win.__vaakPendingPostCount=0;win.vaakRenderInboxTitle();check(!doc.title.startsWith('(3'),'clearing pending posts removes that prefix');
 check(calls.every(c=>c.url==='?ajax=notif_unread'&&c.opts.credentials==='same-origin'&&c.opts.cache==='no-store'),'current authenticated endpoint semantics preserved');
 console.log(JSON.stringify({file,abort_capable:abortCapable,checks:result.length,passed:true,bindings,labels:result},null,2));
})().catch(e=>{console.error(e.stack);process.exitCode=1});
