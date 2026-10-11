<?php
declare(strict_types=1);
$port=(int)getenv('PROFILE_PREVIEW_HTTP_PORT');if($port<1)throw new RuntimeException('owned fixture port required');
function fixture_dns(string $host):array{return $host==='private.test'?['10.0.0.2']:['8.8.8.8'];}
function fixture_curl(string $url){$ch=curl_init($url);curl_setopt($ch,CURLOPT_RESOLVE,['public.test:'.$GLOBALS['port'].':127.0.0.1','private.test:'.$GLOBALS['port'].':127.0.0.1']);curl_setopt($ch,CURLOPT_PROXY,'');return $ch;}
function ap_provider_circuit_allow(string $provider):bool{$GLOBALS['effects'][]=['kind'=>'allow','provider'=>$provider];return true;}
function ap_provider_circuit_success(string $provider):void{$GLOBALS['effects'][]=['kind'=>'success','provider'=>$provider];}
function ap_provider_circuit_failure(string $provider):void{$GLOBALS['effects'][]=['kind'=>'failure','provider'=>$provider];}
function ap_timing_record(string $name,float $ms):void{$GLOBALS['effects'][]=['kind'=>'timing','name'=>$name];}
$hashes=[];foreach(['ap_link_preview_normalize_url','ap_link_preview_url_allowed','ap_link_preview_resolve_redirect','ap_link_preview_http_get'] as $name){$source=(string)file_get_contents(dirname(__DIR__).'/ap-link-preview.php');if(!preg_match('/^function '.preg_quote($name,'/').'\(.*?^}/ms',$source,$m))throw new RuntimeException($name);$hashes[$name]=hash('sha256',$m[0]);$body=str_replace(['gethostbynamel(','curl_init('],['fixture_dns(','fixture_curl('],$m[0]);eval($body);}
$captures=[];foreach(['ok','empty','big','redirect-absolute','redirect-relative','loop','missing-location','private-redirect','fail500','429','ctype-json','exact-max','over-max','slow'] as $case){$GLOBALS['effects']=[];$url='http://public.test:'.$port.'/'.$case;$result=ap_link_preview_http_get($url,1,1024);$captures[]=['case'=>$case,'url'=>$url,'result'=>$result,'effects'=>$GLOBALS['effects']];}
$out=$argv[1]??'/tmp/vaak-profile-preview-http-reference.json';file_put_contents($out,json_encode(['captures'=>$captures,'declaration_sha256'=>$hashes,'port'=>$port,'scope'=>'Actual cURL request/body/redirect/timeout helper. DNS uses declared public/private answers and CURLOPT_RESOLVE routes public logical fixture hosts to owned loopback server; provider circuit/telemetry callbacks recorded separately. No production network.'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));echo count($captures).' actual cURL controls '.$out."\n";
