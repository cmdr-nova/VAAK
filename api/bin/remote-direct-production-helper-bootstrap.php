<?php
declare(strict_types=1);
/** Declaration-only production helper closure for independent offline captures.
 * Called before the first actual painter/cache warm. No application bootstrap,
 * migration, session, HTTP, Redis socket, or background process is executed.
 */
function ap_remote_actor_warm_async(string $actorId): void { $GLOBALS['fixture_actor_warm_requests'][]=$actorId; }
function ap_remote_media_warm_async(string $actorId): void { $GLOBALS['fixture_media_warm_requests'][]=$actorId; }
function ap_quote_target_warm_async(string $objectUrl): void { $GLOBALS['fixture_quote_warm_requests'][]=$objectUrl; }
function ap_redis_client(string $purpose='cache'): ?Redis { $GLOBALS['fixture_redis_connections'][]=$purpose; return null; }
$api=dirname(__DIR__);
// Exact public presentation constants from the production source, without
// reading or evaluating configuration/secrets or database bootstrap code.
preg_match_all('/^const (AP_(?:REMOTE|LOCAL)_AVATAR_FALLBACK|AP_WAFRN_APPROVALS_AVATAR) = (\x27[^\x27]*\x27);/m',(string)file_get_contents($api.'/ap-db.php'),$publicConstants,PREG_SET_ORDER);
foreach($publicConstants as $constant)if(!defined($constant[1]))define($constant[1],trim($constant[2],"'"));
if(!defined('CMDR_NOVA_ACTOR')){
 if(!preg_match('/^const CMDR_NOVA_ACTOR = \x27([^\x27]+)\x27;/m',(string)file_get_contents($api.'/ap-inbox.php'),$localActor))throw new RuntimeException('public local actor constant');
 define('CMDR_NOVA_ACTOR',$localActor[1]);
}
if(!defined('LOCAL_ACTOR'))define('LOCAL_ACTOR',CMDR_NOVA_ACTOR);
// Global declarations only: regex excludes indented class methods. The actual
// source extractor preserves each function body and __DIR__/__FILE__ values.
$loaded=[];$files=[];$pending=[$api.'/ap-admin.php'];
while($pending){$file=array_shift($pending);if(isset($files[$file])||!is_file($file))continue;$files[$file]=true;$source=(string)file_get_contents($file);
 // Only dependencies in the file bootstrap, never lazy require statements
 // inside helper bodies. Their cold paths remain separate explicit boundaries.
 preg_match('/^function\s+/m',$source,$first,PREG_OFFSET_CAPTURE);$bootstrap=isset($first[0])?substr($source,0,$first[0][1]):$source;
 preg_match_all('/require(?:_once)?\s+__DIR__\s*\.\s*[\x27\x22]([^\x27\x22]+\.php)[\x27\x22]/',$bootstrap,$required);
 foreach($required[1] as $relative)$pending[]=dirname($file).$relative;
}
foreach(array_keys($files) as $file){
 preg_match_all('/^function\s+((?:ap_|admin_|vaak_)[A-Za-z_0-9]+)\s*\(/m',(string)file_get_contents($file),$names);
 if($names[1])reference_load($file,array_values(array_unique($names[1])));
 foreach($names[1] as $name)$loaded[$name]=basename($file);
}
$db=ap_db();
// Production following rows have nullable host (ap-db.php CREATE/migration).
// Existing captured rows are preserved exactly; this missing nullable field
// must not turn the real Search component query into a fixture SQL error.
$followingColumns=$db->query('PRAGMA table_info(following)')->fetchAll();
if(!in_array('host',array_column($followingColumns,'name'),true))$db->exec('ALTER TABLE following ADD COLUMN host TEXT');
// Genuine production relationship tables also read by the native Search
// component resolver, including an empty account section. Only captured empty
// initial state is provisioned; no follow/identity permission is fabricated.
foreach(['bsky_graph_sync'=>'ap-bsky.php','bsky_actor_profiles'=>'ap-bsky.php','masto_followed_tags'=>'ap-db.php'] as $table=>$file){
 if(!preg_match('/^CREATE TABLE IF NOT EXISTS '.preg_quote($table,'/').' \(\n.*?^\)/ms',(string)file_get_contents($api.'/'.$file),$declaration))throw new RuntimeException('production relation declaration '.$table);
 $db->exec($declaration[0]);$GLOBALS['fixture_production_relationship_table_declarations'][$table]=['source'=>$file,'sha256'=>hash('sha256',$declaration[0])];
}
foreach([
 'remote_actors'=>'actor_id TEXT PRIMARY KEY,username TEXT,display_name TEXT,host TEXT,icon_source_url TEXT,image_source_url TEXT,updated_at TEXT,summary TEXT,profile_json TEXT',
 'remote_media_cache'=>'id INTEGER,actor_id TEXT,kind TEXT,source_url TEXT,s3_key TEXT,public_url TEXT,content_type TEXT,byte_size INTEGER,fetched_at TEXT,last_used_at TEXT',
 'remote_custom_emojis'=>'host TEXT,shortcode TEXT,url TEXT,static_url TEXT,updated_at TEXT',
 'masto_account_actors'=>'account_id TEXT,actor_id TEXT,updated_at TEXT',
 'quote_authorizations'=>'id TEXT,created_at TEXT,quoted_note_id TEXT,quoting_object_id TEXT,requester_actor TEXT,deleted_at TEXT',
 'ap_asks'=>'id INTEGER,ask_id TEXT,question TEXT,asker_actor TEXT,asked_actor TEXT,owner_user_id INTEGER,answered INTEGER,answer_note_id TEXT,ap_object TEXT,created_at TEXT,updated_at TEXT',
 'ap_wafrn_friend_servers'=>'host TEXT,enabled INTEGER,note TEXT,created_at TEXT,updated_at TEXT'
 ,'masto_pins'=>'status_local_id INTEGER,owner_user_id INTEGER'
 ,'ap_deprioritized_actors'=>'owner_user_id INTEGER,actor_id TEXT,created_at TEXT'
 ,'remote_emoji_host_meta'=>'host TEXT,fetched_at TEXT,source TEXT'
 ,'bsky_posts'=>'bsky_uri TEXT,bsky_cid TEXT,author_did TEXT,author_handle TEXT,author_display TEXT,author_avatar TEXT,indexed_at TEXT,published_at TEXT,text TEXT,embed_json TEXT,reply_parent TEXT,reply_root TEXT,like_count INTEGER,repost_count INTEGER,reply_count INTEGER,quote_count INTEGER,reason_json TEXT,raw_json TEXT,owner_user_id INTEGER,seen_at TEXT,updated_at TEXT'
 ,'ap_blocks'=>'id INTEGER,scope TEXT,value TEXT,kind TEXT,created_at TEXT'
 ,'ap_user_blocks'=>'id INTEGER,owner_user_id INTEGER,scope TEXT,value TEXT,created_at TEXT'
 ,'ap_mutes'=>'owner_user_id INTEGER,actor_id TEXT,created_at TEXT'
 ,'ap_muted_words'=>'id INTEGER,owner_user_id INTEGER,phrase TEXT,created_at TEXT,note TEXT'
 ,'ap_notification_projection'=>'status_id TEXT'
] as $table=>$columns)$db->exec('CREATE TABLE IF NOT EXISTS '.$table.'('.$columns.')');
$substitutions=['ap_db','ap_auth_csrf_token','ap_db_now','ap_bsky_graph_sync_migrate','ap_masto_lookup_status_by_object_url','ap_link_preview_http_get','ap_link_preview_warm_async','ap_remote_actor_warm_async','ap_remote_media_warm_async','ap_quote_target_warm_async','ap_redis_client'];
$GLOBALS['fixture_production_source_declaration_catalog']=$loaded;
$GLOBALS['fixture_production_helper_declarations']=array_diff_key($loaded,array_fill_keys($substitutions,true));
$GLOBALS['fixture_production_semantic_substitutions']=$substitutions;
$GLOBALS['fixture_production_helper_files']=array_map('basename',array_keys($files));
$GLOBALS['fixture_production_external_transports']=['ap_remote_actor_warm_async','ap_remote_media_warm_async','ap_quote_target_warm_async','ap_redis_client'];
require_once __DIR__.'/remote-direct-core-sql-schema.php';
direct_core_sql_schema($db);
$warmSources=getenv('DIRECT_PRODUCTION_WARM_SOURCES');
if(is_string($warmSources)&&$warmSources!==''){
 $sourceRows=json_decode((string)file_get_contents($warmSources),true,512,JSON_THROW_ON_ERROR)['captures'];$actors=[];
 $collect=static function(array $source)use(&$collect,&$actors):void{
  $account=$source['account']??null;$actor=is_array($account)?rtrim((string)($account['uri']??''),'/'):'';
  if(str_starts_with($actor,'https://')&&!str_starts_with($actor,'https://mkultra.monster/users/'))$actors[$actor]=$account;
  foreach(['reblog','quoted_status'] as $key)if(is_array($source[$key]??null))$collect($source[$key]);
  foreach(['quote','vaak_quote_preview'] as $key)if(is_array($source[$key]['quoted_status']??null))$collect($source[$key]['quoted_status']);
 };
 foreach($sourceRows as $row)if(is_array($row['source']??null))$collect($row['source']);
 foreach($actors as $actor=>$account){
  $url='https://cache.test/actual-avatar-'.substr(hash('sha256',$actor),0,20).'.webp';$clock='2026-10-10T15:00:00Z';
  $db->prepare('INSERT INTO remote_media_cache(actor_id,kind,source_url,s3_key,public_url,content_type,byte_size,fetched_at,last_used_at) VALUES(?,?,?,?,?,?,?,?,?)')->execute([$actor,'avatar',$account['avatar']??$url,'declared-fixture-avatar',$url,'image/webp',12,$clock,$clock]);
  $db->prepare('INSERT INTO remote_actors(actor_id,username,display_name,host,icon_source_url,updated_at,summary,profile_json) VALUES(?,?,?,?,?,?,?,?)')->execute([$actor,explode('@',(string)($account['acct']??'user'))[0],$account['display_name']??'Current cached actor',parse_url($actor,PHP_URL_HOST),$account['avatar']??$url,$clock,'','{}']);
 }
 $GLOBALS['fixture_declared_warm_actor_ids']=array_keys($actors);
}
/** Declare canonical persisted moderation rows from fixture input state.
 * The real cache/set/alias/word helpers then query these actual tables.
 * Static administrator/global menu input remains immutable in primary corpus;
 * changing menu state requires a separate process, not this reset helper.
 */
function direct_production_prepare_moderation(): void {
 $db=ap_db();foreach(['ap_blocks','ap_user_blocks','ap_mutes','ap_muted_words'] as $table)$db->exec('DELETE FROM '.$table);
 $db->exec("INSERT INTO ap_blocks(id,scope,value,kind,created_at) SELECT 1,scope,value,kind,'2026-10-10T15:00:00Z' FROM fixture_server_blocks");
 $db->exec("INSERT INTO ap_user_blocks(id,owner_user_id,scope,value,created_at) SELECT id,owner_user_id,scope,value,'2026-10-10T15:00:00Z' FROM fixture_blocks");
 $db->exec("INSERT INTO ap_mutes(owner_user_id,actor_id,created_at) SELECT owner_user_id,actor_id,'2026-10-10T15:00:00Z' FROM fixture_mutes");
 $db->exec("INSERT INTO ap_muted_words(id,owner_user_id,phrase,created_at,note) SELECT 1,owner_user_id,phrase,'2026-10-10T15:00:00Z','declared fixture input' FROM fixture_words");
 unset($GLOBALS['ap_relset_memo']);ap_block_list_cached(true);ap_server_blocked_actors_set(true);
 foreach([1,2] as $owner){ap_user_blocks_list_cached($owner,true);ap_mutes_set_cached($owner,true);ap_muted_words_phrases_cached($owner,true);}
}
