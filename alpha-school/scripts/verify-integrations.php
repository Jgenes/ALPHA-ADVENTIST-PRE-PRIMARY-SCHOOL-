<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
require dirname(__DIR__) . "/src/php/jobs.php";
$options = getopt("", ["send-test:", "check:"]);
try {
    $config = configuration();
    if (!$config["mysql"]) {
        throw new RuntimeException(
            "Verification requires MySQL settings in alpha-school/config.php.",
        );
    }
    if ($config["storageDriver"] !== "local") {
        throw new RuntimeException(
            "Persistent local storage is required for this deployment.",
        );
    }
    $target = $options["send-test"] ?? null;
    if ($target !== null && !in_array($target, ["office", "privacy"], true)) {
        throw new RuntimeException(
            "Use --send-test=office or --send-test=privacy.",
        );
    }
    $check = $options["check"] ?? "all";
    if (!in_array($check, ["all", "database", "storage", "email"], true)) {
        throw new RuntimeException(
            "Use --check=database, storage, email or all.",
        );
    }
    if ($target !== null && !in_array($check, ["all", "email"], true)) {
        throw new RuntimeException(
            "Email sending requires --check=email or all.",
        );
    }
    if (in_array($check, ["all", "database"], true)) {
        $store = new Store($config);
        if (!$store->health()) {
            throw new RuntimeException("Database health check failed.");
        }
        echo "PASS: MySQL " . $config["mysql"]["database"] . " connectivity and application schema.\n";
    }
    if (in_array($check, ["all", "storage"], true)) {
        $files = new Files($config);
        $files->init();
        $file = null;
        try {
            $text =
                "Synthetic Alpha School integration check " .
                bin2hex(random_bytes(16));
            $file = $files->prepare([
                "name" => "integration-check.txt",
                "content" => base64_encode($text),
            ]);
            if (!hash_equals($text, $files->read($file))) {
                throw new RuntimeException(
                    "Encrypted storage round-trip failed.",
                );
            }
            $raw = $files->readStored("private", $file["storageKey"]);
            if (str_contains($raw, $text)) {
                throw new RuntimeException(
                    "Storage verification found unencrypted content.",
                );
            }
            echo "PASS: persistent disk write, AES-GCM decryption and checksum verification.\n";
        } finally {
            if ($file) {
                $files->discard($file);
            }
        }
    }
    if (in_array($check, ["all", "email"], true)) {
        if (!$config["smtp"]) {
            throw new RuntimeException(
                "Complete the provider SMTP settings in hosting secrets first.",
            );
        }
        echo "PASS: authenticated TLS SMTP configuration and approved office/privacy recipients.\n";
        if ($target !== null) {
            $alert = [
                "id" => rid("INTEGRATION-"),
                "reference" => "SYNTHETIC-INTEGRATION-CHECK",
                "type" =>
                    $target === "privacy"
                        ? "Privacy request"
                        : "Email integration check",
                "href" =>
                    $target === "privacy"
                        ? "/portal/privacy"
                        : "/portal/system",
                "createdAt" => (new JsDate())->toISOString(),
            ];
            send_smtp_alert($config["smtp"], $alert, $config["baseUrl"]);
            echo "PASS: SMTP provider accepted the synthetic $target test message. Confirm arrival in the approved mailbox.\n";
        } else {
            echo "NOT SENT: use --send-test=office or --send-test=privacy for an explicit delivery test.\n";
        }
    }
} catch (Throwable $error) {
    // Configuration exceptions have code-owned messages. Never print provider responses or secrets.
    $message =
        $error instanceof PDOException
            ? "MySQL connection/schema check failed; review host, credentials, TLS and schema access."
            : ($error instanceof RuntimeException
                ? $error->getMessage()
                : "Integration check failed; review the protected configuration.");
    fwrite(STDERR, "FAIL: " . $message . "\n");
    exit(1);
}
