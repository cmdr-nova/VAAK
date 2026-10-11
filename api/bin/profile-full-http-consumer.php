<?php
declare(strict_types=1);
/** Actual full/shell dispatch snippet and current private HTTP helper. */
require dirname(__DIR__).'/ap-profile-native.php';
$capture=json_decode((string)file_get_contents($argv[1]),true,512,JSON_THROW_ON_ERROR);
function ap_auth_current_user():array{return ['id'=>2,'is_guest'=>false];}
function ap_auth_csrf_token():string{return 'fixture-csrf-token';}
$GLOBALS['vaak_guest_profile']=$capture['owner']===0;$GLOBALS['vaak_profile_shell']=$capture['shell'];$GLOBALS['admin_bookmark_lazy_media']=false;$vaakOwnerId=$capture['owner'];$_GET=['actor'=>$capture['actor']]+$capture['request'];
$admin=(string)file_get_contents(dirname(__DIR__).'/ap-admin.php');$start=strpos($admin,'          $rpNativeWhole = null;');$end=strpos($admin,'        <?php else: ?>',$start);if($start===false||$end===false)throw new RuntimeException('actual dispatch missing');$dispatch=substr($admin,$start,$end-$start).'<?php endif; ?>';
function full_state():array{$out=[];foreach(['admin_status_link_preview_budget','admin_quote_link_preview_budget']as $name)$out[$name]=['present'=>array_key_exists($name,$GLOBALS),'type'=>get_debug_type($GLOBALS[$name]??null),'value'=>$GLOBALS[$name]??null];return $out;}
$output=[];$modes=['integer0'=>0];if($capture['case']==='posts'&&$capture['owner']===2&&!$capture['shell'])$modes+=['absent'=>'__absent__','null'=>null,'bool'=>true,'numeric_string'=>'2','float'=>3.0,'array'=>[2]];
foreach($modes as $mode=>$raw){foreach(['admin_status_link_preview_budget','admin_quote_link_preview_budget']as $name){if($mode==='absent')unset($GLOBALS[$name]);else $GLOBALS[$name]=$raw;}$before=full_state();ob_start();eval($dispatch);$html=ob_get_clean();$after=full_state();if($rpNativeWhole===null||$GLOBALS['vaak_profile_full_fallback'])throw new RuntimeException('complete full consumer fell back');if($before!==$after)throw new RuntimeException('no-attempt raw state changed');$output[]=['mode'=>$mode,'html'=>$html,'reply'=>$rpNativeWhole,'before'=>$before,'after'=>$after];}
echo json_encode(['actual_dispatch_sha256'=>hash('sha256',$dispatch),'captures'=>$output],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
