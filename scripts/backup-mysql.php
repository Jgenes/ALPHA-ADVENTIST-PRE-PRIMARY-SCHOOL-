<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
require dirname(__DIR__) . "/src/php/operations.php";
cli_main(function () {
    load_environment();
    if (env_value("BACKUP_WRITES_PAUSED") !== "true") {
        throw new RuntimeException(
            "Pause writes in an approved backup window first.",
        );
    }
    $p = cli_application(false);
    $key = backup_key();
    if (
        hash_equals($key, $p->config["storageKey"]) ||
        hash_equals($key, $p->config["auditKey"])
    ) {
        throw new RuntimeException("Use a separate backup key.");
    }
    $snapshot = capture_snapshot($p->store, $p->files, $p->config);
    $bytes = seal_backup($snapshot, $key);
    $dir = env_value("MYSQL_BACKUP_DIR", $p->config["dataDir"] . "/backups");
    if (!is_dir($dir)) {
        mkdir($dir, 0700, true);
    }
    chmod($dir, 0700);
    $file =
        $dir .
        "/" .
        preg_replace("/[^A-Za-z0-9_-]/", "_", $snapshot["database"]) .
        "-" .
        gmdate("Y-m-d\TH-i-s") .
        "-" .
        bin2hex(random_bytes(4)) .
        ".json.enc";
    $fh = fopen($file, "x");
    fwrite($fh, $bytes);
    fflush($fh);
    fclose($fh);
    chmod($file, 0600);
    echo "Encrypted backup: $file\nTables: " .
        count($snapshot["collections"]) .
        "; referenced private files: " .
        count($snapshot["files"]) .
        "; bytes: " .
        strlen($bytes) .
        "\nSHA-256: " .
        sha256($bytes) .
        "\nTransfer off-site and escrow application keys separately.\n";
});
