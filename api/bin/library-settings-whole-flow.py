#!/usr/bin/env python3
"""Finite real front-controller Library actions. No extracted dispatcher or policy stub.
Only isolated filesystem/config/SQL fixtures and unavailable external transports.
"""
import pathlib,os,json,subprocess,time,urllib.request,urllib.parse,http.cookiejar,re,hashlib,sqlite3
R=pathlib.Path(__file__).resolve().parents[2]; O=R/'rust/target/parity-evidence/library-settings-whole-flow'; A=O/'app'; P=R/'api/bin/php-policy-test'
O.mkdir(parents=True,exist_ok=True)
setup=(R/'api/bin/supporter-whole-flow-setup.py').read_text().replace("supporter-whole-flow'","library-settings-whole-flow'")
# Shared setup recipe copied verbatim except destination; later flags below disable rewards/approvals.
exec(compile(setup,str(R/'api/bin/supporter-whole-flow-setup.py'),'exec'))
e=os.environ.copy(); e.update(json.loads((O/'env.json').read_text())); e.update(VAAK_SUPPORTER_CODES='0',VAAK_REGISTRATION_APPROVALS='0',VAAK_LIBRARY_NATIVE_RENDER='0',VAAK_LIBRARY_NATIVE_DATA='0')
cmd=[str(P),'-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so']
provision=(R/'api/bin/posting-threading-sapi-pg-provision.php').read_text().replace("$schema='posting_threading_sapi'","$schema='library_settings_whole_flow'")
(O/'provision.php').write_text(provision)
subprocess.run(cmd+[str(O/'provision.php')],env=dict(e,POSTING_SAPI_SQLITE=str(O/'storage/ap.sqlite'),POSTING_SAPI_API_SOURCE=str(A/'api')),check=True)
e['AP_DB_DSN']='pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres options=--search_path=library_settings_whole_flow'
(A/'seed.php').write_text("<?php require __DIR__.'/api/ap-auth.php';ap_auth_bootstrap();$d=ap_db();$d->prepare('INSERT INTO actor_profile(actor_key,name,summary,attachment_json,updated_at) VALUES(?,?,?,?,?) ON CONFLICT(actor_key) DO NOTHING')->execute(['cmdr_nova','Library operator','','[]',ap_db_now()]);$d->prepare('UPDATE ap_users SET password_hash=? WHERE username=?')->execute([password_hash('fixture-admin-password',PASSWORD_DEFAULT),'cmdr_nova']); $d->beginTransaction();ap_auth_create_account_row($d,'librarysecond','second@example.invalid',password_hash('fixture-member-password',PASSWORD_DEFAULT),ap_db_now());$d->commit();")
subprocess.run(cmd+[str(A/'seed.php')],env=e,check=True)
(A/'state.php').write_text("<?php require __DIR__.'/api/ap-auth.php';ap_auth_bootstrap();require __DIR__.'/api/ap-bookmark-folders.php';vaak_bookmark_folders_ensure_schema();$d=ap_db();echo json_encode(['users'=>$d->query('SELECT id,username FROM ap_users ORDER BY id')->fetchAll(),'profiles'=>$d->query('SELECT actor_key,auto_unblur_sensitive,downranking_enabled FROM actor_profile ORDER BY actor_key')->fetchAll(),'folders'=>$d->query('SELECT * FROM vaak_bookmark_folders ORDER BY id')->fetchAll(),'items'=>$d->query('SELECT * FROM vaak_bookmark_folder_items ORDER BY folder_id,status_id')->fetchAll(),'bookmarks'=>$d->query('SELECT * FROM masto_bookmarks ORDER BY owner_user_id,status_id')->fetchAll(),'subscriptions'=>$d->query('SELECT * FROM ap_post_subscriptions ORDER BY owner_user_id,target_actor_id')->fetchAll()]);")
def state():return json.loads(subprocess.check_output(cmd+[str(A/'state.php')],env=e))
log=(O/'server.log').open('w'); s=subprocess.Popen(cmd+['-d','disable_functions=exec,shell_exec,curl_init','-d','allow_url_fopen=0','-S','127.0.0.1:18481',str(A/'router.php')],env=e,stdout=log,stderr=log)
checks=[];captures=[]
def check(v,m):
 checks.append({'check':m,'ok':bool(v)})
 if not v:raise AssertionError(m)
def client():return urllib.request.build_opener(urllib.request.HTTPCookieProcessor(http.cookiejar.CookieJar()))
def req(c,path,d=None):
 q=urllib.request.Request('http://127.0.0.1:18481'+path,data=urllib.parse.urlencode(d,doseq=True).encode() if d is not None else None)
 try:r=c.open(q,timeout=30)
 except urllib.error.HTTPError as x:r=x
 h=r.read().decode();captures.append({'path':path,'post':d,'status':r.status,'body':h,'headers':dict(r.headers)});return h

def csrf(h):
 m=re.search(r'name="csrf" value="([^"]+)"',h);assert m,'actual CSRF missing';return m[1]
def login(c,name,pw):
 h=req(c,'/vaak/?mode=login');return req(c,'/vaak/?mode=login',dict(action='login',csrf=csrf(h),login=name,password=pw))
def folder(c,token,action,**kw):return json.loads(req(c,'/vaak/?view=bookmarks',dict(action=action,csrf=token,ajax='1',return_view='bookmarks',**kw)))
try:
 c=client()
 for _ in range(30):
  try:h=req(c,'/vaak/?mode=login');break
  except OSError:time.sleep(.1)
 h=login(c,'cmdr_nova','fixture-admin-password');check('name="action" value="login"' not in h,'actual operator login')
 h=req(c,'/vaak/?view=profile');token=csrf(h);before=state()
 req(c,'/vaak/?view=profile',dict(action='save_profile',csrf='forged',name='forged',auto_unblur_sensitive='1'))
 check(state()==before,'Settings global CSRF leaves SQL unchanged')
 for on in [True,False,True]:
  data=dict(action='save_profile',csrf=token,name='Library operator',summary='Isolated Library fixture biography',email='',reply_policy='anyone',quote_policy='anyone',downranking_enabled='1')
  if on:data['auto_unblur_sensitive']='1'
  h=req(c,'/vaak/?view=profile',data);token=csrf(h)
  row=next(x for x in state()['profiles'] if x['actor_key']=='cmdr_nova')
  check(bool(row['auto_unblur_sensitive'])==on,'real Settings persists sensitive preference '+str(on))
  check(bool(row['downranking_enabled']),'real Settings persists downranking enabled')
 before=state();req(c,'/vaak/?view=bookmarks',dict(action='bookmark_folder_create',csrf='bad',ajax='1',title='CSRF rejected'))
 check(state()==before,'folder global CSRF leaves SQL unchanged')
 r=folder(c,token,'bookmark_folder_create',title='Media café');check(r['ok'],'real folder create Unicode');fid=r['id']
 r=folder(c,token,'bookmark_folder_create',title='');check(not r['ok'],'real empty folder rejects')
 r=folder(c,token,'bookmark_folder_create',title='é'*81);check(not r['ok'],'real overlong Unicode folder rejects')
 r=folder(c,token,'bookmark_folder_add',folder_id=fid,status_id='saved-library-1',object_id='https://remote.example.invalid/posts/library1');check(r['ok'],'real add saves bookmark and folder')
 st=state();check(len(st['bookmarks'])==1 and len(st['items'])==1,'actual durable bookmark/folder association')
 r=folder(c,token,'bookmark_folder_add',folder_id=fid,status_id='saved-library-1',object_id='https://remote.example.invalid/posts/library1');check(r['ok'] and r['already'] and len(state()['items'])==1,'duplicate membership idempotent')
 second=client();hh=login(second,'librarysecond','fixture-member-password');hh=req(second,'/vaak/?view=profile');token2=csrf(hh);before=state()
 r=folder(second,token2,'bookmark_folder_add',folder_id=fid,status_id='foreign-save');check(not r['ok'] and state()==before,'cross-owner add denied without save')
 r=folder(second,token2,'bookmark_folder_delete',folder_id=fid);check(not r['ok'] and state()==before,'cross-owner delete denied')
 r=folder(second,token2,'bookmark_folder_remove',folder_id=fid,status_id='saved-library-1');check(r['ok'] and state()==before,'cross-owner remove harmless existing PHP success/noop contract')
 r=folder(c,token,'bookmark_folder_remove',folder_id=fid,status_id='saved-library-1');check(r['ok'] and not state()['items'] and len(state()['bookmarks'])==1,'folder remove retains flat bookmark')
 r=folder(c,token,'bookmark_folder_add',folder_id=fid,status_id='saved-library-1');check(r['ok'],'membership restored')
 r=folder(c,token,'bookmark_folder_delete',folder_id=fid);check(r['ok'] and not state()['items'] and not state()['folders'] and len(state()['bookmarks'])==1,'folder delete clears membership preserves save')
 for act,wanted in [('subscribe_posts',1),('subscribe_posts',1),('unsubscribe_posts',0),('unsubscribe_posts',0)]:
  req(c,'/vaak/?view=profile',dict(action=act,csrf=token,actor_id='https://remote.example.invalid/users/peer',return_view='profile'))
  check(len(state()['subscriptions'])==wanted,'actual post subscription '+act+' count '+str(wanted))
 before=state();req(c,'/vaak/?view=profile',dict(action='subscribe_posts',csrf='bad',actor_id='https://remote.example.invalid/users/peer',return_view='profile'))
 check(state()==before,'subscription CSRF has no durable effects')
 req(c,'/vaak/?view=profile',dict(action='subscribe_posts',csrf=token,actor_id='http://remote.example.invalid/users/peer',return_view='profile'))
 check(state()==before,'invalid insecure subscription target denied')
 check(not any('Fatal error' in x['body'] for x in captures),'full controller responses no fatal error')
finally:
 (O/'checks.json').write_text(json.dumps(checks,indent=2));(O/'captures.json').write_text(json.dumps(captures,indent=2))
 s.terminate();s.wait(timeout=5);log.close()
print(json.dumps({'checks':len(checks),'passed':all(x['ok'] for x in checks),'scope':'Actual full copied controller/login/session/CSRF/SQL; native post-action mapping remains separate open gate'}))
