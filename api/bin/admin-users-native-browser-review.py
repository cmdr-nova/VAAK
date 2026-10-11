#!/usr/bin/env python3
"""Actual native Users forms and unchanged global submit handler in owned Chromium."""
import json,re,os,subprocess,html,hashlib
from pathlib import Path
base=Path(os.environ.get('ADMIN_USERS_PHASE','rust/target/parity-evidence/admin-users-native-phase'))
s=Path('api/ap-admin.php').read_text();end=s.index("    input.value = window.VAAK_CSRF || '';")+len("    input.value = window.VAAK_CSRF || '';\n  }, true);");start=s.rfind("  document.addEventListener('submit', function (ev) {",0,end);handler=s[start:end];assert handler.endswith('}, true);')
(base/'csrf-source.js').write_text(handler)
proof=json.loads((base/'http-proof.json').read_text());forms={r['mode']:r['response']['html'] for r in proof['receipts'] if r.get('width')=='integer' and r.get('case')=='first' and 'response' in r};assert set(forms)=={'full','shell'}
script="""window.confirm=()=>true;const markup=MARKUP;const results=[];for(const mode of ['full','shell'])for(const stale of [false,true]){document.querySelector('#shell').innerHTML=markup[mode];window.VAAK_CSRF=mode+'-current-token';for(const f of document.querySelectorAll('form[method="post"]')){if(stale){const i=document.createElement('input');i.name='csrf';i.value='stale';f.appendChild(i);}const before=Object.fromEntries(new FormData(f));f.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));const after=Object.fromEntries(new FormData(f));results.push({mode,stale,before,after,csrfCount:f.querySelectorAll('input[name="csrf"]').length});}const get=document.querySelector('form[method="get"]');get.dispatchEvent(new Event('submit',{bubbles:true,cancelable:true}));results.push({mode,stale,getCsrf:get.querySelectorAll('input[name="csrf"]').length});}document.body.innerHTML='<pre id="result">'+JSON.stringify(results)+'</pre>';""".replace('MARKUP',json.dumps(forms).replace('</','<\\/'))
page='<!doctype html><meta charset="utf-8"><meta http-equiv="Content-Security-Policy" content="img-src \'none\'"><body><div id="shell"></div><script>'+handler+script+'</script>'
(base/'csrf-browser.html').write_text(page)
profile=Path('/tmp')/('vaak-admin-users-browser-'+str(os.getpid()));profile.mkdir()
command=['/snap/bin/chromium','--headless','--no-sandbox','--disable-gpu','--no-first-run','--disable-background-networking','--user-data-dir='+str(profile),'--dump-dom',(base/'csrf-browser.html').resolve().as_uri()]
p=subprocess.run(command,capture_output=True,text=True,timeout=30);(base/'csrf-chromium.stdout').write_text(p.stdout);(base/'csrf-chromium.stderr').write_text(p.stderr);assert p.returncode==0
match=re.search(r'<pre id="result">(.*?)</pre>',p.stdout,re.S);assert match;results=json.loads(html.unescape(match[1]));posts=[r for r in results if 'after' in r];gets=[r for r in results if 'getCsrf' in r];assert len(posts)==80 and len(gets)==4
for r in posts:
 expected=r['mode']+'-current-token';assert r['after']['csrf']==expected and r['csrfCount']==1
 before={k:v for k,v in r['before'].items() if k!='csrf'};after={k:v for k,v in r['after'].items() if k!='csrf'};assert before==after and r['after']['action'] in ['user_ban','user_unban'] and r['after']['return_view']=='users'
assert all(r['getCsrf']==0 for r in gets)
(base/'csrf-chromium-proof.json').write_text(json.dumps({'results':results,'post_forms':len(posts),'get_forms':len(gets),'handler_sha256':hashlib.sha256(handler.encode()).hexdigest(),'scope':'Actual native first-page DOM inserted full/shell, actual unchanged global submit handler. Browser confirm return declared true; no account actions/submission or whole router/SAPI. Owned temporary browser profile only.'},indent=2));print('80 actual Users POST form CSRF controls + 4 GET controls passed')
