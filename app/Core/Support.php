<?php
declare(strict_types=1);

namespace App\Core;

/** DB-backed system settings with request-level cache. */
final class Settings
{
    private static array $cache = [];

    public static function get(string $key, mixed $default = null): mixed
    {
        if (!array_key_exists($key, self::$cache)) {
            $v = DB::val('SELECT setting_value FROM system_settings WHERE setting_key = ?', [$key]);
            self::$cache[$key] = $v;
        }
        return self::$cache[$key] ?? $default;
    }

    public static function int(string $key, int $default = 0): int
    {
        return (int) self::get($key, $default);
    }

    public static function set(string $key, string $value): void
    {
        DB::exec('INSERT INTO system_settings (setting_key, setting_value, updated_at) VALUES (?,?,?)
                  ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value), updated_at = VALUES(updated_at)', [$key, $value, DB::now()]);
        self::$cache[$key] = $value;
    }

    public static function flush(): void
    {
        self::$cache = [];
    }
}

final class Logger
{
    public static function log(string $channel, string $level, string $message, array $ctx = []): void
    {
        $dir = base_path('storage/logs');
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        unset($ctx['password'], $ctx['token'], $ctx['secret']);
        $line = sprintf("[%s] %s.%s: %s %s\n", gmdate('c'), $channel, strtoupper($level), self::scrub($message),
            $ctx ? self::scrub((string) json_encode($ctx, JSON_UNESCAPED_UNICODE)) : '');
        @file_put_contents($dir . '/' . $channel . '-' . gmdate('Y-m-d') . '.log', $line, FILE_APPEND | LOCK_EX);
    }

    /** Remove anything that looks like a credential before it reaches a log. */
    public static function scrub(string $s): string
    {
        foreach (['MAIL_PASSWORD', 'DB_PASSWORD', 'APP_KEY'] as $k) {
            $v = env($k);
            if (is_string($v) && strlen($v) >= 4) {
                $s = str_replace($v, '***', $s);
            }
        }
        return $s;
    }

    public static function error(string $ch, string $m, array $c = []): void { self::log($ch, 'error', $m, $c); }
    public static function info(string $ch, string $m, array $c = []): void { self::log($ch, 'info', $m, $c); }
    public static function warning(string $ch, string $m, array $c = []): void { self::log($ch, 'warning', $m, $c); }
}

final class Audit
{
    private const REDACT = ['password', 'password_hash', 'token', 'token_hash', 'secret', 'smtp_password', 'mail_password', 'app_key'];

    public static function log(?int $adminId, string $action, string $entity, string|int|null $entityId = null, mixed $old = null, mixed $new = null, ?string $ip = null, string $actor = 'admin'): void
    {
        DB::insert('audit_logs', [
            'admin_id' => $adminId,
            'actor' => $actor,
            'action' => $action,
            'entity' => $entity,
            'entity_id' => $entityId === null ? null : (string) $entityId,
            'previous_values' => $old === null ? null : json_encode(self::redact($old), JSON_UNESCAPED_UNICODE),
            'new_values' => $new === null ? null : json_encode(self::redact($new), JSON_UNESCAPED_UNICODE),
            'ip_address' => $ip,
            'created_at' => DB::now(),
        ]);
    }

    public static function redact(mixed $v): mixed
    {
        if (!is_array($v)) {
            return $v;
        }
        foreach ($v as $k => $x) {
            if (is_string($k) && in_array(strtolower($k), self::REDACT, true)) {
                $v[$k] = '[redacted]';
            } else {
                $v[$k] = self::redact($x);
            }
        }
        return $v;
    }
}

/** Fixed-window rate limiter backed by the database (works on shared hosting). */
final class RateLimiter
{
    /** @return bool true if the action is allowed (and counts a hit). */
    public static function hit(string $key, int $max, int $windowSeconds): bool
    {
        $key = hash('sha256', $key);
        $now = time();
        return (bool) DB::tx(function () use ($key, $max, $windowSeconds, $now) {
            $row = DB::one('SELECT hits, window_start FROM rate_limits WHERE rl_key = ? FOR UPDATE', [$key]);
            if (!$row || strtotime($row['window_start'] . ' UTC') <= $now - $windowSeconds) {
                DB::exec('REPLACE INTO rate_limits (rl_key, hits, window_start) VALUES (?,1,?)', [$key, gmdate('Y-m-d H:i:s', $now)]);
                return true;
            }
            if ((int) $row['hits'] >= $max) {
                return false;
            }
            DB::exec('UPDATE rate_limits SET hits = hits + 1 WHERE rl_key = ?', [$key]);
            return true;
        });
    }

    public static function tooMany(string $key, int $max, int $windowSeconds): bool
    {
        $k = hash('sha256', $key);
        $row = DB::one('SELECT hits, window_start FROM rate_limits WHERE rl_key = ?', [$k]);
        return $row && (int) $row['hits'] >= $max && strtotime($row['window_start'] . ' UTC') > time() - $windowSeconds;
    }

    public static function clear(string $key): void
    {
        $k = hash('sha256', $key);
        DB::exec('DELETE FROM rate_limits WHERE rl_key = ?', [$k]);
    }
}
