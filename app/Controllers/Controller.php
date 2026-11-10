<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\HttpException;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\View;

abstract class Controller
{
    protected function view(string $view, array $data = [], string $layout = 'layouts/main'): Response
    {
        return Response::html(View::render($view, $data, $layout));
    }

    protected function redirect(string $to, ?string $flashType = null, ?string $msg = null): Response
    {
        if ($flashType && $msg) {
            flash($flashType, $msg);
        }
        return Response::redirect($to);
    }

    /** Remember submitted values (never passwords) so forms can re-populate. */
    protected function withOld(Request $r, array $except = ['password', 'password_confirm', 'current_password', 'new_password', 'new_password_confirm', '_csrf']): void
    {
        $old = array_diff_key(array_filter($r->post, 'is_string'), array_flip($except));
        Session::put('_old', $old);
    }

    protected function pagination(Request $r, int $total, int $per = 20): array
    {
        $pages = max(1, (int) ceil($total / $per));
        $page = min(max(1, $r->int('page', 1)), $pages);
        return ['page' => $page, 'pages' => $pages, 'per' => $per, 'offset' => ($page - 1) * $per, 'total' => $total];
    }

    protected function enabledTypes(): array
    {
        return DB::all('SELECT * FROM bond_types WHERE is_enabled = 1 ORDER BY denomination');
    }

    protected function notFound(): never
    {
        throw new HttpException(404);
    }

    protected function bondRateLimit(Request $r, string $bucket, int $max, int $window): void
    {
        if (!\App\Core\RateLimiter::hit($bucket . ':' . $r->ip(), $max, $window)) {
            throw new HttpException(429, t('common.rate_limited'));
        }
    }
}
