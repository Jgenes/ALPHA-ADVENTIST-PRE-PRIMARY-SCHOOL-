<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
require dirname(__DIR__) . "/src/php/operations.php";
cli_main(function () {
    load_environment();
    $o = getopt("", ["username:", "name:", "role:"]);
    $role = $o["role"] ?? "";
    if (
        env_value("BOOTSTRAP_APPROVED") !== "true" ||
        !env_value("BOOTSTRAP_APPROVAL_REFERENCE") ||
        !in_array($role, ["system_admin", "head_teacher"], true)
    ) {
        throw new RuntimeException(
            "Explicit owner-approved appointment required.",
        );
    }
    $file = env_value("BOOTSTRAP_PASSWORD_FILE");
    if (!protected_file($file)) {
        throw new RuntimeException(
            "Use a protected password file; never a command argument.",
        );
    }
    $password = preg_replace('/\r?\n$/', "", file_get_contents($file));
    $p = cli_application();
    $u = create_user(
        strtolower($o["username"] ?? ""),
        $o["name"] ?? "",
        [$role],
        $password,
    );
    $p->store->run(function ($tx) use ($p, $u, $role) {
        foreach ($tx->list("users") as $old) {
            if (in_array($role, $old["roles"], true)) {
                throw new RuntimeException("Initial authority already exists.");
            }
        }
        $tx->insert("users", $u);
        audit(
            $tx,
            $p->config,
            ["userId" => "owner-approved-bootstrap"],
            "user.bootstrap",
            "user",
            $u["id"],
            "success",
            [
                "role" => $role,
                "approvalReference" => substr(
                    env_value("BOOTSTRAP_APPROVAL_REFERENCE"),
                    0,
                    120,
                ),
            ],
        );
    });
    echo "Initial $role account created. First sign-in requires a password change. Remove the temporary password file after secure handover. No password is printed.\n";
});
