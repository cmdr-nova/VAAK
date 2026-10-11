<?php
declare(strict_types=1);
/** Independent actual cache-reader/painter check against an inconsistent owner
 * cache payload. Reuses the isolated canonical reference setup, never a socket. */
ob_start();require __DIR__.'/library-bsky-bookmark-owner-reference.php';ob_end_clean();
unset($GLOBALS['vaak_bsky_bookmark_owner_state']);
ap_db()->exec('DELETE FROM bsky_bookmark_cache; DELETE FROM masto_bookmarks');
$storedKey='at://did:plc:remote/app.bsky.feed.post/cache-key';
ap_db()->prepare('INSERT INTO bsky_bookmark_cache(owner_user_id,bookmark_uri,post_json) VALUES(?,?,?)')->execute([2,$storedKey,json_encode(['post'=>$post],JSON_THROW_ON_ERROR)]);
$mismatch=ap_bsky_bookmark_cache_read(2,10);
if(count($mismatch)!==1)throw new RuntimeException('Expected actual owner cache row');
if(ap_bsky_bookmark_owner_state($storedKey,2)!==null||ap_bsky_bookmark_owner_state($uri,2)!==null)throw new RuntimeException('Mismatched payload established bookmark authority');
foreach([false,true] as $normalized) {
    if(bookmark_paint($mismatch[0],2,$normalized))throw new RuntimeException('Mismatched payload rendered a saved action '.($normalized?'normalized':'legacy'));
}
if(ap_bsky_bookmark_owner_state($uri,1)!==null)throw new RuntimeException('Mismatched owner cache transferred authority');
echo "library-bsky-cache-consistency-independent: PASS (5 actual cache/painter controls; mismatched payload establishes no action state)\n";
