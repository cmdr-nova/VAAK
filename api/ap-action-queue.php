<?php
declare(strict_types=1);

/** Durable desired-state queue for account-scoped reversible interactions. */
function ap_action_queue_make_tid(int $rowId): string
{
    $alphabet = '234567abcdefghijklmnopqrstuvwxyz';
    $micros = (int) floor(microtime(true) * 1000000);
    $value = ($micros * 1024) + ($rowId & 1023);
    $tid = '';
    for ($i = 0; $i < 13; $i++) {
        $tid = $alphabet[$value % 32] . $tid;
        $value = intdiv($value, 32);
    }
    return $tid;
}

function ap_action_queue_enqueue(
    int $ownerUserId,
    string $platform,
    string $kind,
    string $targetKey,
    bool $desired,
    array $payload,
    array $receipt = [],
    int $raceRetry = 1
): array {
    if ($ownerUserId < 1 || !in_array($platform, ['fedi', 'bsky'], true)
        || !in_array($kind, ['like', 'boost', 'bookmark', 'follow'], true)
        || $targetKey === '' || strlen($targetKey) > 2048) {
        return ['ok' => false, 'error' => 'Invalid queued action.'];
    }
    $now = gmdate('c');
    $db = ap_db();
    $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    $receiptJson = $receipt ? json_encode($receipt, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) : null;
    if (!is_string($payloadJson) || strlen($payloadJson) > 12000) {
        return ['ok' => false, 'error' => 'Action details are too large.'];
    }
    try {
        $db->beginTransaction();
        $selectSql = 'SELECT * FROM ap_action_queue WHERE owner_user_id = ? AND platform = ? AND action_kind = ? AND target_key = ?';
        if (ap_db_driver($db) === 'pgsql') $selectSql .= ' FOR UPDATE';
        $st = $db->prepare($selectSql);
        $st->execute([$ownerUserId, $platform, $kind, $targetKey]);
        $old = $st->fetch();
        if (is_array($old)) {
            if ($platform === 'bsky' && in_array($kind, ['like', 'boost', 'follow'], true)) {
                $oldPayload = json_decode((string) ($old['payload_json'] ?? '{}'), true) ?: [];
                $payload['record_key'] = (string) ($oldPayload['record_key'] ?? ap_action_queue_make_tid((int) $old['id']));
                $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            }
            $oldStatus = (string) ($old['status'] ?? '');
            $sameDesiredInFlight = (int) $old['desired_state'] === (int) $desired
                && in_array($oldStatus, ['pending', 'processing'], true);
            $sameConfirmed = $old['confirmed_state'] !== null
                && (int) $old['confirmed_state'] === (int) $desired
                && in_array($oldStatus, ['succeeded', 'pending'], true);
            if ($sameDesiredInFlight || ($sameConfirmed && (int) $old['desired_state'] === (int) $desired)) {
                $db->commit();
                return ['ok' => true, 'id' => (int) $old['id'], 'revision' => (int) $old['revision'],
                    'status' => (string) $old['status'], 'coalesced' => true,
                    'confirmed_state' => (int) $old['confirmed_state'], 'receipt' => json_decode((string) ($old['receipt_json'] ?? '{}'), true) ?: []];
            }
            $newReceipt = $receiptJson !== null ? $receiptJson : ($old['receipt_json'] ?? null);
            $u = $db->prepare("UPDATE ap_action_queue SET desired_state = ?, revision = revision + 1,
                payload_json = ?, receipt_json = ?, status = 'pending', attempts = 0,
                next_attempt_at = ?, claimed_at = NULL, last_error = NULL, result_json = NULL, updated_at = ? WHERE id = ?");
            $u->execute([(int) $desired, $payloadJson, $newReceipt, $now, $now, (int) $old['id']]);
            $id = (int) $old['id'];
            $revision = (int) $old['revision'] + 1;
            $status = 'pending';
        } else {
            $ins = $db->prepare("INSERT INTO ap_action_queue
                (owner_user_id, platform, action_kind, target_key, desired_state, confirmed_state, revision,
                 payload_json, status, attempts, max_attempts, next_attempt_at, claimed_at, last_error,
                 result_json, receipt_json, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, NULL, 1, ?, 'pending', 0, 12, ?, NULL, NULL, NULL, ?, ?, ?)");
            $ins->execute([$ownerUserId, $platform, $kind, $targetKey, (int) $desired, $payloadJson, $now, $receiptJson, $now, $now]);
            $id = ap_db_last_insert_id('ap_action_queue', 'id', $db);
            if ($platform === 'bsky' && in_array($kind, ['like', 'boost', 'follow'], true)) {
                $payload['record_key'] = ap_action_queue_make_tid($id);
                $payloadJson = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $db->prepare('UPDATE ap_action_queue SET payload_json = ? WHERE id = ?')->execute([$payloadJson, $id]);
            }
            $revision = 1;
            $status = 'pending';
        }
        $db->commit();
        return ['ok' => true, 'id' => $id, 'revision' => $revision, 'status' => $status, 'coalesced' => false];
    } catch (Throwable $e) {
        if ($db->inTransaction()) $db->rollBack();
        if ($raceRetry > 0 && ($e instanceof PDOException)
            && (in_array((string) $e->getCode(), ['23000', '23505'], true) || str_contains(strtolower($e->getMessage()), 'unique constraint'))) {
            return ap_action_queue_enqueue($ownerUserId, $platform, $kind, $targetKey, $desired, $payload, $receipt, $raceRetry - 1);
        }
        error_log('[ap-action-queue] enqueue: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not save action to the queue.'];
    }
}

function ap_action_queue_status(int $ownerUserId, int $id): ?array
{
    if ($ownerUserId < 1 || $id < 1) return null;
    try {
        $st = ap_db()->prepare('SELECT id, platform, action_kind, target_key, desired_state, confirmed_state, revision, status, last_error, updated_at FROM ap_action_queue WHERE id = ? AND owner_user_id = ?');
        $st->execute([$id, $ownerUserId]);
        $row = $st->fetch();
        if (!is_array($row)) return null;
        if ((string) ($row['status'] ?? '') === 'failed') {
            $row['last_error'] = 'The action could not be completed after retries. Check the connected account and try again.';
        } else {
            $row['last_error'] = null;
        }
        return $row;
    } catch (Throwable $e) {
        return null;
    }
}

function ap_action_queue_backoff(int $attempt): int
{
    return min(3600, 15 * (2 ** max(0, min(8, $attempt - 1))));
}

function ap_action_queue_remote_state_already_applied(string $error, bool $desired): bool
{
    $e = strtolower($error);
    return $desired
        ? (str_contains($e, 'already exists') || str_contains($e, 'already bookmarked') || str_contains($e, 'duplicate'))
        : (str_contains($e, 'not found') || str_contains($e, 'does not exist') || str_contains($e, 'already removed'));
}

function ap_action_queue_clear_caches(int $ownerUserId, string $platform): void
{
    if ($platform === 'bsky' && function_exists('ap_bsky_tl_cache_clear_owner')) {
        ap_bsky_tl_cache_clear_owner($ownerUserId);
    }
    // Mirror the admin's short-lived ranked timeline cache location without
    // loading the web controller in the CLI worker.
    foreach (['/var/lib/mkultra/ap/admin-tl-cache', sys_get_temp_dir()] as $dir) {
        if (!is_dir($dir) || !is_writable($dir)) continue;
        foreach (glob(rtrim($dir, '/') . '/tl_*.json') ?: [] as $path) {
            if (is_file($path) && is_writable($path)) @unlink($path);
        }
        if ($dir === '/var/lib/mkultra/ap/admin-tl-cache') break;
    }
}

/** Bind worker execution to the row owner; never use the legacy default actor. */
function ap_action_queue_bind_owner(int $ownerUserId): bool
{
    if (!function_exists('ap_actor_key_paths')) {
        if (!defined('AP_INBOX_LIB_ONLY')) define('AP_INBOX_LIB_ONLY', true);
        require_once __DIR__ . '/ap-inbox.php';
    }
    $st = ap_db()->prepare('SELECT * FROM ap_users WHERE id = ? AND disabled_at IS NULL LIMIT 1');
    $st->execute([$ownerUserId]);
    $user = $st->fetch();
    if (!is_array($user) || empty($user['actor_key'])) return false;
    $GLOBALS['vaak_owner_id'] = $ownerUserId;
    $GLOBALS['vaak_user'] = $user;
    $GLOBALS['vaak_actor_key'] = (string) $user['actor_key'];
    $GLOBALS['vaak_actor_id'] = (string) ($user['actor_id'] ?? '');
    ap_request_actor_set((string) $user['actor_key']);
    return true;
}

/** Worker-safe equivalent of the admin UI's own-actor URL test. */
function ap_action_queue_is_own_object(string $objectId): bool
{
    $objectId = rtrim(trim($objectId), '/');
    $actorId = rtrim(ap_local_actor_id(), '/');
    return $objectId !== '' && $actorId !== ''
        && ($objectId === $actorId || str_starts_with($objectId, $actorId . '/'));
}

/** @return array{ok:bool,error?:string,receipt?:array} */
function ap_action_queue_execute(array $row, array $payload, array $receipt): array
{
    $owner = (int) ($row['owner_user_id'] ?? 0);
    if (!ap_action_queue_bind_owner($owner)) return ['ok' => false, 'error' => 'Action account is unavailable.'];
    $platform = (string) $row['platform'];
    $kind = (string) $row['action_kind'];
    $want = !empty($row['desired_state']);
    $target = (string) $row['target_key'];

    try {
        if ($platform === 'bsky') {
            require_once __DIR__ . '/ap-bsky.php';
            if ($kind === 'like' || $kind === 'boost') {
                $collection = $kind === 'like' ? 'app.bsky.feed.like' : 'app.bsky.feed.repost';
                $recordUri = trim((string) ($receipt['record_uri'] ?? $payload['record_uri'] ?? ''));
                if ($want) {
                    $rkey = (string) ($payload['record_key'] ?? '');
                    if (!preg_match('/^[234567abcdefghij][234567abcdefghijklmnopqrstuvwxyz]{12}$/', $rkey)) {
                        return ['ok' => false, 'error' => 'Missing valid Bluesky record key for retry.'];
                    }
                    $session = ap_bsky_session_row($owner);
                    if (is_array($session) && !empty($session['did'])) {
                        $receipt = ['record_uri' => 'at://' . (string) $session['did'] . '/' . $collection . '/' . $rkey, 'collection' => $collection];
                    }
                    $res = $kind === 'like'
                        ? ap_bsky_create_like($owner, ['uri' => (string) ($payload['uri'] ?? $target), 'cid' => (string) ($payload['cid'] ?? '')], $rkey)
                        : ap_bsky_create_repost($owner, ['uri' => (string) ($payload['uri'] ?? $target), 'cid' => (string) ($payload['cid'] ?? '')], $rkey);
                    if (empty($res['ok'])) return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Bluesky action failed.'), 'receipt' => $receipt];
                    return ['ok' => true, 'receipt' => ['record_uri' => (string) ($res['uri'] ?? $receipt['record_uri'] ?? ''), 'collection' => $collection]];
                }
                if ($recordUri === '' && $kind === 'boost') {
                    $res = ap_bsky_unrepost_object($owner, (string) ($payload['uri'] ?? $target));
                    return !empty($res['ok']) ? ['ok' => true, 'receipt' => []]
                        : ['ok' => false, 'error' => (string) ($res['error'] ?? 'Bluesky repost removal failed.')];
                }
                if ($recordUri === '') return ['ok' => true, 'receipt' => []];
                $res = ap_bsky_delete_record_uri($owner, $recordUri);
                $err = (string) ($res['error'] ?? 'Bluesky undo failed.');
                return !empty($res['ok']) || ap_action_queue_remote_state_already_applied($err, false)
                    ? ['ok' => true, 'receipt' => []] : ['ok' => false, 'error' => $err];
            }
            if ($kind === 'bookmark') {
                $res = $want
                    ? ap_bsky_create_bookmark($owner, ['uri' => (string) ($payload['uri'] ?? $target), 'cid' => (string) ($payload['cid'] ?? '')])
                    : ap_bsky_delete_bookmark($owner, $target);
                if (empty($res['ok'])) {
                    $err = (string) ($res['error'] ?? 'Bluesky bookmark failed.');
                    if (ap_action_queue_remote_state_already_applied($err, $want)) $res['ok'] = true;
                    if (empty($res['ok'])) return ['ok' => false, 'error' => $err];
                }
                if (function_exists('ap_bsky_tl_cache_clear_owner')) ap_bsky_tl_cache_clear_owner($owner);
                $statusId = (string) ($payload['status_id'] ?? '');
                if ($statusId !== '') {
                    if ($want) ap_masto_bookmark_add($statusId, (string) ($payload['object_id'] ?? $target), $owner);
                    else ap_masto_bookmark_remove($statusId, $owner);
                }
                return ['ok' => true, 'receipt' => []];
            }
            if ($kind === 'follow') {
                $res = $want ? ap_bsky_follow_actor($owner, $target, (string) ($payload['record_key'] ?? '')) : ap_bsky_unfollow_actor($owner, $target);
                return !empty($res['ok']) ? ['ok' => true, 'receipt' => ['record_uri' => (string) ($res['uri'] ?? $receipt['record_uri'] ?? '')]]
                    : ['ok' => false, 'error' => (string) ($res['error'] ?? 'Bluesky follow failed.')];
            }
        } else {
            require_once __DIR__ . '/ap-masto-entities.php';
            if ($kind === 'like') {
                require_once __DIR__ . '/ap-inbox.php';
                $sid = (string) ($payload['status_id'] ?? '');
                $obj = (string) ($payload['object_id'] ?? $target);
                $actor = (string) ($payload['target_actor'] ?? '');
                if (!preg_match('/^\d+$/', $sid)) return ['ok' => false, 'error' => 'Invalid Fediverse status reference.'];
                if ($want) {
                    $likeId = null;
                    if ($actor !== '' && $obj !== '' && !ap_action_queue_is_own_object($obj)) {
                        $localActor = rtrim(ap_local_actor_id(), '/');
                        $stableLikeId = $localActor . '/likes/queue-' . (int) $row['id'] . '-' . (int) $row['revision'];
                        $sent = ap_cmdr_send_like($obj, $actor, $stableLikeId);
                        if (empty($sent['ok'])) return ['ok' => false, 'error' => (string) ($sent['error'] ?? 'Fediverse like delivery failed.')];
                        $likeId = $sent['like_id'] ?? null;
                    }
                    ap_masto_favourite_add($sid, $obj, $actor !== '' ? $actor : null, is_string($likeId) ? $likeId : null, $owner);
                    return ['ok' => true, 'receipt' => ['like_activity_id' => (string) ($likeId ?? ''), 'object_id' => $obj, 'target_actor' => $actor]];
                }
                $prev = ap_masto_favourite_remove($sid, $owner);
                $likeId = (string) ($prev['like_activity_id'] ?? $receipt['like_activity_id'] ?? '');
                if ($likeId !== '' && $obj !== '' && $actor !== '') {
                    $localActor = rtrim(ap_local_actor_id(), '/');
                    $stableUndoId = $localActor . '/undos/queue-like-' . (int) $row['id'] . '-' . (int) $row['revision'];
                    $undo = ap_cmdr_send_undo_like($likeId, $obj, $actor, $stableUndoId);
                    if (empty($undo['ok'])) return ['ok' => false, 'error' => (string) ($undo['error'] ?? 'Fediverse unlike delivery failed.')];
                }
                return ['ok' => true, 'receipt' => []];
            }
            if ($kind === 'bookmark') {
                $sid = (string) ($payload['status_id'] ?? $target);
                if ($want) ap_masto_bookmark_add($sid, (string) ($payload['object_id'] ?? $target), $owner);
                else ap_masto_bookmark_remove($sid, $owner);
                return ['ok' => true, 'receipt' => []];
            }
            if ($kind === 'follow') {
                require_once __DIR__ . '/ap-inbox.php';
                $localActor = rtrim(ap_local_actor_id(), '/');
                $stableFollowId = $localActor . '/follows/queue-' . (int) $row['id'] . '-' . (int) $row['revision'];
                $res = $want
                    ? ap_follow_remote_actor($target, true, $stableFollowId)
                    : ap_unfollow_remote_actor($target, (string) ($receipt['follow_id'] ?? ''), $localActor . '/undos/queue-follow-' . (int) $row['id'] . '-' . (int) $row['revision']);
                return !empty($res['ok']) ? ['ok' => true, 'receipt' => $want
                    ? ['follow_id' => (string) ($res['follow_id'] ?? $stableFollowId)] : []]
                    : ['ok' => false, 'error' => (string) ($res['error'] ?? 'Fediverse follow failed.')];
            }
            if ($kind === 'boost') {
                require_once __DIR__ . '/ap-inbox.php';
                $resolved = ap_masto_resolve_status_interaction((int) ($payload['status_id'] ?? 0));
                if (!$resolved) return ['ok' => false, 'error' => 'Fediverse status is no longer available.'];
                $localActor = rtrim(ap_local_actor_id(), '/');
                $res = ap_masto_reblog_perform($resolved, !$want,
                    $localActor . '/announces/queue-' . (int) $row['id'] . '-' . (int) $row['revision'],
                    $localActor . '/undos/queue-boost-' . (int) $row['id'] . '-' . (int) $row['revision'], true);
                if (!empty($res['ok']) && function_exists('ap_bsky_resolve_strong_ref')) {
                    $ref = ap_bsky_resolve_strong_ref((string) ($resolved['object_id'] ?? ''), $owner);
                    if (is_array($ref) && !empty($ref['uri']) && !empty($ref['cid'])) {
                        ap_action_queue_enqueue($owner, 'bsky', 'boost', (string) $ref['uri'], $want,
                            ['uri' => (string) $ref['uri'], 'cid' => (string) $ref['cid'], 'object_id' => (string) ($resolved['object_id'] ?? '')]);
                    }
                }
                return !empty($res['ok']) ? ['ok' => true, 'receipt' => []]
                    : ['ok' => false, 'error' => (string) ($res['error'] ?? 'Fediverse boost failed.')];
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-action-queue] execute: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The network action could not be completed.'];
    }
    return ['ok' => false, 'error' => 'Unsupported queued action.'];
}

/** Single-worker bounded batch; network calls happen outside DB transactions. */
function ap_action_queue_worker_run(int $limit = 20): array
{
    $stats = ['claimed' => 0, 'succeeded' => 0, 'retried' => 0, 'failed' => 0];
    $lock = @fopen(sys_get_temp_dir() . '/vaak-action-queue.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $stats + ['busy' => 1];
    try {
        $db = ap_db();
        $limit = max(1, min(50, $limit));
        $now = gmdate('c');
        // Recover work abandoned by a killed worker after its lease expired.
        $stale = gmdate('c', time() - 600);
        $recover = $db->prepare("UPDATE ap_action_queue SET status = 'pending', claimed_at = NULL, updated_at = ? WHERE status = 'processing' AND claimed_at < ?");
        $recover->execute([$now, $stale]);
        $st = $db->prepare("SELECT * FROM ap_action_queue WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY next_attempt_at, id LIMIT ?");
        $st->bindValue(1, $now);
        $st->bindValue(2, $limit, PDO::PARAM_INT);
        $st->execute();
        foreach ($st->fetchAll() as $row) {
            $claim = $db->prepare("UPDATE ap_action_queue SET status = 'processing', claimed_at = ?, updated_at = ? WHERE id = ? AND status = 'pending'");
            $claim->execute([$now, $now, (int) $row['id']]);
            if ($claim->rowCount() !== 1) continue;
            $fresh = $db->prepare("SELECT * FROM ap_action_queue WHERE id = ? AND status = 'processing'");
            $fresh->execute([(int) $row['id']]);
            $row = $fresh->fetch();
            if (!is_array($row)) continue; // a newer click changed it before claim snapshot
            $stats['claimed']++;
            $row['desired_state'] = (int) $row['desired_state'];
            $rev = (int) $row['revision'];
            $payload = json_decode((string) $row['payload_json'], true) ?: [];
            $receipt = json_decode((string) ($row['receipt_json'] ?? '{}'), true) ?: [];
            $result = ap_action_queue_execute($row, $payload, $receipt);
            if (!empty($result['ok'])) ap_action_queue_clear_caches((int) $row['owner_user_id'], (string) $row['platform']);
            $cur = $db->prepare('SELECT revision, attempts, max_attempts FROM ap_action_queue WHERE id = ?');
            $cur->execute([(int) $row['id']]);
            $latest = $cur->fetch();
            if (!is_array($latest)) continue;
            $attempt = (int) $latest['attempts'] + 1;
            $changed = (int) $latest['revision'] !== $rev;
            $newReceipt = array_key_exists('receipt', $result) && is_array($result['receipt'])
                ? json_encode($result['receipt'], JSON_UNESCAPED_SLASHES)
                : ($row['receipt_json'] ?? null);
            if (!empty($result['ok'])) {
                $status = $changed ? 'pending' : 'succeeded';
                $update = $db->prepare("UPDATE ap_action_queue SET status = ?, confirmed_state = ?, attempts = 0, next_attempt_at = ?, claimed_at = NULL, last_error = NULL, result_json = ?, receipt_json = ?, updated_at = ? WHERE id = ? AND revision = ?");
                $update->execute([$status, (int) $row['desired_state'], gmdate('c'), json_encode(['ok' => true]), $newReceipt, gmdate('c'), (int) $row['id'], $rev]);
                if ($update->rowCount() !== 1) {
                    // A new intent arrived after our revision check. Preserve it
                    // and let the next pass reconcile against this result.
                    $again = $db->prepare("UPDATE ap_action_queue SET status = 'pending', confirmed_state = ?, attempts = 0, next_attempt_at = ?, claimed_at = NULL, last_error = NULL, result_json = ?, receipt_json = ?, updated_at = ? WHERE id = ?");
                    $again->execute([(int) $row['desired_state'], gmdate('c'), json_encode(['ok' => true]), $newReceipt, gmdate('c'), (int) $row['id']]);
                }
                $stats['succeeded']++;
            } else {
                $max = max(1, (int) $latest['max_attempts']);
                $dead = !$changed && $attempt >= $max;
                $status = ($changed || !$dead) ? 'pending' : 'failed';
                $delay = $changed ? 0 : ap_action_queue_backoff($attempt);
                $update = $db->prepare('UPDATE ap_action_queue SET status = ?, attempts = ?, next_attempt_at = ?, claimed_at = NULL, last_error = ?, receipt_json = ?, updated_at = ? WHERE id = ? AND revision = ?');
                $update->execute([$status, $attempt, gmdate('c', time() + $delay), substr((string) ($result['error'] ?? 'Action failed.'), 0, 400), $newReceipt, gmdate('c'), (int) $row['id'], $rev]);
                if ($update->rowCount() !== 1) {
                    $again = $db->prepare("UPDATE ap_action_queue SET status = 'pending', attempts = 0, next_attempt_at = ?, claimed_at = NULL, last_error = NULL, receipt_json = ?, updated_at = ? WHERE id = ?");
                    $again->execute([gmdate('c'), $newReceipt, gmdate('c'), (int) $row['id']]);
                }
                $stats[$status === 'failed' ? 'failed' : 'retried']++;
            }
        }
    } finally {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $stats;
}
