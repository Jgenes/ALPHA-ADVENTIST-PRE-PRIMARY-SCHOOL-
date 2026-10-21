<?php
// Full first-install regression; never uses the school's configured database.
require dirname(__DIR__) . '/src/php/bootstrap.php';
require application_root() . '/src/php/installation.php';
require __DIR__ . '/database.php';
$mysql = test_mysql_settings('TEST_MYSQL_SETUP_URL');
$dir = application_root() . '/.runtime/setup-' . bin2hex(random_bytes(6));
foreach ([
    'APP_ENV' => 'development', 'BASE_URL' => 'http://localhost:3000', 'DATA_DIR' => $dir,
    'MYSQL_URL' => getenv('TEST_MYSQL_SETUP_URL'), 'MYSQL_DB_NAME' => '', 'MYSQL_HOST' => '',
    'MYSQL_PASSWORD_FILE' => '', 'MYSQL_PASSWORD' => '', 'MYSQL_SSL_MODE' => 'disabled',
    'STORAGE_DRIVER' => 'local', 'STORAGE_ENCRYPTION_KEY' => '', 'STORAGE_ENCRYPTION_KEY_FILE' => '',
    'AUDIT_HMAC_KEY' => '', 'AUDIT_HMAC_KEY_FILE' => '',
    'SMTP_HOST' => '', 'SMTP_PORT' => '', 'SMTP_SECURITY' => '', 'SMTP_USERNAME' => '',
    'SMTP_PASSWORD' => '', 'SMTP_PASSWORD_FILE' => '', 'SMTP_FROM_EMAIL' => '', 'SMTP_FROM_NAME' => '',
    'OFFICE_ALERT_EMAIL' => '', 'PRIVACY_ALERT_EMAIL' => '',
    'ADMIN_PASSWORD' => '', 'HEAD_TEACHER_USERNAME' => '', 'HEAD_TEACHER_NAME' => '',
    'HEAD_TEACHER_PASSWORD' => '', 'HEAD_TEACHER_APPROVED' => '', 'HEAD_TEACHER_APPROVAL_REFERENCE' => '',
    'FORM_RETENTION_DAYS' => '', 'ADMISSION_RETENTION_DAYS' => '',
] as $key => $value) {
    putenv($key . '=' . $value);
}
$config = configuration();
$store = test_empty_store($config);
$input = [
    'approved' => 'yes', 'approval_reference' => 'SYNTHETIC-TEST-APPOINTMENT',
    'admin_username' => 'setup-admin', 'admin_name' => 'Synthetic Administrator',
    'admin_password' => 'Synthetic-Admin-123!', 'admin_confirm' => 'Synthetic-Admin-123!',
    'head_username' => 'setup-head', 'head_name' => 'Synthetic Head Teacher',
    'head_password' => 'Synthetic-Head-123!', 'head_confirm' => 'Synthetic-Head-123!',
];
$count = 0;
function installation_check(bool $ok, string $label): void
{
    global $count;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $label);
    }
    $count++;
}
setup_install($input);
$users = $store->list('users');
installation_check(count($users) === 2, 'Both initial accounts created in MySQL');
installation_check($users[0]['roles'] === ['system_admin'] && $users[1]['roles'] === ['head_teacher'], 'No extra roles granted');
installation_check($users[0]['mustChangePassword'] && $users[1]['mustChangePassword'], 'Password change required');
installation_check(verify_password($users[0], $input['admin_password']), 'Administrator can verify initial password');
installation_check(verify_password($users[1], $input['head_password']), 'Head teacher can verify initial password');
installation_check(is_file($dir . '/setup-complete.lock'), 'Completion marker saved');
installation_check(is_dir($dir . '/storage/private'), 'Encrypted upload storage initialized');
installation_check((fileperms($dir . '/setup-complete.lock') & 0077) === 0, 'Marker is private');
installation_check(verify_audit($store->list('audit_logs'), $config['auditKey'], $store->get('system_settings', 'audit-head')), 'Initial appointments retain audit integrity');
try {
    setup_install($input);
    throw new RuntimeException('Expected completion-lock rejection');
} catch (HttpError $e) {
    installation_check($e->status === 409, 'Completed setup refuses a second run');
}
// Even losing a lock marker cannot reset accounts in an existing database.
unlink($dir . '/setup-complete.lock');
try {
    setup_install($input);
    throw new RuntimeException('Expected existing-account rejection');
} catch (HttpError $e) {
    installation_check($e->status === 409, 'Existing accounts prevent reprovisioning without a marker');
}
installation_check(count($store->list('users')) === 2, 'Neither refused run changes the account count');
echo "PASS: $count first-install MySQL/account/lock checks (disposable schema only)\n";
