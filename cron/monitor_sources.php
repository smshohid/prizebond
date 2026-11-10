<?php
/** Cron: monitor_sources - CLI only. See CRON_SETUP.md */
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
use App\Services\CronRunner;
use App\Services\Pipeline;
exit(CronRunner::run('monitor_sources', fn() => (function () { $s = (new App\Services\SourceMonitor())->run(in_array("--force", $GLOBALS["argv"] ?? [], true)); return "discovered={$s["discovered"]} fetched={$s["fetched"]} changed={$s["changed"]}" . ($s["skipped"] ? " (not due yet)" : ""); })(), 1800));
