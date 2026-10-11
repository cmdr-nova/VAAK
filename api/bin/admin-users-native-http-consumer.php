<?php
/** Genuine Users PHP HTTP bridge and actual dispatch, no fallback accepted in positive mode. */
declare(strict_types=1);
putenv('APPROVAL_REFERENCE_SETUP_ONLY=1');require __DIR__.'/registration-approvals-reference.php';require dirname(__DIR__).'/ap-admin-native.php';
foreach(['ap_auth_users_list','ap_auth_users_count'] as $fn)approval_load(dirname(__DIR__).'/ap-auth.php',$fn);approval_load(dirname(__DIR__).'/ap-admin.php','relative_time');
function h(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function ap_auth_csrf_token():string{return (string)($_SESSION[AP_AUTH_CSRF_KEY]??'');}
$schema=$argv[1];$mode=$argv[2];$get=json_decode($argv[3],true,512,JSON_THROW_ON_ERROR);$post=json_decode($argv[4],true,512,JSON_THROW_ON_ERROR);$fault=$argv[5]??'';if(!preg_match('/^admin_users_native_[a-z0-9_]+$/',$schema)||!in_array($mode,['full','shell'],true))throw new RuntimeException('fixture args');
$approval_db=new PDO('pgsql:host='. (getenv('LIBRARY_FIXTURE_SOCKET')?:'/tmp/vaak-library-facts-pg-socket').' user=postgres dbname=postgres');$approval_db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$approval_db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$approval_db->exec('SET search_path TO '.$schema);
$_SESSION=['vaak_user_id'=>1,AP_AUTH_CSRF_KEY=>'private-session<&token'];$_GET=$get+($mode==='shell'?['partial'=>'1']:[]);$_POST=$post;$view='users';$vaakIsAdmin=ap_registration_current_admin(ap_db());
$source=file_get_contents(dirname(__DIR__).'/ap-admin.php');$marker="      <?php elseif (\$view === 'users'): ?>";$start=strpos($source,$marker)+strlen($marker);$end=strpos($source,"      <?php elseif (\$view === 'downranked'): ?>",$start);$template=substr($source,$start,$end-$start);
$reference='';if($fault!=='role_withdrawal'){putenv('VAAK_ADMIN_READ_NATIVE');ob_start();eval('?>'.$template);$reference=ob_get_clean();}putenv('VAAK_ADMIN_READ_NATIVE=1');
if($fault==='users_off')putenv('VAAK_ADMIN_USERS_NATIVE');if($fault==='default_off')putenv('VAAK_ADMIN_READ_NATIVE');if($fault==='short_secret')putenv('VAAK_ADMIN_READ_SECRET=short');if($fault==='unavailable')putenv('VAAK_ADMIN_READ_URL=http://127.0.0.1:18998/internal/admin/read');if($fault==='remote_url')putenv('VAAK_ADMIN_READ_URL=http://example.test/internal/admin/read');
$body=ap_admin_native_read_body('users');if(($body===null)!==($fault!==''))throw new RuntimeException('unexpected native/fallback admission');
ob_start();eval('?>'.$template);$native=ob_get_clean();if(($adminNativeBody===null)!==($fault!==''))throw new RuntimeException('actual dispatch admission');
echo json_encode(['mode'=>$mode,'reference'=>$reference,'native'=>$native,'dispatch_sha256'=>hash('sha256',$template)],JSON_THROW_ON_ERROR);
