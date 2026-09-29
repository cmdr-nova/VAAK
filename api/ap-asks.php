<?php
/** Wafrn-compatible ActivityPub Asks. */
declare(strict_types=1);

require_once __DIR__ . '/ap-db.php';
function ap_asks_migrate(): void
{
    static $done = false; if ($done) return; $done = true;
    try {
        $idDef = ap_db_driver() === 'pgsql' ? 'BIGSERIAL PRIMARY KEY' : 'INTEGER PRIMARY KEY AUTOINCREMENT';
        ap_db()->exec("CREATE TABLE IF NOT EXISTS ap_asks (
            id {$idDef},
            ask_id TEXT NOT NULL UNIQUE,
            question TEXT NOT NULL,
            asker_actor TEXT,
            asked_actor TEXT NOT NULL,
            owner_user_id INTEGER NOT NULL DEFAULT 1,
            answered INTEGER NOT NULL DEFAULT 0,
            answer_note_id TEXT,
            ap_object TEXT,
            created_at TEXT NOT NULL,
            updated_at TEXT NOT NULL
        )");
        // Portable upgrade for databases created before Ask answers were linked
        // to their resulting public post.
        try { ap_db()->exec('ALTER TABLE ap_asks ADD COLUMN answer_note_id TEXT'); } catch (Throwable $e) { /* already exists */ }
        ap_db()->exec('CREATE INDEX IF NOT EXISTS idx_ap_asks_owner ON ap_asks(owner_user_id, answered, created_at DESC)');
        ap_db()->exec("CREATE TABLE IF NOT EXISTS ap_wafrn_friend_servers (
            host TEXT PRIMARY KEY, enabled INTEGER NOT NULL DEFAULT 1,
            note TEXT NOT NULL DEFAULT '', created_at TEXT NOT NULL, updated_at TEXT NOT NULL
        )");
    } catch (Throwable $e) { error_log('[asks] migrate: ' . $e->getMessage()); }
}

function ap_ask_answer_for_note(string $noteId): ?array
{
    $noteId = rtrim(trim($noteId), '/');
    if ($noteId === '') return null;
    try {
        $st = ap_db()->prepare('SELECT * FROM ap_asks WHERE answer_note_id = ? OR answer_note_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$noteId, $noteId . '/']);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) { return null; }
}

/**
 * Resolve the cached identity shown alongside an answered Ask.
 * Never performs a synchronous remote actor fetch; the normal actor warmers
 * can fill remote_actors/media caches for the next render.
 *
 * @return array{display_name:string,handle:string,avatar:string}
 */
function ap_ask_actor_identity(string $actor): array
{
    $actor = rtrim(trim($actor), '/');
    $fallback = defined('AP_REMOTE_AVATAR_FALLBACK')
        ? AP_REMOTE_AVATAR_FALLBACK
        : 'https://mkultra.monster/img/avatar/default.webp';
    if ($actor === '' || !str_starts_with($actor, 'https://')) {
        return ['display_name' => 'Anonymous', 'handle' => '', 'avatar' => $fallback];
    }
    $local = function_exists('ap_local_user_by_actor_id') ? ap_local_user_by_actor_id($actor) : null;
    if (is_array($local)) {
        $key = (string) ($local['actor_key'] ?? '');
        $profile = $key !== '' && function_exists('ap_profile_get') ? ap_profile_get($key) : [];
        $avatar = function_exists('ap_local_avatar_url')
            ? ap_local_avatar_url((string) ($profile['icon_url'] ?? ''))
            : $fallback;
        return [
            'display_name' => trim((string) (($profile['name'] ?? '') ?: ($key !== '' ? $key : 'Local user'))),
            'handle' => $key !== '' ? '@' . $key . '@mkultra.monster' : '',
            'avatar' => $avatar,
        ];
    }
    $label = function_exists('ap_remote_actor_label')
        ? ap_remote_actor_label($actor, false)
        : ['display_name' => '', 'handle' => ''];
    $row = function_exists('ap_remote_actor_get') ? ap_remote_actor_get($actor) : null;
    $avatar = '';
    if (function_exists('ap_peer_avatar_override')) {
        $avatar = (string) (ap_peer_avatar_override($actor) ?? '');
    }
    if ($avatar === '' && is_array($row)) {
        $avatar = (string) ($row['icon_source_url'] ?? '');
    }
    if ($avatar === '' && function_exists('ap_remote_media_get')) {
        $cached = ap_remote_media_get($actor, 'avatar');
        if (is_array($cached)) {
            $avatar = (string) ($cached['public_url'] ?? '');
        }
    }
    if (!str_starts_with($avatar, 'https://')) {
        $avatar = $fallback;
    }
    return [
        'display_name' => trim((string) (($label['display_name'] ?? '') ?: ($label['username'] ?? 'user'))),
        'handle' => (string) ($label['handle'] ?? ''),
        'avatar' => $avatar,
    ];
}

function ap_wafrn_ask_level_from_actor(array $doc): int
{
    $v = (int) ($doc['_wafrn_asks'] ?? 0);
    return in_array($v, [1, 2], true) ? $v : 0;
}

function ap_wafrn_actor_host_known(string $actor): bool
{
    $host = strtolower((string) (parse_url($actor, PHP_URL_HOST) ?: ''));
    if ($host === '') return false;
    foreach (ap_wafrn_friend_inboxes(100) as $inbox) {
        if (strtolower((string) (parse_url($inbox, PHP_URL_HOST) ?: '')) === $host) return true;
    }
    return false;
}

function ap_ask_store_inbound(array $activity, int $ownerUserId, string $ownerActor): bool
{
    ap_asks_migrate();
    $target = ap_as_id($activity['target'] ?? null) ?: ap_as_id($activity['object'] ?? null);
    $actor = ap_as_id($activity['actor'] ?? null);
    $question = trim(strip_tags((string) ($activity['content'] ?? '')));
    $askId = ap_as_id($activity['id'] ?? null) ?: ('https://mkultra.monster/received-asks/' . bin2hex(random_bytes(12)));
    if ($target === null || $actor === null || $question === '' || strlen($question) > 10240) return false;
    $local = ap_local_user_by_actor_id($target);
    if (!is_array($local) || (int) ($local['id'] ?? 0) !== $ownerUserId) return false;
    $key = (string) ($local['actor_key'] ?? '');
    if ($key !== '' && function_exists('ap_profile_get')) {
        $profile = ap_profile_get($key);
        if (array_key_exists('asks_enabled', $profile) && empty($profile['asks_enabled'])) return false;
    }
    if (function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($actor)) return false;
    $now = ap_db_now();
    $st = ap_db()->prepare('INSERT INTO ap_asks (ask_id,question,asker_actor,asked_actor,owner_user_id,ap_object,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(ask_id) DO NOTHING');
    $st->execute([$askId, mb_substr($question, 0, 10240), $actor, $target, $ownerUserId, json_encode($activity, JSON_UNESCAPED_SLASHES), $now, $now]);
    return $st->rowCount() > 0;
}

function ap_ask_send(string $targetActor, string $question, int $ownerUserId): array
{
    ap_asks_migrate();
    $targetActor = rtrim(trim($targetActor), '/');
    $question = trim(strip_tags($question));
    if (!str_starts_with($targetActor, 'https://') || $question === '') return ['ok'=>false,'error'=>'Actor and question are required.'];
    if (strlen($question) > 10240) $question = mb_substr($question, 0, 10240);
    $doc = ap_fetch_as2_object($targetActor);
    if (!is_array($doc) || ap_wafrn_ask_level_from_actor($doc) === 0) return ['ok'=>false,'error'=>'This profile does not advertise Wafrn Asks.'];
    $inbox = (string) (($doc['endpoints']['sharedInbox'] ?? '') ?: ($doc['inbox'] ?? ''));
    if ($inbox === '') return ['ok'=>false,'error'=>'That profile has no reachable inbox.'];
    $owner = ap_db_owner_actor_id_for_user_id($ownerUserId);
    $actor = rtrim($owner, '/');
    $id = 'https://mkultra.monster/fediverse/asks/' . bin2hex(random_bytes(12));
    $activity = ['@context'=>['https://www.w3.org/ns/activitystreams',['AskQuestion'=>'https://wafrn.net/ns#AskQuestion']], 'id'=>$id, 'type'=>'AskQuestion', 'actor'=>$actor, 'object'=>$targetActor, 'target'=>$targetActor, 'content'=>$question, 'to'=>[$targetActor]];
    $ok = ap_deliver_signed_json($inbox, $activity, ap_local_key_id(), ap_local_priv_path(), 8.0);
    if (!$ok) return ['ok'=>false,'error'=>'The Ask could not be delivered.'];
    $now = ap_db_now();
    ap_db()->prepare('INSERT INTO ap_asks (ask_id,question,asker_actor,asked_actor,owner_user_id,ap_object,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(ask_id) DO NOTHING')->execute([$id,$question,$actor,$targetActor,$ownerUserId,json_encode($activity,JSON_UNESCAPED_SLASHES),$now,$now]);
    return ['ok'=>true,'id'=>$id];
}
