<?php

namespace App\Libraries;

/**
 * Plain-text e-mail. Uses SMTP when MAIL_HOST is set in .env (works with Gmail app passwords, cPanel mail ...),
 * otherwise PHP's mail(). Returns false instead of throwing, so a mail problem never breaks a request.
 */
class Mailer
{
    public static function send(string $to, string $subject, string $body): bool
    {
        $from = (string) env('mail.from', '');
        $name = (string) env('mail.from_name', 'GEBPSOICS Inventory');
        if ($from === '' || preg_match('/[\r\n]/', $to . $subject . $from . $name)) {
            return false;
        }
        try {
            return env('mail.host') ? self::smtp($to, $subject, $body, $from, $name) : self::native($to, $subject, $body, $from, $name);
        } catch (\Throwable $e) {
            log_message('warning', 'Mail failed: ' . $e->getMessage());

            return false;
        }
    }

    private static function headers(string $to, string $subject, string $from, string $name): string
    {
        return 'From: =?UTF-8?B?' . base64_encode($name) . "?= <$from>\r\n"
             . "To: $to\r\nSubject: =?UTF-8?B?" . base64_encode($subject) . "?=\r\n"
             . "Date: " . date('r') . "\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64\r\n";
    }

    private static function native(string $to, string $subject, string $body, string $from, string $name): bool
    {
        return @mail($to, '=?UTF-8?B?' . base64_encode($subject) . '?=', chunk_split(base64_encode($body)),
            "From: =?UTF-8?B?" . base64_encode($name) . "?= <$from>\r\nMIME-Version: 1.0\r\nContent-Type: text/plain; charset=UTF-8\r\nContent-Transfer-Encoding: base64");
    }

    /** Reads one (possibly multi-line) SMTP reply and returns [code, text]. */
    private static function read($fp): array
    {
        $text = '';
        while (($line = fgets($fp, 1024)) !== false) {
            $text .= $line;
            if (strlen($line) < 4 || $line[3] === ' ') {
                break;
            }
        }

        return [(int) substr($text, 0, 3), $text];
    }

    private static function cmd($fp, string $line, array $ok): string
    {
        fwrite($fp, $line . "\r\n");
        [$code, $text] = self::read($fp);
        if (! in_array($code, $ok, true)) {
            throw new \RuntimeException('SMTP said: ' . trim($text));
        }

        return $text;
    }

    private static function smtp(string $to, string $subject, string $body, string $from, string $name): bool
    {
        $host   = (string) env('mail.host');
        $port   = (int) env('mail.port', 587);
        $crypto = strtolower((string) env('mail.crypto', 'tls')); // tls = STARTTLS (587), ssl = implicit (465), none
        $fp     = @stream_socket_client(($crypto === 'ssl' ? 'ssl://' : 'tcp://') . "$host:$port", $en, $es, 15);
        if (! $fp) {
            throw new \RuntimeException("Cannot connect to $host:$port ($es)");
        }
        stream_set_timeout($fp, 15);
        try {
            [$code, $text] = self::read($fp);
            if ($code !== 220) {
                throw new \RuntimeException('SMTP greeting: ' . trim($text));
            }
            $me = 'gebpsoics';
            self::cmd($fp, "EHLO $me", [250]);
            if ($crypto === 'tls') {
                self::cmd($fp, 'STARTTLS', [220]);
                if (! stream_socket_enable_crypto($fp, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    throw new \RuntimeException('STARTTLS failed');
                }
                self::cmd($fp, "EHLO $me", [250]);
            }
            if (env('mail.user')) {
                self::cmd($fp, 'AUTH LOGIN', [334]);
                self::cmd($fp, base64_encode((string) env('mail.user')), [334]);
                self::cmd($fp, base64_encode((string) env('mail.pass', '')), [235]);
            }
            self::cmd($fp, "MAIL FROM:<$from>", [250]);
            self::cmd($fp, "RCPT TO:<$to>", [250, 251]);
            self::cmd($fp, 'DATA', [354]);
            $msg = self::headers($to, $subject, $from, $name) . "\r\n" . chunk_split(base64_encode($body));
            self::cmd($fp, $msg . "\r\n.", [250]);
            self::cmd($fp, 'QUIT', [221]);
        } finally {
            fclose($fp);
        }

        return true;
    }
}
