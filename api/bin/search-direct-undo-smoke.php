<?php
declare(strict_types=1);
/** Actual Undo writer and canonical Search/direct predicates and card painters. */
putenv('VAAK_SEARCH_NATIVE_RENDER=0');
putenv('VAAK_LIBRARY_NATIVE_RENDER=0');
require_once dirname(__DIR__).'/ap-library-native.php';
ob_start();require __DIR__.'/search-render-reference-capture.php';
$captures=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR)['captures'];
reference_load(dirname(__DIR__).'/ap-db.php',['ap_events_mark_interaction_undone','ap_events_mark_object_deleted','ap_mention_interaction_object_id','ap_mention_soft_delete_interaction']);
$socket=getenv('LIBRARY_FIXTURE_SOCKET');
if($socket){
 $width=getenv('UNDO_FIXTURE_WIDTH')==='BIGINT'?'BIGINT':'INTEGER';$schema='search_direct_undo_'.strtolower($width);
 $sqlite=ap_db();$pg=new PDO('pgsql:host='.$socket.';dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 foreach($sqlite->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table){
  $columns=$sqlite->query('PRAGMA table_info('.$table.')')->fetchAll();$defs=[];
  foreach($columns as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');
  $pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');
  $names=array_column($columns,'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');
  foreach($sqlite->query('SELECT * FROM '.$table)->fetchAll() as $row)$insert->execute(array_values($row));
 }
 $GLOBALS['fixture_db']=$pg;
 register_shutdown_function(static function()use($pg,$schema):void{$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE');});
}
$checks=0;
function undo_check(bool $ok,string $label):void {global $checks;if(!$ok)throw new RuntimeException($label);$checks++;}
function undo_paint(array $status,string $view):string {
 $GLOBALS['view']=$view;ob_start();admin_render_masto_status_card($status,[],$view,false,true);return (string)ob_get_clean();
}
$source=$captures['status_plain']['status'];$source['id']='900';$source['uri']=$source['url']='https://remote.test/undo-original';$source['content']='<p>Live original Undo marker</p>';$source['reblog']=null;$source['quote']=null;
foreach(['fixture_mutes','fixture_blocks','fixture_words','fixture_server_blocks','mentions','events'] as $table)ap_db()->exec('DELETE FROM '.$table);
ap_db()->exec('UPDATE actor_profile SET indexable=1');
ap_db()->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([900,$source['uri'],'Create','log','public',$source['account']['uri']]);
ap_db()->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([901,$source['uri'].'/','Announce','log','public','https://booster.test/users/booster']);
undo_check(ap_search_status_allowed($source,1),'live source Search initially allowed');
undo_check(admin_direct_status_allowed($source,1),'live source direct initially allowed');
undo_check(ap_events_mark_interaction_undone('Announce','https://booster.test/users/booster/',$source['uri'])===1,'actual normal AP Undo writer updates Announce');
$row=ap_db()->query('SELECT type,action_taken,object_id FROM events WHERE id=901')->fetch();
undo_check($row['type']==='Announce'&&$row['action_taken']==='deleted'&&$row['object_id']===$source['uri'].'/','actual writer persists same-target deleted interaction');
if(in_array('--probe-quote',$argv,true)) {
 foreach(['Quote','QuotePost'] as $type) {
  ap_db()->exec('DELETE FROM events WHERE id=902');
  ap_db()->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([902,$source['uri'],$type,'local_observe','public','https://quoter.test/users/quoter']);
  undo_check(ap_events_mark_interaction_undone($type,'https://quoter.test/users/quoter',$source['uri'])===1,'actual '.$type.' Undo writer');
  $rows=ap_db()->query('SELECT id,type,actor_id,object_id,action_taken FROM events ORDER BY id')->fetchAll();
  echo json_encode(['type'=>$type,'rows'=>$rows,'search'=>ap_search_status_allowed($source,1),'direct'=>admin_direct_status_allowed($source,1)],JSON_THROW_ON_ERROR)."\n";
 }
 exit;
}
if(in_array('--probe-quote-audience',$argv,true)) {
 ap_db()->exec('DELETE FROM events WHERE id>=901;DELETE FROM following;DELETE FROM mentions');
 ap_db()->exec("UPDATE events SET visibility='private' WHERE id=900");
 $source['visibility']='private';
 undo_check(!ap_visibility_status_audience_allowed($source,1),'private original initially denied');
 ap_db()->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([902,$source['uri'],'Quote','local_observe','public','https://quoter.test/users/quoter']);
 echo json_encode(['audience'=>ap_visibility_status_audience_allowed($source,1),'search'=>ap_search_status_allowed($source,1),'direct'=>admin_direct_status_allowed($source,1),'search_body'=>str_contains(undo_paint($source,'search'),'Live original Undo marker'),'direct_body'=>str_contains(undo_paint($source,'status'),'Live original Undo marker')],JSON_THROW_ON_ERROR)."\n";
 exit;
}
$before=in_array('--expect-before',$argv,true);
undo_check(ap_search_status_allowed($source,1)===!$before,'Undo does not delete original Search source');
undo_check(admin_direct_status_allowed($source,1)===!$before,'Undo does not delete original direct source');
if(!$before){
 foreach(['search','status','remote_profile'] as $view)undo_check(str_contains(undo_paint($source,$view),'Live original Undo marker'),$view.' original body retained after Undo');
 ap_db()->exec("INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(902,'https://remote.test/undo-original','Like','deleted','public','https://liker.test/users/liker')");
 undo_check(ap_search_status_allowed($source,1)&&admin_direct_status_allowed($source,1),'deleted Like projection is not note deletion');
 foreach(['Create','Update','Delete','Tombstone'] as $type){
  ap_db()->exec('DELETE FROM events WHERE id=903');
  ap_db()->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([903,$source['uri'],$type,in_array($type,['Delete','Tombstone'],true)?'log':'deleted','public',$source['account']['uri']]);
  undo_check(!ap_search_status_allowed($source,1)&&!admin_direct_status_allowed($source,1),'genuine deleted '.$type.' still rejects original');
 }
 foreach(['Quote','QuotePost'] as $type) {
  ap_db()->exec('DELETE FROM events WHERE id>=901');
  ap_db()->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([904,$source['uri'].'/',$type,'local_observe','public','https://quoter.test/users/quoter']);
  undo_check(ap_events_mark_interaction_undone($type,'https://quoter.test/users/quoter',$source['uri'])===1,'actual '.$type.' Undo keeps persisted Create');
  undo_check(ap_search_status_allowed($source,1)&&admin_direct_status_allowed($source,1),'Undo '.$type.' original note allowed');
  foreach(['search','status','remote_profile'] as $view)undo_check(str_contains(undo_paint($source,$view),'Live original Undo marker'),$view.' body after Undo '.$type);
  foreach(['bookmarks','favourites'] as $view){
   $GLOBALS['view']=$view;ob_start();admin_render_library_entries([['kind'=>'masto','item'=>$source]],$view);$html=(string)ob_get_clean();
   undo_check(str_contains($html,'Live original Undo marker'),'Library '.$view.' body after Undo '.$type);
  }
  $parent=$source;$parent['uri']=$parent['url']='https://remote.test/undo-parent';$parent['quote']=['state'=>'accepted','quoted_status'=>$source];
  ap_db()->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([905,$parent['uri'],'Create','log','public',$source['account']['uri']]);
  undo_check(ap_search_status_allowed($parent,1)&&admin_direct_status_allowed($parent,1)&&admin_library_status_not_deleted($parent),'nested Undo '.$type.' source remains live');
  ap_db()->exec('DELETE FROM events WHERE id=900');
  undo_check(!ap_search_status_allowed($source,1)&&!admin_direct_status_allowed($source,1)&&!admin_library_status_not_deleted($source),'legacy Quote-only deleted source still denied');
  ap_db()->exec("UPDATE events SET action_taken='log',visibility='public' WHERE id=904");
  undo_check(ap_search_status_allowed($source,1)&&admin_direct_status_allowed($source,1)&&admin_library_status_not_deleted($source),'legacy Quote-only public source retained');
  ap_db()->exec("UPDATE events SET visibility='private' WHERE id=904");
  undo_check(!ap_visibility_status_audience_allowed($source,1)&&!ap_search_status_allowed($source,1)&&!admin_direct_status_allowed($source,1),'legacy Quote-only private source audience retained');
  ap_db()->exec("UPDATE events SET action_taken='deleted',visibility='public' WHERE id=904");
  ap_db()->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([900,$source['uri'],'Create','log','public',$source['account']['uri']]);
 }
 ap_db()->exec('DELETE FROM events WHERE id>=901;DELETE FROM following;DELETE FROM mentions');
 ap_db()->exec("UPDATE events SET visibility='private' WHERE id=900");
 $private=$source;$private['visibility']='private';
 foreach(['Quote','QuotePost'] as $type){
  ap_db()->exec('DELETE FROM events WHERE id=906');
  ap_db()->prepare('INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(?,?,?,?,?,?)')->execute([906,$source['uri'].'/',$type,'local_observe','public','https://quoter.test/users/quoter']);
  undo_check(!ap_visibility_status_audience_allowed($private,1)&&!ap_search_status_allowed($private,1)&&!admin_direct_status_allowed($private,1),'public '.$type.' does not grant private original audience');
  foreach(['search','status','remote_profile'] as $view)undo_check(!str_contains(undo_paint($private,$view),'Live original Undo marker'),$view.' private original denied beneath public '.$type);
  foreach(['bookmarks','favourites'] as $view){
   $GLOBALS['view']=$view;ob_start();admin_render_library_entries([['kind'=>'masto','item'=>$private]],$view);$html=(string)ob_get_clean();
   undo_check(!str_contains($html,'Live original Undo marker'),'Library '.$view.' private original denied beneath public '.$type);
  }
 }
 ap_db()->exec("DELETE FROM events WHERE id>=901;UPDATE events SET visibility='public' WHERE id=900");
 undo_check(ap_events_mark_object_deleted($source['uri'])===1,'actual object deletion writer persists original withdrawal');
 undo_check(!ap_search_status_allowed($source,1)&&!admin_direct_status_allowed($source,1),'object deletion still rejects original');
}
if(!$before){
 // Notification projection invalidation is a cache IO boundary, not admission policy.
 if(!function_exists('ap_notification_projection_invalidate_all')) {
  function ap_notification_projection_invalidate_all():void {$GLOBALS['undo_notification_invalidations']=($GLOBALS['undo_notification_invalidations']??0)+1;}
 }
 ap_db()->exec('ALTER TABLE mentions ADD COLUMN actor_id TEXT;ALTER TABLE mentions ADD COLUMN activity_id TEXT;DELETE FROM mentions');
 $target='https://mkultra.monster/users/fixture/notes/local-undo';
 foreach(['like'=>'Like','reblog'=>'Announce'] as $kind=>$type){
  ap_db()->exec('DELETE FROM mentions');$stable=ap_mention_interaction_object_id($target,'https://booster.test/users/booster',$kind);
  ap_db()->prepare('INSERT INTO mentions(id,object_id,owner_user_id,activity_type,actor_id,activity_id) VALUES(?,?,?,?,?,?)')->execute([910,$target,1,'Create','https://mkultra.monster/users/fixture','https://mkultra.monster/create/local-undo']);
  ap_db()->prepare('INSERT INTO mentions(id,object_id,owner_user_id,activity_type,actor_id,activity_id) VALUES(?,?,?,?,?,?)')->execute([911,$stable,1,$type,'https://booster.test/users/booster','https://booster.test/activity/interaction']);
  undo_check(ap_mention_soft_delete_interaction('https://booster.test/users/booster',$target,$kind,'https://booster.test/activity/interaction')===1,'actual mention '.$type.' Undo writer touches interaction');
  $rows=ap_db()->query('SELECT id,object_id,deleted_at FROM mentions ORDER BY id')->fetchAll();
  undo_check($rows[0]['deleted_at']===null&&!empty($rows[1]['deleted_at'])&&$rows[1]['object_id']===$stable,'normal stable '.$type.' Undo preserves bare Create source');
 }
}
echo 'search-direct-undo '.($before?'BEFORE REPRO':'PASS').' '.$checks." actual PHP controls\n";
