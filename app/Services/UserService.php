<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Lang;
use App\Core\RateLimiter;

/** Registration, verification, password reset. Tokens are random, hashed at rest, expiring and single-use. */
final class UserService
{
    public const VERIFY_TTL = 86400;      // 24h
    public const RESET_TTL = 3600;        // 1h

    public function __construct(private ?NotificationService $mail = null)
    {
        $this->mail ??= new NotificationService();
    }

    public static function validatePassword(string $p): ?string
    {
        if (mb_strlen($p) < 4) {
            return 'auth.err_password_short';
        }
        if (strlen($p) > 200) {
            return 'auth.err_password_short';
        }
        return null;
    }

    /** @return array{ok:bool,errors:array<string,string>,user_id?:int} */
    public function register(string $name, string $email, string $password, string $confirm, ?string $mobile, string $lang, string $ip): array
    {
        $errors = [];
        $name = trim(preg_replace('/\s+/', ' ', $name));
        $email = strtolower(trim($email));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            $errors['name'] = 'auth.err_name';
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors['email'] = 'auth.err_email';
        }
        if ($e = self::validatePassword($password)) {
            $errors['password'] = $e;
        } elseif ($password !== $confirm) {
            $errors['password_confirm'] = 'auth.err_password_match';
        }
        $mobile = $mobile !== null ? preg_replace('/[^\d+]/', '', $mobile) : null;
        if ($mobile && !preg_match('/^\+?\d{8,15}$/', $mobile)) {
            $errors['mobile'] = 'auth.err_mobile';
        }
        if ($errors) {
            return ['ok' => false, 'errors' => $errors];
        }
        if (!RateLimiter::hit('register:ip:' . $ip, 10, 3600)) {
            return ['ok' => false, 'errors' => ['_' => 'auth.err_rate']];
        }
        $existing = DB::one('SELECT id, email_verified_at, status FROM users WHERE email = ?', [$email]);
        if ($existing) {
            // Generic: do not reveal whether the address exists. Re-send verification if still unverified.
            if (!$existing['email_verified_at'] && $existing['status'] === 'active') {
                $this->sendVerification((int) $existing['id'], $name, $email, $lang);
            }
            return ['ok' => true, 'errors' => [], 'user_id' => (int) $existing['id'], 'existing' => true];
        }
        $now = DB::now();
        $id = DB::tx(function () use ($name, $email, $password, $mobile, $lang, $now) {
            $id = DB::insert('users', ['full_name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
                'mobile' => $mobile ?: null, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
            DB::insert('user_settings', ['user_id' => $id, 'language' => in_array($lang, ['en', 'bn'], true) ? $lang : 'en', 'email_alerts' => 1, 'updated_at' => $now]);
            return $id;
        });
        $this->sendVerification($id, $name, $email, $lang);
        return ['ok' => true, 'errors' => [], 'user_id' => $id];
    }

    public function sendVerification(int $userId, string $name, string $email, string $lang): void
    {
        $token = bin2hex(random_bytes(32));
        DB::exec('UPDATE email_verification_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [DB::now(), $userId]);
        DB::insert('email_verification_tokens', ['user_id' => $userId, 'token_hash' => hash('sha256', $token), 'expires_at' => gmdate('Y-m-d H:i:s', time() + self::VERIFY_TTL), 'created_at' => DB::now()]);
        Lang::$override = $lang;
        try {
            $subject = t('mail.verify_subject');
        } finally {
            Lang::$override = null;
        }
        $id = $this->mail->enqueue('verify_email', $email, $subject, 'verify', ['lang' => $lang, 'name' => $name, 'link' => app_url('verify-email/' . $token)], 'verify:' . $userId . ':' . substr(hash('sha256', $token), 0, 16), $userId);
        if ($id) {
            $this->mail->sendNow($id);
        }
    }

    public function verifyEmail(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        return (bool) DB::tx(function () use ($token) {
            $row = DB::one('SELECT * FROM email_verification_tokens WHERE token_hash = ? FOR UPDATE', [hash('sha256', $token)]);
            if (!$row || $row['used_at'] || strtotime($row['expires_at'] . ' UTC') < time()) {
                return false;
            }
            DB::update('email_verification_tokens', ['used_at' => DB::now()], 'id = ?', [$row['id']]);
            DB::update('users', ['email_verified_at' => DB::now(), 'updated_at' => DB::now()], 'id = ? AND email_verified_at IS NULL', [$row['user_id']]);
            return true;
        });
    }

    /** @return array{ok:bool,user?:array,error?:string} */
    public function attemptLogin(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        $max = \App\Core\Settings::int('security.login_max_attempts', 5);
        $win = \App\Core\Settings::int('security.login_window_minutes', 15) * 60;
        $k1 = 'login:email:' . $email;
        $k2 = 'login:ip:' . $ip;
        if (RateLimiter::tooMany($k1, $max, $win) || RateLimiter::tooMany($k2, $max * 4, $win)) {
            return ['ok' => false, 'error' => 'auth.err_rate'];
        }
        $u = DB::one('SELECT u.*, s.language FROM users u LEFT JOIN user_settings s ON s.user_id = u.id WHERE u.email = ?', [$email]);
        // Always run a hash verification to keep timing similar for unknown emails.
        $hash = $u['password_hash'] ?? '$2y$10$usesomesillystringforsalt.abcdefghijklmnopqrstuvwxyz01234';
        $ok = password_verify($password, $hash) && $u;
        if (!$ok) {
            RateLimiter::hit($k1, $max, $win);
            RateLimiter::hit($k2, $max * 4, $win);
            return ['ok' => false, 'error' => 'auth.err_login'];
        }
        if ($u['status'] !== 'active') {
            return ['ok' => false, 'error' => 'auth.err_suspended'];
        }
        if (!$u['email_verified_at']) {
            return ['ok' => false, 'error' => 'auth.err_unverified', 'unverified' => true];
        }
        RateLimiter::clear($k1);
        if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
            DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT)], 'id = ?', [$u['id']]);
        }
        return ['ok' => true, 'user' => $u];
    }

    public function requestPasswordReset(string $email, string $ip): void
    {
        $email = strtolower(trim($email));
        if (!RateLimiter::hit('reset:ip:' . $ip, 10, 3600) || !RateLimiter::hit('reset:email:' . $email, 3, 3600)) {
            return; // silently ignore: response is identical either way
        }
        $u = DB::one("SELECT u.id, u.full_name, COALESCE(s.language,'en') AS language FROM users u LEFT JOIN user_settings s ON s.user_id = u.id WHERE u.email = ? AND u.status = 'active' AND u.email_verified_at IS NOT NULL", [$email]);
        if (!$u) {
            return;
        }
        $token = bin2hex(random_bytes(32));
        DB::exec('UPDATE password_reset_tokens SET used_at = ? WHERE user_id = ? AND used_at IS NULL', [DB::now(), $u['id']]);
        DB::insert('password_reset_tokens', ['user_id' => $u['id'], 'token_hash' => hash('sha256', $token), 'expires_at' => gmdate('Y-m-d H:i:s', time() + self::RESET_TTL), 'ip_address' => $ip, 'created_at' => DB::now()]);
        Lang::$override = $u['language'];
        try {
            $subject = t('mail.reset_subject');
        } finally {
            Lang::$override = null;
        }
        $id = $this->mail->enqueue('password_reset', $email, $subject, 'reset', ['lang' => $u['language'], 'name' => $u['full_name'], 'link' => app_url('reset-password/' . $token)], 'reset:' . $u['id'] . ':' . substr(hash('sha256', $token), 0, 16), (int) $u['id']);
        if ($id) {
            $this->mail->sendNow($id);
        }
    }

    public function tokenValid(string $token): bool
    {
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            return false;
        }
        $row = DB::one('SELECT expires_at, used_at FROM password_reset_tokens WHERE token_hash = ?', [hash('sha256', $token)]);
        return $row && !$row['used_at'] && strtotime($row['expires_at'] . ' UTC') > time();
    }

    /** @return string|null error key, null on success */
    public function resetPassword(string $token, string $password, string $confirm): ?string
    {
        if ($e = self::validatePassword($password)) {
            return $e;
        }
        if ($password !== $confirm) {
            return 'auth.err_password_match';
        }
        $ok = DB::tx(function () use ($token, $password) {
            if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
                return false;
            }
            $row = DB::one('SELECT * FROM password_reset_tokens WHERE token_hash = ? FOR UPDATE', [hash('sha256', $token)]);
            if (!$row || $row['used_at'] || strtotime($row['expires_at'] . ' UTC') < time()) {
                return false;
            }
            DB::update('password_reset_tokens', ['used_at' => DB::now()], 'id = ?', [$row['id']]);
            DB::update('users', ['password_hash' => password_hash($password, PASSWORD_DEFAULT), 'updated_at' => DB::now()], 'id = ?', [$row['user_id']]);
            return true;
        });
        return $ok ? null : 'auth.err_token';
    }

    public function changePassword(int $userId, string $current, string $new, string $confirm): ?string
    {
        $u = DB::one('SELECT password_hash FROM users WHERE id = ?', [$userId]);
        if (!$u || !password_verify($current, $u['password_hash'])) {
            return 'auth.err_current_password';
        }
        if ($e = self::validatePassword($new)) {
            return $e;
        }
        if ($new !== $confirm) {
            return 'auth.err_password_match';
        }
        DB::update('users', ['password_hash' => password_hash($new, PASSWORD_DEFAULT), 'updated_at' => DB::now()], 'id = ?', [$userId]);
        return null;
    }
}
