<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Lang;
use App\Core\Logger;
use App\Core\Settings;
use App\Core\View;

/**
 * Email queue: enqueue (idempotent), send with retry, delivery logs.
 * A queue row is only 'sent' after the transport reports success.
 */
final class NotificationService
{
    public const DISCLAIMER_KEY = 'mail.win_disclaimer';

    /** Enqueue one email. Returns queue id, or null when the idempotency key already exists. */
    public function enqueue(string $type, string $to, string $subject, string $template, array $data, string $key, ?int $userId = null, ?int $drawId = null, ?\DateTimeInterface $at = null): ?int
    {
        $now = DB::now();
        $n = DB::exec('INSERT IGNORE INTO notification_queue
            (type, user_id, draw_id, recipient_email, subject, payload, status, attempts, max_attempts, next_attempt_at, idempotency_key, created_at, updated_at)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?)', [
            $type, $userId, $drawId, $to, $subject, json_encode(['template' => $template, 'data' => $data], JSON_UNESCAPED_UNICODE),
            'pending', 0, Settings::int('mail.max_attempts', 5), ($at ? $at->format('Y-m-d H:i:s') : $now), substr($key, 0, 190), $now, $now,
        ]);
        if ($n === 0) {
            return null;
        }
        return (int) DB::pdo()->lastInsertId();
    }

    /** Try to deliver a single queued email now. */
    public function sendNow(int $id): bool
    {
        return $this->deliver($id);
    }

    /**
     * Queue matched prizes: ONE email per user per draw (per draw version).
     * @return array{queued:int,skipped:int}
     */
    public function queueWinningNotifications(int $drawId): array
    {
        $draw = DB::one('SELECT d.*, bt.name AS bond_name FROM prize_draws d JOIN bond_types bt ON bt.id = d.bond_type_id WHERE d.id = ?', [$drawId]);
        if (!$draw || $draw['publication_status'] !== 'published' || $draw['verification_status'] !== 'verified') {
            throw new \RuntimeException('Notifications can only be queued for verified, published draws.');
        }
        // Matches that never need an email
        DB::exec("UPDATE prize_matches SET notification_status = 'not_required', updated_at = ? WHERE draw_id = ? AND notification_status = 'pending' AND eligibility_status = 'ineligible'", [DB::now(), $drawId]);
        DB::exec("UPDATE prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id LEFT JOIN user_settings s ON s.user_id = b.user_id
                  SET pm.notification_status = 'not_required', pm.updated_at = ?
                  WHERE pm.draw_id = ? AND pm.notification_status = 'pending' AND COALESCE(s.email_alerts, 1) = 0", [DB::now(), $drawId]);

        $users = DB::all("SELECT DISTINCT u.id, u.full_name, u.email, COALESCE(s.language,'en') AS language
            FROM prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id JOIN users u ON u.id = b.user_id
            LEFT JOIN user_settings s ON s.user_id = u.id
            WHERE pm.draw_id = ? AND pm.notification_status = 'pending' AND pm.match_status = 'detected'
              AND u.status = 'active' AND u.email_verified_at IS NOT NULL", [$drawId]);
        $queued = 0;
        $skipped = 0;
        foreach ($users as $u) {
            DB::tx(function () use ($u, $draw, $drawId, &$queued, &$skipped) {
                $rows = DB::all("SELECT pm.id, pm.amount_snapshot, pm.eligibility_status, pm.eligibility_note, b.series, b.number, pt.name AS tier_name, pt.name_bn AS tier_name_bn
                    FROM prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id
                    JOIN winning_numbers wn ON wn.id = pm.winning_number_id JOIN prize_tiers pt ON pt.id = wn.prize_tier_id
                    WHERE pm.draw_id = ? AND b.user_id = ? AND pm.notification_status = 'pending' AND pm.match_status = 'detected'
                    ORDER BY pt.rank_order, b.number FOR UPDATE", [$drawId, $u['id']]);
                if (!$rows) {
                    return;
                }
                $key = sprintf('win:%d:%d:v%d', $u['id'], $drawId, $draw['version']);
                Lang::$override = $u['language'];
                try {
                    $subject = t('mail.win_subject', ['draw' => $draw['draw_number']]);
                } finally {
                    Lang::$override = null;
                }
                $id = $this->enqueue('winning_match', $u['email'], $subject, 'winning', [
                    'lang' => $u['language'],
                    'name' => $u['full_name'],
                    'draw_number' => $draw['draw_number'],
                    'draw_date' => $draw['draw_date'],
                    'source_url' => $draw['source_url'] ?: BangladeshBankAdapter::URL,
                    'matches' => array_map(fn($r) => [
                        'series' => $r['series'], 'number' => $r['number'], 'tier' => $r['tier_name'], 'tier_bn' => $r['tier_name_bn'],
                        'amount' => $r['amount_snapshot'], 'eligibility' => $r['eligibility_status'], 'note' => $r['eligibility_note'],
                    ], $rows),
                    'match_ids' => array_column($rows, 'id'),
                ], $key, (int) $u['id'], $drawId);
                $ids = array_column($rows, 'id');
                DB::exec("UPDATE prize_matches SET notification_status = 'queued', updated_at = ? WHERE id IN (" . DB::placeholders(count($ids)) . ')', [DB::now(), ...$ids]);
                $id === null ? $skipped++ : $queued++;
            });
        }
        return ['queued' => $queued, 'skipped' => $skipped];
    }

    /** Send due emails. @return array{sent:int,failed:int,retry:int} */
    public function processDue(?int $limit = null): array
    {
        $limit ??= Settings::int('mail.batch_size', 50);
        $this->recoverStuck();
        $ids = array_column(DB::all("SELECT id FROM notification_queue WHERE status = 'pending' AND next_attempt_at <= ? ORDER BY id LIMIT ?", [DB::now(), $limit]), 'id');
        $stat = ['sent' => 0, 'failed' => 0, 'retry' => 0];
        foreach ($ids as $id) {
            $r = $this->deliver((int) $id);
            $row = DB::one('SELECT status FROM notification_queue WHERE id = ?', [$id]);
            if ($r) {
                $stat['sent']++;
            } elseif ($row && $row['status'] === 'failed') {
                $stat['failed']++;
            } else {
                $stat['retry']++;
            }
        }
        return $stat;
    }

    /** Rows stuck in 'sending' (worker died) go to manual review - never auto-resent, to avoid duplicates. */
    private function recoverStuck(): void
    {
        $n = DB::exec("UPDATE notification_queue SET status = 'review', last_error = 'Worker stopped while sending; delivery state unknown. Review before resending.', updated_at = ?
                       WHERE status = 'sending' AND locked_at < ?", [DB::now(), gmdate('Y-m-d H:i:s', time() - 1800)]);
        if ($n) {
            Logger::warning('mail', "$n queue rows moved to review (stuck in sending)");
        }
    }

    private function deliver(int $id): bool
    {
        // Atomic claim: only one worker can move pending -> sending.
        $claimed = DB::exec("UPDATE notification_queue SET status = 'sending', locked_at = ?, attempts = attempts + 1, updated_at = ? WHERE id = ? AND status = 'pending'", [DB::now(), DB::now(), $id]);
        if ($claimed !== 1) {
            return false;
        }
        $row = DB::one('SELECT * FROM notification_queue WHERE id = ?', [$id]);
        try {
            $payload = json_decode((string) $row['payload'], true) ?: [];
            [$html, $text] = $this->render($payload, $row['subject']);
            Mailer::transport()->send($row['recipient_email'], $row['subject'], $html, $text);
        } catch (\Throwable $e) {
            $err = Logger::scrub(mb_substr($e->getMessage(), 0, 500));
            // A timeout/connection loss may have happened AFTER the server accepted the message.
            // For winning alerts we refuse to auto-resend in that ambiguous case (duplicate-alert guard).
            $ambiguous = $row['type'] === 'winning_match' && preg_match('/time(d)?[\s_-]*out|connection (lost|reset|closed)|data not accepted|broken pipe/i', $err);
            if ($ambiguous) {
                DB::update('notification_queue', ['status' => 'review', 'last_error' => 'Ambiguous delivery (' . $err . '). Verify with the mail provider before resending.', 'locked_at' => null, 'updated_at' => DB::now()], 'id = ?', [$id]);
                DB::insert('notification_logs', ['queue_id' => $id, 'recipient_email' => $row['recipient_email'], 'type' => $row['type'], 'status' => 'failed', 'attempt' => $row['attempts'], 'error' => 'ambiguous: ' . $err, 'created_at' => DB::now()]);
                return false;
            }
            $final = (int) $row['attempts'] >= (int) $row['max_attempts'];
            $backoff = max(1, Settings::int('mail.retry_backoff_minutes', 30)) * (int) $row['attempts'];
            DB::update('notification_queue', [
                'status' => $final ? 'failed' : 'pending',
                'last_error' => $err,
                'next_attempt_at' => gmdate('Y-m-d H:i:s', time() + $backoff * 60),
                'locked_at' => null,
                'updated_at' => DB::now(),
            ], 'id = ?', [$id]);
            DB::insert('notification_logs', ['queue_id' => $id, 'recipient_email' => $row['recipient_email'], 'type' => $row['type'], 'status' => 'failed', 'attempt' => $row['attempts'], 'error' => $err, 'created_at' => DB::now()]);
            if ($final) {
                DB::insert('failed_jobs', ['job_name' => 'send_notification', 'reference' => (string) $id, 'payload' => json_encode(['type' => $row['type']]), 'error' => $err, 'attempts' => $row['attempts'], 'created_at' => DB::now()]);
                $this->markMatches($payload ?? [], 'failed');
            }
            Logger::error('mail', "Delivery failed for queue #$id: $err");
            return false;
        }
        DB::update('notification_queue', ['status' => 'sent', 'sent_at' => DB::now(), 'locked_at' => null, 'last_error' => null, 'updated_at' => DB::now()], 'id = ?', [$id]);
        DB::insert('notification_logs', ['queue_id' => $id, 'recipient_email' => $row['recipient_email'], 'type' => $row['type'], 'status' => 'sent', 'attempt' => $row['attempts'], 'created_at' => DB::now()]);
        $this->markMatches($payload, 'sent');
        $this->completeDrawIfDone($row['draw_id'] ? (int) $row['draw_id'] : null);
        return true;
    }

    private function markMatches(array $payload, string $status): void
    {
        $ids = $payload['data']['match_ids'] ?? [];
        if ($ids) {
            DB::exec("UPDATE prize_matches SET notification_status = ?, updated_at = ? WHERE id IN (" . DB::placeholders(count($ids)) . ')', [$status, DB::now(), ...array_map('intval', $ids)]);
        }
    }

    private function completeDrawIfDone(?int $drawId): void
    {
        if (!$drawId) {
            return;
        }
        $open = DB::val("SELECT COUNT(*) FROM notification_queue WHERE draw_id = ? AND type = 'winning_match' AND status IN ('pending','sending','review')", [$drawId]);
        if ((int) $open === 0) {
            DB::exec("UPDATE prize_draws SET lifecycle = 'completed', updated_at = ? WHERE id = ? AND lifecycle = 'notifications_queued'", [DB::now(), $drawId]);
        }
    }

    /** @return array{0:string,1:string} [html, text] */
    private function render(array $payload, string $subject): array
    {
        $data = $payload['data'] ?? [];
        Lang::$override = in_array($data['lang'] ?? 'en', ['en', 'bn'], true) ? $data['lang'] : 'en';
        try {
            $body = View::partial('emails/' . basename((string) ($payload['template'] ?? 'generic')), $data);
            $html = View::partial('emails/layout', ['subject' => $subject, 'body' => $body]);
        } finally {
            Lang::$override = null;
        }
        $text = trim(html_entity_decode(strip_tags(preg_replace(['/<(br|\/p|\/tr|\/h\d|\/li)[^>]*>/i', '/<style.*?<\/style>/is'], ["\n", ''], $body)), ENT_QUOTES, 'UTF-8'));
        return [$html, preg_replace("/\n{3,}/", "\n\n", $text)];
    }

    /** Admin/system alert (import failures, disputes, etc.). */
    public function alertAdmins(string $subject, string $message, string $key): void
    {
        $to = (string) Settings::get('mail.admin_alert_email', '');
        if ($to === '') {
            $to = (string) (DB::val("SELECT a.email FROM admin_users a JOIN admin_roles r ON r.id = a.role_id WHERE r.name = 'super_admin' AND a.status = 'active' ORDER BY a.id LIMIT 1") ?? '');
        }
        Logger::warning('alerts', $subject . ' - ' . $message);
        if ($to === '') {
            return;
        }
        $this->enqueue('admin_alert', $to, '[Prize Bond Monitor] ' . $subject, 'admin_alert', ['lang' => 'en', 'message' => $message], 'alert:' . $key);
    }
}
