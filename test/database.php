<?php
/** Tests never use the site's .env database and never delete existing records. */
function test_mysql_settings(string $name): array
{
    $url = getenv($name) ?: "";
    $m = parse_url($url);
    $database = $m ? rawurldecode(ltrim($m["path"] ?? "", "/")) : "";
    if (
        !$m || ($m["scheme"] ?? "") !== "mysql" ||
        !preg_match('/^[A-Za-z0-9_]+_test$/D', $database) ||
        empty($m["host"]) || empty($m["user"]) ||
        !preg_match('/^[A-Za-z0-9.-]+$/D', $m["host"])
    ) {
        throw new RuntimeException("Set $name to a dedicated empty MySQL schema ending in _test. Never use a live database.");
    }
    return [
        "host" => $m["host"],
        "port" => $m["port"] ?? 3306,
        "user" => rawurldecode($m["user"]),
        "password" => rawurldecode($m["pass"] ?? ""),
        "database" => $database,
    ];
}
function test_empty_store(array $config): Store
{
    // Check before Store creates any schema objects.
    $db = mysql_connection($config);
    if ((int) $db->query("SELECT COUNT(*) FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")->fetchColumn() !== 0) {
        throw new RuntimeException("Tests require an empty disposable schema; existing tables are never cleared automatically.");
    }
    return new Store($config);
}
