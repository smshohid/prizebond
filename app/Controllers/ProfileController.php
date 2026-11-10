<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Services\UserService;

final class DashboardController extends Controller
{
    public function index(Request $r): Response
    {
        $uid = (int) Auth::id();
        $stats = [
            'bonds' => (int) DB::val('SELECT COUNT(*) FROM prize_bonds WHERE user_id = ?', [$uid]),
            'draws' => (int) DB::val("SELECT COUNT(*) FROM prize_draws WHERE publication_status = 'published'"),
            'wins' => (int) DB::val("SELECT COUNT(*) FROM prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id WHERE b.user_id = ? AND pm.match_status = 'detected'", [$uid]),
        ];
        $latest = DB::one("SELECT d.id, d.draw_number, d.draw_date, d.published_at, d.lifecycle FROM prize_draws d WHERE d.publication_status = 'published' ORDER BY d.draw_date DESC, d.draw_number DESC LIMIT 1");
        $u = Auth::user();
        $mailFailures = (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE user_id = ? AND status IN ('failed','review')", [$uid]);
        return $this->view('user/dashboard', ['title' => t('nav.dashboard'), 'stats' => $stats, 'latest' => $latest, 'alerts' => (bool) $u['email_alerts'], 'mailFailures' => $mailFailures, 'robots' => 'noindex']);
    }

    public function matches(Request $r): Response
    {
        $uid = (int) Auth::id();
        $total = (int) DB::val("SELECT COUNT(*) FROM prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id WHERE b.user_id = ? AND pm.match_status = 'detected'", [$uid]);
        $p = $this->pagination($r, $total, 25);
        $rows = DB::all("SELECT pm.*, d.draw_number, d.draw_date, d.source_url, bt.name AS bond_name, b.series, b.number, pt.name AS tier_name, pt.name_bn AS tier_name_bn
            FROM prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id JOIN prize_draws d ON d.id = pm.draw_id
            JOIN bond_types bt ON bt.id = b.bond_type_id JOIN winning_numbers wn ON wn.id = pm.winning_number_id JOIN prize_tiers pt ON pt.id = wn.prize_tier_id
            WHERE b.user_id = ? AND pm.match_status = 'detected' ORDER BY d.draw_date DESC, pt.rank_order LIMIT ? OFFSET ?", [$uid, $p['per'], $p['offset']]);
        return $this->view('user/matches', ['title' => t('nav.my_matches'), 'rows' => $rows, 'pg' => $p, 'robots' => 'noindex']);
    }
}

final class ProfileController extends Controller
{
    public function show(Request $r): Response
    {
        return $this->view('user/profile', ['title' => t('nav.profile'), 'robots' => 'noindex']);
    }

    public function update(Request $r): Response
    {
        $uid = (int) Auth::id();
        $name = trim(preg_replace('/\s+/', ' ', $r->str('name')));
        $mobile = preg_replace('/[^\d+]/', '', $r->str('mobile'));
        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) {
            return $this->redirect('/profile', 'danger', t('auth.err_name'));
        }
        if ($mobile !== '' && !preg_match('/^\+?\d{8,15}$/', $mobile)) {
            return $this->redirect('/profile', 'danger', t('auth.err_mobile'));
        }
        $lang = in_array($r->str('language'), ['en', 'bn'], true) ? $r->str('language') : 'en';
        DB::tx(function () use ($uid, $name, $mobile, $lang, $r) {
            DB::update('users', ['full_name' => $name, 'mobile' => $mobile ?: null, 'updated_at' => DB::now()], 'id = ?', [$uid]);
            DB::update('user_settings', ['language' => $lang, 'email_alerts' => $r->post('email_alerts') ? 1 : 0, 'email_marketing' => $r->post('email_marketing') ? 1 : 0, 'updated_at' => DB::now()], 'user_id = ?', [$uid]);
        });
        Lang::set($lang);
        return $this->redirect('/profile', 'success', t('profile.saved'));
    }

    public function password(Request $r): Response
    {
        $err = (new UserService())->changePassword((int) Auth::id(), (string) $r->post('current_password', ''), (string) $r->post('new_password', ''), (string) $r->post('new_password_confirm', ''));
        return $err ? $this->redirect('/profile', 'danger', t($err)) : $this->redirect('/profile', 'success', t('profile.password_changed'));
    }

    public function requestDeletion(Request $r): Response
    {
        $uid = (int) Auth::id();
        $u = DB::one('SELECT password_hash FROM users WHERE id = ?', [$uid]);
        if (!password_verify((string) $r->post('current_password', ''), $u['password_hash'])) {
            return $this->redirect('/profile', 'danger', t('auth.err_current_password'));
        }
        DB::update('users', ['status' => 'deletion_requested', 'deletion_requested_at' => DB::now(), 'updated_at' => DB::now()], 'id = ?', [$uid]);
        DB::update('user_settings', ['email_alerts' => 0, 'updated_at' => DB::now()], 'user_id = ?', [$uid]);
        Auth::logout();
        return $this->redirect('/', 'info', t('profile.deletion_requested'));
    }
}
