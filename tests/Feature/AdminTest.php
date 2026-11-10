<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Core\Session;
use Tests\TestCase;

final class AdminTest extends TestCase
{
    private const GET_PAGES = ['/admin', '/admin/users', '/admin/bonds', '/admin/bond-types', '/admin/draws', '/admin/imports', '/admin/winning-numbers', '/admin/matches',
        '/admin/email-queue', '/admin/email-logs', '/admin/sources', '/admin/cron', '/admin/audit', '/admin/roles', '/admin/settings'];

    public function testAdminAreaRequiresAdminLogin(): void
    {
        foreach (self::GET_PAGES as $p) {
            $r = $this->get($p);
            $this->assertSame(302, $r->status, $p);
            $this->assertSame('/admin/login', $r->headers['Location'], $p);
        }
        // a normal (even verified) user session is NOT an admin session
        $this->loginAs($this->createUser());
        $this->assertSame('/admin/login', $this->get('/admin')->headers['Location']);
    }

    public function testAdminLoginAndRateLimit(): void
    {
        $this->createAdmin();
        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'wrong']);
        $this->assertNull(Session::get('aid'));
        $r = $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'Adm1nPassw0rdXyz']);
        $this->assertSame('/admin', $r->headers['Location']);
        $this->assertNotNull(Session::get('aid'));
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'admin.login'"));
        Session::reset();
        for ($i = 0; $i < 6; $i++) {
            $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'bad' . $i]);
        }
        $this->post('/admin/login', ['email' => 'admin@example.com', 'password' => 'Adm1nPassw0rdXyz']);
        $this->assertNull(Session::get('aid'), 'brute-force lockout');
    }

    public function testSuperAdminSeesEverything(): void
    {
        $this->loginAdmin($this->createAdmin());
        foreach (self::GET_PAGES as $p) {
            $this->assertSame(200, $this->get($p)->status, $p);
        }
        $this->assertStringContainsString('Roles &amp; Permissions', $this->get('/admin/roles')->body);
    }

    public function testResultManagerPermissions(): void
    {
        $this->loginAdmin($this->createAdmin('result_manager', 'rm@example.com'));
        foreach (['/admin', '/admin/imports', '/admin/draws', '/admin/winning-numbers', '/admin/matches', '/admin/cron'] as $p) {
            $this->assertSame(200, $this->get($p)->status, $p);
        }
        foreach (['/admin/users', '/admin/bonds', '/admin/roles', '/admin/settings', '/admin/audit', '/admin/bond-types', '/admin/sources'] as $p) {
            $this->assertSame(403, $this->get($p)->status, "$p must be forbidden");
        }
    }

    public function testSupportAdminCannotTouchOfficialResults(): void
    {
        $this->loginAdmin($this->createAdmin('support_admin', 'sup@example.com'));
        foreach (['/admin', '/admin/users', '/admin/bonds', '/admin/matches', '/admin/email-queue'] as $p) {
            $this->assertSame(200, $this->get($p)->status, $p);
        }
        foreach (['/admin/imports', '/admin/roles', '/admin/settings', '/admin/audit', '/admin/bond-types'] as $p) {
            $this->assertSame(403, $this->get($p)->status, $p);
        }
        // direct POSTs (not just hidden menu items) are refused server-side
        $imp = DB::insert('result_imports', ['bond_type_id' => $this->typeId(), 'draw_number' => 1, 'status' => 'verified', 'staged_data' => json_encode($this->validStaged(1)), 'validation_report' => '{"valid":true}', 'created_at' => DB::now(), 'updated_at' => DB::now()]);
        foreach (['publish', 'verify', 'reject', 'edit', 'reverify', 'reprocess'] as $act) {
            $this->assertSame(403, $this->post("/admin/imports/$imp/$act", ['reason' => 'x'])->status, $act);
        }
        $this->assertSame(403, $this->post('/admin/imports/fetch')->status);
        $this->assertSame(403, $this->post('/admin/imports/manual', [])->status);
        $this->assertSame(403, $this->post('/admin/cron/run', ['job' => 'matching'])->status);
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM prize_draws'));
        $this->assertSame('verified', DB::val('SELECT status FROM result_imports'));
    }

    public function testResultManagerCannotManageUsersRolesOrSettings(): void
    {
        $this->loginAdmin($this->createAdmin('result_manager', 'rm@example.com'));
        $u = $this->createUser();
        $this->assertSame(403, $this->post("/admin/users/$u/status", ['status' => 'suspended'])->status);
        $this->assertSame(403, $this->post('/admin/roles/admins', ['name' => 'x', 'email' => 'x@example.com', 'password' => 'Adm1nPassw0rdXyz', 'role' => 'super_admin'])->status);
        $this->assertSame(403, $this->post('/admin/settings', ['s' => ['mail.batch_size' => '9999']])->status);
        $this->assertSame('active', DB::val('SELECT status FROM users'));
    }

    public function testAdminPostsRequireCsrfToken(): void
    {
        $this->loginAdmin($this->createAdmin());
        $r = $this->request('POST', '/admin/settings', ['_csrf' => 'forged', 's' => ['mail.batch_size' => '1']]);
        $this->assertSame(419, $r->status);
        $this->assertSame('50', DB::val("SELECT setting_value FROM system_settings WHERE setting_key = 'mail.batch_size'"));
    }

    public function testUserSuspensionViaAdminIsAuditedAndEffective(): void
    {
        $admin = $this->createAdmin();
        $u = $this->createUser('victim@example.com');
        $this->loginAdmin($admin);
        $this->post("/admin/users/$u/status", ['status' => 'suspended']);
        $this->assertSame('suspended', DB::val('SELECT status FROM users'));
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'user.status' AND entity_id = ?", [(string) $u]));
        $this->assertSame(404, $this->post("/admin/users/$u/status", ['status' => 'hacked'])->status);
    }

    public function testSettingsChangesAreAudited(): void
    {
        $this->loginAdmin($this->createAdmin());
        $this->post('/admin/settings', ['s' => ['mail.batch_size' => '10', 'evil.new_key' => 'x']]);
        $this->assertSame('10', DB::val("SELECT setting_value FROM system_settings WHERE setting_key = 'mail.batch_size'"));
        $this->assertNull(DB::val("SELECT setting_value FROM system_settings WHERE setting_key = 'evil.new_key'"), 'unknown keys are ignored');
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'settings.update'"));
    }

    public function testAuditLogsNeverContainSecrets(): void
    {
        $admin = $this->createAdmin();
        \App\Core\Audit::log($admin, 'test', 'x', 1, ['password' => 'p', 'password_hash' => 'h', 'nested' => ['token' => 't', 'smtp_password' => 's']], ['secret' => 'zzz', 'app_key' => 'k', 'ok' => 'visible']);
        $row = DB::one("SELECT previous_values, new_values FROM audit_logs WHERE action = 'test'");
        foreach (['"p"', '"h"', '"t"', '"s"', 'zzz', '"k"'] as $leak) {
            $this->assertStringNotContainsString($leak, $row['previous_values'] . $row['new_values']);
        }
        $this->assertStringContainsString('visible', $row['new_values']);
    }

    // ---- HTTP-driven import → publish workflow -------------------------------------------

    private function uploadPdf(string $name, string $content, string $mime = 'application/pdf'): \App\Core\Response
    {
        $tmp = tempnam(sys_get_temp_dir(), 'up');
        file_put_contents($tmp, $content);
        $r = $this->post('/admin/imports/upload', ['bond_type' => $this->typeId()], ['pdf' => ['name' => $name, 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]]);
        @unlink($tmp);
        return $r;
    }

    public function testManualPdfUploadVerifyPublishViaHttp(): void
    {
        $this->loginAdmin($this->createAdmin('result_manager', 'rm@example.com'));
        $r = $this->uploadPdf('official.pdf', (string) file_get_contents($this->fixture('bb_125thdraw.pdf')));
        $id = (int) DB::val('SELECT id FROM result_imports');
        $this->assertSame("/admin/imports/$id", $r->headers['Location']);
        $this->assertSame('staged', DB::val('SELECT status FROM result_imports'));
        $this->assertSame('manual_upload', DB::val('SELECT origin FROM result_imports'));
        $stored = base_path(DB::val('SELECT file_path FROM result_imports'));
        $this->assertFileExists($stored);
        $this->assertStringNotContainsString('official', basename($stored), 'stored under a random name');
        $page = $this->get("/admin/imports/$id")->body;
        $this->assertStringContainsString('0923283', $page);
        $this->assertStringContainsString('Validation', $page);

        $this->post("/admin/imports/$id/publish");
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM prize_draws'), 'cannot publish before verification');
        $this->post("/admin/imports/$id/verify");
        $this->assertSame('verified', DB::val('SELECT status FROM result_imports'));
        $r = $this->post("/admin/imports/$id/publish");
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM prize_draws'));
        $this->assertStringStartsWith('/admin/draws/', $r->headers['Location']);
        $this->assertStringContainsString('0923283', $this->get('/results/' . DB::val('SELECT id FROM prize_draws'))->body, 'now public');
    }

    public function testUploadRejectsNonPdfOversizeAndScripts(): void
    {
        $this->loginAdmin($this->createAdmin());
        $this->uploadPdf('evil.pdf', '<?php system($_GET["c"]); ?>');
        $this->uploadPdf('page.pdf', '<html><body>not a pdf</body></html>');
        $this->uploadPdf('big.pdf', "%PDF-1.4\n" . str_repeat('A', 9 * 1024 * 1024));
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM result_imports'));
        $this->assertSame([], glob(base_path('storage/imports/manual_upload') . '/*.php') ?: []);
    }

    public function testEditingStagedDataRevalidatesAndRejectsBadInput(): void
    {
        $this->loginAdmin($this->createAdmin());
        $this->uploadPdf('official.pdf', (string) file_get_contents($this->fixture('bb_125thdraw.pdf')));
        $id = (int) DB::val('SELECT id FROM result_imports');
        $this->post("/admin/imports/$id/edit", ['draw_number' => '125', 'draw_date' => '2026-11-01', 'series_count' => '86',
            'tier_1' => '923283', 'tier_2' => '0842511', 'tier_3' => '0873611 0891956', 'tier_4' => '0458658 0924695', 'tier_5' => '0021355']);
        $this->assertSame('review_required', DB::val('SELECT status FROM result_imports'));
        $report = json_decode((string) DB::val('SELECT validation_report FROM result_imports'), true);
        $this->assertFalse($report['valid']);
        $this->post("/admin/imports/$id/verify");
        $this->assertSame('review_required', DB::val('SELECT status FROM result_imports'), 'invalid data can never be verified');
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'import.staged_edit'"));
    }

    public function testManualEntryViaHttp(): void
    {
        $this->loginAdmin($this->createAdmin());
        $s = $this->validStaged(300, '2027-04-30');
        $post = ['bond_type' => $this->typeId(), 'draw_number' => '300', 'draw_date' => '2027-04-30', 'series_count' => '86'];
        foreach ($s['tiers'] as $code => $t) {
            $post["tier_$code"] = implode(' ', $t['numbers']);
        }
        $this->post('/admin/imports/manual', $post);
        $this->assertSame('staged', DB::val('SELECT status FROM result_imports'));
        $this->assertSame('manual_entry', DB::val('SELECT origin FROM result_imports'));
        $this->post('/admin/imports/manual', $post);
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM result_imports'), 'duplicate draw entry refused');
    }

    public function testBondTypeCannotBeEnabledWithoutVerifiedOfficialFormat(): void
    {
        $this->loginAdmin($this->createAdmin());
        $now = DB::now();
        $id = DB::insert('bond_types', ['code' => 'X200', 'name' => 'Invented', 'denomination' => 200, 'source_verified' => 0, 'is_enabled' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $this->post("/admin/bond-types/$id", ['is_enabled' => '1']);
        $this->assertSame(0, (int) DB::val('SELECT is_enabled FROM bond_types WHERE id = ?', [$id]));
    }

    public function testPrizeTierChangeIsAuditedAndDoesNotRewriteHistory(): void
    {
        $this->loginAdmin($this->createAdmin());
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $tier = (int) DB::val("SELECT id FROM prize_tiers WHERE code = '1'");
        $this->post("/admin/prize-tiers/$tier", ['amount' => '700000', 'is_active' => '1']);
        $this->assertEquals(600000, DB::val('SELECT amount FROM prize_tiers WHERE id = ?', [$tier]), 'reason required when published draws use the tier');
        $this->post("/admin/prize-tiers/$tier", ['amount' => '700000', 'is_active' => '1', 'reason' => 'Gazette 2027']);
        $this->assertEquals(700000, DB::val('SELECT amount FROM prize_tiers WHERE id = ?', [$tier]));
        $this->assertEquals(600000, DB::val('SELECT amount FROM winning_numbers WHERE draw_id = ?', [$d]), 'historic winning amount snapshot unchanged');
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM audit_logs WHERE action = 'prize_tier.update'"));
    }

    public function testRerunMatchingViaAdminIsSafe(): void
    {
        $this->loginAdmin($this->createAdmin());
        $u = $this->createUser();
        $this->addBond($u, '0923283');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $this->post("/admin/draws/$d/rematch");
        $this->post("/admin/draws/$d/rematch");
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM prize_matches'));
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM notification_queue WHERE type = 'winning_match'"));
    }

    public function testEmailQueueRetryAndCancel(): void
    {
        $this->loginAdmin($this->createAdmin());
        $id = (new \App\Services\NotificationService())->enqueue('admin_alert', 'x@example.com', 's', 'admin_alert', ['lang' => 'en', 'message' => 'm'], 'k1');
        DB::exec("UPDATE notification_queue SET status = 'failed'");
        $this->post("/admin/email-queue/$id", ['do' => 'retry']);
        $this->assertSame('pending', DB::val('SELECT status FROM notification_queue'));
        $this->post("/admin/email-queue/$id", ['do' => 'cancel']);
        $this->assertSame('cancelled', DB::val('SELECT status FROM notification_queue'));
    }

    public function testAdminListsEscapeHostileData(): void
    {
        $this->loginAdmin($this->createAdmin());
        $this->createUser('x@example.com', 'Str0ngPassw0rd', true, '<script>alert("xss")</script>');
        $html = $this->get('/admin/users')->body;
        $this->assertStringNotContainsString('<script>alert("xss")</script>', $html);
        $this->assertStringContainsString('&lt;script&gt;', $html);
    }

    public function testAdminCanBeCreatedWithoutDefaultsAndPasswordRulesApply(): void
    {
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM admin_users'));
        $svc = new \App\Services\AdminService();
        foreach (['short1A', 'alllowercase1234', 'ALLUPPERCASE1234', 'NoDigitsHereAtAll'] as $pw) {
            try {
                $svc->create('A', 'a@example.com', $pw);
                $this->fail("accepted weak admin password $pw");
            } catch (\InvalidArgumentException) {
            }
        }
        $this->assertGreaterThan(0, $svc->create('A', 'a@example.com', 'Adm1nPassw0rdXyz'));
    }
}
