<?php
/** Genuine Users PHP selection/template before native implementation; owned PDO fixture only. */
declare(strict_types=1);
putenv('APPROVAL_REFERENCE_SETUP_ONLY=1');putenv('VAAK_ADMIN_READ_NATIVE');
require __DIR__.'/registration-approvals-reference.php';require dirname(__DIR__).'/ap-admin-native.php';
foreach(['ap_auth_users_list','ap_auth_users_count'] as $fn)approval_load(dirname(__DIR__).'/ap-auth.php',$fn);
approval_load(dirname(__DIR__).'/ap-admin.php','relative_time');
function h(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function ap_auth_csrf_token():string{return (string)($_SESSION[AP_AUTH_CSRF_KEY]??'');}
$source=file_get_contents(dirname(__DIR__).'/ap-admin.php');$marker="      <?php elseif (\$view === 'users'): ?>";$start=strpos($source,$marker)+strlen($marker);$end=strpos($source,"      <?php elseif (\$view === 'downranked'): ?>",$start);$template=substr($source,$start,$end-$start);
$driver=$argv[2]??'sqlite';$schema=$argv[3]??('admin_users_native_'.getmypid());if(!preg_match('/^admin_users_native_[a-z0-9_]+$/',$schema))throw new RuntimeException('fixture schema');
$approval_db=$driver==='sqlite'?new PDO('sqlite::memory:'):new PDO('pgsql:host='. (getenv('LIBRARY_FIXTURE_SOCKET')?:'/tmp/vaak-library-facts-pg-socket').' user=postgres dbname=postgres');
$approval_db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$approval_db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
if($driver!=='sqlite')$approval_db->exec('CREATE SCHEMA '.$schema.';SET search_path TO '.$schema);
$id=$driver==='pgsql-bigint'?'BIGINT':'INTEGER';
$approval_db->exec("CREATE TABLE ap_users(id $id PRIMARY KEY,username TEXT,email TEXT,password_hash TEXT,actor_key TEXT,actor_id TEXT,is_admin INTEGER,created_at TEXT,updated_at TEXT,disabled_at TEXT);CREATE TABLE actor_profile(actor_key TEXT PRIMARY KEY,name TEXT,icon_url TEXT)");
$u=$approval_db->prepare('INSERT INTO ap_users VALUES(?,?,?,?,?,?,?,?,?,?)');$p=$approval_db->prepare('INSERT INTO actor_profile VALUES(?,?,?)');
for($i=1;$i<=42;$i++){
 $key=$i===1?'cmdr_nova':($i===3?'odd<&"~':'person_'.$i);$name=$i===4?'0':($i===5?'':'Display <&"é '.$i);$icon=$i===4?'0':($i===5?'':'https://img.test/'.$i.'?x=<&"');
 $u->execute([$i,$i===3?'User<&"é':'user_'.$i,$i===6?null:'mail_'.$i.'@example.test','NEVER_RETURN_HASH',$key,'https://local.test/users/'.$key,$i<=2?1:0,'2026-10-10 12:00:00','2026-10-10 12:00:00',$i===7?'0':($i%3===0?'2026-10-10 13:00:00':null)]);
 if($i!==6)$p->execute([$key,$name,$icon]);
}
$cases=[['first',[],[]],['middle',['page'=>'2'],[]],['last',['page'=>'3'],[]],['clamp',['page'=>'999'],[]],['negative',['page'=>'-3'],[]],['get_search',['q'=>'Display <&"é 3'],[]],['email',['q'=>'mail_6'],[]],['actor_key',['q'=>'person_8'],[]],['username',['q'=>'User<&"é'],[]],['wildcard',['q'=>'%'],[]],['underscore',['q'=>'_'],[]],['zero',['q'=>'0'],[]],['trim',['q'=>" \t user_1 \n"],[]],['empty',['q'=>'absent'],[]],['post',[],['users_q'=>'person','users_page'=>'2']],['get_precedence',['q'=>'user_2','page'=>'1'],['users_q'=>'absent','users_page'=>'3']],['disabled_operator',['q'=>'mail_2@'],[]]];
$checks=[];$results=[];$before=hash('sha256',json_encode([$approval_db->query('SELECT * FROM ap_users ORDER BY id')->fetchAll(),$approval_db->query('SELECT * FROM actor_profile ORDER BY actor_key')->fetchAll()]));
foreach(['full','shell'] as $mode)foreach($cases as [$case,$get,$post]){
 if($case==='disabled_operator')$approval_db->exec("UPDATE ap_users SET disabled_at='2026-10-10 13:00:00' WHERE id=2");
 $_SESSION=['vaak_user_id'=>1,AP_AUTH_CSRF_KEY=>'private-session<&token'];$_GET=$get+($mode==='shell'?['partial'=>'1']:[]);$_POST=$post;$view='users';$vaakIsAdmin=ap_registration_current_admin(ap_db());$now=time();ob_start();eval('?>'.$template);$html=ob_get_clean();
 check(!str_contains($html,'NEVER_RETURN_HASH'),'hash absent '.$mode.$case);check(!str_contains($html,'Admin only.'),'admin admitted '.$mode.$case);
 $results[]=['case'=>$case,'mode'=>$mode,'get'=>$get,'post'=>$post,'request'=>['protocol_version'=>1,'owner_id'=>1,'csrf'=>ap_auth_csrf_token(),'view'=>'users','mode'=>$mode,'offset'=>max(1,(int)($get['page']??$post['users_page']??1)),'search'=>trim((string)($get['q']??$post['users_q']??'')),'approvals_enabled'=>false,'now'=>$now,'php_timezone'=>date_default_timezone_get()],'html'=>$html,'rows'=>$localUsers,'total'=>$usersTotal,'page'=>$usersPage,'pages'=>$usersPages];
 if($case==='disabled_operator')$approval_db->exec('UPDATE ap_users SET disabled_at=NULL WHERE id=2');
}
foreach(['ordinary','guest','revoked','disabled'] as $case){$_GET=[];$_POST=[];$_SESSION=['vaak_user_id'=>$case==='ordinary'?2:($case==='guest'?0:1),AP_AUTH_CSRF_KEY=>'token'];if($case==='ordinary')$approval_db->exec('UPDATE ap_users SET is_admin=0 WHERE id=2');if($case==='revoked')$approval_db->exec('UPDATE ap_users SET is_admin=0 WHERE id=1');if($case==='disabled')$approval_db->exec("UPDATE ap_users SET is_admin=1,disabled_at='now' WHERE id=1");$vaakIsAdmin=ap_registration_current_admin(ap_db());ob_start();eval('?>'.$template);$html=ob_get_clean();check(str_contains($html,'Admin only.'),'current role denial '.$case);$results[]=['case'=>$case,'mode'=>'full','html'=>$html];}
$approval_db->exec('UPDATE ap_users SET is_admin=1,disabled_at=NULL WHERE id=1;UPDATE ap_users SET is_admin=1 WHERE id=2');
check($before===hash('sha256',json_encode([$approval_db->query('SELECT * FROM ap_users ORDER BY id')->fetchAll(),$approval_db->query('SELECT * FROM actor_profile ORDER BY actor_key')->fetchAll()])),'read no data writes');
file_put_contents($argv[1]??'/tmp/admin-users-native-reference.json',json_encode(['driver'=>$driver,'schema'=>$schema,'declarations'=>$declarations,'template_sha256'=>hash('sha256',$template),'checks'=>$checks,'results'=>$results,'boundary'=>'Actual extracted Users branch and unchanged actual count/list/role/relative_time declarations with real PDO and declared session/escaping bootstrap. Not whole SAPI controller. PG schemas retained solely for native replay.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));echo json_encode(['passed'=>count($checks),'cases'=>count($results),'driver'=>$driver]).PHP_EOL;
