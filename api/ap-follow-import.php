<?php
/**
 * Import Mastodon following_accounts.csv as outbound Follows from @cmdr_nova.
 *
 * CLI only:
 *   php ap-follow-import.php /path/to/following_accounts.csv
 *   php ap-follow-import.php --status
 *   php ap-follow-import.php --retry-failed
 *
 * Rate-limited by LOCAL_FOLLOW_BACK_MAX_PER_HOUR (~30/hr). State resumes across runs.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    header('Content-Type: text/plain');
    echo "CLI only\n";
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-inbox.php';

const AP_FOLLOW_IMPORT_STATE = '/var/lib/mkultra/ap/follow-import-state.json';
const AP_FOLLOW_IMPORT_LOG = '/var/log/mkultra/ap-follow-import.log';

function fi_log(string $msg): void
{
    // stdout only — cron/nohup redirects into AP_FOLLOW_IMPORT_LOG
    echo '[' . gmdate('c') . '] ' . $msg . "\n";
}

function fi_load_state(): array
{
    if (!is_readable(AP_FOLLOW_IMPORT_STATE)) {
        return [
            'csv' => '',
            'handles' => [],
            'index' => 0,
            'ok' => 0,
            'already' => 0,
            'failed' => 0,
            'skipped' => 0,
            'errors' => [],
            'started_at' => gmdate('c'),
            'updated_at' => gmdate('c'),
            'finished_at' => null,
        ];
    }
    $j = json_decode((string) file_get_contents(AP_FOLLOW_IMPORT_STATE), true);
    return is_array($j) ? $j : fi_load_state_empty();
}

function fi_load_state_empty(): array
{
    return [
        'csv' => '',
        'handles' => [],
        'index' => 0,
        'ok' => 0,
        'already' => 0,
        'failed' => 0,
        'skipped' => 0,
        'errors' => [],
        'started_at' => gmdate('c'),
        'updated_at' => gmdate('c'),
        'finished_at' => null,
    ];
}

function fi_save_state(array $state): void
{
    $state['updated_at'] = gmdate('c');
    $dir = dirname(AP_FOLLOW_IMPORT_STATE);
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    file_put_contents(
        AP_FOLLOW_IMPORT_STATE,
        json_encode($state, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)
    );
}

/** @return list<string> */
function fi_parse_csv(string $path): array
{
    $fh = fopen($path, 'r');
    if ($fh === false) {
        throw new RuntimeException('Cannot open CSV: ' . $path);
    }
    $header = fgetcsv($fh);
    if (!is_array($header) || $header === []) {
        fclose($fh);
        throw new RuntimeException('Empty CSV');
    }
    $addrIdx = 0;
    foreach ($header as $i => $col) {
        if (stripos((string) $col, 'account') !== false || stripos((string) $col, 'address') !== false) {
            $addrIdx = (int) $i;
            break;
        }
    }
    $out = [];
    $seen = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (!is_array($row) || !isset($row[$addrIdx])) {
            continue;
        }
        $acct = trim((string) $row[$addrIdx]);
        if ($acct === '' || str_starts_with($acct, '#')) {
            continue;
        }
        $acct = ltrim($acct, '@');
        if (!preg_match('/^[A-Za-z0-9_.\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $acct)) {
            continue;
        }
        $key = strtolower($acct);
        if (isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $out[] = $acct;
    }
    fclose($fh);
    return $out;
}

function fi_should_skip(string $acct): ?string
{
    $lower = strtolower($acct);
    if ($lower === 'cmdr_nova@mkultra.monster' || $lower === 'cmdr-nova@mkultra.monster') {
        return 'self';
    }
    // Bridgy/WebFinger split handle — not the live local actor
    if ($lower === 'val3r1e@mkultra.monster') {
        return 'local bridgy handle (not live AP actor)';
    }
    return null;
}

$argvList = array_slice($argv, 1);
if (($argvList[0] ?? '') === '--status') {
    $st = fi_load_state();
    $total = count($st['handles'] ?? []);
    $idx = (int) ($st['index'] ?? 0);
    echo json_encode([
        'total' => $total,
        'index' => $idx,
        'remaining' => max(0, $total - $idx),
        'ok' => (int) ($st['ok'] ?? 0),
        'already' => (int) ($st['already'] ?? 0),
        'failed' => (int) ($st['failed'] ?? 0),
        'skipped' => (int) ($st['skipped'] ?? 0),
        'finished_at' => $st['finished_at'] ?? null,
        'updated_at' => $st['updated_at'] ?? null,
        'recent_errors' => array_slice($st['errors'] ?? [], -10),
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    exit(0);
}

// Re-queue previously failed handles (e.g. after authorized-fetch signing fix)
if (($argvList[0] ?? '') === '--retry-failed') {
    $st = fi_load_state();
    $failedAccts = [];
    $seen = [];
    foreach ($st['errors'] ?? [] as $err) {
        $acct = strtolower(ltrim((string) ($err['acct'] ?? ''), '@'));
        if ($acct === '' || isset($seen[$acct])) {
            continue;
        }
        $seen[$acct] = true;
        $failedAccts[] = (string) ($err['acct'] ?? $acct);
    }
    if ($failedAccts === []) {
        fwrite(STDERR, "No failed accounts in follow-import state.\n");
        exit(0);
    }
    fi_log('retry-failed requeue count=' . count($failedAccts));
    $st['handles'] = $failedAccts;
    $st['index'] = 0;
    $st['ok'] = 0;
    $st['already'] = 0;
    $st['failed'] = 0;
    $st['skipped'] = 0;
    $st['errors'] = [];
    $st['finished_at'] = null;
    $st['started_at'] = gmdate('c');
    $st['fingerprint'] = 'retry-failed|' . count($failedAccts) . '|' . md5(implode(',', array_slice($failedAccts, 0, 5)));
    $st['csv'] = (string) ($st['csv'] ?? 'retry-failed');
    fi_save_state($st);
    $state = $st;
} else {
    $csvPath = $argvList[0] ?? '';
    if ($csvPath === '' || !is_readable($csvPath)) {
        fwrite(STDERR, "Usage: php ap-follow-import.php /path/to/following_accounts.csv\n");
        fwrite(STDERR, "       php ap-follow-import.php --status\n");
        fwrite(STDERR, "       php ap-follow-import.php --retry-failed\n");
        exit(1);
    }

    $state = fi_load_state();
    $handles = fi_parse_csv($csvPath);
    // Resume only if same CSV path + same handle list length fingerprint
    $fp = $csvPath . '|' . count($handles) . '|' . md5(implode(',', array_slice($handles, 0, 5)));
    $prevFp = (string) ($state['fingerprint'] ?? '');
    if ($prevFp !== $fp || empty($state['handles'])) {
        fi_log('new import csv=' . $csvPath . ' handles=' . count($handles));
        $state = fi_load_state_empty();
        $state['csv'] = $csvPath;
        $state['handles'] = $handles;
        $state['fingerprint'] = $fp;
        $state['index'] = 0;
    } else {
        fi_log('resume import index=' . (int) $state['index'] . '/' . count($handles));
    }
}

$maxPerHour = defined('LOCAL_FOLLOW_BACK_MAX_PER_HOUR') ? (int) LOCAL_FOLLOW_BACK_MAX_PER_HOUR : 30;
$sleepBetweenOk = 120; // ~30/hr when successful
$sleepOnLimit = 90;

$total = count($state['handles']);
while ((int) $state['index'] < $total) {
    $i = (int) $state['index'];
    $acct = (string) $state['handles'][$i];
    $skip = fi_should_skip($acct);
    if ($skip !== null) {
        fi_log("skip @$acct ($skip)");
        $state['skipped'] = (int) $state['skipped'] + 1;
        $state['index'] = $i + 1;
        fi_save_state($state);
        continue;
    }

    $recent = ap_outbound_follow_count_recent(3600);
    if ($recent >= $maxPerHour) {
        fi_log("rate_limit recent=$recent/$maxPerHour — sleeping {$sleepOnLimit}s (progress $i/$total)");
        fi_save_state($state);
        sleep($sleepOnLimit);
        continue;
    }

    fi_log("follow @$acct (progress " . ($i + 1) . "/$total recent=$recent)");
    $res = ap_follow_remote_actor('@' . $acct, true);
    if (!empty($res['ok'])) {
        if (!empty($res['already'])) {
            $state['already'] = (int) $state['already'] + 1;
            fi_log("already @$acct");
        } else {
            $state['ok'] = (int) $state['ok'] + 1;
            fi_log("ok @$acct follow_id=" . ($res['follow_id'] ?? ''));
            $state['index'] = $i + 1;
            fi_save_state($state);
            sleep($sleepBetweenOk);
            continue;
        }
    } else {
        $err = (string) ($res['error'] ?? 'unknown');
        $state['failed'] = (int) $state['failed'] + 1;
        $state['errors'][] = ['acct' => $acct, 'error' => $err, 'at' => gmdate('c')];
        if (count($state['errors']) > 200) {
            $state['errors'] = array_slice($state['errors'], -200);
        }
        fi_log("fail @$acct — $err");
        if (str_contains($err, 'rate limit')) {
            fi_save_state($state);
            sleep($sleepOnLimit);
            continue; // retry same index
        }
    }

    $state['index'] = $i + 1;
    fi_save_state($state);
    // Brief pause even on fail/already to be polite to WebFinger hosts
    usleep(400000);
}

$state['finished_at'] = gmdate('c');
fi_save_state($state);
fi_log('done ok=' . $state['ok'] . ' already=' . $state['already'] . ' failed=' . $state['failed'] . ' skipped=' . $state['skipped']);
exit(0);
