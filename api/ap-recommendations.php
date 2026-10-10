<?php
declare(strict_types=1);

/** Explicit actions consume recommendation slots; passive views do not. No remote fetches. */
function ap_home_recommendation_interacted_objects(int $owner, array $objects): array
{
    $objects = array_values(array_unique(array_filter(array_map(static fn($s): string => rtrim((string) $s, '/'), $objects))));
    if ($owner < 1 || !$objects) return [];
    $db = ap_db();
    $pgsql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $json = static fn(string $column, string $path): string => $pgsql
        ? "$column #>> '{" . str_replace('.', ',', $path) . "}'"
        : "json_extract(NULLIF($column,''), '$.$path')";
    $doc = static fn(string $column): string => $pgsql ? "NULLIF($column,'')::jsonb" : "NULLIF($column,'')";
    $noteDoc = $doc('o.raw_create_json');
    $signalDoc = $doc('s.metadata_json');
    $quote = 'COALESCE(' . $json('n.doc', 'object.quote') . ',' . $json('n.doc', 'object.quoteUrl') . ',' . $json('n.doc', 'object._misskey_quote') . ", '')";
    $signalObject = $json('s.doc', 'object_id');
    $signalUri = $json('s.doc', 'uri');
    $consumed = [];
    foreach (array_chunk($objects, 200) as $chunk) {
        $candidates = implode(' UNION ALL ', array_fill(0, count($chunk), 'SELECT ? AS object_id'));
        $sql = "WITH candidates AS ($candidates), viewer AS (SELECT id,actor_id FROM ap_users WHERE id=?),
            own_notes AS MATERIALIZED (
                SELECT o.in_reply_to,$noteDoc AS doc FROM outbox_notes o, viewer v
                WHERE substr(o.id,1,length(rtrim(v.actor_id,'/') || '/notes/'))=rtrim(v.actor_id,'/') || '/notes/'
            ),
            signals AS MATERIALIZED (
                SELECT s.target_key,$signalDoc AS doc FROM ap_user_signals s, viewer v
                WHERE s.owner_user_id=v.id AND s.weight>0
                  AND s.signal_type IN ('like','favourite','boost','reblog','reply','quote','bookmark')
            ),
            interactions AS MATERIALIZED (
                SELECT rtrim(f.object_id,'/') AS object_id FROM masto_favourites f, viewer v WHERE f.owner_user_id=v.id
                UNION ALL SELECT rtrim(b.object_id,'/') FROM masto_bookmarks b, viewer v WHERE b.owner_user_id=v.id
                UNION ALL SELECT rtrim(b.object_id,'/') FROM masto_reblogs b, viewer v WHERE b.owner_user_id=v.id
                UNION ALL SELECT rtrim(n.in_reply_to,'/') FROM own_notes n
                UNION ALL SELECT rtrim($quote,'/') FROM own_notes n
                UNION ALL SELECT rtrim(q.quoted_note_id,'/') FROM quote_authorizations q, viewer v WHERE rtrim(q.requester_actor,'/')=rtrim(v.actor_id,'/')
                UNION ALL SELECT rtrim(s.target_key,'/') FROM signals s
                UNION ALL SELECT rtrim($signalObject,'/') FROM signals s
                UNION ALL SELECT rtrim($signalUri,'/') FROM signals s
            )
            SELECT DISTINCT c.object_id FROM candidates c JOIN interactions i ON i.object_id=c.object_id";
        $st = $db->prepare($sql);
        $st->execute([...$chunk, $owner]);
        foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $object) $consumed[(string) $object] = true;
    }
    return $consumed;
}

/** Keep reply/quote history when the new post is later edited or deleted. */
function ap_home_recommendation_record_publication(array $note, array $create): void
{
    $targets = ['reply' => $note['in_reply_to'] ?? ''];
    foreach (['quote', 'quoteUrl', '_misskey_quote'] as $field) {
        $quote = $create['object'][$field] ?? null;
        if (is_string($quote) && $quote !== '') { $targets['quote'] = $quote; break; }
    }
    $targets = array_filter($targets, static fn($target): bool => is_string($target) && trim($target) !== '');
    if (!$targets || !preg_match('~^(https://mkultra\.monster/users/[a-z0-9_]+)/notes/~', (string) ($note['id'] ?? ''), $m)) return;
    try {
        $st = ap_db()->prepare('SELECT id FROM ap_users WHERE actor_id = ?');
        $st->execute([$m[1]]);
        $owner = (int) $st->fetchColumn();
        if ($owner < 1) return;
        require_once __DIR__ . '/ap-signals.php';
        foreach ($targets as $kind => $target) {
            $target = rtrim(trim($target), '/');
            ap_signal_record($owner, 'fedi', $kind, $target, true, ['object_id' => $target]);
        }
    } catch (Throwable $e) {
        // Recommendation bookkeeping must never prevent publication.
        error_log('[ap-recommendations] publication signal: ' . $e->getMessage());
    }
}
