<?php
declare(strict_types=1);
/** Actual selected Announce normalizer, including its pre-account null cases. */
require __DIR__.'/local-profile-genuine-setup.php';
$input=json_decode((string)file_get_contents('/tmp/vaak-local-profile-shared-card-genuine-lookup-reference.json'),true,512,JSON_THROW_ON_ERROR);$captures=[];
foreach(['INTEGER','BIGINT'] as $width){$schema='profile_event_moderation_php_'.strtolower($width).'_'.getmypid();$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);try{
foreach($input['current_schema_by_width'][$width] as $table=>$columns){$defs=array_map(static fn($c)=>$c['column_name'].' '.$c['data_type'],$columns);$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');}$GLOBALS['fixture_db']=$pg;
$seed=null;foreach($input['captures'] as $capture)if($capture['width']===$width&&$capture['case']==='own_remote_boost_public_boosts_owner2'){$seed=$capture;break;}if(!$seed)throw new RuntimeException('actual current Boost seed missing');
foreach(['baseline','server_mute_actor','server_mute_web_alias','server_mute_case','server_mute_domain','personal_mute','personal_mute_other_case','server_block','personal_block','personal_domain'] as $case)foreach([1,2,0] as $owner){
foreach($seed['current_tables'] as $table=>$rows){$pg->exec('DELETE FROM '.$table);foreach($rows as $row)lp_insert_clone($pg,$table,$row);}
$actor='https://mkultra.monster/users/fixture';$now='2026-10-10T15:00:00Z';
if(str_starts_with($case,'server_')){$scope=$case==='server_mute_domain'?'domain':'actor';$value=match($case){'server_mute_domain'=>'mkultra.monster','server_mute_web_alias'=>'https://mkultra.monster/@fixture','server_mute_case'=>'https://mkultra.monster/users/FIXTURE',default=>$actor};$kind=$case==='server_block'?'block':'mute';$pg->prepare('INSERT INTO ap_blocks(id,scope,value,kind,created_at) VALUES(?,?,?,?,?)')->execute([990,$scope,$value,$kind,$now]);}
if(str_starts_with($case,'personal_mute'))$pg->prepare('INSERT INTO ap_mutes(owner_user_id,actor_id,created_at) VALUES(?,?,?)')->execute([2,$case==='personal_mute_other_case'?'https://mkultra.monster/users/FIXTURE':$actor,$now]);
if(in_array($case,['personal_block','personal_domain'],true))$pg->prepare('INSERT INTO ap_user_blocks(id,owner_user_id,scope,value,created_at) VALUES(?,?,?,?,?)')->execute([990,2,$case==='personal_domain'?'domain':'actor',$case==='personal_domain'?'mkultra.monster':$actor,$now]);
direct_bind($owner);lpb_refresh_moderation();$GLOBALS['view']='remote_profile';$_GET=['actor'=>$actor,'tab'=>'boosts'];unset($GLOBALS['ap_masto_flag_memo']);foreach(['fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','fixture_status_lookup_requests'] as $name)$GLOBALS[$name]=[];
$page=ap_local_profile_tab_page('fixture',$actor,'boosts',10,0);$before=boost_snapshot();$statuses=[];foreach($page['items'] as $row){$row['_skip_announce_hydrate']=true;$statuses[]=ap_normalize_from_activitypub_event($row,$actor);}
$effects=[];foreach(['fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','fixture_status_lookup_requests'] as $name)$effects[$name]=$GLOBALS[$name];
$captures[]=['width'=>$width,'case'=>$case.'_owner'.$owner,'owner'=>$owner,'actor'=>$actor,'page'=>$page,'current_tables'=>$before,'statuses'=>$statuses,'effects'=>$effects];
}
}finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}}
$out=$argv[1]??'/tmp/vaak-profile-event-moderation-reference.json';file_put_contents($out,json_encode(['captures'=>$captures,'current_schema_by_width'=>$input['current_schema_by_width'],'semantic_substitutions'=>$GLOBALS['fixture_production_semantic_substitutions'],'scope'=>'unchanged actual selector and event normalizer; inherited external recorders; no expected rewrite'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));echo count($captures)." actual event normalizer cases: $out\n";
