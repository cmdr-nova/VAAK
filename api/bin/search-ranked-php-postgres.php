<?php
require __DIR__.'/migration-reference-functions.php';
$schema=$argv[1]??'';if(!preg_match('/^search_ranked_(integer|bigint)$/D',$schema))throw new RuntimeException('Disposable schema only');
$GLOBALS['db']=new PDO('pgsql:host=/tmp/vaak-library-facts-pg-socket;dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function ap_db(): PDO{return $GLOBALS['db'];}
function ap_db_masto_owner_user_id():int{return (int)$GLOBALS['owner'];}
ap_db()->exec('SET search_path='.$schema);$GLOBALS['owner']=(int)$argv[2];$query=$argv[3];$tag=$argv[4];$limit=max(1,min(180,(int)$argv[5]));
reference_load(dirname(__DIR__).'/ap-db.php',['ap_fix_utf8']);
reference_load(dirname(__DIR__).'/ap-search-fts.php',['ap_search_fts_match_query','ap_search_fts_postgres_query','ap_search_fts_ranked_hits','ap_search_fts_blend_networks']);
$match=ap_search_fts_match_query($query,$tag);$rows=$match===null?[]:ap_search_fts_blend_networks(ap_search_fts_ranked_hits($match,$tag,$limit,'fedi',true),ap_search_fts_ranked_hits($match,$tag,$limit,'bsky',true),$limit,true);
echo json_encode($rows,JSON_THROW_ON_ERROR);
