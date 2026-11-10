<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Core\Session;
use App\Services\ArrayTransport;
use App\Services\HttpClient;
use App\Services\ImportService;
use App\Services\NotificationService;
use App\Services\Pipeline;
use Tests\TestCase;

/**
 * Complete product workflow, driven through the real HTTP layer and cron pipeline, using a REAL
 * official Bangladesh Bank result document (draw 125, published 1 Nov 2026) as the historical fixture.
 */
final class EndToEndTest extends TestCase
{
    public function testFullWorkflowFromRegistrationToNoDuplicateAlerts(): void
    {
        // 1. user registers
        $this->post('/register', ['name' => 'Nasrin Akter', 'email' => 'nasrin@example.com', 'password' => 'Str0ngPassw0rd', 'password_confirm' => 'Str0ngPassw0rd']);
        // 2. verifies email using the link from the (test) mail transport
        preg_match('#/verify-email/([a-f0-9]{64})#', ArrayTransport::$sent[0]['html'], $m);
        $this->get('/verify-email/' . $m[1]);
        $this->post('/login', ['email' => 'nasrin@example.com', 'password' => 'Str0ngPassw0rd']);
        $this->assertNotNull(Session::get('uid'));
        $uid = (int) Session::get('uid');
        ArrayTransport::reset();

        // 3. registers several bonds (real winners of draw 125 + non-winners; one with leading zeros)
        $type = $this->typeId();
        foreach ([['KK', '0923283', '2025-06-01'],    // 1st prize, eligible
                  ['LB', '0021355', '2026-01-15'],    // 5th prize, eligible
                  ['', '0904795', ''],                // 5th prize, purchase date unknown
                  ['KK', '0000001', '2025-01-01'],    // not a winner
                  ['KL', '0873611', '2026-10-25'],    // 3rd prize but bought a week before the draw -> ineligible
                 ] as [$s, $n, $p]) {
            $this->post('/bonds', ['bond_type' => $type, 'series' => $s, 'number' => $n, 'purchase_date' => $p]);
        }
        $this->assertSame(5, (int) DB::val('SELECT COUNT(*) FROM prize_bonds WHERE user_id = ?', [$uid]));

        // second user holding one of the same numbers
        $other = $this->createUser('other@example.com');
        $this->addBond($other, '0923283', 'MM', '2024-01-01');

        // 4. a historical official document is imported (as the monitor would fetch it)
        $pdf = (string) file_get_contents($this->fixture('bb_125thdraw.pdf'));
        $listing = (string) file_get_contents($this->fixture('bb_listing.html'));
        HttpClient::fake(fn($method, $url) => str_ends_with($url, '/prizebond') ? [200, $listing, []] : [200, $pdf, []]);
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM prize_draws'));
        (new \App\Services\SourceMonitor(new \App\Services\BangladeshBankAdapter(new HttpClient(['www.bb.org.bd'])), new ImportService()))->run(true);
        (new Pipeline())->processImports();
        $imp = (int) DB::val('SELECT id FROM result_imports WHERE draw_number = 125');
        $this->assertSame('staged', DB::val('SELECT status FROM result_imports WHERE id = ?', [$imp]));
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM prize_matches'), 'nothing matches before publication');

        // 5. administrator verifies  6. publishes (via the admin UI)
        $admin = $this->createAdmin('result_manager', 'rm@example.com');
        $this->loginAdmin($admin);
        $this->post("/admin/imports/$imp/verify");
        $this->assertSame('verified', DB::val('SELECT status FROM result_imports WHERE id = ?', [$imp]));
        $this->post("/admin/imports/$imp/publish");
        $draw = (int) DB::val('SELECT id FROM prize_draws WHERE draw_number = 125');
        $this->assertGreaterThan(0, $draw);
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM prize_matches'), 'publication alone does not match or email');
        $this->assertSame(0, count(ArrayTransport::$sent));

        // 7. matching runs (cron stage)   8. correct matches
        (new Pipeline())->runMatching();
        $rows = DB::all("SELECT b.number, b.user_id, pm.eligibility_status, pm.amount_snapshot, pm.notification_status FROM prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id ORDER BY b.user_id, b.number");
        $mine = array_values(array_filter($rows, fn($r) => (int) $r['user_id'] === $uid));
        $this->assertSame(['0021355', '0873611', '0904795', '0923283'], array_column($mine, 'number'));
        $by = array_column($mine, null, 'number');
        $this->assertSame('eligible', $by['0923283']['eligibility_status']);
        $this->assertEquals(600000, $by['0923283']['amount_snapshot']);
        $this->assertSame('unknown', $by['0904795']['eligibility_status']);
        $this->assertSame('ineligible', $by['0873611']['eligibility_status']);
        $this->assertSame('not_required', $by['0873611']['notification_status']);
        $this->assertCount(5, $rows, '4 for the first user (incl. the ineligible one) + 1 for the other user; the non-winner has none');

        // 9. notifications queued (one per user)
        $this->assertSame(2, (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE type = 'winning_match'"));
        $this->assertCount(0, ArrayTransport::$sent, 'queueing does not send');

        // 10. email sending job runs with the test transport
        $r = (new NotificationService())->processDue();
        $this->assertSame(2, $r['sent']);
        $mail = array_values(array_filter(ArrayTransport::$sent, fn($x) => $x['to'] === 'nasrin@example.com'))[0];
        foreach (['0923283', '0021355', '0904795'] as $n) {
            $this->assertStringContainsString($n, $mail['text']);
        }
        $this->assertStringNotContainsString('0873611', $mail['text'], 'ineligible match is not announced');
        $this->assertStringNotContainsString('0000001', $mail['text']);
        $this->assertStringContainsString('Purchase date unknown', $mail['text']);

        // 11. delivery logs
        $this->assertSame(2, (int) DB::val("SELECT COUNT(*) FROM notification_logs WHERE status = 'sent' AND type = 'winning_match'"));
        $this->assertSame('completed', DB::val('SELECT lifecycle FROM prize_draws WHERE id = ?', [$draw]));
        $this->loginAs($uid);
        $page = $this->get('/matches')->body;
        $this->assertStringContainsString('0923283', $page);
        $this->assertStringContainsString('Sent', $page);

        // 12. re-run matching and email jobs (several times, including the admin button)
        $this->loginAdmin($admin);
        for ($i = 0; $i < 3; $i++) {
            (new Pipeline())->runMatching();
            $this->post("/admin/draws/$draw/rematch");
            (new NotificationService())->processDue();
            (new Pipeline())->retryFailed();
        }
        // 13. no duplicates anywhere
        $this->assertCount(2, array_filter(ArrayTransport::$sent, fn($m) => str_contains($m['subject'], 'draw 125')));
        $this->assertSame(5, (int) DB::val('SELECT COUNT(*) FROM prize_matches'));
        $this->assertSame(2, (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE type = 'winning_match'"));
        $this->assertSame(2, (int) DB::val("SELECT COUNT(*) FROM notification_logs WHERE status = 'sent' AND type = 'winning_match'"));

        // a bond added AFTER the draw sees history but triggers no email
        $this->loginAs($uid);
        $this->post('/bonds', ['bond_type' => $type, 'series' => 'ZZ', 'number' => '0842511']);
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id WHERE b.number = '0842511'"));
        (new Pipeline())->runMatching();
        (new NotificationService())->processDue();
        $this->assertCount(2, array_filter(ArrayTransport::$sent, fn($m) => str_contains($m['subject'], 'draw 125')));
        // public site now shows the draw
        $this->assertStringContainsString('0923283', $this->get('/')->body);
    }
}
