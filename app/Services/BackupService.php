<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Logger;
use App\Core\Settings;

/** mysqldump based backups, kept OUTSIDE public/, with retention and a basic integrity check. */
final class BackupService
{
    /** @return array{file:string,bytes:int} */
    public function run(): array
    {
        $dirCfg = (string) env('BACKUP_DIR', 'storage/backups');
        $dir = preg_match('#^([A-Za-z]:)?[\\\\/]#', $dirCfg) ? $dirCfg : base_path($dirCfg);
        if (str_contains(realpath($dir) ?: $dir, DIRECTORY_SEPARATOR . 'public')) {
            throw new \RuntimeException('Backup directory must not be inside public/.');
        }
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) {
            throw new \RuntimeException('Cannot create backup directory.');
        }
        $file = rtrim($dir, '/\\') . DIRECTORY_SEPARATOR . 'prizebond_' . gmdate('Ymd_His') . '.sql';
        $bin = (string) env('MYSQLDUMP_PATH', 'mysqldump');
        // Credentials via a temp defaults file (never on the command line / process list).
        $cnf = tempnam(sys_get_temp_dir(), 'pbc');
        file_put_contents($cnf, "[client]\nuser=\"" . addcslashes((string) env('DB_USERNAME', ''), '"\\') . "\"\npassword=\"" . addcslashes((string) env('DB_PASSWORD', ''), '"\\') . "\"\nhost=" . env('DB_HOST', '127.0.0.1') . "\nport=" . env('DB_PORT', '3306') . "\n");
        @chmod($cnf, 0600);
        try {
            $cmd = escapeshellarg($bin) . ' --defaults-extra-file=' . escapeshellarg($cnf) . ' --single-transaction --routines --default-character-set=utf8mb4 --result-file=' . escapeshellarg($file) . ' ' . escapeshellarg((string) env('DB_DATABASE', 'prizebond')) . ' 2>&1';
            exec($cmd, $out, $code);
        } finally {
            @unlink($cnf);
        }
        if ($code !== 0 || !is_file($file)) {
            @unlink($file);
            throw new \RuntimeException('mysqldump failed: ' . Logger::scrub(implode(' ', array_slice($out ?? [], 0, 3))));
        }
        $tail = (string) file_get_contents($file, false, null, max(0, filesize($file) - 200));
        if (!str_contains($tail, 'Dump completed') || filesize($file) < 1000) {
            @unlink($file);
            throw new \RuntimeException('Backup verification failed (incomplete dump).');
        }
        @chmod($file, 0600);
        $this->prune($dir);
        Logger::info('backup', 'Backup created', ['file' => basename($file), 'bytes' => filesize($file)]);
        return ['file' => $file, 'bytes' => (int) filesize($file)];
    }

    private function prune(string $dir): void
    {
        $days = Settings::int('backup.retention_days', 14);
        foreach (glob($dir . DIRECTORY_SEPARATOR . 'prizebond_*.sql') ?: [] as $f) {
            if (filemtime($f) < time() - $days * 86400) {
                @unlink($f);
            }
        }
    }
}
