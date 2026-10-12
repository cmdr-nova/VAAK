<?php
/** Fixed, supervised one-shot SMTP child. No queue and no automatic retries. */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require_once __DIR__ . '/ap-mail.php';
try {
    $input = stream_get_contents(STDIN, 1024);
    $row = json_decode($input, true);
    $username = is_array($row) ? (string) ($row['username'] ?? '') : '';
    $email = is_array($row) ? (string) ($row['email'] ?? '') : '';
    if (!preg_match('/^[a-z][a-z0-9_]{1,29}$/D', $username)
        || strlen($email) > 254 || !filter_var($email, FILTER_VALIDATE_EMAIL)) exit(1);
    $url = 'https://mkultra.monster/vaak/?mode=login';
    $escaped = htmlspecialchars($username, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $sent = ap_mail_send($email, 'Your VAAK account is approved',
        "Your VAAK account @{$username} has been approved.\n\nLog in: {$url}\n",
        '<p>Your VAAK account @'.$escaped.' has been approved.</p><p><a href="'.htmlspecialchars($url, ENT_QUOTES, 'UTF-8').'">Log in to VAAK</a></p>', 5.0);
    fwrite(STDOUT, $sent ? 'sent' : 'failed');
} catch (Throwable $e) { fwrite(STDOUT, 'failed'); }
