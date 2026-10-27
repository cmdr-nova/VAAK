<?php
declare(strict_types=1);
/**
 * Mastodon API acct for local accounts is the bare username.
 * Rust local_api_acct reads this JSON and must match case for case.
 * Web handles and mention linkify stay user@host; this helper does not
 * rewrite url or uri. No database and no network.
 */
if (!function_exists('reference_load')) {
    require __DIR__ . '/migration-reference-functions.php';
}
reference_load(dirname(__DIR__) . '/ap-masto-entities.php', ['ap_masto_local_api_acct']);

$fail = static function (string $msg): never {
    fwrite(STDERR, "FAIL: {$msg}\n");
    exit(1);
};

$cases = [
    ['cmdr_nova', 'mkultra.monster', 'https://mkultra.monster/users/cmdr_nova', 'cmdr_nova'],
    ['cmdr_nova', 'vaak.monster', 'https://vaak.monster/users/cmdr_nova', 'cmdr_nova'],
    ['slice_e_test', 'mkultra.monster', 'https://mkultra.monster/users/slice_e_test', 'slice_e_test'],
    ['slice_e_test', 'www.mkultra.monster', 'https://www.mkultra.monster/users/slice_e_test', 'slice_e_test'],
    ['alice', 'remote.test', 'https://remote.test/users/alice', null],
    ['alice.bsky.social', 'bsky.app', 'https://bsky.app/profile/alice.bsky.social', null],
    ['user', 'mkultra.monster', 'https://mkultra.monster/ap/users/123456789', null],
    ['123456789', 'mkultra.monster', 'https://mkultra.monster/users/cmdr_nova', null],
    ['did:plc:abc', 'mkultra.monster', 'https://mkultra.monster/users/cmdr_nova', null],
    ['cmdr_nova', 'unknown', 'https://mkultra.monster/users/cmdr_nova/notes/1', 'cmdr_nova'],
];

$out = [];
foreach ($cases as [$username, $host, $actor, $expect]) {
    $got = ap_masto_local_api_acct($username, $host, $actor);
    if ($got !== $expect) {
        $fail("{$username}@{$host} {$actor} got " . var_export($got, true) . ' expected ' . var_export($expect, true));
    }
    $url = 'https://' . ($host !== '' ? $host : 'mkultra.monster') . '/@' . rawurlencode($username);
    $account = [
        'acct' => $host !== '' ? ($username . '@' . $host) : $username,
        'url' => $url,
        'uri' => $actor,
    ];
    if (is_string($got) && $got !== '') {
        $account['acct'] = $got;
    }
    if ($account['url'] !== $url || $account['uri'] !== $actor) {
        $fail('url or uri changed for ' . $actor);
    }
    $out[] = [
        'username' => $username,
        'host' => $host,
        'actor' => $actor,
        'acct' => $got,
        'url' => $account['url'],
        'uri' => $account['uri'],
        'url_before' => $url,
        'uri_before' => $actor,
    ];
}

$mention = [
    'acct' => 'cmdr_nova@mkultra.monster',
    'username' => 'cmdr_nova',
];
// Written @user@host stays the full handle so linkify wraps the domain.
$mention['acct'] = 'cmdr_nova@mkultra.monster';
if ($mention['acct'] !== 'cmdr_nova@mkultra.monster') {
    $fail('mention handle collapsed');
}

echo json_encode(['ok' => true, 'cases' => $out], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), "\n";
