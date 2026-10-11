<?php
declare(strict_types=1);
/** Actual saved SQL collector + row mapper + complete PHP caller recording.
 * Session, external refresh/lookup/worker transports remain declared fixture
 * dependencies. This never fabricates a saved row from a supplied card.
 */
$producerArtifact=$argv[1]??'/tmp/vaak-library-saved-producer-reference.json';
$producerExecutionStart=time();
$networkProducer=true;
putenv('ROOT_SAVED_NETWORK_PRODUCER=1');
if($networkProducer&&getenv('ROOT_SAVED_NETWORK_DATA')!=='1'){
 // Only the optional native-data HTTP transport is declared unavailable. The
 // actual PHP SQL miss collector, hydration, renderer and cursor execute.
 function admin_library_data_axum_fetch(int $ownerUserId,int $offset=0,int $limit=20,string $libraryKind='favourites',int $folderId=0):?array{
  $GLOBALS['saved_data_transport_requests'][]=compact('ownerUserId','libraryKind','folderId','offset','limit');return null;
 }
}
$adminPath=dirname(__DIR__).'/ap-admin.php';$entitiesPath=dirname(__DIR__).'/ap-masto-entities.php';
function saved_producer_alias(string $path,string $name,string $alias):string{
 $source=(string)file_get_contents($path);if(!preg_match('/^function '.preg_quote($name,'/').'\(.*?^}/ms',$source,$match))throw new RuntimeException('actual producer declaration '.$name);
 $GLOBALS['saved_producer_declarations'][$name]=hash('sha256',$match[0]);
 $code=str_replace('function '.$name.'(','function '.$alias.'(',$match[0]);$resolved='';
 foreach(token_get_all('<?php '.$code) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(dirname($path),true):(is_array($token)&&$token[0]===T_FILE?var_export($path,true):(is_array($token)?$token[1]:$token));return substr($resolved,5);
}
if($networkProducer&&getenv('ROOT_SAVED_NETWORK_DATA')==='1'){
 eval(saved_producer_alias($adminPath,'admin_library_data_axum_fetch','saved_actual_library_data_fetch'));
 function admin_library_data_axum_fetch(int $ownerUserId,int $offset=0,int $limit=20,string $libraryKind='favourites',int $folderId=0):?array{
  $result=saved_actual_library_data_fetch($ownerUserId,$offset,$limit,$libraryKind,$folderId);
  $GLOBALS['saved_data_transport_requests'][]=['request'=>compact('ownerUserId','libraryKind','folderId','offset','limit'),'actual_response'=>$result];return $result;
 }
}
eval(saved_producer_alias($adminPath,'admin_render_library_entries','saved_actual_render_entries'));
eval(saved_producer_alias($entitiesPath,'ap_masto_interaction_row_to_status','saved_actual_row_to_status'));
function admin_render_library_entries(array $entries,string $view):void{
 $GLOBALS['saved_producer_entries'][]=['entries'=>$entries,'view'=>$view];
 $prior=[];foreach(['fixture_actor_warm_requests','fixture_media_warm_requests'] as $global)$prior[$global]=count($GLOBALS[$global]??[]);
 saved_actual_render_entries($entries,$view);
 // The native effect recorder belongs at this actual render call, after
 // producer hydration and before any fallback painter effects. End-of-caller
 // prepending would reorder earlier real PHP collector effects.
 $path=$GLOBALS['saved_native_effects_path']??null;
 if(is_string($path)&&is_file($path)){
  $native=json_decode((string)file_get_contents($path),true,512,JSON_THROW_ON_ERROR);
  foreach(['actor_warm'=>'fixture_actor_warm_requests','media_warm'=>'fixture_media_warm_requests'] as $key=>$global){$all=$GLOBALS[$global]??[];$GLOBALS[$global]=array_merge(array_slice($all,0,$prior[$global]),$native[$key],array_slice($all,$prior[$global]));}
 }

}
function ap_masto_interaction_row_to_status(array $row,string $flag):?array{
 $result=saved_actual_row_to_status($row,$flag);$GLOBALS['saved_producer_mappings'][]=['row'=>$row,'flag'=>$flag,'actual_status'=>$result];return $result;
}
putenv('ROOT_CAPTURE_FRESH=1');putenv('ROOT_LIBRARY_SEARCH_PRODUCER_BOOTSTRAP=1');
$argv[1]='/tmp/vaak-library-saved-producer-bootstrap-'.getmypid().'.json';ob_start();require __DIR__.'/root-preview-callers-production-reference.php';$bootstrapOutput=ob_get_clean();unlink($argv[1]);
if($networkProducer){
 reference_load(dirname(__DIR__).'/ap-db.php',['ap_masto_favourite_rows']);
 reference_load($entitiesPath,['ap_masto_favourites_list']);
 foreach(['ap_masto_favourite_rows'=>dirname(__DIR__).'/ap-db.php','ap_masto_favourites_list'=>$entitiesPath] as $name=>$path)saved_producer_alias($path,$name,'saved_unused_'.$name);
}
reference_load($adminPath,['admin_favourite_fedi_page','admin_favourite_fedi_fragment']);
if(getenv('ROOT_FAVOURITES_BASELINE')==='1'){
 $original=(string)file_get_contents(getenv('ROOT_FAVOURITES_BASELINE_CONTROLLER'));
 $body=substr($original,strpos($original,'{')+1);$body=substr($body,0,strrpos($body,'}'));
 $body=str_replace("echo \$html;\n    exit;","return ['html'=>\$html,'has_more'=>\$hasMore,'source'=>'miss'];",$body);
 if(str_contains($body,'echo $html;'))throw new RuntimeException('original AJAX fixture boundary');
 eval('function favourite_original_fragment(int $vaakOwnerId,int $offset,int $limit):array { $_GET=["offset"=>$offset,"limit"=>$limit];'.$body.'}');
}

$GLOBALS['fixture_render_clock']=1791673200;
$captures=[];
foreach(['INTEGER','BIGINT'] as $width){
 if(getenv('ROOT_SAVED_WIDTH_FILTER')&&getenv('ROOT_SAVED_WIDTH_FILTER')!==$width)continue;
 $nativeReplay=getenv('ROOT_NATIVE_ACCEPT')==='1';
 $sidecarReplay=$networkProducer&&getenv('ROOT_SAVED_NETWORK_DATA')==='1';
 if($sidecarReplay)putenv('VAAK_SHADOW_HTTP=http://127.0.0.1:'.($width==='INTEGER'?'18874':'18875'));
 $schema=($nativeReplay||$sidecarReplay)?'root_preview_native_'.strtolower($width):'library_saved_producer_'.strtolower($width).'_'.getmypid();
 if($sidecarReplay&&!$nativeReplay)$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');
 if($nativeReplay){$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');putenv('VAAK_LIBRARY_NATIVE_URL='.(getenv('ROOT_LIBRARY_URL_'.$width)?:'http://127.0.0.1:18874/internal/library/render'));putenv('VAAK_LIBRARY_NATIVE_RENDER=1');}
 $pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);$GLOBALS['fixture_db']=$pg;
 try{
  foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}
  foreach(['masto_bookmarks','masto_favourites'] as $table){$pg->exec('ALTER TABLE '.$table.' ADD COLUMN id '.$width.';ALTER TABLE '.$table.' ADD COLUMN owner_actor_id TEXT;ALTER TABLE '.$table.' ALTER COLUMN id SET NOT NULL;ALTER TABLE '.$table.' ALTER COLUMN id ADD GENERATED BY DEFAULT AS IDENTITY;ALTER TABLE '.$table.' ADD PRIMARY KEY(id);ALTER TABLE '.$table.' ADD UNIQUE(owner_user_id,status_id)');}
  $pg->exec('ALTER TABLE masto_favourites ADD COLUMN target_actor TEXT;ALTER TABLE masto_favourites ADD COLUMN like_activity_id TEXT');
  foreach(['bookmark','favourite'] as $kind){$pg->exec('CREATE TABLE bsky_'.$kind.'_cache(owner_user_id '.$width.', '.$kind.'_uri TEXT, '.($kind==='bookmark'?'bookmarked_at':'favourited_at').' TEXT, post_json TEXT, updated_at TEXT, PRIMARY KEY(owner_user_id,'.$kind.'_uri))');}
  $pg->exec('CREATE TABLE bsky_sessions(owner_user_id '.$width.' PRIMARY KEY,handle TEXT NOT NULL,did TEXT NOT NULL,pds_host TEXT NOT NULL,access_jwt_enc TEXT NOT NULL,refresh_jwt_enc TEXT NOT NULL,connected_at TEXT NOT NULL,updated_at TEXT NOT NULL)');
  $pg->exec('CREATE TABLE ap_action_queue(id '.$width.' GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY,owner_user_id '.$width.' NOT NULL,platform TEXT NOT NULL,action_kind TEXT NOT NULL,target_key TEXT NOT NULL,desired_state '.$width.' NOT NULL,confirmed_state '.$width.',revision '.$width.' NOT NULL DEFAULT 1,payload_json TEXT NOT NULL DEFAULT \'{}\',status TEXT NOT NULL DEFAULT \'pending\',attempts '.$width.' NOT NULL DEFAULT 0,max_attempts '.$width.' NOT NULL DEFAULT 12,next_attempt_at TEXT NOT NULL,claimed_at TEXT,last_error TEXT,last_error_host TEXT,last_error_code '.$width.',result_json TEXT,receipt_json TEXT,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,UNIQUE(owner_user_id,platform,action_kind,target_key))');
  $pg->exec('CREATE TABLE vaak_bookmark_folders(id '.$width.',owner_user_id '.$width.',title TEXT,position '.$width.' DEFAULT 0,created_at TEXT,updated_at TEXT);CREATE TABLE vaak_bookmark_folder_items(folder_id '.$width.',owner_user_id '.$width.',status_id TEXT,added_at TEXT,PRIMARY KEY(folder_id,status_id))');
  if(isset($tables['bsky_post_observations']))$pg->exec('ALTER TABLE bsky_post_observations ADD UNIQUE(bsky_uri,owner_user_id)');
  // Real bookmark/favourite rows are the producer inputs. Lookup transport is
  // explicitly recorded; ordinary canonical SQL normalization is a later gate.
  $plain=$corpus['status_plain']['status'];$plain['created_at']='2026-10-10T12:00:00Z';
  // This distinct actor has not been painted by the bootstrap's static PHP
  // avatar memo. Both actual callers therefore read the same warm SQL row.
  $plain['account']['id']=$plain['account']['uri']='https://savedproducer.test/users/alice';
  $plain['account']['url']='https://savedproducer.test/@alice';$plain['account']['acct']='alice@savedproducer.test';
  $pg->exec("UPDATE link_preview_cards SET fetched_at='2026-10-10T12:00:00Z',expires_at='2026-10-12T12:00:00Z'");
  if(getenv('ROOT_SAVED_PRODUCER_COLD')!=='1')$pg->prepare('INSERT INTO remote_media_cache(actor_id,kind,public_url) VALUES(?,?,?)')->execute([$plain['account']['uri'],'avatar','https://cache.test/saved-producer-avatar.webp']);
  $pg->prepare('INSERT INTO fixture_status_cache(object_id,payload) VALUES(?,?)')->execute([$plain['uri'],json_encode($plain,JSON_THROW_ON_ERROR)]);
  $cases=['saved_ap','saved_rss','saved_bsky','removed_saved','foreign_saved','offset_empty','action_saved_ap','action_saved_rss','action_removed_ap','action_removed_rss','network_omitted_fedi','favourite_mixed','favourite_offset','favourite_cap','favourite_same_time'];
  foreach($cases as $case){
   if(getenv('ROOT_SAVED_CASE_FILTER')&&getenv('ROOT_SAVED_CASE_FILTER')!==$case)continue;
   foreach(['masto_bookmarks','masto_favourites','bsky_bookmark_cache','bsky_favourite_cache','vaak_bookmark_folder_items','vaak_bookmark_folders'] as $table)$pg->exec('DELETE FROM '.$table);
   $isBsky=str_contains($case,'bsky')||$case==='mixed_protocols';$owner=in_array($case,['foreign_saved','foreign_bsky'],true)?2:1;$at='at://did:plc:savedproducer/app.bsky.feed.post/one';$sid=str_ends_with($case,'rss')?'rss:11':($isBsky?'bsky:'.$at:'saved-ap-123');$object=str_ends_with($case,'rss')?'rss:11':($isBsky?$at:$plain['uri']);
   foreach(['masto_bookmarks','masto_favourites'] as $table)$pg->prepare('INSERT INTO '.$table.'(id,owner_user_id,owner_actor_id,status_id,object_id,created_at) VALUES(11,?,?,?,?,?)')->execute([$owner,'https://mkultra.monster/users/fixture',$sid,$object,'2026-10-10T12:00:00Z']);
   if($isBsky){$post=['post'=>['uri'=>$at,'cid'=>'saved-producer-cid','author'=>['did'=>'did:plc:savedproducer','handle'=>'saved-producer.test','displayName'=>'Saved Producer','avatar'=>'https://media.test/saved.jpg'],'record'=>['$type'=>'app.bsky.feed.post','text'=>'Actual owner cached saved post','createdAt'=>'2026-10-10T12:00:00Z'],'indexedAt'=>'2026-10-10T12:00:00Z','viewer'=>[]]];foreach(['bookmark','favourite'] as $kind)$pg->prepare('INSERT INTO bsky_'.$kind.'_cache VALUES(?,?,?,?,?)')->execute([$owner,$at,'2026-10-10T12:00:00Z',json_encode($post,JSON_THROW_ON_ERROR),'2026-10-10T12:00:00Z']);}
   if(in_array($case,['mixed_protocols','mixed_omitted_bsky'],true))$pg->prepare('INSERT INTO masto_bookmarks(id,owner_user_id,status_id,object_id,created_at) VALUES(13,1,?,?,?)')->execute(['saved-ap-123',$plain['uri'],'2026-10-10T11:30:00Z']);
   if($case==='mixed_omitted_bsky')$pg->exec("UPDATE bsky_bookmark_cache SET post_json='{malformed selected cache row'");
   if(in_array($case,['mixed_saved','mixed_saved_three'],true))$pg->prepare('INSERT INTO masto_favourites(id,owner_user_id,status_id,object_id,created_at) VALUES(12,1,?,?,?)')->execute(['rss:11','rss:11','2026-10-10T11:00:00Z']);
   if($case==='mixed_saved_three'){
    $second=$plain;$second['id']='124';$second['uri']='https://remote.test/users/alice/statuses/124';$second['url']='https://remote.test/@alice/124';$second['content']='<p>Second genuine saved source</p>';
    $pg->prepare('INSERT INTO fixture_status_cache(object_id,payload) VALUES(?,?)')->execute([$second['uri'],json_encode($second,JSON_THROW_ON_ERROR)]);
    $pg->prepare('INSERT INTO masto_bookmarks(id,owner_user_id,status_id,object_id,created_at) VALUES(13,1,?,?,?)')->execute(['saved-ap-124',$second['uri'],'2026-10-10T10:30:00Z']);
   }
   if($case==='network_omitted_fedi'){
    $pg->exec("UPDATE masto_favourites SET status_id='rss:999999',object_id='rss:999999' WHERE id=11");
    $pg->prepare('INSERT INTO masto_favourites(id,owner_user_id,status_id,object_id,created_at) VALUES(12,1,?,?,?)')->execute(['saved-ap-123',$plain['uri'],'2026-10-10T11:00:00Z']);
   }
   if($case==='network_omitted_bsky'){
    $pg->exec("UPDATE bsky_bookmark_cache SET post_json='{malformed selected cache row'");
    $secondAt='at://did:plc:savedproducer/app.bsky.feed.post/two';$post['post']['uri']=$secondAt;
    $pg->prepare('INSERT INTO bsky_bookmark_cache VALUES(?,?,?,?,?)')->execute([1,$secondAt,'2026-10-10T11:00:00Z',json_encode($post,JSON_THROW_ON_ERROR),'2026-10-10T11:00:00Z']);
   }
   if(str_starts_with($case,'favourite_')){
    $pg->exec('DELETE FROM masto_favourites');
    $count=$case==='favourite_cap'?90:($case==='favourite_offset'?35:($case==='favourite_same_time'?3:12));
    for($n=0;$n<$count;$n++){
     $status=$plain;$status['id']='fav-source-'.$n;$status['uri']='https://remote.test/users/alice/statuses/favourite-'.$n;$status['url']=$status['uri'];
     $status['content']='<p>Favourite exact raw source '.$n.'</p>';
     $sid=$status['id'];$object=$status['uri'];
     if($n%7===0){$sid=$object='rss:'.(999000+$n);} // Actual mapper omits unresolved RSS.
     elseif($n%5===0){$sid='bsky:'.$n;$object='at://did:plc:savedproducer/app.bsky.feed.post/fav'.$n;$status['id']=$sid;$status['uri']=$object;}
     $pg->prepare('INSERT INTO fixture_status_cache(object_id,payload) VALUES(?,?)')->execute([$object,json_encode($status,JSON_THROW_ON_ERROR)]);
     $stamp=$case==='favourite_same_time'?'2026-10-10T12:00:00Z':gmdate('Y-m-d\\TH:i:s\\Z',1791633600-$n);
     $pg->prepare('INSERT INTO masto_favourites(id,owner_user_id,status_id,object_id,created_at) VALUES(?,1,?,?,?)')->execute([100+$n,$sid,$object,$stamp]);
    }
   }
   $folder=str_starts_with($case,'folder_')?5:0;
   if($folder){$pg->exec("INSERT INTO vaak_bookmark_folders VALUES(5,1,'Selected',0,'2026-10-10','2026-10-10')");$pg->prepare('INSERT INTO vaak_bookmark_folder_items VALUES(5,?,?,?)')->execute([in_array($case,['folder_foreign','folder_bsky_foreign'],true)?2:1,$sid,'2026-10-10']);}
   $actionEvidence=[];
   if(str_starts_with($case,'action_')){
    $actionEvidence['execution_wall_start']=time();
    foreach(['masto_bookmarks','masto_favourites'] as $table)$pg->exec('DELETE FROM '.$table);
    $actionEvidence['before']=root_reference_tables();
    ap_masto_bookmark_add($sid,$object,2);ap_masto_favourite_add($sid,$object,'https://remote.test/users/alice',null,2);
    ap_masto_bookmark_add($sid,$object,1);ap_masto_favourite_add($sid,$object,'https://remote.test/users/alice',null,1);
    $actionEvidence['after_save']=root_reference_tables();
    if(str_starts_with($case,'action_removed')){ap_masto_bookmark_remove($sid,1,$object);ap_masto_favourite_remove($sid,1,$object);}
    $actionEvidence['after_action']=root_reference_tables();
    $actionEvidence['execution_wall_end']=time();
    foreach(['masto_bookmarks','masto_favourites'] as $table){$owners=$pg->query('SELECT owner_user_id FROM '.$table.' ORDER BY owner_user_id')->fetchAll(PDO::FETCH_COLUMN);$expected=str_starts_with($case,'action_removed')?[2]:[1,2];if(array_map('intval',$owners)!==$expected)throw new RuntimeException('actual saved action owner isolation '.$case);}
   }
   if($case==='removed_saved')foreach(['masto_bookmarks','masto_favourites'] as $table)$pg->exec('DELETE FROM '.$table.' WHERE owner_user_id=1');
   direct_bind(1);unset($GLOBALS['ap_masto_flag_memo'],$GLOBALS['ap_relset_memo']);direct_production_prepare_moderation();$_GET=[];$GLOBALS['view']='favourites';$GLOBALS['admin_status_link_preview_budget']=0;$GLOBALS['admin_quote_link_preview_budget']=0;$GLOBALS['admin_library_fetch_budget']=3;
   foreach(['saved_producer_entries','saved_producer_mappings','fixture_status_lookup_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','preview_http_requests','preview_warm_requests'] as $key)$GLOBALS[$key]=[];
   $before=root_reference_tables();$offset=$case==='offset_empty'?10:($case==='favourite_offset'?10:($case==='favourite_cap'?70:0));
   $favouritesMapping=getenv('ROOT_SAVED_FAVOURITES_MAPPING')==='1'?['raw_rows'=>ap_masto_favourite_rows(min(80,$offset+10+20),null,1),'hydrated'=>ap_masto_favourites_list($offset+10+20,null)]:null;
   // Separate mapper discovery from bookmark collector effects. No controller
   // slice or native favourite producer acceptance is inferred here.
   if(getenv('ROOT_SAVED_FAVOURITES_MAPPING')==='1')foreach(['saved_producer_mappings','fixture_status_lookup_requests','fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','preview_http_requests','preview_warm_requests'] as $key)$GLOBALS[$key]=[];
   $GLOBALS['saved_data_transport_requests']=[];
   $nativeEffectsPath=(getenv('ROOT_NATIVE_WARM_EFFECTS_PREFIX')?:'/tmp/vaak-library-search-warm-effects').'-'.strtolower($width).'.json';
   $GLOBALS['saved_native_effects_path']=$nativeReplay?$nativeEffectsPath:null;
   if($nativeReplay){if(is_file($nativeEffectsPath))unlink($nativeEffectsPath);file_put_contents('/tmp/vaak-root-preview-fixture-clock-'.strtolower($width).'.json',json_encode(['clock'=>$GLOBALS['fixture_render_clock']],JSON_THROW_ON_ERROR));$traceStart=root_production_trace_offset($width);}
   $network=$isBsky?'bsky':'fedi';
   $result=getenv('ROOT_FAVOURITES_BASELINE')==='1'?favourite_original_fragment(1,$offset,10):admin_favourite_fedi_fragment(1,$offset,10);
   if($result['source']!==($networkProducer?($sidecarReplay?'axum-data':'miss'):'mixed'))throw new RuntimeException('actual saved collector failed '.$case);
   $entries=$GLOBALS['saved_producer_entries'];$expectedEmpty=in_array($case,['folder_foreign','folder_bsky_foreign','removed_saved','foreign_saved','foreign_bsky','offset_empty','action_removed_ap','action_removed_rss','saved_bsky','favourite_cap'],true);
   if($networkProducer&&$case==='mixed_omitted_bsky')$expectedEmpty=true;
   if($expectedEmpty!==empty($entries[0]['entries']))throw new RuntimeException('actual saved owner/folder/window admission '.$case);
   $captures[]=['width'=>$width,'case'=>$case,'owner'=>1,'kind'=>'favourites_fedi','native_data_transport'=>$GLOBALS['saved_data_transport_requests'],'actual_favourites_mapping'=>$favouritesMapping,'folder_id'=>$folder,'offset'=>$offset,'limit'=>10,'native_trace'=>$nativeReplay?['path'=>getenv('ROOT_NATIVE_TRACE_'.$width),'start'=>$traceStart,'end'=>root_production_trace_offset($width)]:null,'current_schema'=>root_production_schema(),'before_tables'=>$before,'after_tables'=>root_reference_tables(),'actual_entry_calls'=>$entries,'actual_row_mappings'=>$GLOBALS['saved_producer_mappings'],'actual_action_chain'=>$actionEvidence,'php'=>['result'=>$result,'effects'=>['lookup'=>$GLOBALS['fixture_status_lookup_requests'],'actor_warm'=>$GLOBALS['fixture_actor_warm_requests'],'media_warm'=>$GLOBALS['fixture_media_warm_requests'],'quote_warm'=>$GLOBALS['fixture_quote_warm_requests'],'http'=>$GLOBALS['preview_http_requests'],'warm'=>$GLOBALS['preview_warm_requests'],'status_budget'=>$GLOBALS['admin_status_link_preview_budget']]]];
  }
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
file_put_contents($producerArtifact,json_encode(['execution_wall_start'=>$producerExecutionStart,'execution_wall_end'=>time(),'declarations'=>$GLOBALS['saved_producer_declarations'],'scope'=>'Actual SQL owner/folder/window collector, exact original saved-row mapper inputs and complete PHP markup; recorder wrappers delegate untouched renamed declarations. Session/external refresh/lookup/worker transport declared. Native producer/card proof not yet claimed.','captures'=>$captures],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));
echo 'Actual saved producer reference '.count($captures).' captures: '.$producerArtifact."\n";
