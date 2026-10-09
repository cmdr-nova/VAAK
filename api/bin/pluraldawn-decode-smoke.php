<?php
/**
 * Round-trip Pluraldawn avatars without a database or network.
 * Usage: php api/bin/pluraldawn-decode-smoke.php
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/ap-pluraldawn.php';

function pd_chunk(string $type, string $data): string
{
    $crc = crc32($type . $data);
    if ($crc < 0) {
        $crc += 4294967296;
    }
    return pack('N', strlen($data)) . $type . $data . pack('N', $crc);
}

function pd_png(int $w, int $h, string $rgb, int $filter): string
{
    $stride = $w * 3;
    $raw = '';
    $prev = str_repeat("\0", $stride);
    for ($y = 0; $y < $h; $y++) {
        $row = substr($rgb, $y * $stride, $stride);
        $stored = '';
        if ($filter === 0) {
            $stored = $row;
        } else {
            for ($i = 0; $i < $stride; $i++) {
                $left = $i >= 3 ? ord($row[$i - 3]) : 0;
                $up = ord($prev[$i]);
                $ul = $i >= 3 ? ord($prev[$i - 3]) : 0;
                $pred = match ($filter) {
                    1 => $left,
                    2 => $up,
                    3 => intdiv($left + $up, 2),
                    4 => ap_pluraldawn_paeth($left, $up, $ul),
                    default => 0,
                };
                $stored .= chr((ord($row[$i]) - $pred) & 255);
            }
        }
        $raw .= chr($filter) . $stored;
        $prev = $row;
    }
    $ihdr = pack('NNC5', $w, $h, 8, 2, 0, 0, 0);
    $idat = zlib_encode($raw, ZLIB_ENCODING_DEFLATE);
    if (!is_string($idat)) {
        throw new RuntimeException('zlib encode failed');
    }
    $gama = pack('N', 45455);
    return "\x89PNG\r\n\x1a\n"
        . pd_chunk('IHDR', $ihdr)
        . pd_chunk('gAMA', $gama)
        . pd_chunk('IDAT', $idat)
        . pd_chunk('IEND', '');
}

function pd_embed(string $rgb, int $w, int $h, string $payload, string $mode): string
{
    $pix = $w * $h;
    if (strlen($payload) > $pix) {
        throw new RuntimeException('payload does not fit');
    }
    for ($i = 0, $n = strlen($payload); $i < $n; $i++) {
        $p = ($pix - 1 - $i) * 3;
        $byte = ord($payload[$i]);
        if ($mode === 'pldon') {
            $r = (ord($rgb[$p]) & ~3) | (($byte >> 6) & 3);
            $g = (ord($rgb[$p + 1]) & ~7) | (($byte >> 3) & 7);
            $b = (ord($rgb[$p + 2]) & ~7) | ($byte & 7);
        } else {
            $r = (ord($rgb[$p]) & ~7) | (($byte >> 5) & 7);
            $g = (ord($rgb[$p + 1]) & ~3) | (($byte >> 3) & 3);
            $b = (ord($rgb[$p + 2]) & ~7) | ($byte & 7);
        }
        $rgb[$p] = chr($r);
        $rgb[$p + 1] = chr($g);
        $rgb[$p + 2] = chr($b);
    }
    return $rgb;
}

function pd_expect(array $members, array $want): void
{
    if (count($members) !== count($want)) {
        throw new RuntimeException('member count ' . count($members) . ' != ' . count($want) . ' ' . json_encode($members));
    }
    foreach ($want as $i => $row) {
        foreach ($row as $key => $value) {
            if (($members[$i][$key] ?? null) !== $value) {
                throw new RuntimeException($key . ' mismatch: ' . json_encode($members[$i]));
            }
        }
    }
}

$ids = "zero\x1Eone\x1D";
$names = "0 Zero | The Numerals\x1E1 One | The Numerals\x1D";
$indicators = ":zero:\x1E:one:\x1D";
$avatars = "\x10\x00\x1E\x10\x0Athis-is-not-a-real-url/just-an-example.webp\x1D";
$fonts = "\x10\x00\x1E\x10\x03\x1D\x04";
$plain = $ids . $names . $indicators . $avatars . $fonts;
$zlib = zlib_encode($plain, ZLIB_ENCODING_DEFLATE);
if (!is_string($zlib)) {
    throw new RuntimeException('group zlib failed');
}
$payload = 'PlDw2' . $zlib;

$w = 48;
$h = 48;
$base = str_repeat("\x80\x80\x80", $w * $h);
$rgb = pd_embed($base, $w, $h, $payload, 'pldw2');
$want = [
    ['id' => 'zero', 'name' => '0 Zero | The Numerals', 'indicators' => [':zero:'], 'avatar' => 'emoji', 'font' => 'normal'],
    ['id' => 'one', 'name' => '1 One | The Numerals', 'indicators' => [':one:'], 'avatar' => 'https://cdn.pluralkit.me/this-is-not-a-real-url/just-an-example.webp', 'font' => 'monospace'],
];
foreach ([0, 1, 2, 3, 4] as $filter) {
    $members = ap_pluraldawn_members_from_image(pd_png($w, $h, $rgb, $filter));
    pd_expect($members, $want);
}

$list = ":three:\x1F~3";
$plain2 = "three\x1D3 Three\x1D{$list}\x1D\x10\x02\x1D\x10\x02\x1D\x04";
$payload2 = 'PlDw2' . zlib_encode($plain2, ZLIB_ENCODING_DEFLATE);
$rgb2 = pd_embed($base, $w, $h, $payload2, 'pldw2');
$two = ap_pluraldawn_members_from_image(pd_png($w, $h, $rgb2, 0));
pd_expect($two, [[
    'id' => 'three',
    'name' => '3 Three',
    'indicators' => [':three:', '~3'],
    'avatar' => 'https://exa.y2k.diy/junk/noise.png',
    'font' => 'small',
]]);

$json = '{"members":[{"emoji":[":a:","~a"],"id":"a","name":"A","avatar":"https://evil.example/a.png","font":"smallcaps"}]}';
$rgb3 = pd_embed($base, $w, $h, 'PlDon' . $json . "\0", 'pldon');
$v1 = ap_pluraldawn_members_from_image(pd_png($w, $h, $rgb3, 0));
pd_expect($v1, [[
    'id' => 'a',
    'name' => 'A',
    'indicators' => [':a:', '~a'],
    'avatar' => 'emoji',
    'font' => 'smallcaps',
]]);

$empty = ap_pluraldawn_members_from_image(pd_png($w, $h, $base, 0));
if ($empty !== []) {
    throw new RuntimeException('plain avatar decoded members');
}
$jpeg = ap_pluraldawn_members_from_image("\xFF\xD8\xFF" . str_repeat('x', 32));
if ($jpeg !== []) {
    throw new RuntimeException('jpeg decoded members');
}

if (!ap_pluraldawn_url_public('https://127.0.0.1/a.png') && !ap_pluraldawn_url_public('https://192.168.1.2/a.png') && !ap_pluraldawn_url_public('http://cdn.pluralkit.me/a.png')) {
    echo "pluraldawn-decode-smoke: ok\n";
    exit(0);
}
throw new RuntimeException('private or plain-http URL was accepted');
