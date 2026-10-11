<?php
/** Paid-only local Supporter rewards. Issuance is a trusted in-process Clearance boundary. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli' && realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    http_response_code(403); header('Cache-Control: no-store'); exit('Library only');
}
function ap_supporter_enabled(): bool { return getenv('VAAK_SUPPORTER_CODES') === '1'; }
function ap_supporter_schema(PDO $db): void {
    $db->exec("CREATE TABLE IF NOT EXISTS ap_supporter_codes (
        code_hash TEXT PRIMARY KEY, source_ref TEXT UNIQUE NOT NULL, email_hash TEXT NOT NULL, receipt_hash TEXT NOT NULL,
        created_at TEXT NOT NULL, expires_at TEXT, used_by BIGINT, used_at TEXT
    )");
    $db->exec("CREATE INDEX IF NOT EXISTS idx_supporter_used_by ON ap_supporter_codes(used_by) WHERE used_at IS NOT NULL");
}
function ap_supporter_email_hash(string $email): string { return hash('sha256', strtolower(trim($email))); }
/** No HTTP endpoint. Caller must verify a settled live payment against authenticated Clearance identity. */
function ap_supporter_issue(string $customer, string $receipt, string $email): array {
    $secret = (string) getenv('VAAK_SUPPORTER_CODE_SECRET');
    if (!ap_supporter_enabled() || strlen($secret) < 32 || !preg_match('/^cus_[A-Za-z0-9]+$/D',$customer)
        || !preg_match('/^ch_[A-Za-z0-9]+$/D',$receipt) || !filter_var($email,FILTER_VALIDATE_EMAIL))
        return ['ok'=>false,'error'=>'Supporter rewards are unavailable.'];
    // One permanent reward per paying customer. Stable secret recovers issuance after either DB write fails.
    $source = hash('sha256','clearance-paid-customer:'.$customer);
    $code = 'SUP-'.strtoupper(substr(hash_hmac('sha256',$source,$secret),0,32));
    $hash = hash('sha256',$code); $db=ap_db();
    try {
        ap_supporter_schema($db);
        $db->prepare('INSERT INTO ap_supporter_codes(code_hash,source_ref,email_hash,receipt_hash,created_at) VALUES (?,?,?,?,?) ON CONFLICT(source_ref) DO NOTHING')
            ->execute([$hash,$source,ap_supporter_email_hash($email),hash('sha256',$receipt),ap_db_now()]);
        $st=$db->prepare('SELECT code_hash,email_hash,used_at FROM ap_supporter_codes WHERE source_ref=?');$st->execute([$source]);$row=$st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($row) || !hash_equals($hash,$row['code_hash']) || !hash_equals(ap_supporter_email_hash($email),$row['email_hash']))
            throw new RuntimeException('Issuance conflict');
        return ['ok'=>true,'code'=>$code,'used'=>!empty($row['used_at'])];
    } catch(Throwable $e) { return ['ok'=>false,'error'=>'Could not issue Supporter reward.']; }
}
function ap_supporter_code_hash(string $code): string { return hash('sha256',strtoupper(trim($code))); }
/** Must run inside the caller's serialized transaction. Raw codes never enter application/admin models. */
function ap_supporter_available(PDO $db,string $hash,string $email): bool {
    $st=$db->prepare('SELECT email_hash,expires_at,used_at FROM ap_supporter_codes WHERE code_hash=?');$st->execute([$hash]);$r=$st->fetch(PDO::FETCH_ASSOC);
    return is_array($r) && $r['used_at']===null && hash_equals($r['email_hash'],ap_supporter_email_hash($email))
        && ($r['expires_at']===null || (strtotime($r['expires_at'].' UTC') !== false && strtotime($r['expires_at'].' UTC') > time()));
}
function ap_supporter_award(PDO $db,string $hash,int $uid,string $email,string $now): void {
    if (!$db->inTransaction() || !ap_supporter_available($db,$hash,$email)) throw new RuntimeException('Unavailable reward');
    $st=$db->prepare('UPDATE ap_supporter_codes SET used_by=?,used_at=? WHERE code_hash=? AND used_at IS NULL');$st->execute([$uid,$now,$hash]);
    if ($st->rowCount()!==1) throw new RuntimeException('Reward race');
    $st=$db->prepare('SELECT actor_key FROM ap_users WHERE id=? AND disabled_at IS NULL');$st->execute([$uid]);$actor=$st->fetchColumn();
    if (!is_string($actor)) throw new RuntimeException('Unavailable account');
    if (ap_db_driver($db)==='pgsql') $db->prepare('SELECT pg_advisory_xact_lock(hashtext(?))')->execute(['vaak:profile-save:'.strtolower(trim($actor))]);
    $st=$db->prepare('SELECT profile_badges FROM actor_profile WHERE actor_key=?');$st->execute([$actor]);$badges=json_decode((string)$st->fetchColumn(),true);
    $badges=is_array($badges)?array_values(array_filter($badges,fn($b)=>is_string($b)&&$b!=='supporter')):[];
    // Reward is separate from the six freely selected flair slots.
    array_unshift($badges,'supporter');
    $db->prepare('INSERT INTO actor_profile(actor_key,name,summary,attachment_json,updated_at) VALUES (?,?,?, ?,?) ON CONFLICT(actor_key) DO NOTHING')->execute([$actor,$actor,'','[]',$now]);
    $db->prepare('UPDATE actor_profile SET profile_badges=?,updated_at=? WHERE actor_key=?')->execute([json_encode($badges,JSON_UNESCAPED_UNICODE),$now,$actor]);
}
function ap_supporter_invalidate(PDO $db,int $uid): void {
    try {
        $st=$db->prepare('SELECT actor_key FROM ap_users WHERE id=?');$st->execute([$uid]);$actor=$st->fetchColumn();
        if (is_string($actor) && function_exists('ap_redis_delete')) ap_redis_delete('vaak:profile:v1:'.hash('sha256',trim($actor)));
    } catch(Throwable $e) { /* Committed award survives unavailable optional cache. */ }
}
function ap_supporter_redeem(string $code,string $csrf): array {
    if (!ap_supporter_enabled() || !ap_auth_csrf_ok($csrf)) return ['ok'=>false,'error'=>'Reward unavailable or session expired.'];
    $db=ap_db();
    try {
        ap_supporter_schema($db); ap_registration_approvals_schema($db);
        $db->beginTransaction(); ap_registration_approvals_lock($db);
        if (ap_db_driver($db)==='pgsql') $db->exec('LOCK TABLE ap_supporter_codes IN SHARE ROW EXCLUSIVE MODE');
        $user=ap_auth_current_user();$id=(int)($user['id']??0);
        $st=$db->prepare('SELECT id,email FROM ap_users WHERE id=? AND disabled_at IS NULL');$st->execute([$id]);$current=$st->fetch(PDO::FETCH_ASSOC);
        if (!is_array($current)) throw new RuntimeException('Account unavailable');
        $hash=ap_supporter_code_hash($code);
        $st=$db->prepare('SELECT used_by FROM ap_supporter_codes WHERE code_hash=?');$st->execute([$hash]);$used=$st->fetchColumn();
        if ($used!==false && $used!==null && (int)$used===$id) {$db->commit();return ['ok'=>true,'already_redeemed'=>true];}
        $st=$db->prepare("SELECT 1 FROM ap_registration_applications WHERE supporter_hash=? AND status='pending'");$st->execute([$hash]);
        if ($st->fetchColumn()) throw new RuntimeException('Reserved reward');
        ap_supporter_award($db,$hash,$id,(string)($current['email']??''),ap_db_now());$db->commit();ap_supporter_invalidate($db,$id);
        return ['ok'=>true];
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack();return ['ok'=>false,'error'=>'Could not redeem this reward. Use the email you paid with, and an unused Supporter code.']; }
}
