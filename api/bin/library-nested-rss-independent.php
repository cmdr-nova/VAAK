<?php
/** Actual canonical binder/parser/owner SQL/converter checks; no transport or production writes. */
declare(strict_types=1);
require __DIR__.'/library-privacy-admission-smoke.php';
$GLOBALS['db_failure']=false;
foreach (['ap_rss_local_status_id','ap_rss_item_to_masto_status'] as $fn) if (!function_exists($fn)) reference_load($api.'/ap-rss.php',[$fn]);
$db->exec("DELETE FROM rss_items;DELETE FROM rss_feeds;DELETE FROM fixture_words;INSERT INTO rss_feeds VALUES(1,201,'Private feed','','https://feed.test','https://feed.test/rss');");
$columns=$db->query('PRAGMA table_info(rss_items)')->fetchAll(PDO::FETCH_COLUMN,1);
foreach (['url','summary_text','image_url','published_at','mirror_note_id'] as $column) if (!in_array($column,$columns,true)) $db->exec("ALTER TABLE rss_items ADD $column TEXT");
$db->exec("INSERT INTO rss_items(id,feed_id,title,url,summary_text,published_at) VALUES(11,1,'Current private title','https://article.test/post','Current private summary','2026-10-10T00:00:00Z');");
$synthetic=['id'=>'rss:11','source'=>'rss','vaak_rss_item_id'=>11,'content'=>'STALE PRIVATE SNAPSHOT'];
$n=0;
function independent_check(bool $ok,string $label):void {if(!$ok)throw new RuntimeException($label);$GLOBALS['n']++;}
foreach (['quote','reblog','compact'] as $placement) {
 $parent=['id'=>'outer','content'=>'Public comment','uri'=>'https://remote.test/public','visibility'=>'public','account'=>['uri'=>'https://remote.test/users/a']];
 $parent[$placement==='compact'?'vaak_quote_preview':$placement]=$placement==='quote'?['state'=>'accepted','quoted_status'=>$synthetic]:$synthetic;
 independent_check(admin_library_bind_rss_sources($parent,202)===null,"foreign $placement denied");
 $current=admin_library_bind_rss_sources($parent,201);
 independent_check(is_array($current)&&str_contains(json_encode($current),'Current private title')&&!str_contains(json_encode($current),'STALE PRIVATE SNAPSHOT'),"fresh currentowner $placement");
 foreach (['rss:bad','rss:0','rss:-11','rss:999'] as $id) {
  $bad=$synthetic;$bad['id']=$id;$q=$parent;$q[$placement==='compact'?'vaak_quote_preview':$placement]=$placement==='quote'?['quoted_status'=>$bad]:$bad;
  independent_check(admin_library_bind_rss_sources($q,201)===null,"malformed or missing $placement $id");
 }
 foreach (['11',11.0,true,null] as $id) {
  $bad=$synthetic;$bad['id']='ordinary';$bad['vaak_rss_item_id']=$id;$q=$parent;$q[$placement==='compact'?'vaak_quote_preview':$placement]=$placement==='quote'?['quoted_status'=>$bad]:$bad;
  independent_check(admin_library_bind_rss_sources($q,201)===null,"marker type $placement");
 }
 $db->exec("INSERT INTO fixture_words VALUES(201,'forbidden article');UPDATE rss_items SET title='Changed forbidden article';");
 independent_check(admin_library_bind_rss_sources($parent,201)===null,"fresh title words $placement");
 $db->exec("DELETE FROM fixture_words;UPDATE rss_items SET title='Current private title';INSERT INTO fixture_words VALUES(201,'forbidden summary');UPDATE rss_items SET summary_text='Changed forbidden summary';");
 independent_check(admin_library_bind_rss_sources($parent,201)===null,"fresh summary words $placement");
 $db->exec("DELETE FROM fixture_words;UPDATE rss_items SET summary_text='Current private summary';");
}
foreach (["rss:11 ","rss:11\n"] as $id) {$st=$synthetic;$st['id']=$id;independent_check(is_array(admin_library_bind_rss_sources($st,201)),'actual PHP whitespace parser acceptance');}
$public=['id'=>'ordinary-public','source'=>'activitypub','content'=>'Public federated article','uri'=>'https://remote.test/mirror'];
independent_check(admin_library_bind_rss_sources($public,202)===$public,'ordinary foreign public AS2 preserved');
echo "library-nested-rss-independent: PASS ($n actual binder/parser/owner SQL/converter/muted-word controls; renderer transport excluded)\n";
