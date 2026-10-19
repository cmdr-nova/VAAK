<?php
declare(strict_types=1);
/** Declaration-only actual controller/helpers, SQLite, owned public XRPC replies; no live session/network. */
ob_start();require __DIR__.'/migration-card-reference-capture.php';$corpus=json_decode((string)ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
define('AP_BSKY_PUBLIC_API','https://public.api.bsky.app');define('AP_BSKY_POST_TEXT_MAX',4000);
function ap_bsky_xrpc(string $base,string $nsid,string $method,array $query=[],mixed $body=null,?string $bearer=null,int $timeout=8):array{
 $GLOBALS['thread_calls'][]=compact('base','nsid','method','query','bearer','timeout');
 if(is_callable($GLOBALS['thread_mutation']??null))($GLOBALS['thread_mutation'])();
 if($nsid==='app.bsky.feed.getPostThread')return $GLOBALS['thread_reply'];
 if($nsid==='app.bsky.feed.getPosts'&&!empty($GLOBALS['thread_posts_fault']))return ['ok'=>false];
 if($nsid==='app.bsky.feed.getPosts')return ['ok'=>true,'json'=>['posts'=>[$GLOBALS['thread_focus']]]];
 return ['ok'=>false];
}
reference_load(dirname(__DIR__).'/ap-bsky.php',['ap_bsky_post_observations_migrate','ap_bsky_observation_fresh_seconds','ap_bsky_observation_stamp','ap_bsky_post_observations_refresh','ap_bsky_post_observations_refresh_batch','ap_bsky_index_feed_post_links','ap_bsky_post_link_upsert','ap_bsky_posts_migrate','ap_bsky_post_observation_touch','ap_bsky_avatar_public_url','ap_bsky_avatar_cid_ok','ap_bsky_post_embed_compact','ap_bsky_compact_label_list','ap_bsky_compact_facets','ap_bsky_post_upsert_from_feed_item','ap_bsky_post_item_by_uri','ap_bsky_feed_item_from_any_url','ap_bsky_at_uri_from_any_url','ap_bsky_at_uri_from_https','ap_bsky_crossposts_migrate','ap_bsky_crosspost_by_uri','ap_bsky_post_links_migrate','ap_bsky_post_link_by_uri','ap_bsky_local_note_id_for_at_uri','ap_bsky_post_embed_media_thin','ap_bsky_record_embed_looks_media','ap_bsky_repair_thin_media_embed','ap_bsky_embed_video_view','ap_bsky_blob_cid','ap_bsky_record_embed_view','ap_bsky_record_embed_synth_compact','ap_bsky_cached_quote_view']);
reference_load(dirname(__DIR__).'/ap-db.php',['ap_masto_status_by_note_id','ap_actor_is_content_blocked','ap_masto_status_is_pinned','ap_followers_list','ap_following_list','ap_follow_lists_cache_gen']);
ap_db()->exec('ALTER TABLE ap_users ADD COLUMN actor_key TEXT');ap_db()->exec("UPDATE ap_users SET actor_key='fixture' WHERE id=1");
foreach(['bsky_sessions'=>'owner_user_id INTEGER, did TEXT','bsky_hide_dids'=>'owner_user_id INTEGER,did TEXT','ap_user_blocks'=>'owner_user_id INTEGER,scope TEXT,value TEXT','ap_mutes'=>'owner_user_id INTEGER,actor_id TEXT','ap_muted_words'=>'owner_user_id INTEGER,phrase TEXT','ap_blocks'=>'scope TEXT,value TEXT,kind TEXT'] as $t=>$cols)ap_db()->exec('CREATE TABLE IF NOT EXISTS '.$t.'('.$cols.')');
ap_db()->exec('CREATE TABLE IF NOT EXISTS actor_profile(actor_key TEXT PRIMARY KEY)');
$base=$corpus['captures']['bsky_normalized']['normalized_status']['bsky_post'];$base=is_array($base['post']??null)?$base['post']:$base;
function thread_post(string $key,string $text):array{$p=$GLOBALS['thread_base'];$p['uri']='at://did:plc:fixture/app.bsky.feed.post/'.$key;$p['record']['text']=$text;return $p;}
$GLOBALS['thread_base']=$base;$focus=thread_post('focus','Focused post');$parent=thread_post('parent','Parent post');$grand=thread_post('grand','Oldest post');$child=thread_post('child','First reply');$next=thread_post('next','Nested reply');
$focus['record']['reply']['parent']['uri']=$parent['uri'];$parent['record']['reply']['parent']['uri']=$grand['uri'];$child['record']['reply']['parent']['uri']=$focus['uri'];$next['record']['reply']['parent']['uri']=$child['uri'];
$tree=['$type'=>'app.bsky.feed.defs#threadViewPost','post'=>$focus,'parent'=>['$type'=>'app.bsky.feed.defs#threadViewPost','post'=>$parent,'parent'=>['$type'=>'app.bsky.feed.defs#threadViewPost','post'=>$grand]],'replies'=>[['$type'=>'app.bsky.feed.defs#threadViewPost','post'=>$child,'replies'=>[['$type'=>'app.bsky.feed.defs#threadViewPost','post'=>$next]]],['$type'=>'app.bsky.feed.defs#blockedPost','uri'=>$next['uri']]]];
$GLOBALS['thread_focus']=$focus;ap_bsky_post_upsert_from_feed_item(['post'=>$focus],1);
$GLOBALS['thread_reply']=['ok'=>true,'json'=>['thread'=>$tree]];$GLOBALS['thread_calls']=[];$GLOBALS['view']='status';
$checks=0;function thread_check(bool $ok,string $why):void{if(!$ok)throw new RuntimeException($why);$GLOBALS['checks']++;}
$posts=admin_bsky_thread_posts($tree,$focus['uri'],true,true);thread_check(array_column($posts,'uri')===[$grand['uri'],$parent['uri'],$child['uri'],$next['uri']],'oldest ancestor + original reply order');
$html=admin_bsky_thread_fragment($focus['uri'],'ancestors','home',[]);thread_check(is_string($html)&&str_contains($html,'Oldest post')&&str_contains($html,'Parent post'),'actual ancestors fragment');
$html=admin_bsky_thread_fragment($focus['uri'],'replies','home',[]);thread_check(is_string($html)&&str_contains($html,'First reply')&&str_contains($html,'Nested reply'),'actual replies fragment');
thread_check(count($GLOBALS['thread_calls'])===2,'one public thread request per part');foreach($GLOBALS['thread_calls'] as $call)thread_check($call['base']===AP_BSKY_PUBLIC_API&&$call['bearer']===null&&$call['timeout']===4,'public bounded transport no private credential');
$GLOBALS['thread_reply']['json']['thread']['post']['uri']=$child['uri'];thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null,'wrong returned focus refused');$GLOBALS['thread_reply']['json']['thread']=$tree;
$GLOBALS['thread_mutation']=static function(){ap_db()->exec("INSERT INTO ap_muted_words VALUES(1,'Changed during transport')");$GLOBALS['thread_mutation']=null;};thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null,'durable policy-row mutation refuses buffer (no Settings POST claim)');ap_db()->exec('DELETE FROM ap_muted_words');
$GLOBALS['thread_mutation']=static function(){ap_db()->exec("DELETE FROM bsky_posts WHERE bsky_uri='at://did:plc:fixture/app.bsky.feed.post/focus'");$GLOBALS['thread_mutation']=null;};thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null,'source withdrawn during transport refuses');ap_bsky_post_upsert_from_feed_item(['post'=>$focus],1);
$GLOBALS['thread_reply']['json']['thread']['$type']='app.bsky.feed.defs#blockedPost';thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null,'blocked root sentinel refuses even if post field supplied');$GLOBALS['thread_reply']['json']['thread']=$tree;
$bad=$tree;$bad['replies'][0]['post']['author']['did']='did:plc:impostor';thread_check(admin_bsky_thread_posts($bad,$focus['uri'],false,true)===[],'malformed author plus disconnected nested reply suppressed');
$cycle=$tree;$cycle['parent']['parent']=$cycle;thread_check(count(admin_bsky_thread_posts($cycle,$focus['uri'],true,false))===1,'ancestor cycle bounded');
$wide=$tree;$wide['replies']=[];for($i=0;$i<90;$i++){$p=thread_post('wide'.$i,'Wide reply');$p['record']['reply']['parent']['uri']=$focus['uri'];$wide['replies'][]=['$type'=>'app.bsky.feed.defs#threadViewPost','post'=>$p];}thread_check(count(admin_bsky_thread_posts($wide,$focus['uri'],false,true))===40,'forty node cap');
ap_db()->exec("INSERT INTO fixture_words VALUES(1,'focused post')");thread_check(ap_muted_words_match(1,'Focused post','')!==null,'root phrase is an active normalized mute');
thread_check(is_string(admin_bsky_thread_fragment($focus['uri'],'focus','home',[])),'explicit focused word mute still permits Open');ap_db()->exec('DELETE FROM fixture_words');
ap_db()->exec("INSERT INTO fixture_words VALUES(1,'first reply')");$html=admin_bsky_thread_fragment($focus['uri'],'replies','home',[]);thread_check(is_string($html)&&!str_contains($html,'First reply'),'discovered replies retain strict word mute');ap_db()->exec('DELETE FROM fixture_words');
ap_db()->exec("INSERT INTO bsky_hide_dids(owner_user_id,did) VALUES(1,'did:plc:fixture')");$before=count($GLOBALS['thread_calls']);thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null&&count($GLOBALS['thread_calls'])===$before,'hidden focus does not authorize discovery');ap_db()->exec('DELETE FROM bsky_hide_dids');
ap_db()->exec('ALTER TABLE ap_users ADD COLUMN disabled_at TEXT');ap_db()->exec("UPDATE ap_users SET disabled_at='disabled' WHERE id=1");thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null,'disabled owner refused');ap_db()->exec('UPDATE ap_users SET disabled_at=NULL');
$GLOBALS['thread_mutation']=static function(){$GLOBALS['vaak_owner_id']=2;$GLOBALS['thread_mutation']=null;};thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null,'owner binding change during transport refused');$GLOBALS['vaak_owner_id']=1;
// Canonical union sentinels and malformed bodies must never authorize publication.
foreach (['app.bsky.feed.defs#notFoundPost', 'unknown#threadViewPost', null] as $type) {
 $GLOBALS['thread_reply']=['ok'=>true,'json'=>['thread'=>$tree]];
 $GLOBALS['thread_reply']['json']['thread']['$type']=$type;
 thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null,'non-ThreadViewPost root refused');
}
foreach (['post','author','record','uri'] as $field) {
 $malformed=$tree;
 if($field==='post')$malformed['post']=null;
 else $malformed['post'][$field]=null;
 $GLOBALS['thread_reply']=['ok'=>true,'json'=>['thread'=>$malformed]];
 thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null,'malformed root '.$field.' refused');
}
$GLOBALS['thread_reply']=['ok'=>true,'json'=>['thread'=>$tree]];
$wrongChild=$tree;$wrongChild['replies'][0]['$type']='app.bsky.feed.defs#notFoundPost';
thread_check(admin_bsky_thread_posts($wrongChild,$focus['uri'],false,true)===[],'canonical nested sentinel cannot carry a post field');
$before=count($GLOBALS['thread_calls']);
thread_check(admin_bsky_thread_target_uri('https://bsky.app/profile/alice.bsky.test/post/focus')===$focus['uri'],'exact cached handle identity resolves');
thread_check(admin_bsky_thread_target_uri('https://bsky.app/profile/other.test/post/focus')===null,'same rkey different handle cannot borrow cached source');
thread_check(count($GLOBALS['thread_calls'])===$before+1,'unknown handle only uses bounded public identity transport');
// Explicit Open permits a muted root, while discovery keeps personal filtering.
ap_db()->exec("INSERT INTO fixture_mutes VALUES(1,'did:plc:fixture')");
thread_check(is_string(admin_bsky_thread_fragment($focus['uri'],'focus','home',[])),'explicit muted focus remains openable');
$html=admin_bsky_thread_fragment($focus['uri'],'replies','home',[]);
thread_check($html==='','muted discovered authors suppressed');ap_db()->exec('DELETE FROM fixture_mutes');
ap_db()->exec("INSERT INTO fixture_blocks VALUES(1,'actor','did:plc:fixture')");
thread_check(admin_bsky_thread_fragment($focus['uri'],'focus','home',[])===null,'personal blocked focus denied');ap_db()->exec('DELETE FROM fixture_blocks');
$guestContext=[];foreach(['vaak_user','vaak_owner_id','vaak_actor_id','vaak_actor_key','vaak_guest_profile'] as $key)$guestContext[$key]=$GLOBALS[$key]??null;
$GLOBALS['vaak_user']=['id'=>0,'username'=>'guest','actor_key'=>'','display_name'=>'Guest','is_guest'=>true];
$GLOBALS['vaak_owner_id']=0;$GLOBALS['vaak_actor_id']='';$GLOBALS['vaak_actor_key']='';$GLOBALS['vaak_guest_profile']=true;
thread_check(admin_owner_user_id()===0,'genuine guest owner resolves zero');
$html=admin_bsky_thread_fragment($focus['uri'],'replies','home',[]);
thread_check(is_string($html)&&str_contains($html,'First reply')&&!str_contains($html,'<form')&&!str_contains($html,'data-bsky-action='),'public owner-zero guest paints nonempty read-only thread');
ap_db()->exec("INSERT INTO fixture_server_blocks VALUES('actor','did:plc:fixture','block')");
thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null,'owner-zero guest server block denied');ap_db()->exec('DELETE FROM fixture_server_blocks');
foreach($guestContext as $key=>$value){if($value===null)unset($GLOBALS[$key]);else $GLOBALS[$key]=$value;}
// Nested material does not inherit the explicit root word exemption.
$nested=$focus;$nested['embed']=['$type'=>'app.bsky.embed.record#view','record'=>['text'=>'Nested restricted phrase','author'=>['did'=>'did:plc:fixture','handle'=>'alice.bsky.test']]];
ap_db()->exec("INSERT INTO fixture_words VALUES(1,'restricted phrase')");
thread_check(!admin_bsky_thread_focus_allowed(['post'=>$nested],1),'nested quote words deny explicit focus');ap_db()->exec('DELETE FROM fixture_words');
$nested['embed']['record']['author']['viewer']['blockedBy']=true;
thread_check(!admin_bsky_thread_focus_allowed(['post'=>$nested],1),'nested blockedBy denies explicit focus');
// An indexed withdrawn twin must not resurrect its bridged public copy.
$q=ap_db()->prepare('INSERT INTO bsky_crossposts(note_id,bsky_uri,created_at) VALUES(?,?,?)');$q->execute(['https://mkultra.monster/users/fixture/notes/withdrawn',$focus['uri'],gmdate('c')]);
$before=count($GLOBALS['thread_calls']);
thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null&&count($GLOBALS['thread_calls'])===$before,'missing local root twin refuses discovery');
ap_db()->exec('DELETE FROM bsky_crossposts');
reference_load(dirname(__DIR__).'/ap-masto-entities.php',['ap_masto_status_from_row','ap_masto_account_for_local_url','ap_masto_local_actor_key_from_url','ap_masto_account_from_user','ap_masto_snowflake_id','ap_masto_status_media_attachments','ap_masto_poll_entity_for_status','ap_masto_quote_url_from_create_json','ap_masto_quote_entity','ap_masto_apply_interaction_flags']);
ap_db()->exec("UPDATE ap_users SET actor_key='other' WHERE id=2");
ap_db()->exec('ALTER TABLE followers ADD COLUMN followed_at TEXT');ap_db()->exec('ALTER TABLE following ADD COLUMN followed_at TEXT');
$local='https://mkultra.monster/users/other/notes/threadfixture';
$q=ap_db()->prepare('INSERT INTO masto_statuses VALUES(?,?,?,?,?,?)');$q->execute([$local,'Local twin reply',0,'',0,'public']);
ap_db()->prepare('INSERT INTO outbox_notes VALUES(?,?,?,?,?,?)')->execute([$local,'','Local twin reply','public','[]','[]']);
$q=ap_db()->prepare('INSERT INTO bsky_crossposts(note_id,bsky_uri,created_at) VALUES(?,?,?)');$q->execute([$local,$child['uri'],gmdate('c')]);
$html=admin_bsky_thread_fragment($focus['uri'],'replies','home',[]);
thread_check(is_string($html)&&str_contains($html,'Local twin reply')&&!str_contains($html,'First reply'),'current public local twin replaces bridged reply');
ap_db()->prepare("UPDATE outbox_notes SET visibility='private' WHERE id=?")->execute([$local]);
$html=admin_bsky_thread_fragment($focus['uri'],'replies','home',[]);
thread_check(is_string($html)&&!str_contains($html,'Local twin reply')&&!str_contains($html,'First reply'),'private local twin cannot revive public bridge');
ap_db()->prepare("UPDATE outbox_notes SET visibility='public' WHERE id=?")->execute([$local]);
$GLOBALS['thread_mutation']=static function()use($local){ap_db()->prepare("UPDATE outbox_notes SET visibility='private' WHERE id=?")->execute([$local]);$GLOBALS['thread_mutation']=null;};
$html=admin_bsky_thread_fragment($focus['uri'],'replies','home',[]);
thread_check(is_string($html)&&!str_contains($html,'Local twin reply')&&!str_contains($html,'First reply'),'twin private during transport uses current raw row');
ap_db()->exec('DELETE FROM bsky_crossposts');

// Cold source must be exact before genuine cache upsert; no wrong-post poisoning.
ap_db()->prepare('DELETE FROM bsky_posts WHERE bsky_uri=?')->execute([$focus['uri']]);
$GLOBALS['thread_focus']=$child;
thread_check(admin_bsky_thread_fragment($focus['uri'],'focus','home',[])===null,'cold getPosts wrong requested URI refused before write');
$q=ap_db()->prepare('SELECT COUNT(*) FROM bsky_posts WHERE bsky_uri=?');$q->execute([$focus['uri']]);thread_check((int)$q->fetchColumn()===0,'wrong getPosts cannot forge focus cache authority');
$GLOBALS['thread_focus']=$focus;
thread_check(is_string(admin_bsky_thread_fragment($focus['uri'],'focus','home',[])),'genuine cold focus transport/cache path succeeds');
// Execute the genuine original repair as the oracle: returned paint and durable cache differ.
$thin=$focus;$thin['record']['text']='Cached repair text';$thin['embed']=['$type'=>'app.bsky.embed.images#view'];$thin['likeCount']=1;
$live=$focus;$live['record']['text']='Live repair text';$live['author']['displayName']='Live repair author';$live['likeCount']=19;
$live['labels']=[['val'=>'nudity']];$live['record']['selfLabels']=['$type'=>'com.atproto.label.defs#selfLabels','values'=>[['val'=>'sexual']]];
$live['record']['embed']=['$type'=>'app.bsky.embed.images','images'=>[]];
$live['embed']=['$type'=>'app.bsky.embed.images#view','images'=>[['fullsize'=>'https://media.test/full.jpg','thumb'=>'https://media.test/thumb.jpg','alt'=>'Repair vector']]];
foreach(['media','labels-only','scalar-embed','unknown-compact','fault'] as $vector){
 $incoming=$live;
 if($vector==='labels-only')unset($incoming['embed']);
 if($vector==='scalar-embed')$incoming['embed']='malformed';
 if($vector==='unknown-compact')$incoming['embed']=['$type'=>'unknown#view'];
 $GLOBALS['thread_focus']=$incoming;$GLOBALS['thread_posts_fault']=$vector==='fault';
 ap_bsky_post_upsert_from_feed_item(['post'=>$thin],1);$originalCached=ap_bsky_post_item_by_uri($focus['uri']);
 thread_check(ap_bsky_post_embed_media_thin($originalCached['post']),'actual thin predicate '.$vector);
 $GLOBALS['admin_bsky_gallery_repair_budget']=2;$GLOBALS['vaak_bsky_force_media_repair']=true;
 $expected=ap_bsky_repair_thin_media_embed($originalCached['post'],1);unset($GLOBALS['vaak_bsky_force_media_repair']);
 $expectedStored=ap_bsky_post_item_by_uri($focus['uri'])['post'];
 ap_bsky_post_upsert_from_feed_item(['post'=>$thin],1);
 $actual=admin_bsky_thread_focus_item($focus['uri'],1,true);
 thread_check(is_array($actual)&&$actual['post']===$expected,'returned paint matches original '.$vector);
 thread_check(ap_bsky_post_item_by_uri($focus['uri'])['post']===$expectedStored,'durable post matches original '.$vector);
 if($vector==='media')thread_check($actual['post']['record']['text']==='Cached repair text'&&$expectedStored['record']['text']==='Live repair text','live durable refresh differs from cached returned paint');
}
$GLOBALS['thread_posts_fault']=false;unset($GLOBALS['admin_bsky_gallery_repair_budget']);
foreach(['wrong-uri','wrong-author','malformed-record'] as $vector){
 $incoming=$live;
 if($vector==='wrong-uri')$incoming['uri']=$child['uri'];
 if($vector==='wrong-author')$incoming['author']['did']='did:plc:impostor';
 if($vector==='malformed-record')$incoming['record']=null;
 ap_bsky_post_upsert_from_feed_item(['post'=>$thin],1);$beforePost=ap_bsky_post_item_by_uri($focus['uri'])['post'];$GLOBALS['thread_focus']=$incoming;
 thread_check(admin_bsky_thread_focus_item($focus['uri'],1,true)===null,'repair identity/shape rejected '.$vector);
 thread_check(ap_bsky_post_item_by_uri($focus['uri'])['post']===$beforePost,'invalid repair has no durable post effect '.$vector);
}
$GLOBALS['thread_focus']=$focus;ap_bsky_post_upsert_from_feed_item(['post'=>$focus],1);
$GLOBALS['thread_reply']=['ok'=>false];thread_check(admin_bsky_thread_fragment($focus['uri'],'replies','home',[])===null,'transport fault refuses');
// Labels read before Bluesky moderation acted: the focus re-reads them.
$young=$focus;$young['uri']='at://did:plc:fixture/app.bsky.feed.post/young';$young['record']['createdAt']=gmdate('c',time()-140);$young['labels']=[];unset($young['indexedAt'],$young['embed']);
ap_bsky_post_upsert_from_feed_item($young,1);
$liveYoung=$young;$liveYoung['indexedAt']=gmdate('c',time()-139);$liveYoung['labels']=[['val'=>'porn','src'=>'did:plc:ar7c4by46qjdydhdevvrndac']];$liveYoung['author']['labels']=[['val'=>'sexual']];
$GLOBALS['thread_focus']=$liveYoung;$GLOBALS['thread_posts_fault']=false;$GLOBALS['admin_bsky_gallery_repair_budget']=2;
$got=admin_bsky_thread_focus_item($young['uri'],1,true);unset($GLOBALS['admin_bsky_gallery_repair_budget']);
thread_check(is_array($got)&&ap_bsky_post_is_sensitive($got['post'])&&($got['post']['author']['labels'][0]['val']??'')==='sexual','stale focus labels refreshed from AppView');
thread_check(ap_bsky_post_is_sensitive(ap_bsky_post_item_by_uri($young['uri'])['post']),'refreshed labels stored');
$GLOBALS['thread_focus']=$focus;
echo "bsky-thread-fragment-smoke PASS $checks actual helper/controller checks; declaration fixture only\n";
