<?php
declare(strict_types=1);
/** Actual PHP complete-card capture; fixture media are declared input, never oracle rewrites. */
if(getenv('QUOTE_MEDIA_WARM_ORIGINAL')===false)require __DIR__.'/remote-direct-production-setup.php';
else{
 // Genuine static avatar memo has no reset API: establish declared warm SQL
 // before the inherited first painter request, not after its cold memo warm.
 $warmBootstrap=(string)file_get_contents(__DIR__.'/remote-direct-production-helper-bootstrap.php');$resolved='';foreach(token_get_all($warmBootstrap) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);
 $resolved.="\nap_db()->exec(\"INSERT INTO remote_media_cache(id,actor_id,kind,source_url,public_url,fetched_at,last_used_at) VALUES(900001,'https://remote.test/users/alice','avatar','https://remote.test/avatar.png','https://media.test/warm-author-avatar.webp','2026-10-10T12:00:00Z','2026-10-10T12:00:00Z')\");\n";
 $warmBootstrapPath='/tmp/vaak-quote-media-warm-bootstrap-'.getmypid().'.php';file_put_contents($warmBootstrapPath,$resolved);
 putenv('DIRECT_PRODUCTION_HELPERS=1');$setup=(string)file_get_contents(__DIR__.'/remote-direct-policy-independent.php');$setup=str_replace("__DIR__.'/remote-direct-production-helper-bootstrap.php'",var_export($warmBootstrapPath,true),$setup);$end=strpos($setup,'$captures=[];$checks=0;');if($end===false)throw new RuntimeException('production setup boundary');$resolved='';foreach(token_get_all(substr($setup,0,$end)) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);eval(substr($resolved,5));
}
reference_load(dirname(__DIR__).'/ap-masto-entities.php',['ap_masto_outer_media_without_quote_duplicates']);
$actualLookupSource=(string)file_get_contents(dirname(__DIR__).'/ap-masto-entities.php');
$actualLookupSource=str_replace('function ap_masto_lookup_status_by_object_url(', 'function quote_reference_actual_lookup(', $actualLookupSource);
$lookupPath='/tmp/vaak-quote-reference-actual-lookup-'.getmypid().'.php';file_put_contents($lookupPath,$actualLookupSource);
reference_load($lookupPath,['quote_reference_actual_lookup']);
$production=json_decode((string)file_get_contents(__DIR__.'/quote-media-production-source.json'),true,512,JSON_THROW_ON_ERROR);
$warmOriginal=[];if(($warmPath=getenv('QUOTE_MEDIA_WARM_ORIGINAL'))!==false)foreach(json_decode((string)file_get_contents($warmPath),true,512,JSON_THROW_ON_ERROR)['captures'] as $original)$warmOriginal[$original['width'].'/'.$original['case'].'/'.$original['context']['view']]=$original;
$base=$corpus['status_quote_accepted'];
$image=static fn(string $url):array=>['type'=>'image','url'=>$url,'preview_url'=>$url,'description'=>'Quote media marker'];
$q=$image('https://media.test/quoted-full.jpg');$p=$image('https://media.test/parent-full.jpg');
$shapes=[];
foreach(['quote_only','parent_and_quote','distinct_parent','same_thumbnail','preview_only','empty_child','envelope_only','pending_empty','multiple_types','child_cw','parent_cw'] as $name){
 $case=$base;$s=&$case['status'];$c=&$s['quote']['quoted_status'];$s['content']='Parent commentary marker';unset($s['card'],$c['card']);$s['media_attachments']=[];$c['media_attachments']=[$q];$c['content']='Quoted body marker';$c['spoiler_text']='';$c['sensitive']=false;$s['spoiler_text']='';$s['sensitive']=false;
 if($name==='quote_only')$s['media_attachments']=[$q];
 if($name==='parent_and_quote')$s['media_attachments']=[$p,$q];
 if($name==='distinct_parent')$s['media_attachments']=[$p];
 if($name==='same_thumbnail'){$s['media_attachments']=[$p];$s['media_attachments'][0]['preview_url']=$q['preview_url'];}
 if($name==='preview_only'){$s['media_attachments']=[['type'=>'image','preview_url'=>$q['preview_url']]];}
 if($name==='empty_child'){$s['media_attachments']=[$q];$c['media_attachments']=[];}
 if($name==='envelope_only'){$s['media_attachments']=[$q];$c['media_attachments']=[];$s['quote']['media_attachments']=[$q];}
 if($name==='pending_empty'){$s['media_attachments']=[$q];$s['quote']=['state'=>'pending','quoted_status'=>null];}
 if($name==='multiple_types'){$v=['type'=>'video','url'=>'https://media.test/quoted-video.mp4','preview_url'=>'https://media.test/video-poster.jpg'];$a=['type'=>'audio','url'=>'https://media.test/quoted-audio.ogg','preview_url'=>null];$c['media_attachments']=[$q,$v,$a];$s['media_attachments']=[$p,$q,$v,$a];}
 if($name==='child_cw'){$s['media_attachments']=[$p,$q];$c['spoiler_text']='Quoted warning marker';$c['sensitive']=true;}
 if($name==='parent_cw'){$s['media_attachments']=[$p,$q];$s['spoiler_text']='Parent warning marker';$s['sensitive']=true;}
 $shapes[$name]=$case;unset($s,$c);
}
$captures=[];$checks=0;$productionCaptures=[];$schemaByWidth=[];
foreach(['INTEGER','BIGINT'] as $width){$schema='quote_media_reference_'.strtolower($width).'_'.getmypid();$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{
  foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}$GLOBALS['fixture_db']=$pg;
  $dbSource=(string)file_get_contents(dirname(__DIR__).'/ap-db.php');preg_match('/CREATE TABLE IF NOT EXISTS ap_media_warm_queue \(.*?\n\);/s',$dbSource,$queueDdl);if(!$queueDdl)throw new RuntimeException('genuine queue DDL missing');$pg->exec(str_replace('INTEGER PRIMARY KEY AUTOINCREMENT',$width.' GENERATED BY DEFAULT AS IDENTITY PRIMARY KEY',$queueDdl[0]));
  foreach($pg->query('SELECT table_name,column_name,data_type FROM information_schema.columns WHERE table_schema=current_schema() ORDER BY table_name,ordinal_position')->fetchAll() as $column)$schemaByWidth[$width][$column['table_name']][]=['column_name'=>$column['column_name'],'data_type'=>$column['data_type']];
  foreach($shapes as $name=>$case)foreach(['home','search','remote_profile'] as $origin){caller_restore($case['context']);direct_bind(1);direct_production_prepare_moderation();$GLOBALS['view']=$origin;$_GET=[];$GLOBALS['admin_status_link_preview_budget']=0;$GLOBALS['admin_quote_link_preview_budget']=0;$GLOBALS['admin_library_fetch_budget']=0;$GLOBALS['fixture_render_clock']=1791673200;$source=$case['status'];
   if(isset($warmOriginal[$width.'/'.$name.'/'.$origin])){$original=$warmOriginal[$width.'/'.$name.'/'.$origin];$source=$original['source'];$GLOBALS['fixture_render_clock']=$original['context']['clock'];foreach($original['current_tables'] as $table=>$currentRows){$pg->exec('DELETE FROM '.$table);foreach($currentRows as $row){$keys=array_keys($row);$pg->prepare('INSERT INTO '.$table.'('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($row));}}
    $pg->prepare('INSERT INTO remote_media_cache(id,actor_id,kind,source_url,public_url,fetched_at,last_used_at) VALUES(?,?,?,?,?,?,?)')->execute([900001,$source['account']['uri'],'avatar',$source['account']['avatar'],'https://media.test/warm-author-avatar.webp','2026-10-10T12:00:00Z','2026-10-10T12:00:00Z']);
   }
   $before=serialize($source);$sql=[];foreach($pg->query('SELECT table_name FROM information_schema.tables WHERE table_schema=current_schema()')->fetchAll(PDO::FETCH_COLUMN) as $table)$sql[$table]=$pg->query('SELECT * FROM '.$table)->fetchAll();
   $capture=caller_capture(static function()use($source,$origin){admin_render_masto_status_card($source,[],$origin,false,true);});
   if(serialize($source)!==$before)throw new RuntimeException('source mutated');$checks++;
   $captures[]=['width'=>$width,'case'=>$name,'source'=>$source,'context'=>['owner'=>1,'view'=>$origin,'return_view'=>$origin,'focused'=>false,'clock'=>$GLOBALS['fixture_render_clock']],'current_tables'=>$sql,'pure_outer_media'=>ap_masto_outer_media_without_quote_duplicates($source),'php'=>$capture,'status_budget'=>$GLOBALS['admin_status_link_preview_budget']];
  }
  foreach(['public','private_child','deleted_announce','deleted_note'] as $state){caller_restore($base['context']);direct_bind(1);direct_production_prepare_moderation();$pg->exec('DELETE FROM events;DELETE FROM following;DELETE FROM mentions;DELETE FROM fixture_status_cache;DELETE FROM ap_media_warm_queue');
   $columns=$pg->query("SELECT column_name FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='events'")->fetchAll(PDO::FETCH_COLUMN);
   foreach(array_keys($production['events'][0]) as $column)if(!in_array($column,$columns,true))$pg->exec('ALTER TABLE events ADD COLUMN '.$column.' '.(in_array($column,['bytes','sensitive'],true)?$width:'TEXT'));
   foreach($production['events'] as $event){if($event['type']==='Announce'){if($state==='private_child')$event['visibility']='private';if($state==='deleted_announce')$event['action_taken']='deleted';}$keys=array_keys($event);$pg->prepare('INSERT INTO events('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($event));}
   if($state==='deleted_note')$pg->prepare('INSERT INTO events(id,type,object_id,actor_id,action_taken,visibility) VALUES(?,?,?,?,?,?)')->execute([900000,'Delete','https://lgbtqia.space/users/alice/statuses/117418829058105216','https://lgbtqia.space/users/alice','log','public']);
   $url='https://lgbtqia.space/@alice/117418829058105216';$child=quote_reference_actual_lookup($url,1,false,true);
   // Explicit bounded cache transport: only the genuinely resolved child is inserted.
   if(is_array($child))$pg->prepare('INSERT INTO fixture_status_cache(object_id,payload) VALUES(?,?)')->execute([$url,json_encode($child,JSON_THROW_ON_ERROR)]);
   $source=ap_masto_status_from_event($production['events'][0]);$GLOBALS['view']='home';$GLOBALS['fixture_render_clock']=1791673200;foreach(['admin_status_link_preview_budget','admin_quote_link_preview_budget','admin_library_fetch_budget'] as $key)$GLOBALS[$key]=0;$before=serialize($source);
   $capture=caller_capture(static function()use($source){if(is_array($source))admin_render_masto_status_card($source,[],'home',false,true);});
   if(serialize($source)!==$before)throw new RuntimeException('production projected source mutated');$productionCaptures[]=['width'=>$width,'state'=>$state,'original_events'=>$production['events'],'current_events'=>$pg->query('SELECT * FROM events ORDER BY id')->fetchAll(),'actual_lookup_input'=>$url,'actual_lookup_result'=>$child,'source'=>$source,'direct_authority_allowed'=>is_array($source)?admin_direct_status_allowed($source,1):false,'queue_effects'=>$pg->query('SELECT * FROM ap_media_warm_queue')->fetchAll(),'php'=>$capture];
  }
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$path=$argv[1]??'/tmp/vaak-quote-media-placement-reference.json';file_put_contents($path,json_encode(['checks'=>$checks,'captures'=>$captures,'production_projection_captures'=>$productionCaptures,'current_schema_by_width'=>$schemaByWidth,'bootstrap_tables'=>$tables,'scope'=>'Actual shared PHP full painter under declared fixture inputs/current SQL; genuine helper closure with recorded offline lookup transport. Production rows projected by genuine event converter and renamed-declaration actual SQL lookup; captured child is then supplied through declared cache transport. Not live Home collector or raw production AS2 proof. Envelope-only and pending shapes are diagnostic, not presumed ownership.','substitutions'=>$GLOBALS['fixture_production_semantic_substitutions']],JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE));echo 'quote-media-placement-reference PASS '.$checks.' captures + '.count($productionCaptures).' production projection controls; '.$path."\n";
