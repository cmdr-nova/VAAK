<?php
/**
 * Ingest pending-approval alerts from the Contabo notifier into VAAK DMs.
 *
 * Auth: Authorization: Bearer <WAFRN_ALERTS_HOOK_SECRET> from /etc/mkultra/wafrn-alerts.env
 *
 * POST JSON:
 * {
 *   "key": "wafrn:<uuid>" | "mastodon:<id>",
 *   "source": "wafrn" | "mastodon",
 *   "username": "handle",
 *   "email": "…",
 *   "created": "…",
 *   "ip": "…" (optional, Wafrn),
 *   "review_url": "https://…"
 * }
 *
 * Stores an inbound DM for @cmdr_nova so VAAK / Ice Cubes surface it like the
 * Mastodon @approvals → @cmdr_nova direct messages.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
header('Content-Type: application/json; charset=utf-8');

require_once __DIR__ . '/ap-db.php';

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Methods: POST, OPTIONS');
    header('Access-Control-Allow-Headers: Authorization, Content-Type');
    http_response_code(204);
    exit;
}

if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST only']);
    exit;
}

function ap_pending_hook_secret(): string
{
    static $secret = null;
    if ($secret !== null) {
        return $secret;
    }
    $secret = '';
    $envPath = '/etc/mkultra/wafrn-alerts.env';
    if (is_readable($envPath)) {
        foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            if (preg_match('/^WAFRN_ALERTS_HOOK_SECRET=(.*)$/', $line, $m)) {
                $secret = trim($m[1], " \t\"'");
                break;
            }
        }
    }
    return $secret;
}

function ap_pending_hook_json(array $payload, int $code = 200): void
{
    http_response_code($code);
    echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

$auth = (string) ($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
$token = '';
if (preg_match('/^Bearer\s+(\S+)$/i', $auth, $m)) {
    $token = $m[1];
}
$expected = ap_pending_hook_secret();
if ($expected === '' || $token === '' || !hash_equals($expected, $token)) {
    ap_pending_hook_json(['ok' => false, 'error' => 'Unauthorized'], 401);
}

$raw = file_get_contents('php://input') ?: '';
$data = json_decode($raw, true);
if (!is_array($data)) {
    // Also accept form fields
    $data = $_POST;
}
if (!is_array($data) || $data === []) {
    ap_pending_hook_json(['ok' => false, 'error' => 'Invalid JSON body'], 400);
}

$key = trim((string) ($data['key'] ?? ''));
$source = strtolower(trim((string) ($data['source'] ?? '')));
$username = trim((string) ($data['username'] ?? ''));
$email = trim((string) ($data['email'] ?? ''));
$created = trim((string) ($data['created'] ?? ''));
$ip = trim((string) ($data['ip'] ?? ''));
$reviewUrl = trim((string) ($data['review_url'] ?? ''));

if ($key === '' || !preg_match('/^(wafrn|mastodon):[A-Za-z0-9_.:-]+$/', $key)) {
    ap_pending_hook_json(['ok' => false, 'error' => 'Invalid key'], 422);
}
if (!in_array($source, ['wafrn', 'mastodon'], true)) {
    // Infer from key prefix
    $source = str_starts_with($key, 'mastodon:') ? 'mastodon' : 'wafrn';
}
if ($username === '') {
    ap_pending_hook_json(['ok' => false, 'error' => 'username required'], 422);
}

$cmdrId = function_exists('ap_db_cmdr_nova_user_id') ? ap_db_cmdr_nova_user_id() : 1;
$cmdrActor = 'https://mkultra.monster/users/cmdr_nova';

$peer = $source === 'mastodon'
    ? 'https://cmplxdecay.space/users/approvals'
    : 'https://waffles.baeddel.social/fediverse/blog/admin';

$label = $source === 'mastodon' ? 'Mastodon' : 'Wafrn';
$handleLine = $source === 'mastodon'
    ? ('Pending approval: @' . $username)
    : ('Pending approval: ' . $username);

$lines = [
    '[' . $label . '] ' . $handleLine,
];
if ($email !== '') {
    $lines[] = 'Email: ' . $email;
}
if ($ip !== '') {
    $lines[] = 'IP: ' . $ip;
}
if ($created !== '') {
    $lines[] = 'Created: ' . $created;
}
if ($reviewUrl !== '') {
    $lines[] = 'Review: ' . $reviewUrl;
} elseif ($source === 'wafrn') {
    $lines[] = 'Review: https://waffles.baeddel.social/';
} else {
    $lines[] = 'Review: https://cmplxdecay.space/admin/accounts?status=pending';
}
$plain = implode("\n", $lines);

$keyHash = substr(hash('sha256', $key), 0, 16);
$objectId = $cmdrActor . '/notes/pending-alert-' . $keyHash;
$activityId = $cmdrActor . '/creates/pending-alert-' . $keyHash;

$contentHtml = function_exists('ap_plain_text_to_html')
    ? ap_plain_text_to_html($plain)
    : ('<p>' . nl2br(htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>');

$stored = ap_dm_store([
    'owner_user_id' => $cmdrId,
    'owner_actor_id' => $cmdrActor,
    'activity_id' => $activityId,
    'object_id' => $objectId,
    'peer_actor_id' => $peer,
    'direction' => 'in',
    'content' => $contentHtml,
    'created_at' => ap_db_now(),
    'read_at' => null,
]);

if (empty($stored['ok'])) {
    ap_pending_hook_json([
        'ok' => false,
        'error' => $stored['error'] ?? 'DM store failed',
    ], 500);
}

// Also drop a notification row so Ice Cubes Notifications tab lights up
if (function_exists('ap_mention_store')) {
    ap_mention_store([
        'owner_user_id' => $cmdrId,
        'owner_actor_id' => $cmdrActor,
        'activity_id' => $activityId . '#notif',
        'activity_type' => 'Create',
        'type' => 'Note',
        'actor_id' => $peer,
        'object_id' => $objectId,
        'content' => $plain,
        'in_reply_to' => null,
        'media_urls' => [],
        'spoiler_text' => '',
        'sensitive' => false,
    ]);
}

ap_pending_hook_json([
    'ok' => true,
    'dm_id' => (int) ($stored['id'] ?? 0),
    'object_id' => $objectId,
    'key' => $key,
]);
