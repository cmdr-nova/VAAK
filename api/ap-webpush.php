<?php
/**
 * Mastodon Web Push for Ice Cubes (VAPID + encrypted payloads).
 * Keys live in /etc/mkultra/vapid.json (outside deploy/rsync).
 * Library: /srv/mkultra/services/webpush (minishlink/web-push).
 */
declare(strict_types=1);
// Refuse direct HTTP hits (include/require only)
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    header('Cache-Control: no-store');
    echo 'Library only';
    exit;
}


require_once __DIR__ . '/ap-db.php';

const AP_WEBPUSH_VAPID_PATH = '/etc/mkultra/vapid.json';
const AP_WEBPUSH_AUTOLOAD = '/srv/mkultra/services/webpush/vendor/autoload.php';

/**
 * @return array{publicKey:string,privateKey:string,subject:string}|null
 */
function ap_webpush_vapid_keys(): ?array
{
    static $cached = null;
    if (is_array($cached)) {
        return $cached;
    }
    if (!is_readable(AP_WEBPUSH_VAPID_PATH)) {
        return null;
    }
    $raw = @file_get_contents(AP_WEBPUSH_VAPID_PATH);
    $data = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($data) || empty($data['publicKey']) || empty($data['privateKey'])) {
        return null;
    }
    $cached = [
        'publicKey' => (string) $data['publicKey'],
        'privateKey' => (string) $data['privateKey'],
        'subject' => (string) ($data['subject'] ?? 'mailto:cmdr-nova@mkultra.monster'),
    ];
    return $cached;
}

/** Public VAPID key for instance/apps API (empty string if unavailable). */
function ap_webpush_vapid_public_key(): string
{
    $k = ap_webpush_vapid_keys();
    return $k['publicKey'] ?? '';
}

function ap_webpush_migrate(): void
{
    $db = ap_db();
    if (ap_db_driver($db) === 'pgsql') {
        // PostgreSQL schema is provisioned by the migration/import runbook.
        return;
    }
    $idColumn = 'INTEGER PRIMARY KEY AUTOINCREMENT';
    $db->exec("CREATE TABLE IF NOT EXISTS push_subscriptions (
    id {$idColumn},
    token_id INTEGER NOT NULL UNIQUE,
    access_token TEXT NOT NULL,
    endpoint TEXT NOT NULL,
    p256dh TEXT NOT NULL,
    auth_key TEXT NOT NULL,
    alert_follow INTEGER NOT NULL DEFAULT 1,
    alert_favourite INTEGER NOT NULL DEFAULT 1,
    alert_reblog INTEGER NOT NULL DEFAULT 1,
    alert_mention INTEGER NOT NULL DEFAULT 1,
    alert_poll INTEGER NOT NULL DEFAULT 1,
    alert_status INTEGER NOT NULL DEFAULT 0,
    alert_update INTEGER NOT NULL DEFAULT 0,
    policy TEXT NOT NULL DEFAULT 'all',
    content_encoding TEXT NOT NULL DEFAULT 'aesgcm',
    created_at TEXT NOT NULL,
    updated_at TEXT NOT NULL
);\n");
}

/**
 * @param array<string,mixed> $tokenRow from ap_masto_require_token / oauth lookup
 * @return array<string,mixed> WebPushSubscription entity
 */
function ap_webpush_subscription_upsert(array $tokenRow, string $accessTokenPlain, array $input): array
{
    ap_webpush_migrate();
    $tokenId = (int) ($tokenRow['id'] ?? 0);
    if ($tokenId < 1) {
        throw new RuntimeException('missing token id');
    }
    $endpoint = trim((string) ($input['subscription']['endpoint']
        ?? $input['subscription[endpoint]']
        ?? ''));
    $p256dh = trim((string) ($input['subscription']['keys']['p256dh']
        ?? $input['subscription[keys][p256dh]']
        ?? ''));
    $auth = trim((string) ($input['subscription']['keys']['auth']
        ?? $input['subscription[keys][auth]']
        ?? ''));
    if ($endpoint === '' || !str_starts_with($endpoint, 'https://')) {
        throw new InvalidArgumentException('subscription[endpoint] required (https)');
    }
    if ($p256dh === '' || $auth === '') {
        throw new InvalidArgumentException('subscription keys required');
    }

    $alertsIn = $input['data']['alerts'] ?? [];
    if (!is_array($alertsIn)) {
        $alertsIn = [];
    }
    // Also accept flat data[alerts][follow] form fields
    foreach (['follow', 'favourite', 'reblog', 'mention', 'poll', 'status', 'update'] as $k) {
        $flat = $input['data[alerts][' . $k . ']'] ?? null;
        if ($flat !== null && !isset($alertsIn[$k])) {
            $alertsIn[$k] = $flat;
        }
    }
    $bool = static function ($v, bool $default): int {
        if ($v === null) {
            return $default ? 1 : 0;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        $s = strtolower(trim((string) $v));
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }
        return $default ? 1 : 0;
    };

    $now = ap_db_now();
    $policy = trim((string) ($input['policy'] ?? 'all')) ?: 'all';
    $encoding = !empty($input['subscription']['standard']) || !empty($input['subscription[standard]'])
        ? 'aes128gcm'
        : 'aesgcm';

    ap_db()->prepare(
        'INSERT INTO push_subscriptions
         (token_id, access_token, endpoint, p256dh, auth_key,
          alert_follow, alert_favourite, alert_reblog, alert_mention, alert_poll, alert_status, alert_update,
          policy, content_encoding, created_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(token_id) DO UPDATE SET
           access_token = excluded.access_token,
           endpoint = excluded.endpoint,
           p256dh = excluded.p256dh,
           auth_key = excluded.auth_key,
           alert_follow = excluded.alert_follow,
           alert_favourite = excluded.alert_favourite,
           alert_reblog = excluded.alert_reblog,
           alert_mention = excluded.alert_mention,
           alert_poll = excluded.alert_poll,
           alert_status = excluded.alert_status,
           alert_update = excluded.alert_update,
           policy = excluded.policy,
           content_encoding = excluded.content_encoding,
           updated_at = excluded.updated_at'
    )->execute([
        $tokenId,
        $accessTokenPlain,
        $endpoint,
        $p256dh,
        $auth,
        $bool($alertsIn['follow'] ?? null, true),
        $bool($alertsIn['favourite'] ?? null, true),
        $bool($alertsIn['reblog'] ?? null, true),
        $bool($alertsIn['mention'] ?? null, true),
        $bool($alertsIn['poll'] ?? null, true),
        $bool($alertsIn['status'] ?? null, false),
        $bool($alertsIn['update'] ?? null, false),
        mb_substr($policy, 0, 40),
        $encoding,
        $now,
        $now,
    ]);

    $row = ap_webpush_subscription_by_token_id($tokenId);
    if ($row === null) {
        throw new RuntimeException('subscription save failed');
    }
    return ap_webpush_subscription_entity($row);
}

/**
 * Update alerts only (PUT).
 *
 * @return array<string,mixed>|null
 */
function ap_webpush_subscription_update_alerts(int $tokenId, array $input): ?array
{
    ap_webpush_migrate();
    $row = ap_webpush_subscription_by_token_id($tokenId);
    if ($row === null) {
        return null;
    }
    $alertsIn = $input['data']['alerts'] ?? [];
    if (!is_array($alertsIn)) {
        $alertsIn = [];
    }
    foreach (['follow', 'favourite', 'reblog', 'mention', 'poll', 'status', 'update'] as $k) {
        $flat = $input['data[alerts][' . $k . ']'] ?? null;
        if ($flat !== null && !isset($alertsIn[$k])) {
            $alertsIn[$k] = $flat;
        }
    }
    $bool = static function ($v, int $current): int {
        if ($v === null) {
            return $current;
        }
        if (is_bool($v)) {
            return $v ? 1 : 0;
        }
        $s = strtolower(trim((string) $v));
        if (in_array($s, ['1', 'true', 'yes', 'on'], true)) {
            return 1;
        }
        if (in_array($s, ['0', 'false', 'no', 'off'], true)) {
            return 0;
        }
        return $current;
    };
    ap_db()->prepare(
        'UPDATE push_subscriptions SET
           alert_follow = ?, alert_favourite = ?, alert_reblog = ?, alert_mention = ?,
           alert_poll = ?, alert_status = ?, alert_update = ?, updated_at = ?
         WHERE token_id = ?'
    )->execute([
        $bool($alertsIn['follow'] ?? null, (int) $row['alert_follow']),
        $bool($alertsIn['favourite'] ?? null, (int) $row['alert_favourite']),
        $bool($alertsIn['reblog'] ?? null, (int) $row['alert_reblog']),
        $bool($alertsIn['mention'] ?? null, (int) $row['alert_mention']),
        $bool($alertsIn['poll'] ?? null, (int) $row['alert_poll']),
        $bool($alertsIn['status'] ?? null, (int) $row['alert_status']),
        $bool($alertsIn['update'] ?? null, (int) $row['alert_update']),
        ap_db_now(),
        $tokenId,
    ]);
    $row = ap_webpush_subscription_by_token_id($tokenId);
    return $row ? ap_webpush_subscription_entity($row) : null;
}

function ap_webpush_subscription_by_token_id(int $tokenId): ?array
{
    ap_webpush_migrate();
    $st = ap_db()->prepare('SELECT * FROM push_subscriptions WHERE token_id = ?');
    $st->execute([$tokenId]);
    $row = $st->fetch();
    return is_array($row) ? $row : null;
}

function ap_webpush_subscription_delete(int $tokenId): bool
{
    ap_webpush_migrate();
    $st = ap_db()->prepare('DELETE FROM push_subscriptions WHERE token_id = ?');
    $st->execute([$tokenId]);
    return $st->rowCount() > 0;
}

/**
 * @param array<string,mixed> $row
 * @return array<string,mixed>
 */
function ap_webpush_subscription_entity(array $row): array
{
    // Ice Cubes decodes id as Int (not String) — must be a JSON number.
    return [
        'id' => (int) ($row['id'] ?? 0),
        'endpoint' => (string) ($row['endpoint'] ?? ''),
        'server_key' => ap_webpush_vapid_public_key(),
        'alerts' => [
            'follow' => !empty($row['alert_follow']),
            'favourite' => !empty($row['alert_favourite']),
            'reblog' => !empty($row['alert_reblog']),
            'mention' => !empty($row['alert_mention']),
            'poll' => !empty($row['alert_poll']),
            'status' => !empty($row['alert_status']),
            // Ice Cubes PushSubscription.Alerts has no "update" field; omit it.
        ],
        'policy' => (string) ($row['policy'] ?? 'all'),
    ];
}

/**
 * Map our notification type to subscription alert column.
 * Quotes use the mention alert toggle (Ice Cubes has no separate quote switch).
 */
function ap_webpush_alert_enabled(array $sub, string $type): bool
{
    return match ($type) {
        'follow' => !empty($sub['alert_follow']),
        'favourite' => !empty($sub['alert_favourite']),
        'reblog' => !empty($sub['alert_reblog']),
        'mention', 'quote', 'bite' => !empty($sub['alert_mention']),
        // DMs are high-signal — always push when a subscription exists.
        'dm' => true,
        'poll' => !empty($sub['alert_poll']),
        'status' => !empty($sub['alert_status']),
        'update' => !empty($sub['alert_update']),
        default => false,
    };
}

/**
 * Best-effort push to active subscriptions for ONE recipient account.
 * Never broadcast to every Ice Cubes login on the instance (ghost badges).
 *
 * @param array{type:string,notification_id:string|int,title:string,body?:string,icon?:string,owner_user_id?:int} $event
 */
function ap_webpush_dispatch(array $event): void
{
    try {
        ap_webpush_migrate();
        $type = (string) ($event['type'] ?? '');
        if ($type === '') {
            return;
        }
        $ownerUserId = (int) ($event['owner_user_id'] ?? 0);
        if ($ownerUserId < 1) {
            // Fail closed — missing owner used to fan out to every token on the server.
            error_log('[ap-webpush] dispatch skipped: missing owner_user_id type=' . $type);
            return;
        }
        $vapid = ap_webpush_vapid_keys();
        if ($vapid === null || !is_readable(AP_WEBPUSH_AUTOLOAD)) {
            return;
        }
        require_once AP_WEBPUSH_AUTOLOAD;

        // Only this account's OAuth tokens. Legacy NULL user_id rows = cmdr_nova.
        $cmdrId = function_exists('ap_db_cmdr_nova_user_id') ? ap_db_cmdr_nova_user_id() : 1;
        $st = ap_db()->prepare(
            'SELECT s.* FROM push_subscriptions s
             INNER JOIN oauth_tokens t ON t.id = s.token_id
             WHERE t.revoked_at IS NULL
               AND (
                 t.user_id = ?
                 OR (t.user_id IS NULL AND ? = ?)
               )'
        );
        $st->execute([$ownerUserId, $ownerUserId, $cmdrId]);
        $subs = $st->fetchAll() ?: [];
        if (!$subs) {
            return;
        }

        $auth = [
            'VAPID' => [
                'subject' => $vapid['subject'],
                'publicKey' => $vapid['publicKey'],
                'privateKey' => $vapid['privateKey'],
            ],
        ];
        $webPush = new \Minishlink\WebPush\WebPush($auth);
        $webPush->setReuseVAPIDHeaders(true);
        $webPush->setAutomaticPadding(true);

        $notifId = $event['notification_id'];
        // Ice Cubes decodes notification_id as Int — send numeric JSON
        $notifIdNum = is_numeric($notifId) ? (int) $notifId : (int) sprintf('%u', crc32((string) $notifId));

        foreach ($subs as $sub) {
            if (!is_array($sub) || !ap_webpush_alert_enabled($sub, $type)) {
                continue;
            }
            $payload = json_encode([
                'access_token' => (string) $sub['access_token'],
                'preferred_locale' => 'en',
                'notification_id' => $notifIdNum,
                'notification_type' => ($type === 'quote' || $type === 'dm') ? 'mention' : $type,
                'icon' => (string) ($event['icon'] ?? 'https://mkultra.monster/img/avatar/default.jpg'),
                'title' => (string) ($event['title'] ?? 'Nova'),
                'body' => (string) ($event['body'] ?? ''),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            if (!is_string($payload)) {
                continue;
            }
            $encoding = (string) ($sub['content_encoding'] ?? 'aesgcm');
            if ($encoding !== 'aes128gcm') {
                $encoding = 'aesgcm';
            }
            $subscription = \Minishlink\WebPush\Subscription::create([
                'endpoint' => (string) $sub['endpoint'],
                'publicKey' => (string) $sub['p256dh'],
                'authToken' => (string) $sub['auth_key'],
                'contentEncoding' => $encoding,
            ]);
            try {
                $webPush->queueNotification($subscription, $payload);
            } catch (Throwable $e) {
                error_log('[ap-webpush] queue: ' . $e->getMessage());
            }
        }

        foreach ($webPush->flush() as $report) {
            /** @var \Minishlink\WebPush\MessageSentReport $report */
            if (!$report->isSuccess()) {
                $code = $report->getResponse() ? $report->getResponse()->getStatusCode() : 0;
                // Gone / Not Found → drop subscription
                if (in_array($code, [404, 410], true)) {
                    $endpoint = $report->getEndpoint();
                    try {
                        ap_db()->prepare('DELETE FROM push_subscriptions WHERE endpoint = ?')
                            ->execute([$endpoint]);
                    } catch (Throwable $e) {
                        // ignore
                    }
                }
                error_log('[ap-webpush] send fail ' . $code . ' ' . $report->getReason());
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-webpush] dispatch: ' . $e->getMessage());
    }
}

/**
 * Fire push after a notifiable mention/follow is stored.
 * Safe to call from inbox; never throws to caller.
 *
 * @param int|null $ownerUserId Recipient ap_users.id — required for multi-user isolation.
 */
function ap_webpush_notify_event(
    string $type,
    string $actorId,
    ?string $notifId = null,
    ?string $body = null,
    ?int $ownerUserId = null
): void {
    try {
        if ($ownerUserId === null || $ownerUserId < 1) {
            // Prefer explicit request/token actor → their ap_users row
            $reqKey = '';
            if (function_exists('ap_request_actor_get')) {
                $req = ap_request_actor_get();
                $reqKey = is_array($req) ? (string) ($req['key'] ?? '') : '';
            }
            if ($reqKey === '') {
                $reqKey = strtolower(trim((string) ($GLOBALS['vaak_actor_key'] ?? '')));
            }
            if ($reqKey !== '' && function_exists('ap_local_user_by_username')) {
                $u = ap_local_user_by_username($reqKey);
                if (is_array($u)) {
                    $ownerUserId = (int) ($u['id'] ?? 0);
                }
            }
            if ($ownerUserId === null || $ownerUserId < 1) {
                $ownerUserId = function_exists('ap_db_default_owner_user_id')
                    ? ap_db_default_owner_user_id()
                    : 0;
            }
        }
        if ($ownerUserId < 1) {
            error_log('[ap-webpush] notify_event skipped: no owner type=' . $type);
            return;
        }

        // Soft-load helpers used for titles/avatars (inbox may not have pulled them in yet)
        if (!function_exists('ap_masto_remote_account')) {
            $ent = __DIR__ . '/ap-masto-entities.php';
            if (is_file($ent)) {
                require_once __DIR__ . '/ap-r2.php';
                require_once $ent;
            }
        }
        $icon = 'https://mkultra.monster/img/avatar/default.jpg';
        $acct = $actorId;
        if ($actorId !== '' && function_exists('ap_masto_remote_account')) {
            try {
                $acc = ap_masto_remote_account($actorId);
                $acct = (string) ($acc['acct'] ?? $acc['display_name'] ?? $actorId);
                if (!empty($acc['avatar'])) {
                    $icon = (string) $acc['avatar'];
                }
            } catch (Throwable $e) {
                // ignore
            }
        } elseif ($actorId !== '' && function_exists('ap_remote_media_account_urls')) {
            try {
                $urls = ap_remote_media_account_urls($actorId);
                if (!empty($urls['avatar'])) {
                    $icon = (string) $urls['avatar'];
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        $title = $acct . ' · ' . match ($type) {
            'follow' => 'followed you',
            'favourite' => 'favourited your post',
            'reblog' => 'boosted your post',
            'mention' => 'mentioned you',
            'quote' => 'quoted you',
            'poll' => 'poll ended',
            'update' => 'edited a post',
            'status' => 'posted',
            'bite' => 'bit you',
            'dm' => 'sent you a DM',
            default => $type,
        };
        $bodyText = $body !== null ? trim(strip_tags($body)) : '';
        if (function_exists('mb_substr') && mb_strlen($bodyText) > 140) {
            $bodyText = mb_substr($bodyText, 0, 137) . '…';
        } elseif (strlen($bodyText) > 140) {
            $bodyText = substr($bodyText, 0, 137) . '…';
        }
        ap_webpush_dispatch([
            'type' => $type,
            'notification_id' => $notifId ?? (string) time(),
            'title' => $title,
            'body' => $bodyText,
            'icon' => $icon,
            'owner_user_id' => $ownerUserId,
        ]);
    } catch (Throwable $e) {
        error_log('[ap-webpush] notify_event: ' . $e->getMessage());
    }
}

/** Stable OAuth client_id for VAAK browser push (not Ice Cubes). */
const AP_WEBPUSH_VAAK_WEB_CLIENT_ID = 'vaak-web-push';

/**
 * Ensure the VAAK Web OAuth app exists (for session-native PushManager subs).
 *
 * @return array<string,mixed>|null oauth_apps row
 */
function ap_webpush_ensure_vaak_web_app(): ?array
{
    $existing = function_exists('ap_oauth_app_by_client_id')
        ? ap_oauth_app_by_client_id(AP_WEBPUSH_VAAK_WEB_CLIENT_ID)
        : null;
    if (is_array($existing)) {
        return $existing;
    }
    try {
        $secret = bin2hex(random_bytes(32));
        $hash = password_hash($secret, PASSWORD_DEFAULT);
        ap_db()->prepare(
            'INSERT INTO oauth_apps (client_name, client_id, client_secret_hash, redirect_uris, scopes, website, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            'VAAK Web',
            AP_WEBPUSH_VAAK_WEB_CLIENT_ID,
            $hash,
            'https://mkultra.monster/vaak/',
            'read push',
            'https://mkultra.monster/vaak/',
            ap_db_now(),
        ]);
    } catch (Throwable $e) {
        error_log('[ap-webpush] ensure vaak web app: ' . $e->getMessage());
    }
    return function_exists('ap_oauth_app_by_client_id')
        ? ap_oauth_app_by_client_id(AP_WEBPUSH_VAAK_WEB_CLIENT_ID)
        : null;
}

/**
 * Mint or reuse a VAAK Web oauth token for browser push.
 *
 * @return array{ok:bool,error?:string,token_row?:array,access_token?:string}
 */
function ap_webpush_vaak_web_token_for_user(int $userId): array
{
    if ($userId < 1) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    ap_webpush_migrate();
    $app = ap_webpush_ensure_vaak_web_app();
    if (!is_array($app)) {
        return ['ok' => false, 'error' => 'Could not provision VAAK Web push app.'];
    }
    $clientId = AP_WEBPUSH_VAAK_WEB_CLIENT_ID;
    // Prefer an existing non-revoked token that already has a push subscription
    // (we still have the plain access_token there).
    try {
        $st = ap_db()->prepare(
            'SELECT t.*, s.access_token AS push_access_token
             FROM oauth_tokens t
             LEFT JOIN push_subscriptions s ON s.token_id = t.id
             WHERE t.user_id = ? AND t.client_id = ? AND t.revoked_at IS NULL
             ORDER BY t.id DESC LIMIT 5'
        );
        $st->execute([$userId, $clientId]);
        foreach ($st->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $plain = trim((string) ($row['push_access_token'] ?? ''));
            if ($plain !== '') {
                return ['ok' => true, 'token_row' => $row, 'access_token' => $plain];
            }
        }
        // No reusable plain token — revoke leftovers and mint a fresh one.
        ap_db()->prepare(
            'UPDATE oauth_tokens SET revoked_at = ? WHERE user_id = ? AND client_id = ? AND revoked_at IS NULL'
        )->execute([ap_db_now(), $userId, $clientId]);
    } catch (Throwable $e) {
        error_log('[ap-webpush] vaak web token lookup: ' . $e->getMessage());
    }
    if (!function_exists('ap_oauth_token_create')) {
        return ['ok' => false, 'error' => 'OAuth unavailable.'];
    }
    $created = ap_oauth_token_create($clientId, 'read push', $userId);
    $plain = (string) ($created['access_token'] ?? '');
    if ($plain === '') {
        return ['ok' => false, 'error' => 'Could not mint push token.'];
    }
    $hash = hash('sha256', $plain);
    $st2 = ap_db()->prepare('SELECT * FROM oauth_tokens WHERE access_token_hash = ? LIMIT 1');
    $st2->execute([$hash]);
    $tokenRow = $st2->fetch();
    if (!is_array($tokenRow)) {
        return ['ok' => false, 'error' => 'Token created but not found.'];
    }
    return ['ok' => true, 'token_row' => $tokenRow, 'access_token' => $plain];
}

/**
 * Whether this user currently has any active browser/app push subscription.
 */
function ap_webpush_user_has_subscription(int $userId): bool
{
    if ($userId < 1) {
        return false;
    }
    try {
        ap_webpush_migrate();
        $cmdrId = function_exists('ap_db_cmdr_nova_user_id') ? ap_db_cmdr_nova_user_id() : 1;
        $st = ap_db()->prepare(
            'SELECT 1 FROM push_subscriptions s
             INNER JOIN oauth_tokens t ON t.id = s.token_id
             WHERE t.revoked_at IS NULL
               AND (t.user_id = ? OR (t.user_id IS NULL AND ? = ?))
             LIMIT 1'
        );
        $st->execute([$userId, $userId, $cmdrId]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}
