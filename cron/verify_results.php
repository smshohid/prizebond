<?php
/** Cron: verify_results - CLI only. See CRON_SETUP.md */
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
use App\Services\CronRunner;
use App\Services\Pipeline;
exit(CronRunner::run('verify_results', fn() => (new Pipeline())->verifyResults(), 1800));
