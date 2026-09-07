<?php
/**
 * Wafrn-compatible Bite activities (https://ns.mia.jetzt/as#Bite).
 * Bite a user or a post — playful poke, federated.
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


const AP_BITE_NS = 'https://ns.mia.jetzt/as#Bite';

/**
 * @return array{ok:bool,error?:string,id?:int,activity_id?:string,delivered?:int}
 */
function ap_bite_send(string $targetRef, string $kind = 'auto'): array
{
    $targetRef = rtrim(trim($targetRef), '/');
    if ($targetRef === '' || !str_starts_with($targetRef, 'https://')) {
        // Resolve @user@host
        if (function_exists('ap_resolve_actor_ref')) {
            $resolved = ap_resolve_actor_ref($targetRef);
            if (is_string($resolved) && str_starts_with($resolved, 'https://')) {
                $targetRef = rtrim($resolved, '/');
            } elseif (is_array($resolved)) {
                $targetRef = rtrim((string) ($resolved['id'] ?? $resolved['actor_id'] ?? ''), '/');
            }
        }
    }
    if ($targetRef === '' || !str_starts_with($targetRef, 'https://')) {
        return ['ok' => false, 'error' => 'Need @user@host, actor URL, or post URL'];
    }
    $selfActor = function_exists('ap_local_actor_id')
        ? rtrim(ap_local_actor_id(), '/')
        : (defined('LOCAL_ACTOR') ? rtrim((string) LOCAL_ACTOR, '/') : 'https://mkultra.monster/users/cmdr_nova');
    // Wafrn forbids biting yourself / your own posts — session actor only, not hard-coded cmdr
    if ($selfActor !== '' && (
        $targetRef === $selfActor
        || str_starts_with($targetRef, $selfActor . '/')
    )) {
        if (!str_contains($targetRef, '/notes/') && !str_contains($targetRef, '/statuses/')) {
            return ['ok' => false, 'error' => "You can't bite yourself"];
        }
        return ['ok' => false, 'error' => "You can't bite your own post"];
    }

    $kind = strtolower(trim($kind));
    if ($kind === 'auto') {
        $kind = ap_bite_guess_kind($targetRef);
    }
    if (!in_array($kind, ['user', 'post'], true)) {
        return ['ok' => false, 'error' => 'Invalid bite kind'];
    }

    $ident = function_exists('ap_outbound_identity') ? ap_outbound_identity() : null;
    $biteActor = is_array($ident) ? (string) ($ident['id'] ?? '') : '';
    $biteKeyId = is_array($ident) ? (string) ($ident['key_id'] ?? '') : '';
    $bitePriv = is_array($ident) ? (string) ($ident['priv'] ?? '') : '';
    if ($biteActor === '' || $biteKeyId === '' || $bitePriv === '') {
        if (!defined('LOCAL_ACTOR') || !defined('LOCAL_KEY_ID') || !defined('LOCAL_PRIV')) {
            return ['ok' => false, 'error' => 'Actor keys unavailable'];
        }
        $biteActor = (string) LOCAL_ACTOR;
        $biteKeyId = (string) LOCAL_KEY_ID;
        $bitePriv = (string) LOCAL_PRIV;
    }

    // Rate limit: 60 bites / hour per session actor
    try {
        $since = gmdate('c', time() - 3600);
        $st = ap_db()->prepare(
            "SELECT COUNT(*) AS c FROM ap_bites
             WHERE direction = 'out' AND biter_actor_id = ? AND created_at >= ?"
        );
        $st->execute([$biteActor, $since]);
        if ((int) ($st->fetch()['c'] ?? 0) >= 60) {
            return ['ok' => false, 'error' => 'Bite rate limit — try again later'];
        }
    } catch (Throwable $e) {
        // table may not exist yet
    }

    $activityId = $biteActor . '/bites/' . bin2hex(random_bytes(10));
    $to = [];
    $inboxes = [];

    if ($kind === 'user') {
        $to = [$targetRef];
        // Same-instance peer — deterministic inbox
        if (preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $targetRef)) {
            $inboxes[] = $targetRef . '/inbox';
        } else {
            $doc = function_exists('ap_fetch_actor_doc') ? ap_fetch_actor_doc($targetRef) : null;
            if (!is_array($doc)) {
                return ['ok' => false, 'error' => 'Could not fetch target actor'];
            }
            $inbox = function_exists('ap_resolve_personal_inbox_from_actor_doc')
                ? (ap_resolve_personal_inbox_from_actor_doc($doc) ?: ap_resolve_inbox_from_actor_doc($doc))
                : null;
            if (!$inbox || !str_starts_with($inbox, 'https://')) {
                return ['ok' => false, 'error' => 'Target has no inbox'];
            }
            if (function_exists('ap_is_blocked_inbox') && ap_is_blocked_inbox($inbox)) {
                return ['ok' => false, 'error' => 'Target inbox is blocked'];
            }
            $inboxes[] = $inbox;
        }
    } else {
        // Post bite — notify the author; Wafrn-style Public addressing for remotes only
        $author = ap_bite_author_of_object($targetRef);
        $publicId = 'https://www.w3.org/ns/activitystreams#Public';
        $followers = $biteActor . '/followers';
        $authorIsLocal = is_string($author)
            && (bool) preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $author);
        if ($author !== null) {
            if ($authorIsLocal) {
                // Same-instance: only the author's inbox (no shared-inbox spray)
                $to = [$author];
                $inboxes[] = $author . '/inbox';
            } else {
                $to = [$publicId, $followers, $author];
                $doc = function_exists('ap_fetch_actor_doc') ? ap_fetch_actor_doc($author) : null;
                if (is_array($doc)) {
                    $inbox = ap_resolve_personal_inbox_from_actor_doc($doc) ?: ap_resolve_inbox_from_actor_doc($doc);
                    if ($inbox && str_starts_with($inbox, 'https://') && !(function_exists('ap_is_blocked_inbox') && ap_is_blocked_inbox($inbox))) {
                        $inboxes[] = $inbox;
                    }
                }
                // Remote bites: light shared-inbox fan so Wafrn friends may see it
                if (function_exists('ap_known_shared_inboxes')) {
                    foreach (array_slice(ap_known_shared_inboxes(8), 0, 8) as $si) {
                        if (is_string($si) && str_starts_with($si, 'https://') && !in_array($si, $inboxes, true)) {
                            $inboxes[] = $si;
                        }
                    }
                }
            }
        } else {
            $to = [$publicId, $followers];
            if (function_exists('ap_known_shared_inboxes')) {
                foreach (array_slice(ap_known_shared_inboxes(8), 0, 8) as $si) {
                    if (is_string($si) && str_starts_with($si, 'https://') && !in_array($si, $inboxes, true)) {
                        $inboxes[] = $si;
                    }
                }
            }
        }
    }

    $bite = [
        '@context' => [
            'https://www.w3.org/ns/activitystreams',
            ['Bite' => AP_BITE_NS],
        ],
        'id' => $activityId,
        'type' => 'Bite',
        'actor' => $biteActor,
        'target' => $targetRef,
        'object' => $targetRef,
        'to' => array_values(array_unique($to)),
        'published' => gmdate('c'),
    ];

    $delivered = 0;
    $n = 0;
    foreach ($inboxes as $inbox) {
        if ($n >= 12) {
            break;
        }
        if (function_exists('ap_deliver_signed_json') && ap_deliver_signed_json($inbox, $bite, $biteKeyId, $bitePriv)) {
            $delivered++;
        }
        $n++;
    }

    $rowId = ap_bite_store([
        'activity_id' => $activityId,
        'direction' => 'out',
        'biter_actor_id' => $biteActor,
        'target_kind' => $kind,
        'target_id' => $targetRef,
    ]);

    if (function_exists('ap_metrics_record')) {
        ap_metrics_record('Bite', $biteActor, $activityId, $targetRef, strlen(json_encode($bite) ?: ''), 'local_bite_out', $kind);
    }
    if (function_exists('ap_log')) {
        ap_log("bite_out kind=$kind target=$targetRef delivered=$delivered");
    }

    return [
        'ok' => true,
        'id' => $rowId,
        'activity_id' => $activityId,
        'delivered' => $delivered,
    ];
}

function ap_bite_guess_kind(string $url): string
{
    $url = rtrim($url, '/');
    if (
        str_contains($url, '/notes/')
        || str_contains($url, '/statuses/')
        || str_contains($url, '/objects/')
        || str_contains($url, '/notice/')
        || preg_match('#/(?:post|posts|woot)/#i', $url)
    ) {
        return 'post';
    }
    return 'user';
}

function ap_bite_author_of_object(string $objectId): ?string
{
    $objectId = rtrim($objectId, '/');
    if (function_exists('ap_event_by_object_id')) {
        $ev = ap_event_by_object_id($objectId);
        if (is_array($ev) && !empty($ev['actor_id'])) {
            return rtrim((string) $ev['actor_id'], '/');
        }
    }
    if (function_exists('ap_masto_actor_url_from_object_url')) {
        $g = ap_masto_actor_url_from_object_url($objectId);
        if (is_string($g) && $g !== '') {
            return rtrim($g, '/');
        }
    }
    if (function_exists('ap_fetch_as2_object') && function_exists('ap_as_id')) {
        $doc = ap_fetch_as2_object($objectId);
        if (is_array($doc)) {
            if (function_exists('ap_unwrap_as2_object')) {
                $doc = ap_unwrap_as2_object($doc) ?? $doc;
            }
            $at = ap_as_id($doc['attributedTo'] ?? null) ?: ap_as_id($doc['actor'] ?? null);
            if (is_string($at) && str_starts_with($at, 'https://')) {
                return rtrim($at, '/');
            }
        }
    }
    return null;
}

/**
 * @param array{activity_id:string,direction:string,biter_actor_id:string,target_kind:string,target_id:string} $row
 */
function ap_bite_store(array $row): int
{
    $now = function_exists('ap_db_now') ? ap_db_now() : gmdate('c');
    try {
        ap_db()->prepare(
            'INSERT INTO ap_bites (activity_id, direction, biter_actor_id, target_kind, target_id, created_at)
             VALUES (?, ?, ?, ?, ?, ?)
             ON CONFLICT(activity_id) DO UPDATE SET
               direction = excluded.direction,
               biter_actor_id = excluded.biter_actor_id,
               target_kind = excluded.target_kind,
               target_id = excluded.target_id'
        )->execute([
            (string) $row['activity_id'],
            (string) $row['direction'],
            rtrim((string) $row['biter_actor_id'], '/'),
            (string) $row['target_kind'],
            rtrim((string) $row['target_id'], '/'),
            $now,
        ]);
        return ap_db_last_insert_id('ap_bites');
    } catch (Throwable $e) {
        error_log('[ap-bites] store: ' . $e->getMessage());
        return 0;
    }
}

/**
 * Ingest inbound Bite activity (Wafrn-compatible).
 *
 * @return array{ok:bool,kind?:string,error?:string}
 */
function ap_bite_ingest(array $activity): array
{
    $type = (string) ($activity['type'] ?? '');
    if ($type !== 'Bite') {
        return ['ok' => false, 'error' => 'Not a Bite'];
    }
    $actorId = function_exists('ap_as_id') ? ap_as_id($activity['actor'] ?? null) : null;
    if (!is_string($actorId) || $actorId === '') {
        return ['ok' => false, 'error' => 'Missing actor'];
    }
    $actorId = rtrim($actorId, '/');
    // Ignore self-bites from any local actor (not only cmdr_nova)
    if (preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $actorId)) {
        return ['ok' => false, 'error' => 'Ignore self'];
    }
    $target = function_exists('ap_as_id')
        ? (ap_as_id($activity['target'] ?? null) ?: ap_as_id($activity['object'] ?? null))
        : null;
    if (!is_string($target) || $target === '') {
        return ['ok' => false, 'error' => 'Missing target'];
    }
    $target = rtrim($target, '/');
    $activityId = function_exists('ap_as_id') ? ap_as_id($activity['id'] ?? null) : null;
    if (!is_string($activityId) || $activityId === '') {
        $activityId = $actorId . '/bites/' . bin2hex(random_bytes(6));
    }

    $kind = ap_bite_guess_kind($target);
    // Targeted at any local user / their posts
    $self = null;
    if (preg_match('#^(https://mkultra\.monster/users/[A-Za-z0-9_]+)(?:/|$)#', $target, $tm)) {
        $self = $tm[1];
    }
    $isUs = $self !== null && (
        ($kind === 'user' && $target === $self)
        || ($kind === 'post' && str_starts_with($target, $self . '/'))
    );

    if (!$isUs || $self === null) {
        // Still log non-targeting bites that somehow arrived (rare)
        ap_bite_store([
            'activity_id' => $activityId,
            'direction' => 'in',
            'biter_actor_id' => $actorId,
            'target_kind' => $kind,
            'target_id' => $target,
        ]);
        return ['ok' => true, 'kind' => $kind];
    }

    ap_bite_store([
        'activity_id' => $activityId,
        'direction' => 'in',
        'biter_actor_id' => $actorId,
        'target_kind' => $kind,
        'target_id' => $target,
    ]);

    // Surface in notifications via mentions table (activity_type=Bite)
    if (function_exists('ap_mention_store')) {
        $storeObjectId = $kind === 'post'
            ? $target
            : ($self . '/bites-received/' . bin2hex(random_bytes(4)));
        // Uniquify per biter so repeat bites still notify
        if (function_exists('ap_mention_interaction_object_id')) {
            $storeObjectId = ap_mention_interaction_object_id($storeObjectId, $actorId, 'bite');
        } else {
            $storeObjectId .= '#bite-' . substr(sha1($actorId), 0, 8);
        }
        $biteOwner = function_exists('ap_inbox_recipient_owner')
            ? ap_inbox_recipient_owner(['object' => $target, 'actor' => $self])
            : null;
        ap_mention_store([
            'activity_id' => $activityId,
            'activity_type' => 'Bite',
            'type' => 'Bite',
            'actor_id' => $actorId,
            'object_id' => $storeObjectId,
            'content' => $kind === 'user' ? '🦷 bit you' : '🦷 bit your post',
            'in_reply_to' => $kind === 'post' ? $target : null,
            'media_urls' => [],
            'spoiler_text' => '',
            'sensitive' => false,
            'owner_user_id' => is_array($biteOwner) ? (int) ($biteOwner['owner_user_id'] ?? 0) : 0,
            'owner_actor_id' => is_array($biteOwner) ? (string) ($biteOwner['owner_actor_id'] ?? '') : $self,
        ]);
    }

    if (function_exists('ap_webpush_notify_event')) {
        try {
            require_once __DIR__ . '/ap-webpush.php';
            $biteOwnerId = is_array($biteOwner) ? (int) ($biteOwner['owner_user_id'] ?? 0) : 0;
            ap_webpush_notify_event(
                'bite',
                $actorId,
                null,
                $kind === 'user' ? 'bit you' : 'bit your post',
                $biteOwnerId > 0 ? $biteOwnerId : null
            );
        } catch (Throwable $e) {
            // ignore
        }
    }

    return ['ok' => true, 'kind' => $kind];
}

/**
 * @return list<array<string,mixed>>
 */
function ap_bites_recent(int $limit = 40): array
{
    $limit = max(1, min(100, $limit));
    try {
        $st = ap_db()->prepare('SELECT * FROM ap_bites ORDER BY id DESC LIMIT ?');
        $st->execute([$limit]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}
