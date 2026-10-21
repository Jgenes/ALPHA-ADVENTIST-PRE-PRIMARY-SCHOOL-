<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
require dirname(__DIR__) . "/src/php/operations.php";
cli_main(function () use ($argv) {
    load_environment();
    $snapshot = open_backup($argv[1] ?? "", backup_key());
    $target = env_value("MYSQL_RESTORE_DB_NAME");
    $dir = env_value("RESTORE_DATA_DIR");
    if (
        in_array(
            env_value("APP_ENV"),
            ["production", "staging"],
            true,
        ) ||
        env_value("RESTORE_APPROVED") !== "true" ||
        !preg_match('/^[A-Za-z0-9_]+_restore_test$/', $target) ||
        $target === ($snapshot["database"] ?? "") ||
        $target === env_value("MYSQL_DB_NAME") ||
        !env_value("MYSQL_RESTORE_URL") ||
        !$dir
    ) {
        throw new RuntimeException(
            "Restore requires an approved isolated schema ending in _restore_test.",
        );
    }
    if (is_dir($dir) && count(array_diff(scandir($dir), [".", ".."])) > 0) {
        throw new RuntimeException("Restore storage must be empty.");
    }
    if (realpath($dir) && realpath($dir) === realpath(env_value("DATA_DIR"))) {
        throw new RuntimeException("Do not restore into active storage.");
    }
    putenv("APP_ENV=development");
    putenv("MYSQL_URL=" . env_value("MYSQL_RESTORE_URL"));
    putenv("MYSQL_DB_NAME=" . $target);
    putenv("DATA_DIR=" . $dir);
    $p = cli_application(false);
    $counts = restore_snapshot($p->store, $p->files, $snapshot);
    echo "Isolated restore complete: " .
        array_sum($counts) .
        " records. Sessions/grants invalidated; no public files recreated.\nSupply the separately escrowed application keys before using restored files or verifying audit integrity. Reconcile consent withdrawals before any cutover.\n";
});
