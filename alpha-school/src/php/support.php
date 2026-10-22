<?php
const LIVE_URL = "https://alpha-adventist-pre-primary-school.onrender.com";
class HttpError extends RuntimeException
{
    public function __construct(
        public int $status,
        string $message,
        public string $errorCode = "REQUEST_FAILED",
    ) {
        parent::__construct($message);
    }
}
function esc($v): string
{
    return str_replace(
        "&#039;",
        "&#39;",
        htmlspecialchars(js_string($v), ENT_QUOTES | ENT_SUBSTITUTE, "UTF-8"),
    );
}
function paragraphs($v): string
{
    return implode(
        "\n",
        array_map(fn($p) => "<p>" . esc($p) . "</p>", is_array($v) ? $v : []),
    );
}
function text($v = null, $max = 2000, $required = false): string
{
    if ($v !== null && !is_string($v)) {
        throw new HttpError(400, "Expected a text field.");
    }
    $v = trim(preg_replace('/[\x00-\x08\x0b\x0c\x0e-\x1f]/', "", $v ?? ""));
    if (($required && $v === "") || mb_strlen($v) > $max) {
        throw new HttpError(
            400,
            "Enter a value between " .
                ($required ? 1 : 0) .
                " and $max characters.",
        );
    }
    return $v;
}
function rid($prefix = ""): string
{
    $b = random_bytes(16);
    $b[6] = chr((ord($b[6]) & 15) | 64);
    $b[8] = chr((ord($b[8]) & 63) | 128);
    $h = bin2hex($b);
    return $prefix .
        substr($h, 0, 8) .
        "-" .
        substr($h, 8, 4) .
        "-" .
        substr($h, 12, 4) .
        "-" .
        substr($h, 16, 4) .
        "-" .
        substr($h, 20);
}
function sha256($v): string
{
    return hash("sha256", js_string($v));
}
function a_lib_slugify($v): string
{
    return substr(
        trim(preg_replace("/[^a-z0-9]+/", "-", strtolower($v)), "-"),
        0,
        80,
    );
}
function strong_password($v): bool
{
    return is_string($v) &&
        strlen($v) >= 12 &&
        strlen($v) <= 128 &&
        preg_match("/[a-z]/", $v) &&
        preg_match("/[A-Z]/", $v) &&
        preg_match("/[0-9]/", $v) &&
        preg_match("/[^A-Za-z0-9]/", $v);
}
function password_record($v): array
{
    return [
        "hash" => password_hash(
            base64_encode(hash("sha256", $v, true)),
            PASSWORD_BCRYPT,
            ["cost" => 12],
        ),
        "salt" => "",
        "algorithm" => "bcrypt-sha256",
    ];
}
function verify_password(array $u, string $v): bool
{
    if (strlen($v) > 128) {
        return false;
    }
    if (str_starts_with($u["hash"] ?? "", '$')) {
        return password_verify(
            ($u["algorithm"] ?? "") === "bcrypt-sha256"
                ? base64_encode(hash("sha256", $v, true))
                : $v,
            $u["hash"],
        );
    }
    if (($u["algorithm"] ?? "") === "scrypt-N32768-r8-p1") {
        if (!function_exists("sodium_crypto_pwhash_scryptsalsa208sha256")) {
            throw new HttpError(
                503,
                "Legacy sign-in requires the PHP sodium extension. Contact the operator.",
            );
        }
        $salt = $u["salt"] ?? "";
        if (strlen($salt) !== 32) {
            return false;
        }
        return hash_equals(
            $u["hash"],
            bin2hex(
                sodium_crypto_pwhash_scryptsalsa208sha256(
                    64,
                    $v,
                    $salt,
                    1048576,
                    33554432,
                ),
            ),
        );
    }
    return false;
}
function env_value($key, $default = "")
{
    $v = getenv($key);
    return $v === false ? $default : $v;
}
function application_root(): string
{
    return dirname(__DIR__, 2);
}
function public_root(): string
{
    return dirname(application_root()) . "/public_html";
}
/** Preserve literal PHP strings, including spaces/quotes in passwords. */
function apply_private_settings(array $values): void
{
    foreach ($values as $key => $value) {
        if (!is_string($key) || !preg_match('/^[A-Z][A-Z0-9_]*$/D', $key) || !is_scalar($value)) {
            throw new RuntimeException("Invalid setting in private config.php.");
        }
        if (getenv($key) === false) {
            putenv($key . "=" . (is_bool($value) ? ($value ? "true" : "false") : (string) $value));
        }
    }
}
/** Environment overrides are for operators/tests; cPanel uses just config.php. */
function load_environment(): void
{
    $file = application_root() . "/config.php";
    if (is_file($file)) {
        $values = require $file;
        if (!is_array($values)) {
            throw new RuntimeException("Private config.php must return a settings array.");
        }
        apply_private_settings($values);
        return;
    }
    // Backward-compatible upgrade path. New installations need only config.php.
    $f = application_root() . "/.env";
    if (!is_file($f)) {
        return;
    }
    foreach (file($f, FILE_IGNORE_NEW_LINES) as $line) {
        if (preg_match('/^([A-Z][A-Z0-9_]*)\s*=\s*(.*)$/', $line, $m) && getenv($m[1]) === false) {
            putenv($m[1] . "=" . trim(trim($m[2]), "\"'"));
        }
    }
}
function configuration(): array
{
    load_environment();
    $environment = env_value("APP_ENV", "development");
    $production = in_array($environment, ["production", "staging"], true);
    $preview =
        $environment === "staging" && env_value("PUBLIC_PREVIEW") === "true";
    $base = env_value("BASE_URL", LIVE_URL);
    $url = parse_url($base);
    if (
        !$url ||
        !in_array($url["scheme"] ?? "", ["http", "https"]) ||
        isset($url["user"]) ||
        isset($url["pass"]) ||
        isset($url["query"]) ||
        isset($url["fragment"]) ||
        !in_array($url["path"] ?? "", ["", "/"])
    ) {
        throw new RuntimeException("BASE_URL must be an origin.");
    }
    if (
        $production &&
        (!env_value("BASE_URL") ||
            ($url["scheme"] ?? "") !== "https" ||
            !env_value("DATA_DIR"))
    ) {
        throw new RuntimeException(
            "Production requires verified HTTPS BASE_URL and persistent DATA_DIR.",
        );
    }
    $dir = env_value("DATA_DIR") ?: dirname(__DIR__, 2) . "/.runtime";
    if (!is_dir($dir) && !mkdir($dir, 0700, true)) {
        throw new RuntimeException("Cannot create DATA_DIR.");
    }
    $dir = realpath($dir);
    $public = realpath(public_root());
    if ($public === false) {
        throw new RuntimeException("The public_html folder is missing beside alpha-school.");
    }
    if ($dir === $public || str_starts_with($dir, $public . "/")) {
        throw new RuntimeException("DATA_DIR must be outside the web root.");
    }
    $file =
        $dir .
        "/" .
        ($production && !$preview
            ? "application-keys.json"
            : "development-keys.json");
    if (!file_exists($file)) {
        $keys = [];
        foreach (["STORAGE_ENCRYPTION_KEY", "AUDIT_HMAC_KEY"] as $key) {
            $v = env_value($key);
            if (!$v && env_value($key . "_FILE")) {
                $v = trim(file_get_contents(env_value($key . "_FILE")));
            }
            $keys[$key] = $v ?: bin2hex(random_bytes(32));
        }
        $fh = @fopen($file, "x");
        if ($fh) {
            fwrite($fh, json_encode($keys));
            fclose($fh);
            chmod($file, 0600);
        }
    }
    $keys = json_decode(
        file_get_contents($file),
        true,
        512,
        JSON_THROW_ON_ERROR,
    );
    foreach (["STORAGE_ENCRYPTION_KEY", "AUDIT_HMAC_KEY"] as $key) {
        $v = env_value($key);
        if (!$v && env_value($key . "_FILE")) {
            $v = trim(file_get_contents(env_value($key . "_FILE")));
        }
        $keys[$key] = $v ?: $keys[$key] ?? "";
        if (!preg_match('/^[a-f0-9]{64}$/i', $keys[$key])) {
            throw new RuntimeException("Invalid encryption/audit key.");
        }
    }
    if (
        strtolower($keys["STORAGE_ENCRYPTION_KEY"]) ===
        strtolower($keys["AUDIT_HMAC_KEY"])
    ) {
        throw new RuntimeException("Storage and audit keys must differ.");
    }
    $mysql = null;
    if (env_value("MYSQL_URL")) {
        $m = parse_url(env_value("MYSQL_URL"));
        if (!$m || ($m["scheme"] ?? "") !== "mysql") {
            throw new RuntimeException("Invalid MYSQL_URL.");
        }
        $urlDatabase = rawurldecode(ltrim($m["path"] ?? "", "/"));
        if (
            env_value("MYSQL_DB_NAME") &&
            $urlDatabase &&
            env_value("MYSQL_DB_NAME") !== $urlDatabase
        ) {
            throw new RuntimeException(
                "MYSQL_DB_NAME must match the database in MYSQL_URL. Moving existing data requires an approved migration.",
            );
        }
        $mysql = [
            "host" => $m["host"] ?? "",
            "port" => $m["port"] ?? 3306,
            "user" => rawurldecode($m["user"] ?? ""),
            "password" => rawurldecode($m["pass"] ?? ""),
            "database" =>
                env_value("MYSQL_DB_NAME") ?:
                (rawurldecode(ltrim($m["path"] ?? "", "/")) ?:
                ($environment === "production"
                    ? "alpha_school"
                    : "")),
        ];
    } elseif (env_value("MYSQL_HOST")) {
        $mysql = [
            "host" => env_value("MYSQL_HOST"),
            "port" => (int) env_value("MYSQL_PORT", 3306),
            "user" => env_value("MYSQL_USER"),
            "password" => env_value("MYSQL_PASSWORD"),
            "database" =>
                env_value("MYSQL_DB_NAME") ?:
                ($environment === "production"
                    ? "alpha_school"
                    : ""),
        ];
    }
    if ($mysql && env_value("MYSQL_PASSWORD_FILE")) {
        if ($mysql["password"] !== "") {
            throw new RuntimeException(
                "Use a MySQL password or a protected password file, not both.",
            );
        }
        $mysql["password"] = integration_secret("MYSQL_PASSWORD");
    }
    if (
        $mysql &&
        (!$mysql["host"] ||
            !$mysql["user"] ||
            !preg_match("/\A[A-Za-z0-9][A-Za-z0-9.-]*\z/D", $mysql["host"]) ||
            preg_match('/[\x00-\x1f\x7f]/', $mysql["user"]) ||
            $mysql["port"] < 1 ||
            $mysql["port"] > 65535 ||
            !preg_match('/^[A-Za-z0-9_]{1,63}$/', $mysql["database"]))
    ) {
        throw new RuntimeException(
            "Configure a dedicated MySQL schema, hostname and valid port.",
        );
    }
    if (!$mysql) {
        throw new RuntimeException(
            "Configure MYSQL_HOST, MYSQL_USER and MYSQL_DB_NAME in alpha-school/config.php. This application requires MySQL.",
        );
    }
    if (
        $production &&
        !$preview &&
        (!$mysql ||
            env_value("RETENTION_POLICY_APPROVED") !== "true" ||
            !env_value("FORM_RETENTION_DAYS") ||
            !env_value("ADMISSION_RETENTION_DAYS"))
    ) {
        throw new RuntimeException(
            "Production requires MySQL and approved retention settings.",
        );
    }
    $smtp = smtp_configuration();
    $storageDriver = storage_configuration();
    if ($production && !$preview && !$smtp) {
        throw new RuntimeException("Production requires SMTP office-alert delivery.");
    }
    $hops = (int) env_value("TRUST_PROXY_HOPS", 0);
    if ($hops < 0 || $hops > 5) {
        throw new RuntimeException("Invalid trusted proxy count.");
    }
    $c = [
        "production" => $production,
        "publicPreview" => $preview,
        "environment" => $environment,
        "baseUrl" => rtrim($base, "/"),
        "dataDir" => $dir,
        "mysql" => $mysql,
        "storageKey" => hex2bin($keys["STORAGE_ENCRYPTION_KEY"]),
        "auditKey" => hex2bin($keys["AUDIT_HMAC_KEY"]),
        "proxyHops" => $hops,
        "sessionIdleMs" => 1800000,
        "sessionAbsoluteMs" => 28800000,
        "maxUploadBytes" => 5242880,
        "policyVersion" => "privacy-2026-09-30-v2",
        "smtp" => $smtp,
        "storageDriver" => $storageDriver,
    ];
    foreach (
        [
            "formRetentionDays" => "FORM_RETENTION_DAYS",
            "admissionRetentionDays" => "ADMISSION_RETENTION_DAYS",
        ]
        as $k => $v
    ) {
        $n = env_value($v) !== ""
            ? env_value($v)
            : ($k === "formRetentionDays" ? 90 : 365);
        if (!ctype_digit((string) $n) || (int) $n < 1 || (int) $n > 3650) {
            throw new RuntimeException("Invalid retention setting.");
        }
        $c[$k] = (int) $n;
    }
    foreach (
        [
            "adminUsername" => "ADMIN_USERNAME",
            "adminName" => "ADMIN_NAME",
            "adminPassword" => "ADMIN_PASSWORD",
            "headTeacherUsername" => "HEAD_TEACHER_USERNAME",
            "headTeacherName" => "HEAD_TEACHER_NAME",
            "headTeacherPassword" => "HEAD_TEACHER_PASSWORD",
            "headTeacherApprovalReference" => "HEAD_TEACHER_APPROVAL_REFERENCE",
        ]
        as $k => $v
    ) {
        $c[$k] = env_value(
            $v,
            [
                "adminUsername" => "system-admin",
                "adminName" => "System Administrator",
            ][$k] ?? "",
        );
    }
    $c["headTeacherApproved"] = env_value("HEAD_TEACHER_APPROVED") === "true";
    return $c;
}
function request_ip(array $c, $second = null): string
{
    if ($second !== null) {
        $c = $second;
    }
    $ip = $_SERVER["REMOTE_ADDR"] ?? "unknown";
    if ($c["proxyHops"]) {
        $chain = array_values(
            array_filter(
                array_map(
                    "trim",
                    explode(",", $_SERVER["HTTP_X_FORWARDED_FOR"] ?? ""),
                ),
            ),
        );
        $candidate = $chain[count($chain) - $c["proxyHops"]] ?? "";
        if (filter_var($candidate, FILTER_VALIDATE_IP)) {
            $ip = $candidate;
        }
    }
    return $ip;
}
function canonical($v): string
{
    if (is_array($v)) {
        if (array_is_list($v)) {
            return "[" . implode(",", array_map("canonical", $v)) . "]";
        }
        ksort($v);
        $p = [];
        foreach ($v as $k => $x) {
            $p[] = json_encode((string) $k) . ":" . canonical($x);
        }
        return "{" . implode(",", $p) . "}";
    }
    if (is_object($v)) {
        if (count((array) $v) === 0) {
            return "{}";
        }
        return canonical((array) $v);
    }
    return json_encode(
        $v,
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
    );
}
function audit(
    $tx,
    $c,
    $actor,
    $action,
    $type,
    $id,
    $result = "success",
    $metadata = [],
): array {
    $head = $tx->getForUpdate("system_settings", "audit-head");
    $seq = ($head["sequence"] ?? 0) + 1;
    $r = [
        "id" => "AUD-" . str_pad((string) $seq, 12, "0", STR_PAD_LEFT),
        "sequence" => $seq,
        "previousHash" => $head["hash"] ?? "GENESIS",
        "at" => (new JsDate())->toISOString(),
        "userId" => $actor["user"]["id"] ?? ($actor["userId"] ?? "anonymous"),
        "action" => $action,
        "resourceType" => $type,
        "resourceId" => (string) $id,
        "result" => $result,
        "session" => isset($actor["session"]["id"])
            ? substr(sha256($actor["session"]["id"]), 0, 20)
            : "",
        "ip" => $actor["ip"] ?? "",
        "userAgent" => substr($actor["userAgent"] ?? "", 0, 200),
        "metadata" => $metadata ?: new stdClass(),
    ];
    $r["hash"] = hash_hmac("sha256", canonical($r), $c["auditKey"]);
    $tx->insert("audit_logs", $r);
    $next = ["id" => "audit-head", "sequence" => $seq, "hash" => $r["hash"]];
    $head
        ? $tx->update("system_settings", array_replace($head, $next))
        : $tx->insert("system_settings", $next);
    return $r;
}
function verify_audit($records, $key, $head): bool
{
    usort($records, fn($a, $b) => $a["sequence"] <=> $b["sequence"]);
    $hash = "GENESIS";
    $seq = 0;
    foreach ($records as $r) {
        $actual = $r["hash"];
        unset($r["hash"], $r["revision"]);
        if (($r["metadata"] ?? null) === []) {
            $r["metadata"] = new stdClass();
        }
        if (
            $r["sequence"] !== ++$seq ||
            $r["previousHash"] !== $hash ||
            !hash_equals(hash_hmac("sha256", canonical($r), $key), $actual)
        ) {
            return false;
        }
        $hash = $actual;
    }
    return !$head
        ? $seq === 0
        : $seq === $head["sequence"] && $hash === $head["hash"];
}
