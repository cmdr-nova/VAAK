<?php
declare(strict_types=1);
/** A Jetstream event that fails to ingest must not hold up the rest of the spool. */
$tmp = sys_get_temp_dir() . '/vaak-jetstream-worker-' . getmypid();
$api = $tmp . '/api';
$state = $tmp . '/state';
@mkdir($api, 0700, true);
@mkdir($state . '/incoming', 0700, true);
copy(dirname(__DIR__) . '/ap-bsky-jetstream-worker.php', $api . '/ap-bsky-jetstream-worker.php');
file_put_contents($api . '/ap-db.php', '<?php');
file_put_contents($api . '/ap-auth.php', '<?php');
// Stub ingest: "bad" throws, "skip" is ignored, everything else is stored.
file_put_contents($api . '/ap-bsky.php', '<?php
function ap_bsky_jetstream_ingest_event(array $e): array {
    if (($e["did"] ?? "") === "did:plc:bad") throw new RuntimeException("boom");
    if (($e["did"] ?? "") === "did:plc:skip") return ["ok" => false, "ignored" => true];
    file_put_contents(getenv("VAAK_JETSTREAM_STATE") . "/stored", $e["did"] . "\n", FILE_APPEND);
    return ["ok" => true];
}');
$line = static fn(string $did): string => json_encode(['kind' => 'commit', 'did' => $did]) . "\n";
file_put_contents($state . '/incoming/a.jsonl', $line('did:plc:one') . $line('did:plc:bad') . $line('did:plc:two'));
file_put_contents($state . '/incoming/b.jsonl', $line('did:plc:skip') . $line('did:plc:three'));
$checks = 0;
$check = static function (bool $ok, string $label) use (&$checks): void {
    if (!$ok) { fwrite(STDERR, "jetstream-worker-smoke FAIL: $label\n"); exit(1); }
    $checks++;
};
$cmd = 'VAAK_JETSTREAM_STATE=' . escapeshellarg($state) . ' ' . escapeshellarg(PHP_BINARY) . ' '
    . escapeshellarg($api . '/ap-bsky-jetstream-worker.php') . ' 2>/dev/null';
exec($cmd, $out, $code);
$stored = file($state . '/stored', FILE_IGNORE_NEW_LINES) ?: [];
$check($stored === ['did:plc:one', 'did:plc:two', 'did:plc:three'], 'events after the bad one and in later segments are stored');
$check(glob($state . '/incoming/*.jsonl') === [] && glob($state . '/processing/*.jsonl') === [], 'no segment is put back');
$check(trim((string) @file_get_contents($state . '/failed/a.jsonl')) === trim($line('did:plc:bad')), 'the bad event is kept in failed/');
$check(str_contains(implode("\n", $out), 'processed=3 ignored=1 failed=1') && $code === 1, 'summary and exit code');
exec('rm -rf ' . escapeshellarg($tmp));
echo "jetstream-worker-smoke ok checks=$checks\n";
