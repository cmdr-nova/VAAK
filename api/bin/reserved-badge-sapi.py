#!/usr/bin/env python3
"""Real full controller/login/CSRF/profile POST + PDO. Isolated fixture only.
Reuses the documented empty-schema inventory/bootstrap, never production rows.
External transports disabled; account roles and grants mutate fixture schemas only.
"""
import pathlib, os, json, subprocess, re, urllib.request, urllib.parse, http.cookiejar, time, shutil, sys, hashlib
R=pathlib.Path(__file__).resolve().parents[2]
before='--before' in sys.argv
O=R/('rust/target/parity-evidence/reserved-badge-authority/sapi-before' if before else 'rust/target/parity-evidence/reserved-badge-authority/sapi');O.mkdir(parents=True,exist_ok=True)
width='INTEGER' if '--integer' in sys.argv else 'BIGINT'
S=pathlib.Path(os.environ.get('RESERVED_BADGE_SOURCE',str(R/'rust/target/parity-evidence/reserved-badge-authority/source')))
os.environ['SUPPORTER_WHOLE_SOURCE']=str(S)
setup=(R/'api/bin/supporter-whole-flow-setup.py').read_text().replace("o=r/'rust/target/parity-evidence/supporter-whole-flow'", "o=r/'"+str(O.relative_to(R))+"'")
exec(compile(setup,str(R/'api/bin/supporter-whole-flow-setup.py'),'exec'))
A=O/'app';schema='reserved_badge_sapi_'+('before_' if before else '')+width.lower()
e=os.environ.copy();e.update(json.loads((O/'env.json').read_text()));e['AP_DB_DSN']='pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=--search_path='+schema
php=[str(R/'api/bin/php-policy-test'),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so']
provision=O/'provision.php';provision.write_text((R/'rust/target/parity-evidence/supporter-whole-flow/provision.php').read_text().replace("$schema='supporter_whole_flow'", "$schema='"+schema+"'").replace("'BIGINT'", "'"+width+"'").replace("'BIGSERIAL'", "'SERIAL'" if width=='INTEGER' else "'BIGSERIAL'"))
subprocess.run(php+[str(provision)],env=dict(e,POSTING_SAPI_SQLITE=str(O/'storage/ap.sqlite'),POSTING_SAPI_API_SOURCE=str(A/'api')),check=True)
control=A/'control.php'
def ctl(code):
 control.write_text("<?php require __DIR__.'/api/ap-registration-approvals.php';ap_auth_bootstrap();$d=ap_db();ap_supporter_schema($d);"+code)
 p=subprocess.run(php+[str(control)],env=e,check=True,text=True,capture_output=True)
 assert not p.stderr,p.stderr
 return json.loads(p.stdout) if p.stdout else None
ctl("$d->prepare('UPDATE ap_users SET password_hash=? WHERE username=?')->execute([password_hash('fixture-admin-password',PASSWORD_DEFAULT),'cmdr_nova']);$d->beginTransaction();ap_auth_create_account_row($d,'ordinary','ordinary@example.invalid',password_hash('fixture-member-password',PASSWORD_DEFAULT),ap_db_now());$d->commit();")
checks=[];captures=[]
def check(ok,label):
 checks.append({'ok':bool(ok),'check':label})
 if not ok:raise AssertionError(label)
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,data=None):
 q=urllib.request.Request('http://127.0.0.1:19023'+path,data=urllib.parse.urlencode(data,doseq=True).encode() if data is not None else None)
 try:r=c.open(q,timeout=30)
 except urllib.error.HTTPError as x:r=x
 h=r.read().decode();captures.append({'path':path,'status':r.status,'body':h});return h
def csrf(h):
 m=re.search(r'name="csrf" value="([^"]+)"',h);assert m,'actual form CSRF missing';return m[1]
def login(c,name,pw):
 h=req(c,'/vaak/?mode=login');h=req(c,'/vaak/?mode=login',dict(action='login',csrf=csrf(h),login=name,password=pw));check('name="action" value="login"' not in h,'actual login '+name)
def badges(key):return ctl("echo json_encode(ap_profile_get('"+key+"')['profile_badges']);")
flair=['cat','dog','coffee','tea','bookworm','gamer']
def save(c,values):
 h=req(c,'/vaak/?view=profile');return req(c,'/vaak/?view=profile',{'action':'save_profile','csrf':csrf(h),'name':'Fixture name','summary':'<p>Fixture bio</p>','profile_badges[]':values})
log=(O/('server-'+width.lower()+'.log')).open('w')
s=subprocess.Popen(php+['-d','disable_functions=exec,shell_exec,curl_init','-d','allow_url_fopen=0','-S','127.0.0.1:19023',str(A/'router.php')],env=e,stdout=log,stderr=log)
try:
 for _ in range(50):
  try:req(client(),'/vaak/?mode=login');break
  except OSError:time.sleep(.1)
 member=client();admin=client();login(member,'ordinary','fixture-member-password');login(admin,'cmdr_nova','fixture-admin-password')
 if before:
  h=req(admin,'/vaak/?view=profile');check('name="profile_badges[]" value="admin"' in h,'before real operator can select Admin')
  save(admin,['admin']+flair);check('admin' in badges('cmdr_nova'),'before real forged profile POST accepts selected Admin')
  ctl("$d->exec(\"UPDATE actor_profile SET profile_badges='[\\\"admin\\\",\\\"supporter\\\"]' WHERE actor_key='ordinary'\");")
  h=req(member,'/vaak/?view=remote_profile&local_key=ordinary');check('title="Admin"' in h,'before forged stored ordinary Admin displays');check('title="Supporter"' not in h,'before Supporter display already requires grant')
  print(json.dumps({'width':width,'before_checks':len(checks),'ok':True}));sys.exit(0)
 for c in [member,admin]:
  h=req(c,'/vaak/?view=profile');check(not re.search(r'name="profile_badges\[\]" value="(?:admin|supporter)"',h),'reserved options absent even for real Admin')
 for values in [['admin','supporter']+flair,[' admin ',' supporter ','Admin','SUPPORTER']+flair,flair+['admin','supporter'],['admin','admin','supporter','supporter']+flair]:
  h=save(member,values);check(badges('ordinary')==flair,'actual forged POST retains six ordinary slots');check('Profile saved' in h or captures[-1]['status']==200,'actual controller save completed')
 h=save(admin,['supporter']+flair);check(badges('cmdr_nova')==['admin']+flair,'current Admin automatically retained without selection')
 # Actual partial-save contract: explicit null/empty clears flair; missing retains.
 ctl("ap_profile_save(['name'=>'Fixture','summary'=>'Fixture bio','profile_badges'=>null],'ordinary');")
 check(badges('ordinary')==[],'explicit null clears ordinary flair')
 ctl("ap_profile_save(['name'=>'Fixture','summary'=>'Fixture bio','profile_badges'=>['cat']],'ordinary');ap_profile_save(['name'=>'Partial name','summary'=>'Fixture bio'],'ordinary');")
 check(badges('ordinary')==['cat'],'missing badges preserves ordinary flair')
 ctl("ap_profile_save(['name'=>'Fixture','summary'=>'Fixture bio','profile_badges'=>[]],'ordinary');")
 check(badges('ordinary')==[],'explicit empty clears ordinary flair')
 save(member,flair)
 ctl("$d->exec(\"UPDATE actor_profile SET profile_badges='[\\\"admin\\\",\\\"supporter\\\",\\\"cat\\\",\\\"dog\\\",\\\"coffee\\\",\\\"tea\\\",\\\"bookworm\\\",\\\"gamer\\\"]' WHERE actor_key='ordinary'\");")
 h=req(member,'/vaak/?view=remote_profile&local_key=ordinary');check('title="Admin"' not in h and 'title="Supporter"' not in h,'stored forged badges suppressed by actual public profile');check(badges('ordinary')==flair,'stored forgery cannot consume ordinary slots')
 # Synthetic settled issuer + actual award transaction, independent from selection.
 ctl("$i=ap_supporter_issue('cus_ReservedFixture','ch_ReservedFixture','ordinary@example.invalid');if(!$i['ok'])throw new RuntimeException('issue');$id=(int)$d->query(\"SELECT id FROM ap_users WHERE actor_key='ordinary'\")->fetchColumn();$d->beginTransaction();ap_supporter_award($d,hash('sha256',$i['code']),$id,'ordinary@example.invalid',ap_db_now());$d->commit();")
 save(member,flair);check(badges('ordinary')==['supporter']+flair,'earned Supporter plus six ordinary slots')
 ctl("$d->exec(\"UPDATE ap_users SET is_admin=1 WHERE actor_key='ordinary'\");")
 h=req(member,'/vaak/?view=remote_profile&local_key=ordinary');check('title="Admin"' in h and 'title="Supporter"' in h,'current role and reward both automatic on actual profile');check(badges('ordinary')==['supporter','admin']+flair,'both reserved badges leave six free slots')
 ctl("$d->exec(\"UPDATE ap_users SET is_admin=0 WHERE actor_key='ordinary'\");$d->exec('UPDATE ap_supporter_codes SET used_at=NULL');")
 h=req(member,'/vaak/?view=remote_profile&local_key=ordinary');check('title="Admin"' not in h and 'title="Supporter"' not in h,'warm role and reward withdrawal remove badges')
 ctl("$d->exec(\"UPDATE ap_users SET is_admin=1,disabled_at='disabled' WHERE actor_key='ordinary'\");")
 check(badges('ordinary')==flair,'disabled account fails closed for role and reward')
 # Admin identity alone no longer authorizes display after role withdrawal.
 ctl("$d->exec(\"UPDATE ap_users SET is_admin=0 WHERE actor_key='cmdr_nova'\");")
 check(badges('cmdr_nova')==flair,'operator actor key does not replace current role')
 print(json.dumps({'width':width,'checks':len(checks),'ok':True}))
finally:
 s.terminate();s.wait(timeout=5);log.close()
 (O/('result-'+width.lower()+'.json')).write_text(json.dumps({'checks':checks,'captures':captures},indent=2))
 subprocess.run(php+[str(provision)],env=dict(e,POSTING_SAPI_DROP_ONLY='1'),check=True)
