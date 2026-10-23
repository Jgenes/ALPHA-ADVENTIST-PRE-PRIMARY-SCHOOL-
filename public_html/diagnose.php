<?php
/**
 * Owner-only deployment diagnostics for cPanel.
 *
 * Use this when the website shows "The service is temporarily unavailable" or
 * setup.php shows a generic failure. It reports which specific requirement is
 * not met and how to fix it. It is read-only: it never creates, changes or
 * deletes a school record and never sends email.
 *
 * Delete this file once the website is healthy, exactly like setup.php.
 */
ini_set('display_errors', '0');
$private = dirname(__DIR__) . '/alpha-school';
if (!is_file($private . '/src/php/bootstrap.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<h1>Upload incomplete</h1><p>Upload the alpha-school folder beside public_html, not inside it. See the cPanel installation guide.</p>';
    exit;
}
require $private . '/src/php/bootstrap.php';
// Reuses the installer's HTTPS, CSRF and same-origin guards.
require $private . '/src/php/installation.php';
header('Content-Type: text/html; charset=utf-8');
header('Cache-Control: no-store, max-age=0');
header('Pragma: no-cache');
header('X-Robots-Tag: noindex, nofollow');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: no-referrer');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'self'; form-action 'self'; base-uri 'none'; frame-ancestors 'none'");
$stage = 'locked';
$error = '';
$report = [];
$privateDir = null;
$sessionReady = false;
try {
    load_environment();
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'POST'], true)) {
        throw new HttpError(405, 'Use GET or POST.');
    }
    if (!setup_transport_allowed()) {
        throw new HttpError(403, 'Open diagnostics over HTTPS. Ask your host to enable SSL first.');
    }
    $privateDir = diagnostic_private_directory();
    if (!$privateDir) {
        throw new RuntimeException('No writable private directory is available, so diagnostics cannot be protected by an attempt limit. Make alpha-school/private writable (chmod 700) and try again.');
    }
    if (!diagnostics_enabled()) {
        http_response_code(403);
        $error = 'Diagnostics are disabled. In alpha-school/config.php set DIAGNOSTIC_TOKEN to a unique random value of at least 32 characters (use the cPanel password generator), save the file, then reload this page. Never put the token in a URL.';
    } else {
        // Diagnostics use their own session, separate from portal sign-in and setup.
        $sessions = $privateDir . '/diagnostic-sessions';
        if (is_link($sessions) || (!is_dir($sessions) && !mkdir($sessions, 0700, true))) {
            throw new RuntimeException('Cannot create private diagnostic sessions. Check that alpha-school/private is writable.');
        }
        chmod($sessions, 0700);
        session_save_path($sessions);
        session_name(setup_https() ? '__Host-alpha_diag' : 'alpha_diag_local');
        ini_set('session.use_strict_mode', '1');
        ini_set('session.use_only_cookies', '1');
        session_set_cookie_params(['lifetime' => 0, 'path' => '/', 'secure' => setup_https(), 'httponly' => true, 'samesite' => 'Strict']);
        if (!session_start()) {
            throw new RuntimeException('Cannot start a private diagnostic session. Allow first-party cookies and open this page directly, not inside a preview frame.');
        }
        $_SESSION['csrf'] ??= bin2hex(random_bytes(32));
        $sessionReady = true;
        $tokenHash = hash('sha256', env_value('DIAGNOSTIC_TOKEN'));
        $unlocked = ($_SESSION['until'] ?? 0) > time() && hash_equals($tokenHash, $_SESSION['owner'] ?? '');
        if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
            if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 12000) {
                throw new HttpError(413, 'Diagnostics submission is too large.');
            }
            if (!setup_csrf_matches($_POST, $_SESSION)) {
                throw new HttpError(403, 'This page expired. Refresh it and try again.');
            }
            a_platform_router_sameOrigin(['headers' => request_headers()]);
            if (!$unlocked) {
                if (!diagnostic_attempt_allowed($privateDir)) {
                    header('Retry-After: 900');
                    throw new HttpError(429, 'Too many diagnostic attempts. Wait 15 minutes.');
                }
                if (!diagnostic_token_matches(is_string($_POST['token'] ?? null) ? $_POST['token'] : '')) {
                    throw new HttpError(403, 'Invalid diagnostic token.');
                }
                session_regenerate_id(true);
                $_SESSION = ['csrf' => bin2hex(random_bytes(32)), 'owner' => $tokenHash, 'until' => time() + 900];
                $unlocked = true;
            }
            if (($_POST['action'] ?? '') === 'run' && $unlocked) {
                $report = diagnostic_report();
                $stage = 'report';
            } else {
                $stage = 'unlocked';
            }
        } else {
            $stage = $unlocked ? 'unlocked' : 'token';
        }
    }
} catch (Throwable $e) {
    http_response_code($e instanceof HttpError ? $e->status : 503);
    $report = [];
    if ($sessionReady && $stage !== 'report' && $stage !== 'unlocked') {
        // The protected session works, so keep the token form on screen and
        // let the owner correct a wrong token without reloading the page.
        $stage = 'token';
    } elseif ($stage !== 'token') {
        // No usable protected session, so do not render a token form.
        $stage = 'locked';
    }
    // Detailed infrastructure errors and credentials are never printed raw.
    $error = $e instanceof HttpError
        ? $e->getMessage()
        : diagnostic_mask($e->getMessage() ?: 'Diagnostics could not run.');
    error_log('[alpha diagnostics] ' . get_class($e) . ' at ' . basename($e->getFile()) . ':' . $e->getLine());
}
function diagnostic_counts(array $report): array
{
    $counts = ['fail' => 0, 'warn' => 0, 'ok' => 0, 'info' => 0];
    foreach ($report as $row) {
        $key = $row['status'] ?? 'info';
        $counts[$key] = ($counts[$key] ?? 0) + 1;
    }
    return $counts;
}
function diagnostic_status_label(string $status): string
{
    return ['fail' => 'FAIL', 'warn' => 'CHECK', 'ok' => 'OK', 'info' => 'INFO'][$status] ?? 'INFO';
}
function diagnostic_render(array $rows, string $heading): void
{
    if (!$rows) {
        return;
    }
    echo '<h2>' . esc($heading) . '</h2><dl class="diag">';
    foreach ($rows as $row) {
        echo '<div class="diag__row diag--' . esc($row['status']) . '">' .
            '<dt><span class="diag__badge diag__badge--' . esc($row['status']) . '">' . esc(diagnostic_status_label($row['status'])) . '</span>' . esc($row['name']) . '</dt>' .
            '<dd>' . nl2br(esc($row['detail'])) .
            ($row['fix'] !== '' ? '<p class="diag__fix"><strong>Fix:</strong> ' . nl2br(esc($row['fix'])) . '</p>' : '') .
            '</dd></div>';
    }
    echo '</dl>';
}
$counts = diagnostic_counts($report);
$grouped = [];
foreach ($report as $row) {
    $grouped[$row['stage']][] = $row;
}
$firstFailure = null;
foreach ($report as $row) {
    if ($row['status'] === 'fail') {
        $firstFailure = $row;
        break;
    }
}
?>
<!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Alpha School — deployment diagnostics</title><link rel="stylesheet" href="/css/setup.css"></head>
<body><main>
<h1>Deployment diagnostics</h1>
<p>Read-only checks for the hosting account owner. This page never changes a school record and never sends email. Delete <code>public_html/diagnose.php</code> once the website is healthy.</p>
<?php if ($error): ?><p role="alert" class="error"><?= esc($error) ?></p><?php endif; ?>
<?php if ($stage === 'token'): ?>
<form method="post" action="/diagnose.php">
<input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf'] ?? '') ?>">
<input type="hidden" name="action" value="run">
<label>Private diagnostic token<input required name="token" type="password" minlength="32" maxlength="512" autocomplete="off"></label>
<p>Enter the <code>DIAGNOSTIC_TOKEN</code> you set in <code>alpha-school/config.php</code>. Do not put it in a URL or share it.</p>
<button type="submit">Run the checks</button>
</form>
<?php elseif ($stage === 'locked' && $error === ''): ?>
<p>This page could not open a protected session, so no token form is shown. Fix the problem above, then reload <code>/diagnose.php</code>.</p>
<?php elseif ($stage === 'unlocked'): ?>
<p>Unlocked for 15 minutes. The checks connect to your database and email server and can take a few seconds.</p>
<form method="post" action="/diagnose.php">
<input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="run">
<button type="submit">Run the checks now</button>
</form>
<?php elseif ($stage === 'report'): ?>
<p class="diag__summary" role="status">
<strong><?= $counts['fail'] ?> failure<?= $counts['fail'] === 1 ? '' : 's' ?></strong>,
<?= $counts['warn'] ?> to review, <?= $counts['ok'] ?> passed, <?= $counts['info'] ?> informational.
</p>
<?php if ($counts['fail'] === 0): ?>
<p class="diag__good">Every requirement passed. If the website still fails, run the checks again immediately after loading the broken page so the failure is recorded, then read the <em>Recorded failures</em> section below.</p>
<?php elseif ($firstFailure): ?>
<div class="error">
<strong>Start here — <?= esc($firstFailure['stage']) ?> → <?= esc($firstFailure['name']) ?></strong><br>
<?= nl2br(esc($firstFailure['detail'])) ?>
<?php if ($firstFailure['fix'] !== ''): ?><br><strong>Fix:</strong> <?= nl2br(esc($firstFailure['fix'])) ?><?php endif; ?>
</div>
<?php endif; ?>
<?php foreach ($grouped as $heading => $rows): ?>
<?php diagnostic_render($rows, $heading); ?>
<?php endforeach; ?>
<form method="post" action="/diagnose.php">
<input type="hidden" name="csrf" value="<?= esc($_SESSION['csrf']) ?>">
<input type="hidden" name="action" value="run">
<button type="submit">Run the checks again</button>
</form>
<p><a href="/">Open the school website</a><?php if (is_file(public_root() . '/setup.php')): ?> · <a href="/setup.php">Open the first-install setup</a><?php endif; ?></p>
<?php endif; ?>
</main></body></html>
