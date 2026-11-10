<?php
/** Cron: daily_maintenance - CLI only. See CRON_SETUP.md */
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
use App\Services\CronRunner;
use App\Services\Pipeline;
exit(CronRunner::run('daily_maintenance', fn() => (new Pipeline())->maintenance(), 3600));
