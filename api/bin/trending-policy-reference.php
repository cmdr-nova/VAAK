<?php
/** Actual sidebar/SQL moderation helpers; global-cache and Redis transports recorded. */
declare(strict_types=1);
require __DIR__.'/migration-reference-functions.php';
function ap_db(): PDO {return $GLOBALS['db'];}
function ap_auth_current_user(): ?array {return ['id'=>$GLOBALS['viewer']];}
function h(string $v):string{return htmlspecialchars($v,ENT_QUOTES,'UTF-8');}
function admin_status_href(string $v,string $from):string{return '?view=post&uri='.rawurlencode($v).'&from='.$from;}
function ap_redis_json_get(string $key):mixed{$GLOBALS['redis_reads'][]=$key;return $GLOBALS['redis'][$key]??null;}
function ap_redis_json_set(string $key,mixed $value,int $ttl):void{$GLOBALS['redis'][$key]=$value;}
function ap_redis_delete(string ...$keys):void{foreach($keys as $key)unset($GLOBALS['redis'][$key]);}
function ap_redis_relsets_enabled():bool{return true;}
function ap_timeline_cache_invalidate_owner(int $id):void{}
function ap_notification_projection_invalidate_owner(int $id):void{}
function ap_notification_projection_invalidate_all():void{}
function ap_masto_trends_cache_read(string $kind,int $owner,bool $allow):?array{$GLOBALS['global_reads']++;if(isset($GLOBALS['cache_hook']))($GLOBALS['cache_hook'])($kind);return ['age'=>5,'stale'=>false,'items'=>$GLOBALS['candidates'][$kind]];}
function ap_bsky_graph_sync_migrate():void{}
reference_load(dirname(__DIR__).'/ap-bsky.php',['ap_bsky_hide_did_set','ap_bsky_hide_did_set_clear_cache']);
reference_load(dirname(__DIR__).'/ap-db.php',['ap_actor_id_membership_map','ap_block_list','ap_block_list_cached','ap_block_cache_clear','ap_actor_moderation_aliases','ap_actor_moderation_refs_match','ap_moderation_syntactic_refs','ap_moderation_path_segment_forms','ap_server_blocked_actors_set','ap_is_blocked_host','ap_is_blocked_actor','ap_is_globally_muted_actor','ap_user_blocks_list','ap_user_blocks_list_cached','ap_user_blocks_cache_clear','ap_blocks_actor_id_set','ap_user_is_blocked','ap_mutes_list','ap_mutes_set_cached','ap_mutes_cache_clear','ap_is_muted_actor','ap_row_is_blocked','ap_row_is_hidden','ap_search_canonical_actor','ap_search_actor_ref_list','ap_search_owner_identity','ap_search_refs_are_self','ap_search_actor_hidden','ap_search_account_hidden','ap_search_status_hidden','ap_deprioritized_list','ap_deprioritized_set_cached','ap_is_deprioritized_actor','ap_muted_words_list','ap_muted_words_phrases_cached','ap_muted_words_cache_clear','ap_muted_words_normalize_haystack','ap_muted_words_match']);
reference_load(getenv('TREND_REFERENCE_ENTITIES')?:dirname(__DIR__).'/ap-masto-entities.php',['ap_masto_trend_actor_hidden','ap_masto_trend_item_actors','ap_masto_trend_item_hidden']);
$sidebarSource=getenv('TREND_REFERENCE_SOURCE')?:dirname(__DIR__).'/ap-admin.php';$names=['admin_home_suppression_map','admin_trend_downrank_ref_set','admin_trend_status_is_downranked','admin_trends_sidebar_html'];
if(str_contains(file_get_contents($sidebarSource),'function admin_trend_actor_refs('))$names=array_merge($names,['admin_trend_actor_refs','admin_trend_status_hidden']);
if(str_contains(file_get_contents($sidebarSource),'function admin_trend_sparkline_svg('))$names[]='admin_trend_sparkline_svg';
reference_load($sidebarSource,$names);
$before=!in_array('admin_trend_actor_refs',$names,true);$results=[];
function trend_check(bool $value,string $name):void{if(!$value)throw new RuntimeException($name);$GLOBALS['checks'][]=$name;}
function trend_clear():void{foreach([201,202] as $id){ap_user_blocks_cache_clear($id);ap_mutes_cache_clear($id);ap_muted_words_cache_clear($id);ap_deprioritized_set_cached($id,true);}ap_block_cache_clear();}
function trend_status(string $name,string $actor):array{return ['uri'=>'https://post.test/'.$name,'url'=>'https://post.test/'.$name,'content'=>'POST-'.$name,'account'=>['uri'=>$actor,'url'=>$actor,'acct'=>$name]];}
foreach(['sqlite','pgsql-integer','pgsql-bigint'] as $driver){
 $checks=[];$redis=[];$redis_reads=[];$global_reads=0;$viewer=201;
 $db=$driver==='sqlite'?new PDO('sqlite::memory:'):new PDO('pgsql:host='.(getenv('LIBRARY_FIXTURE_SOCKET')?:'/tmp/vaak-library-facts-pg-socket').' user=postgres dbname=postgres');
 $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
 $schema='trending_policy_'.getmypid().'_'.str_replace('-','_',$driver);if($driver!=='sqlite'){$db->exec('CREATE SCHEMA '.$schema);$db->exec('SET search_path TO '.$schema);}
 try{
  $width=$driver==='pgsql-bigint'?'BIGINT':'INTEGER';
  $db->exec("CREATE TABLE ap_blocks(id INTEGER PRIMARY KEY AUTOINCREMENT,scope TEXT,kind TEXT,value TEXT,created_at TEXT);
  CREATE TABLE ap_user_blocks(id $width,owner_user_id $width,scope TEXT,value TEXT,created_at TEXT);
  CREATE TABLE ap_mutes(owner_user_id $width,actor_id TEXT,created_at TEXT);
  CREATE TABLE ap_deprioritized_actors(owner_user_id $width,actor_id TEXT,created_at TEXT);
  CREATE TABLE ap_home_suppression(owner_user_id $width,actor_id TEXT,score INTEGER,categories_json TEXT,suppressed_until TEXT);
  CREATE TABLE ap_muted_words(id $width,owner_user_id $width,phrase TEXT,created_at TEXT,note TEXT);
  CREATE TABLE ap_users(id $width,actor_id TEXT,actor_key TEXT);
  CREATE TABLE actor_profile(actor_key TEXT,downranking_enabled INTEGER);
  CREATE TABLE bsky_hide_dids(owner_user_id $width,did TEXT,reason TEXT,list_uri TEXT,updated_at TEXT);
  CREATE TABLE bsky_posts(author_handle TEXT,author_did TEXT);
  CREATE TABLE bsky_actor_profiles(did TEXT,actor_ref TEXT,profile_json TEXT)");
  $db->exec("INSERT INTO ap_users VALUES(201,'https://local.test/users/viewer201','viewer201'),(202,'https://local.test/users/viewer202','viewer202'); INSERT INTO actor_profile VALUES('viewer201',1),('viewer202',1); INSERT INTO bsky_posts VALUES('alias.test','did:plc:trendingtest')");
  $base=trend_status('target','https://remote.test/@alice');
  $candidates=['tags'=>[['name'=>'safe','history'=>[]]],'statuses'=>[$base]];trend_clear();$meta=null;
  $initial=admin_trends_sidebar_html('home',true,$meta);
  $db->exec("INSERT INTO ap_home_suppression VALUES(201,'https://remote.test/users/alice',1,'[]','".gmdate('c',time()+300)."')");
  $warm=admin_trends_sidebar_html('home',true,$meta);
  if($before){$results[$driver]=['before_html'=>$initial,'warm_html'=>$warm,'stale_bypass_reproduced'=>str_contains($warm,'POST-target')];continue;}
  trend_check(!str_contains($warm,'POST-target'),'warm fragment cannot bypass current suppression');
  trend_check(!array_filter($redis_reads,fn($key)=>str_starts_with($key,'vaak:fragment:trends:')),'no HTML fragment authority reads');
  $viewer=202;trend_check(str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'different viewer unaffected');$viewer=201;
  $db->exec("UPDATE actor_profile SET downranking_enabled=0 WHERE actor_key='viewer201'");
  trend_check(str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'current saved temporary flag disabled');
  $db->exec("INSERT INTO ap_deprioritized_actors VALUES(201,'https://remote.test/users/alice','now')");
  trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'manual choice independent of disabled temporary flag');
  $db->exec("DELETE FROM ap_deprioritized_actors; UPDATE actor_profile SET downranking_enabled=1 WHERE actor_key='viewer201'");
  trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'current saved temporary flag reenabled');

  $db->exec("UPDATE ap_home_suppression SET suppressed_until='2000-01-01T00:00:00Z'");trend_check(str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'suppression expiry current');
  $db->exec("INSERT INTO ap_deprioritized_actors VALUES(201,'https://remote.test/users/%61lice','now')");trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'manual encoded alias current');
  $db->exec('DELETE FROM ap_deprioritized_actors');trend_check(str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'manual unmark current without cached HTML');
  $candidates['statuses']=[trend_status('exact','https://exact.test/users/alice')];
  $db->exec("INSERT INTO ap_deprioritized_actors VALUES(201,'https://exact.test/users/alice','now')");
  ap_deprioritized_set_cached(201,true);
  trend_check(ap_masto_trend_item_hidden($candidates['statuses'][0],201),'legacy trend callers still deprioritize by default');
  trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-exact'),'current exact manual choice filters');
  $db->exec('DELETE FROM ap_deprioritized_actors');
  trend_check(str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-exact'),'current exact unmark ignores warmed legacy manual set');

  $db->exec("INSERT INTO ap_deprioritized_actors VALUES(201,'https://remote.test/users/Alice','now')");$candidates['statuses']=[$base];
  trend_check(str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'manual actor path case stays distinct');
  $candidates['statuses']=[trend_status('casealias','https://remote.test/@Alice')];trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-casealias'),'manual case-preserving web alias');$db->exec('DELETE FROM ap_deprioritized_actors');
  $db->exec("INSERT INTO ap_deprioritized_actors VALUES(201,'did:plc:trendingtest','now')");
  $candidates['statuses']=[trend_status('bsky','https://bsky.app/profile/alias.test')];trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-bsky'),'manual DID cached handle alias');
  $candidates['statuses']=[array_replace(trend_status('outer','https://outer.test/users/outer'),['quote'=>['reblog'=>trend_status('inner','https://bsky.app/profile/alias.test')]])];trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-outer'),'recursive quote boost manual author');
  $candidates['tags']=[['name'=>'suppressed-tag','trend_actors'=>['https://bsky.app/profile/alias.test'],'history'=>[]],['name'=>'safe','history'=>[]]];trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'suppressed-tag'),'tag contributors policy');
  $db->exec('DELETE FROM ap_deprioritized_actors');$candidates['tags']=[['name'=>'safe','history'=>[]]];
  foreach(['personal-block','personal-mute','server-block','server-mute','word'] as $policy){
   $db->exec('DELETE FROM ap_user_blocks; DELETE FROM ap_mutes; DELETE FROM ap_blocks; DELETE FROM ap_muted_words');
   if($policy==='personal-block')$db->exec("INSERT INTO ap_user_blocks VALUES(1,201,'actor','https://remote.test/users/alice','now')");
   if($policy==='personal-mute')$db->exec("INSERT INTO ap_mutes VALUES(201,'https://remote.test/users/alice','now')");
   if($policy==='server-block'||$policy==='server-mute')$db->exec("INSERT INTO ap_blocks(scope,kind,value,created_at) VALUES('actor','".($policy==='server-block'?'block':'mute')."','https://remote.test/users/alice','now')");
   if($policy==='word')$db->exec("INSERT INTO ap_muted_words VALUES(1,201,'forbidden','now',NULL)");
   trend_clear();$nested=$base;$nested['content']='forbidden';$candidates['statuses']=[array_replace(trend_status('outer','https://outer.test/users/outer'),['quote'=>['reblog'=>$nested]])];
   trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-outer'),'nested '.$policy);
   $viewer=202;trend_check(str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-outer')===!str_starts_with($policy,'server-'),'isolation '.$policy);$viewer=201;
  }
  $db->exec('DELETE FROM ap_user_blocks; DELETE FROM ap_mutes; DELETE FROM ap_blocks; DELETE FROM ap_muted_words');trend_clear();
  $hideDid='did:plc:hidden'.str_replace('-','',$driver);
  $db->prepare("INSERT INTO bsky_hide_dids VALUES(201,?,'mute','','now')")->execute([$hideDid]);ap_bsky_hide_did_set_clear_cache();
  $candidates['statuses']=[array_replace(trend_status('hideouter','https://outer.test/users/outer'),['quote'=>['author_did'=>$hideDid,'content'=>'native hidden']])];
  trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-hideouter'),'actual native DID hidden set nested author');
  $viewer=202;trend_check(str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-hideouter'),'native DID hidden set owner isolation');$viewer=201;
  foreach(['personal-mute','personal-block','server-mute','server-block'] as $rule){
   $db->exec('DELETE FROM ap_mutes;DELETE FROM ap_user_blocks;DELETE FROM ap_blocks');
   $did='did:plc:'.str_replace('-','',$rule).str_replace('-','',$driver);
   if($rule==='personal-mute')$db->prepare("INSERT INTO ap_mutes VALUES(201,?,'now')")->execute([$did]);
   elseif($rule==='personal-block')$db->prepare("INSERT INTO ap_user_blocks VALUES(1,201,'actor',?,'now')")->execute([$did]);
   else $db->prepare("INSERT INTO ap_blocks(scope,kind,value,created_at) VALUES('actor',?,?,'now')")->execute([$rule==='server-mute'?'mute':'block',$did]);
   trend_clear();$candidates['statuses']=[array_replace(trend_status('didouter','https://outer.test/users/outer'),['quote'=>['reblog'=>['author_did'=>$did]]])];
   trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-didouter'),'nested DID '.$rule);
  }
  $db->exec('DELETE FROM ap_user_blocks; DELETE FROM ap_mutes; DELETE FROM ap_blocks; DELETE FROM ap_muted_words');trend_clear();
  $viewer=0;$uriOnly=$base;$uriOnly['account']=['uri'=>'https://remote.test/@alice'];$candidates['statuses']=[$uriOnly];
  foreach(['block','mute'] as $kind){
   $db->exec('DELETE FROM ap_blocks');$db->prepare("INSERT INTO ap_blocks(scope,kind,value,created_at) VALUES('actor',?,'https://remote.test/users/alice','now')")->execute([$kind]);trend_clear();
   trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'guest URI-only server '.$kind);
  }
  $viewer=201;$db->exec('DELETE FROM ap_blocks');trend_clear();
  $deep=trend_status('deep','https://eligible.test/users/u');foreach(range(1,18) as $level)$deep=['content'=>'POST-too-deep','quote'=>$deep];$candidates['statuses']=[$deep];
  trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-too-deep'),'excess nesting fails closed');
  $db->exec('DELETE FROM ap_user_blocks; DELETE FROM ap_mutes; DELETE FROM ap_blocks; DELETE FROM ap_muted_words');trend_clear();
  $db->exec("INSERT INTO ap_blocks(scope,kind,value,created_at) VALUES('actor','block','https://remote.test/users/alice','now')");trend_clear();$viewer=0;$candidates['statuses']=[$base];
  trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'server moderation applies with owner zero');
  $viewer=201;$db->exec('DELETE FROM ap_blocks');trend_clear();
  $db->exec("INSERT INTO ap_deprioritized_actors VALUES(201,'https://remote.test/users/alice','now')");$candidates['statuses']=[$base];foreach(range(1,8) as $n)$candidates['statuses'][]=trend_status('eligible'.$n,'https://eligible.test/users/u'.$n);
  $began=microtime(true);$html=admin_trends_sidebar_html('home',true,$meta);$duration=(microtime(true)-$began)*1000;
  trend_check(!str_contains($html,'POST-target')&&str_contains($html,'POST-eligible5')&&!str_contains($html,'POST-eligible6'),'refill exactly five in unchanged order');
  $db->exec('DELETE FROM ap_deprioritized_actors;DELETE FROM ap_home_suppression');trend_clear();$candidates['statuses']=[$base];
  $cache_hook=function(string $kind)use($db):void{if($kind==='statuses')$db->prepare("INSERT INTO ap_home_suppression VALUES(201,'https://remote.test/users/alice',1,'[]',?)")->execute([gmdate('c',time()+300)]);};
  trend_check(!str_contains(admin_trends_sidebar_html('home',true,$meta),'POST-target'),'policy reread after global cache IO');unset($cache_hook);
  $cache_hook=function(string $kind):void{if($kind==='statuses')$GLOBALS['viewer']=202;};
  trend_check(admin_trends_sidebar_html('home',true,$meta)==='','viewer change during cache IO refuses old binding');unset($cache_hook);$viewer=201;
  $results[$driver]=['checks'=>$checks,'count'=>count($checks),'warm_html'=>$warm,'refill_html'=>$html,'paint_ms'=>$duration,'global_cache_reads'=>$global_reads,'external_fetches'=>0];
 }finally{if($driver!=='sqlite')$db->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
file_put_contents($argv[1]??'/tmp/trending-policy-reference.json',json_encode(['source_sha256'=>hash_file('sha256',$sidebarSource),'boundary'=>'actual extracted PHP/SQL moderation helpers; recorded Redis/global-cache/session/status-link/Bsky-hide transports. No external collection, full SAPI, or production latency claim.','results'=>$results],JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['before'=>$before,'cases'=>$before?3:array_sum(array_column($results,'count'))]).PHP_EOL;
