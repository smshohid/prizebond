<?php
/** Cron: retry_failed_jobs - CLI only. See CRON_SETUP.md */
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
use App\Services\CronRunner;
use App\Services\Pipeline;
exit(CronRunner::run('retry_failed_jobs', fn() => (new Pipeline())->retryFailed(), 900));
