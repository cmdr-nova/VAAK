<?php
/** Genuine PHP page branches against real PDO; native remains disabled for reference. */
declare(strict_types=1);
putenv('APPROVAL_REFERENCE_SETUP_ONLY=1');putenv('VAAK_ADMIN_READ_NATIVE');
require __DIR__.'/registration-approvals-reference.php';require dirname(__DIR__).'/ap-admin-native.php';
approval_load(dirname(__DIR__).'/ap-auth.php','ap_auth_invites_list');
approval_load(dirname(__DIR__).'/ap-admin.php','relative_time');
function h(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function ap_auth_csrf_token():string{return (string)($_SESSION[AP_AUTH_CSRF_KEY]??'');}
$source=file_get_contents(dirname(__DIR__).'/ap-admin.php');$templates=[];
foreach(['invites'=>'relays','approvals'=>'invites'] as $view=>$next){$marker="      <?php elseif (\$view === '$view'): ?>";$start=strpos($source,$marker)+strlen($marker);$end=strpos($source,"      <?php elseif (\$view === '$next'): ?>",$start);$templates[$view]=substr($source,$start,$end-$start);}
$driver=$argv[2]??'sqlite';$schema=$argv[3]??('admin_native_reference_'.getmypid());
if(!preg_match('/^admin_native_[a-z0-9_]+$/',$schema))throw new RuntimeException('fixture schema');
$approval_db=$driver==='sqlite'?new PDO('sqlite::memory:'):new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres');
$approval_db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$approval_db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
if($driver!=='sqlite'){$approval_db->exec('CREATE SCHEMA '.$schema);$approval_db->exec('SET search_path TO '.$schema);}
$id=$driver==='pgsql-bigint'?'BIGINT':'INTEGER';
$approval_db->exec("CREATE TABLE ap_users(id $id PRIMARY KEY,username TEXT,is_admin INTEGER,disabled_at TEXT);INSERT INTO ap_users VALUES(1,'operator',1,NULL),(2,'ordinary',0,NULL);
CREATE TABLE ap_invite_codes(id $id PRIMARY KEY,code TEXT,created_at TEXT,used_at TEXT,note TEXT);
CREATE TABLE ap_registration_applications(id $id PRIMARY KEY,username TEXT,email TEXT,status TEXT,created_at TEXT,decided_at TEXT,password_hash TEXT)");
$stamp='2026-10-10 12:00:00';$insert=$approval_db->prepare('INSERT INTO ap_invite_codes VALUES(?,?,?,?,?)');
foreach([[1,'unused & <"é~',$stamp,null,'note <script>'],[2,'used',$stamp,$stamp,'0'],[3,'zero',$stamp,'0','']] as $row)$insert->execute($row);
$insert=$approval_db->prepare('INSERT INTO ap_registration_applications VALUES(?,?,?,?,?,?,?)');
for($i=1;$i<=102;$i++)$insert->execute([$i,'app_'.$i,($i%2)?'private_'.$i.'@example.test':null,($i%3)?'pending':(($i%2)?'rejected':'approved'),$stamp,null,'NEVER_RETURN_HASH']);
$results=[];$checks=[];
foreach(['invites','approvals'] as $view)foreach(['full','shell'] as $mode)foreach(['active','closed','ordinary','guest','revoked','disabled'] as $case){
 $_SESSION=['vaak_user_id'=>1,AP_AUTH_CSRF_KEY=>'private-session<&token'];$_GET=$mode==='shell'?['partial'=>'1']:[];
 $approval_db->exec('UPDATE ap_users SET is_admin=1,disabled_at=NULL WHERE id=1');putenv('VAAK_REGISTRATION_APPROVALS=1');
 if($case==='closed')putenv('VAAK_REGISTRATION_APPROVALS');if($case==='ordinary')$_SESSION['vaak_user_id']=2;if($case==='guest')$_SESSION['vaak_user_id']=0;
 if($case==='revoked')$approval_db->exec('UPDATE ap_users SET is_admin=0 WHERE id=1');if($case==='disabled')$approval_db->exec("UPDATE ap_users SET disabled_at='now' WHERE id=1");
 $before=$approval_db->query('SELECT count(*) FROM ap_users')->fetchColumn();$now=time();ob_start();eval('?>'.$templates[$view]);$html=ob_get_clean();
 $private=in_array($case,['active','closed'],true);
 check(!str_contains($html,'NEVER_RETURN_HASH'),'hash absent '.$view.$mode.$case);
 check(str_contains($html,'Admin only.')===!$private,'current role '.$view.$mode.$case);
 check($before===$approval_db->query('SELECT count(*) FROM ap_users')->fetchColumn(),'read no account writes '.$view.$mode.$case);
 $results[]=['view'=>$view,'mode'=>$mode,'case'=>$case,'request'=>['protocol_version'=>1,'owner_id'=>(int)$_SESSION['vaak_user_id'],'csrf'=>ap_auth_csrf_token(),'view'=>$view,'mode'=>$mode,'offset'=>0,'approvals_enabled'=>ap_registration_approvals_enabled(),'now'=>$now,'php_timezone'=>date_default_timezone_get()],'html'=>$html];
}
$approval_db->exec('UPDATE ap_users SET is_admin=1,disabled_at=NULL WHERE id=1');$_SESSION=['vaak_user_id'=>1,AP_AUTH_CSRF_KEY=>'private-session<&token'];putenv('VAAK_REGISTRATION_APPROVALS=1');
foreach([100,200] as $offset){$_GET=['approval_offset'=>$offset];$view='approvals';ob_start();eval('?>'.$templates[$view]);$html=ob_get_clean();check(str_contains($html,'Previous'),'actual previous cursor '.$offset);check(!str_contains($html,'NEVER_RETURN_HASH'),'cursor hash absent '.$offset);$results[]=['view'=>$view,'mode'=>'full','case'=>'offset','request'=>['protocol_version'=>1,'owner_id'=>1,'csrf'=>ap_auth_csrf_token(),'view'=>$view,'mode'=>'full','offset'=>$offset,'approvals_enabled'=>true,'now'=>time(),'php_timezone'=>date_default_timezone_get()],'html'=>$html];}
file_put_contents($argv[1]??'/tmp/admin-native-reference.json',json_encode(['driver'=>$driver,'schema'=>$schema,'declarations'=>$declarations,'template_hashes'=>array_map(fn($s)=>hash('sha256',$s),$templates),'checks'=>$checks,'results'=>$results,'boundary'=>'Actual extracted PHP branches/declarations and PDO. Fixture session/escaping bootstrap; not complete HTTP controller. PG schemas retained solely for matched native replay.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['passed'=>count($checks),'cases'=>count($results),'driver'=>$driver]).PHP_EOL;
