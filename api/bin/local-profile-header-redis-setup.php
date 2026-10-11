<?php
declare(strict_types=1);
/** Replace only the inherited unavailable Redis transport with declared map IO.
 * Actual ap-redis JSON helper declarations and all SQL/painter code stay intact.
 */
class HeaderFixtureRedis {
 public array $values=[];public array $effects=[];
 function get(string $key){$this->effects[]=['get'=>$key];return $this->values[$key]??false;}
 function del(...$keys):int{if(count($keys)===1&&is_array($keys[0]))$keys=$keys[0];$n=0;foreach($keys as $key){if(isset($this->values[$key])){$n++;unset($this->values[$key]);}}return $n;}
 function set(string $key,string $value,array $options):bool{$this->effects[]=['set'=>$key,'ttl'=>$options['ex']??null,'value'=>json_decode($value,true)];$this->values[$key]=$value;return true;}
}
$GLOBALS['header_fixture_redis']=new HeaderFixtureRedis();
$bootstrapPath=__DIR__.'/remote-direct-production-helper-bootstrap.php';$bootstrap=(string)file_get_contents($bootstrapPath);
$bootstrap=str_replace("function ap_redis_client(string \$purpose='cache'): ?Redis { \$GLOBALS['fixture_redis_connections'][]=\$purpose; return null; }","function ap_redis_client(string \$purpose='cache') { return !empty(\$GLOBALS['header_fixture_redis_active']) ? \$GLOBALS['header_fixture_redis'] : null; }",$bootstrap,$replaced);
if($replaced!==1)throw new RuntimeException('actual inherited Redis transport boundary');
$resolved='';foreach(token_get_all($bootstrap)as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(dirname($bootstrapPath),true):(is_array($token)?$token[1]:$token);
$temporary='/tmp/vaak-header-redis-bootstrap-'.getmypid().'.php';file_put_contents($temporary,$resolved);
$setupPath=__DIR__.'/local-profile-genuine-setup.php';$setup=(string)file_get_contents($setupPath);
$anchor='$policy=(string)file_get_contents($policyPath);';$patch=$anchor.'$policy=str_replace("__DIR__.\'/remote-direct-production-helper-bootstrap.php\'",'.var_export(var_export($temporary,true),true).',$policy);';
$setup=str_replace($anchor,$patch,$setup,$replaced);if($replaced!==1)throw new RuntimeException('actual genuine setup boundary');
$resolved='';foreach(token_get_all($setup)as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(dirname($setupPath),true):(is_array($token)?$token[1]:$token);
try{eval(substr($resolved,5));}finally{if(is_file($temporary))unlink($temporary);}

$GLOBALS['header_fixture_redis_active']=true;
