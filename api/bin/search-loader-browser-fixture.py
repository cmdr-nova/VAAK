#!/usr/bin/env python3
"""Verbatim actual Search boot JS; canonical PHP pages, isolated HTTP/visibility IO."""
import argparse,json,pathlib
p=argparse.ArgumentParser();p.add_argument('--fragments',required=True);p.add_argument('--source');p.add_argument('--expect-zero-truncation',action='store_true');p.add_argument('--output',default='/tmp/vaak-search-loader-browser.html');args=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2]
source=pathlib.Path(args.source).read_text() if args.source else (root/'api/ap-admin.php').read_text()
a=source.index('(function () {',source.index('// Favourites, Bookmarks, Followers, Following, and Search'));b=source.index('\n</script>',a);boot=source[a:b]
corpus=json.loads(pathlib.Path(args.fragments).read_text())
setup=r'''
window.observers=[];window.requests=[];window.responseQueue=[];window.redirects=[];window.enhanced=[];
window.vaakRedirectToLogin=(reason)=>redirects.push(reason);window.novaEnhanceTweetFolds=(list)=>enhanced.push(list);
window.IntersectionObserver=class {constructor(callback){this.callback=callback;this.nodes=new Set();observers.push(this);}observe(el){this.nodes.add(el);}unobserve(el){this.nodes.delete(el);}disconnect(){this.nodes.clear();}};
window.visibility=(el)=>{for(const io of observers){if(io.nodes.has(el))io.callback([{target:el,isIntersecting:true}]);}};
window.fetch=async(url,opts)=>{
 const u=new URL(url,location.href);requests.push({params:Object.fromEntries(u.searchParams),credentials:opts.credentials,cache:opts.cache,accept:opts.headers.Accept});
 const item=responseQueue.shift();if(!item)throw Error('fixture response exhausted');if(item.error)throw Error('fixture network failure');
 if(item.delay)await new Promise(resolve=>setTimeout(resolve,item.delay));
 return{ok:!(item.status>=400),status:item.status||200,headers:new Headers(item.headers||{}),text:async()=>item.html||''};
};
'''
checks=r'''
let count=0;const findings=[];const main=document.querySelector('section.main');
function check(v,m){if(!v)throw Error(m);count++;}
const tick=()=>new Promise(r=>setTimeout(r,0));async function settle(){for(let i=0;i<8;i++)await tick();}
function install(section='statuses',resolve='1',offset='0'){
 main.innerHTML='<div class="feed"><div data-search-list data-section="'+section+'" data-q="café &amp; tea" data-resolve="'+resolve+'" data-offset="'+offset+'" data-limit="10" data-has-more="1"><div data-search-sentinel>Scroll for more…</div></div></div>';
 window.vaakBootSearch(main);return main.querySelector('[data-search-list]');
}
function sentinel(list){return list.querySelector('[data-search-sentinel]');}
function page(offset,more='1'){const holder=document.createElement('div');holder.innerHTML=CORPUS.first.html;const f=holder.firstElementChild;if(offset===null)delete f.dataset.offset;else f.dataset.offset=offset;if(more===null)delete f.dataset.hasMore;else f.dataset.hasMore=more;return holder.innerHTML;}
async function send(list,response){responseQueue=[response];visibility(sentinel(list));await settle();}
async function run(){
 let list=install();const start=requests.length;await send(list,{html:CORPUS.first.html});
 check(requests.length===start+1,'one actual empty page request');
 const req=requests.at(-1);check(req.params.ajax==='search_page'&&req.params.section==='statuses'&&req.params.q==='café & tea'&&req.params.offset==='0'&&req.params.limit==='10'&&req.params.resolve==='1','preserves exact query/section/resolve/raw source cursor');
 check(req.credentials==='same-origin'&&req.cache==='no-store'&&req.accept==='text/html','authenticated no-store fragment transport');
 check(list.querySelectorAll('article.tweet').length===0&&!main.querySelector('.timeline-skeleton'),'canonical denied page remains empty without loading skeleton');
 if(EXPECT_TRUNCATION){check(list.dataset.hasMore==='0'&&list.dataset.offset==='0','HEAD reproduces premature stop before offset10');findings.push('canonical empty page offset10/hasMore1 incorrectly ends at offset0');}
 else {
  check(list.dataset.offset==='10'&&list.dataset.hasMore==='1','empty painted page consumes raw ten sources and continues');
  check(sentinel(list).textContent==='Scroll for more…','empty continuation notice');
  await send(list,{html:CORPUS.next.html});
  check(requests.at(-1).params.offset==='10','next request uses consumed source cursor');
  check(list.querySelectorAll('article.tweet').length===2&&list.dataset.offset==='12'&&list.dataset.hasMore==='0','later valid canonical cards append and honor endpoint cursor');
  const ids=[...list.querySelectorAll('article.tweet')].map(card=>card.querySelector('[name=object_id]')?.value);check(ids.every(Boolean)&&new Set(ids).size===2,'later actual cards keep distinct source IDs');
  check(sentinel(list).textContent==='End of results'&&enhanced.at(-1)===list,'end and actual fold enhancement target');
  const end=requests.length;visibility(sentinel(list));await settle();check(requests.length===end,'server end prevents refetch');
 }
 // An ordinary nonempty page preserves append/fold behavior on both versions.
 list=install('statuses','0');await send(list,{html:CORPUS.next.html,headers:{'X-Has-More':'1'}});
 check(!('resolve' in requests.at(-1).params),'resolve=0 does not request resolution');
 check(list.querySelectorAll('article.tweet').length===2&&list.dataset.offset==='12'&&list.dataset.hasMore==='0','nonempty server no-more takes precedence over header');
 const before=requests.length;window.vaakBootSearch(main);visibility(sentinel(list));await settle();check(requests.length===before,'bound bootstrap/end do not duplicate request');
 // Section forwarding uses synthetic empty metadata only, not fake account/tag painter proof.
 for(const section of ['accounts','hashtags']){list=install(section);await send(list,{html:page('10','0')});check(requests.at(-1).params.section===section&&list.dataset.hasMore==='0','section '+section+' forwarded with server end');}
 if(!EXPECT_TRUNCATION){
  for(const offset of ['0','-1','10junk','1e2','9007199254740992','',null]){
   list=install();await send(list,{html:page(offset)});check(list.dataset.hasMore==='0'&&list.dataset.offset==='0','empty unsafe/malformed/nonadvancing cursor stops: '+offset);
   const stopped=requests.length;visibility(sentinel(list));await settle();check(requests.length===stopped,'malformed/stalled cursor cannot loop: '+offset);
  }
  list=install('statuses','1','10');await send(list,{html:page('9')});check(list.dataset.hasMore==='0'&&list.dataset.offset==='10','retreating cursor stops without corrupting current cursor');
  list=install();await send(list,{html:page('10',null),headers:{'X-Has-More':'1'}});check(list.dataset.offset==='10'&&list.dataset.hasMore==='1','advanced empty cursor honors explicit header fallback');
  list=install();await send(list,{html:page('10',null)});check(list.dataset.offset==='10'&&list.dataset.hasMore==='0','missing continuation defaults to end with consumed cursor');
  list=install();await send(list,{html:page('10','0'),headers:{'X-Has-More':'1'}});check(list.dataset.offset==='10'&&list.dataset.hasMore==='0','empty server end takes precedence over header');
 }
 for(const response of [{error:true},{status:500},{html:'<div>missing fragment</div>'}]){
  list=install();await send(list,response);check(list.dataset.offset==='0'&&list.dataset.hasMore==='0'&&list.querySelectorAll('article').length===0,'error preserves original stop/no optimistic append behavior');
  check(sentinel(list).textContent.includes('Couldn')&&!main.querySelector('.timeline-skeleton'),'error notice and skeleton cleanup');
 }
 for(const response of [{status:401},{html:CORPUS.next.html,headers:{'X-VAAK-Auth':'required'}}]){
  list=install();const n=redirects.length;await send(list,response);check(redirects.length===n+1&&redirects.at(-1)==='search-scroll','original auth redirect boundary');
  check(list.dataset.offset==='0'&&list.querySelectorAll('article').length===0,'auth response never appends untrusted cards');
 }
 list=install();responseQueue=[{html:CORPUS.next.html,delay:20}];const busyStart=requests.length;visibility(sentinel(list));visibility(sentinel(list));await new Promise(r=>setTimeout(r,30));await settle();check(requests.length===busyStart+1&&list.querySelectorAll('article').length===2,'busy flag prevents overlapping fetch/duplicate append');
 list=install();list.remove();const detached=requests.length;visibility(sentinel(list));await settle();check(requests.length===detached,'detached list never dispatches');
 document.body.dataset.result=(EXPECT_TRUNCATION?'REPRO PASS ':'PASS ')+count+' actual Search boot controls'+(findings.length?'; '+findings.join('; '):'');
}
run().catch(e=>document.body.dataset.result='FAIL '+e.message).finally(()=>{const report=document.createElement('pre');report.id='search-loader-result';report.textContent=document.body.dataset.result||'pending';document.body.prepend(report);});
'''
html='<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'"><section class="main"></section><script>const EXPECT_TRUNCATION='+json.dumps(args.expect_zero_truncation)+';const CORPUS='+json.dumps(corpus).replace('</','<\\/')+';'+setup+boot+checks+'</script>'
pathlib.Path(args.output).write_text(html);print(args.output)
