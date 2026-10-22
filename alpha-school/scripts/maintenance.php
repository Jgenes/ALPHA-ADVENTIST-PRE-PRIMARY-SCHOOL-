<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
require dirname(__DIR__) . "/src/php/operations.php";
require dirname(__DIR__) . "/src/php/jobs.php";
cli_main(function () {
    $p = cli_application();
    a_platform_jobs_maintenance($p);
    deliver_alerts($p);
    echo "Maintenance completed.\n";
});
