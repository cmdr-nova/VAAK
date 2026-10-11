<?php
declare(strict_types=1);
/** Scoped actual status lookup; inherited fixture construction stays diagnostic. */
$genuineEntities=dirname(__DIR__).'/ap-masto-entities.php';$genuineSource=(string)file_get_contents($genuineEntities);
if(!preg_match('/^function ap_masto_lookup_status_by_object_url\(.*?^}/ms',$genuineSource,$genuineMatch))throw new RuntimeException('actual lookup declaration');
$genuineLookup=str_replace('function ap_masto_lookup_status_by_object_url(','function lp_genuine_current_lookup(',$genuineMatch[0]);
$genuineResolved='';foreach(token_get_all('<?php '.$genuineLookup) as $token)$genuineResolved.=is_array($token)&&$token[0]===T_DIR?var_export(dirname($genuineEntities),true):(is_array($token)&&$token[0]===T_FILE?var_export($genuineEntities,true):(is_array($token)?$token[1]:$token));eval(substr($genuineResolved,5));
function lp_setup_resolve(string $path,string $source):string{$result='';foreach(token_get_all($source) as $token)$result.=is_array($token)&&$token[0]===T_DIR?var_export(dirname($path),true):(is_array($token)?$token[1]:$token);return $result;}
if(getenv('LP_PROFILE_RECORD_QUOTE')==='1'){
 $quotePath=dirname(__DIR__).'/ap-admin.php';$quoteSource=(string)file_get_contents($quotePath);if(!preg_match('/^function admin_quote_card_html\(.*?^}/ms',$quoteSource,$quoteMatch))throw new RuntimeException('actual quote declaration');
 $quoteAlias=str_replace('function admin_quote_card_html(','function lp_genuine_current_quote_html(',$quoteMatch[0]);eval(substr(lp_setup_resolve($quotePath,'<?php '.$quoteAlias),5));
 function admin_quote_card_html(array $opts,string $view='home'):string {
  $entry=['opts'=>$opts,'view'=>$view];
  if(getenv('LP_PROFILE_RECORD_QUOTE_POLICY')==='1'){$key=admin_library_quote_policy_key((string)($opts['url']??''));$policy=$GLOBALS['vaak_quote_policy_statuses'][$key]??null;$entry['actual_policy_source']=$policy;$entry['actual_policy_owner']=admin_owner_user_id();$entry['actual_policy_allowed']=!is_array($policy)||(function_exists('ap_visibility_status_audience_allowed')&&ap_visibility_status_audience_allowed($policy,admin_owner_user_id()));}
  $html=lp_genuine_current_quote_html($opts,$view);if(getenv('LP_PROFILE_RECORD_QUOTE_POLICY')==='1'){$entry['actual_return_html']=$html;}
  if(!empty($GLOBALS['lp_genuine_lookup_active']))$GLOBALS['fixture_actual_quote_opts'][]=$entry;return $html;
 }
 $GLOBALS['lp_genuine_quote_declaration_sha256']=hash('sha256',$quoteMatch[0]);
}
$genuinePrefix='/tmp/vaak-profile-genuine-setup-'.getmypid();$genuineTemps=[];
$policyPath=__DIR__.'/remote-direct-policy-independent.php';$policy=(string)file_get_contents($policyPath);
$injection=<<<'PHP'
$transportDeclaration .= <<<'LOOKUP'
if(getenv('LP_PROFILE_POSITIVE_PREVIEW')==='1'){
 $transport=preg_replace_callback('/^function ap_link_preview_http_get\([^\n]*$/m',static fn($m)=>str_replace('return null;','return $GLOBALS[\'lp_positive_preview_http_body\'] ?? null;',$m[0]),$transport);
 $transport=str_replace("function ap_db_now(): string { return gmdate('c'); }","function ap_db_now(): string { return gmdate('c',lp_positive_preview_clock()); }",$transport);
}
$transport=preg_replace('/^function ap_masto_lookup_status_by_object_url\(.*?^}/ms', <<<'FUNCTION'
function ap_masto_lookup_status_by_object_url(string $url,int $owner=0,bool $fetch=false,bool $allowEmpty=false): ?array {
 if(empty($GLOBALS['lp_genuine_lookup_active'])){$q=ap_db()->prepare('SELECT payload FROM fixture_status_cache WHERE object_id=?');$q->execute([rtrim($url,'/')]);$raw=$q->fetchColumn();return is_string($raw)?json_decode($raw,true):null;}
 if($fetch)throw new RuntimeException('unsupported live lookup IO in genuine profile fixture');
 $entry=['url'=>$url,'depth'=>$owner,'allow_fetch'=>$fetch,'allow_hidden'=>$allowEmpty,'implementation'=>'actual ap-masto-entities declaration'];
 try{$result=lp_genuine_current_lookup($url,$owner,$fetch,$allowEmpty);$entry['returned_source']=$result;$GLOBALS['fixture_status_lookup_requests'][]=$entry;return $result;}
 catch(Throwable $error){$entry['error']=$error->getMessage();$GLOBALS['fixture_status_lookup_requests'][]=$entry;throw $error;}
}
FUNCTION
,$transport,1);
LOOKUP;
PHP;
$marker='$anchor=';if(strpos($policy,$marker)===false)throw new RuntimeException('policy transport hook boundary');$policy=str_replace($marker,$injection."\n".$marker,$policy);
$policyTmp=$genuinePrefix.'-policy.php';file_put_contents($policyTmp,lp_setup_resolve($policyPath,$policy));$genuineTemps[]=$policyTmp;
$libPath=__DIR__.'/library-search-boost-state-reference.php';$lib=(string)file_get_contents($libPath);$lib=str_replace("\$policyPath=__DIR__.'/remote-direct-policy-independent.php'",'$policyPath='.var_export($policyTmp,true),$lib);$libTmp=$genuinePrefix.'-library.php';file_put_contents($libTmp,lp_setup_resolve($libPath,$lib));$genuineTemps[]=$libTmp;
$fourPath=__DIR__.'/local-profile-four-tab-reference.php';$four=(string)file_get_contents($fourPath);$four=str_replace("\$lpSetupPath=__DIR__.'/library-search-boost-state-reference.php'",'$lpSetupPath='.var_export($libTmp,true),$four);$fourTmp=$genuinePrefix.'-four.php';file_put_contents($fourTmp,lp_setup_resolve($fourPath,$four));$genuineTemps[]=$fourTmp;
$hidePath=__DIR__.'/local-profile-hide-settings-reference.php';$hide=(string)file_get_contents($hidePath);$hide=str_replace("\$hideSetupPath=__DIR__.'/local-profile-four-tab-reference.php'",'$hideSetupPath='.var_export($fourTmp,true),$hide);$hideTmp=$genuinePrefix.'-hide.php';file_put_contents($hideTmp,lp_setup_resolve($hidePath,$hide));$genuineTemps[]=$hideTmp;
$boostPath=__DIR__.'/local-profile-boosts-reference.php';$boost=(string)file_get_contents($boostPath);$boost=str_replace("\$setupPath=__DIR__.'/local-profile-hide-settings-reference.php'",'$setupPath='.var_export($hideTmp,true),$boost);$end=strpos($boost,"\n".'$captures=[];');if($end===false)throw new RuntimeException('boost setup boundary');
try{eval(substr(lp_setup_resolve($boostPath,substr($boost,0,$end)),5));}finally{foreach($genuineTemps as $temporary)if(is_file($temporary))unlink($temporary);}
$GLOBALS['lp_genuine_lookup_active']=true;$GLOBALS['lp_genuine_lookup_declaration_sha256']=hash('sha256',$genuineMatch[0]);
$GLOBALS['fixture_production_semantic_substitutions']=array_values(array_diff($GLOBALS['fixture_production_semantic_substitutions'],['ap_masto_lookup_status_by_object_url']));
$GLOBALS['fixture_production_external_transports'][]='ap_masto_lookup_status_by_object_url recording wrapper; actual scoped lookup delegates unchanged, live fetch forbidden';
