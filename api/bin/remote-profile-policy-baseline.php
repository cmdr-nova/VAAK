<?php
declare(strict_types=1);
/** Offline actual direct-surface policy/provider/painter preparation; no native runtime. */
require __DIR__.'/migration-reference-functions.php';
$api=dirname(__DIR__);reference_load($api.'/ap-admin.php',['vaak_actor_id']);
$GLOBALS['vaak_actor_id']='https://mkultra.monster/users/fixture';
// Replace only the inherited corpus's fixed actor transport with the actual
// current-session helper, so owner switches and guest identities are real.
$transport=file_get_contents(__DIR__.'/migration-card-reference-capture.php');
$transport=str_replace('function vaak_actor_id(): string','function unused_fixed_corpus_actor_id(): string',$transport);
$transport=str_replace('__DIR__',var_export(__DIR__,true),$transport);
$transportPath='/tmp/vaak-remote-profile-card-transport.php';file_put_contents($transportPath,$transport);
$capture=file_get_contents(__DIR__.'/search-render-reference-capture.php');
$capture=str_replace("require __DIR__.'/migration-card-reference-capture.php'",'require '.var_export($transportPath,true),$capture);
$capture=str_replace('__DIR__',var_export(__DIR__,true),$capture);
ob_start();eval(substr($capture,5));$corpus=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR)['captures'];
reference_load(__DIR__.'/search-native-callers-capture.php',['caller_restore']);
reference_load($api.'/ap-db.php',['ap_actor_is_content_blocked','ap_local_profile_tab_page','ap_masto_status_by_note_id','ap_masto_status_by_local_id','ap_masto_status_is_pinned','ap_db_masto_owner_user_id','ap_db_cmdr_nova_user_id','ap_follow_lists_cache_gen']);
reference_load($api.'/ap-admin.php',['feed_event_can_reply','feed_event_reply_object_id','actor_handle','actor_display_name','actor_display_name_html','mention_media_urls']);
// Current detail dispatch may use the root-block guarded wrapper. Load its
// actual declarations when present, while retaining historical-source replay.
preg_match_all('/^function\s+(admin_direct_status_content_blocked|admin_direct_event_content_blocked|admin_direct_filter_blocked_statuses|admin_render_direct_status_card)\s*\(/m',(string)file_get_contents($api.'/ap-admin.php'),$directRuntimeHelpers);
reference_load($api.'/ap-admin.php',$directRuntimeHelpers[1]);
reference_load($api.'/ap-profile-html.php',['ap_profile_order_tabs']);
reference_load($api.'/ap-db.php',['ap_followers_list','ap_following_list']);
// Load actual normalization helpers without any file bootstrap/DDL/network.
$source=file_get_contents($api.'/ap-masto-entities.php');preg_match_all('/^function\s+(ap_masto_[A-Za-z0-9_]+)\s*\(/m',$source,$functions);reference_load($api.'/ap-masto-entities.php',$functions[1]);
reference_load($api.'/ap-r2.php',['ap_masto_media_entity']);
$normalizerSource=file_get_contents($api.'/ap-normalize.php');preg_match_all('/^function\s+(ap_normalize_[A-Za-z0-9_]+)\s*\(/m',$normalizerSource,$normalizers);reference_load($api.'/ap-normalize.php',$normalizers[1]);
$db=ap_db();
$db->exec('ALTER TABLE following ADD COLUMN followed_at TEXT;ALTER TABLE followers ADD COLUMN followed_at TEXT;CREATE TABLE remote_actors(actor_id TEXT,username TEXT,host TEXT)');
$db->exec('ALTER TABLE events ADD COLUMN summary TEXT;ALTER TABLE events ADD COLUMN media_urls TEXT;ALTER TABLE events ADD COLUMN spoiler_text TEXT;ALTER TABLE events ADD COLUMN sensitive INTEGER;ALTER TABLE events ADD COLUMN host TEXT;ALTER TABLE events ADD COLUMN in_reply_to TEXT');
foreach(['username TEXT','disabled_at TEXT'] as $column)$db->exec('ALTER TABLE ap_users ADD COLUMN '.$column);
$db->exec("UPDATE ap_users SET username=actor_key;ALTER TABLE masto_statuses ADD COLUMN published TEXT;ALTER TABLE masto_statuses ADD COLUMN edited_at TEXT;ALTER TABLE outbox_notes ADD COLUMN published TEXT;ALTER TABLE outbox_notes ADD COLUMN in_reply_to TEXT;ALTER TABLE events ADD COLUMN created_at TEXT;CREATE TABLE masto_pins(status_local_id INTEGER,owner_user_id INTEGER);CREATE TABLE masto_media(status_local_id INTEGER,local_id INTEGER);ALTER TABLE masto_polls ADD COLUMN status_local_id INTEGER;");
$sqlite=$db;$tables=[];
foreach($db->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%'")->fetchAll(PDO::FETCH_COLUMN) as $table)$tables[$table]=['columns'=>$db->query('PRAGMA table_info('.$table.')')->fetchAll(),'rows'=>$db->query('SELECT * FROM '.$table)->fetchAll()];
function direct_bind(int $owner):void {
 $user=$owner>0?ap_db()->query('SELECT * FROM ap_users WHERE id='.$owner)->fetch():null;
 $GLOBALS['vaak_owner_id']=$owner;$GLOBALS['vaak_user']=$user?:['id'=>0,'username'=>'guest','is_guest'=>true];
 $GLOBALS['vaak_actor_id']=$user['actor_id']??'';$GLOBALS['vaak_actor_key']=$user['actor_key']??'__guest__';$GLOBALS['vaak_guest_profile']=$owner===0;
}
function direct_assert(bool $ok,string $message):void {if(!$ok)throw new RuntimeException($message);$GLOBALS['direct_checks']++;}
function direct_capture(array $status,string $view):string {ob_start();admin_render_masto_status_card($status,[],$view,$view==='status',true);return (string)ob_get_clean();}
function direct_controller_capture(string $uri,string $origin='home'):array {
 $source=file_get_contents(dirname(__DIR__).'/ap-admin.php');
 $start=strpos($source,"          \$stAuthor = '';",strpos($source,"elseif (\$view === 'status')"));
 if($start===false)throw new RuntimeException('actual detail root block region missing');
 $block=substr($source,$start,strpos($source,'?>',$start)-$start);
 $focusStart=strpos($source,'            if (is_array($stLocalStatus)) {',strpos($source,'<div id="status-thread-ancestors"',$start));
 if($focusStart===false)throw new RuntimeException('actual detail focus painter region missing');
 $focus=substr($source,$focusStart,strpos($source,'?>',$focusStart)-$focusStart);
 $stLocalStatus=null;$localRow=ap_masto_status_by_note_id($uri);if(is_array($localRow))$stLocalStatus=ap_masto_status_from_row($localRow,true,true);
 $stEvent=null;$stFrom=$origin;$followingIds=[];$GLOBALS['view']='status';
 eval($block);ob_start();if(!$stContentBlocked&&is_array($stLocalStatus))eval($focus);$html=(string)ob_get_clean();
 return ['source'=>$stLocalStatus,'content_blocked'=>$stContentBlocked,'html'=>$html,'block_source_sha256'=>hash('sha256',$block),'focus_source_sha256'=>hash('sha256',$focus)];
}
function direct_partial_dispatch(array $status,string $slot,string $origin='home'):string {
 $source=file_get_contents(dirname(__DIR__).'/ap-admin.php');$region=substr($source,strpos($source,'    if ($wantFocus) {'),12000);
 $variable=['focus'=>'focusLocal','ancestor'=>'anc','descendant'=>'desc'][$slot];
 if(!preg_match('/admin_render_(?:masto_status_card|direct_status_card)\(\$'.preg_quote($variable,'/').'[^;]+;/',$region,$match))throw new RuntimeException('actual partial dispatch missing');
 ${$variable}=$status;$followingIds=[];$stFrom=$origin;$GLOBALS['view']='status';ob_start();eval($match[0]);return (string)ob_get_clean();
}
function direct_legacy_before_function():void {
 // Current saved/Search producer bootstrap uses current PHP painters only;
 // it does not execute this baseline's historical event comparison tail.
 // Keep that separate historical oracle mandatory for the baseline itself.
 if(getenv('ROOT_LIBRARY_SEARCH_PRODUCER_BOOTSTRAP')==='1')return;
 $path='/tmp/vaak-search-loader-before-admin.php';$source=file_get_contents($path);
 if(!preg_match('/^function admin_render_event_tweet\(.*?^}/ms',$source,$match))throw new RuntimeException('actual historical event painter missing');
 eval(str_replace('function admin_render_event_tweet(','function direct_old_event_tweet(',$match[0]));
}
direct_legacy_before_function();
function direct_effective_tab(string $requested):array {
 $source=file_get_contents(dirname(__DIR__).'/ap-admin.php');$begin=strpos($source,"              \$rpTab = strtolower(trim((string) (\$_GET['tab'] ?? 'posts')));");$end=strpos($source,"          \$rpTabItems = ['posts'",$begin);if($begin===false||$end===false)throw new RuntimeException('actual profile fallback region missing');$code=substr($source,$begin,$end-$begin);
 $_GET['tab']=$requested;$rpIsLocal=true;$prefs=ap_profile_get('other');$rpHideProfileReplies=!empty($prefs['hide_profile_replies']);$rpHideProfileBoosts=!empty($prefs['hide_profile_boosts']);
 $rpPinnedStatuses=[];$rpPinnedCount=0;$rpFeaturedCards=[];$rpFeaturedCount=0;$rpBlogPosts=[];$rpBlogPost=null;$rpBlogCount=0;
 eval($code);return ['tab'=>$rpTab,'prefs'=>$prefs,'source_sha256'=>hash('sha256',$code)];
}
function direct_classify_outbox(array $rows):array {
 $source=file_get_contents(dirname(__DIR__).'/ap-admin.php');$begin=strpos($source,'              foreach ($rpOutboxPosts as $item) {',strpos($source,'} elseif ($rpIsLocal || $rpOutboxPosts || $rpBskyPosts) {'));$end=strpos($source,'              // Same twin dedupe',$begin);$code=substr($source,$begin,$end-$begin);
 $rpOutboxPosts=$rows;$rpTabItems=['posts'=>[],'replies'=>[],'media'=>[]];eval($code);return ['buckets'=>$rpTabItems,'source_sha256'=>hash('sha256',$code)];
}
function direct_source(array $context,string $visibility='public'):array {
 caller_restore($context);ap_db()->exec('DELETE FROM followers');
 $actor='https://mkultra.monster/users/other';$uri=$actor.'/notes/700';
 ap_db()->prepare('INSERT INTO masto_statuses(note_id,local_id,content_text,spoiler_text,sensitive,visibility,published) VALUES(?,?,?,?,?,?,?)')->execute([$uri,700,'Direct policy marker','',0,$visibility,'2026-10-10T12:00:00Z']);
 ap_db()->prepare('INSERT INTO outbox_notes(id,raw_create_json,content,visibility,to_json,cc_json,published,in_reply_to) VALUES(?,?,?,?,?,?,?,?)')->execute([$uri,'{}','Direct policy marker',$visibility,'[]','[]','2026-10-10T12:00:00Z','']);
 return ap_masto_status_from_row(ap_masto_status_by_note_id($uri),true,true);
}
$socket=getenv('LIBRARY_FIXTURE_SOCKET');if(!$socket)throw new RuntimeException('Disposable local PostgreSQL socket required');
$pg=new PDO('pgsql:host='.$socket.';dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$exports=[];$GLOBALS['direct_checks']=0;$repaired=in_array('--expect-repaired-detail',$argv,true);
foreach(['INTEGER','BIGINT'] as $width){
 $schema='remote_profile_policy_'.strtolower($width);$pg->exec('DROP SCHEMA IF EXISTS '.$schema.' CASCADE;CREATE SCHEMA '.$schema.';SET search_path='.$schema);
 try{
  foreach($tables as $table=>$snapshot){$defs=[];foreach($snapshot['columns'] as $column)$defs[]=$column['name'].' '.(str_contains(strtoupper($column['type']),'INT')||($table==='actor_profile'&&$column['name']==='indexable')?$width:'TEXT');$pg->exec('CREATE TABLE '.$table.'('.implode(',',$defs).')');$names=array_column($snapshot['columns'],'name');$insert=$pg->prepare('INSERT INTO '.$table.'('.implode(',',$names).') VALUES('.implode(',',array_fill(0,count($names),'?')).')');foreach($snapshot['rows'] as $row)$insert->execute(array_values($row));}
  $GLOBALS['fixture_db']=$pg;direct_bind(1);
  $ownerBefore=admin_owner_user_id();$pg->beginTransaction();$pg->exec('DROP TABLE remote_actors');$caughtWarnings=[];set_error_handler(static function($severity,$message)use(&$caughtWarnings){$caughtWarnings[]=$message;return true;});
  try{$acctFallback=ap_masto_remote_account_acct_key('https://catch-fixture.example/@Alice');}finally{restore_error_handler();$pg->rollBack();}
  direct_assert($acctFallback==='alice@catch-fixture.example'&&$caughtWarnings===[]&&admin_owner_user_id()===$ownerBefore,"actual account SQL catch fallback no warning or owner mutation $width");$exports[$width]['account_catch']=['acct'=>$acctFallback,'warnings'=>$caughtWarnings,'owner'=>admin_owner_user_id()];
  foreach(['public','unlisted','private','direct'] as $visibility){
   $status=direct_source($corpus['status_plain']['context'],$visibility);
   foreach([0,1,2] as $owner){direct_bind($owner);$allowed=ap_visibility_status_audience_allowed($status,$owner);direct_assert($allowed===($visibility==='public'||$visibility==='unlisted'||$owner===2),"actual root audience $width/$visibility/$owner");$exports[$width]['audience_'.$visibility.'_'.$owner]=['source'=>$status,'allowed'=>$allowed];}
   direct_bind(1);
   $posts=ap_local_profile_tab_page('other','https://mkultra.monster/users/other','posts',20,0);direct_assert(count($posts['items'])===($visibility==='public'?1:0),"actual profile provider public-only $width/$visibility");
   $expectedVisible=!$repaired||ap_visibility_status_audience_allowed($status,1);
   $html=direct_capture($status,'status');direct_assert(str_contains($html,'Direct policy marker')===$expectedVisible,"real direct shared card $width/$visibility");
   $controller=direct_controller_capture($status['uri']);direct_assert(str_contains($controller['html'],'Direct policy marker')===$expectedVisible,"actual detail root block and focused painter $width/$visibility");
   $exports[$width]['detail_root_'.$visibility]=['source'=>$status,'provider_row'=>ap_masto_status_by_note_id($status['uri']),'audience'=>ap_visibility_status_audience_allowed($status,1),'content_blocked'=>ap_actor_is_content_blocked($status['account']['uri'],null,1),'html'=>$html,'profile_page'=>$posts,'controller'=>$controller];
  }
  direct_bind(1);$status=direct_source($corpus['status_plain']['context'],'private');$author=$status['account']['uri'];$viewer=vaak_actor_id();
  $pg->prepare('INSERT INTO followers(owner_actor_id,actor_id) VALUES(?,?)')->execute([$author,$viewer]);direct_assert(ap_visibility_status_audience_allowed($status,1),'accepted follower grant');$pg->exec('DELETE FROM followers');direct_assert(!ap_visibility_status_audience_allowed($status,1),'follower revocation');
  $pg->prepare('UPDATE outbox_notes SET visibility=\'direct\',to_json=? WHERE id=?')->execute([json_encode([$viewer]),$status['uri']]);direct_assert(ap_visibility_status_audience_allowed($status,1),'explicit direct recipient');$pg->prepare('UPDATE outbox_notes SET to_json=\'[]\' WHERE id=?')->execute([$status['uri']]);direct_assert(!ap_visibility_status_audience_allowed($status,1),'direct grant revocation');
  foreach(['author','follower','recipient','revoked'] as $grant){
   direct_bind($grant==='author'?2:1);$pg->exec('DELETE FROM followers');$pg->prepare('UPDATE outbox_notes SET visibility=?,to_json=\'[]\' WHERE id=?')->execute([$grant==='recipient'?'direct':'private',$status['uri']]);
   if($grant==='follower')$pg->prepare('INSERT INTO followers(owner_actor_id,actor_id) VALUES(?,?)')->execute([$author,$viewer]);
   if($grant==='recipient')$pg->prepare('UPDATE outbox_notes SET to_json=? WHERE id=?')->execute([json_encode([$viewer]),$status['uri']]);
   $result=direct_controller_capture($status['uri']);$allowed=ap_visibility_status_audience_allowed($result['source'],admin_owner_user_id());
   direct_assert($allowed===($grant!=='revoked')&&str_contains($result['html'],'Direct policy marker')===(!$repaired||$allowed),"actual allowed/revoked controller $width/$grant");$dispatch=[];
   foreach(['focus','ancestor','descendant'] as $slot){$dispatch[$slot]=direct_partial_dispatch($result['source'],$slot);direct_assert(str_contains($dispatch[$slot],'Direct policy marker')===(!$repaired||$allowed),"actual partial route dispatch $width/$grant/$slot");}
   $exports[$width]['controller_grant_'.$grant]=['allowed'=>$allowed,'controller'=>$result,'partial_dispatch'=>$dispatch];
  }
  direct_bind(1);
  // A current public profile root can contain a cached private/deleted child.
  foreach(['quote','reblog','late_quote','deleted_quote'] as $shape){
   $child=direct_source($corpus['status_plain']['context'],'private');direct_bind(1);
   $root=$corpus['status_plain']['status'];$root['content']='Public parent marker';
   if($shape==='reblog'){$root['content']='';$root['reblog']=$child;}
   elseif($shape==='quote')$root['quote']=['state'=>'accepted','quoted_status'=>$child];
   else{$root['quote']=['state'=>'pending'];$root['quote_url']=$child['uri'];$pg->prepare('INSERT INTO fixture_status_cache VALUES(?,?)')->execute([$child['uri'],json_encode($child)]);}
   if($shape==='deleted_quote'){$pg->prepare('DELETE FROM masto_statuses WHERE note_id=?')->execute([$child['uri']]);$pg->prepare('DELETE FROM outbox_notes WHERE id=?')->execute([$child['uri']]);}
   foreach(['remote_profile','status'] as $view){$html=direct_capture($root,$view);$exports[$width]['nested_'.$shape.'_'.$view]=['source'=>$root,'audience'=>ap_visibility_status_audience_allowed($root,1),'child_source'=>$child,'child_audience'=>ap_visibility_status_audience_allowed($child,1),'child_current_row'=>ap_masto_status_by_note_id($child['uri']),'html'=>$html,'child_body_visible'=>str_contains($html,'Direct policy marker')];direct_assert($repaired?!str_contains($html,'Direct policy marker'):str_contains($html,'<article'),"nested direct painter $width/$shape/$view");}
  }
  $status=direct_source($corpus['status_plain']['context']);direct_bind(0);
  $page=ap_local_profile_tab_page('other','https://mkultra.monster/users/other','posts',20,0);ob_start();foreach($page['items'] as $row){if(!admin_try_render_outbox_shared_card($row,[],'remote_profile'))admin_render_outbox_card($row,'remote_profile');}$guestHtml=(string)ob_get_clean();
  direct_assert(str_contains($guestHtml,'Direct policy marker')&&!str_contains($guestHtml,'name="csrf"'),"actual guest profile provider/legacy body no owner forms $width");$exports[$width]['guest_public_provider']=['page'=>$page,'html'=>$guestHtml];
  direct_bind(1);$status=direct_source($corpus['status_plain']['context']);$pg->prepare('DELETE FROM masto_statuses WHERE note_id=?')->execute([$status['uri']]);$pg->prepare('DELETE FROM outbox_notes WHERE id=?')->execute([$status['uri']]);direct_assert(ap_masto_status_by_note_id($status['uri'])===null,'deleted detail canonical root provider absent');$exports[$width]['deleted_root']=['provider'=>ap_masto_status_by_note_id($status['uri'])];
  foreach(['none','personal_block','personal_mute','word','server_block','server_mute'] as $mode){
   $status=direct_source($corpus['status_plain']['context']);direct_bind(1);
   if($mode==='personal_block')$pg->prepare('INSERT INTO fixture_blocks VALUES(?,?,?)')->execute([1,'actor',$status['account']['uri']]);
   if($mode==='personal_mute')$pg->prepare('INSERT INTO fixture_mutes VALUES(?,?)')->execute([1,$status['account']['uri']]);
   if($mode==='word')$pg->prepare('INSERT INTO fixture_words VALUES(?,?)')->execute([1,'Direct policy marker']);
   if(str_starts_with($mode,'server_'))$pg->prepare('INSERT INTO fixture_server_blocks VALUES(?,?,?)')->execute(['actor',$status['account']['uri'],$mode==='server_block'?'block':'mute']);
   $blocked=ap_actor_is_content_blocked($status['account']['uri'],null,1);direct_assert($blocked===in_array($mode,['personal_block','server_block'],true),"explicit-open content block $width/$mode");
   $html=$blocked?'':direct_capture($status,'remote_profile');direct_assert(str_contains($html,'Direct policy marker')===!$blocked,"actual direct-open body $width/$mode");
   $exports[$width]['moderation_'.$mode]=['blocked'=>$blocked,'html'=>$html];
  }
  direct_source($corpus['status_plain']['context']);direct_bind(1);
  $pg->exec("INSERT INTO actor_profile(actor_key,name,indexable,hide_profile_replies,hide_profile_boosts) VALUES('other','Other',1,'1','1')");
  foreach(['replies','boosts','pinned','featured','blog','invalid','asks'] as $requested){$effective=direct_effective_tab($requested);direct_assert($effective['tab']===($requested==='asks'?'asks':'posts'),"actual current hidden/empty tab fallback $width/$requested");$exports[$width]['tab_'.$requested]=$effective;}
  $pg->exec("UPDATE actor_profile SET hide_profile_replies='0',hide_profile_boosts='0' WHERE actor_key='other'");foreach(['replies','boosts'] as $requested)direct_assert(direct_effective_tab($requested)['tab']===$requested,"current tab setting withdrawal $width/$requested");
  $pg->exec("UPDATE actor_profile SET indexable=0,discoverable='0' WHERE actor_key='other'");$explicit=direct_controller_capture('https://mkultra.monster/users/other/notes/700');direct_assert(str_contains($explicit['html'],'Direct policy marker'),'explicit public detail unaffected by discovery/indexing withdrawal');$exports[$width]['explicit_public_no_discovery']=$explicit;
  $pg->prepare('INSERT INTO fixture_words VALUES(?,?)')->execute([1,'Direct policy marker']);
  foreach(['search','bookmarks','favourites'] as $origin){
   foreach(['public','recipient','revoked'] as $grant){
    $pg->prepare("UPDATE outbox_notes SET visibility=?,to_json=? WHERE id=?")->execute([$grant==='public'?'public':'direct',json_encode($grant==='recipient'?[vaak_actor_id()]:[]),'https://mkultra.monster/users/other/notes/700']);
    $result=direct_controller_capture('https://mkultra.monster/users/other/notes/700',$origin);$allowed=$grant!=='revoked';
    $htmls=['full'=>$result['html']];foreach(['focus','ancestor','descendant'] as $slot)$htmls[$slot]=direct_partial_dispatch($result['source'],$slot,$origin);
    foreach($htmls as $slot=>$html){direct_assert(str_contains($html,'Direct policy marker')===$allowed,"explicit route origin body $width/$origin/$grant/$slot");if($allowed)direct_assert(str_contains($html,'name="return_view" value="'.$origin.'"'),"action return origin unchanged $width/$origin/$grant/$slot");}
    $exports[$width]['origin_'.$origin.'_'.$grant]=$htmls;
   }
  }
  // Real profile cached-event collector admits HTTP object URIs; real muted
  // event normalization refuses them. Exercise the actual legacy painter.
  direct_source($corpus['status_plain']['context']);direct_bind(1);$GLOBALS['view']='remote_profile';
  $event=['id'=>900,'object_id'=>'http://legacy.example/notes/900','type'=>'Create','action_taken'=>'log','visibility'=>'public','actor_id'=>'https://legacy.example/users/alice','summary'=>'Legacy public compatibility marker','media_urls'=>'[]','spoiler_text'=>'','sensitive'=>0,'host'=>'legacy.example','in_reply_to'=>'','created_at'=>'2026-10-10T12:00:00Z'];
  $pg->prepare('INSERT INTO events('.implode(',',array_keys($event)).') VALUES('.implode(',',array_fill(0,count($event),'?')).')')->execute(array_values($event));
  $adminSource=file_get_contents(dirname(__DIR__).'/ap-admin.php');$begin=strpos($adminSource,'                      $ors = [];',strpos($adminSource,'// Mastodon: /users/name vs /ap/users/{id}'));
  $collector=substr($adminSource,$begin,strpos($adminSource,'                      // Also bridgy-style:',$begin)-$begin);$rpAliases=[$event['actor_id']];$rpPosts=[];$db=ap_db();eval($collector);
  direct_assert(count($rpPosts)===1&&$rpPosts[0]['object_id']===$event['object_id'],"actual profile cached collector HTTP reachability $width");
  direct_assert(is_array(ap_normalize_from_activitypub_event($event)),"ordinary unmuted HTTP event normalizes $width");
  $pending=$corpus['status_plain']['status'];$pending['quote']=['state'=>'pending'];$pending['content']='Harmless pending quote marker';
  $pendingHtml=direct_capture($pending,'remote_profile');direct_assert(str_contains($pendingHtml,'Harmless pending quote marker'),"harmless pending quote descriptor preserves public parent $width");$exports[$width]['pending_quote']=$pendingHtml;
  $pg->prepare('INSERT INTO fixture_mutes VALUES(?,?)')->execute([1,$event['actor_id']]);
  direct_assert(ap_masto_status_from_event($event)===null&&ap_normalize_from_activitypub_event($event)===null,"genuine muted HTTP normalization refusal $width");
  ob_start();direct_old_event_tweet($event,[],'remote_profile');$old=(string)ob_get_clean();
  ob_start();admin_render_event_tweet($event,[],'remote_profile');$new=(string)ob_get_clean();
  direct_assert(str_contains($old,'Legacy public compatibility marker')&&$new===$old,"historical public legacy HTML preserved exactly $width");
  $exports[$width]['legacy_public']=['event'=>$event,'before'=>$old,'after'=>$new,'collector_sha256'=>hash('sha256',$collector)];
  foreach(['follower','recipient'] as $grant){
   $pg->prepare('UPDATE events SET visibility=? WHERE id=900')->execute([$grant==='follower'?'private':'direct']);
   if($grant==='follower')$pg->prepare('INSERT INTO following(owner_actor_id,actor_id) VALUES(?,?)')->execute([vaak_actor_id(),$event['actor_id']]);
   else $pg->prepare("INSERT INTO mentions(id,object_id,deleted_at,owner_user_id,activity_type) VALUES(901,?,NULL,1,'Create')")->execute([$event['object_id']]);
   ob_start();admin_render_event_tweet($event,[],'remote_profile');$granted=(string)ob_get_clean();direct_assert(str_contains($granted,'Legacy public compatibility marker'),"legacy current $grant grant allowed $width");
   $pg->exec('DELETE FROM following;DELETE FROM mentions');ob_start();admin_render_event_tweet($event,[],'remote_profile');$revoked=(string)ob_get_clean();direct_assert($revoked==='',"legacy current $grant revocation denied $width");$exports[$width]['legacy_grant_'.$grant]=['granted'=>$granted,'revoked'=>$revoked];
  }
  foreach(['private','direct','deleted'] as $denial){$pg->prepare('UPDATE events SET visibility=?,action_taken=? WHERE id=900')->execute([$denial==='private'?'private':'direct',$denial==='deleted'?'deleted':'log']);ob_start();admin_render_event_tweet($event,[],'remote_profile');$denied=(string)ob_get_clean();direct_assert($denied==='',"current legacy audience/deletion $width/$denial");$exports[$width]['legacy_'.$denial]=$denied;}
  $ordered=ap_profile_order_tabs(array_fill_keys(['blog','asks','media','posts','boosts','replies','pinned','featured'],['Fixture',1]));direct_assert(array_keys($ordered)===['pinned','posts','replies','boosts','media','asks','featured','blog'],'ordered tabs keep Asks after Media');$exports[$width]['ordered_tabs']=$ordered;
  foreach([['701','https://mkultra.monster/users/other/notes/700'],['702','https://mkultra.monster/users/fixture/notes/456']] as [$id,$parent]){$pg->prepare('INSERT INTO outbox_notes(id,raw_create_json,content,visibility,to_json,cc_json,published,in_reply_to) VALUES(?,?,?,?,?,?,?,?)')->execute(['https://mkultra.monster/users/other/notes/'.$id,json_encode(['object'=>['inReplyTo'=>$parent]]),'Reply classification marker','public','[]','[]','2026-10-10T13:00:00Z',$parent]);}
  $paged=ap_local_profile_tab_page('other','https://mkultra.monster/users/other','replies',20,0);direct_assert(count($paged['items'])===2,'actual paged replies includes self reply');
  foreach([1,2] as $owner){direct_bind($owner);$classified=direct_classify_outbox($paged['items']);direct_assert(count($classified['buckets']['posts'])===1&&count($classified['buckets']['replies'])===1,'actual full classification uses viewer-self parent');$exports[$width]['classification_owner_'.$owner]=['paged'=>$paged,'full'=>$classified];}
 }finally{$GLOBALS['fixture_db']=$sqlite;$pg->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
$path=$argv[1]??'/tmp/vaak-remote-profile-policy-baseline.json';file_put_contents($path,json_encode(['checks'=>$GLOBALS['direct_checks'],'expect_repaired_detail'=>$repaired,'cases'=>$exports],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR));
echo 'remote-profile-policy-baseline PASS '.$GLOBALS['direct_checks'].' controls; actual INTEGER/BIGINT; '.$path."\n";
