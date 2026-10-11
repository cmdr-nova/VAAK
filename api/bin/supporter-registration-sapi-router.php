<?php
declare(strict_types=1);
/** Loopback-only extracted current controller/template; real HTTP/PHP session/PDO, declared delivery bootstrap. */
if(PHP_SAPI!=='cli-server'||!in_array($_SERVER['REMOTE_ADDR']??'',['127.0.0.1','::1'],true)){http_response_code(404);exit;}
$path=parse_url($_SERVER['REQUEST_URI'],PHP_URL_PATH);
if($path==='/api/ap-supporter.php'){$_SERVER['SCRIPT_FILENAME']=dirname(__DIR__).'/ap-supporter.php';require dirname(__DIR__).'/ap-supporter.php';exit;}
putenv('APPROVAL_REFERENCE_SETUP_ONLY=1');require __DIR__.'/registration-approvals-reference.php';approval_load(dirname(__DIR__).'/ap-registration-approvals.php','ap_registration_rate_admit');
$fixture=(string)getenv('SUPPORTER_SAPI_DB');if(!str_starts_with($fixture,'/tmp/supporter-sapi-'))throw new RuntimeException('isolated fixture path required');
$approval_db=new PDO('sqlite:'.$fixture);$approval_db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$approval_db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
session_name('supporter_fixture');session_save_path((string)getenv('SUPPORTER_SAPI_SESSIONS'));session_start();
$_SESSION[AP_AUTH_CSRF_KEY]??=bin2hex(random_bytes(24));$effects=[];
header('Cache-Control: no-store');
if($path==='/fixture-admin'){$_SESSION['vaak_user_id']=1;echo 'fixture session';exit;}
if($path==='/fixture-state'){
 header('Content-Type: application/json');echo json_encode(['accounts'=>(int)$approval_db->query('SELECT count(*) FROM ap_users')->fetchColumn(),'applications'=>$approval_db->query('SELECT username,status FROM ap_registration_applications ORDER BY id')->fetchAll(),'csrf'=>$_SESSION[AP_AUTH_CSRF_KEY],'effects'=>$effects]);exit;
}
if($path==='/approve'){
 $result=ap_registration_decide((int)($_POST['id']??0),(string)($_POST['decision']??'approved'),(string)($_POST['csrf']??''));header('Content-Type: application/json');echo json_encode($result);exit;
}
if($path==='/redeem'){
 $result=ap_supporter_redeem((string)($_POST['supporter_code']??''),(string)($_POST['csrf']??''));header('Content-Type: application/json');echo json_encode($result);exit;
}
$source=(string)file_get_contents(dirname(__DIR__,2).'/vaak/index.php');$start=strpos($source,"        \$application = ap_registration_approvals_enabled();");$end=strpos($source,"    } elseif (\$postAction === 'verify_2fa'",$start);$controller=substr($source,$start,$end-$start);
$marker="    <?php elseif (\$mode === 'register'): ?>";$start=strpos($source,$marker)+strlen($marker);$end=strpos($source,"    <?php else: ?>",$start);$template=substr($source,$start,$end-$start);
$csrf=$_SESSION[AP_AUTH_CSRF_KEY];$mode='register';$error='';$notice='';
if($_SERVER['REQUEST_METHOD']==='POST'){
 if(!ap_auth_csrf_ok((string)($_POST['csrf']??'')))$error='Session expired — try again.';
 else eval($controller);
}
echo '<!doctype html><html><body><p id="notice">'.htmlspecialchars($notice,ENT_QUOTES).'</p><p id="error">'.htmlspecialchars($error,ENT_QUOTES).'</p>';
eval('?>'.$template);echo '</body></html>';
