<?php
declare(strict_types=1);
/** Actual remote AP collector/classifier; no normalizer, renderer or network. */
require __DIR__.'/local-profile-genuine-setup.php';
function remote_source_block(string $source,int $start):string {
 $tokens=token_get_all('<?php '.substr($source,$start));$result='';$depth=0;$body=false;
 foreach(array_slice($tokens,1) as $token){$result.=is_array($token)?$token[1]:$token;if(is_array($token)&&in_array($token[0],[T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES],true))$depth++;if($token==='{'){$depth++;$body=true;}if($token==='}')$depth--;if($body&&$depth===0)return $result;}
 throw new RuntimeException('actual balanced remote block');
}
$admin=(string)file_get_contents(dirname(__DIR__).'/ap-admin.php');$start=strpos($admin,"                  if (\$rpActor !== '') {\n                      // Mastodon: /users/name");if($start===false)throw new RuntimeException('remote collector anchor');$collector=remote_source_block($admin,$start);
$start=strpos($admin,'              foreach ($rpPosts as $item) {',strpos($admin,'// Only Announces belong in the Boosts tab'));if($start===false)throw new RuntimeException('remote classifier anchor');$classifier=remote_source_block($admin,$start);
eval('function remote_actual_source(PDO $db,string $requested):array {$rpActor=$requested;$rpPosts=[];$rpAliases=[];'.$collector.'$rpTabItems=["posts"=>[],"replies"=>[],"boosts"=>[],"media"=>[],"asks"=>[]];'.$classifier.'return ["requested_actor"=>$requested,"canonical_actor"=>$rpActor,"aliases"=>$rpAliases,"collector"=>$rpPosts,"buckets"=>$rpTabItems];}');
$input=json_decode((string)file_get_contents('/tmp/vaak-local-profile-shared-card-genuine-lookup-reference.json'),true,512,JSON_THROW_ON_ERROR);$schemas=[];$captures=[];
$cases=['ordinary_predicates','ordinary_order_limit','alias_score','alias_following','alias_encoded','reply_classification','gateway_root','gateway_actor','gateway_host_path','gateway_spoiler_sensitive','gateway_host_wildcard','gateway_canonical_alias'];
foreach(['INTEGER','BIGINT'] as $width){$schema='remote_ap_source_php_'.strtolower($width).'_'.getmypid();$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);$GLOBALS['fixture_db']=$pg;
 try{
  foreach($input['current_schema_by_width'][$width] as $table=>$columns){$defs=array_map(static fn($column)=>$column['column_name'].' '.$column['data_type'],$columns);$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');}
  foreach($pg->query('SELECT table_name,column_name,data_type FROM information_schema.columns WHERE table_schema=current_schema() ORDER BY table_name,ordinal_position')->fetchAll() as $column)$schemas[$width][$column['table_name']][]=['column_name'=>$column['column_name'],'data_type'=>$column['data_type']];
  $seed=null;foreach($input['captures'] as $capture)if($capture['width']===$width&&$capture['case']==='own_remote_boost_public_boosts_owner2'){$seed=$capture;break;}if(!$seed)throw new RuntimeException('actual seed');
  foreach($cases as $case){foreach($seed['current_tables'] as $table=>$rows){$pg->exec('DELETE FROM '.$table);foreach($rows as $row)lp_insert_clone($pg,$table,$row);}$pg->exec('DELETE FROM events;DELETE FROM remote_actors;DELETE FROM following');
   $host=str_replace('_','-',$case).'-'.strtolower($width).'.test';if($case==='gateway_host_wildcard')$host='gateway_under-'.strtolower($width).'.test';$actor='https://'.$host.'/users/alice';
   if($case==='gateway_root'||$case==='gateway_spoiler_sensitive'||$case==='gateway_host_wildcard')$actor='https://'.$host;
   if($case==='gateway_actor')$actor='https://'.$host.'/actor';if($case==='gateway_host_path')$actor='https://'.$host.'/'.$host;
   $event=$seed['current_tables']['events'][0];$id=6000;
   $add=static function(array $changes)use($pg,$event,&$id,$actor):void{$row=$event;$row['id']=++$id;$row['actor_id']=$actor;$row['object_id']=$actor.'/objects/'.$id;$row['type']='Create';$row['summary']='source marker '.$id;$row['created_at']='2026-10-10T12:00:00Z';$row['media_urls']=null;$row['spoiler_text']=null;$row['sensitive']=0;$row['in_reply_to']=null;$row['visibility']='public';lp_insert_clone($pg,'events',array_replace($row,$changes));};
   if($case==='ordinary_predicates'){
    foreach([['summary'=>''],['summary'=>'   '],['summary'=>null,'media_urls'=>'[]'],['summary'=>null,'media_urls'=>'0'],['summary'=>null,'spoiler_text'=>' CW '],['summary'=>null,'sensitive'=>1],['type'=>'Announce','summary'=>''],['type'=>'Announce','summary'=>'boost'],['type'=>'create'],['type'=>'Like'],['visibility'=>'private'],['action_taken'=>'delete'],['actor_id'=>$actor.'/']] as $changes)$add($changes);
   }elseif($case==='ordinary_order_limit'){for($index=0;$index<38;$index++)$add(['created_at'=>sprintf('2026-10-10T12:%02d:00Z',$index%20)]);}
   elseif(str_starts_with($case,'alias_')||$case==='gateway_canonical_alias'){
    if($case==='gateway_canonical_alias')$actor='https://'.$host.'/actor';$uname=$case==='alias_encoded'?'name space':'alice';$actor=$case==='alias_encoded'?'https://'.$host.'/users/name%20space':$actor;
    lp_insert_clone($pg,'remote_actors',['actor_id'=>$actor,'username'=>$uname,'host'=>$host]);$numeric='https://'.$host.'/ap/users/51';lp_insert_clone($pg,'remote_actors',['actor_id'=>$numeric,'username'=>$uname,'host'=>$host]);lp_insert_clone($pg,'following',['owner_actor_id'=>'https://mkultra.monster/users/fixture','actor_id'=>'https://'.$host.'/@'.rawurlencode($uname),'host'=>$host]);
    $add(['actor_id'=>$actor]);$add(['actor_id'=>$numeric]);$add(['actor_id'=>$numeric.'/']);$add(['actor_id'=>$numeric,'summary'=>'scoring third']);$add(['actor_id'=>'https://'.$host.'/@'.rawurlencode($uname),'type'=>'Announce']);$add(['actor_id'=>'https://'.$host.'/other','type'=>'Announce']);
    if($case==='alias_following')$add(['actor_id'=>'https://'.$host.'/users/'.rawurlencode($uname),'summary'=>'users alias']);
   }elseif($case==='reply_classification'){
    foreach([['in_reply_to'=>'https://mkultra.monster/users/fixture/notes/self'],['in_reply_to'=>'https://mkultra.monster/users/other/notes/self/'],['in_reply_to'=>'https://mkultra.monster/users/fixture/objects/other'],['in_reply_to'=>'https://else.test/notes/reply'],['in_reply_to'=>'0'],['in_reply_to'=>' '],['type'=>'Announce','in_reply_to'=>'https://else.test/reply'],['media_urls'=>'["https://media.test/image.jpg"]'],['media_urls'=>'0']] as $changes)$add($changes);
   }else{
    $add(['actor_id'=>'https://'.$host.'/users/a','type'=>'Announce','summary'=>'']);$add(['actor_id'=>'https://'.$host.'/users/b','created_at'=>'2099-01-01T00:00:00Z']);$add(['actor_id'=>'https://'.$host.'/users/a','summary'=>'','spoiler_text'=>'CW']);$add(['actor_id'=>'https://'.$host.'/users/a','summary'=>'','sensitive'=>1]);$add(['actor_id'=>'https://'.$host.'/users/a','summary'=>'','media_urls'=>'0']);$add(['actor_id'=>'https://'.str_replace('_','X',$host).'/users/c','type'=>'Announce']);$add(['actor_id'=>'https://unrelated.test/users/u']);
   }
   $current=boost_snapshot();foreach([1,2,0] as $owner){direct_bind($owner);$GLOBALS['view']='remote_profile';$source=remote_actual_source($pg,$actor);$captures[]=['width'=>$width,'case'=>$case,'owner'=>$owner,'viewer_own_actor'=>vaak_actor_id(),'requested_actor'=>$actor,'current_tables'=>$current,'source'=>$source,'firstpaint'=>['has_more'=>false,'next_offset_by_tab'=>array_map('count',$source['buckets'])]];}
  }
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$out=$argv[1]??'/tmp/vaak-remote-profile-ap-source-reference.json';file_put_contents($out,json_encode(['captures'=>$captures,'current_schema_by_width'=>$schemas,'actual_collector'=>$collector,'actual_classifier'=>$classifier,'actual_callsite_sha256'=>['collector'=>hash('sha256',$collector),'classifier'=>hash('sha256',$classifier)],'scope'=>'actual remote AP collector/classifier after cached actor preparation; actual aliases helper, current SQL and actual viewer identity; no normalizer/header/auth bootstrap/network; original selected rows retain private/deleted candidates for later audience admission; no remote append invented','semantic_substitutions'=>$GLOBALS['fixture_production_semantic_substitutions']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));echo count($captures).' actual remote AP source captures: '.$out."\n";
