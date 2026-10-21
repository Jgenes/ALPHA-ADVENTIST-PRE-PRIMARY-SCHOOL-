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
foreach (['index.php', '.htaccess', 'setup.php', 'css/main.css', 'css/portal.css', 'css/setup.css', 'js/main.js', 'js/portal.js', 'fonts/fonts.css', 'offline.html', 'service-worker.js', 'img/logo-96.png'] as $name) {
    deployment_check(is_file(public_root() . '/' . $name), 'Public asset exists: ' . $name);
}
foreach (['config.php', '.env', 'src', 'scripts', 'data/seed.json', 'private'] as $name) {
    deployment_check(!file_exists(public_root() . '/' . $name), 'Private file is not shipped in public_html: ' . $name);
}
$example = require application_root() . '/config.example.php';
deployment_check($example['DATA_DIR'] === application_root() . '/private', 'Private storage path is automatic');
deployment_check($example['SETUP_TOKEN'] === '', 'No default installation token');
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
// No source file on either side of the deployment contains unsupported PHP syntax.
foreach ([application_root(), public_root()] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->getExtension() === 'php' && !str_contains($file->getPathname(), '/.runtime/')) {
            token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
        }
    }
}
echo "PASS: $count cPanel layout, installer security and public asset checks (no database or email)\n";
