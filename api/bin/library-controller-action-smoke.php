<?php
declare(strict_types=1);
/** Real PHP POST branches + writers, disposable SQLite. No application bootstrap,
 * federation transport, production URL, or replacement permission predicate. */
require_once __DIR__.'/migration-reference-functions.php';
if (!extension_loaded('mbstring') || !extension_loaded('pdo_sqlite')) throw new RuntimeException('Requires real mbstring and pdo_sqlite; use php-policy-test');
const AP_AUTH_CSRF_KEY = '_vaak_csrf';
const LOCAL_KEY_ID='https://local.test/fixture#key';const LOCAL_PRIV='/tmp/vaak-fixture-unused-key';
function admin_tl_cache_clear(): void {ap_db()->exec("INSERT INTO transport_log(kind,json) VALUES('timeline-clear','{}')");}
function ap_deliver_public_activity(array $activity,array $extra=[],bool $skipBridge=false): array {
    ap_db()->prepare('INSERT INTO transport_log(kind,json) VALUES(?,?)')->execute(['delivery',json_encode(['activity'=>$activity,'skip_bridge'=>$skipBridge])]);
    return ['delivered'=>0,'queued'=>0,'bridgy'=>false];
}
function ap_metrics_record(mixed ...$args): void {ap_db()->prepare('INSERT INTO transport_log(kind,json) VALUES(?,?)')->execute(['metrics',json_encode($args)]);}
function ap_log(string $line): void {ap_db()->prepare('INSERT INTO transport_log(kind,json) VALUES(?,?)')->execute(['log',json_encode($line)]);}
function ap_bsky_delete_crosspost_for_note(int $owner,string $note): array {return ['ok'=>true,'skipped'=>true];}

// Boundary adapters: session already established by test host; schema provisioned;
// Redis invalidations recorded to the same isolated database, never real Redis.
function ap_auth_start_session(): void {}
function ap_db(): PDO { return $GLOBALS['fixture_db']; }
function ap_rss_migrate(): void {}
function ap_redis_library_html_invalidate(int $owner,string $view): void { ap_db()->prepare('INSERT INTO invalidations VALUES(?,?)')->execute([$owner,$view]); }
$root=dirname(__DIR__);
reference_load($root.'/ap-auth.php',['ap_auth_csrf_ok']);
reference_load($root.'/ap-db.php',['ap_db_driver','ap_db_last_insert_id','ap_db_now','ap_db_session_bound','ap_db_session_owner_user_id','ap_db_default_owner_user_id','ap_db_owner_actor_id_for_user_id','ap_masto_canonical_interaction_keys','ap_masto_object_id_lookup_keys','ap_masto_favourite_row','ap_masto_favourite_add','ap_masto_favourite_remove','ap_masto_bookmark_add','ap_masto_bookmark_remove','ap_masto_reblog_add','ap_masto_reblog_row_by_status','ap_masto_reblog_remove','ap_local_actor_id','ap_masto_status_by_local_id','ap_masto_status_is_pinned','ap_masto_status_pin','ap_masto_status_unpin']);
reference_load($root.'/ap-admin.php',['vaak_actor_id','vaak_is_own_url']);
reference_load($root.'/ap-masto-entities.php',['ap_masto_session_actor_id','ap_masto_format_time','ap_masto_poll_entity','ap_masto_parse_public_status_id','ap_masto_local_row_from_public_id','ap_masto_mention_id_from_status_id','ap_masto_event_id_from_status_id','ap_masto_dm_id_from_status_id','ap_masto_resolve_status_interaction']);
reference_load($root.'/ap-inbox.php',['ap_masto_poll_vote']);
reference_load($root.'/ap-db.php',['ap_masto_reblog_row_by_boost_id','ap_local_object_is_direct_message']);
reference_load($root.'/ap-action-queue.php',['ap_action_queue_make_tid','ap_action_queue_enqueue','ap_action_queue_status']);
reference_load($root.'/ap-signals.php',['ap_signal_record']);
reference_load($root.'/ap-db.php',['ap_feature_enabled']);
reference_load($root.'/ap-bsky.php',['ap_bsky_tab_enabled']);
reference_load($root.'/ap-admin.php',['admin_bsky_bookmark_keys']);
reference_load($root.'/ap-inbox.php',['ap_outbound_identity','ap_update_local_status','ap_delete_local_status']);
reference_load($root.'/ap-r2.php',['ap_media_by_local_ids','ap_media_as2_attachments']);
reference_load($root.'/ap-db.php',['ap_fix_utf8','ap_quote_ld_context','ap_note_interaction_policy_public','ap_as2_normalize_scalars','ap_as2_ensure_top_context','ap_note_ensure_quote_policy','ap_create_finalize']);
preg_match_all('/^function (ap_masto_[a-zA-Z0-9_]+)\(/m',(string)file_get_contents($root.'/ap-masto-entities.php'),$entityNames);
reference_load($root.'/ap-masto-entities.php',$entityNames[1]);
reference_load($root.'/ap-rss.php',['ap_rss_item_by_id','ap_rss_local_status_id','ap_rss_parse_local_status_id']);
reference_load($root.'/ap-bookmark-folders.php',['vaak_bookmark_folder_fedi_match_sql','vaak_bookmark_folder_saved_keys','vaak_bookmark_folders_ensure_schema','vaak_bookmark_folders_list','vaak_bookmark_folder_create','vaak_bookmark_folder_delete','vaak_bookmark_folder_add_status','vaak_bookmark_folder_remove_status','vaak_bookmark_folders_on_unbookmark','vaak_bookmark_folders_for_status']);
/** Exact source spans, checked sentinels; no copied/reimplemented controller. */
function source_span(string $source,string $start,string $end): string {
    $a=strpos($source,$start);$b=$a===false?false:strpos($source,$end,$a+strlen($start));
    if($a===false||$b===false) throw new RuntimeException('Controller source sentinel moved');
    return substr($source,$a,$b-$a);
}
if (($argv[1]??'')==='--request') {
    $request=json_decode((string)stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
    $path=(string)($request['db']??'');
    if(!str_starts_with($path,sys_get_temp_dir().'/vaak-library-controller-')) throw new RuntimeException('Refusing non-fixture DB');
    $GLOBALS['fixture_db']=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
    $_POST=$request['post'];$_SERVER['REQUEST_METHOD']='POST';$_SESSION[AP_AUTH_CSRF_KEY]='fixture-csrf-token';
    $vaakOwnerId=(int)$request['owner'];$GLOBALS['vaak_owner_id']=$vaakOwnerId;$GLOBALS['vaak_actor_key']='fixture';$GLOBALS['vaak_actor_id']=$vaakOwnerId===201?'https://local.test/users/a':'https://local.test/users/b';
    $GLOBALS['vaak_guest_profile']=$vaakOwnerId<1;$error=null;$notice=null;
    $source=(string)file_get_contents($root.'/ap-admin.php');
    putenv('VAAK_FEATURE_BLUESKY_TAB='.(($request['post']['fixture_disable_bsky']??false)?'0':'1'));
    // Bootstrap include adapter: all used declarations are loaded unchanged above.
    // Prevent production bootstrap while retaining exact branch decisions/effects.
    $source=str_replace("require_once __DIR__ . '/ap-inbox.php';",'/* isolated declaration bootstrap */',$source);
    $gate=source_span($source,"if ((\$_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {",'    // Server-wide / operator-only mutations');
    // Exact guest/CSRF code runs before the exact interaction and folder branches.
    eval($gate."\n}");
    $poll=source_span($source,"    if (\$action === 'poll_vote') {", "    if (in_array(\$action, ['favourite_status', 'unfavourite_status'");
    eval($poll);
    $actions=source_span($source,"    if (in_array(\$action, ['favourite_status', 'unfavourite_status'", "    } elseif (\$action === 'reply') {");
    eval($actions."\n}");
    $bsky=source_span($source,"    } elseif (in_array(\$action, ['bsky_like', 'bsky_unlike'", "        \$ajaxOut = ['ok' => false, 'action' => \$action, 'uri' => \$subject];");
    $bsky=preg_replace('/^    } elseif /','    if ',$bsky,1);eval($bsky."\n}");
    $own=source_span($source,"    } elseif (\$action === 'edit_status') {", "    } elseif (\$action === 'delete_bsky_post') {");
    $own=preg_replace('/^    } elseif /','    if ',$own,1);eval($own."\n}");
    if(in_array($action,['pin_status','unpin_status'],true)) {
        $pin=source_span($source,"    } elseif (\$action === 'pin_status' ||", "    } elseif (\$action === 'edit_status') {");
        $pin=preg_replace('/^    } elseif /','    if ',$pin,1);
        eval($pin."\n}");
        // Controller has no JSON pin response: surface its actual error/notice.
        echo json_encode(['ok'=>$error===null&&$notice!==null,'error'=>$error,'notice'=>$notice,'view'=>$view,'get'=>$_GET]);exit;
    }
    echo json_encode(['ok'=>false,'error'=>$error??'No selected fixture branch']);exit;
}
$path=tempnam(sys_get_temp_dir(),'vaak-library-controller-');
if($path===false) throw new RuntimeException('temp DB failed');
$db=new PDO('sqlite:'.$path,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['fixture_db']=$db;
$db->exec("CREATE TABLE ap_users(id INTEGER PRIMARY KEY,actor_id TEXT);INSERT INTO ap_users VALUES(201,'https://local.test/users/a'),(202,'https://local.test/users/b');
CREATE TABLE rss_feeds(id INTEGER PRIMARY KEY,owner_user_id INTEGER,title TEXT,favicon_url TEXT,site_url TEXT,feed_url TEXT);INSERT INTO rss_feeds VALUES(1,201,'A','','','https://feed.test/a'),(2,202,'B','','','https://feed.test/b');
CREATE TABLE rss_items(id INTEGER PRIMARY KEY,feed_id INTEGER);INSERT INTO rss_items VALUES(11,1),(22,2);
CREATE TABLE masto_favourites(owner_user_id INTEGER,owner_actor_id TEXT,status_id TEXT,object_id TEXT,target_actor TEXT,like_activity_id TEXT,created_at TEXT,PRIMARY KEY(owner_user_id,status_id));
CREATE TABLE masto_bookmarks(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,owner_actor_id TEXT,status_id TEXT,object_id TEXT,created_at TEXT,UNIQUE(owner_user_id,status_id));
CREATE TABLE masto_reblogs(owner_user_id INTEGER,owner_actor_id TEXT,status_id TEXT,boost_status_id TEXT,object_id TEXT,target_actor TEXT,announce_activity_id TEXT,created_at TEXT,PRIMARY KEY(owner_user_id,status_id));
CREATE TABLE vaak_bookmark_folders(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,title TEXT,position INTEGER,created_at TEXT,updated_at TEXT);
CREATE TABLE invalidations(owner INTEGER,view TEXT);
CREATE TABLE masto_statuses(local_id INTEGER PRIMARY KEY,note_id TEXT,visibility TEXT,content_text TEXT,spoiler_text TEXT,sensitive INTEGER,edited_at TEXT,published TEXT,create_id TEXT);
INSERT INTO masto_statuses(local_id,note_id,visibility) VALUES(1,'https://local.test/users/a/notes/aaa','private'),(2,'https://local.test/users/b/notes/bbb','public'),(3,'https://local.test/users/a/notes/ddd','direct');
CREATE TABLE events(id INTEGER PRIMARY KEY, object_id TEXT);
CREATE TABLE masto_media(local_id INTEGER PRIMARY KEY,status_local_id INTEGER,owner_user_id INTEGER,mime TEXT,public_url TEXT);
CREATE TABLE outbox_notes(id TEXT PRIMARY KEY,content TEXT,raw_create_json TEXT,to_json TEXT,cc_json TEXT,in_reply_to TEXT);
INSERT INTO outbox_notes VALUES('https://local.test/users/a/notes/aaa','old','{}','[\"https://local.test/users/a/followers\"]','[]',NULL);
CREATE TABLE transport_log(kind TEXT,json TEXT);
CREATE TABLE masto_pins(owner_user_id INTEGER,status_local_id INTEGER,pinned_at TEXT,PRIMARY KEY(owner_user_id,status_local_id));
CREATE TABLE masto_polls(local_id INTEGER PRIMARY KEY,status_local_id INTEGER,note_id TEXT,expires_at TEXT,multiple INTEGER,options_json TEXT,voters_json TEXT,votes_count INTEGER,voters_count INTEGER);
INSERT INTO masto_polls VALUES(1,0,'','2099-01-01T00:00:00Z',0,'[{\"title\":\"A\",\"votes_count\":0},{\"title\":\"B\",\"votes_count\":0}]','[]',0,0),(2,0,'','2000-01-01T00:00:00Z',0,'[{\"title\":\"A\",\"votes_count\":0}]','[]',0,0);
CREATE TABLE ap_action_queue(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,platform TEXT,action_kind TEXT,target_key TEXT,desired_state INTEGER,confirmed_state INTEGER,revision INTEGER,payload_json TEXT,status TEXT,attempts INTEGER,max_attempts INTEGER,next_attempt_at TEXT,claimed_at TEXT,last_error TEXT,last_error_host TEXT,last_error_code INTEGER,result_json TEXT,receipt_json TEXT,created_at TEXT,updated_at TEXT,UNIQUE(owner_user_id,platform,action_kind,target_key));
CREATE TABLE ap_user_signals(owner_user_id INTEGER,platform TEXT,signal_type TEXT,target_key TEXT,weight REAL,metadata_json TEXT,created_at TEXT);");
$checks=0;
function check(bool $ok,string $case): void { if(!$ok) throw new RuntimeException($case);$GLOBALS['checks']++; }
function request_action(int $owner,string $action,array $post=[]): array {
    $pipes=[];$proc=proc_open([__DIR__.'/php-policy-test',__FILE__,'--request'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    if(!is_resource($proc))throw new RuntimeException('child launch failed');
    fwrite($pipes[0],json_encode(['db'=>$GLOBALS['path'],'owner'=>$owner,'post'=>array_replace(['ajax'=>'1','csrf'=>'fixture-csrf-token','action'=>$action,'status_id'=>'rss:11','object_id'=>'rss:11','return_view'=>'bookmarks'],$post)]));fclose($pipes[0]);
    $output=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);$code=proc_close($proc);
    if($code!==0)throw new RuntimeException('request crashed: '.$err.$output);
    return json_decode($output,true,512,JSON_THROW_ON_ERROR);
}
function count_rows(string $table,int $owner): int { return (int)ap_db()->query('SELECT COUNT(*) FROM '.$table.' WHERE owner_user_id='.$owner)->fetchColumn(); }
try {
    check(!request_action(0,'favourite_status')['ok'],'guest denied');
    check(!request_action(201,'favourite_status',['csrf'=>'forged'])['ok'],'forged CSRF denied');
    check(!request_action(201,'favourite_status',['csrf'=>''])['ok'],'missing CSRF denied');
    check(count_rows('masto_favourites',201)===0,'auth failures have no durable writes');
    foreach(['favourite_status','bookmark_status','reblog_status'] as $action) {
        check(!request_action(201,$action,['status_id'=>'rss:22','object_id'=>'rss:22'])['ok'],'cross-owner RSS '.$action.' denied');
        check(!request_action(201,$action,['status_id'=>'rss:999'])['ok'],'missing RSS '.$action.' denied');
        $res=request_action(201,$action);check($res['ok']&&$res['active']===true&&$res['status_id']==='rss:11','valid '.$action.' response');
        check(request_action(201,$action)['ok'],'idempotent '.$action);
    }
    foreach(['masto_favourites','masto_bookmarks','masto_reblogs'] as $table) {
        check(count_rows($table,201)===1&&count_rows($table,202)===0,$table.' scoped and idempotent');
        check(ap_db()->query('SELECT owner_actor_id FROM '.$table)->fetchColumn()==='https://local.test/users/a',$table.' current actor');
    }
    check(ap_db()->query('SELECT announce_activity_id FROM masto_reblogs')->fetchColumn()==='local:rss-boost:11:201','RSS local receipt');
    $a=request_action(201,'bookmark_folder_create',['title'=>' A ']);$b=request_action(202,'bookmark_folder_create',['title'=>'B']);
    check($a['ok']&&$a['folder']['title']==='A'&&$b['ok'],'actual folder create controller');
    $folder=(int)$a['id'];
    check(!request_action(202,'bookmark_folder_add',['folder_id'=>$folder])['ok'],'foreign folder add denied');
    $bmBefore=count_rows('masto_bookmarks',201);$cacheBefore=(int)$db->query('SELECT COUNT(*) FROM invalidations')->fetchColumn();
    foreach(['rss:22','rss:999','rss:bad'] as $forged) {
        check(!request_action(201,'bookmark_folder_add',['folder_id'=>$folder,'status_id'=>$forged,'object_id'=>$forged])['ok'],'folder RSS scope rejects '.$forged);
        check(count_rows('masto_bookmarks',201)===$bmBefore&&count_rows('vaak_bookmark_folder_items',201)===0,'rejected RSS folder add has no durable effects '.$forged);
    }
    check((int)$db->query('SELECT COUNT(*) FROM invalidations')->fetchColumn()===$cacheBefore,'rejected RSS folder adds have no cache effects');
    check(request_action(201,'bookmark_folder_add',['folder_id'=>$folder])['ok'],'folder add actual bookmark writer');
    check(!empty(request_action(201,'bookmark_folder_add',['folder_id'=>$folder])['already']),'folder add idempotent response');
    check(!request_action(202,'bookmark_folder_delete',['folder_id'=>$folder])['ok'],'foreign folder delete denied');
    check(request_action(201,'bookmark_folder_remove',['folder_id'=>$folder])['ok']&&count_rows('masto_bookmarks',201)===1,'folder remove retains bookmark');
    request_action(201,'bookmark_folder_add',['folder_id'=>$folder]);
    $res=request_action(201,'unbookmark_status');check($res['ok']&&$res['active']===false&&!$res['open_folder_picker'],'unbookmark desired state');
    check(count_rows('masto_bookmarks',201)===0&&count_rows('vaak_bookmark_folder_items',201)===0,'unbookmark cleans actual memberships');
    foreach(['unfavourite_status'=>'masto_favourites','unreblog_status'=>'masto_reblogs'] as $action=>$table) {
        $res=request_action(201,$action);check($res['ok']&&$res['active']===false&&count_rows($table,201)===0,$action.' durable removal');
        check(request_action(201,$action)['ok'],'repeat undo '.$action);
    }
    check((int)$db->query('SELECT COUNT(*) FROM invalidations WHERE owner=201')->fetchColumn()>=6,'owner cache invalidation transport called');
    check((int)$db->query('SELECT COUNT(*) FROM invalidations WHERE owner=202')->fetchColumn()===0,'other owner cache unchanged');
    check(!request_action(201,'pin_status',['note_id'=>'https://local.test/users/b/notes/bbb'])['ok'],'pin foreign local post denied');
    check(!request_action(201,'pin_status',['note_id'=>'https://remote.test/post'])['ok'],'pin remote post denied');
    check(!request_action(201,'pin_status',['note_id'=>'https://local.test/users/a/notes/ddd'])['ok'],'pin direct post denied');
    check(request_action(201,'pin_status',['note_id'=>'https://local.test/users/a/notes/aaa','return_view'=>'status','from'=>'bookmarks'])['ok'],'pin current post succeeds');
    check(count_rows('masto_pins',201)===1,'pin durable owner receipt');
    check(request_action(201,'pin_status',['note_id'=>'https://local.test/users/a/notes/aaa'])['ok']&&count_rows('masto_pins',201)===1,'pin idempotent');
    check(!request_action(202,'unpin_status',['note_id'=>'https://local.test/users/a/notes/aaa'])['ok']&&count_rows('masto_pins',201)===1,'foreign unpin has no effect');
    $pinReturn=request_action(201,'unpin_status',['note_id'=>'https://local.test/users/a/notes/aaa','return_view'=>'status','from'=>'bookmarks']);
    check($pinReturn['ok']&&$pinReturn['view']==='status'&&$pinReturn['get']['from']==='bookmarks'&&count_rows('masto_pins',201)===0,'unpin durable removal and return context');
    check(!request_action(201,'poll_vote',['poll_id'=>2,'choices'=>[0]])['ok'],'expired poll denied');
    check(!request_action(201,'poll_vote',['poll_id'=>1,'choices'=>[99]])['ok'],'invalid poll choice denied');
    check(request_action(201,'poll_vote',['poll_id'=>1,'choices'=>[1]])['ok'],'actual vote records');
    check(!request_action(201,'poll_vote',['poll_id'=>1,'choices'=>[0]])['ok'],'duplicate owner vote denied');
    check(request_action(202,'poll_vote',['poll_id'=>1,'choices'=>[0]])['ok'],'second voter independently records');
    $poll=$db->query('SELECT * FROM masto_polls WHERE local_id=1')->fetch();
    check((int)$poll['votes_count']===2&&(int)$poll['voters_count']===2&&count(json_decode($poll['voters_json'],true))===2,'actual vote counts and owner actors');
    foreach(['favourite_status'=>'like','bookmark_status'=>'bookmark'] as $action=>$kind) {
        $post=['status_id'=>'999999','object_id'=>'https://remote.test/posts/queued','target_actor'=>'https://remote.test/users/author'];
        $res=request_action(201,$action,$post);check($res['ok']&&!empty($res['queued'])&&$res['revision']===1,'actual fedi queue '.$kind);
        $again=request_action(201,$action,$post);check($again['queue_id']===$res['queue_id']&&!empty($again['coalesced']),'actual coalesced '.$kind);
        $undo=$action==='favourite_status'?'unfavourite_status':'unbookmark_status';
        $res=request_action(201,$undo,$post);check($res['ok']&&$res['active']===false&&$res['revision']===2,'actual queued undo '.$kind);
        $q=$db->query('SELECT * FROM ap_action_queue WHERE id='.(int)$res['queue_id'])->fetch();
        check((int)$q['owner_user_id']===201&&(int)$q['desired_state']===0&&$q['platform']==='fedi'&&$q['status']==='pending','actual queued desired state '.$kind);
    }
    $bskyPost=['bsky_uri'=>'at://did:plc:author/app.bsky.feed.post/queued','bsky_cid'=>'fixture-cid'];
    check(!request_action(201,'bsky_like',$bskyPost+['fixture_disable_bsky'=>true])['ok'],'disabled Bluesky feature denies mutation');
    check(!request_action(201,'bsky_like',['bsky_uri'=>'https://invalid.test/post','bsky_cid'=>'fixture-cid'])['ok'],'invalid AT target denied');
    check(!request_action(201,'bsky_like',['bsky_uri'=>$bskyPost['bsky_uri']])['ok'],'missing create CID denied');
    foreach(['bsky_like'=>'like','bsky_repost'=>'boost','bsky_bookmark'=>'bookmark'] as $action=>$kind) {
        $res=request_action(201,$action,$bskyPost);check($res['ok']&&$res['queued']&&$res['revision']===1,'actual Bluesky queue '.$kind);
        if($kind==='bookmark')check(count_rows('masto_bookmarks',201)===1,'actual bsky bookmark enqueue writes independent AP save');
        $q=$db->query('SELECT * FROM ap_action_queue WHERE id='.(int)$res['queue_id'])->fetch();$payload=json_decode($q['payload_json'],true);
        check($q['platform']==='bsky'&&(int)$q['owner_user_id']===201&&$payload['uri']===$bskyPost['bsky_uri']&&$payload['cid']==='fixture-cid','Bluesky owner strongref '.$kind);
        if($kind!=='bookmark')check(strlen($payload['record_key'])===13,'stable generated record key '.$kind);
        $again=request_action(201,$action,$bskyPost);check($again['coalesced']&&$again['queue_id']===$res['queue_id'],'Bluesky desired coalescence '.$kind);
        $undo=['bsky_like'=>'bsky_unlike','bsky_repost'=>'bsky_unrepost','bsky_bookmark'=>'bsky_unbookmark'][$action];
        $un=request_action(201,$undo,$bskyPost);check($un['ok']&&$un['active']===false&&$un['revision']===2,'Bluesky queued undo '.$kind);
        if($kind==='bookmark')check(count_rows('masto_bookmarks',201)===0,'actual bsky unbookmark enqueue removes AP canonical save');
    }
    check(count_rows('ap_action_queue',202)===0,'queue writes isolate second account');
    check(!request_action(201,'edit_status',['note_id'=>'https://local.test/users/b/notes/bbb','content'=>'forged'])['ok'],'foreign edit denied');
    check(!request_action(201,'edit_status',['note_id'=>'https://local.test/users/a/notes/aaa','content'=>''])['ok'],'empty edit denied');
    check(!request_action(201,'edit_status',['note_id'=>'https://local.test/users/a/notes/aaa','content'=>str_repeat('é',2001)])['ok'],'overlimit Unicode edit denied');
    $own=request_action(201,'edit_status',['note_id'=>'https://local.test/users/a/notes/aaa','content'=>'Edited safely','spoiler_text'=>'Warning','sensitive'=>'0']);
    check($own['ok'],'actual edit succeeds');
    $edited=$db->query('SELECT * FROM masto_statuses WHERE local_id=1')->fetch();
    check($edited['content_text']==='Edited safely'&&$edited['spoiler_text']==='Warning'&&(int)$edited['sensitive']===1&&$edited['visibility']==='private','actual edit retains visibility and CW sensitivity');
    $outbox=$db->query("SELECT * FROM outbox_notes WHERE id='https://local.test/users/a/notes/aaa'")->fetch();
    check(json_decode($outbox['to_json'],true)===['https://local.test/users/a/followers']&&json_decode($outbox['cc_json'],true)===[],'edit preserves durable private audience');
    check(!request_action(201,'delete_status',['local_id'=>2,'note_id'=>'https://local.test/users/b/notes/bbb'])['ok'],'foreign delete denied');
    check((int)$db->query('SELECT COUNT(*) FROM masto_statuses WHERE local_id=2')->fetchColumn()===1,'foreign status retained');
    $deleted=request_action(201,'delete_status',['local_id'=>999999999,'note_id'=>'https://local.test/users/a/notes/aaa']);
    check($deleted['ok']&&$deleted['local_id']===1,'actual note ID resolves stale delete PK');
    check((int)$db->query('SELECT COUNT(*) FROM masto_statuses WHERE local_id=1')->fetchColumn()===0&&(int)$db->query("SELECT COUNT(*) FROM outbox_notes WHERE id='https://local.test/users/a/notes/aaa'")->fetchColumn()===0,'actual delete removes durable copies');
    $deliveries=$db->query("SELECT json FROM transport_log WHERE kind='delivery'")->fetchAll(PDO::FETCH_COLUMN);
    check(count($deliveries)===2,'actual Update and Delete delivery transport invocations');
    $update=json_decode($deliveries[0],true);$delete=json_decode($deliveries[1],true);
    check($update['activity']['type']==='Update'&&$update['skip_bridge']===true&&$update['activity']['object']['to']===['https://local.test/users/a/followers'],'edit AS2 privacy and bridge semantics');
    check($delete['activity']['type']==='Delete'&&$delete['activity']['to']===['https://local.test/users/a/followers']&&$delete['activity']['object']['type']==='Tombstone','delete private audience Tombstone');
    $actor='https://local.test/users/a';$public='https://www.w3.org/ns/activitystreams#Public';$followers=$actor.'/followers';
    $audiences=[
        ['private',[$followers],[],true],['direct',['https://recipient.test/users/peer'],[],true],
        ['unlisted',[$followers],[$public],true],['public',[$public],[$followers],true],
        ['private',[],[],true],['public','malformed-to','malformed-cc',true],['public',null,null,false],
    ];
    foreach($audiences as $n=>[$visibility,$to,$cc,$hasOutbox]) {
        $id=41+$n;$note=$actor.'/notes/'.dechex($id);
        $db->prepare('INSERT INTO masto_statuses(local_id,note_id,visibility) VALUES(?,?,?)')->execute([$id,$note,$visibility]);
        if($hasOutbox)$db->prepare('INSERT INTO outbox_notes(id,content,raw_create_json,to_json,cc_json) VALUES(?,?,?,?,?)')->execute([$note,'old','{}',is_array($to)?json_encode($to):$to,is_array($cc)?json_encode($cc):$cc]);
        $expectedTo=is_array($to)?$to:[$public];$expectedCc=is_array($cc)?$cc:[$followers];
        foreach(['edit_status','delete_status'] as $act) {
            check(!request_action(201,$act,['note_id'=>$note,'local_id'=>$id,'content'=>'Edit','csrf'=>'forged'])['ok'],'own '.$act.' CSRF denied case '.$n);
            check(!request_action(0,$act,['note_id'=>$note,'local_id'=>$id,'content'=>'Edit'])['ok'],'own '.$act.' guest denied case '.$n);
        }
        check(request_action(201,'edit_status',['note_id'=>$note,'content'=>'Edited audience case'])['ok'],'actual audience edit '.$n);
        $raw=$db->query("SELECT json FROM transport_log WHERE kind='delivery' ORDER BY rowid DESC LIMIT 1")->fetchColumn();$envelope=json_decode($raw,true)['activity'];
        check($envelope['to']===$expectedTo&&$envelope['cc']===$expectedCc&&$envelope['object']['to']===$expectedTo&&$envelope['object']['cc']===$expectedCc,'Update exact stored/fallback audience '.$n);
        check(request_action(201,'delete_status',['note_id'=>$note,'local_id'=>$id])['ok'],'actual audience delete '.$n);
        $raw=$db->query("SELECT json FROM transport_log WHERE kind='delivery' ORDER BY rowid DESC LIMIT 1")->fetchColumn();$envelope=json_decode($raw,true)['activity'];
        check($envelope['to']===$expectedTo&&$envelope['cc']===$expectedCc&&$envelope['object']['type']==='Tombstone','Delete exact stored/fallback audience '.$n);
    }
    $nativePath=$argv[1]??'';
    if($nativePath!=='') {
        $native=json_decode((string)file_get_contents($nativePath),true,512,JSON_THROW_ON_ERROR);
        $pipes=[];$proc=proc_open([__DIR__.'/php-policy-test',__DIR__.'/migration-card-reference-capture.php'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
        fclose($pipes[0]);$phpRaw=stream_get_contents($pipes[1]);$phpError=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($proc)!==0)throw new RuntimeException('canonical capture failed '.$phpError);
        $php=json_decode($phpRaw,true,512,JSON_THROW_ON_ERROR);
        foreach(['PHP'=>$php,'Rust'=>$native] as $language=>$capture) {
            foreach(['rss','plain'] as $cardCase) {
            $html=$capture['captures'][$cardCase]['html']??'';
            preg_match_all('/<form\b[^>]*>(.*?)<\/form>/s',$html,$forms);
            $seen=[];
            foreach($forms[1] as $form) {
                preg_match_all('/<input\b[^>]*name="([^"]+)"[^>]*value="([^"]*)"[^>]*>/s',$form,$inputs,PREG_SET_ORDER);
                $post=[];foreach($inputs as $input)$post[html_entity_decode($input[1],ENT_QUOTES|ENT_HTML5,'UTF-8')]=html_entity_decode($input[2],ENT_QUOTES|ENT_HTML5,'UTF-8');
                $action=$post['action']??'';
                if(!in_array($action,$cardCase==='rss'?['favourite_status','bookmark_status','reblog_status']:['favourite_status','bookmark_status'],true))continue;
                $seen[$action]=true;
                $response=request_action(201,$action,$post);
                check($response['ok']&&$response['active']===true,$language.' '.$cardCase.' generated '.$action.' reaches actual durable controller');
            }
            check(isset($seen['favourite_status'],$seen['bookmark_status'])&&($cardCase!=='rss'||isset($seen['reblog_status'])),$language.' '.$cardCase.' interaction form coverage');
            }
        }
    }
    $favBefore=count_rows('masto_favourites',201);$bmBefore=count_rows('masto_bookmarks',201);$signalBefore=count_rows('ap_user_signals',201);
    $db->exec('DROP TABLE ap_action_queue');
    foreach(['favourite_status','bookmark_status'] as $action) {
        check(!request_action(201,$action,['status_id'=>'999999','object_id'=>'https://remote.test/posts/failed'])['ok'],'failed enqueue '.$action.' reports failure');
        check(count_rows('masto_favourites',201)===$favBefore&&count_rows('masto_bookmarks',201)===$bmBefore&&count_rows('ap_user_signals',201)===$signalBefore,'failed enqueue has no optimistic durable effects '.$action);
    }
    echo 'library-controller-action: PASS ('.$checks." checks; actual PHP auth/actions/queues/polls/own effects and recipient envelopes; no federation)\n";
} finally { @unlink($path); }
