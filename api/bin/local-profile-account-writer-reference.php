<?php
declare(strict_types=1);
/** Actual remember/local/alias declarations on disposable SQL; no network. */
$conn=(string)getenv('LIBRARY_FIXTURE_DB');if(!str_contains($conn,'/tmp/'))throw new RuntimeException('disposable local PostgreSQL required');
$db=new PDO('pgsql:'.$conn,'postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);$GLOBALS['writer_db']=$db;
function ap_db():PDO{return $GLOBALS['writer_db'];}
$hashes=[];$source=(string)file_get_contents(dirname(__DIR__).'/ap-masto-entities.php');foreach(['ap_masto_local_user_by_account_id','ap_masto_actor_id_aliases','ap_masto_account_actor_remember'] as $name){if(!preg_match('/^function '.preg_quote($name,'/').'\(.*?^}/ms',$source,$m))throw new RuntimeException($name);eval($m[0]);$hashes[$name]=hash('sha256',$m[0]);}
$source=(string)file_get_contents(dirname(__DIR__).'/ap-db.php');preg_match('/^function ap_db_now\(.*?^}/ms',$source,$m);eval($m[0]);$hashes['ap_db_now']=hash('sha256',$m[0]);
$schema='profile_account_writer_php_'.getmypid();$db->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);
$captures=[];$ordered=[];try{
$db->exec('CREATE TABLE ap_users(id BIGINT,actor_key TEXT,disabled_at TEXT);CREATE TABLE remote_actors(actor_id TEXT,username TEXT,host TEXT);CREATE TABLE following(actor_id TEXT,host TEXT);CREATE TABLE events(actor_id TEXT,type TEXT);CREATE TABLE masto_account_actors(account_id TEXT PRIMARY KEY,actor_id TEXT UNIQUE,updated_at TEXT)');
$cases=[
 ['native_boost','758938460','https://remote.test/users/profile-boost-target',[['events',['actor_id'=>'https://remote.test/users/profile-boost-target','type'=>'Create']],['masto_account_actors',['account_id'=>'old-native','actor_id'=>'https://remote.test/users/profile-boost-target/','updated_at'=>'original']]]],
 ['empty','','https://remote.test/users/empty',[]],
 ['local_primary','1','https://remote.test/users/primary',[['ap_users',['id'=>99,'actor_key'=>'cmdr_nova','disabled_at'=>null]]]],
 ['local_numeric','2','https://remote.test/users/numeric',[['ap_users',['id'=>2,'actor_key'=>'other','disabled_at'=>null]]]],
 ['local_legacy','444','https://remote.test/users/legacy',[['ap_users',['id'=>3,'actor_key'=>'legacy','disabled_at'=>null]],['masto_account_actors',['account_id'=>'444','actor_id'=>'https://mkultra.monster/users/legacy/','updated_at'=>'original']]]],
 ['disabled','4','https://remote.test/users/disabled',[['ap_users',['id'=>4,'actor_key'=>'disabled','disabled_at'=>'now']]]],
 ['scored_alias','555',' https://remote.test/users/scored/ ',[['remote_actors',['actor_id'=>'https://remote.test/users/scored','username'=>'scored','host'=>'remote.test']],['remote_actors',['actor_id'=>'https://remote.test/ap/users/51','username'=>'scored','host'=>'remote.test']],['events',['actor_id'=>'https://remote.test/ap/users/51/','type'=>'Create']],['events',['actor_id'=>'https://remote.test/ap/users/51','type'=>'Create']],['events',['actor_id'=>'https://remote.test/users/scored','type'=>'Create']],['masto_account_actors',['account_id'=>'old','actor_id'=>'https://remote.test/ap/users/51/','updated_at'=>'original']],['masto_account_actors',['account_id'=>'untouched','actor_id'=>'https://unrelated.test/users/u','updated_at'=>'original']]]],
 ['encoded_alias','666','https://remote.test/users/encoded',[['remote_actors',['actor_id'=>'https://remote.test/users/encoded','username'=>'name space','host'=>'REMOTE.TEST']],['following',['actor_id'=>'https://remote.test/users/name%20space','host'=>'remote.test']],['events',['actor_id'=>'https://remote.test/users/name%20space','type'=>'Create']]]]
];
foreach($cases as [$case,$id,$actor,$seed]){foreach(['ap_users','remote_actors','following','events','masto_account_actors'] as $table)$db->exec('DELETE FROM '.$table);foreach($seed as [$table,$row]){$st=$db->prepare('INSERT INTO '.$table.' SELECT * FROM jsonb_populate_record(NULL::'.$table.',CAST(? AS jsonb))');$st->execute([json_encode($row,JSON_THROW_ON_ERROR)]);} $initial=[];foreach(['ap_users','remote_actors','following','events','masto_account_actors'] as $table)$initial[$table]=$db->query('SELECT * FROM '.$table)->fetchAll(PDO::FETCH_ASSOC);$trimActor=rtrim(trim($actor),'/');$local=ap_masto_local_user_by_account_id(trim($id))!==null;$aliases=ap_masto_actor_id_aliases($trimActor);ap_masto_account_actor_remember($id,$actor);$after=$db->query('SELECT account_id,actor_id FROM masto_account_actors ORDER BY account_id')->fetchAll(PDO::FETCH_ASSOC);$captures[]=compact('case','id','actor','initial','local','aliases','after');}
foreach(['ap_users','remote_actors','following','events','masto_account_actors'] as $table)$db->exec('DELETE FROM '.$table);
$actor='https://remote.test/users/profile-boost-target';
ap_masto_account_actor_remember('old-native',$actor.'/');$before=$db->query('SELECT * FROM masto_account_actors ORDER BY account_id')->fetchAll(PDO::FETCH_ASSOC);
ap_masto_account_actor_remember('758938460',$actor);$after=$db->query('SELECT account_id,actor_id FROM masto_account_actors ORDER BY account_id')->fetchAll(PDO::FETCH_ASSOC);
$ordered[]=['case'=>'old_then_new_id_same_actor','actor'=>$actor,'before'=>$before,'after'=>$after];
}finally{$db->exec('DROP SCHEMA '.$schema.' CASCADE');}
$out=$argv[1]??'/tmp/vaak-profile-account-writer-reference.json';file_put_contents($out,json_encode(['captures'=>$captures,'ordered'=>$ordered,'declaration_sha256'=>$hashes],JSON_THROW_ON_ERROR));echo count($captures)." actual account writer cases: $out\n";
