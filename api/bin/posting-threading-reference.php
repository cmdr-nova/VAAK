<?php
/** Actual publication/wrapper/storage/metrics declarations; declared fanout and remote transports. */
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';
const LOCAL_ACTOR='https://mkultra.monster/users/a';
function ap_db():PDO{return $GLOBALS['db'];}
function ap_db_now():string{return gmdate('c');}
function ap_db_last_insert_id(string $table,string $column='id'):int{if(ap_db()->getAttribute(PDO::ATTR_DRIVER_NAME)==='pgsql'){return (int)ap_db()->query("SELECT currval(pg_get_serial_sequence('$table','$column'))")->fetchColumn();}return (int)ap_db()->lastInsertId();}
function ap_local_actor_id():string{return LOCAL_ACTOR;}
function vaak_actor_id():string{return LOCAL_ACTOR;}
function ap_fix_utf8(string $v):string{return $v;}
function ap_log(string $v):void{$GLOBALS['log'][]=$v;}
function ap_short(string $v):string{return $v;}
function ap_link_preview_extract_url(string $v):?string{return null;}
function ap_plain_text_to_html(string $v):string{return '<p>'.htmlspecialchars($v).'</p>';}
function ap_html_to_plain_text(string $v):string{return strip_tags($v);}
function ap_home_recommendation_record_publication(array $n,array $d):void{}
function ap_masto_content_with_mentions(string $v,array $a=[]):array{return ['content'=>ap_plain_text_to_html($v),'mentions'=>[]];}
function ap_note_interaction_policy_public():array{return [];}
function ap_create_finalize(array $v):array{return $v;}
function ap_media_by_local_ids(array $ids):array{return array_map(fn($id)=>['local_id'=>$id,'url'=>'https://media.test/'.$id.'.webp'],$ids);}
function ap_media_as2_attachments(array $rows):array{return array_map(fn($r)=>['type'=>'Document','mediaType'=>'image/webp','url'=>$r['url']],$rows);}
function ap_media_attach_to_status(array $ids,int $id):void{foreach($ids as $mid)ap_db()->prepare('INSERT INTO media_attached VALUES (?,?)')->execute([$mid,$id]);}
function ap_db_owner_user_id_for_actor(string $a):int{return $a===LOCAL_ACTOR?201:0;}
function ap_timeline_fanout_local_status(int $owner,array $entry,array $views):void{
 try{$deliveryRows=(int)ap_db()->query('SELECT COUNT(*) FROM ap_publish_delivery_queue')->fetchColumn();}catch(Throwable){$deliveryRows=-1;}
 $GLOBALS['fanout'][]=['kind'=>'own','owner'=>$owner,'views'=>$views,'entry'=>$entry,'delivery_rows'=>$deliveryRows];
 if($GLOBALS['fanout_throw'])throw new RuntimeException('declared fanout failure');
}
function ap_timeline_notify(mixed ...$a):void{}
function ap_timeline_actor_is_local(string $a):bool{return str_starts_with($a,'https://mkultra.monster/users/');}
function ap_timeline_fanout_followers_home(int $id,string $type,string $actor):void{$GLOBALS['fanout'][]=['kind'=>'followers','id'=>$id];}
function ap_timeline_fanout_public_local_feed(int $id,string $type,string $actor,string $visibility):void{$GLOBALS['fanout'][]=['kind'=>'public','id'=>$id,'visibility'=>$visibility];}
function ap_deliver_public_activity(array $a,array $p,bool $pending):array{$GLOBALS['remote']++;return ['delivered'=>0,'queued'=>1,'bridgy'=>false];}
function ap_deliver_followers_activity(array $a,array $p):array{$GLOBALS['remote']++;return ['delivered'=>0,'queued'=>1,'bridgy'=>false];}
function ap_bsky_crosspost_status(mixed ...$a):array{return ['ok'=>true,'skipped'=>true];}
function check(bool $v,string $label):void{if(!$v)throw new RuntimeException($label);$GLOBALS['checks'][]=$label;}
$before=getenv('POST_REFERENCE_BEFORE')==='1';$dir=dirname(__DIR__);$sourceDir=(string)getenv('POST_REFERENCE_SOURCE_DIR');
if($sourceDir==='')throw new RuntimeException('Supply frozen isolated source directory with declared transport include shims');
// Load actual delivery declarations before extraction. exec/shell_exec disabled by runner.
require $dir.'/ap-publish-delivery.php';
reference_load($sourceDir.'/ap-db.php',['ap_normalize_visibility','ap_outbox_store','ap_masto_status_register','ap_masto_status_by_note_id','ap_metrics_record']);
reference_load($sourceDir.'/ap-inbox.php',['ap_publish_status_text']);
reference_load($sourceDir.'/ap-admin.php',['ap_local_post_reply']);
$checks=[];$captures=[];
foreach(['sqlite','pgsql-integer','pgsql-bigint'] as $driver){
foreach(['public','unlisted','private','local'] as $visibility){foreach($before?['queue','queue_missing']:['queue','queue_missing','fanout_failure'] as $mode){foreach([false,true] as $media){
 $db=$driver==='sqlite'?new PDO('sqlite::memory:'):new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
 $schema='posting_threading_'.getmypid().'_'.str_replace('-','_',$driver);if($driver!=='sqlite'){$db->exec('CREATE SCHEMA '.$schema);$db->exec('SET search_path TO '.$schema);}
 $pk=$driver==='sqlite'?'INTEGER PRIMARY KEY AUTOINCREMENT':($driver==='pgsql-bigint'?'BIGSERIAL PRIMARY KEY':'SERIAL PRIMARY KEY');
 $db->exec("CREATE TABLE outbox_notes(id TEXT PRIMARY KEY,create_id TEXT,published TEXT,content TEXT,in_reply_to TEXT,to_json TEXT,cc_json TEXT,raw_create_json TEXT,kind TEXT,visibility TEXT);
 CREATE TABLE masto_statuses(local_id $pk,note_id TEXT,create_id TEXT,published TEXT,content_text TEXT,in_reply_to_local_id INTEGER,visibility TEXT,spoiler_text TEXT,sensitive INTEGER);
 CREATE TABLE media_attached(media_id INTEGER,status_id INTEGER);
 CREATE TABLE events(id $pk,created_at TEXT,type TEXT,actor_id TEXT,object_id TEXT,target_actor TEXT,host TEXT,bytes INTEGER,action_taken TEXT,summary TEXT,media_urls TEXT,in_reply_to TEXT,spoiler_text TEXT,sensitive INTEGER,visibility TEXT);
 CREATE TABLE ap_publish_delivery_queue(owner_user_id INTEGER,note_id TEXT UNIQUE,payload_json TEXT,status TEXT,priority INTEGER,attempts INTEGER,next_attempt_at TEXT,created_at TEXT,updated_at TEXT);");
 if($mode==='queue_missing')$db->exec('DROP TABLE ap_publish_delivery_queue');
 $fanout=[];$remote=0;$fanout_throw=$mode==='fanout_failure';$log=[];
 // Seed a genuine stored local parent through the actual storage declarations.
 ap_outbox_store(['id'=>LOCAL_ACTOR.'/notes/parent','create_id'=>'parent-create','published'=>gmdate('c'),'content'=>'<p>parent</p>','visibility'=>$visibility]);
 ap_masto_status_register(LOCAL_ACTOR.'/notes/parent','parent-create',gmdate('c'),'parent',null,'',false,$visibility);
 $res=ap_local_post_reply($media?'text + media':'text',LOCAL_ACTOR.'/notes/parent',null,'',false,null,$media?[7]:[],$visibility);
 $key="$driver/$visibility/$mode/".($media?'media':'text');
 check(!empty($res['ok']),$key.' publication succeeds');check((int)$db->query('SELECT COUNT(*) FROM outbox_notes')->fetchColumn()===2,$key.' exactly one new note');
 check((int)$db->query('SELECT COUNT(*) FROM masto_statuses')->fetchColumn()===2,$key.' exactly one new status');
 $actual=(string)$db->query("SELECT visibility FROM outbox_notes WHERE id <> '".LOCAL_ACTOR."/notes/parent'")->fetchColumn();check($actual===$visibility,$key.' parent audience preserved');
 check((int)$db->query('SELECT COUNT(*) FROM media_attached')->fetchColumn()===($media?1:0),$key.' media attached once');
 $own=array_values(array_filter($fanout,fn($r)=>$r['kind']==='own'));
 if(!$before){check(count($own)===1,$key.' own fanout attempted exactly once');check($own[0]['views']===($visibility==='public'?['home','local']:['home']),$key.' discovery scope preserved');
 check(count(array_filter($fanout,fn($r)=>$r['kind']==='public'))===($visibility==='public'?1:0),$key.' public peer fanout guard');}
 elseif($mode==='queue'){check(count($own)===0,$key.' BEFORE delivery early return skips own fanout');}
 if($mode!=='queue_missing'){check((int)$db->query('SELECT COUNT(*) FROM ap_publish_delivery_queue')->fetchColumn()===1,$key.' delivery durable');check($remote===0,$key.' no remote delivery on critical path');}
 $captures[]=['case'=>$key,'own_fanout'=>$own,'fanout'=>$fanout,'remote_calls'=>$remote,'delivery_pending'=>$res['delivery_pending']??false,'visibility'=>$actual];
 if($driver!=='sqlite'){$db->exec('SET search_path TO public');$db->exec('DROP SCHEMA '.$schema.' CASCADE');}
}}}}
echo json_encode(['before'=>$before,'passed'=>count($checks),'checks'=>$checks,'captures'=>$captures,'boundary'=>'Include shims replace optional preview/bootstrap imports; actual PHP wrapper/publication/outbox/status/metrics/delivery enqueue SQL on isolated SQLite and PostgreSQL INTEGER/BIGINT schemas; declared actor/profile/media/mention formatting/fanout and remote delivery transports. No full SAPI, actual Rust HTTP or production mutation.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
