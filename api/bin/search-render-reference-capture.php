<?php
declare(strict_types=1);
/** Actual Search painters, offline SQL transports inherited from the card corpus.
 * No admission claim: this file captures presentation and authoritative helper
 * decisions separately. Cold relationships and authenticated responses remain
 * distinct source/context gates.
 */
ob_start();require __DIR__.'/migration-card-reference-capture.php';$cards=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$dbSource=(string)file_get_contents(dirname(__DIR__).'/ap-db.php');preg_match_all('/^function\s+(ap_search_[A-Za-z0-9_]+)\s*\(/m',$dbSource,$searchFunctions);reference_load(dirname(__DIR__).'/ap-db.php',$searchFunctions[1]);
ap_db()->exec('ALTER TABLE ap_users ADD COLUMN actor_key TEXT');ap_db()->exec("UPDATE ap_users SET actor_key=CASE id WHEN 1 THEN 'fixture' ELSE 'other' END");
ap_db()->exec('CREATE TABLE IF NOT EXISTS bsky_posts(bsky_uri TEXT)');
reference_load(dirname(__DIR__).'/ap-vaak-profile-route.php',['ap_vaak_normalize_actor_key','ap_vaak_pretty_profile_path']);
reference_load(dirname(__DIR__).'/ap-db.php',['ap_remote_emoji_map_for_host','ap_remote_emoji_ensure_host','ap_remote_emoji_ensure_for_display','ap_emoji_html']);
ap_db()->exec('CREATE TABLE IF NOT EXISTS remote_custom_emojis(host TEXT,shortcode TEXT,url TEXT)');
ap_db()->exec('CREATE TABLE IF NOT EXISTS remote_emoji_host_meta(host TEXT,fetched_at TEXT,source TEXT)');
ap_db()->exec("INSERT INTO remote_custom_emojis VALUES('remote.test','wave','https://remote.test/wave.png?x=1&y=2')");
$GLOBALS['vaak_user']=['id'=>1,'username'=>'fixture'];$GLOBALS['vaak_actor_key']='fixture';
$captures=[];
foreach ([['name'=>'rust','following'=>false],['name'=>'café & tea','following'=>true],['name'=>"\u{00A0}tag\u{2009}",'following'=>'0'],['name'=>'','following'=>true],['name'=>'0','following'=>'1']] as $i=>$tag) {
 ob_start();admin_search_render_hashtag($tag);$captures['tag_'.$i]=['kind'=>'hashtag','input'=>$tag,'html'=>ob_get_clean()];
}
$base=['id'=>'','uri'=>'https://remote.test/users/alice','url'=>'https://remote.test/@alice','username'=>'alice','acct'=>'alice@remote.test','display_name'=>'Alice'];
foreach (['remote'=>$base,'following'=>$base,'local'=>array_replace($base,['uri'=>'https://mkultra.monster/users/Another/','url'=>'https://mkultra.monster/users/Another','username'=>'Another']),'own'=>array_replace($base,['uri'=>vaak_actor_id(),'url'=>vaak_actor_id(),'username'=>'fixture','acct'=>'fixture']),'empty'=>array_replace($base,['uri'=>'','url'=>'']),'unicode'=>array_replace($base,['display_name'=>"\u{00A0}Alice\u{2009}"]),'emoji'=>array_replace($base,['display_name'=>'Alice :wave: & :unknown:'])] as $name=>$acc) {
 $following=$name==='following'?[$acc['uri']=>true]:[];
 $uri=rtrim($acc['uri'],'/');$url=rtrim($acc['url'],'/');$target=$uri!==''?$uri:$url;$key='';$acct=$acc['acct'];
 if(preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#',$target,$match)){$key=strtolower($match[1]);$target='https://mkultra.monster/users/'.rawurlencode($key);$acct=(string)($acc['username']??$key);}
 $me=vaak_is_own_url($target)||vaak_is_own_url($uri)||vaak_is_own_url($url)||($acct!==''&&(strtolower($acct)===strtolower(admin_search_viewer_username())||strtolower($acct)===strtolower(admin_search_viewer_username().'@mkultra.monster')));
 $facts=['is_me'=>$me,'following'=>admin_is_following($following,$target,$url,$uri),'profile_href'=>$key!==''?ap_vaak_pretty_profile_path($key,['from'=>'search']):'?view=remote_profile&actor='.urlencode($target).'&from=search','open_label'=>admin_open_profile_label($target!==''?$target:$url)];
 $facts['emoji_map']=ap_remote_emoji_map_for_host((string)(parse_url($target,PHP_URL_HOST)??''));
 ob_start();admin_search_render_account($acc,$following);$captures['account_'.$name]=['kind'=>'account','input'=>$acc,'facts'=>$facts,'following_ids'=>$following,'owner_actor'=>vaak_actor_id(),'viewer_username'=>admin_search_viewer_username(),'html'=>ob_get_clean()];
}
foreach ([['q'=>'a & café','counts'=>[1,0,2],'more'=>[false,false,true]],['q'=>"\u{00A0}",'counts'=>[0,1,1],'more'=>[true,true,false]]] as $i=>$case) {
 $results=[];$more=[];foreach(['accounts','hashtags','statuses'] as $j=>$section){$results[$section]=array_fill(0,$case['counts'][$j],null);$more[$section]=$case['more'][$j];}
 ob_start();admin_search_render_summary($case['q'],$results,$more);$captures['summary_'.$i]=['kind'=>'summary','input'=>$case,'html'=>ob_get_clean()];
}
foreach(['accepted','pending','fallback'] as $state){$case=$cards['captures']['quote'];$case['status']['quote']['state']=$state;$cards['captures']['quote_'.$state]=$case;}
$requiredStatuses=['plain','cw_media','own','poll_open','ask','sensitive_auto_unblur','cw_auto_unblur','saved_flags','boost','quote','quote_accepted','quote_pending','quote_fallback','bsky_normalized','bsky_sensitive','allowed_late_quote'];
foreach ($cards['captures'] as $name=>$case) {
 if(empty($case['expected_admitted'])||!in_array($name,$requiredStatuses,true))continue;
 // Restore current authoritative SQL for every selected presentation case;
 // earlier corpus contexts must not be paired with its last painted DB state.
 foreach(['masto_favourites'=>'saved_favourites','masto_bookmarks'=>'saved_bookmarks','masto_reblogs'=>'saved_reblogs','masto_statuses'=>'local_status_rows','masto_polls'=>'poll_rows','fixture_mutes'=>'moderation_mutes','fixture_blocks'=>'moderation_blocks','fixture_words'=>'moderation_words','fixture_server_blocks'=>'server_moderation','events'=>'audience_events','outbox_notes'=>'audience_outbox','following'=>'following','bsky_hide_dids'=>'native_bsky_hide_dids','fixture_status_cache'=>'cached_status_payloads'] as $table=>$field){
  ap_db()->exec('DELETE FROM '.$table);
  foreach($case['context'][$field]??[] as $row){$keys=array_keys($row);ap_db()->prepare('INSERT INTO '.$table.'('.implode(',',$keys).') VALUES('.implode(',',array_fill(0,count($keys),'?')).')')->execute(array_values($row));}
 }
 unset($GLOBALS['ap_masto_flag_memo']);
 ap_db()->exec('UPDATE actor_profile SET auto_unblur_sensitive='.(int)!empty($case['context']['profile']['auto_unblur_sensitive']));
 $source=is_array($case['normalized_status']??null)?$case['normalized_status']:$case['status'];
 $seedSource=function(array $source)use(&$seedSource):void {
  $uri=rtrim((string)($source['uri']??$source['url']??''),'/');$post=$source['bsky_post']??null;$post=is_array($post['post']??null)?$post['post']:$post;
  if(is_array($post)&&!empty($post['uri']))ap_db()->prepare('INSERT INTO bsky_posts VALUES(?)')->execute([$post['uri']]);
  elseif($uri!==''&&!str_starts_with($uri,'https://mkultra.monster/users/'))ap_db()->prepare('INSERT INTO events VALUES(?,?,?,?,?,?)')->execute([100,$uri,'Create','log',(string)($source['visibility']??'public'),(string)($source['account']['uri']??'')]);
  foreach(['reblog','quote','vaak_quote_preview'] as $field)if(is_array($source[$field]??null)){$child=$source[$field];$child=is_array($child['quoted_status']??null)?$child['quoted_status']:$child;if(!empty($child['uri'])||!empty($child['url']))$seedSource($child);}
 };
 $seedSource($source);
 foreach($case['context']['cached_status_payloads']??[] as $cached){$payload=json_decode((string)$cached['payload'],true,512,JSON_THROW_ON_ERROR);if(is_array($payload))$seedSource($payload);}
 $GLOBALS['fixture_render_clock']=time();ob_start();admin_render_masto_status_card($source,[],'search',false,true);$html=ob_get_clean();
 if(!str_contains($html,'<article'))throw new RuntimeException('required current public Search source was rejected: '.$name);
 $ctx=$case['context'];$ctx['rendered_at']=$GLOBALS['fixture_render_clock'];$ctx['return_view']='search';$ctx['lazy_media']=false;
 $ctx['admission_verified']=false; // Shared painter capture is not Search admission proof.
 $ctx['profile']=ap_profile_get((string)$GLOBALS['vaak_actor_key']);
 foreach(['masto_favourites'=>'saved_favourites','masto_bookmarks'=>'saved_bookmarks','masto_reblogs'=>'saved_reblogs','masto_statuses'=>'local_status_rows','masto_polls'=>'poll_rows'] as $table=>$field)$ctx[$field]=ap_db()->query('SELECT * FROM '.$table)->fetchAll();
 $ctx['audience_events']=ap_db()->query('SELECT * FROM events')->fetchAll();$ctx['search_bsky_rows']=ap_db()->query('SELECT * FROM bsky_posts')->fetchAll();
 $captures['status_'.$name]=['kind'=>'status','status'=>$source,'context'=>$ctx,'html'=>$html,'native_support'=>$name==='allowed_late_quote'?'php_fallback':'required'];
}
foreach($requiredStatuses as $name)if(!isset($captures['status_'.$name]))throw new RuntimeException('missing required Search card '.$name);
unset($GLOBALS['fixture_render_clock']);
echo json_encode(['captures'=>$captures,'case_count'=>count($captures),'admission_verified'=>false,'promotion_allowed'=>false],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR)."\n";
