<?php
// Quote target URL with reply text glued on (old flattened notification rows):
// "RE: https://host/users/me/notes/abc*hoo !*" must resolve to the note, not
// ".../notes/abc*hoo". No database needed.
declare(strict_types=1);
require dirname(__DIR__) . '/ap-masto-entities.php';

$cases = [
    "RE: https://mkultra.monster/users/cmdr_nova/notes/22a7c4d49c6353d4*hoo !*"
        => 'https://mkultra.monster/users/cmdr_nova/notes/22a7c4d49c6353d4',
    "RE: https://mkultra.monster/users/cmdr_nova/notes/22a7c4d49c6353d4\n\n*hoo !*"
        => 'https://mkultra.monster/users/cmdr_nova/notes/22a7c4d49c6353d4',
    "RE: https://social.example/@bob/123456789?x=1"
        => 'https://social.example/@bob/123456789?x=1',
    "RE: https://social.example/users/bob/statuses/42/activity"
        => 'https://social.example/users/bob/statuses/42/activity',
    '<p class="quote-inline">RE: <a href="https://social.example/users/bob/statuses/42"><span class="invisible">https://</span>social.example/users/bob/statuses/42</a></p><p>hi</p>'
        => 'https://social.example/users/bob/statuses/42',
];
foreach ($cases as $summary => $want) {
    $got = ap_masto_quote_url_from_event_summary($summary);
    if ($got !== $want) {
        throw new RuntimeException('quote url for ' . json_encode($summary) . ': got ' . var_export($got, true) . ", want $want");
    }
}
echo "quote-url-glued-text-smoke: ok\n";
