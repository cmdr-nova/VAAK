<?php
declare(strict_types=1);
/** Actual complete quoted-card OG parsing/cache writes with declared HTTP body and clock. */
function lp_positive_preview_clock():int{return (int)($GLOBALS['lp_positive_preview_clock']??time());}
$source=(string)file_get_contents(dirname(__DIR__).'/ap-link-preview.php');$declarations=[];
foreach(['ap_link_preview_cache_get','ap_link_preview_cache_put'] as $helper){if(!preg_match('/^function '.preg_quote($helper,'/').'\(.*?^}/ms',$source,$match))throw new RuntimeException($helper);$declarations[$helper]=hash('sha256',$match[0]);eval(str_replace('time()','lp_positive_preview_clock()',$match[0]));}
putenv('LP_PROFILE_POSITIVE_PREVIEW=1');putenv('LP_PROFILE_RECORD_QUOTE=1');require __DIR__.'/local-profile-genuine-setup.php';
$input=json_decode(file_get_contents('/tmp/vaak-local-profile-quote-url-budget-reference.json'),true,512,JSON_THROW_ON_ERROR);$captures=[];
foreach($input['captures'] as $case){if(!str_contains($case['case'],'cold')||$case['quote_budget_mode']!=='one')continue;$width=$case['width'];$schema='profile_positive_preview_'.getmypid().'_'.count($captures);$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);try{
 foreach($input['current_schema_by_width'][$width] as $table=>$columns){$defs=array_map(static fn($column)=>$column['column_name'].' '.$column['data_type'],$columns);$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');}
 // The genuine production cache key constraint is essential for real UPSERT.
 $pg->exec('ALTER TABLE link_preview_cards ADD PRIMARY KEY(url)');$GLOBALS['fixture_db']=$pg;
 foreach($case['current_tables'] as $table=>$rows)foreach($rows as $row)lp_insert_clone($pg,$table,$row);
 direct_bind($case['context']['owner']);lpb_refresh_moderation();$GLOBALS['view']='remote_profile';$_GET=['actor'=>$case['context']['get_actor'],'tab'=>'posts'];unset($GLOBALS['ap_masto_flag_memo']);
 $GLOBALS['fixture_render_clock']=$case['context']['rendered_at'];$GLOBALS['lp_positive_preview_clock']=$case['context']['rendered_at'];
 $GLOBALS['lp_positive_preview_http_body']='<meta property="og:title" content="Actual positive quoted article"><meta property="og:description" content="Quoted description"><meta property="og:image" content="https://8.8.8.8/quoted-preview.png"><meta property="og:site_name" content="Article fixture">';
 foreach(['admin_status_link_preview_budget','admin_quote_link_preview_budget','admin_library_fetch_budget','admin_quote_fetch_budget'] as $name)$GLOBALS[$name]=0;$GLOBALS['admin_quote_link_preview_budget']=1;
 foreach(['preview_http_requests','preview_warm_requests','fixture_actual_quote_opts','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','fixture_status_lookup_requests'] as $name)$GLOBALS[$name]=[];
 $before=boost_snapshot();ob_start();if(!admin_try_render_outbox_shared_card($case['selected_source'],[],'remote_profile'))throw new RuntimeException('genuine shared source disappeared');$html=ob_get_clean();
 $effects=[];foreach(['admin_status_link_preview_budget','admin_quote_link_preview_budget','preview_http_requests','preview_warm_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests'] as $name)$effects[$name]=$GLOBALS[$name];
 $captures[]= $case+['positive_http_body'=>$GLOBALS['lp_positive_preview_http_body'],'before_tables'=>$before,'after_tables'=>boost_snapshot(),'positive_php'=>['html'=>$html,'effects'=>$effects,'actual_quote_opts'=>$GLOBALS['fixture_actual_quote_opts']]];
}finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}}
$out=$argv[1]??'/tmp/vaak-profile-positive-quote-preview-reference.json';file_put_contents($out,json_encode(['captures'=>$captures,'current_schema_by_width'=>$input['current_schema_by_width'],'cache_key_constraint'=>'actual ap-db.php link_preview_cards.url PRIMARY KEY','cache_declaration_sha256'=>$declarations,'scope'=>'actual selected source/shared complete PHP card, actual parser/cache write; declared HTTP body and captured clock only; no native expected generation'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));echo count($captures).' positive quoted-card captures '.$out."\n";
