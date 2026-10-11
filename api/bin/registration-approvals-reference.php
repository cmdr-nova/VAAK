<?php
declare(strict_types=1);
/** Actual declarations on actual SQLite / PostgreSQL, with session and delivery boundaries explicit. */
$declarations = [];
function approval_load(string $file, string $name): void {
    global $declarations;
    $source = file_get_contents($file);
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) throw new RuntimeException('Missing declaration ' . $name);
    $tokens = token_get_all('<?php ' . substr($source, $start));
    $body = ''; $depth = 0; $opened = false;
    foreach (array_slice($tokens, 1) as $token) {
        $body .= is_array($token) ? $token[1] : $token;
        if (is_array($token) && in_array($token[0], [T_CURLY_OPEN,T_DOLLAR_OPEN_CURLY_BRACES], true)) $depth++;
        if ($token === '{') { $depth++; $opened = true; }
        if ($token === '}') $depth--;
        if ($opened && $depth === 0) break;
    }
    $declarations[$name] = hash('sha256', $body);
    eval($body);
}
function ap_db(): PDO { return $GLOBALS['approval_db']; }
function ap_db_now(): string { return gmdate('Y-m-d H:i:s'); }
function ap_auth_start_session(): void {}
function ap_auth_bootstrap(): void {}
function ap_auth_current_user(): ?array { return ap_auth_user_by_id((int) ($_SESSION['vaak_user_id'] ?? 0)); }
function ap_auth_login_user(array $user): void { $_SESSION['vaak_user_id'] = (int) $user['id']; }
function ap_actor_ensure_keypair(string $username): array { $GLOBALS['effects'][] = ['key', $username]; return !empty($GLOBALS['key_failure']) ? ['ok'=>false,'error'=>'fixture key creation failed'] : ['ok'=>true]; }
function ap_auth_operator_follow_new_user(string $username): void { $GLOBALS['effects'][] = ['follow', $username]; if (!empty($GLOBALS['throw_provision'])) throw new RuntimeException('fixture transport failed'); }
function ap_auth_notify_operator_registration(string $username,int $id,bool $approved = false): void { $GLOBALS['effects'][] = ['notice', $username]; }
const AP_AUTH_CSRF_KEY = '_vaak_csrf';
foreach (['ap_db_driver','ap_db_last_insert_id','ap_local_user_by_username'] as $fn) approval_load(dirname(__DIR__).'/ap-db.php',$fn);
foreach (['ap_auth_username_ok','ap_auth_csrf_ok','ap_auth_user_by_id','ap_auth_user_by_login','ap_auth_verify_credentials','ap_auth_invite_lookup','ap_auth_create_account_row','ap_auth_provision_registered_account','ap_auth_register'] as $fn) approval_load(dirname(__DIR__).'/ap-auth.php',$fn);
foreach (['ap_supporter_enabled','ap_supporter_schema','ap_supporter_email_hash','ap_supporter_issue','ap_supporter_code_hash','ap_supporter_available','ap_supporter_award','ap_supporter_invalidate','ap_supporter_redeem'] as $fn) approval_load(dirname(__DIR__).'/ap-supporter.php',$fn);
foreach (['ap_registration_approvals_enabled','ap_registration_approvals_schema','ap_registration_approvals_lock','ap_registration_submit','ap_registration_current_admin','ap_registration_applications_list','ap_registration_decide'] as $fn) approval_load(dirname(__DIR__).'/ap-registration-approvals.php',$fn);
function check(bool $ok,string $label): void { if (!$ok) throw new RuntimeException($label); $GLOBALS['checks'][]=$label; }
if (getenv('APPROVAL_REFERENCE_SETUP_ONLY') === '1') return;
$results=[];
foreach (['sqlite','pgsql-integer','pgsql-bigint'] as $driver) {
    $checks=[]; $effects=[];
    $sqlitePath=tempnam(sys_get_temp_dir(),'vaak-approval-sqlite-');
    $db=$driver==='sqlite'?new PDO('sqlite:'.$sqlitePath):new PDO('pgsql:host='. (getenv('LIBRARY_FIXTURE_SOCKET') ?: '/tmp/vaak-library-facts-pg-socket').' user=postgres dbname=postgres');
    $db->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE,PDO::FETCH_ASSOC);$GLOBALS['approval_db']=$db;
    $schema='approval_reference_'.getmypid().'_'.str_replace('-','_',$driver);
    if ($driver!=='sqlite') {$db->exec('CREATE SCHEMA '.$schema);$db->exec('SET search_path TO '.$schema);}
    try {
        $id=$driver==='sqlite'?'INTEGER PRIMARY KEY AUTOINCREMENT':($driver==='pgsql-integer'?'SERIAL PRIMARY KEY':'BIGSERIAL PRIMARY KEY');
        $db->exec("CREATE TABLE ap_users (id $id, username TEXT UNIQUE NOT NULL,email TEXT UNIQUE,password_hash TEXT NOT NULL,actor_key TEXT UNIQUE NOT NULL,actor_id TEXT UNIQUE NOT NULL,is_admin INTEGER NOT NULL DEFAULT 0,created_at TEXT NOT NULL,updated_at TEXT NOT NULL,disabled_at TEXT);
        CREATE TABLE actor_profile (actor_key TEXT PRIMARY KEY,name TEXT,summary TEXT,attachment_json TEXT,updated_at TEXT);
        CREATE TABLE ap_invite_codes (id $id,code TEXT UNIQUE,used_by BIGINT,used_at TEXT,expires_at TEXT)");
        $db->exec("INSERT INTO ap_users(username,password_hash,actor_key,actor_id,is_admin,created_at,updated_at) VALUES ('operator','unused','operator','https://local/users/operator',1,'now','now')");
        $_SESSION=['vaak_user_id'=>1,AP_AUTH_CSRF_KEY=>'fixture-csrf'];
        putenv('VAAK_REGISTRATION_APPROVALS');
        check(!ap_registration_submit('alice','long-password','alice@example.test','fixture-csrf')['ok'],'default closed');
        putenv('VAAK_REGISTRATION_APPROVALS=1');
        check(!ap_registration_submit('alice','long-password','alice@example.test','bad')['ok'],'bad submit CSRF');
        check(ap_registration_submit('alice','long-password','alice@example.test','fixture-csrf')['ok'],'submit');
        check((int)$db->query('SELECT count(*) FROM ap_users')->fetchColumn()===1,'pending no account');
        check((int)$db->query('SELECT count(*) FROM actor_profile')->fetchColumn()===0,'pending no public profile');
        check(ap_auth_user_by_id(2)===null,'pending no user capability');
        check(ap_local_user_by_username('alice')===null,'actual public actor/profile lookup denied');
        check(ap_auth_verify_credentials('alice','long-password')===null && ap_auth_verify_credentials('alice@example.test','long-password')===null,'actual pending credentials denied');
        $hash=$db->query('SELECT password_hash FROM ap_registration_applications')->fetchColumn();
        check(password_verify('long-password',$hash),'hash only');unset($hash);
        check(!ap_registration_submit('ALICE','long-password','other@example.test','fixture-csrf')['ok'],'duplicate username');
        check(!ap_registration_submit('other','long-password','ALICE@EXAMPLE.TEST','fixture-csrf')['ok'],'duplicate email');
        check(!$db->inTransaction(),'failed submit rolled back');
        check(!array_key_exists('password_hash',ap_registration_applications_list()[0]),'list excludes hash');
        $_SESSION['vaak_user_id']=0;check(ap_registration_applications_list()===[],'guest private list');check(!ap_registration_decide(1,'approved','fixture-csrf')['ok'],'guest denied');
        $_SESSION['vaak_user_id']=1;
        $db->exec('UPDATE ap_users SET is_admin=0 WHERE id=1');check(ap_registration_applications_list()===[],'revoked private list');check(!ap_registration_decide(1,'approved','fixture-csrf')['ok'],'revoked denied');
        $db->exec("UPDATE ap_users SET is_admin=1,disabled_at='now' WHERE id=1");check(!ap_registration_decide(1,'approved','fixture-csrf')['ok'],'disabled denied');
        $db->exec('UPDATE ap_users SET disabled_at=NULL WHERE id=1');
        check(!ap_registration_decide(1,'approved','bad')['ok'],'bad decision CSRF');
        check(ap_registration_decide(1,'approved','fixture-csrf')['ok'],'approve');
        check($_SESSION['vaak_user_id']===1,'admin session preserved');
        check((int)$db->query('SELECT count(*) FROM ap_users')->fetchColumn()===2,'exact account created');
        check((int)$db->query('SELECT count(*) FROM actor_profile')->fetchColumn()===1,'exact profile created');
        check($db->query('SELECT password_hash FROM ap_registration_applications WHERE id=1')->fetchColumn()===null,'decided hash erased');
        check(count($effects)===3,'same provisioning boundary calls');
        check(ap_registration_decide(1,'approved','fixture-csrf')['ok'] && count($effects)===3,'approval replay no repeated effects');
        check(!ap_registration_decide(1,'rejected','fixture-csrf')['ok'],'opposite replay denied');
        check(ap_registration_submit('rejectme','long-password','','fixture-csrf')['ok'],'second submit');
        $rejectId=(int)$db->query("SELECT id FROM ap_registration_applications WHERE username='rejectme'")->fetchColumn();
        check(ap_registration_decide($rejectId,'rejected','fixture-csrf')['ok'],'reject');
        check(ap_registration_decide($rejectId,'rejected','fixture-csrf')['ok'],'reject replay');
        check((int)$db->query('SELECT count(*) FROM ap_users')->fetchColumn()===2,'rejection creates no account');
        $db->exec("INSERT INTO ap_invite_codes(code) VALUES ('INVITE-TEST')");
        putenv('VAAK_REGISTRATION_APPROVALS');
        check(ap_auth_register('INVITE-TEST','invited','long-password','invited@example.test')['ok'],'invite unchanged with flag off');
        check($_SESSION['vaak_user_id']!==1,'invite still logs in');
        check((int)$db->query("SELECT count(*) FROM ap_invite_codes WHERE used_at IS NOT NULL")->fetchColumn()===1,'invite consumed once');
        check(count($effects)===6,'invite provisioning once');
        check(!ap_auth_register('INVITE-TEST','invitedtwo','long-password')['ok'] && count($effects)===6,'used invite no effects');
        $_SESSION['vaak_user_id']=1;putenv('VAAK_REGISTRATION_APPROVALS=1');
        check(ap_registration_submit('collision','long-password','collision@example.test','fixture-csrf')['ok'],'collision pending');
        $collisionId=(int)$db->query("SELECT id FROM ap_registration_applications WHERE username='collision'")->fetchColumn();
        $db->exec("INSERT INTO ap_invite_codes(code) VALUES ('SECOND-TEST')");
        check(ap_auth_register('SECOND-TEST','collision','long-password','collision@example.test')['ok'],'invite can win pending collision');
        $_SESSION['vaak_user_id']=1;
        check(!ap_registration_decide($collisionId,'approved','fixture-csrf')['ok'],'approval collision fails closed');
        check(!$db->inTransaction(),'approval collision rollback');
        check($db->query("SELECT status FROM ap_registration_applications WHERE id=$collisionId")->fetchColumn()==='pending','collision leaves pending');
        check(ap_registration_submit('effectfail','long-password','','fixture-csrf')['ok'],'provision failure submit');
        $effectId=(int)$db->query("SELECT id FROM ap_registration_applications WHERE username='effectfail'")->fetchColumn();
        $throw_provision=true;
        $res=ap_registration_decide($effectId,'approved','fixture-csrf');
        check(!empty($res['ok']) && !empty($res['provisioning_failed']),'committed provisioning failure reported');
        check(!$db->inTransaction() && $_SESSION['vaak_user_id']===1,'failure session and commit preserved');
        $effectCount=count($effects);$throw_provision=false;
        check(ap_registration_decide($effectId,'approved','fixture-csrf')['ok'] && count($effects)===$effectCount,'failed provisioning replay no repeat');
        $beforeApplications=(int)$db->query('SELECT count(*) FROM ap_registration_applications')->fetchColumn();
        $beforeUsers=(int)$db->query('SELECT count(*) FROM ap_users')->fetchColumn();
        $beforeEffects=count($effects);
        check(!ap_registration_submit('nulapp',"long\0password",'','fixture-csrf')['ok'],'actual NUL hasher failure returns application error');
        check((int)$db->query('SELECT count(*) FROM ap_registration_applications')->fetchColumn()===$beforeApplications && (int)$db->query('SELECT count(*) FROM ap_users')->fetchColumn()===$beforeUsers,'NUL application no writes');
        $db->exec("INSERT INTO ap_invite_codes(code) VALUES ('NUL-INVITE')");
        check(!ap_auth_register('NUL-INVITE','nulinvite',"long\0password")['ok'],'actual NUL hasher failure returns invite error');
        check($db->query("SELECT used_at FROM ap_invite_codes WHERE code='NUL-INVITE'")->fetchColumn()===null && (int)$db->query('SELECT count(*) FROM ap_users')->fetchColumn()===$beforeUsers && count($effects)===$beforeEffects,'NUL invite unconsumed no accounts or effects');
        check(ap_registration_submit('keyfail','long-password','','fixture-csrf')['ok'],'known key failure submit');
        $keyId=(int)$db->query("SELECT id FROM ap_registration_applications WHERE username='keyfail'")->fetchColumn();
        $key_failure=true;$beforeEffects=count($effects);
        // Execute the real Admin action branch and verify the approving operator sees attention.
        $adminSource=file_get_contents(dirname(__DIR__).'/ap-admin.php');
        $dispatchStart=strpos($adminSource,"    } elseif (\$action === 'registration_approve' || \$action === 'registration_reject') {");
        $dispatchEnd=strpos($adminSource,"    } elseif (\$action === 'invite_create') {",$dispatchStart);
        $dispatch=substr($adminSource,$dispatchStart,$dispatchEnd-$dispatchStart);
        $action='registration_approve';$csrfToken='fixture-csrf';$_POST=['application_id'=>$keyId];$notice=null;$error=null;
        eval('if(false){'.$dispatch.'}');
        check($notice==='Account approved; provisioning needs operator attention.' && $error===null,'actual dispatcher flags real-shaped key failure');
        check($_SESSION['vaak_user_id']===1 && !$db->inTransaction() && count($effects)===$beforeEffects+3 && array_column(array_slice($effects,-3),0)===['key','follow','notice'],'known key failure commits preserves session and effect order');
        $key_failure=false;$beforeEffects=count($effects);
        check(ap_registration_decide($keyId,'approved','fixture-csrf')['ok'] && count($effects)===$beforeEffects,'known key failure replay never reprovisions');
        $db->exec("INSERT INTO ap_invite_codes(code) VALUES ('KEYFAIL-INVITE')");
        $key_failure=true;$beforeEffects=count($effects);
        check(ap_auth_register('KEYFAIL-INVITE','keyinvite','long-password')['ok'] && count($effects)===$beforeEffects+3 && array_column(array_slice($effects,-3),0)===['key','follow','notice'],'invite retains best effort known key failure behavior');
        check($_SESSION['vaak_user_id']!==1,'known key failure invite retains login');
        $key_failure=false;$_SESSION['vaak_user_id']=1;
        $other=$driver==='sqlite'?new PDO('sqlite:'.$sqlitePath):new PDO('pgsql:host='.(getenv('LIBRARY_FIXTURE_SOCKET') ?: '/tmp/vaak-library-facts-pg-socket').' user=postgres dbname=postgres');
        $other->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
        if ($driver==='sqlite') $other->exec('PRAGMA busy_timeout=30');
        else {$other->exec('SET search_path TO '.$schema);$other->exec("SET lock_timeout='30ms'");}
        $db->beginTransaction();ap_registration_approvals_lock($db);
        $other->beginTransaction();$locked=false;
        try {ap_registration_approvals_lock($other);} catch (PDOException $e) {$locked=true;}
        check($locked,'independent connection blocked by collision lock');
        $other->rollBack();$db->rollBack();
        $other->beginTransaction();ap_registration_approvals_lock($other);$other->commit();
        check(!$db->inTransaction() && !$other->inTransaction(),'locks released after actual PDO rollback');
        $other=null;
        $results[$driver]=['checks'=>$checks,'count'=>count($checks)];
    } finally {if($db->inTransaction())$db->rollBack();if($driver!=='sqlite')$db->exec('DROP SCHEMA '.$schema.' CASCADE');$db=null;unlink($sqlitePath);}
}
$out=['boundary'=>'actual extracted declarations; real PDO SQL; simulated session bootstrap/login and provisioning transports. No HTTP/OAuth/full actor endpoint or concurrency proof.', 'declarations'=>$declarations,'results'=>$results];
file_put_contents($argv[1]??'/tmp/registration-approvals-reference.json',json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));
echo json_encode(['passed'=>array_sum(array_column($results,'count')),'drivers'=>array_keys($results)]).PHP_EOL;
