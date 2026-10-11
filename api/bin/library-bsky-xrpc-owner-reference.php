<?php
declare(strict_types=1);
/** Actual XRPC function body with namespaced curl/budget transport fixtures.
 * No sockets, credentials, account or provider mutations. Actual owner binding
 * and response policy helpers run unchanged against the isolated SQLite fixture.
 */
ob_start();require __DIR__.'/library-bsky-bookmark-owner-reference.php';ob_end_clean();
foreach(['AP_BSKY_DEFAULT_TIMEOUT'=>6,'AP_BSKY_CONNECT_TIMEOUT'=>3] as $key=>$value)if(!defined($key))define($key,$value);
foreach(['CURLOPT_RETURNTRANSFER','CURLOPT_FOLLOWLOCATION','CURLOPT_MAXREDIRS','CURLOPT_CONNECTTIMEOUT','CURLOPT_TIMEOUT','CURLOPT_HTTPHEADER','CURLOPT_CUSTOMREQUEST','CURLOPT_HEADERFUNCTION','CURLOPT_POSTFIELDS','CURLINFO_HTTP_CODE'] as $key)if(!defined($key))define($key,100+count(get_defined_constants()));
eval(<<<'CODE'
namespace BookmarkXrpcFixture;
function ap_bsky_budget_exceeded(){return $GLOBALS['xrpc_budget_denied']??false;}
function ap_bsky_effective_timeout($timeout){return $timeout;}
function ap_bsky_parse_rate_limit_headers($headers){return [];}
function curl_init($url){return new \stdClass();}
function curl_setopt_array($handle,$options){return true;}
function curl_setopt($handle,$key,$value){return true;}
function curl_exec($handle){return json_encode($GLOBALS['xrpc_response']);}
function curl_errno($handle){return 0;}
function curl_error($handle){return '';}
function curl_getinfo($handle,$key){return $GLOBALS['xrpc_status'];}
function curl_close($handle){}
CODE);
$tokens=token_get_all((string)file_get_contents(dirname(__DIR__).'/ap-bsky.php'));
for($i=0;$i<count($tokens);$i++){
 if(!is_array($tokens[$i])||$tokens[$i][0]!==T_FUNCTION)continue;
 $j=$i+1;while(is_array($tokens[$j])&&$tokens[$j][0]===T_WHITESPACE)$j++;
 if(!is_array($tokens[$j])||$tokens[$j][1]!=='ap_bsky_xrpc')continue;
 $code='';$depth=0;$body=false;for($k=$i;$k<count($tokens);$k++){$token=$tokens[$k];$code.=is_array($token)?$token[1]:$token;if(is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))$depth++;if($token==='{'){$depth++;$body=true;}if($token==='}')$depth--;if($body&&$depth===0)break;}
 eval('namespace BookmarkXrpcFixture;'.$code);break;
}
$cases=[];$token=ap_bsky_access_token(2)['access'];
foreach(['authenticated'=>[200,$token,'app.bsky.feed.getPosts',true], 'unauthed'=>[200,null,'app.bsky.feed.getPosts',false], 'failed'=>[401,$token,'app.bsky.feed.getPosts',false], 'record_response'=>[200,$token,'com.atproto.repo.getRecord',false]] as $name=>[$status,$access,$nsid,$expected]){
 unset($GLOBALS['vaak_bsky_bookmark_owner_state']);$GLOBALS['xrpc_status']=$status;$GLOBALS['xrpc_response']=['posts'=>[$post]];
 $result=BookmarkXrpcFixture\ap_bsky_xrpc('https://fixture.invalid',$nsid,'GET',null,null,$access);
 if(($result['ok']??false)!==($status===200))throw new RuntimeException('XRPC result changed '.$name);
 if((ap_bsky_bookmark_owner_state($uri,2)===true)!==$expected)throw new RuntimeException('XRPC owner provenance '.$name);
 $cases[]=$name;
}
unset($GLOBALS['vaak_bsky_bookmark_owner_state']);$GLOBALS['xrpc_budget_denied']=true;
$result=BookmarkXrpcFixture\ap_bsky_xrpc('https://fixture.invalid','app.bsky.feed.getPosts','GET',null,null,$token);
if(!empty($result['ok'])||ap_bsky_bookmark_owner_state($uri,2)!==null)throw new RuntimeException('budget error must not establish state');$cases[]='budget_error';
echo json_encode(['checks'=>count($cases),'cases'=>$cases,'transport'=>'namespaced curl and budget only','promotion_allowed'=>false],JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
