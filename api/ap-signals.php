<?php
/** Low-cost interaction signals used by background recommendation jobs. */
declare(strict_types=1);

/** Record an interaction after its desired state has been queued. */
function ap_signal_record(int $ownerUserId, string $platform, string $kind, string $targetKey, bool $desired, array $payload = []): void
{
    if ($ownerUserId < 1 || $targetKey === '' || !in_array($platform, ['fedi', 'bsky'], true)) {
        return;
    }
    try {
        $metadata = [
            'target_actor' => trim((string) ($payload['target_actor'] ?? '')),
            'author_did' => trim((string) ($payload['author_did'] ?? '')),
            'author_handle' => trim((string) ($payload['author_handle'] ?? '')),
            'object_id' => trim((string) ($payload['object_id'] ?? '')),
            'uri' => trim((string) ($payload['uri'] ?? '')),
        ];
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        ap_db()->prepare(
            'INSERT INTO ap_user_signals
             (owner_user_id, platform, signal_type, target_key, weight, metadata_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ownerUserId,
            $platform,
            $kind,
            substr($targetKey, 0, 2048),
            $desired ? 1.0 : -1.0,
            is_string($json) ? $json : '{}',
            ap_db_now(),
        ]);
        if (function_exists('ap_redis_delete')) {
            ap_redis_delete(
                'vaak:recommend:v1:signals:' . $ownerUserId,
                'vaak:recommend:v1:favourite-actors:' . $ownerUserId,
                'vaak:recommend:v1:favourite-tags:' . $ownerUserId
            );
        }
    } catch (Throwable $e) {
        // Signals are advisory. A schema/lock problem must never fail a user action.
        error_log('[ap-signals] record: ' . $e->getMessage());
    }
}

/**
 * Record a bounded passive Home signal. The browser may emit the same event
 * more than once across reloads/tabs, so enforce a per-user/target cooldown in
 * the database before inserting. No raw navigation URL or scroll position is
 * stored; target_key is the same post identifier used by explicit actions.
 */
function ap_signal_record_passive(
    int $ownerUserId,
    string $platform,
    string $kind,
    string $targetKey,
    string $targetActor,
    int $dwellSeconds = 0
): bool {
    if ($ownerUserId < 1
        || !in_array($platform, ['fedi', 'bsky', 'rss'], true)
        || !in_array($kind, ['impression', 'click', 'dwell'], true)
        || $targetKey === ''
        || $targetActor === ''
    ) {
        return false;
    }
    $targetKey = mb_substr($targetKey, 0, 2048);
    $targetActor = mb_substr($targetActor, 0, 2048);
    $cooldown = [
        'impression' => 6 * 3600,
        'click' => 20 * 60,
        'dwell' => 2 * 3600,
    ][$kind];
    $weight = 1.0;
    if ($kind === 'dwell') {
        $dwellSeconds = max(0, min(300, $dwellSeconds));
        if ($dwellSeconds < 6) return false;
        $weight = $dwellSeconds >= 90 ? 4.0 : ($dwellSeconds >= 45 ? 3.0 : ($dwellSeconds >= 20 ? 2.0 : 1.0));
    }
    try {
        $db = ap_db();
        $recent = $db->prepare(
            'SELECT created_at FROM ap_user_signals
             WHERE owner_user_id = ? AND platform = ? AND signal_type = ? AND target_key = ?
             ORDER BY id DESC LIMIT 1'
        );
        $recent->execute([$ownerUserId, $platform, $kind, $targetKey]);
        $lastAt = strtotime((string) ($recent->fetchColumn() ?: '')) ?: 0;
        if ($lastAt > 0 && (time() - $lastAt) < $cooldown) {
            return false;
        }
        $metadata = [
            'target_actor' => $targetActor,
            'object_id' => $platform === 'fedi' ? $targetKey : '',
            'uri' => $platform === 'bsky' ? $targetKey : '',
            'dwell_bucket' => $kind === 'dwell' ? (int) $weight : 0,
        ];
        $json = json_encode($metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $db->prepare(
            'INSERT INTO ap_user_signals
             (owner_user_id, platform, signal_type, target_key, weight, metadata_json, created_at)
             VALUES (?, ?, ?, ?, ?, ?, ?)'
        )->execute([
            $ownerUserId,
            $platform,
            $kind,
            $targetKey,
            $weight,
            is_string($json) ? $json : '{}',
            ap_db_now(),
        ]);
        if (function_exists('ap_redis_delete')) {
            ap_redis_delete('vaak:recommend:v1:signals:' . $ownerUserId);
        }
        return true;
    } catch (Throwable $e) {
        // Passive learning must never affect feed availability or navigation.
        error_log('[ap-signals] passive record: ' . $e->getMessage());
        return false;
    }
}
