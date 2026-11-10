<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Core\Session;
use Tests\TestCase;

final class PublicAndUserAreaTest extends TestCase
{
    public function testAllPublicPagesRenderOnEmptyDatabase(): void
    {
        foreach (['/', '/check', '/results', '/how-it-works', '/faq', '/about', '/contact', '/privacy', '/terms', '/login', '/register', '/forgot-password', '/sitemap.xml', '/robots.txt'] as $p) {
            $r = $this->get($p);
            $this->assertSame(200, $r->status, $p);
        }
        $home = $this->get('/')->body;
        $this->assertStringContainsString('No draw results have been published here yet', $home, 'useful empty state, no fake data');
        $this->assertSame(404, $this->get('/results/999')->status);
        $this->assertSame(404, $this->get('/nope')->status);
        $this->assertSame(405, $this->post('/results', [])->status);
    }

    public function testHomepageStatisticsComeFromTheDatabase(): void
    {
        $a = $this->createUser('a@example.com');
        $this->createUser('b@example.com');
        $this->createUser('c@example.com', 'Str0ngPassw0rd', false);   // unverified -> not counted
        $this->addBond($a, '0923283');
        $this->addBond($a, '0000001');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        (new \App\Services\MatchingService())->matchDraw($d);
        $html = $this->get('/')->body;
        $stats = [];
        preg_match_all('#class="num">([\d,]+)</div>#', $html, $m);
        $stats = array_map(fn($s) => (int) str_replace(',', '', $s), $m[1]);
        $this->assertSame([1, 2, 2, 1], $stats, 'draws, bonds, users, matches');
        $this->assertStringContainsString('0923283', $html);
        $this->assertStringContainsString('Draw 125 results', $html);
    }

    public function testResultsAndDrawPagesShowPublishedDataOnly(): void
    {
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283'], '5' => ['0021355']]);
        $hidden = $this->publishDraw(126, '2027-01-31', ['1' => ['0777777']]);
        DB::exec("UPDATE prize_draws SET publication_status = 'unpublished' WHERE id = ?", [$hidden]);
        $list = $this->get('/results')->body;
        $this->assertStringContainsString('#125', $list);
        $this->assertStringNotContainsString('#126', $list);
        $page = $this->get("/results/$d")->body;
        $this->assertStringContainsString('0021355', $page);
        $this->assertStringContainsString('Tk. 6,00,000', $page);
        $this->assertSame(404, $this->get("/results/$hidden")->status);
    }

    public function testPublicCheckFindsWinnersAndNeverLeaksUsers(): void
    {
        $u = $this->createUser('secret.person@example.com', 'Str0ngPassw0rd', true, 'Secret Person');
        $this->addBond($u, '0923283', 'KK');
        $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $r = $this->get('/check', ['bond_type' => $this->typeId(), 'number' => '০৯২৩২৮৩']);   // Bangla digits accepted
        $this->assertSame(200, $r->status);
        $this->assertStringContainsString('appears in 1 published draw', $r->body);
        $this->assertStringNotContainsString('Secret Person', $r->body);
        $this->assertStringNotContainsString('secret.person', $r->body);
        $miss = $this->get('/check', ['bond_type' => $this->typeId(), 'number' => '0923284']);
        $this->assertStringContainsString('No match', $miss->body);
        $bad = $this->get('/check', ['bond_type' => $this->typeId(), 'number' => '923283']);
        $this->assertStringContainsString('exactly 7 digits', $bad->body);
    }

    public function testPublicCheckIsRateLimited(): void
    {
        $last = null;
        for ($i = 0; $i < 42; $i++) {
            $last = $this->get('/check', ['bond_type' => $this->typeId(), 'number' => '0000001']);
        }
        $this->assertSame(429, $last->status);
    }

    public function testLanguageSwitching(): void
    {
        $this->assertStringContainsString('Check a Bond', $this->get('/')->body);
        $this->get('/lang/bn');
        $bn = $this->get('/')->body;
        $this->assertStringContainsString('বন্ড যাচাই', $bn);
        $this->assertStringContainsString('lang="bn"', $bn);
        $this->assertStringContainsString('Noto+Sans+Bengali', $bn);
        $this->get('/lang/en');
        $this->assertStringContainsString('Check a Bond', $this->get('/')->body);
        $this->get('/lang/xx');
        $this->assertSame('en', \App\Core\Lang::locale());
    }

    public function testSeoBasics(): void
    {
        $home = $this->get('/')->body;
        $this->assertMatchesRegularExpression('#<title>[^<]+</title>#', $home);
        $this->assertStringContainsString('<meta name="description"', $home);
        $this->assertStringContainsString('rel="canonical" href="http://localhost/"', $home);
        $this->assertStringContainsString('property="og:title"', $home);
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $sm = $this->get('/sitemap.xml')->body;
        $this->assertStringContainsString("/results/$d", $sm);
        $this->assertStringNotContainsString('/dashboard', $sm);
        $robots = $this->get('/robots.txt')->body;
        foreach (['/dashboard', '/bonds', '/matches', '/profile', '/admin'] as $p) {
            $this->assertStringContainsString("Disallow: $p", $robots);
        }
        $this->loginAs($this->createUser());
        $this->assertStringContainsString('noindex', $this->get('/dashboard')->body, 'private pages are not indexable');
        $this->assertStringContainsString('noindex', $this->get('/bonds')->body);
    }

    // ---- user area ------------------------------------------------------------------

    public function testGuestsAreRedirectedFromPrivateAreas(): void
    {
        foreach (['/dashboard', '/bonds', '/bonds/import', '/matches', '/profile', '/bonds/export'] as $p) {
            $r = $this->get($p);
            $this->assertSame(302, $r->status, $p);
            $this->assertSame('/login', $r->headers['Location'], $p);
        }
    }

    public function testBondCrudPreservesLeadingZeros(): void
    {
        $u = $this->createUser();
        $this->loginAs($u);
        $this->post('/bonds', ['bond_type' => $this->typeId(), 'series' => 'kk', 'number' => '০০৪৬৯০০', 'purchase_date' => '2025-01-15']);
        $b = DB::one('SELECT * FROM prize_bonds');
        $this->assertSame('0046900', $b['number']);
        $this->assertSame('KK', $b['series']);
        $this->assertStringContainsString('0046900', $this->get('/bonds')->body);

        $this->post('/bonds', ['bond_type' => $this->typeId(), 'series' => 'KK', 'number' => '46900']);
        $this->assertSame('bond.err_number_length', Session::get('_errors')['number']);
        $this->post('/bonds', ['bond_type' => $this->typeId(), 'series' => 'KK', 'number' => '0046900']);
        $this->assertSame('bond.err_duplicate', Session::get('_errors')['number']);
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM prize_bonds'));

        $this->post("/bonds/{$b['id']}/edit", ['series' => 'LB', 'number' => '0046901', 'purchase_date' => '']);
        $this->assertSame(['LB', '0046901', null], array_values(DB::one('SELECT series, number, purchase_date FROM prize_bonds')));
        $this->post("/bonds/{$b['id']}/toggle");
        $this->assertSame('inactive', DB::val('SELECT status FROM prize_bonds'));
        $this->post("/bonds/{$b['id']}/delete");
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM prize_bonds'));
    }

    public function testUsersCannotTouchEachOthersBondsIdor(): void
    {
        $a = $this->createUser('a@example.com');
        $b = $this->createUser('b@example.com');
        $bond = $this->addBond($a, '0923283', 'KK');
        $this->loginAs($b);
        $this->assertSame(404, $this->get("/bonds/$bond/edit")->status);
        $this->post("/bonds/$bond/edit", ['series' => 'ZZ', 'number' => '1111111']);
        $this->post("/bonds/$bond/toggle");
        $this->post("/bonds/$bond/delete");
        $this->assertSame(['KK', '0923283', 'active'], array_values(DB::one('SELECT series, number, status FROM prize_bonds WHERE id = ?', [$bond])));
        $this->assertStringNotContainsString('0923283', $this->get('/bonds')->body);
        $this->assertStringNotContainsString('0923283', $this->get('/bonds/export')->body);
    }

    public function testMatchesPageShowsOnlyOwnMatches(): void
    {
        $a = $this->createUser('a@example.com');
        $b = $this->createUser('b@example.com');
        $this->addBond($a, '0923283');
        $this->addBond($b, '0842511');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283'], '2' => ['0842511']]);
        (new \App\Services\MatchingService())->matchDraw($d);
        $this->loginAs($a);
        $html = $this->get('/matches')->body;
        $this->assertStringContainsString('0923283', $html);
        $this->assertStringNotContainsString('0842511', $html);
        $this->assertStringContainsString('verify with Bangladesh Bank', $html);
        $this->assertStringContainsString('Tk. 6,00,000', $html);
    }

    public function testCsvImportUploadValidationAndReport(): void
    {
        $u = $this->createUser();
        $this->loginAs($u);
        $tmp = tempnam(sys_get_temp_dir(), 'csv');
        file_put_contents($tmp, "series,number,purchase_date\nKK,0046900,2025-01-15\nKK,123,\n");
        $r = $this->post('/bonds/import', ['bond_type' => $this->typeId()], ['csv' => ['name' => 'bonds.csv', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]]);
        $this->assertSame('/bonds/import', $r->headers['Location']);
        $page = $this->get('/bonds/import')->body;
        $this->assertStringContainsString('Imported 1, duplicates 0, errors 1', $page);
        $this->assertStringContainsString('0046900', $page);
        $this->assertSame('0046900', DB::val('SELECT number FROM prize_bonds'));

        // wrong type / extension rejected
        file_put_contents($tmp, '<?php echo 1;');
        $this->post('/bonds/import', ['bond_type' => $this->typeId()], ['csv' => ['name' => 'evil.php', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]]);
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM prize_bonds'));
        file_put_contents($tmp, str_repeat('a', 1024 * 1024 + 10));
        $this->post('/bonds/import', ['bond_type' => $this->typeId()], ['csv' => ['name' => 'big.csv', 'tmp_name' => $tmp, 'error' => UPLOAD_ERR_OK, 'size' => filesize($tmp)]]);
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM prize_bonds'));
        @unlink($tmp);
    }

    public function testTemplateAndExportDownloads(): void
    {
        $u = $this->createUser();
        $this->addBond($u, '0000042', '=cmd|calc');   // hostile series, even if it somehow got in
        $this->loginAs($u);
        $t = $this->get('/bonds/template');
        $this->assertStringContainsString('attachment', $t->headers['Content-Disposition']);
        $this->assertStringContainsString('series,number,purchase_date', $t->body);
        $e = $this->get('/bonds/export');
        $this->assertStringContainsString('="0000042"', $e->body, 'leading zeros survive spreadsheet import');
        $this->assertStringNotContainsString(',"=cmd', $e->body, 'formula injection neutralised');
    }

    public function testProfileUpdateAndLanguagePreference(): void
    {
        $u = $this->createUser();
        $this->loginAs($u);
        $this->post('/profile', ['name' => 'New Name', 'mobile' => '+8801712345678', 'language' => 'bn']);
        $this->assertSame('New Name', DB::val('SELECT full_name FROM users'));
        $this->assertSame('bn', DB::val('SELECT language FROM user_settings'));
        $this->assertSame(0, (int) DB::val('SELECT email_alerts FROM user_settings'), 'unchecked switch disables alerts');
        $this->post('/profile', ['name' => 'X', 'mobile' => 'abc', 'language' => 'en']);
        $this->assertSame('New Name', DB::val('SELECT full_name FROM users'), 'invalid input rejected');
    }

    public function testDashboardShowsRealCounts(): void
    {
        $u = $this->createUser();
        $this->addBond($u, '0000001');
        $this->addBond($u, '0000002');
        $this->loginAs($u);
        $html = $this->get('/dashboard')->body;
        $this->assertStringContainsString('Welcome, Rahim Uddin', $html);
        preg_match_all('#class="num">(\d+)</div>#', $html, $m);
        $this->assertSame(['2', '0', '0'], $m[1]);
    }
}
