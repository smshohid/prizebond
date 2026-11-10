<?php
declare(strict_types=1);

namespace App\Core;

/** Public-site user authentication (session based). */
final class Auth
{
    private static ?array $user = null;
    private static bool $loaded = false;

    public static function user(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = Session::get('uid');
            if ($id) {
                $u = DB::one('SELECT u.*, s.language, s.email_alerts FROM users u LEFT JOIN user_settings s ON s.user_id = u.id WHERE u.id = ?', [(int) $id]);
                // Re-check status on every request so suspension takes effect immediately.
                self::$user = ($u && $u['status'] === 'active' && $u['email_verified_at']) ? $u : null;
                if (!self::$user) {
                    Session::forget('uid');
                }
            }
        }
        return self::$user;
    }

    public static function id(): ?int
    {
        $u = self::user();
        return $u ? (int) $u['id'] : null;
    }

    public static function login(array $user): void
    {
        Session::regenerate();
        Session::put('uid', (int) $user['id']);
        Session::forget('aid');
        if (!empty($user['language'])) {
            Session::put('lang', $user['language']);
        }
        DB::update('users', ['last_login_at' => DB::now()], 'id = ?', [$user['id']]);
        self::reset();
    }

    public static function logout(): void
    {
        Session::forget('uid');
        Session::destroy();
        self::reset();
    }

    public static function reset(): void
    {
        self::$user = null;
        self::$loaded = false;
    }
}

/** Administrator authentication + role/permission checks (server-side). */
final class AdminAuth
{
    private static ?array $admin = null;
    private static bool $loaded = false;
    private static ?array $perms = null;

    public static function admin(): ?array
    {
        if (!self::$loaded) {
            self::$loaded = true;
            $id = Session::get('aid');
            if ($id) {
                self::$admin = DB::one('SELECT a.*, r.name AS role_name FROM admin_users a JOIN admin_roles r ON r.id = a.role_id WHERE a.id = ? AND a.status = ?', [(int) $id, 'active']);
                if (!self::$admin) {
                    Session::forget('aid');
                }
            }
        }
        return self::$admin;
    }

    public static function id(): ?int
    {
        $a = self::admin();
        return $a ? (int) $a['id'] : null;
    }

    public static function login(array $admin): void
    {
        Session::regenerate();
        Session::put('aid', (int) $admin['id']);
        Session::forget('uid');
        DB::update('admin_users', ['last_login_at' => DB::now()], 'id = ?', [$admin['id']]);
        self::reset();
    }

    public static function logout(): void
    {
        Session::forget('aid');
        Session::destroy();
        self::reset();
    }

    public static function can(string $perm): bool
    {
        $a = self::admin();
        if (!$a) {
            return false;
        }
        if (self::$perms === null) {
            self::$perms = array_column(DB::all(
                'SELECT p.name FROM admin_role_permissions rp JOIN admin_permissions p ON p.id = rp.permission_id WHERE rp.role_id = ?',
                [(int) $a['role_id']]), 'name');
        }
        return in_array($perm, self::$perms, true);
    }

    public static function reset(): void
    {
        self::$admin = null;
        self::$loaded = false;
        self::$perms = null;
    }
}
