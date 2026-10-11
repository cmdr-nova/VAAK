<?php
declare(strict_types=1);
putenv('APPROVAL_REFERENCE_SETUP_ONLY=1');require __DIR__.'/registration-approvals-reference.php';require dirname(__DIR__).'/ap-admin-native.php';
approval_load(dirname(__DIR__).'/ap-auth.php','ap_auth_invites_list');approval_load(dirname(__DIR__).'/ap-admin.php','relative_time');
function h(string $value):string{return htmlspecialchars($value,ENT_QUOTES,'UTF-8');}
function ap_auth_csrf_token():string{return (string)($_SESSION[AP_AUTH_CSRF_KEY]??'');}
$schema=$argv[1];$view=$argv[2];$mode=$argv[3];if(!preg_match('/^admin_native_[a-z0-9_]+$/',$schema)||!in_array($view,['invites','approvals'],true)||!in_array($mode,['full','shell'],true))throw new RuntimeException('fixture args');
$approval_db=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres');$approval_db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$approval_db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$approval_db->exec('SET search_path TO '.$schema);
$_SESSION=['vaak_user_id'=>1,AP_AUTH_CSRF_KEY=>'private-session<&token'];$_GET=$mode==='shell'?['partial'=>'1']:[];
$source=file_get_contents(dirname(__DIR__).'/ap-admin.php');$next=$view==='invites'?'relays':'invites';$marker="      <?php elseif (\$view === '$view'): ?>";$start=strpos($source,$marker)+strlen($marker);$end=strpos($source,"      <?php elseif (\$view === '$next'): ?>",$start);$template=substr($source,$start,$end-$start);
putenv('VAAK_ADMIN_READ_NATIVE');ob_start();eval('?>'.$template);$reference=ob_get_clean();
$fault=$argv[4]??'';putenv('VAAK_ADMIN_READ_NATIVE=1');
if($fault==='default_off')putenv('VAAK_ADMIN_READ_NATIVE');if($fault==='short_secret')putenv('VAAK_ADMIN_READ_SECRET=short');if($fault==='unavailable')putenv('VAAK_ADMIN_READ_URL=http://127.0.0.1:18790/internal/admin/read');if($fault==='remote_url')putenv('VAAK_ADMIN_READ_URL=http://example.test/internal/admin/read');
$body=ap_admin_native_read_body($view);if(($body===null)!==($fault!==''))throw new RuntimeException('unexpected native/fallback admission');
ob_start();eval('?>'.$template);$native=ob_get_clean();if(($adminNativeBody===null)!==($fault!==''))throw new RuntimeException('unexpected actual dispatch fallback');
echo json_encode(['view'=>$view,'mode'=>$mode,'reference'=>$reference,'native'=>$native,'dispatch_sha256'=>hash('sha256',$template)],JSON_THROW_ON_ERROR);
