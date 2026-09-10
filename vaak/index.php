<?php
/**
 * VAAK front controller — https://mkultra.monster/vaak/
 * Logged out: ASCII-V login / invite registration.
 * Logged in: boots ap-admin.php (same app, session-gated).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/ap-auth.php';
require_once dirname(__DIR__) . '/api/ap-version.php';

ap_auth_bootstrap();
ap_auth_start_session();

/**
 * Small, unauthenticated preview of the public timeline for the login page.
 * Keep this deliberately separate from the authenticated timeline builders:
 * only rows explicitly marked public are eligible, and no media is fetched.
 *
 * @return list<array{actor:string,acct:string,content:string,created_at:string}>
 */
function vaak_login_public_feed(int $limit = 14): array
{
    $limit = max(1, min(24, $limit));
    $db = ap_db();
    $items = [];

    try {
        $st = $db->prepare(
            "SELECT actor_id, summary, created_at, visibility
             FROM events
             WHERE type IN ('Create', 'Quote', 'QuotePost', 'Announce')
               AND COALESCE(action_taken, '') NOT IN ('deleted', 'reject')
               AND COALESCE(visibility, 'public') = 'public'
               AND COALESCE(summary, '') <> ''
             ORDER BY created_at DESC, id DESC
             LIMIT 60"
        );
        $st->execute();
        foreach ($st->fetchAll() ?: [] as $row) {
            $actorId = rtrim(trim((string) ($row['actor_id'] ?? '')), '/');
            $text = trim(html_entity_decode(strip_tags((string) ($row['summary'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($actorId === '' || $text === '') {
                continue;
            }
            $items[] = [
                'actor_id' => $actorId,
                'content' => $text,
                'created_at' => (string) ($row['created_at'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log('[vaak-login] public events: ' . $e->getMessage());
    }

    // Local public outbox notes cover posts that have not yet appeared in the
    // event log. They are restricted to explicit public visibility as well.
    try {
        $st = $db->query(
            "SELECT id, content, published
             FROM outbox_notes
             WHERE COALESCE(visibility, 'public') = 'public'
               AND COALESCE(content, '') <> ''
             ORDER BY published DESC
             LIMIT 30"
        );
        foreach ($st->fetchAll() ?: [] as $row) {
            $noteId = rtrim((string) ($row['id'] ?? ''), '/');
            if (!preg_match('#^(https://mkultra\\.monster/users/[A-Za-z0-9_]+)/#', $noteId, $m)) {
                continue;
            }
            $text = trim(html_entity_decode(strip_tags((string) ($row['content'] ?? '')), ENT_QUOTES | ENT_HTML5, 'UTF-8'));
            if ($text === '') {
                continue;
            }
            $items[] = [
                'actor_id' => $m[1],
                'content' => $text,
                'created_at' => (string) ($row['published'] ?? ''),
            ];
        }
    } catch (Throwable $e) {
        error_log('[vaak-login] public outbox: ' . $e->getMessage());
    }

    usort($items, static function (array $a, array $b): int {
        return strcmp((string) ($b['created_at'] ?? ''), (string) ($a['created_at'] ?? ''));
    });

    $out = [];
    $seen = [];
    $localByActor = [];
    foreach ($items as $item) {
        $actorId = rtrim((string) ($item['actor_id'] ?? ''), '/');
        $content = trim((string) ($item['content'] ?? ''));
        $key = $actorId . '|' . $content . '|' . (string) ($item['created_at'] ?? '');
        if ($actorId === '' || $content === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $isLocal = str_starts_with($actorId, 'https://mkultra.monster/users/');
        // Keep one busy local account from crowding out the federated preview.
        if ($isLocal) {
            $localByActor[$actorId] = ($localByActor[$actorId] ?? 0) + 1;
            if ($localByActor[$actorId] > 3) {
                continue;
            }
        }

        $username = '';
        $display = '';
        $host = (string) (parse_url($actorId, PHP_URL_HOST) ?: '');
        try {
            if ($isLocal && preg_match('#/users/([A-Za-z0-9_]+)$#', $actorId, $m)) {
                $keyName = strtolower($m[1]);
                $u = $db->prepare('SELECT username FROM ap_users WHERE actor_key = ? LIMIT 1');
                $u->execute([$keyName]);
                $username = (string) ($u->fetchColumn() ?: $keyName);
                $p = $db->prepare('SELECT name FROM actor_profile WHERE actor_key = ? LIMIT 1');
                $p->execute([$keyName]);
                $display = trim((string) ($p->fetchColumn() ?: $username));
            } else {
                $a = $db->prepare('SELECT username, display_name, host FROM remote_actors WHERE actor_id = ? LIMIT 1');
                $a->execute([$actorId]);
                $ar = $a->fetch();
                if (is_array($ar)) {
                    $username = trim((string) ($ar['username'] ?? ''));
                    $display = trim((string) ($ar['display_name'] ?? ''));
                    $host = trim((string) ($ar['host'] ?? $host));
                }
            }
        } catch (Throwable $e) {
            // A missing profile row should not prevent the login page loading.
        }
        if ($username === '') {
            $path = trim((string) (parse_url($actorId, PHP_URL_PATH) ?? ''), '/');
            $username = $path !== '' ? (string) basename($path) : 'user';
        }
        if ($display === '') {
            $display = $username;
        }
        $itemsOut = [
            'actor' => $display,
            'acct' => '@' . $username . ($host !== '' ? '@' . $host : ''),
            'content' => mb_strimwidth($content, 0, 260, '…', 'UTF-8'),
            'created_at' => (string) ($item['created_at'] ?? ''),
            'remote' => !$isLocal,
        ];
        $out[] = $itemsOut;
        if (count($out) >= $limit) {
            break;
        }
    }
    return $out;
}

// A tiny JSON endpoint used only by the logged-out background preview.
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET' && ($_GET['mode'] ?? '') === 'public-feed') {
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: public, max-age=20, stale-while-revalidate=40');
    try {
        echo json_encode(['items' => vaak_login_public_feed()], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    } catch (Throwable $e) {
        http_response_code(503);
        echo json_encode(['items' => []]);
    }
    exit;
}

$notice = null;
$error = null;
$mode = preg_replace('/[^a-z]/', '', (string) ($_GET['mode'] ?? '')) ?: '';
if ($mode !== 'register' && $mode !== 'login' && $mode !== 'forgot' && $mode !== 'reset') {
    $mode = '';
}
// Flash messages from PRG redirects (forgot / reset password).
ap_auth_start_session();
if (!empty($_SESSION['vaak_flash_ok']) && is_string($_SESSION['vaak_flash_ok'])) {
    $notice = (string) $_SESSION['vaak_flash_ok'];
    unset($_SESSION['vaak_flash_ok']);
}
if (!empty($_SESSION['vaak_flash_err']) && is_string($_SESSION['vaak_flash_err'])) {
    $error = (string) $_SESSION['vaak_flash_err'];
    unset($_SESSION['vaak_flash_err']);
}

if (isset($_GET['logout'])) {
    ap_auth_logout();
    header('Location: /vaak/', true, 302);
    exit;
}

// Only handle auth forms here — compose/favourite/etc. POSTs must reach ap-admin.php
$postAction = (string) ($_POST['action'] ?? '');
$isAuthPost = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
    && ($postAction === 'login' || $postAction === 'register' || $mode === 'login' || $mode === 'register');

if ($isAuthPost) {
    $csrf = (string) ($_POST['csrf'] ?? '');
    if (!ap_auth_csrf_ok($csrf)) {
        $error = 'Session expired — try again.';
        $mode = ($postAction === 'register' || $mode === 'register') ? 'register' : 'login';
    } elseif ($postAction === 'login' || ($postAction === '' && $mode === 'login')) {
        $res = ap_auth_attempt_login(
            (string) ($_POST['login'] ?? ''),
            (string) ($_POST['password'] ?? '')
        );
        if (!empty($res['ok'])) {
            $next = preg_replace('/[^a-z_]/', '', (string) ($_GET['next'] ?? $_POST['next'] ?? '')) ?: 'home';
            header('Location: /vaak/?view=' . rawurlencode($next), true, 302);
            exit;
        }
        $error = $res['error'] ?? 'Login failed.';
        $mode = 'login';
    } elseif ($postAction === 'register' || $mode === 'register') {
        $res = ap_auth_register(
            (string) ($_POST['invite'] ?? ''),
            (string) ($_POST['username'] ?? ''),
            (string) ($_POST['password'] ?? ''),
            (string) ($_POST['email'] ?? '')
        );
        if (!empty($res['ok'])) {
            header('Location: /vaak/?view=home', true, 302);
            exit;
        }
        $error = $res['error'] ?? 'Registration failed.';
        $mode = 'register';
    }
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'forgot') {
    if (ap_auth_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
        ap_auth_request_password_reset((string) ($_POST['login'] ?? ''));
        // PRG: avoid duplicate sends if the browser retries the POST.
        ap_auth_start_session();
        $_SESSION['vaak_flash_ok'] = 'If that account has an email address, a reset link is on its way.';
        header('Location: /vaak/?mode=forgot', true, 303);
        exit;
    }
    $error = 'Session expired — try again.';
    $mode = 'forgot';
}
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && ($_POST['action'] ?? '') === 'reset') {
    if (!ap_auth_csrf_ok((string) ($_POST['csrf'] ?? ''))) {
        $error = 'Session expired — try again.';
        $mode = 'reset';
    } else {
        $res = ap_auth_consume_password_reset((string) ($_POST['token'] ?? ''), (string) ($_POST['password'] ?? ''));
        if (!empty($res['ok'])) {
            ap_auth_start_session();
            $_SESSION['vaak_flash_ok'] = 'Password updated. You can now log in.';
            header('Location: /vaak/?mode=login', true, 303);
            exit;
        }
        $error = $res['error'] ?? 'Could not reset password.';
        $mode = 'reset';
    }
}

$user = ap_auth_current_user();

// Logged-in users get the app (POST compose/fav/etc. included)
if ($user !== null && $mode === '') {
    require dirname(__DIR__) . '/api/ap-admin.php';
    exit;
}
if ($user !== null && $mode === 'login') {
    header('Location: /vaak/?view=home', true, 302);
    exit;
}

// Logged out — show login (default) or register
if ($mode === '') {
    $mode = 'login';
}

$csrf = htmlspecialchars(ap_auth_csrf_token(), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
$loginFeed = vaak_login_public_feed();
$asciiV = <<<'ASCII'
██╗   ██╗
██║   ██║
██║   ██║
╚██╗ ██╔╝
 ╚████╔╝
  ╚═══╝
ASCII;
?><!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width,initial-scale=1">
  <meta name="robots" content="noindex,nofollow">
  <meta name="theme-color" content="#00ff9f">
  <meta name="apple-mobile-web-app-capable" content="yes">
  <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
  <meta name="apple-mobile-web-app-title" content="VAAK">
  <title>VAAK</title>
  <!-- Versioned, path-specific icons keep Safari from reusing the root site's favicon. -->
  <link rel="manifest" href="/vaak/manifest.webmanifest?v=20260909">
  <link rel="icon" href="/vaak/favicon.svg?v=20260907" type="image/svg+xml">
  <link rel="icon" href="/vaak/favicon.ico?v=20260907" sizes="any">
  <link rel="icon" href="/vaak/favicon-32x32.png?v=20260907" type="image/png" sizes="32x32">
  <link rel="icon" href="/vaak/favicon-16x16.png?v=20260907" type="image/png" sizes="16x16">
  <link rel="apple-touch-icon" href="/vaak/apple-touch-icon.png?v=20260907">
  <style>
    :root { color-scheme: dark; }
    * { box-sizing: border-box; }
    body {
      margin: 0; min-height: 100vh; display: flex; align-items: center; justify-content: center;
      background: #000; color: #e8e8e8; overflow-x: hidden; position: relative;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      padding: 1.5rem;
    }
    main { width: min(22rem, 100%); position: relative; z-index: 2; }
    .login-live-feed {
      position: fixed; z-index: 0; top: 0; right: 0; width: min(54vw, 760px); height: 100vh;
      overflow: hidden; padding: 5vh 3vw 5vh 2vw; pointer-events: none;
      opacity: .22; filter: saturate(.72) blur(.2px);
      mask-image: linear-gradient(to left, #000 58%, transparent 100%);
      -webkit-mask-image: linear-gradient(to left, #000 58%, transparent 100%);
    }
    .login-live-feed::before {
      content: "PUBLIC VAAK TIMELINE"; display: block; margin: 0 0 1rem 1rem;
      color: #00ff9f; font-size: .72rem; font-weight: 700; letter-spacing: .18em;
    }
    .login-feed-scroll { display: grid; gap: .8rem; transform: rotate(-1deg); }
    .login-feed-item {
      border: 1px solid rgba(255,255,255,.2); border-radius: 14px; padding: .9rem 1rem;
      background: rgba(12,12,12,.82); box-shadow: 0 12px 35px rgba(0,0,0,.22);
    }
    .login-feed-head { display: flex; gap: .45rem; align-items: baseline; font-size: .78rem; }
    .login-feed-head strong { color: #eee; font-size: .9rem; }
    .login-feed-head span { color: #9c9c9c; overflow: hidden; text-overflow: ellipsis; white-space: nowrap; }
    .login-feed-body { margin-top: .6rem; color: #d7d7d7; font-size: .92rem; line-height: 1.35; }
    .login-feed-item.remote { border-color: rgba(126,224,255,.27); }
    .login-feed-empty { color: #777; font-size: .85rem; padding: 1rem; }
    .brand { text-align: center; margin-bottom: 1.75rem; }
    .brand pre {
      margin: 0 auto; display: inline-block; text-align: left;
      font-family: ui-monospace, SFMono-Regular, Menlo, Consolas, monospace;
      font-size: clamp(.7rem, 2.8vw, .95rem); line-height: 1.05;
      color: #00ff9f; text-shadow: 0 0 24px rgba(0,255,159,.25);
    }
    .brand h1 {
      margin: .85rem 0 0; font-size: .95rem; font-weight: 600; letter-spacing: .35em;
      color: #8a8a8a; text-indent: .35em;
    }
    .brand .vaak-version {
      margin: .55rem 0 0; font-size: .72rem; color: #666; letter-spacing: .04em;
    }
    form {
      border: 1px solid #222; border-radius: 14px; padding: 1.15rem 1.1rem 1.2rem;
      background: #0a0a0a;
    }
    label { display: block; font-size: .78rem; color: #8a8a8a; margin: .65rem 0 .3rem; }
    label:first-of-type { margin-top: 0; }
    input {
      width: 100%; padding: .7rem .75rem; border-radius: 10px;
      border: 1px solid #2a2a2a; background: #050505; color: #eee;
      font: inherit; font-size: 16px;
    }
    input:focus { outline: none; border-color: #00ff9f; }
    button {
      width: 100%; margin-top: 1rem; padding: .75rem 1rem; border: 0; border-radius: 999px;
      background: linear-gradient(135deg, #00ff9f, #00cc7f); color: #04140c;
      font-weight: 700; font-size: .95rem; cursor: pointer;
    }
    button:hover { filter: brightness(1.06); }
    .flash {
      margin: 0 0 .85rem; padding: .65rem .75rem; border-radius: 10px; font-size: .85rem;
    }
    .flash.err { background: rgba(255,80,80,.12); color: #ff8a8a; border: 1px solid rgba(255,80,80,.25); }
    .flash.ok { background: rgba(0,255,159,.1); color: #9dffd0; border: 1px solid rgba(0,255,159,.25); }
    .switch { text-align: center; margin-top: 1rem; font-size: .85rem; color: #8a8a8a; }
    .switch a { color: #7ee0ff; text-decoration: none; }
    .switch a:hover { text-decoration: underline; }
    .hint { margin: .5rem 0 0; font-size: .75rem; color: #666; line-height: 1.35; }
    .policies {
      text-align: center; margin-top: .55rem; font-size: .78rem; color: #666;
      display: flex; flex-wrap: wrap; gap: .35rem .75rem; justify-content: center;
    }
    .policies a { color: #9ad4e8; text-decoration: none; }
    .policies a:hover { text-decoration: underline; text-underline-offset: 2px; }
    .policies .sep { color: #444; user-select: none; }
    .ai-disclaimer {
      text-align: center; margin-top: .85rem; font-size: .72rem; color: #555; line-height: 1.35;
    }
    .vaak-version-foot {
      text-align: center; margin-top: 1.15rem; font-size: .72rem; color: #555; line-height: 1.35;
    }
    @media (max-width: 760px) {
      body { align-items: flex-start; padding-top: 2.25rem; }
      .login-live-feed { width: 100vw; opacity: .09; padding: 1.5rem .75rem; mask-image: linear-gradient(to bottom, transparent 0, #000 25%, #000 75%, transparent 100%); -webkit-mask-image: linear-gradient(to bottom, transparent 0, #000 25%, #000 75%, transparent 100%); }
      .login-feed-scroll { transform: none; }
      main { max-width: 22rem; }
    }
  </style>
</head>
<body>
  <aside class="login-live-feed" aria-hidden="true">
    <div class="login-feed-scroll" id="login-live-feed-list">
      <?php if (!$loginFeed): ?>
        <div class="login-feed-empty">Public posts will appear here.</div>
      <?php else: foreach ($loginFeed as $item): ?>
        <article class="login-feed-item<?= !empty($item['remote']) ? ' remote' : '' ?>">
          <div class="login-feed-head"><strong><?= htmlspecialchars((string) $item['actor'], ENT_QUOTES, 'UTF-8') ?></strong><span><?= htmlspecialchars((string) $item['acct'], ENT_QUOTES, 'UTF-8') ?></span></div>
          <div class="login-feed-body"><?= nl2br(htmlspecialchars((string) $item['content'], ENT_QUOTES, 'UTF-8')) ?></div>
        </article>
      <?php endforeach; endif; ?>
    </div>
  </aside>
  <main>
    <div class="brand">
      <pre><?= htmlspecialchars($asciiV, ENT_QUOTES, 'UTF-8') ?></pre>
      <h1>VAAK</h1>
      <p class="vaak-version"><?= htmlspecialchars(vaak_version_label(), ENT_QUOTES, 'UTF-8') ?></p>
    </div>

    <?php if ($error): ?><div class="flash err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="flash ok"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($mode === 'forgot'): ?>
      <form method="post" action="/vaak/?mode=forgot" id="forgot-form">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="forgot">
        <label for="login">Username or email</label>
        <input id="login" name="login" required autocomplete="username" placeholder="username or email">
        <button type="submit" id="forgot-submit">Email reset link</button>
      </form>
      <p class="switch"><a href="/vaak/?mode=login">Back to login</a></p>
      <script>
        (function () {
          const form = document.getElementById('forgot-form');
          const btn = document.getElementById('forgot-submit');
          if (!form || !btn) return;
          form.addEventListener('submit', () => {
            if (btn.disabled) return;
            btn.disabled = true;
            btn.textContent = 'Sending…';
          });
        })();
      </script>
    <?php elseif ($mode === 'reset'): ?>
      <form method="post" action="/vaak/?mode=reset">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="reset">
        <input type="hidden" name="token" value="<?= htmlspecialchars((string) ($_GET['token'] ?? $_POST['token'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="password">New password</label>
        <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password" placeholder="at least 10 characters">
        <button type="submit">Set new password</button>
      </form>
      <p class="switch"><a href="/vaak/?mode=login">Back to login</a></p>
    <?php elseif ($mode === 'register'): ?>
      <form method="post" action="/vaak/?mode=register" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="register">
        <label for="invite">Invite code</label>
        <input id="invite" name="invite" required autocomplete="off" placeholder="ABCD-EF12"
               value="<?= htmlspecialchars((string) ($_POST['invite'] ?? $_GET['invite'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="username">Username</label>
        <input id="username" name="username" required autocomplete="username" pattern="[A-Za-z][A-Za-z0-9_]{1,29}"
               placeholder="your_name"
               value="<?= htmlspecialchars((string) ($_POST['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="email" placeholder="you@example.com"
               value="<?= htmlspecialchars((string) ($_POST['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password"
               placeholder="at least 10 characters">
        <button type="submit">Create account</button>
      </form>
      <p class="switch">Have an account? <a href="/vaak/?mode=login">Log in</a></p>
    <?php else: ?>
      <form method="post" action="/vaak/?mode=login" autocomplete="on">
        <input type="hidden" name="csrf" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="login">
        <label for="login">Username or email</label>
        <input id="login" name="login" required autocomplete="username"
               placeholder="username"
               value="<?= htmlspecialchars((string) ($_POST['login'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
        <label for="password">Password</label>
        <input id="password" name="password" type="password" required autocomplete="current-password"
               placeholder="••••••••••">
        <button type="submit">Log in</button>
      </form>
      <p class="switch">Have an invite? <a href="/vaak/?mode=register">Register</a><br><a href="/vaak/?mode=forgot">Forgot password?</a></p>
    <?php endif; ?>

    <nav class="policies" aria-label="Policies">
      <a href="/vaak/privacy/">Privacy</a>
      <span class="sep" aria-hidden="true">·</span>
      <a href="/vaak/conduct/">Code of Conduct</a>
      <span class="sep" aria-hidden="true">·</span>
      <a href="https://mkultra.monster/">mkultra.monster</a>
    </nav>
    <p class="ai-disclaimer">This app was developed in conjunction with AI</p>
  </main>
  <script>
    (() => {
      const list = document.getElementById('login-live-feed-list');
      if (!list) return;
      const esc = (value) => String(value ?? '').replace(/[&<>"']/g, (ch) => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
      const render = (items) => {
        if (!Array.isArray(items) || !items.length) return;
        list.innerHTML = items.map((item) => `<article class="login-feed-item${item.remote ? ' remote' : ''}"><div class="login-feed-head"><strong>${esc(item.actor)}</strong><span>${esc(item.acct)}</span></div><div class="login-feed-body">${esc(item.content).replace(/\n/g, '<br>')}</div></article>`).join('');
      };
      const refresh = () => fetch('/vaak/?mode=public-feed', {credentials: 'same-origin', cache: 'no-store'})
        .then((response) => response.ok ? response.json() : null)
        .then((payload) => { if (payload) render(payload.items); })
        .catch(() => {});
      window.setInterval(refresh, 45000);
    })();
  </script>
</body>
</html>
