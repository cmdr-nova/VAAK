<?php
declare(strict_types=1);
/** Same disposable DB oracle: actual PHP Search admission and final painter. */
$schema=$argv[1]??'';$owner=(int)($argv[2]??0);
if(!preg_match('/^search_context_review_(integer|bigint)$/',$schema)||!in_array($owner,[1,2],true))throw new RuntimeException('Disposable Search schema only');
$input=json_decode(stream_get_contents(STDIN),true,512,JSON_THROW_ON_ERROR);
$reviewView=($input['_review_return_view']??'search')==='bookmarks'?'bookmarks':'search';
$argv[1]='--setup-only';
// Adapt only the inherited capture's hard-coded authenticated actor transport.
// Actual production helper declarations and painters are otherwise unchanged.
function review_copy_php_transport(string $path,string $source):string{
 $code='';foreach(token_get_all($source) as $token)$code.=is_array($token)&&$token[0]===T_DIR?var_export(dirname($path),true):(is_array($token)?$token[1]:$token);
 $tmp=tempnam('/tmp','vaak-search-php-oracle-');file_put_contents($tmp,$code);return $tmp;
}
$capturePath=__DIR__.'/migration-card-reference-capture.php';
$captureSource=str_replace("return 'https://mkultra.monster/users/fixture';","return (string)(\$GLOBALS['vaak_actor_id']??'https://mkultra.monster/users/fixture');",file_get_contents($capturePath));
$captureSource=str_replace("fixture-csrf-token","canonical-current-session",$captureSource);
// Provision the policy columns before the inherited corpus warms static admin
// lookups, avoiding an artificial failed lookup from its minimal SQLite table.
$captureSource=str_replace("'ap_users'=>'id INTEGER,actor_id TEXT'","'ap_users'=>'id INTEGER,actor_id TEXT,actor_key TEXT,is_admin INTEGER DEFAULT 0'",$captureSource);
$captureSource=str_replace("INSERT INTO ap_users VALUES(","INSERT INTO ap_users(id,actor_id) VALUES(",$captureSource);
$captureTmp=review_copy_php_transport($capturePath,$captureSource);
$setupPath=__DIR__.'/search-cache-privacy-independent.php';
$setupSource=str_replace("require __DIR__.'/migration-card-reference-capture.php'",'require '.var_export($captureTmp,true),file_get_contents($setupPath));
$setupSource=str_replace('ALTER TABLE ap_users ADD COLUMN actor_key TEXT;','',$setupSource);
$setupTmp=review_copy_php_transport($setupPath,$setupSource);
try{require $setupTmp;}finally{unlink($setupTmp);unlink($captureTmp);}
reference_load(dirname(__DIR__).'/ap-db.php',['ap_local_user_by_actor_id','ap_local_avatar_url','ap_remote_media_get','ap_local_note_engagement_counts','ap_masto_status_is_pinned']);
reference_load(dirname(__DIR__).'/ap-masto-entities.php',['ap_masto_snowflake_id']);
reference_load(dirname(__DIR__).'/ap-asks.php',['ap_ask_actor_identity']);
$socket=getenv('LIBRARY_FIXTURE_SOCKET')?:'/tmp/vaak-library-facts-pg-socket';
if(!str_starts_with($socket,'/tmp/'))throw new RuntimeException('Isolated socket only');
$GLOBALS['fixture_db']=new PDO('pgsql:host='.$socket.';dbname=vaak_search_context_fixture','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
ap_db()->exec('SET search_path='.$schema);
// Real quote lookup IO transport: a cache miss is null, not a missing-table error.
ap_db()->exec('CREATE TABLE IF NOT EXISTS fixture_status_cache(object_id TEXT PRIMARY KEY,payload TEXT)');
// The inherited real PHP moderation helpers read isolated fixture transports.
// Views expose the native fixture's current durable rules to those transports.
ap_db()->exec("CREATE TEMP VIEW fixture_blocks AS SELECT owner_user_id,scope,value FROM ap_user_blocks;
CREATE TEMP VIEW fixture_mutes AS SELECT owner_user_id,actor_id FROM ap_mutes;
CREATE TEMP VIEW fixture_server_blocks AS SELECT scope,value,kind FROM ap_blocks;
CREATE TEMP VIEW fixture_words AS SELECT owner_user_id,phrase FROM ap_muted_words");
$GLOBALS['vaak_owner_id']=$owner;$GLOBALS['vaak_actor_key']=$owner===1?'viewer':'other';$GLOBALS['vaak_actor_id']='https://mkultra.monster/users/'.$GLOBALS['vaak_actor_key'];$GLOBALS['vaak_user']=['id'=>$owner,'username'=>$GLOBALS['vaak_actor_key']];
// Match durable saved rows to the public IDs that the normal producer/action
// writer uses. Legacy opaque-ID controls retain their separate historical SID.
$reviewSid=isset($input['_review_canonical_own_id'])?ap_masto_snowflake_id('2026-10-10T12:00:00Z',9,0):'9';
foreach(['masto_favourites','masto_reblogs','masto_bookmarks'] as $table){$q=ap_db()->prepare('UPDATE '.$table.' SET status_id=? WHERE object_id=?');$q->execute([$reviewSid,'https://mkultra.monster/users/viewer/notes/own']);}
if(isset($input['_review_current_own_source'])){
 $q=ap_db()->query("SELECT content_text,spoiler_text,sensitive,visibility FROM masto_statuses WHERE local_id=9");$fresh=$q->fetch();foreach(['spoiler_text','sensitive','visibility'] as $field)$input['status'][$field]=$fresh[$field];$input['status']['content']=htmlspecialchars((string)$fresh['content_text'],ENT_QUOTES|ENT_SUBSTITUTE,'UTF-8');
}
if(isset($input['_review_canonical_own_id'])){
 $status=$input['status'];$publicId=ap_masto_snowflake_id('2026-10-10T12:00:00Z',9,0);if(empty($status['content'])&&is_array($status['reblog']??null))$status['reblog']['id']=$publicId;else $status['id']=$publicId;$input=$status;
}
if(isset($input['_review_expected_visibility_fallback'])||isset($input['_review_expected_slash_fallback']))$input=$input['status'];
if(isset($input['_review_remote_ask_writer'])){
 reference_load(dirname(__DIR__).'/ap-db.php',['ap_mention_store']);
 $status=$input['status'];$writes=$input['_review_remote_ask_writer'];
 ap_mention_store($writes[0]);
 // This is the actual structured Ask branch used by the mention serializer:
 // ap_masto_mention_status builds content with this unchanged helper.
 $status['content']=ap_ask_card_html(['question'=>$writes[0]['ask_question'],'asker_actor'=>$writes[0]['ask_actor'],'answer'=>$writes[0]['ask_answer']]);
 unset($status['vaak_ask']);ap_mention_store($writes[1]);$input=$status;
}
$reviewDeleteAffected=null;
if(isset($input['_review_delete_object'])){
 reference_load(dirname(__DIR__).'/ap-db.php',['ap_events_mark_object_deleted']);
 $reviewDeleteAffected=ap_events_mark_object_deleted((string)$input['_review_delete_object']);$input=$input['status'];
}
if(isset($input['_review_return_view'])&&is_array($input['status']??null))$input=$input['status'];
$rows=$reviewView==='bookmarks'?[$input]:ap_search_filter_statuses(ap_masto_search_sanitize_statuses([$input]),$owner,40);
ob_start();if($reviewView==='bookmarks'){require_once dirname(__DIR__).'/ap-library-native.php';putenv('VAAK_LIBRARY_NATIVE_RENDER=0');admin_render_library_entries(array_map(static fn($status)=>['kind'=>'fedi','item'=>$status],$rows),'bookmarks');}else admin_search_render_sections(['statuses'=>$rows],'independent','statuses',false,40,[]);$html=ob_get_clean();
echo json_encode(['allowed'=>$reviewView==='bookmarks'?str_contains($html,'<article'):count($rows)===1,'html'=>$html,'status'=>$rows[0]??$input,'delete_writer_affected'=>$reviewDeleteAffected],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
