<?php
declare(strict_types=1);

/**
 * ImageMagick only ever sees a pinned raster decoder.
 * Uploads and remote media reach magick/convert. Without a coder prefix it
 * sniffs the bytes and can hand SVG, MVG, PostScript, or text to delegates.
 */

$failures = [];
$checks = 0;
$check = static function (bool $ok, string $label) use (&$failures, &$checks): void {
    $checks++;
    if (!$ok) {
        $failures[] = $label;
    }
};

$api = dirname(__DIR__);
$r2 = (string) file_get_contents($api . '/ap-r2.php');

if (!preg_match('/^function ap_media_magick_input\(.*?^}/ms', $r2, $m)) {
    fwrite(STDERR, "ap_media_magick_input not found\n");
    exit(1);
}
eval($m[0]);

$tmp = sys_get_temp_dir() . '/vaak-magick-smoke-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700);
$write = static function (string $name, string $bytes) use ($tmp): string {
    $path = $tmp . '/' . $name;
    file_put_contents($path, $bytes);
    return $path;
};

$png = $write('a', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==', true));
$check(ap_media_magick_input($png) === escapeshellarg('png:' . $png . '[0]'), 'png is pinned to the png coder');

$gif = $write('b', "GIF89a\x01\x00\x01\x00\x80\x00\x00\x00\x00\x00\xff\xff\xff!\xf9\x04\x01\x00\x00\x00\x00,\x00\x00\x00\x00\x01\x00\x01\x00\x00\x02\x02D\x01\x00;");
$check(ap_media_magick_input($gif) === escapeshellarg('gif:' . $gif . '[0]'), 'gif is pinned to the gif coder');

$svg = $write('c', '<svg xmlns="http://www.w3.org/2000/svg" width="1" height="1"></svg>');
$check(ap_media_magick_input($svg) === null, 'svg is refused');

$ps = $write('d', "%!PS-Adobe-3.0\nshowpage\n");
$check(ap_media_magick_input($ps) === null, 'postscript is refused');

$mvg = $write('e', "push graphic-context\nviewbox 0 0 1 1\npop graphic-context\n");
$check(ap_media_magick_input($mvg) === null, 'mvg text is refused');

$check(!preg_match("/(magick|convert)\s+'\s*\.\s*escapeshellarg\(\\\$path\)/", $r2)
    && !preg_match('/escapeshellarg\(\$path\)\s*\.\s*\'\[0\]/', $r2), 'no converter passes an unpinned path');

foreach (glob($tmp . '/*') ?: [] as $f) {
    @unlink($f);
}
@rmdir($tmp);

if ($failures !== []) {
    fwrite(STDERR, "media-magick-coder-smoke: " . count($failures) . " of {$checks} failed\n  " . implode("\n  ", $failures) . "\n");
    exit(1);
}
echo "media-magick-coder-smoke: {$checks} checks ok\n";
