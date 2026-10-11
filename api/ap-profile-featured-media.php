<?php
/** Retained media-worker dependency for the native Featured read surface.
 * CLI only. Returns public avatar/cache receipts; never HTML, status or grants.
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$actor=$argv[1]??'';
if (count($argv)!==2 || $actor==='' || strlen($actor)>2048 || !str_starts_with($actor,'https://') || preg_match('/[\x00-\x1f\x7f]/',$actor)) exit(2);
require_once __DIR__.'/ap-db.php';
require_once __DIR__.'/ap-r2.php';
function profile_featured_media_rows(string $actor,string $table):array {
    $sql=$table==='remote_media_cache'
        ? "SELECT * FROM remote_media_cache WHERE actor_id=? AND kind='avatar'"
        : "SELECT * FROM remote_actors WHERE actor_id=? OR actor_id=?";
    $statement=ap_db()->prepare($sql);$statement->execute($table==='remote_media_cache'?[$actor]:[$actor,$actor.'/']);return $statement->fetchAll()?:[];
}
$before=[];foreach(['remote_actors','remote_media_cache']as $table)$before[$table]=profile_featured_media_rows($actor,$table);
$avatar=ap_remote_media_ensure($actor,'avatar',false);
$receipts=[];foreach(['remote_actors','remote_media_cache']as $table)$receipts[]=['table'=>$table,'before'=>$before[$table],'after'=>profile_featured_media_rows($actor,$table)];
echo json_encode(['protocol_version'=>1,'actor'=>$actor,'avatar'=>$avatar,'cache_receipts'=>$receipts],JSON_THROW_ON_ERROR|JSON_UNESCAPED_SLASHES);
