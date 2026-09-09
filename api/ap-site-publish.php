<?php
/**
 * Publish a site post/note as a public Create Note on @cmdr_nova.
 *
 * CLI (preferred from update_site.sh via SSH):
 *   echo '{"title":"...","url":"https://...","summary":"...","kind":"post"}' \
 *     | php ap-site-publish.php --stdin
 *
 * HTTP (optional): POST JSON with header X-AP-Publish-Key matching /etc/mkultra/ap-publish.env
 * Caddy should not expose this publicly without additional lockdown.
 */
declare(strict_types=1);

const AP_PUBLISH_ENV = '/etc/mkultra/ap-publish.env';
const AP_PUBLISH_ACTOR = 'https://mkultra.monster/users/cmdr_nova';
const AP_PUBLISH_PRIV = '/etc/mkultra/ap-inbox/cmdr_nova_private.pem';
const AP_PUBLISH_KEY_ID = 'https://mkultra.monster/users/cmdr_nova#main-key';

require_once __DIR__ . '/ap-db.php';

$isCli = (PHP_SAPI === 'cli');

if (!$isCli) {
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: no-store');
    header('X-Robots-Tag: noindex, nofollow');
}

// Production VAAK is on Postgres. A bare CLI `php` without AP_DB_DSN silently
// writes to the legacy SQLite file, so posts "succeed" then vanish from timelines.
if ((!defined('AP_SITE_PUBLISH_LIB_ONLY') || !AP_SITE_PUBLISH_LIB_ONLY)
    && ap_db_driver() !== 'pgsql') {
    $msg = 'ap-site-publish requires AP_DB_DSN=pgsql:… (refusing SQLite fallback)';
    if ($isCli) {
        fwrite(STDERR, $msg . "\n");
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES) . "\n";
        exit(1);
    }
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @return array{title:string,url:string,summary:string,kind:string,content:string,tags:list<string>}
 */
function ap_publish_parse_input(bool $isCli): array
{
    $normalize = static function (array $json): array {
        $tags = $json['tags'] ?? [];
        if (!is_array($tags)) {
            $tags = [];
        }
        $tagsOut = [];
        foreach ($tags as $t) {
            $t = trim((string) $t);
            if ($t === '') {
                continue;
            }
            if ($t[0] !== '#') {
                $t = '#' . preg_replace('/\s+/', '', $t);
            }
            $tagsOut[] = $t;
            if (count($tagsOut) >= 12) {
                break;
            }
        }
        return [
            'title' => trim((string) ($json['title'] ?? '')),
            'url' => trim((string) ($json['url'] ?? '')),
            'summary' => trim((string) ($json['summary'] ?? '')),
            'content' => trim((string) ($json['content'] ?? '')),
            'kind' => trim((string) ($json['kind'] ?? 'post')),
            'tags' => $tagsOut,
        ];
    };

    if ($isCli) {
        $opts = getopt('', ['stdin', 'title:', 'url:', 'summary::', 'kind::']);
        if (array_key_exists('stdin', $opts)) {
            $raw = stream_get_contents(STDIN) ?: '';
            $json = json_decode($raw, true);
            if (!is_array($json)) {
                fwrite(STDERR, "Invalid JSON on stdin\n");
                exit(1);
            }
            return $normalize($json);
        }
        return $normalize([
            'title' => (string) ($opts['title'] ?? ''),
            'url' => (string) ($opts['url'] ?? ''),
            'summary' => (string) ($opts['summary'] ?? ''),
            'kind' => (string) ($opts['kind'] ?? 'post'),
        ]);
    }

    $keyExpected = ap_publish_load_key();
    $keyGot = (string) ($_SERVER['HTTP_X_AP_PUBLISH_KEY'] ?? '');
    if ($keyExpected === '' || !hash_equals($keyExpected, $keyGot)) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo '{"ok":false,"error":"unauthorized"}';
        exit;
    }
    if (strtoupper($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Content-Type: application/json');
        echo '{"ok":false,"error":"POST required"}';
        exit;
    }
    $raw = file_get_contents('php://input') ?: '';
    $json = json_decode($raw, true);
    if (!is_array($json)) {
        $json = $_POST;
    }
    return $normalize(is_array($json) ? $json : []);
}

function ap_publish_load_key(): string
{
    if (!is_file(AP_PUBLISH_ENV)) {
        return '';
    }
    $lines = file(AP_PUBLISH_ENV, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) {
            continue;
        }
        if (preg_match('/^AP_PUBLISH_KEY=(.*)$/', $line, $m)) {
            return trim($m[1], " \t\"'");
        }
    }
    return '';
}

function ap_publish_migrate(): void
{
    try {
        ap_db()->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS site_syndications (
    url TEXT PRIMARY KEY,
    note_id TEXT NOT NULL,
    create_id TEXT NOT NULL,
    kind TEXT,
    title TEXT,
    published_at TEXT NOT NULL
);
SQL);
    } catch (Throwable $e) {
        // www-data on Postgres often cannot CREATE; table is already provisioned.
        try {
            ap_db()->query('SELECT 1 FROM site_syndications LIMIT 1');
        } catch (Throwable $e2) {
            throw $e;
        }
    }
}

/** HTML → plain text, keeping paragraph/line breaks for timeline display. */
function ap_publish_html_to_plain(string $html): string
{
    $html = preg_replace('#</p>\s*<p[^>]*>#i', "\n\n", $html) ?? $html;
    $html = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
    $html = preg_replace('#</(p|div|h[1-6]|li|blockquote)>#i', "$0\n", $html) ?? $html;
    $text = trim(html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    // Collapse runs of spaces/tabs, but keep newlines (paragraph returns).
    $text = preg_replace('/[^\S\n]+/u', ' ', $text) ?? $text;
    $text = preg_replace('/\n{3,}/u', "\n\n", $text) ?? $text;
    return trim($text);
}

/**
 * @param list<string> $tags
 * @return array{ok:bool,error?:string,note_id?:string,create_id?:string,delivered?:int,duplicate?:bool}
 */
function ap_site_publish_note(
    string $title,
    string $url,
    string $summary = '',
    string $kind = 'post',
    array $tags = [],
    string $content = ''
): array {
    $title = trim(ap_fix_utf8($title));
    $url = trim($url);
    $summary = trim(ap_fix_utf8($summary));
    $content = trim(ap_fix_utf8($content));
    // Map: post=blog share card, note=plain status like compose
    $kindIn = in_array($kind, ['post', 'blog', 'note'], true) ? $kind : 'post';
    $outboxKind = $kindIn === 'note' ? 'note' : 'blog';

    if (!str_starts_with($url, 'https://mkultra.monster/')) {
        return ['ok' => false, 'error' => 'url must be https://mkultra.monster/…'];
    }

    if ($outboxKind === 'blog') {
        if ($title === '' || mb_strlen($title) > 300) {
            return ['ok' => false, 'error' => 'title required (max 300)'];
        }
        if (mb_strlen($summary) > 500) {
            $summary = mb_substr($summary, 0, 497) . '…';
        }
    } else {
        // Regular note/status body
        $body = $content !== '' ? $content : $summary;
        if ($body === '') {
            $body = $title;
        }
        if ($body === '' || mb_strlen($body) > 2000) {
            return ['ok' => false, 'error' => 'note content required (max 2000)'];
        }
        if ($title === '') {
            $title = mb_substr(strip_tags($body), 0, 80);
        }
        $content = $body;
    }

    ap_publish_migrate();

    $existing = ap_db()->prepare('SELECT note_id, create_id FROM site_syndications WHERE url = ?');
    $existing->execute([$url]);
    $row = $existing->fetch();
    if ($row) {
        // Older shares may exist in site_syndications/outbox without a Mastodon row.
        $noteId = (string) $row['note_id'];
        $createId = (string) $row['create_id'];
        if ($noteId !== '' && !ap_masto_status_by_note_id($noteId)) {
            $st = ap_db()->prepare('SELECT content, published FROM outbox_notes WHERE id = ?');
            $st->execute([$noteId]);
            $ob = $st->fetch();
            $html = is_array($ob) ? (string) ($ob['content'] ?? '') : '';
            $published = is_array($ob) && !empty($ob['published'])
                ? (string) $ob['published']
                : gmdate('c');
            $text = ap_publish_html_to_plain($html);
            if ($text === '') {
                $text = $title !== '' ? $title : '(shared post)';
            }
            ap_masto_status_register($noteId, $createId, $published, $text);
        }
        return [
            'ok' => true,
            'duplicate' => true,
            'note_id' => $noteId,
            'create_id' => $createId,
            'delivered' => 0,
        ];
    }

    if (!defined('AP_INBOX_LIB_ONLY')) {
        define('AP_INBOX_LIB_ONLY', true);
    }
    require_once __DIR__ . '/ap-inbox.php';

    $safeUrl = htmlspecialchars($url, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    if ($outboxKind === 'blog') {
        // Mastodon-style share: title, short description, link, hashtags
        $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $parts = ['<p>Today on NovaLandia: <strong>' . $safeTitle . '</strong></p>'];
        if ($summary !== '') {
            $esc = htmlspecialchars($summary, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            $parts[] = '<p>' . nl2br($esc, false) . '</p>';
        }
        $parts[] = '<p><a href="' . $safeUrl . '">' . $safeUrl . '</a></p>';
        if ($tags) {
            $tagHtml = [];
            foreach ($tags as $tag) {
                $tagHtml[] = htmlspecialchars($tag, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
            }
            $parts[] = '<p>' . implode(' ', $tagHtml) . '</p>';
        }
        // Separate <p> blocks with newlines so plain-text extraction keeps returns
        // after title, summary, and link.
        $contentHtml = implode("\n", $parts);
    } else {
        // Plain status like admin compose (Mastodon-style paragraphs)
        $contentHtml = function_exists('ap_plain_text_to_html')
            ? ap_plain_text_to_html($content)
            : '<p>' . nl2br(htmlspecialchars($content, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'), false) . '</p>';
    }

    $noteId = AP_PUBLISH_ACTOR . '/notes/' . bin2hex(random_bytes(8));
    $createId = AP_PUBLISH_ACTOR . '/creates/' . bin2hex(random_bytes(8));
    $published = gmdate('c');
    $to = ['https://www.w3.org/ns/activitystreams#Public'];
    $cc = [AP_PUBLISH_ACTOR . '/followers'];

    $asTags = [];
    foreach ($tags as $tag) {
        $name = ltrim($tag, '#');
        $asTags[] = [
            'type' => 'Hashtag',
            'name' => '#' . $name,
            'href' => 'https://mkultra.monster/tags/' . rawurlencode(strtolower($name)),
        ];
    }

    $note = [
        'id' => $noteId,
        'type' => 'Note',
        'published' => $published,
        'attributedTo' => AP_PUBLISH_ACTOR,
        'content' => $contentHtml,
        'url' => $url,
        'to' => $to,
        'cc' => $cc,
        'interactionPolicy' => ap_note_interaction_policy_public(),
    ];
    if ($asTags) {
        $note['tag'] = $asTags;
    }
    $create = ap_create_finalize([
        'id' => $createId,
        'type' => 'Create',
        'actor' => AP_PUBLISH_ACTOR,
        'published' => $published,
        'to' => $to,
        'cc' => $cc,
        'object' => $note,
    ]);

    ap_outbox_store([
        'id' => $noteId,
        'create_id' => $createId,
        'published' => $published,
        'content' => $contentHtml,
        'in_reply_to' => null,
        'to' => $to,
        'cc' => $cc,
        'raw_create_json' => json_encode($create, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        'kind' => $outboxKind,
    ]);

    // Ice Cubes / Mastodon API timelines read masto_statuses, not outbox alone.
    // Keep paragraph returns (title / summary / link) — do not collapse to one line.
    $plain = ap_publish_html_to_plain($contentHtml);
    if ($plain === '') {
        $plain = $title !== '' ? $title : '(shared post)';
    }
    ap_masto_status_register($noteId, $createId, $published, $plain);

    $targets = ap_delivery_targets_filtered(ap_followers_list(), ap_known_shared_inboxes(40));

    $delivered = 0;
    $n = 0;
    foreach ($targets as $inbox) {
        if ($n >= 50) {
            break;
        }
        if (ap_deliver_signed_json($inbox, $create, AP_PUBLISH_KEY_ID, AP_PUBLISH_PRIV)) {
            $delivered++;
        }
        $n++;
    }

    $ins = ap_db()->prepare(
        'INSERT INTO site_syndications (url, note_id, create_id, kind, title, published_at) VALUES (?, ?, ?, ?, ?, ?)'
    );
    $ins->execute([$url, $noteId, $createId, $kind, $title, $published]);

    ap_metrics_record('Create', AP_PUBLISH_ACTOR, $noteId, null, strlen($contentHtml), 'site_syndicate_' . $kind, $title);
    if (function_exists('ap_log')) {
        ap_log("site_syndicate kind=$kind create=$createId delivered=$delivered url=$url");
    }

    return [
        'ok' => true,
        'note_id' => $noteId,
        'create_id' => $createId,
        'delivered' => $delivered,
        'duplicate' => false,
    ];
}

if (defined('AP_SITE_PUBLISH_LIB_ONLY') && AP_SITE_PUBLISH_LIB_ONLY) {
    return;
}

$input = ap_publish_parse_input($isCli);
$result = ap_site_publish_note(
    $input['title'],
    $input['url'],
    $input['summary'],
    $input['kind'],
    $input['tags'],
    $input['content']
);

if ($isCli) {
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
    exit(!empty($result['ok']) ? 0 : 1);
}

header('Content-Type: application/json; charset=utf-8');
http_response_code(!empty($result['ok']) ? 200 : 400);
echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
