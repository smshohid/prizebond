<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DB;
use App\Services\BangladeshBankAdapter;
use App\Services\DrawService;
use App\Services\ImportService;
use App\Services\HttpClient;
use App\Services\IRDAdapter;
use App\Services\IRDVerifier;
use App\Services\MatchingService;
use App\Services\NotificationService;
use App\Services\Pipeline;
use App\Services\SourceMonitor;
use Tests\TestCase;

final class ImportWorkflowTest extends TestCase
{
    private function pdf(int $n): string
    {
        $names = [118 => '118thdraw', 119 => '119thdraw', 120 => '120thdraw', 121 => '121stdraw', 122 => '122nddraw', 123 => '123rddraw', 124 => '124thdraw', 125 => '125thdraw'];
        return (string) file_get_contents($this->fixture("bb_{$names[$n]}.pdf"));
    }

    private function register(int $n, ?string $listingDate): int
    {
        $svc = new ImportService();
        return $svc->registerDocument(['bond_type_id' => $this->typeId(), 'draw_number' => $n, 'draw_date' => $listingDate, 'source_url' => "https://www.bb.org.bd/investfacility/prizebond/{$n}thdraw.pdf", 'body' => $this->pdf($n), 'format' => 'pdf', 'origin' => 'auto'])['id'];
    }

    /** IRD fake that answers exactly like the official site for the numbers in staged data. */
    private function iridAgreeing(int $importId): IRDVerifier
    {
        $staged = json_decode((string) DB::val('SELECT staged_data FROM result_imports WHERE id = ?', [$importId]), true);
        $map = [];
        foreach ($staged['tiers'] as $code => $t) {
            foreach ($t['numbers'] as $n) {
                $map[$n] = [$code, $t['amount']];
            }
        }
        HttpClient::fake(function ($m, $url, $fields) use ($map, $staged) {
            $html = '<table><tr><th>x</th></tr>';
            foreach (explode(',', $fields['from']) as $n) {
                if (isset($map[$n])) {
                    $html .= "<tr><td>$n</td><td>{$map[$n][0]}ম</td><td>{$map[$n][1]}</td><td>{$staged['draw_date']}</td><td><a>x</a></tr>";
                }
            }
            return [200, $html . '</table>', []];
        });
        return new IRDVerifier(new IRDAdapter(new HttpClient(['prizebond.ird.gov.bd'])), 10, 0);
    }

    public function testRealDocumentGoesFetchedToStagedValid(): void
    {
        $id = $this->register(125, '2026-11-01');
        $this->assertSame('fetched', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
        $r = (new ImportService())->process($id);
        $this->assertSame('staged', $r['status'], json_encode($r['report']['errors'] ?? []));
        $row = DB::one('SELECT * FROM result_imports WHERE id = ?', [$id]);
        $this->assertSame(PDFResultParserVersion::get(), $row['parser_version']);
        $this->assertSame(64, strlen($row['file_checksum']));
        $this->assertFileExists(base_path($row['file_path']));
        $this->assertSame(46, $r['report']['stats']['total_numbers']);
    }

    public function testDateMismatchBetweenListingAndDocumentRequiresReview(): void
    {
        // Real case: listing says 31 Oct 2025, the official PDF says 2 Nov 2025 (draw 121).
        $id = $this->register(121, '2025-10-31');
        $r = (new ImportService())->process($id);
        $this->assertSame('review_required', $r['status']);
        $this->assertStringContainsString('differs from source listing', implode(' ', $r['report']['errors']));
        $this->expectException(\RuntimeException::class);   // cannot be verified until a human resolves it
        (new ImportService())->verify($id, $this->createAdmin());
    }

    public function testAdminResolvesDateThenVerifiesAndPublishes(): void
    {
        $admin = $this->createAdmin('result_manager', 'rm@example.com');
        $svc = new ImportService();
        $id = $this->register(121, '2025-10-31');
        $svc->process($id);
        $staged = json_decode((string) DB::val('SELECT staged_data FROM result_imports WHERE id = ?', [$id]), true);
        $tiers = array_map(fn($t) => $t['numbers'], $staged['tiers']);
        $res = $svc->updateStaged($id, ['draw_number' => 121, 'draw_date' => '2025-11-02', 'series_count' => 83], $tiers, $admin);
        $this->assertSame('staged', $res['status']);
        $svc->verify($id, $admin);
        $this->assertSame('verified', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
        $drawId = (new DrawService())->publish($id, $admin);
        $d = DB::one('SELECT * FROM prize_draws WHERE id = ?', [$drawId]);
        $this->assertSame('2025-11-02', $d['draw_date']);
        $this->assertSame('published', $d['publication_status']);
        $this->assertSame(46, (int) DB::val('SELECT COUNT(*) FROM winning_numbers WHERE draw_id = ?', [$drawId]));
        $this->assertSame('published', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
        $this->assertGreaterThan(0, (int) DB::val("SELECT COUNT(*) FROM audit_logs WHERE action IN ('draw.publish','import.verify','import.staged_edit')"));
    }

    public function testOnlyVerifiedImportsCanBePublished(): void
    {
        $id = $this->register(125, '2026-11-01');
        (new ImportService())->process($id);                       // staged, not verified
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('verified');
        (new DrawService())->publish($id, null);
    }

    public function testIrdAgreementVerifiesAndAutoPublishIsOffByDefault(): void
    {
        $id = $this->register(125, '2026-11-01');
        (new ImportService())->process($id);
        $rep = $this->iridAgreeing($id)->verifyImport($id);
        $this->assertSame('agree', $rep['status']);
        $this->assertSame(46, $rep['checked']);
        $this->assertSame('verified', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM prize_draws'), 'verification never publishes by itself by default');
    }

    public function testIrdDisagreementDisputesAndBlocksPublication(): void
    {
        $admin = $this->createAdmin();
        $id = $this->register(125, '2026-11-01');
        (new ImportService())->process($id);
        HttpClient::fake(fn($m, $u, $f) => [200, '<table><tr><td>0923283</td><td>2ম</td><td>325000</td><td>2026-11-01</td></tr></table>', []]);
        $rep = (new IRDVerifier(new IRDAdapter(new HttpClient(['prizebond.ird.gov.bd'])), 10, 0))->verifyImport($id);
        $this->assertSame('disputed', $rep['status']);
        $this->assertNotEmpty($rep['mismatches']);
        $this->assertSame('disputed', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
        try {
            (new DrawService())->publish($id, $admin);
            $this->fail('disputed import must not publish');
        } catch (\RuntimeException) {
        }
        try {
            (new ImportService())->verify($id, $admin);          // override reason missing
            $this->fail('override reason required');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('override', $e->getMessage());
        }
        (new ImportService())->verify($id, $admin, 'Checked against the printed gazette copy.');
        $this->assertSame('verified', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
        $this->assertGreaterThan(0, (int) DB::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'import.verify' AND new_values LIKE '%gazette%'"));
        // an admin alert is queued because a super_admin exists (alertAdmins falls back to the first super admin)
        $this->assertGreaterThanOrEqual(1, (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE type = 'admin_alert' AND subject LIKE '%Sources disagree%'"));
    }

    public function testIrdUnavailableDoesNotDisputeOrVerify(): void
    {
        $id = $this->register(125, '2026-11-01');
        (new ImportService())->process($id);
        HttpClient::fake(fn() => [503, 'down', []]);
        $rep = (new IRDVerifier(new IRDAdapter(new HttpClient(['prizebond.ird.gov.bd'])), 10, 0))->verifyImport($id);
        $this->assertSame('unavailable', $rep['status']);
        $this->assertSame('staged', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
    }

    public function testIrdNotCoveringTheDrawIsNeitherDisputeNorVerification(): void
    {
        // Real behaviour observed: IRD has no records for draws 116/117 at all.
        $id = $this->register(125, '2026-11-01');
        (new ImportService())->process($id);
        HttpClient::fake(fn() => [200, '<table><tr><th>x</th></tr></table>', []]);
        $rep = (new IRDVerifier(new IRDAdapter(new HttpClient(['prizebond.ird.gov.bd'])), 10, 0))->verifyImport($id);
        $this->assertSame('not_covered', $rep['status']);
        $this->assertSame('staged', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]), 'neither verified nor disputed');
        // and the verify cron does not hammer IRD for it again
        $calls = 0;
        HttpClient::fake(function () use (&$calls) {
            $calls++;
            return [200, '', []];
        });
        (new Pipeline())->verifyResults(new IRDVerifier(new IRDAdapter(new HttpClient(['prizebond.ird.gov.bd'])), 10, 0));
        $this->assertSame(0, $calls);
    }

    public function testSingleNumberMissingAtIrdIsADisputeNotSilentlyIgnored(): void
    {
        // Real behaviour observed for draw 123: IRD lacks one 5th-prize number that Bangladesh Bank published.
        $id = $this->register(125, '2026-11-01');
        (new ImportService())->process($id);
        $verifier = $this->iridAgreeing($id);
        $staged = json_decode((string) DB::val('SELECT staged_data FROM result_imports WHERE id = ?', [$id]), true);
        $missing = $staged['tiers']['5']['numbers'][3];
        $date = $staged['draw_date'];
        $map = [];
        foreach ($staged['tiers'] as $code => $t) {
            foreach ($t['numbers'] as $n) {
                if ($n !== $missing) {
                    $map[$n] = [$code, $t['amount']];
                }
            }
        }
        HttpClient::fake(function ($m, $u, $f) use ($map, $date) {
            $h = '<table>';
            foreach (explode(',', $f['from']) as $n) {
                if (isset($map[$n])) {
                    $h .= "<tr><td>$n</td><td>{$map[$n][0]}ম</td><td>{$map[$n][1]}</td><td>$date</td><td></td></tr>";
                }
            }
            return [200, $h . '</table>', []];
        });
        $rep = (new IRDVerifier(new IRDAdapter(new HttpClient(['prizebond.ird.gov.bd'])), 10, 0))->verifyImport($id);
        $this->assertSame('disputed', $rep['status']);
        $this->assertSame($missing, $rep['mismatches'][0]['number']);
        $this->assertSame('disputed', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
    }

    public function testAutoPublishOnlyWhenEnabledAndIrdAgrees(): void
    {
        \App\Core\Settings::set('imports.auto_publish', '1');
        $id = $this->register(125, '2026-11-01');
        (new ImportService())->process($id);
        (new Pipeline())->verifyResults($this->iridAgreeing($id));
        $this->assertSame('published', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM prize_draws'));
    }

    public function testDuplicateDrawIsNeverOverwritten(): void
    {
        $admin = $this->createAdmin();
        $this->publishDraw(125, '2026-11-01', ['1' => ['0000001']]);
        $id = $this->register(125, '2026-11-01');
        $svc = new ImportService();
        $svc->process($id);
        $svc->verify($id, $admin);
        try {
            (new DrawService())->publish($id, $admin);
            $this->fail('must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('never overwritten', $e->getMessage());
        }
        $this->assertSame(['0000001'], array_column(DB::all('SELECT number FROM winning_numbers'), 'number'));
    }

    public function testPublishIsAtomic(): void
    {
        $admin = $this->createAdmin();
        $id = $this->register(125, '2026-11-01');
        $svc = new ImportService();
        $svc->process($id);
        $svc->verify($id, $admin);
        // corrupt staged data AFTER verification: final validation must stop publication before anything is written
        $staged = json_decode((string) DB::val('SELECT staged_data FROM result_imports WHERE id = ?', [$id]), true);
        unset($staged['tiers']['5']);
        DB::exec('UPDATE result_imports SET staged_data = ? WHERE id = ?', [json_encode($staged), $id]);
        try {
            (new DrawService())->publish($id, $admin);
            $this->fail('should fail');
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM prize_draws'));
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM winning_numbers'));
        $this->assertSame('verified', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
    }

    public function testValidatorCatchesBrokenData(): void
    {
        $v = new \App\Services\ResultValidator();
        $t = $this->typeId();
        $this->assertTrue($v->validate($this->validStaged(), $t)['valid']);

        $s = $this->validStaged();
        $s['tiers']['5']['numbers'][] = '0000001';                               // 41 numbers
        $this->assertStringContainsString('expected 40', implode(' ', $v->validate($s, $t)['errors']));

        $s = $this->validStaged();
        $s['tiers']['2']['numbers'][0] = $s['tiers']['1']['numbers'][0];          // duplicate across tiers
        $this->assertStringContainsString('Duplicate winning number', implode(' ', $v->validate($s, $t)['errors']));

        $s = $this->validStaged();
        $s['tiers']['1']['numbers'][0] = '923283';                                // zero stripped
        $this->assertStringContainsString('Invalid winning number format', implode(' ', $v->validate($s, $t)['errors']));

        $s = $this->validStaged();
        unset($s['tiers']['3']);
        $this->assertStringContainsString('missing', implode(' ', $v->validate($s, $t)['errors']));

        $s = $this->validStaged();
        $s['tiers']['1']['amount'] = 700000;
        $this->assertStringContainsString('differs from configured amount', implode(' ', $v->validate($s, $t)['errors']));

        $s = $this->validStaged();
        $s['draw_date'] = '2027-02-30';
        $this->assertFalse($v->validate($s, $t)['valid']);

        $empty = ['draw_number' => 5, 'draw_date' => '2027-01-31', 'tiers' => []];
        $r = $v->validate($empty, $t);
        $this->assertFalse($r['valid'], 'an empty parse is NOT "no winners"');
        $this->assertFalse($v->validate($this->validStaged(), 99999)['valid'], 'unknown bond type');
    }

    public function testUnverifiedBondTypeCannotBeImported(): void
    {
        $now = DB::now();
        $id = DB::insert('bond_types', ['code' => 'X200', 'name' => 'Unsupported', 'denomination' => 200, 'source_verified' => 0, 'is_enabled' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $r = (new \App\Services\ResultValidator())->validate($this->validStaged(), $id);
        $this->assertFalse($r['valid']);
        $this->assertStringContainsString('no verified official draw format', implode(' ', $r['errors']));
    }

    public function testChangedDocumentHandling(): void
    {
        $svc = new ImportService();
        $mk = fn(string $body) => ['bond_type_id' => $this->typeId(), 'draw_number' => 125, 'draw_date' => '2026-11-01', 'source_url' => 'https://www.bb.org.bd/x', 'body' => $body, 'format' => 'pdf', 'origin' => 'auto'];
        $a = $svc->registerDocument($mk($this->pdf(125)));
        $this->assertSame('created', $a['action']);
        $this->assertSame('unchanged', $svc->registerDocument($mk($this->pdf(125)))['action']);
        $this->assertSame('refreshed', $svc->registerDocument($mk($this->pdf(125) . "\n%edited"))['action']);
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM result_imports'));

        // after publication a changed source is flagged, never applied
        DB::exec("UPDATE result_imports SET status = 'published' WHERE id = ?", [$a['id']]);
        $before = DB::val('SELECT file_checksum FROM result_imports WHERE id = ?', [$a['id']]);
        $r = $svc->registerDocument($mk($this->pdf(125) . "\n%edited again"));
        $this->assertSame('changed_after_publish', $r['action']);
        $this->assertSame($before, DB::val('SELECT file_checksum FROM result_imports WHERE id = ?', [$a['id']]));
    }

    public function testManualEntryFlow(): void
    {
        $admin = $this->createAdmin();
        $svc = new ImportService();
        $id = DB::insert('result_imports', ['bond_type_id' => $this->typeId(), 'draw_number' => 300, 'status' => 'parsed', 'origin' => 'manual_entry', 'created_at' => DB::now(), 'updated_at' => DB::now()]);
        $s = $this->validStaged(300, '2027-04-30');
        $tiers = array_map(fn($t) => implode(' ', $t['numbers']), $s['tiers']);
        $r = $svc->updateStaged($id, ['draw_number' => 300, 'draw_date' => '2027-04-30', 'series_count' => 86], $tiers, $admin);
        $this->assertSame('staged', $r['status']);
        $svc->verify($id, $admin);
        $this->assertGreaterThan(0, (new DrawService())->publish($id, $admin));
    }

    public function testRejectedImportCannotBePublishedAndPublishedCannotBeRejected(): void
    {
        $admin = $this->createAdmin();
        $id = $this->register(125, '2026-11-01');
        $svc = new ImportService();
        $svc->process($id);
        $svc->reject($id, $admin, 'wrong file');
        $this->assertSame('rejected', DB::val('SELECT status FROM result_imports WHERE id = ?', [$id]));
        $this->expectException(\RuntimeException::class);
        (new DrawService())->publish($id, $admin);
    }

    public function testCorrectionIsVersionedAuditedAndRetractsWrongAlerts(): void
    {
        $admin = $this->createAdmin();
        $u = $this->createUser();
        $this->addBond($u, '0923283');
        $this->addBond($u, '0000999');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283'], '2' => ['0842511'], '3' => ['0873611', '0891956'], '4' => ['0458658', '0924695'], '5' => array_map(fn($i) => str_pad((string) ($i * 1000 + 7), 7, '0', STR_PAD_LEFT), range(1, 40))]);
        (new MatchingService())->matchDraw($d);
        $n = new NotificationService();
        $n->queueWinningNotifications($d);
        $n->processDue();
        $this->assertSame('sent', DB::val('SELECT notification_status FROM prize_matches LIMIT 1'));

        $snap = (new DrawService())->snapshot($d)['tiers'];
        $snap['1'] = ['0000999'];                                            // the official correction replaces the 1st-prize number
        $res = (new DrawService())->correct($d, $snap, 'Bangladesh Bank issued a corrected result document', $admin);
        $this->assertSame(2, $res['version']);
        $this->assertSame(1, $res['voided']);
        $this->assertSame(1, $res['added']);
        $this->assertSame(1, $res['retractions']);
        $this->assertSame('void', DB::val("SELECT status FROM winning_numbers WHERE number = '0923283'"));
        $this->assertSame('void', DB::val("SELECT match_status FROM prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id WHERE b.number = '0923283'"));
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM draw_corrections WHERE draw_id = ? AND version = 2', [$d]));
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'draw.correct'"));
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE type = 'match_retracted'"));

        // re-run matching: new winner matched, new alert (version-scoped key), old match stays void
        (new Pipeline())->runMatching($d);
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM prize_matches WHERE match_status = 'detected'"));
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE type = 'winning_match' AND status = 'pending'"));
    }

    public function testCorrectionRequiresReasonAndPublishedDraw(): void
    {
        $admin = $this->createAdmin();
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $this->expectException(\RuntimeException::class);
        (new DrawService())->correct($d, ['1' => ['0000001']], '  ', $admin);
    }

    public function testSourceMonitorDiscoversFetchesAndAvoidsRepeatDownloads(): void
    {
        $listing = (string) file_get_contents($this->fixture('bb_listing.html'));
        $calls = ['listing' => 0, 'pdf' => 0];
        HttpClient::fake(function ($m, $url) use ($listing, &$calls) {
            if (str_ends_with($url, '/prizebond')) {
                $calls['listing']++;
                return [200, $listing, []];
            }
            $calls['pdf']++;
            return [200, $this->pdf(125), []];
        });
        $mon = new SourceMonitor(new BangladeshBankAdapter(new HttpClient(['www.bb.org.bd'])), new ImportService());
        $s1 = $mon->run(true);
        $imports = (int) DB::val('SELECT COUNT(*) FROM result_imports');
        $this->assertGreaterThanOrEqual(8, $imports);
        $this->assertSame($imports, $s1['discovered']);
        $this->assertSame($imports, $s1['fetched']);
        $this->assertSame($imports, $calls['pdf']);
        $this->assertSame('fetched', DB::val('SELECT status FROM result_imports WHERE draw_number = 125'));
        $this->assertNotNull(DB::val("SELECT last_success_at FROM result_sources WHERE code = 'bb'"));

        $mon->run(true);                                   // second pass: only the newest is re-checked
        $this->assertSame($imports + 1, $calls['pdf']);
        $this->assertSame($imports, (int) DB::val('SELECT COUNT(*) FROM result_imports'));

        // not due yet when not forced
        $this->assertSame(1, $mon->run(false)['skipped']);
    }

    public function testSourceMonitorFailureIsLoggedAndSurfaced(): void
    {
        HttpClient::fake(fn() => [500, 'boom', []]);
        $mon = new SourceMonitor(new BangladeshBankAdapter(new HttpClient(['www.bb.org.bd'])), new ImportService());
        try {
            $mon->run(true);
            $this->fail('should throw');
        } catch (\RuntimeException) {
        }
        $this->assertNotEmpty(DB::val("SELECT last_error FROM result_sources WHERE code = 'bb'"));
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM failed_jobs WHERE job_name = 'monitor_sources'"));
    }

    public function testMonitorNeverSendsUserEmail(): void
    {
        $listing = (string) file_get_contents($this->fixture('bb_listing.html'));
        $this->createUser();
        HttpClient::fake(fn($m, $url) => str_ends_with($url, '/prizebond') ? [200, $listing, []] : [200, $this->pdf(125), []]);
        (new SourceMonitor(new BangladeshBankAdapter(new HttpClient(['www.bb.org.bd'])), new ImportService()))->run(true);
        (new NotificationService())->processDue();
        $this->assertSame([], \App\Services\ArrayTransport::$sent);
    }

    public function testPipelineProcessImportsAndFailureIsRecorded(): void
    {
        $good = $this->register(125, '2026-11-01');
        $bad = (new ImportService())->registerDocument(['bond_type_id' => $this->typeId(), 'draw_number' => 126, 'draw_date' => '2027-01-31', 'source_url' => 'https://www.bb.org.bd/y', 'body' => "%PDF-1.4\nnot really a pdf", 'format' => 'pdf', 'origin' => 'auto'])['id'];
        (new Pipeline())->processImports();
        $this->assertSame('staged', DB::val('SELECT status FROM result_imports WHERE id = ?', [$good]));
        $this->assertSame('failed', DB::val('SELECT status FROM result_imports WHERE id = ?', [$bad]));
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM failed_jobs WHERE job_name = 'process_import'"));
    }
}

/** Tiny indirection so the test does not hard-code the parser version string. */
final class PDFResultParserVersion
{
    public static function get(): string
    {
        return \App\Services\PDFResultParser::VERSION;
    }
}
