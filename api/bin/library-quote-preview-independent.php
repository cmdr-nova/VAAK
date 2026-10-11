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

require_once dirname(__DIR__).'/ap-library-native.php';
function ap_auth_current_user(): ?array{return $GLOBALS['vaak_user']??null;}
ap_db()->exec('CREATE TABLE IF NOT EXISTS link_preview_cards(url TEXT PRIMARY KEY,title TEXT,description TEXT,image TEXT,provider_name TEXT,provider_url TEXT,type TEXT,status TEXT,fetched_at TEXT,expires_at TEXT)');
function quote_review_capture(array $status,bool $enabled,string $view):array {
 putenv('VAAK_LIBRARY_NATIVE_RENDER='.($enabled?'1':'0'));
 $GLOBALS['admin_quote_link_preview_budget']=$GLOBALS['review_quote_budget']??3;$GLOBALS['admin_library_fetch_budget']=3;
 $GLOBALS['preview_http_requests']=[];$GLOBALS['preview_warm_requests']=[];$GLOBALS['fixture_render_clock']=time();
 unset($GLOBALS['vaak_quote_policy_statuses'],$GLOBALS['vaak_bsky_quote_policy_posts'],$GLOBALS['vaak_rss_quote_policy_rows']);
 ob_start();admin_render_library_entries([['kind'=>'fedi','item'=>$status]],$view);$html=ob_get_clean();
 return ['html'=>$html,'effects'=>['quote_budget'=>$GLOBALS['admin_quote_link_preview_budget'],'fetch_budget'=>$GLOBALS['admin_library_fetch_budget'],'preview_http'=>$GLOBALS['preview_http_requests'],'preview_warm'=>$GLOBALS['preview_warm_requests']]];
}
$cases=[];$child=['id'=>'child','uri'=>'https://quote.test/child','url'=>'https://quote.test/child','content'=>'Plain complete quoted marker','visibility'=>'public','account'=>['uri'=>'https://quote.test/users/q','acct'=>'q@quote.test']];
$parent=['id'=>'parent','uri'=>'https://quote.test/parent','url'=>'https://quote.test/parent','content'=>'Outer complete marker','visibility'=>'public','account'=>['uri'=>'https://quote.test/users/p','acct'=>'p@quote.test'],'created_at'=>'2026-10-01T00:00:00Z','quote'=>['state'=>'accepted','quoted_status'=>$child]];
$cases['plain_no_url']=$parent;$cases['plain_budget0']=$parent;$cases['plain_budget1']=$parent;
$withurl=$parent;$withurl['quote']['quoted_status']['content']='Cached preview marker https://article.test/story';$cases['cached_preview']=$withurl;$cases['cached_budget0']=$withurl;$cases['cached_budget1']=$withurl;
$withmedia=$parent;$withmedia['quote']['quoted_status']['media_attachments']=[['type'=>'image','url'=>'https://media.test/image.jpg','preview_url'=>'https://media.test/image.jpg','description'=>'Quoted image']];$cases['supplied_media']=$withmedia;foreach(['follower','recipient','revoked','deleted'] as $grant)$cases['private_media_'.$grant]=$withmedia;
$withcard=$parent;$withcard['quote']['quoted_status']['card']=['url'=>'https://article.test/story','title'=>'Supplied card','description'=>'Supplied description','type'=>'link'];$cases['supplied_card']=$withcard;
$out=[];foreach([false,true] as $lazy)foreach(['bookmarks','favourites'] as $view)foreach($cases as $name=>$status){
 $GLOBALS['admin_bookmark_lazy_media']=$lazy;$GLOBALS['review_quote_budget']=str_ends_with($name,'budget0')?0:(str_ends_with($name,'budget1')?1:3);
 ap_db()->exec("DELETE FROM events WHERE object_id IN ('https://quote.test/child','https://quote.test/child/');DELETE FROM following WHERE actor_id='https://quote.test/users/q';DELETE FROM mentions WHERE object_id='https://quote.test/child'");
 if(str_starts_with($name,'private_media_')){
  ap_db()->exec("INSERT INTO events(id,type,actor_id,object_id,action_taken,visibility) VALUES(2001,'Create','https://quote.test/users/q','https://quote.test/child','log','".(str_ends_with($name,'recipient')?'direct':'private')."')");
  if(str_ends_with($name,'follower'))ap_db()->exec("INSERT INTO following(owner_actor_id,actor_id) VALUES('https://mkultra.monster/users/viewer','https://quote.test/users/q')");
  if(str_ends_with($name,'recipient'))ap_db()->exec("INSERT INTO mentions(owner_user_id,activity_type,object_id,id) VALUES(1,'Create','https://quote.test/child',2001)");
  if(str_ends_with($name,'deleted'))ap_db()->exec("INSERT INTO events(id,type,actor_id,object_id,action_taken,visibility) VALUES(2002,'Delete','https://quote.test/users/q','https://quote.test/child/','log','public')");
 }
 ap_db()->exec('DELETE FROM link_preview_cards');if(str_starts_with($name,'cached_'))ap_db()->exec("INSERT INTO link_preview_cards(url,title,description,type,status) VALUES('https://article.test/story','Actual cached card','Cached card description','link','ok')");
 $php=quote_review_capture($status,false,$view);$native=quote_review_capture($status,true,$view);
 $out[]=['schema'=>$schema,'owner'=>$owner,'case'=>$name,'view'=>$view,'lazy_media'=>$lazy,'source'=>$status,'php'=>$php,'native'=>$native];
}
echo json_encode($out,JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES)."\n";
