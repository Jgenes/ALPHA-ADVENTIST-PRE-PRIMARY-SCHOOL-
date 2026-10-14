<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
require dirname(__DIR__) . "/src/php/operations.php";
require dirname(__DIR__) . "/src/php/jobs.php";
// Container worker; shared database transactions serialize it against web requests.
// Non-container installations can use scripts/maintenance.php in a one-minute cron.
while (true) {
    try {
        $p = cli_application();
        a_platform_jobs_maintenance($p);
        deliver_alerts($p);
        unset($p);
    } catch (Throwable $e) {
        error_log(
            "[alpha maintenance] Job failed; check protected storage and database availability.",
        );
    }
    sleep(60);
}
