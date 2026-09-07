<?php
/**
 * VAAK instance policy docs — privacy + code of conduct + Mastodon rules.
 * Editable by admin in /vaak/?view=policies; public at /vaak/privacy/ and /vaak/conduct/.
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


require_once __DIR__ . '/ap-db.php';

const AP_INSTANCE_PRIVACY_URL = 'https://mkultra.monster/vaak/privacy/';
const AP_INSTANCE_CONDUCT_URL = 'https://mkultra.monster/vaak/conduct/';

function ap_instance_docs_migrate(?PDO $db = null): void
{
    $db = $db ?? ap_db();
    // PostgreSQL staging is provisioned and validated before FPM starts.
    // The SQLite DDL below only supports legacy on-demand SQLite upgrades.
    if (ap_db_driver($db) === 'pgsql') {
        return;
    }
    $db->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS ap_instance_docs (
    doc_key TEXT PRIMARY KEY,
    title TEXT NOT NULL,
    body TEXT NOT NULL DEFAULT '',
    updated_at TEXT NOT NULL
);
CREATE TABLE IF NOT EXISTS ap_instance_rules (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    sort_order INTEGER NOT NULL DEFAULT 0,
    text TEXT NOT NULL,
    updated_at TEXT NOT NULL
);
SQL);

    $st = $db->query("SELECT COUNT(*) FROM ap_instance_docs WHERE doc_key = 'privacy'");
    if ((int) $st->fetchColumn() === 0) {
        $db->prepare(
            'INSERT INTO ap_instance_docs (doc_key, title, body, updated_at) VALUES (?, ?, ?, ?)'
        )->execute(['privacy', 'Privacy Policy', ap_instance_docs_default_privacy(), ap_db_now()]);
    }

    $st = $db->query("SELECT COUNT(*) FROM ap_instance_docs WHERE doc_key = 'conduct'");
    if ((int) $st->fetchColumn() === 0) {
        $db->prepare(
            'INSERT INTO ap_instance_docs (doc_key, title, body, updated_at) VALUES (?, ?, ?, ?)'
        )->execute(['conduct', 'Code of Conduct', ap_instance_docs_default_conduct(), ap_db_now()]);
    }

    $st = $db->query('SELECT COUNT(*) FROM ap_instance_rules');
    if ((int) $st->fetchColumn() === 0) {
        $now = ap_db_now();
        $ins = $db->prepare(
            'INSERT INTO ap_instance_rules (sort_order, text, updated_at) VALUES (?, ?, ?)'
        );
        foreach (ap_instance_docs_default_rules() as $i => $text) {
            $ins->execute([$i + 1, $text, $now]);
        }
    }
}

function ap_instance_docs_default_privacy(): string
{
    return <<<'TXT'
VAAK is the invite-only ActivityPub / Mastodon-compatible server on mkultra.monster. The public website (blog, radio, archives) has its own site privacy note at https://mkultra.monster/pages/privacy/ — this page covers federation accounts and the VAAK web app.

What is public by design

- Your public profile (display name, bio, avatar, profile fields) and public posts are part of the open web. Other Fediverse servers that follow or interact with you may fetch and store them under their own policies.
- Followers / following lists may be visible according to ActivityPub norms.
- Remote servers that receive public activities process them under their own rules (including large commercial services if federation is not blocked).

What this server may store

- Account credentials (password hashes), invite codes, and session cookies for VAAK login.
- ActivityPub inbox/outbox traffic needed to operate your actor (follows, accepts, public posts/mentions observed locally, delivery logs).
- OAuth tokens for apps you authorize (e.g. Ice Cubes), stored hashed, with expiry and admin revoke.
- Optional media uploaded for posts, subject to size limits.
- Server access logs (IP, user-agent, path) retained for operations and abuse response on normal web-server schedules.

What we do not do

- No ads, no sale of personal data, no training of third-party language models on private inbox contents for commercial product features.
- Direct messages, where supported, are treated as private between participants but remain visible to this server’s operator and to any remote servers that receive them — do not send secrets over the Fediverse.

Contact

Questions about this policy or a VAAK account: cmdr-nova@mkultra.monster
TXT;
}

function ap_instance_docs_default_conduct(): string
{
    return <<<'TXT'
mkultra.monster / VAAK is a small invite-only community. Treat it like a shared living room, not a stadium.

The numbered rules below are also exposed to Mastodon-compatible apps via the instance API. The operator (cmdr_nova) may update them at any time; this page always reflects the current text.

If something goes wrong, contact cmdr-nova@mkultra.monster. Accounts may be suspended or removed for serious or repeated violations.
TXT;
}

/** @return list<string> */
function ap_instance_docs_default_rules(): array
{
    return [
        'Invite-only server. Do not share unused invite codes publicly without permission.',
        'Be excellent. Harassment, stalking, and targeted abuse are not welcome.',
        'No illegal content. That includes CSAM, non-consensual intimate imagery, and clear incitement to imminent violence.',
        'Spam, scams, and malware links will be removed; repeat offenders lose access.',
        'Respect consent around media and mentions. Content warnings are appreciated for graphic or NSFW material.',
        'The operator’s call is final on moderation for this tiny server.',
    ];
}

/**
 * @return array{doc_key:string,title:string,body:string,updated_at:?string}
 */
function ap_instance_doc_get(string $key): array
{
    ap_instance_docs_migrate();
    $key = $key === 'conduct' ? 'conduct' : 'privacy';
    $st = ap_db()->prepare('SELECT doc_key, title, body, updated_at FROM ap_instance_docs WHERE doc_key = ?');
    $st->execute([$key]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!is_array($row)) {
        return [
            'doc_key' => $key,
            'title' => $key === 'conduct' ? 'Code of Conduct' : 'Privacy Policy',
            'body' => $key === 'conduct' ? ap_instance_docs_default_conduct() : ap_instance_docs_default_privacy(),
            'updated_at' => null,
        ];
    }
    return [
        'doc_key' => (string) $row['doc_key'],
        'title' => (string) ($row['title'] ?: ($key === 'conduct' ? 'Code of Conduct' : 'Privacy Policy')),
        'body' => (string) ($row['body'] ?? ''),
        'updated_at' => isset($row['updated_at']) ? (string) $row['updated_at'] : null,
    ];
}

function ap_instance_doc_save(string $key, string $title, string $body): array
{
    ap_instance_docs_migrate();
    $key = $key === 'conduct' ? 'conduct' : 'privacy';
    $title = trim($title);
    if ($title === '') {
        $title = $key === 'conduct' ? 'Code of Conduct' : 'Privacy Policy';
    }
    if (mb_strlen($title) > 120) {
        return ['ok' => false, 'error' => 'Title is too long.'];
    }
    if (mb_strlen($body) > 100000) {
        return ['ok' => false, 'error' => 'Body is too long.'];
    }
    $now = ap_db_now();
    ap_db()->prepare(
        'INSERT INTO ap_instance_docs (doc_key, title, body, updated_at) VALUES (?, ?, ?, ?)
         ON CONFLICT(doc_key) DO UPDATE SET title = excluded.title, body = excluded.body, updated_at = excluded.updated_at'
    )->execute([$key, $title, $body, $now]);
    return ['ok' => true, 'updated_at' => $now];
}

/**
 * @return list<array{id:string,text:string}>
 */
function ap_instance_rules_list(): array
{
    ap_instance_docs_migrate();
    $st = ap_db()->query('SELECT id, text FROM ap_instance_rules ORDER BY sort_order ASC, id ASC');
    $out = [];
    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $text = trim((string) ($row['text'] ?? ''));
        if ($text === '') {
            continue;
        }
        $out[] = [
            'id' => (string) (int) $row['id'],
            'text' => $text,
        ];
    }
    if ($out === []) {
        foreach (ap_instance_docs_default_rules() as $i => $text) {
            $out[] = ['id' => (string) ($i + 1), 'text' => $text];
        }
    }
    return $out;
}

/**
 * Replace all rules from a newline-separated textarea (one rule per non-empty line).
 *
 * @return array{ok:bool,error?:string,count?:int}
 */
function ap_instance_rules_save_from_text(string $raw): array
{
    ap_instance_docs_migrate();
    $lines = preg_split('/\R/u', $raw) ?: [];
    $rules = [];
    foreach ($lines as $line) {
        $line = trim($line);
        // Allow "1. rule" style from copy-paste
        $line = preg_replace('/^\d+[\.\)]\s+/', '', $line) ?? $line;
        if ($line === '') {
            continue;
        }
        if (mb_strlen($line) > 500) {
            return ['ok' => false, 'error' => 'Each rule must be 500 characters or fewer.'];
        }
        $rules[] = $line;
        if (count($rules) > 40) {
            return ['ok' => false, 'error' => 'Too many rules (max 40).'];
        }
    }
    if ($rules === []) {
        return ['ok' => false, 'error' => 'Add at least one rule.'];
    }

    $db = ap_db();
    $db->beginTransaction();
    try {
        $db->exec('DELETE FROM ap_instance_rules');
        $ins = $db->prepare(
            'INSERT INTO ap_instance_rules (sort_order, text, updated_at) VALUES (?, ?, ?)'
        );
        $now = ap_db_now();
        foreach ($rules as $i => $text) {
            $ins->execute([$i + 1, $text, $now]);
        }
        $db->commit();
    } catch (Throwable $e) {
        if ($db->inTransaction()) {
            $db->rollBack();
        }
        return ['ok' => false, 'error' => 'Could not save rules.'];
    }
    return ['ok' => true, 'count' => count($rules)];
}

/** Escape + light formatting for public policy pages. */
function ap_instance_docs_format_body(string $body): string
{
    $body = str_replace(["\r\n", "\r"], "\n", $body);
    $body = trim($body);
    if ($body === '') {
        return '<p class="muted">No text yet.</p>';
    }
    $parts = preg_split("/\n{2,}/", $body) ?: [];
    $html = '';
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        $esc = htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $esc = preg_replace(
            '#https?://[^\s<]+#u',
            '<a href="$0" rel="noopener noreferrer">$0</a>',
            $esc
        ) ?? $esc;
        $esc = nl2br($esc, false);
        $html .= '<p>' . $esc . '</p>';
    }
    return $html !== '' ? $html : '<p class="muted">No text yet.</p>';
}

/**
 * Shared VAAK-branded public document shell.
 *
 * @param list<array{id:string,text:string}>|null $rules
 */
function ap_instance_docs_render_public_page(string $docKey, ?array $rules = null): void
{
    $doc = ap_instance_doc_get($docKey);
    $title = $doc['title'];
    $safeTitle = htmlspecialchars($title, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $updated = $doc['updated_at'] ? htmlspecialchars((string) $doc['updated_at'], ENT_QUOTES, 'UTF-8') : '';
    $bodyHtml = ap_instance_docs_format_body($doc['body']);
    $asciiV = <<<'ASCII'
██╗   ██╗
██║   ██║
██║   ██║
╚██╗ ██╔╝
 ╚████╔╝
  ╚═══╝
ASCII;

    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: public, max-age=120');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width,initial-scale=1">';
    echo '<title>' . $safeTitle . ' · VAAK</title>';
    echo '<link rel="icon" href="/vaak/favicon.svg" type="image/svg+xml">';
    echo '<link rel="icon" href="/vaak/favicon.ico" sizes="any">';
    echo '<style>
:root{color-scheme:dark}
*{box-sizing:border-box}
body{margin:0;min-height:100vh;background:#000;color:#e8e8e8;
font-family:ui-sans-serif,system-ui,-apple-system,Segoe UI,Roboto,sans-serif;line-height:1.55;padding:1.5rem}
.wrap{width:min(42rem,100%);margin:0 auto}
.brand{text-align:center;margin-bottom:1.5rem}
.brand pre{margin:0 auto;display:inline-block;text-align:left;
font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;
font-size:clamp(.55rem,2.2vw,.8rem);line-height:1.05;color:#00ff9f;
text-shadow:0 0 24px rgba(0,255,159,.25)}
.brand .word{margin:.65rem 0 0;font-size:.85rem;font-weight:600;letter-spacing:.35em;color:#8a8a8a;text-indent:.35em}
.card{border:1px solid #222;border-radius:14px;padding:1.25rem 1.2rem 1.35rem;background:#0a0a0a}
h1{margin:0 0 .35rem;font-size:1.35rem;color:#eee}
.meta{font-size:.78rem;color:#777;margin:0 0 1rem}
.card p{margin:0 0 .85rem;color:#d0d0d0}
.card p:last-child{margin-bottom:0}
.card a{color:#7ee0ff;text-decoration:none}
.card a:hover{text-decoration:underline;text-underline-offset:2px}
.muted{color:#888}
.rules{margin:1.1rem 0 0;padding:0;list-style:none;counter-reset:rule}
.rules li{position:relative;padding:.7rem .75rem .7rem 2.4rem;margin:0 0 .45rem;
border:1px solid #1e1e1e;border-radius:10px;background:#050505;color:#d8d8d8}
.rules li::before{counter-increment:rule;content:counter(rule);position:absolute;left:.7rem;top:.7rem;
width:1.35rem;height:1.35rem;border-radius:999px;background:rgba(0,255,159,.12);color:#00ff9f;
font-size:.75rem;font-weight:700;display:flex;align-items:center;justify-content:center}
.footer{margin:1.25rem 0 0;padding-top:1rem;border-top:1px solid #1a1a1a;font-size:.8rem;color:#777;
display:flex;flex-wrap:wrap;gap:.35rem .85rem}
.footer a{color:#9ad4e8;text-decoration:none}
.footer a:hover{text-decoration:underline;text-underline-offset:2px}
.sep{color:#444;user-select:none}
</style></head><body><div class="wrap">';
    echo '<div class="brand"><pre>' . htmlspecialchars($asciiV, ENT_QUOTES, 'UTF-8') . '</pre>';
    echo '<div class="word">VAAK</div></div>';
    echo '<article class="card">';
    echo '<h1>' . $safeTitle . '</h1>';
    if ($updated !== '') {
        echo '<p class="meta">Updated ' . $updated . ' · mkultra.monster</p>';
    } else {
        echo '<p class="meta">mkultra.monster · invite-only ActivityPub</p>';
    }
    echo $bodyHtml;
    if ($rules !== null && $rules !== []) {
        echo '<ol class="rules">';
        foreach ($rules as $rule) {
            echo '<li>' . htmlspecialchars($rule['text'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '</li>';
        }
        echo '</ol>';
    }
    echo '</article>';
    echo '<nav class="footer" aria-label="VAAK policies">';
    echo '<a href="' . htmlspecialchars(AP_INSTANCE_PRIVACY_URL, ENT_QUOTES, 'UTF-8') . '">Privacy</a>';
    echo '<span class="sep" aria-hidden="true">·</span>';
    echo '<a href="' . htmlspecialchars(AP_INSTANCE_CONDUCT_URL, ENT_QUOTES, 'UTF-8') . '">Code of Conduct</a>';
    echo '<span class="sep" aria-hidden="true">·</span>';
    echo '<a href="/vaak/">VAAK login</a>';
    echo '<span class="sep" aria-hidden="true">·</span>';
    echo '<a href="https://mkultra.monster/">mkultra.monster</a>';
    echo '<span class="sep" aria-hidden="true">·</span>';
    echo '<a href="mailto:cmdr-nova@mkultra.monster">Contact</a>';
    echo '</nav></div></body></html>';
}
