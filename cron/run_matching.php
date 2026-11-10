<?php
/** Cron: run_matching - CLI only. See CRON_SETUP.md */
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
use App\Services\CronRunner;
use App\Services\Pipeline;
exit(CronRunner::run('run_matching', fn() => (new Pipeline())->runMatching(), 1800));
