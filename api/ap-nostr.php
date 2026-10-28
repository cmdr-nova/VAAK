<?php
/**
 * Opt-in Nostr follows in Home.
 *
 * A user pastes their npub in Settings > Profile. That is a public key: VAAK
 * never asks for or stores a Nostr private key, so it cannot publish anything
 * as the user by itself. Likes, replies and follows from Home cards are signed
 * in the browser by the user's signer extension (NIP-07) and only relayed by
 * the worker (ap_nostr_action below, nostr/act.rs). `vaak-worker nostr-sync`
 * (rust/vaak-worker/src/nostr, deploy/vaak-nostr-sync.timer) reads the user's
 * follow list and their follows' recent notes from public relays, and Home
 * mixes those notes in.
 *
 * The tables come from api/migrations/20261028_nostr_postgres.sql and
 * 20261028_nostr_actions_postgres.sql (applied by
 * deploy/remote/migrate.php). Until they exist every helper here returns
 * nothing and Settings hides the form.
 *
 * Rollout: the form is shown to admins only until VAAK_NOSTR=1 is set for
 * PHP-FPM; VAAK_NOSTR=0 hides it for everyone (the sync honours the same
 * kill switch).
 *
 * Loading this file runs nothing and needs no database.
 */
declare(strict_types=1);

const AP_NOSTR_BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

function ap_nostr_sqlite_ddl(): array
{
    return [
        'CREATE TABLE IF NOT EXISTS nostr_links (
            owner_user_id INTEGER PRIMARY KEY,
            pubkey TEXT NOT NULL,
            show_in_home INTEGER NOT NULL DEFAULT 1,
            follow_count INTEGER NOT NULL DEFAULT 0,
            follow_list_at INTEGER NOT NULL DEFAULT 0,
            synced_at INTEGER NOT NULL DEFAULT 0,
            next_sync_at INTEGER NOT NULL DEFAULT 0,
            failures INTEGER NOT NULL DEFAULT 0,
            last_error TEXT NOT NULL DEFAULT \'\',
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )',
        'CREATE TABLE IF NOT EXISTS nostr_follows (
            owner_user_id INTEGER NOT NULL,
            pubkey TEXT NOT NULL,
            PRIMARY KEY (owner_user_id, pubkey)
        )',
    ];
}

/** True when nostr_links exists. SQLite (tests, dev) creates it on demand. */
function ap_nostr_table_ready(?PDO $db = null): bool
{
    if (array_key_exists('ap_nostr_table_ready', $GLOBALS) && is_bool($GLOBALS['ap_nostr_table_ready'])) {
        return $GLOBALS['ap_nostr_table_ready'];
    }
    $ok = false;
    try {
        $db ??= ap_db();
        if (strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'sqlite') {
            foreach (ap_nostr_sqlite_ddl() as $ddl) {
                $db->exec($ddl);
            }
            $ok = true;
        } else {
            $name = $db->query("SELECT to_regclass('public.nostr_links')")->fetchColumn();
            $ok = is_string($name) && $name !== '';
        }
    } catch (Throwable $e) {
        $ok = false;
    }
    $GLOBALS['ap_nostr_table_ready'] = $ok;
    return $ok;
}

/** Settings shows the form to admins first; VAAK_NOSTR=1 opens it to everyone. */
function ap_nostr_form_visible(bool $isAdmin): bool
{
    $flag = getenv('VAAK_NOSTR');
    if ($flag === '0') {
        return false;
    }
    return $flag === '1' || $isAdmin;
}

function ap_nostr_bech32_polymod(array $values): int
{
    $gen = [0x3b6a57b2, 0x26508e6d, 0x1ea119fa, 0x3d4233dd, 0x2a1462b3];
    $chk = 1;
    foreach ($values as $v) {
        $top = $chk >> 25;
        $chk = (($chk & 0x1ffffff) << 5) ^ $v;
        for ($i = 0; $i < 5; $i++) {
            if ((($top >> $i) & 1) === 1) {
                $chk ^= $gen[$i];
            }
        }
    }
    return $chk;
}

/** @return list<int> */
function ap_nostr_bech32_hrp_expand(string $hrp): array
{
    $out = [];
    $len = strlen($hrp);
    for ($i = 0; $i < $len; $i++) {
        $out[] = ord($hrp[$i]) >> 5;
    }
    $out[] = 0;
    for ($i = 0; $i < $len; $i++) {
        $out[] = ord($hrp[$i]) & 31;
    }
    return $out;
}

/**
 * NIP-19 bech32 (not bech32m). Returns [hrp, bytes] or null when the string
 * is not valid bech32 with a good checksum.
 *
 * @return array{0:string,1:string}|null
 */
function ap_nostr_bech32_decode(string $input): ?array
{
    if (strlen($input) > 5000 || $input !== strtolower($input) && $input !== strtoupper($input)) {
        return null;
    }
    $s = strtolower($input);
    $pos = strrpos($s, '1');
    if ($pos === false || $pos < 1 || $pos + 7 > strlen($s)) {
        return null;
    }
    $hrp = substr($s, 0, $pos);
    $values = [];
    for ($i = $pos + 1, $n = strlen($s); $i < $n; $i++) {
        $v = strpos(AP_NOSTR_BECH32_CHARSET, $s[$i]);
        if ($v === false) {
            return null;
        }
        $values[] = $v;
    }
    if (ap_nostr_bech32_polymod(array_merge(ap_nostr_bech32_hrp_expand($hrp), $values)) !== 1) {
        return null;
    }
    $data = array_slice($values, 0, -6);
    $acc = 0;
    $bits = 0;
    $bytes = '';
    foreach ($data as $v) {
        $acc = (($acc << 5) | $v) & 0xffffff;
        $bits += 5;
        if ($bits >= 8) {
            $bits -= 8;
            $bytes .= chr(($acc >> $bits) & 0xff);
        }
    }
    if ($bits >= 5 || (($acc << (8 - $bits)) & 0xff) !== 0) {
        return null;
    }
    return [$hrp, $bytes];
}

function ap_nostr_bech32_encode(string $hrp, string $bytes): string
{
    $values = [];
    $acc = 0;
    $bits = 0;
    $len = strlen($bytes);
    for ($i = 0; $i < $len; $i++) {
        $acc = (($acc << 8) | ord($bytes[$i])) & 0xffffff;
        $bits += 8;
        while ($bits >= 5) {
            $bits -= 5;
            $values[] = ($acc >> $bits) & 31;
        }
    }
    if ($bits > 0) {
        $values[] = ($acc << (5 - $bits)) & 31;
    }
    $poly = ap_nostr_bech32_polymod(array_merge(ap_nostr_bech32_hrp_expand($hrp), $values, [0, 0, 0, 0, 0, 0])) ^ 1;
    for ($i = 0; $i < 6; $i++) {
        $values[] = ($poly >> (5 * (5 - $i))) & 31;
    }
    $out = $hrp . '1';
    foreach ($values as $v) {
        $out .= AP_NOSTR_BECH32_CHARSET[$v];
    }
    return $out;
}

function ap_nostr_npub(string $hexPubkey): string
{
    if (!preg_match('/^[0-9a-f]{64}$/', $hexPubkey)) {
        return '';
    }
    return ap_nostr_bech32_encode('npub', (string) hex2bin($hexPubkey));
}

/**
 * Turn what a user pasted into a 64-char hex public key.
 * Accepts npub1…, nprofile1…, a "nostr:" prefix, or raw hex.
 *
 * @return array{ok:bool,pubkey?:string,error?:string}
 */
function ap_nostr_parse_pubkey(string $raw): array
{
    $s = trim($raw);
    if (stripos($s, 'nostr:') === 0) {
        $s = substr($s, 6);
    }
    if ($s === '') {
        return ['ok' => true, 'pubkey' => ''];
    }
    if (preg_match('/^nsec1/i', $s)) {
        return ['ok' => false, 'error' => 'That is your Nostr private key (nsec). Do not paste it anywhere, and consider it exposed if you shared it. VAAK only needs your public npub.'];
    }
    if (preg_match('/^[0-9a-fA-F]{64}$/', $s)) {
        return ['ok' => true, 'pubkey' => strtolower($s)];
    }
    $decoded = ap_nostr_bech32_decode($s);
    if ($decoded === null) {
        return ['ok' => false, 'error' => 'That does not look like an npub. It should start with npub1.'];
    }
    [$hrp, $bytes] = $decoded;
    if ($hrp === 'npub' && strlen($bytes) === 32) {
        return ['ok' => true, 'pubkey' => bin2hex($bytes)];
    }
    if ($hrp === 'nprofile') {
        // TLV: type 0 is the 32-byte public key.
        $i = 0;
        $n = strlen($bytes);
        while ($i + 2 <= $n) {
            $type = ord($bytes[$i]);
            $len = ord($bytes[$i + 1]);
            $value = substr($bytes, $i + 2, $len);
            if ($type === 0 && strlen($value) === 32) {
                return ['ok' => true, 'pubkey' => bin2hex($value)];
            }
            $i += 2 + $len;
        }
    }
    return ['ok' => false, 'error' => 'That does not look like an npub. It should start with npub1.'];
}

/** @return array<string,mixed>|null */
function ap_nostr_link_for_user(int $userId, ?PDO $db = null): ?array
{
    if ($userId <= 0) {
        return null;
    }
    try {
        $db ??= ap_db();
        if (!ap_nostr_table_ready($db)) {
            return null;
        }
        $st = $db->prepare('SELECT * FROM nostr_links WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Save, replace, or (empty input) remove a user's linked npub. Replacing or
 * removing drops that user's stored follow list; the sync then deletes notes
 * nobody follows any more.
 *
 * @return array{ok:bool,pubkey?:string,error?:string}
 */
function ap_nostr_save_link(int $userId, string $raw, bool $showInHome = true, ?PDO $db = null): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Sign in again to change this.'];
    }
    $parsed = ap_nostr_parse_pubkey($raw);
    if (empty($parsed['ok'])) {
        return $parsed;
    }
    $pubkey = (string) $parsed['pubkey'];
    try {
        $db ??= ap_db();
        if (!ap_nostr_table_ready($db)) {
            return ['ok' => false, 'error' => 'Nostr is not set up on this server yet.'];
        }
        $existing = ap_nostr_link_for_user($userId, $db);
        $now = gmdate('c');
        if ($pubkey === '') {
            $db->prepare('DELETE FROM nostr_follows WHERE owner_user_id = ?')->execute([$userId]);
            $db->prepare('DELETE FROM nostr_links WHERE owner_user_id = ?')->execute([$userId]);
            return ['ok' => true, 'pubkey' => ''];
        }
        $show = $showInHome ? 1 : 0;
        $isPg = strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) === 'pgsql';
        $showSql = $isPg ? ($show ? 'TRUE' : 'FALSE') : (string) $show;
        if (is_array($existing)) {
            if ((string) ($existing['pubkey'] ?? '') === $pubkey) {
                $db->prepare("UPDATE nostr_links SET show_in_home = {$showSql}, updated_at = ? WHERE owner_user_id = ?")
                    ->execute([$now, $userId]);
                return ['ok' => true, 'pubkey' => $pubkey];
            }
            $db->prepare('DELETE FROM nostr_follows WHERE owner_user_id = ?')->execute([$userId]);
            $db->prepare(
                "UPDATE nostr_links SET pubkey = ?, show_in_home = {$showSql}, follow_count = 0, follow_list_at = 0,
                 synced_at = 0, next_sync_at = 0, failures = 0, last_error = '', updated_at = ? WHERE owner_user_id = ?"
            )->execute([$pubkey, $now, $userId]);
        } else {
            $db->prepare(
                "INSERT INTO nostr_links (owner_user_id, pubkey, show_in_home, created_at, updated_at) VALUES (?, ?, {$showSql}, ?, ?)"
            )->execute([$userId, $pubkey, $now, $now]);
        }
        return ['ok' => true, 'pubkey' => $pubkey];
    } catch (Throwable $e) {
        error_log('[nostr] save failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save your npub.'];
    }
}

/** One line under the Settings form describing the last sync. */
function ap_nostr_status_line(?array $link, int $now): string
{
    if (!is_array($link) || (string) ($link['pubkey'] ?? '') === '') {
        return '';
    }
    $synced = (int) ($link['synced_at'] ?? 0);
    $error = trim((string) ($link['last_error'] ?? ''));
    if ($synced <= 0) {
        return $error !== '' ? 'Waiting for the first sync. Last try: ' . $error . '.' : 'Waiting for the first sync (a few minutes).';
    }
    $ago = max(0, $now - $synced);
    $when = $ago < 90 ? 'just now' : ($ago < 5400 ? (int) round($ago / 60) . ' min ago' : (int) round($ago / 3600) . ' h ago');
    $line = 'Following ' . (int) ($link['follow_count'] ?? 0) . ' on Nostr. Last sync ' . $when . '.';
    if ($error !== '') {
        $line .= ' Last problem: ' . $error . '.';
    }
    return $line;
}

/**
 * Like, reply or follow from a Home card. The browser's signer extension
 * (NIP-07) signs; the worker (`/shadow/nostr-prepare`, `/shadow/nostr-publish`
 * in nostr/act.rs) builds the event, checks the signed copy and relays it.
 * VAAK never sees a private key.
 *
 * @param array<string,mixed> $post
 * @return array{status:int, body:array<string,mixed>}
 */
function ap_nostr_action(string $action, int $ownerUserId, bool $isAdmin, array $post): array
{
    $fail = static fn (int $status, string $error): array => ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    if (!ap_nostr_form_visible($isAdmin)) {
        return $fail(403, 'Nostr is not open on this server yet.');
    }
    if ($ownerUserId < 1) {
        return $fail(401, 'Sign in first.');
    }
    if ($action === 'nostr_prepare') {
        $purpose = (string) ($post['purpose'] ?? '');
        $target = strtolower(trim((string) ($post['target'] ?? '')));
        if (!in_array($purpose, ['like', 'reply', 'follow', 'unfollow'], true) || !preg_match('/^[0-9a-f]{64}$/', $target)) {
            return $fail(400, 'Unknown Nostr action.');
        }
        $path = '/shadow/nostr-prepare';
        $payload = ['owner_id' => $ownerUserId, 'purpose' => $purpose, 'target' => $target];
    } elseif ($action === 'nostr_publish') {
        $nonce = (string) ($post['nonce'] ?? '');
        $event = json_decode((string) ($post['event'] ?? ''), true);
        if (!preg_match('/^[0-9a-f]{32}$/', $nonce) || !is_array($event)) {
            return $fail(400, 'Your signer didn\'t return a signed event.');
        }
        $path = '/shadow/nostr-publish';
        $payload = ['owner_id' => $ownerUserId, 'nonce' => $nonce, 'event' => $event];
    } else {
        return $fail(400, 'Unknown Nostr action.');
    }
    $base = getenv('VAAK_SHADOW_HTTP');
    $base = is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : 'http://127.0.0.1:8787';
    $host = parse_url($base, PHP_URL_HOST);
    if (!is_string($host) || !in_array(strtolower($host), ['127.0.0.1', 'localhost', '::1'], true) || !function_exists('curl_init')) {
        return $fail(503, 'The Nostr worker is not reachable.');
    }
    if (!function_exists('ap_shadow_http_auth_headers')) {
        require_once __DIR__ . '/ap-shadow-http.php';
    }
    $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($json) || strlen($json) > 1048576) {
        return $fail(413, 'That is too large to send.');
    }
    $ch = curl_init($base . $path);
    if ($ch === false) {
        return $fail(503, 'The Nostr worker is not reachable.');
    }
    // Follow edits read the current list from relays before answering.
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 250,
        CURLOPT_TIMEOUT_MS => 30000,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_HTTPHEADER => array_merge(['Content-Type: application/json', 'Accept: application/json'], ap_shadow_http_auth_headers()),
    ]);
    $raw = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    $body = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($body) || !array_key_exists('ok', $body)) {
        return $fail(502, 'The Nostr worker did not answer. Try again in a minute.');
    }
    return ['status' => $code === 200 ? 200 : max(400, $code), 'body' => $body];
}
