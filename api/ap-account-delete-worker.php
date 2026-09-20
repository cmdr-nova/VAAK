<?php
/** Background cleanup for a confirmed local account deletion. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
$userId = (int) ($argv[1] ?? 0);
if ($userId < 1) exit(1);
define('AP_INBOX_LIB_ONLY', true);
require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-auth.php';
require_once __DIR__ . '/ap-inbox.php';

try {
    $st = ap_db()->prepare('SELECT actor_key, actor_id FROM ap_users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $user = $st->fetch();
    if (!is_array($user)) exit(0);
    $actorKey = (string) ($user['actor_key'] ?? '');
    $actorId = rtrim((string) ($user['actor_id'] ?? ''), '/');
    if ($actorKey === '' || $actorId === '') exit(1);
    ap_request_actor_set($actorKey);

    // Delete local posts in bounded batches. Each Delete activity uses the
    // existing delivery queue, so remote federation never blocks this worker.
    $st = ap_db()->prepare('SELECT local_id FROM masto_statuses WHERE note_id LIKE ? ORDER BY local_id LIMIT 2000');
    $st->execute([$actorId . '/notes/%']);
    foreach ($st->fetchAll() ?: [] as $row) {
        $localId = (int) ($row['local_id'] ?? 0);
        if ($localId > 0) {
            try { ap_delete_local_status($localId); }
            catch (Throwable $e) { error_log('[account-delete] status ' . $localId . ': ' . $e->getMessage()); }
        }
    }

    // Remove local relationships and profile data after posts are gone.
    foreach ([
        ['DELETE FROM followers WHERE owner_actor_id = ? OR actor_id = ?', [$actorId, $actorId]],
        ['DELETE FROM following WHERE owner_actor_id = ? OR actor_id = ?', [$actorId, $actorId]],
        ['DELETE FROM actor_profile WHERE actor_key = ?', [$actorKey]],
    ] as [$sql, $params]) {
        try { ap_db()->prepare($sql)->execute($params); } catch (Throwable $e) { error_log('[account-delete] cleanup: ' . $e->getMessage()); }
    }
    ap_log('account_delete_cleanup actor=' . $actorKey . ' user_id=' . $userId);
} catch (Throwable $e) {
    error_log('[account-delete] worker failed: ' . $e->getMessage());
    exit(1);
}
