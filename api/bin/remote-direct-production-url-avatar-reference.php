<?php
declare(strict_types=1);
/** Actual canonical link/constant/SQL-avatar controls, independent of Rust. */
putenv('DIRECT_PRODUCTION_HELPERS=1');
$setup=(string)file_get_contents(__DIR__.'/remote-direct-policy-independent.php');$end=strpos($setup,'$captures=[];$checks=0;');$resolved='';foreach(token_get_all(substr($setup,0,$end)) as $token)$resolved.=is_array($token)&&$token[0]===T_DIR?var_export(__DIR__,true):(is_array($token)?$token[1]:$token);eval(substr($resolved,5));
$captures=[];$checks=0;
$inputs=[
 'ordinary_pretty'=>['https://remote.test/users/alice/statuses/123','https://remote.test/@alice/123'],
 'ordinary_array_hints'=>['https://remote.test/objects/1',['https://remote.test/@alice/1',['href'=>'https://remote.test/posts/1']]],
 'empty_object_hint'=>['','https://remote.test/pretty/1'],
 'empty_object_bsky_hint'=>['','https://bsky.app/profile/did:plc:example/post/abc123'],
 'bridgy_redirect'=>['https://bsky.brid.gy/r/https://bsky.app/profile/alice.test/post/abc123',null],
 'bridgy_convert'=>['https://bsky.brid.gy/convert/ap/at://did:plc:example/app.bsky.feed.post/abc123',null],
 'embedded_at'=>['https://bridge.test/at://did:plc:example/app.bsky.feed.post/abc123',null],
 'array_special_hint'=>['https://ordinary.test/objects/1',[['href'=>'https://bsky.app/profile/alice.test/post/abc123']]],
 'threads_at'=>['https://threads.net/@alice/post/xyz',null],
 'threads_encoded_at'=>['https://www.threads.com/@caf%C3%A9/post/xyz',null],
 'threads_numeric_cached'=>['https://threads.net/ap/users/901/post/1',null],
 'threads_numeric_cold'=>['https://threads.net/ap/users/902/post/1',null],
 'non_https'=>['at://did:plc:example/app.bsky.feed.post/abc123',null],
];
foreach(['INTEGER','BIGINT'] as $width){$schema='remote_direct_url_avatar_'.strtolower($width);$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{
  foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}
  $GLOBALS['fixture_db']=$pg;$pg->prepare('INSERT INTO remote_actors(actor_id,username,host,updated_at) VALUES(?,?,?,?)')->execute(['https://threads.net/ap/users/901','current.person','threads.net','2026-10-10T15:00:00Z']);unset($GLOBALS['ap_remote_actor_memo'],$GLOBALS['vaak_ap_actor_memo']);
  foreach($inputs as $case=>[$object,$hint]){$source=['object'=>$object,'url_hint'=>$hint];$before=json_encode($source,JSON_THROW_ON_ERROR);$result=ap_remote_object_web_url($object,$hint);if($before!==json_encode($source,JSON_THROW_ON_ERROR))throw new RuntimeException('helper source mutated');$checks++;$captures[]=['width'=>$width,'case'=>$case,'kind'=>'remote_object_web_url','source'=>$source,'php'=>$result,'remote_actor_rows'=>$pg->query('SELECT * FROM remote_actors')->fetchAll()];}
  foreach(['cold'=>'https://avatar-'.$width.'.test/users/cold','warm'=>'https://avatar-'.$width.'.test/users/warm','local'=>'https://mkultra.monster/users/local_'.$width,'unrecognized_alert_actor'=>'https://wafrn-approvals.mkultra.monster/actor','synthetic_wafrn'=>'https://app.wafrn.net/fediverse/blog/admin'] as $case=>$actor){
   if($case==='warm')$pg->prepare('INSERT INTO remote_media_cache(actor_id,kind,public_url) VALUES(?,?,?)')->execute([$actor,'avatar','https://cache.test/actual-current-'.$width.'.webp']);
   unset($GLOBALS['ap_remote_media_memo'],$GLOBALS['ap_remote_actor_memo'],$GLOBALS['vaak_ap_actor_memo']);$GLOBALS['fixture_actor_warm_requests']=[];$GLOBALS['fixture_media_warm_requests']=[];
   $result=admin_avatar_img($actor);$captures[]=['width'=>$width,'case'=>$case,'kind'=>'avatar','source'=>['actor'=>$actor],'php'=>$result,'effects'=>['actor_warm'=>$GLOBALS['fixture_actor_warm_requests'],'media_warm'=>$GLOBALS['fixture_media_warm_requests']],'current_media'=>$pg->query('SELECT * FROM remote_media_cache')->fetchAll()];$checks++;
  }
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$path=$argv[1]??'/tmp/vaak-remote-direct-production-url-avatar-reference.json';file_put_contents($path,json_encode(['checks'=>$checks,'captures'=>$captures,'public_constants'=>['AP_REMOTE_AVATAR_FALLBACK'=>AP_REMOTE_AVATAR_FALLBACK,'AP_LOCAL_AVATAR_FALLBACK'=>AP_LOCAL_AVATAR_FALLBACK,'AP_WAFRN_APPROVALS_AVATAR'=>AP_WAFRN_APPROVALS_AVATAR,'LOCAL_ACTOR'=>LOCAL_ACTOR],'native_parity'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));echo 'remote-direct-production-url-avatar-reference PASS '.$checks.' actual controls; '.$path."\n";
