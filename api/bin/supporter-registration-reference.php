<?php
declare(strict_types=1);
/** Actual application/account/reward declarations; delivery/session/Stripe boundaries declared. */
putenv('APPROVAL_REFERENCE_SETUP_ONLY=1');require __DIR__.'/registration-approvals-reference.php';
foreach (['ap_profile_badge_catalog','ap_profile_normalize_badges','ap_profile_supporter_awarded','ap_profile_authorized_badges'] as $fn) approval_load(dirname(__DIR__).'/ap-db.php',$fn);
$results=[];
foreach (['sqlite','pgsql-integer','pgsql-bigint'] as $driver) {
 if($driver!=='sqlite'&&!getenv('LIBRARY_FIXTURE_SOCKET'))continue;
 $checks=[];$effects=[];$sqlitePath=tempnam(sys_get_temp_dir(),'supporter-fixture-');
 $db=$driver==='sqlite'?new PDO('sqlite:'.$sqlitePath):new PDO('pgsql:host='.getenv('LIBRARY_FIXTURE_SOCKET').' port='.(getenv('LIBRARY_FIXTURE_PORT')?:5432).' user=postgres dbname=postgres');
 $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$GLOBALS['approval_db']=$db;
 $schema='supporter_reference_'.getmypid().'_'.str_replace('-','_',$driver);
 if($driver!=='sqlite'){$db->exec('CREATE SCHEMA '.$schema);$db->exec('SET search_path TO '.$schema);}
 try {
  $id=$driver==='sqlite'?'INTEGER PRIMARY KEY AUTOINCREMENT':($driver==='pgsql-integer'?'SERIAL PRIMARY KEY':'BIGSERIAL PRIMARY KEY');
  $db->exec("CREATE TABLE ap_users(id $id,username TEXT UNIQUE,email TEXT UNIQUE,password_hash TEXT,actor_key TEXT UNIQUE,actor_id TEXT,is_admin INTEGER DEFAULT 0,disabled_at TEXT,created_at TEXT,updated_at TEXT);
  CREATE TABLE actor_profile(actor_key TEXT PRIMARY KEY,name TEXT,summary TEXT,attachment_json TEXT,profile_badges TEXT DEFAULT '[]',updated_at TEXT);
  CREATE TABLE ap_invite_codes(id $id,code TEXT UNIQUE,used_by BIGINT,used_at TEXT,expires_at TEXT)");
  $db->exec("INSERT INTO ap_users(username,email,password_hash,actor_key,is_admin) VALUES('operator','operator@example.test','unused','operator',1)");
  $_SESSION=['vaak_user_id'=>1,AP_AUTH_CSRF_KEY=>'fixture-csrf'];
  putenv('VAAK_SUPPORTER_CODES');putenv('VAAK_REGISTRATION_APPROVALS=1');putenv('VAAK_SUPPORTER_CODE_SECRET='.str_repeat('fixture-secret-',4));
  check(!ap_supporter_issue('cus_fixture','ch_paid','paid@example.test')['ok'],'reward default off');
  putenv('VAAK_SUPPORTER_CODES=1');
  $issued=ap_supporter_issue('cus_fixture','ch_paid','paid@example.test');check($issued['ok'],'trusted verified receipt issues');$code=$issued['code'];
  check(ap_supporter_issue('cus_fixture','ch_other','paid@example.test')['code']===$code,'permanent customer issuance idempotent');
  check(!ap_supporter_issue('cus_fixture','ch_other','other@example.test')['ok'],'issuer identity cannot change');
  check(!ap_supporter_issue('bogus','ch_paid','paid@example.test')['ok'],'malformed customer rejected');
  check((int)$db->query('SELECT count(*) FROM ap_supporter_codes')->fetchColumn()===1,'one code per customer');
  $row=$db->query('SELECT * FROM ap_supporter_codes')->fetch();check(!in_array($code,$row,true)&&!in_array('paid@example.test',$row,true),'database stores no raw code email or receipt');
  check(!ap_registration_submit('forged','long-password','paid@example.test','fixture-csrf','LEGACY-CODE')['ok'],'legacy code not reward');
  check(!ap_registration_submit('wrongemail','long-password','other@example.test','fixture-csrf',$code)['ok'],'paid code bound to email');
  check(ap_registration_submit('paid','long-password','paid@example.test','fixture-csrf',$code)['ok'],'paid application pending');
  check((int)$db->query('SELECT count(*) FROM ap_users')->fetchColumn()===1&&count($effects)===0,'payment cannot bypass approval or provision');
  $app=(int)$db->query("SELECT id FROM ap_registration_applications WHERE username='paid'")->fetchColumn();
  check(!ap_registration_submit('paidagain','long-password','paid@example.test','fixture-csrf',$code)['ok'],'pending reward reservation unique');
  check(!array_key_exists('supporter_hash',ap_registration_applications_list()[0]),'admin model excludes reward hash');
  check(!ap_registration_decide($app,'approved','bad')['ok'],'decision csrf required');
  $db->exec('UPDATE ap_users SET is_admin=0 WHERE id=1');check(!ap_registration_decide($app,'approved','fixture-csrf')['ok'],'role withdrawn denied');$db->exec('UPDATE ap_users SET is_admin=1 WHERE id=1');
  check(ap_registration_decide($app,'approved','fixture-csrf')['ok'],'approve consumes reward');
  $uid=(int)$db->query("SELECT id FROM ap_users WHERE username='paid'")->fetchColumn();
  check(ap_profile_supporter_awarded('paid'),'paid account entitlement');
  check(ap_profile_authorized_badges('["supporter"]','paid')===['supporter'],'paid badge displayed');
  check(ap_profile_authorized_badges('["supporter","cat"]','operator')===['cat'],'forged legacy cached badge suppressed');
  check((int)$db->query("SELECT is_admin FROM ap_users WHERE username='paid'")->fetchColumn()===0,'supporter no role elevation');
  check($_SESSION['vaak_user_id']===1,'admin session unchanged');
  check(ap_registration_decide($app,'approved','fixture-csrf')['ok']&&count($effects)===3,'approval replay idempotent');
  check($db->query('SELECT supporter_hash FROM ap_registration_applications WHERE id='.$app)->fetchColumn()===null,'decided reward hash erased');
  $_SESSION['vaak_user_id']=$uid;check(ap_supporter_redeem($code,'fixture-csrf')['ok'],'same user redemption replay');
  check(!ap_supporter_redeem($code,'bad')['ok'],'redemption csrf required');
  $_SESSION['vaak_user_id']=1;check(!ap_supporter_redeem($code,'fixture-csrf')['ok'],'used reward not transferable');
  $code2=ap_supporter_issue('cus_second','ch_second','second@example.test')['code'];
  check(ap_registration_submit('second','long-password','second@example.test','fixture-csrf',$code2)['ok'],'second paid pending');
  $app2=(int)$db->query("SELECT id FROM ap_registration_applications WHERE username='second'")->fetchColumn();
  check(ap_registration_decide($app2,'rejected','fixture-csrf')['ok'],'reject');
  check(ap_registration_submit('secondnew','long-password','second@example.test','fixture-csrf',$code2)['ok'],'rejection releases unspent code');
  $code3=ap_supporter_issue('cus_operator','ch_operator','operator@example.test')['code'];
  check(ap_supporter_redeem($code3,'fixture-csrf')['ok']&&ap_profile_supporter_awarded('operator'),'future paying existing account redeems');
  $code4=ap_supporter_issue('cus_expired','ch_expired','expired@example.test')['code'];
  $db->prepare('UPDATE ap_supporter_codes SET expires_at=? WHERE code_hash=?')->execute(['2000-01-01 00:00:00',ap_supporter_code_hash($code4)]);
  check(!ap_registration_submit('expired','long-password','expired@example.test','fixture-csrf',$code4)['ok'],'expired code rejected');
  check(ap_registration_submit('ordinary','long-password','','fixture-csrf')['ok'],'unpaid public application allowed');
  $app3=(int)$db->query("SELECT id FROM ap_registration_applications WHERE username='ordinary'")->fetchColumn();check(ap_registration_decide($app3,'approved','fixture-csrf')['ok']&&!ap_profile_supporter_awarded('ordinary'),'unpaid approved no badge');
  $flair=['cat','dog','coffee','tea','bookworm','gamer','supporter'];check(count(ap_profile_normalize_badges($flair))===7&&ap_profile_normalize_badges($flair)[0]==='supporter','reward preserves six flair slots');
  $db->exec("INSERT INTO ap_invite_codes(code) VALUES('LEGACY-CODE')");putenv('VAAK_REGISTRATION_APPROVALS');check(ap_auth_register('LEGACY-CODE','legacy','long-password','legacy@example.test')['ok']&&!ap_profile_supporter_awarded('legacy'),'closed-mode legacy invite unchanged no badge');
  $results[$driver]=['passed'=>count($checks),'checks'=>$checks];
 }finally{if($db->inTransaction())$db->rollBack();if($driver!=='sqlite'){$db->exec('SET search_path TO public');$db->exec('DROP SCHEMA '.$schema.' CASCADE');}else{@unlink($sqlitePath);}}
}
echo json_encode(['results'=>$results,'declarations'=>$declarations,'boundaries'=>'Actual PDO declarations; session and postcommit key/follow/notification are declared fixtures. Issuance caller proof checked separately with actual Clearance receipt selector.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
