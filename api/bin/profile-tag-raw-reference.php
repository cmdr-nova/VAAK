<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
putenv('LP_PROFILE_RECORD_QUOTE=1');require __DIR__.'/local-profile-genuine-setup.php';
$input=json_decode((string)file_get_contents(getenv('PROFILE_TAG_SEED')),true,512,JSON_THROW_ON_ERROR);$captures=[];
foreach(['INTEGER','BIGINT'] as $width){$schema='profile_tag_quote_'.strtolower($width).'_'.getmypid();$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);try{
 foreach($input['current_schema_by_width'][$width]as$table=>$columns){$defs=array_map(static fn($c)=>$c['column_name'].' '.$c['data_type'],$columns);$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');}
 $GLOBALS['fixture_db']=$pg;
 foreach(['quote_child_cw_posts_owner0','quote_parent_cw_posts_owner0','quote_private_child_posts_owner0','quote_deleted_child_posts_owner0']as$case){
  $seed=null;foreach($input['captures']as$r)if($r['width']===$width&&$r['case']===$case){$seed=$r;break;}if(!$seed)throw new RuntimeException('existing genuine local quote seed absent');
  foreach(['scalar','tag_list','tag_single','tag_rel_array']as$variant){
   foreach($seed['current_tables']as$table=>$rows){$pg->exec('DELETE FROM '.$table);foreach($rows as$row)lp_insert_clone($pg,$table,$row);}
   $source=$seed['source'];$raw=json_decode($source['raw_create_json'],true,512,JSON_THROW_ON_ERROR);$target=$raw['object']['quoteUrl'];
   if($variant!=='scalar'){unset($raw['object']['quoteUrl']);$tag=['type'=>'Link','rel'=>$variant==='tag_rel_array'?['alternate','https://w3id.org/fep/044f#quote']:'https://w3id.org/fep/044f#quote','href'=>$target];$raw['object']['tag']=$variant==='tag_single'?$tag:[$tag];$source['raw_create_json']=json_encode($raw,JSON_THROW_ON_ERROR);$pg->prepare('UPDATE outbox_notes SET raw_create_json=? WHERE id=?')->execute([$source['raw_create_json'],$source['id']]);}
   direct_bind(0);lpb_refresh_moderation();unset($GLOBALS['admin_masto_by_note'],$GLOBALS['ap_masto_flag_memo']);$GLOBALS['view']='remote_profile';$GLOBALS['fixture_render_clock']=$seed['context']['rendered_at'];$_GET=['actor'=>$seed['context']['actor'],'tab'=>'posts'];
   foreach(['admin_status_link_preview_budget','admin_quote_link_preview_budget','admin_library_fetch_budget','admin_quote_fetch_budget']as$name)$GLOBALS[$name]=0;
   foreach(['preview_http_requests','preview_warm_requests','fixture_status_lookup_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','fixture_actual_quote_opts']as$name)$GLOBALS[$name]=[];
   $before=boost_snapshot();ob_start();admin_render_outbox_card($source,'remote_profile');$html=ob_get_clean();
   $captures[]=['width'=>$width,'case'=>$case,'variant'=>$variant,'source'=>$source,'context'=>$seed['context'],'current_tables'=>$before,'after_tables'=>boost_snapshot(),'php'=>['html'=>$html,'actual_quote_opts'=>$GLOBALS['fixture_actual_quote_opts']],'pack'=>ap_quote_target_pack($raw['object'])];
  }
 }
}finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}}
file_put_contents($argv[1],json_encode(['captures'=>$captures,'current_schema_by_width'=>$input['current_schema_by_width'],'source_sha256'=>['admin'=>hash_file('sha256',dirname(__DIR__).'/ap-admin.php'),'inbox'=>hash_file('sha256',dirname(__DIR__).'/ap-inbox.php')],'scope'=>'actual current raw profile painter from retained genuine local source rows, only alternative quote-link encoding mutated durably; existing genuine lookup and offline transport bootstrap; complete auth/bootstrap/header and native HTTP separate'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));echo count($captures),' actual raw card captures',"\n";
