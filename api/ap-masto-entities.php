<?php
/**
 * Mastodon API entity builders (multi-user; token/session-bound actor).
 */
declare(strict_types=1);

require_once __DIR__ . '/ap-link-preview.php';

/**
 * Mastodon account id for the token/session-bound local user.
 * cmdr_nova stays stable as "1"; other locals use ap_users.id.
 * Unbound session → '' (never invent cmdr / "1").
 */
function ap_masto_session_account_id(): string
{
    $vaakKey = strtolower(trim((string) ($GLOBALS['vaak_actor_key'] ?? '')));
    $uid = (int) ($GLOBALS['vaak_owner_id'] ?? $GLOBALS['vaak_user']['id'] ?? 0);
    if ($vaakKey === 'cmdr_nova') {
        return '1';
    }
    if ($vaakKey !== '' && preg_match('/^[a-z0-9_]+$/', $vaakKey)) {
        if ($uid < 1 && function_exists('ap_db_owner_user_id_for_actor')) {
            $actorId = rtrim((string) ($GLOBALS['vaak_actor_id'] ?? ('https://mkultra.monster/users/' . $vaakKey)), '/');
            $uid = ap_db_owner_user_id_for_actor($actorId);
        }
        return $uid > 0 ? (string) $uid : '';
    }
    // No actor key bound — do not pretend we are cmdr_nova.
    return $uid > 0 ? (string) $uid : '';
}

/**
 * Actor IRI for the authenticated Ice Cubes / VAAK session.
 * Unbound → '' (never invent cmdr_nova).
 */
function ap_masto_session_actor_id(): string
{
    // Prefer OAuth/token-bound globals first so poll/etc. never leak across users
    // when ap_request_actor still points at a different inbox actor.
    $g = rtrim((string) ($GLOBALS['vaak_actor_id'] ?? ''), '/');
    if ($g !== '' && str_starts_with($g, 'https://')) {
        return $g;
    }
    $key = strtolower(trim((string) ($GLOBALS['vaak_actor_key'] ?? '')));
    if ($key !== '' && preg_match('/^[a-z0-9_]+$/', $key)) {
        return 'https://mkultra.monster/users/' . $key;
    }
    if (!empty($GLOBALS['vaak_user']['actor_id'])) {
        $u = rtrim((string) $GLOBALS['vaak_user']['actor_id'], '/');
        if ($u !== '' && str_starts_with($u, 'https://')) {
            return $u;
        }
    }
    // No bound VAAK/OAuth actor — never invent cmdr_nova (hijack risk).
    return '';
}

/** True for https://mkultra.monster/users/{localKey}. */
function ap_masto_is_local_actor_url(string $actorId): bool
{
    return (bool) preg_match(
        '#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#',
        rtrim(trim($actorId), '/')
    );
}

/**
 * If $url is a local note, return its actor IRI; else null.
 * e.g. .../users/test_account/notes/abc → .../users/test_account
 */
function ap_masto_local_note_actor_id(string $url): ?string
{
    if (preg_match('#^(https://mkultra\.monster/users/[A-Za-z0-9_]+)/notes/#', rtrim(trim($url), '/'), $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Resolve a local ap_users row from a Mastodon account id ("1" = cmdr_nova).
 *
 * @return array<string,mixed>|null
 */
function ap_masto_local_user_by_account_id(string $accountId): ?array
{
    $accountId = trim($accountId);
    if ($accountId === '' || !ctype_digit($accountId)) {
        return null;
    }
    try {
        if ($accountId === '1') {
            $st = ap_db()->prepare(
                "SELECT * FROM ap_users WHERE actor_key = 'cmdr_nova' AND disabled_at IS NULL LIMIT 1"
            );
            $st->execute();
            $row = $st->fetch();
            return is_array($row) ? $row : null;
        }
        $st = ap_db()->prepare(
            'SELECT * FROM ap_users WHERE id = ? AND disabled_at IS NULL LIMIT 1'
        );
        $st->execute([(int) $accountId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

function ap_masto_account(): array
{
    // Require a bound session/token actor — never default the whole account to cmdr_nova.
    $vaakKey = strtolower(trim((string) ($GLOBALS['vaak_actor_key'] ?? '')));
    if ($vaakKey === '' || !preg_match('/^[a-z0-9_]+$/', $vaakKey)) {
        $vaakKey = '';
        if (!empty($GLOBALS['vaak_user']['actor_key'])) {
            $vaakKey = strtolower(trim((string) $GLOBALS['vaak_user']['actor_key']));
        }
    }
    if ($vaakKey === '' || !preg_match('/^[a-z0-9_]+$/', $vaakKey)) {
        // Last resort for legacy callers with no bind: empty shell (API auth should have bound).
        $actorKey = '';
        $actorId = function_exists('ap_masto_session_actor_id') ? ap_masto_session_actor_id() : '';
        $accountId = function_exists('ap_masto_session_account_id') ? ap_masto_session_account_id() : '';
        $username = '';
        if ($actorId === '' || $accountId === '') {
            return [
                'id' => '',
                'username' => '',
                'acct' => '',
                'display_name' => '',
                'locked' => false,
                'bot' => false,
                'discoverable' => false,
                'group' => false,
                'created_at' => gmdate('Y-m-d\\TH:i:s.000\\Z'),
                'note' => '',
                'url' => '',
                'avatar' => 'https://mkultra.monster/img/avatar/local-default.webp',
                'avatar_static' => 'https://mkultra.monster/img/avatar/local-default.webp',
                'header' => 'https://mkultra.monster/img/gifs/waves.gif',
                'header_static' => 'https://mkultra.monster/img/gifs/waves.gif',
                'followers_count' => 0,
                'following_count' => 0,
                'statuses_count' => 0,
                'last_status_at' => null,
                'emojis' => [],
                'fields' => [],
            ];
        }
        if (preg_match('#/users/([A-Za-z0-9_]+)$#', $actorId, $m)) {
            $actorKey = strtolower($m[1]);
            $username = $actorKey;
            $vaakKey = $actorKey;
        }
    }
    $actorKey = $vaakKey;
    $actorId = rtrim((string) ($GLOBALS['vaak_actor_id'] ?? ('https://mkultra.monster/users/' . $actorKey)), '/');
    $username = (string) ($GLOBALS['vaak_user']['username'] ?? $actorKey);
    if ($actorKey === 'cmdr_nova') {
        $accountId = '1';
    } else {
        $uid = (int) ($GLOBALS['vaak_owner_id'] ?? $GLOBALS['vaak_user']['id'] ?? 0);
        if ($uid < 1 && function_exists('ap_db_owner_user_id_for_actor')) {
            $uid = ap_db_owner_user_id_for_actor($actorId);
        }
        // Stable id from DB only — never fall through to cmdr "1".
        $accountId = $uid > 0 ? (string) $uid : '';
    }

    $p = ap_profile_get($actorKey);
    $followers = count(ap_followers_list($actorId));
    $following = count(ap_following_list($actorId));
    $statuses = ($actorKey === 'cmdr_nova')
        ? ap_public_post_count()
        : (function_exists('ap_outbox_list') ? count(ap_outbox_list(200, $actorKey)) : 0);
    $note = (string) ($p['summary'] ?? '');
    $avatar = function_exists('ap_local_avatar_url')
        ? ap_local_avatar_url($p['icon_url'] ?? null)
        : ((string) ($p['icon_url'] ?? '') ?: 'https://mkultra.monster/img/avatar/local-default.webp');
    $header = $p['image_url'] ?: 'https://mkultra.monster/img/gifs/waves.gif';
    $created = '2018-01-01T00:00:00.000Z';
    if (!empty($GLOBALS['vaak_user']['created_at']) && is_string($GLOBALS['vaak_user']['created_at'])) {
        try {
            $created = (new DateTimeImmutable((string) $GLOBALS['vaak_user']['created_at']))
                ->setTimezone(new DateTimeZone('UTC'))
                ->format('Y-m-d\\TH:i:s.000\\Z');
        } catch (Throwable $e) {
            // keep default
        }
    }
    $fields = function_exists('ap_profile_masto_fields') ? ap_profile_masto_fields(false, $actorKey) : [];
    $sourceFields = function_exists('ap_profile_masto_fields') ? ap_profile_masto_fields(true, $actorKey) : [];
    $displayName = function_exists('ap_profile_display_name')
        ? ap_profile_display_name($actorKey, true)
        : ((string) ($p['name'] ?? '') ?: $username);
    return [
        'id' => $accountId,
        'username' => $username,
        'acct' => $username,
        'display_name' => $displayName,
        'locked' => !empty($p['manually_approves']),
        'bot' => false,
        'discoverable' => !empty($p['discoverable']),
        'indexable' => array_key_exists('indexable', $p) ? !empty($p['indexable']) : true,
        'group' => false,
        'created_at' => $created,
        'note' => $note,
        'url' => $actorId,
        'uri' => $actorId,
        'avatar' => $avatar,
        'avatar_static' => $avatar,
        'header' => $header,
        'header_static' => $header,
        'followers_count' => $followers,
        'following_count' => $following,
        'statuses_count' => $statuses,
        'last_status_at' => null,
        'emojis' => [],
        'fields' => $fields,
        'source' => [
            'privacy' => 'public',
            'sensitive' => false,
            'language' => 'en',
            'note' => strip_tags($note),
            'fields' => $sourceFields,
        ],
    ];
}

/**
 * Mastodon account entity for any local ap_users row (search / lookups).
 *
 * @param array<string,mixed> $user
 * @return array<string,mixed>
 */
function ap_masto_account_from_user(array $user): array
{
    $actorKey = strtolower(trim((string) ($user['actor_key'] ?? $user['username'] ?? '')));
    if ($actorKey === '') {
        return ap_masto_account();
    }
    $actorId = rtrim((string) ($user['actor_id'] ?? ('https://mkultra.monster/users/' . $actorKey)), '/');
    $username = (string) ($user['username'] ?? $actorKey);
    $uid = (int) ($user['id'] ?? 0);
    $accountId = ($actorKey === 'cmdr_nova') ? '1' : ($uid > 0 ? (string) $uid : '1');
    $p = ap_profile_get($actorKey);
    $note = (string) ($p['summary'] ?? '');
    $avatar = function_exists('ap_local_avatar_url')
        ? ap_local_avatar_url($p['icon_url'] ?? null)
        : ((string) ($p['icon_url'] ?? '') ?: 'https://mkultra.monster/img/avatar/local-default.webp');
    $header = $p['image_url'] ?: 'https://mkultra.monster/img/gifs/waves.gif';
    $displayName = function_exists('ap_profile_display_name')
        ? ap_profile_display_name($actorKey, true)
        : ((string) ($p['name'] ?? '') ?: $username);
    $fields = function_exists('ap_profile_masto_fields') ? ap_profile_masto_fields(false, $actorKey) : [];
    return [
        'id' => $accountId,
        'username' => $username,
        'acct' => $username,
        'display_name' => $displayName,
        'locked' => !empty($p['manually_approves']),
        'bot' => false,
        'discoverable' => !empty($p['discoverable']),
        'indexable' => array_key_exists('indexable', $p) ? !empty($p['indexable']) : true,
        'group' => false,
        'created_at' => '2018-01-01T00:00:00.000Z',
        'note' => $note,
        'url' => $actorId,
        'uri' => $actorId,
        'avatar' => $avatar,
        'avatar_static' => $avatar,
        'header' => $header,
        'header_static' => $header,
        'followers_count' => count(ap_followers_list($actorId)),
        'following_count' => count(ap_following_list($actorId)),
        'statuses_count' => ($actorKey === 'cmdr_nova')
            ? ap_public_post_count()
            : (function_exists('ap_outbox_list') ? count(ap_outbox_list(200, $actorKey)) : 0),
        'last_status_at' => null,
        'emojis' => [],
        'fields' => $fields,
    ];
}

/**
 * Time-ordered public status ids so Ice Cubes (which assumes Mastodon snowflake
 * ordering) does not pin tiny local ids above newer federated posts.
 * type: 0=local, 1=event, 2=mention-status, 3=dm, 4=reblog-wrapper,
 *       5=notif(mention-row), 6=notif(follow), 7=notif(poll),
 *       8=announce_inner (boosted Note with no local Create row)
 *
 * v2 (2026-08): sec * 1e9 + type * 1e8 + dbId (full id up to 1e8-1).
 * Fixes home/federated taps opening the wrong post — v1 used dbId%10000 and
 * PDO/SQLite string binds broke modulo disambiguation, so event 15519 decoded
 * as 5519.
 */
function ap_masto_snowflake_id(string $iso, int $dbId, int $type = 0): string
{
    $t = strtotime($iso);
    if ($t === false) {
        $t = time();
    }
    // Keep within signed 64-bit: sec(~2e9) * 1e9 = ~2e18
    return (string) (((int) $t) * 1000000000 + (($type % 10) * 100000000) + ($dbId % 100000000));
}

/**
 * Stable snowflake for an Announce's inner Note when no Create event exists.
 * db slot = crc32(object_id) % 1e8; type 8 so it never collides with real events.
 */
function ap_masto_announce_inner_synth_id(string $createdAt, string $objectId): string
{
    $objectId = rtrim($objectId, '/');
    return ap_masto_snowflake_id(
        $createdAt !== '' ? $createdAt : gmdate('c'),
        abs(crc32($objectId)) % 100000000,
        8
    );
}

/**
 * @return array{type:string,db_id:int,ver?:int,ms?:int,sec?:int}|null
 */
function ap_masto_parse_public_status_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    // Legacy namespaces (pre-snowflake)
    if ($id < 2000000) {
        return ['type' => 'local', 'db_id' => $id, 'ver' => 0];
    }
    if ($id < 3000000) {
        return ['type' => 'mention', 'db_id' => $id - 2000000, 'ver' => 0];
    }
    if ($id < 4000000) {
        return ['type' => 'event', 'db_id' => $id - 3000000, 'ver' => 0];
    }
    if ($id < 5000000) {
        return ['type' => 'dm', 'db_id' => $id - 4000000, 'ver' => 0];
    }

    $typeMap = static function (int $typeNum): string {
        return match ($typeNum) {
            1 => 'event',
            2 => 'mention',
            3 => 'dm',
            4 => 'reblog',
            8 => 'announce_inner',
            default => 'local',
        };
    };

    // v2 snowflake: sec * 1e9 + …  (≥ 1e18 for dates from ~2001)
    if ($id >= 1000000000000000000) {
        $dbId = $id % 100000000;
        $typeNum = (int) floor(($id % 1000000000) / 100000000);
        $sec = (int) floor($id / 1000000000);
        return [
            'type' => $typeMap($typeNum),
            'db_id' => $dbId,
            'ver' => 2,
            'sec' => $sec,
        ];
    }

    // v1 snowflake: ms * 1e5 + type * 1e4 + dbId%1e4 (collision-prone; kept for cached client ids)
    $dbId = $id % 10000;
    $typeNum = (int) floor(($id % 100000) / 10000);
    $ms = (int) floor($id / 100000);
    return [
        'type' => $typeMap($typeNum),
        'db_id' => $dbId,
        'ver' => 1,
        'ms' => $ms,
    ];
}

/**
 * Resolve a truncated (v1) db id against a table using timestamp proximity.
 * Inlines integers — PDO SQLite binds parameters as strings, which breaks
 * `id % N = ?` comparisons (only `id = ?` matched, causing wrong-post opens).
 *
 * @param 'events'|'mentions'|'direct_messages' $table
 */
function ap_masto_disambiguate_mod_id(
    string $table,
    int $dbMod,
    int $timeMs,
    string $timeCol = 'created_at',
    bool $requireDeletedNull = false,
    int $mod = 10000
): ?int {
    $allowed = ['events' => true, 'mentions' => true, 'direct_messages' => true];
    if (!isset($allowed[$table])) {
        return null;
    }
    $dbMod = (int) $dbMod;
    $mod = max(1, (int) $mod);
    $extra = $requireDeletedNull ? ' AND deleted_at IS NULL' : '';
    // phpcs:ignore — table/column whitelisted above; ids inlined as ints
    $sql = "SELECT id, {$timeCol} AS ts FROM {$table}
            WHERE (id = {$dbMod} OR (id % {$mod}) = {$dbMod}){$extra}
            ORDER BY id DESC LIMIT 40";
    try {
        $rows = ap_db()->query($sql)->fetchAll();
    } catch (Throwable $e) {
        return null;
    }
    $best = null;
    $bestDelta = PHP_INT_MAX;
    foreach ($rows as $row) {
        $t = strtotime((string) ($row['ts'] ?? ''));
        if ($t === false) {
            continue;
        }
        $delta = abs(((int) $t * 1000) - $timeMs);
        if ($delta < $bestDelta) {
            $bestDelta = $delta;
            $best = (int) $row['id'];
        }
    }
    return $best;
}

/** Fill favourited/bookmarked/reblogged from local masto_* tables (status id string). */
function ap_masto_apply_interaction_flags(array $status): array
{
    $id = (string) ($status['id'] ?? '');
    if ($id !== '') {
        $status['favourited'] = ap_masto_status_is_favourited($id);
        $status['bookmarked'] = ap_masto_status_is_bookmarked($id);
        $status['reblogged'] = ap_masto_status_is_reblogged($id);
    }
    if (isset($status['reblog']) && is_array($status['reblog'])) {
        $status['reblog'] = ap_masto_apply_interaction_flags($status['reblog']);
    }
    return $status;
}

/**
 * @param array<string,mixed> $row
 * @param bool $attachQuote When false, skip nested Status.quote (used while embedding a quote target)
 */
/**
 * Local actor key from a mkultra note/actor URL, or null.
 */
function ap_masto_local_actor_key_from_url(?string $url): ?string
{
    $url = rtrim((string) $url, '/');
    if ($url !== '' && preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)(?:/|$)#', $url, $m)) {
        return strtolower($m[1]);
    }
    return null;
}

/**
 * Account entity for a local actor URL (does not use the VAAK session identity).
 *
 * @return array<string,mixed>
 */
function ap_masto_account_for_local_url(?string $url): array
{
    $key = ap_masto_local_actor_key_from_url($url);
    if ($key === null) {
        return ap_masto_account();
    }
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM ap_users WHERE lower(actor_key) = lower(?) AND disabled_at IS NULL LIMIT 1'
        );
        $st->execute([$key]);
        $user = $st->fetch();
        if (is_array($user) && function_exists('ap_masto_account_from_user')) {
            return ap_masto_account_from_user($user);
        }
    } catch (Throwable $e) {
        // fall through
    }
    // Profile-only local (no ap_users row) — build a minimal account without clobbering session
    $prevKey = $GLOBALS['vaak_actor_key'] ?? null;
    $prevId = $GLOBALS['vaak_actor_id'] ?? null;
    $prevUser = $GLOBALS['vaak_user'] ?? null;
    $GLOBALS['vaak_actor_key'] = $key;
    $GLOBALS['vaak_actor_id'] = 'https://mkultra.monster/users/' . $key;
    unset($GLOBALS['vaak_user']);
    try {
        return ap_masto_account();
    } finally {
        if ($prevKey !== null) {
            $GLOBALS['vaak_actor_key'] = $prevKey;
        } else {
            unset($GLOBALS['vaak_actor_key']);
        }
        if ($prevId !== null) {
            $GLOBALS['vaak_actor_id'] = $prevId;
        } else {
            unset($GLOBALS['vaak_actor_id']);
        }
        if ($prevUser !== null) {
            $GLOBALS['vaak_user'] = $prevUser;
        }
    }
}

function ap_masto_status_from_row(array $row, bool $attachQuote = true, bool $allowQuoteFetch = false): array
{
    // Author must come from the note URL — never the logged-in VAAK session
    $noteIdForAcct = (string) ($row['note_id'] ?? '');
    $account = $noteIdForAcct !== ''
        ? ap_masto_account_for_local_url($noteIdForAcct)
        : ap_masto_account();
    $text = (string) ($row['content_text'] ?? '');
    // Placeholder used when quote/media/poll-only posts have no commentary
    if ($text === '(quote)' || $text === '(media)' || $text === '(poll)') {
        $text = '';
    }
    $html = $text !== ''
        ? (function_exists('ap_plain_text_to_html')
            ? ap_plain_text_to_html($text)
            : '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>')
        : '';
    // Prefer live outbox HTML when present
    $noteId = (string) ($row['note_id'] ?? '');
    $quoteObjectUrl = null;
    if ($noteId !== '') {
        $st = ap_db()->prepare('SELECT content, raw_create_json FROM outbox_notes WHERE id = ?');
        $st->execute([$noteId]);
        $ob = $st->fetch();
        if (is_array($ob)) {
            if (!empty($ob['content']) && trim(strip_tags((string) $ob['content'])) !== '') {
                $html = (string) $ob['content'];
                // Drop legacy </p><br><p> spacers — they double-gap in Ice Cubes/Mastodon.
                if (function_exists('ap_normalize_status_html')) {
                    $html = ap_normalize_status_html($html);
                }
            } elseif ($text === '') {
                $html = '';
            }
            if ($attachQuote) {
                $quoteObjectUrl = ap_masto_quote_url_from_create_json(
                    isset($ob['raw_create_json']) ? (string) $ob['raw_create_json'] : null
                );
            }
        }
    }
    $published = (string) ($row['published'] ?? gmdate('c'));
    try {
        $dt = new DateTimeImmutable($published);
        $published = $dt->format('Y-m-d\TH:i:s.000\Z');
    } catch (Throwable $e) {
        $published = gmdate('Y-m-d\TH:i:s.000\Z');
    }
    $localId = (int) ($row['local_id'] ?? 0);
    $id = ap_masto_snowflake_id($published, $localId, 0);
    $url = $noteId !== '' ? $noteId : ap_masto_session_actor_id();
    $replyPublicId = null;
    $replyAccountId = null;
    $replyParentActor = null;
    $outboxInReplyTo = null;
    if (isset($row['in_reply_to_local_id']) && $row['in_reply_to_local_id'] !== null) {
        $parent = ap_masto_status_by_local_id((int) $row['in_reply_to_local_id']);
        if ($parent) {
            $replyPublicId = ap_masto_snowflake_id((string) ($parent['published'] ?? $published), (int) $parent['local_id'], 0);
            $parentNote = (string) ($parent['note_id'] ?? '');
            $parentAcct = $parentNote !== '' ? ap_masto_account_for_local_url($parentNote) : ap_masto_account();
            $replyAccountId = (string) ($parentAcct['id'] ?? '1');
            $replyParentActor = rtrim((string) ($parentAcct['url'] ?? $parentAcct['uri'] ?? 'https://mkultra.monster/users/cmdr_nova'), '/');
        }
    }
    // Remote reply parent: resolve from outbox_notes.in_reply_to when local parent missing
    if ($replyPublicId === null && $noteId !== '') {
        try {
            $stIr = ap_db()->prepare('SELECT in_reply_to FROM outbox_notes WHERE id = ?');
            $stIr->execute([$noteId]);
            $irRow = $stIr->fetch();
            $outboxInReplyTo = is_array($irRow) ? rtrim((string) ($irRow['in_reply_to'] ?? ''), '/') : '';
            if ($outboxInReplyTo !== '' && str_starts_with($outboxInReplyTo, 'https://')) {
                // Prefer event/mention snowflake if we already know the parent object
                $parentStatus = function_exists('ap_masto_lookup_status_by_object_url')
                    ? ap_masto_lookup_status_by_object_url($outboxInReplyTo, 0, false)
                    : null;
                if (is_array($parentStatus) && !empty($parentStatus['id'])) {
                    $replyPublicId = (string) $parentStatus['id'];
                    if (!empty($parentStatus['account']['id'])) {
                        $replyAccountId = (string) $parentStatus['account']['id'];
                    }
                    if (!empty($parentStatus['account']['url'])) {
                        $replyParentActor = rtrim((string) $parentStatus['account']['url'], '/');
                    }
                }
                if ($replyParentActor === null) {
                    $replyParentActor = ap_masto_actor_url_from_object_url($outboxInReplyTo);
                }
                if ($replyAccountId === null && is_string($replyParentActor) && $replyParentActor !== '') {
                    $replyAccountId = ap_masto_remote_account_id($replyParentActor);
                    ap_masto_account_actor_remember($replyAccountId, $replyParentActor);
                }
                // Synthetic fallback id so Ice Cubes still threads the reply UI
                if ($replyPublicId === null) {
                    $replyPublicId = (string) (900000000 + (abs(crc32($outboxInReplyTo)) % 99999999));
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }
    $status = [
        'id' => $id,
        'created_at' => $published,
        'in_reply_to_id' => $replyPublicId,
        'in_reply_to_account_id' => $replyAccountId,
        'sensitive' => !empty($row['sensitive']),
        'spoiler_text' => (string) ($row['spoiler_text'] ?? ''),
        'visibility' => (string) ($row['visibility'] ?? 'public'),
        'language' => 'en',
        'uri' => $url,
        'url' => $url,
        'replies_count' => 0,
        'reblogs_count' => 0,
        'favourites_count' => 0,
        'quotes_count' => 0,
        'edited_at' => !empty($row['edited_at'])
            ? ap_masto_format_time((string) $row['edited_at'])
            : null,
        'favourited' => false,
        'reblogged' => false,
        'muted' => false,
        'bookmarked' => false,
        'pinned' => ap_masto_status_is_pinned($localId),
        'content' => $html !== '' ? $html : '<p></p>',
        'reblog' => null,
        'application' => ['name' => 'mkultra.monster', 'website' => 'https://mkultra.monster'],
        'account' => $account,
        'media_attachments' => ap_masto_status_media_attachments((int) ($row['local_id'] ?? 0)),
        'mentions' => [],
        'tags' => [],
        'emojis' => [],
        'card' => null,
        'poll' => ap_masto_poll_entity_for_status($localId),
        'quote_approval' => [
            'automatic' => ['public'],
            'manual' => [],
            'current_user' => 'automatic',
        ],
    ];
    // Mentions + hashtags for Ice Cubes (clickable @ / # — @ must be inside the <a>)
    $plainForEntities = $text !== ''
        ? $text
        : trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    $extraActors = [];
    if (is_string($replyParentActor) && $replyParentActor !== '') {
        $extraActors[] = $replyParentActor;
    }
    $hasMentionMarkup = str_contains($html, 'class="u-url mention"')
        || str_contains($html, "class='u-url mention'")
        || str_contains($html, 'class="mention"');
    if ($plainForEntities !== '' && (str_contains($plainForEntities, '@') || str_contains($plainForEntities, '#'))) {
        $packLocal = ap_masto_content_with_mentions($plainForEntities, $extraActors);
        $status['mentions'] = $packLocal['mentions'] ?? [];
        $status['tags'] = $packLocal['tags'] ?? [];
        // Prefer mention-aware HTML so Ice Cubes links "@" + username together.
        // Older outbox rows used ap_plain_text_to_html (no h-card) — rebuild those.
        if (!$hasMentionMarkup && !empty($packLocal['content'])) {
            $status['content'] = $packLocal['content'];
        } elseif ($html !== '' && str_contains($html, '<')) {
            $htag = ap_masto_linkify_hashtags_in_html($html);
            $status['content'] = $htag['html'];
            if (!empty($htag['tags'])) {
                $status['tags'] = $htag['tags'];
            }
        } elseif (!empty($packLocal['content'])) {
            $status['content'] = $packLocal['content'];
        }
    } elseif ($html !== '' && str_contains($html, '#')) {
        $htag = ap_masto_linkify_hashtags_in_html($html);
        $status['content'] = $htag['html'];
        $status['tags'] = $htag['tags'];
    }
    // Link preview card from cache only (no sync HTTP here — avoids slow/flaky
    // notification + timeline renders under SQLite write load). Compose/homepage warm the cache.
    $hasMedia = is_array($status['media_attachments']) && count($status['media_attachments']) > 0;
    if (!$hasMedia && function_exists('ap_link_preview_card_for_status_text')) {
        $cardSource = $text !== '' ? $text : $html;
        $card = ap_link_preview_card_for_status_text($cardSource, false, false);
        if ($card !== null) {
            $status['card'] = $card;
        }
    }
    if ($attachQuote) {
        // Timelines: cache-only quotes (no sync HTTP). Detail views may pass allowQuoteFetch.
        if ($allowQuoteFetch && function_exists('ap_feature_enabled')) {
            $allowQuoteFetch = ap_feature_enabled('detail_quote_hydration', true);
        }
        $quote = ap_masto_quote_entity($quoteObjectUrl, 0, $allowQuoteFetch);
        if ($quote !== null) {
            $status['quote'] = $quote;
        }
    }
    return ap_masto_apply_interaction_flags($status);
}

/**
 * Mastodon Poll entity for a local status, or null.
 *
 * @return array<string,mixed>|null
 */
function ap_masto_poll_entity_for_status(int $statusLocalId): ?array
{
    if ($statusLocalId <= 0) {
        return null;
    }
    $st = ap_db()->prepare('SELECT * FROM masto_polls WHERE status_local_id = ?');
    $st->execute([$statusLocalId]);
    $row = $st->fetch();
    return is_array($row) ? ap_masto_poll_entity($row) : null;
}

/**
 * @param array<string,mixed> $row masto_polls row
 * @return array<string,mixed>
 */
function ap_masto_poll_entity(array $row): array
{
    $options = json_decode((string) ($row['options_json'] ?? '[]'), true);
    if (!is_array($options)) {
        $options = [];
    }
    $optsOut = [];
    foreach ($options as $opt) {
        if (!is_array($opt)) {
            continue;
        }
        $optsOut[] = [
            'title' => (string) ($opt['title'] ?? ''),
            'votes_count' => (int) ($opt['votes_count'] ?? 0),
        ];
    }
    $expiresAt = (string) ($row['expires_at'] ?? '');
    $expired = false;
    try {
        if ($expiresAt !== '') {
            $expired = (new DateTimeImmutable($expiresAt)) <= new DateTimeImmutable('now');
        }
    } catch (Throwable $e) {
        $expired = false;
    }
    $voters = json_decode((string) ($row['voters_json'] ?? '[]'), true);
    if (!is_array($voters)) {
        $voters = [];
    }
    $me = ap_masto_session_actor_id();
    $voted = in_array($me, $voters, true)
        || in_array($me . '/', $voters, true);

    return [
        'id' => (string) (int) ($row['local_id'] ?? 0),
        'expires_at' => $expiresAt !== '' ? ap_masto_format_time($expiresAt) : null,
        'expired' => $expired,
        'multiple' => !empty($row['multiple']),
        'votes_count' => (int) ($row['votes_count'] ?? 0),
        'voters_count' => (int) ($row['voters_count'] ?? 0),
        'voted' => $voted,
        'own_votes' => $voted ? [] : null, // detailed own_votes filled by vote endpoint
        'options' => $optsOut,
    ];
}

/** Pull FEP-044f / Misskey quote target URL from a stored Create activity JSON. */
function ap_masto_quote_url_from_create_json(?string $rawCreateJson): ?string
{
    if ($rawCreateJson === null || $rawCreateJson === '') {
        return null;
    }
    $doc = json_decode($rawCreateJson, true);
    if (!is_array($doc)) {
        return null;
    }
    $obj = $doc['object'] ?? null;
    if (!is_array($obj)) {
        return null;
    }
    if (function_exists('ap_quote_target_url')) {
        $u = ap_quote_target_url($obj);
        if (is_string($u) && str_starts_with($u, 'https://')) {
            return rtrim($u, '/');
        }
    }
    foreach (['quote', 'quoteUri', 'quoteUrl', '_misskey_quote'] as $key) {
        $v = $obj[$key] ?? null;
        if (is_string($v) && str_starts_with($v, 'https://')) {
            return rtrim($v, '/');
        }
        if (is_array($v)) {
            $id = $v['id'] ?? $v['url'] ?? $v['href'] ?? null;
            if (is_string($id) && str_starts_with($id, 'https://')) {
                return rtrim($id, '/');
            }
        }
    }
    return null;
}

/**
 * Mastodon 4.4+/4.5 Quote entity for Ice Cubes (needs state=accepted + quoted_status).
 *
 * @return array{state:string,quoted_status:?array<string,mixed>,quoted_status_id:?string}|null
 */
function ap_masto_quote_entity(?string $quoteObjectUrl, int $depth = 0, bool $allowFetch = false): ?array
{
    if ($quoteObjectUrl === null || $quoteObjectUrl === '' || !str_starts_with($quoteObjectUrl, 'https://')) {
        return null;
    }
    $quoteObjectUrl = rtrim($quoteObjectUrl, '/');
    // Avoid deep nesting / recursion
    if ($depth > 1) {
        return [
            'state' => 'accepted',
            'quoted_status' => null,
            'quoted_status_id' => null,
        ];
    }

    $quoted = ap_masto_lookup_status_by_object_url($quoteObjectUrl, $depth + 1, $allowFetch);
    if ($quoted === null) {
        // Still advertise the quote so clients know one exists (pending until cached)
        return [
            'state' => 'pending',
            'quoted_status' => null,
            'quoted_status_id' => null,
        ];
    }

    return [
        'state' => 'accepted',
        'quoted_status' => $quoted,
        'quoted_status_id' => (string) ($quoted['id'] ?? ''),
    ];
}

/**
 * Alternate forms of a status/object URL for cache hits without HTTP.
 * Prefers shared ap_object_url_lookup_candidates() from ap-inbox.php when loaded.
 *
 * @return list<string>
 */
function ap_masto_object_url_lookup_candidates(string $objectUrl): array
{
    if (function_exists('ap_object_url_lookup_candidates')) {
        return ap_object_url_lookup_candidates($objectUrl);
    }
    $objectUrl = rtrim(trim($objectUrl), '/');
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        return [];
    }
    $cands = [$objectUrl];
    if (preg_match('#^(https://[^/]+)/@([^/]+)/([A-Za-z0-9_-]+)$#', $objectUrl, $m)) {
        $cands[] = $m[1] . '/users/' . rawurlencode($m[2]) . '/statuses/' . $m[3];
    }
    if (preg_match('#^(https://[^/]+)/(?:ap/)?users/([^/]+)/statuses/([A-Za-z0-9_-]+)$#', $objectUrl, $m)) {
        $cands[] = $m[1] . '/@' . rawurldecode($m[2]) . '/' . $m[3];
    }
    return array_values(array_unique($cands));
}

/**
 * Resolve an ActivityPub object URL to a Mastodon Status entity (local, event, or live fetch).
 *
 * @return array<string,mixed>|null
 */
function ap_masto_lookup_status_by_object_url(string $objectUrl, int $quoteDepth = 1, bool $allowFetch = false): ?array
{
    $objectUrl = rtrim(trim($objectUrl), '/');
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        return null;
    }

    $candidates = ap_masto_object_url_lookup_candidates($objectUrl);
    if ($candidates === []) {
        $candidates = [$objectUrl];
    }

    foreach ($candidates as $cand) {
        // Local note
        $local = ap_masto_status_by_note_id($cand);
        if (is_array($local)) {
            // Nested embeds skip further quote attachment
            return ap_masto_status_from_row($local, false);
        }

        // Inbound event (federated Create/etc.)
        $st = ap_db()->prepare(
            'SELECT * FROM events WHERE object_id = ? OR object_id = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$cand, $cand . '/']);
        $erow = $st->fetch();
        if (is_array($erow)) {
            $status = ap_masto_status_from_event($erow);
            if (is_array($status)) {
                // Prefer the inner reblog payload when the event was an Announce
                if (!empty($status['reblog']) && is_array($status['reblog'])) {
                    $status = $status['reblog'];
                }
                unset($status['quote']);
                return $status;
            }
        }

        // Mentions table
        $st = ap_db()->prepare(
            'SELECT * FROM mentions WHERE (object_id = ? OR object_id = ?) AND deleted_at IS NULL ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$cand, $cand . '/']);
        $mrow = $st->fetch();
        if (is_array($mrow) && function_exists('ap_masto_status_from_mention')) {
            $status = ap_masto_status_from_mention($mrow);
            if (is_array($status)) {
                unset($status['quote']);
                return $status;
            }
        }
    }

    // Live fetch only when explicitly allowed (status detail) — never on timelines.
    if (!$allowFetch) {
        return null;
    }
    if (!function_exists('ap_fetch_as2_object')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    $doc = null;
    $fetchedAs = $objectUrl;
    foreach ($candidates as $cand) {
        $doc = ap_fetch_as2_object($cand);
        if (is_array($doc)) {
            $fetchedAs = $cand;
            break;
        }
    }
    if (!is_array($doc)) {
        return null;
    }
    // Unwrap Create
    if (($doc['type'] ?? '') === 'Create' && isset($doc['object']) && is_array($doc['object'])) {
        $doc = $doc['object'];
    }
    return ap_masto_status_from_as2_note($doc, $fetchedAs);
}

/**
 * Best-effort Status entity from a remote/local AS2 Note (for quote embeds).
 *
 * @param array<string,mixed> $note
 * @return array<string,mixed>|null
 */
function ap_masto_status_from_as2_note(array $note, string $fallbackUrl): ?array
{
    $objectUrl = '';
    if (function_exists('ap_as_id')) {
        $objectUrl = (string) (ap_as_id($note['id'] ?? null) ?: '');
    }
    if ($objectUrl === '') {
        $objectUrl = is_string($note['id'] ?? null) ? (string) $note['id'] : $fallbackUrl;
    }
    $objectUrl = rtrim($objectUrl, '/');
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        $objectUrl = rtrim($fallbackUrl, '/');
    }

    $actorId = '';
    if (function_exists('ap_as_id')) {
        $actorId = (string) (ap_as_id($note['attributedTo'] ?? null) ?: ap_as_id($note['actor'] ?? null) ?: '');
    }
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return null;
    }

    $contentHtml = '';
    if (isset($note['content']) && is_string($note['content'])) {
        $contentHtml = $note['content'];
    } elseif (isset($note['source']['content']) && is_string($note['source']['content'])) {
        $plain = $note['source']['content'];
        $contentHtml = function_exists('ap_plain_text_to_html')
            ? ap_plain_text_to_html($plain)
            : '<p>' . htmlspecialchars($plain, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</p>';
    }
    $textPlain = trim(html_entity_decode(strip_tags($contentHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8'));

    $media = [];
    $atts = $note['attachment'] ?? [];
    if (is_array($atts)) {
        if (isset($atts['type']) || isset($atts['url'])) {
            $atts = [$atts];
        }
        $i = 0;
        foreach ($atts as $att) {
            if (!is_array($att)) {
                continue;
            }
            $u = null;
            foreach (['url', 'href'] as $k) {
                if (isset($att[$k]) && is_string($att[$k]) && str_starts_with($att[$k], 'https://')) {
                    $u = $att[$k];
                    break;
                }
                if (isset($att[$k]) && is_array($att[$k]) && isset($att[$k]['href']) && is_string($att[$k]['href'])) {
                    $u = $att[$k]['href'];
                    break;
                }
            }
            $u = is_string($u) ? ap_profile_sanitize_https_url($u) : null;
            if ($u === null) {
                continue;
            }
            $i++;
            $mt = strtolower((string) ($att['mediaType'] ?? ''));
            $type = str_starts_with($mt, 'video/')
                ? 'video'
                : (str_starts_with($mt, 'audio/') ? 'audio' : 'image');
            $media[] = [
                'id' => ap_masto_synthetic_object_status_id($objectUrl) . $i,
                'type' => $type,
                'mediaType' => $mt !== '' ? $mt : null,
                'url' => $u,
                'preview_url' => $u,
                'remote_url' => $u,
                'preview_remote_url' => null,
                'text_url' => null,
                'meta' => null,
                'description' => isset($att['name']) && is_string($att['name']) ? $att['name'] : null,
                'blurhash' => null,
            ];
            if ($i >= 4) {
                break;
            }
        }
    }

    if ($textPlain === '' && !$media) {
        // Still allow empty notes as quote targets (rare)
        $contentHtml = '<p></p>';
    }

    $published = is_string($note['published'] ?? null) ? (string) $note['published'] : gmdate('c');
    $spoiler = is_string($note['summary'] ?? null) ? (string) $note['summary'] : '';
    $sensitive = !empty($note['sensitive']) || $spoiler !== '';

    $isLocal = ap_masto_local_actor_key_from_url($actorId) !== null
        || ap_masto_local_actor_key_from_url($objectUrl) !== null;
    $account = $isLocal
        ? ap_masto_account_for_local_url($actorId !== '' ? $actorId : $objectUrl)
        : ap_masto_remote_account($actorId);

    $mentions = [];
    $tags = [];
    $contentOut = $contentHtml !== '' ? $contentHtml : '<p></p>';
    if ($textPlain !== '' && (str_contains($textPlain, '@') || str_contains($textPlain, '#'))) {
        $packSyn = ap_masto_content_with_mentions($textPlain, $actorId !== '' ? [$actorId] : []);
        $mentions = $packSyn['mentions'] ?? [];
        $tags = $packSyn['tags'] ?? [];
        if ($contentHtml !== '' && str_contains($contentHtml, '<')) {
            $htag = ap_masto_linkify_hashtags_in_html($contentHtml);
            $contentOut = $htag['html'];
            if (!empty($htag['tags'])) {
                $tags = $htag['tags'];
            }
        } elseif (!empty($packSyn['content'])) {
            $contentOut = $packSyn['content'];
        }
    } elseif ($contentHtml !== '' && str_contains($contentHtml, '#')) {
        $htag = ap_masto_linkify_hashtags_in_html($contentHtml);
        $contentOut = $htag['html'];
        $tags = $htag['tags'];
    }

    return [
        'id' => ap_masto_synthetic_object_status_id($objectUrl),
        'created_at' => ap_masto_format_time($published),
        'in_reply_to_id' => null,
        'in_reply_to_account_id' => null,
        'sensitive' => $sensitive,
        'spoiler_text' => $spoiler,
        'visibility' => 'public',
        'language' => 'en',
        'uri' => $objectUrl,
        'url' => $objectUrl,
        'replies_count' => 0,
        'reblogs_count' => 0,
        'favourites_count' => 0,
        'quotes_count' => 0,
        'edited_at' => null,
        'favourited' => false,
        'reblogged' => false,
        'muted' => false,
        'bookmarked' => false,
        'pinned' => false,
        'content' => $contentOut,
        'reblog' => null,
        'application' => null,
        'account' => $account,
        'media_attachments' => $media,
        'mentions' => $mentions,
        'tags' => $tags,
        'emojis' => [],
        'card' => null,
        'poll' => null,
    ];
}

/** Stable public status id for a remote/local object URL (quote embeds). */
function ap_masto_synthetic_object_status_id(string $objectUrl): string
{
    $n = (int) sprintf('%u', crc32(rtrim($objectUrl, '/')));
    // Distinct from local snowflakes / event ids
    return (string) (700000000000000000 + ($n % 99999999999999));
}

function ap_masto_status_media_attachments(int $statusLocalId): array
{
    if ($statusLocalId <= 0 || !function_exists('ap_masto_media_entity')) {
        return [];
    }
    $st = ap_db()->prepare('SELECT * FROM masto_media WHERE status_local_id = ? ORDER BY local_id ASC');
    $st->execute([$statusLocalId]);
    $out = [];
    foreach ($st->fetchAll() as $row) {
        $out[] = ap_masto_media_entity($row);
    }
    return $out;
}

function ap_masto_instance_v1(): array
{
    // Admin contact stays cmdr_nova regardless of any request session/token.
    $contactUser = null;
    try {
        $st = ap_db()->prepare(
            "SELECT * FROM ap_users WHERE actor_key = 'cmdr_nova' AND disabled_at IS NULL LIMIT 1"
        );
        $st->execute();
        $row = $st->fetch();
        if (is_array($row)) {
            $contactUser = $row;
        }
    } catch (Throwable $e) {
        $contactUser = null;
    }
    $account = $contactUser !== null
        ? ap_masto_account_from_user($contactUser)
        : ap_masto_account_by_id('1') ?? ap_masto_account();
    $userCount = function_exists('ap_db_enabled_user_count') ? ap_db_enabled_user_count() : 1;
    return [
        'uri' => 'mkultra.monster',
        'title' => 'NovaLandia',
        'short_description' => 'Invite-only ActivityPub / Mastodon-compatible instance (NovaLandia).',
        'description' => 'Invite-only ActivityPub / Mastodon-compatible instance (NovaLandia).',
        'email' => 'cmdr-nova@mkultra.monster',
        // 4.5 + api_versions.mastodon≥7 → Ice Cubes uses native quote cards (Status.quote)
        'version' => '4.5.0 (compatible; vaak)',
        'urls' => (object) [
            'privacy_policy' => 'https://mkultra.monster/vaak/privacy/',
        ],
        'stats' => [
            'user_count' => $userCount,
            'status_count' => (int) $account['statuses_count'],
            'domain_count' => 1,
        ],
        'thumbnail' => $account['avatar'],
        'languages' => ['en'],
        'registrations' => false,
        'approval_required' => false,
        'invites_enabled' => true,
        'configuration' => [
            'statuses' => [
                'max_characters' => 2000,
                'max_media_attachments' => 4,
                'characters_reserved_per_url' => 23,
            ],
            'media_attachments' => [
                'supported_mime_types' => [
                    'image/jpeg', 'image/png', 'image/gif', 'image/webp',
                    'video/mp4', 'video/webm', 'video/quicktime',
                ],
                'image_size_limit' => 10485760,
                'image_matrix_limit' => 16777216,
                'video_size_limit' => 52428800,
                'video_frame_rate_limit' => 60,
                'video_matrix_limit' => 8300032,
            ],
            'polls' => [
                'max_options' => 4,
                'max_characters_per_option' => 50,
                'min_expiration' => 300,
                'max_expiration' => 604800,
            ],
        ],
        'contact_account' => $account,
        'rules' => (static function (): array {
            if (!function_exists('ap_instance_rules_list')) {
                require_once __DIR__ . '/ap-instance-docs.php';
            }
            return ap_instance_rules_list();
        })(),
    ];
}

function ap_masto_instance_v2(): array
{
    $v1 = ap_masto_instance_v1();
    $userCount = (int) ($v1['stats']['user_count'] ?? 1);
    return [
        'domain' => 'mkultra.monster',
        'title' => $v1['title'],
        'version' => $v1['version'],
        // Mastodon API version 7 = quote posts (Status.quote / quoted_status_id)
        'api_versions' => [
            'mastodon' => 7,
        ],
        'source_url' => 'https://mkultra.monster',
        'description' => $v1['description'],
        'usage' => [
            'users' => ['active_month' => $userCount],
        ],
        'thumbnail' => [
            'url' => $v1['thumbnail'],
        ],
        'languages' => ['en'],
        'configuration' => $v1['configuration'] + [
            'urls' => [
                'streaming' => 'wss://mkultra.monster',
                'status' => null,
                'about' => 'https://mkultra.monster/users/cmdr_nova',
                'privacy_policy' => 'https://mkultra.monster/vaak/privacy/',
                'terms_of_service' => 'https://mkultra.monster/vaak/conduct/',
            ],
            'vapid' => [
                'public_key' => (static function (): string {
                    if (!function_exists('ap_webpush_vapid_public_key')) {
                        require_once __DIR__ . '/ap-webpush.php';
                    }
                    return ap_webpush_vapid_public_key();
                })(),
            ],
        ],
        'registrations' => [
            'enabled' => false,
            'approval_required' => false,
            'message' => null,
        ],
        'contact' => [
            'email' => 'cmdr-nova@mkultra.monster',
            'account' => $v1['contact_account'],
        ],
        'rules' => $v1['rules'],
    ];
}

/* ----------------- Notifications / remote accounts ----------------- */

/**
 * Parse Mastodon-style id / id[] query params (Ice Cubes sends id[]=…).
 *
 * @return list<string>
 */
function ap_masto_parse_id_list_param(): array
{
    $ids = [];
    if (isset($_GET['id']) && is_array($_GET['id'])) {
        foreach ($_GET['id'] as $id) {
            $ids[] = (string) $id;
        }
    } elseif (isset($_GET['id'])) {
        $ids[] = (string) $_GET['id'];
    }
    if (isset($_GET['id[]']) && is_array($_GET['id[]'])) {
        foreach ($_GET['id[]'] as $id) {
            $ids[] = (string) $id;
        }
    }
    // Fallback: raw query when PHP/Caddy leaves id[] unparsed
    if ($ids === [] && !empty($_SERVER['QUERY_STRING'])) {
        parse_str((string) $_SERVER['QUERY_STRING'], $qs);
        if (isset($qs['id']) && is_array($qs['id'])) {
            foreach ($qs['id'] as $id) {
                $ids[] = (string) $id;
            }
        } elseif (isset($qs['id'])) {
            $ids[] = (string) $qs['id'];
        }
        if (isset($qs['id[]']) && is_array($qs['id[]'])) {
            foreach ($qs['id[]'] as $id) {
                $ids[] = (string) $id;
            }
        }
    }
    return $ids;
}

/**
 * Mastodon Relationship entity for a remote/local account id.
 * Matches follows by account id (username@host keyed) and actor IRI aliases,
 * so Ice Cubes search Follow buttons stay in sync after dual-IRI unification.
 *
 * @return array<string,mixed>
 */
function ap_masto_relationship_for_account_id(string $accountId): array
{
    $accountId = trim($accountId);
    $empty = static function (string $id): array {
        return [
            'id' => $id,
            'following' => false,
            'showing_reblogs' => false,
            'notifying' => false,
            'followed_by' => false,
            'blocking' => false,
            'blocked_by' => false,
            'muting' => false,
            'muting_notifications' => false,
            'requested' => false,
            'domain_blocking' => false,
            'endorsed' => false,
            'note' => '',
        ];
    };
    $selfId = function_exists('ap_masto_session_account_id') ? ap_masto_session_account_id() : '1';
    if ($accountId === '' || $accountId === $selfId) {
        return $empty($accountId === '' ? $selfId : $accountId);
    }

    static $followById = null;
    static $followActors = null;
    static $followerActors = null;
    static $cacheAt = 0;
    if ($followById === null || (time() - $cacheAt) >= 30) {
        $followById = [];
        $followActors = [];
        $followerActors = [];
        foreach (ap_following_list() as $f) {
            $a = rtrim((string) ($f['actor_id'] ?? ''), '/');
            if ($a === '') {
                continue;
            }
            $followActors[$a] = true;
            $followById[ap_masto_remote_account_id($a)] = $a;
        }
        foreach (ap_followers_list() as $f) {
            $a = rtrim((string) ($f['actor_id'] ?? ''), '/');
            if ($a !== '') {
                $followerActors[$a] = true;
            }
        }
        $cacheAt = time();
    }

    $actor = ap_masto_actor_id_from_account_id($accountId);
    $following = isset($followById[$accountId]);
    $followedBy = false;
    $blocking = false;
    $muting = false;

    if ($actor) {
        $root = rtrim($actor, '/');
        if (!$following && isset($followActors[$root])) {
            $following = true;
        }
        if (!$following) {
            foreach (ap_masto_actor_id_aliases($actor) as $al) {
                $al = rtrim((string) $al, '/');
                if ($al !== '' && isset($followActors[$al])) {
                    $following = true;
                    break;
                }
            }
        }
        if (isset($followerActors[$root])) {
            $followedBy = true;
        } else {
            foreach (ap_masto_actor_id_aliases($actor) as $al) {
                $al = rtrim((string) $al, '/');
                if ($al !== '' && isset($followerActors[$al])) {
                    $followedBy = true;
                    break;
                }
            }
        }
        // Mastodon clients expect this relationship flag to reflect the
        // authenticated user's personal block, not a server-wide block.
        $ownerId = ap_db_masto_owner_user_id();
        $blocking = function_exists('ap_user_is_blocked')
            ? ap_user_is_blocked($actor, null, $ownerId)
            : false;
        $muting = function_exists('ap_is_muted_actor') && ap_is_muted_actor($actor, ap_db_masto_owner_user_id());
    }

    return [
        'id' => $accountId,
        'following' => $following,
        'showing_reblogs' => $following,
        'notifying' => false,
        'followed_by' => $followedBy,
        'blocking' => $blocking,
        'blocked_by' => false,
        'muting' => $muting,
        'muting_notifications' => $muting,
        'requested' => false,
        'domain_blocking' => false,
        'endorsed' => false,
        'note' => '',
    ];
}

/**
 * Stable acct key (username@host) for a remote actor, when known.
 * Mastodon often exposes the same person as /users/name and /ap/users/{snowflake};
 * keying Mastodon account ids on actor URL CRC made Ice Cubes show duplicates.
 */
function ap_masto_remote_account_acct_key(string $actorId): ?string
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return null;
    }
    $host = strtolower((string) (parse_url($actorId, PHP_URL_HOST) ?? ''));
    $uname = '';
    try {
        $st = ap_db()->prepare(
            'SELECT username, host FROM remote_actors WHERE actor_id = ? OR actor_id = ? LIMIT 1'
        );
        $st->execute([$actorId, $actorId . '/']);
        $row = $st->fetch();
        if (is_array($row)) {
            $uname = trim((string) ($row['username'] ?? ''));
            $h = strtolower(trim((string) ($row['host'] ?? '')));
            if ($h !== '') {
                $host = $h;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    if ($uname === '' && preg_match('#/(?:users|@)([^/]+)/?$#i', $actorId, $m)) {
        $pathUname = rawurldecode((string) $m[1]);
        // Skip Mastodon AP snowflake paths (/ap/users/1168…) — those are not handles.
        if ($pathUname !== '' && !ctype_digit($pathUname)
            && !(function_exists('ap_remote_actor_username_is_placeholder')
                && ap_remote_actor_username_is_placeholder($pathUname))) {
            $uname = $pathUname;
        }
    }
    if ($uname === '' || $host === '' || $host === 'unknown') {
        return null;
    }
    if (function_exists('ap_remote_actor_username_is_placeholder')
        && ap_remote_actor_username_is_placeholder($uname)) {
        return null;
    }
    return mb_strtolower($uname) . '@' . $host;
}

/** Stable numeric-ish account id (string for Mastodon clients). Prefer username@host over actor IRI. */
function ap_masto_remote_account_id(string $actorId): string
{
    $actorId = rtrim(trim($actorId), '/');
    $key = ap_masto_remote_account_acct_key($actorId);
    // Snowflake-only IRIs: resolve via alias set (other IRI may already have a username).
    if ($key === null) {
        foreach (ap_masto_actor_id_aliases($actorId) as $alias) {
            $alias = rtrim($alias, '/');
            if ($alias === '' || $alias === $actorId) {
                continue;
            }
            $key = ap_masto_remote_account_acct_key($alias);
            if ($key !== null) {
                break;
            }
        }
    }
    $seed = $key !== null ? ('acct:' . $key) : $actorId;
    $n = (int) sprintf('%u', crc32($seed));
    // Keep out of local id "1" and synthetic status ranges
    return (string) (300000000 + ($n % 699999999));
}

/** Persist account_id → actor_id so profile taps survive beyond the recent-events scan. */
function ap_masto_account_actor_remember(string $accountId, string $actorId): void
{
    $accountId = trim($accountId);
    $actorId = rtrim(trim($actorId), '/');
    if ($accountId === '' || $actorId === '') {
        return;
    }
    // Local accounts resolve via ap_users — never overwrite with remote IRIs.
    if (function_exists('ap_masto_local_user_by_account_id') && ap_masto_local_user_by_account_id($accountId)) {
        return;
    }
    // Prefer the IRI that actually holds Creates (Mastodon /ap/users/id over /users/name).
    $aliases = ap_masto_actor_id_aliases($actorId);
    if (!empty($aliases[0])) {
        $actorId = rtrim((string) $aliases[0], '/');
    }
    try {
        $db = ap_db();
        // Drop stale duplicate rows for the same person (old URL-CRC ids + other alias IRIs).
        $aliasRoots = [];
        foreach ($aliases as $a) {
            $a = rtrim((string) $a, '/');
            if ($a !== '' && str_starts_with($a, 'https://')) {
                $aliasRoots[$a] = true;
            }
        }
        $aliasRoots[$actorId] = true;
        foreach (array_keys($aliasRoots) as $a) {
            $del = $db->prepare(
                'DELETE FROM masto_account_actors
                 WHERE (actor_id = ? OR actor_id = ?) AND account_id != ?'
            );
            $del->execute([$a, $a . '/', $accountId]);
        }
        $st = $db->prepare(
            'INSERT INTO masto_account_actors (account_id, actor_id, updated_at) VALUES (?, ?, ?)
             ON CONFLICT(account_id) DO UPDATE SET actor_id = excluded.actor_id, updated_at = excluded.updated_at'
        );
        $st->execute([$accountId, $actorId, ap_db_now()]);
    } catch (Throwable $e) {
        // table may not exist yet on first request before migrate; UNIQUE(actor_id) races
        try {
            $st = ap_db()->prepare(
                'DELETE FROM masto_account_actors WHERE actor_id = ? OR actor_id = ? OR account_id = ?'
            );
            $st->execute([$actorId, $actorId . '/', $accountId]);
            $st = ap_db()->prepare(
                'INSERT INTO masto_account_actors (account_id, actor_id, updated_at) VALUES (?, ?, ?)'
            );
            $st->execute([$accountId, $actorId, ap_db_now()]);
        } catch (Throwable $e2) {
            // ignore
        }
    }
}

/**
 * Same person, different actor IRIs (common on Mastodon: /users/name vs /ap/users/snowflake).
 * Ordered with the IRI that has the most local Create events first.
 *
 * @return list<string>
 */
function ap_masto_actor_id_aliases(string $actorId): array
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return $actorId !== '' ? [$actorId] : [];
    }
    static $memo = [];
    if (isset($memo[$actorId])) {
        return $memo[$actorId];
    }
    $host = strtolower((string) (parse_url($actorId, PHP_URL_HOST) ?? ''));
    if ($host === '') {
        return $memo[$actorId] = [$actorId, $actorId . '/'];
    }

    $pathUname = '';
    if (preg_match('#/(?:users|ap/users)/([^/]+)/?$#i', $actorId, $m)) {
        $pathUname = rawurldecode($m[1]);
    }

    $uname = '';
    try {
        $st = ap_db()->prepare('SELECT username, host FROM remote_actors WHERE actor_id = ? OR actor_id = ? LIMIT 1');
        $st->execute([$actorId, $actorId . '/']);
        $row = $st->fetch();
        if (is_array($row)) {
            $uname = trim((string) ($row['username'] ?? ''));
            $h = strtolower(trim((string) ($row['host'] ?? '')));
            if ($h !== '') {
                $host = $h;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    if ($uname === '' && $pathUname !== '' && !ctype_digit($pathUname)) {
        $uname = $pathUname;
    }

    $candidates = [$actorId];
    if ($uname !== '') {
        $candidates[] = 'https://' . $host . '/users/' . rawurlencode($uname);
        $candidates[] = 'https://' . $host . '/@' . rawurlencode($uname);
        try {
            $st = ap_db()->prepare(
                'SELECT actor_id FROM remote_actors
                 WHERE lower(COALESCE(host,\'\')) = ? AND lower(COALESCE(username,\'\')) = ?'
            );
            $st->execute([$host, mb_strtolower($uname)]);
            foreach ($st->fetchAll() ?: [] as $r) {
                $aid = rtrim((string) ($r['actor_id'] ?? ''), '/');
                if ($aid !== '') {
                    $candidates[] = $aid;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $st = ap_db()->prepare(
                'SELECT actor_id FROM following WHERE lower(COALESCE(host,\'\')) = ?'
            );
            $st->execute([$host]);
            $want = mb_strtolower($uname);
            foreach ($st->fetchAll() ?: [] as $r) {
                $aid = rtrim((string) ($r['actor_id'] ?? ''), '/');
                if ($aid === '') {
                    continue;
                }
                if (preg_match('#/(?:users|@)([^/]+)/?$#i', $aid, $mm)
                    && mb_strtolower(rawurldecode($mm[1])) === $want) {
                    $candidates[] = $aid;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    $scored = [];
    foreach ($candidates as $a) {
        $a = rtrim($a, '/');
        if ($a !== '' && str_starts_with($a, 'https://')) {
            $scored[$a] = 0;
        }
    }
    if (count($scored) > 1) {
        try {
            $ors = [];
            $bind = [];
            foreach (array_keys($scored) as $a) {
                $ors[] = 'actor_id = ? OR actor_id = ?';
                $bind[] = $a;
                $bind[] = $a . '/';
            }
            $st = ap_db()->prepare(
                'SELECT rtrim(actor_id, \'/\') AS aid, COUNT(*) AS c FROM events
                 WHERE type = \'Create\' AND (' . implode(' OR ', $ors) . ')
                 GROUP BY rtrim(actor_id, \'/\')'
            );
            $st->execute($bind);
            foreach ($st->fetchAll() ?: [] as $r) {
                $aid = rtrim((string) ($r['aid'] ?? ''), '/');
                if (isset($scored[$aid])) {
                    $scored[$aid] = (int) ($r['c'] ?? 0);
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        arsort($scored, SORT_NUMERIC);
    }

    $ordered = [];
    foreach (array_keys($scored) as $a) {
        $ordered[] = $a;
        $ordered[] = $a . '/';
    }
    $ordered = array_values(array_unique($ordered));
    // Memo under every alias root so /users/name and /ap/users/id share one result.
    foreach ($ordered as $a) {
        $root = rtrim($a, '/');
        if ($root !== '') {
            $memo[$root] = $ordered;
        }
    }
    return $memo[$actorId] = $ordered;
}

/** Resolve a Mastodon account id back to an actor URL (best-effort scan). */
function ap_masto_actor_id_from_account_id(string $accountId): ?string
{
    $local = function_exists('ap_masto_local_user_by_account_id')
        ? ap_masto_local_user_by_account_id($accountId)
        : null;
    if (is_array($local)) {
        $aid = rtrim((string) ($local['actor_id'] ?? ''), '/');
        if ($aid !== '') {
            return $aid;
        }
        $key = strtolower(trim((string) ($local['actor_key'] ?? '')));
        if ($key !== '') {
            return 'https://mkultra.monster/users/' . $key;
        }
    }
    try {
        $st = ap_db()->prepare('SELECT actor_id FROM masto_account_actors WHERE account_id = ?');
        $st->execute([$accountId]);
        $row = $st->fetch();
        if (is_array($row) && !empty($row['actor_id'])) {
            $aid = rtrim((string) $row['actor_id'], '/');
            // Prefer the alias that actually holds Creates (Mastodon /users/name vs /ap/users/id)
            $aliases = ap_masto_actor_id_aliases($aid);
            return $aliases[0] ?? $aid;
        }
    } catch (Throwable $e) {
        // fall through
    }
    $candidates = [];
    foreach (ap_db()->query('SELECT actor_id FROM remote_actors')->fetchAll() as $r) {
        $candidates[] = (string) $r['actor_id'];
    }
    foreach (ap_following_list() as $r) {
        $candidates[] = (string) ($r['actor_id'] ?? '');
    }
    foreach (ap_followers_list() as $r) {
        $candidates[] = (string) ($r['actor_id'] ?? '');
    }
    foreach (ap_dm_conversations(100) as $c) {
        $candidates[] = (string) $c['peer_actor_id'];
    }
    // Recent event actors (federated faces)
    foreach (ap_db()->query("SELECT DISTINCT actor_id FROM events WHERE actor_id IS NOT NULL ORDER BY id DESC LIMIT 800")->fetchAll() as $r) {
        $candidates[] = (string) $r['actor_id'];
    }
    $seen = [];
    foreach ($candidates as $a) {
        $a = rtrim(trim($a), '/');
        if ($a === '' || isset($seen[$a])) {
            continue;
        }
        $seen[$a] = true;
        if (ap_masto_remote_account_id($a) === $accountId) {
            ap_masto_account_actor_remember($accountId, $a);
            return $a;
        }
    }
    return null;
}

/**
 * Guess an actor URL from a status/object URL (for reply mention metadata).
 */
function ap_masto_actor_url_from_object_url(string $objectUrl): ?string
{
    $objectUrl = rtrim(trim($objectUrl), '/');
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        return null;
    }
    if (preg_match('#^(https://[^/]+)/users/([^/]+)/(?:statuses|notes|objects)/#i', $objectUrl, $m)) {
        return $m[1] . '/users/' . rawurlencode(rawurldecode($m[2]));
    }
    if (preg_match('#^(https://[^/]+)/@([^/]+)/(?:statuses/)?[A-Za-z0-9_-]+#i', $objectUrl, $m)) {
        return $m[1] . '/users/' . rawurlencode(rawurldecode($m[2]));
    }
    // GoToSocial / some Akkoma: /ap/users/{id}/statuses/{id}
    if (preg_match('#^(https://[^/]+)/ap/users/([^/]+)/(?:statuses|notes)/#i', $objectUrl, $m)) {
        return $m[1] . '/ap/users/' . rawurlencode(rawurldecode($m[2]));
    }
    // Pleroma-style /objects/… — cannot guess actor from URL alone
    if (preg_match('#^(https://[^/]+)/notice/[A-Za-z0-9_-]+#i', $objectUrl, $m)) {
        return null;
    }
    if (preg_match('#^(https://[^/]+)/notes/[A-Za-z0-9_-]+#i', $objectUrl, $m)) {
        // Misskey-style note without username in path — unknown actor
        return null;
    }
    return null;
}

/**
 * Resolve a Bluesky-style handle (alice.bsky.social / custom.domain) to a Bridgy
 * ActivityPub actor URL via remote_actors cache. Does not invent fake AP URLs.
 */
function ap_masto_resolve_bsky_handle_actor(string $handle): ?string
{
    $handle = strtolower(ltrim(trim($handle), '@'));
    if ($handle === '' || !str_contains($handle, '.') || str_ends_with($handle, '.brid.gy')) {
        return null;
    }
    try {
        $st = ap_db()->prepare(
            "SELECT actor_id FROM remote_actors
             WHERE lower(username) = ?
               AND (host LIKE '%.brid.gy' OR host = 'brid.gy' OR actor_id LIKE 'https://bsky.brid.gy/%')
             ORDER BY updated_at DESC LIMIT 1"
        );
        $st->execute([$handle]);
        $aid = rtrim((string) ($st->fetchColumn() ?: ''), '/');
        if ($aid !== '' && str_starts_with($aid, 'https://')) {
            return $aid;
        }
        // Also accept any cached actor with that preferredUsername (non-Bridgy rare)
        $st2 = ap_db()->prepare(
            'SELECT actor_id FROM remote_actors WHERE lower(username) = ? ORDER BY updated_at DESC LIMIT 1'
        );
        $st2->execute([$handle]);
        $aid2 = rtrim((string) ($st2->fetchColumn() ?: ''), '/');
        if ($aid2 !== '' && str_starts_with($aid2, 'https://')) {
            return $aid2;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

/**
 * Mastodon Mention entity for an actor URL.
 *
 * @return array{id:string,username:string,url:string,acct:string}
 */
function ap_masto_mention_from_actor(string $actorId): array
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === 'https://mkultra.monster/users/cmdr_nova'
        || str_starts_with($actorId, 'https://mkultra.monster/users/cmdr_nova/')) {
        return [
            'id' => '1',
            'username' => 'cmdr_nova',
            'url' => 'https://mkultra.monster/users/cmdr_nova',
            'acct' => 'cmdr_nova',
        ];
    }
    $acct = ap_masto_remote_account($actorId);
    // Prefer ActivityPub actor IRI for mention.url (VAAK remote_profile / follow).
    // account.url may be a Bluesky web profile for Bridgy — that must not replace the AP id.
    $mentionUrl = (string) ($acct['uri'] ?? $actorId);
    if ($mentionUrl === '' || !str_starts_with($mentionUrl, 'https://')) {
        $mentionUrl = $actorId !== '' ? $actorId : (string) ($acct['url'] ?? '');
    }
    return [
        'id' => (string) ($acct['id'] ?? ap_masto_remote_account_id($actorId)),
        'username' => (string) ($acct['username'] ?? 'user'),
        'url' => $mentionUrl,
        'acct' => (string) ($acct['acct'] ?? 'user'),
    ];
}

/**
 * Mastodon Tag entity URL for Ice Cubes / clients (opens /api/v1/timelines/tag/:name).
 */
function ap_masto_tag_url(string $name): string
{
    $name = function_exists('ap_masto_normalize_tag_name')
        ? ap_masto_normalize_tag_name($name)
        : mb_strtolower(ltrim(trim($name), '#'));
    return 'https://mkultra.monster/tags/' . rawurlencode($name);
}

/**
 * Linkify #hashtags in already-escaped plain text (no HTML tags yet).
 * Returns [escapedHtmlFragment, tags[]].
 *
 * @return array{0:string,1:list<array{name:string,url:string}>}
 */
function ap_masto_linkify_hashtags_escaped(string $escaped): array
{
    /** @var array<string,array{name:string,url:string}> $byName */
    $byName = [];
    $out = preg_replace_callback(
        '/(^|[^A-Za-z0-9_&\/%])#([\p{L}\p{N}_]{1,100})/u',
        static function (array $m) use (&$byName): string {
            $raw = $m[2];
            $norm = function_exists('ap_masto_normalize_tag_name')
                ? ap_masto_normalize_tag_name($raw)
                : mb_strtolower($raw);
            if ($norm === '') {
                return $m[0];
            }
            $url = ap_masto_tag_url($norm);
            $byName[$norm] = ['name' => $norm, 'url' => $url];
            // Mastodon-shaped markup so Ice Cubes treats it as a tag, not a normal link
            $link = '<a href="' . htmlspecialchars($url, ENT_QUOTES, 'UTF-8')
                . '" class="mention hashtag" rel="tag">#<span>'
                . htmlspecialchars($raw, ENT_QUOTES, 'UTF-8') . '</span></a>';
            return $m[1] . $link;
        },
        $escaped
    );
    return [is_string($out) ? $out : $escaped, array_values($byName)];
}

/**
 * Linkify #hashtags inside existing HTML (protects <a>…</a>). Also returns tags[].
 *
 * @return array{html:string,tags:list<array{name:string,url:string}>}
 */
function ap_masto_linkify_hashtags_in_html(string $html): array
{
    if ($html === '' || !str_contains($html, '#')) {
        return ['html' => $html, 'tags' => []];
    }
    // Already has Mastodon hashtag links — just harvest tags[] from them / plain text
    if (str_contains($html, 'class="mention hashtag"') || str_contains($html, "class='mention hashtag'")) {
        $tags = [];
        if (preg_match_all('#https://mkultra\.monster/tags/([^"\s]+)#i', $html, $tm)) {
            foreach ($tm[1] as $t) {
                $n = ap_masto_normalize_tag_name(rawurldecode((string) $t));
                if ($n !== '') {
                    $tags[$n] = ['name' => $n, 'url' => ap_masto_tag_url($n)];
                }
            }
        }
        if (!$tags) {
            $plain = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if (preg_match_all('/#([\p{L}\p{N}_]{1,100})/u', $plain, $pm)) {
                foreach ($pm[1] as $t) {
                    $n = ap_masto_normalize_tag_name((string) $t);
                    if ($n !== '') {
                        $tags[$n] = ['name' => $n, 'url' => ap_masto_tag_url($n)];
                    }
                }
            }
        }
        return ['html' => $html, 'tags' => array_values($tags)];
    }

    $placeholders = [];
    $protected = preg_replace_callback(
        '#<a\b[^>]*>.*?</a>#isu',
        static function (array $m) use (&$placeholders): string {
            $key = "\x01A" . count($placeholders) . "\x01";
            $placeholders[$key] = $m[0];
            return $key;
        },
        $html
    );
    if (!is_string($protected)) {
        $protected = $html;
    }
    // Also protect bare URLs so #fragments aren't treated as tags
    $protected = preg_replace_callback(
        '#https?://[^\s<]+#iu',
        static function (array $m) use (&$placeholders): string {
            $key = "\x01U" . count($placeholders) . "\x01";
            $placeholders[$key] = $m[0];
            return $key;
        },
        $protected
    ) ?? $protected;

    [$linked, $tags] = ap_masto_linkify_hashtags_escaped($protected);
    if ($placeholders) {
        $keys = array_keys($placeholders);
        usort($keys, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($keys as $key) {
            $linked = str_replace($key, $placeholders[$key], $linked);
        }
    }
    return ['html' => $linked, 'tags' => $tags];
}

/**
 * Build Mastodon-style HTML content + mentions[] + tags[] (Ice Cubes needs these for
 * clickable @handles / #hashtags and “replying to @user”).
 *
 * @param list<string> $extraActorIds Actor URLs to always include (e.g. reply parent author)
 * @return array{content:string,mentions:list<array{id:string,username:string,url:string,acct:string}>,tags:list<array{name:string,url:string}>}
 */
/**
 * Repair corrupted "@#tag@host" plain text produced when hashtag links were
 * mistaken for user mentions during HTML→plain conversion.
 */
function ap_masto_repair_at_hash_tags(string $text): string
{
    if ($text === '' || !str_contains($text, '@#')) {
        return $text;
    }
    // @#gaming@mastodon.social → #gaming
    $text = preg_replace('/@#([\p{L}\p{N}_]+)@(?:[a-z0-9.-]+\.[a-z]{2,})/iu', '#$1', $text) ?? $text;
    // @#gaming → #gaming
    $text = preg_replace('/@#([\p{L}\p{N}_]+)/u', '#$1', $text) ?? $text;
    return $text;
}

function ap_masto_content_with_mentions(string $plainText, array $extraActorIds = []): array
{
    $plainText = html_entity_decode($plainText, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if (function_exists('ap_normalize_bridgy_plain_mentions')) {
        $plainText = ap_normalize_bridgy_plain_mentions($plainText);
    }
    $plainText = ap_masto_repair_at_hash_tags($plainText);
    /** @var array<string,array{id:string,username:string,url:string,acct:string}> $byAcct */
    $byAcct = [];

    foreach ($extraActorIds as $actorId) {
        if (!is_string($actorId) || $actorId === '') {
            continue;
        }
        $m = ap_masto_mention_from_actor($actorId);
        $key = strtolower($m['acct']);
        $byAcct[$key] = $m;
    }

    // Heal legacy unglue damage: "@user@infosec.exchang e" → "@user@infosec.exchange"
    // (old regex treated trailing TLD letters as a glued word).
    for ($i = 0; $i < 8; $i++) {
        $healed = preg_replace(
            '/(@[\w]+@(?:[a-z0-9-]+\.)+[a-z]{2,})\s([a-z])(?=\s|@|$|[^\w])/u',
            '$1$2',
            $plainText
        );
        if (!is_string($healed) || $healed === $plainText) {
            break;
        }
        $plainText = $healed;
    }

    // Unglue "@userHello" using known reply-parent usernames before parsing
    $knownUsers = [];
    foreach ($byAcct as $m) {
        $knownUsers[] = (string) $m['username'];
    }
    if (function_exists('ap_plain_unglue_mentions')) {
        $plainText = ap_plain_unglue_mentions($plainText, $knownUsers);
    }
    // Wafrn glues the next word onto @user@mkultra.monster — split before host parse.
    $plainText = ap_masto_unglue_host_mentions($plainText);
    // Opportunistic split of glued bare @handles via known actors ("@ElizafoxIf")
    $plainText = ap_masto_unglue_bare_handles($plainText);

    // @user@host in the body
    if (preg_match_all('/(^|[^A-Za-z0-9_])@([A-Za-z0-9_]+)@([a-z0-9.-]+\.[a-z]{2,})/u', $plainText, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $hit) {
            $user = $hit[2];
            $host = strtolower($hit[3]);
            $acctFull = $user . '@' . $host;
            $acctKey = strtolower($acctFull);
            if (isset($byAcct[$acctKey])) {
                continue;
            }
            $actor = 'https://' . $host . '/users/' . rawurlencode($user);
            $resolved = ap_masto_mention_from_actor($actor);
            // Keep the written @user@host as acct so linkify wraps the whole
            // handle. Local actors otherwise collapse to bare username and leave
            // "@mkultra.monster" sitting outside the link.
            $resolved['acct'] = $acctFull;
            $resolved['username'] = $user;
            $byAcct[$acctKey] = $resolved;
        }
    }

    // Bluesky-style @handle.domain (Bridgy / ATProto)
    if (preg_match_all('/(^|[^A-Za-z0-9_@])@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,})(?![A-Za-z0-9.@])/u', $plainText, $bb, PREG_SET_ORDER)) {
        foreach ($bb as $hit) {
            $handle = strtolower($hit[2]);
            if (isset($byAcct[$handle])) {
                continue;
            }
            $covered = false;
            foreach ($byAcct as $m) {
                if (strtolower((string) $m['username']) === $handle || strtolower((string) $m['acct']) === $handle) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            $actor = ap_masto_resolve_bsky_handle_actor($handle);
            if ($actor !== null) {
                $byAcct[$handle] = ap_masto_mention_from_actor($actor);
            }
        }
    }

    // Bare @user at start / after whitespace — resolve via known actors or recent events
    if (preg_match_all('/(^|[^A-Za-z0-9_@])@([A-Za-z0-9_]{2,32})(?![A-Za-z0-9_@])/u', $plainText, $bm, PREG_SET_ORDER)) {
        foreach ($bm as $hit) {
            $user = $hit[2];
            $userKey = strtolower($user);
            // Already covered as user@host?
            $covered = false;
            foreach ($byAcct as $m) {
                if (strtolower((string) $m['username']) === $userKey) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            $resolved = ap_masto_resolve_bare_username($user);
            if ($resolved !== null) {
                $byAcct[strtolower($resolved['acct'])] = $resolved;
            }
        }
    }

    $mentions = array_values($byAcct);

    // Escape then re-inject mention links
    $escaped = htmlspecialchars($plainText, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($mentions) {
        // Longer accts first so @user@host beats partials
        usort($mentions, static fn ($a, $b) => strlen($b['acct']) <=> strlen($a['acct']));
        // Pass 1: full @user@host only — never bare str_replace (nests inside full handles).
        foreach ($mentions as $m) {
            $acct = (string) $m['acct'];
            if (!str_contains($acct, '@')) {
                continue;
            }
            $user = (string) $m['username'];
            $url = htmlspecialchars((string) $m['url'], ENT_QUOTES, 'UTF-8');
            $needleFull = '@' . htmlspecialchars($acct, ENT_QUOTES, 'UTF-8');
            $acctParts = explode('@', $acct, 2);
            $labelUser = htmlspecialchars((string) ($acctParts[0] !== '' ? $acctParts[0] : $user), ENT_QUOTES, 'UTF-8');
            $labelHost = htmlspecialchars((string) ($acctParts[1] ?? ''), ENT_QUOTES, 'UTF-8');
            $link = '<span class="h-card"><a href="' . $url . '" class="u-url mention">@<span>'
                . $labelUser . '</span>@<span>' . $labelHost . '</span></a></span>';
            $escaped = str_replace($needleFull, $link, $escaped);
        }

        // Protect already-built mention cards / hrefs so bare @user replace
        // cannot nest HTML inside https://host/@user URLs (Ice Cubes then shows raw tags).
        $placeholders = [];
        $escaped = preg_replace_callback(
            '#<span class="h-card">.*?</span>#su',
            static function (array $m) use (&$placeholders): string {
                $key = "\x01M" . count($placeholders) . "\x01";
                $placeholders[$key] = $m[0];
                return $key;
            },
            $escaped
        ) ?? $escaped;

        // Bare @user when it matches a known mention username uniquely
        $byUser = [];
        foreach ($mentions as $m) {
            $u = strtolower((string) $m['username']);
            if (!isset($byUser[$u])) {
                $byUser[$u] = $m;
            } elseif (is_array($byUser[$u]) && str_contains((string) $byUser[$u]['acct'], '@') && !str_contains((string) $m['acct'], '@')) {
                $byUser[$u] = $m;
            } elseif (is_array($byUser[$u]) && (string) $byUser[$u]['url'] !== (string) $m['url']) {
                $byUser[$u] = false; // ambiguous
            }
        }
        foreach ($byUser as $u => $m) {
            if (!is_array($m)) {
                continue;
            }
            $url = htmlspecialchars((string) $m['url'], ENT_QUOTES, 'UTF-8');
            $uname = htmlspecialchars((string) $m['username'], ENT_QUOTES, 'UTF-8');
            $link = '<span class="h-card"><a href="' . $url . '" class="u-url mention">@<span>'
                . $uname . '</span></a></span>';
            // Don't match @user inside URLs (…/@user), emails, or @user@host
            $escaped = preg_replace(
                '/(^|[^A-Za-z0-9_\/])@' . preg_quote($uname, '/') . '(?![A-Za-z0-9_.@])/u',
                '$1' . $link,
                $escaped
            ) ?? $escaped;
        }

        if ($placeholders) {
            $escaped = str_replace(array_keys($placeholders), array_values($placeholders), $escaped);
        }
    }

    // Protect URLs so #fragments aren't hashtags, then linkify #tags for Ice Cubes
    $urlHold = [];
    $escaped = preg_replace_callback(
        '#https?://[^\s<]+#iu',
        static function (array $m) use (&$urlHold): string {
            $key = "\x01U" . count($urlHold) . "\x01";
            $urlHold[$key] = $m[0];
            return $key;
        },
        $escaped
    ) ?? $escaped;
    [$escaped, $tags] = ap_masto_linkify_hashtags_escaped($escaped);
    if ($urlHold) {
        $escaped = str_replace(array_keys($urlHold), array_values($urlHold), $escaped);
    }

    // Mastodon-style: blank lines → separate <p>; single newlines → <br>.
    // Never wrap the whole post in one <p> with <br><br> — remotes render that as huge gaps.
    $escaped = str_replace(["\r\n", "\r"], "\n", $escaped);
    $parts = preg_split("/\n{2,}/", $escaped);
    if (!is_array($parts) || $parts === []) {
        $parts = [$escaped];
    }
    $blocks = [];
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $blocks[] = '<p>' . nl2br($part, false) . '</p>';
    }
    $html = $blocks !== [] ? implode("\n", $blocks) : '<p></p>';
    if (function_exists('ap_normalize_status_html')) {
        $html = ap_normalize_status_html($html);
    }

    return [
        'content' => $html,
        'mentions' => array_values($byAcct),
        'tags' => $tags,
    ];
}

/**
 * Split glued bare handles when a known username is a prefix ("@ElizafoxIf" → "@Elizafox If").
 * Won't touch real camelCase usernames that resolve as a whole ("@CactuarJoe").
 */
function ap_masto_unglue_bare_handles(string $text): string
{
    if ($text === '' || !str_contains($text, '@')) {
        return $text;
    }
    // Only bare @user tokens — never touch @user@host (negative lookahead for @).
    // Without that, "@aliceamour@beige.party" was split into "@alice amour@…" whenever
    // a shorter bare username like "alice" existed in the actor cache (aceofcosmicspace posts).
    return preg_replace_callback(
        '/(^|[^\w@])@([A-Za-z][\w]{1,40})(?![@\w])/u',
        static function (array $m): string {
            $token = $m[2];
            // Real camelCase / multi-cap handles that exist as-is
            if (ap_masto_resolve_bare_username($token) !== null) {
                return $m[0];
            }
            // Longest prefix that resolves, with a glued remainder
            $len = mb_strlen($token);
            for ($n = $len - 1; $n >= 2; $n--) {
                $prefix = mb_substr($token, 0, $n);
                $rest = mb_substr($token, $n);
                if ($rest === '' || !preg_match('/^[\p{L}\p{N}]/u', $rest)) {
                    continue;
                }
                // Don't invent splits that leave a host-looking remainder ("amour@beige.party")
                if (str_contains($rest, '@')) {
                    continue;
                }
                if (ap_masto_resolve_bare_username($prefix) !== null) {
                    return $m[1] . '@' . $prefix . ' ' . $rest;
                }
            }
            return $m[0];
        },
        $text
    ) ?? $text;
}

/**
 * Wafrn (and some other writers) glue the next word onto a full @user@host
 * mention: "@cmdr_nova@mkultra.monsterone more" / "@user@hostaudio posts".
 * Split known hosts back out so linkify can wrap the whole real handle.
 *
 * @param list<string> $extraHosts Optional extra hosts to protect (lowercase).
 */
function ap_masto_unglue_host_mentions(string $text, array $extraHosts = []): string
{
    if ($text === '' || !str_contains($text, '@')) {
        return $text;
    }
    $hosts = [];
    foreach ($extraHosts as $h) {
        $h = strtolower(trim((string) $h));
        if ($h !== '' && str_contains($h, '.')) {
            $hosts[$h] = true;
        }
    }
    $actorId = '';
    if (function_exists('ap_masto_session_actor_id')) {
        $actorId = (string) ap_masto_session_actor_id();
    }
    if ($actorId === '' && !empty($GLOBALS['vaak_actor_id'])) {
        $actorId = (string) $GLOBALS['vaak_actor_id'];
    }
    if ($actorId === '' && function_exists('ap_db_cmdr_nova_user_id') && function_exists('ap_db_owner_actor_id_for_user_id')) {
        $actorId = (string) ap_db_owner_actor_id_for_user_id(ap_db_cmdr_nova_user_id());
    }
    $localHost = strtolower((string) (parse_url($actorId, PHP_URL_HOST) ?? ''));
    if ($localHost !== '') {
        $hosts[$localHost] = true;
    }
    // Always protect this instance host even if session actor is unset.
    $hosts['mkultra.monster'] = true;

    // Longest hosts first so mkultra.monster beats mkultra.mon
    $hostList = array_keys($hosts);
    usort($hostList, static fn ($a, $b) => strlen($b) <=> strlen($a));
    foreach ($hostList as $host) {
        $text = preg_replace(
            '/(^|[^A-Za-z0-9_])(@[A-Za-z0-9_]+@' . preg_quote($host, '/') . ')([\p{L}\p{N}][\p{L}\p{N}_-]*)(?=[\s[:punct:]]|$)/u',
            '$1$2 $3',
            $text
        ) ?? $text;
    }
    return $text;
}

/**
 * Best-effort resolve @user (no host) to a Mention via reply context / recent actors.
 *
 * @return array{id:string,username:string,url:string,acct:string}|null
 */
function ap_masto_resolve_bare_username(string $username): ?array
{
    static $cache = [];
    $username = trim($username);
    if ($username === '') {
        return null;
    }
    $needle = strtolower($username);
    if (array_key_exists($needle, $cache)) {
        return $cache[$needle];
    }
    if ($needle === 'cmdr_nova') {
        return $cache[$needle] = ap_masto_mention_from_actor('https://mkultra.monster/users/cmdr_nova');
    }
    try {
        // Prefer remote_actors exact username (indexed). Never LIKE-scan events —
        // that was a multi-second seq scan on every unknown @bare handle in timelines.
        $st = ap_db()->prepare(
            'SELECT actor_id FROM remote_actors WHERE lower(username) = ? ORDER BY updated_at DESC LIMIT 3'
        );
        $st->execute([$needle]);
        $rows = $st->fetchAll();
        if (count($rows) >= 1) {
            // If multiple hosts share the username, still use the most recently updated
            return $cache[$needle] = ap_masto_mention_from_actor((string) $rows[0]['actor_id']);
        }
        // Local instance account
        if (preg_match('/^[A-Za-z0-9_]{2,32}$/', $username)
            && function_exists('ap_auth_user_by_username')) {
            $local = ap_auth_user_by_username($username);
            if (is_array($local) && !empty($local['actor_id'])) {
                return $cache[$needle] = ap_masto_mention_from_actor((string) $local['actor_id']);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $cache[$needle] = null;
}

function ap_masto_account_by_id(string $accountId): ?array
{
    if ($accountId === '1') {
        // Stable id for cmdr_nova — not "whoever is logged into VAAK"
        try {
            $st = ap_db()->prepare(
                "SELECT * FROM ap_users WHERE actor_key = 'cmdr_nova' AND disabled_at IS NULL LIMIT 1"
            );
            $st->execute();
            $row = $st->fetch();
            if (is_array($row)) {
                return ap_masto_account_from_user($row);
            }
        } catch (Throwable $e) {
            // fall through
        }
        $prevKey = $GLOBALS['vaak_actor_key'] ?? null;
        $GLOBALS['vaak_actor_key'] = 'cmdr_nova';
        $GLOBALS['vaak_actor_id'] = 'https://mkultra.monster/users/cmdr_nova';
        $acct = ap_masto_account();
        if ($prevKey === null) {
            unset($GLOBALS['vaak_actor_key'], $GLOBALS['vaak_actor_id']);
        } else {
            $GLOBALS['vaak_actor_key'] = $prevKey;
        }
        return $acct;
    }
    // Local VAAK user by ap_users.id
    if (ctype_digit($accountId) && (int) $accountId > 1) {
        try {
            $st = ap_db()->prepare(
                'SELECT * FROM ap_users WHERE id = ? AND disabled_at IS NULL LIMIT 1'
            );
            $st->execute([(int) $accountId]);
            $row = $st->fetch();
            if (is_array($row)) {
                return ap_masto_account_from_user($row);
            }
        } catch (Throwable $e) {
            // fall through to remote
        }
    }
    $actor = ap_masto_actor_id_from_account_id($accountId);
    if ($actor === null) {
        return null;
    }
    if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', rtrim($actor, '/'), $m)) {
        try {
            $st = ap_db()->prepare(
                'SELECT * FROM ap_users WHERE lower(actor_key) = ? AND disabled_at IS NULL LIMIT 1'
            );
            $st->execute([strtolower($m[1])]);
            $row = $st->fetch();
            if (is_array($row)) {
                return ap_masto_account_from_user($row);
            }
        } catch (Throwable $e) {
            // fall through
        }
    }
    return ap_masto_remote_account($actor);
}

/**
 * Fetch a remote Note (if missing) and cache it as an events row for threading.
 *
 * @return array<string,mixed>|null events row
 */
function ap_masto_ensure_remote_note_event(string $objectUrl): ?array
{
    $objectUrl = rtrim(trim($objectUrl), '/');
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        return null;
    }
    // Never treat local notes as remote events
    if (ap_masto_local_note_actor_id($objectUrl) !== null
        || preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+/#', $objectUrl)) {
        return null;
    }
    $existing = function_exists('ap_event_by_object_id') ? ap_event_by_object_id($objectUrl) : null;
    $existingType = is_array($existing) ? strtolower((string) ($existing['type'] ?? '')) : '';
    $isNoteRow = in_array($existingType, ['create', 'update'], true);
    $needsReplyBackfill = $isNoteRow && empty($existing['in_reply_to']);
    // Announce/Like stubs are not enough — fetch the real Note when missing.
    if ($isNoteRow && !$needsReplyBackfill) {
        return $existing;
    }

    if (!function_exists('ap_fetch_as2_object')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    if (!function_exists('ap_fetch_as2_object') || !function_exists('ap_metrics_record')) {
        return null;
    }

    $doc = ap_fetch_as2_object($objectUrl);
    if (!is_array($doc)) {
        return null;
    }
    if (($doc['type'] ?? '') === 'Create' && isset($doc['object']) && is_array($doc['object'])) {
        $doc = $doc['object'];
    }
    $type = (string) ($doc['type'] ?? 'Note');
    if (!in_array($type, ['Note', 'Article', 'Page', 'Question', 'Video', 'Image', 'Audio'], true)) {
        // Still try if it has content / attachments
        if (empty($doc['content']) && empty($doc['attachment']) && empty($doc['summary'])) {
            return null;
        }
    }

    $actorId = null;
    if (function_exists('ap_as_id')) {
        $actorId = ap_as_id($doc['attributedTo'] ?? null) ?: ap_as_id($doc['actor'] ?? null);
    }
    if (!is_string($actorId) || !str_starts_with($actorId, 'https://')) {
        return null;
    }
    if (function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($actorId)) {
        return null;
    }

    $summary = null;
    if (!empty($doc['content']) && is_string($doc['content'])) {
        $summary = $doc['content'];
    } elseif (!empty($doc['summary']) && is_string($doc['summary'])) {
        $summary = $doc['summary'];
    } elseif (!empty($doc['name']) && is_string($doc['name'])) {
        $summary = $doc['name'];
    }
    if (($summary === null || trim(strip_tags((string) $summary)) === '')) {
        $summary = '(attachment)';
    }

    $media = function_exists('ap_extract_media_urls') ? ap_extract_media_urls($doc) : [];
    $inReplyTo = function_exists('ap_as_id') ? ap_as_id($doc['inReplyTo'] ?? null) : null;
    if (is_string($inReplyTo) && str_starts_with($inReplyTo, 'https://')) {
        $inReplyTo = rtrim($inReplyTo, '/');
    } else {
        $inReplyTo = null;
    }
    $published = (!empty($doc['published']) && is_string($doc['published'])) ? $doc['published'] : null;
    $oid = function_exists('ap_as_id') ? (ap_as_id($doc['id'] ?? null) ?: $objectUrl) : $objectUrl;
    $oid = rtrim((string) $oid, '/');

    ap_metrics_record(
        'Create',
        $actorId,
        $oid,
        null,
        0,
        'local_observe',
        is_string($summary) ? $summary : null,
        is_array($media) ? $media : null,
        $published,
        $inReplyTo
    );

    return function_exists('ap_event_by_object_id') ? ap_event_by_object_id($oid) : null;
}

/**
 * Map an ActivityPub object URL to a Mastodon status id (+ account id) when we have it locally.
 *
 * @return array{status_id:string,account_id:?string}|null
 */
function ap_masto_resolve_object_url_to_status_ref(string $objectUrl): ?array
{
    $objectUrl = rtrim(trim($objectUrl), '/');
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        return null;
    }

    $local = ap_masto_status_by_note_id($objectUrl);
    if (is_array($local)) {
        return [
            'status_id' => ap_masto_snowflake_id(
                (string) ($local['published'] ?? gmdate('c')),
                (int) $local['local_id'],
                0
            ),
            'account_id' => '1',
        ];
    }

    if (function_exists('ap_event_by_object_id')) {
        $erow = ap_event_by_object_id($objectUrl);
        if (is_array($erow)) {
            $eid = (int) ($erow['id'] ?? 0);
            if ($eid > 0) {
                $actor = (string) ($erow['actor_id'] ?? '');
                return [
                    'status_id' => ap_masto_event_status_id(
                        $eid,
                        isset($erow['created_at']) ? (string) $erow['created_at'] : null
                    ),
                    'account_id' => $actor !== '' ? ap_masto_remote_account_id($actor) : null,
                ];
            }
        }
    }

    $st = ap_db()->prepare(
        'SELECT id, created_at, actor_id FROM mentions WHERE (object_id = ? OR object_id = ?) AND deleted_at IS NULL LIMIT 1'
    );
    $st->execute([$objectUrl, $objectUrl . '/']);
    $mrow = $st->fetch();
    if (is_array($mrow)) {
        $actor = (string) ($mrow['actor_id'] ?? '');
        return [
            'status_id' => ap_masto_mention_status_id(
                (int) $mrow['id'],
                isset($mrow['created_at']) ? (string) $mrow['created_at'] : null
            ),
            'account_id' => $actor !== '' ? ap_masto_remote_account_id($actor) : null,
        ];
    }

    return null;
}

/**
 * Status context for Ice Cubes threads / DM views.
 *
 * @return array{ancestors:list,descendants:list}
 */
/**
 * @param array{allow_fetch?:bool,max_ancestor_depth?:int} $opts
 *   allow_fetch: live AS2 parent fetches (default true for Mastodon API)
 *   max_ancestor_depth: walk depth (default 20)
 * @return array{ancestors:list<array<string,mixed>>,descendants:list<array<string,mixed>>}
 */
function ap_masto_status_context(int $statusId, array $opts = []): array
{
    $allowFetch = !array_key_exists('allow_fetch', $opts) || !empty($opts['allow_fetch']);
    $maxDepth = isset($opts['max_ancestor_depth']) ? max(0, (int) $opts['max_ancestor_depth']) : 20;
    $ancestors = [];
    $descendants = [];

    // DM thread
    $dmId = ap_masto_dm_id_from_status_id($statusId);
    if ($dmId !== null) {
        $dm = ap_dm_by_id($dmId);
        if ($dm) {
            $peer = (string) ($dm['peer_actor_id'] ?? '');
            // Opening a DM in Ice Cubes loads context — treat as read
            if ($peer !== '' && function_exists('ap_dm_mark_peer_read')) {
                try {
                    ap_dm_mark_peer_read($peer);
                } catch (Throwable $e) {
                    error_log('[ap-masto] dm mark read (context): ' . $e->getMessage());
                }
            }
            $thread = ap_dm_thread($peer, 200);
            $before = true;
            foreach ($thread as $row) {
                $sid = (int) ap_masto_dm_status_id((int) $row['id'], isset($row['created_at']) ? (string) $row['created_at'] : null);
                if ($sid === $statusId) {
                    $before = false;
                    continue;
                }
                $ent = ap_masto_status_from_dm($row);
                if ($before) {
                    $ancestors[] = $ent;
                } else {
                    $descendants[] = $ent;
                }
            }
        }
        return ['ancestors' => $ancestors, 'descendants' => $descendants];
    }

    // Local public reply chain (best-effort via in_reply_to_local_id)
    $row = ap_masto_local_row_from_public_id($statusId)
        ?? (($statusId < 2000000) ? ap_masto_status_by_local_id($statusId) : null);
    if ($row) {
        $localId = (int) ($row['local_id'] ?? 0);
        $noteId = rtrim((string) ($row['note_id'] ?? ''), '/');
        $cur = $row;
        $guard = 0;
        while (!empty($cur['in_reply_to_local_id']) && $guard++ < $maxDepth) {
            $parent = ap_masto_status_by_local_id((int) $cur['in_reply_to_local_id']);
            if (!$parent) {
                break;
            }
            array_unshift($ancestors, ap_masto_status_from_row($parent));
            $cur = $parent;
        }
        // Remote parent (we replied to someone else's Note)
        $remoteParentUrl = '';
        if ($noteId !== '') {
            try {
                $ob = ap_db()->prepare('SELECT in_reply_to FROM outbox_notes WHERE id = ?');
                $ob->execute([$noteId]);
                $obRow = $ob->fetch();
                if (is_array($obRow) && !empty($obRow['in_reply_to'])) {
                    $remoteParentUrl = rtrim((string) $obRow['in_reply_to'], '/');
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
        if ($remoteParentUrl !== '' && str_starts_with($remoteParentUrl, 'https://')
            && ap_masto_local_note_actor_id($remoteParentUrl) === null
            && !preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+/#', $remoteParentUrl)) {
            $ancestors = array_merge(
                ap_masto_context_ancestors_from_object_url($remoteParentUrl, $maxDepth, $allowFetch),
                $ancestors
            );
        }
        if ($localId > 0) {
            $st = ap_db()->prepare('SELECT * FROM masto_statuses WHERE in_reply_to_local_id = ? ORDER BY local_id ASC LIMIT 50');
            $st->execute([$localId]);
            foreach ($st->fetchAll() as $child) {
                $descendants[] = ap_masto_status_from_row($child);
            }
        }
        if ($noteId !== '') {
            foreach (ap_masto_mention_replies_to_object($noteId, 80) as $ment) {
                $descendants[] = $ment;
            }
        }
        return [
            'ancestors' => $ancestors,
            'descendants' => ap_masto_context_dedupe_statuses($descendants),
        ];
    }

    // Mention statuses (inbound)
    $mentionId = ap_masto_mention_id_from_status_id($statusId);
    if ($mentionId !== null) {
        $st = ap_db()->prepare('SELECT * FROM mentions WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$mentionId]);
        $mrow = $st->fetch();
        if (is_array($mrow)) {
            $parentUrl = rtrim((string) ($mrow['in_reply_to'] ?? ''), '/');
            if ($parentUrl !== '' && str_starts_with($parentUrl, 'https://')) {
                $ancestors = ap_masto_context_ancestors_from_object_url($parentUrl, $maxDepth, $allowFetch);
            }
            $objectUrl = rtrim((string) ($mrow['object_id'] ?? ''), '/');
            if ($objectUrl !== '') {
                $descendants = array_merge(
                    $descendants,
                    ap_masto_context_descendants_for_object_url($objectUrl)
                );
                foreach (ap_masto_mention_replies_to_object($objectUrl, 40) as $ment) {
                    $descendants[] = $ment;
                }
            }
            return [
                'ancestors' => $ancestors,
                'descendants' => ap_masto_context_dedupe_statuses($descendants),
            ];
        }
    }

    // Remote firehose event (Home / Federated) — walk in_reply_to URLs (fetch missing parents)
    $eventId = ap_masto_event_id_from_status_id($statusId);
    if ($eventId !== null) {
        $st = ap_db()->prepare('SELECT * FROM events WHERE id = ?');
        $st->execute([$eventId]);
        $erow = $st->fetch();
        if (is_array($erow)) {
            $parentUrl = rtrim((string) ($erow['in_reply_to'] ?? ''), '/');
            if ($parentUrl !== '' && str_starts_with($parentUrl, 'https://')) {
                // Ensure immediate parent is cached before walking (Ice Cubes loads status+context in parallel)
                if (
                    $allowFetch
                    && function_exists('ap_event_by_object_id')
                    && !is_array(ap_event_by_object_id($parentUrl))
                ) {
                    ap_masto_ensure_remote_note_event($parentUrl);
                }
                $ancestors = ap_masto_context_ancestors_from_object_url($parentUrl, $maxDepth, $allowFetch);
            }
            $objectUrl = rtrim((string) ($erow['object_id'] ?? ''), '/');
            if ($objectUrl !== '') {
                $descendants = ap_masto_context_descendants_for_object_url($objectUrl);
                foreach (ap_masto_mention_replies_to_object($objectUrl, 40) as $ment) {
                    $descendants[] = $ment;
                }
            }
        }
    }

    return [
        'ancestors' => $ancestors,
        'descendants' => ap_masto_context_dedupe_statuses($descendants),
    ];
}

/**
 * Walk parent chain for a Note URL, optionally fetching+caching missing remote parents.
 *
 * @return list<array<string,mixed>>
 */
function ap_masto_context_ancestors_from_object_url(string $objectUrl, int $maxDepth = 20, bool $allowFetch = true): array
{
    $ancestors = [];
    $curUrl = rtrim($objectUrl, '/');
    $seenUrl = [];
    $depth = 0;
    while ($curUrl !== '' && str_starts_with($curUrl, 'https://') && $depth++ < $maxDepth) {
        if (isset($seenUrl[$curUrl])) {
            break;
        }
        $seenUrl[$curUrl] = true;

        // Local note
        $local = ap_masto_status_by_note_id($curUrl);
        if (is_array($local)) {
            array_unshift($ancestors, ap_masto_status_from_row($local));
            // Continue via outbox in_reply_to if present
            try {
                $ob = ap_db()->prepare('SELECT in_reply_to FROM outbox_notes WHERE id = ?');
                $ob->execute([(string) ($local['note_id'] ?? $curUrl)]);
                $obRow = $ob->fetch();
                $next = is_array($obRow) ? rtrim((string) ($obRow['in_reply_to'] ?? ''), '/') : '';
                $curUrl = ($next !== '' && str_starts_with($next, 'https://')) ? $next : '';
            } catch (Throwable $e) {
                $curUrl = '';
            }
            continue;
        }

        $event = function_exists('ap_event_by_object_id') ? ap_event_by_object_id($curUrl) : null;
        if (!is_array($event) && $allowFetch) {
            $event = ap_masto_ensure_remote_note_event($curUrl);
        }
        if (!is_array($event)) {
            // Mention fallback
            $st = ap_db()->prepare(
                'SELECT * FROM mentions WHERE (object_id = ? OR object_id = ?) AND deleted_at IS NULL LIMIT 1'
            );
            $st->execute([$curUrl, $curUrl . '/']);
            $mrow = $st->fetch();
            if (is_array($mrow)) {
                array_unshift($ancestors, ap_masto_status_from_mention($mrow));
                $next = rtrim((string) ($mrow['in_reply_to'] ?? ''), '/');
                $curUrl = ($next !== '' && str_starts_with($next, 'https://')) ? $next : '';
                continue;
            }
            break;
        }

        $event['_allow_empty_for_context'] = true;
        $ent = ap_masto_status_from_event($event);
        if (is_array($ent)) {
            if (!empty($ent['reblog']) && is_array($ent['reblog'])) {
                array_unshift($ancestors, $ent['reblog']);
            } else {
                array_unshift($ancestors, $ent);
            }
        }
        $next = rtrim((string) ($event['in_reply_to'] ?? ''), '/');
        // If cached event lacks in_reply_to, try live doc once
        if ($next === '' && $allowFetch) {
            $fetched = ap_masto_ensure_remote_note_event($curUrl);
            if (is_array($fetched) && !empty($fetched['in_reply_to'])) {
                $next = rtrim((string) $fetched['in_reply_to'], '/');
            }
        }
        $curUrl = ($next !== '' && str_starts_with($next, 'https://')) ? $next : '';
    }
    return $ancestors;
}

/**
 * Best-effort Mastodon status snowflake from a common object URL.
 * e.g. https://host/users/name/statuses/123 → "123"
 */
function ap_masto_remote_status_id_from_object_url(string $objectUrl): ?string
{
    $objectUrl = rtrim(trim($objectUrl), '/');
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        return null;
    }
    if (preg_match('#/statuses/(\d+)(?:/|$)#', $objectUrl, $m)) {
        return $m[1];
    }
    if (preg_match('#/@[^/]+/(\d+)(?:/|$)#', $objectUrl, $m)) {
        return $m[1];
    }
    return null;
}

/**
 * Fetch remote Mastodon-compatible /api/v1/statuses/:id/context and cache Notes.
 * Returns URIs of ancestors + descendants discovered (may be empty on failure).
 *
 * Caps how many Notes we sync-ensure so mega-threads (years of replies) cannot
 * blank the Status page / PHP-FPM worker.
 *
 * @return array{ancestors:list<string>,descendants:list<string>}|null
 */
/**
 * @param array{ensure_cap?:int,poll?:bool,timeout_sec?:int} $opts
 */
function ap_masto_fetch_remote_context_uris(string $objectUrl, int $ensureCap = 40, array $opts = []): ?array
{
    $objectUrl = rtrim(trim($objectUrl), '/');
    $sid = ap_masto_remote_status_id_from_object_url($objectUrl);
    $host = strtolower((string) (parse_url($objectUrl, PHP_URL_HOST) ?: ''));
    if ($sid === null || $host === '' || $host === 'mkultra.monster') {
        return null;
    }
    $poll = !empty($opts['poll']);
    if (isset($opts['ensure_cap'])) {
        $ensureCap = (int) $opts['ensure_cap'];
    }
    // Live polls: tiny ensure budget so leaving the thread doesn't pin a PHP worker.
    $ensureCap = $poll
        ? max(0, min(8, $ensureCap > 0 ? $ensureCap : 5))
        : max(5, min(120, $ensureCap));
    $timeoutSec = isset($opts['timeout_sec'])
        ? max(2, min(15, (int) $opts['timeout_sec']))
        : ($poll ? 4 : 8);
    $ctxUrl = 'https://' . $host . '/api/v1/statuses/' . rawurlencode($sid) . '/context';
    if (!function_exists('ap_unsigned_get')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    if (!function_exists('ap_unsigned_get')) {
        return null;
    }
    // Drop work early if the browser navigated away (Home click, etc.).
    if (function_exists('ignore_user_abort')) {
        ignore_user_abort(false);
    }
    if (connection_aborted()) {
        return null;
    }
    $body = ap_unsigned_get($ctxUrl, $timeoutSec);
    if (!is_string($body) || $body === '') {
        return null;
    }
    if (connection_aborted()) {
        return null;
    }
    $data = json_decode($body, true);
    if (!is_array($data)) {
        return null;
    }
    $out = ['ancestors' => [], 'descendants' => []];
    $ensured = 0;
    foreach (['ancestors', 'descendants'] as $bucket) {
        $list = $data[$bucket] ?? null;
        if (!is_array($list)) {
            continue;
        }
        foreach ($list as $st) {
            if (connection_aborted()) {
                break 2;
            }
            if (!is_array($st)) {
                continue;
            }
            // Unwrap reblogs to the original Note URI
            if (isset($st['reblog']) && is_array($st['reblog'])) {
                $st = $st['reblog'];
            }
            $uri = '';
            foreach (['uri', 'url', 'id'] as $field) {
                $v = isset($st[$field]) ? rtrim((string) $st[$field], '/') : '';
                if ($v !== '' && str_starts_with($v, 'https://')) {
                    $uri = $v;
                    break;
                }
            }
            if ($uri === '') {
                continue;
            }
            $out[$bucket][] = $uri;
            if ($ensured >= $ensureCap || !function_exists('ap_masto_ensure_remote_note_event')) {
                continue;
            }
            // Poll: only fetch Notes we do not already have (skip cache hits / backfills).
            if ($poll && function_exists('ap_event_by_object_id')) {
                $ex = ap_event_by_object_id($uri);
                $exType = is_array($ex) ? strtolower((string) ($ex['type'] ?? '')) : '';
                if (in_array($exType, ['create', 'update'], true)) {
                    continue;
                }
            }
            ap_masto_ensure_remote_note_event($uri);
            $ensured++;
        }
    }
    $out['ancestors'] = array_values(array_unique($out['ancestors']));
    $out['descendants'] = array_values(array_unique($out['descendants']));
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function ap_masto_context_descendants_for_object_url(string $objectUrl): array
{
    $objectUrl = rtrim($objectUrl, '/');
    $descendants = [];
    if ($objectUrl === '') {
        return $descendants;
    }
    $dst = ap_db()->prepare(
        "SELECT * FROM events
         WHERE type = 'Create'
           AND (in_reply_to = ? OR in_reply_to = ?)
         ORDER BY id ASC
         LIMIT 80"
    );
    $dst->execute([$objectUrl, $objectUrl . '/']);
    $childUrls = [];
    foreach ($dst->fetchAll() as $child) {
        $child['_allow_empty_for_context'] = true;
        $cent = ap_masto_status_from_event($child);
        if (!is_array($cent)) {
            continue;
        }
        if (!empty($cent['reblog']) && is_array($cent['reblog'])) {
            $cent = $cent['reblog'];
        }
        $descendants[] = $cent;
        $cuid = rtrim((string) ($child['object_id'] ?? ''), '/');
        if ($cuid !== '') {
            $childUrls[] = $cuid;
        }
    }
    foreach ($childUrls as $cuid) {
        $nst = ap_db()->prepare(
            "SELECT * FROM events
             WHERE type = 'Create'
               AND (in_reply_to = ? OR in_reply_to = ?)
             ORDER BY id ASC
             LIMIT 40"
        );
        $nst->execute([$cuid, $cuid . '/']);
        foreach ($nst->fetchAll() as $nchild) {
            $nchild['_allow_empty_for_context'] = true;
            $nent = ap_masto_status_from_event($nchild);
            if (!is_array($nent)) {
                continue;
            }
            if (!empty($nent['reblog']) && is_array($nent['reblog'])) {
                $nent = $nent['reblog'];
            }
            $descendants[] = $nent;
        }
    }
    return $descendants;
}

/**
 * Drop duplicate statuses in a thread context (same uri/url/id).
 * Prefer the first occurrence (usually the richer local outbox card).
 *
 * @param list<array<string,mixed>> $statuses
 * @return list<array<string,mixed>>
 */
function ap_masto_context_dedupe_statuses(array $statuses): array
{
    $out = [];
    $seen = [];
    foreach ($statuses as $st) {
        if (!is_array($st)) {
            continue;
        }
        $keys = [];
        foreach (['uri', 'url', 'id'] as $field) {
            $v = isset($st[$field]) ? rtrim((string) $st[$field], '/') : '';
            if ($v !== '') {
                $keys[] = $v;
            }
        }
        $dup = false;
        foreach ($keys as $k) {
            if (isset($seen[$k])) {
                $dup = true;
                break;
            }
        }
        if ($dup) {
            continue;
        }
        foreach ($keys as $k) {
            $seen[$k] = true;
        }
        $out[] = $st;
    }
    return $out;
}

/**
 * Mentions (and nested replies) that reply to a given object URL — for blog comments / context.
 *
 * @return list<array<string,mixed>>
 */
function ap_masto_mention_replies_to_object(string $objectUrl, int $limit = 80): array
{
    $objectUrl = rtrim(trim($objectUrl), '/');
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        return [];
    }
    $limit = max(1, min(200, $limit));
    $ourPrefix = ap_masto_session_actor_id();

    $st = ap_db()->prepare(
        'SELECT * FROM mentions
         WHERE deleted_at IS NULL
           AND (in_reply_to = ? OR in_reply_to = ?)
           AND object_id IS NOT NULL
           AND object_id != ?
           AND object_id != ?
         ORDER BY id ASC
         LIMIT ' . (int) $limit
    );
    $st->execute([$objectUrl, $objectUrl . '/', $objectUrl, $objectUrl . '/']);
    $rows = $st->fetchAll();
    $out = [];
    $seenObject = [$objectUrl => true, $objectUrl . '/' => true];
    $queueUrls = [];

    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        // Skip our own loopback Creates — local outbox / masto_statuses already own the thread.
        $rowActor = rtrim((string) ($row['actor_id'] ?? ''), '/');
        if ($rowActor === $ourPrefix) {
            continue;
        }
        $oid = rtrim((string) ($row['object_id'] ?? ''), '/');
        if ($oid !== '' && str_starts_with($oid, $ourPrefix . '/notes/')) {
            continue;
        }
        $content = trim(html_entity_decode(strip_tags((string) ($row['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $hasMedia = !empty($row['media_urls']) && $row['media_urls'] !== '[]';
        if ($content === '' && !$hasMedia) {
            continue; // Likes / empty stamps
        }
        if ($oid !== '') {
            $seenObject[$oid] = true;
            $seenObject[$oid . '/'] = true;
            $queueUrls[] = $oid;
        }
        $out[] = ap_masto_status_from_mention($row);
    }

    // One level of nested replies to those mention objects
    $nested = 0;
    foreach ($queueUrls as $parentUrl) {
        if ($nested >= $limit) {
            break;
        }
        $nst = ap_db()->prepare(
            'SELECT * FROM mentions
             WHERE deleted_at IS NULL
               AND (in_reply_to = ? OR in_reply_to = ?)
             ORDER BY id ASC
             LIMIT 40'
        );
        $nst->execute([$parentUrl, $parentUrl . '/']);
        foreach ($nst->fetchAll() as $nrow) {
            if (!is_array($nrow)) {
                continue;
            }
            $noid = rtrim((string) ($nrow['object_id'] ?? ''), '/');
            if ($noid === '' || isset($seenObject[$noid])) {
                continue;
            }
            $content = trim(html_entity_decode(strip_tags((string) ($nrow['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $hasMedia = !empty($nrow['media_urls']) && $nrow['media_urls'] !== '[]';
            if ($content === '' && !$hasMedia) {
                continue;
            }
            $seenObject[$noid] = true;
            $out[] = ap_masto_status_from_mention($nrow);
            $nested++;
            if (count($out) >= $limit) {
                break 2;
            }
        }
    }

    return $out;
}

/**
 * Resolve a blog/note page URL to its syndicated cmdr_nova Note + Mastodon status id.
 *
 * @return array{ok:bool,error?:string,url?:string,note_id?:string,status_id?:string,title?:string,kind?:string}|null
 */
function ap_masto_site_syndication_for_url(string $pageUrl): ?array
{
    $pageUrl = trim($pageUrl);
    if ($pageUrl === '' || !str_starts_with($pageUrl, 'https://mkultra.monster/')) {
        return null;
    }
    // Normalize trailing slash variants
    $candidates = array_values(array_unique([
        $pageUrl,
        rtrim($pageUrl, '/') . '/',
        rtrim($pageUrl, '/'),
    ]));
    $st = ap_db()->prepare('SELECT url, note_id, create_id, kind, title, published_at FROM site_syndications WHERE url = ?');
    $row = null;
    foreach ($candidates as $cand) {
        $st->execute([$cand]);
        $row = $st->fetch();
        if (is_array($row)) {
            break;
        }
    }
    if (!is_array($row)) {
        return null;
    }
    $noteId = (string) ($row['note_id'] ?? '');
    if ($noteId === '') {
        return null;
    }
    $local = ap_masto_status_by_note_id($noteId);
    $statusId = null;
    if (is_array($local)) {
        $statusId = ap_masto_snowflake_id(
            (string) ($local['published'] ?? gmdate('c')),
            (int) $local['local_id'],
            0
        );
    }
    return [
        'ok' => true,
        'url' => (string) $row['url'],
        'note_id' => $noteId,
        'status_id' => $statusId,
        'title' => (string) ($row['title'] ?? ''),
        'kind' => (string) ($row['kind'] ?? ''),
    ];
}

/**
 * Best-effort remote Account entity from an ActivityPub actor id.
 */
function ap_masto_remote_account(string $actorId, bool $allowFetch = false): array
{
    $actorId = rtrim(trim($actorId), '/');
    static $memo = [];
    $memoKey = $actorId . ':' . ($allowFetch ? '1' : '0');
    if (isset($memo[$memoKey])) {
        return $memo[$memoKey];
    }
    $fallback = defined('AP_REMOTE_AVATAR_FALLBACK')
        ? AP_REMOTE_AVATAR_FALLBACK
        : 'https://mkultra.monster/img/avatar/default.jpg';

    // Timelines must not sync-fetch AS2 (Ice Cubes hammers home/public/notifs together).
    // Cron/media-warm + follow Accept backfill keep remote_actors fresh.
    if (function_exists('ap_remote_actor_label')) {
        $label = ap_remote_actor_label($actorId, $allowFetch);
        $username = $label['username'];
        $display = $label['display_name'];
        $host = $label['host'] !== '' ? $label['host'] : 'unknown';
        $acct = $label['acct'];
    } else {
        $host = parse_url($actorId, PHP_URL_HOST);
        $host = is_string($host) ? strtolower($host) : 'unknown';
        $path = trim((string) (parse_url($actorId, PHP_URL_PATH) ?? ''), '/');
        $seg = $path !== '' ? (basename($path) ?: 'user') : 'user';
        $username = $seg;
        $display = $seg;
        $ra = function_exists('ap_remote_actor_get') ? ap_remote_actor_get($actorId) : null;
        if (is_array($ra)) {
            if (!empty($ra['username'])) {
                $username = (string) $ra['username'];
            }
            if (!empty($ra['display_name'])) {
                $display = (string) $ra['display_name'];
            } else {
                $display = $username;
            }
            if (!empty($ra['host'])) {
                $host = strtolower((string) $ra['host']);
            }
        }
        $acct = $host !== '' ? ($username . '@' . $host) : $username;
    }

    // Prefer R2-cached avatar/header; may sync-fetch once on cache miss.
    $avatar = $fallback;
    $header = $fallback;
    // Notification peers (Wafrn approvals, etc.) use a fixed local avatar.
    if (function_exists('ap_peer_avatar_override')) {
        $ov = ap_peer_avatar_override($actorId);
        if (is_string($ov) && $ov !== '') {
            $avatar = $ov;
            $header = $ov;
        }
    }
    if ($avatar === $fallback && function_exists('ap_remote_media_account_urls')) {
        try {
            $urls = ap_remote_media_account_urls($actorId);
            $avatar = $urls['avatar'] ?: $fallback;
            $header = $urls['header'] ?: $avatar;
        } catch (Throwable $e) {
            error_log('[ap-masto] remote media: ' . $e->getMessage());
        }
    }

    // Collapse Mastodon dual IRIs (/users/name vs /ap/users/snowflake) to one account id.
    $canonicalActor = $actorId;
    $aliases = ap_masto_actor_id_aliases($actorId);
    if (!empty($aliases[0])) {
        $canonicalActor = rtrim((string) $aliases[0], '/');
    }
    if ($canonicalActor !== $actorId && function_exists('ap_remote_media_account_urls')) {
        try {
            $urls = ap_remote_media_account_urls($canonicalActor);
            if (!empty($urls['avatar'])) {
                $avatar = $urls['avatar'];
            }
            if (!empty($urls['header'])) {
                $header = $urls['header'];
            } elseif ($avatar !== $fallback) {
                $header = $avatar;
            }
        } catch (Throwable $e) {
            // keep prior avatar/header
        }
    }
    $id = ap_masto_remote_account_id($actorId);
    ap_masto_account_actor_remember($id, $canonicalActor);
    // Prefer Mastodon web profile URL when we have a username (nicer in Ice Cubes / browsers).
    // Bridgy Bluesky actors must NOT use https://bsky.brid.gy/@alice.bsky.social (JSON/404) —
    // point at the real Bluesky profile instead. Threads /ap/users/{id} → threads.com/@user.
    $webUrl = $canonicalActor;
    $bridgyBsky = function_exists('ap_bridgy_bsky_profile_web_url')
        ? ap_bridgy_bsky_profile_web_url($canonicalActor, $username)
        : null;
    $threadsWeb = function_exists('ap_threads_profile_web_url')
        ? ap_threads_profile_web_url($canonicalActor, $username)
        : null;
    if (is_string($bridgyBsky) && $bridgyBsky !== '') {
        $webUrl = $bridgyBsky;
        // Display acct as the Bluesky handle (alice.bsky.social), not alice.bsky.social@bsky.brid.gy
        if ($username !== '' && str_contains($username, '.') && !str_starts_with(strtolower($username), 'did:')) {
            $acct = $username;
        }
    } elseif (is_string($threadsWeb) && $threadsWeb !== '') {
        $webUrl = $threadsWeb;
    } elseif ($host !== '' && $username !== '' && $username !== 'user'
        && !(function_exists('ap_remote_actor_username_is_placeholder') && ap_remote_actor_username_is_placeholder($username))) {
        $webUrl = 'https://' . $host . '/@' . rawurlencode($username);
    }
    $account = [
        'id' => $id,
        'username' => $username,
        'acct' => $acct,
        'display_name' => $display,
        'locked' => false,
        'bot' => false,
        'discoverable' => true,
        'group' => false,
        'created_at' => '2020-01-01T00:00:00.000Z',
        'note' => '',
        'url' => $webUrl,
        'uri' => $canonicalActor,
        'avatar' => $avatar,
        'avatar_static' => $avatar,
        'header' => $header,
        'header_static' => $header,
        'followers_count' => 0,
        'following_count' => 0,
        'statuses_count' => 0,
        'last_status_at' => null,
        'emojis' => [],
        'fields' => [],
    ];
    return $memo[$memoKey] = $account;
}

function ap_masto_format_time(?string $iso): string
{
    $iso = $iso ?: gmdate('c');
    try {
        return (new DateTimeImmutable($iso))->format('Y-m-d\TH:i:s.000\Z');
    } catch (Throwable $e) {
        return gmdate('Y-m-d\TH:i:s.000\Z');
    }
}

/** Synthetic status id for a remote mention row. */
function ap_masto_mention_status_id(int $mentionId, ?string $createdAt = null): string
{
    if ($createdAt === null || $createdAt === '') {
        return (string) (2000000 + $mentionId); // legacy fallback
    }
    return ap_masto_snowflake_id($createdAt, $mentionId, 2);
}

function ap_masto_mention_id_from_status_id(int $statusId): ?int
{
    $p = ap_masto_parse_public_status_id($statusId);
    if (!$p || $p['type'] !== 'mention') {
        return null;
    }
    if ($statusId >= 2000000 && $statusId < 3000000) {
        return $statusId - 2000000;
    }
    $ver = (int) ($p['ver'] ?? 1);
    if ($ver === 2) {
        return (int) $p['db_id'];
    }
    return ap_masto_disambiguate_mod_id(
        'mentions',
        (int) $p['db_id'],
        (int) ($p['ms'] ?? 0),
        'created_at',
        true
    );
}

/**
 * Strip noisy "RE: https://…" / glued URL prefixes from quote/reply bodies
 * so notifications and timelines show readable commentary only.
 *
 * Mastodon-compatible quotes often prefix the body with RE:<url> with the URL glued
 * directly onto the commentary (no space). Bridgy quote-inline does the same mid-body:
 *   "that's my mutualRE: https://bsky.app/profile/…/post/…"
 */
function ap_masto_clean_mention_text(string $text): string
{
    $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $text = trim(strip_tags($text));
    // Leading RE: <local-note-or-status-id>… (stop before following commentary word)
    $text = preg_replace(
        '#^RE:\s*https?://\S+?(?:/notes/[a-f0-9]+(?![a-f0-9])|/\d+(?!\d))#iu',
        '',
        $text
    ) ?? $text;
    // Bridgy / generic: strip any "RE: https://…" chunk (including glued to prior word)
    $text = preg_replace('#RE:\s*https?://\S+#iu', '', $text) ?? $text;
    // Any leading absolute URL (typically spaced); \S+ is fine after RE: strip above
    $text = preg_replace('#^https?://\S+#u', '', $text) ?? $text;
    // Drop leftover QT machine markers from enriched summaries (keep block for splitters)
    $text = preg_replace('#^↪ QT(?:\s+@\S+:)?\s*#u', '', $text) ?? $text;
    // Collapse whitespace left by mid-string RE: removal
    $text = preg_replace("/[ \t]+/u", ' ', $text) ?? $text;
    $text = preg_replace("/\n{3,}/u", "\n\n", $text) ?? $text;
    return trim($text);
}

/**
 * Status entity built from a mentions-table row (remote note / reply / mention).
 */
function ap_masto_status_from_mention(array $row): array
{
    $mentionId = (int) ($row['id'] ?? 0);
    $actorId = (string) ($row['actor_id'] ?? '');
    $account = $actorId !== '' ? ap_masto_remote_account($actorId) : ap_masto_account();
    $text = ap_masto_clean_mention_text((string) ($row['content'] ?? ''));
    $objectId = (string) ($row['object_id'] ?? '');
    // Strip interaction hash suffixes (#like-… / #reblog-… / #quote-…) for the public URL.
    $urlBase = ap_masto_mention_target_object_id($objectId);
    if (str_starts_with($urlBase, 'at://') && function_exists('ap_bsky_https_url_from_at_uri')) {
        $urlBase = ap_bsky_https_url_from_at_uri($urlBase, null);
    } elseif (str_starts_with($urlBase, 'bsky:') && function_exists('ap_bsky_https_url_from_at_uri')) {
        $urlBase = ap_bsky_https_url_from_at_uri(substr($urlBase, 5), null);
    }
    $url = $urlBase !== '' ? $urlBase : $actorId;
    $media = [];
    if (!empty($row['media_urls'])) {
        $decoded = json_decode((string) $row['media_urls'], true);
        if (is_array($decoded)) {
            $i = 0;
            foreach ($decoded as $u) {
                $clean = is_string($u) ? ap_profile_sanitize_https_url($u) : null;
                if ($clean === null) {
                    continue;
                }
                $i++;
                $media[] = [
                    'id' => (string) (2000000 + $mentionId) . $i,
                    'type' => 'image',
                    'url' => $clean,
                    'preview_url' => $clean,
                    'remote_url' => $clean,
                    'preview_remote_url' => null,
                    'text_url' => null,
                    'meta' => null,
                    'description' => null,
                    'blurhash' => null,
                ];
                if ($i >= 4) {
                    break;
                }
            }
        }
    }
    $inReplyTo = (string) ($row['in_reply_to'] ?? '');
    if ($inReplyTo === '' && is_string($row['content'] ?? null)) {
        // Some remotes omit inReplyTo but prefix body with RE: <local-note-url>
        if (preg_match('#https://mkultra\.monster/users/[A-Za-z0-9_]+/notes/[a-f0-9]+#i', (string) $row['content'], $mm)) {
            $inReplyTo = $mm[0];
        }
    }
    $replyPublicId = null;
    $replyAccountId = null;
    $replyParentActor = null;
    if ($inReplyTo !== '') {
        $inReplyTo = rtrim($inReplyTo, '/');
        $localParentActor = ap_masto_local_note_actor_id($inReplyTo);
        if ($localParentActor !== null) {
            $parent = ap_masto_status_by_note_id($inReplyTo);
            if ($parent) {
                $replyPublicId = ap_masto_snowflake_id(
                    (string) ($parent['published'] ?? gmdate('c')),
                    (int) $parent['local_id'],
                    0
                );
                $parentAcct = ap_masto_account_for_local_url($inReplyTo);
                $replyAccountId = (string) ($parentAcct['id'] ?? '');
                $replyParentActor = $localParentActor;
            }
        } else {
            $parentPack = ap_masto_resolve_object_url_to_status_ref($inReplyTo);
            if ($parentPack !== null) {
                $replyPublicId = $parentPack['status_id'];
                $replyAccountId = $parentPack['account_id'];
            } else {
                // Nested reply to another inbound mention
                $pst = ap_db()->prepare(
                    'SELECT id, created_at, actor_id FROM mentions WHERE (object_id = ? OR object_id = ?) AND deleted_at IS NULL LIMIT 1'
                );
                $pst->execute([$inReplyTo, $inReplyTo . '/']);
                $prow = $pst->fetch();
                if (is_array($prow)) {
                    $replyPublicId = ap_masto_mention_status_id(
                        (int) $prow['id'],
                        isset($prow['created_at']) ? (string) $prow['created_at'] : null
                    );
                    if (!empty($prow['actor_id'])) {
                        $replyParentActor = rtrim((string) $prow['actor_id'], '/');
                        $replyAccountId = ap_masto_remote_account_id($replyParentActor);
                    }
                }
            }
            if ($replyParentActor === null) {
                $replyParentActor = ap_masto_actor_url_from_object_url($inReplyTo);
                if ($replyAccountId === null && $replyParentActor !== null) {
                    $replyAccountId = ap_masto_remote_account_id($replyParentActor);
                }
            }
        }
    }
    $extraActors = [];
    if (is_string($replyParentActor) && $replyParentActor !== '') {
        $extraActors[] = $replyParentActor;
    }
    // Session actor for "@you" mention parsing (not hard-coded cmdr_nova)
    $extraActors[] = ap_masto_session_actor_id();
    $pack = ap_masto_content_with_mentions($text, $extraActors);
    if ($replyAccountId !== null && $replyParentActor !== null && ap_masto_is_local_actor_url($replyParentActor)) {
        $parentAcct = ap_masto_account_for_local_url($replyParentActor);
        $localMention = [
            'id' => (string) ($parentAcct['id'] ?? $replyAccountId),
            'username' => (string) ($parentAcct['username'] ?? ''),
            'url' => (string) ($parentAcct['url'] ?? $replyParentActor),
            'acct' => (string) ($parentAcct['acct'] ?? ''),
        ];
        if ($localMention['username'] === '' && preg_match('#/users/([A-Za-z0-9_]+)$#', $replyParentActor, $um)) {
            $localMention['username'] = $um[1];
            $localMention['acct'] = $um[1];
        }
        $has = false;
        foreach ($pack['mentions'] as $m) {
            if ((string) ($m['id'] ?? '') === (string) $localMention['id']) {
                $has = true;
                break;
            }
        }
        if (!$has) {
            array_unshift($pack['mentions'], $localMention);
        }
    } elseif ($replyAccountId !== null && $replyParentActor) {
        $has = false;
        foreach ($pack['mentions'] as $m) {
            if ((string) ($m['id'] ?? '') === (string) $replyAccountId) {
                $has = true;
                break;
            }
        }
        if (!$has) {
            array_unshift($pack['mentions'], ap_masto_mention_from_actor($replyParentActor));
        }
    }
    $spoilerText = trim((string) ($row['spoiler_text'] ?? ''));
    $isSensitive = !empty($row['sensitive']) || $spoilerText !== '';
    $status = [
        'id' => ap_masto_mention_status_id($mentionId, isset($row['created_at']) ? (string) $row['created_at'] : null),
        'created_at' => ap_masto_format_time(isset($row['created_at']) ? (string) $row['created_at'] : null),
        'in_reply_to_id' => $replyPublicId,
        'in_reply_to_account_id' => $replyAccountId,
        'sensitive' => $isSensitive,
        'spoiler_text' => $spoilerText,
        'visibility' => 'public',
        'language' => 'en',
        'uri' => $url,
        'url' => $url,
        'replies_count' => 0,
        'reblogs_count' => 0,
        'favourites_count' => 0,
        'edited_at' => null,
        'favourited' => false,
        'reblogged' => false,
        'muted' => false,
        'bookmarked' => false,
        'pinned' => false,
        'content' => $pack['content'],
        'reblog' => null,
        'application' => null,
        'account' => $account,
        'media_attachments' => $media,
        'mentions' => $pack['mentions'],
        'tags' => $pack['tags'] ?? [],
        'emojis' => [],
        'card' => null,
        'poll' => null,
    ];
    return ap_masto_apply_interaction_flags($status);
}

/**
 * Map a mentions row to Mastodon notification type, or null if not notifiable.
 */
/** Strip #like-… / #reblog-… / #update-… / #quote-… suffixes used to uniquify mention object_ids. */
function ap_masto_mention_target_object_id(string $objectId): string
{
    $objectId = rtrim($objectId, '/');
    if (preg_match('/^(https:\/\/.+?)#(like|reblog|update|quote|bite)-[a-z0-9]+$/i', $objectId, $m)) {
        return rtrim($m[1], '/');
    }
    return $objectId;
}

/**
 * True when a URL looks like a remote/local status/post permalink (not a bare profile).
 */
function ap_masto_url_looks_like_status(string $url): bool
{
    $url = rtrim(trim($url), '/');
    if ($url === '' || !str_starts_with($url, 'https://')) {
        return false;
    }
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    if ($path === '' || $path === '/') {
        return false;
    }
    // Profiles only: /@user or /users/user — not a post
    if (preg_match('#^/(@|users/)[^/]+/?$#i', $path)) {
        return false;
    }
    return (bool) preg_match(
        '#/(?:statuses|notes|objects|notice|post|posts|p)/|/@(?:[^/]+)/[A-Za-z0-9_-]+#i',
        $path
    );
}

/**
 * Resolve a pasted post URL to a canonical object id (fetch AS2 when needed).
 * Accepts Mastodon web forms like https://host/@user/123456789.
 */
function ap_masto_resolve_pasted_status_url(string $url): ?string
{
    $url = ap_masto_mention_target_object_id(trim($url));
    if ($url === '' || !str_starts_with($url, 'https://') || !ap_masto_url_looks_like_status($url)) {
        return null;
    }
    // Already in local store?
    if (function_exists('ap_event_by_object_id')) {
        $ex = ap_event_by_object_id($url);
        if (is_array($ex) && !empty($ex['object_id'])) {
            return rtrim((string) $ex['object_id'], '/');
        }
    }
    if (function_exists('ap_masto_status_by_note_id')) {
        $local = ap_masto_status_by_note_id($url);
        if (is_array($local) && !empty($local['note_id'])) {
            return rtrim((string) $local['note_id'], '/');
        }
    }
    // Live fetch → prefer Note id from AS2
    if (!function_exists('ap_fetch_as2_object')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    if (function_exists('ap_fetch_as2_object')) {
        $doc = ap_fetch_as2_object($url);
        if (is_array($doc)) {
            if (($doc['type'] ?? '') === 'Create' && isset($doc['object']) && is_array($doc['object'])) {
                $doc = $doc['object'];
            }
            $oid = '';
            if (function_exists('ap_as_id')) {
                $oid = (string) (ap_as_id($doc['id'] ?? null) ?: '');
            } elseif (!empty($doc['id']) && is_string($doc['id'])) {
                $oid = $doc['id'];
            }
            $oid = rtrim($oid, '/');
            if ($oid !== '' && str_starts_with($oid, 'https://')) {
                // Cache into events for status view
                if (function_exists('ap_masto_ensure_remote_note_event')) {
                    ap_masto_ensure_remote_note_event($oid);
                }
                return $oid;
            }
        }
    }
    // Last resort: try ensure with the pasted URL as-is
    if (function_exists('ap_masto_ensure_remote_note_event')) {
        $ev = ap_masto_ensure_remote_note_event($url);
        if (is_array($ev) && !empty($ev['object_id'])) {
            return rtrim((string) $ev['object_id'], '/');
        }
    }
    return $url;
}

/**
 * Resolve a local post that was liked/boosted (notes URL, statuses URL, or outbox id).
 *
 * @return array<string,mixed>|null Mastodon Status entity
 */
function ap_masto_resolve_our_liked_status(string $objectId): ?array
{
    $objectId = ap_masto_mention_target_object_id($objectId);
    if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
        return null;
    }

    // Direct note_id / object URL hit
    $local = ap_masto_status_by_note_id($objectId);
    if (is_array($local)) {
        return ap_masto_status_from_row($local);
    }
    $looked = ap_masto_lookup_status_by_object_url($objectId, 0);
    if (is_array($looked)) {
        $lookedAcctUrl = rtrim((string) ($looked['account']['url'] ?? $looked['account']['uri'] ?? ''), '/');
        $lookedAcctId = (string) ($looked['account']['id'] ?? '');
        $isLocalLooked = ($lookedAcctId !== '' && function_exists('ap_masto_local_user_by_account_id')
                && ap_masto_local_user_by_account_id($lookedAcctId) !== null)
            || ($lookedAcctUrl !== '' && ap_masto_is_local_actor_url($lookedAcctUrl))
            || ap_masto_local_note_actor_id($objectId) !== null;
        if ($isLocalLooked) {
            return $looked;
        }
    }

    // Local /users/{key}/statuses/{id} (Mastodon-shaped) → try public status id
    if (preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+/statuses/(\d+)/?$#', $objectId, $m)) {
        $sid = (int) $m[1];
        $row = ap_masto_local_row_from_public_id($sid) ?? (($sid < 2000000) ? ap_masto_status_by_local_id($sid) : null);
        if (is_array($row)) {
            return ap_masto_status_from_row($row);
        }
        // Snowflake / legacy public id via interaction resolver
        if (function_exists('ap_masto_resolve_status_interaction')) {
            $pack = ap_masto_resolve_status_interaction($sid);
            if ($pack !== null && !empty($pack['is_ours']) && is_array($pack['status'])) {
                return $pack['status'];
            }
        }
    }

    // Outbox note by exact id
    try {
        $st = ap_db()->prepare('SELECT * FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
        $st->execute([$objectId, $objectId . '/']);
        $ob = $st->fetch();
        if (is_array($ob) && function_exists('ap_masto_status_from_as2_note')) {
            $raw = (string) ($ob['raw_create_json'] ?? '');
            $decoded = $raw !== '' ? json_decode($raw, true) : null;
            $note = is_array($decoded) ? ($decoded['object'] ?? $decoded) : null;
            if (is_array($note)) {
                $status = ap_masto_status_from_as2_note($note, $objectId);
                if (is_array($status)) {
                    return $status;
                }
            }
            // Plain outbox row → ensure masto_statuses then re-read
            if (function_exists('ap_masto_backfill_outbox_statuses')) {
                ap_masto_backfill_outbox_statuses();
            }
            $local2 = ap_masto_status_by_note_id($objectId);
            if (is_array($local2)) {
                return ap_masto_status_from_row($local2);
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    return null;
}

/** True when mention content/object indicates a quote of one of our notes. */
function ap_masto_mention_is_quote_of_ours(array $row): bool
{
    $activity = strtolower((string) ($row['activity_type'] ?? ''));
    $objType = strtolower((string) ($row['type'] ?? ''));
    if ($activity === 'quote' || $activity === 'quotepost' || $objType === 'quote' || $objType === 'quotepost') {
        return true;
    }
    $content = trim(html_entity_decode(strip_tags((string) ($row['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    // Enrichment / GTS quote posts often prefix with RE: <our note url>
    // (Do NOT treat a bare link to our note in free text as a quote.)
    if (preg_match('#^RE:\s*https://mkultra\.monster/users/cmdr_nova/notes/[a-f0-9]+#i', $content)
        || str_contains($content, '↪ QT')) {
        return true;
    }
    $oid = (string) ($row['object_id'] ?? '');
    if (str_contains($oid, '#quote-')) {
        return true;
    }
    return false;
}

/** True when a mentions row was ingested from a connected Bluesky account. */
function ap_masto_mention_is_bluesky(array $row): bool
{
    $activityId = (string) ($row['activity_id'] ?? '');
    $actorId = rtrim((string) ($row['actor_id'] ?? ''), '/');
    $objectId = (string) ($row['object_id'] ?? '');
    if (str_starts_with($activityId, 'at://')) {
        return true;
    }
    if (str_starts_with($actorId, 'https://bsky.app/')) {
        return true;
    }
    if (str_starts_with($objectId, 'https://bsky.app/') || str_starts_with($objectId, 'bsky:')) {
        return true;
    }
    return false;
}

function ap_masto_mention_notif_type(array $row): ?string
{
    $activity = strtolower((string) ($row['activity_type'] ?? ''));
    $objType = strtolower((string) ($row['type'] ?? ''));
    $objectId = ap_masto_mention_target_object_id((string) ($row['object_id'] ?? ''));
    $ownerActor = rtrim((string) ($row['owner_actor_id'] ?? ''), '/');
    if ($ownerActor !== '') {
        $ourPrefix = $ownerActor;
    } else {
        $ourPrefix = ap_masto_session_actor_id();
    }
    $ourNote = str_starts_with($objectId, $ourPrefix . '/notes/')
        || str_starts_with($objectId, $ourPrefix . '/statuses/');
    $actorId = rtrim((string) ($row['actor_id'] ?? ''), '/');
    $isSelf = $actorId === $ourPrefix;

    // Never surface our own posts/replies as notifications (self-thread loopback).
    if ($isSelf) {
        return null;
    }

    // Bluesky-connected ingest: map by activity_type without requiring local AP note URLs.
    if (ap_masto_mention_is_bluesky($row)) {
        if ($activity === 'like' || $objType === 'like') {
            return 'favourite';
        }
        if ($activity === 'announce' || $objType === 'announce') {
            return 'reblog';
        }
        if ($activity === 'quote' || $activity === 'quotepost' || $objType === 'quote' || $objType === 'quotepost'
            || str_contains((string) ($row['object_id'] ?? ''), '#quote-')
            || str_contains((string) ($row['content'] ?? ''), '↪ QT')) {
            return 'quote';
        }
        // mention / reply Creates
        if ($activity === 'create' || $activity === 'mention' || $objType === 'note' || ($row['content'] ?? '') !== '') {
            return 'mention';
        }
        return null;
    }

    if ($activity === 'like' || $objType === 'like' || $activity === 'emojireact') {
        return 'favourite';
    }
    if ($activity === 'bite' || $objType === 'bite') {
        return 'bite';
    }
    if ($activity === 'announce' || $objType === 'announce') {
        // Boost of our post → reblog; boost of something else that tagged us → mention-ish
        return $ourNote ? 'reblog' : 'mention';
    }
    // Quote-boost of our post (Create with quote→our note, or Quote/QuotePost activity)
    if (ap_masto_mention_is_quote_of_ours($row)) {
        return 'quote';
    }
    // Author edited a post we favourited (never our own edits).
    // PeerTube/etc. federate frequent Video Updates (live metadata, timestamps)
    // that are not real “edits” — only Note-like objects count.
    if ($activity === 'update') {
        if ($ourNote) {
            return null;
        }
        if (!in_array($objType, ['note', 'article', 'page', 'question', ''], true)) {
            return null;
        }
        // Video watch URLs even if type was stored oddly
        if (str_contains($objectId, '/videos/') || str_contains($objectId, '/w/')) {
            return null;
        }
        if (function_exists('ap_masto_favourite_by_object_id') && ap_masto_favourite_by_object_id($objectId)) {
            return 'update';
        }
        return null;
    }
    if (in_array($objType, ['person', 'application', 'service', 'group'], true)) {
        return null;
    }
    // Create Note / Article / reply / bare content
    if (in_array($objType, ['note', 'article', 'page', 'question', ''], true) || ($row['content'] ?? '') !== '') {
        $inReplyTo = rtrim((string) ($row['in_reply_to'] ?? ''), '/');
        if ($inReplyTo !== '' && str_starts_with($inReplyTo, $ourPrefix . '/notes/')) {
            return 'mention';
        }
        $content = (string) ($row['content'] ?? '');
        if ($content !== '' && ap_content_addresses_local_actor($content, $ourPrefix)) {
            return 'mention';
        }
        $ownerUserId = (int) ($row['owner_user_id'] ?? 0);
        if ($ownerUserId < 1 && $ourPrefix !== '' && function_exists('ap_db_owner_user_id_for_actor')) {
            $ownerUserId = ap_db_owner_user_id_for_actor($ourPrefix);
        }
        if (
            $actorId !== ''
            && function_exists('ap_post_subscription_is')
            && ap_post_subscription_is($actorId, $ownerUserId > 0 ? $ownerUserId : null)
        ) {
            return 'status';
        }
        return null;
    }
    return null;
}

/**
 * Time-ordered notification ids (Mastodon snowflake shape).
 * Ice Cubes re-sorts by id — legacy mix of tiny mention ids + 1_000_000+ follow
 * ids pushed brand-new likes below older follows.
 */
function ap_masto_notification_id_for_mention(int $mentionId, ?string $createdAt = null): string
{
    if ($createdAt === null || $createdAt === '') {
        $createdAt = gmdate('c');
    }
    return ap_masto_snowflake_id($createdAt, $mentionId, 5);
}

function ap_masto_notification_id_for_follow_event(int $eventId, ?string $createdAt = null): string
{
    if ($createdAt === null || $createdAt === '') {
        $createdAt = gmdate('c');
    }
    return ap_masto_snowflake_id($createdAt, $eventId, 6);
}

function ap_masto_notification_id_for_poll(int $pollLocalId, ?string $createdAt = null): string
{
    if ($createdAt === null || $createdAt === '') {
        $createdAt = gmdate('c');
    }
    return ap_masto_snowflake_id($createdAt, $pollLocalId, 7);
}

/**
 * @return array{kind:string,db_id:int,ver?:int}|null
 */
function ap_masto_parse_notification_id(int $id): ?array
{
    if ($id <= 0) {
        return null;
    }
    // Legacy namespaces (pre-snowflake notifs)
    if ($id < 1000000) {
        return ['kind' => 'mention', 'db_id' => $id, 'ver' => 0];
    }
    if ($id < 2000000) {
        return ['kind' => 'follow', 'db_id' => $id - 1000000, 'ver' => 0];
    }
    if ($id < 3000000) {
        return ['kind' => 'poll', 'db_id' => $id - 2000000, 'ver' => 0];
    }
    // v2 notif snowflake (types 5–7)
    if ($id >= 1000000000000000000) {
        $dbId = $id % 100000000;
        $typeNum = (int) floor(($id % 1000000000) / 100000000);
        return match ($typeNum) {
            5 => ['kind' => 'mention', 'db_id' => $dbId, 'ver' => 2],
            6 => ['kind' => 'follow', 'db_id' => $dbId, 'ver' => 2],
            7 => ['kind' => 'poll', 'db_id' => $dbId, 'ver' => 2],
            default => null,
        };
    }
    // v1
    $dbId = $id % 10000;
    $typeNum = (int) floor(($id % 100000) / 10000);
    return match ($typeNum) {
        5 => ['kind' => 'mention', 'db_id' => $dbId, 'ver' => 1],
        6 => ['kind' => 'follow', 'db_id' => $dbId, 'ver' => 1],
        7 => ['kind' => 'poll', 'db_id' => $dbId, 'ver' => 1],
        default => null,
    };
}

/**
 * Build one notification entity, or null.
 *
 * @param array{kind:string,row:array} $item
 */
function ap_masto_notification_entity(array $item): ?array
{
    $kind = $item['kind'];
    $row = $item['row'];
    if ($kind === 'follow') {
        $actorId = (string) ($row['actor_id'] ?? '');
        if ($actorId === '' || (function_exists('ap_row_is_hidden') ? ap_row_is_hidden($actorId, null, ap_db_masto_owner_user_id()) : ap_row_is_blocked($actorId))) {
            return null;
        }
        $createdAt = isset($row['created_at']) ? (string) $row['created_at'] : null;
        $id = ap_masto_notification_id_for_follow_event((int) $row['id'], $createdAt);
        return [
            'id' => $id,
            'type' => 'follow',
            'group_key' => 'ungrouped-' . $id,
            'created_at' => ap_masto_format_time($createdAt),
            'account' => ap_masto_remote_account($actorId),
            'status' => null,
        ];
    }

    if ($kind === 'poll') {
        // Synthetic poll-ended row from masto_polls
        $pollLocalId = (int) ($row['local_id'] ?? 0);
        $statusLocalId = (int) ($row['status_local_id'] ?? 0);
        if ($pollLocalId < 1 || $statusLocalId < 1) {
            return null;
        }
        $statusRow = ap_masto_status_by_local_id($statusLocalId);
        if (!$statusRow) {
            return null;
        }
        $status = ap_masto_status_from_row($statusRow);
        $created = (string) ($row['expires_at'] ?? $row['created_at'] ?? '');
        $id = ap_masto_notification_id_for_poll($pollLocalId, $created !== '' ? $created : null);
        // Mastodon: poll notif account = poll author (status.account), never the viewer session
        return [
            'id' => $id,
            'type' => 'poll',
            'group_key' => 'ungrouped-' . $id,
            'created_at' => ap_masto_format_time($created !== '' ? $created : null),
            'account' => is_array($status['account'] ?? null) ? $status['account'] : ap_masto_account(),
            'status' => $status,
        ];
    }

    // mention / favourite / reblog / quote / update from mentions table
    $notifActor = isset($row['actor_id']) ? (string) $row['actor_id'] : null;
    if (function_exists('ap_row_is_hidden') ? ap_row_is_hidden($notifActor, null, ap_db_masto_owner_user_id()) : ap_row_is_blocked($notifActor)) {
        return null;
    }
    $type = ap_masto_mention_notif_type($row);
    if ($type === null) {
        return null;
    }
    $actorId = (string) ($row['actor_id'] ?? '');
    $account = $actorId !== '' ? ap_masto_remote_account($actorId) : ap_masto_account();
    $status = null;
    $objectId = ap_masto_mention_target_object_id((string) ($row['object_id'] ?? ''));
    $isBsky = ap_masto_mention_is_bluesky($row);
    if ($type === 'favourite' || $type === 'reblog') {
        $status = ap_masto_resolve_our_liked_status($objectId);
        // Bluesky likes/reposts: prefer the mapped VAAK post body when this was a cross-post.
        if ($status === null && $isBsky) {
            $subjectKey = ap_masto_mention_target_object_id((string) ($row['object_id'] ?? ''));
            $mappedNote = null;
            if (str_starts_with($subjectKey, 'https://mkultra.monster/users/')
                && function_exists('ap_masto_status_by_note_id')) {
                $mappedNote = ap_masto_status_by_note_id($subjectKey);
            } else {
                if (!function_exists('ap_bsky_local_note_id_for_at_uri')) {
                    $bskyLib = __DIR__ . '/ap-bsky.php';
                    if (is_file($bskyLib)) {
                        require_once $bskyLib;
                    }
                }
                if (function_exists('ap_bsky_local_note_id_for_at_uri')) {
                    $noteId = ap_bsky_local_note_id_for_at_uri($subjectKey, (int) ($row['owner_user_id'] ?? 0));
                    if (is_string($noteId) && $noteId !== '' && function_exists('ap_masto_status_by_note_id')) {
                        $mappedNote = ap_masto_status_by_note_id($noteId);
                    }
                }
            }
            if (is_array($mappedNote) && function_exists('ap_masto_status_from_row')) {
                $status = ap_masto_status_from_row($mappedNote);
            }
        }
        if ($status === null && $isBsky) {
            $status = ap_masto_status_from_mention($row);
            // Favourite/reblog status must be *our* post; account on the notification
            // remains the remote actor. Rewrite the embedded status author to us.
            $ownerAcct = null;
            $ownerActor = rtrim((string) ($row['owner_actor_id'] ?? ''), '/');
            if ($ownerActor !== '' && function_exists('ap_masto_account_for_local_url')) {
                $ownerAcct = ap_masto_account_for_local_url($ownerActor);
            }
            if (!is_array($ownerAcct) && function_exists('ap_masto_account')) {
                $ownerAcct = ap_masto_account();
            }
            if (is_array($ownerAcct)) {
                $status['account'] = $ownerAcct;
            }
            $subjectUrl = ap_masto_mention_target_object_id((string) ($row['object_id'] ?? ''));
            if (str_starts_with($subjectUrl, 'https://bsky.app/')
                || str_starts_with($subjectUrl, 'https://mkultra.monster/')) {
                $status['url'] = $subjectUrl;
                $status['uri'] = $subjectUrl;
            }
            // Placeholder only when we truly have no local body.
            if (trim(strip_tags((string) ($status['content'] ?? ''))) === ''
                || str_contains((string) ($row['content'] ?? ''), 'your Bluesky post')) {
                $status['content'] = '<p>Bluesky post</p>';
            }
        }
        // Liked/boosted post gone (deleted) → drop the notification entirely
        if ($status === null) {
            return null;
        }
    } elseif ($type === 'bite') {
        // Post bite → show our post; user bite → synthetic status from mention content
        if (str_contains($objectId, '/notes/') || str_contains($objectId, '/statuses/')) {
            $status = ap_masto_resolve_our_liked_status($objectId)
                ?? ap_masto_status_from_mention($row);
        } else {
            $status = ap_masto_status_from_mention($row);
        }
    } elseif ($type === 'update') {
        // Edited remote status we favourited
        $looked = $objectId !== '' ? ap_masto_lookup_status_by_object_url($objectId, 1) : null;
        $status = $looked ?? ap_masto_status_from_mention($row);
    } elseif ($type === 'quote') {
        // Quoting status (the Create that quotes us)
        $status = ap_masto_status_from_mention($row);
    } else {
        $status = ap_masto_status_from_mention($row);
    }

    $createdAt = isset($row['created_at']) ? (string) $row['created_at'] : null;
    $id = ap_masto_notification_id_for_mention((int) $row['id'], $createdAt);
    return [
        'id' => $id,
        'type' => $type,
        'group_key' => 'ungrouped-' . $id,
        'created_at' => ap_masto_format_time($createdAt),
        'account' => $account,
        'status' => $status,
    ];
}

/**
 * Mastodon 4.3+ / Ice Cubes grouped notifications payload.
 * One group per notification (ungrouped-*) — enough for clients that only call /api/v2/notifications.
 *
 * @param list<string> $types
 * @param list<string> $exclude
 * @return array{accounts:list<array>,statuses:list<array>,notification_groups:list<array>}
 */
function ap_masto_notifications_grouped_fetch(int $limit = 40, ?string $maxId = null, ?string $sinceId = null, array $types = [], array $exclude = []): array
{
    $notifs = ap_masto_notifications_fetch($limit, $maxId, $sinceId, $types, $exclude);
    $accounts = [];
    $statuses = [];
    $groups = [];
    foreach ($notifs as $n) {
        if (!is_array($n)) {
            continue;
        }
        $acct = $n['account'] ?? null;
        $acctId = '';
        if (is_array($acct) && isset($acct['id'])) {
            $acctId = (string) $acct['id'];
            $accounts[$acctId] = $acct;
        }
        $statusId = null;
        $status = $n['status'] ?? null;
        if (is_array($status) && isset($status['id'])) {
            $statusId = (string) $status['id'];
            $statuses[$statusId] = $status;
        }
        $nid = (string) ($n['id'] ?? '');
        if ($nid === '') {
            continue;
        }
        $groupKey = isset($n['group_key']) && is_string($n['group_key']) && $n['group_key'] !== ''
            ? $n['group_key']
            : ('ungrouped-' . $nid);
        $type = (string) ($n['type'] ?? 'mention');
        // Mastodon returns most_recent_notification_id as an integer; Ice Cubes
        // decoding can fail if this is a string.
        $nidInt = (int) $nid;
        $group = [
            'group_key' => $groupKey,
            'notifications_count' => 1,
            'type' => $type,
            'most_recent_notification_id' => $nidInt,
            'page_min_id' => $nid,
            'page_max_id' => $nid,
            'latest_page_notification_at' => (string) ($n['created_at'] ?? gmdate('Y-m-d\TH:i:s.000\Z')),
            'sample_account_ids' => $acctId !== '' ? [$acctId] : [],
        ];
        if (in_array($type, ['mention', 'status', 'reblog', 'favourite', 'poll', 'update', 'quote', 'quoted_update'], true)) {
            $group['status_id'] = $statusId;
        }
        $groups[] = $group;
    }
    return [
        'accounts' => array_values($accounts),
        'statuses' => array_values($statuses),
        'notification_groups' => $groups,
    ];
}

/**
 * @param list<string> $types empty = all supported
 * @param list<string> $exclude
 * @return list<array<string,mixed>>
 */
function ap_masto_notifications_fetch(int $limit = 40, ?string $maxId = null, ?string $sinceId = null, array $types = [], array $exclude = []): array
{
    $limit = max(1, min(80, $limit));
    // favourites, mentions, boosts, quote-boosts, bites, poll ended, favourited-status edits, follows, subscribed posts
    $want = ['mention', 'follow', 'favourite', 'reblog', 'quote', 'poll', 'update', 'bite', 'status'];
    if ($types) {
        $want = array_values(array_intersect($want, $types));
    }
    if ($exclude) {
        $want = array_values(array_diff($want, $exclude));
    }
    if (!$want) {
        return [];
    }

    $items = [];
    $mentionTypes = ['mention', 'favourite', 'reblog', 'quote', 'update', 'bite', 'status'];

    $ownerUserId = function_exists('ap_db_masto_owner_user_id')
        ? ap_db_masto_owner_user_id()
        : ap_db_default_owner_user_id();
    $ownerActorId = function_exists('ap_db_owner_actor_id_for_user_id')
        ? ap_db_owner_actor_id_for_user_id($ownerUserId)
        : (string) ($GLOBALS['vaak_actor_id'] ?? 'https://mkultra.monster/users/cmdr_nova');
    $ownerActorId = rtrim($ownerActorId, '/');

    if (array_intersect($want, $mentionTypes)) {
        $st = ap_db()->prepare(
            'SELECT * FROM mentions WHERE owner_user_id = ? AND deleted_at IS NULL ORDER BY id DESC LIMIT 250'
        );
        $st->execute([$ownerUserId]);
        $seenActivityIds = [];
        foreach ($st->fetchAll() as $row) {
            $activityId = trim((string) ($row['activity_id'] ?? ''));
            if ($activityId !== '') {
                if (isset($seenActivityIds[$activityId])) {
                    continue;
                }
                $seenActivityIds[$activityId] = true;
            }
            $t = ap_masto_mention_notif_type($row);
            if ($t === null || !in_array($t, $want, true)) {
                continue;
            }
            $nid = (int) $row['id'];
            $items[] = [
                'sort' => strtotime((string) ($row['created_at'] ?? '')) ?: $nid,
                'kind' => 'mention',
                'row' => $row,
            ];
        }
    }

    if (in_array('follow', $want, true)) {
        // Follow events must target THIS session actor only (never NULL/empty — that leaked).
        // Include Bluesky follows (action_taken=bsky_follow) — those are not in AP followers[].
        $bskyFollowAction = defined('AP_BSKY_FOLLOW_ACTION') ? AP_BSKY_FOLLOW_ACTION : 'bsky_follow';
        $st = ap_db()->prepare(
            "SELECT id, created_at, actor_id, target_actor, action_taken FROM events
             WHERE type = 'Follow'
               AND action_taken IN ('local_accept_followback', ?)
               AND (target_actor = ? OR target_actor = ?)
             ORDER BY id DESC LIMIT 100"
        );
        $st->execute([$bskyFollowAction, $ownerActorId, $ownerActorId . '/']);
        // Drop AP follow notifs once the actor has unfollowed (Undo Follow).
        $followerSet = [];
        try {
            foreach (ap_followers_list($ownerActorId) as $fr) {
                $aid = rtrim((string) ($fr['actor_id'] ?? ''), '/');
                if ($aid !== '') {
                    $followerSet[$aid] = true;
                }
            }
        } catch (Throwable $e) {
            $followerSet = [];
        }
        foreach ($st->fetchAll() as $row) {
            $fa = rtrim((string) ($row['actor_id'] ?? ''), '/');
            $action = (string) ($row['action_taken'] ?? '');
            if ($action === 'local_accept_followback'
                && $fa !== '' && $followerSet !== [] && empty($followerSet[$fa])) {
                continue;
            }
            $nid = 1000000 + (int) $row['id'];
            $items[] = [
                'sort' => strtotime((string) ($row['created_at'] ?? '')) ?: $nid,
                'kind' => 'follow',
                'row' => $row,
            ];
        }
    }

    // Poll ended: polls THIS account authored, or polls they voted on — never other locals'.
    if (in_array('poll', $want, true)) {
        try {
            $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('c');
            $st = ap_db()->prepare(
                'SELECT * FROM masto_polls WHERE expires_at <= ? ORDER BY expires_at DESC LIMIT 40'
            );
            $st->execute([$now]);
            $me = rtrim($ownerActorId !== '' ? $ownerActorId : ap_masto_session_actor_id(), '/');
            foreach ($st->fetchAll() as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $noteId = rtrim((string) ($row['note_id'] ?? ''), '/');
                $ours = $me !== '' && str_starts_with($noteId, $me . '/notes/');
                $voted = false;
                $voters = json_decode((string) ($row['voters_json'] ?? '[]'), true);
                if (is_array($voters)) {
                    foreach ($voters as $v) {
                        if (rtrim((string) $v, '/') === $me) {
                            $voted = true;
                            break;
                        }
                    }
                }
                if (!$ours && !$voted) {
                    continue;
                }
                $nid = 2000000 + (int) $row['local_id'];
                $items[] = [
                    'sort' => strtotime((string) ($row['expires_at'] ?? '')) ?: $nid,
                    'kind' => 'poll',
                    'row' => $row,
                ];
            }
        } catch (Throwable $e) {
            // polls table may be absent on older installs
        }
    }

    usort($items, static function ($a, $b) {
        return $b['sort'] <=> $a['sort'];
    });

    $out = [];
    foreach ($items as $item) {
        $ent = ap_masto_notification_entity($item);
        if ($ent === null) {
            continue;
        }
        // Generic max_id / since_id on notification id string
        if ($maxId !== null && $maxId !== '' && (int) $ent['id'] >= (int) $maxId) {
            continue;
        }
        if ($sinceId !== null && $sinceId !== '' && (int) $ent['id'] <= (int) $sinceId) {
            continue;
        }
        $out[] = $ent;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Unread notification state vs masto_markers.notifications.last_read_id.
 *
 * @return array{count:int,last_read_id:string,latest_unread_id:string,latest_id:string}
 */
function ap_masto_notifications_unread_state(int $scan = 80, bool $bypassCache = false): array
{
    $scan = max(1, min(80, $scan));
    $markers = ap_masto_markers_get();
    $lastRead = '0';
    $nMark = $markers->notifications ?? null;
    if (is_array($nMark) && isset($nMark['last_read_id'])) {
        $lastRead = (string) $nMark['last_read_id'];
    } elseif (is_object($nMark) && isset($nMark->last_read_id)) {
        $lastRead = (string) $nMark->last_read_id;
    }
    // Compare as digit strings — snowflake ids can exceed float precision if cast poorly.
    $lastRead = preg_replace('/\D+/', '', $lastRead) ?: '0';

    $ownerUserId = function_exists('ap_db_masto_owner_user_id')
        ? ap_db_masto_owner_user_id()
        : (function_exists('ap_db_default_owner_user_id') ? ap_db_default_owner_user_id() : 0);
    // Keep page-load badge snappy, but ajax polling must not sit on a 45s lie.
    $cacheTtl = $bypassCache ? 0 : 12;
    $cacheDir = '/var/lib/mkultra/ap';
    if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
        $cacheDir = sys_get_temp_dir();
    }
    $cachePath = $cacheDir . '/notif_unread_' . (int) $ownerUserId . '_' . substr(sha1($lastRead), 0, 12) . '.json';
    if ($cacheTtl > 0 && is_file($cachePath)) {
        $age = time() - (int) @filemtime($cachePath);
        if ($age >= 0 && $age < $cacheTtl) {
            $raw = @file_get_contents($cachePath);
            if (is_string($raw) && $raw !== '') {
                $decoded = json_decode($raw, true);
                if (is_array($decoded) && isset($decoded['c'])) {
                    return [
                        'count' => max(0, min($scan, (int) $decoded['c'])),
                        'last_read_id' => $lastRead,
                        'latest_unread_id' => (string) ($decoded['u'] ?? ''),
                        'latest_id' => (string) ($decoded['l'] ?? ''),
                    ];
                }
            }
        }
    }

    $ownerActorId = function_exists('ap_db_owner_actor_id_for_user_id')
        ? ap_db_owner_actor_id_for_user_id($ownerUserId)
        : (string) ($GLOBALS['vaak_actor_id'] ?? 'https://mkultra.monster/users/cmdr_nova');
    $ownerActorId = rtrim((string) $ownerActorId, '/');

    $ids = [];
    try {
        // Mentions / favs / boosts / quotes / bites / updates — light rows (no entity hydrate).
        // Column list must match the real mentions schema (there is no object_type).
        // A bad SELECT here was swallowed by the catch below and left the nav badge stuck at 0
        // while the full notifications view (SELECT *) still showed new mentions.
        $st = ap_db()->prepare(
            'SELECT id, created_at, type, activity_id, activity_type,
                    object_id, owner_actor_id, actor_id, content, in_reply_to, owner_user_id
             FROM mentions
             WHERE owner_user_id = ? AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 250'
        );
        $st->execute([$ownerUserId]);
        $seenActivityIds = [];
        foreach ($st->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $activityId = trim((string) ($row['activity_id'] ?? ''));
            if ($activityId !== '') {
                if (isset($seenActivityIds[$activityId])) {
                    continue;
                }
                $seenActivityIds[$activityId] = true;
            }
            if (function_exists('ap_masto_mention_notif_type') && ap_masto_mention_notif_type($row) === null) {
                continue;
            }
            $ids[] = ap_masto_notification_id_for_mention(
                (int) ($row['id'] ?? 0),
                isset($row['created_at']) ? (string) $row['created_at'] : null
            );
        }
    } catch (Throwable $e) {
        // fall through
    }

    try {
        $st = ap_db()->prepare(
            "SELECT id, created_at, actor_id FROM events
             WHERE type = 'Follow' AND action_taken = 'local_accept_followback'
               AND (target_actor = ? OR target_actor = ?)
             ORDER BY id DESC LIMIT 100"
        );
        $st->execute([$ownerActorId, $ownerActorId . '/']);
        $followerSet = [];
        try {
            foreach (ap_followers_list($ownerActorId) as $fr) {
                $aid = rtrim((string) ($fr['actor_id'] ?? ''), '/');
                if ($aid !== '') {
                    $followerSet[$aid] = true;
                }
            }
        } catch (Throwable $e) {
            $followerSet = [];
        }
        foreach ($st->fetchAll() ?: [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $fa = rtrim((string) ($row['actor_id'] ?? ''), '/');
            if ($fa !== '' && $followerSet !== [] && empty($followerSet[$fa])) {
                continue;
            }
            $ids[] = ap_masto_notification_id_for_follow_event(
                (int) ($row['id'] ?? 0),
                isset($row['created_at']) ? (string) $row['created_at'] : null
            );
        }
    } catch (Throwable $e) {
        // fall through
    }

    // Newest-first by snowflake string length then lexicographic compare.
    usort($ids, static function (string $a, string $b): int {
        $la = strlen($a);
        $lb = strlen($b);
        if ($la !== $lb) {
            return $lb <=> $la;
        }
        return $b <=> $a;
    });
    $ids = array_slice($ids, 0, $scan);

    $count = 0;
    $latestId = '';
    $latestUnreadId = '';
    foreach ($ids as $nidRaw) {
        $nid = preg_replace('/\D+/', '', (string) $nidRaw) ?: '0';
        if ($latestId === '') {
            $latestId = $nid;
        }
        $isUnread = false;
        if (strlen($nid) === strlen($lastRead)) {
            if ($nid > $lastRead) {
                $isUnread = true;
            }
        } elseif (strlen($nid) > strlen($lastRead)) {
            $isUnread = true;
        }
        if ($isUnread) {
            $count++;
            if ($latestUnreadId === '') {
                $latestUnreadId = $nid;
            }
        }
    }

    $payload = [
        'c' => $count,
        'u' => $latestUnreadId,
        'l' => $latestId,
        'ts' => time(),
    ];
    @file_put_contents($cachePath, json_encode($payload), LOCK_EX);
    return [
        'count' => $count,
        'last_read_id' => $lastRead,
        'latest_unread_id' => $latestUnreadId,
        'latest_id' => $latestId,
    ];
}

/**
 * Unread notification count vs masto_markers.notifications.last_read_id.
 * Same logic Ice Cubes uses via /api/v1/notifications/unread_count.
 */
function ap_masto_notifications_unread_count(int $scan = 80): int
{
    $state = ap_masto_notifications_unread_state($scan, false);
    return (int) ($state['count'] ?? 0);
}

/**
 * Newest snowflake among digit-only ids (length then lexicographic).
 */
function ap_masto_snowflake_newest(string ...$ids): string
{
    $best = '0';
    foreach ($ids as $raw) {
        $id = preg_replace('/\D+/', '', (string) $raw) ?: '0';
        if ($id === '0') {
            continue;
        }
        if ($best === '0') {
            $best = $id;
            continue;
        }
        $lb = strlen($best);
        $li = strlen($id);
        if ($li > $lb || ($li === $lb && $id > $best)) {
            $best = $id;
        }
    }
    return $best;
}

/**
 * Mark notifications timeline read up to $lastId (or the newest notification).
 * Returns the last_read_id that was written.
 *
 * Always takes the max of: explicit id, light-scan tip, hydrated fetch tip, and
 * the existing marker — never regress. A wrong/older snowflake for the same
 * mention id (clock skew / now()-fallback) used to leave real newer ids unread
 * so the badge came back after leaving Notifications.
 */
function ap_masto_notifications_mark_read(?string $lastId = null): string
{
    $candidates = [];
    if ($lastId !== null && $lastId !== '') {
        $candidates[] = $lastId;
    }
    try {
        $state = ap_masto_notifications_unread_state(80, true);
        $candidates[] = (string) ($state['latest_id'] ?? '');
        $candidates[] = (string) ($state['latest_unread_id'] ?? '');
        $candidates[] = (string) ($state['last_read_id'] ?? '');
    } catch (Throwable $e) {
        // fall through
    }
    try {
        $latest = ap_masto_notifications_fetch(1);
        $candidates[] = (string) ($latest[0]['id'] ?? '');
    } catch (Throwable $e) {
        // fall through
    }
    try {
        $markers = ap_masto_markers_get();
        $nMark = $markers->notifications ?? null;
        if (is_array($nMark) && isset($nMark['last_read_id'])) {
            $candidates[] = (string) $nMark['last_read_id'];
        } elseif (is_object($nMark) && isset($nMark->last_read_id)) {
            $candidates[] = (string) $nMark->last_read_id;
        }
    } catch (Throwable $e) {
        // fall through
    }

    $best = ap_masto_snowflake_newest(...$candidates);
    if ($best === '' || $best === '0') {
        return '0';
    }
    ap_masto_markers_set(['notifications' => ['last_read_id' => $best]]);
    // Drop short-lived unread badge cache so the nav clears immediately.
    try {
        $ownerUserId = function_exists('ap_db_masto_owner_user_id')
            ? ap_db_masto_owner_user_id()
            : (function_exists('ap_db_default_owner_user_id') ? ap_db_default_owner_user_id() : 0);
        $cacheDir = '/var/lib/mkultra/ap';
        if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
            $cacheDir = sys_get_temp_dir();
        }
        foreach (glob($cacheDir . '/notif_unread_' . (int) $ownerUserId . '_*.json') ?: [] as $path) {
            @unlink($path);
        }
    } catch (Throwable $e) {
        // non-fatal
    }
    return $best;
}

/**
 * Soft-dismiss a notification for the current token user (Ice Cubes swipe-away).
 * Mention-backed notifs are soft-deleted; follow/poll are acknowledged only.
 */
function ap_masto_notification_dismiss(string $id): bool
{
    $parsed = ap_masto_parse_notification_id((int) $id);
    if ($parsed === null) {
        return false;
    }
    if (($parsed['kind'] ?? '') !== 'mention') {
        return true; // follow/poll: nothing durable to delete
    }
    $ownerUserId = ap_db_default_owner_user_id();
    $dbId = (int) ($parsed['db_id'] ?? 0);
    if ($dbId < 1 || $ownerUserId < 1) {
        return false;
    }
    try {
        $st = ap_db()->prepare(
            'UPDATE mentions SET deleted_at = ? WHERE id = ? AND owner_user_id = ? AND deleted_at IS NULL'
        );
        $st->execute([ap_db_now(), $dbId, $ownerUserId]);
        return $st->rowCount() > 0;
    } catch (Throwable $e) {
        return false;
    }
}

function ap_masto_notification_by_id(string $id): ?array
{
    $parsed = ap_masto_parse_notification_id((int) $id);
    if ($parsed === null) {
        return null;
    }
    $kind = $parsed['kind'];
    $dbId = $parsed['db_id'];
    if ($kind === 'poll') {
        try {
            $st = ap_db()->prepare('SELECT * FROM masto_polls WHERE local_id = ?');
            $st->execute([$dbId]);
            $row = $st->fetch();
            if (!is_array($row)) {
                return null;
            }
            return ap_masto_notification_entity(['kind' => 'poll', 'row' => $row]);
        } catch (Throwable $e) {
            return null;
        }
    }
    if ($kind === 'follow') {
        $st = ap_db()->prepare(
            "SELECT id, created_at, actor_id FROM events
             WHERE id = ? AND type = 'Follow' AND action_taken = 'local_accept_followback'"
        );
        $st->execute([$dbId]);
        $row = $st->fetch();
        if (!is_array($row)) {
            return null;
        }
        return ap_masto_notification_entity(['kind' => 'follow', 'row' => $row]);
    }
    $ownerUserId = ap_db_default_owner_user_id();
    $st = ap_db()->prepare(
        'SELECT * FROM mentions WHERE id = ? AND owner_user_id = ? AND deleted_at IS NULL'
    );
    $st->execute([$dbId, $ownerUserId]);
    $row = $st->fetch();
    if (!is_array($row)) {
        return null;
    }
    return ap_masto_notification_entity(['kind' => 'mention', 'row' => $row]);
}

function ap_masto_markers_get(?int $ownerUserId = null): object
{
    $ownerUserId = $ownerUserId ?? ap_db_default_owner_user_id();
    $out = new stdClass();
    try {
        $st = ap_db()->prepare(
            'SELECT timeline, last_read_id, updated_at FROM masto_markers WHERE owner_user_id = ?'
        );
        $st->execute([$ownerUserId]);
        foreach ($st->fetchAll() as $r) {
            $tl = (string) $r['timeline'];
            $out->{$tl} = [
                'last_read_id' => (string) $r['last_read_id'],
                'version' => 1,
                'updated_at' => ap_masto_format_time((string) $r['updated_at']),
            ];
        }
    } catch (Throwable $e) {
        // table may not exist yet on first boot before migrate
    }
    return $out;
}

/** Synthetic status id for a federated events-table row. */
function ap_masto_event_status_id(int $eventId, ?string $createdAt = null): string
{
    if ($createdAt === null || $createdAt === '') {
        return (string) (3000000 + $eventId); // legacy fallback
    }
    return ap_masto_snowflake_id($createdAt, $eventId, 1);
}

function ap_masto_event_id_from_status_id(int $statusId): ?int
{
    $p = ap_masto_parse_public_status_id($statusId);
    if (!$p || $p['type'] !== 'event') {
        return null;
    }
    // Legacy fixed namespace
    if ($statusId >= 3000000 && $statusId < 4000000) {
        return $statusId - 3000000;
    }
    $ver = (int) ($p['ver'] ?? 1);
    if ($ver === 2) {
        // Full event id embedded — exact lookup
        return (int) $p['db_id'];
    }
    // v1: db_id was event_id % 10000; disambiguate by timestamp
    return ap_masto_disambiguate_mod_id(
        'events',
        (int) $p['db_id'],
        (int) ($p['ms'] ?? 0),
        'created_at',
        false
    );
}

/**
 * Resolve the original Note author for an Announce (boost) events row.
 * Prefer target_actor, then a matching Create, then URL heuristics / local notes.
 */
function ap_masto_announce_original_actor(array $row, bool $allowFetch = false): ?string
{
    $target = rtrim(trim((string) ($row['target_actor'] ?? '')), '/');
    if ($target !== '' && str_starts_with($target, 'https://')) {
        return $target;
    }
    $objectId = rtrim(trim((string) ($row['object_id'] ?? '')), '/');
    if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
        return null;
    }
    $localNoteActor = ap_masto_local_note_actor_id($objectId);
    if ($localNoteActor !== null) {
        return $localNoteActor;
    }
    if (ap_masto_is_local_actor_url($objectId)) {
        return rtrim($objectId, '/');
    }
    try {
        $st = ap_db()->prepare(
            "SELECT actor_id FROM events
             WHERE type = 'Create'
               AND (object_id = ? OR object_id = ?)
               AND COALESCE(action_taken, '') != 'deleted'
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$objectId, $objectId . '/']);
        $cr = $st->fetch();
        if (is_array($cr) && !empty($cr['actor_id'])) {
            return rtrim((string) $cr['actor_id'], '/');
        }
    } catch (Throwable $e) {
        // ignore
    }
    $guess = ap_masto_actor_url_from_object_url($objectId);
    if (is_string($guess) && $guess !== '') {
        return rtrim($guess, '/');
    }
    // Remote AS2 fetch is opt-in — timeline/admin render must stay offline-fast
    // (relay firehose made sync fetches blow past gateway timeouts).
    if ($allowFetch && function_exists('ap_fetch_as2_object')) {
        $doc = ap_fetch_as2_object($objectId);
        if (is_array($doc)) {
            if (function_exists('ap_unwrap_as2_object')) {
                $doc = ap_unwrap_as2_object($doc) ?? $doc;
            }
            $at = null;
            if (function_exists('ap_as_id')) {
                $at = ap_as_id($doc['attributedTo'] ?? null) ?: ap_as_id($doc['actor'] ?? null);
            } elseif (!empty($doc['attributedTo']) && is_string($doc['attributedTo'])) {
                $at = $doc['attributedTo'];
            }
            if (is_string($at) && str_starts_with($at, 'https://')) {
                return rtrim($at, '/');
            }
        }
    }
    return null;
}

/**
 * Status entity from a public inbound events row (Create firehose / following feed).
 */
function ap_masto_status_from_event(array $row): ?array
{
    $eventId = (int) ($row['id'] ?? 0);
    if ($eventId <= 0) {
        return null;
    }
    $actorId = (string) ($row['actor_id'] ?? '');
    $evHost = isset($row['host']) ? (string) $row['host'] : null;
    if ($actorId === '' || (function_exists('ap_row_is_hidden')
        ? ap_row_is_hidden($actorId, $evHost, ap_db_masto_owner_user_id())
        : ap_row_is_blocked($actorId, $evHost))) {
        return null;
    }
    // Skip session actor's own outbound copies (shown via local statuses / outbox cards).
    // Other local instance accounts must remain resolvable for fav/boost/etc.
    $sessionActor = function_exists('ap_local_actor_id') ? rtrim(ap_local_actor_id(), '/') : '';
    if ($sessionActor !== '' && rtrim($actorId, '/') === $sessionActor) {
        return null;
    }

    $account = ap_masto_remote_account($actorId);
    $text = html_entity_decode((string) ($row['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $spoilerText = trim((string) ($row['spoiler_text'] ?? ''));
    $isSensitive = !empty($row['sensitive']) || $spoilerText !== '';
    // Strip RE:<url> prefixes from quote commentary (keep ↪ QT block intact)
    if ($text !== '' && function_exists('ap_masto_clean_mention_text')) {
        if (str_contains($text, '↪ QT')) {
            if (preg_match('/^(.*?)((?:\n\n|\n)↪ QT.*)$/us', $text, $cm)) {
                $text = ap_masto_clean_mention_text($cm[1]) . $cm[2];
            } else {
                $text = ap_masto_clean_mention_text($text);
            }
        } else {
            $text = ap_masto_clean_mention_text($text);
        }
    }
    $objectId = (string) ($row['object_id'] ?? '');
    $url = $objectId !== '' ? $objectId : $actorId;
    // Human "Open remote" / status.url — Bridgy convert/ap URLs are AP JSON, not a webpage
    $webUrl = $url;
    if ($objectId !== '' && function_exists('ap_remote_object_web_url')) {
        $mapped = ap_remote_object_web_url($objectId);
        if ($mapped !== '') {
            $webUrl = $mapped;
        }
    }

    $media = [];
    if (!empty($row['media_urls'])) {
        $decoded = json_decode((string) $row['media_urls'], true);
        if (is_array($decoded)) {
            $i = 0;
            foreach ($decoded as $u) {
                $clean = is_string($u) ? ap_profile_sanitize_https_url($u) : null;
                if ($clean === null) {
                    continue;
                }
                $i++;
                $ext = strtolower(pathinfo(parse_url($clean, PHP_URL_PATH) ?? '', PATHINFO_EXTENSION));
                $type = in_array($ext, ['mp4', 'webm', 'mov', 'm4v'], true) ? 'video' : 'image';
                $media[] = [
                    'id' => ap_masto_event_status_id($eventId, isset($row['created_at']) ? (string) $row['created_at'] : null) . $i,
                    'type' => $type,
                    'url' => $clean,
                    'preview_url' => $clean,
                    'remote_url' => $clean,
                    'preview_remote_url' => null,
                    'text_url' => null,
                    'meta' => null,
                    'description' => null,
                    'blurhash' => null,
                ];
                if ($i >= 4) {
                    break;
                }
            }
        }
    }

    // Allow empty stubs in thread context (CW-only / fetch failures still need a parent card).
    // Announces of our own notes often store null summary — still show the boost wrapper.
    $type = strtolower((string) ($row['type'] ?? 'Create'));
    $allowEmpty = !empty($row['_allow_empty_for_context']) || $type === 'announce';
    $hasCw = trim((string) ($row['spoiler_text'] ?? '')) !== '' || !empty($row['sensitive']);
    if (trim($text) === '' && !$media && !$hasCw) {
        if (!$allowEmpty) {
            return null;
        }
        $text = '';
    }

    $replyPublicId = null;
    $replyAccountId = null;
    $replyParentActor = null;
    $inReplyToUrl = rtrim((string) ($row['in_reply_to'] ?? ''), '/');
    if ($inReplyToUrl !== '' && str_starts_with($inReplyToUrl, 'https://')) {
        // Optionally fetch missing parent (detail/context paths set _fetch_reply_parent)
        if (!empty($row['_fetch_reply_parent'])
            && (!function_exists('ap_feature_enabled') || ap_feature_enabled('detail_parent_hydration', true))) {
            $parentPack = ap_masto_resolve_object_url_to_status_ref($inReplyToUrl);
            if ($parentPack === null) {
                ap_masto_ensure_remote_note_event($inReplyToUrl);
            }
        }
        $parentPack = ap_masto_resolve_object_url_to_status_ref($inReplyToUrl);
        if ($parentPack !== null) {
            $replyPublicId = $parentPack['status_id'];
            $replyAccountId = $parentPack['account_id'];
            // Prefer live actor from the cached parent event when present
            if (function_exists('ap_event_by_object_id')) {
                $prow = ap_event_by_object_id($inReplyToUrl);
                if (is_array($prow) && !empty($prow['actor_id'])) {
                    $replyParentActor = rtrim((string) $prow['actor_id'], '/');
                }
            }
        }
        if ($replyParentActor === null) {
            $replyParentActor = ap_masto_actor_url_from_object_url($inReplyToUrl);
        }
        if ($replyAccountId === null && $replyParentActor !== null) {
            $replyAccountId = ap_masto_remote_account_id($replyParentActor);
        }
        // Synthetic fallback so profile exclude_replies + Ice Cubes reply UI still work
        // when the parent Note isn't in our local store yet.
        if ($replyPublicId === null) {
            $replyPublicId = (string) (900000000 + (abs(crc32($inReplyToUrl)) % 99999999));
        }
    }

    $extraActors = [];
    if (is_string($replyParentActor) && $replyParentActor !== '') {
        $extraActors[] = $replyParentActor;
    }
    // Fast path: no @mentions → skip the expensive mention resolver (timeline hot path).
    if ($text === '' || !str_contains($text, '@')) {
        $escaped = htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $escaped = preg_replace_callback(
            '/(^|[^A-Za-z0-9_&\/%])#([\p{L}\p{N}_]{1,100})/u',
            static function (array $m): string {
                $tag = $m[2];
                $href = 'https://mkultra.monster/tags/' . rawurlencode(mb_strtolower($tag));
                return $m[1] . '<a href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8')
                    . '" class="mention hashtag" rel="tag">#<span>'
                    . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '</span></a>';
            },
            $escaped
        ) ?? $escaped;
        $pack = [
            'content' => $escaped !== '' ? ('<p>' . nl2br($escaped) . '</p>') : '',
            'mentions' => [],
            'tags' => [],
        ];
        if (preg_match_all('/#([\p{L}\p{N}_]{1,100})/u', $text, $tm)) {
            $seenTag = [];
            foreach ($tm[1] as $rawTag) {
                $tn = function_exists('ap_masto_normalize_tag_name')
                    ? ap_masto_normalize_tag_name((string) $rawTag)
                    : mb_strtolower((string) $rawTag);
                if ($tn === '' || isset($seenTag[$tn])) {
                    continue;
                }
                $seenTag[$tn] = true;
                $pack['tags'][] = [
                    'name' => $tn,
                    'url' => 'https://mkultra.monster/tags/' . rawurlencode($tn),
                ];
            }
        }
    } else {
        $pack = ap_masto_content_with_mentions($text, $extraActors);
    }
    // Ice Cubes StatusRowReplyView: mentions must include in_reply_to_account_id
    if ($replyAccountId !== null && $replyParentActor) {
        $has = false;
        foreach ($pack['mentions'] as $m) {
            if ((string) ($m['id'] ?? '') === (string) $replyAccountId) {
                $has = true;
                break;
            }
        }
        if (!$has) {
            array_unshift($pack['mentions'], ap_masto_mention_from_actor($replyParentActor));
        }
    }
    // Dedupe mentions by account id
    $dedup = [];
    foreach ($pack['mentions'] as $m) {
        $mid = (string) ($m['id'] ?? '');
        if ($mid === '' || isset($dedup[$mid])) {
            continue;
        }
        $dedup[$mid] = $m;
    }
    $pack['mentions'] = array_values($dedup);

    $status = [
        'id' => ap_masto_event_status_id($eventId, isset($row['created_at']) ? (string) $row['created_at'] : null),
        'created_at' => ap_masto_format_time(isset($row['created_at']) ? (string) $row['created_at'] : null),
        'in_reply_to_id' => $replyPublicId,
        'in_reply_to_account_id' => $replyAccountId,
        'sensitive' => $isSensitive,
        'spoiler_text' => $spoilerText,
        'visibility' => function_exists('ap_normalize_visibility')
            ? ap_normalize_visibility($row['visibility'] ?? 'public')
            : (string) ($row['visibility'] ?? 'public'),
        'language' => 'en',
        'uri' => $url,
        'url' => $webUrl,
        'replies_count' => 0,
        'reblogs_count' => 0,
        'favourites_count' => 0,
        'edited_at' => null,
        'favourited' => false,
        'reblogged' => false,
        'muted' => false,
        'bookmarked' => false,
        'pinned' => false,
        'content' => $pack['content'],
        'reblog' => null,
        'application' => null,
        'account' => $account,
        'media_attachments' => $media,
        'mentions' => $pack['mentions'],
        'tags' => $pack['tags'] ?? [],
        'emojis' => [],
        'card' => null,
        'poll' => null,
    ];

    // Announce → Mastodon reblog wrapper: outer = booster, inner = original author
    if ($type === 'announce') {
        $origActor = ap_masto_announce_original_actor($row);
        // Never attribute the boosted Note to the booster when we can avoid it.
        if ($origActor === null || rtrim($origActor, '/') === rtrim($actorId, '/')) {
            // Last resort: leave inner as-is only if we truly cannot resolve — still wrap.
            $origActor = $origActor ?: $actorId;
        }
        $inner = $status;
        if ($origActor !== '' && rtrim($origActor, '/') !== rtrim($actorId, '/')) {
            $inner['account'] = (ap_masto_local_actor_key_from_url($origActor) !== null)
                ? ap_masto_account_for_local_url($origActor)
                : ap_masto_remote_account($origActor);
        }
        // Prefer a Create-event snowflake for the inner status id when available
        $innerId = $inner['id'];
        try {
            $oid = rtrim($objectId, '/');
            if ($oid !== '') {
                $cst = ap_db()->prepare(
                    "SELECT id, created_at FROM events
                     WHERE type = 'Create' AND (object_id = ? OR object_id = ?)
                     ORDER BY id DESC LIMIT 1"
                );
                $cst->execute([$oid, $oid . '/']);
                $crow = $cst->fetch();
                if (is_array($crow) && !empty($crow['id'])) {
                    $innerId = ap_masto_event_status_id(
                        (int) $crow['id'],
                        isset($crow['created_at']) ? (string) $crow['created_at'] : null
                    );
                } elseif (ap_masto_local_note_actor_id($oid) !== null) {
                    $local = function_exists('ap_masto_status_by_note_id') ? ap_masto_status_by_note_id($oid) : null;
                    if (is_array($local) && !empty($local['local_id'])) {
                        $innerId = ap_masto_snowflake_id(
                            (string) ($local['published'] ?? $inner['created_at']),
                            (int) $local['local_id'],
                            0
                        );
                    }
                }
            }
        } catch (Throwable $e) {
            // keep announce-based id
        }
        // Always uniquify inner vs outer — Ice Cubes Identifiable / SearchResults
        // choke when boost wrapper and reblog share the same id.
        // Use type-8 announce_inner snowflakes (not type-1 event) so favourite /
        // bookmark / GET status can resolve them without inventing fake event rows.
        if ($innerId === $status['id'] || $innerId === '') {
            if ($objectId !== '') {
                $innerId = ap_masto_announce_inner_synth_id(
                    (string) ($status['created_at'] ?? $row['created_at'] ?? gmdate('c')),
                    $objectId
                );
            } else {
                $innerId = ap_masto_event_status_id(
                    $eventId,
                    isset($row['created_at']) ? (string) $row['created_at'] : null
                );
                $innerId = (string) ((int) $innerId + 1);
            }
        }
        $inner['id'] = $innerId;
        $inner['uri'] = $objectId !== '' ? $objectId : (string) ($inner['uri'] ?? '');
        $inner['url'] = $inner['uri'];
        unset($inner['reblog']);
        $inner['reblog'] = null;

        $status = [
            'id' => $status['id'], // announce / event snowflake
            'created_at' => $status['created_at'],
            'in_reply_to_id' => null,
            'in_reply_to_account_id' => null,
            'sensitive' => false,
            'spoiler_text' => '',
            'visibility' => $inner['visibility'] ?? 'public',
            'language' => null,
            'uri' => ($objectId !== '' ? $objectId : $url) . '#announce-' . $eventId,
            'url' => $objectId !== '' ? $objectId : $url,
            'replies_count' => 0,
            'reblogs_count' => 0,
            'favourites_count' => 0,
            'quotes_count' => 0,
            'edited_at' => null,
            'favourited' => false,
            'reblogged' => false,
            'muted' => false,
            'bookmarked' => false,
            'pinned' => false,
            'content' => '',
            'reblog' => $inner,
            'application' => null,
            'account' => $account, // booster
            'media_attachments' => [],
            'mentions' => [],
            'tags' => [],
            'emojis' => [],
            'card' => null,
            'poll' => null,
        ];
    }

    return ap_masto_apply_interaction_flags($status);
}

/**
 * Federated public timeline (inbound Creates) or home (following only).
 *
 * @param 'federated'|'home' $mode
 * @return list<array<string,mixed>>
 */
/**
 * Log only slow timeline stages. The timing call itself is cheap, while the
 * threshold keeps normal request logs quiet and makes regressions visible.
 */
function ap_timeline_perf_log(string $stage, float $startedAt, array $fields = []): void
{
    $elapsedMs = (microtime(true) - $startedAt) * 1000.0;
    if ($elapsedMs < 250.0) {
        return;
    }
    $fields = ['ms' => round($elapsedMs, 1)] + $fields;
    error_log('[vaak-timeline] ' . $stage . ' ' . (json_encode($fields, JSON_UNESCAPED_SLASHES) ?: '{}'));
}

/** Hide a timeline row when its visible actor or related original is hidden. */
function ap_timeline_row_is_hidden(array $row, int $ownerUserId): bool
{
    $actor = (string) ($row['actor_id'] ?? '');
    $host = $row['host'] ?? null;
    $hidden = function_exists('ap_row_is_hidden')
        ? ap_row_is_hidden($actor !== '' ? $actor : null, $host, $ownerUserId)
        : (function_exists('ap_row_is_blocked') && ap_row_is_blocked($actor !== '' ? $actor : null, $host));
    if ($hidden) {
        return true;
    }
    $type = strtolower((string) ($row['type'] ?? ''));
    if ($type === 'announce') {
        $original = function_exists('ap_masto_announce_original_actor')
            ? ap_masto_announce_original_actor($row)
            : null;
        if (($original === null || $original === '') && !empty($row['target_actor'])) {
            $original = (string) $row['target_actor'];
        }
        if (is_string($original) && $original !== '') {
            return function_exists('ap_row_is_hidden')
                ? ap_row_is_hidden($original, null, $ownerUserId)
                : (function_exists('ap_row_is_blocked') && ap_row_is_blocked($original, null));
        }
    }
    if (in_array($type, ['quote', 'quotepost'], true)
        && function_exists('ap_quote_post_parent_url')
        && function_exists('ap_masto_actor_url_from_object_url')) {
        $parent = ap_quote_post_parent_url((string) ($row['object_id'] ?? ''));
        $quotedActor = $parent !== null ? ap_masto_actor_url_from_object_url($parent) : null;
        if (is_string($quotedActor) && $quotedActor !== '') {
            return function_exists('ap_row_is_hidden')
                ? ap_row_is_hidden($quotedActor, null, $ownerUserId)
                : (function_exists('ap_row_is_blocked') && ap_row_is_blocked($quotedActor, null));
        }
    }
    return false;
}

/**
 * Optional lightweight diversity pass for discovery/federated views only.
 * Home remains chronological. The switch is off by default until measured.
 *
 * @param list<array<string,mixed>> $statuses
 * @return list<array<string,mixed>>
 */
function ap_timeline_author_diversity(array $statuses, int $limit): array
{
    if (!function_exists('ap_feature_enabled')
        || !ap_feature_enabled('federated_author_diversity', false)
        || count($statuses) < 2) {
        return array_slice($statuses, 0, $limit);
    }
    $out = [];
    $deferred = [];
    $lastActor = '';
    $run = 0;
    foreach ($statuses as $status) {
        $actor = (string) (($status['account']['id'] ?? '') ?: ($status['account']['acct'] ?? ''));
        if ($actor !== '' && $actor === $lastActor && $run >= 3) {
            $deferred[] = $status;
            continue;
        }
        $out[] = $status;
        if ($actor !== '' && $actor === $lastActor) {
            $run++;
        } else {
            $lastActor = $actor;
            $run = 1;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    if (count($out) < $limit) {
        foreach ($deferred as $status) {
            $out[] = $status;
            if (count($out) >= $limit) {
                break;
            }
        }
    }
    return $out;
}

function ap_masto_timeline_events(string $mode, int $limit = 40, ?string $maxId = null, ?string $sinceId = null, bool $onlyMedia = false): array
{
    $startedAt = microtime(true);
    $limit = max(1, min(80, $limit));
    $params = [];
    // Home includes Creates + Announces (boosts) from people we follow.
    // Federated stays Create-focused for the public firehose.
    $typeClause = $mode === 'home'
        ? "type IN ('Create', 'Announce')"
        : "type = 'Create'";
    $where = [
        $typeClause,
        // Allow media-only posts (empty text summary) onto timelines
        "( (summary IS NOT NULL AND summary != '') OR (media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]') OR (spoiler_text IS NOT NULL AND spoiler_text != '') OR (sensitive IS NOT NULL AND sensitive != 0) )",
        "action_taken IN ('log', 'local_observe')",
    ];

    if ($mode === 'home') {
        $following = ap_following_list();
        if (!$following) {
            return [];
        }
        // Exact actor match via IN (uses idx_events_actor_created). Host-wide
        // expansion only for true instance actors (…/actor), NOT Bridgy Fed.
        $actorIds = [];
        $likeHosts = [];
        foreach ($following as $f) {
            $a = rtrim((string) ($f['actor_id'] ?? ''), '/');
            if ($a === '') {
                continue;
            }
            $actorIds[$a] = true;
            $actorIds[$a . '/'] = true;
            $host = (string) ($f['host'] ?? '');
            if ($host === '') {
                $h = parse_url($a, PHP_URL_HOST);
                $host = is_string($h) ? strtolower($h) : '';
            }
            if ($host === '' || str_ends_with($host, 'brid.gy') || $host === 'brid.gy') {
                continue;
            }
            $path = trim((string) (parse_url($a, PHP_URL_PATH) ?? ''), '/');
            // True instance/gateway actors only (…/actor or bare host root)
            if ($path === '' || $path === 'actor') {
                $likeHosts[$host] = true;
            }
        }
        if (!$actorIds && !$likeHosts) {
            return [];
        }
        $actorClause = [];
        if ($actorIds) {
            $ids = array_keys($actorIds);
            $actorClause[] = 'actor_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            array_push($params, ...$ids);
        }
        foreach (array_keys($likeHosts) as $host) {
            $actorClause[] = 'actor_id LIKE ?';
            $params[] = 'https://' . $host . '/%';
        }
        $where[] = '(' . implode(' OR ', $actorClause) . ')';
    }

    if ($onlyMedia) {
        $where[] = "media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]'";
    }

    // Page by created_at (feed order). Derive bounds from max_id/since_id for any
    // status kind — local snowflakes used to be ignored, so deep scroll dropped remotes.
    $beforeSql = null;
    $afterSql = null;
    if ($maxId !== null && $maxId !== '') {
        $beforeAt = ap_masto_status_created_at_by_id((int) $maxId);
        if ($beforeAt !== null && $beforeAt !== '') {
            try {
                $beforeSql = (new DateTimeImmutable($beforeAt))->format('c');
            } catch (Throwable $e) {
                $beforeSql = null;
            }
        }
        if ($beforeSql === null) {
            $eid = ap_masto_event_id_from_status_id((int) $maxId);
            if ($eid !== null) {
                $where[] = 'id < ?';
                $params[] = $eid;
            }
        }
    }
    if ($sinceId !== null && $sinceId !== '') {
        $afterAt = ap_masto_status_created_at_by_id((int) $sinceId);
        if ($afterAt !== null && $afterAt !== '') {
            try {
                $afterSql = (new DateTimeImmutable($afterAt))->format('c');
            } catch (Throwable $e) {
                $afterSql = null;
            }
        }
        if ($afterSql === null) {
            $eid = ap_masto_event_id_from_status_id((int) $sinceId);
            if ($eid !== null) {
                $where[] = 'id > ?';
                $params[] = $eid;
            }
        }
    }
    if ($beforeSql !== null) {
        $where[] = 'created_at < ?';
        $params[] = $beforeSql;
    }
    if ($afterSql !== null) {
        $where[] = 'created_at > ?';
        $params[] = $afterSql;
    }

    // Order by created_at (not id): outbox backfill inserts older posts with
    // newer row ids, which otherwise push real recent inbox Creates out of the
    // over-fetch window and make Home look empty of follows.
    // Over-fetch ×2 (was ×4) — enough for block/dedupe without converting hundreds of rows.
    $sql = 'SELECT * FROM events WHERE ' . implode(' AND ', $where)
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) max($limit * 2, $limit + 10);
    $queryStartedAt = microtime(true);
    $st = ap_db()->prepare($sql);
    $st->execute($params);
    $rows = $st->fetchAll();
    $queryMs = (microtime(true) - $queryStartedAt) * 1000.0;
    $hydrateStartedAt = microtime(true);
    $out = [];
    $seenUri = [];
    $ownerUserId = ap_db_masto_owner_user_id();
    $hiddenCount = 0;
    // Prefetch remote_actors for this window so per-card account builds stay in-memory.
    if (function_exists('ap_remote_actors_prefetch')) {
        $prefetchIds = [];
        foreach ($rows as $row) {
            $aid = rtrim((string) ($row['actor_id'] ?? ''), '/');
            if ($aid !== '') {
                $prefetchIds[$aid] = true;
                $prefetchIds[$aid . '/'] = true;
            }
        }
        if ($prefetchIds !== []) {
            ap_remote_actors_prefetch(array_keys($prefetchIds));
        }
    }
    // Federated only needs a small overflow for diversity — converting 2×limit was expensive.
    $hydrateCap = $mode === 'home' ? $limit : min(count($rows), $limit + 8);
    foreach ($rows as $row) {
        // Apply the authenticated user's personal blocks and mutes to both
        // sides of a boost: the booster and the original author. This mirrors
        // Mastodon timeline behavior and prevents blocked originals returning
        // through someone else's Announce.
        if (ap_timeline_row_is_hidden($row, $ownerUserId)) {
            $hiddenCount++;
            continue;
        }
        $status = ap_masto_status_from_event($row);
        if ($status === null) {
            continue;
        }
        // Dedupe identical AS object URIs (shared-inbox retries)
        $uri = (string) ($status['uri'] ?? $status['url'] ?? $row['object_id'] ?? '');
        $uriKey = rtrim($uri, '/');
        $uriKey = preg_replace('#^http://#i', 'https://', $uriKey) ?? $uriKey;
        if ($uriKey !== '') {
            if (isset($seenUri[$uriKey])) {
                continue;
            }
            $seenUri[$uriKey] = true;
        }
        $out[] = $status;
        if (count($out) >= $hydrateCap) {
            break;
        }
    }
    ap_timeline_perf_log('events', $startedAt, [
        'mode' => $mode,
        'query_ms' => round($queryMs, 1),
        'hydrate_ms' => round((microtime(true) - $hydrateStartedAt) * 1000.0, 1),
        'fetched' => count($rows),
        'hidden' => $hiddenCount,
        'returned' => count($out),
    ]);
    if ($mode !== 'home') {
        $out = ap_timeline_author_diversity($out, $limit);
    }
    return $out;
}

/**
 * Resolve a home-timeline status id (local / event / mention / dm) to created_at (Zulu).
 */
function ap_masto_status_created_at_by_id(int $statusId): ?string
{
    if ($statusId <= 0) {
        return null;
    }
    // Fast path: snowflake encodes time (v2=seconds, v1=ms)
    if ($statusId >= 5000000) {
        $p = ap_masto_parse_public_status_id($statusId);
        if ($p && (int) ($p['ver'] ?? 0) === 2 && isset($p['sec'])) {
            return gmdate('Y-m-d\TH:i:s.000\Z', (int) $p['sec']);
        }
        $ms = (int) ($p['ms'] ?? floor($statusId / 100000));
        return gmdate('Y-m-d\TH:i:s.000\Z', (int) floor($ms / 1000));
    }
    $row = ap_masto_status_by_local_id($statusId);
    if ($row) {
        return ap_masto_format_time((string) ($row['published'] ?? ''));
    }
    $eid = ap_masto_event_id_from_status_id($statusId);
    if ($eid !== null) {
        $st = ap_db()->prepare('SELECT created_at FROM events WHERE id = ?');
        $st->execute([$eid]);
        $er = $st->fetch();
        if (is_array($er)) {
            return ap_masto_format_time((string) ($er['created_at'] ?? ''));
        }
    }
    $mid = ap_masto_mention_id_from_status_id($statusId);
    if ($mid !== null) {
        $st = ap_db()->prepare('SELECT created_at FROM mentions WHERE id = ?');
        $st->execute([$mid]);
        $mr = $st->fetch();
        if (is_array($mr)) {
            return ap_masto_format_time((string) ($mr['created_at'] ?? ''));
        }
    }
    $did = ap_masto_dm_id_from_status_id($statusId);
    if ($did !== null) {
        $dm = ap_dm_by_id($did);
        if ($dm) {
            return ap_masto_format_time((string) ($dm['created_at'] ?? ''));
        }
    }
    return null;
}

/** Resolve any public status id to a local masto_statuses row when applicable. */
function ap_masto_local_row_from_public_id(int $statusId): ?array
{
    $p = ap_masto_parse_public_status_id($statusId);
    if (!$p || $p['type'] !== 'local') {
        return null;
    }
    $row = ap_masto_status_by_local_id($p['db_id']);
    if (!$row) {
        return null;
    }
    if ($statusId < 2000000 || (int) ($p['ver'] ?? 0) === 0) {
        return $row; // legacy
    }
    $t = strtotime((string) ($row['published'] ?? ''));
    if ($t === false) {
        return null;
    }
    $ver = (int) ($p['ver'] ?? 1);
    if ($ver === 2) {
        if (abs($t - (int) ($p['sec'] ?? 0)) > 180) {
            return null;
        }
        return $row;
    }
    // v1
    $ms = (int) ($p['ms'] ?? floor($statusId / 100000));
    if (abs(((int) $t * 1000) - $ms) > 180000) {
        return null;
    }
    return $row;
}

/**
 * Public/federated timeline for this single-user instance: remote Creates plus
 * our own public compose posts, sorted by created_at (deduped by URI).
 *
 * @return list<array<string,mixed>>
 */
function ap_masto_timeline_public_merged(int $limit = 40, ?string $maxId = null, ?string $sinceId = null, bool $onlyMedia = false): array
{
    $limit = max(1, min(80, $limit));
    $before = ($maxId !== null && $maxId !== '') ? ap_masto_status_created_at_by_id((int) $maxId) : null;
    $after = ($sinceId !== null && $sinceId !== '') ? ap_masto_status_created_at_by_id((int) $sinceId) : null;

    // Page remotes with the same cursor — fetching the head then filtering left
    // deep scroll with only local/boost leftovers.
    $remote = ap_masto_timeline_events('federated', max($limit + 10, 40), $maxId, $sinceId, $onlyMedia);
    $ownCap = max(1, min(3, (int) ceil($limit * 0.2)));
    $local = [];
    foreach (ap_masto_statuses_recent(max($ownCap * 3, 12), $before, $after) as $r) {
        $status = ap_masto_status_from_row($r);
        if ($onlyMedia && empty($status['media_attachments'])) {
            continue;
        }
        // Public timeline: skip DMs
        if (($status['visibility'] ?? '') === 'direct') {
            continue;
        }
        $local[] = $status;
    }
    $boosts = [];
    if (!$onlyMedia) {
        $boosts = ap_masto_own_reblogs_as_statuses(max($ownCap * 3, 12), $maxId);
    }

    $all = array_merge($remote, $local, $boosts);
    usort($all, static function ($a, $b) {
        $cmp = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
    });

    $out = [];
    $seenUri = [];
    $seenId = [];
    $ownLocalEmitted = 0;
    $ownBoostEmitted = 0;
    foreach ($all as $status) {
        $ts = (string) ($status['created_at'] ?? '');
        if ($before !== null && $ts !== '' && strcmp($ts, $before) >= 0) {
            continue;
        }
        if ($after !== null && $ts !== '' && strcmp($ts, $after) <= 0) {
            continue;
        }
        $acct = is_array($status['account'] ?? null) ? $status['account'] : [];
        $selfId = ap_masto_session_account_id();
        $isOwn = (string) ($acct['id'] ?? '') === $selfId;
        $isOwnBoost = $isOwn && !empty($status['reblog']);
        $isOwnLocal = $isOwn && empty($status['reblog']);
        if ($isOwnLocal && $ownLocalEmitted >= $ownCap) {
            continue;
        }
        if ($isOwnBoost && $ownBoostEmitted >= $ownCap) {
            continue;
        }
        $sid = (string) ($status['id'] ?? '');
        if ($sid !== '') {
            if (isset($seenId[$sid])) {
                continue;
            }
            $seenId[$sid] = true;
        }
        $uri = rtrim((string) ($status['uri'] ?? $status['url'] ?? ''), '/');
        // Normalize trivial URI variants that caused federated/home duplicates
        $uri = preg_replace('#^http://#i', 'https://', $uri) ?? $uri;
        if ($uri !== '') {
            if (isset($seenUri[$uri])) {
                continue;
            }
            $seenUri[$uri] = true;
        }
        $out[] = $status;
        if ($isOwnLocal) {
            $ownLocalEmitted++;
        } elseif ($isOwnBoost) {
            $ownBoostEmitted++;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Merge own local statuses into home timeline (by created_at).
 * Pagination uses created_at cursors derived from max_id/since_id so mixed
 * local (small ints) and federated (3000000+) ids don't reshuffle the feed.
 *
 * @return list<array<string,mixed>>
 */
function ap_masto_timeline_home_merged(int $limit = 40, ?string $maxId = null, ?string $sinceId = null): array
{
    $startedAt = microtime(true);
    $limit = max(1, min(80, $limit));

    // Translate Mastodon max_id/since_id into time bounds (our ids are not snowflakes).
    $before = null; // exclusive upper bound for scrolling down
    $after = null;  // exclusive lower bound for since_id
    if ($maxId !== null && $maxId !== '') {
        $before = ap_masto_status_created_at_by_id((int) $maxId);
    }
    if ($sinceId !== null && $sinceId !== '') {
        $after = ap_masto_status_created_at_by_id((int) $sinceId);
    }

    // Page follows with the real cursor (head-fetch + PHP filter starved remotes on page 2+).
    $remote = ap_masto_timeline_events('home', max($limit + 10, 40), $maxId, $sinceId, false);
    // Own posts/boosts: spice, not the whole plate — fetch a small cursor window then cap.
    $ownCap = max(1, min(3, (int) ceil($limit * 0.2)));
    $localRows = ap_masto_statuses_recent(max($ownCap * 3, 12), $before, $after);
    $local = [];
    foreach ($localRows as $r) {
        $local[] = ap_masto_status_from_row($r);
    }
    // Own boosts appear as Mastodon reblog wrappers in Home
    $boosts = ap_masto_own_reblogs_as_statuses(max($ownCap * 3, 12), $maxId);

    // Followed hashtags land in Home, but stay a spice mix — not the main course.
    // Cap conversion + deprioritize vs follows/own posts when assembling the page.
    $tagPosts = [];
    try {
        $tagEventCap = min(10, max(4, (int) ceil($limit / 4)));
        foreach (ap_masto_followed_tag_event_rows($tagEventCap) as $row) {
            $st = ap_masto_status_from_event($row);
            if (is_array($st)) {
                $st['_from_followed_tag'] = true;
                $tagPosts[] = $st;
            }
            if (count($tagPosts) >= $tagEventCap) {
                break;
            }
        }
        $followedNames = [];
        foreach (ap_masto_followed_tags(0) as $tag) {
            $tname = ap_masto_normalize_tag_name((string) ($tag['name'] ?? ''));
            if ($tname !== '') {
                $followedNames[] = $tname;
            }
        }
        if ($followedNames) {
            foreach (ap_masto_timeline_hashtag_local($followedNames, min(4, $limit), $maxId) as $st) {
                if (!is_array($st)) {
                    continue;
                }
                $st['_from_followed_tag'] = true;
                $tagPosts[] = $st;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $ownerForDeprio = function_exists('ap_db_masto_owner_user_id')
        ? (int) ap_db_masto_owner_user_id()
        : (function_exists('ap_db_default_owner_user_id') ? (int) ap_db_default_owner_user_id() : 0);
    $deprioSet = ($ownerForDeprio > 0 && function_exists('ap_deprioritized_set_cached'))
        ? ap_deprioritized_set_cached($ownerForDeprio)
        : [];
    $deprioPenaltyHours = function_exists('ap_deprioritize_penalty_seconds')
        ? max(1, (int) round(ap_deprioritize_penalty_seconds() / 3600))
        : 6;

    $statusActorUrl = static function (array $status): string {
        $acct = is_array($status['account'] ?? null) ? $status['account'] : [];
        $acctId = (string) ($acct['id'] ?? '');
        if ($acctId !== '' && function_exists('ap_masto_actor_id_from_account_id')) {
            $url = (string) (ap_masto_actor_id_from_account_id($acctId) ?? '');
            if ($url !== '') {
                return rtrim($url, '/');
            }
        }
        foreach (['url', 'uri'] as $k) {
            $u = (string) ($acct[$k] ?? '');
            if (str_starts_with($u, 'https://')) {
                return rtrim($u, '/');
            }
        }
        return '';
    };

    $rankTs = static function (array $status) use ($deprioSet, $deprioPenaltyHours, $statusActorUrl): string {
        $ts = (string) ($status['created_at'] ?? '');
        if ($ts === '') {
            return '';
        }
        $penaltyH = 0;
        // Tag-only posts sort as if ~4h older so follows stay more prominent.
        if (!empty($status['_from_followed_tag'])) {
            $penaltyH += 4;
        }
        // Overflow "Deprioritize" — Home soft-rank only (Federated uses a different path).
        if ($deprioSet) {
            $actorUrl = $statusActorUrl($status);
            if ($actorUrl !== '' && (!empty($deprioSet[$actorUrl]) || !empty($deprioSet[$actorUrl . '/']))) {
                $penaltyH += $deprioPenaltyHours;
            }
        }
        if ($penaltyH > 0) {
            try {
                $dt = new DateTimeImmutable($ts);
                return $dt->modify('-' . $penaltyH . ' hours')->format('Y-m-d\TH:i:s.000\Z');
            } catch (Throwable $e) {
                return $ts;
            }
        }
        return $ts;
    };

    $all = array_merge($remote, $local, $boosts, $tagPosts);
    usort($all, static function ($a, $b) use ($rankTs) {
        $cmp = strcmp($rankTs($b), $rankTs($a));
        if ($cmp !== 0) {
            return $cmp;
        }
        return strcmp((string) ($b['id'] ?? ''), (string) ($a['id'] ?? ''));
    });

    $out = [];
    $seenUri = [];
    $seenId = [];
    $tagEmitted = 0;
    $primarySinceTag = 0;
    $ownLocalEmitted = 0;
    $ownBoostEmitted = 0;
    $maxTagShare = 0.25;
    $deprioEmitted = 0;
    $primarySinceDeprio = 0;
    // Exclusive lists: members are removed from Home (Mastodon exclusive lists).
    $exclusiveActors = function_exists('ap_list_exclusive_actor_map')
        ? ap_list_exclusive_actor_map()
        : [];
    foreach ($all as $status) {
        $ts = (string) ($status['created_at'] ?? '');
        if ($before !== null && $ts !== '' && strcmp($ts, $before) >= 0) {
            continue; // not older than max_id cursor
        }
        if ($after !== null && $ts !== '' && strcmp($ts, $after) <= 0) {
            continue; // not newer than since_id cursor
        }
        $acct = is_array($status['account'] ?? null) ? $status['account'] : [];
        if ($exclusiveActors) {
            $acctId = (string) ($acct['id'] ?? '');
            $actorUrl = '';
            if ($acctId !== '' && function_exists('ap_masto_actor_id_from_account_id')) {
                $actorUrl = (string) (ap_masto_actor_id_from_account_id($acctId) ?? '');
            }
            $actorUrl = rtrim($actorUrl, '/');
            // Drop Home posts authored by exclusive-list members (Mastodon exclusive lists).
            if ($actorUrl !== '' && !empty($exclusiveActors[$actorUrl])) {
                continue;
            }
        }
        $selfId = ap_masto_session_account_id();
        $isOwn = (string) ($acct['id'] ?? '') === $selfId;
        $isOwnBoost = $isOwn && !empty($status['reblog']);
        $isOwnLocal = $isOwn && empty($status['reblog']) && empty($status['_from_followed_tag']);
        if ($isOwnLocal && $ownLocalEmitted >= $ownCap) {
            continue;
        }
        if ($isOwnBoost && $ownBoostEmitted >= $ownCap) {
            continue;
        }
        $sid = (string) ($status['id'] ?? '');
        if ($sid !== '') {
            if (isset($seenId[$sid])) {
                continue;
            }
            $seenId[$sid] = true;
        }
        $uri = rtrim((string) ($status['uri'] ?? $status['url'] ?? ''), '/');
        // Normalize trivial URI variants that caused federated/home duplicates
        $uri = preg_replace('#^http://#i', 'https://', $uri) ?? $uri;
        if ($uri !== '') {
            if (isset($seenUri[$uri])) {
                continue;
            }
            $seenUri[$uri] = true;
        }
        $isTag = !empty($status['_from_followed_tag']);
        $actorUrl = $statusActorUrl($status);
        $isDeprio = $deprioSet
            && $actorUrl !== ''
            && (!empty($deprioSet[$actorUrl]) || !empty($deprioSet[$actorUrl . '/']));
        if ($isTag) {
            // Prefer follows: require a few primary posts between tags + hard ratio cap.
            if ($primarySinceTag < 3 && count($out) > 0) {
                continue;
            }
            if (($tagEmitted + 1) / max(1, count($out) + 1) > $maxTagShare) {
                continue;
            }
        }
        if ($isDeprio && !$isTag) {
            // At most ~1 deprioritized post per 5 Home slots.
            if ($primarySinceDeprio < 4 && count($out) > 0) {
                continue;
            }
            if (($deprioEmitted + 1) / max(1, count($out) + 1) > 0.2) {
                continue;
            }
        }
        // Strip internal flag before JSON leaves the API
        unset($status['_from_followed_tag']);
        $out[] = $status;
        if ($isTag) {
            $tagEmitted++;
            $primarySinceTag = 0;
        } else {
            $primarySinceTag++;
        }
        if ($isDeprio) {
            $deprioEmitted++;
            $primarySinceDeprio = 0;
        } else {
            $primarySinceDeprio++;
        }
        if ($isOwnLocal) {
            $ownLocalEmitted++;
        } elseif ($isOwnBoost) {
            $ownBoostEmitted++;
        }
        if (count($out) >= $limit) {
            break;
        }
    }
    ap_timeline_perf_log('home-merge', $startedAt, [
        'limit' => $limit,
        'remote' => count($remote),
        'local' => count($local),
        'boosts' => count($boosts),
        'tags' => count($tagPosts),
        'returned' => count($out),
    ]);
    return $out;
}

/**
 * Write timeline markers. Notification (and home) last_read_id only advances —
 * never regresses. Ice Cubes / other clients often POST a stale last_read after
 * VAAK already marked newer items read, which resurrected the nav badge.
 */
function ap_masto_markers_set(array $input, ?int $ownerUserId = null): object
{
    $ownerUserId = $ownerUserId ?? ap_db_default_owner_user_id();
    $writeMarker = static function (int $ownerUserId, string $tl, string $last) : void {
        $last = preg_replace('/\D+/', '', $last) ?: '';
        if ($last === '' || $last === '0') {
            return;
        }
        $now = ap_db_now();
        // Read existing so we can keep the newer snowflake.
        $existing = '0';
        try {
            $st = ap_db()->prepare(
                'SELECT last_read_id FROM masto_markers WHERE owner_user_id = ? AND timeline = ? LIMIT 1'
            );
            $st->execute([$ownerUserId, $tl]);
            $existing = preg_replace('/\D+/', '', (string) ($st->fetchColumn() ?: '0')) ?: '0';
        } catch (Throwable $e) {
            $existing = '0';
        }
        $best = function_exists('ap_masto_snowflake_newest')
            ? ap_masto_snowflake_newest($existing, $last)
            : $last;
        if ($best === '' || $best === '0') {
            return;
        }
        ap_db()->prepare(
            'INSERT INTO masto_markers (owner_user_id, timeline, last_read_id, updated_at) VALUES (?, ?, ?, ?)
             ON CONFLICT(owner_user_id, timeline) DO UPDATE SET
               last_read_id = excluded.last_read_id, updated_at = excluded.updated_at'
        )->execute([$ownerUserId, $tl, $best, $now]);
        // Drop unread badge caches when notifications marker moves forward.
        if ($tl === 'notifications' && $best !== $existing) {
            try {
                $cacheDir = '/var/lib/mkultra/ap';
                if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
                    $cacheDir = sys_get_temp_dir();
                }
                foreach (glob($cacheDir . '/notif_unread_' . (int) $ownerUserId . '_*.json') ?: [] as $path) {
                    @unlink($path);
                }
            } catch (Throwable $e) {
                // non-fatal
            }
        }
    };

    foreach (['notifications', 'home'] as $tl) {
        if (!isset($input[$tl]) || !is_array($input[$tl])) {
            continue;
        }
        $last = (string) ($input[$tl]['last_read_id'] ?? '');
        if ($last === '') {
            continue;
        }
        $writeMarker($ownerUserId, $tl, $last);
    }
    foreach ($input as $key => $val) {
        if (!is_string($key)) {
            continue;
        }
        if (preg_match('/^(notifications|home)\[last_read_id\]$/', $key, $m) && is_string($val) && $val !== '') {
            $writeMarker($ownerUserId, $m[1], $val);
        }
    }
    return ap_masto_markers_get($ownerUserId);
}

/* ----------------- Direct messages (Mastodon entities) ----------------- */

function ap_masto_dm_status_id(int $dmId, ?string $createdAt = null): string
{
    if ($createdAt === null || $createdAt === '') {
        return (string) (4000000 + $dmId);
    }
    return ap_masto_snowflake_id($createdAt, $dmId, 3);
}

function ap_masto_dm_id_from_status_id(int $statusId): ?int
{
    $p = ap_masto_parse_public_status_id($statusId);
    if (!$p || $p['type'] !== 'dm') {
        return null;
    }
    if ($statusId >= 4000000 && $statusId < 5000000) {
        return $statusId - 4000000;
    }
    $ver = (int) ($p['ver'] ?? 1);
    if ($ver === 2) {
        return (int) $p['db_id'];
    }
    return ap_masto_disambiguate_mod_id(
        'direct_messages',
        (int) $p['db_id'],
        (int) ($p['ms'] ?? 0),
        'created_at',
        true
    );
}

function ap_masto_status_from_dm(array $row): array
{
    $dmId = (int) ($row['id'] ?? 0);
    $peer = (string) ($row['peer_actor_id'] ?? '');
    $direction = (string) ($row['direction'] ?? 'in');
    $ownerActor = rtrim((string) ($row['owner_actor_id'] ?? ''), '/');
    if ($ownerActor === '') {
        $ownerActor = ap_masto_session_actor_id();
    }
    $selfAcct = ap_masto_account_for_local_url($ownerActor);
    $account = $direction === 'out' ? $selfAcct : ap_masto_remote_account($peer);
    $raw = (string) ($row['content'] ?? '');
    $objectId = (string) ($row['object_id'] ?? '');
    $media = [];
    if (!empty($row['media_urls'])) {
        $decoded = json_decode((string) $row['media_urls'], true);
        if (is_array($decoded)) {
            $i = 0;
            foreach ($decoded as $u) {
                $clean = is_string($u) ? ap_profile_sanitize_https_url($u) : null;
                if ($clean === null) {
                    continue;
                }
                $i++;
                $media[] = [
                    'id' => ap_masto_dm_status_id($dmId, isset($row['created_at']) ? (string) $row['created_at'] : null) . $i,
                    'type' => 'image',
                    'url' => $clean,
                    'preview_url' => $clean,
                    'remote_url' => $clean,
                    'preview_remote_url' => null,
                    'text_url' => null,
                    'meta' => null,
                    'description' => null,
                    'blurhash' => null,
                ];
                if ($i >= 4) {
                    break;
                }
            }
        }
    }
    $extraActors = [];
    if ($peer !== '') {
        $extraActors[] = $peer;
    }
    $extraActors[] = $ownerActor;

    // Bridgy digests + Mastodon HTML: keep safe <a href> (Ice Cubes renders content HTML)
    $actionHtml = '';
    if (!empty($row['attachments_json']) && function_exists('ap_dm_action_links_html')) {
        $atts = json_decode((string) $row['attachments_json'], true);
        if (is_array($atts) && !str_contains($raw, 'class="dm-actions"')) {
            $actionHtml = ap_dm_action_links_html($atts);
        }
    }
    $hasStoredHtml = $raw !== '' && (
        str_contains($raw, '<a ') || str_contains($raw, '<p>') || str_contains($raw, '<ul')
    );
    if ($hasStoredHtml && function_exists('ap_dm_sanitize_html')) {
        $html = ap_dm_sanitize_html($raw);
        if ($actionHtml !== '' && !str_contains($html, 'class="dm-actions"')) {
            $html = ap_dm_sanitize_html($html . "\n" . $actionHtml);
        }
        $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $pack = ap_masto_content_with_mentions($text, $extraActors);
        if ($html === '' || $html === '<p></p>') {
            $html = $text !== '' ? (string) ($pack['content'] ?? '<p></p>') : '<p></p>';
        }
    } else {
        $text = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $pack = ap_masto_content_with_mentions($text, $extraActors);
        $html = $text !== '' ? (string) ($pack['content'] ?? '<p></p>') : '<p></p>';
        if ($text !== '' && (trim(strip_tags($html)) === '' || $html === '<p></p>')) {
            $html = '<p>' . nl2br(htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
        }
        if ($actionHtml !== '') {
            $html = function_exists('ap_dm_sanitize_html')
                ? ap_dm_sanitize_html($html . "\n" . $actionHtml)
                : ($html . "\n" . $actionHtml);
        }
    }
    // Ice Cubes renders status content as HTML — autolink any bare URLs
    if (function_exists('ap_dm_linkify_html')) {
        $html = ap_dm_linkify_html($html);
    }
    if (function_exists('ap_dm_sanitize_html')) {
        $html = ap_dm_sanitize_html($html);
    }
    $mentions = array_values($pack['mentions'] ?? []);
    if ($mentions === []) {
        if ($direction === 'out' && $peer !== '') {
            $ra = ap_masto_remote_account($peer);
            $mentions[] = [
                'id' => $ra['id'],
                'username' => $ra['username'],
                'url' => $ra['url'],
                'acct' => $ra['acct'],
            ];
        } elseif ($direction === 'in') {
            $mentions[] = [
                'id' => (string) ($selfAcct['id'] ?? ap_masto_session_account_id()),
                'username' => (string) ($selfAcct['username'] ?? ''),
                'url' => (string) ($selfAcct['url'] ?? $ownerActor),
                'acct' => (string) ($selfAcct['acct'] ?? ''),
            ];
        }
    }
    $status = [
        'id' => ap_masto_dm_status_id($dmId, isset($row['created_at']) ? (string) $row['created_at'] : null),
        'created_at' => ap_masto_format_time(isset($row['created_at']) ? (string) $row['created_at'] : null),
        'in_reply_to_id' => null,
        'in_reply_to_account_id' => null,
        'sensitive' => false,
        'spoiler_text' => '',
        'visibility' => 'direct',
        'language' => 'en',
        'uri' => $objectId !== '' ? $objectId : ($ownerActor . '/notes/dm-' . $dmId),
        'url' => $objectId !== '' ? $objectId : null,
        'replies_count' => 0,
        'reblogs_count' => 0,
        'favourites_count' => 0,
        'edited_at' => null,
        'favourited' => false,
        'reblogged' => false,
        'muted' => false,
        'bookmarked' => false,
        'pinned' => false,
        'content' => $html,
        'reblog' => null,
        'application' => null,
        'account' => $account,
        'media_attachments' => $media,
        'mentions' => $mentions,
        'tags' => $pack['tags'] ?? [],
        'emojis' => [],
        'card' => null,
        'poll' => null,
    ];
    return ap_masto_apply_interaction_flags($status);
}

/**
 * @return list<array<string,mixed>>
 */
function ap_masto_conversations_list(int $limit = 40): array
{
    $out = [];
    foreach (ap_dm_conversations($limit) as $c) {
        $peer = $c['peer_actor_id'];
        $last = $c['last'];
        $out[] = [
            'id' => ap_dm_conversation_id($peer),
            'unread' => ((int) $c['unread']) > 0,
            'accounts' => [ap_masto_remote_account($peer)],
            'last_status' => ap_masto_status_from_dm($last),
        ];
    }
    return $out;
}

/**
 * @return list<array<string,mixed>>
 */
function ap_masto_direct_timeline(int $limit = 40, ?string $maxId = null): array
{
    $limit = max(1, min(80, $limit));
    $rows = ap_dm_list_recent($limit * 2);
    $out = [];
    foreach ($rows as $row) {
        $sid = (int) ap_masto_dm_status_id((int) $row['id'], isset($row['created_at']) ? (string) $row['created_at'] : null);
        if ($maxId !== null && $maxId !== '' && $sid >= (int) $maxId) {
            continue;
        }
        $out[] = ap_masto_status_from_dm($row);
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Accounts that follow us / we follow (Ice Cubes profile Followers / Following tabs).
 *
 * @param 'followers'|'following' $which
 * @return list<array<string,mixed>>
 */
function ap_masto_account_follow_list(string $which, int $limit = 40, ?string $ownerActorId = null): array
{
    $limit = max(1, min(80, $limit));
    $owner = rtrim((string) ($ownerActorId ?? ''), '/');
    if ($owner === '') {
        $owner = rtrim(function_exists('ap_local_actor_id') ? ap_local_actor_id() : 'https://mkultra.monster/users/cmdr_nova', '/');
    }
    $rows = $which === 'followers' ? ap_followers_list($owner) : ap_following_list($owner);
    $out = [];
    $seenAccountIds = [];
    foreach ($rows as $row) {
        $actorId = (string) ($row['actor_id'] ?? '');
        if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
            continue;
        }
        // Skip self if somehow present
        if (rtrim($actorId, '/') === $owner) {
            continue;
        }
        $acct = ap_masto_remote_account($actorId);
        $aid = (string) ($acct['id'] ?? '');
        if ($aid !== '' && isset($seenAccountIds[$aid])) {
            continue;
        }
        if ($aid !== '') {
            $seenAccountIds[$aid] = true;
        }
        $out[] = $acct;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Mastodon-compatible search over local federation data + optional WebFinger resolve.
 *
 * @return array{accounts:list<array<string,mixed>>,statuses:list<array<string,mixed>>,hashtags:list<array<string,mixed>>}
 */
/**
 * Keep only accounts we currently follow (Mastodon search ?following=true).
 *
 * @param list<array<string,mixed>> $accounts
 * @return list<array<string,mixed>>
 */
function ap_masto_filter_accounts_following(array $accounts): array
{
    $out = [];
    foreach ($accounts as $a) {
        if (!is_array($a)) {
            continue;
        }
        $id = (string) ($a['id'] ?? '');
        $selfId = function_exists('ap_masto_session_account_id') ? ap_masto_session_account_id() : '1';
        if ($id === '' || $id === $selfId) {
            continue;
        }
        $rel = ap_masto_relationship_for_account_id($id);
        if (!empty($rel['following'])) {
            $out[] = $a;
        }
    }
    return $out;
}

function ap_masto_search(string $q, ?string $type = null, bool $resolve = false, int $limit = 20): array
{
    $q = trim(ap_fix_utf8($q));
    // Bound attacker-controlled query size (DoS / pathological LIKE)
    if (mb_strlen($q) > 200) {
        $q = mb_substr($q, 0, 200);
    }
    $limit = max(1, min(40, $limit));
    $type = $type !== null && $type !== '' ? strtolower(trim($type)) : null;
    if ($type !== null && !in_array($type, ['accounts', 'hashtags', 'statuses'], true)) {
        $type = null;
    }
    $parts = ap_masto_search_query_parts($q);
    $wantAccounts = $type === null || $type === 'accounts';
    $wantTags = $type === null || $type === 'hashtags';
    $wantStatuses = $type === null || $type === 'statuses';
    // Instance-name queries (misskey.io / @misskey.io) → accounts on that host.
    // Skip expensive tag scans / keyword status search unless explicitly requested.
    if (!empty($parts['is_host']) && $type === null) {
        $wantTags = false;
        $wantStatuses = false;
    }

    $accounts = $wantAccounts ? ap_masto_search_accounts($q, $resolve, $limit) : [];
    $hashtags = $wantTags ? ap_masto_search_hashtags($q, $limit) : [];
    $statuses = $wantStatuses ? ap_masto_search_statuses($q, $limit) : [];
    // Flatten boost wrappers + fix ids/meta so Ice Cubes SearchResults (All) decodes
    $statuses = ap_masto_search_sanitize_statuses($statuses);
    $accounts = ap_masto_search_sanitize_accounts($accounts);

    return [
        'accounts' => $accounts,
        'statuses' => $statuses,
        'hashtags' => $hashtags,
    ];
}

/**
 * @param list<array<string,mixed>> $accounts
 * @return list<array<string,mixed>>
 */
function ap_masto_search_sanitize_accounts(array $accounts): array
{
    $out = [];
    $seenIds = [];
    foreach ($accounts as $a) {
        if (!is_array($a)) {
            continue;
        }
        foreach (['avatar', 'header', 'avatar_static', 'header_static'] as $k) {
            $u = (string) ($a[$k] ?? '');
            if ($u === '' || !str_starts_with($u, 'https://')) {
                $a[$k] = 'https://mkultra.monster/img/avatar/default.jpg';
            }
        }
        if (!is_string($a['note'] ?? null)) {
            $a['note'] = '';
        }
        if (!is_array($a['fields'] ?? null)) {
            $a['fields'] = [];
        }
        if (!is_array($a['emojis'] ?? null)) {
            $a['emojis'] = [];
        }
        if (!isset($a['bot'])) {
            $a['bot'] = false;
        }
        if (!isset($a['locked'])) {
            $a['locked'] = false;
        }
        if (empty($a['created_at']) || !is_string($a['created_at'])) {
            $a['created_at'] = '2020-01-01T00:00:00.000Z';
        }
        // Drop keys Ice Cubes Account doesn't model (harmless, but keep payload lean)
        unset($a['group'], $a['uri'], $a['noindex'], $a['roles'], $a['indexable']);
        if (empty($a['id']) || empty($a['username']) || empty($a['acct'])) {
            continue;
        }
        // Hide numeric Threads-style usernames (and any all-digit handle) from search UX
        $username = (string) $a['username'];
        if (preg_match('/^\d+$/', $username)) {
            continue;
        }
        $id = (string) $a['id'];
        if (isset($seenIds[$id])) {
            continue;
        }
        $seenIds[$id] = true;
        $out[] = $a;
    }
    return $out;
}

/**
 * Make status search hits Ice Cubes–safe:
 * - Flatten Announce wrappers to the inner post (search matched the text, not the boost shell)
 * - Unique ids, null media meta, drop nested reblog on inners
 *
 * @param list<array<string,mixed>> $statuses
 * @return list<array<string,mixed>>
 */
function ap_masto_search_sanitize_statuses(array $statuses): array
{
    $out = [];
    $seenIds = [];
    $seenUris = [];
    foreach ($statuses as $st) {
        if (!is_array($st)) {
            continue;
        }
        // Prefer the post that actually contains the matched text
        if (!empty($st['reblog']) && is_array($st['reblog'])) {
            $st = $st['reblog'];
        }
        // Inners must not carry a nested reblog key (ReblogStatus has no reblog field)
        unset($st['reblog']);
        $st['reblog'] = null;

        $id = (string) ($st['id'] ?? '');
        if ($id === '' || isset($seenIds[$id])) {
            continue;
        }

        // Canonical object URL — Create + Announce of the same Note share this.
        // Strip #announce-… / interaction fragments so both collapse to one hit.
        $uri = (string) ($st['uri'] ?? $st['url'] ?? '');
        $uri = rtrim($uri, '/');
        if ($uri !== '' && str_contains($uri, '#')) {
            $uri = rtrim((string) (preg_replace('/#.*$/', '', $uri) ?? $uri), '/');
            $st['uri'] = $uri;
            if (!empty($st['url']) && str_contains((string) $st['url'], '#')) {
                $st['url'] = $uri;
            }
        }
        if ($uri !== '' && isset($seenUris[$uri])) {
            continue;
        }

        $seenIds[$id] = true;
        if ($uri !== '') {
            $seenUris[$uri] = true;
        }

        // Media meta: null not {} — some clients type-mismatch empty objects oddly
        if (!empty($st['media_attachments']) && is_array($st['media_attachments'])) {
            foreach ($st['media_attachments'] as $i => $m) {
                if (!is_array($m)) {
                    continue;
                }
                $st['media_attachments'][$i]['meta'] = null;
            }
        }

        if (!array_key_exists('quotes_count', $st)) {
            $st['quotes_count'] = 0;
        }
        if (!isset($st['account']) || !is_array($st['account'])) {
            continue;
        }
        // Required Ice Cubes Account arrays
        if (!isset($st['account']['fields']) || !is_array($st['account']['fields'])) {
            $st['account']['fields'] = [];
        }
        if (!isset($st['account']['emojis']) || !is_array($st['account']['emojis'])) {
            $st['account']['emojis'] = [];
        }
        if (!is_string($st['account']['note'] ?? null)) {
            $st['account']['note'] = '';
        }
        if (!is_array($st['tags'] ?? null)) {
            $st['tags'] = [];
        }
        if (!is_array($st['mentions'] ?? null)) {
            $st['mentions'] = [];
        }
        if (!is_array($st['emojis'] ?? null)) {
            $st['emojis'] = [];
        }
        if (!is_array($st['media_attachments'] ?? null)) {
            $st['media_attachments'] = [];
        }

        $out[] = $st;
        if (count($out) >= 40) {
            break;
        }
    }
    return $out;
}

/**
 * Normalize a search query for account/host matching.
 * Mastodon-style "@misskey.io" / "misskey.io" should hit host, not keep the leading @.
 *
 * @return array{raw:string,lower:string,host:?string,is_host:bool,handle:?string}
 */
function ap_masto_search_query_parts(string $q): array
{
    $q = trim(ap_fix_utf8($q));
    if (mb_strlen($q) > 200) {
        $q = mb_substr($q, 0, 200);
    }
    $raw = $q;
    $lower = mb_strtolower($q);
    $host = null;
    $isHost = false;
    $handle = null;

    // Full @user@host
    if (preg_match('/^@?([A-Za-z0-9_.\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,})$/', $q, $m)) {
        $handle = $m[1];
        $hostPart = explode('@', $handle, 2)[1] ?? '';
        $host = $hostPart !== '' ? mb_strtolower($hostPart) : null;
        // Prefer bare user@host for LIKE matching (strip leading @)
        $raw = $handle;
        $lower = mb_strtolower($handle);
    } else {
        // Domain-only: misskey.io, @misskey.io, https://misskey.io/...
        $candidate = $q;
        if (preg_match('#^https?://([^/]+)#i', $candidate, $m)) {
            $candidate = $m[1];
        }
        $candidate = ltrim($candidate, '@');
        $candidate = preg_replace('#^www\.#i', '', $candidate) ?? $candidate;
        if (preg_match('/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $candidate)
            && !str_contains($candidate, '@')
            && substr_count($candidate, '.') >= 1
        ) {
            $host = mb_strtolower($candidate);
            $isHost = true;
            // Prefer searching without the leading @ so LIKE host matches work
            $lower = $host;
            $raw = $host;
        }
    }

    return [
        'raw' => $raw,
        'lower' => $lower,
        'host' => $host,
        'is_host' => $isHost,
        'handle' => $handle,
    ];
}

/** @return list<array<string,mixed>> */
function ap_masto_search_accounts(string $q, bool $resolve, int $limit): array
{
    if (trim($q) === '') {
        return [];
    }
    $parts = ap_masto_search_query_parts($q);
    $q = $parts['raw'];
    $qLower = $parts['lower'];
    $seen = [];
    $out = [];

    $addActor = static function (string $actorId) use (&$seen, &$out, $limit): void {
        $actorId = rtrim(trim($actorId), '/');
        if ($actorId === '' || isset($seen['u:' . $actorId]) || count($out) >= $limit) {
            return;
        }
        if (!str_starts_with($actorId, 'https://')) {
            return;
        }
        // Never return non-https or javascript: etc. — already gated above
        $seen['u:' . $actorId] = true;
        if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', rtrim($actorId, '/'), $lm)) {
            try {
                $st = ap_db()->prepare(
                    'SELECT * FROM ap_users WHERE lower(actor_key) = ? AND disabled_at IS NULL LIMIT 1'
                );
                $st->execute([strtolower($lm[1])]);
                $urow = $st->fetch();
                if (is_array($urow) && function_exists('ap_masto_account_from_user')) {
                    $acct = ap_masto_account_from_user($urow);
                    $aid = (string) ($acct['id'] ?? '');
                    if ($aid !== '' && isset($seen['id:' . $aid])) {
                        return;
                    }
                    if ($aid !== '') {
                        $seen['id:' . $aid] = true;
                    }
                    $out[] = $acct;
                    return;
                }
            } catch (Throwable $e) {
                // fall through to remote/local fallback
            }
            if (strtolower($lm[1]) === 'cmdr_nova') {
                $seen['id:1'] = true;
                $out[] = ap_masto_account_from_user([
                    'id' => 1,
                    'actor_key' => 'cmdr_nova',
                    'username' => 'cmdr_nova',
                    'actor_id' => 'https://mkultra.monster/users/cmdr_nova',
                ]);
                return;
            }
        }
        $acct = ap_masto_remote_account($actorId);
        $aid = (string) ($acct['id'] ?? '');
        // Same person under /users/name and /ap/users/snowflake → one search hit
        if ($aid !== '' && isset($seen['id:' . $aid])) {
            return;
        }
        if ($aid !== '') {
            $seen['id:' . $aid] = true;
        }
        $out[] = $acct;
    };

    // Exact / handle resolve first when asked (Ice Cubes “add account”).
    // Only @user@host — never raw URLs/IPs (SSRF surface stays in ap_resolve_actor_ref + public-DNS checks).
    $handle = $parts['handle'] ?? ltrim($q, '@');
    if ($resolve && is_string($handle) && preg_match('/^[A-Za-z0-9_.\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}$/', $handle)) {
        if (!function_exists('ap_resolve_actor_ref')) {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
        }
        if (function_exists('ap_resolve_actor_ref')) {
            $resolved = ap_resolve_actor_ref('@' . $handle);
            if (is_string($resolved) && $resolved !== '') {
                if (function_exists('ap_fetch_actor_doc')) {
                    $doc = ap_fetch_actor_doc($resolved);
                    if (is_array($doc)) {
                        $canon = function_exists('ap_as_id') ? ap_as_id($doc['id'] ?? null) : null;
                        $resolved = is_string($canon) && $canon !== '' ? $canon : $resolved;
                        if (function_exists('ap_remote_actor_upsert')) {
                            $uname = isset($doc['preferredUsername']) && is_string($doc['preferredUsername'])
                                ? $doc['preferredUsername'] : null;
                            $dname = isset($doc['name']) && is_string($doc['name']) ? $doc['name'] : $uname;
                            $host = parse_url($resolved, PHP_URL_HOST);
                            $icon = null;
                            if (is_array($doc['icon'] ?? null) && is_string($doc['icon']['url'] ?? null)) {
                                $icon = $doc['icon']['url'];
                            } elseif (is_string($doc['icon'] ?? null)) {
                                $icon = $doc['icon'];
                            }
                            $image = null;
                            if (is_array($doc['image'] ?? null) && is_string($doc['image']['url'] ?? null)) {
                                $image = $doc['image']['url'];
                            }
                            ap_remote_actor_upsert($resolved, [
                                'username' => $uname,
                                'display_name' => $dname,
                                'host' => is_string($host) ? $host : null,
                                'icon_source_url' => $icon,
                                'image_source_url' => $image,
                            ]);
                        }
                    }
                }
                $addActor($resolved);
            }
        }
    }

    // Local accounts (all ap_users) — never hard-code only cmdr_nova as "you"
    try {
        $st = ap_db()->prepare(
            "SELECT actor_id FROM ap_users
             WHERE disabled_at IS NULL
               AND (
                 lower(username) LIKE ? ESCAPE '\\'
                 OR lower(actor_key) LIKE ? ESCAPE '\\'
                 OR lower(actor_id) LIKE ? ESCAPE '\\'
               )
             ORDER BY id ASC LIMIT ?"
        );
        $localLike = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $qLower) . '%';
        $st->execute([$localLike, $localLike, $localLike, $limit]);
        foreach ($st->fetchAll() as $row) {
            $addActor((string) ($row['actor_id'] ?? ''));
        }
    } catch (Throwable $e) {
        // fall back below
    }
    if (str_contains('cmdr_nova', $qLower)
        || str_contains('mkultra.monster', $qLower)
        || str_contains('@cmdr_nova@mkultra.monster', $qLower)
        || str_contains('https://mkultra.monster/users/cmdr_nova', $qLower)
    ) {
        $addActor('https://mkultra.monster/users/cmdr_nova');
    }

    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $qLower) . '%';
    $hostExact = is_string($parts['host'] ?? null) ? (string) $parts['host'] : '';

    // Instance / host browse (Mastodon-style typing an instance name).
    // Prefer exact host match from remote_actors / following / followers / events.
    if ($hostExact !== '') {
        try {
            $st = ap_db()->prepare(
                "SELECT actor_id FROM remote_actors
                 WHERE lower(COALESCE(host,'')) = ?
                 ORDER BY updated_at DESC LIMIT ?"
            );
            $st->execute([$hostExact, $limit * 2]);
            foreach ($st->fetchAll() as $row) {
                $addActor((string) ($row['actor_id'] ?? ''));
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $st = ap_db()->prepare(
                "SELECT actor_id FROM following WHERE lower(COALESCE(host,'')) = ? LIMIT ?"
            );
            $st->execute([$hostExact, $limit * 2]);
            foreach ($st->fetchAll() as $row) {
                $addActor((string) ($row['actor_id'] ?? ''));
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $st = ap_db()->prepare(
                "SELECT actor_id FROM followers WHERE lower(COALESCE(host,'')) = ? LIMIT ?"
            );
            $st->execute([$hostExact, $limit * 2]);
            foreach ($st->fetchAll() as $row) {
                $addActor((string) ($row['actor_id'] ?? ''));
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $st = ap_db()->prepare(
                "SELECT DISTINCT actor_id FROM events
                 WHERE actor_id IS NOT NULL AND lower(COALESCE(host,'')) = ?
                 ORDER BY id DESC LIMIT ?"
            );
            $st->execute([$hostExact, $limit * 3]);
            foreach ($st->fetchAll() as $row) {
                $addActor((string) ($row['actor_id'] ?? ''));
            }
        } catch (Throwable $e) {
            // ignore
        }
        // Host-only queries: don't also run fuzzy username LIKE for "@misskey.io"
        // (that would match nothing useful and dilute results). Fall through only
        // when we still have room and this wasn't purely an instance browse.
        if (!empty($parts['is_host']) && count($out) > 0) {
            return array_slice($out, 0, $limit);
        }
        if (!empty($parts['is_host'])) {
            return array_slice($out, 0, $limit);
        }
    }

    try {
        $st = ap_db()->prepare(
            "SELECT actor_id FROM remote_actors
             WHERE lower(COALESCE(username,'')) LIKE ? ESCAPE '\\'
                OR lower(COALESCE(display_name,'')) LIKE ? ESCAPE '\\'
                OR lower(COALESCE(host,'')) LIKE ? ESCAPE '\\'
                OR lower(actor_id) LIKE ? ESCAPE '\\'
             ORDER BY updated_at DESC LIMIT ?"
        );
        $st->execute([$like, $like, $like, $like, $limit * 2]);
        foreach ($st->fetchAll() as $row) {
            $addActor((string) ($row['actor_id'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }

    // followers has username; following is actor_id + host only
    try {
        $st = ap_db()->prepare(
            "SELECT actor_id FROM followers
             WHERE lower(COALESCE(username,'')) LIKE ? ESCAPE '\\'
                OR lower(COALESCE(host,'')) LIKE ? ESCAPE '\\'
                OR lower(actor_id) LIKE ? ESCAPE '\\'
             LIMIT ?"
        );
        $st->execute([$like, $like, $like, $limit * 2]);
        foreach ($st->fetchAll() as $row) {
            $addActor((string) ($row['actor_id'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $st = ap_db()->prepare(
            "SELECT actor_id FROM following
             WHERE lower(COALESCE(host,'')) LIKE ? ESCAPE '\\'
                OR lower(actor_id) LIKE ? ESCAPE '\\'
             LIMIT ?"
        );
        $st->execute([$like, $like, $limit * 2]);
        foreach ($st->fetchAll() as $row) {
            $addActor((string) ($row['actor_id'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Actors seen in mentions / events
    try {
        $st = ap_db()->prepare(
            "SELECT DISTINCT actor_id FROM mentions
             WHERE deleted_at IS NULL AND actor_id IS NOT NULL
               AND (lower(actor_id) LIKE ? ESCAPE '\\' OR lower(COALESCE(content,'')) LIKE ? ESCAPE '\\')
             ORDER BY id DESC LIMIT ?"
        );
        $st->execute([$like, $like, $limit * 2]);
        foreach ($st->fetchAll() as $row) {
            $addActor((string) ($row['actor_id'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }

    return array_slice($out, 0, $limit);
}

/** @return list<array<string,mixed>> */
function ap_masto_search_hashtags(string $q, int $limit): array
{
    $q = ltrim(trim($q), '#');
    $qLower = mb_strtolower($q);
    $counts = [];

    $ingest = static function (string $text) use (&$counts): void {
        if ($text === '') {
            return;
        }
        if (!preg_match_all('/#([\p{L}\p{N}_]{1,100})/u', $text, $m)) {
            return;
        }
        foreach ($m[1] as $tag) {
            $key = mb_strtolower($tag);
            if ($key === '') {
                continue;
            }
            $counts[$key] = ($counts[$key] ?? 0) + 1;
        }
    };

    try {
        foreach (ap_db()->query(
            "SELECT content FROM outbox_notes ORDER BY published DESC LIMIT 400"
        )->fetchAll() as $row) {
            $ingest((string) ($row['content'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        foreach (ap_db()->query(
            "SELECT summary FROM events WHERE summary IS NOT NULL AND summary != '' ORDER BY id DESC LIMIT 600"
        )->fetchAll() as $row) {
            $ingest(html_entity_decode((string) ($row['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        foreach (ap_db()->query(
            "SELECT content FROM mentions WHERE deleted_at IS NULL AND content IS NOT NULL ORDER BY id DESC LIMIT 300"
        )->fetchAll() as $row) {
            $ingest((string) ($row['content'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }

    if ($qLower !== '') {
        $filtered = [];
        foreach ($counts as $name => $uses) {
            if (is_string($name) && str_contains($name, $qLower)) {
                $filtered[$name] = $uses;
            }
        }
        $counts = $filtered;
    }
    arsort($counts, SORT_NUMERIC);
    $out = [];
    foreach ($counts as $name => $uses) {
        $out[] = ap_masto_tag_entity($name);
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/** Normalize hashtag name (no leading #, lowercase). */
function ap_masto_normalize_tag_name(string $name): string
{
    $name = ltrim(trim(ap_fix_utf8($name)), '#');
    $name = mb_strtolower($name);
    // Keep letters/numbers/underscore only (Mastodon-ish)
    $name = preg_replace('/[^\p{L}\p{N}_]/u', '', $name) ?? '';
    return mb_substr($name, 0, 100);
}

function ap_masto_tag_is_following(string $name, ?int $ownerUserId = null): bool
{
    $name = ap_masto_normalize_tag_name($name);
    if ($name === '') {
        return false;
    }
    $ownerUserId = $ownerUserId ?? ap_db_default_owner_user_id();
    try {
        $st = ap_db()->prepare(
            'SELECT 1 FROM masto_followed_tags WHERE owner_user_id = ? AND name = ?'
        );
        $st->execute([$ownerUserId, $name]);
        return (bool) $st->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function ap_masto_tag_follow(string $name, ?int $ownerUserId = null): array
{
    $name = ap_masto_normalize_tag_name($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Invalid tag name'];
    }
    $ownerUserId = $ownerUserId ?? ap_db_default_owner_user_id();
    ap_db()->prepare(
        'INSERT INTO masto_followed_tags (owner_user_id, name, followed_at) VALUES (?, ?, ?)
         ON CONFLICT(owner_user_id, name) DO NOTHING'
    )->execute([$ownerUserId, $name, gmdate('c')]);
    return ['ok' => true, 'tag' => ap_masto_tag_entity($name)];
}

function ap_masto_tag_unfollow(string $name, ?int $ownerUserId = null): array
{
    $name = ap_masto_normalize_tag_name($name);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Invalid tag name'];
    }
    $ownerUserId = $ownerUserId ?? ap_db_default_owner_user_id();
    ap_db()->prepare('DELETE FROM masto_followed_tags WHERE owner_user_id = ? AND name = ?')
        ->execute([$ownerUserId, $name]);
    return ['ok' => true, 'tag' => ap_masto_tag_entity($name)];
}

/**
 * @param int $limit 0 = no limit (return all). Positive values are soft caps for API pagination.
 * @return list<array<string,mixed>>
 */
function ap_masto_followed_tags(int $limit = 0, ?int $ownerUserId = null): array
{
    $ownerUserId = $ownerUserId ?? ap_db_default_owner_user_id();
    $out = [];
    try {
        if ($limit > 0) {
            $limit = max(1, min(5000, $limit));
            $st = ap_db()->prepare(
                'SELECT name FROM masto_followed_tags WHERE owner_user_id = ? ORDER BY followed_at DESC LIMIT ?'
            );
            $st->execute([$ownerUserId, $limit]);
        } else {
            $st = ap_db()->prepare(
                'SELECT name FROM masto_followed_tags WHERE owner_user_id = ? ORDER BY followed_at DESC'
            );
            $st->execute([$ownerUserId]);
        }
        foreach ($st->fetchAll() as $row) {
            $out[] = ap_masto_tag_entity((string) ($row['name'] ?? ''));
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/**
 * Mastodon Tag entity for Ice Cubes tag pages / follow UI.
 *
 * @return array<string,mixed>
 */
/**
 * @param list<array{day:string,uses:string,accounts:string}>|null $history
 */
function ap_masto_tag_entity(string $name, ?array $history = null): array
{
    $name = ap_masto_normalize_tag_name($name);
    $id = (string) (100000 + (abs(crc32($name)) % 800000000));
    if ($history === null) {
        // Minimal 7-day history so clients that decode history don't crash
        $history = [];
        $day = (int) (floor(time() / 86400) * 86400);
        for ($i = 0; $i < 7; $i++) {
            $history[] = [
                'day' => (string) ($day - ($i * 86400)),
                'uses' => '0',
                'accounts' => '0',
            ];
        }
    }
    return [
        'id' => $id,
        'name' => $name,
        'url' => 'https://mkultra.monster/tags/' . rawurlencode($name),
        'history' => $history,
        'following' => ap_masto_tag_is_following($name),
        'featuring' => false,
    ];
}

/** Shared trends cache TTL (admin sidebar + Ice Cubes /api/v1/trends/*). */
const AP_MASTO_TRENDS_CACHE_TTL = 900; // 15 minutes

/**
 * @return array{path:string,ttl:int}
 */
function ap_masto_trends_cache_info(string $kind): array
{
    $kind = preg_replace('/[^a-z]/', '', strtolower($kind)) ?: 'tags';
    // Prefer the durable AP state dir (www-data writable). /tmp was leaving
    // root-owned stale files that FPM workers could not refresh.
    $dir = '/var/lib/mkultra/ap';
    if (!is_dir($dir) || !is_writable($dir)) {
        $dir = sys_get_temp_dir();
    }
    return [
        'path' => rtrim($dir, '/') . '/mkultra-ap-trends-' . $kind . '.json',
        'ttl' => AP_MASTO_TRENDS_CACHE_TTL,
    ];
}

/**
 * @return list<array<string,mixed>>|null
 */
function ap_masto_trends_cache_get(string $kind, int $minItems = 1): ?array
{
    $info = ap_masto_trends_cache_info($kind);
    $path = $info['path'];
    if (!is_file($path)) {
        return null;
    }
    $age = time() - (int) filemtime($path);
    if ($age < 0 || $age >= (int) $info['ttl']) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $decoded = json_decode($raw, true);
    if (!is_array($decoded) || !isset($decoded['items']) || !is_array($decoded['items'])) {
        return null;
    }
    if (count($decoded['items']) < $minItems) {
        return null;
    }
    return $decoded['items'];
}

/**
 * @param list<array<string,mixed>> $items
 */
function ap_masto_trends_cache_set(string $kind, array $items): void
{
    $info = ap_masto_trends_cache_info($kind);
    $payload = json_encode([
        'checked_at' => gmdate('c'),
        'items' => array_values($items),
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if (!is_string($payload)) {
        return;
    }
    $tmp = $info['path'] . '.' . getmypid();
    if (@file_put_contents($tmp, $payload, LOCK_EX) !== false) {
        @rename($tmp, $info['path']);
        @chmod($info['path'], 0664);
    }
}

/** Unix mtime of trends cache, or 0. */
function ap_masto_trends_cache_mtime(string $kind): int
{
    $path = ap_masto_trends_cache_info($kind)['path'];
    return is_file($path) ? (int) filemtime($path) : 0;
}

/**
 * Trending hashtags mined from recent local + federated text (7-day window).
 * Powers /api/v1/trends/tags and the admin sidebar.
 * Recalculates at most once per hour (shared file cache across PHP-FPM workers).
 *
 * @return list<array<string,mixed>> Mastodon Tag entities with history
 */
function ap_masto_trends_tags(int $limit = 10): array
{
    $limit = max(1, min(30, $limit));
    $cached = ap_masto_trends_cache_get('tags', 1);
    if (is_array($cached) && count($cached) >= $limit) {
        return array_slice($cached, 0, $limit);
    }

    // dayIndex 0 = today … 6 = 6 days ago
    $dayStart = (int) (floor(time() / 86400) * 86400);
    /** @var array<string,array{uses:list<int>,accounts:list<array<string,bool>>}> $stats */
    $stats = [];

    $ingest = static function (string $text, int $ts, string $accountKey) use (&$stats, $dayStart): void {
        if ($text === '' || $ts <= 0) {
            return;
        }
        $dayIdx = (int) floor(($dayStart - (int) (floor($ts / 86400) * 86400)) / 86400);
        if ($dayIdx < 0 || $dayIdx > 6) {
            return;
        }
        if (!preg_match_all('/#([\p{L}\p{N}_]{2,100})/u', $text, $m)) {
            return;
        }
        $seenInPost = [];
        foreach ($m[1] as $tag) {
            $key = mb_strtolower($tag);
            if ($key === '' || isset($seenInPost[$key])) {
                continue;
            }
            // Skip ultra-common spammy short tags
            if (in_array($key, ['nsfw', 'cw'], true)) {
                continue;
            }
            $seenInPost[$key] = true;
            if (!isset($stats[$key])) {
                $stats[$key] = [
                    'uses' => [0, 0, 0, 0, 0, 0, 0],
                    'accounts' => [[], [], [], [], [], [], []],
                ];
            }
            $stats[$key]['uses'][$dayIdx]++;
            if ($accountKey !== '') {
                $stats[$key]['accounts'][$dayIdx][$accountKey] = true;
            }
        }
    };

    try {
        $since = gmdate('c', $dayStart - (6 * 86400));
        $st = ap_db()->prepare(
            "SELECT summary, created_at, actor_id FROM events
             WHERE created_at >= ? AND summary IS NOT NULL AND summary != ''
             ORDER BY id DESC LIMIT 2500"
        );
        $st->execute([$since]);
        foreach ($st->fetchAll() as $row) {
            $text = html_entity_decode((string) ($row['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $ts = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            $ingest($text, $ts, rtrim((string) ($row['actor_id'] ?? ''), '/'));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $since = gmdate('c', $dayStart - (6 * 86400));
        $st = ap_db()->prepare(
            "SELECT content, created_at, actor_id FROM mentions
             WHERE deleted_at IS NULL AND created_at >= ? AND content IS NOT NULL
             ORDER BY id DESC LIMIT 800"
        );
        $st->execute([$since]);
        foreach ($st->fetchAll() as $row) {
            $ts = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            $ingest((string) ($row['content'] ?? ''), $ts, rtrim((string) ($row['actor_id'] ?? ''), '/'));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $since = gmdate('c', $dayStart - (6 * 86400));
        $st = ap_db()->prepare(
            "SELECT content, published FROM outbox_notes
             WHERE published >= ?
             ORDER BY published DESC LIMIT 400"
        );
        $st->execute([$since]);
        foreach ($st->fetchAll() as $row) {
            $ts = strtotime((string) ($row['published'] ?? '')) ?: 0;
            $ingest(strip_tags((string) ($row['content'] ?? '')), $ts, 'https://mkultra.monster/users/cmdr_nova');
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Score: today/yesterday dominate so the sidebar moves through the week
    $scored = [];
    foreach ($stats as $name => $s) {
        $totalUses = array_sum($s['uses']);
        if ($totalUses < 2) {
            continue;
        }
        $score = 0.0;
        $weights = [20, 8, 3, 1.5, 1, 0.5, 0.25];
        for ($i = 0; $i < 7; $i++) {
            $uses = (int) $s['uses'][$i];
            $accts = count($s['accounts'][$i]);
            $score += $weights[$i] * ($uses + (0.5 * $accts));
        }
        // Prefer tags that actually appeared today
        if ((int) $s['uses'][0] > 0) {
            $score *= 1.35;
        }
        $scored[$name] = $score;
    }
    arsort($scored, SORT_NUMERIC);

    $out = [];
    foreach ($scored as $name => $score) {
        $s = $stats[$name];
        $history = [];
        for ($i = 0; $i < 7; $i++) {
            $history[] = [
                'day' => (string) ($dayStart - ($i * 86400)),
                'uses' => (string) (int) $s['uses'][$i],
                'accounts' => (string) count($s['accounts'][$i]),
            ];
        }
        $out[] = ap_masto_tag_entity((string) $name, $history);
        if (count($out) >= 30) {
            break;
        }
    }
    ap_masto_trends_cache_set('tags', $out);
    return array_slice($out, 0, $limit);
}

/**
 * Build a Mastodon Trends::Link entity (PreviewCard + history).
 *
 * @param list<array{day:string,uses:string,accounts:string}> $history
 * @return array<string,mixed>
 */
function ap_masto_trends_link_entity(string $url, array $history, bool $allowFetch = false): array
{
    $card = null;
    if (function_exists('ap_link_preview_cache_get')) {
        $cached = ap_link_preview_cache_get($url);
        if (is_array($cached) && ($cached['status'] ?? '') === 'ok') {
            $card = ap_masto_preview_card($cached);
        }
    }
    if ($card === null && $allowFetch && function_exists('ap_link_preview_for_url')) {
        // Bypass a stale "fail" row so trends can retry after redirect/UA fixes.
        try {
            ap_db()->prepare(
                "DELETE FROM link_preview_cards WHERE url = ? AND status = 'fail'"
            )->execute([function_exists('ap_link_preview_normalize_url')
                ? ap_link_preview_normalize_url($url)
                : $url]);
        } catch (Throwable $e) {
            // non-fatal
        }
        $fetched = ap_link_preview_for_url($url, true);
        if (is_array($fetched)) {
            $card = ap_masto_preview_card($fetched);
        }
    }
    if ($card === null) {
        $host = (string) (parse_url($url, PHP_URL_HOST) ?: '');
        if (str_starts_with(strtolower($host), 'www.')) {
            $host = substr($host, 4);
        }
        $slugTitle = function_exists('ap_link_preview_title_from_url')
            ? ap_link_preview_title_from_url($url)
            : null;
        $card = [
            'url' => $url,
            'title' => $slugTitle !== null && $slugTitle !== ''
                ? $slugTitle
                : ($host !== '' ? $host : $url),
            'description' => '',
            'type' => 'link',
            'author_name' => '',
            'author_url' => '',
            'provider_name' => $host,
            'provider_url' => '',
            'html' => '',
            'width' => 0,
            'height' => 0,
            'image' => null,
            'embed_url' => '',
            'blurhash' => null,
            'language' => null,
            'published_at' => null,
            'authors' => [],
        ];
    }
    $card['history'] = $history;
    return $card;
}

/**
 * Trending links mined from recent local + federated text (7-day window).
 * Powers /api/v1/trends/links and the admin sidebar.
 *
 * @return list<array<string,mixed>> Mastodon Trends::Link entities
 */
function ap_masto_trends_links(int $limit = 10): array
{
    $limit = max(1, min(20, $limit));
    $cached = ap_masto_trends_cache_get('links', 1);
    if (is_array($cached) && count($cached) >= $limit) {
        return array_slice($cached, 0, $limit);
    }

    $dayStart = (int) (floor(time() / 86400) * 86400);
    /** @var array<string,array{uses:list<int>,accounts:list<array<string,bool>>}> $stats */
    $stats = [];

    $ingest = static function (string $text, int $ts, string $accountKey) use (&$stats, $dayStart): void {
        if ($text === '' || $ts <= 0 || !function_exists('ap_link_preview_extract_all_urls')) {
            return;
        }
        $dayIdx = (int) floor(($dayStart - (int) (floor($ts / 86400) * 86400)) / 86400);
        if ($dayIdx < 0 || $dayIdx > 6) {
            return;
        }
        $urls = ap_link_preview_extract_all_urls($text, true);
        if (!$urls) {
            return;
        }
        $seenInPost = [];
        foreach ($urls as $url) {
            // Skip fediverse status/profile URLs — News wants articles/media sites
            $host = strtolower((string) (parse_url($url, PHP_URL_HOST) ?: ''));
            $path = strtolower((string) (parse_url($url, PHP_URL_PATH) ?: ''));
            if ($host === '' || str_ends_with($host, 'mkultra.monster')) {
                continue;
            }
            if (
                str_contains($path, '/users/')
                || str_contains($path, '/@')
                || str_contains($path, '/statuses/')
                || str_contains($path, '/notes/')
                || str_contains($path, '/objects/')
                || str_contains($path, '/activities/')
                || str_contains($path, '/tags/')
                || str_contains($host, 'brid.gy')
            ) {
                continue;
            }
            if (isset($seenInPost[$url])) {
                continue;
            }
            $seenInPost[$url] = true;
            if (!isset($stats[$url])) {
                $stats[$url] = [
                    'uses' => [0, 0, 0, 0, 0, 0, 0],
                    'accounts' => [[], [], [], [], [], [], []],
                ];
            }
            $stats[$url]['uses'][$dayIdx]++;
            if ($accountKey !== '') {
                $stats[$url]['accounts'][$dayIdx][$accountKey] = true;
            }
        }
    };

    try {
        $since = gmdate('c', $dayStart - (6 * 86400));
        $st = ap_db()->prepare(
            "SELECT summary, created_at, actor_id FROM events
             WHERE created_at >= ? AND summary IS NOT NULL AND summary != ''
               AND type IN ('Create', 'Announce')
             ORDER BY id DESC LIMIT 3000"
        );
        $st->execute([$since]);
        foreach ($st->fetchAll() as $row) {
            $text = html_entity_decode((string) ($row['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $ts = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            $ingest($text, $ts, rtrim((string) ($row['actor_id'] ?? ''), '/'));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $since = gmdate('c', $dayStart - (6 * 86400));
        $st = ap_db()->prepare(
            "SELECT content, created_at, actor_id FROM mentions
             WHERE deleted_at IS NULL AND created_at >= ? AND content IS NOT NULL
             ORDER BY id DESC LIMIT 800"
        );
        $st->execute([$since]);
        foreach ($st->fetchAll() as $row) {
            $ts = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            $ingest((string) ($row['content'] ?? ''), $ts, rtrim((string) ($row['actor_id'] ?? ''), '/'));
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $since = gmdate('c', $dayStart - (6 * 86400));
        $st = ap_db()->prepare(
            "SELECT content, published FROM outbox_notes
             WHERE published >= ?
             ORDER BY published DESC LIMIT 400"
        );
        $st->execute([$since]);
        foreach ($st->fetchAll() as $row) {
            $ts = strtotime((string) ($row['published'] ?? '')) ?: 0;
            $ingest(strip_tags((string) ($row['content'] ?? '')), $ts, 'https://mkultra.monster/users/cmdr_nova');
        }
    } catch (Throwable $e) {
        // ignore
    }

    $scored = [];
    foreach ($stats as $url => $s) {
        $totalUses = array_sum($s['uses']);
        if ($totalUses < 2) {
            continue;
        }
        $score = 0.0;
        $weights = [20, 8, 3, 1.5, 1, 0.5, 0.25];
        for ($i = 0; $i < 7; $i++) {
            $uses = (int) $s['uses'][$i];
            $accts = count($s['accounts'][$i]);
            $score += $weights[$i] * ($uses + (0.5 * $accts));
        }
        if ((int) $s['uses'][0] > 0) {
            $score *= 1.35;
        }
        $scored[$url] = $score;
    }
    arsort($scored, SORT_NUMERIC);

    $out = [];
    // Deferred ajax trends + 15m cache: warm titles for the sidebar (5) plus a few more.
    $fetchBudget = 8;
    foreach ($scored as $url => $score) {
        $s = $stats[$url];
        $history = [];
        for ($i = 0; $i < 7; $i++) {
            $history[] = [
                'day' => (string) ($dayStart - ($i * 86400)),
                'uses' => (string) (int) $s['uses'][$i],
                'accounts' => (string) count($s['accounts'][$i]),
            ];
        }
        $cached = function_exists('ap_link_preview_cache_get') ? ap_link_preview_cache_get((string) $url) : null;
        $hasOkTitle = is_array($cached)
            && ($cached['status'] ?? '') === 'ok'
            && trim((string) ($cached['title'] ?? '')) !== '';
        // Retry when missing or previously failed (fail rows used to block refetch).
        $allowFetch = $fetchBudget > 0 && !$hasOkTitle;
        if ($allowFetch) {
            $fetchBudget--;
        }
        $out[] = ap_masto_trends_link_entity((string) $url, $history, $allowFetch);
        if (count($out) >= 20) {
            break;
        }
    }
    ap_masto_trends_cache_set('links', $out);
    return array_slice($out, 0, $limit);
}

/**
 * Whether an ActivityPub object URL looks like a shareable status (not a Like activity, etc.).
 */
function ap_masto_trends_is_status_object_url(string $objectId): bool
{
    $objectId = rtrim($objectId, '/');
    if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
        return false;
    }
    $path = strtolower((string) (parse_url($objectId, PHP_URL_PATH) ?: ''));
    if ($path === '' || str_contains($path, '/activities/') || str_contains($path, '/like/') || str_contains($path, '/emoji')) {
        return false;
    }
    // Prefer known note-ish paths; also allow Lemmy posts and generic objects
    if (
        str_contains($path, '/statuses/')
        || str_contains($path, '/notes/')
        || str_contains($path, '/objects/')
        || str_contains($path, '/post/')
        || str_contains($path, '/comment/')
        || preg_match('#/users/[^/]+/\d+#', $path)
    ) {
        return true;
    }
    // Reject obvious non-status AP collections
    if (str_contains($path, '/followers') || str_contains($path, '/following') || str_contains($path, '/outbox') || str_contains($path, '/inbox')) {
        return false;
    }
    return false;
}

/**
 * Trending statuses ranked by boosts / likes / replies we observed (7-day window).
 * Powers /api/v1/trends/statuses and the admin sidebar.
 *
 * @return list<array<string,mixed>> Mastodon Status entities
 */
function ap_masto_trends_statuses(int $limit = 10): array
{
    $limit = max(1, min(20, $limit));
    $cached = ap_masto_trends_cache_get('statuses', 1);
    if (is_array($cached) && count($cached) >= $limit) {
        return array_slice($cached, 0, $limit);
    }

    $dayStart = (int) (floor(time() / 86400) * 86400);
    $since = gmdate('c', $dayStart - (6 * 86400));
    /** @var array<string,array{announces:int,likes:int,replies:int,accounts:array<string,bool>,last_ts:int}> $agg */
    $agg = [];

    $bump = static function (string $objectId, string $kind, int $ts, string $actorKey) use (&$agg): void {
        $objectId = rtrim($objectId, '/');
        if ($objectId === '' || !ap_masto_trends_is_status_object_url($objectId)) {
            return;
        }
        if (!isset($agg[$objectId])) {
            $agg[$objectId] = [
                'announces' => 0,
                'likes' => 0,
                'replies' => 0,
                'accounts' => [],
                'last_ts' => 0,
            ];
        }
        if ($kind === 'announce') {
            $agg[$objectId]['announces']++;
        } elseif ($kind === 'like') {
            $agg[$objectId]['likes']++;
        } elseif ($kind === 'reply') {
            $agg[$objectId]['replies']++;
        }
        if ($actorKey !== '') {
            $agg[$objectId]['accounts'][$actorKey] = true;
        }
        if ($ts > $agg[$objectId]['last_ts']) {
            $agg[$objectId]['last_ts'] = $ts;
        }
    };

    try {
        $st = ap_db()->prepare(
            "SELECT type, object_id, actor_id, created_at, in_reply_to FROM events
             WHERE created_at >= ?
               AND type IN ('Announce', 'Like', 'Create')
             ORDER BY id DESC LIMIT 8000"
        );
        $st->execute([$since]);
        foreach ($st->fetchAll() as $row) {
            $type = strtolower((string) ($row['type'] ?? ''));
            $ts = strtotime((string) ($row['created_at'] ?? '')) ?: 0;
            $actor = rtrim((string) ($row['actor_id'] ?? ''), '/');
            if ($type === 'announce') {
                $bump((string) ($row['object_id'] ?? ''), 'announce', $ts, $actor);
            } elseif ($type === 'like') {
                $bump((string) ($row['object_id'] ?? ''), 'like', $ts, $actor);
            } elseif ($type === 'create') {
                $replyTo = rtrim((string) ($row['in_reply_to'] ?? ''), '/');
                if ($replyTo !== '') {
                    $bump($replyTo, 'reply', $ts, $actor);
                }
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $scored = [];
    foreach ($agg as $oid => $a) {
        $eng = ((int) $a['announces'] * 5) + ((int) $a['likes'] * 3) + ((int) $a['replies'] * 2);
        if ($eng < 2 && count($a['accounts']) < 2) {
            continue;
        }
        $ageHours = max(1.0, (time() - max(1, (int) $a['last_ts'])) / 3600.0);
        // Stronger recency so yesterday's viral posts don't own the sidebar for days
        $recency = 96.0 / $ageHours;
        $scored[$oid] = $eng + (0.75 * count($a['accounts'])) + $recency;
    }
    arsort($scored, SORT_NUMERIC);

    $out = [];
    $seenIds = [];
    /** @var array<string,int> $perAcct */
    $perAcct = [];
    $takeStatus = static function (array $status) use (&$out, &$seenIds, &$perAcct): bool {
        $sid = (string) ($status['id'] ?? '');
        if ($sid === '' || isset($seenIds[$sid])) {
            return false;
        }
        $acctKey = '';
        if (!empty($status['account']) && is_array($status['account'])) {
            $acctKey = (string) ($status['account']['acct'] ?? $status['account']['id'] ?? '');
        }
        if ($acctKey !== '' && ($perAcct[$acctKey] ?? 0) >= 2) {
            return false; // keep Explore varied
        }
        $seenIds[$sid] = true;
        if ($acctKey !== '') {
            $perAcct[$acctKey] = ($perAcct[$acctKey] ?? 0) + 1;
        }
        $out[] = $status;
        return true;
    };

    foreach ($scored as $oid => $_score) {
        if (count($out) >= 20) {
            break;
        }
        $row = null;
        try {
            // Prefer a Create with text; then Create with media; then Announce
            $st = ap_db()->prepare(
                "SELECT * FROM events
                 WHERE object_id = ? AND type = 'Create'
                   AND summary IS NOT NULL AND summary != ''
                 ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$oid]);
            $row = $st->fetch();
            if (!is_array($row)) {
                $st = ap_db()->prepare(
                    "SELECT * FROM events
                     WHERE object_id = ? AND type = 'Create'
                       AND media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]'
                     ORDER BY id DESC LIMIT 1"
                );
                $st->execute([$oid]);
                $row = $st->fetch();
            }
            if (!is_array($row)) {
                $st = ap_db()->prepare(
                    "SELECT * FROM events
                     WHERE object_id = ? AND type = 'Announce'
                       AND (
                         (summary IS NOT NULL AND summary != '')
                         OR (media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]')
                       )
                     ORDER BY id DESC LIMIT 1"
                );
                $st->execute([$oid]);
                $row = $st->fetch();
            }
        } catch (Throwable $e) {
            $row = null;
        }
        if (!is_array($row)) {
            continue;
        }
        $status = ap_masto_status_from_event($row);
        if ($status === null) {
            continue;
        }
        // Trends should show the underlying post, not the boost wrapper
        if (!empty($status['reblog']) && is_array($status['reblog'])) {
            $status = $status['reblog'];
        }
        $a = $agg[$oid];
        $status['reblogs_count'] = max((int) ($status['reblogs_count'] ?? 0), (int) $a['announces']);
        $status['favourites_count'] = max((int) ($status['favourites_count'] ?? 0), (int) $a['likes']);
        $status['replies_count'] = max((int) ($status['replies_count'] ?? 0), (int) $a['replies']);
        $takeStatus($status);
    }

    // Fill with recent original Creates if engagement set is thin
    if (count($out) < $limit) {
        try {
            $st = ap_db()->prepare(
                "SELECT * FROM events
                 WHERE created_at >= ? AND type = 'Create'
                   AND (in_reply_to IS NULL OR in_reply_to = '')
                   AND summary IS NOT NULL AND summary != ''
                   AND (action_taken = 'log' OR action_taken = 'local_observe')
                 ORDER BY id DESC LIMIT 120"
            );
            $st->execute([$since]);
            foreach ($st->fetchAll() as $row) {
                if (count($out) >= max($limit, 10)) {
                    break;
                }
                $status = ap_masto_status_from_event($row);
                if ($status === null) {
                    continue;
                }
                $takeStatus($status);
            }
        } catch (Throwable $e) {
            // ignore
        }
    }

    ap_masto_trends_cache_set('statuses', $out);
    return array_slice($out, 0, $limit);
}

/* -------------------------------------------------------------------------- */
/* Follow suggestions (“For You” in official Mastodon Explore)                */
/* -------------------------------------------------------------------------- */

/**
 * @return array<string,true> actor_id => true
 */
function ap_masto_suggestion_dismissed_set(?int $ownerUserId = null): array
{
    $ownerUserId = $ownerUserId ?? ap_db_default_owner_user_id();
    if ($ownerUserId < 1) {
        return [];
    }
    $out = [];
    try {
        $st = ap_db()->prepare('SELECT actor_id FROM masto_suggestion_dismissals WHERE owner_user_id = ?');
        $st->execute([$ownerUserId]);
        foreach ($st as $row) {
            $id = rtrim((string) ($row['actor_id'] ?? ''), '/');
            if ($id !== '') {
                $out[$id] = true;
            }
        }
    } catch (Throwable $e) {
        // table may not exist yet mid-migrate
    }
    return $out;
}

function ap_masto_suggestion_dismiss(string $actorId): void
{
    $ownerUserId = ap_db_default_owner_user_id();
    if ($ownerUserId < 1) {
        return;
    }
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return;
    }
    ap_db()->prepare(
        'INSERT INTO masto_suggestion_dismissals (owner_user_id, actor_id, dismissed_at) VALUES (?, ?, ?)
         ON CONFLICT(owner_user_id, actor_id) DO UPDATE SET dismissed_at = excluded.dismissed_at'
    )->execute([$ownerUserId, $actorId, ap_db_now()]);
}

/**
 * Official Explore → For You: accounts to follow (not a post feed).
 * Sources mirror Mastodon Suggestion: past_interactions + global.
 *
 * @return list<array{source:string,sources?:list<string>,account:array}>
 */
function ap_masto_suggestions_v2(int $limit = 40): array
{
    $limit = max(1, min(80, $limit));
    $local = ap_masto_session_actor_id();
    $dismissed = ap_masto_suggestion_dismissed_set();
    $following = [];
    /** @var array<string,true> $followingKeys bare did / handle keys for Bluesky alias matching */
    $followingKeys = [];
    foreach (ap_following_list() as $row) {
        $id = rtrim((string) ($row['actor_id'] ?? ''), '/');
        if ($id !== '') {
            $following[$id] = true;
        }
    }
    // Bluesky follows live outside the AP following table — include them so
    // Home suggestions don't keep offering accounts you already follow there.
    $ownerUserId = function_exists('ap_db_masto_owner_user_id') ? ap_db_masto_owner_user_id() : 0;
    if ($ownerUserId > 0) {
        try {
            if (function_exists('ap_bsky_graph_sync_migrate')) {
                ap_bsky_graph_sync_migrate();
            }
            $gst = ap_db()->prepare(
                "SELECT target_did FROM bsky_graph_sync WHERE owner_user_id = ? AND kind = 'follow'"
            );
            $gst->execute([$ownerUserId]);
            foreach ($gst->fetchAll() as $grow) {
                $did = trim((string) ($grow['target_did'] ?? ''));
                if ($did === '' || !str_starts_with($did, 'did:')) {
                    continue;
                }
                $followingKeys[strtolower($did)] = true;
                $following['https://bsky.app/profile/' . $did] = true;
                $following['https://bsky.app/profile/' . rawurlencode($did)] = true;
            }
        } catch (Throwable $e) {
            // ignore
        }
        try {
            $bskyAction = defined('AP_BSKY_FOLLOW_ACTION') ? AP_BSKY_FOLLOW_ACTION : 'bsky_follow';
            $est = ap_db()->prepare(
                'SELECT DISTINCT actor_id FROM events WHERE action_taken = ? AND actor_id IS NOT NULL'
            );
            $est->execute([$bskyAction]);
            foreach ($est->fetchAll() as $erow) {
                $aid = rtrim((string) ($erow['actor_id'] ?? ''), '/');
                if ($aid === '') {
                    continue;
                }
                $following[$aid] = true;
                if (preg_match('~^https://bsky\.app/profile/([^/?#]+)~i', $aid, $bm)) {
                    $followingKeys[strtolower(rawurldecode($bm[1]))] = true;
                }
            }
        } catch (Throwable $e) {
            // ignore
        }
        // Map followed DIDs → known handles so handle-form suggestions also skip.
        if ($followingKeys !== []) {
            try {
                $dids = [];
                foreach (array_keys($followingKeys) as $k) {
                    if (str_starts_with($k, 'did:')) {
                        $dids[] = $k;
                    }
                }
                if ($dids !== []) {
                    // Chunk to keep IN lists reasonable.
                    foreach (array_chunk($dids, 40) as $chunk) {
                        $ph = implode(',', array_fill(0, count($chunk), '?'));
                        $hst = ap_db()->prepare(
                            "SELECT DISTINCT lower(author_handle) AS h, lower(author_did) AS d
                             FROM bsky_posts
                             WHERE lower(author_did) IN ($ph)
                               AND author_handle IS NOT NULL AND author_handle != ''"
                        );
                        $hst->execute($chunk);
                        foreach ($hst->fetchAll() as $hrow) {
                            $h = trim((string) ($hrow['h'] ?? ''));
                            if ($h === '') {
                                continue;
                            }
                            $followingKeys[$h] = true;
                            $following['https://bsky.app/profile/' . $h] = true;
                        }
                    }
                }
            } catch (Throwable $e) {
                // ignore
            }
        }
    }
    $skip = static function (string $actorId) use ($local, $dismissed, $following, $followingKeys): bool {
        $actorId = rtrim($actorId, '/');
        if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
            return true;
        }
        // Never suggest the logged-in local actor (or any mkultra.monster local user)
        if ($actorId === $local || ap_masto_is_local_actor_url($actorId)) {
            return true;
        }
        if (isset($dismissed[$actorId]) || isset($following[$actorId])) {
            return true;
        }
        if (preg_match('~^https://bsky\.app/profile/([^/?#]+)~i', $actorId, $bm)) {
            $key = strtolower(rawurldecode($bm[1]));
            if ($key !== '' && isset($followingKeys[$key])) {
                return true;
            }
        }
        if (function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($actorId)) {
            return true;
        }
        return false;
    };

    /** @var array<string,array{score:float,sources:array<string,true>}> $scores */
    $scores = [];
    $bump = static function (string $actorId, float $w, string $source) use (&$scores, $skip): void {
        $actorId = rtrim($actorId, '/');
        if ($skip($actorId)) {
            return;
        }
        if (!isset($scores[$actorId])) {
            $scores[$actorId] = ['score' => 0.0, 'sources' => []];
        }
        $scores[$actorId]['score'] += $w;
        $scores[$actorId]['sources'][$source] = true;
    };

    // Past positive interactions: people whose posts we favourited / boosted
    try {
        $st = ap_db()->query(
            "SELECT target_actor AS a, COUNT(*) AS c FROM masto_favourites
             WHERE target_actor IS NOT NULL AND target_actor != ''
             GROUP BY target_actor ORDER BY c DESC LIMIT 80"
        );
        foreach ($st as $row) {
            $bump((string) $row['a'], 3.0 * (float) $row['c'], 'past_interactions');
        }
    } catch (Throwable $e) {
    }
    try {
        $st = ap_db()->query(
            "SELECT target_actor AS a, COUNT(*) AS c FROM masto_reblogs
             WHERE target_actor IS NOT NULL AND target_actor != ''
             GROUP BY target_actor ORDER BY c DESC LIMIT 80"
        );
        foreach ($st as $row) {
            $bump((string) $row['a'], 4.0 * (float) $row['c'], 'past_interactions');
        }
    } catch (Throwable $e) {
    }
    // People who mentioned / replied to us a lot (familiar but not followed)
    try {
        $st = ap_db()->query(
            "SELECT actor_id AS a, COUNT(*) AS c FROM mentions
             WHERE deleted_at IS NULL AND actor_id IS NOT NULL AND actor_id != ''
             GROUP BY actor_id ORDER BY c DESC LIMIT 80"
        );
        foreach ($st as $row) {
            $bump((string) $row['a'], 2.0 * (float) $row['c'], 'past_interactions');
        }
    } catch (Throwable $e) {
    }

    // Global: frequent authors in the recent public firehose
    try {
        $recentCutoff = ap_db_driver() === 'pgsql'
            ? "NOW() - INTERVAL '14 days'"
            : "datetime('now', '-14 days')";
        $st = ap_db()->query(
            "SELECT actor_id AS a, COUNT(*) AS c FROM events
             WHERE type IN ('Create','Announce')
               AND COALESCE(action_taken,'') NOT IN ('deleted','blocked','rejected')
               AND actor_id IS NOT NULL AND actor_id != ''
               AND created_at >= {$recentCutoff}
             GROUP BY actor_id
             ORDER BY c DESC
             LIMIT 120"
        );
        foreach ($st as $row) {
            $bump((string) $row['a'], 1.0 * sqrt((float) $row['c']), 'global');
        }
    } catch (Throwable $e) {
    }

    uasort($scores, static function (array $a, array $b): int {
        return $b['score'] <=> $a['score'];
    });

    $out = [];
    foreach ($scores as $actorId => $meta) {
        if (count($out) >= $limit) {
            break;
        }
        try {
            $account = ap_masto_remote_account($actorId);
        } catch (Throwable $e) {
            continue;
        }
        if (!is_array($account) || ($account['id'] ?? '') === '') {
            continue;
        }
        $sources = array_keys($meta['sources']);
        // Prefer past_interactions label when both apply (official apps show one primary source)
        $primary = in_array('past_interactions', $sources, true) ? 'past_interactions' : ($sources[0] ?? 'global');
        $out[] = [
            'source' => $primary,
            'sources' => $sources,
            'account' => $account,
        ];
    }
    return $out;
}

/**
 * Deprecated v1 shape: bare Account array.
 *
 * @return list<array<string,mixed>>
 */
function ap_masto_suggestions_v1(int $limit = 40): array
{
    $out = [];
    foreach (ap_masto_suggestions_v2($limit) as $row) {
        if (!empty($row['account']) && is_array($row['account'])) {
            $out[] = $row['account'];
        }
    }
    return $out;
}

/**
 * Statuses containing a hashtag (local posts + mentions + federated firehose text).
 *
 * @return list<array<string,mixed>>
 */
/**
 * Local/mention posts matching any of the given hashtags (no events scan).
 *
 * @param list<string> $tagNames
 * @return list<array<string,mixed>>
 */
function ap_masto_timeline_hashtag_local(array $tagNames, int $limit = 20, ?string $maxId = null): array
{
    $limit = max(1, min(40, $limit));
    $names = [];
    foreach ($tagNames as $n) {
        $n = ap_masto_normalize_tag_name((string) $n);
        if ($n !== '') {
            $names[$n] = true;
        }
    }
    $names = array_keys($names);
    if (!$names) {
        return [];
    }
    $before = ($maxId !== null && $maxId !== '') ? ap_masto_status_created_at_by_id((int) $maxId) : null;
    $out = [];
    $seenId = [];
    $ors = [];
    $params = [];
    foreach ($names as $n) {
        $ors[] = 'lower(content_text) LIKE ? ESCAPE \'\\\'';
        $params[] = '%#' . str_replace(['%', '_'], ['\\%', '\\_'], $n) . '%';
        $ors[] = 'lower(COALESCE(spoiler_text,\'\')) LIKE ? ESCAPE \'\\\'';
        $params[] = '%#' . str_replace(['%', '_'], ['\\%', '\\_'], $n) . '%';
    }
    try {
        $sql = 'SELECT * FROM masto_statuses WHERE (' . implode(' OR ', $ors) . ') ORDER BY local_id DESC LIMIT ?';
        $params[] = $limit * 3;
        $st = ap_db()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $status = ap_masto_status_from_row($row);
            $hit = false;
            foreach ($names as $n) {
                if (ap_masto_status_has_tag($status, $n)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $ts = (string) ($status['created_at'] ?? '');
            if ($before !== null && $ts !== '' && strcmp($ts, $before) >= 0) {
                continue;
            }
            $sid = (string) ($status['id'] ?? '');
            if ($sid !== '' && isset($seenId[$sid])) {
                continue;
            }
            if ($sid !== '') {
                $seenId[$sid] = true;
            }
            $out[] = $status;
            if (count($out) >= $limit) {
                return $out;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    $ors = [];
    $params = [];
    foreach ($names as $n) {
        $ors[] = 'lower(COALESCE(content,\'\')) LIKE ? ESCAPE \'\\\'';
        $params[] = '%#' . str_replace(['%', '_'], ['\\%', '\\_'], $n) . '%';
    }
    try {
        $sql = 'SELECT * FROM mentions WHERE deleted_at IS NULL AND (' . implode(' OR ', $ors) . ') ORDER BY id DESC LIMIT ?';
        $params[] = $limit * 3;
        $st = ap_db()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $status = ap_masto_status_from_mention($row);
            $hit = false;
            foreach ($names as $n) {
                if (ap_masto_status_has_tag($status, $n)) {
                    $hit = true;
                    break;
                }
            }
            if (!$hit) {
                continue;
            }
            $ts = (string) ($status['created_at'] ?? '');
            if ($before !== null && $ts !== '' && strcmp($ts, $before) >= 0) {
                continue;
            }
            $sid = (string) ($status['id'] ?? '');
            if ($sid !== '' && isset($seenId[$sid])) {
                continue;
            }
            if ($sid !== '') {
                $seenId[$sid] = true;
            }
            $out[] = $status;
            if (count($out) >= $limit) {
                break;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

/**
 * True if a Mastodon status (or its reblog wrapper) contains #tag.
 */
function ap_masto_status_has_tag(array $status, string $tagName): bool
{
    $tagName = ap_masto_normalize_tag_name($tagName);
    if ($tagName === '') {
        return false;
    }
    $needle = '#' . mb_strtolower($tagName);
    $parts = [
        (string) ($status['content'] ?? ''),
        (string) ($status['spoiler_text'] ?? ''),
    ];
    $tagLists = [];
    if (!empty($status['tags']) && is_array($status['tags'])) {
        $tagLists[] = $status['tags'];
    }
    if (!empty($status['reblog']) && is_array($status['reblog'])) {
        $parts[] = (string) ($status['reblog']['content'] ?? '');
        $parts[] = (string) ($status['reblog']['spoiler_text'] ?? '');
        if (!empty($status['reblog']['tags']) && is_array($status['reblog']['tags'])) {
            $tagLists[] = $status['reblog']['tags'];
        }
    }
    foreach ($tagLists as $list) {
        foreach ($list as $t) {
            if (!is_array($t)) {
                continue;
            }
            if (ap_masto_normalize_tag_name((string) ($t['name'] ?? '')) === $tagName) {
                return true;
            }
        }
    }
    $blob = mb_strtolower(strip_tags(implode("\n", $parts)));
    if (str_contains($blob, $needle)) {
        return true;
    }
    return (bool) preg_match('/#' . preg_quote($tagName, '/') . '\b/ui', $blob);
}

/**
 * Event rows matching any followed hashtag (for admin Home + helpers).
 *
 * @return list<array<string,mixed>>
 */
function ap_masto_followed_tag_event_rows(int $limit = 80, ?int $ownerUserId = null): array
{
    $limit = max(1, min(200, $limit));
    $tags = [];
    foreach (ap_masto_followed_tags(0, $ownerUserId) as $tag) {
        $n = ap_masto_normalize_tag_name((string) ($tag['name'] ?? ''));
        if ($n !== '') {
            $tags[$n] = true;
        }
    }
    if (!$tags) {
        return [];
    }
    $names = array_keys($tags);
    // One query with OR of LIKE patterns (bound; capped tag count)
    $names = array_slice($names, 0, 40);
    $ors = [];
    $params = [];
    foreach ($names as $n) {
        $ors[] = 'lower(summary) LIKE ? ESCAPE \'\\\'';
        $params[] = '%#' . str_replace(['%', '_'], ['\\%', '\\_'], $n) . '%';
    }
    $sql = "SELECT * FROM events
            WHERE summary IS NOT NULL AND summary != ''
              AND lower(COALESCE(type,'')) IN ('create','announce','update')
              AND (action_taken = 'log' OR action_taken = 'local_observe')
              AND (" . implode(' OR ', $ors) . ")
            ORDER BY id DESC LIMIT ?";
    $params[] = max($limit * 3, 60);
    $out = [];
    $seen = [];
    try {
        $st = ap_db()->prepare($sql);
        $st->execute($params);
        foreach ($st->fetchAll() as $row) {
            $sum = html_entity_decode((string) ($row['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            $matched = false;
            foreach ($names as $n) {
                if (preg_match('/#' . preg_quote($n, '/') . '\b/ui', $sum)) {
                    $matched = true;
                    break;
                }
            }
            if (!$matched) {
                continue;
            }
            $aid = (string) ($row['actor_id'] ?? '');
            if ($aid === '' || (function_exists('ap_row_is_hidden')
                ? ap_row_is_hidden($aid, $row['host'] ?? null, ap_db_masto_owner_user_id())
                : (function_exists('ap_row_is_blocked') && ap_row_is_blocked($aid, $row['host'] ?? null)))) {
                continue;
            }
            // Skip local actors' mirrored copies (any invitee / cmdr_nova)
            if (ap_masto_is_local_actor_url($aid) || str_contains($aid, 'mkultra.monster/users/')) {
                continue;
            }
            $oid = rtrim((string) ($row['object_id'] ?? ''), '/');
            $key = $oid !== '' ? $oid : ('e:' . (string) ($row['id'] ?? ''));
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $row['_from_followed_tag'] = true;
            $out[] = $row;
            if (count($out) >= $limit) {
                break;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }
    return $out;
}

function ap_masto_timeline_hashtag(string $name, int $limit = 20, ?string $maxId = null): array
{
    $name = ap_masto_normalize_tag_name($name);
    $limit = max(1, min(40, $limit));
    if ($name === '') {
        return [];
    }
    // Match #tag as a word-ish boundary in HTML/plain text
    $needle = '#' . $name;
    $out = [];
    $seenId = [];
    $before = ($maxId !== null && $maxId !== '') ? ap_masto_status_created_at_by_id((int) $maxId) : null;

    $push = static function (array $status) use (&$out, &$seenId, $limit, $name, $before): bool {
        if (!ap_masto_status_has_tag($status, $name)) {
            return false;
        }
        $ts = (string) ($status['created_at'] ?? '');
        if ($before !== null && $ts !== '' && strcmp($ts, $before) >= 0) {
            return false;
        }
        $sid = (string) ($status['id'] ?? '');
        if ($sid !== '') {
            if (isset($seenId[$sid])) {
                return false;
            }
            $seenId[$sid] = true;
        }
        $out[] = $status;
        return count($out) >= $limit;
    };

    try {
        $st = ap_db()->prepare(
            "SELECT * FROM masto_statuses
             WHERE lower(content_text) LIKE ?
                OR lower(COALESCE(spoiler_text,'')) LIKE ?
             ORDER BY local_id DESC LIMIT ?"
        );
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($needle)) . '%';
        $st->execute([$like, $like, $limit * 3]);
        foreach ($st->fetchAll() as $row) {
            if ($push(ap_masto_status_from_row($row))) {
                return $out;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    try {
        $st = ap_db()->prepare(
            "SELECT * FROM mentions
             WHERE deleted_at IS NULL AND lower(COALESCE(content,'')) LIKE ?
             ORDER BY id DESC LIMIT ?"
        );
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($needle)) . '%';
        $st->execute([$like, $limit * 3]);
        foreach ($st->fetchAll() as $row) {
            if ($push(ap_masto_status_from_mention($row))) {
                return $out;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Creates + Announces (relay hashtag firehose is mostly Announce)
    try {
        $st = ap_db()->prepare(
            "SELECT * FROM events
             WHERE summary IS NOT NULL AND summary != ''
               AND lower(COALESCE(type,'')) IN ('create','announce','update')
               AND lower(summary) LIKE ?
               AND (action_taken = 'log' OR action_taken = 'local_observe')
             ORDER BY id DESC LIMIT ?"
        );
        $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], mb_strtolower($needle)) . '%';
        $st->execute([$like, $limit * 4]);
        foreach ($st->fetchAll() as $row) {
            $sum = html_entity_decode((string) ($row['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
            if (!preg_match('/#' . preg_quote($name, '/') . '\b/ui', $sum)) {
                continue;
            }
            $status = ap_masto_status_from_event($row);
            if (!$status) {
                continue;
            }
            if ($push($status)) {
                break;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    return $out;
}

/**
 * Local status text search (own posts + mentions + federated events).
 * Hashtag queries (#feet) match tag tokens in content/summary, not only literal substrings.
 *
 * @return list<array<string,mixed>>
 */
function ap_masto_search_statuses(string $q, int $limit): array
{
    $q = trim(ap_fix_utf8($q));
    if ($q === '' || mb_strlen($q) < 2) {
        return [];
    }
    if (mb_strlen($q) > 200) {
        $q = mb_substr($q, 0, 200);
    }
    $qLower = mb_strtolower($q);
    // Only explicit #tag queries use hashtag-precision mode.
    // Bare words (linux, queuing, …) stay normal FTS text search so posts
    // without a #hashtag still match.
    $tagName = '';
    if (str_starts_with($qLower, '#')) {
        $tagName = ap_masto_normalize_tag_name($q);
    }

    $out = [];
    $seenIds = [];

    $push = static function (array $status) use (&$out, &$seenIds, $limit): void {
        if (count($out) >= $limit) {
            return;
        }
        $id = (string) ($status['id'] ?? '');
        if ($id === '' || isset($seenIds[$id])) {
            return;
        }
        $seenIds[$id] = true;
        $out[] = $status;
    };

    // Prefer local FTS5 index (scales to 10^5+ docs). Falls back to LIKE if unavailable.
    if (!function_exists('ap_search_fts_available')) {
        require_once __DIR__ . '/ap-search-fts.php';
    }
    if (function_exists('ap_search_fts_available') && ap_search_fts_available()) {
        $hits = ap_search_fts_query($q, max($limit * 3, 40), $tagName);
        foreach ($hits as $hit) {
            $source = (string) ($hit['source'] ?? '');
            $pk = (int) ($hit['source_pk'] ?? 0);
            if ($pk <= 0) {
                continue;
            }
            try {
                if ($source === 'status') {
                    $row = ap_masto_status_by_local_id($pk);
                    if (is_array($row)) {
                        $push(ap_masto_status_from_row($row));
                    }
                } elseif ($source === 'mention') {
                    $st = ap_db()->prepare('SELECT * FROM mentions WHERE id = ? AND deleted_at IS NULL');
                    $st->execute([$pk]);
                    $row = $st->fetch();
                    if (is_array($row)) {
                        $push(ap_masto_status_from_mention($row));
                    }
                } elseif ($source === 'event') {
                    $st = ap_db()->prepare('SELECT * FROM events WHERE id = ?');
                    $st->execute([$pk]);
                    $row = $st->fetch();
                    if (is_array($row)) {
                        if ($tagName !== '') {
                            $sum = html_entity_decode((string) ($row['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                            // Prefer real #tag token; FTS may also match bare word via htag:
                            if (!preg_match('/#' . preg_quote($tagName, '/') . '\b/ui', $sum)) {
                                continue;
                            }
                        }
                        $status = ap_masto_status_from_event($row);
                        if (is_array($status)) {
                            $push($status);
                        }
                    }
                }
            } catch (Throwable $e) {
                // skip bad row
            }
            if (count($out) >= $limit) {
                break;
            }
        }
        if ($out) {
            return array_slice($out, 0, $limit);
        }
        // Empty FTS result is authoritative once backfill is done.
        if ((ap_search_fts_meta_get('backfill_phase') ?? '') === 'done') {
            return [];
        }
    }

    $likeNeedle = $tagName !== '' ? '#' . $tagName : $qLower;
    $like = '%' . str_replace(['%', '_'], ['\\%', '\\_'], $likeNeedle) . '%';

    try {
        $st = ap_db()->prepare(
            "SELECT * FROM masto_statuses
             WHERE lower(content_text) LIKE ? ESCAPE '\\'
                OR lower(COALESCE(spoiler_text,'')) LIKE ? ESCAPE '\\'
             ORDER BY local_id DESC LIMIT ?"
        );
        $st->execute([$like, $like, $limit]);
        foreach ($st->fetchAll() as $row) {
            $push(ap_masto_status_from_row($row));
        }
    } catch (Throwable $e) {
        // ignore
    }
    if (count($out) >= $limit) {
        return $out;
    }

    try {
        $st = ap_db()->prepare(
            "SELECT * FROM mentions
             WHERE deleted_at IS NULL AND lower(COALESCE(content,'')) LIKE ? ESCAPE '\\'
             ORDER BY id DESC LIMIT ?"
        );
        $st->execute([$like, $limit]);
        foreach ($st->fetchAll() as $row) {
            $push(ap_masto_status_from_mention($row));
            if (count($out) >= $limit) {
                return $out;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    // Federated notes / boosts in the events firehose (hashtag / keyword hits).
    // Relay traffic is often Announce with the post text in summary.
    try {
        $st = ap_db()->prepare(
            "SELECT * FROM events
             WHERE summary IS NOT NULL AND summary != ''
               AND lower(summary) LIKE ? ESCAPE '\\'
               AND lower(COALESCE(type,'')) IN ('create','update','announce','note')
             ORDER BY id DESC LIMIT ?"
        );
        $st->execute([$like, max($limit * 4, 60)]);
        foreach ($st->fetchAll() as $row) {
            // For hashtags, require a real #tag token (avoid matching "feet" inside unrelated words)
            if ($tagName !== '') {
                $sum = html_entity_decode((string) ($row['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                if (!preg_match('/#' . preg_quote($tagName, '/') . '\b/ui', $sum)) {
                    continue;
                }
            }
            $status = ap_masto_status_from_event($row);
            if (is_array($status)) {
                $push($status);
            }
            if (count($out) >= $limit) {
                break;
            }
        }
    } catch (Throwable $e) {
        // ignore
    }

    return array_slice($out, 0, $limit);
}

/** True if hashtag was recently seen in local cache (cheap existence check). */
function ap_masto_search_tag_seen(string $name): bool
{
    $name = ap_masto_normalize_tag_name($name);
    if ($name === '') {
        return false;
    }
    if (!function_exists('ap_search_fts_exists')) {
        require_once __DIR__ . '/ap-search-fts.php';
    }
    if (function_exists('ap_search_fts_exists') && ap_search_fts_available() && ap_search_fts_exists('#' . $name, $name)) {
        return true;
    }
    $needle = '%#' . str_replace(['%', '_'], ['\\%', '\\_'], $name) . '%';
    try {
        $st = ap_db()->prepare(
            "SELECT 1 FROM events WHERE summary IS NOT NULL AND lower(summary) LIKE ? ESCAPE '\\' LIMIT 1"
        );
        $st->execute([$needle]);
        if ($st->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // ignore
    }
    try {
        $st = ap_db()->prepare(
            "SELECT 1 FROM outbox_notes WHERE lower(COALESCE(content,'')) LIKE ? ESCAPE '\\' LIMIT 1"
        );
        $st->execute([$needle]);
        if ($st->fetchColumn()) {
            return true;
        }
    } catch (Throwable $e) {
        // ignore
    }
    return false;
}

/**
 * Find an Announce whose fabricated inner Note id matches $statusId.
 * Covers type-8 announce_inner ids and legacy type-1 crc32 phantoms (pre-fix).
 *
 * @return array<string,mixed>|null events row
 */
function ap_masto_announce_row_for_inner_synth_id(int $statusId): ?array
{
    $p = ap_masto_parse_public_status_id($statusId);
    if (!$p || (int) ($p['ver'] ?? 0) !== 2) {
        return null;
    }
    $type = (string) ($p['type'] ?? '');
    // type 8 = current; type event with missing row = legacy phantom inners
    if ($type !== 'announce_inner' && $type !== 'event') {
        return null;
    }
    $dbMod = (int) ($p['db_id'] ?? 0);
    $sec = (int) ($p['sec'] ?? 0);
    if ($dbMod < 0 || $sec < 1) {
        return null;
    }
    $from = gmdate('c', max(0, $sec - 2));
    $to = gmdate('c', $sec + 2);
    try {
        $st = ap_db()->prepare(
            "SELECT * FROM events
             WHERE type = 'Announce'
               AND created_at >= ? AND created_at <= ?
             ORDER BY id DESC
             LIMIT 80"
        );
        $st->execute([$from, $to]);
        $rows = $st->fetchAll();
    } catch (Throwable $e) {
        return null;
    }
    if (!is_array($rows) || !$rows) {
        return null;
    }
    $wantType = $type === 'announce_inner' ? 8 : 1;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $oid = rtrim((string) ($row['object_id'] ?? ''), '/');
        if ($oid === '') {
            continue;
        }
        $created = (string) ($row['created_at'] ?? '');
        $cand = ap_masto_snowflake_id($created !== '' ? $created : gmdate('c', $sec), abs(crc32($oid)) % 100000000, $wantType);
        if ((int) $cand === $statusId) {
            return $row;
        }
        // Also accept type-8 match when client still holds a legacy type-1 id
        // for the same object (or vice versa) within the same second window.
        $altType = $wantType === 8 ? 1 : 8;
        $alt = ap_masto_snowflake_id($created !== '' ? $created : gmdate('c', $sec), abs(crc32($oid)) % 100000000, $altType);
        if ((int) $alt === $statusId) {
            return $row;
        }
    }
    return null;
}

/**
 * Build interaction pack for a synthetic boost-inner status id.
 *
 * @return array{status:array<string,mixed>,object_id:string,target_actor:?string,is_ours:bool}|null
 */
function ap_masto_resolve_announce_inner_interaction(int $statusId): ?array
{
    $erow = ap_masto_announce_row_for_inner_synth_id($statusId);
    if ($erow === null) {
        return null;
    }
    $full = ap_masto_status_from_event($erow);
    if (!$full) {
        return null;
    }
    // Prefer the inner Note entity — Ice Cubes bookmarks/favourites that id.
    $status = $full;
    if (!empty($full['reblog']) && is_array($full['reblog'])) {
        $status = $full['reblog'];
    }
    $status['id'] = (string) $statusId;
    $objectId = rtrim((string) ($status['uri'] ?? $erow['object_id'] ?? ''), '/');
    $actor = null;
    if (!empty($status['account']['url']) && is_string($status['account']['url'])) {
        $actor = (string) $status['account']['url'];
    } elseif (!empty($status['account']['uri']) && is_string($status['account']['uri'])) {
        $actor = (string) $status['account']['uri'];
    } else {
        $orig = ap_masto_announce_original_actor($erow);
        $actor = is_string($orig) && $orig !== '' ? $orig : null;
    }
    $ourPrefix = ap_masto_session_actor_id();
    $isOurs = $objectId !== '' && (
        $objectId === $ourPrefix
        || str_starts_with($objectId, $ourPrefix . '/')
    );
    if (!$isOurs && is_string($actor) && rtrim($actor, '/') === $ourPrefix) {
        $isOurs = true;
    }
    return [
        'status' => $status,
        'object_id' => $objectId,
        'target_actor' => $actor,
        'is_ours' => $isOurs,
    ];
}

/**
 * Resolve a public Mastodon status id for favourite/bookmark (local, event, mention, DM).
 *
 * @return array{status:array<string,mixed>,object_id:string,target_actor:?string,is_ours:bool}|null
 */
function ap_masto_resolve_status_interaction(int $statusId): ?array
{
    if ($statusId <= 0) {
        return null;
    }
    // Session actor — not hard-coded cmdr_nova (invitees interact with peers)
    $ourPrefix = ap_masto_session_actor_id();

    $pack = static function (array $status, string $objectId, ?string $targetActor) use ($ourPrefix): array {
        $objectId = rtrim($objectId, '/');
        $isOurs = $objectId !== '' && (
            $objectId === $ourPrefix
            || str_starts_with($objectId, $ourPrefix . '/')
        );
        if (!$isOurs && is_string($targetActor) && rtrim($targetActor, '/') === $ourPrefix) {
            $isOurs = true;
        }
        return [
            'status' => $status,
            'object_id' => $objectId,
            'target_actor' => $targetActor,
            'is_ours' => $isOurs,
        ];
    };

    $row = ap_masto_local_row_from_public_id($statusId)
        ?? (($statusId < 2000000) ? ap_masto_status_by_local_id($statusId) : null);
    if ($row) {
        $status = ap_masto_status_from_row($row);
        $noteId = (string) ($row['note_id'] ?? '');
        $noteActor = null;
        if ($noteId !== '' && preg_match('#^(https://mkultra\.monster/users/[A-Za-z0-9_]+)/#', $noteId, $nm)) {
            $noteActor = $nm[1];
        }
        return $pack($status, $noteId, $noteActor);
    }

    $mentionId = ap_masto_mention_id_from_status_id($statusId);
    if ($mentionId !== null) {
        $st = ap_db()->prepare('SELECT * FROM mentions WHERE id = ? AND deleted_at IS NULL');
        $st->execute([$mentionId]);
        $mrow = $st->fetch();
        if (is_array($mrow)) {
            $status = ap_masto_status_from_mention($mrow);
            $objectId = (string) ($mrow['object_id'] ?? '');
            $actor = (string) ($mrow['actor_id'] ?? '');
            return $pack($status, $objectId, $actor !== '' ? $actor : null);
        }
    }

    $eventId = ap_masto_event_id_from_status_id($statusId);
    if ($eventId !== null) {
        $st = ap_db()->prepare('SELECT * FROM events WHERE id = ?');
        $st->execute([$eventId]);
        $erow = $st->fetch();
        if (is_array($erow)) {
            $status = ap_masto_status_from_event($erow);
            if ($status) {
                $objectId = (string) ($erow['object_id'] ?? '');
                $actor = (string) ($erow['actor_id'] ?? '');
                return $pack($status, $objectId, $actor !== '' ? $actor : null);
            }
        }
        // Legacy: boost inners used type=1 + crc32(object) and looked like missing events.
        $synth = ap_masto_resolve_announce_inner_interaction($statusId);
        if ($synth !== null) {
            return $synth;
        }
    }

    // Current type-8 announce_inner snowflakes
    $parsed = ap_masto_parse_public_status_id($statusId);
    if ($parsed && ($parsed['type'] ?? '') === 'announce_inner') {
        $synth = ap_masto_resolve_announce_inner_interaction($statusId);
        if ($synth !== null) {
            return $synth;
        }
    }

    $dmId = ap_masto_dm_id_from_status_id($statusId);
    if ($dmId !== null) {
        $dm = ap_dm_by_id($dmId);
        if ($dm) {
            $status = ap_masto_status_from_dm($dm);
            $objectId = (string) ($dm['object_id'] ?? '');
            $peer = (string) ($dm['peer_actor_id'] ?? '');
            return $pack($status, $objectId, $peer !== '' ? $peer : null);
        }
    }

    return null;
}

/**
 * @return list<array<string,mixed>>
 */
function ap_masto_favourites_list(int $limit = 40, ?string $maxId = null): array
{
    $limit = max(1, min(80, $limit));
    $out = [];
    foreach (ap_masto_favourite_rows($limit, $maxId) as $row) {
        $status = ap_masto_interaction_row_to_status($row, 'favourited');
        if ($status === null) {
            continue;
        }
        $out[] = $status;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Hydrate a favourite/bookmark DB row into a Mastodon status.
 * Prefers status_id resolve; falls back to stored object_id when the snowflake
 * no longer maps (deleted event, legacy boost-inner phantom, etc.).
 *
 * @param array<string,mixed> $row
 * @param 'favourited'|'bookmarked' $flag
 * @return array<string,mixed>|null
 */
function ap_masto_interaction_row_to_status(array $row, string $flag): ?array
{
    $sid = (int) ($row['status_id'] ?? 0);
    $status = null;
    if ($sid > 0) {
        $resolved = ap_masto_resolve_status_interaction($sid);
        if ($resolved !== null) {
            $status = $resolved['status'];
        }
    }
    if ($status === null) {
        $oid = rtrim((string) ($row['object_id'] ?? ''), '/');
        if ($oid !== '' && str_starts_with($oid, 'https://')) {
            $status = ap_masto_lookup_status_by_object_url($oid, 0, false);
            if ($status === null && function_exists('ap_masto_status_from_as2_note')) {
                // Minimal stub so the bookmark/favourite still appears in VAAK
                $actor = (string) ($row['target_actor'] ?? '');
                $status = [
                    'id' => (string) ($row['status_id'] ?? '0'),
                    'created_at' => ap_masto_format_time((string) ($row['created_at'] ?? gmdate('c'))),
                    'in_reply_to_id' => null,
                    'in_reply_to_account_id' => null,
                    'sensitive' => false,
                    'spoiler_text' => '',
                    'visibility' => 'public',
                    'language' => null,
                    'uri' => $oid,
                    'url' => $oid,
                    'replies_count' => 0,
                    'reblogs_count' => 0,
                    'favourites_count' => 0,
                    'edited_at' => null,
                    'favourited' => false,
                    'reblogged' => false,
                    'muted' => false,
                    'bookmarked' => false,
                    'pinned' => false,
                    'content' => '<p><a href="' . htmlspecialchars($oid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">'
                        . htmlspecialchars($oid, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</a></p>',
                    'reblog' => null,
                    'application' => null,
                    'account' => $actor !== ''
                        ? (ap_masto_local_actor_key_from_url($actor) !== null
                            ? ap_masto_account_for_local_url($actor)
                            : ap_masto_remote_account($actor))
                        : ap_masto_remote_account($oid),
                    'media_attachments' => [],
                    'mentions' => [],
                    'tags' => [],
                    'emojis' => [],
                    'card' => null,
                    'poll' => null,
                ];
            }
        }
    }
    if (!is_array($status)) {
        return null;
    }
    if ($flag === 'favourited') {
        $status['favourited'] = true;
    } else {
        $status['bookmarked'] = true;
    }
    return $status;
}

/**
 * @return list<array<string,mixed>>
 */
function ap_masto_bookmarks_list(int $limit = 40, ?string $maxId = null): array
{
    $limit = max(1, min(80, $limit));
    $out = [];
    foreach (ap_masto_bookmark_rows($limit, $maxId) as $row) {
        $status = ap_masto_interaction_row_to_status($row, 'bookmarked');
        if ($status === null) {
            continue;
        }
        $out[] = $status;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Mastodon-shaped boost wrapper status for one of our Announces.
 *
 * @param array<string,mixed> $reblogRow masto_reblogs row
 * @param array<string,mixed> $original original status entity
 * @return array<string,mixed>
 */
function ap_masto_status_from_reblog(array $reblogRow, array $original): array
{
    $created = ap_masto_format_time((string) ($reblogRow['created_at'] ?? gmdate('c')));
    $dbId = (int) ($reblogRow['id'] ?? 0);
    $boostId = (string) ($reblogRow['boost_status_id'] ?? '');
    if ($boostId === '' && $dbId > 0) {
        $boostId = ap_masto_snowflake_id((string) ($reblogRow['created_at'] ?? gmdate('c')), $dbId, 4);
    }
    $announceUri = (string) ($reblogRow['announce_activity_id'] ?? '');
    // Unwrap nested boosts — wrapper always points at the original Note
    if (!empty($original['reblog']) && is_array($original['reblog'])) {
        $original = $original['reblog'];
    }
    $inner = ap_masto_apply_interaction_flags($original);
    $inner['reblogged'] = true;
    return [
        'id' => $boostId,
        'created_at' => $created,
        'in_reply_to_id' => null,
        'in_reply_to_account_id' => null,
        'sensitive' => false,
        'spoiler_text' => '',
        'visibility' => 'public',
        'language' => null,
        'uri' => $announceUri !== ''
            ? $announceUri
            : (rtrim((string) ($reblogRow['owner_actor_id'] ?? ap_masto_session_actor_id()), '/') . '/announces/' . $boostId),
        'url' => (string) ($original['url'] ?? $original['uri'] ?? ''),
        'replies_count' => 0,
        'reblogs_count' => 1,
        'favourites_count' => 0,
        'edited_at' => null,
        'favourited' => false,
        'reblogged' => true,
        'muted' => false,
        'bookmarked' => false,
        'pinned' => false,
        'content' => '',
        'reblog' => $inner,
        'application' => ['name' => 'mkultra.monster', 'website' => 'https://mkultra.monster'],
        'account' => !empty($reblogRow['owner_actor_id'])
            ? ap_masto_account_for_local_url((string) $reblogRow['owner_actor_id'])
            : ap_masto_account(),
        'media_attachments' => [],
        'mentions' => [],
        'tags' => [],
        'emojis' => [],
        'card' => null,
        'poll' => null,
    ];
}

/**
 * Build Mastodon Status entities for our outbound boosts.
 *
 * @return list<array<string,mixed>>
 */
function ap_masto_own_reblogs_as_statuses(int $limit = 40, ?string $maxId = null, ?int $ownerUserId = null): array
{
    static $didBackfill = false;
    if (!$didBackfill && function_exists('ap_masto_backfill_reblogs_from_events')) {
        $didBackfill = true;
        try {
            ap_masto_backfill_reblogs_from_events();
        } catch (Throwable $e) {
            // non-fatal
        }
    }
    $limit = max(1, min(80, $limit));
    $out = [];
    foreach (ap_masto_reblog_rows($limit, $maxId, $ownerUserId) as $row) {
        $targetActor = (string) ($row['target_actor'] ?? '');
        if ($targetActor !== '' && function_exists('ap_row_is_hidden')
            && ap_row_is_hidden($targetActor, null, $ownerUserId)) {
            continue;
        }
        $original = null;
        $sid = (int) ($row['status_id'] ?? 0);
        if ($sid > 0) {
            $resolved = ap_masto_resolve_status_interaction($sid);
            if ($resolved !== null) {
                $original = $resolved['status'];
            }
        }
        if ($original === null) {
            $objectId = rtrim((string) ($row['object_id'] ?? ''), '/');
            if ($objectId !== '' && function_exists('ap_event_by_object_id')) {
                $erow = ap_event_by_object_id($objectId);
                if (is_array($erow)) {
                    // Allow empty / Announce-shaped rows for boost display
                    $erow['_allow_empty_for_context'] = true;
                    // Don't treat our own Creates as the boosted remote note
                    $actor = rtrim((string) ($erow['actor_id'] ?? ''), '/');
                    $boostOwner = rtrim((string) ($row['owner_actor_id'] ?? ap_masto_session_actor_id()), '/');
                    if ($actor === '' || ($actor !== $boostOwner && !ap_masto_is_local_actor_url($actor))) {
                        $original = ap_masto_status_from_event($erow);
                    }
                }
            }
        }
        if ($original === null) {
            // Minimal stub so the boost still appears
            $objectId = (string) ($row['object_id'] ?? '');
            $target = (string) ($row['target_actor'] ?? '');
            $original = [
                'id' => (string) ($row['status_id'] ?? ''),
                'created_at' => ap_masto_format_time((string) ($row['created_at'] ?? null)),
                'in_reply_to_id' => null,
                'in_reply_to_account_id' => null,
                'sensitive' => false,
                'spoiler_text' => '',
                'visibility' => 'public',
                'language' => null,
                'uri' => $objectId,
                'url' => $objectId,
                'replies_count' => 0,
                'reblogs_count' => 0,
                'favourites_count' => 0,
                'edited_at' => null,
                'favourited' => false,
                'reblogged' => true,
                'muted' => false,
                'bookmarked' => false,
                'content' => '<p></p>',
                'reblog' => null,
                'account' => $target !== '' ? ap_masto_remote_account($target) : ap_masto_account(),
                'media_attachments' => [],
                'mentions' => [],
                'tags' => [],
                'emojis' => [],
                'card' => null,
                'poll' => null,
            ];
        }
        $out[] = ap_masto_status_from_reblog($row, $original);
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

/**
 * Build boost/unboost response for a resolved original status.
 *
 * @param array{status:array<string,mixed>,object_id:string,target_actor:?string,is_ours:bool} $resolved
 * @return array{ok:bool,status?:array<string,mixed>,error?:string}
 */
function ap_masto_reblog_perform(array $resolved, bool $undo = false): array
{
    $original = $resolved['status'];
    $statusId = (string) ($original['id'] ?? '');
    if ($statusId === '') {
        return ['ok' => false, 'error' => 'missing status id'];
    }
    $objectId = (string) ($resolved['object_id'] ?? '');
    if (!empty($original['reblog']) && is_array($original['reblog']) && !empty($original['reblog']['uri'])) {
        // Don't boost a boost — boost the underlying Note
        $objectId = (string) $original['reblog']['uri'];
        $original = $original['reblog'];
        $statusId = (string) ($original['id'] ?? $statusId);
    }
    $objectId = preg_replace('/#announce-\d+$/', '', $objectId) ?? $objectId;
    if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
        return ['ok' => false, 'error' => 'missing object uri'];
    }
    // Prefer AP actor id over profile URL (/@handle). Wrong target_actor breaks
    // author cc on Announces and confuses remote servers.
    $targetActor = $resolved['target_actor'];
    if (!empty($original['account']['id']) && is_string($original['account']['id'])
        && str_starts_with((string) $original['account']['id'], 'https://')) {
        $targetActor = (string) $original['account']['id'];
    } elseif (!empty($original['account']['url']) && is_string($original['account']['url'])
        && str_starts_with((string) $original['account']['url'], 'https://')
        && !preg_match('#https://[^/]+/@[^/]+/?$#', (string) $original['account']['url'])) {
        $targetActor = (string) $original['account']['url'];
    }
    if ((!is_string($targetActor) || $targetActor === '' || preg_match('#https://[^/]+/@[^/]+/?$#', $targetActor))
        && preg_match('#^(https://[^/]+/users/[^/]+)/#', $objectId, $am)) {
        $targetActor = $am[1];
    }

    if ($undo) {
        $prev = ap_masto_reblog_remove($statusId);
        if ($prev && !empty($prev['announce_activity_id'])) {
            if (!function_exists('ap_cmdr_send_undo_announce')) {
                if (!defined('AP_INBOX_LIB_ONLY')) {
                    define('AP_INBOX_LIB_ONLY', true);
                }
                require_once __DIR__ . '/ap-inbox.php';
            }
            ap_cmdr_send_undo_announce(
                (string) $prev['announce_activity_id'],
                (string) ($prev['object_id'] ?? $objectId),
                isset($prev['target_actor']) ? (string) $prev['target_actor'] : $targetActor
            );
        }
        $original['reblogged'] = false;
        return ['ok' => true, 'status' => ap_masto_apply_interaction_flags($original)];
    }

    $existing = ap_masto_reblog_row_by_status($statusId);
    if ($existing) {
        return ['ok' => true, 'status' => ap_masto_status_from_reblog($existing, $original)];
    }

    if (!function_exists('ap_cmdr_send_announce')) {
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
    }
    $fan = ap_cmdr_send_announce($objectId, is_string($targetActor) ? $targetActor : null);
    if (empty($fan['ok']) || empty($fan['announce_id'])) {
        return ['ok' => false, 'error' => $fan['error'] ?? 'Could not boost'];
    }
    // Best-effort Bluesky repost when the target has an AT twin (or is a bsky URL).
    try {
        if (!function_exists('ap_bsky_repost_object')) {
            require_once __DIR__ . '/ap-bsky.php';
        }
        if (function_exists('ap_bsky_repost_object') && function_exists('ap_db_masto_owner_user_id')) {
            $bskyOwner = ap_db_masto_owner_user_id();
            if ($bskyOwner > 0) {
                ap_bsky_repost_object($bskyOwner, $objectId);
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-masto] bsky_repost: ' . $e->getMessage());
    }
    $created = gmdate('c');
    // Provisional id until we know DB id — insert then update boost_status_id
    $tmpBoostId = 'pending-' . bin2hex(random_bytes(4));
    $ins = ap_masto_reblog_add(
        $statusId,
        $tmpBoostId,
        $objectId,
        is_string($targetActor) ? $targetActor : null,
        (string) $fan['announce_id']
    );
    if (empty($ins['ok']) || empty($ins['row'])) {
        return ['ok' => false, 'error' => $ins['error'] ?? 'Could not store boost'];
    }
    $row = $ins['row'];
    $dbId = (int) ($row['id'] ?? 0);
    $boostId = ap_masto_snowflake_id($created, $dbId > 0 ? $dbId : ((int) sprintf('%u', crc32($statusId)) % 10000), 4);
    try {
        ap_db()->prepare('UPDATE masto_reblogs SET boost_status_id = ?, created_at = ? WHERE id = ?')
            ->execute([$boostId, $created, $dbId]);
        $row['boost_status_id'] = $boostId;
        $row['created_at'] = $created;
    } catch (Throwable $e) {
        // keep tmp id
    }
    return ['ok' => true, 'status' => ap_masto_status_from_reblog($row, $original)];
}
