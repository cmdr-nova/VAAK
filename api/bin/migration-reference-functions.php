<?php
declare(strict_types=1);
require_once __DIR__ . "/../ap-search-native.php";
/** Extract named PHP declarations without executing application bootstrap code. */
function reference_load(string $file, array $names): void {
    $tokens = token_get_all((string) file_get_contents($file));
    $found = [];
    for ($i = 0; $i < count($tokens); $i++) {
        if (!is_array($tokens[$i]) || $tokens[$i][0] !== T_FUNCTION) continue;
        $j = $i + 1;
        while (isset($tokens[$j]) && is_array($tokens[$j]) && $tokens[$j][0] === T_WHITESPACE) $j++;
        if (!isset($tokens[$j]) || !is_array($tokens[$j]) || $tokens[$j][0] !== T_STRING) continue;
        $name = $tokens[$j][1];
        if (!in_array($name, $names, true)) continue;
        $code = ''; $depth = 0; $body = false;
        for ($k = $i; $k < count($tokens); $k++) {
            $token = $tokens[$k];
            $code .= is_array($token) && $token[0] === T_DIR ? var_export(dirname($file), true)
                : (is_array($token) && $token[0] === T_FILE ? var_export($file, true)
                : (is_array($token) ? $token[1] : $token));
            if (is_array($token) && in_array($token[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $depth++;
            if ($token === '{') { $depth++; $body = true; }
            if ($token === '}') $depth--;
            if ($body && $depth === 0) break;
        }
        if (!function_exists($name)) eval($code);
        $found[] = $name; $i = $k;
    }
    if (array_diff($names, $found)) throw new RuntimeException('Missing reference functions: ' . implode(',', array_diff($names, $found)));
}

/**
 * Named helpers the reference harness evaluates. reference_load runs only
 * functions the caller lists; new fan-out host helpers have to stay here.
 *
 * @return array<string, list<string>>
 */
function migration_reference_function_lists(): array
{
    return [
        'ap-fanout-host.php' => [
            'ap_fanout_host_marker',
            'ap_fanout_config_int',
            'ap_fanout_dead_days',
            'ap_fanout_dead_failures',
            'ap_fanout_host_now',
            'ap_fanout_host_unix',
            'ap_fanout_host_normalize',
            'ap_fanout_host_is_bluesky_host',
            'ap_fanout_live_rejection_status',
            'ap_fanout_counts_as_dead_failure',
            'ap_fanout_activity_is_bluesky',
            'ap_fanout_host_suspended',
            'ap_fanout_terminal_attempt_limit',
            'ap_fanout_probe_due',
            'ap_fanout_host_after_success',
            'ap_fanout_host_after_terminal_failure',
            'ap_fanout_worker_outcome',
            'ap_fanout_host_state_from_history',
            'ap_fanout_host_sqlite_ddl',
            'ap_fanout_host_ensure_sqlite',
            'ap_fanout_host_cache_reset',
            'ap_fanout_host_table_ready',
            'ap_fanout_host_cache',
            'ap_fanout_host_is_suspended_host',
            'ap_fanout_host_note_success',
            'ap_fanout_host_note_inbound',
            'ap_fanout_host_note_terminal',
            'ap_fanout_host_backfill',
            'ap_fanout_host_kick_worker',
            'ap_fanout_host_enqueue_probe',
            'ap_fanout_deliver_or_hold',
            'ap_fanout_host_admit',
            'ap_fanout_host_requeue_probe',
            'ap_fanout_terminal_failure_counts',
        ],
    ];
}
