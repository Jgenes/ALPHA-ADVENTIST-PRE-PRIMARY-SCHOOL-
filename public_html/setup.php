<?php
// Optional first-install helper. Delete this file after successful installation.
ini_set('display_errors', '0');
require dirname(__DIR__) . '/alpha-school/src/php/bootstrap.php';
require application_root() . '/src/php/installation.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$stage = 'disabled';
$error = '';
try {
    load_environment();
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
        throw new HttpError(405, 'Use GET or POST.');
    }
    if (!setup_transport_allowed()) {
        throw new HttpError(403, 'Open setup over HTTPS. Ask your host to enable SSL first.');
    }
    if (strlen(env_value('SETUP_TOKEN')) < 32) {
        http_response_code(403);
        $error = 'Setup is disabled. The hosting account owner must configure the private config.php and a random SETUP_TOKEN of at least 32 characters.';
    } else {
        $dir = setup_storage_directory();
        if (file_exists($dir . '/setup-complete.lock')) {
            http_response_code(410);
            $stage = 'complete';
        } else {
            // Setup sessions are separate from normal portal authentication.
            $sessions = $dir . '/setup-sessions';
            if (is_link($sessions) || (!is_dir($sessions) && !mkdir($sessions, 0700))) {
                throw new RuntimeException('Cannot create private setup sessions.');
            }
            chmod($sessions, 0700);
            session_save_path($sessions);
            session_name(setup_https() ? '__Host-alpha_setup' : 'alpha_setup_local');
            ini_set('session.use_strict_mode', '1');
            ini_set('session.use_only_cookies', '1');
            session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => setup_https(), 'httponly' => true, 'samesite' => 'Strict']);
            if (!session_start()) {
                throw new RuntimeException('Cannot start private setup session.');
            }
            $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
            $tokenHash = hash('sha256', env_value('SETUP_TOKEN'));
            $unlocked = ($_SESSION['until'] ?? 0) > time() && hash_equals($tokenHash, $_SESSION['owner'] ?? '');
            $stage = $unlocked ? 'accounts' : 'token';
            if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
                if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 12000) {
                    throw new HttpError(413, 'Setup submission is too large.');
                }
                if (!setup_csrf_matches($_POST, $_SESSION)) {
                    throw new HttpError(403, 'Setup session expired. Refresh the page and try again.');
                }
                a_platform_router_sameOrigin(['headers' => request_headers()]);
                if (!$unlocked) {
                    if (!setup_attempt_allowed($dir)) {
                        header('Retry-After: 900');
                        throw new HttpError(429, 'Too many setup attempts. Wait 15 minutes.');
                    }
                    if (!setup_token_matches(is_string($_POST['token'] ?? null) ? $_POST['token'] : '')) {
                        throw new HttpError(403, 'Invalid setup token.');
                    }
                    session_regenerate_id(true);
                    $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'owner' => $tokenHash, 'until' => time() + 900];
                    $stage = 'accounts';
                } else {
                    setup_install($_POST);
                    $_SESSION = [];
                    session_destroy();
                    $stage = 'success';
                }
            }
        }
    }
} catch (Throwable $e) {
    http_response_code($e instanceof HttpError ? $e->status : 503);
    // Detailed infrastructure errors/credentials are never printed to the browser.
    $error = $e instanceof HttpError ? $e->getMessage() : 'Setup could not finish. Check private config.php, PHP extensions, MySQL 8 permissions, SMTP and approved retention settings. Consult the protected server error log or hosting support.';
    if (!($e instanceof HttpError)) {
        error_log('[alpha setup] ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
    }
}
function setup_field(string $name, string $label, string $type = 'text'): void
{
    echo '<label>' . esc($label) . '<input required name="' . esc($name) . '" type="' . esc($type) . '" maxlength="' . ($type === 'password' ? '128' : '120') . '" autocomplete="' . ($type === 'password' ? 'new-password' : 'off') . '"></label>';
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Alpha School — first installation</title><link rel="stylesheet" href="/css/setup.css"></head>
<body><main>
<h1>Alpha School setup</h1>
<p>One-time installation for the approved school owner. Your database settings remain in the private <code>alpha-school/config.php</code> file.</p>
<?php if ($error): ?><p role="alert" class="error"><?= esc($error) ?></p><?php endif; ?>
<?php if ($stage === 'token'): ?>
<form method="post" action="/setup.php">
<input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
<label>Private setup token<input required name="token" type="password" minlength="32" maxlength="512" autocomplete="off"></label>
<p>Enter the random SETUP_TOKEN you configured privately. Do not put it in a URL or share it.</p>
<button type="submit">Unlock installation</button>
</form>
<?php elseif ($stage === 'accounts'): ?>
<p>Database tables will be initialized automatically. No existing database is renamed or dropped, and existing accounts cannot be overwritten.</p>
<form method="post" action="/setup.php">
<input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
<?php foreach (['admin' => 'System administrator', 'head' => 'Head teacher'] as $prefix => $title): ?>
<fieldset><legend><?= esc($title) ?></legend>
<?php setup_field($prefix . '_name', 'Full name'); setup_field($prefix . '_username', 'Username (3–64 lowercase letters, numbers, dots or hyphens)'); setup_field($prefix . '_password', 'Unique password (12–128 characters, upper/lowercase, number and symbol)', 'password'); setup_field($prefix . '_confirm', 'Confirm password', 'password'); ?>
</fieldset>
<?php endforeach; ?>
<?php setup_field('approval_reference', 'School appointment approval reference'); ?>
<label class="check"><input required type="checkbox" name="approved" value="yes">The school has approved both appointments. I am authorized to create these accounts.</label>
<button type="submit">Initialize database and create accounts</button>
</form>
<?php elseif (in_array($stage, ['success', 'complete'], true)): ?>
<h2><?= $stage === 'success' ? 'Installation complete' : 'Setup is locked' ?></h2>
<p>Setup cannot create or reset accounts again. Delete <code>public_html/setup.php</code> and clear <code>SETUP_TOKEN</code> in your private configuration.</p>
<p>Sign in with an account you created. A password change is required at first sign-in. Configure the one-minute maintenance cron job described in the installation guide.</p>
<p><a href="/portal">Open portal sign-in</a> · <a href="/">Open school website</a></p>
<?php endif; ?>
</main></body></html>
