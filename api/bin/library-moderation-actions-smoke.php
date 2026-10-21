<?php
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';
require dirname(__DIR__).'/ap-admin-native.php'; // the controller's native branches; their switches are off here
if(!extension_loaded('mbstring')||!extension_loaded('pdo_sqlite'))throw new RuntimeException('Use php-policy-test');
const AP_AUTH_CSRF_KEY='_vaak_csrf';const LOCAL_ACTOR='https://mkultra.monster/users/alice';const LOCAL_KEY_ID=LOCAL_ACTOR.'#key';const LOCAL_PRIV='/tmp/unused-fixture-key';const LOCAL_FOLLOW_BACK_MAX_PER_HOUR=30;
function ap_auth_start_session(): void {}
function ap_db(): PDO {return $GLOBALS['db'];}
function ap_redis_json_get(string $key): mixed {return $GLOBALS['redis'][$key]??null;}
function ap_redis_json_set(string $key,mixed $value,int $ttl): void {$GLOBALS['redis'][$key]=$value;}
function ap_redis_delete(string ...$keys): void {foreach($keys as $key){$GLOBALS['deleted'][]=$key;unset($GLOBALS['redis'][$key]);}}
function ap_redis_relsets_enabled(): bool {return true;}
function admin_tl_cache_clear(): void {$GLOBALS['invalidated'][]=['timeline', (int)$GLOBALS['vaak_owner_id']];}
function ap_timeline_cache_invalidate_owner(int $owner): void {$GLOBALS['invalidated'][]=['ranked',$owner];}
function ap_notification_projection_invalidate_all(): void {$GLOBALS['invalidated'][]=['notifications-all',0];}
function ap_notification_projection_invalidate_owner(int $owner): void {$GLOBALS['invalidated'][]=['notifications',$owner];}
function ap_masto_notifications_unread_invalidate(int $owner): void {$GLOBALS['invalidated'][]=['unread',$owner];}
function ap_bsky_migrate(): void {}
function ap_bsky_graph_sync_migrate(): void {}
function ap_redis_queue_push(string $queue,int $id): void {$GLOBALS['transport'][]=['redis-queue',$queue,$id];}
function ap_fetch_actor_doc(string $actor): ?array {return ['id'=>$actor,'inbox'=>$actor.'/inbox','preferredUsername'=>'remote'];}
function ap_deliver_signed_json(string $inbox,array $activity,string $key,string $private,float $timeout=5): bool {$GLOBALS['transport'][]=['signed',$inbox,$activity];return true;}
function ap_log(string $line): void {$GLOBALS['transport'][]=['log',$line];}
function ap_metrics_record(mixed ...$args): void {$GLOBALS['transport'][]=['metrics',$args];}
function load_declarations(string $file): void {preg_match_all('/^function ([a-zA-Z0-9_]+)\(/m',(string)file_get_contents($file),$names);reference_load($file,$names[1]);}
$api=dirname(__DIR__);
load_declarations($api.'/ap-db.php');load_declarations($api.'/ap-inbox.php');load_declarations($api.'/ap-visibility.php');
reference_load($api.'/ap-bsky.php',['ap_bsky_session_row','ap_bsky_sync_moderation_from_vaak','ap_bsky_hide_did_set','ap_bsky_hide_did_add','ap_bsky_hide_did_remove','ap_bsky_hide_did_set_clear_cache']);
reference_load($api.'/ap-auth.php',['ap_auth_csrf_ok']);
reference_load($api.'/ap-admin.php',['admin_owner_user_id','admin_library_bind_rss_sources','admin_library_status_not_deleted','admin_library_quote_policy_key','admin_library_status_allowed','admin_library_bsky_item_allowed','vaak_actor_id','vaak_is_own_url','admin_is_local_admin_actor']);
$GLOBALS['redis']=[];$GLOBALS['deleted']=[];$GLOBALS['invalidated']=[];$GLOBALS['transport']=[];
$db=new PDO('sqlite::memory:',null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$GLOBALS['db']=$db;
$db->exec("CREATE TABLE ap_users(id INTEGER PRIMARY KEY,actor_id TEXT,actor_key TEXT,role TEXT,disabled_at TEXT,is_admin INTEGER);INSERT INTO ap_users VALUES(201,'https://mkultra.monster/users/alice','alice','user',NULL,0),(202,'https://mkultra.monster/users/bob','bob','user',NULL,0),(203,'https://mkultra.monster/users/operator','operator','admin',NULL,1);
CREATE TABLE ap_deprioritized_actors(owner_user_id INTEGER,actor_id TEXT,created_at TEXT);
CREATE TABLE ap_mutes(owner_user_id INTEGER,actor_id TEXT,created_at TEXT,notifications INTEGER,host TEXT,UNIQUE(owner_user_id,actor_id));
CREATE TABLE ap_user_blocks(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,scope TEXT,value TEXT,kind TEXT,reason TEXT,created_at TEXT,UNIQUE(owner_user_id,scope,value));
CREATE TABLE ap_blocks(id INTEGER PRIMARY KEY AUTOINCREMENT,scope TEXT,value TEXT,kind TEXT,reason TEXT,created_at TEXT);
CREATE TABLE ap_muted_words(id INTEGER PRIMARY KEY AUTOINCREMENT,owner_user_id INTEGER,phrase TEXT,created_at TEXT,note TEXT);
CREATE TABLE bsky_sessions(owner_user_id INTEGER,did TEXT);CREATE TABLE bsky_posts(author_handle TEXT,author_did TEXT);CREATE TABLE bsky_actor_profiles(did TEXT,actor_ref TEXT,profile_json TEXT);CREATE TABLE bsky_hide_dids(owner_user_id INTEGER,did TEXT,reason TEXT,list_uri TEXT);
CREATE TABLE following(owner_actor_id TEXT,actor_id TEXT,host TEXT,follow_id TEXT,created_at TEXT,followed_at TEXT,UNIQUE(owner_actor_id,actor_id));CREATE TABLE followers(owner_actor_id TEXT,actor_id TEXT,host TEXT,inbox TEXT,shared_inbox TEXT,username TEXT,follow_activity_id TEXT,created_at TEXT,followed_at TEXT,last_seen TEXT);
CREATE TABLE actor_profile(actor_key TEXT PRIMARY KEY,indexable INTEGER DEFAULT 1);
CREATE TABLE ap_follow_requests(id INTEGER PRIMARY KEY,owner_actor_id TEXT,actor_id TEXT,inbox TEXT,shared_inbox TEXT,follow_activity_id TEXT,username TEXT,status TEXT,decided_at TEXT,created_at TEXT,updated_at TEXT);
CREATE TABLE events(id INTEGER,object_id TEXT,type TEXT,action_taken TEXT,visibility TEXT,actor_id TEXT);CREATE TABLE outbox_notes(id TEXT,visibility TEXT,to_json TEXT,cc_json TEXT);CREATE TABLE mentions(id INTEGER,owner_user_id INTEGER,object_id TEXT,activity_type TEXT,deleted_at TEXT);");
$controller=(string)file_get_contents($api.'/ap-admin.php');$controller=str_replace("require_once __DIR__ . '/ap-inbox.php';",'/* genuine declarations already loaded */',$controller);
function span(string $start,string $end): string {$s=$GLOBALS['controller'];$a=strpos($s,$start);$b=$a===false?false:strpos($s,$end,$a+strlen($start));if($a===false||$b===false)throw new RuntimeException('Controller sentinel moved');return substr($s,$a,$b-$a);}
function dispatch(int $owner,string $action,array $post=[]): array {
    $_POST=array_replace(['action'=>$action,'csrf'=>'fixture-csrf','ajax'=>'0'],$post);$_SERVER['REQUEST_METHOD']='POST';$_SESSION[AP_AUTH_CSRF_KEY]='fixture-csrf';$_GET=[];
    $vaakOwnerId=$owner;$vaakActorId=$owner===201?'https://mkultra.monster/users/alice':'https://mkultra.monster/users/bob';$vaakUser=['id'=>$owner,'actor_id'=>$vaakActorId];
    $GLOBALS['vaak_owner_id']=$owner;$GLOBALS['vaak_actor_id']=$vaakActorId;$GLOBALS['vaak_actor_key']=$owner===201?'alice':'bob';$GLOBALS['vaak_user']=$vaakUser;$GLOBALS['vaak_guest_profile']=$owner<1;
    $error=null;$notice=null;$view='home';
    eval(span("if ((\$_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {",'    // Server-wide / operator-only mutations')."\n}");
    foreach([
        ["    } elseif (\$action === 'mute_remote' ||", "    } elseif (\$action === 'deprioritize_remote' ||"],
        ["    } elseif (\$action === 'muted_word_add') {", "    } elseif (\$action === 'user_block_add') {"],
        ["    } elseif (\$action === 'follow_remote') {", "    } elseif (\$action === 'follow_tag' ||"],
        ["    } elseif (\$action === 'user_block_add') {", "    } elseif (in_array(\$action, ['bsky_like', 'bsky_unlike'"],
    ] as [$a,$b])eval(preg_replace('/^    } elseif /','    if ',span($a,$b),1)."\n}");
    return ['ok'=>$error===null&&$notice!==null,'error'=>$error,'notice'=>$notice,'view'=>$view,'get'=>$_GET];
}
if(($argv[1]??'')==='--guest') {dispatch(0,'mute_remote',['actor_id'=>'https://remote.test/users/a','ajax'=>'1']);throw new RuntimeException('guest guard did not exit');}
$count=0;function verify(bool $v,string $name): void {if(!$v)throw new RuntimeException($name);$GLOBALS['count']++;}
function status(string $actor,string $text='Clean body'): array {return ['id'=>'123','uri'=>'https://remote.test/posts/123','visibility'=>'public','content'=>$text,'account'=>['uri'=>$actor]];}
function allowed(string $actor,int $owner=201,string $text='Clean body'): bool {return admin_library_status_allowed(status($actor,$text),$owner);}
$target='https://remote.test/users/alice';
ap_db()->exec("INSERT INTO events VALUES(1,'https://remote.test/posts/123','Create','log','public','https://remote.test/users/alice')");
verify(allowed($target)&&allowed($target,202),'warm both owner Library caches');
verify(count(ap_search_filter_statuses([status($target)],201,10))===1&&count(ap_search_filter_accounts([['uri'=>$target]],201,10))===1,'warm canonical Search status/account filters');
verify(dispatch(201,'mute_remote',['actor_id'=>$target])['ok'],'actual mute controller');
verify(!allowed($target)&&allowed($target,202),'warm Library mute scoped');
verify(ap_search_filter_statuses([status($target)],201,10)===[]&&count(ap_search_filter_statuses([status($target)],202,10))===1,'warm Search mute statuses current owner');
verify(ap_search_filter_accounts([['uri'=>$target]],201,10)===[],'warm Search muted account hidden');
verify(!ap_actor_is_content_blocked($target,'remote.test',201),'direct profile personal mute exception');
verify(dispatch(201,'mute_remote',['actor_id'=>$target])['ok'],'idempotent mute controller');
verify(dispatch(201,'unmute_remote',['actor_id'=>$target])['ok']&&allowed($target),'actual unmute restores warm Library');
verify(count(ap_search_filter_statuses([status($target)],201,10))===1,'warm Search unmute restores current state');
verify(!dispatch(201,'mute_remote',['actor_id'=>'https://mkultra.monster/users/alice'])['ok'],'self mute denied');
verify(!dispatch(201,'mute_remote',['actor_id'=>''])['ok'],'missing mute denied');
verify(!dispatch(201,'mute_remote',['actor_id'=>$target,'csrf'=>'forged'])['ok']&&allowed($target),'CSRF rejection no mute effect');
verify(dispatch(201,'muted_word_add',['phrase'=>' CAFÉ & tea '])['ok'],'actual Unicode word create');
$word=(int)$db->query('SELECT id FROM ap_muted_words')->fetchColumn();
verify(!allowed($target,201,'CAFÉ &amp; tea')&&allowed($target,202,'CAFÉ &amp; tea'),'word current warm cache and owner');
verify(!ap_actor_is_content_blocked($target,'remote.test',201),'wordmute does not block direct profile content gate');
verify(!dispatch(202,'muted_word_remove',['id'=>$word])['ok']&&!allowed($target,201,'CAFÉ &amp; tea'),'foreign word remove denied');
verify(dispatch(201,'muted_word_remove',['id'=>$word])['ok']&&allowed($target,201,'CAFÉ &amp; tea'),'word removal current warm reader');
verify(!dispatch(201,'muted_word_add',['phrase'=>'x'])['ok'],'short word denied');
verify(dispatch(201,'user_block_add',['target'=>$target,'kind'=>'suspend'])['ok'],'actual personal actor suspension');
$block=(int)$db->query('SELECT id FROM ap_user_blocks')->fetchColumn();
verify(!allowed($target)&&allowed($target,202),'block current Library owners');
verify(ap_actor_is_content_blocked($target,'remote.test',201),'direct profile blocks apply');
verify(ap_search_filter_statuses([status($target)],201,10)===[]&&ap_search_filter_accounts([['uri'=>$target]],201,10)===[],'warm Search personal block filters both');
verify(!dispatch(202,'user_block_remove',['id'=>$block])['ok']&&!allowed($target),'foreign block remove denied');
verify(dispatch(201,'user_block_remove',['id'=>$block])['ok']&&allowed($target),'unblock current warm Library');
verify(count(ap_search_filter_statuses([status($target)],201,10))===1,'warm Search unblock restores');
verify(!dispatch(201,'user_block_add',['target'=>'https://mkultra.monster/users/alice'])['ok'],'self block denied');
verify(!dispatch(201,'user_block_add',['target'=>'mkultra.monster'])['ok'],'local domain block denied');
verify(!dispatch(201,'user_block_add',['target'=>'https://mkultra.monster/users/operator'])['ok'],'actual local administrator personal block denied');
foreach(['mute_remote'=>['actor_id'=>$target],'muted_word_add'=>['phrase'=>'Secret phrase'],'user_block_add'=>['target'=>$target,'kind'=>'suspend']] as $action=>$input) {
    $before=$db->query('SELECT COUNT(*) FROM ap_mutes')->fetchColumn()+$db->query('SELECT COUNT(*) FROM ap_user_blocks')->fetchColumn()+$db->query('SELECT COUNT(*) FROM ap_muted_words')->fetchColumn();
    verify(!dispatch(201,$action,$input+['csrf'=>'invalid'])['ok'],'actual CSRF denial '.$action);
    $after=$db->query('SELECT COUNT(*) FROM ap_mutes')->fetchColumn()+$db->query('SELECT COUNT(*) FROM ap_user_blocks')->fetchColumn()+$db->query('SELECT COUNT(*) FROM ap_muted_words')->fetchColumn();
    verify($before===$after,'CSRF no durable moderation write '.$action);
}
foreach([['https://edge.test/users/alice%2F','https://edge.test/users/alice'],['https://edge.test/users/1','https://edge.test/@1'],['https://edge.test/users/alice','https://edge.test/users/Alice']] as [$stored,$distinct]) {
    verify(allowed($stored)&&allowed($distinct),'warm distinct URI pair');
    verify(dispatch(201,'mute_remote',['actor_id'=>$stored])['ok'],'actual mute distinct URI');
    verify(!allowed($stored)&&allowed($distinct),'reserved/numeric/case distinctions after warm mute');
    verify(dispatch(201,'unmute_remote',['actor_id'=>$stored])['ok']&&allowed($stored),'distinct URI unmute');
}
verify(dispatch(201,'user_block_add',['target'=>'blocked.test','kind'=>'suspend'])['ok'],'actual domain suspension');
$domain=(int)$db->query("SELECT id FROM ap_user_blocks WHERE scope='domain'")->fetchColumn();
verify(!allowed('https://sub.blocked.test/users/a')&&allowed('https://unblocked.test/users/a'),'domain suffix current policy');
verify(dispatch(201,'user_block_remove',['id'=>$domain])['ok']&&allowed('https://sub.blocked.test/users/a'),'domain removal current warm policy');
$did='did:plc:remote';$profile='https://bsky.app/profile/'.$did;
verify(dispatch(201,'mute_remote',['actor_id'=>$profile])['ok'],'actual native profile alias mute');
verify(ap_visibility_actor_hidden($did,201)&&!ap_visibility_actor_hidden($did,202),'native DID owner alias hidden');
verify(ap_search_filter_statuses([array_replace(status($profile),['author_did'=>$did])],201,10)===[],'native DID Search owner alias hidden');
verify(dispatch(201,'unmute_remote',['actor_id'=>$profile])['ok']&&!ap_visibility_actor_hidden($did,201),'native DID unmute current cache');
$db->prepare("INSERT INTO ap_blocks(scope,value,kind,created_at) VALUES('actor',?,'mute','now')")->execute([$target]);ap_block_cache_clear();
verify(!allowed($target)&&!allowed($target,202),'server mute applies both Library owners');
verify(ap_search_filter_statuses([status($target)],201,10)===[]&&ap_search_filter_statuses([status($target)],202,10)===[],'server mute current Search owners');
verify(!ap_actor_is_content_blocked($target,'remote.test',201),'canonical direct profile allows server mute');
$db->exec("UPDATE ap_blocks SET kind='block'");ap_block_cache_clear();verify(ap_actor_is_content_blocked($target,'remote.test',201),'direct profile blocks server block');
$db->exec('DELETE FROM ap_blocks');ap_block_cache_clear();verify(allowed($target)&&allowed($target,202),'server unmute clears warm owner policies');
verify(in_array('vaak:moderation:mutes:v4:201',$GLOBALS['deleted'],true),'actual mute current namespace invalidation');
verify(in_array('vaak:relset:v3:blocks-actors:201',$GLOBALS['deleted'],true),'actual block current namespace invalidation');
verify(in_array('vaak:moderation:muted-words:201',$GLOBALS['deleted'],true),'actual words namespace invalidation');
$owner='https://mkultra.monster/users/alice';$other='https://mkultra.monster/users/bob';
$db->prepare('INSERT INTO ap_follow_requests(id,owner_actor_id,actor_id,inbox,shared_inbox,follow_activity_id,username,status) VALUES(1,?,?,?,?,?,?,?)')->execute([$owner,$target,$target.'/inbox','',$target.'/follow/1','remote','pending']);
verify(!dispatch(202,'follow_request_decide',['id'=>1,'decision'=>'approve'])['ok'],'foreign follow request denied');
verify(!dispatch(201,'follow_request_decide',['id'=>1,'decision'=>'approve','csrf'=>'forged'])['ok'],'request CSRF denied');
verify(dispatch(201,'follow_request_decide',['id'=>1,'decision'=>'approve'])['ok'],'actual request approval');
verify($db->query('SELECT status FROM ap_follow_requests WHERE id=1')->fetchColumn()==='accepted','accepted request durable status');
verify((int)$db->query('SELECT COUNT(*) FROM followers')->fetchColumn()===1,'actual approval creates follower');
verify(!dispatch(201,'follow_request_decide',['id'=>1,'decision'=>'approve'])['ok'],'replayed approval denied');
verify(dispatch(201,'follow_remote',['actor_id'=>$other])['ok'],'actual same-instance follow');
verify((int)$db->query("SELECT COUNT(*) FROM following WHERE owner_actor_id='https://mkultra.monster/users/alice' AND actor_id='https://mkultra.monster/users/bob'")->fetchColumn()===1,'actual follow durable owner relation');
$private=status($other);$private['uri']=$other.'/notes/private';$private['visibility']='private';
$db->prepare("INSERT INTO events VALUES(1,?,'Create','log','private',?)")->execute([$private['uri'],$other]);
$db->prepare('INSERT INTO outbox_notes VALUES(?,?,?,?)')->execute([$private['uri'],'private',json_encode([$other.'/followers']),'[]']);
verify(admin_library_status_allowed($private,201),'current follow admits saved private source');
verify(dispatch(202,'unfollow_remote',['actor_id'=>$other])['ok'],'other owner unfollow does not substitute actor');
verify(admin_library_status_allowed($private,201),'other owner unfollow preserves first owner private access');
verify(dispatch(201,'unfollow_remote',['actor_id'=>$other])['ok'],'actual same-instance unfollow');
verify(!admin_library_status_allowed($private,201),'actual follow revocation denies saved private source');
$db->prepare('INSERT INTO ap_follow_requests(id,owner_actor_id,actor_id,inbox,shared_inbox,follow_activity_id,username,status) VALUES(2,?,?,?,?,?,?,?)')->execute([$owner,'https://reject.test/users/a','https://reject.test/inbox','','https://reject.test/follow/1','reject','pending']);
verify(dispatch(201,'follow_request_decide',['id'=>2,'decision'=>'reject'])['ok'],'actual request reject');
verify($db->query('SELECT status FROM ap_follow_requests WHERE id=2')->fetchColumn()==='rejected','request rejection durable status');
verify((int)$db->query("SELECT COUNT(*) FROM followers WHERE actor_id='https://reject.test/users/a'")->fetchColumn()===0,'rejected request does not grant access');
$pipe=[];$proc=proc_open([__DIR__.'/php-policy-test',__FILE__,'--guest'],[['pipe','r'],['pipe','w'],['pipe','w']],$pipe);fclose($pipe[0]);$json=stream_get_contents($pipe[1]);$err=stream_get_contents($pipe[2]);fclose($pipe[1]);fclose($pipe[2]);verify(proc_close($proc)===0,'guest child exits through actual guard');$guest=json_decode($json,true);verify(($guest['error']??null)==='login_required'&&($guest['ok']??true)===false,'actual guest requires login');
verify(!dispatch(201,'follow_remote',['actor_id'=>$owner])['ok'],'self follow denied');
verify(!dispatch(201,'follow_remote',['actor_id'=>''])['ok'],'missing follow denied');
verify(dispatch(201,'follow_remote',['actor_id'=>$target])['ok'],'actual remote follow transport');
verify(isset(ap_following_id_set($owner,201)[$target]),'remote follow actual owner relationship');
verify(!dispatch(201,'unfollow_remote',['actor_id'=>$target,'csrf'=>'forged'])['ok']&&isset(ap_following_id_set($owner,201)[$target]),'unfollow CSRF leaves durable relationship');
verify(dispatch(201,'unfollow_remote',['actor_id'=>$target])['ok']&&!isset(ap_following_id_set($owner,201)[$target]),'actual remote unfollow removes relationship');
verify(dispatch(201,'user_block_add',['target'=>$target])['ok'],'actual block controller with signed transport');
$actualBlock=(int)$db->query("SELECT id FROM ap_user_blocks WHERE scope='actor' AND value='https://remote.test/users/alice'")->fetchColumn();
verify(!allowed($target),'actual block Library policy');
verify(dispatch(201,'user_block_remove',['id'=>$actualBlock])['ok']&&allowed($target),'actual unblock with signed transport');
verify(count(array_filter($GLOBALS['transport'],fn($x)=>$x[0]==='signed'))>=4,'real follow/unfollow/block/unblock envelope transports');
echo "library-moderation-actions: PASS ($count actual controller/writer/warm policy checks; partial gate)\n";
