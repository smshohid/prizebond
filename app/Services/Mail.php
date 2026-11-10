<?php
declare(strict_types=1);

namespace App\Services;

use PHPMailer\PHPMailer\Exception as MailException;
use PHPMailer\PHPMailer\PHPMailer;

interface MailTransport
{
    /** @throws \RuntimeException on failure */
    public function send(string $to, string $subject, string $html, string $text): void;
}

/** Production SMTP transport (PHPMailer). Credentials come only from the environment. */
final class SmtpTransport implements MailTransport
{
    public function send(string $to, string $subject, string $html, string $text): void
    {
        $m = new PHPMailer(true);
        try {
            $m->isSMTP();
            $m->Host = (string) env('MAIL_HOST', '');
            $m->Port = (int) env('MAIL_PORT', 587);
            $user = (string) env('MAIL_USERNAME', '');
            $m->SMTPAuth = $user !== '';
            $m->Username = $user;
            $m->Password = (string) env('MAIL_PASSWORD', '');
            $enc = strtolower((string) env('MAIL_ENCRYPTION', 'tls'));
            $m->SMTPSecure = match ($enc) { 'ssl', 'smtps' => PHPMailer::ENCRYPTION_SMTPS, 'tls', 'starttls' => PHPMailer::ENCRYPTION_STARTTLS, default => '' };
            $m->SMTPAutoTLS = $enc !== 'none';
            $m->Timeout = 20;
            $m->CharSet = 'UTF-8';
            $m->setFrom((string) env('MAIL_FROM_ADDRESS', 'no-reply@localhost'), (string) env('MAIL_FROM_NAME', 'Prize Bond Monitor BD'));
            $m->addAddress($to);
            $m->Subject = $subject;
            $m->isHTML(true);
            $m->Body = $html;
            $m->AltBody = $text;
            $m->send();
        } catch (MailException $e) {
            throw new \RuntimeException(\App\Core\Logger::scrub($m->ErrorInfo ?: $e->getMessage()));
        }
    }
}

/** Writes messages to storage/logs (local development only). */
final class LogTransport implements MailTransport
{
    public function send(string $to, string $subject, string $html, string $text): void
    {
        @file_put_contents(base_path('storage/logs/mail-' . gmdate('Y-m-d') . '.log'),
            "---- " . gmdate('c') . " TO: $to\nSUBJECT: $subject\n$text\n\n", FILE_APPEND | LOCK_EX);
    }
}

/** In-memory transport for automated tests (never sends real email). */
final class ArrayTransport implements MailTransport
{
    public static array $sent = [];
    public static int $failNext = 0;
    public static bool $throwAfterRecord = false; // simulate "delivered but SMTP timed out"
    public static string $failMessage = 'Simulated SMTP failure';

    public function send(string $to, string $subject, string $html, string $text): void
    {
        if (self::$failNext > 0) {
            self::$failNext--;
            if (self::$throwAfterRecord) {
                self::$sent[] = compact('to', 'subject', 'html', 'text');
            }
            throw new \RuntimeException(self::$failMessage);
        }
        self::$sent[] = compact('to', 'subject', 'html', 'text');
    }

    public static function reset(): void
    {
        self::$sent = [];
        self::$failNext = 0;
        self::$throwAfterRecord = false;
        self::$failMessage = 'Simulated SMTP failure';
    }
}

final class Mailer
{
    private static ?MailTransport $transport = null;

    public static function use(?MailTransport $t): void
    {
        self::$transport = $t;
    }

    public static function transport(): MailTransport
    {
        if (self::$transport) {
            return self::$transport;
        }
        return match (strtolower((string) env('MAIL_TRANSPORT', 'smtp'))) {
            'log' => new LogTransport(),
            default => new SmtpTransport(),
        };
    }
}
