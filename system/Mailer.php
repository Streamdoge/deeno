<?php
declare(strict_types=1);

/**
 * Отправка почты через PHP mail() — работает на большинстве shared-хостингов.
 * SMTP-транспорт по smtp_* из config.json — в backlog (после 1.0).
 */
class Mailer
{
    public static function send(array $config, string $to, string $subject, string $body): bool
    {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $host = (string)(parse_url((string)($config['site_url'] ?? ''), PHP_URL_HOST)
            ?: ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        // Хост может прийти с портом — для From он не нужен
        $host = preg_replace('/:\d+$/', '', $host) ?: 'localhost';

        $fromName = trim((string)($config['site_title'] ?? '')) ?: 'deeno';
        $headers = implode("\r\n", [
            'From: =?UTF-8?B?' . base64_encode($fromName) . '?= <no-reply@' . $host . '>',
            'Content-Type: text/plain; charset=UTF-8',
            'Content-Transfer-Encoding: 8bit',
            'X-Mailer: deeno',
        ]);

        $encodedSubject = '=?UTF-8?B?' . base64_encode($subject) . '?=';

        return @mail($to, $encodedSubject, $body, $headers);
    }
}
