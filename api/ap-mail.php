<?php
declare(strict_types=1);

/** Reuse the existing Clearance/Brevo sender without storing SMTP secrets in Vaak. */
function ap_mail_send(string $to, string $subject, string $text, string $html): bool
{
    if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
        return false;
    }
    $configPath = dirname(__DIR__) . '/clearance/config.local.php';
    if (!is_file($configPath)) {
        $configPath = '/srv/mkultra/html/clearance/config.local.php';
    }
    /** @var mixed $cfgRaw */
    $cfgRaw = is_file($configPath) ? require $configPath : [];
    $cfg = is_array($cfgRaw) ? $cfgRaw : [];
    if ($cfg === [] || empty($cfg['smtp_user']) || empty($cfg['smtp_pass'])) {
        error_log('[ap-mail] SMTP configuration unavailable');
        return false;
    }
    $host = (string) ($cfg['smtp_host'] ?? 'smtp-relay.brevo.com');
    $port = (int) ($cfg['smtp_port'] ?? 587);
    $from = (string) ($cfg['smtp_from_email'] ?? 'clearance@mkultra.monster');
    $fromName = (string) ($cfg['smtp_from_name'] ?? 'Vaak');
    $messageId = sprintf('<%s.%s@mkultra.monster>', bin2hex(random_bytes(8)), gmdate('YmdHis'));
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, 20, STREAM_CLIENT_CONNECT);
    if (!$fp) {
        error_log('[ap-mail] SMTP connect failed: ' . $errstr);
        return false;
    }
    stream_set_timeout($fp, 20);
    $read = static function () use ($fp): string {
        $out = '';
        while (!feof($fp)) {
            $line = fgets($fp, 515);
            if ($line === false) break;
            $out .= $line;
            if (isset($line[3]) && $line[3] === ' ') break;
        }
        return $out;
    };
    $expect = static function (string $prefix) use ($read): void {
        $resp = $read();
        if (!str_starts_with($resp, $prefix)) throw new RuntimeException(trim($resp));
    };
    $write = static function (string $line) use ($fp): void { fwrite($fp, $line . "\r\n"); };
    try {
        $expect('220'); $write('EHLO mkultra.monster'); $expect('250');
        $write('STARTTLS'); $expect('220');
        if (!stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) throw new RuntimeException('TLS failed');
        $write('EHLO mkultra.monster'); $expect('250'); $write('AUTH LOGIN'); $expect('334');
        $write(base64_encode((string) $cfg['smtp_user'])); $expect('334'); $write(base64_encode((string) $cfg['smtp_pass'])); $expect('235');
        $write('MAIL FROM:<' . $from . '>'); $expect('250'); $write('RCPT TO:<' . $to . '>'); $expect('250'); $write('DATA'); $expect('354');
        $boundary = 'b_' . bin2hex(random_bytes(8));
        $headers = [
            'Date: ' . date('r'),
            'From: ' . $fromName . ' <' . $from . '>',
            'To: <' . $to . '>',
            'Subject: ' . $subject,
            'Message-ID: ' . $messageId,
            'MIME-Version: 1.0',
            'Content-Type: multipart/alternative; boundary="' . $boundary . '"',
        ];
        $body = implode("\r\n", $headers) . "\r\n\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/plain; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $text . "\r\n"
            . '--' . $boundary . "\r\n"
            . "Content-Type: text/html; charset=UTF-8\r\n"
            . "Content-Transfer-Encoding: 8bit\r\n\r\n"
            . $html . "\r\n"
            . '--' . $boundary . "--\r\n";
        $body = preg_replace('/^\./m', '..', $body) ?? $body;
        fwrite($fp, $body . "\r\n.\r\n"); $expect('250'); $write('QUIT'); fclose($fp);
        error_log('[ap-mail] sent to=' . $to . ' subject=' . $subject . ' id=' . $messageId);
        return true;
    } catch (Throwable $e) {
        fclose($fp); error_log('[ap-mail] ' . $e->getMessage()); return false;
    }
}
