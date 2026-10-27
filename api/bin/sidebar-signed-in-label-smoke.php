<?php
declare(strict_types=1);
/**
 * Sidebar "signed in as" label is the local @handle only.
 * The account acct, actor id, profile URL, mentions, and VAAK_HANDLE stay full.
 * No database. The label is server-rendered; scripts and Rust pages do not paint it.
 */
$root = dirname(__DIR__, 2);
$checks = 0;
function sidebar_label_check(bool $ok, string $label): void
{
    if (!$ok) {
        throw new RuntimeException($label);
    }
    $GLOBALS['checks']++;
}

$admin = (string) file_get_contents($root . '/api/ap-admin.php');
if (!preg_match('/^function admin_sidebar_local_handle\([^\n]*\).*?^\}/ms', $admin, $fn)) {
    throw new RuntimeException('missing admin_sidebar_local_handle');
}
eval($fn[0]);

$cases = [
    ['cmdr_nova', '', true, '@cmdr_nova'],
    ['', 'cmdr_nova', true, '@cmdr_nova'],
    ['@cmdr_nova@mkultra.monster', '', true, '@cmdr_nova'],
    ['cmdr_nova@mkultra.monster', 'other', true, '@cmdr_nova'],
    ['  @cmdr_nova@vaak.monster  ', '', true, '@cmdr_nova'],
    ['cmdr_nova@mkultra.monster', '', false, 'cmdr_nova'],
    ['', '', true, ''],
    ['   ', '  ', true, ''],
    ['__guest__', '', true, ''],
    ['__guest__', 'cmdr_nova', true, '@cmdr_nova'],
    ['', '__guest__', true, ''],
    ['@', 'cmdr_nova', true, '@cmdr_nova'],
    ['cmdr_nova', 'someone@remote.test', true, '@cmdr_nova'],
];
foreach ($cases as [$username, $fallback, $withAt, $want]) {
    $got = admin_sidebar_local_handle($username, $fallback, $withAt);
    sidebar_label_check($got === $want, 'local handle ' . json_encode([$username, $fallback, $withAt]) . ' => ' . json_encode($got) . ' wanted ' . json_encode($want));
}

sidebar_label_check(
    str_contains($admin, "\$vaakHandle = \$vaakUsername !== ''\n        ? ('@' . \$vaakUsername . '@mkultra.monster')\n        : '';"),
    'acct handle still appends the instance domain'
);
sidebar_label_check(
    str_contains($admin, 'window.VAAK_HANDLE = <?= json_encode((string) ($vaakHandle ?? \'\')'),
    'client VAAK_HANDLE still publishes the full acct handle'
);

$signed = '<div class="meta brand-signed-in" style="margin:.35rem 0 0;font-size:.72rem;line-height:1.3">signed in as <?= h(admin_sidebar_local_handle((string) $vaakUsername, (string) ($vaakActorKey ?? \'\'))) ?></div>';
sidebar_label_check(substr_count($admin, $signed) === 1, 'signed-in sidebar label uses the local handle once');
sidebar_label_check(
    str_contains($admin, '<div class="meta brand-signed-in" style="margin:.35rem 0 0;font-size:.72rem;line-height:1.3">browsing as guest</div>'),
    'guest sidebar label unchanged'
);
sidebar_label_check(substr_count($admin, 'brand-signed-in') === 3, 'signed-in class stays on the CSS rule and the two labels');

$railAt = strpos($admin, '<aside class="rail-left" id="admin-rail-left">');
$railEnd = strpos($admin, '<nav class="nav" aria-label="Guest">', (int) $railAt);
sidebar_label_check($railAt !== false && $railEnd !== false && $railEnd > $railAt, 'left rail located');
$brand = substr($admin, (int) $railAt, (int) $railEnd - (int) $railAt);
sidebar_label_check(str_contains($brand, 'aria-label="View profile"'), 'profile link stays beside the label');
sidebar_label_check(str_contains($brand, 'ap_vaak_pretty_profile_path((string) $vaakActorKey)'), 'profile URL still uses the actor key');
sidebar_label_check(!str_contains($brand, 'h($vaakHandle)'), 'sidebar brand block no longer prints the full acct');
sidebar_label_check(str_contains($brand, 'name="action" value="switch_account"'), 'switch still posts switch_account');
sidebar_label_check(str_contains($brand, 'name="account_id" value="<?= $switchRowId ?>"'), 'switch still posts the account id');
sidebar_label_check(
    str_contains($brand, "h(admin_sidebar_local_handle((string) (\$switchRow['username'] ?? ''), (string) \$switchRowKey))"),
    'sidebar switcher rows use the local handle'
);

$switchPageAt = strpos($admin, "<?php elseif (\$view === 'account_switcher'): ?>");
$switchPageEnd = strpos($admin, "<?php elseif (\$view === 'security'): ?>", (int) $switchPageAt);
sidebar_label_check($switchPageAt !== false && $switchPageEnd !== false, 'account switcher page located');
$switchPage = substr($admin, (int) $switchPageAt, (int) $switchPageEnd - (int) $switchPageAt);
sidebar_label_check(
    str_contains($switchPage, "admin_sidebar_local_handle((string) (\$sessionAccount['username'] ?? ''), \$sessionAccountKey, false)"),
    'switcher account name drops the domain'
);
sidebar_label_check(
    str_contains($switchPage, "admin_sidebar_local_handle((string) (\$sessionAccount['username'] ?? ''), \$sessionAccountKey))"),
    'switcher account handle drops the domain'
);
sidebar_label_check(str_contains($switchPage, 'name="account_id" value="<?= $sessionAccountId ?>"'), 'switcher page still posts the account id');
sidebar_label_check(str_contains($admin, "('Switched to @' . \$as . '.')"), 'switch notice still uses the local actor key');

sidebar_label_check(str_contains($admin, 'h($vaakHandle)'), 'other screens still print the full acct handle');
sidebar_label_check(str_contains($admin, "admin_handle' => (string) (\$vaakHandle ?? 'admin')"), 'report notes still sign with the full handle');

$scripts = '';
if (preg_match_all('/<script\b[^>]*>(.*?)<\/script>/is', $admin, $scriptBlocks)) {
    $scripts = implode("\n", $scriptBlocks[1]);
}
sidebar_label_check($scripts !== '' && !str_contains($scripts, 'brand-signed-in') && !str_contains($scripts, 'signed in as'), 'no script fills or updates the sidebar label');
$jsHit = [];
foreach (glob($root . '/api/assets/*.js') ?: [] as $jsFile) {
    $js = (string) file_get_contents($jsFile);
    if (str_contains($js, 'brand-signed-in') || str_contains($js, 'signed in as')) {
        $jsHit[] = basename($jsFile);
    }
}
sidebar_label_check($jsHit === [], 'asset scripts do not render the sidebar label' . ($jsHit === [] ? '' : ': ' . implode(', ', $jsHit)));

$rustHit = [];
$rust = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/rust', FilesystemIterator::SKIP_DOTS));
foreach ($rust as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '.rs')) {
        continue;
    }
    $text = (string) file_get_contents($file->getPathname());
    if (str_contains($text, 'brand-signed-in') || str_contains($text, 'signed in as ')) {
        $rustHit[] = substr($file->getPathname(), strlen($root) + 1);
    }
}
sidebar_label_check($rustHit === [], 'Rust pages do not render the sidebar label' . ($rustHit === [] ? '' : ': ' . implode(', ', $rustHit)));

echo "sidebar-signed-in-label-smoke: {$checks} checks passed\n";
