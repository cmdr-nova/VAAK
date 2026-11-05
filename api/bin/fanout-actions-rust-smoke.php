<?php
/**
 * Cutover A1 smoke (no network, no database).
 *
 * With the Rust worker's actions heartbeat (ap_fanout_rust_actions_active()),
 * ap_cmdr_send_like(), ap_cmdr_send_undo_like() and
 * ap_cmdr_send_undo_announce() must send nothing themselves: their inboxes go
 * to the fan-out queue at priority 3 (an unboost's followers keep bulk
 * priority). Without it, or when the queue is down, they send inline as
 * before.
 *
 * Usage: php api/bin/fanout-actions-rust-smoke.php
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
$rustActions = false;
$followers = [];
$metrics = [];

const ME = 'https://mkultra.monster/users/cmdr_nova';
function ap_fanout_rust_actions_active(): bool { return $GLOBALS['rustActions']; }
function ap_outbound_identity(): array { return ['id' => ME, 'key_id' => ME . '#main-key', 'priv' => 'p']; }
function ap_is_blocked_actor(string $a): bool { return false; }
function ap_actor_delivery_inboxes(string $a): array { return [$a . '/inbox', 'https://remote.example/inbox']; }
function ap_followers_list($owner): array { return $GLOBALS['followers']; }
function ap_bridgy_priority_inboxes(): array { return ['https://bsky.brid.gy/inbox']; }
function ap_delivery_targets_filtered(array $followers, array $extra): array {
    return array_values(array_unique(array_merge($extra, $followers)));
}
function ap_as2_ensure_top_context(array $a): array { return $a; }
function ap_short(string $s, int $n = 120): string { return $s; }
function ap_log(string $l): void {}
function ap_metrics_record(string $type, ...$rest): void { $GLOBALS['metrics'][] = [$type, $rest[4] ?? '']; }
function ap_deliver_signed_json(string $inbox, array $a, string $k, string $p, float $t = 2.5): bool {
    $GLOBALS['inline'][] = $inbox;
    return true;
}
function ap_deliver_fanout_background(array $activity, array $inboxes, string $keyId, string $priv, ?int $priority = null): bool {
    if (!$GLOBALS['queueOk']) return false;
    $GLOBALS['queued'][] = [array_values($inboxes), $priority];
    return true;
}
eval(declaration($source, 'ap_deliver_action_via_rust'));
eval(declaration($source, 'ap_cmdr_send_like'));
eval(declaration($source, 'ap_cmdr_send_undo_like'));
eval(declaration($source, 'ap_cmdr_send_undo_announce'));

$fails = 0;
$check = static function (bool $ok, string $label) use (&$fails): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $label . "\n";
    if (!$ok) $fails++;
};
$reset = static function (): void { $GLOBALS['inline'] = []; $GLOBALS['queued'] = []; $GLOBALS['metrics'] = []; };
$author = 'https://remote.example/users/amy';
$note = $author . '/statuses/9';
$followers = array_map(static fn($i) => "https://f$i.example/inbox", range(1, 12));
$likeId = ME . '/likes/queue-1-1';

// Like
$rustActions = false; $reset();
$r = ap_cmdr_send_like($note, $author, $likeId);
$check(count($inline) === 2 && $queued === [] && $r['ok'] && $r['delivered'] === 2, 'like off: both inboxes sent inline');
$rustActions = true; $reset();
$r = ap_cmdr_send_like($note, $author, $likeId);
$check($inline === [], 'like on: nothing sent inline');
$check(count($queued) === 1 && $queued[0][1] === 3 && count($queued[0][0]) === 2, 'like on: both inboxes queued at priority 3');
$check($r['ok'] === true && $r['delivered'] === 2 && $r['like_id'] === $likeId, 'like on: reports ok with the stable id');
$check(($metrics[0][1] ?? '') === 'like_ok', 'like on: metrics still record like_ok');
$queueOk = false; $reset();
$r = ap_cmdr_send_like($note, $author, $likeId);
$check(count($inline) === 2 && $r['ok'], 'like on, queue down: sent inline');
$queueOk = true;
$reset();
$r = ap_cmdr_send_like(ME . '/statuses/1', ME, $likeId);
$check($inline === [] && $queued === [] && $r['delivered'] === 0, 'like of an own note: nothing sent or queued');

// Undo Like
$rustActions = false; $reset();
ap_cmdr_send_undo_like($likeId, $note, $author, ME . '/undos/queue-like-1-2');
$check(count($inline) === 2 && $queued === [], 'unlike off: sent inline');
$rustActions = true; $reset();
$r = ap_cmdr_send_undo_like($likeId, $note, $author, ME . '/undos/queue-like-1-2');
$check($inline === [] && ($queued[0][1] ?? null) === 3 && $r['ok'], 'unlike on: queued at priority 3, ok');

// Undo Announce
$rustActions = false; $reset();
ap_cmdr_send_undo_announce(ME . '/announces/queue-2-1', $note, $author, ME . '/undos/queue-boost-2-2');
$check(count($inline) === 15 && $queued === [], 'unboost off: Bridgy + author + 12 followers inline');
$rustActions = true; $reset();
$r = ap_cmdr_send_undo_announce(ME . '/announces/queue-2-1', $note, $author, ME . '/undos/queue-boost-2-2');
$check($inline === [], 'unboost on: nothing sent inline');
$check(($queued[0][1] ?? null) === 3 && count($queued[0][0]) === 3
    && in_array('https://bsky.brid.gy/inbox', $queued[0][0], true), 'unboost on: Bridgy + author inboxes first at priority 3');
$check(array_key_exists(1, $queued) && $queued[1][1] === null && count($queued[1][0]) === 12, 'unboost on: followers keep bulk priority');
$check($r['ok'] && $r['delivered'] === 15, 'unboost on: all 15 counted');
$queueOk = false; $reset();
ap_cmdr_send_undo_announce(ME . '/announces/queue-2-1', $note, $author, ME . '/undos/queue-boost-2-2');
$check(count($inline) === 15, 'unboost on, queue down: sent inline');
$queueOk = true;

echo $fails === 0 ? "PASS\n" : "$fails failure(s)\n";
exit($fails === 0 ? 0 : 1);
