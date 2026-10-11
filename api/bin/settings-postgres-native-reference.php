<?php
declare(strict_types=1);
/** Isolated genuine PHP profile writes versus the actual loopback Rust HTTP projection.
 * Redis is an in-memory transport recorder; cache invalidation is recorded.
 * No production bootstrap, credentials, filesystem exports, or federation IO.
 */
require __DIR__.'/migration-reference-functions.php';
$socket=getenv('LIBRARY_FIXTURE_SOCKET');
if (!is_string($socket)||!str_starts_with($socket,'/tmp/')) throw new RuntimeException('disposable /tmp PostgreSQL socket required');
$pg=new PDO('pgsql:host='.$socket.';dbname=postgres','postgres',null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC]);
$GLOBALS['fixture_db']=$pg;$GLOBALS['effects']=[];$GLOBALS['redis']=[];
function ap_db(): PDO { return $GLOBALS['fixture_db']; }
function ap_redis_json_get(string $key): ?array { return $GLOBALS['redis'][$key]??null; }
function ap_redis_json_set(string $key,array $value,int $ttl): bool { $GLOBALS['redis'][$key]=$value;return true; }
function ap_redis_delete(string ...$keys): void { foreach($keys as $key){unset($GLOBALS['redis'][$key]);$GLOBALS['effects'][]=['delete',$key];} }
function ap_timeline_cache_invalidate_owner(int $owner): void { $GLOBALS['effects'][]=['ranked',$owner]; }
function ap_timeline_home_hydrate_invalidate_owner(int $owner): void { $GLOBALS['effects'][]=['hydrate',$owner]; }
$declarations=['ap_profile_save','ap_profile_save_unlocked','ap_profile_get','ap_profile_defaults','ap_fix_utf8','ap_profile_plain_bio_to_html','ap_profile_normalize_summary_html','ap_profile_sanitize_https_url','ap_profile_verify_attachments','ap_profile_identity_urls','ap_profile_actor_url','ap_profile_coerce_https_url','ap_profile_normalize_verify_url','ap_profile_normalize_badges','ap_profile_badge_catalog','ap_db_now','ap_profile_export_public_cache'];
reference_load(dirname(__DIR__).'/ap-db.php',$declarations);
$flags=['manually_approves','discoverable','indexable','collection_consent','vanity_verified','auto_follow_back','anti_ai_marker','auto_unblur_sensitive','auto_delete_posts_7d','automated','hide_profile_replies','hide_profile_boosts','algorithm_enabled','downranking_enabled','asks_enabled','webmentions_enabled'];
$canonical=json_decode((string)file_get_contents(dirname(__DIR__,2).'/rust/vaak-worker/fixtures/settings/profile-fields.json'),true,512,JSON_THROW_ON_ERROR)['fields'];
$rustSource=(string)file_get_contents(dirname(__DIR__,2).'/rust/vaak-worker/src/settings.rs');
if(!preg_match('/pub const CANONICAL_FIELDS:.*?= &\[(.*?)\];/s',$rustSource,$contract))throw new RuntimeException('native canonical contract missing');
preg_match_all('/"([a-z_0-9]+)"/',$contract[1],$fieldNames);
if($fieldNames[1]!==$canonical)throw new RuntimeException('fixture canonical contract drift');
if (($argv[1]??'')==='--child') {
    $schema=(string)($argv[2]??'');if(!preg_match('/^settings_native_[a-z0-9_]+$/',$schema))throw new RuntimeException('invalid child schema');
    $pg->exec('SET search_path='.$schema);
    $pg->exec("SET application_name='settings_native_child'");
    usleep(100000);
    $result=ap_profile_save(['name'=>'Alice Ω','summary'=>'<p>Canonical bio</p>',(string)$argv[3]=>false],'settings_alice');
    echo json_encode(['result'=>$result,'effects'=>$GLOBALS['effects']]);
    exit(!empty($result['ok'])?0:1);
}
$worker=getenv('VAAK_SETTINGS_FIXTURE_WORKER')?:dirname(__DIR__,2).'/rust/target/debug/vaak-worker';
if(!is_file($worker)||!is_executable($worker))throw new RuntimeException('fresh native worker required');
$checks=0;$captures=[];
function check_settings(bool $ok,string $label): void { if(!$ok)throw new RuntimeException($label);$GLOBALS['checks']++; }
function native_settings(string $base,string $query,string $method='GET'): array {
    $context=stream_context_create(['http'=>['ignore_errors'=>true,'timeout'=>5,'method'=>$method]]);
    $raw=file_get_contents($base.'/shadow/settings'.$query,false,$context);
    preg_match('/\s(\d{3})\s/',$http_response_header[0]??'',$status);
    $body=json_decode((string)$raw,true);
    if((int)($status[1]??0)===200&&!is_array($body))throw new RuntimeException('successful native response is not JSON');
    return ['status'=>(int)($status[1]??0),'body'=>$body,'raw'=>(string)$raw];
}
function capture_settings(string $case,string $base,int $owner=201): array {
    $reply=native_settings($base,'?owner_id='.$owner);
    $row=ap_db()->query("SELECT * FROM actor_profile WHERE actor_key='settings_alice'")->fetch();
    $GLOBALS['captures'][]=['width'=>$GLOBALS['width'],'case'=>$case,'current_row'=>$row,'php'=>ap_profile_get('settings_alice'),'http'=>$reply,'effects'=>$GLOBALS['effects']];
    check_settings($reply['status']===200,$case.' HTTP status '.json_encode($reply));
    check_settings(($reply['body']['mutation_owner']??null)==='php'&&($reply['body']['mutation_enabled']??null)===false,$case.' writes remain PHP owned');
    $actual=$reply['body']['fields'];$php=ap_profile_get('settings_alice');
    $keys=array_keys($actual);$expected=$GLOBALS['canonical'];sort($keys);sort($expected);check_settings($keys===$expected,$case.' exact canonical field allowlist');
    check_settings(!str_contains($reply['raw'],'PRIVATE_PROFILE_SENTINEL'),$case.' excludes unknown profile SQL column');
    foreach($GLOBALS['flags'] as $flag)check_settings(($actual[$flag]??null)===$php[$flag],$case.' current flag '.$flag);
    foreach(['name','summary','icon_url','image_url','reply_policy','quote_policy','forum_signature','updated_at'] as $field)check_settings(($actual[$field]??null)===$php[$field],$case.' persisted field '.$field);
    check_settings(json_decode($actual['attachment_json'],true)===$php['attachment'],$case.' attachment');
    check_settings(json_decode($actual['profile_badges'],true)===$php['profile_badges'],$case.' badges');
    foreach(['email','password_hash','xai_api_key_enc','totp_secret','session_token','recovery_codes_json'] as $secret)check_settings(!array_key_exists($secret,$actual),$case.' excludes '.$secret);
    return $actual;
}
foreach(['INTEGER','BIGINT'] as $width){
    $GLOBALS['width']=$width;$schema='settings_native_'.strtolower($width).'_'.getmypid();$pg->exec('CREATE SCHEMA '.$schema.';SET search_path='.$schema);$proc=null;
    try{
        // pgloader uses BIGINT for user IDs; preference widths can be mixed during migration.
        $pg->exec('CREATE TABLE ap_users(id BIGINT PRIMARY KEY,username TEXT,email TEXT,password_hash TEXT,actor_key TEXT UNIQUE,actor_id TEXT,is_admin INTEGER,created_at TEXT,updated_at TEXT,disabled_at TEXT,xai_api_key_enc TEXT)');
        $defaultOn=['discoverable','indexable','collection_consent','algorithm_enabled','downranking_enabled','asks_enabled','webmentions_enabled'];
        $pg->exec('CREATE TABLE actor_profile(actor_key TEXT PRIMARY KEY,name TEXT NOT NULL,summary TEXT NOT NULL,attachment_json TEXT NOT NULL DEFAULT \'[]\',icon_url TEXT,image_url TEXT,reply_policy TEXT NOT NULL DEFAULT \'anyone\',quote_policy TEXT NOT NULL DEFAULT \'anyone\',forum_signature TEXT NOT NULL DEFAULT \'\',profile_badges TEXT NOT NULL DEFAULT \'[]\',updated_at TEXT NOT NULL,'.implode(',',array_map(fn($flag)=>$flag.' '.$width.' NOT NULL DEFAULT '.(in_array($flag,$defaultOn,true)?'1':'0'),$flags)).')');
        // A declared test-only extra column catches accidental SELECT */row export.
        $pg->exec("ALTER TABLE actor_profile ADD COLUMN fixture_private_export_marker TEXT NOT NULL DEFAULT 'PRIVATE_PROFILE_SENTINEL'");
        $GLOBALS['captured_schema'][$width]=$pg->query("SELECT table_name,column_name,data_type,is_nullable,column_default FROM information_schema.columns WHERE table_schema=current_schema() ORDER BY table_name,ordinal_position")->fetchAll();
        foreach([201=>'settings_alice',202=>'settings_bob',203=>'settings_missing_profile'] as $owner=>$key)$pg->prepare('INSERT INTO ap_users(id,username,actor_key,actor_id,email,password_hash,xai_api_key_enc) VALUES(?,?,?,?,?,?,?)')->execute([$owner,$key,$key,'https://fixture.test/users/'.$key,'private-email-'.$owner,'private-hash-'.$owner,'private-api-'.$owner]);
        foreach(['settings_alice'=>'Alice Ω','settings_bob'=>'Bob'] as $key=>$name)$pg->prepare('INSERT INTO actor_profile(actor_key,name,summary,attachment_json,profile_badges,forum_signature,updated_at) VALUES(?,?,?,?,?,?,?)')->execute([$key,$name,'<p>Canonical bio</p>','[]','[]','Keep signature','2026-10-11T00:00:00Z']);
        // Explicit input state (rather than changing production defaults).
        $pg->exec('UPDATE actor_profile SET '.implode(',',array_map(fn($flag)=>$flag.'=0',$flags)));
        $GLOBALS['redis']=[];$GLOBALS['effects']=[];
        $port=18920+($width==='INTEGER'?0:1);$base='http://127.0.0.1:'.$port;
        $env=['PATH'=>getenv('PATH'),'LD_LIBRARY_PATH'=>'/usr/lib/rustlib/x86_64-unknown-linux-gnu/lib','VAAK_ENV_FILE'=>'/tmp/vaak-no-settings-env','VAAK_PHYRIAN_BRIDGE_ENV_FILE'=>'/tmp/vaak-no-settings-bridge-env','VAAK_DATABASE_URL'=>'host='.$socket.' user=postgres dbname=postgres options=\'-csearch_path='.$schema.'\'','VAAK_SHADOW_OWNER_ID'=>'201','VAAK_REDIS_URL'=>'redis://127.0.0.1:1/0','VAAK_REDIS_QUEUE_URL'=>'redis://127.0.0.1:1/1','VAAK_BSKY_PUBLIC_API'=>'http://127.0.0.1:1','VAAK_NOTIF_CACHE_DIR'=>'/tmp/vaak-settings-unused','VAAK_LIBRARY_NATIVE_RENDER'=>'0','VAAK_SEARCH_NATIVE_RENDER'=>'0'];
        $pipes=[];$proc=proc_open([$worker,'serve','--bind','127.0.0.1:'.$port],[['pipe','r'],['file','/tmp/vaak-settings-worker-'.strtolower($width).'.log','a'],['file','/tmp/vaak-settings-worker-'.strtolower($width).'.log','a']],$pipes,null,$env);if(!is_resource($proc))throw new RuntimeException('worker start');fclose($pipes[0]);
        for($attempt=0;$attempt<50;$attempt++){usleep(100000);$conn=@fsockopen('127.0.0.1',$port,$errno,$error,.1);if($conn){fclose($conn);break;}}
        $rejectedPipes=[];$rejected=proc_open([$worker,'serve','--bind','0.0.0.0:'.$port],[['pipe','r'],['pipe','w'],['pipe','w']],$rejectedPipes,null,$env);
        fclose($rejectedPipes[0]);$rejectedOut=stream_get_contents($rejectedPipes[1]);$rejectedErr=stream_get_contents($rejectedPipes[2]);fclose($rejectedPipes[1]);fclose($rejectedPipes[2]);
        check_settings(proc_close($rejected)!==0&&str_contains($rejectedErr.$rejectedOut,'refuse non-loopback bind'),'native service refuses public bind');
        $before=capture_settings('initial',$base);$bob=native_settings($base,'?owner_id=202')['body'];
        foreach($flags as $flag){
            $result=ap_profile_save(['name'=>'Alice Ω','summary'=>'<p>Canonical bio</p>',$flag=>true],'settings_alice');check_settings(!empty($result['ok']),'enable '.$flag);
            $actual=capture_settings('enable_'.$flag,$base);check_settings($actual[$flag]===true,'enabled '.$flag);
            foreach($flags as $other)if($other!==$flag)check_settings($actual[$other]===$before[$other],'omitted '.$other.' while changing '.$flag);
            check_settings(native_settings($base,'?owner_id=202')['body']===$bob,'second owner isolated '.$flag);
            $result=ap_profile_save(['name'=>'Alice Ω','summary'=>'<p>Canonical bio</p>',$flag=>false],'settings_alice');check_settings(!empty($result['ok']),'disable '.$flag);
            $before=capture_settings('disable_'.$flag,$base);
        }
        foreach(['anyone','followers','nobody'] as $policy){check_settings(!empty(ap_profile_save(['name'=>'Alice Ω','summary'=>'<p>Canonical bio</p>','reply_policy'=>$policy,'quote_policy'=>$policy],'settings_alice')['ok']),'policy save '.$policy);$current=capture_settings('policy_'.$policy,$base);check_settings($current['reply_policy']===$policy&&$current['quote_policy']===$policy,'policy '.$policy);}
        $ranked=array_values(array_map(fn($effect)=>$effect[1],array_filter($GLOBALS['effects'],fn($effect)=>$effect[0]==='ranked')));
        $hydrate=array_values(array_map(fn($effect)=>$effect[1],array_filter($GLOBALS['effects'],fn($effect)=>$effect[0]==='hydrate')));
        check_settings($ranked===[201,201]&&$hydrate===[201,201],'algorithm transitions invalidate exactly current owner');
        check_settings(!empty(ap_profile_save(['name'=>'Alice Ω','summary'=>'<p>Canonical bio</p>','forum_signature'=>'Unicode Ω signature','attachment'=>[['name'=>'Pronouns','value'=>'she/her Ω']],'profile_badges'=>['transgender','gamer','not-a-badge'],'icon_url'=>'https://fixture.test/avatar.webp','image_url'=>'https://fixture.test/header.webp'],'settings_alice')['ok']),'other profile fields save');
        capture_settings('other_profile_fields',$base);
        $fields=['name'=>'Alice Ω','summary'=>'<p>Canonical bio</p>','manually_approves'=>true,'discoverable'=>false,'indexable'=>false,'collection_consent'=>false,'algorithm_enabled'=>true,'asks_enabled'=>true];
        check_settings(!empty(ap_profile_save($fields,'settings_alice')['ok']),'privacy baseline');
        $prior=capture_settings('privacy_baseline',$base);
        // Both writers must visibly queue on the genuine per-actor PostgreSQL
        // advisory lock before we release them; a scheduler-only race is insufficient.
        $pg->query("SELECT pg_advisory_lock(hashtext('vaak:profile-save:settings_alice'))");
        $children=[];
        foreach(['algorithm_enabled','asks_enabled'] as $field){$p=[];$cmd=[PHP_BINARY,'-d','extension=/tmp/vaak-php-mbstring-test/usr/lib/php/20230831/mbstring.so','-d','extension=/tmp/vaak-php-pgsql-test/extracted/usr/lib/php/20230831/pdo_pgsql.so',__FILE__,'--child',$schema,$field];$child=proc_open($cmd,[['pipe','r'],['pipe','w'],['pipe','w']],$p,null,['LIBRARY_FIXTURE_SOCKET'=>$socket]);$children[]=[$child,$p];}
        $waiting=0;for($attempt=0;$attempt<40;$attempt++){usleep(50000);$waiting=(int)$pg->query("SELECT count(*) FROM pg_stat_activity WHERE application_name='settings_native_child' AND wait_event='advisory'")->fetchColumn();if($waiting===2)break;}
        $pg->query("SELECT pg_advisory_unlock(hashtext('vaak:profile-save:settings_alice'))");check_settings($waiting===2,'both independent writers queued at actual advisory lock');
        $GLOBALS['observed_lock_waiters'][$width]=$waiting;
        foreach($children as [$child,$p]){fclose($p[0]);$out=stream_get_contents($p[1]);$err=stream_get_contents($p[2]);fclose($p[1]);fclose($p[2]);check_settings(proc_close($child)===0,'concurrent save '.$out.$err);$GLOBALS['effects'][]=['child',json_decode($out,true,512,JSON_THROW_ON_ERROR)];}
        // Parent read models a new request after the independent writer processes.
        $GLOBALS['redis']=[];$concurrent=capture_settings('concurrent_partial_saves',$base);
        check_settings(!$concurrent['algorithm_enabled']&&!$concurrent['asks_enabled'],'concurrent writes retained');
        foreach(['manually_approves','discoverable','indexable','collection_consent','reply_policy','quote_policy'] as $f)check_settings($concurrent[$f]===$prior[$f],'concurrency preserves privacy '.$f);
        $pg->beginTransaction();ap_profile_save(['name'=>'Rollback','summary'=>'<p>Changed</p>','manually_approves'=>false],'settings_alice');
        check_settings(native_settings($base,'?owner_id=201')['body']['fields']===$concurrent,'uncommitted PHP save invisible to native');$pg->rollBack();$GLOBALS['redis']=[];check_settings(capture_settings('rollback',$base)===$concurrent,'rollback exact');
        $invalid=ap_profile_save(['name'=>'','summary'=>'<p>Canonical bio</p>','indexable'=>true],'settings_alice');check_settings(empty($invalid['ok']),'invalid name rejected');check_settings(capture_settings('invalid_save',$base)===$concurrent,'invalid save did not mutate');
        check_settings(native_settings($base,'?owner_id=201','POST')['status']===405,'Settings HTTP mutation unavailable');check_settings(native_settings($base,'?owner_id=201')['body']['fields']===$concurrent,'rejected HTTP mutation changed nothing');
        foreach([203,999999] as $owner)check_settings(native_settings($base,'?owner_id='.$owner)['status']===500,'missing owner/profile denies '.$owner);
        $pg->exec("UPDATE ap_users SET disabled_at='2026-10-11' WHERE id=201");check_settings(native_settings($base,'?owner_id=201')['status']===500,'disabled owner denied');$pg->exec('UPDATE ap_users SET disabled_at=NULL WHERE id=201');
        $pg->exec("UPDATE actor_profile SET actor_key='settings_current' WHERE actor_key='settings_alice';UPDATE ap_users SET actor_key='settings_current',actor_id='https://fixture.test/users/settings_current' WHERE id=201");
        $identity=native_settings($base,'?owner_id=201');check_settings($identity['status']===200&&$identity['body']['actor_key']==='settings_current'&&$identity['body']['fields']===$concurrent,'current database identity used');
        foreach(['','?owner_id=0','?owner_id=-7'] as $query)check_settings(native_settings($base,$query)['body']['actor_key']==='settings_current','documented internal default owner '.$query);
        check_settings(native_settings($base,'?owner_id=not-an-integer')['status']===400,'malformed query rejected');
    }finally{if(is_resource($proc)){proc_terminate($proc);proc_close($proc);}$pg->exec('SET search_path=public;DROP SCHEMA '.$schema.' CASCADE');}
}
$helperHashes=[];$phpSource=(string)file_get_contents(dirname(__DIR__).'/ap-db.php');
foreach($declarations as $declaration){if(!preg_match('/^function '.preg_quote($declaration,'/').'\([^\n]*\).*?^\}/ms',$phpSource,$body))throw new RuntimeException('helper hash extraction '.$declaration);$helperHashes[$declaration]=hash('sha256',$body[0]);}
file_put_contents('/tmp/vaak-settings-postgres-native-reference.json',json_encode(['checks'=>$checks,'captures'=>$captures,'canonical_fields'=>$canonical,'schema_columns'=>$GLOBALS['captured_schema'],'production_helper_sha256'=>$helperHashes,'observed_advisory_lock_waiters'=>$GLOBALS['observed_lock_waiters'],'production_declarations'=>$declarations,'transport_recorders'=>['ap_db'=>'disposable PostgreSQL connection','ap_redis_json_get/ap_redis_json_set/ap_redis_delete'=>'in-memory Redis transport','ap_timeline_cache_invalidate_owner/ap_timeline_home_hydrate_invalidate_owner'=>'cache invalidation transport records'],'ap_db_sha256'=>hash_file('sha256',dirname(__DIR__).'/ap-db.php'),'native_settings_sha256'=>hash_file('sha256',dirname(__DIR__,2).'/rust/vaak-worker/src/settings.rs'),'native_binary_sha256'=>hash_file('sha256',$worker)],JSON_PRETTY_PRINT|JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES)."\n");
echo 'settings-postgres-native-reference: PASS '.$checks.' checks / '.count($captures)." captures\n";
