<?php
declare(strict_types=1);

/**
 * Opt-in Phyrian clans.
 *
 * VAAK_PHYRIAN_CLANS is off unless set to 1/true/yes/on.
 * The game page checks that switch before this file is loaded. While it is
 * off, clans do no database or upload work. While it is on, clan tables are
 * read only on the Clans tab and written only from a confirmed form.
 *
 * Local players only. Shared feeds, combined stats, and goals are out of scope.
 */

const AP_PHYRIAN_CLANS_NAME_MAX = 40;
const AP_PHYRIAN_CLANS_DESC_MAX = 280;
const AP_PHYRIAN_CLANS_LIST_MAX = 50;

function ap_phyrian_clans_enabled(): bool
{
    $raw = getenv('VAAK_PHYRIAN_CLANS');
    if (!is_string($raw)) {
        return false;
    }
    return in_array(strtolower(trim($raw)), ['1', 'true', 'yes', 'on'], true);
}

function ap_phyrian_clans_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ap_phyrian_clans_plain(string $value, int $max): string
{
    $value = trim(strip_tags(str_replace(["\r\n", "\r"], ' ', $value)));
    $value = preg_replace("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/", '', $value) ?? $value;
    $value = preg_replace('/\s+/', ' ', $value) ?? $value;
    if (mb_strlen($value) > $max) {
        $value = rtrim(mb_substr($value, 0, $max));
    }
    return trim($value);
}

function ap_phyrian_clans_nav_html(string $page): string
{
    if (!ap_phyrian_clans_enabled()) {
        return '';
    }
    $active = $page === 'clans';
    return '<a class="' . ($active ? 'is-active' : '') . '" href="?view=phyrian&amp;page=clans"'
        . ($active ? ' aria-current="page"' : '')
        . '>Clans</a>';
}

function ap_phyrian_clans_db(?PDO $db): PDO
{
    if ($db instanceof PDO) {
        return $db;
    }
    if (!function_exists('ap_db')) {
        throw new RuntimeException('database unavailable');
    }
    return ap_db();
}

/**
 * @param array<string, mixed> $file
 * @param callable(array<string, mixed>):array{ok:bool,local_id?:int,error?:string}|null $storeEmblem
 * @return array{ok:bool,local_id?:int,error?:string,skipped?:bool}
 */
function ap_phyrian_clans_take_emblem(array $file, ?callable $storeEmblem): array
{
    $err = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    $name = trim((string) ($file['name'] ?? ''));
    if ($file === [] || $err === UPLOAD_ERR_NO_FILE || ($name === '' && $err === UPLOAD_ERR_OK)) {
        return ['ok' => true, 'skipped' => true];
    }
    $store = $storeEmblem ?? static function (array $upload): array {
        if (!function_exists('ap_media_ingest_upload')) {
            return ['ok' => false, 'error' => 'Uploads are unavailable.'];
        }
        $saved = ap_media_ingest_upload($upload, 'Phyrian clan emblem');
        return [
            'ok' => !empty($saved['ok']),
            'local_id' => (int) ($saved['local_id'] ?? 0),
            'error' => (string) ($saved['error'] ?? 'Could not store that emblem.'),
        ];
    };
    $saved = $store($file);
    if (empty($saved['ok'])) {
        return ['ok' => false, 'error' => (string) ($saved['error'] ?? 'Could not store that emblem.')];
    }
    $id = (int) ($saved['local_id'] ?? 0);
    if ($id < 1) {
        return ['ok' => false, 'error' => 'Could not store that emblem.'];
    }
    return ['ok' => true, 'local_id' => $id];
}

/**
 * @param array{name?:string,description?:string,clan_id?:int} $input
 * @param array<string, mixed> $file
 * @param callable(array<string, mixed>):array{ok:bool,local_id?:int,error?:string}|null $storeEmblem
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_phyrian_clans_mutate(
    string $action,
    int $ownerUserId,
    array $input,
    array $file,
    string $csrf,
    ?PDO $db = null,
    ?callable $storeEmblem = null
): array {
    if (!ap_phyrian_clans_enabled()) {
        return ['ok' => false, 'error' => 'Clans are turned off.'];
    }
    if (function_exists('ap_auth_csrf_ok') && !ap_auth_csrf_ok($csrf)) {
        return ['ok' => false, 'error' => 'Session expired — refresh the page and try again.'];
    }
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    if (!in_array($action, ['phyrian_clan_create', 'phyrian_clan_join', 'phyrian_clan_leave'], true)) {
        return ['ok' => false, 'error' => 'Unknown clan action.'];
    }
    try {
        $pdo = ap_phyrian_clans_db($db);
        if ($action === 'phyrian_clan_create') {
            return ap_phyrian_clans_create($pdo, $ownerUserId, $input, $file, $storeEmblem);
        }
        $clanId = (int) ($input['clan_id'] ?? 0);
        if ($clanId < 1) {
            return ['ok' => false, 'error' => 'Choose a clan first.'];
        }
        if ($action === 'phyrian_clan_join') {
            return ap_phyrian_clans_join($pdo, $ownerUserId, $clanId);
        }
        return ap_phyrian_clans_leave($pdo, $ownerUserId, $clanId);
    } catch (Throwable $e) {
        error_log('[phyrian-clans] write failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'Clans are not available yet.'];
    }
}

/**
 * @param array{name?:string,description?:string} $input
 * @param array<string, mixed> $file
 * @param callable(array<string, mixed>):array{ok:bool,local_id?:int,error?:string}|null $storeEmblem
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_phyrian_clans_create(PDO $db, int $ownerUserId, array $input, array $file, ?callable $storeEmblem): array
{
    $name = ap_phyrian_clans_plain((string) ($input['name'] ?? ''), AP_PHYRIAN_CLANS_NAME_MAX);
    $description = ap_phyrian_clans_plain((string) ($input['description'] ?? ''), AP_PHYRIAN_CLANS_DESC_MAX);
    if ($name === '') {
        return ['ok' => false, 'error' => 'Give the clan a name.'];
    }
    $existing = $db->prepare('SELECT id FROM phyrian_clans WHERE owner_user_id = ? LIMIT 1');
    $existing->execute([$ownerUserId]);
    if ($existing->fetchColumn()) {
        return ['ok' => false, 'error' => 'You already have a clan.'];
    }
    $emblem = ap_phyrian_clans_take_emblem($file, $storeEmblem);
    if (empty($emblem['ok'])) {
        return ['ok' => false, 'error' => (string) ($emblem['error'] ?? 'Could not store that emblem.')];
    }
    $emblemId = isset($emblem['local_id']) ? (int) $emblem['local_id'] : null;
    $now = gmdate('c');
    $db->beginTransaction();
    try {
        $db->prepare(
            'INSERT INTO phyrian_clans (owner_user_id, name, description, emblem_media_id, created_at)
             VALUES (?, ?, ?, ?, ?)'
        )->execute([$ownerUserId, $name, $description, $emblemId, $now]);
        $clanId = (int) $db->lastInsertId();
        if ($clanId < 1) {
            $again = $db->prepare('SELECT id FROM phyrian_clans WHERE owner_user_id = ? LIMIT 1');
            $again->execute([$ownerUserId]);
            $clanId = (int) $again->fetchColumn();
        }
        if ($clanId < 1) {
            throw new RuntimeException('clan id missing after insert');
        }
        $db->prepare(
            'INSERT INTO phyrian_clan_members (clan_id, owner_user_id, created_at) VALUES (?, ?, ?)'
        )->execute([$clanId, $ownerUserId, $now]);
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        $code = (string) $e->getCode();
        if ($code === '23000' || $code === '23505' || str_contains($e->getMessage(), 'UNIQUE')) {
            return ['ok' => false, 'error' => 'You already have a clan.'];
        }
        throw $e;
    }
    return ['ok' => true, 'notice' => 'Clan created.'];
}

/**
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_phyrian_clans_join(PDO $db, int $ownerUserId, int $clanId): array
{
    $found = $db->prepare('SELECT id FROM phyrian_clans WHERE id = ? LIMIT 1');
    $found->execute([$clanId]);
    if (!$found->fetchColumn()) {
        return ['ok' => false, 'error' => 'That clan is not on this server.'];
    }
    try {
        $db->prepare(
            'INSERT INTO phyrian_clan_members (clan_id, owner_user_id, created_at) VALUES (?, ?, ?)'
        )->execute([$clanId, $ownerUserId, gmdate('c')]);
    } catch (Throwable $e) {
        $code = (string) $e->getCode();
        if ($code === '23000' || $code === '23505' || str_contains($e->getMessage(), 'UNIQUE')) {
            return ['ok' => false, 'error' => 'You are already in that clan.'];
        }
        throw $e;
    }
    return ['ok' => true, 'notice' => 'Joined the clan.'];
}

/**
 * @return array{ok:bool,error?:string,notice?:string}
 */
function ap_phyrian_clans_leave(PDO $db, int $ownerUserId, int $clanId): array
{
    $st = $db->prepare('DELETE FROM phyrian_clan_members WHERE clan_id = ? AND owner_user_id = ?');
    $st->execute([$clanId, $ownerUserId]);
    if ($st->rowCount() < 1) {
        return ['ok' => false, 'error' => 'You are not in that clan.'];
    }
    return ['ok' => true, 'notice' => 'Left the clan.'];
}

/**
 * @return array{ok:bool,clans:list<array<string,mixed>>,error?:string}
 */
function ap_phyrian_clans_load(int $ownerUserId, ?PDO $db = null): array
{
    if (!ap_phyrian_clans_enabled()) {
        return ['ok' => true, 'clans' => []];
    }
    try {
        $pdo = ap_phyrian_clans_db($db);
        $clans = $pdo->prepare(
            'SELECT c.id, c.owner_user_id, c.name, c.description, c.emblem_media_id,
                    COALESCE(NULLIF(u.username, \'\'), NULLIF(u.actor_key, \'\'), \'\') AS owner_name
             FROM phyrian_clans c
             LEFT JOIN ap_users u ON u.id = c.owner_user_id
             ORDER BY c.id DESC
             LIMIT ' . (string) AP_PHYRIAN_CLANS_LIST_MAX
        );
        $clans->execute();
        $rows = $clans->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($rows) || $rows === []) {
            return ['ok' => true, 'clans' => []];
        }
        $ids = [];
        $emblemIds = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            if ($id > 0) {
                $ids[] = $id;
            }
            $emblem = (int) ($row['emblem_media_id'] ?? 0);
            if ($emblem > 0) {
                $emblemIds[$emblem] = $emblem;
            }
        }
        $members = [];
        if ($ids !== []) {
            $marks = implode(',', array_fill(0, count($ids), '?'));
            $memberSt = $pdo->prepare(
                'SELECT m.clan_id, m.owner_user_id,
                        COALESCE(NULLIF(u.username, \'\'), NULLIF(u.actor_key, \'\'), \'\') AS username
                 FROM phyrian_clan_members m
                 LEFT JOIN ap_users u ON u.id = m.owner_user_id
                 WHERE m.clan_id IN (' . $marks . ')
                 ORDER BY m.owner_user_id ASC'
            );
            $memberSt->execute($ids);
            $memberRows = $memberSt->fetchAll(PDO::FETCH_ASSOC);
            if (is_array($memberRows)) {
                foreach ($memberRows as $member) {
                    if (!is_array($member)) {
                        continue;
                    }
                    $clanId = (int) ($member['clan_id'] ?? 0);
                    $members[$clanId][] = $member;
                }
            }
        }
        $emblems = [];
        if ($emblemIds !== []) {
            try {
                $marks = implode(',', array_fill(0, count($emblemIds), '?'));
                $media = $pdo->prepare(
                    'SELECT local_id, public_url FROM masto_media WHERE local_id IN (' . $marks . ')'
                );
                $media->execute(array_values($emblemIds));
                $mediaRows = $media->fetchAll(PDO::FETCH_ASSOC);
                if (is_array($mediaRows)) {
                    foreach ($mediaRows as $mediaRow) {
                        if (!is_array($mediaRow)) {
                            continue;
                        }
                        $url = trim((string) ($mediaRow['public_url'] ?? ''));
                        if (str_starts_with($url, 'https://')) {
                            $emblems[(int) ($mediaRow['local_id'] ?? 0)] = $url;
                        }
                    }
                }
            } catch (Throwable $e) {
                $emblems = [];
            }
        }
        $out = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (int) ($row['id'] ?? 0);
            $founder = (int) ($row['owner_user_id'] ?? 0);
            $people = [];
            foreach ($members[$id] ?? [] as $member) {
                $memberId = (int) ($member['owner_user_id'] ?? 0);
                $label = trim((string) ($member['username'] ?? ''));
                if ($label === '') {
                    $label = 'user' . $memberId;
                }
                $people[] = [
                    'owner_user_id' => $memberId,
                    'username' => $label,
                    'founder' => $memberId === $founder,
                    'self' => $memberId === $ownerUserId,
                ];
            }
            usort($people, static function (array $a, array $b): int {
                if (!empty($a['founder']) !== !empty($b['founder'])) {
                    return !empty($a['founder']) ? -1 : 1;
                }
                return ($a['owner_user_id'] <=> $b['owner_user_id']);
            });
            $emblemId = (int) ($row['emblem_media_id'] ?? 0);
            $out[] = [
                'id' => $id,
                'owner_user_id' => $founder,
                'name' => (string) ($row['name'] ?? ''),
                'description' => (string) ($row['description'] ?? ''),
                'owner_name' => trim((string) ($row['owner_name'] ?? '')),
                'emblem_url' => $emblems[$emblemId] ?? '',
                'members' => $people,
                'joined' => in_array($ownerUserId, array_column($people, 'owner_user_id'), true),
                'owned' => $founder === $ownerUserId,
            ];
        }
        return ['ok' => true, 'clans' => $out];
    } catch (Throwable $e) {
        error_log('[phyrian-clans] read failed: ' . $e->getMessage());
        return ['ok' => false, 'clans' => [], 'error' => 'Clans are not available yet.'];
    }
}

function ap_phyrian_clans_page_html(int $ownerUserId, string $csrf, ?PDO $db = null): string
{
    if (!ap_phyrian_clans_enabled()) {
        return '';
    }
    $loaded = ap_phyrian_clans_load($ownerUserId, $db);
    if (empty($loaded['ok'])) {
        return '<p class="empty">' . ap_phyrian_clans_h((string) ($loaded['error'] ?? 'Clans are not available yet.')) . '</p>';
    }
    $clans = $loaded['clans'];
    $owns = false;
    foreach ($clans as $clan) {
        if (!empty($clan['owned'])) {
            $owns = true;
            break;
        }
    }
    $html = '<section class="phyrian-clans" aria-label="Clans" style="margin:0 0 1rem">';
    $html .= '<h3 class="phyrian-section-title" style="margin-top:0">Clans</h3>';
    $html .= '<p class="meta" style="margin:0 0 .85rem">Local players on this server can start a clan, join one, or leave. Nothing here is shared with other servers.</p>';
    if ($ownerUserId < 1) {
        $html .= '<p class="empty">Sign in to create or join a clan.</p>';
    } elseif ($owns) {
        $html .= '<p class="meta" style="margin:0 0 .85rem">You already have a clan.</p>';
    } else {
        $html .= '<form method="post" action="?view=phyrian&amp;page=clans" enctype="multipart/form-data" style="margin:0 0 1rem;padding:.85rem;border:1px solid var(--border);border-radius:12px">';
        $html .= '<input type="hidden" name="csrf" value="' . ap_phyrian_clans_h($csrf) . '">';
        $html .= '<input type="hidden" name="action" value="phyrian_clan_create">';
        $html .= '<label for="phyrian-clan-name">Clan name</label>';
        $html .= '<input id="phyrian-clan-name" name="clan_name" required maxlength="' . (string) AP_PHYRIAN_CLANS_NAME_MAX . '" style="width:100%;box-sizing:border-box">';
        $html .= '<label for="phyrian-clan-description">Short description</label>';
        $html .= '<textarea id="phyrian-clan-description" name="clan_description" maxlength="' . (string) AP_PHYRIAN_CLANS_DESC_MAX . '" rows="2" style="width:100%;box-sizing:border-box"></textarea>';
        $html .= '<label for="phyrian-clan-emblem">Emblem (optional)</label>';
        $html .= '<input id="phyrian-clan-emblem" type="file" name="emblem" accept="image/*">';
        $html .= '<div class="composer-actions"><span class="meta">Emblems use the same upload rules as other images.</span>';
        $html .= '<button class="btn btn-primary" type="submit">Create clan</button></div></form>';
    }
    if ($clans === []) {
        $html .= '<p class="empty">No clans yet.</p></section>';
        return $html;
    }
    foreach ($clans as $clan) {
        $name = ap_phyrian_clans_h((string) ($clan['name'] ?? ''));
        $html .= '<article style="margin:0 0 .75rem;padding:.85rem;border:1px solid var(--border);border-radius:12px">';
        if ((string) ($clan['emblem_url'] ?? '') !== '') {
            $html .= '<img src="' . ap_phyrian_clans_h((string) $clan['emblem_url']) . '" alt="' . $name . ' emblem" width="64" height="64" style="width:64px;height:64px;object-fit:cover;border-radius:8px;border:1px solid var(--border)" loading="lazy" decoding="async">';
        }
        $html .= '<h4 style="margin:.35rem 0 .25rem">' . $name . '</h4>';
        $description = trim((string) ($clan['description'] ?? ''));
        if ($description !== '') {
            $html .= '<p style="margin:0 0 .45rem">' . ap_phyrian_clans_h($description) . '</p>';
        }
        $founder = trim((string) ($clan['owner_name'] ?? ''));
        if ($founder === '') {
            $founder = 'user' . (int) ($clan['owner_user_id'] ?? 0);
        }
        $html .= '<p class="meta" style="margin:0 0 .45rem">Founded by @' . ap_phyrian_clans_h($founder) . '</p>';
        $labels = [];
        foreach ($clan['members'] as $member) {
            if (!is_array($member)) {
                continue;
            }
            $label = '@' . ap_phyrian_clans_h((string) ($member['username'] ?? ''));
            if (!empty($member['founder'])) {
                $label .= ' (founder)';
            }
            if (!empty($member['self'])) {
                $label .= ' (you)';
            }
            $labels[] = $label;
        }
        $html .= '<p class="meta" style="margin:0 0 .55rem">Members: ' . ($labels === [] ? 'none yet' : implode(', ', $labels)) . '</p>';
        if ($ownerUserId > 0) {
            $html .= '<form method="post" action="?view=phyrian&amp;page=clans" style="margin:0">';
            $html .= '<input type="hidden" name="csrf" value="' . ap_phyrian_clans_h($csrf) . '">';
            $html .= '<input type="hidden" name="clan_id" value="' . (int) ($clan['id'] ?? 0) . '">';
            if (!empty($clan['joined'])) {
                $html .= '<input type="hidden" name="action" value="phyrian_clan_leave">';
                $html .= '<button class="btn btn-ghost" type="submit">Leave</button>';
            } else {
                $html .= '<input type="hidden" name="action" value="phyrian_clan_join">';
                $html .= '<button class="btn btn-primary" type="submit">Join</button>';
            }
            $html .= '</form>';
        }
        $html .= '</article>';
    }
    return $html . '</section>';
}
