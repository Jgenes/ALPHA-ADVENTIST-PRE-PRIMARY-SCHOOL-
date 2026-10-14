<?php
class NativeUrl
{
    public string $pathname;
    public string $host;
    public string $protocol;
    public JsMap $searchParams;
    public string $search;
    public function __construct($value, $base = "")
    {
        if (str_starts_with($value, "/")) {
            $value = ($base ?: "http://internal") . $value;
        }
        $p = parse_url($value);
        if (!$p || !isset($p["host"])) {
            throw new HttpError(400, "Invalid request URL.");
        }
        $this->pathname = $p["path"] ?? "/";
        $this->host = $p["host"] . (isset($p["port"]) ? ":" . $p["port"] : "");
        $this->protocol = ($p["scheme"] ?? "http") . ":";
        $this->search = isset($p["query"]) ? "?" . $p["query"] : "";
        parse_str($p["query"] ?? "", $q);
        $this->searchParams = new JsMap(
            array_map(fn($k, $v) => [$k, $v], array_keys($q), array_values($q)),
        );
    }
}
function request_headers(): array
{
    $r = [];
    foreach ($_SERVER as $k => $v) {
        if (str_starts_with($k, "HTTP_")) {
            $r[strtolower(str_replace("_", "-", substr($k, 5)))] = $v;
        }
    }
    foreach (
        ["CONTENT_TYPE" => "content-type", "CONTENT_LENGTH" => "content-length"]
        as $k => $h
    ) {
        if (isset($_SERVER[$k])) {
            $r[$h] = $_SERVER[$k];
        }
    }
    return $r;
}
function parse_form($req = null, $limit = 100000): array
{
    if ((int) ($_SERVER["CONTENT_LENGTH"] ?? 0) > $limit) {
        throw new HttpError(413, "Submission too large.");
    }
    $raw = file_get_contents("php://input", false, null, 0, $limit + 1);
    if (strlen($raw) > $limit) {
        throw new HttpError(413, "Submission too large.");
    }
    $type = $_SERVER["CONTENT_TYPE"] ?? "";
    if (str_starts_with($type, "application/json")) {
        try {
            $o = json_decode($raw, false, 512, JSON_THROW_ON_ERROR);
            if (!($o instanceof stdClass)) {
                throw new RuntimeException("Invalid object");
            }
            return json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            throw new HttpError(400, "Invalid JSON request.");
        }
    }
    if (str_starts_with($type, "application/x-www-form-urlencoded")) {
        parse_str($raw, $r);
        return $r;
    }
    throw new HttpError(415, "Use JSON or a form-encoded request.");
}
function a_platform_router_sameOrigin($req): void
{
    $h = $req["headers"];
    if (($h["sec-fetch-site"] ?? "") === "cross-site") {
        throw new HttpError(
            403,
            "Cross-site requests are not accepted.",
            "ORIGIN_FAILED",
        );
    }
    if (isset($h["origin"])) {
        $p = parse_url($h["origin"]);
        $host =
            ($p["host"] ?? "") . (isset($p["port"]) ? ":" . $p["port"] : "");
        if (
            !$p ||
            !in_array($p["scheme"] ?? "", ["http", "https"], true) ||
            $host !== ($h["host"] ?? "")
        ) {
            throw new HttpError(
                403,
                "Cross-site requests are not accepted.",
                "ORIGIN_FAILED",
            );
        }
    }
}
function response_json($data, $status = 200): void
{
    http_response_code($status);
    header("Content-Type: application/json; charset=utf-8");
    header("Cache-Control: no-store");
    header("X-Robots-Tag: noindex, nofollow");
    echo js_json_stringify($data);
}
function a_platform_router_json($res, $status, $data): void
{
    response_json($data, $status);
}
function a_platform_router_sendFile($res, $result, $inline = false): void
{
    $f = $result["file"];
    header("Content-Type: " . $f["mime"]);
    header("Content-Length: " . strlen($result["buffer"]));
    header(
        "Content-Disposition: " .
            ($inline ? "inline" : "attachment") .
            '; filename="' .
            preg_replace('/["\r\n]/', "_", $f["name"]) .
            '"',
    );
    header("Cache-Control: private, no-store, max-age=0");
    header("X-Robots-Tag: noindex, nofollow");
    echo $result["buffer"];
}
function security_headers($c, $nonce): void
{
    header("X-Content-Type-Options: nosniff");
    if ($c["environment"] !== "production") {
        header("X-Robots-Tag: noindex, nofollow");
    }
    header("Referrer-Policy: strict-origin-when-cross-origin");
    header(
        "Permissions-Policy: camera=(), microphone=(), geolocation=(), payment=()",
    );
    header("Cross-Origin-Resource-Policy: same-origin");
    $frames = $c["production"]
        ? "'self'"
        : "'self' https://arena.ai https://*.arena.ai https://*.e2b.app https://lmarena.ai https://*.lmarena.ai";
    header(
        "Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-$nonce'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; font-src 'self'; connect-src 'self'; object-src 'none'; base-uri 'self'; form-action 'self'; frame-src 'none'; frame-ancestors $frames; manifest-src 'self'; worker-src 'self'",
    );
    if ($c["production"] || ($_SERVER["HTTPS"] ?? "") === "on") {
        header("Strict-Transport-Security: max-age=31536000");
    }
}
function response_html($html, $status = 200): void
{
    http_response_code($status);
    header("Content-Type: text/html; charset=utf-8");
    header("Cache-Control: no-cache");
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "HEAD") {
        echo $html;
    }
}
function response_redirect($target, $status = 303): void
{
    http_response_code($status);
    header("Location: " . $target);
    header("Cache-Control: no-store");
}
const PUBLIC_PAGES = [
    "/" => "home",
    "/about" => "about",
    "/academics" => "academics",
    "/admissions" => "admissions",
    "/computer-learning" => "computer",
    "/school-life" => "schoollife",
    "/faith" => "faith",
    "/parents" => "parents",
    "/students" => "students",
    "/gallery" => "gallery",
    "/contact" => "contact",
    "/privacy" => "privacy",
    "/downloads" => "downloads",
    "/safeguarding" => "safeguarding",
    "/talents" => "talents",
];
function public_context(Platform $p, NativeUrl $url, $nonce): array
{
    $db = SEED;
    $content = $p->publicContent();
    $media = $p->publicMedia();
    $db["settings"] = apply_details($db["settings"], $content);
    $db["news"] = [];
    $db["events"] = [];
    $db["gallery"] = [];
    foreach ($content as $r) {
        if ($r["kind"] === "news") {
            $r["image"] = !empty($r["mediaId"])
                ? "/media/" . $r["mediaId"]
                : "campus";
            $r["alt"] = "Alpha school crest — photograph awaiting approval";
            foreach ($media as $m) {
                if ($m["id"] === ($r["mediaId"] ?? "")) {
                    $r["alt"] = $m["alt"];
                }
            }
            $r["gallery"] = [];
            $r["author"] = $r["author"] ?? "School Communications";
            $r["dateLabel"] =
                $r["dateLabel"] ??
                (new JsDate($r["publishedAt"]))->toLocaleDateString("en-GB");
            $r["sortDate"] = $r["publishedAt"];
            $r["content"] =
                $r["content"] ?? preg_split('/\n\s*\n/', $r["body"]);
            $db["news"][] = $r;
        }
        if ($r["kind"] === "event") {
            $db["events"][] = array_replace($r, [
                "dateLabel" => $r["eventDate"],
            ]);
        }
    }
    usort($db["news"], fn($a, $b) => strcmp($b["sortDate"], $a["sortDate"]));
    foreach ($media as $m) {
        $db["gallery"][] = [
            "img" => $m["url"],
            "cat" => $m["category"],
            "caption" => $m["title"],
            "alt" => $m["alt"],
        ];
    }
    return [
        "db" => $db,
        "content" => $content,
        "downloads" => in_array(
            $url->pathname,
            ["/downloads", "/parents"],
            true,
        )
            ? $p->publicDownloads()
            : [],
        "path" => $url->pathname,
        "query" => $url->searchParams,
        "lang" => str_starts_with($url->pathname, "/sw") ? "sw" : "en",
        "baseUrl" => $p->config["baseUrl"],
        "config" => $p->config,
        "nonce" => $nonce,
    ];
}
function serve_asset($route): bool
{
    $root = realpath(dirname(__DIR__, 2) . "/public");
    $decoded = rawurldecode($route);
    if (
        str_contains($decoded, "..") ||
        str_contains($decoded, "\\") ||
        str_contains($decoded, "\0")
    ) {
        return false;
    }
    $file = realpath($root . "/" . $decoded);
    $types = [
        "css" => "text/css; charset=utf-8",
        "js" => "text/javascript; charset=utf-8",
        "svg" => "image/svg+xml",
        "png" => "image/png",
        "jpg" => "image/jpeg",
        "webp" => "image/webp",
        "woff2" => "font/woff2",
    ];
    $ext = pathinfo($decoded, PATHINFO_EXTENSION);
    if (
        !$file ||
        !str_starts_with($file, $root . "/") ||
        !is_file($file) ||
        !isset($types[$ext])
    ) {
        return false;
    }
    header("Content-Type: " . $types[$ext]);
    header("Content-Length: " . filesize($file));
    header("Cache-Control: public, max-age=3600");
    if (($_SERVER["REQUEST_METHOD"] ?? "GET") !== "HEAD") {
        readfile($file);
    }
    return true;
}
function handle_request(): void
{
    $url = null;
    $store = null;
    $c = null;
    try {
        $c = configuration();
        $nonce = base64_encode(random_bytes(18));
        security_headers($c, $nonce);
        $url = new NativeUrl($_SERVER["REQUEST_URI"] ?? "/", "http://internal");
        $route = $url->pathname;
        $verb = $_SERVER["REQUEST_METHOD"] ?? "GET";
        if (
            $route === "/favicon.ico" ||
            preg_match("~^/(img|css|js|fonts)/~", $route)
        ) {
            if (!in_array($verb, ["GET", "HEAD"], true)) {
                throw new HttpError(405, "Method not allowed.");
            }
            if (
                !serve_asset(
                    $route === "/favicon.ico" ? "/img/favicon-32.png" : $route,
                )
            ) {
                throw new HttpError(404, "Asset not found.");
            }
            return;
        }
        $store = new Store($c);
        $files = new Files($c);
        $platform = new Platform($store, $c, $files);
        $platform->init();
        $auth = new Auth($store, $c);
        if (str_starts_with($route, "/api/")) {
            if ($c["publicPreview"]) {
                response_json(
                    [
                        "ok" => false,
                        "code" => "PREVIEW_READ_ONLY",
                        "message" =>
                            "Public preview does not accept submissions or provide staff access.",
                    ],
                    404,
                );
                return;
            }
            $req = ["method" => $verb, "headers" => request_headers()];
            a_platform_router_handleApi($req, null, $url, $platform, $auth);
            return;
        }
        if ($route === "/admin" || str_starts_with($route, "/admin/")) {
            if ($verb !== "GET") {
                throw new HttpError(
                    410,
                    "The legacy CMS is retired. Use the secure portal.",
                );
            }
            response_redirect("/portal");
            return;
        }
        if ($route === "/portal" || str_starts_with($route, "/portal/")) {
            if ($verb !== "GET") {
                throw new HttpError(405, "Use the authenticated API.");
            }
            header("Cache-Control: private, no-store, max-age=0");
            header("X-Robots-Tag: noindex, nofollow");
            header("Referrer-Policy: no-referrer");
            if ($c["publicPreview"]) {
                response_html(
                    '<!doctype html><html lang="en"><title>Unavailable in preview</title><body><h1>Staff access is unavailable in this public preview.</h1><a href="/">Return to the school website</a></body></html>',
                    404,
                );
                header("Cache-Control: no-store");
                return;
            }
            $section = explode("/", $route)[2] ?? "dashboard";
            response_html(
                a_pages_portal_portal(
                    ["section" => $section ?: "dashboard", "nonce" => $nonce],
                    $auth->actor(true),
                    $c,
                ),
            );
            header("Cache-Control: private, no-store, max-age=0");
            return;
        }
        if (!in_array($verb, ["GET", "HEAD"], true)) {
            throw new HttpError(405, "Method not allowed.");
        }
        if (in_array($route, ["/healthz", "/readyz"], true)) {
            response_json(["ok" => $store->health()]);
            return;
        }
        if ($route === "/service-worker.js") {
            header("Content-Type: text/javascript; charset=utf-8");
            header("Cache-Control: no-cache");
            header("Service-Worker-Allowed: /");
            if ($verb !== "HEAD") {
                readfile(dirname(__DIR__, 2) . "/public/service-worker.js");
            }
            return;
        }
        if ($route === "/offline") {
            response_html(
                file_get_contents(dirname(__DIR__, 2) . "/public/offline.html"),
            );
            return;
        }
        if ($route === "/site.webmanifest") {
            header("Content-Type: application/manifest+json");
            header("Cache-Control: no-cache");
            $shortcuts = [
                ["name" => "Staff workspace", "url" => "/portal"],
                ["name" => "Admissions", "url" => "/admissions"],
                ["name" => "Kiswahili", "url" => "/sw"],
            ];
            if ($c["publicPreview"]) {
                array_shift($shortcuts);
            }
            echo js_json_stringify([
                "id" => "/",
                "name" => "Alpha Adventist Digital School",
                "short_name" => "Alpha School",
                "lang" => "en",
                "start_url" => "/",
                "scope" => "/",
                "display" => "standalone",
                "background_color" => "#f8f6f0",
                "theme_color" => "#0A1E59",
                "description" =>
                    "Alpha Adventist school information and secure, online staff workspace.",
                "icons" => [
                    [
                        "src" => "/img/icon-192.png",
                        "sizes" => "192x192",
                        "type" => "image/png",
                        "purpose" => "any",
                    ],
                    [
                        "src" => "/img/icon-512.png",
                        "sizes" => "512x512",
                        "type" => "image/png",
                        "purpose" => "any",
                    ],
                ],
                "shortcuts" => $shortcuts,
            ]);
            return;
        }
        if ($route === "/robots.txt") {
            header("Content-Type: text/plain; charset=utf-8");
            echo $c["environment"] === "staging"
                ? "User-agent: *\nDisallow: /\n"
                : "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /portal\nDisallow: /api/\nDisallow: /offline\n\nSitemap: {$c["baseUrl"]}/sitemap.xml\n";
            return;
        }
        if ($route === "/sitemap.xml") {
            $paths = array_merge(array_keys(PUBLIC_PAGES), [
                "/sw",
                "/sw/admissions",
                "/sw/contact",
                "/news",
            ]);
            foreach ($platform->publicContent() as $r) {
                if ($r["kind"] === "news") {
                    $paths[] = "/news/" . $r["slug"];
                } elseif (
                    in_array(
                        $r["kind"],
                        ["page", "faq", "vacancy", "event"],
                        true,
                    )
                ) {
                    $paths[] = "/pages/" . $r["slug"];
                }
            }
            header("Content-Type: application/xml; charset=utf-8");
            echo '<?xml version="1.0" encoding="UTF-8"?>' .
                "\n" .
                '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">';
            foreach (array_unique($paths) as $path) {
                echo "<url><loc>" . esc($c["baseUrl"] . $path) . "</loc></url>";
            }
            echo "</urlset>";
            return;
        }
        if (preg_match('~^/downloads/DOC-[a-f0-9-]+$~', $route)) {
            a_platform_router_sendFile(
                null,
                $platform->publicDownload(substr($route, 11), [
                    "ip" => request_ip($c),
                    "userAgent" => $_SERVER["HTTP_USER_AGENT"] ?? "",
                ]),
            );
            return;
        }
        if (preg_match('~^/media/MED-[a-f0-9-]+$~', $route)) {
            a_platform_router_sendFile(
                null,
                $platform->publicMediaFile(substr($route, 7)),
                true,
            );
            return;
        }
        if ($route !== "/" && str_ends_with($route, "/")) {
            response_redirect(rtrim($route, "/") . $url->search, 308);
            return;
        }
        $ctx = public_context($platform, $url, $nonce);
        $out = null;
        if (isset(PUBLIC_PAGES[$route])) {
            $out = ("a_pages_" . PUBLIC_PAGES[$route] . "_render")($ctx);
        } elseif ($route === "/sw") {
            $out = a_pages_home_render($ctx);
        } elseif ($route === "/sw/admissions") {
            $out = a_pages_swahili_admissions($ctx);
        } elseif ($route === "/sw/contact") {
            $out = a_pages_swahili_contact($ctx);
        } elseif ($route === "/news") {
            $out = a_pages_news_list($ctx);
        } elseif (preg_match('~^/news/[^/]+$~', $route)) {
            $out = a_pages_news_article($ctx, rawurldecode(substr($route, 6)));
        } elseif (preg_match('~^/pages/[^/]+$~', $route)) {
            foreach ($ctx["content"] as $r) {
                if (
                    $r["slug"] === substr($route, 7) &&
                    in_array(
                        $r["kind"],
                        ["page", "faq", "vacancy", "event"],
                        true,
                    )
                ) {
                    $out = [
                        "body" =>
                            a_pages_about_phero(
                                $ctx,
                                $r["kind"],
                                $r["title"],
                                $r["excerpt"],
                                "campus",
                            ) .
                            '<section class="sec"><article class="container article">' .
                            paragraphs(
                                $r["content"] ??
                                    preg_split('/\n\s*\n/', $r["body"]),
                            ) .
                            "</article></section>",
                        "meta" => [
                            "title" =>
                                $r["seoTitle"] ?:
                                $r["title"] . " — Alpha Adventist",
                            "desc" => $r["seoDescription"] ?: $r["excerpt"],
                        ],
                    ];
                    break;
                }
            };
        }
        if (!$out) {
            response_html(
                a_layout_page(
                    $ctx,
                    '<section class="sec"><div class="container text-center"><span class="kicker">404</span><h1>This page isn’t here.</h1><p>Let’s return to familiar ground.</p><a class="btn btn--primary" href="/">Back to school home</a></div></section>',
                    ["title" => "Page not found — Alpha Adventist"],
                ),
                404,
            );
            return;
        }
        response_html(a_layout_page($ctx, $out["body"], $out["meta"]));
    } catch (Throwable $e) {
        $status = $e instanceof HttpError ? $e->status : 500;
        $message =
            $status < 500
                ? $e->getMessage()
                : "The service is temporarily unavailable. Please try again or contact the school.";
        if ($url && str_starts_with($url->pathname, "/api/") && $store && $c) {
            try {
                $actor = isset($auth)
                    ? $auth->actor()
                    : ["ip" => request_ip($c)];
                $store->run(
                    fn($tx) => audit(
                        $tx,
                        $c,
                        $actor,
                        $status === 403
                            ? "api.access_denied"
                            : "api.request_failed",
                        "api",
                        preg_replace(
                            "~^/api/downloads/.*~",
                            "/api/downloads/[redacted]",
                            $url->pathname,
                        ),
                        $status === 403 ? "denied" : "failure",
                        [
                            "method" => $_SERVER["REQUEST_METHOD"] ?? "",
                            "status" => $status,
                        ],
                    ),
                );
            } catch (Throwable) {
                error_log(
                    "[alpha audit] Recording failed; check database availability.",
                );
            }
        }
        if ($status >= 500) {
            error_log(
                "[alpha] " .
                    get_class($e) .
                    " at " .
                    basename($e->getFile()) .
                    ":" .
                    $e->getLine(),
            );
        }
        if ($url && str_starts_with($url->pathname, "/api/")) {
            response_json(
                [
                    "ok" => false,
                    "code" =>
                        $e instanceof HttpError
                            ? $e->errorCode
                            : "REQUEST_FAILED",
                    "message" => $message,
                ],
                $status,
            );
        } else {
            response_html(
                '<!doctype html><html lang="en"><meta name="viewport" content="width=device-width, initial-scale=1"><title>Alpha School</title><body style="font-family:system-ui;padding:40px;max-width:700px;margin:auto"><h1>Please try again</h1><p>' .
                    esc($message) .
                    '</p><a href="/">Return to school home</a></body></html>',
                $status,
            );
        }
        header("Cache-Control: no-store");
    }
}
