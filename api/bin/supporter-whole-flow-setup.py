import pathlib,shutil,sqlite3,os,subprocess
r=pathlib.Path(__file__).resolve().parents[2];o=r/'rust/target/parity-evidence/supporter-whole-flow';a=o/'app'
source=pathlib.Path(os.environ.get('SUPPORTER_WHOLE_SOURCE',str(r)))
for d in ['api','vaak']:
 (a/d).mkdir(parents=True,exist_ok=True)
 for p in (source/d).glob('*.php'):
  s=p.read_text().replace('/var/lib/mkultra/ap',str(o/'storage')).replace('/etc/mkultra/ap-inbox',str(o/'keys')).replace('/etc/mkultra/vaak.env',str(o/'fixture.env'))
  (a/d/p.name).write_text(s)
  shutil.copymode(p,a/d/p.name)
for d in ['keys','storage/sessions','tmp']:(o/d).mkdir(parents=True,exist_ok=True)
(o/'fixture.env').write_text('VAAK_SECRET='+('fixture-only-'*5)+'\n')
# Actual SQLite schema bootstrap, pre-provision only the legacy DM schema the migrator expects.
db=sqlite3.connect(o/'storage/ap.sqlite');db.execute('CREATE TABLE IF NOT EXISTS direct_messages(id INTEGER PRIMARY KEY AUTOINCREMENT,peer_actor_id TEXT NOT NULL,direction TEXT NOT NULL,content TEXT,created_at TEXT NOT NULL,read_at TEXT,activity_id TEXT,object_id TEXT NOT NULL UNIQUE,in_reply_to TEXT,media_urls TEXT,deleted_at TEXT)');db.commit();db.close()
env=os.environ.copy();env.update(AP_ALLOW_SQLITE='1',AP_DB_DSN='',VAAK_REDIS_ENABLED='0',VAAK_FEATURE_BLUESKY='0',VAAK_REGISTRATION_APPROVALS='1',VAAK_SUPPORTER_CODES='1',VAAK_SUPPORTER_CODE_SECRET='isolated-supporter-secret-no-real-payments',TMPDIR=str(o/'tmp'))
(o/'env.json').write_text(__import__('json').dumps({k:v for k,v in env.items() if k.startswith(('AP_','VAAK_')) or k=='TMPDIR'}))
(a/'bootstrap.php').write_text("<?php require __DIR__.'/api/ap-registration-approvals.php';ap_auth_bootstrap();ap_registration_approvals_schema(ap_db());ap_supporter_schema(ap_db());ap_db()->prepare('UPDATE ap_users SET password_hash=? WHERE username=?')->execute([password_hash('fixture-admin-password',PASSWORD_DEFAULT),'cmdr_nova']); echo 'booted';")
subprocess.run([str(r/'api/bin/php-policy-test'),str(a/'bootstrap.php')],env=env,check=True)
(a/'router.php').write_text("<?php if(!in_array($_SERVER['REMOTE_ADDR']??'', ['127.0.0.1','::1'],true)){http_response_code(404);exit;} require __DIR__.'/vaak/index.php';")

manifest=[]
for d in ['api','vaak']:
 for p in (source/d).glob('*.php'):
  q=a/d/p.name
  manifest.append({'path':str(p.relative_to(source)),'original_sha256':__import__('hashlib').sha256(p.read_bytes()).hexdigest(),'copied_sha256':__import__('hashlib').sha256(q.read_bytes()).hexdigest(),'mode':oct(p.stat().st_mode&0o777),'adaptations':'literal storage/session/key/config path replacement only'})
(o/'copy-manifest.json').write_text(__import__('json').dumps(manifest,indent=2))
