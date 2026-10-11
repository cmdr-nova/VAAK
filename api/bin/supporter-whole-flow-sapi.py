#!/usr/bin/env python3
"""Full copied front/controller/bootstrap, real login/session/CSRF/approval/keys/PDO.
Only storage/config paths and outbound transports are isolated. No extracted handlers.
"""
import sys
import pathlib,os,json,subprocess,time,urllib.request,urllib.parse,http.cookiejar,re,hashlib
R=pathlib.Path(__file__).resolve().parents[2];O=R/'rust/target/parity-evidence/supporter-whole-flow';A=O/'app';P=R/'api/bin/php-policy-test'
WIDTH='integer' if '--integer' in sys.argv else 'bigint'
e=os.environ.copy();e.update(json.loads((O/'env.json').read_text()));e['AP_DB_DSN']='pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=--search_path=supporter_whole_flow'
if WIDTH=='integer':e['AP_DB_DSN']=e['AP_DB_DSN'].replace('supporter_whole_flow','supporter_whole_flow_integer')
cmd=[str(P),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so']
import shutil
shutil.rmtree(O/'tmp/ap-vaak-registration-rate',ignore_errors=True)
for owned in [O/'keys',O/'storage/sessions']:
 shutil.rmtree(owned,ignore_errors=True);owned.mkdir(parents=True)
provision=O/'provision.php'
if WIDTH=='integer':
 provision=O/'provision-integer.php';provision.write_text((O/'provision.php').read_text().replace("$schema='supporter_whole_flow'","$schema='supporter_whole_flow_integer'").replace("'BIGINT'","'INTEGER'").replace("'BIGSERIAL'","'SERIAL'"))
subprocess.run(cmd+[str(provision)],env=dict(e,POSTING_SAPI_SQLITE=str(O/'storage/ap.sqlite'),POSTING_SAPI_API_SOURCE=str(A/'api')),check=True)
setup="""<?php require __DIR__.'/api/ap-registration-approvals.php';ap_auth_bootstrap();$d=ap_db();ap_registration_approvals_schema($d);ap_supporter_schema($d);$d->prepare('UPDATE ap_users SET password_hash=? WHERE username=?')->execute([password_hash('fixture-admin-password',PASSWORD_DEFAULT),'cmdr_nova']); $i=ap_supporter_issue('cus_WholeFlow','ch_WholeFlow','paid@example.invalid');echo json_encode($i);"""
(A/'seed.php').write_text(setup);seed=json.loads(subprocess.check_output(cmd+[str(A/'seed.php')],env=e));assert seed['ok']
# State read executes actual bootstrap, without altering request authentication.
(A/'state.php').write_text("<?php require __DIR__.'/api/ap-registration-approvals.php';ap_auth_bootstrap();$d=ap_db();echo json_encode(['users'=>$d->query('SELECT id,username,is_admin,disabled_at FROM ap_users ORDER BY id')->fetchAll(),'applications'=>$d->query('SELECT id,username,status,user_id,password_hash IS NULL AS erased FROM ap_registration_applications ORDER BY id')->fetchAll(),'used'=>(int)$d->query('SELECT count(*) FROM ap_supporter_codes WHERE used_at IS NOT NULL')->fetchColumn()]);")
def state():return json.loads(subprocess.check_output(cmd+[str(A/'state.php')],env=e))
log=(O/('sapi-server-'+WIDTH+'.log')).open('w');s=subprocess.Popen(cmd+['-d','disable_functions=exec,shell_exec,curl_init','-d','allow_url_fopen=0','-S','127.0.0.1:18997',str(A/'router.php')],env=e,stdout=log,stderr=log)
checks=[];captures=[]
def check(v,m):
 checks.append({'check':m,'ok':bool(v)})
 if not v:raise AssertionError(m)
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,d=None):
 t=time.monotonic();q=urllib.request.Request('http://127.0.0.1:18997'+path,data=urllib.parse.urlencode(d).encode() if d is not None else None)
 try:r=c.open(q,timeout=30)
 except urllib.error.HTTPError as x:r=x
 h=r.read().decode();captures.append({'path':path,'status':r.status,'elapsed':time.monotonic()-t,'body':h,'headers':dict(r.headers)});return h
def csrf(h):
 m=re.search(r'name="csrf" value="([^"]+)"',h);assert m,'actual CSRF form missing';return m[1]
def application(c,name,code='',email=''):
 h=req(c,'/vaak/?mode=register');return req(c,'/vaak/?mode=register',dict(action='register',csrf=csrf(h),username=name,password='fixture-member-password',email=email,invite=code))
def login(c,name,pw):
 h=req(c,'/vaak/?mode=login');return req(c,'/vaak/?mode=login',dict(action='login',csrf=csrf(h),login=name,password=pw))
try:
 c=client()
 for _ in range(30):
  try:h=req(c,'/vaak/?mode=register');break
  except OSError:time.sleep(.1)
 check('Supporter code (optional)' in h and 'Every application' in h,'real logged-out application form')
 h=req(c,'/vaak/?mode=register',dict(action='register',csrf='bad',username='wrongcsrf',password='fixture-member-password'));check(not state()['applications'],'invalid CSRF creates no application')
 h=application(c,'legacyapp','LEGACY-INVITE');check(not state()['applications'],'legacy invite does not become paid reward')
 h=application(c,'wrongemail',seed['code'],'other@example.invalid');check(not state()['applications'],'paid code requires bound email')
 h=application(c,'ordinaryapp');check('Application submitted' in h,'no-code optional-email application pending')
 h=application(c,'paidapp',seed['code'],'paid@example.invalid');check('Application submitted' in h,'paid application remains pending')
 st=state();check(len(st['users'])==1 and len(st['applications'])==2 and st['used']==0,'pending creates no account and spends no reward')
 check(not (O/'keys/paidapp_private.pem').exists(),'pending creates no private key')
 h=login(c,'paidapp','fixture-member-password');check('name="password"' in h,'pending cannot login')
 admin=client();h=login(admin,'cmdr_nova','fixture-admin-password');check('name="action" value="login"' not in h,'actual operator password login reaches authenticated controller')
 h=req(admin,'/vaak/?view=approvals');check('ordinaryapp' in h and 'paidapp' in h and 'registration_approve' in h,'actual admin application list/forms')
 ids={x['username']:x['id'] for x in state()['applications']};token=csrf(h)
 h=req(admin,'/vaak/?view=approvals',dict(action='registration_approve',csrf='bad',application_id=ids['paidapp']));check(state()['used']==0,'global admin CSRF denies reward consumption')
 h=req(admin,'/vaak/?view=approvals',dict(action='registration_approve',csrf=token,application_id=ids['paidapp']));st=state();check(st['used']==1 and any(x['username']=='paidapp' for x in st['users']),'actual approval creates account and consumes reward atomically')
 check((O/'keys/paidapp_private.pem').exists() and (O/'keys/paidapp_public.pem').exists(),'actual RSA provisioning writes isolated keypair')
 h=req(admin,'/vaak/?view=approvals',dict(action='registration_reject',csrf=csrf(h),application_id=ids['ordinaryapp']));st=state();check(all(x['erased'] for x in st['applications']) and not any(x['username']=='ordinaryapp' for x in st['users']),'actual rejection erases hash without account')
 # Existing paid account fixture uses the actual transactional account creator.
 (A/'existing-seed.php').write_text("<?php require __DIR__.'/api/ap-registration-approvals.php';$d=ap_db();$d->beginTransaction();ap_auth_create_account_row($d,'existingpaid','existing@example.invalid',password_hash('fixture-member-password',PASSWORD_DEFAULT),ap_db_now());$d->commit();echo json_encode(ap_supporter_issue('cus_ExistingPaid','ch_ExistingPaid','existing@example.invalid'));")
 existing_reward=json.loads(subprocess.check_output(cmd+[str(A/'existing-seed.php')],env=e));check(existing_reward['ok'],'synthetic trusted existing payer reward issued')
 existing=client();h=login(existing,'existingpaid','fixture-member-password');h=req(existing,'/vaak/?view=profile');token=csrf(h)
 h=req(existing,'/vaak/?view=profile',dict(action='redeem_supporter',csrf='bad',supporter_code=existing_reward['code']));check(state()['used']==1,'actual Settings CSRF denies redemption')
 h=req(existing,'/vaak/?view=profile',dict(action='redeem_supporter',csrf=token,supporter_code=existing_reward['code']));check(state()['used']==2 and 'Supporter badge awarded' in h,'actual Settings redeems paid existing user reward')
 check(next(x for x in state()['users'] if x['username']=='existingpaid')['is_admin']==0,'paid redemption grants no admin role')
 h=req(existing,'/vaak/?view=profile',dict(action='redeem_supporter',csrf=csrf(h),supporter_code=existing_reward['code']));check(state()['used']==2,'actual duplicate Settings redemption consumes once')
 member=client();h=login(member,'paidapp','fixture-member-password');check('name="action" value="login"' not in h,'approved member real login succeeds')
 h=req(member,'/vaak/?view=profile');check('Supporter' in h,'actual profile Settings contains earned reward')
 # Rendering authority must be current even when stored profile data omits or forges Supporter.
 (A/'authority-control.php').write_text("<?php require __DIR__.'/api/ap-registration-approvals.php';$d=ap_db();$d->prepare('UPDATE actor_profile SET profile_badges=? WHERE actor_key=?')->execute([json_encode(['rainbow','trans','bi','nonbinary','lesbian','gay']),'paidapp']);")
 subprocess.run(cmd+[str(A/'authority-control.php')],env=e,check=True)
 h=req(member,'/vaak/?view=remote_profile&local_key=paidapp');check('title="Supporter"' in h,'actual public profile recovers omitted earned badge')
 check(all('title="'+label+'"' in h for label in ['Rainbow pride','Trans pride','Bisexual pride','Non-binary pride','Lesbian pride','Gay pride']),'six freely selected badges coexist with earned badge')
 guest=client();h=req(guest,'/vaak/users/paidapp');check('title="Supporter"' in h,'guest pretty profile shows server-earned badge')
 h=req(member,'/vaak/?view=approvals');check('registration_approve' not in h,'ordinary paid member cannot see approval decision form')
 h=req(member,'/vaak/?view=approvals',dict(action='registration_approve',csrf=csrf(h),application_id=ids['ordinaryapp']));check(not any(x['username']=='ordinaryapp' for x in state()['users']),'paid member cannot approve rejected applicant')
 h=application(c,'withdrawapp');check('Application submitted' in h,'fifth admitted application pending');
 h=application(c,'rateblocked');check('Too many application attempts' in h and len(state()['applications'])==3,'actual public admission cap before account creation')
 def control(code):
  (A/'authority-control.php').write_text("<?php require __DIR__.'/api/ap-registration-approvals.php';$d=ap_db();"+code)
  subprocess.run(cmd+[str(A/'authority-control.php')],env=e,check=True)
 control("$d->exec(\"UPDATE actor_profile SET profile_badges='[\\\"supporter\\\"]' WHERE actor_key='cmdr_nova'\");")
 h=req(member,'/vaak/?view=remote_profile&local_key=cmdr_nova');check('title="Supporter"' not in h,'forged stored badge denied in actual public profile')
 control("$d->exec(\"UPDATE ap_supporter_codes SET used_at=NULL WHERE used_by=(SELECT id FROM ap_users WHERE username='paidapp')\");")
 h=req(member,'/vaak/?view=remote_profile&local_key=paidapp');check('title="Supporter"' not in h,'current withdrawal removes earned public profile badge')
 # The same authenticated admin session must lose decision permission immediately.
 control("$d->exec(\"UPDATE ap_users SET is_admin=0 WHERE username='cmdr_nova'\");")
 h=req(admin,'/vaak/?view=approvals');check('registration_approve' not in h,'actual admin page hides applications after role withdrawal')
 wid=next(x['id'] for x in state()['applications'] if x['username']=='withdrawapp')
 h=req(admin,'/vaak/?view=approvals',dict(action='registration_approve',csrf=csrf(h),application_id=wid));check(not any(x['username']=='withdrawapp' for x in state()['users']),'actual POST rejects current admin role withdrawal')
 control("$d->exec(\"UPDATE ap_users SET is_admin=1,disabled_at='2026-10-11T00:00:00Z' WHERE username='cmdr_nova'\");")
 h=req(admin,'/vaak/?view=approvals');check('name="action" value="login"' in h,'disabled admin session returns real login form')
 control("$d->exec(\"UPDATE ap_users SET disabled_at=NULL WHERE username='cmdr_nova'\");")
 control("$d->exec('DROP TABLE ap_supporter_codes');")
 h=req(member,'/vaak/?view=remote_profile&local_key=existingpaid');check('title="Supporter"' not in h and captures[-1]['status']==200,'missing reward table fails closed without broken profile')
 check('Undefined array key' not in (O/('sapi-server-'+WIDTH+'.log')).read_text(),'real Redis-unavailable fallback no array-key warnings')
 (O/('result-'+WIDTH+'.json')).write_text(json.dumps({'checks':checks,'captures':captures,'boundaries':['isolated DB/session/key/config/temp paths','actual empty schema inventory from SQLite with declared legacy DM table and native module migrations','trusted issuer invoked only CLI using synthetic settled receipt identifiers; separate Clearance eligibility gate','external HTTP/exec and SMTP unavailable; real RSA/account/local notification provisioning executes'],'source_sha256':{str(p.relative_to(A)):hashlib.sha256(p.read_bytes()).hexdigest() for p in A.rglob('*.php')}},indent=2));print(json.dumps({'ok':True,'checks':len(checks)}))
finally:
 s.terminate();s.wait(timeout=5);log.close();(O/('partial-result-'+WIDTH+'.json')).write_text(json.dumps({'checks':checks,'captures':captures},indent=2))
