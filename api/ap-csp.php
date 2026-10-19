<?php
declare(strict_types=1);

/**
 * Content-Security-Policy for VAAK's HTML entry points.
 *
 * The policy ships in Report-Only mode: browsers keep loading everything and
 * POST a report for each load the policy would have blocked. The reports land
 * in a small log (see docs/10.19-csp-report-only.md) so the policy can be
 * tightened against real traffic before VAAK_CSP_ENFORCE=1 turns it on.
 *
 * The OAuth authorize page is the exception: framing it is plain clickjacking
 * of the password form, so `frame-ancestors 'none'` is enforced there today.
 *
 * Inventory behind the allow-lists (keep in step with the pages):
 *  - scripts: same-origin assets (/api/assets/hls-*.min.js), many inline
 *    <script> blocks and on*= handlers, qrcode-generator from cdn.jsdelivr.net
 *    (2FA setup);
 *  - styles: inline <style> blocks and style= attributes, Phosphor icon CSS
 *    from cdn.jsdelivr.net (which also serves its icon fonts);
 *  - images and media: remote avatars, headers, attachments and link-preview
 *    images from any fediverse host, the R2 media CDN, cdn.bsky.app and
 *    video.bsky.app, data: SVG backgrounds and blob: upload previews;
 *  - connect: same-origin fetch/SSE, plus hls.js pulling playlists and
 *    segments from remote video hosts (Bluesky, PeerTube);
 *  - workers: /vaak/sw.js and hls.js's blob: worker;
 *  - frames: youtube-nocookie.com click-to-play embeds.
 */

/** True when VAAK_CSP_ENFORCE asks for the policy to be enforced. */
function ap_csp_enforced(): bool
{
    $raw = getenv('VAAK_CSP_ENFORCE');
    return $raw !== false && in_array(strtolower(trim((string) $raw)), ['1', 'true', 'on', 'yes'], true);
}

/** Path browsers POST violation reports to (handled by vaak/index.php). */
function ap_csp_report_path(): string
{
    return '/vaak/?csp_report=1';
}

/**
 * The page policy. `frame-ancestors` is 'self' to match X-Frame-Options:
 * SAMEORIGIN, or 'none' for pages that must never be framed.
 */
function ap_csp_policy(bool $frameNone = false): string
{
    $directives = [
        'default-src' => "'self'",
        'script-src' => "'self' 'unsafe-inline' https://cdn.jsdelivr.net",
        'style-src' => "'self' 'unsafe-inline' https://cdn.jsdelivr.net",
        'font-src' => "'self' data: https://cdn.jsdelivr.net",
        'img-src' => "'self' data: blob: https:",
        'media-src' => "'self' data: blob: https:",
        'connect-src' => "'self' https:",
        'frame-src' => "'self' https://www.youtube-nocookie.com https://www.youtube.com",
        'worker-src' => "'self' blob:",
        'manifest-src' => "'self'",
        'object-src' => "'none'",
        'base-uri' => "'self'",
        'form-action' => "'self' https:",
        'frame-ancestors' => $frameNone ? "'none'" : "'self'",
        'report-uri' => ap_csp_report_path(),
        'report-to' => 'vaak-csp',
    ];
    $out = [];
    foreach ($directives as $name => $value) {
        $out[] = $name . ' ' . $value;
    }
    return implode('; ', $out);
}

/**
 * Headers for one response. `app` is every ordinary HTML page; `oauth` is
 * the OAuth authorize page (and its interstitials), which also enforces
 * frame-ancestors 'none' and X-Frame-Options: DENY.
 *
 * @return array<string,string>
 */
function ap_csp_headers(string $context = 'app', ?string $host = null, bool $https = true): array
{
    $oauth = $context === 'oauth';
    $policy = ap_csp_policy($oauth);
    $headers = [];
    if (ap_csp_enforced()) {
        $headers['Content-Security-Policy'] = $policy;
    } else {
        $headers['Content-Security-Policy-Report-Only'] = $policy;
        if ($oauth) {
            $headers['Content-Security-Policy'] = "frame-ancestors 'none'; report-uri " . ap_csp_report_path() . '; report-to vaak-csp';
        }
    }
    if ($oauth) {
        $headers['X-Frame-Options'] = 'DENY';
    }
    // Reporting API (Chrome) wants an absolute https endpoint; report-uri
    // covers Firefox and Safari. Hosts outside a plain name[:port] are ignored.
    $host = (string) $host;
    if ($https && preg_match('/^[A-Za-z0-9.-]{1,253}(?::\d{1,5})?$/', $host)) {
        $headers['Reporting-Endpoints'] = 'vaak-csp="https://' . strtolower($host) . ap_csp_report_path() . '"';
    }
    return $headers;
}

/** Send the CSP headers for this request (no-op once output has started). */
function ap_csp_send_headers(string $context = 'app'): void
{
    if (headers_sent()) {
        return;
    }
    $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
    foreach (ap_csp_headers($context, (string) ($_SERVER['HTTP_HOST'] ?? ''), $https) as $name => $value) {
        header($name . ': ' . $value);
    }
}

/**
 * Reduce a report URL to scheme://host[:port]/path. Query strings and
 * fragments can carry OAuth codes, search terms or tokens, so they never
 * reach the log. CSP keywords (inline, eval, data, blob, …) pass through.
 */
function ap_csp_report_url(mixed $raw): string
{
    if (!is_string($raw) || trim($raw) === '') {
        return '';
    }
    $raw = trim($raw);
    if (preg_match('/^[a-z][a-z-]{0,30}$/i', $raw)) {
        return strtolower($raw);
    }
    $parts = parse_url($raw);
    $scheme = strtolower((string) ($parts['scheme'] ?? ''));
    if (!is_array($parts) || $scheme === '') {
        return 'other';
    }
    if (!in_array($scheme, ['http', 'https', 'ws', 'wss'], true)) {
        // data:, blob:, chrome-extension:, moz-extension:, … — scheme only.
        return preg_match('/^[a-z][a-z0-9+.-]{0,30}$/', $scheme) ? $scheme : 'other';
    }
    $host = strtolower((string) ($parts['host'] ?? ''));
    if ($host === '' || !preg_match('/^[a-z0-9.\-\[\]:]+$/', $host)) {
        return 'other';
    }
    $url = $scheme . '://' . $host . (isset($parts['port']) ? ':' . (int) $parts['port'] : '') . (string) ($parts['path'] ?? '');
    return substr(preg_replace('/[^\x21-\x7e]/', '', $url) ?? '', 0, 200);
}

/**
 * Normalize a report body (legacy application/csp-report or Reporting API
 * application/reports+json) into at most 10 log records.
 *
 * @return list<array{disposition:string,directive:string,blocked:string,document:string,source:string,line:int}>
 */
function ap_csp_parse_reports(string $body): array
{
    $doc = json_decode($body, true);
    if (!is_array($doc)) {
        return [];
    }
    $bodies = [];
    if (is_array($doc['csp-report'] ?? null)) {
        $bodies[] = $doc['csp-report'];
    } elseif (array_is_list($doc)) {
        foreach ($doc as $item) {
            if (is_array($item) && ($item['type'] ?? '') === 'csp-violation' && is_array($item['body'] ?? null)) {
                $bodies[] = $item['body'];
            }
        }
    }
    $pick = static function (array $b, array $keys): mixed {
        foreach ($keys as $k) {
            if (isset($b[$k])) {
                return $b[$k];
            }
        }
        return null;
    };
    $out = [];
    foreach (array_slice($bodies, 0, 10) as $b) {
        $directive = strtolower(trim((string) $pick($b, ['effectiveDirective', 'effective-directive', 'violated-directive'])));
        // violated-directive may carry the whole directive with its sources.
        $directive = explode(' ', $directive)[0];
        $disposition = strtolower((string) $pick($b, ['disposition']));
        $out[] = [
            'disposition' => in_array($disposition, ['report', 'enforce'], true) ? $disposition : '',
            'directive' => preg_match('/^[a-z-]{1,40}$/', $directive) ? $directive : 'other',
            'blocked' => ap_csp_report_url($pick($b, ['blockedURL', 'blocked-uri'])),
            'document' => ap_csp_report_url($pick($b, ['documentURL', 'document-uri'])),
            'source' => ap_csp_report_url($pick($b, ['sourceFile', 'source-file'])),
            'line' => max(0, min(10000000, (int) $pick($b, ['lineNumber', 'line-number']))),
        ];
    }
    return $out;
}

/**
 * Per-minute budget: 20 report requests per client and 300 overall. Clients
 * are counted by a hash of their address, which is never logged. Without
 * Redis only the overall budget applies, counted in a temp file.
 */
function ap_csp_report_rate_ok(string $client, ?string $dir = null): bool
{
    $minute = intdiv(time(), 60);
    if (function_exists('ap_redis_rate_count')) {
        $all = ap_redis_rate_count('csp-report:all:' . $minute, 60);
        if ($all !== null) {
            $mine = ap_redis_rate_count('csp-report:' . hash('sha256', $client) . ':' . $minute, 60);
            return $all <= 300 && ($mine === null || $mine <= 20);
        }
    }
    $path = rtrim($dir ?? sys_get_temp_dir(), '/') . '/vaak-csp-report-rate';
    $fh = @fopen($path, 'c+');
    if ($fh === false) {
        return true;
    }
    try {
        flock($fh, LOCK_EX);
        [$at, $count] = array_map('intval', explode(' ', trim((string) stream_get_contents($fh)) . ' 0 0'));
        $count = $at === $minute ? $count + 1 : 1;
        ftruncate($fh, 0);
        rewind($fh);
        fwrite($fh, $minute . ' ' . $count);
        return $count <= 300;
    } finally {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

function ap_csp_report_log_path(): string
{
    $env = getenv('VAAK_CSP_REPORT_LOG');
    return is_string($env) && $env !== '' ? $env : '/var/log/mkultra/ap-csp.log';
}

/** Append records as JSON lines; the file rotates once at 1 MiB. */
function ap_csp_report_log(array $records, ?string $path = null): void
{
    $path ??= ap_csp_report_log_path();
    if ($records === []) {
        return;
    }
    $dir = dirname($path);
    if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
        return;
    }
    if (is_file($path) && (int) @filesize($path) >= 1048576) {
        @unlink($path . '.1');
        @rename($path, $path . '.1');
    }
    $lines = '';
    foreach ($records as $r) {
        $lines .= json_encode(['t' => gmdate('Y-m-d\TH:i:s\Z')] + $r, JSON_UNESCAPED_SLASHES) . "\n";
    }
    @file_put_contents($path, $lines, FILE_APPEND | LOCK_EX);
}

/** POST /vaak/?csp_report=1 — log violation reports, always answer 204. */
function ap_csp_report_handle(): void
{
    header('Cache-Control: no-store');
    if (strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET')) !== 'POST') {
        http_response_code(405);
        header('Allow: POST');
        exit;
    }
    http_response_code(204);
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 65536
        || !ap_csp_report_rate_ok((string) ($_SERVER['REMOTE_ADDR'] ?? ''))) {
        exit;
    }
    $body = (string) file_get_contents('php://input', false, null, 0, 65536);
    ap_csp_report_log(ap_csp_parse_reports($body));
    exit;
}
