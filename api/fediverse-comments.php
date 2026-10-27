<?php
/**
 * Blog / note Fediverse comments — replies to the syndicated @cmdr_nova Note.
 *
 * GET /api/fediverse-comments.php?url=https://mkultra.monster/.../
 * Optional: note_id=https://mkultra.monster/users/cmdr_nova/notes/...
 *
 * Looks up site_syndications → Note → inbound mentions (and local replies).
 * Used by the Jekyll site (assets/js/fediverse-comments.js), so it is public
 * and unauthenticated: only public/unlisted replies from unblocked actors.
 */
declare(strict_types=1);

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'OPTIONS') {
    http_response_code(200);
    exit;
}

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-r2.php';
require_once __DIR__ . '/ap-masto-entities.php';

const FEDI_COMMENTS_NOTE_PREFIX = 'https://mkultra.monster/users/cmdr_nova/notes/';

/**
 * Mentions carry no audience; inbound Creates log it on events.visibility.
 * Hide a reply only when its logged visibility is known to be non-public
 * (events are pruned, so older replies have no row and stay visible).
 */
function fedi_comments_known_private(string $objectUrl): bool
{
    $objectUrl = rtrim($objectUrl, '/');
    if ($objectUrl === '') {
        return false;
    }
    $st = ap_db()->prepare(
        "SELECT visibility FROM events
         WHERE (object_id = ? OR object_id = ?) AND type IN ('Create', 'Update')
         ORDER BY id DESC LIMIT 1"
    );
    $st->execute([$objectUrl, $objectUrl . '/']);
    $vis = $st->fetchColumn();
    if (!is_string($vis) || $vis === '') {
        return false;
    }
    return !in_array(strtolower($vis), ['public', 'unlisted'], true);
}

function fedi_comments_visible(array $status, int $ownerId): bool
{
    $visibility = strtolower((string) ($status['visibility'] ?? 'public'));
    if (!in_array($visibility, ['public', 'unlisted'], true)) {
        return false;
    }
    $author = rtrim((string) (($status['account']['uri'] ?? null) ?: ($status['account']['url'] ?? '')), '/');
    if ($author !== '' && $ownerId > 0 && function_exists('ap_actor_is_content_blocked')
        && ap_actor_is_content_blocked($author, null, $ownerId)) {
        return false;
    }
    return true;
}

$pageUrl = trim((string) ($_GET['url'] ?? ''));
$noteIdParam = trim((string) ($_GET['note_id'] ?? ''));

$syndication = null;
$noteId = '';
$statusId = null;
$title = '';

try {
    // Bind cmdr_nova as the actor so her own loopback replies are skipped in
    // mentions (they come back as local masto_statuses children instead).
    $ownerId = ap_db_cmdr_nova_user_id();
    if ($ownerId > 0) {
        $GLOBALS['vaak_actor_id'] = rtrim(ap_db_owner_actor_id_for_user_id($ownerId), '/');
    }

    if ($noteIdParam !== '' && str_starts_with($noteIdParam, FEDI_COMMENTS_NOTE_PREFIX)) {
        $noteId = rtrim($noteIdParam, '/');
        $local = ap_masto_status_by_note_id($noteId);
        if (is_array($local)) {
            $statusId = ap_masto_snowflake_id(
                (string) ($local['published'] ?? gmdate('c')),
                (int) $local['local_id'],
                0
            );
        }
        $st = ap_db()->prepare('SELECT url, title FROM site_syndications WHERE note_id = ? OR note_id = ? LIMIT 1');
        $st->execute([$noteId, $noteId . '/']);
        $syn = $st->fetch();
        if (is_array($syn)) {
            $pageUrl = (string) ($syn['url'] ?? $pageUrl);
            $title = (string) ($syn['title'] ?? '');
        } else {
            // Only syndicated site posts have a public comment thread.
            $noteId = '';
        }
    } elseif ($pageUrl !== '') {
        $syndication = ap_masto_site_syndication_for_url($pageUrl);
        if (is_array($syndication)) {
            $noteId = (string) ($syndication['note_id'] ?? '');
            $statusId = $syndication['status_id'] ?? null;
            $title = (string) ($syndication['title'] ?? '');
            $pageUrl = (string) ($syndication['url'] ?? $pageUrl);
        }
    } else {
        http_response_code(400);
        echo json_encode([
            'success' => false,
            'error' => 'Provide url= (page URL) or note_id=',
        ]);
        exit;
    }

    if ($noteId === '') {
        echo json_encode([
            'success' => true,
            'has_thread' => false,
            'comments' => [],
            'comment_count' => 0,
            'account' => '@cmdr_nova@mkultra.monster',
            'message' => 'No ActivityPub thread for this page yet.',
            'timestamp' => time(),
        ]);
        exit;
    }

    $comments = [];
    foreach (ap_masto_mention_replies_to_object($noteId, 80) as $ment) {
        if (!is_array($ment) || !fedi_comments_visible($ment, $ownerId)) {
            continue;
        }
        if (fedi_comments_known_private((string) ($ment['uri'] ?? ''))) {
            continue;
        }
        $comments[] = $ment;
    }

    // Local compose replies to the same note (rare but possible)
    if ($statusId !== null) {
        $localRow = ap_masto_status_by_note_id($noteId);
        if (is_array($localRow)) {
            $localId = (int) ($localRow['local_id'] ?? 0);
            if ($localId > 0) {
                $st = ap_db()->prepare(
                    'SELECT * FROM masto_statuses WHERE in_reply_to_local_id = ? ORDER BY local_id ASC LIMIT 40'
                );
                $st->execute([$localId]);
                foreach ($st->fetchAll() as $child) {
                    $status = ap_masto_status_from_row($child);
                    if (is_array($status) && fedi_comments_visible($status, $ownerId)) {
                        $comments[] = $status;
                    }
                }
            }
        }
    }

    echo json_encode([
        'success' => true,
        'has_thread' => true,
        'comments' => $comments,
        'comment_count' => count($comments),
        'account' => '@cmdr_nova@mkultra.monster',
        'url' => $pageUrl !== '' ? $pageUrl : null,
        'note_id' => $noteId,
        'status_id' => $statusId,
        'title' => $title !== '' ? $title : null,
        'thread_url' => $noteId,
        'timestamp' => time(),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    error_log('fediverse-comments.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to fetch comments',
        'timestamp' => time(),
    ]);
}
