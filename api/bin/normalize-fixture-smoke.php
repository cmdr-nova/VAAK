#!/usr/bin/env php
<?php
declare(strict_types=1);

/**
 * Snapshot-test the VAAK normalizer against api/fixtures/normalize/*.
 *
 * Usage:
 *   php api/bin/normalize-fixture-smoke.php
 *   php api/bin/normalize-fixture-smoke.php --write-expected
 *   php api/bin/normalize-fixture-smoke.php --only=mastodon_note,ask_answer
 *
 * Prod: sudo -u www-data env AP_DB_DSN='pgsql:dbname=novalandia' \
 *         php /srv/mkultra/html/api/bin/normalize-fixture-smoke.php
 */

$opts = getopt('', ['write-expected', 'only:', 'help']);
if (isset($opts['help'])) {
    fwrite(STDOUT, "normalize-fixture-smoke.php [--write-expected] [--only=a,b]\n");
    exit(0);
}
$writeExpected = isset($opts['write-expected']);
$only = [];
if (!empty($opts['only']) && is_string($opts['only'])) {
    foreach (explode(',', $opts['only']) as $name) {
        $name = trim($name);
        if ($name !== '') {
            $only[$name] = true;
        }
    }
}

$apiDir = dirname(__DIR__);
$fixturesRoot = $apiDir . '/fixtures/normalize';

if (getenv('AP_DB_DSN') === false || getenv('AP_DB_DSN') === '') {
    // Peer-auth default used on mkultra prod for www-data CLI smokes.
    putenv('AP_DB_DSN=pgsql:dbname=novalandia');
    $_ENV['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
    $_SERVER['AP_DB_DSN'] = 'pgsql:dbname=novalandia';
}

if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}

require_once $apiDir . '/ap-db.php';
require_once $apiDir . '/ap-inbox.php';
require_once $apiDir . '/ap-masto-entities.php';
if (!function_exists('ap_normalize_status')) {
    require_once $apiDir . '/ap-normalize.php';
}
if (!function_exists('ap_rss_item_to_masto_status')) {
    require_once $apiDir . '/ap-rss.php';
}
// Bluesky sensitivity / quote helpers (optional if a case needs them).
if (!function_exists('ap_bsky_post_is_sensitive') && is_file($apiDir . '/ap-bsky.php')) {
    require_once $apiDir . '/ap-bsky.php';
}

/**
 * @param array<string,mixed> $status
 * @return array<string,mixed>
 */
function vaak_fixture_project(array $status): array
{
    $projectAccount = static function (?array $acct): ?array {
        if (!is_array($acct)) {
            return null;
        }
        return [
            'acct' => (string) ($acct['acct'] ?? ''),
            'username' => (string) ($acct['username'] ?? ''),
            'display_name' => (string) ($acct['display_name'] ?? ''),
        ];
    };
    $projectMedia = static function (array $media): array {
        $out = [];
        foreach ($media as $m) {
            if (!is_array($m)) {
                continue;
            }
            $out[] = [
                'type' => (string) ($m['type'] ?? ''),
                'url' => (string) ($m['url'] ?? ''),
            ];
        }
        return $out;
    };
    $projectOne = static function (array $st) use (&$projectOne, $projectAccount, $projectMedia): array {
        $content = (string) ($st['content'] ?? '');
        $plain = trim(html_entity_decode(strip_tags($content), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $plain = preg_replace('/\s+/u', ' ', $plain) ?? $plain;
        $out = [
            'uri' => (string) ($st['uri'] ?? $st['url'] ?? ''),
            'content_plain' => $plain,
            'sensitive' => !empty($st['sensitive']),
            'spoiler_text' => (string) ($st['spoiler_text'] ?? ''),
            'source' => (string) ($st['source'] ?? ''),
            'account' => $projectAccount(is_array($st['account'] ?? null) ? $st['account'] : null),
            'media' => $projectMedia(is_array($st['media_attachments'] ?? null) ? $st['media_attachments'] : []),
            'has_visible_body' => function_exists('ap_normalize_status_has_visible_body')
                ? ap_normalize_status_has_visible_body($st)
                : ($plain !== '' || !empty($st['media_attachments'])),
            'has_ask' => !empty($st['vaak_ask']) && is_array($st['vaak_ask']),
            'ask_question' => is_array($st['vaak_ask'] ?? null)
                ? (string) (($st['vaak_ask']['question'] ?? ''))
                : '',
            'has_quote' => is_array($st['quote'] ?? null)
                && is_array(($st['quote']['quoted_status'] ?? null)),
            'has_reblog' => is_array($st['reblog'] ?? null),
            'vaak_rss_feed_id' => isset($st['vaak_rss_feed_id']) ? (int) $st['vaak_rss_feed_id'] : null,
            'vaak_degraded' => !empty($st['vaak_degraded']),
            'vaak_degraded_reason' => (string) ($st['vaak_degraded_reason'] ?? ''),
        ];
        if ($out['has_quote']) {
            $out['quote_plain'] = trim(html_entity_decode(
                strip_tags((string) ($st['quote']['quoted_status']['content'] ?? '')),
                ENT_QUOTES | ENT_HTML5,
                'UTF-8'
            ));
        }
        if ($out['has_reblog'] && is_array($st['reblog'])) {
            $out['reblog'] = $projectOne($st['reblog']);
        }
        return $out;
    };
    return $projectOne($status);
}

/**
 * @param mixed $expected
 * @param mixed $actual
 * @param list<string> $path
 * @return list<string>
 */
function vaak_fixture_diff($expected, $actual, array $path = []): array
{
    $errs = [];
    $key = $path === [] ? '$' : implode('.', $path);
    if (is_array($expected) && is_array($actual)) {
        $isList = array_keys($expected) === range(0, count($expected) - 1);
        if ($isList) {
            if (count($expected) !== count($actual)) {
                $errs[] = "$key: list length " . count($actual) . ' !== ' . count($expected);
                return $errs;
            }
            foreach ($expected as $i => $ev) {
                $errs = array_merge($errs, vaak_fixture_diff($ev, $actual[$i] ?? null, [...$path, (string) $i]));
            }
            return $errs;
        }
        foreach ($expected as $k => $ev) {
            if (!array_key_exists($k, $actual)) {
                $errs[] = "$key.$k: missing";
                continue;
            }
            $errs = array_merge($errs, vaak_fixture_diff($ev, $actual[$k], [...$path, (string) $k]));
        }
        return $errs;
    }
    if ($expected !== $actual) {
        $errs[] = "$key: " . json_encode($actual, JSON_UNESCAPED_SLASHES)
            . ' !== ' . json_encode($expected, JSON_UNESCAPED_SLASHES);
    }
    return $errs;
}

/**
 * @param array<string,mixed> $meta
 * @param array<string,mixed> $input
 * @return array<string,mixed>|null
 */
function vaak_fixture_run(array $meta, array $input): ?array
{
    $entry = (string) ($meta['entry'] ?? '');
    return match ($entry) {
        'as2_note' => (static function () use ($input): ?array {
            $fallback = (string) ($input['id'] ?? '');
            $st = ap_masto_status_from_as2_note($input, $fallback);
            return is_array($st) ? ap_normalize_status($st) : null;
        })(),
        'bsky' => ap_normalize_from_bsky_post($input),
        'rss' => ap_normalize_from_rss_item($input),
        'event' => ap_normalize_from_activitypub_event($input),
        'status' => ap_normalize_status($input),
        'degraded' => ap_normalize_degraded_status($input),
        default => null,
    };
}

$dirs = glob($fixturesRoot . '/*', GLOB_ONLYDIR) ?: [];
sort($dirs);
if ($dirs === []) {
    fwrite(STDERR, "No fixtures under $fixturesRoot\n");
    exit(2);
}

$pass = 0;
$fail = 0;
$skip = 0;

foreach ($dirs as $dir) {
    $name = basename($dir);
    if ($only !== [] && !isset($only[$name])) {
        $skip++;
        continue;
    }
    $metaPath = $dir . '/meta.json';
    $inputPath = $dir . '/input.json';
    $expectedPath = $dir . '/expected.json';
    if (!is_file($metaPath) || !is_file($inputPath)) {
        fwrite(STDERR, "FAIL $name: missing meta.json or input.json\n");
        $fail++;
        continue;
    }
    $meta = json_decode((string) file_get_contents($metaPath), true);
    $input = json_decode((string) file_get_contents($inputPath), true);
    if (!is_array($meta) || !is_array($input)) {
        fwrite(STDERR, "FAIL $name: invalid JSON\n");
        $fail++;
        continue;
    }

    try {
        $status = vaak_fixture_run($meta, $input);
    } catch (Throwable $e) {
        fwrite(STDERR, "FAIL $name: exception " . $e->getMessage() . "\n");
        $fail++;
        continue;
    }
    if (!is_array($status)) {
        fwrite(STDERR, "FAIL $name: converter returned null\n");
        $fail++;
        continue;
    }
    $actual = vaak_fixture_project($status);

    if ($writeExpected || !is_file($expectedPath)) {
        $json = json_encode($actual, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . "\n";
        if (file_put_contents($expectedPath, $json) === false) {
            fwrite(STDERR, "FAIL $name: could not write expected.json\n");
            $fail++;
            continue;
        }
        fwrite(STDOUT, ($writeExpected ? 'WRITE' : 'SEED') . " $name\n");
        $pass++;
        continue;
    }

    $expected = json_decode((string) file_get_contents($expectedPath), true);
    if (!is_array($expected)) {
        fwrite(STDERR, "FAIL $name: invalid expected.json\n");
        $fail++;
        continue;
    }
    $errs = vaak_fixture_diff($expected, $actual);
    if ($errs === []) {
        fwrite(STDOUT, "PASS $name\n");
        $pass++;
    } else {
        fwrite(STDERR, "FAIL $name\n");
        foreach ($errs as $err) {
            fwrite(STDERR, "  - $err\n");
        }
        $fail++;
    }
}

fwrite(STDOUT, "done pass=$pass fail=$fail skip=$skip\n");
exit($fail > 0 ? 1 : 0);
