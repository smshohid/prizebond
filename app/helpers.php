<?php
declare(strict_types=1);

use App\Core\Lang;
use App\Core\Session;

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}

function base_path(string $p = ''): string
{
    return BASE_PATH . ($p !== '' ? DIRECTORY_SEPARATOR . ltrim(str_replace('/', DIRECTORY_SEPARATOR, $p), '\\/') : '');
}

function env(string $key, mixed $default = null): mixed
{
    $v = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);
    if ($v === false || $v === null || $v === '') {
        return $default;
    }
    return match (strtolower((string) $v)) {
        'true' => true,
        'false' => false,
        default => $v,
    };
}

function is_production(): bool
{
    return env('APP_ENV', 'production') === 'production';
}

/** HTML-escape for output. */
function e(mixed $v): string
{
    return htmlspecialchars((string) $v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function app_url(string $path = ''): string
{
    $base = rtrim((string) env('APP_URL', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

/** Site-relative URL (works when installed in a sub-directory). */
function url(string $path = ''): string
{
    $base = rtrim((string) env('APP_BASE_PATH', ''), '/');
    return $base . '/' . ltrim($path, '/');
}

function asset(string $path): string
{
    return url('assets/' . ltrim($path, '/'));
}

function t(string $key, array $repl = []): string
{
    return Lang::get($key, $repl);
}

function csrf_token(): string
{
    return \App\Core\Csrf::token();
}

function csrf_field(): string
{
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function old(string $key, mixed $default = ''): mixed
{
    $o = Session::get('_old', []);
    return $o[$key] ?? $default;
}

function flash(string $type, string $msg): void
{
    $f = Session::get('_flash', []);
    $f[] = ['type' => $type, 'msg' => $msg];
    Session::put('_flash', $f);
}

/** Format a UTC DB datetime for display in Asia/Dhaka. */
function fmt_dt(?string $utc, string $fmt = 'd M Y, h:i A'): string
{
    if (!$utc) {
        return '—';
    }
    $d = new DateTimeImmutable($utc, new DateTimeZone('UTC'));
    return $d->setTimezone(new DateTimeZone('Asia/Dhaka'))->format($fmt);
}

/** Format a calendar date (no timezone conversion: official Bangladesh local date). */
function fmt_date(?string $d, string $fmt = 'd M Y'): string
{
    if (!$d) {
        return '—';
    }
    return (new DateTimeImmutable($d))->format($fmt);
}

function money(float|int|string $v): string
{
    // Bangladeshi digit grouping: 6,00,000
    $n = (int) round((float) $v);
    $s = (string) abs($n);
    if (strlen($s) > 3) {
        $last = substr($s, -3);
        $rest = substr($s, 0, -3);
        $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
        $s = $rest . ',' . $last;
    }
    return ($n < 0 ? '-' : '') . 'Tk. ' . $s;
}
