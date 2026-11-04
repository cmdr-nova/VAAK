<?php
declare(strict_types=1);
/**
 * The shell's CSS and big scripts are files in api/assets/app/ (cached by the
 * browser) instead of inline text resent on every full page load. Checks:
 * every linked file exists, every VAAK_BOOT value a script reads is filled by
 * its boot line, and no new big inline block creeps back into the shell.
 */
require_once __DIR__ . '/admin-shell-source.php';
$root = getenv('VAAK_SHELL_ASSETS_ROOT') ?: dirname(__DIR__, 2);
$checks = 0;
function shell_assets_check(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message);
    }
    $GLOBALS['checks']++;
}

$admin = (string) file_get_contents($root . '/api/ap-admin.php');
preg_match_all("#admin_static_asset_url\\('(app/[a-z0-9-]+\\.(?:js|css))'\\)#", $admin, $m);
$linked = array_values(array_unique($m[1]));
shell_assets_check(count($linked) >= 2, 'shell links its asset files');
foreach ($linked as $rel) {
    shell_assets_check(is_file($root . '/api/assets/' . $rel), "{$rel} exists");
}
foreach (glob($root . '/api/assets/app/*.{js,css}', GLOB_BRACE) ?: [] as $file) {
    shell_assets_check(in_array('app/' . basename($file), $linked, true), basename($file) . ' is linked by the shell');
}

// Boot values: VAAK_BOOT.sNN[i] in a file needs entry i in the sNN boot line,
// and that line must come right before the file's <script src>.
preg_match_all('#<script>window\.VAAK_BOOT=window\.VAAK_BOOT\|\|\{\};VAAK_BOOT\.(s[0-9]+)=\[(.*)\];</script>\n\s*<script src="<\?= h\(admin_static_asset_url\(\'app/vaak-([0-9]+)\.js\'\)\) \?>"></script>#', $admin, $boots, PREG_SET_ORDER);
$bootSizes = [];
foreach ($boots as $b) {
    shell_assets_check($b[1] === 's' . $b[3], "boot line {$b[1]} sits right before vaak-{$b[3]}.js");
    $bootSizes[$b[1]] = preg_match_all('#<\?=.*?\?>#', $b[2]);
}
foreach (glob($root . '/api/assets/app/*.js') ?: [] as $file) {
    preg_match_all('#VAAK_BOOT\.(s[0-9]+)\[([0-9]+)\]#', (string) file_get_contents($file), $refs, PREG_SET_ORDER);
    foreach ($refs as $r) {
        shell_assets_check(isset($bootSizes[$r[1]]) && (int) $r[2] < $bootSizes[$r[1]], basename($file) . " reads a filled {$r[0]}");
    }
    shell_assets_check(!str_contains((string) file_get_contents($file), '<?'), basename($file) . ' has no PHP');
}

// The test view puts everything back inline (used by the other smokes).
$full = vaak_admin_shell_source($root . '/api/ap-admin.php');
shell_assets_check(!preg_match("#admin_static_asset_url\\('app/#", $full), 'test view re-inlines every file');
shell_assets_check(!preg_match('#VAAK_BOOT\.s[0-9]+\[[0-9]+\]#', $full), 'test view restores every boot value');

// No big inline <script>/<style> back in the shell: put new code in a file.
$start = strpos($admin, "<!DOCTYPE html>\n");
$end = strrpos($admin, "\n</html>");
$shell = substr($admin, (int) $start, (int) $end - (int) $start);
preg_match_all('#<(script|style)>\n(.*?)\n\s*</\1>#s', $shell, $inline, PREG_SET_ORDER);
foreach ($inline as $block) {
    shell_assets_check(strlen($block[2]) < 12000, 'inline <' . $block[1] . '> under 12 KB (move big code to api/assets/app/): ' . substr(trim($block[2]), 0, 60));
}

echo "shell-static-assets-smoke: {$checks} checks passed\n";
