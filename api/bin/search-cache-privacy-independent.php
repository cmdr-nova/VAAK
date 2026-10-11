<?php
/** Actual Search cache/filter/final painters with isolated source hydration IO. */
declare(strict_types=1);
function ap_db_masto_owner_user_id():int{return (int)$GLOBALS['vaak_owner_id'];}
function ap_masto_search_statuses(string $q,int $limit):array {
 $GLOBALS['review_source_calls']=($GLOBALS['review_source_calls']??0)+1;
 // Actual owner-scoped ranked query; only source hydration transport is fixture.
 $hits=ap_search_fts_ranked_hits('keyword*','',121,'fedi',false);
 return array_map(static fn($hit)=>$GLOBALS['review_statuses'][(int)$hit['source_pk']],$hits);
}
ob_start();require __DIR__.'/migration-card-reference-capture.php';$refs=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
// Isolated Redis IO transport is introduced after the unrelated card corpus,
// whose intentional profile mutations assume its original uncached transport.
eval('function ap_redis_json_get(string $key):mixed{return $GLOBALS["review_api_cache"][$key]??null;} function ap_redis_json_set(string $key,mixed $value,int $ttl):void{$GLOBALS["review_api_cache"][$key]=$value;$GLOBALS["review_api_keys"][]=$key;}');
require dirname(__DIR__).'/ap-search-fts.php';
$api=dirname(__DIR__);
reference_load($api.'/ap-db.php',['ap_search_canonical_actor','ap_search_actor_ref_list','ap_search_owner_identity','ap_search_refs_are_self','ap_search_actor_hidden','ap_search_account_hidden','ap_search_status_hidden','ap_search_filter_statuses']);
$dbSource=file_get_contents($api.'/ap-db.php');preg_match_all('/^function (ap_search_[a-z_]+)\(/m',$dbSource,$helpers);reference_load($api.'/ap-db.php',$helpers[1]);
reference_load($api.'/ap-masto-entities.php',['ap_masto_search_sanitize_statuses']);
reference_load($api.'/ap-masto-entities.php',['ap_masto_search_hashtags','ap_masto_tag_entity','ap_masto_normalize_tag_name','ap_masto_tag_is_following']);
reference_load($api.'/ap-masto-entities.php',['ap_masto_search','ap_masto_search_query_parts','ap_masto_search_accounts','ap_masto_search_sanitize_accounts']);
// Dependency bootstrap is already loaded by the actual painter fixture. Extract
// the production run unchanged except require_once IO, avoiding redeclarations.
$src=file_get_contents($api.'/ap-admin.php');preg_match('/^function admin_search_run\(.*?^}/ms',$src,$m);
$run=str_replace('function admin_search_run(','function review_admin_search_run(',$m[0]);
$run=preg_replace('/^\s*require_once __DIR__ .*?;$/m','',$run);eval($run);
preg_match('/^function admin_search_page_fragment\(.*?^}/ms',$src,$pageCode);eval(str_replace(['function admin_search_page_fragment(','admin_search_run('],['function review_admin_search_page_fragment(','review_admin_search_run('],$pageCode[0]));
foreach(['fixture_words','fixture_mutes','fixture_blocks','fixture_server_blocks','mentions','events'] as $table)ap_db()->exec('DELETE FROM '.$table);
// The painter corpus declares all profile columns TEXT; restore production-like
// INTEGER preference affinity for the actual FTS query's COALESCE comparison.
$cols=ap_db()->query('PRAGMA table_info(actor_profile)')->fetchAll();$select=implode(',',array_map(static fn($c)=>$c['name']==='indexable'?'CAST(indexable AS INTEGER) AS indexable':$c['name'],$cols));
ap_db()->exec('CREATE TABLE profile_typed AS SELECT '.$select.' FROM actor_profile;DROP TABLE actor_profile;ALTER TABLE profile_typed RENAME TO actor_profile');
ap_db()->exec("ALTER TABLE ap_users ADD COLUMN actor_key TEXT;UPDATE ap_users SET actor_key=CASE id WHEN 1 THEN 'fixture' ELSE 'other' END;INSERT INTO actor_profile(actor_key,name,indexable,discoverable) VALUES('other','Other author',0,1);
CREATE TABLE ap_search_docs(id INTEGER PRIMARY KEY,source TEXT,source_pk INTEGER,object_id TEXT,created_at TEXT,body TEXT);
CREATE VIRTUAL TABLE ap_search_fts USING fts5(body,content='ap_search_docs',content_rowid='id');
INSERT INTO masto_statuses VALUES('https://mkultra.monster/users/other/notes/700','Search private marker',700,'',0,'public');
INSERT INTO outbox_notes VALUES('https://mkultra.monster/users/other/notes/700','{}','Search private marker','public','[]','[]');
INSERT INTO ap_search_docs VALUES(700,'status',700,'https://mkultra.monster/users/other/notes/700','2026-10-10','keyword');INSERT INTO ap_search_fts(ap_search_fts) VALUES('rebuild');");
$st=$refs['captures']['plain']['status'];$st['id']='700';$st['uri']=$st['url']='https://mkultra.monster/users/other/notes/700';$st['content']='Search private marker';$st['account']=['id'=>'2','uri'=>'https://mkultra.monster/users/other','acct'=>'other','display_name'=>'Other author'];
$GLOBALS['review_statuses']=[700=>$st];$GLOBALS['review_source_calls']=0;
if(($argv[1]??'')==='--setup-only')return;
function review_search_html(string $q):array {
 $result=review_admin_search_run($q,'statuses',false,20,0);ob_start();admin_search_render_sections($result,$q,'statuses',false,20,[]);$html=ob_get_clean();
 return ['rows'=>count($result['statuses']),'visible'=>str_contains($html,'Search private marker'),'current_audience_allowed'=>ap_visibility_status_audience_allowed($GLOBALS['review_statuses'][700],(int)$GLOBALS['vaak_owner_id']),'source_calls'=>$GLOBALS['review_source_calls']];
}
$q='#reviewcache'.bin2hex(random_bytes(6));$dir=(is_dir('/var/lib/mkultra/ap/search-cache')&&is_writable('/var/lib/mkultra/ap/search-cache')?'/var/lib/mkultra/ap/search-cache':sys_get_temp_dir().'/vaak-search-cache');$omitQ=$q.'omit';$cacheFiles=[];
foreach([$q,$omitQ] as $query)foreach([1,2] as $owner){$prefix=str_contains($src,"'mf3|'")?'mf3|'.$owner.'|':'mf2|';$cacheFiles[$query][$owner]=$dir.'/s_'.hash('sha256',$prefix.mb_strtolower($query).'|0|121|80').'.json';}$cache=$cacheFiles[$q][1];
$out=[];
try{
 $GLOBALS['vaak_owner_id']=2;$out['author_warm']=review_search_html($q);
 $GLOBALS['vaak_owner_id']=1;$out['other_current_fts_hits']=count(ap_search_fts_ranked_hits('keyword*','',121,'fedi',false));$out['other_warm_cache']=review_search_html($q);
 $out['other_warm_empty']=review_search_html($omitQ);$GLOBALS['vaak_owner_id']=2;$out['author_current_fts_hits']=count(ap_search_fts_ranked_hits('keyword*','',121,'fedi',false));$out['author_after_other_empty']=review_search_html($omitQ);$GLOBALS['vaak_owner_id']=1;
 ap_db()->exec("UPDATE actor_profile SET indexable=1 WHERE actor_key='other'");unlink($cache);$out['public_warm']=review_search_html($q);
 ap_db()->exec("UPDATE actor_profile SET indexable=0 WHERE actor_key='other'");$out['withdraw_indexable']=review_search_html($q);
 ap_db()->exec("UPDATE actor_profile SET indexable=1 WHERE actor_key='other'");
 ap_db()->exec("UPDATE masto_statuses SET visibility='private' WHERE local_id=700;UPDATE outbox_notes SET visibility='private'");$out['withdraw_audience']=review_search_html($q);
 ap_db()->exec("INSERT INTO followers VALUES('https://mkultra.monster/users/other','https://mkultra.monster/users/fixture')");$out['accepted_follower']=review_search_html($q);
 ap_db()->exec('DELETE FROM followers');$out['revoked_follower']=review_search_html($q);
 ap_db()->exec("UPDATE outbox_notes SET visibility='direct',to_json='[\"https://mkultra.monster/users/fixture\"]'");$out['direct_to_recipient']=review_search_html($q);
 ap_db()->exec("UPDATE outbox_notes SET to_json='[]',cc_json='[\"https://mkultra.monster/users/fixture\"]'");$out['direct_cc_recipient']=review_search_html($q);
 ap_db()->exec("UPDATE outbox_notes SET cc_json='[]'");$out['revoked_direct']=review_search_html($q);
 ap_db()->exec("UPDATE outbox_notes SET visibility='unlisted'");$out['unlisted_control']=review_search_html($q);
 ap_db()->exec("UPDATE outbox_notes SET visibility='private'");
 ap_db()->exec("UPDATE outbox_notes SET visibility='public';DELETE FROM masto_statuses WHERE local_id=700;DELETE FROM outbox_notes");$out['delete_after_warm']=review_search_html($q);
 ap_db()->exec("INSERT INTO masto_statuses VALUES('https://mkultra.monster/users/other/notes/700','Search private marker',700,'',0,'public');INSERT INTO outbox_notes VALUES('https://mkultra.monster/users/other/notes/700','{}','Search private marker','public','[]','[]')");
 ap_db()->exec("INSERT INTO fixture_blocks VALUES(1,'actor','https://mkultra.monster/users/other')");$out['block_after_warm']=review_search_html($q);
 ap_db()->exec("DELETE FROM fixture_blocks;INSERT INTO fixture_mutes VALUES(1,'https://mkultra.monster/users/other')");$out['actor_mute_after_warm']=review_search_html($q);ap_db()->exec('DELETE FROM fixture_mutes');
 ap_db()->exec("DELETE FROM fixture_blocks;INSERT INTO fixture_words VALUES(1,'Search private marker')");$out['word_after_warm']=review_search_html($q);
 ap_db()->exec('DELETE FROM fixture_words');
 ap_db()->exec("UPDATE outbox_notes SET visibility='private'");
 $child=$GLOBALS['review_statuses'][700];$child['content']='Nested private marker';
 $parent=$refs['captures']['plain']['status'];$parent['content']='Outer public post';$parent['quote']=['quoted_status'=>$child];
 ap_db()->prepare('INSERT INTO events VALUES(1,?,"Create","log","public",?)')->execute([$parent['uri'],$parent['account']['uri']]);
 $sanitized=ap_masto_search_sanitize_statuses([$parent]);$filtered=ap_search_filter_statuses($sanitized,1,20);ob_start();admin_search_render_sections(['statuses'=>$filtered],'nested','statuses',false,20,[]);$nestedHtml=ob_get_clean();
 $out['nested_private_quote']=['current_audience_allowed'=>ap_visibility_status_audience_allowed($parent,1),'rows'=>count($filtered),'private_child_visible'=>str_contains($nestedHtml,'Nested private marker'),'ordinary_outer_visible'=>str_contains($nestedHtml,'Outer public post')];
 reference_load($api.'/ap-rss.php',['ap_rss_item_by_mirror_note_id']);
 $mirror=$refs['captures']['plain']['status'];$mirror['id']='mirror';$mirror['uri']=$mirror['url']='https://remote.test/ordinary-public-rss-mirror';$mirror['content']='Foreign public AS2 mirror';
 ap_db()->prepare('INSERT INTO events VALUES(2,?,"Create","log","public",?)')->execute([$mirror['uri'],$mirror['account']['uri']]);
 ap_db()->prepare('UPDATE rss_items SET mirror_note_id=? WHERE id=22')->execute([$mirror['uri']]);
 $parent['quote']=['quoted_status'=>$mirror];ob_start();admin_search_render_sections(['statuses'=>ap_search_filter_statuses(ap_masto_search_sanitize_statuses([$parent]),1,20)],'mirror','statuses',false,20,[]);$mirrorHtml=ob_get_clean();
 $out['ordinary_foreign_public_rss_mirror']=['public_body_visible'=>str_contains($mirrorHtml,'Foreign public AS2 mirror'),'foreign_subscription_metadata_visible'=>str_contains($mirrorHtml,'Private RSS')||str_contains($mirrorHtml,'Other owner secret')];
 $leaf=$mirror;$leaf['account']['uri']='https://quote.test/users/isolated';$leaf['account']['acct']='isolated@quote.test';$leaf['content']='Blocked child marker';ap_db()->exec("UPDATE events SET actor_id='https://quote.test/users/isolated' WHERE id=2");
 $moderatedParent=$parent;$moderatedParent['quote']=['quoted_status'=>$leaf];
 foreach(['personal_block','personal_mute','server_block','server_mute'] as $kind){
  foreach(['fixture_blocks','fixture_mutes','fixture_server_blocks'] as $table)ap_db()->exec('DELETE FROM '.$table);
  if($kind==='personal_block')ap_db()->exec("INSERT INTO fixture_blocks VALUES(1,'actor','https://quote.test/users/isolated')");
  elseif($kind==='personal_mute')ap_db()->exec("INSERT INTO fixture_mutes VALUES(1,'https://quote.test/users/isolated')");
  else ap_db()->prepare('INSERT INTO fixture_server_blocks VALUES("actor",?,?)')->execute(['https://quote.test/users/isolated',$kind==='server_mute'?'mute':'block']);
  $rows=ap_search_filter_statuses(ap_masto_search_sanitize_statuses([$moderatedParent]),1,20);ob_start();admin_search_render_sections(['statuses'=>$rows],'nested_moderation','statuses',false,20,[]);$html=ob_get_clean();
  $out['nested_'.$kind]=['rows'=>count($rows),'current_child_hidden'=>ap_search_account_hidden($leaf['account'],1),'child_visible'=>str_contains($html,'Blocked child marker')];
 }
 foreach(['fixture_blocks','fixture_mutes','fixture_server_blocks'] as $table)ap_db()->exec('DELETE FROM '.$table);
 $late=$refs['captures']['allowed_late_quote']['status'];$late['content']='Late ordinary public parent';$lateUri=$late['quote_url'];
 $lateChild=$mirror;$lateChild['id']='late';$lateChild['uri']=$lateChild['url']=$lateUri;$lateChild['content']='Late private quoted marker';$lateChild['account']['uri']='https://quote.test/users/author';
 ap_db()->prepare('INSERT OR REPLACE INTO fixture_status_cache VALUES(?,?)')->execute([$lateUri,json_encode($lateChild)]);
 ap_db()->prepare('INSERT INTO events VALUES(3,?,"Create","log","private",?)')->execute([$lateUri,$lateChild['account']['uri']]);
 $lateRows=ap_search_filter_statuses(ap_masto_search_sanitize_statuses([$late]),1,20);ob_start();admin_search_render_sections(['statuses'=>$lateRows],'late','statuses',false,20,[]);$lateHtml=ob_get_clean();
 $out['late_private_quote']=['parent_rows'=>count($lateRows),'current_child_audience_allowed'=>ap_visibility_status_audience_allowed($lateChild,1),'private_child_visible'=>str_contains($lateHtml,'Late private quoted marker'),'ordinary_parent_visible'=>str_contains($lateHtml,'Late ordinary public parent')];
 ap_db()->exec("UPDATE events SET visibility='public' WHERE id=3");
 foreach(['pending'=>$late,'pending_null'=>array_replace($late,['quote'=>['state'=>'pending','quoted_status'=>null]]),'full_public_quote'=>$refs['captures']['quote']['status'],'full_public_boost'=>$refs['captures']['boost']['status']] as $name=>$variant){
  $rows=ap_search_filter_statuses(ap_masto_search_sanitize_statuses([$variant]),1,20);ob_start();admin_search_render_sections(['statuses'=>$rows],$name,'statuses',false,20,[]);$html=ob_get_clean();
  ob_start();admin_render_masto_status_card($variant,[],'search',false,true);$directHtml=ob_get_clean();
  $out['positive_'.$name]=['rows'=>count($rows),'html_nonempty'=>$html!=='','canonical_direct_card_nonempty'=>$directHtml!=='','late_public_child_visible'=>str_contains($html,'Late private quoted marker'),'canonical_direct_late_public_child_visible'=>str_contains($directHtml,'Late private quoted marker')];
 }
 ap_db()->exec('ALTER TABLE outbox_notes ADD COLUMN published TEXT;ALTER TABLE events ADD COLUMN summary TEXT;ALTER TABLE mentions ADD COLUMN content TEXT');
 $privateTag='privateonlytag'.bin2hex(random_bytes(6));ap_db()->prepare('UPDATE outbox_notes SET content=?,published=?')->execute(['Private-only #'.$privateTag,'2026-10-10']);
 $tags=ap_masto_search_hashtags($privateTag,20);ob_start();foreach($tags as $tag)admin_search_render_hashtag($tag);$tagHtml=ob_get_clean();
 $out['private_only_hashtag']=['tags'=>array_column($tags,'name'),'visible'=>str_contains($tagHtml,$privateTag),'viewer'=>1,'author'=>2,'current_visibility'=>'private','to'=>[],'cc'=>[]];
 $tagQ='#'.$privateTag;foreach([1,2] as $owner){$prefix=str_contains($src,"'mf3|'")?'mf3|'.$owner.'|':'mf2|';$cacheFiles[$tagQ][$owner]=$dir.'/s_'.hash('sha256',$prefix.mb_strtolower($tagQ).'|0|121|80').'.json';}
 $readTag=static function()use($tagQ,$privateTag):array{$result=review_admin_search_run($tagQ,'hashtags',false,20,0);ob_start();admin_search_render_sections($result,$tagQ,'hashtags',false,20,[]);$html=ob_get_clean();return ['rows'=>count($result['hashtags']),'visible'=>str_contains($html,$privateTag)];};
 ap_db()->exec("UPDATE outbox_notes SET visibility='public'");$out['tag_public_warm']=$readTag();
 ap_db()->exec("UPDATE outbox_notes SET visibility='private'");$out['tag_audience_withdraw_after_warm']=$readTag();
 ap_db()->exec("INSERT INTO followers VALUES('https://mkultra.monster/users/other','https://mkultra.monster/users/fixture')");$out['tag_accepted_follower']=$readTag();ap_db()->exec('DELETE FROM followers');$out['tag_revoked_follower']=$readTag();
 ap_db()->exec("UPDATE outbox_notes SET visibility='public';UPDATE actor_profile SET indexable=0 WHERE actor_key='other'");$out['tag_indexing_withdraw_after_warm']=$readTag();
 $GLOBALS['vaak_owner_id']=2;$out['tag_author_optout_exception']=$readTag();$GLOBALS['vaak_owner_id']=1;
 ap_db()->exec("UPDATE actor_profile SET indexable=1 WHERE actor_key='other';DELETE FROM outbox_notes");$out['tag_deleted_after_warm']=$readTag();
 $remoteTag='deletedremotetag'.bin2hex(random_bytes(6));$remoteObject='https://remote.test/deleted-tag-object';
 ap_db()->prepare('INSERT INTO events VALUES(10,?,"Create","log","public","https://remote.test/users/alice",?)')->execute([$remoteObject,'Public #'.$remoteTag]);
 $out['tag_remote_public_before_delete']=array_column(ap_masto_search_hashtags($remoteTag,20),'name');
 ap_db()->prepare('INSERT INTO events VALUES(11,?,"Delete","log","public","https://remote.test/users/alice",NULL)')->execute([$remoteObject]);
 $out['tag_remote_after_delete']=array_column(ap_masto_search_hashtags($remoteTag,20),'name');
 ap_db()->exec("UPDATE events SET type='Tombstone' WHERE id=11");$out['tag_remote_after_tombstone']=array_column(ap_masto_search_hashtags($remoteTag,20),'name');
 ap_db()->exec("UPDATE events SET visibility='private' WHERE id=3");
 for($i=0;$i<12;$i++){
  $id=900+$i;$uri='https://remote.test/search-scroll/'.$id;$variant=$refs['captures']['plain']['status'];$variant['id']=(string)$id;$variant['uri']=$variant['url']=$uri;$variant['content']='Valid next Search page '.$id;
  if($i<10){$variant['quote']=['state'=>'pending'];$variant['quote_url']=$lateUri;}$GLOBALS['review_statuses'][$id]=$variant;
  ap_db()->prepare('INSERT INTO events VALUES(?,? ,"Create","log","public",?,NULL)')->execute([$id,$uri,$variant['account']['uri']]);
  ap_db()->prepare('INSERT INTO ap_search_docs VALUES(?,"event",?,?,?,"keyword")')->execute([$id,$id,$uri,'2099-01-'.sprintf('%02d',12-$i)]);
 }
 ap_db()->exec("INSERT INTO ap_search_fts(ap_search_fts) VALUES('rebuild')");
 $pageQ=$q.'pages';foreach([1,2] as $owner){$prefix=str_contains($src,"'mf3|'")?'mf3|'.$owner.'|':'mf2|';$cacheFiles[$pageQ][$owner]=$dir.'/s_'.hash('sha256',$prefix.mb_strtolower($pageQ).'|0|121|80').'.json';}
 $firstPage=review_admin_search_page_fragment($pageQ,'statuses',false,0,10);$nextPage=review_admin_search_page_fragment($pageQ,'statuses',false,10,10);
 $out['zero_painted_cursor_page']=['has_more'=>$firstPage['has_more'],'offset_advanced'=>str_contains($firstPage['html'],'data-offset="10"'),'article_count'=>substr_count($firstPage['html'],'<article'),'private_child_visible'=>str_contains($firstPage['html'],'Late private quoted marker')];
 $out['valid_next_cursor_page']=['has_more'=>$nextPage['has_more'],'offset_advanced'=>str_contains($nextPage['html'],'data-offset="12"'),'article_count'=>substr_count($nextPage['html'],'<article'),'valid_body_visible'=>str_contains($nextPage['html'],'Valid next Search page')];
 file_put_contents('/tmp/vaak-search-independent-cursor-fragments.json',json_encode(['first'=>$firstPage,'next'=>$nextPage],JSON_THROW_ON_ERROR));
 $GLOBALS['review_api_cache']=[];$GLOBALS['review_api_keys']=[];$apiTag='apiprivatetag'.bin2hex(random_bytes(6));
 ap_db()->prepare('INSERT INTO outbox_notes VALUES(?,?,?,"public","[]","[]",?)')->execute(['https://mkultra.monster/users/other/notes/700','{}','API-only #'.$apiTag,'2026-10-10']);
 $tagApiWarm=ap_masto_search($apiTag,'hashtags',false,20);ap_db()->exec("UPDATE outbox_notes SET visibility='private' WHERE id LIKE '%/700'");$tagApiAfter=ap_masto_search($apiTag,'hashtags',false,20);
 $GLOBALS['vaak_owner_id']=2;$tagApiAuthor=ap_masto_search($apiTag,'hashtags',false,20);$GLOBALS['vaak_owner_id']=1;
 $out['api_tag_cache_current']=['public_warm'=>count($tagApiWarm['hashtags']),'private_after_warm'=>count($tagApiAfter['hashtags']),'author_private'=>count($tagApiAuthor['hashtags']),'owner_keys_distinct'=>count(array_unique($GLOBALS['review_api_keys']))===2];
 $sourceCalls=$GLOBALS['review_source_calls'];$hostCold=ap_masto_search('example.test',null,false,20);$hostHit=ap_masto_search('example.test',null,false,20);
 $out['api_bare_host_cold_hit']=['cold_statuses'=>count($hostCold['statuses']),'cold_tags'=>count($hostCold['hashtags']),'hit_statuses'=>count($hostHit['statuses']),'hit_tags'=>count($hostHit['hashtags']),'status_source_called'=>$GLOBALS['review_source_calls']!==$sourceCalls];
 if(($argv[1]??'')!=='--observe'){
  foreach(['author_warm','author_after_other_empty','public_warm','accepted_follower','direct_to_recipient','direct_cc_recipient','unlisted_control','word_after_warm','tag_public_warm','tag_accepted_follower','tag_author_optout_exception'] as $case)if($out[$case]['rows']!==1||!$out[$case]['visible'])throw new RuntimeException('Search positive parity regression: '.$case);
  foreach(['other_warm_cache','other_warm_empty','withdraw_indexable','withdraw_audience','revoked_follower','revoked_direct','delete_after_warm','block_after_warm','actor_mute_after_warm','tag_audience_withdraw_after_warm','tag_revoked_follower','tag_indexing_withdraw_after_warm','tag_deleted_after_warm'] as $case)if($out[$case]['rows']!==0||$out[$case]['visible'])throw new RuntimeException('Search current privacy regression: '.$case);
  foreach(['nested_personal_block','nested_personal_mute','nested_server_block','nested_server_mute'] as $case)if(!$out[$case]['current_child_hidden']||$out[$case]['child_visible'])throw new RuntimeException('Search nested actor policy regression: '.$case);
  if($out['nested_private_quote']['private_child_visible']||$out['late_private_quote']['private_child_visible']||$out['late_private_quote']['parent_rows']!==1)throw new RuntimeException('Search final nested/late audience boundary regression');
  foreach(['positive_pending','positive_pending_null','positive_full_public_quote','positive_full_public_boost'] as $case)if($out[$case]['rows']!==1||!$out[$case]['html_nonempty'])throw new RuntimeException('Search descriptor/public source feature regression: '.$case);
  foreach(['positive_pending','positive_pending_null'] as $case)if(!$out[$case]['late_public_child_visible'])throw new RuntimeException('Search public late hydration lost: '.$case);
  if(!$out['ordinary_foreign_public_rss_mirror']['public_body_visible']||$out['ordinary_foreign_public_rss_mirror']['foreign_subscription_metadata_visible'])throw new RuntimeException('Normal public RSS mirror parity/metadata regression');
  if($out['private_only_hashtag']['visible']||$out['tag_remote_after_delete']!==[]||$out['tag_remote_after_tombstone']!==[])throw new RuntimeException('Search private/deleted tag regression');
  if(!$out['zero_painted_cursor_page']['has_more']||!$out['zero_painted_cursor_page']['offset_advanced']||$out['zero_painted_cursor_page']['article_count']!==0||$out['zero_painted_cursor_page']['private_child_visible'])throw new RuntimeException('Search zero-painted cursor page regression');
  if($out['valid_next_cursor_page']['has_more']||!$out['valid_next_cursor_page']['offset_advanced']||$out['valid_next_cursor_page']['article_count']!==2||!$out['valid_next_cursor_page']['valid_body_visible'])throw new RuntimeException('Search following valid cursor page regression');
  if($out['api_tag_cache_current']!==['public_warm'=>1,'private_after_warm'=>0,'author_private'=>1,'owner_keys_distinct'=>true])throw new RuntimeException('API Search owner/cache/tag freshness regression');
  if($out['api_bare_host_cold_hit']!==['cold_statuses'=>0,'cold_tags'=>0,'hit_statuses'=>0,'hit_tags'=>0,'status_source_called'=>false])throw new RuntimeException('API bare-host account-only cold/cache parity regression');
 }
 echo json_encode($out,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR)."\n";
}finally{foreach($cacheFiles as $files)foreach($files as $file)if(is_file($file))unlink($file);}
