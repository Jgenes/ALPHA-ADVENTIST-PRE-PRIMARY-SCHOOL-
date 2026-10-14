<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
if (
    env_value("UI_TEST_FIXTURE") !== "true" ||
    env_value("APP_ENV") !== "test" ||
    !str_contains(env_value("DATA_DIR"), "alpha-php-ui-")
) {
    throw new RuntimeException(
        "Synthetic fixture requires an isolated test directory.",
    );
}
$c = configuration();
$store = new Store($c);
$p = new Platform($store, $c, new Files($c));
$p->init();
$password = "Synthetic-Browser-123!";
$users = [];
foreach (
    [
        "tech" => "system_admin",
        "head" => "head_teacher",
        "office" => "school_admin",
        "hr" => "hr_officer",
        "hod" => "head_of_department",
        "teacher" => "teacher",
        "parent" => "parent",
        "student" => "student",
    ]
    as $key => $role
) {
    $u = create_user(
        "fixture." . $key,
        "Synthetic " . $key,
        [$role],
        $password,
    );
    $u["mustChangePassword"] = false;
    $users[$key] = safe_user($store->run(fn($tx) => $tx->insert("users", $u)));
}
echo js_json_stringify(["users" => $users, "password" => $password]);
