<?php
/** Cron: process_imports - CLI only. See CRON_SETUP.md */
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
use App\Services\CronRunner;
use App\Services\Pipeline;
exit(CronRunner::run('process_imports', fn() => (new Pipeline())->processImports(), 900));
