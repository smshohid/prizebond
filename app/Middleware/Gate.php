<?php
declare(strict_types=1);

namespace App\Middleware;

use App\Core\AdminAuth;
use App\Core\Auth;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;

/** Route middleware: 'auth', 'guest', 'admin', 'perm:<permission>'. */
final class Gate
{
    public static function check(string $name, Request $req): ?Response
    {
        if ($name === 'auth') {
            if (!Auth::user()) {
                flash('warning', t('auth.login_required'));
                return Response::redirect('/login');
            }
            return null;
        }
        if ($name === 'guest') {
            return Auth::user() ? Response::redirect('/dashboard') : null;
        }
        if ($name === 'admin') {
            return AdminAuth::admin() ? null : Response::redirect('/admin/login');
        }
        if (str_starts_with($name, 'perm:')) {
            if (!AdminAuth::admin()) {
                return Response::redirect('/admin/login');
            }
            if (!AdminAuth::can(substr($name, 5))) {
                throw new HttpException(403, 'Your role does not permit this action.');
            }
            return null;
        }
        throw new \LogicException("Unknown middleware $name");
    }
}
