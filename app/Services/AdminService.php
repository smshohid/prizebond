<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\RateLimiter;

final class AdminService
{
    public const ROLES = ['super_admin', 'result_manager', 'support_admin'];

    public static function passwordError(string $p): ?string
    {
        if (strlen($p) < 12) {
            return 'Admin password must be at least 12 characters.';
        }
        if (!preg_match('/[a-z]/', $p) || !preg_match('/[A-Z]/', $p) || !preg_match('/\d/', $p)) {
            return 'Admin password needs upper-case, lower-case and a digit.';
        }
        return null;
    }

    public function create(string $name, string $email, string $password, string $role = 'super_admin'): int
    {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            throw new \InvalidArgumentException('Invalid email address.');
        }
        if ($e = self::passwordError($password)) {
            throw new \InvalidArgumentException($e);
        }
        $roleId = DB::val('SELECT id FROM admin_roles WHERE name = ?', [$role]);
        if (!$roleId) {
            throw new \InvalidArgumentException('Unknown role.');
        }
        if (DB::val('SELECT id FROM admin_users WHERE email = ?', [$email])) {
            throw new \InvalidArgumentException('An administrator with this email already exists.');
        }
        $now = DB::now();
        return DB::insert('admin_users', ['role_id' => $roleId, 'full_name' => trim($name), 'email' => $email,
            'password_hash' => password_hash($password, PASSWORD_DEFAULT), 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
    }

    /** @return array{ok:bool,admin?:array,rate?:bool} */
    public function attemptLogin(string $email, string $password, string $ip): array
    {
        $email = strtolower(trim($email));
        if (RateLimiter::tooMany('adminlogin:' . $email, 5, 900) || RateLimiter::tooMany('adminlogin:ip:' . $ip, 20, 900)) {
            return ['ok' => false, 'rate' => true];
        }
        $a = DB::one('SELECT * FROM admin_users WHERE email = ?', [$email]);
        $hash = $a['password_hash'] ?? '$2y$10$usesomesillystringforsalt.abcdefghijklmnopqrstuvwxyz01234';
        if (!(password_verify($password, $hash) && $a && $a['status'] === 'active')) {
            RateLimiter::hit('adminlogin:' . $email, 5, 900);
            RateLimiter::hit('adminlogin:ip:' . $ip, 20, 900);
            return ['ok' => false];
        }
        RateLimiter::clear('adminlogin:' . $email);
        return ['ok' => true, 'admin' => $a];
    }
}
