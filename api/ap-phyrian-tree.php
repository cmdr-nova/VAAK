<?php
declare(strict_types=1);

/**
 * Phyrian family tree and weekly recap.
 *
 * Read-only view of who imprinted whom on this server, built from
 * phyrian_players.imprinted_by_owner_id. The OpenSim bridge never writes that
 * column, so grid-linked players keep their grid-synced stats here and show
 * up as roots when their imprint happened on the grid.
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
                    CASE WHEN EXISTS (
                        SELECT 1 FROM phyrian_bridge_links l
                        WHERE l.owner_user_id = p.owner_user_id
                          AND l.status = 'verified' AND l.unlinked_at IS NULL
                    ) THEN 1 ELSE 0 END AS linked
             FROM phyrian_players p
             LEFT JOIN ap_users u ON u.id = p.owner_user_id
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
        $rows[] = [
            'owner_user_id' => $id,
            'username' => trim((string) ($row['username'] ?? '')),
            'strain' => trim((string) ($row['strain'] ?? '')),
            'generation' => (int) ($row['generation'] ?? 1),
            'parent' => (int) ($row['imprinted_by_owner_id'] ?? 0),
            'imprinted_at' => (string) ($row['imprinted_at'] ?? ''),
            'linked' => !empty($row['linked']),
        ];
    }
    return ['ok' => true, 'rows' => $rows];
}

/**
 * Parent/child links. A player whose parent is missing, unimprinted, itself,
 * or part of a loop becomes a root, so every row appears exactly once.
 *
 * @param list<array<string,mixed>> $rows
 * @return array{nodes:array<int,array<string,mixed>>,children:array<int,list<int>>,roots:list<int>,root_of:array<int,int>}
 */
function ap_phyrian_tree_build(array $rows): array
{
    $nodes = [];
    foreach ($rows as $row) {
        $nodes[(int) $row['owner_user_id']] = $row;
    }
    $children = [];
    $roots = [];
    foreach ($nodes as $id => $row) {
        $parent = (int) ($row['parent'] ?? 0);
        if ($parent > 0 && $parent !== $id && isset($nodes[$parent])) {
            $children[$parent][] = $id;
        } else {
            $roots[] = $id;
        }
    }
    $rootOf = [];
    $walk = static function (int $root) use (&$children, &$rootOf): void {
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
        if (isset($rootOf[$id])) {
            continue;
        }
        $parent = (int) $nodes[$id]['parent'];
        $children[$parent] = array_values(array_filter(
            $children[$parent] ?? [],
            static fn (int $child): bool => $child !== $id
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
 * @param array{root_of:array<int,int>} $tree
 * @return array{new_imprints:int,new_ids:array<int,bool>,exchanges:int,new_clans:int,top_root:int,top_count:int}
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
        $id = (int) $row['owner_user_id'];
        $newIds[$id] = true;
        $root = (int) ($tree['root_of'][$id] ?? $id);
        // A brand-new root is not growth of an existing line.
        if ($root !== $id) {
            $perRoot[$root] = ($perRoot[$root] ?? 0) + 1;
        }
    }
    $topRoot = 0;
    $topCount = 0;
    foreach ($perRoot as $root => $count) {
        if ($count > $topCount) {
            $topRoot = (int) $root;
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

function ap_phyrian_tree_name(array $node): string
{
    $name = trim((string) ($node['username'] ?? ''));
    return $name !== '' ? $name : 'user' . (int) ($node['owner_user_id'] ?? 0);
}

/**
 * @param array{nodes:array<int,array<string,mixed>>,children:array<int,list<int>>} $tree
 * @param array<int,bool> $path viewer and their ancestors
 * @param array<int,bool> $newIds
 */
function ap_phyrian_tree_node_html(array $tree, int $id, int $depth, int $viewerId, array $path, array $newIds, int $originId): string
{
    $node = $tree['nodes'][$id];
    $kids = $tree['children'][$id] ?? [];
    $isSelf = $id === $viewerId;
    $href = $isSelf ? '?view=phyrian&amp;page=dossier' : '?view=phyrian&amp;page=dossier&amp;owner=' . $id;
    $label = '<a href="' . $href . '">@' . ap_phyrian_tree_h(ap_phyrian_tree_name($node)) . '</a>'
        . ' <span class="meta">' . ap_phyrian_tree_h((string) $node['strain']) . ' · Gen ' . (int) $node['generation'] . '</span>';
    if ($isSelf) {
        $label .= ' <span class="phyrian-badge">You</span>';
    }
    if ($id === $originId) {
        $label .= ' <span class="phyrian-badge phyrian-badge--origin">Origin</span>';
    }
    if (!empty($node['linked'])) {
        $label .= ' <span class="phyrian-badge phyrian-badge--grid" title="Stats synced from the NovaLandia grid">Grid-linked</span>';
    }
    if (isset($newIds[$id])) {
        $label .= ' <span class="phyrian-badge phyrian-badge--new">New this week</span>';
    }
    if ($depth === 0 && $id !== $originId) {
        $parent = (int) ($node['parent'] ?? 0);
        if ($parent > 0) {
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
        $html .= ap_phyrian_tree_node_html($tree, $kid, $depth + 1, $viewerId, $path, $newIds, $originId);
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
    $originId = 0;
    foreach ($tree['nodes'] as $id => $node) {
        if (function_exists('ap_phyrian_is_origin_owner') && ap_phyrian_is_origin_owner((int) $id)) {
            $originId = (int) $id;
            break;
        }
    }
    $path = [];
    $cursor = $viewerId;
    while ($cursor > 0 && isset($tree['nodes'][$cursor]) && !isset($path[$cursor])) {
        $path[$cursor] = true;
        $parent = (int) ($tree['nodes'][$cursor]['parent'] ?? 0);
        $cursor = isset($tree['nodes'][$parent]) ? $parent : 0;
    }

    $html = '<section class="phyrian-tree-page" aria-label="Family tree">';
    $html .= '<h3 class="phyrian-section-title" style="margin-top:0">This week</h3>';
    $html .= '<div class="phyrian-recap">';
    $html .= '<div><b>' . (int) $recap['new_imprints'] . '</b><span class="meta">new imprints</span></div>';
    $html .= '<div><b>' . (int) $recap['exchanges'] . '</b><span class="meta">resonance exchanges</span></div>';
    $html .= '<div><b>' . (int) $recap['new_clans'] . '</b><span class="meta">new clans</span></div>';
    $html .= '<div><b>' . count($rows) . '</b><span class="meta">carriers</span></div>';
    $html .= '</div>';
    if ((int) $recap['top_count'] > 0 && isset($tree['nodes'][(int) $recap['top_root']])) {
        $html .= '<p class="meta" style="margin:.5rem 0 0">Fastest-growing line: @'
            . ap_phyrian_tree_h(ap_phyrian_tree_name($tree['nodes'][(int) $recap['top_root']]))
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
    $html .= '<p class="meta" style="margin:0 0 .75rem">Who imprinted whom on this server. Grid-linked players keep the stats synced from the NovaLandia grid.</p>';
    if ($viewerId > 0 && !isset($tree['nodes'][$viewerId])) {
        $html .= '<p class="meta" style="margin:0 0 .75rem">You are not on the tree yet. Accept an imprint to join a line.</p>';
    }
    if ($tree['roots'] === []) {
        return $html . '<p class="empty">No imprinted players yet.</p></section>';
    }
    // Origin line first, then the rest in imprint order.
    $roots = $tree['roots'];
    usort($roots, static fn (int $a, int $b): int => ($b === $originId) <=> ($a === $originId));
    $html .= '<ul class="phyrian-tree">';
    foreach ($roots as $root) {
        $html .= ap_phyrian_tree_node_html($tree, $root, 0, $viewerId, $path, $recap['new_ids'], $originId);
    }
    return $html . '</ul></section>';
}
