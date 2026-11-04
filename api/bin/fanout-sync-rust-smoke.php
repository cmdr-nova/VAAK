<?php
/**
 * Cutover D3 smoke (no network, no database).
 *
 * With the Rust worker's sync heartbeat (ap_fanout_rust_sync_active()),
 * ap_deliver_public_activity() and ap_deliver_followers_activity() must send
 * nothing inline: Bridgy and the first followers are queued at priority 5,
 * the rest as before. Without it, or when the queue is down, they send inline
 * exactly as they did.
 *
 * Usage: php api/bin/fanout-sync-rust-smoke.php
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
$queued = [];   // list of [inboxes, priority]
$queueOk = true;
$rustSync = false;
$followers = [];

function ap_fanout_rust_sync_active(): bool { return $GLOBALS['rustSync']; }
function ap_request_actor_get() { return null; }
function ap_request_actor_set($k): void {}
function ap_local_actor_id(): string { return 'https://mkultra.monster/users/cmdr_nova'; }
function ap_outbound_identity(): array { return ['key_id' => 'k', 'priv' => 'p']; }
function ap_followers_list($owner): array { return $GLOBALS['followers']; }
function ap_bridgy_priority_inboxes(): array { return ['https://bsky.brid.gy/inbox', 'https://fed.brid.gy/inbox']; }
function ap_is_blocked_inbox(string $u): bool { return str_contains($u, 'blocked.'); }
function ap_delivery_targets_filtered(array $followers, array $extra): array {
    return array_values(array_unique(array_merge($extra, $followers)));
}
function ap_known_shared_inboxes(int $n): array { return ['https://shared.example/inbox']; }
function ap_relays_accepted_inboxes(): array { return ['https://relay.example/inbox']; }
function ap_wafrn_friend_inboxes(int $n): array { return []; }
function ap_log(string $l): void {}
function ap_fanout_deliver_or_hold(string $inbox, array $a, string $k, string $p, float $t): bool {
    $GLOBALS['inline'][] = $inbox;
    return true;
}
function ap_deliver_fanout_background(array $activity, array $inboxes, string $keyId, string $priv, ?int $priority = null): bool {
    if (!$GLOBALS['queueOk']) return false;
    $GLOBALS['queued'][] = [array_values($inboxes), $priority];
    return true;
}
eval(declaration($source, 'ap_deliver_public_activity'));
eval(declaration($source, 'ap_deliver_followers_activity'));

$fails = 0;
$check = static function (bool $ok, string $label) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
};
$reset = static function (): void { $GLOBALS['inline'] = []; $GLOBALS['queued'] = []; };
$followers = array_map(static fn($i) => "https://f$i.example/inbox", range(1, 20));
$create = ['id' => 'https://mkultra.monster/users/cmdr_nova/statuses/1', 'type' => 'Create',
    'actor' => 'https://mkultra.monster/users/cmdr_nova', 'object' => ['type' => 'Note']];
$replyTarget = 'https://parent.example/users/p/inbox';
$allQueued = static fn(): array => array_merge(...array_map(static fn($q) => $q[0], $GLOBALS['queued']));

// Before D3: Bridgy (first 2xx only) and 6 followers inline.
$rustSync = false; $reset();
$r = ap_deliver_public_activity($create, [$replyTarget]);
$check($inline[0] === 'https://bsky.brid.gy/inbox' && count($inline) === 7, 'off: Bridgy + 6 sent inline');
$check($r['bridgy'] === true && $r['delivered'] === 7, 'off: counts unchanged');

// D3 on: nothing inline; urgent batch first at priority 5.
$rustSync = true; $reset();
$r = ap_deliver_public_activity($create, [$replyTarget]);
$check($inline === [], 'on: nothing sent inline');
$check($queued[0][1] === 5 && $queued[0][0][0] === 'https://bsky.brid.gy/inbox', 'on: Bridgy queued first at priority 5');
$check(in_array($replyTarget, $queued[0][0], true), 'on: reply target is in the urgent batch');
$check(count($queued[0][0]) === 7, 'on: urgent batch is one Bridgy inbox + 6 followers');
$check(!in_array('https://fed.brid.gy/inbox', $allQueued(), true), 'on: second Bridgy inbox not queued (no double copy)');
$check(isset($queued[1]) && $queued[1][1] === null, 'on: the rest keeps its normal priority');
$every = $allQueued();
$check(count($every) === count(array_unique($every)), 'on: no inbox queued twice');
foreach (array_merge($followers, [$replyTarget, 'https://shared.example/inbox', 'https://relay.example/inbox']) as $u) {
    if (!in_array($u, $every, true)) { $check(false, "on: $u queued"); }
}
$check($r['delivered'] === 0 && $r['queued'] === count($every), 'on: counts report queued, not delivered');

// D3 on, skipBridgy: no Bridgy rows.
$reset();
ap_deliver_public_activity($create, [], true);
$check(!array_filter($allQueued(), static fn($u) => str_contains($u, 'brid.gy')), 'on + skipBridgy: no Bridgy row');

// D3 on but queue down: falls back to sending inline.
$queueOk = false; $reset();
$r = ap_deliver_public_activity($create, [$replyTarget]);
$check($inline[0] === 'https://bsky.brid.gy/inbox' && $r['bridgy'] === true, 'on, queue down: Bridgy sent inline');
$check(count($inline) >= 7, 'on, queue down: followers sent inline');
$queueOk = true;

// Followers-only.
$rustSync = false; $reset();
$r = ap_deliver_followers_activity($create, [$replyTarget]);
$check(count($inline) === 8 && $r['delivered'] === 8, 'followers off: 8 inline');
$rustSync = true; $reset();
$r = ap_deliver_followers_activity($create, [$replyTarget]);
$check($inline === [] && $queued[0][1] === 5 && count($queued[0][0]) === 8, 'followers on: first 8 queued at priority 5');
$check(!array_filter($allQueued(), static fn($u) => str_contains($u, 'brid.gy') || str_contains($u, 'shared.')), 'followers on: no Bridgy or shared-inbox spray');
$check($r['delivered'] === 0 && $r['queued'] === 21, 'followers on: all 21 counted as queued');
$queueOk = false; $reset();
ap_deliver_followers_activity($create, [$replyTarget]);
$check(count($inline) >= 8, 'followers on, queue down: sent inline');

echo $fails === 0 ? "PASS\n" : "$fails failure(s)\n";
exit($fails === 0 ? 0 : 1);
