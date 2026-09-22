<?php
/**
 * Optional World of Warcraft character links for VAAK HTML profiles.
 * Armory pages and portraits are stored after linking so public profile
 * renders never need to contact Blizzard.
 */
declare(strict_types=1);

function ap_wow_schema_ensure(): void
{
    static $ready = false;
    if ($ready) {
        return;
    }
    try {
        $db = ap_db();
        if (ap_db_driver($db) === 'pgsql') {
            // PostgreSQL tables are provisioned by the central AP bootstrap/migration.
            $ready = true;
            return;
        }
        $db->exec(
            'CREATE TABLE IF NOT EXISTS ap_wow_links (
                owner_user_id INTEGER PRIMARY KEY,
                character_name TEXT NOT NULL,
                realm TEXT NOT NULL,
                armory_url TEXT NOT NULL,
                portrait_url TEXT NOT NULL,
                linked_at TEXT NOT NULL,
                updated_at TEXT NOT NULL
            )'
        );
    } catch (Throwable $e) {
        // Optional profile metadata must never take a public profile down.
        $ready = true;
        return;
    }
    $ready = true;
}

function ap_wow_armory_url(string $raw): ?string
{
    $url = trim($raw);
    if ($url === '' || !preg_match('~^https://worldofwarcraft\.blizzard\.com/([a-z]{2}-[a-z]{2})/([^?]+)$~i', $url, $m)) {
        return null;
    }
    $path = '/' . trim((string) $m[2], '/');
    if (!preg_match('#^/worldsoul/[a-z]{2}/armory/character/([a-z0-9-]+)/([a-z0-9-]+)$#i', $path, $parts)) {
        return null;
    }
    return 'https://worldofwarcraft.blizzard.com/' . strtolower($m[1]) . $path;
}

function ap_wow_portrait_url(string $raw): ?string
{
    $url = trim($raw);
    if ($url === '' || !preg_match('#^https://render\.worldofwarcraft\.com/[a-z0-9/_-]+\.(?:jpg|jpeg|png|webp)(?:\?[^\s]*)?$#i', $url)) {
        return null;
    }
    return $url;
}

/** @return array{realm:string,name:string}|null */
function ap_wow_armory_parts(string $url): ?array
{
    if (!preg_match('~/armory/character/([^/]+)/([^/?#]+)$~i', $url, $m)) {
        return null;
    }
    return [
        'realm' => ucwords(str_replace('-', ' ', strtolower(rawurldecode($m[1])))),
        'name' => ucwords(str_replace('-', ' ', strtolower(rawurldecode($m[2])))),
    ];
}

function ap_wow_discover_portrait(string $armoryUrl): ?string
{
    $ch = curl_init($armoryUrl);
    if ($ch === false) {
        return null;
    }
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_MAXREDIRS => 3,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_USERAGENT => 'VAAK/1.0 WoW profile link',
        CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
    ]);
    $html = curl_exec($ch);
    curl_close($ch);
    if (!is_string($html) || $html === '') {
        return null;
    }
    if (preg_match('#https://render\.worldofwarcraft\.com/[a-z0-9/_-]+-avatar\.(?:jpg|jpeg|png|webp)(?:\?[^"\'<>\s]*)?#i', $html, $m)) {
        return ap_wow_portrait_url(html_entity_decode($m[0], ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    }
    return null;
}

/** @return array<string,mixed>|null */
function ap_wow_link_for_user(int $userId): ?array
{
    if ($userId <= 0) {
        return null;
    }
    try {
        ap_wow_schema_ensure();
        $st = ap_db()->prepare('SELECT * FROM ap_wow_links WHERE owner_user_id = ? LIMIT 1');
        $st->execute([$userId]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** @return array<string,mixed>|null */
function ap_wow_link_for_actor_key(string $actorKey): ?array
{
    $actorKey = strtolower(preg_replace('/[^a-z0-9_]/', '', $actorKey) ?? '');
    if ($actorKey === '') {
        return null;
    }
    try {
        ap_wow_schema_ensure();
        $st = ap_db()->prepare(
            'SELECT w.* FROM ap_wow_links w
             INNER JOIN ap_users u ON u.id = w.owner_user_id
             WHERE u.actor_key = ? AND u.disabled_at IS NULL LIMIT 1'
        );
        $st->execute([$actorKey]);
        $row = $st->fetch();
        return is_array($row) ? $row : null;
    } catch (Throwable $e) {
        return null;
    }
}

/** @return array{ok:bool,error?:string,notice?:string} */
function ap_wow_link_save(int $userId, string $armoryRaw, string $portraitRaw = ''): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    $armory = ap_wow_armory_url($armoryRaw);
    if ($armory === null) {
        return ['ok' => false, 'error' => 'Enter an official World of Warcraft Armory character URL.'];
    }
    $parts = ap_wow_armory_parts($armory);
    if ($parts === null) {
        return ['ok' => false, 'error' => 'That Armory URL does not contain a character and realm.'];
    }
    $portrait = ap_wow_portrait_url($portraitRaw) ?? ap_wow_discover_portrait($armory);
    if ($portrait === null) {
        return ['ok' => false, 'error' => 'Could not find the character portrait. Try again or provide its Blizzard portrait URL.'];
    }
    ap_wow_schema_ensure();
    $now = gmdate('c');
    ap_db()->prepare(
        'INSERT INTO ap_wow_links (owner_user_id, character_name, realm, armory_url, portrait_url, linked_at, updated_at)
         VALUES (?, ?, ?, ?, ?, ?, ?)
         ON CONFLICT(owner_user_id) DO UPDATE SET character_name=excluded.character_name, realm=excluded.realm,
         armory_url=excluded.armory_url, portrait_url=excluded.portrait_url, updated_at=excluded.updated_at'
    )->execute([$userId, $parts['name'], $parts['realm'], $armory, $portrait, $now, $now]);
    return ['ok' => true, 'notice' => $parts['name'] . ' linked from World of Warcraft.'];
}

/** @return array{ok:bool,error?:string,notice?:string} */
function ap_wow_unlink(int $userId): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    ap_wow_schema_ensure();
    ap_db()->prepare('DELETE FROM ap_wow_links WHERE owner_user_id = ?')->execute([$userId]);
    return ['ok' => true, 'notice' => 'World of Warcraft character unlinked.'];
}
