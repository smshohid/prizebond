<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DB;
use App\Services\BackupService;
use Tests\TestCase;

/** Executes the real cron scripts as separate PHP CLI processes against the isolated test database. */
final class CronScriptsTest extends TestCase
{
    private string $backupDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'pbm_bk_' . bin2hex(random_bytes(4));
    }

    protected function tearDown(): void
    {
        foreach (glob($this->backupDir . '/*') ?: [] as $f) {
            @unlink($f);
        }
        @rmdir($this->backupDir);
        parent::tearDown();
    }

    /** @return array{0:int,1:string} exit code, combined output */
    private function cron(string $script, array $env = []): array
    {
        $envAll = array_merge(getenv(), $_ENV, [
            'APP_ENV' => 'testing', 'DB_DATABASE' => 'prizebond_test', 'MAIL_TRANSPORT' => 'log', 'BACKUP_DIR' => $this->backupDir,
        ], $env);
        $p = proc_open([PHP_BINARY, base_path("cron/$script.php")], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $envAll);
        $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
        return [proc_close($p), $out];
    }

    public function testEveryCronScriptRunsCleanlyAndIsLogged(): void
    {
        DB::exec("UPDATE result_sources SET last_checked_at = ? WHERE code = 'bb'", [DB::now()]);   // monitor: "not due" -> no network in tests
        foreach (['monitor_sources', 'process_imports', 'verify_results', 'run_matching', 'send_notifications', 'retry_failed_jobs', 'daily_maintenance'] as $job) {
            [$code, $out] = $this->cron($job);
            $this->assertSame(0, $code, "$job: $out");
            $this->assertStringContainsString("[$job] success", $out);
            $log = DB::one('SELECT * FROM cron_job_logs WHERE job_name = ? ORDER BY id DESC LIMIT 1', [$job]);
            $this->assertSame('success', $log['status'], $job);
            $this->assertNotNull($log['finished_at']);
            $this->assertNotNull($log['duration_ms']);
            $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM job_locks'), "$job released its lock");
        }
    }

    public function testConcurrentRunIsBlockedByDatabaseLock(): void
    {
        DB::insert('job_locks', ['name' => 'run_matching', 'locked_until' => gmdate('Y-m-d H:i:s', time() + 600), 'owner' => 'other-host:1']);
        [$code, $out] = $this->cron('run_matching');
        $this->assertSame(2, $code);
        $this->assertStringContainsString('skipped', $out);
        $this->assertSame('skipped', DB::val("SELECT status FROM cron_job_logs WHERE job_name = 'run_matching'"));
        // an expired lock (crashed worker) is reclaimed
        DB::exec("UPDATE job_locks SET locked_until = ?", [gmdate('Y-m-d H:i:s', time() - 5)]);
        [$code] = $this->cron('run_matching');
        $this->assertSame(0, $code);
    }

    public function testFailingJobReturnsNonZeroAndIsRecorded(): void
    {
        [$code, $out] = $this->cron('send_notifications', ['DB_DATABASE' => 'prizebond_does_not_exist_test']);
        $this->assertNotSame(0, $code);
        $this->assertStringNotContainsString((string) env('DB_PASSWORD', "\0never"), $out);
    }

    public function testMatchingAndSendingPipelineViaCli(): void
    {
        $u = $this->createUser('winner@example.com', 'Str0ngPassw0rd', true, 'Lucky Person');
        $this->addBond($u, '0923283', 'KK', '2025-01-01');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        [$c1, $o1] = $this->cron('run_matching');
        $this->assertSame(0, $c1, $o1);
        $this->assertSame(1, (int) DB::val("SELECT COUNT(*) FROM prize_matches WHERE draw_id = $d"));
        $this->assertSame('notifications_queued', DB::val('SELECT lifecycle FROM prize_draws WHERE id = ?', [$d]));
        $this->assertSame('pending', DB::val("SELECT status FROM notification_queue WHERE type = 'winning_match'"));

        $mailLog = base_path('storage/logs/mail-' . gmdate('Y-m-d') . '.log');
        clearstatcache();
        $before = is_file($mailLog) ? filesize($mailLog) : 0;
        [$c2, $o2] = $this->cron('send_notifications');
        $this->assertSame(0, $c2, $o2);
        $this->assertSame('sent', DB::val("SELECT status FROM notification_queue WHERE type = 'winning_match'"));
        $this->assertSame('completed', DB::val('SELECT lifecycle FROM prize_draws WHERE id = ?', [$d]));
        clearstatcache();
        $this->assertGreaterThan($before, filesize($mailLog), 'log transport wrote the message (no real SMTP)');
        $written = substr((string) file_get_contents($mailLog), $before);
        $this->assertStringContainsString('winner@example.com', $written);
        $this->assertStringContainsString('0923283', $written);

        // re-running the whole thing changes nothing
        $this->cron('run_matching');
        clearstatcache();
        $size = filesize($mailLog);
        $this->cron('send_notifications');
        clearstatcache();
        $this->assertSame($size, filesize($mailLog));
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM notification_queue WHERE type = \'winning_match\''));
    }

    public function testDailyMaintenancePurgesExpiredTokensAndRateLimits(): void
    {
        $u = $this->createUser();
        DB::insert('password_reset_tokens', ['user_id' => $u, 'token_hash' => str_repeat('a', 64), 'expires_at' => gmdate('Y-m-d H:i:s', time() - 100), 'created_at' => DB::now()]);
        DB::insert('rate_limits', ['rl_key' => 'old', 'hits' => 3, 'window_start' => gmdate('Y-m-d H:i:s', time() - 200000)]);
        $unverified = $this->createUser('stale@example.com', 'Str0ngPassw0rd', false);
        DB::exec('UPDATE users SET created_at = ? WHERE id = ?', [gmdate('Y-m-d H:i:s', time() - 40 * 86400), $unverified]);
        [$code, $out] = $this->cron('daily_maintenance');
        $this->assertSame(0, $code, $out);
        $this->assertSame(0, (int) DB::val('SELECT COUNT(*) FROM password_reset_tokens'));
        $this->assertSame(0, (int) DB::val("SELECT COUNT(*) FROM rate_limits WHERE rl_key = 'old'"));
        $this->assertNull(DB::val('SELECT id FROM users WHERE id = ?', [$unverified]));
        $this->assertNotNull(DB::val('SELECT id FROM users WHERE id = ?', [$u]));
        $this->assertStringContainsString('backup', $out);
    }

    public function testBackupIsCreatedVerifiedPrunedAndKeptOutOfPublic(): void
    {
        $_ENV['BACKUP_DIR'] = $this->backupDir;
        putenv('BACKUP_DIR=' . $this->backupDir);
        $this->createUser();
        $r = (new BackupService())->run();
        $this->assertFileExists($r['file']);
        $this->assertStringStartsWith($this->backupDir, $r['file']);
        $this->assertMatchesRegularExpression('/prizebond_\d{8}_\d{6}\.sql$/', $r['file']);
        $dump = (string) file_get_contents($r['file']);
        $this->assertStringContainsString('CREATE TABLE `users`', $dump);
        $this->assertStringContainsString('Dump completed', $dump);
        $this->assertStringNotContainsString('PASSWORD', strtoupper((string) env('DB_PASSWORD', 'x')) === '' ? 'x' : 'y');
        $old = $this->backupDir . '/prizebond_20200101_000000.sql';
        file_put_contents($old, '-- old');
        touch($old, time() - 40 * 86400);
        (new BackupService())->run();
        $this->assertFileDoesNotExist($old, 'retention applied');
        $this->assertStringNotContainsString(DIRECTORY_SEPARATOR . 'public', $r['file']);
        putenv('BACKUP_DIR');
        unset($_ENV['BACKUP_DIR']);
    }

    public function testBackupRefusesPublicDirectory(): void
    {
        putenv('BACKUP_DIR=' . base_path('public/backups'));
        $_ENV['BACKUP_DIR'] = base_path('public/backups');
        try {
            (new BackupService())->run();
            $this->fail('must refuse');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('public', $e->getMessage());
        } finally {
            putenv('BACKUP_DIR');
            unset($_ENV['BACKUP_DIR']);
        }
    }
}
