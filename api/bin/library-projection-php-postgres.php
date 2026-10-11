<?php
// Actual canonical source queries on the same disposable PostgreSQL schema.
require __DIR__.'/migration-reference-functions.php';
$schema=$argv[1]??'';
if(!preg_match('/^library_projection_(integer|bigint)$/D',$schema))throw new RuntimeException('Disposable schema required');
$GLOBALS['projection_db']=new PDO('pgsql:host='.getenv('LIBRARY_FIXTURE_SOCKET').';dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
function ap_db(): PDO{return $GLOBALS['projection_db'];}
ap_db()->exec('SET search_path='.$schema);
reference_load(dirname(__DIR__).'/ap-db.php',['ap_masto_favourite_rows','ap_masto_bookmark_rows']);
reference_load(dirname(__DIR__).'/ap-bsky.php',['ap_bsky_favourite_cache_migrate','ap_bsky_favourite_cache_read','ap_bsky_favourite_cache_count']);
$fedi=array_values(array_filter(ap_masto_favourite_rows(80,null,1),static fn($row)=>!str_starts_with($row['status_id'],'bsky:')&&!str_starts_with($row['object_id'],'at://')&&!str_contains($row['object_id'],'bsky.app/')));
$ids=array_column($fedi,'id');sort($ids);
// Execute the production network-folder source query without replacing it.
$admin=file_get_contents(dirname(__DIR__).'/ap-admin.php');
if(!preg_match('/\$st = ap_db\(\)->prepare\(\s*"(SELECT \* FROM masto_bookmarks b\s+WHERE b.owner_user_id = \?.*?OFFSET \? LIMIT \?)"/s',$admin,$match))throw new RuntimeException('Canonical folder query not found');
$st=ap_db()->prepare($match[1]);$st->execute([1,7,0,11]);$folder=$st->fetchAll();
$posts=ap_bsky_favourite_cache_read(1,10,0);
echo json_encode(['fedi_ids'=>$ids,'folder_ids'=>array_column($folder,'id'),'bsky_uris'=>array_column(array_column($posts,'post'),'uri'),'total_bsky'=>ap_bsky_favourite_cache_count(1)],JSON_THROW_ON_ERROR);
