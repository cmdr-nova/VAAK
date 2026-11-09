<?php
declare(strict_types=1);

/**
 * Phyrian rarity tiers: every tiered race is in the catalog, tiers don't overlap,
 * and the weighted origin roll lands near the published odds. No database.
 */
$apiDir = dirname(__DIR__);
require_once $apiDir . '/ap-phyrian.php';

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$catalog = ap_phyrian_strain_catalog();
$tiers = ap_phyrian_strain_tiers();
$seen = [];
foreach ($tiers as $tier => $names) {
    foreach ($names as $name) {
        $check(in_array($name, $catalog, true), $tier . ' race is in the catalog: ' . $name);
        $check(!isset($seen[$name]), 'race is in one tier only: ' . $name);
        $seen[$name] = true;
    }
}
$check(count($tiers['Legendary'] ?? []) >= 1, 'at least one Legendary race');
$check(array_sum(ap_phyrian_strain_tier_weights()) === 100, 'tier weights add up to 100');

$check(ap_phyrian_strain_tier('Black Sun Cult') === 'Legendary', 'Black Sun Cult is Legendary');
$check(ap_phyrian_strain_tier('Cosmic Alien') === 'Common', 'untiered catalog race is Common');
$check(ap_phyrian_strain_tier('Not A Real Strain') === '', 'unknown strain has no tier');
$check(ap_phyrian_strain_tier('') === '', 'empty strain has no tier');

$counts = ['Common' => 0, 'Uncommon' => 0, 'Rare' => 0, 'Legendary' => 0];
$rolls = 40000;
for ($i = 0; $i < $rolls; $i++) {
    $strain = ap_phyrian_pick_strain();
    $tier = ap_phyrian_strain_tier($strain);
    $check($tier !== '', 'roll returns a catalog race');
    $counts[$tier] = ($counts[$tier] ?? 0) + 1;
}
foreach (ap_phyrian_strain_tier_weights() as $tier => $pct) {
    $got = 100 * $counts[$tier] / $rolls;
    // Wide band: this guards against a broken roll, not against normal variance.
    $check(abs($got - $pct) <= max(1.0, $pct * 0.25), sprintf('%s rolls near %d%% (got %.2f%%)', $tier, $pct, $got));
}

if ($failures !== []) {
    fwrite(STDERR, "phyrian-rarity-smoke: " . count($failures) . " of {$checks} checks failed\n");
    foreach (array_slice(array_unique($failures), 0, 20) as $f) {
        fwrite(STDERR, "  - {$f}\n");
    }
    exit(1);
}
echo "phyrian-rarity-smoke: {$checks} checks passed\n";
