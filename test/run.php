<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
require __DIR__ . "/database.php";
$testMysql = test_mysql_settings("TEST_MYSQL_URL");
$restoreMysql = test_mysql_settings("TEST_MYSQL_RESTORE_URL");
if ($testMysql["host"] === $restoreMysql["host"] && $testMysql["port"] === $restoreMysql["port"] && $testMysql["database"] === $restoreMysql["database"]) {
    throw new RuntimeException("Recovery tests require a separate empty schema.");
}
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$checks = 0;
function check($ok, $message): void
{
    global $checks;
    if (!$ok) {
        throw new RuntimeException("FAIL: " . $message);
    }
    $checks++;
}
function rejects(callable $fn, int $status, string $message): void
{
    try {
        $fn();
        throw new RuntimeException("Expected rejection: " . $message);
    } catch (HttpError $e) {
        check($e->status === $status, $message . " (" . $e->status . ")");
    }
}
$dir = dirname(__DIR__) . "/.runtime/test-" . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
putenv("APP_ENV=development");
putenv("DATA_DIR=" . $dir);
putenv("BASE_URL=http://localhost:3000");
putenv("MYSQL_URL=" . getenv("TEST_MYSQL_URL"));
putenv("MYSQL_HOST=");
putenv("MYSQL_DB_NAME=");
putenv("MYSQL_SSL_MODE=disabled");
foreach (
    [
        "MYSQL_PASSWORD_FILE",
        "MYSQL_PASSWORD",
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
        "STORAGE_DRIVER",
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
    as $name
) {
    putenv($name . "=");
}
$c = configuration();
if ($c["mysql"] && !str_ends_with($c["mysql"]["database"], "_test")) {
    throw new RuntimeException(
        "Tests require a disposable schema ending in _test.",
    );
}
$store = test_empty_store($c);
$files = new Files($c);
$p = new Platform($store, $c, $files);
$p->init();
$password = "Synthetic-Passphrase-123!";
$actors = [];
foreach (
    [
        "head" => ["head_teacher"],
        "hr" => ["hr_officer"],
        "teacher" => ["teacher"],
        "hod" => ["head_of_department"],
        "admin" => ["system_admin"],
        "editor" => ["cms_editor"],
        "publisher" => ["cms_publisher"],
        "author" => ["cms_author"],
        "parent" => ["parent"],
        "otherParent" => ["parent"],
        "student" => ["student"],
        "dpo" => ["dpo"],
        "school" => ["school_admin"],
        "media" => ["media_manager"],
    ]
    as $key => $roles
) {
    $u = create_user(
        strtolower("test." . $key),
        "Synthetic " . $key,
        $roles,
        $password,
    );
    $u["mustChangePassword"] = false;
    $u = $store->run(fn($tx) => $tx->insert("users", $u));
    $s = $store->run(
        fn($tx) => $tx->insert("sessions", [
            "id" => sha256($key),
            "userId" => $u["id"],
            "authVersion" => 1,
            "csrf" => bin2hex(random_bytes(32)),
            "createdAt" => js_date_now(),
            "lastSeen" => js_date_now(),
            "expiresAt" => js_date_now() + 28800000,
        ]),
    );
    $actors[$key] = [
        "user" => $u,
        "session" => $s,
        "ip" => "127.0.0.1",
        "userAgent" => "PHP synthetic test",
    ];
}
$a = $actors;
check(
    count($p->publicContent()) === count(SEED["news"]),
    "Seed publications retained",
);
check(
    $p->dashboard($a["admin"])["accountsTotal"] === count($a),
    "Dashboard works",
);
rejects(
    fn() => $p->students($a["admin"]),
    403,
    "Technical roles cannot see learners",
);
foreach (
    [
        "head",
        "hr",
        "teacher",
        "hod",
        "admin",
        "editor",
        "publisher",
        "author",
        "parent",
        "otherParent",
        "student",
        "dpo",
        "school",
    ]
    as $key
) {
    $p->metadata($a[$key]);
    check(is_array($p->dashboard($a[$key])), "Dashboard " . $key);
}
$r = $p->submitPublic(["ip" => "127.0.0.9"], "contact", [
    "name" => "Synthetic parent",
    "phone" => "+255700000000",
    "email" => "test@example.test",
    "message" => "Synthetic test only",
    "privacy_consent" => "yes",
    "submissionKey" => "same-key",
]);
$r2 = $p->submitPublic(["ip" => "127.0.0.9"], "contact", [
    "name" => "Synthetic parent",
    "phone" => "+255700000000",
    "message" => "Synthetic test only",
    "privacy_consent" => "yes",
    "submissionKey" => "same-key",
]);
check($r["reference"] === $r2["reference"], "Public submission idempotency");
rejects(
    fn() => $p->submitPublic(["ip" => "127.0.0.9"], "contact", [
        "name" => "Test",
        "phone" => "+255700000000",
        "message" => "Test",
    ]),
    400,
    "Privacy consent mandatory",
);
check(count($p->enquiries($a["head"])) === 1, "Office queue persisted");
$enquiry = $p->enquiries($a["head"])[0];
$enquiry = $p->changeEnquiry($a["head"], $enquiry["id"], [
    "revision" => $enquiry["revision"],
    "status" => "IN_PROGRESS",
]);
check($enquiry["status"] === "IN_PROGRESS", "Enquiry state change");
$class = $p->saveClass($a["head"], [
    "id" => "test-kg",
    "name" => "Synthetic KG",
    "yearLevel" => "KG II",
]);
$p->assignTeacher($a["head"], [
    "classId" => $class["id"],
    "teacherUserId" => $a["teacher"]["user"]["id"],
]);
$student = $p->saveStudent($a["head"], [
    "userId" => $a["student"]["user"]["id"],
    "studentRef" => "STU-TEST-100",
    "classId" => $class["id"],
    "yearLevel" => "KG II",
]);
check(
    count($p->students($a["parent"])) === 0,
    "Unverified parent sees no learners",
);
$p->verifyGuardian($a["head"], $student["id"], [
    "guardianUserId" => $a["parent"]["user"]["id"],
    "relationship" => "Parent",
    "verificationReference" => "SYNTHETIC-VERIFICATION",
    "authorityVerified" => true,
]);
check(count($p->students($a["parent"])) === 1, "Verified guardian scope");
check(
    count($p->students($a["otherParent"])) === 0,
    "Other parent remains isolated",
);
$p->recordAttendance($a["teacher"], [
    "studentId" => $student["id"],
    "date" => gmdate("Y-m-d"),
    "status" => "PRESENT",
]);
$result = $p->saveResult($a["teacher"], [
    "studentId" => $student["id"],
    "subject" => "Mathematics",
    "term" => "Term 1",
    "score" => "A",
]);
check(
    count($p->results($a["parent"], $student["id"])) === 0,
    "Draft results stay private",
);
rejects(
    fn() => $p->publishResult($a["teacher"], $result["id"], [
        "revision" => $result["revision"],
    ]),
    403,
    "Teacher cannot publish own result",
);
$p->publishResult($a["head"], $result["id"], [
    "revision" => $result["revision"],
]);
check(
    count($p->results($a["parent"], $student["id"])) === 1,
    "Published result visible",
);
check(
    $p->family($a["parent"])["children"][0]["attendance"]["percentage"] === 100,
    "Family attendance summary",
);
check(
    $p->family($a["teacher"])["classrooms"][0]["students"][0]["id"] ===
        $student["id"],
    "Classroom scope",
);
rejects(
    fn() => $p->attendance($a["otherParent"], $student["id"]),
    403,
    "Attendance scoped",
);
$app = $p->createMyAdmission($a["parent"], [
    "childName" => "Synthetic child",
    "level" => "KG II",
    "arrangement" => "Day",
    "guardianPhone" => "+255700000000",
    "privacyConsent" => true,
]);
$adoc = $p->uploadAdmissionDocument($a["parent"], $app["id"], [
    "documentType" => "Evidence",
    "title" => "Synthetic evidence",
    "file" => [
        "name" => "evidence.txt",
        "content" => base64_encode("Synthetic only"),
    ],
]);
$app = $p->myAdmissions($a["parent"])[0];
$app = $p->submitMyAdmission($a["parent"], $app["id"], [
    "revision" => $app["revision"],
]);
check($app["status"] === "SUBMITTED", "Parent admission submission");
check($p->myAdmissions($a["otherParent"]) === [], "Admission ownership");
rejects(
    fn() => $p->admissionDocumentGrant($a["otherParent"], $adoc["id"]),
    403,
    "Application file scoped",
);
$grant = $p->admissionDocumentGrant($a["parent"], $adoc["id"]);
$download = $p->privateDownload($a["parent"], basename($grant["url"]));
check($download["buffer"] === "Synthetic only", "Encrypted private download");
rejects(
    fn() => $p->privateDownload($a["parent"], basename($grant["url"])),
    403,
    "Download grant single use",
);
foreach (["teacher", "hod"] as $key) {
    $p->saveStaff($a["hr"], $a[$key]["user"]["id"], [
        "staffId" => "SYN-" . $key,
        "position" => "Synthetic teacher",
        "departmentId" => "primary",
        "employmentType" => "Permanent",
        "employmentDate" => "2026-01-01",
        "supervisorId" => $a[$key === "teacher" ? "hod" : "head"]["user"]["id"],
    ]);
}
$start = (new DateTimeImmutable(
    "next Monday",
    new DateTimeZone("UTC"),
))->format("Y-m-d");
$return = (new DateTimeImmutable($start))->modify("+2 days")->format("Y-m-d");
$p->setBalance($a["hr"], [
    "userId" => $a["teacher"]["user"]["id"],
    "year" => (int) substr($start, 0, 4),
    "typeId" => "annual",
    "entitledDays" => 20,
]);
$leave = $p->createLeave($a["teacher"], [
    "typeId" => "annual",
    "startDate" => $start,
    "returnDate" => $return,
    "reason" => "Synthetic leave",
    "handover" => "Synthetic cover",
    "emergencyContact" => "+255700000000",
]);
$leave = $p->leaveAction($a["teacher"], $leave["id"], [
    "revision" => $leave["revision"],
    "action" => "SUBMIT",
]);
foreach (["hod", "hr", "head"] as $key) {
    $queue = $p->approvalQueue($a[$key]);
    $flow = current(
        array_filter($queue, fn($x) => $x["resourceId"] === $leave["id"]),
    );
    check((bool) $flow, "Leave stage " . $key);
    $p->decide($a[$key], $flow["id"], [
        "revision" => $flow["revision"],
        "decision" => "APPROVE",
        "comment" => "Synthetic approval",
    ]);
}
check(
    $p->leaves($a["teacher"])[0]["status"] === "APPROVED",
    "Leave workflow completion",
);
check(
    $p->balances($a["teacher"])[0]["usedDays"] === 2,
    "Leave balance deduction",
);
$content = $p->saveContent($a["author"], [
    "kind" => "news",
    "title" => "Synthetic publication",
    "slug" => "synthetic-news",
    "language" => "en",
    "body" => 'First paragraph.\n\nSecond paragraph.',
    "excerpt" => "Synthetic public update",
    "category" => "School News",
]);
$content = $p->contentAction($a["author"], $content["id"], [
    "revision" => $content["revision"],
    "action" => "SUBMIT",
]);
foreach (["editor", "head"] as $key) {
    $flow = current(
        array_filter(
            $p->approvalQueue($a[$key]),
            fn($x) => $x["resourceId"] === $content["id"],
        ),
    );
    $p->decide($a[$key], $flow["id"], [
        "revision" => $flow["revision"],
        "decision" => "APPROVE",
    ]);
}
$content = current(
    array_filter(
        $p->cms($a["publisher"]),
        fn($x) => $x["id"] === $content["id"],
    ),
);
$content = $p->contentAction($a["publisher"], $content["id"], [
    "revision" => $content["revision"],
    "action" => "PUBLISH",
]);
check($content["status"] === "PUBLISHED", "Independent CMS publication");
check(
    (bool) array_filter(
        $p->publicContent(),
        fn($x) => $x["slug"] === "synthetic-news",
    ),
    "CMS appears on public pages",
);
$notice = $p->saveNotice($a["hr"], [
    "title" => "Synthetic notice",
    "message" => "Synthetic only",
    "category" => "HR",
    "priority" => "Information",
    "audience" => ["type" => "ALL_STAFF"],
    "acknowledgementRequired" => true,
]);
$notice = $p->publishNotice($a["head"], $notice["id"], [
    "revision" => $notice["revision"],
]);
$p->acknowledgeNotice($a["teacher"], $notice["id"], [
    "revision" => $notice["revision"],
    "version" => $notice["version"],
]);
check(
    count($p->notices($a["parent"])) === 0,
    "Staff notices not visible to parents",
);
check(
    count($p->search($a["teacher"], ["q" => "Synthetic"])) > 0,
    "Authorised search",
);
$contract = $p->createContract($a["hr"], [
    "ownerId" => $a["teacher"]["user"]["id"],
    "title" => "Synthetic employment contract",
    "startDate" => gmdate("Y-m-d"),
    "endDate" => (new DateTimeImmutable("+180 days"))->format("Y-m-d"),
    "financialReview" => false,
    "file" => [
        "name" => "contract.txt",
        "content" => base64_encode("Synthetic contract terms"),
    ],
]);
$contract = $p->contractSubmit($a["hr"], $contract["id"], [
    "revision" => $contract["revision"],
]);
foreach (["school", "head", "head", "teacher"] as $key) {
    $flow = current(
        array_filter(
            $p->approvalQueue($a[$key]),
            fn($x) => $x["resourceId"] === $contract["id"],
        ),
    );
    check((bool) $flow, "Contract stage " . $key);
    $p->decide($a[$key], $flow["id"], [
        "revision" => $flow["revision"],
        "decision" => "APPROVE",
        "comment" => "Synthetic acknowledgement and attestation",
    ]);
}
check(
    $p->contracts($a["teacher"])[0]["status"] === "ACTIVE",
    "Contract issuance",
);
$doc = $p->createDocument($a["teacher"], [
    "documentNumber" => "ALPHA-TEST-PUB-001",
    "title" => "Synthetic public guidance",
    "description" => "Synthetic only",
    "category" => "Public policies",
    "classification" => "PUBLIC",
    "changeSummary" => "First synthetic version",
    "file" => [
        "name" => "guidance.txt",
        "content" => base64_encode("Approved synthetic guidance"),
    ],
]);
$v = $doc["draft"];
$v = $p->documentAction($a["teacher"], $v["id"], [
    "revision" => $v["revision"],
    "action" => "SUBMIT",
]);
foreach (["school", "head"] as $key) {
    $flow = current(
        array_filter(
            $p->approvalQueue($a[$key]),
            fn($x) => $x["resourceId"] === $v["id"],
        ),
    );
    $p->decide($a[$key], $flow["id"], [
        "revision" => $flow["revision"],
        "decision" => "APPROVE",
    ]);
}
$v = $store->get("document_versions", $v["id"]);
$v = $p->documentAction($a["school"], $v["id"], [
    "revision" => $v["revision"],
    "action" => "PUBLISH",
]);
check(count($p->publicDownloads()) === 1, "Approved public document listed");
check(
    $p->publicDownload($doc["id"], ["ip" => "127.0.0.1"])["buffer"] ===
        "Approved synthetic guidance",
    "Public download works",
);
$evidence = $p->createDocument($a["dpo"], [
    "documentNumber" => "ALPHA-CONSENT-001",
    "title" => "Synthetic consent evidence",
    "category" => "Child protection",
    "classification" => "CONFIDENTIAL",
    "changeSummary" => "Synthetic signed evidence",
    "file" => [
        "name" => "evidence.txt",
        "content" => base64_encode("Synthetic evidence"),
    ],
]);
$channels = array_fill_keys(a_platform_privacy_CHANNELS, false);
$channels["website"] = true;
$consent = $p->saveConsent($a["dpo"], [
    "studentRef" => "STU-TEST-100",
    "guardianName" => "Synthetic guardian",
    "relationship" => "Parent",
    "grantedAt" => gmdate("Y-m-d"),
    "expiresAt" => (new DateTimeImmutable("+180 days"))->format("Y-m-d"),
    "channels" => $channels,
    "evidenceVersionId" => $evidence["draft"]["id"],
    "authorityVerified" => true,
]);
$im = imagecreatetruecolor(8, 8);
ob_start();
imagepng($im);
$png = ob_get_clean();
unset($im);
$media = $p->createMedia($a["media"], [
    "title" => "Synthetic school activity",
    "alt" => "Synthetic illustration",
    "category" => "Campus",
    "childrenPresent" => true,
    "studentRefs" => ["STU-TEST-100"],
    "subjectsVerified" => true,
    "noChildNames" => true,
    "file" => ["name" => "activity.png", "content" => base64_encode($png)],
]);
$media = $p->mediaAction($a["media"], $media["id"], [
    "revision" => $media["revision"],
    "action" => "SUBMIT",
]);
foreach (["dpo", "head"] as $key) {
    $flow = current(
        array_filter(
            $p->approvalQueue($a[$key]),
            fn($x) => $x["resourceId"] === $media["id"],
        ),
    );
    $p->decide($a[$key], $flow["id"], [
        "revision" => $flow["revision"],
        "decision" => "APPROVE",
    ]);
}
$media = $store->get("media", $media["id"]);
$media = $p->mediaAction($a["publisher"], $media["id"], [
    "revision" => $media["revision"],
    "action" => "PUBLISH",
]);
check(count($p->publicMedia()) === 1, "Approved media visible");
check(
    str_starts_with(
        $p->publicMediaFile($media["id"])["buffer"],
        hex2bin("ffd8ff"),
    ),
    "Image metadata stripped and re-encoded",
);
$p->withdrawConsent($a["dpo"], $consent["id"], [
    "revision" => $consent["revision"],
    "reason" => "Synthetic withdrawal",
]);
check(
    $p->publicMedia() === [],
    "Withdrawn consent immediately removes public media",
);
rejects(
    fn() => $p->publicMediaFile($media["id"]),
    404,
    "Withdrawn media cannot be fetched",
);
check(
    !is_file(
        $dir .
            "/storage/public/" .
            $store->get("media", $media["id"])["file"]["storageKey"],
    ),
    "Public file physically removed",
);
$checkRecord = $store->run(
    fn($tx) => $tx->insert("system_settings", [
        "id" => "optimistic-test",
        "value" => 1,
    ]),
);
$store->run(fn($tx) => $tx->update("system_settings", $checkRecord));
rejects(
    fn() => $store->run(
        fn($tx) => $tx->update("system_settings", $checkRecord),
    ),
    409,
    "Optimistic conflict",
);
rejects(
    fn() => $store->run(
        fn($tx) => $tx->remove("audit_logs", "AUD-000000000001"),
    ),
    409,
    "Audit append-only",
);
check($p->auditLog($a["head"])["integrityValid"], "Audit HMAC chain");
a_platform_jobs_maintenance($p);
check((bool) $store->get("system_settings", "maintenance"), "Maintenance job");
$bytes = $files->prepare([
    "name" => "safe.txt",
    "content" => base64_encode("private text"),
]);
check($files->read($bytes) === "private text", "AES-GCM round trip");
rejects(
    fn() => $files->prepare([
        "name" => "unsafe.pdf",
        "content" => base64_encode("%PDF-1.7 /JavaScript %%EOF"),
    ]),
    400,
    "Active PDF rejected",
);
rejects(
    fn() => $files->prepare(["name" => "bad.txt", "content" => "%%%"]),
    413,
    "Invalid base64 rejected",
);
check(
    verify_password(
        password_record(str_repeat("A", 90) . "a1!"),
        str_repeat("A", 90) . "a1!",
    ),
    "Long passwords supported without truncation",
);
check(
    !verify_password(
        password_record(str_repeat("A", 90) . "a1!"),
        str_repeat("A", 90) . "b1!",
    ),
    "Long password differences verified",
);
$baseline = json_decode(
    file_get_contents(__DIR__ . "/render-hashes.json"),
    true,
);
$db = SEED;
$db["events"] = [];
$db["gallery"] = [];
$ctx = [
    "db" => $db,
    "content" => [],
    "downloads" => [],
    "path" => "/",
    "query" => new JsMap(),
    "lang" => "en",
    "baseUrl" => LIVE_URL,
    "config" => $c,
    "nonce" => "test-nonce",
];
foreach ($baseline as $name => $hash) {
    $out = ("a_pages_" . $name . "_render")($ctx);
    check(
        hash("sha256", $out["body"]) === $hash,
        "Original UI markup parity: " . $name,
    );
}
foreach (["/sw", "/sw/admissions", "/sw/contact", "/news"] as $path) {
    $url = new NativeUrl($path, "http://internal");
    $ctx = public_context($p, $url, "test");
    $out = match ($path) {
        "/sw" => a_pages_home_render($ctx),
        "/sw/admissions" => a_pages_swahili_admissions($ctx),
        "/sw/contact" => a_pages_swahili_contact($ctx),
        default => a_pages_news_list($ctx),
    };
    check(
        strlen(a_layout_page($ctx, $out["body"], $out["meta"])) > 1000,
        "Bilingual/news rendering " . $path,
    );
}
if (function_exists("sodium_crypto_pwhash_scryptsalsa208sha256")) {
    $vector = json_decode(
        file_get_contents(__DIR__ . "/legacy-password.json"),
        true,
    );
    check(
        verify_password($vector, $password),
        "Existing Node scrypt credentials compatible",
    );
}
require dirname(__DIR__) . "/src/php/operations.php";
$snapshot = capture_snapshot($store, $files, $c);
$backupTestKey = random_bytes(32);
$archive = seal_backup($snapshot, $backupTestKey);
check(str_starts_with($archive, "ALPHAMYS"), "PHP backup envelope");
validate_backup_files($snapshot["files"]);
check(count($snapshot["files"]) > 0, "Private file backup references");
$archiveFile = $dir . "/synthetic-backup.json.enc";
file_put_contents($archiveFile, $archive);
$opened = open_backup($archiveFile, $backupTestKey);
check(
    count($opened["collections"]) === count(COLLECTIONS),
    "Archive authentication and decoding",
);
$wrongKeyRejected = false;
try {
    open_backup($archiveFile, random_bytes(32));
} catch (RuntimeException) {
    $wrongKeyRejected = true;
}
check($wrongKeyRejected, "Wrong backup key rejected");
$restoreDir = $dir . "/isolated-restore";
mkdir($restoreDir, 0700, true);
$restoreConfig = array_replace($c, ["dataDir" => $restoreDir, "mysql" => $restoreMysql]);
$restoreStore = test_empty_store($restoreConfig);
$restoreFiles = new Files($restoreConfig);
$restoreFiles->init();
$counts = restore_snapshot($restoreStore, $restoreFiles, $opened);
check(
    $counts["sessions"] === 0 && $counts["download_grants"] === 0,
    "Recovery invalidates access records",
);
check(
    verify_audit(
        $restoreStore->list("audit_logs"),
        $c["auditKey"],
        $restoreStore->get("system_settings", "audit-head"),
    ),
    "Restored audit chain",
);
check(
    $restoreFiles->read(
        $store->get("admission_documents", $adoc["id"])["file"],
    ) === "Synthetic only",
    "Restored private file decryption",
);
check(
    count(array_diff(scandir($restoreDir . "/storage/public"), [".", ".."])) ===
        0,
    "No public copies resurrected",
);

echo "PASS: $checks checks (" . $store->kind . ")\n";
