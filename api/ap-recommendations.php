<?php
declare(strict_types=1);

/** Signal types that consume a recommendation. Passive views are not in this list. */
function ap_home_recommendation_signal_types_sql(): string
{
    return "'like','favourite','boost','reblog','reply','quote','bookmark'";
}

/**
 * Quote URL stored on a note, or '' when it has none.
 * vaak_reco_quote_key (created by deploy/remote/migrate.php) is the same
 * CASE as the old note document: skip the parse unless the text contains
 * "quote", then COALESCE object.quote, object.quoteUrl, object._misskey_quote.
 * Invalid JSON yields '' so one bad row cannot fail the index build.
 */
function ap_home_recommendation_pgsql_quote_key(string $column): string
{
    return "vaak_reco_quote_key($column)";
}

/**
 * One trimmed metadata field, or NULL when the field is absent.
 * NULL (not '') keeps an empty candidate from matching every signal.
 */
function ap_home_recommendation_pgsql_signal_key(string $column, string $field): string
{
    if ($field !== 'object_id' && $field !== 'uri') {
        throw new InvalidArgumentException('unsupported signal field');
    }
    return "vaak_reco_signal_key($column,'$field')";
}

/**
 * Candidate lookup. Each branch is an EXISTS that stops at the first hit
 * (LIMIT 1) and filters by this owner, so Postgres can index-probe the
 * candidate instead of hashing every note, favourite, and signal the owner
 * has ever stored. work_mem is 16MB; that hash spilled to temp files.
 */
function ap_home_recommendation_interacted_sql(bool $pgsql, int $candidateCount): string
{
    if ($candidateCount < 1) {
        throw new InvalidArgumentException('recommendation lookup needs a candidate');
    }
    $candidates = implode(' UNION ALL ', array_fill(0, $candidateCount, "SELECT rtrim(?,'/') AS object_id"));
    $quote = $pgsql
        ? ap_home_recommendation_pgsql_quote_key('o.raw_create_json')
        : "rtrim(COALESCE(json_extract(NULLIF(o.raw_create_json,''), '$.object.quote'),json_extract(NULLIF(o.raw_create_json,''), '$.object.quoteUrl'),json_extract(NULLIF(o.raw_create_json,''), '$.object._misskey_quote'), ''), '/')";
    $signalObject = $pgsql
        ? ap_home_recommendation_pgsql_signal_key('s.metadata_json', 'object_id')
        : "rtrim(json_extract(NULLIF(s.metadata_json,''), '$.object_id'), '/')";
    $signalUri = $pgsql
        ? ap_home_recommendation_pgsql_signal_key('s.metadata_json', 'uri')
        : "rtrim(json_extract(NULLIF(s.metadata_json,''), '$.uri'), '/')";
    // starts-with, not LIKE: '_' in an actor name is a literal character.
    $prefix = $pgsql
        ? "o.id ^@ (v.actor || '/notes/')"
        : "substr(o.id,1,length(v.actor || '/notes/'))=v.actor || '/notes/'";
    $signals = 's.owner_user_id=v.id AND s.weight>0 AND s.signal_type IN (' . ap_home_recommendation_signal_types_sql() . ')';
    // A non-empty hit implies the document contains "quote", so that arm can
    // use the partial index. An empty candidate still matches a note whose
    // quote extraction is empty, including notes that never mention quote.
    $quoteArms = $pgsql
        ? "OR (c.object_id<>'' AND EXISTS (SELECT 1 FROM outbox_notes o, viewer v WHERE $prefix AND strpos(o.raw_create_json,'quote')>0 AND ($quote)<>'' AND ($quote)=c.object_id LIMIT 1))
OR (c.object_id='' AND EXISTS (SELECT 1 FROM outbox_notes o, viewer v WHERE $prefix AND ($quote)='' LIMIT 1))"
        : "OR EXISTS (SELECT 1 FROM outbox_notes o, viewer v WHERE $prefix AND ($quote)=c.object_id LIMIT 1)";
    return "WITH candidates AS ($candidates),
viewer AS (SELECT id, rtrim(actor_id,'/') AS actor FROM ap_users WHERE id=?)
SELECT DISTINCT c.object_id FROM candidates c
WHERE EXISTS (SELECT 1 FROM masto_favourites f, viewer v WHERE f.owner_user_id=v.id AND rtrim(f.object_id,'/')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM masto_bookmarks b, viewer v WHERE b.owner_user_id=v.id AND rtrim(b.object_id,'/')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM masto_reblogs b, viewer v WHERE b.owner_user_id=v.id AND rtrim(b.object_id,'/')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM outbox_notes o, viewer v WHERE $prefix AND rtrim(o.in_reply_to,'/')=c.object_id LIMIT 1)
$quoteArms
OR EXISTS (SELECT 1 FROM quote_authorizations q, viewer v WHERE rtrim(q.requester_actor,'/')=v.actor AND rtrim(q.quoted_note_id,'/')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM ap_user_signals s, viewer v WHERE $signals AND rtrim(s.target_key,'/')=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM ap_user_signals s, viewer v WHERE $signals AND ($signalObject)=c.object_id LIMIT 1)
OR EXISTS (SELECT 1 FROM ap_user_signals s, viewer v WHERE $signals AND ($signalUri)=c.object_id LIMIT 1)";
}

/** Explicit actions consume recommendation slots; passive views do not. No remote fetches. */
function ap_home_recommendation_interacted_objects(int $owner, array $objects): array
{
    $objects = array_values(array_unique(array_filter(array_map(static fn($s): string => rtrim((string) $s, '/'), $objects))));
    if ($owner < 1 || !$objects) return [];
    $db = ap_db();
    $pgsql = $db->getAttribute(PDO::ATTR_DRIVER_NAME) === 'pgsql';
    $consumed = [];
    foreach (array_chunk($objects, 200) as $chunk) {
        $st = $db->prepare(ap_home_recommendation_interacted_sql($pgsql, count($chunk)));
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
