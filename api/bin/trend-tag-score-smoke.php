<?php
// Trending tag score (ap_masto_trend_tag_score): people over posts, and
// today against the tag's own usual level. No database needed.
declare(strict_types=1);
require dirname(__DIR__) . '/ap-masto-entities.php';

$empty = [[], [], [], [], [], [], []];
$day = static function (array $perAccount, int $day) use ($empty): array {
    $a = $empty;
    $a[$day] = $perAccount;
    return $a;
};
$uses = static fn (array $accounts): array => array_map(static fn ($d) => array_sum($d), $accounts);

// One account posting a tag 30 times today vs 10 people once each.
$spam = $day(['https://x.test/users/bot' => 30], 0);
$crowd = [];
for ($i = 0; $i < 10; $i++) $crowd['https://x.test/users/p' . $i] = 1;
$crowd = $day($crowd, 0);
$spamScore = ap_masto_trend_tag_score($uses($spam), $spam);
$crowdScore = ap_masto_trend_tag_score($uses($crowd), $crowd);
if (!($crowdScore > $spamScore * 5)) throw new RuntimeException("crowd $crowdScore should beat one account $spamScore");

// An always-busy tag (8 people every day) vs a new one 6 people picked up today.
$steady = [];
for ($d = 0; $d < 7; $d++) {
    for ($i = 0; $i < 8; $i++) $steady[$d]['https://x.test/users/s' . $i] = 1;
}
$fresh = [];
for ($i = 0; $i < 6; $i++) $fresh['https://x.test/users/f' . $i] = 1;
$fresh = $day($fresh, 0);
$steadyScore = ap_masto_trend_tag_score($uses($steady), $steady);
$freshScore = ap_masto_trend_tag_score($uses($fresh), $fresh);
if (!($freshScore > $steadyScore)) throw new RuntimeException("new tag $freshScore should beat steady tag $steadyScore");

// Anything active now ranks above a tag only used days ago.
$old = [];
for ($i = 0; $i < 20; $i++) $old['https://x.test/users/o' . $i] = 1;
$old = $day($old, 4);
$oneToday = $day(['https://x.test/users/a' => 1, 'https://x.test/users/b' => 1], 0);
if (!(ap_masto_trend_tag_score($uses($oneToday), $oneToday) > ap_masto_trend_tag_score($uses($old), $old))) {
    throw new RuntimeException('a tag active today should rank above one only used 4 days ago');
}

// A second post by the same person adds a little, a third adds nothing.
$two = $day(['https://x.test/users/a' => 2, 'https://x.test/users/b' => 1], 0);
$three = $day(['https://x.test/users/a' => 3, 'https://x.test/users/b' => 1], 0);
$one = $day(['https://x.test/users/a' => 1, 'https://x.test/users/b' => 1], 0);
$s1 = ap_masto_trend_tag_score($uses($one), $one);
$s2 = ap_masto_trend_tag_score($uses($two), $two);
$s3 = ap_masto_trend_tag_score($uses($three), $three);
if (!($s2 > $s1) || abs($s3 - $s2) > 1e-9) throw new RuntimeException("per-account cap: $s1 $s2 $s3");

// Posts with no known account still count, one each.
$anon = [3, 0, 0, 0, 0, 0, 0];
if (!(ap_masto_trend_tag_score($anon, $empty) > 0.05)) throw new RuntimeException('account-less posts count');

echo "trend tag score: people over posts, baseline, per-account cap ok\n";
