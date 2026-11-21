<?php
declare(strict_types=1);
/**
 * Own Bluesky posts imported as local notes keep their richtext facets:
 * shortened link labels link to the facet URI, mentions and tags link, and
 * the repair rewrites only vaakOrigin=bluesky notes. SQLite in memory; no network.
 */
$api = getenv('VAAK_BSKY_FACETS_API') ?: dirname(__DIR__);
require __DIR__ . '/migration-reference-functions.php';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->exec('CREATE TABLE outbox_notes (id TEXT PRIMARY KEY, content TEXT, raw_create_json TEXT, create_id TEXT)');
function ap_db(): PDO { return $GLOBALS['pdo']; }
function ap_masto_normalize_tag_name(string $n): string { return mb_strtolower(ltrim($n, '#')); }
function ap_masto_tag_url(string $n): string { return 'https://mkultra.monster/tags/' . rawurlencode($n); }
reference_load($api . '/ap-db.php', ['ap_fix_utf8', 'ap_plain_text_to_html']);
reference_load($api . '/ap-bsky.php', ['ap_bsky_record_text_html', 'ap_bsky_import_content_html', 'ap_bsky_repair_imported_note_content']);

$checks = 0;
function facets_check(bool $ok, string $label): void
{
    global $checks;
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
    $checks++;
}
function facet(string $text, string $needle, array $feature): array
{
    $at = strpos($text, $needle);
    return ['index' => ['byteStart' => $at, 'byteEnd' => $at + strlen($needle)], 'features' => [$feature]];
}

$text = "héllo 🎉 @val.bsky.social read example.com/a-very-lon... #Rust #842\nnext\n\nhttps://plain.example/x.";
$facets = [
    facet($text, '@val.bsky.social', ['$type' => 'app.bsky.richtext.facet#mention', 'did' => 'did:plc:abc123']),
    facet($text, 'example.com/a-very-lon...', ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/a-very-long-path?a=1&b=2']),
    facet($text, '#Rust', ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => 'Rust']),
    facet($text, '#842', ['$type' => 'app.bsky.richtext.facet#tag', 'tag' => '842']),
    ['index' => ['byteStart' => 0, 'byteEnd' => 2], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'javascript:alert(1)']]],
    ['index' => ['byteStart' => strpos($text, '🎉') + 1, 'byteEnd' => strpos($text, '🎉') + 3], 'features' => [['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://split.example/']]],
];
$html = ap_bsky_record_text_html($text, $facets);
facets_check(str_contains($html, '<a href="https://example.com/a-very-long-path?a=1&amp;b=2" target="_blank" rel="nofollow noopener noreferrer" data-vaak-facet="link">example.com/a-very-lon...</a>'), 'shortened link label links to facet URI');
facets_check(str_contains($html, '<span class="h-card"><a href="https://bsky.app/profile/did:plc:abc123" class="u-url mention" data-vaak-facet="mention">@<span>val.bsky.social</span></a></span>'), 'mention gets h-card markup');
facets_check(str_contains($html, '<a href="https://mkultra.monster/tags/rust" class="mention hashtag" rel="tag" data-vaak-facet="tag">#<span>Rust</span></a>'), 'tag links to local tag page');
facets_check(!str_contains($html, '/tags/842'), 'digit-only tag stays text');
facets_check(!str_contains($html, 'javascript:') && !str_contains($html, 'split.example'), 'non-http and mid-character facets are dropped');
facets_check(str_contains($html, '<a href="https://plain.example/x" target="_blank" rel="nofollow noopener noreferrer">https://plain.example/x</a>.'), 'bare URL outside facets still links');
facets_check(str_contains($html, '<br>next</p>' . "\n" . '<p>'), 'line and paragraph breaks kept');
facets_check(str_starts_with($html, '<p>héllo 🎉 '), 'multibyte text before facets intact');

$lead = "  hi example.com/x...";
$leadFacet = facet($lead, 'example.com/x...', ['$type' => 'app.bsky.richtext.facet#link', 'uri' => 'https://example.com/xyz']);
facets_check(str_contains(ap_bsky_record_text_html($lead, [$leadFacet]), '>example.com/x...</a>'), 'leading whitespace keeps byte offsets aligned');
facets_check(ap_bsky_record_text_html('plain words', []) === '<p>plain words</p>', 'no facets falls back to plain HTML');
facets_check(ap_bsky_record_text_html('', $facets) === '', 'empty text stays empty');

$pdo = $GLOBALS['pdo'];
$ins = $pdo->prepare('INSERT INTO outbox_notes VALUES (?, ?, ?, ?)');
$imported = 'https://mkultra.monster/users/example/notes/imported';
$vaak = 'https://mkultra.monster/users/example/notes/vaak';
$ins->execute([$imported, '<p>old</p>', json_encode(['object' => ['vaakOrigin' => 'bluesky', 'content' => '<p>old</p>']]), $imported . '/activity']);
$ins->execute([$vaak, '<p>mine</p>', json_encode(['object' => ['content' => '<p>mine</p>']]), $vaak . '/activity']);
$post = ['record' => ['text' => $text, 'facets' => $facets]];

$dry = ap_bsky_repair_imported_note_content($imported, $post, true);
facets_check(!empty($dry['updated']) && $pdo->query("SELECT content FROM outbox_notes WHERE id = '{$imported}'")->fetchColumn() === '<p>old</p>', 'dry run reports without writing');
$r = ap_bsky_repair_imported_note_content($imported, $post);
$row = $pdo->query("SELECT content, raw_create_json FROM outbox_notes WHERE id = '{$imported}'")->fetch(PDO::FETCH_ASSOC);
facets_check(!empty($r['updated']) && $row['content'] === $html, 'repair rewrites imported content');
facets_check(json_decode($row['raw_create_json'], true)['object']['content'] === $html, 'repair updates the stored Create object');
facets_check(empty(ap_bsky_repair_imported_note_content($imported, $post)['updated']), 'second repair is a no-op');
facets_check(empty(ap_bsky_repair_imported_note_content($vaak, $post)['updated']), 'VAAK-authored notes are never rewritten');
facets_check(empty(ap_bsky_repair_imported_note_content($imported, ['record' => ['text' => 'thin']])['updated']), 'facet-less (thin) posts never downgrade content');

$entities = (string) file_get_contents($api . '/ap-masto-entities.php');
facets_check(str_contains($entities, "str_contains(\$html, 'data-vaak-facet=')"), 'PHP status builder keeps facet HTML');
foreach (['profile_materialize.rs' => 'content.contains("data-vaak-facet=")', 'notif_entity.rs' => 'html.contains("data-vaak-facet=")'] as $file => $needle) {
    facets_check(str_contains((string) file_get_contents(dirname($api) . '/rust/vaak-worker/src/' . $file), $needle), $file . ' keeps facet HTML');
}

fwrite(STDOUT, "bsky-import-facets-smoke: {$checks} checks passed\n");
