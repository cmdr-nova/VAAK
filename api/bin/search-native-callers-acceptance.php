<?php
declare(strict_types=1);
/** Actual caller differential: isolated real PHP cURL -> actual Rust endpoint. */
function ap_auth_current_user(): ?array { return ($GLOBALS['acceptance_auth_mode']??'valid')==='guest'?['id'=>0,'is_guest'=>true]:($GLOBALS['vaak_user']??null); }
function acceptance_provision():void {
 $db=ap_db();
 $db->exec("ALTER TABLE ap_users ADD username TEXT;ALTER TABLE ap_users ADD disabled_at TEXT;ALTER TABLE ap_users ADD is_admin INTEGER;UPDATE ap_users SET actor_key=CASE WHEN id=1 THEN 'fixture' ELSE 'other' END,username=CASE WHEN id=1 THEN 'fixture' ELSE 'other' END,is_admin=0;UPDATE actor_profile SET vanity_verified=0;CREATE TABLE ap_mutes(owner_user_id INTEGER,actor_id TEXT);CREATE TABLE ap_user_blocks(id INTEGER,owner_user_id INTEGER,scope TEXT,value TEXT,created_at TEXT);CREATE TABLE ap_blocks(id INTEGER,scope TEXT,value TEXT,kind TEXT);CREATE TABLE ap_muted_words(owner_user_id INTEGER,phrase TEXT);CREATE TABLE ap_deprioritized_actors(owner_user_id INTEGER,actor_id TEXT);CREATE TABLE ap_wafrn_friend_servers(host TEXT,enabled INTEGER);CREATE TABLE remote_media_cache(actor_id TEXT,kind TEXT,public_url TEXT);CREATE TABLE masto_pins(owner_user_id INTEGER,status_local_id INTEGER);CREATE TABLE ap_asks(id INTEGER,answer_note_id TEXT,question TEXT,asker_actor TEXT);CREATE TABLE quote_authorizations(deleted_at TEXT,quoted_note_id TEXT);CREATE TABLE bsky_sessions(owner_user_id INTEGER,did TEXT,handle TEXT);CREATE TABLE bsky_crossposts(note_id TEXT,bsky_uri TEXT,created_at TEXT);CREATE TABLE bsky_graph_sync(owner_user_id INTEGER,kind TEXT,target_did TEXT);CREATE TABLE bsky_actor_profiles(actor_ref TEXT,did TEXT,profile_json TEXT,updated_at TEXT);CREATE TABLE masto_followed_tags(owner_user_id INTEGER,name TEXT);CREATE TABLE masto_account_actors(account_id TEXT,actor_id TEXT);ALTER TABLE events ADD quote_object TEXT;ALTER TABLE events ADD summary TEXT;ALTER TABLE following ADD owner_user_id INTEGER;ALTER TABLE masto_statuses ADD published TEXT;ALTER TABLE mentions ADD actor_id TEXT;ALTER TABLE bsky_posts ADD like_count INTEGER;ALTER TABLE bsky_posts ADD repost_count INTEGER;ALTER TABLE bsky_posts ADD quote_count INTEGER;CREATE TABLE ap_post_subscriptions(owner_user_id INTEGER,target_actor_id TEXT);");
}
function acceptance_sync(array $context):void {
 $db=ap_db();foreach(['ap_mutes','ap_user_blocks','ap_blocks','ap_muted_words','ap_asks','masto_followed_tags'] as $table)$db->exec('DELETE FROM '.$table);
 $db->exec('INSERT INTO ap_mutes SELECT * FROM fixture_mutes;INSERT INTO ap_user_blocks SELECT 1,owner_user_id,scope,value,NULL FROM fixture_blocks;INSERT INTO ap_blocks SELECT 1,scope,value,kind FROM fixture_server_blocks;INSERT INTO ap_muted_words SELECT * FROM fixture_words');
 $db->exec('DELETE FROM mentions');
 if(($context['complete_quote_grant']??'')==='recipient')$db->exec("INSERT INTO mentions(id,object_id,owner_user_id,activity_type,deleted_at,actor_id) VALUES(925,'https://remote.test/independent-media-child',1,'Create',NULL,'https://remote.test/users/alice')");
 unset($GLOBALS['ap_followed_tags_cache']);
 $GLOBALS['acceptance_auth_mode']=$context['transport_auth']??'valid';
 $GLOBALS['acceptance_flag']=$context['transport_flag']??'1';
 putenv('VAAK_SEARCH_NATIVE_SECRET='.($context['transport_secret']??'independent-search-acceptance-token-0001'));
 $db->exec("UPDATE ap_users SET disabled_at=NULL");if(!empty($context['transport_inactive']))$db->exec("UPDATE ap_users SET disabled_at='fixture-disabled' WHERE id=1");
 if(($context['collector_tag_state']??'')==='followed')$db->exec("INSERT INTO masto_followed_tags(owner_user_id,name) VALUES(1,'rust')");
 if(isset($context['canonical_public_id_provenance']))$db->prepare('UPDATE masto_statuses SET published=? WHERE local_id=?')->execute([$context['canonical_public_id_provenance']['published'],$context['canonical_public_id_provenance']['local_id']]);
 acceptance_sources(is_array($GLOBALS['acceptance_window']['statuses']??null)?$GLOBALS['acceptance_window']['statuses']:[]);
 if(isset($context['actual_undo_type'])){
  if(!function_exists('ap_events_mark_interaction_undone'))reference_load(dirname(__DIR__).'/ap-db.php',['ap_events_mark_interaction_undone']);
  $n=ap_events_mark_interaction_undone($context['actual_undo_type'],'https://interaction.test/users/person',$context['actual_undo_target']);
  if($n!==1)throw new RuntimeException('Actual canonical Undo writer did not update one interaction');
 }
 $GLOBALS['fixture_render_clock']=time();
}
function acceptance_sources(array $rows):void {
 $walk=static function(array $st)use(&$walk):void {
  $uri=$st['uri']??'';$actor=$st['account']['uri']??'';
  if(is_string($uri)&&str_starts_with($uri,'https://')&&!str_starts_with($uri,'https://mkultra.monster/users/')&&is_string($actor)&&$actor!==''){
   $q=ap_db()->prepare('SELECT 1 FROM events WHERE object_id=?');$q->execute([$uri]);
   $cached=ap_db()->prepare('SELECT 1 FROM remote_media_cache WHERE actor_id=?');$cached->execute([$actor]);if(!$cached->fetchColumn())ap_db()->prepare('INSERT INTO remote_media_cache VALUES(?,?,?)')->execute([$actor,'avatar','https://mkultra.monster/img/avatar/default.webp']);
   if(!$q->fetchColumn())ap_db()->prepare("INSERT INTO events(id,object_id,type,action_taken,visibility,actor_id) VALUES(1000,?,'Create','log',?,?)")->execute([$uri,$st['visibility']??'public',$actor]);
  }
  foreach(['reblog','vaak_quote_preview'] as $field)if(is_array($st[$field]??null))$walk($st[$field]);if(is_array($st['quote']['quoted_status']??null))$walk($st['quote']['quoted_status']);
 };
 foreach($rows as $row)if(is_array($row))$walk($row);
}
function acceptance_stable_cases(array &$cases):void {
 $base=$cases['mixed_type_all'];$plain=$base['rows']['statuses'][0];
 foreach(['Announce','Quote','QuotePost'] as $type)foreach([false,true] as $nested){
  $one=$base;$child=$plain;$child['uri']=$child['url']='https://remote.test/independent-undo';$child['content']='<p>Independent live Undo marker</p>';
  $rows=[['id'=>901,'object_id'=>$child['uri'],'type'=>'Create','action_taken'=>'log','visibility'=>'public','actor_id'=>$child['account']['uri']],['id'=>902,'object_id'=>$child['uri'].'/','type'=>$type,'action_taken'=>'local_observe','visibility'=>'public','actor_id'=>'https://interaction.test/users/person']];
  $root=$child;if($nested){$root=$plain;$root['uri']=$root['url']='https://remote.test/independent-parent';$root['quote']=['state'=>'accepted','quoted_status'=>$child];$rows[]=['id'=>903,'object_id'=>$root['uri'],'type'=>'Create','action_taken'=>'log','visibility'=>'public','actor_id'=>$root['account']['uri']];}
  $one['rows']['statuses']=[$root];$one['context']['audience_events']=$rows;$one['context']['actual_undo_type']=$type;$one['context']['actual_undo_target']=$child['uri'];$cases['policy_undo_'.$type.($nested?'_nested':'_root')]=$one;
 }
 foreach(['public','private','Delete'] as $kind){$one=$base;$st=$plain;$st['uri']=$st['url']='https://remote.test/independent-authority';$st['content']='<p>Independent authority marker</p>';$st['visibility']='public';$one['rows']['statuses']=[$st];$one['context']['audience_events']=[['id'=>911,'object_id'=>$st['uri'],'type'=>'Create','action_taken'=>'log','visibility'=>$kind==='private'?'private':'public','actor_id'=>$st['account']['uri']],['id'=>912,'object_id'=>$st['uri'].'/','type'=>$kind==='Delete'?'Delete':'Quote','action_taken'=>'local_observe','visibility'=>'public','actor_id'=>'https://interaction.test/users/person']];$cases['policy_original_'.$kind.'_new_quote']=$one;}
 foreach(['follower','recipient','revoked','deleted'] as $grant){
  $one=$base;$child=$plain;$child['uri']=$child['url']='https://remote.test/independent-media-child';$child['content']='<p>Independent complete quote marker</p>';$child['media_attachments']=$cases['status_cw_media']['rows']['statuses'][0]['media_attachments'];
  $parent=$plain;$parent['uri']=$parent['url']='https://remote.test/independent-media-parent';$parent['quote']=['state'=>'accepted','quoted_status'=>$child];$one['rows']['statuses']=[$parent];
  $one['context']['audience_events']=[['id'=>921,'object_id'=>$child['uri'],'type'=>'Create','action_taken'=>'log','visibility'=>$grant==='recipient'?'direct':'private','actor_id'=>$child['account']['uri']],['id'=>922,'object_id'=>$parent['uri'],'type'=>'Create','action_taken'=>'log','visibility'=>'public','actor_id'=>$parent['account']['uri']]];
  if($grant==='follower')$one['context']['following'][]=['owner_actor_id'=>vaak_actor_id(),'actor_id'=>$child['account']['uri'],'host'=>'remote.test','followed_at'=>'2026-10-10'];
  if($grant==='deleted')$one['context']['audience_events'][]=['id'=>923,'object_id'=>$child['uri'].'/','type'=>'Delete','action_taken'=>'log','visibility'=>'public','actor_id'=>$child['account']['uri']];
  $one['context']['complete_quote_grant']=$grant;$cases['complete_media_quote_'.$grant]=$one;
 }
 foreach(['block','mute','withdrawn'] as $mode){$one=$base;$one['context']['collector_account_filter']=true;if($mode==='block')$one['context']['moderation_blocks'][]=['owner_user_id'=>1,'scope'=>'actor','value'=>$plain['account']['uri']];if($mode==='mute')$one['context']['moderation_mutes'][]=['owner_user_id'=>1,'actor_id'=>$plain['account']['uri']];$cases['collector_account_'.$mode]=$one;}
 foreach(['followed','withdrawn'] as $mode){$one=$base;$one['context']['collector_tag_state']=$mode;$cases['collector_tag_'.$mode]=$one;}
 foreach(['unknown_thin','invalid_utf8','cap','unresolved_quote','guest','disabled','wrong_secret','inactive'] as $mode){
  $one=$base;
  if($mode==='unknown_thin')$one['rows']['statuses'][0]['account']=[];
  if($mode==='invalid_utf8')$one['rows']['accounts'][0]['display_name']="Malformed fixture \xff";
  if($mode==='cap')$one['rows']['accounts']=array_fill(0,41,$base['rows']['accounts'][0]);
  if($mode==='unresolved_quote'){$one['rows']['statuses'][0]['quote']=['state'=>'pending'];$one['rows']['statuses'][0]['quote_url']='https://remote.test/independent-unresolved';}
  if($mode==='guest')$one['context']['transport_auth']='guest';if($mode==='disabled')$one['context']['transport_flag']='0';
  if($mode==='wrong_secret')$one['context']['transport_secret']=str_repeat('x',36);if($mode==='inactive')$one['context']['transport_inactive']=true;
  $cases['fallback_'.$mode]=$one;
 }
 foreach($cases as &$case)if(is_array($case['rows']['statuses']??null))foreach($case['rows']['statuses'] as &$status)if(is_array($status))$status['created_at']='2026-10-01T00:00:00Z';
 if($only=getenv('SEARCH_ACCEPT_CASES')){$names=array_fill_keys(explode(',',$only),true);$cases=array_intersect_key($cases,$names);}
}
function acceptance_filter_window(array $window,array $context):array {
 if(isset($context['collector_tag_state'])){
  if(!function_exists('ap_masto_tag_entity'))reference_load(dirname(__DIR__).'/ap-masto-entities.php',['ap_masto_normalize_tag_name','ap_masto_tag_is_following','ap_masto_tag_entity']);
  $tag=ap_masto_tag_entity('rust',[],1);$expected=$context['collector_tag_state']==='followed';
  if($tag['following']!==$expected)throw new RuntimeException('Actual canonical current tag producer withdrawal failed');$window['hashtags']=[$tag];
 }
 if(empty($context['collector_account_filter']))return $window;
 $before=$window['accounts'];$window['accounts']=ap_search_filter_accounts($before,1,40);
 $expected=!empty($context['moderation_blocks'])||!empty($context['moderation_mutes'])?1:2;
 if(count($window['accounts'])!==$expected)throw new RuntimeException('Actual Search collector account filter current withdrawal failed');
 return $window;
}
function acceptance_browser_capture(array $cases):void {
 $captures=[];$zero=$cases['empty_advanced_denied'];$plain=$cases['mixed_type_all'];
 foreach(['first','next'] as $step){
  $context=$step==='first'?$zero['context']:$plain['context'];$window=['accounts'=>[],'hashtags'=>[],'statuses'=>[],'has_more'=>['statuses'=>$step==='first']];
  if($step==='first')$window['statuses']=$zero['rows']['statuses'];
  else foreach([1,2] as $number){$status=$plain['rows']['statuses'][0];$status['id']='browser-'.$number;$status['uri']=$status['url']='https://remote.test/browser/'.$number;$status['content']='<p>Independent browser post '.$number.'</p>';$window['statuses'][]=$status;}
  $GLOBALS['name']='browser_'.$step;$GLOBALS['context']=$context;$GLOBALS['acceptance_window']=$window;$GLOBALS['search_caller_window']=$window;caller_restore($context);$GLOBALS['search_caller_collector_calls']=[];
  $capture=caller_capture(static function()use($step){$fragment=admin_search_page_fragment('café & tea','statuses',true,$step==='first'?0:10,10);echo $fragment['html'];return $fragment;});
  $captures[$step]=['html'=>$capture['html']];
 }
 file_put_contents('/tmp/vaak-search-native-browser-'.strtolower($GLOBALS['width']).'.json',json_encode($captures,JSON_THROW_ON_ERROR));
}
$source=file_get_contents(__DIR__.'/search-native-callers-capture.php');
$source=str_replace('__DIR__',var_export(__DIR__,true),$source);
$source=str_replace('$sqlite=ap_db();$snapshots=[];','acceptance_provision();$sqlite=ap_db();$snapshots=[];',$source);
$source=str_replace("(\$table==='actor_profile'&&\$col['name']==='indexable')","(\$table==='actor_profile'&&in_array(\$col['name'],['indexable','auto_unblur_sensitive','discoverable','automated','vanity_verified'],true))",$source);
$source=str_replace('$exports=[];$checks=0;','acceptance_stable_cases($cases);$exports=[];$checks=0;',$source);
$source=str_replace('JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR','JSON_UNESCAPED_UNICODE|JSON_INVALID_UTF8_SUBSTITUTE|JSON_THROW_ON_ERROR',$source);
$source=str_replace('search_native_callers_','search_native_accept_',$source);
$source=str_replace('    ap_follow_lists_cache_gen(true);','    ap_follow_lists_cache_gen(true);acceptance_sync($context);',$source);
$source=str_replace('function caller_capture(callable $paint):array {','function caller_capture_once(callable $paint):array {',$source);
$source=str_replace('$q="café & tea',"\$GLOBALS['acceptance_window']=\$window;\n            \$q=\"café & tea",$source);
$source=str_replace("\$GLOBALS['acceptance_window']=\$window;", "\$GLOBALS['acceptance_window']=\$window;caller_restore(\$case['context']);\$window=acceptance_filter_window(\$window,\$case['context']);\$GLOBALS['acceptance_window']=\$window;",$source);
$source=str_replace("\$schema='search_native_accept_'.strtolower(\$width);","\$schema='search_native_accept_'.strtolower(\$width);putenv('VAAK_SEARCH_NATIVE_URL='.(getenv('SEARCH_ACCEPT_URL_'.\$width)?:'http://127.0.0.1:'.(\$width==='INTEGER'?18876:18877).'/internal/search/render'));",$source);
$source=str_replace("    } finally {if(\$pg)","        if(!getenv('SEARCH_ACCEPT_CASES'))acceptance_browser_capture(\$cases);\n    } finally {if(\$pg)",$source);
function caller_capture(callable $paint):array {
 $sourceBefore=serialize($GLOBALS['acceptance_window']);
 putenv('VAAK_SEARCH_NATIVE_RENDER=0');$php=caller_capture_once($paint);
 $canonicalCalls=$GLOBALS['search_caller_collector_calls']??[];
 $GLOBALS['search_caller_collector_calls']=[];
 caller_restore($GLOBALS['context']);
 $GLOBALS['preview_http_requests']=[];$GLOBALS['preview_warm_requests']=[];
 $GLOBALS['admin_library_fetch_budget']=3;$GLOBALS['admin_quote_link_preview_budget']=3;
 putenv('VAAK_SEARCH_NATIVE_RENDER='.($GLOBALS['acceptance_flag']??'1'));$native=caller_capture_once($paint);
 if(serialize($GLOBALS['acceptance_window'])!==$sourceBefore)throw new RuntimeException('Caller original source rows mutated across bridge');
 $case=(string)($GLOBALS['name']??'');
 if(str_starts_with($case,'policy_')&&str_contains($php['html'],'data-section="statuses"')){
  $marker=str_starts_with($case,'policy_undo_')?'Independent live Undo marker':'Independent authority marker';
  $allowed=!str_contains($case,'_private_')&&!str_contains($case,'_Delete_');
  if(str_contains($php['html'],$marker)!==$allowed||str_contains($native['html'],$marker)!==$allowed)throw new RuntimeException('Actual paired full card authority/Undo body mismatch '.$case);
  $GLOBALS['acceptance_policy_checks']=($GLOBALS['acceptance_policy_checks']??0)+1;
 }
 if(str_starts_with($case,'complete_media_quote_')&&str_contains($php['html'],'data-section="statuses"')){
  $allowed=str_ends_with($case,'follower')||str_ends_with($case,'recipient');
  if(str_contains($php['html'],'Independent complete quote marker')!==$allowed||str_contains($native['html'],'Independent complete quote marker')!==$allowed)throw new RuntimeException('Actual complete quote current grant mismatch '.$case);
 }
 file_put_contents('/tmp/vaak-search-acceptance-pairs.jsonl',json_encode(['width'=>$GLOBALS['width']??'', 'case'=>$GLOBALS['name']??'', 'original_source_sha256'=>hash('sha256',$sourceBefore),'invalid_original_bytes'=>str_contains($case,'invalid_utf8')?base64_encode($sourceBefore):null,'equal'=>$php===$native,'php'=>$php,'native'=>$native],JSON_INVALID_UTF8_SUBSTITUTE)."\n",FILE_APPEND);
 $GLOBALS['search_caller_collector_calls']=$canonicalCalls;
 if($php!==$native)$GLOBALS['acceptance_mismatches']=($GLOBALS['acceptance_mismatches']??0)+1;
 return $native; // Canonical caller assertions now inspect the actual active candidate.
}
putenv('VAAK_SEARCH_NATIVE_SECRET=independent-search-acceptance-token-0001');putenv('VAAK_SEARCH_NATIVE_RENDER=0');
file_put_contents('/tmp/vaak-search-acceptance-pairs.jsonl','');eval(substr($source,5));echo 'Independent raw HTML diagnostic mismatches: '.($GLOBALS['acceptance_mismatches']??0).'; actual paired policy checks: '.($GLOBALS['acceptance_policy_checks']??0)."\n";
