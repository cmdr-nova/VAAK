<?php
declare(strict_types=1);
/** Actual production declarations; transport and clock substitutions are explicit. */
$root = sys_get_temp_dir().'/vaak-quote-helper-reference-'.getmypid();
mkdir($root,0700); $GLOBALS['quote_temp']=$root; $GLOBALS['quote_clock']=1791700200;
$GLOBALS['quote_db']=new PDO('sqlite::memory:');
$GLOBALS['quote_db']->exec('CREATE TABLE events(object_id TEXT,type TEXT,action_taken TEXT);CREATE TABLE bsky_crossposts(bsky_uri TEXT);CREATE TABLE bsky_posts(bsky_uri TEXT,author_handle TEXT)');
function ap_db():PDO{return $GLOBALS['quote_db'];}
function quote_temp():string{return $GLOBALS['quote_temp'];}
function quote_clock():int{return $GLOBALS['quote_clock'];}
function quote_exec(string $cmd):void{$GLOBALS['quote_effects'][]=['kind'=>'process_record_only','command'=>$cmd];}
function ap_php_cli_binary():string{return $GLOBALS['quote_php']??'/usr/bin/php';}
function ap_db_masto_owner_user_id():int{return $GLOBALS['quote_owner']??0;}
function ap_bsky_crossposts_migrate():void{}
function ap_bsky_resolve_handle_did(string $h,int $o=0):?string{$GLOBALS['quote_effects'][]=['kind'=>'resolve_handle_record_only','handle'=>$h,'owner'=>$o];return 'did:plc:resolved';}
function ap_redis_lock(string $k,int $ttl=30):bool{$GLOBALS['quote_effects'][]=['kind'=>'redis_lock','key'=>$k,'ttl'=>$ttl,'result'=>$GLOBALS['quote_lock']];return $GLOBALS['quote_lock'];}
function ap_redis_queue_push(string $q,string $p):bool{$GLOBALS['quote_effects'][]=['kind'=>'redis_push','queue'=>$q,'payload'=>json_decode($p,true),'result'=>$GLOBALS['quote_push']];return $GLOBALS['quote_push'];}
$declarations=[];$instrumented=[];
function quote_load(string $file,string $name):void{
 $source=(string)file_get_contents(dirname(__DIR__).'/'.$file);
 if(!preg_match('/^function '.preg_quote($name,'/').'\(.*?^}/ms',$source,$m))throw new RuntimeException($name);
 $GLOBALS['declarations'][$name]=hash('sha256',$m[0]);
 $body=str_replace(['sys_get_temp_dir()','time()','@exec(',' exec(','__DIR__'],['quote_temp()','quote_clock()','quote_exec(',' quote_exec(',var_export(dirname(__DIR__),true)],$m[0]);
 $GLOBALS['instrumented'][$name]=$body;eval($body);
}
foreach(['ap_quote_target_is_bluesky','ap_quote_post_parent_url','ap_object_url_lookup_candidates'] as $n)quote_load('ap-inbox.php',$n);
foreach(['ap_bsky_at_uri_from_https','ap_bsky_at_uri_from_any_url','ap_bsky_post_preview_warm_enqueue'] as $n)quote_load('ap-bsky.php',$n);
foreach(['ap_url_looks_like_status_object','ap_object_target_unavailable_reason','ap_quote_target_warm_async'] as $n)quote_load('ap-masto-entities.php',$n);
$inputs=['','at://did:plc:a/app.bsky.feed.post/abc','HTTPS://peer.test/notes/abc',' https://peer.test/notes/abc/ ','https://peer.test/','https://peer.test/blog/article','https://youtube.com/posts/123','https://www.x.com/post/12','https://peer.test/notes/image.PNG','https://peer.test/notes/abc','https://peer.test/objects/abc/suffix','https://peer.test/@alice/abc','https://peer.test/@alice/abc/suffix','https://peer.test/post/123a','https://sub.bsky.app/profile/a/post/key','https://bsky.app/profile/did:plc:a/post/key','https://bsky.brid.gy/convert/ap/at://did:plc:a/app.bsky.feed.post/key','https://notbsky.brid.gy.test/blog','https://threads.net/user/post/text','https://peer.test/users/a/statuses/abc/QuotePost','https://peer.test/@a%2Fb/abc','https://peer.test/users/a%2Fb/posts/abc','https://peer.test/notes/abc/activity'];
$pure=[];foreach($inputs as $url)$pure[]=['input'=>$url,'status_shape'=>ap_url_looks_like_status_object($url),'aliases'=>ap_object_url_lookup_candidates($url),'at_uri'=>ap_bsky_at_uri_from_any_url($url)];
$captures=[];
$cases=[['name'=>'ordinary'],['name'=>'cooldown89','previous'=>1791700111],['name'=>'cooldown90','previous'=>1791700110],['name'=>'future_stamp','previous'=>1791700201],['name'=>'malformed_stamp','previous'=>'invalid'],['name'=>'miss_recent','miss'=>'miss','miss_age'=>1799],['name'=>'deleted_recent','miss'=>'tombstone','miss_age'=>0],['name'=>'miss_expired','miss'=>'deleted','miss_age'=>1800],['name'=>'miss_future','miss'=>'deleted','miss_age'=>-1],['name'=>'delete_alias','delete'=>'https://peer.test/users/a/statuses/abc/'],['name'=>'deleted_update','deleted_update'=>true],['name'=>'nonstatus','url'=>'https://peer.test/blog/column'],['name'=>'bsky_queue','url'=>'https://bsky.app/profile/did:plc:a/post/key','owner'=>9],['name'=>'bsky_guest','url'=>'https://bsky.app/profile/did:plc:a/post/key','owner'=>0],['name'=>'bsky_locked','url'=>'https://bsky.app/profile/did:plc:a/post/key','lock'=>false],['name'=>'bsky_push_failed','url'=>'https://bsky.app/profile/did:plc:a/post/key','push'=>false],['name'=>'bsky_primary_off','url'=>'https://bsky.app/profile/did:plc:a/post/key','primary'=>'0'],['name'=>'php_fpm_fallback','php'=>'/usr/sbin/php-fpm8.3']];
try{foreach($cases as $case){foreach(glob($root.'/*') as $p)unlink($p);ap_db()->exec('DELETE FROM events');$GLOBALS['quote_effects']=[];$GLOBALS['quote_owner']=$case['owner']??2;$GLOBALS['quote_php']=$case['php']??'/usr/bin/php';$GLOBALS['quote_lock']=$case['lock']??true;$GLOBALS['quote_push']=$case['push']??true;putenv('VAAK_THIN_MEDIA_RUST_PRIMARY='.($case['primary']??'1'));$url=$case['url']??'https://peer.test/@a/abc';$hash=hash('sha256',$url);
 if(array_key_exists('previous',$case))file_put_contents($root.'/vaak-quote-warm-'.$hash.'.lock',(string)$case['previous']);
 if(isset($case['miss'])){$p=$root.'/vaak-quote-warm-miss-'.$hash;file_put_contents($p,$case['miss']);touch($p,$GLOBALS['quote_clock']-$case['miss_age']);}
 if(isset($case['delete']))ap_db()->prepare('INSERT INTO events VALUES(?,\'Delete\',\'log\')')->execute([$case['delete']]);
 if(isset($case['deleted_update']))ap_db()->prepare('INSERT INTO events VALUES(?,\'Update\',\'deleted\')')->execute([$url]);
 $unavailable=ap_object_target_unavailable_reason($url);ap_quote_target_warm_async($url);$lockfile=$root.'/vaak-quote-warm-'.$hash.'.lock';$captures[]=['case'=>$case,'url'=>$url,'unavailable'=>$unavailable,'effects'=>$GLOBALS['quote_effects'],'lock_stamp'=>is_file($lockfile)?file_get_contents($lockfile):null];
}}finally{foreach(glob($root.'/*') as $p)unlink($p);rmdir($root);}
$out=$argv[1]??'/tmp/vaak-profile-quote-warm-reference.json';file_put_contents($out,json_encode(['clock'=>$GLOBALS['quote_clock'],'pure'=>$pure,'captures'=>$captures,'declaration_sha256'=>$GLOBALS['declarations'],'instrumented_declarations'=>$GLOBALS['instrumented'],'scope'=>'Actual helper branches and SQLite alias Delete queries, actual flock/stat/stamp behavior. Clock/temp directory, Redis lock/push, handle network resolver and subprocess effects explicitly recorded; no actual queue/process delivery.'],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES));echo count($pure).' pure / '.count($captures).' warm captures '.$out."\n";
