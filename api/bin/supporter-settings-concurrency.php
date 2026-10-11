<?php
declare(strict_types=1);
/** Genuine simultaneous profile rebuild and award, independent PDO child processes. */
$fixture=(string)file_get_contents(__DIR__.'/supporter-settings-reference.php');$end=strpos($fixture,'$pgSchema=');eval(substr(substr($fixture,0,$end),5));
reference_load(dirname(__DIR__).'/ap-supporter.php',['ap_supporter_enabled','ap_supporter_schema','ap_supporter_email_hash','ap_supporter_issue','ap_supporter_available','ap_supporter_award','ap_supporter_invalidate']);
if(($argv[1]??'')==='child'){
 $schema=$argv[2];if(!preg_match('/^supporter_settings_race_[a-z0-9_]+$/',$schema))throw new RuntimeException('isolated schema');
 $GLOBALS['fixture_db']=new PDO('pgsql:host='.getenv('LIBRARY_FIXTURE_SOCKET').' dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$db=ap_db();$db->exec('SET search_path TO '.$schema);
 $GLOBALS['cache']=[];$GLOBALS['invalidated']=[];$GLOBALS['exports']=[];$GLOBALS['ranked']=[];$GLOBALS['hydrated']=[];
 $until=(float)$argv[4];$delay=$until-microtime(true);if($delay>0)usleep((int)($delay*1000000));
 if($argv[3]==='save'){$result=ap_profile_save(['name'=>'Alice','summary'=>'<p>Unicode é bio</p>','profile_badges'=>['cat','dog','coffee','tea','bookworm','gamer']],'alice');if(empty($result['ok']))throw new RuntimeException('save failed');}
 else{$hash=$db->query('SELECT code_hash FROM ap_supporter_codes')->fetchColumn();$db->beginTransaction();$db->exec('LOCK TABLE ap_users IN SHARE ROW EXCLUSIVE MODE');ap_supporter_award($db,$hash,201,'alice@example.test',ap_db_now());$db->commit();}
 echo json_encode(['ok'=>true]);exit;
}
$results=[];
foreach(['integer','bigint']as$width){
 $schema='supporter_settings_race_'.getmypid().'_'.$width;
 $db=new PDO('pgsql:host='.getenv('LIBRARY_FIXTURE_SOCKET').' dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);$GLOBALS['fixture_db']=$db;$db->exec('CREATE SCHEMA '.$schema);$db->exec('SET search_path TO '.$schema);
 try{
  $flags=['manually_approves','discoverable','indexable','collection_consent','vanity_verified','auto_follow_back','anti_ai_marker','auto_unblur_sensitive','auto_delete_posts_7d','automated','hide_profile_replies','hide_profile_boosts','algorithm_enabled','downranking_enabled','asks_enabled','webmentions_enabled'];
  $db->exec('CREATE TABLE actor_profile(actor_key TEXT PRIMARY KEY,name TEXT,summary TEXT,attachment_json TEXT,icon_url TEXT,image_url TEXT,reply_policy TEXT,quote_policy TEXT,forum_signature TEXT,profile_badges TEXT,updated_at TEXT,'.implode(',',array_map(fn($f)=>$f.' INTEGER NOT NULL DEFAULT 0',$flags)).')');
  $db->exec('CREATE TABLE ap_users(id '.strtoupper($width).',actor_key TEXT,disabled_at TEXT)');$db->exec("INSERT INTO ap_users VALUES(201,'alice',NULL);INSERT INTO actor_profile(actor_key,name,summary,attachment_json,profile_badges)VALUES('alice','Alice','<p>Unicode é bio</p>','[]','[]')");
  putenv('VAAK_SUPPORTER_CODES=1');putenv('VAAK_SUPPORTER_CODE_SECRET='.str_repeat('fixture-secret-',4));ap_supporter_issue('cus_race','ch_paid','alice@example.test');
  $children=[];$start=(string)(microtime(true)+.3);
  foreach(['save','award']as$action){$command=[__DIR__.'/php-policy-test','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so',__FILE__,'child',$schema,$action,$start];$pipes=[];$proc=proc_open($command,[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);fclose($pipes[0]);$children[]=[$proc,$pipes];}
  foreach($children as[$proc,$pipes]){$out=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($proc)!==0)throw new RuntimeException('child failed '.$error);if(empty(json_decode($out,true)['ok']))throw new RuntimeException('child receipt');}
  $GLOBALS['cache']=[];$GLOBALS['invalidated']=[];$GLOBALS['exports']=[];$GLOBALS['ranked']=[];$GLOBALS['hydrated']=[];
  $profile=ap_profile_get('alice');if($profile['profile_badges']!==['supporter','cat','dog','coffee','tea','bookworm','gamer'])throw new RuntimeException('concurrent badge/flair loss');
  $row=json_decode($db->query("SELECT profile_badges FROM actor_profile WHERE actor_key='alice'")->fetchColumn(),true);if(!in_array('supporter',$row,true))throw new RuntimeException('serialized persisted badge missing');
  $db->exec("UPDATE actor_profile SET profile_badges='[\"cat\",\"dog\",\"coffee\",\"tea\",\"bookworm\",\"gamer\"]' WHERE actor_key='alice'");$GLOBALS['cache']['vaak:profile:v1:'.hash('sha256','alice')]=array_replace($profile,['profile_badges'=>['cat','dog','coffee','tea','bookworm','gamer']]);
  if(ap_profile_get('alice')['profile_badges']!==$profile['profile_badges'])throw new RuntimeException('warmed omitted grant missing');
  $db->exec("UPDATE ap_users SET disabled_at='now' WHERE id=201");if(in_array('supporter',ap_profile_get('alice')['profile_badges'],true))throw new RuntimeException('disabled grant retained');
  $results[$width]=['passed'=>true,'independent_children'=>2,'persisted_and_cached_recovery'=>true,'disabled_withdrawal'=>true];
 }finally{if($db->inTransaction())$db->rollBack();$db->exec('SET search_path TO public');$db->exec('DROP SCHEMA '.$schema.' CASCADE');}
}
echo json_encode(['results'=>$results,'boundary'=>'Actual PHP profile save/read and award; independent PDO processes and real advisory lock, fixture cache/export transport.'],JSON_PRETTY_PRINT)."\n";
