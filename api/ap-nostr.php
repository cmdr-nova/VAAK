<?php
/**
 * Opt-in Nostr follows in Home.
 *
 * A user pastes their npub in Settings > Profile. That is a public key: VAAK
 * never asks for or stores a Nostr private key, so it cannot publish anything
 * as the user by itself. Likes, replies and follows from Home cards are signed
 * in the browser by the user's signer extension (NIP-07) and only relayed by
 * the worker (ap_nostr_action below, nostr/act.rs); pages with no extension
 * can sign through the user's NIP-46 bunker instead (nostr/bunker.rs,
 * ops/nostr-bunker). `vaak-worker nostr-sync`
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
/** NIP-56 report types, with the label the report box shows. */
const AP_NOSTR_REPORT_TYPES = ['spam', 'nudity', 'profanity', 'illegal', 'impersonation', 'malware', 'other'];

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
    $reply = ap_nostr_action_route($action, $ownerUserId, $isAdmin, $post);
    if ($action === 'nostr_prepare' && !empty($reply['body']['ok'])) {
        // Tells the page a bunker can sign when the browser's extension
        // can't (no key, locked, or a different key than the linked npub).
        $reply['body']['bunker'] = ap_nostr_bunker_for_user($ownerUserId) !== null;
    }
    return $reply;
}

/**
 * @param array<string,mixed> $post
 * @return array{status:int, body:array<string,mixed>}
 */
function ap_nostr_action_route(string $action, int $ownerUserId, bool $isAdmin, array $post): array
{
    $fail = static fn (int $status, string $error): array => ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    if (!ap_nostr_form_visible($isAdmin)) {
        return $fail(403, 'Nostr is not open on this server yet.');
    }
    if ($ownerUserId < 1) {
        return $fail(401, 'Sign in first.');
    }
    if ($action === 'nostr_prepare' && (string) ($post['purpose'] ?? '') === 'post') {
        // A VAAK post going to Nostr too: built here from the stored post,
        // so the page can only ask for a post the user already made.
        $note = ap_nostr_post_note($ownerUserId, trim((string) ($post['target'] ?? '')));
        if (empty($note['ok'])) {
            return ['status' => 200, 'body' => ['ok' => false, 'error' => $note['error'], 'retry' => !empty($note['retry'])]];
        }
        return ap_nostr_worker_call('/shadow/nostr-prepare', [
            'owner_id' => $ownerUserId,
            'purpose' => 'post',
            'target' => '',
            'note' => ['content' => $note['content'], 'tags' => $note['tags']],
        ], 30000);
    }
    if ($action === 'nostr_prepare' && (string) ($post['purpose'] ?? '') === 'lightning') {
        // Settings > Profile: set (or clear) lud16 on the user's own Nostr
        // profile. The worker edits the newest kind 0; the signer signs it.
        $address = strtolower(trim((string) ($post['lightning'] ?? '')));
        if ($address !== '' && (strlen($address) > 320 || !preg_match('/^[a-z0-9._+-]+@[a-z0-9.-]+\.[a-z0-9-]+$/', $address))) {
            return $fail(400, 'That isn\'t a Lightning address. It looks like name@wallet.com.');
        }
        return ap_nostr_worker_call('/shadow/nostr-prepare', [
            'owner_id' => $ownerUserId,
            'purpose' => 'lightning',
            'target' => '',
            'lightning' => $address,
        ], 30000);
    }
    if ($action === 'nostr_prepare' && (string) ($post['purpose'] ?? '') === 'follow_pack') {
        // Library > Collections: follow picked members of an open follow
        // pack in one kind 3 edit (nostr/pack.rs, nostr/act.rs).
        $addr = trim((string) ($post['target'] ?? ''));
        $picks = ap_nostr_pack_picks((string) ($post['picks'] ?? ''));
        if (!ap_nostr_pack_addr_ok($addr)) {
            return $fail(400, 'Unknown follow pack.');
        }
        if ($picks === []) {
            return $fail(400, 'Pick at least one person to follow.');
        }
        if (count($picks) > AP_NOSTR_PACK_MAX_PICKS) {
            return $fail(400, 'Pick ' . AP_NOSTR_PACK_MAX_PICKS . ' or fewer people at a time.');
        }
        return ap_nostr_worker_call('/shadow/nostr-prepare', [
            'owner_id' => $ownerUserId,
            'purpose' => 'follow_pack',
            'target' => $addr,
            'picks' => $picks,
        ], 30000);
    }
    if ($action === 'nostr_prepare') {
        $purpose = (string) ($post['purpose'] ?? '');
        $target = strtolower(trim((string) ($post['target'] ?? '')));
        if (!in_array($purpose, ['like', 'reply', 'repost', 'quote', 'delete', 'follow', 'unfollow', 'mute', 'unmute', 'report', 'zap'], true) || !preg_match('/^[0-9a-f]{64}$/', $target)) {
            return $fail(400, 'Unknown Nostr action.');
        }
        $path = '/shadow/nostr-prepare';
        $payload = ['owner_id' => $ownerUserId, 'purpose' => $purpose, 'target' => $target];
        if ($purpose === 'report') {
            $reason = (string) ($post['reason'] ?? '');
            if (!in_array($reason, AP_NOSTR_REPORT_TYPES, true)) {
                return $fail(400, 'Pick a reason for the report.');
            }
            $payload['reason'] = $reason;
        }
        if ($purpose === 'zap') {
            // NIP-57: the worker asks the author's Lightning wallet for an
            // invoice; the viewer's own wallet pays it (nostr/zap.rs).
            $sats = (string) ($post['zap_sats'] ?? '');
            $comment = trim((string) ($post['zap_comment'] ?? ''));
            if (!preg_match('/^[1-9][0-9]{0,6}$/', $sats) || (int) $sats > 1000000) {
                return $fail(400, 'Pick an amount from 1 to 1,000,000 sats.');
            }
            if (mb_strlen($comment) > 280) {
                return $fail(400, 'Keep the zap comment under 280 characters.');
            }
            $payload['zap'] = ['sats' => (int) $sats, 'comment' => $comment];
        }
    } elseif ($action === 'nostr_bunker_sign') {
        // No usable signer extension on this page (an iPhone Home Screen
        // app, or Safari on a Mac without one): the worker asks the user's
        // NIP-46 bunker to sign instead.
        $nonce = (string) ($post['nonce'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/', $nonce)) {
            return $fail(400, 'That action expired. Try again.');
        }
        $path = '/shadow/nostr-bunker-sign';
        $payload = ['owner_id' => $ownerUserId, 'nonce' => $nonce, 'content' => (string) ($post['content'] ?? '')];
    } elseif ($action === 'nostr_publish') {
        $nonce = (string) ($post['nonce'] ?? '');
        $event = json_decode((string) ($post['event'] ?? ''), true);
        if (!preg_match('/^[0-9a-f]{32}$/', $nonce) || !is_array($event)) {
            return $fail(400, 'Your signer didn\'t return a signed event.');
        }
        $path = '/shadow/nostr-publish';
        $payload = ['owner_id' => $ownerUserId, 'nonce' => $nonce, 'event' => $event];
    } elseif ($action === 'nostr_zap_status') {
        // A zap's pay panel asking whether its invoice was paid yet.
        $watch = (string) ($post['watch'] ?? '');
        if (!preg_match('/^[0-9a-f]{32}$/', $watch)) {
            return $fail(400, 'Unknown zap.');
        }
        return ap_nostr_worker_call('/shadow/nostr-zap-status', ['owner_id' => $ownerUserId, 'watch' => $watch], 15000);
    } else {
        return $fail(400, 'Unknown Nostr action.');
    }
    // Follow edits read the current list from relays before answering; a
    // bunker gets up to 35 s to answer (bunker.rs SIGN_DEADLINE).
    return ap_nostr_worker_call($path, $payload, $path === '/shadow/nostr-bunker-sign' ? 45000 : 30000);
}

/**
 * The user's NIP-46 bunker pairing (nostr_bunkers, made by the worker's
 * `/shadow/nostr-bunker-connect`), or null. Never includes the client key.
 *
 * @return array<string,mixed>|null
 */
/**
 * The Lightning address in a key's Nostr profile, as profile sync stored it
 * (nostr_zap_addrs, nostr/zap.rs): `name@domain` for a lud16, '' when none.
 *
 * @return array{address: string, zaps: ?bool}
 */
function ap_nostr_lightning_address(string $pubkey, ?PDO $db = null): array
{
    $none = ['address' => '', 'zaps' => null];
    if (!preg_match('/^[0-9a-f]{64}$/', $pubkey)) {
        return $none;
    }
    try {
        $db ??= ap_db();
        if (strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'pgsql'
            || !is_string($db->query("SELECT to_regclass('public.nostr_zap_addrs')")->fetchColumn())) {
            return $none;
        }
        $st = $db->prepare('SELECT pay_url, allows_nostr, checked_at FROM nostr_zap_addrs WHERE pubkey = ? LIMIT 1');
        $st->execute([$pubkey]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        return $none;
    }
    if (!is_array($row)) {
        return $none;
    }
    return [
        'address' => ap_nostr_lightning_from_pay_url((string) $row['pay_url']),
        'zaps' => (int) $row['checked_at'] > 0 ? in_array($row['allows_nostr'], [true, 't', 1, '1'], true) : null,
    ];
}

/** `https://domain/.well-known/lnurlp/name` → `name@domain` ('' otherwise). */
function ap_nostr_lightning_from_pay_url(string $url): string
{
    if (preg_match('#^https://([a-z0-9.-]+)/\.well-known/lnurlp/([a-z0-9._+-]+)$#', $url, $m)) {
        return $m[2] . '@' . $m[1];
    }
    return '';
}

function ap_nostr_bunker_for_user(int $userId, ?PDO $db = null): ?array
{
    if ($userId <= 0) {
        return null;
    }
    try {
        $db ??= ap_db();
        if (strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'pgsql') {
            return null;
        }
        if (!is_string($db->query("SELECT to_regclass('public.nostr_bunkers')")->fetchColumn())) {
            return null;
        }
        $st = $db->prepare('SELECT signer_pubkey, connected_at, last_used_at, last_error, last_error_at FROM nostr_bunkers WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Pair with the bunker in a pasted bunker:// link, or (empty input) forget
 * the pairing. The worker does the NIP-46 handshake and checks the bunker
 * holds the linked npub's key.
 *
 * @return array{ok:bool,connected?:bool,removed?:bool,error?:string}
 */
function ap_nostr_bunker_save(int $userId, string $uri, ?PDO $db = null): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Sign in again to change this.'];
    }
    $uri = trim($uri);
    if ($uri === '') {
        try {
            $db ??= ap_db();
            $removed = ap_nostr_bunker_for_user($userId, $db) !== null;
            if ($removed) {
                $db->prepare('DELETE FROM nostr_bunkers WHERE owner_user_id = ?')->execute([$userId]);
            }
            return ['ok' => true, 'connected' => false, 'removed' => $removed];
        } catch (Throwable $e) {
            error_log('[nostr] bunker forget failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not disconnect the bunker.'];
        }
    }
    if (stripos($uri, 'nsec1') !== false) {
        return ['ok' => false, 'error' => 'That looks like a secret key. Never paste your nsec on VAAK; paste the bunker:// link instead.'];
    }
    if (stripos($uri, 'bunker://') !== 0) {
        return ['ok' => false, 'error' => 'Paste the whole bunker:// link from your bunker.'];
    }
    $reply = ap_nostr_worker_call('/shadow/nostr-bunker-connect', ['owner_id' => $userId, 'uri' => $uri], 90000);
    $body = $reply['body'];
    if (empty($body['ok'])) {
        return ['ok' => false, 'error' => (string) ($body['error'] ?? 'Could not reach the bunker.')];
    }
    return ['ok' => true, 'connected' => true];
}

/** One line under the bunker box describing the pairing. */
function ap_nostr_bunker_status_line(?array $bunker, int $now): string
{
    if (!is_array($bunker)) {
        return '';
    }
    $ago = static function (int $at) use ($now): string {
        $d = max(0, $now - $at);
        return $d < 90 ? 'just now' : ($d < 5400 ? (int) round($d / 60) . ' min ago' : ($d < 172800 ? (int) round($d / 3600) . ' h ago' : (int) round($d / 86400) . ' days ago'));
    };
    $line = 'Connected to bunker ' . substr(ap_nostr_npub((string) ($bunker['signer_pubkey'] ?? '')), 0, 16) . '… ' . $ago((int) ($bunker['connected_at'] ?? 0)) . '.';
    $used = (int) ($bunker['last_used_at'] ?? 0);
    if ($used > 0) {
        $line .= ' Last signed ' . $ago($used) . '.';
    }
    $error = trim((string) ($bunker['last_error'] ?? ''));
    $errorAt = (int) ($bunker['last_error_at'] ?? 0);
    if ($error !== '' && $errorAt >= $used) {
        $line .= ' Last problem (' . $ago($errorAt) . '): ' . $error . '.';
    }
    return $line;
}

/**
 * The same post crosspost for an app with an access token instead of a
 * browser (Shana, ops/nostr-signer/vaak_nostr.py): `prepare` takes the id
 * POST /api/v1/statuses returned (or the post's note URL) and answers the unsigned note, the app
 * signs it with a key that never leaves its machine, and `publish` hands it
 * back for the worker to check and relay. Blog posts stay on VAAK.
 *
 * @param array<string,mixed> $user ap_users row the token belongs to
 * @param array<string,mixed> $in
 * @return array{status:int, body:array<string,mixed>}
 */
function ap_nostr_api_action(string $step, array $user, array $in, ?PDO $db = null): array
{
    $ownerId = (int) ($user['id'] ?? 0);
    $isAdmin = !empty($user['is_admin']);
    if ($step === 'publish') {
        $event = $in['event'] ?? null;
        return ap_nostr_action('nostr_publish', $ownerId, $isAdmin, [
            'nonce' => (string) ($in['nonce'] ?? ''),
            'event' => is_array($event) ? (string) json_encode($event, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : (string) $event,
        ]);
    }
    if ($step !== 'prepare') {
        return ['status' => 404, 'body' => ['ok' => false, 'error' => 'Unknown Nostr step.']];
    }
    // note_id is for posters that don't go through POST /api/v1/statuses
    // (Shana's SSH "post" command answers the note URL, not a status id).
    // ap_nostr_post_note still checks it is the token owner's own post.
    $noteId = trim((string) ($in['note_id'] ?? ''));
    if ($noteId !== '' && (!str_starts_with($noteId, 'https://') || strlen($noteId) > 300)) {
        return ['status' => 400, 'body' => ['ok' => false, 'error' => 'note_id must be the post\'s https URL.']];
    }
    if ($noteId === '') {
        $sid = (string) ($in['status_id'] ?? '');
        if (!preg_match('/^\d{1,20}$/', $sid)) {
            return ['status' => 400, 'body' => ['ok' => false, 'error' => 'status_id or note_id required.']];
        }
        $row = ap_masto_local_row_from_public_id((int) $sid) ?? (((int) $sid < 2000000) ? ap_masto_status_by_local_id((int) $sid) : null);
        $noteId = is_array($row) ? (string) ($row['note_id'] ?? '') : '';
    }
    if ($noteId === '') {
        return ['status' => 200, 'body' => ['ok' => false, 'error' => 'The post is still being saved.', 'retry' => true]];
    }
    $db = $db ?? ap_db();
    $kind = $db->prepare('SELECT kind FROM outbox_notes WHERE id = ?');
    $kind->execute([$noteId]);
    if ((string) ($kind->fetchColumn() ?: '') === 'blog') {
        return ['status' => 200, 'body' => ['ok' => false, 'error' => 'Blog posts stay on VAAK.', 'skip' => true]];
    }
    return ap_nostr_action('nostr_prepare', $ownerId, $isAdmin, ['purpose' => 'post', 'target' => $noteId]);
}

/**
 * Who the composer's "Nostr" box posts as: the linked key and its kind 0
 * name and picture. Null when the user can't crosspost (no linked npub, or
 * Nostr not open to them).
 *
 * @return array{npub:string, name:string, picture:string}|null
 */
function ap_nostr_compose_identity(int $userId, bool $isAdmin, ?PDO $db = null): ?array
{
    if (!ap_nostr_form_visible($isAdmin)) {
        return null;
    }
    try {
        $db ??= ap_db();
        $link = ap_nostr_link_for_user($userId, $db);
        $pubkey = is_array($link) ? (string) ($link['pubkey'] ?? '') : '';
        $npub = ap_nostr_npub($pubkey);
        if ($npub === '') {
            return null;
        }
        $st = $db->prepare('SELECT name, display_name, picture FROM nostr_profiles WHERE pubkey = ? LIMIT 1');
        $st->execute([$pubkey]);
        $p = $st->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return null;
    }
    $name = trim((string) ($p['display_name'] ?? ''));
    if ($name === '') {
        $name = trim((string) ($p['name'] ?? ''));
    }
    if ($name === '') {
        $name = substr($npub, 0, 10) . '…' . substr($npub, -6);
    }
    $picture = trim((string) ($p['picture'] ?? ''));
    return [
        'npub' => $npub,
        'name' => $name,
        'picture' => str_starts_with($picture, 'https://') ? $picture : '',
    ];
}

/**
 * The Nostr copy of one of the user's own public, top-level VAAK posts:
 * its text, then its media links (NIP-92 imeta for each), hashtags as `t`
 * tags, the content warning (NIP-36) and a NIP-48 `proxy` tag naming the
 * VAAK post. Replies and non-public posts stay on VAAK. `retry` means the
 * post isn't stored yet (the publication queue is still writing it).
 *
 * @return array{ok:bool, content?:string, tags?:list<list<string>>, error?:string, retry?:bool}
 */
function ap_nostr_post_note(int $ownerUserId, string $noteId, ?PDO $db = null): array
{
    $db = $db ?? ap_db();
    $owner = function_exists('ap_db_owner_actor_id_for_user_id') ? rtrim(ap_db_owner_actor_id_for_user_id($ownerUserId), '/') : '';
    if ($owner === '' || !str_starts_with($noteId, $owner . '/notes/') || strlen($noteId) > 300) {
        return ['ok' => false, 'error' => 'Only your own posts can go to Nostr.'];
    }
    $st = $db->prepare('SELECT local_id, content_text, visibility, spoiler_text, in_reply_to_local_id FROM masto_statuses WHERE note_id = ?');
    $st->execute([$noteId]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return ['ok' => false, 'error' => 'The post is still being saved.', 'retry' => true];
    }
    $reply = $db->prepare('SELECT in_reply_to FROM outbox_notes WHERE id = ?');
    $reply->execute([$noteId]);
    if ($row['in_reply_to_local_id'] !== null || trim((string) ($reply->fetchColumn() ?: '')) !== '') {
        return ['ok' => false, 'error' => 'Replies stay on VAAK.'];
    }
    if ((string) $row['visibility'] !== 'public') {
        return ['ok' => false, 'error' => 'Only public posts go to Nostr.'];
    }
    $text = trim((string) $row['content_text']);
    // Media-only, poll-only and quote-only posts store a "(media)"-style
    // sentinel as their text; the Nostr note carries only the real body.
    $bare = preg_replace('/^[\s\x{00A0}\x{2009}]+|[\s\x{00A0}\x{2009}]+$/u', '', $text) ?? $text;
    if (in_array($bare, ['(media)', '(attachment)', '(poll)', '(quote)', '(boost)'], true)) {
        $text = '';
    }
    $tags = [];
    if (preg_match_all('/(?<![\w&#\/])#([\p{L}\p{N}_]{1,64})/u', $text, $m)) {
        foreach (array_unique(array_map(static fn (string $t): string => mb_strtolower($t), $m[1])) as $t) {
            if (count($tags) < 10) {
                $tags[] = ['t', $t];
            }
        }
    }
    $spoiler = trim((string) $row['spoiler_text']);
    if ($spoiler !== '') {
        $tags[] = ['content-warning', mb_substr($spoiler, 0, 500)];
    }
    $media = $db->prepare('SELECT public_url, mime, description FROM masto_media WHERE status_local_id = ? ORDER BY local_id LIMIT 4');
    $media->execute([(int) $row['local_id']]);
    $links = [];
    foreach ($media->fetchAll(PDO::FETCH_ASSOC) as $item) {
        $url = (string) $item['public_url'];
        if (!str_starts_with($url, 'https://')) {
            continue;
        }
        $links[] = $url;
        $meta = ['imeta', 'url ' . $url];
        $mime = trim((string) ($item['mime'] ?? ''));
        if ($mime !== '') {
            $meta[] = 'm ' . $mime;
        }
        $alt = trim(preg_replace('/\s+/', ' ', (string) ($item['description'] ?? '')) ?? '');
        if ($alt !== '') {
            $meta[] = 'alt ' . mb_substr($alt, 0, 1500);
        }
        $tags[] = $meta;
    }
    $content = trim($text . ($links !== [] ? "\n\n" . implode("\n", $links) : ''));
    if ($content === '') {
        return ['ok' => false, 'error' => 'There\'s nothing to post.'];
    }
    $tags[] = ['proxy', $noteId, 'activitypub'];
    return ['ok' => true, 'content' => $content, 'tags' => $tags];
}

/**
 * The hex id of a Nostr note from its njump link (https://njump.me/note1…,
 * how nostr-sync stores Nostr notes and their parents), or ''.
 */
function ap_nostr_note_hex_from_url(string $url): string
{
    if (!preg_match('#^https://njump\.me/(note1[02-9ac-hj-np-z]{6,})/?$#i', trim($url), $m)) {
        return '';
    }
    $dec = ap_nostr_bech32_decode($m[1]);
    if (!is_array($dec) || $dec[0] !== 'note' || strlen($dec[1]) !== 32) {
        return '';
    }
    return bin2hex($dec[1]);
}

/**
 * The local post a Nostr note is a crosspost of, or ''.
 *
 * Crossposts (composer checkbox, Shana's scripts) carry a NIP-48
 * `["proxy", <local note URL>, "activitypub"]` tag (ap_nostr_post_note).
 * A Nostr reply to one names only the Nostr note, so without this the
 * thread shows "reply to parent post" with nothing above it. The note is
 * looked up in the backup archive (linked users' own events) and in
 * nostr_events; the proxy is only believed when the note's key is linked to
 * the account that owns that local post, so a stranger can't tag their note
 * as someone else's post.
 */
function ap_nostr_proxied_local_note(string $url, ?PDO $db = null): string
{
    static $cache = [];
    $hex = ap_nostr_note_hex_from_url($url);
    if ($hex === '') {
        return '';
    }
    if (array_key_exists($hex, $cache)) {
        return $cache[$hex];
    }
    $found = '';
    try {
        $db ??= ap_db();
        foreach (['nostr_archive', 'nostr_events'] as $table) {
            try {
                $st = $db->prepare("SELECT pubkey, tags FROM {$table} WHERE id = ? LIMIT 1");
                $st->execute([$hex]);
                $row = $st->fetch(PDO::FETCH_ASSOC);
            } catch (Throwable $e) {
                $row = false;
            }
            if (!is_array($row)) {
                continue;
            }
            $tags = is_array($row['tags']) ? $row['tags'] : json_decode((string) $row['tags'], true);
            $proxy = '';
            foreach (is_array($tags) ? $tags : [] as $tag) {
                if (is_array($tag) && ($tag[0] ?? '') === 'proxy' && ($tag[2] ?? '') === 'activitypub'
                    && is_string($tag[1] ?? null) && str_starts_with($tag[1], 'https://')) {
                    $proxy = rtrim($tag[1], '/');
                    break;
                }
            }
            if ($proxy === '') {
                break;
            }
            $owners = $db->prepare('SELECT owner_user_id FROM nostr_links WHERE pubkey = ?');
            $owners->execute([(string) $row['pubkey']]);
            foreach ($owners->fetchAll(PDO::FETCH_COLUMN) as $ownerId) {
                $actor = function_exists('ap_db_owner_actor_id_for_user_id')
                    ? rtrim(ap_db_owner_actor_id_for_user_id((int) $ownerId), '/')
                    : '';
                if ($actor === '' || !str_starts_with($proxy, $actor . '/notes/')) {
                    continue;
                }
                $local = $db->prepare('SELECT 1 FROM masto_statuses WHERE note_id = ? LIMIT 1');
                $local->execute([$proxy]);
                if ($local->fetchColumn()) {
                    $found = $proxy;
                }
                break;
            }
            break;
        }
    } catch (Throwable $e) {
        $found = '';
    }
    return $cache[$hex] = $found;
}

/**
 * POST to the worker's shadow API on loopback.
 *
 * @return array{status:int, body:array<string,mixed>}
 */
function ap_nostr_worker_call(string $path, array $payload, int $timeoutMs): array
{
    $fail = static fn (int $status, string $error): array => ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
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
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $json,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT_MS => 250,
        CURLOPT_TIMEOUT_MS => $timeoutMs,
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

/**
 * Stored Nostr notes as Mastodon-shaped statuses (the worker paints them the
 * same way Home does), keyed by hex id. Missing or unreachable: left out.
 * Cached for the request, so a Bookmarks page asks once per note.
 *
 * @param list<string> $ids
 * @return array<string, array<string,mixed>>
 */
function ap_nostr_statuses(array $ids): array
{
    static $cache = [];
    $want = [];
    foreach ($ids as $id) {
        $id = strtolower(trim((string) $id));
        if (preg_match('/^[0-9a-f]{64}$/', $id) && !array_key_exists($id, $cache)) {
            $want[$id] = true;
        }
    }
    if ($want !== []) {
        $res = ap_nostr_worker_call('/shadow/nostr-statuses', ['ids' => array_keys($want)], 3000);
        $found = is_array($res['body']['statuses'] ?? null) ? $res['body']['statuses'] : [];
        foreach (array_keys($want) as $id) {
            $cache[$id] = is_array($found[$id] ?? null) ? $found[$id] : null;
        }
    }
    $out = [];
    foreach ($ids as $id) {
        $id = strtolower(trim((string) $id));
        if (is_array($cache[$id] ?? null)) {
            $out[$id] = $cache[$id];
        }
    }
    return $out;
}

/**
 * Card HTML for one Nostr status, painted by the worker like Home paints it
 * (signed actions, VAAK bookmark). '' when the worker can't be reached.
 */
function ap_nostr_card_html(array $status, int $ownerUserId, string $view): string
{
    return ap_nostr_cards_html([$status], $ownerUserId, $view)[0];
}

/**
 * Worker-painted cards for Nostr statuses, one HTML string per status in
 * order ('' where none could be painted).
 * @param list<array<string,mixed>> $statuses
 * @return list<string>
 */
function ap_nostr_cards_html(array $statuses, int $ownerUserId, string $view): array
{
    $out = array_fill(0, count($statuses), '');
    $send = [];
    foreach (array_values($statuses) as $i => $status) {
        // A Nostr note, or a boost wrapper around one (a VAAK user's boost
        // of a Nostr note: the worker paints the booster line too).
        $inner = is_array($status['reblog'] ?? null) ? $status['reblog'] : null;
        if (is_array($status) && (str_starts_with((string) ($status['id'] ?? ''), 'nostr:')
            || ($inner !== null && str_starts_with((string) ($inner['id'] ?? ''), 'nostr:')))) {
            $send[$i] = $status;
        }
    }
    if ($ownerUserId < 1 || $send === []) {
        return $out;
    }
    foreach (array_chunk($send, 100, true) as $chunk) {
        $res = ap_nostr_worker_call('/shadow/nostr-card', ['owner_id' => $ownerUserId, 'from' => $view, 'statuses' => array_values($chunk)], 3000);
        $htmls = $res['body']['htmls'] ?? null;
        if (empty($res['body']['ok']) || !is_array($htmls) || count($htmls) !== count($chunk)) {
            continue;
        }
        foreach (array_keys($chunk) as $n => $i) {
            $out[$i] = is_string($htmls[$n] ?? null) ? $htmls[$n] : '';
        }
    }
    return $out;
}

/** Favourites > Nostr shows for users who may use Nostr and linked an npub. */
function ap_nostr_favourites_tab_visible(int $ownerUserId, bool $isAdmin): bool
{
    return $ownerUserId > 0 && ap_nostr_form_visible($isAdmin) && ap_nostr_table_ready()
        && ap_nostr_link_for_user($ownerUserId) !== null;
}

/**
 * Favourites > Nostr fragment: the notes the owner liked, painted by the
 * worker. Rows count toward the offset even when their note is gone.
 * @return array{html:string,has_more:bool}
 */
function ap_nostr_favourites_fragment(int $ownerUserId, int $offset, int $limit): array
{
    $offset = max(0, $offset);
    $limit = max(1, min(40, $limit));
    $res = ap_nostr_worker_call('/shadow/nostr-likes', ['owner_id' => $ownerUserId, 'offset' => $offset, 'limit' => $limit], 5000);
    $ok = !empty($res['body']['ok']) && is_array($res['body']['htmls'] ?? null);
    $htmls = $ok ? array_values(array_filter($res['body']['htmls'], 'is_string')) : [];
    $hasMore = $ok && !empty($res['body']['has_more']);
    $html = '<div data-fedi-favourites-fragment data-ajax="favourites_nostr" data-end-label="End of Nostr favourites"'
        . ' data-offset="' . ($offset + ($hasMore ? $limit : count($htmls))) . '" data-limit="' . $limit
        . '" data-has-more="' . ($hasMore ? '1' : '0') . '">';
    if (!$ok && $offset === 0) {
        $html .= '<div class="empty">Nostr favourites couldn’t load. Try again shortly.</div>';
    } elseif ($htmls === [] && $offset === 0) {
        $html .= '<div class="empty">No Nostr favourites yet. Notes you like on Home show up here.</div>';
    } else {
        $html .= implode('', $htmls);
    }
    $html .= '<div data-fedi-favourites-sentinel class="meta" style="padding:1rem 0 2rem;text-align:center">'
        . ($hasMore ? 'Scroll for more…' : '') . '</div></div>';
    return ['html' => $html, 'has_more' => $hasMore];
}

/** Search > Nostr shows for viewers with a linked npub and "Show in Home" on. */
function ap_nostr_search_visible(int $ownerUserId): bool
{
    if ($ownerUserId < 1 || getenv('VAAK_NOSTR') === '0') {
        return false;
    }
    static $memo = [];
    if (!array_key_exists($ownerUserId, $memo)) {
        $link = ap_nostr_link_for_user($ownerUserId);
        $memo[$ownerUserId] = is_array($link) && filter_var($link['show_in_home'] ?? true, FILTER_VALIDATE_BOOLEAN);
    }
    return $memo[$ownerUserId];
}

/**
 * One page of Search > Nostr: stored notes from the viewer's Nostr follows
 * that match $q, painted by the worker (nostr/search.rs). Cached for the
 * request so the tab count and the section share one call.
 *
 * @return array{ok:bool,htmls:list<string>,has_more:bool}
 */
function ap_nostr_search_page(int $ownerUserId, string $q, int $offset, int $limit): array
{
    $none = ['ok' => true, 'htmls' => [], 'has_more' => false];
    $q = trim($q);
    if ($q === '' || mb_strlen($q) > 200 || !ap_nostr_search_visible($ownerUserId)) {
        return $none;
    }
    $offset = max(0, min(400, $offset));
    $limit = max(1, min(40, $limit));
    static $memo = [];
    $key = $ownerUserId . "\0" . $q . "\0" . $offset . "\0" . $limit;
    if (isset($memo[$key])) {
        return $memo[$key];
    }
    $res = ap_nostr_worker_call('/shadow/nostr-search', ['owner_id' => $ownerUserId, 'q' => $q, 'offset' => $offset, 'limit' => $limit], 3000);
    $ok = !empty($res['body']['ok']) && is_array($res['body']['htmls'] ?? null);
    $htmls = $ok ? array_values(array_filter($res['body']['htmls'], static fn ($h): bool => is_string($h) && $h !== '')) : [];
    return $memo[$key] = ['ok' => $ok, 'htmls' => $htmls, 'has_more' => $ok && !empty($res['body']['has_more'])];
}

/**
 * Trending posts, Nostr leg: recent notes from this viewer's Nostr follows
 * with like/repost/reply counts, as statuses (nostr/counts.rs). Already
 * filtered for the viewer (mutes, hides, blocks, lists, downranks, muted
 * words). Empty without a linked npub or when the worker can't be reached.
 *
 * @return list<array<string,mixed>>
 */
function ap_nostr_trending_statuses(int $ownerUserId, int $limit = 10): array
{
    if (!ap_nostr_search_visible($ownerUserId)) {
        return [];
    }
    $res = ap_nostr_worker_call('/shadow/nostr-trending', ['owner_id' => $ownerUserId, 'limit' => max(1, min(40, $limit))], 2000);
    $statuses = $res['body']['statuses'] ?? null;
    if (empty($res['body']['ok']) || !is_array($statuses)) {
        return [];
    }
    return array_values(array_filter($statuses, static fn ($st): bool => is_array($st) && str_starts_with((string) ($st['id'] ?? ''), 'nostr:')));
}

/**
 * Trending tags, Nostr leg: kind 1 notes from the last $sinceTs onward by
 * the keys this viewer follows on Nostr (plus their own), for viewers with
 * a linked npub and "Show in Home" on. Reads only notes nostr-sync already
 * stores for Home, so it adds no relay traffic. Keys the viewer muted or
 * hid, keys on their moderation lists, keys an admin blocked, and keys the
 * viewer downranked by hand are left out. Notes whose text has no hashtag
 * and no `t` tag are skipped in SQL.
 *
 * @return list<array{pubkey:string,content:string,created_at:int,cw:string}>
 */
function ap_nostr_trend_tag_notes(int $ownerUserId, int $sinceTs, int $limit = 3000, ?PDO $db = null): array
{
    if ($ownerUserId < 1 || getenv('VAAK_NOSTR') === '0') {
        return [];
    }
    try {
        $db ??= ap_db();
        if (strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'pgsql'
            || !(bool) $db->query("SELECT to_regclass('public.nostr_links') IS NOT NULL AND to_regclass('public.nostr_events') IS NOT NULL")->fetchColumn()) {
            return [];
        }
        $link = $db->prepare('SELECT show_in_home FROM nostr_links WHERE owner_user_id = ?');
        $link->execute([$ownerUserId]);
        $show = $link->fetchColumn();
        if ($show === false || !filter_var($show, FILTER_VALIDATE_BOOLEAN)) {
            return [];
        }
        $hidden = [];
        if (ap_nostr_moderation_ready($db)) {
            $st = $db->prepare('SELECT pubkey FROM nostr_mutes WHERE owner_user_id = ? AND (on_nostr OR hidden_vaak) UNION SELECT pubkey FROM nostr_blocks');
            $st->execute([$ownerUserId]);
            foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $pk) {
                $hidden[strtolower((string) $pk)] = true;
            }
        }
        if (function_exists('ap_lists_moderation_nostr_keys')) {
            $hidden += ap_lists_moderation_nostr_keys($ownerUserId);
        }
        $st = $db->prepare("SELECT actor_id FROM ap_deprioritized_actors WHERE owner_user_id = ? AND actor_id LIKE 'nostr:%'");
        $st->execute([$ownerUserId]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) ?: [] as $actor) {
            $pk = ap_nostr_downranked_pubkey((string) $actor);
            if ($pk !== '') {
                $hidden[$pk] = true;
            }
        }
        $st = $db->prepare(
            "SELECT e.pubkey, e.content, e.created_at,
                    COALESCE((SELECT t->>1 FROM jsonb_array_elements(e.tags) t WHERE t->>0 = 'content-warning' LIMIT 1), '') AS cw,
                    COALESCE((SELECT string_agg('#' || (t->>1), ' ') FROM jsonb_array_elements(e.tags) t WHERE t->>0 = 't' AND COALESCE(t->>1, '') <> ''), '') AS ttags
             FROM nostr_events e
             WHERE e.kind = 1 AND e.created_at >= ?
               AND e.pubkey IN (SELECT pubkey FROM nostr_follows WHERE owner_user_id = ? UNION SELECT pubkey FROM nostr_links WHERE owner_user_id = ?)
               AND (e.content LIKE '%#%' OR e.tags @> '[[\"t\"]]'::jsonb)
             ORDER BY e.created_at DESC
             LIMIT ?"
        );
        $st->bindValue(1, $sinceTs, PDO::PARAM_INT);
        $st->bindValue(2, $ownerUserId, PDO::PARAM_INT);
        $st->bindValue(3, $ownerUserId, PDO::PARAM_INT);
        $st->bindValue(4, max(1, min(5000, $limit)), PDO::PARAM_INT);
        $st->execute();
        $out = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            $pk = strtolower((string) $row['pubkey']);
            if (isset($hidden[$pk])) {
                continue;
            }
            $content = (string) $row['content'];
            // A `t` tag missing from the text still counts once (the recount
            // dedupes a tag within one post).
            $tags = (string) $row['ttags'];
            $out[] = [
                'pubkey' => $pk,
                'content' => $tags === '' ? $content : $content . "\n" . $tags,
                'created_at' => (int) $row['created_at'],
                'cw' => (string) $row['cw'],
            ];
        }
        return $out;
    } catch (Throwable $e) {
        error_log('[nostr] trend notes skipped: ' . $e->getMessage());
        return [];
    }
}

/** Same rules as the worker's normalize_relay_url (nostr/relay.rs): wss only, no ports but 443, no private names. */
function ap_nostr_normalize_relay_url(string $raw): ?string
{
    $raw = trim($raw);
    if (strlen($raw) > 200 || strncasecmp($raw, 'wss://', 6) !== 0) {
        return null;
    }
    $rest = substr($raw, 6);
    if (preg_match('/[?#@\\\\\s]/', $rest)) {
        return null;
    }
    $slash = strpos($rest, '/');
    $authority = $slash === false ? $rest : substr($rest, 0, $slash);
    $path = $slash === false ? '' : substr($rest, $slash);
    $colon = strrpos($authority, ':');
    if ($colon !== false) {
        if (substr($authority, $colon + 1) !== '443') {
            return null;
        }
        $authority = substr($authority, 0, $colon);
    }
    $host = strtolower(rtrim($authority, '.'));
    if ($host === '' || strlen($host) > 120 || !str_contains($host, '.') || in_array($host[0], ['.', '-'], true)
        || !preg_match('/^[a-z0-9.-]+$/', $host) || preg_match('/^[0-9.]+$/', $host)
        || $host === 'localhost' || preg_match('/\.(localhost|local|internal)$/', $host)) {
        return null;
    }
    $path = rtrim($path, '/');
    if (!preg_match('#^[A-Za-z0-9/_.~-]*$#', $path) || str_contains($path, '..')) {
        return null;
    }
    return 'wss://' . $host . $path;
}

function ap_nostr_relays_ready(?PDO $db = null): bool
{
    try {
        $db ??= ap_db();
        $name = $db->query("SELECT to_regclass('public.nostr_relays')")->fetchColumn();
        return is_string($name) && $name !== '';
    } catch (Throwable $e) {
        return false;
    }
}

/** @return list<array<string,mixed>> configured relays first, then ones followed authors list, then removed ones */
function ap_nostr_relays_list(?PDO $db = null): array
{
    try {
        $db ??= ap_db();
        $st = $db->query('SELECT url, is_index, is_fallback, added_at, removed_at, checked_at, ok, last_ok_at, last_error, fail_streak
            FROM nostr_relays
            ORDER BY (removed_at IS NOT NULL), (NOT is_index AND NOT is_fallback), ok IS TRUE, url
            LIMIT 500');
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Admin > Relays > Nostr actions. The worker reads the table on its next
 * sync; removed relays are never contacted again until restored.
 * @return array{ok:bool,notice?:string,error?:string}
 */
function ap_nostr_relay_admin_action(string $action, array $post, ?PDO $db = null): array
{
    $url = ap_nostr_normalize_relay_url((string) ($post['relay_url'] ?? ''));
    if ($action !== 'nostr_relay_check_all' && $url === null) {
        return ['ok' => false, 'error' => 'That isn\'t a public wss:// relay address.'];
    }
    try {
        $db ??= ap_db();
        if (!ap_nostr_relays_ready($db)) {
            return ['ok' => false, 'error' => 'The Nostr relay table isn\'t set up yet. Deploy the latest release first.'];
        }
        $now = time();
        switch ($action) {
            case 'nostr_relay_add':
                $role = (string) ($post['role'] ?? 'both');
                $index = in_array($role, ['index', 'both'], true);
                $fallback = in_array($role, ['fallback', 'both'], true);
                $db->prepare('INSERT INTO nostr_relays (url, is_index, is_fallback, added_at) VALUES (?, ?, ?, ?)
                    ON CONFLICT (url) DO UPDATE SET is_index = EXCLUDED.is_index, is_fallback = EXCLUDED.is_fallback, removed_at = NULL')
                    ->execute([$url, $index ? 'true' : 'false', $fallback ? 'true' : 'false', $now]);
                $check = ap_nostr_relay_check([$url]);
                return ['ok' => true, 'notice' => 'Added ' . $url . '. ' . $check];
            case 'nostr_relay_remove':
                $db->prepare('UPDATE nostr_relays SET removed_at = ? WHERE url = ?')->execute([$now, $url]);
                return ['ok' => true, 'notice' => 'Removed ' . $url . '. VAAK won\'t connect to it again.'];
            case 'nostr_relay_restore':
                $db->prepare('UPDATE nostr_relays SET removed_at = NULL WHERE url = ?')->execute([$url]);
                return ['ok' => true, 'notice' => 'Restored ' . $url . '.'];
            case 'nostr_relay_check':
                return ['ok' => true, 'notice' => ap_nostr_relay_check([$url])];
            case 'nostr_relay_check_all':
                $urls = $db->query('SELECT url FROM nostr_relays WHERE removed_at IS NULL AND (is_index OR is_fallback) ORDER BY url LIMIT 60')
                    ->fetchAll(PDO::FETCH_COLUMN) ?: [];
                return $urls === [] ? ['ok' => false, 'error' => 'No relays to check yet.'] : ['ok' => true, 'notice' => ap_nostr_relay_check($urls)];
        }
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update Nostr relays.'];
    }
    return ['ok' => false, 'error' => 'Unknown relay action.'];
}

/** Probe relays through the worker (it records the result); a one-line summary. */
function ap_nostr_relay_check(array $urls): string
{
    $res = ap_nostr_worker_call('/shadow/nostr-relay-check', ['urls' => array_values($urls)], 15000);
    $results = $res['body']['results'] ?? null;
    if (empty($res['body']['ok']) || !is_array($results)) {
        return 'The relay check couldn\'t run right now.';
    }
    if (count($results) === 1) {
        $r = $results[0];
        return !empty($r['ok']) ? 'It answered.' : 'It did not answer: ' . mb_substr((string) ($r['error'] ?? ''), 0, 160);
    }
    $up = count(array_filter($results, static fn ($r): bool => !empty($r['ok'])));
    return $up . ' of ' . count($results) . ' relays answered.';
}

function ap_nostr_relay_age(int $at, int $now): string
{
    if ($at <= 0) {
        return 'never';
    }
    $d = max(0, $now - $at);
    return match (true) {
        $d < 90 => 'just now',
        $d < 5400 => (int) round($d / 60) . ' min ago',
        $d < 129600 => (int) round($d / 3600) . ' h ago',
        default => (int) round($d / 86400) . ' d ago',
    };
}

/** Admin > Relays > Nostr body. */
function ap_nostr_relays_tab_html(string $csrf, ?PDO $db = null): string
{
    $e = static fn (mixed $s): string => htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $action = '?view=relays&amp;network=nostr';
    $hidden = '<input type="hidden" name="csrf" value="' . $e($csrf) . '">';
    $btn = static function (string $act, string $url, string $label, string $class = 'btn-ghost', string $confirm = '') use ($e, $action, $hidden): string {
        return '<form method="post" action="' . $action . '" style="display:inline"'
            . ($confirm !== '' ? ' onsubmit="return confirm(\'' . $e($confirm) . '\');"' : '') . '>'
            . '<input type="hidden" name="action" value="' . $e($act) . '"><input type="hidden" name="relay_url" value="' . $e($url) . '">' . $hidden
            . '<button class="btn ' . $class . '" type="submit" style="padding:.25rem .7rem;font-size:.8rem">' . $e($label) . '</button></form>';
    };
    $h = '<div class="tweet" style="margin-bottom:1rem"><div class="who">Nostr relays</div>'
        . '<div class="body meta" style="margin-top:.45rem">VAAK reads profiles and follow lists from <b>index</b> relays, and notes from each author\'s own relays, '
        . 'using <b>fallback</b> relays when an author lists none (likes, replies and boosts are published there too). '
        . 'The dot shows whether the relay answered the last time VAAK asked. Removing a relay stops VAAK from ever connecting to it, '
        . 'even when an author lists it.</div></div>';
    if (!ap_nostr_relays_ready($db)) {
        return $h . '<div class="empty">The Nostr relay table isn\'t set up yet. Deploy the latest release first.</div>';
    }
    $h .= '<form class="composer" method="post" action="' . $action . '" style="margin-bottom:1rem">'
        . '<input type="hidden" name="action" value="nostr_relay_add">' . $hidden
        . '<input type="text" name="relay_url" required placeholder="wss://relay.example" autocomplete="off" spellcheck="false">'
        . '<div class="composer-actions"><select name="role" aria-label="Use as">'
        . '<option value="both">Index and fallback</option><option value="index">Index only</option><option value="fallback">Fallback only</option></select>'
        . '<button class="btn btn-primary" type="submit">Add relay</button></div></form>';
    $rows = ap_nostr_relays_list($db);
    if ($rows === []) {
        return $h . '<div class="empty">No relays recorded yet. They show up after the next Nostr sync.</div>';
    }
    $h .= '<div style="margin:0 0 1rem">' . $btn('nostr_relay_check_all', '', 'Check all index and fallback relays') . '</div>';
    $now = time();
    $section = '';
    foreach ($rows as $r) {
        $removed = $r['removed_at'] !== null;
        $index = in_array($r['is_index'], [true, 't', '1', 1], true);
        $fallback = in_array($r['is_fallback'], [true, 't', '1', 1], true);
        $group = $removed ? 'Removed' : (($index || $fallback) ? 'In use' : 'Listed by people you follow');
        if ($group !== $section) {
            $section = $group;
            $h .= '<h3 style="font-size:.95rem;color:var(--muted);margin:1.2rem 0 .5rem">' . $e($group) . '</h3>';
        }
        $checked = (int) $r['checked_at'];
        $ok = $r['ok'] === null ? null : in_array($r['ok'], [true, 't', '1', 1], true);
        [$color, $state] = $removed ? ['#6b7280', 'removed'] : ($checked === 0 || $ok === null ? ['#6b7280', 'not checked yet'] : ($ok ? ['#22c55e', 'answering'] : ['#ef4444', 'not answering']));
        $url = (string) $r['url'];
        $h .= '<article class="tweet nostr-relay" data-relay-url="' . $e($url) . '"><div class="who">'
            . '<span class="nostr-relay-dot" role="img" aria-label="' . $e($state) . '" title="' . $e($state) . '" style="display:inline-block;width:.7rem;height:.7rem;border-radius:50%;background:' . $color . ';margin-right:.45rem;vertical-align:middle"></span>'
            . $e($url);
        if ($index) {
            $h .= ' <span class="tag" style="margin-left:.35rem">index</span>';
        }
        if ($fallback) {
            $h .= ' <span class="tag" style="margin-left:.35rem">fallback</span>';
        }
        $h .= '</div><div class="body meta" style="margin-top:.35rem">';
        if ($removed) {
            $h .= 'removed ' . $e(ap_nostr_relay_age((int) $r['removed_at'], $now));
        } else {
            $h .= 'checked ' . $e(ap_nostr_relay_age($checked, $now)) . ' · last answered ' . $e(ap_nostr_relay_age((int) $r['last_ok_at'], $now));
            if ($ok === false) {
                $h .= ' · failed ' . (int) $r['fail_streak'] . 'x in a row';
                if ((string) $r['last_error'] !== '') {
                    $h .= '<br><code style="font-size:.75rem;word-break:break-all">' . $e(mb_substr((string) $r['last_error'], 0, 300)) . '</code>';
                }
            }
        }
        $h .= '</div><div class="tweet-actions">';
        if ($removed) {
            $h .= $btn('nostr_relay_restore', $url, 'Restore');
        } else {
            $h .= $btn('nostr_relay_check', $url, 'Check now') . ' '
                . $btn('nostr_relay_remove', $url, 'Remove', 'btn-ghost', 'Remove this Nostr relay? VAAK will stop connecting to it.');
        }
        $h .= '</div></article>';
    }
    return $h;
}

/** True once the moderation migration (nostr_mutes, nostr_blocks) ran. */
function ap_nostr_moderation_ready(?PDO $db = null): bool
{
    try {
        $db ??= ap_db();
        if (strtolower((string) $db->getAttribute(PDO::ATTR_DRIVER_NAME)) !== 'pgsql') {
            return false;
        }
        return (bool) $db->query("SELECT to_regclass('public.nostr_mutes') IS NOT NULL AND to_regclass('public.nostr_blocks') IS NOT NULL")->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * VAAK-side moderation of a Nostr key, no signing needed: Hide on VAAK
 * (only for this user, never published) and the admin Block on VAAK (for
 * every user). Mute on Nostr is signed instead (ap_nostr_action, purpose
 * mute). Home and every timeline drop cards by these keys
 * (nostr/moderation.rs).
 *
 * @param array<string,mixed> $post
 * @return array{status:int, body:array<string,mixed>}
 */
function ap_nostr_moderation_action(string $action, int $ownerUserId, bool $isAdmin, array $post, ?PDO $db = null): array
{
    $fail = static fn (int $status, string $error): array => ['status' => $status, 'body' => ['ok' => false, 'error' => $error]];
    if (!ap_nostr_form_visible($isAdmin)) {
        return $fail(403, 'Nostr is not open on this server yet.');
    }
    if ($ownerUserId < 1) {
        return $fail(401, 'Sign in first.');
    }
    $target = strtolower(trim((string) ($post['target'] ?? '')));
    if (!preg_match('/^[0-9a-f]{64}$/', $target)) {
        return $fail(400, 'That isn\'t a Nostr account.');
    }
    if (in_array($action, ['nostr_block', 'nostr_unblock'], true) && !$isAdmin) {
        return $fail(403, 'Only admins can block on VAAK.');
    }
    try {
        $db ??= ap_db();
        if (!ap_nostr_moderation_ready($db)) {
            return $fail(503, 'Nostr moderation isn\'t set up on this server yet.');
        }
        $now = time();
        switch ($action) {
            case 'nostr_hide':
                $mine = ap_nostr_link_for_user($ownerUserId, $db);
                if (is_array($mine) && (string) ($mine['pubkey'] ?? '') === $target) {
                    return $fail(400, 'That\'s you.');
                }
                $db->prepare('INSERT INTO nostr_mutes (owner_user_id, pubkey, hidden_vaak, created_at) VALUES (?, ?, TRUE, ?)
                    ON CONFLICT (owner_user_id, pubkey) DO UPDATE SET hidden_vaak = TRUE')->execute([$ownerUserId, $target, $now]);
                break;
            case 'nostr_unhide':
                $db->prepare('UPDATE nostr_mutes SET hidden_vaak = FALSE WHERE owner_user_id = ? AND pubkey = ?')->execute([$ownerUserId, $target]);
                $db->prepare('DELETE FROM nostr_mutes WHERE owner_user_id = ? AND pubkey = ? AND NOT on_nostr AND NOT hidden_vaak')->execute([$ownerUserId, $target]);
                break;
            case 'nostr_block':
                $reason = mb_substr(trim((string) ($post['reason'] ?? '')), 0, 300);
                $db->prepare('INSERT INTO nostr_blocks (pubkey, blocked_by, reason, created_at) VALUES (?, ?, ?, ?)
                    ON CONFLICT (pubkey) DO NOTHING')->execute([$target, $ownerUserId, $reason, $now]);
                break;
            case 'nostr_unblock':
                $db->prepare('DELETE FROM nostr_blocks WHERE pubkey = ?')->execute([$target]);
                break;
            default:
                return $fail(400, 'Unknown Nostr action.');
        }
    } catch (Throwable $e) {
        error_log('[nostr] moderation failed: ' . $e->getMessage());
        return $fail(500, 'VAAK couldn\'t save that. Try again.');
    }
    return ['status' => 200, 'body' => ['ok' => true, 'purpose' => $action]];
}

/**
 * Names for Nostr keys from stored kind 0 profiles, falling back to a
 * short npub.
 *
 * @param list<string> $pubkeys
 * @return array<string, array{name:string, npub:string}>
 */
function ap_nostr_names(array $pubkeys, ?PDO $db = null): array
{
    $keys = array_values(array_unique(array_filter(array_map(static fn ($k): string => strtolower((string) $k), $pubkeys), static fn (string $k): bool => (bool) preg_match('/^[0-9a-f]{64}$/', $k))));
    $out = [];
    foreach ($keys as $k) {
        $npub = ap_nostr_npub($k);
        $out[$k] = ['name' => substr($npub, 0, 10) . '…' . substr($npub, -6), 'npub' => $npub];
    }
    if ($keys === []) {
        return $out;
    }
    try {
        $db ??= ap_db();
        $marks = implode(',', array_fill(0, count($keys), '?'));
        $st = $db->prepare("SELECT pubkey, COALESCE(display_name, ''), COALESCE(name, '') FROM nostr_profiles WHERE pubkey IN ($marks)");
        $st->execute($keys);
        foreach ($st->fetchAll(PDO::FETCH_NUM) ?: [] as $row) {
            $name = trim((string) $row[1]) !== '' ? trim((string) $row[1]) : trim((string) $row[2]);
            if ($name !== '' && isset($out[(string) $row[0]])) {
                $out[(string) $row[0]]['name'] = mb_substr($name, 0, 80);
            }
        }
    } catch (Throwable $e) {
    }
    return $out;
}

/**
 * Follow / Follow back for a Nostr account in a notification row (PHP
 * painter; the worker paints the same button in mentions_html.rs). It signs
 * a kind 3 edit through the viewer's signer (data-nostr-act), never VAAK's
 * fediverse follow. 'nostr' says the actor is a Nostr key, so the caller
 * skips its fediverse forms; 'html' is '' when the viewer has no linked npub.
 *
 * @return array{nostr:bool,html:string}
 */
function ap_nostr_notif_follow_html(string $actorRef, int $ownerUserId, string $name, bool $followBack, ?PDO $db = null): array
{
    $ref = rtrim(trim($actorRef), '/');
    if (!preg_match('~^(?:https://njump\.me/|nostr:)(npub1[02-9ac-hj-np-z]+)$~i', $ref, $m)) {
        return ['nostr' => false, 'html' => ''];
    }
    $parsed = ap_nostr_parse_pubkey($m[1]);
    $pubkey = (string) ($parsed['pubkey'] ?? '');
    if (empty($parsed['ok']) || !preg_match('/^[0-9a-f]{64}$/', $pubkey) || $ownerUserId < 1) {
        return ['nostr' => true, 'html' => ''];
    }
    $following = false;
    try {
        $db ??= ap_db();
        if (!ap_nostr_table_ready($db)) {
            return ['nostr' => true, 'html' => ''];
        }
        $mine = strtolower((string) (ap_nostr_link_for_user($ownerUserId, $db)['pubkey'] ?? ''));
        if ($mine === '' || $mine === $pubkey) {
            return ['nostr' => true, 'html' => ''];
        }
        $st = $db->prepare('SELECT 1 FROM nostr_follows WHERE owner_user_id = ? AND pubkey = ? LIMIT 1');
        $st->execute([$ownerUserId, $pubkey]);
        $following = (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return ['nostr' => true, 'html' => ''];
    }
    $follow = $followBack ? 'Follow back' : 'Follow';
    $label = $following ? 'Following' : $follow;
    $html = '<button type="button" class="btn btn-ghost' . ($following ? ' btn-following' : '') . '"'
        . ' data-nostr-act="' . ($following ? 'unfollow' : 'follow') . '"'
        . ' data-nostr-target="' . $pubkey . '"'
        . ' data-nostr-follow-label="' . htmlspecialchars($follow, ENT_QUOTES) . '"'
        . ' data-nostr-unfollow-label="Following"'
        . ' data-nostr-name="' . htmlspecialchars(mb_substr($name, 0, 80), ENT_QUOTES) . '"'
        . ' title="' . ($following ? 'Following on Nostr — click to unfollow' : htmlspecialchars($follow, ENT_QUOTES) . ' on Nostr') . '"'
        . ' style="padding:.25rem .7rem;font-size:.8rem">' . $label . '</button>';
    return ['nostr' => true, 'html' => $html];
}

/**
 * Hover card data for a Nostr account (njump npub URL), from stored kind 0
 * metadata and the viewer's stored follow list. Nothing is fetched inline.
 *
 * @return array<string,mixed>
 */
function ap_nostr_hover_payload(string $actorUrl, int $ownerUserId, ?PDO $db = null): array
{
    if (!preg_match('~^https://njump\.me/(npub1[02-9ac-hj-np-z]+)$~i', rtrim(trim($actorUrl), '/'), $m)) {
        return ['ok' => false, 'error' => 'Invalid profile'];
    }
    $parsed = ap_nostr_parse_pubkey($m[1]);
    $pubkey = (string) ($parsed['pubkey'] ?? '');
    if (empty($parsed['ok']) || !preg_match('/^[0-9a-f]{64}$/', $pubkey)) {
        return ['ok' => false, 'error' => 'Invalid profile'];
    }
    $npub = ap_nostr_npub($pubkey);
    $short = substr($npub, 0, 10) . '…' . substr($npub, -6);
    $display = '';
    $handle = '';
    $avatar = '';
    $following = false;
    $own = false;
    $linked = false;
    try {
        $db ??= ap_db();
        if (ap_nostr_table_ready($db)) {
            $st = $db->prepare("SELECT COALESCE(name, ''), COALESCE(display_name, ''), COALESCE(picture, ''), COALESCE(nip05, '') FROM nostr_profiles WHERE pubkey = ? LIMIT 1");
            $st->execute([$pubkey]);
            $row = $st->fetch(PDO::FETCH_NUM);
            if (is_array($row)) {
                $display = trim((string) $row[1]) !== '' ? trim((string) $row[1]) : trim((string) $row[0]);
                $avatar = str_starts_with((string) $row[2], 'https://') ? (string) $row[2] : '';
                $nip05 = preg_replace('/^_@/', '', trim((string) $row[3]));
                if (is_string($nip05) && $nip05 !== '' && str_contains($nip05, '.') && !preg_match('/\s/', $nip05)) {
                    $handle = '@' . $nip05;
                }
            }
            $link = ap_nostr_link_for_user($ownerUserId, $db);
            $mine = strtolower((string) ($link['pubkey'] ?? ''));
            $linked = $mine !== '';
            $own = $linked && $mine === $pubkey;
            if ($linked && !$own) {
                $st = $db->prepare('SELECT 1 FROM nostr_follows WHERE owner_user_id = ? AND pubkey = ? LIMIT 1');
                $st->execute([$ownerUserId, $pubkey]);
                $following = (bool) $st->fetchColumn();
            }
        }
    } catch (Throwable $e) {
        // A card with just the npub is still useful.
    }
    return [
        'ok' => true,
        'loading' => false,
        'actor' => 'https://njump.me/' . $npub,
        'profile_url' => 'https://njump.me/' . $npub,
        'platform' => 'nostr',
        'nostr_pubkey' => $pubkey,
        'display_name' => mb_substr($display !== '' ? $display : $short, 0, 80),
        'handle' => $handle !== '' ? $handle : $short,
        'avatar' => $avatar,
        'bio' => '',
        'following' => $following,
        'follows_you' => false,
        'own' => $own,
        'can_follow' => $linked && !$own,
    ];
}

/**
 * Settings > Profile list of the user's Nostr mutes and VAAK hides (and,
 * for admins, server blocks), with undo buttons the Home card script runs.
 */
function ap_nostr_moderation_settings_html(int $ownerUserId, bool $isAdmin, ?PDO $db = null): string
{
    try {
        $db ??= ap_db();
        if ($ownerUserId < 1 || !ap_nostr_moderation_ready($db)) {
            return '';
        }
        $st = $db->prepare('SELECT pubkey, on_nostr, hidden_vaak FROM nostr_mutes WHERE owner_user_id = ? ORDER BY created_at DESC, pubkey LIMIT 500');
        $st->execute([$ownerUserId]);
        $mutes = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        $blocks = $isAdmin ? ($db->query('SELECT pubkey, reason FROM nostr_blocks ORDER BY created_at DESC, pubkey LIMIT 500')->fetchAll(PDO::FETCH_ASSOC) ?: []) : [];
    } catch (Throwable $e) {
        return '';
    }
    $names = ap_nostr_names(array_merge(array_column($mutes, 'pubkey'), array_column($blocks, 'pubkey')), $db);
    $esc = static fn (string $v): string => htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $who = static function (string $pk) use ($names, $esc): string {
        $n = $names[$pk] ?? ['name' => $pk, 'npub' => ''];
        return '<a href="https://njump.me/' . $esc($n['npub']) . '" target="_blank" rel="noopener noreferrer">' . $esc($n['name']) . '</a>';
    };
    $btn = static fn (string $attr, string $pk, string $label): string => '<button type="button" class="btn btn-ghost" style="padding:.15rem .6rem;font-size:.8rem" ' . $attr . ' data-nostr-target="' . $esc($pk) . '">' . $esc($label) . '</button>';
    $html = '<details class="nostr-moderation"><summary class="meta">Muted and hidden on Nostr (' . count($mutes) . ')</summary>';
    if ($mutes === []) {
        $html .= '<div class="meta">Nobody yet. Use ⋯ on a Nostr post to mute someone on Nostr (your signer signs your mute list) or hide them on VAAK only.</div>';
    }
    foreach ($mutes as $m) {
        $pk = (string) $m['pubkey'];
        $how = [];
        $buttons = '';
        if (!empty($m['on_nostr'])) {
            $how[] = 'muted on Nostr';
            $buttons .= $btn('data-nostr-act="unmute"', $pk, 'Unmute');
        }
        if (!empty($m['hidden_vaak'])) {
            $how[] = 'hidden on VAAK';
            $buttons .= $btn('data-nostr-vaak="nostr_unhide"', $pk, 'Unhide');
        }
        $html .= '<div class="meta nostr-moderation-row" data-nostr-row="' . $esc($pk) . '" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">' . $who($pk) . ' · ' . $esc(implode(', ', $how)) . ' ' . $buttons . '</div>';
    }
    $html .= '</details>';
    if ($isAdmin) {
        $html .= '<details class="nostr-moderation"><summary class="meta">Blocked on VAAK for everyone (' . count($blocks) . ')</summary>';
        foreach ($blocks as $b) {
            $pk = (string) $b['pubkey'];
            $reason = trim((string) ($b['reason'] ?? ''));
            $html .= '<div class="meta nostr-moderation-row" data-nostr-row="' . $esc($pk) . '" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">' . $who($pk) . ($reason !== '' ? ' · ' . $esc($reason) : '') . ' ' . $btn('data-nostr-vaak="nostr_unblock"', $pk, 'Unblock') . '</div>';
        }
        $html .= '</details>';
    }
    return $html;
}

/** Hex pubkey from a `nostr:{hex}` downranking actor, else ''. */
function ap_nostr_downranked_pubkey(string $actorId): string
{
    return preg_match('/^nostr:([0-9a-f]{64})$/', trim($actorId), $m) ? $m[1] : '';
}

/**
 * Admin > Downranked evidence for a Nostr author: their stored notes that
 * raised the signal (`seen` holds `nostr:{note id}`), else recent matching
 * notes.
 *
 * @param list<string> $categories
 * @param list<string> $seen
 * @return list<array{object_id:string,href:string,summary:string,categories:list<string>}>
 */
function ap_nostr_downranked_evidence(string $pubkey, array $categories, array $seen, int $limit = 5, ?PDO $db = null): array
{
    $wanted = array_values(array_filter(array_map('strval', $categories)));
    if (!preg_match('/^[0-9a-f]{64}$/', $pubkey) || $wanted === []) {
        return [];
    }
    $seenIds = [];
    foreach ($seen as $s) {
        if (preg_match('/^nostr:([0-9a-f]{64})$/', (string) $s, $m)) {
            $seenIds[$m[1]] = true;
        }
    }
    try {
        $db ??= ap_db();
        $st = $db->prepare('SELECT id, content FROM nostr_events WHERE pubkey = ? AND kind = 1 ORDER BY created_at DESC, id DESC LIMIT 100');
        $st->execute([$pubkey]);
        $out = [];
        foreach ($st->fetchAll(PDO::FETCH_NUM) ?: [] as $row) {
            $text = trim((string) $row[1]);
            if ($text === '') {
                continue;
            }
            $found = function_exists('admin_home_toxicity_categories') ? admin_home_toxicity_categories($text) : [];
            $matched = array_values(array_intersect($wanted, $found));
            if ($matched === [] && isset($seenIds[(string) $row[0]])) {
                $matched = $wanted;
            }
            if ($matched === []) {
                continue;
            }
            $out[] = [
                'object_id' => 'nostr:' . $row[0],
                'href' => 'https://njump.me/' . ap_nostr_bech32_encode('note', (string) hex2bin((string) $row[0])),
                'summary' => $text,
                'categories' => $matched,
            ];
            if (count($out) >= $limit) {
                break;
            }
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}


/** Keys one "Follow" press may add (nostr/pack.rs MAX_FOLLOW_PICKS). */
const AP_NOSTR_PACK_MAX_PICKS = 150;

/** A follow pack address: `39089:<hex pubkey>:<d tag>` (or 39092). */
function ap_nostr_pack_addr_ok(string $addr): bool
{
    return strlen($addr) <= 300 && (bool) preg_match('/^(39089|39092):[0-9a-f]{64}:[^\x00-\x1f]{1,200}$/u', $addr);
}

/**
 * Comma-separated hex keys from the pack page, lowercased, once each.
 * Anything that isn't a key is dropped.
 *
 * @return list<string>
 */
function ap_nostr_pack_picks(string $raw): array
{
    $out = [];
    foreach (explode(',', strtolower(substr($raw, 0, 200 * 66))) as $k) {
        $k = trim($k);
        if (preg_match('/^[0-9a-f]{64}$/', $k)) {
            $out[$k] = true;
        }
    }
    return array_keys($out);
}

/**
 * Library > Collections: a Nostr follow pack from a pasted link, fetched and
 * checked by the worker (`/shadow/nostr-pack`, nostr/pack.rs) and cached
 * there for half an hour.
 *
 * @return array<string,mixed>
 */
function ap_nostr_pack_open(int $ownerUserId, string $link, bool $refresh = false): array
{
    $link = trim($link);
    if ($ownerUserId < 1 || $link === '' || strlen($link) > 4000) {
        return ['ok' => false, 'error' => 'Paste a follow pack link first.'];
    }
    $res = ap_nostr_worker_call('/shadow/nostr-pack', ['owner_id' => $ownerUserId, 'link' => $link, 'refresh' => $refresh], 25000);
    $body = is_array($res['body'] ?? null) ? $res['body'] : [];
    if (empty($body['ok'])) {
        return ['ok' => false, 'error' => (string) ($body['error'] ?? 'VAAK couldn\'t open that pack. Try again.')];
    }
    return $body;
}

/**
 * The pack page: title, author, members with checkboxes, and the Follow
 * button the Home card script (ap-admin.php, nostr actions) signs and sends.
 *
 * @param array<string,mixed> $res ap_nostr_pack_open() answer
 */
function ap_nostr_pack_html(array $res, string $link): string
{
    $e = static fn ($v): string => htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $fallback = defined('AP_REMOTE_AVATAR_FALLBACK') ? (string) AP_REMOTE_AVATAR_FALLBACK : 'https://mkultra.monster/img/avatar/default.jpg';
    $back = '<div class="page-back" style="margin-bottom:.75rem"><a class="btn btn-ghost" href="?view=collections">← All collections</a></div>';
    if (empty($res['ok'])) {
        return $back . '<div class="empty">' . $e($res['error'] ?? 'Couldn\'t open that pack.') . '</div>';
    }
    $pack = is_array($res['pack'] ?? null) ? $res['pack'] : [];
    $author = is_array($pack['author'] ?? null) ? $pack['author'] : [];
    $members = is_array($res['members'] ?? null) ? $res['members'] : [];
    $linked = !empty($res['linked']);
    $max = (int) ($res['max_picks'] ?? AP_NOSTR_PACK_MAX_PICKS);
    $nameOf = static function (array $p): string {
        $name = trim((string) ($p['name'] ?? ''));
        $npub = (string) ($p['npub'] ?? '');
        return $name !== '' ? $name : (substr($npub, 0, 12) . '…' . substr($npub, -6));
    };
    $avatar = static function (array $p, string $class) use ($e, $fallback): string {
        $src = (string) ($p['picture'] ?? '');
        if (!str_starts_with($src, 'https://')) {
            $src = $fallback;
        }
        return '<img class="' . $class . '" src="' . $e($src) . '" alt="" width="40" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.onerror=null;this.src=' . $e(json_encode($fallback)) . '">';
    };
    $open = 0;
    foreach ($members as $m) {
        if (is_array($m) && empty($m['following']) && empty($m['me'])) {
            $open++;
        }
    }
    $title = trim((string) ($pack['title'] ?? '')) !== '' ? (string) $pack['title'] : 'Untitled follow pack';
    $naddr = (string) ($pack['naddr'] ?? '');
    $out = $back;
    $out .= '<article class="tweet nostr-pack-head">';
    if (str_starts_with((string) ($pack['image'] ?? ''), 'https://')) {
        $out .= '<img class="nostr-pack-banner" src="' . $e($pack['image']) . '" alt="" loading="lazy" decoding="async" referrerpolicy="no-referrer" onerror="this.remove()">';
    }
    $out .= '<div class="tweet-hd">' . $avatar($author, 'tweet-av') . '<div class="tweet-hd-main"><div><span class="who">' . $e($title) . '</span> <span class="tag">Nostr</span></div>'
        . '<div class="meta">Follow pack by <a href="https://njump.me/' . $e($author['npub'] ?? '') . '" target="_blank" rel="noopener noreferrer">' . $e($nameOf($author)) . '</a> · ' . count($members) . ' people</div></div></div>';
    if (trim((string) ($pack['description'] ?? '')) !== '') {
        $out .= '<div class="body" style="margin:.35rem 0;white-space:pre-line">' . $e($pack['description']) . '</div>';
    }
    if ((int) ($pack['dropped'] ?? 0) > 0) {
        $out .= '<div class="meta">This pack lists ' . (int) $pack['dropped'] . ' more people than VAAK shows (' . count($members) . ' max).</div>';
    }
    $out .= '<div class="tweet-actions" style="gap:.75rem">';
    if ($naddr !== '') {
        $out .= '<a href="https://njump.me/' . $e($naddr) . '" target="_blank" rel="noopener noreferrer">Open on njump</a>';
        $parts = explode(':', (string) ($pack['addr'] ?? ''), 3);
        if (count($parts) === 3 && $parts[0] === '39089') {
            $out .= '<a href="https://following.space/d/' . $e(rawurlencode($parts[2])) . '?p=' . $e($parts[1]) . '" target="_blank" rel="noopener noreferrer">Open on following.space</a>';
        }
    }
    $out .= '<a href="?view=collections&amp;nostr_pack=' . $e(rawurlencode($link)) . '&amp;nostr_pack_refresh=1">Refresh</a>';
    $out .= '</div></article>';

    $out .= '<form class="nostr-pack-form" data-ajax-submit="1" data-nostr-pack="' . $e($pack['addr'] ?? '') . '" data-nostr-pack-max="' . $max . '" onsubmit="return false">';
    if (!$linked) {
        $out .= '<div class="empty" style="margin:.75rem 0">Link your npub in <a href="?view=profile">Settings &gt; Profile</a> to follow people from this pack.</div>';
    } elseif ($open === 0) {
        $out .= '<div class="meta" style="margin:.75rem 0">You already follow everyone in this pack.</div>';
    } else {
        $out .= '<div class="composer-actions nostr-pack-bar" style="margin:.75rem 0;gap:.5rem;flex-wrap:wrap">'
            . '<label class="composer-check"><input type="checkbox" data-nostr-pack-all checked> <span>Select everyone you don\'t follow yet</span></label>'
            . '<button class="btn btn-primary" type="button" data-nostr-pack-follow>Follow <span data-nostr-pack-count>' . min($open, $max) . '</span> on Nostr</button></div>'
            . '<div class="meta" style="margin:-.25rem 0 .75rem">One signature adds everyone you picked to your Nostr follow list (up to ' . $max . ' at a time). Their posts reach Home after the next Nostr sync.</div>';
    }
    $checked = 0;
    foreach ($members as $m) {
        if (!is_array($m) || !preg_match('/^[0-9a-f]{64}$/', (string) ($m['pubkey'] ?? ''))) {
            continue;
        }
        $done = !empty($m['following']) || !empty($m['me']);
        $tick = !$done && $linked && $checked < $max;
        if ($tick) {
            $checked++;
        }
        $npub = (string) ($m['npub'] ?? '');
        $out .= '<label class="tweet nostr-pack-member" style="display:block;cursor:' . ($done || !$linked ? 'default' : 'pointer') . '"><div class="tweet-hd">';
        if ($linked) {
            $out .= '<input type="checkbox" name="nostr_pack_pick" value="' . $e($m['pubkey']) . '"' . ($tick ? ' checked' : '') . ($done ? ' disabled' : '') . ' aria-label="' . $e('Follow ' . $nameOf($m)) . '" style="align-self:center;margin-right:.5rem">';
        }
        $out .= $avatar($m, 'tweet-av') . '<div class="tweet-hd-main"><div><span class="who">' . $e($nameOf($m)) . '</span>';
        if (!empty($m['me'])) {
            $out .= ' <span class="tag">you</span>';
        } elseif (!empty($m['following'])) {
            $out .= ' <span class="tag" data-nostr-pack-tag>following</span>';
        }
        $out .= '</div><div class="meta">';
        $out .= trim((string) ($m['nip05'] ?? '')) !== '' ? $e(preg_replace('/^_@/', '', (string) $m['nip05'])) . ' · ' : '';
        $out .= '<a href="https://njump.me/' . $e($npub) . '" target="_blank" rel="noopener noreferrer">' . $e(substr($npub, 0, 14) . '…') . '</a></div></div></div></label>';
    }
    return $out . '</form>';
}
