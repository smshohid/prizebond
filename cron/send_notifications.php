<?php
/** Cron: send_notifications - CLI only. See CRON_SETUP.md */
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
use App\Services\CronRunner;
use App\Services\Pipeline;
exit(CronRunner::run('send_notifications', fn() => json_encode((new App\Services\NotificationService())->processDue()), 600));
