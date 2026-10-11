#!/usr/bin/env python3
"""Finite full-controller failure/concurrency/legacy gate; no extracted application handlers.
Reuses retained whole-flow fixture setup/request plumbing only. All actual handlers stay whole.
"""
import pathlib,os,sys,shutil,json,hashlib,socket,signal,concurrent.futures,threading
R=pathlib.Path(__file__).resolve().parents[2]
POLICY_ONLY='--policy-only' in sys.argv
ORIGINAL=R/'rust/target/parity-evidence/supporter-bootstrap-activation'
O=R/('rust/target/parity-evidence/supporter-bootstrap-policy-repair' if POLICY_ONLY else 'rust/target/parity-evidence/supporter-bootstrap-activation')
SCHEMA='supporter_activation_policy' if POLICY_ONLY else 'supporter_activation'
WIDTH='integer' if '--integer' in sys.argv else 'bigint'
O.mkdir(parents=True,exist_ok=True)
# Deny external Python transport before DNS/connect; fixture listeners/socket only.
_dns=socket.getaddrinfo;_connect=socket.socket.connect
socket.getaddrinfo=lambda host,*a,**k: _dns(host,*a,**k) if host in ('127.0.0.1','localhost','::1',None) else (_ for _ in ()).throw(RuntimeError('External DNS denied'))
def connect(self,addr):
 if isinstance(addr,tuple) and addr[0] not in ('127.0.0.1','::1'):raise RuntimeError('External socket denied')
 return _connect(self,addr)
socket.socket.connect=connect
source=O/'source'
if POLICY_ONLY and not source.exists():
 shutil.copytree(ORIGINAL/'source',source)
 for task_file in ['api/bin/supporter-bootstrap-activation.py','api/bin/php-supporter-isolated']:
  shutil.copy2(R/task_file,source/task_file)
 shutil.copy2(ORIGINAL/'provision-template.php',O/'provision-template.php')
if not source.exists():
 manifest=[]
 for d in ['api','vaak']:
  for p in (R/d).rglob('*'):
   if not p.is_file():continue
   q=source/p.relative_to(R);q.parent.mkdir(parents=True,exist_ok=True);shutil.copy2(p,q)
   manifest.append({'path':str(p.relative_to(R)),'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'mode':oct(p.stat().st_mode&0o777)})
 (O/'inputs.json').write_text(json.dumps(manifest,indent=2))
os.environ['VAAK_TEST_PHP_BINARY']=str((source if POLICY_ONLY else R)/'api/bin/php-supporter-isolated')
if POLICY_ONLY:
 os.environ['VAAK_TEST_PHP_AUDIT_LOG']=str(O/('effective-'+WIDTH+'.jsonl'))
 os.environ['VAAK_TEST_PHP_AUDIT_PREPEND']=str(O/'audit.php')
 (O/'audit.php').write_text('''<?php
$disabled=explode(',',(string)ini_get('disable_functions'));
$required=['exec','shell_exec','system','passthru','proc_open','popen','curl_init','fsockopen','pfsockopen','stream_socket_client','socket_create'];
foreach($required as $f)if(!in_array($f,$disabled,true)||function_exists($f))throw new RuntimeException('Fixture denial policy missing: '.$f);
if(ini_get('allow_url_fopen')!=='0')throw new RuntimeException('Fixture URL streams enabled');
file_put_contents(getenv('VAAK_TEST_PHP_AUDIT_LOG'),json_encode(['sapi'=>PHP_SAPI,'script'=>$_SERVER['SCRIPT_FILENAME']??null,'allow_url_fopen'=>ini_get('allow_url_fopen'),'disabled'=>$disabled])."\\n",FILE_APPEND|LOCK_EX);
''')
 (O/('effective-'+WIDTH+'.jsonl')).write_text('')
setup=(source/'api/bin/supporter-whole-flow-setup.py').read_text().replace("o=r/'rust/target/parity-evidence/supporter-whole-flow'","o=r/"+repr(str(O.relative_to(R))))
os.environ['SUPPORTER_WHOLE_SOURCE']=str(source)
exec(compile(setup,'fixture-setup','exec'),{'__file__':str(R/'api/bin/supporter-whole-flow-setup.py')})
old=R/'rust/target/parity-evidence/supporter-whole-flow'
if not (O/'provision-template.php').exists():shutil.copy2(old/'provision.php',O/'provision-template.php')
(O/'provision.php').write_text((O/'provision-template.php').read_text().replace('supporter_whole_flow',SCHEMA))
# Existing fixture prefix runs complete bootstrap, seed, isolated server and defines HTTP clients only.
base=(source/'api/bin/supporter-whole-flow-sapi.py').read_text().split('\ntry:\n c=client()')[0]
base=base.replace("O=R/'rust/target/parity-evidence/supporter-whole-flow'","O=R/"+repr(str(O.relative_to(R)))).replace('supporter_whole_flow',SCHEMA).replace('18997','18995')
base=base.replace("s=subprocess.Popen(","e['PHP_CLI_SERVER_WORKERS']='4';s=subprocess.Popen(").replace('stderr=log)','stderr=log,start_new_session=True)')
base=base.replace("'-S'","'-t',str(A),'-S'").replace("'elapsed':time.monotonic()-t","'started':t,'ended':time.monotonic(),'elapsed':time.monotonic()-t")
base=base.replace('disable_functions=exec,shell_exec,curl_init','disable_functions=exec,shell_exec,system,passthru,proc_open,popen,curl_init,fsockopen,pfsockopen,stream_socket_client,socket_create')
router=((O/'app')/'router.php').read_text();((O/'app')/'router.php').write_text("<?php "+("require getenv('VAAK_TEST_PHP_AUDIT_PREPEND');" if POLICY_ONLY else "")+"if(str_starts_with($_SERVER['REQUEST_URI'],'/api/v')){require __DIR__.'/api/ap-mastodon.php';exit;}if(in_array($_SERVER['REQUEST_URI'],['/api/ap-auth.php','/api/ap-registration-approvals.php'],true))return false;require __DIR__.'/vaak/index.php';")
exec(compile(base,'retained-fixture-plumbing','exec'))
def control(code):
 (A/'control.php').write_text("<?php require __DIR__.'/api/ap-registration-approvals.php';ap_auth_bootstrap();$d=ap_db();"+code)
 return subprocess.check_output(cmd+[str(A/'control.php')],env=e).decode()
def clear_rate():shutil.rmtree(O/'tmp/ap-vaak-registration-rate',ignore_errors=True)
def app(name,code='',email='',path='/vaak/?mode=register',action='register'):
 clear_rate();c=client();h=req(c,'/vaak/?mode=register');d=dict(csrf=csrf(h),username=name,password='fixture-member-password',email=email,invite=code)
 if action is not None:d['action']=action
 return req(c,path,d)
def newid(name):return next(x['id'] for x in state()['applications'] if x['username']==name)
def adminclient():
 c=client();login(c,'cmdr_nova','fixture-admin-password');h=req(c,'/vaak/?view=approvals');return c,csrf(h)
def reward(name,email):return json.loads(control("echo json_encode(ap_supporter_issue('cus_"+name+"','ch_"+name+"','"+email+"'));"))['code']
def parallel(jobs):
 barrier=threading.Barrier(len(jobs))
 def call(j):barrier.wait();return j()
 with concurrent.futures.ThreadPoolExecutor(max_workers=len(jobs)) as pool:return list(pool.map(call,jobs))
try:
 c=client()
 for _ in range(50):
  try:h=req(c,'/vaak/?mode=register');break
  except OSError:time.sleep(.1)
 if POLICY_ONLY:
  check(bool(state()['users']),'state CLI uses full protected bootstrap')
  check(control("echo 'protected-control';")=='protected-control','control CLI uses protected bootstrap')
  (A/'probe.php').write_text("<?php $warnings=[];set_error_handler(function($n,$s)use(&$warnings){$warnings[]=$s;return true;});$result=file_get_contents('https://fixture-denied.example.invalid/');restore_error_handler();$blocked=[];foreach(['stream_socket_client','fsockopen','curl_init','exec'] as $f){try{$f('fixture-denied.example.invalid');$blocked[$f]=false;}catch(Error $e){$blocked[$f]=true;}}echo json_encode(['url_denied'=>$result===false,'warnings'=>$warnings,'blocked'=>$blocked]);")
  cli=json.loads(subprocess.check_output(cmd+[str(A/'probe.php')],env=e));check(cli['url_denied'] and all(cli['blocked'].values()) and any('allow_url_fopen' in w for w in cli['warnings']),'CLI actual forbidden URL/process/socket calls fail before transport')
  (O/('probe-cli-'+WIDTH+'.json')).write_text(json.dumps(cli,indent=2))
  (A/'router.php').write_text("<?php require getenv('VAAK_TEST_PHP_AUDIT_PREPEND');require __DIR__.'/probe.php';")
  os.killpg(s.pid,signal.SIGTERM);s.wait(timeout=5)
  s=subprocess.Popen(cmd+['-t',str(A),'-S','127.0.0.1:18995',str(A/'router.php')],env=e,stdout=log,stderr=log,start_new_session=True)
  for _ in range(40):
   try:probe=json.loads(req(client(),'/'));break
   except OSError:time.sleep(.1)
  check(probe['url_denied'] and all(probe['blocked'].values()) and any('allow_url_fopen' in w for w in probe['warnings']),'HTTP actual forbidden URL/process/socket calls fail before transport')
  (O/('probe-http-'+WIDTH+'.json')).write_text(json.dumps(probe,indent=2))
  print(json.dumps({'ok':True,'policy_checks':len(checks),'width':WIDTH}))
  raise SystemExit(0)
 for version in [1,2]:
  data=json.loads(req(client(),'/api/v'+str(version)+'/instance'));reg=data['registrations'];check((reg is True and data['approval_required'] is True and data['invites_enabled'] is False) if version==1 else (reg['enabled'] is True and reg['approval_required'] is True and reg['reason_required'] is False and reg['url']=='https://mkultra.monster/vaak/?mode=register'),'actual v'+str(version)+' metadata describes approval applications')
 # Alternate actual index auth branches, action takes precedence or mode dispatches.
 for name,path,action in [('actiononly','/vaak/','register'),('modeonly','/vaak/?mode=register',None),('loginmode','/vaak/?mode=login','register')]:
  h=app(name,path=path,action=action);check('Application submitted' in h and len(state()['users'])==1,'alternate '+name+' stays pending without account')
 # Actual library direct entry denies; actual Mastodon full router has no account-creation route.
 h=req(client(),'/api/v1/accounts',dict(username='masto_bypass',password='fixture-member-password'));check(captures[-1]['status'] in (401,404) and len(state()['users'])==1,'actual Mastodon account POST cannot bypass approval')
 for lib in ['ap-auth.php','ap-registration-approvals.php']:
  h=req(client(),'/api/'+lib,dict(action='register',username='library_bypass',password='fixture-member-password'));check(captures[-1]['status']==403 and len(state()['users'])==1,'direct '+lib+' denies account creation')
 # Failure at real PostgreSQL INSERT boundary via declared trigger, actual controller remains unchanged.
 paid=reward('Storage','storage@example.invalid');app('storagefail',paid,'storage@example.invalid');aid=newid('storagefail');ad,t=adminclient()
 control("$d->exec(\"CREATE FUNCTION fixture_fail_insert() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.username = ''storagefail'' THEN RAISE EXCEPTION ''declared storage boundary failure''; END IF; RETURN NEW; END'; CREATE TRIGGER fixture_insert_failure BEFORE INSERT ON ap_users FOR EACH ROW EXECUTE FUNCTION fixture_fail_insert()\");")
 h=req(ad,'/vaak/?view=approvals',dict(action='registration_approve',csrf=t,application_id=aid));st=state()
 check('Could not decide' in h and next(x for x in st['applications'] if x['id']==aid)['status']=='pending' and st['used']==0 and not any(x['username']=='storagefail' for x in st['users']),'real storage error rolls back account/status/reward')
 check(not (O/'keys/storagefail_private.pem').exists(),'storage failure creates no key')
 control("$d->exec('DROP TRIGGER fixture_insert_failure ON ap_users; DROP FUNCTION fixture_fail_insert()');")
 h=req(ad,'/vaak/?view=approvals',dict(action='registration_approve',csrf=csrf(h),application_id=aid));check(state()['used']==1 and next(x for x in state()['applications'] if x['id']==aid)['status']=='approved','pending storage failure retries successfully')
 # Failure after actual reward update must roll back the whole decision, including spend.
 latecode=reward('LateFailure','latefail@example.invalid');app('latefail',latecode,'latefail@example.invalid');lid=newid('latefail');before=state()['used']
 control("$d->exec(\"CREATE FUNCTION fixture_fail_decision() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN IF NEW.username = ''latefail'' AND NEW.status = ''approved'' THEN RAISE EXCEPTION ''declared post-award storage failure''; END IF; RETURN NEW; END'; CREATE TRIGGER fixture_decision_failure BEFORE UPDATE ON ap_registration_applications FOR EACH ROW EXECUTE FUNCTION fixture_fail_decision()\");")
 h=req(ad,'/vaak/?view=approvals',dict(action='registration_approve',csrf=csrf(h),application_id=lid));st=state()
 check('Could not decide' in h and st['used']==before and not any(x['username']=='latefail' for x in st['users']) and next(x for x in st['applications'] if x['id']==lid)['status']=='pending','post-award storage failure rolls back account/reward/application together')
 check(not (O/'keys/latefail_private.pem').exists(),'post-award failed decision provisions no key')
 control("$d->exec('DROP TRIGGER fixture_decision_failure ON ap_registration_applications; DROP FUNCTION fixture_fail_decision()');")
 h=req(ad,'/vaak/?view=approvals',dict(action='registration_approve',csrf=csrf(h),application_id=lid));check(state()['used']==before+1,'post-award failure retains usable pending retry')
 # Actual missing key directory: legacy postcommit best effort deliberately retained.
 app('keyfail');kid=newid('keyfail');shutil.move(str(O/'keys'),str(O/'keys-offline'))
 h=req(ad,'/vaak/?view=approvals',dict(action='registration_approve',csrf=csrf(h),application_id=kid));check('provisioning needs operator attention' in h and any(x['username']=='keyfail' for x in state()['users']),'real key storage failure preserves committed account and operator warning')
 shutil.move(str(O/'keys-offline'),str(O/'keys'))
 h=req(ad,'/vaak/?view=approvals',dict(action='registration_approve',csrf=csrf(h),application_id=kid));check('Application already decided' in h and not (O/'keys/keyfail_private.pem').exists(),'decision replay does not silently repeat provisioning')
 repair=json.loads(control("echo json_encode(ap_actor_ensure_keypair('keyfail'));"));check(repair['ok'] and (O/'keys/keyfail_private.pem').exists(),'actual key helper permits explicit operator remediation')
 member=client();h=login(member,'keyfail','fixture-member-password');check('name="action" value="login"' not in h,'postcommit key failure account has usable real login')
 # Simultaneous actual approval requests use independent login sessions/workers.
 for name,decisions in [('dupapprove',['registration_approve']*2),('mixeddecision',['registration_approve','registration_reject'])]:
  code=reward(name,name+'@example.invalid');app(name,code,name+'@example.invalid');i=newid(name);pairs=[adminclient(),adminclient()];before=state()['used']
  outputs=parallel([lambda c=c,t=t,d=d:req(c,'/vaak/?view=approvals',dict(action=d,csrf=t,application_id=i)) for (c,t),d in zip(pairs,decisions)])
  st=state();row=next(x for x in st['applications'] if x['id']==i);users=[x for x in st['users'] if x['username']==name]
  check(row['status'] in ('approved','rejected') and len(users)==(1 if row['status']=='approved' else 0) and st['used']==before+(1 if row['status']=='approved' else 0),'simultaneous '+name+' has one coherent durable outcome')
  check(not row['erased']==False and all('Internal server error' not in x for x in outputs),'simultaneous '+name+' erases password and no unhandled error')
 # Real Settings save and redemption on separate sessions for same paid account.
 control("$d->beginTransaction();ap_auth_create_account_row($d,'racepaid','racepaid@example.invalid',password_hash('fixture-member-password',PASSWORD_DEFAULT),ap_db_now());$d->commit();")
 code=reward('Redeem','racepaid@example.invalid');pairs=[]
 for _ in range(3):
  m=client();login(m,'racepaid','fixture-member-password');h=req(m,'/vaak/?view=profile');pairs.append((m,csrf(h)))
 before=state()['used'];jobs=[]
 for m,t in pairs[:2]:jobs.append(lambda m=m,t=t:req(m,'/vaak/?view=profile',dict(action='redeem_supporter',csrf=t,supporter_code=code)))
 m,t=pairs[2];jobs.append(lambda:req(m,'/vaak/?view=profile',dict(action='save_profile',csrf=t,name='Race Paid',summary='actual concurrent profile save',email='racepaid@example.invalid',reply_policy='anyone',quote_policy='anyone',discoverable='1')))
 outputs=parallel(jobs);check(state()['used']==before+1,'simultaneous real Settings duplicate redeem spends once')
 h=req(client(),'/vaak/users/racepaid');check('title="Supporter"' in h and 'Race Paid' in h,'simultaneous profile save preserves current earned badge and profile edit')
 check(all('Internal server error' not in x for x in outputs),'simultaneous save/redeem no unhandled response')
 # Restart complete worker with both flags unset: old actual invite workflow/UI.
 os.killpg(s.pid,signal.SIGTERM);s.wait(timeout=5);e.pop('VAAK_REGISTRATION_APPROVALS',None);e.pop('VAAK_SUPPORTER_CODES',None)
 s=subprocess.Popen(cmd+['-d','disable_functions=exec,shell_exec,system,passthru,proc_open,popen,curl_init,fsockopen,pfsockopen,stream_socket_client,socket_create','-d','allow_url_fopen=0','-t',str(A),'-S','127.0.0.1:18995',str(A/'router.php')],env=e,stdout=log,stderr=log,start_new_session=True)
 for _ in range(40):
  try:h=req(client(),'/vaak/?mode=register');break
  except OSError:time.sleep(.1)
 for version in [1,2]:
  data=json.loads(req(client(),'/api/v'+str(version)+'/instance'));reg=data['registrations'];check((reg is False and data['approval_required'] is False and data['invites_enabled'] is True) if version==1 else reg==dict(enabled=False,approval_required=False,message=None),'flags unset exact legacy v'+str(version)+' registration metadata')
 check('Invite code' in h and 'Supporter code (optional)' not in h,'flags unset real legacy invite form')
 control("$d->exec(\"INSERT INTO ap_invite_codes(code,created_at) VALUES('LEGACY-ACTUAL','2026-10-11T00:00:00Z')\");")
 h=app('nocodelegacy');check(not any(x['username']=='nocodelegacy' for x in state()['users']),'flags unset no-code signup denied')
 h=app('legacyactual','LEGACY-ACTUAL','legacyactual@example.invalid');check(any(x['username']=='legacyactual' for x in state()['users']),'flags unset real invite creates account/login')
 h=req(client(),'/vaak/users/legacyactual');check('title="Supporter"' not in h,'legacy invite awards no Supporter badge')
 h=app('legacyagain','LEGACY-ACTUAL');check(not any(x['username']=='legacyagain' for x in state()['users']),'legacy invite single-use unchanged')
 ad,t=adminclient();h=req(ad,'/vaak/?view=approvals',dict(action='registration_approve',csrf=t,application_id=newid('actiononly')));check(not any(x['username']=='actiononly' for x in state()['users']),'flags unset pending approval POST cannot create account')
 log.flush();check('deadlock detected' not in (O/('sapi-server-'+WIDTH+'.log')).read_text(),'actual concurrent workers no PostgreSQL deadlocks in finite replay')
 print(json.dumps({'ok':True,'checks':len(checks),'width':WIDTH}))
finally:
 try:os.killpg(s.pid,signal.SIGTERM);s.wait(timeout=5)
 except ProcessLookupError:pass
 log.close();(O/('result-'+WIDTH+'.json')).write_text(json.dumps({'checks':checks,'captures':captures,'boundaries':['real complete controller/bootstrap and multiworker sessions','declared PostgreSQL account INSERT failure trigger','real missing key directory and explicit helper remediation','loopback only Python DNS/socket; external PHP network/process primitives disabled','same declared empty schema inventory as accepted whole flow']},indent=2))
 (O/('type-'+WIDTH+'.json')).write_text(control("echo json_encode($d->query(\"SELECT data_type FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='ap_users' AND column_name='id'\")->fetchAll());"))
 cleanup="<?php $d=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres');$d->exec('DROP SCHEMA IF EXISTS "+SCHEMA+('_integer' if WIDTH=='integer' else '')+" CASCADE');"
 (A/'cleanup.php').write_text(cleanup);subprocess.run(cmd+[str(A/'cleanup.php')],env=e,check=True)
 (O/('actual-app-'+WIDTH+'.json')).write_text(json.dumps([{'path':str(p.relative_to(A)),'sha256':hashlib.sha256(p.read_bytes()).hexdigest(),'mode':oct(p.stat().st_mode&0o777)} for p in A.rglob('*') if p.is_file()],indent=2))
