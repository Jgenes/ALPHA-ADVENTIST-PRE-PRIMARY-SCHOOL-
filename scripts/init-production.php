<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
require dirname(__DIR__) . "/src/php/operations.php";
cli_main(function () {
    $options = getopt("", ["create-database"]);
    $config = configuration();
    if (!$config["mysql"] || $config["mysql"]["database"] !== "alpha_school") {
        throw new RuntimeException(
            "This setup command requires MySQL database alpha_school.",
        );
    }
    if ($config["storageDriver"] !== "local") {
        throw new RuntimeException(
            "This deployment was selected to use persistent local storage.",
        );
    }
    if (isset($options["create-database"])) {
        if (env_value("MYSQL_SCHEMA_CREATE_APPROVED") !== "true") {
            throw new RuntimeException(
                "Creating the database requires MYSQL_SCHEMA_CREATE_APPROVED=true.",
            );
        }
        $admin = mysql_connection($config, false);
        $admin->exec(
            "CREATE DATABASE IF NOT EXISTS `alpha_school` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin",
        );
        unset($admin);
    }
    $store = new Store($config);
    $files = new Files($config);
    $platform = new Platform($store, $config, $files);
    $platform->init();
    if (!$store->health()) {
        throw new RuntimeException("MySQL health check failed.");
    }
    echo "MySQL alpha_school schema initialized; persistent encrypted disk storage ready.\n";
    echo "No existing database was renamed, dropped or copied. Run verify-integrations.php to check delivery.\n";
});
