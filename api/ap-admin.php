<?php
/**
 * VAAK — ActivityPub web client (+ admin surfaces for is_admin).
 * PHP sessions via /vaak login (front controller boots this file when logged in).
 * /admin redirects to /vaak. api/ is rsync-excluded.
 * Remote media: URL previews only (browser loads them); never stored as bytes.
 */
declare(strict_types=1);

header('X-Content-Type-Options: nosniff');
header('Cache-Control: no-store');
header('X-Robots-Tag: noindex, nofollow');

require_once __DIR__ . '/ap-db.php';
require_once __DIR__ . '/ap-auth.php';
require_once __DIR__ . '/ap-collections.php'; // Library → Collections (starter packs)
require_once __DIR__ . '/ap-lists.php'; // Library → Lists (private follow subsets)
require_once __DIR__ . '/ap-bites.php'; // Wafrn-compatible Bite
require_once __DIR__ . '/ap-reports.php'; // Moderation → Reports (Flag)
require_once __DIR__ . '/ap-relays.php'; // Admin → Relays (Mastodon-compatible)
require_once __DIR__ . '/ap-import-export.php'; // You → Import/Export + Move/aliases
require_once __DIR__ . '/ap-r2.php'; // avatar URL helpers / async warm
require_once __DIR__ . '/ap-masto-entities.php'; // status ids, favourites/bookmarks
require_once __DIR__ . '/ap-bookmark-folders.php'; // VAAK bookmark folders (API-safe overlay)
require_once __DIR__ . '/ap-queue.php'; // posting queue / scheduler
require_once __DIR__ . '/ap-sl-link.php'; // Profile → Link Second Life avatar
require_once __DIR__ . '/ap-featured.php'; // Profile → Featured accounts (endorsements)
require_once __DIR__ . '/ap-notices.php'; // Local-only operator notices
require_once __DIR__ . '/ap-discuss.php'; // Local-only discussion forums
require_once __DIR__ . '/ap-webpush.php'; // Browser + Ice Cubes Web Push
require_once __DIR__ . '/ap-bsky.php'; // Phase A Bluesky tab (opt-in, feature-flagged)
// Quote helpers (ap_quote_target_pack, ap_fetch_as2_object, local note docs, etc.)
if (!defined('AP_INBOX_LIB_ONLY')) {
    define('AP_INBOX_LIB_ONLY', true);
}
require_once __DIR__ . '/ap-inbox.php';

const LOCAL_ACTOR = 'https://mkultra.monster/users/cmdr_nova';

// --- Session gate (login at /vaak/) ---
// Never auto-login from HTTP Basic — that ignored the password and bound cmdr_nova.
ap_auth_bootstrap();
$vaakUser = ap_auth_current_user();
if ($vaakUser === null) {
    $loginQs = '/vaak/?mode=login';
    $returnView = preg_replace('/[^a-z_]/', '', (string) ($_GET['view'] ?? ''));
    if ($returnView !== '' && $returnView !== 'home') {
        $loginQs .= '&next=' . rawurlencode($returnView);
    }
    header('Location: ' . $loginQs, true, 302);
    exit;
}
$vaakIsAdmin = ap_auth_is_admin($vaakUser);
$vaakOwnerId = (int) ($vaakUser['id'] ?? 0);
$GLOBALS['vaak_user'] = $vaakUser;
$GLOBALS['vaak_is_admin'] = $vaakIsAdmin;
$GLOBALS['vaak_owner_id'] = $vaakOwnerId;

$vaakActorKey = (string) ($vaakUser['actor_key'] ?? 'cmdr_nova');
$vaakActorId = rtrim((string) ($vaakUser['actor_id'] ?? ('https://mkultra.monster/users/' . $vaakActorKey)), '/');
$vaakUsername = (string) ($vaakUser['username'] ?? $vaakActorKey);
$vaakHandle = '@' . $vaakUsername . '@mkultra.monster';
$GLOBALS['vaak_actor_key'] = $vaakActorKey;
$GLOBALS['vaak_actor_id'] = $vaakActorId;
if (function_exists('ap_request_actor_set')) {
    ap_request_actor_set($vaakActorKey);
}

// Dedicated log — php-fpm often has no catch_workers_output / error_log path.
@ini_set('log_errors', '1');
if (is_dir('/var/log/mkultra') && (is_writable('/var/log/mkultra') || @touch('/var/log/mkultra/ap-admin.log'))) {
    @ini_set('error_log', '/var/log/mkultra/ap-admin.log');
}

// Avoid blank white screens: exceptions + fatals under federation write traffic.
$adminRenderHiccup = static function (string $msg): void {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }
    $msg = trim($msg);
    if ($msg === '') {
        $msg = '(no error message — see /var/log/mkultra/ap-admin.log)';
    }
    $safe = htmlspecialchars($msg, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
        . '<title>Admin hiccup</title></head>'
        . '<body style="margin:0;font-family:system-ui,sans-serif;background:#0a0a0a;color:#eee">'
        . '<main style="max-width:36rem;margin:3rem auto;padding:0 1.25rem">'
        . '<div style="border:1px solid #333;border-radius:12px;padding:1.35rem;background:#121212">'
        . '<h1 style="margin-top:0;font-size:1.25rem">Admin hiccup</h1>'
        . '<p>This page failed to render — often a brief database contention or worker error. '
        . '<a href="/vaak/?view=home" style="color:#7ee0ff">Retry Home</a> · '
        . '<a href="javascript:location.reload()" style="color:#7ee0ff">Reload</a></p>'
        . '<pre style="color:#bbb;font-size:.78rem;white-space:pre-wrap;overflow:auto;background:#0a0a0a;padding:.75rem;border-radius:8px;border:1px solid #2a2a2a">' . $safe . '</pre>'
        . '</div></main></body></html>';
};
set_exception_handler(static function (Throwable $e) use ($adminRenderHiccup): void {
    $detail = get_class($e)
        . ': ' . ($e->getMessage() !== '' ? $e->getMessage() : '(empty message)')
        . "\n" . $e->getFile() . ':' . $e->getLine()
        . "\n" . $e->getTraceAsString();
    error_log('[ap-admin] ' . str_replace("\n", ' | ', $detail));
    @file_put_contents(
        '/var/log/mkultra/ap-admin.log',
        '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $detail . "\n\n",
        FILE_APPEND | LOCK_EX
    );
    $adminRenderHiccup(
        get_class($e) . ': ' . ($e->getMessage() !== '' ? $e->getMessage() : '(empty message)')
        . "\n" . $e->getFile() . ':' . $e->getLine()
    );
    exit;
});
register_shutdown_function(static function () use ($adminRenderHiccup): void {
    $err = error_get_last();
    if ($err === null) {
        return;
    }
    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array((int) ($err['type'] ?? 0), $fatalTypes, true)) {
        return;
    }
    // If something already printed a full page, don't clobber it.
    if (headers_sent() && (ob_get_length() ?: 0) > 200) {
        return;
    }
    $msg = (string) ($err['message'] ?? 'Fatal error');
    if ($msg === '') {
        $msg = 'Fatal error (empty message)';
    }
    $file = (string) ($err['file'] ?? '');
    $line = (int) ($err['line'] ?? 0);
    $detail = 'fatal: ' . $msg . ($file !== '' ? ' @ ' . $file . ':' . $line : '');
    error_log('[ap-admin] ' . $detail);
    @file_put_contents(
        '/var/log/mkultra/ap-admin.log',
        '[' . gmdate('Y-m-d H:i:s') . " UTC] " . $detail . "\n\n",
        FILE_APPEND | LOCK_EX
    );
    $adminRenderHiccup($msg . ($file !== '' ? "\n" . $file . ':' . $line : ''));
});

$notice = null;
$error = null;
$view = preg_replace('/[^a-z_]/', '', (string) ($_GET['view'] ?? 'home')) ?: 'home';
$composerForceOpen = false;
// Legacy ?view=compose → Your posts + open floating composer
if ($view === 'compose') {
    $composerForceOpen = true;
    $view = 'outbox';
}
// Legacy muted_words admin view → Profile (per-user mutes / words / personal blocks)
if ($view === 'muted_words') {
    $view = 'profile';
}
// Admin-only surfaces (Guestbook / Support / Analytics / Moderation / …)
$vaakAdminOnlyViews = [
    'moderation', 'blocks', 'relays', 'stats', 'invites', 'users', 'policies',
    'guestbook', 'support', 'analytics',
];
// Security is under You for every account (own OAuth tokens / password).
if (in_array($view, $vaakAdminOnlyViews, true) && !$vaakIsAdmin) {
    $error = 'Admin only.';
    $view = 'home';
}

// CSV downloads (basicauth already protects /admin)
if ($view === 'import_export' && isset($_GET['export'])) {
    ap_ie_handle_export(preg_replace('/[^a-z_]/', '', (string) $_GET['export']) ?: '');
}

/**
 * AI credentials for alt-text (OpenAI-compatible Chat Completions vision).
 * - Any user: only their own encrypted Profile key (+ optional ai_api_root / ai_model)
 * - Host /etc/mkultra/xai.env: cmdr_nova only (never other admins / invitees)
 * Not request-static — FPM workers must not leak the host key across users.
 *
 * @return array{api_key:string,model:string,api_root:string,source:string,prefer_chat:bool}
 */
function admin_xai_config(): array
{
    $apiKey = '';
    $source = '';
    $model = '';
    $apiRoot = '';
    $userRoot = '';
    $userModel = '';

    $user = $GLOBALS['vaak_user'] ?? null;
    // Derive host-key authorization from the authenticated user row itself.
    // Request globals can be temporarily switched by federation helpers and
    // must never decide who may read the operator-only environment key.
    $actorKey = strtolower(trim((string) (is_array($user) ? ($user['actor_key'] ?? '') : '')));
    // Host xAI key is operator-only — not shared with other is_admin rows
    $mayUseHostXai = ($actorKey === 'cmdr_nova');

    if (is_array($user)) {
        if (function_exists('ap_auth_user_get_xai_key')) {
            $uk = ap_auth_user_get_xai_key($user);
            if (is_string($uk) && $uk !== '') {
                $apiKey = $uk;
                $source = 'user';
            }
        }
        // Prefer fresh row fields for root/model (session may be stale)
        $fresh = $user;
        $uid = (int) ($user['id'] ?? 0);
        if ($uid > 0 && function_exists('ap_auth_user_by_id')) {
            $reread = ap_auth_user_by_id($uid);
            if (is_array($reread)) {
                $fresh = $reread;
            }
        }
        $userRoot = rtrim(trim((string) ($fresh['ai_api_root'] ?? '')), '/');
        $userModel = trim((string) ($fresh['ai_model'] ?? ''));
    }

    // Host env key: cmdr_nova only (never invitees, never other accounts)
    $envKey = '';
    $envModel = '';
    $envRoot = '';
    if ($mayUseHostXai) {
        $envKey = getenv('XAI_API_KEY') ?: '';
        $envModel = getenv('XAI_ALT_MODEL') ?: getenv('XAI_MODEL') ?: '';
        $envRoot = getenv('XAI_API_ROOT') ?: '';
        $envFile = '/etc/mkultra/xai.env';
        if (is_readable($envFile)) {
            foreach (file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
                $line = trim($line);
                if ($line === '' || str_starts_with($line, '#') || !str_contains($line, '=')) {
                    continue;
                }
                [$k, $v] = explode('=', $line, 2);
                $k = trim($k);
                $v = trim($v, " \t\"'");
                if ($k === 'XAI_API_KEY' && $v !== '') {
                    $envKey = $v;
                } elseif ($k === 'XAI_ALT_MODEL' && $v !== '') {
                    $envModel = $v;
                } elseif ($k === 'XAI_API_ROOT' && $v !== '') {
                    $envRoot = rtrim($v, '/');
                }
            }
        }
        if ($apiKey === '' && $envKey !== '') {
            $apiKey = $envKey;
            $source = 'env';
        }
    }

    if ($source === 'user') {
        if ($userRoot !== '') {
            $apiRoot = $userRoot;
        } else {
            // Personal key without custom root → OpenAI-compatible default
            $apiRoot = 'https://api.openai.com/v1';
        }
        if ($userModel !== '') {
            $model = $userModel;
        } else {
            $model = 'gpt-4o-mini';
        }
    } elseif ($source === 'env') {
        $apiRoot = $envRoot !== '' ? $envRoot : 'https://api.x.ai/v1';
        $model = $envModel !== '' ? $envModel : 'grok-4.6';
    } else {
        $apiRoot = 'https://api.openai.com/v1';
        $model = 'gpt-4o-mini';
    }

    // If someone stored the full chat/completions URL, normalize to API root
    if (str_ends_with($apiRoot, '/chat/completions')) {
        $apiRoot = substr($apiRoot, 0, -strlen('/chat/completions'));
    }
    if (str_ends_with($apiRoot, '/responses')) {
        $apiRoot = substr($apiRoot, 0, -strlen('/responses'));
    }

    $rootHost = strtolower((string) (parse_url($apiRoot, PHP_URL_HOST) ?: ''));
    $preferChat = $rootHost === 'api.openai.com'
        || str_contains($apiRoot, 'openai')
        || ($source === 'user' && $rootHost !== 'api.x.ai');

    return [
        'api_key' => $apiKey,
        'model' => $model !== '' ? $model : 'gpt-4o-mini',
        'api_root' => $apiRoot !== '' ? $apiRoot : 'https://api.openai.com/v1',
        'source' => $source,
        'prefer_chat' => $preferChat,
    ];
}

/**
 * Vision alt-text via OpenAI-compatible provider (Ice Cubes–style brief social description).
 * @return array{ok:bool,alt?:string,error?:string,usage?:array<string,mixed>}
 */
function admin_xai_generate_alt_text(string $dataUrl, string $mimeHint = 'image/jpeg'): array
{
    $cfg = admin_xai_config();
    if ($cfg['api_key'] === '') {
        return ['ok' => false, 'error' => 'AI alt text is not configured (missing API key).'];
    }
    if (!preg_match('#^data:(image/(jpeg|jpg|png|webp|gif));base64,#i', $dataUrl, $mm)) {
        // Accept raw base64 with mime hint
        if (preg_match('#^[A-Za-z0-9+/=\s]+$#', $dataUrl) && strlen($dataUrl) > 64) {
            $mime = in_array(strtolower($mimeHint), ['image/jpeg', 'image/jpg', 'image/png', 'image/webp', 'image/gif'], true)
                ? ($mimeHint === 'image/jpg' ? 'image/jpeg' : $mimeHint)
                : 'image/jpeg';
            $dataUrl = 'data:' . $mime . ';base64,' . preg_replace('/\s+/', '', $dataUrl);
        } else {
            return ['ok' => false, 'error' => 'Invalid image payload (need jpeg/png data URL).'];
        }
    }
    // jpg/png preferred; strip oversize
    $comma = strpos($dataUrl, ',');
    if ($comma === false) {
        return ['ok' => false, 'error' => 'Invalid image data URL.'];
    }
    $b64 = substr($dataUrl, $comma + 1);
    $rawLen = (int) (strlen($b64) * 0.75);
    if ($rawLen > 18 * 1024 * 1024) {
        return ['ok' => false, 'error' => 'Image too large for AI describe (max ~18MB).'];
    }
    $prompt = "What's in this image?\n"
        . "Be brief, it's for image alt description on a social network.\n"
        . "Don't write in the first person.\n"
        . "Don't start with \"Image of\" or \"A picture of\".\n"
        . "One or two short sentences. No hashtags.";

    $httpPost = static function (string $url, array $payload, string $apiKey): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['ok' => false, 'error' => 'Could not init HTTP client.', 'code' => 0, 'json' => null];
        }
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $apiKey,
            ],
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 90,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($errno !== 0 || !is_string($body)) {
            return [
                'ok' => false,
                'error' => 'Network error talking to AI provider' . ($err !== '' ? (': ' . $err) : '.'),
                'code' => $code,
                'json' => null,
            ];
        }
        $json = json_decode($body, true);
        return [
            'ok' => is_array($json) && $code < 400,
            'error' => null,
            'code' => $code,
            'json' => is_array($json) ? $json : null,
            'raw' => $body,
        ];
    };

    $respPayload = [
        'model' => $cfg['model'],
        'input' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'input_image',
                        'image_url' => $dataUrl,
                        'detail' => 'high',
                    ],
                    [
                        'type' => 'input_text',
                        'text' => $prompt,
                    ],
                ],
            ],
        ],
    ];
    $chatPayload = [
        'model' => $cfg['model'],
        'messages' => [
            [
                'role' => 'user',
                'content' => [
                    [
                        'type' => 'image_url',
                        'image_url' => [
                            'url' => $dataUrl,
                            'detail' => 'high',
                        ],
                    ],
                    [
                        'type' => 'text',
                        'text' => $prompt,
                    ],
                ],
            ],
        ],
        'max_tokens' => 300,
        'temperature' => 0.3,
    ];

    $json = null;
    $code = 0;
    $lastErr = null;
    $preferChat = !empty($cfg['prefer_chat']);
    $attempts = $preferChat
        ? [
            [$cfg['api_root'] . '/chat/completions', $chatPayload],
            [$cfg['api_root'] . '/responses', $respPayload],
        ]
        : [
            [$cfg['api_root'] . '/responses', $respPayload],
            [$cfg['api_root'] . '/chat/completions', $chatPayload],
        ];
    foreach ($attempts as [$url, $payload]) {
        $res = $httpPost($url, $payload, $cfg['api_key']);
        $code = (int) ($res['code'] ?? 0);
        if ($res['ok'] && is_array($res['json'])) {
            $json = $res['json'];
            break;
        }
        $lastErr = $res;
    }
    if (!is_array($json)) {
        $errJson = is_array($lastErr['json'] ?? null) ? $lastErr['json'] : null;
        $msg = 'AI provider request failed';
        if (is_array($errJson)) {
            $msg = (string) ($errJson['error']['message'] ?? $errJson['error'] ?? $errJson['message'] ?? $msg);
        } elseif (!empty($lastErr['error'])) {
            $msg = (string) $lastErr['error'];
        }
        return ['ok' => false, 'error' => 'AI provider error: ' . mb_substr($msg, 0, 240)];
    }
    $alt = '';
    // Responses API: output[].content[].text
    if (!empty($json['output']) && is_array($json['output'])) {
        foreach ($json['output'] as $item) {
            if (!is_array($item)) {
                continue;
            }
            $parts = $item['content'] ?? null;
            if (!is_array($parts)) {
                continue;
            }
            foreach ($parts as $part) {
                if (!is_array($part)) {
                    continue;
                }
                if (($part['type'] ?? '') === 'output_text' && isset($part['text'])) {
                    $alt .= (string) $part['text'];
                } elseif (isset($part['text']) && is_string($part['text'])) {
                    $alt .= $part['text'];
                }
            }
        }
    }
    if ($alt === '' && isset($json['output_text']) && is_string($json['output_text'])) {
        $alt = $json['output_text'];
    }
    // Chat completions shape
    if ($alt === '' && isset($json['choices'][0]['message']['content'])) {
        $alt = (string) $json['choices'][0]['message']['content'];
    }
    $alt = trim(preg_replace('/\s+/u', ' ', $alt) ?? $alt);
    if ($alt === '') {
        return ['ok' => false, 'error' => 'AI provider returned an empty description.'];
    }
    if (mb_strlen($alt) > 1500) {
        $alt = mb_substr($alt, 0, 1497) . '…';
    }
    $usage = is_array($json['usage'] ?? null) ? $json['usage'] : null;
    return ['ok' => true, 'alt' => $alt, 'usage' => $usage];
}

// AJAX: AI alt-text for compose media (vision — server-side key only)
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && (
        (isset($_POST['action']) && (string) $_POST['action'] === 'ai_alt_text')
        || (isset($_GET['op']) && (string) $_GET['op'] === 'ai_alt_text')
    )
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $csrfAlt = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_VAAK_CSRF'] ?? '');
    if (!ap_auth_csrf_ok($csrfAlt)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Session expired — refresh and try again.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $dataUrl = '';
    $mime = 'image/jpeg';
    if (!empty($_FILES['image']['tmp_name']) && is_uploaded_file((string) $_FILES['image']['tmp_name'])) {
        $tmp = (string) $_FILES['image']['tmp_name'];
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $detected = $finfo->file($tmp) ?: '';
        if (!in_array($detected, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
            echo json_encode(['ok' => false, 'error' => 'Only jpeg/png/webp/gif images are supported.'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        $bin = file_get_contents($tmp);
        if ($bin === false || $bin === '') {
            echo json_encode(['ok' => false, 'error' => 'Could not read uploaded image.'], JSON_UNESCAPED_SLASHES);
            exit;
        }
        // Vision APIs prefer jpeg/png; convert webp/gif via GD when possible
        $mime = $detected === 'image/jpg' ? 'image/jpeg' : $detected;
        if (in_array($mime, ['image/webp', 'image/gif'], true) && function_exists('imagecreatefromstring') && function_exists('imagejpeg')) {
            $im = @imagecreatefromstring($bin);
            if ($im !== false) {
                ob_start();
                imagejpeg($im, null, 88);
                $jpegBin = ob_get_clean();
                imagedestroy($im);
                if (is_string($jpegBin) && $jpegBin !== '') {
                    $bin = $jpegBin;
                    $mime = 'image/jpeg';
                }
            }
        }
        $dataUrl = 'data:' . $mime . ';base64,' . base64_encode($bin);
    } else {
        $dataUrl = trim((string) ($_POST['image_data'] ?? ''));
        $mime = trim((string) ($_POST['mime'] ?? 'image/jpeg'));
    }
    if ($dataUrl === '') {
        echo json_encode(['ok' => false, 'error' => 'No image provided.'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    $result = admin_xai_generate_alt_text($dataUrl, $mime);
    echo json_encode($result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// AJAX: plain text for Edit composer (avoids brittle data-* attrs / webview cache)
if (isset($_GET['op']) && (string) $_GET['op'] === 'edit_draft') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $noteId = trim((string) ($_GET['note_id'] ?? ''));
    $out = ['ok' => false, 'error' => 'Not found', 'content' => '', 'spoiler_text' => '', 'sensitive' => false];
    if ($noteId !== '' && vaak_is_own_url($noteId) && str_contains($noteId, '/notes/')) {
        try {
            $st = ap_db()->prepare(
                'SELECT content_text, spoiler_text, sensitive FROM masto_statuses
                 WHERE note_id = ? OR note_id = ? LIMIT 1'
            );
            $st->execute([$noteId, rtrim($noteId, '/') . '/']);
            $row = $st->fetch();
            if (is_array($row)) {
                $text = (string) ($row['content_text'] ?? '');
                if (in_array($text, ['(quote)', '(media)', '(poll)'], true)) {
                    $text = '';
                }
                $spoiler = trim((string) ($row['spoiler_text'] ?? ''));
                $out = [
                    'ok' => true,
                    'error' => null,
                    'content' => $text,
                    'spoiler_text' => $spoiler,
                    'sensitive' => !empty($row['sensitive']) || $spoiler !== '',
                    'note_id' => $noteId,
                ];
            }
        } catch (Throwable $e) {
            $out['error'] = 'Lookup failed';
        }
    } else {
        $out['error'] = 'Invalid note id';
    }
    echo json_encode($out, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $action = (string) ($_POST['action'] ?? '');
    // CSRF: session token (login/register already use the same helper)
    $csrfToken = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_VAAK_CSRF'] ?? '');
    if (!ap_auth_csrf_ok($csrfToken)) {
        $wantJsonCsrf = !empty($_POST['ajax'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        // PHP empties $_POST/$_FILES when the body exceeds post_max_size or
        // max_input_time — the browser still shows 100% uploaded, then we used
        // to mis-report that as "session expired" because csrf was missing.
        $contentLen = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $postLooksTruncated = $contentLen > 65536 && empty($_POST) && empty($_FILES);
        $csrfError = $postLooksTruncated
            ? 'Upload too large or timed out while the server was receiving it. Videos must be under 50MB (images 10MB). Try a shorter/compressed clip, or upload on Wi‑Fi.'
            : 'Session expired — refresh the page and try again.';
        if ($wantJsonCsrf) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code($postLooksTruncated ? 413 : 403);
            echo json_encode(['ok' => false, 'error' => $csrfError]);
            exit;
        }
        $error = $csrfError;
        $action = '';
    }
    // Server-wide / operator-only mutations (UI views are gated; POSTs must be too)
    $vaakAdminOnlyActions = [
        'set_app_password', 'purge_oauth_tokens',
        'block_add', 'block_actor', 'block_domain', 'unblock',
        'relay_add', 'relay_enable', 'relay_disable', 'relay_remove',
        'report_dismiss', 'report_ignore',
        'invite_create',
        'user_ban', 'user_unban',
        'policies_save_privacy', 'policies_save_conduct', 'policies_save_rules',
    ];
    // revoke_oauth_token + change_password are per-user (scoped in handlers)
    if ($action !== '' && in_array($action, $vaakAdminOnlyActions, true) && empty($vaakIsAdmin)) {
        $error = 'Admin only.';
        $action = '';
        $view = 'home';
    }
    if (in_array($action, ['favourite_status', 'unfavourite_status', 'bookmark_status', 'unbookmark_status', 'reblog_status', 'unreblog_status'], true)) {
        $returnView = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'home')) ?: 'home';
        $view = $returnView;
        $statusId = trim((string) ($_POST['status_id'] ?? ''));
        $objectId = trim((string) ($_POST['object_id'] ?? ''));
        $targetActor = trim((string) ($_POST['target_actor'] ?? ''));
        $wantJson = !empty($_POST['ajax'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $interactOk = false;
        $interactActive = null; // post-action toggle state
        $interactKind = null;   // favourite|bookmark|reblog
        if ($statusId === '' || !preg_match('/^\d+$/', $statusId)) {
            $error = 'Invalid status id.';
        } else {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
            $sid = (int) $statusId;
            $resolved = ap_masto_resolve_status_interaction($sid);
            if ($resolved === null && $objectId === '') {
                $error = 'Status not found in local store.';
            } elseif ($action === 'reblog_status' || $action === 'unreblog_status') {
                $interactKind = 'reblog';
                if ($resolved === null) {
                    $error = 'Status not found in local store.';
                } else {
                    $res = ap_masto_reblog_perform($resolved, $action === 'unreblog_status');
                    if (empty($res['ok'])) {
                        $error = $res['error'] ?? 'Boost failed.';
                    } else {
                        $interactOk = true;
                        $interactActive = $action === 'reblog_status';
                        $notice = $action === 'unreblog_status' ? 'Boost removed.' : 'Boosted.';
                    }
                }
            } else {
                if ($resolved) {
                    $objectId = $objectId !== '' ? $objectId : (string) ($resolved['object_id'] ?? '');
                    $targetActor = $targetActor !== '' ? $targetActor : (string) ($resolved['target_actor'] ?? '');
                    $statusArr = $resolved['status'];
                    if (!empty($statusArr['reblog']['uri'])) {
                        $objectId = (string) $statusArr['reblog']['uri'];
                    }
                    $objectId = preg_replace('/#announce-\d+$/', '', $objectId) ?? $objectId;
                }
                if ($action === 'favourite_status') {
                    $interactKind = 'favourite';
                    $likeId = null;
                    $isOurs = $resolved['is_ours'] ?? vaak_is_own_url($objectId);
                    if (!$isOurs && $objectId !== '' && str_starts_with($targetActor, 'https://')) {
                        $fan = ap_cmdr_send_like($objectId, $targetActor);
                        $likeId = $fan['like_id'] ?? null;
                    }
                    ap_masto_favourite_add($statusId, $objectId !== '' ? $objectId : $statusId, $targetActor !== '' ? $targetActor : null, is_string($likeId) ? $likeId : null);
                    $interactOk = true;
                    $interactActive = true;
                    $notice = 'Favourited.';
                } elseif ($action === 'unfavourite_status') {
                    $interactKind = 'favourite';
                    $prev = ap_masto_favourite_remove($statusId);
                    if ($prev && !empty($prev['like_activity_id']) && !empty($prev['object_id']) && !empty($prev['target_actor'])) {
                        ap_cmdr_send_undo_like((string) $prev['like_activity_id'], (string) $prev['object_id'], (string) $prev['target_actor']);
                    }
                    $interactOk = true;
                    $interactActive = false;
                    $notice = 'Removed favourite.';
                } elseif ($action === 'bookmark_status') {
                    $interactKind = 'bookmark';
                    ap_masto_bookmark_add($statusId, $objectId !== '' ? $objectId : null);
                    $interactOk = true;
                    $interactActive = true;
                    $notice = 'Bookmarked.';
                } else {
                    $interactKind = 'bookmark';
                    ap_masto_bookmark_remove($statusId);
                    $interactOk = true;
                    $interactActive = false;
                    $notice = 'Bookmark removed.';
                }
            }
        }
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            $folderIds = [];
            if ($interactOk && $interactKind === 'bookmark' && $interactActive && $statusId !== '') {
                $folderIds = function_exists('vaak_bookmark_folders_for_status')
                    ? vaak_bookmark_folders_for_status($statusId, $vaakOwnerId)
                    : [];
            }
            echo json_encode([
                'ok' => $interactOk && $error === null,
                'error' => $error,
                'notice' => $notice,
                'action' => $action,
                'kind' => $interactKind,
                'active' => $interactActive,
                'status_id' => $statusId,
                'object_id' => $objectId,
                'folder_ids' => $folderIds,
                'open_folder_picker' => $interactOk && $interactKind === 'bookmark' && $interactActive,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif (in_array($action, ['bookmark_folder_create', 'bookmark_folder_delete', 'bookmark_folder_add', 'bookmark_folder_remove'], true)) {
        $wantJson = !empty($_POST['ajax'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'bookmarks')) ?: 'bookmarks';
        $payload = ['ok' => false];
        if ($action === 'bookmark_folder_create') {
            $res = vaak_bookmark_folder_create($vaakOwnerId, (string) ($_POST['title'] ?? ''));
            $payload = $res;
            if (!empty($res['ok'])) {
                $notice = 'Folder created.';
            } else {
                $error = $res['error'] ?? 'Could not create folder.';
            }
        } elseif ($action === 'bookmark_folder_delete') {
            $res = vaak_bookmark_folder_delete((int) ($_POST['folder_id'] ?? 0), $vaakOwnerId);
            $payload = $res;
            if (!empty($res['ok'])) {
                $notice = 'Folder deleted.';
            } else {
                $error = $res['error'] ?? 'Could not delete folder.';
            }
        } elseif ($action === 'bookmark_folder_add') {
            $res = vaak_bookmark_folder_add_status(
                (int) ($_POST['folder_id'] ?? 0),
                (string) ($_POST['status_id'] ?? ''),
                $vaakOwnerId,
                trim((string) ($_POST['object_id'] ?? '')) ?: null
            );
            $payload = $res + [
                'status_id' => (string) ($_POST['status_id'] ?? ''),
                'folder_id' => (int) ($_POST['folder_id'] ?? 0),
            ];
            if (!empty($res['ok'])) {
                $notice = !empty($res['already']) ? 'Already in that folder.' : 'Saved to folder.';
            } else {
                $error = $res['error'] ?? 'Could not add to folder.';
            }
        } else {
            $res = vaak_bookmark_folder_remove_status(
                (int) ($_POST['folder_id'] ?? 0),
                (string) ($_POST['status_id'] ?? ''),
                $vaakOwnerId
            );
            $payload = $res + [
                'status_id' => (string) ($_POST['status_id'] ?? ''),
                'folder_id' => (int) ($_POST['folder_id'] ?? 0),
            ];
            if (!empty($res['ok'])) {
                $notice = 'Removed from folder.';
            } else {
                $error = $res['error'] ?? 'Could not remove from folder.';
            }
        }
        if ($wantJson) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            $payload['notice'] = $notice;
            $payload['error'] = $error;
            $payload['folders'] = vaak_bookmark_folders_list($vaakOwnerId);
            echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif ($action === 'reply') {
        $inReplyTo = trim((string) ($_POST['in_reply_to'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        $toActor = trim((string) ($_POST['to_actor'] ?? ''));
        $spoiler = trim((string) ($_POST['spoiler_text'] ?? ''));
        $quoteObject = trim((string) ($_POST['quote_object'] ?? ''));
        $visibility = function_exists('ap_normalize_visibility')
            ? ap_normalize_visibility($_POST['visibility'] ?? 'public')
            : 'public';
        $sensitive = !empty($_POST['sensitive']) || $spoiler !== '';
        $isQuote = $quoteObject !== '';
        $returnView = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'outbox')) ?: 'outbox';
        $fromDraftId = (int) ($_POST['draft_id'] ?? 0);
        $draftMedia = [];
        $rawDraftMedia = trim((string) ($_POST['draft_media_ids'] ?? ''));
        if ($rawDraftMedia !== '') {
            foreach (preg_split('/[\s,]+/', $rawDraftMedia) ?: [] as $mid) {
                $mid = (int) $mid;
                if ($mid > 0) {
                    $draftMedia[] = $mid;
                }
            }
        }
        $mediaPack = ap_admin_collect_media_uploads();
        $mediaIds = array_values(array_unique(array_merge($draftMedia, $mediaPack['ids'])));
        if ($mediaPack['error'] !== null) {
            $error = $mediaPack['error'];
            $composerForceOpen = true;
            $view = $returnView;
        } elseif (!$isQuote && $content === '' && !$mediaIds) {
            $error = 'Post text or media required (max 2000 chars).';
            $composerForceOpen = true;
            $view = $returnView;
        } elseif (mb_strlen($content) > 2000) {
            $error = 'Post text max 2000 chars.';
            $composerForceOpen = true;
            $view = $returnView;
        } elseif ($inReplyTo !== '' && !str_starts_with($inReplyTo, 'https://')) {
            $error = 'in_reply_to must be an https object URL (or leave blank for an original post).';
            $composerForceOpen = true;
            $view = $returnView;
        } elseif ($quoteObject !== '' && !str_starts_with($quoteObject, 'https://')) {
            $error = 'quote_object must be an https URL.';
            $composerForceOpen = true;
            $view = $returnView;
        } elseif (mb_strlen($spoiler) > 500) {
            $error = 'Content warning max 500 characters.';
            $composerForceOpen = true;
            $view = $returnView;
        } else {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
            $result = ap_local_post_reply(
                $content,
                $inReplyTo,
                $toActor !== '' ? $toActor : null,
                $spoiler,
                $sensitive,
                $quoteObject !== '' ? $quoteObject : null,
                $mediaIds,
                $visibility
            );
            if (!empty($result['ok'])) {
                if ($fromDraftId > 0) {
                    ap_draft_delete($fromDraftId);
                }
                $delivered = (int) ($result['delivered'] ?? 0);
                $queued = (int) ($result['queued'] ?? 0);
                $notice = $isQuote ? 'Quote posted.' : 'Posted to Your posts.';
                if ($mediaIds) {
                    $notice .= ' · ' . count($mediaIds) . ' media';
                }
                if ($delivered > 0 || $queued > 0) {
                    $notice .= ' Delivered to ' . $delivered . ' inbox(es)';
                    if ($queued > 0) {
                        $notice .= ', ' . $queued . ' more queued in background';
                    }
                    $notice .= '.';
                }
                $view = $returnView !== '' ? $returnView : 'outbox';
            } else {
                $error = $result['error'] ?? 'Reply failed.';
                $composerForceOpen = true;
                $view = $returnView;
            }
        }
        $wantJsonReply = !empty($_POST['ajax'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($wantJsonReply) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'ok' => $error === null && $notice !== null,
                'error' => $error,
                'notice' => $notice,
                'action' => 'reply',
                'kind' => $isQuote ? 'quote' : 'post',
                'return_view' => $returnView,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif ($action === 'pin_status' || $action === 'unpin_status') {
        $noteId = trim((string) ($_POST['note_id'] ?? ''));
        $returnView = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'outbox')) ?: 'outbox';
        $localId = 0;
        if ($noteId !== '' && vaak_is_own_url($noteId) && str_contains($noteId, '/notes/')) {
            try {
                $st = ap_db()->prepare('SELECT local_id FROM masto_statuses WHERE note_id = ? OR note_id = ? LIMIT 1');
                $st->execute([$noteId, rtrim($noteId, '/') . '/']);
                $localId = (int) ($st->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $localId = 0;
            }
        }
        if ($localId <= 0) {
            $error = 'Could not find that local post to pin.';
        } elseif ($action === 'pin_status') {
            $res = function_exists('ap_masto_status_pin') ? ap_masto_status_pin($localId) : ['ok' => false, 'error' => 'Pin unavailable'];
            if (!empty($res['ok'])) {
                $notice = 'Pinned — shows on your HTML profile and in Ice Cubes.';
            } else {
                $error = $res['error'] ?? 'Could not pin.';
            }
        } else {
            $res = function_exists('ap_masto_status_unpin') ? ap_masto_status_unpin($localId) : ['ok' => false];
            if (!empty($res['ok'])) {
                $notice = 'Unpinned.';
            } else {
                $error = $res['error'] ?? 'Could not unpin.';
            }
        }
        $view = $returnView;
        // Stay on focused status when pinning from Open
        if ($returnView === 'status' && $noteId !== '') {
            $_GET['object'] = $noteId;
            $_GET['from'] = preg_replace('/[^a-z_]/', '', (string) ($_POST['from'] ?? 'outbox')) ?: 'outbox';
        }
    } elseif ($action === 'edit_status') {
        $noteId = trim((string) ($_POST['note_id'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        $spoiler = trim((string) ($_POST['spoiler_text'] ?? ''));
        $sensitive = !empty($_POST['sensitive']) || $spoiler !== '';
        $returnView = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'outbox')) ?: 'outbox';
        $localId = 0;
        if ($noteId !== '' && vaak_is_own_url($noteId) && str_contains($noteId, '/notes/')) {
            try {
                $st = ap_db()->prepare('SELECT local_id FROM masto_statuses WHERE note_id = ? OR note_id = ? LIMIT 1');
                $st->execute([$noteId, rtrim($noteId, '/') . '/']);
                $localId = (int) ($st->fetchColumn() ?: 0);
            } catch (Throwable $e) {
                $localId = 0;
            }
        }
        if ($localId <= 0) {
            $error = 'Could not find that local post to edit.';
            $composerForceOpen = true;
            $view = $returnView;
        } elseif ($content === '' && empty($_POST['allow_empty_media'])) {
            // Media-only edits: allow empty text if the status already has media
            $hasMedia = false;
            try {
                $mst = ap_db()->prepare('SELECT 1 FROM masto_media WHERE status_local_id = ? LIMIT 1');
                $mst->execute([$localId]);
                $hasMedia = (bool) $mst->fetchColumn();
            } catch (Throwable $e) {
                $hasMedia = false;
            }
            if (!$hasMedia) {
                $error = 'Post text or existing media required.';
                $composerForceOpen = true;
                $view = $returnView;
            }
        }
        if ($error === null && mb_strlen($content) > 2000) {
            $error = 'Post text max 2000 chars.';
            $composerForceOpen = true;
            $view = $returnView;
        } elseif ($error === null && mb_strlen($spoiler) > 500) {
            $error = 'Content warning max 500 characters.';
            $composerForceOpen = true;
            $view = $returnView;
        } elseif ($error === null) {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
            $result = ap_update_local_status($localId, $content, $spoiler, $sensitive, null);
            if (!empty($result['ok'])) {
                admin_tl_cache_clear();
                $notice = 'Post updated.';
                $delivered = (int) ($result['delivered'] ?? 0);
                $queued = (int) ($result['queued'] ?? 0);
                if ($delivered > 0 || $queued > 0) {
                    $notice .= ' Update delivered to ' . $delivered . ' inbox(es)';
                    if ($queued > 0) {
                        $notice .= ', ' . $queued . ' more queued';
                    }
                    $notice .= '.';
                }
                $view = $returnView;
            } else {
                $error = $result['error'] ?? 'Edit failed.';
                $composerForceOpen = true;
                $view = $returnView;
            }
        }
        $wantJsonReply = !empty($_POST['ajax'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($wantJsonReply) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'ok' => $error === null && $notice !== null,
                'error' => $error,
                'notice' => $notice,
                'action' => 'edit_status',
                'return_view' => $returnView,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif ($action === 'queue_post') {
        $inReplyTo = trim((string) ($_POST['in_reply_to'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        $toActor = trim((string) ($_POST['to_actor'] ?? ''));
        $spoiler = trim((string) ($_POST['spoiler_text'] ?? ''));
        $quoteObject = trim((string) ($_POST['quote_object'] ?? ''));
        $visibility = function_exists('ap_normalize_visibility')
            ? ap_normalize_visibility($_POST['visibility'] ?? 'public')
            : 'public';
        $sensitive = !empty($_POST['sensitive']) || $spoiler !== '';
        $returnView = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'queue')) ?: 'queue';
        $fromDraftId = (int) ($_POST['draft_id'] ?? 0);
        $draftMedia = [];
        $rawDraftMedia = trim((string) ($_POST['draft_media_ids'] ?? ''));
        if ($rawDraftMedia !== '') {
            foreach (preg_split('/[\s,]+/', $rawDraftMedia) ?: [] as $mid) {
                $mid = (int) $mid;
                if ($mid > 0) {
                    $draftMedia[] = $mid;
                }
            }
        }
        $mediaPack = ap_admin_collect_media_uploads();
        $mediaIds = array_values(array_unique(array_merge($draftMedia, $mediaPack['ids'])));
        $queueScheduledLocal = '';
        $queueId = 0;
        $fallbackView = preg_replace('/[^a-z_]/', '', (string) ($_POST['compose_return_view'] ?? 'home')) ?: 'home';
        if ($mediaPack['error'] !== null) {
            $error = $mediaPack['error'];
            $composerForceOpen = true;
            $view = $fallbackView;
        } else {
            $result = ap_queue_enqueue(
                $content,
                $spoiler,
                $sensitive,
                $inReplyTo,
                $toActor,
                $quoteObject,
                $mediaIds,
                $visibility
            );
            if (!empty($result['ok'])) {
                if ($fromDraftId > 0) {
                    ap_draft_delete($fromDraftId);
                }
                $queueId = (int) (($result['item']['id'] ?? 0));
                $when = (string) ($result['scheduled_at'] ?? '');
                $queueScheduledLocal = ap_queue_format_local($when !== '' ? $when : null);
                $notice = 'Added to queue'
                    . ($queueScheduledLocal !== '' ? (' · ' . $queueScheduledLocal) : '')
                    . '.';
                $view = 'queue';
            } else {
                $error = $result['error'] ?? 'Could not queue post.';
                $composerForceOpen = true;
                $view = $fallbackView;
            }
        }
        $wantJsonQueue = !empty($_POST['ajax'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($wantJsonQueue) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            echo json_encode([
                'ok' => $error === null && $notice !== null,
                'error' => $error,
                'notice' => $notice,
                'action' => 'queue_post',
                'queue_id' => $queueId,
                'scheduled_at' => $queueScheduledLocal,
                'return_view' => 'queue',
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
    } elseif ($action === 'queue_settings') {
        $view = 'queue';
        $result = ap_queue_settings_set([
            'timezone' => (string) ($_POST['timezone'] ?? 'America/New_York'),
            'window_start' => (string) ($_POST['window_start'] ?? '09:00'),
            'window_end' => (string) ($_POST['window_end'] ?? '21:00'),
            'min_gap_minutes' => (int) ($_POST['min_gap_minutes'] ?? 60),
            'enabled' => !empty($_POST['enabled']),
        ]);
        if (!empty($result['ok'])) {
            $notice = 'Queue window saved · upcoming times recalculated.';
        } else {
            $error = $result['error'] ?? 'Could not save queue settings.';
        }
    } elseif ($action === 'queue_cancel') {
        $view = 'queue';
        $qid = (int) ($_POST['queue_id'] ?? 0);
        $result = ap_queue_cancel($qid);
        if (!empty($result['ok'])) {
            $notice = 'Removed from queue.';
        } else {
            $error = $result['error'] ?? 'Cancel failed.';
        }
    } elseif ($action === 'queue_move') {
        $view = 'queue';
        $qid = (int) ($_POST['queue_id'] ?? 0);
        $dir = (string) ($_POST['direction'] ?? 'down');
        $result = ap_queue_move($qid, $dir);
        if (!empty($result['ok'])) {
            $notice = 'Queue order updated.';
        } else {
            $error = $result['error'] ?? 'Reorder failed.';
        }
    } elseif ($action === 'queue_retry') {
        $view = 'queue';
        $qid = (int) ($_POST['queue_id'] ?? 0);
        $result = ap_queue_retry($qid);
        if (!empty($result['ok'])) {
            $notice = 'Queued for retry.';
        } else {
            $error = $result['error'] ?? 'Retry failed.';
        }
    } elseif ($action === 'queue_edit') {
        $view = 'queue';
        $qid = (int) ($_POST['queue_id'] ?? 0);
        $result = ap_queue_update_content(
            $qid,
            (string) ($_POST['content'] ?? ''),
            (string) ($_POST['spoiler_text'] ?? ''),
            isset($_POST['sensitive']) ? !empty($_POST['sensitive']) : null
        );
        if (!empty($result['ok'])) {
            $notice = 'Queued post updated.';
        } else {
            $error = $result['error'] ?? 'Update failed.';
        }
    } elseif ($action === 'save_profile') {
        $fieldNames = $_POST['field_name'] ?? [];
        $fieldValues = $_POST['field_value'] ?? [];
        $attachment = [];
        if (is_array($fieldNames) && is_array($fieldValues)) {
            $n = min(count($fieldNames), count($fieldValues), 8);
            for ($i = 0; $i < $n; $i++) {
                $attachment[] = [
                    'name' => (string) $fieldNames[$i],
                    'value' => (string) $fieldValues[$i],
                ];
            }
        }
        $iconUrl = (string) ($_POST['icon_url'] ?? '');
        $imageUrl = (string) ($_POST['image_url'] ?? '');
        $uploadNotes = [];
        $view = 'profile';

        $iconFile = $_FILES['icon_file'] ?? null;
        if (is_array($iconFile) && (int) ($iconFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
            $up = ap_profile_store_uploaded_image($iconFile, 'avatar');
            if (empty($up['ok'])) {
                $error = $up['error'] ?? 'Avatar upload failed.';
            } else {
                $iconUrl = (string) ($up['url'] ?? '');
                $uploadNotes[] = 'avatar uploaded';
            }
        }

        if ($error === null) {
            $imageFile = $_FILES['image_file'] ?? null;
            if (is_array($imageFile) && (int) ($imageFile['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE) {
                $up = ap_profile_store_uploaded_image($imageFile, 'header');
                if (empty($up['ok'])) {
                    $error = $up['error'] ?? 'Header upload failed.';
                } else {
                    $imageUrl = (string) ($up['url'] ?? '');
                    $uploadNotes[] = 'header uploaded';
                }
            }
        }

        if ($error === null) {
            $emailIn = trim((string) ($_POST['email'] ?? ''));
            if (function_exists('ap_auth_user_set_email')) {
                $emailRes = ap_auth_user_set_email($vaakOwnerId, $emailIn);
                if (empty($emailRes['ok'])) {
                    $error = $emailRes['error'] ?? 'Could not save email.';
                } else {
                    $freshUser = ap_auth_user_by_id($vaakOwnerId);
                    if (is_array($freshUser)) {
                        $vaakUser = $freshUser;
                        $GLOBALS['vaak_user'] = $freshUser;
                    }
                }
            }
        }

        if ($error === null) {
            $saved = ap_profile_save([
                'name' => (string) ($_POST['name'] ?? ''),
                'summary' => (string) ($_POST['summary'] ?? ''),
                'attachment' => $attachment,
                'icon_url' => $iconUrl,
                'image_url' => $imageUrl,
                'manually_approves' => !empty($_POST['manually_approves']),
                'discoverable' => !empty($_POST['discoverable']),
                'indexable' => !empty($_POST['indexable']),
                'collection_consent' => !empty($_POST['collection_consent']),
                'vanity_verified' => !empty($_POST['vanity_verified']),
                'auto_follow_back' => !empty($_POST['auto_follow_back']),
                'anti_ai_marker' => !empty($_POST['anti_ai_marker']),
                'auto_unblur_sensitive' => !empty($_POST['auto_unblur_sensitive']),
                'auto_delete_posts_7d' => !empty($_POST['auto_delete_posts_7d']),
                'reply_policy' => (string) ($_POST['reply_policy'] ?? 'anyone'),
                'quote_policy' => (string) ($_POST['quote_policy'] ?? 'anyone'),
            ], $vaakActorKey);
            if (empty($saved['ok'])) {
                $error = $saved['error'] ?? 'Profile save failed.';
            } else {
                $fan = ['ok' => false, 'error' => 'skipped'];
                if (!defined('AP_INBOX_LIB_ONLY')) {
                    define('AP_INBOX_LIB_ONLY', true);
                }
                require_once __DIR__ . '/ap-inbox.php';
                $fan = function_exists('ap_publish_profile_update')
                    ? ap_publish_profile_update($vaakActorKey, true)
                    : ap_cmdr_publish_profile_update($vaakActorKey);
                $notice = 'Profile saved.';
                if (!empty($_POST['vanity_verified'])) {
                    $notice .= ' Vanity verified ✓ enabled.';
                }
                if ($uploadNotes) {
                    $notice .= ' (' . implode(', ', $uploadNotes) . ')';
                }
                $verCount = 0;
                foreach ((ap_profile_get($vaakActorKey)['attachment'] ?? []) as $attRow) {
                    if (is_array($attRow) && !empty($attRow['verified_at'])) {
                        $verCount++;
                    }
                }
                if ($verCount > 0) {
                    $notice .= ' ' . $verCount . ' field' . ($verCount === 1 ? '' : 's') . ' verified (rel=me).';
                }
                if (!empty($fan['queued'])) {
                    $notice .= ' Update queued for background delivery to ' . (int) $fan['queued'] . ' inbox(es).';
                } elseif (!empty($fan['ok'])) {
                    $notice .= ' Update delivered to ' . (int) ($fan['delivered'] ?? 0) . ' inbox(es).';
                } else {
                    $notice .= ' (follower Update skipped: ' . ($fan['error'] ?? 'unknown') . ')';
                }
                // Mirror avatar / header / bio to linked Bluesky PDS account (if any).
                if (function_exists('ap_bsky_session_row') && ap_bsky_session_row($vaakOwnerId)
                    && function_exists('ap_bsky_sync_profile_from_vaak')) {
                    $bskySync = ap_bsky_sync_profile_from_vaak($vaakOwnerId, $vaakActorKey);
                    if (!empty($bskySync['ok'])) {
                        $notice .= !empty($bskySync['truncated'])
                            ? ' Bluesky profile updated (bio truncated + HTML profile link).'
                            : ' Bluesky profile updated.';
                    } else {
                        $notice .= ' (Bluesky profile sync skipped: '
                            . (string) ($bskySync['error'] ?? 'unknown') . ')';
                    }
                }
            }
        }
    } elseif ($action === 'repush_profile') {
        $view = 'profile';
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
        $fan = function_exists('ap_publish_profile_update')
            ? ap_publish_profile_update($vaakActorKey)
            : ap_cmdr_publish_profile_update($vaakActorKey);
        if (!empty($fan['ok'])) {
            $notice = 'Profile Update pushed (no movedTo). Delivered to ' . (int) ($fan['delivered'] ?? 0)
                . ' inbox(es) — followers'
                . ($vaakActorKey === 'cmdr_nova' ? ' + known remote shared inboxes' : '')
                . '. Remotes that still show “moved” should clear after they process the Update or re-fetch the actor.';
        } else {
            $error = $fan['error'] ?? 'Profile re-push failed.';
        }
    } elseif ($action === 'reverify_profile_fields') {
        $view = 'profile';
        $rev = ap_profile_reverify_fields($vaakActorKey);
        if (!empty($rev['ok'])) {
            $notice = 'Checked ' . (int) ($rev['checked'] ?? 0) . ' link field'
                . (((int) ($rev['checked'] ?? 0) === 1) ? '' : 's')
                . ' — ' . (int) ($rev['verified'] ?? 0) . ' verified via rel=me.';
        } else {
            $error = $rev['error'] ?? 'Verification failed.';
        }
    } elseif ($action === 'sl_link_start') {
        $view = 'profile';
        $uid = (int) ($vaakUser['id'] ?? 0);
        $res = ap_sl_challenge_start(
            $uid,
            (string) ($_POST['sl_username'] ?? ''),
            (string) ($_POST['sl_agent_id'] ?? '')
        );
        if (!empty($res['ok'])) {
            $notice = (string) ($res['notice'] ?? 'Verification code sent.');
            // debug_code is already embedded in notice when SL_LINK_DEBUG=1
        } else {
            $error = $res['error'] ?? 'Could not start Second Life verification.';
        }
    } elseif ($action === 'sl_link_verify') {
        $view = 'profile';
        $uid = (int) ($vaakUser['id'] ?? 0);
        $res = ap_sl_challenge_verify($uid, (string) ($_POST['sl_code'] ?? ''));
        if (!empty($res['ok'])) {
            $notice = (string) ($res['notice'] ?? 'Second Life avatar linked.');
        } else {
            $error = $res['error'] ?? 'Verification failed.';
        }
    } elseif ($action === 'sl_link_unlink') {
        $view = 'profile';
        $uid = (int) ($vaakUser['id'] ?? 0);
        $res = ap_sl_unlink($uid);
        if (!empty($res['ok'])) {
            $notice = (string) ($res['notice'] ?? 'Unlinked.');
        } else {
            $error = $res['error'] ?? 'Could not unlink.';
        }
    } elseif ($action === 'featured_add') {
        $view = 'profile';
        $uid = (int) ($vaakUser['id'] ?? 0);
        $res = ap_featured_add($uid, (string) ($_POST['featured_ref'] ?? ''));
        if (!empty($res['ok'])) {
            $notice = (string) ($res['notice'] ?? 'Featured.');
        } else {
            $error = $res['error'] ?? 'Could not feature that account.';
        }
    } elseif ($action === 'featured_remove') {
        $view = 'profile';
        $uid = (int) ($vaakUser['id'] ?? 0);
        $res = ap_featured_remove($uid, (int) ($_POST['featured_id'] ?? 0));
        if (!empty($res['ok'])) {
            $notice = (string) ($res['notice'] ?? 'Removed.');
        } else {
            $error = $res['error'] ?? 'Could not remove.';
        }
    } elseif ($action === 'featured_move') {
        $view = 'profile';
        $uid = (int) ($vaakUser['id'] ?? 0);
        $res = ap_featured_move($uid, (int) ($_POST['featured_id'] ?? 0), (string) ($_POST['dir'] ?? 'up'));
        if (!empty($res['ok'])) {
            $notice = (string) ($res['notice'] ?? 'Updated.');
        } else {
            $error = $res['error'] ?? 'Could not reorder.';
        }
    } elseif ($action === 'follow_remote') {
        $target = trim((string) ($_POST['actor_id'] ?? ''));
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'followers')) ?: 'followers';
        $returnActor = trim((string) ($_POST['return_actor'] ?? ''));
        if ($target === '') {
            $error = 'Enter an actor URL (https://…) or handle (@user@instance).';
        } else {
            $isBskyTarget = function_exists('ap_bsky_is_profile_ref') && ap_bsky_is_profile_ref($target);
            if ($isBskyTarget && function_exists('ap_bsky_follow_actor') && function_exists('ap_bsky_session_row')
                && is_array(ap_bsky_session_row($vaakOwnerId))) {
                $result = ap_bsky_follow_actor($vaakOwnerId, $target);
                if (!empty($result['ok'])) {
                    $notice = !empty($result['already'])
                        ? 'Already following on Bluesky'
                        : 'Followed on Bluesky';
                    if ($view === '' || $view === 'followers') {
                        $view = 'following';
                    }
                    if ($view === 'remote_profile' && $returnActor !== '' && str_starts_with($returnActor, 'https://')) {
                        $_GET['actor'] = $returnActor;
                        $_GET['from'] = (string) ($_POST['return_from'] ?? ($_GET['from'] ?? ''));
                    }
                } else {
                    $error = $result['error'] ?? 'Bluesky follow failed.';
                }
            } else {
                if (!defined('AP_INBOX_LIB_ONLY')) {
                    define('AP_INBOX_LIB_ONLY', true);
                }
                require_once __DIR__ . '/ap-inbox.php';
                $result = ap_follow_remote_actor($target, true);
                if (!empty($result['ok'])) {
                    if (!empty($result['already'])) {
                        $notice = 'Already following ' . $target;
                    } else {
                        $notice = 'Follow sent to ' . $target;
                    }
                    // Keep return_view when set (e.g. search); default following
                    if ($view === '' || $view === 'followers') {
                        $view = 'following';
                    }
                    if ($view === 'remote_profile' && $returnActor !== '' && str_starts_with($returnActor, 'https://')) {
                        $_GET['actor'] = $returnActor;
                        $_GET['from'] = (string) ($_POST['return_from'] ?? ($_GET['from'] ?? ''));
                    }
                } else {
                    $error = $result['error'] ?? 'Follow failed.';
                }
            }
        }
    } elseif ($action === 'unfollow_remote') {
        $target = trim((string) ($_POST['actor_id'] ?? ''));
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'following')) ?: 'following';
        $returnActor = trim((string) ($_POST['return_actor'] ?? ''));
        if ($target === '') {
            $error = 'Missing actor to unfollow.';
        } else {
            $isBskyTarget = function_exists('ap_bsky_is_profile_ref') && ap_bsky_is_profile_ref($target);
            if ($isBskyTarget && function_exists('ap_bsky_unfollow_actor') && function_exists('ap_bsky_session_row')
                && is_array(ap_bsky_session_row($vaakOwnerId))) {
                $result = ap_bsky_unfollow_actor($vaakOwnerId, $target);
                if (!empty($result['ok'])) {
                    $notice = !empty($result['already'])
                        ? 'Was not following on Bluesky'
                        : 'Unfollowed on Bluesky';
                    $view = $view !== '' ? $view : 'following';
                    if ($view === 'remote_profile' && $returnActor !== '' && str_starts_with($returnActor, 'https://')) {
                        $_GET['actor'] = $returnActor;
                        $_GET['from'] = (string) ($_POST['return_from'] ?? ($_GET['from'] ?? ''));
                    }
                } else {
                    $error = $result['error'] ?? 'Bluesky unfollow failed.';
                }
            } else {
                if (!defined('AP_INBOX_LIB_ONLY')) {
                    define('AP_INBOX_LIB_ONLY', true);
                }
                require_once __DIR__ . '/ap-inbox.php';
                $result = ap_unfollow_remote_actor($target);
                if (!empty($result['ok'])) {
                    $notice = !empty($result['already'])
                        ? 'Was not following ' . $target
                        : 'Unfollowed ' . $target;
                    $view = $view !== '' ? $view : 'following';
                    if ($view === 'remote_profile' && $returnActor !== '' && str_starts_with($returnActor, 'https://')) {
                        $_GET['actor'] = $returnActor;
                        $_GET['from'] = (string) ($_POST['return_from'] ?? ($_GET['from'] ?? ''));
                    }
                } else {
                    $error = $result['error'] ?? 'Unfollow failed.';
                }
            }
        }
    } elseif ($action === 'follow_tag' || $action === 'unfollow_tag') {
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'tags')) ?: 'tags';
        $tagName = trim((string) ($_POST['tag'] ?? ''));
        require_once __DIR__ . '/ap-masto-entities.php';
        if ($action === 'follow_tag') {
            $result = ap_masto_tag_follow($tagName);
            if (!empty($result['ok'])) {
                $notice = 'Following #' . ($result['tag']['name'] ?? $tagName);
            } else {
                $error = $result['error'] ?? 'Could not follow hashtag.';
            }
        } else {
            $result = ap_masto_tag_unfollow($tagName);
            if (!empty($result['ok'])) {
                $notice = 'Unfollowed #' . ($result['tag']['name'] ?? $tagName);
            } else {
                $error = $result['error'] ?? 'Could not unfollow hashtag.';
            }
        }
    } elseif ($action === 'suggestion_follow' || $action === 'suggestion_dismiss') {
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'home')) ?: 'home';
        $actor = trim((string) ($_POST['actor_id'] ?? ''));
        if ($action === 'suggestion_dismiss') {
            ap_masto_suggestion_dismiss($actor);
            $notice = 'Suggestion dismissed.';
        } else {
            $res = ap_follow_remote_actor($actor, true);
            if (!empty($res['ok'])) {
                $notice = 'Follow requested / sent.';
                ap_masto_suggestion_dismiss($actor); // drop from For You once followed
            } else {
                $error = $res['error'] ?? 'Follow failed.';
            }
        }
    } elseif (str_starts_with($action, 'collection_')) {
        $view = 'collections';
        $cid = (int) ($_POST['collection_id'] ?? $_GET['id'] ?? 0);
        if ($action === 'collection_create') {
            $res = ap_collection_create(
                (string) ($_POST['name'] ?? ''),
                (string) ($_POST['description'] ?? ''),
                !isset($_POST['discoverable']) || !empty($_POST['discoverable']),
                trim((string) ($_POST['tag_name'] ?? '')) ?: null
            );
            if (!empty($res['ok'])) {
                $notice = 'Collection created.';
                $_GET['id'] = (string) (int) ($res['id'] ?? 0);
            } else {
                $error = $res['error'] ?? 'Could not create collection.';
            }
        } elseif ($action === 'collection_update') {
            $res = ap_collection_update($cid, [
                'name' => (string) ($_POST['name'] ?? ''),
                'description' => (string) ($_POST['description'] ?? ''),
                'discoverable' => !empty($_POST['discoverable']),
                'sensitive' => !empty($_POST['sensitive']),
                'tag_name' => (string) ($_POST['tag_name'] ?? ''),
            ]);
            if (!empty($res['ok'])) {
                $notice = 'Collection saved.';
                $_GET['id'] = (string) $cid;
            } else {
                $error = $res['error'] ?? 'Could not update collection.';
                if ($cid > 0) {
                    $_GET['id'] = (string) $cid;
                }
            }
        } elseif ($action === 'collection_delete') {
            $res = ap_collection_soft_delete($cid);
            if (!empty($res['ok'])) {
                $notice = 'Collection deleted.';
                unset($_GET['id']);
            } else {
                $error = $res['error'] ?? 'Could not delete collection.';
            }
        } elseif ($action === 'collection_add_member') {
            $ref = trim((string) ($_POST['actor'] ?? $_POST['to'] ?? ''));
            $actorId = '';
            if (str_starts_with($ref, 'https://')) {
                $actorId = rtrim($ref, '/');
            } elseif ($ref !== '') {
                $resolved = ap_resolve_actor_ref($ref);
                if (is_string($resolved) && str_starts_with($resolved, 'https://')) {
                    $actorId = rtrim($resolved, '/');
                } elseif (is_array($resolved)) {
                    $actorId = rtrim((string) ($resolved['id'] ?? $resolved['actor_id'] ?? ''), '/');
                }
            }
            $res = ap_collection_add_member($cid, $actorId);
            if (!empty($res['ok'])) {
                $notice = 'Added to collection.';
            } else {
                $error = $res['error'] ?? 'Could not add member.';
            }
            if ($cid > 0) {
                $_GET['id'] = (string) $cid;
            }
        } elseif ($action === 'collection_remove_member') {
            $actorId = trim((string) ($_POST['actor_id'] ?? ''));
            $res = ap_collection_remove_member($cid, $actorId);
            if (!empty($res['ok'])) {
                $notice = 'Removed from collection.';
            } else {
                $error = $res['error'] ?? 'Could not remove member.';
            }
            if ($cid > 0) {
                $_GET['id'] = (string) $cid;
            }
        } elseif ($action === 'collection_follow_all') {
            $res = ap_collection_follow_all($cid);
            if (!empty($res['ok'])) {
                $parts = [];
                $parts[] = 'Followed ' . (int) ($res['followed'] ?? 0);
                $parts[] = 'skipped ' . (int) ($res['skipped'] ?? 0);
                if (!empty($res['failed'])) {
                    $parts[] = 'failed ' . (int) $res['failed'];
                }
                $notice = implode(', ', $parts) . '.';
                if (!empty($res['rate_limited'])) {
                    $notice .= ' Hit the outbound follow rate limit (30/hour) — run Follow all again later.';
                }
                if (!empty($res['errors'])) {
                    $error = implode('; ', array_slice($res['errors'], 0, 3));
                }
            } else {
                $error = $res['error'] ?? 'Follow all failed.';
            }
            if ($cid > 0) {
                $_GET['id'] = (string) $cid;
            }
        } elseif ($action === 'collection_membership_import') {
            $res = ap_collection_import_membership_from_url((string) ($_POST['url'] ?? ''));
            if (!empty($res['ok'])) {
                $notice = 'Saved membership' . (!empty($res['name']) ? (': ' . $res['name']) : '.');
            } else {
                $error = $res['error'] ?? 'Could not import collection.';
            }
        } elseif ($action === 'collection_membership_dismiss') {
            $mid = (int) ($_POST['membership_id'] ?? 0);
            $res = ap_collection_membership_dismiss($mid);
            if (!empty($res['ok'])) {
                $notice = 'Dismissed.';
            } else {
                $error = $res['error'] ?? 'Could not dismiss.';
            }
        } else {
            $error = 'Unknown collection action.';
        }
    } elseif (str_starts_with($action, 'list_')) {
        $view = 'lists';
        $lid = (int) ($_POST['list_id'] ?? $_GET['id'] ?? 0);
        if ($action === 'list_create') {
            $res = ap_list_create(
                (string) ($_POST['title'] ?? ''),
                (string) ($_POST['replies_policy'] ?? 'list'),
                !empty($_POST['exclusive'])
            );
            if (!empty($res['ok'])) {
                $notice = 'List created.';
                $_GET['id'] = (string) (int) ($res['id'] ?? 0);
            } else {
                $error = $res['error'] ?? 'Could not create list.';
            }
        } elseif ($action === 'list_update') {
            $res = ap_list_update($lid, [
                'title' => (string) ($_POST['title'] ?? ''),
                'replies_policy' => (string) ($_POST['replies_policy'] ?? 'list'),
                'exclusive' => !empty($_POST['exclusive']),
            ]);
            if (!empty($res['ok'])) {
                $notice = 'List saved.';
                $_GET['id'] = (string) $lid;
            } else {
                $error = $res['error'] ?? 'Could not update list.';
                if ($lid > 0) {
                    $_GET['id'] = (string) $lid;
                }
            }
        } elseif ($action === 'list_delete') {
            $res = ap_list_delete($lid);
            if (!empty($res['ok'])) {
                $notice = 'List deleted.';
                unset($_GET['id']);
            } else {
                $error = $res['error'] ?? 'Could not delete list.';
            }
        } elseif ($action === 'list_add_account') {
            $ref = trim((string) ($_POST['actor'] ?? $_POST['to'] ?? ''));
            $actorId = ap_list_resolve_actor_ref($ref) ?? '';
            $followIfNeeded = !empty($_POST['follow_if_needed']);
            $res = ap_list_add_account($lid, $actorId, $followIfNeeded);
            if (!empty($res['ok'])) {
                $notice = !empty($res['followed']) ? 'Followed and added to list.' : 'Added to list.';
            } else {
                $error = $res['error'] ?? 'Could not add account.';
                if (!empty($res['rate_limited'])) {
                    $error .= ' Hit the outbound follow rate limit (30/hour).';
                }
            }
            if ($lid > 0) {
                $_GET['id'] = (string) $lid;
            }
        } elseif ($action === 'list_remove_account') {
            $actorId = trim((string) ($_POST['actor_id'] ?? ''));
            $res = ap_list_remove_account($lid, $actorId);
            if (!empty($res['ok'])) {
                $notice = 'Removed from list.';
            } else {
                $error = $res['error'] ?? 'Could not remove account.';
            }
            if ($lid > 0) {
                $_GET['id'] = (string) $lid;
            }
        } else {
            $error = 'Unknown list action.';
        }
    } elseif (str_starts_with($action, 'ie_') || str_starts_with($action, 'alias_') || str_starts_with($action, 'move_')) {
        $view = 'import_export';
        try {
            if ($action === 'ie_import') {
                $kind = preg_replace('/[^a-z_]/', '', (string) ($_POST['import_kind'] ?? '')) ?: '';
                $file = $_FILES['csv'] ?? null;
                if (!is_array($file)) {
                    $error = 'Choose a CSV file to upload.';
                } else {
                    $rows = ap_ie_read_upload_rows($file);
                    $res = match ($kind) {
                        'following' => (static function (array $rows): array {
                            $q = ap_ie_follow_queue_from_rows($rows);
                            if (empty($q['ok'])) {
                                return ['ok' => false, 'errors' => [$q['error'] ?? 'Queue failed'], 'imported' => 0, 'skipped' => 0, 'failed' => 0];
                            }
                            $batch = ap_ie_follow_process_batch(5);
                            return [
                                'ok' => true,
                                'imported' => (int) ($batch['ok_new'] ?? 0) + (int) ($batch['already'] ?? 0),
                                'skipped' => (int) ($batch['skipped'] ?? 0),
                                'failed' => (int) ($batch['failed'] ?? 0),
                                'errors' => $batch['errors'] ?? [],
                                'queued' => (int) ($q['queued'] ?? 0),
                                'remaining' => (int) ($batch['remaining'] ?? 0),
                                'rate_limited' => !empty($batch['rate_limited']),
                                'follow_job' => true,
                            ];
                        })($rows),
                        'mutes' => ap_ie_import_mutes($rows),
                        'blocks' => ap_ie_import_blocks($rows),
                        'blocked_domains' => ap_ie_import_blocked_domains($rows),
                        'bookmarks' => ap_ie_import_bookmarks($rows),
                        'lists' => ap_ie_import_lists($rows),
                        'followed_tags' => ap_ie_import_followed_tags($rows),
                        default => ['ok' => false, 'errors' => ['Unknown import type'], 'imported' => 0, 'skipped' => 0, 'failed' => 0],
                    };
                    if (!empty($res['ok'])) {
                        $parts = [];
                        if (!empty($res['follow_job'])) {
                            $parts[] = 'Queued ' . (int) ($res['queued'] ?? 0) . ' follows';
                            $parts[] = 'batch +' . (int) ($res['imported'] ?? 0);
                            $parts[] = (int) ($res['remaining'] ?? 0) . ' remaining';
                            if (!empty($res['rate_limited'])) {
                                $parts[] = 'hit 30/hr limit — use Continue batch';
                            }
                        } else {
                            $parts[] = 'Imported ' . (int) ($res['imported'] ?? 0);
                            $parts[] = 'skipped ' . (int) ($res['skipped'] ?? 0);
                            $parts[] = 'failed ' . (int) ($res['failed'] ?? 0);
                            if (!empty($res['rate_limited'])) {
                                $parts[] = 'stopped on follow rate limit';
                            }
                        }
                        $notice = implode(', ', $parts) . '.';
                        if (!empty($res['errors'])) {
                            $error = implode('; ', array_slice($res['errors'], 0, 4));
                        }
                    } else {
                        $error = implode('; ', $res['errors'] ?? ['Import failed']);
                    }
                }
            } elseif ($action === 'ie_follow_continue') {
                $batch = ap_ie_follow_process_batch(5);
                $notice = 'Follow batch: +' . (int) ($batch['ok_new'] ?? 0) . ' new, '
                    . (int) ($batch['already'] ?? 0) . ' already, '
                    . (int) ($batch['remaining'] ?? 0) . ' remaining.';
                if (!empty($batch['rate_limited'])) {
                    $notice .= ' Rate limit (30/hr) — try again later or run CLI continue.';
                }
                if (!empty($batch['finished'])) {
                    $notice .= ' Job finished.';
                }
                if (!empty($batch['errors'])) {
                    $error = implode('; ', array_slice($batch['errors'], 0, 3));
                }
            } elseif ($action === 'alias_add') {
                $res = ap_alias_add((string) ($_POST['alias_ref'] ?? ''), (string) ($_POST['direction'] ?? 'aka'), vaak_actor_key());
                if (!empty($res['ok'])) {
                    $fan = function_exists('ap_publish_profile_update')
                        ? ap_publish_profile_update(vaak_actor_key())
                        : (function_exists('ap_cmdr_publish_profile_update')
                            ? ap_cmdr_publish_profile_update(vaak_actor_key())
                            : ['delivered' => 0]);
                    $notice = 'Alias saved. Profile Update delivered to ' . (int) ($fan['delivered'] ?? 0) . ' inboxes.';
                } else {
                    $error = $res['error'] ?? 'Could not add alias.';
                }
            } elseif ($action === 'alias_remove') {
                $res = ap_alias_remove((int) ($_POST['alias_id'] ?? 0), vaak_actor_key());
                if (!empty($res['ok'])) {
                    if (function_exists('ap_publish_profile_update')) {
                        ap_publish_profile_update(vaak_actor_key());
                    } elseif (function_exists('ap_cmdr_publish_profile_update')) {
                        ap_cmdr_publish_profile_update(vaak_actor_key());
                    }
                    $notice = 'Alias removed.';
                } else {
                    $error = $res['error'] ?? 'Could not remove alias.';
                }
            } elseif ($action === 'alias_verify') {
                $res = ap_alias_verify((int) ($_POST['alias_id'] ?? 0), vaak_actor_key());
                if (!empty($res['ok'])) {
                    $notice = !empty($res['verified'])
                        ? ('Verified: ' . ($res['detail'] ?? 'ok'))
                        : ('Not verified yet: ' . ($res['detail'] ?? ''));
                } else {
                    $error = $res['error'] ?? 'Verify failed.';
                }
            } elseif ($action === 'move_out') {
                $res = ap_move_out((string) ($_POST['target'] ?? ''), (string) ($_POST['confirm'] ?? ''), vaak_actor_key());
                if (!empty($res['ok'])) {
                    $notice = 'Move published (movedTo set). Delivered profile Update to '
                        . (int) ($res['delivered'] ?? 0) . ' inboxes. Followers migrate on supporting servers.';
                } else {
                    $error = $res['error'] ?? 'Move failed.';
                }
            } elseif ($action === 'move_clear') {
                $res = ap_move_clear((string) ($_POST['confirm'] ?? ''), vaak_actor_key());
                if (!empty($res['ok'])) {
                    $notice = 'movedTo cleared. Update delivered to ' . (int) ($res['delivered'] ?? 0) . ' inboxes.';
                } else {
                    $error = $res['error'] ?? 'Clear failed.';
                }
            } elseif ($action === 'move_prepare_target') {
                $res = ap_move_prepare_target((string) ($_POST['old_account'] ?? ''), vaak_actor_key());
                if (!empty($res['ok'])) {
                    $fan = function_exists('ap_publish_profile_update')
                        ? ap_publish_profile_update(vaak_actor_key())
                        : (function_exists('ap_cmdr_publish_profile_update')
                            ? ap_cmdr_publish_profile_update(vaak_actor_key())
                            : ['delivered' => 0]);
                    $notice = 'Prepared as migration target (alsoKnownAs). Update delivered to '
                        . (int) ($fan['delivered'] ?? 0) . ' inboxes. On the old account, add this account as an alias / Move destination.';
                } else {
                    $error = $res['error'] ?? 'Prepare failed.';
                }
            } else {
                $error = 'Unknown import/export action.';
            }
        } catch (Throwable $e) {
            $error = $e->getMessage();
        }
    } elseif ($action === 'block_add' || $action === 'block_actor' || $action === 'block_domain') {
        $view = preg_replace('/[^a-z]/', '', (string) ($_POST['return_view'] ?? 'blocks')) ?: 'blocks';
        $postedKind = (string) ($_POST['kind'] ?? 'block');
        $kind = in_array($postedKind, ['block', 'suspend', 'mute'], true) ? $postedKind : 'block';
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $result = null;

        if ($action === 'block_domain') {
            $domain = strtolower(trim((string) ($_POST['domain'] ?? $_POST['host'] ?? '')));
            $domain = preg_replace('#^https?://#', '', $domain) ?? $domain;
            $domain = rtrim(explode('/', $domain, 2)[0], '.');
            if ($domain === 'mkultra.monster' || str_ends_with($domain, '.mkultra.monster')) {
                $error = 'You can’t block the home instance.';
            } else {
                $result = ap_block_upsert('domain', $domain, $kind, $reason !== '' ? $reason : null);
            }
        } elseif ($action === 'block_actor') {
            $actor = trim((string) ($_POST['actor_id'] ?? ''));
            if ($actor !== '' && !str_starts_with($actor, 'https://')) {
                if (!defined('AP_INBOX_LIB_ONLY')) {
                    define('AP_INBOX_LIB_ONLY', true);
                }
                require_once __DIR__ . '/ap-inbox.php';
                $resolved = ap_resolve_actor_ref($actor);
                if ($resolved) {
                    $actor = $resolved;
                }
            }
            if ($actor !== '' && rtrim($actor, '/') === rtrim(vaak_actor_id(), '/')) {
                $error = 'You can’t block yourself.';
            } else {
                $result = ap_block_upsert('actor', $actor, $kind, $reason !== '' ? $reason : null);
            }
        } else {
            // block_add: auto-detect domain vs actor/handle
            $input = trim((string) ($_POST['target'] ?? ''));
            $postedKind = (string) ($_POST['kind'] ?? 'block');
            $kind = in_array($postedKind, ['block', 'suspend', 'mute'], true) ? $postedKind : 'block';
            if ($input === '') {
                $error = 'Enter a domain (example.com) or @user@host / actor URL.';
            } elseif (str_starts_with($input, 'https://') || str_starts_with($input, '@') || str_contains($input, '@')) {
                if (!str_starts_with($input, 'https://')) {
                    if (!defined('AP_INBOX_LIB_ONLY')) {
                        define('AP_INBOX_LIB_ONLY', true);
                    }
                    require_once __DIR__ . '/ap-inbox.php';
                    $resolved = ap_resolve_actor_ref($input);
                    if (!$resolved) {
                        $error = 'Could not resolve handle via WebFinger.';
                    } else {
                        $input = $resolved;
                    }
                }
                if ($error === null) {
                    if (rtrim($input, '/') === rtrim(vaak_actor_id(), '/')) {
                        $error = 'You can’t block yourself.';
                    } else {
                        $result = ap_block_upsert('actor', $input, $kind, $reason !== '' ? $reason : null);
                    }
                }
            } else {
                $dom = strtolower(trim($input));
                $dom = preg_replace('#^https?://#', '', $dom) ?? $dom;
                $dom = rtrim(explode('/', $dom, 2)[0], '.');
                if ($dom === 'mkultra.monster' || str_ends_with($dom, '.mkultra.monster')) {
                    $error = 'You can’t block the home instance.';
                } else {
                    $result = ap_block_upsert('domain', $dom, $kind, $reason !== '' ? $reason : null);
                }
            }
        }

        if ($result !== null) {
            if (!empty($result['ok'])) {
                $side = $result['side_effects'] ?? [];
                $verb = $kind === 'mute' ? 'Muted' : ucfirst($kind) . 'ed';
                $notice = $verb . ' ' . ($result['scope'] ?? '') . ' ' . ($result['value'] ?? '')
                    . ' · removed followers ' . (int) ($side['followers_removed'] ?? 0)
                    . ', following ' . (int) ($side['following_removed'] ?? 0)
                    . ', hid mentions ' . (int) ($side['mentions_hidden'] ?? 0);
                if ($kind === 'block' || $kind === 'suspend') {
                    $notice .= ' · inbound federation from them is now rejected (HTTP 403).';
                }
                $view = 'blocks';
            } else {
                $error = $result['error'] ?? 'Block failed.';
            }
        }
    } elseif ($action === 'unblock') {
        $view = 'blocks';
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $error = 'Missing block id.';
        } else {
            $removed = ap_block_remove($id);
            if (!empty($removed['ok'])) {
                $notice = 'Removed server-wide control for ' . ($removed['scope'] ?? '') . ' '
                    . ($removed['value'] ?? ('#' . $id))
                    . ' · restored mentions ' . (int) ($removed['mentions_restored'] ?? 0)
                    . ' (follows are not auto-restored — re-follow if you want them back).';
            } else {
                $error = $removed['error'] ?? 'Block not found.';
            }
        }
    } elseif ($action === 'bite_remote') {
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'home')) ?: 'home';
        $target = trim((string) ($_POST['target'] ?? $_POST['actor_id'] ?? $_POST['object_id'] ?? ''));
        $kind = trim((string) ($_POST['bite_kind'] ?? 'auto'));
        $returnActor = trim((string) ($_POST['return_actor'] ?? ''));
        $returnFrom = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_from'] ?? '')) ?: '';
        $res = ap_bite_send($target, $kind !== '' ? $kind : 'auto');
        if (!empty($res['ok'])) {
            $notice = '🦷 Bite sent'
                . (isset($res['delivered']) ? (' (delivered to ' . (int) $res['delivered'] . ' inbox' . ((int) $res['delivered'] === 1 ? '' : 'es') . ')') : '')
                . '.';
        } else {
            $error = $res['error'] ?? 'Bite failed.';
        }
        if ($view === 'remote_profile' && $returnActor !== '' && str_starts_with($returnActor, 'https://')) {
            $_GET['actor'] = $returnActor;
            if ($returnFrom !== '') {
                $_GET['from'] = $returnFrom;
            }
        }
    } elseif ($action === 'report_remote') {
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'moderation')) ?: 'moderation';
        $target = trim((string) ($_POST['target'] ?? $_POST['actor_id'] ?? ''));
        $comment = trim((string) ($_POST['comment'] ?? ''));
        $statusUri = trim((string) ($_POST['object_id'] ?? $_POST['status_uri'] ?? ''));
        $statuses = $statusUri !== '' ? [$statusUri] : [];
        $returnActor = trim((string) ($_POST['return_actor'] ?? ''));
        $returnFrom = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_from'] ?? '')) ?: '';
        $res = ap_report_send($target, $comment, $statuses);
        if (!empty($res['ok'])) {
            $notice = !empty($res['local_peer'])
                ? 'Report filed for local moderators.'
                : 'Report (Flag) sent to remote moderators.';
            $wanted = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'report')) ?: 'report';
            if ($wanted === 'moderation' && empty($vaakIsAdmin)) {
                $wanted = 'report';
            }
            $view = $wanted === 'moderation' ? 'moderation' : 'report';
            if ($returnActor !== '' && str_starts_with($returnActor, 'https://')) {
                $_GET['report_target'] = $returnActor;
            }
            if ($returnFrom !== '') {
                $_GET['from'] = $returnFrom;
            }
        } else {
            $error = $res['error'] ?? 'Report failed.';
            $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'report')) ?: 'report';
            if ($view === 'moderation' && empty($vaakIsAdmin)) {
                $view = 'report';
            }
            if ($returnActor !== '' && str_starts_with($returnActor, 'https://')) {
                $_GET['report_target'] = $returnActor;
                if ($returnFrom !== '') {
                    $_GET['from'] = $returnFrom;
                }
            }
        }
    } elseif ($action === 'relay_add') {
        $view = 'relays';
        $inbox = trim((string) ($_POST['inbox_url'] ?? ''));
        $res = ap_relay_add($inbox);
        if (!empty($res['ok'])) {
            $notice = 'Relay saved: ' . (string) ($res['inbox_url'] ?? $inbox) . ' (idle — click Enable to subscribe).';
        } else {
            $error = $res['error'] ?? 'Could not add relay.';
        }
    } elseif ($action === 'relay_enable') {
        $view = 'relays';
        $rid = (int) ($_POST['relay_id'] ?? 0);
        $res = ap_relay_enable($rid);
        if (!empty($res['ok'])) {
            $notice = 'Relay subscribe Follow sent — state: ' . (string) ($res['state'] ?? 'pending') . '.';
            if (!empty($res['error'])) {
                $notice .= ' Note: ' . (string) $res['error'];
            }
        } else {
            $error = $res['error'] ?? 'Enable failed.';
        }
    } elseif ($action === 'relay_disable') {
        $view = 'relays';
        $rid = (int) ($_POST['relay_id'] ?? 0);
        $res = ap_relay_disable($rid);
        if (!empty($res['ok'])) {
            $notice = 'Relay disabled (Undo Follow sent if it was pending/accepted).';
        } else {
            $error = $res['error'] ?? 'Disable failed.';
        }
    } elseif ($action === 'relay_remove') {
        $view = 'relays';
        $rid = (int) ($_POST['relay_id'] ?? 0);
        $res = ap_relay_remove($rid);
        if (!empty($res['ok'])) {
            $notice = 'Relay removed.';
        } else {
            $error = $res['error'] ?? 'Remove failed.';
        }
    } elseif ($action === 'report_dismiss' || $action === 'report_ignore') {
        $view = 'moderation';
        $rid = (int) ($_POST['report_id'] ?? 0);
        $state = $action === 'report_ignore' ? 'ignored' : 'dismissed';
        $res = ap_report_set_state($rid, $state);
        if (!empty($res['ok'])) {
            $notice = $state === 'ignored'
                ? 'Report ignored — cleared from open queue.'
                : 'Report dismissed — cleared from open queue.';
        } else {
            $error = $res['error'] ?? 'Could not update report.';
        }
        if (!empty($_POST['filter'])) {
            $_GET['filter'] = preg_replace('/[^a-z_]/', '', (string) $_POST['filter']) ?: 'open';
        }
    } elseif ($action === 'mute_remote' || $action === 'unmute_remote') {
        $target = trim((string) ($_POST['actor_id'] ?? ''));
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'remote_profile')) ?: 'remote_profile';
        if ($view === 'blocks' || $view === 'muted_words') {
            $view = 'profile';
        }
        $returnActor = trim((string) ($_POST['return_actor'] ?? ''));
        if ($target === '') {
            $error = 'Missing actor to mute.';
        } else {
            if (!str_starts_with($target, 'https://')) {
                if (!defined('AP_INBOX_LIB_ONLY')) {
                    define('AP_INBOX_LIB_ONLY', true);
                }
                require_once __DIR__ . '/ap-inbox.php';
                $resolved = ap_resolve_actor_ref($target);
                if (is_string($resolved) && $resolved !== '') {
                    $target = $resolved;
                } else {
                    $error = 'Could not resolve handle via WebFinger.';
                }
            }
        }
        if ($error === null) {
            if ($action === 'mute_remote') {
                $result = ap_mute_upsert($target, true, $vaakOwnerId);
                if (!empty($result['ok'])) {
                    admin_tl_cache_clear();
                    $notice = !empty($result['already'])
                        ? 'Already muted ' . $target
                        : 'Muted ' . $target . ' (hidden from Home / Federated / Notifications; follow stays).';
                } else {
                    $error = $result['error'] ?? 'Mute failed.';
                }
            } else {
                $result = ap_unmute($target, $vaakOwnerId);
                if (!empty($result['ok'])) {
                    admin_tl_cache_clear();
                    $notice = 'Unmuted ' . $target;
                } else {
                    $error = $result['error'] ?? 'Unmute failed.';
                }
            }
        }
        if ($returnActor === '' && str_starts_with($target, 'https://')) {
            $returnActor = $target;
        }
        if ($view === 'remote_profile' && $returnActor !== '' && str_starts_with($returnActor, 'https://')) {
            $_GET['actor'] = $returnActor;
            $_GET['from'] = (string) ($_POST['return_from'] ?? ($_GET['from'] ?? ''));
        }
    } elseif ($action === 'deprioritize_remote' || $action === 'undeprioritize_remote') {
        $target = trim((string) ($_POST['actor_id'] ?? ''));
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'home')) ?: 'home';
        if ($view === 'blocks' || $view === 'muted_words') {
            $view = 'profile';
        }
        $returnActor = trim((string) ($_POST['return_actor'] ?? ''));
        if ($target === '') {
            $error = 'Missing actor to deprioritize.';
        } elseif (!str_starts_with($target, 'https://')) {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
            $resolved = ap_resolve_actor_ref($target);
            if (is_string($resolved) && $resolved !== '') {
                $target = $resolved;
            } else {
                $error = 'Could not resolve handle via WebFinger.';
            }
        }
        if ($error === null) {
            if ($action === 'deprioritize_remote') {
                $result = function_exists('ap_deprioritize_upsert')
                    ? ap_deprioritize_upsert($target, $vaakOwnerId)
                    : ['ok' => false, 'error' => 'Deprioritize unavailable.'];
                if (!empty($result['ok'])) {
                    admin_tl_cache_clear();
                    $notice = !empty($result['already'])
                        ? 'Already deprioritized on Home.'
                        : 'Deprioritized on Home (still shows on Federated / Local).';
                } else {
                    $error = $result['error'] ?? 'Deprioritize failed.';
                }
            } else {
                $result = function_exists('ap_deprioritize_remove')
                    ? ap_deprioritize_remove($target, $vaakOwnerId)
                    : ['ok' => false, 'error' => 'Deprioritize unavailable.'];
                if (!empty($result['ok'])) {
                    admin_tl_cache_clear();
                    $notice = 'Removed Home deprioritize.';
                } else {
                    $error = $result['error'] ?? 'Could not remove deprioritize.';
                }
            }
        }
        if ($returnActor === '' && str_starts_with($target, 'https://')) {
            $returnActor = $target;
        }
        if ($view === 'remote_profile' && $returnActor !== '' && str_starts_with($returnActor, 'https://')) {
            $_GET['actor'] = $returnActor;
            $_GET['from'] = (string) ($_POST['return_from'] ?? ($_GET['from'] ?? ''));
        }
    } elseif ($action === 'subscribe_posts' || $action === 'unsubscribe_posts') {
        $target = trim((string) ($_POST['actor_id'] ?? ''));
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'remote_profile')) ?: 'remote_profile';
        $returnActor = trim((string) ($_POST['return_actor'] ?? ''));
        if ($target === '' || !str_starts_with($target, 'https://')) {
            $error = 'Missing actor for post notifications.';
        } else {
            $wantOn = $action === 'subscribe_posts';
            $result = function_exists('ap_post_subscription_set')
                ? ap_post_subscription_set($target, $wantOn, $vaakOwnerId)
                : ['ok' => false, 'error' => 'Post subscriptions unavailable.'];
            if (!empty($result['ok'])) {
                $notice = $wantOn
                    ? (!empty($result['already'])
                        ? 'Already notified of posts from ' . $target
                        : 'You will be notified when they post.')
                    : (!empty($result['already'])
                        ? 'Were not subscribed to posts from ' . $target
                        : 'Stopped notifying for their posts.');
            } else {
                $error = $result['error'] ?? 'Could not update post notifications.';
            }
        }
        if ($returnActor === '' && str_starts_with($target, 'https://')) {
            $returnActor = $target;
        }
        if ($view === 'remote_profile' && $returnActor !== '' && str_starts_with($returnActor, 'https://')) {
            $_GET['actor'] = $returnActor;
            $_GET['from'] = (string) ($_POST['return_from'] ?? ($_GET['from'] ?? ''));
        }
    } elseif ($action === 'muted_word_add') {
        $view = 'profile';
        $phrase = (string) ($_POST['phrase'] ?? '');
        $note = (string) ($_POST['note'] ?? '');
        $result = function_exists('ap_muted_word_add')
            ? ap_muted_word_add($phrase, $note, $vaakOwnerId)
            : ['ok' => false, 'error' => 'Muted words unavailable.'];
        if (!empty($result['ok'])) {
            admin_tl_cache_clear();
            $notice = !empty($result['already'])
                ? 'Already muting “' . ($result['phrase'] ?? $phrase) . '”.'
                : 'Muted “' . ($result['phrase'] ?? $phrase) . '” — matching posts hide from Home & Federated.';
        } else {
            $error = $result['error'] ?? 'Could not add muted phrase.';
        }
    } elseif ($action === 'muted_word_remove') {
        $view = 'profile';
        $wid = (int) ($_POST['id'] ?? 0);
        $result = function_exists('ap_muted_word_remove')
            ? ap_muted_word_remove($wid, $vaakOwnerId)
            : ['ok' => false, 'error' => 'Muted words unavailable.'];
        if (!empty($result['ok'])) {
            admin_tl_cache_clear();
            $notice = 'Removed muted phrase.';
        } else {
            $error = $result['error'] ?? 'Could not remove phrase.';
        }
    } elseif ($action === 'follow_request_decide') {
        $view = 'profile';
        $requestId = (int) ($_POST['id'] ?? 0);
        $approve = (($_POST['decision'] ?? '') === 'approve');
        $ownerActor = rtrim((string) ($vaakUser['actor_id'] ?? $vaakActorId), '/');
        $request = function_exists('ap_follow_request_get')
            ? ap_follow_request_get($requestId, $ownerActor)
            : null;
        if (!is_array($request)) {
            $error = 'Follow request not found.';
        } else {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
            $result = function_exists('ap_follow_request_decide')
                ? ap_follow_request_decide($request, $approve)
                : ['ok' => false, 'error' => 'Follow approval unavailable.'];
            $notice = !empty($result['ok'])
                ? ($approve ? 'Follower approved.' : 'Follow request rejected.')
                : ($result['error'] ?? 'Could not update follow request.');
            if (empty($result['ok'])) {
                $error = $notice;
                $notice = null;
            }
        }
    } elseif ($action === 'user_block_add') {
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'profile')) ?: 'profile';
        if ($view === 'blocks' || $view === 'muted_words') {
            $view = 'profile';
        }
        $kind = (($_POST['kind'] ?? '') === 'suspend') ? 'suspend' : 'block';
        $reason = trim((string) ($_POST['reason'] ?? ''));
        $input = trim((string) ($_POST['target'] ?? ''));
        $returnActor = trim((string) ($_POST['return_actor'] ?? ''));
        $result = null;
        if ($input === '') {
            $error = 'Enter a domain (example.com) or @user@host / actor URL.';
        } elseif (str_starts_with($input, 'https://') || str_starts_with($input, '@') || str_contains($input, '@')) {
            if (!str_starts_with($input, 'https://')) {
                if (!defined('AP_INBOX_LIB_ONLY')) {
                    define('AP_INBOX_LIB_ONLY', true);
                }
                require_once __DIR__ . '/ap-inbox.php';
                $resolved = ap_resolve_actor_ref($input);
                if (!$resolved) {
                    $error = 'Could not resolve handle via WebFinger.';
                } else {
                    $input = $resolved;
                }
            }
            if ($error === null) {
                $result = ap_user_block_add($vaakOwnerId, 'actor', $input, $kind, $reason !== '' ? $reason : null);
            }
        } else {
            $result = ap_user_block_add($vaakOwnerId, 'domain', $input, $kind, $reason !== '' ? $reason : null);
        }
        if ($result !== null) {
            if (!empty($result['ok'])) {
                admin_tl_cache_clear();
                $notice = 'Personal ' . $kind . ' saved for ' . ($result['scope'] ?? '') . ' ' . ($result['value'] ?? '') . '.';
                if (($result['scope'] ?? '') === 'actor') {
                    $notice .= !empty($result['delivered'])
                        ? ' Block activity delivered to their inbox.'
                        : ' Local hide applied; remote Block delivery may have failed — check if Bridgy still shows as active.';
                }

            } else {
                $error = $result['error'] ?? 'Personal block failed.';
            }
        }
        if ($view === 'remote_profile' && $returnActor !== '' && str_starts_with($returnActor, 'https://')) {
            $_GET['actor'] = $returnActor;
            $_GET['from'] = (string) ($_POST['return_from'] ?? ($_GET['from'] ?? ''));
        }
    } elseif ($action === 'user_block_remove') {
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'profile')) ?: 'profile';
        if ($view === 'blocks' || $view === 'muted_words') {
            $view = 'profile';
        }
        $id = (int) ($_POST['id'] ?? 0);
        $removed = ap_user_block_remove($vaakOwnerId, $id);
        if (!empty($removed['ok'])) {
            admin_tl_cache_clear();
            $notice = 'Removed personal block for ' . ($removed['scope'] ?? '') . ' ' . ($removed['value'] ?? ('#' . $id)) . '.';
        } else {
            $error = $removed['error'] ?? 'Personal block not found.';
        }
        $returnActor = trim((string) ($_POST['return_actor'] ?? ''));
        if ($view === 'remote_profile' && $returnActor !== '' && str_starts_with($returnActor, 'https://')) {
            $_GET['actor'] = $returnActor;
            $_GET['from'] = (string) ($_POST['return_from'] ?? ($_GET['from'] ?? ''));
        }
    } elseif (in_array($action, ['bsky_like', 'bsky_unlike', 'bsky_repost', 'bsky_unrepost', 'bsky_bookmark', 'bsky_unbookmark'], true)) {
        $view = 'bluesky';
        $subject = trim((string) ($_POST['bsky_uri'] ?? ''));
        $cid = trim((string) ($_POST['bsky_cid'] ?? ''));
        $recordUri = trim((string) ($_POST['bsky_record_uri'] ?? ''));
        $returnFeed = trim((string) ($_POST['bsky_feed'] ?? 'following'));
        $wantAjax = isset($_GET['ajax']) || isset($_POST['ajax'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');
        if ($returnFeed !== '') {
            $_GET['feed'] = $returnFeed;
        }
        $ajaxOut = ['ok' => false, 'action' => $action, 'uri' => $subject];
        if (!function_exists('ap_bsky_tab_enabled') || !ap_bsky_tab_enabled()) {
            $error = 'Bluesky tab is disabled on this instance.';
            $ajaxOut['error'] = $error;
        } elseif (in_array($action, ['bsky_unlike', 'bsky_unrepost'], true)) {
            if ($recordUri === '' || !str_starts_with($recordUri, 'at://')) {
                $error = 'Missing Bluesky engagement record.';
                $ajaxOut['error'] = $error;
            } else {
                $res = ap_bsky_delete_record_uri($vaakOwnerId, $recordUri);
                $ajaxOut['ok'] = !empty($res['ok']);
                $ajaxOut['error'] = (string) ($res['error'] ?? '');
                if ($action === 'bsky_unlike') {
                    $ajaxOut['liked'] = false;
                    $ajaxOut['record_uri'] = '';
                    $notice = !empty($res['ok']) ? 'Unliked on Bluesky.' : ('Unlike failed: ' . $ajaxOut['error']);
                } else {
                    $ajaxOut['reposted'] = false;
                    $ajaxOut['record_uri'] = '';
                    $notice = !empty($res['ok']) ? 'Removed Bluesky boost.' : ('Unboost failed: ' . $ajaxOut['error']);
                }
                if (empty($res['ok'])) {
                    $error = $notice;
                    $notice = '';
                }
            }
        } elseif (!str_starts_with($subject, 'at://')) {
            $error = 'Missing Bluesky post reference.';
            $ajaxOut['error'] = $error;
        } elseif ($action === 'bsky_unbookmark') {
            $res = ap_bsky_delete_bookmark($vaakOwnerId, $subject);
            $ajaxOut['ok'] = !empty($res['ok']);
            $ajaxOut['error'] = (string) ($res['error'] ?? '');
            $ajaxOut['bookmarked'] = false;
            $ajaxOut['open_folder_picker'] = false;
            $bmKeys = admin_bsky_bookmark_keys($subject, $vaakOwnerId);
            $ajaxOut['status_id'] = $bmKeys['status_id'];
            $ajaxOut['object_id'] = $bmKeys['object_id'];
            $notice = !empty($res['ok']) ? 'Bookmark removed.' : ('Unbookmark failed: ' . $ajaxOut['error']);
            if (empty($res['ok'])) {
                $error = $notice;
                $notice = '';
            }
            if (!empty($res['ok'])) {
                if (function_exists('ap_bsky_tl_cache_clear_owner')) {
                    ap_bsky_tl_cache_clear_owner($vaakOwnerId);
                }
                if ($bmKeys['status_id'] !== '' && function_exists('ap_masto_bookmark_remove')) {
                    try {
                        ap_masto_bookmark_remove($bmKeys['status_id'], $vaakOwnerId);
                    } catch (Throwable $e) {
                        // ignore
                    }
                }
            }
        } elseif ($cid === '') {
            $error = 'Missing Bluesky post CID.';
            $ajaxOut['error'] = $error;
        } elseif ($action === 'bsky_like') {
            $res = ap_bsky_create_like($vaakOwnerId, ['uri' => $subject, 'cid' => $cid]);
            $ajaxOut['ok'] = !empty($res['ok']);
            $ajaxOut['error'] = (string) ($res['error'] ?? '');
            $ajaxOut['liked'] = !empty($res['ok']);
            $ajaxOut['record_uri'] = (string) ($res['uri'] ?? '');
            $notice = !empty($res['ok']) ? 'Liked on Bluesky.' : ('Like failed: ' . $ajaxOut['error']);
            if (empty($res['ok'])) {
                $error = $notice;
                $notice = '';
            }
            if (!empty($res['ok']) && function_exists('ap_bsky_tl_cache_clear_owner')) {
                ap_bsky_tl_cache_clear_owner($vaakOwnerId);
            }
        } elseif ($action === 'bsky_repost') {
            $res = ap_bsky_create_repost($vaakOwnerId, ['uri' => $subject, 'cid' => $cid]);
            $ajaxOut['ok'] = !empty($res['ok']);
            $ajaxOut['error'] = (string) ($res['error'] ?? '');
            $ajaxOut['reposted'] = !empty($res['ok']);
            $ajaxOut['record_uri'] = (string) ($res['uri'] ?? '');
            $notice = !empty($res['ok']) ? 'Boosted on Bluesky.' : ('Boost failed: ' . $ajaxOut['error']);
            if (empty($res['ok'])) {
                $error = $notice;
                $notice = '';
            }
            if (!empty($res['ok']) && function_exists('ap_bsky_tl_cache_clear_owner')) {
                ap_bsky_tl_cache_clear_owner($vaakOwnerId);
            }
        } else { // bsky_bookmark
            $res = ap_bsky_create_bookmark($vaakOwnerId, ['uri' => $subject, 'cid' => $cid]);
            $ajaxOut['ok'] = !empty($res['ok']);
            $ajaxOut['error'] = (string) ($res['error'] ?? '');
            $ajaxOut['bookmarked'] = !empty($res['ok']);
            $bmKeys = admin_bsky_bookmark_keys($subject, $vaakOwnerId);
            $ajaxOut['status_id'] = $bmKeys['status_id'];
            $ajaxOut['object_id'] = $bmKeys['object_id'];
            $ajaxOut['open_folder_picker'] = !empty($res['ok']);
            $ajaxOut['folder_ids'] = [];
            $notice = !empty($res['ok']) ? 'Bookmarked on Bluesky.' : ('Bookmark failed: ' . $ajaxOut['error']);
            if (empty($res['ok'])) {
                $error = $notice;
                $notice = '';
            }
            if (!empty($res['ok'])) {
                if (function_exists('ap_bsky_tl_cache_clear_owner')) {
                    ap_bsky_tl_cache_clear_owner($vaakOwnerId);
                }
                // Always mirror into VAAK bookmarks so the folder picker works
                // (local AP twin when mapped, otherwise a stable bsky: status key).
                if ($bmKeys['status_id'] !== '' && function_exists('ap_masto_bookmark_add')) {
                    try {
                        ap_masto_bookmark_add($bmKeys['status_id'], $bmKeys['object_id'], $vaakOwnerId);
                    } catch (Throwable $e) {
                        // ignore
                    }
                }
                if ($bmKeys['status_id'] !== '' && function_exists('vaak_bookmark_folders_for_status')) {
                    $ajaxOut['folder_ids'] = vaak_bookmark_folders_for_status($bmKeys['status_id'], $vaakOwnerId);
                }
            }
        }
        if ($wantAjax) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            http_response_code(!empty($ajaxOut['ok']) ? 200 : 422);
            echo json_encode($ajaxOut, JSON_UNESCAPED_SLASHES);
            exit;
        }
    } elseif ($action === 'bsky_connect' || $action === 'bsky_disconnect' || $action === 'bsky_sync_profile') {
        $view = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'profile')) ?: 'profile';
        if (!function_exists('ap_bsky_tab_enabled') || !ap_bsky_tab_enabled()) {
            $error = 'Bluesky tab is disabled on this instance.';
        } elseif ($action === 'bsky_disconnect') {
            ap_bsky_disconnect($vaakOwnerId);
            $notice = 'Bluesky disconnected.';
        } elseif ($action === 'bsky_sync_profile') {
            if (!function_exists('ap_bsky_sync_profile_from_vaak')) {
                $error = 'Bluesky profile sync unavailable.';
            } else {
                $sync = ap_bsky_sync_profile_from_vaak($vaakOwnerId, $vaakActorKey);
                if (!empty($sync['ok'])) {
                    $notice = 'Bluesky profile re-synced from VAAK'
                        . (!empty($sync['truncated']) ? ' (bio truncated with profile link).' : '.');
                } else {
                    $error = 'Bluesky profile sync failed: ' . (string) ($sync['error'] ?? 'unknown error');
                }
            }
        } else {
            $handle = trim((string) ($_POST['bsky_handle'] ?? ''));
            $appPass = trim((string) ($_POST['bsky_app_password'] ?? ''));
            $pds = trim((string) ($_POST['bsky_pds'] ?? ''));
            if ($pds === '') {
                $pds = AP_BSKY_DEFAULT_PDS;
            }
            $res = ap_bsky_connect($vaakOwnerId, $handle, $appPass, $pds);
            if (!empty($res['ok'])) {
                $notice = 'Bluesky connected as @' . (string) ($res['handle'] ?? $handle) . '.';
                if (!empty($res['profile_synced'])) {
                    $notice .= ' VAAK avatar / header / bio synced to Bluesky.';
                } elseif (!empty($res['profile_sync_error'])) {
                    $notice .= ' (Profile sync: ' . (string) $res['profile_sync_error'] . ')';
                }
            } else {
                $error = $res['error'] ?? 'Bluesky connect failed.';
            }
        }
    } elseif ($action === 'save_xai_key') {
        $view = 'profile';
        $key = trim((string) ($_POST['xai_api_key'] ?? ''));
        $apiRootIn = trim((string) ($_POST['ai_api_root'] ?? ''));
        $modelIn = trim((string) ($_POST['ai_model'] ?? ''));
        $hasKey = $key !== '';
        $hasEndpoint = $apiRootIn !== '' || $modelIn !== ''
            || array_key_exists('ai_api_root', $_POST)
            || array_key_exists('ai_model', $_POST);
        if (!$hasKey && !$hasEndpoint) {
            $error = 'Paste an API key, or use Clear to remove your saved key.';
        } else {
            $ok = true;
            if ($hasKey) {
                $res = ap_auth_user_set_xai_key($vaakOwnerId, $key);
                if (empty($res['ok'])) {
                    $ok = false;
                    $error = $res['error'] ?? 'Could not save API key.';
                }
            }
            if ($ok && function_exists('ap_auth_user_set_ai_endpoint')) {
                $ep = ap_auth_user_set_ai_endpoint(
                    $vaakOwnerId,
                    array_key_exists('ai_api_root', $_POST) ? $apiRootIn : null,
                    array_key_exists('ai_model', $_POST) ? $modelIn : null
                );
                if (empty($ep['ok'])) {
                    $ok = false;
                    $error = $ep['error'] ?? 'Could not save AI endpoint settings.';
                }
            }
            if ($ok) {
                $fresh = ap_auth_user_by_id($vaakOwnerId);
                if (is_array($fresh)) {
                    $vaakUser = $fresh;
                    $GLOBALS['vaak_user'] = $fresh;
                }
                $notice = $hasKey
                    ? 'AI alt-text API key saved for your account (encrypted).'
                    : 'AI endpoint settings saved.';
            }
        }
    } elseif ($action === 'clear_xai_key') {
        $view = 'profile';
        $res = ap_auth_user_set_xai_key($vaakOwnerId, null);
        if (!empty($res['ok'])) {
            if (function_exists('ap_auth_user_set_ai_endpoint')) {
                ap_auth_user_set_ai_endpoint($vaakOwnerId, '', '');
            }
            $fresh = ap_auth_user_by_id($vaakOwnerId);
            if (is_array($fresh)) {
                $vaakUser = $fresh;
                $GLOBALS['vaak_user'] = $fresh;
            }
            $notice = 'Cleared your AI alt-text API key.'
                . ($vaakActorKey === 'cmdr_nova'
                    ? ' Operator host key still available as fallback for you only.'
                    : '');
        } else {
            $error = $res['error'] ?? 'Could not clear API key.';
        }
    } elseif ($action === 'discuss_topic_create') {
        $view = 'discuss';
        $categorySlug = strtolower(trim((string) ($_POST['category_slug'] ?? '')));
        $category = ap_discuss_category($categorySlug);
        $res = $category
            ? ap_discuss_topic_create((int) $category['id'], $vaakOwnerId, (string) ($_POST['topic_title'] ?? ''), (string) ($_POST['topic_body'] ?? ''))
            : ['ok' => false, 'error' => 'Discussion category not found.'];
        if (!empty($res['ok'])) {
            header('Location: ?view=discuss&topic=' . (int) ($res['id'] ?? 0));
            exit;
        }
        $error = $res['error'] ?? 'Could not create discussion.';
        $_GET['category'] = $categorySlug;
    } elseif ($action === 'discuss_post_create') {
        $view = 'discuss';
        $topicId = (int) ($_POST['topic_id'] ?? 0);
        $res = ap_discuss_post_create($topicId, $vaakOwnerId, (string) ($_POST['post_body'] ?? ''));
        if (!empty($res['ok'])) {
            header('Location: ?view=discuss&topic=' . $topicId . '#post-' . (int) ($res['id'] ?? 0));
            exit;
        }
        $error = $res['error'] ?? 'Could not save reply.';
        $_GET['topic'] = (string) $topicId;
    } elseif ($action === 'discuss_topic_delete' || $action === 'discuss_post_delete') {
        $view = 'discuss';
        if ($vaakActorKey !== 'cmdr_nova') {
            $error = 'Only the server operator can moderate discussions.';
        } elseif ($action === 'discuss_topic_delete') {
            $topicId = (int) ($_POST['topic_id'] ?? 0);
            $categorySlug = strtolower(trim((string) ($_POST['category_slug'] ?? '')));
            $res = ap_discuss_topic_delete($topicId);
            if (!empty($res['ok'])) {
                $notice = 'Discussion deleted.';
                header('Location: ?view=discuss' . ($categorySlug !== '' ? '&category=' . rawurlencode($categorySlug) : ''));
                exit;
            }
            $error = $res['error'] ?? 'Could not delete discussion.';
            $_GET['topic'] = (string) $topicId;
        } else {
            $postId = (int) ($_POST['post_id'] ?? 0);
            $res = ap_discuss_post_delete($postId);
            if (!empty($res['ok'])) {
                $topicId = (int) ($res['topic_id'] ?? 0);
                $notice = 'Reply deleted.';
                header('Location: ?view=discuss&topic=' . $topicId);
                exit;
            }
            $error = $res['error'] ?? 'Could not delete reply.';
            $_GET['topic'] = (string) ($res['topic_id'] ?? ($_POST['topic_id'] ?? 0));
        }
    } elseif ($action === 'notice_reply') {
        $view = 'notices';
        $res = ap_notice_reply_add(
            (int) ($_POST['notice_id'] ?? 0),
            $vaakOwnerId,
            (string) ($_POST['notice_reply_body'] ?? '')
        );
        if (!empty($res['ok'])) {
            $notice = 'Reply saved locally in VAAK.';
        } else {
            $error = $res['error'] ?? 'Could not save reply.';
        }
    } elseif ($action === 'notice_save' || $action === 'notice_delete') {
        $view = 'notices';
        if ($vaakActorKey !== 'cmdr_nova') {
            $error = 'Only the server operator can change notices.';
        } elseif ($action === 'notice_delete') {
            $res = ap_notice_delete((int) ($_POST['notice_id'] ?? 0));
            if (!empty($res['ok'])) {
                $notice = !empty($res['deleted']) ? 'Notice deleted.' : 'Notice was already gone.';
            } else {
                $error = $res['error'] ?? 'Could not delete notice.';
            }
        } else {
            $noticeId = (int) ($_POST['notice_id'] ?? 0);
            $res = ap_notice_save(
                $noticeId > 0 ? $noticeId : null,
                (string) ($_POST['notice_title'] ?? ''),
                (string) ($_POST['notice_body'] ?? ''),
                !empty($_POST['notice_published'])
            );
            if (!empty($res['ok'])) {
                $notice = $noticeId > 0 ? 'Notice updated.' : 'Notice published.';
            } else {
                $error = $res['error'] ?? 'Could not save notice.';
            }
        }
    } elseif ($action === 'invite_create') {
        $view = 'invites';
        if (!$vaakIsAdmin) {
            $error = 'Admin only.';
            $view = 'home';
        } else {
            $note = trim((string) ($_POST['note'] ?? ''));
            $res = ap_auth_invite_create((int) ($vaakUser['id'] ?? 0), $note, null);
            if (!empty($res['ok'])) {
                $notice = 'Invite created: ' . ($res['code'] ?? '');
            } else {
                $error = $res['error'] ?? 'Could not create invite.';
            }
        }
    } elseif ($action === 'user_ban' || $action === 'user_unban') {
        $view = 'users';
        if (!$vaakIsAdmin) {
            $error = 'Admin only.';
        } else {
            $userId = (int) ($_POST['user_id'] ?? 0);
            $disable = $action === 'user_ban';
            $res = ap_auth_user_set_disabled($userId, $disable);
            if (!empty($res['ok'])) {
                $notice = $disable ? 'User banned and OAuth tokens revoked.' : 'User unbanned.';
            } else {
                $error = $res['error'] ?? 'Could not update user status.';
            }
        }
    } elseif ($action === 'policies_save_privacy' || $action === 'policies_save_conduct' || $action === 'policies_save_rules') {
        $view = 'policies';
        if (!$vaakIsAdmin) {
            $error = 'Admin only.';
            $view = 'home';
        } else {
            require_once __DIR__ . '/ap-instance-docs.php';
            if ($action === 'policies_save_rules') {
                $res = ap_instance_rules_save_from_text((string) ($_POST['rules_text'] ?? ''));
                if (!empty($res['ok'])) {
                    $notice = 'Server rules saved (' . (int) ($res['count'] ?? 0) . '). Shown on /vaak/conduct/ and in Mastodon apps.';
                } else {
                    $error = $res['error'] ?? 'Could not save rules.';
                }
            } else {
                $docKey = $action === 'policies_save_conduct' ? 'conduct' : 'privacy';
                $res = ap_instance_doc_save(
                    $docKey,
                    (string) ($_POST['title'] ?? ''),
                    (string) ($_POST['body'] ?? '')
                );
                if (!empty($res['ok'])) {
                    $notice = ($docKey === 'conduct' ? 'Code of Conduct' : 'Privacy Policy') . ' saved.';
                } else {
                    $error = $res['error'] ?? 'Could not save document.';
                }
            }
        }
    } elseif ($action === 'upload_media') {
        // Pre-upload a single attachment so compose/post bodies stay small on mobile.
        $wantJsonUp = !empty($_POST['ajax'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        $altOne = trim((string) ($_POST['description'] ?? $_POST['media_alt'] ?? ''));
        $file = $_FILES['file'] ?? $_FILES['media'] ?? null;
        $upError = null;
        $upId = 0;
        $upType = '';
        $upUrl = '';
        $upPreview = '';
        if (!is_array($file) || !isset($file['tmp_name'])) {
            $upError = 'No file uploaded.';
        } else {
            // Normalize multi-file shape to one file (first only).
            if (is_array($file['tmp_name'] ?? null)) {
                $file = [
                    'name' => (string) ($file['name'][0] ?? ''),
                    'type' => (string) ($file['type'][0] ?? ''),
                    'tmp_name' => (string) ($file['tmp_name'][0] ?? ''),
                    'error' => (int) ($file['error'][0] ?? UPLOAD_ERR_NO_FILE),
                    'size' => (int) ($file['size'][0] ?? 0),
                ];
            }
            $resUp = function_exists('ap_media_ingest_upload')
                ? ap_media_ingest_upload($file, $altOne !== '' ? $altOne : null)
                : ['ok' => false, 'error' => 'Media upload unavailable.'];
            if (empty($resUp['ok'])) {
                $upError = (string) ($resUp['error'] ?? 'Media upload failed');
            } else {
                $upId = (int) ($resUp['local_id'] ?? 0);
                $att = is_array($resUp['attachment'] ?? null) ? $resUp['attachment'] : [];
                $upType = (string) ($att['type'] ?? '');
                $upUrl = (string) ($att['url'] ?? '');
                $upPreview = (string) ($att['preview_url'] ?? $upUrl);
            }
        }
        if ($wantJsonUp) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            http_response_code($upError === null ? 200 : 400);
            echo json_encode([
                'ok' => $upError === null && $upId > 0,
                'error' => $upError,
                'media_id' => $upId > 0 ? $upId : null,
                'type' => $upType !== '' ? $upType : null,
                'url' => $upUrl !== '' ? $upUrl : null,
                'preview_url' => $upPreview !== '' ? $upPreview : null,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        $error = $upError ?? 'Media upload requires AJAX.';
        $composerForceOpen = true;
    } elseif ($action === 'save_draft') {
        $returnView = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? ($_POST['compose_return_view'] ?? 'home'))) ?: 'home';
        $view = $returnView;
        $draftId = (int) ($_POST['draft_id'] ?? 0);
        $content = (string) ($_POST['content'] ?? '');
        $spoiler = trim((string) ($_POST['spoiler_text'] ?? ''));
        $visibility = function_exists('ap_normalize_visibility')
            ? ap_normalize_visibility($_POST['visibility'] ?? 'public')
            : 'public';
        $sensitive = !empty($_POST['sensitive']) || $spoiler !== '';
        $inReplyTo = trim((string) ($_POST['in_reply_to'] ?? ''));
        $toActor = trim((string) ($_POST['to_actor'] ?? ''));
        $quoteObject = trim((string) ($_POST['quote_object'] ?? ''));
        $existingMedia = [];
        $rawExisting = trim((string) ($_POST['draft_media_ids'] ?? ''));
        if ($rawExisting !== '') {
            foreach (preg_split('/[\s,]+/', $rawExisting) ?: [] as $mid) {
                $mid = (int) $mid;
                if ($mid > 0) {
                    $existingMedia[] = $mid;
                }
            }
        }
        $mediaPack = ap_admin_collect_media_uploads();
        if ($mediaPack['error'] !== null) {
            $error = $mediaPack['error'];
        } else {
            $mergedMedia = array_values(array_unique(array_merge($existingMedia, $mediaPack['ids'])));
            $result = ap_draft_save(
                $content,
                $spoiler,
                $sensitive,
                $visibility,
                $inReplyTo,
                $toActor,
                $quoteObject,
                [],
                $draftId,
                $mergedMedia
            );
            if (!empty($result['ok'])) {
                $notice = !empty($result['created']) ? 'Draft saved.' : 'Draft updated.';
                $draftId = (int) ($result['id'] ?? $draftId);
            } else {
                $error = $result['error'] ?? 'Could not save draft.';
            }
        }
        $wantJsonDraft = !empty($_POST['ajax'])
            || str_contains((string) ($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json')
            || strtolower((string) ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest';
        if ($wantJsonDraft) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store');
            $saved = $draftId > 0 ? ap_draft_get($draftId) : null;
            echo json_encode([
                'ok' => $error === null,
                'error' => $error,
                'notice' => $notice,
                'action' => 'save_draft',
                'draft_id' => $draftId > 0 ? $draftId : null,
                'media_ids' => is_array($saved) ? ap_draft_media_ids($saved) : [],
                'drafts_count' => ap_drafts_count(),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            exit;
        }
        if ($error === null) {
            $view = 'drafts';
        } else {
            $composerForceOpen = true;
        }
    } elseif ($action === 'draft_delete') {
        $view = 'drafts';
        $did = (int) ($_POST['draft_id'] ?? 0);
        $result = ap_draft_delete($did);
        if (!empty($result['ok'])) {
            $notice = 'Draft deleted.';
        } else {
            $error = $result['error'] ?? 'Could not delete draft.';
        }
    } elseif ($action === 'draft_publish') {
        $view = 'drafts';
        $did = (int) ($_POST['draft_id'] ?? 0);
        $draft = ap_draft_get($did);
        if ($draft === null) {
            $error = 'Draft not found.';
        } else {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
            $mediaIds = ap_draft_media_ids($draft);
            $result = ap_local_post_reply(
                (string) ($draft['content'] ?? ''),
                (string) ($draft['in_reply_to'] ?? ''),
                (($ta = trim((string) ($draft['to_actor'] ?? ''))) !== '' ? $ta : null),
                (string) ($draft['spoiler_text'] ?? ''),
                !empty($draft['sensitive']),
                (($qo = trim((string) ($draft['quote_object'] ?? ''))) !== '' ? $qo : null),
                $mediaIds,
                (string) ($draft['visibility'] ?? 'public')
            );
            if (!empty($result['ok'])) {
                ap_draft_delete($did);
                $notice = 'Draft published.';
                $view = 'outbox';
            } else {
                $error = $result['error'] ?? 'Could not publish draft.';
            }
        }
    } elseif ($action === 'dm_send' || $action === 'dm_reply') {
        $view = 'dms';
        $to = trim((string) ($_POST['to'] ?? ''));
        $content = trim((string) ($_POST['content'] ?? ''));
        $inReplyTo = trim((string) ($_POST['in_reply_to'] ?? ''));
        $peerView = trim((string) ($_POST['peer'] ?? ''));
        if ($peerView !== '') {
            $view = 'dms';
        }
        if (!defined('AP_INBOX_LIB_ONLY')) {
            define('AP_INBOX_LIB_ONLY', true);
        }
        require_once __DIR__ . '/ap-inbox.php';
        if ($to === '' || $content === '') {
            $error = 'Recipient and message text are required.';
        } else {
            $res = ap_dm_send($content, $to, $inReplyTo !== '' ? $inReplyTo : null);
            if (!empty($res['ok'])) {
                $notice = 'DM sent to ' . ($res['peer'] ?? $to)
                    . ' · delivered=' . (int) ($res['delivered'] ?? 0);
                if (!empty($res['peer'])) {
                    $_GET['peer'] = $res['peer'];
                }
            } else {
                $error = $res['error'] ?? 'DM failed.';
            }
        }
    } elseif ($action === 'dm_mark_read') {
        $view = 'dms';
        $peer = trim((string) ($_POST['peer'] ?? ''));
        if ($peer !== '') {
            ap_dm_mark_peer_read($peer);
            $notice = 'Marked conversation read.';
            $_GET['peer'] = $peer;
        }
    } elseif ($action === 'set_app_password') {
        $view = 'security';
        if (!$vaakIsAdmin || $vaakActorKey !== 'cmdr_nova') {
            $error = 'Admin only.';
            $view = 'home';
        } else {
        $pass = (string) ($_POST['password'] ?? '');
        $pass2 = (string) ($_POST['password_confirm'] ?? '');
        if ($pass !== $pass2) {
            $error = 'Passwords do not match.';
        } else {
            $res = ap_app_password_set($pass);
            if (!empty($res['ok'])) {
                // Keep VAAK web login in sync (cmdr_nova uses the same password)
                $row = ap_db()->query('SELECT password_hash FROM app_auth WHERE id = 1')->fetch();
                if (is_array($row) && !empty($row['password_hash'])) {
                    ap_auth_sync_admin_password_hash((string) $row['password_hash']);
                }
                $notice = 'App password saved. Use it for VAAK login and Ice Cubes (@cmdr_nova@mkultra.monster).';
            } else {
                $error = $res['error'] ?? 'Could not save app password.';
            }
        }
        }
    } elseif ($action === 'change_password') {
        $returnView = preg_replace('/[^a-z_]/', '', (string) ($_POST['return_view'] ?? 'security')) ?: 'security';
        if (!in_array($returnView, ['security', 'profile'], true)) {
            $returnView = 'security';
        }
        $view = $returnView;
        $pass = (string) ($_POST['password'] ?? '');
        $pass2 = (string) ($_POST['password_confirm'] ?? '');
        if ($pass !== $pass2) {
            $error = 'Passwords do not match.';
        } else {
            $res = ap_auth_user_set_password($vaakOwnerId, $pass);
            if (!empty($res['ok'])) {
                // Keep Ice Cubes app password in sync only for cmdr_nova
                if ($vaakActorKey === 'cmdr_nova' && function_exists('ap_app_password_set')) {
                    ap_app_password_set($pass);
                }
                $notice = 'Account password updated. Use it next time you log in to VAAK.';
            } else {
                $error = $res['error'] ?? 'Could not update password.';
            }
        }
    } elseif ($action === 'revoke_oauth_token') {
        $view = 'security';
        $tid = (int) ($_POST['token_id'] ?? 0);
        if ($tid > 0 && ap_oauth_token_revoke_by_id($tid, $vaakOwnerId)) {
            $notice = 'OAuth token #' . $tid . ' revoked. Re-authorize the app if you still use it.';
        } else {
            $error = 'Could not revoke that token (already revoked, missing, or not yours).';
        }
    } elseif ($action === 'purge_oauth_tokens') {
        $view = 'security';
        if (!$vaakIsAdmin || $vaakActorKey !== 'cmdr_nova') {
            $error = 'Admin only.';
            $view = 'home';
        } else {
            $mode = (string) ($_POST['purge_mode'] ?? 'revoked');
            if ($mode === 'revoked') {
                // Clear all revoked rows now (active sessions untouched)
                $purge = ap_oauth_tokens_purge_stale(7, true);
                $notice = 'Purged revoked tokens: ' . (int) ($purge['revoked_deleted'] ?? 0)
                    . ' · expired: ' . (int) ($purge['expired_deleted'] ?? 0)
                    . ' · unused apps: ' . (int) ($purge['apps_deleted'] ?? 0)
                    . '. Active tokens kept. Daily maintain also auto-deletes revoked after 7 days.';
            } else {
                $purge = ap_oauth_tokens_purge_stale(7, false);
                $notice = 'Purged stale OAuth rows (revoked ≥7d / fully expired): '
                    . ((int) ($purge['revoked_deleted'] ?? 0) + (int) ($purge['expired_deleted'] ?? 0))
                    . ' tokens · apps ' . (int) ($purge['apps_deleted'] ?? 0) . '.';
            }
        }
    }
}

// Bookmark folder list for one-tap picker (VAAK-only; not Mastodon API)
if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'bookmark_folders') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $statusId = trim((string) ($_GET['status_id'] ?? ''));
    $folders = function_exists('vaak_bookmark_folders_list')
        ? vaak_bookmark_folders_list($vaakOwnerId)
        : [];
    $selected = ($statusId !== '' && function_exists('vaak_bookmark_folders_for_status'))
        ? vaak_bookmark_folders_for_status($statusId, $vaakOwnerId)
        : [];
    echo json_encode([
        'ok' => true,
        'folders' => $folders,
        'selected' => $selected,
        'status_id' => $statusId,
    ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

// Lightweight JSON for nav badge / tab title polling (before heavy feed queries)
if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'notif_unread') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    // Bypass the short file cache so mobile polls see new mentions promptly.
    // latest_unread_id (not overall latest) drives the AIM chime — hydration
    // of the full notifications feed used to make overall latest_id jump around.
    $state = [
        'count' => 0,
        'last_read_id' => '0',
        'latest_unread_id' => '',
        'latest_id' => '',
    ];
    try {
        if (function_exists('ap_masto_notifications_unread_state')) {
            $state = ap_masto_notifications_unread_state(80, true);
        } elseif (function_exists('ap_masto_notifications_unread_count')) {
            $state['count'] = ap_masto_notifications_unread_count(80);
        }
    } catch (Throwable $e) {
        // Keep the lightweight badge endpoint usable if a remote actor is stale.
    }
    $dmCount = function_exists('ap_dm_unread_count') ? ap_dm_unread_count() : 0;
    $latestDmId = '';
    try {
        $ownerForDm = function_exists('admin_owner_user_id') ? admin_owner_user_id() : (int) ($vaakUser['id'] ?? 0);
        $dmLatest = ap_db()->prepare(
            "SELECT id FROM direct_messages
             WHERE owner_user_id = ? AND direction = 'in' AND read_at IS NULL AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1"
        );
        $dmLatest->execute([$ownerForDm]);
        $latestDmId = (string) ($dmLatest->fetchColumn() ?: '');
    } catch (Throwable $e) {
        // Keep the badge endpoint usable if DM metadata is temporarily busy.
    }
    $latestUnread = preg_replace('/\D+/', '', (string) ($state['latest_unread_id'] ?? '')) ?: '';
    $latestAny = preg_replace('/\D+/', '', (string) ($state['latest_id'] ?? '')) ?: '';
    echo json_encode([
        'count' => (int) ($state['count'] ?? 0),
        'dm_count' => (int) $dmCount,
        'latest_unread_id' => $latestUnread,
        // Keep latest_id as an alias of the unread tip for older cached JS.
        'latest_id' => $latestUnread !== '' ? $latestUnread : $latestAny,
        'last_read_id' => preg_replace('/\D+/', '', (string) ($state['last_read_id'] ?? '0')) ?: '0',
        'latest_dm_id' => $latestDmId,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

// Browser Web Push (VAAK session → oauth token → push_subscriptions)
if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'push_vapid') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $pub = function_exists('ap_webpush_vapid_public_key') ? ap_webpush_vapid_public_key() : '';
    echo json_encode([
        'ok' => $pub !== '',
        'publicKey' => $pub,
        'error' => $pub === '' ? 'VAPID keys not configured on this server.' : null,
    ], JSON_UNESCAPED_SLASHES);
    exit;
}
if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'push_status') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $uid = function_exists('admin_owner_user_id') ? admin_owner_user_id() : (int) ($vaakUser['id'] ?? 0);
    $enabled = $uid > 0 && function_exists('ap_webpush_user_has_subscription')
        ? ap_webpush_user_has_subscription($uid)
        : false;
    echo json_encode(['ok' => true, 'subscribed' => $enabled], JSON_UNESCAPED_SLASHES);
    exit;
}
if (
    ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && isset($_GET['ajax'])
    && in_array((string) $_GET['ajax'], ['push_subscribe', 'push_unsubscribe'], true)
) {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    $csrfTok = (string) ($_POST['csrf'] ?? $_SERVER['HTTP_X_VAAK_CSRF'] ?? '');
    if (!ap_auth_csrf_ok($csrfTok)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Session expired — refresh and try again.']);
        exit;
    }
    $uid = function_exists('admin_owner_user_id') ? admin_owner_user_id() : (int) ($vaakUser['id'] ?? 0);
    if ($uid < 1) {
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Not signed in.']);
        exit;
    }
    $ajaxPush = (string) $_GET['ajax'];
    try {
        if ($ajaxPush === 'push_unsubscribe') {
            $tokPack = ap_webpush_vaak_web_token_for_user($uid);
            if (!empty($tokPack['ok']) && !empty($tokPack['token_row']['id'])) {
                ap_webpush_subscription_delete((int) $tokPack['token_row']['id']);
            }
            echo json_encode(['ok' => true, 'subscribed' => false]);
            exit;
        }
        $raw = file_get_contents('php://input');
        $in = [];
        if (is_string($raw) && $raw !== '' && str_starts_with(ltrim($raw), '{')) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                $in = $decoded;
            }
        }
        if ($in === [] && !empty($_POST['subscription'])) {
            $subRaw = $_POST['subscription'];
            if (is_string($subRaw)) {
                $decoded = json_decode($subRaw, true);
                if (is_array($decoded)) {
                    $in['subscription'] = $decoded;
                }
            } elseif (is_array($subRaw)) {
                $in['subscription'] = $subRaw;
            }
        }
        $tokPack = ap_webpush_vaak_web_token_for_user($uid);
        if (empty($tokPack['ok'])) {
            echo json_encode(['ok' => false, 'error' => $tokPack['error'] ?? 'Could not mint push token.']);
            exit;
        }
        $entity = ap_webpush_subscription_upsert(
            $tokPack['token_row'],
            (string) $tokPack['access_token'],
            $in
        );
        echo json_encode(['ok' => true, 'subscribed' => true, 'subscription' => $entity], JSON_UNESCAPED_SLASHES);
    } catch (Throwable $e) {
        error_log('[ap-admin] push ajax: ' . $e->getMessage());
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Could not update push subscription.']);
    }
    exit;
}

// Deferred Home suggestions (keeps first paint off the suggestion scorer).
if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'home_suggestions') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $suggestions = function_exists('ap_masto_suggestions_v2') ? ap_masto_suggestions_v2(12) : [];
    if ($suggestions) {
        admin_render_home_suggestions($suggestions);
    }
    exit;
}

// Deferred trends sidebar (recompute / OG fetch happens here, not on every nav).
if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'trends') {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $trendTags = [];
    $trendLinks = [];
    $trendStatuses = [];
    try {
        $trendTags = function_exists('ap_masto_trends_tags') ? ap_masto_trends_tags(5) : [];
        $trendLinks = function_exists('ap_masto_trends_links') ? ap_masto_trends_links(5) : [];
        $trendStatuses = function_exists('ap_masto_trends_statuses') ? ap_masto_trends_statuses(5) : [];
    } catch (Throwable $e) {
        error_log('[ap-admin] ajax trends: ' . $e->getMessage());
    }
    $admin_strim = static function (string $s, int $width): string {
        if (function_exists('mb_strimwidth')) {
            return mb_strimwidth($s, 0, $width, '…');
        }
        return strlen($s) > $width ? (substr($s, 0, max(0, $width - 1)) . '…') : $s;
    };
    $viewForTrends = preg_replace('/[^a-z_]/', '', (string) ($_GET['from'] ?? 'home')) ?: 'home';
    echo '<div class="side-card"><h3>Trending tags</h3>';
    if (!$trendTags) {
        echo '<div class="meta">No hashtag signal yet…</div>';
    } else {
        foreach ($trendTags as $tg) {
            $tname = (string) ($tg['name'] ?? '');
            $usesToday = !empty($tg['history'][0]['uses']) ? (int) $tg['history'][0]['uses'] : 0;
            $usesWeek = 0;
            if (!empty($tg['history']) && is_array($tg['history'])) {
                foreach ($tg['history'] as $hday) {
                    $usesWeek += (int) ($hday['uses'] ?? 0);
                }
            }
            echo '<div class="bar-row"><span><a href="?view=search&amp;q='
                . urlencode('#' . $tname) . '">#' . h($tname) . '</a></span>'
                . '<span class="meta" title="uses today / 7d">' . $usesToday . '/' . $usesWeek . '</span></div>';
        }
    }
    echo '</div>';
    echo '<div class="side-card"><h3>Trending links</h3>';
    if (!$trendLinks) {
        echo '<div class="meta">No link signal yet…</div>';
    } else {
        foreach ($trendLinks as $ln) {
            $title = trim((string) ($ln['title'] ?? ''));
            $url = trim((string) ($ln['url'] ?? ''));
            $host = $url !== '' ? strtolower((string) (parse_url($url, PHP_URL_HOST) ?: '')) : '';
            // When OG title is missing we used to dump the raw URL as the label —
            // unbroken query strings blew out the right rail. Prefer host + short path.
            $titleIsUrl = $title === '' || $title === $url
                || str_starts_with($title, 'http://') || str_starts_with($title, 'https://');
            if ($titleIsUrl && $url !== '') {
                $path = (string) (parse_url($url, PHP_URL_PATH) ?: '');
                $label = $host !== '' ? $host : $url;
                if ($path !== '' && $path !== '/') {
                    $label .= $admin_strim($path, 36);
                }
            } else {
                $label = $admin_strim($title !== '' ? $title : ($host !== '' ? $host : 'link'), 72);
            }
            echo '<div class="trend-link">';
            if ($url !== '') {
                echo '<a href="' . h($url) . '" rel="noopener noreferrer" target="_blank" title="' . h($url) . '">'
                    . h($label) . '</a>';
                if (!$titleIsUrl && $host !== '') {
                    echo '<span class="trend-link-host">' . h($host) . '</span>';
                }
            } else {
                echo h($label);
            }
            echo '</div>';
        }
    }
    echo '</div>';
    echo '<div class="side-card"><h3>Trending posts</h3>';
    if (!$trendStatuses) {
        echo '<div class="meta">No post signal yet…</div>';
    } else {
        foreach ($trendStatuses as $ts) {
            $acct = is_array($ts['account'] ?? null) ? $ts['account'] : [];
            $who = (string) ($acct['acct'] ?? $acct['username'] ?? 'someone');
            // Decode &#039; / &amp; / curly quotes — bare strip_tags left entities visible.
            $content = function_exists('admin_html_to_plain')
                ? admin_html_to_plain((string) ($ts['content'] ?? ''))
                : trim(html_entity_decode(strip_tags((string) ($ts['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $excerpt = $admin_strim($content, 96);
            $surl = (string) ($ts['url'] ?? $ts['uri'] ?? '');
            echo '<div style="margin:0 0 .65rem;line-height:1.35">';
            echo '<div class="meta" style="margin-bottom:.15rem">' . h($who) . '</div>';
            if ($surl !== '') {
                echo '<a href="' . h(admin_status_href($surl, $viewForTrends))
                    . '" style="color:var(--text);text-decoration:none">'
                    . h($excerpt !== '' ? $excerpt : '(media)') . '</a>';
            } else {
                echo '<span>' . h($excerpt !== '' ? $excerpt : '(media)') . '</span>';
            }
            echo '</div>';
        }
    }
    echo '</div>';
    exit;
}

// AIM imrcv.wav for new DMs / notifications (admin-auth protected)
if (isset($_GET['ajax']) && (string) $_GET['ajax'] === 'notif_sound') {
    $candidates = [
        __DIR__ . '/assets/imrcv.wav',
        __DIR__ . '/assets/message_sound.wav',
    ];
    $soundPath = null;
    foreach ($candidates as $cand) {
        if (is_file($cand) && is_readable($cand)) {
            $soundPath = $cand;
            break;
        }
    }
    if ($soundPath === null) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo 'notification sound missing';
        exit;
    }
    header('Content-Type: audio/wav');
    header('Cache-Control: public, max-age=3600');
    header('Content-Length: ' . (string) filesize($soundPath));
    readfile($soundPath);
    exit;
}

$db = ap_db();
$since24 = gmdate('c', time() - 86400);
$since7 = gmdate('c', time() - 7 * 86400);

$tlLimit = isset($_GET['limit']) ? (int) $_GET['limit'] : (in_array(($view ?? ''), ['gallery', 'vakktok'], true) ? 16 : 15);
$tlLimit = max(1, min(40, $tlLimit));
$tlOffset = isset($_GET['offset']) ? max(0, (int) $_GET['offset']) : 0;
$isPartial = isset($_GET['partial']) && (string) $_GET['partial'] === '1';
// Live "anything new?" polls must stay cheap — skip full timeline rebuilds.
$wantNewerPoll = $isPartial
    && isset($_GET['newer'])
    && (string) $_GET['newer'] === '1'
    && in_array($view, ['home', 'feed', 'local', 'gallery', 'vakktok'], true);

// Stats-only event COUNTs (were previously paid on every full-page nav click).
$total24 = 0;
$total7 = 0;
$typeRows = [];
$actionRows = [];
$hostRows = [];
if ($view === 'stats') {
    try {
        $st = ap_db_execute_retry('SELECT COUNT(*) AS c FROM events WHERE created_at >= ?', [$since7]);
        if ($st) {
            $total7 = (int) ($st->fetch()['c'] ?? 0);
        }
        $st = ap_db_execute_retry('SELECT COUNT(*) AS c FROM events WHERE created_at >= ?', [$since24]);
        if ($st) {
            $total24 = (int) ($st->fetch()['c'] ?? 0);
        }
        $st = ap_db_execute_retry(
            'SELECT type, COUNT(*) AS c FROM events WHERE created_at >= ? GROUP BY type ORDER BY c DESC LIMIT 12',
            [$since24]
        );
        $typeRows = $st ? ($st->fetchAll() ?: []) : [];
        $st = ap_db_execute_retry(
            'SELECT action_taken, COUNT(*) AS c FROM events WHERE created_at >= ? GROUP BY action_taken ORDER BY c DESC LIMIT 12',
            [$since24]
        );
        $actionRows = $st ? ($st->fetchAll() ?: []) : [];
        $st = ap_db_execute_retry(
            'SELECT host, COUNT(*) AS c FROM events WHERE created_at >= ? AND host IS NOT NULL GROUP BY host ORDER BY c DESC LIMIT 12',
            [$since24]
        );
        $hostRows = $st ? ($st->fetchAll() ?: []) : [];
    } catch (Throwable $e) {
        error_log('[ap-admin] stats rows: ' . $e->getMessage());
    }
}

// Mentions list only when Notifications / Stats need it (Ice Cubes-style feed uses API helper)
$mentions = [];
if ($view === 'stats') {
    try {
        $mentionsRaw = function_exists('ap_mentions_list')
            ? ap_mentions_list(120, admin_owner_user_id())
            : [];
        foreach ($mentionsRaw as $m) {
            if (function_exists('ap_row_is_hidden')
                ? ap_row_is_hidden($m['actor_id'] ?? null, null, admin_owner_user_id())
                : ap_row_is_blocked($m['actor_id'] ?? null, null)) {
                continue;
            }
            $mType = strtolower((string) ($m['type'] ?? ''));
            if (in_array($mType, ['person', 'application', 'service', 'group'], true)) {
                continue;
            }
            $mentions[] = $m;
            if (count($mentions) >= 60) {
                break;
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-admin] mentions: ' . $e->getMessage());
    }
}

// Follow graphs: full pages need rich URL aliases; partials only need actor_id keys.
$followers = $isPartial ? [] : ap_followers_list($vaakActorId);
$following = ap_following_list($vaakActorId);
// Scope remote_actors username lookups to the follow graph (not the whole table).
$adminUnameByActor = [];
if (!$isPartial) {
    $aliasActorIds = [];
    foreach (array_merge($followers, $following) as $grow) {
        if (!is_array($grow)) {
            continue;
        }
        $ga = rtrim((string) ($grow['actor_id'] ?? ''), '/');
        if ($ga !== '') {
            $aliasActorIds[$ga] = true;
            $aliasActorIds[$ga . '/'] = true;
        }
    }
    if ($aliasActorIds !== []) {
        try {
            foreach (array_chunk(array_keys($aliasActorIds), 400) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = ap_db()->prepare(
                    "SELECT actor_id, username, host FROM remote_actors
                     WHERE actor_id IN ($ph)
                       AND username IS NOT NULL AND username != ''"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll() ?: [] as $ra) {
                    if (!is_array($ra)) {
                        continue;
                    }
                    $rid = rtrim((string) ($ra['actor_id'] ?? ''), '/');
                    $ru = trim((string) ($ra['username'] ?? ''));
                    if ($rid !== '' && $ru !== '' && !ctype_digit($ru)) {
                        $adminUnameByActor[$rid] = [
                            'username' => $ru,
                            'host' => strtolower(trim((string) ($ra['host'] ?? ''))),
                        ];
                    }
                }
            }
        } catch (Throwable $e) {
            // table may be empty on first boot
        }
    }
}
/** @param list<array<string,mixed>> $rows */
$adminIndexActorMap = static function (array $rows, bool $richAliases = true) use ($adminUnameByActor): array {
    $map = [];
    // Prefer preferredUsername from remote_actors (Mastodon /ap/users/{snowflake} ≠ handle)
    $unameByActor = $richAliases ? $adminUnameByActor : [];
    foreach ($rows as $row) {
        if (empty($row['actor_id'])) {
            continue;
        }
        $aid = rtrim((string) $row['actor_id'], '/');
        $map[$aid] = true;
        $map[$aid . '/'] = true;
        $map[(string) $row['actor_id']] = true;
        if (!$richAliases) {
            continue;
        }
        $host = (string) ($row['host'] ?? '');
        if ($host === '') {
            $h = parse_url($aid, PHP_URL_HOST);
            $host = is_string($h) ? strtolower($h) : '';
        } else {
            $host = strtolower($host);
        }
        $uname = (string) ($row['username'] ?? '');
        if ($uname === '' && isset($unameByActor[$aid])) {
            $uname = $unameByActor[$aid]['username'];
            if ($unameByActor[$aid]['host'] !== '') {
                $host = $unameByActor[$aid]['host'];
            }
        }
        if ($uname === '') {
            $path = (string) (parse_url($aid, PHP_URL_PATH) ?? '');
            if (preg_match('#/(?:users|@)([^/]+)/?$#i', $path, $pm)
                && !ctype_digit(rawurldecode($pm[1]))) {
                $uname = rawurldecode($pm[1]);
            }
        }
        // Skip snowflake path segments (/ap/users/1168…) — those are not handles
        if ($uname !== '' && ctype_digit($uname)) {
            $uname = '';
        }
        if ($host !== '' && $uname !== '') {
            $map['https://' . $host . '/@' . $uname] = true;
            $map['https://' . $host . '/users/' . $uname] = true;
            $map['https://' . $host . '/users/' . rawurlencode($uname)] = true;
        }
    }
    return $map;
};
$followingIds = $adminIndexActorMap($following, !$isPartial);
$followerIds = $isPartial ? [] : $adminIndexActorMap($followers, true);

// AJAX: hydrate one thin boost card in place (no full timeline rebuild / no scroll reset)
$hydrateBoost = $isPartial && (string) ($_GET['hydrate_boost'] ?? '') === '1';
if ($hydrateBoost) {
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    $eventId = (int) ($_GET['event_id'] ?? 0);
    $objectId = trim((string) ($_GET['object_id'] ?? ''));
    $returnView = preg_replace('/[^a-z_]/', '', (string) ($_GET['from'] ?? $_GET['return_view'] ?? 'home')) ?: 'home';
    if (!in_array($returnView, ['home', 'feed', 'local', 'mentions', 'status', 'remote_profile', 'foryou'], true)) {
        $returnView = 'home';
    }
    if (!defined('AP_INBOX_LIB_ONLY')) {
        define('AP_INBOX_LIB_ONLY', true);
    }
    require_once __DIR__ . '/ap-inbox.php';
    require_once __DIR__ . '/ap-masto-entities.php';
    $hydrateLocalBoost = (string) ($_GET['hydrate_local_boost'] ?? '') === '1';
    if ($hydrateLocalBoost) {
        $ownerId = admin_owner_user_id();
        $localRow = null;
        if ($objectId !== '' && str_starts_with($objectId, 'https://')) {
            try {
                $st = ap_db()->prepare(
                    "SELECT * FROM masto_reblogs
                     WHERE owner_user_id = ? AND (object_id = ? OR object_id = ?)
                     ORDER BY id DESC LIMIT 1"
                );
                $st->execute([$ownerId, rtrim($objectId, '/'), rtrim($objectId, '/') . '/']);
                $row = $st->fetch();
                $localRow = is_array($row) ? $row : null;
            } catch (Throwable $ex) {
                $localRow = null;
            }
        }
        if (!is_array($localRow)) {
            http_response_code(404);
            echo '<div class="meta">Boost not found.</div>';
            exit;
        }
        $GLOBALS['admin_boost_fetch_budget'] = 1;
        if ($followingIds === [] || count($followingIds) < 3) {
            $followingIds = $adminIndexActorMap($following, true);
        }
        ob_start();
        admin_render_boost_card($localRow, $followingIds, $returnView);
        echo ob_get_clean();
        exit;
    }
    $e = null;
    if ($eventId > 0) {
        try {
            $st = ap_db()->prepare("SELECT * FROM events WHERE id = ? AND type = 'Announce' LIMIT 1");
            $st->execute([$eventId]);
            $row = $st->fetch();
            $e = is_array($row) ? $row : null;
        } catch (Throwable $ex) {
            $e = null;
        }
    }
    if ($e === null && $objectId !== '' && str_starts_with($objectId, 'https://')) {
        try {
            $st = ap_db()->prepare(
                "SELECT * FROM events WHERE type = 'Announce' AND (object_id = ? OR object_id = ?)
                 ORDER BY id DESC LIMIT 1"
            );
            $st->execute([$objectId, rtrim($objectId, '/') . '/']);
            $row = $st->fetch();
            $e = is_array($row) ? $row : null;
        } catch (Throwable $ex) {
            $e = null;
        }
    }
    if (!is_array($e)) {
        http_response_code(404);
        echo '<div class="meta">Boost not found.</div>';
        exit;
    }
    // Allow one sync remote fetch for this card
    $GLOBALS['admin_boost_fetch_budget'] = 1;
    // Rich following aliases help Follow button state on the hydrated card
    if ($followingIds === [] || count($followingIds) < 3) {
        $followingIds = $adminIndexActorMap($following, true);
    }
    ob_start();
    admin_render_remote_boost_card($e, $followingIds, $returnView, false);
    echo ob_get_clean();
    exit;
}

// Phase 2: short-lived ranked timeline index so first paint + infinite scroll
// can hydrate a window instead of rebuilding Home/Local/Federated.
// Refresh (?_r=) bypasses cache so operators still get a hard rebuild.
$adminTlCacheKey = '';
$adminTlRankedCached = null;
$adminTlFromCache = false;
$adminTlForceRefresh = isset($_GET['_r']);
$adminTlCachedHasMore = false;
$adminTlCachedTotal = 0;
if (
    !$wantNewerPoll
    && !$adminTlForceRefresh
    && in_array($view, ['home', 'feed', 'local', 'gallery', 'vakktok'], true)
) {
    $adminTlCacheKey = admin_tl_cache_key($view, $following);
    $adminTlRankedCached = admin_tl_cache_get($adminTlCacheKey);
    $adminTlFromCache = is_array($adminTlRankedCached);
}

// Outbox cards: home / federated mix-in + Your posts
$outbox = [];
/** @var array<string,array<string,mixed>> note_id → masto_statuses row (request cache) */
$GLOBALS['admin_masto_by_note'] = [];
$needOutboxBuild = !$wantNewerPoll && !$adminTlFromCache && (
    in_array($view, ['outbox', 'queue'], true)
    || in_array($view, ['home', 'feed', 'local', 'gallery', 'vakktok'], true)
    || ($isPartial && in_array($view, ['home', 'feed', 'local', 'gallery', 'vakktok'], true))
);
if ($needOutboxBuild) {
    $outbox = ap_outbox_list(40, $vaakActorKey);
    // One IN-query for body/CW/visibility/edit instead of 2–3 prepares per card
    $noteIds = [];
    foreach ($outbox as $n) {
        $nid = (string) ($n['id'] ?? '');
        if ($nid !== '' && str_starts_with($nid, 'https://')) {
            $noteIds[$nid] = true;
            $noteIds[rtrim($nid, '/') . '/'] = true;
        }
    }
    if ($noteIds !== []) {
        try {
            $idList = array_keys($noteIds);
            foreach (array_chunk($idList, 400) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = ap_db()->prepare(
                    "SELECT note_id, local_id, content_text, spoiler_text, sensitive, visibility
                     FROM masto_statuses WHERE note_id IN ($ph)"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll() ?: [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $key = rtrim((string) ($row['note_id'] ?? ''), '/');
                    if ($key !== '') {
                        $GLOBALS['admin_masto_by_note'][$key] = $row;
                        $GLOBALS['admin_masto_by_note'][$key . '/'] = $row;
                    }
                }
            }
        } catch (Throwable $e) {
            // fall back to per-card lookups
        }
    }
}

/**
 * @return array<string,mixed>|null
 */
function admin_masto_row_for_note(string $noteId): ?array
{
    if ($noteId === '') {
        return null;
    }
    $map = $GLOBALS['admin_masto_by_note'] ?? null;
    if (is_array($map)) {
        $key = rtrim($noteId, '/');
        if (isset($map[$key]) && is_array($map[$key])) {
            return $map[$key];
        }
        if (isset($map[$key . '/']) && is_array($map[$key . '/'])) {
            return $map[$key . '/'];
        }
        if (isset($map[$noteId]) && is_array($map[$noteId])) {
            return $map[$noteId];
        }
    }
    try {
        $st = ap_db()->prepare(
            'SELECT note_id, local_id, content_text, spoiler_text, sensitive, visibility
             FROM masto_statuses WHERE note_id = ? OR note_id = ? LIMIT 1'
        );
        $st->execute([$noteId, rtrim($noteId, '/') . '/']);
        $row = $st->fetch();
        if (is_array($row)) {
            if (!isset($GLOBALS['admin_masto_by_note']) || !is_array($GLOBALS['admin_masto_by_note'])) {
                $GLOBALS['admin_masto_by_note'] = [];
            }
            $k = rtrim((string) ($row['note_id'] ?? $noteId), '/');
            $GLOBALS['admin_masto_by_note'][$k] = $row;
            $GLOBALS['admin_masto_by_note'][$k . '/'] = $row;
            return $row;
        }
    } catch (Throwable $e) {
        return null;
    }
    return null;
}

/** Directory for short-lived Home/Federated ranked-id cache. */
function admin_tl_cache_dir(): string
{
    $dir = '/var/lib/mkultra/ap/admin-tl-cache';
    if (!is_dir($dir)) {
        @mkdir($dir, 0750, true);
    }
    return is_dir($dir) && is_writable($dir) ? $dir : sys_get_temp_dir();
}

/** @param list<array<string,mixed>> $following */
function admin_owner_user_id(): int
{
    $id = (int) ($GLOBALS['vaak_owner_id'] ?? 0);
    if ($id > 0) {
        return $id;
    }
    if (!empty($GLOBALS['vaak_user']['id'])) {
        $id = (int) $GLOBALS['vaak_user']['id'];
        if ($id > 0) {
            return $id;
        }
    }
    // Session is required for ap-admin — never fall back to cmdr_nova.
    return 0;
}

function admin_tl_cache_key(string $view, array $following): string
{
    if ($view === 'feed') {
        $view = 'feed';
    } elseif ($view === 'local') {
        $view = 'local';
    } elseif ($view === 'gallery') {
        $view = 'gallery';
    } elseif ($view === 'vakktok') {
        $view = 'vakktok';
    } else {
        $view = 'home';
    }
    $parts = [];
    // Local, Gallery, and VakkTok are follow-set independent (instance/media firehose).
    if ($view !== 'local' && $view !== 'gallery' && $view !== 'vakktok') {
        foreach ($following as $f) {
            $aid = rtrim((string) ($f['actor_id'] ?? ''), '/');
            if ($aid !== '') {
                $parts[] = $aid;
            }
        }
        sort($parts);
    }
    // Fingerprint follow-set + owner (per-user mutes/words/blocks)
    $owner = admin_owner_user_id();
    return $view . '_u' . $owner . '_' . substr(hash('sha256', implode('|', $parts)), 0, 24);
}

/**
 * Compact ranked index entry for one timeline card.
 *
 * @param array{kind?:string,row?:array<string,mixed>,from_tag?:bool} $item
 * @return array{k:string,id:string,t?:int}|null
 */
function admin_tl_rank_entry(array $item): ?array
{
    $kind = (string) ($item['kind'] ?? '');
    $row = is_array($item['row'] ?? null) ? $item['row'] : [];
    if ($kind === 'event') {
        $id = (string) (int) ($row['id'] ?? 0);
        if ($id === '0') {
            return null;
        }
        // Don't rank empty followers-only firehose stubs into the timeline cache
        if (function_exists('admin_event_is_empty_private_stub') && admin_event_is_empty_private_stub($row)) {
            return null;
        }
        $entry = ['k' => 'event', 'id' => $id];
        if (!empty($item['from_tag'])) {
            $entry['t'] = 1;
        }
        return $entry;
    }
    if ($kind === 'outbox') {
        $id = rtrim((string) ($row['id'] ?? ''), '/');
        return $id !== '' ? ['k' => 'outbox', 'id' => $id] : null;
    }
    if ($kind === 'boost') {
        $id = (string) ($row['status_id'] ?? '');
        return $id !== '' ? ['k' => 'boost', 'id' => $id] : null;
    }
    if ($kind === 'bsky') {
        $id = (string) ($row['bsky_uri'] ?? ($row['post']['uri'] ?? ''));
        return $id !== '' ? ['k' => 'bsky', 'id' => $id] : null;
    }
    return null;
}

/**
 * @param list<array{kind?:string,row?:array<string,mixed>,from_tag?:bool}> $timeline
 * @return list<array{k:string,id:string,t?:int}>
 */
function admin_tl_rank_from_timeline(array $timeline): array
{
    $out = [];
    foreach ($timeline as $item) {
        if (!is_array($item)) {
            continue;
        }
        $entry = admin_tl_rank_entry($item);
        if ($entry !== null) {
            $out[] = $entry;
        }
    }
    return $out;
}

/** @param list<array{k:string,id:string,t?:int}> $ranked */
function admin_tl_cache_put(string $key, array $ranked): void
{
    if ($key === '' || $ranked === []) {
        return;
    }
    $safe = preg_replace('/[^a-z0-9_]/', '', $key) ?: 'tl';
    $path = admin_tl_cache_dir() . '/tl_' . $safe . '.json';
    $payload = json_encode([
        'ts' => time(),
        'ranked' => $ranked,
    ], JSON_UNESCAPED_SLASHES);
    if (!is_string($payload) || $payload === '') {
        return;
    }
    @file_put_contents($path, $payload, LOCK_EX);
}

/** Drop short-lived Home/Federated ranked caches (e.g. after muted-words change). */
function admin_tl_cache_clear(): void
{
    $dir = admin_tl_cache_dir();
    foreach (glob($dir . '/tl_*.json') ?: [] as $path) {
        @unlink($path);
    }
}

/**
 * Timeline item hidden by Admin muted words/phrases.
 *
 * @param array{kind?:string,row?:array<string,mixed>} $item
 */
function admin_timeline_item_muted_by_words(array $item): bool
{
    if (!function_exists('ap_row_matches_muted_words')) {
        return false;
    }
    $kind = (string) ($item['kind'] ?? 'event');
    $row = $item['row'] ?? null;
    if (!is_array($row)) {
        return false;
    }
    $extra = [];
    if ($kind === 'outbox') {
        $nid = (string) ($row['id'] ?? '');
        $ms = $nid !== '' ? admin_masto_row_for_note($nid) : null;
        if (is_array($ms)) {
            $extra[] = (string) ($ms['content_text'] ?? '');
            $extra[] = (string) ($ms['spoiler_text'] ?? '');
        }
    }
    return ap_row_matches_muted_words(
        $row,
        $kind === 'boost' ? 'boost' : ($kind === 'outbox' ? 'outbox' : 'event'),
        $extra,
        admin_owner_user_id()
    );
}

/** Hide a timeline row when either its visible actor or boosted original is blocked. */
function admin_timeline_row_hidden(array $row, int $ownerUserId): bool
{
    if (function_exists('ap_timeline_row_is_hidden')) {
        return ap_timeline_row_is_hidden($row, $ownerUserId);
    }
    $actor = (string) ($row['actor_id'] ?? '');
    $host = $row['host'] ?? null;
    if (function_exists('ap_row_is_hidden')
        ? ap_row_is_hidden($actor !== '' ? $actor : null, $host, $ownerUserId)
        : (function_exists('ap_row_is_blocked') && ap_row_is_blocked($actor !== '' ? $actor : null, $host))) {
        return true;
    }
    if (strtolower((string) ($row['type'] ?? '')) === 'announce') {
        $original = function_exists('ap_masto_announce_original_actor')
            ? ap_masto_announce_original_actor($row)
            : null;
        if (($original === null || $original === '') && !empty($row['target_actor'])) {
            $original = (string) $row['target_actor'];
        }
        if ($original !== null && $original !== ''
            && (function_exists('ap_row_is_hidden')
                ? ap_row_is_hidden($original, null, $ownerUserId)
                : (function_exists('ap_row_is_blocked') && ap_row_is_blocked($original, null)))) {
            return true;
        }
    }
    $type = strtolower((string) ($row['type'] ?? ''));
    if (in_array($type, ['quote', 'quotepost'], true)
        && function_exists('ap_quote_post_parent_url')
        && function_exists('ap_masto_actor_url_from_object_url')) {
        $parent = ap_quote_post_parent_url((string) ($row['object_id'] ?? ''));
        $quotedActor = $parent !== null ? ap_masto_actor_url_from_object_url($parent) : null;
        if (is_string($quotedActor) && $quotedActor !== ''
            && (function_exists('ap_row_is_hidden')
                ? ap_row_is_hidden($quotedActor, null, $ownerUserId)
                : (function_exists('ap_row_is_blocked') && ap_row_is_blocked($quotedActor, null)))) {
            return true;
        }
    }
    return false;
}

/**
 * @return list<array{k:string,id:string,t?:int}>|null
 */
function admin_tl_cache_get(string $key, int $ttlSec = 180): ?array
{
    if ($key === '') {
        return null;
    }
    $safe = preg_replace('/[^a-z0-9_]/', '', $key) ?: 'tl';
    $path = admin_tl_cache_dir() . '/tl_' . $safe . '.json';
    if (!is_file($path)) {
        return null;
    }
    $raw = @file_get_contents($path);
    if (!is_string($raw) || $raw === '') {
        return null;
    }
    $data = json_decode($raw, true);
    if (!is_array($data) || !isset($data['ranked']) || !is_array($data['ranked'])) {
        return null;
    }
    $ts = (int) ($data['ts'] ?? 0);
    if ($ts <= 0 || (time() - $ts) > $ttlSec) {
        return null;
    }
    /** @var list<array{k:string,id:string,t?:int}> $ranked */
    $ranked = [];
    foreach ($data['ranked'] as $row) {
        if (!is_array($row) || empty($row['k']) || empty($row['id'])) {
            continue;
        }
        $ranked[] = [
            'k' => (string) $row['k'],
            'id' => (string) $row['id'],
            't' => !empty($row['t']) ? 1 : 0,
        ];
    }
    return $ranked !== [] ? $ranked : null;
}

/**
 * Hydrate ranked keys into timeline items (only the requested window).
 *
 * @param list<array{k:string,id:string,t?:int}> $slice
 * @return list<array{kind:string,row:array<string,mixed>,from_tag?:bool,sort?:int}>
 */
function admin_tl_hydrate(array $slice): array
{
    $items = [];
    $eventIds = [];
    $outboxIds = [];
    $boostIds = [];
    $bskyUris = [];
    foreach ($slice as $entry) {
        $k = (string) ($entry['k'] ?? '');
        $id = (string) ($entry['id'] ?? '');
        if ($k === 'event' && $id !== '') {
            $eventIds[] = (int) $id;
        } elseif ($k === 'outbox' && $id !== '') {
            $outboxIds[] = $id;
        } elseif ($k === 'boost' && $id !== '') {
            $boostIds[] = $id;
        } elseif ($k === 'bsky' && $id !== '') {
            $bskyUris[] = $id;
        }
    }
    $eventsById = [];
    if ($eventIds !== []) {
        try {
            foreach (array_chunk(array_values(array_unique($eventIds)), 200) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = ap_db()->prepare("SELECT * FROM events WHERE id IN ($ph)");
                $st->execute($chunk);
                foreach ($st->fetchAll() ?: [] as $erow) {
                    if (is_array($erow)) {
                        $eventsById[(int) ($erow['id'] ?? 0)] = $erow;
                    }
                }
            }
        } catch (Throwable $e) {
            // leave empty
        }
    }
    $outboxById = [];
    if ($outboxIds !== []) {
        try {
            foreach (array_chunk(array_values(array_unique($outboxIds)), 200) as $chunk) {
                $bind = [];
                $ors = [];
                foreach ($chunk as $oid) {
                    $ors[] = 'id = ? OR id = ?';
                    $bind[] = $oid;
                    $bind[] = rtrim($oid, '/') . '/';
                }
                $st = ap_db()->prepare('SELECT * FROM outbox_notes WHERE ' . implode(' OR ', $ors));
                $st->execute($bind);
                foreach ($st->fetchAll() ?: [] as $nrow) {
                    if (is_array($nrow)) {
                        $key = rtrim((string) ($nrow['id'] ?? ''), '/');
                        if ($key !== '') {
                            $outboxById[$key] = $nrow;
                        }
                    }
                }
            }
        } catch (Throwable $e) {
            // leave empty
        }
    }
    $boostById = [];
    if ($boostIds !== []) {
        foreach (array_unique($boostIds) as $sid) {
            $rb = function_exists('ap_masto_reblog_row_by_status')
                ? ap_masto_reblog_row_by_status((string) $sid)
                : null;
            // Local timeline may include other locals' boosts — fall back to any owner.
            if (!is_array($rb)) {
                try {
                    $st = ap_db()->prepare(
                        'SELECT * FROM masto_reblogs WHERE status_id = ? OR boost_status_id = ? ORDER BY id DESC LIMIT 1'
                    );
                    $st->execute([(string) $sid, (string) $sid]);
                    $found = $st->fetch();
                    $rb = is_array($found) ? $found : null;
                } catch (Throwable $e) {
                    $rb = null;
                }
            }
            if (is_array($rb)) {
                $boostById[(string) $sid] = $rb;
            }
        }
    }
    foreach ($slice as $entry) {
        $k = (string) ($entry['k'] ?? '');
        $id = (string) ($entry['id'] ?? '');
        if ($k === 'event') {
            $erow = $eventsById[(int) $id] ?? null;
            if (!is_array($erow)) {
                continue;
            }
            if (admin_event_is_empty_private_stub($erow)) {
                continue;
            }
            $item = [
                'kind' => 'event',
                'sort' => strtotime((string) ($erow['created_at'] ?? '')) ?: (int) ($erow['id'] ?? 0),
                'row' => $erow,
            ];
            if (!empty($entry['t'])) {
                $item['from_tag'] = true;
                $erow['_from_followed_tag'] = true;
                $item['row'] = $erow;
            }
            $items[] = $item;
        } elseif ($k === 'outbox') {
            $nrow = $outboxById[rtrim($id, '/')] ?? null;
            if (!is_array($nrow)) {
                continue;
            }
            $items[] = [
                'kind' => 'outbox',
                'sort' => strtotime((string) ($nrow['published'] ?? '')) ?: 0,
                'row' => $nrow,
            ];
        } elseif ($k === 'boost') {
            $rb = $boostById[$id] ?? null;
            if (!is_array($rb)) {
                continue;
            }
            $items[] = [
                'kind' => 'boost',
                'sort' => strtotime((string) ($rb['created_at'] ?? '')) ?: 0,
                'row' => $rb,
            ];
        } elseif ($k === 'bsky') {
            $bItem = function_exists('ap_bsky_post_item_by_uri')
                ? ap_bsky_post_item_by_uri($id)
                : null;
            if (!is_array($bItem)) {
                continue;
            }
            $indexed = (string) (
                $bItem['indexed_at']
                ?? ($bItem['post']['indexedAt'] ?? ($bItem['post']['record']['createdAt'] ?? ''))
            );
            $items[] = [
                'kind' => 'bsky',
                'sort' => strtotime($indexed) ?: 0,
                'row' => $bItem,
            ];
        }
    }
    return $items;
}

/**
 * Append older remote events to a ranked Home/Federated index (cursor by created_at).
 * Own posts/boosts stay in the head mix only — deep pages stay network-centric.
 *
 * @param list<array{k:string,id:string,t?:int}> $ranked
 * @param list<array<string,mixed>> $following
 * @return list<array{k:string,id:string,t?:int}>|null extended ranked list, or null if nothing added
 */
function admin_tl_extend_ranked(string $view, array $following, array $ranked, int $want = 80): ?array
{
    if ($ranked === []) {
        return null;
    }
    $want = max(20, min(200, $want));
    $tail = admin_tl_hydrate([end($ranked) ?: []]);
    $beforeTs = 0;
    $beforeId = 0;
    if ($tail !== [] && is_array($tail[0])) {
        $beforeTs = (int) ($tail[0]['sort'] ?? 0);
        $row = is_array($tail[0]['row'] ?? null) ? $tail[0]['row'] : [];
        $beforeId = (int) ($row['id'] ?? 0);
    }
    if ($beforeTs <= 0) {
        return null;
    }
    $beforeAt = gmdate('c', $beforeTs);
    $seenIds = [];
    $seenOutbox = [];
    $seenBsky = [];
    foreach ($ranked as $entry) {
        $ek = (string) ($entry['k'] ?? '');
        if ($ek === 'event' && isset($entry['id'])) {
            $seenIds[(string) $entry['id']] = true;
        } elseif ($ek === 'outbox' && isset($entry['id'])) {
            $seenOutbox[rtrim((string) $entry['id'], '/')] = true;
        } elseif ($ek === 'bsky' && isset($entry['id'])) {
            $seenBsky[(string) $entry['id']] = true;
        }
    }

    $db = ap_db();
    $added = [];
    if ($view === 'local') {
        try {
            $st = $db->prepare(
                "SELECT id FROM outbox_notes
                 WHERE id LIKE 'https://mkultra.monster/users/%/notes/%'
                   AND published < ?
                 ORDER BY published DESC
                 LIMIT ?"
            );
            $st->execute([$beforeAt, $want * 2]);
            foreach ($st->fetchAll() ?: [] as $nrow) {
                $oid = rtrim((string) ($nrow['id'] ?? ''), '/');
                if ($oid === '' || isset($seenOutbox[$oid])) {
                    continue;
                }
                $seenOutbox[$oid] = true;
                $added[] = ['k' => 'outbox', 'id' => $oid];
                if (count($added) >= $want) {
                    break;
                }
            }
        } catch (Throwable $e) {
            error_log('[ap-admin] local extend: ' . $e->getMessage());
        }
        return $added === [] ? null : array_merge($ranked, $added);
    }

    if ($view === 'gallery' || $view === 'vakktok') {
        try {
            $st = $db->prepare(
                "SELECT * FROM events
                 WHERE type IN ('Create', 'Quote', 'QuotePost')
                   AND action_taken IN ('log', 'local_observe')
                   AND media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]'
                   AND created_at < ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT ?"
            );
            $st->execute([$beforeAt, $want * 4]);
            foreach ($st->fetchAll() ?: [] as $erow) {
                if (!is_array($erow)) {
                    continue;
                }
                $eid = (string) (int) ($erow['id'] ?? 0);
                if ($eid === '0' || isset($seenIds[$eid])) {
                    continue;
                }
                if (admin_timeline_row_hidden($erow, admin_owner_user_id())) {
                    continue;
                }
                if (function_exists('ap_row_matches_muted_words')
                    && ap_row_matches_muted_words($erow, 'event', [], admin_owner_user_id())) {
                    continue;
                }
                if (!admin_gallery_event_has_media($erow)) {
                    continue;
                }
                $seenIds[$eid] = true;
                $added[] = ['k' => 'event', 'id' => $eid];
                if (count($added) >= $want) {
                    break;
                }
            }
        } catch (Throwable $e) {
            error_log('[ap-admin] gallery extend: ' . $e->getMessage());
        }
        return $added === [] ? null : array_merge($ranked, $added);
    }

    $view = $view === 'feed' ? 'feed' : 'home';

    if ($view === 'feed') {
        try {
            $ownActorLike = '%mkultra.monster/users/' . vaak_actor_key() . '%';
            $st = $db->prepare(
                "SELECT * FROM events
                 WHERE type IN ('Create', 'Quote', 'QuotePost')
                   AND action_taken IN ('log', 'local_observe')
                   AND (
                     (summary IS NOT NULL AND summary != '')
                     OR (media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]')
                     OR (spoiler_text IS NOT NULL AND spoiler_text != '')
                     OR (sensitive IS NOT NULL AND sensitive != 0)
                   )
                   AND (actor_id IS NULL OR actor_id NOT LIKE ?)
                   AND (created_at < ? OR (created_at = ? AND id < ?))
                 ORDER BY created_at DESC, id DESC
                 LIMIT ?"
            );
            $st->execute([$ownActorLike, $beforeAt, $beforeAt, max(0, $beforeId), $want * 2]);
            $rows = $st->fetchAll() ?: [];
        } catch (Throwable $e) {
            error_log('[ap-admin] feed extend: ' . $e->getMessage());
            $rows = [];
        }
        foreach ($rows as $e) {
            if (!is_array($e)) {
                continue;
            }
            $eid = (string) (int) ($e['id'] ?? 0);
            if ($eid === '0' || isset($seenIds[$eid])) {
                continue;
            }
            if (admin_timeline_row_hidden($e, admin_owner_user_id())) {
                continue;
            }
            if (function_exists('ap_row_matches_muted_words') && ap_row_matches_muted_words($e, 'event', [], admin_owner_user_id())) {
                continue;
            }
            $seenIds[$eid] = true;
            $added[] = ['k' => 'event', 'id' => $eid];
            if (count($added) >= $want) {
                break;
            }
        }
    } else {
        $actorIds = [];
        foreach ($following as $frow) {
            $fa = rtrim((string) ($frow['actor_id'] ?? ''), '/');
            if ($fa !== '') {
                $actorIds[$fa] = true;
                $actorIds[$fa . '/'] = true;
            }
        }
        if ($actorIds === []) {
            return null;
        }
        $homeRaw = [];
        foreach (array_chunk(array_keys($actorIds), 400) as $chunk) {
            $ph = implode(',', array_fill(0, count($chunk), '?'));
            try {
                $params = $chunk;
                $params[] = $beforeAt;
                $params[] = $beforeAt;
                $params[] = max(0, $beforeId);
                $params[] = $want * 2;
                $st = $db->prepare(
                    "SELECT * FROM events
                     WHERE type IN ('Create', 'Announce', 'Quote', 'QuotePost')
                       AND (action_taken = 'log' OR action_taken = 'local_observe')
                       AND actor_id IN ($ph)
                       AND (created_at < ? OR (created_at = ? AND id < ?))
                     ORDER BY created_at DESC, id DESC
                     LIMIT ?"
                );
                $st->execute($params);
                foreach ($st->fetchAll() ?: [] as $erow) {
                    if (is_array($erow)) {
                        $homeRaw[] = $erow;
                    }
                }
            } catch (Throwable $e) {
                error_log('[ap-admin] home extend: ' . $e->getMessage());
            }
        }
        usort($homeRaw, static function ($a, $b) {
            $cmp = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
            if ($cmp !== 0) {
                return $cmp;
            }
            return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
        });
        /** @var list<array{k:string,id:string,sort:int}> $homeCand */
        $homeCand = [];
        foreach ($homeRaw as $e) {
            $eid = (string) (int) ($e['id'] ?? 0);
            if ($eid === '0' || isset($seenIds[$eid])) {
                continue;
            }
            $aid = (string) ($e['actor_id'] ?? '');
            if ($aid === '' || admin_timeline_row_hidden($e, admin_owner_user_id())) {
                continue;
            }
            if (function_exists('ap_row_matches_muted_words') && ap_row_matches_muted_words($e, 'event', [], admin_owner_user_id())) {
                continue;
            }
            if (vaak_is_own_url($aid)) {
                continue;
            }
            $sum = trim(html_entity_decode((string) ($e['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $med = (string) ($e['media_urls'] ?? '');
            $etype = strtolower((string) ($e['type'] ?? ''));
            if ($sum === '' && ($med === '' || $med === '[]') && $etype !== 'announce') {
                continue;
            }
            $seenIds[$eid] = true;
            $homeCand[] = [
                'k' => 'event',
                'id' => $eid,
                'sort' => strtotime((string) ($e['created_at'] ?? '')) ?: 0,
            ];
            if (count($homeCand) >= $want * 2) {
                break;
            }
        }
        // Older Bluesky cache rows so deep Home scroll keeps the mix.
        if (
            function_exists('ap_bsky_tab_enabled') && ap_bsky_tab_enabled()
            && function_exists('ap_bsky_posts_for_home')
            && function_exists('ap_bsky_session_row')
        ) {
            $ownerId = admin_owner_user_id();
            $sess = $ownerId > 0 ? ap_bsky_session_row($ownerId) : null;
            if (is_array($sess)) {
                $ownDid = (string) ($sess['did'] ?? '');
                $bskyOlder = ap_bsky_posts_for_home(
                    $ownerId,
                    max(20, min(60, $want)),
                    $ownDid !== '' ? $ownDid : null,
                    $beforeAt
                );
                foreach ($bskyOlder as $bItem) {
                    if (!is_array($bItem)) {
                        continue;
                    }
                    $bUri = (string) ($bItem['bsky_uri'] ?? ($bItem['post']['uri'] ?? ''));
                    if ($bUri === '' || isset($seenBsky[$bUri])) {
                        continue;
                    }
                    $authorDid = (string) ($bItem['post']['author']['did'] ?? '');
                    if ($authorDid !== '' && function_exists('ap_bsky_filter_hidden_authors')) {
                        $filtered = ap_bsky_filter_hidden_authors($ownerId, [['post' => $bItem['post']]]);
                        if ($filtered === []) {
                            continue;
                        }
                    }
                    $indexed = (string) ($bItem['indexed_at'] ?? ($bItem['post']['indexedAt'] ?? ''));
                    $sortTs = strtotime($indexed) ?: 0;
                    if ($sortTs <= 0 || $sortTs >= $beforeTs) {
                        continue;
                    }
                    $seenBsky[$bUri] = true;
                    $homeCand[] = ['k' => 'bsky', 'id' => $bUri, 'sort' => $sortTs];
                }
            }
        }
        usort($homeCand, static fn($a, $b) => ($b['sort'] ?? 0) <=> ($a['sort'] ?? 0));
        // Soft-space Bluesky in the extend window (~30%, ≥2 fedi between).
        $bskyEmitted = 0;
        $sinceBsky = 2; // allow a Bluesky card first in the extend window
        $deferred = [];
        foreach ($homeCand as $cand) {
            $isBsky = ((string) ($cand['k'] ?? '')) === 'bsky';
            if ($isBsky) {
                if (($sinceBsky < 2 && $added !== [])
                    || (($bskyEmitted + 1) / max(1, count($added) + 1) > 0.30)
                ) {
                    $deferred[] = $cand;
                    continue;
                }
                $bskyEmitted++;
                $sinceBsky = 0;
            } else {
                $sinceBsky++;
                while ($deferred !== []) {
                    if ($sinceBsky < 2) {
                        break;
                    }
                    if (($bskyEmitted + 1) / max(1, count($added) + 1) > 0.30) {
                        break;
                    }
                    $d = array_shift($deferred);
                    $added[] = ['k' => (string) $d['k'], 'id' => (string) $d['id']];
                    $bskyEmitted++;
                    $sinceBsky = 0;
                }
            }
            $added[] = ['k' => (string) $cand['k'], 'id' => (string) $cand['id']];
            if (count($added) >= $want) {
                break;
            }
        }
        // Leave excess deferred Bluesky for a later extend window (keeps mix
        // chronological with fedi still available in the retention window).
    }

    if ($added === []) {
        return null;
    }
    return array_merge($ranked, $added);
}

$blocks = [];
$mutes = [];
$userBlocks = [];
$mutedWordRows = [];
$deprioritizedRows = [];
if (in_array($view, ['blocks', 'remote_profile', 'dms', 'security', 'profile'], true)) {
    if ($view === 'blocks' || $view === 'remote_profile' || $view === 'dms' || $view === 'security') {
        $blocks = ap_block_list();
    }
    if ($view !== 'blocks') {
        $mutes = function_exists('ap_mutes_list') ? ap_mutes_list(admin_owner_user_id()) : [];
    }
}
if ($view === 'profile') {
    $mutes = function_exists('ap_mutes_list') ? ap_mutes_list(admin_owner_user_id()) : [];
    $userBlocks = function_exists('ap_user_blocks_list') ? ap_user_blocks_list(admin_owner_user_id()) : [];
    $mutedWordRows = function_exists('ap_muted_words_list') ? ap_muted_words_list(admin_owner_user_id()) : [];
    $deprioritizedRows = function_exists('ap_deprioritized_list')
        ? ap_deprioritized_list(admin_owner_user_id())
        : [];
    $followRequests = function_exists('ap_follow_requests_list')
        ? ap_follow_requests_list(rtrim((string) ($vaakUser['actor_id'] ?? $vaakActorId), '/'))
        : [];
}

// Federation feed — only when viewing Federated (was previously built on every click)
$feedTimeline = [];
$feedEvents = [];
if (!$wantNewerPoll && !$adminTlFromCache && ($view === 'feed' || ($isPartial && $view === 'feed'))) {
    $feedEventsRaw = [];
    try {
        // Deep window so infinite scroll stays Federated — not a short stack of own posts.
        // Prefer Creates with text/media; order by created_at (14d retention), not insert id.
        $ownActorLike = '%mkultra.monster/users/' . $vaakActorKey . '%';
        $stFeed = $db->prepare(
            "SELECT * FROM events
             WHERE type IN ('Create', 'Quote', 'QuotePost')
               AND action_taken IN ('log', 'local_observe')
               AND (
                 (summary IS NOT NULL AND summary != '')
                 OR (media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]')
                 OR (spoiler_text IS NOT NULL AND spoiler_text != '')
                 OR (sensitive IS NOT NULL AND sensitive != 0)
               )
               AND (actor_id IS NULL OR actor_id NOT LIKE ?)
             ORDER BY created_at DESC, id DESC LIMIT 100"
        );
        $stFeed->execute([$ownActorLike]);
        $feedEventsRaw = $stFeed->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[ap-admin] feed events: ' . $e->getMessage());
    }
    foreach ($feedEventsRaw as $e) {
        if (admin_timeline_row_hidden($e, admin_owner_user_id())) {
            continue;
        }
        if (function_exists('ap_row_matches_muted_words') && ap_row_matches_muted_words($e, 'event', [], admin_owner_user_id())) {
            continue;
        }
        $feedTimeline[] = [
            'kind' => 'event',
            'sort' => strtotime((string) ($e['created_at'] ?? '')) ?: (int) ($e['id'] ?? 0),
            'row' => $e,
        ];
        if (count($feedTimeline) >= 80) {
            break;
        }
    }
    // A few recent own posts only (≤24h) so you appear in the firehose without
    // drowning page 2+ in day-old outbox cards.
    $feedOwnCutoff = time() - 86400;
    $feedOwnAdded = 0;
    foreach ($outbox as $n) {
        if ($feedOwnAdded >= 3) {
            break;
        }
        $pub = strtotime((string) ($n['published'] ?? '')) ?: 0;
        if ($pub < $feedOwnCutoff) {
            continue;
        }
        $outItem = [
            'kind' => 'outbox',
            'sort' => $pub,
            'row' => $n,
        ];
        if (admin_timeline_item_muted_by_words($outItem)) {
            continue;
        }
        $feedTimeline[] = $outItem;
        $feedOwnAdded++;
    }
    // Own boosts (≤3 within 24h) — never run backfill on page load
    $feedBoostAdded = 0;
    foreach (ap_masto_reblog_rows(12, null, admin_owner_user_id()) as $rb) {
        if ($feedBoostAdded >= 3) {
            break;
        }
        $bSort = strtotime((string) ($rb['created_at'] ?? '')) ?: 0;
        if ($bSort < $feedOwnCutoff) {
            continue;
        }
        $boostItem = [
            'kind' => 'boost',
            'sort' => $bSort,
            'row' => $rb,
        ];
        if (admin_timeline_item_muted_by_words($boostItem)) {
            continue;
        }
        $feedTimeline[] = $boostItem;
        $feedBoostAdded++;
    }
    usort($feedTimeline, static fn($a, $b) => $b['sort'] <=> $a['sort']);
    // Dedupe: prefer outbox card when same note id appears as event
    $seenFeed = [];
    $feedDeduped = [];
    foreach ($feedTimeline as $item) {
        $kind = (string) ($item['kind'] ?? 'event');
        if ($kind === 'boost') {
            $key = 'boost:' . rtrim((string) ($item['row']['object_id'] ?? $item['row']['boost_status_id'] ?? ''), '/');
        } elseif ($kind === 'outbox') {
            $key = 'note:' . rtrim((string) ($item['row']['id'] ?? ''), '/');
        } else {
            $key = 'evt:' . rtrim((string) ($item['row']['object_id'] ?? ''), '/');
        }
        if ($key !== 'note:' && $key !== 'evt:' && $key !== 'boost:' && isset($seenFeed[$key])) {
            continue;
        }
        if ($key !== 'note:' && $key !== 'evt:' && $key !== 'boost:') {
            $seenFeed[$key] = true;
            // Also mark cross-kind
            if ($kind === 'outbox') {
                $seenFeed['evt:' . rtrim((string) ($item['row']['id'] ?? ''), '/')] = true;
            } elseif ($kind === 'event') {
                $oid = rtrim((string) ($item['row']['object_id'] ?? ''), '/');
                if (vaak_is_own_url($oid) && str_contains($oid, '/notes/')) {
                    $seenFeed['note:' . $oid] = true;
                }
            }
        }
        $feedDeduped[] = $item;
    }
    $feedTimeline = $feedDeduped;
    $feedEvents = array_map(static fn($i) => $i['row'], array_filter($feedTimeline, static fn($i) => $i['kind'] === 'event'));
    // Seed ranked cache for subsequent infinite-scroll pages
    if ($feedTimeline !== []) {
        $ck = $adminTlCacheKey !== '' ? $adminTlCacheKey : admin_tl_cache_key('feed', $following);
        admin_tl_cache_put($ck, admin_tl_rank_from_timeline($feedTimeline));
    }
}

// Home: follows + local instance posts + followed hashtags + our own posts (Mastodon-style)
$homeTimeline = [];
if (!$wantNewerPoll && !$adminTlFromCache && ($view === 'home' || ($isPartial && $view === 'home'))) {
    $homeSeenObject = [];
    $homeOwnerId = admin_owner_user_id();
    if ($followingIds) {
        // SQL filter by followed actors (avoid scanning 500 firehose rows in PHP)
        $homeActorIds = [];
        foreach ($following as $frow) {
            $fa = rtrim((string) ($frow['actor_id'] ?? ''), '/');
            if ($fa !== '') {
                $homeActorIds[$fa] = true;
                $homeActorIds[$fa . '/'] = true;
            }
        }
        $homeRaw = [];
        if ($homeActorIds) {
            $idList = array_keys($homeActorIds);
            // SQLite caps variables; chunk if needed
            $chunks = array_chunk($idList, 400);
            foreach ($chunks as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                try {
                    // Deep enough that infinite scroll can keep mixing fedi within
                    // the retention window before falling through to Bluesky-only.
                    $st = $db->prepare(
                        "SELECT * FROM events
                         WHERE type IN ('Create', 'Announce', 'Quote', 'QuotePost')
                           AND (action_taken = 'log' OR action_taken = 'local_observe')
                           AND actor_id IN ($ph)
                         ORDER BY created_at DESC, id DESC
                         LIMIT 300"
                    );
                    $st->execute($chunk);
                    foreach ($st->fetchAll() ?: [] as $erow) {
                        $homeRaw[] = $erow;
                    }
                } catch (Throwable $e) {
                    error_log('[ap-admin] home events: ' . $e->getMessage());
                }
            }
            usort($homeRaw, static function ($a, $b) {
                $cmp = strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
                if ($cmp !== 0) {
                    return $cmp;
                }
                return ((int) ($b['id'] ?? 0)) <=> ((int) ($a['id'] ?? 0));
            });
            $homeRaw = array_slice($homeRaw, 0, 300);
        }
        foreach ($homeRaw as $e) {
            $aid = (string) ($e['actor_id'] ?? '');
            if ($aid === '' || admin_timeline_row_hidden($e, $homeOwnerId)) {
                continue;
            }
            if (function_exists('ap_row_matches_muted_words') && ap_row_matches_muted_words($e, 'event', [], $homeOwnerId)) {
                continue;
            }
            if (empty($followingIds[$aid]) && empty($followingIds[rtrim($aid, '/')])) {
                continue;
            }
            $sum = trim(html_entity_decode((string) ($e['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $med = (string) ($e['media_urls'] ?? '');
            $etype = strtolower((string) ($e['type'] ?? ''));
            // Allow empty Announce stubs (boosts) — same as Ice Cubes home
            if ($sum === '' && ($med === '' || $med === '[]') && $etype !== 'announce') {
                continue;
            }
            // Skip mirrored copies of our own posts — outbox cards below are richer
            if (vaak_is_own_url($aid)) {
                continue;
            }
            $oid = rtrim((string) ($e['object_id'] ?? ''), '/');
            if ($oid !== '') {
                $homeSeenObject[$oid] = true;
            }
            $sortTs = strtotime((string) ($e['created_at'] ?? '')) ?: (int) ($e['id'] ?? 0);
            if (
                function_exists('ap_is_deprioritized_actor')
                && ap_is_deprioritized_actor($aid, $homeOwnerId)
            ) {
                $sortTs -= function_exists('ap_deprioritize_penalty_seconds')
                    ? ap_deprioritize_penalty_seconds()
                    : (6 * 3600);
            }
            $homeTimeline[] = [
                'kind' => 'event',
                'sort' => $sortTs,
                'row' => $e,
                'deprioritized' => function_exists('ap_is_deprioritized_actor')
                    && ap_is_deprioritized_actor($aid, $homeOwnerId),
            ];
        }
    }
    // Local instance Creates (other mkultra.monster accounts) so Home isn't empty with no follows.
    $localAdded = 0;
    try {
        $stLocal = $db->prepare(
            "SELECT * FROM events
             WHERE type IN ('Create', 'Quote', 'QuotePost')
               AND (action_taken = 'log' OR action_taken = 'local_observe')
               AND actor_id LIKE 'https://mkultra.monster/users/%'
             ORDER BY created_at DESC, id DESC
             LIMIT 80"
        );
        $stLocal->execute();
        foreach ($stLocal->fetchAll() ?: [] as $e) {
            if ($localAdded >= 40) {
                break;
            }
            $aid = rtrim((string) ($e['actor_id'] ?? ''), '/');
            if ($aid === '' || vaak_is_own_url($aid)) {
                continue;
            }
            if (admin_timeline_row_hidden($e, $homeOwnerId)) {
                continue;
            }
            if (function_exists('ap_row_matches_muted_words') && ap_row_matches_muted_words($e, 'event', [], $homeOwnerId)) {
                continue;
            }
            $sum = trim(html_entity_decode((string) ($e['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            $med = (string) ($e['media_urls'] ?? '');
            if ($sum === '' && ($med === '' || $med === '[]')) {
                continue;
            }
            $oid = rtrim((string) ($e['object_id'] ?? ''), '/');
            if ($oid !== '' && isset($homeSeenObject[$oid])) {
                continue;
            }
            if ($oid !== '') {
                $homeSeenObject[$oid] = true;
            }
            $sortTs = strtotime((string) ($e['created_at'] ?? '')) ?: (int) ($e['id'] ?? 0);
            if (
                function_exists('ap_is_deprioritized_actor')
                && ap_is_deprioritized_actor($aid, $homeOwnerId)
            ) {
                $sortTs -= function_exists('ap_deprioritize_penalty_seconds')
                    ? ap_deprioritize_penalty_seconds()
                    : (6 * 3600);
            }
            $homeTimeline[] = [
                'kind' => 'event',
                'sort' => $sortTs,
                'row' => $e,
                'deprioritized' => function_exists('ap_is_deprioritized_actor')
                    && ap_is_deprioritized_actor($aid, $homeOwnerId),
            ];
            $localAdded++;
        }
    } catch (Throwable $e) {
        error_log('[ap-admin] home local posts: ' . $e->getMessage());
    }
    // Public outbox notes from other local users (as synthetic events — not outbox cards).
    try {
        $stOb = $db->prepare(
            "SELECT * FROM outbox_notes
             WHERE id LIKE 'https://mkultra.monster/users/%'
               AND id NOT LIKE ?
               AND COALESCE(visibility, 'public') IN ('public', 'unlisted')
             ORDER BY published DESC LIMIT 40"
        );
        $stOb->execute(['https://mkultra.monster/users/' . $vaakActorKey . '/%']);
        foreach ($stOb->fetchAll() ?: [] as $n) {
            if ($localAdded >= 40) {
                break;
            }
            $nid = rtrim((string) ($n['id'] ?? ''), '/');
            if ($nid === '' || isset($homeSeenObject[$nid]) || vaak_is_own_url($nid)) {
                continue;
            }
            $nActor = '';
            if (preg_match('#^(https://mkultra\.monster/users/[A-Za-z0-9_]+)/#', $nid, $nm)) {
                $nActor = $nm[1];
            }
            if ($nActor === '' || vaak_is_own_url($nActor)) {
                continue;
            }
            if (function_exists('ap_row_is_hidden')
                ? ap_row_is_hidden($nActor, 'mkultra.monster', $homeOwnerId)
                : false) {
                continue;
            }
            $plain = trim(html_entity_decode(strip_tags((string) ($n['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($plain === '') {
                continue;
            }
            $homeSeenObject[$nid] = true;
            $synth = [
                'id' => 'outbox:' . $nid,
                'type' => 'Create',
                'actor_id' => $nActor,
                'object_id' => $nid,
                'created_at' => (string) ($n['published'] ?? ''),
                'summary' => (string) ($n['content'] ?? ''),
                'host' => 'mkultra.monster',
                'action_taken' => 'local_observe',
                'media_urls' => null,
                'in_reply_to' => $n['in_reply_to'] ?? null,
                'spoiler_text' => '',
                'sensitive' => 0,
                'visibility' => (string) ($n['visibility'] ?? 'public'),
            ];
            $homeTimeline[] = [
                'kind' => 'event',
                'sort' => strtotime((string) ($n['published'] ?? '')) ?: 0,
                'row' => $synth,
            ];
            $localAdded++;
        }
    } catch (Throwable $e) {
        error_log('[ap-admin] home local outbox: ' . $e->getMessage());
    }
    // Followed hashtags → Home (per-user; skip when this account follows none).
    // Busy tags used to dominate chronological merge; keep them visible, less loud.
    $tagPenaltySec = 4 * 3600; // treat tag-only posts as ~4h older for ranking
    $homeHasFollowedTags = function_exists('ap_masto_followed_tags')
        && ap_masto_followed_tags(0, admin_owner_user_id()) !== [];
    if ($homeHasFollowedTags && function_exists('ap_masto_followed_tag_event_rows')) {
        foreach (ap_masto_followed_tag_event_rows(48, admin_owner_user_id()) as $e) {
            $oid = rtrim((string) ($e['object_id'] ?? ''), '/');
            if ($oid !== '' && isset($homeSeenObject[$oid])) {
                continue;
            }
            if (function_exists('ap_row_matches_muted_words') && ap_row_matches_muted_words($e, 'event', [], $homeOwnerId)) {
                continue;
            }
            if ($oid !== '') {
                $homeSeenObject[$oid] = true;
            }
            $created = strtotime((string) ($e['created_at'] ?? '')) ?: (int) ($e['id'] ?? 0);
            $homeTimeline[] = [
                'kind' => 'event',
                'sort' => $created - $tagPenaltySec,
                'row' => $e,
                'from_tag' => true,
            ];
        }
    }
    // Cap own posts/boosts (≤5 within 24h) so Home stays follow-centric on deep scroll.
    $homeOwnCutoff = time() - 86400;
    $homeOwnAdded = 0;
    foreach ($outbox as $n) {
        if ($homeOwnAdded >= 5) {
            break;
        }
        $pub = strtotime((string) ($n['published'] ?? '')) ?: 0;
        if ($pub < $homeOwnCutoff) {
            continue;
        }
        $outItem = [
            'kind' => 'outbox',
            'sort' => $pub,
            'row' => $n,
        ];
        if (admin_timeline_item_muted_by_words($outItem)) {
            continue;
        }
        $homeTimeline[] = $outItem;
        $homeOwnAdded++;
    }
    $homeBoostAdded = 0;
    foreach (ap_masto_reblog_rows(16, null, $homeOwnerId) as $rb) {
        if ($homeBoostAdded >= 5) {
            break;
        }
        $bSort = strtotime((string) ($rb['created_at'] ?? '')) ?: 0;
        if ($bSort < $homeOwnCutoff) {
            continue;
        }
        $boostItem = [
            'kind' => 'boost',
            'sort' => $bSort,
            'row' => $rb,
        ];
        if (admin_timeline_item_muted_by_words($boostItem)) {
            continue;
        }
        $homeTimeline[] = $boostItem;
        $homeBoostAdded++;
    }
    // Bluesky following mix from durable cache (warm cron / tab browse).
    // Soft-cap ~30% so Home stays fedi-first; skip dual-published twins already shown.
    if (
        function_exists('ap_bsky_tab_enabled') && ap_bsky_tab_enabled()
        && function_exists('ap_bsky_session_row') && is_array(ap_bsky_session_row($homeOwnerId))
        && function_exists('ap_bsky_posts_for_home')
    ) {
        $ownBskyDid = '';
        $bskySessHome = ap_bsky_session_row($homeOwnerId);
        if (is_array($bskySessHome)) {
            $ownBskyDid = (string) ($bskySessHome['did'] ?? '');
        }
        $bskyHomeItems = ap_bsky_posts_for_home($homeOwnerId, 80, $ownBskyDid !== '' ? $ownBskyDid : null);
        $bskyCandidates = [];
        foreach ($bskyHomeItems as $bItem) {
            if (!is_array($bItem) || empty($bItem['post']) || !is_array($bItem['post'])) {
                continue;
            }
            $bUri = (string) ($bItem['bsky_uri'] ?? ($bItem['post']['uri'] ?? ''));
            if ($bUri === '') {
                continue;
            }
            $fediTwin = isset($bItem['fediverse_id']) ? rtrim((string) $bItem['fediverse_id'], '/') : '';
            if ($fediTwin === '' && function_exists('ap_bsky_post_link_by_uri')) {
                $link = ap_bsky_post_link_by_uri($bUri);
                if (is_array($link) && !empty($link['fediverse_id'])) {
                    $fediTwin = rtrim((string) $link['fediverse_id'], '/');
                }
            }
            if ($fediTwin !== '' && isset($homeSeenObject[$fediTwin])) {
                continue; // already showing the AP twin
            }
            // Hide-set / muted DIDs
            $authorDid = (string) ($bItem['post']['author']['did'] ?? '');
            if ($authorDid !== '' && function_exists('ap_bsky_filter_hidden_authors')) {
                $filtered = ap_bsky_filter_hidden_authors($homeOwnerId, [['post' => $bItem['post']]]);
                if ($filtered === []) {
                    continue;
                }
            }
            $indexed = (string) ($bItem['indexed_at'] ?? ($bItem['post']['indexedAt'] ?? ''));
            $sortTs = strtotime($indexed) ?: 0;
            if ($sortTs <= 0) {
                continue;
            }
            if ($fediTwin !== '') {
                $homeSeenObject[$fediTwin] = true;
            }
            $homeSeenObject['bsky:' . $bUri] = true;
            $bskyCandidates[] = [
                'kind' => 'bsky',
                'sort' => $sortTs,
                'row' => $bItem,
            ];
        }
        foreach ($bskyCandidates as $bc) {
            $homeTimeline[] = $bc;
        }
    }
    usort($homeTimeline, static fn($a, $b) => $b['sort'] <=> $a['sort']);
    // Soft cap Bluesky share (~30%) so Home stays fedi-first when AT cache is busy.
    // Defer surplus Bluesky (don't drop) so later pages / deeper scroll still get them.
    if ($homeTimeline) {
        $cappedBsky = [];
        $deferredBsky = [];
        $bskyEmitted = 0;
        $sinceBsky = 0;
        $flushDeferredBsky = static function () use (&$cappedBsky, &$deferredBsky, &$bskyEmitted, &$sinceBsky): void {
            while ($deferredBsky !== []) {
                if ($sinceBsky < 2 && $cappedBsky !== []) {
                    break;
                }
                if (($bskyEmitted + 1) / max(1, count($cappedBsky) + 1) > 0.30) {
                    break;
                }
                $cappedBsky[] = array_shift($deferredBsky);
                $bskyEmitted++;
                $sinceBsky = 0;
            }
        };
        foreach ($homeTimeline as $item) {
            $isBsky = ((string) ($item['kind'] ?? '')) === 'bsky';
            if ($isBsky) {
                if (($sinceBsky < 2 && $cappedBsky !== [])
                    || (($bskyEmitted + 1) / max(1, count($cappedBsky) + 1) > 0.30)
                ) {
                    $deferredBsky[] = $item;
                    continue;
                }
                $bskyEmitted++;
                $sinceBsky = 0;
                $cappedBsky[] = $item;
            } else {
                $sinceBsky++;
                $cappedBsky[] = $item;
                $flushDeferredBsky();
            }
        }
        // Do NOT dump leftover Bluesky onto the tail — that created a
        // Bluesky-only cliff after old local posts. Surplus stays out of this
        // ranked head; deep scroll re-pulls older bsky_posts via extend, mixed
        // chronologically with older following events still in the retention window.
        $homeTimeline = $cappedBsky;
    }
    // Hard cap: tag-only posts ≤ ~25% of the ranked list (still keep some spice).
    $maxTagShare = 0.25;
    $primary = [];
    $tagsOnly = [];
    foreach ($homeTimeline as $item) {
        if (!empty($item['from_tag'])) {
            $tagsOnly[] = $item;
        } else {
            $primary[] = $item;
        }
    }
    if ($tagsOnly) {
        $merged = [];
        $ti = 0;
        $tagEmitted = 0;
        $primarySinceTag = 0;
        foreach ($primary as $p) {
            $merged[] = $p;
            $primarySinceTag++;
            // After 3 primary posts, allow one tag if under quota and still newer-ish
            while (
                $ti < count($tagsOnly)
                && $primarySinceTag >= 3
                && ($tagEmitted + 1) / max(1, count($merged) + 1) <= $maxTagShare
            ) {
                $merged[] = $tagsOnly[$ti];
                $ti++;
                $tagEmitted++;
                $primarySinceTag = 0;
            }
        }
        // If primary feed is thin, still append a few tags (capped)
        while ($ti < count($tagsOnly) && $tagEmitted < max(3, (int) ceil(count($primary) * $maxTagShare))) {
            $merged[] = $tagsOnly[$ti];
            $ti++;
            $tagEmitted++;
        }
        $homeTimeline = $merged;
    }
    // Cap deprioritized authors to ~1 in 5 Home slots (still visible, less loud).
    if ($homeTimeline) {
        $capped = [];
        $deprioEmitted = 0;
        $sinceDeprio = 0;
        foreach ($homeTimeline as $item) {
            $isDep = !empty($item['deprioritized']);
            if ($isDep) {
                if ($sinceDeprio < 4 && count($capped) > 0) {
                    continue;
                }
                if (($deprioEmitted + 1) / max(1, count($capped) + 1) > 0.2) {
                    continue;
                }
                $deprioEmitted++;
                $sinceDeprio = 0;
            } else {
                $sinceDeprio++;
            }
            $capped[] = $item;
        }
        $homeTimeline = $capped;
    }
    // Seed ranked cache for subsequent infinite-scroll pages
    if ($homeTimeline !== []) {
        $ck = $adminTlCacheKey !== '' ? $adminTlCacheKey : admin_tl_cache_key('home', $following);
        admin_tl_cache_put($ck, admin_tl_rank_from_timeline($homeTimeline));
    }
}
$homeEvents = array_map(static fn($i) => $i['row'], array_filter($homeTimeline, static fn($i) => $i['kind'] === 'event'));

// Local: posts from this instance only (all local actors) — no remote follows.
$localTimeline = [];
if (!$wantNewerPoll && !$adminTlFromCache && ($view === 'local' || ($isPartial && $view === 'local'))) {
    $localOwnerId = admin_owner_user_id();
    try {
        $stLocalTl = $db->prepare(
            "SELECT * FROM outbox_notes
             WHERE id LIKE 'https://mkultra.monster/users/%/notes/%'
             ORDER BY published DESC
             LIMIT 100"
        );
        $stLocalTl->execute();
        $localNotes = $stLocalTl->fetchAll() ?: [];
    } catch (Throwable $e) {
        error_log('[ap-admin] local timeline: ' . $e->getMessage());
        $localNotes = [];
    }
    // Prefetch masto_statuses for CW / visibility / edit
    $localNoteIds = [];
    foreach ($localNotes as $n) {
        $nid = (string) ($n['id'] ?? '');
        if ($nid !== '' && str_starts_with($nid, 'https://')) {
            $localNoteIds[$nid] = true;
            $localNoteIds[rtrim($nid, '/') . '/'] = true;
        }
    }
    if ($localNoteIds !== []) {
        try {
            foreach (array_chunk(array_keys($localNoteIds), 400) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = $db->prepare(
                    "SELECT note_id, local_id, content_text, spoiler_text, sensitive, visibility
                     FROM masto_statuses WHERE note_id IN ($ph)"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll() ?: [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $key = rtrim((string) ($row['note_id'] ?? ''), '/');
                    if ($key !== '') {
                        $GLOBALS['admin_masto_by_note'][$key] = $row;
                        $GLOBALS['admin_masto_by_note'][$key . '/'] = $row;
                    }
                }
            }
        } catch (Throwable $e) {
            // per-card fallback
        }
    }
    foreach ($localNotes as $n) {
        if (!is_array($n)) {
            continue;
        }
        $outItem = [
            'kind' => 'outbox',
            'sort' => strtotime((string) ($n['published'] ?? '')) ?: 0,
            'row' => $n,
        ];
        if (admin_timeline_item_muted_by_words($outItem)) {
            continue;
        }
        $localTimeline[] = $outItem;
    }
    // Local users' boosts (including boosts of remote posts). The booster is
    // local; the target can be anywhere, while the Local view remains
    // instance-scoped by who performed the action.
    try {
        $stRb = $db->prepare(
            "SELECT * FROM masto_reblogs
             WHERE owner_actor_id LIKE 'https://mkultra.monster/users/%'
             ORDER BY created_at DESC LIMIT 120"
        );
        $stRb->execute();
        foreach ($stRb->fetchAll() ?: [] as $rb) {
            if (!is_array($rb)) {
                continue;
            }
            $boostItem = [
                'kind' => 'boost',
                'sort' => strtotime((string) ($rb['created_at'] ?? '')) ?: 0,
                'row' => $rb,
            ];
            if (admin_timeline_item_muted_by_words($boostItem)) {
                continue;
            }
            $localTimeline[] = $boostItem;
        }
    } catch (Throwable $e) {
        // optional
    }
    usort($localTimeline, static fn($a, $b) => $b['sort'] <=> $a['sort']);
    if ($localTimeline !== []) {
        $ck = $adminTlCacheKey !== '' ? $adminTlCacheKey : admin_tl_cache_key('local', $following);
        admin_tl_cache_put($ck, admin_tl_rank_from_timeline($localTimeline));
    }
}

// Gallery: media-only posts (federated Creates with attachments + local outbox with media).
$galleryTimeline = [];
$GLOBALS['admin_vakktok_mode'] = ($view === 'vakktok');
if (!$wantNewerPoll && !$adminTlFromCache && in_array($view, ['gallery', 'vakktok'], true)) {
    $galOwnerId = admin_owner_user_id();
    $galSeen = [];
    try {
        $stGal = $db->prepare(
            "SELECT * FROM events
             WHERE type IN ('Create', 'Quote', 'QuotePost')
               AND action_taken IN ('log', 'local_observe')
               AND media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]'
             ORDER BY created_at DESC, id DESC
             LIMIT 400"
        );
        $stGal->execute();
        foreach ($stGal->fetchAll() ?: [] as $e) {
            if (!is_array($e) || count($galleryTimeline) >= 250) {
                break;
            }
            if (function_exists('ap_row_is_hidden')
                ? ap_row_is_hidden($e['actor_id'] ?? null, $e['host'] ?? null, $galOwnerId)
                : (function_exists('ap_row_is_blocked') && ap_row_is_blocked($e['actor_id'] ?? null, $e['host'] ?? null))) {
                continue;
            }
            if (function_exists('ap_row_matches_muted_words')
                && ap_row_matches_muted_words($e, 'event', [], $galOwnerId)) {
                continue;
            }
            if (!admin_gallery_event_has_media($e)) {
                continue;
            }
            if ($view === 'vakktok' && (!admin_vakktok_item_has_video(['row' => $e]) || admin_vakktok_item_is_sensitive(['row' => $e]))) {
                continue;
            }
            $oid = rtrim((string) ($e['object_id'] ?? ''), '/');
            if ($oid !== '' && isset($galSeen[$oid])) {
                continue;
            }
            if ($oid !== '') {
                $galSeen[$oid] = true;
            }
            $item = [
                'kind' => 'event',
                'sort' => strtotime((string) ($e['created_at'] ?? '')) ?: (int) ($e['id'] ?? 0),
                'row' => $e,
            ];
            if (admin_timeline_item_muted_by_words($item)) {
                continue;
            }
            $galleryTimeline[] = $item;
        }
    } catch (Throwable $e) {
        error_log('[ap-admin] gallery events: ' . $e->getMessage());
    }
    // Local outbox notes that carry image/video attachments
    try {
        $stGalOut = $db->prepare(
            "SELECT * FROM outbox_notes
             WHERE id LIKE 'https://mkultra.monster/users/%/notes/%'
               AND COALESCE(visibility, 'public') IN ('public', 'unlisted')
             ORDER BY published DESC
             LIMIT 200"
        );
        $stGalOut->execute();
        foreach ($stGalOut->fetchAll() ?: [] as $n) {
            if (!is_array($n) || count($galleryTimeline) >= 280) {
                break;
            }
            $nid = rtrim((string) ($n['id'] ?? ''), '/');
            if ($nid === '' || isset($galSeen[$nid])) {
                continue;
            }
            if (!admin_gallery_outbox_has_media($n)) {
                continue;
            }
            if ($view === 'vakktok' && admin_vakktok_item_is_sensitive(['kind' => 'outbox', 'row' => $n])) {
                continue;
            }
            $galSeen[$nid] = true;
            $item = [
                'kind' => 'outbox',
                'sort' => strtotime((string) ($n['published'] ?? '')) ?: 0,
                'row' => $n,
            ];
            if (admin_timeline_item_muted_by_words($item)) {
                continue;
            }
            $galleryTimeline[] = $item;
        }
    } catch (Throwable $e) {
        error_log('[ap-admin] gallery outbox: ' . $e->getMessage());
    }
    usort($galleryTimeline, static fn($a, $b) => $b['sort'] <=> $a['sort']);
    if ($galleryTimeline !== []) {
        $ck = $adminTlCacheKey !== '' ? $adminTlCacheKey : admin_tl_cache_key($view, $following);
        admin_tl_cache_put($ck, admin_tl_rank_from_timeline($galleryTimeline));
    }
}

// Full-page first paint: when ranked cache hits, hydrate only the visible window
// (builders above were skipped via $adminTlFromCache). Partials hydrate later.
if (
    !$isPartial
    && $adminTlFromCache
    && is_array($adminTlRankedCached)
    && in_array($view, ['home', 'feed', 'local', 'gallery', 'vakktok'], true)
) {
    $adminTlCachedTotal = count($adminTlRankedCached);
    $sliceKeys = array_slice($adminTlRankedCached, 0, $tlLimit);
    $hydrated = admin_tl_hydrate($sliceKeys);
    $hydrateNotes = [];
    foreach ($hydrated as $it) {
        if (($it['kind'] ?? '') === 'outbox') {
            $nid = rtrim((string) ($it['row']['id'] ?? ''), '/');
            if ($nid !== '') {
                $hydrateNotes[$nid] = true;
                $hydrateNotes[$nid . '/'] = true;
            }
        }
    }
    if ($hydrateNotes !== []) {
        try {
            foreach (array_chunk(array_keys($hydrateNotes), 400) as $chunk) {
                $ph = implode(',', array_fill(0, count($chunk), '?'));
                $st = ap_db()->prepare(
                    "SELECT note_id, local_id, content_text, spoiler_text, sensitive, visibility
                     FROM masto_statuses WHERE note_id IN ($ph)"
                );
                $st->execute($chunk);
                foreach ($st->fetchAll() ?: [] as $row) {
                    if (!is_array($row)) {
                        continue;
                    }
                    $key = rtrim((string) ($row['note_id'] ?? ''), '/');
                    if ($key !== '') {
                        $GLOBALS['admin_masto_by_note'][$key] = $row;
                        $GLOBALS['admin_masto_by_note'][$key . '/'] = $row;
                    }
                }
            }
        } catch (Throwable $e) {
            // per-card fallback
        }
    }
    $adminTlCachedHasMore = $adminTlCachedTotal > $tlLimit;
    if ($view === 'home') {
        $homeTimeline = $hydrated;
    } elseif ($view === 'feed') {
        $feedTimeline = $hydrated;
        $feedEvents = array_map(
            static fn($i) => $i['row'],
            array_filter($feedTimeline, static fn($i) => ($i['kind'] ?? '') === 'event')
        );
    } elseif ($view === 'local') {
        $localTimeline = $hydrated;
    } elseif (in_array($view, ['gallery', 'vakktok'], true)) {
        $galleryTimeline = $hydrated;
    }
}

$prefillReplyTo = trim((string) ($_GET['reply_to'] ?? ''));
$prefillActor = trim((string) ($_GET['to'] ?? ''));
$prefillQuoteObject = trim((string) ($_GET['quote_object'] ?? ''));
$prefillQuoteStatusId = trim((string) ($_GET['quote_status_id'] ?? ''));
$prefillEditNote = trim((string) ($_GET['edit_note'] ?? ''));
$prefillEditContent = '';
$prefillEditSpoiler = '';
$prefillEditSensitive = false;
$prefillVisibility = 'public';
$prefillDraftId = (int) ($_GET['draft_id'] ?? 0);
$prefillDraftMediaIds = [];
$prefillDraft = null;
if (
    $prefillEditNote !== ''
    && vaak_is_own_url($prefillEditNote)
    && str_contains($prefillEditNote, '/notes/')
) {
    try {
        $est = ap_db()->prepare(
            'SELECT content_text, spoiler_text, sensitive FROM masto_statuses
             WHERE note_id = ? OR note_id = ? LIMIT 1'
        );
        $est->execute([$prefillEditNote, rtrim($prefillEditNote, '/') . '/']);
        $erow = $est->fetch();
        if (is_array($erow)) {
            $prefillEditContent = (string) ($erow['content_text'] ?? '');
            if (in_array($prefillEditContent, ['(quote)', '(media)', '(poll)'], true)) {
                $prefillEditContent = '';
            }
            // Normalize CRLF from some clients so the textarea shows clean blank lines
            $prefillEditContent = str_replace(["\r\n", "\r"], "\n", $prefillEditContent);
            $prefillEditSpoiler = trim((string) ($erow['spoiler_text'] ?? ''));
            $prefillEditSensitive = !empty($erow['sensitive']) || $prefillEditSpoiler !== '';
        } else {
            $prefillEditNote = '';
        }
    } catch (Throwable $e) {
        $prefillEditNote = '';
    }
}
// Resume a saved draft into the composer (takes priority over empty compose)
if ($prefillDraftId > 0 && $prefillEditNote === '') {
    $prefillDraft = ap_draft_get($prefillDraftId);
    if (is_array($prefillDraft)) {
        $prefillEditContent = str_replace(["\r\n", "\r"], "\n", (string) ($prefillDraft['content'] ?? ''));
        $prefillEditSpoiler = trim((string) ($prefillDraft['spoiler_text'] ?? ''));
        $prefillEditSensitive = !empty($prefillDraft['sensitive']) || $prefillEditSpoiler !== '';
        $prefillVisibility = function_exists('ap_normalize_visibility')
            ? ap_normalize_visibility($prefillDraft['visibility'] ?? 'public')
            : 'public';
        $dr = trim((string) ($prefillDraft['in_reply_to'] ?? ''));
        if ($dr !== '' && $prefillReplyTo === '') {
            $prefillReplyTo = $dr;
        }
        $da = trim((string) ($prefillDraft['to_actor'] ?? ''));
        if ($da !== '' && $prefillActor === '') {
            $prefillActor = $da;
        }
        $dq = trim((string) ($prefillDraft['quote_object'] ?? ''));
        if ($dq !== '' && $prefillQuoteObject === '') {
            $prefillQuoteObject = $dq;
        }
        $prefillDraftMediaIds = ap_draft_media_ids($prefillDraft);
        $composerForceOpen = true;
    } else {
        $prefillDraftId = 0;
    }
}
// Replies inherit the parent's content-warning state. This lookup is local
// and keeps the composer fields correct before the reply is submitted.
if ($prefillReplyTo !== '' && $prefillEditNote === '' && $prefillDraftId <= 0) {
    try {
        $parentUri = rtrim($prefillReplyTo, '/');
        $parentRow = null;
        $pst = ap_db()->prepare(
            'SELECT spoiler_text, sensitive FROM masto_statuses
             WHERE note_id = ? OR note_id = ? LIMIT 1'
        );
        $pst->execute([$parentUri, $parentUri . '/']);
        $parentRow = $pst->fetch();
        if (!is_array($parentRow)) {
            $pst = ap_db()->prepare(
                'SELECT spoiler_text, sensitive FROM events
                 WHERE object_id = ? OR object_id = ?
                 ORDER BY id DESC LIMIT 1'
            );
            $pst->execute([$parentUri, $parentUri . '/']);
            $parentRow = $pst->fetch();
        }
        if (is_array($parentRow)) {
            $parentSpoiler = trim((string) ($parentRow['spoiler_text'] ?? ''));
            $parentSensitive = !empty($parentRow['sensitive']) || $parentSpoiler !== '';
            if ($parentSpoiler !== '') {
                $prefillEditSpoiler = $parentSpoiler;
            }
            if ($parentSensitive) {
                $prefillEditSensitive = true;
            }
        }
    } catch (Throwable $e) {
        // A missing cache row should not prevent opening the composer.
    }
}
$autoOpenComposer = $composerForceOpen
    || isset($_GET['compose'])
    || $prefillReplyTo !== ''
    || $prefillQuoteObject !== ''
    || $prefillEditNote !== ''
    || $prefillDraftId > 0;
$composerReturnView = $view;
$draftsCountNav = function_exists('ap_drafts_count') ? ap_drafts_count() : 0;
$profile = ap_profile_get($vaakActorKey);

/** HTML-escape anything displayable (never pass bool/null through to htmlspecialchars). */
function h(mixed $s): string
{
    if ($s === null || $s === false) {
        return '';
    }
    if (is_bool($s)) {
        return $s ? '1' : '';
    }
    if (!is_string($s) && !is_numeric($s)) {
        $s = '';
    }
    $str = function_exists('ap_fix_utf8') ? ap_fix_utf8((string) $s) : (string) $s;
    return htmlspecialchars($str, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function vaak_actor_id(): string
{
    $id = rtrim((string) ($GLOBALS['vaak_actor_id'] ?? ''), '/');
    if ($id !== '' && str_starts_with($id, 'https://')) {
        return $id;
    }
    if (!empty($GLOBALS['vaak_user']['actor_id'])) {
        $id = rtrim((string) $GLOBALS['vaak_user']['actor_id'], '/');
        if ($id !== '' && str_starts_with($id, 'https://')) {
            return $id;
        }
    }
    // Admin is always session-gated — never invent cmdr_nova for a missing bind.
    return '';
}

function vaak_actor_key(): string
{
    $key = strtolower(trim((string) ($GLOBALS['vaak_actor_key'] ?? '')));
    $key = preg_replace('/[^a-z0-9_]/', '', $key) ?? '';
    if ($key !== '') {
        return $key;
    }
    if (!empty($GLOBALS['vaak_user']['actor_key'])) {
        $key = strtolower(trim((string) $GLOBALS['vaak_user']['actor_key']));
        $key = preg_replace('/[^a-z0-9_]/', '', $key) ?? '';
        if ($key !== '') {
            return $key;
        }
    }
    return '';
}

function vaak_is_own_url(?string $url): bool
{
    $url = rtrim((string) $url, '/');
    $me = vaak_actor_id();
    return $url !== '' && ($url === $me || str_starts_with($url, $me . '/'));
}

/** True for any mkultra.monster local actor/note URL (not just the logged-in user). */
function vaak_is_local_url(?string $url): bool
{
    $url = rtrim((string) $url, '/');
    return $url !== '' && (bool) preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+(?:/|$)#', $url);
}

/** @return list<string> */
function mention_media_urls(?string $json): array
{
    if ($json === null || $json === '') {
        return [];
    }
    $decoded = json_decode($json, true);
    if (!is_array($decoded)) {
        return [];
    }
    $out = [];
    foreach ($decoded as $u) {
        if (is_string($u) && str_starts_with($u, 'https://')) {
            $out[] = $u;
        }
        if (count($out) >= 4) {
            break;
        }
    }
    return $out;
}

/** Hide placeholder summaries emitted by remote servers when a post is media-only. */
function admin_media_placeholder_summary(string $summary, array $mediaUrls): string
{
    if ($mediaUrls !== [] && preg_match('/^\((?:attachment|media)\)$/i', trim(strip_tags($summary)))) {
        return '';
    }
    return $summary;
}

function view_title(string $view): string
{
    return match ($view) {
        'notices' => 'Notices',
        'discuss' => 'Discuss',
        'home' => 'Home',
        'local' => 'Local',
        'feed' => 'Federation feed',
        'gallery' => 'Gallery',
        'mentions' => 'Notifications',
        'dms' => 'Direct messages',
        'favourites' => 'Favourites',
        'bookmarks' => 'Bookmarks',
        'followers' => 'Followers',
        'following' => 'Following',
        'search' => 'Search',
        'foryou' => 'For You',
        'bluesky' => 'Bluesky',
        'tags' => 'Hashtags',
        'collections' => 'Collections',
        'lists' => 'Lists',
        'import_export' => 'Import / Export',
        'report' => 'Report',
        'moderation' => 'Moderation',
        'blocks' => 'Server blocks',
        'muted_words' => 'Profile',
        'invites' => 'Invites',
        'policies' => 'Policies',
        'relays' => 'Relays',
        'outbox' => 'Your posts',
        'queue' => 'Queue',
        'drafts' => 'Drafts',
        'compose' => 'Compose',
        'profile' => 'Profile',
        'remote_profile' => 'Remote profile',
        'status' => 'Post',
        'security' => 'Security',
        'stats' => 'AP stats',
        'guestbook' => 'Guestbook',
        'support' => 'Support',
        'analytics' => 'Analytics',
        'users' => 'Users',
        default => ucfirst($view),
    };
}

/** In-webview status detail link (Home / Federated / Notifications). */
function admin_status_href(string $objectUrl, string $from = 'home'): string
{
    $objectUrl = trim($objectUrl);
    if ($objectUrl === '' || !str_starts_with($objectUrl, 'https://')) {
        return '#';
    }
    $from = preg_replace('/[^a-z_]/', '', $from) ?: 'home';
    return '?view=status&object=' . rawurlencode($objectUrl) . '&from=' . rawurlencode($from);
}

/**
 * Open Moderation report composer with account (+ optional post) prefilled.
 * Timeline ⚑ uses this instead of instantly POSTing an empty Flag.
 */
function admin_report_href(string $targetActor, string $objectId = '', string $from = ''): string
{
    $targetActor = trim($targetActor);
    $objectId = trim($objectId);
    $from = preg_replace('/[^a-z_]/', '', $from) ?: '';
    $q = ['view' => 'report'];
    if ($targetActor !== '') {
        $q['report_target'] = $targetActor;
    }
    if ($objectId !== '' && str_starts_with($objectId, 'https://')) {
        $q['report_object'] = $objectId;
    }
    if ($from !== '') {
        $q['from'] = $from;
    }
    return '?' . http_build_query($q);
}

/** True if URL / mediaType looks like a playable video. */
function admin_media_is_video(string $url, ?string $mediaType = null): bool
{
    $mt = strtolower(trim((string) $mediaType));
    if (str_starts_with($mt, 'video/')) {
        return true;
    }
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    return (bool) preg_match('/\.(mp4|webm|mov|m4v)(\?|$)/i', $path);
}

function admin_media_is_audio(string $url, ?string $mediaType = null): bool
{
    $mt = strtolower(trim((string) $mediaType));
    if (str_starts_with($mt, 'audio/')) {
        return true;
    }
    $path = (string) (parse_url($url, PHP_URL_PATH) ?? '');
    return (bool) preg_match('/\.(mp3|m4a|aac|ogg|oga|wav|flac|webm)(\?|$)/i', $path);
}

/**
 * Timeline / detail media: images open lightbox; videos play inline with controls.
 *
 * @param list<string|array{url?:string,mediaType?:string,preview_url?:string}> $items
 */
/**
 * Edit button for local posts. Content is base64'd so multiline text survives HTML attributes.
 */
/**
 * HTML → plain for webview timelines. Preserves blank lines between <p> blocks
 * (raw strip_tags fuses paragraphs — fine for remotes, broken for our own posts).
 */
function admin_html_to_plain(string $htmlOrPlain): string
{
    if (function_exists('ap_html_to_plain_text')) {
        return ap_html_to_plain_text($htmlOrPlain);
    }
    return trim(html_entity_decode(strip_tags($htmlOrPlain), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
}

/**
 * Edit control — navigates to compose with ?edit_note= (PHP prefills textarea).
 * Webview-safe: no reliance on data-* / JS decode for the post body.
 */
function admin_edit_post_button(
    string $noteId,
    string $plainText = '',
    string $spoiler = '',
    bool $sensitive = false,
    string $returnView = 'home'
): string {
    if ($noteId === '' || !vaak_is_own_url($noteId) || !str_contains($noteId, '/notes/')) {
        return '';
    }
    $returnView = preg_replace('/[^a-z_]/', '', $returnView) ?: 'home';
    // Stay on status focus when editing from Open
    $href = '?view=' . rawurlencode($returnView)
        . '&compose=1&edit_note=' . rawurlencode($noteId);
    if ($returnView === 'status') {
        $href = '?view=status&object=' . rawurlencode($noteId)
            . '&from=home&compose=1&edit_note=' . rawurlencode($noteId);
    }
    return '<a class="btn btn-ghost" href="' . h($href) . '" style="padding:.25rem .7rem;font-size:.8rem">Edit</a>';
}

/** Pin / unpin local public posts (Ice Cubes + HTML profile). Max 5. */
function admin_pin_post_button(string $noteId, string $returnView = 'outbox', string $from = ''): string
{
    if ($noteId === '' || !vaak_is_own_url($noteId) || !str_contains($noteId, '/notes/')) {
        return '';
    }
    $returnView = preg_replace('/[^a-z_]/', '', $returnView) ?: 'outbox';
    $from = preg_replace('/[^a-z_]/', '', $from) ?: '';
    $localId = 0;
    $vis = 'public';
    try {
        $st = ap_db()->prepare(
            'SELECT local_id, visibility FROM masto_statuses WHERE note_id = ? OR note_id = ? LIMIT 1'
        );
        $st->execute([$noteId, rtrim($noteId, '/') . '/']);
        $row = $st->fetch();
        if (is_array($row)) {
            $localId = (int) ($row['local_id'] ?? 0);
            $vis = (string) ($row['visibility'] ?? 'public');
        }
    } catch (Throwable $e) {
        return '';
    }
    if ($localId <= 0 || $vis === 'direct') {
        return '';
    }
    $pinned = function_exists('ap_masto_status_is_pinned') && ap_masto_status_is_pinned($localId);
    $action = $pinned ? 'unpin_status' : 'pin_status';
    $label = $pinned ? 'Unpin' : 'Pin';
    $title = $pinned ? 'Unpin from profile' : 'Pin on profile (HTML + Ice Cubes, max 5)';
    $actionUrl = '?view=' . rawurlencode($returnView);
    if ($returnView === 'status') {
        $actionUrl .= '&object=' . rawurlencode($noteId);
        if ($from !== '') {
            $actionUrl .= '&from=' . rawurlencode($from);
        }
    }
    $html = '<form method="post" action="' . h($actionUrl) . '" style="display:inline">'
        . '<input type="hidden" name="action" value="' . h($action) . '">'
        . '<input type="hidden" name="return_view" value="' . h($returnView) . '">'
        . '<input type="hidden" name="note_id" value="' . h($noteId) . '">';
    if ($from !== '') {
        $html .= '<input type="hidden" name="from" value="' . h($from) . '">';
    }
    $html .= '<button class="btn btn-ghost' . ($pinned ? ' on' : '') . '" type="submit" '
        . 'style="padding:.25rem .7rem;font-size:.8rem" title="' . h($title) . '">'
        . h($label) . '</button></form>';
    return $html;
}

/**
 * True when an events row has usable image/video URLs in media_urls.
 *
 * @param array<string,mixed> $e
 */
function admin_gallery_event_has_media(array $e): bool
{
    return admin_gallery_media_urls_from_json($e['media_urls'] ?? null) !== [];
}

/**
 * @param mixed $json
 * @return list<array{url:string,mediaType:?string,is_video:bool}>
 */
function admin_gallery_media_urls_from_json($json): array
{
    $raw = [];
    if (is_string($json) && $json !== '' && $json !== '[]') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            $raw = $decoded;
        }
    } elseif (is_array($json)) {
        $raw = $json;
    }
    return admin_gallery_normalize_media_list($raw);
}

/**
 * @param array<string,mixed> $n outbox_notes row
 */
function admin_gallery_outbox_has_media(array $n): bool
{
    return admin_gallery_media_from_outbox($n) !== [];
}

/**
 * @param array<string,mixed> $n
 * @return list<array{url:string,mediaType:?string,is_video:bool}>
 */
function admin_gallery_media_from_outbox(array $n): array
{
    $rawJson = (string) ($n['raw_create_json'] ?? '');
    if ($rawJson === '') {
        return [];
    }
    $decoded = json_decode($rawJson, true);
    if (!is_array($decoded)) {
        return [];
    }
    $obj = is_array($decoded['object'] ?? null) ? $decoded['object'] : $decoded;
    if (!is_array($obj)) {
        return [];
    }
    if (function_exists('ap_extract_media_urls')) {
        $urls = ap_extract_media_urls($obj);
        if (is_array($urls) && $urls !== []) {
            return admin_gallery_normalize_media_list($urls);
        }
    }
    $atts = $obj['attachment'] ?? null;
    if (!is_array($atts)) {
        return [];
    }
    if (isset($atts['type'])) {
        $atts = [$atts];
    }
    return admin_gallery_normalize_media_list($atts);
}

/**
 * @param list<mixed> $items
 * @return list<array{url:string,mediaType:?string,is_video:bool}>
 */
function admin_gallery_normalize_media_list(array $items): array
{
    $out = [];
    foreach ($items as $item) {
        $url = '';
        $mt = null;
        if (is_string($item)) {
            $url = $item;
        } elseif (is_array($item)) {
            $url = (string) ($item['url'] ?? '');
            if ($url === '' && isset($item['url']['href']) && is_string($item['url']['href'])) {
                $url = $item['url']['href'];
            }
            if ($url === '') {
                $url = (string) ($item['preview_url'] ?? '');
            }
            $mt = isset($item['mediaType']) ? (string) $item['mediaType'] : null;
            if ($mt === null && isset($item['type']) && is_string($item['type'])) {
                $t = (string) $item['type'];
                if ($t === 'Image') {
                    $mt = 'image/*';
                } elseif ($t === 'Video') {
                    $mt = 'video/*';
                }
            }
        }
        if ($url === '' || !str_starts_with($url, 'https://')) {
            continue;
        }
        $isVideo = admin_media_is_video($url, $mt);
        // Gallery stays image-focused; VakkTok opts into the same source rows
        // but keeps only video cells at render time.
        if ($isVideo && empty($GLOBALS['admin_vakktok_mode'])) {
            continue;
        }
        // Gallery prefers visual media; skip obvious non-image docs
        if (!$isVideo && is_string($mt) && $mt !== '' && !str_starts_with(strtolower($mt), 'image/') && $mt !== 'image/*') {
            // allow unknown mediaType on https URLs that look like images
            if (!preg_match('/\.(jpe?g|png|gif|webp|avif)(\?|$)/i', (string) (parse_url($url, PHP_URL_PATH) ?? ''))) {
                continue;
            }
        }
        $out[] = ['url' => $url, 'mediaType' => $mt, 'is_video' => $isVideo];
        if (count($out) >= 4) {
            break;
        }
    }
    return $out;
}

/**
 * @param array{kind?:string,row?:array<string,mixed>} $item
 * @return list<array{url:string,mediaType:?string,is_video:bool}>
 */
function admin_gallery_item_media(array $item): array
{
    $kind = (string) ($item['kind'] ?? '');
    $row = is_array($item['row'] ?? null) ? $item['row'] : [];
    if ($kind === 'outbox') {
        return admin_gallery_media_from_outbox($row);
    }
    return admin_gallery_media_urls_from_json($row['media_urls'] ?? null);
}

/**
 * Render one Gallery grid cell (thumbnail → status Open).
 *
 * @param array{kind?:string,row?:array<string,mixed>} $item
 * @param array<string,bool> $followingIds
 */
function admin_render_gallery_cell(array $item, array $followingIds, string $returnView = 'gallery'): void
{
    $media = admin_gallery_item_media($item);
    if ($media === []) {
        return;
    }
    $kind = (string) ($item['kind'] ?? '');
    $row = is_array($item['row'] ?? null) ? $item['row'] : [];
    $objectId = '';
    $actorId = '';
    $sensitive = false;
    $spoiler = '';
    if ($kind === 'outbox') {
        $objectId = rtrim((string) ($row['id'] ?? ''), '/');
        if ($objectId !== '' && preg_match('#^(https://mkultra\.monster/users/[A-Za-z0-9_]+)/#', $objectId, $m)) {
            $actorId = $m[1];
        }
        $ms = $GLOBALS['admin_masto_by_note'][$objectId] ?? $GLOBALS['admin_masto_by_note'][$objectId . '/'] ?? null;
        if (is_array($ms)) {
            $sensitive = !empty($ms['sensitive']);
            $spoiler = trim((string) ($ms['spoiler_text'] ?? ''));
        }
    } else {
        $objectId = rtrim((string) ($row['object_id'] ?? ''), '/');
        $actorId = rtrim((string) ($row['actor_id'] ?? ''), '/');
        $sensitive = !empty($row['sensitive']);
        $spoiler = trim((string) ($row['spoiler_text'] ?? ''));
    }
    if ($objectId === '' || !str_starts_with($objectId, 'https://')) {
        return;
    }
    $thumb = $media[0];
    $href = admin_status_href($objectId, $returnView);
    $handle = $actorId !== '' ? actor_handle($actorId) : '';
    $count = count($media);
    $isVideo = !empty($thumb['is_video']);
    $autoUnblur = $sensitive && $spoiler === '' && function_exists('ap_profile_get')
        && !empty(ap_profile_get((string) ($GLOBALS['vaak_actor_key'] ?? 'cmdr_nova'))['auto_unblur_sensitive']);
    $cw = ($sensitive && !$autoUnblur) || $spoiler !== '';
    ?>
    <a class="gallery-cell<?= $cw ? ' gallery-cell-cw' : '' ?>" href="<?= h($href) ?>" title="<?= h($handle !== '' ? $handle : 'Open') ?>">
      <?php if ($isVideo): ?>
        <video class="gallery-thumb" src="<?= h($thumb['url']) ?>" muted playsinline preload="metadata" referrerpolicy="no-referrer"></video>
        <span class="gallery-badge" aria-hidden="true">▶</span>
      <?php else: ?>
        <img class="gallery-thumb" src="<?= h($thumb['url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" decoding="async">
      <?php endif; ?>
      <?php if ($count > 1): ?>
        <span class="gallery-badge gallery-badge-count" aria-hidden="true"><?= (int) $count ?></span>
      <?php endif; ?>
      <?php if ($cw): ?>
        <span class="gallery-cw"><?= $spoiler !== '' ? h($spoiler) : 'CW' ?></span>
      <?php endif; ?>
      <?php if ($handle !== ''): ?>
        <span class="gallery-who"><?= h($handle) ?></span>
      <?php endif; ?>
    </a>
    <?php
}

/** Render one full-screen, video-only VakkTok item. */
function admin_vakktok_item_has_video(array $item): bool
{
    foreach (admin_gallery_item_media($item) as $media) {
        if (!empty($media['is_video'])) return true;
    }
    return false;
}

/** VakkTok intentionally omits sensitive/CW media from its autoplay reel. */
function admin_vakktok_item_is_sensitive(array $item): bool
{
    $row = is_array($item['row'] ?? null) ? $item['row'] : [];
    if (!empty($row['sensitive']) || trim((string) ($row['spoiler_text'] ?? '')) !== '') {
        return true;
    }
    if (($item['kind'] ?? '') === 'outbox') {
        $raw = json_decode((string) ($row['raw_create_json'] ?? ''), true);
        $obj = is_array($raw['object'] ?? null) ? $raw['object'] : $raw;
        if (is_array($obj) && (!empty($obj['sensitive']) || trim((string) ($obj['summary'] ?? $obj['contentWarning'] ?? '')) !== '')) {
            return true;
        }
    }
    return false;
}

function admin_render_vakktok_cell(array $item): void
{
    $media = admin_gallery_item_media($item);
    $video = null;
    foreach ($media as $candidate) {
        if (!empty($candidate['is_video'])) {
            $video = $candidate;
            break;
        }
    }
    if (!is_array($video) || empty($video['url'])) {
        return;
    }
    $url = (string) $video['url'];
    $poster = '';
    try {
        $st = ap_db()->prepare('SELECT preview_url FROM masto_media WHERE public_url = ? AND preview_url IS NOT NULL LIMIT 1');
        $st->execute([$url]);
        $poster = (string) ($st->fetchColumn() ?: '');
    } catch (Throwable $e) {
        // Remote videos may not have a local poster.
    }
    $row = is_array($item['row'] ?? null) ? $item['row'] : [];
    echo '<article class="vakktok-item" data-vakktok-video="1">';
    echo '<video class="vakktok-video" controls loop playsinline preload="metadata" src="' . h($url) . '"';
    if (str_starts_with($poster, 'https://')) {
        echo ' poster="' . h($poster) . '"';
    }
    echo '></video>';
    echo '</article>';
}

function admin_media_row_html(array $items, string $hint = ''): string
{
    if (!$items) {
        return '';
    }
    $cells = [];
    $hasImage = false;
    $hasVideo = false;
    foreach ($items as $item) {
        $url = '';
        $mt = null;
        $preview = '';
        if (is_string($item)) {
            $url = $item;
        } elseif (is_array($item)) {
            $url = (string) ($item['url'] ?? $item['preview_url'] ?? '');
            $mt = isset($item['mediaType']) ? (string) $item['mediaType'] : null;
            if ($mt === null && strtolower((string) ($item['type'] ?? '')) === 'audio') {
                $mt = 'audio/*';
            }
            $preview = (string) ($item['preview_url'] ?? $item['poster'] ?? '');
        }
        if ($url === '' || !str_starts_with($url, 'https://')) {
            continue;
        }
        // Older cached event/attachment payloads only kept the media URL.
        // Recover locally generated video posters from the authoritative media row.
        if ($preview === '') {
            try {
                $pst = ap_db()->prepare('SELECT preview_url FROM masto_media WHERE public_url = ? AND preview_url IS NOT NULL LIMIT 1');
                $pst->execute([$url]);
                $dbPreview = $pst->fetchColumn();
                if (is_string($dbPreview) && str_starts_with($dbPreview, 'https://')) {
                    $preview = $dbPreview;
                }
            } catch (Throwable $e) {
                // Remote media and older schemas simply have no local poster row.
            }
        }
        if (admin_media_is_video($url, $mt)) {
            $hasVideo = true;
            $cells[] = '<video class="media-video" src="' . h($url) . '" controls loop playsinline preload="none"'
                . (str_starts_with($preview, 'https://') ? ' poster="' . h($preview) . '"' : '')
                . ' referrerpolicy="no-referrer"></video>';
        } elseif (admin_media_is_audio($url, $mt)) {
            $cells[] = '<div class="media-audio-card" role="group" aria-label="Audio post">'
                . '<img class="media-audio-art" src="/api/assets/audio-post-default.jpg" alt="" loading="lazy" decoding="async">'
                . '<audio class="media-audio" src="' . h($url) . '" controls preload="auto"></audio>'
                . '</div>';
        } else {
            $hasImage = true;
            $cells[] = '<button type="button" class="media-lightbox-trigger" data-full="' . h($url) . '" title="View image">'
                . '<img src="' . h($url) . '" alt="" loading="lazy" referrerpolicy="no-referrer" decoding="async">'
                . '</button>';
        }
        if (count($cells) >= 4) {
            break;
        }
    }
    if ($cells === []) {
        return '';
    }
    $n = count($cells);
    $html = '<div class="media-row media-count-' . $n . '">';
    $html .= implode('', $cells);
    $html .= '</div>';
    if ($hint !== '') {
        if ($hasVideo && !$hasImage) {
            $hint = 'Video · use controls to play';
        } elseif ($hasVideo && $hasImage) {
            $hint = 'Tap images to enlarge · videos play inline';
        }
        $html .= '<div class="media-hint">' . h($hint) . '</div>';
    }
    return $html;
}

/** True if any of the given actor/web URLs is in the following map. */
function admin_is_following(array $followingIds, string ...$refs): bool
{
    foreach ($refs as $ref) {
        $ref = trim($ref);
        if ($ref === '') {
            continue;
        }
        $root = rtrim($ref, '/');
        if (!empty($followingIds[$ref]) || !empty($followingIds[$root]) || !empty($followingIds[$root . '/'])) {
            return true;
        }
        // /@user ↔ /users/user
        if (preg_match('#^(https://[^/]+)/@([^/]+)/?$#', $root, $m)) {
            $alt = $m[1] . '/users/' . rawurlencode(rawurldecode($m[2]));
            if (!empty($followingIds[$alt]) || !empty($followingIds[$alt . '/'])) {
                return true;
            }
        }
        if (preg_match('#^(https://[^/]+)/users/([^/]+)/?$#', $root, $m)
            && !ctype_digit(rawurldecode($m[2]))) {
            $alt = $m[1] . '/@' . rawurldecode($m[2]);
            if (!empty($followingIds[$alt]) || !empty($followingIds[$alt . '/'])) {
                return true;
            }
        }
        // Mastodon dual IRI: /users/name ↔ /ap/users/{snowflake} via remote_actors
        if (str_starts_with($root, 'https://') && function_exists('ap_masto_remote_account_acct_key')) {
            $key = ap_masto_remote_account_acct_key($root);
            if ($key !== null && str_contains($key, '@')) {
                [$u, $h] = explode('@', $key, 2);
                if ($u !== '' && $h !== '') {
                    foreach ([
                        'https://' . $h . '/@' . $u,
                        'https://' . $h . '/users/' . $u,
                        'https://' . $h . '/users/' . rawurlencode($u),
                    ] as $alt) {
                        if (!empty($followingIds[$alt]) || !empty($followingIds[$alt . '/'])) {
                            return true;
                        }
                    }
                }
            }
        }
    }
    return false;
}

/**
 * Relationship between us and a remote actor.
 * @return 'mutual'|'following'|'follows_you'|'none'
 */
function admin_rel_state(array $followingIds, array $followerIds, string ...$refs): string
{
    $out = admin_is_following($followingIds, ...$refs);
    $in = admin_is_following($followerIds, ...$refs);
    if ($out && $in) {
        return 'mutual';
    }
    if ($out) {
        return 'following';
    }
    if ($in) {
        return 'follows_you';
    }
    return 'none';
}

function admin_rel_badge(string $state): string
{
    return match ($state) {
        'mutual' => '<span class="tag" title="You follow each other">mutual</span>',
        'following' => '<span class="tag" title="You follow them">following</span>',
        'follows_you' => '<span class="tag" title="They follow you">follows you</span>',
        default => '',
    };
}

/**
 * Human "Remote" / "Open on remote" href for a status object.
 * Bridgy convert/ap/at://… URLs are ActivityPub JSON — map those to bsky.app.
 */
function admin_remote_object_href(string $objectOrUri, mixed $urlHint = null): string
{
    $objectOrUri = trim($objectOrUri);
    if (function_exists('ap_remote_object_web_url')) {
        $mapped = ap_remote_object_web_url($objectOrUri, $urlHint);
        if ($mapped !== '') {
            return $mapped;
        }
    }
    if (is_string($urlHint) && str_starts_with($urlHint, 'https://')
        && !(function_exists('ap_is_bridgy_url') && ap_is_bridgy_url($urlHint)
            && !str_starts_with($urlHint, 'https://bsky.app/'))) {
        return $urlHint;
    }
    return $objectOrUri;
}

/**
 * Human profile URL for "Open remote" / "Open web profile" on an actor.
 * Bridgy → bsky.app; Threads → threads.com/@user (not /ap/users/ JSON).
 */
function admin_remote_actor_href(string $actorId, ?string $username = null): string
{
    $actorId = rtrim(trim($actorId), '/');
    if ($actorId === '') {
        return '';
    }
    if (function_exists('ap_bridgy_bsky_profile_web_url')) {
        $bsky = ap_bridgy_bsky_profile_web_url($actorId, $username);
        if (is_string($bsky) && $bsky !== '') {
            return $bsky;
        }
    }
    if (function_exists('ap_threads_profile_web_url')) {
        $threads = ap_threads_profile_web_url($actorId, $username);
        if (is_string($threads) && $threads !== '') {
            return $threads;
        }
    }
    return $actorId;
}

/** Label for the external profile link: local instance → web profile, else remote. */
function admin_open_profile_label(?string $actorIdOrUrl): string
{
    $u = rtrim(trim((string) $actorIdOrUrl), '/');
    if ($u !== '' && (
        vaak_is_local_url($u)
        || (bool) preg_match('#^https://mkultra\.monster/@[A-Za-z0-9_]+$#', $u)
        || (bool) preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+$#', $u)
    )) {
        return 'Open web profile';
    }
    return 'Open remote';
}

/**
 * Prefer ActivityPub actor id (uri) over web profile url for follows / remote_profile.
 *
 * @param array<string,mixed> $account Mastodon-shaped account
 */
function admin_account_actor_ref(array $account): string
{
    $uri = rtrim((string) ($account['uri'] ?? ''), '/');
    $url = rtrim((string) ($account['url'] ?? ''), '/');
    if ($uri !== '' && str_starts_with($uri, 'https://') && !preg_match('#/@[^/]+$#', $uri)) {
        return $uri;
    }
    if ($url !== '' && str_starts_with($url, 'https://')) {
        // Convert /@user web URL to /users/user when possible
        if (preg_match('#^(https://[^/]+)/@([^/]+)$#', $url, $m)) {
            return $m[1] . '/users/' . rawurlencode(rawurldecode($m[2]));
        }
        return $url;
    }
    return $uri !== '' ? $uri : $url;
}

/**
 * Linkify @mentions, #hashtags, and bare https?:// URLs in plain status text for admin UI.
 * Mentions → remote_profile; hashtags → Search; URLs → open in new tab.
 *
 * @param list<array{url?:string,acct?:string,username?:string,uri?:string}> $mentions
 */
function admin_linkify_body_html(string $plain, string $returnView = 'home', array $mentions = []): string
{
    $plain = html_entity_decode(trim($plain), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    if ($plain === '') {
        return '';
    }
    $returnView = preg_replace('/[^a-z_]/', '', $returnView) ?: 'home';

    if (function_exists('ap_normalize_bridgy_plain_mentions')) {
        $plain = ap_normalize_bridgy_plain_mentions($plain);
    }
    if (function_exists('ap_masto_repair_at_hash_tags')) {
        $plain = ap_masto_repair_at_hash_tags($plain);
    }
    if (function_exists('ap_plain_unglue_mentions')) {
        $plain = ap_plain_unglue_mentions($plain);
    }
    // Expand bare @welshpixie → @welshpixie@mastodon.art using URLs in the same post
    if (function_exists('ap_plain_expand_bare_mentions_from_urls')) {
        $plain = ap_plain_expand_bare_mentions_from_urls($plain);
    }
    if (function_exists('ap_plain_unglue_hashtags')) {
        $plain = ap_plain_unglue_hashtags($plain);
    }
    if (function_exists('ap_masto_unglue_host_mentions')) {
        $plain = ap_masto_unglue_host_mentions($plain);
    }
    if (function_exists('ap_masto_unglue_bare_handles')) {
        $plain = ap_masto_unglue_bare_handles($plain);
    }

    /** @var array<string,array{acct:string,username:string,url:string}> $byAcct */
    $byAcct = [];
    foreach ($mentions as $m) {
        if (!is_array($m)) {
            continue;
        }
        $acct = trim((string) ($m['acct'] ?? ''));
        $url = trim((string) ($m['url'] ?? $m['uri'] ?? ''));
        $user = trim((string) ($m['username'] ?? ''));
        if ($acct === '' || $url === '' || !str_starts_with($url, 'https://')) {
            continue;
        }
        if ($user === '') {
            $user = str_contains($acct, '@') ? explode('@', $acct, 2)[0] : $acct;
        }
        $byAcct[strtolower($acct)] = ['acct' => $acct, 'username' => $user, 'url' => $url];
    }

    // Discover @user@host in the body
    if (preg_match_all('/(^|[^A-Za-z0-9_])@([A-Za-z0-9_]+)@([a-z0-9.-]+\.[a-z]{2,})/u', $plain, $mm, PREG_SET_ORDER)) {
        foreach ($mm as $hit) {
            $user = $hit[2];
            $host = strtolower($hit[3]);
            $acctFull = $user . '@' . $host;
            $acctKey = strtolower($acctFull);
            if (isset($byAcct[$acctKey])) {
                continue;
            }
            // Bridgy leftovers: @alice.bsky.social@bsky.brid.gy (after normalize should be rare)
            if (($host === 'bsky.brid.gy' || str_ends_with($host, '.brid.gy')) && str_contains($user, '.')) {
                continue; // handled as Bluesky-style handle below
            }
            $actor = 'https://' . $host . '/users/' . rawurlencode($user);
            if (function_exists('ap_masto_mention_from_actor')) {
                $resolved = ap_masto_mention_from_actor($actor);
                $byAcct[$acctKey] = [
                    // Prefer the written @user@host so the whole handle is linked
                    // (local actors otherwise collapse to bare username).
                    'acct' => $acctFull,
                    'username' => (string) ($resolved['username'] ?? $user),
                    'url' => (string) ($resolved['url'] ?? $actor),
                ];
            } else {
                $byAcct[$acctKey] = ['acct' => $acctFull, 'username' => $user, 'url' => $actor];
            }
        }
    }

    // Bluesky / Bridgy handles with dots: @alice.bsky.social or @custom.domain
    // (must not invent https://custom.domain/users/... — that's not ActivityPub)
    if (preg_match_all('/(^|[^A-Za-z0-9_@])@([A-Za-z0-9][A-Za-z0-9.-]*\.[A-Za-z]{2,})(?![A-Za-z0-9.@])/u', $plain, $bmBsky, PREG_SET_ORDER)) {
        foreach ($bmBsky as $hit) {
            $handle = strtolower($hit[2]);
            if (isset($byAcct[$handle])) {
                continue;
            }
            $covered = false;
            foreach ($byAcct as $m) {
                if (strtolower((string) $m['username']) === $handle || strtolower((string) $m['acct']) === $handle) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            $actor = function_exists('ap_masto_resolve_bsky_handle_actor')
                ? ap_masto_resolve_bsky_handle_actor($handle)
                : null;
            if (is_string($actor) && str_starts_with($actor, 'https://')) {
                if (function_exists('ap_masto_mention_from_actor')) {
                    $resolved = ap_masto_mention_from_actor($actor);
                    $byAcct[$handle] = [
                        'acct' => (string) ($resolved['acct'] ?? $handle),
                        'username' => (string) ($resolved['username'] ?? $handle),
                        'url' => (string) ($resolved['url'] ?? $actor),
                    ];
                } else {
                    $byAcct[$handle] = ['acct' => $handle, 'username' => $handle, 'url' => $actor];
                }
            } else {
                // Unknown Bluesky handle → open on bsky.app (don't invent a fake AP /users/ URL)
                $byAcct[$handle] = [
                    'acct' => $handle,
                    'username' => $handle,
                    'url' => 'https://bsky.app/profile/' . rawurlencode($handle),
                ];
            }
        }
    }

    // Bare @user — resolve via known mentions or local actor cache
    if (preg_match_all('/(^|[^A-Za-z0-9_@])@([A-Za-z0-9_]{2,32})(?![A-Za-z0-9_@])/u', $plain, $bm, PREG_SET_ORDER)) {
        foreach ($bm as $hit) {
            $user = $hit[2];
            $userKey = strtolower($user);
            $covered = false;
            foreach ($byAcct as $m) {
                if (strtolower($m['username']) === $userKey) {
                    $covered = true;
                    break;
                }
            }
            if ($covered) {
                continue;
            }
            $selfKey = strtolower(vaak_actor_key());
            if ($userKey === $selfKey || ($selfKey === 'cmdr_nova' && $userKey === 'cmdr-nova')) {
                $byAcct[$selfKey] = [
                    'acct' => vaak_actor_key(),
                    'username' => vaak_actor_key(),
                    'url' => vaak_actor_id(),
                ];
                continue;
            }
            if (function_exists('ap_masto_resolve_bare_username')) {
                $resolved = ap_masto_resolve_bare_username($user);
                if (is_array($resolved) && !empty($resolved['url'])) {
                    $byAcct[strtolower((string) $resolved['acct'])] = [
                        'acct' => (string) $resolved['acct'],
                        'username' => (string) ($resolved['username'] ?? $user),
                        'url' => (string) $resolved['url'],
                    ];
                    continue;
                }
            }
            // Unknown bare @user (not in cache / not federated yet) → Search with resolve
            $byAcct['__bare:' . $userKey] = [
                'acct' => $user,
                'username' => $user,
                'url' => 'search://' . $user,
            ];
        }
    }

    // Protect URLs so #fragments / @ in paths aren't treated as tags/mentions;
    // later restored as clickable external links.
    $placeholders = [];
    $protect = static function (string $text) use (&$placeholders): string {
        return preg_replace_callback(
            '#https?://[^\s<]+#iu',
            static function (array $m) use (&$placeholders): string {
                $raw = $m[0];
                // Keep trailing sentence punctuation outside the link
                $trail = '';
                if (preg_match('/^(.*?)([.,;:!?)\]]+)$/u', $raw, $tm)) {
                    // Don't strip if it looks like part of the URL path/query (rare trailing .)
                    $core = $tm[1];
                    $maybeTrail = $tm[2];
                    if ($core !== '' && !str_ends_with($core, '/')) {
                        $raw = $core;
                        $trail = $maybeTrail;
                    }
                }
                $key = "\x01U" . count($placeholders) . "\x01";
                $placeholders[$key] = $raw;
                return $key . $trail;
            },
            $text
        ) ?? $text;
    };
    $plainProtected = $protect($plain);
    $escaped = htmlspecialchars($plainProtected, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

    $profileHref = static function (string $actorUrl) use ($returnView): array {
        // [href, external?]
        if (str_starts_with($actorUrl, 'search://')) {
            $q = substr($actorUrl, strlen('search://'));
            return ['?view=search&q=' . rawurlencode('@' . $q) . '&type=accounts&resolve=1', false];
        }
        if (str_starts_with($actorUrl, 'https://bsky.app/')) {
            // Keep Bluesky profiles inside VAAK so Follow uses AT Protocol, not AP.
            return ['?view=remote_profile&actor=' . rawurlencode($actorUrl) . '&from=' . rawurlencode($returnView), false];
        }
        if (vaak_is_own_url($actorUrl)) {
            return ['?view=outbox', false];
        }
        // Always use AP actor IRI for remote_profile (not bsky.app web URL)
        return ['?view=remote_profile&actor=' . rawurlencode($actorUrl) . '&from=' . rawurlencode($returnView), false];
    };

    if ($byAcct) {
        $list = array_values($byAcct);
        usort($list, static fn($a, $b) => strlen($b['acct']) <=> strlen($a['acct']));
        // Pass 1: full @user@host only. Never str_replace bare @user here —
        // that nested <@cmdr_nova> inside <@cmdr_nova@mkultra.monster> and made
        // Wafrn mentions look like only the local part was linked.
        foreach ($list as $m) {
            $acct = (string) $m['acct'];
            if (!str_contains($acct, '@')) {
                continue;
            }
            [$hrefRaw, $ext] = $profileHref((string) $m['url']);
            $href = htmlspecialchars($hrefRaw, ENT_QUOTES, 'UTF-8');
            $needleFull = '@' . htmlspecialchars($acct, ENT_QUOTES, 'UTF-8');
            $labelFull = '@' . htmlspecialchars($acct, ENT_QUOTES, 'UTF-8');
            $extra = $ext ? ' target="_blank" rel="noopener noreferrer"' : '';
            $linkFull = '<a class="mention" href="' . $href . '"' . $extra . '>' . $labelFull . '</a>';
            $escaped = str_replace($needleFull, $linkFull, $escaped);
        }

        // Protect mention anchors before bare @user replace
        $escaped = preg_replace_callback(
            '#<a class="mention"[^>]*>.*?</a>#su',
            static function (array $m) use (&$placeholders): string {
                $key = "\x01M" . count($placeholders) . "\x01";
                $placeholders[$key] = $m[0];
                return $key;
            },
            $escaped
        ) ?? $escaped;

        $byUser = [];
        foreach ($list as $m) {
            $u = strtolower((string) $m['username']);
            // Prefer the entry whose acct is bare (or any unique username).
            if (!isset($byUser[$u])) {
                $byUser[$u] = $m;
            } elseif (is_array($byUser[$u]) && str_contains((string) $byUser[$u]['acct'], '@') && !str_contains((string) $m['acct'], '@')) {
                $byUser[$u] = $m;
            } elseif (is_array($byUser[$u]) && (string) $byUser[$u]['url'] !== (string) $m['url']) {
                $byUser[$u] = false; // ambiguous username across hosts
            }
        }
        foreach ($byUser as $u => $m) {
            if (!is_array($m)) {
                continue;
            }
            [$hrefRaw, $ext] = $profileHref((string) $m['url']);
            $href = htmlspecialchars($hrefRaw, ENT_QUOTES, 'UTF-8');
            $uname = htmlspecialchars((string) $m['username'], ENT_QUOTES, 'UTF-8');
            $extra = $ext ? ' target="_blank" rel="noopener noreferrer"' : '';
            $link = '<a class="mention" href="' . $href . '"' . $extra . '>@' . $uname . '</a>';
            // Allow dots in Bluesky handles; don't match mid-handle or @user@host
            $escaped = preg_replace(
                '/(^|[^A-Za-z0-9_\/])@' . preg_quote($uname, '/') . '(?![A-Za-z0-9_.@])/u',
                '$1' . $link,
                $escaped
            ) ?? $escaped;
        }
    }

    // Hashtags → admin Search (statuses for that tag)
    $escaped = preg_replace_callback(
        '/(^|[^A-Za-z0-9_&\/%])#([\p{L}\p{N}_]{1,100})/u',
        static function (array $m) use (&$placeholders): string {
            $tag = $m[2];
            $href = '?view=search&q=' . rawurlencode('#' . $tag) . '&type=statuses';
            $link = '<a class="hashtag" href="' . htmlspecialchars($href, ENT_QUOTES, 'UTF-8') . '">#'
                . htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') . '</a>';
            $key = "\x01H" . count($placeholders) . "\x01";
            $placeholders[$key] = $link;
            return $m[1] . $key;
        },
        $escaped
    ) ?? $escaped;

    if ($placeholders) {
        // Restore longest keys first so nested markers don't collide oddly
        $keys = array_keys($placeholders);
        usort($keys, static fn($a, $b) => strlen($b) <=> strlen($a));
        foreach ($keys as $key) {
            $val = $placeholders[$key];
            if (str_starts_with($key, "\x01U")) {
                // Bare URL → clickable external link (opens source like the video preview)
                $href = htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $label = htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
                $val = '<a class="ext-link" href="' . $href . '" target="_blank" rel="noopener noreferrer">'
                    . $label . '</a>';
            }
            $escaped = str_replace($key, $val, $escaped);
        }
    }

    return $escaped;
}

/** Whether a feed event is something we can reply to (has a remote object URL). */
function feed_event_can_reply(array $e): bool
{
    $type = (string) ($e['type'] ?? '');
    if (!in_array($type, ['Create', 'Announce', 'Update', 'QuotePost', 'Quote'], true)) {
        return false;
    }
    $objectId = (string) ($e['object_id'] ?? '');
    $actorId = (string) ($e['actor_id'] ?? '');
    // QuotePost URLs → reply to parent status when possible
    if (preg_match('#^(https://[^\s]+/statuses/[^/]+)/QuotePost/?$#i', $objectId, $m)) {
        $objectId = $m[1];
    }
    if (!str_starts_with($objectId, 'https://') || !str_starts_with($actorId, 'https://')) {
        return false;
    }
    // Skip our own outbound Creates / profile Updates
    if (vaak_is_own_url($objectId) || vaak_is_own_url($actorId)) {
        return false;
    }
    return true;
}

/** Object URL to use for Reply (parent status for …/QuotePost). */
function feed_event_reply_object_id(array $e): string
{
    $objectId = (string) ($e['object_id'] ?? '');
    if (preg_match('#^(https://[^\s]+/statuses/[^/]+)/QuotePost/?$#i', $objectId, $m)) {
        return $m[1];
    }
    return $objectId;
}

/**
 * Render a DM body for admin: keep safe HTML links, otherwise linkify plain text.
 *
 * @param array<string,mixed>|null $dmRow optional row (attachments_json for Bridgy actions)
 */
function admin_dm_html(?string $raw, ?array $dmRow = null): string
{
    $raw = (string) ($raw ?? '');
    $extra = '';
    if (is_array($dmRow) && !empty($dmRow['attachments_json']) && function_exists('ap_dm_action_links_html')) {
        $atts = json_decode((string) $dmRow['attachments_json'], true);
        if (is_array($atts) && !(is_string($raw) && str_contains($raw, 'class="dm-actions"'))) {
            $extra = ap_dm_action_links_html($atts);
        }
    }

    // Stored HTML (new Bridgy digests / sanitized Mastodon HTML)
    if ($raw !== '' && (str_contains($raw, '<a ') || str_contains($raw, '<p>') || str_contains($raw, '<ul'))) {
        $html = function_exists('ap_dm_sanitize_html') ? ap_dm_sanitize_html($raw) : strip_tags($raw, '<p><br><a><ul><li><em><strong>');
        if (function_exists('ap_dm_linkify_html')) {
            $html = ap_dm_linkify_html($html);
            $html = function_exists('ap_dm_sanitize_html') ? ap_dm_sanitize_html($html) : $html;
        }
        // Strip indentation-only whitespace (spaces/tabs after newlines), but keep
        // single spaces around inline tags — otherwise "</a> on the…" becomes
        // "</a>on the…" and "blocking <a" becomes "blocking<a".
        $html = preg_replace('/\n[\t ]+/u', "\n", $html) ?? $html;
        $html = preg_replace('/[\t ]+\n/u', "\n", $html) ?? $html;
        if ($extra !== '' && !str_contains($html, 'class="dm-actions"')) {
            $html .= "\n" . $extra;
        }
        return $html;
    }

    $t = html_entity_decode($raw, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $t = trim(strip_tags($t));
    $t = preg_replace('/^\h+/mu', '', $t) ?? $t;
    if ($t === '' && $extra === '') {
        return '';
    }
    $escaped = htmlspecialchars($t, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $makeLink = static function (string $href, string $label): string {
        $href = rtrim($href, '.,;:!?)]}\'"');
        $label = rtrim($label, '.,;:!?)]}\'"');
        if ($href === '' || !preg_match('#^https?://#i', $href)) {
            return htmlspecialchars($label, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        }
        // Prefer full URL as visible text when the plain label looks truncated
        $labSrc = $label;
        if ((str_ends_with($label, '...') || str_ends_with($label, '…'))
            && preg_match('#^(https?://|[a-z0-9.-]+\.[a-z]{2,}/)#i', $label)) {
            $labSrc = preg_replace('#^https://#i', '', $href) ?? $href;
        } elseif ($label === '' || $label === $href) {
            $labSrc = preg_replace('#^https://#i', '', $href) ?? $href;
        }
        $safe = htmlspecialchars($href, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        $lab = htmlspecialchars($labSrc, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<a href="' . $safe . '" target="_blank" rel="noopener noreferrer nofollow">' . $lab . '</a>';
    };
    $escaped = preg_replace_callback(
        '#https?://[^\s<]+#iu',
        static function (array $m) use ($makeLink): string {
            $raw = $m[0];
            // Bridgy/legacy strip_tags left truncated URL text (… / ...) — do not invent a link
            if (str_ends_with($raw, '...') || str_ends_with($raw, '…')
                || str_ends_with(rtrim($raw, '.,;:!?)]}\'"'), '...')) {
                return $raw;
            }
            return $makeLink($raw, $raw);
        },
        $escaped
    ) ?? $escaped;
    $placeholders = [];
    $escaped = preg_replace_callback(
        '#<a\b[^>]*>.*?</a>#su',
        static function (array $m) use (&$placeholders): string {
            $key = "\x01L" . count($placeholders) . "\x01";
            $placeholders[$key] = $m[0];
            return $key;
        },
        $escaped
    ) ?? $escaped;
    $escaped = preg_replace_callback(
        '#\b((?:bsky\.app|bluesky\.social|[a-z0-9-]+(?:\.[a-z0-9-]+)+)/[^\s<]+)#iu',
        static function (array $m) use ($makeLink): string {
            $raw = $m[1];
            // Truncated leftovers like bsky.app/profile/did:pl... must not become https://…/did:pl
            if (str_ends_with($raw, '...') || str_ends_with($raw, '…')
                || str_contains($raw, '...') || str_contains($raw, '…')) {
                return $m[0];
            }
            $path = rtrim($raw, '.,;:!?)]}\'"');
            if ($path === '' || str_contains($path, '..') || str_contains($path, "\x01")) {
                return $m[0];
            }
            return $makeLink('https://' . $path, $path);
        },
        $escaped
    ) ?? $escaped;
    if ($placeholders) {
        $escaped = str_replace(array_keys($placeholders), array_values($placeholders), $escaped);
    }
    $out = $escaped !== '' ? nl2br($escaped, false) : '';
    if ($extra !== '') {
        $out .= ($out !== '' ? '<br>' : '') . $extra;
    }
    return $out;
}

/** Viewer preference: highlight anti-AI posters (request-memoized). */
function admin_viewer_anti_ai_enabled(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }
    $key = function_exists('vaak_actor_key') ? (string) vaak_actor_key() : 'cmdr_nova';
    $p = function_exists('ap_profile_get') ? ap_profile_get($key) : [];
    $cached = !empty($p['anti_ai_marker']);
    return $cached;
}

/** Cheap heuristic: current post text contains slop/clanker (etc.). */
function admin_text_looks_anti_ai(string $text): bool
{
    return function_exists('ap_text_looks_anti_ai')
        ? ap_text_looks_anti_ai($text)
        : ($text !== '' && (bool) preg_match('/\b(?:slop|clankers?)\b/iu', $text));
}

/**
 * Small timeline tag when viewer opted in and either:
 * - this actor is marked from cached posts (≥3 slang hits), or
 * - this specific post matches the heuristic (early signal before mark).
 */
function admin_anti_ai_tag_html(string $text, ?string $actorId = null): string
{
    if (!admin_viewer_anti_ai_enabled()) {
        return '';
    }
    $actorId = $actorId !== null ? rtrim(trim($actorId), '/') : '';
    $marked = $actorId !== ''
        && function_exists('ap_anti_ai_actor_is_marked')
        && ap_anti_ai_actor_is_marked($actorId);
    $thisPost = admin_text_looks_anti_ai($text);
    if (!$marked && !$thisPost) {
        return '';
    }
    $title = $marked
        ? 'Marked anti-AI from cached posts (3+ uses of slop/clanker/etc.)'
        : 'Uses anti-AI slang in this post';
    return '<span class="tag" style="margin-left:.35rem" title="' . h($title) . '">anti-ai</span>';
}

/**
 * Wrap post body + media + quotes + link cards so long posts can fold as one unit.
 */
function admin_tweet_content_wrap(string $innerHtml): string
{
    $innerHtml = trim($innerHtml);
    if ($innerHtml === '') {
        return '';
    }
    return '<div class="tweet-content">' . $innerHtml . '</div>';
}

/**
 * Wrap body/media HTML in a content-warning disclosure when needed.
 * Always wraps the payload in .tweet-content (text + images + cards fold together).
 */
function admin_cw_gate_html(string $spoilerText, bool $sensitive, string $innerHtml): string
{
    $spoilerText = trim($spoilerText);
    $autoUnblur = $sensitive && $spoilerText === '' && function_exists('ap_profile_get')
        && !empty(ap_profile_get((string) ($GLOBALS['vaak_actor_key'] ?? 'cmdr_nova'))['auto_unblur_sensitive']);
    if ($innerHtml === '') {
        // Still show a CW shell for sensitive/spoiler posts with no extractable body/media
        // (e.g. gifv filtered before fix) so the card isn't invisible.
        if (!$sensitive && $spoilerText === '') {
            return '';
        }
        $innerHtml = '<div class="meta">(sensitive media — open post on remote if nothing appears)</div>';
    }
    $wrapped = admin_tweet_content_wrap($innerHtml);
    if ((!$sensitive || $autoUnblur) && $spoilerText === '') {
        return $wrapped;
    }
    $label = $spoilerText !== '' ? $spoilerText : 'Sensitive content';
    return '<details class="cw-gate">'
        . '<summary><span class="cw-label">CW</span>'
        . '<span class="cw-text">' . h($label) . '</span>'
        . '<span class="cw-hint"></span></summary>'
        . '<div class="cw-body">' . $wrapped . '</div>'
        . '</details>';
}

/**
 * Render one federation/home event as a tweet card.
 *
 * @param array<string,bool> $followingIds
 */
/**
 * True when an inbound event is an empty followers-only firehose stub
 * (no text/media) — useless "reply to parent post" cards on Federated.
 */
function admin_event_is_empty_private_stub(array $e): bool
{
    $vis = strtolower(trim((string) ($e['visibility'] ?? 'public')));
    if ($vis !== 'private' && $vis !== 'direct') {
        return false;
    }
    $summary = trim(html_entity_decode((string) ($e['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
    if ($summary !== '' && !in_array($summary, ['(no text preview)', '(attachment)'], true)) {
        return false;
    }
    $media = mention_media_urls($e['media_urls'] ?? null);
    if (is_array($media) && $media !== []) {
        return false;
    }
    // Empty text + "(attachment)" placeholder but no actual media URLs → still a stub
    return true;
}

function admin_render_event_tweet(array $e, array $followingIds, string $returnView, bool $fromFollowedTag = false): void
{
    // Hide empty followers-only firehose stubs (no body to show).
    if (admin_event_is_empty_private_stub($e)) {
        return;
    }
    // Inbound Announce → Mastodon-style "X boosted" card with original author visible
    if (strtolower((string) ($e['type'] ?? '')) === 'announce') {
        admin_render_remote_boost_card($e, $followingIds, $returnView, $fromFollowedTag);
        return;
    }
    $canReply = feed_event_can_reply($e);
    $replyObjectId = feed_event_reply_object_id($e);
    $summaryRaw = html_entity_decode((string) ($e['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8');
    // Wafrn can concatenate a full local handle with the first word of its
    // commentary (for example `@cmdr_nova@mkultra.monsteraudio`). Restore the
    // missing separator before quote/mention parsing.
    $summaryRaw = preg_replace(
        '/(@[A-Za-z0-9_]+@mkultra\.monster)(?=[\p{L}\p{N}])/u',
        '$1 ',
        $summaryRaw
    ) ?? $summaryRaw;
    // Nuke dumped ActivityPub JSON anywhere in the feed summary (broken quote enrichment)
    $summaryIsAs2Dump = static function (string $t): bool {
        return str_contains($t, '"@context"')
            || str_contains($t, 'activitystreams')
            || str_contains($t, 'QuoteAuthorization')
            || str_contains($t, 'interactionPolicy')
            || (bool) preg_match('/↪ QT(Create|Announce|Update|Note|QuotePost)\b/u', $t);
    };
    if ($summaryRaw !== '' && $summaryIsAs2Dump($summaryRaw)) {
        // Keep commentary before the QT marker when possible
        if (preg_match('/^(.*?)(?:\n\n↪ QT|\n↪ QT)/us', $summaryRaw, $m)) {
            $keep = trim($m[1]);
            $summaryRaw = ($keep !== '' && !$summaryIsAs2Dump($keep))
                ? ($keep . "\n\n↪ QT: (quoted post unavailable)")
                : '↪ QT: (quoted post unavailable)';
        } else {
            $summaryRaw = '↪ QT: (quoted post unavailable)';
        }
    }
    // Strip RE:<url> glued prefixes from quote commentary (same as notifications)
    if ($summaryRaw !== '' && function_exists('ap_masto_clean_mention_text')) {
        if (str_contains($summaryRaw, '↪ QT')) {
            // Clean only the commentary half so we don't touch the quoted snippet
            if (preg_match('/^(.*?)((?:\n\n|\n)↪ QT.*)$/us', $summaryRaw, $cm)) {
                $summaryRaw = ap_masto_clean_mention_text($cm[1]) . $cm[2];
            } else {
                $summaryRaw = ap_masto_clean_mention_text($summaryRaw);
            }
        } else {
            $summaryRaw = ap_masto_clean_mention_text($summaryRaw);
        }
    }
    $quoteParts = null;
    $quotedStatus = null;
    $quotedStatusUrl = '';
    $quotedBsky = null;
    if ($summaryRaw !== '' && str_contains($summaryRaw, '↪ QT')) {
        $chunks = preg_split('/\n\n↪ QT/u', $summaryRaw, 2);
        if (!is_array($chunks) || count($chunks) !== 2) {
            // Tolerate single newline between commentary and QT marker
            $chunks = preg_split('/\n↪ QT/u', $summaryRaw, 2);
        }
        if (is_array($chunks) && count($chunks) === 2) {
            $commentary = trim($chunks[0]);
            $quoted = trim('↪ QT' . $chunks[1]);
            if ($summaryIsAs2Dump($quoted) || preg_match('/^↪ QT(Create|Announce|Update|Note|QuotePost)\b/u', $quoted)) {
                $quoted = '↪ QT: (quoted post unavailable)';
            }
            if ($summaryIsAs2Dump($commentary)) {
                $commentary = '';
            }
            $quoteParts = [
                'commentary' => $commentary,
                'quoted' => $quoted,
            ];
            // Wafrn and older Mastodon-compatible servers often provide only
            // a QT marker plus the quoted object URL. Hydrate from Vaak's
            // cache when possible; otherwise keep the fallback compact.
            if (preg_match('#https://[^\s<>]+#u', $quoted, $qm)) {
                $quotedStatusUrl = rtrim((string) $qm[0], '.,);]');
            } elseif (preg_match('#(?:^|\n)RE:\s*(https://[^\s<>]+)#u', $commentary, $rm)) {
                // Many servers put the quoted permalink only in the RE: prefix.
                $quotedStatusUrl = rtrim((string) $rm[1], '.,);]');
            }
            if ($quotedStatusUrl !== '' && function_exists('ap_masto_lookup_status_by_object_url')) {
                $quotedStatus = ap_masto_lookup_status_by_object_url($quotedStatusUrl, 0, false);
            }
            // Bridgy Fed Bluesky quotes often only carry a URL stub. Hydrate from
            // bsky_posts / public AppView so Home shows the quoted body instead of
            // just "Open quoted".
            $qStatusPlain = '';
            if (is_array($quotedStatus)) {
                $qStatusPlain = function_exists('admin_html_to_plain')
                    ? trim(admin_html_to_plain((string) ($quotedStatus['content'] ?? '')))
                    : trim(strip_tags((string) ($quotedStatus['content'] ?? '')));
            }
            if (
                $quotedStatusUrl !== ''
                && $qStatusPlain === ''
                && function_exists('ap_bsky_post_preview_from_url')
                && function_exists('ap_bsky_at_uri_from_any_url')
                && ap_bsky_at_uri_from_any_url($quotedStatusUrl) !== null
            ) {
                $ownerForBsky = function_exists('admin_owner_user_id') ? admin_owner_user_id() : 0;
                $quotedBsky = ap_bsky_post_preview_from_url($quotedStatusUrl, $ownerForBsky, false);
                if ($quotedBsky === null) {
                    $bskyQuoteBudget = &$GLOBALS['admin_bsky_quote_fetch_budget'];
                    if (!isset($bskyQuoteBudget) || !is_int($bskyQuoteBudget)) {
                        $bskyQuoteBudget = 3; // small paint budget per request
                    }
                    if ($bskyQuoteBudget > 0) {
                        $bskyQuoteBudget--;
                        $quotedBsky = ap_bsky_post_preview_from_url($quotedStatusUrl, $ownerForBsky, true);
                    }
                }
            }
        }
    }
    $aid = (string) ($e['actor_id'] ?? '');
    $alreadyFollowing = $aid !== '' && function_exists('admin_is_following')
        ? admin_is_following($followingIds, $aid)
        : ($aid !== '' && (!empty($followingIds[$aid]) || !empty($followingIds[rtrim($aid, '/')])));
    $eventId = (int) ($e['id'] ?? 0);
    $objectId = (string) ($e['object_id'] ?? '');
    // Prefer masto snowflake for local notes (synthetic outbox:* ids cast to 0)
    $statusId = '';
    if ($objectId !== '' && function_exists('ap_masto_status_by_note_id') && function_exists('ap_masto_snowflake_id')
        && preg_match('#^https://mkultra\.monster/users/[A-Za-z0-9_]+/notes/#', $objectId)) {
        $localNote = ap_masto_status_by_note_id($objectId);
        if (is_array($localNote) && !empty($localNote['local_id'])) {
            $statusId = ap_masto_snowflake_id(
                (string) ($localNote['published'] ?? ($e['created_at'] ?? gmdate('c'))),
                (int) $localNote['local_id'],
                0
            );
        }
    }
    if ($statusId === '' && $eventId > 0) {
        $statusId = ap_masto_event_status_id($eventId, isset($e['created_at']) ? (string) $e['created_at'] : null);
    }
    $fav = $statusId !== '' && ap_masto_status_is_favourited($statusId);
    $bm = $statusId !== '' && ap_masto_status_is_bookmarked($statusId);
    $boosted = $statusId !== '' && ap_masto_status_is_reblogged($statusId);
    $isOwnEvent = $aid !== '' && vaak_is_own_url($aid);
    $isOtherLocal = $aid !== ''
        && str_starts_with(rtrim($aid, '/'), 'https://mkultra.monster/users/')
        && !$isOwnEvent;
    $profileHref = $aid !== ''
        ? ('?view=remote_profile&actor=' . rawurlencode($aid) . '&from=' . rawurlencode($returnView))
        : '';
    ?>
          <article class="tweet">
            <div class="tweet-hd">
              <?php if ($profileHref !== ''): ?>
                <a href="<?= h($profileHref) ?>" style="text-decoration:none"><?= admin_avatar_img($aid !== '' ? $aid : null) ?></a>
              <?php else: ?>
                <?= admin_avatar_img($aid !== '' ? $aid : null) ?>
              <?php endif; ?>
              <div class="tweet-hd-main">
                <div>
                  <?php
                    $whoName = actor_display_name($aid !== '' ? $aid : null);
                    $whoHandle = actor_handle($aid !== '' ? $aid : null);
                    $whoNameHtml = actor_display_name_html($aid !== '' ? $aid : null);
                  ?>
                  <?php if ($profileHref !== ''): ?>
                    <a class="who" href="<?= h($profileHref) ?>" style="color:inherit;text-decoration:none"><?= $whoNameHtml ?></a>
                    <?php if ($whoHandle !== '' && $whoHandle !== $whoName): ?>
                      <a class="meta" href="<?= h($profileHref) ?>" style="color:var(--muted);text-decoration:none"> <?= h($whoHandle) ?></a>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="who"><?= $whoNameHtml ?></span>
                    <?php if ($whoHandle !== '' && $whoHandle !== $whoName): ?>
                      <span class="meta"> <?= h($whoHandle) ?></span>
                    <?php endif; ?>
                  <?php endif; ?>
                  <span class="meta"> · <?= h((string) $e['type']) ?> · <?= h(relative_time($e['created_at'])) ?></span>
                  <?php
                    $evVis = admin_visibility_meta($e['visibility'] ?? 'public');
                    if ($evVis['key'] !== 'public'):
                  ?>
                    <span class="tag" style="margin-left:.35rem" title="Audience"><?= h($evVis['label']) ?></span>
                  <?php endif; ?>
                  <?php if ($fromFollowedTag || !empty($e['_from_followed_tag'])): ?>
                    <?php
                      $matchedTag = '';
                      $sumForTag = (string) ($e['summary'] ?? '');
                      if ($sumForTag !== '' && function_exists('ap_masto_followed_tags') && function_exists('ap_masto_normalize_tag_name')) {
                          foreach (ap_masto_followed_tags(0, admin_owner_user_id()) as $ft) {
                              $tn = ap_masto_normalize_tag_name((string) ($ft['name'] ?? ''));
                              if ($tn !== '' && preg_match('/#' . preg_quote($tn, '/') . '\b/ui', $sumForTag)) {
                                  $matchedTag = $tn;
                                  break;
                              }
                          }
                      }
                    ?>
                    <span class="tag" title="Matched a hashtag you follow"><?= $matchedTag !== '' ? '#' . h($matchedTag) : 'followed tag' ?></span>
                  <?php endif; ?>
                  <?php if ($isOtherLocal): ?>
                    <span class="tag" title="Posted by another account on this instance">local</span>
                  <?php endif; ?>
                  <?= admin_anti_ai_tag_html($summaryRaw, $aid !== '' ? $aid : null) ?>
                </div>
                <div class="meta"><?= h((string) $e['host']) ?><?= $evVis['key'] !== 'public' ? ' · ' . h($evVis['label']) : '' ?></div>
              </div>
            </div>
            <?php
              $replyParentUrl = rtrim((string) ($e['in_reply_to'] ?? ''), '/');
              if ($replyParentUrl !== '' && str_starts_with($replyParentUrl, 'https://')):
                  $parentEvent = function_exists('ap_event_by_object_id') ? ap_event_by_object_id($replyParentUrl) : null;
                  $parentSnippet = '';
                  if (is_array($parentEvent) && !empty($parentEvent['summary'])) {
                      $parentSnippet = mb_substr(trim((string) $parentEvent['summary']), 0, 120);
                  }
            ?>
              <div class="meta" style="margin:.35rem 0 .5rem">
                ↩ reply to
                <a href="<?= h(admin_status_href($replyParentUrl, $returnView)) ?>"><?= h($parentSnippet !== '' ? $parentSnippet : 'parent post') ?></a>
              </div>
            <?php endif; ?>
            <?php
              $cwSpoiler = trim((string) ($e['spoiler_text'] ?? ''));
              $cwSensitive = !empty($e['sensitive']) || $cwSpoiler !== '';
              $eMedia = mention_media_urls($e['media_urls'] ?? null);
              $summaryRaw = admin_media_placeholder_summary($summaryRaw, $eMedia);
              $bodyChunk = '';
              // Resolve bare @user → full acct via cache (same path Ice Cubes uses), so
              // timeline summaries that lost @host still get clickable profile links.
              $eventMentions = [];
              if ($summaryRaw !== '' && function_exists('ap_masto_content_with_mentions')) {
                  $extraActors = [];
                  if ($aid !== '' && str_starts_with($aid, 'https://')) {
                      $extraActors[] = $aid;
                  }
                  $eventMentions = ap_masto_content_with_mentions($summaryRaw, $extraActors)['mentions'] ?? [];
              }
              if ($quoteParts !== null) {
                  if ($quoteParts['commentary'] !== '') {
                      $bodyChunk .= '<div class="body feed-body">'
                          . admin_linkify_body_html($quoteParts['commentary'], $returnView, $eventMentions) . '</div>';
                  }
                  $qMentions = [];
                  if ($quoteParts['quoted'] !== '' && function_exists('ap_masto_content_with_mentions')) {
                      $qMentions = ap_masto_content_with_mentions($quoteParts['quoted'], [])['mentions'] ?? [];
                  }
                  $bodyChunk .= '<div class="quote-block"><span class="qt-label">Quoted</span>';
                  // Fallback text/acct from the already-enriched "↪ QT @acct: text" line
                  // when the remote object is not in local cache (common; no sync HTTP on paint).
                  $qFallbackAcct = '';
                  $qFallbackText = '';
                  if (preg_match('/^↪ QT(?:\s+@(\S+))?\s*:\s*(.*)$/us', (string) ($quoteParts['quoted'] ?? ''), $qfm)) {
                      $qFallbackAcct = trim((string) ($qfm[1] ?? ''));
                      $qFallbackText = trim((string) ($qfm[2] ?? ''));
                      if ($qFallbackText === '(quoted post unavailable)' || $qFallbackText === '(quoted post)') {
                          $qFallbackText = '';
                      }
                      if (str_starts_with($qFallbackText, 'https://') && !str_contains($qFallbackText, ' ')) {
                          // URL-only stub — keep for linking, not as body text
                          if ($quotedStatusUrl === '') {
                              $quotedStatusUrl = rtrim($qFallbackText, '.,);]');
                          }
                          $qFallbackText = '';
                      }
                  }
                  if (is_array($quotedStatus)) {
                      $qAcct = (string) ($quotedStatus['account']['acct'] ?? '');
                      $qText = admin_html_to_plain((string) ($quotedStatus['content'] ?? ''));
                      if ($qAcct !== '') {
                          $bodyChunk .= '<div class="meta" style="margin-top:.3rem">@' . h($qAcct) . '</div>';
                      } elseif ($qFallbackAcct !== '') {
                          $bodyChunk .= '<div class="meta" style="margin-top:.3rem">@' . h($qFallbackAcct) . '</div>';
                      } elseif (is_array($quotedBsky) && ($quotedBsky['handle'] ?? '') !== '') {
                          $bodyChunk .= '<div class="meta" style="margin-top:.3rem">@' . h((string) $quotedBsky['handle']) . '</div>';
                      }
                      if ($qText !== '') {
                          $bodyChunk .= '<div style="margin-top:.25rem">'
                              . admin_linkify_body_html(mb_substr($qText, 0, 400), $returnView, [])
                              . '</div>';
                      } elseif ($qFallbackText !== '') {
                          $bodyChunk .= '<div style="margin-top:.25rem">'
                              . admin_linkify_body_html(mb_substr($qFallbackText, 0, 400), $returnView, $qMentions)
                              . '</div>';
                      } elseif (is_array($quotedBsky) && trim((string) ($quotedBsky['text'] ?? '')) !== '') {
                          $bodyChunk .= '<div style="margin-top:.25rem">'
                              . admin_linkify_body_html(mb_substr((string) $quotedBsky['text'], 0, 400), $returnView, [])
                              . '</div>';
                      }
                      if ($quotedStatusUrl !== '') {
                          $bodyChunk .= '<div class="meta" style="margin-top:.3rem"><a href="'
                              . h(admin_status_href($quotedStatusUrl, $returnView)) . '">Open quoted</a></div>';
                      }
                  } elseif (is_array($quotedBsky) && (trim((string) ($quotedBsky['text'] ?? '')) !== '' || ($quotedBsky['handle'] ?? '') !== '')) {
                      $qAcct = ($quotedBsky['handle'] ?? '') !== '' ? (string) $quotedBsky['handle'] : '';
                      if ($qAcct !== '') {
                          $bodyChunk .= '<div class="meta" style="margin-top:.3rem">@' . h($qAcct) . '</div>';
                      }
                      $qText = trim((string) ($quotedBsky['text'] ?? ''));
                      if ($qText !== '') {
                          $bodyChunk .= '<div style="margin-top:.25rem">'
                              . admin_linkify_body_html(mb_substr($qText, 0, 400), $returnView, [])
                              . '</div>';
                      }
                      $qOpen = (string) ($quotedBsky['url'] ?? $quotedStatusUrl);
                      if ($qOpen !== '' && $qOpen !== 'https://bsky.app/') {
                          $bodyChunk .= '<div class="meta" style="margin-top:.3rem"><a href="'
                              . h(admin_status_href($qOpen, $returnView)) . '">Open quoted</a></div>';
                      }
                  } elseif ($qFallbackText !== '' || $qFallbackAcct !== '') {
                      if ($qFallbackAcct !== '') {
                          $bodyChunk .= '<div class="meta" style="margin-top:.3rem">@' . h($qFallbackAcct) . '</div>';
                      }
                      if ($qFallbackText !== '') {
                          $bodyChunk .= '<div style="margin-top:.25rem">'
                              . admin_linkify_body_html(mb_substr($qFallbackText, 0, 400), $returnView, $qMentions)
                              . '</div>';
                      }
                      if ($quotedStatusUrl !== '') {
                          $bodyChunk .= '<div class="meta" style="margin-top:.3rem"><a href="'
                              . h(admin_status_href($quotedStatusUrl, $returnView)) . '">Open quoted</a></div>';
                      }
                  } elseif ($quotedStatusUrl !== '') {
                      $bodyChunk .= '<div class="meta" style="margin-top:.3rem"><a href="'
                          . h(admin_status_href($quotedStatusUrl, $returnView)) . '">Open quoted post</a></div>';
                  } else {
                      $bodyChunk .= '<div class="meta" style="margin-top:.3rem">Quoted post unavailable</div>';
                  }
                  $bodyChunk .= '</div>';
              } elseif ($summaryRaw !== '') {
                  $bodyChunk .= '<div class="body feed-body">'
                      . admin_linkify_body_html($summaryRaw, $returnView, $eventMentions) . '</div>';
              }
              $mediaChunk = $eMedia ? admin_media_row_html($eMedia) : '';
              echo admin_cw_gate_html($cwSpoiler, $cwSensitive, $bodyChunk . $mediaChunk);
            ?>
            <div class="tags">
              <?php if (!empty($e['target_actor'])): ?><span class="tag">target <?= h(basename(parse_url((string) $e['target_actor'], PHP_URL_PATH) ?: '')) ?></span><?php endif; ?>
            </div>
            <div class="tweet-actions">
              <?php if ($objectId !== ''): ?>
                <a class="btn btn-ghost" href="<?= h(admin_status_href($objectId, $returnView)) ?>" style="padding:.25rem .7rem;font-size:.8rem">Open</a>
              <?php endif; ?>
              <?php if ($canReply): ?>
                <a class="icon-btn" href="?view=<?= h($returnView) ?>&amp;compose=1&amp;reply_to=<?= urlencode($replyObjectId) ?>&amp;to=<?= urlencode($aid) ?>" title="Reply" aria-label="Reply"><i class="ph ph-arrow-bend-up-left" aria-hidden="true"></i></a>
                <?php if ($aid !== '' && !$alreadyFollowing): ?>
                  <form method="post" action="?view=following" style="display:inline">
                    <input type="hidden" name="action" value="follow_remote">
                    <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                    <input type="hidden" name="actor_id" value="<?= h($aid) ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Follow</button>
                  </form>
                <?php endif; ?>
                <a href="<?= h(admin_remote_object_href((string) $e['object_id'])) ?>" target="_blank" rel="noopener noreferrer" class="meta" title="Open on remote instance">Remote</a>
              <?php endif; ?>
              <?php if ($statusId !== '' && $objectId !== ''): ?>
                <form method="post" action="?view=<?= h($returnView) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $fav ? 'unfavourite_status' : 'favourite_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($statusId) ?>">
                  <input type="hidden" name="object_id" value="<?= h($objectId) ?>">
                  <input type="hidden" name="target_actor" value="<?= h($aid) ?>">
                  <button class="icon-btn<?= $fav ? ' on' : '' ?>" type="submit" title="<?= $fav ? 'Unlike' : 'Like' ?>" aria-label="<?= $fav ? 'Unlike' : 'Like' ?>"><i class="ph<?= $fav ? '-fill' : '' ?> ph-heart" aria-hidden="true"></i></button>
                </form>
                <form method="post" action="?view=<?= h($returnView) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $boosted ? 'unreblog_status' : 'reblog_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($statusId) ?>">
                  <input type="hidden" name="object_id" value="<?= h($objectId) ?>">
                  <input type="hidden" name="target_actor" value="<?= h($aid) ?>">
                  <button class="icon-btn<?= $boosted ? ' on' : '' ?>" type="submit" title="<?= $boosted ? 'Undo boost' : 'Boost' ?>" aria-label="<?= $boosted ? 'Undo boost' : 'Boost' ?>"><i class="ph ph-repeat" aria-hidden="true"></i></button>
                </form>
                <a class="icon-btn" href="?view=<?= h($returnView) ?>&amp;compose=1&amp;quote_object=<?= urlencode($objectId) ?>&amp;quote_status_id=<?= urlencode($statusId) ?>" title="Quote" aria-label="Quote"><i class="ph ph-quotes" aria-hidden="true"></i></a>
                <?php if ($objectId !== '' && !$isOwnEvent): ?>
                <form method="post" action="?view=<?= h($returnView) ?>" style="display:inline" onsubmit="return confirm('Bite this post?');">
                  <input type="hidden" name="action" value="bite_remote">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="bite_kind" value="post">
                  <input type="hidden" name="target" value="<?= h($objectId) ?>">
                  <button class="icon-btn" type="submit" title="Bite (Wafrn)" aria-label="Bite"><i class="ph ph-tooth" aria-hidden="true"></i></button>
                </form>
                <?php endif; ?>
                <form method="post" action="?view=<?= h($returnView) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $bm ? 'unbookmark_status' : 'bookmark_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($statusId) ?>">
                  <input type="hidden" name="object_id" value="<?= h($objectId) ?>">
                  <button class="icon-btn<?= $bm ? ' on' : '' ?>" type="submit" title="<?= $bm ? 'Bookmark folders' : 'Bookmark' ?>" aria-label="<?= $bm ? 'Bookmark folders' : 'Bookmark' ?>" data-bm-picker="<?= $bm ? '1' : '0' ?>"><i class="ph<?= $bm ? '-fill' : '' ?> ph-bookmark-simple" aria-hidden="true"></i></button>
                </form>
              <?php endif; ?>
              <?= block_quick_actions($aid, short_host($aid), $returnView, admin_owner_user_id(), !empty($GLOBALS['vaak_is_admin']), $returnView, $objectId) ?>
            </div>
          </article>
    <?php
}

/** True when host is this VAAK/home instance (never offer "Block instance" for it). */
function admin_is_home_instance_host(?string $host): bool
{
    $host = strtolower(trim((string) $host));
    if ($host === '') {
        return false;
    }
    return $host === 'mkultra.monster' || str_ends_with($host, '.mkultra.monster');
}

/** Return the current server-wide actor control without querying per menu item. */
function admin_global_actor_control(string $actorId): ?array
{
    static $controls = null;
    if ($controls === null) {
        $controls = [];
        foreach (function_exists('ap_block_list') ? ap_block_list() : [] as $row) {
            if (($row['scope'] ?? '') === 'actor') {
                $controls[rtrim((string) ($row['value'] ?? ''), '/')] = $row;
            }
        }
    }
    $key = rtrim($actorId, '/');
    return $key !== '' && isset($controls[$key]) && is_array($controls[$key])
        ? $controls[$key]
        : null;
}

function admin_global_domain_control(string $host): ?array
{
    static $controls = null;
    if ($controls === null) {
        $controls = [];
        foreach (function_exists('ap_block_list') ? ap_block_list() : [] as $row) {
            if (($row['scope'] ?? '') === 'domain') {
                $controls[strtolower((string) ($row['value'] ?? ''))] = $row;
            }
        }
    }
    $key = strtolower(trim($host));
    return $key !== '' && isset($controls[$key]) && is_array($controls[$key])
        ? $controls[$key]
        : null;
}

/** Permission-aware actor actions used by posts, profiles, and DMs. */
function block_quick_actions(?string $actorId, ?string $host, string $returnView, int $ownerUserId = 0, bool $isAdmin = false, string $returnFrom = '', string $objectId = ''): string
{
    $html = '';
    $actorId = is_string($actorId) ? rtrim($actorId, '/') : '';
    $host = is_string($host) ? strtolower(trim($host)) : '';
    if ($host === '' && $actorId !== '') {
        $host = strtolower(short_host($actorId));
    }
    $isLocal = $actorId !== '' && function_exists('vaak_is_local_url') && vaak_is_local_url($actorId);
    $isSelf = $actorId !== '' && function_exists('vaak_actor_id')
        && rtrim(vaak_actor_id(), '/') === $actorId;
    $personalBlock = function_exists('ap_user_block_find')
        ? ap_user_block_find($actorId, $host, $ownerUserId)
        : null;
    $isMuted = $actorId !== '' && function_exists('ap_is_muted_actor')
        ? ap_is_muted_actor($actorId, $ownerUserId)
        : false;
    $isDeprioritized = $actorId !== '' && function_exists('ap_is_deprioritized_actor')
        ? ap_is_deprioritized_actor($actorId, $ownerUserId)
        : false;
    $globalControl = ($isAdmin && !$isLocal && $actorId !== '')
        ? admin_global_actor_control($actorId)
        : null;
    $menu = '';
    // Personal mute/block first — available to every signed-in user, including admins.
    // Don't offer personal actions for the signed-in actor itself.
    if ($actorId !== '' && str_starts_with($actorId, 'https://') && !$isSelf) {
        $isBlocked = is_array($personalBlock);
        $menu .= '<form method="post" action="?view=' . h($returnView) . '">'
            . '<input type="hidden" name="csrf" value="' . h(ap_auth_csrf_token()) . '">'
            . '<input type="hidden" name="action" value="' . ($isBlocked ? 'user_block_remove' : 'user_block_add') . '">'
            . '<input type="hidden" name="return_view" value="' . h($returnView) . '">'
            . '<input type="hidden" name="return_from" value="' . h($returnFrom) . '">'
            . '<input type="hidden" name="return_actor" value="' . h($actorId) . '">'
            . ($isBlocked
                ? '<input type="hidden" name="id" value="' . (int) ($personalBlock['id'] ?? 0) . '">'
                : '<input type="hidden" name="target" value="' . h($actorId) . '">')
            . '<button class="menu-action" type="submit" title="Hide from your timelines only">'
            . ($isBlocked ? 'Unblock for me' : 'Block for me') . '</button>'
            . '</form>';
        $menu .= '<form method="post" action="?view=' . h($returnView) . '">'
            . '<input type="hidden" name="csrf" value="' . h(ap_auth_csrf_token()) . '">'
            . '<input type="hidden" name="action" value="' . ($isMuted ? 'unmute_remote' : 'mute_remote') . '">'
            . '<input type="hidden" name="return_view" value="' . h($returnView) . '">'
            . '<input type="hidden" name="return_from" value="' . h($returnFrom) . '">'
            . '<input type="hidden" name="return_actor" value="' . h($actorId) . '">'
            . '<input type="hidden" name="actor_id" value="' . h($actorId) . '">'
            . '<button class="menu-action" type="submit" title="Hide from your Home / Federated / Notifications">'
            . ($isMuted ? 'Unmute for me' : 'Mute for me') . '</button>'
            . '</form>';
        // Soft-rank on Home only — still shows on Federated / Local / notifications.
        $menu .= '<form method="post" action="?view=' . h($returnView) . '">'
            . '<input type="hidden" name="csrf" value="' . h(ap_auth_csrf_token()) . '">'
            . '<input type="hidden" name="action" value="'
            . ($isDeprioritized ? 'undeprioritize_remote' : 'deprioritize_remote') . '">'
            . '<input type="hidden" name="return_view" value="' . h($returnView) . '">'
            . '<input type="hidden" name="return_from" value="' . h($returnFrom) . '">'
            . '<input type="hidden" name="return_actor" value="' . h($actorId) . '">'
            . '<input type="hidden" name="actor_id" value="' . h($actorId) . '">'
            . '<button class="menu-action" type="submit">'
            . ($isDeprioritized ? 'Stop deprioritizing' : 'Deprioritize on Home')
            . '</button>'
            . '</form>';
        // Report is available to every signed-in user (local peers → mod queue; remote → Flag).
        $menu .= '<a class="menu-action" href="' . h(admin_report_href($actorId, $objectId, $returnFrom !== '' ? $returnFrom : $returnView)) . '">Report user</a>';
    }
    // Admin-only: one mute + one block for this actor (instance blocks live under Server blocks).
    if ($isAdmin && !$isLocal && $actorId !== '') {
        if ($menu !== '') {
            $menu .= '<div class="menu-action-sep" role="separator" aria-hidden="true"></div>';
        }
        $globalKind = is_array($globalControl) ? (string) ($globalControl['kind'] ?? '') : '';
        if ($globalKind === 'mute') {
            $menu .= '<form method="post" action="?view=blocks">'
                . '<input type="hidden" name="csrf" value="' . h(ap_auth_csrf_token()) . '">'
                . '<input type="hidden" name="action" value="unblock">'
                . '<input type="hidden" name="id" value="' . (int) ($globalControl['id'] ?? 0) . '">'
                . '<button class="menu-action" type="submit">Remove user mute (server)</button></form>';
        } else {
            $menu .= '<form method="post" action="?view=blocks" onsubmit="return confirm(\'Mute this user for everyone on Vaak?\');">'
                . '<input type="hidden" name="csrf" value="' . h(ap_auth_csrf_token()) . '">'
                . '<input type="hidden" name="action" value="block_actor">'
                . '<input type="hidden" name="kind" value="mute">'
                . '<input type="hidden" name="actor_id" value="' . h($actorId) . '">'
                . '<button class="menu-action" type="submit">Mute this user (server)</button></form>';
        }
        if ($globalKind === 'block' || $globalKind === 'suspend') {
            $menu .= '<form method="post" action="?view=blocks">'
                . '<input type="hidden" name="csrf" value="' . h(ap_auth_csrf_token()) . '">'
                . '<input type="hidden" name="action" value="unblock">'
                . '<input type="hidden" name="id" value="' . (int) ($globalControl['id'] ?? 0) . '">'
                . '<button class="menu-action" type="submit">Remove user block (server)</button></form>';
        } else {
            $menu .= '<form method="post" action="?view=blocks" onsubmit="return confirm(\'Block this user server-wide? VAAK will reject their federation (HTTP 403) and stop delivering to them.\');">'
                . '<input type="hidden" name="csrf" value="' . h(ap_auth_csrf_token()) . '">'
                . '<input type="hidden" name="action" value="block_actor">'
                . '<input type="hidden" name="kind" value="block">'
                . '<input type="hidden" name="actor_id" value="' . h($actorId) . '">'
                . '<button class="menu-action danger" type="submit">Block this user (server)</button></form>';
        }
        $menu .= '<a class="menu-action" href="/vaak/?view=moderation&amp;actor=' . rawurlencode($actorId) . '&amp;from=' . rawurlencode($returnView) . '">Open in moderation panel</a>';
    } elseif ($isAdmin && $isLocal && $actorId !== '') {
        if ($menu !== '') {
            $menu .= '<div class="menu-action-sep" role="separator" aria-hidden="true"></div>';
        }
        $menu .= '<a class="menu-action" href="/vaak/?view=moderation&amp;actor=' . rawurlencode($actorId) . '&amp;from=' . rawurlencode($returnView) . '">Open in moderation panel</a>';
    }
    if ($menu === '') {
        return '';
    }
    return '<details class="post-action-menu">'
        . '<summary class="icon-btn" title="More actions" aria-label="More actions">⋯</summary>'
        . '<div class="post-action-menu__body">' . $menu . '</div>'
        . '</details>';
}

function short_host(?string $actorId): string
{
    if (!$actorId) {
        return '';
    }
    $host = parse_url($actorId, PHP_URL_HOST);
    return is_string($host) ? $host : '';
}

/**
 * Best-effort avatar URL for admin UI (R2 cache → remote icon → local profile → fallback).
 * Does not sync-fetch (keeps /admin snappy); async warm already runs from Mastodon API paths.
 */
function admin_actor_avatar_url(?string $actorId): string
{
    static $memo = [];
    $remoteFallback = defined('AP_REMOTE_AVATAR_FALLBACK')
        ? AP_REMOTE_AVATAR_FALLBACK
        : 'https://mkultra.monster/img/avatar/default.webp';
    $localFallback = defined('AP_LOCAL_AVATAR_FALLBACK')
        ? AP_LOCAL_AVATAR_FALLBACK
        : 'https://mkultra.monster/img/avatar/local-default.webp';
    if ($actorId === null || $actorId === '' || !str_starts_with($actorId, 'https://')) {
        return $remoteFallback;
    }
    $actorId = rtrim($actorId, '/');
    if (isset($memo[$actorId])) {
        return $memo[$actorId];
    }
    // Synthetic / alert peers (Wafrn approvals DM, etc.) — fixed local art.
    if (function_exists('ap_peer_avatar_override')) {
        $ov = ap_peer_avatar_override($actorId);
        if (is_string($ov) && $ov !== '') {
            $memo[$actorId] = $ov;
            return $ov;
        }
    }
    // Any local instance account — use actor_profile (not session-only, not remote R2).
    if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $actorId, $lm)) {
        $p = ap_profile_get(strtolower($lm[1]));
        $local = function_exists('ap_local_avatar_url')
            ? ap_local_avatar_url($p['icon_url'] ?? null)
            : (ap_profile_sanitize_https_url($p['icon_url'] ?? null) ?: $localFallback);
        $memo[$actorId] = $local;
        return $local;
    }
    // Only serve R2-cached avatars in admin timelines. Hotlinking remote
    // icon_source_url breaks often (404 / hotlink blocks) and looks like a
    // "broken image" instead of our default — especially for relay strangers
    // we haven't warmed yet.
    if (function_exists('ap_remote_media_get')) {
        $cached = ap_remote_media_get($actorId, 'avatar');
        if (is_array($cached) && !empty($cached['public_url'])) {
            $u = ap_profile_sanitize_https_url($cached['public_url']);
            if ($u !== null) {
                $memo[$actorId] = $u;
                return $u;
            }
        }
        // Mastodon-compatible instances may emit a different actor IRI in
        // notifications than the profile URL we fetched. Reuse the same
        // media cache across those known aliases.
        if (function_exists('ap_masto_actor_id_aliases')) {
            foreach (ap_masto_actor_id_aliases($actorId) as $alias) {
                $alias = rtrim((string) $alias, '/');
                if ($alias === '' || $alias === $actorId) {
                    continue;
                }
                $aliasCached = ap_remote_media_get($alias, 'avatar');
                if (is_array($aliasCached) && !empty($aliasCached['public_url'])) {
                    $u = ap_profile_sanitize_https_url($aliasCached['public_url']);
                    if ($u !== null) {
                        $memo[$actorId] = $u;
                        return $u;
                    }
                }
            }
        }
    }
    // Queue a background warm when we know a source URL, but still show fallback now.
    if (function_exists('ap_remote_media_warm_async')) {
        $ra = function_exists('ap_remote_actor_get') ? ap_remote_actor_get($actorId) : null;
        if (is_array($ra) && !empty($ra['icon_source_url'])) {
            ap_remote_media_warm_async($actorId);
        }
    }
    // Until the async warm finishes, use the known remote icon rather than
    // rendering the generic fallback. onerror still protects the UI if the
    // remote host rejects the image request.
    if (is_array($ra ?? null) && !empty($ra['icon_source_url'])) {
        $source = ap_profile_sanitize_https_url((string) $ra['icon_source_url']);
        if ($source !== null) {
            $memo[$actorId] = $source;
            return $source;
        }
    }
    $memo[$actorId] = $remoteFallback;
    return $remoteFallback;
}

function admin_avatar_img(?string $actorId, string $class = 'tweet-av'): string
{
    try {
        $url = admin_actor_avatar_url($actorId);
    } catch (Throwable $e) {
        error_log('[ap-admin] avatar: ' . $e->getMessage());
        $url = defined('AP_REMOTE_AVATAR_FALLBACK')
            ? AP_REMOTE_AVATAR_FALLBACK
            : 'https://mkultra.monster/img/avatar/default.jpg';
    }
    try {
        $alt = actor_handle($actorId);
    } catch (Throwable $e) {
        $alt = '';
    }
    $fallback = defined('AP_REMOTE_AVATAR_FALLBACK')
        ? AP_REMOTE_AVATAR_FALLBACK
        : 'https://mkultra.monster/img/avatar/default.jpg';
    // onerror → default so a stale/broken R2 URL never shows a cracked icon
    return '<img class="' . h($class) . '" src="' . h($url) . '" alt="" width="40" height="40" '
        . 'loading="lazy" decoding="async" referrerpolicy="no-referrer" '
        . 'onerror="this.onerror=null;this.src=\'' . h($fallback) . '\'" '
        . 'title="' . h($alt) . '">';
}

function actor_handle(?string $actorId, ?string $username = null, bool $allowFetch = false): string
{
    $actorId = is_string($actorId) ? rtrim($actorId, '/') : '';
    if ($actorId !== '' && function_exists('ap_remote_actor_label')) {
        // Default: cache-only. Sync fetches on Federated (relay strangers) were
        // taking 30–50s and browsers showed "connection interrupted".
        if ($username !== null && $username !== ''
            && !(function_exists('ap_remote_actor_username_is_placeholder') && ap_remote_actor_username_is_placeholder($username))) {
            $host = short_host($actorId);
            if ($host !== '') {
                // Bridgy: username already looks like alice.bsky.social
                if ((str_ends_with($host, 'brid.gy') || $host === 'brid.gy') && str_contains($username, '.')) {
                    return '@' . $username;
                }
                return '@' . $username . '@' . $host;
            }
        }
        $label = ap_remote_actor_label($actorId, $allowFetch);
        return $label['handle'];
    }
    $host = short_host($actorId);
    if ($username && $host
        && !(function_exists('ap_remote_actor_username_is_placeholder') && ap_remote_actor_username_is_placeholder($username))) {
        return '@' . $username . '@' . $host;
    }
    if ($host) {
        $path = parse_url((string) $actorId, PHP_URL_PATH) ?: '';
        $base = basename(rtrim($path, '/'));
        return $base !== '' ? ('@' . $base . '@' . $host) : $host;
    }
    return (string) $actorId;
}

/** Display name for a remote actor (falls back to handle). Cache-only by default. */
function actor_display_name(?string $actorId, bool $allowFetch = false): string
{
    $actorId = is_string($actorId) ? rtrim($actorId, '/') : '';
    if ($actorId === '') {
        return actor_handle($actorId, null, $allowFetch);
    }
    // Local peers: prefer actor_profile over stale remote_actors cache
    if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $actorId, $lm)
        && function_exists('ap_profile_display_name')) {
        $name = trim((string) ap_profile_display_name(strtolower($lm[1]), true));
        if ($name !== '') {
            return $name;
        }
    }
    if (!function_exists('ap_remote_actor_label')) {
        return actor_handle($actorId, null, $allowFetch);
    }
    $label = ap_remote_actor_label($actorId, $allowFetch);
    $display = trim($label['display_name']);
    if ($display === '' || (function_exists('ap_remote_actor_username_is_placeholder')
        && ap_remote_actor_username_is_placeholder($display))) {
        return $label['handle'];
    }
    return $display;
}

/**
 * Safe HTML for a display name / snippet with custom :emoji: shortcodes rendered.
 * Pass the actor URL so we can resolve that instance’s emoji pack.
 */
function admin_emoji_html(string $text, ?string $actorId = null): string
{
    if (function_exists('ap_emoji_html')) {
        return ap_emoji_html($text, $actorId);
    }
    return h($text);
}

/** Display name as HTML (custom emojis expanded). */
function actor_display_name_html(?string $actorId): string
{
    $actorId = is_string($actorId) ? rtrim($actorId, '/') : '';
    if ($actorId === '') {
        return h(actor_handle(null));
    }
    return admin_emoji_html(actor_display_name($actorId), $actorId);
}

function relative_time(mixed $iso): string
{
    $iso = is_string($iso) ? $iso : (is_scalar($iso) ? (string) $iso : '');
    if ($iso === '') {
        return '';
    }
    $t = strtotime($iso);
    if ($t === false) {
        return $iso;
    }
    $d = time() - $t;
    if ($d < 60) {
        return $d . 's';
    }
    if ($d < 3600) {
        return intdiv($d, 60) . 'm';
    }
    if ($d < 86400) {
        return intdiv($d, 3600) . 'h';
    }
    return intdiv($d, 86400) . 'd';
}

/** Normalize + human label for post audience (public / silent / followers-only). */
function admin_visibility_meta(mixed $visibility): array
{
    $v = function_exists('ap_normalize_visibility')
        ? ap_normalize_visibility($visibility ?? 'public')
        : strtolower(trim((string) ($visibility ?? 'public')));
    if ($v === 'unlisted') {
        return ['key' => 'unlisted', 'label' => 'Silent public'];
    }
    if ($v === 'private') {
        return ['key' => 'private', 'label' => 'Followers-only'];
    }
    return ['key' => 'public', 'label' => 'Public'];
}

/**
 * Render a Mastodon Status entity (search / thread / detail) with media,
 * link cards, Open, and reply/boost/fav/quote actions.
 *
 * @param array<string,mixed> $st
 * @param array<string,bool> $followingIds
 */
function admin_render_masto_status_card(
    array $st,
    array $followingIds,
    string $returnView,
    bool $focused = false,
    bool $showOpen = true
): void {
    $boostHeader = '';
    // Keep Mastodon reblog wrapper: show "X boosted" then the original author card.
    if (
        isset($st['reblog']) && is_array($st['reblog'])
        && (
            trim(strip_tags((string) ($st['content'] ?? ''))) === ''
            || empty($st['content'])
        )
    ) {
        $boosterAcct = (string) ($st['account']['acct'] ?? '');
        $boosterName = (string) ($st['account']['display_name'] ?? $boosterAcct);
        $boostWhen = (string) ($st['created_at'] ?? '');
        $boostHeader = '<div class="meta" style="margin-bottom:.45rem;color:var(--primary)"><i class="ph ph-repeat" aria-hidden="true"></i> '
            . htmlspecialchars($boosterName !== '' ? $boosterName : 'Someone', ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')
            . ' boosted'
            . ($boostWhen !== '' ? ' · ' . htmlspecialchars(relative_time($boostWhen), ENT_QUOTES, 'UTF-8') : '')
            . '</div>';
        $st = $st['reblog'];
    }
    $acct = (string) ($st['account']['acct'] ?? '?');
    $display = (string) ($st['account']['display_name'] ?? $acct);
    $actorRef = function_exists('admin_account_actor_ref')
        ? admin_account_actor_ref(is_array($st['account'] ?? null) ? $st['account'] : [])
        : (string) ($st['account']['uri'] ?? $st['account']['url'] ?? '');
    $uri = (string) ($st['uri'] ?? $st['url'] ?? '');
    $plain = admin_html_to_plain((string) ($st['content'] ?? ''));
    $isLocalEarly = $uri !== '' && vaak_is_own_url($uri);
    // Local posts: prefer stored plain text so blank lines match compose / remotes
    if ($isLocalEarly) {
        try {
            $pst = ap_db()->prepare(
                'SELECT content_text FROM masto_statuses WHERE note_id = ? OR note_id = ? LIMIT 1'
            );
            $pst->execute([$uri, rtrim($uri, '/') . '/']);
            $dbPlainEarly = (string) ($pst->fetchColumn() ?: '');
            if ($dbPlainEarly !== '' && !in_array($dbPlainEarly, ['(quote)', '(media)', '(poll)'], true)) {
                $plain = $dbPlainEarly;
            }
        } catch (Throwable $e) {
            // keep HTML→plain
        }
    }
    if (function_exists('ap_plain_unglue_mentions')) {
        $plain = ap_plain_unglue_mentions($plain);
    }
    $stMentions = [];
    if (!empty($st['mentions']) && is_array($st['mentions'])) {
        foreach ($st['mentions'] as $sm) {
            if (is_array($sm)) {
                $stMentions[] = $sm;
            }
        }
    }
    $media = [];
    foreach (($st['media_attachments'] ?? []) as $att) {
        if (!is_array($att)) {
            continue;
        }
        $u = (string) ($att['url'] ?? $att['preview_url'] ?? '');
        $preview = (string) ($att['preview_url'] ?? '');
        if (!str_starts_with($u, 'https://')) {
            continue;
        }
        $atype = strtolower((string) ($att['type'] ?? ''));
        $attMime = strtolower((string) ($att['mediaType'] ?? ''));
        $media[] = [
            'url' => $u,
            'mediaType' => in_array($atype, ['video', 'gifv'], true)
                ? ($attMime !== '' ? $attMime : 'video/mp4')
                : ($atype === 'audio' ? ($attMime !== '' ? $attMime : 'audio/mpeg') : null),
            'preview_url' => str_starts_with($preview, 'https://') ? $preview : null,
        ];
    }
    $following = $actorRef !== '' && admin_is_following($followingIds, $actorRef);
    $sid = (string) ($st['id'] ?? '');
    $fav = $sid !== '' && function_exists('ap_masto_status_is_favourited') && ap_masto_status_is_favourited($sid);
    $boosted = $sid !== '' && function_exists('ap_masto_status_is_reblogged') && ap_masto_status_is_reblogged($sid);
    $bm = $sid !== '' && function_exists('ap_masto_status_is_bookmarked') && ap_masto_status_is_bookmarked($sid);
    $isLocal = $uri !== '' && vaak_is_own_url($uri);
    $cwSpoiler = trim((string) ($st['spoiler_text'] ?? ''));
    $cwSensitive = !empty($st['sensitive']) || $cwSpoiler !== '';

    $bodyInner = '';
    if ($plain !== '') {
        $bodyInner .= '<div class="body feed-body" style="white-space:pre-wrap">'
            . admin_linkify_body_html($plain, $returnView, $stMentions) . '</div>';
    }
    $quote = is_array($st['quote'] ?? null) ? $st['quote'] : null;
    if (is_array($quote) && is_array($quote['quoted_status'] ?? null)) {
        $qst = $quote['quoted_status'];
        $qplain = admin_html_to_plain((string) ($qst['content'] ?? ''));
        $qacct = (string) ($qst['account']['acct'] ?? '');
        $quri = (string) ($qst['uri'] ?? $qst['url'] ?? '');
        $qlabel = $qacct !== '' ? '@' . $qacct : 'Quoted';
        $qMentions = [];
        if (!empty($qst['mentions']) && is_array($qst['mentions'])) {
            foreach ($qst['mentions'] as $qm) {
                if (is_array($qm)) {
                    $qMentions[] = $qm;
                }
            }
        }
        $bodyInner .= '<div class="quote-block"><span class="qt-label">' . h($qlabel) . '</span>';
        if ($qplain !== '') {
            $bodyInner .= '<div style="margin-top:.35rem;white-space:pre-wrap">'
                . admin_linkify_body_html(mb_substr($qplain, 0, 400), $returnView, $qMentions) . '</div>';
        }
        if ($quri !== '') {
            $bodyInner .= '<div class="meta" style="margin-top:.35rem"><a href="' . h(admin_status_href($quri, $returnView)) . '">Open quoted</a></div>';
        }
        $bodyInner .= '</div>';
    } elseif (is_array($quote) && ($quote['state'] ?? '') === 'pending') {
        $bodyInner .= '<div class="quote-block meta">Quoted post (not cached yet)</div>';
    }
    if ($media) {
        $bodyInner .= admin_media_row_html($media);
    }
    $hasMedia = $media !== [];
    $cardHtml = '';
    if (!$hasMedia && function_exists('ap_link_preview_html')) {
        if (is_array($st['card'] ?? null)) {
            $cardHtml = ap_link_preview_html(array_merge($st['card'], ['status' => 'ok']), true);
        }
        if ($cardHtml === '' && $plain !== '' && function_exists('ap_link_preview_card_for_status_text')) {
            $pcard = ap_link_preview_card_for_status_text(
                (string) ($st['content'] ?? $plain),
                false,
                $returnView === 'search'
            );
            if (is_array($pcard)) {
                $cardHtml = ap_link_preview_html(array_merge($pcard, ['status' => 'ok']), true);
            }
        }
    }
    if ($cardHtml !== '') {
        $bodyInner .= $cardHtml;
    }

    $actionBase = '?view=' . rawurlencode($returnView);
    if ($returnView === 'status' && $uri !== '') {
        $actionBase = '?view=status&object=' . rawurlencode($uri) . '&from=search';
    }
    ?>
          <article class="tweet<?= $focused ? ' tweet-focus' : '' ?><?= $boostHeader !== '' ? ' tweet-boost' : '' ?>"<?= $focused ? ' id="status-focus"' : '' ?>>
            <?php if ($boostHeader !== ''): ?><?= $boostHeader ?><?php endif; ?>
            <div class="tweet-hd">
              <?php if ($actorRef !== '' && !$isLocal): ?>
                <a href="?view=remote_profile&amp;actor=<?= urlencode($actorRef) ?>&amp;from=<?= urlencode($returnView) ?>" style="text-decoration:none"><?= admin_avatar_img($actorRef) ?></a>
              <?php else: ?>
                <?= admin_avatar_img($isLocal ? vaak_actor_id() : ($actorRef !== '' ? $actorRef : null)) ?>
              <?php endif; ?>
              <div class="tweet-hd-main">
                <div>
                  <?php if ($actorRef !== '' && !$isLocal): ?>
                    <a class="who" href="?view=remote_profile&amp;actor=<?= urlencode($actorRef) ?>&amp;from=<?= urlencode($returnView) ?>" style="color:inherit;text-decoration:none"><?= admin_emoji_html($display, $actorRef) ?></a>
                    <a class="meta" href="?view=remote_profile&amp;actor=<?= urlencode($actorRef) ?>&amp;from=<?= urlencode($returnView) ?>" style="color:var(--muted);text-decoration:none"> @<?= h($acct) ?></a>
                  <?php else: ?>
                    <span class="who"><?= admin_emoji_html($display, $actorRef !== '' ? $actorRef : null) ?></span>
                    <?php if ($acct !== ''): ?><span class="meta"> @<?= h($acct) ?></span><?php endif; ?>
                  <?php endif; ?>
                  <?php if ($isLocal): ?><span class="tag" style="margin-left:.35rem">you</span><?php endif; ?>
                  <span class="meta"> · <?= h(relative_time((string) ($st['created_at'] ?? ''))) ?></span>
                  <?php if (!empty($st['edited_at'])): ?>
                    <span class="meta" title="<?= h((string) $st['edited_at']) ?>"> · edited</span>
                  <?php endif; ?>
                  <?php
                    $stVis = admin_visibility_meta($st['visibility'] ?? 'public');
                    if ($stVis['key'] !== 'public'):
                  ?>
                    <span class="tag" style="margin-left:.35rem" title="Audience"><?= h($stVis['label']) ?></span>
                  <?php endif; ?>
                  <?= admin_anti_ai_tag_html($plain, $actorRef !== '' ? $actorRef : null) ?>
                </div>
                <?php if ($stVis['key'] !== 'public'): ?>
                  <div class="meta"><?= h($stVis['label']) ?></div>
                <?php endif; ?>
              </div>
            </div>
            <?php
              echo admin_cw_gate_html($cwSpoiler, $cwSensitive, $bodyInner);
            ?>
            <div class="tweet-actions">
              <?php if ($showOpen && $uri !== ''): ?>
                <a class="btn btn-ghost" href="<?= h(admin_status_href($uri, $returnView)) ?>" style="padding:.25rem .7rem;font-size:.8rem">Open</a>
              <?php endif; ?>
              <?php if ($isLocal && $uri !== ''): ?>
                <?php
                  $editPlain = $plain;
                  $editSpoiler = $cwSpoiler;
                  $editSensitive = $cwSensitive;
                  // Prefer stored plain text (preserves blank lines) over strip_tags HTML
                  try {
                      $est = ap_db()->prepare(
                          'SELECT content_text, spoiler_text, sensitive FROM masto_statuses
                           WHERE note_id = ? OR note_id = ? LIMIT 1'
                      );
                      $est->execute([$uri, rtrim($uri, '/') . '/']);
                      $erow = $est->fetch();
                      if (is_array($erow)) {
                          $dbPlain = (string) ($erow['content_text'] ?? '');
                          if ($dbPlain !== '' && !in_array($dbPlain, ['(quote)', '(media)', '(poll)'], true)) {
                              $editPlain = $dbPlain;
                          }
                          $editSpoiler = trim((string) ($erow['spoiler_text'] ?? $editSpoiler));
                          $editSensitive = !empty($erow['sensitive']) || $editSpoiler !== '';
                      }
                  } catch (Throwable $e) {
                      // keep strip_tags fallback
                  }
                ?>
                <?= admin_edit_post_button(
                    $uri,
                    $editPlain,
                    $editSpoiler,
                    $editSensitive,
                    $returnView === 'status' ? 'home' : $returnView
                ) ?>
                <?= admin_pin_post_button(
                    $uri,
                    $focused ? 'status' : ($returnView === 'status' ? 'home' : $returnView),
                    $focused ? $returnView : ''
                ) ?>
              <?php endif; ?>
              <?php if ($uri !== ''): ?>
                <a class="icon-btn" href="?view=<?= h($returnView) ?>&amp;compose=1&amp;reply_to=<?= urlencode($uri) ?><?= $actorRef !== '' && !$isLocal ? '&amp;to=' . urlencode($actorRef) : '' ?>" title="Reply" aria-label="Reply"><i class="ph ph-arrow-bend-up-left" aria-hidden="true"></i></a>
              <?php endif; ?>
              <?php if ($sid !== '' && $uri !== ''): ?>
                <form method="post" action="<?= h($actionBase) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $fav ? 'unfavourite_status' : 'favourite_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($sid) ?>">
                  <input type="hidden" name="object_id" value="<?= h($uri) ?>">
                  <input type="hidden" name="target_actor" value="<?= h($actorRef) ?>">
                  <button class="icon-btn<?= $fav ? ' on' : '' ?>" type="submit" title="<?= $fav ? 'Unlike' : 'Like' ?>" aria-label="<?= $fav ? 'Unlike' : 'Like' ?>"><i class="ph<?= $fav ? '-fill' : '' ?> ph-heart" aria-hidden="true"></i></button>
                </form>
                <form method="post" action="<?= h($actionBase) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $boosted ? 'unreblog_status' : 'reblog_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($sid) ?>">
                  <input type="hidden" name="object_id" value="<?= h($uri) ?>">
                  <input type="hidden" name="target_actor" value="<?= h($actorRef) ?>">
                  <button class="icon-btn<?= $boosted ? ' on' : '' ?>" type="submit" title="<?= $boosted ? 'Undo boost' : 'Boost' ?>" aria-label="<?= $boosted ? 'Undo boost' : 'Boost' ?>"><i class="ph ph-repeat" aria-hidden="true"></i></button>
                </form>
                <a class="icon-btn" href="?view=<?= h($returnView) ?>&amp;compose=1&amp;quote_object=<?= urlencode($uri) ?>&amp;quote_status_id=<?= urlencode($sid) ?>" title="Quote" aria-label="Quote"><i class="ph ph-quotes" aria-hidden="true"></i></a>
                <?php if (!$isLocal): ?>
                <form method="post" action="<?= h($actionBase) ?>" style="display:inline" onsubmit="return confirm('Bite this post?');">
                  <input type="hidden" name="action" value="bite_remote">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="bite_kind" value="post">
                  <input type="hidden" name="target" value="<?= h($uri) ?>">
                  <button class="icon-btn" type="submit" title="Bite (Wafrn)" aria-label="Bite"><i class="ph ph-tooth" aria-hidden="true"></i></button>
                </form>
                <?php endif; ?>
                <form method="post" action="<?= h($actionBase) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $bm ? 'unbookmark_status' : 'bookmark_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($sid) ?>">
                  <input type="hidden" name="object_id" value="<?= h($uri) ?>">
                  <button class="icon-btn<?= $bm ? ' on' : '' ?>" type="submit" title="<?= $bm ? 'Bookmark folders' : 'Bookmark' ?>" aria-label="<?= $bm ? 'Bookmark folders' : 'Bookmark' ?>" data-bm-picker="<?= $bm ? '1' : '0' ?>"><i class="ph<?= $bm ? '-fill' : '' ?> ph-bookmark-simple" aria-hidden="true"></i></button>
                </form>
              <?php endif; ?>
              <?php if ($actorRef !== '' && !$following && !$isLocal): ?>
                <form method="post" action="<?= h($actionBase) ?>" style="display:inline">
                  <input type="hidden" name="action" value="follow_remote">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="actor_id" value="<?= h($actorRef) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Follow</button>
                </form>
              <?php endif; ?>
              <?php if ($uri !== ''): ?>
                <a href="<?= h(admin_remote_object_href($uri, $st['url'] ?? null)) ?>" target="_blank" rel="noopener noreferrer" class="meta" title="Open on remote instance">Remote</a>
              <?php endif; ?>
              <?= block_quick_actions($actorRef, short_host($actorRef), $returnView, admin_owner_user_id(), !empty($GLOBALS['vaak_is_admin']), $returnView, $uri) ?>
            </div>
          </article>
    <?php
}

/**
 * Render an inbound remote Announce as "X boosted" with the original author card.
 *
 * @param array<string,mixed> $e events row (type=Announce)
 * @param array<string,bool> $followingIds
 */
function admin_render_remote_boost_card(
    array $e,
    array $followingIds,
    string $returnView,
    bool $fromFollowedTag = false
): void {
    $boosterId = rtrim((string) ($e['actor_id'] ?? ''), '/');
    $objectId = rtrim((string) ($e['object_id'] ?? ''), '/');
    $created = (string) ($e['created_at'] ?? '');
    $origActor = null;
    if (function_exists('ap_masto_announce_original_actor')) {
        $origActor = ap_masto_announce_original_actor($e);
    }
    if ($origActor === null || $origActor === '') {
        $origActor = (string) ($e['target_actor'] ?? '');
    }
    $origActor = rtrim($origActor, '/');
    if ($origActor !== '' && function_exists('ap_row_is_hidden')
        && ap_row_is_hidden($origActor, null, admin_owner_user_id())) {
        return;
    }
    // Prefer Create/Update note row — never treat the Announce itself as the inner post
    // (ap_event_by_object_id falls back to Announce when Create was never enriched).
    $innerEvent = null;
    if ($objectId !== '') {
        try {
            $st = ap_db()->prepare(
                "SELECT * FROM events
                 WHERE type IN ('Create', 'Update')
                   AND (object_id = ? OR object_id = ?)
                   AND COALESCE(action_taken, '') != 'deleted'
                 ORDER BY CASE type WHEN 'Create' THEN 0 ELSE 1 END ASC, id DESC
                 LIMIT 1"
            );
            $st->execute([$objectId, $objectId . '/']);
            $cr = $st->fetch();
            if (is_array($cr)) {
                $innerEvent = $cr;
            }
        } catch (Throwable $ex) {
        }
        // Thin Announce (empty summary, no Create): hydrate via AJAX in the browser
        // (sync fetches stall the timeline). Opt-in sync when admin_boost_fetch_budget > 0
        // (used by the hydrate_boost partial endpoint).
        $annSum = trim((string) ($e['summary'] ?? ''));
        if (
            $innerEvent === null
            && $annSum === ''
            && function_exists('ap_masto_ensure_remote_note_event')
        ) {
            $fetchBudget = &$GLOBALS['admin_boost_fetch_budget'];
            if (!is_int($fetchBudget)) {
                $fetchBudget = 0;
            }
            if ($fetchBudget > 0) {
                $fetchBudget--;
                $fetched = ap_masto_ensure_remote_note_event($objectId);
                if (is_array($fetched) && in_array(strtolower((string) ($fetched['type'] ?? '')), ['create', 'update'], true)) {
                    $innerEvent = $fetched;
                    $newSum = trim((string) ($fetched['summary'] ?? ''));
                    $annId = (int) ($e['id'] ?? 0);
                    if ($annId > 0 && $newSum !== '') {
                        try {
                            $up = ap_db()->prepare(
                                "UPDATE events SET summary = ?, media_urls = COALESCE(?, media_urls),
                                 spoiler_text = COALESCE(?, spoiler_text),
                                 sensitive = COALESCE(?, sensitive),
                                 target_actor = COALESCE(NULLIF(target_actor, ''), ?)
                                 WHERE id = ? AND type = 'Announce'"
                            );
                            $tgt = rtrim((string) ($fetched['actor_id'] ?? ''), '/');
                            $up->execute([
                                $newSum,
                                $fetched['media_urls'] ?? null,
                                $fetched['spoiler_text'] ?? null,
                                isset($fetched['sensitive']) ? (int) !empty($fetched['sensitive']) : null,
                                $tgt !== '' ? $tgt : null,
                                $annId,
                            ]);
                            $e['summary'] = $newSum;
                            if ($tgt !== '') {
                                $e['target_actor'] = $tgt;
                            }
                        } catch (Throwable $ex) {
                            // non-fatal
                        }
                    }
                }
            }
        }
        if (is_array($innerEvent) && !empty($innerEvent['actor_id'])) {
            $origActor = rtrim((string) $innerEvent['actor_id'], '/');
        }
    }
    if ($origActor === '' && $objectId !== '' && function_exists('ap_masto_actor_url_from_object_url')) {
        $guess = ap_masto_actor_url_from_object_url($objectId);
        if (is_string($guess) && $guess !== '') {
            $origActor = rtrim($guess, '/');
        }
    }
    // Never attribute the boosted post to the booster when we still lack the original
    if ($origActor !== '' && $boosterId !== '' && $origActor === $boosterId && $innerEvent === null) {
        $guess = function_exists('ap_masto_actor_url_from_object_url')
            ? ap_masto_actor_url_from_object_url($objectId)
            : null;
        $origActor = (is_string($guess) && $guess !== '') ? rtrim($guess, '/') : '';
    }

    $boosterName = $boosterId !== '' ? actor_display_name($boosterId) : 'someone';
    $boosterHandle = $boosterId !== '' ? actor_handle($boosterId) : '';
    $boosterProfile = $boosterId !== ''
        ? ('?view=remote_profile&actor=' . rawurlencode($boosterId) . '&from=' . rawurlencode($returnView))
        : '';
    $origName = $origActor !== '' ? actor_display_name($origActor) : 'unknown';
    $origHandle = $origActor !== '' ? actor_handle($origActor) : '';
    $origProfile = $origActor !== ''
        ? ('?view=remote_profile&actor=' . rawurlencode($origActor) . '&from=' . rawurlencode($returnView))
        : '';
    $summaryRaw = '';
    if (is_array($innerEvent) && trim((string) ($innerEvent['summary'] ?? '')) !== '') {
        $summaryRaw = html_entity_decode((string) $innerEvent['summary'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    } elseif (trim((string) ($e['summary'] ?? '')) !== '') {
        $summaryRaw = html_entity_decode((string) $e['summary'], ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
    if ($summaryRaw !== '' && function_exists('ap_masto_clean_mention_text')) {
        $summaryRaw = ap_masto_clean_mention_text($summaryRaw);
    }
    $mediaRow = is_array($innerEvent) ? ($innerEvent['media_urls'] ?? null) : ($e['media_urls'] ?? null);
    $mediaUrls = mention_media_urls($mediaRow);
    $summaryRaw = admin_media_placeholder_summary($summaryRaw, $mediaUrls);
    $eventId = (int) ($e['id'] ?? 0);
    $statusId = $eventId > 0
        ? ap_masto_event_status_id($eventId, $created !== '' ? $created : null)
        : '';
    $alreadyFollowing = $origActor !== '' && (
        !empty($followingIds[$origActor]) || !empty($followingIds[rtrim($origActor, '/')])
    );
    $fav = $statusId !== '' && ap_masto_status_is_favourited($statusId);
    $bm = $statusId !== '' && ap_masto_status_is_bookmarked($statusId);
    $boosted = $statusId !== '' && ap_masto_status_is_reblogged($statusId);
    $replyObjectId = $objectId;
    $canReply = $objectId !== '' && str_starts_with($objectId, 'https://');
    $needsHydrate = $summaryRaw === '' && $objectId !== '' && $innerEvent === null;
    $hydrateAttrs = '';
    if ($needsHydrate && $eventId > 0) {
        $hydrateAttrs = ' data-boost-hydrate="1"'
            . ' data-event-id="' . (int) $eventId . '"'
            . ' data-object-id="' . h($objectId) . '"'
            . ' data-return-view="' . h($returnView) . '"';
    }
    ?>
          <article class="tweet tweet-boost"<?= $hydrateAttrs ?>>
            <div class="meta" style="margin-bottom:.45rem;color:var(--primary)">
              <i class="ph ph-repeat" aria-hidden="true"></i>
              <?php if ($boosterProfile !== ''): ?>
                <a href="<?= h($boosterProfile) ?>" style="color:inherit;text-decoration:none"><?= h($boosterName) ?></a>
              <?php else: ?>
                <?= h($boosterName) ?>
              <?php endif; ?>
              boosted
              <?php if ($boosterHandle !== '' && $boosterHandle !== $boosterName): ?>
                <span style="opacity:.75"><?= h($boosterHandle) ?></span>
              <?php endif; ?>
              · <?= h(relative_time($created)) ?>
              <?php if ($fromFollowedTag): ?><span class="tag" style="margin-left:.35rem">followed tag</span><?php endif; ?>
            </div>
            <div class="tweet-hd">
              <?php if ($origProfile !== ''): ?>
                <a href="<?= h($origProfile) ?>" style="text-decoration:none"><?= admin_avatar_img($origActor !== '' ? $origActor : null) ?></a>
              <?php else: ?>
                <?= admin_avatar_img($origActor !== '' ? $origActor : null) ?>
              <?php endif; ?>
              <div class="tweet-hd-main">
                <div>
                  <?php if ($origProfile !== ''): ?>
                    <a class="who" href="<?= h($origProfile) ?>" style="color:inherit;text-decoration:none"><?= actor_display_name_html($origActor !== '' ? $origActor : null) ?></a>
                    <?php if ($origHandle !== '' && $origHandle !== $origName): ?>
                      <a class="meta" href="<?= h($origProfile) ?>" style="color:var(--muted);text-decoration:none"> <?= h($origHandle) ?></a>
                    <?php endif; ?>
                  <?php else: ?>
                    <span class="who"><?= actor_display_name_html($origActor !== '' ? $origActor : null) ?></span>
                    <?php if ($origHandle !== '' && $origHandle !== $origName): ?>
                      <span class="meta"> <?= h($origHandle) ?></span>
                    <?php endif; ?>
                  <?php endif; ?>
                </div>
              </div>
            </div>
            <?php
              $boostInner = '';
              if ($summaryRaw !== '') {
                  $boostMentions = [];
                  if (function_exists('ap_masto_content_with_mentions')) {
                      $boostMentions = ap_masto_content_with_mentions(
                          $summaryRaw,
                          $origActor !== '' ? [$origActor] : []
                      )['mentions'] ?? [];
                  }
                  $boostInner .= '<div class="body feed-body">'
                      . admin_linkify_body_html($summaryRaw, $returnView, $boostMentions) . '</div>';
              } elseif ($objectId !== '' && $mediaUrls === []) {
                  $boostInner .= '<div class="meta boost-hydrate-pending" style="margin-top:.35rem">'
                      . '<span class="boost-hydrate-status">Loading boosted post…</span>'
                      . ' · <a href="' . h(admin_remote_object_href($objectId)) . '" target="_blank" rel="noopener noreferrer">Open on remote</a>'
                      . '</div>';
              } else {
                  $boostInner .= '<div class="meta">(boost)</div>';
              }
              $boostMedia = $mediaUrls;
              if ($boostMedia) {
                  $boostInner .= admin_media_row_html($boostMedia);
              }
              $boostSpoiler = '';
              $boostSensitive = false;
              if (is_array($innerEvent)) {
                  $boostSpoiler = trim((string) ($innerEvent['spoiler_text'] ?? ''));
                  $boostSensitive = !empty($innerEvent['sensitive']) || $boostSpoiler !== '';
              } else {
                  $boostSpoiler = trim((string) ($e['spoiler_text'] ?? ''));
                  $boostSensitive = !empty($e['sensitive']) || $boostSpoiler !== '';
              }
              echo admin_cw_gate_html($boostSpoiler, $boostSensitive, $boostInner);
            ?>
            <div class="tweet-actions">
              <?php if ($objectId !== ''): ?>
                <a class="btn btn-ghost" href="<?= h(admin_status_href($objectId, $returnView)) ?>" style="padding:.25rem .7rem;font-size:.8rem">Open</a>
                <a href="<?= h(admin_remote_object_href($objectId)) ?>" target="_blank" rel="noopener noreferrer" class="meta" title="Open on remote instance">Remote</a>
              <?php endif; ?>
              <?php if ($origActor !== '' && !$alreadyFollowing && !vaak_is_own_url($origActor)): ?>
                <form method="post" action="?view=following" style="display:inline">
                  <input type="hidden" name="action" value="follow_remote">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="actor_id" value="<?= h($origActor) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Follow</button>
                </form>
              <?php endif; ?>
              <?php if ($canReply): ?>
                <a class="btn btn-ghost" href="?view=compose&amp;reply_to=<?= urlencode($replyObjectId) ?>&amp;from=<?= urlencode($returnView) ?>" style="padding:.25rem .7rem;font-size:.8rem">Reply</a>
              <?php endif; ?>
              <?php if ($statusId !== '' && $objectId !== ''): ?>
                <form method="post" action="?view=<?= h($returnView) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $fav ? 'unfavourite_status' : 'favourite_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($statusId) ?>">
                  <input type="hidden" name="object_id" value="<?= h($objectId) ?>">
                  <input type="hidden" name="target_actor" value="<?= h($origActor) ?>">
                  <button class="icon-btn<?= $fav ? ' on' : '' ?>" type="submit" title="<?= $fav ? 'Unlike' : 'Like' ?>" aria-label="<?= $fav ? 'Unlike' : 'Like' ?>"><i class="ph<?= $fav ? '-fill' : '' ?> ph-heart" aria-hidden="true"></i></button>
                </form>
                <form method="post" action="?view=<?= h($returnView) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $boosted ? 'unreblog_status' : 'reblog_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($statusId) ?>">
                  <input type="hidden" name="object_id" value="<?= h($objectId) ?>">
                  <input type="hidden" name="target_actor" value="<?= h($origActor) ?>">
                  <button class="icon-btn<?= $boosted ? ' on' : '' ?>" type="submit" title="<?= $boosted ? 'Undo boost' : 'Boost' ?>" aria-label="<?= $boosted ? 'Undo boost' : 'Boost' ?>"><i class="ph ph-repeat" aria-hidden="true"></i></button>
                </form>
                <form method="post" action="?view=<?= h($returnView) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $bm ? 'unbookmark_status' : 'bookmark_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($statusId) ?>">
                  <input type="hidden" name="object_id" value="<?= h($objectId) ?>">
                  <button class="icon-btn<?= $bm ? ' on' : '' ?>" type="submit" title="<?= $bm ? 'Bookmark folders' : 'Bookmark' ?>" aria-label="<?= $bm ? 'Bookmark folders' : 'Bookmark' ?>" data-bm-picker="<?= $bm ? '1' : '0' ?>"><i class="ph<?= $bm ? '-fill' : '' ?> ph-bookmark-simple" aria-hidden="true"></i></button>
                </form>
              <?php endif; ?>
              <?= block_quick_actions($origActor, short_host($origActor), $returnView, admin_owner_user_id(), !empty($GLOBALS['vaak_is_admin']), $returnView, $objectId) ?>
            </div>
          </article>
    <?php
}

/**
 * Render one of our boosts as a Mastodon-style wrapper card.
 *
 * @param array<string,mixed> $rb masto_reblogs row
 * @param array<string,bool> $followingIds
 */
function admin_render_boost_card(array $rb, array $followingIds, string $returnView): void
{
    $objectId = (string) ($rb['object_id'] ?? '');
    $created = (string) ($rb['created_at'] ?? '');
    $statusId = (string) ($rb['status_id'] ?? '');
    $boostId = (string) ($rb['boost_status_id'] ?? '');
    $targetActor = (string) ($rb['target_actor'] ?? '');
    $boosterActor = rtrim((string) ($rb['owner_actor_id'] ?? ''), '/');
    $sessionActor = rtrim(vaak_actor_id(), '/');
    if ($boosterActor !== '' && $boosterActor === $sessionActor) {
        $boostWho = 'You boosted';
    } elseif ($boosterActor !== '') {
        $boostWho = actor_display_name($boosterActor) . ' boosted';
    } else {
        $boostWho = 'Boosted';
    }
    $innerSummary = '';
    $innerHost = '';
    $innerHandle = $targetActor !== '' ? actor_display_name($targetActor) : 'unknown';
    $innerAcct = $targetActor !== '' ? actor_handle($targetActor) : '';
    $innerEvent = null;
    if ($objectId !== '' && function_exists('ap_event_by_object_id')) {
        $innerEvent = ap_event_by_object_id($objectId);
    }
    // Local boosts can point at a remote object that was only recorded as a
    // thin interaction stub. Hydrate at most one object when the async
    // partial endpoint asks us to, keeping normal timeline renders cheap.
    $innerType = is_array($innerEvent) ? strtolower((string) ($innerEvent['type'] ?? '')) : '';
    $innerSummaryRaw = is_array($innerEvent) ? trim((string) ($innerEvent['summary'] ?? '')) : '';
    if ($objectId !== ''
        && str_starts_with($objectId, 'https://')
        && (!in_array($innerType, ['create', 'update'], true) || $innerSummaryRaw === '')
        && function_exists('ap_masto_ensure_remote_note_event')) {
        $fetchBudget = &$GLOBALS['admin_boost_fetch_budget'];
        if (!is_int($fetchBudget)) {
            $fetchBudget = 0;
        }
        if ($fetchBudget > 0) {
            $fetchBudget--;
            $fetched = ap_masto_ensure_remote_note_event($objectId);
            if (is_array($fetched)) {
                $innerEvent = $fetched;
            }
        }
    }
    if (is_array($innerEvent)) {
        $innerSummary = trim(html_entity_decode((string) ($innerEvent['summary'] ?? ''), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        $innerHost = (string) ($innerEvent['host'] ?? '');
        $aid = (string) ($innerEvent['actor_id'] ?? '');
        if ($aid !== '') {
            $innerHandle = actor_display_name($aid);
            $innerAcct = actor_handle($aid);
            $targetActor = $aid !== '' ? $aid : $targetActor;
        }
    }
    $innerMedia = is_array($innerEvent) ? mention_media_urls($innerEvent['media_urls'] ?? null) : [];
    $innerSummary = admin_media_placeholder_summary($innerSummary, $innerMedia);
    if ($targetActor !== '' && function_exists('ap_row_is_hidden')
        && ap_row_is_hidden($targetActor, null, admin_owner_user_id())) {
        return;
    }
    $alreadyFollowing = $targetActor !== '' && (
        !empty($followingIds[$targetActor]) || !empty($followingIds[rtrim($targetActor, '/')])
    );
    $fav = $statusId !== '' && ap_masto_status_is_favourited($statusId);
    $bm = $statusId !== '' && ap_masto_status_is_bookmarked($statusId);
    $boosted = true;
    ?>
          <article class="tweet tweet-boost"<?= ($innerSummary === '' && $objectId !== '' && str_starts_with($objectId, 'https://')) ? ' data-boost-hydrate-local="1" data-object-id="' . h($objectId) . '" data-return-view="' . h($returnView) . '"' : '' ?>>
            <div class="meta" style="margin-bottom:.45rem;color:var(--primary)"><i class="ph ph-repeat" aria-hidden="true"></i> <?= h($boostWho) ?> · <?= h(relative_time($created)) ?></div>
            <div class="tweet-hd">
              <?= admin_avatar_img($targetActor !== '' ? $targetActor : null) ?>
              <div class="tweet-hd-main">
                <div>
                  <span class="who"><?= admin_emoji_html($innerHandle, $targetActor !== '' ? $targetActor : null) ?></span>
                  <?php if ($innerAcct !== '' && $innerAcct !== $innerHandle): ?>
                    <span class="meta"> <?= h($innerAcct) ?></span>
                  <?php endif; ?>
                  <?php if ($innerHost !== ''): ?><span class="meta"> · <?= h($innerHost) ?></span><?php endif; ?>
                </div>
              </div>
            </div>
            <?php
              $boostInner = '';
              if ($innerSummary !== '') {
                  $boostInner .= '<div class="body feed-body">' . admin_linkify_body_html($innerSummary, $returnView) . '</div>';
              } else {
                  $pending = $objectId !== '' && str_starts_with($objectId, 'https://');
                  $boostInner .= '<div class="meta boost-hydrate-status" style="margin-top:.35rem">'
                      . h($pending ? 'Loading boosted post…' : 'Boosted post unavailable.') . '</div>';
              }
              if ($innerMedia) {
                  $boostInner .= admin_media_row_html($innerMedia);
              }
              echo admin_tweet_content_wrap($boostInner);
            ?>
            <div class="tweet-actions">
              <?php if ($objectId !== ''): ?>
                <a class="btn btn-ghost" href="<?= h(admin_status_href($objectId, $returnView)) ?>" style="padding:.25rem .7rem;font-size:.8rem">Open</a>
                <a href="<?= h(admin_remote_object_href($objectId)) ?>" target="_blank" rel="noopener noreferrer" class="meta" title="Open on remote instance">Remote</a>
              <?php endif; ?>
              <?php if ($targetActor !== '' && !$alreadyFollowing): ?>
                <form method="post" action="?view=following" style="display:inline">
                  <input type="hidden" name="action" value="follow_remote">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="actor_id" value="<?= h($targetActor) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Follow</button>
                </form>
              <?php endif; ?>
              <?php if ($statusId !== '' && $objectId !== ''): ?>
                <form method="post" action="?view=<?= h($returnView) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $fav ? 'unfavourite_status' : 'favourite_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($statusId) ?>">
                  <input type="hidden" name="object_id" value="<?= h($objectId) ?>">
                  <input type="hidden" name="target_actor" value="<?= h($targetActor) ?>">
                  <button class="icon-btn<?= $fav ? ' on' : '' ?>" type="submit" title="<?= $fav ? 'Unlike' : 'Like' ?>" aria-label="<?= $fav ? 'Unlike' : 'Like' ?>"><i class="ph<?= $fav ? '-fill' : '' ?> ph-heart" aria-hidden="true"></i></button>
                </form>
                <form method="post" action="?view=<?= h($returnView) ?>" style="display:inline">
                  <input type="hidden" name="action" value="unreblog_status">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($statusId) ?>">
                  <input type="hidden" name="object_id" value="<?= h($objectId) ?>">
                  <input type="hidden" name="target_actor" value="<?= h($targetActor) ?>">
                  <button class="icon-btn on" type="submit" title="Undo boost" aria-label="Undo boost"><i class="ph ph-repeat" aria-hidden="true"></i></button>
                </form>
                <form method="post" action="?view=<?= h($returnView) ?>" style="display:inline">
                  <input type="hidden" name="action" value="<?= $bm ? 'unbookmark_status' : 'bookmark_status' ?>">
                  <input type="hidden" name="return_view" value="<?= h($returnView) ?>">
                  <input type="hidden" name="status_id" value="<?= h($statusId) ?>">
                  <input type="hidden" name="object_id" value="<?= h($objectId) ?>">
                  <button class="icon-btn<?= $bm ? ' on' : '' ?>" type="submit" title="<?= $bm ? 'Bookmark folders' : 'Bookmark' ?>" aria-label="<?= $bm ? 'Bookmark folders' : 'Bookmark' ?>" data-bm-picker="<?= $bm ? '1' : '0' ?>"><i class="ph<?= $bm ? '-fill' : '' ?> ph-bookmark-simple" aria-hidden="true"></i></button>
                </form>
              <?php endif; ?>
              <?= block_quick_actions($targetActor, short_host($targetActor), $returnView, admin_owner_user_id(), !empty($GLOBALS['vaak_is_admin']), $returnView, $objectId) ?>
            </div>
          </article>
    <?php
}

/**
 * Render a local outbox note as a timeline card (for Home/Federated mix-in).
 *
 * @param array<string,mixed> $n outbox_notes row
 */
function admin_render_outbox_card(array $n, string $returnView): void
{
    $actor = vaak_actor_id();
    $actorKey = vaak_actor_key();
    $noteId = (string) ($n['id'] ?? '');
    // Prefer actor from note id so peer local profiles render correctly
    if ($noteId !== '' && preg_match('#^(https://mkultra\.monster/users/([A-Za-z0-9_]+))/#', $noteId, $nm)) {
        $actor = $nm[1];
        $actorKey = strtolower($nm[2]);
    }
    $isOwnNote = function_exists('vaak_is_own_url') ? vaak_is_own_url($noteId !== '' ? $noteId : $actor) : ($actor === vaak_actor_id());
    $content = (string) ($n['content'] ?? '');
    $published = (string) ($n['published'] ?? '');
    $replyTo = (string) ($n['in_reply_to'] ?? '');
    $mediaHtml = '';
    $quoteHtml = '';
    $raw = (string) ($n['raw_create_json'] ?? '');
    $obj = null;
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        $obj = is_array($decoded) ? ($decoded['object'] ?? $decoded) : null;
        $atts = is_array($obj) ? ($obj['attachment'] ?? null) : null;
        if (is_array($atts)) {
            if (isset($atts['type'])) {
                $atts = [$atts];
            }
            $mediaItems = [];
            foreach ($atts as $att) {
                if (!is_array($att)) {
                    continue;
                }
                $url = '';
                if (isset($att['url']) && is_string($att['url'])) {
                    $url = $att['url'];
                } elseif (isset($att['url']['href']) && is_string($att['url']['href'])) {
                    $url = $att['url']['href'];
                }
                if ($url === '' || !str_starts_with($url, 'https://')) {
                    continue;
                }
                $mt = strtolower((string) ($att['mediaType'] ?? ''));
                $type = (string) ($att['type'] ?? '');
                if (
                    str_starts_with($mt, 'image/') || $type === 'Image'
                    || str_starts_with($mt, 'video/') || $type === 'Video'
                    || str_starts_with($mt, 'audio/') || $type === 'Audio'
                    || admin_media_is_video($url, $mt)
                ) {
                    $mediaItems[] = ['url' => $url, 'mediaType' => $mt !== '' ? $mt : null];
                }
            }
            if ($mediaItems) {
                $mediaHtml = admin_media_row_html($mediaItems);
            }
        }
        // Quote preview for our own quote posts
        if (is_array($obj) && function_exists('ap_quote_target_pack')) {
            $pack = ap_quote_target_pack($obj);
            $qUrl = $pack['url'] ?? null;
            $qDoc = is_array($pack['embedded'] ?? null) ? $pack['embedded'] : null;
            $tomb = !empty($pack['tombstone']);
            $plain = '';

            // Prefer already-enriched compose event summary (no live remote fetch on page render)
            if ($noteId !== '') {
                try {
                    $est = ap_db()->prepare(
                        "SELECT summary FROM events
                         WHERE object_id = ? AND action_taken = 'compose'
                         ORDER BY id DESC LIMIT 1"
                    );
                    $est->execute([$noteId]);
                    $esum = (string) ($est->fetchColumn() ?: '');
                    if ($esum !== '' && str_contains($esum, '↪ QT')) {
                        $echunks = preg_split('/\n\n↪ QT/u', $esum, 2);
                        if (is_array($echunks) && count($echunks) === 2) {
                            $qline = trim($echunks[1]);
                            // " @acct@host: text" or ": text"
                            if (preg_match('/^(?:\s*@\S+:\s*|\s*:\s*)(.*)$/us', $qline, $qm)) {
                                $plain = trim($qm[1]);
                            } else {
                                $plain = ltrim($qline, ": \t");
                            }
                            if (str_contains($plain, '(quoted post unavailable)')) {
                                $tomb = true;
                                $plain = '';
                            }
                        }
                    }
                } catch (Throwable $e) {
                    // fall through to fetch
                }
            }

            if ($plain === '' && !$tomb && is_string($qUrl) && $qUrl !== '') {
                if (vaak_is_own_url($qUrl) && str_contains($qUrl, '/notes/')
                    && function_exists('ap_local_note_as2_doc')) {
                    $qDoc = ap_local_note_as2_doc($qUrl) ?? $qDoc;
                }
                if ($qDoc === null && function_exists('ap_event_by_object_id')) {
                    $qCands = function_exists('ap_object_url_lookup_candidates')
                        ? ap_object_url_lookup_candidates($qUrl)
                        : [rtrim($qUrl, '/')];
                    if ($qCands === []) {
                        $qCands = [rtrim($qUrl, '/')];
                    }
                    foreach ($qCands as $qCand) {
                        $ev = ap_event_by_object_id($qCand);
                        if (!is_array($ev) || empty($ev['summary'])) {
                            continue;
                        }
                        $plain = trim((string) $ev['summary']);
                        // If the cached summary is itself a QT block, take the commentary portion
                        if (str_contains($plain, '↪ QT')) {
                            $parts = preg_split('/\n\n↪ QT/u', $plain, 2);
                            $plain = is_array($parts) && isset($parts[0]) ? trim($parts[0]) : $plain;
                        }
                        if ($plain !== '') {
                            break;
                        }
                    }
                }
                // Never sync-fetch quoted remotes on timeline paint — that stalls
                // Federated tab swaps for seconds. Local/event cache above is enough;
                // remaining quotes render as a link stub (same idea as boost hydrate).
                if ($plain === '' && $qDoc === null) {
                    $quoteFetchBudget = &$GLOBALS['admin_quote_fetch_budget'];
                    if (!isset($quoteFetchBudget) || !is_int($quoteFetchBudget)) {
                        $quoteFetchBudget = 0;
                    }
                    if ($quoteFetchBudget > 0 && function_exists('ap_fetch_as2_object')) {
                        $quoteFetchBudget--;
                        $qDoc = ap_fetch_as2_object($qUrl);
                    }
                }
            }
            if ($plain === '' && is_array($qDoc) && function_exists('ap_unwrap_as2_object')) {
                $qDoc = ap_unwrap_as2_object($qDoc);
                $tomb = $tomb || (function_exists('ap_quote_is_tombstone') && ap_quote_is_tombstone($qDoc));
                if (!$tomb && !empty($qDoc['content']) && is_string($qDoc['content'])) {
                    $plain = admin_html_to_plain($qDoc['content']);
                }
            }
            if ($plain !== '' && function_exists('ap_text_looks_like_as2_json') && ap_text_looks_like_as2_json($plain)) {
                $plain = '';
            }
            if ($qUrl || $tomb || $plain !== '') {
                $quoteHtml = '<div class="quote-block"><span class="qt-label">Quoted</span><br>';
                if ($tomb || ($plain === '' && !$qUrl)) {
                    $quoteHtml .= '(quoted post unavailable)';
                } elseif ($plain !== '') {
                    if (mb_strlen($plain) > 280) {
                        $plain = mb_substr($plain, 0, 277) . '…';
                    }
                    $quoteHtml .= h($plain);
                } else {
                    $quoteHtml .= '<span class="mono">' . h((string) $qUrl) . '</span>';
                }
                if (is_string($qUrl) && $qUrl !== '') {
                    $quoteHtml .= '<div class="meta" style="margin-top:.35rem"><a href="' . h($qUrl) . '" target="_blank" rel="noopener noreferrer">Open original</a></div>';
                }
                $quoteHtml .= '</div>';
            }
        }
    }
    $bodyPlain = admin_html_to_plain($content);
    $ownSpoiler = '';
    $ownSensitive = false;
    if (is_array($obj)) {
        if (!empty($obj['summary']) && is_string($obj['summary'])) {
            $ownSpoiler = trim(html_entity_decode(strip_tags($obj['summary']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
        }
        $ownSensitive = !empty($obj['sensitive']) || $ownSpoiler !== '';
    }
    // Prefer masto_statuses.content_text for display + CW (blank lines survive; HTML→plain can thin them)
    $srow = $noteId !== '' ? admin_masto_row_for_note($noteId) : null;
    if (is_array($srow)) {
        $dbBody = (string) ($srow['content_text'] ?? '');
        if ($dbBody !== '' && !in_array($dbBody, ['(quote)', '(media)', '(poll)'], true)) {
            $bodyPlain = $dbBody;
        }
        $ownSpoiler = trim((string) ($srow['spoiler_text'] ?? $ownSpoiler));
        $ownSensitive = !empty($srow['sensitive']) || $ownSpoiler !== '';
    }
    ?>
          <article class="tweet tweet-own"<?= $noteId !== '' ? ' id="note-' . h(md5($noteId)) . '" data-note-id="' . h($noteId) . '"' : '' ?>>
            <div class="tweet-hd">
              <?= admin_avatar_img($actor) ?>
              <div class="tweet-hd-main">
                <?php
                  $ownProfile = function_exists('ap_profile_get') ? ap_profile_get($actorKey) : [];
                  $ownName = trim((string) ($ownProfile['name'] ?? ''));
                  if ($ownName === '') {
                      $ownName = $actorKey;
                  }
                  $ownHandle = '@' . $actorKey . '@mkultra.monster';
                  $ownType = (!empty($n['kind']) && $n['kind'] === 'quote') ? 'Quote' : 'Create';
                  $ownVisRaw = (string) ($n['visibility'] ?? 'public');
                  if (($ownVisRaw === '' || $ownVisRaw === 'public') && is_array($srow)) {
                      $vrow = (string) ($srow['visibility'] ?? '');
                      if ($vrow !== '') {
                          $ownVisRaw = $vrow;
                      }
                  }
                  $ownVisMeta = admin_visibility_meta($ownVisRaw);
                  $ownVisLabel = $ownVisMeta['label'];
                  $ownPinned = false;
                  if ($isOwnNote) {
                      if (is_array($srow) && function_exists('ap_masto_status_is_pinned')) {
                          $ownPinned = ap_masto_status_is_pinned((int) ($srow['local_id'] ?? 0));
                      } elseif ($noteId !== '' && function_exists('ap_masto_status_by_note_id') && function_exists('ap_masto_status_is_pinned')) {
                          $pinRow = ap_masto_status_by_note_id($noteId);
                          if (is_array($pinRow)) {
                              $ownPinned = ap_masto_status_is_pinned((int) ($pinRow['local_id'] ?? 0));
                          }
                      }
                  }
                ?>
                <div>
                  <span class="who"><?= admin_emoji_html($ownName, $actor) ?></span>
                  <span class="meta"> <?= h($ownHandle) ?></span>
                  <span class="meta"> · <?= h($ownType) ?> · <?= h(relative_time($published)) ?></span>
                  <?php if (is_array($srow) && !empty($srow['edited_at'])): ?>
                    <span class="meta" title="<?= h((string) $srow['edited_at']) ?>"> · edited</span>
                  <?php endif; ?>
                  <?php if ($ownPinned): ?>
                    <span class="tag" style="margin-left:.35rem" title="Pinned on profile">pinned</span>
                  <?php endif; ?>
                  <?php if ($ownVisMeta['key'] !== 'public'): ?>
                    <span class="tag" style="margin-left:.35rem" title="Audience"><?= h($ownVisLabel) ?></span>
                  <?php endif; ?>
                  <?= admin_anti_ai_tag_html($bodyPlain, $actor !== '' ? $actor : null) ?>
                </div>
                <div class="meta">mkultra.monster<?= $ownVisMeta['key'] !== 'public' ? ' · ' . h($ownVisLabel) : '' ?></div>
              </div>
            </div>
            <?php if ($replyTo !== '' && str_starts_with($replyTo, 'https://')): ?>
              <div class="meta" style="margin:.25rem 0 .35rem">
                ↩ reply to
                <a href="<?= h(admin_status_href($replyTo, $returnView)) ?>">parent post</a>
              </div>
            <?php elseif ($replyTo !== ''): ?>
              <div class="meta" style="margin:.25rem 0 .35rem">↩ parent post</div>
            <?php endif; ?>
            <?php
              $ownInner = '';
              if ($bodyPlain !== '' && $bodyPlain !== '(quote)') {
                  $ownInner .= '<div class="body feed-body" style="white-space:pre-wrap">'
                      . admin_linkify_body_html($bodyPlain, $returnView) . '</div>';
              }
              $ownInner .= $quoteHtml . $mediaHtml;
              $linkCardHtml = '';
              if ($mediaHtml === '' && $bodyPlain !== '' && function_exists('ap_link_preview_for_url') && function_exists('ap_link_preview_extract_url')) {
                  $cardUrl = ap_link_preview_extract_url($bodyPlain);
                  if ($cardUrl !== null) {
                      // Cache-only — never sync-fetch OG cards on timeline render
                      $linkCardHtml = ap_link_preview_html(ap_link_preview_for_url($cardUrl, false));
                  }
              }
              $ownInner .= $linkCardHtml;
              echo admin_cw_gate_html($ownSpoiler, $ownSensitive, $ownInner);
            ?>
            <div class="tweet-actions">
              <?php if ($noteId !== ''): ?>
                <a class="btn btn-ghost" href="<?= h(admin_status_href($noteId, $returnView)) ?>" style="padding:.25rem .7rem;font-size:.8rem">Open</a>
                <?php if ($isOwnNote): ?>
                  <?php
                    $editPlain = $bodyPlain !== '(quote)' ? $bodyPlain : '';
                    $editSpoiler = $ownSpoiler;
                    $editSensitive = $ownSensitive;
                    if (is_array($srow)) {
                        $editPlain = (string) ($srow['content_text'] ?? $editPlain);
                        $editSpoiler = trim((string) ($srow['spoiler_text'] ?? $editSpoiler));
                        $editSensitive = !empty($srow['sensitive']) || $editSpoiler !== '';
                    }
                  ?>
                  <?= admin_edit_post_button($noteId, $editPlain, $editSpoiler, $editSensitive, $returnView) ?>
                  <?= admin_pin_post_button($noteId, $returnView) ?>
                <?php endif; ?>
            <a class="icon-btn" href="?view=<?= h($returnView) ?>&amp;compose=1&amp;reply_to=<?= urlencode($noteId) ?>" title="Reply / continue thread" aria-label="Reply"><i class="ph ph-arrow-bend-up-left" aria-hidden="true"></i></a>
                <a href="<?= h($noteId) ?>" target="_blank" rel="noopener noreferrer" class="meta" title="Open note URL (<?= h($ownVisLabel ?? 'Public') ?>)">Note</a>
              <?php endif; ?>
            </div>
          </article>
    <?php
}

/**
 * Stable VAAK bookmark key for a Bluesky AT-URI (local twin snowflake when mapped).
 *
 * @return array{status_id:string,object_id:string}
 */
function admin_bsky_bookmark_keys(string $atUri, int $ownerUserId = 0): array
{
    $atUri = trim($atUri);
    $objectId = $atUri;
    $statusId = '';
    if ($atUri !== '' && function_exists('ap_bsky_local_note_id_for_at_uri')) {
        $noteId = ap_bsky_local_note_id_for_at_uri($atUri, $ownerUserId);
        if (is_string($noteId) && $noteId !== '' && function_exists('ap_masto_status_by_note_id')) {
            $st = ap_masto_status_by_note_id($noteId);
            if (is_array($st) && !empty($st['local_id'])) {
                $statusId = function_exists('ap_masto_snowflake_id')
                    ? (string) ap_masto_snowflake_id((string) ($st['published'] ?? gmdate('c')), (int) $st['local_id'], 0)
                    : (string) $st['local_id'];
                $objectId = $noteId;
            }
        }
    }
    if ($statusId === '' && $atUri !== '') {
        $statusId = 'bsky:' . substr(hash('sha256', $atUri), 0, 32);
        if (function_exists('ap_bsky_https_url_from_at_uri')) {
            $https = ap_bsky_https_url_from_at_uri($atUri, null);
            if (is_string($https) && str_starts_with($https, 'https://')) {
                $objectId = $https;
            }
        }
    }
    return ['status_id' => $statusId, 'object_id' => $objectId];
}

/**
 * Render one Bluesky feed item (Bluesky tab or Home mix).
 *
 * @param array<string,mixed> $item
 * @param string $context 'bluesky' (tab) or 'home' (mixed Home feed — federated card chrome)
 */
function admin_render_bsky_feed_item(array $item, string $feedKey = 'following', string $context = 'bluesky'): void
{
    $post = is_array($item['post'] ?? null) ? $item['post'] : null;
    if ($post === null) {
        return;
    }
    $author = is_array($post['author'] ?? null) ? $post['author'] : [];
    $display = trim((string) ($author['displayName'] ?? ''));
    $handle = (string) ($author['handle'] ?? '');
    if ($display === '') {
        $display = $handle !== '' ? $handle : 'Bluesky user';
    }
    $av = (string) ($author['avatar'] ?? '');
    $text = function_exists('ap_bsky_post_text') ? ap_bsky_post_text($post) : '';
    $imgs = function_exists('ap_bsky_post_image_urls') ? ap_bsky_post_image_urls($post) : [];
    $external = function_exists('ap_bsky_post_external') ? ap_bsky_post_external($post) : null;
    $openUrl = function_exists('ap_bsky_post_url') ? ap_bsky_post_url($post) : 'https://bsky.app/';
    $created = (string) ($post['indexedAt'] ?? ($post['record']['createdAt'] ?? ''));
    $uri = (string) ($post['uri'] ?? '');
    $cid = (string) ($post['cid'] ?? '');
    $viewer = is_array($post['viewer'] ?? null) ? $post['viewer'] : [];
    $likeRecord = is_string($viewer['like'] ?? null) ? (string) $viewer['like'] : '';
    $repostRecord = is_string($viewer['repost'] ?? null) ? (string) $viewer['repost'] : '';
    $liked = $likeRecord !== '';
    $reposted = $repostRecord !== '';
    $bookmarked = !empty($viewer['bookmarked']) || !empty($viewer['bookmark']);
    $reason = is_array($item['reason'] ?? null) ? $item['reason'] : null;
    $reasonLabel = '';
    $isRepost = false;
    if (is_array($reason)) {
        $rt = (string) ($reason['$type'] ?? '');
        if (str_contains($rt, 'reasonRepost')) {
            $isRepost = true;
            $by = is_array($reason['by'] ?? null) ? $reason['by'] : [];
            $rh = (string) ($by['handle'] ?? $by['displayName'] ?? 'someone');
            $reasonLabel = 'Reposted by @' . $rh;
        }
    }
    $feedSource = (string) ($item['_vaak_feed_source'] ?? '');
    $fediId = '';
    $record = is_array($post['record'] ?? null) ? $post['record'] : [];
    if (!empty($record['fediverseId']) && is_string($record['fediverseId'])) {
        $fediId = trim($record['fediverseId']);
    }
    if ($fediId === '' && function_exists('ap_bsky_post_link_by_uri') && $uri !== '') {
        $link = ap_bsky_post_link_by_uri($uri);
        if (is_array($link) && !empty($link['fediverse_id'])) {
            $fediId = (string) $link['fediverse_id'];
        }
    }
    $replyParent = function_exists('ap_bsky_reply_parent_preview') ? ap_bsky_reply_parent_preview($item) : null;
    $quote = function_exists('ap_bsky_quote_preview') ? ap_bsky_quote_preview($post) : null;
    $isQuoteBoost = $quote !== null && $text === '' && !$isRepost;
    // Prefer DID-form bsky.app URLs so compose → crosspost can resolve at:// without
    // a handle lookup (handle-form https://bsky.app/profile/{handle}/post/… often failed).
    $replyTarget = $openUrl !== '' ? $openUrl : $uri;
    $authorDid = (string) ($author['did'] ?? '');
    if ($uri !== '' && preg_match('#/app\.bsky\.feed\.post/([^/\s]+)$#', $uri, $rm)
        && str_starts_with($authorDid, 'did:')) {
        $replyTarget = 'https://bsky.app/profile/' . rawurlencode($authorDid)
            . '/post/' . rawurlencode($rm[1]);
    }

    // Build nested blocks as compact strings (same as fedi cards). Template
    // indentation inside .quote-block { white-space:pre-wrap } was creating
    // large empty gaps between the header and the quoted body.
    $replyMetaHtml = '';
    if (is_array($replyParent)) {
        $parentLabel = $replyParent['handle'] !== ''
            ? '@' . $replyParent['handle']
            : (string) ($replyParent['display'] ?? 'parent post');
        $snip = trim((string) ($replyParent['text'] ?? ''));
        if ($snip !== '') {
            $snip = mb_substr($snip, 0, 120);
        }
        $parentUrl = (string) ($replyParent['url'] ?? '');
        $replyMetaHtml = '<div class="meta" style="margin:.35rem 0 .5rem">↩ reply to ';
        if ($parentUrl !== '' && $parentUrl !== 'https://bsky.app/') {
            $replyMetaHtml .= '<a href="' . h($parentUrl) . '" target="_blank" rel="noopener noreferrer">'
                . h($snip !== '' ? $snip : $parentLabel) . '</a>';
        } else {
            $replyMetaHtml .= h($snip !== '' ? $snip : $parentLabel);
        }
        $replyMetaHtml .= '</div>';
    }

    $quoteHtml = '';
    if (is_array($quote)) {
        $qlabel = $isQuoteBoost ? 'Quote' : 'Quoted';
        $qAcct = $quote['handle'] !== '' ? '@' . $quote['handle'] : (string) ($quote['display'] ?? '');
        $quoteHtml = '<div class="quote-block"><span class="qt-label">' . h($qlabel) . '</span>';
        if ($qAcct !== '') {
            $quoteHtml .= '<div class="meta" style="margin-top:.3rem">' . h($qAcct) . '</div>';
        }
        $qText = trim((string) ($quote['text'] ?? ''));
        if ($qText !== '') {
            $qTextLink = preg_replace('#(?<![\w./:@])(www\.[^\s<]+)#iu', 'https://$1', $qText) ?? $qText;
            $quoteHtml .= '<div style="margin-top:.25rem;white-space:pre-wrap">'
                . admin_linkify_body_html(mb_substr($qTextLink, 0, 400), 'bluesky')
                . '</div>';
        }
        $qUrl = (string) ($quote['url'] ?? '');
        if ($qUrl !== '' && $qUrl !== 'https://bsky.app/') {
            $quoteHtml .= '<div class="meta" style="margin-top:.35rem"><a href="'
                . h($qUrl) . '" target="_blank" rel="noopener noreferrer">Open quoted</a></div>';
        }
        $quoteHtml .= '</div>';
    }

    // Same lightbox triggers as federated cards (not raw <a target=_blank>).
    $mediaHtml = $imgs !== [] ? admin_media_row_html($imgs) : '';
    $linkCardHtml = '';
    if (is_array($external) && function_exists('ap_bsky_external_link_card_html')) {
        $linkCardHtml = ap_bsky_external_link_card_html($external);
    }
    $isHome = ($context === 'home');
    $linkView = $isHome ? 'home' : 'bluesky';
    // Stay on the current surface (Home mix vs Bluesky tab). reply_to / quote_object
    // still carry the bsky.app target so dual-publish / AT reply keep working.
    $composeView = $linkView;
    $authorDid = (string) ($author['did'] ?? '');
    $authorProfileUrl = $authorDid !== ''
        ? ('https://bsky.app/profile/' . rawurlencode($authorDid))
        : ($handle !== '' ? ('https://bsky.app/profile/' . rawurlencode($handle)) : '');
    $authorProfileHref = $authorProfileUrl !== ''
        ? ('?view=remote_profile&actor=' . rawurlencode($authorProfileUrl) . '&from=' . rawurlencode($linkView))
        : '';
    // Bluesky often truncates URLs as www.host/... without a scheme; prepend https://
    // so admin_linkify_body_html can turn them into clickable links.
    $textForLink = $text;
    if ($textForLink !== '') {
        $textForLink = preg_replace(
            '#(?<![\w./:@])(www\.[^\s<]+)#iu',
            'https://$1',
            $textForLink
        ) ?? $textForLink;
    }
    // Home: linkify as home so mentions/hashtags stay in-app like federated cards.
    $bodyHtml = $textForLink !== '' ? admin_linkify_body_html($textForLink, $linkView) : '';
    if ($quote !== null && is_array($quote) && $quoteHtml !== '') {
        // Re-linkify quote body with home context when mixed into Home.
        if ($isHome && ($quote['text'] ?? '') !== '') {
            $qlabel = $isQuoteBoost ? 'Quote' : 'Quoted';
            $qAcct = $quote['handle'] !== '' ? '@' . $quote['handle'] : (string) ($quote['display'] ?? '');
            $quoteHtml = '<div class="quote-block"><span class="qt-label">' . h($qlabel) . '</span>';
            if ($qAcct !== '') {
                $quoteHtml .= '<div class="meta" style="margin-top:.3rem">' . h($qAcct) . '</div>';
            }
            $quoteHtml .= '<div style="margin-top:.25rem;white-space:pre-wrap">'
                . admin_linkify_body_html(mb_substr((string) $quote['text'], 0, 400), $linkView)
                . '</div>';
            $qUrl = (string) ($quote['url'] ?? '');
            if ($qUrl !== '' && $qUrl !== 'https://bsky.app/') {
                $quoteHtml .= '<div class="meta" style="margin-top:.35rem"><a href="'
                    . h($qUrl) . '" target="_blank" rel="noopener noreferrer">Open quoted</a></div>';
            }
            $quoteHtml .= '</div>';
        }
    }
    ?>
    <article class="tweet tweet-bsky<?= $isRepost ? ' tweet-boost' : '' ?><?= $isHome ? ' tweet-bsky-home' : '' ?>" data-bsky-uri="<?= h($uri) ?>" data-bsky-cid="<?= h($cid) ?>">
      <?php if ($reasonLabel !== ''): ?>
        <div class="meta" style="margin:0 0 .35rem;color:var(--primary)"><i class="ph ph-repeat" aria-hidden="true"></i> <?= h($reasonLabel) ?></div>
      <?php endif; ?>
      <?php if (!$isHome && $feedSource !== ''): ?>
        <div class="meta" style="margin:0 0 .35rem">☁ From saved feed</div>
      <?php endif; ?>
      <div class="tweet-hd">
        <?php if ($av !== ''): ?>
          <?php if ($authorProfileHref !== ''): ?>
            <a href="<?= h($authorProfileHref) ?>" style="text-decoration:none">
              <img class="tweet-av" src="<?= h($av) ?>" alt="" width="40" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer">
            </a>
          <?php else: ?>
            <img class="tweet-av" src="<?= h($av) ?>" alt="" width="40" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer">
          <?php endif; ?>
        <?php endif; ?>
        <div class="tweet-hd-main">
          <div>
            <?php if ($authorProfileHref !== ''): ?>
              <a class="who" href="<?= h($authorProfileHref) ?>" style="color:inherit;text-decoration:none"><?= h($display) ?></a>
            <?php else: ?>
              <span class="who"><?= h($display) ?></span>
            <?php endif; ?>
            <?php if ($handle !== ''): ?>
              <?php if ($authorProfileHref !== ''): ?>
                <a class="meta" href="<?= h($authorProfileHref) ?>" style="color:var(--muted);text-decoration:none"> @<?= h($handle) ?></a>
              <?php else: ?>
                <span class="meta"> @<?= h($handle) ?></span>
              <?php endif; ?>
            <?php endif; ?>
            <?php if ($created !== ''): ?>
              <span class="meta"> · <?= h(relative_time($created)) ?></span>
            <?php endif; ?>
            <span class="tag" title="From Bluesky">Bluesky</span>
            <?= admin_anti_ai_tag_html($text, $authorDid !== '' ? $authorDid : null) ?>
            <?php if ($quote !== null): ?>
              <span class="tag" title="<?= $isQuoteBoost ? 'Quote post' : 'Quote with commentary' ?>">quote</span>
            <?php endif; ?>
            <?php if ($isHome && $fediId !== ''): ?>
              <span class="meta"> · <a href="<?= h(admin_status_href($fediId, 'home')) ?>">also on fedi</a></span>
            <?php endif; ?>
          </div>
        </div>
      </div>
      <?= $replyMetaHtml ?>
      <?php if ($bodyHtml !== ''): ?>
        <div class="body feed-body"><?= $bodyHtml ?></div>
      <?php endif; ?>
      <?= $quoteHtml ?>
      <?= $mediaHtml ?>
      <?= $linkCardHtml ?>
      <div class="tweet-actions">
        <a class="icon-btn" href="?view=<?= h($composeView) ?>&amp;compose=1&amp;reply_to=<?= urlencode($replyTarget) ?>&amp;feed=<?= urlencode($feedKey) ?>" title="Reply" aria-label="Reply"><i class="ph ph-arrow-bend-up-left" aria-hidden="true"></i></a>
        <a class="icon-btn" href="?view=<?= h($composeView) ?>&amp;compose=1&amp;quote_object=<?= urlencode($replyTarget) ?>&amp;feed=<?= urlencode($feedKey) ?>" title="Quote" aria-label="Quote"><i class="ph ph-quotes" aria-hidden="true"></i></a>
        <?php if ($uri !== '' && $cid !== ''): ?>
          <?php
            $bmKeys = admin_bsky_bookmark_keys($uri, (int) ($GLOBALS['vaak_owner_id'] ?? 0));
          ?>
          <button type="button" class="icon-btn bsky-action<?= $reposted ? ' on' : '' ?>" data-bsky-action="repost" data-uri="<?= h($uri) ?>" data-cid="<?= h($cid) ?>" data-record-uri="<?= h($repostRecord) ?>" title="<?= $reposted ? 'Undo boost' : 'Boost' ?>" aria-label="<?= $reposted ? 'Undo boost' : 'Boost' ?>" aria-pressed="<?= $reposted ? 'true' : 'false' ?>"><i class="ph ph-repeat" aria-hidden="true"></i></button>
          <button type="button" class="icon-btn bsky-action<?= $liked ? ' on' : '' ?>" data-bsky-action="like" data-uri="<?= h($uri) ?>" data-cid="<?= h($cid) ?>" data-record-uri="<?= h($likeRecord) ?>" title="<?= $liked ? 'Unlike' : 'Like' ?>" aria-label="<?= $liked ? 'Unlike' : 'Like' ?>" aria-pressed="<?= $liked ? 'true' : 'false' ?>"><i class="ph<?= $liked ? '-fill' : '' ?> ph-heart" aria-hidden="true"></i></button>
          <button type="button" class="icon-btn bsky-action<?= $bookmarked ? ' on' : '' ?>" data-bsky-action="bookmark" data-uri="<?= h($uri) ?>" data-cid="<?= h($cid) ?>" data-status-id="<?= h($bmKeys['status_id']) ?>" data-object-id="<?= h($bmKeys['object_id']) ?>" data-bm-picker="<?= $bookmarked ? '1' : '0' ?>" title="<?= $bookmarked ? 'Bookmark folders' : 'Bookmark' ?>" aria-label="<?= $bookmarked ? 'Bookmark folders' : 'Bookmark' ?>" aria-pressed="<?= $bookmarked ? 'true' : 'false' ?>"><i class="ph<?= $bookmarked ? '-fill' : '' ?> ph-bookmark-simple" aria-hidden="true"></i></button>
        <?php endif; ?>
        <?php if (!$isHome && $fediId !== ''): ?>
          <a class="meta" href="<?= h($fediId) ?>" style="margin-left:.25rem">AP copy</a>
        <?php endif; ?>
        <a class="meta" href="<?= h($openUrl) ?>" target="_blank" rel="noopener noreferrer" style="margin-left:.35rem">Open on Bluesky</a>
      </div>
    </article>
    <?php
}

/**
 * @param array{kind:string,row:array<string,mixed>} $item
 * @param array<string,bool> $followingIds
 */
function admin_render_timeline_item(array $item, array $followingIds, string $returnView): void
{
    $kind = (string) ($item['kind'] ?? '');
    if ($kind === 'outbox') {
        admin_render_outbox_card($item['row'], $returnView);
        return;
    }
    if ($kind === 'boost') {
        admin_render_boost_card($item['row'], $followingIds, $returnView);
        return;
    }
    if ($kind === 'bsky') {
        $row = is_array($item['row'] ?? null) ? $item['row'] : [];
        if ($row !== []) {
            admin_render_bsky_feed_item($row, 'following', $returnView === 'home' ? 'home' : 'bluesky');
        }
        return;
    }
    $fromTag = !empty($item['from_tag']) || !empty($item['row']['_from_followed_tag']);
    admin_render_event_tweet($item['row'], $followingIds, $returnView, $fromTag);
}

/**
 * Collect Mastodon status ids from a timeline slice for batch fav/boost/bookmark lookup.
 *
 * @param list<array{kind?:string,row?:array<string,mixed>}> $items
 * @return list<string>
 */
function admin_timeline_status_ids(array $items): array
{
    $ids = [];
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $kind = (string) ($item['kind'] ?? '');
        $row = is_array($item['row'] ?? null) ? $item['row'] : [];
        if ($kind === 'boost') {
            $sid = (string) ($row['status_id'] ?? '');
            if ($sid !== '') {
                $ids[] = $sid;
            }
            continue;
        }
        if ($kind === 'outbox') {
            continue;
        }
        $eventId = (int) ($row['id'] ?? 0);
        if ($eventId > 0 && function_exists('ap_masto_event_status_id')) {
            $sid = ap_masto_event_status_id(
                $eventId,
                isset($row['created_at']) ? (string) $row['created_at'] : null
            );
            if ($sid !== '') {
                $ids[] = $sid;
            }
        }
    }
    return $ids;
}

// AJAX fragment: Status Open — ancestors and/or replies (Mastodon-style progressive load)
if ($isPartial && $view === 'status' && isset($_GET['thread'])) {
    $stObject = trim((string) ($_GET['object'] ?? ''));
    $stFrom = preg_replace('/[^a-z_]/', '', (string) ($_GET['from'] ?? 'home')) ?: 'home';
    $threadPart = preg_replace('/[^a-z_]/', '', (string) ($_GET['thread'] ?? '1')) ?: '1';
    // thread=1|ancestors → parents above; thread=replies|descendants → replies below
    // thread=focus → ensure + render the source/focus post itself
    $wantFocus = ($threadPart === 'focus');
    $wantAncestors = in_array($threadPart, ['1', 'ancestors', 'both'], true);
    $wantReplies = in_array($threadPart, ['replies', 'descendants', 'both'], true);
    if (!$wantAncestors && !$wantReplies && !$wantFocus) {
        $wantAncestors = true;
    }
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if ($stObject === '' || !str_starts_with($stObject, 'https://')) {
        echo '<div class="meta">Missing post URL.</div>';
        exit;
    }
    if (!defined('AP_INBOX_LIB_ONLY')) {
        define('AP_INBOX_LIB_ONLY', true);
    }
    require_once __DIR__ . '/ap-inbox.php';
    require_once __DIR__ . '/ap-masto-entities.php';
    if (function_exists('ap_masto_mention_target_object_id')) {
        $stObject = ap_masto_mention_target_object_id($stObject);
    }
    $stStatusId = '';
    $stLocalStatus = null;
    $stEvent = null;
    try {
        // Any local note (incl. other instance accounts) — authorship from note URL
        if (vaak_is_local_url($stObject)
            && function_exists('ap_masto_status_by_note_id')) {
            $localRow = ap_masto_status_by_note_id($stObject);
            if (is_array($localRow)) {
                $stLocalStatus = ap_masto_status_from_row($localRow, true, true);
            }
        }
        if ($stLocalStatus === null && function_exists('ap_event_by_object_id')) {
            $stEvent = ap_event_by_object_id($stObject);
        }
        if ($stLocalStatus === null && !is_array($stEvent) && function_exists('ap_masto_ensure_remote_note_event')) {
            $stEvent = ap_masto_ensure_remote_note_event($stObject);
        }
        if (is_array($stLocalStatus) && !empty($stLocalStatus['id'])) {
            $stStatusId = (string) $stLocalStatus['id'];
        } elseif (is_array($stEvent)) {
            $stStatusId = ap_masto_event_status_id(
                (int) ($stEvent['id'] ?? 0),
                isset($stEvent['created_at']) ? (string) $stEvent['created_at'] : null
            );
        }
    } catch (Throwable $e) {
        error_log('[ap-admin] status thread partial: ' . $e->getMessage());
    }
    // Live reply poll (while viewing a thread): skip focus re-ensure; just refresh context.
    $isThreadPoll = isset($_GET['poll']) && (string) $_GET['poll'] === '1' && $wantReplies && !$wantAncestors && !$wantFocus;
    // Always (re)fetch the focus/source Note first so in_reply_to + body are populated
    // before we walk parents or pull remote reply context. First paint is cache-only;
    // this AJAX path is what fills the "source post" the user opened into.
    if (!$isThreadPoll
        && function_exists('ap_masto_ensure_remote_note_event')
        && !vaak_is_local_url($stObject)) {
        try {
            $ensuredFocus = ap_masto_ensure_remote_note_event($stObject);
            if (is_array($ensuredFocus)) {
                $stEvent = $ensuredFocus;
                $stStatusId = ap_masto_event_status_id(
                    (int) ($ensuredFocus['id'] ?? 0),
                    isset($ensuredFocus['created_at']) ? (string) $ensuredFocus['created_at'] : null
                );
            }
        } catch (Throwable $e) {
            error_log('[ap-admin] thread focus ensure: ' . $e->getMessage());
        }
    }
    // Pull remote Mastodon context so parents + replies not yet in our firehose can appear
    if (($wantAncestors || $wantReplies) && function_exists('ap_masto_fetch_remote_context_uris')) {
        try {
            // Polls: short timeout + only ensure missing Notes (abort if client navigates away).
            if ($isThreadPoll) {
                ap_masto_fetch_remote_context_uris($stObject, 5, [
                    'poll' => true,
                    'ensure_cap' => 5,
                    'timeout_sec' => 4,
                ]);
            } else {
                ap_masto_fetch_remote_context_uris($stObject, 40);
            }
        } catch (Throwable $e) {
            error_log('[ap-admin] remote context: ' . $e->getMessage());
        }
    }
    // Re-resolve status id after ensures (Create may have been inserted)
    if ($stStatusId === '') {
        try {
            if (vaak_is_local_url($stObject) && function_exists('ap_masto_status_by_note_id')) {
                $localRow = ap_masto_status_by_note_id($stObject);
                if (is_array($localRow)) {
                    $stLocalStatus = ap_masto_status_from_row($localRow, true, true);
                    if (!empty($stLocalStatus['id'])) {
                        $stStatusId = (string) $stLocalStatus['id'];
                    }
                }
            }
            if ($stStatusId === '' && function_exists('ap_event_by_object_id')) {
                $stEvent = ap_event_by_object_id($stObject);
                if (is_array($stEvent)) {
                    $stStatusId = ap_masto_event_status_id(
                        (int) ($stEvent['id'] ?? 0),
                        isset($stEvent['created_at']) ? (string) $stEvent['created_at'] : null
                    );
                }
            }
        } catch (Throwable $e) {
        }
    }
    $stAncestors = [];
    $stDescendants = [];
    if ($stStatusId !== '') {
        $ctx = ap_masto_status_context((int) $stStatusId, [
            'allow_fetch' => true,
            'max_ancestor_depth' => 12,
        ]);
        $stAncestors = is_array($ctx['ancestors'] ?? null) ? $ctx['ancestors'] : [];
        $stDescendants = is_array($ctx['descendants'] ?? null) ? $ctx['descendants'] : [];
    }
    if ($wantFocus) {
        // Re-load local/event after ensure above
        $focusLocal = null;
        $focusEvent = is_array($stEvent) ? $stEvent : null;
        try {
            if (vaak_is_local_url($stObject) && function_exists('ap_masto_status_by_note_id')) {
                $lr = ap_masto_status_by_note_id($stObject);
                if (is_array($lr)) {
                    $focusLocal = ap_masto_status_from_row($lr, true, true);
                }
            }
            if ($focusLocal === null && $focusEvent === null && function_exists('ap_event_by_object_id')) {
                $focusEvent = ap_event_by_object_id($stObject);
            }
        } catch (Throwable $e) {
        }
        if (is_array($focusLocal)) {
            if (function_exists('ap_masto_status_flags_prefetch') && !empty($focusLocal['id'])) {
                ap_masto_status_flags_prefetch([(string) $focusLocal['id']]);
            }
            admin_render_masto_status_card($focusLocal, $followingIds, $stFrom, true, false);
            exit;
        }
        if (is_array($focusEvent)) {
            $focusStatus = function_exists('ap_masto_status_from_event')
                ? ap_masto_status_from_event($focusEvent)
                : null;
            if (is_array($focusStatus)) {
                if (function_exists('ap_masto_status_flags_prefetch') && !empty($focusStatus['id'])) {
                    ap_masto_status_flags_prefetch([(string) $focusStatus['id']]);
                }
                admin_render_masto_status_card($focusStatus, $followingIds, $stFrom, true, false);
            } else {
                admin_render_event_tweet($focusEvent, $followingIds, $stFrom);
            }
            exit;
        }
        echo '<div class="meta">Couldn’t load this post yet. '
            . '<a href="?view=status&amp;object=' . h(urlencode($stObject))
            . '&amp;from=' . h($stFrom) . '&amp;refresh_thread=1">Retry</a></div>';
        exit;
    }
    if (function_exists('ap_masto_status_flags_prefetch')) {
        $ids = [];
        foreach (array_merge($wantAncestors ? $stAncestors : [], $wantReplies ? $stDescendants : []) as $card) {
            if (is_array($card) && !empty($card['id'])) {
                $ids[] = (string) $card['id'];
            }
        }
        ap_masto_status_flags_prefetch($ids);
    }
    if ($wantAncestors) {
        if ($stAncestors === []) {
            echo '<div class="meta status-thread-empty" style="margin:0 0 1rem">No earlier posts in this thread on this instance.</div>';
        } else {
            echo '<div class="meta status-thread-count" style="margin:0 0 1rem">'
                . count($stAncestors) . ' earlier in thread</div>';
            foreach ($stAncestors as $anc) {
                if (is_array($anc)) {
                    admin_render_masto_status_card($anc, $followingIds, $stFrom, false, true);
                }
            }
        }
    }
    if ($wantReplies) {
        if ($stDescendants === []) {
            echo '<div class="meta">No replies found yet.</div>';
        } else {
            echo '<div class="meta status-thread-count" style="margin:1rem 0 .5rem">'
                . count($stDescendants) . ' replies</div>';
            foreach ($stDescendants as $desc) {
                if (is_array($desc)) {
                    admin_render_masto_status_card($desc, $followingIds, $stFrom, false, true);
                }
            }
        }
    }
    exit;
}

/**
 * Lightweight "anything newer than $sinceTs" poll for live feed updates.
 *
 * @param list<array<string,mixed>> $following
 * @return list<array{kind:string,sort:int,row:array<string,mixed>}>
 */
function admin_tl_fetch_newer(string $view, array $following, int $sinceTs, int $limit = 20): array
{
    if ($sinceTs <= 0) {
        return [];
    }
    $limit = max(1, min(40, $limit));
    $sinceAt = gmdate('c', $sinceTs);
    $db = ap_db();
    $ownerId = admin_owner_user_id();
    $out = [];
    $seen = [];

    $pushEvent = static function (array $e) use (&$out, &$seen, $ownerId): void {
        if (admin_timeline_row_hidden($e, $ownerId)) {
            return;
        }
        if (function_exists('ap_row_matches_muted_words')
            && ap_row_matches_muted_words($e, 'event', [], $ownerId)) {
            return;
        }
        $oid = rtrim((string) ($e['object_id'] ?? ''), '/');
        $key = $oid !== '' ? ('o:' . $oid) : ('e:' . (int) ($e['id'] ?? 0));
        if (isset($seen[$key])) {
            return;
        }
        $item = [
            'kind' => 'event',
            'sort' => strtotime((string) ($e['created_at'] ?? '')) ?: (int) ($e['id'] ?? 0),
            'row' => $e,
        ];
        if (admin_timeline_item_muted_by_words($item)) {
            return;
        }
        $seen[$key] = true;
        $out[] = $item;
    };

    try {
        if ($view === 'local') {
            $st = $db->prepare(
                "SELECT * FROM outbox_notes
                 WHERE id LIKE 'https://mkultra.monster/users/%/notes/%'
                   AND published > ?
                 ORDER BY published DESC
                 LIMIT ?"
            );
            $st->execute([$sinceAt, $limit * 2]);
            foreach ($st->fetchAll() ?: [] as $n) {
                if (!is_array($n)) {
                    continue;
                }
                $nid = rtrim((string) ($n['id'] ?? ''), '/');
                if ($nid === '' || isset($seen['o:' . $nid])) {
                    continue;
                }
                $item = [
                    'kind' => 'outbox',
                    'sort' => strtotime((string) ($n['published'] ?? '')) ?: 0,
                    'row' => $n,
                ];
                if ($item['sort'] <= $sinceTs || admin_timeline_item_muted_by_words($item)) {
                    continue;
                }
                $seen['o:' . $nid] = true;
                $out[] = $item;
                if (count($out) >= $limit) {
                    break;
                }
            }
        } elseif ($view === 'gallery' || $view === 'vakktok') {
            $st = $db->prepare(
                "SELECT * FROM events
                 WHERE type IN ('Create', 'Quote', 'QuotePost')
                   AND action_taken IN ('log', 'local_observe')
                   AND media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]'
                   AND created_at > ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT ?"
            );
            $st->execute([$sinceAt, $limit * 3]);
            foreach ($st->fetchAll() ?: [] as $e) {
                if (!is_array($e) || !admin_gallery_event_has_media($e)) {
                    continue;
                }
                if ($view === 'vakktok' && (!admin_vakktok_item_has_video(['row' => $e]) || admin_vakktok_item_is_sensitive(['row' => $e]))) {
                    continue;
                }
                $pushEvent($e);
                if (count($out) >= $limit) {
                    break;
                }
            }
        } elseif ($view === 'feed') {
            $st = $db->prepare(
                "SELECT * FROM events
                 WHERE type IN ('Create', 'Quote', 'QuotePost', 'Announce')
                   AND action_taken IN ('log', 'local_observe')
                   AND created_at > ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT ?"
            );
            $st->execute([$sinceAt, $limit * 3]);
            foreach ($st->fetchAll() ?: [] as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $pushEvent($e);
                if (count($out) >= $limit) {
                    break;
                }
            }
        } else { // home
            $actorIds = [];
            foreach ($following as $f) {
                $aid = rtrim((string) ($f['actor_id'] ?? ''), '/');
                if ($aid !== '') {
                    $actorIds[$aid] = true;
                    $actorIds[$aid . '/'] = true;
                }
            }
            $ids = array_keys($actorIds);
            if ($ids !== []) {
                foreach (array_chunk($ids, 80) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $st = $db->prepare(
                        "SELECT * FROM events
                         WHERE type IN ('Create', 'Announce', 'Quote', 'QuotePost')
                           AND action_taken IN ('log', 'local_observe')
                           AND actor_id IN ($ph)
                           AND created_at > ?
                         ORDER BY created_at DESC, id DESC
                         LIMIT ?"
                    );
                    $params = $chunk;
                    $params[] = $sinceAt;
                    $params[] = $limit * 2;
                    $st->execute($params);
                    foreach ($st->fetchAll() ?: [] as $e) {
                        if (!is_array($e)) {
                            continue;
                        }
                        $pushEvent($e);
                    }
                    if (count($out) >= $limit) {
                        break;
                    }
                }
            }
            // Local peers' Creates always belong on Home
            $st = $db->prepare(
                "SELECT * FROM events
                 WHERE type IN ('Create', 'Quote', 'QuotePost')
                   AND action_taken IN ('log', 'local_observe')
                   AND actor_id LIKE 'https://mkultra.monster/users/%'
                   AND created_at > ?
                 ORDER BY created_at DESC, id DESC
                 LIMIT ?"
            );
            $st->execute([$sinceAt, $limit]);
            foreach ($st->fetchAll() ?: [] as $e) {
                if (!is_array($e)) {
                    continue;
                }
                $pushEvent($e);
            }
            // Own compose posts live in outbox_notes (events use action_taken=compose
            // and are skipped by the query above). Without this, soft-refresh after
            // posting never shows your own card until a hard refresh.
            $ownActor = rtrim((string) ($GLOBALS['vaak_actor_id'] ?? ''), '/');
            if ($ownActor !== '' && str_starts_with($ownActor, 'https://mkultra.monster/users/')) {
                $prefix = $ownActor . '/notes/%';
                $st = $db->prepare(
                    "SELECT * FROM outbox_notes
                     WHERE id LIKE ?
                       AND published > ?
                     ORDER BY published DESC
                     LIMIT ?"
                );
                $st->execute([$prefix, $sinceAt, $limit]);
                foreach ($st->fetchAll() ?: [] as $n) {
                    if (!is_array($n)) {
                        continue;
                    }
                    $nid = rtrim((string) ($n['id'] ?? ''), '/');
                    if ($nid === '' || isset($seen['o:' . $nid])) {
                        continue;
                    }
                    $item = [
                        'kind' => 'outbox',
                        'sort' => strtotime((string) ($n['published'] ?? '')) ?: 0,
                        'row' => $n,
                    ];
                    if ($item['sort'] <= $sinceTs || admin_timeline_item_muted_by_words($item)) {
                        continue;
                    }
                    $seen['o:' . $nid] = true;
                    $out[] = $item;
                }
            }
        }
    } catch (Throwable $e) {
        error_log('[ap-admin] newer poll: ' . $e->getMessage());
    }

    usort($out, static fn($a, $b) => $b['sort'] <=> $a['sort']);
    return array_slice($out, 0, $limit);
}

// AJAX fragment for Bluesky tab infinite scroll (cursor-based) + deferred merge samples
if ($isPartial && $view === 'bluesky') {
    $bskyPartialT0 = microtime(true);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    if (!function_exists('ap_bsky_tab_enabled') || !ap_bsky_tab_enabled() || !is_array(ap_bsky_session_row($vaakOwnerId))) {
        header('X-Has-More: 0');
        exit;
    }
    $bskyFeedKey = trim((string) ($_GET['feed'] ?? 'following'));
    $bskyCursor = trim((string) ($_GET['cursor'] ?? ''));
    $bskySource = preg_replace('/[^a-z_]/', '', (string) ($_GET['source'] ?? '')) ?: '';
    $wantMerge = isset($_GET['merge']) && (string) $_GET['merge'] === '1';
    $bskyLimit = max(10, min(50, (int) ($_GET['limit'] ?? ($wantMerge ? 15 : 20))));
    $prefsRaw = ap_bsky_get_preferences($vaakOwnerId);
    $prefs = ap_bsky_parse_feed_prefs(!empty($prefsRaw['ok']) ? ($prefsRaw['preferences'] ?? []) : []);
    if ($bskyFeedKey === '' || $bskyFeedKey === 'following' || $bskyFeedKey === 'timeline') {
        $tl = ap_bsky_following_feed(
            $vaakOwnerId,
            $bskyLimit,
            $bskyCursor !== '' ? $bskyCursor : null,
            $prefs,
            $wantMerge,
            $bskySource
        );
    } else {
        $tl = ap_bsky_get_custom_feed($vaakOwnerId, $bskyFeedKey, $bskyLimit, $bskyCursor !== '' ? $bskyCursor : null);
        if (!empty($tl['ok']) && is_array($tl['feed'] ?? null)) {
            $tl['feed'] = ap_bsky_filter_feed_items($tl['feed'], $prefs);
            $tl['feed'] = ap_bsky_filter_hidden_authors($vaakOwnerId, $tl['feed']);
        }
    }
    $feed = (!empty($tl['ok']) && is_array($tl['feed'] ?? null)) ? $tl['feed'] : [];
    $nextCursor = is_string($tl['cursor'] ?? null) ? (string) $tl['cursor'] : '';
    $outSource = (string) ($tl['source'] ?? ($bskySource !== '' ? $bskySource : 'home'));
    header('X-Has-More: ' . ($nextCursor !== '' ? '1' : '0'));
    header('X-Next-Cursor: ' . $nextCursor);
    header('X-BSKY-Source: ' . $outSource);
    header('X-BSKY-Cache: ' . (string) ($tl['cache'] ?? 'miss'));
    header('X-BSKY-Partial-Ms: ' . (string) (int) round((microtime(true) - $bskyPartialT0) * 1000));
    if (isset($tl['timing_ms'])) {
        header('X-BSKY-Timeline-Ms: ' . (string) (int) $tl['timing_ms']);
    }
    foreach ($feed as $item) {
        admin_render_bsky_feed_item($item, $bskyFeedKey);
    }
    exit;
}

// AJAX fragment for Home / Local / Federated / Gallery infinite scroll
if ($isPartial && in_array($view, ['home', 'feed', 'local', 'gallery', 'vakktok'], true)) {
    // Live poll: items newer than the client's current head (no scroll jump on server).
    $wantNewer = isset($_GET['newer']) && (string) $_GET['newer'] === '1';
    $sinceTs = isset($_GET['since']) ? (int) $_GET['since'] : 0;
    if ($wantNewer) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
        if ($sinceTs <= 0) {
            header('X-New-Count: 0');
            header('X-Newest: 0');
            exit;
        }
        $slice = admin_tl_fetch_newer($view, $following, $sinceTs, max(8, min(30, $tlLimit)));
        $newest = $sinceTs;
        foreach ($slice as $it) {
            $newest = max($newest, (int) ($it['sort'] ?? 0));
        }
        if (function_exists('ap_masto_status_flags_prefetch')) {
            ap_masto_status_flags_prefetch(admin_timeline_status_ids($slice));
        }
        header('X-New-Count: ' . count($slice));
        header('X-Newest: ' . $newest);
        foreach ($slice as $item) {
            if (admin_timeline_item_muted_by_words($item)) {
                continue;
            }
            if ($view === 'gallery') {
                admin_render_gallery_cell($item, $followingIds, 'gallery');
            } elseif ($view === 'vakktok') {
                admin_render_vakktok_cell($item);
            } else {
                admin_render_timeline_item($item, $followingIds, $view);
            }
        }
        exit;
    }
    if ($view === 'vakktok') {
        // VakkTok has a media-only projection; never serve mixed gallery cache keys.
        $adminTlFromCache = false;
        $adminTlRankedCached = null;
    }
    $adminTlPerfT0 = microtime(true);
    $adminTlPerfHydrateMs = 0.0;
    if ($adminTlFromCache && is_array($adminTlRankedCached)) {
        $totalRanked = count($adminTlRankedCached);
        // Past the cached head → re-query older remotes by created_at cursor and append.
        if ($tlOffset + $tlLimit >= $totalRanked) {
            $extended = admin_tl_extend_ranked(
                $view,
                $following,
                $adminTlRankedCached,
                max(80, $tlLimit * 6)
            );
            if (is_array($extended) && count($extended) > $totalRanked) {
                $adminTlRankedCached = $extended;
                $totalRanked = count($adminTlRankedCached);
                $ck = $adminTlCacheKey !== '' ? $adminTlCacheKey : admin_tl_cache_key($view, $following);
                admin_tl_cache_put($ck, $adminTlRankedCached);
            }
        }
        $sliceKeys = array_slice($adminTlRankedCached, $tlOffset, $tlLimit);
        $hydrateT0 = microtime(true);
        $slice = admin_tl_hydrate($sliceKeys);
        // Prefetch masto rows for any outbox cards in this window
        $hydrateNotes = [];
        foreach ($slice as $it) {
            if (($it['kind'] ?? '') === 'outbox') {
                $nid = rtrim((string) ($it['row']['id'] ?? ''), '/');
                if ($nid !== '') {
                    $hydrateNotes[$nid] = true;
                    $hydrateNotes[$nid . '/'] = true;
                }
            }
        }
        if ($hydrateNotes !== []) {
            try {
                $idList = array_keys($hydrateNotes);
                foreach (array_chunk($idList, 400) as $chunk) {
                    $ph = implode(',', array_fill(0, count($chunk), '?'));
                    $st = ap_db()->prepare(
                        "SELECT note_id, local_id, content_text, spoiler_text, sensitive, visibility
                         FROM masto_statuses WHERE note_id IN ($ph)"
                    );
                    $st->execute($chunk);
                    foreach ($st->fetchAll() ?: [] as $row) {
                        if (!is_array($row)) {
                            continue;
                        }
                        $key = rtrim((string) ($row['note_id'] ?? ''), '/');
                        if ($key !== '') {
                            $GLOBALS['admin_masto_by_note'][$key] = $row;
                            $GLOBALS['admin_masto_by_note'][$key . '/'] = $row;
                        }
                    }
                }
            } catch (Throwable $e) {
                // per-card fallback
            }
        }
        $adminTlPerfHydrateMs = (microtime(true) - $hydrateT0) * 1000.0;
        $hasMore = ($tlOffset + $tlLimit) < $totalRanked;
        $nextOffset = $tlOffset + $tlLimit;
    } else {
        $timeline = $view === 'feed'
            ? $feedTimeline
            : ($view === 'local'
                ? $localTimeline
                : (in_array($view, ['gallery', 'vakktok'], true) ? $galleryTimeline : $homeTimeline));
        if ($view === 'vakktok') {
            $timeline = array_values(array_filter($timeline, static fn($item): bool => is_array($item) && admin_vakktok_item_has_video($item) && !admin_vakktok_item_is_sensitive($item)));
        }
        $rankedMiss = admin_tl_rank_from_timeline($timeline);
        // Cache miss on a deep offset: extend remotes instead of serving an empty tail.
        while ($tlOffset + $tlLimit > count($rankedMiss) && $rankedMiss !== []) {
            $extended = admin_tl_extend_ranked(
                $view,
                $following,
                $rankedMiss,
                max(80, $tlLimit * 6)
            );
            if (!is_array($extended) || count($extended) <= count($rankedMiss)) {
                break;
            }
            $rankedMiss = $extended;
        }
        if (count($rankedMiss) > count($timeline)) {
            $ck = $adminTlCacheKey !== '' ? $adminTlCacheKey : admin_tl_cache_key($view, $following);
            admin_tl_cache_put($ck, $rankedMiss);
            $sliceKeys = array_slice($rankedMiss, $tlOffset, $tlLimit);
            $slice = admin_tl_hydrate($sliceKeys);
            $hasMore = ($tlOffset + $tlLimit) < count($rankedMiss);
            $nextOffset = $tlOffset + $tlLimit;
        } else {
            $slice = array_slice($timeline, $tlOffset, $tlLimit);
            $hasMore = ($tlOffset + $tlLimit) < count($timeline);
            $nextOffset = $tlOffset + count($slice);
        }
    }
    $flagsT0 = microtime(true);
    if (function_exists('ap_masto_status_flags_prefetch')) {
        ap_masto_status_flags_prefetch(admin_timeline_status_ids($slice));
    }
    $adminTlPerfFlagsMs = (microtime(true) - $flagsT0) * 1000.0;
    $renderT0 = microtime(true);
    ob_start();
    foreach ($slice as $item) {
        if (admin_timeline_item_muted_by_words($item)) {
            continue;
        }
        if ($view === 'gallery') {
            admin_render_gallery_cell($item, $followingIds, 'gallery');
        } elseif ($view === 'vakktok') {
            admin_render_vakktok_cell($item);
        } else {
            admin_render_timeline_item($item, $followingIds, $view);
        }
    }
    $body = ob_get_clean();
    $adminTlPerfRenderMs = (microtime(true) - $renderT0) * 1000.0;
    $adminTlPerfTotalMs = (microtime(true) - $adminTlPerfT0) * 1000.0;
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Has-More: ' . ($hasMore ? '1' : '0'));
    header('X-Next-Offset: ' . $nextOffset);
    header('X-TL-Cache: ' . ($adminTlFromCache ? 'hit' : 'miss'));
    header('X-TL-Hydrate-Ms: ' . (string) (int) round($adminTlPerfHydrateMs));
    header('X-TL-Flags-Ms: ' . (string) (int) round($adminTlPerfFlagsMs));
    header('X-TL-Render-Ms: ' . (string) (int) round($adminTlPerfRenderMs));
    header('X-TL-Partial-Ms: ' . (string) (int) round($adminTlPerfTotalMs));
    echo $body;
    exit;
}

// AJAX fragment for Notifications infinite scroll (append older cards; keep scroll place).
if ($isPartial && $view === 'mentions') {
    $notifFilter = strtolower(trim((string) ($_GET['notification_filter'] ?? 'all')));
    $notifFilterOptions = [
        'all' => ['types' => []],
        'mentions' => ['types' => ['mention']],
        'favourites' => ['types' => ['favourite']],
        'boosts_quotes' => ['types' => ['reblog', 'quote']],
    ];
    if (!isset($notifFilterOptions[$notifFilter])) {
        $notifFilter = 'all';
    }
    $notifTypes = $notifFilterOptions[$notifFilter]['types'];
    $notifLimit = isset($_GET['limit']) ? max(1, min(40, (int) $_GET['limit'])) : 20;
    $notifMaxId = preg_replace('/\D+/', '', (string) ($_GET['notifications_max_id'] ?? '')) ?: null;
    // Partials skip followers by default — restore for Follow/relationship badges.
    if ($followerIds === []) {
        try {
            $followers = ap_followers_list($vaakActorId);
            $followerIds = $adminIndexActorMap($followers, true);
        } catch (Throwable $e) {
            $followerIds = [];
        }
    }
    $adminNotifs = [];
    try {
        $adminNotifs = function_exists('ap_masto_notifications_fetch')
            ? ap_masto_notifications_fetch($notifLimit, $notifMaxId, null, $notifTypes)
            : [];
    } catch (Throwable $e) {
        error_log('[ap-admin] notifications partial: ' . $e->getMessage());
    }
    $nextMaxId = '';
    if ($adminNotifs !== []) {
        $nextMaxId = preg_replace('/\D+/', '', (string) ($adminNotifs[count($adminNotifs) - 1]['id'] ?? '')) ?: '';
    }
    $hasMore = count($adminNotifs) >= $notifLimit && $nextMaxId !== '';
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Has-More: ' . ($hasMore ? '1' : '0'));
    header('X-Next-Max-Id: ' . $nextMaxId);
    foreach ($adminNotifs as $n) {
        if (is_array($n)) {
            admin_render_notification_card($n, $followingIds, $followerIds);
        }
    }
    exit;
}

// Search: pasted post permalink → resolve & redirect BEFORE any HTML (headers must be clean).
// Doing this inside the search view (after DOCTYPE) made Location a no-op → blank/broken page.
if (!$isPartial && $view === 'search') {
    $earlyPostUrl = trim((string) ($_GET['q'] ?? ''));
    if ($earlyPostUrl !== '' && str_starts_with($earlyPostUrl, 'https://')) {
        require_once __DIR__ . '/ap-masto-entities.php';
        if (function_exists('ap_masto_url_looks_like_status') && ap_masto_url_looks_like_status($earlyPostUrl)) {
            if (!defined('AP_INBOX_LIB_ONLY')) {
                define('AP_INBOX_LIB_ONLY', true);
            }
            require_once __DIR__ . '/ap-inbox.php';
            @set_time_limit(45);
            $resolvedPost = function_exists('ap_masto_resolve_pasted_status_url')
                ? ap_masto_resolve_pasted_status_url($earlyPostUrl)
                : $earlyPostUrl;
            if (is_string($resolvedPost) && str_starts_with($resolvedPost, 'https://')) {
                // Don't deep-link into a post whose author is content-blocked for this viewer.
                $blockAuthor = function_exists('ap_masto_actor_url_from_object_url')
                    ? ap_masto_actor_url_from_object_url($resolvedPost)
                    : null;
                if (is_string($blockAuthor) && $blockAuthor !== ''
                    && function_exists('ap_actor_is_content_blocked')
                    && ap_actor_is_content_blocked($blockAuthor, null, admin_owner_user_id())) {
                    $error = 'That post is hidden because you blocked the author (or they are blocked server-wide).';
                } else {
                    header(
                        'Location: /vaak/?view=status&object=' . rawurlencode($resolvedPost) . '&from=search',
                        true,
                        302
                    );
                    exit;
                }
            }
            // Fall through to search UI with an error banner
            $error = 'Could not fetch that post URL (unreachable, blocked, or not a public Note).';
        }
    }
}

// Notification unread badge (nav + browser tab). Opening Notifications clears it.
// Seed chime from latest_unread_id (same light scan as the ajax poll) so we never
// chime on hydration-order flicker from the full notifications feed.
$notifUnreadNav = 0;
$notifLatestUnreadIdNav = '';
$notifLatestIdNav = '';
$notifLastReadIdNav = '0';
try {
    if ($view === 'mentions' && function_exists('ap_masto_notifications_mark_read')) {
        // Prefer the newest id the Notifications UI will actually render, then
        // mark_read still maxes with the light scan so badge + list stay aligned.
        $markTip = null;
        try {
            if (function_exists('ap_masto_notifications_fetch')) {
                $markNewest = ap_masto_notifications_fetch(1);
                $markTip = (string) ($markNewest[0]['id'] ?? '');
            }
        } catch (Throwable $e) {
            $markTip = null;
        }
        ap_masto_notifications_mark_read($markTip !== '' ? $markTip : null);
    }
    if ($view !== 'mentions' && function_exists('ap_masto_notifications_unread_state')) {
        $notifStateNav = ap_masto_notifications_unread_state(80, false);
        $notifUnreadNav = (int) ($notifStateNav['count'] ?? 0);
        $notifLatestUnreadIdNav = preg_replace('/\D+/', '', (string) ($notifStateNav['latest_unread_id'] ?? '')) ?: '';
        $notifLatestIdNav = preg_replace('/\D+/', '', (string) ($notifStateNav['latest_id'] ?? '')) ?: '';
        $notifLastReadIdNav = preg_replace('/\D+/', '', (string) ($notifStateNav['last_read_id'] ?? '0')) ?: '0';
    } elseif ($view !== 'mentions' && function_exists('ap_masto_notifications_unread_count')) {
        $notifUnreadNav = ap_masto_notifications_unread_count(80);
    }
} catch (Throwable $e) {
    error_log('[ap-admin] notif badge: ' . $e->getMessage());
    $notifUnreadNav = 0;
}
$notifBadgeLabel = $notifUnreadNav > 99 ? '99+' : (string) (int) $notifUnreadNav;
// Chime seed prefers the newest unread tip; fall back to overall latest when caught up.
if ($notifLatestUnreadIdNav === '') {
    $notifLatestUnreadIdNav = $notifLatestIdNav;
}
$notifLastReadIdNav = preg_replace('/\D+/', '', $notifLastReadIdNav) ?: '0';
// Seed unread DM tip so the first poll cannot chime for already-open DMs.
$dmLatestIdNav = '';
try {
    $ownerForDmSeed = function_exists('admin_owner_user_id') ? admin_owner_user_id() : (int) ($vaakUser['id'] ?? 0);
    if ($ownerForDmSeed > 0) {
        $dmSeedSt = ap_db()->prepare(
            "SELECT id FROM direct_messages
             WHERE owner_user_id = ? AND direction = 'in' AND read_at IS NULL AND deleted_at IS NULL
             ORDER BY id DESC LIMIT 1"
        );
        $dmSeedSt->execute([$ownerForDmSeed]);
        $dmLatestIdNav = (string) ($dmSeedSt->fetchColumn() ?: '');
    }
} catch (Throwable $e) {
    $dmLatestIdNav = '';
}

// Notices badge: baseline cursor on first auth page load; clear when opening Notices.
$noticesUnreadNav = 0;
try {
    if ($vaakOwnerId > 0 && function_exists('ap_notices_ensure_read_cursor')) {
        if ($view === 'notices' && function_exists('ap_notices_mark_read')) {
            ap_notices_mark_read($vaakOwnerId);
        } else {
            ap_notices_ensure_read_cursor($vaakOwnerId);
            $noticesUnreadNav = function_exists('ap_notices_unread_count')
                ? ap_notices_unread_count($vaakOwnerId)
                : 0;
        }
    }
} catch (Throwable $e) {
    error_log('[ap-admin] notices badge: ' . $e->getMessage());
    $noticesUnreadNav = 0;
}

// Opening a DM thread marks that peer read before nav/unread counts render
try {
    if ($view === 'dms' && function_exists('ap_dm_mark_peer_read')) {
        $earlyDmPeer = trim((string) ($_GET['peer'] ?? ''));
        if ($earlyDmPeer !== '' && str_starts_with($earlyDmPeer, 'https://')) {
            ap_dm_mark_peer_read(rtrim($earlyDmPeer, '/'));
        }
    }
} catch (Throwable $e) {
    error_log('[ap-admin] dm mark read (early): ' . $e->getMessage());
}

header('Content-Type: text/html; charset=utf-8');

/** Render a compact, Mastodon-style recommendation break in Home. */
/**
 * Render one admin notification card (Notifications timeline).
 *
 * @param array<string,mixed> $n Mastodon notification entity
 * @param array<string,bool> $followingIds
 * @param array<string,bool> $followerIds
 */
function admin_render_notification_card(array $n, array $followingIds, array $followerIds): void
{
    try {
        if (!is_array($n)) {
            return;
        }
        $nType = (string) ($n['type'] ?? 'mention');
        $nAcct = (string) ($n['account']['acct'] ?? '?');
        $nDisplay = (string) ($n['account']['display_name'] ?? '');
        $nWebUrl = (string) ($n['account']['url'] ?? '');
        $nActorUri = (string) ($n['account']['uri'] ?? '');
        $nActorRef = function_exists('admin_account_actor_ref')
            ? admin_account_actor_ref(is_array($n['account'] ?? null) ? $n['account'] : [])
            : ($nActorUri !== '' ? $nActorUri : $nWebUrl);
        $nCreated = (string) ($n['created_at'] ?? '');
        $nStatus = (isset($n['status']) && is_array($n['status'])) ? $n['status'] : null;
        $nStatusUri = is_array($nStatus) ? (string) ($nStatus['uri'] ?? $nStatus['url'] ?? '') : '';
        $nSnippet = '';
        $nMedia = [];
        $nStatusMentions = [];
        if (is_array($nStatus)) {
            $nSnippet = trim(admin_html_to_plain((string) ($nStatus['content'] ?? '')));
            $nSnippet = preg_replace('/^\h+/mu', '', $nSnippet) ?? $nSnippet;
            if (function_exists('ap_masto_clean_mention_text')) {
                $nSnippet = ap_masto_clean_mention_text($nSnippet);
            } else {
                $nSnippet = preg_replace('#^RE:\s*https://\S+#u', '', $nSnippet) ?? $nSnippet;
                $nSnippet = trim($nSnippet);
            }
            // Wafrn may glue the first commentary word onto our local domain
            // (@cmdr_nova@mkultra.monsteraudio / @…monsterone). Shared unglue
            // restores the real handle before we linkify the snippet.
            if (function_exists('ap_masto_unglue_host_mentions')) {
                $nSnippet = ap_masto_unglue_host_mentions($nSnippet);
            }
            $nStatusMentions = [];
            foreach (($nStatus['mentions'] ?? []) as $nMention) {
                if (!is_array($nMention)) {
                    continue;
                }
                $nStatusMentions[] = $nMention;
                $nAcctKnown = ltrim(trim((string) ($nMention['acct'] ?? '')), '@');
                if ($nAcctKnown !== '' && str_contains($nAcctKnown, '@') && str_starts_with($nSnippet, '@' . $nAcctKnown)) {
                    $afterMention = strlen($nAcctKnown) + 1;
                    if (isset($nSnippet[$afterMention]) && !preg_match('/\s/u', $nSnippet[$afterMention])) {
                        $nSnippet = substr($nSnippet, 0, $afterMention) . ' ' . substr($nSnippet, $afterMention);
                    }
                    break;
                }
            }
            foreach (($nStatus['media_attachments'] ?? []) as $nAttachment) {
                if (!is_array($nAttachment)) {
                    continue;
                }
                $nUrl = (string) ($nAttachment['url'] ?? $nAttachment['preview_url'] ?? '');
                if (!str_starts_with($nUrl, 'https://')) {
                    continue;
                }
                $nMedia[] = [
                    'url' => $nUrl,
                    'mediaType' => in_array(strtolower((string) ($nAttachment['type'] ?? '')), ['video', 'gifv'], true) ? 'video/mp4' : null,
                    'preview_url' => (string) ($nAttachment['preview_url'] ?? ''),
                ];
                if (count($nMedia) >= 4) {
                    break;
                }
            }
        }
        $typeLabel = match ($nType) {
            'follow' => '👤 followed you',
            'favourite' => '★ liked your post',
            'reblog' => '🔁 boosted your post',
            'mention' => '＠ mentioned you',
            'quote' => '💬 quoted your post',
            'poll' => '📊 poll ended',
            'update' => '✏️ edited a post you liked',
            'bite' => '🦷 bit you / your post',
            'status' => '✉ posted',
            default => $nType,
        };
        // Account-targeted bites use synthetic /bites-received/ URIs (no real post).
        $biteHasPost = false;
        if ($nType === 'bite') {
            $biteTarget = $nStatusUri;
            if (function_exists('ap_masto_mention_target_object_id')) {
                $biteTarget = ap_masto_mention_target_object_id($biteTarget);
            }
            $biteHasPost = $biteTarget !== ''
                && !str_contains($biteTarget, '/bites-received/')
                && (str_contains($biteTarget, '/notes/')
                    || str_contains($biteTarget, '/statuses/')
                    || str_contains($biteTarget, '/objects/'));
            $typeLabel = $biteHasPost ? '🦷 bit your post' : '🦷 bit you';
        }
        $nRel = $nActorRef !== ''
            ? admin_rel_state($followingIds, $followerIds, $nActorRef, $nWebUrl, $nActorUri)
            : 'none';
        $alreadyFollowing = $nRel === 'mutual' || $nRel === 'following';
        $snipShow = $nSnippet;
        if (function_exists('mb_strlen') && function_exists('mb_substr') && is_string($nSnippet) && mb_strlen($nSnippet) > 280) {
            $snipShow = mb_substr($nSnippet, 0, 280) . '…';
        } elseif (is_string($nSnippet) && strlen($nSnippet) > 280) {
            $snipShow = substr($nSnippet, 0, 280) . '…';
        }
        // Clickable @handles in mention/quote bodies (full @user@host when present).
        $nSnippetHtml = $snipShow !== ''
            ? admin_linkify_body_html($snipShow, 'mentions', $nStatusMentions ?? [])
            : '';
        $profileHref = $nActorRef !== ''
            ? ('?view=remote_profile&actor=' . rawurlencode($nActorRef) . '&from=mentions')
            : '';
    ?>
    <article class="tweet tweet-notif tweet-notif-<?= h($nType) ?>">
    <div class="tweet-hd">
      <?php if ($profileHref !== ''): ?>
        <a href="<?= h($profileHref) ?>" title="Open profile" style="text-decoration:none"><?= admin_avatar_img($nActorRef !== '' ? $nActorRef : null) ?></a>
      <?php else: ?>
        <?= admin_avatar_img($nActorRef !== '' ? $nActorRef : null) ?>
      <?php endif; ?>
      <div class="tweet-hd-main">
        <div>
          <?php if ($profileHref !== ''): ?>
            <a class="who" href="<?= h($profileHref) ?>" style="color:inherit;text-decoration:none"><?= admin_emoji_html($nDisplay !== '' ? $nDisplay : $nAcct, $nActorRef !== '' ? $nActorRef : null) ?></a>
            <a class="meta" href="<?= h($profileHref) ?>" style="color:var(--muted);text-decoration:none"> @<?= h($nAcct) ?></a>
          <?php else: ?>
            <span class="who"><?= h($nAcct) ?></span>
          <?php endif; ?>
          <span class="meta"> · <?= h(relative_time($nCreated)) ?></span>
        </div>
        <div class="meta" style="color:var(--primary);margin-top:.2rem"><?= h($typeLabel) ?></div>
      </div>
    </div>
    <?php if ($nType === 'bite' && !$biteHasPost): ?>
      <div class="meta" style="margin-top:.55rem;color:var(--muted)">No associated post</div>
    <?php elseif ($nSnippetHtml !== '' && $nType === 'mention'): ?>
      <div class="body feed-body notification-post"><?= $nSnippetHtml ?></div>
    <?php elseif ($nSnippetHtml !== '' && $nType === 'quote'): ?>
      <div class="body feed-body notification-post"><?= $nSnippetHtml ?></div>
    <?php elseif ($nSnippet !== '' && in_array($nType, ['favourite', 'reblog', 'update', 'poll', 'status'], true)): ?>
      <div class="quote-block" style="margin-top:.55rem"><span class="qt-label"><?= in_array($nType, ['quote', 'status'], true) ? 'Post' : 'Your post' ?></span><br><span class="notification-snippet"><?= h($snipShow) ?></span></div>
    <?php endif; ?>
    <?php if ($nMedia !== []): ?><div class="notification-media"><?= admin_media_row_html($nMedia) ?></div><?php endif; ?>
    <div class="tweet-actions">
      <?php if ($profileHref !== ''): ?>
        <a class="btn btn-ghost" href="<?= h($profileHref) ?>" style="padding:.25rem .7rem;font-size:.8rem">Profile</a>
      <?php endif; ?>
      <?php if ($nType === 'mention' && $nStatusUri !== ''): ?>
        <a class="icon-btn" href="?view=mentions&amp;compose=1&amp;reply_to=<?= urlencode($nStatusUri) ?>&amp;to=<?= urlencode($nActorRef) ?>" title="Reply" aria-label="Reply"><i class="ph ph-arrow-bend-up-left" aria-hidden="true"></i></a>
      <?php endif; ?>
      <?php
        // Bite user-target uses synthetic /bites-received/ URIs — Open would 404/white-screen.
        // Post bites keep a real note/status URI (with #bite- fragment stripped upstream).
        $notifOpenUri = $nStatusUri;
        if ($nType === 'bite') {
            if (!$biteHasPost || str_contains($notifOpenUri, '/bites-received/')) {
                $notifOpenUri = '';
            } elseif (function_exists('ap_masto_mention_target_object_id')) {
                $notifOpenUri = ap_masto_mention_target_object_id($notifOpenUri);
            }
        }
      ?>
      <?php if ($notifOpenUri !== '' && vaak_is_own_url($notifOpenUri) && str_contains($notifOpenUri, '/notes/')): ?>
        <a class="btn btn-ghost" href="?view=outbox&amp;focus=<?= urlencode($notifOpenUri) ?>" style="padding:.25rem .7rem;font-size:.8rem">Open in Your posts</a>
      <?php elseif ($notifOpenUri !== '' && !str_contains($notifOpenUri, '/bites-received/')): ?>
        <a class="btn btn-ghost" href="<?= h(admin_status_href($notifOpenUri, 'mentions')) ?>" style="padding:.25rem .7rem;font-size:.8rem">Open</a>
        <a href="<?= h(admin_remote_object_href($notifOpenUri)) ?>" target="_blank" rel="noopener noreferrer" class="meta">Remote</a>
      <?php endif; ?>
      <?php if ($nRel !== 'none'): ?>
        <?= admin_rel_badge($nRel) ?>
      <?php endif; ?>
      <?php if ($nActorRef !== '' && !$alreadyFollowing && $nType === 'follow'): ?>
        <form method="post" action="?view=mentions" style="display:inline">
          <input type="hidden" name="action" value="follow_remote">
          <input type="hidden" name="return_view" value="mentions">
          <input type="hidden" name="actor_id" value="<?= h($nActorRef) ?>">
          <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Follow back</button>
        </form>
      <?php elseif ($nActorRef !== '' && !$alreadyFollowing): ?>
        <form method="post" action="?view=mentions" style="display:inline">
          <input type="hidden" name="action" value="follow_remote">
          <input type="hidden" name="return_view" value="mentions">
          <input type="hidden" name="actor_id" value="<?= h($nActorRef) ?>">
          <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Follow</button>
        </form>
      <?php endif; ?>
    </div>
    </article>
    <?php
    } catch (Throwable $e) {
        error_log('[ap-admin] notif row: ' . $e->getMessage());
        echo '<div class="meta" style="padding:.5rem 0;color:var(--muted)">Skipped a notification (temporary error).</div>';
    }

}

function admin_render_home_suggestions(array $suggestions): void
{
    if (!$suggestions) {
        return;
    }
    shuffle($suggestions);
    $suggestions = array_slice($suggestions, 0, 3);
    echo '<section class="home-suggestions" aria-label="Suggested accounts">';
    echo '<div class="meta home-suggestions-title">Suggested accounts</div>';
    echo '<div class="home-suggestions-grid">';
    foreach ($suggestions as $sug) {
        $acc = is_array($sug['account'] ?? null) ? $sug['account'] : [];
        // Account.url is often an HTML profile page; uri is the AP actor IRI
        // that Follow delivery and actor-document fetching require.
        $actorUrl = (string) ($acc['uri'] ?? $acc['url'] ?? '');
        if ($actorUrl === '') {
            continue;
        }
        $display = trim((string) ($acc['display_name'] ?? $acc['username'] ?? 'account')) ?: 'account';
        $acct = (string) ($acc['acct'] ?? '');
        $av = (string) ($acc['avatar'] ?? '');
        echo '<article class="home-suggestion">';
        echo $av !== ''
            ? '<img class="tweet-av" src="' . h($av) . '" alt="" width="40" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer">'
            : admin_avatar_img($actorUrl);
        echo '<div class="home-suggestion-main"><a class="who" href="?view=remote_profile&amp;actor=' . rawurlencode($actorUrl) . '">' . h($display) . '</a>';
        if ($acct !== '') {
            echo '<div class="meta home-suggestion-handle" title="@' . h($acct) . '">@' . h($acct) . '</div>';
        }
        echo '<form method="post" action="?view=home" class="home-suggestion-form">'
            . '<input type="hidden" name="action" value="suggestion_follow">'
            . '<input type="hidden" name="return_view" value="home">'
            . '<input type="hidden" name="actor_id" value="' . h($actorUrl) . '">'
            . '<button class="btn btn-primary" type="submit">Follow</button></form></div></article>';
    }
    echo '</div></section>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#0a0a0a">
  <meta name="color-scheme" content="dark">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-title" content="VAAK">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="csrf-token" content="<?= h(ap_auth_csrf_token()) ?>">
  <title><?= $notifUnreadNav > 0 ? '(' . h($notifBadgeLabel) . ') ' : '' ?>VAAK · <?= h(view_title($view)) ?></title>
  <!-- Versioned, path-specific icons keep Safari from reusing the root site's favicon. -->
  <link rel="manifest" href="/vaak/manifest.webmanifest?v=20260909">
  <link rel="icon" href="/vaak/favicon.svg?v=20260907" type="image/svg+xml">
  <link rel="icon" href="/vaak/favicon.ico?v=20260907" sizes="any">
  <link rel="icon" href="/vaak/favicon-32x32.png?v=20260907" type="image/png" sizes="32x32">
  <link rel="icon" href="/vaak/favicon-16x16.png?v=20260907" type="image/png" sizes="16x16">
  <link rel="apple-touch-icon" href="/vaak/apple-touch-icon.png?v=20260907">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/regular/style.css">
  <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/fill/style.css">
  <script>
    (function () {
      const key = 'vaak-accent-<?= h((string) $vaakActorKey) ?>';
      const allowed = ['green', 'yellow', 'blue', 'red', 'purple', 'orange', 'pink'];
      const value = localStorage.getItem(key);
      if (allowed.includes(value)) document.documentElement.dataset.accent = value;
    }());
  </script>
  <style>
    :root {
      --primary: #00ff9f;
      --primary-dim: rgba(0,255,159,.15);
      --bg-glow: #102018;
      --bg: #0a0a0a;
      --panel: #121212;
      --panel-2: #1a1a1a;
      --border: #2a2a2a;
      --text: #f2f2f2;
      --muted: #8a8a8a;
      --danger: #ff6b6b;
      --info: #7ee0ff;
      --radius: 14px;
      --shadow: 0 8px 30px rgba(0,0,0,.35);
    }
    :root[data-accent="yellow"] { --primary:#ffd400; --primary-dim:rgba(255,212,0,.15); --bg-glow:#201e10; }
    :root[data-accent="blue"] { --primary:#5aa9ff; --primary-dim:rgba(90,169,255,.15); --bg-glow:#101820; }
    :root[data-accent="red"] { --primary:#ff6b6b; --primary-dim:rgba(255,107,107,.15); --bg-glow:#201010; }
    :root[data-accent="purple"] { --primary:#c084fc; --primary-dim:rgba(192,132,252,.15); --bg-glow:#181020; }
    :root[data-accent="orange"] { --primary:#ff9f43; --primary-dim:rgba(255,159,67,.15); --bg-glow:#201810; }
    :root[data-accent="pink"] { --primary:#ff70c7; --primary-dim:rgba(255,112,199,.15); --bg-glow:#201018; }
    * { box-sizing: border-box; }
    html { background: #0a0a0a; color-scheme: dark; }
    body {
      margin: 0;
      font-family: "Segoe UI", system-ui, sans-serif;
      background: radial-gradient(1200px 600px at 10% -10%, var(--bg-glow) 0%, var(--bg) 45%);
      color: var(--text);
      min-height: 100vh;
    }
    a { color: var(--primary); text-decoration: none; }
    a:hover { text-decoration: underline; }

    .shell {
      display: grid;
      grid-template-columns: 240px minmax(0, 1fr) 320px;
      /* Named areas so rail-right can sit before .main in the DOM (paints sooner
         on heavy pages like Notifications) while staying visually on the right. */
      grid-template-areas: "left main right";
      gap: 0;
      max-width: 1280px;
      margin: 0 auto;
      min-height: 100vh;
    }
    .rail-left { grid-area: left; }
    .main { grid-area: main; }
    .rail-right { grid-area: right; }
    @media (min-width: 701px) {
      body { height: 100vh; overflow: hidden; }
      .shell { height: 100vh; overflow: hidden; max-height: 100vh; }
      .rail-left, .rail-right {
        height: 100vh; overflow-y: auto; overscroll-behavior: contain;
      }
      .main {
        display: flex; flex-direction: column;
        min-height: 0; height: 100vh; overflow: hidden;
      }
      .topbar { flex: 0 0 auto; }
      .feed {
        flex: 1 1 auto; min-height: 0; overflow-y: auto;
        overscroll-behavior: contain;
      }
      /* Keep scroll, hide ugly scrollbars on rails + feed */
      .rail-left, .rail-right, .feed {
        scrollbar-width: none; /* Firefox */
        -ms-overflow-style: none; /* legacy Edge */
      }
      .rail-left::-webkit-scrollbar,
      .rail-right::-webkit-scrollbar,
      .feed::-webkit-scrollbar {
        width: 0;
        height: 0;
        display: none;
      }
    }
    @media (max-width: 1100px) {
      .shell {
        grid-template-columns: 72px minmax(0, 1fr);
        grid-template-areas: "left main";
      }
      .rail-right { display: none; }
      .nav span.label { display: none; }
      .brand .brand-ascii { display: none; }
      .brand strong { letter-spacing: .06em; text-indent: 0; font-size: .75rem; margin-top: 0; }
      .nav-group summary .label { display: none; }
      .nav-search-narrow { display: flex !important; }
    }
    /* Mobile: drawer nav (desktop / tablet rails unchanged above 700px) */
    .mobile-topbar { display: none; }
    .mobile-nav-backdrop { display: none; }
    @media (max-width: 700px) {
      .shell {
        grid-template-columns: 1fr;
        grid-template-areas: "main";
        height: auto;
        overflow: visible;
        max-width: 100%;
      }
      body { height: auto; overflow: auto; }
      body.mobile-nav-open { overflow: hidden; }

      .mobile-topbar {
        display: flex;
        align-items: center;
        gap: .65rem;
        position: sticky;
        top: 0;
        z-index: 60;
        padding: .55rem .75rem;
        padding-top: max(.55rem, env(safe-area-inset-top));
        background: rgba(18,18,18,.96);
        border-bottom: 1px solid var(--border);
        backdrop-filter: blur(8px);
      }
      .mobile-topbar__menu,
      .mobile-topbar__search {
        appearance: none;
        border: 1px solid var(--border);
        background: var(--panel-2);
        color: var(--text);
        border-radius: 999px;
        width: 2.4rem;
        height: 2.4rem;
        display: inline-flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        font-size: 1.1rem;
        text-decoration: none;
        flex: 0 0 auto;
      }
      .mobile-topbar__menu:hover,
      .mobile-topbar__search:hover { border-color: var(--primary); color: var(--primary); text-decoration: none; }
      .mobile-topbar__title {
        flex: 1 1 auto;
        min-width: 0;
        font-size: .95rem;
        font-weight: 700;
        letter-spacing: .02em;
        white-space: nowrap;
        overflow: hidden;
        text-overflow: ellipsis;
      }
      .mobile-topbar__title span { color: var(--primary); }
      /* Keep timeline navigation reachable while the feed scrolls. The
         mobile menu/search bar occupies the first row, so the timeline bar
         sticks directly beneath it. */
      .main > .topbar { position: sticky; top: calc(3.45rem + env(safe-area-inset-top)); z-index: 55; padding: .45rem .65rem; background: rgba(10,10,10,.98); }
      .main > .topbar h1 { display: none; }
      .main > .topbar .topbar-actions { width: 100%; justify-content: space-between; gap: .35rem; flex-wrap: nowrap; }
      .main > .topbar .timeline-tabs { flex: 0 1 auto; min-width: 0; max-width: calc(100% - 3rem); overflow-x: auto; justify-content: flex-start; scrollbar-width: none; }
      .main > .topbar .timeline-tabs::-webkit-scrollbar { display: none; }
      .main > .topbar .timeline-tabs a { flex: 0 0 auto; padding: .28rem .5rem; font-size: .74rem; }
      .main > .topbar .topbar-actions > .btn { flex: 0 0 2.25rem; width: 2.25rem; padding: .35rem 0; font-size: 0; }
      .main > .topbar .topbar-actions > .btn::before { content: '↻'; font-size: 1.05rem; }

      .mobile-nav-backdrop {
        display: block;
        position: fixed;
        inset: 0;
        z-index: 90;
        background: rgba(0,0,0,.55);
        backdrop-filter: blur(2px);
        opacity: 0;
        pointer-events: none;
        transition: opacity .18s ease;
      }
      .mobile-nav-backdrop.show {
        opacity: 1;
        pointer-events: auto;
      }

      .rail-left {
        position: fixed;
        top: 0;
        left: 0;
        bottom: 0;
        width: min(86vw, 300px);
        z-index: 100;
        height: 100%;
        max-height: 100dvh;
        display: flex;
        flex-direction: column;
        overflow-x: hidden;
        overflow-y: auto;
        overscroll-behavior: contain;
        border-right: 1px solid var(--border);
        border-bottom: none;
        transform: translateX(-105%);
        transition: transform .2s ease;
        padding-bottom: env(safe-area-inset-bottom);
        -webkit-overflow-scrolling: touch;
      }
      .rail-left.mobile-open { transform: translateX(0); box-shadow: 8px 0 32px rgba(0,0,0,.45); }

      /* Restore readable labels inside the drawer (tablet icon-rail hides them) */
      .rail-left .nav span.label,
      .rail-left .nav-group summary .label { display: initial !important; }
      .rail-left .brand .brand-ascii { display: block !important; }
      .rail-left .brand strong {
        letter-spacing: .28em !important; text-indent: .28em !important;
        font-size: .82rem !important; margin-top: .45rem !important;
      }
      .rail-left .nav-search-narrow { display: flex !important; }
      .rail-left .nav-badge {
        position: static !important;
        margin-left: auto !important;
        min-width: 1.15rem !important;
        height: 1.15rem !important;
        padding: 0 .35rem !important;
        font-size: .68rem !important;
      }

      .nav {
        display: flex;
        flex-direction: column;
        flex-wrap: nowrap;
        gap: .35rem;
        padding: .75rem;
      }
      .nav a, .nav-group summary { padding: .7rem .85rem; }
      .nav-group { min-width: 0; }
      .nav-group .nav-sub { padding-left: .55rem; }
      .brand { padding: 1rem .9rem .85rem; }

      .main { height: auto; overflow: visible; min-width: 0; }
      .feed {
        overflow: visible;
        padding: .85rem .85rem 5.5rem;
        max-width: 100%;
      }
      .topbar { padding: .85rem; }
      .tweet { padding: .85rem .15rem; }
      .tweet-hd-main { flex-direction: column; gap: .25rem; }
      .tweet-actions { gap: .45rem; }
      .tweet-actions .icon-btn,
      .tweet-actions .btn { min-height: 2.25rem; }
      .composer input, .composer textarea, .composer select { font-size: 16px; } /* avoid iOS zoom */
      .compose-fab {
        right: max(.85rem, env(safe-area-inset-right));
        bottom: max(.85rem, env(safe-area-inset-bottom));
      }
      .feed-top-btn {
        position: fixed;
        right: max(.85rem, env(safe-area-inset-right));
        bottom: max(5.25rem, calc(env(safe-area-inset-bottom) + 4.65rem));
        z-index: 75;
      }
      .link-card { max-width: 100%; }
      .link-card__media { flex-basis: 96px; }
      .wide, .feed.wide-feed { max-width: 100%; }
      .stats-grid, .profile-grid { grid-template-columns: 1fr !important; }
    }

    .rail-left, .rail-right {
      background: rgba(18,18,18,.92);
      border-right: 1px solid var(--border);
      backdrop-filter: blur(8px);
    }
    .rail-right { border-right: none; border-left: 1px solid var(--border); padding: 1rem; min-width: 0; }
    .rail-right a { color: var(--primary); }
    .rail-right a:hover { color: var(--primary); text-decoration: underline; }
    .rail-right a[style*="color:var(--text)"] { color: var(--primary) !important; }
    .brand {
      padding: 1.1rem 1rem .95rem;
      border-bottom: 1px solid var(--border);
      text-align: center;
    }
    .brand a.brand-link {
      display: block; text-decoration: none; color: inherit;
    }
    .brand .brand-ascii {
      margin: 0 auto; display: block;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: .52rem; line-height: 1.05;
      color: var(--primary);
      text-shadow: 0 0 18px color-mix(in srgb, var(--primary) 22%, transparent);
      white-space: pre;
      user-select: none;
    }
    .brand strong {
      display: block; margin-top: .45rem;
      color: var(--primary); letter-spacing: .28em;
      font-size: .82rem; font-weight: 700;
      text-indent: .28em;
    }
    .nav-label {
      margin: .85rem .85rem .25rem; font-size: .7rem; letter-spacing: .08em;
      text-transform: uppercase; color: #555;
    }
    .nav-sep {
      height: 1px; margin: .45rem .85rem;
      background: var(--border); border: 0;
    }
    .nav-group { margin: 0; }
    .nav-group summary {
      display: flex; align-items: center; gap: .7rem;
      padding: .7rem .85rem; border-radius: 999px; color: var(--muted);
      cursor: pointer; list-style: none; user-select: none;
      border: 1px solid transparent; font-size: .92rem;
    }
    .nav-group summary::-webkit-details-marker { display: none; }
    .nav-group summary:hover { background: var(--panel-2); color: var(--text); }
    .nav-group[open] > summary { color: var(--text); }
    .nav-group .nav-sub {
      display: flex; flex-direction: column; gap: .25rem;
      padding: .15rem 0 .35rem .55rem;
    }
    .nav-group .nav-sub a { padding: .55rem .85rem; font-size: .92rem; }
    .nav-search-narrow { display: none; }

    .nav { padding: .75rem; display: flex; flex-direction: column; gap: .35rem; }
    .nav a {
      display: flex; align-items: center; gap: .7rem;
      padding: .7rem .85rem; border-radius: 999px; color: var(--text);
      border: 1px solid transparent;
      position: relative;
    }
    .nav a:hover { background: var(--panel-2); text-decoration: none; }
    .nav a.active {
      background: var(--primary-dim);
      border-color: color-mix(in srgb, var(--primary) 35%, transparent);
      color: var(--primary);
      font-weight: 600;
    }
    .nav .ico { width: 1.25rem; text-align: center; opacity: .9; }
    .nav-badge {
      display: inline-flex; align-items: center; justify-content: center;
      min-width: 1.15rem; height: 1.15rem; padding: 0 .35rem;
      margin-left: auto;
      border-radius: 999px;
      background: var(--primary); color: #04140c;
      font-size: .68rem; font-weight: 700; line-height: 1;
      letter-spacing: 0;
    }
    .nav-badge[hidden] { display: none !important; }
    @media (max-width: 1100px) {
      .nav-badge {
        position: absolute; top: .2rem; right: .15rem; margin-left: 0;
        min-width: .9rem; height: .9rem; padding: 0 .22rem; font-size: .58rem;
      }
    }
    .cw-gate {
      margin: .35rem 0 .55rem;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: var(--panel-2);
      overflow: hidden;
    }
    .cw-gate > summary {
      list-style: none;
      cursor: pointer;
      padding: .65rem .85rem;
      display: flex;
      flex-wrap: wrap;
      gap: .35rem .75rem;
      align-items: baseline;
      color: var(--text);
      user-select: none;
    }
    .cw-gate > summary::-webkit-details-marker { display: none; }
    .cw-gate > summary .cw-label {
      color: var(--primary);
      font-weight: 700;
      font-size: .78rem;
      letter-spacing: .04em;
      text-transform: uppercase;
    }
    .cw-gate > summary .cw-text { color: var(--text); font-weight: 600; }
    .cw-gate > summary .cw-hint { color: var(--muted); font-size: .82rem; margin-left: auto; }
    .cw-gate[open] > summary .cw-hint::after { content: 'Hide'; }
    .cw-gate:not([open]) > summary .cw-hint::after { content: 'Show'; }
    .cw-gate .cw-body { padding: 0 .85rem .85rem; border-top: 1px solid var(--border); }
    .cw-gate .cw-body .body { margin-top: .65rem; }
    .dm-bubble {
      margin: .55rem 0 .35rem;
      padding: .75rem .9rem;
      border-radius: 14px;
      border: 1px solid var(--border);
      background: var(--panel-2);
      color: var(--text);
      white-space: pre-wrap;
      word-break: break-word;
      line-height: 1.45;
      font-size: .98rem;
      text-align: left;
      text-indent: 0;
    }
    .dm-bubble p { margin: .3rem 0; padding: 0; text-indent: 0; white-space: normal; }
    .dm-bubble p:first-child { margin-top: 0; }
    .dm-bubble p:last-child { margin-bottom: 0; }
    .dm-bubble > :first-child { margin-top: 0; }
    .dm-bubble > :last-child { margin-bottom: 0; }
    .dm-bubble ul,.dm-bubble ol { margin: .4rem 0 .2rem 1.1rem; padding: 0; text-indent: 0; }
    .dm-bubble.dm-out {
      background: rgba(0, 255, 159, .08);
      border-color: rgba(0, 255, 159, .28);
    }
    .dm-bubble.dm-in {
      background: #141814;
    }
    .dm-bubble a {
      color: var(--primary);
      text-decoration: underline;
      text-underline-offset: 2px;
      word-break: break-all;
    }
    .dm-bubble a:hover { filter: brightness(1.15); }
    .dm-bubble .dm-actions {
      margin: .65rem 0 0;
      padding-top: .55rem;
      border-top: 1px dashed var(--border);
      font-size: .9rem;
    }
    .dm-bubble li { margin: .25rem 0; }
    .dm-thread { display: flex; flex-direction: column; gap: .35rem; }
    .dm-thread .tweet { margin-bottom: 0; padding: .7rem .8rem; }
    .link-card {
      display: flex; gap: .75rem; margin: .65rem 0 0; padding: 0;
      border-radius: 12px; border: 1px solid var(--border); background: var(--panel-2);
      overflow: hidden; text-decoration: none; color: inherit; max-width: 520px;
    }
    .link-card:hover { border-color: color-mix(in srgb, var(--primary) 35%, transparent); text-decoration: none; }
    .link-card__media { flex: 0 0 120px; max-height: 120px; overflow: hidden; background: #0a0a0a; }
    .link-card__media img { width: 100%; height: 100%; object-fit: cover; display: block; }
    .link-card__body { padding: .65rem .75rem; min-width: 0; flex: 1; }
    .link-card__provider {
      font-size: .7rem; text-transform: uppercase; letter-spacing: .05em;
      color: var(--muted); margin-bottom: .2rem;
    }
    .link-card__title { font-size: .92rem; font-weight: 600; color: var(--text); line-height: 1.3; overflow-wrap: anywhere; }
    .link-card__desc {
      font-size: .8rem; color: var(--muted); margin-top: .25rem; line-height: 1.35;
      display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden;
    }

    .main { min-width: 0; }
    .topbar {
      position: sticky; top: 0; z-index: 10;
      display: flex; justify-content: space-between; align-items: center;
      flex-wrap: nowrap;
      gap: .75rem 1rem; padding: 1rem 1.25rem;
      background: rgba(10,10,10,.85); border-bottom: 1px solid var(--border);
      backdrop-filter: blur(10px);
    }
    /* Keep the view title on one line — min-width:0 + flex-shrink was wrapping
       "Federated" as F / ederated when the action cluster got wide. */
    .topbar h1 {
      margin: 0;
      font-size: 1.15rem;
      flex: 0 0 auto;
      white-space: nowrap;
      line-height: 1.2;
    }
    .topbar-timeline h1 { display: none; }
    .topbar-actions {
      display: flex; align-items: center; justify-content: flex-end;
      gap: .55rem; flex: 1 1 auto; flex-wrap: wrap; min-width: 0;
    }
    .topbar-timeline .topbar-actions { position: relative; justify-content: center; }
    .topbar-timeline .topbar-actions > .timeline-tabs { margin-inline: auto; }
    .topbar-timeline .topbar-actions > .btn { position: absolute; right: 0; width: 2.4rem; height: 2.25rem; min-height: 0; padding: 0; display: inline-flex; align-items: center; justify-content: center; font-size: 0; line-height: 1; }
    .topbar-timeline .topbar-actions > .btn::before { content: '↻'; display: block; font-size: 1.2rem; line-height: 1; }
    .pill {
      display: inline-flex; gap: .5rem; align-items: center;
      padding: .35rem .7rem; border-radius: 999px;
      background: var(--panel-2); border: 1px solid var(--border);
      color: var(--muted); font-size: .85rem;
      white-space: nowrap;
    }
    .pill b { color: var(--primary); font-weight: 600; }

    .feed { padding: 1rem 1.25rem 3rem; max-width: 720px; }
    .feed.wide-feed { max-width: 920px; }

    .composer, .card, .tweet {
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: var(--radius);
      box-shadow: var(--shadow);
    }
    .composer { padding: 1rem; margin-bottom: 1rem; }
    .composer textarea {
      width: 100%; min-height: 96px; max-height: 500px; resize: none;
      box-sizing: border-box;
      background: #0c0c0c; color: var(--text);
      border: 1px solid var(--border); border-radius: 10px;
      padding: .75rem; font: inherit;
    }
    .composer input:not([type="checkbox"]):not([type="radio"]):not([type="hidden"]) {
      width: 100%; margin-top: .5rem;
      background: #0c0c0c; color: var(--text);
      border: 1px solid var(--border); border-radius: 10px;
      padding: .6rem .75rem; font: inherit;
    }
    .composer-check {
      display: flex;
      align-items: center;
      gap: .55rem;
      margin: .65rem 0 .15rem;
      font-size: .88rem;
      color: var(--muted);
      line-height: 1.3;
      white-space: nowrap;
      flex-wrap: nowrap;
    }
    .compose-emoji-picker {
      display: grid;
      grid-template-columns: repeat(8, minmax(0, 1fr));
      gap: .25rem;
      margin-top: .45rem;
      padding: .55rem;
      max-height: 11rem;
      overflow: auto;
      border: 1px solid var(--border);
      border-radius: 12px;
      background: #0c0c0c;
    }
    .compose-inline-panel .compose-emoji-wrap { position: relative; }
    .compose-inline-panel .compose-emoji-picker {
      position: absolute; left: 0; bottom: calc(100% + .5rem); z-index: 70;
      width: min(23rem, calc(100vw - 2rem)); box-sizing: border-box;
      grid-template-columns: repeat(8, minmax(0, 1fr));
    }
    .compose-emoji-search {
      grid-column: 1 / -1; width: 100%; box-sizing: border-box;
      margin: 0 0 .25rem; padding: .45rem .55rem;
      background: #111; color: var(--text); border: 1px solid var(--border);
      border-radius: 8px; font: inherit; font-size: .85rem;
    }
    .compose-emoji-picker[hidden] { display: none !important; }
    .compose-emoji-picker button {
      appearance: none;
      border: 0;
      background: transparent;
      border-radius: 8px;
      font-size: 1.25rem;
      line-height: 1.45;
      cursor: pointer;
      padding: .15rem;
    }
    .compose-emoji-picker button:hover,
    .compose-emoji-picker button:focus-visible {
      background: rgba(126,224,255,.12);
      outline: none;
    }
    .composer-check input[type="checkbox"] {
      width: 1rem;
      height: 1rem;
      margin: 0;
      flex: 0 0 auto;
      accent-color: var(--primary);
    }
    .composer-actions {
      display: flex; justify-content: space-between; align-items: center;
      gap: 1rem; margin-top: .75rem; flex-wrap: wrap;
    }
    .compose-submit-progress {
      display: none; flex: 1 1 100%; gap: .5rem; align-items: center;
      color: var(--muted); font-size: .78rem;
    }
    .compose-submit-progress.is-visible { display: flex; }
    .compose-submit-progress progress {
      width: min(15rem, 100%); height: .45rem; accent-color: var(--primary);
    }
    .compose-submit-progress.is-indeterminate progress { opacity: .55; }
    .compose-modal__panel > .composer > .meta,
    .compose-modal__panel > .composer > .quote-block,
    .compose-modal__panel > .composer > .composer-check,
    .compose-modal__panel > .composer > .compose-media-row,
    .compose-modal__panel > .composer > .composer-actions,
    .compose-modal__panel > .composer > label,
    .compose-modal__panel > .composer > input:not([type="hidden"]) {
      flex: 0 0 auto;
    }
    /* Keep Post/Queue reachable when the compose panel scrolls on small screens */
    .compose-modal__panel > .composer > .composer-actions {
      position: sticky;
      bottom: 0;
      z-index: 2;
      background: var(--panel);
      padding-top: .55rem;
      padding-bottom: max(.35rem, env(safe-area-inset-bottom, 0px));
      margin-bottom: 0;
      border-top: 1px solid rgba(255,255,255,.06);
    }
    /* Normal timeline posting uses an inline, X-style composer at the feed
       top. Focused reply/quote/edit flows continue using the modal shell. */
    .compose-inline-shell { display: none !important; }
    .compose-inline-panel {
      width: 100% !important; max-width: none !important; padding: 0 !important;
      margin: 0 0 .75rem !important;
      background: transparent !important; border: 0 !important;
      border-radius: 0; box-shadow: none !important;
    }
    .compose-inline-panel > .compose-modal__hd { display: none; }
    .compose-inline-panel > .composer {
      position: relative; margin: 0; padding: .75rem; overflow: visible;
      border-radius: var(--radius);
    }
    .compose-inline-panel .composer textarea { min-height: 5.5rem; }
    .compose-inline-panel .composer textarea { overflow-y: hidden; }
    .compose-inline-panel .compose-textarea-wrap {
      flex: 0 0 auto; max-height: none; overflow: visible;
    }
    .compose-inline-panel #compose-content {
      height: auto; max-height: 500px; field-sizing: content;
      overscroll-behavior: contain; -webkit-overflow-scrolling: touch;
    }
    .compose-inline-panel .compose-inline-options {
      margin-top: .55rem; border-top: 1px solid var(--border);
      border-bottom: 1px solid var(--border);
    }
    .compose-inline-panel .compose-inline-options > summary {
      cursor: pointer; list-style: none; padding: .45rem 0;
      color: var(--muted); font-size: .86rem; font-weight: 600;
    }
    .compose-inline-panel .compose-inline-options > summary::-webkit-details-marker { display: none; }
    .compose-inline-panel .compose-inline-options > summary::before { content: '＋ '; color: var(--primary); }
    .compose-inline-panel .compose-inline-options[open] > summary::before { content: '− '; }
    .compose-inline-panel .compose-inline-options-body { padding: 0 0 .35rem; }
    .compose-inline-panel .compose-inline-visibility {
      display: inline-flex !important; flex-direction: row !important;
      align-items: center; gap: .35rem;
      position: absolute; top: .25rem; right: .75rem;
      margin: 0; color: var(--muted); font-size: .82rem;
    }
    .compose-inline-panel .composer > .meta[style*="margin-bottom"] {
      display: flex !important; align-items: center; width: 100%;
      column-gap: .3rem;
    }
    .compose-inline-panel .composer > .meta[style*="margin-bottom"] > b { margin-left: .2rem; }
    .compose-inline-panel .composer > input[name="spoiler_text"] { margin-top: 1rem !important; }
    .compose-inline-panel .compose-inline-visibility select {
      width: auto; max-width: 10rem; margin: 0; padding: .25rem .45rem;
      font-size: .82rem;
    }
    .compose-inline-panel .compose-inline-tools {
      display: flex; align-items: center; gap: .45rem;
      margin-top: .65rem;
    }
    .compose-inline-panel .compose-inline-tools .compose-emoji-wrap { margin: 0 !important; }
    .compose-inline-panel .compose-inline-tools .composer-check {
      margin: 0; padding: 0; width: auto; position: relative;
    }
    .compose-inline-panel .compose-inline-tools .composer-check input {
      position: absolute; width: 1px; height: 1px; opacity: 0; pointer-events: none;
    }
    .compose-inline-panel .compose-tool {
      width: 2.25rem; height: 2.25rem; padding: 0;
      display: inline-flex; align-items: center; justify-content: center;
      border: 1px solid var(--border); border-radius: 999px;
      background: transparent; color: var(--muted); cursor: pointer;
      font-size: 1.15rem;
    }
    .compose-inline-panel .compose-tool.is-recording {
      width: auto; min-width: 8.5rem; padding: 0 .7rem;
      gap: .35rem; white-space: nowrap; font-size: .8rem;
    }
    .compose-inline-panel .compose-tool:hover,
    .compose-inline-panel .compose-tool[aria-pressed="true"] {
      color: var(--primary); border-color: var(--primary);
      background: var(--primary-dim);
    }
    .compose-inline-panel .compose-media-tool { margin: 0; }
    .compose-inline-panel .compose-media-tool input { display: none; }
    .compose-inline-panel .compose-options-legacy { display: none !important; }
    .compose-inline-slot { width: 100%; margin: 0 0 .75rem; }
    .compose-inline-skeleton {
      height: 12rem; border: 1px solid var(--border); border-radius: var(--radius);
      background: var(--panel); opacity: .72;
      background-image: linear-gradient(90deg, transparent, rgba(255,255,255,.045), transparent);
      background-size: 200% 100%; animation: compose-skeleton 1.4s ease-in-out infinite;
    }
    @keyframes compose-skeleton { from { background-position: 200% 0; } to { background-position: -200% 0; } }
    .compose-inline-panel .composer-actions {
      margin-top: .75rem; padding-top: .7rem;
      border-top: 1px solid var(--border);
    }
    /* Floating compose FAB + modal */
    .compose-fab {
      position: fixed; right: 1.25rem; bottom: 1.25rem; z-index: 80;
      width: 3.4rem; height: 3.4rem; border-radius: 999px;
      border: none; cursor: pointer;
      background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 78%, #000));
      color: #04140c; font-size: 1.75rem; font-weight: 700; line-height: 1;
      box-shadow: 0 8px 28px rgba(0, 255, 159, 0.28);
    }
    .compose-fab:hover { filter: brightness(1.06); }
    /* The composer is inline at the feed top; keep the floating FAB out of
       the way so the back-to-top control owns the lower corner. */
    .compose-fab { display: none !important; }
    .main { position: relative; }
    .feed-top-btn {
      /* Bottom-right of the feed column (not the viewport FAB corner) */
      position: absolute; right: 1.1rem; bottom: 1.1rem; z-index: 40;
      width: 2.6rem; height: 2.6rem; border-radius: 999px;
      border: 1px solid var(--border); cursor: pointer;
      background: rgba(18,18,18,.94); color: var(--primary);
      font-size: 1.15rem; line-height: 1;
      box-shadow: 0 6px 20px rgba(0,0,0,.35);
      opacity: 0; pointer-events: none; transform: translateY(8px);
      transition: opacity .18s ease, transform .18s ease;
    }
    .feed-top-btn.show {
      opacity: 1; pointer-events: auto; transform: translateY(0);
    }
    .feed-top-btn:hover { border-color: var(--primary); }
    @media (max-width: 700px) {
      /* Keep this above the compose FAB in the viewport, not at the end of
         the document's feed column. */
      .main .feed-top-btn {
        position: fixed;
        right: max(.85rem, env(safe-area-inset-right));
        bottom: max(1rem, env(safe-area-inset-bottom));
        z-index: 75;
        width: 2.9rem; height: 2.9rem;
        background: var(--primary); color: #04140c;
        border-color: var(--primary);
      }
    }
    .feed-new-btn {
      display: block; width: 100%;
      padding: .7rem 1rem; margin: 0 0 .75rem;
      border: 0; border-top: 1px solid var(--border); border-bottom: 1px solid var(--border);
      border-radius: 0; cursor: pointer;
      background: transparent; color: var(--primary);
      font: inherit; font-size: .85rem; font-weight: 600;
      opacity: 0; pointer-events: none;
      transition: opacity .18s ease, background .18s ease;
      white-space: nowrap;
    }
    .feed-new-btn[hidden] { display: none !important; }
    .feed-new-btn.show {
      opacity: 1; pointer-events: auto;
    }
    .feed-new-btn:hover { background: var(--primary-dim); }
    .feed-ptr {
      display: flex; align-items: center; justify-content: center;
      gap: .45rem; height: 0; overflow: hidden;
      color: var(--muted); font-size: .85rem; font-weight: 600;
      transition: height .12s ease, opacity .12s ease;
      opacity: 0; pointer-events: none;
    }
    .feed-ptr.show { opacity: 1; }
    .feed-ptr.ready { color: var(--primary); }
    .compose-modal {
      position: fixed; inset: 0; z-index: 90;
      display: none; align-items: flex-end; justify-content: center;
      padding: 1rem; background: rgba(0,0,0,.55); backdrop-filter: blur(4px);
    }
    .compose-modal.open { display: flex; }
    .compose-modal__panel {
      width: min(560px, 100%);
      max-height: min(90dvh, 720px);
      display: flex;
      flex-direction: column;
      overflow: hidden; /* desktop: resize textarea — don't spawn a panel scrollbar */
      background: var(--panel);
      border: 1px solid var(--border);
      border-radius: 16px 16px 12px 12px;
      box-shadow: 0 20px 60px rgba(0,0,0,.45);
      padding: 1rem 1.1rem 1.15rem;
      margin-bottom: .5rem;
      box-sizing: border-box;
    }
    .compose-modal__panel > .composer {
      flex: 1 1 auto;
      min-height: 0;
      margin-bottom: 0;
      display: flex;
      flex-direction: column;
      overflow: hidden; /* desktop: never scroll the form — textarea is capped instead */
    }
    @media (max-width: 700px) {
      .compose-modal__panel {
        max-height: min(90dvh, 720px);
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
      }
      .compose-modal__panel > .composer {
        overflow: visible;
        overflow-y: auto;
        -webkit-overflow-scrolling: touch;
        max-height: none;
      }
    }
    /* Flex slot for the body: resize can grow only within remaining modal space */
    .compose-textarea-wrap {
      flex: 1 1 0;
      min-height: 6rem;
      max-height: 100%;
      overflow: hidden;
      display: flex;
      flex-direction: column;
      margin: 0;
    }
    .compose-modal #compose-content {
      flex: 1 1 auto;
      width: 100%;
      height: 100%;
      min-height: 6rem;
      max-height: 100%;
      resize: vertical;
      overflow-y: auto; /* long text scrolls inside the box, not the modal */
      field-sizing: fixed;
      box-sizing: border-box;
    }
    @media (min-width: 701px) {
      .compose-modal { align-items: center; }
      .compose-modal__panel { border-radius: 16px; margin-bottom: 0; }
    }
    .compose-modal__hd {
      display: flex; justify-content: space-between; align-items: center;
      gap: 1rem; margin-bottom: .75rem;
      flex: 0 0 auto;
    }
    .compose-modal__hd h2 { margin: 0; font-size: 1.05rem; }
    .compose-modal__close {
      appearance: none; border: 1px solid var(--border); background: transparent;
      color: var(--muted); border-radius: 999px; width: 2rem; height: 2rem;
      cursor: pointer; font-size: 1.1rem; line-height: 1;
    }
    .compose-media-row {
      display: flex; flex-wrap: wrap; gap: .65rem; margin: .65rem 0 .25rem;
    }
    .compose-media-card {
      width: 7.5rem; background: #0c0c0c; border: 1px solid var(--border);
      border-radius: 10px; overflow: hidden; padding: .35rem;
    }
    .compose-media-card img, .compose-media-card video {
      display: block; width: 100%; height: 5rem; object-fit: cover; border-radius: 6px;
    }
    .compose-media-card .alt-btn {
      display: block; width: 100%; margin-top: .35rem;
      font-size: .72rem; padding: .35rem .4rem; border-radius: 6px;
      background: #111; color: var(--muted); border: 1px solid var(--border);
      cursor: pointer; text-align: left; white-space: nowrap;
      overflow: hidden; text-overflow: ellipsis;
    }
    .compose-media-card .alt-btn.has-alt {
      color: var(--primary); border-color: color-mix(in srgb, var(--primary) 35%, transparent);
    }
    .compose-media-card .alt-btn:hover {
      border-color: color-mix(in srgb, var(--primary) 45%, transparent); color: var(--text);
    }
    .compose-media-card .rm {
      display: block; width: 100%; margin-top: .25rem; font-size: .7rem;
      background: transparent; border: none; color: #f88; cursor: pointer;
    }
    /* Alt-text editor popout (above compose modal) */
    .alt-modal {
      display: none; position: fixed; inset: 0; z-index: 100;
      background: rgba(0,0,0,.72); align-items: flex-end; justify-content: center;
      padding: .75rem;
    }
    .alt-modal.open { display: flex; }
    .alt-modal__panel {
      width: min(560px, 100%);
      max-height: min(85vh, 640px);
      background: var(--panel); border: 1px solid var(--border);
      border-radius: 16px 16px 12px 12px; box-shadow: var(--shadow);
      display: flex; flex-direction: column; overflow: hidden;
      margin-bottom: .25rem;
    }
    @media (min-width: 701px) {
      .alt-modal { align-items: center; }
      .alt-modal__panel { border-radius: 16px; margin-bottom: 0; }
    }
    .alt-modal__hd {
      display: flex; align-items: center; justify-content: space-between;
      gap: .75rem; padding: .85rem 1rem; border-bottom: 1px solid var(--border);
    }
    .alt-modal__hd h3 { margin: 0; font-size: 1rem; }
    .alt-modal__body {
      padding: 1rem; overflow: auto; flex: 1 1 auto;
      display: flex; flex-direction: column; gap: .75rem;
    }
    .alt-modal__preview {
      width: 100%; max-height: 160px; object-fit: contain;
      border-radius: 10px; border: 1px solid var(--border); background: #0a0a0a;
    }
    .alt-modal__body textarea {
      width: 100%; min-height: 10rem; resize: vertical;
      background: #0c0c0c; color: var(--text);
      border: 1px solid var(--border); border-radius: 10px;
      padding: .75rem; font: inherit; line-height: 1.45;
    }
    .alt-modal__ft {
      display: flex; justify-content: space-between; align-items: center;
      gap: .75rem; padding: .75rem 1rem; border-top: 1px solid var(--border);
      flex-wrap: wrap;
    }
    .btn {
      appearance: none; border: none; cursor: pointer;
      padding: .65rem 1.1rem; border-radius: 999px;
      font-weight: 600; font: inherit;
    }
    .btn-primary {
      background: linear-gradient(135deg, var(--primary), color-mix(in srgb, var(--primary) 78%, #000));
      color: #04140c;
    }
    .btn-primary:hover { filter: brightness(1.05); }
    .btn-ghost {
      background: transparent; color: var(--muted);
      border: 1px solid var(--border);
    }

    .tweet {
      padding: 1rem 1.1rem; margin-bottom: .75rem;
      transition: border-color .15s ease;
    }
    .tweet:hover { border-color: #3a3a3a; }

    /* Gallery — Instagram-style media grid */
    .gallery-grid {
      display: grid;
      grid-template-columns: repeat(3, 1fr);
      gap: 3px;
      margin: 0 0 .5rem;
    }
    @media (max-width: 720px) {
      .gallery-grid { grid-template-columns: repeat(2, 1fr); }
    }
    .gallery-cell {
      position: relative;
      display: block;
      aspect-ratio: 1 / 1;
      overflow: hidden;
      background: #111;
      border-radius: 4px;
      text-decoration: none;
      color: inherit;
    }
    .gallery-cell:hover .gallery-thumb { transform: scale(1.04); }
    .gallery-thumb {
      width: 100%; height: 100%;
      object-fit: cover; display: block;
      transition: transform .2s ease;
      background: #1a1a1a;
    }
    .gallery-badge {
      position: absolute; top: .4rem; right: .4rem;
      background: rgba(0,0,0,.65); color: #fff;
      font-size: .72rem; font-weight: 700;
      padding: .15rem .4rem; border-radius: 6px;
      line-height: 1.2;
    }
    .gallery-who {
      position: absolute; left: 0; right: 0; bottom: 0;
      padding: 1.4rem .45rem .35rem;
      background: linear-gradient(transparent, rgba(0,0,0,.75));
      color: #fff; font-size: .72rem;
      white-space: nowrap; overflow: hidden; text-overflow: ellipsis;
      opacity: 0; transition: opacity .15s ease;
    }
    .gallery-cell:hover .gallery-who { opacity: 1; }
    .gallery-cell-cw .gallery-thumb {
      filter: blur(18px) brightness(.55);
      transform: scale(1.12);
    }
    .gallery-cw {
      position: absolute; inset: 0;
      display: flex; align-items: center; justify-content: center;
      text-align: center; padding: .75rem;
      color: #fff; font-size: .8rem; font-weight: 600;
      background: rgba(0,0,0,.25);
      pointer-events: none;
    }
    .vakktok-feed { height: calc(100vh - 7rem); min-height: 28rem; overflow-y: auto; scroll-snap-type: y mandatory; overscroll-behavior: contain; background: #050505; border: 1px solid var(--border); border-radius: 12px; }
    .vakktok-item { position: relative; height: 100%; min-height: 28rem; scroll-snap-align: start; display: grid; place-items: center; background: #050505; }
    .vakktok-video { display: block; width: 100%; height: 100%; min-width: 0; min-height: 0; max-width: 100%; max-height: 100%; object-fit: contain; object-position: center; aspect-ratio: auto; background: #000; }
    .tweet-hd {
      display: flex; align-items: flex-start; gap: .75rem;
      margin-bottom: .45rem;
    }
    .tweet-hd-main {
      flex: 1; min-width: 0;
      display: flex; justify-content: space-between; gap: 1rem;
    }
    .tweet-av {
      width: 40px; height: 40px; border-radius: 50%;
      object-fit: cover; flex-shrink: 0;
      background: #1a1a1a; border: 1px solid var(--border);
    }
    .who { font-weight: 600; }
    .vanity-verified {
      display: inline-flex; align-items: center; justify-content: center;
      width: 1.05em; height: 1.05em; margin-left: .15rem;
      border-radius: 50%; background: #1d9bf0; color: #fff;
      font-size: .72em; font-weight: 800; line-height: 1;
      vertical-align: middle; position: relative; top: -0.05em;
    }
    .who .custom-emoji, .tweet-hd .custom-emoji, .body .custom-emoji, .feed-body .custom-emoji {
      width: 1.25em; height: 1.25em; vertical-align: -0.25em;
      object-fit: contain; display: inline;
    }
    .meta { color: var(--muted); font-size: .85rem; }
    .body, .feed-body {
      white-space: pre-wrap; word-break: break-word; line-height: 1.45;
    }
    /* Modest gap between paragraphs (HTML from compose / remotes) */
    .body p, .feed-body p {
      margin: 0 0 0.75em;
    }
    .body p:last-child, .feed-body p:last-child {
      margin-bottom: 0;
    }
    .feed-body a.mention,
    .feed-body a.hashtag,
    .feed-body a.ext-link,
    .quote-block a.mention,
    .quote-block a.hashtag,
    .quote-block a.ext-link {
      color: var(--primary);
      text-decoration: none;
      font-weight: 600;
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    .feed-body a.mention:hover,
    .feed-body a.hashtag:hover,
    .feed-body a.ext-link:hover,
    .quote-block a.mention:hover,
    .quote-block a.hashtag:hover,
    .quote-block a.ext-link:hover {
      text-decoration: underline;
      text-underline-offset: 2px;
    }
    .tags { display: flex; flex-wrap: wrap; gap: .4rem; margin-top: .75rem; }
    .tag {
      font-size: .75rem; padding: .2rem .55rem; border-radius: 999px;
      background: #182018; color: #9fd; border: 1px solid #243824;
    }
    .tweet-actions { display: flex; flex-wrap: wrap; gap: .35rem; margin-top: .8rem; align-items: center; overflow: visible; }
    .tweet-actions a, .tweet-actions button {
      font-size: .85rem; color: var(--muted);
      background: none; border: none; cursor: pointer; padding: .25rem .4rem;
    }
    .tweet-actions a:hover, .tweet-actions button:hover { color: var(--primary); }
    .tweet-actions .icon-btn {
      width: 2rem; height: 2rem; padding: 0;
      display: inline-flex; align-items: center; justify-content: center;
      border-radius: 999px; border: 1px solid transparent;
      font-size: 1rem; line-height: 1;
    }
    .tweet-actions .icon-btn:hover { border-color: transparent; background: transparent; }
    .tweet-actions .icon-btn.on { color: var(--primary); }
    .post-action-menu { position: relative; display: inline-block; z-index: 2; }
    .post-action-menu[open] { z-index: 80; }
    .post-action-menu > summary { list-style: none; cursor: pointer; }
    .post-action-menu > summary::-webkit-details-marker { display: none; }
    /* Closed: stays inside <details> (UA hides it). Open: JS portals to body. */
    .post-action-menu__body {
      position: absolute; z-index: 80; left: 100%; top: 100%;
      margin: 6px 0 0 6px;
      min-width: 12.5rem; max-width: min(90vw, 18rem);
      max-height: min(70vh, 22rem); overflow-x: hidden; overflow-y: auto;
      padding: .3rem; border: 1px solid var(--border);
      border-radius: 8px; background: #151515; box-shadow: 0 8px 24px rgba(0,0,0,.45);
    }
    .post-action-menu__body.is-portaled {
      position: fixed !important;
      z-index: 12000 !important;
      display: block !important;
      margin: 0 !important;
      right: auto !important;
      bottom: auto !important;
    }
    .post-action-menu__body form { margin: 0; }
    .post-action-menu__body .menu-action-sep {
      height: 1px; margin: .35rem .55rem; background: var(--border); border: 0;
    }
    .post-action-menu__body .menu-action {
      display: block; width: 100%; padding: .5rem .65rem; border: 0; border-radius: 5px;
      background: transparent; color: var(--text); text-align: left; font: inherit; cursor: pointer;
      text-decoration: none; white-space: nowrap;
    }
    .post-action-menu__body .menu-action:hover { background: #242424; color: var(--primary); }
    .post-action-menu__body .menu-action.danger { color: var(--danger); }
    .tweet.tweet-focus {
      outline: 2px solid var(--primary);
      outline-offset: 2px;
      background: #142014;
    }
    /* Breathing room under ← Home / back on detail pages */
    .page-back,
    .status-back {
      display: flex;
      align-items: center;
      gap: .5rem;
      margin: 0 0 1.25rem;
      padding-bottom: .85rem;
      border-bottom: 1px solid var(--border);
    }
    .page-back .btn,
    .status-back .btn {
      margin: 0;
    }
    .status-back + .tweet.tweet-focus,
    .status-back + .meta,
    .page-back + .tweet,
    .page-back + .meta,
    .page-back + .empty,
    .page-back + .composer,
    .page-back + .side-card {
      margin-top: .85rem;
    }
    .feed-toolbar:empty { display: none; }
    .tweet-notif-like .quote-block,
    .tweet-notif-boost .quote-block {
      margin-top: .55rem;
    }

    .stat-grid {
      display: grid; grid-template-columns: 1fr 1fr; gap: .6rem; margin-bottom: 1rem;
    }
    .stat {
      background: var(--panel-2); border: 1px solid var(--border);
      border-radius: 12px; padding: .75rem;
    }
    .stat .n { font-size: 1.35rem; color: var(--primary); font-weight: 700; }
    .stat .l { color: var(--muted); font-size: .8rem; }

    .side-card {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); padding: .9rem; margin-bottom: .75rem;
      /* Grid/flex kids default min-width:auto — long unbroken URLs otherwise
         expand the whole right rail past the viewport. */
      min-width: 0;
      max-width: 100%;
      overflow-wrap: anywhere;
      word-break: break-word;
    }
    .side-card h3 {
      margin: 0 0 .6rem; font-size: .85rem; color: var(--muted);
      text-transform: uppercase; letter-spacing: .06em;
    }
    .trend-link {
      margin: 0 0 .55rem;
      line-height: 1.35;
      min-width: 0;
      max-width: 100%;
    }
    .trend-link a {
      display: inline-block;
      max-width: 100%;
      /* Break inside long path/query strings that have no spaces */
      overflow-wrap: anywhere;
      word-break: break-all;
    }
    .trend-link .trend-link-host {
      display: block;
      font-size: .72rem;
      color: var(--muted);
      margin-top: .15rem;
      overflow-wrap: anywhere;
      word-break: break-all;
    }
    details.side-drop {
      padding: 0;
    }
    details.side-drop > summary {
      list-style: none; cursor: pointer; user-select: none;
      padding: .9rem; font-size: .85rem; color: var(--muted);
      text-transform: uppercase; letter-spacing: .06em;
      display: flex; align-items: center; justify-content: space-between; gap: .5rem;
    }
    details.side-drop > summary::-webkit-details-marker { display: none; }
    details.side-drop > summary::after {
      content: '▸'; color: var(--muted); font-size: .75rem; transition: transform .15s ease;
    }
    details.side-drop[open] > summary::after { transform: rotate(90deg); }
    details.side-drop > summary:hover { color: var(--text); }
    details.side-drop .side-drop-body {
      padding: 0 .9rem .9rem; display: flex; flex-direction: column; gap: .45rem;
    }
    details.side-drop .side-drop-body a { color: var(--muted); font-size: .9rem; }
    details.side-drop .side-drop-body a:hover { color: var(--primary); }
    .bar-row { display: flex; justify-content: space-between; gap: .5rem; font-size: .85rem; margin: .3rem 0; }
    .bar-row span:last-child { color: var(--primary); }

    .flash {
      margin: 0 1.25rem 1rem; padding: .75rem 1rem; border-radius: 10px;
    }
    .flash.ok { background: #0a2a18; border: 1px solid #1f5a3a; color: #b6f5d0; }
    .flash.err { background: #2a1010; border: 1px solid #5a2a2a; color: #ffc9c9; }
    .bm-folder-popover {
      position: fixed; z-index: 80; min-width: 220px; max-width: min(320px, 92vw);
      background: var(--panel); border: 1px solid var(--border); border-radius: 12px;
      box-shadow: 0 12px 40px rgba(0,0,0,.45); padding: .65rem .7rem; color: var(--text);
    }
    .bm-folder-popover h4 { margin: 0 0 .45rem; font-size: .85rem; }
    .bm-folder-popover label {
      display: flex; gap: .45rem; align-items: center; padding: .28rem 0;
      font-size: .86rem; cursor: pointer;
    }
    .bm-folder-popover .bm-folder-actions {
      display: flex; gap: .4rem; flex-wrap: wrap; margin-top: .55rem;
    }
    .bm-folder-popover input[type="text"] {
      width: 100%; margin-top: .35rem; padding: .35rem .5rem;
      border-radius: 8px; border: 1px solid var(--border); background: #121212; color: var(--text);
    }
    .ap-toast-stack {
      position: fixed; bottom: 1.4rem; left: 50%; transform: translateX(-50%);
      z-index: 12000; display: flex; flex-direction: column; gap: .45rem;
      align-items: center; pointer-events: none; max-width: min(92vw, 28rem);
    }
    .ap-toast {
      pointer-events: auto; padding: .7rem 1.05rem; border-radius: 10px;
      font-size: .9rem; line-height: 1.35; box-shadow: 0 10px 28px rgba(0,0,0,.45);
      background: #0a2a18; border: 1px solid #1f5a3a; color: #b6f5d0;
      animation: ap-toast-in .18s ease-out;
    }
    .ap-toast.err { background: #2a1010; border-color: #5a2a2a; color: #ffc9c9; }
    @keyframes ap-toast-in {
      from { opacity: 0; transform: translateY(10px); }
      to { opacity: 1; transform: translateY(0); }
    }
    .empty { color: var(--muted); padding: 2rem 1rem; text-align: center; }
    .mono { font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size: .8rem; color: var(--muted); word-break: break-all; }
    .gb-item, .fin-row {
      background: var(--panel); border: 1px solid var(--border);
      border-radius: var(--radius); padding: 1rem; margin-bottom: .65rem;
    }
    .gb-hd { display: flex; justify-content: space-between; gap: 1rem; flex-wrap: wrap; margin-bottom: .4rem; }
    .danger { color: var(--danger); }
    .search {
      width: 100%; margin-bottom: .75rem; padding: .65rem .8rem;
      background: #0c0c0c; border: 1px solid var(--border); border-radius: 10px;
      color: var(--text); font: inherit;
    }
    .wide { max-width: 920px; }

    .feed-body {
      white-space: pre-wrap;
      line-height: 1.45;
    }
    /* Let the browser skip layout/paint work for cards far outside the viewport
       while retaining their DOM, controls, and scroll position. */
    article.tweet { content-visibility: auto; contain-intrinsic-size: 420px; }
    /* Long posts: fold text + media + cards together (no inner scrollbar) */
    .tweet-content {
      position: relative;
      margin: .15rem 0 .1rem;
    }
    .tweet-content.is-collapsed {
      max-height: 18rem;
      overflow: hidden;
    }
    .tweet-content.is-collapsed::after {
      content: '';
      position: absolute;
      left: 0; right: 0; bottom: 0;
      height: 4.5rem;
      pointer-events: none;
      background: linear-gradient(to bottom, rgba(18,18,18,0), var(--panel) 88%);
    }
    .tweet-content-more {
      display: none;
      margin: .35rem 0 0;
      padding: .35rem .7rem;
      font: inherit;
      font-size: .85rem;
      font-weight: 600;
      color: var(--primary);
      background: transparent;
      border: 1px solid var(--border);
      border-radius: 999px;
      cursor: pointer;
    }
    .tweet-content-more:hover {
      border-color: color-mix(in srgb, var(--primary) 45%, transparent);
      background: var(--primary-dim);
    }
    .tweet-content.has-fold + .tweet-content-more,
    .tweet-content-more.is-visible {
      display: inline-flex;
      align-items: center;
    }
    /* Mastodon-like: media fills the tweet content width */
    .media-row {
      display: grid;
      gap: .28rem;
      margin-top: .75rem;
      width: 100%;
      max-width: 100%;
      border-radius: 12px;
      overflow: hidden;
      border: 1px solid var(--border);
      background: #0a0a0a;
      box-sizing: border-box;
    }
    .media-row.media-count-1 { grid-template-columns: 1fr; }
    .media-row.media-count-2 { grid-template-columns: 1fr 1fr; }
    .media-row.media-count-3 {
      grid-template-columns: 1fr 1fr;
      grid-template-rows: minmax(140px, 1fr) minmax(140px, 1fr);
    }
    .media-row.media-count-3 > :first-child {
      grid-row: 1 / span 2;
    }
    .media-row.media-count-4 {
      grid-template-columns: 1fr 1fr;
      grid-template-rows: minmax(140px, 1fr) minmax(140px, 1fr);
    }
    .media-row a, .media-row .media-lightbox-trigger {
      display: block; width: 100%; height: 100%; min-height: 0; min-width: 0;
      padding: 0; margin: 0; border: 0; background: #0c0c0c; cursor: zoom-in;
      overflow: hidden;
    }
    .media-row img, .media-row .media-video {
      display: block; width: 100%; height: 100%;
      max-height: min(58vh, 520px);
      object-fit: cover; background: #0c0c0c;
    }
    /* Single image: show full frame (letterbox ok) rather than cropping */
    .media-row.media-count-1 img {
      object-fit: contain;
      max-height: min(62vh, 560px);
      min-height: 180px;
    }
    .media-row .media-video {
      max-height: min(62vh, 560px);
      object-fit: contain; cursor: default; background: #000;
    }
    .media-row .media-audio {
      display: block;
      width: calc(100% - 1rem);
      margin: .5rem;
    }
    .media-audio-card {
      position: relative;
      display: flex;
      align-items: flex-end;
      min-height: 220px;
      overflow: hidden;
      background: #050505;
    }
    .media-audio-art {
      position: absolute;
      inset: 0;
      width: 100%;
      height: 100%;
      object-fit: cover;
      opacity: .72;
    }
    .media-audio-card::after {
      content: '';
      position: absolute;
      inset: 35% 0 0;
      background: linear-gradient(transparent, rgba(0,0,0,.86));
      pointer-events: none;
    }
    .media-audio-card .media-audio {
      position: relative;
      z-index: 1;
      margin: .75rem;
      width: calc(100% - 1.5rem);
    }
    .media-row.media-count-1 .media-video { min-height: 200px; }
    .media-hint { font-size: .75rem; color: var(--muted); margin-top: .35rem; }
    .notification-media { max-width: 300px; margin-top: .55rem; }
    .notification-media .media-row { margin-top: 0; }
    .notification-media .media-row.media-count-1 img,
    .notification-media .media-row.media-count-1 .media-video {
      min-height: 0; max-height: 190px;
    }
    .img-lightbox {
      display: none; position: fixed; inset: 0; z-index: 12000;
      background: rgba(0,0,0,.88); align-items: center; justify-content: center;
      padding: 1rem; box-sizing: border-box;
    }
    .img-lightbox.open { display: flex; }
    .img-lightbox img {
      max-width: min(96vw, 1200px); max-height: 92vh; object-fit: contain;
      border-radius: 8px; box-shadow: 0 12px 48px rgba(0,0,0,.5);
    }
    .img-lightbox__close {
      position: absolute; top: .75rem; right: .9rem; width: 2.4rem; height: 2.4rem;
      border: 0; border-radius: 999px; background: rgba(255,255,255,.12); color: #fff;
      font-size: 1.4rem; cursor: pointer; line-height: 1;
    }
    .img-lightbox__close:hover { background: rgba(255,255,255,.22); }
    .feed-toolbar {
      display: flex; align-items: center; justify-content: space-between; gap: .75rem;
      margin-bottom: .75rem;
    }
    .feed-toolbar .meta { color: var(--muted); font-size: .85rem; }
    .timeline-tabs { display:flex; gap:.25rem; align-items:center; padding:.2rem; border:1px solid var(--border); border-radius:999px; background:var(--panel-2); }
    .timeline-tabs a { padding:.3rem .65rem; border-radius:999px; color:var(--muted); text-decoration:none; font-size:.8rem; }
    .timeline-tabs a:hover, .timeline-tabs a.active { background:var(--primary); color:#04140c; }
    /* Bluesky pinned/saved feeds: one horizontal scroll row with edge padding */
    .bsky-feed-tabs {
      display: flex;
      flex-wrap: nowrap;
      align-items: center;
      gap: .4rem;
      margin: 0 0 .85rem;
      padding: .35rem .55rem;
      overflow-x: auto;
      overflow-y: hidden;
      -webkit-overflow-scrolling: touch;
      scrollbar-width: thin;
      overscroll-behavior-x: contain;
    }
    .bsky-feed-tabs::-webkit-scrollbar { height: 6px; }
    .bsky-feed-tabs::-webkit-scrollbar-thumb {
      background: color-mix(in srgb, var(--muted) 45%, transparent);
      border-radius: 999px;
    }
    .bsky-feed-tabs > .btn {
      flex: 0 0 auto;
      white-space: nowrap;
      padding: .3rem .75rem;
      font-size: .82rem;
    }
    .timeline-skeleton { display:grid; gap:.6rem; margin:.5rem 0; }
    .timeline-skeleton-row { height:7.5rem; border:1px solid var(--border); border-radius:12px; background:linear-gradient(100deg,var(--panel) 30%,#202420 45%,var(--panel) 60%); background-size:220% 100%; animation:timeline-shimmer 1.1s linear infinite; }
    .trends-skeleton { display:grid; gap:.45rem; margin:.35rem 0 0; }
    .trends-skeleton-row { height:1.15rem; border:1px solid var(--border); border-radius:8px; background:linear-gradient(100deg,var(--panel) 30%,#202420 45%,var(--panel) 60%); background-size:220% 100%; animation:timeline-shimmer 1.1s linear infinite; }
    .trends-skeleton-row.wide { height:2.4rem; }
    @keyframes timeline-shimmer { to { background-position:-220% 0; } }
    .keyboard-selected { outline:2px solid var(--primary); outline-offset:2px; }
    .media-row { gap:.45rem; }
    .media-row img, .media-row .media-video { aspect-ratio:4/3; object-fit:cover; }
    .media-row .media-video { aspect-ratio:auto; object-fit:contain; height:auto; }
    .media-row.media-count-1 .media-video { max-height:min(80vh,900px); }
    .compose-fab { z-index: 110; }
    @media (max-width: 700px) { .compose-fab { width:3.35rem; height:3.35rem; bottom:max(1rem, env(safe-area-inset-bottom)); right:1rem; font-size:1.7rem; } .timeline-tabs { width:auto; justify-content:flex-start; } .timeline-tabs a { flex:0 0 auto; text-align:center; } }
    .home-suggestions { margin: 1rem 0; padding: .8rem; border: 1px solid var(--border); border-radius: 12px; background: var(--panel-2); }
    .home-suggestions-title { margin-bottom: .55rem; }
    .home-suggestions-grid { display: grid; grid-template-columns: repeat(3, minmax(0, 1fr)); gap: .55rem; }
    .home-suggestion { min-width: 0; display: flex; gap: .5rem; align-items: stretch; padding: .55rem; border: 1px solid var(--border); border-radius: 9px; background: var(--panel); }
    .home-suggestion .tweet-av { width: 40px; height: 40px; flex: 0 0 40px; }
    .home-suggestion-main { min-width: 0; min-height: 5.6rem; display: flex; flex: 1; flex-direction: column; overflow-wrap: anywhere; }
    .home-suggestion-main .who { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .home-suggestion-handle { display: block; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .home-suggestion-form { margin-top: auto; padding-top: .4rem; }
    .home-suggestion-form .btn { padding: .25rem .65rem; font-size: .78rem; }
    @media (max-width: 700px) { .home-suggestions-grid { grid-template-columns: 1fr; } }
    .quote-block {
      margin-top: .55rem; padding: .65rem .8rem;
      border-left: 3px solid var(--primary);
      background: var(--panel-2); border-radius: 0 10px 10px 0;
      color: var(--muted); white-space: pre-wrap; line-height: 1.4;
    }
    .quote-block .notification-snippet { white-space: pre-wrap; }
    .tweet-notif .quote-block { white-space: normal; }
    /* Bluesky cards build quote HTML as a compact string — don't preserve
       template whitespace as a huge empty gap (esp. quote-boosts on Home). */
    .tweet-bsky .quote-block { white-space: normal; }
    .tweet-bsky-home .quote-block { margin-top: .45rem; }
    .quote-block .qt-label { color: var(--primary); font-size: .75rem; letter-spacing: .04em; }
    .notification-post {
      margin-top: .55rem;
      color: var(--text);
    }

    .profile-form label {
      display: block; margin: .85rem 0 .35rem; color: var(--muted); font-size: .85rem;
    }
    .profile-form input[type="text"],
    .profile-form input[type="url"],
    .profile-form textarea {
      width: 100%; background: #0c0c0c; color: var(--text);
      border: 1px solid var(--border); border-radius: 10px;
      padding: .65rem .75rem; font: inherit;
    }
    .profile-form textarea { min-height: 140px; resize: vertical; }
    .profile-form .field-row {
      display: grid; grid-template-columns: 1fr 2fr auto; gap: .5rem; margin-top: .5rem; align-items: center;
    }
    .profile-form .field-status {
      display: inline-block; min-width: 5.5rem; white-space: nowrap;
    }
    .profile-form .checks {
      display: flex; flex-wrap: wrap; gap: 1rem; margin: 1rem 0;
      color: var(--muted); font-size: .9rem;
    }
    .profile-form .checks label { margin: 0; color: var(--text); display: flex; gap: .4rem; align-items: center; }
    .profile-form .profile-policy-block {
      display: flex; flex-direction: column; gap: .75rem;
      margin: 1rem 0 .25rem;
    }
    .profile-form .profile-policy-block label.profile-policy-row {
      margin: 0; color: var(--text);
      display: flex; flex-direction: column; align-items: flex-start; gap: .35rem;
    }
    .profile-form .profile-policy-block label.profile-policy-row select { max-width: 16rem; }
    .profile-form .profile-policy-block .profile-policy-note { margin: 0; }
    .profile-preview {
      display: flex; gap: 1rem; align-items: center; margin-bottom: 1rem;
      padding: .85rem; border-radius: 12px; background: var(--panel-2); border: 1px solid var(--border);
    }
    .profile-preview img.av {
      width: 64px; height: 64px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border);
    }
    .profile-preview .ph {
      width: 64px; height: 64px; border-radius: 50%; background: #222; border: 2px solid var(--border);
    }
    .brand-profile-link {
      display: inline-block;
      margin-top: .45rem;
      padding: .25rem .55rem;
      font-size: .72rem;
    }
    .brand-avatar { width: 52px; height: 52px; border-radius: 50%; object-fit: cover; border: 2px solid var(--border); display: block; margin: .4rem auto .45rem; }
  </style>
</head>
<body>
<div class="mobile-nav-backdrop" id="mobile-nav-backdrop" hidden></div>
<header class="mobile-topbar" id="mobile-topbar">
  <button type="button" class="mobile-topbar__menu" id="mobile-menu-btn" aria-label="Open menu" aria-controls="admin-rail-left" aria-expanded="false">☰</button>
  <div class="mobile-topbar__title"><span>VAAK</span> · <?= h(view_title($view)) ?></div>
  <a class="mobile-topbar__search" href="?view=search" aria-label="Search">⌕</a>
</header>
<div class="shell">
  <aside class="rail-left" id="admin-rail-left">
    <div class="brand">
      <a class="brand-link" href="?view=home" title="VAAK Home">
        <pre class="brand-ascii" aria-hidden="true">██╗   ██╗
██║   ██║
██║   ██║
╚██╗ ██╔╝
 ╚████╔╝
  ╚═══╝</pre>
        <strong>VAAK</strong>
      </a>
      <?= admin_avatar_img($vaakActorId, 'brand-avatar') ?>
      <div class="meta" style="margin:.35rem 0 0;font-size:.72rem;line-height:1.3">signed in as <?= h($vaakHandle) ?></div>
      <a class="btn btn-ghost brand-profile-link" href="/users/<?= h(rawurlencode($vaakActorKey)) ?>" target="_blank" rel="noopener noreferrer">View profile</a>
    </div>
    <?php
      $dmUnreadNav = ap_dm_unread_count();
      $discussUnreadNav = function_exists('ap_discuss_unread_topic_count') ? ap_discuss_unread_topic_count($vaakOwnerId) : 0;
      $noticesUnreadNav = isset($noticesUnreadNav) ? (int) $noticesUnreadNav : 0;
      $navLibraryOpen = in_array($view, ['favourites', 'bookmarks', 'followers', 'following', 'tags', 'collections', 'lists'], true);
      $navYouOpen = in_array($view, ['outbox', 'queue', 'drafts', 'profile', 'import_export', 'security'], true);
      $navAdminOpen = in_array($view, ['blocks', 'stats', 'moderation', 'relays', 'invites', 'users', 'policies'], true);
      $navSiteOpen = in_array($view, ['guestbook', 'support', 'analytics'], true);
      $reportsOpenCount = function_exists('ap_reports_open_count') ? ap_reports_open_count() : 0;
    ?>
    <nav class="nav">
      <a class="<?= $view === 'home' ? 'active' : '' ?>" href="?view=home"><span class="ico">⌂</span><span class="label">Home</span></a>
      <a class="<?= $view === 'notices' ? 'active' : '' ?>" href="?view=notices"><span class="ico">▤</span><span class="label">Notices</span><span class="nav-badge"<?= $noticesUnreadNav > 0 ? '' : ' hidden' ?>><?= $noticesUnreadNav > 99 ? '99+' : (string) (int) $noticesUnreadNav ?></span></a>
      <hr class="nav-sep">
      <a class="<?= $view === 'gallery' ? 'active' : '' ?>" href="?view=gallery"><span class="ico">▦</span><span class="label">Gallery</span></a>
      <a class="<?= $view === 'vakktok' ? 'active' : '' ?>" href="?view=vakktok"><span class="ico">▶</span><span class="label">VakkTok</span></a>
      <?php if (function_exists('ap_bsky_tab_enabled') && ap_bsky_tab_enabled()): ?>
      <a class="<?= $view === 'bluesky' ? 'active' : '' ?>" href="?view=bluesky"><span class="ico">☁</span><span class="label">Bluesky</span></a>
      <?php endif; ?>
      <a class="<?= $view === 'discuss' ? 'active' : '' ?>" href="?view=discuss"><span class="ico">▤</span><span class="label">Discuss</span><span class="nav-badge"<?= $discussUnreadNav > 0 ? '' : ' hidden' ?>><?= $discussUnreadNav > 99 ? '99+' : (string) (int) $discussUnreadNav ?></span></a>
      <hr class="nav-sep">
      <a class="<?= $view === 'mentions' ? 'active' : '' ?>" href="?view=mentions" id="nav-notifications">
        <span class="ico">＠</span><span class="label">Notifications</span>
        <span class="nav-badge" id="notif-badge"<?= $notifUnreadNav > 0 ? '' : ' hidden' ?>><?= h($notifBadgeLabel) ?></span>
      </a>
      <a class="<?= $view === 'dms' ? 'active' : '' ?>" href="?view=dms" id="nav-dms">
        <span class="ico">✉</span><span class="label">DMs</span>
        <span class="nav-badge" id="dm-badge"<?= $dmUnreadNav > 0 ? '' : ' hidden' ?>><?= $dmUnreadNav > 99 ? '99+' : (string) (int) $dmUnreadNav ?></span>
      </a>
      <hr class="nav-sep">
      <details class="nav-group" data-nav-key="library" <?= $navLibraryOpen ? 'open' : '' ?>>
        <summary><span class="ico">☰</span><span class="label">Library</span></summary>
        <div class="nav-sub">
          <a class="<?= $view === 'favourites' ? 'active' : '' ?>" href="?view=favourites"><span class="ico"><i class="ph ph-star" aria-hidden="true"></i></span><span class="label">Favourites</span></a>
          <a class="<?= $view === 'bookmarks' ? 'active' : '' ?>" href="?view=bookmarks"><span class="ico"><i class="ph ph-bookmark-simple" aria-hidden="true"></i></span><span class="label">Bookmarks</span></a>
          <a class="<?= $view === 'followers' ? 'active' : '' ?>" href="?view=followers"><span class="ico">◎</span><span class="label">Followers</span></a>
          <a class="<?= $view === 'following' ? 'active' : '' ?>" href="?view=following"><span class="ico">⇄</span><span class="label">Following</span></a>
          <a class="<?= $view === 'tags' ? 'active' : '' ?>" href="?view=tags"><span class="ico">＃</span><span class="label">Hashtags</span></a>
          <a class="<?= $view === 'collections' ? 'active' : '' ?>" href="?view=collections"><span class="ico">▦</span><span class="label">Collections</span></a>
          <a class="<?= $view === 'lists' ? 'active' : '' ?>" href="?view=lists"><span class="ico">☰</span><span class="label">Lists</span></a>
        </div>
      </details>
      <hr class="nav-sep">
      <details class="nav-group" data-nav-key="you" <?= $navYouOpen ? 'open' : '' ?>>
        <summary><span class="ico">☺</span><span class="label">You</span></summary>
        <div class="nav-sub">
          <a class="<?= $view === 'outbox' ? 'active' : '' ?>" href="?view=outbox"><span class="ico">✎</span><span class="label">Your posts</span></a>
          <a class="<?= $view === 'queue' ? 'active' : '' ?>" href="?view=queue"><span class="ico">⏱</span><span class="label">Queue</span></a>
          <a class="<?= $view === 'drafts' ? 'active' : '' ?>" href="?view=drafts" id="nav-drafts">
            <span class="ico">📄</span><span class="label">Drafts</span>
            <?php if ($draftsCountNav > 0): ?>
              <span class="nav-badge" id="nav-drafts-badge"><?= $draftsCountNav > 99 ? '99+' : (string) (int) $draftsCountNav ?></span>
            <?php else: ?>
              <span class="nav-badge" id="nav-drafts-badge" hidden></span>
            <?php endif; ?>
          </a>
          <a class="<?= $view === 'profile' ? 'active' : '' ?>" href="?view=profile"><span class="ico">◇</span><span class="label">Profile</span></a>
          <a class="<?= $view === 'security' ? 'active' : '' ?>" href="?view=security"><span class="ico">⚿</span><span class="label">Security</span></a>
          <a class="<?= $view === 'import_export' ? 'active' : '' ?>" href="?view=import_export"><span class="ico">⇄</span><span class="label">Import / Export</span></a>
        </div>
      </details>
      <?php if (!empty($vaakIsAdmin)): ?>
      <hr class="nav-sep">
      <details class="nav-group" data-nav-key="admin" <?= $navAdminOpen ? 'open' : '' ?>>
        <summary><span class="ico">⚙</span><span class="label">Admin</span></summary>
        <div class="nav-sub">
          <a class="<?= $view === 'moderation' ? 'active' : '' ?>" href="?view=moderation" id="nav-moderation">
            <span class="ico">⚑</span><span class="label">Moderation</span>
            <?php if ($reportsOpenCount > 0): ?>
              <span class="nav-badge"><?= $reportsOpenCount > 99 ? '99+' : (string) (int) $reportsOpenCount ?></span>
            <?php endif; ?>
          </a>
          <a class="<?= $view === 'blocks' ? 'active' : '' ?>" href="?view=blocks"><span class="ico">⊘</span><span class="label">Server blocks</span></a>
          <a class="<?= $view === 'invites' ? 'active' : '' ?>" href="?view=invites"><span class="ico">✦</span><span class="label">Invites</span></a>
          <a class="<?= $view === 'users' ? 'active' : '' ?>" href="?view=users"><span class="ico"><i class="ph ph-users-three" aria-hidden="true"></i></span><span class="label">Users</span></a>
          <a class="<?= $view === 'policies' ? 'active' : '' ?>" href="?view=policies"><span class="ico">§</span><span class="label">Policies</span></a>
          <a class="<?= $view === 'relays' ? 'active' : '' ?>" href="?view=relays"><span class="ico">⇄</span><span class="label">Relays</span></a>
          <a class="<?= $view === 'stats' ? 'active' : '' ?>" href="?view=stats"><span class="ico">▤</span><span class="label">AP stats</span></a>
        </div>
      </details>
      <?php endif; ?>
      <hr class="nav-sep">
      <details class="nav-group" data-nav-key="site" <?= $navSiteOpen ? 'open' : '' ?>>
        <summary><span class="ico">▤</span><span class="label">Site</span></summary>
        <div class="nav-sub">
          <?php if (!empty($vaakIsAdmin)): ?>
          <a class="<?= $view === 'guestbook' ? 'active' : '' ?>" href="?view=guestbook"><span class="ico">✉</span><span class="label">Guestbook</span></a>
          <a class="<?= $view === 'support' ? 'active' : '' ?>" href="?view=support"><span class="ico">$</span><span class="label">Support</span></a>
          <a class="<?= $view === 'analytics' ? 'active' : '' ?>" href="?view=analytics"><span class="ico">◔</span><span class="label">Analytics</span></a>
          <?php endif; ?>
          <a href="/"><span class="ico">←</span><span class="label">View site</span></a>
        </div>
      </details>
      <a href="/vaak/?logout=1"><span class="ico">⎋</span><span class="label">Log out</span></a>
    </nav>
  </aside>

  <aside class="rail-right">
    <div class="side-card">
      <h3>Search</h3>
      <form method="get" action="" style="margin:0">
        <input type="hidden" name="view" value="search">
        <input name="q" type="search" value="<?= h(trim((string) ($_GET['q'] ?? ''))) ?>" placeholder="@user@host · #tag" style="width:100%;background:#0c0c0c;color:var(--text);border:1px solid var(--border);border-radius:10px;padding:.55rem .7rem;font:inherit">
        <button class="btn btn-primary" type="submit" style="width:100%;margin-top:.55rem">Search</button>
      </form>
    </div>
    <?php
      // Always paint a shimmer shell first; JS fills via ?ajax=trends after paint
      // (even on a warm cache) so every page shows a brief loading state.
    ?>
    <div id="trends-sidebar" data-deferred="1" data-from="<?= h($view) ?>">
      <div class="side-card" aria-busy="true" aria-label="Loading trending tags">
        <h3>Trending tags</h3>
        <div class="trends-skeleton" aria-hidden="true">
          <div class="trends-skeleton-row"></div>
          <div class="trends-skeleton-row"></div>
          <div class="trends-skeleton-row" style="width:72%"></div>
        </div>
      </div>
      <div class="side-card" aria-busy="true" aria-label="Loading trending links">
        <h3>Trending links</h3>
        <div class="trends-skeleton" aria-hidden="true">
          <div class="trends-skeleton-row wide"></div>
          <div class="trends-skeleton-row wide" style="width:88%"></div>
        </div>
      </div>
      <div class="side-card" aria-busy="true" aria-label="Loading trending posts">
        <h3>Trending posts</h3>
        <div class="trends-skeleton" aria-hidden="true">
          <div class="trends-skeleton-row wide"></div>
          <div class="trends-skeleton-row wide" style="width:80%"></div>
          <div class="trends-skeleton-row" style="width:60%"></div>
        </div>
      </div>
    </div>
  </aside>

  <section class="main">
    <div class="topbar<?= in_array($view, ['home', 'local', 'feed'], true) ? ' topbar-timeline' : '' ?>">
      <h1><?= h(view_title($view)) ?></h1>
      <div class="topbar-actions">
        <?php if (in_array($view, ['home', 'local', 'feed'], true)): ?>
          <nav class="timeline-tabs" aria-label="Timeline views">
            <a class="<?= $view === 'home' ? 'active' : '' ?>" href="?view=home">Home</a>
            <a class="<?= $view === 'local' ? 'active' : '' ?>" href="?view=local">Local</a>
            <a class="<?= $view === 'feed' ? 'active' : '' ?>" href="?view=feed">Federated</a>
          </nav>
        <?php endif; ?>
        <?php if (in_array($view, ['home', 'local', 'feed', 'gallery', 'mentions', 'dms', 'favourites', 'bookmarks', 'outbox', 'queue', 'drafts', 'followers', 'following', 'blocks', 'profile', 'users'], true)): ?>
          <a class="btn btn-ghost" href="?view=<?= h($view) ?><?= $view === 'dms' && !empty($_GET['peer']) ? '&amp;peer=' . urlencode((string) $_GET['peer']) : '' ?>&amp;_r=<?= time() ?>" title="Reload this view">↻ Refresh</a>
        <?php endif; ?>
      </div>
    </div>

    <?php if ($notice): ?><div class="flash ok"><?= h($notice) ?></div><?php endif; ?>
    <?php if ($error): ?><div class="flash err"><?= h($error) ?></div><?php endif; ?>

    <div class="feed<?= in_array($view, ['guestbook','support','analytics'], true) ? ' wide-feed' : '' ?>">
      <?php if (in_array($view, ['home', 'local', 'feed'], true) && !$autoOpenComposer): ?>
        <div class="compose-inline-slot" id="compose-inline-slot" aria-label="Loading composer">
          <div class="compose-inline-skeleton" aria-hidden="true"></div>
        </div>
      <?php endif; ?>
      <?php if ($view === 'discuss'): ?>
        <?php
          $discussTopicId = (int) ($_GET['topic'] ?? 0);
          $discussTopic = $discussTopicId > 0 ? ap_discuss_topic($discussTopicId) : null;
          $discussCategorySlug = strtolower(trim((string) ($_GET['category'] ?? '')));
          if ($discussTopic !== null) {
              $discussCategorySlug = (string) ($discussTopic['category_slug'] ?? $discussCategorySlug);
              ap_discuss_mark_read($discussTopicId, $vaakOwnerId);
          }
          $discussCategory = $discussCategorySlug !== '' ? ap_discuss_category($discussCategorySlug) : null;
        ?>
        <?php if ($discussTopic !== null): ?>
          <?php $discussPosts = ap_discuss_posts($discussTopicId); ?>
          <p class="meta" style="margin:0 0 .8rem"><a href="?view=discuss">Discuss</a> <span aria-hidden="true">/</span> <a href="?view=discuss&amp;category=<?= h(rawurlencode((string) ($discussTopic['category_slug'] ?? ''))) ?>"><?= h((string) ($discussTopic['category_name'] ?? 'Category')) ?></a></p>
          <article class="forum-topic-head side-card" style="margin-bottom:1rem">
            <div class="meta">Discussion</div>
            <h1 style="margin:.15rem 0 .35rem;font-size:1.35rem"><?= h((string) ($discussTopic['title'] ?? '')) ?></h1>
            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
              <div class="meta">Started by <b>@<?= h((string) ($discussTopic['username'] ?? 'local user')) ?></b> · <?= h(relative_time((string) ($discussTopic['created_at'] ?? ''))) ?><?= !empty($discussTopic['locked']) ? ' · locked' : '' ?></div>
              <?php if ($vaakActorKey === 'cmdr_nova'): ?>
                <form method="post" action="?view=discuss&amp;topic=<?= $discussTopicId ?>" onsubmit="return confirm('Delete this discussion and all of its replies?');">
                  <input type="hidden" name="action" value="discuss_topic_delete">
                  <input type="hidden" name="topic_id" value="<?= $discussTopicId ?>">
                  <input type="hidden" name="category_slug" value="<?= h((string) ($discussTopic['category_slug'] ?? '')) ?>">
                  <button class="btn btn-ghost" type="submit">Delete discussion</button>
                </form>
              <?php endif; ?>
            </div>
          </article>
          <?php foreach ($discussPosts as $dp): ?>
            <article id="post-<?= (int) ($dp['id'] ?? 0) ?>" class="side-card" style="margin-bottom:.75rem">
              <div style="display:flex;justify-content:space-between;gap:.75rem;align-items:baseline;flex-wrap:wrap">
                <div><b>@<?= h((string) ($dp['username'] ?? 'local user')) ?></b><span class="meta"> · <?= h(relative_time((string) ($dp['created_at'] ?? ''))) ?></span></div>
                <div style="display:flex;gap:.65rem;align-items:center"><a class="meta" href="#post-<?= (int) ($dp['id'] ?? 0) ?>">#<?= (int) ($dp['id'] ?? 0) ?></a><?php if ($vaakActorKey === 'cmdr_nova'): ?><form method="post" action="?view=discuss&amp;topic=<?= $discussTopicId ?>" onsubmit="return confirm('Delete this reply?');"><input type="hidden" name="action" value="discuss_post_delete"><input type="hidden" name="post_id" value="<?= (int) ($dp['id'] ?? 0) ?>"><input type="hidden" name="topic_id" value="<?= $discussTopicId ?>"><button class="btn btn-ghost" type="submit">Delete</button></form><?php endif; ?></div>
              </div>
              <div class="body feed-body" style="white-space:pre-wrap;overflow-wrap:anywhere;margin-top:.65rem"><?= h((string) ($dp['body'] ?? '')) ?></div>
            </article>
          <?php endforeach; ?>
          <?php if (empty($discussTopic['locked'])): ?>
            <form method="post" action="?view=discuss&amp;topic=<?= $discussTopicId ?>" class="side-card composer" style="margin-top:1rem">
              <input type="hidden" name="action" value="discuss_post_create">
              <input type="hidden" name="topic_id" value="<?= $discussTopicId ?>">
              <h2 style="margin-top:0;font-size:1rem">Reply</h2>
              <textarea name="post_body" maxlength="20000" rows="6" placeholder="Write a reply…" required></textarea>
              <div class="composer-actions"><span class="meta">Posted as @<?= h($vaakUsername) ?> · local to VAAK</span><button class="btn btn-primary" type="submit">Post reply</button></div>
            </form>
          <?php endif; ?>
        <?php elseif ($discussCategory !== null): ?>
          <?php
            $discussTopics = ap_discuss_topics((int) $discussCategory['id'], 100, $vaakOwnerId);
            $discussCategoryUnread = 0;
            foreach ($discussTopics as $dtUnread) {
                if (!empty($dtUnread['is_unread'])) {
                    $discussCategoryUnread++;
                }
            }
          ?>
          <p class="meta" style="margin:0 0 .8rem"><a href="?view=discuss">Discuss</a> <span aria-hidden="true">/</span> <?= h((string) $discussCategory['name']) ?></p>
          <section class="side-card" style="margin-bottom:1rem">
            <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
              <h1 style="margin:.1rem 0 .35rem;font-size:1.35rem"><?= h((string) $discussCategory['name']) ?></h1>
              <?php if ($discussCategoryUnread > 0): ?>
                <span class="nav-badge" style="position:static"><?= $discussCategoryUnread > 99 ? '99+' : (string) $discussCategoryUnread ?> new</span>
              <?php endif; ?>
            </div>
            <p class="meta" style="margin:0"><?= h((string) ($discussCategory['description'] ?? '')) ?></p>
          </section>
          <section class="side-card" style="margin-bottom:1rem">
            <h2 style="margin:.1rem 0 .7rem;font-size:1rem">Start a discussion</h2>
            <form method="post" action="?view=discuss&amp;category=<?= h(rawurlencode($discussCategorySlug)) ?>" class="composer">
              <input type="hidden" name="action" value="discuss_topic_create">
              <input type="hidden" name="category_slug" value="<?= h($discussCategorySlug) ?>">
              <input name="topic_title" maxlength="180" placeholder="Topic title" required>
              <textarea name="topic_body" maxlength="20000" rows="5" placeholder="What would you like to discuss?" required></textarea>
              <div class="composer-actions"><span class="meta">Posted as @<?= h($vaakUsername) ?> · local to VAAK</span><button class="btn btn-primary" type="submit">Create discussion</button></div>
            </form>
          </section>
          <?php if (!$discussTopics): ?>
            <div class="empty">No discussions here yet.</div>
          <?php else: ?>
            <section class="side-card" style="padding:0;overflow:hidden">
              <?php foreach ($discussTopics as $dt): ?>
                <?php $topicUnread = !empty($dt['is_unread']); ?>
                <a href="?view=discuss&amp;topic=<?= (int) ($dt['id'] ?? 0) ?>" style="display:block;padding:.9rem 1rem;border-bottom:1px solid var(--border);text-decoration:none;color:inherit<?= $topicUnread ? ';background:rgba(120,200,140,.06)' : '' ?>">
                  <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap">
                    <strong><?= h((string) ($dt['title'] ?? '')) ?></strong>
                    <span style="display:flex;align-items:center;gap:.5rem">
                      <?php if ($topicUnread): ?><span class="nav-badge" style="position:static">new</span><?php endif; ?>
                      <span class="meta"><?= (int) ($dt['post_count'] ?? 0) ?> <?= ((int) ($dt['post_count'] ?? 0) === 1 ? 'post' : 'posts') ?></span>
                    </span>
                  </div>
                  <div class="meta" style="margin-top:.25rem">Started by @<?= h((string) ($dt['username'] ?? 'local user')) ?> · <?= h(relative_time((string) ($dt['updated_at'] ?? ''))) ?></div>
                </a>
              <?php endforeach; ?>
            </section>
          <?php endif; ?>
        <?php else: ?>
          <?php $discussCategories = ap_discuss_categories($vaakOwnerId); ?>
          <section class="side-card" style="margin-bottom:1rem">
            <h1 style="margin:.1rem 0 .35rem;font-size:1.35rem">Discuss</h1>
            <p class="meta" style="margin:0">Local discussion forums for VAAK users. Posts and replies stay inside this interface.</p>
          </section>
          <section class="side-card" style="padding:0;overflow:hidden">
            <?php foreach ($discussCategories as $dc): ?>
              <a href="?view=discuss&amp;category=<?= h(rawurlencode((string) ($dc['slug'] ?? ''))) ?>" style="display:block;padding:1rem;border-bottom:1px solid var(--border);text-decoration:none;color:inherit">
                <div style="display:flex;justify-content:space-between;gap:1rem;align-items:center;flex-wrap:wrap"><strong><?= h((string) ($dc['name'] ?? '')) ?></strong><span style="display:flex;align-items:center;gap:.5rem"><span class="meta"><?= (int) ($dc['topic_count'] ?? 0) ?> <?= ((int) ($dc['topic_count'] ?? 0) === 1 ? 'topic' : 'topics') ?></span><?php $categoryUnread = (int) ($dc['unread_count'] ?? 0); ?><?php if ($categoryUnread > 0): ?><span class="nav-badge" style="position:static"><?= $categoryUnread > 99 ? '99+' : $categoryUnread ?> new</span><?php endif; ?></span></div>
                <div class="meta" style="margin-top:.3rem"><?= h((string) ($dc['description'] ?? '')) ?></div>
              </a>
            <?php endforeach; ?>
          </section>
        <?php endif; ?>

      <?php elseif ($view === 'notices'): ?>
        <?php $noticeRows = ap_notices_list($vaakActorKey === 'cmdr_nova', 100); ?>
        <?php if ($vaakActorKey === 'cmdr_nova'): ?>
          <section class="side-card" style="margin-bottom:1rem">
            <h2 style="margin-top:0">Write a notice</h2>
            <form method="post" action="?view=notices" class="composer">
              <input type="hidden" name="action" value="notice_save">
              <input name="notice_title" maxlength="160" placeholder="Title (optional)">
              <textarea name="notice_body" maxlength="20000" rows="6" placeholder="Announcement or explanation for local VAAK users…" required></textarea>
              <label class="composer-check"><input type="checkbox" name="notice_published" value="1" checked><span>Visible to local users</span></label>
              <div class="composer-actions"><span class="meta">Notices and replies stay inside VAAK.</span><button class="btn btn-primary" type="submit">Publish notice</button></div>
            </form>
          </section>
        <?php endif; ?>
        <?php if (!$noticeRows): ?>
          <div class="empty">No notices have been posted.</div>
        <?php else: ?>
          <?php foreach ($noticeRows as $nr): ?>
            <?php
              $nid = (int) ($nr['id'] ?? 0);
              $ntitle = trim((string) ($nr['title'] ?? ''));
              $nbody = (string) ($nr['body'] ?? '');
              $nreplies = ap_notice_replies($nid);
            ?>
            <article class="side-card" style="margin-bottom:1rem">
              <div class="meta" style="margin-bottom:.35rem">Server notice · <?= h(relative_time((string) ($nr['updated_at'] ?? ''))) ?><?= empty($nr['published']) ? ' · unpublished' : '' ?></div>
              <?php if ($ntitle !== ''): ?><h2 style="margin:.1rem 0 .55rem"><?= h($ntitle) ?></h2><?php endif; ?>
              <div class="body feed-body" style="white-space:pre-wrap;overflow-wrap:anywhere"><?= h($nbody) ?></div>
              <?php if ($nreplies): ?>
                <div style="margin-top:1rem;padding-top:.75rem;border-top:1px solid var(--border)">
                  <div class="meta" style="margin-bottom:.45rem">Local replies</div>
                  <?php foreach ($nreplies as $reply): ?>
                    <div style="padding:.55rem 0;border-top:1px solid rgba(255,255,255,.08)">
                      <div class="meta"><b>@<?= h((string) ($reply['username'] ?? 'local user')) ?></b> · <?= h(relative_time((string) ($reply['created_at'] ?? ''))) ?></div>
                      <div style="white-space:pre-wrap;overflow-wrap:anywhere;margin-top:.2rem"><?= h((string) ($reply['body'] ?? '')) ?></div>
                    </div>
                  <?php endforeach; ?>
                </div>
              <?php endif; ?>
              <form method="post" action="?view=notices" class="composer" style="margin-top:1rem">
                <input type="hidden" name="action" value="notice_reply">
                <input type="hidden" name="notice_id" value="<?= $nid ?>">
                <textarea name="notice_reply_body" maxlength="5000" rows="2" placeholder="Reply locally…" required></textarea>
                <div class="composer-actions"><span class="meta">Your reply is visible only to local VAAK users.</span><button class="btn btn-ghost" type="submit">Reply</button></div>
              </form>
              <?php if ($vaakActorKey === 'cmdr_nova'): ?>
                <details style="margin-top:.75rem">
                  <summary class="meta" style="cursor:pointer">Edit notice</summary>
                  <form method="post" action="?view=notices" class="composer" style="margin-top:.5rem">
                    <input type="hidden" name="action" value="notice_save">
                    <input type="hidden" name="notice_id" value="<?= $nid ?>">
                    <input name="notice_title" maxlength="160" value="<?= h($ntitle) ?>">
                    <textarea name="notice_body" maxlength="20000" rows="5" required><?= h($nbody) ?></textarea>
                    <label class="composer-check"><input type="checkbox" name="notice_published" value="1"<?= !empty($nr['published']) ? ' checked' : '' ?>><span>Visible to local users</span></label>
                    <div class="composer-actions"><button class="btn btn-primary" type="submit">Save changes</button></div>
                  </form>
                  <form method="post" action="?view=notices" style="margin-top:.5rem" onsubmit="return confirm('Delete this notice and its local replies?');">
                    <input type="hidden" name="action" value="notice_delete"><input type="hidden" name="notice_id" value="<?= $nid ?>">
                    <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Delete notice</button>
                  </form>
                </details>
              <?php endif; ?>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($view === 'home'): ?>
        <?php
          $homePage = array_slice($homeTimeline, 0, $tlLimit);
          $homeHasMore = $adminTlFromCache
              ? $adminTlCachedHasMore
              : (count($homeTimeline) > $tlLimit);
          // Suggestions are deferred after first paint (see ajax=home_suggestions).
          if (function_exists('ap_masto_status_flags_prefetch')) {
              ap_masto_status_flags_prefetch(admin_timeline_status_ids($homePage));
          }
        ?>
        <?php if (!$homeTimeline): ?>
          <div class="empty">Nothing here yet. Follow people, <a href="?view=tags">follow hashtags</a>, or hit ＋ to post.</div>
        <?php endif; ?>
        <div id="timeline-items" data-view="home" data-offset="<?= (int) count($homePage) ?>" data-limit="<?= (int) $tlLimit ?>" data-has-more="<?= $homeHasMore ? '1' : '0' ?>" data-newest="<?= (int) (!empty($homePage[0]['sort']) ? $homePage[0]['sort'] : time()) ?>">
          <?php foreach ($homePage as $homeIndex => $item): ?>
            <?php
              if (admin_timeline_item_muted_by_words($item)) {
                  continue;
              }
              admin_render_timeline_item($item, $followingIds, 'home');
              // Placeholder after a few posts; filled async so Home first paint stays light.
              if ($homeIndex === 5) {
                  echo '<div id="home-suggestions-slot" class="home-suggestions-slot" data-deferred="1" hidden></div>';
              }
            ?>
          <?php endforeach; ?>
          <?php if (count($homePage) < 6): ?>
            <div id="home-suggestions-slot" class="home-suggestions-slot" data-deferred="1" hidden></div>
          <?php endif; ?>
        </div>
        <div id="timeline-status" class="meta" style="padding:.75rem 0;text-align:center"><?= $homeHasMore ? 'Scroll for more…' : ($homeTimeline ? 'End of timeline' : '') ?></div>
        <div id="timeline-sentinel" aria-hidden="true" style="height:1px"></div>

      <?php elseif ($view === 'local'): ?>
        <?php
          $localPage = array_slice($localTimeline, 0, $tlLimit);
          $localHasMore = $adminTlFromCache
              ? $adminTlCachedHasMore
              : (count($localTimeline) > $tlLimit);
          if (function_exists('ap_masto_status_flags_prefetch')) {
              ap_masto_status_flags_prefetch(admin_timeline_status_ids($localPage));
          }
        ?>
        <?php if (!$localTimeline): ?>
          <div class="empty">No local posts yet. When anyone on this instance posts, it shows up here.</div>
        <?php endif; ?>
        <div id="timeline-items" data-view="local" data-offset="<?= (int) count($localPage) ?>" data-limit="<?= (int) $tlLimit ?>" data-has-more="<?= $localHasMore ? '1' : '0' ?>" data-newest="<?= (int) (!empty($localPage[0]['sort']) ? $localPage[0]['sort'] : time()) ?>">
          <?php foreach ($localPage as $item): ?>
            <?php
              if (admin_timeline_item_muted_by_words($item)) {
                  continue;
              }
              admin_render_timeline_item($item, $followingIds, 'local');
            ?>
          <?php endforeach; ?>
        </div>
        <div id="timeline-status" class="meta" style="padding:.75rem 0;text-align:center"><?= $localHasMore ? 'Scroll for more…' : ($localTimeline ? 'End of timeline' : '') ?></div>
        <div id="timeline-sentinel" aria-hidden="true" style="height:1px"></div>

      <?php elseif ($view === 'feed'): ?>
        <?php
          $feedPage = array_slice($feedTimeline, 0, $tlLimit);
          $feedHasMore = $adminTlFromCache
              ? $adminTlCachedHasMore
              : (count($feedTimeline) > $tlLimit);
          if (function_exists('ap_masto_status_flags_prefetch')) {
              ap_masto_status_flags_prefetch(admin_timeline_status_ids($feedPage));
          }
        ?>
        <?php if (!$feedTimeline): ?><div class="empty">No federation events yet.</div><?php endif; ?>
        <div id="timeline-items" data-view="feed" data-offset="<?= (int) count($feedPage) ?>" data-limit="<?= (int) $tlLimit ?>" data-has-more="<?= $feedHasMore ? '1' : '0' ?>" data-newest="<?= (int) (!empty($feedPage[0]['sort']) ? $feedPage[0]['sort'] : time()) ?>">
          <?php foreach ($feedPage as $item): ?>
            <?php
              if (admin_timeline_item_muted_by_words($item)) {
                  continue;
              }
              admin_render_timeline_item($item, $followingIds, 'feed');
            ?>
          <?php endforeach; ?>
        </div>
        <div id="timeline-status" class="meta" style="padding:.75rem 0;text-align:center"><?= $feedHasMore ? 'Scroll for more…' : ($feedTimeline ? 'End of timeline' : '') ?></div>
        <div id="timeline-sentinel" aria-hidden="true" style="height:1px"></div>

      <?php elseif ($view === 'vakktok'): ?>
        <?php
          $tokLimit = max($tlLimit, 8);
          $tokTimeline = array_values(array_filter($galleryTimeline, static fn($item): bool => is_array($item) && admin_vakktok_item_has_video($item) && !admin_vakktok_item_is_sensitive($item)));
          $tokPage = array_slice($tokTimeline, 0, $tokLimit);
          $tokHasMore = $adminTlFromCache
              ? $adminTlCachedHasMore
              : (count($tokTimeline) > $tokLimit);
        ?>
        <?php if (!$tokTimeline): ?><div class="empty">No videos are available yet.</div><?php endif; ?>
        <div id="timeline-items" class="vakktok-feed" data-view="vakktok" data-offset="<?= (int) count($tokPage) ?>" data-limit="<?= (int) $tokLimit ?>" data-has-more="<?= $tokHasMore ? '1' : '0' ?>" data-newest="<?= (int) (!empty($tokPage[0]['sort']) ? $tokPage[0]['sort'] : time()) ?>">
          <?php foreach ($tokPage as $item): ?>
            <?php if (!admin_timeline_item_muted_by_words($item)) { admin_render_vakktok_cell($item); } ?>
          <?php endforeach; ?>
        </div>
        <div id="timeline-status" class="meta" style="padding:.75rem 0;text-align:center"><?= $tokHasMore ? 'Scroll for more…' : '' ?></div>
        <div id="timeline-sentinel" aria-hidden="true" style="height:1px"></div>

      <?php elseif ($view === 'gallery'): ?>
        <?php
          $galLimit = max($tlLimit, 16);
          $galleryPage = array_slice($galleryTimeline, 0, $galLimit);
          $galleryHasMore = $adminTlFromCache
              ? $adminTlCachedHasMore
              : (count($galleryTimeline) > $galLimit);
        ?>
        <?php if (!$galleryTimeline): ?>
          <div class="empty">No images in the cache yet. Posts with media from the firehose and local users show up here.</div>
        <?php endif; ?>
        <div id="timeline-items" class="gallery-grid" data-view="gallery" data-offset="<?= (int) count($galleryPage) ?>" data-limit="<?= (int) $galLimit ?>" data-has-more="<?= $galleryHasMore ? '1' : '0' ?>" data-newest="<?= (int) (!empty($galleryPage[0]['sort']) ? $galleryPage[0]['sort'] : time()) ?>">
          <?php foreach ($galleryPage as $item): ?>
            <?php
              if (admin_timeline_item_muted_by_words($item)) {
                  continue;
              }
              admin_render_gallery_cell($item, $followingIds, 'gallery');
            ?>
          <?php endforeach; ?>
        </div>
        <div id="timeline-status" class="meta" style="padding:.75rem 0;text-align:center"><?= $galleryHasMore ? 'Scroll for more…' : ($galleryTimeline ? 'End of gallery' : '') ?></div>
        <div id="timeline-sentinel" aria-hidden="true" style="height:1px"></div>

      <?php elseif ($view === 'favourites'): ?>

        <?php
          $favList = ap_masto_favourites_list(60, null);
          if (!$favList):
        ?>
          <div class="empty">No favourites yet.</div>
        <?php else: ?>
          <?php foreach ($favList as $st): ?>
            <?php
              $acct = (string) ($st['account']['acct'] ?? '?');
              $sid = (string) ($st['id'] ?? '');
              $oid = (string) ($st['uri'] ?? '');
              $actorUrl = (string) ($st['account']['url'] ?? $st['account']['uri'] ?? '');
            ?>
            <article class="tweet">
              <div class="tweet-hd">
                <?= admin_avatar_img($actorUrl !== '' ? $actorUrl : null) ?>
                <div class="tweet-hd-main">
                  <div>
                    <span class="who"><?= h($acct) ?></span>
                    <span class="meta"> · <?= h((string) ($st['created_at'] ?? '')) ?></span>
                  </div>
                </div>
              </div>
              <?php
                $favPlain = admin_html_to_plain((string) ($st['content'] ?? ''));
                $favMentions = is_array($st['mentions'] ?? null) ? $st['mentions'] : [];
                echo admin_cw_gate_html(
                    (string) ($st['spoiler_text'] ?? ''),
                    !empty($st['sensitive']) || trim((string) ($st['spoiler_text'] ?? '')) !== '',
                    $favPlain !== ''
                        ? '<div class="body feed-body">' . admin_linkify_body_html($favPlain, 'favourites', $favMentions) . '</div>'
                        : ''
                );
              ?>
              <div class="tweet-actions">
                <?php if ($oid !== ''): ?>
                  <a class="btn btn-ghost" href="<?= h(admin_status_href($oid, 'favourites')) ?>" style="padding:.25rem .7rem;font-size:.8rem">Open</a>
                  <a href="<?= h(admin_remote_object_href($oid)) ?>" target="_blank" rel="noopener noreferrer" class="meta">Remote</a>
                <?php endif; ?>
                <?php if ($sid !== ''): ?>
                  <form method="post" action="?view=favourites" style="display:inline">
                    <input type="hidden" name="action" value="unfavourite_status">
                    <input type="hidden" name="return_view" value="favourites">
                    <input type="hidden" name="status_id" value="<?= h($sid) ?>">
                    <input type="hidden" name="object_id" value="<?= h($oid) ?>">
                    <input type="hidden" name="target_actor" value="<?= h($actorUrl) ?>">
                    <button class="icon-btn on" type="submit" title="Unlike" aria-label="Unlike"><i class="ph-fill ph-heart" aria-hidden="true"></i></button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($view === 'bookmarks'): ?>

        <?php
          $bmFolders = function_exists('vaak_bookmark_folders_list')
              ? vaak_bookmark_folders_list($vaakOwnerId)
              : [];
          $bmFolderFilter = (int) ($_GET['folder'] ?? 0);
          $bmList = ap_masto_bookmarks_list(80, null);
          if ($bmFolderFilter > 0 && function_exists('vaak_bookmark_folder_status_ids')) {
              $allowed = array_fill_keys(
                  vaak_bookmark_folder_status_ids($bmFolderFilter, $vaakOwnerId, 500),
                  true
              );
              $bmList = array_values(array_filter(
                  $bmList,
                  static fn($st) => isset($allowed[(string) ($st['id'] ?? '')])
              ));
          }
        ?>
        <section class="side-card" style="margin-bottom:1rem">
          <div style="display:flex;flex-wrap:wrap;gap:.45rem;align-items:center;margin-bottom:.65rem">
            <a class="btn <?= $bmFolderFilter < 1 ? 'btn-primary' : 'btn-ghost' ?>" href="?view=bookmarks" style="padding:.3rem .7rem;font-size:.82rem">All</a>
            <?php foreach ($bmFolders as $bf): ?>
              <?php $bfid = (int) ($bf['id'] ?? 0); ?>
              <a class="btn <?= $bmFolderFilter === $bfid ? 'btn-primary' : 'btn-ghost' ?>" href="?view=bookmarks&amp;folder=<?= $bfid ?>" style="padding:.3rem .7rem;font-size:.82rem">
                <?= h((string) ($bf['title'] ?? 'Folder')) ?>
                <span class="meta">(<?= (int) ($bf['item_count'] ?? 0) ?>)</span>
              </a>
            <?php endforeach; ?>
          </div>
          <form method="post" action="?view=bookmarks" class="composer" style="margin:0;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
            <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
            <input type="hidden" name="action" value="bookmark_folder_create">
            <input type="hidden" name="return_view" value="bookmarks">
            <input name="title" maxlength="80" placeholder="New folder name…" required style="flex:1;min-width:10rem">
            <button class="btn btn-ghost" type="submit">Create folder</button>
          </form>
          <div class="meta" style="margin-top:.55rem">Folders are VAAK-only.</div>
        </section>
        <?php if ($bmFolderFilter > 0): ?>
          <form method="post" action="?view=bookmarks" style="margin:0 0 1rem" onsubmit="return confirm('Delete this folder? Bookmarks stay saved under All.');">
            <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
            <input type="hidden" name="action" value="bookmark_folder_delete">
            <input type="hidden" name="return_view" value="bookmarks">
            <input type="hidden" name="folder_id" value="<?= $bmFolderFilter ?>">
            <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Delete this folder</button>
          </form>
        <?php endif; ?>
        <?php if (!$bmList): ?>
          <div class="empty"><?= $bmFolderFilter > 0 ? 'No bookmarks in this folder yet.' : 'No bookmarks yet.' ?></div>
        <?php else: ?>
          <?php foreach ($bmList as $st): ?>
            <?php
              $acct = (string) ($st['account']['acct'] ?? '?');
              $sid = (string) ($st['id'] ?? '');
              $oid = (string) ($st['uri'] ?? '');
              $actorUrl = (string) ($st['account']['url'] ?? $st['account']['uri'] ?? '');
            ?>
            <article class="tweet">
              <div class="tweet-hd">
                <?= admin_avatar_img($actorUrl !== '' ? $actorUrl : null) ?>
                <div class="tweet-hd-main">
                  <div>
                    <span class="who"><?= h($acct) ?></span>
                    <span class="meta"> · <?= h((string) ($st['created_at'] ?? '')) ?></span>
                  </div>
                </div>
              </div>
              <?php
                $bmPlain = admin_html_to_plain((string) ($st['content'] ?? ''));
                $bmMentions = is_array($st['mentions'] ?? null) ? $st['mentions'] : [];
                echo admin_cw_gate_html(
                    (string) ($st['spoiler_text'] ?? ''),
                    !empty($st['sensitive']) || trim((string) ($st['spoiler_text'] ?? '')) !== '',
                    $bmPlain !== ''
                        ? '<div class="body feed-body">' . admin_linkify_body_html($bmPlain, 'bookmarks', $bmMentions) . '</div>'
                        : ''
                );
              ?>
              <div class="tweet-actions">
                <?php if ($oid !== ''): ?>
                  <a class="btn btn-ghost" href="<?= h(admin_status_href($oid, 'bookmarks')) ?>" style="padding:.25rem .7rem;font-size:.8rem">Open</a>
                  <a href="<?= h(admin_remote_object_href($oid)) ?>" target="_blank" rel="noopener noreferrer" class="meta">Remote</a>
                <?php endif; ?>
                <?php if ($sid !== ''): ?>
                  <form method="post" action="?view=bookmarks" style="display:inline" class="bm-folder-trigger">
                    <input type="hidden" name="action" value="unbookmark_status">
                    <input type="hidden" name="return_view" value="bookmarks">
                    <input type="hidden" name="status_id" value="<?= h($sid) ?>">
                    <input type="hidden" name="object_id" value="<?= h($oid) ?>">
                    <button class="icon-btn on" type="submit" title="Bookmark folders" aria-label="Bookmark folders" data-bm-picker="1"><i class="ph-fill ph-bookmark-simple" aria-hidden="true"></i></button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($view === 'mentions'): ?>
        <?php
          // Same unified feed as Ice Cubes: follows, likes, boosts, mentions, quotes…
          $notifFilter = strtolower(trim((string) ($_GET['notification_filter'] ?? 'all')));
          $notifFilterOptions = [
              'all' => ['label' => 'All', 'types' => []],
              'mentions' => ['label' => 'Mentions', 'types' => ['mention']],
              'favourites' => ['label' => 'Favourites', 'types' => ['favourite']],
              'boosts_quotes' => ['label' => 'Boosts/Quotes', 'types' => ['reblog', 'quote']],
          ];
          if (!isset($notifFilterOptions[$notifFilter])) {
              $notifFilter = 'all';
          }
          $notifTypes = $notifFilterOptions[$notifFilter]['types'];
          $notifFilterHref = static function (string $filter): string {
              return '?view=mentions&notification_filter=' . rawurlencode($filter);
          };
          echo '<nav class="notification-tabs" aria-label="Notification filters" style="display:flex;gap:.5rem;flex-wrap:wrap;margin:0 0 1rem">';
          foreach ($notifFilterOptions as $filterKey => $filterOption) {
              $active = $notifFilter === $filterKey;
              echo '<a class="btn ' . ($active ? 'btn-primary' : 'btn-ghost') . '" role="tab" aria-selected="' . ($active ? 'true' : 'false') . '" href="' . $notifFilterHref($filterKey) . '">' . h((string) $filterOption['label']) . '</a>';
          }
          echo '</nav>';
          $notifLimit = 20;
          $adminNotifs = [];
          // First page only — older pages append via ?partial=1 (keeps scroll place).
          try {
              $adminNotifs = function_exists('ap_masto_notifications_fetch')
                  ? ap_masto_notifications_fetch($notifLimit, null, null, $notifTypes)
                  : [];
          } catch (Throwable $e) {
              error_log('[ap-admin] notifications fetch: ' . $e->getMessage());
          }
          $notifNextMaxId = '';
          if ($adminNotifs !== []) {
              $notifNextMaxId = preg_replace('/\D+/', '', (string) ($adminNotifs[count($adminNotifs) - 1]['id'] ?? '')) ?: '';
          }
          $notifHasMore = count($adminNotifs) >= $notifLimit && $notifNextMaxId !== '';
          if (!$adminNotifs):
        ?>
          <div class="empty">No notifications yet.</div>
        <?php else: ?>
          <div id="timeline-items" data-view="mentions" data-filter="<?= h($notifFilter) ?>" data-limit="<?= (int) $notifLimit ?>" data-max-id="<?= h($notifNextMaxId) ?>" data-has-more="<?= $notifHasMore ? '1' : '0' ?>" data-offset="0" data-newest="0">
            <?php foreach ($adminNotifs as $n): ?>
              <?php admin_render_notification_card(is_array($n) ? $n : [], $followingIds, $followerIds); ?>
            <?php endforeach; ?>
          </div>
          <div id="timeline-status" class="meta" style="padding:.75rem 0;text-align:center"><?= $notifHasMore ? 'Scroll for more…' : 'End of notifications' ?></div>
          <div id="timeline-sentinel" aria-hidden="true" style="height:1px"></div>
        <?php endif; ?>

      <?php elseif ($view === 'dms'): ?>
        <?php
          $dmPeer = trim((string) ($_GET['peer'] ?? ''));
          // Normalize peer if the query string was partially decoded
          if ($dmPeer !== '' && str_starts_with($dmPeer, 'https://')) {
              $dmPeer = rtrim($dmPeer, '/');
          }
          $dmConversations = ap_dm_conversations(60);
          $dmUnread = ap_dm_unread_count();
        ?>
        <?php if ($dmUnread > 0): ?>
          <div class="meta" style="margin-bottom:.75rem">Unread <b><?= (int) $dmUnread ?></b></div>
        <?php endif; ?>
        <?php if ($dmPeer === ''): ?>
          <form class="composer" method="post" action="?view=dms" style="margin-bottom:1rem">
            <input type="hidden" name="action" value="dm_send">
            <div class="meta" style="margin-bottom:.5rem">New direct message</div>
            <input name="to" type="text" required placeholder="@user@instance or https://…/users/…">
            <textarea name="content" maxlength="2000" required placeholder="Private message…" style="margin-top:.5rem"></textarea>
            <div class="composer-actions">
              <span class="meta"></span>
              <button class="btn btn-primary" type="submit">Send DM</button>
            </div>
          </form>
          <?php if (!$dmConversations): ?>
            <div class="empty">No direct messages yet.</div>
          <?php endif; ?>
          <?php foreach ($dmConversations as $c): ?>
            <?php
              $last = $c['last'];
              $previewHtml = admin_dm_html($last['content'] ?? null, is_array($last) ? $last : null);
              $peerId = (string) $c['peer_actor_id'];
            ?>
            <article class="tweet">
              <div class="tweet-hd">
                <?= admin_avatar_img($peerId) ?>
                <div class="tweet-hd-main">
                  <div>
                    <span class="who"><?= actor_display_name_html($peerId) ?></span>
                    <span class="meta"> <?= h(actor_handle($peerId)) ?></span>
                    <?php if (((int) $c['unread']) > 0): ?>
                      <span class="tag">unread <?= (int) $c['unread'] ?></span>
                    <?php endif; ?>
                  </div>
                  <div class="meta"><?= ($last['direction'] ?? '') === 'out' ? 'You' : 'Them' ?> · <?= h(relative_time((string) ($last['created_at'] ?? ''))) ?></div>
                </div>
              </div>
              <div class="dm-bubble<?= ($last['direction'] ?? '') === 'out' ? ' dm-out' : ' dm-in' ?>"><?= $previewHtml !== '' ? $previewHtml : '<span class="meta">(no text)</span>' ?></div>
              <div class="tweet-actions">
                <a class="btn btn-primary" href="?view=dms&amp;peer=<?= urlencode($peerId) ?>" style="padding:.35rem .9rem;font-size:.85rem">Open thread</a>
                <?= block_quick_actions($peerId, short_host($peerId), 'dms', $vaakOwnerId, !empty($vaakIsAdmin), 'dms') ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php else: ?>
          <?php
            $thread = ap_dm_thread($dmPeer, 200);
            // Mark read after load so a lock/failure can't blank the thread
            try {
                ap_dm_mark_peer_read($dmPeer);
            } catch (Throwable $e) {
                error_log('[ap-admin] dm mark read: ' . $e->getMessage());
            }
          ?>
          <div style="margin-bottom:.75rem;display:flex;flex-wrap:wrap;gap:.5rem;align-items:center">
            <div class="page-back"><a class="btn btn-ghost" href="?view=dms">← All conversations</a></div>
            <?= admin_avatar_img($dmPeer) ?>
            <div>
              <div class="who"><?= actor_display_name_html($dmPeer) ?></div>
              <div class="meta"><?= h(actor_handle($dmPeer)) ?></div>
            </div>
          </div>
          <div id="dm-thread" class="dm-thread">
          <?php if (!$thread): ?>
            <div class="empty">Empty thread — no stored messages for this peer.</div>
          <?php endif; ?>
          <?php foreach ($thread as $dmIndex => $m): ?>
            <?php
              $isOut = ($m['direction'] ?? '') === 'out';
              $msgHtml = admin_dm_html($m['content'] ?? null, $m);
              $msgObj = (string) ($m['object_id'] ?? '');
            ?>
            <article class="tweet"<?= $dmIndex === count($thread) - 1 ? ' id="dm-message-last"' : '' ?>>
              <div class="tweet-hd">
                <?= admin_avatar_img($isOut ? vaak_actor_id() : (string) ($m['peer_actor_id'] ?? '')) ?>
                <div class="tweet-hd-main">
                  <div>
                    <span class="who"><?= $isOut ? h('@' . vaak_actor_key()) : actor_display_name_html((string) ($m['peer_actor_id'] ?? '')) ?></span>
                    <span class="meta"> · <?= $isOut ? 'You' : 'Them' ?> · <?= h(relative_time((string) ($m['created_at'] ?? ''))) ?></span>
                  </div>
                </div>
              </div>
              <div class="dm-bubble<?= $isOut ? ' dm-out' : ' dm-in' ?>"><?= $msgHtml !== '' ? $msgHtml : '<span class="meta">(no text in this message)</span>' ?></div>
              <?php
                $dMedia = mention_media_urls($m['media_urls'] ?? null);
                if ($dMedia) {
                    echo admin_media_row_html($dMedia);
                }
              ?>
              <div class="tweet-actions">
                <?php if ($msgObj !== '' && str_starts_with($msgObj, 'https://')): ?>
                  <a href="<?= h($msgObj) ?>" target="_blank" rel="noopener noreferrer" class="meta">Open remote</a>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
          </div>
          <form class="composer" method="post" action="?view=dms&amp;peer=<?= urlencode($dmPeer) ?>" style="margin-top:1rem">
            <input type="hidden" name="action" value="dm_reply">
            <input type="hidden" name="to" value="<?= h($dmPeer) ?>">
            <input type="hidden" name="peer" value="<?= h($dmPeer) ?>">
            <?php
              $lastIn = null;
              for ($i = count($thread) - 1; $i >= 0; $i--) {
                  if (($thread[$i]['direction'] ?? '') === 'in') {
                      $lastIn = $thread[$i];
                      break;
                  }
              }
            ?>
            <?php if ($lastIn): ?>
              <input type="hidden" name="in_reply_to" value="<?= h((string) $lastIn['object_id']) ?>">
            <?php endif; ?>
            <textarea name="content" maxlength="2000" required placeholder="Reply privately…"></textarea>
            <div class="composer-actions">
              <span class="meta">Stays direct — not public</span>
              <button class="btn btn-primary" type="submit">Reply</button>
            </div>
          </form>
          <script>
          (function () {
            function focusLatestDm() {
              const feed = document.querySelector('.feed');
              const thread = document.getElementById('dm-thread');
              if (!feed || !thread) return;
              const last = document.getElementById('dm-message-last');
              const feedRect = feed.getBoundingClientRect();
              const targetRect = (last || thread).getBoundingClientRect();
              const target = feed.scrollTop + targetRect.bottom - feedRect.bottom + 24;
              const maxTop = Math.max(0, feed.scrollHeight - feed.clientHeight);
              feed.scrollTop = Math.min(maxTop, Math.max(0, target));
            }
            requestAnimationFrame(function () {
              requestAnimationFrame(focusLatestDm);
            });
            window.addEventListener('load', focusLatestDm, { once: true });
          })();
          </script>
        <?php endif; ?>

      <?php elseif ($view === 'report'): ?>
        <?php
          $reportTargetPrefill = trim((string) ($_GET['report_target'] ?? ''));
          $reportObjectPrefill = trim((string) ($_GET['report_object'] ?? ''));
          if ($reportObjectPrefill !== '' && !str_starts_with($reportObjectPrefill, 'https://')) {
              $reportObjectPrefill = '';
          }
          $reportFrom = preg_replace('/[^a-z_]/', '', (string) ($_GET['from'] ?? '')) ?: '';
          $reportPrefillActive = $reportTargetPrefill !== '' || $reportObjectPrefill !== '';
          $reportTargetDisplay = $reportTargetPrefill;
          if ($reportTargetPrefill !== '' && str_starts_with($reportTargetPrefill, 'https://')) {
              $reportTargetDisplay = actor_handle($reportTargetPrefill) ?: $reportTargetPrefill;
          }
          $reportBackHref = $reportFrom !== '' ? ('?view=' . rawurlencode($reportFrom)) : '?view=home';
          if ($reportFrom === 'remote_profile' && $reportTargetPrefill !== '') {
              $reportBackHref = '?view=remote_profile&actor=' . rawurlencode($reportTargetPrefill);
          }
        ?>
        <div class="page-back">
          <a class="btn btn-ghost" href="<?= h($reportBackHref) ?>">← Back</a>
        </div>
        <h2 style="font-size:1.05rem;margin:0 0 .5rem">Report an account</h2>
        <div class="meta" style="margin-bottom:.75rem">
          Sends an ActivityPub <code>Flag</code> to their moderators (or files it for VAAK mods if they’re local).
        </div>
        <form class="composer" method="post" action="?view=report" style="margin-bottom:1.25rem">
          <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
          <input type="hidden" name="action" value="report_remote">
          <input type="hidden" name="return_view" value="report">
          <?php if ($reportFrom !== ''): ?>
            <input type="hidden" name="return_from" value="<?= h($reportFrom) ?>">
          <?php endif; ?>
          <?php if ($reportTargetPrefill !== ''): ?>
            <input type="hidden" name="return_actor" value="<?= h($reportTargetPrefill) ?>">
          <?php endif; ?>
          <label for="report-target">Account</label>
          <input id="report-target" name="target" type="text" required placeholder="@user@instance or https://…/users/…" value="<?= h($reportTargetPrefill) ?>"<?= $reportPrefillActive && $reportTargetPrefill === '' ? ' autofocus' : '' ?>>
          <?php if ($reportTargetPrefill !== '' && $reportTargetDisplay !== '' && $reportTargetDisplay !== $reportTargetPrefill): ?>
            <div class="meta" style="margin-top:.35rem">Account: <?= h($reportTargetDisplay) ?></div>
          <?php endif; ?>
          <label for="report-comment" style="margin-top:.65rem;display:block">Comment</label>
          <textarea id="report-comment" name="comment" maxlength="5000" placeholder="Optional comment for moderators" style="margin-top:.35rem;min-height:3.5rem"<?= $reportPrefillActive ? ' autofocus' : '' ?>></textarea>
          <label for="report-object" style="margin-top:.65rem;display:block">Post URL (optional)</label>
          <input id="report-object" name="object_id" type="url" placeholder="Optional post URL to attach" style="margin-top:.35rem" value="<?= h($reportObjectPrefill) ?>">
          <div class="composer-actions" style="margin-top:.75rem">
            <span class="meta">Visible to every VAAK account</span>
            <button class="btn btn-primary" type="submit">Send report</button>
          </div>
        </form>

      <?php elseif ($view === 'moderation'): ?>
        <?php
          $modFilter = preg_replace('/[^a-z_]/', '', (string) ($_GET['filter'] ?? 'open')) ?: 'open';
          if (!in_array($modFilter, ['open', 'about_us', 'outbound', 'closed', 'all'], true)) {
              $modFilter = 'open';
          }
          $modReports = ap_reports_list($modFilter, 80);
          $reportTargetPrefill = trim((string) ($_GET['report_target'] ?? ''));
          $reportObjectPrefill = trim((string) ($_GET['report_object'] ?? ''));
          if ($reportObjectPrefill !== '' && !str_starts_with($reportObjectPrefill, 'https://')) {
              $reportObjectPrefill = '';
          }
          $reportFrom = preg_replace('/[^a-z_]/', '', (string) ($_GET['from'] ?? '')) ?: '';
          $reportPrefillActive = $reportTargetPrefill !== '' || $reportObjectPrefill !== '';
          $reportTargetDisplay = $reportTargetPrefill;
          if ($reportTargetPrefill !== '' && str_starts_with($reportTargetPrefill, 'https://')) {
              $reportTargetDisplay = actor_handle($reportTargetPrefill) ?: $reportTargetPrefill;
          }
          // An account deep-link from a post/profile overflow menu. Keep this
          // on the moderation surface instead of silently returning to the
          // ordinary remote-profile view.
          $modActor = trim((string) ($_GET['actor'] ?? ''));
          if ($modActor !== '' && !str_starts_with($modActor, 'https://')) {
              $modActorResolved = function_exists('ap_resolve_actor_ref') ? ap_resolve_actor_ref($modActor) : null;
              if (is_string($modActorResolved) && $modActorResolved !== '') {
                  $modActor = $modActorResolved;
              }
          }
          $modActor = rtrim($modActor, '/');
          $modActorControl = ($modActor !== '' && str_starts_with($modActor, 'https://'))
              ? admin_global_actor_control($modActor)
              : null;
        ?>
        <?php if ($modActor !== ''): ?>
          <article class="tweet" style="margin-bottom:1.25rem;border-color:var(--primary)">
            <div class="tweet-hd">
              <div>
                <div class="who">Account moderation</div>
                <div class="meta"><?= h(actor_handle($modActor) ?: $modActor) ?></div>
              </div>
              <div class="meta">admin only</div>
            </div>
            <div class="body meta" style="margin-top:.5rem">
              Apply a server-wide control to this account, or inspect its cached profile and recent activity.
            </div>
            <div class="tweet-actions" style="flex-wrap:wrap">
              <?php if ($modActorControl && ($modActorControl['kind'] ?? '') === 'mute'): ?>
                <form method="post" action="?view=moderation&amp;actor=<?= rawurlencode($modActor) ?>" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
                  <input type="hidden" name="action" value="unblock">
                  <input type="hidden" name="id" value="<?= (int) ($modActorControl['id'] ?? 0) ?>">
                  <button class="btn btn-ghost" type="submit">Remove global mute</button>
                </form>
              <?php else: ?>
                <form method="post" action="?view=moderation&amp;actor=<?= rawurlencode($modActor) ?>" style="display:inline" onsubmit="return confirm('Mute this user for everyone on Vaak?');">
                  <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
                  <input type="hidden" name="action" value="block_actor">
                  <input type="hidden" name="kind" value="mute">
                  <input type="hidden" name="actor_id" value="<?= h($modActor) ?>">
                  <button class="btn btn-ghost" type="submit">Global mute</button>
                </form>
              <?php endif; ?>
              <?php if ($modActorControl && in_array((string) ($modActorControl['kind'] ?? ''), ['block', 'suspend'], true)): ?>
                <form method="post" action="?view=moderation&amp;actor=<?= rawurlencode($modActor) ?>" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
                  <input type="hidden" name="action" value="unblock">
                  <input type="hidden" name="id" value="<?= (int) ($modActorControl['id'] ?? 0) ?>">
                  <button class="btn btn-ghost" type="submit">Remove server block</button>
                </form>
              <?php else: ?>
                <form method="post" action="?view=moderation&amp;actor=<?= rawurlencode($modActor) ?>" style="display:inline" onsubmit="return confirm('Block this user server-wide? VAAK will reject their federation (HTTP 403) and stop delivering to them.');">
                  <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
                  <input type="hidden" name="action" value="block_actor">
                  <input type="hidden" name="kind" value="block">
                  <input type="hidden" name="actor_id" value="<?= h($modActor) ?>">
                  <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Block server-wide</button>
                </form>
              <?php endif; ?>
              <a class="btn btn-ghost" href="?view=remote_profile&amp;actor=<?= rawurlencode($modActor) ?>&amp;from=moderation">Open profile</a>
              <a class="btn btn-ghost" href="?view=moderation">Clear account</a>
            </div>
          </article>
        <?php endif; ?>
        <div class="meta" style="margin-bottom:.75rem">
          Open queue includes inbound Flags and reports filed by local users awaiting review.
          Use <b>Dismiss</b> / <b>Ignore</b> to clear items from the queue (clears the Moderation badge).
          Remote outbound reports (already sent) appear under <b>Sent</b>.
        </div>
        <div class="tweet-actions" style="flex-wrap:wrap;gap:.4rem;margin-bottom:1rem">
          <?php foreach ([
              'open' => 'Open inbox',
              'about_us' => 'About me',
              'outbound' => 'Sent',
              'closed' => 'Dismissed / ignored',
              'all' => 'All',
          ] as $fk => $fl): ?>
            <a class="btn <?= $modFilter === $fk ? 'btn-primary' : 'btn-ghost' ?>" href="?view=moderation&amp;filter=<?= h($fk) ?>" style="padding:.3rem .8rem;font-size:.85rem"><?= h($fl) ?></a>
          <?php endforeach; ?>
        </div>

        <form class="composer" id="report-composer" method="post" action="?view=moderation" style="margin-bottom:1.25rem">
          <input type="hidden" name="action" value="report_remote">
          <input type="hidden" name="return_view" value="moderation">
          <?php if ($reportFrom !== ''): ?>
            <input type="hidden" name="return_from" value="<?= h($reportFrom) ?>">
          <?php endif; ?>
          <div class="meta" style="margin-bottom:.5rem">
            Report a remote account (sends <code>Flag</code>)
            <?php if ($reportPrefillActive): ?>
              · <span style="color:var(--primary)">prefilled from timeline</span>
              <?php if ($reportFrom !== ''): ?>
                · <a href="?view=<?= h($reportFrom) ?>">← back</a>
              <?php endif; ?>
            <?php endif; ?>
          </div>
          <input name="target" type="text" required placeholder="@user@instance or https://…/users/…" value="<?= h($reportTargetPrefill) ?>"<?= $reportPrefillActive && $reportTargetPrefill === '' ? ' autofocus' : '' ?>>
          <?php if ($reportTargetPrefill !== '' && $reportTargetDisplay !== '' && $reportTargetDisplay !== $reportTargetPrefill): ?>
            <div class="meta" style="margin-top:.35rem">Account: <?= h($reportTargetDisplay) ?></div>
          <?php endif; ?>
          <textarea name="comment" maxlength="5000" placeholder="Optional comment for their moderators" style="margin-top:.5rem;min-height:3.5rem"<?= $reportPrefillActive ? ' autofocus' : '' ?>></textarea>
          <input name="object_id" type="url" placeholder="Optional post URL to attach" style="margin-top:.5rem" value="<?= h($reportObjectPrefill) ?>">
          <?php if ($reportObjectPrefill !== ''): ?>
            <div class="meta" style="margin-top:.35rem">
              Attached post:
              <a href="<?= h(admin_status_href($reportObjectPrefill, $reportFrom !== '' ? $reportFrom : 'moderation')) ?>">Open in VAAK</a>
              · <a href="<?= h($reportObjectPrefill) ?>" target="_blank" rel="noopener noreferrer">Remote</a>
            </div>
          <?php endif; ?>
          <div class="composer-actions">
            <span class="meta">Delivered to their shared inbox when possible</span>
            <button class="btn btn-primary" type="submit">Send report</button>
          </div>
        </form>
        <?php if ($reportPrefillActive): ?>
        <script>
        (function () {
          var el = document.getElementById('report-composer');
          if (el) el.scrollIntoView({ behavior: 'smooth', block: 'start' });
        })();
        </script>
        <?php endif; ?>

        <?php if (!$modReports): ?>
          <div class="empty">No reports in this filter.</div>
        <?php else: ?>
          <?php foreach ($modReports as $rep): ?>
            <?php
              $repId = (int) ($rep['id'] ?? 0);
              $dir = (string) ($rep['direction'] ?? '');
              $state = (string) ($rep['state'] ?? '');
              $reporter = (string) ($rep['reporter_actor_id'] ?? '');
              $target = (string) ($rep['target_actor_id'] ?? '');
              $aboutUs = !empty($rep['about_us']);
              $priority = (int) ($rep['priority_score'] ?? 0);
              $comment = trim((string) ($rep['comment'] ?? ''));
              $statusUris = [];
              $rawJson = (string) ($rep['status_uris_json'] ?? '');
              if ($rawJson !== '') {
                  $decoded = json_decode($rawJson, true);
                  if (is_array($decoded)) {
                      foreach ($decoded as $su) {
                          if (is_string($su) && $su !== '') {
                              $statusUris[] = $su;
                          }
                      }
                  }
              }
            ?>
            <?php
              $isLocalFiled = $dir === 'out'
                  && str_starts_with($reporter, 'https://mkultra.monster/users/');
              $repLabel = $dir === 'in'
                  ? 'Inbound Flag'
                  : ($isLocalFiled ? 'Local user report' : 'Outbound Flag');
              $canClose = $state === 'open' && ($dir === 'in' || $isLocalFiled);
            ?>
            <article class="tweet" style="<?= $aboutUs ? 'border-color:var(--primary)' : '' ?>">
              <div class="tweet-hd">
                <div class="tweet-hd-main">
                  <div>
                    <span class="who"><?= h($repLabel) ?></span>
                    <span class="tag"><?= h($state) ?></span>
                    <?php if ($aboutUs): ?><span class="tag">about you</span><?php endif; ?>
                    <span class="tag" title="Transparent triage score; does not auto-moderate">priority <?= $priority ?></span>
                    <?php if ($isLocalFiled): ?><span class="tag">needs review</span><?php endif; ?>
                    <span class="meta"> · <?= h(relative_time((string) ($rep['created_at'] ?? ''))) ?></span>
                  </div>
                  <div class="meta">
                    From <?= h($reporter !== '' ? actor_handle($reporter) : '?') ?>
                    → <?= h($target !== '' ? actor_handle($target) : 'unknown target') ?>
                  </div>
                </div>
              </div>
              <?php if ($comment !== ''): ?>
                <div class="body" style="margin:.35rem 0;white-space:pre-wrap"><?= h($comment) ?></div>
              <?php endif; ?>
              <?php if ($statusUris): ?>
                <div class="meta" style="margin:.35rem 0">Attached posts:</div>
                <ul style="margin:.25rem 0 .5rem 1.1rem;padding:0">
                  <?php foreach (array_slice($statusUris, 0, 8) as $su): ?>
                    <li class="mono" style="margin:.2rem 0;word-break:break-all">
                      <a href="<?= h(admin_status_href($su, 'moderation')) ?>"><?= h($su) ?></a>
                    </li>
                  <?php endforeach; ?>
                </ul>
              <?php endif; ?>
              <div class="tweet-actions" style="flex-wrap:wrap">
                <?php if ($reporter !== '' && str_starts_with($reporter, 'https://')): ?>
                  <a href="?view=remote_profile&amp;actor=<?= urlencode($reporter) ?>&amp;from=moderation">Reporter</a>
                <?php endif; ?>
                <?php if ($target !== '' && str_starts_with($target, 'https://')): ?>
                  <a href="?view=remote_profile&amp;actor=<?= urlencode($target) ?>&amp;from=moderation">Target</a>
                <?php endif; ?>
                <?php if ($canClose): ?>
                  <form method="post" action="?view=moderation&amp;filter=<?= h($modFilter) ?>" style="display:inline">
                    <input type="hidden" name="action" value="report_ignore">
                    <input type="hidden" name="report_id" value="<?= $repId ?>">
                    <input type="hidden" name="filter" value="<?= h($modFilter) ?>">
                    <button class="btn btn-primary" type="submit" style="padding:.3rem .8rem;font-size:.85rem" title="Clear from open queue">Ignore</button>
                  </form>
                  <form method="post" action="?view=moderation&amp;filter=<?= h($modFilter) ?>" style="display:inline">
                    <input type="hidden" name="action" value="report_dismiss">
                    <input type="hidden" name="report_id" value="<?= $repId ?>">
                    <input type="hidden" name="filter" value="<?= h($modFilter) ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.3rem .8rem;font-size:.85rem" title="Mark handled / dismiss">Dismiss</button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($view === 'blocks'): ?>
        <div class="meta" style="margin-bottom:1rem">
          <b>Server-wide controls</b>:
          <b>Block / Suspend</b> fully reject inbound federation (HTTP 403) and stop outbound delivery to that user/instance;
          <b>Global mute</b> only hides their content locally (they can still federate).
          Personal mutes/blocks live under <a href="?view=profile">Profile</a>.
        </div>
        <form class="composer" method="post" action="?view=blocks" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="block_add">
          <div class="meta" style="margin-bottom:.5rem">
            Mute, block, or suspend a <b>domain</b> (<code>example.com</code>) or <b>user</b> (<code>@user@host</code> / actor URL).
          </div>
          <input name="target" type="text" required placeholder="example.com  or  @user@instance  or  https://…/users/…">
          <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin:.5rem 0">
            <label class="meta" style="display:flex;gap:.35rem;align-items:center;color:var(--text)">
              <input type="radio" name="kind" value="block" checked> Block
            </label>
            <label class="meta" style="display:flex;gap:.35rem;align-items:center;color:var(--text)">
              <input type="radio" name="kind" value="suspend"> Suspend
            </label>
            <label class="meta" style="display:flex;gap:.35rem;align-items:center;color:var(--text)">
              <input type="radio" name="kind" value="mute"> Global mute
            </label>
          </div>
          <input name="reason" type="text" maxlength="500" placeholder="Optional note (spam, harassment, …)">
          <div class="composer-actions">
            <span class="meta"><?= count($blocks) ?> controls · block/suspend = reject federation (403); mute = hide only</span>
            <button class="btn btn-primary" type="submit">Add control</button>
          </div>
        </form>

        <h2 style="font-size:1rem;margin:1.25rem 0 .5rem">Server-wide user &amp; domain controls</h2>
        <?php if (!$blocks): ?>
          <div class="empty">No server blocks yet.</div>
        <?php endif; ?>
        <?php foreach ($blocks as $b): ?>
          <article class="tweet">
            <div class="tweet-hd">
              <div>
                <span class="who"><?= h((string) $b['value']) ?></span>
                <span class="meta"> · <?= h((string) $b['scope']) ?> · <?= h((string) $b['kind']) ?> · <?= h(relative_time((string) $b['created_at'])) ?></span>
              </div>
              <div class="meta">#<?= (int) $b['id'] ?></div>
            </div>
            <?php if (!empty($b['reason'])): ?>
              <div class="body meta"><?= h((string) $b['reason']) ?></div>
            <?php endif; ?>
            <div class="tweet-actions">
              <form method="post" action="?view=blocks" style="display:inline">
                <input type="hidden" name="action" value="unblock">
                <input type="hidden" name="id" value="<?= (int) $b['id'] ?>">
                <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Unblock</button>
              </form>
              <?php if (($b['scope'] ?? '') === 'actor'): ?>
                <a href="?view=remote_profile&amp;actor=<?= urlencode((string) $b['value']) ?>&amp;from=blocks">Profile</a>
                <a href="<?= h((string) $b['value']) ?>" target="_blank" rel="noopener noreferrer">Open</a>
              <?php else: ?>
                <a href="https://<?= h((string) $b['value']) ?>/" target="_blank" rel="noopener noreferrer">Open host</a>
              <?php endif; ?>
            </div>
          </article>
        <?php endforeach; ?>

      <?php elseif ($view === 'users'): ?>
        <?php if (empty($vaakIsAdmin)): ?>
          <div class="empty">Admin only.</div>
        <?php else: ?>
          <?php
            $usersSearch = trim((string) ($_GET['q'] ?? $_POST['users_q'] ?? ''));
            $usersPage = max(1, (int) ($_GET['page'] ?? $_POST['users_page'] ?? 1));
            $usersPerPage = 20;
            $usersTotal = function_exists('ap_auth_users_count') ? ap_auth_users_count($usersSearch) : 0;
            $usersPages = max(1, (int) ceil($usersTotal / $usersPerPage));
            if ($usersPage > $usersPages) {
                $usersPage = $usersPages;
            }
            $usersOffset = ($usersPage - 1) * $usersPerPage;
            $localUsers = ap_auth_users_list($usersPerPage, $usersSearch, $usersOffset);
            $usersQuery = $usersSearch !== '' ? '&amp;q=' . rawurlencode($usersSearch) : '';
          ?>
          <div class="meta" style="margin-bottom:1rem">
            Local VAAK accounts. Banning disables web login, rejects future API
            authentication, and revokes the user’s OAuth tokens. The operator account
            cannot be banned from this page.
          </div>
          <form method="get" action="" style="display:flex;gap:.5rem;align-items:center;margin-bottom:1rem">
            <input type="hidden" name="view" value="users">
            <input type="search" name="q" value="<?= h($usersSearch) ?>" placeholder="Search username, email, or display name" aria-label="Search users" style="flex:1;min-width:0">
            <button class="btn btn-ghost" type="submit">Search</button>
            <?php if ($usersSearch !== ''): ?><a class="btn btn-ghost" href="?view=users">Clear</a><?php endif; ?>
          </form>
          <div class="meta" style="margin-bottom:.75rem">
            <?= $usersTotal ?> account<?= $usersTotal === 1 ? '' : 's' ?><?= $usersSearch !== '' ? ' matching “' . h($usersSearch) . '”' : '' ?>
          </div>
          <?php if (!$localUsers): ?>
            <div class="empty">No local users found.</div>
          <?php else: ?>
            <div style="display:flex;flex-direction:column;gap:.7rem">
            <?php foreach ($localUsers as $localUser): ?>
              <?php
                $uid = (int) ($localUser['id'] ?? 0);
                $ukey = (string) ($localUser['actor_key'] ?? '');
                $uname = (string) ($localUser['profile_name'] ?? '') ?: (string) ($localUser['username'] ?? $ukey);
                $disabled = !empty($localUser['disabled_at']);
                $isOperator = $ukey === 'cmdr_nova' || !empty($localUser['is_admin']);
              ?>
              <article class="tweet">
                <div class="tweet-hd">
                  <div style="display:flex;align-items:center;gap:.65rem;min-width:0">
                    <?php if (!empty($localUser['icon_url'])): ?>
                      <img src="<?= h((string) $localUser['icon_url']) ?>" alt="" width="42" height="42"
                           style="width:42px;height:42px;border-radius:50%;object-fit:cover;border:1px solid var(--border);flex:0 0 auto"
                           loading="lazy" referrerpolicy="no-referrer">
                    <?php endif; ?>
                    <div style="min-width:0">
                      <div class="who" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h($uname) ?></div>
                      <div class="meta">@<?= h((string) ($localUser['username'] ?? $ukey)) ?>@mkultra.monster</div>
                    </div>
                  </div>
                  <div class="meta" style="text-align:right">
                    <?= $isOperator ? '<span class="tag">operator</span>' : ($disabled ? '<span class="tag" style="color:var(--danger)">banned</span>' : '<span class="tag">active</span>') ?>
                  </div>
                </div>
                <div class="meta" style="margin-top:.55rem">
                  Joined <?= h(relative_time((string) ($localUser['created_at'] ?? ''))) ?>
                  <?php if ($disabled): ?> · banned <?= h(relative_time((string) $localUser['disabled_at'])) ?><?php endif; ?>
                </div>
                <div class="tweet-actions" style="flex-wrap:wrap">
                  <a href="/users/<?= h(rawurlencode($ukey)) ?>" target="_blank" rel="noopener noreferrer">View profile</a>
                  <?php if (!$isOperator): ?>
                    <form method="post" action="?view=users" style="display:inline" onsubmit="return confirm('<?= $disabled ? 'Unban' : 'Ban' ?> this local user?');">
                      <input type="hidden" name="action" value="<?= $disabled ? 'user_unban' : 'user_ban' ?>">
                      <input type="hidden" name="user_id" value="<?= $uid ?>">
                      <input type="hidden" name="return_view" value="users">
                      <input type="hidden" name="users_q" value="<?= h($usersSearch) ?>">
                      <input type="hidden" name="users_page" value="<?= (int) $usersPage ?>">
                      <button class="btn <?= $disabled ? 'btn-primary' : 'btn-ghost' ?>" type="submit" style="padding:.25rem .7rem;font-size:.8rem;<?= !$disabled ? 'color:var(--danger)' : '' ?>"><?= $disabled ? 'Unban user' : 'Ban user' ?></button>
                    </form>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
            </div>
            <?php if ($usersPages > 1): ?>
              <nav class="composer-actions" aria-label="Users pages" style="margin-top:1rem;justify-content:center;gap:.5rem">
                <?php if ($usersPage > 1): ?><a class="btn btn-ghost" href="?view=users&amp;page=<?= $usersPage - 1 ?><?= $usersQuery ?>">Previous</a><?php endif; ?>
                <span class="meta">Page <?= $usersPage ?> of <?= $usersPages ?></span>
                <?php if ($usersPage < $usersPages): ?><a class="btn btn-ghost" href="?view=users&amp;page=<?= $usersPage + 1 ?><?= $usersQuery ?>">Next</a><?php endif; ?>
              </nav>
            <?php endif; ?>
          <?php endif; ?>
        <?php endif; ?>

      <?php elseif ($view === 'policies'): ?>
        <?php if (empty($vaakIsAdmin)): ?>
          <div class="empty">Admin only.</div>
        <?php else: ?>
          <?php
            require_once __DIR__ . '/ap-instance-docs.php';
            $privacyDoc = ap_instance_doc_get('privacy');
            $conductDoc = ap_instance_doc_get('conduct');
            $ruleRows = ap_instance_rules_list();
            $rulesText = implode("\n", array_map(static fn(array $r): string => (string) $r['text'], $ruleRows));
          ?>
          <div class="meta" style="margin-bottom:1rem">
            Public pages (VAAK-branded):
            <a href="/vaak/privacy/" target="_blank" rel="noopener">/vaak/privacy/</a>
            ·
            <a href="/vaak/conduct/" target="_blank" rel="noopener">/vaak/conduct/</a>.
            Rules also appear in Mastodon-compatible apps via the instance API. Site blog privacy remains at
            <a href="/pages/privacy/" target="_blank" rel="noopener">/pages/privacy/</a>.
          </div>

          <form class="composer" method="post" action="?view=policies" style="margin-bottom:1.25rem">
            <input type="hidden" name="action" value="policies_save_privacy">
            <div class="meta" style="margin-bottom:.45rem"><strong>Privacy Policy</strong></div>
            <input name="title" type="text" maxlength="120" value="<?= h((string) $privacyDoc['title']) ?>" placeholder="Title">
            <textarea name="body" rows="12" placeholder="Privacy policy body (plain text; blank lines = paragraphs)" style="width:100%;margin-top:.55rem;min-height:12rem"><?= h((string) $privacyDoc['body']) ?></textarea>
            <div class="composer-actions">
              <span class="meta"><?= $privacyDoc['updated_at'] ? 'Updated ' . h((string) $privacyDoc['updated_at']) : 'Not saved yet' ?></span>
              <button class="btn btn-primary" type="submit">Save privacy</button>
            </div>
          </form>

          <form class="composer" method="post" action="?view=policies" style="margin-bottom:1.25rem">
            <input type="hidden" name="action" value="policies_save_conduct">
            <div class="meta" style="margin-bottom:.45rem"><strong>Code of Conduct</strong> (intro text above the numbered rules)</div>
            <input name="title" type="text" maxlength="120" value="<?= h((string) $conductDoc['title']) ?>" placeholder="Title">
            <textarea name="body" rows="8" placeholder="Code of conduct intro" style="width:100%;margin-top:.55rem;min-height:8rem"><?= h((string) $conductDoc['body']) ?></textarea>
            <div class="composer-actions">
              <span class="meta"><?= $conductDoc['updated_at'] ? 'Updated ' . h((string) $conductDoc['updated_at']) : 'Not saved yet' ?></span>
              <button class="btn btn-primary" type="submit">Save code of conduct</button>
            </div>
          </form>

          <form class="composer" method="post" action="?view=policies">
            <input type="hidden" name="action" value="policies_save_rules">
            <div class="meta" style="margin-bottom:.45rem"><strong>Server rules</strong> — one rule per line (shown numbered on /vaak/conduct/ and in apps)</div>
            <textarea name="rules_text" rows="10" placeholder="One rule per line" style="width:100%;min-height:10rem;font-family:ui-monospace,SFMono-Regular,Menlo,Consolas,monospace;font-size:.85rem"><?= h($rulesText) ?></textarea>
            <div class="composer-actions">
              <span class="meta"><?= count($ruleRows) ?> rules</span>
              <button class="btn btn-primary" type="submit">Save rules</button>
            </div>
          </form>
        <?php endif; ?>

      <?php elseif ($view === 'invites'): ?>
        <?php if (empty($vaakIsAdmin)): ?>
          <div class="empty">Admin only.</div>
        <?php else: ?>
          <?php $inviteRows = ap_auth_invites_list(100); ?>
          <div class="meta" style="margin-bottom:1rem">
            Generate invite codes for new VAAK accounts. Registration: <a href="/vaak/?mode=register">/vaak/?mode=register</a>.
            Public HTML profiles + federation keys for invitees ship in a later slice.
          </div>
          <form class="composer" method="post" action="?view=invites" style="margin-bottom:1.25rem">
            <input type="hidden" name="action" value="invite_create">
            <input name="note" type="text" maxlength="200" placeholder="Optional note (who it’s for)">
            <div class="composer-actions">
              <span class="meta"><?= count($inviteRows) ?> codes</span>
              <button class="btn btn-primary" type="submit">Generate invite</button>
            </div>
          </form>
          <?php if (!$inviteRows): ?>
            <div class="empty">No invites yet.</div>
          <?php else: ?>
            <?php foreach ($inviteRows as $inv): ?>
              <article class="tweet">
                <div class="tweet-hd">
                  <div>
                    <span class="who mono"><?= h((string) ($inv['code'] ?? '')) ?></span>
                    <span class="meta"> · <?= h(relative_time((string) ($inv['created_at'] ?? ''))) ?></span>
                    <?php if (!empty($inv['used_at'])): ?>
                      <span class="meta"> · used <?= h(relative_time((string) $inv['used_at'])) ?></span>
                    <?php else: ?>
                      <span class="meta" style="color:var(--primary)"> · unused</span>
                    <?php endif; ?>
                  </div>
                </div>
                <?php if (!empty($inv['note'])): ?>
                  <div class="body meta"><?= h((string) $inv['note']) ?></div>
                <?php endif; ?>
                <?php if (empty($inv['used_at'])): ?>
                  <div class="tweet-actions">
                    <a class="btn btn-ghost" href="/vaak/?mode=register&amp;invite=<?= urlencode((string) ($inv['code'] ?? '')) ?>" style="padding:.25rem .7rem;font-size:.8rem">Registration link</a>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>

      <?php elseif ($view === 'relays'): ?>
        <?php $relayRows = function_exists('ap_relays_list') ? ap_relays_list() : []; ?>
        <form class="composer" method="post" action="?view=relays" style="margin-bottom:1.25rem">
          <input type="hidden" name="action" value="relay_add">
          <input type="url" name="inbox_url" required placeholder="https://relay.example/inbox" autocomplete="off">
          <div class="composer-actions">
            <span class="meta"></span>
            <button class="btn btn-primary" type="submit">Add relay</button>
          </div>
        </form>
        <?php if (!$relayRows): ?>
          <div class="empty">No relays yet.</div>
        <?php else: ?>
          <?php foreach ($relayRows as $rr): ?>
            <?php
              $rid = (int) ($rr['id'] ?? 0);
              $rst = (string) ($rr['state'] ?? 'idle');
              $rin = (string) ($rr['inbox_url'] ?? '');
              $badge = match ($rst) {
                  'accepted' => 'accepted',
                  'pending' => 'pending',
                  'rejected' => 'rejected',
                  default => 'idle',
              };
            ?>
            <article class="tweet">
              <div class="who">
                <?= h($rin) ?>
                <span class="tag" style="margin-left:.35rem"><?= h($badge) ?></span>
              </div>
              <div class="body meta" style="margin-top:.35rem">
                <?php if (!empty($rr['follow_activity_id'])): ?>
                  follow id <code style="font-size:.75rem;word-break:break-all"><?= h((string) $rr['follow_activity_id']) ?></code><br>
                <?php endif; ?>
                updated <?= h((string) ($rr['updated_at'] ?? '')) ?>
              </div>
              <div class="tweet-actions">
                <?php if ($rst !== 'accepted' && $rst !== 'pending'): ?>
                  <form method="post" action="?view=relays" style="display:inline">
                    <input type="hidden" name="action" value="relay_enable">
                    <input type="hidden" name="relay_id" value="<?= $rid ?>">
                    <button class="btn btn-primary" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Enable</button>
                  </form>
                <?php elseif ($rst === 'pending'): ?>
                  <form method="post" action="?view=relays" style="display:inline">
                    <input type="hidden" name="action" value="relay_enable">
                    <input type="hidden" name="relay_id" value="<?= $rid ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Retry Enable</button>
                  </form>
                  <form method="post" action="?view=relays" style="display:inline">
                    <input type="hidden" name="action" value="relay_disable">
                    <input type="hidden" name="relay_id" value="<?= $rid ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Cancel</button>
                  </form>
                <?php else: ?>
                  <form method="post" action="?view=relays" style="display:inline">
                    <input type="hidden" name="action" value="relay_disable">
                    <input type="hidden" name="relay_id" value="<?= $rid ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Disable</button>
                  </form>
                <?php endif; ?>
                <form method="post" action="?view=relays" style="display:inline" onsubmit="return confirm('Remove this relay?');">
                  <input type="hidden" name="action" value="relay_remove">
                  <input type="hidden" name="relay_id" value="<?= $rid ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Remove</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($view === 'security'): ?>
        <?php
          $oauthTokens = ap_oauth_tokens_list(40, 7, $vaakOwnerId);
          $nowTs = time();
          $oauthRevokedShown = 0;
          foreach ($oauthTokens as $_t) {
              if (!empty($_t['revoked_at'])) {
                  $oauthRevokedShown++;
              }
          }
          $isCmdrSecurity = ($vaakActorKey === 'cmdr_nova');
        ?>
        <div class="tweet" style="margin-bottom:1rem">
          <div class="who">Your account · <?= h($vaakHandle) ?></div>
          <div class="body meta" style="margin-top:.5rem">
            Manage your VAAK login password and OAuth apps (Ice Cubes, Tusky, etc.) for
            <b>this account only</b>. Tokens below are yours — not other users’.
            Access tokens expire after <b>90 days</b> (refresh <b>180 days</b>).
          </div>
          <div class="tags" style="margin-top:.75rem">
            <span class="tag">PKCE S256</span>
            <span class="tag">access 90d · refresh 180d</span>
          </div>
        </div>

        <form class="composer" method="post" action="?view=security" style="margin-bottom:1.25rem" autocomplete="off">
          <input type="hidden" name="action" value="change_password">
          <input type="hidden" name="return_view" value="security">
          <div class="meta" style="margin-bottom:.45rem"><strong>Change account password</strong></div>
          <input type="password" name="password" required minlength="10" autocomplete="new-password" placeholder="New password (min 10 chars)">
          <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password" placeholder="Confirm password">
          <div class="composer-actions">
            <span class="meta">VAAK login for <?= h($vaakUsername) ?><?= $isCmdrSecurity ? ' · also syncs Ice Cubes app password' : '' ?></span>
            <button class="btn btn-primary" type="submit">Update password</button>
          </div>
        </form>

        <div class="tweet" style="margin-bottom:1.25rem" id="vaak-push-card">
          <div class="who">Browser notifications</div>
          <div class="body meta" style="margin-top:.5rem">
            Get OS alerts for mentions, favs, boosts, follows, and DMs while VAAK is in the background.
            Ice Cubes push (if you use the app) is separate.
          </div>
          <div class="body meta" style="margin-top:.45rem" id="vaak-push-safari-hint" hidden>
            <strong>iPhone / iPad Safari:</strong> Web Push only works after
            <b>Share → Add to Home Screen</b>, then open VAAK from that icon (not a Safari tab), then tap Enable here.
            Desktop Safari / Chrome / Firefox can Enable directly.
          </div>
          <div class="meta" id="vaak-push-status" style="margin-top:.55rem">Checking…</div>
          <div class="tweet-actions" style="margin-top:.75rem;display:flex;gap:.5rem;flex-wrap:wrap">
            <button type="button" class="btn btn-primary" id="vaak-push-enable" style="padding:.35rem .85rem;font-size:.85rem">Enable</button>
            <button type="button" class="btn btn-ghost" id="vaak-push-disable" style="padding:.35rem .85rem;font-size:.85rem" hidden>Disable</button>
          </div>
        </div>

        <?php if ($isCmdrSecurity && !empty($vaakIsAdmin)): ?>
        <div class="tweet" style="margin-bottom:1rem">
          <div class="who">Operator app password</div>
          <div class="body meta" style="margin-top:.5rem">
            Legacy Ice Cubes / Mastodon <b>app password</b> for <code>@cmdr_nova</code>
            (also synced when you change your VAAK password above). Status:
            <b><?= ap_app_password_is_set() ? 'set' : 'not set' ?></b>.
          </div>
        </div>
        <form class="composer" method="post" action="?view=security" style="margin-bottom:1.25rem">
          <input type="hidden" name="action" value="set_app_password">
          <input type="password" name="password" required minlength="10" autocomplete="new-password" placeholder="New app password (min 10 chars)">
          <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password" placeholder="Confirm app password">
          <div class="composer-actions">
            <span class="meta">Stored as bcrypt · /oauth/authorize</span>
            <button class="btn btn-primary" type="submit">Save app password</button>
          </div>
        </form>
        <?php endif; ?>

        <div class="tweet" style="margin-top:.5rem">
          <div class="who">Your OAuth tokens</div>
          <div class="body meta" style="margin-top:.5rem">
            Bearer tokens are stored hashed. Revoke if a phone is lost or an app looks suspicious.
            Revoked rows auto-delete after <b>7 days</b>.
          </div>
          <?php if ($isCmdrSecurity && !empty($vaakIsAdmin)): ?>
          <div class="tweet-actions" style="margin-top:.75rem">
            <form method="post" action="?view=security" style="display:inline" onsubmit="return confirm('Delete all revoked tokens from the database now? Active sessions stay.');">
              <input type="hidden" name="action" value="purge_oauth_tokens">
              <input type="hidden" name="purge_mode" value="revoked">
              <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">
                Purge revoked now<?= $oauthRevokedShown > 0 ? ' (' . $oauthRevokedShown . ')' : '' ?>
              </button>
            </form>
          </div>
          <?php endif; ?>
        </div>
        <?php if (!$oauthTokens): ?>
          <div class="empty">No tokens yet. Authorize an app once to create one.</div>
        <?php else: ?>
          <?php foreach ($oauthTokens as $tok): ?>
            <?php
              $revoked = !empty($tok['revoked_at']);
              $expTs = !empty($tok['expires_at']) ? strtotime((string) $tok['expires_at']) : false;
              $expired = $expTs !== false && $expTs < $nowTs;
              $status = $revoked ? 'revoked' : ($expired ? 'expired' : 'active');
              $name = (string) ($tok['client_name'] ?? 'unknown app');
            ?>
            <article class="tweet">
              <div class="who"><?= h($name) ?> <span class="meta">#<?= (int) $tok['id'] ?></span></div>
              <div class="body meta" style="margin-top:.35rem">
                scopes <code><?= h((string) ($tok['scopes'] ?? '')) ?></code><br>
                created <?= h((string) ($tok['created_at'] ?? '')) ?>
                · expires <?= h((string) ($tok['expires_at'] ?? 'n/a')) ?>
                <?php if ($revoked): ?>
                  <br>revoked <?= h((string) $tok['revoked_at']) ?>
                <?php endif; ?>
              </div>
              <div class="tags" style="margin-top:.5rem">
                <span class="tag"><?= h($status) ?></span>
                <?php if ($status === 'active'): ?>
                  <form method="post" action="?view=security" style="display:inline" onsubmit="return confirm('Revoke this token? The app will need to re-authorize.');">
                    <input type="hidden" name="action" value="revoke_oauth_token">
                    <input type="hidden" name="token_id" value="<?= (int) $tok['id'] ?>">
                    <button class="btn" type="submit">Revoke</button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($view === 'profile'): ?>
        <?php
          $atts = is_array($profile['attachment'] ?? null) ? $profile['attachment'] : [];
          while (count($atts) < 8) {
              $atts[] = ['name' => '', 'value' => ''];
          }
          // Bio editor: keep HTML when links/markup matter; otherwise plain text
          $summaryEdit = (string) ($profile['summary'] ?? '');
          if (preg_match('/<(a|code|strong|em|b|i|ul|ol|li)\b/i', $summaryEdit)) {
              $summaryForForm = $summaryEdit;
          } else {
              $summaryForForm = trim(html_entity_decode(strip_tags(str_replace(
                  ['<br>', '<br/>', '<br />', '</p><p>', '</p>', '<p>'],
                  ["\n", "\n", "\n", "\n\n", '', ''],
                  $summaryEdit
              )), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
              // Collapse runaway blank lines so the textarea doesn't re-save huge gaps
              $summaryForForm = preg_replace("/\n{3,}/u", "\n\n", $summaryForForm) ?? $summaryForForm;
          }
        ?>
        <form class="composer profile-form" method="post" action="?view=profile" enctype="multipart/form-data">
          <input type="hidden" name="action" value="save_profile">
          <div class="profile-preview">
            <?php
              $previewAv = function_exists('ap_local_avatar_url')
                  ? ap_local_avatar_url($profile['icon_url'] ?? null)
                  : ((string) ($profile['icon_url'] ?? '') ?: 'https://mkultra.monster/img/avatar/local-default.webp');
            ?>
              <img class="av" src="<?= h($previewAv) ?>" alt="" loading="lazy" referrerpolicy="no-referrer">
            <div>
              <div class="who"><?= h($profile['name']) ?><?php if (!empty($profile['vanity_verified'])): ?> <span class="vanity-verified" title="Vanity verified (just for fun)" aria-label="Verified">✓</span><?php endif; ?></div>
              <div class="meta"><?= h($vaakHandle) ?></div>
              <?php if (!empty($profile['updated_at'])): ?>
                <div class="meta">Updated <?= h(relative_time((string) $profile['updated_at'])) ?> ago</div>
              <?php endif; ?>
            </div>
          </div>
          <?php if (!empty($profile['image_url'])): ?>
            <div class="profile-header-preview" style="margin:.75rem 0 1rem;border-radius:12px;overflow:hidden;border:1px solid var(--border);height:180px;max-height:180px">
              <img src="<?= h($profile['image_url']) ?>" alt="" loading="lazy" referrerpolicy="no-referrer" style="display:block;width:100%;height:100%;object-fit:cover">
            </div>
          <?php endif; ?>

          <?php if (!empty($followRequests)): ?>
            <div class="composer" style="margin:0 0 1rem">
              <h3 style="margin-top:0">Pending follower requests</h3>
              <div class="meta" style="margin-bottom:.65rem">Approve requests to add the account to your followers, or reject them without accepting the follow.</div>
              <?php foreach ($followRequests as $request):
                  $requestActor = (string) ($request['actor_id'] ?? '');
                  $requestName = trim((string) ($request['username'] ?? ''));
                  $requestLabel = $requestName !== '' ? '@' . $requestName : $requestActor;
              ?>
                <div class="row" style="justify-content:space-between;gap:.75rem;align-items:center;margin:.55rem 0">
                  <a href="?view=remote_profile&amp;actor=<?= h(rawurlencode($requestActor)) ?>"><?= h($requestLabel) ?></a>
                  <span style="display:flex;gap:.45rem;flex-wrap:wrap">
                    <form method="post" action="?view=profile" style="display:inline">
                      <input type="hidden" name="action" value="follow_request_decide"><input type="hidden" name="id" value="<?= (int) ($request['id'] ?? 0) ?>"><input type="hidden" name="decision" value="approve">
                      <button class="btn btn-primary" type="submit">Approve</button>
                    </form>
                    <form method="post" action="?view=profile" style="display:inline">
                      <input type="hidden" name="action" value="follow_request_decide"><input type="hidden" name="id" value="<?= (int) ($request['id'] ?? 0) ?>"><input type="hidden" name="decision" value="reject">
                      <button class="btn" type="submit">Reject</button>
                    </form>
                  </span>
                </div>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>

          <label for="pf-name">Display name</label>
          <input id="pf-name" type="text" name="name" maxlength="100" required value="<?= h($profile['name']) ?>">

          <label for="pf-email">Email</label>
          <input id="pf-email" type="email" name="email" maxlength="200" autocomplete="email"
                 placeholder="you@example.com"
                 value="<?= h((string) ($vaakUser['email'] ?? '')) ?>">
          <div class="meta" style="margin:.25rem 0 .75rem">
            Used for VAAK login (username <em>or</em> email). Not shown on your public profile.
            Leave blank to clear.
          </div>

          <label for="vaak-accent-select">VAAK accent color</label>
          <select id="vaak-accent-select" style="max-width:18rem">
            <option value="green">Green</option>
            <option value="yellow">Yellow</option>
            <option value="blue">Blue</option>
            <option value="red">Red</option>
            <option value="purple">Purple</option>
            <option value="orange">Orange</option>
            <option value="pink">Pink</option>
          </select>
          <script>
            (function () {
              const select = document.getElementById('vaak-accent-select');
              if (!select) return;
              const key = 'vaak-accent-<?= h((string) $vaakActorKey) ?>';
              const allowed = ['green', 'yellow', 'blue', 'red', 'purple', 'orange', 'pink'];
              const current = localStorage.getItem(key);
              select.value = allowed.includes(current) ? current : 'green';
              select.addEventListener('change', function () {
                const value = allowed.includes(select.value) ? select.value : 'green';
                document.documentElement.dataset.accent = value;
                localStorage.setItem(key, value);
              });
            }());
          </script>
          <div class="meta" style="margin:.25rem 0 .75rem">Changes the accent used throughout Vaak. This is private to your account and browser.</div>

          <label for="pf-summary">Bio (plain text or simple HTML: p, br, a, code, strong, em)</label>
          <textarea id="pf-summary" name="summary" maxlength="4000" required><?= h($summaryForForm) ?></textarea>

          <label for="pf-icon-file">Upload avatar (JPEG / PNG / WebP / GIF · max 2&nbsp;MB)</label>
          <input id="pf-icon-file" type="file" name="icon_file" accept="image/jpeg,image/png,image/webp,image/gif">
          <label for="pf-icon">Or avatar image URL (https)</label>
          <input id="pf-icon" type="url" name="icon_url" placeholder="https://…" value="<?= h((string) ($profile['icon_url'] ?? '')) ?>">
          <div class="meta" style="margin:.25rem 0 .75rem">Upload stores on <code>/img/profile/</code> (survives site deploy) and overrides the URL for this save. Federates via ActivityPub <code>Update</code>.</div>

          <label for="pf-image-file">Upload header / banner (JPEG / PNG / WebP / GIF · max 5&nbsp;MB)</label>
          <input id="pf-image-file" type="file" name="image_file" accept="image/jpeg,image/png,image/webp,image/gif">
          <label for="pf-image">Or header / banner image URL (https)</label>
          <input id="pf-image" type="url" name="image_url" placeholder="https://…" value="<?= h((string) ($profile['image_url'] ?? '')) ?>">
          <div class="meta" style="margin:.25rem 0 .75rem">Same storage rules as avatar. Remotes may cache until they process the profile Update (Bridgy: use ↻ Update profile below).</div>

          <label>Verified creator links / profile fields (up to 8)</label>
          <div class="meta" style="margin:0 0 .55rem">
            Add a website or post URL you control. For a verified creator check,
            the linked page must include
            <code>&lt;a rel="me" href="<?= h($vaakActorId) ?>"&gt;</code>
            (or a <code>&lt;link rel="me"&gt;</code>). Ice Cubes / Mastodon show a green check when <code>verified_at</code> is set.
          </div>
          <?php for ($i = 0; $i < 8; $i++):
              $an = (string) ($atts[$i]['name'] ?? '');
              $av = (string) ($atts[$i]['value'] ?? '');
              $avPlain = trim(html_entity_decode(strip_tags($av), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
              if ($avPlain === '' && preg_match('#href=[\'"](https://[^\'"]+)#', $av, $hm)) {
                  $avPlain = $hm[1];
              }
              $isVerified = !empty($atts[$i]['verified_at']);
              $verifiedAt = $isVerified ? (string) $atts[$i]['verified_at'] : '';
          ?>
            <div class="field-row" style="align-items:center">
              <input type="text" name="field_name[]" maxlength="64" placeholder="Label" value="<?= h($an) ?>">
              <input type="text" name="field_value[]" maxlength="500" placeholder="Value or https://…" value="<?= h($avPlain) ?>">
              <?php if ($an !== '' || $avPlain !== ''): ?>
                <?php if ($isVerified): ?>
                  <span class="tag field-status" style="background:var(--primary-dim);color:var(--primary)" title="<?= h($verifiedAt) ?>">✓ verified</span>
                <?php else: ?>
                  <span class="meta field-status" title="No rel=me backlink found yet">unverified</span>
                <?php endif; ?>
              <?php else: ?>
                <span class="field-status" aria-hidden="true"></span>
              <?php endif; ?>
            </div>
          <?php endfor; ?>

          <div class="profile-policy-block">
            <label class="profile-policy-row">Who can reply
              <select name="reply_policy">
                <option value="anyone" <?= ($profile['reply_policy'] ?? 'anyone') === 'anyone' ? 'selected' : '' ?>>Anyone</option>
                <option value="followers" <?= ($profile['reply_policy'] ?? '') === 'followers' ? 'selected' : '' ?>>Followers</option>
                <option value="nobody" <?= ($profile['reply_policy'] ?? '') === 'nobody' ? 'selected' : '' ?>>Nobody</option>
              </select>
            </label>
            <label class="profile-policy-row">Who can quote
              <select name="quote_policy">
                <option value="anyone" <?= ($profile['quote_policy'] ?? 'anyone') === 'anyone' ? 'selected' : '' ?>>Anyone</option>
                <option value="followers" <?= ($profile['quote_policy'] ?? '') === 'followers' ? 'selected' : '' ?>>Followers</option>
                <option value="nobody" <?= ($profile['quote_policy'] ?? '') === 'nobody' ? 'selected' : '' ?>>Nobody</option>
              </select>
            </label>
            <div class="meta profile-policy-note">These defaults are published with your posts and are enforced for incoming quote requests. You can still choose a post's visibility when composing.</div>
          </div>

          <div class="checks">
            <label><input type="checkbox" name="discoverable" value="1" <?= !empty($profile['discoverable']) ? 'checked' : '' ?>> Show in profile directories / discovery</label>
            <label><input type="checkbox" name="indexable" value="1" <?= !empty($profile['indexable']) ? 'checked' : '' ?>> Allow fediverse search indexing</label>
            <label><input type="checkbox" name="manually_approves" value="1" <?= !empty($profile['manually_approves']) ? 'checked' : '' ?>> Private account (manually approve followers)</label>
            <label><input type="checkbox" name="auto_follow_back" value="1" <?= !empty($profile['auto_follow_back']) ? 'checked' : '' ?>> Automatically follow back new followers</label>
            <label><input type="checkbox" name="anti_ai_marker" value="1" <?= !empty($profile['anti_ai_marker']) ? 'checked' : '' ?>> Highlight anti-AI posters in my timelines</label>
            <label><input type="checkbox" name="auto_unblur_sensitive" value="1" <?= !empty($profile['auto_unblur_sensitive']) ? 'checked' : '' ?>> Automatically show sensitive media</label>
            <label><input type="checkbox" name="auto_delete_posts_7d" value="1" <?= !empty($profile['auto_delete_posts_7d']) ? 'checked' : '' ?>> Automatically delete my posts older than 7 days</label>
            <label><input type="checkbox" name="collection_consent" value="1" <?= !empty($profile['collection_consent']) ? 'checked' : '' ?>> Allow featuring in Collections</label>
            <label><input type="checkbox" name="vanity_verified" value="1" <?= !empty($profile['vanity_verified']) ? 'checked' : '' ?>> Vanity verified checkmark <span class="vanity-verified" aria-hidden="true">✓</span></label>
          </div>
          <div class="meta" style="margin:.35rem 0 .75rem">
            <b style="color:var(--primary)">Follow back</b> —
            when on, accepting a Follow also sends a Follow to them (same as the old single-user habit).
            Off by default for every account — turn on only if you want that behavior.
          </div>
          <div class="meta" style="margin:.35rem 0 .75rem">
            <b style="color:var(--primary)">Private account</b> —
            new followers remain pending until you approve them. Your ActivityPub actor is advertised as locked/private.
          </div>
          <div class="meta" style="margin:.35rem 0 .75rem">
            <b style="color:var(--primary)">Anti-AI highlight</b> —
            viewer-only: when on, timeline cards show a small <code>anti-ai</code> tag for remote posters
            who’ve used slang like “slop” / “clanker” in <b>3+ cached posts</b> (fedi Creates + Bluesky cache; job rescans periodically;
            mark clears if that usage drops off for ~90 days). A single matching post can also tag early.
            Nothing is appended to federated outbound posts.
          </div>
          <div class="meta" style="margin:.35rem 0 .75rem">
            <b style="color:var(--primary)">Sensitive media</b> —
            when enabled, media marked sensitive is shown without the blur gate in your timelines and gallery. Content warnings with custom text still remain collapsible.
          </div>
          <div class="meta" style="margin:.35rem 0 .75rem">
            <b style="color:var(--primary)">Post retention</b> —
            when enabled, VAAK permanently deletes this account’s local posts after 7 days and sends ActivityPub <code>Delete</code> activities. Off by default; remote posts and other users are never affected.
          </div>
          <div class="meta" style="margin:.35rem 0 .75rem">
            <b style="color:var(--primary)">Vanity verified</b> —
            a silly blue ✓ next to your name on the HTML profile and in Ice Cubes (appended to display name).
            Not real platform verification — just for the bit. Toggle anytime.
          </div>
          <div class="meta" style="margin:.35rem 0 .75rem">
            <b style="color:var(--primary)">Discovery</b> —
            <em>directories</em> controls whether remotes may list you in profile directories / suggestions (<code>discoverable</code>).
            <em>Search indexing</em> sets ActivityPub/Mastodon <code>indexable</code> (public posts eligible for fediverse full-text / search indexes).
            Turn either off if you want to stay harder to find.
          </div>
          <div class="meta" style="margin:.35rem 0 .75rem">
            <b style="color:var(--primary)">Collections consent</b> —
            when on, others may include <code><?= h($vaakHandle) ?></code> in starter-pack style Collections (FEP-7aa9 <code>canFeature</code>).
            When off, your actor advertises an explicit opt-out and new “you’re featured in” imports are rejected.
          </div>

          <div class="composer-actions">
            <span class="meta">Saves to the shared database and sends an ActivityPub <code>Update</code> (no <code>movedTo</code>). Handle stays <b><?= h($vaakUsername) ?></b>.</span>
            <a class="btn btn-ghost" href="/users/<?= h(rawurlencode($vaakActorKey)) ?>">View public profile</a>
            <button class="btn btn-primary" type="submit">Save profile</button>
          </div>
        </form>

        <?php
          $slLink = function_exists('ap_sl_link_for_user')
              ? ap_sl_link_for_user((int) ($vaakUser['id'] ?? 0))
              : null;
          $slPending = null;
          if ($slLink === null && function_exists('ap_sl_schema_ensure')) {
              try {
                  ap_sl_schema_ensure();
                  $pst = ap_db()->prepare(
                      'SELECT sl_username, sl_agent_id, expires_at FROM ap_sl_challenges
                       WHERE owner_user_id = ? AND expires_at >= ? ORDER BY id DESC LIMIT 1'
                  );
                  $pst->execute([(int) ($vaakUser['id'] ?? 0), gmdate('c')]);
                  $prow = $pst->fetch();
                  $slPending = is_array($prow) ? $prow : null;
              } catch (Throwable $e) {
                  $slPending = null;
              }
          }
        ?>
        <div class="composer" style="margin-top:1.25rem">
          <div class="meta" style="margin-bottom:.75rem">
            <b style="color:var(--primary)">Second Life avatar</b><br>
            Prove you own an SL avatar the Primfeed way: we IM you a one-time code in-world.
            Use your <b>username</b> (account name like <code>first.last</code>), not your display name.
          </div>
          <?php if (is_array($slLink)): ?>
            <div style="display:flex;gap:.75rem;align-items:center;margin-bottom:.75rem;flex-wrap:wrap">
              <?php if (!empty($slLink['sl_profile_image_url'])): ?>
                <img src="<?= h((string) $slLink['sl_profile_image_url']) ?>" alt="" width="48" height="48"
                     style="border-radius:10px;object-fit:cover;border:1px solid var(--border)" loading="lazy" referrerpolicy="no-referrer">
              <?php endif; ?>
              <div>
                <div class="who"><?= h((string) $slLink['sl_username']) ?></div>
                <div class="meta">
                  Linked <?= h(relative_time((string) ($slLink['verified_at'] ?? ''))) ?>
                  · <a href="<?= h(ap_sl_profile_url((string) $slLink['sl_agent_id'])) ?>" target="_blank" rel="noopener noreferrer">SL profile</a>
                  · <span class="mono" style="font-size:.72rem"><?= h((string) $slLink['sl_agent_id']) ?></span>
                </div>
              </div>
            </div>
            <form method="post" action="?view=profile" onsubmit="return confirm('Unlink this Second Life avatar from your VAAK account?');">
              <input type="hidden" name="action" value="sl_link_unlink">
              <div class="composer-actions">
                <span class="meta">One avatar per VAAK account</span>
                <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Unlink</button>
              </div>
            </form>
          <?php else: ?>
            <?php if (is_array($slPending)): ?>
              <div class="meta" style="margin-bottom:.75rem;color:var(--primary)">
                Code sent for <code><?= h((string) $slPending['sl_username']) ?></code>
                — check Second Life IMs, then enter it below
                (expires <?= h(relative_time((string) ($slPending['expires_at'] ?? ''))) ?>).
              </div>
              <form method="post" action="?view=profile" style="margin-bottom:1rem">
                <input type="hidden" name="action" value="sl_link_verify">
                <label for="sl-code">Verification code</label>
                <input id="sl-code" type="text" name="sl_code" required maxlength="16" autocomplete="one-time-code"
                       placeholder="e.g. A7K3MQ" style="text-transform:uppercase;letter-spacing:.08em">
                <div class="composer-actions">
                  <span class="meta">From the VAAK verifier object IM</span>
                  <button class="btn btn-primary" type="submit">Confirm link</button>
                </div>
              </form>
            <?php endif; ?>
            <form method="post" action="?view=profile">
              <input type="hidden" name="action" value="sl_link_start">
              <label for="sl-username">Second Life username</label>
              <input id="sl-username" type="text" name="sl_username" required maxlength="64"
                     placeholder="first.last or First Last"
                     value="<?= h((string) ($slPending['sl_username'] ?? '')) ?>">
              <details style="margin-top:.5rem">
                <summary class="meta" style="cursor:pointer">Advanced: paste agent UUID</summary>
                <label for="sl-agent" class="meta">Agent UUID (skips Name2Key if set)</label>
                <input id="sl-agent" type="text" name="sl_agent_id" maxlength="36"
                       placeholder="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx"
                       value="<?= h((string) ($slPending['sl_agent_id'] ?? '')) ?>">
              </details>
              <div class="composer-actions">
                <span class="meta">Requires object IMs enabled in SL preferences</span>
                <button class="btn btn-primary" type="submit"><?= is_array($slPending) ? 'Resend code' : 'Send code in-world' ?></button>
              </div>
            </form>
          <?php endif; ?>
        </div>

        <?php
          $featuredCards = function_exists('ap_featured_cards')
              ? ap_featured_cards((int) ($vaakUser['id'] ?? 0))
              : [];
          $featuredMax = defined('AP_FEATURED_ACCOUNTS_MAX') ? (int) AP_FEATURED_ACCOUNTS_MAX : 12;
        ?>
        <div class="composer" style="margin-top:1.25rem">
          <div class="meta" style="margin-bottom:.75rem">
            <b style="color:var(--primary)">Featured accounts</b><br>
            Every VAAK account can curate this list — it shows on <b>your</b> public profile’s
            <b>Featured</b> tab (Mastodon-style endorsements).
            Max <?= (int) $featuredMax ?> · we fetch their avatar when you add them.
            · <a href="/users/<?= h(rawurlencode($vaakActorKey)) ?>?tab=featured" target="_blank" rel="noopener">Preview</a>
          </div>
          <?php if ($featuredCards): ?>
            <div style="display:flex;flex-direction:column;gap:.65rem;margin-bottom:1rem">
              <?php foreach ($featuredCards as $i => $fc): ?>
                <div style="display:flex;align-items:center;gap:.65rem;flex-wrap:wrap;padding:.45rem .55rem;border:1px solid var(--border);border-radius:12px;background:#0e0e0e">
                  <img src="<?= h((string) $fc['avatar']) ?>" alt="" width="40" height="40"
                       style="border-radius:50%;object-fit:cover;border:1px solid var(--border);flex:0 0 auto"
                       loading="lazy" referrerpolicy="no-referrer"
                       onerror="this.src='<?= h(defined('AP_REMOTE_AVATAR_FALLBACK') ? AP_REMOTE_AVATAR_FALLBACK : '/img/avatar/default.webp') ?>'">
                  <div style="flex:1 1 10rem;min-width:0">
                    <div class="who" style="overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= h((string) $fc['display_name']) ?></div>
                    <div class="meta">@<?= h((string) $fc['acct']) ?></div>
                  </div>
                  <div style="display:flex;gap:.35rem;flex-wrap:wrap">
                    <?php if ($i > 0): ?>
                      <form method="post" action="?view=profile" style="display:inline">
                        <input type="hidden" name="action" value="featured_move">
                        <input type="hidden" name="featured_id" value="<?= (int) $fc['id'] ?>">
                        <input type="hidden" name="dir" value="up">
                        <button class="btn btn-ghost" type="submit" style="padding:.25rem .65rem;font-size:.8rem" title="Move up">↑</button>
                      </form>
                    <?php endif; ?>
                    <?php if ($i < count($featuredCards) - 1): ?>
                      <form method="post" action="?view=profile" style="display:inline">
                        <input type="hidden" name="action" value="featured_move">
                        <input type="hidden" name="featured_id" value="<?= (int) $fc['id'] ?>">
                        <input type="hidden" name="dir" value="down">
                        <button class="btn btn-ghost" type="submit" style="padding:.25rem .65rem;font-size:.8rem" title="Move down">↓</button>
                      </form>
                    <?php endif; ?>
                    <form method="post" action="?view=profile" style="display:inline" onsubmit="return confirm('Remove from Featured?');">
                      <input type="hidden" name="action" value="featured_remove">
                      <input type="hidden" name="featured_id" value="<?= (int) $fc['id'] ?>">
                      <button class="btn btn-ghost" type="submit" style="padding:.25rem .65rem;font-size:.8rem;color:var(--danger)">Remove</button>
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          <?php else: ?>
            <div class="meta" style="margin-bottom:.75rem">No featured accounts yet.</div>
          <?php endif; ?>
          <?php if (count($featuredCards) < $featuredMax): ?>
            <form method="post" action="?view=profile">
              <input type="hidden" name="action" value="featured_add">
              <label for="featured-ref">Add account</label>
              <input id="featured-ref" type="text" name="featured_ref" required maxlength="400"
                     placeholder="@user@instance or https://…/users/…">
              <div class="composer-actions">
                <span class="meta"><?= count($featuredCards) ?> / <?= (int) $featuredMax ?></span>
                <button class="btn btn-primary" type="submit">Feature</button>
              </div>
            </form>
          <?php else: ?>
            <div class="meta">Limit reached (<?= (int) $featuredMax ?>). Remove one to add another.</div>
          <?php endif; ?>
        </div>

        <form class="composer" method="post" action="?view=profile" style="margin-top:1.25rem" autocomplete="off">
          <input type="hidden" name="action" value="change_password">
          <input type="hidden" name="return_view" value="profile">
          <div class="meta" style="margin-bottom:.55rem">
            <b style="color:var(--primary)">Change account password</b><br>
            Updates your VAAK login password for <code><?= h($vaakUsername) ?></code>
            (also available under <a href="?view=security">You → Security</a>).
          </div>
          <input type="password" name="password" required minlength="10" autocomplete="new-password" placeholder="New password (min 10 chars)">
          <input type="password" name="password_confirm" required minlength="10" autocomplete="new-password" placeholder="Confirm password">
          <div class="composer-actions">
            <span class="meta">Min 10 characters · bcrypt hashed</span>
            <button class="btn btn-primary" type="submit">Update password</button>
          </div>
        </form>

        <form class="composer" method="post" action="?view=profile" style="margin-top:1rem">
          <input type="hidden" name="action" value="reverify_profile_fields">
          <div class="meta" style="margin-bottom:.75rem">
            <b style="color:var(--primary)">Re-check verified links</b><br>
            Fetches each https profile field and looks for a <code>rel=me</code> link back to
            <code><?= h($vaakActorId) ?></code>. Other sites need the same backlink.
          </div>
          <div class="composer-actions">
            <span class="meta">Updates <code>verified_at</code> for Ice Cubes / Mastodon clients.</span>
            <button class="btn btn-ghost" type="submit">Re-check verification</button>
          </div>
        </form>

        <form class="composer" method="post" action="?view=profile" style="margin-top:1rem">
          <input type="hidden" name="action" value="repush_profile">
          <div class="meta" style="margin-bottom:.75rem">
            <b style="color:var(--primary)">Re-push profile Update</b><br>
            Fans out a fresh actor document for <code>@<?= h($vaakActorKey) ?></code> to your followers<?= $vaakActorKey === 'cmdr_nova' ? ' and known shared inboxes' : '' ?>.
            <?php
              $activeMove = function_exists('ap_move_active_target') ? ap_move_active_target(vaak_actor_key()) : null;
            ?>
            <?php if ($activeMove): ?>
              <span style="color:var(--danger)">Active Move: publishing <code>movedTo</code> → <?= h($activeMove) ?>. Manage under <a href="?view=import_export">Import / Export</a>.</span>
            <?php else: ?>
              No active <code>movedTo</code>. Use this to clear stale “moved” banners on remotes that cached an old tombstone.
              Account Move / aliases live under <a href="?view=import_export">Import / Export</a>.
            <?php endif; ?>
          </div>
          <div class="composer-actions">
            <span class="meta">ActivityPub <code>Update(Person)</code></span>
            <button class="btn btn-ghost" type="submit">Re-push profile Update</button>
          </div>
        </form>
        <?php if (function_exists('ap_bsky_tab_enabled') && ap_bsky_tab_enabled()): ?>
        <?php
          $bskyRow = function_exists('ap_bsky_session_row') ? ap_bsky_session_row($vaakOwnerId) : null;
          $bskyHandle = is_array($bskyRow) ? (string) ($bskyRow['handle'] ?? '') : '';
        ?>
        <div class="tweet" style="margin-top:1rem">
          <div class="tweet-hd"><div class="who">Bluesky</div></div>
          <div class="body meta">
            Optional Bluesky home timeline in VAAK via a native Bluesky / PDS login.
            Connected accounts mirror your VAAK <b>avatar</b>, <b>header</b>, and <b>bio</b>
            (long bios are truncated with a link to your full HTML profile).
            Create an app password under
            <a href="https://bsky.app/settings/app-passwords" target="_blank" rel="noopener noreferrer">Bluesky settings</a>
            (or use your <code>bsky.mkultra.monster</code> account password).
          </div>
          <?php if ($bskyHandle !== ''): ?>
            <div class="meta" style="margin:.5rem 0">Connected as <b>@<?= h($bskyHandle) ?></b>
              · <a href="?view=bluesky">Open Bluesky tab</a>
            </div>
            <div style="display:flex;flex-wrap:wrap;gap:.5rem;margin-top:.5rem">
              <form method="post" action="?view=profile" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
                <input type="hidden" name="action" value="bsky_sync_profile">
                <input type="hidden" name="return_view" value="profile">
                <button class="btn btn-primary" type="submit">Re-sync avatar / header / bio</button>
              </form>
              <form method="post" action="?view=profile" style="display:inline">
                <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
                <input type="hidden" name="action" value="bsky_disconnect">
                <input type="hidden" name="return_view" value="profile">
                <button class="btn btn-ghost" type="submit">Disconnect Bluesky</button>
              </form>
            </div>
          <?php else: ?>
            <form class="composer" method="post" action="?view=profile" style="margin-top:.75rem">
              <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
              <input type="hidden" name="action" value="bsky_connect">
              <input type="hidden" name="return_view" value="profile">
              <div style="display:block;max-width:22rem;margin:0 0 .85rem">
                <label for="bsky-handle" style="display:block;margin:0 0 .3rem">Handle</label>
                <input id="bsky-handle" name="bsky_handle" type="text" required autocomplete="username"
                       placeholder="you.bsky.social" style="max-width:22rem;margin-top:0">
              </div>
              <div style="display:block;max-width:22rem;margin:0 0 .85rem">
                <label for="bsky-app-password" style="display:block;margin:0 0 .3rem">App password</label>
                <input id="bsky-app-password" name="bsky_app_password" type="password" required autocomplete="new-password"
                       placeholder="xxxx-xxxx-xxxx-xxxx" style="max-width:22rem;margin-top:0">
              </div>
              <div style="display:block;max-width:22rem;margin:0 0 .5rem">
                <label for="bsky-pds" style="display:block;margin:0 0 .3rem">PDS</label>
                <div class="meta" style="margin:0 0 .35rem">
                  For a VAAK-hosted account use <code>https://bsky.mkultra.monster</code>.
                  Leave blank only for normal <code>*.bsky.social</code> accounts.
                </div>
                <input id="bsky-pds" name="bsky_pds" type="url" value="https://bsky.mkultra.monster"
                       placeholder="https://bsky.mkultra.monster" style="max-width:22rem;margin-top:0">
              </div>
              <div class="composer-actions" style="margin-top:.75rem">
                <button class="btn btn-primary" type="submit">Connect Bluesky</button>
              </div>
            </form>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <h2 style="font-size:1rem;margin:2rem 0 .5rem">Muted accounts</h2>
        <div class="meta" style="margin-bottom:.75rem">
          Hide from <b>your</b> Home / Federated / Notifications. Follow stays. Also available on remote profiles.
        </div>
        <form class="composer" method="post" action="?view=profile" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="mute_remote">
          <input type="hidden" name="return_view" value="profile">
          <input name="actor_id" type="text" required placeholder="@user@instance  or  https://…/users/…">
          <div class="composer-actions">
            <span class="meta"><?= count($mutes) ?> muted</span>
            <button class="btn btn-primary" type="submit">Mute</button>
          </div>
        </form>
        <?php if (!$mutes): ?>
          <div class="empty" style="margin-bottom:1.25rem">No mutes yet.</div>
        <?php else: ?>
          <?php foreach ($mutes as $mu): ?>
            <?php $muActor = (string) ($mu['actor_id'] ?? ''); ?>
            <article class="tweet">
              <div class="tweet-hd">
                <?= admin_avatar_img($muActor !== '' ? $muActor : null) ?>
                <div class="tweet-hd-main">
                  <div>
                    <span class="who"><?= actor_display_name_html($muActor !== '' ? $muActor : null) ?></span>
                    <span class="meta"> <?= h(actor_handle($muActor !== '' ? $muActor : null)) ?></span>
                    <span class="meta"> · <?= h(relative_time((string) ($mu['created_at'] ?? ''))) ?></span>
                  </div>
                </div>
              </div>
              <div class="mono"><?= h($muActor) ?></div>
              <div class="tweet-actions">
                <?php if ($muActor !== ''): ?>
                  <a href="?view=remote_profile&amp;actor=<?= urlencode($muActor) ?>&amp;from=profile">Profile</a>
                  <a href="<?= h($muActor) ?>" target="_blank" rel="noopener noreferrer"><?= h(admin_open_profile_label($muActor)) ?></a>
                <?php endif; ?>
                <form method="post" action="?view=profile" style="display:inline">
                  <input type="hidden" name="action" value="unmute_remote">
                  <input type="hidden" name="return_view" value="profile">
                  <input type="hidden" name="actor_id" value="<?= h($muActor) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Unmute</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

        <h2 style="font-size:1rem;margin:2rem 0 .5rem">Deprioritized on Home</h2>
        <div class="meta" style="margin-bottom:.75rem">
          Soft-rank these accounts lower on <b>Home</b> only (⋯ → Deprioritize). They still appear on Federated, Local, and notifications. Mute/block still fully hide.
        </div>
        <?php if (!$deprioritizedRows): ?>
          <div class="empty" style="margin-bottom:1.25rem">Nobody deprioritized yet — use ⋯ on a post.</div>
        <?php else: ?>
          <?php foreach ($deprioritizedRows as $dpRow): ?>
            <?php $dpActor = rtrim((string) ($dpRow['actor_id'] ?? ''), '/'); ?>
            <article class="tweet">
              <div class="tweet-hd">
                <div>
                  <span class="who"><?= h($dpActor) ?></span>
                  <span class="meta"> · <?= h(relative_time((string) ($dpRow['created_at'] ?? ''))) ?></span>
                </div>
              </div>
              <div class="tweet-actions">
                <form method="post" action="?view=profile" style="display:inline">
                  <input type="hidden" name="csrf" value="<?= h(ap_auth_csrf_token()) ?>">
                  <input type="hidden" name="action" value="undeprioritize_remote">
                  <input type="hidden" name="return_view" value="profile">
                  <input type="hidden" name="actor_id" value="<?= h($dpActor) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Stop deprioritizing</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

        <h2 style="font-size:1rem;margin:2rem 0 .5rem">Muted words</h2>
        <div class="meta" style="margin-bottom:.75rem">
          Hide posts on your Home &amp; Federated when text/CW contains a phrase (case-insensitive substring).
        </div>
        <form class="composer" method="post" action="?view=profile" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="muted_word_add">
          <input name="phrase" type="text" required maxlength="200" placeholder="word or phrase to hide…" autocomplete="off">
          <input name="note" type="text" maxlength="300" placeholder="Optional note">
          <div class="composer-actions">
            <span class="meta"><?= count($mutedWordRows) ?> phrase<?= count($mutedWordRows) === 1 ? '' : 's' ?></span>
            <button class="btn btn-primary" type="submit">Mute phrase</button>
          </div>
        </form>
        <?php if (!$mutedWordRows): ?>
          <div class="empty" style="margin-bottom:1.25rem">No muted words yet.</div>
        <?php else: ?>
          <?php foreach ($mutedWordRows as $mw): ?>
            <article class="tweet">
              <div class="tweet-hd">
                <div>
                  <span class="who">“<?= h((string) ($mw['phrase'] ?? '')) ?>”</span>
                  <span class="meta"> · <?= h(relative_time((string) ($mw['created_at'] ?? ''))) ?></span>
                </div>
                <div class="meta">#<?= (int) ($mw['id'] ?? 0) ?></div>
              </div>
              <?php if (!empty($mw['note'])): ?>
                <div class="body meta"><?= h((string) $mw['note']) ?></div>
              <?php endif; ?>
              <div class="tweet-actions">
                <form method="post" action="?view=profile" style="display:inline">
                  <input type="hidden" name="action" value="muted_word_remove">
                  <input type="hidden" name="id" value="<?= (int) ($mw['id'] ?? 0) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Remove</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

        <h2 style="font-size:1rem;margin:2rem 0 .5rem">Your blocks</h2>
        <div class="meta" style="margin-bottom:.75rem">
          Personal blocks hide that account’s <b>profile and posts</b> from you (timelines, search, profile pages). Mutes only filter timelines/notifications.
          <?php if (!empty($vaakIsAdmin)): ?>
            Server-wide enforcement: <a href="?view=blocks">Admin → Server blocks</a>.
          <?php endif; ?>
        </div>
        <form class="composer" method="post" action="?view=profile" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="user_block_add">
          <input name="target" type="text" required placeholder="example.com  or  @user@instance  or  https://…/users/…">
          <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin:.5rem 0">
            <label class="meta" style="display:flex;gap:.35rem;align-items:center;color:var(--text)">
              <input type="radio" name="kind" value="block" checked> Block
            </label>
            <label class="meta" style="display:flex;gap:.35rem;align-items:center;color:var(--text)">
              <input type="radio" name="kind" value="suspend"> Suspend
            </label>
          </div>
          <input name="reason" type="text" maxlength="500" placeholder="Optional note">
          <div class="composer-actions">
            <span class="meta"><?= count($userBlocks) ?> personal</span>
            <button class="btn btn-primary" type="submit">Add personal block</button>
          </div>
        </form>
        <?php if (!$userBlocks): ?>
          <div class="empty" style="margin-bottom:1.25rem">No personal blocks yet.</div>
        <?php else: ?>
          <?php foreach ($userBlocks as $ub): ?>
            <article class="tweet">
              <div class="tweet-hd">
                <div>
                  <span class="who"><?= h((string) ($ub['value'] ?? '')) ?></span>
                  <span class="meta"> · <?= h((string) ($ub['scope'] ?? '')) ?> · <?= h((string) ($ub['kind'] ?? '')) ?> · <?= h(relative_time((string) ($ub['created_at'] ?? ''))) ?></span>
                </div>
                <div class="meta">#<?= (int) ($ub['id'] ?? 0) ?></div>
              </div>
              <?php if (!empty($ub['reason'])): ?>
                <div class="body meta"><?= h((string) $ub['reason']) ?></div>
              <?php endif; ?>
              <div class="tweet-actions">
                <form method="post" action="?view=profile" style="display:inline">
                  <input type="hidden" name="action" value="user_block_remove">
                  <input type="hidden" name="id" value="<?= (int) ($ub['id'] ?? 0) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Remove</button>
                </form>
                <?php if (($ub['scope'] ?? '') === 'actor'): ?>
                  <a href="?view=remote_profile&amp;actor=<?= urlencode((string) $ub['value']) ?>&amp;from=profile">Profile</a>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

        <h2 style="font-size:1rem;margin:2rem 0 .5rem">AI alt-text API key</h2>
        <?php
          $xaiConfigured = function_exists('ap_auth_user_has_xai_key') && ap_auth_user_has_xai_key($vaakUser);
          $aiRootVal = trim((string) ($vaakUser['ai_api_root'] ?? ''));
          $aiModelVal = trim((string) ($vaakUser['ai_model'] ?? ''));
          if (($aiRootVal === '' || $aiModelVal === '') && function_exists('ap_auth_user_by_id')) {
              $freshAi = ap_auth_user_by_id((int) ($vaakUser['id'] ?? 0));
              if (is_array($freshAi)) {
                  if ($aiRootVal === '') {
                      $aiRootVal = trim((string) ($freshAi['ai_api_root'] ?? ''));
                  }
                  if ($aiModelVal === '') {
                      $aiModelVal = trim((string) ($freshAi['ai_model'] ?? ''));
                  }
              }
          }
        ?>
        <div class="meta" style="margin-bottom:.75rem">
          OpenAI-compatible API key for image descriptions (Chat Completions vision).
          Stored encrypted <b>on your account only</b> — never shown again after save, never shared with other users.
          Status: <b><?= $xaiConfigured ? 'configured' : 'not configured' ?></b>
          <?php if (!$xaiConfigured && $vaakActorKey === 'cmdr_nova'): ?>
            · operator host key available as fallback for you only
          <?php elseif (!$xaiConfigured): ?>
            · add your own key to use AI describe (no shared server key)
          <?php endif; ?>
        </div>
        <form class="composer" method="post" action="?view=profile" style="margin-bottom:.75rem" autocomplete="off">
          <input type="hidden" name="action" value="save_xai_key">
          <label class="meta" style="display:block;margin-bottom:.35rem">API key</label>
          <input name="xai_api_key" type="password" maxlength="500" placeholder="sk-… or provider key" autocomplete="new-password" style="margin-bottom:.65rem"<?= $xaiConfigured ? '' : ' required' ?>>
          <label class="meta" style="display:block;margin-bottom:.35rem">API base URL <span style="opacity:.7">(optional)</span></label>
          <input name="ai_api_root" type="url" maxlength="300" placeholder="https://api.openai.com/v1" value="<?= h($aiRootVal) ?>" style="margin-bottom:.65rem">
          <label class="meta" style="display:block;margin-bottom:.35rem">Model <span style="opacity:.7">(optional)</span></label>
          <input name="ai_model" type="text" maxlength="120" placeholder="gpt-4o-mini" value="<?= h($aiModelVal) ?>" style="margin-bottom:.65rem">
          <div class="composer-actions">
            <span class="meta">Key replaces any existing personal key; blank base/model clears those fields</span>
            <button class="btn btn-primary" type="submit">Save</button>
          </div>
        </form>
        <?php if ($xaiConfigured): ?>
          <form method="post" action="?view=profile" style="margin-bottom:1.25rem" onsubmit="return confirm('Clear your personal AI API key?');">
            <input type="hidden" name="action" value="clear_xai_key">
            <button class="btn btn-ghost" type="submit">Clear API key</button>
          </form>
        <?php endif; ?>

      <?php elseif ($view === 'import_export'): ?>
        <?php
          $followStatus = ap_ie_follow_status();
          $aliases = ap_alias_list(vaak_actor_key());
          $moveRow = ap_move_get(vaak_actor_key());
          $activeMove = ap_move_active_target(vaak_actor_key());
          $cooldownSec = ap_move_cooldown_remaining_seconds(vaak_actor_key());
        ?>
        <div class="meta" style="margin-bottom:1rem">
          Mastodon-compatible <b>CSV</b> export/import (merge). Posts and media do <em>not</em> migrate with Move — only relationships / lists / bookmarks as selected.
        </div>

        <h3 style="font-size:.95rem;color:var(--muted);margin:0 0 .5rem">Export</h3>
        <div class="tweet-actions" style="flex-wrap:wrap;gap:.5rem;margin-bottom:1.25rem">
          <a class="btn btn-primary" href="?view=import_export&amp;export=following">following_accounts.csv</a>
          <a class="btn btn-ghost" href="?view=import_export&amp;export=mutes">muted_accounts.csv</a>
          <a class="btn btn-ghost" href="?view=import_export&amp;export=blocks">blocked_accounts.csv</a>
          <a class="btn btn-ghost" href="?view=import_export&amp;export=blocked_domains">blocked_domains.csv</a>
          <a class="btn btn-ghost" href="?view=import_export&amp;export=bookmarks">bookmarks.csv</a>
          <a class="btn btn-ghost" href="?view=import_export&amp;export=lists">lists.csv</a>
          <a class="btn btn-ghost" href="?view=import_export&amp;export=followed_tags">followed_tags.csv</a>
        </div>

        <h3 style="font-size:.95rem;color:var(--muted);margin:0 0 .5rem">Import (merge)</h3>
        <form class="composer" method="post" action="?view=import_export" enctype="multipart/form-data" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="ie_import">
          <label class="meta" style="display:block;margin-bottom:.35rem">Type</label>
          <select name="import_kind" required style="width:100%;max-width:22rem;margin-bottom:.65rem">
            <option value="following">Follows (following_accounts.csv) — rate-limited 30/hr</option>
            <option value="mutes">Mutes (muted_accounts.csv)</option>
            <option value="blocks">Blocked accounts</option>
            <option value="blocked_domains">Blocked domains</option>
            <option value="bookmarks">Bookmarks (#uri)</option>
            <option value="lists">Lists (List name, Account address)</option>
            <option value="followed_tags">Followed hashtags (#hashtag)</option>
          </select>
          <input type="file" name="csv" accept=".csv,text/csv,text/plain" required>
          <div class="composer-actions">
            <span class="meta">UTF-8 · merge only · follows share CLI state file</span>
            <button class="btn btn-primary" type="submit">Upload &amp; import</button>
          </div>
        </form>

        <?php if (($followStatus['total'] ?? 0) > 0): ?>
          <article class="tweet" style="margin-bottom:1rem">
            <div class="tweet-hd"><div class="who">Follow import job</div></div>
            <div class="body meta">
              Progress <?= (int) $followStatus['index'] ?> / <?= (int) $followStatus['total'] ?>
              · ok <?= (int) $followStatus['ok'] ?>
              · already <?= (int) $followStatus['already'] ?>
              · failed <?= (int) $followStatus['failed'] ?>
              · skipped <?= (int) $followStatus['skipped'] ?>
              · remaining <?= (int) $followStatus['remaining'] ?>
              <?php if (!empty($followStatus['finished_at'])): ?>
                · <span class="tag">finished</span>
              <?php endif; ?>
            </div>
            <?php if (!empty($followStatus['recent_errors'])): ?>
              <div class="meta" style="margin-top:.35rem;color:var(--danger)">
                <?php foreach (array_slice($followStatus['recent_errors'], -3) as $err): ?>
                  @<?= h((string) ($err['acct'] ?? '')) ?>: <?= h((string) ($err['error'] ?? '')) ?><br>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
            <?php if ((int) ($followStatus['remaining'] ?? 0) > 0): ?>
              <div class="tweet-actions">
                <form method="post" action="?view=import_export" style="display:inline">
                  <input type="hidden" name="action" value="ie_follow_continue">
                  <button class="btn btn-primary" type="submit">Continue batch (+5)</button>
                </form>
                <span class="meta">Or CLI: <code>php ap-follow-import-continue.php</code></span>
              </div>
            <?php endif; ?>
          </article>
        <?php endif; ?>

        <h3 style="font-size:.95rem;color:var(--muted);margin:1.25rem 0 .5rem">Migration — aliases (alsoKnownAs)</h3>
        <div class="meta" style="margin-bottom:.75rem;line-height:1.45">
          Link another account you own so remotes can verify Move / alias relationships.
          <div style="margin:.65rem 0 0;padding:.65rem .75rem;border:1px solid var(--border);border-radius:10px;background:#0e0e0e">
            <b style="color:var(--primary)">Which dropdown option?</b>
            <ul style="margin:.4rem 0 0;padding-left:1.2rem">
              <li style="margin:.35rem 0">
                <b>Old account (moved_from)</b> —
                use when <em>this</em> VAAK account (<code><?= h($vaakHandle) ?></code>) is the
                <b>new home</b> and you’re migrating <b>from</b> somewhere else
                (e.g. Mastodon → here). Paste the <em>old</em> account below.
              </li>
              <li style="margin:.35rem 0">
                <b>alsoKnownAs (aka)</b> —
                use when you just want to say “I also control this other account”
                (same person, <b>no</b> follower Move). Either direction of “also me.”
              </li>
            </ul>
          </div>
          <div style="margin:.65rem 0 0">
            <b>Moving into VAAK (typical):</b>
            <ol style="margin:.35rem 0 0;padding-left:1.2rem">
              <li>Here: add the old account as <b>Old account (moved_from)</b>.</li>
              <li>On the old server (e.g. Mastodon <b>Preferences → Account → Account aliases</b>):
                paste <code>https://mkultra.monster/users/<?= h($vaakActorKey) ?></code>
                (or <code>https://mkultra.monster/@<?= h($vaakActorKey) ?></code> — both count).</li>
              <li>Back here: click <b>Verify remote</b>.</li>
              <li>On the old server: run <b>Move to another account</b> targeting
                <code><?= h($vaakHandle) ?></code>
                (or use <b>Prepare as target</b> below, then Move on the old side).</li>
            </ol>
          </div>
        </div>
        <form class="composer" method="post" action="?view=import_export" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="alias_add">
          <input name="alias_ref" type="text" required placeholder="@old@instance or https://host/@user or /users/…">
          <label class="meta" for="alias-direction" style="display:block;margin:.55rem 0 .25rem">Alias type</label>
          <select id="alias-direction" name="direction" style="width:100%;max-width:22rem">
            <option value="moved_from">Old account (moved_from) — migrating INTO this account</option>
            <option value="aka">alsoKnownAs (aka) — same person, not a Move</option>
          </select>
          <div class="composer-actions">
            <span class="meta">Saves + fans out profile Update</span>
            <button class="btn btn-primary" type="submit">Add alias</button>
          </div>
        </form>
        <?php if (!$aliases): ?>
          <div class="empty">No aliases yet.</div>
        <?php else: ?>
          <?php foreach ($aliases as $arow): ?>
            <article class="tweet">
              <div class="tweet-hd">
                <div class="tweet-hd-main">
                  <div>
                    <span class="who"><?= h((string) ($arow['alias_acct'] ?: $arow['alias_actor_id'])) ?></span>
                    <span class="tag"><?= h((string) ($arow['direction'] ?? '')) ?></span>
                    <?php if (!empty($arow['verified_at'])): ?>
                      <span class="tag">verified</span>
                    <?php endif; ?>
                  </div>
                  <div class="meta"><?= h((string) ($arow['alias_actor_id'] ?? '')) ?></div>
                </div>
              </div>
              <div class="tweet-actions">
                <form method="post" action="?view=import_export" style="display:inline">
                  <input type="hidden" name="action" value="alias_verify">
                  <input type="hidden" name="alias_id" value="<?= (int) ($arow['id'] ?? 0) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Verify remote</button>
                </form>
                <form method="post" action="?view=import_export" style="display:inline" onsubmit="return confirm('Remove this alias?');">
                  <input type="hidden" name="action" value="alias_remove">
                  <input type="hidden" name="alias_id" value="<?= (int) ($arow['id'] ?? 0) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem;color:var(--danger)">Remove</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

        <h3 style="font-size:.95rem;color:var(--muted);margin:1.25rem 0 .5rem">Migration — Move followers</h3>
        <div class="meta" style="margin-bottom:.75rem;line-height:1.45">
          <span style="color:var(--danger)"><b>Dangerous.</b></span>
          These tools publish ActivityPub <code>movedTo</code> / prepare the target.
          Posts and media stay on the old account; supporting servers migrate <b>followers</b>.
          Cooldown: <?= (int) AP_IE_MOVE_COOLDOWN_DAYS ?> days between moves.
          <div style="margin:.65rem 0 0;padding:.65rem .75rem;border:1px solid var(--border);border-radius:10px;background:#0e0e0e">
            <b style="color:var(--primary)">Two directions — pick one</b>
            <ul style="margin:.4rem 0 0;padding-left:1.2rem">
              <li style="margin:.35rem 0">
                <b>Moving INTO this VAAK account</b> (recommended for imports) —
                use <b>Prepare as target</b> below (or add <b>moved_from</b> above),
                verify aliases, then trigger Move on the <em>old</em> server toward
                <code><?= h($vaakHandle) ?></code>. Do <em>not</em> use Move out here.
              </li>
              <li style="margin:.35rem 0">
                <b>Moving AWAY from this VAAK account</b> —
                use <b>Move out</b> (this account becomes the old / tombstone side).
                Prefer aliases set up on the new account first.
              </li>
            </ul>
          </div>
        </div>

        <?php if ($activeMove): ?>
          <article class="tweet" style="margin-bottom:1rem;border-color:var(--danger)">
            <div class="tweet-hd"><div class="who">Active Move</div></div>
            <div class="body">
              <code>movedTo</code> → <?= h($activeMove) ?>
              <?php if (!empty($moveRow['moved_to_acct'])): ?>
                (<?= h((string) $moveRow['moved_to_acct']) ?>)
              <?php endif; ?>
              <div class="meta">Since <?= h((string) ($moveRow['moved_at'] ?? '')) ?></div>
            </div>
            <form class="composer" method="post" action="?view=import_export" style="margin-top:.75rem" onsubmit="return confirm('Clear movedTo and re-push profile?');">
              <input type="hidden" name="action" value="move_clear">
              <input name="confirm" type="text" required placeholder="Type CLEAR to confirm" autocomplete="off">
              <div class="composer-actions">
                <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Clear movedTo</button>
              </div>
            </form>
          </article>
        <?php else: ?>
          <form class="composer" method="post" action="?view=import_export" style="margin-bottom:1rem" onsubmit="return confirm('This publishes movedTo and notifies followers. Continue?');">
            <input type="hidden" name="action" value="move_out">
            <div class="meta" style="margin-bottom:.5rem">
              <b>Move out</b> — leave <em>this</em> account (we are the <b>old</b> account).
              Followers are pointed at the new account below.
            </div>
            <input name="target" type="text" required placeholder="New account @user@host or https://…">
            <input name="confirm" type="text" required placeholder="Type MOVE to confirm" autocomplete="off" style="margin-top:.5rem">
            <?php if ($cooldownSec > 0): ?>
              <div class="meta" style="margin-top:.5rem;color:var(--danger)">Cooldown remaining ~<?= (int) ceil($cooldownSec / 86400) ?> days</div>
            <?php endif; ?>
            <div class="composer-actions">
              <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Move out</button>
            </div>
          </form>
        <?php endif; ?>

        <form class="composer" method="post" action="?view=import_export" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="move_prepare_target">
          <div class="meta" style="margin-bottom:.5rem;line-height:1.45">
            <b>Prepare as target</b> — <em>this</em> account is the <b>new</b> home.
            Adds the old account as <code>moved_from</code> / alsoKnownAs (same as the alias dropdown above).
            Afterward, on the old server, Move / alias to <code><?= h($vaakHandle) ?></code>.
          </div>
          <input name="old_account" type="text" required placeholder="Old @user@host or https://…">
          <div class="composer-actions">
            <span class="meta">Then Move on the old server → <?= h($vaakHandle) ?></span>
            <button class="btn btn-primary" type="submit">Prepare as target</button>
          </div>
        </form>

      <?php elseif ($view === 'tags'): ?>
        <?php
          require_once __DIR__ . '/ap-masto-entities.php';
          $followedTags = ap_masto_followed_tags(0, admin_owner_user_id());
        ?>
        <form class="composer" method="post" action="?view=tags" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="follow_tag">
          <input type="hidden" name="return_view" value="tags">
          <div class="meta" style="margin-bottom:.5rem">
            Follow hashtags for Home (same store Ice Cubes uses). They mix into Home at a lower priority than people you follow.
          </div>
          <input name="tag" type="text" required placeholder="#linux or linux" maxlength="100" autofocus>
          <div class="composer-actions">
            <span class="meta"><?= count($followedTags) ?> followed tag<?= count($followedTags) === 1 ? '' : 's' ?></span>
            <button class="btn btn-primary" type="submit">Follow hashtag</button>
          </div>
        </form>

        <h3 style="font-size:.95rem;color:var(--muted);margin:0 0 .5rem">Followed hashtags</h3>
        <?php if (!$followedTags): ?>
          <div class="empty">No followed hashtags yet. Type a tag above to start.</div>
        <?php else: ?>
          <?php foreach ($followedTags as $ft): ?>
            <article class="tweet">
              <div class="tweet-hd">
                <div class="who">#<?= h((string) ($ft['name'] ?? '')) ?></div>
              </div>
              <div class="tweet-actions">
                <a href="?view=search&amp;q=<?= urlencode('#' . ($ft['name'] ?? '')) ?>&amp;type=statuses">View posts</a>
                <form method="post" action="?view=tags" style="display:inline">
                  <input type="hidden" name="action" value="unfollow_tag">
                  <input type="hidden" name="return_view" value="tags">
                  <input type="hidden" name="tag" value="<?= h((string) ($ft['name'] ?? '')) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Unfollow</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($view === 'collections'): ?>
        <?php
          $collId = (int) ($_GET['id'] ?? 0);
          $collDetail = $collId > 0 ? ap_collection_by_id($collId) : null;
          // Per-account isolation: never show/edit another local user's collection.
          if (is_array($collDetail) && !ap_collection_owned_by_session($collDetail)) {
              $collDetail = null;
          }
          $collNotFound = ($collId > 0 && !$collDetail);
          $members = ($collDetail ? ap_collection_items((int) $collDetail['id'], 'accepted') : []);
        ?>
        <?php if ($collNotFound): ?>
          <div class="empty">Collection not found. <a href="?view=collections">Back to collections</a></div>
        <?php elseif ($collDetail): ?>
          <div style="margin-bottom:.75rem">
            <div class="page-back"><a class="btn btn-ghost" href="?view=collections">← All collections</a></div>
          </div>
          <form class="composer" method="post" action="?view=collections&amp;id=<?= (int) $collDetail['id'] ?>" style="margin-bottom:1rem">
            <input type="hidden" name="action" value="collection_update">
            <input type="hidden" name="collection_id" value="<?= (int) $collDetail['id'] ?>">
            <div class="meta" style="margin-bottom:.5rem">Edit collection · <?= count($members) ?>/<?= (int) AP_COLLECTION_MAX_MEMBERS ?> members</div>
            <input name="name" type="text" required maxlength="40" value="<?= h((string) $collDetail['name']) ?>" placeholder="Name">
            <textarea name="description" maxlength="500" placeholder="Short description (optional)" style="margin-top:.5rem;min-height:4rem"><?= h((string) ($collDetail['description'] ?? '')) ?></textarea>
            <input name="tag_name" type="text" maxlength="100" value="<?= h((string) ($collDetail['tag_name'] ?? '')) ?>" placeholder="#topic (optional)" style="margin-top:.5rem">
            <label class="composer-check" style="margin-top:.65rem">
              <input type="checkbox" name="discoverable" value="1" <?= !empty($collDetail['discoverable']) ? 'checked' : '' ?>>
              <span>Discoverable (public page + profile listing later)</span>
            </label>
            <div class="composer-actions">
              <a class="meta" href="<?= h((string) ($collDetail['html_url'] ?? ap_collection_html_url((int) $collDetail['id']))) ?>" target="_blank" rel="noopener noreferrer">Public URL</a>
              <button class="btn btn-primary" type="submit">Save</button>
            </div>
          </form>

          <div class="tweet-actions" style="margin:0 0 1rem;gap:.5rem">
            <form method="post" action="?view=collections&amp;id=<?= (int) $collDetail['id'] ?>" style="display:inline" onsubmit="return confirm('Follow everyone in this collection who you are not already following? Rate limit: 30/hour.');">
              <input type="hidden" name="action" value="collection_follow_all">
              <input type="hidden" name="collection_id" value="<?= (int) $collDetail['id'] ?>">
              <button class="btn btn-primary" type="submit">Follow all</button>
            </form>
            <form method="post" action="?view=collections" style="display:inline" onsubmit="return confirm(<?= json_encode('Delete collection “' . (string) ($collDetail['name'] ?? '') . '”?') ?>);">
              <input type="hidden" name="action" value="collection_delete">
              <input type="hidden" name="collection_id" value="<?= (int) $collDetail['id'] ?>">
              <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Delete collection</button>
            </form>
          </div>

          <form class="composer" method="post" action="?view=collections&amp;id=<?= (int) $collDetail['id'] ?>" style="margin-bottom:1rem">
            <input type="hidden" name="action" value="collection_add_member">
            <input type="hidden" name="collection_id" value="<?= (int) $collDetail['id'] ?>">
            <div class="meta" style="margin-bottom:.5rem">Add account</div>
            <input name="actor" type="text" required placeholder="@user@instance or https://…/users/…">
            <div class="composer-actions">
              <span class="meta">Resolves via WebFinger · max <?= (int) AP_COLLECTION_MAX_MEMBERS ?></span>
              <button class="btn btn-primary" type="submit">Add</button>
            </div>
          </form>

          <h3 style="font-size:.95rem;color:var(--muted);margin:0 0 .5rem">Members</h3>
          <?php if (!$members): ?>
            <div class="empty">No members yet. Add @user@host above.</div>
          <?php else: ?>
            <?php foreach ($members as $mem): ?>
              <?php $maid = (string) ($mem['actor_id'] ?? ''); ?>
              <article class="tweet">
                <div class="tweet-hd">
                  <?= admin_avatar_img($maid) ?>
                  <div class="tweet-hd-main">
                    <div>
                      <span class="who"><?= actor_display_name_html($maid) ?></span>
                      <span class="meta"> <?= h(actor_handle($maid)) ?></span>
                      <?php if (ap_actor_is_followed($maid)): ?>
                        <span class="tag">following</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="tweet-actions">
                  <a href="?view=remote_profile&amp;actor=<?= urlencode($maid) ?>">Profile</a>
                  <form method="post" action="?view=collections&amp;id=<?= (int) $collDetail['id'] ?>" style="display:inline">
                    <input type="hidden" name="action" value="collection_remove_member">
                    <input type="hidden" name="collection_id" value="<?= (int) $collDetail['id'] ?>">
                    <input type="hidden" name="actor_id" value="<?= h($maid) ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Remove</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>

        <?php else: ?>
          <?php
            $localColls = ap_collections_list_local();
            $memberships = ap_collection_memberships_list(false);
          ?>
          <div class="meta" style="margin-bottom:.75rem">
            Curated bundles of accounts (like Bluesky starter packs / Mastodon Collections). Follow everyone in a pack at once.
            Local limit: <?= (int) AP_COLLECTION_MAX_LOCAL ?> collections · <?= (int) AP_COLLECTION_MAX_MEMBERS ?> members each.
          </div>

          <form class="composer" method="post" action="?view=collections" style="margin-bottom:1rem">
            <input type="hidden" name="action" value="collection_create">
            <div class="meta" style="margin-bottom:.5rem">New collection</div>
            <input name="name" type="text" required maxlength="40" placeholder="Name (e.g. Indie web folks)">
            <textarea name="description" maxlength="500" placeholder="Short description (optional)" style="margin-top:.5rem;min-height:3.5rem"></textarea>
            <input name="tag_name" type="text" maxlength="100" placeholder="#topic (optional)" style="margin-top:.5rem">
            <label class="composer-check" style="margin-top:.65rem">
              <input type="checkbox" name="discoverable" value="1" checked>
              <span>Discoverable public page</span>
            </label>
            <div class="composer-actions">
              <span class="meta"><?= count($localColls) ?> / <?= (int) AP_COLLECTION_MAX_LOCAL ?></span>
              <button class="btn btn-primary" type="submit">Create</button>
            </div>
          </form>

          <h3 style="font-size:.95rem;color:var(--muted);margin:0 0 .5rem">Your collections</h3>
          <?php if (!$localColls): ?>
            <div class="empty">No collections yet. Create one above.</div>
          <?php else: ?>
            <?php foreach ($localColls as $c): ?>
              <article class="tweet">
                <div class="tweet-hd">
                  <div class="tweet-hd-main">
                    <div>
                      <span class="who"><?= h((string) ($c['name'] ?? '')) ?></span>
                      <?php if (!empty($c['discoverable'])): ?>
                        <span class="tag">discoverable</span>
                      <?php else: ?>
                        <span class="tag">unlisted</span>
                      <?php endif; ?>
                      <?php if (!empty($c['tag_name'])): ?>
                        <span class="meta"> #<?= h((string) $c['tag_name']) ?></span>
                      <?php endif; ?>
                    </div>
                    <div class="meta"><?= (int) ($c['member_count'] ?? 0) ?> members · <?= h(relative_time((string) ($c['updated_at'] ?? ''))) ?></div>
                  </div>
                </div>
                <?php if (trim((string) ($c['description'] ?? '')) !== ''): ?>
                  <div class="body" style="margin:.35rem 0"><?= h((string) $c['description']) ?></div>
                <?php endif; ?>
                <div class="tweet-actions">
                  <a class="btn btn-primary" href="?view=collections&amp;id=<?= (int) $c['id'] ?>" style="padding:.35rem .9rem;font-size:.85rem">Open</a>
                  <form method="post" action="?view=collections&amp;id=<?= (int) $c['id'] ?>" style="display:inline" onsubmit="return confirm('Follow everyone not already followed?');">
                    <input type="hidden" name="action" value="collection_follow_all">
                    <input type="hidden" name="collection_id" value="<?= (int) $c['id'] ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Follow all</button>
                  </form>
                  <form method="post" action="?view=collections" style="display:inline" onsubmit="return confirm(<?= json_encode('Delete collection “' . (string) ($c['name'] ?? '') . '”?') ?>);">
                    <input type="hidden" name="action" value="collection_delete">
                    <input type="hidden" name="collection_id" value="<?= (int) $c['id'] ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem;color:var(--danger)">Delete</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>

          <h3 style="font-size:.95rem;color:var(--muted);margin:1.25rem 0 .5rem">You’re featured in</h3>
          <form class="composer" method="post" action="?view=collections" style="margin-bottom:1rem">
            <input type="hidden" name="action" value="collection_membership_import">
            <div class="meta" style="margin-bottom:.5rem">Paste a remote collection URL that includes you (AS2 FeaturedCollection / Mastodon collection JSON)</div>
            <input name="url" type="url" required placeholder="https://…/collections/…">
            <div class="composer-actions">
              <span class="meta">Best-effort until full FEP federation</span>
              <button class="btn btn-primary" type="submit">Import</button>
            </div>
          </form>
          <?php if (!$memberships): ?>
            <div class="empty">No memberships recorded yet.</div>
          <?php else: ?>
            <?php foreach ($memberships as $mrow): ?>
              <article class="tweet">
                <div class="tweet-hd">
                  <div class="tweet-hd-main">
                    <div>
                      <span class="who"><?= h((string) ($mrow['name'] ?? 'Untitled collection')) ?></span>
                      <span class="tag"><?= h((string) ($mrow['state'] ?? 'accepted')) ?></span>
                    </div>
                    <div class="meta">
                      <?php if (!empty($mrow['curator_actor_id'])): ?>
                        <?= h(actor_handle((string) $mrow['curator_actor_id'])) ?> ·
                      <?php endif; ?>
                      <?= h(relative_time((string) ($mrow['updated_at'] ?? ''))) ?>
                    </div>
                  </div>
                </div>
                <div class="tweet-actions">
                  <?php if (!empty($mrow['html_url'])): ?>
                    <a href="<?= h((string) $mrow['html_url']) ?>" target="_blank" rel="noopener noreferrer">Open remote</a>
                  <?php endif; ?>
                  <form method="post" action="?view=collections" style="display:inline">
                    <input type="hidden" name="action" value="collection_membership_dismiss">
                    <input type="hidden" name="membership_id" value="<?= (int) ($mrow['id'] ?? 0) ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Dismiss</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>

      <?php elseif ($view === 'lists'): ?>
        <?php
          $listId = (int) ($_GET['id'] ?? 0);
          $listDetail = $listId > 0 ? ap_list_by_id($listId) : null;
          $listNotFound = ($listId > 0 && !$listDetail);
          $listMembers = ($listDetail ? ap_list_accounts((int) $listDetail['id']) : []);
          $listShowTimeline = !empty($_GET['timeline']) && $listDetail;
          $listTimelineStatuses = [];
          if ($listShowTimeline && function_exists('ap_masto_timeline_list')) {
              $listTimelineStatuses = ap_masto_timeline_list((int) $listDetail['id'], 40);
          }
          $followingIdsForLists = [];
          foreach (ap_following_list($vaakActorId) as $frow) {
              $fa = rtrim((string) ($frow['actor_id'] ?? ''), '/');
              if ($fa !== '') {
                  $followingIdsForLists[$fa] = true;
              }
          }
        ?>
        <?php if ($listNotFound): ?>
          <div class="empty">List not found. <a href="?view=lists">Back to lists</a></div>
        <?php elseif ($listDetail && $listShowTimeline): ?>
          <div style="margin-bottom:.75rem;display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
            <div class="page-back"><a class="btn btn-ghost" href="?view=lists&amp;id=<?= (int) $listDetail['id'] ?>">← Members</a></div>
            <span class="meta">Timeline · <?= h((string) $listDetail['title']) ?></span>
          </div>
          <?php if (!$listTimelineStatuses): ?>
            <div class="empty">No posts from list members in the firehose yet.</div>
          <?php else: ?>
            <?php foreach ($listTimelineStatuses as $st): ?>
              <?php
                // Prefer rendering via event row when we can recover it
                $oid = rtrim((string) ($st['uri'] ?? $st['url'] ?? ''), '/');
                $evRow = null;
                if ($oid !== '' && function_exists('ap_event_by_object_id')) {
                    $evRow = ap_event_by_object_id($oid);
                }
                if (is_array($evRow)) {
                    admin_render_event_tweet($evRow, $followingIdsForLists, 'lists');
                } else {
                    $acct = is_array($st['account'] ?? null) ? $st['account'] : [];
                    $who = (string) ($acct['display_name'] ?? $acct['username'] ?? 'unknown');
                    $handle = (string) ($acct['acct'] ?? '');
                    $content = (string) ($st['content'] ?? '');
                    ?>
                    <article class="tweet">
                      <div class="tweet-hd">
                        <div class="tweet-hd-main">
                          <div>
                            <span class="who"><?= h($who) ?></span>
                            <?php if ($handle !== ''): ?><span class="meta"> @<?= h($handle) ?></span><?php endif; ?>
                          </div>
                          <div class="meta"><?= h((string) ($st['created_at'] ?? '')) ?></div>
                        </div>
                      </div>
                      <div class="body"><?= $content ?></div>
                      <?php if ($oid !== ''): ?>
                        <div class="tweet-actions">
                          <a href="<?= h(admin_status_href($oid, 'lists')) ?>">Open</a>
                        </div>
                      <?php endif; ?>
                    </article>
                    <?php
                }
              ?>
            <?php endforeach; ?>
          <?php endif; ?>

        <?php elseif ($listDetail): ?>
          <div style="margin-bottom:.75rem">
            <div class="page-back"><a class="btn btn-ghost" href="?view=lists">← All lists</a></div>
          </div>
          <form class="composer" method="post" action="?view=lists&amp;id=<?= (int) $listDetail['id'] ?>" style="margin-bottom:1rem">
            <input type="hidden" name="action" value="list_update">
            <input type="hidden" name="list_id" value="<?= (int) $listDetail['id'] ?>">
            <div class="meta" style="margin-bottom:.5rem">Edit list · <?= count($listMembers) ?> members</div>
            <input name="title" type="text" required maxlength="<?= (int) AP_LIST_TITLE_MAX ?>" value="<?= h((string) $listDetail['title']) ?>" placeholder="Title">
            <label class="meta" style="display:block;margin-top:.65rem">Replies in timeline</label>
            <select name="replies_policy" style="margin-top:.25rem;width:100%;max-width:20rem">
              <?php
                $rp = (string) ($listDetail['replies_policy'] ?? 'list');
                foreach (['list' => 'Replies to list members', 'followed' => 'Replies to anyone you follow', 'none' => 'No replies'] as $val => $label):
              ?>
                <option value="<?= h($val) ?>" <?= $rp === $val ? 'selected' : '' ?>><?= h($label) ?></option>
              <?php endforeach; ?>
            </select>
            <label class="composer-check" style="margin-top:.65rem">
              <input type="checkbox" name="exclusive" value="1" <?= !empty($listDetail['exclusive']) ? 'checked' : '' ?>>
              <span>Exclusive (hide these accounts from Home — Ice Cubes / API)</span>
            </label>
            <div class="composer-actions">
              <a class="btn btn-ghost" href="?view=lists&amp;id=<?= (int) $listDetail['id'] ?>&amp;timeline=1">Open timeline</a>
              <button class="btn btn-primary" type="submit">Save</button>
            </div>
          </form>

          <div class="tweet-actions" style="margin:0 0 1rem;gap:.5rem">
            <form method="post" action="?view=lists" style="display:inline" onsubmit="return confirm(<?= json_encode('Delete list “' . (string) ($listDetail['title'] ?? '') . '”?') ?>);">
              <input type="hidden" name="action" value="list_delete">
              <input type="hidden" name="list_id" value="<?= (int) $listDetail['id'] ?>">
              <button class="btn btn-ghost" type="submit" style="color:var(--danger)">Delete list</button>
            </form>
          </div>

          <form class="composer" method="post" action="?view=lists&amp;id=<?= (int) $listDetail['id'] ?>" style="margin-bottom:1rem">
            <input type="hidden" name="action" value="list_add_account">
            <input type="hidden" name="list_id" value="<?= (int) $listDetail['id'] ?>">
            <div class="meta" style="margin-bottom:.5rem">Add account (must be someone you follow)</div>
            <input name="actor" type="text" required placeholder="@user@instance or https://…/users/…">
            <label class="composer-check" style="margin-top:.65rem">
              <input type="checkbox" name="follow_if_needed" value="1">
              <span>Follow first if not already following (uses 30/hour rate limit)</span>
            </label>
            <div class="composer-actions">
              <span class="meta">Private list · not a public Collection</span>
              <button class="btn btn-primary" type="submit">Add</button>
            </div>
          </form>

          <h3 style="font-size:.95rem;color:var(--muted);margin:0 0 .5rem">Members</h3>
          <?php if (!$listMembers): ?>
            <div class="empty">No members yet. Add a followed @user@host above.</div>
          <?php else: ?>
            <?php foreach ($listMembers as $mem): ?>
              <?php $maid = (string) ($mem['actor_id'] ?? ''); ?>
              <article class="tweet">
                <div class="tweet-hd">
                  <?= admin_avatar_img($maid) ?>
                  <div class="tweet-hd-main">
                    <div>
                      <span class="who"><?= actor_display_name_html($maid) ?></span>
                      <span class="meta"> <?= h(actor_handle($maid)) ?></span>
                      <?php if (ap_actor_is_followed($maid)): ?>
                        <span class="tag">following</span>
                      <?php endif; ?>
                    </div>
                  </div>
                </div>
                <div class="tweet-actions">
                  <a href="?view=remote_profile&amp;actor=<?= urlencode($maid) ?>">Profile</a>
                  <form method="post" action="?view=lists&amp;id=<?= (int) $listDetail['id'] ?>" style="display:inline">
                    <input type="hidden" name="action" value="list_remove_account">
                    <input type="hidden" name="list_id" value="<?= (int) $listDetail['id'] ?>">
                    <input type="hidden" name="actor_id" value="<?= h($maid) ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Remove</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>

        <?php else: ?>
          <?php $allLists = ap_lists_all(); ?>
          <div class="meta" style="margin-bottom:.75rem">
            Private lists of people you follow — like Mastodon Lists. Separate from
            <a href="?view=collections">Collections</a> (public starter packs).
            Limit: <?= (int) AP_LIST_MAX ?> lists.
          </div>

          <form class="composer" method="post" action="?view=lists" style="margin-bottom:1rem">
            <input type="hidden" name="action" value="list_create">
            <div class="meta" style="margin-bottom:.5rem">New list</div>
            <input name="title" type="text" required maxlength="<?= (int) AP_LIST_TITLE_MAX ?>" placeholder="Title (e.g. Close friends)">
            <label class="meta" style="display:block;margin-top:.65rem">Replies in timeline</label>
            <select name="replies_policy" style="margin-top:.25rem;width:100%;max-width:20rem">
              <option value="list" selected>Replies to list members</option>
              <option value="followed">Replies to anyone you follow</option>
              <option value="none">No replies</option>
            </select>
            <label class="composer-check" style="margin-top:.65rem">
              <input type="checkbox" name="exclusive" value="1">
              <span>Exclusive (hide members from Home)</span>
            </label>
            <div class="composer-actions">
              <span class="meta"><?= count($allLists) ?> / <?= (int) AP_LIST_MAX ?></span>
              <button class="btn btn-primary" type="submit">Create</button>
            </div>
          </form>

          <h3 style="font-size:.95rem;color:var(--muted);margin:0 0 .5rem">Your lists</h3>
          <?php if (!$allLists): ?>
            <div class="empty">No lists yet. Create one above.</div>
          <?php else: ?>
            <?php foreach ($allLists as $L): ?>
              <article class="tweet">
                <div class="tweet-hd">
                  <div class="tweet-hd-main">
                    <div>
                      <span class="who"><?= h((string) ($L['title'] ?? '')) ?></span>
                      <?php if (!empty($L['exclusive'])): ?>
                        <span class="tag">exclusive</span>
                      <?php endif; ?>
                      <span class="meta"> · <?= h((string) ($L['replies_policy'] ?? 'list')) ?></span>
                    </div>
                    <div class="meta"><?= (int) ($L['member_count'] ?? 0) ?> members · <?= h(relative_time((string) ($L['updated_at'] ?? ''))) ?></div>
                  </div>
                </div>
                <div class="tweet-actions">
                  <a class="btn btn-primary" href="?view=lists&amp;id=<?= (int) $L['id'] ?>" style="padding:.35rem .9rem;font-size:.85rem">Open</a>
                  <a class="btn btn-ghost" href="?view=lists&amp;id=<?= (int) $L['id'] ?>&amp;timeline=1" style="padding:.35rem .9rem;font-size:.85rem">Timeline</a>
                  <form method="post" action="?view=lists" style="display:inline" onsubmit="return confirm(<?= json_encode('Delete list “' . (string) ($L['title'] ?? '') . '”?') ?>);">
                    <input type="hidden" name="action" value="list_delete">
                    <input type="hidden" name="list_id" value="<?= (int) $L['id'] ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem;color:var(--danger)">Delete</button>
                  </form>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
        <?php endif; ?>

      <?php elseif ($view === 'bluesky'): ?>
        <?php
          if (!function_exists('ap_bsky_tab_enabled') || !ap_bsky_tab_enabled()):
        ?>
          <div class="empty">Bluesky tab is disabled on this instance.</div>
        <?php
          else:
            $bskySess = ap_bsky_session_row($vaakOwnerId);
            $bskyFeedKey = trim((string) ($_GET['feed'] ?? 'following'));
            if ($bskyFeedKey === '') {
                $bskyFeedKey = 'following';
            }
            $bskyCursor = trim((string) ($_GET['cursor'] ?? ''));
            if (!is_array($bskySess)):
        ?>
          <div class="empty">
            Connect a Bluesky / PDS account in
            <a href="?view=profile">Profile settings</a>
            to load your Bluesky home timeline here.
            <div class="meta" style="margin-top:.75rem">This is separate from your ActivityPub feeds.</div>
          </div>
        <?php
            else:
              $prefsRaw = ap_bsky_get_preferences($vaakOwnerId);
              $bskyPrefs = ap_bsky_parse_feed_prefs(
                  !empty($prefsRaw['ok']) && is_array($prefsRaw['preferences'] ?? null)
                      ? $prefsRaw['preferences']
                      : []
              );
              // First paint: Following-only (limit 15). Saved-feed merge loads after paint.
              if ($bskyFeedKey === 'following' || $bskyFeedKey === 'timeline') {
                  $tl = ap_bsky_following_feed(
                      $vaakOwnerId,
                      15,
                      $bskyCursor !== '' ? $bskyCursor : null,
                      $bskyPrefs,
                      false
                  );
              } else {
                  $tl = ap_bsky_get_custom_feed($vaakOwnerId, $bskyFeedKey, 15, $bskyCursor !== '' ? $bskyCursor : null);
                  if (!empty($tl['ok']) && is_array($tl['feed'] ?? null)) {
                      $tl['feed'] = ap_bsky_filter_feed_items($tl['feed'], $bskyPrefs);
                      $tl['feed'] = ap_bsky_filter_hidden_authors($vaakOwnerId, $tl['feed']);
                  }
              }
              if (empty($tl['ok'])):
        ?>
          <div class="empty">
            Could not load Bluesky timeline: <?= h((string) ($tl['error'] ?? 'unknown error')) ?>
            <div class="meta" style="margin-top:.75rem">
              Try <a href="?view=profile">reconnecting</a> with a fresh app password.
            </div>
          </div>
        <?php
              else:
                $feed = is_array($tl['feed'] ?? null) ? $tl['feed'] : [];
                $nextCursor = is_string($tl['cursor'] ?? null) ? (string) $tl['cursor'] : '';
                $pinnedTabs = is_array($bskyPrefs['pinned'] ?? null) ? $bskyPrefs['pinned'] : [];
                $mergePending = !empty($tl['merge_pending']);
        ?>
          <div class="meta" style="margin:0 0 .65rem">
            Bluesky for <b>@<?= h((string) ($bskySess['handle'] ?? '')) ?></b>
            ·
            <a href="https://bsky.app/profile/<?= h(rawurlencode((string) ($bskySess['handle'] ?? ''))) ?>" target="_blank" rel="noopener noreferrer">Open profile</a>
          </div>
          <?php if ($pinnedTabs !== []): ?>
            <nav class="bsky-feed-tabs" aria-label="Bluesky feeds">
              <?php foreach ($pinnedTabs as $tab): ?>
                <?php
                  $tType = (string) ($tab['type'] ?? '');
                  $tVal = (string) ($tab['value'] ?? '');
                  $tabKey = ($tType === 'timeline' || $tVal === 'following') ? 'following' : $tVal;
                  $tabLabel = ($tabKey === 'following')
                      ? 'Following'
                      : (str_contains($tVal, '/') ? basename($tVal) : $tVal);
                  $active = ($bskyFeedKey === $tabKey) || ($bskyFeedKey === 'following' && $tabKey === 'following');
                ?>
                <a class="btn <?= $active ? 'btn-primary' : 'btn-ghost' ?>" href="?view=bluesky&amp;feed=<?= h(rawurlencode($tabKey)) ?>"><?= h($tabLabel) ?></a>
              <?php endforeach; ?>
            </nav>
          <?php endif; ?>
          <?php if ($feed === []): ?>
            <div class="empty">
              No posts in this Bluesky feed yet.
              <div class="meta" style="margin-top:.75rem;line-height:1.45">
                Follow people on
                <a href="https://bsky.app" target="_blank" rel="noopener noreferrer">bsky.app</a>
                as <code>@<?= h((string) ($bskySess['handle'] ?? '')) ?></code>, then refresh.
              </div>
            </div>
          <?php else: ?>
            <?php if (($tl['source'] ?? '') === 'author'): ?>
              <div class="meta" style="margin:0 0 .85rem;line-height:1.4">
                Home timeline is empty (no follows yet), so showing <b>your own posts</b> instead.
              </div>
            <?php endif; ?>
            <div id="timeline-items"
                 data-view="bluesky"
                 data-feed="<?= h($bskyFeedKey) ?>"
                 data-source="<?= h((string) ($tl['source'] ?? 'home')) ?>"
                 data-cursor="<?= h($nextCursor) ?>"
                 data-has-more="<?= $nextCursor !== '' ? '1' : '0' ?>"
                 data-limit="20"
                 data-offset="0"
                 data-newest="0"
                 data-merge-pending="<?= !empty($mergePending) ? '1' : '0' ?>">
              <?php foreach ($feed as $item): ?>
                <?php if (is_array($item)) {
                    admin_render_bsky_feed_item($item, $bskyFeedKey);
                } ?>
              <?php endforeach; ?>
            </div>
            <div id="timeline-sentinel" class="meta" style="text-align:center;padding:1rem 0 2rem">
              <?= $nextCursor !== '' ? 'Scroll for more…' : 'End of Bluesky feed' ?>
            </div>
          <?php endif; ?>
        <?php
              endif;
            endif;
          endif;
        ?>

      <?php elseif ($view === 'foryou'): ?>
        <?php
          $suggestions = function_exists('ap_masto_suggestions_v2') ? ap_masto_suggestions_v2(40) : [];
        ?>
        <?php if (!$suggestions): ?>
          <div class="empty">No suggestions yet.</div>
        <?php else: ?>
          <?php foreach ($suggestions as $sug): ?>
            <?php
              $acc = is_array($sug['account'] ?? null) ? $sug['account'] : [];
              $actorUrl = (string) ($acc['uri'] ?? $acc['url'] ?? '');
              $src = (string) ($sug['source'] ?? 'global');
              $srcLabel = $src === 'past_interactions' ? 'From your interactions' : 'Popular on your timeline';
              $display = (string) ($acc['display_name'] ?? $acc['username'] ?? 'account');
              $acct = (string) ($acc['acct'] ?? '');
              $note = trim(html_entity_decode(strip_tags((string) ($acc['note'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
              if (mb_strlen($note) > 160) {
                  $note = mb_substr($note, 0, 157) . '…';
              }
              $av = (string) ($acc['avatar'] ?? '');
            ?>
            <article class="tweet">
              <div class="tweet-hd">
                <?php if ($av !== ''): ?>
                  <img class="tweet-av" src="<?= h($av) ?>" alt="" width="40" height="40" loading="lazy" decoding="async" referrerpolicy="no-referrer">
                <?php elseif ($actorUrl !== ''): ?>
                  <?= admin_avatar_img($actorUrl) ?>
                <?php endif; ?>
                <div class="tweet-hd-main">
                  <div>
                    <span class="who"><?= h($display) ?></span>
                    <?php if ($acct !== ''): ?>
                      <span class="meta"> @<?= h($acct) ?></span>
                    <?php endif; ?>
                    <span class="tag"><?= h($srcLabel) ?></span>
                  </div>
                  <div class="meta">
                    <?= (int) ($acc['followers_count'] ?? 0) ?> followers · <?= (int) ($acc['statuses_count'] ?? 0) ?> posts
                  </div>
                </div>
              </div>
              <?php if ($note !== ''): ?>
                <div class="body" style="margin:.35rem 0"><?= h($note) ?></div>
              <?php endif; ?>
              <div class="tweet-actions">
                <?php if ($actorUrl !== ''): ?>
                  <a href="?view=remote_profile&amp;actor=<?= urlencode($actorUrl) ?>">Profile</a>
                  <form method="post" action="?view=foryou" style="display:inline">
                    <input type="hidden" name="action" value="suggestion_follow">
                    <input type="hidden" name="actor_id" value="<?= h($actorUrl) ?>">
                    <button class="btn btn-primary" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Follow</button>
                  </form>
                  <form method="post" action="?view=foryou" style="display:inline">
                    <input type="hidden" name="action" value="suggestion_dismiss">
                    <input type="hidden" name="actor_id" value="<?= h($actorUrl) ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Dismiss</button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($view === 'search'): ?>
        <?php
          require_once __DIR__ . '/ap-masto-entities.php';
          $sq = trim((string) ($_GET['q'] ?? ''));
          $stype = preg_replace('/[^a-z]/', '', (string) ($_GET['type'] ?? '')) ?: '';
          $sresolve = !isset($_GET['q']) || !empty($_GET['resolve']);
          $sresults = ['accounts' => [], 'hashtags' => [], 'statuses' => []];
          // Post-URL open is handled early (before HTML). Skip text search for those queries.
          $sqIsPostUrl = $sq !== '' && str_starts_with($sq, 'https://')
              && function_exists('ap_masto_url_looks_like_status')
              && ap_masto_url_looks_like_status($sq);
          if ($sq !== '' && !$sqIsPostUrl) {
              if (!defined('AP_INBOX_LIB_ONLY')) {
                  define('AP_INBOX_LIB_ONLY', true);
              }
              require_once __DIR__ . '/ap-inbox.php';
              $sresults = ap_masto_search($sq, $stype !== '' ? $stype : null, $sresolve, 25);
              $sOwner = admin_owner_user_id();
              if (function_exists('ap_actor_is_content_blocked') && $sOwner > 0) {
                  $sresults['accounts'] = array_values(array_filter(
                      is_array($sresults['accounts'] ?? null) ? $sresults['accounts'] : [],
                      static function ($acc) use ($sOwner): bool {
                          if (!is_array($acc)) {
                              return false;
                          }
                          $uri = rtrim((string) ($acc['uri'] ?? $acc['url'] ?? ''), '/');
                          return $uri === '' || !ap_actor_is_content_blocked($uri, null, $sOwner);
                      }
                  ));
                  $sresults['statuses'] = array_values(array_filter(
                      is_array($sresults['statuses'] ?? null) ? $sresults['statuses'] : [],
                      static function ($st) use ($sOwner): bool {
                          if (!is_array($st)) {
                              return false;
                          }
                          $acct = $st['account'] ?? null;
                          $uri = '';
                          if (is_array($acct)) {
                              $uri = rtrim((string) ($acct['uri'] ?? $acct['url'] ?? ''), '/');
                          }
                          return $uri === '' || !ap_actor_is_content_blocked($uri, null, $sOwner);
                      }
                  ));
              }
          }
        ?>
        <form class="composer" method="get" action="?view=search" style="margin:0 0 1rem">
          <input type="hidden" name="view" value="search">
          <input name="q" type="search" value="<?= h($sq) ?>" required
                 placeholder="text · #tag · @user@instance · or https://…/post URL" autofocus>
          <div class="meta" style="margin:.45rem 0 .15rem;line-height:1.4">
            Paste a public post link (Mastodon/Akkoma/etc.) to <b>fetch it into VAAK</b> and open the thread —
            even if it was published before this instance existed.
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center;margin:.6rem 0">
            <label class="meta"><input type="radio" name="type" value="" <?= $stype === '' ? 'checked' : '' ?>> All</label>
            <label class="meta"><input type="radio" name="type" value="accounts" <?= $stype === 'accounts' ? 'checked' : '' ?>> Accounts</label>
            <label class="meta"><input type="radio" name="type" value="hashtags" <?= $stype === 'hashtags' ? 'checked' : '' ?>> Hashtags</label>
            <label class="meta"><input type="radio" name="type" value="statuses" <?= $stype === 'statuses' ? 'checked' : '' ?>> Posts</label>
            <label class="meta"><input type="checkbox" name="resolve" value="1" <?= $sresolve ? 'checked' : '' ?>> Resolve remote</label>
          </div>
          <div class="composer-actions">
            <span class="meta"><a href="?view=tags">Hashtags</a></span>
            <button class="btn btn-primary" type="submit">Search / Open</button>
          </div>
        </form>
        <?php if ($sq === ''): ?>
          <div class="empty">Search posts, tags, or accounts — or paste a remote post URL above.</div>
        <?php else: ?>
          <div class="meta" style="margin-bottom:.75rem">
            Results for <b><?= h($sq) ?></b> —
            <?= count($sresults['accounts']) ?> accounts ·
            <?= count($sresults['hashtags']) ?> tags ·
            <?= count($sresults['statuses']) ?> posts
          </div>
          <?php if ($sresults['accounts']): ?>
            <h3 style="font-size:.95rem;color:var(--muted);margin:1rem 0 .5rem">Accounts</h3>
            <?php foreach ($sresults['accounts'] as $acc): ?>
              <?php
                // Prefer AP actor IRI (uri) for follows; url is often /@user web profile.
                $actorUri = rtrim((string) ($acc['uri'] ?? ''), '/');
                $actorUrl = rtrim((string) ($acc['url'] ?? ''), '/');
                $followTarget = $actorUri !== '' ? $actorUri : $actorUrl;
                $already = $followTarget !== '' && admin_is_following(
                    $followingIds,
                    $followTarget,
                    $actorUrl,
                    $actorUri
                );
                $acctStr = (string) ($acc['acct'] ?? '');
                $isMe = ($followTarget !== '' && vaak_is_own_url($followTarget))
                    || ($actorUri !== '' && vaak_is_own_url($actorUri))
                    || ($actorUrl !== '' && vaak_is_own_url($actorUrl))
                    || ($acctStr !== '' && (
                        strtolower($acctStr) === strtolower($vaakUsername)
                        || strtolower($acctStr) === strtolower($vaakUsername . '@mkultra.monster')
                    ));
                // Mastodon account-id fallback (never treat hard-coded id "1" as self)
                if (!$already && !$isMe && !empty($acc['id'])
                    && function_exists('ap_masto_relationship_for_account_id')) {
                    $rel = ap_masto_relationship_for_account_id((string) $acc['id']);
                    $already = !empty($rel['following']);
                }
              ?>
              <article class="tweet">
                <div class="tweet-hd">
                  <div class="who"><?= admin_emoji_html((string) ($acc['display_name'] ?: $acc['username'] ?? ''), $followTarget !== '' ? $followTarget : null) ?>
                    <span class="meta">@<?= h($acctStr) ?></span>
                    <?php if ($isMe): ?><span class="tag">me</span><?php elseif ($already): ?><?= admin_rel_badge('following') ?><?php endif; ?>
                  </div>
                </div>
                <div class="mono"><?= h($followTarget !== '' ? $followTarget : $actorUrl) ?></div>
                <div class="tweet-actions">
                  <?php if ($isMe): ?>
                    <span class="tag">me</span>
                    <a href="<?= h($vaakActorId) ?>">Your profile</a>
                  <?php elseif ($followTarget !== ''): ?>
                    <a href="?view=remote_profile&amp;actor=<?= urlencode($followTarget) ?>&amp;from=search">Profile</a>
                    <?php if (!$already): ?>
                      <form method="post" action="?view=search" style="display:inline">
                        <input type="hidden" name="action" value="follow_remote">
                        <input type="hidden" name="return_view" value="search">
                        <input type="hidden" name="actor_id" value="<?= h($followTarget) ?>">
                        <button class="btn btn-primary" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Follow</button>
                      </form>
                    <?php else: ?>
                      <span class="tag">following</span>
                    <?php endif; ?>
                    <a href="<?= h($actorUrl !== '' ? $actorUrl : $followTarget) ?>" target="_blank" rel="noopener noreferrer"><?= h(admin_open_profile_label($followTarget !== '' ? $followTarget : $actorUrl)) ?></a>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($sresults['hashtags']): ?>
            <h3 style="font-size:.95rem;color:var(--muted);margin:1rem 0 .5rem">Hashtags</h3>
            <?php foreach ($sresults['hashtags'] as $tag): ?>
              <?php
                $tname = (string) ($tag['name'] ?? '');
                $tfollowing = !empty($tag['following']);
              ?>
              <article class="tweet">
                <div class="tweet-hd">
                  <div class="who">#<?= h($tname) ?></div>
                  <?php if ($tfollowing): ?><span class="tag">following</span><?php endif; ?>
                </div>
                <div class="tweet-actions">
                  <a href="?view=search&amp;q=<?= urlencode('#' . $tname) ?>&amp;type=statuses">View posts</a>
                  <?php if (!$tfollowing): ?>
                    <form method="post" action="?view=tags" style="display:inline">
                      <input type="hidden" name="action" value="follow_tag">
                      <input type="hidden" name="return_view" value="tags">
                      <input type="hidden" name="tag" value="<?= h($tname) ?>">
                      <button class="btn btn-primary" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Follow</button>
                    </form>
                  <?php else: ?>
                    <a class="btn btn-ghost" href="?view=tags" style="padding:.35rem .9rem;font-size:.85rem">Manage</a>
                  <?php endif; ?>
                </div>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if ($sresults['statuses']): ?>
            <h3 style="font-size:.95rem;color:var(--muted);margin:1rem 0 .5rem">Posts</h3>
            <div class="meta" style="margin:0 0 .75rem">Open a post for the full thread · reply / boost / like / quote from the card or detail view</div>
            <?php foreach ($sresults['statuses'] as $st): ?>
              <?php
                if (is_array($st)) {
                    admin_render_masto_status_card($st, $followingIds, 'search', false, true);
                }
              ?>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php if (!$sresults['accounts'] && !$sresults['hashtags'] && !$sresults['statuses']): ?>
            <?php
              $sqHostHint = preg_replace('#^https?://#i', '', ltrim($sq, '@'));
              $sqHostHint = preg_replace('#/.*$#', '', (string) $sqHostHint);
              $looksLikeHost = is_string($sqHostHint)
                && (bool) preg_match('/^[A-Za-z0-9.-]+\.[A-Za-z]{2,}$/', $sqHostHint)
                && !str_contains($sqHostHint, '@');
            ?>
            <div class="empty">
              <?php if ($looksLikeHost): ?>
                No people from <code><?= h(mb_strtolower($sqHostHint)) ?></code> in the local cache yet.
                This isn’t a live instance directory — we only know accounts that already federated
                (follows, mentions, relays). Try a specific person:
                <code>@someone@<?= h(mb_strtolower($sqHostHint)) ?></code> with resolve enabled.
              <?php elseif (str_starts_with($sq, '#')): ?>
                No posts or tags matching <code><?= h($sq) ?></code> in recent federation traffic.
                <a href="?view=tags">Follow hashtags</a> to catch future posts on Home, or try another tag.
              <?php else: ?>
                No matches in the local federation cache.
                For remote people use the full <code>@user@host</code> with resolve enabled
                (example: <code>@gargron@mastodon.social</code>).
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php endif; ?>

      <?php elseif ($view === 'followers'): ?>
        <form class="composer" method="post" action="?view=following" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="follow_remote">
          <input type="hidden" name="return_view" value="following">
          <div class="meta" style="margin-bottom:.5rem">Follow someone as <b style="color:var(--primary)"><?= h($vaakHandle) ?></b></div>
          <input name="actor_id" type="text" required placeholder="@you@instance.example or https://…/users/…">
          <div class="composer-actions">
            <span class="meta">Handle (WebFinger) or actor URL. Wafrn: <code>@valerie@waffles.baeddel.social</code> — not app.wafrn.net.</span>
            <button class="btn btn-primary" type="submit">Follow</button>
          </div>
        </form>
        <?php if (!$followers): ?><div class="empty">No local followers yet.</div><?php endif; ?>
        <?php foreach ($followers as $f): ?>
          <?php
            $fActor = rtrim((string) ($f['actor_id'] ?? ''), '/');
            $fHost = (string) ($f['host'] ?? short_host($fActor));
            $already = isset($followingIds[$fActor]) || isset($followingIds[$fActor . '/'])
                || isset($followingIds[(string) ($f['actor_id'] ?? '')]);
            $fMuted = $fActor !== '' && function_exists('ap_is_muted_actor')
                && ap_is_muted_actor($fActor, $vaakOwnerId);
            $fBlockedMe = $fActor !== '' && function_exists('ap_user_is_blocked')
                && ap_user_is_blocked($fActor, $fHost, $vaakOwnerId);
            $fBlockedSrv = $fActor !== '' && function_exists('ap_is_blocked_actor')
                && ap_is_blocked_actor($fActor);
          ?>
          <article class="tweet">
            <div class="tweet-hd">
              <?= admin_avatar_img($f['actor_id'] ?? null) ?>
              <div class="tweet-hd-main">
                <div class="who"><a href="?view=remote_profile&amp;actor=<?= urlencode((string) $f['actor_id']) ?>" style="color:inherit;text-decoration:none"><?= h(actor_handle($f['actor_id'], $f['username'] ?? null)) ?></a></div>
                <div class="meta"><?= h(relative_time($f['followed_at'])) ?></div>
              </div>
            </div>
            <div class="mono"><?= h($f['actor_id']) ?></div>
            <div class="tags">
              <span class="tag"><?= h($fHost) ?></span>
              <?php if ($already): ?><span class="tag">following</span><?php endif; ?>
              <?php if ($fMuted): ?><span class="tag">muted for me</span><?php endif; ?>
              <?php if ($fBlockedMe): ?><span class="tag" style="color:var(--danger)">blocked for me</span><?php endif; ?>
              <?php if ($fBlockedSrv): ?><span class="tag" style="color:var(--danger)">blocked server-wide</span><?php endif; ?>
            </div>
            <div class="tweet-actions">
              <a href="?view=remote_profile&amp;actor=<?= urlencode((string) $f['actor_id']) ?>">Profile</a>
              <?php if (!$already): ?>
                <form method="post" action="?view=following" style="display:inline">
                  <input type="hidden" name="action" value="follow_remote">
                  <input type="hidden" name="return_view" value="following">
                  <input type="hidden" name="actor_id" value="<?= h($f['actor_id']) ?>">
                  <button class="btn btn-primary" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Follow back</button>
                </form>
              <?php else: ?>
                <form method="post" action="?view=following" style="display:inline" onsubmit="return confirm('Unfollow this account?');">
                  <input type="hidden" name="action" value="unfollow_remote">
                  <input type="hidden" name="actor_id" value="<?= h($f['actor_id']) ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.35rem .9rem;font-size:.85rem">Unfollow</button>
                </form>
              <?php endif; ?>
              <a href="<?= h(admin_remote_actor_href((string) $f['actor_id'], isset($f['username']) ? (string) $f['username'] : null)) ?>" target="_blank" rel="noopener noreferrer"><?= h(admin_open_profile_label((string) ($f['actor_id'] ?? ''))) ?></a>
              <?= block_quick_actions($fActor, $fHost, 'followers', $vaakOwnerId, !empty($vaakIsAdmin), 'followers') ?>
            </div>
          </article>
        <?php endforeach; ?>

      <?php elseif ($view === 'following'): ?>
        <form class="composer" method="post" action="?view=following" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="follow_remote">
          <input type="hidden" name="return_view" value="following">
          <div class="meta" style="margin-bottom:.5rem">Follow someone as <b style="color:var(--primary)"><?= h($vaakHandle) ?></b></div>
          <input name="actor_id" type="text" required placeholder="@you@instance.example or https://…/users/…">
          <div class="composer-actions">
            <span class="meta"><?= count($following) ?> following · handles via WebFinger · rate-limited</span>
            <button class="btn btn-primary" type="submit">Follow</button>
          </div>
        </form>
        <?php if (!$following): ?><div class="empty">Not following anyone yet. Use Follow back on Followers, or paste an actor URL above.</div><?php endif; ?>
        <?php foreach ($following as $f): ?>
          <?php
            $fActor = rtrim((string) ($f['actor_id'] ?? ''), '/');
            $fHost = (string) ($f['host'] ?? short_host($fActor));
            $fMuted = $fActor !== '' && function_exists('ap_is_muted_actor')
                && ap_is_muted_actor($fActor, $vaakOwnerId);
            $fBlockedMe = $fActor !== '' && function_exists('ap_user_is_blocked')
                && ap_user_is_blocked($fActor, $fHost, $vaakOwnerId);
            $fBlockedSrv = $fActor !== '' && function_exists('ap_is_blocked_actor')
                && ap_is_blocked_actor($fActor);
          ?>
          <article class="tweet">
            <div class="tweet-hd">
              <?= admin_avatar_img($f['actor_id'] ?? null) ?>
              <div class="tweet-hd-main">
                <div class="who"><a href="?view=remote_profile&amp;actor=<?= urlencode((string) $f['actor_id']) ?>" style="color:inherit;text-decoration:none"><?= h(actor_handle($f['actor_id'])) ?></a></div>
                <div class="meta"><?= h(relative_time($f['followed_at'])) ?></div>
              </div>
            </div>
            <div class="mono"><?= h($f['actor_id']) ?></div>
            <div class="tags">
              <span class="tag"><?= h($fHost) ?></span>
              <?php if ($fMuted): ?><span class="tag">muted for me</span><?php endif; ?>
              <?php if ($fBlockedMe): ?><span class="tag" style="color:var(--danger)">blocked for me</span><?php endif; ?>
              <?php if ($fBlockedSrv): ?><span class="tag" style="color:var(--danger)">blocked server-wide</span><?php endif; ?>
            </div>
            <div class="tweet-actions">
              <a href="?view=remote_profile&amp;actor=<?= urlencode((string) $f['actor_id']) ?>">Profile</a>
              <a href="<?= h(admin_remote_actor_href((string) $f['actor_id'], isset($f['username']) ? (string) $f['username'] : null)) ?>" target="_blank" rel="noopener noreferrer"><?= h(admin_open_profile_label((string) ($f['actor_id'] ?? ''))) ?></a>
              <form method="post" action="?view=following" style="display:inline" onsubmit="return confirm('Unfollow this account?');">
                <input type="hidden" name="action" value="unfollow_remote">
                <input type="hidden" name="return_view" value="following">
                <input type="hidden" name="actor_id" value="<?= h((string) $f['actor_id']) ?>">
                <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Unfollow</button>
              </form>
              <?= block_quick_actions($fActor, $fHost, 'following', $vaakOwnerId, !empty($vaakIsAdmin), 'following') ?>
            </div>
          </article>
        <?php endforeach; ?>

      <?php elseif ($view === 'status'): ?>
        <?php
          $stObject = trim((string) ($_GET['object'] ?? ''));
          $stFrom = preg_replace('/[^a-z_]/', '', (string) ($_GET['from'] ?? 'home')) ?: 'home';
          $stBackHref = match ($stFrom) {
              'mentions' => '?view=mentions',
              'feed' => '?view=feed',
              'local' => '?view=local',
              'gallery' => '?view=gallery',
              'following' => '?view=following',
              'search' => '?view=search',
              'outbox' => '?view=outbox',
              default => '?view=home',
          };
          $stBackLabel = match ($stFrom) {
              'mentions' => '← Notifications',
              'feed' => '← Federated',
              'local' => '← Local',
              'gallery' => '← Gallery',
              'following' => '← Following',
              'search' => '← Search',
              'outbox' => '← Your posts',
              default => '← Home',
          };
          if (!defined('AP_INBOX_LIB_ONLY')) {
              define('AP_INBOX_LIB_ONLY', true);
          }
          require_once __DIR__ . '/ap-inbox.php';
          require_once __DIR__ . '/ap-masto-entities.php';

          $stEvent = null;
          $stLocalStatus = null;
          $isSyntheticBite = str_contains($stObject, '/bites-received/');
          if ($stObject !== '' && str_starts_with($stObject, 'https://')) {
              // Strip like/reblog/bite interaction fragments so Open hits the real Note.
              if (function_exists('ap_masto_mention_target_object_id')) {
                  $stObject = ap_masto_mention_target_object_id($stObject);
              }
              // Web permalinks (https://host/@user/123) → canonical AS2 id + cache fetch
              if (function_exists('ap_masto_url_looks_like_status')
                  && ap_masto_url_looks_like_status($stObject)
                  && function_exists('ap_event_by_object_id')
                  && !is_array(ap_event_by_object_id($stObject))
                  && function_exists('ap_masto_resolve_pasted_status_url')) {
                  $resolvedSt = ap_masto_resolve_pasted_status_url($stObject);
                  if (is_string($resolvedSt) && str_starts_with($resolvedSt, 'https://')) {
                      $stObject = $resolvedSt;
                  }
              }
              $isSyntheticBite = str_contains($stObject, '/bites-received/');
              try {
                  // Prefer local masto row for any instance note. Authorship comes from
                  // the note URL (not the session). Edit/you badges still use vaak_is_own_url.
                  if (
                      !$isSyntheticBite
                      && vaak_is_local_url($stObject)
                      && function_exists('ap_masto_status_by_note_id')
                  ) {
                      $localRow = ap_masto_status_by_note_id($stObject);
                      if (is_array($localRow)) {
                          $stLocalStatus = ap_masto_status_from_row($localRow, true, true);
                      }
                  }
                  if (!$isSyntheticBite && $stLocalStatus === null) {
                      $stEvent = function_exists('ap_event_by_object_id') ? ap_event_by_object_id($stObject) : null;
                  }
                  $stType = is_array($stEvent) ? strtolower((string) ($stEvent['type'] ?? '')) : '';
                  // Boost/Like rows aren't the Note — fetch/create a Create when needed.
                  // Skip synthetic bite URLs (they aren't AS2 Notes and remote fetch 404s).
                  if (
                      !$isSyntheticBite
                      && $stLocalStatus === null
                      && function_exists('ap_masto_ensure_remote_note_event')
                      && (!is_array($stEvent) || !in_array($stType, ['create', 'update'], true))
                  ) {
                      $ensured = ap_masto_ensure_remote_note_event($stObject);
                      if (is_array($ensured)) {
                          $stEvent = $ensured;
                      }
                  }
              } catch (Throwable $e) {
                  error_log('[ap-admin] status view: ' . $e->getMessage());
              }
          }
          $stStatusId = '';
          $stAncestors = [];
          $stDescendants = [];
          // First paint: cache-only thread walk (no sync AS2). Deeper parents load via AJAX.
          $stCtxOpts = ['allow_fetch' => false, 'max_ancestor_depth' => 20];
          if (is_array($stEvent)) {
              $stEvent['_fetch_reply_parent'] = false;
              $stStatusId = ap_masto_event_status_id(
                  (int) ($stEvent['id'] ?? 0),
                  isset($stEvent['created_at']) ? (string) $stEvent['created_at'] : null
              );
              if ($stStatusId !== '') {
                  $ctx = ap_masto_status_context((int) $stStatusId, $stCtxOpts);
                  $stAncestors = is_array($ctx['ancestors'] ?? null) ? $ctx['ancestors'] : [];
                  $stDescendants = is_array($ctx['descendants'] ?? null) ? $ctx['descendants'] : [];
              }
          } elseif (is_array($stLocalStatus) && !empty($stLocalStatus['id'])) {
              $stStatusId = (string) $stLocalStatus['id'];
              $ctx = ap_masto_status_context((int) $stStatusId, $stCtxOpts);
              $stAncestors = is_array($ctx['ancestors'] ?? null) ? $ctx['ancestors'] : [];
              $stDescendants = is_array($ctx['descendants'] ?? null) ? $ctx['descendants'] : [];
          }
          // Drop thread posts from blocked accounts (mute alone still shows in threads).
          if (function_exists('ap_actor_is_content_blocked')) {
              $stOwnerFilter = admin_owner_user_id();
              $stFilterBlocked = static function (array $cards) use ($stOwnerFilter): array {
                  $out = [];
                  foreach ($cards as $card) {
                      if (!is_array($card)) {
                          continue;
                      }
                      $uri = rtrim((string) (($card['account']['uri'] ?? null) ?: ($card['account']['url'] ?? '')), '/');
                      if ($uri !== '' && ap_actor_is_content_blocked($uri, null, $stOwnerFilter)) {
                          continue;
                      }
                      $out[] = $card;
                  }
                  return $out;
              };
              $stAncestors = $stFilterBlocked($stAncestors);
              $stDescendants = $stFilterBlocked($stDescendants);
          }
          $stMaybeMoreThread = false;
          $stMaybeMoreReplies = false;
          $stMaybeHydrateFocus = false;
          if ($stStatusId !== '') {
              // If this is a reply, deepen the thread via AJAX (may sync-fetch missing parents).
              $stReplyTo = '';
              if (is_array($stEvent)) {
                  $stReplyTo = rtrim((string) ($stEvent['in_reply_to'] ?? ''), '/');
              }
              if ($stReplyTo === '' && $stObject !== '') {
                  try {
                      $ob = ap_db()->prepare('SELECT in_reply_to FROM outbox_notes WHERE id = ? OR id = ? LIMIT 1');
                      $ob->execute([$stObject, rtrim($stObject, '/') . '/']);
                      $stReplyTo = rtrim((string) ($ob->fetchColumn() ?: ''), '/');
                  } catch (Throwable $e) {
                      $stReplyTo = '';
                  }
              }
              if ($stReplyTo === '' && function_exists('ap_event_by_object_id')) {
                  $evTmp = ap_event_by_object_id($stObject);
                  if (is_array($evTmp)) {
                      $stReplyTo = rtrim((string) ($evTmp['in_reply_to'] ?? ''), '/');
                  }
              }
              if ($stReplyTo !== '' && str_starts_with($stReplyTo, 'https://')) {
                  $stMaybeMoreThread = true;
              }
              // No parents in cache yet — still try AJAX: ensuring the focus/source Note
              // often reveals in_reply_to that first paint (cache-only) missed.
              if ($stAncestors === [] && $stObject !== '' && str_starts_with($stObject, 'https://')) {
                  $stMaybeMoreThread = true;
              }
              // Always try remote context for replies (Mastodon-style) unless we already
              // have a healthy local reply set from a refresh.
              $stMaybeMoreReplies = $stObject !== '' && str_starts_with($stObject, 'https://');
          } elseif ($stObject !== '' && str_starts_with($stObject, 'https://') && !$isSyntheticBite) {
              // Focus post itself missing from store — hydrate it asynchronously.
              $stMaybeHydrateFocus = true;
          }
          // Explicit retry from failed AJAX
          if (isset($_GET['refresh_thread']) && (string) $_GET['refresh_thread'] === '1' && $stStatusId !== '') {
              if (function_exists('ap_masto_fetch_remote_context_uris') && $stObject !== '') {
                  try {
                      ap_masto_fetch_remote_context_uris($stObject);
                  } catch (Throwable $e) {
                  }
              }
              $ctx = ap_masto_status_context((int) $stStatusId, [
                  'allow_fetch' => true,
                  'max_ancestor_depth' => 12,
              ]);
              $stAncestors = is_array($ctx['ancestors'] ?? null) ? $ctx['ancestors'] : [];
              $stDescendants = is_array($ctx['descendants'] ?? null) ? $ctx['descendants'] : $stDescendants;
              $stMaybeMoreThread = false;
              $stMaybeMoreReplies = false;
          }
        ?>
        <div class="page-back status-back">
          <a class="btn btn-ghost" href="<?= h($stBackHref) ?>"><?= h($stBackLabel) ?></a>
        </div>
        <?php
          $stAuthor = '';
          if (is_array($stLocalStatus)) {
              $stAuthor = rtrim((string) (($stLocalStatus['account']['uri'] ?? null) ?: ($stLocalStatus['account']['url'] ?? '')), '/');
          } elseif (is_array($stEvent)) {
              $stAuthor = rtrim((string) ($stEvent['actor_id'] ?? ''), '/');
          }
          $stContentBlocked = $stAuthor !== ''
              && function_exists('ap_actor_is_content_blocked')
              && ap_actor_is_content_blocked($stAuthor, null, admin_owner_user_id());
        ?>
        <?php if ($stContentBlocked): ?>
          <div class="empty">
            This post is hidden because you blocked the author
            <?php if (function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($stAuthor)): ?>
              (or they are blocked server-wide)
            <?php endif; ?>.
            <div style="margin-top:.75rem">
              <a href="?view=remote_profile&amp;actor=<?= urlencode($stAuthor) ?>&amp;from=status">Open account</a>
              to unblock.
            </div>
          </div>
        <?php elseif (!is_array($stEvent) && !is_array($stLocalStatus)): ?>
          <?php if (!empty($isSyntheticBite) || str_contains($stObject, '/bites-received/')): ?>
            <div class="empty">No associated post — this was an account bite, not a post bite.</div>
          <?php elseif (!empty($stMaybeHydrateFocus)): ?>
            <div id="status-thread-focus"
                 data-object="<?= h($stObject) ?>"
                 data-from="<?= h($stFrom) ?>"
                 data-need-more="1">
              <div class="meta status-thread-loading" style="padding:1rem 0">Loading this post…</div>
              <div class="meta" style="margin-top:.35rem">
                <a href="<?= h(admin_remote_object_href($stObject)) ?>" target="_blank" rel="noopener noreferrer">Open on remote</a>
                · <a href="?view=status&amp;object=<?= urlencode($stObject) ?>&amp;from=<?= urlencode($stFrom) ?>&amp;refresh_thread=1">Retry</a>
              </div>
            </div>
          <?php else: ?>
            <div class="empty">
              Couldn’t load that post in this instance’s store.
              <?php if ($stObject !== ''): ?>
                <div style="margin-top:.75rem"><a href="<?= h(admin_remote_object_href($stObject)) ?>" target="_blank" rel="noopener noreferrer">Open on remote</a></div>
              <?php endif; ?>
            </div>
          <?php endif; ?>
        <?php else: ?>
          <?php
            // Batch fav/boost/bookmark for the whole thread page
            if (function_exists('ap_masto_status_flags_prefetch')) {
                $stFlagIds = [];
                foreach (array_merge($stAncestors, $stDescendants) as $stCard) {
                    if (is_array($stCard) && !empty($stCard['id'])) {
                        $stFlagIds[] = (string) $stCard['id'];
                    }
                }
                if (is_array($stLocalStatus) && !empty($stLocalStatus['id'])) {
                    $stFlagIds[] = (string) $stLocalStatus['id'];
                } elseif (is_array($stEvent) && function_exists('ap_masto_event_status_id')) {
                    $eid = (int) ($stEvent['id'] ?? 0);
                    if ($eid > 0) {
                        $stFlagIds[] = ap_masto_event_status_id(
                            $eid,
                            isset($stEvent['created_at']) ? (string) $stEvent['created_at'] : null
                        );
                    }
                }
                ap_masto_status_flags_prefetch($stFlagIds);
            }
          ?>
          <div id="status-thread-ancestors" data-object="<?= h($stObject) ?>" data-from="<?= h($stFrom) ?>" data-need-more="<?= !empty($stMaybeMoreThread) ? '1' : '0' ?>">
            <?php if ($stAncestors): ?>
              <div class="meta status-thread-count" style="margin:0 0 1rem"><?= count($stAncestors) ?> earlier in thread</div>
              <?php foreach ($stAncestors as $anc): ?>
                <?php if (is_array($anc)) {
                    admin_render_masto_status_card($anc, $followingIds, $stFrom, false, true);
                } ?>
              <?php endforeach; ?>
            <?php elseif (!empty($stMaybeMoreThread)): ?>
              <div class="meta status-thread-loading" style="margin-bottom:.5rem">Loading earlier posts…</div>
            <?php endif; ?>
          </div>

          <?php
            if (is_array($stLocalStatus)) {
                admin_render_masto_status_card($stLocalStatus, $followingIds, $stFrom, true, false);
            } else {
                $focusStatus = ap_masto_status_from_event($stEvent);
                if (is_array($focusStatus)) {
                    if (function_exists('ap_masto_status_flags_prefetch') && !empty($focusStatus['id'])) {
                        ap_masto_status_flags_prefetch([(string) $focusStatus['id']]);
                    }
                    admin_render_masto_status_card($focusStatus, $followingIds, $stFrom, true, false);
                } else {
                    admin_render_event_tweet($stEvent, $followingIds, $stFrom);
                }
            }
          ?>

          <div id="status-thread-descendants" data-object="<?= h($stObject) ?>" data-from="<?= h($stFrom) ?>" data-need-more="<?= !empty($stMaybeMoreReplies) ? '1' : '0' ?>" data-seen-count="<?= (int) count($stDescendants) ?>">
            <?php if ($stDescendants): ?>
              <div class="meta status-thread-count" style="margin:1rem 0 .5rem"><?= count($stDescendants) ?> replies we’ve seen</div>
              <?php foreach ($stDescendants as $desc): ?>
                <?php if (is_array($desc)) {
                    admin_render_masto_status_card($desc, $followingIds, $stFrom, false, true);
                } ?>
              <?php endforeach; ?>
              <?php if (!empty($stMaybeMoreReplies)): ?>
                <div class="meta status-thread-loading" style="margin-top:.5rem">Looking for more replies…</div>
              <?php endif; ?>
            <?php elseif (!empty($stMaybeMoreReplies)): ?>
              <div class="meta status-thread-loading" style="margin-top:1rem">Loading replies…</div>
            <?php else: ?>
              <div class="empty" style="margin-top:1rem">No replies cached on this instance yet.</div>
            <?php endif; ?>
          </div>
        <?php endif; ?>

      <?php elseif ($view === 'remote_profile'): ?>
        <?php
          $rpFrom = preg_replace('/[^a-z_]/', '', (string) ($_GET['from'] ?? '')) ?: '';
          $rpBackHref = match ($rpFrom) {
              'mentions' => '?view=mentions',
              'search' => '?view=search',
              'followers' => '?view=followers',
              'home' => '?view=home',
              'feed' => '?view=feed',
              'blocks' => '?view=blocks',
              default => '?view=following',
          };
          $rpBackLabel = match ($rpFrom) {
              'mentions' => '← Notifications',
              'search' => '← Search',
              'followers' => '← Followers',
              'home' => '← Home',
              'feed' => '← Federated',
              'blocks' => '← Blocks & mutes',
              default => '← Following',
          };
          $rpActor = trim((string) ($_GET['actor'] ?? ''));
          $rpMeta = null;
          $rpBio = '';
          $rpAvatar = null;
          $rpHeader = null;
          $rpFollowing = false;
          $rpPosts = [];
          $rpOutboxPosts = [];
          $rpIsLocal = false;
          $rpLocalKey = null;
          $rpError = null;
          $rpIsBsky = false;
          $rpBskyDid = '';
          $rpBskyHandle = '';
          $rpBskyFollowing = false;
          $rpBskyFollowsYou = false;
          $rpBskyPosts = [];
          try {
              if (!defined('AP_INBOX_LIB_ONLY')) {
                  define('AP_INBOX_LIB_ONLY', true);
              }
              require_once __DIR__ . '/ap-inbox.php';
              // Web profile URL → actor id (skip Bluesky — handled below via AT Protocol)
              if ($rpActor !== '' && !str_starts_with($rpActor, 'https://bsky.app/')
                  && preg_match('#^(https://[^/]+)/@([^/]+)/?$#', rtrim($rpActor, '/'), $wm)) {
                  $cand = $wm[1] . '/users/' . rawurlencode(rawurldecode($wm[2]));
                  if (ap_remote_actor_get($cand) || (function_exists('ap_fetch_as2_object') && is_array(ap_fetch_as2_object($cand)))) {
                      $rpActor = $cand;
                  } else {
                      $wf = ap_resolve_actor_ref('@' . rawurldecode($wm[2]) . '@' . parse_url($wm[1], PHP_URL_HOST));
                      if (is_string($wf) && $wf !== '') {
                          $rpActor = $wf;
                      }
                  }
              }
              if ($rpActor !== '' && !str_starts_with($rpActor, 'https://')) {
                  $resolved = ap_resolve_actor_ref($rpActor);
                  if ($resolved) {
                      $rpActor = $resolved;
                  }
              }
              $rpActor = rtrim($rpActor, '/');
              $rpLocalKey = null;
              if (preg_match('#^https://mkultra\.monster/users/([A-Za-z0-9_]+)$#', $rpActor, $lm)) {
                  $rpLocalKey = strtolower($lm[1]);
              }
              $rpIsLocal = $rpLocalKey !== null;
              $rpIsBsky = function_exists('ap_bsky_is_profile_ref') && ap_bsky_is_profile_ref($rpActor);
              $rpBskyDid = '';
              $rpBskyHandle = '';
              $rpBskyFollowing = false;
              $rpBskyFollowsYou = false;
              $rpBskyPosts = [];
              $rpOutboxPosts = [];
              $rpMeta = null;
              $rpDoc = null;
              $rpForceRefresh = isset($_GET['refresh']) && (string) $_GET['refresh'] === '1';

              // Bluesky profile: hydrate via AT Protocol (never AP actor fetch / WebFinger).
              if ($rpIsBsky && !$rpIsLocal && function_exists('ap_bsky_get_profile')
                  && function_exists('ap_bsky_session_row') && is_array(ap_bsky_session_row($vaakOwnerId))) {
                  $bskyProf = ap_bsky_get_profile($vaakOwnerId, $rpActor);
                  if (!empty($bskyProf['ok']) && is_array($bskyProf['profile'] ?? null)) {
                      $bp = $bskyProf['profile'];
                      $rpBskyDid = (string) ($bp['did'] ?? '');
                      $rpBskyHandle = (string) ($bp['handle'] ?? '');
                      $rpActor = $rpBskyDid !== ''
                          ? ('https://bsky.app/profile/' . rawurlencode($rpBskyDid))
                          : ($rpBskyHandle !== ''
                              ? ('https://bsky.app/profile/' . rawurlencode($rpBskyHandle))
                              : $rpActor);
                      $rpAvatar = is_string($bp['avatar'] ?? null) ? (string) $bp['avatar'] : null;
                      $rpHeader = is_string($bp['banner'] ?? null) ? (string) $bp['banner'] : $rpAvatar;
                      $rpBio = trim((string) ($bp['description'] ?? ''));
                      $disp = trim((string) ($bp['displayName'] ?? ''));
                      if ($disp === '') {
                          $disp = $rpBskyHandle !== '' ? $rpBskyHandle : 'Bluesky user';
                      }
                      $rpMeta = [
                          'username' => $rpBskyHandle !== '' ? $rpBskyHandle : $rpBskyDid,
                          'display_name' => $disp,
                          'host' => 'bsky.app',
                          'icon_source_url' => $rpAvatar,
                          'image_source_url' => $rpHeader,
                      ];
                      $viewer = is_array($bp['viewer'] ?? null) ? $bp['viewer'] : [];
                      $rpBskyFollowing = !empty($viewer['following']);
                      $rpBskyFollowsYou = !empty($viewer['followedBy']);
                      if ($rpBskyFollowing && $rpBskyDid !== '' && function_exists('ap_bsky_graph_sync_upsert')) {
                          ap_bsky_graph_sync_upsert(
                              $vaakOwnerId,
                              'follow',
                              $rpBskyDid,
                              is_string($viewer['following'] ?? null) ? (string) $viewer['following'] : null,
                              'pull'
                          );
                      }
                      // Prefer durable cache; fill-through via XRPC when thin/stale.
                      if ($rpBskyDid !== '' && function_exists('ap_bsky_posts_for_author')) {
                          $rpBskyPosts = ap_bsky_posts_for_author($rpBskyDid, 20);
                      }
                      $needAuthorFetch = $rpForceRefresh || count($rpBskyPosts) < 5;
                      if ($needAuthorFetch && $rpBskyDid !== '') {
                          $tok = ap_bsky_access_token($vaakOwnerId, false);
                          if (empty($tok['ok'])) {
                              $tok = ap_bsky_access_token($vaakOwnerId, true);
                          }
                          if (!empty($tok['ok'])) {
                              $sess = ap_bsky_session_row($vaakOwnerId);
                              $hosts = ap_bsky_feed_hosts(rtrim((string) ($sess['pds_host'] ?? AP_BSKY_DEFAULT_PDS), '/'));
                              foreach ($hosts as $host) {
                                  $af = ap_bsky_xrpc(
                                      $host,
                                      'app.bsky.feed.getAuthorFeed',
                                      'GET',
                                      ['actor' => $rpBskyDid, 'limit' => 20],
                                      null,
                                      (string) $tok['access'],
                                      12
                                  );
                                  if (!empty($af['ok']) && is_array($af['json']['feed'] ?? null)) {
                                      $rpBskyPosts = $af['json']['feed'];
                                      if (function_exists('ap_bsky_index_feed_items')) {
                                          ap_bsky_index_feed_items($rpBskyPosts, $vaakOwnerId, 20);
                                      }
                                      break;
                                  }
                              }
                          }
                      }
                  } else {
                      $rpError = 'Could not load Bluesky profile: ' . (string) ($bskyProf['error'] ?? 'unknown error');
                  }
              } elseif ($rpIsBsky && !$rpIsLocal) {
                  $rpError = 'Connect a Bluesky account in Profile settings to view and follow Bluesky profiles here.';
              }

              if ($rpIsLocal) {
                  // Local peers: actor_profile is authoritative (skip remote_actors / R2 / AS2).
                  $prof = function_exists('ap_profile_get') ? ap_profile_get($rpLocalKey) : [];
                  $iconRaw = $prof['icon_url'] ?? null;
                  $imageRaw = $prof['image_url'] ?? null;
                  $rpAvatar = function_exists('ap_profile_sanitize_https_url')
                      ? ap_profile_sanitize_https_url($iconRaw)
                      : (is_string($iconRaw) && str_starts_with($iconRaw, 'https://') ? $iconRaw : null);
                  $rpHeader = function_exists('ap_profile_sanitize_https_url')
                      ? ap_profile_sanitize_https_url($imageRaw)
                      : (is_string($imageRaw) && str_starts_with($imageRaw, 'https://') ? $imageRaw : null);
                  if (!$rpHeader) {
                      $rpHeader = $rpAvatar;
                  }
                  $sum = (string) ($prof['summary'] ?? '');
                  if ($sum !== '') {
                      $rpBio = function_exists('ap_html_to_plain_text')
                          ? ap_html_to_plain_text($sum)
                          : trim(html_entity_decode(strip_tags($sum), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                  }
                  $disp = function_exists('ap_profile_display_name')
                      ? ap_profile_display_name($rpLocalKey, true)
                      : trim((string) ($prof['name'] ?? $rpLocalKey));
                  if ($disp === '') {
                      $disp = $rpLocalKey;
                  }
                  $rpMeta = [
                      'username' => $rpLocalKey,
                      'display_name' => $disp,
                      'host' => 'mkultra.monster',
                      'icon_source_url' => $rpAvatar,
                      'image_source_url' => $rpHeader,
                  ];
                  try {
                      $stOb = ap_db()->prepare(
                          "SELECT * FROM outbox_notes
                           WHERE id LIKE ?
                           ORDER BY published DESC LIMIT 30"
                      );
                      $stOb->execute(['https://mkultra.monster/users/' . $rpLocalKey . '/%']);
                      $rpOutboxPosts = $stOb->fetchAll() ?: [];
                  } catch (Throwable $e) {
                      error_log('[ap-admin] remote_profile local outbox: ' . $e->getMessage());
                      $rpOutboxPosts = [];
                  }
                  if (!$rpOutboxPosts) {
                      $ors = ['actor_id = ? OR actor_id = ?'];
                      $bind = [$rpActor, $rpActor . '/'];
                      $postsSql = "SELECT * FROM events WHERE type IN ('Create','Announce') AND ("
                           . implode(' OR ', $ors) . ")
                           AND (
                             (summary IS NOT NULL AND summary != '')
                             OR (media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]')
                             OR (spoiler_text IS NOT NULL AND spoiler_text != '')
                             OR (sensitive IS NOT NULL AND sensitive != 0)
                           )
                           ORDER BY created_at DESC, id DESC LIMIT 30";
                      if (function_exists('ap_db_execute_retry')) {
                          $st = ap_db_execute_retry($postsSql, $bind);
                          $rpPosts = $st ? $st->fetchAll() : [];
                      } else {
                          $st = $db->prepare($postsSql);
                          $st->execute($bind);
                          $rpPosts = $st->fetchAll();
                      }
                  }
              } elseif (!$rpIsBsky) {
                  $rpMeta = $rpActor !== '' ? ap_remote_actor_get($rpActor) : null;
                  // Cache-first: only sync-fetch AS2 on miss or explicit Refresh (was every open).
                  $rpNeedFetch = $rpActor !== ''
                      && function_exists('ap_fetch_as2_object')
                      && ($rpForceRefresh || !is_array($rpMeta) || empty($rpMeta['username']));
                  if ($rpNeedFetch) {
                      $rpDoc = ap_fetch_as2_object($rpActor);
                      if (is_array($rpDoc)) {
                          // Cache/refresh basic fields
                          $uname = null;
                          if (!empty($rpDoc['preferredUsername']) && is_string($rpDoc['preferredUsername'])) {
                              $uname = $rpDoc['preferredUsername'];
                          }
                          $dname = null;
                          if (!empty($rpDoc['name']) && is_string($rpDoc['name'])) {
                              $dname = $rpDoc['name'];
                          }
                          $icon = null;
                          if (isset($rpDoc['icon'])) {
                              if (is_array($rpDoc['icon']) && !empty($rpDoc['icon']['url'])) {
                                  $icon = (string) $rpDoc['icon']['url'];
                              } elseif (is_string($rpDoc['icon'])) {
                                  $icon = $rpDoc['icon'];
                              }
                          }
                          $image = null;
                          if (isset($rpDoc['image'])) {
                              if (is_array($rpDoc['image']) && !empty($rpDoc['image']['url'])) {
                                  $image = (string) $rpDoc['image']['url'];
                              } elseif (is_string($rpDoc['image'])) {
                                  $image = $rpDoc['image'];
                              }
                          }
                          try {
                              ap_remote_actor_upsert($rpActor, [
                                  'username' => $uname,
                                  'display_name' => $dname,
                                  'host' => parse_url($rpActor, PHP_URL_HOST) ?: null,
                                  'icon_source_url' => $icon,
                                  'image_source_url' => $image,
                              ]);
                              if (function_exists('ap_remote_emoji_ingest_actor_doc')) {
                                  ap_remote_emoji_ingest_actor_doc($rpActor, $rpDoc);
                              }
                              $rpMeta = ap_remote_actor_get($rpActor) ?: $rpMeta;
                          } catch (Throwable $e) {
                              error_log('[ap-admin] remote_profile upsert: ' . $e->getMessage());
                          }
                          if (!empty($rpDoc['summary']) && is_string($rpDoc['summary'])) {
                              $rpBio = function_exists('ap_html_to_plain_text')
                                  ? ap_html_to_plain_text($rpDoc['summary'])
                                  : trim(html_entity_decode(strip_tags($rpDoc['summary']), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
                          }
                      }
                  }
                  if ($rpActor !== '' && function_exists('ap_remote_media_get')) {
                      $ca = ap_remote_media_get($rpActor, 'avatar');
                      $ch = ap_remote_media_get($rpActor, 'header');
                      $rpAvatar = (is_array($ca) ? ($ca['public_url'] ?? null) : null)
                          ?: (is_array($rpMeta) ? ($rpMeta['icon_source_url'] ?? null) : null);
                      $rpHeader = (is_array($ch) ? ($ch['public_url'] ?? null) : null)
                          ?: (is_array($rpMeta) ? ($rpMeta['image_source_url'] ?? null) : null)
                          ?: $rpAvatar;
                      // A profile view is an explicit signal that this
                      // account matters to the user. Warm both media slots
                      // asynchronously so later Notifications/timelines can
                      // use the cached avatar instead of the default.
                      if ((!$ca || !$ch) && function_exists('ap_remote_media_warm_async')) {
                          ap_remote_media_warm_async($rpActor);
                      }
                  }
                  if ($rpActor !== '') {
                      // Mastodon: /users/name vs /ap/users/{id} — posts often live under only one IRI
                      $rpAliases = function_exists('ap_masto_actor_id_aliases')
                          ? ap_masto_actor_id_aliases($rpActor)
                          : [$rpActor, $rpActor . '/'];
                      if ($rpAliases !== []) {
                          $rpActor = rtrim((string) $rpAliases[0], '/');
                      }
                      $ors = [];
                      $bind = [];
                      foreach ($rpAliases as $a) {
                          $a = rtrim((string) $a, '/');
                          if ($a === '') {
                              continue;
                          }
                          $ors[] = 'actor_id = ? OR actor_id = ?';
                          $bind[] = $a;
                          $bind[] = $a . '/';
                      }
                      $postsSql = "SELECT * FROM events WHERE type IN ('Create','Announce') AND ("
                           . ($ors ? implode(' OR ', $ors) : '0') . ")
                           AND (
                             (summary IS NOT NULL AND summary != '')
                             OR (media_urls IS NOT NULL AND media_urls != '' AND media_urls != '[]')
                             OR (spoiler_text IS NOT NULL AND spoiler_text != '')
                             OR (sensitive IS NOT NULL AND sensitive != 0)
                           )
                           ORDER BY created_at DESC, id DESC LIMIT 30";
                      if ($ors) {
                          if (function_exists('ap_db_execute_retry')) {
                              $st = ap_db_execute_retry($postsSql, $bind);
                              $rpPosts = $st ? $st->fetchAll() : [];
                          } else {
                              $st = $db->prepare($postsSql);
                              $st->execute($bind);
                              $rpPosts = $st->fetchAll();
                          }
                      }
                      // Also bridgy-style: if viewing the gateway actor, show host posts
                      $rpHost = parse_url($rpActor, PHP_URL_HOST);
                      $rpPath = trim((string) (parse_url($rpActor, PHP_URL_PATH) ?? ''), '/');
                      if (is_string($rpHost) && ($rpPath === '' || $rpPath === $rpHost || $rpPath === 'actor')) {
                          $hostSql = "SELECT * FROM events WHERE type = 'Create' AND actor_id LIKE ? AND summary IS NOT NULL AND summary != ''
                               ORDER BY id DESC LIMIT 30";
                          $like = ['https://' . strtolower($rpHost) . '/%'];
                          if (function_exists('ap_db_execute_retry')) {
                              $st = ap_db_execute_retry($hostSql, $like);
                              $rpPosts = $st ? $st->fetchAll() : [];
                          } else {
                              $st = $db->prepare($hostSql);
                              $st->execute($like);
                              $rpPosts = $st->fetchAll();
                          }
                      }
                  }
              }
              $rpFollowing = $rpActor !== '' && admin_is_following($followingIds, $rpActor);
          } catch (Throwable $e) {
              error_log('[ap-admin] remote_profile: ' . $e->getMessage());
              $rpError = $e->getMessage();
          }
        ?>
        <?php if ($rpError): ?>
          <div class="empty" style="color:var(--danger)">Couldn’t load this profile (database busy). Retry shortly.</div>
          <?php if ($rpActor !== '' && str_starts_with($rpActor, 'https://')): ?>
            <div class="page-back">
              <a class="btn btn-ghost" href="<?= h($rpBackHref) ?>"><?= h($rpBackLabel) ?></a>
              <a class="btn btn-ghost" href="<?= h(admin_remote_actor_href($rpActor)) ?>" target="_blank" rel="noopener noreferrer"><?= h(admin_open_profile_label($rpActor)) ?></a>
            </div>
          <?php endif; ?>
        <?php elseif ($rpActor === '' || !str_starts_with($rpActor, 'https://')): ?>
          <div class="empty">No actor URL.</div>
        <?php else: ?>
          <div class="page-back">
            <a class="btn btn-ghost" href="<?= h($rpBackHref) ?>"><?= h($rpBackLabel) ?></a>
            <?php if ($rpIsLocal): ?>
              <a class="btn btn-ghost" href="?view=remote_profile&amp;actor=<?= urlencode($rpActor) ?>&amp;from=<?= urlencode($rpFrom) ?>" title="Reload local profile">↻ Refresh</a>
            <?php else: ?>
              <a class="btn btn-ghost" href="?view=remote_profile&amp;actor=<?= urlencode($rpActor) ?>&amp;from=<?= urlencode($rpFrom) ?>&amp;refresh=1" title="Re-fetch profile from remote">↻ Refresh profile</a>
            <?php endif; ?>
          </div>
          <?php
            $rpRel = $rpIsBsky
                ? ($rpBskyFollowing
                    ? ($rpBskyFollowsYou ? 'mutual' : 'following')
                    : ($rpBskyFollowsYou ? 'follows_you' : 'none'))
                : admin_rel_state($followingIds, $followerIds, $rpActor);
            $rpFollowing = $rpRel === 'mutual' || $rpRel === 'following';
            $rpOwnerId = admin_owner_user_id();
            $rpMuted = !$rpIsBsky && function_exists('ap_is_muted_actor') && ap_is_muted_actor($rpActor, $rpOwnerId);
            $rpBlockedPersonal = !$rpIsBsky && function_exists('ap_user_is_blocked')
                && ap_user_is_blocked($rpActor, short_host($rpActor), $rpOwnerId);
            $rpBlockedServer = !$rpIsBsky && function_exists('ap_is_blocked_actor') && ap_is_blocked_actor($rpActor);
            $rpContentBlocked = !$rpIsBsky && function_exists('ap_actor_is_content_blocked')
                && ap_actor_is_content_blocked($rpActor, short_host($rpActor), $rpOwnerId);
            $rpIsOwn = $rpIsBsky
                ? (function_exists('ap_bsky_session_row') && is_array($sess = ap_bsky_session_row($vaakOwnerId))
                    && (($sess['did'] ?? '') === $rpBskyDid || ($sess['handle'] ?? '') === $rpBskyHandle))
                : (function_exists('vaak_is_own_url') && vaak_is_own_url($rpActor));
            $rpPostSub = $rpIsLocal && !$rpIsOwn
                && function_exists('ap_post_subscription_is')
                && ap_post_subscription_is($rpActor, admin_owner_user_id());
          ?>
          <?php if ($rpContentBlocked): ?>
          <article class="tweet">
            <div class="tweet-hd" style="align-items:center;gap:.75rem">
              <?= admin_avatar_img($rpActor) ?>
              <div>
                <div class="who"><?= h(actor_handle($rpActor)) ?></div>
                <div class="meta"><?= h(actor_handle($rpActor)) ?></div>
              </div>
            </div>
            <div class="tweet-actions" style="flex-wrap:wrap;align-items:center;margin-top:.75rem">
              <?php if ($rpBlockedPersonal): ?><span class="tag" style="color:var(--danger)">blocked for me</span><?php endif; ?>
              <?php if ($rpBlockedServer): ?><span class="tag" style="color:var(--danger)">blocked server-wide</span><?php endif; ?>
              <?= block_quick_actions($rpActor, short_host($rpActor), 'remote_profile', $vaakOwnerId, !empty($vaakIsAdmin), $rpFrom) ?>
            </div>
            <div class="empty" style="margin-top:1rem">
              <?php if ($rpBlockedServer && !$rpBlockedPersonal): ?>
                This account is <b>blocked server-wide</b>. Their profile and posts are hidden for everyone on VAAK.
              <?php else: ?>
                You blocked this account. Their profile and posts are hidden for you.
                Use ⋯ → Unblock for me if you want to see them again.
              <?php endif; ?>
            </div>
          </article>
          <?php else: ?>
          <?php if ($rpHeader): ?>
            <div style="height:120px;border-radius:12px;overflow:hidden;margin-bottom:.75rem;background:#111">
              <img src="<?= h((string) $rpHeader) ?>" alt="" style="width:100%;height:100%;object-fit:cover" loading="lazy" referrerpolicy="no-referrer">
            </div>
          <?php endif; ?>
          <article class="tweet">
            <div class="tweet-hd" style="align-items:center;gap:.75rem">
              <?php if ($rpAvatar): ?>
                <?php
                  $rpAvFallback = defined('AP_REMOTE_AVATAR_FALLBACK')
                      ? AP_REMOTE_AVATAR_FALLBACK
                      : 'https://mkultra.monster/img/avatar/default.jpg';
                ?>
                <img src="<?= h((string) $rpAvatar) ?>" alt="" width="64" height="64" style="border-radius:50%;object-fit:cover" loading="lazy" referrerpolicy="no-referrer"
                     onerror="this.onerror=null;this.src='<?= h($rpAvFallback) ?>'">
              <?php else: ?>
                <?= admin_avatar_img($rpActor) ?>
              <?php endif; ?>
              <div>
                <div class="who"><?= admin_emoji_html((string) ((is_array($rpMeta) ? ($rpMeta['display_name'] ?? null) : null) ?: actor_handle($rpActor)), $rpActor) ?></div>
                <div class="meta"><?= h($rpIsBsky && $rpBskyHandle !== '' ? ('@' . $rpBskyHandle) : actor_handle($rpActor, is_array($rpMeta) ? ($rpMeta['username'] ?? null) : null)) ?>
                  <?php if ($rpIsLocal): ?><span class="tag" style="margin-left:.35rem" title="Account on this instance">local</span><?php endif; ?>
                  <?php if ($rpIsBsky): ?><span class="tag" style="margin-left:.35rem" title="Bluesky / AT Protocol">Bluesky</span><?php endif; ?>
                  <?php if ($rpIsOwn): ?><span class="tag" style="margin-left:.35rem" title="Signed-in account">you</span><?php endif; ?>
                </div>
                <?php if ($rpIsOwn): ?>
                  <div class="meta" style="margin-top:.25rem">This is you · <a href="?view=profile">Edit profile</a></div>
                <?php endif; ?>
              </div>
            </div>
            <?php if ($rpBio !== ''): ?>
              <div class="body" style="margin-top:.75rem;white-space:pre-wrap"><?= h($rpBio) ?></div>
            <?php elseif ($rpIsLocal): ?>
              <div class="meta" style="margin-top:.75rem">No bio set.</div>
            <?php else: ?>
              <div class="meta" style="margin-top:.75rem">No bio available from this instance’s cache / remote fetch.</div>
            <?php endif; ?>
            <div class="mono" style="margin-top:.5rem"><?= h($rpActor) ?></div>
            <div class="tweet-actions" style="flex-wrap:wrap;align-items:center">
              <?php if ($rpRel !== 'none'): ?>
                <?= admin_rel_badge($rpRel) ?>
              <?php else: ?>
                <span class="meta">not following</span>
              <?php endif; ?>
              <?php if ($rpMuted): ?><span class="tag" title="Hidden from your timelines &amp; notifications">muted for me</span><?php endif; ?>
              <?php if ($rpBlockedPersonal): ?><span class="tag" style="color:var(--danger)" title="Personal block — hidden from your timelines only">blocked for me</span><?php endif; ?>
              <?php if ($rpBlockedServer): ?><span class="tag" style="color:var(--danger)" title="Server-wide block">blocked server-wide</span><?php endif; ?>
              <?php if (!$rpIsOwn): ?>
                <?php if ($rpFollowing): ?>
                  <form method="post" action="?view=remote_profile&amp;actor=<?= urlencode($rpActor) ?>&amp;from=<?= urlencode($rpFrom) ?>" style="display:inline" onsubmit="return confirm('Unfollow this account?');">
                    <input type="hidden" name="action" value="unfollow_remote">
                    <input type="hidden" name="return_view" value="remote_profile">
                    <input type="hidden" name="return_actor" value="<?= h($rpActor) ?>">
                    <input type="hidden" name="return_from" value="<?= h($rpFrom) ?>">
                    <input type="hidden" name="actor_id" value="<?= h($rpActor) ?>">
                    <button class="btn btn-ghost" type="submit"><?= $rpIsBsky ? 'Unfollow on Bluesky' : 'Unfollow' ?></button>
                  </form>
                <?php else: ?>
                  <form method="post" action="?view=remote_profile&amp;actor=<?= urlencode($rpActor) ?>&amp;from=<?= urlencode($rpFrom) ?>" style="display:inline">
                    <input type="hidden" name="action" value="follow_remote">
                    <input type="hidden" name="return_view" value="remote_profile">
                    <input type="hidden" name="return_actor" value="<?= h($rpActor) ?>">
                    <input type="hidden" name="return_from" value="<?= h($rpFrom) ?>">
                    <input type="hidden" name="actor_id" value="<?= h($rpActor) ?>">
                    <button class="btn btn-primary" type="submit"><?= $rpIsBsky
                        ? ($rpRel === 'follows_you' ? 'Follow back on Bluesky' : 'Follow on Bluesky')
                        : ($rpRel === 'follows_you' ? 'Follow back' : 'Follow') ?></button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
              <?php if ($rpIsLocal && !$rpIsOwn): ?>
                <?php if ($rpPostSub): ?>
                  <form method="post" action="?view=remote_profile&amp;actor=<?= urlencode($rpActor) ?>&amp;from=<?= urlencode($rpFrom) ?>" style="display:inline">
                    <input type="hidden" name="action" value="unsubscribe_posts">
                    <input type="hidden" name="return_view" value="remote_profile">
                    <input type="hidden" name="return_actor" value="<?= h($rpActor) ?>">
                    <input type="hidden" name="return_from" value="<?= h($rpFrom) ?>">
                    <input type="hidden" name="actor_id" value="<?= h($rpActor) ?>">
                    <button class="btn btn-ghost" type="submit" title="Stop notifications for their posts">Stop notifying</button>
                  </form>
                <?php else: ?>
                  <form method="post" action="?view=remote_profile&amp;actor=<?= urlencode($rpActor) ?>&amp;from=<?= urlencode($rpFrom) ?>" style="display:inline">
                    <input type="hidden" name="action" value="subscribe_posts">
                    <input type="hidden" name="return_view" value="remote_profile">
                    <input type="hidden" name="return_actor" value="<?= h($rpActor) ?>">
                    <input type="hidden" name="return_from" value="<?= h($rpFrom) ?>">
                    <input type="hidden" name="actor_id" value="<?= h($rpActor) ?>">
                    <button class="btn btn-ghost" type="submit" title="Get a notification when they post">Notify me of posts</button>
                  </form>
                <?php endif; ?>
              <?php endif; ?>
              <?php if (!$rpIsBsky): ?>
                <form method="post" action="?view=remote_profile&amp;actor=<?= urlencode($rpActor) ?>&amp;from=<?= urlencode($rpFrom) ?>" style="display:inline" onsubmit="return confirm('Bite this account? (Wafrn-compatible 🦷)');">
                  <input type="hidden" name="action" value="bite_remote">
                  <input type="hidden" name="return_view" value="remote_profile">
                  <input type="hidden" name="return_actor" value="<?= h($rpActor) ?>">
                  <input type="hidden" name="return_from" value="<?= h($rpFrom) ?>">
                  <input type="hidden" name="bite_kind" value="user">
                  <input type="hidden" name="target" value="<?= h($rpActor) ?>">
                  <button class="btn btn-ghost" type="submit" title="Wafrn-compatible bite"><i class="ph ph-tooth" aria-hidden="true"></i> Bite</button>
                </form>
                <?= block_quick_actions($rpActor, short_host($rpActor), 'remote_profile', $vaakOwnerId, !empty($vaakIsAdmin), $rpFrom) ?>
              <?php endif; ?>
              <a href="<?= h($rpIsBsky ? $rpActor : admin_remote_actor_href($rpActor)) ?>" target="_blank" rel="noopener noreferrer"><?= $rpIsBsky ? 'Open on Bluesky' : h(admin_open_profile_label($rpActor)) ?></a>
            </div>
          </article>
          <h2 style="font-size:1rem;margin:1.25rem 0 .5rem"><?= $rpIsBsky ? 'Recent Bluesky posts' : ($rpIsLocal ? 'Recent posts' : 'Recent posts we’ve seen on this instance') ?></h2>
          <?php if ($rpIsBsky): ?>
            <?php if ($rpBskyPosts === []): ?>
              <div class="empty">No posts loaded from Bluesky yet.</div>
            <?php else: ?>
              <?php foreach ($rpBskyPosts as $bItem): ?>
                <?php if (is_array($bItem)) {
                    admin_render_bsky_feed_item($bItem, 'following');
                } ?>
              <?php endforeach; ?>
            <?php endif; ?>
          <?php elseif ($rpOutboxPosts): ?>
            <?php foreach ($rpOutboxPosts as $n): ?>
              <?php admin_render_outbox_card($n, 'remote_profile'); ?>
            <?php endforeach; ?>
          <?php elseif (!$rpPosts): ?>
            <div class="empty"><?= $rpIsLocal ? 'No local outbox posts yet.' : 'No Create/Announce activity from this actor in the local firehose yet.' ?></div>
          <?php else: ?>
            <?php foreach ($rpPosts as $p): ?>
              <?php
                $pSum = (string) ($p['summary'] ?? '');
                if (function_exists('ap_plain_unglue_mentions')) {
                    $pSum = ap_plain_unglue_mentions($pSum);
                }
                $pObj = (string) ($p['object_id'] ?? '');
              ?>
              <article class="tweet">
                <div class="tweet-hd">
                  <div class="who"><?= h((string) ($p['type'] ?? 'Create')) ?></div>
                  <div class="meta"><?= h(relative_time((string) $p['created_at'])) ?></div>
                </div>
                <div class="body" style="white-space:pre-wrap"><?= h($pSum) ?></div>
                <?php if ($pObj !== ''): ?>
                  <div class="tweet-actions">
                    <a href="<?= h(admin_status_href($pObj, 'remote_profile')) ?>">Open</a>
                    <a href="<?= h(admin_remote_object_href($pObj)) ?>" target="_blank" rel="noopener noreferrer" class="meta">Remote</a>
                  </div>
                <?php endif; ?>
              </article>
            <?php endforeach; ?>
          <?php endif; ?>
          <?php endif; /* !rpContentBlocked */ ?>
        <?php endif; ?>

      <?php elseif ($view === 'queue'): ?>
        <?php
          $qSettings = ap_queue_settings_get();
          $qPending = ap_queue_list_pending(100);
          $qPublished = ap_queue_list_published(15);
        ?>
        <form class="composer" method="post" action="?view=queue" style="margin-bottom:1rem">
          <input type="hidden" name="action" value="queue_settings">
          <div class="meta" style="margin-bottom:.5rem">
            Daily posting window (<b style="color:var(--primary)"><?= h($qSettings['timezone']) ?></b>).
            Pending posts fill evenly spaced slots from start→end, then spill into following days.
          </div>
          <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:flex-end;margin-bottom:.5rem">
            <label class="meta" style="display:flex;flex-direction:column;gap:.25rem">
              Start
              <input type="time" name="window_start" value="<?= h($qSettings['window_start']) ?>" required style="width:auto">
            </label>
            <label class="meta" style="display:flex;flex-direction:column;gap:.25rem">
              End
              <input type="time" name="window_end" value="<?= h($qSettings['window_end']) ?>" required style="width:auto">
            </label>
            <label class="meta" style="display:flex;flex-direction:column;gap:.25rem">
              Min gap (minutes)
              <input type="number" name="min_gap_minutes" min="5" max="720" value="<?= (int) $qSettings['min_gap_minutes'] ?>" style="width:6rem">
            </label>
            <input type="hidden" name="timezone" value="<?= h($qSettings['timezone']) ?>">
            <label class="composer-check" style="margin:0">
              <input type="checkbox" name="enabled" value="1"<?= $qSettings['enabled'] ? ' checked' : '' ?>>
              <span>Queue enabled</span>
            </label>
          </div>
          <div class="composer-actions">
            <span class="meta"><?= count($qPending) ?> upcoming</span>
            <button class="btn btn-primary" type="submit">Save window</button>
          </div>
        </form>

        <?php if (!$qSettings['enabled']): ?>
          <div class="flash err" style="margin:0 0 1rem">Queue is disabled — cron will not publish until you enable it.</div>
        <?php endif; ?>

        <h3 style="font-size:.95rem;color:var(--muted);margin:0 0 .5rem">Upcoming</h3>
        <?php if (!$qPending): ?>
          <div class="empty">Nothing queued. Use ＋ Compose → <b>Add to queue</b>.</div>
        <?php else: ?>
          <?php foreach ($qPending as $qi): ?>
            <?php
              $qid = (int) ($qi['id'] ?? 0);
              $qstate = (string) ($qi['state'] ?? 'pending');
              $qplain = trim((string) ($qi['content'] ?? ''));
              $qwhen = ap_queue_format_local(isset($qi['scheduled_at']) ? (string) $qi['scheduled_at'] : null, $qSettings);
              $qmedia = json_decode((string) ($qi['media_ids_json'] ?? '[]'), true);
              if (!is_array($qmedia)) {
                  $qmedia = [];
              }
              $qmediaN = count($qmedia);
              $qVis = function_exists('ap_normalize_visibility')
                  ? ap_normalize_visibility($qi['visibility'] ?? 'public')
                  : 'public';
              $qVisLabel = $qVis === 'unlisted' ? 'silent' : ($qVis === 'private' ? 'followers' : 'public');
            ?>
            <article class="tweet">
              <div class="tweet-hd">
                <div class="tweet-hd-main">
                  <div class="who">
                    <?php if ($qwhen !== ''): ?><?= h($qwhen) ?><?php else: ?>Unscheduled<?php endif; ?>
                    <?php if ($qstate === 'failed'): ?><span class="tag" style="margin-left:.35rem">failed</span><?php endif; ?>
                    <?php if ($qstate === 'publishing'): ?><span class="tag" style="margin-left:.35rem">publishing</span><?php endif; ?>
                    <?php if ($qVis !== 'public'): ?><span class="tag" style="margin-left:.35rem"><?= h($qVisLabel) ?></span><?php endif; ?>
                  </div>
                  <div class="meta">
                    #<?= $qid ?>
                    · <?= h($qVisLabel) ?>
                    <?php if ($qmediaN): ?> · <?= $qmediaN ?> media<?php endif; ?>
                    <?php if (!empty($qi['spoiler_text'])): ?> · CW<?php endif; ?>
                    <?php if (!empty($qi['in_reply_to'])): ?> · reply<?php endif; ?>
                    <?php if (!empty($qi['quote_object'])): ?> · quote<?php endif; ?>
                  </div>
                </div>
              </div>
              <?php if ($qplain !== ''): ?>
                <div class="body feed-body" style="white-space:pre-wrap"><?= h(mb_strlen($qplain) > 400 ? mb_substr($qplain, 0, 397) . '…' : $qplain) ?></div>
              <?php elseif ($qmediaN): ?>
                <div class="meta">(media only)</div>
              <?php endif; ?>
              <?php if (!empty($qi['last_error'])): ?>
                <div class="meta" style="color:var(--danger);margin-top:.35rem"><?= h((string) $qi['last_error']) ?></div>
              <?php endif; ?>
              <div class="tweet-actions">
                <?php if ($qstate === 'pending'): ?>
                  <form method="post" action="?view=queue" style="display:inline">
                    <input type="hidden" name="action" value="queue_move">
                    <input type="hidden" name="queue_id" value="<?= $qid ?>">
                    <input type="hidden" name="direction" value="up">
                    <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">↑</button>
                  </form>
                  <form method="post" action="?view=queue" style="display:inline">
                    <input type="hidden" name="action" value="queue_move">
                    <input type="hidden" name="queue_id" value="<?= $qid ?>">
                    <input type="hidden" name="direction" value="down">
                    <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">↓</button>
                  </form>
                <?php endif; ?>
                <?php if ($qstate === 'failed'): ?>
                  <form method="post" action="?view=queue" style="display:inline">
                    <input type="hidden" name="action" value="queue_retry">
                    <input type="hidden" name="queue_id" value="<?= $qid ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Retry</button>
                  </form>
                <?php endif; ?>
                <?php if (in_array($qstate, ['pending', 'failed'], true)): ?>
                  <details style="display:inline-block">
                    <summary class="btn btn-ghost" style="padding:.25rem .7rem;font-size:.8rem;list-style:none;cursor:pointer">Edit</summary>
                    <form method="post" action="?view=queue" class="composer" style="margin-top:.5rem;min-width:min(100%,28rem)">
                      <input type="hidden" name="action" value="queue_edit">
                      <input type="hidden" name="queue_id" value="<?= $qid ?>">
                      <input name="spoiler_text" maxlength="500" value="<?= h((string) ($qi['spoiler_text'] ?? '')) ?>" placeholder="Content warning (optional)">
                      <textarea name="content" maxlength="2000" style="min-height:6rem"><?= h($qplain) ?></textarea>
                      <label class="composer-check">
                        <input type="checkbox" name="sensitive" value="1"<?= !empty($qi['sensitive']) ? ' checked' : '' ?>>
                        <span>Sensitive</span>
                      </label>
                      <div class="composer-actions">
                        <span class="meta">Media attachments stay as-is</span>
                        <button class="btn btn-primary" type="submit">Save</button>
                      </div>
                    </form>
                  </details>
                  <form method="post" action="?view=queue" style="display:inline" onsubmit="return confirm('Remove this from the queue?');">
                    <input type="hidden" name="action" value="queue_cancel">
                    <input type="hidden" name="queue_id" value="<?= $qid ?>">
                    <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem;color:var(--danger)">Cancel</button>
                  </form>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

        <?php if ($qPublished): ?>
          <h3 style="font-size:.95rem;color:var(--muted);margin:1.25rem 0 .5rem">Recently published from queue</h3>
          <?php foreach ($qPublished as $qp): ?>
            <?php
              $noteId = (string) ($qp['published_note_id'] ?? '');
              $whenPub = ap_queue_format_local(isset($qp['published_at']) ? (string) $qp['published_at'] : null, $qSettings);
              $plainPub = trim((string) ($qp['content'] ?? ''));
            ?>
            <article class="tweet">
              <div class="meta"><?= h($whenPub !== '' ? $whenPub : 'Published') ?></div>
              <?php if ($plainPub !== ''): ?>
                <div class="body feed-body" style="white-space:pre-wrap;margin-top:.35rem"><?= h(mb_strlen($plainPub) > 280 ? mb_substr($plainPub, 0, 277) . '…' : $plainPub) ?></div>
              <?php endif; ?>
              <div class="tweet-actions">
                <?php if ($noteId !== ''): ?>
                  <a class="btn btn-ghost" href="<?= h(admin_status_href($noteId, 'queue')) ?>" style="padding:.25rem .7rem;font-size:.8rem">Open</a>
                  <a href="?view=outbox" class="meta">Your posts</a>
                <?php endif; ?>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($view === 'drafts'): ?>
        <?php $draftRows = function_exists('ap_drafts_list') ? ap_drafts_list(100) : []; ?>
        <div class="meta" style="margin-bottom:1rem">
          Closing the composer (or tapping <b>Save draft</b>) keeps unfinished posts here. Resume to edit, or publish / queue from the composer.
        </div>
        <?php if (!$draftRows): ?>
          <div class="empty">No drafts yet. Type something in Compose and close it — it’ll land here.</div>
        <?php else: ?>
          <?php foreach ($draftRows as $dr): ?>
            <?php
              $did = (int) ($dr['id'] ?? 0);
              $dplain = trim((string) ($dr['content'] ?? ''));
              $dspoiler = trim((string) ($dr['spoiler_text'] ?? ''));
              $dmedia = function_exists('ap_draft_media_ids') ? ap_draft_media_ids($dr) : [];
              $dvis = (string) ($dr['visibility'] ?? 'public');
              $dquote = trim((string) ($dr['quote_object'] ?? ''));
              $dreply = trim((string) ($dr['in_reply_to'] ?? ''));
            ?>
            <article class="tweet">
              <div class="tweet-hd">
                <div>
                  <span class="who">Draft #<?= $did ?></span>
                  <span class="meta"> · <?= h(relative_time((string) ($dr['updated_at'] ?? ''))) ?></span>
                  <span class="meta"> · <?= h($dvis) ?></span>
                  <?php if ($dmedia): ?>
                    <span class="meta"> · <?= count($dmedia) ?> media</span>
                  <?php endif; ?>
                </div>
              </div>
              <?php if ($dspoiler !== ''): ?>
                <div class="meta" style="margin-bottom:.35rem">CW: <?= h($dspoiler) ?></div>
              <?php endif; ?>
              <?php if ($dplain !== ''): ?>
                <div class="body feed-body" style="white-space:pre-wrap"><?= h(mb_strlen($dplain) > 500 ? mb_substr($dplain, 0, 497) . '…' : $dplain) ?></div>
              <?php elseif ($dmedia): ?>
                <div class="meta">(media only)</div>
              <?php else: ?>
                <div class="meta">(empty body)</div>
              <?php endif; ?>
              <?php if ($dquote !== ''): ?>
                <div class="meta" style="margin-top:.35rem">Quote → <span class="mono"><?= h(mb_strlen($dquote) > 60 ? mb_substr($dquote, 0, 57) . '…' : $dquote) ?></span></div>
              <?php elseif ($dreply !== ''): ?>
                <div class="meta" style="margin-top:.35rem">Reply → <span class="mono"><?= h(mb_strlen($dreply) > 60 ? mb_substr($dreply, 0, 57) . '…' : $dreply) ?></span></div>
              <?php endif; ?>
              <div class="tweet-actions">
                <a class="btn btn-primary" href="?view=drafts&amp;compose=1&amp;draft_id=<?= $did ?>" style="padding:.25rem .7rem;font-size:.8rem">Resume</a>
                <form method="post" action="?view=drafts" style="display:inline">
                  <input type="hidden" name="action" value="draft_publish">
                  <input type="hidden" name="draft_id" value="<?= $did ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem">Publish now</button>
                </form>
                <form method="post" action="?view=drafts" style="display:inline" onsubmit="return confirm('Delete this draft?');">
                  <input type="hidden" name="action" value="draft_delete">
                  <input type="hidden" name="draft_id" value="<?= $did ?>">
                  <button class="btn btn-ghost" type="submit" style="padding:.25rem .7rem;font-size:.8rem;color:var(--danger)">Delete</button>
                </form>
              </div>
            </article>
          <?php endforeach; ?>
        <?php endif; ?>

      <?php elseif ($view === 'outbox'): ?>
        <?php if (!$outbox): ?><div class="empty">No local posts yet. Use the ＋ button to compose.</div><?php endif; ?>
        <?php foreach ($outbox as $n): ?>
          <?php admin_render_outbox_card($n, 'outbox'); ?>
        <?php endforeach; ?>

      <?php elseif ($view === 'stats'): ?>
        <div class="stat-grid">
          <div class="stat"><div class="n"><?= $total24 ?></div><div class="l">Events (24h)</div></div>
          <div class="stat"><div class="n"><?= $total7 ?></div><div class="l">Events (7d)</div></div>
          <div class="stat"><div class="n"><?= count($followers) ?></div><div class="l">Followers</div></div>
          <div class="stat"><div class="n"><?= count($mentions) ?></div><div class="l">Open mentions</div></div>
        </div>
        <div class="side-card">
          <h3>Activity types (24h)</h3>
          <?php foreach ($typeRows as $r): ?>
            <div class="bar-row"><span><?= h($r['type']) ?></span><span><?= (int) $r['c'] ?></span></div>
          <?php endforeach; ?>
        </div>
        <div class="side-card">
          <h3>Actions (24h)</h3>
          <?php foreach ($actionRows as $r): ?>
            <div class="bar-row"><span><?= h((string) $r['action_taken']) ?></span><span><?= (int) $r['c'] ?></span></div>
          <?php endforeach; ?>
        </div>
        <div class="side-card">
          <h3>Top hosts (24h)</h3>
          <?php foreach ($hostRows as $r): ?>
            <div class="bar-row"><span><?= h((string) $r['host']) ?></span><span><?= (int) $r['c'] ?></span></div>
          <?php endforeach; ?>
        </div>

      <?php elseif ($view === 'guestbook'): ?>
        <div class="wide">
          <div class="stat-grid" style="margin-bottom:1rem">
            <div class="stat"><div class="n" id="gb-total">--</div><div class="l">Total entries</div></div>
            <div class="stat"><div class="n" id="gb-recent">--</div><div class="l">Recent</div></div>
            <div class="stat"><div class="n" id="gb-updated" style="font-size:1rem">--</div><div class="l">Last updated</div></div>
          </div>
          <input class="search" id="gb-search" placeholder="Search name, email, message…" oninput="filterGuestbook()">
          <div id="gb-list"><div class="empty">Loading guestbook…</div></div>
        </div>

      <?php elseif ($view === 'support'): ?>
        <div class="wide">
          <div class="stat-grid" style="margin-bottom:1rem">
            <div class="stat"><div class="n">$<span id="sup-month">--</span></div><div class="l">Total this month</div></div>
            <div class="stat"><div class="n" id="sup-subs">--</div><div class="l">Active subscriptions</div></div>
            <div class="stat"><div class="n">$<span id="sup-mrr">--</span></div><div class="l">Monthly recurring</div></div>
            <div class="stat"><div class="n" id="sup-donations">--</div><div class="l">Recent donations</div></div>
          </div>
          <h2 style="font-size:1rem;color:var(--muted)">Recent activity</h2>
          <div id="sup-activity"><div class="empty">Loading…</div></div>
          <h2 style="font-size:1rem;color:var(--muted);margin-top:1.25rem">Subscriptions</h2>
          <div id="sup-subs-list"><div class="empty">Loading…</div></div>
        </div>

      <?php elseif ($view === 'analytics'): ?>
        <div class="wide">
          <div class="stat-grid" style="margin-bottom:1rem">
            <div class="stat"><div class="n" id="an-today">--</div><div class="l">Visitors today</div></div>
            <div class="stat"><div class="n" id="an-week">--</div><div class="l">Visitors this week</div></div>
            <div class="stat"><div class="n" id="an-views-week">--</div><div class="l">Page views (7d)</div></div>
            <div class="stat"><div class="n" id="an-views-month">--</div><div class="l">Page views (30d)</div></div>
          </div>
          <h2 style="font-size:1rem;color:var(--muted)">Popular pages</h2>
          <div id="an-pages"><div class="empty">Loading…</div></div>
          <h2 style="font-size:1rem;color:var(--muted);margin-top:1.25rem">Referrers</h2>
          <div id="an-refs"><div class="empty">Loading…</div></div>
          <div class="stat-grid" style="margin-top:1rem">
            <div class="stat"><div class="n" id="an-mobile">--%</div><div class="l">Mobile</div></div>
            <div class="stat"><div class="n" id="an-desktop">--%</div><div class="l">Desktop</div></div>
          </div>
          <h2 style="font-size:1rem;color:var(--muted);margin-top:1.25rem">Browsers</h2>
          <div id="an-browsers"><div class="empty">Loading…</div></div>
        </div>

      <?php endif; ?>
    </div>
    <?php if (in_array($view, ['home', 'feed', 'local', 'gallery', 'vakktok', 'bluesky'], true)): ?>
      <button type="button" class="feed-new-btn" id="feed-new-btn" hidden>New posts</button>
      <button type="button" class="feed-top-btn" id="feed-top-btn" title="Back to latest" aria-label="Back to latest posts">↑</button>
    <?php endif; ?>
  </section>
</div>

<script>
(function () {
  // CSRF: inject into every POST form (capture) + expose for hand-built FormData
  window.VAAK_CSRF = (document.querySelector('meta[name="csrf-token"]') || {}).content || '';
  window.vaakCsrfApply = function (fd) {
    if (fd && typeof fd.set === 'function' && window.VAAK_CSRF) {
      fd.set('csrf', window.VAAK_CSRF);
    }
    return fd;
  };

  // Progressive mobile haptics: Android browsers may support Vibration API;
  // iOS Safari simply ignores this enhancement.
  window.vaakHaptic = function (pattern) {
    try {
      if (navigator.vibrate) navigator.vibrate(pattern || 8);
    } catch (e) {}
  };
  document.addEventListener('click', function (ev) {
    const el = ev.target && ev.target.closest
      ? ev.target.closest('button, .btn, .icon-btn, .compose-tool, .timeline-tabs a, .nav a')
      : null;
    if (!el || el.disabled || el.getAttribute('aria-disabled') === 'true') return;
    const strong = el.classList.contains('btn-primary') || el.type === 'submit';
    window.vaakHaptic(strong ? 12 : 7);
  }, { passive: true, capture: true });

  function vaakPostMenuBodyFor(menu) {
    if (!(menu instanceof HTMLElement)) return null;
    var id = menu.getAttribute('data-menu-id');
    if (id) {
      var portaled = document.querySelector('.post-action-menu__body.is-portaled[data-menu-id="' + id + '"]');
      if (portaled) return portaled;
    }
    var body = menu.querySelector('.post-action-menu__body');
    return body || null;
  }

  function vaakRestorePostActionMenuBody(menu) {
    var body = vaakPostMenuBodyFor(menu);
    if (!body) return;
    body.classList.remove('is-portaled');
    body.style.top = '';
    body.style.left = '';
    body.style.right = '';
    body.style.bottom = '';
    body.style.maxHeight = '';
    if (body.parentElement !== menu) menu.appendChild(body);
  }

  function vaakClosePostActionMenus(except) {
    document.querySelectorAll('.post-action-menu[open]').forEach(function (menu) {
      if (except && menu === except) return;
      menu.removeAttribute('open');
      vaakRestorePostActionMenuBody(menu);
    });
  }

  function vaakStickyTopInset() {
    var h = 8;
    document.querySelectorAll('.mobile-topbar, .main > .topbar, .topbar-timeline').forEach(function (el) {
      if (!(el instanceof HTMLElement)) return;
      var st = window.getComputedStyle(el);
      if (st.display === 'none' || st.visibility === 'hidden') return;
      var r = el.getBoundingClientRect();
      if (r.bottom > h && r.top < 120) h = Math.max(h, Math.ceil(r.bottom) + 6);
    });
    return h;
  }

  function vaakPlacePostActionMenu(menu) {
    var summary = menu.querySelector('summary');
    var body = vaakPostMenuBodyFor(menu);
    if (!summary || !body) return;
    if (!menu.getAttribute('data-menu-id')) {
      menu.setAttribute('data-menu-id', 'pam-' + Math.random().toString(36).slice(2, 10));
    }
    var id = menu.getAttribute('data-menu-id');
    body.setAttribute('data-menu-id', id);
    // Portal to <body> so tweet content-visibility / overflow cannot clip it.
    if (body.parentElement !== document.body) document.body.appendChild(body);
    body.classList.add('is-portaled');

    var rect = summary.getBoundingClientRect();
    var pad = 8;
    var topInset = vaakStickyTopInset();
    var maxH = Math.min(window.innerHeight * 0.7, 352);
    body.style.maxHeight = maxH + 'px';
    // Force layout after portaling so scrollHeight is real (full admin menu).
    void body.offsetHeight;
    var menuW = Math.max(body.offsetWidth || 200, 200);
    var menuH = Math.min(Math.max(body.scrollHeight || 120, 80), maxH);

    // Horizontal: prefer to the RIGHT of the ⋯; else to the LEFT; else clamp.
    var left = rect.right + 6;
    if (left + menuW > window.innerWidth - pad) {
      left = rect.left - menuW - 6;
    }
    if (left < pad) left = pad;
    if (left + menuW > window.innerWidth - pad) {
      left = Math.max(pad, window.innerWidth - menuW - pad);
    }

    // Always open downward from the ⋯ button (clamp into the viewport).
    var top = rect.bottom + 6;
    if (top + menuH > window.innerHeight - pad) {
      top = Math.max(topInset, window.innerHeight - menuH - pad);
    }

    body.style.top = Math.round(top) + 'px';
    body.style.left = Math.round(left) + 'px';
    body.scrollTop = 0;
  }

  // pointerdown: ignore presses on the trigger or the portaled panel.
  document.addEventListener('pointerdown', function (ev) {
    var target = ev.target;
    if (target && target.closest && (
      target.closest('.post-action-menu') || target.closest('.post-action-menu__body')
    )) return;
    vaakClosePostActionMenus();
  }, true);

  function vaakOnPostMenuOpen(menu) {
    vaakClosePostActionMenus(menu);
    // Double rAF: first paint after portal, then measure full height.
    requestAnimationFrame(function () {
      requestAnimationFrame(function () {
        if (menu.open) vaakPlacePostActionMenu(menu);
      });
    });
  }

  document.addEventListener('toggle', function (ev) {
    var menu = ev.target;
    if (!(menu instanceof HTMLDetailsElement) || !menu.classList.contains('post-action-menu')) return;
    if (!menu.open) {
      vaakRestorePostActionMenuBody(menu);
      return;
    }
    vaakOnPostMenuOpen(menu);
  });

  // Backup if `toggle` is flaky: place after the click that opens <details>.
  document.addEventListener('click', function (ev) {
    var sum = ev.target && ev.target.closest && ev.target.closest('.post-action-menu > summary');
    if (!sum) return;
    var menu = sum.closest('.post-action-menu');
    if (!(menu instanceof HTMLDetailsElement)) return;
    setTimeout(function () {
      if (menu.open) vaakOnPostMenuOpen(menu);
      else vaakRestorePostActionMenuBody(menu);
    }, 0);
  });

  window.addEventListener('resize', function () {
    var open = document.querySelector('.post-action-menu[open]');
    if (open) vaakPlacePostActionMenu(open);
  });
  document.addEventListener('scroll', function (ev) {
    var open = document.querySelector('.post-action-menu[open]');
    if (!open) return;
    if (ev.target && ev.target.closest && ev.target.closest('.post-action-menu__body')) return;
    vaakPlacePostActionMenu(open);
  }, true);

  document.addEventListener('submit', function (ev) {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    if ((form.getAttribute('method') || 'get').toLowerCase() !== 'post') return;
    let input = form.querySelector('input[name="csrf"]');
    if (!input) {
      input = document.createElement('input');
      input.type = 'hidden';
      input.name = 'csrf';
      form.appendChild(input);
    }
    input.value = window.VAAK_CSRF || '';
  }, true);

  // Fold long timeline posts (text + images + link cards) behind Show more
  const FOLD_MAX = Math.round(18 * parseFloat(getComputedStyle(document.documentElement).fontSize || '16'));
  function ensureMoreBtn(content) {
    let btn = content.nextElementSibling;
    if (btn && btn.classList && btn.classList.contains('tweet-content-more')) return btn;
    btn = document.createElement('button');
    btn.type = 'button';
    btn.className = 'tweet-content-more';
    btn.textContent = 'Show more';
    btn.setAttribute('aria-expanded', 'false');
    content.insertAdjacentElement('afterend', btn);
    btn.addEventListener('click', () => {
      const expanded = content.classList.toggle('is-expanded');
      content.classList.toggle('is-collapsed', !expanded);
      btn.textContent = expanded ? 'Show less' : 'Show more';
      btn.setAttribute('aria-expanded', expanded ? 'true' : 'false');
      if (!expanded) {
        const tweet = content.closest('.tweet');
        if (tweet) {
          const scroller = document.querySelector('.feed') || document.querySelector('.main');
          const top = tweet.getBoundingClientRect().top;
          if (scroller && top < 0) {
            scroller.scrollBy({ top: top - 12, behavior: 'smooth' });
          }
        }
      }
    });
    return btn;
  }
  function clearTweetFold(el) {
    el.classList.remove('is-collapsed', 'has-fold', 'is-expanded');
    el.dataset.foldBound = '1';
    const sib = el.nextElementSibling;
    if (sib && sib.classList && sib.classList.contains('tweet-content-more')) sib.remove();
  }
  function enhanceTweetFolds(root) {
    const scope = root || document;
    scope.querySelectorAll('.tweet-content').forEach((el) => {
      // Opened status detail stays fully expanded
      if (el.closest('article.tweet-focus')) {
        clearTweetFold(el);
        return;
      }
      // CW posts already fold behind the warning — Show more would double-fold.
      // Opening CW "Show" reveals the full post (text + media) with no second clamp.
      if (el.closest('details.cw-gate')) {
        clearTweetFold(el);
        return;
      }
      if (el.dataset.foldBound === '1') return;
      // Measure natural height (text + media + cards as one block)
      el.classList.remove('is-collapsed', 'is-expanded');
      const full = el.scrollHeight;
      if (full <= FOLD_MAX + 24) {
        el.dataset.foldBound = '1';
        el.classList.remove('has-fold');
        const sib = el.nextElementSibling;
        if (sib && sib.classList && sib.classList.contains('tweet-content-more')) sib.remove();
        // Images may still be loading — remeasure when they finish
        el.querySelectorAll('img').forEach((img) => {
          if (img.complete) return;
          img.addEventListener('load', () => {
            if (el.closest('details.cw-gate') || el.closest('article.tweet-focus')) {
              clearTweetFold(el);
              return;
            }
            el.dataset.foldBound = '';
            enhanceTweetFolds(el.parentElement || document);
          }, { once: true });
        });
        return;
      }
      el.classList.add('has-fold', 'is-collapsed');
      el.dataset.foldBound = '1';
      const btn = ensureMoreBtn(el);
      btn.classList.add('is-visible');
      btn.textContent = 'Show more';
      btn.setAttribute('aria-expanded', 'false');
    });
  }
  window.novaEnhanceTweetFolds = enhanceTweetFolds;
  function scheduleTweetFolds(root) {
    requestAnimationFrame(() => enhanceTweetFolds(root));
  }
  if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', () => scheduleTweetFolds(document));
  } else {
    scheduleTweetFolds(document);
  }
  // Images can finish loading after first measure — recheck once
  window.addEventListener('load', () => {
    document.querySelectorAll('.tweet-content[data-fold-bound="1"]').forEach((el) => {
      if (el.classList.contains('is-expanded') || el.closest('article.tweet-focus') || el.closest('details.cw-gate')) return;
      el.dataset.foldBound = '';
    });
    scheduleTweetFolds(document);
  });

  // Deep-link from Notifications → Your posts / Home: highlight the liked note
  try {
    const focus = new URLSearchParams(window.location.search).get('focus');
    if (focus) {
      const esc = (window.CSS && CSS.escape) ? CSS.escape(focus) : focus.replace(/"/g, '\\"');
      const el = document.querySelector('[data-note-id="' + esc + '"]');
      if (el) {
        el.classList.add('tweet-focus');
        const root = document.querySelector('.feed') || document.querySelector('.main');
        setTimeout(() => {
          el.scrollIntoView({ behavior: 'smooth', block: 'center' });
        }, 80);
      }
    }
  } catch (e) {}

  // Persist nav / sidebar dropdown open/closed (unless current page forces open)
  document.querySelectorAll('details.nav-group[data-nav-key], details.side-drop[data-nav-key]').forEach((el) => {
    const key = 'nova-admin-nav-' + el.getAttribute('data-nav-key');
    const forced = el.classList.contains('nav-group') && el.hasAttribute('open');
    try {
      const saved = localStorage.getItem(key);
      if (!forced && saved === '0') el.removeAttribute('open');
      if (!forced && saved === '1') el.setAttribute('open', '');
    } catch (e) {}
    el.addEventListener('toggle', () => {
      try { localStorage.setItem(key, el.open ? '1' : '0'); } catch (e) {}
    });
  });

  // Notification + DM badges, tab title, AIM imrcv chime
  const notifView = <?= json_encode($view === 'mentions') ?>;
  const dmView = <?= json_encode($view === 'dms') ?>;
  const notifBaseTitle = <?= json_encode('VAAK · ' . view_title($view)) ?>;
  const notifBadge = document.getElementById('notif-badge');
  const dmBadge = document.getElementById('dm-badge');
  let lastNotifCount = <?= (int) $notifUnreadNav ?>;
  let lastDmCount = <?= (int) $dmUnreadNav ?>;
  // Digit-only snowflake compare — never chime for an id at/under the read marker,
  // and never for an id we already announced (count flicker used to false-trigger).
  const snowflakeDigits = (v) => String(v || '').replace(/\D+/g, '') || '0';
  const snowflakeNewer = (a, b) => {
    const x = snowflakeDigits(a);
    const y = snowflakeDigits(b);
    if (x.length !== y.length) return x.length > y.length;
    return x > y;
  };
  let lastReadNotifId = <?= json_encode($notifLastReadIdNav) ?>;
  // Track the newest *unread* tip only — overall latest used to false-chime when
  // the heavy notifications fetch hydrated in a different order than the light scan.
  let lastNotifEventId = <?= json_encode($notifLatestUnreadIdNav !== '' ? $notifLatestUnreadIdNav : $notifLatestIdNav) ?>;
  let lastDmEventId = <?= json_encode($dmLatestIdNav) ?>;
  let notifPollReady = false; // skip chime on the first poll after page load
  let notifPollTimer = null;
  // Foreground AIM chime — background tabs rely on Web Push instead.
  const NOTIF_SOUND_ENABLED = true;
  let notifSoundUnlocked = false;
  let lastPushSuppressAt = 0;
  let lastPushSuppressId = '';
  const notifAudio = NOTIF_SOUND_ENABLED ? new Audio('?ajax=notif_sound&v=imrcv5') : null;
  if (notifAudio) {
    notifAudio.preload = 'auto';
    notifAudio.volume = 0.9;
    notifAudio.setAttribute('playsinline', '');
  }
  // Browsers block autoplay until a user gesture. Prime the element with a
  // muted play/pause cycle so Safari grants permission for future chimes.
  const unlockNotifSound = () => {
    if (!NOTIF_SOUND_ENABLED || !notifAudio || notifSoundUnlocked) return;
    try {
      notifAudio.muted = true;
      notifAudio.volume = 0;
      notifAudio.currentTime = 0;
      const p = notifAudio.play();
      if (p && typeof p.then === 'function') {
        p.then(() => {
          notifAudio.pause();
          notifAudio.currentTime = 0;
          notifAudio.muted = false;
          notifAudio.volume = 0.9;
          notifSoundUnlocked = true;
          document.removeEventListener('pointerdown', unlockNotifSound);
          document.removeEventListener('keydown', unlockNotifSound);
        }).catch(() => {
          notifAudio.muted = false;
          notifAudio.volume = 0.9;
        });
      }
    } catch (e) {
      notifAudio.muted = false;
      notifAudio.volume = 0.9;
    }
  };
  if (NOTIF_SOUND_ENABLED) {
    document.addEventListener('pointerdown', unlockNotifSound);
    document.addEventListener('keydown', unlockNotifSound);
  }

  function playNotifSound(eventKey) {
    if (!NOTIF_SOUND_ENABLED || !notifAudio || !notifSoundUnlocked) return;
    // Background / unfocused tab: OS Web Push covers alerts — don't double-chime.
    if (document.visibilityState !== 'visible' || (typeof document.hasFocus === 'function' && !document.hasFocus())) {
      return;
    }
    // Suppress if a service-worker push just landed for this tip.
    const now = Date.now();
    if (lastPushSuppressAt && (now - lastPushSuppressAt) < 60 * 1000) {
      const tip = String(eventKey || '');
      if (!lastPushSuppressId || tip.includes(lastPushSuppressId)) return;
    }
    // Avoid duplicate chimes when Safari has two Vaak tabs polling at
    // different times, or when an unread counter briefly resets and rises
    // again for the same notification set.
    try {
      const signature = String(eventKey || '');
      if (!signature) return;
      const key = 'vaak-notif-chime-v5';
      const prior = JSON.parse(localStorage.getItem(key) || 'null');
      if (prior && prior.signature === signature && (now - Number(prior.at || 0)) < 10 * 60 * 1000) {
        return;
      }
      localStorage.setItem(key, JSON.stringify({ signature, at: now }));
    } catch (e) {}
    try {
      notifAudio.muted = false;
      notifAudio.volume = 0.9;
      notifAudio.currentTime = 0;
      const p = notifAudio.play();
      if (p && typeof p.catch === 'function') p.catch(() => {});
    } catch (e) {}
  }
  if (navigator.serviceWorker) {
    navigator.serviceWorker.addEventListener('message', (ev) => {
      const data = ev && ev.data;
      if (!data || data.type !== 'vaak-push') return;
      lastPushSuppressAt = Date.now();
      lastPushSuppressId = String(data.notification_id || '');
      if (typeof window.vaakPollNotif === 'function') window.vaakPollNotif();
    });
  }

  function setBadge(el, n) {
    if (!el) return;
    const label = n > 99 ? '99+' : String(n);
    if (n > 0) {
      el.textContent = label;
      el.hidden = false;
    } else {
      el.hidden = true;
      el.textContent = '0';
    }
  }

  function applyInboxUnread(notifCount, dmCount, opts) {
    const play = !!(opts && opts.play);
    // While reading Notifications the server marks them read — never resurrect
    // the badge/chime from a racing poll on this same page.
    let n = notifView ? 0 : Math.max(0, parseInt(notifCount, 10) || 0);
    const d = dmView ? 0 : Math.max(0, parseInt(dmCount, 10) || 0);
    const notifEventId = snowflakeDigits((opts && opts.notifId) || '');
    const dmEventId = String((opts && opts.dmId) || '');
    if (opts && opts.lastReadId != null && String(opts.lastReadId) !== '') {
      const lr = snowflakeDigits(opts.lastReadId);
      if (lr !== '0') lastReadNotifId = lr;
    }
    setBadge(notifBadge, n);
    setBadge(dmBadge, d);
    const titleBits = [];
    if (n > 0 && !notifView) titleBits.push(n > 99 ? '99+' : String(n));
    if (d > 0 && !dmView) titleBits.push('✉' + (d > 99 ? '99+' : String(d)));
    document.title = titleBits.length
      ? ('(' + titleBits.join(' · ') + ') ' + notifBaseTitle)
      : notifBaseTitle;
    // Chime only when a real unread tip advances past both the read marker and
    // the tip we already announced — never on count flicker or overall-latest fallback.
    let shouldPlay = false;
    let eventKey = '';
    if (play && notifPollReady) {
      const dmAdvanced = d > 0
        && dmEventId !== ''
        && lastDmEventId !== ''
        && lastDmEventId !== '0'
        && dmEventId !== lastDmEventId
        && Number(dmEventId) > Number(lastDmEventId)
        && d >= lastDmCount;
      const notifAdvanced = n > 0
        && notifEventId !== '0'
        && lastNotifEventId !== ''
        && lastNotifEventId !== '0'
        && notifEventId !== lastNotifEventId
        && snowflakeNewer(notifEventId, lastReadNotifId)
        && snowflakeNewer(notifEventId, lastNotifEventId);
      if (dmAdvanced || notifAdvanced) {
        shouldPlay = true;
        eventKey = (dmAdvanced ? 'dm:' + dmEventId : '') + (notifAdvanced ? '|notif:' + notifEventId : '');
      }
    }
    if (shouldPlay) playNotifSound(eventKey);
    lastNotifCount = n;
    lastDmCount = d;
    // Tip tracking: only advance from a real unread tip; when caught up, pin to read marker.
    if (n > 0 && notifEventId !== '0') {
      if (lastNotifEventId === '' || lastNotifEventId === '0'
          || snowflakeNewer(notifEventId, lastNotifEventId)
          || notifEventId === lastNotifEventId) {
        lastNotifEventId = notifEventId;
      }
    } else if (n === 0 && lastReadNotifId !== '0') {
      lastNotifEventId = lastReadNotifId;
    }
    if (d > 0 && dmEventId !== '') {
      lastDmEventId = dmEventId;
    } else if (d === 0) {
      lastDmEventId = lastDmEventId || '0';
    }
  }
  applyInboxUnread(<?= (int) $notifUnreadNav ?>, <?= (int) $dmUnreadNav ?>, {
    play: false,
    notifId: <?= json_encode($notifLatestUnreadIdNav !== '' ? $notifLatestUnreadIdNav : $notifLatestIdNav) ?>,
    lastReadId: <?= json_encode($notifLastReadIdNav) ?>,
    dmId: <?= json_encode($dmLatestIdNav) ?>,
  });
  const pollNotif = async () => {
    try {
      const res = await fetch('?ajax=notif_unread', {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' },
        cache: 'no-store',
      });
      if (!res.ok) return;
      const data = await res.json();
      const count = data && data.count;
      // Never fall back to overall latest_id when count is 0 — that was false-chiming
      // and re-badging already-read Wafrn mentions after mark-read.
      const unreadTip = (parseInt(count, 10) > 0 && data && data.latest_unread_id)
        ? data.latest_unread_id
        : '';
      applyInboxUnread(count, data && data.dm_count, {
        play: true,
        notifId: unreadTip,
        lastReadId: data && data.last_read_id,
        dmId: data && data.latest_dm_id,
      });
    } catch (e) {
    } finally {
      notifPollReady = true;
    }
  };
  const notifPollMs = () => (document.visibilityState === 'visible' ? 12000 : 45000);
  const scheduleNotifPoll = () => {
    if (notifPollTimer) clearTimeout(notifPollTimer);
    notifPollTimer = setTimeout(async () => {
      await pollNotif();
      scheduleNotifPoll();
    }, notifPollMs());
  };
  scheduleNotifPoll();
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') {
      pollNotif();
      scheduleNotifPoll();
    }
  });
  // Mobile Safari often throttles timers; refresh badges when the hamburger opens.
  window.addEventListener('vaak:mobile-nav-open', () => { pollNotif(); });
  window.vaakPollNotif = pollNotif;
})();

// VAAK browser Web Push (Security panel + SW registration)
(function () {
  const statusEl = document.getElementById('vaak-push-status');
  const enableBtn = document.getElementById('vaak-push-enable');
  const disableBtn = document.getElementById('vaak-push-disable');
  const safariHint = document.getElementById('vaak-push-safari-hint');
  const isIOS = /iPad|iPhone|iPod/.test(navigator.userAgent)
    || (navigator.platform === 'MacIntel' && navigator.maxTouchPoints > 1);
  const isStandalone = window.matchMedia('(display-mode: standalone)').matches
    || window.navigator.standalone === true;
  const supported = !!(window.isSecureContext && 'serviceWorker' in navigator && 'PushManager' in window && 'Notification' in window);
  if (safariHint && isIOS) safariHint.hidden = false;

  function urlBase64ToUint8Array(base64String) {
    const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
    const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
    const raw = atob(base64);
    const out = new Uint8Array(raw.length);
    for (let i = 0; i < raw.length; i++) out[i] = raw.charCodeAt(i);
    return out;
  }

  async function ensureSw() {
    return navigator.serviceWorker.register('/vaak/sw.js', { scope: '/vaak/' });
  }

  function setStatus(text, subscribed) {
    if (statusEl) statusEl.textContent = text;
    if (enableBtn) enableBtn.hidden = !!subscribed;
    if (disableBtn) disableBtn.hidden = !subscribed;
  }

  async function refreshStatus() {
    if (isIOS && !isStandalone) {
      setStatus('On iPhone/iPad: Share → Add to Home Screen, open VAAK from that icon, then tap Enable. (Safari tabs cannot show the permission prompt.)', false);
      // Keep Enable clickable so we can show the same guidance on tap.
      if (enableBtn) enableBtn.disabled = false;
      return;
    }
    if (!supported) {
      setStatus('Not supported in this browser (needs HTTPS + Push API). Try desktop Chrome/Firefox, or Home Screen VAAK on iOS 16.4+.', false);
      if (enableBtn) enableBtn.disabled = true;
      return;
    }
    try {
      const res = await fetch('?ajax=push_status', { credentials: 'same-origin', cache: 'no-store' });
      const data = await res.json().catch(() => null);
      const perm = Notification.permission;
      if (data && data.subscribed) {
        setStatus('Enabled (' + perm + '). Background alerts are on for this account.', true);
      } else if (perm === 'denied') {
        setStatus('Blocked by the browser — reset notification permission for mkultra.monster, then try Enable again.', false);
      } else {
        setStatus('Off. Tap Enable to allow alerts while VAAK is in the background.', false);
      }
    } catch (e) {
      setStatus('Could not check push status.', false);
    }
  }

  async function enablePush() {
    try {
      if (enableBtn) enableBtn.disabled = true;
      if (isIOS && !isStandalone) {
        setStatus('iPhone/iPad: Add VAAK to the Home Screen first, open it from that icon, then tap Enable again.', false);
        if (window.apAdminToast) {
          window.apAdminToast('Add VAAK to your Home Screen, open it from there, then Enable.', true);
        }
        return;
      }
      if (!supported) {
        setStatus('Push API not available in this browser.', false);
        return;
      }
      // Safari requires requestPermission() in the same turn as the user gesture.
      // Awaiting serviceWorker.ready first cancels the prompt entirely.
      let perm = Notification.permission;
      if (perm !== 'granted') {
        perm = await Notification.requestPermission();
      }
      if (perm !== 'granted') {
        setStatus('Permission not granted (' + perm + '). Check site settings for mkultra.monster.', false);
        return;
      }
      const reg = await ensureSw();
      await navigator.serviceWorker.ready;
      const vapidRes = await fetch('?ajax=push_vapid', { credentials: 'same-origin', cache: 'no-store' });
      const vapid = await vapidRes.json();
      if (!vapid || !vapid.ok || !vapid.publicKey) {
        setStatus((vapid && vapid.error) || 'VAPID key missing on server.', false);
        return;
      }
      let sub = await reg.pushManager.getSubscription();
      if (!sub) {
        sub = await reg.pushManager.subscribe({
          userVisibleOnly: true,
          applicationServerKey: urlBase64ToUint8Array(vapid.publicKey),
        });
      }
      const res = await fetch('?ajax=push_subscribe', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
          'X-VAAK-CSRF': window.VAAK_CSRF || '',
        },
        body: JSON.stringify({ subscription: sub.toJSON(), csrf: window.VAAK_CSRF || '' }),
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok) {
        setStatus((data && data.error) || 'Subscribe failed.', false);
        if (window.apAdminToast) window.apAdminToast((data && data.error) || 'Push subscribe failed.', true);
        return;
      }
      setStatus('Enabled. You’ll get alerts while VAAK is in the background.', true);
      if (window.apAdminToast) window.apAdminToast('Browser notifications enabled.');
    } catch (e) {
      setStatus('Could not enable notifications.', false);
      if (window.apAdminToast) window.apAdminToast('Could not enable notifications.', true);
    } finally {
      if (enableBtn) enableBtn.disabled = false;
    }
  }

  async function disablePush() {
    try {
      if (disableBtn) disableBtn.disabled = true;
      const reg = await navigator.serviceWorker.getRegistration('/vaak/');
      const sub = reg ? await reg.pushManager.getSubscription() : null;
      if (sub) await sub.unsubscribe();
      const fd = new FormData();
      fd.set('csrf', window.VAAK_CSRF || '');
      await fetch('?ajax=push_unsubscribe', {
        method: 'POST',
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest', 'X-VAAK-CSRF': window.VAAK_CSRF || '' },
        body: fd,
      });
      setStatus('Disabled for this browser.', false);
      if (window.apAdminToast) window.apAdminToast('Browser notifications disabled.');
    } catch (e) {
      setStatus('Could not disable notifications.', true);
    } finally {
      if (disableBtn) disableBtn.disabled = false;
    }
  }

  if (enableBtn) enableBtn.addEventListener('click', () => { enablePush(); });
  if (disableBtn) disableBtn.addEventListener('click', () => { disablePush(); });
  if (statusEl || enableBtn) refreshStatus();
  // Quietly register SW if already subscribed so push clicks work from any view.
  if (supported) {
    fetch('?ajax=push_status', { credentials: 'same-origin', cache: 'no-store' })
      .then((r) => r.json())
      .then((d) => { if (d && d.subscribed) ensureSw().catch(() => {}); })
      .catch(() => {});
  }
})();

window.apAdminToast = function (msg, isErr) {
  if (!msg) return;
  let stack = document.getElementById('ap-toast-stack');
  if (!stack) {
    stack = document.createElement('div');
    stack.id = 'ap-toast-stack';
    stack.className = 'ap-toast-stack';
    document.body.appendChild(stack);
  }
  const el = document.createElement('div');
  el.className = 'ap-toast' + (isErr ? ' err' : '');
  el.textContent = String(msg);
  stack.appendChild(el);
  setTimeout(() => {
    el.style.opacity = '0';
    el.style.transition = 'opacity .25s ease';
    setTimeout(() => el.remove(), 280);
  }, 2800);
};

(function () {
  const INTERACT = new Set([
    'favourite_status', 'unfavourite_status',
    'bookmark_status', 'unbookmark_status',
    'reblog_status', 'unreblog_status'
  ]);
  let folderPopover = null;

  function closeFolderPopover() {
    if (folderPopover) {
      folderPopover.remove();
      folderPopover = null;
    }
  }

  async function postFolderAction(action, fields) {
    const fd = new FormData();
    fd.set('action', action);
    fd.set('ajax', '1');
    fd.set('return_view', 'bookmarks');
    if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
    Object.keys(fields || {}).forEach((k) => fd.set(k, fields[k]));
    const res = await fetch(window.location.pathname + (window.location.search || ''), {
      method: 'POST',
      body: fd,
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
    });
    return res.json().catch(() => null);
  }

  /**
   * @param {HTMLElement} anchorBtn
   * @param {string} statusId
   * @param {string} objectId
   * @param {Array|null} selectedIds
   * @param {{ onRemove?: () => (void|Promise<void>) }|null} opts
   */
  async function openBookmarkFolderPicker(anchorBtn, statusId, objectId, selectedIds, opts) {
    closeFolderPopover();
    const rect = anchorBtn.getBoundingClientRect();
    const pop = document.createElement('div');
    pop.className = 'bm-folder-popover';
    pop.setAttribute('role', 'dialog');
    pop.innerHTML = '<h4>Save bookmark</h4><div class="meta">Loading folders…</div>';
    document.body.appendChild(pop);
    folderPopover = pop;
    const left = Math.min(window.innerWidth - 240, Math.max(8, rect.left));
    const top = Math.min(window.innerHeight - 200, rect.bottom + 6);
    pop.style.left = left + 'px';
    pop.style.top = top + 'px';

    let data = null;
    try {
      const res = await fetch('?ajax=bookmark_folders&status_id=' + encodeURIComponent(statusId), {
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json' }
      });
      data = await res.json();
    } catch (e) {
      data = null;
    }
    if (!folderPopover) return;
    const selected = new Set((selectedIds || (data && data.selected) || []).map(String));
    const folders = (data && data.folders) || [];
    let html = '<h4>Save to folder</h4>';
    html += '<div class="meta" style="margin-bottom:.35rem">Stays in All bookmarks either way.</div>';
    if (!folders.length) {
      html += '<div class="meta">No folders yet — create one below.</div>';
    } else {
      folders.forEach((f) => {
        const id = String(f.id || '');
        const checked = selected.has(id) ? ' checked' : '';
        html += '<label><input type="checkbox" data-folder-id="' + id + '"' + checked + '> '
          + String(f.title || 'Folder').replace(/</g, '&lt;') + '</label>';
      });
    }
    html += '<input type="text" id="bm-new-folder-name" maxlength="80" placeholder="New folder name…">';
    html += '<div class="bm-folder-actions">'
      + '<button type="button" class="btn btn-primary" data-bm-done="1">Done</button>'
      + '<button type="button" class="btn btn-ghost" data-bm-unbookmark="1">Remove bookmark</button>'
      + '</div>';
    pop.innerHTML = html;

    pop.addEventListener('change', async (ev) => {
      const input = ev.target;
      if (!(input instanceof HTMLInputElement) || input.type !== 'checkbox') return;
      const fid = input.getAttribute('data-folder-id');
      if (!fid) return;
      const action = input.checked ? 'bookmark_folder_add' : 'bookmark_folder_remove';
      const res = await postFolderAction(action, {
        folder_id: fid,
        status_id: statusId,
        object_id: objectId || ''
      });
      if (!res || !res.ok) {
        input.checked = !input.checked;
        window.apAdminToast((res && res.error) || 'Folder update failed.', true);
      }
    });

    pop.addEventListener('click', async (ev) => {
      const t = ev.target;
      if (!(t instanceof HTMLElement)) return;
      if (t.getAttribute('data-bm-done') === '1') {
        const nameInput = pop.querySelector('#bm-new-folder-name');
        const name = nameInput instanceof HTMLInputElement ? nameInput.value.trim() : '';
        if (name) {
          const created = await postFolderAction('bookmark_folder_create', { title: name });
          if (created && created.ok && created.id) {
            await postFolderAction('bookmark_folder_add', {
              folder_id: String(created.id),
              status_id: statusId,
              object_id: objectId || ''
            });
            window.apAdminToast('Saved to “' + name + '”.');
          } else {
            window.apAdminToast((created && created.error) || 'Could not create folder.', true);
            return;
          }
        }
        closeFolderPopover();
        return;
      }
      if (t.getAttribute('data-bm-unbookmark') === '1') {
        closeFolderPopover();
        if (opts && typeof opts.onRemove === 'function') {
          try { await opts.onRemove(); } catch (e) { /* quiet */ }
          return;
        }
        const form = anchorBtn.closest('form');
        if (form) {
          const actionInput = form.querySelector('input[name="action"]');
          if (actionInput) actionInput.value = 'unbookmark_status';
          form.requestSubmit ? form.requestSubmit() : form.submit();
        }
      }
    });
  }
  window.novaOpenBookmarkFolderPicker = openBookmarkFolderPicker;

  document.addEventListener('click', (ev) => {
    if (folderPopover && !folderPopover.contains(ev.target)) {
      closeFolderPopover();
    }
  });
  document.addEventListener('keydown', (ev) => {
    if (ev.key === 'Escape') closeFolderPopover();
  });

  function applyInteractButton(form, data) {
    const btn = form.querySelector('button[type="submit"]');
    const actionInput = form.querySelector('input[name="action"]');
    if (!btn || !actionInput || !data || !data.ok) return;
    const kind = data.kind;
    const active = !!data.active;
    if (kind === 'favourite') {
      btn.innerHTML = '<i class="ph' + (active ? '-fill' : '') + ' ph-heart" aria-hidden="true"></i>';
      btn.classList.toggle('on', active);
      btn.title = active ? 'Unlike' : 'Like';
      btn.setAttribute('aria-label', active ? 'Unlike' : 'Like');
      actionInput.value = active ? 'unfavourite_status' : 'favourite_status';
    } else if (kind === 'bookmark') {
      btn.innerHTML = '<i class="ph' + (active ? '-fill' : '') + ' ph-bookmark-simple" aria-hidden="true"></i>';
      btn.classList.toggle('on', active);
      btn.title = active ? 'Bookmark folders' : 'Bookmark';
      btn.setAttribute('aria-label', active ? 'Bookmark folders' : 'Bookmark');
      btn.setAttribute('data-bm-picker', active ? '1' : '0');
      actionInput.value = active ? 'unbookmark_status' : 'bookmark_status';
    } else if (kind === 'reblog') {
      btn.classList.toggle('on', active);
      btn.title = active ? 'Undo boost' : 'Boost';
      btn.setAttribute('aria-label', active ? 'Undo boost' : 'Boost');
      actionInput.value = active ? 'unreblog_status' : 'reblog_status';
    }
  }

  document.addEventListener('submit', async (ev) => {
    const form = ev.target;
    if (!(form instanceof HTMLFormElement)) return;
    const actionInput = form.querySelector('input[name="action"]');
    const action = actionInput ? actionInput.value : '';
    if (!INTERACT.has(action)) return;
    const btn = form.querySelector('button[type="submit"]');
    // Already bookmarked: open folder picker instead of immediately removing
    if (action === 'unbookmark_status' && btn && btn.getAttribute('data-bm-picker') === '1') {
      ev.preventDefault();
      const sid = (form.querySelector('input[name="status_id"]') || {}).value || '';
      const oid = (form.querySelector('input[name="object_id"]') || {}).value || '';
      if (sid) openBookmarkFolderPicker(btn, sid, oid, null);
      return;
    }
    ev.preventDefault();
    if (form.dataset.busy === '1') return;
    form.dataset.busy = '1';
    if (btn) btn.disabled = true;
    try {
      const fd = new FormData(form);
      fd.set('ajax', '1');
      const res = await fetch(form.getAttribute('action') || window.location.href, {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok) {
        window.apAdminToast((data && (data.error || data.notice)) || 'Action failed.', true);
        return;
      }
      applyInteractButton(form, data);
      // On Favourites / Bookmarks lists, drop the row when toggling off
      if ((data.kind === 'favourite' || data.kind === 'bookmark') && data.active === false) {
        const card = form.closest('article.tweet');
        const listView = (window.location.search || '').includes('view=favourites')
          || (window.location.search || '').includes('view=bookmarks');
        if (card && listView) {
          card.style.transition = 'opacity .2s ease';
          card.style.opacity = '0';
          setTimeout(() => card.remove(), 220);
        }
      }
      if (data.kind === 'bookmark' && data.active && data.open_folder_picker && btn) {
        openBookmarkFolderPicker(
          btn,
          String(data.status_id || ''),
          String(data.object_id || ''),
          data.folder_ids || []
        );
      } else if (data.kind === 'reblog' || (data.kind === 'bookmark' && !data.active)) {
        window.apAdminToast(data.notice || (data.active ? 'Saved.' : 'Removed.'));
      }
      // favourite: icon fill only — no toast, no scroll jump
    } catch (err) {
      window.apAdminToast('Network error — try again.', true);
    } finally {
      form.dataset.busy = '0';
      if (btn) btn.disabled = false;
    }
  });
})();
</script>
<?php if ($view === 'status'): ?>
<script>
(function () {
  /** Cancel all thread hydrate/poll fetches when leaving Status (Home click, back, etc.). */
  let threadAlive = true;
  let threadAbort = new AbortController();
  let replyPollTimer = null;
  let replyPollInterval = null;
  let replyPollBusy = false;
  const REPLY_POLL_MS = 45000;

  function killThreadWork() {
    if (!threadAlive) return;
    threadAlive = false;
    try { threadAbort.abort(); } catch (e) {}
    if (replyPollTimer) { clearTimeout(replyPollTimer); replyPollTimer = null; }
    if (replyPollInterval) { clearInterval(replyPollInterval); replyPollInterval = null; }
    replyPollBusy = false;
  }

  // Full navigation away from this page — abort before the next view competes for PHP-FPM.
  window.addEventListener('pagehide', killThreadWork);
  window.addEventListener('beforeunload', killThreadWork);
  document.addEventListener('click', (ev) => {
    const a = ev.target && ev.target.closest ? ev.target.closest('a[href]') : null;
    if (!a) return;
    if (ev.defaultPrevented || ev.button !== 0 || ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey) return;
    if (a.target && a.target !== '' && a.target !== '_self') return;
    const href = a.getAttribute('href') || '';
    if (!href || href.startsWith('#') || href.startsWith('javascript:')) return;
    // Same-page status refresh / Retry — keep work alive.
    if (href.indexOf('view=status') !== -1 && href.indexOf('object=') !== -1) return;
    killThreadWork();
  }, true);

  function threadFetch(url, init) {
    if (!threadAlive) return Promise.reject(new DOMException('Thread left', 'AbortError'));
    const opts = Object.assign({
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html' },
      cache: 'no-store',
      signal: threadAbort.signal
    }, init || {});
    return fetch(url, opts);
  }

  function isAbort(err) {
    return !threadAlive || (err && (err.name === 'AbortError' || err.code === 20));
  }

  /** Keep the focused post visually stable when content inserts above it (Mastodon-style). */
  function withFocusAnchor(fn) {
    const focus = document.getElementById('status-focus')
      || document.querySelector('article.tweet-focus');
    const before = focus ? focus.getBoundingClientRect().top : null;
    fn();
    if (focus && before !== null) {
      const after = focus.getBoundingClientRect().top;
      const delta = after - before;
      if (Math.abs(delta) > 1) {
        window.scrollBy(0, delta);
      }
    }
  }

  function loadThreadPart(box, threadParam, emptyMsg, failMsg) {
    if (!threadAlive) return;
    if (!box || box.dataset.needMore !== '1' || box.dataset.loading === '1') return;
    const objectUrl = box.dataset.object || '';
    const from = box.dataset.from || 'home';
    if (!objectUrl) return;
    box.dataset.loading = '1';
    const loading = box.querySelector('.status-thread-loading');
    threadFetch('?view=status&partial=1&thread=' + encodeURIComponent(threadParam)
        + '&object=' + encodeURIComponent(objectUrl)
        + '&from=' + encodeURIComponent(from))
      .then((res) => res.ok ? res.text() : Promise.reject(new Error('HTTP ' + res.status)))
      .then((html) => {
        if (!threadAlive) return;
        box.dataset.needMore = '0';
        box.dataset.loading = '0';
        if (!html || !html.trim()) {
          if (loading) loading.textContent = emptyMsg;
          return;
        }
        // Ancestors insert above the focus post — lock scroll to the focused card.
        if (threadParam === '1' || threadParam === 'ancestors') {
          withFocusAnchor(() => {
            box.innerHTML = html;
          });
        } else {
          // Replies grow below — leave scroll where it is (never jump to top).
          // Keep existing cached replies if the remote pass found nothing new.
          const tmp = document.createElement('div');
          tmp.innerHTML = html;
          const newCount = tmp.querySelectorAll('article.tweet').length;
          const oldCount = parseInt(box.dataset.seenCount || '0', 10);
          if (newCount === 0 && oldCount > 0) {
            if (loading) loading.remove();
            return;
          }
          if (newCount > 0 && newCount < oldCount) {
            if (loading) loading.remove();
            return;
          }
          box.innerHTML = html;
          box.dataset.seenCount = String(newCount);
        }
        if (typeof window.novaEnhanceTweetFolds === 'function') {
          window.novaEnhanceTweetFolds(box);
        }
      })
      .catch((err) => {
        box.dataset.loading = '0';
        if (isAbort(err)) return;
        if (loading) {
          loading.innerHTML = failMsg + ' '
            + '<a href="?view=status&object=' + encodeURIComponent(objectUrl)
            + '&from=' + encodeURIComponent(from) + '&amp;refresh_thread=1">Retry</a>';
        }
      });
  }

  function ensureThreadShells(objectUrl, from) {
    let anc = document.getElementById('status-thread-ancestors');
    let desc = document.getElementById('status-thread-descendants');
    const focus = document.getElementById('status-focus')
      || document.querySelector('article.tweet-focus');
    if (!anc) {
      anc = document.createElement('div');
      anc.id = 'status-thread-ancestors';
      anc.dataset.object = objectUrl;
      anc.dataset.from = from;
      anc.dataset.needMore = '1';
      anc.innerHTML = '<div class="meta status-thread-loading" style="margin-bottom:.5rem">Loading earlier posts…</div>';
      if (focus && focus.parentNode) {
        focus.parentNode.insertBefore(anc, focus);
      }
    } else if (anc.dataset.needMore !== '1' && !anc.querySelector('article.tweet')) {
      anc.dataset.needMore = '1';
      if (!anc.querySelector('.status-thread-loading')) {
        anc.innerHTML = '<div class="meta status-thread-loading" style="margin-bottom:.5rem">Loading earlier posts…</div>';
      }
    }
    if (!desc) {
      desc = document.createElement('div');
      desc.id = 'status-thread-descendants';
      desc.dataset.object = objectUrl;
      desc.dataset.from = from;
      desc.dataset.needMore = '1';
      desc.dataset.seenCount = '0';
      desc.innerHTML = '<div class="meta status-thread-loading" style="margin-top:1rem">Loading replies…</div>';
      if (focus && focus.parentNode) {
        focus.parentNode.insertBefore(desc, focus.nextSibling);
      }
    }
    return { anc, desc };
  }

  function loadFocusThenThread() {
    if (!threadAlive) return;
    const box = document.getElementById('status-thread-focus');
    if (!box || box.dataset.needMore !== '1') {
      loadThreadPart(
        document.getElementById('status-thread-ancestors'),
        'ancestors',
        'No earlier posts found.',
        'Couldn’t load earlier posts.'
      );
      loadThreadPart(
        document.getElementById('status-thread-descendants'),
        'replies',
        'No replies found.',
        'Couldn’t load replies.'
      );
      return;
    }
    const objectUrl = box.dataset.object || '';
    const from = box.dataset.from || 'home';
    if (!objectUrl) return;
    box.dataset.loading = '1';
    const loading = box.querySelector('.status-thread-loading');
    threadFetch('?view=status&partial=1&thread=focus&object=' + encodeURIComponent(objectUrl)
        + '&from=' + encodeURIComponent(from))
      .then((res) => res.ok ? res.text() : Promise.reject(new Error('HTTP ' + res.status)))
      .then((html) => {
        if (!threadAlive) return;
        box.dataset.needMore = '0';
        box.dataset.loading = '0';
        if (!html || !html.trim() || !html.includes('article')) {
          if (loading) {
            loading.innerHTML = 'Couldn’t load this post. '
              + '<a href="?view=status&object=' + encodeURIComponent(objectUrl)
              + '&from=' + encodeURIComponent(from) + '&amp;refresh_thread=1">Retry</a>';
          }
          return;
        }
        // Swap loading shell for the focus card (keep scroll — nothing above yet).
        const wrap = document.createElement('div');
        wrap.innerHTML = html.trim();
        const card = wrap.firstElementChild;
        if (!card) return;
        box.replaceWith(card);
        if (typeof window.novaEnhanceTweetFolds === 'function') {
          window.novaEnhanceTweetFolds(card);
        }
        // Now pull parents (source posts) + replies around the hydrated focus.
        const shells = ensureThreadShells(objectUrl, from);
        loadThreadPart(shells.anc, 'ancestors', 'No earlier posts found.', 'Couldn’t load earlier posts.');
        loadThreadPart(shells.desc, 'replies', 'No replies found.', 'Couldn’t load replies.');
      })
      .catch((err) => {
        box.dataset.loading = '0';
        if (isAbort(err)) return;
        if (loading) {
          loading.innerHTML = 'Couldn’t load this post. '
            + '<a href="?view=status&object=' + encodeURIComponent(objectUrl)
            + '&from=' + encodeURIComponent(from) + '&amp;refresh_thread=1">Retry</a>';
        }
      });
  }

  /** Object URL key for a reply card (used to detect newly arrived replies). */
  function replyCardKey(el) {
    if (!el || !el.querySelector) return '';
    const link = el.querySelector('a[href*="object="]');
    if (link) {
      const href = link.getAttribute('href') || '';
      const m = href.match(/[?&]object=([^&]+)/);
      if (m) {
        try { return decodeURIComponent(m[1]); } catch (e) { return m[1]; }
      }
    }
    return (el.id || '') || ('txt:' + (el.textContent || '').slice(0, 64));
  }

  function mergeNewReplies(box, html) {
    const tmp = document.createElement('div');
    tmp.innerHTML = html || '';
    const incoming = Array.from(tmp.querySelectorAll('article.tweet'));
    const loading = box.querySelector('.status-thread-loading');
    if (!incoming.length) {
      if (loading) loading.remove();
      return 0;
    }
    const known = {};
    box.querySelectorAll('article.tweet').forEach((el) => {
      const k = replyCardKey(el);
      if (k) known[k] = true;
    });
    const added = [];
    incoming.forEach((el) => {
      const k = replyCardKey(el);
      if (!k || known[k]) return;
      known[k] = true;
      added.push(el);
    });
    if (!added.length) {
      if (loading) loading.remove();
      const have = box.querySelectorAll('article.tweet').length;
      if (have > 0) box.dataset.seenCount = String(have);
      return 0;
    }
    box.querySelectorAll('.status-thread-loading, .empty, .status-thread-empty').forEach((el) => el.remove());
    const total = box.querySelectorAll('article.tweet').length + added.length;
    let countEl = box.querySelector('.status-thread-count');
    if (!countEl) {
      countEl = document.createElement('div');
      countEl.className = 'meta status-thread-count';
      countEl.style.cssText = 'margin:1rem 0 .5rem';
      box.insertBefore(countEl, box.firstChild);
    }
    countEl.textContent = total + ' replies';
    const frag = document.createDocumentFragment();
    added.forEach((el) => frag.appendChild(el));
    box.appendChild(frag);
    box.dataset.seenCount = String(total);
    if (typeof window.novaEnhanceTweetFolds === 'function') {
      window.novaEnhanceTweetFolds(box);
    }
    return added.length;
  }

  function pollThreadReplies() {
    if (!threadAlive || replyPollBusy) return;
    if (document.visibilityState === 'hidden') return;
    const box = document.getElementById('status-thread-descendants');
    if (!box) return;
    // Wait out the one-shot hydrate (needMore / loading flags).
    if (box.dataset.loading === '1' || box.dataset.needMore === '1') return;
    const focusBusy = document.getElementById('status-thread-focus');
    if (focusBusy && focusBusy.dataset.loading === '1') return;
    const objectUrl = box.dataset.object || '';
    const from = box.dataset.from || 'home';
    if (!objectUrl) return;
    replyPollBusy = true;
    threadFetch('?view=status&partial=1&thread=replies'
        + '&object=' + encodeURIComponent(objectUrl)
        + '&from=' + encodeURIComponent(from)
        + '&poll=1')
      .then((res) => res.ok ? res.text() : Promise.reject(new Error('HTTP ' + res.status)))
      .then((html) => {
        if (!threadAlive) return;
        mergeNewReplies(box, html);
      })
      .catch((err) => { if (!isAbort(err)) { /* quiet */ } })
      .finally(() => { replyPollBusy = false; });
  }

  loadFocusThenThread();
  // Live replies: wait for first hydrate, then quiet origin rechecks.
  replyPollTimer = setTimeout(pollThreadReplies, 20000);
  replyPollInterval = setInterval(pollThreadReplies, REPLY_POLL_MS);
  document.addEventListener('visibilitychange', () => {
    if (!threadAlive) return;
    if (document.visibilityState === 'hidden') {
      // Don't let a background tab keep a long remote context request running.
      try { threadAbort.abort(); } catch (e) {}
      threadAbort = new AbortController();
      replyPollBusy = false;
      return;
    }
    pollThreadReplies();
  });
})();
</script>
<?php endif; ?>

<?php if (in_array($view, ['home', 'feed', 'local', 'gallery', 'vakktok', 'mentions', 'bluesky'], true)): ?>
<script>
(function () {
  /** Replace a timeline card in place without jumping scroll to the top. */
  function replaceCardInPlace(oldEl, html) {
    const wrap = document.createElement('div');
    wrap.innerHTML = (html || '').trim();
    const neu = wrap.firstElementChild;
    if (!neu) return null;
    const beforeTop = oldEl.getBoundingClientRect().top;
    oldEl.replaceWith(neu);
    const afterTop = neu.getBoundingClientRect().top;
    const delta = afterTop - beforeTop;
    if (Math.abs(delta) > 1) {
      window.scrollBy(0, delta);
    }
    if (typeof window.novaEnhanceTweetFolds === 'function') {
      window.novaEnhanceTweetFolds(neu);
    }
    return neu;
  }

  // Progressive boost hydration: fetch missing boosted posts while you scroll,
  // swapping each card in place (never reloads the timeline / never jumps to top).
  const boostQueue = [];
  let boostActive = 0;
  const BOOST_CONCURRENCY = 2;

  function pumpBoostHydrate() {
    while (boostActive < BOOST_CONCURRENCY && boostQueue.length) {
      const card = boostQueue.shift();
      if (!card || !card.isConnected
        || (card.dataset.boostHydrate !== '1' && card.dataset.boostHydrateLocal !== '1')) continue;
      if (card.dataset.boostHydrating === '1') continue;
      card.dataset.boostHydrating = '1';
      boostActive++;
      const eventId = card.dataset.eventId || '';
      const objectId = card.dataset.objectId || '';
      const isLocal = card.dataset.boostHydrateLocal === '1';
      const from = card.dataset.returnView || 'home';
      const statusEl = card.querySelector('.boost-hydrate-status');
      if (statusEl) statusEl.textContent = 'Loading boosted post…';
      const url = '?view=' + encodeURIComponent(from)
        + '&partial=1&hydrate_boost=1'
        + (isLocal ? '&hydrate_local_boost=1' : '')
        + '&event_id=' + encodeURIComponent(eventId)
        + '&object_id=' + encodeURIComponent(objectId)
        + '&from=' + encodeURIComponent(from);
      fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html' },
        cache: 'no-store'
      }).then((res) => res.ok ? res.text() : Promise.reject(new Error('HTTP ' + res.status)))
        .then((html) => {
          const neu = replaceCardInPlace(card, html);
          // If still marked pending (fetch failed), leave a soft retry.
          if (neu && (neu.dataset.boostHydrate === '1' || neu.dataset.boostHydrateLocal === '1')) {
            const st = neu.querySelector('.boost-hydrate-status');
            if (st) {
              st.textContent = 'Still loading…';
            }
            neu.dataset.boostHydrate = '0';
          }
        })
        .catch(() => {
          const st = card.querySelector('.boost-hydrate-status');
          if (st) st.textContent = 'Couldn’t load boosted post';
          card.dataset.boostHydrate = '0';
          card.dataset.boostHydrating = '0';
        })
        .finally(() => {
          boostActive--;
          pumpBoostHydrate();
        });
    }
  }

  function enqueueBoostHydrates(root) {
    const scope = root || document;
    scope.querySelectorAll('article.tweet-boost[data-boost-hydrate="1"], article.tweet-boost[data-boost-hydrate-local="1"]').forEach((card) => {
      if (card.dataset.boostQueued === '1') return;
      card.dataset.boostQueued = '1';
      boostQueue.push(card);
    });
    pumpBoostHydrate();
  }

  window.novaEnqueueBoostHydrates = enqueueBoostHydrates;
  enqueueBoostHydrates(document.getElementById('timeline-items') || document);

  // Bluesky tab: like / boost / bookmark without leaving scroll position.
  (function bindBskyActions() {
    if (document.documentElement.dataset.bskyActionsBound === '1') return;
    document.documentElement.dataset.bskyActionsBound = '1';

    function setBskyIcon(btn, phName, on, titles) {
      btn.classList.toggle('on', on);
      btn.setAttribute('aria-pressed', on ? 'true' : 'false');
      btn.innerHTML = '<i class="ph' + (on ? '-fill' : '') + ' ph-' + phName + '" aria-hidden="true"></i>';
      const tOn = (titles && titles.on) || '';
      const tOff = (titles && titles.off) || '';
      btn.title = on ? tOn : tOff;
      btn.setAttribute('aria-label', on ? tOn : tOff);
    }

    async function postBskyAction(btn, postAction) {
      const uri = btn.dataset.uri || '';
      const cid = btn.dataset.cid || '';
      const recordUri = btn.dataset.recordUri || '';
      const body = new URLSearchParams();
      body.set('action', postAction);
      if (uri) body.set('bsky_uri', uri);
      if (cid) body.set('bsky_cid', cid);
      if (recordUri) body.set('bsky_record_uri', recordUri);
      body.set('ajax', '1');
      if (window.VAAK_CSRF) body.set('csrf', window.VAAK_CSRF);
      const res = await fetch('?view=bluesky&ajax=1', {
        method: 'POST',
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'Content-Type': 'application/x-www-form-urlencoded;charset=UTF-8',
          ...(window.VAAK_CSRF ? { 'X-VAAK-CSRF': window.VAAK_CSRF } : {}),
        },
        body: body.toString(),
      });
      const data = await res.json().catch(() => ({}));
      if (!res.ok || !data || !data.ok) {
        const msg = (data && data.error) ? data.error : ('HTTP ' + res.status);
        throw new Error(msg);
      }
      return data;
    }

    function applyBskyBookmarkUi(btn, on, data) {
      setBskyIcon(btn, 'bookmark-simple', on, {
        on: 'Bookmark folders',
        off: 'Bookmark on Bluesky',
      });
      btn.setAttribute('data-bm-picker', on ? '1' : '0');
      if (data && data.status_id) btn.dataset.statusId = String(data.status_id);
      if (data && data.object_id) btn.dataset.objectId = String(data.object_id);
    }

    document.addEventListener('click', async (ev) => {
      const btn = ev.target && ev.target.closest ? ev.target.closest('button.bsky-action') : null;
      if (!btn) return;
      ev.preventDefault();
      ev.stopPropagation();
      if (btn.dataset.busy === '1') return;
      const action = btn.dataset.bskyAction || '';
      const uri = btn.dataset.uri || '';
      const cid = btn.dataset.cid || '';
      const recordUri = btn.dataset.recordUri || '';
      const isOn = btn.classList.contains('on');
      if (!action) return;

      // Already bookmarked: open folder picker (same as federated cards).
      if (action === 'bookmark' && isOn && btn.getAttribute('data-bm-picker') === '1') {
        const sid = btn.dataset.statusId || '';
        const oid = btn.dataset.objectId || uri;
        if (sid && typeof window.novaOpenBookmarkFolderPicker === 'function') {
          window.novaOpenBookmarkFolderPicker(btn, sid, oid, null, {
            onRemove: async () => {
              btn.dataset.busy = '1';
              btn.disabled = true;
              try {
                const data = await postBskyAction(btn, 'bsky_unbookmark');
                applyBskyBookmarkUi(btn, false, data);
                if (window.vaakHaptic) window.vaakHaptic(6);
              } catch (e) {
                if (typeof window.apAdminToast === 'function') {
                  window.apAdminToast((e && e.message) || 'Unbookmark failed', true);
                }
              } finally {
                btn.dataset.busy = '0';
                btn.disabled = false;
              }
            },
          });
        }
        return;
      }

      let postAction = '';
      if (action === 'like') postAction = isOn ? 'bsky_unlike' : 'bsky_like';
      else if (action === 'repost') postAction = isOn ? 'bsky_unrepost' : 'bsky_repost';
      else if (action === 'bookmark') postAction = 'bsky_bookmark';
      else return;
      if ((postAction === 'bsky_like' || postAction === 'bsky_repost' || postAction === 'bsky_bookmark') && (!uri || !cid)) return;
      if ((postAction === 'bsky_unlike' || postAction === 'bsky_unrepost') && !recordUri) {
        btn.title = 'Refresh the page to undo this action';
        return;
      }
      btn.dataset.busy = '1';
      btn.disabled = true;
      try {
        const data = await postBskyAction(btn, postAction);
        if (action === 'like') {
          const on = !!data.liked;
          setBskyIcon(btn, 'heart', on, { on: 'Unlike', off: 'Like on Bluesky' });
          btn.dataset.recordUri = on ? (data.record_uri || '') : '';
        } else if (action === 'repost') {
          const on = !!data.reposted;
          // Match federated boosts: color via .on, no fill variant for ph-repeat.
          btn.classList.toggle('on', on);
          btn.setAttribute('aria-pressed', on ? 'true' : 'false');
          btn.title = on ? 'Undo boost' : 'Boost on Bluesky';
          btn.setAttribute('aria-label', on ? 'Undo boost' : 'Boost');
          btn.dataset.recordUri = on ? (data.record_uri || '') : '';
        } else if (action === 'bookmark') {
          applyBskyBookmarkUi(btn, !!data.bookmarked, data);
          if (data.bookmarked && data.open_folder_picker && typeof window.novaOpenBookmarkFolderPicker === 'function') {
            window.novaOpenBookmarkFolderPicker(
              btn,
              String(data.status_id || btn.dataset.statusId || ''),
              String(data.object_id || btn.dataset.objectId || uri),
              data.folder_ids || [],
              {
                onRemove: async () => {
                  btn.dataset.busy = '1';
                  btn.disabled = true;
                  try {
                    const removed = await postBskyAction(btn, 'bsky_unbookmark');
                    applyBskyBookmarkUi(btn, false, removed);
                  } catch (e) {
                    if (typeof window.apAdminToast === 'function') {
                      window.apAdminToast((e && e.message) || 'Unbookmark failed', true);
                    }
                  } finally {
                    btn.dataset.busy = '0';
                    btn.disabled = false;
                  }
                },
              }
            );
          }
        }
        if (window.vaakHaptic) window.vaakHaptic(6);
      } catch (e) {
        const msg = (e && e.message) ? e.message : 'Bluesky action failed — try again';
        btn.title = msg;
        if (typeof window.apAdminToast === 'function') {
          window.apAdminToast(msg, true);
        }
        if (window.vaakHaptic) window.vaakHaptic(8);
      } finally {
        btn.dataset.busy = '0';
        btn.disabled = false;
      }
    });
  })();

  const root = document.querySelector('.feed');
  let items = document.getElementById('timeline-items');
  let sentinel = document.getElementById('timeline-sentinel');
  let status = document.getElementById('timeline-status');
  const topBtn = document.getElementById('feed-top-btn');
  const newBtn = document.getElementById('feed-new-btn');
  const feedRoot = document.querySelector('.feed');
  if (newBtn && feedRoot) {
    // Keep the new-post row in normal feed flow; it must never cover the
    // sticky timeline tabs above it.
    const composeSlot = feedRoot.querySelector('#compose-inline-slot');
    if (composeSlot) feedRoot.insertBefore(newBtn, composeSlot.nextSibling);
    else feedRoot.insertBefore(newBtn, feedRoot.firstChild);
  }
  if (!root || !items || !sentinel) return;

  // Desktop: .feed is the scroll container. Mobile: body/window scrolls and
  // .feed is overflow:visible — IntersectionObserver must use the viewport.
  function feedIsScrollContainer() {
    const style = window.getComputedStyle(root);
    const oy = style.overflowY;
    if (oy !== 'auto' && oy !== 'scroll') return false;
    return root.scrollHeight > root.clientHeight + 2;
  }
  function scrollApi() {
    if (feedIsScrollContainer()) {
      return {
        mode: 'feed',
        ioRoot: root,
        top: () => root.scrollTop,
        height: () => root.scrollHeight,
        setTop: (v, smooth) => root.scrollTo({ top: v, behavior: smooth ? 'smooth' : 'auto' }),
        onScroll: (fn) => root.addEventListener('scroll', fn, { passive: true }),
      };
    }
    return {
      mode: 'window',
      ioRoot: null,
      top: () => window.scrollY || document.documentElement.scrollTop || 0,
      height: () => document.documentElement.scrollHeight,
      setTop: (v, smooth) => window.scrollTo({ top: v, behavior: smooth ? 'smooth' : 'auto' }),
      onScroll: (fn) => window.addEventListener('scroll', fn, { passive: true }),
    };
  }
  let sc = scrollApi();

  let offset = parseInt(items.dataset.offset || '0', 10);
  let limit = parseInt(items.dataset.limit || '15', 10);
  let viewName = items.dataset.view || 'home';
  let hasMore = items.dataset.hasMore === '1';
  let notifMaxId = items.dataset.maxId || '';
  let notifFilter = items.dataset.filter || 'all';
  let bskyCursor = items.dataset.cursor || '';
  let bskyFeed = items.dataset.feed || 'following';
  let bskySource = items.dataset.source || 'home';
  let loading = false;
  let newestTs = parseInt(items.dataset.newest || '0', 10) || Math.floor(Date.now() / 1000);
  let pendingHtml = '';
  let pendingCount = 0;
  let pollBusy = false;
  let tabSwapBusy = false;
  const POLL_MS = 120000;
  const AT_TOP_PX = 120;
  const TL_TITLES = { home: 'Home', local: 'Local', feed: 'Federation feed' };
  const isNotifTimeline = viewName === 'mentions';
  const isBskyTimeline = viewName === 'bluesky';

  // VakkTok behaves like a focused video reel: the visible video plays muted,
  // while videos leaving the viewport are paused so background media does not
  // consume CPU or memory.
  if (viewName === 'vakktok') {
    const tokRoot = items.classList.contains('vakktok-feed') ? items : null;
    let activeTokVideo = null;
    const syncTokPlayback = () => {
      if (!tokRoot) return;
      const rootRect = tokRoot.getBoundingClientRect();
      let best = null;
      let bestRatio = 0;
      items.querySelectorAll('.vakktok-video').forEach((video) => {
        const rect = video.getBoundingClientRect();
        const visible = Math.max(0, Math.min(rect.bottom, rootRect.bottom) - Math.max(rect.top, rootRect.top));
        const ratio = rect.height > 0 ? visible / rect.height : 0;
        if (ratio > bestRatio) { best = video; bestRatio = ratio; }
        if (video !== activeTokVideo && ratio < 0.55) {
          video.pause();
          try { video.currentTime = 0; } catch (e) {}
        }
      });
      if (best && bestRatio >= 0.7) {
        if (activeTokVideo && activeTokVideo !== best) {
          activeTokVideo.pause();
          try { activeTokVideo.currentTime = 0; } catch (e) {}
        }
        activeTokVideo = best;
        best.muted = false;
        best.play().catch(() => {});
      }
    };
    const tokObserver = new IntersectionObserver((entries) => {
      entries.forEach((entry) => {
        const video = entry.target;
        if (!(video instanceof HTMLVideoElement)) return;
        if (entry.isIntersecting && entry.intersectionRatio >= 0.7) {
          items.querySelectorAll('.vakktok-video').forEach((other) => {
            if (other !== video) {
              other.pause();
              try { other.currentTime = 0; } catch (e) {}
            }
          });
          activeTokVideo = video;
          video.muted = false;
          video.play().catch(() => {});
        } else {
          video.pause();
          try { video.currentTime = 0; } catch (e) {}
        }
      });
    }, { root: tokRoot, threshold: [0.7] });
    const observeTokVideos = (scope) => {
      if (!scope || !scope.querySelectorAll) return;
      scope.querySelectorAll('.vakktok-video').forEach((video) => {
        if (video.dataset.tokObserved === '1') return;
        video.dataset.tokObserved = '1';
        video.addEventListener('loadedmetadata', () => window.requestAnimationFrame(syncTokPlayback), { passive: true });
        video.addEventListener('canplay', () => window.requestAnimationFrame(syncTokPlayback), { passive: true });
        tokObserver.observe(video);
      });
    };
    observeTokVideos(items);
    if (tokRoot) {
      tokRoot.addEventListener('scroll', () => window.requestAnimationFrame(syncTokPlayback), { passive: true });
      tokRoot.addEventListener('keydown', (event) => {
        if (event.key === 'ArrowDown' || event.key === 'PageDown') {
          window.requestAnimationFrame(syncTokPlayback);
        }
      });
      window.addEventListener('resize', syncTokPlayback, { passive: true });
    }
    const tokMutationObserver = new MutationObserver((mutations) => {
      mutations.forEach((mutation) => mutation.addedNodes.forEach((node) => observeTokVideos(node)));
      window.requestAnimationFrame(syncTokPlayback);
    });
    tokMutationObserver.observe(items, { childList: true, subtree: true });
    window.requestAnimationFrame(syncTokPlayback);
  }

  // Pull-to-refresh indicator (mobile / window scroll)
  let ptrEl = root.querySelector('.feed-ptr');
  if (!ptrEl) {
    ptrEl = document.createElement('div');
    ptrEl.className = 'feed-ptr';
    ptrEl.setAttribute('aria-hidden', 'true');
    ptrEl.textContent = 'Pull to refresh';
    root.insertBefore(ptrEl, root.firstChild);
  }

  function seenKeys() {
    const keys = {};
    items.querySelectorAll('a[href*="object="], a.gallery-cell').forEach((a) => {
      const href = a.getAttribute('href') || '';
      const m = href.match(/[?&]object=([^&]+)/);
      if (m) keys[decodeURIComponent(m[1])] = true;
    });
    items.querySelectorAll('article.tweet').forEach((el, i) => {
      keys['node:' + (el.id || i) + ':' + (el.textContent || '').slice(0, 40)] = true;
    });
    return keys;
  }

  function filterNewHtml(html) {
    const wrap = document.createElement('div');
    wrap.innerHTML = (html || '').trim();
    const known = seenKeys();
    const keep = [];
    Array.from(wrap.children).forEach((el) => {
      let key = '';
      const link = el.matches && el.matches('a.gallery-cell')
        ? el
        : (el.querySelector && el.querySelector('a[href*="object="]'));
      if (link) {
        const href = link.getAttribute('href') || '';
        const m = href.match(/[?&]object=([^&]+)/);
        if (m) key = decodeURIComponent(m[1]);
      }
      if (!key) {
        key = 'node:' + (el.textContent || '').slice(0, 64);
      }
      if (known[key]) return;
      known[key] = true;
      keep.push(el);
    });
    const out = document.createElement('div');
    keep.forEach((el) => out.appendChild(el));
    return { html: out.innerHTML, count: keep.length };
  }

  function nearTop() {
    return sc.top() < AT_TOP_PX;
  }

  function updateNewBtn() {
    if (!newBtn) return;
    if (pendingCount <= 0 || !nearTop()) {
      newBtn.hidden = true;
      newBtn.classList.remove('show');
      newBtn.textContent = 'New posts';
      return;
    }
    newBtn.hidden = false;
    newBtn.classList.add('show');
    newBtn.textContent = pendingCount === 1 ? 'Show 1 post' : ('Show ' + pendingCount + ' posts');
  }

  function insertPending(opts) {
    const scrollToTop = !!(opts && opts.scrollToTop);
    if (!pendingHtml || pendingCount <= 0) {
      if (scrollToTop) sc.setTop(0, true);
      return;
    }
    const html = pendingHtml;
    const count = pendingCount;
    pendingHtml = '';
    pendingCount = 0;
    updateNewBtn();
    const prevHeight = sc.height();
    const prevTop = sc.top();
    items.insertAdjacentHTML('afterbegin', html);
    offset += count;
    items.dataset.offset = String(offset);
    if (typeof window.novaEnhanceTweetFolds === 'function') {
      window.novaEnhanceTweetFolds(items);
    }
    if (typeof window.novaEnqueueBoostHydrates === 'function') {
      window.novaEnqueueBoostHydrates(items);
    }
    if (scrollToTop || nearTop()) {
      sc.setTop(0, !!scrollToTop);
    } else {
      const delta = sc.height() - prevHeight;
      sc.setTop(prevTop + delta, false);
    }
  }

  async function pollNewer() {
    if (isNotifTimeline) return; // Notifications use max_id pages, not newer polls.
    if (isBskyTimeline) return; // Bluesky uses cursor pages, not since= head polls.
    if (pollBusy || document.hidden) return;
    pollBusy = true;
    try {
      const url = '?view=' + encodeURIComponent(viewName)
        + '&partial=1&newer=1&since=' + encodeURIComponent(String(newestTs))
        + '&limit=' + encodeURIComponent(String(Math.min(24, limit)));
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html' },
        cache: 'no-store'
      });
      if (!res.ok) return;
      const newestHdr = parseInt(res.headers.get('X-Newest') || '0', 10);
      if (newestHdr > newestTs) newestTs = newestHdr;
      items.dataset.newest = String(newestTs);
      const raw = await res.text();
      if (!raw || !raw.trim()) return;
      const filtered = filterNewHtml(raw);
      if (!filtered.count) return;
      pendingHtml = filtered.html + pendingHtml;
      pendingCount += filtered.count;
      updateNewBtn();
    } catch (e) {
      // quiet — Refresh still works
    } finally {
      pollBusy = false;
    }
  }

  async function loadMore() {
    if (!hasMore || loading) return;
    loading = true;
    const skeleton = document.createElement('div');
    skeleton.className = 'timeline-skeleton';
    skeleton.setAttribute('aria-hidden', 'true');
    skeleton.innerHTML = '<div class="timeline-skeleton-row"></div><div class="timeline-skeleton-row"></div>';
    if (status && status.parentNode) status.parentNode.insertBefore(skeleton, status);
    if (status) status.textContent = 'Loading…';
    // Capture scroll anchor before append so desktop .feed scroll doesn't jump.
    const prevTop = sc.top();
    const prevHeight = sc.height();
    try {
      // A page may render no HTML when every row was filtered or deduplicated.
      // Keep the pagination cursor independent from rendered card count (as
      // Mastodon does) and skip through a few such pages in one request.
      let attempts = 0;
      let inserted = false;
      while (hasMore && attempts < 4) {
        attempts++;
        let url;
        if (isNotifTimeline) {
          if (!notifMaxId) { hasMore = false; break; }
          url = '?view=mentions&partial=1'
            + '&notification_filter=' + encodeURIComponent(notifFilter || 'all')
            + '&notifications_max_id=' + encodeURIComponent(notifMaxId)
            + '&limit=' + encodeURIComponent(String(limit || 20));
        } else if (isBskyTimeline) {
          if (!bskyCursor && attempts > 1) { hasMore = false; break; }
          url = '?view=bluesky&partial=1'
            + '&feed=' + encodeURIComponent(bskyFeed || 'following')
            + '&source=' + encodeURIComponent(bskySource || 'home')
            + '&cursor=' + encodeURIComponent(bskyCursor || '')
            + '&limit=' + encodeURIComponent(String(limit || 30));
        } else {
          const requestOffset = offset;
          url = '?view=' + encodeURIComponent(viewName)
            + '&partial=1&offset=' + requestOffset + '&limit=' + limit;
        }
        const res = await fetch(url, { credentials: 'same-origin', headers: { 'Accept': 'text/html' } });
        if (!res.ok) throw new Error('HTTP ' + res.status);
        const html = await res.text();
        hasMore = res.headers.get('X-Has-More') === '1';
        if (isNotifTimeline) {
          const nextMax = (res.headers.get('X-Next-Max-Id') || '').replace(/\D+/g, '');
          if (!nextMax || nextMax === notifMaxId) {
            hasMore = false;
          } else {
            notifMaxId = nextMax;
            items.dataset.maxId = notifMaxId;
          }
        } else if (isBskyTimeline) {
          const nextCur = res.headers.get('X-Next-Cursor') || '';
          const srcHdr = res.headers.get('X-BSKY-Source') || '';
          if (srcHdr) {
            bskySource = srcHdr;
            items.dataset.source = bskySource;
          }
          if (!nextCur || nextCur === bskyCursor) {
            hasMore = false;
            bskyCursor = '';
          } else {
            bskyCursor = nextCur;
          }
          items.dataset.cursor = bskyCursor;
        } else {
          const requestOffset = offset;
          const next = parseInt(res.headers.get('X-Next-Offset') || String(requestOffset), 10);
          // Guard against a broken cursor causing a tight request loop.
          offset = next > requestOffset ? next : requestOffset + limit;
          items.dataset.offset = String(offset);
        }
        if (html.trim()) {
          items.insertAdjacentHTML('beforeend', html);
          inserted = true;
          // Keep visual place: if scroll container grew upward relative to the
          // viewport (rare), compensate; otherwise stay where you were reading.
          const delta = sc.height() - prevHeight;
          if (delta > 0 && sc.top() + 2 < prevTop) {
            sc.setTop(prevTop + delta, false);
          }
          if (typeof window.novaEnhanceTweetFolds === 'function') {
            window.novaEnhanceTweetFolds(items);
          }
          if (typeof window.novaEnqueueBoostHydrates === 'function') {
            window.novaEnqueueBoostHydrates(items);
          }
          break;
        }
      }
      if (!inserted && hasMore) {
        if (status) status.textContent = 'Scroll for more…';
      }
      items.dataset.hasMore = hasMore ? '1' : '0';
      if (status) {
        if (hasMore) status.textContent = 'Scroll for more…';
        else if (viewName === 'vakktok') status.textContent = '';
        else if (isNotifTimeline) status.textContent = 'End of notifications';
        else if (isBskyTimeline) status.textContent = 'End of Bluesky feed';
        else status.textContent = 'End of timeline';
      }
    } catch (e) {
      if (status) status.textContent = 'Could not load more — try Refresh';
      hasMore = false;
    } finally {
      if (skeleton && skeleton.parentNode) skeleton.remove();
      loading = false;
    }
  }

  let io = new IntersectionObserver((entries) => {
    if (entries.some((en) => en.isIntersecting)) loadMore();
  }, { root: sc.ioRoot, rootMargin: '180px', threshold: 0 });
  io.observe(sentinel);

  // Re-bind observer if layout flips (rotate / resize across the mobile breakpoint).
  window.addEventListener('resize', () => {
    const next = scrollApi();
    if (next.mode === sc.mode) return;
    io.disconnect();
    sc = next;
    io = new IntersectionObserver((entries) => {
      if (entries.some((en) => en.isIntersecting)) loadMore();
    }, { root: sc.ioRoot, rootMargin: '180px', threshold: 0 });
    io.observe(sentinel);
    sc.onScroll(updateTopBtn);
    updateTopBtn();
  }, { passive: true, capture: true });

  function updateTopBtn() {
    if (!topBtn) return;
    if (sc.top() > 280) topBtn.classList.add('show');
    else topBtn.classList.remove('show');
    updateNewBtn();
  }
  sc.onScroll(updateTopBtn);
  updateTopBtn();
  if (topBtn) {
    topBtn.addEventListener('click', () => {
      insertPending({ scrollToTop: true });
      sc.setTop(0, true);
    });
  }
  if (newBtn) {
    newBtn.addEventListener('click', () => insertPending({ scrollToTop: true }));
  }

  // Bluesky: first paint is Following-only; saved-feed samples load after paint
  // (mirrors Home deferred boost hydrate — keep TTFB / first paint snappy).
  async function loadBskyMergeSamples() {
    if (!isBskyTimeline) return;
    if (items.dataset.mergePending !== '1') return;
    if ((bskyFeed || 'following') !== 'following' && bskyFeed !== 'timeline') return;
    items.dataset.mergePending = '0';
    try {
      const url = '?view=bluesky&partial=1&merge=1'
        + '&feed=' + encodeURIComponent(bskyFeed || 'following')
        + '&limit=' + encodeURIComponent(String(Math.min(20, limit || 15)));
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html' },
        cache: 'no-store'
      });
      if (!res.ok) return;
      const html = await res.text();
      if (!html || !html.trim()) return;
      // Only swap the head if the reader is still near the top — avoid scroll jump.
      if (!nearTop()) return;
      const prevCursor = bskyCursor;
      items.innerHTML = html;
      const nextCur = res.headers.get('X-Next-Cursor') || prevCursor || '';
      if (nextCur) {
        bskyCursor = nextCur;
        items.dataset.cursor = bskyCursor;
        hasMore = true;
        items.dataset.hasMore = '1';
        if (status) status.textContent = 'Scroll for more…';
      }
      if (typeof window.novaEnhanceTweetFolds === 'function') {
        window.novaEnhanceTweetFolds(items);
      }
    } catch (e) {
      // quiet — Following head already painted
    }
  }
  if (isBskyTimeline && items.dataset.mergePending === '1') {
    const scheduleMerge = () => {
      if (typeof window.requestIdleCallback === 'function') {
        window.requestIdleCallback(() => { loadBskyMergeSamples(); }, { timeout: 2500 });
      } else {
        window.setTimeout(() => { loadBskyMergeSamples(); }, 400);
      }
    };
    if (document.readyState === 'complete') scheduleMerge();
    else window.addEventListener('load', scheduleMerge, { once: true });
  }

  // Pull-to-refresh (mainly mobile window scroll; desktop .feed overscroll is rare).
  let ptrStartY = 0;
  let ptrDy = 0;
  let ptrTracking = false;
  let ptrArmed = false;
  let ptrLastHaptic = 0;
  const PTR_READY = 72;
  function ptrReset() {
    ptrTracking = false;
    ptrArmed = false;
    ptrDy = 0;
    ptrLastHaptic = 0;
    if (ptrEl) {
      ptrEl.style.height = '0px';
      ptrEl.classList.remove('show', 'ready');
      ptrEl.textContent = 'Pull to refresh';
    }
  }
  function ptrCanStart(ev) {
    const target = ev && ev.target;
    if (target && target.closest && target.closest('textarea, input, select, button')) return false;
    return nearTop() && !document.body.classList.contains('mobile-nav-open');
  }
  document.addEventListener('touchstart', (ev) => {
    if (!ev.touches || !ev.touches[0] || !ptrCanStart(ev)) {
      ptrTracking = false;
      return;
    }
    ptrTracking = true;
    ptrArmed = false;
    ptrStartY = ev.touches[0].clientY;
    ptrDy = 0;
  }, { passive: true, capture: true });
  document.addEventListener('touchmove', (ev) => {
    if (!ptrTracking || !ev.touches || !ev.touches[0]) return;
    if (sc.top() > 4) {
      ptrReset();
      return;
    }
    ptrDy = ev.touches[0].clientY - ptrStartY;
    if (ptrDy < 8) return;
    ev.preventDefault();
    const pull = Math.min(110, ptrDy * 0.55);
    ptrArmed = pull >= PTR_READY;
    if (pull - ptrLastHaptic >= 16 || (ptrArmed && ptrLastHaptic < PTR_READY)) {
      window.vaakHaptic(ptrArmed ? 14 : 4);
      ptrLastHaptic = pull;
    }
    if (ptrEl) {
      ptrEl.style.height = pull + 'px';
      ptrEl.classList.add('show');
      ptrEl.classList.toggle('ready', ptrArmed);
      ptrEl.textContent = ptrArmed ? 'Release to refresh' : 'Pull to refresh';
    }
  }, { passive: false, capture: true });
  document.addEventListener('touchend', () => {
    if (!ptrTracking) return;
    const doRefresh = ptrArmed;
    ptrReset();
    if (doRefresh) {
      // Same as ↻ Refresh — full reload of the current feed view.
      const u = new URL(window.location.href);
      u.searchParams.set('view', viewName);
      u.searchParams.set('_r', String(Date.now()));
      window.location.href = u.pathname + u.search;
    }
  }, { passive: true });
  document.addEventListener('touchcancel', ptrReset, { passive: true });

  // Auto-hydrate: poll for newer posts; keep ↻ Refresh for a full reload.
  setInterval(pollNewer, POLL_MS);
  document.addEventListener('visibilitychange', () => {
    if (document.visibilityState === 'visible') pollNewer();
  });
  // Prime shortly after paint; subsequent checks use the two-minute cadence.
  setTimeout(pollNewer, 5000);
  window.novaPollTimeline = pollNewer;
  window.novaInsertPendingTimeline = insertPending;

  function loadDeferredSuggestions() {
    const slot = document.getElementById('home-suggestions-slot');
    if (!slot || slot.dataset.deferred !== '1') return;
    slot.dataset.deferred = '0';
    fetch('?ajax=home_suggestions', {
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html' },
      cache: 'no-store'
    }).then((res) => res.ok ? res.text() : '')
      .then((html) => {
        if (!html || !html.trim()) {
          slot.remove();
          return;
        }
        slot.hidden = false;
        slot.outerHTML = html;
      })
      .catch(() => { try { slot.remove(); } catch (e) {} });
  }

  async function swapTimelineView(nextView, push) {
    if (tabSwapBusy) return;
    nextView = String(nextView || '');
    if (!['home', 'local', 'feed'].includes(nextView)) {
      window.location.href = '?view=' + encodeURIComponent(nextView);
      return;
    }
    if (nextView === viewName && !push) return;
    tabSwapBusy = true;
    if (status) status.textContent = 'Loading…';
    items.classList.add('timeline-swapping');
    try {
      const url = '?view=' + encodeURIComponent(nextView)
        + '&partial=1&offset=0&limit=' + encodeURIComponent(String(limit || 15));
      const res = await fetch(url, {
        credentials: 'same-origin',
        headers: { 'Accept': 'text/html' },
        cache: 'no-store'
      });
      if (!res.ok) throw new Error('HTTP ' + res.status);
      const html = await res.text();
      hasMore = res.headers.get('X-Has-More') === '1';
      const nextOff = parseInt(res.headers.get('X-Next-Offset') || String(limit), 10);
      offset = nextOff > 0 ? nextOff : limit;
      viewName = nextView;
      pendingHtml = '';
      pendingCount = 0;
      updateNewBtn();
      items.innerHTML = html;
      items.dataset.view = nextView;
      items.dataset.offset = String(offset);
      items.dataset.hasMore = hasMore ? '1' : '0';
      // Prefer first card sort when present; fall back to "now".
      const firstSort = items.querySelector('article.tweet[data-sort], article.tweet');
      newestTs = Math.floor(Date.now() / 1000);
      items.dataset.newest = String(newestTs);
      if (status) {
        status.textContent = hasMore ? 'Scroll for more…' : (html.trim() ? 'End of timeline' : '');
      }
      // Empty-state hint when the partial returns nothing.
      if (!html.trim()) {
        const empty = document.createElement('div');
        empty.className = 'empty';
        if (nextView === 'local') {
          empty.textContent = 'No local posts yet. When anyone on this instance posts, it shows up here.';
        } else if (nextView === 'feed') {
          empty.textContent = 'No federation events yet.';
        } else {
          empty.innerHTML = 'Nothing here yet. Follow people, <a href="?view=tags">follow hashtags</a>, or hit ＋ to post.';
        }
        items.appendChild(empty);
      }
      if (nextView === 'home') {
        const slot = document.createElement('div');
        slot.id = 'home-suggestions-slot';
        slot.className = 'home-suggestions-slot';
        slot.dataset.deferred = '1';
        slot.hidden = true;
        items.appendChild(slot);
        loadDeferredSuggestions();
      }
      document.querySelectorAll('.timeline-tabs a').forEach((a) => {
        const href = a.getAttribute('href') || '';
        const m = href.match(/[?&]view=([a-z_]+)/);
        const v = m ? m[1] : '';
        a.classList.toggle('active', v === nextView);
      });
      document.querySelectorAll('#admin-rail-left a[href*="view="]').forEach((a) => {
        const href = a.getAttribute('href') || '';
        if (!/[?&]view=(home|local|feed)(?:&|$)/.test(href)) return;
        const m = href.match(/[?&]view=([a-z_]+)/);
        a.classList.toggle('active', (m ? m[1] : '') === nextView);
      });
      const h1 = document.querySelector('.main > .topbar h1');
      if (h1) h1.textContent = TL_TITLES[nextView] || nextView;
      const refresh = document.querySelector('.topbar-actions a.btn[title="Reload this view"]');
      if (refresh) {
        refresh.setAttribute('href', '?view=' + encodeURIComponent(nextView) + '&_r=' + Date.now());
      }
      if (typeof window.novaEnhanceTweetFolds === 'function') {
        window.novaEnhanceTweetFolds(items);
      }
      if (typeof window.novaEnqueueBoostHydrates === 'function') {
        window.novaEnqueueBoostHydrates(items);
      }
      sc.setTop(0, false);
      if (push !== false) {
        const u = new URL(window.location.href);
        u.searchParams.set('view', nextView);
        u.searchParams.delete('_r');
        history.pushState({ vaakTl: nextView }, '', u.pathname + u.search);
      }
      document.title = (TL_TITLES[nextView] || nextView) + ' · VAAK';
    } catch (e) {
      window.location.href = '?view=' + encodeURIComponent(nextView);
      return;
    } finally {
      items.classList.remove('timeline-swapping');
      tabSwapBusy = false;
      loading = false;
    }
  }

  document.querySelectorAll('.timeline-tabs a').forEach((a) => {
    a.addEventListener('click', (ev) => {
      if (ev.metaKey || ev.ctrlKey || ev.shiftKey || ev.altKey || a.target === '_blank') return;
      const href = a.getAttribute('href') || '';
      const m = href.match(/[?&]view=([a-z_]+)/);
      const next = m ? m[1] : '';
      if (!['home', 'local', 'feed'].includes(next)) return;
      ev.preventDefault();
      swapTimelineView(next, true);
    });
  });
  window.addEventListener('popstate', () => {
    const u = new URL(window.location.href);
    const next = u.searchParams.get('view') || 'home';
    if (['home', 'local', 'feed'].includes(next) && next !== viewName) {
      swapTimelineView(next, false);
    }
  });

  // After first paint: Home suggestions (trends load via global script below).
  if ('requestIdleCallback' in window) {
    requestIdleCallback(() => { loadDeferredSuggestions(); }, { timeout: 2500 });
  } else {
    setTimeout(() => { loadDeferredSuggestions(); }, 400);
  }
})();
</script>
<?php endif; ?>

<script>
// Deferred trends for every view that shows the right rail (Home, Notices, Discuss, …).
// Always starts as a shimmer shell; keep it visible briefly even on a warm cache
// so the sidebar clearly "loads" instead of popping in instantly.
(function () {
  const MIN_SKELETON_MS = 420;
  function loadDeferredTrends() {
    const box = document.getElementById('trends-sidebar');
    if (!box || box.dataset.deferred !== '1') return;
    box.dataset.deferred = 'loading';
    const from = box.dataset.from || 'home';
    const started = Date.now();
    const apply = (html) => {
      const wait = Math.max(0, MIN_SKELETON_MS - (Date.now() - started));
      window.setTimeout(() => {
        if (html && html.trim()) {
          box.innerHTML = html;
          box.dataset.deferred = '0';
        } else {
          box.dataset.deferred = '1';
        }
      }, wait);
    };
    fetch('?ajax=trends&from=' + encodeURIComponent(from), {
      credentials: 'same-origin',
      headers: { 'Accept': 'text/html' },
      cache: 'no-store'
    }).then((res) => res.ok ? res.text() : '')
      .then((html) => apply(html))
      .catch(() => apply(''));
  }
  window.novaLoadDeferredTrends = loadDeferredTrends;
  if ('requestIdleCallback' in window) {
    requestIdleCallback(loadDeferredTrends, { timeout: 1200 });
  } else {
    setTimeout(loadDeferredTrends, 120);
  }
})();
</script>

<?php
$showComposeFab = !in_array($view, ['guestbook', 'support', 'analytics', 'security'], true);
?>
<?php if ($showComposeFab): ?>
<button type="button" class="compose-fab" id="compose-fab" title="Compose" aria-label="Compose">＋</button>
<div class="img-lightbox" id="img-lightbox" aria-hidden="true" role="dialog" aria-modal="true" aria-label="Image preview">
  <button type="button" class="img-lightbox__close" id="img-lightbox-close" aria-label="Close">×</button>
  <img id="img-lightbox-img" src="" alt="">
</div>
<script>
(function () {
  const box = document.getElementById('img-lightbox');
  const img = document.getElementById('img-lightbox-img');
  const closeBtn = document.getElementById('img-lightbox-close');
  if (!box || !img) return;
  function openLb(src) {
    if (!src) return;
    img.src = src;
    box.classList.add('open');
    box.setAttribute('aria-hidden', 'false');
  }
  function closeLb() {
    box.classList.remove('open');
    box.setAttribute('aria-hidden', 'true');
    img.removeAttribute('src');
  }
  document.addEventListener('click', (e) => {
    const t = e.target instanceof Element ? e.target.closest('.media-lightbox-trigger') : null;
    if (!t) return;
    e.preventDefault();
    openLb(t.getAttribute('data-full') || (t.querySelector('img') && t.querySelector('img').src) || '');
  });
  closeBtn && closeBtn.addEventListener('click', closeLb);
  box.addEventListener('click', (e) => { if (e.target === box) closeLb(); });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && box.classList.contains('open')) closeLb();
  });
})();
</script>
<script>
(() => {
  const rail = document.getElementById('admin-rail-left');
  const btn = document.getElementById('mobile-menu-btn');
  const backdrop = document.getElementById('mobile-nav-backdrop');
  if (!rail || !btn || !backdrop) return;

  const mq = window.matchMedia('(max-width: 700px)');
  function isMobile() { return mq.matches; }

  function openNav() {
    if (!isMobile()) return;
    rail.classList.add('mobile-open');
    backdrop.hidden = false;
    requestAnimationFrame(() => backdrop.classList.add('show'));
    document.body.classList.add('mobile-nav-open');
    btn.setAttribute('aria-expanded', 'true');
    btn.setAttribute('aria-label', 'Close menu');
    try { window.dispatchEvent(new CustomEvent('vaak:mobile-nav-open')); } catch (e) {}
  }
  function closeNav() {
    rail.classList.remove('mobile-open');
    backdrop.classList.remove('show');
    document.body.classList.remove('mobile-nav-open');
    btn.setAttribute('aria-expanded', 'false');
    btn.setAttribute('aria-label', 'Open menu');
    window.setTimeout(() => {
      if (!rail.classList.contains('mobile-open')) backdrop.hidden = true;
    }, 200);
  }
  function toggleNav() {
    if (rail.classList.contains('mobile-open')) closeNav();
    else openNav();
  }

  btn.addEventListener('click', toggleNav);
  backdrop.addEventListener('click', closeNav);
  rail.querySelectorAll('a[href]').forEach((a) => {
    a.addEventListener('click', () => { if (isMobile()) closeNav(); });
  });
  document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape' && rail.classList.contains('mobile-open')) closeNav();
  });
  mq.addEventListener('change', () => {
    if (!mq.matches) closeNav();
  });
})();
</script>

<div class="compose-modal<?= $autoOpenComposer ? ' open' : '' ?>" id="compose-modal" aria-hidden="<?= $autoOpenComposer ? 'false' : 'true' ?>">
  <div class="compose-modal__panel" role="dialog" aria-modal="true" aria-labelledby="compose-modal-title">
    <div class="compose-modal__hd">
      <?php
        $composeIsEdit = $prefillEditNote !== '';
        $composeIsDraft = !$composeIsEdit && $prefillDraftId > 0;
        $composeIsSelfReply = !$composeIsEdit && $prefillReplyTo !== ''
            && vaak_is_own_url($prefillReplyTo) && str_contains($prefillReplyTo, '/notes/');
        $composeTitle = $composeIsEdit
            ? 'Edit post'
            : ($composeIsDraft
                ? 'Edit draft'
                : ($prefillQuoteObject !== ''
                    ? 'Quote'
                    : ($prefillReplyTo !== '' ? ($composeIsSelfReply ? 'Continue thread' : 'Reply') : 'Compose')));
        $composeSubmit = $composeIsEdit
            ? 'Save edit'
            : ($prefillQuoteObject !== ''
                ? 'Quote'
                : ($prefillReplyTo !== '' ? 'Reply' : 'Post'));
        $composePlaceholder = $composeIsEdit
            ? 'Edit your post…'
            : ($prefillQuoteObject !== ''
                ? 'Add commentary (optional)…'
                : ($composeIsSelfReply ? 'Add to the thread…' : ($prefillReplyTo !== '' ? 'Write your reply…' : "What's happening?")));
        $composeVis = in_array($prefillVisibility, ['public', 'unlisted', 'private'], true)
            ? $prefillVisibility
            : 'public';
      ?>
      <h2 id="compose-modal-title"><?= h($composeTitle) ?></h2>
      <button type="button" class="compose-modal__close" id="compose-modal-close" aria-label="Close">×</button>
    </div>
    <form class="composer" method="post" action="?view=outbox" enctype="multipart/form-data" id="compose-form">
      <input type="hidden" name="action" id="compose-action" value="<?= $composeIsEdit ? 'edit_status' : 'reply' ?>">
      <input type="hidden" name="note_id" id="compose-note-id" value="<?= h($composeIsEdit ? $prefillEditNote : '') ?>">
      <input type="hidden" name="draft_id" id="compose-draft-id" value="<?= $prefillDraftId > 0 ? (string) (int) $prefillDraftId : '' ?>">
      <input type="hidden" name="draft_media_ids" id="compose-draft-media-ids" value="<?= h(implode(',', $prefillDraftMediaIds)) ?>">
      <input type="hidden" name="return_view" id="compose-return-view" value="<?= h($composerReturnView) ?>">
      <input type="hidden" name="compose_return_view" value="<?= h($composerReturnView) ?>">
      <?php if ($prefillQuoteObject !== '' && !$composeIsEdit): ?>
        <input type="hidden" name="quote_object" value="<?= h($prefillQuoteObject) ?>">
        <div class="meta" style="margin-bottom:.5rem">As <b style="color:var(--primary)"><?= h($vaakHandle) ?></b></div>
        <div class="quote-block" style="margin-bottom:.75rem"><span class="qt-label">Quoting</span><br><span class="mono"><?= h($prefillQuoteObject) ?></span></div>
      <?php else: ?>
        <div class="meta" style="margin-bottom:.5rem">As <b style="color:var(--primary)"><?= h('@' . $vaakUsername) ?></b><?php if ($composeIsDraft): ?> · <span style="color:var(--muted)">draft #<?= (int) $prefillDraftId ?></span><?php endif; ?></div>
        <?php if ($composeIsSelfReply): ?>
          <div class="quote-block" style="margin-bottom:.75rem"><span class="qt-label">Replying to your post</span><br><span class="mono"><?= h($prefillReplyTo) ?></span></div>
        <?php endif; ?>
      <?php endif; ?>
      <input name="spoiler_text" maxlength="500" placeholder="Content warning (optional)" style="margin-bottom:.5rem;flex:0 0 auto" value="<?= h($prefillEditSpoiler) ?>">
      <div class="compose-textarea-wrap">
        <textarea name="content" id="compose-content" maxlength="2000" placeholder="<?= h($composePlaceholder) ?>"><?= h($prefillEditContent) ?></textarea>
        <div class="meta compose-char-count" id="compose-char-count" style="margin-top:.35rem;text-align:right">0 / 2,000</div>
      </div>
      <div class="compose-emoji-wrap" style="margin:.45rem 0 .25rem;flex:0 0 auto">
        <button type="button" class="btn btn-ghost" id="compose-emoji-toggle" aria-expanded="false" aria-controls="compose-emoji-picker" style="padding:.3rem .75rem;font-size:.85rem">😀 Emoji</button>
        <div id="compose-emoji-picker" class="compose-emoji-picker" hidden role="listbox" aria-label="Emoji picker"></div>
      </div>
      <label class="meta" style="display:flex;flex-direction:column;gap:.3rem;margin:.55rem 0 .35rem<?= $composeIsEdit ? ';display:none' : '' ?>">
        Audience
        <select name="visibility" id="compose-visibility" style="max-width:18rem">
          <option value="public"<?= $composeVis === 'public' ? ' selected' : '' ?>>Public</option>
          <option value="unlisted"<?= $composeVis === 'unlisted' ? ' selected' : '' ?>>Silent public</option>
          <option value="private"<?= $composeVis === 'private' ? ' selected' : '' ?>>Followers-only</option>
        </select>
      </label>
      <label class="composer-check">
        <input type="checkbox" name="sensitive" value="1"<?= $prefillEditSensitive ? ' checked' : '' ?>>
        <span>Mark as sensitive (auto-on when CW is set)</span>
      </label>
      <div class="compose-media-row" id="compose-media-previews"></div>
      <label class="meta" style="display:block;margin:.55rem 0 .35rem<?= $composeIsEdit ? ';display:none' : '' ?>">
        <input type="file" name="media[]" id="compose-media-input" accept="image/*,video/mp4,video/webm,video/quicktime,audio/*" multiple style="max-width:100%">
      </label>
      <?php if (!$composeIsEdit): ?>
        <button type="button" class="btn btn-ghost compose-record-btn" id="compose-record-btn" title="Record up to 60 seconds of audio" aria-label="Record audio">
          <i class="ph ph-microphone" aria-hidden="true"></i>
        </button>
      <?php endif; ?>
      <?php if ($prefillQuoteObject === '' && !$composeIsEdit): ?>
        <input name="in_reply_to" id="compose-in-reply-to" value="<?= h($prefillReplyTo) ?>" placeholder="Reply-to object URL (optional)"<?= $composeIsSelfReply ? ' readonly' : '' ?>>
        <?php if (!$composeIsSelfReply): ?>
          <input name="to_actor" id="compose-to-actor" value="<?= h($prefillActor) ?>" placeholder="to actor URL (optional)">
        <?php else: ?>
          <input type="hidden" name="to_actor" id="compose-to-actor" value="">
        <?php endif; ?>
      <?php endif; ?>
      <div class="composer-actions">
        <div class="compose-submit-progress" id="compose-submit-progress" role="status" aria-live="polite">
          <progress id="compose-submit-progress-bar" max="100" value="0"></progress>
          <span id="compose-submit-progress-label">Uploading media…</span>
        </div>
        <span class="meta" id="compose-media-hint"><?php
          if ($composeIsEdit) {
              echo 'Media stays attached; text/CW edit only for now';
          } elseif ($prefillDraftMediaIds) {
              echo count($prefillDraftMediaIds) . ' media saved with draft'
                  . (count($prefillDraftMediaIds) < 4 ? ' — you can add more' : '');
          }
        ?></span>
        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
          <button class="btn btn-ghost" type="button" id="compose-draft-btn" title="Save as draft"<?= $composeIsEdit ? ' style="display:none"' : '' ?>>Save draft</button>
          <button class="btn btn-ghost" type="button" id="compose-queue-btn" title="Add to posting queue"<?= $composeIsEdit ? ' style="display:none"' : '' ?>>Add to queue</button>
          <button class="btn btn-primary" type="submit" id="compose-submit-btn"><?= h($composeSubmit) ?></button>
        </div>
      </div>
    </form>
  </div>
</div>
<div class="alt-modal" id="alt-modal" aria-hidden="true">
  <div class="alt-modal__panel" role="dialog" aria-modal="true" aria-labelledby="alt-modal-title">
    <div class="alt-modal__hd">
      <h3 id="alt-modal-title">Alt text</h3>
      <button type="button" class="compose-modal__close" id="alt-modal-close" aria-label="Close alt editor">×</button>
    </div>
    <div class="alt-modal__body">
      <div id="alt-modal-preview"></div>
      <label class="meta" for="alt-modal-text">Describe this image or video for people who can’t see it</label>
      <textarea id="alt-modal-text" maxlength="1500" placeholder="What’s in the media?"></textarea>
      <div class="meta" id="alt-modal-ai-status" style="margin-top:.45rem;min-height:1.2em"></div>
    </div>
    <div class="alt-modal__ft">
      <span class="meta" id="alt-modal-count">0 / 1500</span>
      <div style="display:flex;gap:.5rem;flex-wrap:wrap">
        <button type="button" class="btn btn-ghost" id="alt-modal-ai" title="Generate a draft description with Grok (review before saving)">✦ Generate with AI</button>
        <button type="button" class="btn btn-ghost" id="alt-modal-cancel">Cancel</button>
        <button type="button" class="btn btn-primary" id="alt-modal-save">Save</button>
      </div>
    </div>
  </div>
</div>
<script>
(function () {
  const modal = document.getElementById('compose-modal');
  const fab = document.getElementById('compose-fab');
  const closeBtn = document.getElementById('compose-modal-close');
  const input = document.getElementById('compose-media-input');
  const previews = document.getElementById('compose-media-previews');
  const form = document.getElementById('compose-form');
  const altModal = document.getElementById('alt-modal');
  const altText = document.getElementById('alt-modal-text');
  const altPreview = document.getElementById('alt-modal-preview');
  const altCount = document.getElementById('alt-modal-count');
  const altSave = document.getElementById('alt-modal-save');
  const altCancel = document.getElementById('alt-modal-cancel');
  const altClose = document.getElementById('alt-modal-close');
  const altAiBtn = document.getElementById('alt-modal-ai');
  const altAiStatus = document.getElementById('alt-modal-ai-status');
  const MAX = 4;
  // Match ap_media_ingest_upload caps — reject before wasting a mobile upload.
  const MEDIA_LIMITS = {
    image: 10 * 1024 * 1024,
    video: 50 * 1024 * 1024,
    audio: 20 * 1024 * 1024,
  };
  function mediaKindForFile(file) {
    const t = String((file && file.type) || '').toLowerCase();
    if (t.startsWith('video/')) return 'video';
    if (t.startsWith('audio/')) return 'audio';
    if (t.startsWith('image/') || /\.(heic|heif)$/i.test(String((file && file.name) || ''))) return 'image';
    return '';
  }
  function mediaLimitError(file) {
    const kind = mediaKindForFile(file);
    if (!kind) return 'Unsupported media type.';
    const max = MEDIA_LIMITS[kind] || 0;
    if (file && file.size > max) {
      return kind.charAt(0).toUpperCase() + kind.slice(1)
        + ' too large (max ' + Math.round(max / (1024 * 1024)) + 'MB).';
    }
    return '';
  }
  const COMPOSE_LS_KEY = 'vaak-compose-draft-v1';
  const timelineFeed = document.querySelector('.feed');
  const timelineItems = document.getElementById('timeline-items');
  // Home / Local / Federated share one composer panel: inline at top of feed,
  // or pop-out modal for reply/quote/edit. Must be moved back to the slot on close.
  const supportsInlineComposer = !!(modal && timelineFeed && timelineItems
    && ['home', 'local', 'feed'].includes(timelineItems.dataset.view || ''));
  let inlineChromeApplied = false;
  function getComposePanel() {
    return document.querySelector('.compose-modal__panel');
  }
  function isComposerInline() {
    const panel = getComposePanel();
    return !!(panel && panel.classList.contains('compose-inline-panel'));
  }
  function applyInlineComposerChrome(panel) {
    if (!panel || inlineChromeApplied) return;
    const inlineForm = panel.querySelector('#compose-form');
    const inlineActions = inlineForm && inlineForm.querySelector('.composer-actions');
    if (!inlineForm || !inlineActions) return;
    inlineChromeApplied = true;
    const asMeta = inlineForm.querySelector('.meta[style*="margin-bottom"]');
    const visibility = inlineForm.querySelector('#compose-visibility');
    const visibilityWrap = visibility && visibility.closest('label');
    if (asMeta && visibilityWrap && !visibilityWrap.classList.contains('compose-inline-visibility')) {
      visibilityWrap.classList.add('compose-inline-visibility');
      visibilityWrap.childNodes.forEach((node) => {
        if (node.nodeType === Node.TEXT_NODE) node.textContent = '';
      });
      asMeta.appendChild(document.createTextNode(' '));
      asMeta.appendChild(visibilityWrap);
    }
    // Manual object/actor targets belong to reply actions on posts, not
    // the normal top-of-feed composer.
    ['#compose-in-reply-to', '#compose-to-actor'].forEach((sel) => {
      const field = inlineForm.querySelector(sel);
      if (field) field.remove();
    });
    if (inlineForm.querySelector('.compose-inline-tools')) return;
    const tools = document.createElement('div');
    tools.className = 'compose-inline-tools';
    inlineForm.insertBefore(tools, inlineActions);
    const emojiWrap = inlineForm.querySelector('.compose-emoji-wrap');
    if (emojiWrap) {
      const emojiBtn = emojiWrap.querySelector('#compose-emoji-toggle');
      if (emojiBtn) {
        emojiBtn.className = 'compose-tool';
        emojiBtn.innerHTML = '<i class="ph ph-smiley" aria-hidden="true"></i>';
        emojiBtn.title = 'Add emoji';
        emojiBtn.setAttribute('aria-label', 'Add emoji');
      }
      tools.appendChild(emojiWrap);
    }
    const sensitiveLabel = inlineForm.querySelector('.composer-check');
    if (sensitiveLabel) {
      const sensitiveInput = sensitiveLabel.querySelector('input[name="sensitive"]');
      const sensitiveBtn = document.createElement('button');
      sensitiveBtn.type = 'button';
      sensitiveBtn.className = 'compose-tool';
      sensitiveBtn.innerHTML = '<i class="ph ph-eye-slash" aria-hidden="true"></i>';
      sensitiveBtn.title = 'Mark as sensitive';
      sensitiveBtn.setAttribute('aria-label', 'Mark as sensitive');
      sensitiveBtn.setAttribute('aria-pressed', sensitiveInput && sensitiveInput.checked ? 'true' : 'false');
      sensitiveBtn.addEventListener('click', () => {
        if (!sensitiveInput) return;
        sensitiveInput.checked = !sensitiveInput.checked;
        sensitiveBtn.setAttribute('aria-pressed', sensitiveInput.checked ? 'true' : 'false');
      });
      sensitiveLabel.innerHTML = '';
      if (sensitiveInput) sensitiveLabel.appendChild(sensitiveInput);
      sensitiveLabel.appendChild(sensitiveBtn);
      tools.appendChild(sensitiveLabel);
    }
    const mediaInputForToolbar = inlineForm.querySelector('#compose-media-input');
    const mediaLabel = mediaInputForToolbar && mediaInputForToolbar.closest('label');
    if (mediaLabel) {
      mediaLabel.classList.add('compose-media-tool');
      const mediaInput = mediaInputForToolbar;
      const mediaBtn = document.createElement('button');
      mediaBtn.type = 'button';
      mediaBtn.className = 'compose-tool';
      mediaBtn.innerHTML = '<i class="ph ph-image" aria-hidden="true"></i>';
      mediaBtn.title = 'Add media';
      mediaBtn.setAttribute('aria-label', 'Add media');
      mediaBtn.addEventListener('click', () => { if (mediaInput) mediaInput.click(); });
      mediaLabel.insertBefore(mediaBtn, mediaInput);
      tools.appendChild(mediaLabel);
    }
    const pollBtn = document.createElement('button');
    pollBtn.type = 'button';
    pollBtn.className = 'compose-tool';
    pollBtn.innerHTML = '<i class="ph ph-chart-bar" aria-hidden="true"></i>';
    pollBtn.title = 'Create poll';
    pollBtn.setAttribute('aria-label', 'Create poll');
    pollBtn.addEventListener('click', () => {
      if (window.apAdminToast) window.apAdminToast('Polls are not supported by Vaak yet.', true);
    });
    tools.appendChild(pollBtn);
    const recordBtnInline = inlineForm.querySelector('#compose-record-btn');
    if (recordBtnInline) {
      recordBtnInline.className = 'compose-tool';
      recordBtnInline.title = 'Record audio (up to 60 seconds)';
      tools.appendChild(recordBtnInline);
    }
  }
  function placeComposerInline() {
    if (!supportsInlineComposer || !modal) return;
    const panel = getComposePanel();
    if (!panel) return;
    panel.classList.add('compose-inline-panel');
    panel.setAttribute('role', 'region');
    panel.removeAttribute('aria-modal');
    const composeSlot = document.getElementById('compose-inline-slot');
    const newPostsRow = document.getElementById('feed-new-btn');
    if (composeSlot) {
      if (panel.parentElement !== composeSlot) {
        composeSlot.replaceChildren(panel);
      }
      composeSlot.style.marginBottom = '0';
      composeSlot.removeAttribute('aria-label');
    } else if (timelineFeed && panel.parentElement !== timelineFeed) {
      timelineFeed.insertBefore(panel, newPostsRow || timelineItems);
    }
    applyInlineComposerChrome(panel);
    modal.hidden = true;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    if (fab) fab.setAttribute('aria-hidden', 'true');
  }
  function placeComposerInModal() {
    if (!modal) return;
    const panel = getComposePanel();
    if (!panel) return;
    panel.classList.remove('compose-inline-panel');
    panel.setAttribute('role', 'dialog');
    panel.setAttribute('aria-modal', 'true');
    if (panel.parentElement !== modal) {
      modal.appendChild(panel);
    }
    modal.hidden = false;
    if (fab) fab.removeAttribute('aria-hidden');
  }
  // Initial placement: keep pop-out when opened as reply/quote/edit; otherwise inline.
  if (supportsInlineComposer && modal && !modal.classList.contains('open')) {
    placeComposerInline();
  }
  // Shared 2000-char counter for inline + pop-out composers (same textarea).
  (function bindComposeCharCount() {
    const composeText = document.getElementById('compose-content');
    const count = document.getElementById('compose-char-count');
    if (!composeText || !count) return;
    const updateCount = () => {
      count.textContent = (composeText.value || '').length + ' / 2,000';
    };
    updateCount();
    composeText.addEventListener('input', updateCount);
  })();
  let files = [];
  let alts = [];
  let altEditIdx = -1;
  let altAiBusy = false;
  const recordBtn = document.getElementById('compose-record-btn');
  let mediaRecorder = null;
  let recordStream = null;
  let recordChunks = [];
  let recordTimer = null;
  let recordStartedAt = 0;

  function stopAudioRecording() {
    if (recordTimer) {
      clearInterval(recordTimer);
      recordTimer = null;
    }
    if (mediaRecorder && mediaRecorder.state !== 'inactive') {
      mediaRecorder.stop();
    }
    if (recordStream) {
      recordStream.getTracks().forEach((track) => track.stop());
      recordStream = null;
    }
  }

  function updateRecordButton(elapsedMs) {
    if (!recordBtn) return;
    const seconds = Math.min(60, Math.floor((elapsedMs || 0) / 1000));
    const label = mediaRecorder && mediaRecorder.state === 'recording'
      ? ('Stop recording (' + seconds + '/60)')
      : 'Record audio';
    recordBtn.innerHTML = mediaRecorder && mediaRecorder.state === 'recording'
      ? '<i class="ph ph-stop-circle" aria-hidden="true"></i> <span>' + label + '</span>'
      : '<i class="ph ph-microphone" aria-hidden="true"></i>';
    recordBtn.classList.toggle('is-recording', !!(mediaRecorder && mediaRecorder.state === 'recording'));
    recordBtn.setAttribute('aria-label', label);
  }

  if (recordBtn) {
    recordBtn.addEventListener('click', async () => {
      if (mediaRecorder && mediaRecorder.state === 'recording') {
        stopAudioRecording();
        return;
      }
      if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia || typeof MediaRecorder === 'undefined') {
        if (window.apAdminToast) window.apAdminToast('Audio recording is not supported by this browser.', true);
        return;
      }
      if (files.length >= MAX) {
        if (window.apAdminToast) window.apAdminToast('Remove an attachment before recording audio.', true);
        return;
      }
      try {
        recordStream = await navigator.mediaDevices.getUserMedia({
          audio: {
            echoCancellation: true,
            noiseSuppression: true,
            autoGainControl: true,
            channelCount: 1
          }
        });
        const preferred = ['audio/webm;codecs=opus', 'audio/webm', 'audio/mp4'].find((type) => MediaRecorder.isTypeSupported(type));
        mediaRecorder = preferred ? new MediaRecorder(recordStream, { mimeType: preferred }) : new MediaRecorder(recordStream);
        recordChunks = [];
        recordStartedAt = Date.now();
        mediaRecorder.addEventListener('dataavailable', (event) => {
          if (event.data && event.data.size) recordChunks.push(event.data);
        });
        mediaRecorder.addEventListener('stop', () => {
          const mime = mediaRecorder && mediaRecorder.mimeType ? mediaRecorder.mimeType : (preferred || 'audio/webm');
          const duration = Date.now() - recordStartedAt;
          const blob = new Blob(recordChunks, { type: mime });
          recordChunks = [];
          const oldRecorder = mediaRecorder;
          mediaRecorder = null;
          updateRecordButton(0);
          if (duration > 60500) {
            if (window.apAdminToast) window.apAdminToast('Recordings must be one minute or shorter.', true);
            return;
          }
          const ext = mime.includes('mp4') ? 'm4a' : 'webm';
          files.push(new File([blob], 'voice-note-' + Date.now() + '.' + ext, { type: mime }));
          alts.push('');
          syncInput();
          render();
          void oldRecorder;
        });
        updateRecordButton(0);
        recordBtn.disabled = true;
        recordBtn.innerHTML = '<i class="ph ph-spinner-gap ph-spin" aria-hidden="true"></i>';
        // Give Safari/desktop input devices a short warm-up before the first
        // encoded sample. This avoids clipping the first spoken syllable.
        await new Promise((resolve) => setTimeout(resolve, 400));
        if (!mediaRecorder || !recordStream) return;
        mediaRecorder.start();
        recordBtn.disabled = false;
        updateRecordButton(0);
        recordTimer = setInterval(() => {
          const elapsed = Date.now() - recordStartedAt;
          updateRecordButton(elapsed);
          if (elapsed >= 60000) stopAudioRecording();
        }, 250);
      } catch (e) {
        if (recordStream) recordStream.getTracks().forEach((track) => track.stop());
        recordStream = null;
        mediaRecorder = null;
        if (window.apAdminToast) window.apAdminToast('Microphone permission is required to record audio.', true);
      }
    });
  }

  const draftIdField = document.getElementById('compose-draft-id');
  const draftMediaField = document.getElementById('compose-draft-media-ids');
  const draftBtn = document.getElementById('compose-draft-btn');
  let draftSaveBusy = false;
  let skipDraftOnClose = false;

  function clampComposeTextarea() {
    const ta = document.getElementById('compose-content');
    const wrap = ta && ta.closest ? ta.closest('.compose-textarea-wrap') : null;
    if (!ta || !wrap) return;
    const max = wrap.clientHeight;
    if (max < 48) return;
    // Native resize can set an inline height past the flex slot — snap it back.
    const h = ta.offsetHeight;
    if (h > max) {
      ta.style.height = max + 'px';
    }
  }
  function autoGrowComposeTextarea() {
    const ta = document.getElementById('compose-content');
    if (!ta) return;
    ta.style.height = '0px';
    const max = 500;
    const contentHeight = ta.scrollHeight;
    const next = Math.min(Math.max(contentHeight, 96), max);
    ta.style.height = next + 'px';
    ta.style.overflowY = contentHeight > max ? 'auto' : 'hidden';
  }
  function saveComposeLocalBackup() {
    if (composeMode === 'edit_status') return;
    try {
      const ta = document.getElementById('compose-content');
      const spoiler = form.querySelector('input[name="spoiler_text"]');
      const vis = form.querySelector('select[name="visibility"], input[name="visibility"]');
      const sens = form.querySelector('input[name="sensitive"]');
      const replyTo = document.getElementById('compose-in-reply-to');
      const payload = {
        at: Date.now(),
        content: (ta && ta.value) || '',
        spoiler: (spoiler && spoiler.value) || '',
        visibility: (vis && vis.value) || 'public',
        sensitive: !!(sens && sens.checked),
        reply_to: (replyTo && replyTo.value) || '',
        draft_id: (draftIdField && draftIdField.value) || '',
        draft_media_ids: (draftMediaField && draftMediaField.value) || '',
      };
      if (!(payload.content.trim() || payload.spoiler.trim() || payload.draft_media_ids)) {
        localStorage.removeItem(COMPOSE_LS_KEY);
        return;
      }
      localStorage.setItem(COMPOSE_LS_KEY, JSON.stringify(payload));
    } catch (e) {}
  }
  function restoreComposeLocalBackup() {
    if (composeMode === 'edit_status') return;
    try {
      const raw = localStorage.getItem(COMPOSE_LS_KEY);
      if (!raw) return;
      const payload = JSON.parse(raw);
      if (!payload || !payload.at || (Date.now() - Number(payload.at)) > 7 * 24 * 3600 * 1000) {
        localStorage.removeItem(COMPOSE_LS_KEY);
        return;
      }
      const ta = document.getElementById('compose-content');
      if (ta && !(ta.value || '').trim() && payload.content) ta.value = String(payload.content);
      const spoiler = form.querySelector('input[name="spoiler_text"]');
      if (spoiler && !(spoiler.value || '').trim() && payload.spoiler) spoiler.value = String(payload.spoiler);
      if (draftIdField && !draftIdField.value && payload.draft_id) draftIdField.value = String(payload.draft_id);
      if (draftMediaField && !draftMediaField.value && payload.draft_media_ids) {
        draftMediaField.value = String(payload.draft_media_ids);
      }
    } catch (e) {}
  }
  setInterval(() => {
    if (modal && modal.classList.contains('open') && composeHasDraftableContent()) {
      saveComposeLocalBackup();
    }
  }, 20000);
  window.addEventListener('pagehide', () => { saveComposeLocalBackup(); });
  window.addEventListener('beforeunload', (e) => {
    if (!composeHasDraftableContent()) return;
    if (draftIdField && draftIdField.value) return;
    saveComposeLocalBackup();
    e.preventDefault();
    e.returnValue = '';
  });
  function openModal() {
    restoreComposeLocalBackup();
    // Reply/quote/edit pop-out needs the panel back inside the modal shell.
    if (supportsInlineComposer && isComposerInline()) {
      placeComposerInModal();
    }
    modal.hidden = false;
    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
    const ta = document.getElementById('compose-content');
    if (ta) {
      requestAnimationFrame(() => {
        clampComposeTextarea();
        autoGrowComposeTextarea();
        ta.focus();
      });
    }
  }
  function composeHasDraftableContent() {
    if (composeMode === 'edit_status') return false;
    const ta = document.getElementById('compose-content');
    const spoiler = form.querySelector('input[name="spoiler_text"]');
    const text = ((ta && ta.value) || '').trim();
    const cw = ((spoiler && spoiler.value) || '').trim();
    const existingMedia = ((draftMediaField && draftMediaField.value) || '').trim();
    // Reply/quote targets are composer context, not user-authored content.
    // Do not create a draft merely because the composer was opened from a
    // Reply or Quote action and then closed without text or media.
    return !!(text || cw || existingMedia || (files && files.length));
  }
  function updateDraftsBadge(count) {
    const badge = document.getElementById('nav-drafts-badge');
    if (!badge) return;
    const n = parseInt(count, 10) || 0;
    if (n > 0) {
      badge.hidden = false;
      badge.textContent = n > 99 ? '99+' : String(n);
    } else {
      badge.hidden = true;
      badge.textContent = '';
    }
  }
  async function saveComposeDraft(opts) {
    opts = opts || {};
    const silent = !!opts.silent;
    if (composeMode === 'edit_status') {
      return { ok: false, skipped: true };
    }
    if (!composeHasDraftableContent()) {
      return { ok: false, skipped: true };
    }
    if (draftSaveBusy) {
      return { ok: false, busy: true };
    }
    draftSaveBusy = true;
    if (draftBtn) draftBtn.disabled = true;
    try {
      syncInput();
      const fd = new FormData(form);
      fd.set('ajax', '1');
      fd.set('action', 'save_draft');
      // Escape/close uses fetch FormData — not a form submit — so inject CSRF explicitly
      if (window.vaakCsrfApply) window.vaakCsrfApply(fd);
      else if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
      if (draftIdField && draftIdField.value) {
        fd.set('draft_id', draftIdField.value);
      }
      if (draftMediaField) {
        fd.set('draft_media_ids', draftMediaField.value || '');
      }
      const res = await fetch(form.getAttribute('action') || '?view=outbox', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: {
          'Accept': 'application/json',
          'X-Requested-With': 'XMLHttpRequest'
        }
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok) {
        if (!silent && window.apAdminToast) {
          window.apAdminToast((data && data.error) || 'Could not save draft.', true);
        }
        return { ok: false, error: (data && data.error) || 'save failed' };
      }
      if (draftIdField && data.draft_id) {
        draftIdField.value = String(data.draft_id);
      }
      if (draftMediaField && Array.isArray(data.media_ids)) {
        draftMediaField.value = data.media_ids.join(',');
      }
      // Clear freshly uploaded FileList — they’re now on the draft as media_ids
      files = [];
      alts = [];
      syncInput();
      render();
      if (typeof data.drafts_count === 'number') {
        updateDraftsBadge(data.drafts_count);
      }
      if (!silent && window.apAdminToast) {
        window.apAdminToast(data.notice || 'Draft saved.');
      }
      return { ok: true, data: data };
    } catch (e) {
      if (!silent && window.apAdminToast) {
        window.apAdminToast('Network error saving draft.', true);
      }
      return { ok: false, error: 'network' };
    } finally {
      draftSaveBusy = false;
      if (draftBtn) draftBtn.disabled = false;
    }
  }
  function clearComposeFieldsAfterClose() {
    const ta = document.getElementById('compose-content');
    if (ta) ta.value = '';
    const spoiler = form.querySelector('input[name="spoiler_text"]');
    if (spoiler) spoiler.value = '';
    const sens = form.querySelector('input[name="sensitive"]');
    if (sens) sens.checked = false;
    files = [];
    alts = [];
    syncInput();
    render();
    if (draftIdField) draftIdField.value = '';
    if (draftMediaField) draftMediaField.value = '';
    const replyTo = document.getElementById('compose-in-reply-to');
    const toActor = document.getElementById('compose-to-actor');
    if (replyTo) { replyTo.value = ''; replyTo.readOnly = false; replyTo.style.display = ''; }
    if (toActor) { toActor.value = ''; if (toActor.type !== 'hidden') toActor.style.display = ''; }
    const quoteField = form.querySelector('input[name="quote_object"]');
    if (quoteField) quoteField.remove();
    const qb = form.querySelector('.quote-block');
    if (qb) qb.remove();
    try {
      const u = new URL(window.location.href);
      ['compose', 'quote_object', 'quote_status_id', 'reply_to', 'to', 'edit_note', 'draft_id'].forEach((k) => u.searchParams.delete(k));
      window.history.replaceState({}, '', u.pathname + u.search + u.hash);
    } catch (e) {}
  }
  async function closeModal() {
    if (mediaRecorder && mediaRecorder.state === 'recording') stopAudioRecording();
    if (altModal && altModal.classList.contains('open')) {
      closeAltEditor(false);
      return;
    }
    const ep = document.getElementById('compose-emoji-picker');
    const et = document.getElementById('compose-emoji-toggle');
    if (ep && !ep.hidden) {
      ep.hidden = true;
      if (et) et.setAttribute('aria-expanded', 'false');
    }
    if (!skipDraftOnClose && composeHasDraftableContent()) {
      const saved = await saveComposeDraft({ silent: false });
      // Never wipe the composer after a failed draft — mobile users lose videos that way.
      if (saved && saved.ok) {
        try { localStorage.removeItem(COMPOSE_LS_KEY); } catch (e) {}
      } else if (!(saved && saved.skipped)) {
        return;
      }
    }
    skipDraftOnClose = false;
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    if (typeof window.__apResetComposeChrome === 'function') {
      try { window.__apResetComposeChrome(); } catch (e) {}
    }
    clearComposeFieldsAfterClose();
    // After closing a reply/quote pop-out on Home/Local/Federated, put the
    // shared composer back at the top of the timeline (otherwise it stays
    // inside the hidden modal until refresh).
    if (supportsInlineComposer) {
      placeComposerInline();
    } else {
      modal.hidden = true;
    }
  }

  // Unicode emoji picker for compose
  (function initEmojiPicker() {
    const toggle = document.getElementById('compose-emoji-toggle');
    const picker = document.getElementById('compose-emoji-picker');
    const ta = document.getElementById('compose-content');
    if (!toggle || !picker || !ta) return;
    const EMOJIS = [
      '😀','😁','😂','🤣','😅','😊','😍','🥰','😘','😜','🤔','😏','😎','🥺','😢','😭',
      '😤','🤬','🤯','😴','🫡','👀','✨','🔥','💯','❤️','🧡','💛','💚','💙','💜','🖤',
      '🤍','💔','💕','💖','⭐','🌟','⚡','🌙','☀️','🌈','🌸','🍀','🐱','🐶','🦊','🐻',
      '🐼','🐸','🦄','🐝','🐛','🐢','🐙','🐡','🦷','🍪','🍩','🍕','☕','🍺','🎉','🎊',
      '🦷','🦇','💀','👻','👽','🤖','👾','🎃','😈','👿','💋','🖕','👍','👎','👏','🙌',
      '🤝','✌️','🤞','🤟','🤘','👌','🤙','🙏','💪','🧠','💬','📢','🔔','📌','📎','✏️'
    ];
    // unique preserve order
    const seen = {};
    const list = [];
    EMOJIS.forEach((e) => { if (!seen[e]) { seen[e] = 1; list.push(e); } });
    const names = {
      '😀':'grinning smile', '😂':'joy laugh', '😊':'smile', '😍':'heart eyes',
      '🥰':'love', '😎':'sunglasses cool', '😢':'cry sad', '😭':'sob',
      '😡':'angry', '🤔':'thinking', '😴':'sleep', '👀':'eyes', '✨':'sparkles',
      '🔥':'fire', '💯':'hundred', '❤️':'heart', '⭐':'star', '🌈':'rainbow',
      '🐱':'cat', '🐶':'dog', '🦄':'unicorn', '🍕':'pizza', '☕':'coffee',
      '🎉':'party', '🎊':'confetti', '💀':'skull', '👻':'ghost', '👽':'alien',
      '🤖':'robot', '👍':'thumbs up', '👎':'thumbs down', '👏':'clap',
      '🙏':'pray', '💪':'strong', '💬':'speech', '📌':'pin'
    };
    picker.innerHTML = '';
    const search = document.createElement('input');
    search.type = 'search';
    search.className = 'compose-emoji-search';
    search.placeholder = 'Search emoji';
    search.setAttribute('aria-label', 'Search emoji');
    picker.appendChild(search);
    const grid = document.createElement('div');
    grid.style.display = 'contents';
    picker.appendChild(grid);
    const buttons = [];
    list.forEach((emo) => {
      const b = document.createElement('button');
      b.type = 'button';
      b.setAttribute('role', 'option');
      b.title = emo;
      b.dataset.search = (emo + ' ' + (names[emo] || '')).toLowerCase();
      b.textContent = emo;
      b.addEventListener('click', () => {
        const start = ta.selectionStart ?? ta.value.length;
        const end = ta.selectionEnd ?? ta.value.length;
        const before = ta.value.slice(0, start);
        const after = ta.value.slice(end);
        ta.value = before + emo + after;
        const pos = start + emo.length;
        ta.focus();
        try { ta.setSelectionRange(pos, pos); } catch (_) {}
        ta.dispatchEvent(new Event('input', { bubbles: true }));
      });
      grid.appendChild(b);
      buttons.push(b);
    });
    search.addEventListener('input', () => {
      const q = search.value.trim().toLowerCase();
      buttons.forEach((b) => { b.hidden = !!q && !b.dataset.search.includes(q); });
    });
    toggle.addEventListener('click', () => {
      const open = picker.hidden;
      picker.hidden = !open;
      toggle.setAttribute('aria-expanded', open ? 'true' : 'false');
      if (open) search.focus();
    });
    document.addEventListener('click', (event) => {
      if (picker.hidden) return;
      if (event.target === picker || picker.contains(event.target) || event.target === toggle) return;
      picker.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
    });
    document.addEventListener('keydown', (event) => {
      if (event.key !== 'Escape' || picker.hidden) return;
      picker.hidden = true;
      toggle.setAttribute('aria-expanded', 'false');
      toggle.focus();
    });
  })();
  function updateAltCount() {
    if (!altCount || !altText) return;
    altCount.textContent = (altText.value || '').length + ' / 1500';
  }
  function setAltAiStatus(msg, isErr) {
    if (!altAiStatus) return;
    altAiStatus.textContent = msg || '';
    altAiStatus.style.color = isErr ? 'var(--danger)' : 'var(--muted)';
  }
  function openAltEditor(idx) {
    if (!altModal || !altText || !altPreview || idx < 0 || idx >= files.length) return;
    altEditIdx = idx;
    const file = files[idx];
    const url = URL.createObjectURL(file);
    altPreview.innerHTML = '';
    const isVideo = file.type.startsWith('video/');
    const isAudio = file.type.startsWith('audio/');
    if (isVideo) {
      const v = document.createElement('video');
      v.className = 'alt-modal__preview';
      v.src = url;
      v.controls = true;
      v.muted = true;
      altPreview.appendChild(v);
    } else if (isAudio) {
      const a = document.createElement('audio');
      a.className = 'alt-modal__preview';
      a.src = url;
      a.controls = true;
      altPreview.appendChild(a);
    } else {
      const img = document.createElement('img');
      img.className = 'alt-modal__preview';
      img.src = url;
      img.alt = '';
      altPreview.appendChild(img);
    }
    altText.value = alts[idx] || '';
    updateAltCount();
    setAltAiStatus('');
    if (altAiBtn) {
      altAiBtn.style.display = (isVideo || isAudio) ? 'none' : '';
      altAiBtn.disabled = false;
    }
    altModal.classList.add('open');
    altModal.setAttribute('aria-hidden', 'false');
    setTimeout(() => altText.focus(), 40);
  }
  function closeAltEditor(save) {
    if (!altModal) return;
    if (altAiBusy) return;
    if (save && altEditIdx >= 0) {
      alts[altEditIdx] = (altText.value || '').trim();
    }
    altEditIdx = -1;
    altModal.classList.remove('open');
    altModal.setAttribute('aria-hidden', 'true');
    altPreview.innerHTML = '';
    setAltAiStatus('');
    render();
  }
  async function generateAltWithAi() {
    if (altAiBusy || altEditIdx < 0 || altEditIdx >= files.length) return;
    const file = files[altEditIdx];
    if (!file || !file.type.startsWith('image/')) {
      setAltAiStatus('AI describe works on images only (not video).', true);
      return;
    }
    altAiBusy = true;
    if (altAiBtn) altAiBtn.disabled = true;
    setAltAiStatus('Generating with Grok…');
    try {
      const fd = new FormData();
      fd.set('action', 'ai_alt_text');
      fd.set('ajax', '1');
      if (window.vaakCsrfApply) window.vaakCsrfApply(fd);
      else if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
      fd.append('image', file, file.name || 'image.jpg');
      const res = await fetch('?view=home&op=ai_alt_text', {
        method: 'POST',
        body: fd,
        credentials: 'same-origin',
        headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
      });
      const data = await res.json().catch(() => null);
      if (!data || !data.ok || !data.alt) {
        setAltAiStatus((data && data.error) || 'AI describe failed.', true);
        return;
      }
      if (altText) {
        altText.value = data.alt;
        updateAltCount();
        altText.focus();
      }
      setAltAiStatus('Draft ready — edit if needed, then Save.');
    } catch (e) {
      setAltAiStatus('Network error — try again.', true);
    } finally {
      altAiBusy = false;
      if (altAiBtn) altAiBtn.disabled = false;
    }
  }
  if (fab) fab.addEventListener('click', () => {
    if (supportsInlineComposer && isComposerInline()) {
      const target = document.querySelector('.compose-inline-panel');
      if (target) target.scrollIntoView({ behavior: 'smooth', block: 'start' });
      const ta = document.getElementById('compose-content');
      if (ta) setTimeout(() => ta.focus(), 260);
      return;
    }
    openModal();
  });
  if (closeBtn) closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (e) => { if (e.target === modal) closeModal(); });
  // Keep textarea resize inside its flex slot (avoids modal/form scrollbars)
  (function bindComposeResizeClamp() {
    const ta = document.getElementById('compose-content');
    if (!ta) return;
    const clampSoon = () => requestAnimationFrame(() => {
      clampComposeTextarea();
      autoGrowComposeTextarea();
    });
    ta.addEventListener('input', autoGrowComposeTextarea);
    ta.addEventListener('mouseup', clampSoon);
    ta.addEventListener('pointerup', clampSoon);
    ta.addEventListener('blur', autoGrowComposeTextarea);
    window.addEventListener('resize', () => {
      clampComposeTextarea();
      autoGrowComposeTextarea();
    });
    if (typeof ResizeObserver !== 'undefined') {
      let clamping = false;
      new ResizeObserver(() => {
        if (clamping) return;
        clamping = true;
        clampComposeTextarea();
        autoGrowComposeTextarea();
        clamping = false;
      }).observe(ta);
    }
    autoGrowComposeTextarea();
  })();
  if (altSave) altSave.addEventListener('click', () => closeAltEditor(true));
  if (altCancel) altCancel.addEventListener('click', () => closeAltEditor(false));
  if (altClose) altClose.addEventListener('click', () => closeAltEditor(false));
  if (altAiBtn) altAiBtn.addEventListener('click', () => { generateAltWithAi(); });
  if (altModal) {
    altModal.addEventListener('click', (e) => { if (e.target === altModal) closeAltEditor(false); });
  }
  if (altText) altText.addEventListener('input', updateAltCount);
  document.addEventListener('keydown', (e) => {
    if (e.key !== 'Escape') return;
    if (altModal && altModal.classList.contains('open')) {
      e.stopPropagation();
      closeAltEditor(false);
      return;
    }
    if (modal.classList.contains('open')) closeModal();
  });
  function syncInput() {
    const dt = new DataTransfer();
    files.forEach((f) => dt.items.add(f));
    input.files = dt.files;
  }
  function altButtonLabel(text) {
    const t = (text || '').trim();
    if (!t) return 'Add alt text';
    return t.length > 18 ? ('Alt: ' + t.slice(0, 16) + '…') : ('Alt: ' + t);
  }
  function render() {
    previews.innerHTML = '';
    while (alts.length < files.length) alts.push('');
    if (alts.length > files.length) alts.length = files.length;
    files.forEach((file, idx) => {
      const card = document.createElement('div');
      card.className = 'compose-media-card';
      const url = URL.createObjectURL(file);
      if (file.type.startsWith('video/')) {
        card.innerHTML = '<video src="' + url + '" muted></video>';
      } else if (file.type.startsWith('audio/')) {
        card.innerHTML = '<audio src="' + url + '" controls></audio>';
      } else {
        card.innerHTML = '<img src="' + url + '" alt="">';
      }
      const hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = 'media_alt[' + idx + ']';
      hidden.value = alts[idx] || '';
      const altBtn = document.createElement('button');
      altBtn.type = 'button';
      altBtn.className = 'alt-btn' + ((alts[idx] || '').trim() ? ' has-alt' : '');
      altBtn.textContent = altButtonLabel(alts[idx]);
      altBtn.title = (alts[idx] || '').trim() || 'Write alt text';
      altBtn.addEventListener('click', () => openAltEditor(idx));
      const rm = document.createElement('button');
      rm.type = 'button';
      rm.className = 'rm';
      rm.textContent = 'Remove';
      rm.addEventListener('click', () => {
        files.splice(idx, 1);
        alts.splice(idx, 1);
        syncInput();
        render();
      });
      card.appendChild(hidden);
      card.appendChild(altBtn);
      card.appendChild(rm);
      previews.appendChild(card);
    });
    const hint = document.getElementById('compose-media-hint');
    if (hint) hint.textContent = files.length ? (files.length + ' / ' + MAX) : '';
  }
  input.addEventListener('change', () => {
    const picked = Array.from(input.files || []);
    const existingCount = ((draftMediaField && draftMediaField.value) || '')
      .split(/[\s,]+/).filter((x) => parseInt(x, 10) > 0).length;
    for (const f of picked) {
      if (files.length + existingCount >= MAX) {
        if (window.apAdminToast) window.apAdminToast('Too many media attachments (max ' + MAX + ').', true);
        break;
      }
      const lim = mediaLimitError(f);
      if (lim) {
        if (window.apAdminToast) window.apAdminToast(lim, true);
        continue;
      }
      files.push(f);
      alts.push('');
    }
    // Clear the native input so the same file can be re-picked; we own `files`.
    try { input.value = ''; } catch (e) {}
    syncInput();
    render();
  });
  let composeMode = <?= $composeIsEdit ? "'edit_status'" : "'reply'" ?>; // reply | queue_post | edit_status
  const actionField = document.getElementById('compose-action');
  const returnField = document.getElementById('compose-return-view');
  const noteIdField = document.getElementById('compose-note-id');
  const queueBtn = document.getElementById('compose-queue-btn');
  const mediaInput = document.getElementById('compose-media-input');
  const mediaHint = document.getElementById('compose-media-hint');
  const submitProgress = document.getElementById('compose-submit-progress');
  const submitProgressBar = document.getElementById('compose-submit-progress-bar');
  const submitProgressLabel = document.getElementById('compose-submit-progress-label');
  const postAudio = new Audio('/api/assets/post.wav?v=1');
  postAudio.preload = 'auto';
  postAudio.volume = 0.9;
  postAudio.setAttribute('playsinline', '');
  let postAudioUnlocked = false;
  function unlockPostAudio() {
    if (postAudioUnlocked) return;
    try {
      postAudio.muted = true;
      postAudio.currentTime = 0;
      const p = postAudio.play();
      if (p && typeof p.then === 'function') p.then(() => {
        postAudio.pause(); postAudio.currentTime = 0; postAudio.muted = false; postAudioUnlocked = true;
      }).catch(() => { postAudio.muted = false; });
    } catch (e) { postAudio.muted = false; }
  }
  function playPostAudio() {
    try {
      postAudio.currentTime = 0;
      const p = postAudio.play();
      if (p && typeof p.catch === 'function') p.catch(() => {});
    } catch (e) {}
  }
  const visibilityWrap = document.getElementById('compose-visibility')
    ? document.getElementById('compose-visibility').closest('label')
    : null;
  function setSubmitProgress(state, percent) {
    if (!submitProgress) return;
    const active = state !== 'hidden';
    submitProgress.classList.toggle('is-visible', active);
    submitProgress.classList.toggle('is-indeterminate', state === 'finalizing' || state === 'posting' || state === 'processing');
    if (submitProgressBar) {
      submitProgressBar.value = Math.max(0, Math.min(100, Number(percent) || 0));
    }
    if (submitProgressLabel) {
      submitProgressLabel.textContent = state === 'uploading'
        ? ('Uploading media… ' + Math.round(Number(percent) || 0) + '%')
        : (state === 'processing'
          ? 'Processing video on server…'
          : (state === 'finalizing' ? 'Media uploaded — posting…' : (state === 'posting' ? 'Posting…' : '')));
    }
  }
  async function uploadOneComposeFile(file, altText, onProgress) {
    const fd = new FormData();
    fd.set('action', 'upload_media');
    fd.set('ajax', '1');
    fd.set('file', file, file.name || 'upload.bin');
    if (altText) fd.set('description', altText);
    if (window.vaakCsrfApply) window.vaakCsrfApply(fd);
    else if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
    return submitComposeRequest(form.getAttribute('action') || '?view=outbox', fd, onProgress);
  }
  async function preUploadPendingComposeFiles() {
    if (!files.length) return { ok: true };
    const existing = ((draftMediaField && draftMediaField.value) || '')
      .split(/[\s,]+/).map((x) => parseInt(x, 10)).filter((n) => n > 0);
    if (existing.length + files.length > MAX) {
      return { ok: false, error: 'Too many media attachments (max ' + MAX + ').' };
    }
    const ids = existing.slice();
    for (let i = 0; i < files.length; i++) {
      const f = files[i];
      const lim = mediaLimitError(f);
      if (lim) return { ok: false, error: lim };
      setSubmitProgress('uploading', Math.round((i / Math.max(files.length, 1)) * 100));
      const data = await uploadOneComposeFile(f, alts[i] || '', (pct) => {
        const base = (i / Math.max(files.length, 1)) * 100;
        const slice = (1 / Math.max(files.length, 1)) * 100;
        setSubmitProgress('uploading', base + (pct / 100) * slice);
      });
      if (!data || !data.ok || !data.media_id) {
        return { ok: false, error: (data && data.error) || 'Media upload failed.' };
      }
      ids.push(Number(data.media_id));
      setSubmitProgress('processing', 100);
    }
    if (draftMediaField) draftMediaField.value = ids.join(',');
    files = [];
    alts = [];
    syncInput();
    render();
    return { ok: true, media_ids: ids };
  }
  function submitComposeRequest(url, fd, onProgress) {
    return new Promise((resolve, reject) => {
      const xhr = new XMLHttpRequest();
      xhr.open('POST', url, true);
      xhr.withCredentials = true;
      xhr.setRequestHeader('Accept', 'application/json');
      xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      xhr.upload.addEventListener('progress', (event) => {
        if (event.lengthComputable && typeof onProgress === 'function') {
          onProgress((event.loaded / event.total) * 100);
        }
      });
      xhr.addEventListener('load', () => {
        let data = null;
        try { data = JSON.parse(xhr.responseText || ''); } catch (_) {}
        if (xhr.status >= 200 && xhr.status < 300) resolve(data);
        else resolve(data || { ok: false, error: 'Upload failed (HTTP ' + xhr.status + ').' });
      });
      xhr.addEventListener('error', () => reject(new Error('network')));
      xhr.addEventListener('timeout', () => reject(new Error('timeout')));
      xhr.timeout = 0;
      xhr.send(fd);
    });
  }
  // PHP-prefilled edit: keep mode sticky so AJAX save hits edit_status
  if (composeMode === 'edit_status' && actionField) {
    actionField.value = 'edit_status';
  }

  function resetComposeChrome() {
    composeMode = 'reply';
    if (actionField) actionField.value = 'reply';
    const title = document.getElementById('compose-modal-title');
    const submitBtn = document.getElementById('compose-submit-btn');
    if (title) title.textContent = 'Compose';
    if (submitBtn) submitBtn.textContent = 'Post';
    if (noteIdField) noteIdField.value = '';
    if (queueBtn) queueBtn.style.display = '';
    if (draftBtn) draftBtn.style.display = '';
    if (mediaInput) {
      const lab = mediaInput.closest('label');
      if (lab) lab.style.display = '';
    }
    if (mediaHint) {
      mediaHint.style.display = '';
      mediaHint.textContent = '';
    }
    if (visibilityWrap) visibilityWrap.style.display = '';
    const replyTo = document.getElementById('compose-in-reply-to');
    const toActor = document.getElementById('compose-to-actor');
    if (replyTo) {
      replyTo.style.display = '';
      if (replyTo.parentElement) replyTo.parentElement.style.display = '';
    }
    if (toActor && toActor.tagName === 'INPUT' && toActor.type !== 'hidden') {
      toActor.style.display = '';
      if (toActor.parentElement) toActor.parentElement.style.display = '';
    }
  }
  window.__apResetComposeChrome = resetComposeChrome;

  function b64ToUtf8(b64) {
    try {
      const bin = atob(b64 || '');
      const bytes = Uint8Array.from(bin, (c) => c.charCodeAt(0));
      return new TextDecoder('utf-8').decode(bytes);
    } catch (e) {
      try { return decodeURIComponent(escape(atob(b64 || ''))); } catch (e2) { return ''; }
    }
  }

  function applyEditChrome(noteId, returnView) {
    resetComposeChrome();
    composeMode = 'edit_status';
    if (actionField) actionField.value = 'edit_status';
    if (noteIdField) noteIdField.value = noteId || '';
    if (returnField) returnField.value = returnView || 'outbox';
    const title = document.getElementById('compose-modal-title');
    const submitBtn = document.getElementById('compose-submit-btn');
    if (title) title.textContent = 'Edit post';
    if (submitBtn) submitBtn.textContent = 'Save edit';
    // Keep media as-is on edit (API keeps existing attachments); hide new uploads for now
    if (queueBtn) queueBtn.style.display = 'none';
    if (draftBtn) draftBtn.style.display = 'none';
    if (draftIdField) draftIdField.value = '';
    if (draftMediaField) draftMediaField.value = '';
    if (mediaInput) {
      const lab = mediaInput.closest('label');
      if (lab) lab.style.display = 'none';
    }
    if (mediaHint) mediaHint.textContent = 'Media stays attached; text/CW edit only for now';
    if (visibilityWrap) visibilityWrap.style.display = 'none';
    const replyTo = document.getElementById('compose-in-reply-to');
    if (replyTo) {
      replyTo.value = '';
      if (replyTo.parentElement) replyTo.parentElement.style.display = 'none';
      else replyTo.style.display = 'none';
    }
    const toActor = document.getElementById('compose-to-actor');
    if (toActor && toActor.type !== 'hidden') {
      toActor.value = '';
      toActor.style.display = 'none';
    }
  }

  function fillEditFields(content, spoilerText, sensitive) {
    const ta = document.getElementById('compose-content');
    if (ta) ta.value = content || '';
    const spoiler = form.querySelector('input[name="spoiler_text"]');
    if (spoiler) spoiler.value = spoilerText || '';
    const sens = form.querySelector('input[name="sensitive"]');
    if (sens) sens.checked = !!sensitive;
  }

  function openEditComposer(btn) {
    const noteId = btn.getAttribute('data-note-id') || '';
    const returnView = btn.getAttribute('data-return-view') || 'outbox';
    applyEditChrome(noteId, returnView);
    // Instant fallback from data attrs while we fetch fresh text from DB
    let content = '';
    const b64 = btn.getAttribute('data-content-b64');
    if (b64) content = b64ToUtf8(b64);
    else content = btn.getAttribute('data-content') || '';
    let spoilerText = '';
    const sb64 = btn.getAttribute('data-spoiler-b64');
    if (sb64) spoilerText = b64ToUtf8(sb64);
    else spoilerText = btn.getAttribute('data-spoiler') || '';
    const sensitive = btn.getAttribute('data-sensitive') === '1';
    fillEditFields(content, spoilerText, sensitive);
    openModal();
    const ta = document.getElementById('compose-content');
    if (ta) setTimeout(() => ta.focus(), 50);

    if (!noteId) return;
    // Authoritative load: masto_statuses.content_text (blank lines intact)
    fetch('?op=edit_draft&note_id=' + encodeURIComponent(noteId), {
      credentials: 'same-origin',
      headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      cache: 'no-store'
    }).then((res) => res.json()).then((data) => {
      if (!data || !data.ok) return;
      // Only apply if still editing this note
      if (composeMode !== 'edit_status') return;
      if (noteIdField && noteIdField.value !== noteId) return;
      fillEditFields(data.content || '', data.spoiler_text || '', !!data.sensitive);
    }).catch(() => { /* keep attribute fallback */ });
  }

  document.addEventListener('click', (ev) => {
    const btn = ev.target && ev.target.closest ? ev.target.closest('.js-edit-post') : null;
    if (!btn) return;
    ev.preventDefault();
    openEditComposer(btn);
  });

  if (queueBtn) {
    queueBtn.addEventListener('click', () => {
      composeMode = 'queue_post';
      if (actionField) actionField.value = 'queue_post';
      if (returnField) returnField.value = 'queue';
      form.requestSubmit();
    });
  }
  if (draftBtn) {
    draftBtn.addEventListener('click', async () => {
      const res = await saveComposeDraft({ silent: false });
      if (res && res.ok) {
        // Keep composer open with draft id set so further edits update the same draft
        const title = document.getElementById('compose-modal-title');
        if (title && draftIdField && draftIdField.value) {
          title.textContent = 'Edit draft';
        }
      }
    });
  }
  form.addEventListener('submit', async (ev) => {
    ev.preventDefault();
    syncInput();
    if (form.dataset.busy === '1') return;
    form.dataset.busy = '1';
    const submitBtn = document.getElementById('compose-submit-btn') || form.querySelector('button[type="submit"]');
    unlockPostAudio();
    const mode = composeMode;
    const actionName = mode === 'queue_post' ? 'queue_post' : (mode === 'edit_status' ? 'edit_status' : 'reply');
    if (actionField) actionField.value = actionName;
    if (returnField && mode !== 'queue_post') {
      returnField.value = <?= json_encode($composerReturnView) ?>;
    }
    if (submitBtn) submitBtn.disabled = true;
    if (queueBtn) queueBtn.disabled = true;
    if (draftBtn) draftBtn.disabled = true;
    try {
      // Pre-upload local files so the publish POST stays small (avoids mobile
      // “session expired” when PHP empties a huge multipart body).
      if (files.length && mode !== 'edit_status') {
        const up = await preUploadPendingComposeFiles();
        if (!up.ok) {
          if (window.apAdminToast) window.apAdminToast(up.error || 'Media upload failed.', true);
          else alert(up.error || 'Media upload failed.');
          return;
        }
      }
      const fd = new FormData(form);
      fd.set('ajax', '1');
      fd.set('action', actionName);
      if (window.vaakCsrfApply) window.vaakCsrfApply(fd);
      else if (window.VAAK_CSRF) fd.set('csrf', window.VAAK_CSRF);
      // Drop any leftover file inputs — media already lives in draft_media_ids.
      fd.delete('media[]');
      if (draftMediaField) fd.set('draft_media_ids', draftMediaField.value || '');
      const hadMedia = !!(((draftMediaField && draftMediaField.value) || '').trim());
      setSubmitProgress(hadMedia ? 'finalizing' : 'posting', hadMedia ? 100 : 0);
      const data = await submitComposeRequest(form.getAttribute('action') || '?view=outbox', fd, null);
      setSubmitProgress(hadMedia ? 'finalizing' : 'posting', 100);
      if (!data || !data.ok) {
        const failMsg = mode === 'queue_post' ? 'Queue failed.' : (mode === 'edit_status' ? 'Edit failed.' : 'Post failed.');
        if (window.apAdminToast) {
          window.apAdminToast((data && (data.error || data.notice)) || failMsg, true);
        } else {
          alert((data && data.error) || failMsg);
        }
        // Keep text + already-uploaded media_ids for retry.
        return;
      }
      if (window.apAdminToast) {
        const okMsg = mode === 'queue_post'
          ? 'Added to queue.'
          : (mode === 'edit_status' ? 'Post updated.' : (data.kind === 'quote' ? 'Quote posted.' : 'Posted.'));
        window.apAdminToast(data.notice || okMsg);
      }
      if (mode === 'reply') playPostAudio();
      // Posted/queued — don't re-save as draft on close
      skipDraftOnClose = true;
      if (draftIdField) draftIdField.value = '';
      if (draftMediaField) draftMediaField.value = '';
      await closeModal();
      resetComposeChrome();
      // Clear compose state for next open (strip quote prefills via soft reset)
      const ta = document.getElementById('compose-content');
      if (ta) ta.value = '';
      const spoiler = form.querySelector('input[name="spoiler_text"]');
      if (spoiler) spoiler.value = '';
      const sens = form.querySelector('input[name="sensitive"]');
      if (sens) sens.checked = false;
      files = [];
      alts = [];
      syncInput();
      render();
      const quoteField = form.querySelector('input[name="quote_object"]');
      if (quoteField) {
        // After a successful quote, drop quote UI so next compose is a normal post
        quoteField.remove();
        const title = document.getElementById('compose-modal-title');
        if (title) title.textContent = 'Compose';
        if (submitBtn) submitBtn.textContent = 'Post';
        const qb = form.querySelector('.quote-block');
        if (qb) qb.remove();
      }
      const replyTo = document.getElementById('compose-in-reply-to');
      const toActor = document.getElementById('compose-to-actor');
      if (replyTo) { replyTo.value = ''; replyTo.style.display = ''; }
      if (toActor) { toActor.value = ''; if (toActor.type !== 'hidden') toActor.style.display = ''; }
      // Drop compose/quote/reply query flags so refresh doesn't reopen the modal
      try {
        const u = new URL(window.location.href);
        ['compose', 'quote_object', 'quote_status_id', 'reply_to', 'to', 'edit_note', 'draft_id'].forEach((k) => u.searchParams.delete(k));
        window.history.replaceState({}, '', u.pathname + u.search + u.hash);
      } catch (e) {}
      if (mode === 'queue_post') {
        window.location.href = '?view=queue';
        return;
      }
      // Soft-insert own post into the live timeline (home newer-poll now includes
      // outbox_notes). Scroll to top so the card is visible without Refresh.
      if (mode !== 'edit_status' && typeof window.novaPollTimeline === 'function') {
        await window.novaPollTimeline();
        if (typeof window.novaInsertPendingTimeline === 'function') {
          window.novaInsertPendingTimeline({ scrollToTop: true });
        }
      } else {
        window.location.reload();
      }
    } catch (err) {
      if (window.apAdminToast) window.apAdminToast('Upload or network error — try again.', true);
      else alert('Upload or network error — try again.');
    } finally {
      composeMode = 'reply';
      if (actionField) actionField.value = 'reply';
      form.dataset.busy = '0';
      if (submitBtn) submitBtn.disabled = false;
      if (queueBtn) queueBtn.disabled = false;
      if (draftBtn) draftBtn.disabled = false;
      setSubmitProgress('hidden', 0);
    }
  });
})();
</script>
<?php endif; ?>

<?php if (in_array($view, ['guestbook', 'support', 'analytics'], true)): ?>
<script>
const VIEW = <?= json_encode($view) ?>;
let allGuestbookEntries = [];

function esc(s) {
  return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
function fmtDate(v) {
  try {
    const d = typeof v === 'number' ? new Date(v > 1e12 ? v : v * 1000) : new Date(v);
    return isNaN(d) ? String(v) : d.toLocaleString();
  } catch { return String(v); }
}
async function fetchJson(url, opts = {}) {
  const res = await fetch(url, { credentials: 'same-origin', ...opts });
  if (!res.ok) throw new Error('HTTP ' + res.status);
  return res.json();
}

function displayGuestbook(entries) {
  const el = document.getElementById('gb-list');
  if (!entries.length) { el.innerHTML = '<div class="empty">No entries.</div>'; return; }
  el.innerHTML = entries.map(e => `
    <div class="gb-item" data-id="${esc(e.id)}">
      <div class="gb-hd">
        <div><strong>${esc(e.name || 'Anonymous')}</strong> <span class="meta">${esc(e.email || '')}</span></div>
        <div class="meta">${esc(fmtDate(e.timestamp || e.date))}</div>
      </div>
      <div class="body">${esc(e.message || '')}</div>
      <div class="tweet-actions">
        <button type="button" class="danger" onclick="deleteGuestbook(${Number(e.id)})">Delete</button>
      </div>
    </div>`).join('');
}
function filterGuestbook() {
  const q = (document.getElementById('gb-search').value || '').toLowerCase();
  if (!q) return displayGuestbook(allGuestbookEntries);
  displayGuestbook(allGuestbookEntries.filter(e =>
    (e.name||'').toLowerCase().includes(q) ||
    (e.email||'').toLowerCase().includes(q) ||
    (e.message||'').toLowerCase().includes(q)
  ));
}
async function loadGuestbook() {
  try {
    const data = await fetchJson('/admin/guestbook');
    allGuestbookEntries = data.entries || [];
    if (data.stats) {
      document.getElementById('gb-total').textContent = data.stats.total ?? '0';
      document.getElementById('gb-recent').textContent = data.stats.recent ?? '0';
      document.getElementById('gb-updated').textContent = data.stats.lastUpdated || '—';
    }
    displayGuestbook(allGuestbookEntries);
  } catch (err) {
    document.getElementById('gb-list').innerHTML = '<div class="empty danger">Failed to load guestbook.</div>';
  }
}
async function deleteGuestbook(id) {
  if (!confirm('Delete guestbook entry #' + id + '?')) return;
  try {
    const data = await fetchJson('/admin/guestbook/' + id, { method: 'DELETE' });
    if (data.success) {
      allGuestbookEntries = allGuestbookEntries.filter(e => Number(e.id) !== Number(id));
      displayGuestbook(allGuestbookEntries);
    } else alert(data.error || 'Delete failed');
  } catch { alert('Delete failed'); }
}

async function loadSupport() {
  try {
    const data = await fetchJson('/api/stripe/admin/stats');
    if (data.error) throw new Error(data.error);
    document.getElementById('sup-month').textContent = (data.total_month ?? 0).toFixed(2);
    document.getElementById('sup-subs').textContent = data.active_subscriptions ?? '0';
    document.getElementById('sup-mrr').textContent = (data.monthly_recurring ?? 0).toFixed(2);
    document.getElementById('sup-donations').textContent = data.recent_donations ?? '0';
    const acts = data.recent_activity || [];
    document.getElementById('sup-activity').innerHTML = acts.length ? acts.map(a => `
      <div class="fin-row">
        <div><strong>${esc(a.type === 'subscription' ? 'Subscription' : 'Donation')}</strong><br><span class="meta">${esc(a.customer_email || '')}</span></div>
        <div>$${(a.amount || 0).toFixed(2)}</div>
        <div class="meta">${esc(fmtDate(a.date))}</div>
      </div>`).join('') : '<div class="empty">No recent activity.</div>';
    const subs = data.subscriptions || [];
    document.getElementById('sup-subs-list').innerHTML = subs.length ? subs.map(s => `
      <div class="fin-row">
        <div><strong>${esc(s.customer_email || 'Unknown')}</strong><br><span class="meta">${esc(s.plan_name || '')}</span></div>
        <div>$${(s.amount || 0).toFixed(2)}/mo</div>
        <div class="meta">${esc(s.status || '')}</div>
      </div>`).join('') : '<div class="empty">No subscriptions.</div>';
  } catch {
    document.getElementById('sup-activity').innerHTML = '<div class="empty danger">Failed to load Stripe stats (auth required).</div>';
  }
}

async function loadAnalytics() {
  try {
    const data = await fetchJson('/api/analytics.php');
    if (!data.success && data.error) throw new Error(data.error);
    document.getElementById('an-today').textContent = (data.visitors?.today ?? 0).toLocaleString();
    document.getElementById('an-week').textContent = (data.visitors?.week ?? 0).toLocaleString();
    document.getElementById('an-views-week').textContent = (data.page_views?.week ?? 0).toLocaleString();
    document.getElementById('an-views-month').textContent = (data.page_views?.month ?? 0).toLocaleString();
    document.getElementById('an-mobile').textContent = (data.devices?.mobile_percent ?? 0) + '%';
    document.getElementById('an-desktop').textContent = (data.devices?.desktop_percent ?? 0) + '%';
    const pages = data.popular_pages || [];
    document.getElementById('an-pages').innerHTML = pages.length ? pages.map(p => `
      <div class="fin-row"><div>${esc(p.title || p.path || p.page || '—')}</div><div>${esc(String(p.views ?? p.count ?? ''))}</div></div>
    `).join('') : '<div class="empty">No page data.</div>';
    const refs = data.referrers || [];
    document.getElementById('an-refs').innerHTML = refs.length ? refs.map(r => `
      <div class="fin-row"><div class="mono">${esc(r.source || r.referrer || r.url || '—')}</div><div>${esc(String(r.count ?? r.views ?? ''))}</div></div>
    `).join('') : '<div class="empty">No referrer data.</div>';
    const browsers = data.browsers || [];
    document.getElementById('an-browsers').innerHTML = browsers.length ? browsers.map(b => `
      <div class="fin-row"><div>${esc(b.name || b.browser || '—')}</div><div>${esc(String(b.count ?? b.percent ?? ''))}</div></div>
    `).join('') : '<div class="empty">No browser data.</div>';
  } catch {
    document.getElementById('an-pages').innerHTML = '<div class="empty danger">Failed to load analytics.</div>';
  }
}

if (VIEW === 'guestbook') loadGuestbook();
if (VIEW === 'support') loadSupport();
if (VIEW === 'analytics') loadAnalytics();
</script>
<?php endif; ?>
<script>
// Small, opt-in keyboard layer for fast timeline navigation (X-style, but
// limited to familiar actions and disabled while typing in a form control).
(function () {
  let selected = -1;
  function cards() { return Array.from(document.querySelectorAll('#timeline-items article.tweet')); }
  document.addEventListener('keydown', function (ev) {
    const el = ev.target;
    if (el && (el.matches('input, textarea, select, button, [contenteditable="true"]'))) return;
    if (ev.key === 'n') {
      const fab = document.getElementById('compose-fab');
      if (fab) { ev.preventDefault(); fab.click(); }
      return;
    }
    if (ev.key === '/') {
      const search = document.querySelector('input[name="q"][type="search"], input[placeholder*="@user@instance"]');
      if (search) { ev.preventDefault(); search.focus(); }
      return;
    }
    if (ev.key !== 'j' && ev.key !== 'k') return;
    const list = cards();
    if (!list.length) return;
    ev.preventDefault();
    selected = Math.max(0, Math.min(list.length - 1, selected + (ev.key === 'j' ? 1 : -1)));
    list.forEach((card, i) => card.classList.toggle('keyboard-selected', i === selected));
    list[selected].scrollIntoView({ block: 'center', behavior: 'smooth' });
  });
  document.addEventListener('keydown', function (ev) {
    if (ev.key !== 'r' || selected < 0) return;
    const list = cards();
    const card = list[selected];
    if (!card) return;
    const reply = card.querySelector('a[title="Reply"], a[aria-label="Reply"]');
    if (reply) { ev.preventDefault(); reply.click(); }
  });
})();
</script>
</body>
</html>
<?php

/**
 * @return array{ok:bool,error?:string,create_id?:string,delivered?:int,queued?:int}
 */
function ap_local_post_reply(
    string $content,
    string $inReplyTo,
    ?string $toActor,
    string $spoilerText = '',
    ?bool $sensitive = null,
    ?string $quoteObjectId = null,
    array $mediaLocalIds = [],
    string $visibility = 'public'
): array {
    $localActor = function_exists('ap_local_actor_id') ? ap_local_actor_id() : LOCAL_ACTOR;
    $replyTo = ($inReplyTo === ''
        || $inReplyTo === LOCAL_ACTOR
        || $inReplyTo === $localActor
        || (function_exists('vaak_actor_id') && $inReplyTo === vaak_actor_id())
    ) ? null : $inReplyTo;
    return ap_publish_status_text(
        $content,
        $replyTo,
        $toActor,
        $mediaLocalIds,
        $spoilerText,
        $sensitive,
        $quoteObjectId,
        null,
        $visibility
    );
}

/**
 * Collect up to 4 uploaded media files (+ optional alt texts) for compose.
 *
 * @return array{ids:list<int>,error:?string}
 */
function ap_admin_collect_media_uploads(): array
{
    $alts = $_POST['media_alt'] ?? [];
    if (!is_array($alts)) {
        $alts = [];
    }
    $files = $_FILES['media'] ?? null;
    if (!is_array($files) || !isset($files['name'])) {
        return ['ids' => [], 'error' => null];
    }
    // Normalize single-file → multi-file shape
    if (!is_array($files['name'])) {
        $files = [
            'name' => [$files['name']],
            'type' => [$files['type'] ?? ''],
            'tmp_name' => [$files['tmp_name'] ?? ''],
            'error' => [$files['error'] ?? UPLOAD_ERR_NO_FILE],
            'size' => [$files['size'] ?? 0],
        ];
    }
    $count = count($files['name']);
    if ($count > 4) {
        return ['ids' => [], 'error' => 'Too many media attachments (max 4)'];
    }
    $ids = [];
    for ($i = 0; $i < $count; $i++) {
        $err = (int) ($files['error'][$i] ?? UPLOAD_ERR_NO_FILE);
        if ($err === UPLOAD_ERR_NO_FILE) {
            continue;
        }
        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
            return ['ids' => [], 'error' => 'File too large for the server (video max 50MB, images 10MB, audio 20MB).'];
        }
        if ($err === UPLOAD_ERR_PARTIAL) {
            return ['ids' => [], 'error' => 'Upload was interrupted — try again on Wi‑Fi.'];
        }
        $file = [
            'name' => (string) ($files['name'][$i] ?? ''),
            'type' => (string) ($files['type'][$i] ?? ''),
            'tmp_name' => (string) ($files['tmp_name'][$i] ?? ''),
            'error' => $err,
            'size' => (int) ($files['size'][$i] ?? 0),
        ];
        $alt = isset($alts[$i]) ? (string) $alts[$i] : null;
        $up = ap_media_ingest_upload($file, $alt);
        if (empty($up['ok'])) {
            return ['ids' => [], 'error' => $up['error'] ?? 'Media upload failed'];
        }
        $ids[] = (int) ($up['local_id'] ?? 0);
    }
    $ids = array_values(array_filter($ids, static fn($id) => $id > 0));
    return ['ids' => $ids, 'error' => null];
}

// ap_cmdr_publish_profile_update() / ap_publish_status_text() live in ap-inbox.php
