<?php
require __DIR__ . '/library-privacy-admission-smoke.php';
$GLOBALS['db_failure']=false;$db->exec('DELETE FROM fixture_words;DELETE FROM fixture_blocks;DELETE FROM fixture_mutes;DELETE FROM bsky_hide_dids;INSERT INTO bsky_hide_dids VALUES(201,\'did:plc:hidden\');');
$wrapper=['post'=>['uri'=>'at://did:plc:v/app.bsky.feed.post/1','author'=>['did'=>'did:plc:v','handle'=>'v.test'],'record'=>['text'=>'clean']],'reason'=>['by'=>['did'=>'did:plc:hidden']]];
echo 'PHP wrapper allowed=',admin_library_bsky_item_allowed($wrapper,201)?'true':'false',PHP_EOL;
function ap_block_list_cached():array{return [['scope'=>'actor','value'=>'https://sub.remote.test','kind'=>'block']];}
$db->exec('CREATE TABLE ap_wafrn_friend_servers(host TEXT,enabled INTEGER);');
reference_load(dirname(__DIR__).'/ap-db.php',['ap_is_blocked_host','ap_is_blocked_inbox','ap_wafrn_friend_inboxes']);
reference_load(dirname(__DIR__).'/ap-asks.php',['ap_wafrn_actor_host_known']);
putenv('VAAK_WAFRN_FRIEND_HOSTS=https://sub.remote.test/');
echo 'PHP Wafrn actor-only server block membership=',ap_wafrn_actor_host_known('https://sub.remote.test/users/a')?'true':'false',PHP_EOL;

foreach([14,15,16,17] as $depth){$tree=[];for($i=0;$i<$depth;$i++)$tree=['child'=>$tree]; echo 'PHP native depth '.$depth.' allowed=',admin_library_bsky_item_allowed($tree,201)?'true':'false',PHP_EOL;}
$tree=[];for($i=0;$i<18;$i++)$tree=['child'=>$tree];$status=['id'=>'metadata','uri'=>'https://allowed.test/post','visibility'=>'public','content'=>'clean','account'=>['uri'=>'https://allowed.test/users/a'],'metadata'=>$tree];echo 'PHP unrelated deep metadata allowed=',admin_library_status_allowed($status,201)?'true':'false',PHP_EOL;
$status['metadata']=['viewer'=>['blockedBy'=>true]];echo 'PHP unrelated metadata blockedBy allowed=',admin_library_status_allowed($status,201)?'true':'false',PHP_EOL;
