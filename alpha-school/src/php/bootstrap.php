<?php
require_once __DIR__ . "/runtime.php";
require_once __DIR__ . "/support.php";
require_once __DIR__ . "/integrations.php";
require_once __DIR__ . "/diagnostics.php";
define(
    "SEED",
    json_decode(
        file_get_contents(dirname(__DIR__, 2) . "/data/seed.json"),
        true,
        512,
        JSON_THROW_ON_ERROR,
    ),
);
require_once __DIR__ . "/collections.php";
require_once __DIR__ . "/store.php";
require_once __DIR__ . "/school-details.php";
require_once __DIR__ . "/auth.php";
require_once __DIR__ . "/files.php";
foreach (
    [
        "access",
        "validation",
        "workflows",
        "family",
        "applications",
        "people",
        "documents",
        "communications",
        "privacy",
        "overview",
        "service",
    ]
    as $name
) {
    require_once __DIR__ . "/platform_" . $name . ".php";
}
require_once __DIR__ . "/layout.php";
foreach (glob(__DIR__ . "/pages_*.php") as $f) {
    require_once $f;
}
class Platform
{
    use PlatformCore,
        a_platform_people_Methods,
        a_platform_documents_Methods,
        a_platform_communications_Methods,
        a_platform_privacy_Methods,
        a_platform_family_Methods,
        a_platform_applications_Methods,
        a_platform_overview_Methods;
    public function __construct(
        public Store $store,
        public array $config,
        public Files $files,
    ) {}
}
function a_node_crypto_randomBytes($n): RandomBytes
{
    return js_crypto_randomBytes($n);
}
require_once __DIR__ . "/platform_router.php";
require_once __DIR__ . "/platform_jobs.php";
require_once __DIR__ . "/http.php";
