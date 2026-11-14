<?php
declare(strict_types=1);
/**
 * VAAK browser push: one subscription per browser, matched by endpoint.
 * - Security "Enabled" only for THIS browser (an Ice Cubes sub never counts).
 * - Enabling on a second device keeps the first one.
 * - Browser subs use aes128gcm (Apple's push service takes nothing else).
 * - A visit pushes the token expiry out so the daily purge can't drop it.
 * - One browser belongs to one VAAK login.
 * SQLite in memory. No Postgres, Redis, or network.
 */
$api = dirname(__DIR__);
require __DIR__ . '/migration-reference-functions.php';

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
};
$check = static function (bool $ok, string $label) use ($fail): void {
    if (!$ok) {
        $fail($label);
    }
    echo "ok - {$label}\n";
};

$GLOBALS['smoke_pdo'] = new PDO('sqlite::memory:', null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
function ap_db(): PDO { return $GLOBALS['smoke_pdo']; }
function ap_db_driver(PDO $db): string { return 'sqlite'; }
function ap_db_now(): string { return gmdate('c'); }
function ap_oauth_app_by_client_id(string $id): ?array { return ['client_id' => $id]; }
const AP_WEBPUSH_VAPID_PATH = '/nonexistent/vapid.json';
const AP_WEBPUSH_VAAK_WEB_CLIENT_ID = 'vaak-web-push';

reference_load($api . '/ap-db.php', ['ap_oauth_token_create', 'ap_oauth_token_ttl_seconds', 'ap_oauth_refresh_ttl_seconds']);
reference_load($api . '/ap-webpush.php', [
    'ap_webpush_vapid_keys', 'ap_webpush_vapid_public_key', 'ap_webpush_migrate',
    'ap_webpush_subscription_upsert', 'ap_webpush_subscription_by_token_id',
    'ap_webpush_subscription_delete', 'ap_webpush_subscription_entity',
    'ap_webpush_ensure_vaak_web_app', 'ap_webpush_browser_subscription',
    'ap_webpush_browser_touch', 'ap_webpush_vaak_web_token_for_user',
    'ap_webpush_browser_subscribe', 'ap_webpush_browser_unsubscribe',
]);

$db = ap_db();
$db->exec('CREATE TABLE oauth_tokens (id INTEGER PRIMARY KEY AUTOINCREMENT, access_token_hash TEXT NOT NULL UNIQUE,
    client_id TEXT NOT NULL, scopes TEXT NOT NULL, created_at TEXT NOT NULL, expires_at TEXT, refresh_token_hash TEXT,
    refresh_expires_at TEXT, revoked_at TEXT, user_id INTEGER)');
ap_webpush_migrate();

$sub = static fn (string $host): array => [
    'endpoint' => "https://{$host}/push/" . bin2hex(random_bytes(8)),
    'keys' => ['p256dh' => 'BPk' . bin2hex(random_bytes(30)), 'auth' => bin2hex(random_bytes(8))],
];
$count = static fn (): int => (int) $db->query('SELECT COUNT(*) FROM push_subscriptions')->fetchColumn();

// Ice Cubes already subscribed for user 1 (its own token).
$ice = ap_oauth_token_create('ice-cubes', 'read write push', 1);
$iceRow = $db->query("SELECT * FROM oauth_tokens WHERE client_id = 'ice-cubes'")->fetch();
ap_webpush_subscription_upsert($iceRow, $ice['access_token'], ['subscription' => $sub('fcm.example') + ['standard' => false]]);

$desktop = $sub('fcm.googleapis.com');
$phone = $sub('web.push.apple.com');
$check(ap_webpush_browser_subscription(1, $desktop['endpoint']) === null, 'an Ice Cubes sub does not make this browser look Enabled');

ap_webpush_browser_subscribe(1, $desktop);
$d = ap_webpush_browser_subscription(1, $desktop['endpoint']);
$check(is_array($d), 'desktop browser saved');
$check($d['content_encoding'] === 'aes128gcm', 'browser sub uses aes128gcm');

ap_webpush_browser_subscribe(1, $phone);
$check(is_array(ap_webpush_browser_subscription(1, $desktop['endpoint'])), 'phone Enable keeps the desktop sub');
$check(is_array(ap_webpush_browser_subscription(1, $phone['endpoint'])), 'phone saved');
$check($count() === 3, 'Ice Cubes + desktop + phone = 3 subs');

ap_webpush_browser_subscribe(1, $desktop);
$check($count() === 3, 're-enabling the same browser reuses its row');

// Token expiry: purge would drop the token; a visit pushes it out.
$db->prepare("UPDATE oauth_tokens SET expires_at = '2000-01-01T00:00:00+00:00', refresh_expires_at = '2000-01-01T00:00:00+00:00' WHERE id = ?")
    ->execute([(int) $d['token_id']]);
ap_webpush_browser_touch((int) $d['token_id']);
$exp = (string) $db->query('SELECT refresh_expires_at FROM oauth_tokens WHERE id = ' . (int) $d['token_id'])->fetchColumn();
$check(strtotime($exp) > time() + 170 * 86400, 'visit extends the browser token');

// Same browser, different VAAK login: the old login stops getting pushes here.
ap_webpush_browser_subscribe(2, $desktop);
$check(ap_webpush_browser_subscription(1, $desktop['endpoint']) === null, 'user 1 no longer owns the shared browser');
$check(is_array(ap_webpush_browser_subscription(2, $desktop['endpoint'])), 'user 2 owns it now');
$check(is_array(ap_webpush_browser_subscription(1, $phone['endpoint'])), 'user 1 phone untouched');

$check(ap_webpush_browser_unsubscribe(1, $phone['endpoint']), 'phone Disable');
$check(ap_webpush_browser_subscription(1, $phone['endpoint']) === null, 'phone gone');
$iceLeft = (int) $db->query('SELECT COUNT(*) FROM push_subscriptions WHERE token_id = ' . (int) $iceRow['id'])->fetchColumn();
$check($iceLeft === 1, 'Ice Cubes sub never touched');

echo "webpush-browser-subs-smoke: all good\n";
