#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Bluemoji facet parsing (no network, no database). Mirrors the Rust tests in
 * rust/vaak-worker/src/bluemoji.rs so both painters read facets the same way.
 *
 *   php api/bin/bluemoji-facet-smoke.php
 */

require_once dirname(__DIR__) . '/ap-bsky.php';

$fail = 0;
$check = static function (bool $ok, string $what) use (&$fail): void {
    echo ($ok ? 'ok   ' : 'FAIL ') . $what . "\n";
    if (!$ok) {
        $fail++;
    }
};

$did = 'did:plc:abcdefghijklmnopqrstuvwx';
$png = 'bafkreigh2akiscaildcqabsyg3dfr6chu3fgpregiymsck7e7aqa4s52zy';
$gif = 'bafkreibme22gw2h7y2h7tg2fhqotaqjucnbc24deqo72b6mkl2egezxhvy';
$text = "add support for bluemoji\n\n:cat_smoke_by_gabboman:";
$feature = static fn (array $extra = []): array => array_merge(
    ['$type' => 'blue.moji.richtext.facet', 'did' => $did, 'name' => ':cat_smoke_by_gabboman:', 'formats' => ['$type' => 'blue.moji.richtext.facet#formats_v1', 'png_128' => $png]],
    $extra
);
$facets = static fn (array $f): array => [['index' => ['byteStart' => 26, 'byteEnd' => 49], 'features' => [$f]]];

$got = ap_bsky_bluemoji_claims($text, $facets($feature()));
$check($got === [['did' => $did, 'code' => 'cat_smoke_by_gabboman', 'cid' => $png, 'static_cid' => $png]], 'v1 facet claim');

$got = ap_bsky_bluemoji_claims($text, $facets($feature(['formats' => ['gif_128' => $gif, 'png_128' => $png, 'apng_128' => true]])));
$check(($got[0]['cid'] ?? '') === $gif && ($got[0]['static_cid'] ?? '') === $png, 'animated url, still static');

$check(ap_bsky_bluemoji_claims($text, $facets($feature(['formats' => ['apng_128' => true, 'lottie' => true]]))) === [], 'v0 booleans only: no claim');
$check(ap_bsky_bluemoji_claims($text, $facets($feature(['adultOnly' => true]))) === [], 'adultOnly stays text');
$check(ap_bsky_bluemoji_claims($text, $facets($feature(['labels' => ['values' => [['val' => 'porn']]]]))) === [], 'labelled stays text');
$check(ap_bsky_bluemoji_claims($text, $facets($feature(['did' => 'did:web:localhost']))) === [], 'dotless did:web refused');
$check(ap_bsky_bluemoji_claims($text, $facets($feature(['formats' => ['png_128' => 'https://evil.test/x.png']]))) === [], 'non-CID format refused');
$check(ap_bsky_bluemoji_claims($text, $facets($feature(['$type' => 'app.bsky.richtext.facet#link']))) === [], 'other features ignored');

$blob = ['png_128' => ['$type' => 'blob', 'ref' => ['$link' => $png], 'mimeType' => 'image/png', 'size' => 900]];
$check(ap_bsky_bluemoji_pick_formats($blob) === [$png, $png], 'record blob ref');

$bad = [['index' => ['byteStart' => 0, 'byteEnd' => 99], 'features' => [
    $feature(['name' => ':Blob-Cat:']),
    $feature(['name' => ':blob.cat:']),
]]];
$got = ap_bsky_bluemoji_claims('x', $bad);
$check(count($got) === 1 && $got[0]['code'] === 'Blob-Cat', 'name fallback; dotted names stay text');

$check(
    ap_bsky_bluemoji_blob_url('https://pds.test/', $did, $png)
        === 'https://pds.test/xrpc/com.atproto.sync.getBlob?did=did%3Aplc%3Aabcdefghijklmnopqrstuvwx&cid=' . $png,
    'getBlob url'
);

exit($fail === 0 ? 0 : 1);
