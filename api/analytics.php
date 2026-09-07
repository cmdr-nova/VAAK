<?php
/**
 * Server Analytics API
 * Parses nginx/Caddy access logs to provide real website statistics.
 * Admin-only (VAAK session).
 */

header('Content-Type: application/json');
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');
header('X-Content-Type-Options: nosniff');

require_once __DIR__ . '/ap-auth.php';
ap_auth_bootstrap();
$analyticsUser = ap_auth_current_user();
if ($analyticsUser === null || !ap_auth_is_admin($analyticsUser)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Admin login required']);
    exit;
}

// Configuration
$logFile = '/var/log/nginx/access.log';
$logFileYesterday = '/var/log/nginx/access.log.1';

// Bot patterns to exclude from analytics
$botPatterns = [
    'bot', 'Bot', 'crawler', 'spider', 'Spider', 'GPTBot', 'Claude-Web',
    'facebookexternalhit', 'Twitterbot', 'LinkedInBot', 'Slackbot',
    'Discordbot', 'TelegramBot', 'WhatsApp', 'curl', 'wget', 'python-requests'
];

// Paths to exclude from page view analytics
$excludePaths = [
    '/api/', '/assets/', '/images/', '/img/', '/css/', '/js/', '/fonts/',
    '.jpg', '.jpeg', '.png', '.gif', '.webp', '.ico', '.svg',
    '.css', '.js', '.woff', '.woff2', '.ttf', '.eot',
    '/admin', '/admin-panel', '/inbox', '/nodeinfo'
];

/**
 * Check if a user agent is a bot
 */
function isBot($userAgent) {
    global $botPatterns;
    foreach ($botPatterns as $pattern) {
        if (stripos($userAgent, $pattern) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Check if a path should be excluded from analytics
 */
function shouldExcludePath($path) {
    global $excludePaths;
    foreach ($excludePaths as $exclude) {
        if (strpos($path, $exclude) !== false) {
            return true;
        }
    }
    return false;
}

/**
 * Parse a single nginx log line
 */
function parseLogLine($line) {
    // Nginx combined log format regex
    $pattern = '/^(\S+) \S+ \S+ \[([^\]]+)\] "([^"]*)" (\d+) (\d+) "([^"]*)" "([^"]*)"/';

    if (preg_match($pattern, $line, $matches)) {
        return [
            'ip' => $matches[1],
            'timestamp' => strtotime($matches[2]),
            'request' => $matches[3],
            'status' => (int)$matches[4],
            'bytes' => (int)$matches[5],
            'referrer' => $matches[6],
            'user_agent' => $matches[7]
        ];
    }

    return null;
}

/**
 * Extract path from HTTP request
 */
function extractPath($request) {
    if (preg_match('/^[A-Z]+ ([^ ]+)/', $request, $matches)) {
        $path = parse_url($matches[1], PHP_URL_PATH);
        return $path ?: '/';
    }
    return '/';
}

/**
 * Determine if user agent is mobile
 */
function isMobile($userAgent) {
    return preg_match('/(android|webos|iphone|ipad|ipod|blackberry|mobile)/i', $userAgent);
}

/**
 * Get browser name from user agent
 */
function getBrowser($userAgent) {
    if (stripos($userAgent, 'Firefox') !== false) return 'Firefox';
    if (stripos($userAgent, 'Edg') !== false) return 'Edge';
    if (stripos($userAgent, 'Chrome') !== false) return 'Chrome';
    if (stripos($userAgent, 'Safari') !== false) return 'Safari';
    if (stripos($userAgent, 'Opera') !== false || stripos($userAgent, 'OPR') !== false) return 'Opera';
    return 'Other';
}

/**
 * Parse logs and generate analytics
 */
function generateAnalytics() {
    global $logFile, $logFileYesterday;

    $now = time();
    $todayStart = strtotime('today');
    $weekStart = strtotime('-7 days');
    $monthStart = strtotime('-30 days');

    $stats = [
        'today_visitors' => [],
        'today_views' => 0,
        'week_visitors' => [],
        'week_views' => 0,
        'month_visitors' => [],
        'month_views' => 0,
        'popular_pages' => [],
        'referrers' => [],
        'browsers' => [],
        'mobile_count' => 0,
        'desktop_count' => 0,
        'total_processed' => 0,
        'bots_filtered' => 0
    ];

    // Read both current and yesterday's log
    $logFiles = [$logFile];
    if (file_exists($logFileYesterday)) {
        $logFiles[] = $logFileYesterday;
    }

    foreach ($logFiles as $file) {
        if (!file_exists($file) || !is_readable($file)) {
            continue;
        }

        $handle = fopen($file, 'r');
        if (!$handle) continue;

        // Read from the end for recent entries (more efficient)
        fseek($handle, -min(filesize($file), 1024 * 1024 * 2), SEEK_END); // Last 2MB
        fgets($handle); // Skip partial line

        while (($line = fgets($handle)) !== false) {
            $stats['total_processed']++;

            $entry = parseLogLine($line);
            if (!$entry) continue;

            // Filter out bots
            if (isBot($entry['user_agent'])) {
                $stats['bots_filtered']++;
                continue;
            }

            // Only count successful requests
            if ($entry['status'] != 200 && $entry['status'] != 304) {
                continue;
            }

            $path = extractPath($entry['request']);

            // Skip excluded paths
            if (shouldExcludePath($path)) {
                continue;
            }

            $timestamp = $entry['timestamp'];
            $ip = $entry['ip'];

            // Count by time period
            if ($timestamp >= $todayStart) {
                $stats['today_visitors'][$ip] = true;
                $stats['today_views']++;
            }

            if ($timestamp >= $weekStart) {
                $stats['week_visitors'][$ip] = true;
                $stats['week_views']++;

                // Track popular pages (last 7 days)
                if (!isset($stats['popular_pages'][$path])) {
                    $stats['popular_pages'][$path] = 0;
                }
                $stats['popular_pages'][$path]++;

                // Track referrers
                if ($entry['referrer'] != '-' && !empty($entry['referrer'])) {
                    $host = parse_url($entry['referrer'], PHP_URL_HOST);
                    if ($host && $host != 'mkultra.monster') {
                        if (!isset($stats['referrers'][$host])) {
                            $stats['referrers'][$host] = 0;
                        }
                        $stats['referrers'][$host]++;
                    }
                }

                // Track browsers
                $browser = getBrowser($entry['user_agent']);
                if (!isset($stats['browsers'][$browser])) {
                    $stats['browsers'][$browser] = 0;
                }
                $stats['browsers'][$browser]++;

                // Track mobile vs desktop
                if (isMobile($entry['user_agent'])) {
                    $stats['mobile_count']++;
                } else {
                    $stats['desktop_count']++;
                }
            }

            if ($timestamp >= $monthStart) {
                $stats['month_visitors'][$ip] = true;
                $stats['month_views']++;
            }
        }

        fclose($handle);
    }

    // Sort popular pages
    arsort($stats['popular_pages']);
    $stats['popular_pages'] = array_slice($stats['popular_pages'], 0, 10, true);

    // Sort referrers
    arsort($stats['referrers']);
    $stats['referrers'] = array_slice($stats['referrers'], 0, 10, true);

    // Sort browsers
    arsort($stats['browsers']);

    // Format output
    $totalDevices = $stats['mobile_count'] + $stats['desktop_count'];

    return [
        'success' => true,
        'visitors' => [
            'today' => count($stats['today_visitors']),
            'week' => count($stats['week_visitors']),
            'month' => count($stats['month_visitors'])
        ],
        'page_views' => [
            'today' => $stats['today_views'],
            'week' => $stats['week_views'],
            'month' => $stats['month_views']
        ],
        'popular_pages' => array_map(function($path, $views) {
            return [
                'path' => $path,
                'title' => ucfirst(trim($path, '/')),
                'views' => $views
            ];
        }, array_keys($stats['popular_pages']), $stats['popular_pages']),
        'referrers' => array_map(function($host, $visits) {
            return [
                'source' => $host,
                'visits' => $visits
            ];
        }, array_keys($stats['referrers']), $stats['referrers']),
        'browsers' => $stats['browsers'],
        'devices' => [
            'mobile' => $stats['mobile_count'],
            'desktop' => $stats['desktop_count'],
            'mobile_percent' => $totalDevices > 0 ? round(($stats['mobile_count'] / $totalDevices) * 100, 1) : 0,
            'desktop_percent' => $totalDevices > 0 ? round(($stats['desktop_count'] / $totalDevices) * 100, 1) : 0
        ],
        'meta' => [
            'total_processed' => $stats['total_processed'],
            'bots_filtered' => $stats['bots_filtered'],
            'generated_at' => date('Y-m-d H:i:s')
        ]
    ];
}

// Main execution
try {
    $analytics = generateAnalytics();
    echo json_encode($analytics, JSON_PRETTY_PRINT);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => 'Unable to generate analytics'
    ]);
}
