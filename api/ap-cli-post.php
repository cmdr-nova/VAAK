<?php
declare(strict_types=1);

/**
 * CLI helper: publish a public status as a local VAAK actor (stdin → outbox + Bluesky mirror).
 *
 * Usage:
 *   echo "hello" | php ap-cli-post.php --actor=cmdr_nova
 *   php ap-cli-post.php --actor=cmdr_nova --text="hello world"
 *
 * Env:
 *   AP_DB_DSN / AP_DB_USER / AP_DB_PASSWORD (same as FPM)
 */

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "CLI only\n");
    exit(1);
}

define('AP_INBOX_LIB_ONLY', true);

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-auth.php';
require_once __DIR__ . '/ap-inbox.php';
require_once __DIR__ . '/ap-r2.php';
if (is_file(__DIR__ . '/ap-bsky.php')) {
    require_once __DIR__ . '/ap-bsky.php';
}

$actor = 'cmdr_nova';
$text = '';
$visibility = 'public';
$spoiler = '';
$dryRun = false;

foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--dry-run') {
        $dryRun = true;
        continue;
    }
    if (str_starts_with($arg, '--actor=')) {
        $actor = strtolower(trim(substr($arg, 8)));
        continue;
    }
    if (str_starts_with($arg, '--text=')) {
        $text = (string) substr($arg, 7);
        continue;
    }
    if (str_starts_with($arg, '--visibility=')) {
        $visibility = strtolower(trim(substr($arg, 13)));
        continue;
    }
    if (str_starts_with($arg, '--spoiler=')) {
        $spoiler = (string) substr($arg, 10);
        continue;
    }
    if ($arg === '-h' || $arg === '--help') {
        fwrite(STDOUT, "Usage: echo TEXT | php ap-cli-post.php --actor=cmdr_nova [--spoiler=CW] [--visibility=public] [--dry-run]\n");
        exit(0);
    }
}

if ($text === '') {
    $text = stream_get_contents(STDIN);
}
$text = trim((string) $text);
if ($text === '') {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => 'Empty post text'], JSON_UNESCAPED_SLASHES) . "\n");
    exit(2);
}

if (!preg_match('/^[a-z][a-z0-9_]{1,29}$/', $actor)) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => 'Invalid actor'], JSON_UNESCAPED_SLASHES) . "\n");
    exit(2);
}

$st = ap_db()->prepare('SELECT id, username, actor_key, disabled_at FROM ap_users WHERE lower(username) = lower(?) LIMIT 1');
$st->execute([$actor]);
$user = $st->fetch(PDO::FETCH_ASSOC);
if (!is_array($user) || !empty($user['disabled_at'])) {
    fwrite(STDERR, json_encode(['ok' => false, 'error' => 'Unknown or disabled actor'], JSON_UNESCAPED_SLASHES) . "\n");
    exit(2);
}

if ($dryRun) {
    fwrite(STDOUT, json_encode([
        'ok' => true,
        'dry_run' => true,
        'actor' => $actor,
        'visibility' => $visibility,
        'spoiler' => $spoiler,
        'text' => $text,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
    exit(0);
}

if (function_exists('ap_request_actor_set')) {
    ap_request_actor_set($actor);
}

$result = ap_publish_status_text(
    $text,
    null,
    null,
    [],
    $spoiler,
    $spoiler !== '' ? true : null,
    null,
    null,
    $visibility
);

$out = [
    'ok' => !empty($result['ok']),
    'actor' => $actor,
    'note_id' => $result['note_id'] ?? null,
    'error' => $result['error'] ?? null,
    'delivered' => $result['delivered'] ?? null,
    'queued' => $result['queued'] ?? null,
];
fwrite(STDOUT, json_encode($out, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");
exit(!empty($result['ok']) ? 0 : 1);
