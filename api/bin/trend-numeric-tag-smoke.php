<?php
/** Number-only tags (#039, #2026) never trend, including rows cached before the rule. */
declare(strict_types=1);

require __DIR__ . '/migration-reference-functions.php';

$checks = 0;
function check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

reference_load(dirname(__DIR__) . '/ap-masto-entities.php', [
    'ap_masto_trend_tag_is_numeric',
    'ap_masto_trend_tags_without_numeric',
]);

foreach (['039', '2026', '12_34', '٣٤'] as $name) {
    check(ap_masto_trend_tag_is_numeric($name), "#{$name} is number-only");
}
foreach (['anime', 'web3', '3d', 'año', 'f1_2026', ''] as $name) {
    check(!ap_masto_trend_tag_is_numeric($name), "#{$name} is a real tag");
}
$kept = ap_masto_trend_tags_without_numeric([['name' => 'anime'], ['name' => '039'], ['name' => '3d'], 'junk']);
check(array_column(array_filter($kept, 'is_array'), 'name') === ['anime', '3d'], 'cached rows drop number-only tags in order');

$src = file_get_contents(dirname(__DIR__) . '/ap-admin.php');
check(str_contains($src, "|| preg_match('/^[\\p{N}_]+$/u', (string) (\$candidate['name'] ?? '')) === 1"), 'sidebar skips cached number-only tags before the limit');

echo "trend-numeric-tag-smoke: {$checks} checks ok\n";
