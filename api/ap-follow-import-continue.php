<?php
/**
 * Resume follow-import from saved state handles (CLI).
 * Used after redeploy so the long-running importer reloads ap-inbox.php.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-inbox.php';

const AP_FOLLOW_IMPORT_STATE = '/var/lib/mkultra/ap/follow-import-state.json';

function fi_log(string $msg): void
{
    echo '[' . gmdate('c') . '] ' . $msg . "\n";
}

function fi_load(): array
{
    if (!is_readable(AP_FOLLOW_IMPORT_STATE)) {
        return [];
    }
    $j = json_decode((string) file_get_contents(AP_FOLLOW_IMPORT_STATE), true);
    return is_array($j) ? $j : [];
}

function fi_save(array $state): void
{
    $state['updated_at'] = gmdate('c');
    file_put_contents(
        AP_FOLLOW_IMPORT_STATE,
        json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE)
    );
}

function fi_should_skip(string $acct): ?string
{
    $lower = strtolower($acct);
    if ($lower === 'cmdr_nova@mkultra.monster' || $lower === 'cmdr-nova@mkultra.monster') {
        return 'self';
    }
    if ($lower === 'val3r1e@mkultra.monster') {
        return 'local bridgy handle (not live AP actor)';
    }
    return null;
}

$state = fi_load();
$handles = $state['handles'] ?? [];
if ($handles === []) {
    fi_log('no handles in state — nothing to continue');
    exit(0);
}

// Compact to remaining from current index
$idx = (int) ($state['index'] ?? 0);
if ($idx > 0 && $idx < count($handles)) {
    $state['handles'] = array_values(array_slice($handles, $idx));
    $state['index'] = 0;
    fi_save($state);
    $handles = $state['handles'];
    fi_log('compacted queue remaining=' . count($handles));
}

$maxPerHour = defined('LOCAL_FOLLOW_BACK_MAX_PER_HOUR') ? (int) LOCAL_FOLLOW_BACK_MAX_PER_HOUR : 30;
$sleepBetweenOk = 120;
$sleepOnLimit = 90;
$total = count($state['handles']);
fi_log('continue import total=' . $total . ' (authorized-fetch via cmdr_nova key)');

while ((int) $state['index'] < $total) {
    $i = (int) $state['index'];
    $acct = (string) $state['handles'][$i];
    $skip = fi_should_skip($acct);
    if ($skip !== null) {
        fi_log("skip @$acct ($skip)");
        $state['skipped'] = (int) ($state['skipped'] ?? 0) + 1;
        $state['index'] = $i + 1;
        fi_save($state);
        continue;
    }

    $recent = ap_outbound_follow_count_recent(3600);
    if ($recent >= $maxPerHour) {
        fi_log("rate_limit recent=$recent/$maxPerHour — sleeping {$sleepOnLimit}s (progress $i/$total)");
        fi_save($state);
        sleep($sleepOnLimit);
        continue;
    }

    fi_log('follow @' . $acct . ' (progress ' . ($i + 1) . "/$total recent=$recent)");
    $res = ap_follow_remote_actor('@' . $acct, true);
    if (!empty($res['ok'])) {
        if (!empty($res['already'])) {
            $state['already'] = (int) ($state['already'] ?? 0) + 1;
            fi_log("already @$acct");
        } else {
            $state['ok'] = (int) ($state['ok'] ?? 0) + 1;
            fi_log('ok @' . $acct . ' follow_id=' . ($res['follow_id'] ?? ''));
            $state['index'] = $i + 1;
            fi_save($state);
            sleep($sleepBetweenOk);
            continue;
        }
    } else {
        $err = (string) ($res['error'] ?? 'unknown');
        $state['failed'] = (int) ($state['failed'] ?? 0) + 1;
        $state['errors'][] = ['acct' => $acct, 'error' => $err, 'at' => gmdate('c')];
        if (count($state['errors']) > 200) {
            $state['errors'] = array_slice($state['errors'], -200);
        }
        fi_log("fail @$acct — $err");
        if (str_contains($err, 'rate limit')) {
            fi_save($state);
            sleep($sleepOnLimit);
            continue;
        }
    }

    $state['index'] = $i + 1;
    fi_save($state);
    usleep(400000);
}

$state['finished_at'] = gmdate('c');
fi_save($state);
fi_log(
    'done ok=' . (int) ($state['ok'] ?? 0)
    . ' already=' . (int) ($state['already'] ?? 0)
    . ' failed=' . (int) ($state['failed'] ?? 0)
    . ' skipped=' . (int) ($state['skipped'] ?? 0)
);
exit(0);
