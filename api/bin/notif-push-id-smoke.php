<?php
declare(strict_types=1);
/**
 * Push notification ids must resolve to the event that was pushed, or to
 * nothing. Ice Cubes fetches GET /api/v1/notifications/{notification_id} for
 * every push and paints the sender and type from that answer, so a made-up id
 * (time(), or a bare direct_messages row id) borrowed an unrelated like or
 * boost: "ruby boosted" over the defederation monitor DM text.
 * No Postgres, Redis, or network.
 */
$api = dirname(__DIR__);
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
};
$check = static function (bool $ok, string $label) use ($fail): void {
    if (!$ok) {
        $fail($label);
    }
};

reference_load($api . '/ap-masto-entities.php', [
    'ap_masto_snowflake_id',
    'ap_masto_parse_notification_id',
    'ap_masto_notification_id_for_mention',
    'ap_masto_notification_id_for_dm',
    'ap_masto_notification_id_unresolvable',
    'ap_masto_notification_id_matches_row',
]);

$created = '2026-11-03T13:15:07+00:00';

// DM push id resolves to the DM, never to a mention row.
$dm = ap_masto_parse_notification_id((int) ap_masto_notification_id_for_dm(523, $created));
$check(is_array($dm) && $dm['kind'] === 'dm' && $dm['db_id'] === 523, 'DM push id parses as the DM');
$check(ap_masto_notification_id_matches_row($dm, ['created_at' => $created]), 'DM id matches its own row');
$check(!ap_masto_notification_id_matches_row($dm, ['created_at' => '2026-10-01T00:00:00+00:00']), 'DM id does not match another row');

// The old DM push sent the bare row id: that is a legacy mention id.
$legacy = ap_masto_parse_notification_id(523);
$check(is_array($legacy) && $legacy['kind'] === 'mention', 'bare row id is a mention id (why DMs must not send it)');

// The old fallback sent time(); some seconds land on a mention row.
$borrowed = ap_masto_parse_notification_id(1762155123);
$check(is_array($borrowed) && $borrowed['kind'] === 'mention', 'a time() id can parse as a mention (old bug)');

// The new fallback never resolves.
for ($i = 0; $i < 200; $i++) {
    $none = ap_masto_parse_notification_id((int) ap_masto_notification_id_unresolvable($created));
    $check($none === null, 'unresolvable push id parses to nothing');
}

// A v2 mention id only matches the row it was minted from.
$m = ap_masto_parse_notification_id((int) ap_masto_notification_id_for_mention(1916, $created));
$check(is_array($m) && $m['kind'] === 'mention' && $m['db_id'] === 1916, 'mention id round trip');
$check(ap_masto_notification_id_matches_row($m, ['created_at' => '2026-11-03 13:15:07+00']), 'Postgres timestamp text matches');
$check(!ap_masto_notification_id_matches_row($m, ['created_at' => '2026-11-02 09:00:00+00']), 'mention id rejects a different row');
$check(!ap_masto_notification_id_matches_row($m, ['created_at' => '']), 'mention id rejects a row with no time');

// Legacy ids keep resolving as before (cached client ids).
$check(ap_masto_notification_id_matches_row(['kind' => 'mention', 'db_id' => 5, 'ver' => 0], []), 'legacy ids skip the time check');

echo "OK notif-push-id-smoke\n";
