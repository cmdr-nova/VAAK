<?php
/** Trending tag sparklines: oldest day on the left, scaled to the busiest day, safe on empty history. */
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

reference_load(dirname(__DIR__) . '/ap-admin.php', ['admin_trend_sparkline_svg']);

function spark_points(string $svg): array
{
    if (!preg_match('/<polyline class="trend-spark-line" points="([^"]+)"/', $svg, $m)) return [];
    return array_map(static fn(string $p): array => array_map('floatval', explode(',', $p)), explode(' ', $m[1]));
}

$day = static fn(int $uses): array => ['day' => '0', 'uses' => (string) $uses, 'accounts' => '0'];

// Mastodon order: today first. Today is the spike, so it must land on the right edge.
$svg = admin_trend_sparkline_svg([$day(8), $day(0), $day(0), $day(2), $day(0), $day(0), $day(0)]);
$pts = spark_points($svg);
check(count($pts) === 7, 'seven points');
check($pts[0][0] === 0.0 && $pts[6][0] === 56.0, 'spans the full width');
check($pts[6][1] === 2.0, 'busiest day (today) touches the top on the right');
check($pts[0][1] === 22.0, 'quiet oldest day sits on the baseline');
check($pts[3][1] > $pts[6][1] && $pts[3][1] < $pts[0][1], 'middle day scales between');
check(str_contains($svg, 'aria-hidden="true"'), 'decorative for screen readers');

$flat = spark_points(admin_trend_sparkline_svg([]));
check(count($flat) === 7 && count(array_unique(array_column($flat, 1))) === 1, 'empty history is a flat baseline');
check(spark_points(admin_trend_sparkline_svg([$day(-5), 'junk']))[6][1] === 22.0, 'bad rows clamp to zero');

echo "trend-sparkline-smoke: {$checks} checks ok\n";
