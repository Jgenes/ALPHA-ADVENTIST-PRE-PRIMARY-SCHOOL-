<?php
/**
 * Owner-only deployment diagnostics.
 *
 * The public website deliberately hides infrastructure failures behind a
 * generic "temporarily unavailable" page and the installer behind a generic
 * "setup could not finish" page, because those pages can be requested by
 * anyone. This module produces the missing detail for the hosting account
 * owner instead: it is read-only, it never prints a secret, and it never
 * creates, renames or deletes a school record.
 *
 * Nothing here is loaded by a normal website request except
 * diagnostic_record_failure(), which only appends one masked line to a
 * private file so the owner can see why the homepage failed.
 */
const DIAGNOSTIC_REQUIRED_EXTENSIONS = [
    "pdo_mysql",
    "openssl",
    "mbstring",
    "gd",
    "sodium",
    "fileinfo",
    "session",
];
const DIAGNOSTIC_RECOMMENDED_EXTENSIONS = ["exif", "ctype", "filter", "hash"];
const DIAGNOSTIC_MAX_MESSAGE = 400;
const DIAGNOSTIC_MAX_ENTRIES = 40;

/** Best-effort private directory. Diagnostics must work on a broken install. */
function diagnostic_private_directory(): ?string
{
    try {
        load_environment();
    } catch (Throwable) {
        // A broken config.php must not stop the page that explains it.
    }
    $configured = env_value("DATA_DIR");
    $candidates = $configured !== "" ? [$configured] : [];
    $candidates[] = application_root() . "/private";
    $candidates[] = sys_get_temp_dir() . "/alpha-school-diagnostics";
    foreach ($candidates as $candidate) {
        if (is_link($candidate)) {
            continue;
        }
        if (is_dir($candidate)) {
            $real = realpath($candidate);
            if ($real !== false && is_writable($real)) {
                return $real;
            }
            continue;
        }
        if (@mkdir($candidate, 0700, true) || is_dir($candidate)) {
            @chmod($candidate, 0700);
            $real = realpath($candidate);
            if ($real !== false && is_writable($real)) {
                return $real;
            }
        }
    }
    return null;
}

/** Remove credentials and token-shaped strings before anything is stored or shown. */
function diagnostic_mask(string $message): string
{
    $message = preg_replace("/[^\P{C}]+/u", " ", $message) ?? $message;
    $message = preg_replace("/\s+/", " ", $message) ?? $message;
    foreach (
        [
            "MYSQL_PASSWORD",
            "SMTP_PASSWORD",
            "SETUP_TOKEN",
            "DIAGNOSTIC_TOKEN",
            "STORAGE_ENCRYPTION_KEY",
            "AUDIT_HMAC_KEY",
            "ADMIN_PASSWORD",
            "HEAD_TEACHER_PASSWORD",
        ]
        as $name
    ) {
        $secret = env_value($name);
        if (strlen($secret) >= 4) {
            $message = str_replace($secret, "[redacted]", $message);
        }
    }
    $message = preg_replace(
        "~(password|passwd|pwd)([\"']?\\s*[:=]\\s*)[^\\s,;\"']+~i",
        "$1$2[redacted]",
        $message,
    );
    $message = preg_replace("~mysql://[^\\s\"']*~i", "mysql://[redacted]", $message);
    $message = preg_replace("~\b[a-f0-9]{32,}\b~i", "[redacted]", $message);
    $message = preg_replace("~\b[A-Za-z0-9+/]{40,}={0,2}\b~", "[redacted]", $message);
    return trim(mb_substr($message, 0, DIAGNOSTIC_MAX_MESSAGE));
}

/**
 * Append one masked failure line for the owner. Called from the website error
 * handler; it must never throw, never slow a response down and never write
 * inside the web root.
 */
function diagnostic_record_failure(
    string $route,
    int $status,
    Throwable $error,
): void {
    if ($status < 500) {
        return;
    }
    $dir = diagnostic_private_directory();
    if (!$dir) {
        return;
    }
    $path = $dir . "/diagnostic-log.json";
    if (is_link($path)) {
        return;
    }
    $handle = @fopen($path, "c+");
    if (!$handle) {
        return;
    }
    try {
        if (!flock($handle, LOCK_EX)) {
            return;
        }
        $entries = json_decode(stream_get_contents($handle) ?: "[]", true);
        if (!is_array($entries)) {
            $entries = [];
        }
        $entries[] = [
            "at" => gmdate("c"),
            "route" => mb_substr($route, 0, 120),
            "status" => $status,
            "type" => get_class($error),
            "where" =>
                basename($error->getFile()) . ":" . $error->getLine(),
            "detail" => diagnostic_mask($error->getMessage()),
        ];
        if (count($entries) > DIAGNOSTIC_MAX_ENTRIES) {
            $entries = array_slice($entries, -DIAGNOSTIC_MAX_ENTRIES);
        }
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode(array_values($entries), JSON_THROW_ON_ERROR));
        fflush($handle);
    } catch (Throwable) {
        // Diagnostics are never allowed to break the website itself.
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
        @chmod($path, 0600);
    }
}

/** @return array<int,array<string,mixed>> Most recent entries first. */
function diagnostic_recent_failures(int $limit = 10): array
{
    $dir = diagnostic_private_directory();
    if (!$dir) {
        return [];
    }
    $path = $dir . "/diagnostic-log.json";
    if (!is_file($path) || is_link($path)) {
        return [];
    }
    $entries = json_decode((string) @file_get_contents($path), true);
    if (!is_array($entries)) {
        return [];
    }
    return array_slice(array_reverse(array_values($entries)), 0, max(1, $limit));
}

function diagnostics_enabled(): bool
{
    return strlen(env_value("DIAGNOSTIC_TOKEN")) >= 32;
}

function diagnostic_token_matches(string $submitted): bool
{
    $expected = env_value("DIAGNOSTIC_TOKEN");
    return strlen($expected) >= 32 &&
        strlen($submitted) >= 32 &&
        strlen($submitted) <= 512 &&
        hash_equals($expected, $submitted);
}

/** Separate counter from the installer so testing one never locks the other. */
function diagnostic_attempt_allowed(string $dir, ?int $now = null): bool
{
    $now ??= time();
    $path = $dir . "/diagnostic-attempts.json";
    if (is_link($path)) {
        throw new RuntimeException("Invalid diagnostic state file.");
    }
    $handle = fopen($path, "c+");
    if (!$handle || !flock($handle, LOCK_EX)) {
        throw new RuntimeException("Cannot lock private diagnostic state.");
    }
    try {
        chmod($path, 0600);
        $state = json_decode(stream_get_contents($handle), true) ?: [];
        if (($state["until"] ?? 0) <= $now) {
            $state = ["count" => 0, "until" => $now + 900];
        }
        $allowed = $state["count"] < 10;
        if ($allowed) {
            $state["count"]++;
        }
        rewind($handle);
        ftruncate($handle, 0);
        fwrite($handle, json_encode($state, JSON_THROW_ON_ERROR));
        return $allowed;
    } finally {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}

/** Never echo a secret value; report only presence, shape and length. */
function diagnostic_setting(string $name, string $label): array
{
    $value = env_value($name);
    if ($value === "") {
        return [
            "status" => "empty",
            "detail" => "$label is empty in alpha-school/config.php.",
        ];
    }
    if (preg_match("/PASSWORD|TOKEN|SECRET|_KEY$/", $name)) {
        return ["status" => "set", "detail" => "$label is set (" . strlen($value) . " characters)."];
    }
    return ["status" => "set", "detail" => "$label is set to " . diagnostic_mask($value) . "."];
}

function diagnostic_pdo_options(array $mysql, string $mode): array
{
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    if ($mode !== "verify-full") {
        return $options;
    }
    $ca = env_value("MYSQL_TLS_CA_FILE", env_value("MYSQL_SSL_CA_FILE"));
    if (!$ca && env_value("MYSQL_SSL_CA")) {
        $ca = (env_value("DATA_DIR") ?: application_root() . "/private") . "/mysql-ca.pem";
    }
    $ca = $ca ?: "/etc/ssl/certs/ca-certificates.crt";
    if (!is_readable($ca)) {
        throw new RuntimeException(
            "Verified MySQL TLS needs a readable certificate authority file. Set MYSQL_TLS_CA_FILE in alpha-school/config.php (looked for " .
                diagnostic_mask($ca) .
                ").",
        );
    }
    $options[PDO::MYSQL_ATTR_SSL_CA] = $ca;
    $options[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
    return $options;
}

/** Translate the MySQL failures cPanel accounts actually hit into a fix. */
function diagnostic_database_hint(string $message): string
{
    $m = strtolower($message);
    if (str_contains($m, "certificate authority file")) {
        return "The TLS certificate file for verified MySQL could not be read. Either point MYSQL_TLS_CA_FILE at your provider's CA bundle, or — for the usual cPanel case where MySQL runs on the same server — set MYSQL_HOST to localhost and MYSQL_SSL_MODE to disabled.";
    }
    if (str_contains($m, "access denied")) {
        return "The database username or password is wrong, or that user was not added to this database. In cPanel open MySQL Databases, re-add the user to the database and choose ALL PRIVILEGES, then copy the exact prefixed names (for example account_alpha_school) into config.php.";
    }
    if (str_contains($m, "unknown database")) {
        return "The database name does not exist on this server. Create it with the cPanel Database Wizard and use the full prefixed name shown there in MYSQL_DB_NAME.";
    }
    if (
        str_contains($m, "connection refused") ||
        str_contains($m, "no such file or directory") ||
        str_contains($m, "server has gone away")
    ) {
        return "The server could not reach MySQL. On cPanel the host is normally localhost with port 3306. Ask your host if the database server uses a different hostname, port or socket.";
    }
    if (
        str_contains($m, "timed out") ||
        str_contains($m, "timeout")
    ) {
        return "The MySQL connection timed out. Confirm the hostname and port with your host and that remote MySQL access is enabled for this account if the server is not local.";
    }
    if (
        str_contains($m, "ssl") ||
        str_contains($m, "tls") ||
        str_contains($m, "certificate")
    ) {
        return "The connection failed during TLS negotiation. For cPanel MySQL on the same server set MYSQL_SSL_MODE to disabled in config.php; keep verify-full only for a remote managed database whose CA file you provide.";
    }
    if (str_contains($m, "too many connections")) {
        return "MySQL refused a new connection because the server is at its connection limit. Try again shortly and ask your host about the account connection limit.";
    }
    if (str_contains($m, "using password: yes")) {
        return "The password was rejected. Re-set the database user password in cPanel MySQL Databases and paste it exactly into MYSQL_PASSWORD, keeping any spaces or symbols.";
    }
    return "Review MYSQL_HOST, MYSQL_PORT, MYSQL_USER, MYSQL_PASSWORD and MYSQL_DB_NAME in alpha-school/config.php against the values shown in cPanel MySQL Databases.";
}

function diagnostic_table_exists(PDO $db, string $table): bool
{
    $query = $db->prepare(
        "SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME=?",
    );
    $query->execute([$table]);
    return (bool) $query->fetchColumn();
}

/**
 * Read-only report of every requirement the website and installer enforce.
 * Each stage returns partial results so one broken requirement never hides
 * the rest.
 */
function diagnostic_report(): array
{
    $results = [];
    $add = function (
        string $stage,
        string $name,
        string $status,
        string $detail,
        string $fix = "",
    ) use (&$results): void {
        $results[] = [
            "stage" => $stage,
            "name" => $name,
            "status" => $status,
            "detail" => $detail,
            "fix" => $fix,
        ];
    };
    $app = application_root();
    $public = public_root();

    // ── PHP runtime ────────────────────────────────────────────────────────
    $stage = "PHP runtime";
    $add(
        $stage,
        "PHP version",
        PHP_VERSION_ID >= 80200 ? "ok" : "fail",
        "This request ran PHP " . PHP_VERSION . " (" . PHP_SAPI . ").",
        PHP_VERSION_ID >= 80200
            ? ""
            : "In cPanel open MultiPHP Manager and select PHP 8.2 or newer for this domain.",
    );
    foreach (DIAGNOSTIC_REQUIRED_EXTENSIONS as $extension) {
        $loaded = extension_loaded($extension);
        $add(
            $stage,
            "Extension " . $extension,
            $loaded ? "ok" : "fail",
            $loaded ? "Loaded." : "Not loaded.",
            $loaded
                ? ""
                : "In cPanel open Select PHP Version → Extensions and enable " . $extension . ", then save.",
        );
    }
    $drivers = PDO::getAvailableDrivers();
    $add(
        $stage,
        "PDO MySQL driver",
        in_array("mysql", $drivers, true) ? "ok" : "fail",
        "Available PDO drivers: " . ($drivers ? implode(", ", $drivers) : "none") . ".",
        in_array("mysql", $drivers, true)
            ? ""
            : "Enable pdo_mysql (and php-mysqlnd) in cPanel Select PHP Version → Extensions.",
    );
    $missingRecommended = [];
    foreach (DIAGNOSTIC_RECOMMENDED_EXTENSIONS as $extension) {
        if (!extension_loaded($extension)) {
            $missingRecommended[] = $extension;
        }
    }
    $add(
        $stage,
        "Recommended extensions",
        $missingRecommended ? "warn" : "ok",
        $missingRecommended
            ? "Not loaded: " . implode(", ", $missingRecommended) . ". exif keeps photo orientation correct."
            : "All recommended extensions are loaded.",
    );

    // ── Folder layout ──────────────────────────────────────────────────────
    $stage = "Folder layout";
    $booted = is_file($app . "/src/php/bootstrap.php");
    $add(
        $stage,
        "Private application folder",
        $booted ? "ok" : "fail",
        $booted
            ? "alpha-school/src/php/bootstrap.php was found and loaded."
            : "alpha-school/src/php/bootstrap.php is missing.",
        $booted
            ? ""
            : "Upload the whole alpha-school folder into your account home directory, beside public_html (not inside it).",
    );
    if (!$booted) {
        return $results;
    }
    $add(
        $stage,
        "Public folder",
        is_dir($public) ? "ok" : "fail",
        "Resolved to " . diagnostic_mask($public) . ".",
        is_dir($public)
            ? ""
            : "public_html must sit beside alpha-school. Do not rename it or move the application inside it.",
    );
    $rewrite = is_file($public . "/.htaccess")
        ? (string) file_get_contents($public . "/.htaccess")
        : "";
    $add(
        $stage,
        "Public .htaccess",
        $rewrite !== "" ? "ok" : "warn",
        $rewrite !== ""
            ? "Present (" . strlen($rewrite) . " bytes)."
            : "public_html/.htaccess is missing.",
        $rewrite !== ""
            ? ""
            : "Re-upload public_html/.htaccess including hidden files. Without it every route except the homepage returns 404.",
    );
    if ($rewrite !== "" && !preg_match("/(?:index\|setup\|diagnose)/", $rewrite)) {
        $add(
            $stage,
            ".htaccess rewrite allowlist",
            "warn",
            "The installed .htaccess does not list diagnose.php, so /diagnose.php may be rewritten to the homepage.",
            "Update the rule to: RewriteRule ^(?:index|setup|diagnose)\\.php$ - [L]",
        );
    }
    $leaked = [];
    foreach (["config.php", ".env", "alpha-school", "src", "private"] as $name) {
        if (file_exists($public . "/" . $name)) {
            $leaked[] = $name;
        }
    }
    $add(
        $stage,
        "Nothing private inside public_html",
        $leaked ? "fail" : "ok",
        $leaked
            ? "Found in the web root: " . implode(", ", $leaked) . "."
            : "No private file or folder was found in the web root.",
        $leaked
            ? "Move those items out of public_html. Source code, config.php and private uploads must never be web-readable."
            : "",
    );

    // ── Private configuration ──────────────────────────────────────────────
    $stage = "Private configuration";
    $configFile = $app . "/config.php";
    $values = null;
    $haveConfig = is_file($configFile);
    if ($haveConfig) {
        try {
            $values = require $configFile;
        } catch (Throwable $error) {
            $add(
                $stage,
                "config.php is readable",
                "fail",
                "PHP could not read config.php: " . get_class($error) . " — " . diagnostic_mask($error->getMessage()),
                "Fix the PHP syntax. Every line needs a quoted key, a value and a trailing comma: 'MYSQL_USER' => 'account_user',",
            );
        }
    }
    if ($values !== null && !is_array($values)) {
        $add(
            $stage,
            "config.php returns settings",
            "fail",
            "config.php returned " . gettype($values) . " instead of an array.",
            "The file must start with <?php and use: return [ ... ];",
        );
        $values = null;
    }
    if (!$haveConfig) {
        $add(
            $stage,
            "config.php exists",
            is_file($app . "/.env") ? "warn" : "fail",
            is_file($app . "/.env")
                ? "config.php is missing but a legacy .env was found, so it is being used."
                : "alpha-school/config.php does not exist.",
            is_file($app . "/.env")
                ? "Copy every real setting from .env into a new config.php. PHP configuration takes precedence and the two files are not combined."
                : "Copy alpha-school/config.example.php to alpha-school/config.php and fill it in. This is the only settings file a new installation needs.",
        );
    } else {
        $perms = fileperms($configFile) & 0777;
        $add(
            $stage,
            "config.php permissions",
            $perms & 0077 ? "warn" : "ok",
            "Permissions are " . substr(sprintf("%04o", $perms), -4) . ".",
            $perms & 0077
                ? "Run chmod 600 alpha-school/config.php. Group and world readable settings can expose database and SMTP passwords."
                : "",
        );
        $add(
            $stage,
            "config.php returns settings",
            "ok",
            is_array($values) ? count($values) . " settings were read." : "",
        );
    }

    $environment = "";
    $production = false;
    $config = null;
    try {
        load_environment();
        $environment = env_value("APP_ENV", "development");
        $production = in_array($environment, ["production", "staging"], true);
        $add(
            $stage,
            "APP_ENV",
            "ok",
            "Set to " . $environment . ($production ? " (strict production rules apply)." : " (development rules apply)."),
        );
    } catch (Throwable $error) {
        $add(
            $stage,
            "Load private settings",
            "fail",
            diagnostic_mask($error->getMessage()),
            "Correct config.php so it returns a flat array of scalar settings.",
        );
    }
    if ($environment === "") {
        $add(
            $stage,
            "APP_ENV",
            "fail",
            "APP_ENV is empty.",
            "Set 'APP_ENV' => 'production' in alpha-school/config.php for a live school website.",
        );
    }

    $base = env_value("BASE_URL");
    $baseParts = $base !== "" ? parse_url($base) : false;
    $scheme = is_array($baseParts) ? ($baseParts["scheme"] ?? "") : "";
    $baseHost = is_array($baseParts) ? ($baseParts["host"] ?? "") : "";
    $basePath = is_array($baseParts) ? ($baseParts["path"] ?? "") : "";
    $baseIsOrigin = is_array($baseParts) &&
        in_array($scheme, ["http", "https"], true) &&
        $baseHost !== "" &&
        in_array($basePath, ["", "/"], true) &&
        !isset($baseParts["query"]) &&
        !isset($baseParts["fragment"]) &&
        !isset($baseParts["user"]) &&
        !isset($baseParts["pass"]);
    $baseProblem = $base === ""
        ? "BASE_URL is empty, so the application falls back to a demo address it is not allowed to use in production."
        : ($baseIsOrigin
            ? ""
            : "BASE_URL is not a bare origin. A path, query, fragment, username or missing scheme makes the application refuse to start.");
    $add(
        $stage,
        "BASE_URL",
        $baseProblem !== "" ? "fail" : ($production && $scheme !== "https" ? "fail" : "ok"),
        $base === ""
            ? $baseProblem
            : "Set to " . diagnostic_mask($base) . ($baseProblem !== "" ? " — " . $baseProblem : "") . ".",
        $baseProblem !== "" || ($production && $scheme !== "https")
            ? "Set 'BASE_URL' => 'https://your-domain' in alpha-school/config.php — the scheme and host only, with no path, query or trailing slash. Production also requires SSL/TLS to be enabled in cPanel so the address really is https."
            : "",
    );

    $dataDir = env_value("DATA_DIR");
    if ($dataDir === "") {
        $add(
            $stage,
            "DATA_DIR",
            $production ? "fail" : "warn",
            "DATA_DIR is empty.",
            $production
                ? "Production requires a persistent private path. Use 'DATA_DIR' => __DIR__ . '/private'."
                : "Set 'DATA_DIR' => __DIR__ . '/private' so encrypted uploads and keys survive updates.",
        );
    } else {
        $real = realpath($dataDir);
        $publicReal = realpath($public);
        $inside = $publicReal !== false &&
            $real !== false &&
            ($real === $publicReal || str_starts_with($real, $publicReal . "/"));
        $add(
            $stage,
            "DATA_DIR",
            $real === false ? ($production ? "fail" : "warn") : ($inside ? "fail" : (is_writable($real) ? "ok" : "fail")),
            $real === false
                ? "Set to " . diagnostic_mask($dataDir) . ", which does not exist and could not be created."
                : "Resolved to " . diagnostic_mask($real) . " (" . (is_writable($real) ? "writable" : "not writable") . ").",
            $inside
                ? "DATA_DIR must be outside public_html. Encrypted school records must never be web-readable."
                : ($real === false || !is_writable($real)
                    ? "Create the folder and make it writable by your cPanel account: mkdir alpha-school/private and chmod 700 alpha-school/private. Never use 777."
                    : ""),
        );
    }

    $setupToken = env_value("SETUP_TOKEN");
    $add(
        $stage,
        "SETUP_TOKEN",
        strlen($setupToken) >= 32 ? "ok" : ($setupToken === "" ? "fail" : "warn"),
        $setupToken === ""
            ? "SETUP_TOKEN is empty, which disables /setup.php with the message you saw."
            : (strlen($setupToken) >= 32
                ? "Set (" . strlen($setupToken) . " characters)."
                : "SETUP_TOKEN is only " . strlen($setupToken) . " characters; at least 32 are required."),
        strlen($setupToken) >= 32
            ? "Clear it again and delete public_html/setup.php once installation is complete."
            : "Generate a random token of at least 32 characters with the cPanel password generator and set 'SETUP_TOKEN' => 'that-value' in alpha-school/config.php. Then reload /setup.php.",
    );
    $diagnosticToken = env_value("DIAGNOSTIC_TOKEN");
    $add(
        $stage,
        "DIAGNOSTIC_TOKEN",
        strlen($diagnosticToken) >= 32 ? "ok" : "info",
        strlen($diagnosticToken) >= 32
            ? "Set (" . strlen($diagnosticToken) . " characters); this page stays available while you work."
            : "Not configured. This page was unlocked another way and will lock again when you leave it.",
        strlen($diagnosticToken) >= 32
            ? ""
            : "Add 'DIAGNOSTIC_TOKEN' => 'a-random-value-of-32-characters-or-more' to config.php, then delete public_html/diagnose.php when the site is healthy.",
    );
    foreach (
        [
            "STORAGE_ENCRYPTION_KEY" => "Storage encryption key",
            "AUDIT_HMAC_KEY" => "Audit signing key",
        ]
        as $name => $label
    ) {
        $value = env_value($name);
        $add(
            $stage,
            $label,
            $value === "" ? "info" : (preg_match("/^[a-f0-9]{64}$/i", $value) ? "ok" : "fail"),
            $value === ""
                ? "Empty in config.php, so it is generated once and stored privately in DATA_DIR."
                : (preg_match("/^[a-f0-9]{64}$/i", $value)
                    ? "Set to a valid 64-character hex value."
                    : "Set, but it is not exactly 64 hexadecimal characters."),
            preg_match("/^[a-f0-9]{64}$/i", $value) || $value === ""
                ? ""
                : "Either leave it empty for a new installation or paste a 64-character hex string. An existing installation must keep its original keys.",
        );
    }
    $storageKey = env_value("STORAGE_ENCRYPTION_KEY");
    $auditKey = env_value("AUDIT_HMAC_KEY");
    if (
        $storageKey !== "" &&
        $auditKey !== "" &&
        strtolower($storageKey) === strtolower($auditKey)
    ) {
        $add(
            $stage,
            "Keys must differ",
            "fail",
            "STORAGE_ENCRYPTION_KEY and AUDIT_HMAC_KEY hold the same value.",
            "Use two different random keys, or leave both empty so the installer generates them.",
        );
    }

    // The strict production gates are the usual reason a fresh cPanel upload
    // shows "temporarily unavailable" on every page.
    $retentionApproved = env_value("RETENTION_POLICY_APPROVED");
    $formDays = env_value("FORM_RETENTION_DAYS");
    $admissionDays = env_value("ADMISSION_RETENTION_DAYS");
    $add(
        $stage,
        "Retention approval",
        $production && $retentionApproved !== "true" ? "fail" : "ok",
        "RETENTION_POLICY_APPROVED is " . ($retentionApproved === "" ? "empty" : diagnostic_mask($retentionApproved)) . ".",
        $production && $retentionApproved !== "true"
            ? "Production refuses to run until the school has approved retention. Set 'RETENTION_POLICY_APPROVED' => true in config.php."
            : "",
    );
    foreach (
        [
            "FORM_RETENTION_DAYS" => $formDays,
            "ADMISSION_RETENTION_DAYS" => $admissionDays,
        ]
        as $name => $value
    ) {
        $valid = ctype_digit((string) $value) && (int) $value >= 1 && (int) $value <= 3650;
        $add(
            $stage,
            $name,
            $production && !$valid ? "fail" : ($valid ? "ok" : "warn"),
            $value === "" ? "Empty." : "Set to " . diagnostic_mask($value) . ".",
            $valid
                ? ""
                : "Enter the school-approved whole number of days (1–3650), for example 'FORM_RETENTION_DAYS' => 90 and 'ADMISSION_RETENTION_DAYS' => 365.",
        );
    }

    $smtp = null;
    $smtpError = "";
    try {
        $smtp = smtp_configuration();
    } catch (Throwable $error) {
        $smtpError = diagnostic_mask($error->getMessage());
    }
    $add(
        $stage,
        "SMTP settings",
        $smtpError !== "" ? "fail" : ($smtp === null ? ($production ? "fail" : "info") : "ok"),
        $smtpError !== ""
            ? $smtpError
            : ($smtp === null
                ? "No SMTP settings are configured."
                : "Configured for " . diagnostic_mask($smtp["host"]) . ":" . $smtp["port"] . " using " . $smtp["security"] . "."),
        $smtpError !== ""
            ? "SMTP must be complete or completely empty. Fill in SMTP_HOST, SMTP_PORT, SMTP_SECURITY, SMTP_USERNAME, SMTP_PASSWORD, SMTP_FROM_EMAIL, OFFICE_ALERT_EMAIL and PRIVACY_ALERT_EMAIL."
            : ($smtp === null && $production
                ? "Production requires authenticated SMTP for office and privacy alerts. Add your provider's real settings and both approved recipient addresses."
                : ""),
    );

    try {
        $config = configuration();
        $add(
            $stage,
            "Full configuration accepted",
            "ok",
            "configuration() completed, so the website can start. Database: " .
                diagnostic_mask((string) $config["mysql"]["database"]) . ".",
        );
    } catch (Throwable $error) {
        $config = null;
        $add(
            $stage,
            "Full configuration accepted",
            "fail",
            get_class($error) . " — " . diagnostic_mask($error->getMessage()),
            "This exact error is what produces the generic 'temporarily unavailable' page. Fix the setting named above and reload the homepage.",
        );
    }

    // ── MySQL ──────────────────────────────────────────────────────────────
    $stage = "MySQL";
    $mysql = $config["mysql"] ?? null;
    $db = null;
    if (!is_array($mysql) || !$mysql) {
        $add(
            $stage,
            "MySQL settings",
            "fail",
            $config
                ? "The accepted configuration produced no MySQL settings."
                : "MySQL was not tested because the configuration above was refused first. Nothing was sent to the database server.",
            $config
                ? "Set MYSQL_HOST, MYSQL_PORT, MYSQL_DB_NAME, MYSQL_USER and MYSQL_PASSWORD in alpha-school/config.php using the exact prefixed names from cPanel MySQL Databases."
                : "Fix the configuration failures listed above first, then run the checks again — MySQL is tested as soon as the configuration is accepted.",
        );
    } else {
        $missing = [];
        foreach (
            ["host", "user", "database"]
            as $field
        ) {
            if ((string) $mysql[$field] === "") {
                $missing[] = "MYSQL_" . strtoupper($field === "database" ? "DB_NAME" : $field);
            }
        }
        if ((string) $mysql["password"] === "" && env_value("MYSQL_PASSWORD_FILE") === "") {
            $missing[] = "MYSQL_PASSWORD";
        }
        $add(
            $stage,
            "MySQL settings",
            $missing ? "fail" : "ok",
            $missing
                ? "Empty: " . implode(", ", $missing) . "."
                : "Host " . diagnostic_mask((string) $mysql["host"]) . ":" . (int) $mysql["port"] .
                    ", database " . diagnostic_mask((string) $mysql["database"]) .
                    ", user " . diagnostic_mask((string) $mysql["user"]) . ".",
            $missing
                ? "Fill in " . implode(", ", $missing) . " in alpha-school/config.php."
                : "",
        );
        $mode = "";
        try {
            $mode = mysql_ssl_mode($config);
            $add(
                $stage,
                "MySQL TLS mode",
                "ok",
                "Using " . $mode . " for this connection.",
            );
        } catch (Throwable $error) {
            $add(
                $stage,
                "MySQL TLS mode",
                "fail",
                diagnostic_mask($error->getMessage()),
                "On cPanel, MySQL is normally on the same server: set 'MYSQL_SSL_MODE' => 'disabled'. Keep verify-full only for a remote managed database.",
            );
        }
        if ($mode !== "") {
            try {
                $db = new PDO(
                    "mysql:host=" . $mysql["host"] . ";port=" . (int) $mysql["port"] .
                        ";dbname=" . $mysql["database"] . ";charset=utf8mb4",
                    (string) $mysql["user"],
                    (string) $mysql["password"],
                    diagnostic_pdo_options($mysql, $mode),
                );
                $add($stage, "Connect to MySQL", "ok", "Connected and selected the database.");
            } catch (Throwable $error) {
                $message = diagnostic_mask($error->getMessage());
                $add(
                    $stage,
                    "Connect to MySQL",
                    "fail",
                    $message,
                    diagnostic_database_hint($message),
                );
            }
        }
    }

    if ($db instanceof PDO) {
        $serverVersion = (string) $db->getAttribute(PDO::ATTR_SERVER_VERSION);
        $isMaria = stripos($serverVersion, "maria") !== false;
        $isNumericVersion = (bool) preg_match("/^\d+\.\d+/", $serverVersion);
        $add(
            $stage,
            "Server version",
            $isMaria ? "warn" : ($isNumericVersion ? "ok" : "warn"),
            "The server reports " . diagnostic_mask($serverVersion) . ".",
            $isMaria
                ? "This looks like MariaDB. The schema uses MySQL 8 JSON generated-column indexes and triggers and MariaDB compatibility has not been verified. Ask your host to move the account to MySQL 8.0 or newer."
                : ($isNumericVersion
                    ? ""
                    : "MySQL 8.0 or newer is required for JSON generated columns and the audit triggers."),
        );
        try {
            $tables = $db
                ->query("SELECT TABLE_NAME FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE()")
                ->fetchAll(PDO::FETCH_COLUMN);
            $triggers = $db
                ->query("SELECT TRIGGER_NAME FROM information_schema.TRIGGERS WHERE TRIGGER_SCHEMA=DATABASE()")
                ->fetchAll(PDO::FETCH_COLUMN);
            $expected = array_map(fn($name) => "adsp_" . $name, COLLECTIONS);
            $missingTables = array_values(array_diff($expected, is_array($tables) ? $tables : []));
            $extra = array_values(array_filter(
                is_array($tables) ? $tables : [],
                fn($name) => str_starts_with((string) $name, "adsp_") && !in_array($name, $expected, true),
            ));
            $add(
                $stage,
                "Application tables",
                $missingTables ? ($tables ? "warn" : "fail") : "ok",
                $missingTables
                    ? count($missingTables) . " of " . count($expected) . " application tables are missing" .
                        (count($missingTables) <= 8 ? ": " . implode(", ", $missingTables) : "") . "."
                    : "All " . count($expected) . " adsp_* tables exist." . ($extra ? " Unexpected extra tables: " . implode(", ", $extra) . "." : ""),
                $missingTables
                    ? "The tables are created automatically on the first successful request, so this normally means the earlier connection or privilege checks failed. Fix those first; if the database was created by an older installation, restore its backup instead."
                    : "",
            );
            $missingTriggers = array_values(array_diff(
                ["adsp_audit_no_update", "adsp_audit_no_delete"],
                is_array($triggers) ? $triggers : [],
            ));
            $add(
                $stage,
                "Append-only audit triggers",
                $missingTriggers ? "warn" : "ok",
                $missingTriggers
                    ? "Missing: " . implode(", ", $missingTriggers) . "."
                    : "Both audit triggers exist.",
                $missingTriggers
                    ? "They are created with the tables on the first successful request. If creation keeps failing, ask your host to grant the TRIGGER privilege on this database."
                    : "",
            );
        } catch (Throwable $error) {
            $add(
                $stage,
                "Read schema metadata",
                "fail",
                diagnostic_mask($error->getMessage()),
                "The account needs SELECT access to information_schema for this database, which cPanel's ALL PRIVILEGES grant includes.",
            );
        }
        try {
            $db->beginTransaction();
            $locked = (int) $db
                ->query("SELECT GET_LOCK(SHA2(CONCAT(DATABASE(), ':adsp-transaction'), 256), 10)")
                ->fetchColumn() === 1;
            $db->rollBack();
            if ($locked) {
                $db->query("SELECT RELEASE_LOCK(SHA2(CONCAT(DATABASE(), ':adsp-transaction'), 256))");
            }
            $add(
                $stage,
                "Write transactions and locking",
                $locked ? "ok" : "warn",
                $locked
                    ? "A transaction and the application write lock both succeeded (rolled back, nothing was written)."
                    : "GET_LOCK did not succeed within 10 seconds.",
                $locked
                    ? ""
                    : "Another long-running process is holding the application lock. Wait a minute and re-run, and check the maintenance cron job is not stuck.",
            );
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                try {
                    $db->rollBack();
                } catch (Throwable) {
                }
            }
            $add(
                $stage,
                "Write transactions and locking",
                "fail",
                diagnostic_mask($error->getMessage()),
                "The database account must be allowed to start transactions and use GET_LOCK. In cPanel MySQL Databases re-add the user with ALL PRIVILEGES on this database.",
            );
        }
        try {
            $probe = "adsp_diagnostic_probe_" . substr(bin2hex(random_bytes(4)), 0, 8);
            if (diagnostic_table_exists($db, "adsp_audit_logs")) {
                try {
                    $db->exec("CREATE TRIGGER `$probe` BEFORE INSERT ON adsp_audit_logs FOR EACH ROW SET @a=1");
                    $db->exec("DROP TRIGGER IF EXISTS `$probe`");
                    $add($stage, "Create triggers", "ok", "A probe trigger was created and dropped again.");
                } catch (Throwable $error) {
                    try {
                        $db->exec("DROP TRIGGER IF EXISTS `$probe`");
                    } catch (Throwable) {
                    }
                    $add(
                        $stage,
                        "Create triggers",
                        "fail",
                        diagnostic_mask($error->getMessage()),
                        "Ask your host to grant the TRIGGER privilege (cPanel's ALL PRIVILEGES includes it). Without it the audit triggers cannot be created and every page fails.",
                    );
                }
            } else {
                $add(
                    $stage,
                    "Create triggers",
                    "info",
                    "Not tested because adsp_audit_logs does not exist yet.",
                    "Fix the failures above; the tables and audit triggers are created on the first successful request.",
                );
            }
        } catch (Throwable $error) {
            $add(
                $stage,
                "Create triggers",
                "info",
                "Could not test trigger creation: " . diagnostic_mask($error->getMessage()),
            );
        }
        try {
            $accounts = (int) $db->query("SELECT COUNT(*) FROM adsp_users")->fetchColumn();
            $add(
                $stage,
                "Portal accounts",
                $accounts > 0 ? "ok" : "warn",
                $accounts > 0 ? $accounts . " account record(s) exist." : "adsp_users is empty, so nobody can sign in yet.",
                $accounts > 0
                    ? ""
                    : "Create the system administrator and head teacher with /setup.php once every check above passes.",
            );
        } catch (Throwable $error) {
            $add(
                $stage,
                "Portal accounts",
                "info",
                "Could not count accounts: " . diagnostic_mask($error->getMessage()),
            );
        }
    }

    // ── Encrypted storage ──────────────────────────────────────────────────
    $stage = "Encrypted storage";
    if ($config) {
        $file = null;
        try {
            $files = new Files($config);
            $files->init();
            $secret = "Alpha diagnostic " . bin2hex(random_bytes(8));
            $file = $files->prepare([
                "name" => "diagnostic.txt",
                "content" => base64_encode($secret),
            ]);
            $roundTrip = hash_equals($secret, $files->read($file));
            $raw = $files->readStored("private", $file["storageKey"]);
            $add(
                $stage,
                "Encrypt, store and read back",
                $roundTrip && !str_contains($raw, $secret) ? "ok" : "fail",
                $roundTrip
                    ? ($raw !== "" && !str_contains($raw, $secret)
                        ? "A test file was written, decrypted and verified, then deleted. The stored bytes are encrypted."
                        : "A test file round-tripped but the stored bytes were not provably encrypted.")
                    : "The decrypted content did not match what was written.",
                $roundTrip && !str_contains($raw, $secret)
                    ? ""
                    : "DATA_DIR must be private, writable and stable. If STORAGE_ENCRYPTION_KEY changed after files were written, restore the original key.",
            );
        } catch (Throwable $error) {
            $add(
                $stage,
                "Encrypt, store and read back",
                "fail",
                get_class($error) . " — " . diagnostic_mask($error->getMessage()),
                "Check that DATA_DIR exists, is outside public_html, is writable by your cPanel account and is chmod 700 (never 777).",
            );
        } finally {
            if ($file && isset($files)) {
                try {
                    $files->discard($file);
                } catch (Throwable) {
                }
            }
        }
    } else {
        $add($stage, "Encrypt, store and read back", "info", "Skipped because the configuration is not valid yet.");
    }

    // ── Email ──────────────────────────────────────────────────────────────
    $stage = "Email delivery";
    if ($smtp) {
        $socket = null;
        try {
            $context = stream_context_create([
                "ssl" => [
                    "verify_peer" => true,
                    "verify_peer_name" => true,
                    "peer_name" => $smtp["host"],
                    "allow_self_signed" => false,
                    "disable_compression" => true,
                ],
            ]);
            $scheme = $smtp["security"] === "tls" ? "tls" : "tcp";
            $started = microtime(true);
            $socket = @stream_socket_client(
                $scheme . "://" . $smtp["host"] . ":" . $smtp["port"],
                $errno,
                $errstr,
                8,
                STREAM_CLIENT_CONNECT,
                $context,
            );
            if (!$socket) {
                throw new RuntimeException(
                    "Could not open a verified connection to " . $smtp["host"] . ":" . $smtp["port"] .
                        " (" . $errno . ": " . ($errstr ?: "no detail") . ").",
                );
            }
            $add(
                $stage,
                "Reach the SMTP server",
                "ok",
                "Connected in " . round(microtime(true) - $started, 2) . "s. No message was sent and no credentials were transmitted.",
            );
        } catch (Throwable $error) {
            $message = diagnostic_mask($error->getMessage());
            $add(
                $stage,
                "Reach the SMTP server",
                "fail",
                $message,
                str_contains(strtolower($message), "timed out") || str_contains(strtolower($message), "refused")
                    ? "Many shared hosts block outbound ports 25, 465 and 587 or require their own mail host. Ask your host which SMTP host and port are permitted, or switch to the SMTP account provided by your mail host."
                    : "Use the exact SMTP host, port and security mode from your provider. SMTP_SECURITY must be starttls (port 587) or tls (port 465); plaintext is not supported.",
            );
        } finally {
            if ($socket) {
                fclose($socket);
            }
        }
        foreach (
            ["from" => "SMTP_FROM_EMAIL", "officeEmail" => "OFFICE_ALERT_EMAIL", "privacyEmail" => "PRIVACY_ALERT_EMAIL"]
            as $field => $name
        ) {
            $value = (string) ($smtp[$field] ?? "");
            $valid = filter_var($value, FILTER_VALIDATE_EMAIL) !== false;
            $add(
                $stage,
                $name,
                $valid ? "ok" : "fail",
                $valid ? "Set to " . diagnostic_mask($value) . "." : "Empty or not a valid email address.",
                $valid ? "" : "Enter a verified address that belongs to your domain. Alerts are refused without all three addresses.",
            );
        }
        $add(
            $stage,
            "Send a real test message",
            "info",
            "Not attempted here, so no mail is sent by this page.",
            "From the cPanel Terminal run: php alpha-school/scripts/verify-integrations.php --check=email --send-test=office",
        );
    } else {
        $add($stage, "SMTP", "info", "Skipped because no complete SMTP configuration was found.");
    }

    // ── Installation state ─────────────────────────────────────────────────
    $stage = "Installation state";
    $privateDir = diagnostic_private_directory() ?: $app;
    $setupLock = diagnostic_lock_file();
    $locked = $setupLock !== "" && is_file($setupLock);
    $add(
        $stage,
        "First installation",
        $locked ? "ok" : "info",
        $locked
            ? "setup-complete.lock exists, so the installer has already finished and will not run again."
            : "No completion lock was found, so the first installation has not finished.",
        $locked
            ? ""
            : "Once every failure above is fixed, open /setup.php with a 32+ character SETUP_TOKEN and create both approved accounts.",
    );
    $add(
        $stage,
        "setup.php still uploaded",
        is_file($public . "/setup.php") ? ($locked ? "warn" : "info") : "ok",
        is_file($public . "/setup.php")
            ? "public_html/setup.php is present."
            : "public_html/setup.php is not present.",
        is_file($public . "/setup.php") && $locked
            ? "Installation is complete, so delete public_html/setup.php and clear SETUP_TOKEN in config.php."
            : "",
    );

    // ── Recent failures ────────────────────────────────────────────────────
    $stage = "Recorded failures";
    $entries = diagnostic_recent_failures(10);
    $lines = [];
    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $lines[] =
            (string) ($entry["at"] ?? "") . " · " .
            (string) ($entry["route"] ?? "") . " · " .
            (int) ($entry["status"] ?? 0) . " · " .
            (string) ($entry["type"] ?? "") . " · " .
            (string) ($entry["where"] ?? "") . " · " .
            (string) ($entry["detail"] ?? "");
    }
    $add(
        $stage,
        "Failures recorded by the website",
        $lines ? "warn" : "ok",
        $lines ? implode("\n", $lines) : "No failure has been recorded since the log was last cleared.",
        $lines
            ? "Each line is one masked error from a real request, newest first. The full history is in " . diagnostic_mask($privateDir) . "/diagnostic-log.json."
            : "",
    );
    $logFile = ini_get("error_log");
    $serverLog = getenv("ERROR_LOG");
    $tail = "";
    foreach ([$logFile, $serverLog, dirname($public) . "/logs/" . ($_SERVER["HTTP_HOST"] ?? "") . ".log"] as $candidate) {
        if (!is_string($candidate) || $candidate === "" || !is_file($candidate) || !is_readable($candidate)) {
            continue;
        }
        if ((int) @filesize($candidate) > 2097152) {
            continue;
        }
        $raw = (string) @file_get_contents($candidate);
        $pieces = array_slice(array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== "")), -6);
        $tail = implode("\n", array_map(fn($l) => diagnostic_mask($l), $pieces));
        if ($tail !== "") {
            $tail = "Read from " . diagnostic_mask($candidate) . ":\n" . $tail;
        }
        break;
    }
    $add(
        $stage,
        "PHP error log",
        $tail !== "" ? "warn" : "info",
        $tail !== ""
            ? $tail
            : "PHP's error_log is " . ($logFile !== "" ? diagnostic_mask($logFile) : "not set for this request") .
                ", and it is not readable through PHP. In cPanel open Metrics → Errors, or read errors.log in your account home directory. Look for lines beginning with [alpha].",
    );
    $add(
        $stage,
        "Maintenance cron job",
        "info",
        "The one-minute maintenance job cannot be verified from a web request.",
        "In cPanel → Cron Jobs add: * * * * * /usr/local/bin/php " . diagnostic_mask($app) . "/scripts/maintenance.php",
    );
    return $results;
}

function diagnostic_lock_file(): string
{
    $dir = env_value("DATA_DIR");
    if ($dir === "") {
        try {
            load_environment();
            $dir = env_value("DATA_DIR");
        } catch (Throwable) {
            $dir = "";
        }
    }
    if ($dir === "") {
        return "";
    }
    return rtrim($dir, "/") . "/setup-complete.lock";
}
