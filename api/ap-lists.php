<?php
/**
 * Private Mastodon-style Lists (Library → Lists).
 * Separate from public Collections / starter packs (ap-collections.php).
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


const AP_LIST_MAX = 50;
const AP_LIST_TITLE_MAX = 256;
/** Soft UI/API cap per list (Mastodon has no hard member limit). */
const AP_LIST_MAX_MEMBERS = 500;

/** Import list records owned by the connected Bluesky account into VAAK. */
function ap_lists_sync_bsky(int $ownerUserId, bool $force = false): array
{
    if ($ownerUserId < 1 || !function_exists('ap_bsky_session_row') || ap_bsky_session_row($ownerUserId) === null) {
        return ['ok' => true, 'synced' => 0, 'skipped' => true];
    }
    $cache = sys_get_temp_dir() . '/vaak-bsky-lists-' . $ownerUserId . '.json';
    if (!$force && is_file($cache) && time() - (int) @filemtime($cache) < 300) {
        return ['ok' => true, 'synced' => 0, 'cached' => true];
    }
    // Push VAAK-owned lists created before the Bluesky connection, plus any
    // pending Bluesky members, before importing the remote snapshot.
    try {
        $st = ap_db()->prepare("SELECT * FROM masto_lists WHERE owner_user_id = ? AND bsky_list_uri IS NULL AND COALESCE(bsky_list_source, 'vaak') = 'vaak'");
        $st->execute([$ownerUserId]);
        foreach ($st->fetchAll() ?: [] as $localList) {
            $purpose = ($localList['list_kind'] ?? 'curation') === 'moderation' ? 'moderation' : 'curation';
            $made = ap_bsky_create_graph_list($ownerUserId, (string) ($localList['title'] ?? ''), $purpose);
            if (empty($made['ok']) || empty($made['uri'])) continue;
            $uri = (string) $made['uri'];
            $action = (string) ($localList['bsky_moderation_action'] ?? 'none');
            $actionUri = null;
            if ($purpose === 'moderation' && in_array($action, ['mute', 'block'], true)) {
                $sub = ap_bsky_set_graph_list_moderation($ownerUserId, $uri, $action);
                if (empty($sub['ok'])) continue;
                $actionUri = (string) ($sub['uri'] ?? '');
            }
            ap_db()->prepare('UPDATE masto_lists SET bsky_list_uri = ?, bsky_mod_action_uri = ? WHERE id = ? AND owner_user_id = ?')
                ->execute([$uri, $actionUri !== '' ? $actionUri : null, (int) $localList['id'], $ownerUserId]);
        }
        $st = ap_db()->prepare("SELECT l.id, l.bsky_list_uri, l.list_kind, l.bsky_moderation_action, a.actor_id, a.bsky_did, a.bsky_item_uri FROM masto_lists l INNER JOIN masto_list_accounts a ON a.list_id = l.id WHERE l.owner_user_id = ? AND l.bsky_list_uri IS NOT NULL AND COALESCE(l.bsky_list_source, 'vaak') = 'vaak' AND a.bsky_item_uri IS NULL");
        $st->execute([$ownerUserId]);
        foreach ($st->fetchAll() ?: [] as $member) {
            $memberDid = (string) ($member['bsky_did'] ?? '');
            if ($memberDid === '' && function_exists('ap_bsky_resolve_target_did')) {
                $memberDid = (string) (ap_bsky_resolve_target_did((string) ($member['actor_id'] ?? ''), $ownerUserId) ?? '');
            }
            if (!str_starts_with($memberDid, 'did:')) continue;
            $added = ap_bsky_add_graph_list_member($ownerUserId, (string) $member['bsky_list_uri'], $memberDid);
            if (empty($added['ok'])) continue;
            ap_db()->prepare('UPDATE masto_list_accounts SET bsky_did = ?, bsky_item_uri = ? WHERE list_id = ? AND actor_id = ?')
                ->execute([$memberDid, (string) ($added['uri'] ?? ''), (int) $member['id'], (string) $member['actor_id']]);
        }
    } catch (Throwable $e) {
        error_log('[ap-lists] push VAAK lists to Bluesky: ' . $e->getMessage());
    }
    $session = ap_bsky_session_row($ownerUserId);
    $did = (string) ($session['did'] ?? '');
    $cursor = null;
    $remoteLists = [];
    for ($page = 0; $page < 10; $page++) {
        $query = ['actor' => $did, 'limit' => 100];
        if ($cursor !== null) $query['cursor'] = $cursor;
        $res = ap_bsky_account_xrpc($ownerUserId, 'app.bsky.graph.getLists', 'GET', $query);
        if (empty($res['ok']) || !is_array($res['json'] ?? null)) {
            return ['ok' => false, 'error' => (string) ($res['error'] ?? 'Could not load your Bluesky lists')];
        }
        $remoteLists = array_merge($remoteLists, (array) ($res['json']['lists'] ?? []));
        $cursor = isset($res['json']['cursor']) && is_string($res['json']['cursor']) ? $res['json']['cursor'] : null;
        if ($cursor === null) break;
    }
    $moderationUris = ['mute' => [], 'block' => []];
    $moderationViews = ['mute' => [], 'block' => []];
    $subscriptionFetchComplete = ['mute' => false, 'block' => false];
    foreach (['mute' => 'app.bsky.graph.getListMutes', 'block' => 'app.bsky.graph.getListBlocks'] as $mode => $nsid) {
        $cursor = null;
        for ($page = 0; $page < 10; $page++) {
            $query = ['limit' => 100];
            if ($cursor !== null) $query['cursor'] = $cursor;
            $res = ap_bsky_account_xrpc($ownerUserId, $nsid, 'GET', $query);
            if (empty($res['ok']) || !is_array($res['json'] ?? null)) break;
            foreach ((array) ($res['json']['lists'] ?? []) as $row) {
                $uri = (string) ($row['uri'] ?? '');
                if ($uri !== '') {
                    $moderationUris[$mode][$uri] = true;
                    $moderationViews[$mode][$uri] = $row;
                }
            }
            $cursor = isset($res['json']['cursor']) && is_string($res['json']['cursor']) ? $res['json']['cursor'] : null;
            if ($cursor === null) {
                $subscriptionFetchComplete[$mode] = true;
                break;
            }
        }
    }
    $synced = 0;
    foreach ($remoteLists as $remote) {
        if (!is_array($remote)) continue;
        $listView = is_array($remote['list'] ?? null) ? $remote['list'] : $remote;
        $uri = trim((string) ($listView['uri'] ?? ''));
        $purpose = (string) ($listView['purpose'] ?? '');
        if (!str_starts_with($uri, 'at://') || !in_array($purpose, ['app.bsky.graph.defs#curatelist', 'app.bsky.graph.defs#modlist'], true)) continue;
        $kind = $purpose === 'app.bsky.graph.defs#modlist' ? 'moderation' : 'curation';
        $title = trim((string) ($listView['name'] ?? 'Untitled Bluesky list')) ?: 'Untitled Bluesky list';
        try {
            $st = ap_db()->prepare('SELECT id FROM masto_lists WHERE owner_user_id = ? AND bsky_list_uri = ? LIMIT 1');
            $st->execute([$ownerUserId, $uri]);
            $listId = (int) ($st->fetchColumn() ?: 0);
            $action = isset($moderationUris['block'][$uri]) ? 'block' : (isset($moderationUris['mute'][$uri]) ? 'mute' : 'none');
            if ($listId > 0) {
                ap_db()->prepare('UPDATE masto_lists SET title = ?, list_kind = ?, bsky_moderation_action = ?, updated_at = ? WHERE id = ? AND owner_user_id = ?')
                    ->execute([$title, $kind, $action, ap_db_now(), $listId, $ownerUserId]);
            } else {
                ap_db()->prepare('INSERT INTO masto_lists (owner_user_id, title, list_kind, bsky_list_uri, bsky_moderation_action, bsky_list_source, replies_policy, exclusive, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?, 0, ?, ?)')
                    ->execute([$ownerUserId, $title, $kind, $uri, $action, 'bsky', 'list', ap_db_now(), ap_db_now()]);
                $listId = (int) ap_db_last_insert_id('masto_lists');
            }
        } catch (Throwable $e) {
            error_log('[ap-lists] import Bluesky list: ' . $e->getMessage());
            continue;
        }
        $members = ap_bsky_get_graph_list($ownerUserId, $uri, AP_LIST_MAX_MEMBERS);
        if (empty($members['ok']) || empty($members['complete'])) continue;
        try {
            ap_db()->prepare('DELETE FROM masto_list_accounts WHERE list_id = ? AND bsky_did IS NOT NULL')->execute([$listId]);
            foreach ((array) ($members['items'] ?? []) as $member) {
                $subject = is_array($member['subject'] ?? null) ? $member['subject'] : [];
                $memberDid = (string) ($subject['did'] ?? '');
                if (!str_starts_with($memberDid, 'did:')) continue;
                $memberUrl = 'https://bsky.app/profile/' . rawurlencode($memberDid);
                $itemUri = (string) ($member['uri'] ?? '');
                ap_db()->prepare('INSERT INTO masto_list_accounts (list_id, actor_id, bsky_did, bsky_item_uri, added_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(list_id, actor_id) DO UPDATE SET bsky_did = excluded.bsky_did, bsky_item_uri = excluded.bsky_item_uri')
                    ->execute([$listId, $memberUrl, $memberDid, $itemUri !== '' ? $itemUri : null, ap_db_now()]);
            }
        } catch (Throwable $e) {
            error_log('[ap-lists] import Bluesky list members: ' . $e->getMessage());
            continue;
        }
        $synced++;
    }
    // Also show moderation lists owned by other Bluesky users that this
    // account currently subscribes to. These are locally mirrored as
    // removable subscriptions, never mistaken for VAAK-owned list records.
    foreach (['mute' => 'mute', 'block' => 'block'] as $mode => $defaultAction) {
        foreach ($moderationViews[$mode] as $uri => $listView) {
            $title = trim((string) ($listView['name'] ?? 'Untitled Bluesky moderation list')) ?: 'Untitled Bluesky moderation list';
            try {
                $st = ap_db()->prepare('SELECT id, bsky_list_source FROM masto_lists WHERE owner_user_id = ? AND bsky_list_uri = ? LIMIT 1');
                $st->execute([$ownerUserId, $uri]);
                $existing = $st->fetch();
                if (is_array($existing) && ($existing['bsky_list_source'] ?? '') !== 'subscription') continue;
                if (is_array($existing)) {
                    $listId = (int) $existing['id'];
                    ap_db()->prepare("UPDATE masto_lists SET title = ?, list_kind = 'moderation', bsky_moderation_action = ?, updated_at = ? WHERE id = ? AND owner_user_id = ?")
                        ->execute([$title, $defaultAction, ap_db_now(), $listId, $ownerUserId]);
                } else {
                    ap_db()->prepare("INSERT INTO masto_lists (owner_user_id, title, list_kind, bsky_list_uri, bsky_moderation_action, bsky_list_source, replies_policy, exclusive, created_at, updated_at) VALUES (?, ?, 'moderation', ?, ?, 'subscription', 'list', 0, ?, ?)")
                        ->execute([$ownerUserId, $title, $uri, $defaultAction, ap_db_now(), ap_db_now()]);
                    $listId = (int) ap_db_last_insert_id('masto_lists');
                }
                $members = ap_bsky_get_graph_list($ownerUserId, $uri, AP_LIST_MAX_MEMBERS);
                if (empty($members['ok']) || empty($members['complete'])) continue;
                ap_db()->prepare('DELETE FROM masto_list_accounts WHERE list_id = ? AND bsky_did IS NOT NULL')->execute([$listId]);
                foreach ((array) ($members['items'] ?? []) as $member) {
                    $subject = is_array($member['subject'] ?? null) ? $member['subject'] : [];
                    $memberDid = (string) ($subject['did'] ?? '');
                    if (!str_starts_with($memberDid, 'did:')) continue;
                    $memberUrl = 'https://bsky.app/profile/' . rawurlencode($memberDid);
                    ap_db()->prepare('INSERT INTO masto_list_accounts (list_id, actor_id, bsky_did, bsky_item_uri, added_at) VALUES (?, ?, ?, ?, ?) ON CONFLICT(list_id, actor_id) DO UPDATE SET bsky_did = excluded.bsky_did, bsky_item_uri = excluded.bsky_item_uri')
                        ->execute([$listId, $memberUrl, $memberDid, (string) ($member['uri'] ?? '') ?: null, ap_db_now()]);
                }
                $synced++;
            } catch (Throwable $e) {
                error_log('[ap-lists] import subscribed Bluesky moderation list: ' . $e->getMessage());
            }
        }
    }
    if ($subscriptionFetchComplete['mute'] && $subscriptionFetchComplete['block']) {
        try {
            $st = ap_db()->prepare("SELECT id, bsky_list_uri FROM masto_lists WHERE owner_user_id = ? AND list_kind = 'moderation' AND bsky_list_source = 'subscription' AND bsky_moderation_action <> 'none'");
            $st->execute([$ownerUserId]);
            foreach ($st->fetchAll() ?: [] as $inactive) {
                $uri = (string) ($inactive['bsky_list_uri'] ?? '');
                if (!isset($moderationUris['mute'][$uri]) && !isset($moderationUris['block'][$uri])) {
                    ap_db()->prepare("UPDATE masto_lists SET bsky_moderation_action = 'none', bsky_mod_action_uri = NULL, updated_at = ? WHERE id = ? AND owner_user_id = ?")
                        ->execute([ap_db_now(), (int) $inactive['id'], $ownerUserId]);
                }
            }
        } catch (Throwable $e) {
            error_log('[ap-lists] reconcile unsubscribed Bluesky moderation lists: ' . $e->getMessage());
        }
    }
    ap_lists_moderation_cache_clear($ownerUserId);
    @file_put_contents($cache, json_encode(['at' => time(), 'synced' => $synced]), LOCK_EX);
    return ['ok' => true, 'synced' => $synced];
}

/**
 * @return list<array<string,mixed>>
 */
function ap_list_owner_user_id(?int $ownerUserId = null): int
{
    $ownerUserId = $ownerUserId ?? ap_db_default_owner_user_id();
    return $ownerUserId > 0 ? $ownerUserId : 0;
}

function ap_lists_all(?int $ownerUserId = null): array
{
    $ownerUserId = ap_list_owner_user_id($ownerUserId);
    if ($ownerUserId < 1) {
        return [];
    }
    try {
        $st = ap_db()->prepare(
            'SELECT l.*,
               (SELECT COUNT(*) FROM masto_list_accounts a WHERE a.list_id = l.id) AS member_count
             FROM masto_lists l
             WHERE l.owner_user_id = ?
             ORDER BY l.updated_at DESC, l.id DESC'
        );
        $st->execute([$ownerUserId]);
        $rows = $st->fetchAll();
        return is_array($rows) ? $rows : [];
    } catch (Throwable $e) {
        error_log('[ap-lists] list_all: ' . $e->getMessage());
        return [];
    }
}

function ap_list_by_id(int $id, ?int $ownerUserId = null): ?array
{
    $ownerUserId = ap_list_owner_user_id($ownerUserId);
    if ($id <= 0 || $ownerUserId < 1) {
        return null;
    }
    try {
        $st = ap_db()->prepare('SELECT * FROM masto_lists WHERE id = ? AND owner_user_id = ?');
        $st->execute([$id, $ownerUserId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * @return list<array<string,mixed>>
 */
function ap_list_accounts(int $listId): array
{
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM masto_list_accounts WHERE list_id = ? ORDER BY added_at DESC, actor_id ASC'
        );
        $st->execute([$listId]);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * @return list<string>
 */
function ap_list_actor_ids(int $listId): array
{
    $out = [];
    foreach (ap_list_accounts($listId) as $row) {
        $aid = rtrim(trim((string) ($row['actor_id'] ?? '')), '/');
        if ($aid !== '') {
            $out[] = $aid;
        }
    }
    return $out;
}

function ap_list_normalize_replies_policy(string $policy): ?string
{
    $policy = strtolower(trim($policy));
    if (in_array($policy, ['list', 'followed', 'none'], true)) {
        return $policy;
    }
    return null;
}

/**
 * Mastodon List entity.
 *
 * @param array<string,mixed> $row
 * @return array{id:string,title:string,replies_policy:string,exclusive:bool}
 */
function ap_list_to_masto(array $row): array
{
    $policy = (string) ($row['replies_policy'] ?? 'list');
    if (ap_list_normalize_replies_policy($policy) === null) {
        $policy = 'list';
    }
    return [
        'id' => (string) (int) ($row['id'] ?? 0),
        'title' => (string) ($row['title'] ?? ''),
        'replies_policy' => $policy,
        'exclusive' => !empty($row['exclusive']),
    ];
}

/**
 * @return array{ok:bool,error?:string,id?:int,list?:array}
 */
function ap_list_create(string $title, string $repliesPolicy = 'list', bool $exclusive = false, string $kind = 'curation', string $moderationAction = 'none'): array
{
    $ownerUserId = ap_list_owner_user_id();
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Not signed in'];
    }
    $title = trim(ap_fix_utf8($title));
    if ($title === '') {
        return ['ok' => false, 'error' => 'Title is required'];
    }
    if (mb_strlen($title) > AP_LIST_TITLE_MAX) {
        return ['ok' => false, 'error' => 'Title is too long (max ' . AP_LIST_TITLE_MAX . ' characters)'];
    }
    $policy = ap_list_normalize_replies_policy($repliesPolicy);
    if ($policy === null) {
        return ['ok' => false, 'error' => "'" . $repliesPolicy . "' is not a valid replies_policy"];
    }
    $kind = $kind === 'moderation' ? 'moderation' : 'curation';
    $moderationAction = in_array($moderationAction, ['mute', 'block'], true) ? $moderationAction : 'none';
    if ($kind === 'curation') $moderationAction = 'none';
    try {
        $stc = ap_db()->prepare('SELECT COUNT(*) FROM masto_lists WHERE owner_user_id = ?');
        $stc->execute([$ownerUserId]);
        $count = (int) $stc->fetchColumn();
        if ($count >= AP_LIST_MAX) {
            return ['ok' => false, 'error' => 'List limit reached (max ' . AP_LIST_MAX . ')'];
        }
        $now = ap_db_now();
        ap_db()->prepare(
            'INSERT INTO masto_lists (owner_user_id, title, list_kind, bsky_moderation_action, bsky_list_source, replies_policy, exclusive, created_at, updated_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
        )->execute([$ownerUserId, $title, $kind, $moderationAction, 'vaak', $policy, $exclusive ? 1 : 0, $now, $now]);
        $id = ap_db_last_insert_id('masto_lists');
        $bskyUri = '';
        $bskyActionUri = '';
        if (function_exists('ap_bsky_session_row') && ap_bsky_session_row($ownerUserId) !== null) {
            if (mb_strlen($title) > 64) {
                ap_db()->prepare('DELETE FROM masto_lists WHERE id = ? AND owner_user_id = ?')->execute([$id, $ownerUserId]);
                return ['ok' => false, 'error' => 'Bluesky list names are limited to 64 characters'];
            }
            $remote = ap_bsky_create_graph_list($ownerUserId, $title, $kind);
            if (empty($remote['ok'])) {
                ap_db()->prepare('DELETE FROM masto_lists WHERE id = ? AND owner_user_id = ?')->execute([$id, $ownerUserId]);
                return ['ok' => false, 'error' => 'Could not create synchronized Bluesky list: ' . (string) ($remote['error'] ?? 'Unknown error')];
            }
            $bskyUri = (string) ($remote['uri'] ?? '');
            if ($kind === 'moderation' && $moderationAction !== 'none') {
                $sub = ap_bsky_set_graph_list_moderation($ownerUserId, $bskyUri, $moderationAction);
                if (empty($sub['ok'])) {
                    ap_bsky_delete_record_uri($ownerUserId, $bskyUri);
                    ap_db()->prepare('DELETE FROM masto_lists WHERE id = ? AND owner_user_id = ?')->execute([$id, $ownerUserId]);
                    return ['ok' => false, 'error' => 'Bluesky list created, but subscription failed: ' . (string) ($sub['error'] ?? 'Unknown error')];
                }
                $bskyActionUri = (string) ($sub['uri'] ?? '');
            }
            ap_db()->prepare('UPDATE masto_lists SET bsky_list_uri = ?, bsky_mod_action_uri = ? WHERE id = ? AND owner_user_id = ?')
                ->execute([$bskyUri, $bskyActionUri !== '' ? $bskyActionUri : null, $id, $ownerUserId]);
        }
        $row = ap_list_by_id($id);
        return ['ok' => true, 'id' => $id, 'list' => is_array($row) ? $row : []];
    } catch (Throwable $e) {
        error_log('[ap-lists] create: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not create list'];
    }
}

/**
 * @param array<string,mixed> $fields
 * @return array{ok:bool,error?:string,list?:array}
 */
function ap_list_update(int $id, array $fields): array
{
    $ownerUserId = ap_list_owner_user_id();
    $row = ap_list_by_id($id, $ownerUserId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Record not found'];
    }
    $title = array_key_exists('title', $fields)
        ? trim(ap_fix_utf8((string) $fields['title']))
        : (string) $row['title'];
    if ($title === '') {
        return ['ok' => false, 'error' => 'Title is required'];
    }
    if (mb_strlen($title) > AP_LIST_TITLE_MAX) {
        return ['ok' => false, 'error' => 'Title is too long (max ' . AP_LIST_TITLE_MAX . ' characters)'];
    }
    $policy = (string) ($row['replies_policy'] ?? 'list');
    if (array_key_exists('replies_policy', $fields)) {
        $norm = ap_list_normalize_replies_policy((string) $fields['replies_policy']);
        if ($norm === null) {
            return ['ok' => false, 'error' => "'" . $fields['replies_policy'] . "' is not a valid replies_policy"];
        }
        $policy = $norm;
    }
    $exclusive = array_key_exists('exclusive', $fields)
        ? (!empty($fields['exclusive']) ? 1 : 0)
        : (int) ($row['exclusive'] ?? 0);
    try {
        ap_db()->prepare(
            'UPDATE masto_lists SET title = ?, replies_policy = ?, exclusive = ?, updated_at = ? WHERE id = ? AND owner_user_id = ?'
        )->execute([$title, $policy, $exclusive, ap_db_now(), $id, $ownerUserId]);
        $fresh = ap_list_by_id($id, $ownerUserId);
        return ['ok' => true, 'list' => is_array($fresh) ? $fresh : $row];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not update list'];
    }
}

/** Change how a moderation list is enforced locally and, when linked, on Bluesky. */
function ap_list_set_moderation_action(int $id, string $action): array
{
    if (!in_array($action, ['none', 'mute', 'block'], true)) return ['ok' => false, 'error' => 'Choose VAAK only, mute, or block'];
    $ownerUserId = ap_list_owner_user_id();
    $row = ap_list_by_id($id, $ownerUserId);
    if (!$row || ($row['list_kind'] ?? '') !== 'moderation') return ['ok' => false, 'error' => 'Moderation list not found'];
    $old = (string) ($row['bsky_moderation_action'] ?? 'none');
    $uri = (string) ($row['bsky_list_uri'] ?? '');
    $actionUri = (string) ($row['bsky_mod_action_uri'] ?? '');
    if ($uri !== '' && $old !== $action && in_array($old, ['mute', 'block'], true)) {
        $removed = ap_bsky_remove_graph_list_moderation($ownerUserId, $uri, $old, $actionUri);
        if (empty($removed['ok'])) return ['ok' => false, 'error' => 'Could not remove the old Bluesky list action: ' . (string) ($removed['error'] ?? '')];
        $actionUri = '';
    }
    if ($uri !== '' && $action !== $old && in_array($action, ['mute', 'block'], true)) {
        $added = ap_bsky_set_graph_list_moderation($ownerUserId, $uri, $action);
        if (empty($added['ok'])) {
            if (in_array($old, ['mute', 'block'], true)) {
                $restore = ap_bsky_set_graph_list_moderation($ownerUserId, $uri, $old);
                if (empty($restore['ok'])) error_log('[ap-lists] could not restore prior Bluesky moderation subscription after a failed action change');
            }
            return ['ok' => false, 'error' => 'Could not apply the Bluesky list action: ' . (string) ($added['error'] ?? '')];
        }
        $actionUri = (string) ($added['uri'] ?? '');
    }
    try {
        ap_db()->prepare('UPDATE masto_lists SET bsky_moderation_action = ?, bsky_mod_action_uri = ?, updated_at = ? WHERE id = ? AND owner_user_id = ?')
            ->execute([$action, $actionUri !== '' ? $actionUri : null, ap_db_now(), $id, $ownerUserId]);
        ap_lists_moderation_cache_clear($ownerUserId);
        ap_bsky_refresh_hide_set($ownerUserId, true);
        if (function_exists('ap_bsky_hide_did_set_clear_cache')) ap_bsky_hide_did_set_clear_cache($ownerUserId);
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not save moderation list action'];
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_list_delete(int $id): array
{
    $ownerUserId = ap_list_owner_user_id();
    $row = ap_list_by_id($id, $ownerUserId);
    if (!$row) {
        return ['ok' => false, 'error' => 'Record not found'];
    }
    $externalSubscription = ($row['bsky_list_source'] ?? '') === 'subscription';
    if (!empty($row['bsky_list_uri']) && function_exists('ap_bsky_delete_record_uri')) {
        $action = (string) ($row['bsky_moderation_action'] ?? 'none');
        if (($row['list_kind'] ?? '') === 'moderation' && in_array($action, ['mute', 'block'], true)) {
            $unsub = ap_bsky_remove_graph_list_moderation($ownerUserId, (string) $row['bsky_list_uri'], $action, (string) ($row['bsky_mod_action_uri'] ?? ''));
            if (empty($unsub['ok'])) return ['ok' => false, 'error' => 'Could not unsubscribe from the Bluesky moderation list: ' . (string) ($unsub['error'] ?? '')];
        }
        if (!$externalSubscription) {
            foreach (ap_list_accounts($id) as $member) {
                $itemUri = (string) ($member['bsky_item_uri'] ?? '');
                if ($itemUri === '') continue;
                $remoteMember = ap_bsky_delete_graph_list_member($ownerUserId, $itemUri);
                if (empty($remoteMember['ok'])) return ['ok' => false, 'error' => 'Could not remove synchronized Bluesky list members: ' . (string) ($remoteMember['error'] ?? '')];
            }
            $remoteList = ap_bsky_delete_record_uri($ownerUserId, (string) $row['bsky_list_uri']);
            if (empty($remoteList['ok'])) return ['ok' => false, 'error' => 'Could not delete the synchronized Bluesky list: ' . (string) ($remoteList['error'] ?? '')];
        }
    }
    try {
        $db = ap_db();
        $db->beginTransaction();
        $db->prepare('DELETE FROM masto_list_accounts WHERE list_id = ?')->execute([$id]);
        $db->prepare('DELETE FROM masto_lists WHERE id = ? AND owner_user_id = ?')->execute([$id, $ownerUserId]);
        $db->commit();
        ap_lists_moderation_cache_clear($ownerUserId);
        return ['ok' => true];
    } catch (Throwable $e) {
        try {
            ap_db()->rollBack();
        } catch (Throwable $e2) {
        }
        return ['ok' => false, 'error' => 'Could not delete list'];
    }
}

/**
 * Add a followed account to a list. Optionally follow-then-add when $followIfNeeded.
 *
 * @return array{ok:bool,error?:string,followed?:bool,rate_limited?:bool}
 */
function ap_list_add_account(int $listId, string $actorId, bool $followIfNeeded = false): array
{
    $ownerUserId = ap_list_owner_user_id();
    $list = ap_list_by_id($listId, $ownerUserId);
    if (!$list) {
        return ['ok' => false, 'error' => 'Record not found'];
    }
    if (($list['bsky_list_source'] ?? '') === 'subscription') return ['ok' => false, 'error' => 'This Bluesky list belongs to another account; unsubscribe or manage its members on Bluesky.'];
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || !str_starts_with($actorId, 'https://')) {
        return ['ok' => false, 'error' => 'Invalid actor URL'];
    }
    $bskyDid = function_exists('ap_bsky_resolve_target_did') ? ap_bsky_resolve_target_did($actorId, $ownerUserId) : null;
    $isBsky = $bskyDid !== null;
    if ($isBsky && ($list['bsky_list_uri'] ?? '') === '' && ($list['bsky_list_source'] ?? '') !== 'bsky') {
        return ['ok' => false, 'error' => 'This list is not linked to Bluesky; connect Bluesky and create a synchronized list first.'];
    }
    $actorId = $isBsky ? 'https://bsky.app/profile/' . rawurlencode($bskyDid) : $actorId;
    $exists = ap_db()->prepare('SELECT 1 FROM masto_list_accounts WHERE list_id = ? AND (actor_id = ? OR bsky_did = ?)');
    $exists->execute([$listId, $actorId, $isBsky ? $bskyDid : '']);
    if ($exists->fetch()) return ['ok' => true, 'followed' => false];
    $stc = ap_db()->prepare('SELECT COUNT(*) FROM masto_list_accounts WHERE list_id = ?');
    $stc->execute([$listId]);
    if ((int) $stc->fetchColumn() >= AP_LIST_MAX_MEMBERS) {
        return ['ok' => false, 'error' => 'List member limit reached (max ' . AP_LIST_MAX_MEMBERS . ')'];
    }
    $remoteItemUri = '';
    if ($isBsky && ($list['bsky_list_uri'] ?? '') !== '') {
        $remote = ap_bsky_add_graph_list_member($ownerUserId, (string) $list['bsky_list_uri'], $bskyDid);
        if (empty($remote['ok'])) return ['ok' => false, 'error' => 'Could not sync member to Bluesky: ' . (string) ($remote['error'] ?? '')];
        $remoteItemUri = (string) ($remote['uri'] ?? '');
    }
    $followed = false;
    if (!$isBsky && !ap_actor_is_followed($actorId)) {
        if (!$followIfNeeded) {
            return ['ok' => false, 'error' => 'Account must be followed before adding to a list'];
        }
        if (!function_exists('ap_follow_remote_actor')) {
            return ['ok' => false, 'error' => 'Follow helper unavailable'];
        }
        $fres = ap_follow_remote_actor($actorId, true);
        if (empty($fres['ok'])) {
            $err = (string) ($fres['error'] ?? 'Follow failed');
            $rateLimited = str_contains(strtolower($err), 'rate') || !empty($fres['rate_limited']);
            return [
                'ok' => false,
                'error' => $err,
                'rate_limited' => $rateLimited,
            ];
        }
        $followed = true;
    }
    try {
        $now = ap_db_now();
        ap_db()->prepare(
            'INSERT INTO masto_list_accounts (list_id, actor_id, bsky_did, bsky_item_uri, added_at) VALUES (?, ?, ?, ?, ?)'
        )->execute([$listId, $actorId, $isBsky ? $bskyDid : null, $remoteItemUri !== '' ? $remoteItemUri : null, $now]);
        if (!empty($list['bsky_list_uri']) && !empty($list['bsky_moderation_action']) && $list['bsky_moderation_action'] !== 'none') {
            ap_bsky_refresh_hide_set($ownerUserId, true);
        }
        ap_db()->prepare('UPDATE masto_lists SET updated_at = ? WHERE id = ?')->execute([$now, $listId]);
        ap_lists_moderation_cache_clear($ownerUserId);
        return ['ok' => true, 'followed' => $followed];
    } catch (Throwable $e) {
        if ($remoteItemUri !== '' && function_exists('ap_bsky_delete_graph_list_member')) {
            ap_bsky_delete_graph_list_member($ownerUserId, $remoteItemUri);
        }
        error_log('[ap-lists] add_account: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Could not add account'];
    }
}

/**
 * @return array{ok:bool,error?:string}
 */
function ap_list_remove_account(int $listId, string $actorId): array
{
    $ownerUserId = ap_list_owner_user_id();
    $list = ap_list_by_id($listId, $ownerUserId);
    if (!$list) {
        return ['ok' => false, 'error' => 'Record not found'];
    }
    if (($list['bsky_list_source'] ?? '') === 'subscription') return ['ok' => false, 'error' => 'This Bluesky list belongs to another account; its members cannot be edited here.'];
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '') {
        return ['ok' => false, 'error' => 'Invalid actor'];
    }
    try {
        $st = ap_db()->prepare('SELECT bsky_item_uri FROM masto_list_accounts WHERE list_id = ? AND (actor_id = ? OR actor_id = ?) LIMIT 1');
        $st->execute([$listId, $actorId, $actorId . '/']);
        $itemUri = (string) ($st->fetchColumn() ?: '');
        if ($itemUri !== '' && !empty($list['bsky_list_uri'])) {
            $remote = ap_bsky_delete_graph_list_member($ownerUserId, $itemUri);
            if (empty($remote['ok'])) return ['ok' => false, 'error' => 'Could not remove member from Bluesky: ' . (string) ($remote['error'] ?? '')];
        }
        ap_db()->prepare(
            'DELETE FROM masto_list_accounts WHERE list_id = ? AND (actor_id = ? OR actor_id = ?)'
        )->execute([$listId, $actorId, $actorId . '/']);
        ap_db()->prepare('UPDATE masto_lists SET updated_at = ? WHERE id = ?')->execute([ap_db_now(), $listId]);
        ap_lists_moderation_cache_clear($ownerUserId);
        if (($list['list_kind'] ?? '') === 'moderation' && in_array((string) ($list['bsky_moderation_action'] ?? 'none'), ['mute', 'block'], true)
            && !empty($list['bsky_list_uri']) && function_exists('ap_bsky_refresh_hide_set')) {
            ap_bsky_refresh_hide_set($ownerUserId, true);
            if (function_exists('ap_bsky_hide_did_set_clear_cache')) ap_bsky_hide_did_set_clear_cache($ownerUserId);
        }
        return ['ok' => true];
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Could not remove account'];
    }
}

/**
 * Lists that contain a given actor.
 *
 * @return list<array<string,mixed>>
 */
function ap_lists_for_actor(string $actorId, ?int $ownerUserId = null): array
{
    $ownerUserId = ap_list_owner_user_id($ownerUserId);
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '' || $ownerUserId < 1) {
        return [];
    }
    try {
        $st = ap_db()->prepare(
            'SELECT l.* FROM masto_lists l
             INNER JOIN masto_list_accounts a ON a.list_id = l.id
             WHERE l.owner_user_id = ? AND (a.actor_id = ? OR a.actor_id = ?)
             ORDER BY l.title ASC'
        );
        $st->execute([$ownerUserId, $actorId, $actorId . '/']);
        return $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/** Return this viewer's strongest moderation-list action for an actor. */
function ap_lists_moderation_action_for_actor(?string $actorId, ?int $ownerUserId = null): string
{
    $actorId = rtrim(trim((string) $actorId), '/');
    $ownerUserId = ap_list_owner_user_id($ownerUserId);
    if ($actorId === '' || $ownerUserId < 1) return 'none';
    if (!isset($GLOBALS['ap_moderation_list_cache']) || !array_key_exists($ownerUserId, $GLOBALS['ap_moderation_list_cache'])) {
        $GLOBALS['ap_moderation_list_cache'][$ownerUserId] = [];
        try {
            $st = ap_db()->prepare("SELECT a.actor_id, l.bsky_moderation_action FROM masto_lists l INNER JOIN masto_list_accounts a ON a.list_id = l.id WHERE l.owner_user_id = ? AND l.list_kind = 'moderation'");
            $st->execute([$ownerUserId]);
            foreach ($st->fetchAll() ?: [] as $row) {
                $key = rtrim(trim((string) ($row['actor_id'] ?? '')), '/');
                $action = (string) ($row['bsky_moderation_action'] ?? 'none');
                if ($key !== '' && in_array($action, ['mute', 'block'], true)
                    && (!isset($GLOBALS['ap_moderation_list_cache'][$ownerUserId][$key]) || $action === 'block')) $GLOBALS['ap_moderation_list_cache'][$ownerUserId][$key] = $action;
            }
        } catch (Throwable $e) {
            $GLOBALS['ap_moderation_list_cache'][$ownerUserId] = [];
        }
    }
    return (string) ($GLOBALS['ap_moderation_list_cache'][$ownerUserId][$actorId] ?? 'none');
}

function ap_lists_moderation_cache_clear(?int $ownerUserId = null): void
{
    if ($ownerUserId === null) unset($GLOBALS['ap_moderation_list_cache']);
    else unset($GLOBALS['ap_moderation_list_cache'][$ownerUserId]);
}

/**
 * Actor IDs that belong to any exclusive list (for home-timeline filtering later).
 *
 * @return array<string,true>
 */
function ap_list_exclusive_actor_map(?int $ownerUserId = null): array
{
    $ownerUserId = ap_list_owner_user_id($ownerUserId);
    if ($ownerUserId < 1) {
        return [];
    }
    $map = [];
    try {
        $st = ap_db()->prepare(
            'SELECT a.actor_id FROM masto_list_accounts a
             INNER JOIN masto_lists l ON l.id = a.list_id
             WHERE l.owner_user_id = ? AND l.exclusive = 1'
        );
        $st->execute([$ownerUserId]);
        $rows = $st->fetchAll();
        foreach ($rows ?: [] as $row) {
            $aid = rtrim((string) ($row['actor_id'] ?? ''), '/');
            if ($aid !== '') {
                $map[$aid] = true;
            }
        }
    } catch (Throwable $e) {
    }
    return $map;
}

/**
 * Mastodon GET /api/v1/timelines/list/:id — posts from list members.
 * replies_policy: list = include replies to list members; followed = replies to anyone we follow;
 * none = drop reply Creates (Announces still included).
 *
 * @return list<array<string,mixed>>
 */
function ap_masto_timeline_list(int $listId, int $limit = 40, ?string $maxId = null, ?string $sinceId = null): array
{
    $limit = max(1, min(80, $limit));
    $list = ap_list_by_id($listId);
    if (!$list) {
        return [];
    }
    $actors = ap_list_actor_ids($listId);
    if (!$actors) {
        return [];
    }
    $policy = ap_list_normalize_replies_policy((string) ($list['replies_policy'] ?? 'list')) ?? 'list';

    $actorIds = [];
    foreach ($actors as $a) {
        $actorIds[$a] = true;
        $actorIds[$a . '/'] = true;
    }
    $ids = array_keys($actorIds);
    $params = $ids;
    $where = [
        "type IN ('Create', 'Announce', 'Quote', 'QuotePost')",
        "( (summary IS NOT NULL AND summary != '') OR (media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]') OR (spoiler_text IS NOT NULL AND spoiler_text != '') OR (sensitive IS NOT NULL AND sensitive != 0) )",
        "(action_taken = 'log' OR action_taken = 'local_observe')",
        'actor_id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')',
    ];

    if ($maxId !== null && $maxId !== '' && function_exists('ap_masto_event_id_from_status_id')) {
        $eid = ap_masto_event_id_from_status_id((int) $maxId);
        if ($eid !== null) {
            $where[] = 'id < ?';
            $params[] = $eid;
        }
    }
    if ($sinceId !== null && $sinceId !== '' && function_exists('ap_masto_event_id_from_status_id')) {
        $eid = ap_masto_event_id_from_status_id((int) $sinceId);
        if ($eid !== null) {
            $where[] = 'id > ?';
            $params[] = $eid;
        }
    }

    $followedMap = [];
    if ($policy === 'followed' || $policy === 'list') {
        foreach (ap_following_list() as $f) {
            $fa = rtrim((string) ($f['actor_id'] ?? ''), '/');
            if ($fa !== '') {
                $followedMap[$fa] = true;
            }
        }
    }

    $sql = 'SELECT * FROM events WHERE ' . implode(' AND ', $where)
        . ' ORDER BY created_at DESC, id DESC LIMIT ' . (int) max($limit * 3, $limit + 20);
    try {
        $st = ap_db()->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[ap-lists] timeline: ' . $e->getMessage());
        return [];
    }

    $out = [];
    $seenUri = [];
    foreach ($rows as $row) {
        $type = (string) ($row['type'] ?? '');
        $inReplyTo = trim((string) ($row['in_reply_to'] ?? ''));
        if ($type === 'Create' && $inReplyTo !== '') {
            if ($policy === 'none') {
                continue;
            }
            // Best-effort: resolve reply parent actor from events cache
            if ($policy === 'list' || $policy === 'followed') {
                $parentActor = ap_list_parent_actor_for_object($inReplyTo);
                if ($parentActor !== null) {
                    $pa = rtrim($parentActor, '/');
                    if ($policy === 'list' && empty($actorIds[$pa]) && empty($actorIds[$pa . '/'])) {
                        continue;
                    }
                    if ($policy === 'followed' && empty($followedMap[$pa])) {
                        continue;
                    }
                }
                // If parent unknown, keep the reply (prefer showing over dropping).
            }
        }

        if (!function_exists('ap_masto_status_from_event')) {
            continue;
        }
        $status = ap_masto_status_from_event($row);
        if ($status === null) {
            continue;
        }
        $uri = (string) ($status['uri'] ?? $status['url'] ?? $row['object_id'] ?? '');
        $uriKey = rtrim($uri, '/');
        $uriKey = preg_replace('#^http://#i', 'https://', $uriKey) ?? $uriKey;
        if ($uriKey !== '' && isset($seenUri[$uriKey])) {
            continue;
        }
        if ($uriKey !== '') {
            $seenUri[$uriKey] = true;
        }
        $out[] = $status;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

function ap_list_parent_actor_for_object(string $objectId): ?string
{
    $objectId = rtrim(trim($objectId), '/');
    if ($objectId === '') {
        return null;
    }
    try {
        $st = ap_db()->prepare(
            "SELECT actor_id FROM events
             WHERE (object_id = ? OR object_id = ?)
               AND type IN ('Create', 'Update')
             ORDER BY id DESC LIMIT 1"
        );
        $st->execute([$objectId, $objectId . '/']);
        $row = $st->fetch();
        if (is_array($row) && !empty($row['actor_id'])) {
            return rtrim((string) $row['actor_id'], '/');
        }
    } catch (Throwable $e) {
    }
    return null;
}

/**
 * Resolve @user@host or https actor URL for list membership forms.
 */
function ap_list_resolve_actor_ref(string $ref): ?string
{
    $ref = trim($ref);
    if ($ref === '') {
        return null;
    }
    if (str_starts_with($ref, 'https://')) {
        return rtrim($ref, '/');
    }
    if (!function_exists('ap_resolve_actor_ref')) {
        return null;
    }
    $resolved = ap_resolve_actor_ref($ref);
    if (is_string($resolved) && str_starts_with($resolved, 'https://')) {
        return rtrim($resolved, '/');
    }
    if (is_array($resolved)) {
        $id = rtrim((string) ($resolved['id'] ?? $resolved['actor_id'] ?? ''), '/');
        return $id !== '' ? $id : null;
    }
    return null;
}
