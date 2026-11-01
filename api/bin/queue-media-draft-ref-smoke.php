<?php
declare(strict_types=1);
/**
 * Cancelling a queued post scrubs its media only when nothing else uses it.
 * ap_queue_media_still_referenced must count drafts (they were ignored) and
 * compare ids exactly (media 12 is not kept alive by a row listing 123).
 * Also: the follow-import CLIs read the per-owner state file, falling back to
 * the legacy shared file only when the owner's file does not exist yet.
 * SQLite in memory, no network.
 */
$api = dirname(__DIR__);
require __DIR__ . '/migration-reference-functions.php';

$GLOBALS['pdo'] = new PDO('sqlite::memory:');
$GLOBALS['pdo']->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$GLOBALS['pdo']->exec("CREATE TABLE ap_post_queue (id INTEGER PRIMARY KEY, state TEXT, media_ids_json TEXT)");
$GLOBALS['pdo']->exec("CREATE TABLE ap_drafts (id INTEGER PRIMARY KEY, media_ids_json TEXT NOT NULL DEFAULT '[]')");
$GLOBALS['pdo']->exec("INSERT INTO ap_post_queue (state, media_ids_json) VALUES ('pending', '[123]'), ('cancelled', '[7]'), ('failed', '[\"40\"]')");
$GLOBALS['pdo']->exec("INSERT INTO ap_drafts (media_ids_json) VALUES ('[12, 55]')");
function ap_db(): PDO { return $GLOBALS['pdo']; }

reference_load($api . '/ap-queue.php', ['ap_queue_media_still_referenced']);

$failures = [];
$check = static function (bool $ok, string $label) use (&$failures): void {
    if (!$ok) {
        $failures[] = $label;
    }
};
$check(ap_queue_media_still_referenced(12), 'media used by a draft is kept');
$check(ap_queue_media_still_referenced(55), 'second media in a draft is kept');
$check(ap_queue_media_still_referenced(123), 'media used by a pending queue item is kept');
$check(ap_queue_media_still_referenced(40), 'string ids in a failed item count');
$check(!ap_queue_media_still_referenced(1), 'media 1 is not kept alive by 12/123 substrings');
$check(!ap_queue_media_still_referenced(7), 'a cancelled item does not keep media');
$check(!ap_queue_media_still_referenced(0), 'non-ids are never referenced');

$GLOBALS['pdo']->exec('DROP TABLE ap_drafts');
$check(ap_queue_media_still_referenced(99), 'a failed check keeps the media rather than deleting it');

// Follow-import state path: per-owner first, legacy only as a read fallback.
$src = (string) file_get_contents($api . '/ap-import-export.php');
$check(str_contains($src, 'function ap_ie_follow_state_read_path'), 'read-path helper exists');
foreach (['ap-follow-import.php', 'ap-follow-import-continue.php'] as $cli) {
    $code = (string) file_get_contents($api . '/' . $cli);
    $check(str_contains($code, "define('AP_FOLLOW_IMPORT_STATE', ap_ie_follow_state_path());"), "$cli writes the per-owner file");
    $check(!str_contains($code, "const AP_FOLLOW_IMPORT_STATE = '/var/lib/mkultra/ap/follow-import-state.json'"), "$cli no longer hardcodes the shared file");
    $check(str_contains($code, 'file_get_contents(AP_FOLLOW_IMPORT_STATE_READ)'), "$cli reads through the fallback path");
}
$check(str_contains($src, "gmdate('Ymd_His') . '_' . bin2hex(random_bytes(3))"), 'audit CSV names are unique within a second');

if ($failures) {
    fwrite(STDERR, "queue-media-draft-ref-smoke: FAIL\n - " . implode("\n - ", $failures) . "\n");
    exit(1);
}
echo "queue-media-draft-ref-smoke: ok\n";
