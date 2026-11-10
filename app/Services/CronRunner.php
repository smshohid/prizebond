<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;

/** Wraps every cron script: CLI check, DB lock, logging, duration, exit codes. */
final class CronRunner
{
    public const OK = 0;
    public const FAILED = 1;
    public const LOCKED = 2;

    /** @param callable():string $job returns a human-readable summary */
    public static function run(string $name, callable $job, int $lockSeconds = 900): int
    {
        if (PHP_SAPI !== 'cli') {
            http_response_code(403);
            exit("CLI only\n");
        }
        $owner = gethostname() . ':' . getmypid();
        try {
            DB::pdo();
        } catch (\Throwable $e) {
            fwrite(STDERR, "[$name] database unavailable: " . Logger::scrub($e->getMessage()) . "\n");
            return self::FAILED;
        }
        if (!self::acquire($name, $owner, $lockSeconds)) {
            $id = DB::insert('cron_job_logs', ['job_name' => $name, 'status' => 'skipped', 'started_at' => DB::now(), 'finished_at' => DB::now(), 'duration_ms' => 0, 'message' => 'Another instance holds the lock']);
            echo "[$name] skipped: already running\n";
            return self::LOCKED;
        }
        $t0 = microtime(true);
        $logId = DB::insert('cron_job_logs', ['job_name' => $name, 'status' => 'running', 'started_at' => DB::now()]);
        try {
            $msg = $job();
            $status = 'success';
            $code = self::OK;
        } catch (\Throwable $e) {
            $msg = Logger::scrub($e::class . ': ' . $e->getMessage());
            $status = 'failed';
            $code = self::FAILED;
            Logger::error('cron', "$name failed: $msg");
        } finally {
            DB::exec('DELETE FROM job_locks WHERE name = ? AND owner = ?', [$name, $owner]);
        }
        DB::update('cron_job_logs', ['status' => $status, 'finished_at' => DB::now(), 'duration_ms' => (int) ((microtime(true) - $t0) * 1000), 'message' => mb_substr($msg, 0, 2000)], 'id = ?', [$logId]);
        echo "[$name] $status: $msg\n";
        return $code;
    }

    private static function acquire(string $name, string $owner, int $seconds): bool
    {
        DB::exec('DELETE FROM job_locks WHERE name = ? AND locked_until < ?', [$name, DB::now()]);
        return DB::exec('INSERT IGNORE INTO job_locks (name, locked_until, owner) VALUES (?,?,?)', [$name, gmdate('Y-m-d H:i:s', time() + $seconds), $owner]) === 1;
    }
}
