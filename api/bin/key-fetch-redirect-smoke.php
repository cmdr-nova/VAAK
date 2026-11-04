<?php
/**
 * ap_key_fetch_same_host_redirect: the one redirect hop a key fetch follows.
 * Same cases as same_host_redirect's test in rust/vaak-worker/src/inbox_key_fetch.rs.
 * No database, no Redis, no network.
 */
declare(strict_types=1);

putenv('VAAK_REDIS_ENABLED=0');
define('AP_INBOX_LIB_ONLY', true);
require_once dirname(__DIR__) . '/ap-inbox.php';

$n = 0;
$from = 'https://startrek.website/u/Picard';
$cases = [
    ['https://startrek.website/u/picard', 'https://startrek.website/u/picard'],
    ['/u/picard', 'https://startrek.website/u/picard'],
    ['https://STARTREK.website/u/picard#main-key', 'https://startrek.website/u/picard'],
    ['https://evil.test/u/picard', null],
    ['//evil.test/u/picard', null],
    ['http://startrek.website/u/picard', null],
    ['https://startrek.website:8443/u/picard', null],
    ['https://user@startrek.website/u/picard', null],
    [$from, null],
    [null, null],
    ['', null],
];
foreach ($cases as $i => [$location, $want]) {
    $got = ap_key_fetch_same_host_redirect($from, $location);
    if ($got !== $want) {
        fwrite(STDERR, "key-fetch-redirect: case {$i} got " . var_export($got, true) . "\n");
        exit(1);
    }
    $n++;
}
echo "key-fetch-redirect: {$n} checks ok\n";
