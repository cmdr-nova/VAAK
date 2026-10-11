<?php
declare(strict_types=1);
/** Actual current selected local status projection with canonical body inputs. */
require __DIR__.'/local-profile-genuine-setup.php';
$input=json_decode((string)file_get_contents('/tmp/vaak-local-profile-shared-card-genuine-lookup-reference.json'),true,512,JSON_THROW_ON_ERROR);$captures=[];
$cases=['baseline'=>'body','missing_profile'=>'body','missing_user'=>'body','disabled_user'=>'body'];
foreach(['INTEGER','BIGINT'] as $width){$schema='profile_account_default_php_'.strtolower($width).'_'.getmypid();$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);try{
foreach($input['current_schema_by_width'][$width] as $table=>$columns){$defs=array_map(static fn($c)=>$c['column_name'].' '.$c['data_type'],$columns);$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');}$GLOBALS['fixture_db']=$pg;
$seed=null;foreach($input['captures'] as $row)if($row['width']===$width&&$row['case']==='baseline_posts_owner1'){$seed=$row;break;}if(!$seed)throw new RuntimeException('actual current local seed missing');
foreach($cases as $case=>$text)foreach([1,2,0] as $owner){
foreach($seed['current_tables'] as $table=>$rows){$pg->exec('DELETE FROM '.$table);foreach($rows as $row)lp_insert_clone($pg,$table,$row);}
if($case==='missing_profile')$pg->exec("DELETE FROM actor_profile WHERE actor_key='fixture'");
if($case==='missing_user')$pg->exec("DELETE FROM ap_users WHERE actor_key='fixture'");
if($case==='disabled_user')$pg->exec("UPDATE ap_users SET disabled_at='2026-10-10T12:00:00Z' WHERE actor_key='fixture'");
$note=$seed['selected_source']['id'];$html=ap_plain_text_to_html($text);$pg->prepare('UPDATE masto_statuses SET content_text=? WHERE note_id=?')->execute([$text,$note]);$pg->prepare('UPDATE outbox_notes SET content=? WHERE id=?')->execute([$html,$note]);
direct_bind($owner);lpb_refresh_moderation();$actor='https://mkultra.monster/users/fixture';$GLOBALS['view']='remote_profile';$_GET=['actor'=>$actor,'tab'=>'posts'];unset($GLOBALS['ap_masto_flag_memo']);foreach(['fixture_actor_warm_requests','fixture_media_warm_requests','fixture_status_lookup_requests'] as $name)$GLOBALS[$name]=[];
$page=ap_local_profile_tab_page('fixture',$actor,'posts',10,0);$before=boost_snapshot();$row=ap_masto_status_by_note_id($note);$source=ap_masto_status_from_row($row,true,false);$index=null;foreach($page['items'] as $i=>$item)if($item['id']===$note){$index=$i;break;}if($index===null)throw new RuntimeException('current canonical source not selected');
$effects=[];foreach(['fixture_actor_warm_requests','fixture_media_warm_requests','fixture_status_lookup_requests'] as $name)$effects[$name]=$GLOBALS[$name];$captures[]=['width'=>$width,'case'=>$case.'_owner'.$owner,'owner'=>$owner,'actor'=>$actor,'source_index'=>$index,'page'=>$page,'current_tables'=>$before,'source'=>$source,'effects'=>$effects];
}
}finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}}
$out=$argv[1]??'/tmp/vaak-profile-local-account-default-reference.json';file_put_contents($out,json_encode(['captures'=>$captures,'current_schema_by_width'=>$input['current_schema_by_width'],'semantic_substitutions'=>$GLOBALS['fixture_production_semantic_substitutions'],'scope'=>'actual current local projector; declared body/owner controls; preserves original source fixtures'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));echo count($captures)." actual canonical local projection cases: $out\n";
