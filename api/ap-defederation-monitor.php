<?php
/**
 * Daily public-blocklist watch for mkultra.monster (and optional related hosts).
 *
 * Port of the Mastodon check-defederation.sh monitor: fetch known lists, see if
 * watched domains appear, DM @cmdr_nova@mkultra.monster with the result.
 *
 * Usage:
 *   php ap-defederation-monitor.php
 *   php ap-defederation-monitor.php --dry-run
 *   php ap-defederation-monitor.php --quiet-ok   # only DM on alert / first check-fail
 *
 * Cron (recommended):
 *   20 9 * * * www-data /usr/bin/php /srv/mkultra/html/api/ap-defederation-monitor.php >> /var/log/mkultra/ap-defederation.log 2>&1
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

require_once __DIR__ . '/ap-db.php';
if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-inbox.php';
require_once __DIR__ . '/ap-webpush.php';

$dryRun = in_array('--dry-run', $argv, true);
$quietOk = in_array('--quiet-ok', $argv, true);

const AP_DEFED_MONITOR_PEER = 'https://mkultra.monster/users/vaak_monitor';
const AP_DEFED_STATE_FILE = '/var/lib/mkultra/ap/defederation-monitor-state';
const AP_DEFED_LOCK = '/tmp/ap-defederation-monitor.lock';

/** Domains to watch (exact CSV / line match — not substrings). */
$watchDomains = [
    'mkultra.monster',
];

$gardenfenceUrls = [
    'https://raw.githubusercontent.com/gardenfence/blocklist/main/gardenfence-mastodon.csv',
    'https://github.com/gardenfence/blocklist/raw/refs/heads/main/gardenfence-mastodon.csv',
    'https://codeberg.org/oliphant/blocklists/raw/branch/main/blocklists/mastodon/gardenfence.csv',
];
$seirdyTier0Url = 'https://seirdy.one/pb/tier0.csv';
$oliphantTier0Urls = [
    'https://codeberg.org/oliphant/blocklists/raw/branch/main/blocklists/mastodon/_unified_tier0_blocklist.csv',
    'https://codeberg.org/oliphant/blocklists/raw/branch/main/blocklists/_unified_tier0_blocklist.csv',
];
$badspaceExportUrls = [
    'https://tweaking.thebad.space/exports/mastodon/20',
    'https://tweaking.thebad.space/exports/mastodon/50',
];

$lockFh = @fopen(AP_DEFED_LOCK, 'c+');
if ($lockFh === false || !flock($lockFh, LOCK_EX | LOCK_NB)) {
    fwrite(STDOUT, sprintf("[%s] defederation monitor busy\n", gmdate('c')));
    exit(0);
}

function ap_defed_log(string $msg): void
{
    fwrite(STDOUT, sprintf("[%s] %s\n", gmdate('c'), $msg));
}

/**
 * HTTPS GET preferring IPv4 (AAAA hangs on some hosts).
 */
function ap_defed_fetch(string $url, int $timeoutSec = 45): string
{
    $url = trim($url);
    if ($url === '' || !str_starts_with($url, 'https://')) {
        return '';
    }
    if (!function_exists('curl_init')) {
        return '';
    }
    $attempts = [];
    if (defined('CURL_IPRESOLVE_V4')) {
        $attempts[] = CURL_IPRESOLVE_V4;
    }
    $attempts[] = CURL_IPRESOLVE_WHATEVER;
    foreach ($attempts as $ipMode) {
        $ch = curl_init($url);
        if ($ch === false) {
            continue;
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 4,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeoutSec,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_IPRESOLVE => $ipMode,
            CURLOPT_USERAGENT => 'VAAK-defederation-monitor/1.0 (+https://mkultra.monster)',
            CURLOPT_HTTPHEADER => ['Accept: text/csv, text/plain, */*'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if (is_string($body) && $body !== '' && $code >= 200 && $code < 300) {
            return $body;
        }
    }
    return '';
}

/**
 * Return first watched domain found in list content, or null.
 *
 * @param list<string> $watchDomains
 */
function ap_defed_content_hit(string $content, array $watchDomains): ?string
{
    if ($content === '') {
        return null;
    }
    $lines = preg_split("/\r\n|\n|\r/", $content) ?: [];
    foreach ($watchDomains as $domain) {
        $domain = strtolower(trim($domain));
        if ($domain === '') {
            continue;
        }
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            // CSV: domain is first field
            $first = strtolower(trim(explode(',', $line, 2)[0]));
            if ($first === $domain) {
                return $domain;
            }
            // Plain domain-per-line
            if (strtolower($line) === $domain) {
                return $domain;
            }
            // Domain as its own CSV field (not substring)
            $fields = array_map(static fn ($f) => strtolower(trim($f)), explode(',', $line));
            if (in_array($domain, $fields, true)) {
                return $domain;
            }
        }
    }
    return null;
}

/**
 * @param list<string> $urls
 * @param list<string> $watchDomains
 * @return array{status:string,detail?:string} status=ok|hit|fail
 */
function ap_defed_check_urls(string $name, array $urls, array $watchDomains): array
{
    ap_defed_log("Checking $name");
    foreach ($urls as $url) {
        $content = ap_defed_fetch($url);
        if ($content === '') {
            ap_defed_log("WARNING: download failed for $name from $url");
            continue;
        }
        $hit = ap_defed_content_hit($content, $watchDomains);
        if ($hit !== null) {
            ap_defed_log("ALERT: found on $name domain=$hit (via $url)");
            return ['status' => 'hit', 'detail' => "$name ($hit)"];
        }
        ap_defed_log("OK: not found on $name");
        return ['status' => 'ok'];
    }
    ap_defed_log("WARNING: failed to download $name from all sources");
    return ['status' => 'fail'];
}

/**
 * @param list<string> $watchDomains
 * @return array{status:string,detail?:string}
 */
function ap_defed_check_badspace(array $watchDomains, array $exportUrls): array
{
    ap_defed_log('Checking The Bad Space (CSV exports)');
    $content = '';
    foreach ($exportUrls as $url) {
        $content = ap_defed_fetch($url);
        if ($content !== '') {
            break;
        }
    }
    if ($content === '') {
        ap_defed_log('WARNING: failed to download The Bad Space CSV exports');
        return ['status' => 'fail'];
    }
    $hit = ap_defed_content_hit($content, $watchDomains);
    if ($hit === null) {
        ap_defed_log('OK: not found on The Bad Space');
        return ['status' => 'ok'];
    }

    $heatNote = 'heat>=20% (low consensus / tracked)';
    foreach (
        [
            90 => "https://tweaking.thebad.space/exports/mastodon/90",
            70 => "https://tweaking.thebad.space/exports/mastodon/70",
            50 => "https://tweaking.thebad.space/exports/mastodon/50",
        ] as $heat => $heatUrl
    ) {
        $heatContent = ap_defed_fetch($heatUrl);
        if ($heatContent !== '' && ap_defed_content_hit($heatContent, [$hit]) !== null) {
            $heatNote = "heat>={$heat}%";
            break;
        }
    }
    ap_defed_log("ALERT: found on The Bad Space domain=$hit $heatNote");
    return [
        'status' => 'hit',
        'detail' => "The Bad Space ($hit, $heatNote) — https://tweaking.thebad.space/",
    ];
}

/**
 * Ensure Ice Cubes can render the system DM peer as an account.
 */
function ap_defed_ensure_monitor_peer(): void
{
    if (!function_exists('ap_remote_actor_upsert')) {
        return;
    }
    try {
        ap_remote_actor_upsert(AP_DEFED_MONITOR_PEER, [
            'username' => 'vaak_monitor',
            'display_name' => 'Blocklist Monitor',
            'host' => 'mkultra.monster',
            'icon_source_url' => 'https://mkultra.monster/img/avatar/local-default.webp',
        ]);
    } catch (Throwable $e) {
        // non-fatal
    }
}

/**
 * Inbound system DM to cmdr_nova (cannot use ap_dm_send — no self-DM).
 *
 * @return array{ok:bool,error?:string,id?:int}
 */
function ap_defed_dm_cmdr(string $plainText, bool $dryRun): array
{
    $ownerId = function_exists('ap_db_cmdr_nova_user_id') ? ap_db_cmdr_nova_user_id() : 1;
    $ownerActor = function_exists('ap_db_owner_actor_id_for_user_id')
        ? ap_db_owner_actor_id_for_user_id($ownerId)
        : 'https://mkultra.monster/users/cmdr_nova';

    if ($dryRun) {
        ap_defed_log("DRY-RUN DM to cmdr_nova (" . strlen($plainText) . " chars):\n" . $plainText);
        return ['ok' => true, 'id' => 0];
    }

    ap_defed_ensure_monitor_peer();
    $now = gmdate('c');
    $objectId = AP_DEFED_MONITOR_PEER . '/notices/' . bin2hex(random_bytes(8));
    $stored = ap_dm_store([
        'activity_id' => $objectId . '/activity',
        'object_id' => $objectId,
        'peer_actor_id' => AP_DEFED_MONITOR_PEER,
        'direction' => 'in',
        'content' => $plainText,
        'created_at' => $now,
        'read_at' => null, // unread → badge
        'owner_user_id' => $ownerId,
        'owner_actor_id' => $ownerActor,
    ]);
    if (empty($stored['ok'])) {
        return ['ok' => false, 'error' => $stored['error'] ?? 'dm_store failed'];
    }

    // Push only to cmdr_nova's devices
    try {
        if (function_exists('ap_webpush_notify_event')) {
            $preview = trim(preg_replace('/\s+/', ' ', $plainText) ?? $plainText);
            if (function_exists('mb_substr') && mb_strlen($preview) > 140) {
                $preview = mb_substr($preview, 0, 137) . '…';
            } elseif (strlen($preview) > 140) {
                $preview = substr($preview, 0, 137) . '…';
            }
            ap_webpush_notify_event(
                'mention',
                AP_DEFED_MONITOR_PEER,
                null,
                $preview,
                $ownerId
            );
        }
    } catch (Throwable $e) {
        error_log('[ap-defederation] webpush: ' . $e->getMessage());
    }

    return ['ok' => true, 'id' => (int) ($stored['id'] ?? 0)];
}

function ap_defed_write_state(string $kind): void
{
    $dir = dirname(AP_DEFED_STATE_FILE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    @file_put_contents(AP_DEFED_STATE_FILE, $kind . ':' . time() . "\n");
}

function ap_defed_state_kind(): string
{
    if (!is_readable(AP_DEFED_STATE_FILE)) {
        return '';
    }
    $raw = trim((string) file_get_contents(AP_DEFED_STATE_FILE));
    $kind = explode(':', $raw, 2)[0] ?? '';
    return is_string($kind) ? $kind : '';
}

// ----- run checks -----
ap_defed_log('Defederation monitor starting for: ' . implode(' ', $watchDomains));

$foundOn = [];
$checkFailed = [];

$r = ap_defed_check_urls('GardenFence', $gardenfenceUrls, $watchDomains);
if ($r['status'] === 'hit') {
    $foundOn[] = $r['detail'] ?? 'GardenFence';
} elseif ($r['status'] === 'fail') {
    $checkFailed[] = 'GardenFence';
}

$r = ap_defed_check_urls('Seirdy Tier 0', [$seirdyTier0Url], $watchDomains);
if ($r['status'] === 'hit') {
    $foundOn[] = $r['detail'] ?? 'Seirdy Tier 0';
} elseif ($r['status'] === 'fail') {
    $checkFailed[] = 'Seirdy Tier 0';
}

$r = ap_defed_check_urls('Oliphant Unified Tier 0', $oliphantTier0Urls, $watchDomains);
if ($r['status'] === 'hit') {
    $foundOn[] = $r['detail'] ?? 'Oliphant Unified Tier 0';
} elseif ($r['status'] === 'fail') {
    $checkFailed[] = 'Oliphant Unified Tier 0';
}

$r = ap_defed_check_badspace($watchDomains, $badspaceExportUrls);
if ($r['status'] === 'hit') {
    $foundOn[] = $r['detail'] ?? 'The Bad Space';
} elseif ($r['status'] === 'fail') {
    $checkFailed[] = 'The Bad Space';
}

$watchLabel = implode(' ', $watchDomains);
$send = true;
$message = '';

if ($foundOn !== []) {
    // Compact: blank lines only between sections (Ice Cubes spaces <p> / <br> generously)
    $message = "DEFEDERATION ALERT\n\n"
        . "Watched domain(s) found on public blocklists.\n"
        . "Watching: {$watchLabel}\n\n"
        . implode("\n", array_map(static fn ($i) => '• ' . $i, $foundOn))
        . "\n\nCatalog: https://tweaking.thebad.space/";
    ap_defed_write_state('ALERT_SENT');
} elseif ($checkFailed !== []) {
    $message = "Defederation Monitor — Check Failed\n\n"
        . "Some lists could not be accessed:\n"
        . implode("\n", array_map(static fn ($i) => '• ' . $i, $checkFailed));
    // Only DM the first consecutive failure (match Mastodon script)
    if (ap_defed_state_kind() === 'CHECK_FAILED') {
        $send = false;
        ap_defed_log('Check failed (already notified) — skipping DM');
    } else {
        ap_defed_write_state('CHECK_FAILED');
    }
} else {
    $message = "Defederation Monitor — All Clear\n\n"
        . "Daily blocklist check completed. Watching: {$watchLabel}\n\n"
        . "GardenFence · Seirdy Tier 0 · Oliphant Tier 0 · The Bad Space: all OK\n\n"
        . "Catalog: https://tweaking.thebad.space/";
    ap_defed_write_state('ALL_CLEAR');
    if ($quietOk) {
        $send = false;
        ap_defed_log('All clear — quiet-ok, skipping DM');
    }
}

if ($send && $message !== '') {
    $res = ap_defed_dm_cmdr($message, $dryRun);
    if (!empty($res['ok'])) {
        ap_defed_log('DM stored id=' . (int) ($res['id'] ?? 0));
    } else {
        ap_defed_log('DM FAILED: ' . ($res['error'] ?? 'unknown'));
        flock($lockFh, LOCK_UN);
        fclose($lockFh);
        exit(1);
    }
}

ap_defed_log('Defederation monitor complete');
flock($lockFh, LOCK_UN);
fclose($lockFh);
exit(0);
