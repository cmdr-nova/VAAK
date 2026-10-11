#!/usr/bin/env python3
"""Extract real Blog form/CSS/delegated editor and drawer JS; no SAPI/auth claim."""
import argparse, pathlib, re, subprocess, json, hashlib
parser=argparse.ArgumentParser();parser.add_argument('--output', default='/tmp/vaak-blog-composer-browser.html');parser.add_argument('--source',default=str(pathlib.Path(__file__).resolve().parents[1]/'ap-admin.php'));parser.add_argument('--baseline-css-source');args=parser.parse_args()
s=pathlib.Path(args.source).read_text()
css_source=pathlib.Path(args.baseline_css_source).read_text() if args.baseline_css_source else s
styles=max(re.findall(r'<style[^>]*>(.*?)</style>',css_source,re.S),key=len)
start=s.index('<form method="post" action="?view=blog" data-blog-editor>');end=s.index('</form>',start)+7
form=s[start:end]
php='<?php function h($v){return htmlspecialchars((string)$v, ENT_QUOTES|ENT_SUBSTITUTE,"UTF-8");} function ap_auth_csrf_token(){return "fixture-csrf";} $blogEditor=["slug"=>"existing-draft","title"=>"Draft title","category"=>"Category","body_markdown"=>"Existing text"]; $blogEditorTags="#test"; ?>'+form
rendered=subprocess.check_output(['php'],input=php.encode()).decode()
start=s.index('(() => {\n  const rail = document.getElementById(\'admin-rail-left\');');end=s.index('</script>',start);drawer=s[start:end]
start=s.index('// Delegated handlers also work when the Blog page arrives');end=s.index('</script>',start);editor=s[start:end]
checks=r'''
window.runBlogControls=async function(){
let checks=0;const check=(value,label)=>{if(!value)throw Error(label);checks++;};
const tick=()=>new Promise(resolve=>setTimeout(resolve,250));
const rail=document.querySelector('.rail-left'),menu=document.querySelector('#mobile-menu-btn'),backdrop=document.querySelector('#mobile-nav-backdrop');
const mobile=matchMedia('(max-width:700px), (max-width:950px) and (max-height:520px)').matches;
for(const page of ['blog-fullscreen','dm-fullscreen']){
document.body.className=page;
check(getComputedStyle(rail).display===(mobile?'flex':'none'),page+' rail breakpoint');
menu.click();await tick();
check(menu.getAttribute('aria-expanded')===(mobile?'true':'false'),page+' toggle');
if(mobile){check(rail.getBoundingClientRect().right>100,page+' visible drawer');check(!backdrop.hidden&&document.body.classList.contains('mobile-nav-open'),page+' backdrop/body');
document.dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true}));await tick();check(backdrop.hidden&&!rail.classList.contains('mobile-open'),page+' Escape');
menu.click();await tick();backdrop.click();await tick();check(backdrop.hidden,page+' backdrop close');
menu.click();await tick();rail.querySelector('a').click();await tick();check(menu.getAttribute('aria-expanded')==='false',page+' link close');
menu.click();await tick();window.vaakCloseMobileNav();await tick();check(!document.body.classList.contains('mobile-nav-open'),page+' softnav close');}
}
document.body.className='blog-fullscreen';
const form=document.querySelector('[data-blog-editor]'),body=form.elements.blog_body;
check(form.elements.action.value==='blog_save'&&form.elements.csrf.value==='fixture-csrf'&&form.elements.blog_slug.value==='existing-draft','form hidden fields');
check(form.querySelector('[name=blog_publish][value="0"]').type==='submit'&&form.querySelector('[name=blog_publish][value="1"]').type==='submit','save and publish');
check([...form.querySelectorAll('[data-blog-format]')].every(b=>b.type==='button'),'no formatting submits');
let submits=0;form.addEventListener('submit',e=>{e.preventDefault();submits++;});
function select(value,start,end){body.value=value;body.focus();body.setSelectionRange(start,end);}
function button(kind){form.querySelector('[data-blog-format="'+kind+'"]').click();}
select('hello world',0,5);button('bold');check(body.value==='**hello** world'&&body.selectionStart===2&&body.selectionEnd===7,'bold selection');
check(document.activeElement===body,'caret focus');check(document.execCommand('undo')&&body.value==='hello world','native undo');
select('hello',0,5);body.dispatchEvent(new KeyboardEvent('keydown',{key:'i',ctrlKey:true,bubbles:true,cancelable:true}));check(body.value==='*hello*','Ctrl I');
select('',0,0);button('bold');check(body.value==='**text**'&&body.selectionStart===2&&body.selectionEnd===6,'empty caret placeholder');
for(const [kind,prefix] of [['heading1','# '],['heading2','## '],['heading3','### '],['list','- ']]){select('one\ntwo\nthree',0,8);button(kind);check(body.value===prefix+'one\n'+prefix+'two\nthree',kind+' newline-ended selection');}
select('\nnext',0,0);button('heading1');check(body.value==='# \nnext','initial empty line');
select('## old',2,4);button('list');check(body.value==='- old','replace existing prefix');
select('hello world',0,5);button('link');check(form.querySelector('[data-blog-link-text]').value==='hello'&&!form.querySelector('.blog-link-fields').hidden,'link selection');
form.querySelector('[data-blog-link-url]').value='http://example.org';form.querySelector('[data-blog-link-insert]').click();check(body.value==='hello world'&&form.querySelector('.blog-format-status').textContent!=='','reject HTTP');
form.querySelector('[data-blog-link-url]').value='https://example.org';form.querySelector('[data-blog-link-insert]').click();check(body.value==='[hello](https://example.org) world'&&form.querySelector('.blog-link-fields').hidden,'insert HTTPS');
select('hello',0,5);button('link');form.querySelector('[data-blog-link-cancel]').click();check(body.value==='hello'&&document.activeElement===body,'cancel preserves text');
button('link');form.querySelector('[data-blog-link-text]').dispatchEvent(new KeyboardEvent('keydown',{key:'Escape',bubbles:true,cancelable:true}));check(form.querySelector('.blog-link-fields').hidden,'link Escape');
button('link');body.value='changed';form.querySelector('[data-blog-link-url]').value='https://example.org';form.querySelector('[data-blog-link-insert]').click();check(body.value==='changed','stale selection cannot replace changed post');form.querySelector('[data-blog-link-cancel]').click();
select('x'.repeat(200000),0,1);button('bold');check(body.value.length===200000&&form.querySelector('.blog-format-status').textContent!=='','max length intact');
check(submits===0,'formatting never publishes');
// Replacing the inner page exercises delegated formatting after soft navigation.
const copy=form.cloneNode(true);form.replaceWith(copy);const next=copy.elements.blog_body;next.value='soft';next.setSelectionRange(0,4);copy.querySelector('[data-blog-format="bold"]').click();check(next.value==='**soft**','softnav delegated format');
check(!mobile||document.documentElement.scrollWidth<=innerWidth,'mobile page no horizontal overflow');
return {checks, mobile,width:innerWidth,height:innerHeight};
};
'''
# Minimal outer shell; full current stylesheet, actual PHP-rendered form and unchanged production handlers.
html='<!doctype html><meta name="viewport" content="width=device-width,initial-scale=1"><style>'+styles+'</style><body class="blog-fullscreen"><div class="shell"><aside class="rail-left" id="admin-rail-left"><a href="#fixture">Home</a></aside><main class="main"><div class="mobile-topbar"><button id="mobile-menu-btn" aria-expanded="false">Menu</button></div><div class="feed"><section class="side-card blog-editor">'+rendered+'</section></div></main><aside class="rail-right"></aside></div><div id="mobile-nav-backdrop" class="mobile-nav-backdrop" hidden></div><script>'+drawer+'</script><script>'+editor+'</script><script>'+checks+'</script>'
pathlib.Path(args.output).write_text(html)
pathlib.Path(args.output+'.manifest.json').write_text(json.dumps({'source':args.source,'source_sha256':hashlib.sha256(s.encode()).hexdigest(),'css_source_sha256':hashlib.sha256(css_source.encode()).hexdigest(),'fixture_sha256':hashlib.sha256(html.encode()).hexdigest(),'boundaries':['minimal outer shell','fixture CSRF helper','fixed draft values','no auth/SAPI/publish mutation']},indent=2))
print(args.output)
