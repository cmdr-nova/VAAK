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
    } catch (Throwable $e) {
        // Signals are advisory. A schema/lock problem must never fail a user action.
        error_log('[ap-signals] record: ' . $e->getMessage());
    }
}
