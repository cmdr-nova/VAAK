<?php
declare(strict_types=1);

/** Reuse the existing Clearance/Brevo sender without storing SMTP secrets in Vaak. */
function ap_mail_send(string $to, string $subject, string $text, string $html, ?float $totalTimeout = null): bool
{
    $deadline = $totalTimeout === null ? null : hrtime(true) / 1e9 + max(0.01, min(20.0, $totalTimeout));
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
    $remaining = static function () use ($deadline): float {
        $seconds = $deadline === null ? 20.0 : $deadline - hrtime(true) / 1e9;
        if ($seconds <= 0) throw new RuntimeException('SMTP deadline exceeded');
        return min(20.0, $seconds);
    };
    $fp = null;
    try {
    $fp = @stream_socket_client("tcp://{$host}:{$port}", $errno, $errstr, $remaining(), STREAM_CLIENT_CONNECT);
    if (!$fp) {
        error_log($deadline === null ? '[ap-mail] SMTP connect failed: ' . $errstr : '[ap-mail] Approval SMTP connection failed.');
        return false;
    }
    $setTimeout = static function () use ($fp, $remaining): void {
        $seconds = $remaining();
        stream_set_timeout($fp, (int)$seconds, max(1, (int)(($seconds - (int)$seconds) * 1000000)));
    };
    $read = static function () use ($fp, $setTimeout): string {
        $out = '';
        while (!feof($fp)) {
            $setTimeout();
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
    $writeBytes = static function (string $bytes) use ($fp, $setTimeout): void {
        $offset = 0;
        while ($offset < strlen($bytes)) {
            $setTimeout();
            $written = fwrite($fp, substr($bytes, $offset));
            if ($written === false || $written === 0) throw new RuntimeException('SMTP write failed');
            $offset += $written;
        }
    };
    $write = static function (string $line) use ($writeBytes): void { $writeBytes($line . "\r\n"); };
        $expect('220'); $write('EHLO mkultra.monster'); $expect('250');
        $write('STARTTLS'); $expect('220');
        $setTimeout();
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
        $writeBytes($body . "\r\n.\r\n"); $expect('250');
        // DATA acceptance is delivery success; QUIT cannot reverse that receipt.
        if ($deadline === null) $write('QUIT');
        fclose($fp);
        error_log($deadline === null ? '[ap-mail] sent to=' . $to . ' subject=' . $subject . ' id=' . $messageId : '[ap-mail] Approval email sent.');
        return true;
    } catch (Throwable $e) {
        if (is_resource($fp)) fclose($fp);
        error_log($deadline === null ? '[ap-mail] ' . $e->getMessage() : '[ap-mail] Approval email unavailable.');
        return false;
    }
}
