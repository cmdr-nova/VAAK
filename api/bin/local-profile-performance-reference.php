<?php
declare(strict_types=1);
/** Distinct genuine single-request mixed profile page, never rewrites older oracles. */
require __DIR__.'/local-profile-genuine-setup.php';
$width=$argv[1]??'';$order=$argv[2]??'';$owner=(int)($argv[3]??-1);$output=$argv[4]??'';
if(!in_array($width,['INTEGER','BIGINT'],true)||!in_array($order,['shared_raw','raw_shared'],true)||!in_array($owner,[0,2],true)||$output==='')throw new RuntimeException('fixture arguments');
$extent=(int)($argv[5]??0);if(!in_array($extent,[12,50],true))throw new RuntimeException('extent');
$input=json_decode((string)file_get_contents('/tmp/vaak-local-profile-card-after-own-boost-reference.json'),true,512,JSON_THROW_ON_ERROR);$capture=null;foreach($input['captures'] as $row)if($row['width']===$width&&$row['case']==='baseline'&&$row['owner']===$owner&&$row['tab']==='posts'){$capture=$row;break;}if(!$capture)throw new RuntimeException('genuine baseline missing');
$schema='profile_mixed_'.strtolower($width).'_'.getmypid();$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);try{
foreach($input['current_schema_by_width'][$width] as $table=>$columns){$definitions=array_map(static fn($column)=>$column['column_name'].' '.$column['data_type'],$columns);$pg->exec('CREATE TABLE '.$table.'('.implode(',',$definitions).')');}
$GLOBALS['fixture_db']=$pg;foreach($capture['current_tables'] as $table=>$rows)foreach($rows as $row)lp_insert_clone($pg,$table,$row);
$actor=$capture['actor'];$key=basename($actor);$note=null;$masto=null;
foreach($capture['current_tables']['outbox_notes'] as $row)if(str_ends_with($row['id'],'-3'))$note=$row;
foreach($capture['current_tables']['masto_statuses'] as $row)if(str_ends_with($row['note_id'],'-3'))$masto=$row;
if(!$note||!$masto)throw new RuntimeException('actual template');$pg->exec('DELETE FROM outbox_notes;DELETE FROM masto_statuses');
for($number=1;$number<=$extent+1;$number++){$id=$actor.'/notes/profile-perf-'.$number;$plain='Actual performance source '.$number;$published=gmdate('c',1791633720+$number*60);$row=$note;$row['id']=$id;$row['content']=$plain;$row['published']=$published;$row['raw_create_json']=json_encode(['type'=>'Create','object'=>['id'=>$id,'type'=>'Note','attributedTo'=>$actor,'content'=>$plain,'to'=>['https://www.w3.org/ns/activitystreams#Public']]],JSON_THROW_ON_ERROR);lp_insert_clone($pg,'outbox_notes',$row);if($number%2===0){$row=$masto;$row['note_id']=$id;$row['content_text']=$plain;$row['local_id']=800+$number;$row['published']=$published;lp_insert_clone($pg,'masto_statuses',$row);}}
// Real canonical media columns are added from production DDL, not invented url aliases.
$pg->exec('ALTER TABLE masto_media ADD COLUMN IF NOT EXISTS public_url TEXT; ALTER TABLE masto_media ADD COLUMN IF NOT EXISTS preview_url TEXT');
// Distinct current Ask rows repeat one public remote asker across shared/raw ordinals.
$askCapture=null;foreach($input['captures'] as $candidate)if($candidate['width']===$width&&$candidate['case']==='ask_current'&&$candidate['owner']===$owner){$askCapture=$candidate;break;}
if(!$askCapture)throw new RuntimeException('actual Ask template missing');
$pg->exec('DELETE FROM ap_asks');$template=$askCapture['current_tables']['ap_asks'][0];
foreach(range(1,$extent+1) as $number){$row=$template;$row['id']=900+$number;$row['ask_id']='https://asks.test/mixed-'.$number;$row['question']='Repeated remote Ask '.$number;$row['answer_note_id']=$actor.'/notes/profile-perf-'.$number;lp_insert_clone($pg,'ap_asks',$row);}
direct_bind($owner);lpb_refresh_moderation();unset($GLOBALS['ap_masto_flag_memo'],$GLOBALS['admin_masto_by_note']);$GLOBALS['view']='remote_profile';$GLOBALS['fixture_render_clock']=$capture['clock'];
foreach(['admin_status_link_preview_budget','admin_quote_link_preview_budget','admin_library_fetch_budget','admin_quote_fetch_budget'] as $budget)$GLOBALS[$budget]=0;
$GLOBALS['admin_status_link_preview_budget']=3;$GLOBALS['admin_quote_link_preview_budget']=2;
foreach(['preview_http_requests','preview_warm_requests','fixture_status_lookup_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','lp_headers'] as $name)$GLOBALS[$name]=[];
$GLOBALS['lp_calls']=['axum'=>[],'page'=>[],'shared'=>[],'raw'=>[],'event'=>[]];$_GET=['actor'=>$actor,'tab'=>'posts','limit'=>$extent,'offset'=>0];$before=boost_snapshot();$backend=(int)$pg->query('SELECT pg_backend_pid()')->fetchColumn();$tag='PHP_PROFILE_PERF_'.$width.'_'.$extent;
$pg->exec("SET log_statement='all'");$pg->query("SELECT '".$tag."_BEGIN'");$started=microtime(true);ob_start();$result=lp_actual_append([],$owner);$html=ob_get_clean();$elapsed=(microtime(true)-$started)*1000;$pg->query("SELECT '".$tag."_END'");$pg->exec("SET log_statement='none'");
$effects=[];foreach(['admin_status_link_preview_budget','admin_quote_link_preview_budget','preview_http_requests','preview_warm_requests','fixture_status_lookup_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests'] as $name)$effects[$name]=['present'=>array_key_exists($name,$GLOBALS),'type'=>get_debug_type($GLOBALS[$name]??null),'value'=>$GLOBALS[$name]??null];
$columns=[];foreach($pg->query('SELECT table_name,column_name,data_type FROM information_schema.columns WHERE table_schema=current_schema() ORDER BY table_name,ordinal_position')->fetchAll() as $column)$columns[$column['table_name']][]=['column_name'=>$column['column_name'],'data_type'=>$column['data_type']];
file_put_contents($output,json_encode(['width'=>$width,'case'=>$order.'_repeated_ask_'.$extent,'owner'=>$owner,'actor'=>$actor,'key'=>$key,'tab'=>'posts','clock'=>$capture['clock'],'limit'=>$extent,'offset'=>0,'elapsed_ms'=>$elapsed,'backend_pid'=>$backend,'log_tag'=>$tag,'initial_status_budget'=>3,'initial_quote_budget'=>2,'current_tables'=>$before,'current_schema'=>$columns,'page'=>$result['page']??null,'php'=>['html'=>$html,'effects'=>$effects,'headers'=>$GLOBALS['lp_headers'],'calls'=>$GLOBALS['lp_calls']],'after_tables'=>boost_snapshot(),'scope'=>'actual append12/50 one request repeated remote Ask, mixed shared/raw and positive status3/quote2; request timing excludes schema restore; per-backend SQL markers; original source order; declared external warming recorder and Redis unavailable; no outer header/HTTP'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));echo $output."\n";
}finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
