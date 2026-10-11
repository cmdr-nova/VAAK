<?php
declare(strict_types=1);
/** Genuine declaration closure, original caller sources/clocks retained. */
$api=dirname(__DIR__);$plainHelpers=['actual production declaration closure'];
$argv[1]??='/tmp/vaak-root-preview-callers-production-reference.json';
if(!getenv('ROOT_PREVIEW_REPLAY_BASELINE')&&getenv('ROOT_CAPTURE_FRESH')!=='1')putenv('ROOT_PREVIEW_REPLAY_BASELINE=/tmp/vaak-root-preview-callers-after-reference.json');
putenv('DIRECT_PRODUCTION_HELPERS=1');
function root_production_resolve(string $path,string $source):string {$out='';foreach(token_get_all($source) as $token)$out.=is_array($token)&&$token[0]===T_DIR?var_export(dirname($path),true):(is_array($token)?$token[1]:$token);return $out;}
$path=__DIR__.'/root-preview-callers-reference.php';$code=(string)file_get_contents($path);
$start=strpos($code,'function ap_auth_current_user()');$end=strpos($code,"reference_load(\$api.'/ap-db.php',['ap_following_id_set'",$start);if($start===false||$end===false)throw new RuntimeException('actual caller setup boundary');
eval(substr(root_production_resolve($path,"<?php\n".substr($code,$start,$end-$start)),5));
$setupPath=__DIR__.'/remote-direct-production-setup.php';$setup=file_get_contents($setupPath);$policyPath=__DIR__.'/remote-direct-policy-independent.php';$policy=file_get_contents($policyPath);$prefix='/tmp/vaak-root-production-'.getmypid();
$policy=str_replace("'/tmp/vaak-remote-direct-policy-role-bootstrap.php'",var_export($prefix.'-role.php',true),$policy);$policy=str_replace("'/tmp/vaak-remote-profile-card-transport.php'",var_export($prefix.'-transport.php',true),$policy);$policyTmp=$prefix.'-policy.php';file_put_contents($policyTmp,root_production_resolve($policyPath,$policy));$setup=str_replace("__DIR__.'/remote-direct-policy-independent.php'",var_export($policyTmp,true),$setup);
try{eval(substr(root_production_resolve($setupPath,$setup),5));}finally{foreach([$policyTmp,$prefix.'-role.php',$prefix.'-transport.php'] as $tmp)if(is_file($tmp))unlink($tmp);}
// Session authority and ranked collector window transports are declared; full
// authentication/bootstrap/upstream FTS are outside this local caller fixture.
$GLOBALS['fixture_production_semantic_substitutions']=array_values(array_unique(array_merge($GLOBALS['fixture_production_semantic_substitutions'],['ap_auth_current_user','admin_search_run'])));unset($GLOBALS['fixture_production_helper_declarations']['ap_auth_current_user'],$GLOBALS['fixture_production_helper_declarations']['admin_search_run']);
$rootProductionCallerPath=__DIR__.'/root-preview-callers-reference.php';$rootProductionCallerCode=(string)file_get_contents($rootProductionCallerPath);$rootProductionCallerBegin=strpos($rootProductionCallerCode,"reference_load(\$api.'/ap-db.php',['ap_following_id_set'");if($rootProductionCallerBegin===false)throw new RuntimeException('genuine caller tail boundary');$tail=substr($rootProductionCallerCode,$rootProductionCallerBegin);
$tail=str_replace("\$before=serialize(\$sources);\$currentTables=root_reference_tables();","direct_production_prepare_moderation();\$before=serialize(\$sources);\$currentTables=root_reference_tables();",$tail);
$tail=str_replace("\$before=serialize(\$allowedWindow);\$grantedTables=root_reference_tables();","direct_production_prepare_moderation();\$before=serialize(\$allowedWindow);\$grantedTables=root_reference_tables();",$tail);
// Record declared external transports for each individual caller request.
// Genuine helper bodies and the original source/window remain unchanged.
$tail=str_replace('ob_start();$result=null;',"foreach(['fixture_status_lookup_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','fixture_redis_connections'] as \$rootEffectKey)\$GLOBALS[\$rootEffectKey]=[];ob_start();\$result=null;",$tail);
$tail=str_replace("'warm'=>\$GLOBALS['preview_warm_requests']", "'warm'=>\$GLOBALS['preview_warm_requests'],'lookup'=>\$GLOBALS['fixture_status_lookup_requests'],'actor_warm'=>\$GLOBALS['fixture_actor_warm_requests'],'media_warm'=>\$GLOBALS['fixture_media_warm_requests'],'quote_warm'=>\$GLOBALS['fixture_quote_warm_requests'],'redis_connections'=>\$GLOBALS['fixture_redis_connections']",$tail);
$tail=str_replace("'current_tables'=>\$currentTables", "'current_schema'=>root_production_schema(),'current_tables'=>\$currentTables",$tail);
$tail=str_replace("'current_tables'=>root_reference_tables()", "'current_schema'=>root_production_schema(),'current_tables'=>root_reference_tables()",$tail);
if(getenv('ROOT_EXTENDED_PREVIEW')==='1'){
 $GLOBALS['fixture_render_clock']=1791673200;
 $tail=preg_replace('/^\$variants=\[.*?];$/m',"\$variants=['youtube_plain','youtube_media','youtube_poll','youtube_supplied','own_youtube_current'];",$tail);
 $tail=preg_replace('/^\$entries=\[.*?];\$out=\[\];\$checks=0;$/m',"\$entries=['library_full_bookmarks','search_full','search_append'];\$out=[];\$checks=0;",$tail);
 $tail=str_replace("foreach([0,1,3,'unset','malformed'] as \$budget)","foreach([0,3,-1] as \$budget)",$tail);
 $tail=str_replace("\$variant==='poll'", "in_array(\$variant,['poll','youtube_poll'],true)",$tail);
 $tail=str_replace("foreach(['granted','revoked','public','revoked_no_url'] as \$mode)","foreach([] as \$mode)",$tail);
 $extra=<<<'PHP'
$source['content']='YouTube current body https://youtu.be/dQw4w9WgXcQ';
if($variant==='youtube_media')$source['media_attachments']=[['type'=>'image','url'=>'https://media.test/youtube.jpg','preview_url'=>'https://media.test/youtube.jpg']];
if($variant==='youtube_supplied')$source['card']=['url'=>'https://article.test/supplied','title'=>'Supplied YouTube precedence','description'=>'Actual supplied metadata','type'=>'link'];
if($variant==='own_youtube_current'){$source['content']='Cached own ordinary plain';$pg->prepare('UPDATE masto_statuses SET content_text=? WHERE note_id=?')->execute(['Current own YouTube https://youtu.be/dQw4w9WgXcQ',$source['uri']]);}
PHP;
 $tail=str_replace('$sources=[$source];',$extra."\n".'$sources=[$source];',$tail);
}
if(getenv('ROOT_FAULT_COHORT')==='1'){
 $tail=preg_replace('/^\$variants=\[.*?];$/m',"\$variants=['plain_no_url','ordered_supported_cold'];",$tail);
 $tail=preg_replace('/^\$entries=\[.*?];\$out=\[\];\$checks=0;$/m',"\$entries=['library_full_bookmarks','library_append','search_full','search_append'];\$out=[];\$checks=0;",$tail);
 $tail=str_replace("foreach(['granted','revoked','public','revoked_no_url'] as \$mode)","foreach([] as \$mode)",$tail);
}
if(getenv('ROOT_NATIVE_ACCEPT')==='1'){
 // Only this disposable fixture opts in. Production/default flags are untouched.
 $tail=str_replace("putenv('VAAK_LIBRARY_NATIVE_RENDER=0');putenv('VAAK_SEARCH_NATIVE_RENDER=0');", "putenv('VAAK_LIBRARY_NATIVE_RENDER=1');putenv('VAAK_SEARCH_NATIVE_RENDER=1');",$tail);
 $tail=str_replace("\$schema='root_preview_callers_'.strtolower(\$width).'_'.getmypid();", "\$schema='root_preview_native_'.strtolower(\$width);putenv('VAAK_LIBRARY_NATIVE_URL='.(getenv('ROOT_LIBRARY_URL_'.\$width)?:'http://127.0.0.1:18879/internal/library/render'));putenv('VAAK_SEARCH_NATIVE_URL='.(getenv('ROOT_SEARCH_URL_'.\$width)?:'http://127.0.0.1:18879/internal/search/render'));",$tail);
 $tail=str_replace('ob_start();$result=null;',"file_put_contents('/tmp/vaak-root-preview-fixture-clock-'.strtolower(\$width).'.json',json_encode(['clock'=>\$GLOBALS['fixture_render_clock']],JSON_THROW_ON_ERROR));ob_start();\$result=null;",$tail);
 $tail=str_replace('ob_start();$result=null;',"\$rootTraceStart=root_production_trace_offset(\$width);ob_start();\$result=null;",$tail);
 $tail=str_replace("'php'=>['html'=>\$html", "'native_trace'=>['path'=>getenv('ROOT_NATIVE_TRACE_'.\$width)?:null,'start'=>\$rootTraceStart,'end'=>root_production_trace_offset(\$width)],'php'=>['html'=>\$html",$tail);
}
$tail=str_replace("['native_parity'=>false,'checks'=>\$checks", "['native_parity'=>false,'production_helper_catalog'=>\$GLOBALS['fixture_production_helper_declarations'],'semantic_substitutions'=>\$GLOBALS['fixture_production_semantic_substitutions'],'cache_scope'=>'immutable administrator/global menu inputs; actual canonical moderation refresh per selected request; current SQL exported; session and collector transports declared','checks'=>\$checks",$tail);
eval(substr(root_production_resolve($rootProductionCallerPath,"<?php\n".$tail),5));
function root_production_schema():array {
 $schema=[];foreach(ap_db()->query("SELECT table_name,column_name,data_type FROM information_schema.columns WHERE table_schema=current_schema() ORDER BY table_name,ordinal_position")->fetchAll() as $column)$schema[$column['table_name']][]=['column_name'=>$column['column_name'],'data_type'=>$column['data_type']];return $schema;
}
function root_production_trace_offset(string $width):?int {
 $path=getenv('ROOT_NATIVE_TRACE_'.$width);if(!$path)return null;if(!str_starts_with($path,'/tmp/'))throw new RuntimeException('isolated trace only');clearstatcache(true,$path);return is_file($path)?filesize($path):0;
}
