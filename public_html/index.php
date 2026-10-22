<?php
// Ready for cPanel: alpha-school and public_html are sibling directories.
ini_set('display_errors', '0');
$private = dirname(__DIR__) . '/alpha-school';
if (!is_file($private . '/src/php/bootstrap.php')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<h1>Upload incomplete</h1><p>Upload the alpha-school folder beside public_html, not inside it. See the cPanel installation guide.</p>';
    exit;
}
if (!is_file($private . '/config.php') && !is_file($private . '/.env') && !getenv('MYSQL_HOST') && !getenv('MYSQL_URL')) {
    http_response_code(503);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    echo '<h1>Configure your school website</h1><p>Copy alpha-school/config.example.php to alpha-school/config.php and enter your private hosting settings.</p><p>Then <a href="/setup.php">open first-install setup</a>.</p>';
    exit;
}
require $private . '/src/php/bootstrap.php';
handle_request();
