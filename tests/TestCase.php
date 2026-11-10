<?php
declare(strict_types=1);

namespace Tests;

use App\Core\AdminAuth;
use App\Core\App;
use App\Core\Auth;
use App\Core\Csrf;
use App\Core\DB;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Core\Settings;
use App\Services\ArrayTransport;
use App\Services\HttpClient;
use App\Services\Mailer;
use App\Services\Migrator;
use App\Services\PublicCache;
use PHPUnit\Framework\TestCase as Base;

abstract class TestCase extends Base
{
    private static bool $migrated = false;

    /** Tables that hold runtime data. Seeded configuration tables are left intact. */
    private const DYNAMIC = [
        'rate_limits', 'failed_jobs', 'job_locks', 'cron_job_logs', 'audit_logs', 'draw_corrections', 'import_logs', 'result_imports',
        'admin_users', 'notification_logs', 'notification_queue', 'prize_matches', 'winning_numbers', 'prize_draws', 'prize_bonds',
        'password_reset_tokens', 'email_verification_tokens', 'user_settings', 'users',
    ];

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        if (!self::$migrated) {
            $m = new Migrator();
            $m->wipe();               // clean slate: also proves migrations run from zero
            $m->migrate();
            self::$migrated = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::DYNAMIC as $t) {
            $pdo->exec("TRUNCATE TABLE `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        $this->restoreSeededConfiguration();
        Settings::flush();
        Session::reset();
        Auth::reset();
        AdminAuth::reset();
        Lang::$override = null;
        ArrayTransport::reset();
        Mailer::use(new ArrayTransport());
        HttpClient::fake(null);
        PublicCache::flush();
    }

    /** Seeded configuration snapshot taken once after migration; restored before every test. */
    private static ?array $seed = null;

    private function restoreSeededConfiguration(): void
    {
        $pdo = DB::pdo();
        if (self::$seed === null) {
            self::$seed = [];
            foreach (['bond_types', 'prize_tiers', 'system_settings', 'result_sources'] as $t) {
                self::$seed[$t] = DB::all("SELECT * FROM `$t`");
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        $codes = array_column(self::$seed['bond_types'], 'code');
        DB::exec('DELETE FROM prize_tiers WHERE id NOT IN (' . DB::placeholders(count(self::$seed['prize_tiers'])) . ')', array_column(self::$seed['prize_tiers'], 'id'));
        DB::exec('DELETE FROM bond_types WHERE code NOT IN (' . DB::placeholders(count($codes)) . ')', $codes);
        DB::exec('DELETE FROM system_settings WHERE setting_key NOT IN (' . DB::placeholders(count(self::$seed['system_settings'])) . ')', array_column(self::$seed['system_settings'], 'setting_key'));
        foreach (self::$seed as $table => $rows) {
            foreach ($rows as $row) {
                $cols = array_keys($row);
                $sql = "REPLACE INTO `$table` (`" . implode('`,`', $cols) . '`) VALUES (' . DB::placeholders(count($cols)) . ')';
                DB::exec($sql, array_values($row));
            }
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }

    protected function tearDown(): void
    {
        HttpClient::fake(null);
        parent::tearDown();
    }

    // ---- helpers -----------------------------------------------------------------

    protected function request(string $method, string $path, array $post = [], array $query = [], array $files = [], string $ip = '203.0.113.9'): Response
    {
        Auth::reset();
        AdminAuth::reset();
        if ($method === 'POST' && !array_key_exists('_csrf', $post)) {
            $post['_csrf'] = Csrf::token();
        }
        $req = new Request($method, $path, $query, $post, $files, ['REMOTE_ADDR' => $ip, 'HTTP_HOST' => 'localhost']);
        return App::handle($req);
    }

    protected function get(string $path, array $query = []): Response
    {
        return $this->request('GET', $path, [], $query);
    }

    protected function post(string $path, array $post = [], array $files = [], string $ip = '203.0.113.9'): Response
    {
        return $this->request('POST', $path, $post, [], $files, $ip);
    }

    protected function createUser(string $email = 'rahim@example.com', string $password = 'Str0ngPassw0rd', bool $verified = true, string $name = 'Rahim Uddin'): int
    {
        $now = DB::now();
        $id = DB::insert('users', ['full_name' => $name, 'email' => $email, 'password_hash' => password_hash($password, PASSWORD_DEFAULT),
            'status' => 'active', 'email_verified_at' => $verified ? $now : null, 'created_at' => $now, 'updated_at' => $now]);
        DB::insert('user_settings', ['user_id' => $id, 'language' => 'en', 'email_alerts' => 1, 'updated_at' => $now]);
        return $id;
    }

    protected function loginAs(int $userId): void
    {
        Session::reset();
        Session::put('uid', $userId);
        Auth::reset();
    }

    protected function createAdmin(string $role = 'super_admin', string $email = 'admin@example.com'): int
    {
        return (new \App\Services\AdminService())->create('Test Admin', $email, 'Adm1nPassw0rdXyz', $role);
    }

    protected function loginAdmin(int $adminId): void
    {
        Session::reset();
        Session::put('aid', $adminId);
        AdminAuth::reset();
    }

    protected function typeId(string $code = 'BDT100'): int
    {
        return (int) DB::val('SELECT id FROM bond_types WHERE code = ?', [$code]);
    }

    /** Publish a draw directly (verified) with given tier => numbers. Returns draw id. */
    protected function publishDraw(int $drawNumber, string $date, array $tiers, int $version = 1): int
    {
        $now = DB::now();
        $typeId = $this->typeId();
        $drawId = DB::insert('prize_draws', ['bond_type_id' => $typeId, 'draw_number' => $drawNumber, 'draw_date' => $date, 'series_count' => 86,
            'import_status' => 'imported', 'verification_status' => 'verified', 'publication_status' => 'published', 'lifecycle' => 'published',
            'source_url' => 'https://www.bb.org.bd/investfacility/prizebond/' . $drawNumber . 'thdraw.pdf', 'version' => $version, 'created_at' => $now, 'published_at' => $now, 'updated_at' => $now]);
        foreach ($tiers as $code => $numbers) {
            $tier = DB::one('SELECT id, amount FROM prize_tiers WHERE bond_type_id = ? AND code = ?', [$typeId, (string) $code]);
            foreach ($numbers as $n) {
                DB::insert('winning_numbers', ['draw_id' => $drawId, 'prize_tier_id' => $tier['id'], 'number' => $n, 'series' => '', 'amount' => $tier['amount'], 'status' => 'active', 'created_at' => $now]);
            }
        }
        return $drawId;
    }

    protected function addBond(int $userId, string $number, string $series = '', ?string $purchase = null, string $status = 'active'): int
    {
        $now = DB::now();
        return DB::insert('prize_bonds', ['user_id' => $userId, 'bond_type_id' => $this->typeId(), 'series' => $series, 'number' => $number,
            'purchase_date' => $purchase, 'status' => $status, 'created_at' => $now, 'updated_at' => $now]);
    }

    protected function fixture(string $name): string
    {
        return BASE_PATH . '/tests/Fixtures/' . $name;
    }

    /** A complete, valid staged structure for the Tk.100 bond (all 46 numbers). */
    protected function validStaged(int $drawNumber = 200, string $date = '2027-01-31'): array
    {
        $n = fn(int $seed, int $count) => array_map(fn($i) => str_pad((string) (($seed * 7919 + $i * 104729) % 10000000), 7, '0', STR_PAD_LEFT), range(1, $count));
        $tiers = ['1' => $n(1, 1), '2' => $n(2, 1), '3' => $n(3, 2), '4' => $n(4, 2), '5' => $n(5, 40)];
        $out = ['parser' => 'test', 'draw_number' => $drawNumber, 'draw_date' => $date, 'series_count' => 86, 'warnings' => [], 'tiers' => []];
        foreach ([['1', 600000, 1], ['2', 325000, 1], ['3', 100000, 2], ['4', 50000, 2], ['5', 10000, 40]] as [$c, $a, $e]) {
            $out['tiers'][$c] = ['amount' => $a, 'expected_count' => $e, 'numbers' => $tiers[$c]];
        }
        return $out;
    }
}
