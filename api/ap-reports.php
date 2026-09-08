<?php
/**
 * Federated reports via ActivityPub Flag (Mastodon-compatible).
 * Inbound: store Flags about us / our posts for review + dismiss.
 * Outbound: send Flag to a remote account's shared inbox / actor inbox.
 */
declare(strict_types=1);
require_once __DIR__ . '/ap-mail.php';
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


/**
 * @param list<string>|string $object
 * @return list<string>
 */
function ap_report_normalize_object_uris($object): array
{
    $uris = [];
    if (is_string($object) && $object !== '') {
        $uris[] = $object;
    } elseif (is_array($object)) {
        foreach ($object as $item) {
            if (is_string($item) && $item !== '') {
                $uris[] = $item;
            } elseif (is_array($item) && function_exists('ap_as_id')) {
                $id = ap_as_id($item);
                if (is_string($id) && $id !== '') {
                    $uris[] = $id;
                }
            }
        }
    }
    $out = [];
    $seen = [];
    foreach ($uris as $u) {
        $u = rtrim(trim($u), '/');
        if ($u === '' || !str_starts_with($u, 'https://') || isset($seen[$u])) {
            continue;
        }
        $seen[$u] = true;
        $out[] = $u;
    }
    return $out;
}

/**
 * Split Flag object URIs into target account + status URIs.
 *
 * @param list<string> $uris
 * @return array{target_actor:?string,status_uris:list<string>,about_us:bool}
 */
function ap_report_classify_uris(array $uris): array
{
    $self = defined('LOCAL_ACTOR')
        ? rtrim(LOCAL_ACTOR, '/')
        : 'https://mkultra.monster/users/cmdr_nova';
    $targetActor = null;
    $statuses = [];
    $aboutUs = false;
    foreach ($uris as $u) {
        if ($u === $self || str_starts_with($u, $self . '/')) {
            $aboutUs = true;
        }
        if (
            str_contains($u, '/notes/')
            || str_contains($u, '/statuses/')
            || str_contains($u, '/objects/')
            || str_contains($u, '/notice/')
            || preg_match('#/(?:post|posts|woot)/#i', $u)
        ) {
            $statuses[] = $u;
            continue;
        }
        // Prefer first actor-looking URI as target
        if ($targetActor === null) {
            $targetActor = $u;
        }
    }
    if ($targetActor === null && $statuses) {
        // Infer actor from first status URL
        if (function_exists('ap_masto_actor_url_from_object_url')) {
            $guess = ap_masto_actor_url_from_object_url($statuses[0]);
            if (is_string($guess) && $guess !== '') {
                $targetActor = rtrim($guess, '/');
            }
        }
        if ($targetActor === null && str_starts_with($statuses[0], $self . '/')) {
            $targetActor = $self;
        }
    }
    if ($targetActor === $self || ($targetActor !== null && str_starts_with($targetActor, $self . '/'))) {
        $aboutUs = true;
    }
    return [
        'target_actor' => $targetActor,
        'status_uris' => $statuses,
        'about_us' => $aboutUs,
    ];
}

/**
 * Ingest inbound Flag activity.
 *
 * @return array{ok:bool,id?:int,about_us?:bool,error?:string}
 */
function ap_report_ingest(array $activity): array
{
    $type = (string) ($activity['type'] ?? '');
    if ($type !== 'Flag') {
        return ['ok' => false, 'error' => 'Not a Flag'];
    }
    $reporter = function_exists('ap_as_id') ? ap_as_id($activity['actor'] ?? null) : null;
    if (!is_string($reporter) || $reporter === '') {
        return ['ok' => false, 'error' => 'Missing reporter'];
    }
    $reporter = rtrim($reporter, '/');
    $self = defined('LOCAL_ACTOR') ? rtrim(LOCAL_ACTOR, '/') : 'https://mkultra.monster/users/cmdr_nova';
    if ($reporter === $self) {
        return ['ok' => false, 'error' => 'Ignore self Flag'];
    }

    $activityId = function_exists('ap_as_id') ? ap_as_id($activity['id'] ?? null) : null;
    if (!is_string($activityId) || $activityId === '') {
        $activityId = $reporter . '/reports/' . bin2hex(random_bytes(8));
    }
    $comment = trim(strip_tags(function_exists('ap_fix_utf8')
        ? ap_fix_utf8((string) ($activity['content'] ?? ''))
        : (string) ($activity['content'] ?? '')));
    if (mb_strlen($comment) > 5000) {
        $comment = mb_substr($comment, 0, 5000);
    }

    $uris = ap_report_normalize_object_uris($activity['object'] ?? []);
    if ($uris === []) {
        return ['ok' => false, 'error' => 'Flag has no object'];
    }
    $class = ap_report_classify_uris($uris);
    $now = function_exists('ap_db_now') ? ap_db_now() : gmdate('c');

    $hadOpen = ap_reports_open_count() > 0;
    try {
        ap_db()->prepare(
            'INSERT INTO ap_reports
             (activity_id, direction, reporter_actor_id, target_actor_id, status_uris_json, comment, about_us, state, created_at, updated_at)
             VALUES (?, \'in\', ?, ?, ?, ?, ?, \'open\', ?, ?)
             ON CONFLICT(activity_id) DO UPDATE SET
               comment = excluded.comment,
               status_uris_json = excluded.status_uris_json,
               updated_at = excluded.updated_at'
        )->execute([
            $activityId,
            $reporter,
            $class['target_actor'],
            json_encode($class['status_uris'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $comment !== '' ? $comment : null,
            $class['about_us'] ? 1 : 0,
            $now,
            $now,
        ]);
        $id = ap_db_last_insert_id('ap_reports');
        if ($id < 1) {
            $st = ap_db()->prepare('SELECT id FROM ap_reports WHERE activity_id = ?');
            $st->execute([$activityId]);
            $id = (int) ($st->fetch()['id'] ?? 0);
        }
        if (!$hadOpen && $class['about_us']) {
            $adminEmail = '';
            try {
                $mailRow = ap_db()->query("SELECT email FROM ap_users WHERE actor_key = 'cmdr_nova' LIMIT 1")->fetch();
                $adminEmail = is_array($mailRow) ? trim((string) ($mailRow['email'] ?? '')) : '';
            } catch (Throwable $mailError) {
                error_log('[ap-reports] admin email lookup: ' . $mailError->getMessage());
            }
            $safeReporter = htmlspecialchars($reporter, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $safeTarget = htmlspecialchars((string) ($class['target_actor'] ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            ap_mail_send($adminEmail, 'New Vaak moderation report',
                "A new moderation report is waiting on Vaak.\nReporter: {$reporter}\nTarget: " . ($class['target_actor'] ?? '') . "\nOpen: https://mkultra.monster/vaak/?view=moderation\n",
                '<p>A new moderation report is waiting on Vaak.</p><p><b>Reporter:</b> ' . $safeReporter . '<br><b>Target:</b> ' . $safeTarget . '</p><p><a href="https://mkultra.monster/vaak/?view=moderation">Open moderation</a></p>');
        }
        return ['ok' => true, 'id' => $id, 'about_us' => $class['about_us']];
    } catch (Throwable $e) {
        error_log('[ap-reports] ingest: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not store report'];
    }
}

/**
 * Send an outbound Flag to a remote account's instance.
 *
 * @param list<string> $statusUris optional post URIs to attach
 * @return array{ok:bool,error?:string,id?:int,activity_id?:string,delivered?:int}
 */
function ap_report_send(string $targetActorRef, string $comment = '', array $statusUris = []): array
{
    $target = rtrim(trim($targetActorRef), '/');
    if ($target === '' || !str_starts_with($target, 'https://')) {
        if (function_exists('ap_resolve_actor_ref')) {
            $resolved = ap_resolve_actor_ref($targetActorRef);
            if (is_string($resolved) && str_starts_with($resolved, 'https://')) {
                $target = rtrim($resolved, '/');
            } elseif (is_array($resolved)) {
                $target = rtrim((string) ($resolved['id'] ?? $resolved['actor_id'] ?? ''), '/');
            }
        }
    }
    if ($target === '' || !str_starts_with($target, 'https://')) {
        return ['ok' => false, 'error' => 'Need @user@host or actor URL to report'];
    }
    $ident = function_exists('ap_outbound_identity') ? ap_outbound_identity() : null;
    $reporter = is_array($ident) ? rtrim((string) ($ident['id'] ?? ''), '/') : '';
    $repKeyId = is_array($ident) ? (string) ($ident['key_id'] ?? '') : '';
    $repPriv = is_array($ident) ? (string) ($ident['priv'] ?? '') : '';
    if ($reporter === '') {
        $reporter = defined('LOCAL_ACTOR') ? rtrim((string) LOCAL_ACTOR, '/') : 'https://mkultra.monster/users/cmdr_nova';
    }
    if ($target === $reporter || str_starts_with($target, $reporter . '/')) {
        return ['ok' => false, 'error' => "Can't report yourself"];
    }
    if ($repKeyId === '' || $repPriv === '') {
        if (!defined('LOCAL_KEY_ID') || !defined('LOCAL_PRIV')) {
            return ['ok' => false, 'error' => 'Actor keys unavailable'];
        }
        $repKeyId = (string) LOCAL_KEY_ID;
        $repPriv = (string) LOCAL_PRIV;
    }

    $comment = trim(strip_tags(function_exists('ap_fix_utf8') ? ap_fix_utf8($comment) : $comment));
    if (mb_strlen($comment) > 5000) {
        $comment = mb_substr($comment, 0, 5000);
    }

    $cleanStatuses = [];
    foreach ($statusUris as $u) {
        $u = rtrim(trim((string) $u), '/');
        if ($u !== '' && str_starts_with($u, 'https://')) {
            $cleanStatuses[] = $u;
        }
    }
    $cleanStatuses = array_values(array_unique($cleanStatuses));
    $object = array_values(array_unique(array_merge([$target], $cleanStatuses)));

    $isLocalPeer = (bool) preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $target);
    $activityId = $reporter . '/reports/' . bin2hex(random_bytes(10));
    $flag = [
        '@context' => 'https://www.w3.org/ns/activitystreams',
        'id' => $activityId,
        'type' => 'Flag',
        'actor' => $reporter,
        'content' => $comment,
        'object' => $object,
        'to' => $target,
    ];

    $delivered = 0;
    if ($isLocalPeer) {
        // Same-instance: file for local moderators (no self-federation)
        $delivered = 1;
    } else {
        $doc = function_exists('ap_fetch_actor_doc') ? ap_fetch_actor_doc($target) : null;
        if (!is_array($doc)) {
            return ['ok' => false, 'error' => 'Could not fetch target actor'];
        }
        // Prefer shared inbox (Mastodon instance actor receives reports there)
        $inbox = null;
        if (!empty($doc['endpoints']['sharedInbox']) && is_string($doc['endpoints']['sharedInbox'])) {
            $inbox = $doc['endpoints']['sharedInbox'];
        }
        if (!$inbox && function_exists('ap_resolve_personal_inbox_from_actor_doc')) {
            $inbox = ap_resolve_personal_inbox_from_actor_doc($doc) ?: ap_resolve_inbox_from_actor_doc($doc);
        }
        if (!$inbox || !str_starts_with($inbox, 'https://')) {
            return ['ok' => false, 'error' => 'Target has no inbox'];
        }
        if (function_exists('ap_is_blocked_inbox') && ap_is_blocked_inbox($inbox)) {
            return ['ok' => false, 'error' => 'Target inbox is blocked'];
        }
        if (function_exists('ap_deliver_signed_json') && ap_deliver_signed_json($inbox, $flag, $repKeyId, $repPriv)) {
            $delivered = 1;
        }
    }

    $now = function_exists('ap_db_now') ? ap_db_now() : gmdate('c');
    $id = 0;
    try {
        ap_db()->prepare(
            'INSERT INTO ap_reports
             (activity_id, direction, reporter_actor_id, target_actor_id, status_uris_json, comment, about_us, state, created_at, updated_at)
             VALUES (?, \'out\', ?, ?, ?, ?, 0, ?, ?, ?)'
        )->execute([
            $activityId,
            $reporter,
            $target,
            json_encode($cleanStatuses, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            $comment !== '' ? $comment : null,
            $delivered > 0 ? ($isLocalPeer ? 'open' : 'sent') : 'failed',
            $now,
            $now,
        ]);
        $id = ap_db_last_insert_id('ap_reports');
    } catch (Throwable $e) {
        error_log('[ap-reports] send store: ' . $e->getMessage());
    }

    if (function_exists('ap_metrics_record')) {
        ap_metrics_record('Flag', $reporter, $activityId, $target, strlen(json_encode($flag) ?: ''), $delivered > 0 ? 'local_report_out' : 'local_report_fail', $comment !== '' ? $comment : null);
    }
    if (function_exists('ap_log')) {
        ap_log("report_out target=$target delivered=$delivered local_peer=" . ($isLocalPeer ? '1' : '0'));
    }

    if ($delivered < 1) {
        return ['ok' => false, 'error' => 'Delivery failed', 'id' => $id, 'activity_id' => $activityId, 'delivered' => 0];
    }
    return ['ok' => true, 'id' => $id, 'activity_id' => $activityId, 'delivered' => $delivered];
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_report_set_state(int $id, string $state): array
{
    $state = strtolower(trim($state));
    if (!in_array($state, ['open', 'dismissed', 'ignored', 'sent', 'failed'], true)) {
        return ['ok' => false, 'error' => 'Invalid state'];
    }
    try {
        $st = ap_db()->prepare('UPDATE ap_reports SET state = ?, updated_at = ? WHERE id = ?');
        $st->execute([$state, function_exists('ap_db_now') ? ap_db_now() : gmdate('c'), $id]);
        if ($st->rowCount() < 1) {
            return ['ok' => false, 'error' => 'Report not found'];
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update report'];
    }
}

/**
 * @return list<array<string,mixed>>
 */
function ap_reports_list(string $filter = 'open', int $limit = 80): array
{
    $limit = max(1, min(200, $limit));
    $filter = strtolower(trim($filter));
    try {
        if ($filter === 'all') {
            $st = ap_db()->prepare('SELECT * FROM ap_reports ORDER BY id DESC LIMIT ?');
            $st->execute([$limit]);
        } elseif ($filter === 'about_us') {
            $st = ap_db()->prepare(
                "SELECT * FROM ap_reports WHERE about_us = 1 AND state = 'open' ORDER BY id DESC LIMIT ?"
            );
            $st->execute([$limit]);
        } elseif ($filter === 'outbound') {
            $st = ap_db()->prepare(
                "SELECT * FROM ap_reports WHERE direction = 'out' ORDER BY id DESC LIMIT ?"
            );
            $st->execute([$limit]);
        } elseif ($filter === 'closed') {
            $st = ap_db()->prepare(
                "SELECT * FROM ap_reports WHERE state IN ('dismissed', 'ignored') ORDER BY id DESC LIMIT ?"
            );
            $st->execute([$limit]);
        } else {
            // Open queue: inbound Flags + local users' outbound reports awaiting review
            $st = ap_db()->prepare(
                "SELECT * FROM ap_reports
                 WHERE state = 'open'
                   AND (direction = 'in'
                        OR (direction = 'out' AND reporter_actor_id LIKE 'https://mkultra.monster/users/%'))
                 ORDER BY id DESC LIMIT ?"
            );
            $st->execute([$limit]);
        }
        $rows = $st->fetchAll() ?: [];
        foreach ($rows as &$row) {
            if (is_array($row)) {
                $row['priority_score'] = ap_report_priority_score($row);
            }
        }
        unset($row);
        usort($rows, static function (array $a, array $b): int {
            $score = ((int) ($b['priority_score'] ?? 0)) <=> ((int) ($a['priority_score'] ?? 0));
            return $score !== 0 ? $score : ((int) ($b['id'] ?? 0) <=> (int) ($a['id'] ?? 0));
        });
        return $rows;
    } catch (Throwable $e) {
        return [];
    }
}

/** Transparent admin-only triage hint; this never changes moderation state. */
function ap_report_priority_score(array $row): int
{
    $score = !empty($row['about_us']) ? 5 : 0;
    $score += ((string) ($row['direction'] ?? '')) === 'in' ? 2 : 1;
    $uris = json_decode((string) ($row['status_uris_json'] ?? '[]'), true);
    if (is_array($uris)) {
        $score += min(3, count($uris));
    }
    $created = strtotime((string) ($row['created_at'] ?? ''));
    if ($created !== false) {
        $ageHours = max(0, (time() - $created) / 3600);
        $score += max(0, 3 - (int) floor($ageHours / 24));
    }
    return $score;
}

function ap_reports_open_count(): int
{
    try {
        return (int) ap_db()->query(
            "SELECT COUNT(*) FROM ap_reports
             WHERE state = 'open'
               AND (direction = 'in'
                    OR (direction = 'out' AND reporter_actor_id LIKE 'https://mkultra.monster/users/%'))"
        )->fetchColumn();
    } catch (Throwable $e) {
        return 0;
    }
}
