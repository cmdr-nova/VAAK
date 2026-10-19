<?php
declare(strict_types=1);
/** CSP headers (report-only, OAuth frame-ancestors enforced) and the violation report log. */
require dirname(__DIR__) . '/ap-csp.php';
$checks = 0;
function csp_check(bool $ok, string $label): void {
    if (!$ok) throw new RuntimeException($label);
    $GLOBALS['checks']++;
}
$tmp = sys_get_temp_dir() . '/vaak-csp-smoke-' . bin2hex(random_bytes(4));
@mkdir($tmp, 0700, true);
try {
    putenv('VAAK_CSP_ENFORCE');
    $app = ap_csp_headers('app', 'social.example', true);
    $policy = $app['Content-Security-Policy-Report-Only'] ?? '';
    csp_check(!isset($app['Content-Security-Policy']) && $policy !== '', 'app pages are report-only by default');
    foreach (["default-src 'self'", "object-src 'none'", "base-uri 'self'", "frame-ancestors 'self'", 'https://cdn.jsdelivr.net',
        'https://www.youtube-nocookie.com', "worker-src 'self' blob:", 'report-uri /vaak/?csp_report=1', 'report-to vaak-csp'] as $needle) {
        csp_check(str_contains($policy, $needle), 'policy has ' . $needle);
    }
    csp_check(!isset($app['X-Frame-Options']), 'app keeps the entry point X-Frame-Options');
    csp_check($app['Reporting-Endpoints'] === 'vaak-csp="https://social.example/vaak/?csp_report=1"', 'reporting endpoint');
    csp_check(!isset(ap_csp_headers('app', "evil.example\r\nX-Injected: 1", true)['Reporting-Endpoints']), 'odd hosts never reach a header');
    csp_check(!isset(ap_csp_headers('app', 'social.example', false)['Reporting-Endpoints']), 'no Reporting API endpoint without https');

    $oauth = ap_csp_headers('oauth', 'social.example', true);
    csp_check($oauth['X-Frame-Options'] === 'DENY', 'authorize page DENY');
    csp_check(str_starts_with($oauth['Content-Security-Policy'], "frame-ancestors 'none'"), 'authorize page enforces frame-ancestors none');
    csp_check(str_contains($oauth['Content-Security-Policy-Report-Only'], "frame-ancestors 'none'"), 'authorize report-only policy matches');

    putenv('VAAK_CSP_ENFORCE=1');
    $enforced = ap_csp_headers('app', 'social.example', true);
    csp_check(isset($enforced['Content-Security-Policy']) && !isset($enforced['Content-Security-Policy-Report-Only']), 'VAAK_CSP_ENFORCE=1 enforces');
    $enforcedOauth = ap_csp_headers('oauth', 'social.example', true);
    csp_check(str_contains($enforcedOauth['Content-Security-Policy'], "frame-ancestors 'none'") && str_contains($enforcedOauth['Content-Security-Policy'], "default-src 'self'"), 'enforced authorize policy keeps frame-ancestors none');
    putenv('VAAK_CSP_ENFORCE=0');
    csp_check(!ap_csp_enforced(), '0 means report-only');
    putenv('VAAK_CSP_ENFORCE');

    // URL reduction: no query strings, fragments, userinfo or odd bytes.
    csp_check(ap_csp_report_url('https://user:pw@social.example:8443/oauth/authorize?code=secret&state=x#frag') === 'https://social.example:8443/oauth/authorize', 'query and userinfo stripped');
    csp_check(ap_csp_report_url('inline') === 'inline' && ap_csp_report_url('eval') === 'eval', 'keywords kept');
    csp_check(ap_csp_report_url('data:text/html;base64,PHNjcmlwdD4=') === 'data' && ap_csp_report_url('blob:https://x/1') === 'blob', 'scheme-only URLs');
    csp_check(ap_csp_report_url('chrome-extension://abc/inject.js') === 'chrome-extension', 'extension scheme only');
    csp_check(strlen(ap_csp_report_url('https://social.example/' . str_repeat('a', 500))) === 200, 'truncated');
    csp_check(ap_csp_report_url(['x']) === '' && ap_csp_report_url('not a url at all') === 'other', 'garbage');

    // Legacy report-uri body.
    $legacy = json_encode(['csp-report' => [
        'document-uri' => 'https://social.example/vaak/?view=search&q=private+words',
        'violated-directive' => "script-src-elem 'self' 'unsafe-inline'",
        'effective-directive' => 'script-src-elem',
        'blocked-uri' => 'https://evil.example/x.js?uid=1',
        'source-file' => 'https://social.example/vaak/',
        'line-number' => 12,
        'script-sample' => 'alert(document.cookie)',
        'disposition' => 'report',
    ]]);
    $r = ap_csp_parse_reports((string) $legacy);
    csp_check(count($r) === 1 && $r[0]['directive'] === 'script-src-elem' && $r[0]['blocked'] === 'https://evil.example/x.js', 'legacy report parsed');
    csp_check($r[0]['document'] === 'https://social.example/vaak/' && $r[0]['line'] === 12 && $r[0]['disposition'] === 'report', 'legacy document path only');
    csp_check(!str_contains(json_encode($r), 'cookie') && !str_contains(json_encode($r), 'private'), 'samples and queries never kept');

    // Reporting API batch: only csp-violation entries, at most 10.
    $batch = [['type' => 'deprecation', 'body' => ['id' => 'x']]];
    for ($i = 0; $i < 14; $i++) {
        $batch[] = ['type' => 'csp-violation', 'url' => 'https://social.example/@a', 'body' => [
            'documentURL' => 'https://social.example/@a?ref=1', 'effectiveDirective' => 'img-src', 'blockedURL' => 'http://insecure.example/a.png',
            'disposition' => 'enforce', 'lineNumber' => $i, 'sample' => 'secret']];
    }
    $r = ap_csp_parse_reports((string) json_encode($batch));
    csp_check(count($r) === 10 && $r[0]['directive'] === 'img-src' && $r[0]['blocked'] === 'http://insecure.example/a.png' && $r[0]['disposition'] === 'enforce', 'Reporting API batch parsed and capped');
    csp_check(ap_csp_parse_reports('nope') === [] && ap_csp_parse_reports('{"x":1}') === [], 'junk bodies ignored');
    csp_check(ap_csp_parse_reports('{"csp-report":{"violated-directive":"<script>"}}')[0]['directive'] === 'other', 'directive names only');

    // Log lines are JSON with exactly the reduced fields.
    $log = $tmp . '/csp.log';
    ap_csp_report_log($r, $log);
    $lines = file($log, FILE_IGNORE_NEW_LINES);
    $first = json_decode($lines[0], true);
    csp_check(count($lines) === 10 && array_keys($first) === ['t', 'disposition', 'directive', 'blocked', 'document', 'source', 'line'], 'log lines');
    file_put_contents($log, str_repeat('x', 1048576));
    ap_csp_report_log($r, $log);
    csp_check(is_file($log . '.1') && count(file($log)) === 10, 'log rotates at 1 MiB');

    // File-backed overall budget when Redis is absent.
    $ok = 0;
    $minute = intdiv(time(), 60);
    for ($i = 0; $i < 305; $i++) {
        $ok += ap_csp_report_rate_ok('203.0.113.9', $tmp) ? 1 : 0;
    }
    // A minute boundary mid-loop resets the counter; only then may more pass.
    csp_check($ok === 300 || intdiv(time(), 60) !== $minute, 'overall budget of 300 a minute');
} catch (Throwable $e) {
    fwrite(STDERR, 'csp-smoke failed: ' . $e->getMessage() . "\n");
    exit(1);
} finally {
    foreach (glob($tmp . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmp);
}
fwrite(STDOUT, "csp-smoke ok checks={$checks}\n");
