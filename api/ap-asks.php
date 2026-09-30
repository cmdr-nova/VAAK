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

function ap_ask_by_id(string $askId): ?array
{
    $askId = rtrim(trim($askId), '/');
    if ($askId === '') return null;
    try {
        $st = ap_db()->prepare('SELECT * FROM ap_asks WHERE ask_id = ? OR ask_id = ? ORDER BY id DESC LIMIT 1');
        $st->execute([$askId, $askId . '/']);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) { return null; }
}

/** Match a remote Wafrn answer to one of our outbound Asks. */
function ap_ask_record_remote_answer(string $remoteActor, string $question, string $answerId, int $ownerUserId): ?array
{
    $remoteActor = rtrim(trim($remoteActor), '/');
    $question = trim($question);
    $answerId = rtrim(trim($answerId), '/');
    if ($remoteActor === '' || $question === '' || $answerId === '' || $ownerUserId <= 0) return null;
    try {
        $st = ap_db()->prepare(
            'SELECT * FROM ap_asks WHERE owner_user_id = ? AND asker_actor = ? AND asked_actor = ? AND question = ? ORDER BY id DESC LIMIT 1'
        );
        $st->execute([$ownerUserId, rtrim(ap_db_owner_actor_id_for_user_id($ownerUserId), '/'), $remoteActor, $question]);
        $row = $st->fetch();
        if (!is_array($row)) return null;
        $now = ap_db_now();
        ap_db()->prepare('UPDATE ap_asks SET answered = 1, answer_note_id = ?, updated_at = ? WHERE id = ?')
            ->execute([$answerId, $now, (int) $row['id']]);
        $row['answered'] = 1;
        $row['answer_note_id'] = $answerId;
        return $row;
    } catch (Throwable $e) {
        return null;
    }
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

/** Build the exact Wafrn-compatible Ask context fragment for an answer Note. */
function ap_ask_representation_html(string $answerNoteId, string $question, string $askerActor): string
{
    $identity = ap_ask_actor_identity($askerActor);
    $display = trim((string) ($identity['display_name'] ?? ''));
    $handle = trim((string) ($identity['handle'] ?? ''));
    if ($display === '') $display = $handle !== '' ? $handle : 'Someone';
    $label = htmlspecialchars($display, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $handleHtml = ($handle !== '' && strcasecmp($display, $handle) !== 0)
        ? ' <span class="h-card">' . htmlspecialchars($handle, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</span>'
        : '';
    return '<p><a href="' . htmlspecialchars($askerActor, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">' . $label . '</a>' . $handleHtml
        . ' <a href="' . htmlspecialchars($answerNoteId, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '">asked</a> </p> <blockquote>'
        . htmlspecialchars($question, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</blockquote> ';
}

/**
 * Render Wafrn's compact remote Ask representation for Mastodon-compatible
 * clients. Wafrn intentionally sends this as ordinary Note text, so it needs
 * a presentation hint at the VAAK status boundary rather than in storage.
 */
function ap_wafrn_remote_ask_html(string $text): ?string
{
    $raw = trim($text);
    $answer = '';
    $asker = '';
    $question = '';
    // Wafrn answers carry the question in a blockquote and the answer after
    // it. Preserve those two parts instead of flattening them into one line.
    if (preg_match('~^\s*<p>(.*?)\s+asked\s*</p>\s*<blockquote[^>]*>(.*?)</blockquote>\s*(.*)$~isu', $raw, $m)) {
        $asker = trim(html_entity_decode(strip_tags($m[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $question = trim(html_entity_decode(strip_tags($m[2]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $answer = trim(html_entity_decode(strip_tags($m[3]), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    } else {
        $plain = trim(html_entity_decode(strip_tags($raw), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        if ($plain === '' || !preg_match('/^(.{1,240}?)\s+asked\s+(.{1,20000})$/isu', $plain, $m)) return null;
        $asker = trim($m[1]);
        $question = trim($m[2]);
    }
    if ($asker === '' || $question === '' || !str_contains($asker, '@')) return null;
    $html = '<div class="ask-container" style="margin:.35rem 0 .65rem;padding:.7rem .8rem;border:1px solid rgba(220,113,190,.55);border-radius:8px;background:rgba(164,90,145,.11)">'
        . '<div class="ask-label" style="font-weight:700;margin-bottom:.4rem;color:#e5a8d2">'
        . htmlspecialchars($asker, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . ' asked</div>'
        . '<blockquote class="ask-text" style="margin:0;padding:.2rem 0 .2rem .8rem;border-left:3px solid rgba(220,113,190,.7);white-space:pre-wrap">'
        . nl2br(htmlspecialchars($question, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</blockquote>';
    if ($answer !== '') {
        // Keep a text fallback because some Mastodon-compatible clients strip
        // HR elements and inline styles from status HTML.
        $html .= '<hr class="ask-divider" style="margin:.7rem 0;border:0;border-top:1px solid rgba(220,113,190,.45)"><p class="ask-divider-fallback" aria-hidden="true">────────────</p>'
            . '<div class="ask-answer" style="white-space:pre-wrap">'
            . nl2br(htmlspecialchars($answer, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')) . '</div>';
    }
    return $html . '</div>';
}

/** Format an answered Ask for the connected Bluesky mirror. */
function ap_ask_bluesky_mirror_text(string $answer, array $ask): string
{
    $identity = ap_ask_actor_identity((string) ($ask['asker_actor'] ?? ''));
    $label = trim((string) (($identity['handle'] ?? '') ?: ($identity['display_name'] ?? 'User')));
    if ($label === '') {
        $label = 'User';
    }
    $question = trim((string) ($ask['question'] ?? ''));
    $answer = trim($answer);
    if ($question === '') {
        return $answer;
    }
    return $label . ' asked: ' . $question . "\n\n" . $answer;
}

function ap_wafrn_ask_level_from_actor(array $doc): int
{
    $v = (int) ($doc['_wafrn_asks'] ?? 0);
    return in_array($v, [1, 2], true) ? $v : 0;
}

/**
 * Fetch a Wafrn actor, tolerating the common /users/name form used by
 * Mastodon-style search results. Wafrn's canonical actor path is
 * /fediverse/blog/name, and that is where its _wafrn_asks marker lives.
 *
 * @return array{actor:string,document:array<string,mixed>}|null
 */
function ap_wafrn_ask_actor_document(string $targetActor): ?array
{
    $targetActor = rtrim(trim($targetActor), '/');
    if ($targetActor === '' || !str_starts_with($targetActor, 'https://')) return null;
    $candidates = [$targetActor];
    $parts = parse_url($targetActor);
    $host = strtolower((string) ($parts['host'] ?? ''));
    $path = trim((string) ($parts['path'] ?? ''), '/');
    if ($host !== '' && preg_match('~^(?:users|@)/([^/]+)$~i', $path, $m)) {
        $candidates[] = 'https://' . $host . '/fediverse/blog/' . rawurlencode($m[1]);
    }
    foreach (array_values(array_unique($candidates)) as $candidate) {
        $doc = ap_fetch_as2_object($candidate);
        if (!is_array($doc) || ap_wafrn_ask_level_from_actor($doc) === 0) continue;
        $canonical = rtrim((string) ($doc['id'] ?? $candidate), '/');
        return ['actor' => $canonical !== '' ? $canonical : $candidate, 'document' => $doc];
    }
    return null;
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

/**
 * Add Wafrn's JSON-LD RsaSignature2017 envelope to an AskQuestion.  Wafrn
 * verifies this embedded signature in addition to the normal HTTP signature.
 * The small helper uses the same URDNA2015 normalization as Wafrn and keeps
 * the optional Python dependency out of the PHP request process.
 */
function ap_wafrn_sign_ask_activity(array $activity, string $creator, string $privPath, string $targetActor): ?array
{
    $script = __DIR__ . '/ap-ask-jsonld-sign.py';
    if (!is_file($script) || !is_readable($privPath)) return null;
    $host = strtolower((string) (parse_url($targetActor, PHP_URL_HOST) ?: ''));
    if ($host === '') return null;
    $context = 'https://' . $host . '/contexts/identity-v1.jsonld';
    $cmd = 'python3 ' . escapeshellarg($script)
        . ' --key ' . escapeshellarg($privPath)
        . ' --creator ' . escapeshellarg($creator)
        . ' --context ' . escapeshellarg($context);
    $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $proc = @proc_open($cmd, $descriptors, $pipes);
    if (!is_resource($proc)) return null;
    fwrite($pipes[0], json_encode($activity, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
    fclose($pipes[0]);
    $out = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $err = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $status = proc_close($proc);
    if ($status !== 0 || trim($out) === '') {
        error_log('[asks] JSON-LD signing failed: ' . trim($err));
        return null;
    }
    $signed = json_decode($out, true);
    return is_array($signed) && is_array($signed['signature'] ?? null) ? $signed : null;
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
    $actorDoc = ap_wafrn_ask_actor_document($targetActor);
    if (!is_array($actorDoc)) return ['ok'=>false,'error'=>'This profile does not advertise Wafrn Asks.'];
    $targetActor = (string) ($actorDoc['actor'] ?? $targetActor);
    $doc = is_array($actorDoc['document'] ?? null) ? $actorDoc['document'] : [];
    $inbox = (string) (($doc['endpoints']['sharedInbox'] ?? '') ?: ($doc['inbox'] ?? ''));
    if ($inbox === '') return ['ok'=>false,'error'=>'That profile has no reachable inbox.'];
    $owner = ap_db_owner_actor_id_for_user_id($ownerUserId);
    $actor = rtrim($owner, '/');
    $id = 'https://mkultra.monster/fediverse/asks/' . bin2hex(random_bytes(12));
    // VAAK never sends anonymous Asks: the authenticated local actor is always
    // included, regardless of the recipient's Wafrn level marker.
    $activity = ['@context'=>['https://www.w3.org/ns/activitystreams',['AskQuestion'=>'https://wafrn.net/ns#AskQuestion']], 'id'=>$id, 'type'=>'AskQuestion', 'actor'=>$actor, 'object'=>$targetActor, 'target'=>$targetActor, 'content'=>$question, 'to'=>[$targetActor]];
    $activity = ap_wafrn_sign_ask_activity($activity, ap_local_key_id(), ap_local_priv_path(), $targetActor);
    if (!is_array($activity)) return ['ok'=>false,'error'=>'The Wafrn-compatible JSON-LD signer is unavailable.'];
    $ok = ap_deliver_signed_json($inbox, $activity, ap_local_key_id(), ap_local_priv_path(), 8.0);
    if (!$ok) return ['ok'=>false,'error'=>'The Ask could not be delivered.'];
    $now = ap_db_now();
    ap_db()->prepare('INSERT INTO ap_asks (ask_id,question,asker_actor,asked_actor,owner_user_id,ap_object,created_at,updated_at) VALUES (?,?,?,?,?,?,?,?) ON CONFLICT(ask_id) DO NOTHING')->execute([$id,$question,$actor,$targetActor,$ownerUserId,json_encode($activity,JSON_UNESCAPED_SLASHES),$now,$now]);
    return ['ok'=>true,'id'=>$id];
}
