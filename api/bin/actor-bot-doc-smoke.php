<?php
declare(strict_types=1);
/** ap_actor_doc_is_bot: same rule as Rust home_hydrate_ranked::actor_doc_is_bot. */
require dirname(__DIR__) . '/ap-masto-entities.php';
$cases = [
    ['{"type":"Service"}', true],
    ['{"type":"application"}', true],
    ['{"type":["Person","Application"]}', true],
    ['{"type":"Person","isBot":true}', true],
    ['{"type":"Person","isBot":"yes"}', false],
    ['{"type":"Person"}', false],
    ['{"type":"Group"}', false],
    ['', false],
    ['not json', false],
    [null, false],
];
foreach ($cases as [$doc, $want]) {
    if (ap_actor_doc_is_bot($doc) !== $want) {
        fwrite(STDERR, 'actor-bot-doc: wrong answer for ' . var_export($doc, true) . "\n");
        exit(1);
    }
}
echo "actor-bot-doc-smoke ok cases=" . count($cases) . "\n";
