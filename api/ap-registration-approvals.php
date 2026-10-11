<?php
/** Staged, default-off registration applications. Pending rows are not accounts. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403);
    header('Cache-Control: no-store');
    exit('Library only');
}
require_once __DIR__ . '/ap-auth.php';
require_once __DIR__ . '/ap-supporter.php';

function ap_registration_approvals_enabled(): bool
{
    return getenv('VAAK_REGISTRATION_APPROVALS') === '1';
}

function ap_registration_approvals_schema(PDO $db): void
{
    $id = ap_db_driver($db) === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db->exec("CREATE TABLE IF NOT EXISTS ap_registration_applications (
        id $id, username TEXT NOT NULL, email TEXT, password_hash TEXT,
        status TEXT NOT NULL DEFAULT 'pending' CHECK (status IN ('pending','approved','rejected')),
        created_at TEXT NOT NULL, decided_at TEXT, decided_by BIGINT, user_id BIGINT, supporter_hash TEXT
    )");
    $has = ap_db_driver($db)==='pgsql'
        ? $db->query("SELECT 1 FROM information_schema.columns WHERE table_schema=current_schema() AND table_name='ap_registration_applications' AND column_name='supporter_hash'")->fetchColumn()
        : in_array('supporter_hash',array_column($db->query('PRAGMA table_info(ap_registration_applications)')->fetchAll(PDO::FETCH_ASSOC),'name'),true);
    if (!$has) $db->exec('ALTER TABLE ap_registration_applications ADD COLUMN supporter_hash TEXT');
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_registration_pending_supporter ON ap_registration_applications(supporter_hash) WHERE status='pending' AND supporter_hash IS NOT NULL");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_registration_pending_username ON ap_registration_applications(lower(username)) WHERE status = 'pending'");
    $db->exec("CREATE UNIQUE INDEX IF NOT EXISTS idx_registration_pending_email ON ap_registration_applications(lower(email)) WHERE status = 'pending' AND email IS NOT NULL");
}

/** Serialize collisions with ordinary invite account creation on both drivers. */
function ap_registration_approvals_lock(PDO $db): void
{
    if (ap_db_driver($db) === 'pgsql') {
        $db->exec('LOCK TABLE ap_users, ap_registration_applications IN SHARE ROW EXCLUSIVE MODE');
    } else {
        // A write after PDO beginTransaction preserves PDO's commit/rollback tracking.
        $db->exec('UPDATE ap_users SET updated_at = updated_at WHERE id = -1');
    }
}

/** Bounded public admission before password hashing; isolated from login limits. */
function ap_registration_rate_admit(): bool
{
    $ip=(string)($_SERVER['REMOTE_ADDR']??'0.0.0.0');
    $count=function_exists('ap_redis_rate_count') ? ap_redis_rate_count('registration:'.$ip,900) : null;
    if ($count!==null) return $count<=5;
    $dir=sys_get_temp_dir().'/ap-vaak-registration-rate';
    if (!is_dir($dir) && !@mkdir($dir,0700,true) && !is_dir($dir)) return false;
    $fp=@fopen($dir.'/'.hash('sha256',$ip).'.json','c+');
    if (!$fp || !flock($fp,LOCK_EX)) { if($fp)fclose($fp);return false; }
    try {
        $row=json_decode(stream_get_contents($fp),true);$now=time();
        if (!is_array($row)||$now-(int)($row['first']??0)>=900) $row=['first'=>$now,'count'=>0];
        $row['count']=(int)($row['count']??0)+1;rewind($fp);ftruncate($fp,0);fwrite($fp,json_encode($row));fflush($fp);
        return $row['count']<=5;
    } finally {flock($fp,LOCK_UN);fclose($fp);}
}

function ap_registration_submit(string $username, string $password, string $email, string $csrf, string $supporterCode = ''): array
{
    if (!ap_registration_approvals_enabled()) return ['ok' => false, 'error' => 'Registration applications are closed.'];
    if (!ap_auth_csrf_ok($csrf)) return ['ok' => false, 'error' => 'Session expired — refresh and try again.'];
    $username = strtolower(trim($username));
    $password = trim($password);
    $email = trim($email);
    if (!ap_auth_username_ok($username) || strlen($password) < 10 || strlen($password) > 4096
        || ($email !== '' && (strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)))) {
        return ['ok' => false, 'error' => 'Use a valid username, email and password of at least 10 characters.'];
    }
    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not hash password. Use a password without unsupported bytes.'];
    }
    if (!is_string($hash)) return ['ok' => false, 'error' => 'Could not submit application.'];
    $db = ap_db();
    try {
        ap_registration_approvals_schema($db);
        if (ap_supporter_enabled()) ap_supporter_schema($db);
        $db->beginTransaction();
        ap_registration_approvals_lock($db);
        $supporterHash=null;
        if (trim($supporterCode)!=='') {
            if (!ap_supporter_enabled()) throw new RuntimeException('Rewards closed');
            if (ap_db_driver($db)==='pgsql') $db->exec('LOCK TABLE ap_supporter_codes IN SHARE ROW EXCLUSIVE MODE');
            $supporterHash=ap_supporter_code_hash($supporterCode);
            if (!ap_supporter_available($db,$supporterHash,$email)) throw new RuntimeException('Invalid reward');
        }
        $st = $db->prepare("SELECT 1 FROM ap_users WHERE lower(username) = lower(?) OR (? <> '' AND lower(email) = lower(?))");
        $st->execute([$username, $email, $email]);
        if ($st->fetchColumn()) throw new RuntimeException('Collision');
        $st = $db->prepare("INSERT INTO ap_registration_applications (username,email,password_hash,created_at,supporter_hash) VALUES (?,?,?,?,?)");
        $st->execute([$username, $email !== '' ? $email : null, $hash, ap_db_now(), $supporterHash]);
        $db->commit();
        return ['ok' => true, 'pending' => true];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        // Do not leak account/application existence or applicant credentials.
        return ['ok' => false, 'error' => 'Could not submit application. Check your details or try again later.'];
    }
}

/** Current session identity is revalidated from the database, never caller flags. */
function ap_registration_current_admin(PDO $db, bool $lock = false): ?array
{
    $session = ap_auth_current_user();
    $id = (int) ($session['id'] ?? 0);
    if ($id < 1) return null;
    $sql = 'SELECT id FROM ap_users WHERE id = ? AND is_admin = 1 AND disabled_at IS NULL';
    if ($lock && ap_db_driver($db) === 'pgsql') $sql .= ' FOR UPDATE';
    $st = $db->prepare($sql);
    $st->execute([$id]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    return is_array($row) ? $row : null;
}

function ap_registration_applications_list(int $offset = 0): array
{
    $db = ap_db();
    if (!ap_registration_current_admin($db)) return [];
    ap_registration_approvals_schema($db);
    // Hashes are never included in read page models.
    $offset = max(0, $offset);
    $rows = $db->query("SELECT id,username,email,status,created_at,decided_at FROM ap_registration_applications ORDER BY id DESC LIMIT 100 OFFSET " . $offset)->fetchAll(PDO::FETCH_ASSOC);
    return ap_registration_current_admin($db) ? $rows : [];
}

function ap_registration_decide(int $id, string $decision, string $csrf): array
{
    if (!ap_registration_approvals_enabled()) return ['ok' => false, 'error' => 'Registration applications are closed.'];
    if (!ap_auth_csrf_ok($csrf)) return ['ok' => false, 'error' => 'Session expired — refresh and try again.'];
    if ($id < 1 || !in_array($decision, ['approved', 'rejected'], true)) return ['ok' => false, 'error' => 'Invalid decision.'];
    $db = ap_db();
    $created = null;
    try {
        if (!ap_registration_current_admin($db)) return ['ok' => false, 'error' => 'Admin only.'];
        ap_registration_approvals_schema($db);
        $db->beginTransaction();
        ap_registration_approvals_lock($db);
        $admin = ap_registration_current_admin($db, true);
        if (!$admin) throw new RuntimeException('Admin only');
        $st = $db->prepare('SELECT * FROM ap_registration_applications WHERE id = ?');
        $st->execute([$id]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row)) throw new RuntimeException('Missing application');
        if ($row['status'] !== 'pending') {
            $db->commit();
            return ['ok' => $row['status'] === $decision, 'already_decided' => true];
        }
        $now = ap_db_now();
        $uid = null;
        if ($decision === 'approved') {
            $uid = ap_auth_create_account_row($db, $row['username'], (string) ($row['email'] ?? ''), (string) $row['password_hash'], $now);
            if (!empty($row['supporter_hash'])) {
                if (!ap_supporter_enabled()) throw new RuntimeException('Rewards closed');
                if (ap_db_driver($db)==='pgsql') $db->exec('LOCK TABLE ap_supporter_codes IN SHARE ROW EXCLUSIVE MODE');
                ap_supporter_award($db,$row['supporter_hash'],$uid,(string)($row['email']??''),$now);
            }
            $created = [$row['username'], $uid];
        }
        $st = $db->prepare("UPDATE ap_registration_applications SET status=?,password_hash=NULL,supporter_hash=NULL,decided_at=?,decided_by=?,user_id=? WHERE id=? AND status='pending'");
        $st->execute([$decision,$now,(int) $admin['id'],$uid,$id]);
        if ($st->rowCount() !== 1) throw new RuntimeException('Decision race');
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        return ['ok' => false, 'error' => 'Could not decide this application. Check permissions and account collisions.'];
    }
    // Session is deliberately untouched. Replays never repeat provisioning effects.
    if ($created !== null) {
        if (!empty($row['supporter_hash'])) ap_supporter_invalidate($db,$created[1]);
        try {
            $provision = ap_auth_provision_registered_account($created[0], $created[1], true);
            if (empty($provision['ok'])) return ['ok' => true, 'provisioning_failed' => true];
        } catch (Throwable $e) {
            // The account is already committed. No credentials or private details in logs.
            error_log('[ap-approvals] Account approved; best-effort provisioning failed.');
            return ['ok' => true, 'provisioning_failed' => true];
        }
    }
    return ['ok' => true];
}
