<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\UserService;

final class AuthController extends Controller
{
    private function svc(): UserService
    {
        return new UserService();
    }

    public function showRegister(Request $r): Response
    {
        return $this->view('auth/register', ['title' => t('nav.register'), 'errors' => Session::pull('_errors', [])]);
    }

    public function register(Request $r): Response
    {
        $res = $this->svc()->register($r->str('name'), $r->str('email'), (string) $r->post('password', ''), (string) $r->post('password_confirm', ''), $r->str('mobile') ?: null, Lang::locale(), $r->ip());
        if (!$res['ok']) {
            $this->withOld($r);
            Session::put('_errors', $res['errors']);
            return Response::redirect('/register');
        }
        // Identical response whether or not the email already existed.
        return $this->redirect('/login', 'success', t('auth.registered'));
    }

    public function verify(Request $r): Response
    {
        $ok = $this->svc()->verifyEmail((string) $r->params['token']);
        return $this->redirect('/login', $ok ? 'success' : 'danger', $ok ? t('auth.verified') : t('auth.err_token'));
    }

    public function resendVerification(Request $r): Response
    {
        $email = strtolower($r->str('email'));
        if (\App\Core\RateLimiter::hit('resend:' . $email, 3, 3600) && \App\Core\RateLimiter::hit('resend:ip:' . $r->ip(), 10, 3600)) {
            $u = DB::one("SELECT u.id, u.full_name, u.email, COALESCE(s.language,'en') AS language FROM users u LEFT JOIN user_settings s ON s.user_id = u.id WHERE u.email = ? AND u.email_verified_at IS NULL AND u.status = 'active'", [$email]);
            if ($u) {
                $this->svc()->sendVerification((int) $u['id'], $u['full_name'], $u['email'], $u['language']);
            }
        }
        return $this->redirect('/login', 'info', t('auth.resent'));
    }

    public function showLogin(Request $r): Response
    {
        return $this->view('auth/login', ['title' => t('nav.login')]);
    }

    public function login(Request $r): Response
    {
        $res = $this->svc()->attemptLogin($r->str('email'), (string) $r->post('password', ''), $r->ip());
        if (!$res['ok']) {
            $this->withOld($r);
            flash('danger', t($res['error']));
            if (!empty($res['unverified'])) {
                Session::put('_unverified_email', $r->str('email'));
            }
            return Response::redirect('/login');
        }
        Auth::login($res['user']);
        return Response::redirect('/dashboard');
    }

    public function logout(Request $r): Response
    {
        Auth::logout();
        return $this->redirect('/', 'info', t('auth.logged_out'));
    }

    public function showForgot(Request $r): Response
    {
        return $this->view('auth/forgot', ['title' => t('auth.forgot')]);
    }

    public function forgot(Request $r): Response
    {
        $this->svc()->requestPasswordReset($r->str('email'), $r->ip());
        return $this->redirect('/login', 'info', t('auth.reset_sent'));
    }

    public function showReset(Request $r): Response
    {
        $token = (string) $r->params['token'];
        if (!$this->svc()->tokenValid($token)) {
            return $this->redirect('/forgot-password', 'danger', t('auth.err_token'));
        }
        return $this->view('auth/reset', ['title' => t('auth.reset_title'), 'token' => $token]);
    }

    public function reset(Request $r): Response
    {
        $token = (string) $r->params['token'];
        $err = $this->svc()->resetPassword($token, (string) $r->post('password', ''), (string) $r->post('password_confirm', ''));
        if ($err) {
            flash('danger', t($err));
            return Response::redirect('/reset-password/' . $token);
        }
        return $this->redirect('/login', 'success', t('auth.reset_done'));
    }
}
