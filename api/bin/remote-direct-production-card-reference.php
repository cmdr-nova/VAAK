<?php
declare(strict_types=1);
/** Re-capture immutable primary sources under genuine production declarations.
 * Original diagnostic artifacts are inputs only, never expected-output patches.
 */
putenv('DIRECT_PRODUCTION_HELPERS=1');
$setup=(string)file_get_contents(__DIR__.'/remote-direct-policy-independent.php');
$end=strpos($setup,'$captures=[];$checks=0;');if($end===false)throw new RuntimeException('setup boundary');
$resolved='';foreach(token_get_all(substr($setup,0,$end)) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);eval(substr($resolved,5));
$original=json_decode((string)file_get_contents('/tmp/vaak-remote-direct-card-reference.json'),true,512,JSON_THROW_ON_ERROR);
$captures=[];$checks=0;
foreach(['INTEGER','BIGINT'] as $width){
 $schema='remote_direct_production_cards_'.strtolower($width);$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{
  foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}
  $GLOBALS['fixture_db']=$pg;
  foreach($original['captures'] as $old){
   if($old['width']!==$width)continue;
   if(!isset($old['source'])){$captures[]=$old+['production_helper_recaptured'=>false];continue;}
   caller_restore($old['authoritative_context']);direct_production_prepare_moderation();
   $options=$old['context'];direct_bind($options['owner']);$GLOBALS['view']=$options['route'];$GLOBALS['admin_bookmark_lazy_media']=$options['lazy_media'];$_GET=isset($options['get_actor'])?['actor'=>$options['get_actor']]:[];
   $GLOBALS['fixture_render_clock']=$options['rendered_at'];
   foreach(['fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','fixture_redis_connections','fixture_status_lookup_requests'] as $key)$GLOBALS[$key]=[];
   unset($GLOBALS['admin_status_link_preview_budget']);
   $source=$old['source'];$before=json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
   $php=caller_capture(static function()use($source,$options){admin_render_masto_status_card($source,[],$options['return_view'],$options['focused'],$options['show_open']??true,$options['hide_header']??false);});
   $php['effects']['status_link_preview_budget']=['present'=>array_key_exists('admin_status_link_preview_budget',$GLOBALS),'value'=>$GLOBALS['admin_status_link_preview_budget']??null,'type'=>get_debug_type($GLOBALS['admin_status_link_preview_budget']??null)];
   foreach(['fixture_actor_warm_requests','fixture_media_warm_requests','fixture_quote_warm_requests','fixture_redis_connections','fixture_status_lookup_requests'] as $key)$php['effects'][$key]=$GLOBALS[$key];
   if($before!==json_encode($source,JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR))throw new RuntimeException('source mutated');$checks++;
   $current=[];foreach($pg->query('SELECT table_name FROM information_schema.tables WHERE table_schema=current_schema()')->fetchAll(PDO::FETCH_COLUMN) as $table)$current[$table]=$pg->query('SELECT * FROM '.$table)->fetchAll();
   $row=$old;$row['php']=$php;$row['forms']=direct_reference_forms($php['html']);$row['current_tables']=$current;$row['production_helper_recaptured']=true;$row['fixture_lookup_boundary_exercised']=!empty($php['effects']['fixture_status_lookup_requests']);$row['diagnostic_original_php_sha256']=hash('sha256',json_encode($old['php'],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));$captures[]=$row;
  }
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$path=$argv[1]??'/tmp/vaak-remote-direct-production-card-reference.json';
$sourceHashes=[];foreach($GLOBALS['fixture_production_helper_files'] as $file)$sourceHashes[$file]=hash_file('sha256',dirname(__DIR__).'/'.$file);
file_put_contents($path,json_encode(['checks'=>$checks,'captures'=>$captures,'bootstrap_tables'=>$tables,'loaded_genuine_helpers'=>$GLOBALS['fixture_production_helper_declarations'],'semantic_substitutions'=>$GLOBALS['fixture_production_semantic_substitutions'],'production_source_hashes'=>$sourceHashes,'declared_warm_actor_ids'=>$GLOBALS['fixture_declared_warm_actor_ids']??[],'declared_extra_transports'=>$GLOBALS['fixture_production_external_transports'],'clock_source'=>'immutable original context rendered_at','static_memo_scope'=>'single CLI fixture process; first avatar warm reads declared initial SQL; separate request-global relationship memo reset; administrator/global menu state immutable in primary captures','guest_recaptured'=>false,'native_parity_proven'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo 'remote-direct-production-card-reference PASS '.$checks.' immutable source checks; '.count($captures).' rows (guest2 retained diagnostic); '.$path."\n";
