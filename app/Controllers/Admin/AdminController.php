<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\AdminAuth;
use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\AdminService;
use App\Services\Pipeline;

/** Login/logout, dashboard, generic read-only lists, and management actions. */
final class AdminController extends Controller
{
    private function av(string $v, array $d = []): Response
    {
        return $this->view($v, $d + ['robots' => 'noindex'], 'layouts/admin');
    }

    // ---- auth ---------------------------------------------------------------
    public function showLogin(Request $r): Response
    {
        return $this->view('admin/login', ['title' => 'Admin sign in', 'robots' => 'noindex'], 'layouts/bare');
    }

    public function login(Request $r): Response
    {
        $res = (new AdminService())->attemptLogin($r->str('email'), (string) $r->post('password', ''), $r->ip());
        if (!$res['ok']) {
            flash('danger', !empty($res['rate']) ? t('auth.err_rate') : t('auth.err_login'));
            return Response::redirect('/admin/login');
        }
        AdminAuth::login($res['admin']);
        Audit::log((int) $res['admin']['id'], 'admin.login', 'admin_user', $res['admin']['id'], null, null, $r->ip());
        return Response::redirect('/admin');
    }

    public function logout(Request $r): Response
    {
        AdminAuth::logout();
        return $this->redirect('/admin/login', 'info', 'Signed out.');
    }

    // ---- dashboard ----------------------------------------------------------
    public function dashboard(Request $r): Response
    {
        $c = fn(string $sql, array $p = []) => (int) DB::val($sql, $p);
        $stats = [
            'Registered users' => $c('SELECT COUNT(*) FROM users'),
            'Active users' => $c("SELECT COUNT(*) FROM users WHERE status = 'active' AND email_verified_at IS NOT NULL"),
            'Registered bonds' => $c('SELECT COUNT(*) FROM prize_bonds'),
            'Total draws' => $c('SELECT COUNT(*) FROM prize_draws'),
            'Published draws' => $c("SELECT COUNT(*) FROM prize_draws WHERE publication_status = 'published'"),
            'Pending imports' => $c("SELECT COUNT(*) FROM result_imports WHERE status IN ('discovered','fetched','parsed')"),
            'Pending verification' => $c("SELECT COUNT(*) FROM result_imports WHERE status IN ('staged','review_required','disputed','verified')"),
            'Winning matches' => $c("SELECT COUNT(*) FROM prize_matches WHERE match_status = 'detected'"),
            'Emails sent' => $c("SELECT COUNT(*) FROM notification_queue WHERE status = 'sent'"),
            'Failed notifications' => $c("SELECT COUNT(*) FROM notification_queue WHERE status IN ('failed','review')"),
        ];
        $src = DB::one("SELECT last_success_at, last_error FROM result_sources WHERE code = 'bb'");
        $attention = DB::all("SELECT id, draw_number, status FROM result_imports WHERE status IN ('review_required','disputed','failed','staged','verified') ORDER BY id DESC LIMIT 8");
        $cron = DB::all('SELECT job_name, status, started_at, message FROM cron_job_logs ORDER BY id DESC LIMIT 6');
        return $this->av('admin/dashboard', ['title' => 'Dashboard', 'stats' => $stats, 'src' => $src, 'attention' => $attention, 'cron' => $cron]);
    }

    // ---- generic lists ------------------------------------------------------
    private function table(Request $r, string $title, string $from, string $select, array $cols, string $order, array $where = [], array $params = [], array $extra = []): Response
    {
        $w = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $total = (int) DB::val("SELECT COUNT(*) FROM $from $w", $params);
        $p = $this->pagination($r, $total, 30);
        $rows = DB::all("SELECT $select FROM $from $w ORDER BY $order LIMIT ? OFFSET ?", [...$params, $p['per'], $p['offset']]);
        return $this->av('admin/table', ['title' => $title, 'cols' => $cols, 'rows' => $rows, 'pg' => $p, 'q' => $r->str('q')] + $extra);
    }

    private function search(Request $r, array $fields, array &$where, array &$params): void
    {
        if (($q = $r->str('q')) !== '') {
            $where[] = '(' . implode(' OR ', array_map(fn($f) => "$f LIKE ?", $fields)) . ')';
            foreach ($fields as $_) {
                $params[] = '%' . addcslashes($q, '%_\\') . '%';
            }
        }
    }

    public function users(Request $r): Response
    {
        $w = [];
        $p = [];
        $this->search($r, ['u.email', 'u.full_name'], $w, $p);
        return $this->table($r, 'Users', 'users u', 'u.id, u.full_name, u.email, u.status, u.email_verified_at, u.created_at, (SELECT COUNT(*) FROM prize_bonds b WHERE b.user_id = u.id) AS bonds', [
            ['id', 'ID'], ['full_name', 'Name'], ['email', 'Email'], ['status', 'Status', 'badge'], ['bonds', 'Bonds'],
            ['email_verified_at', 'Verified', 'dt'], ['created_at', 'Registered', 'dt'],
            ['id', 'Action', 'user_action'],
        ], 'u.id DESC', $w, $p, ['searchable' => true]);
    }

    public function userStatus(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $new = $r->str('status');
        if (!in_array($new, ['active', 'suspended'], true)) {
            $this->notFound();
        }
        $old = DB::val('SELECT status FROM users WHERE id = ?', [$id]);
        if ($old === null || $old === 'deleted') {
            $this->notFound();
        }
        DB::update('users', ['status' => $new, 'updated_at' => DB::now()], 'id = ?', [$id]);
        Audit::log((int) AdminAuth::id(), 'user.status', 'user', $id, ['status' => $old], ['status' => $new], $r->ip());
        return $this->redirect('/admin/users', 'success', "User #$id is now $new.");
    }

    public function bonds(Request $r): Response
    {
        $w = [];
        $p = [];
        $this->search($r, ['b.number', 'b.series', 'u.email'], $w, $p);
        return $this->table($r, 'Registered Bonds', 'prize_bonds b JOIN users u ON u.id = b.user_id JOIN bond_types bt ON bt.id = b.bond_type_id', 'b.id, bt.code, b.series, b.number, b.purchase_date, b.status, u.email, b.created_at', [
            ['id', 'ID'], ['code', 'Type'], ['series', 'Series'], ['number', 'Number'], ['purchase_date', 'Purchased'], ['status', 'Status', 'badge'], ['email', 'Owner'], ['created_at', 'Added', 'dt'],
        ], 'b.id DESC', $w, $p, ['searchable' => true]);
    }

    public function winningNumbers(Request $r): Response
    {
        $w = [];
        $p = [];
        $this->search($r, ['wn.number'], $w, $p);
        if ($d = $r->int('draw')) {
            $w[] = 'wn.draw_id = ?';
            $p[] = $d;
        }
        return $this->table($r, 'Winning Numbers', 'winning_numbers wn JOIN prize_draws d ON d.id = wn.draw_id JOIN prize_tiers pt ON pt.id = wn.prize_tier_id', 'wn.id, d.draw_number, pt.name AS tier, wn.number, wn.amount, wn.status', [
            ['draw_number', 'Draw'], ['tier', 'Prize'], ['number', 'Number'], ['amount', 'Amount', 'money'], ['status', 'Status', 'badge'],
        ], 'd.draw_number DESC, pt.rank_order, wn.number', $w, $p, ['searchable' => true]);
    }

    public function matches(Request $r): Response
    {
        $w = [];
        $p = [];
        $this->search($r, ['b.number', 'u.email'], $w, $p);
        return $this->table($r, 'Prize Matches', 'prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id JOIN users u ON u.id = b.user_id JOIN prize_draws d ON d.id = pm.draw_id', 'pm.id, d.draw_number, b.series, b.number, u.email, pm.amount_snapshot, pm.eligibility_status, pm.match_status, pm.notification_status, pm.created_at', [
            ['draw_number', 'Draw'], ['series', 'Series'], ['number', 'Number'], ['email', 'User'], ['amount_snapshot', 'Prize', 'money'],
            ['eligibility_status', 'Eligibility', 'badge'], ['match_status', 'Match', 'badge'], ['notification_status', 'Notice', 'badge'], ['created_at', 'Detected', 'dt'],
        ], 'pm.id DESC', $w, $p, ['searchable' => true]);
    }

    public function emailQueue(Request $r): Response
    {
        $w = [];
        $p = [];
        if (in_array($s = $r->str('status'), ['pending', 'sending', 'sent', 'failed', 'cancelled', 'review'], true)) {
            $w[] = 'status = ?';
            $p[] = $s;
        }
        $this->search($r, ['recipient_email', 'subject'], $w, $p);
        return $this->table($r, 'Email Queue', 'notification_queue', 'id, type, recipient_email, subject, status, attempts, next_attempt_at, sent_at, last_error', [
            ['id', 'ID'], ['type', 'Type'], ['recipient_email', 'To'], ['subject', 'Subject'], ['status', 'Status', 'badge'], ['attempts', 'Tries'],
            ['next_attempt_at', 'Next try', 'dt'], ['sent_at', 'Sent', 'dt'], ['last_error', 'Last error'], ['id', 'Action', 'queue_action'],
        ], 'id DESC', $w, $p, ['searchable' => true, 'statusFilter' => ['pending', 'sending', 'sent', 'failed', 'review', 'cancelled']]);
    }

    public function queueAction(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $act = $r->str('do');
        $row = DB::one('SELECT status, type FROM notification_queue WHERE id = ?', [$id]);
        if (!$row) {
            $this->notFound();
        }
        if ($act === 'retry' && in_array($row['status'], ['failed', 'review'], true)) {
            DB::update('notification_queue', ['status' => 'pending', 'attempts' => 0, 'next_attempt_at' => DB::now(), 'updated_at' => DB::now()], 'id = ?', [$id]);
        } elseif ($act === 'cancel' && in_array($row['status'], ['pending', 'failed', 'review'], true)) {
            DB::update('notification_queue', ['status' => 'cancelled', 'updated_at' => DB::now()], 'id = ?', [$id]);
        } else {
            return $this->redirect('/admin/email-queue', 'danger', 'Action not allowed for this status.');
        }
        Audit::log((int) AdminAuth::id(), 'queue.' . $act, 'notification_queue', $id, ['status' => $row['status']], null, $r->ip());
        return $this->redirect('/admin/email-queue', 'success', "Queue item #$id: $act done.");
    }

    public function emailLogs(Request $r): Response
    {
        $w = [];
        $p = [];
        $this->search($r, ['recipient_email', 'error'], $w, $p);
        return $this->table($r, 'Email Logs', 'notification_logs', 'id, queue_id, recipient_email, type, status, attempt, error, created_at', [
            ['id', 'ID'], ['queue_id', 'Queue'], ['recipient_email', 'To'], ['type', 'Type'], ['status', 'Status', 'badge'], ['attempt', 'Attempt'], ['error', 'Error'], ['created_at', 'Time', 'dt'],
        ], 'id DESC', $w, $p, ['searchable' => true]);
    }

    public function sources(Request $r): Response
    {
        return $this->table($r, 'Result Sources', 'result_sources', 'id, code, name, base_url, authority, is_enabled, check_interval_minutes, last_checked_at, last_success_at, last_error', [
            ['name', 'Source'], ['authority', 'Authority', 'badge'], ['base_url', 'URL'], ['is_enabled', 'Enabled', 'bool'], ['check_interval_minutes', 'Every (min)'],
            ['last_checked_at', 'Last checked', 'dt'], ['last_success_at', 'Last success', 'dt'], ['last_error', 'Last error'],
        ], 'id', [], [], ['note' => 'Bangladesh Bank is the final authority. IRD is a secondary cross-check. Intervals are applied by the monitor_sources cron job.']);
    }

    public function cron(Request $r): Response
    {
        $fails = (int) DB::val("SELECT COUNT(*) FROM failed_jobs WHERE resolved_at IS NULL");
        return $this->table($r, 'Cron Job Monitor', 'cron_job_logs', 'id, job_name, status, started_at, finished_at, duration_ms, message', [
            ['job_name', 'Job'], ['status', 'Status', 'badge'], ['started_at', 'Started', 'dt'], ['finished_at', 'Finished', 'dt'], ['duration_ms', 'ms'], ['message', 'Message'],
        ], 'id DESC', [], [], ['note' => "Unresolved failed jobs: $fails. Expected schedule is in CRON_SETUP.md."]);
    }

    public function audit(Request $r): Response
    {
        $w = [];
        $p = [];
        $this->search($r, ['a.action', 'a.entity', 'a.entity_id'], $w, $p);
        return $this->table($r, 'Audit Logs', 'audit_logs a LEFT JOIN admin_users au ON au.id = a.admin_id', 'a.id, au.email AS admin, a.actor, a.action, a.entity, a.entity_id, a.ip_address, a.created_at', [
            ['id', 'ID'], ['admin', 'Admin'], ['actor', 'Actor'], ['action', 'Action'], ['entity', 'Entity'], ['entity_id', 'Entity ID'], ['ip_address', 'IP'], ['created_at', 'Time', 'dt'],
        ], 'a.id DESC', $w, $p, ['searchable' => true]);
    }

    // ---- bond types / tiers --------------------------------------------------
    public function bondTypes(Request $r): Response
    {
        return $this->av('admin/bond_types', ['title' => 'Bond Types & Prize Tiers',
            'types' => DB::all('SELECT * FROM bond_types ORDER BY denomination'),
            'tiers' => DB::all('SELECT pt.*, bt.code AS type_code FROM prize_tiers pt JOIN bond_types bt ON bt.id = pt.bond_type_id ORDER BY bt.denomination, pt.rank_order')]);
    }

    public function updateType(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $t = DB::one('SELECT * FROM bond_types WHERE id = ?', [$id]);
        if (!$t) {
            $this->notFound();
        }
        $enable = $r->post('is_enabled') ? 1 : 0;
        if ($enable && !$t['source_verified']) {
            return $this->redirect('/admin/bond-types', 'danger', 'This bond type has no verified official draw format and cannot be enabled until a supported importer exists.');
        }
        DB::update('bond_types', ['is_enabled' => $enable, 'updated_at' => DB::now()], 'id = ?', [$id]);
        Audit::log((int) AdminAuth::id(), 'bond_type.update', 'bond_type', $id, ['is_enabled' => $t['is_enabled']], ['is_enabled' => $enable], $r->ip());
        return $this->redirect('/admin/bond-types', 'success', 'Bond type updated.');
    }

    public function updateTier(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $t = DB::one('SELECT * FROM prize_tiers WHERE id = ?', [$id]);
        if (!$t) {
            $this->notFound();
        }
        $amount = (float) preg_replace('/[^\d.]/', '', $r->str('amount'));
        if ($amount <= 0) {
            return $this->redirect('/admin/bond-types', 'danger', 'Invalid amount.');
        }
        $reason = $r->str('reason');
        $published = (int) DB::val("SELECT COUNT(*) FROM winning_numbers wn JOIN prize_draws d ON d.id = wn.draw_id WHERE wn.prize_tier_id = ? AND d.publication_status = 'published'", [$id]);
        if ($published > 0 && $reason === '') {
            return $this->redirect('/admin/bond-types', 'danger', 'Published draws use this tier: a reason is required (historical amounts are preserved as snapshots).');
        }
        DB::update('prize_tiers', ['amount' => $amount, 'is_active' => $r->post('is_active') ? 1 : 0, 'updated_at' => DB::now()], 'id = ?', [$id]);
        Audit::log((int) AdminAuth::id(), 'prize_tier.update', 'prize_tier', $id, ['amount' => $t['amount'], 'is_active' => $t['is_active']], ['amount' => $amount, 'reason' => $reason], $r->ip());
        return $this->redirect('/admin/bond-types', 'success', 'Prize tier saved. Already-published draws keep their original amounts.');
    }

    // ---- settings / roles ------------------------------------------------------
    public function settings(Request $r): Response
    {
        return $this->av('admin/settings', ['title' => 'System Settings', 'rows' => DB::all('SELECT * FROM system_settings ORDER BY setting_key')]);
    }

    public function saveSettings(Request $r): Response
    {
        $allowed = array_column(DB::all('SELECT setting_key FROM system_settings'), 'setting_key');
        $changed = [];
        foreach ((array) $r->post('s', []) as $k => $v) {
            $k = str_replace('__', '.', (string) $k);
            if (in_array($k, $allowed, true) && is_string($v)) {
                $old = (string) Settings::get($k);
                if ($old !== trim($v)) {
                    Settings::set($k, trim($v));
                    $changed[$k] = ['from' => $old, 'to' => trim($v)];
                }
            }
        }
        Audit::log((int) AdminAuth::id(), 'settings.update', 'system_settings', null, null, $changed, $r->ip());
        return $this->redirect('/admin/settings', 'success', count($changed) . ' setting(s) saved.');
    }

    public function roles(Request $r): Response
    {
        $perms = DB::all('SELECT r.name AS role, GROUP_CONCAT(p.name ORDER BY p.name SEPARATOR ", ") AS permissions FROM admin_roles r LEFT JOIN admin_role_permissions rp ON rp.role_id = r.id LEFT JOIN admin_permissions p ON p.id = rp.permission_id GROUP BY r.id ORDER BY r.id');
        $admins = DB::all('SELECT a.id, a.full_name, a.email, a.status, a.last_login_at, r.name AS role FROM admin_users a JOIN admin_roles r ON r.id = a.role_id ORDER BY a.id');
        return $this->av('admin/roles', ['title' => 'Roles & Permissions', 'roles' => $perms, 'admins' => $admins, 'allRoles' => DB::all('SELECT id, name FROM admin_roles ORDER BY id')]);
    }

    public function createAdmin(Request $r): Response
    {
        try {
            $id = (new AdminService())->create($r->str('name'), $r->str('email'), (string) $r->post('password', ''), $r->str('role'));
            Audit::log((int) AdminAuth::id(), 'admin.create', 'admin_user', $id, null, ['email' => strtolower($r->str('email')), 'role' => $r->str('role')], $r->ip());
            return $this->redirect('/admin/roles', 'success', 'Administrator created.');
        } catch (\InvalidArgumentException $e) {
            return $this->redirect('/admin/roles', 'danger', $e->getMessage());
        }
    }

    public function updateAdmin(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $a = DB::one('SELECT * FROM admin_users WHERE id = ?', [$id]);
        if (!$a) {
            $this->notFound();
        }
        if ($id === (int) AdminAuth::id()) {
            return $this->redirect('/admin/roles', 'danger', 'You cannot change your own role or status.');
        }
        $roleId = DB::val('SELECT id FROM admin_roles WHERE name = ?', [$r->str('role')]);
        $status = $r->str('status') === 'disabled' ? 'disabled' : 'active';
        if (!$roleId) {
            return $this->redirect('/admin/roles', 'danger', 'Unknown role.');
        }
        DB::update('admin_users', ['role_id' => $roleId, 'status' => $status, 'updated_at' => DB::now()], 'id = ?', [$id]);
        Audit::log((int) AdminAuth::id(), 'admin.update', 'admin_user', $id, ['role_id' => $a['role_id'], 'status' => $a['status']], ['role' => $r->str('role'), 'status' => $status], $r->ip());
        return $this->redirect('/admin/roles', 'success', 'Administrator updated.');
    }

    public function runJob(Request $r): Response
    {
        $job = $r->str('job');
        $p = new Pipeline();
        try {
            $msg = match ($job) {
                'send' => json_encode((new \App\Services\NotificationService())->processDue()),
                'matching' => $p->runMatching(),
                default => throw new \RuntimeException('Unknown job'),
            };
        } catch (\Throwable $e) {
            return $this->redirect('/admin/cron', 'danger', $e->getMessage());
        }
        Audit::log((int) AdminAuth::id(), 'job.run', 'cron', $job, null, ['result' => $msg], $r->ip());
        return $this->redirect('/admin/cron', 'success', "$job: $msg");
    }
}
