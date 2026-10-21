<?php
// Local development only. Production uses Apache and public_html/.htaccess.
$path = parse_url($_SERVER["REQUEST_URI"] ?? "/", PHP_URL_PATH);
if ($path === "/setup.php") {
    require __DIR__ . "/public_html/setup.php";
} else {
    require __DIR__ . "/public_html/index.php";
}
