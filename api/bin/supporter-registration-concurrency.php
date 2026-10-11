<?php
/** Actual decision functions in separate processes with independent PDO connections. */
declare(strict_types=1);
putenv('APPROVAL_REFERENCE_SETUP_ONLY=1');require __DIR__.'/registration-approvals-reference.php';
putenv('VAAK_REGISTRATION_APPROVALS=1');putenv('VAAK_SUPPORTER_CODES=1');putenv('VAAK_SUPPORTER_CODE_SECRET='.str_repeat('fixture-secret-',4));
function race_connection(string $driver,string $sqlite,string $schema): PDO {
    $db=$driver==='sqlite'?new PDO('sqlite:'.$sqlite):new PDO('pgsql:host='.(getenv('LIBRARY_FIXTURE_SOCKET')?:'/tmp/vaak-library-facts-pg-socket').' user=postgres dbname=postgres');
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);
    if($driver==='sqlite')$db->exec('PRAGMA busy_timeout=2000');else $db->exec('SET search_path TO '.$schema);
    return $db;
}
$results=[];
foreach(['sqlite','pgsql-integer','pgsql-bigint'] as $driver){
 $sqlite=tempnam(sys_get_temp_dir(),'vaak-approval-race-');$schema='supporter_race_'.getmypid().'_'.str_replace('-','_',$driver);
 $db=$driver==='sqlite'?new PDO('sqlite:'.$sqlite):new PDO('pgsql:host='.(getenv('LIBRARY_FIXTURE_SOCKET')?:'/tmp/vaak-library-facts-pg-socket').' user=postgres dbname=postgres');
 $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$GLOBALS['approval_db']=$db;
 if($driver!=='sqlite'){$db->exec('CREATE SCHEMA '.$schema);$db->exec('SET search_path TO '.$schema);}
 $id=$driver==='sqlite'?'INTEGER PRIMARY KEY AUTOINCREMENT':($driver==='pgsql-integer'?'SERIAL PRIMARY KEY':'BIGSERIAL PRIMARY KEY');
 try{
  $db->exec("CREATE TABLE ap_users (id $id,username TEXT UNIQUE,email TEXT UNIQUE,password_hash TEXT,actor_key TEXT UNIQUE,actor_id TEXT UNIQUE,is_admin INTEGER DEFAULT 0,created_at TEXT,updated_at TEXT,disabled_at TEXT);
  CREATE TABLE actor_profile(actor_key TEXT PRIMARY KEY,name TEXT,summary TEXT,attachment_json TEXT,profile_badges TEXT DEFAULT '[]',updated_at TEXT);
  CREATE TABLE ap_invite_codes(id $id,code TEXT,used_by BIGINT,used_at TEXT,expires_at TEXT);
  INSERT INTO ap_users(username,password_hash,actor_key,actor_id,is_admin,created_at,updated_at) VALUES('operator','unused','operator','local',1,'now','now')");
  ap_registration_approvals_schema($db);ap_supporter_schema($db);
  foreach(['duplicate-submit','same-approve','opposite-decisions','existing-double-redeem'] as $case){
   $effects=[];$_SESSION=['vaak_user_id'=>1,AP_AUTH_CSRF_KEY=>'fixture-csrf'];
   $db->exec('DELETE FROM ap_registration_applications; DELETE FROM actor_profile; DELETE FROM ap_users WHERE id<>1; DELETE FROM ap_invite_codes; DELETE FROM ap_supporter_codes');
   $application=0;
   $email=$case==='existing-double-redeem'?'operator@example.test':'race@example.test';
   $db->exec("UPDATE ap_users SET email='operator@example.test' WHERE id=1");
   $code=ap_supporter_issue('cus_'.str_replace('-','',$case),'ch_paid',$email)['code'];
   if(!in_array($case,['duplicate-submit','existing-double-redeem'],true)){
    if(!ap_registration_submit('raceuser','long-password','race@example.test','fixture-csrf',$code)['ok'])throw new RuntimeException('setup submit');
    $application=(int)$db->query('SELECT id FROM ap_registration_applications')->fetchColumn();
   }

   $GLOBALS['approval_db']=null;$db=null;
   $children=[];$files=[];$start=microtime(true)+.15;
   for($slot=0;$slot<2;$slot++){
    $file=tempnam(sys_get_temp_dir(),'vaak-approval-race-result-');$files[]=$file;
    $pid=pcntl_fork();if($pid<0)throw new RuntimeException('fork failed');
    if($pid===0){
     try{
      $GLOBALS['approval_db']=race_connection($driver,$sqlite,$schema);$effects=[];
      $_SESSION=['vaak_user_id'=>1,AP_AUTH_CSRF_KEY=>'fixture-csrf'];
      $delay=$start-microtime(true);if($delay>0)usleep((int)($delay*1000000));
      if($case==='duplicate-submit')$r=ap_registration_submit('raceuser','long-password','race@example.test','fixture-csrf',$code);
      elseif($case==='existing-double-redeem')$r=ap_supporter_redeem($code,'fixture-csrf');
      else $r=ap_registration_decide($application,$case==='opposite-decisions'&&$slot===1?'rejected':'approved','fixture-csrf');
      // Never persist credential/user payloads returned by invite registration.
      file_put_contents($file,json_encode(['ok'=>!empty($r['ok']),'replay'=>!empty($r['already_decided']),'effects'=>count($effects),'admin_session'=>$_SESSION['vaak_user_id']===1]));exit(0);
     }catch(Throwable $e){file_put_contents($file,json_encode(['exception'=>get_class($e)]));exit(1);}
    }
    $children[]=$pid;
   }
   foreach($children as $pid){pcntl_waitpid($pid,$status);if(pcntl_wexitstatus($status)!==0)throw new RuntimeException('child failed');}
   $out=[];foreach($files as $file){$out[]=json_decode(file_get_contents($file),true);unlink($file);}
   $db=race_connection($driver,$sqlite,$schema);$GLOBALS['approval_db']=$db;
   $success=count(array_filter($out,fn($r)=>$r['ok']));$accounts=(int)$db->query('SELECT count(*) FROM ap_users WHERE id<>1')->fetchColumn();$effectCount=array_sum(array_column($out,'effects'));
   if($case==='duplicate-submit'){$passed=$success===1&&$accounts===0&&(int)$db->query('SELECT count(*) FROM ap_registration_applications')->fetchColumn()===1;}
   elseif($case==='same-approve'){$passed=$success===2&&$accounts===1&&$effectCount===3;}
   elseif($case==='opposite-decisions'){$passed=$success===1&&$accounts<=1&&$effectCount===$accounts*3;}
   else {$passed=$success===2&&$accounts===0&&$effectCount===0&&(int)$db->query('SELECT count(*) FROM ap_supporter_codes WHERE used_by=1')->fetchColumn()===1;}
   if(!$passed)throw new RuntimeException('race assertion '.$driver.' '.$case.' '.json_encode($out));
   foreach($out as $slot=>$r)if(!$r['admin_session'])throw new RuntimeException('admin session race');
   $results[$driver][$case]=['passed'=>true,'children'=>$out,'accounts'=>$accounts];
  }
 }finally{if($db&&$db->inTransaction())$db->rollBack();if($driver!=='sqlite')$db->exec('DROP SCHEMA '.$schema.' CASCADE');$GLOBALS['approval_db']=null;$db=null;unlink($sqlite);}
}
file_put_contents($argv[1]??'/tmp/supporter-registration-concurrency.json',json_encode(['declarations'=>$declarations,'boundary'=>'actual declarations and independent concurrent PDO connections; simulated session/provisioning transports','results'=>$results],JSON_PRETTY_PRINT));
echo json_encode(['passed'=>12,'concurrent_children'=>24]).PHP_EOL;
