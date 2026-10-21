<?php
// Synthetic fixtures and a dedicated disposable MySQL schema; no real emails.
require dirname(__DIR__) . "/src/php/bootstrap.php";
require __DIR__ . "/database.php";
require dirname(__DIR__) . "/src/php/jobs.php";
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
function integration_check($ok, string $label): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $label);
    }
    $checks++;
}
function integration_reject(callable $fn, string $label): void
{
    try {
        $fn();
    } catch (RuntimeException) {
        integration_check(true, $label);
        return;
    }
    throw new RuntimeException("FAIL: expected rejection: " . $label);
}
$dir = dirname(__DIR__) . "/.runtime/integrations-" . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
foreach (
    [
        "MYSQL_URL",
        "MYSQL_HOST",
        "MYSQL_DB_NAME",
        "MYSQL_PASSWORD",
        "MYSQL_PASSWORD_FILE",
        "STORAGE_ENCRYPTION_KEY",
        "STORAGE_ENCRYPTION_KEY_FILE",
        "AUDIT_HMAC_KEY",
        "AUDIT_HMAC_KEY_FILE",
        "ADMIN_PASSWORD",
        "HEAD_TEACHER_PASSWORD",
        "HEAD_TEACHER_USERNAME",
        "HEAD_TEACHER_NAME",
        "HEAD_TEACHER_APPROVED",
        "HEAD_TEACHER_APPROVAL_REFERENCE",
        "PUBLIC_PREVIEW",
        "FORM_RETENTION_DAYS",
        "ADMISSION_RETENTION_DAYS",
        "RETENTION_POLICY_APPROVED",
        "SMTP_HOST",
        "SMTP_PORT",
        "SMTP_SECURITY",
        "SMTP_USERNAME",
        "SMTP_PASSWORD",
        "SMTP_PASSWORD_FILE",
        "SMTP_FROM_EMAIL",
        "SMTP_FROM_NAME",
        "OFFICE_ALERT_EMAIL",
        "PRIVACY_ALERT_EMAIL",
    ]
    as $key
) {
    putenv($key . "=");
}
putenv("APP_ENV=development");
putenv("BASE_URL=https://school.example");
putenv("DATA_DIR=" . $dir);
putenv("STORAGE_DRIVER=local");
putenv("MYSQL_SSL_MODE=disabled");
putenv("MYSQL_HOST=localhost");
putenv("MYSQL_USER=synthetic-user");
putenv("MYSQL_DB_NAME=alpha_school");
integration_check(
    smtp_configuration() === null,
    "Unconfigured development email sends nothing",
);
$settings = [
    "SMTP_HOST" => "smtp.example.com",
    "SMTP_PORT" => "587",
    "SMTP_SECURITY" => "starttls",
    "SMTP_USERNAME" => "provider-account-id",
    "SMTP_PASSWORD" => "synthetic-password-only",
    "SMTP_FROM_EMAIL" => "alerts@school.example",
    "SMTP_FROM_NAME" => "Alpha School",
    "OFFICE_ALERT_EMAIL" => "office@school.example",
    "PRIVACY_ALERT_EMAIL" => "privacy@school.example",
];
foreach ($settings as $key => $value) {
    putenv($key . "=" . $value);
}
$smtp = smtp_configuration();
integration_check(
    $smtp["user"] === "provider-account-id" &&
        $smtp["from"] === "alerts@school.example",
    "SMTP auth identity is independent of the verified sender",
);
integration_check(
    $smtp["security"] === "starttls" && $smtp["port"] === 587,
    "Authenticated STARTTLS selected",
);
foreach (
    [
        "SMTP_HOST" => "https://smtp.example.com",
        "SMTP_PORT" => "0",
        "SMTP_SECURITY" => "none",
        "SMTP_FROM_EMAIL" =>
            "alerts@school.example\r\nBcc: victim@school.example",
        "SMTP_FROM_NAME" => "Alpha\r\nInjected",
        "PRIVACY_ALERT_EMAIL" => "",
        "SMTP_USERNAME" => "account\nAUTH",
        "SMTP_PASSWORD" => "",
    ]
    as $key => $bad
) {
    putenv($key . "=" . $bad);
    integration_reject(
        fn() => smtp_configuration(),
        "Reject unsafe/incomplete " . $key,
    );
    putenv($key . "=" . $settings[$key]);
}
$secret = $dir . "/smtp-password";
file_put_contents($secret, "synthetic-secret-with-spaces \n");
chmod($secret, 0600);
putenv("SMTP_PASSWORD_FILE=" . $secret);
integration_reject(
    fn() => smtp_configuration(),
    "Reject duplicate password sources",
);
putenv("SMTP_PASSWORD=");
integration_check(
    smtp_configuration()["password"] === "synthetic-secret-with-spaces ",
    "Secret file preserves meaningful password whitespace",
);
chmod($secret, 0644);
integration_reject(
    fn() => smtp_configuration(),
    "Reject world-readable SMTP credential files",
);
chmod($secret, 0600);
putenv("SMTP_PASSWORD_FILE=");
putenv("SMTP_PASSWORD=" . $settings["SMTP_PASSWORD"]);
unlink($secret);
integration_check(
    storage_configuration() === "local",
    "Storage provider is explicitly pinned to disk",
);
putenv("STORAGE_DRIVER=unknown");
integration_reject(
    fn() => storage_configuration(),
    "Unknown storage driver rejected",
);
putenv("STORAGE_DRIVER=local");
$c = configuration();
integration_check($c["formRetentionDays"] === 90 && $c["admissionRetentionDays"] === 365, "Blank development retention fields use documented defaults");
$files = new Files($c);
$files->init();
integration_check(
    $files->provider() === "local",
    "Files reports selected disk provider",
);
$file = $files->prepare([
    "name" => "synthetic.txt",
    "content" => base64_encode("Synthetic integration fixture only"),
]);
integration_check(
    $files->read($file) === "Synthetic integration fixture only",
    "Encrypted private file round-trip",
);
integration_check(
    !str_contains(
        $files->readStored("private", $file["storageKey"]),
        "Synthetic integration fixture only",
    ),
    "Private bytes are encrypted on disk",
);
$files->discard($file);
integration_check(
    !file_exists($dir . "/storage/private/" . $file["storageKey"]),
    "Storage probe removes its file",
);
integration_check(
    (fileperms($dir . "/storage/private") & 0077) === 0,
    "Private storage directory is owner-only",
);
if (
    function_exists("symlink") &&
    @symlink($dir . "/storage", $dir . "/linked-storage")
) {
    // This deliberately points the expected storage root through a link.
    mkdir($dir . "/link-test", 0700);
    symlink($dir . "/storage", $dir . "/link-test/storage");
    integration_reject(
        fn() => (new Files(
            array_replace($c, ["dataDir" => $dir . "/link-test"]),
        ))->init(),
        "Storage root symlinks rejected",
    );
    unlink($dir . "/linked-storage");
    unlink($dir . "/link-test/storage");
    rmdir($dir . "/link-test");
}
putenv("APP_ENV=production");
putenv("MYSQL_HOST=mysql.example.com");
putenv("MYSQL_USER=synthetic-user");
putenv("MYSQL_PASSWORD=synthetic-password");
putenv("MYSQL_PORT=3306");
putenv("MYSQL_SSL_MODE=verify-full");
putenv("RETENTION_POLICY_APPROVED=true");
putenv("FORM_RETENTION_DAYS=90");
putenv("ADMISSION_RETENTION_DAYS=365");
integration_check(
    configuration()["mysql"]["database"] === "alpha_school",
    "Production MySQL defaults to alpha_school",
);
putenv(
    "MYSQL_URL=mysql://synthetic-user:synthetic-password@mysql.example.com/alpha_school",
);
putenv("MYSQL_DB_NAME=wrong_schema");
integration_reject(
    fn() => configuration(),
    "MYSQL_URL and explicit schema must agree",
);
putenv("MYSQL_DB_NAME=");
putenv("MYSQL_URL=mysql://synthetic-user:synthetic-password@mysql.example.com/account_alpha_school");
integration_check(
    configuration()["mysql"]["database"] === "account_alpha_school",
    "Production accepts cPanel-prefixed MySQL schemas",
);
putenv("MYSQL_URL=");
putenv("MYSQL_HOST=mysql.example.com;dbname=wrong_schema");
integration_reject(
    fn() => configuration(),
    "MySQL hostname cannot inject DSN options",
);
putenv("MYSQL_HOST=localhost");
putenv("MYSQL_DB_NAME=account_alpha_school");
putenv("MYSQL_SSL_MODE=");
integration_check(mysql_ssl_mode(configuration()) === "disabled", "cPanel local MySQL works without database TLS");
putenv("MYSQL_HOST=127.0.0.1");
integration_check(mysql_ssl_mode(configuration()) === "disabled", "Loopback IP MySQL is supported");
putenv("MYSQL_HOST=db.example.com");
integration_check(mysql_ssl_mode(configuration()) === "verify-full", "Remote production MySQL defaults to verified TLS");
putenv("MYSQL_SSL_MODE=disabled");
integration_reject(fn() => mysql_ssl_mode(configuration()), "Remote production MySQL cannot disable TLS");
putenv("MYSQL_HOST=localhost");
putenv("MYSQL_SSL_MODE=invalid");
integration_reject(fn() => mysql_ssl_mode(configuration()), "Unknown MySQL TLS mode is rejected");
putenv("MYSQL_SSL_MODE=disabled");
foreach (["bad-name", "bad;DROP DATABASE alpha_school", "bad`name", str_repeat("a", 64)] as $invalid) {
    putenv("MYSQL_DB_NAME=" . $invalid);
    integration_reject(fn() => configuration(), "Invalid database names cannot reach schema setup SQL");
}
putenv("MYSQL_HOST=");
integration_reject(fn() => configuration(), "Missing MySQL configuration never falls back to another database");
putenv("APP_ENV=development");
putenv("MYSQL_HOST=localhost");
putenv("MYSQL_USER=synthetic-user");
putenv("MYSQL_PASSWORD=");
putenv("MYSQL_DB_NAME=alpha_school");
putenv("MYSQL_SSL_MODE=disabled");
final class FakeSmtpConnection implements SmtpConnection
{
    public array $events = [];
    public bool $closed = false;
    public bool $failTls = false;
    public function __construct(public array $responses) {}
    public function readLine(): ?string
    {
        $this->events[] = "READ";
        return array_shift($this->responses);
    }
    public function write(string $bytes): void
    {
        $this->events[] = $bytes;
    }
    public function enableTls(): void
    {
        $this->events[] = "TLS";
        if ($this->failTls) {
            throw new RuntimeException("Synthetic TLS failure");
        }
    }
    public function close(): void
    {
        $this->closed = true;
    }
    public function transcript(): string
    {
        return implode(
            "",
            array_filter(
                $this->events,
                fn($s) => $s !== "READ" && $s !== "TLS",
            ),
        );
    }
}
function smtp_replies(
    string $security = "starttls",
    string $mechanism = "LOGIN",
    int $rcpt = 250,
    bool $quit = true,
): array {
    $r = ["220 synthetic provider\r\n"];
    if ($security === "starttls") {
        array_push(
            $r,
            "250-synthetic\r\n",
            "250 STARTTLS\r\n",
            "220 Ready for TLS\r\n",
        );
    }
    array_push($r, "250-synthetic\r\n", "250 AUTH $mechanism\r\n");
    if ($mechanism === "LOGIN") {
        array_push($r, "334 Username\r\n", "334 Password\r\n");
    }
    array_push(
        $r,
        "235 Authenticated\r\n",
        "250 Sender accepted\r\n",
        "$rcpt Recipient response\r\n",
        "354 Send data\r\n",
        "250 Queued\r\n",
    );
    if ($quit) {
        $r[] = "221 Goodbye\r\n";
    }
    return $r;
}
$conn = new FakeSmtpConnection(smtp_replies());
integration_check(
    (new SmtpClient($conn))->send(
        $smtp,
        $smtp["officeEmail"],
        "Header: synthetic\r\n\r\n.first line\r\n.last line",
    ),
    "STARTTLS/LOGIN delivery accepted",
);
$auth = array_search("AUTH LOGIN\r\n", $conn->events, true);
$tls = array_search("TLS", $conn->events, true);
integration_check(
    $tls !== false &&
        $auth > $tls &&
        count(array_keys($conn->events, "EHLO alpha-school\r\n", true)) === 2,
    "AUTH occurs only after TLS and a second EHLO",
);
integration_check(
    str_contains(
        $conn->transcript(),
        "\r\n..first line\r\n..last line\r\n.\r\n",
    ),
    "SMTP data is dot-stuffed correctly",
);
integration_check($conn->closed, "Successful delivery closes the connection");
$conn = new FakeSmtpConnection(smtp_replies("tls", "PLAIN"));
integration_check(
    (new SmtpClient($conn))->send(
        array_replace($smtp, ["security" => "tls", "port" => 465]),
        $smtp["officeEmail"],
        "Synthetic",
    ) && str_contains($conn->transcript(), "AUTH PLAIN "),
    "Implicit TLS supports PLAIN authentication",
);
$conn = new FakeSmtpConnection(["220 synthetic\r\n", "250 AUTH LOGIN\r\n"]);
integration_reject(
    fn() => (new SmtpClient($conn))->send(
        $smtp,
        $smtp["officeEmail"],
        "Synthetic",
    ),
    "Provider without STARTTLS rejected",
);
integration_check(
    !str_contains($conn->transcript(), "AUTH LOGIN") && $conn->closed,
    "Credentials not sent when STARTTLS missing",
);
$conn = new FakeSmtpConnection(smtp_replies());
$conn->failTls = true;
integration_reject(
    fn() => (new SmtpClient($conn))->send(
        $smtp,
        $smtp["officeEmail"],
        "Synthetic",
    ),
    "TLS handshake failure rejected",
);
integration_check(
    !str_contains($conn->transcript(), "AUTH LOGIN"),
    "Credentials not sent when TLS fails",
);
$conn = new FakeSmtpConnection(["220 synthetic\r\n", "250 AUTH LOGIN\r\n"]);
integration_reject(
    fn() => (new SmtpClient($conn))->send(
        array_replace($smtp, ["security" => "none"]),
        $smtp["officeEmail"],
        "Synthetic",
    ),
    "Plaintext transport rejected by SMTP client",
);
$r = smtp_replies("tls");
$r[4] = "535 Authentication rejected\r\n";
$conn = new FakeSmtpConnection($r);
integration_reject(
    fn() => (new SmtpClient($conn))->send(
        array_replace($smtp, ["security" => "tls"]),
        $smtp["officeEmail"],
        "Synthetic",
    ),
    "Rejected credentials fail delivery",
);
integration_check(
    !str_contains($conn->transcript(), "MAIL FROM"),
    "No mail submitted after rejected authentication",
);
$conn = new FakeSmtpConnection(smtp_replies("starttls", "LOGIN", 550));
integration_reject(
    fn() => (new SmtpClient($conn))->send(
        $smtp,
        $smtp["officeEmail"],
        "Synthetic",
    ),
    "Rejected recipient fails delivery",
);
integration_check(
    !str_contains($conn->transcript(), "DATA\r\n"),
    "Rejected recipient receives no body",
);
$conn = new FakeSmtpConnection(smtp_replies("starttls", "LOGIN", 250, false));
integration_check(
    (new SmtpClient($conn))->send($smtp, $smtp["officeEmail"], "Synthetic"),
    "Lost QUIT reply cannot cause duplicate retry after acceptance",
);
$conn = new FakeSmtpConnection(["220-synthetic\r\n", "250 inconsistent\r\n"]);
integration_reject(
    fn() => (new SmtpClient($conn))->send(
        $smtp,
        $smtp["officeEmail"],
        "Synthetic",
    ),
    "Inconsistent multiline replies rejected",
);
$alert = [
    "id" => "SYNTHETIC-ALERT",
    "reference" => "SYNTHETIC-REF",
    "type" => "Admission application",
    "href" => "/portal/admissions",
    "createdAt" => "2026-10-14T10:00:00.000Z",
    "childName" => "PRIVATE-SYNTHETIC-CHILD",
    "email" => "PRIVATE-SYNTHETIC-EMAIL",
    "message" => "PRIVATE-SYNTHETIC-BODY",
];
$mail = smtp_alert_message($smtp, $alert, $c["baseUrl"]);
integration_check(
    $mail["recipient"] === $smtp["officeEmail"],
    "Office notification uses approved office mailbox",
);
$body = base64_decode(explode("\r\n\r\n", $mail["message"], 2)[1], true);
integration_check(
    str_contains($body, "SYNTHETIC-REF") &&
        str_contains($body, "https://school.example/portal/admissions"),
    "Mail contains a reference and secure portal link",
);
integration_check(
    !str_contains($body, "PRIVATE-SYNTHETIC"),
    "Mail omits child/contact/form contents",
);
foreach (["Privacy request", "Safeguarding concern"] as $type) {
    integration_check(
        smtp_alert_message(
            $smtp,
            array_replace($alert, ["type" => $type]),
            $c["baseUrl"],
        )["recipient"] === $smtp["privacyEmail"],
        "Restricted " . $type . " routes to privacy mailbox",
    );
}
$long = smtp_encoded_header(
    str_repeat("Kiswahili à ", 30) . "\r\nInjected: header",
);
$valid = true;
foreach (explode("\r\n ", $long) as $word) {
    if (
        strlen($word) > 75 ||
        !preg_match('/^=\?UTF-8\?B\?[A-Za-z0-9+\/=]+\?=$/D', $word)
    ) {
        $valid = false;
    }
}
integration_check(
    $valid && !str_contains($long, "\r\nInjected"),
    "UTF-8 MIME headers are bounded and injection-safe",
);

// Everything above is configuration, disk or fake SMTP only.
if (in_array("--configuration-only", $argv, true)) {
    echo "PASS: $checks offline configuration, file storage and synthetic SMTP checks\n";
    exit(0);
}
// Persistent outbox checks use only an explicitly selected empty test schema.
$testMysql = test_mysql_settings("TEST_MYSQL_INTEGRATIONS_URL");
$c = array_replace(configuration(), ["mysql" => $testMysql]);
$store = test_empty_store($c);
$p = new Platform($store, $c, new Files($c));
$p->init();
$queue = array_replace($alert, [
    "id" => "SYNTHETIC-OUTBOX",
    "status" => "PENDING",
    "attempts" => 0,
    "nextAttemptAt" => 0,
]);
$store->run(fn($tx) => $tx->insert("outbox", $queue));
$invocations = 0;
$failed = function ($settings, $row, $base) use (&$invocations) {
    $invocations++;
    throw new RuntimeException(
        "Synthetic provider failure: never persist this credential-looking string",
    );
};
deliver_alerts($p, $failed);
$row = $store->get("outbox", $queue["id"]);
integration_check(
    $row["status"] === "RETRY" &&
        $row["attempts"] === 1 &&
        $row["nextAttemptAt"] > js_date_now(),
    "SMTP failure persists bounded backoff",
);
deliver_alerts($p, $failed);
integration_check($invocations === 1, "Future retry is not dispatched early");
for ($i = 1; $i < 8; $i++) {
    $store->run(function ($tx) use ($queue) {
        $r = $tx->get("outbox", $queue["id"]);
        $r["nextAttemptAt"] = 0;
        $tx->update("outbox", $r);
    });
    deliver_alerts($p, $failed);
}
$row = $store->get("outbox", $queue["id"]);
integration_check(
    $row["status"] === "FAILED" && $row["attempts"] === 8,
    "Eight failed SMTP attempts terminate retries",
);
integration_check(
    !str_contains(json_encode($row), "credential-looking"),
    "Provider exceptions and credentials never enter outbox",
);
$store->run(
    fn($tx) => $tx->insert(
        "outbox",
        array_replace($queue, ["id" => "SYNTHETIC-SUCCESS"]),
    ),
);
deliver_alerts($p, fn() => true);
$row = $store->get("outbox", "SYNTHETIC-SUCCESS");
integration_check(
    $row["status"] === "DELIVERED" &&
        $row["lastStatus"] === 250 &&
        $row["deliveredAt"] &&
        $row["lockedUntil"] === 0,
    "Accepted SMTP message is recorded as delivered",
);
integration_check(
    verify_audit(
        $store->list("audit_logs"),
        $c["auditKey"],
        $store->get("system_settings", "audit-head"),
    ),
    "Delivery attempts retain valid audit integrity",
);
echo "PASS: $checks production integration checks (synthetic SMTP and local storage; no live providers)\n";
