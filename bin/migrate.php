<?php
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
if (PHP_SAPI !== 'cli') { exit(1); }
$cmd = $argv[1] ?? 'up';
$m = new App\Services\Migrator();
try {
    if ($cmd === 'rollback') { foreach ($m->rollback() as $n) echo "Rolled back $n\n"; }
    else { $a = $m->migrate(); echo $a ? implode("\n", array_map(fn($n) => "Migrated $n", $a)) . "\n" : "Nothing to migrate.\n"; }
} catch (Throwable $e) { fwrite(STDERR, "Migration failed: " . $e->getMessage() . "\n"); exit(1); }
