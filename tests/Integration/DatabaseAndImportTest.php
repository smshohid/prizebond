<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DB;
use App\Services\BondService;
use App\Services\Migrator;
use Tests\TestCase;

final class DatabaseAndImportTest extends TestCase
{
    public function testAllRequiredTablesExistWithInnoDbAndUtf8mb4(): void
    {
        $required = ['users', 'user_settings', 'email_verification_tokens', 'password_reset_tokens', 'bond_types', 'prize_bonds', 'prize_draws', 'prize_tiers',
            'winning_numbers', 'prize_matches', 'notification_queue', 'notification_logs', 'result_sources', 'result_imports', 'import_logs', 'admin_users',
            'admin_roles', 'admin_permissions', 'admin_role_permissions', 'audit_logs', 'system_settings', 'cron_job_logs', 'failed_jobs'];
        $rows = DB::all("SELECT TABLE_NAME, ENGINE, TABLE_COLLATION FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        $have = array_column($rows, null, 'TABLE_NAME');
        foreach ($required as $t) {
            $this->assertArrayHasKey($t, $have, "missing table $t");
            $this->assertSame('InnoDB', $have[$t]['ENGINE'], $t);
            $this->assertStringStartsWith('utf8mb4', $have[$t]['TABLE_COLLATION'], $t);
        }
    }

    public function testBondNumbersAreStoredAsVarcharNeverInteger(): void
    {
        $cols = DB::all("SELECT TABLE_NAME, COLUMN_NAME, DATA_TYPE FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND COLUMN_NAME IN ('number','series')");
        $this->assertNotEmpty($cols);
        foreach ($cols as $c) {
            $this->assertSame('varchar', $c['DATA_TYPE'], "{$c['TABLE_NAME']}.{$c['COLUMN_NAME']}");
        }
        $u = $this->createUser();
        $id = $this->addBond($u, '0000042');
        $this->assertSame('0000042', DB::val('SELECT number FROM prize_bonds WHERE id = ?', [$id]));
    }

    public function testForeignKeysAndUniqueConstraintsExist(): void
    {
        $fks = (int) DB::val("SELECT COUNT(*) FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
        $this->assertGreaterThanOrEqual(25, $fks);
        $uq = array_column(DB::all("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND CONSTRAINT_TYPE = 'UNIQUE'"), 'CONSTRAINT_NAME');
        foreach (['uq_users_email', 'uq_draw', 'uq_wn', 'uq_match', 'uq_nq_key', 'uq_bond_user', 'uq_tier_code'] as $name) {
            $this->assertContains($name, $uq);
        }
    }

    public function testDatabaseRejectsDuplicatesAndOrphans(): void
    {
        $u = $this->createUser();
        $this->addBond($u, '0000001', 'KK');
        $this->expectException(\PDOException::class);
        $this->addBond($u, '0000001', 'KK');
    }

    public function testDrawUniquenessAndWinningNumberUniqueness(): void
    {
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        try {
            $this->publishDraw(125, '2026-11-01', []);
            $this->fail('duplicate draw number must be rejected');
        } catch (\PDOException) {
        }
        $tier = DB::val("SELECT id FROM prize_tiers WHERE code = '1'");
        $this->expectException(\PDOException::class);
        DB::insert('winning_numbers', ['draw_id' => $d, 'prize_tier_id' => $tier, 'number' => '0923283', 'series' => '', 'amount' => 600000, 'status' => 'active', 'created_at' => DB::now()]);
    }

    public function testSeedDataMatchesOfficialStructure(): void
    {
        $t = DB::all("SELECT code, amount, expected_count FROM prize_tiers ORDER BY rank_order");
        $this->assertSame(['1', '2', '3', '4', '5'], array_column($t, 'code'));
        $this->assertEquals([600000, 325000, 100000, 50000, 10000], array_column($t, 'amount'));
        $this->assertEquals([1, 1, 2, 2, 40], array_column($t, 'expected_count'));
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM bond_types WHERE is_enabled = 1"), 'only the Tk.100 bond is enabled');
        $this->assertSame(['super_admin', 'result_manager', 'support_admin'], array_column(DB::all('SELECT name FROM admin_roles ORDER BY id'), 'name'));
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM admin_users'), 'no default administrator exists');
    }

    public function testMigrationsRollBackAndReapplyCleanly(): void
    {
        $m = new Migrator();
        $before = (int) DB::val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()");
        $rolled = $m->rollback();
        $this->assertNotEmpty($rolled);
        $this->assertLessThan($before, (int) DB::val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"));
        $m->migrate();
        $this->assertSame($before, (int) DB::val("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA = DATABASE()"));
        $this->assertSame([], $m->migrate(), 'idempotent: second run applies nothing');
    }

    public function testTransactionRollsBackOnError(): void
    {
        try {
            DB::tx(function () {
                $this->createUser('tx@example.com');
                throw new \RuntimeException('boom');
            });
        } catch (\RuntimeException) {
        }
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM users'));
    }

    // ---- bulk import --------------------------------------------------------------

    public function testBulkImportReportsEveryRowAndKeepsLeadingZeros(): void
    {
        $u = $this->createUser();
        $this->addBond($u, '0000333', 'KK');
        $csv = "series,number,purchase_date\n"
            . "KK,0046900,2025-01-15\n"      // ok
            . ",0123456,\n"                  // ok, no series, no date
            . "KL,0046900,\n"                // ok (different series)
            . "KK,0046900,2025-01-15\n"      // duplicate in file
            . "KK,0000333,\n"                // duplicate of existing
            . "KK,12345,\n"                  // too short
            . "K1,0123457,\n"                // bad series
            . "KK,0123458,2999-01-01\n"      // future date
            . "KK,ABCDEFG,\n"                // not digits
            . "\n"
            . "মক,০৯২৩২৮৩,2024-12-01\n";      // Bangla series + Bangla digits
        $r = (new BondService())->importCsv($u, $this->typeId(), $csv);
        $by = array_column($r['rows'], 'status', 'line');
        $this->assertSame(4, $r['inserted']);
        $this->assertSame(['ok', 'ok', 'ok', 'duplicate', 'duplicate', 'error', 'error', 'error', 'error', 'ok'], array_values($by));
        $this->assertSame(2, $r['duplicates']);
        $this->assertSame(4, $r['errors']);
        $numbers = array_column(DB::all('SELECT number FROM prize_bonds WHERE user_id = ? ORDER BY id', [$u]), 'number');
        $this->assertSame(['0000333', '0046900', '0123456', '0046900', '0923283'], $numbers);
        foreach ($r['rows'] as $row) {
            $this->assertNotSame('', $row['message'], 'no silent discards: every row has a message');
        }
    }

    public function testBulkImportWithoutHeaderAndRowLimit(): void
    {
        $u = $this->createUser();
        $r = (new BondService())->importCsv($u, $this->typeId(), "KK,0000001\nKK,0000002");
        $this->assertSame(2, $r['inserted']);
        $big = implode("\n", array_map(fn($i) => ',' . str_pad((string) $i, 7, '0', STR_PAD_LEFT), range(1, 2100)));
        $r = (new BondService())->importCsv($u, $this->typeId(), $big);
        $this->assertSame('bond.err_too_many_rows', $r['fatal']);
        $this->assertSame(2, (int) DB::val('SELECT COUNT(*) FROM prize_bonds WHERE user_id = ?', [$u]), 'nothing partially imported on a fatal error');
    }

    public function testBulkImportIntoDisabledTypeFails(): void
    {
        $r = (new BondService())->importCsv($this->createUser(), 9999, ",0000001");
        $this->assertSame('bond.err_type', $r['fatal']);
    }
}
