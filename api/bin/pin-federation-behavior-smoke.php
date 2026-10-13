#!/usr/bin/env php
<?php
declare(strict_types=1);
// Actual storage/collection/queue producer functions; in-memory owned PDO and captured transport only.
$source=file_get_contents(dirname(__DIR__).'/ap-db.php');
foreach(['ap_masto_status_is_pinned','ap_masto_pinned_statuses','ap_masto_featured_note_ids','ap_masto_pin_is_federatable','ap_masto_pin_federate','ap_masto_status_pin','ap_masto_status_unpin'] as $name){
 if(!preg_match('/^function '.preg_quote($name,'/').'\([^\n]*\).*?^\}/ms',$source,$m))throw new RuntimeException('missing '.$name);
 eval($m[0]);
}
function ap_db():PDO{return $GLOBALS['db'];}
function ap_db_default_owner_user_id():int{return $GLOBALS['owner'];}
function ap_local_actor_id():string{return 'https://mkultra.monster/users/'.($GLOBALS['owner']===201?'alice':'bob');}
function ap_db_owner_user_id_for_actor(string $key):int{return ['alice'=>201,'bob'=>202][$key]??0;}
function ap_masto_status_by_local_id(int $id):?array{$s=ap_db()->prepare('SELECT * FROM masto_statuses WHERE local_id=?');$s->execute([$id]);return $s->fetch(PDO::FETCH_ASSOC)?:null;}
function ap_outbound_identity():array{return ['key_id'=>ap_local_actor_id().'#main-key','priv'=>'fixture-no-real-key'];}
function ap_followers_list(string $actor):array{return [['actor_id'=>'https://remote.test/users/follower','inbox'=>'https://remote.test/inbox'],['actor_id'=>'https://brid.gy/users/follower','inbox'=>'https://brid.gy/inbox']];}
function ap_known_shared_inboxes(int $n):array{return ['https://unrelated.test/inbox'];}
function ap_delivery_targets_filtered(iterable $rows,iterable $extra=[]):array{return array_merge(array_column(iterator_to_array((function()use($rows){yield from $rows;})()),'inbox'),(array)$extra);}
function ap_deliver_fanout_background(array $a,array $targets,string $key,string $priv):bool{$GLOBALS['queued'][]=[$a,$targets,$key];return true;}
function ap_log(string $s):void{throw new RuntimeException($s);}
$GLOBALS['owner']=201;$GLOBALS['queued']=[];$GLOBALS['db']=new PDO('sqlite::memory:');ap_db()->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
ap_db()->exec('CREATE TABLE masto_statuses(local_id INTEGER PRIMARY KEY,note_id TEXT,visibility TEXT);CREATE TABLE masto_pins(owner_user_id INTEGER,status_local_id INTEGER,pinned_at TEXT);CREATE TABLE outbox_notes(id TEXT,visibility TEXT)');
foreach([1=>'public',2=>'unlisted',3=>'private',4=>'direct',5=>'local',6=>'public'] as $id=>$vis){$url='https://mkultra.monster/users/'.($id===6?'bob':'alice').'/notes/'.$id;ap_db()->prepare('INSERT INTO masto_statuses VALUES(?,?,?)')->execute([$id,$url,$vis]);ap_db()->prepare('INSERT INTO outbox_notes VALUES(?,?)')->execute([$url,$id===5?'local':$vis]);}
$n=0;function check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['n']++;}
check(ap_masto_status_pin(1)['ok'],'public pin');check(count($GLOBALS['queued'])===1,'Add queued');$add=$GLOBALS['queued'][0];check($add[0]['type']==='Add'&&$add[0]['object']==='https://mkultra.monster/users/alice/notes/1'&&$add[0]['target']==='https://mkultra.monster/users/alice/collections/featured','exact references');check($add[1]===['https://remote.test/inbox'],'followers and no Bridgy');
ap_masto_status_pin(1);check(count($GLOBALS['queued'])===1,'repeat pin no duplicate');check(ap_masto_status_pin(2)['ok'],'unlisted pin');check(count(ap_masto_featured_note_ids('alice'))===2,'public and unlisted featured');
check(ap_masto_status_pin(3)['ok'],'private local pin');check(!ap_masto_status_pin(4)['ok'],'direct rejected');check(ap_masto_status_pin(5)['ok'],'local pin retained');check(!ap_masto_status_pin(6)['ok'],'other owner rejected');check(count($GLOBALS['queued'])===2,'nonpublic never queued');
$GLOBALS['owner']=0;check(count(ap_masto_featured_note_ids('alice'))===2,'guest explicit actor collection');$GLOBALS['owner']=201;
ap_db()->exec("UPDATE outbox_notes SET visibility='private' WHERE id LIKE '%/notes/1'");check(ap_masto_featured_note_ids('alice')===['https://mkultra.monster/users/alice/notes/2'],'current authoritative privacy');ap_db()->exec("UPDATE outbox_notes SET visibility='public' WHERE id LIKE '%/notes/1'");
ap_masto_status_unpin(1);check(count($GLOBALS['queued'])===3&&$GLOBALS['queued'][2][0]['type']==='Remove','Remove queued');ap_masto_status_unpin(1);check(count($GLOBALS['queued'])===3,'repeat unpin no duplicate');ap_masto_status_unpin(3);check(count($GLOBALS['queued'])===3,'private unpin no emission');
$GLOBALS['owner']=202;check(ap_masto_status_pin(6)['ok'],'second actor pin');check(ap_masto_featured_note_ids('bob')===['https://mkultra.monster/users/bob/notes/6'],'actor scoped featured');check($GLOBALS['queued'][3][0]['actor']===ap_local_actor_id(),'second actor signature context');
printf("pin-federation-behavior: PASS (%d checks; captured transport, no network)\n",$n);
