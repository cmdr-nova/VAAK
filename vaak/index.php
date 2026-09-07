<?php
/**
 * VAAK front controller — https://mkultra.monster/vaak/
 * Logged out: ASCII-V login / invite registration.
 * Logged in: boots ap-admin.php (same app, session-gated).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/api/ap-auth.php';

ap_auth_bootstrap();
ap_auth_start_session();

$notice = null;
$error = null;
$mode = preg_replace('/[^a-z]/', '', (string) ($_GET['mode'] ?? '')) ?: '';
if ($mode !== 'register' && $mode !== 'login') {
    $mode = '';
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
  <title>VAAK</title>
  <!-- Versioned, path-specific icons keep Safari from reusing the root site's favicon. -->
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
      background: #000; color: #e8e8e8;
      font-family: ui-sans-serif, system-ui, -apple-system, Segoe UI, Roboto, sans-serif;
      padding: 1.5rem;
    }
    main { width: min(22rem, 100%); }
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
      text-align: center; margin-top: 1.15rem; font-size: .72rem; color: #555; line-height: 1.35;
    }
  </style>
</head>
<body>
  <main>
    <div class="brand">
      <pre><?= htmlspecialchars($asciiV, ENT_QUOTES, 'UTF-8') ?></pre>
      <h1>VAAK</h1>
    </div>

    <?php if ($error): ?><div class="flash err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
    <?php if ($notice): ?><div class="flash ok"><?= htmlspecialchars($notice, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

    <?php if ($mode === 'register'): ?>
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
      <p class="switch">Have an invite? <a href="/vaak/?mode=register">Register</a></p>
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
</body>
</html>
