<?php
// Offline cPanel layout, configuration, installer guard and asset regressions.
require dirname(__DIR__) . '/src/php/bootstrap.php';
require application_root() . '/src/php/installation.php';
set_error_handler(function ($severity, $message, $file, $line) {
    throw new ErrorException($message, 0, $severity, $file, $line);
});
$count = 0;
function deployment_check(bool $ok, string $message): void
{
    global $count;
    if (!$ok) {
        throw new RuntimeException('FAIL: ' . $message);
    }
    $count++;
}
function deployment_reject(callable $fn, string $message): void
{
    try {
        $fn();
    } catch (RuntimeException) {
        deployment_check(true, $message);
        return;
    }
    throw new RuntimeException('FAIL: expected rejection: ' . $message);
}
$root = dirname(application_root());
deployment_check(basename(application_root()) === 'alpha-school', 'Private application has the ready-to-upload name');
deployment_check(public_root() === $root . '/public_html', 'No cPanel username or edited document-root path needed');
foreach (['index.php', '.htaccess', 'setup.php', 'diagnose.php', 'css/main.css', 'css/portal.css', 'css/setup.css', 'js/main.js', 'js/portal.js', 'fonts/fonts.css', 'offline.html', 'service-worker.js', 'img/logo-96.png'] as $name) {
    deployment_check(is_file(public_root() . '/' . $name), 'Public asset exists: ' . $name);
}
foreach (['config.php', '.env', 'src', 'scripts', 'data/seed.json', 'private'] as $name) {
    deployment_check(!file_exists(public_root() . '/' . $name), 'Private file is not shipped in public_html: ' . $name);
}
$example = require application_root() . '/config.example.php';
deployment_check($example['DATA_DIR'] === application_root() . '/private', 'Private storage path is automatic');
deployment_check($example['SETUP_TOKEN'] === '' && $example['DIAGNOSTIC_TOKEN'] === '', 'No default installation or diagnostics token');
$htaccess = (string) file_get_contents(public_root() . '/.htaccess');
deployment_check((bool) preg_match('/\^\(\?:index\|setup\|diagnose\)/', $htaccess), 'Rewrite allowlist reaches the protected diagnostics page');
deployment_check($example['MYSQL_PASSWORD'] === '' && $example['SMTP_PASSWORD'] === '', 'No supplied passwords');

// Plain-PHP configuration keeps literal secrets and operator/test overrides.
putenv('CPANEL_TEST_SECRET');
putenv('CPANEL_TEST_BOOL');
putenv('CPANEL_TEST_FALSE');
putenv('CPANEL_TEST_OVERRIDE=operator');
apply_private_settings([
    'CPANEL_TEST_SECRET' => "  quote' and \\ slash & dollar$  ",
    'CPANEL_TEST_BOOL' => true, 'CPANEL_TEST_FALSE' => false,
    'CPANEL_TEST_OVERRIDE' => 'file',
]);
deployment_check(env_value('CPANEL_TEST_SECRET') === "  quote' and \\ slash & dollar$  ", 'PHP configuration preserves literal password characters');
deployment_check(env_value('CPANEL_TEST_BOOL') === 'true' && env_value('CPANEL_TEST_FALSE') === 'false', 'PHP booleans map to approval flags correctly');
deployment_check(env_value('CPANEL_TEST_OVERRIDE') === 'operator', 'Explicit operator environment takes precedence');
deployment_reject(fn() => apply_private_settings(['BAD-KEY' => 'x']), 'Invalid configuration key refused');
deployment_reject(fn() => apply_private_settings(['BAD_VALUE' => []]), 'Non-scalar configuration refused');
$dir = application_root() . '/.runtime/cpanel-' . bin2hex(random_bytes(6));
mkdir($dir, 0700, true);
putenv('DATA_DIR=' . $dir);
putenv('STORAGE_DRIVER=local');
deployment_check(setup_storage_directory() === realpath($dir), 'Installer uses private disk');
putenv('DATA_DIR=' . public_root());
deployment_reject(fn() => setup_storage_directory(), 'Installer rejects a public data directory');
putenv('DATA_DIR=' . $dir);
putenv('SETUP_TOKEN=');
deployment_check(!setup_token_matches(''), 'Blank token never unlocks installer');
putenv('SETUP_TOKEN=short');
deployment_check(!setup_token_matches('short'), 'Short token never unlocks installer');
$token = bin2hex(random_bytes(24));
putenv('SETUP_TOKEN=' . $token);
deployment_check(setup_token_matches($token), 'Random configured token unlocks');
deployment_check(!setup_token_matches('wrong'), 'Wrong token rejected');
deployment_check(!setup_token_matches(str_repeat('x', 513)), 'Oversized token rejected');
$_SERVER = ['HTTP_HOST' => 'school.example', 'REMOTE_ADDR' => '192.0.2.2'];
putenv('APP_ENV=production');
putenv('TRUST_PROXY_HOPS=0');
deployment_check(!setup_transport_allowed(), 'Production HTTP setup refused');
$_SERVER['HTTP_X_FORWARDED_PROTO'] = 'https';
deployment_check(!setup_transport_allowed(), 'Untrusted forwarded protocol refused');
$_SERVER['HTTPS'] = 'on';
deployment_check(setup_transport_allowed(), 'Direct HTTPS setup allowed');
unset($_SERVER['HTTPS']);
putenv('TRUST_PROXY_HOPS=1');
deployment_check(setup_transport_allowed(), 'Explicit trusted HTTPS proxy supported');
putenv('TRUST_PROXY_HOPS=0');
putenv('APP_ENV=development');
$_SERVER = ['HTTP_HOST' => 'localhost:3000', 'REMOTE_ADDR' => '127.0.0.1'];
deployment_check(setup_transport_allowed(), 'Loopback development works without SSL');
$_SERVER['REMOTE_ADDR'] = '192.0.2.2';
deployment_check(!setup_transport_allowed(), 'Development setting cannot expose HTTP installer remotely');
$csrf = bin2hex(random_bytes(32));
deployment_check(setup_csrf_matches(['csrf' => $csrf], ['csrf' => $csrf]), 'CSRF token accepted');
deployment_check(!setup_csrf_matches(['csrf' => 'bad'], ['csrf' => $csrf]), 'Wrong CSRF token refused');
deployment_check(!setup_csrf_matches(['csrf' => ['bad']], ['csrf' => $csrf]), 'Array CSRF input refused');
for ($i = 0; $i < 10; $i++) {
    deployment_check(setup_attempt_allowed($dir, 100), 'Allowed token attempt ' . $i);
}
deployment_check(!setup_attempt_allowed($dir, 101), 'Eleventh attempt blocked across sessions');
deployment_check(setup_attempt_allowed($dir, 1001), 'Attempt window resets after 15 minutes');
deployment_check((fileperms($dir . '/setup-attempts.json') & 0077) === 0, 'Token-attempt state is private');
$input = [
    'approved' => 'yes', 'approval_reference' => 'SYNTHETIC-APPOINTMENT',
    'admin_username' => 'system-admin', 'admin_name' => 'Synthetic Administrator',
    'admin_password' => 'Synthetic-Admin-123!', 'admin_confirm' => 'Synthetic-Admin-123!',
    'head_username' => 'school-head', 'head_name' => 'Synthetic Head Teacher',
    'head_password' => 'Synthetic-Head-123!', 'head_confirm' => 'Synthetic-Head-123!',
];
[$users, $reference] = setup_accounts($input);
deployment_check($users[0]['roles'] === ['system_admin'] && $users[1]['roles'] === ['head_teacher'], 'Installer creates only explicit approved authorities');
deployment_check($users[0]['mustChangePassword'] && $users[1]['mustChangePassword'], 'First sign-in requires password change');
deployment_check(!str_contains(json_encode($users), 'Synthetic-Admin-123!'), 'No plaintext password stored in account records');
deployment_reject(fn() => setup_accounts(array_replace($input, ['approved' => 'no'])), 'Owner approval required');
deployment_reject(fn() => setup_accounts(array_replace($input, ['approval_reference' => ''])), 'Appointment reference required');
deployment_reject(fn() => setup_accounts(array_replace($input, ['head_username' => 'system-admin'])), 'Duplicate usernames refused');
deployment_reject(fn() => setup_accounts(array_replace($input, ['admin_confirm' => 'wrong'])), 'Password confirmation checked');
deployment_reject(fn() => setup_accounts(array_replace($input, ['admin_password' => 'weak', 'admin_confirm' => 'weak'])), 'Existing password policy preserved');
deployment_reject(fn() => setup_accounts(array_replace($input, ['head_password' => $input['admin_password'], 'head_confirm' => $input['admin_password']])), 'Distinct account passwords required');
// Cookie/session behavior is covered in the HTTP smoke check; no live DB here.
foreach (['/css/main.css', '/js/main.js', '/img/logo-96.png'] as $asset) {
    ob_start();
    $ok = serve_asset($asset);
    $bytes = ob_get_clean();
    deployment_check($ok && strlen($bytes) > 0, 'PHP asset fallback reads new public_html path: ' . $asset);
}
ob_start();
$ok = serve_asset('/../alpha-school/config.example.php');
ob_end_clean();
deployment_check(!$ok, 'Asset traversal cannot read private config');

// Owner-only diagnostics: token-gated, read-only and never leaks a secret.
putenv('DIAGNOSTIC_TOKEN=');
deployment_check(!diagnostics_enabled(), 'Blank diagnostics token disables the page');
putenv('DIAGNOSTIC_TOKEN=short');
deployment_check(!diagnostics_enabled(), 'Short diagnostics token disables the page');
deployment_check(!diagnostic_token_matches('short'), 'Short diagnostics token never unlocks');
$diagnosticToken = bin2hex(random_bytes(24));
putenv('DIAGNOSTIC_TOKEN=' . $diagnosticToken);
deployment_check(diagnostics_enabled(), 'Random configured diagnostics token enables the page');
deployment_check(diagnostic_token_matches($diagnosticToken), 'Correct diagnostics token unlocks');
deployment_check(!diagnostic_token_matches('wrong'), 'Wrong diagnostics token rejected');
deployment_check(!diagnostic_token_matches(str_repeat('x', 513)), 'Oversized diagnostics token rejected');
deployment_check($diagnosticToken !== env_value('SETUP_TOKEN'), 'Diagnostics and installer tokens are independent');
$attempts = application_root() . '/.runtime/cpanel-diag-' . bin2hex(random_bytes(6));
mkdir($attempts, 0700, true);
for ($i = 0; $i < 10; $i++) {
    deployment_check(diagnostic_attempt_allowed($attempts, 200), 'Allowed diagnostics attempt ' . $i);
}
deployment_check(!diagnostic_attempt_allowed($attempts, 201), 'Eleventh diagnostics attempt blocked across sessions');
deployment_check(diagnostic_attempt_allowed($attempts, 1201), 'Diagnostics attempt window resets after 15 minutes');
deployment_check((fileperms($attempts . '/diagnostic-attempts.json') & 0077) === 0, 'Diagnostics attempt state is private');

putenv('MYSQL_PASSWORD=Sup3r-Secret-Database-Pass!');
$masked = diagnostic_mask('SQLSTATE[HY000] [1045] Access denied for user \'account_user\'@\'localhost\' (using password: YES) secret=Sup3r-Secret-Database-Pass!');
deployment_check(!str_contains($masked, 'Sup3r-Secret-Database-Pass!'), 'Database password never reaches the diagnostics report');
deployment_check(str_contains($masked, 'Access denied'), 'Diagnostics keep the useful part of a database error');
deployment_check(!str_contains(diagnostic_mask('connect to mysql://account_user:Sup3r-Secret-Database-Pass!@localhost/db'), 'Sup3r-Secret-Database-Pass!'), 'Credentials in a connection string are redacted');
deployment_check(!str_contains(diagnostic_mask('key ' . str_repeat('a', 64) . ' leaked'), str_repeat('a', 64)), 'Key-shaped values are redacted');
deployment_check(str_contains(diagnostic_database_hint('Access denied for user x'), 'MySQL Databases'), 'Access-denied failures explain the cPanel fix');
deployment_check(str_contains(diagnostic_database_hint('Unknown database \'account_alpha_school\''), 'Database Wizard'), 'Missing-database failures explain the cPanel fix');
deployment_check(str_contains(diagnostic_database_hint('Connection refused'), 'localhost'), 'Refused connections explain the usual cPanel host');
deployment_check(diagnostic_setting('MYSQL_PASSWORD', 'Database password')['status'] === 'set', 'A set secret is reported without printing its value');
deployment_check(!str_contains(diagnostic_setting('MYSQL_PASSWORD', 'Database password')['detail'], 'Sup3r-Secret-Database-Pass!'), 'A set secret detail shows only its length');
deployment_check(diagnostic_setting('SMTP_HOST', 'SMTP host')['status'] === 'empty', 'An empty setting is reported as empty');

$failure = new RuntimeException('Boom Sup3r-Secret-Database-Pass!');
diagnostic_record_failure('/', 503, $failure);
$recorded = diagnostic_recent_failures(5);
deployment_check($recorded !== [], 'Website failures are recorded privately for the owner');
deployment_check(!str_contains(json_encode($recorded), 'Sup3r-Secret-Database-Pass!'), 'Recorded failures never contain a secret');
deployment_check(($recorded[0]['route'] ?? '') === '/' && ($recorded[0]['status'] ?? 0) === 503, 'Recorded failures keep the route and status');
$before = count(diagnostic_recent_failures(DIAGNOSTIC_MAX_ENTRIES));
diagnostic_record_failure('/news', 404, new RuntimeException('Ignored'));
deployment_check(count(diagnostic_recent_failures(DIAGNOSTIC_MAX_ENTRIES)) === $before, 'Client errors are not recorded as failures');
deployment_check((fileperms(diagnostic_private_directory() . '/diagnostic-log.json') & 0077) === 0, 'Recorded failures stay private');
deployment_check(!str_contains(json_encode(diagnostic_recent_failures(50)), 'Sup3r-Secret-Database-Pass!'), 'The whole recorded trail is secret-free');

// The report must survive a deliberately broken production configuration and
// name the failing requirement instead of a generic page. Reserved RFC 2606
// .invalid names keep this offline test away from any real MySQL or SMTP
// server, so it can never create tables in a schema another test needs empty.
$diagnosticEnv = [
    'APP_ENV' => 'production',
    'MYSQL_HOST' => 'diagnostics.invalid',
    'MYSQL_PORT' => '3306',
    'MYSQL_DB_NAME' => 'alpha_diagnostics_offline',
    'MYSQL_USER' => 'alpha_diagnostics',
    'MYSQL_PASSWORD' => 'synthetic-ci-only',
    'MYSQL_SSL_MODE' => 'disabled',
    'RETENTION_POLICY_APPROVED' => 'true',
    'FORM_RETENTION_DAYS' => '90',
    'ADMISSION_RETENTION_DAYS' => '365',
    'SMTP_HOST' => 'smtp.invalid',
    'SMTP_PORT' => '587',
    'SMTP_SECURITY' => 'starttls',
    'SMTP_USERNAME' => 'alerts',
    'SMTP_PASSWORD' => 'synthetic-smtp-only',
    'SMTP_FROM_EMAIL' => 'alerts@school.example',
    'OFFICE_ALERT_EMAIL' => 'office@school.example',
    'PRIVACY_ALERT_EMAIL' => 'privacy@school.example',
    'STORAGE_ENCRYPTION_KEY' => '',
    'AUDIT_HMAC_KEY' => '',
    // Reproduces the exact 'Setup is disabled' state the owner reported.
    'SETUP_TOKEN' => '',
    'DIAGNOSTIC_TOKEN' => '',
];
$applyDiagnosticEnv = function (array $overrides) use ($diagnosticEnv): void {
    foreach (array_replace($diagnosticEnv, $overrides) as $key => $value) {
        putenv($key . '=' . $value);
    }
};
$assertReport = function (array $report): array {
    $named = [];
    foreach ($report as $row) {
        deployment_check(in_array($row['status'], ['ok', 'warn', 'fail', 'info'], true), 'Every diagnostics row has a usable status');
        deployment_check($row['detail'] !== '', 'Every diagnostics row explains itself');
        deployment_check(!str_contains(json_encode($row), 'synthetic-smtp-only'), 'The report never prints the SMTP password: ' . $row['name']);
        deployment_check(!str_contains(json_encode($row), 'synthetic-ci-only'), 'The report never prints the database password: ' . $row['name']);
        if ($row['status'] === 'fail') {
            $named[$row['name']] = $row;
        }
    }
    return $named;
};

// Scenario A: the empty BASE_URL a fresh cPanel upload usually has.
$applyDiagnosticEnv(['BASE_URL' => '']);
$report = diagnostic_report();
deployment_check($report !== [], 'Diagnostics still report on a broken production configuration');
$named = $assertReport($report);
deployment_check(isset($named['BASE_URL']), 'The report names the missing production BASE_URL behind the generic failure');
deployment_check(str_contains($named['BASE_URL']['fix'] ?? '', 'https://'), 'The BASE_URL failure explains the value to set');
deployment_check(isset($named['Full configuration accepted']), 'The report also names the configuration refusal itself');
$stages = array_values(array_unique(array_column($report, 'stage')));
foreach (['PHP runtime', 'Folder layout', 'Private configuration', 'MySQL', 'Encrypted storage', 'Email delivery', 'Installation state', 'Recorded failures'] as $expected) {
    deployment_check(in_array($expected, $stages, true), 'The report covers the ' . $expected . ' stage');
}

// Scenario B: configuration accepted, but MySQL refused before any network
// attempt. A remote host with TLS disabled is refused deterministically, so
// this offline test never depends on a reachable database server.
$applyDiagnosticEnv(['BASE_URL' => 'https://school.example', 'MYSQL_HOST' => 'db.school.example']);
$report = diagnostic_report();
$named = $assertReport($report);
deployment_check(!isset($named['BASE_URL']), 'A valid production BASE_URL passes');
deployment_check(!isset($named['Full configuration accepted']), 'A complete production configuration is accepted');
deployment_check(isset($named['MySQL TLS mode']), 'The report names the MySQL TLS refusal behind the generic failure');
deployment_check(str_contains($named['MySQL TLS mode']['fix'] ?? '', 'MYSQL_SSL_MODE'), 'A MySQL TLS refusal names the setting to change');
deployment_check(!isset($named['MySQL settings']), 'Complete MySQL settings pass validation');
$byName = [];
foreach ($report as $row) {
    $byName[$row['name']] = $row;
}
deployment_check(($byName['Encrypt, store and read back']['status'] ?? '') === 'ok', 'The encrypted storage round trip is verified and cleaned up');
// Reachability depends on the host's outbound mail policy, so only assert that
// the SMTP probe ran and reported a usable result.
deployment_check(in_array($byName['Reach the SMTP server']['status'] ?? '', ['ok', 'fail'], true), 'The SMTP server probe always reports a result');
deployment_check(($byName['SMTP settings']['status'] ?? '') === 'ok', 'Complete SMTP settings pass validation');
deployment_check(($byName['SETUP_TOKEN']['status'] ?? '') === 'fail', 'An empty SETUP_TOKEN is reported, which is what disables setup.php');
deployment_check(is_dir(env_value('DATA_DIR') . '/storage'), 'Diagnostics created only its own private storage folder');

putenv('APP_ENV=development');
putenv('BASE_URL=');
putenv('DIAGNOSTIC_TOKEN=');
foreach (array_keys($diagnosticEnv) as $cleared) {
    putenv($cleared);
}

// No source file on either side of the deployment contains unsupported PHP syntax.
foreach ([application_root(), public_root()] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/.runtime/')) {
            token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
        }
    }
}
echo "PASS: $count cPanel layout, installer security and public asset checks (no database or email)\n";
