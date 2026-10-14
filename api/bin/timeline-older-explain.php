<?php
/**
 * Confirm older-page keyset queries use indexes.
 * Local only: CI has no Postgres. Not part of php-standalone-tests.sh.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/ap-timeline-older.php';

$dsn = getenv('TIMELINE_EXPLAIN_DSN');
$dsn = is_string($dsn) && $dsn !== '' ? $dsn : 'pgsql:host=/var/run/postgresql;dbname=postgres';
$pdo = new PDO($dsn);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->exec('DROP SCHEMA IF EXISTS tl_explain CASCADE');
$pdo->exec('CREATE SCHEMA tl_explain');
$pdo->exec('SET search_path TO tl_explain');
$pdo->exec(<<<'SQL'
CREATE TABLE events (
    id BIGINT PRIMARY KEY,
    created_at TEXT NOT NULL,
    type TEXT NOT NULL,
    actor_id TEXT,
    object_id TEXT,
    summary TEXT,
    media_urls TEXT,
    action_taken TEXT,
    spoiler_text TEXT,
    sensitive BIGINT,
    host TEXT,
    in_reply_to TEXT,
    target_actor TEXT,
    visibility TEXT
);
CREATE INDEX idx_events_timeline_created ON events (created_at DESC, id DESC)
    WHERE type IN ('Create', 'Announce') AND action_taken IN ('log', 'local_observe');
CREATE INDEX idx_events_timeline_actor_created ON events (actor_id, created_at DESC, id DESC)
    WHERE type IN ('Create', 'Announce') AND action_taken IN ('log', 'local_observe');
CREATE INDEX idx_events_type_action_created ON events (type, action_taken, created_at DESC, id DESC);
CREATE INDEX idx_events_actor_type_action_created ON events (actor_id, type, action_taken, created_at DESC, id DESC);

CREATE TABLE bsky_posts (
    bsky_uri TEXT PRIMARY KEY,
    author_did TEXT NOT NULL,
    indexed_at TEXT,
    text TEXT
);
CREATE INDEX idx_bsky_posts_author_idx ON bsky_posts (author_did, indexed_at DESC);

CREATE TABLE outbox_notes (
    id TEXT PRIMARY KEY,
    published TEXT NOT NULL,
    content TEXT,
    in_reply_to TEXT,
    raw_create_json TEXT
);
CREATE INDEX idx_outbox_published ON outbox_notes (published);

CREATE TABLE ap_users (
    id BIGINT PRIMARY KEY
);

CREATE TABLE masto_reblogs (
    id BIGINT PRIMARY KEY,
    owner_user_id BIGINT NOT NULL,
    status_id TEXT NOT NULL,
    created_at TEXT NOT NULL,
    owner_actor_id TEXT,
    object_id TEXT
);
CREATE INDEX idx_masto_reblogs_owner_created ON masto_reblogs (owner_user_id, created_at DESC);
CREATE INDEX idx_masto_reblogs_created ON masto_reblogs (created_at DESC);
SQL);

$event = $pdo->prepare(
    "INSERT INTO events (id, created_at, type, actor_id, object_id, summary, media_urls, action_taken, spoiler_text, sensitive, visibility)
     VALUES (?, ?, ?, ?, ?, 'hello', '[]', 'log', '', 0, 'public')"
);
for ($i = 1; $i <= 12000; $i++) {
    $type = match ($i % 11) {
        0 => 'Quote',
        1 => 'QuotePost',
        2 => 'Announce',
        default => 'Create',
    };
    $actorN = $i % 200;
    $at = gmdate('Y-m-d\TH:i:s\Z', 1700000000 + ($i * 60));
    $event->execute([
        $i,
        $at,
        $type,
        'https://example.test/users/actor' . $actorN,
        'https://example.test/users/actor' . $actorN . '/notes/' . $i,
    ]);
}
$bsky = $pdo->prepare('INSERT INTO bsky_posts (bsky_uri, author_did, indexed_at, text) VALUES (?, ?, ?, ?)');
for ($i = 1; $i <= 10000; $i++) {
    $didN = $i % 50;
    $at = gmdate('Y-m-d\TH:i:s\Z', 1700000000 + ($i * 60));
    $bsky->execute([
        'at://did:plc:author' . $didN . '/app.bsky.feed.post/' . $i,
        'did:plc:author' . $didN,
        $at,
        'post ' . $i,
    ]);
}
$note = $pdo->prepare('INSERT INTO outbox_notes (id, published, content, in_reply_to, raw_create_json) VALUES (?, ?, ?, ?, ?)');
for ($i = 1; $i <= 4000; $i++) {
    $at = gmdate('Y-m-d\TH:i:s\Z', 1700000000 + ($i * 120));
    $note->execute([
        'https://mkultra.monster/users/alice/notes/' . $i,
        $at,
        'note',
        null,
        '{}',
    ]);
}
$pdo->exec('INSERT INTO ap_users (id) SELECT g FROM generate_series(1, 40) g');
$boost = $pdo->prepare(
    'INSERT INTO masto_reblogs (id, owner_user_id, status_id, created_at, owner_actor_id, object_id) VALUES (?, ?, ?, ?, ?, ?)'
);
for ($i = 1; $i <= 4000; $i++) {
    $at = gmdate('Y-m-d\TH:i:s\Z', 1700000000 + ($i * 90));
    $boost->execute([
        $i,
        ($i % 40) + 1,
        (string) (900000 + $i),
        $at,
        'https://mkultra.monster/users/local' . ($i % 40),
        'https://example.test/notes/' . $i,
    ]);
}
$pdo->exec('ANALYZE');

$at = gmdate('Y-m-d\TH:i:s\Z', 1700000000 + (6000 * 60));
$queries = vaak_tl_older_explain_queries(
    'https://example.test/users/actor7',
    'did:plc:author7',
    $at,
    6000
);
$failed = false;
foreach ($queries as $query) {
    $sql = 'EXPLAIN (ANALYZE, BUFFERS) ' . $query['sql'];
    $st = $pdo->prepare($sql);
    $st->execute($query['params']);
    $plan = implode("\n", array_map(static fn(array $row): string => (string) ($row['QUERY PLAN'] ?? ''), $st->fetchAll(PDO::FETCH_ASSOC) ?: []));
    $usesIndex = str_contains($plan, 'Index Scan') || str_contains($plan, 'Bitmap Index Scan') || str_contains($plan, 'Index Only Scan');
    $seqLarge = (bool) preg_match('/Seq Scan on (events|bsky_posts|outbox_notes|masto_reblogs)\b/', $plan);
    echo '== ' . $query['name'] . " ==\n" . $plan . "\n";
    if (!$usesIndex || $seqLarge || !str_contains($plan, 'Index Cond:')) {
        fwrite(STDERR, $query['name'] . " did not seek an index\n");
        $failed = true;
    }
    if ($query['name'] === 'events_federated_create' && preg_match('/Rows Removed by Filter: (\d+)/', $plan, $m) && (int) $m[1] > 200) {
        fwrite(STDERR, "events_federated_create walked " . $m[1] . " newer rows\n");
        $failed = true;
    }
}
$pdo->exec('DROP SCHEMA IF EXISTS tl_explain CASCADE');
if ($failed) {
    exit(1);
}
echo "timeline-older-explain ok\n";
