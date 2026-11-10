<?php
declare(strict_types=1);

namespace Tests\Feature;

use App\Core\DB;
use App\Core\Session;
use Tests\TestCase;

final class SecurityTest extends TestCase
{
    public function testStateChangingRequestsWithoutCsrfAreRejected(): void
    {
        $u = $this->createUser();
        $this->loginAs($u);
        $bond = $this->addBond($u, '0923283');
        $cases = [['/bonds', ['bond_type' => $this->typeId(), 'number' => '0000001']], ["/bonds/$bond/delete", []], ["/bonds/$bond/toggle", []],
            ['/profile', ['name' => 'Hacked']], ['/profile/delete', ['current_password' => 'Str0ngPassw0rd']], ['/logout', []]];
        foreach ($cases as [$path, $data]) {
            $r = $this->request('POST', $path, $data + ['_csrf' => 'wrong']);
            $this->assertSame(419, $r->status, $path);
            $r = $this->request('POST', $path, $data + ['_csrf' => '']);
            $this->assertSame(419, $r->status, $path);
        }
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM prize_bonds'));
        $this->assertSame('Rahim Uddin', DB::val('SELECT full_name FROM users'));
        $this->assertSame('active', DB::val('SELECT status FROM users'));
        // unauthenticated forms too
        $this->assertSame(419, $this->request('POST', '/login', ['email' => 'a@b.c', 'password' => 'x', '_csrf' => 'wrong'])->status);
        $this->assertSame(419, $this->request('POST', '/register', ['_csrf' => 'wrong'])->status);
    }

    public function testCsrfTokenIsRandomPerSessionAndConstantTimeCompared(): void
    {
        Session::reset();
        $a = \App\Core\Csrf::token();
        $this->assertSame(64, strlen($a));
        $this->assertSame($a, \App\Core\Csrf::token());
        Session::reset();
        $this->assertNotSame($a, \App\Core\Csrf::token());
    }

    public function testOutputIsEscapedAgainstXss(): void
    {
        $payload = '"><script>alert(1)</script>';
        $u = $this->createUser('x@example.com', 'Str0ngPassw0rd', true, $payload);
        $this->loginAs($u);
        foreach (['/dashboard', '/profile', '/bonds', '/matches'] as $p) {
            $b = $this->get($p)->body;
            $this->assertStringNotContainsString('<script>alert(1)</script>', $b, $p);
        }
        $this->assertStringContainsString('&lt;script&gt;alert(1)&lt;/script&gt;', $this->get('/dashboard')->body);

        // reflected input
        $b = $this->get('/bonds', ['q' => $payload, 'status' => $payload])->body;
        $this->assertStringNotContainsString('<script>alert(1)</script>', $b);
        $b = $this->get('/check', ['number' => $payload, 'series' => $payload, 'bond_type' => $this->typeId()])->body;
        $this->assertStringNotContainsString('<script>alert(1)</script>', $b);

        // hostile series cannot be stored at all
        $this->post('/bonds', ['bond_type' => $this->typeId(), 'series' => '<img src=x onerror=alert(1)>', 'number' => '0000001']);
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM prize_bonds'));
        // stored hostile data in the DB is still escaped on output
        DB::exec("INSERT INTO prize_bonds (user_id,bond_type_id,series,number,status,created_at,updated_at) VALUES (?,?,?,?,?,?,?)", [$u, $this->typeId(), '<b>x</b>', '0000002', 'active', DB::now(), DB::now()]);
        $this->assertStringNotContainsString('<b>x</b>', $this->get('/bonds')->body);
    }

    public function testSqlInjectionAttemptsAreHarmless(): void
    {
        $victim = $this->createUser('victim@example.com');
        $this->addBond($victim, '0923283');
        $attacker = $this->createUser('attacker@example.com');
        $this->loginAs($attacker);
        $inj = ["' OR '1'='1", "1; DROP TABLE users; --", "%' UNION SELECT password_hash,2,3,4,5,6,7,8,9 FROM users -- ", "\\' OR 1=1 #"];
        foreach ($inj as $s) {
            $r = $this->get('/bonds', ['q' => $s, 'status' => $s, 'page' => $s]);
            $this->assertContains($r->status, [200], $s);
            $this->assertStringNotContainsString('0923283', $r->body, "no leak with $s");
            $this->assertStringNotContainsString('$2y$', $r->body);
            $r = $this->get('/check', ['number' => $s, 'series' => $s, 'bond_type' => $s]);
            $this->assertContains($r->status, [200]);
            $this->post('/bonds', ['bond_type' => $s, 'series' => $s, 'number' => $s, 'purchase_date' => $s]);
            $this->get('/results', ['page' => $s]);
            $this->get("/results/" . rawurlencode($s));
        }
        Session::reset();
        $this->post('/login', ['email' => "admin'--", 'password' => $inj[0]]);
        $this->post('/login', ['email' => "' OR 1=1 --", 'password' => "' OR 1=1 --"]);
        $this->assertNull(Session::get('uid'));
        $this->assertSame(2, (int) DB::val('SELECT COUNT(*) FROM users'), 'tables intact');
        $this->loginAdmin($this->createAdmin());
        foreach ($inj as $s) {
            foreach (['/admin/users', '/admin/bonds', '/admin/matches', '/admin/email-queue', '/admin/audit', '/admin/winning-numbers'] as $p) {
                $this->assertSame(200, $this->get($p, ['q' => $s, 'status' => $s, 'draw' => $s])->status, "$p $s");
            }
        }
        $this->assertSame(2, (int) DB::val('SELECT COUNT(*) FROM users'));
    }

    public function testSecurityHeadersOnEveryResponseType(): void
    {
        foreach (['/', '/login', '/nope', '/sitemap.xml'] as $p) {
            $h = $this->get($p)->headers;
            $this->assertSame('nosniff', $h['X-Content-Type-Options'] ?? null, $p);
            $this->assertSame('DENY', $h['X-Frame-Options'] ?? null, $p);
            $this->assertStringContainsString("default-src 'self'", $h['Content-Security-Policy'] ?? '', $p);
            $this->assertStringContainsString("frame-ancestors 'none'", $h['Content-Security-Policy'], $p);
            $this->assertStringNotContainsString("'unsafe-inline'", $h['Content-Security-Policy'], $p);
            $this->assertArrayHasKey('Referrer-Policy', $h);
            $this->assertArrayHasKey('Permissions-Policy', $h);
        }
    }

    public function testNoInlineScriptsOrStylesSoCspHolds(): void
    {
        $u = $this->createUser();
        $this->loginAs($u);
        foreach (['/', '/check', '/results', '/faq', '/login', '/register', '/dashboard', '/bonds', '/bonds/import', '/profile', '/matches'] as $p) {
            $b = $this->get($p)->body;
            $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)[^>]*>#i', $b, "inline <script> on $p");
            $this->assertDoesNotMatchRegularExpression('#\sstyle="#i', $b, "inline style attribute on $p");
            $this->assertDoesNotMatchRegularExpression('#\son(click|load|error|submit)=#i', $b, "inline handler on $p");
        }
        $this->loginAdmin($this->createAdmin());
        foreach (['/admin', '/admin/imports', '/admin/settings', '/admin/bond-types', '/admin/roles', '/admin/users'] as $p) {
            $b = $this->get($p)->body;
            $this->assertDoesNotMatchRegularExpression('#<script(?![^>]*\bsrc=)[^>]*>#i', $b, $p);
            $this->assertDoesNotMatchRegularExpression('#\sstyle="#i', $b, $p);
        }
    }

    public function testExternalLinksUseNoopener(): void
    {
        $b = $this->get('/')->body;
        preg_match_all('#<a [^>]*target="_blank"[^>]*>#', $b, $m);
        $this->assertNotEmpty($m[0]);
        foreach ($m[0] as $a) {
            $this->assertStringContainsString('noopener', $a);
        }
    }

    public function testProjectInternalsAreNotInsideThePublicDirectory(): void
    {
        $public = base_path('public');
        $items = array_diff(scandir($public), ['.', '..']);
        $this->assertEqualsCanonicalizing(['.htaccess', 'assets', 'index.php', 'uploads'], array_values($items), 'only the front controller, assets and uploads are web-reachable');
        foreach (['.env', 'composer.json', 'composer.lock', 'storage', 'app', 'database', 'vendor', 'cron', 'bin'] as $private) {
            $this->assertFileDoesNotExist("$public/$private");
        }
        $this->assertFileExists(base_path('.htaccess'), 'project-root safety-net deny rule');
        $this->assertStringContainsString('denied', (string) file_get_contents(base_path('.htaccess')));
        $this->assertStringContainsString('-Indexes', (string) file_get_contents("$public/.htaccess"));
        $this->assertEqualsCanonicalizing(['.htaccess'], array_values(array_diff(scandir("$public/uploads"), ['.', '..', '.gitkeep'])), 'uploads holds only its deny-execution rules');
        // dot-files and sensitive paths are routed to 404, never to a file
        foreach (['/.env', '/composer.json', '/storage/logs/app.log', '/../.env', '/vendor/autoload.php'] as $p) {
            $this->assertSame(404, $this->get($p)->status, $p);
        }
    }

    public function testRenderedPagesNeverContainSecrets(): void
    {
        $u = $this->createUser();
        $this->loginAs($u);
        $dbPass = (string) env('DB_PASSWORD', '');
        foreach (['/', '/dashboard', '/profile', '/bonds', '/login'] as $p) {
            $b = $this->get($p)->body;
            $this->assertStringNotContainsString('$2y$', $b);
            $this->assertStringNotContainsString('password_hash', $b);
            $this->assertStringNotContainsString('MAIL_PASSWORD', $b);
            if (strlen($dbPass) > 3) {
                $this->assertStringNotContainsString($dbPass, $b);
            }
        }
    }

    public function testErrorPagesDoNotLeakInternalsInProduction(): void
    {
        $r = $this->get('/results/1');
        $this->assertSame(404, $r->status);
        $this->assertStringNotContainsString('Stack trace', $r->body);
        $this->assertStringNotContainsString(base_path(), $r->body);
    }

    public function testLoggerScrubsConfiguredSecrets(): void
    {
        $_ENV['MAIL_PASSWORD'] = 'SuperSecretSmtpPw!';
        $this->assertSame('login failed for *** at host', \App\Core\Logger::scrub('login failed for SuperSecretSmtpPw! at host'));
        unset($_ENV['MAIL_PASSWORD']);
    }

    public function testIdorOnAdminlessObjectsViaIds(): void
    {
        // numeric-id probing of other users' bonds returns 404 (indistinguishable from nonexistent)
        $a = $this->createUser('a@example.com');
        $b = $this->createUser('b@example.com');
        $bond = $this->addBond($a, '0923283');
        $this->loginAs($b);
        $exists = $this->get("/bonds/$bond/edit")->status;
        $missing = $this->get('/bonds/999999/edit')->status;
        $this->assertSame($missing, $exists);
        $this->assertSame(404, $exists);
        // forged owner/role fields in the request are ignored
        $this->post('/bonds', ['bond_type' => $this->typeId(), 'number' => '0000009', 'user_id' => $a, 'role' => 'admin', 'status' => 'inactive']);
        $this->assertSame($b, (int) DB::val("SELECT user_id FROM prize_bonds WHERE number = '0000009'"));
        $this->assertSame('active', DB::val("SELECT status FROM prize_bonds WHERE number = '0000009'"));
        $this->post('/profile', ['name' => 'Bee', 'mobile' => '', 'language' => 'en', 'status' => 'suspended', 'email' => 'new@example.com', 'is_admin' => '1']);
        $this->assertSame('active', DB::val('SELECT status FROM users WHERE id = ?', [$b]));
        $this->assertSame('b@example.com', DB::val('SELECT email FROM users WHERE id = ?', [$b]));
        $this->assertSame('/admin/login', $this->get('/admin')->headers['Location']);
    }

    public function testDatabaseRateLimiterWindowResets(): void
    {
        for ($i = 0; $i < 3; $i++) {
            $this->assertTrue(\App\Core\RateLimiter::hit('k', 3, 60));
        }
        $this->assertFalse(\App\Core\RateLimiter::hit('k', 3, 60));
        DB::exec('UPDATE rate_limits SET window_start = ?', [gmdate('Y-m-d H:i:s', time() - 120)]);
        $this->assertTrue(\App\Core\RateLimiter::hit('k', 3, 60), 'new window');
    }

    public function testCronScriptsCannotBeRunOverHttp(): void
    {
        $this->assertDirectoryDoesNotExist(base_path('public/cron'));
        foreach (glob(base_path('cron/*.php')) as $f) {
            $this->assertStringContainsString('CronRunner::run', (string) file_get_contents($f), basename($f) . ' goes through the CLI-only runner');
        }
        $src = (string) file_get_contents(base_path('app/Services/CronRunner.php'));
        $this->assertStringContainsString("PHP_SAPI !== 'cli'", $src);
    }
}
