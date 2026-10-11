<?php
/** Actual current SQL head selector with declared moderation/preprocessing/empty remote transports. */
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';
function ap_db():PDO{return $GLOBALS['db'];}
function admin_owner_user_id():int{return 201;}
function vaak_is_local_url(string $v):bool{return str_starts_with($v,'https://mkultra.monster/users/');}
function admin_timeline_row_hidden(array $r,int $owner):bool{return false;}
function admin_peertube_prefer_create(array $r):array{return $r;}
function admin_timeline_item_muted_by_words(array $r):bool{return false;}
function ap_masto_reblog_rows(mixed ...$args):array{return [];}
function newer_check(bool $v,string $n):void{if(!$v)throw new RuntimeException($n);$GLOBALS['checks'][]=$n;}
$source=getenv('NEWER_REFERENCE_SOURCE')?:dirname(__DIR__).'/ap-admin.php';$before=getenv('NEWER_REFERENCE_BEFORE')==='1';
reference_load(dirname(__DIR__).'/ap-db.php',['ap_normalize_visibility']);
reference_load($source,['admin_tl_fetch_newer','admin_local_timeline_item_allowed','admin_federated_timeline_item_allowed','admin_outbox_is_bsky_import']);
$checks=[];$captures=[];
foreach(['sqlite','pgsql-integer','pgsql-bigint'] as $driver){
 $db=$driver==='sqlite'?new PDO('sqlite::memory:'):new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
 $schema='posting_threading_newer_'.getmypid().'_'.str_replace('-','_',$driver);if($driver!=='sqlite'){$db->exec('CREATE SCHEMA '.$schema);$db->exec('SET search_path TO '.$schema);}
 $width=$driver==='pgsql-bigint'?'BIGINT':'INTEGER';$db->exec("CREATE TABLE outbox_notes(id TEXT,published TEXT,visibility TEXT,in_reply_to TEXT,kind TEXT);
 CREATE TABLE events(id $width,type TEXT,actor_id TEXT,object_id TEXT,summary TEXT,media_urls TEXT,created_at TEXT,action_taken TEXT,spoiler_text TEXT,sensitive INTEGER,visibility TEXT,host TEXT,in_reply_to TEXT);");
 $GLOBALS['vaak_actor_id']='https://mkultra.monster/users/a';$clock=time();$now=gmdate('c',$clock);
 foreach(['public','unlisted','private','local'] as $visibility){$id='https://mkultra.monster/users/a/notes/'.$visibility;$db->prepare('INSERT INTO outbox_notes VALUES(?,?,?,?,?)')->execute([$id,$now,$visibility,null,'compose']);}
 $peerId='https://mkultra.monster/users/peer/notes/public';$db->prepare('INSERT INTO events VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([1,'Create','https://mkultra.monster/users/peer',$peerId,'peer','[]',$now,'compose','',0,'public','mkultra.monster',null]);
 $privatePeer='https://mkultra.monster/users/peer/notes/private';$db->prepare('INSERT INTO events VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?)')->execute([2,'Create','https://mkultra.monster/users/peer',$privatePeer,'secret','[]',$now,'compose','',0,'private','mkultra.monster',null]);
 foreach(['home','local'] as $view){$slice=admin_tl_fetch_newer($view,[],$clock,20);$ids=array_map(fn($r)=>$r['row']['object_id']??$r['row']['id'],$slice);sort($ids);$captures[]=['driver'=>$driver,'view'=>$view,'clock'=>$clock,'ids'=>$ids];
 if(!$before){$expected=$view==='local'?['https://mkultra.monster/users/a/notes/public',$peerId]:['https://mkultra.monster/users/a/notes/local','https://mkultra.monster/users/a/notes/private','https://mkultra.monster/users/a/notes/public','https://mkultra.monster/users/a/notes/unlisted',$peerId];sort($expected);newer_check($ids===$expected,$driver.'/'.$view.' same-second durable source membership');newer_check(!in_array($privatePeer,$ids,true),$driver.'/'.$view.' peer private excluded');}
 else{newer_check($ids===[],$driver.'/'.$view.' BEFORE same-second own and compose peer omitted');}
 }
 if($driver!=='sqlite'){$db->exec('SET search_path TO public');$db->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
echo json_encode(['passed'=>count($checks),'before'=>$before,'checks'=>$checks,'captures'=>$captures,'boundary'=>'Actual PHP newer SQL and Local/Feed source selectors on SQLite and PG INTEGER/BIGINT. Moderation/preprocessing and no remote rows declared; actual audience/moderation painter exercised separately. No full SAPI/Rust HTTP.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
