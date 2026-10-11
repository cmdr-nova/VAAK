<?php
declare(strict_types=1);
/** Actual current PostgreSQL policy and painter regression; no web bootstrap/native. */
$source=(string)file_get_contents(__DIR__.'/remote-direct-card-reference.php');
$end=strpos($source,"\n\$rows=[];\$checks=0;");
if($end===false)throw new RuntimeException('bootstrap boundary missing');
$resolved='';foreach(token_get_all(substr($source,0,$end)) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);
eval(substr($resolved,5));
reference_load(dirname(__DIR__).'/ap-admin.php',['admin_direct_status_content_blocked','admin_direct_event_content_blocked','admin_direct_filter_blocked_statuses','admin_render_direct_status_card']);
$checks=0;
$assert=static function(bool $ok,string $message)use(&$checks):void{if(!$ok)throw new RuntimeException($message);$checks++;};
foreach(['INTEGER','BIGINT'] as $width){
 $schema='direct_partial_blocks_smoke_'.strtolower($width);
 $pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{
  foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}
  $GLOBALS['fixture_db']=$pg;
  foreach(['none','personal_block','server_block','personal_mute','server_mute','word'] as $rule){
   $status=direct_source($corpus['status_plain']['context']);direct_bind(1);$GLOBALS['view']='status';$_GET=[];
   $actor=$status['account']['uri'];
   if($rule==='personal_block')$pg->prepare('INSERT INTO fixture_blocks VALUES(?,?,?)')->execute([1,'actor',$actor]);
   if($rule==='personal_mute')$pg->prepare('INSERT INTO fixture_mutes VALUES(?,?)')->execute([1,$actor]);
   if($rule==='word')$pg->prepare('INSERT INTO fixture_words VALUES(?,?)')->execute([1,ap_muted_words_normalize_haystack('Direct policy marker')]);
   if(str_starts_with($rule,'server_'))$pg->prepare('INSERT INTO fixture_server_blocks VALUES(?,?,?)')->execute(['actor',$actor,$rule==='server_block'?'block':'mute']);
   $blocked=in_array($rule,['personal_block','server_block'],true);
   $assert(admin_direct_status_content_blocked($status)===$blocked,"status $width/$rule");
   $assert(admin_direct_event_content_blocked(['actor_id'=>$actor.'/'])===$blocked,"event $width/$rule");
   $urlOnly=$status;$urlOnly['account']['uri']='';$urlOnly['account']['url']=$actor.'/';
   $assert(admin_direct_status_content_blocked($urlOnly)===$blocked,"URI fallback $width/$rule");
   $other=$status;$other['account']['uri']='https://unblocked.test/users/other';$other['id']='other';
   $filtered=admin_direct_filter_blocked_statuses([$other,$status,$other]);
   $assert(array_column($filtered,'id')===($blocked?['other','other']:['other',$status['id'],'other']),"ordered/count collection $width/$rule");
   foreach(['home','search','bookmarks','favourites'] as $origin){
    $capture=caller_capture(static function()use($status,$origin){admin_render_direct_status_card($status,[],$origin,true,false);});
    $assert(str_contains($capture['html'],'Direct policy marker')===!$blocked,"actual painter $width/$rule/$origin");
   }
   $pg->exec('DELETE FROM fixture_blocks;DELETE FROM fixture_server_blocks');
   $assert(!admin_direct_status_content_blocked($status),"withdrawal current lookup $width/$rule");
  }
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
echo "direct-partial-blocks-smoke PASS $checks checks\n";
