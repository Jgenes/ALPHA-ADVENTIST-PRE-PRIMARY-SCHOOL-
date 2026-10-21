<?php
/** Server-only production integration configuration. Never serialize credentials to the portal. */
function integration_secret(
    string $name,
    bool $preserveWhitespace = true,
): string {
    $value = env_value($name);
    $file = env_value($name . "_FILE");
    if ($value !== "" && $file !== "") {
        throw new RuntimeException(
            "Configure $name or its protected file, not both.",
        );
    }
    if ($file) {
        if (
            !is_file($file) ||
            is_link($file) ||
            (fileperms($file) & 0077) !== 0
        ) {
            throw new RuntimeException(
                "{$name}_FILE must be a protected regular file (600 or stricter).",
            );
        }
        $value = preg_replace('/\r?\n$/', "", file_get_contents($file));
    }
    return $preserveWhitespace ? $value : preg_replace("/\s+/", "", $value);
}
function smtp_configuration(): ?array
{
    $genericKeys = [
        "SMTP_HOST",
        "SMTP_PORT",
        "SMTP_SECURITY",
        "SMTP_USERNAME",
        "SMTP_PASSWORD",
        "SMTP_PASSWORD_FILE",
        "SMTP_FROM_EMAIL",
        "SMTP_FROM_NAME",
    ];
    $generic = false;
    foreach ($genericKeys as $key) {
        if (env_value($key) !== "") {
            $generic = true;
        }
    }
    $recipients =
        env_value("OFFICE_ALERT_EMAIL") !== "" ||
        env_value("PRIVACY_ALERT_EMAIL") !== "";
    if (!$generic && !$recipients) {
        return null;
    }
    $host = env_value("SMTP_HOST");
    $portText = env_value("SMTP_PORT") !== "" ? env_value("SMTP_PORT") : "587";
    $security = env_value("SMTP_SECURITY") !== "" ? env_value("SMTP_SECURITY") : "starttls";
    $user = env_value("SMTP_USERNAME");
    $password = integration_secret("SMTP_PASSWORD");
    $from = env_value("SMTP_FROM_EMAIL");
    $fromName = env_value("SMTP_FROM_NAME") ?: "Alpha School";
    // Only a hostname/IP, never a URL, credentials, path or SMTP command.
    if (
        !preg_match(
            "/\A(?=.{1,253}\z)[A-Za-z0-9](?:[A-Za-z0-9.-]*[A-Za-z0-9])?\z/D",
            $host,
        ) ||
        str_contains($host, "..")
    ) {
        throw new RuntimeException(
            "Set a valid SMTP_HOST hostname (not a URL).",
        );
    }
    if (
        !ctype_digit($portText) ||
        (int) $portText < 1 ||
        (int) $portText > 65535
    ) {
        throw new RuntimeException("SMTP_PORT must be between 1 and 65535.");
    }
    if (!in_array($security, ["tls", "starttls"], true)) {
        throw new RuntimeException(
            "SMTP_SECURITY must be tls (implicit TLS) or starttls. Plaintext SMTP is not supported.",
        );
    }
    if (
        !$user ||
        !$password ||
        strlen($user) > 320 ||
        preg_match('/[\x00-\x1f\x7f]/', $user . $password)
    ) {
        throw new RuntimeException(
            "Configure a valid SMTP username and password in hosting secrets.",
        );
    }
    if (
        mb_strlen($fromName) > 120 ||
        preg_match('/[\x00-\x1f\x7f]/', $fromName)
    ) {
        throw new RuntimeException(
            "SMTP_FROM_NAME must be a single-line display name.",
        );
    }
    $smtp = [
        "host" => $host,
        "port" => (int) $portText,
        "security" => $security,
        "user" => $user,
        "password" => $password,
        "from" => $from,
        "fromName" => $fromName,
        "officeEmail" => env_value("OFFICE_ALERT_EMAIL"),
        "privacyEmail" => env_value("PRIVACY_ALERT_EMAIL"),
    ];
    foreach (["from", "officeEmail", "privacyEmail"] as $key) {
        if (
            !filter_var($smtp[$key], FILTER_VALIDATE_EMAIL) ||
            preg_match('/[\r\n]/', $smtp[$key])
        ) {
            throw new RuntimeException(
                "Configure the verified sender and both approved email recipients.",
            );
        }
    }
    return $smtp;
}
function storage_configuration(): string
{
    // Do not silently open an existing installation with a removed storage backend.
    if (!in_array(env_value("STORAGE_DRIVER"), ["", "local"], true)) {
        throw new RuntimeException("Only local file storage is supported. Migrate existing files before changing storage settings.");
    }
    return "local";
}
