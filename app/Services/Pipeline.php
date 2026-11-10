<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Settings;

/** Stage runners shared by the cron scripts and the admin "run now" actions. */
final class Pipeline
{
    /** fetched -> parsed -> staged | review_required */
    public function processImports(): string
    {
        $svc = new ImportService();
        $ids = array_column(DB::all("SELECT id FROM result_imports WHERE status = 'fetched' ORDER BY id LIMIT 25"), 'id');
        $ok = $bad = 0;
        foreach ($ids as $id) {
            $r = $svc->process((int) $id);
            in_array($r['status'], ['staged'], true) ? $ok++ : $bad++;
        }
        return count($ids) . " imports processed ($ok staged, $bad need attention)";
    }

    /** staged -> IRD cross-check -> verified | disputed (optionally auto-publish). */
    public function verifyResults(?IRDVerifier $verifier = null): string
    {
        if (!Settings::int('imports.irD_verification', 1)) {
            return 'IRD verification disabled';
        }
        $verifier ??= new IRDVerifier();
        $ids = array_column(DB::all("SELECT id FROM result_imports WHERE status = 'staged' AND (secondary_report IS NULL OR JSON_UNQUOTE(JSON_EXTRACT(secondary_report, '$.status')) = 'unavailable') ORDER BY id LIMIT 10"), 'id');
        $out = [];
        foreach ($ids as $id) {
            $r = $verifier->verifyImport((int) $id);
            $out[] = "#$id:" . $r['status'];
            if ($r['status'] === 'agree' && Settings::int('imports.auto_publish', 0) === 1) {
                $imp = DB::one('SELECT status FROM result_imports WHERE id = ?', [$id]);
                if ($imp && $imp['status'] === 'verified') {
                    (new DrawService())->publish((int) $id, null, '', true);
                    $out[] = "#$id:auto-published";
                }
            }
        }
        return $ids ? implode(', ', $out) : 'nothing to verify';
    }

    /** published -> matched -> notifications_queued. */
    public function runMatching(?int $onlyDrawId = null): string
    {
        $m = new MatchingService();
        $n = new NotificationService();
        $sql = "SELECT id FROM prize_draws WHERE publication_status = 'published' AND verification_status = 'verified' AND lifecycle = 'published'";
        $params = [];
        if ($onlyDrawId) {
            $sql = 'SELECT id FROM prize_draws WHERE id = ?';
            $params = [$onlyDrawId];
        }
        $out = [];
        foreach (array_column(DB::all($sql, $params), 'id') as $id) {
            $s = $m->matchDraw((int) $id);
            $q = $n->queueWinningNotifications((int) $id);
            $open = (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE draw_id = ? AND type = 'winning_match' AND status IN ('pending','sending','review')", [$id]);
            DB::exec("UPDATE prize_draws SET lifecycle = ?, updated_at = ? WHERE id = ?", [$open > 0 ? 'notifications_queued' : 'completed', DB::now(), $id]);
            $out[] = "draw #$id: {$s['total']} matches ({$s['new']} new), {$q['queued']} emails queued";
        }
        return $out ? implode('; ', $out) : 'no draws awaiting matching';
    }

    /** Failed (but retryable) rows are re-armed by an administrator or this job; 'review' rows are never auto-resent. */
    public function retryFailed(): string
    {
        $n = DB::exec("UPDATE notification_queue SET status = 'pending', next_attempt_at = ?, updated_at = ?
                       WHERE status = 'failed' AND type <> 'winning_match' AND attempts < max_attempts + 3 AND updated_at < ?", [DB::now(), DB::now(), gmdate('Y-m-d H:i:s', time() - 3600)]);
        $imports = DB::all("SELECT id FROM result_imports WHERE status = 'failed' AND updated_at < ? LIMIT 5", [gmdate('Y-m-d H:i:s', time() - 1800)]);
        foreach ($imports as $i) {
            DB::update('result_imports', ['status' => 'fetched', 'updated_at' => DB::now()], 'id = ?', [$i['id']]);
        }
        $sent = (new NotificationService())->processDue();
        return "re-armed $n failed emails, " . count($imports) . ' imports reset, delivery pass: ' . json_encode($sent);
    }

    public function maintenance(): string
    {
        $days = Settings::int('maintenance.log_retention_days', 90);
        $cut = gmdate('Y-m-d H:i:s', time() - $days * 86400);
        $c = [];
        $c[] = DB::exec('DELETE FROM email_verification_tokens WHERE expires_at < ? OR used_at IS NOT NULL AND used_at < ?', [DB::now(), $cut]);
        $c[] = DB::exec('DELETE FROM password_reset_tokens WHERE expires_at < ? OR used_at IS NOT NULL AND used_at < ?', [DB::now(), $cut]);
        $c[] = DB::exec('DELETE FROM rate_limits WHERE window_start < ?', [gmdate('Y-m-d H:i:s', time() - 86400)]);
        $c[] = DB::exec('DELETE FROM cron_job_logs WHERE started_at < ?', [$cut]);
        $c[] = DB::exec('DELETE FROM notification_logs WHERE created_at < ?', [$cut]);
        $c[] = DB::exec('DELETE FROM import_logs WHERE created_at < ?', [$cut]);
        // Unverified accounts that never confirmed within 30 days
        $c[] = DB::exec("DELETE FROM users WHERE email_verified_at IS NULL AND created_at < ?", [gmdate('Y-m-d H:i:s', time() - 30 * 86400)]);
        // Deletion requests older than 14 days (grace period)
        $c[] = DB::exec("DELETE FROM users WHERE status = 'deletion_requested' AND deletion_requested_at < ?", [gmdate('Y-m-d H:i:s', time() - 14 * 86400)]);
        // Cache + old session files
        foreach (glob(base_path('storage/cache') . '/*.cache') ?: [] as $f) {
            if (filemtime($f) < time() - 86400) {
                @unlink($f);
            }
        }
        foreach (glob(base_path('storage/sessions') . '/sess_*') ?: [] as $f) {
            if (filemtime($f) < time() - 7 * 86400) {
                @unlink($f);
            }
        }
        $backup = '';
        try {
            $b = (new BackupService())->run();
            $backup = ', backup ' . basename($b['file']) . ' (' . $b['bytes'] . ' bytes, verified)';
        } catch (\Throwable $e) {
            Logger::error('backup', $e->getMessage());
            $backup = ', backup FAILED: ' . $e->getMessage();
        }
        return 'purged ' . array_sum($c) . ' expired rows' . $backup;
    }
}
