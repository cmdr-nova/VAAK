<?php
declare(strict_types=1);

/**
 * Pure DM/Ask parity gate. This deliberately does not connect to a database,
 * deliver ActivityPub, or contact a remote actor. Database mutation coverage
 * remains an opt-in isolated-DB job; this gate validates the shared contracts
 * before that job is allowed to run.
 */
$api = dirname(__DIR__);
$dbSource = (string) @file_get_contents($api . '/ap-db.php');
$inboxSource = (string) @file_get_contents($api . '/ap-inbox.php');
$asksSource = (string) @file_get_contents($api . '/ap-asks.php');
$maintainSource = (string) @file_get_contents($api . '/ap-maintain.php');
foreach ([
    [$dbSource, 'ap_dm_store', 'DM storage function'],
    [$dbSource, 'owner_user_id = ? AND object_id = ?', 'DM owner-scoped lookup'],
    [$dbSource, 'deleted_at IS NULL', 'DM deleted-row hiding'],
    [$dbSource, 'ap_is_blocked_actor($peer)', 'blocked-peer delivery guard'],
    [$inboxSource, 'to_actor', 'inbox recipient addressing'],
    [$inboxSource, 'Never publish as DM through this path', 'public/DM separation'],
    [$asksSource, 'ap_ask_parse_compact_text', 'Ask compact parser'],
    [$asksSource, 'VAAK never sends anonymous Asks', 'non-anonymous Ask boundary'],
    [$maintainSource, '-48 hours', 'Ask expiry window'],
] as [$source, $needle, $label]) {
    if ($source === '' || !str_contains($source, $needle)) {
        fwrite(STDERR, "dm-ask-parity: missing {$label}\n");
        exit(1);
    }
}

require_once $api . '/ap-asks.php';

$cases = [
    [
        'name' => 'html-separated-answer',
        'text' => '<p>@alice@example.test asked</p><blockquote>What is VAAK?</blockquote><p>A social bridge.</p>',
        'question' => 'What is VAAK?',
        'answer' => 'A social bridge.',
    ],
    [
        'name' => 'compact-separated-answer',
        'text' => "@alice@example.test asked\n\nWhat is VAAK?\n\nA social bridge.",
        'question' => 'What is VAAK?',
        'answer' => 'A social bridge.',
    ],
];
foreach ($cases as $case) {
    $parsed = ap_ask_parse_compact_text($case['text']);
    if (!is_array($parsed)
        || ($parsed['question'] ?? '') !== $case['question']
        || ($parsed['answer'] ?? '') !== $case['answer']) {
        fwrite(STDERR, 'dm-ask-parity: parser mismatch: ' . $case['name'] . "\n");
        exit(1);
    }
    $html = ap_wafrn_remote_ask_html($case['text']);
    if (!is_string($html) || !str_contains($html, 'ask-container')
        || !str_contains($html, 'ask-divider')
        || !str_contains($html, htmlspecialchars($case['question'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))
        || !str_contains($html, htmlspecialchars($case['answer'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'))) {
        fwrite(STDERR, 'dm-ask-parity: rendered Ask mismatch: ' . $case['name'] . "\n");
        exit(1);
    }
}

$invalid = ap_ask_parse_compact_text('@alice@example.test asked\n\n');
if ($invalid !== null) {
    fwrite(STDERR, "dm-ask-parity: accepted empty Ask question\n");
    exit(1);
}

echo "dm-ask-parity: PASS (DM ownership/privacy markers; Ask parser, answer separation, divider, expiry, and federation boundaries)\n";
