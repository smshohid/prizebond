<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/** Versioned migration runner. Migration files live in database/migrations/NNNN_name.php */
final class Migrator
{
    public function __construct(private ?string $dir = null)
    {
        $this->dir ??= base_path('database/migrations');
    }

    private function ensureTable(): void
    {
        DB::pdo()->exec("CREATE TABLE IF NOT EXISTS migrations (
            id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            migration VARCHAR(190) NOT NULL UNIQUE,
            batch INT UNSIGNED NOT NULL,
            applied_at DATETIME NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** @return string[] names of migrations applied in this run */
    public function migrate(): array
    {
        $this->ensureTable();
        $done = array_column(DB::all('SELECT migration FROM migrations'), 'migration');
        $batch = (int) DB::val('SELECT COALESCE(MAX(batch),0) FROM migrations') + 1;
        $applied = [];
        $files = glob($this->dir . '/*.php') ?: [];
        sort($files);
        foreach ($files as $f) {
            $name = basename($f, '.php');
            if (in_array($name, $done, true)) {
                continue;
            }
            $def = require $f;
            foreach ($def['up'] as $stmt) {
                if (is_array($stmt)) {
                    DB::q($stmt[0], $stmt[1]);
                } else {
                    DB::pdo()->exec($stmt);
                }
            }
            DB::insert('migrations', ['migration' => $name, 'batch' => $batch, 'applied_at' => DB::now()]);
            $applied[] = $name;
        }
        return $applied;
    }

    /** Roll back the most recent batch. @return string[] */
    public function rollback(): array
    {
        $this->ensureTable();
        $batch = (int) DB::val('SELECT COALESCE(MAX(batch),0) FROM migrations');
        $rows = DB::all('SELECT migration FROM migrations WHERE batch = ? ORDER BY id DESC', [$batch]);
        $out = [];
        DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($rows as $r) {
            $def = require $this->dir . '/' . $r['migration'] . '.php';
            foreach ($def['down'] as $stmt) {
                DB::pdo()->exec(str_starts_with(strtoupper($stmt), 'DELETE') ? $stmt : "DROP TABLE IF EXISTS `$stmt`");
            }
            DB::exec('DELETE FROM migrations WHERE migration = ?', [$r['migration']]);
            $out[] = $r['migration'];
        }
        DB::pdo()->exec('SET FOREIGN_KEY_CHECKS = 1');
        return $out;
    }

    /** Drop every table (TEST DATABASES ONLY). */
    public function wipe(): void
    {
        $pdo = DB::pdo();
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
        foreach ($pdo->query('SHOW TABLES')->fetchAll(\PDO::FETCH_COLUMN) as $t) {
            $pdo->exec("DROP TABLE IF EXISTS `$t`");
        }
        $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
    }
}
