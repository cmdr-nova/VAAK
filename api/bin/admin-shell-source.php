<?php
declare(strict_types=1);
/**
 * Test helper: api/ap-admin.php with the shell's CSS and scripts put back
 * inline. Those moved to api/assets/app/ (linked through
 * admin_static_asset_url) so browsers can cache them; source-level smokes
 * that look for JS or CSS in the shell read this instead of the raw file.
 * Request values the scripts get from VAAK_BOOT.sNN[i] are put back as the
 * PHP tags the inline boot line holds, so the text matches the old shell.
 */
function vaak_admin_shell_source(string $adminPath): string
{
    $admin = (string) file_get_contents($adminPath);
    $boot = [];
    if (preg_match_all('#VAAK_BOOT\.(s[0-9]+)=\[(.*)\];</script>#', $admin, $lines, PREG_SET_ORDER)) {
        foreach ($lines as $line) {
            preg_match_all('#<\?=.*?\?>#', $line[2], $tags);
            $boot[$line[1]] = $tags[0];
        }
    }
    $assets = dirname($adminPath) . '/assets/';
    $body = static function (string $rel) use ($assets, $boot): string {
        $text = (string) file_get_contents($assets . $rel);
        // Drop the "moved out of ap-admin.php" header comment.
        $text = (string) preg_replace('#^/\*.*?\*/\n#s', '', $text, 1);
        return (string) preg_replace_callback(
            '#VAAK_BOOT\.(s[0-9]+)\[([0-9]+)\]#',
            static fn(array $m): string => $boot[$m[1]][(int) $m[2]] ?? $m[0],
            $text
        );
    };
    $admin = (string) preg_replace_callback(
        '#<script src="<\?= h\(admin_static_asset_url\(\'(app/[a-z0-9-]+\.js)\'\)\) \?>"></script>#',
        static fn(array $m): string => "<script>\n" . rtrim($body($m[1]), "\n") . "\n</script>",
        $admin
    );
    return (string) preg_replace_callback(
        '#<link rel="stylesheet" href="<\?= h\(admin_static_asset_url\(\'(app/[a-z0-9-]+\.css)\'\)\) \?>">#',
        static fn(array $m): string => "<style>\n" . rtrim($body($m[1]), "\n") . "\n</style>",
        $admin
    );
}
