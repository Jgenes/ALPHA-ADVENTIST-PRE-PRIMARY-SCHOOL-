<?php
require dirname(__DIR__) . "/src/php/bootstrap.php";
$root = dirname(__DIR__);
$count = 0;
foreach (["src", "scripts", "test", "../public_html"] as $dir) {
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
            $root . "/" . $dir,
            FilesystemIterator::SKIP_DOTS,
        ),
    );
    foreach ($it as $file) {
        if ($file->getExtension() === "php") {
            token_get_all(file_get_contents($file->getPathname()), TOKEN_PARSE);
            $count++;
        }
    }
}
$required = ["PDO", "openssl", "mbstring", "gd"];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        throw new RuntimeException("Required extension missing: " . $ext);
    }
}
if (!in_array("mysql", PDO::getAvailableDrivers(), true)) {
    throw new RuntimeException("Install the pdo_mysql PHP extension.");
}
if (!extension_loaded("sodium")) {
    throw new RuntimeException("Install the sodium PHP extension for existing password compatibility.");
}
foreach (
    [
        "../public_html/css/main.css",
        "../public_html/css/portal.css",
        "../public_html/js/main.js",
        "../public_html/js/portal.js",
        "../public_html/fonts/fonts.css",
        "../public_html/index.php",
    ]
    as $f
) {
    if (!is_file($root . "/" . $f)) {
        throw new RuntimeException("Missing required file: " . $f);
    }
}
echo "Checked $count PHP files and required application assets.\n";
