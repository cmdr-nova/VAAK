#!/usr/bin/env python3
"""Build offline browser integration fixture using real PHP cards/client handlers.

Optional --native captures.json accepts exported native HTML with the same case
keys. No server writes: fetch is intercepted; external resources are blocked by
CSP. Browser execution remains a separate check from this fixture builder.
"""
import argparse, json, pathlib, subprocess

parser=argparse.ArgumentParser()
parser.add_argument('--output',default='/tmp/vaak-library-card-browser.html')
parser.add_argument('--native')
parser.add_argument('--bsky-native')
args=parser.parse_args()
root=pathlib.Path(__file__).resolve().parents[2]
reference=json.loads(subprocess.check_output([str(root/'api/bin/php-policy-test'),str(root/'api/bin/migration-card-reference-capture.php')]))
sources={'php':reference['captures']}
if args.native: sources['rust']=json.loads(pathlib.Path(args.native).read_text())['captures']
if args.bsky_native:
    bsky_reference=json.loads(subprocess.check_output([str(root/'api/bin/php-policy-test'),str(root/'api/bin/library-bsky-enabled-reference-capture.php')]))
    sources['php'].update(bsky_reference['captures'])
    sources['rust'].update(json.loads(pathlib.Path(args.bsky_native).read_text())['captures'])
source=(root/'api/ap-admin.php').read_text()
start=source.index('(function () {\n  const INTERACT = new Set([')
end=source.index('\n(function bindPollVotes()',start)
interact=source[start:end]
poll_end=source.index('\n</script>',end)
poll=source[end:poll_end]
csrf_start=source.index("  document.addEventListener('submit', function (ev) {\n    const form = ev.target;\n    if (!(form instanceof HTMLFormElement)) return;\n    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;")
csrf_end=source.index('\n  }, true);',csrf_start)+len('\n  }, true);')
csrf=source[csrf_start:csrf_end]
avatar_start=source.index("(function () {\n  const avatars = 'img.tweet-av")
avatar_end=source.index('\n})();',avatar_start)+len('\n})();')
avatar=source[avatar_start:avatar_end]
bsky_start=source.index('(function bindBskyActions() {')
bsky_end=source.index('\n</script>',bsky_start)
bsky=source[bsky_start:bsky_end]
cases=['plain','saved_flags','cw_media','cw_auto_unblur','sensitive_auto_unblur','poll_open','own','rss']
if args.bsky_native: cases+=['legacy_images','legacy_own','legacy_quote','legacy_sensitive_auto_unblur']
panels=''.join('<section data-engine="'+engine+'" data-case="'+case+'">'+cards[case]['html']+'</section>' for engine,cards in sources.items() for case in cases)
setup="""
window.VAAK_CSRF='fixture-csrf-token';window.requests=[];window.notices=[];
window.apAdminToast=(m)=>notices.push(m);window.vaakHideLoading=()=>{};
window.mode='success';window.fetch=async(url,opts)=>{
const entries=opts&&opts.body?(typeof opts.body==='string'?[...new URLSearchParams(opts.body).entries()]:[...opts.body.entries()]):[];
const fields=Object.fromEntries(entries);requests.push({url:String(url),fields,all:entries,credentials:opts&&opts.credentials,headers:opts&&opts.headers});
if(String(url).includes('ajax=bookmark_folders')) return{ok:true,json:async()=>({ok:true,folders:[{id:7,title:'Current folder'}],selected:[]})};
if(mode==='network')throw Error('fixture network error');
if(fields.action==='bookmark_folder_create')return{ok:true,json:async()=>({ok:true,id:8})};
if(fields.action&&fields.action.startsWith('bsky_'))return{ok:mode!=='failure',status:mode==='failure'?409:200,json:async()=>mode==='failure'?{ok:false,error:'fixture failure'}:{ok:true,liked:fields.action==='bsky_like',reposted:fields.action==='bsky_repost',bookmarked:fields.action==='bsky_bookmark',record_uri:'at://did:plc:fixture/app.bsky.feed.like/current'}};
if(fields.action==='bookmark_folders_list')return{json:async()=>({ok:true,folders:[],selected:[]})};
return {ok:mode!=='failure',json:async()=>mode==='failure'?{ok:false,error:'fixture failure'}:{ok:true,kind:/reblog/.test(fields.action)?'reblog':/bookmark/.test(fields.action)?'bookmark':'favourite',active:!fields.action.startsWith('un'),poll_html:'<div class="poll-block" data-current="1">Current poll results</div>'}};
};
"""
checks="""
let count=0;function check(v,msg){if(!v)throw Error(msg);count++;}
const tick=()=>new Promise(r=>setTimeout(r,0));
async function run(){
for(const engine of ENGINES){
const panel=n=>document.querySelector('[data-engine="'+engine+'"][data-case="'+n+'"]');
const plain=panel('plain'),form=plain.querySelector('input[value="favourite_status"]').form,btn=form.querySelector('button');
const av=plain.querySelector('img.tweet-av'),actor=av.dataset.profileHoverActor;av.dispatchEvent(new PointerEvent('pointerover',{bubbles:true}));
check(av.getAttribute('title')===''&&!av.closest('a').hasAttribute('title'),engine+' avatar tooltip suppression');
check(av.dataset.profileHoverActor===actor&&!!actor,engine+' avatar hover identity preserved');
const sid=form.querySelector('[name=status_id]').value,oid=form.querySelector('[name=object_id]').value;
mode='failure';form.requestSubmit();await tick();
check(requests.at(-1).fields.csrf===VAAK_CSRF,engine+' CSRF capture');
check(requests.at(-1).fields.status_id===sid&&requests.at(-1).fields.object_id===oid,engine+' exact action targets');
check(!btn.classList.contains('on')&&form.querySelector('[name=action]').value==='favourite_status',engine+' failure rollback');
mode='success';form.requestSubmit();await tick();
check(btn.classList.contains('on')&&form.querySelector('[name=action]').value==='unfavourite_status',engine+' success toggle');
const saved=panel('saved_flags'),bm=saved.querySelector('input[value="unbookmark_status"]').form;
const before=requests.length;bm.requestSubmit();await tick();
check(requests.slice(before).every(r=>r.fields.action!=='unbookmark_status'),engine+' picker before unbookmark');
check(document.querySelector('.bm-folder-popover'),engine+' folder picker opens');
let picker=document.querySelector('.bm-folder-popover'),folder=picker.querySelector('[data-folder-id]');
mode='failure';folder.checked=true;folder.dispatchEvent(new Event('change',{bubbles:true}));await tick();
check(!folder.checked,engine+' folder error rollback');
check(requests.at(-1).fields.folder_id==='7'&&requests.at(-1).fields.csrf===VAAK_CSRF,engine+' folder owner action transport');
mode='success';picker.querySelector('[data-bm-done]').click();await tick();check(!document.querySelector('.bm-folder-popover'),engine+' folder dismissal');
const cw=panel('cw_media').querySelector('details.cw-gate');check(cw&&!cw.open,engine+' initial CW closed');cw.querySelector('summary').click();check(cw.open,engine+' CW opens');
check(panel('cw_auto_unblur').querySelector('details.cw-gate'),engine+' custom CW overrides saved auto-unblur');
check(!panel('sensitive_auto_unblur').querySelector('details.cw-gate'),engine+' saved sensitive auto-unblur');
const vote=panel('poll_open').querySelector('form.poll-vote-form');const noPick=requests.length;vote.requestSubmit();await tick();check(requests.length===noPick,engine+' no empty poll request');
vote.querySelector('[name="choices[]"]').checked=true;mode='failure';vote.requestSubmit();await tick();
check(vote.isConnected&&!vote.querySelector('button').disabled&&vote.dataset.busy==='0',engine+' failed vote preserved/retry enabled');
mode='success';vote.requestSubmit();await tick();
check(requests.at(-1).fields.action==='poll_vote'&&requests.at(-1).fields.csrf===VAAK_CSRF&&requests.at(-1).all.some(([k,v])=>k==='choices[]'&&v==='0'),engine+' exact poll transport');
check(panel('poll_open').querySelector('[data-current="1"]'),engine+' server poll replacement');
for(const cardCase of ['own','rss']){
 const card=panel(cardCase);check(card.querySelector('article'),engine+' '+cardCase+' card remains complete');
 const forms=[...card.querySelectorAll('form[method="post"]')];check(forms.length>0,engine+' '+cardCase+' retains action forms');
 for(const f of forms){const a=f.querySelector('[name=action]');if(!a||!['favourite_status','bookmark_status','reblog_status'].includes(a.value))continue;
 const old=a.value,target=f.querySelector('[name=object_id]')?.value,status=f.querySelector('[name=status_id]')?.value,button=f.querySelector('button'),before=button.innerHTML;
 mode='network';f.requestSubmit();await tick();check(a.value===old&&button.innerHTML===before&&f.dataset.busy==='0',engine+' '+cardCase+' '+old+' network rollback');
 check(requests.at(-1).fields.object_id===target&&requests.at(-1).fields.status_id===status&&requests.at(-1).fields.csrf===VAAK_CSRF,engine+' '+cardCase+' '+old+' exact targets');
 }
}
// Exercise existing delegated handlers on cards arriving after initial binding.
// These insertions model the loader DOM boundary, not the loader/network itself.
for(const insertion of ['infinite_append','soft_view_replacement']){
 const holder=document.createElement('section');document.body.appendChild(holder);holder.innerHTML=plain.innerHTML;
 const dynamic=holder.querySelector('input[name=action]').form,action=dynamic.querySelector('[name=action]');
 mode='failure';dynamic.requestSubmit();await tick();check(requests.at(-1).fields.action===action.value&&dynamic.dataset.busy==='0',engine+' '+insertion+' delegated handler');
 const avatar=holder.querySelector('img.tweet-av');avatar.title='stale title';avatar.dispatchEvent(new PointerEvent('pointerover',{bubbles:true}));check(!avatar.title,engine+' '+insertion+' avatar delegation');holder.remove();
}
if(panel('legacy_images')){
 for(const caseName of ['legacy_images','legacy_own','legacy_quote']){
 const card=panel(caseName);check(card.querySelector('article'),engine+' '+caseName+' complete card');
 for(const actionName of ['like','repost','bookmark']){
 const button=card.querySelector('[data-bsky-action="'+actionName+'"]');check(button,engine+' '+caseName+' '+actionName+' retained');
 const on=button.classList.contains('on'),uri=button.dataset.uri,cid=button.dataset.cid,record=button.dataset.recordUri;
 mode='failure';button.click();await tick();check(button.classList.contains('on')===on&&button.dataset.recordUri===record&&button.dataset.busy==='0',engine+' '+caseName+' '+actionName+' rollback');
 const request=requests.at(-1);check(request.fields.bsky_uri===uri&&request.fields.bsky_cid===cid&&request.fields.csrf===VAAK_CSRF&&request.headers['X-VAAK-CSRF']===VAAK_CSRF&&request.credentials==='same-origin',engine+' '+caseName+' '+actionName+' exact protocol transport');
 mode='success';button.click();await tick();check(button.classList.contains('on')&&button.getAttribute('aria-pressed')==='true',engine+' '+caseName+' '+actionName+' success UI');
 if(actionName!=='bookmark'){
 check(button.dataset.recordUri==='at://did:plc:fixture/app.bsky.feed.like/current',engine+' '+caseName+' '+actionName+' receipt identity');
 button.click();await tick();check(!button.classList.contains('on')&&button.dataset.recordUri===''&&requests.at(-1).fields.bsky_record_uri==='at://did:plc:fixture/app.bsky.feed.like/current',engine+' '+caseName+' '+actionName+' undo transport/state');
 }
 }
 }
 check(!panel('legacy_sensitive_auto_unblur').querySelector('details.cw-gate'),engine+' Bluesky saved sensitive unblur');
}
}
document.body.dataset.result='PASS '+count+' browser checks';
}
run().catch(e=>document.body.dataset.result='FAIL '+e.message);
""".replace('ENGINES',json.dumps(list(sources)))
html='<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="default-src \'none\'; script-src \'unsafe-inline\'; style-src \'unsafe-inline\'"><body>'+panels+'<script>'+setup+csrf+interact+poll+bsky+avatar+checks+'</script></body>'
pathlib.Path(args.output).write_text(html)
print(args.output)
