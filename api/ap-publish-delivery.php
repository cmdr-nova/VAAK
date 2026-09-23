<?php
/** Durable at-least-once delivery for locally committed posts. */
declare(strict_types=1);

function ap_publish_delivery_enqueue(int $owner, string $noteId, array $payload): bool
{
    if ($owner < 1 || $noteId === '') return false;
    $now = gmdate('c');
    // Lower values run first: replies/mentions should reach their target
    // before bulk publication and backfill work.
    $priority = !empty($payload['in_reply_to']) ? 10 : (!empty($payload['quote_object_id']) ? 20 : 50);
    $db = ap_db();
    $st = $db->prepare("INSERT INTO ap_publish_delivery_queue
        (owner_user_id,note_id,payload_json,status,priority,attempts,next_attempt_at,created_at,updated_at)
        VALUES (?,?,?,'pending',?,0,?,?,?) ON CONFLICT(note_id) DO NOTHING");
    $st->execute([$owner, $noteId, json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), $priority, $now, $now, $now]);
    if (function_exists('ap_redis_queue_push')) {
        ap_redis_queue_push('publish-delivery', $noteId);
    }
    return true;
}

function ap_publish_delivery_wake_async(): void
{
    if (!function_exists('exec') || !function_exists('shell_exec')) return;
    $script = __DIR__ . '/ap-publish-delivery-worker.php';
    if (!is_file($script)) return;
    $php = function_exists('ap_php_cli_binary')
        ? ap_php_cli_binary()
        : (defined('PHP_BINARY') && PHP_BINARY !== '' ? PHP_BINARY : '/usr/bin/php');
    $running = trim((string) @shell_exec("pgrep -fc 'ap-publish-delivery-worker.php' 2>/dev/null"));
    if ((int) $running > 0) return;
    $cmd = 'nohup ' . escapeshellarg($php) . ' ' . escapeshellarg($script)
        . ' --limit=10 >/dev/null 2>&1 </dev/null &';
    @exec($cmd);
}

function ap_publish_delivery_execute(array $row, array $payload): array
{
    $owner = (int) ($row['owner_user_id'] ?? 0);
    if (!ap_action_queue_bind_owner($owner)) return ['ok' => false, 'error' => 'Publishing account is unavailable.'];
    $db = ap_db();
    $st = $db->prepare('SELECT raw_create_json FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
    $st->execute([(string) $row['note_id'], rtrim((string) $row['note_id'], '/') . '/']);
    $raw = $st->fetchColumn();
    $create = is_string($raw) ? json_decode($raw, true) : null;
    if (!is_array($create) || empty($create['object']) || !is_array($create['object'])) {
        return ['ok' => false, 'error' => 'Locally stored post is unavailable.'];
    }
    $note = $create['object'];
    $actor = (string) ($create['actor'] ?? ap_local_actor_id());
    $visibility = (string) ($payload['visibility'] ?? 'public');
    $pendingQuote = false;
    $priority = [];
    $priorityActors = [];
    $replyTo = (string) ($payload['in_reply_to'] ?? '');
    if ($replyTo !== '') {
        $parent = ap_fetch_as2_object($replyTo);
        if (is_array($parent)) {
            $parentActor = ap_as_id($parent['attributedTo'] ?? null) ?: ap_as_id($parent['actor'] ?? null);
            if ($parentActor !== '') {
                $priorityActors[] = $parentActor;
                if (rtrim($parentActor, '/') !== rtrim($actor, '/')) {
                    $note['to'] = is_array($note['to'] ?? null) ? $note['to'] : [];
                    if (!in_array($parentActor, $note['to'], true)) $note['to'][] = $parentActor;
                }
            }
        }
    }
    $quoteId = (string) ($payload['quote_object_id'] ?? '');
    if ($quoteId !== '') {
        $sameActorQuote = str_starts_with(rtrim($quoteId, '/') . '/', rtrim($actor, '/') . '/notes/');
        // Bluesky / Bridgy AT targets are not ActivityPub QuoteAuthorization
        // subjects — never hold them as FEP-044f "pending" (that skipped the
        // Bluesky mirror entirely while still marking bsky_done).
        $isBskyQuote = str_starts_with($quoteId, 'https://bsky.app/')
            || str_starts_with($quoteId, 'at://')
            || str_contains($quoteId, 'bsky.brid.gy');
        if (!$sameActorQuote && !$isBskyQuote) {
            $pendingQuote = true;
            $qDoc = ap_fetch_as2_object($quoteId);
            if (is_array($qDoc)) {
                $qDoc = ap_unwrap_as2_object($qDoc);
                $qActor = ap_as_id($qDoc['attributedTo'] ?? null) ?: ap_as_id($qDoc['actor'] ?? null);
                if ($qActor && rtrim($qActor, '/') !== rtrim($actor, '/')) {
                    $note['cc'] = is_array($note['cc'] ?? null) ? $note['cc'] : [];
                    if (!in_array($qActor, $note['cc'], true) && !in_array($qActor, $note['to'] ?? [], true)) $note['cc'][] = $qActor;
                } elseif ($qActor) {
                    $pendingQuote = false;
                }
            }
        }
    }
    foreach (($note['tag'] ?? []) as $tag) {
        if (is_array($tag) && strtolower((string) ($tag['type'] ?? '')) === 'mention') {
            $id = (string) ($tag['href'] ?? '');
            if ($id !== '') $priorityActors[] = $id;
        }
    }
    // Persist reply/quote addressing resolved by the worker before the Create
    // is sent. This keeps remote object fetches off the request path while
    // preserving normal ActivityPub addressing.
    $create['object'] = $note;
    $create['to'] = $note['to'] ?? ($create['to'] ?? []);
    $create['cc'] = $note['cc'] ?? ($create['cc'] ?? []);
    $db->prepare('UPDATE outbox_notes SET raw_create_json = ?, to_json = ?, cc_json = ? WHERE id = ? OR id = ?')->execute([
        json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        json_encode($create['to'] ?? [], JSON_UNESCAPED_SLASHES),
        json_encode($create['cc'] ?? [], JSON_UNESCAPED_SLASHES),
        (string) $row['note_id'], rtrim((string) $row['note_id'], '/') . '/',
    ]);
    foreach (array_slice(array_unique($priorityActors), 0, 8) as $targetActor) {
        if ($targetActor === $actor || ap_is_blocked_actor($targetActor)) continue;
        $doc = ap_fetch_actor_doc($targetActor);
        if (!$doc) continue;
        $inbox = ap_resolve_personal_inbox_from_actor_doc($doc) ?: ap_resolve_inbox_from_actor_doc($doc);
        if ($inbox && !ap_is_blocked_inbox($inbox)
            && !str_contains($inbox, rtrim($actor, '/') . '/inbox')
            && !preg_match('#mkultra\.monster/users/[A-Za-z0-9_]+/inbox#', $inbox)
            && !str_contains($inbox, 'mkultra.monster/inbox')) $priority[] = $inbox;
    }

    if (empty($payload['fedi_done'])) {
        $fan = ($visibility === 'local')
            ? ['delivered' => 0, 'queued' => 0]
            : (($visibility === 'public')
            ? ap_deliver_public_activity($create, $priority, $pendingQuote)
            : ap_deliver_followers_activity($create, $priority));
        $payload['fedi_done'] = true;
        $db->prepare('UPDATE ap_publish_delivery_queue SET payload_json = ?, updated_at = ? WHERE id = ?')
            ->execute([json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), gmdate('c'), (int) $row['id']]);
        ap_log('publish_delivery fedi note=' . $row['note_id'] . ' delivered=' . (int) ($fan['delivered'] ?? 0) . ' queued=' . (int) ($fan['queued'] ?? 0));
    }
    $quoteId = (string) ($payload['quote_object_id'] ?? '');
    if ($quoteId !== '' && $pendingQuote && $visibility !== 'local' && empty($payload['quote_request_done'])) {
        if (!ap_quote_request_send($quoteId, $note)) return ['ok' => false, 'error' => 'Quote authorization request could not be queued.'];
        $payload['quote_request_done'] = true;
        $db->prepare('UPDATE ap_publish_delivery_queue SET payload_json = ?, updated_at = ? WHERE id = ?')
            ->execute([json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), gmdate('c'), (int) $row['id']]);
    } elseif ($quoteId !== '' && !$pendingQuote) {
        $payload['quote_request_done'] = true;
    }

    if (empty($payload['bsky_done'])) {
        require_once __DIR__ . '/ap-bsky.php';
        $bsky = null;
        if ($visibility !== 'local' && !$pendingQuote && ap_bsky_session_row($owner) !== null) {
            $bsky = ap_bsky_crosspost_status(
                $owner,
                (string) ($payload['content'] ?? ''),
                $visibility,
                array_values(array_map('intval', $payload['media_local_ids'] ?? [])),
                (string) ($payload['spoiler_text'] ?? ''),
                $replyTo !== '' ? $replyTo : null,
                $quoteId !== '' ? $quoteId : null,
                (string) $row['note_id']
            );
        }
        if (is_array($bsky) && !empty($bsky['ok']) && empty($bsky['skipped']) && !empty($bsky['uri'])) {
            $note = ap_note_attach_bsky_proxy($note, (string) $bsky['uri'], isset($bsky['cid']) ? (string) $bsky['cid'] : null);
            $create['object'] = $note;
            $db->prepare('UPDATE outbox_notes SET raw_create_json = ? WHERE id = ? OR id = ?')->execute([
                json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
                (string) $row['note_id'], rtrim((string) $row['note_id'], '/') . '/',
            ]);
            if (in_array($visibility, ['public', 'unlisted'], true)) {
                $update = ['@context' => 'https://www.w3.org/ns/activitystreams', 'id' => $actor . '/updates/' . bin2hex(random_bytes(8)),
                    'type' => 'Update', 'actor' => $actor, 'published' => gmdate('c'), 'to' => $create['to'] ?? [],
                    'cc' => $create['cc'] ?? [], 'object' => $note];
                if ($visibility === 'public') ap_deliver_public_activity($update, []);
                else ap_deliver_followers_activity($update, []);
            }
            if (function_exists('ap_bsky_crosspost_retry_mark_done')) ap_bsky_crosspost_retry_mark_done((string) $row['note_id']);
        } elseif (is_array($bsky) && $owner > 0 && function_exists('ap_bsky_crosspost_retry_enqueue')) {
            $reason = (string) ($bsky['error'] ?? 'Background publication retry');
            if (function_exists('ap_bsky_crosspost_should_retry') && ap_bsky_crosspost_should_retry($bsky)) {
                ap_bsky_crosspost_retry_enqueue((string) $row['note_id'], $owner, (int) ($payload['local_id'] ?? 0), $reason, 60);
            }
        }
        $payload['bsky_done'] = true;
        $db->prepare('UPDATE ap_publish_delivery_queue SET payload_json = ?, updated_at = ? WHERE id = ?')
            ->execute([json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), gmdate('c'), (int) $row['id']]);
    }
    return ['ok' => true];
}

function ap_publish_delivery_worker_run(int $limit = 10): array
{
    $stats = ['claimed' => 0, 'succeeded' => 0, 'retried' => 0, 'failed' => 0];
    $lock = @fopen(sys_get_temp_dir() . '/vaak-publish-delivery.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) return $stats + ['busy' => 1];
    try {
        $db = ap_db();
        $limit = max(1, min(50, $limit));
        $pending = (int) $db->query("SELECT COUNT(*) FROM ap_publish_delivery_queue WHERE status = 'pending'")->fetchColumn();
        if (function_exists('ap_worker_backpressure_limit')) {
            $limit = ap_worker_backpressure_limit('publish-delivery', $limit, $pending);
        }
        $now = gmdate('c');
        $staleAt = gmdate('c', time() - 600);
        $db->prepare("UPDATE ap_publish_delivery_queue SET status='failed',claimed_at=NULL,last_error='Worker lease repeatedly expired',updated_at=? WHERE status='processing' AND claimed_at < ? AND attempts >= 11")
            ->execute([$now, $staleAt]);
        $db->prepare("UPDATE ap_publish_delivery_queue SET status='pending',attempts=attempts+1,claimed_at=NULL,last_error='Worker lease expired; retrying',updated_at=? WHERE status='processing' AND claimed_at < ?")
            ->execute([$now, $staleAt]);
        $st = $db->prepare("SELECT * FROM ap_publish_delivery_queue WHERE status='pending' AND next_attempt_at <= ? ORDER BY priority, next_attempt_at, id LIMIT ?");
        $st->bindValue(1, $now); $st->bindValue(2, $limit, PDO::PARAM_INT); $st->execute();
        foreach ($st->fetchAll() as $row) {
            $claim = $db->prepare("UPDATE ap_publish_delivery_queue SET status='processing',claimed_at=?,updated_at=? WHERE id=? AND status='pending'");
            $claim->execute([$now, $now, (int) $row['id']]);
            if ($claim->rowCount() !== 1) continue;
            $stats['claimed']++;
            $payload = json_decode((string) $row['payload_json'], true) ?: [];
            try { $result = ap_publish_delivery_execute($row, $payload); }
            catch (Throwable $e) { error_log('[ap-publish-delivery] ' . $e->getMessage()); $result = ['ok' => false, 'error' => 'Delivery worker error']; }
            if (!empty($result['ok'])) {
                $db->prepare("UPDATE ap_publish_delivery_queue SET status='succeeded',claimed_at=NULL,last_error=NULL,updated_at=? WHERE id=?")
                    ->execute([gmdate('c'), (int) $row['id']]);
                $stats['succeeded']++;
            } else {
                $attempts = (int) $row['attempts'] + 1;
                $terminal = $attempts >= 12;
                $delay = min(3600, 15 * (2 ** min(8, $attempts - 1)));
                $db->prepare('UPDATE ap_publish_delivery_queue SET status=?,attempts=?,next_attempt_at=?,claimed_at=NULL,last_error=?,updated_at=? WHERE id=?')
                    ->execute([$terminal ? 'failed' : 'pending', $attempts, gmdate('c', time() + $delay), substr((string) ($result['error'] ?? 'Delivery failed'), 0, 400), gmdate('c'), (int) $row['id']]);
                $stats[$terminal ? 'failed' : 'retried']++;
            }
        }
    } finally { flock($lock, LOCK_UN); fclose($lock); }
    return $stats;
}
