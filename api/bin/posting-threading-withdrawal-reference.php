<?php
/** Actual shared card/thread painter and durable audience policy, using declared normalized status transport. */
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';
reference_load(dirname(__DIR__).'/ap-db.php',['ap_profile_authorized_badges','ap_profile_supporter_awarded']);
ob_start();require __DIR__.'/migration-card-reference-capture.php';ob_end_clean();
reference_load(dirname(__DIR__).'/ap-visibility.php',['ap_visibility_public_status']);
function ap_masto_status_by_note_id(string $id):?array{$q=ap_db()->prepare('SELECT * FROM masto_statuses WHERE note_id=?');$q->execute([$id]);return $q->fetch()?:null;}
function ap_masto_status_from_row(array $r,bool $quote=true,bool $fetch=false):array{
 if (!empty($GLOBALS['withdraw_on_parent']) && str_ends_with($r['note_id'],'/parent')) {
  $GLOBALS['withdraw_on_parent']=false;$m=$GLOBALS['mutation'];
  if(str_starts_with($m,'late_fallback'))ap_db()->prepare('DELETE FROM outbox_notes WHERE id=?')->execute([$GLOBALS['parentId']]);
  if(in_array($m,['tip_private','tip_unlisted','parent_private','quote_private'],true)){
   $id=str_starts_with($m,'parent')?$GLOBALS['parentId']:(str_starts_with($m,'quote')?$GLOBALS['quoteId']:$GLOBALS['tipId']);$vis=$m==='tip_unlisted'?'unlisted':'private';ap_db()->prepare('UPDATE outbox_notes SET visibility=? WHERE id=?')->execute([$vis,$id]);
  }
  if(in_array($m,['tip_deleted','parent_deleted','quote_deleted'],true)){
   $id=str_starts_with($m,'parent')?$GLOBALS['parentId']:(str_starts_with($m,'quote')?$GLOBALS['quoteId']:$GLOBALS['tipId']);ap_db()->prepare('DELETE FROM outbox_notes WHERE id=?')->execute([$id]);
  }
  if(in_array($m,['tip_word','parent_word'],true))ap_db()->prepare('INSERT INTO fixture_words VALUES(?,?)')->execute([$GLOBALS['vaak_owner_id'],$m==='tip_word'?'tip':'parent']);
  if($m==='tip_block')ap_db()->prepare('INSERT INTO fixture_server_blocks VALUES(?,?,?)')->execute(['actor','https://mkultra.monster/users/fixture','block']);
 }
 $q=ap_db()->prepare('SELECT payload FROM fixture_status_cache WHERE object_id=?');$q->execute([$r['note_id']]);return json_decode((string)$q->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
}
function pt_check(bool $v,string $n):void{if(!$v)throw new RuntimeException($n);$GLOBALS['pt_checks'][]=$n;}
class PostingWithdrawalStatement extends PDOStatement {
 protected function __construct() {}
 public function execute(?array $params=null): bool {
  if(str_contains($this->queryString,'SELECT raw_create_json FROM outbox_notes')
   &&($params[0]??'')===($GLOBALS['tipId']??'')&&!($GLOBALS['late_mutated']??false)
   &&str_starts_with($GLOBALS['mutation']??'','late_')) {
   $m=$GLOBALS['mutation'];$GLOBALS['late_mutated']=true;
   if(str_ends_with($m,'private'))ap_db()->prepare("UPDATE outbox_notes SET visibility='private' WHERE id=?")->execute([$GLOBALS['tipId']]);
   if(str_ends_with($m,'deleted'))ap_db()->prepare('DELETE FROM outbox_notes WHERE id=?')->execute([$GLOBALS['tipId']]);
   if(str_ends_with($m,'word'))ap_db()->prepare('INSERT INTO fixture_words VALUES(?,?)')->execute([$GLOBALS['vaak_owner_id'],'tip']);
  }
  return parent::execute($params);
 }
}
$db=ap_db();$db->exec('ALTER TABLE outbox_notes ADD COLUMN in_reply_to TEXT');
if(getenv('POSTING_WITHDRAWAL_PG')==='1'){
 $legacy=$db;$db=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket user=postgres dbname=postgres',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
 $db->exec('DROP SCHEMA IF EXISTS posting_threading_withdrawal CASCADE');$db->exec('CREATE SCHEMA posting_threading_withdrawal');$db->exec('SET search_path TO posting_threading_withdrawal');
 foreach($legacy->query("SELECT name FROM sqlite_master WHERE type='table'")->fetchAll() as $t){
  $name=$t['name'];$cols=$legacy->query('PRAGMA table_info("'.$name.'")')->fetchAll();$defs=[];
  foreach($cols as $col)$defs[]='"'.$col['name'].'" '.(str_contains(strtoupper($col['type']),'INT')?'BIGINT':'TEXT');
  $db->exec('CREATE TABLE "'.$name.'"('.implode(',',$defs).')');
  foreach($legacy->query('SELECT * FROM "'.$name.'"')->fetchAll() as $row)$db->prepare('INSERT INTO "'.$name.'" VALUES('.implode(',',array_fill(0,count($cols),'?')).')')->execute(array_values($row));
 }
 $GLOBALS['fixture_db']=$db;
 register_shutdown_function(static function()use($db){$db->exec('DROP SCHEMA IF EXISTS posting_threading_withdrawal CASCADE');});
}
$db->setAttribute(PDO::ATTR_STATEMENT_CLASS,[PostingWithdrawalStatement::class]);
$pt_checks=[];$pt_captures=[];
$actor='https://mkultra.monster/users/fixture';$parentId=$actor.'/notes/parent';$tipId=$actor.'/notes/tip';

$captures=[];$checks=[];
foreach(['home','local','feed'] as $view)foreach([1,2] as $owner)foreach(['none','tip_private','tip_unlisted','tip_deleted','parent_private','parent_deleted','tip_word','parent_word','tip_block','quote_private','quote_deleted','late_tip_private','late_tip_deleted','late_tip_word','late_fallback_tip_private','late_fallback_tip_deleted','late_fallback_tip_word','missing_tip_mapping'] as $mutation){
 $GLOBALS['vaak_owner_id']=$owner;$GLOBALS['vaak_user']=['id'=>$owner];$GLOBALS['vaak_guest_profile']=false;
 foreach(['fixture_blocks','fixture_mutes','fixture_words','fixture_server_blocks','events','outbox_notes','masto_statuses','fixture_status_cache'] as $table)$db->exec('DELETE FROM '.$table);
 $quoteId=$actor.'/notes/quote';$statuses=[];
 foreach([[$parentId,'PARENT'],[$tipId,'TIP'],[$quoteId,'QUOTED']] as [$id,$text]) {
  $status=['id'=>$text,'uri'=>$id,'url'=>$id,'created_at'=>'2026-10-11T12:00:00Z','content'=>'<p>'.$text.'</p>','visibility'=>'public','account'=>['id'=>'1','uri'=>$actor,'url'=>$actor,'acct'=>'fixture','display_name'=>'Fixture owner'],'media_attachments'=>[['type'=>'image','url'=>'https://media.test/'.$text.'.webp','preview_url'=>'https://media.test/'.$text.'.webp']],'spoiler_text'=>'','sensitive'=>false];
  $statuses[$text]=$status;
  $db->prepare('INSERT INTO outbox_notes(id,content,visibility,to_json,cc_json,in_reply_to) VALUES(?,?,?,?,?,?)')->execute([$id,$status['content'],'public','[]','[]',$text==='TIP'?$parentId:null]);
  $db->prepare('INSERT INTO masto_statuses VALUES(?,?,?,?,?,?)')->execute([$id,$text,$text==='PARENT'?1:($text==='TIP'?2:3),'',0,'public']);
 }
 if(str_starts_with($mutation,'quote_'))$statuses['TIP']['quote']=$statuses['QUOTED'];
 foreach($statuses as $status)$db->prepare('INSERT INTO fixture_status_cache VALUES(?,?)')->execute([$status['uri'],json_encode($status)]);
 if($mutation==='missing_tip_mapping')$db->prepare('DELETE FROM masto_statuses WHERE note_id=?')->execute([$tipId]);
 $GLOBALS['tip_cache_reads']=0;$GLOBALS['late_mutated']=false;unset($GLOBALS['ap_masto_flag_memo']);$GLOBALS['mutation']=$mutation;$GLOBALS['tipId']=$tipId;$GLOBALS['parentId']=$parentId;$GLOBALS['quoteId']=$quoteId;$GLOBALS['withdraw_on_parent']=true;
 ob_start();$handled=admin_try_render_outbox_shared_card(['id'=>$tipId,'in_reply_to'=>$parentId],[],$view);$html=ob_get_clean();
 $tipAllowed=!in_array($mutation,['tip_deleted','tip_word','tip_block','quote_deleted','late_tip_deleted','late_tip_word','late_fallback_tip_deleted','late_fallback_tip_word','missing_tip_mapping'],true)
  && !(in_array($mutation,['tip_private','late_tip_private','late_fallback_tip_private'],true)&&($owner===2||$view!=='home'))
  && !($mutation==='tip_unlisted'&&$view!=='home')
  && !($mutation==='quote_private'&&($owner===2||$view!=='home'));
 $parentAllowed=!str_starts_with($mutation,'late_fallback')&&!in_array($mutation,['parent_deleted','parent_word'],true)&&!($mutation==='parent_private'&&($owner===2||$view!=='home'));
 $key="$view/$owner/$mutation";if(str_starts_with($mutation,'late_'))pt_check($GLOBALS['late_mutated'],$key.' actual painter reached late withdrawal transport');
 pt_check($handled,$key.' handled without legacy revival');
 pt_check(($html!=='')===$tipAllowed,$key.' current tip emission');
 if(!$tipAllowed)pt_check(!str_contains($html,$tipId)&&!str_contains($html,'TIP')&&!str_contains($html,'media.test'),$key.' no private identifiers/body/media/actions');
 if($tipAllowed){pt_check(str_contains($html,'TIP'),$key.' visible tip preserved');pt_check(str_contains($html,'tweet-thread')===$parentAllowed,$key.' parent grouping/fallback');}
 $captures[]=['case'=>$key,'html'=>$html,'allowed'=>$tipAllowed,'parent_allowed'=>$parentAllowed];
}
echo json_encode(['passed'=>count($pt_checks),'cases'=>count($captures),'checks'=>$pt_checks,'captures'=>$captures,'driver'=>$db->getAttribute(PDO::ATTR_DRIVER_NAME),'boundary'=>'Actual PDO caller, grouped helper, shared painter, current audience/moderation/discovery. Declared normalized mapper completes withdrawal on parent normalization; PDO statement transport also completes late withdrawal during actual painter raw_create_json read, including standalone fallback; policy changes are real SQL, external transports remain fixture-only.'],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
