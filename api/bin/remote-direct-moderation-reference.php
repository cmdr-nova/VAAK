<?php
declare(strict_types=1);
/** Actual direct painter + selected controller moderation reference, no native. */
$path=__DIR__.'/remote-direct-card-reference.php';$code=(string)file_get_contents($path);
$end=strpos($code,"\n\$rows=[];\$checks=0;");if($end===false)throw new RuntimeException('reference setup boundary');
$resolved='';foreach(token_get_all(substr($code,0,$end)) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);eval(substr($resolved,5));
$admin=(string)file_get_contents(dirname(__DIR__).'/ap-admin.php');
$start=strpos($admin,"          \$stAuthor = '';",strpos($admin,"elseif (\$view === 'status')"));
$block=substr($admin,$start,strpos($admin,'?>',$start)-$start);
if($start===false||!str_contains($block,'$stContentBlocked'))throw new RuntimeException('actual detail root block region');
$rows=[];$checks=0;
$repaired=in_array('--expect-repaired-partial-blocks',$argv,true);
$moderationCases=[];
foreach(['plain','quote_supplied_media','boost'] as $shape){
 $case=$cases[$shape];
 if($shape!=='plain'){
  if($shape==='boost')$child=&$case['status']['reblog'];else $child=&$case['status']['quote']['quoted_status'];
  $child['uri']=$child['url']='https://nested-direct.test/notes/'.$shape;
  $child['account']=['id'=>'https://nested-direct.test/users/bob','uri'=>'https://nested-direct.test/users/bob','url'=>'https://nested-direct.test/@bob','acct'=>'bob@nested-direct.test','display_name'=>'Distinct nested Bob'];
  $case['context']['audience_events'][]=['id'=>20000,'object_id'=>$child['uri'],'type'=>'Create','action_taken'=>'log','visibility'=>'public','actor_id'=>$child['account']['uri']];unset($child);
 }
 $moderationCases[$shape]=$case;
}
foreach(['INTEGER','BIGINT'] as $width){
 $schema='remote_direct_moderation_'.strtolower($width);$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{
  foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}
  $GLOBALS['fixture_db']=$pg;
  foreach(['plain','quote_supplied_media','boost'] as $shape)foreach(['none','personal_block','server_block','personal_mute','server_mute','word'] as $rule)foreach(['profile','focus_home','focus_search','focus_bookmarks','focus_favourites','ancestor_home'] as $mode){
   $case=$moderationCases[$shape];$status=$case['status'];$options=$modes[$mode];caller_restore($case['context']);direct_bind(1);$_GET=[];$GLOBALS['view']=$options['route'];$GLOBALS['admin_bookmark_lazy_media']=false;
   $target=$shape==='quote_supplied_media'?$status['quote']['quoted_status']:($shape==='boost'?$status['reblog']:$status);
   $actor=$target['account']['uri'];$word=admin_html_to_plain((string)$target['content']);
   if($rule==='personal_block')$pg->prepare('INSERT INTO fixture_blocks VALUES(?,?,?)')->execute([1,'actor',$actor]);
   if($rule==='personal_mute')$pg->prepare('INSERT INTO fixture_mutes VALUES(?,?)')->execute([1,$actor]);
   if($rule==='word')$pg->prepare('INSERT INTO fixture_words VALUES(?,?)')->execute([1,ap_muted_words_normalize_haystack($word)]);
   if(str_starts_with($rule,'server_'))$pg->prepare('INSERT INTO fixture_server_blocks VALUES(?,?,?)')->execute(['actor',$actor,$rule==='server_block'?'block':'mute']);
   // Exact real full-detail root-block region; supplied complete source, not
   // a claim that every profile/event/detail provider constructs this shape.
   $stLocalStatus=$status;$stEvent=null;eval($block);
   $painter=caller_capture(static function()use($status,$options){admin_render_masto_status_card($status,[],$options['return_view'],$options['focused'],true);});
   $allowed=admin_direct_status_allowed($status,1);
   if(!$allowed)throw new RuntimeException('moderation altered direct audience/source gate');$checks++;
   $targetBlocked=ap_actor_is_content_blocked($actor,null,1);
   if($targetBlocked!==in_array($rule,['personal_block','server_block'],true))throw new RuntimeException('real content-block classification');$checks++;
   $rows[]=['width'=>$width,'shape'=>$shape,'rule'=>$rule,'mode'=>$mode,'source'=>$status,'authoritative_context'=>$case['context'],'current_fixture_rules'=>['personal_blocks'=>$pg->query('SELECT * FROM fixture_blocks')->fetchAll(),'personal_mutes'=>$pg->query('SELECT * FROM fixture_mutes')->fetchAll(),'server'=>$pg->query('SELECT * FROM fixture_server_blocks')->fetchAll(),'words'=>$pg->query('SELECT * FROM fixture_words')->fetchAll()],'options'=>$options,'rule_actor'=>$actor,'word'=>$word,'current_target_content_blocked'=>$targetBlocked,'direct_gate_allowed'=>$allowed,'selected_actual_detail_root_blocked'=>$stContentBlocked,'selected_detail_block_sha256'=>hash('sha256',$block),'painter'=>$painter,'painter_target_body_visible'=>str_contains($painter['html'],$word),'painter_article'=>str_contains($painter['html'],'<article'),'controller_then_painter_html'=>$stContentBlocked?'':$painter['html']];
  }
  foreach(['none','personal_block','server_block','personal_domain_block','server_domain_block','personal_mute','server_mute','server_domain_mute','word'] as $rule){
   $status=direct_source($corpus['status_plain']['context']);direct_bind(1);$actor=$status['account']['uri'];
   if($rule==='personal_block')$pg->prepare('INSERT INTO fixture_blocks VALUES(?,?,?)')->execute([1,'actor',$actor]);
   if($rule==='personal_mute')$pg->prepare('INSERT INTO fixture_mutes VALUES(?,?)')->execute([1,$actor]);
   if($rule==='personal_domain_block')$pg->prepare('INSERT INTO fixture_blocks VALUES(?,?,?)')->execute([1,'domain','mkultra.monster']);
   if($rule==='word')$pg->prepare('INSERT INTO fixture_words VALUES(?,?)')->execute([1,ap_muted_words_normalize_haystack('Direct policy marker')]);
   if(str_starts_with($rule,'server_'))$pg->prepare('INSERT INTO fixture_server_blocks VALUES(?,?,?)')->execute([str_contains($rule,'domain')?'domain':'actor',str_contains($rule,'domain')?'mkultra.monster':$actor,str_ends_with($rule,'block')?'block':'mute']);
   $full=direct_controller_capture($status['uri']);$partials=[];
   foreach(['focus','ancestor','descendant'] as $slot)$partials[$slot]=direct_partial_dispatch($full['source'],$slot);
   $expectedBlocked=in_array($rule,['personal_block','server_block','personal_domain_block','server_domain_block'],true);
   if($full['content_blocked']!==$expectedBlocked||str_contains($full['html'],'Direct policy marker')===$expectedBlocked)throw new RuntimeException('actual full block root behavior');$checks++;
   foreach($partials as $slot=>$html){if(str_contains($html,'Direct policy marker')!==(!$repaired||!$expectedBlocked))throw new RuntimeException('actual partial block expectation '.$width.'/'.$rule.'/'.$slot);$checks++;}
   $rows[]=['width'=>$width,'shape'=>'actual_local_provider_full_vs_partial','rule'=>$rule,'full'=>$full,'current_fixture_rules'=>['personal_blocks'=>$pg->query('SELECT * FROM fixture_blocks')->fetchAll(),'personal_mutes'=>$pg->query('SELECT * FROM fixture_mutes')->fetchAll(),'server'=>$pg->query('SELECT * FROM fixture_server_blocks')->fetchAll(),'words'=>$pg->query('SELECT * FROM fixture_words')->fetchAll()],'partial_dispatch'=>$partials,'partial_markers'=>array_map(static fn($html)=>str_contains($html,'Direct policy marker'),$partials),'source_provider'=>'actual ap_masto_status_by_note_id + ap_masto_status_from_row','partial_collection_supplied'=>true];
  }
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$path=$argv[1]??'/tmp/vaak-remote-direct-moderation-reference.json';file_put_contents($path,json_encode(['checks'=>$checks,'captures'=>$rows,'bootstrap_tables'=>$tables,'expect_repaired_partial_blocks'=>$repaired,'native_parity_proven'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo 'remote-direct-moderation-reference PASS '.$checks.' checks; '.count($rows).' actual captures; '.$path."\n";
