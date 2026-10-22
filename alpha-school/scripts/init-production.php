<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
require dirname(__DIR__) . "/src/php/operations.php";
cli_main(function () {
    $options = getopt("", ["create-database"]);
    $config = configuration();
    if (!$config["mysql"]) {
        throw new RuntimeException(
            "This setup command requires MySQL settings in alpha-school/config.php.",
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
        // configuration() restricts the schema to letters, digits and underscores.
        $database = $config["mysql"]["database"];
        $admin->exec(
            "CREATE DATABASE IF NOT EXISTS `$database` CHARACTER SET utf8mb4 COLLATE utf8mb4_bin",
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
    echo "MySQL " . $config["mysql"]["database"] . " schema initialized; encrypted local file storage ready.\n";
    echo "No existing database was renamed, dropped or copied. Run verify-integrations.php to check delivery.\n";
});
