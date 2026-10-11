#!/usr/bin/env python3
"""Execute verbatim Library loader/soft-navigation JS with real painter fragments.
HTTP responses and IntersectionObserver visibility notifications are isolated;
loader parsing, cursor state, append, history and DOM lifecycle remain actual JS.
"""
import argparse,json,pathlib,re
p=argparse.ArgumentParser();p.add_argument('--fragments',required=True);p.add_argument('--expect-zero-truncation',action='store_true');p.add_argument('--source');p.add_argument('--output',default='/tmp/vaak-library-loader-browser.html');args=p.parse_args()
root=pathlib.Path(__file__).resolve().parents[2];source=pathlib.Path(args.source).read_text() if args.source else (root/'api/ap-admin.php').read_text();corpus=json.loads(pathlib.Path(args.fragments).read_text())['fragments']
a=source.index('(function () {',source.index('// Soft-nav for primary left-rail'));b=source.index('\n</script>',a);soft=source[a:b]
a=source.index('(function () {',source.index('// Favourites, Bookmarks, Followers, Following, and Search'));b=source.index('\n</script>',a);loaders=source[a:b]
a=source.index('  window.vaakIsPrettyProfilePath = function');b=source.index('\n\n  // Session expired',a);history=source[a:b]
fedi_sentinel=re.search(r'<div data-fedi-favourites-sentinel class="meta"[^>]*>.*?</div>',source).group(0)
fedi_sentinel=re.sub(r'<\?.*?\?>','Scroll for more…',fedi_sentinel)
setup=r'''
window.observers=[];window.requests=[];window.visibility=(el)=>{for(const io of observers){if(io.nodes.has(el))io.callback([{target:el,isIntersecting:true}]);}};
window.IntersectionObserver=class {constructor(callback){this.callback=callback;this.nodes=new Set();observers.push(this);}observe(el){this.nodes.add(el);}unobserve(el){this.nodes.delete(el);}disconnect(){this.nodes.clear();}};
window.vaakShowLoading=()=>{};window.vaakHideLoading=()=>{};
window.responseQueue=[];window.fetch=async(url,opts)=>{
const u=new URL(url,location.href);requests.push({url:u.href,offset:u.searchParams.get('offset'),folder:u.searchParams.get('folder'),credentials:opts.credentials,cache:opts.cache});
if(u.searchParams.get('shell')==='1')return{ok:true,status:200,headers:new Headers({'X-VAAK-View':u.searchParams.get('view')}),text:async()=>shell(u.searchParams.get('view'),u.searchParams.get('folder')||'0',u.searchParams.get('network')||'fedi')};
const item=responseQueue.shift();if(!item)throw Error('fixture response exhausted');if(item==='error')throw Error('fixture network failure');return{ok:true,status:200,headers:new Headers(),text:async()=>item};
};
function shell(view,folder='0',network='fedi'){
if(view==='bookmarks')return '<div class="feed"><h2>Bookmarks</h2><a href="?view=bookmarks&folder=7" data-vaak-soft-nav="bookmarks" data-bm-folder="7">Folder seven</a><div id="bookmarks-slot" data-folder="'+folder+'" data-limit="20"></div></div>';
return '<div class="feed"><h2>Favourites</h2><div data-'+(network==='bsky'?'bsky':'fedi')+'-favourites-fragment data-offset="0" data-limit="20" data-has-more="1"><div data-'+(network==='bsky'?'bsky':'fedi')+'-favourites-sentinel></div></div></div>';
}
'''
checks=r'''
let count=0;const findings=[];function check(v,m){if(!v)throw Error(m);count++;}const tick=()=>new Promise(r=>setTimeout(r,0));async function settle(){for(let i=0;i<8;i++)await tick();}
const main=document.querySelector('section.main');function install(type,html){main.innerHTML=shell(type==='bookmarks'?'bookmarks':'favourites','0',type==='favourites_bsky'?'bsky':'fedi');if(type==='favourites_fedi'){const sentinel=main.querySelector('[data-fedi-favourites-sentinel]');sentinel.outerHTML=FEDI_SENTINEL;const live=main.querySelector('[data-fedi-favourites-sentinel]');main.querySelector('[data-fedi-favourites-fragment]').outerHTML=html;main.querySelector('[data-fedi-favourites-fragment]').appendChild(live);}}
function boot(type){window[type==='bookmarks'?'vaakBootBookmarks':type==='favourites_fedi'?'vaakBootFavouritesFedi':'vaakBootFavouritesBsky'](main);}
function frag(type){return main.querySelector('[data-'+(type==='bookmarks'?'bookmark':type==='favourites_fedi'?'fedi-favourites':'bsky-favourites')+'-fragment]');}
function sentinel(type){return main.querySelector('[data-'+(type==='bookmarks'?'bookmark':type==='favourites_fedi'?'fedi-favourites':'bsky-favourites')+'-sentinel]');}
async function run(){
for(const engine of ['php','candidate_fallback']){
for(const type of ['bookmarks','favourites_fedi','favourites_bsky']){
const pages=CORPUS[type][engine];install(type,pages[0]);responseQueue=type==='favourites_fedi'?[]:[pages[0]];const start=requests.length;boot(type);await settle();
check(frag(type).dataset.offset==='20'&&frag(type).dataset.hasMore==='1',engine+' '+type+' initial cursor');
const initial=main.querySelectorAll('article.tweet').length;const expectedNext=document.createElement('div');expectedNext.innerHTML=pages[1];const expectedCount=expectedNext.querySelectorAll('article.tweet').length;responseQueue=[pages[1]];visibility(sentinel(type));await settle();
check(requests.at(-1).offset==='20'&&requests.at(-1).credentials==='same-origin'&&requests.at(-1).cache==='no-store',engine+' '+type+' actual append transport');
check(main.querySelectorAll('article.tweet').length===initial+expectedCount&&frag(type).dataset.offset==='40'&&frag(type).dataset.hasMore==='0',engine+' '+type+' append/cursor');
const identities=[...main.querySelectorAll('article.tweet')].map(card=>card.dataset.bskyUri||card.querySelector('[name=object_id]')?.value||card.querySelector('[data-uri]')?.dataset.uri||'');check(identities.every(Boolean)&&new Set(identities).size===identities.length,engine+' '+type+' unique consumed source identities');
const end=requests.length;visibility(sentinel(type));await settle();check(requests.length===end,engine+' '+type+' end does not refetch');
// Actual owner-blocked canonical fragment: no cards, consumed offset, more keys.
install(type,pages[0]);responseQueue=type==='favourites_fedi'?[]:[pages[0]];boot(type);await settle();
responseQueue=[CORPUS['zero_card_'+type][engine]];visibility(sentinel(type));await settle();
if(frag(type).dataset.hasMore!=='1'||frag(type).dataset.offset!=='40')findings.push(engine+' '+type+' zero-card page terminates at '+frag(type).dataset.offset+' despite server hasmore=1');
if(frag(type).dataset.hasMore==='1'){responseQueue=[CORPUS['later_'+type][engine]];visibility(sentinel(type));await settle();check(requests.at(-1).offset==='40'&&frag(type).dataset.offset==='60',engine+' '+type+' skips denied consumed keys to later valid card');}
if(!EXPECT_TRUNCATION){
// Isolated malformed/nonadvancing response metadata must never loop.
install(type,pages[0]);responseQueue=type==='favourites_fedi'?[]:[pages[0]];boot(type);await settle();
const stalled=document.createElement('div');stalled.innerHTML=CORPUS['zero_card_'+type][engine];stalled.firstElementChild.dataset.offset='20';responseQueue=[stalled.innerHTML];visibility(sentinel(type));await settle();check(frag(type).dataset.hasMore==='0',engine+' '+type+' zero-card unchanged cursor stops');
const stopped=requests.length;visibility(sentinel(type));await settle();check(requests.length===stopped,engine+' '+type+' zero-card unchanged cursor no refetch');
if(type!=='favourites_fedi'){
 install(type,pages[0]);stalled.firstElementChild.dataset.offset='0';responseQueue=[stalled.innerHTML];boot(type);await settle();check(frag(type).dataset.hasMore==='0',engine+' '+type+' initial zero-card unchanged cursor stops');
}
}
// Network failure is passed through the actual loader; no optimistic card append.
install(type,pages[0]);responseQueue=type==='favourites_fedi'?[]:[pages[0]];boot(type);await settle();const before=main.querySelectorAll('article.tweet').length;
responseQueue=['error'];visibility(sentinel(type));await settle();check(main.querySelectorAll('article.tweet').length===before,engine+' '+type+' network failure keeps cards');
check(sentinel(type).textContent.includes('Couldn'),engine+' '+type+' error notice');
if(type==='bookmarks'){responseQueue=[pages[1]];visibility(sentinel(type));await settle();check(frag(type).dataset.offset==='40',engine+' bookmarks retries append after error');}
}
// Execute actual softNavTo, actual folder-link capture handler, then popstate.
responseQueue=[CORPUS.bookmarks[engine][0]];await vaakSoftNavTo('bookmarks',true,'',{});await settle();check(document.title==='Bookmarks · VAAK'&&main.querySelector('h2').textContent==='Bookmarks',engine+' soft heading/chrome');
check(main.querySelector('#bookmarks-slot').dataset.folder==='0',engine+' All boot');
responseQueue=[CORPUS.bookmarks_folder[engine][0]];main.querySelector('[data-bm-folder="7"]').click();await settle();
check(new URL(location.href).searchParams.get('folder')==='7'&&main.querySelector('#bookmarks-slot').dataset.folder==='7',engine+' actual folder link/history/boot');
check(requests.at(-1).folder==='7'&&requests.at(-1).offset==='0',engine+' folder offset reset');
responseQueue=[CORPUS.bookmarks[engine][0]];history.back();for(let i=0;i<50&&(new URL(location.href).searchParams.has('folder')||main.querySelector('#bookmarks-slot')?.dataset.folder!=='0');i++)await new Promise(resolve=>setTimeout(resolve,10));await settle();
check(!new URL(location.href).searchParams.has('folder')&&main.querySelector('#bookmarks-slot').dataset.folder==='0',engine+' actual Back restores All');
const expectedBack=document.createElement('div');expectedBack.innerHTML=CORPUS.bookmarks[engine][0];check(main.querySelectorAll('article.tweet').length===expectedBack.querySelectorAll('article.tweet').length,engine+' back no stale duplicate');
}
document.body.dataset.result=(EXPECT_TRUNCATION?(findings.length===6?'REPRO PASS ':'REPRO FAIL '):(findings.length?'FAIL ':'PASS '))+count+' actual-loader controls'+(findings.length?'; '+findings.join('; '):'');
}run().catch(e=>document.body.dataset.result='FAIL '+e.message).finally(()=>{const result=document.createElement('pre');result.id='loader-result';result.textContent=document.body.dataset.result||'pending';document.body.prepend(result);});
'''
html='<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'"><section class="main"></section><script>const FEDI_SENTINEL='+json.dumps(fedi_sentinel)+';const EXPECT_TRUNCATION='+json.dumps(args.expect_zero_truncation)+';const CORPUS='+json.dumps(corpus).replace('</','<\\/')+';'+setup+history+soft+loaders+checks+'</script>'
pathlib.Path(args.output).write_text(html);print(args.output)
