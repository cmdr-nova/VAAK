<?php
declare(strict_types=1);
/** Actual PHP raw favourite query on isolated in-memory SQLite. */
function ap_db(): PDO { return $GLOBALS['favourite_sqlite']; }
function ap_db_default_owner_user_id(): int { return 1; }
$path=dirname(__DIR__).'/ap-db.php';$source=(string)file_get_contents($path);
if(!preg_match('/^function ap_masto_favourite_rows\(.*?^}/ms',$source,$match))throw new RuntimeException('actual favourite row declaration');
eval($match[0]);
$db=new PDO('sqlite::memory:');$db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$GLOBALS['favourite_sqlite']=$db;
$db->exec('CREATE TABLE masto_favourites(id INTEGER PRIMARY KEY,owner_user_id INTEGER,status_id TEXT,object_id TEXT,created_at TEXT)');
$insert=$db->prepare('INSERT INTO masto_favourites VALUES(?,?,?,?,?)');
for($n=0;$n<180;$n++)$insert->execute([$n+1,1,'source-'.$n,'https://remote.test/'.$n,gmdate('c',1791633600-$n)]);
$insert->execute([999,2,'foreign','https://remote.test/foreign','2027-01-01']);
$checks=[];
foreach([-1,1,2,40,80,100] as $limit){$rows=ap_masto_favourite_rows($limit,null,1);$expected=min(160,2*max(1,min(80,$limit)));if(count($rows)!==$expected||array_unique(array_column($rows,'owner_user_id'))!==[1])throw new RuntimeException('actual SQLite favourite clamp/owner');$checks[]=['limit'=>$limit,'rows'=>$rows];}
foreach(['source-19','missing',''] as $maxId){$rows=ap_masto_favourite_rows(40,$maxId,1);$expectedFirst=$maxId==='source-19'?'source-20':'source-0';if($rows[0]['status_id']!==$expectedFirst||count($rows)!==80)throw new RuntimeException('actual SQLite strict before');$checks[]=['max_id'=>$maxId,'rows'=>$rows];}
file_put_contents($argv[1],json_encode(['declaration_sha256'=>hash('sha256',$match[0]),'checks'=>count($checks),'captures'=>$checks,'scope'=>'Actual untouched raw PHP query; no hydration/native SQLite claim'],JSON_THROW_ON_ERROR));
echo 'Actual favourite SQLite raw query 9 controls'.PHP_EOL;
