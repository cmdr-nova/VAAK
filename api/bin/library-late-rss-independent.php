<?php
/** Independent actual PHP late cache-to-full-painter RSS regression; isolated SQLite transport. */
declare(strict_types=1);
ob_start();require __DIR__.'/migration-card-reference-capture.php';$reference=json_decode(ob_get_clean(),true,512,JSON_THROW_ON_ERROR);
$base=$reference['captures']['plain']['status'];$GLOBALS['fixture_render_clock']=1700000000;
$target='https://article.test/post';
$rss=['id'=>'rss:11','source'=>'rss','uri'=>$target,'url'=>$target,'content'=>'STALE LATE PRIVATE SNAPSHOT','account'=>['acct'=>'Old Private Feed','uri'=>'https://feed.test'],'vaak_rss_item_id'=>11];
ap_db()->exec("UPDATE rss_items SET title='Fresh late owner title',summary_text='Fresh late owner summary' WHERE id=11");
function late_paint(array $base,string $target,string $kind,int $owner):string {
 $GLOBALS['vaak_owner_id']=$owner;$GLOBALS['vaak_user']=['id'=>$owner];$GLOBALS['fixture_render_clock']=1700000000;$GLOBALS['admin_library_fetch_budget']=3;$GLOBALS['admin_quote_link_preview_budget']=3;
 $base['content']=$kind==='pending'?'Public comment':"Public comment\n↪ QT @old@feed.test: $target";
 $base['quote_url']=$target;if($kind==='pending')$base['quote']=['state'=>'pending'];
 ob_start();admin_render_masto_status_card($base,[],'bookmarks');return ob_get_clean();
}
$checks=0;
foreach (['pending','raw'] as $kind) {
 ap_db()->exec('DELETE FROM fixture_status_cache');ap_db()->prepare('INSERT INTO fixture_status_cache VALUES(?,?)')->execute([$target,json_encode($rss)]);
 $html=late_paint($base,$target,$kind,1);
 if(str_contains($html,'STALE LATE PRIVATE SNAPSHOT')||!str_contains($html,'Fresh late owner title')||!str_contains($html,'Fresh late owner summary'))throw new RuntimeException("Current late $kind stale");$checks++;
 $html=late_paint($base,$target,$kind,2);if(str_contains($html,'STALE LATE PRIVATE SNAPSHOT')||str_contains($html,'Fresh late owner title'))throw new RuntimeException("Foreign late $kind RSS leaked");$checks++;
 ap_db()->exec("INSERT INTO fixture_words VALUES(1,'fresh late owner title')");$html=late_paint($base,$target,$kind,1);if(str_contains($html,'Fresh late owner title')||str_contains($html,'STALE LATE PRIVATE SNAPSHOT'))throw new RuntimeException("Muted late $kind RSS leaked");$checks++;ap_db()->exec('DELETE FROM fixture_words');
 foreach(['rss:9999','rss:bad','rss:0'] as $id){$bad=$rss;$bad['id']=$id;ap_db()->exec('DELETE FROM fixture_status_cache');ap_db()->prepare('INSERT INTO fixture_status_cache VALUES(?,?)')->execute([$target,json_encode($bad)]);$html=late_paint($base,$target,$kind,1);if(str_contains($html,'STALE LATE PRIVATE SNAPSHOT'))throw new RuntimeException("Missing/malformed late $kind leaked");$checks++;}
}
echo "library-late-rss-independent: PASS ($checks actual pending/raw cache recovery full-painter freshness/owner/words/malformed controls; cache transport isolated)\n";
