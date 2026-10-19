<?php
declare(strict_types=1);

/**
 * Phyrian family tree and weekly recap.
 *
 * Read-only view of who imprinted whom, built from
 * phyrian_players.imprinted_by_owner_id plus the grid lineage that
 * phyrian-sync.php mirrors into phyrian_grid_lineage. Grid-linked players
 * keep their grid-synced stats; avatars that only exist on the grid show
 * their grid name and no profile link until they link a VAAK account.
 *
 * Nodes are keyed "u<owner id>" for VAAK players and "g<avatar uuid>" for
 * grid-only avatars.
 *
 * The recap is a draft. Nothing is posted until the viewer confirms the
 * form, and it posts from the viewer's own account. The suggested text uses
 * counts only so it never names other players in a public post.
 */

const AP_PHYRIAN_TREE_MAX_ROWS = 2000;
const AP_PHYRIAN_TREE_MAX_DEPTH = 64;
/** Tree levels open by default; the viewer's own branch is always open. */
const AP_PHYRIAN_TREE_OPEN_DEPTH = 2;
const AP_PHYRIAN_TREE_RECAP_DAYS = 7;
const AP_PHYRIAN_TREE_RECAP_TEXT_MAX = 500;

function ap_phyrian_tree_h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ap_phyrian_tree_nav_html(string $page): string
{
    $active = $page === 'tree';
    return '<a class="' . ($active ? 'is-active' : '') . '" href="?view=phyrian&amp;page=tree"'
        . ($active ? ' aria-current="page"' : '')
        . '>Family tree</a>';
}

function ap_phyrian_tree_db(?PDO $db): PDO
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
 * Imprinted players, oldest imprint first. One query.
 *
 * @return array{ok:bool,error?:string,rows?:list<array{owner_user_id:int,username:string,strain:string,generation:int,parent:int,imprinted_at:string,linked:bool}>}
 */
function ap_phyrian_tree_load(?PDO $db = null): array
{
    try {
        $pdo = ap_phyrian_tree_db($db);
        $st = $pdo->prepare(
            "SELECT p.owner_user_id, p.strain, p.generation, p.imprinted_by_owner_id, p.imprinted_at,
                    COALESCE(NULLIF(u.username, ''), NULLIF(u.actor_key, ''), '') AS username,
                    l.avatar_uuid
             FROM phyrian_players p
             LEFT JOIN ap_users u ON u.id = p.owner_user_id
             LEFT JOIN phyrian_bridge_links l
               ON l.owner_user_id = p.owner_user_id AND l.status = 'verified' AND l.unlinked_at IS NULL
             WHERE p.status = 'imprinted' AND COALESCE(p.strain, '') <> ''
             ORDER BY p.imprinted_at IS NULL DESC, p.imprinted_at, p.owner_user_id
             LIMIT " . (string) AP_PHYRIAN_TREE_MAX_ROWS
        );
        $st->execute();
        $raw = $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[phyrian-tree] load failed: ' . $e->getMessage());
        return ['ok' => false, 'error' => 'The family tree is not available right now.'];
    }
    $rows = [];
    foreach ($raw as $row) {
        $id = (int) ($row['owner_user_id'] ?? 0);
        if ($id < 1) {
            continue;
        }
        $parent = (int) ($row['imprinted_by_owner_id'] ?? 0);
        $uuid = strtolower(trim((string) ($row['avatar_uuid'] ?? '')));
        $rows[] = [
            'key' => 'u' . $id,
            'owner_user_id' => $id,
            'username' => trim((string) ($row['username'] ?? '')),
            'strain' => trim((string) ($row['strain'] ?? '')),
            'generation' => (int) ($row['generation'] ?? 1),
            'parent_key' => $parent > 0 && $parent !== $id ? 'u' . $parent : '',
            'imprinted_at' => (string) ($row['imprinted_at'] ?? ''),
            'linked' => $uuid !== '',
            'avatar_uuid' => $uuid,
            'grid_only' => false,
        ];
    }
    // Grid rows first: the join also fills parents on $rows in place.
    $gridOnly = ap_phyrian_tree_grid_rows($rows, ap_phyrian_tree_load_grid($db));
    return ['ok' => true, 'rows' => array_merge($rows, $gridOnly)];
}

/**
 * Mirrored grid lineage. Empty when the table is missing or not synced yet.
 *
 * @return list<array<string,mixed>>
 */
function ap_phyrian_tree_load_grid(?PDO $db = null): array
{
    try {
        $st = ap_phyrian_tree_db($db)->prepare(
            'SELECT avatar_uuid, avatar_name, parent_uuid, strain, generation, imprinted_at
             FROM phyrian_grid_lineage LIMIT ' . (string) AP_PHYRIAN_TREE_MAX_ROWS
        );
        $st->execute();
        return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * Join grid lineage onto VAAK rows (by reference) and return the grid-only
 * avatars as extra rows. A linked player keeps a VAAK imprinted_by when it
 * has one; otherwise its grid parent is used. Grid parents that are linked
 * resolve to their VAAK node.
 *
 * @param list<array<string,mixed>> $rows
 * @param list<array<string,mixed>> $grid
 * @return list<array<string,mixed>>
 */
function ap_phyrian_tree_grid_rows(array &$rows, array $grid): array
{
    if ($grid === []) {
        return [];
    }
    $byUuid = [];
    foreach ($rows as $i => $row) {
        if ($row['avatar_uuid'] !== '') {
            $byUuid[$row['avatar_uuid']] = $i;
        }
    }
    $keyFor = static function (string $uuid) use (&$byUuid, &$rows): string {
        return isset($byUuid[$uuid]) ? (string) $rows[$byUuid[$uuid]]['key'] : 'g' . $uuid;
    };
    $extra = [];
    foreach ($grid as $g) {
        $uuid = strtolower(trim((string) ($g['avatar_uuid'] ?? '')));
        if ($uuid === '') {
            continue;
        }
        $parentUuid = strtolower(trim((string) ($g['parent_uuid'] ?? '')));
        $parentKey = $parentUuid !== '' && $parentUuid !== $uuid ? $keyFor($parentUuid) : '';
        if (isset($byUuid[$uuid])) {
            $i = $byUuid[$uuid];
            if ($rows[$i]['parent_key'] === '' && $parentKey !== $rows[$i]['key']) {
                $rows[$i]['parent_key'] = $parentKey;
            }
            continue;
        }
        if (trim((string) ($g['strain'] ?? '')) === '') {
            continue;
        }
        $extra[] = [
            'key' => 'g' . $uuid,
            'owner_user_id' => 0,
            'username' => trim((string) ($g['avatar_name'] ?? '')),
            'strain' => trim((string) ($g['strain'] ?? '')),
            'generation' => max(1, (int) ($g['generation'] ?? 1)),
            'parent_key' => $parentKey,
            'imprinted_at' => (string) ($g['imprinted_at'] ?? ''),
            'linked' => false,
            'avatar_uuid' => $uuid,
            'grid_only' => true,
        ];
    }
    return $extra;
}

/**
 * Parent/child links. A player whose parent is missing, unimprinted, itself,
 * or part of a loop becomes a root, so every row appears exactly once.
 *
 * @param list<array<string,mixed>> $rows
 * @return array{nodes:array<string,array<string,mixed>>,children:array<string,list<string>>,roots:list<string>,root_of:array<string,string>}
 */
function ap_phyrian_tree_build(array $rows): array
{
    $nodes = [];
    foreach ($rows as $row) {
        $nodes[(string) $row['key']] = $row;
    }
    $children = [];
    $roots = [];
    foreach ($nodes as $id => $row) {
        $id = (string) $id;
        $parent = (string) ($row['parent_key'] ?? '');
        if ($parent !== '' && $parent !== $id && isset($nodes[$parent])) {
            $children[$parent][] = $id;
        } else {
            $roots[] = $id;
        }
    }
    $rootOf = [];
    $walk = static function (string $root) use (&$children, &$rootOf): void {
        $stack = [$root];
        while ($stack !== []) {
            $id = array_pop($stack);
            if (isset($rootOf[$id])) {
                continue;
            }
            $rootOf[$id] = $root;
            foreach ($children[$id] ?? [] as $child) {
                $stack[] = $child;
            }
        }
    };
    foreach ($roots as $root) {
        $walk($root);
    }
    // Rows left over sit on a parent loop. Break each loop at its first row.
    foreach (array_keys($nodes) as $id) {
        $id = (string) $id;
        if (isset($rootOf[$id])) {
            continue;
        }
        $parent = (string) $nodes[$id]['parent_key'];
        $children[$parent] = array_values(array_filter(
            $children[$parent] ?? [],
            static fn (string $child): bool => $child !== $id
        ));
        $roots[] = $id;
        $walk($id);
    }
    return ['nodes' => $nodes, 'children' => $children, 'roots' => $roots, 'root_of' => $rootOf];
}

function ap_phyrian_tree_ts(string $value): ?int
{
    if (trim($value) === '') {
        return null;
    }
    $ts = strtotime($value);
    return $ts === false ? null : $ts;
}

/**
 * Last seven days of spread, from the rows already loaded plus two counts.
 *
 * @param list<array<string,mixed>> $rows
 * @param array{root_of:array<string,string>} $tree
 * @return array{new_imprints:int,new_ids:array<string,bool>,exchanges:int,new_clans:int,top_root:string,top_count:int}
 */
function ap_phyrian_tree_recap(array $rows, array $tree, ?PDO $db = null, ?int $now = null): array
{
    $now ??= time();
    $since = $now - AP_PHYRIAN_TREE_RECAP_DAYS * 86400;
    $newIds = [];
    $perRoot = [];
    foreach ($rows as $row) {
        $ts = ap_phyrian_tree_ts((string) ($row['imprinted_at'] ?? ''));
        if ($ts === null || $ts < $since || $ts > $now) {
            continue;
        }
        $id = (string) $row['key'];
        $newIds[$id] = true;
        $root = (string) ($tree['root_of'][$id] ?? $id);
        // A brand-new root is not growth of an existing line.
        if ($root !== $id) {
            $perRoot[$root] = ($perRoot[$root] ?? 0) + 1;
        }
    }
    $topRoot = '';
    $topCount = 0;
    foreach ($perRoot as $root => $count) {
        if ($count > $topCount) {
            $topRoot = (string) $root;
            $topCount = $count;
        }
    }
    $sinceSql = gmdate('Y-m-d H:i:s', $since) . '+00';
    $exchanges = 0;
    $newClans = 0;
    try {
        $pdo = ap_phyrian_tree_db($db);
        $st = $pdo->prepare(
            "SELECT COUNT(*) FROM phyrian_requests
             WHERE kind = 'resonance' AND status = 'accepted' AND resolved_at >= ?"
        );
        $st->execute([$sinceSql]);
        $exchanges = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        $exchanges = 0;
    }
    try {
        $pdo = ap_phyrian_tree_db($db);
        $st = $pdo->prepare('SELECT COUNT(*) FROM phyrian_clans WHERE created_at >= ?');
        $st->execute([$sinceSql]);
        $newClans = (int) $st->fetchColumn();
    } catch (Throwable $e) {
        // Clans table only exists once clans are migrated.
        $newClans = 0;
    }
    return [
        'new_imprints' => count($newIds),
        'new_ids' => $newIds,
        'exchanges' => $exchanges,
        'new_clans' => $newClans,
        'top_root' => $topRoot,
        'top_count' => $topCount,
    ];
}

/**
 * Suggested post text. Counts only, no player names.
 *
 * @param array{new_imprints:int,exchanges:int,new_clans:int,top_count:int} $recap
 */
function ap_phyrian_tree_recap_text(array $recap, int $players): string
{
    $parts = [];
    $plural = static fn (int $n, string $one, string $many): string => $n . ' ' . ($n === 1 ? $one : $many);
    $parts[] = $plural((int) $recap['new_imprints'], 'new imprint', 'new imprints');
    $parts[] = $plural((int) $recap['exchanges'], 'resonance exchange', 'resonance exchanges');
    if ((int) $recap['new_clans'] > 0) {
        $parts[] = $plural((int) $recap['new_clans'], 'new clan', 'new clans');
    }
    $text = 'This week in Phyrian Strains on VAAK: ' . implode(', ', $parts) . '. '
        . $plural($players, 'carrier', 'carriers') . ' in the family tree so far.';
    if ((int) $recap['top_count'] > 1) {
        $text .= ' One line grew by ' . (int) $recap['top_count'] . ' this week.';
    }
    return $text . ' #PhyrianStrains';
}

/**
 * Confirmed recap post from the viewer's own account.
 *
 * @param callable(string):array{ok:bool,error?:string}|null $deliver
 * @return array{ok:bool,error?:string,text?:string}
 */
function ap_phyrian_tree_recap_publish(int $ownerUserId, string $text, bool $confirmed, string $csrf = '', ?callable $deliver = null): array
{
    if (function_exists('ap_auth_csrf_ok') && !ap_auth_csrf_ok($csrf)) {
        return ['ok' => false, 'error' => 'Session expired — refresh the page and try again.'];
    }
    if (!$confirmed) {
        return ['ok' => false, 'error' => 'Confirm the post before sharing it.'];
    }
    if ($ownerUserId < 1) {
        return ['ok' => false, 'error' => 'Not signed in.'];
    }
    $text = trim(str_replace(["\r\n", "\r"], "\n", strip_tags($text)));
    $text = preg_replace("/[\\x00-\\x08\\x0B\\x0C\\x0E-\\x1F]/", '', $text) ?? $text;
    if ($text === '') {
        return ['ok' => false, 'error' => 'Write a short note before posting.'];
    }
    if (mb_strlen($text) > AP_PHYRIAN_TREE_RECAP_TEXT_MAX) {
        $text = rtrim(mb_substr($text, 0, AP_PHYRIAN_TREE_RECAP_TEXT_MAX));
    }
    $send = $deliver ?? static function (string $body): array {
        if (!function_exists('ap_local_post_reply')) {
            return ['ok' => false, 'error' => 'Posting is unavailable.'];
        }
        try {
            return ap_local_post_reply($body, '', null, '', false, null, [], 'public');
        } catch (Throwable $e) {
            error_log('[phyrian-tree] recap post failed: ' . $e->getMessage());
            return ['ok' => false, 'error' => 'Could not post the recap.'];
        }
    };
    $sent = $send($text);
    if (empty($sent['ok'])) {
        return ['ok' => false, 'error' => (string) ($sent['error'] ?? 'Could not post the recap.')];
    }
    return ['ok' => true, 'text' => $text];
}

/** "@handle" for VAAK players, the plain grid name for grid-only avatars. */
function ap_phyrian_tree_name(array $node): string
{
    $name = trim((string) ($node['username'] ?? ''));
    if (!empty($node['grid_only'])) {
        return $name !== '' ? $name : 'Grid resident';
    }
    return '@' . ($name !== '' ? $name : 'user' . (int) ($node['owner_user_id'] ?? 0));
}

/**
 * @param array{nodes:array<string,array<string,mixed>>,children:array<string,list<string>>} $tree
 * @param array<string,bool> $path viewer and their ancestors
 * @param array<string,bool> $newIds
 */
function ap_phyrian_tree_node_html(array $tree, string $id, int $depth, string $viewerKey, array $path, array $newIds, string $originKey): string
{
    $node = $tree['nodes'][$id];
    $kids = $tree['children'][$id] ?? [];
    $isSelf = $id === $viewerKey;
    $name = ap_phyrian_tree_h(ap_phyrian_tree_name($node));
    if (!empty($node['grid_only'])) {
        // No VAAK profile yet, so nothing to link to.
        $label = '<span class="phyrian-tree-grid-name">' . $name . '</span>';
    } else {
        $href = $isSelf ? '?view=phyrian&amp;page=dossier' : '?view=phyrian&amp;page=dossier&amp;owner=' . (int) $node['owner_user_id'];
        $label = '<a href="' . $href . '">' . $name . '</a>';
    }
    $label .= ''
        . ' <span class="meta">' . ap_phyrian_tree_h((string) $node['strain']) . ' · Gen ' . (int) $node['generation'] . '</span>';
    if ($isSelf) {
        $label .= ' <span class="phyrian-badge">You</span>';
    }
    if ($id === $originKey) {
        $label .= ' <span class="phyrian-badge phyrian-badge--origin">Origin</span>';
    }
    if (!empty($node['linked'])) {
        $label .= ' <span class="phyrian-badge phyrian-badge--grid" title="Stats synced from the NovaLandia grid">Grid-linked</span>';
    } elseif (!empty($node['grid_only'])) {
        $label .= ' <span class="phyrian-badge phyrian-badge--grid" title="On the NovaLandia grid, not on VAAK yet">Grid only</span>';
    }
    if (isset($newIds[$id])) {
        $label .= ' <span class="phyrian-badge phyrian-badge--new">New this week</span>';
    }
    if ($depth === 0 && $id !== $originKey) {
        if ((string) ($node['parent_key'] ?? '') !== '') {
            $label .= ' <span class="meta">· imprinted by someone not on the tree</span>';
        } elseif (!empty($node['linked'])) {
            $label .= ' <span class="meta">· imprinted on the grid</span>';
        }
    }
    $class = isset($path[$id]) ? ' class="is-path"' : '';
    if ($kids === [] || $depth >= AP_PHYRIAN_TREE_MAX_DEPTH) {
        return '<li' . $class . '><span class="phyrian-tree-node">' . $label . '</span></li>';
    }
    $open = $depth < AP_PHYRIAN_TREE_OPEN_DEPTH || isset($path[$id]);
    $html = '<li' . $class . '><details' . ($open ? ' open' : '') . '><summary class="phyrian-tree-node">' . $label
        . ' <span class="meta">(' . count($kids) . ')</span></summary><ul>';
    foreach ($kids as $kid) {
        $html .= ap_phyrian_tree_node_html($tree, $kid, $depth + 1, $viewerKey, $path, $newIds, $originKey);
    }
    return $html . '</ul></details></li>';
}

function ap_phyrian_tree_page_html(int $viewerId, string $csrf, ?PDO $db = null, ?int $now = null): string
{
    $loaded = ap_phyrian_tree_load($db);
    if (empty($loaded['ok'])) {
        return '<p class="empty">' . ap_phyrian_tree_h((string) ($loaded['error'] ?? 'The family tree is not available right now.')) . '</p>';
    }
    $rows = $loaded['rows'];
    $tree = ap_phyrian_tree_build($rows);
    $recap = ap_phyrian_tree_recap($rows, $tree, $db, $now);
    $originKey = '';
    foreach ($tree['nodes'] as $id => $node) {
        $owner = (int) ($node['owner_user_id'] ?? 0);
        if ($owner > 0 && function_exists('ap_phyrian_is_origin_owner') && ap_phyrian_is_origin_owner($owner)) {
            $originKey = (string) $id;
            break;
        }
    }
    $viewerKey = $viewerId > 0 ? 'u' . $viewerId : '';
    $path = [];
    $cursor = $viewerKey;
    while ($cursor !== '' && isset($tree['nodes'][$cursor]) && !isset($path[$cursor])) {
        $path[$cursor] = true;
        $parent = (string) ($tree['nodes'][$cursor]['parent_key'] ?? '');
        $cursor = isset($tree['nodes'][$parent]) ? $parent : '';
    }

    $html = '<section class="phyrian-tree-page" aria-label="Family tree">';
    $html .= '<h3 class="phyrian-section-title" style="margin-top:0">This week</h3>';
    $html .= '<div class="phyrian-recap">';
    $html .= '<div><b>' . (int) $recap['new_imprints'] . '</b><span class="meta">new imprints</span></div>';
    $html .= '<div><b>' . (int) $recap['exchanges'] . '</b><span class="meta">resonance exchanges</span></div>';
    $html .= '<div><b>' . (int) $recap['new_clans'] . '</b><span class="meta">new clans</span></div>';
    $html .= '<div><b>' . count($rows) . '</b><span class="meta">carriers</span></div>';
    $html .= '</div>';
    if ((int) $recap['top_count'] > 0 && isset($tree['nodes'][$recap['top_root']])) {
        $html .= '<p class="meta" style="margin:.5rem 0 0">Fastest-growing line: '
            . ap_phyrian_tree_h(ap_phyrian_tree_name($tree['nodes'][$recap['top_root']]))
            . '\'s, with ' . (int) $recap['top_count'] . ' new ' . ((int) $recap['top_count'] === 1 ? 'carrier' : 'carriers') . '.</p>';
    }
    if ($viewerId > 0) {
        $html .= '<details class="phyrian-recap-share"><summary>Share this recap</summary>'
            . '<form method="post" action="?view=phyrian&amp;page=tree">'
            . '<input type="hidden" name="csrf" value="' . ap_phyrian_tree_h($csrf) . '">'
            . '<input type="hidden" name="action" value="phyrian_recap_post">'
            . '<input type="hidden" name="confirm" value="1">'
            . '<label for="phyrian-recap-text">Post text</label>'
            . '<textarea id="phyrian-recap-text" name="recap_text" maxlength="' . (string) AP_PHYRIAN_TREE_RECAP_TEXT_MAX . '" required rows="3">'
            . ap_phyrian_tree_h(ap_phyrian_tree_recap_text($recap, count($rows)))
            . '</textarea>'
            . '<div class="composer-actions"><span class="meta">Posts publicly from your account. The draft leaves out names; nothing is posted until you confirm.</span>'
            . '<button class="btn btn-primary" type="submit">Post recap</button></div>'
            . '</form></details>';
    }

    $html .= '<h3 class="phyrian-section-title">Family tree</h3>';
    $html .= '<p class="meta" style="margin:0 0 .75rem">Who imprinted whom, on VAAK and the NovaLandia grid. Grid-linked players keep the stats synced from the grid; grid-only avatars get a profile link once they link a VAAK account.</p>';
    if ($viewerKey !== '' && !isset($tree['nodes'][$viewerKey])) {
        $html .= '<p class="meta" style="margin:0 0 .75rem">You are not on the tree yet. Accept an imprint to join a line.</p>';
    }
    if ($tree['roots'] === []) {
        return $html . '<p class="empty">No imprinted players yet.</p></section>';
    }
    // Origin line first, then the rest in imprint order.
    $roots = $tree['roots'];
    usort($roots, static fn (string $a, string $b): int => ($b === $originKey) <=> ($a === $originKey));
    $html .= '<ul class="phyrian-tree">';
    foreach ($roots as $root) {
        $html .= ap_phyrian_tree_node_html($tree, $root, 0, $viewerKey, $path, $recap['new_ids'], $originKey);
    }
    return $html . '</ul></section>';
}
