<?php
/** Actual shared card/thread painter and durable audience policy, using declared normalized status transport. */
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';
reference_load(dirname(__DIR__).'/ap-db.php',['ap_profile_authorized_badges','ap_profile_supporter_awarded']);
ob_start();require __DIR__.'/migration-card-reference-capture.php';ob_end_clean();
reference_load(dirname(__DIR__).'/ap-visibility.php',['ap_visibility_public_status']);
function ap_masto_status_by_note_id(string $id):?array{$q=ap_db()->prepare('SELECT * FROM masto_statuses WHERE note_id=?');$q->execute([$id]);return $q->fetch()?:null;}
function ap_masto_status_from_row(array $r,bool $quote=true,bool $fetch=false):array{
 $q=ap_db()->prepare('SELECT payload FROM fixture_status_cache WHERE object_id=?');$q->execute([$r['note_id']]);return json_decode((string)$q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
}
function pt_check(bool $v,string $n):void{if(!$v)throw new RuntimeException($n);$GLOBALS['pt_checks'][]=$n;}
$db=ap_db();$db->exec('ALTER TABLE outbox_notes ADD COLUMN in_reply_to TEXT');
$pt_checks=[];$pt_captures=[];
$actor='https://mkultra.monster/users/fixture';$parentId=$actor.'/notes/parent';$tipId=$actor.'/notes/tip';
foreach(['home','local','feed'] as $view)foreach(['own','peer','guest'] as $viewer)foreach(['text','text_media','media_media'] as $media){
 foreach(['public','parent_private','parent_unlisted','parent_deleted','parent_blocked','parent_word','tip_private'] as $policy){
 foreach(['fixture_blocks','fixture_mutes','fixture_words','fixture_server_blocks','events','outbox_notes','masto_statuses','fixture_status_cache','following','followers','mentions'] as $table)$db->exec('DELETE FROM '.$table);
 $GLOBALS['vaak_owner_id']=$viewer==='own'?1:($viewer==='peer'?2:0);$GLOBALS['vaak_user']=$viewer==='guest'?null:['id'=>$GLOBALS['vaak_owner_id']];$GLOBALS['vaak_guest_profile']=$viewer==='guest';
 unset($GLOBALS['ap_masto_flag_memo']);
 $parentVisibility=$policy==='parent_private'?'private':($policy==='parent_unlisted'?'unlisted':'public');$tipVisibility=$policy==='tip_private'?'private':'public';
 $statuses=[];
 foreach([[$parentId,$parentVisibility,'PARENT',$media==='media_media'],[$tipId,$tipVisibility,'TIP',$media!=='text']] as [$id,$visibility,$text,$image]){
  $status=['id'=>$text,'uri'=>$id,'url'=>$id,'created_at'=>'2026-10-11T12:00:00Z','content'=>'<p>'.$text.'</p>','visibility'=>$visibility,'account'=>['id'=>'1','uri'=>$actor,'url'=>$actor,'acct'=>'fixture','display_name'=>'Fixture owner'],'media_attachments'=>$image?[['type'=>'image','url'=>'https://media.test/'.$text.'.webp','preview_url'=>'https://media.test/'.$text.'.webp','description'=>$text.' alt']]:[],'spoiler_text'=>'','sensitive'=>false];
  $statuses[$text]=$status;
  if(!($policy==='parent_deleted'&&$text==='PARENT'))$db->prepare('INSERT INTO outbox_notes(id,content,visibility,to_json,cc_json,in_reply_to) VALUES(?,?,?,?,?,?)')->execute([$id,$status['content'],$visibility,'[]','[]',$text==='TIP'?$parentId:null]);
  $db->prepare('INSERT INTO masto_statuses VALUES(?,?,?,?,?,?)')->execute([$id,$text,$text==='PARENT'?1:2,'',0,$visibility]);
  $db->prepare('INSERT INTO fixture_status_cache VALUES(?,?)')->execute([$id,json_encode($status)]);
 }
 if($policy==='parent_blocked')$db->prepare('INSERT INTO fixture_server_blocks VALUES(?,?,?)')->execute(['actor',$actor,'block']);
 if($policy==='parent_word')$db->prepare('INSERT INTO fixture_words VALUES(?,?)')->execute([$GLOBALS['vaak_owner_id'],'parent']);
 $eligible=($policy==='parent_word'&&$viewer==='guest')||$policy==='public'||($view==='home'&&$policy==='parent_unlisted')||($view==='home'&&$viewer==='own'&&in_array($policy,['parent_private','tip_private'],true));
 // The normalized mapper is declared; complete card bodies/actions are the real shared painter.
 ob_start();$grouped=admin_render_local_self_thread(['id'=>$tipId,'in_reply_to'=>$parentId],$statuses['TIP'],[],$view);$html=(string)ob_get_clean();
 $key="$view/$viewer/$media/$policy";pt_check($grouped===$eligible,$key.' grouping eligibility');pt_check(($html!=='')===$eligible,$key.' no denied parent wrapper');
 if($eligible){pt_check(str_contains($html,'tweet-thread-seg--ancestor')&&str_contains($html,'tweet-thread-seg--tip'),$key.' both segments');pt_check(strpos($html,'PARENT')<strpos($html,'TIP'),$key.' parent before tip');pt_check(substr_count($html,'src="https://media.test/')>=($media==='text'?0:($media==='media_media'?2:1)),$key.' media survives actual painter');}
 $pt_captures[]=['case'=>$key,'grouped'=>$grouped,'html'=>$html];
 }
}
echo json_encode(['passed'=>count($pt_checks),'checks'=>$pt_checks,'captures'=>$pt_captures,'boundary'=>'Actual shared PHP card/thread painter and audience/moderation helpers on isolated SQLite. Normalized status mapper and existing migration-card transport/bootstrap boundaries declared; no full SAPI, producer classification, Rust HTTP or browser integration.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
