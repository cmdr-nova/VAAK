<?php
declare(strict_types=1);
/** Actual selected Announce normalizer, including its pre-account null cases. */
require __DIR__.'/local-profile-genuine-setup.php';
$input=json_decode((string)file_get_contents('/tmp/vaak-local-profile-shared-card-genuine-lookup-reference.json'),true,512,JSON_THROW_ON_ERROR);$captures=[];$schemas=[];
foreach(['INTEGER','BIGINT'] as $width){$schema='profile_event_reply_php_'.strtolower($width).'_'.getmypid();$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);try{
foreach($input['current_schema_by_width'][$width] as $table=>$columns){$defs=array_map(static fn($c)=>$c['column_name'].' '.$c['data_type'],$columns);$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');}$pg->exec('ALTER TABLE mentions ADD COLUMN created_at TEXT');$GLOBALS['fixture_db']=$pg;
foreach($pg->query('SELECT table_name,column_name,data_type FROM information_schema.columns WHERE table_schema=current_schema() ORDER BY table_name,ordinal_position')->fetchAll() as $column)$schemas[$width][$column['table_name']][]=['column_name'=>$column['column_name'],'data_type'=>$column['data_type']];
$seed=null;foreach($input['captures'] as $capture)if($capture['width']===$width&&$capture['case']==='own_remote_boost_public_boosts_owner2'){$seed=$capture;break;}if(!$seed)throw new RuntimeException('actual current Boost seed missing');
$cases=['cached_local'=>'plain reply','cached_event'=>'plain reply','cached_mention'=>'plain reply','missing_named'=>'plain reply','missing_unknown'=>'plain reply','deleted_parent'=>'plain reply','trailing'=>'plain reply','http'=>'plain reply','leading_space'=>'plain reply'];
if (($argv[2] ?? '') === 'mentions') $cases += ['reply_bare'=>'Hi @carol #reply','reply_full'=>'Hi @carol@parent.test #reply','reply_glue'=>'Hi @carolHello #reply','reply_two'=>'Hi @carol and @alice@remote.test #reply','reply_unknown'=>'Hi @unknownbare #reply','reply_lines'=>"Hi @carol\n#reply\n\nnext"];
foreach($cases as $case=>$body)foreach([1,2,0] as $owner){
foreach($seed['current_tables'] as $table=>$rows){$pg->exec('DELETE FROM '.$table);foreach($rows as $row)lp_insert_clone($pg,$table,$row);}
$parent='https://parent.test/users/carol/statuses/parent';
if($case==='cached_local')$parent=$seed['current_tables']['masto_statuses'][0]['note_id'];
if(in_array($case,['cached_event','deleted_parent'],true))lp_insert_clone($pg,'events',['id'=>3020,'object_id'=>$parent,'type'=>'Create','action_taken'=>$case==='deleted_parent'?'deleted':'log','visibility'=>'public','actor_id'=>'https://parent.test/users/carol','summary'=>'Parent body','created_at'=>'2026-10-10T11:00:00Z']);
if($case==='cached_mention')lp_insert_clone($pg,'mentions',['id'=>4100,'object_id'=>$parent,'actor_id'=>'https://parent.test/users/carol','created_at'=>'2026-10-10T11:00:00Z','deleted_at'=>null]);
if($case==='missing_unknown')$parent='https://parent.test/objects/unknown';
if($case==='trailing')$parent.='///';
if($case==='http')$parent='http://parent.test/objects/plain';
if($case==='leading_space')$parent=' '.$parent;
$pg->prepare("UPDATE events SET in_reply_to=? WHERE type='Announce'")->execute([$parent]);
$actor='https://mkultra.monster/users/fixture';$pg->prepare("UPDATE events SET summary=?,spoiler_text=? WHERE type='Announce'")->execute([$body,$case==='spoiler'?'  CW title  ':'']);

if ($case==='boundaries') lp_insert_clone($pg,'link_preview_cards',['url'=>'https://example.test/path','title'=>'Fixture title','description'=>'Fixture description','image'=>'','provider_name'=>'Example','provider_url'=>'https://example.test','type'=>'link','status'=>'ok','fetched_at'=>'2026-10-10T14:00:00Z','expires_at'=>'2099-01-01T00:00:00Z']);
direct_bind($owner);lpb_refresh_moderation();$GLOBALS['view']='remote_profile';$_GET=['actor'=>$actor,'tab'=>'boosts'];unset($GLOBALS['ap_masto_flag_memo']);foreach(['fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','fixture_status_lookup_requests','preview_http_requests','preview_warm_requests'] as $name)$GLOBALS[$name]=[];
$page=ap_local_profile_tab_page('fixture',$actor,'boosts',10,0);$before=boost_snapshot();$statuses=[];foreach($page['items'] as $row){$row['_skip_announce_hydrate']=true;$statuses[]=ap_normalize_from_activitypub_event($row,$actor);}
$effects=[];foreach(['fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','fixture_status_lookup_requests','preview_http_requests','preview_warm_requests'] as $name)$effects[$name]=$GLOBALS[$name];
$captures[]=['width'=>$width,'case'=>$case.'_owner'.$owner,'owner'=>$owner,'actor'=>$actor,'page'=>$page,'current_tables'=>$before,'statuses'=>$statuses,'effects'=>$effects];
}
}finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}}
$out=$argv[1]??'/tmp/vaak-profile-event-reply-reference.json';file_put_contents($out,json_encode(['captures'=>$captures,'current_schema_by_width'=>$schemas,'semantic_substitutions'=>$GLOBALS['fixture_production_semantic_substitutions'],'scope'=>'unchanged actual selector and event normalizer; inherited external recorders; no expected rewrite'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));echo count($captures)." actual event entity projection cases: $out\n";
