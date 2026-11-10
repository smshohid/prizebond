<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DB;
use App\Services\ArrayTransport;
use App\Services\MatchingService;
use App\Services\NotificationService;
use App\Services\Pipeline;
use Tests\TestCase;

final class NotificationServiceTest extends TestCase
{
    private int $draw;

    protected function setUp(): void
    {
        parent::setUp();
        $this->draw = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283'], '2' => ['0842511'], '5' => ['0021355', '0034451']]);
    }

    private function runAll(): array
    {
        (new MatchingService())->matchDraw($this->draw);
        return (new NotificationService())->queueWinningNotifications($this->draw);
    }

    public function testOneEmailPerUserPerDrawSummarisingAllMatches(): void
    {
        $a = $this->createUser('a@example.com', 'Str0ngPassw0rd', true, 'Alice');
        $b = $this->createUser('b@example.com');
        $this->addBond($a, '0923283', 'KK', '2025-01-01');
        $this->addBond($a, '0021355', 'LB', '2025-01-01');
        $this->addBond($a, '0034451', '', '2025-01-01');
        $this->addBond($b, '0842511');
        $q = $this->runAll();
        $this->assertSame(2, $q['queued']);
        $this->assertSame(2, (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE type = 'winning_match'"));

        $res = (new NotificationService())->processDue();
        $this->assertSame(2, $res['sent']);
        $this->assertCount(2, ArrayTransport::$sent);
        $mailA = array_values(array_filter(ArrayTransport::$sent, fn($m) => $m['to'] === 'a@example.com'))[0];
        foreach (['0923283', '0021355', '0034451', 'KK', 'LB', 'Alice', 'Tk. 6,00,000', 'Tk. 10,000', 'Draw 125'] as $needle) {
            $this->assertStringContainsString($needle, $mailA['text'] . $mailA['subject'], $needle);
        }
        $this->assertStringContainsString('Our system has detected a possible winning match in the published draw result. Please verify the result with Bangladesh Bank or the appropriate authorized government channel before making a claim.', $mailA['text']);
        $this->assertStringContainsString('https://www.bb.org.bd/investfacility/prizebond/125thdraw.pdf', $mailA['html']);
        $this->assertStringContainsString('/matches', $mailA['html']);
        $this->assertStringNotContainsString('0842511', $mailA['text'], 'never leaks another user\'s bond');
        $this->assertStringNotContainsStringIgnoringCase('you have won', $mailA['text']);
        $this->assertSame(['sent'], array_unique(array_column(DB::all('SELECT notification_status FROM prize_matches'), 'notification_status')));
    }

    public function testRerunningMatchingQueueingAndSendingNeverDuplicates(): void
    {
        $a = $this->createUser();
        $this->addBond($a, '0923283');
        $this->runAll();
        (new NotificationService())->processDue();
        for ($i = 0; $i < 3; $i++) {
            $this->runAll();
            (new NotificationService())->processDue();
            (new Pipeline())->runMatching($this->draw);
            (new NotificationService())->processDue();
        }
        $this->assertCount(1, ArrayTransport::$sent);
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE type = 'winning_match'"));
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM notification_logs WHERE status = \'sent\''));
    }

    public function testIdempotencyKeyBlocksADirectDuplicateInsert(): void
    {
        $svc = new NotificationService();
        $k = 'win:1:1:v1';
        $this->assertNotNull($svc->enqueue('winning_match', 'x@example.com', 's', 'admin_alert', ['lang' => 'en', 'message' => 'm'], $k));
        $this->assertNull($svc->enqueue('winning_match', 'x@example.com', 's', 'admin_alert', ['lang' => 'en', 'message' => 'm'], $k));
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM notification_queue'));
    }

    public function testQueueingIsNotDeliveryAndFailuresAreRetried(): void
    {
        $this->addBond($this->createUser(), '0923283');
        $this->runAll();
        $this->assertCount(0, ArrayTransport::$sent, 'queue creation alone sends nothing');
        $this->assertSame('queued', DB::val('SELECT notification_status FROM prize_matches'));

        ArrayTransport::$failNext = 1;
        $svc = new NotificationService();
        $r = $svc->processDue();
        $this->assertSame(['sent' => 0, 'failed' => 0, 'retry' => 1], $r);
        $row = DB::one('SELECT * FROM notification_queue');
        $this->assertSame('pending', $row['status']);
        $this->assertSame(1, (int) $row['attempts']);
        $this->assertStringContainsString('Simulated', $row['last_error']);
        $this->assertGreaterThan(DB::now(), $row['next_attempt_at'], 'backoff applied');
        $this->assertSame('queued', DB::val('SELECT notification_status FROM prize_matches'), 'not marked sent after a failure');

        $this->assertSame(0, $svc->processDue()['sent'], 'not retried before next_attempt_at');
        DB::exec('UPDATE notification_queue SET next_attempt_at = ?', [gmdate('Y-m-d H:i:s', time() - 5)]);
        $this->assertSame(1, $svc->processDue()['sent']);
        $this->assertSame('sent', DB::val('SELECT status FROM notification_queue'));
        $this->assertSame('sent', DB::val('SELECT notification_status FROM prize_matches'));
        $this->assertSame(['failed', 'sent'], array_column(DB::all('SELECT status FROM notification_logs ORDER BY id'), 'status'));
        $this->assertCount(1, ArrayTransport::$sent);
    }

    public function testGivesUpAfterMaxAttemptsAndRecordsFailedJob(): void
    {
        \App\Core\Settings::set('mail.max_attempts', '2');
        $this->addBond($this->createUser(), '0923283');
        $this->runAll();
        $svc = new NotificationService();
        ArrayTransport::$failNext = 5;
        $svc->processDue();
        DB::exec('UPDATE notification_queue SET next_attempt_at = ?', [gmdate('Y-m-d H:i:s', time() - 5)]);
        $r = $svc->processDue();
        $this->assertSame(1, $r['failed']);
        $this->assertSame('failed', DB::val('SELECT status FROM notification_queue'));
        $this->assertSame('failed', DB::val('SELECT notification_status FROM prize_matches'));
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM failed_jobs WHERE job_name = 'send_notification'"));
    }

    public function testAmbiguousTimeoutAfterDeliveryIsNeverAutoResent(): void
    {
        $this->addBond($this->createUser(), '0923283');
        $this->runAll();
        ArrayTransport::$failNext = 1;
        ArrayTransport::$throwAfterRecord = true;              // server accepted the message, then the connection timed out
        ArrayTransport::$failMessage = 'SMTP Error: connection timed out';
        $svc = new NotificationService();
        $svc->processDue();
        $this->assertSame('review', DB::val('SELECT status FROM notification_queue'));
        DB::exec('UPDATE notification_queue SET next_attempt_at = ?', [gmdate('Y-m-d H:i:s', time() - 5)]);
        $svc->processDue();
        (new Pipeline())->retryFailed();
        $this->assertCount(1, ArrayTransport::$sent, 'exactly one copy was delivered; no automatic duplicate');
    }

    public function testStuckSendingRowsGoToReviewNotResend(): void
    {
        $this->addBond($this->createUser(), '0923283');
        $this->runAll();
        DB::exec("UPDATE notification_queue SET status = 'sending', locked_at = ?", [gmdate('Y-m-d H:i:s', time() - 7200)]);
        (new NotificationService())->processDue();
        $this->assertSame('review', DB::val('SELECT status FROM notification_queue'));
        $this->assertCount(0, ArrayTransport::$sent);
    }

    public function testRowAlreadyBeingSentIsNotClaimedTwice(): void
    {
        $this->addBond($this->createUser(), '0923283');
        $this->runAll();
        DB::exec("UPDATE notification_queue SET status = 'sending', locked_at = ?", [DB::now()]);
        $r = (new NotificationService())->processDue();
        $this->assertSame(['sent' => 0, 'failed' => 0, 'retry' => 0], $r);
        $this->assertCount(0, ArrayTransport::$sent);
    }

    public function testUsersWithAlertsOffSuspendedOrUnverifiedGetNothing(): void
    {
        $off = $this->createUser('off@example.com');
        DB::exec('UPDATE user_settings SET email_alerts = 0 WHERE user_id = ?', [$off]);
        $susp = $this->createUser('susp@example.com');
        DB::exec("UPDATE users SET status = 'suspended' WHERE id = ?", [$susp]);
        $unv = $this->createUser('unv@example.com', 'Str0ngPassw0rd', false);
        foreach ([$off, $susp, $unv] as $u) {
            $this->addBond($u, '0923283');
        }
        $q = $this->runAll();
        $this->assertSame(0, $q['queued']);
        (new NotificationService())->processDue();
        $this->assertCount(0, ArrayTransport::$sent);
    }

    public function testBanglaUserReceivesBanglaEmail(): void
    {
        $u = $this->createUser();
        DB::exec("UPDATE user_settings SET language = 'bn' WHERE user_id = ?", [$u]);
        $this->addBond($u, '0923283');
        $this->runAll();
        (new NotificationService())->processDue();
        $this->assertStringContainsString('প্রকাশিত ড্র-এর ফলাফলে একটি সম্ভাব্য বিজয়ী মিল শনাক্ত করেছে', ArrayTransport::$sent[0]['text']);
        $this->assertStringContainsString('ড্র-এ প্রাইজবন্ড মিলের সম্ভাবনা', ArrayTransport::$sent[0]['subject']);
    }

    public function testPipelineAdvancesLifecycleToCompleted(): void
    {
        $this->addBond($this->createUser(), '0923283');
        (new Pipeline())->runMatching();
        $this->assertSame('notifications_queued', DB::val('SELECT lifecycle FROM prize_draws WHERE id = ?', [$this->draw]));
        (new NotificationService())->processDue();
        $this->assertSame('completed', DB::val('SELECT lifecycle FROM prize_draws WHERE id = ?', [$this->draw]));
    }

    public function testDrawWithNoMatchesCompletesImmediately(): void
    {
        (new Pipeline())->runMatching();
        $this->assertSame('completed', DB::val('SELECT lifecycle FROM prize_draws WHERE id = ?', [$this->draw]));
    }

    public function testTransportIsAlwaysTheTestTransport(): void
    {
        $this->assertInstanceOf(ArrayTransport::class, \App\Services\Mailer::transport());
    }
}
