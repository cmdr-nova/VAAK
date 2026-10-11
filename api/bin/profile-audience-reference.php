<?php
declare(strict_types=1);
/** Actual PHP audience helper, disposable PG only; duplicate-alias behavior is
 * recorded as a diagnostic, not a canonical ordering contract. */
require __DIR__.'/migration-reference-functions.php';
function ap_db(): PDO { return $GLOBALS['profile_audience_db']; }
reference_load(dirname(__DIR__).'/ap-db.php',['ap_db_owner_actor_id_for_user_id']);
reference_load(dirname(__DIR__).'/ap-visibility.php',['ap_visibility_status_audience_allowed']);
$dsn=getenv('PROFILE_AUDIENCE_DSN')?:'';
if (!str_contains($dsn,'/tmp/')) throw new RuntimeException('disposable socket required');
$db=new PDO($dsn,'postgres','',[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['profile_audience_db']=$db;$schema='profile_audience_php_'.getmypid();
$db->exec("CREATE SCHEMA $schema; SET search_path=$schema");
$captures=[];
try {
 $db->exec('CREATE TABLE ap_users(id BIGINT,actor_id TEXT,disabled_at TEXT);CREATE TABLE events(id BIGINT,object_id TEXT,visibility TEXT,actor_id TEXT,type TEXT,action_taken TEXT);CREATE TABLE outbox_notes(id TEXT,visibility TEXT,to_json TEXT,cc_json TEXT);CREATE TABLE mentions(owner_user_id BIGINT,object_id TEXT,deleted_at TEXT,activity_type TEXT);CREATE TABLE followers(owner_actor_id TEXT,actor_id TEXT);CREATE TABLE following(owner_actor_id TEXT,actor_id TEXT)');
 $author='https://mkultra.monster/users/fixture';$viewer='https://mkultra.monster/users/other';$uri=$author.'/notes/audience';
 $db->prepare('INSERT INTO ap_users VALUES(?,?,NULL),(?,?,NULL)')->execute([1,$author,2,$viewer]);
 foreach(['public','unlisted','private','direct_recipient','private_follower','direct_mention','missing','canonical_public_slash_private','canonical_private_slash_public'] as $case) {
  $db->exec('TRUNCATE outbox_notes,events,mentions,followers,following');
  $vis=in_array($case,['private','private_follower','canonical_private_slash_public'],true)?'private':(str_starts_with($case,'direct')?'direct':($case==='unlisted'?'unlisted':'public'));
  if($case!=='missing') $db->prepare('INSERT INTO outbox_notes VALUES(?,?,?,?)')->execute([$uri,$vis,$case==='direct_recipient'?json_encode([$viewer]):'[]','[]']);
  if(str_starts_with($case,'canonical_')) $db->prepare('INSERT INTO outbox_notes VALUES(?,?,?,?)')->execute([$uri.'/',$vis==='public'?'private':'public','[]','[]']);
  if($case==='private_follower') $db->prepare('INSERT INTO followers VALUES(?,?)')->execute([$author,$viewer]);
  if($case==='direct_mention') $db->prepare("INSERT INTO mentions VALUES(?,?,NULL,'Create')")->execute([2,$uri]);
  $source=['uri'=>$uri,'visibility'=>'public','account'=>['uri'=>$author]];
  $decisions=[];foreach([0,1,2] as $owner) $decisions[(string)$owner]=ap_visibility_status_audience_allowed($source,$owner);
  $captures[]=['case'=>$case,'source'=>$source,'outbox'=>$db->query('SELECT * FROM outbox_notes')->fetchAll(),'decisions'=>$decisions];
 }
} finally { $db->exec("SET search_path=public;DROP SCHEMA $schema CASCADE"); }
$out=['scope'=>'actual PHP current durable audience; canonical/slash conflicts diagnostic only; no full renderer or HTTP proof','function_sha256'=>hash('sha256',file_get_contents(dirname(__DIR__).'/ap-visibility.php')),'captures'=>$captures];
file_put_contents($argv[1]??'/tmp/vaak-profile-audience-current-reference.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR));
echo count($captures)," captures\n";
