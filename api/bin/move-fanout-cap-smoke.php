<?php
/**
 * Profile Update / Move fan-out smoke (no network, no database).
 *
 * ap_deliver_inline_then_queue() must send the first 50 inboxes inline and
 * hand every remaining inbox to the durable queue. Profile Updates and Moves
 * used to stop at 50 and silently drop the rest.
 *
 * Usage: php api/bin/move-fanout-cap-smoke.php
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

$source = (string) file_get_contents(dirname(__DIR__) . '/ap-inbox.php');
function declaration(string $source, string $name): string {
    $start = strpos($source, 'function ' . $name . '(');
    if ($start === false) throw new RuntimeException('missing ' . $name);
    $depth = 0; $opened = false; $out = '';
    foreach (array_slice(token_get_all('<?php ' . substr($source, $start)), 1) as $t) {
        $out .= is_array($t) ? $t[1] : $t;
        if (is_array($t) && in_array($t[0], [T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES], true)) $depth++;
        if ($t === '{') { $depth++; $opened = true; }
        if ($t === '}') $depth--;
        if ($opened && $depth === 0) break;
    }
    return $out;
}

$inline = [];
$queued = [];
$queueOk = true;
function ap_deliver_signed_json(string $inbox, array $activity, string $keyId, string $priv): bool {
    $GLOBALS['inline'][] = $inbox;
    return !str_contains($inbox, 'down.');
}
function ap_deliver_fanout_background(array $activity, array $inboxes, string $keyId, string $priv): bool {
    $GLOBALS['queued'] = array_merge($GLOBALS['queued'], $inboxes);
    return $GLOBALS['queueOk'];
}
eval(declaration($source, 'ap_deliver_inline_then_queue'));

$fails = 0;
$check = static function (bool $ok, string $label) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
};
$targets = static fn(int $n): array => array_map(static fn($i) => "https://h$i.example/inbox", range(1, $n));
$move = ['id' => 'https://mkultra.monster/users/a/moves/1', 'type' => 'Move'];

$r = ap_deliver_inline_then_queue($move, $targets(30), 'k', 'p');
$check($r === ['delivered' => 30, 'queued' => 0] && count($inline) === 30 && $queued === [], 'under the cap: all inline, nothing queued');

$inline = []; $queued = [];
$all = $targets(180);
$r = ap_deliver_inline_then_queue($move, $all, 'k', 'p');
$check(count($inline) === 50 && $inline === array_slice($all, 0, 50), 'first 50 inboxes go inline');
$check($queued === array_slice($all, 50) && $r['queued'] === 130, 'remaining 130 inboxes are queued, none dropped');
$check($r['delivered'] === 50, 'delivered counts inline successes');

$inline = []; $queued = [];
$r = ap_deliver_inline_then_queue($move, ['https://down.example/inbox', 'https://up.example/inbox'], 'k', 'p');
$check($r === ['delivered' => 1, 'queued' => 0], 'failed inline delivery is not counted');

$inline = []; $queued = []; $queueOk = false;
$r = ap_deliver_inline_then_queue($move, $targets(60), 'k', 'p');
$check($r['queued'] === 0 && count($queued) === 10, 'queue failure reports 0 queued');

echo $fails === 0 ? "PASS\n" : "$fails failure(s)\n";
exit($fails === 0 ? 0 : 1);
