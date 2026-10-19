#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Post-deploy canary. Runs after the activate script's health check, against
 * the live api/ tree, as www-data with the app's database.
 *
 * It renders through the same functions the web and the Mastodon API use,
 * and asks Axum for the timeline HTML PHP would fetch. It does not store
 * results or queue work itself; the render functions may touch their own
 * caches as an ordinary page view does. Each check runs in its own PHP
 * process, so a fatal error in one is reported as that check failing.
 *
 * A check with nothing to test (no Bluesky media posts seen recently, no
 * remote polls, an Axum cache miss) prints "skip" and does not fail.
 *
 * Exit 0: every check passed or skipped. Exit 3: at least one check failed.
 * Any other exit means the canary itself could not run.
 *
 *   sudo -n -u www-data /usr/bin/php /srv/mkultra/html/api/bin/deploy-canary.php
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

const CANARY_EXIT_FAILED = 3;
const CANARY_CHECKS = ['home', 'federated', 'local', 'bsky_media', 'remote_poll', 'axum_home', 'axum_local'];
/** Bluesky rows seen inside this window are candidates for the media check. */
const CANARY_BSKY_WINDOW_SECONDS = 6 * 3600;

/** Fill env names the CLI did not inherit from /etc/mkultra/vaak.env (never overrides). */
function canary_load_env(): void
{
    $path = getenv('VAAK_CANARY_ENV_FILE');
    $path = is_string($path) && $path !== '' ? $path : '/etc/mkultra/vaak.env';
    if (is_readable($path)) {
        foreach (file($path, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
            $line = trim($line);
            if (str_starts_with($line, 'export ')) {
                $line = trim(substr($line, 7));
            }
            if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                continue;
            }
            [$k, $v] = array_map('trim', explode('=', $line, 2));
            if (!preg_match('/^[A-Z][A-Z0-9_]*$/', $k) || getenv($k) !== false) {
                continue;
            }
            $v = trim($v, "\"'");
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
    // Same defaults as bin/home-timeline-warm.php and the worker units.
    foreach (['AP_DB_DSN' => 'pgsql:dbname=novalandia', 'VAAK_FEATURE_BLUESKY_TAB' => '1'] as $k => $v) {
        if (getenv($k) === false || getenv($k) === '') {
            putenv($k . '=' . $v);
            $_ENV[$k] = $v;
        }
    }
}

/** @return array{0:string,1:string} */
function canary_r(string $status, string $detail): array
{
    return [$status, $detail];
}

function canary_emit(string $status, string $detail): never
{
    $detail = substr(trim((string) preg_replace('/\s+/', ' ', $detail)), 0, 400);
    fwrite(STDOUT, json_encode(['status' => $status, 'detail' => $detail], JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

/** Admin account first, then the oldest enabled one. */
function canary_owner_id(): int
{
    $row = ap_db()->query(
        'SELECT id FROM ap_users WHERE disabled_at IS NULL ORDER BY is_admin DESC, id ASC LIMIT 1'
    )->fetch();
    return (int) ($row['id'] ?? 0);
}

function canary_has_row(string $sql): bool
{
    try {
        return ap_db()->query($sql)->fetchColumn() !== false;
    } catch (Throwable $e) {
        return false;
    }
}

function canary_boot_masto(): int
{
    $apiDir = dirname(__DIR__);
    require_once $apiDir . '/ap-db.php';
    require_once $apiDir . '/ap-action-queue.php';
    $owner = canary_owner_id();
    if ($owner < 1) {
        return 0;
    }
    if (!ap_action_queue_bind_owner($owner)) {
        throw new RuntimeException("cannot bind owner_id={$owner}");
    }
    if (!defined('AP_MASTO_LIB_ONLY')) {
        define('AP_MASTO_LIB_ONLY', true);
    }
    require_once $apiDir . '/ap-mastodon.php';
    return $owner;
}

function canary_boot_admin(): int
{
    $apiDir = dirname(__DIR__);
    require_once $apiDir . '/ap-db.php';
    require_once $apiDir . '/ap-action-queue.php';
    $owner = canary_owner_id();
    if ($owner < 1) {
        return 0;
    }
    if (!ap_action_queue_bind_owner($owner)) {
        throw new RuntimeException("cannot bind owner_id={$owner}");
    }
    if (!defined('AP_ADMIN_LIB_ONLY')) {
        define('AP_ADMIN_LIB_ONLY', true);
    }
    require_once $apiDir . '/ap-admin.php';
    return $owner;
}

/** @param list<mixed> $statuses */
function canary_count_statuses(array $statuses): int
{
    $n = 0;
    foreach ($statuses as $s) {
        if (is_array($s) && (string) ($s['id'] ?? '') !== '') {
            $n++;
        }
    }
    return $n;
}

function canary_check_home(): array
{
    $owner = canary_boot_masto();
    if ($owner < 1) {
        return canary_r('skip', 'no enabled account');
    }
    $statuses = ap_masto_timeline_home_merged(20, null, null, true);
    if (function_exists('ap_visibility_filter_statuses')) {
        $statuses = ap_visibility_filter_statuses($statuses, $owner);
    }
    $n = canary_count_statuses($statuses);
    if ($n > 0) {
        return canary_r('ok', "owner={$owner} statuses={$n}");
    }
    if (!canary_has_row('SELECT 1 FROM following LIMIT 1') && !canary_has_row('SELECT 1 FROM outbox_notes LIMIT 1')) {
        return canary_r('skip', 'no follows and no own posts');
    }
    return canary_r('fail', "owner={$owner} Home timeline is empty");
}

function canary_check_federated(): array
{
    $owner = canary_boot_masto();
    if ($owner < 1) {
        return canary_r('skip', 'no enabled account');
    }
    $n = canary_count_statuses(ap_masto_timeline_public_merged(20));
    if ($n > 0) {
        return canary_r('ok', "owner={$owner} statuses={$n}");
    }
    if (!canary_has_row("SELECT 1 FROM events WHERE type = 'Create' LIMIT 1")) {
        return canary_r('skip', 'no inbound Create events');
    }
    return canary_r('fail', 'Federated timeline is empty');
}

/** Local timeline: the instance's own public posts, as /api/v1/timelines/public?local=true builds it. */
function canary_check_local(): array
{
    $owner = canary_boot_masto();
    if ($owner < 1) {
        return canary_r('skip', 'no enabled account');
    }
    $n = 0;
    foreach (ap_masto_statuses_recent(40, null, null, null, true) as $row) {
        $status = ap_masto_status_from_row($row);
        if (ap_visibility_public_status($status) && (string) ($status['id'] ?? '') !== '') {
            $n++;
        }
    }
    if ($n > 0) {
        return canary_r('ok', "owner={$owner} statuses={$n}");
    }
    if (!canary_has_row('SELECT 1 FROM masto_statuses LIMIT 1')) {
        return canary_r('skip', 'no local posts');
    }
    return canary_r('fail', 'Local timeline is empty');
}

/**
 * Bluesky posts whose record carries image blob refs must reach the painter
 * with https image URLs. Jetstream rows have no AppView view, so the stored
 * embed is the only place those URLs come from.
 */
function canary_check_bsky_media(): array
{
    $owner = canary_boot_masto();
    if ($owner < 1) {
        return canary_r('skip', 'no enabled account');
    }
    try {
        $st = ap_db()->prepare(
            "SELECT bsky_uri, embed_json, raw_json FROM bsky_posts
             WHERE seen_at >= ? AND raw_json LIKE '%\"app.bsky.embed.images\"%'
             ORDER BY seen_at DESC LIMIT 10"
        );
        $st->execute([gmdate('c', time() - CANARY_BSKY_WINDOW_SECONDS)]);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return canary_r('skip', 'bsky_posts not readable: ' . $e->getMessage());
    }
    if ($rows === []) {
        return canary_r('skip', 'no Bluesky image posts seen in the last 6h');
    }
    $painted = 0;
    $firstMiss = '';
    foreach ($rows as $row) {
        $post = json_decode((string) ($row['raw_json'] ?? ''), true);
        if (!is_array($post)) {
            continue;
        }
        $embed = json_decode((string) ($row['embed_json'] ?? ''), true);
        $post['embed'] = is_array($embed) ? $embed : null;
        $hasUrl = false;
        foreach (ap_masto_bsky_media_attachments($post, 'canary') as $att) {
            if (str_starts_with((string) ($att['url'] ?? ''), 'https://')) {
                $hasUrl = true;
                break;
            }
        }
        if ($hasUrl) {
            $painted++;
        } elseif ($firstMiss === '') {
            $firstMiss = (string) $row['bsky_uri'];
        }
    }
    $detail = "painted={$painted}/" . count($rows);
    if ($painted === 0) {
        return canary_r('fail', $detail . ' none had an image URL, e.g. ' . $firstMiss);
    }
    return canary_r('ok', $detail);
}

function canary_check_remote_poll(): array
{
    $owner = canary_boot_admin();
    if ($owner < 1) {
        return canary_r('skip', 'no enabled account');
    }
    try {
        $row = ap_db()->query(
            "SELECT note_id, options_json FROM remote_polls
             WHERE options_json <> '[]' ORDER BY updated_at DESC LIMIT 1"
        )->fetch();
    } catch (Throwable $e) {
        return canary_r('skip', 'remote_polls not readable');
    }
    if (!is_array($row)) {
        return canary_r('skip', 'no remote polls stored');
    }
    $noteId = (string) $row['note_id'];
    $options = json_decode((string) $row['options_json'], true);
    $want = is_array($options) ? count($options) : 0;
    $html = admin_poll_block_html($noteId, false, 'home');
    $got = substr_count($html, 'class="poll-opt-text"');
    if ($want > 0 && $got === $want) {
        return canary_r('ok', "options={$got}");
    }
    return canary_r('fail', "poll {$noteId} painted {$got} of {$want} options");
}

function canary_check_axum(string $view): array
{
    $owner = canary_boot_admin();
    if ($owner < 1) {
        return canary_r('skip', 'no enabled account');
    }
    $base = getenv('VAAK_SHADOW_HTTP');
    $base = is_string($base) && trim($base) !== '' ? rtrim(trim($base), '/') : 'http://127.0.0.1:8787';
    $url = $base . '/shadow/' . $view . '-html?' . http_build_query(['owner_id' => $owner, 'limit' => 5]);
    $code = 0;
    $len = 0;
    // Axum was restarted moments ago; give it a few tries before calling a
    // connection error or a 5xx a failure.
    for ($i = 0; $i < 3; $i++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT_MS => 1000,
            CURLOPT_TIMEOUT_MS => 5000,
            CURLOPT_HTTPHEADER => ['Accept: text/html'],
        ]);
        $body = curl_exec($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        $len = is_string($body) ? strlen($body) : 0;
        if ($code > 0 && $code < 500) {
            break;
        }
        sleep(1);
    }
    if ($code === 404) {
        // Cache miss: PHP paints the cards itself, and the warm worker refills.
        return canary_r('skip', "{$view}-html cache miss (404)");
    }
    if ($code !== 200 || $len === 0) {
        return canary_r('fail', "{$view}-html returned {$code} with {$len} bytes");
    }
    $fetched = admin_tl_html_axum_fetch($view, 5, $owner);
    if (!is_array($fetched) || trim((string) ($fetched['html'] ?? '')) === '') {
        // Only a disabled flag makes PHP skip Axum on purpose.
        $flag = getenv($view === 'home' ? 'VAAK_HOME_HTML_AXUM' : 'VAAK_LOCAL_FEED_HTML_AXUM');
        if (is_string($flag) && in_array(strtolower(trim($flag)), ['0', 'false', 'off', 'no'], true)) {
            return canary_r('skip', "{$view}-html disabled by flag");
        }
        return canary_r('fail', "admin_tl_html_axum_fetch('{$view}') returned nothing after a 200");
    }
    return canary_r('ok', "{$view}-html 200 bytes={$len}");
}

function canary_run_check(string $name): never
{
    canary_load_env();
    try {
        [$status, $detail] = match ($name) {
            'home' => canary_check_home(),
            'federated' => canary_check_federated(),
            'local' => canary_check_local(),
            'bsky_media' => canary_check_bsky_media(),
            'remote_poll' => canary_check_remote_poll(),
            'axum_home' => canary_check_axum('home'),
            'axum_local' => canary_check_axum('local'),
            default => canary_r('fail', 'unknown check'),
        };
    } catch (Throwable $e) {
        [$status, $detail] = canary_r('fail', get_class($e) . ': ' . $e->getMessage());
    }
    canary_emit($status, $detail);
}

/** @return array{status:string,detail:string} */
function canary_spawn(string $name, int $timeout): array
{
    $cmd = [PHP_BINARY, __FILE__, '--check=' . $name];
    $proc = proc_open($cmd, [0 => ['file', '/dev/null', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($proc)) {
        return ['status' => 'fail', 'detail' => 'could not start check'];
    }
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $out = '';
    $err = '';
    $deadline = microtime(true) + $timeout;
    while (true) {
        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        $status = proc_get_status($proc);
        if (!$status['running']) {
            break;
        }
        if (microtime(true) > $deadline) {
            proc_terminate($proc, 9);
            proc_close($proc);
            return ['status' => 'fail', 'detail' => "timed out after {$timeout}s"];
        }
        usleep(50000);
    }
    $out .= (string) stream_get_contents($pipes[1]);
    $err .= (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    proc_close($proc);
    $lines = preg_split('/\R/', trim($out)) ?: [];
    $last = json_decode((string) end($lines), true);
    if (is_array($last) && in_array($last['status'] ?? '', ['ok', 'skip', 'fail'], true)) {
        return ['status' => (string) $last['status'], 'detail' => (string) ($last['detail'] ?? '')];
    }
    $tail = trim(substr(trim($err . "\n" . $out), -300));
    return ['status' => 'fail', 'detail' => 'no result: ' . preg_replace('/\s+/', ' ', $tail)];
}

$opts = getopt('', ['check:', 'only:', 'timeout:']);
if (isset($opts['check'])) {
    canary_run_check((string) $opts['check']);
}

$names = CANARY_CHECKS;
if (isset($opts['only']) && is_string($opts['only'])) {
    $names = array_values(array_intersect(CANARY_CHECKS, array_map('trim', explode(',', $opts['only']))));
}
$timeout = isset($opts['timeout']) ? max(5, (int) $opts['timeout']) : 45;
$counts = ['ok' => 0, 'skip' => 0, 'fail' => 0];
foreach ($names as $name) {
    $r = canary_spawn($name, $timeout);
    $counts[$r['status']]++;
    fwrite(STDOUT, sprintf("canary %s %s: %s\n", $name, $r['status'], $r['detail']));
}
$summary = sprintf('ok=%d skip=%d fail=%d', $counts['ok'], $counts['skip'], $counts['fail']);
if ($counts['fail'] > 0) {
    fwrite(STDOUT, "deploy_canary_failed {$summary}\n");
    exit(CANARY_EXIT_FAILED);
}
fwrite(STDOUT, "deploy_canary_ok {$summary}\n");
exit(0);
