<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_layout_baseOf($ctx = null)
{
    return rtrim($ctx["baseUrl"] ?? (env_value("BASE_URL") ?: LIVE_URL), "/");
}
function a_layout_localPath($ctx = null, $path = null)
{
    return js_truth(
        js_and(g($ctx, "lang") === "sw", function () use (&$path) {
            return jm(["/", "/admissions", "/contact"], "includes", $path);
        }),
    )
        ? js_add("/sw", js_truth($path === "/") ? "" : $path)
        : $path;
}
define("a_layout_P", [
    "pause" => '<path d="M8 5v14M16 5v14"/>',
    "book" =>
        '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 5.5V20.5"/><path d="M9 7.5h7M9 11h7"/>',
    "flame" =>
        '<path d="M12 3c1 3-3 4.5-3 8a3.5 3.5 0 0 0 7 0c0-1.2-.4-2.2-1-3-.3 1-.9 1.6-1.6 2 .5-2.4-.2-5-1.4-7z"/><path d="M12 21a6 6 0 0 0 6-6c0-1.6-.5-3-1.3-4.2M12 21a6 6 0 0 1-6-6c0-1.6.5-3 1.3-4.2"/>',
    "chip" =>
        '<rect x="7" y="7" width="10" height="10" rx="2"/><path d="M10 3v4M14 3v4M10 17v4M14 17v4M3 10h4M3 14h4M17 10h4M17 14h4"/>',
    "star" =>
        '<path d="m12 3.6 2.5 5.1 5.6.8-4 4 .9 5.6-5-2.7-5 2.7.9-5.6-4-4 5.6-.8z"/>',
    "shield" =>
        '<path d="M12 3 5 6v5c0 4.5 3 8.4 7 10 4-1.6 7-5.5 7-10V6z"/><path d="m9.3 11.8 2 2 3.6-4"/>',
    "rocket" =>
        '<path d="M12 15c-2 0-3-1-3-3 0-4 2-7.5 3-9 1 1.5 3 5 3 9 0 2-1 3-3 3z"/><path d="M9 12c-2 .5-3.5 2-4 5 2.6-.3 4.2-1 5-2M15 12c2 .5 3.5 2 4 5-2.6-.3-4.2-1-5-2"/><circle cx="12" cy="10" r="1.4"/>',
    "phone" =>
        '<path d="M6.8 3.5h2.4l1.3 3.4-1.8 1.4a12.6 12.6 0 0 0 5 5l1.4-1.8 3.4 1.3v2.4c0 1-.8 1.9-1.9 1.8C10 16.6 7.4 14 5 6.9c-.3-1.1.2-2.4 1.8-3.4z"/>',
    "mail" =>
        '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
    "pin" =>
        '<path d="M12 21s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
    "whatsapp" =>
        '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3z"/><path d="M8.8 9.2c-.3 1.6 1 3.6 2.3 4.8 1.2 1.1 2.9 2 4.2 1.7l.5-1.5-2-1-.9.8c-.9-.4-2-1.4-2.4-2.3l.8-.9-1-2z"/>',
    "clock" => '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
    "menu" => '<path d="M4 7h16M4 12h16M4 17h16"/>',
    "close" => '<path d="m6 6 12 12M18 6 6 18"/>',
    "arrow" => '<path d="M5 12h14M13 6l6 6-6 6"/>',
    "arrowUp" => '<path d="M7 17 17 7M9 7h8v8"/>',
    "check" => '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
    "camera" =>
        '<path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.4"/>',
    "calendar" =>
        '<rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
    "mega" =>
        '<path d="M4 10v4a1 1 0 0 0 1 1h2l8 4V5L7 9H5a1 1 0 0 0-1 1z"/><path d="M18.5 9.5a4 4 0 0 1 0 5"/>',
    "users" =>
        '<circle cx="9" cy="8.5" r="3.2"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="16.8" cy="9.5" r="2.6"/><path d="M15.5 14.3c2.4.2 4.3 1.8 4.9 4.7"/>',
    "laptop" =>
        '<rect x="4" y="5" width="16" height="10" rx="1.5"/><path d="M2.5 18.5h19"/>',
    "music" =>
        '<path d="M9 18.5V6l10-2v12.5"/><circle cx="6.5" cy="18.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
    "ball" =>
        '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5c2.5 2.4 2.5 14.6 0 17M3.8 9.5c4.6 2 11.8 2 16.4 0M3.8 14.5c4.6-2 11.8-2 16.4 0"/>',
    "palette" =>
        '<path d="M12 3a9 9 0 1 0 0 18c1.6 0 2.2-1 1.6-2.2-.7-1.4.2-2.8 1.8-2.8H18a3.6 3.6 0 0 0 3-3.6C21 7 17 3 12 3z"/><circle cx="8" cy="9" r="1.1"/><circle cx="12" cy="7.5" r="1.1"/><circle cx="15.8" cy="9.6" r="1.1"/>',
    "mic" =>
        '<rect x="9.5" y="3" width="5" height="10" rx="2.5"/><path d="M6 11a6 6 0 0 0 12 0M12 17v4M9 21h6"/>',
    "leaf" =>
        '<path d="M5 19C5 9 12 4 20 4c0 9-5 15-13 15"/><path d="M5 19c2-5 6-9 10-11"/>',
    "cross" => '<path d="M12 4v16M7 9h10"/>',
    "heart" =>
        '<path d="M12 20s-7.5-4.7-7.5-10A4.3 4.3 0 0 1 12 7.6 4.3 4.3 0 0 1 19.5 10c0 5.3-7.5 10-7.5 10z"/>',
    "grid" =>
        '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
    "lock" =>
        '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
    "clipboard" =>
        '<rect x="5" y="4.5" width="14" height="16.5" rx="2"/><path d="M9 4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V6H9z"/><path d="M9 11h6M9 15h6"/>',
    "flask" =>
        '<path d="M10 3v5.5L4.8 18a2 2 0 0 0 1.8 3h10.8a2 2 0 0 0 1.8-3L14 8.5V3"/><path d="M8.5 3h7M7.5 14.5h9"/>',
    "growth" => '<path d="M4 19h16M6 16v-4M10.5 16V8M15 16v-6M19.5 16V5"/>',
    "target" =>
        '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
    "sun" =>
        '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3 7 7M17 17l1.7 1.7M18.7 5.3 17 7M7 17l-1.7 1.7"/>',
    "home" => '<path d="m4 11 8-7 8 7"/><path d="M6 9.5V20h12V9.5"/>',
    "download" => '<path d="M12 4v10M8 10.5l4 4 4-4M5 19h14"/>',
    "share" =>
        '<circle cx="6" cy="12" r="2.5"/><circle cx="17" cy="6" r="2.5"/><circle cx="17" cy="18" r="2.5"/><path d="m8.3 10.8 6.4-3.6M8.3 13.2l6.4 3.6"/>',
    "link" =>
        '<path d="M10 14a4 4 0 0 0 6 .4l2.5-2.5a4 4 0 0 0-5.6-5.6L11.5 7.7"/><path d="M14 10a4 4 0 0 0-6-.4L5.5 12.1a4 4 0 0 0 5.6 5.6l1.4-1.4"/>',
    "sparkle" =>
        '<path d="M12 3.5 13.8 9l5.7 1.8-5.7 1.8L12 18.5 10.2 12.6 4.5 10.8 10.2 9z"/><path d="M19 3.5v3M17.5 5h3"/>',
    "keyboard" =>
        '<rect x="3" y="7" width="18" height="10" rx="2"/><path d="M6.5 10.5h.01M10 10.5h.01M13.5 10.5h.01M17 10.5h.01M6.5 13.5h.01M17 13.5h.01M9.5 13.5h5"/>',
    "globe" =>
        '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.4 2.5 14.6 0 17M12 3.5c-2.5 2.4-2.5 14.6 0 17"/>',
    "pencil" => '<path d="m14.5 5.5 4 4L8 20H4v-4z"/><path d="m12.5 7.5 4 4"/>',
    "badge" =>
        '<circle cx="12" cy="9" r="5.5"/><path d="m8.8 13.5-1.3 7 4.5-2.6 4.5 2.6-1.3-7"/>',
    "bed" =>
        '<path d="M3 18v-8M3 14h18v4M3 14V7"/><path d="M7 11h4a3 3 0 0 1 3 3"/><circle cx="7" cy="10" r="0.6"/>',
    "eye" =>
        '<path d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
    "send" => '<path d="M21 3 3 10.5l7 3 3 7z"/><path d="M21 3 10 13.5"/>',
    "quote" =>
        '<path d="M9 7c-3 1-4.5 3.4-4.5 6.5V17H10v-6H7.2C7.6 9.4 8.4 8.4 10 7.6zM19 7c-3 1-4.5 3.4-4.5 6.5V17H20v-6h-2.8c.4-1.6 1.2-2.6 2.8-3.4z"/>',
    "chevron" => '<path d="m8 10 4 4 4-4"/>',
]);
function a_layout_icon($name = null, $cls = "")
{
    $d = js_or(g(a_layout_P, $name), function () {
        return g(a_layout_P, "star");
    });
    return '<svg class="icon ' .
        js_string($cls) .
        '" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">' .
        js_string($d) .
        "</svg>";
}
define("a_layout_PHOTO_ASSETS", [
    "campus" => "site-campus.webp",
    "garden-project" => "site-garden.webp",
    "exhibit-pupils" => "site-project.webp",
    "exhibit-teacher" => "site-classroom.webp",
    "graduation-group" => "site-graduation.webp",
    "graduation-certificate" => "site-awards.webp",
    "choir-green" => "site-choir.webp",
    "choir-teal" => "site-choir-stage.webp",
    "staff-group" => "site-leadership.webp",
    "leadership-guests" => "site-leadership.webp",
    "school-community" => "site-school-community.webp",
    "gate" => "site-gate.webp",
    "classroom" => "site-classroom.webp",
    "sports" => "site-sports.webp",
]);
function a_layout_photoSrc($name = null)
{
    return js_truth(g(a_layout_PHOTO_ASSETS, $name))
        ? "/img/" . js_string(g(a_layout_PHOTO_ASSETS, $name)) . ""
        : "";
}
function a_layout_pic($base = null, $alt = null, $opts = [])
{
    $media = jm(
        new JsRegex('^\\/media\\/MED-[a-f0-9-]+$', ""),
        "test",
        js_String($base),
    );
    $local = a_layout_photoSrc($base);
    $src = js_truth($media)
        ? $base
        : js_or($local, function () {
            return "/img/logo-512.png";
        });
    $description = js_truth(
        js_or($media, function () use (&$local) {
            return $local;
        }),
    )
        ? $alt
        : "Alpha school crest";
    $pending = js_and(!js_truth($media), function () use (&$local) {
        return !js_truth($local);
    });
    return "<picture" .
        js_string(js_truth($pending) ? ' class="media-pending"' : "") .
        '><img src="' .
        js_string(esc($src)) .
        '" alt="' .
        js_string(esc($description)) .
        '" ' .
        js_string(
            js_truth(g($opts, "cls"))
                ? 'class="' . js_string(esc(g($opts, "cls"))) . '"'
                : "",
        ) .
        " " .
        js_string(
            js_truth(g($opts, "eager"))
                ? 'fetchpriority="high"'
                : 'loading="lazy"',
        ) .
        ' decoding="async" width="1200" height="820" ' .
        js_string(
            js_truth(g($opts, "ratio"))
                ? 'style="aspect-ratio:' .
                    js_string(esc(g($opts, "ratio"))) .
                    '"'
                : "",
        ) .
        "></picture>";
}
function a_layout_btn(
    $href = null,
    $label = null,
    $variant = "primary",
    $ic = "",
) {
    $cls = "btn btn--" . js_string($variant) . "";
    $ico = js_truth($ic) ? a_layout_icon($ic, "btn__ico") : "";
    return '<a class="' .
        js_string($cls) .
        '" href="' .
        js_string(esc($href)) .
        '">' .
        js_string(esc($label)) .
        "" .
        js_string($ico) .
        "</a>";
}
function a_layout_sectionHead(
    $kicker = null,
    $title = null,
    $text = null,
    $opts = [],
) {
    $align = js_truth(g($opts, "align") === "center")
        ? " sec-head--center"
        : "";
    $tone = js_truth(g($opts, "tone"))
        ? " sec-head--" . js_string(g($opts, "tone")) . ""
        : "";
    return '<div class="sec-head' .
        js_string($align) .
        "" .
        js_string($tone) .
        '">
    ' .
        js_string(
            js_truth($kicker)
                ? '<span class="kicker">' . js_string(esc($kicker)) . "</span>"
                : "",
        ) .
        '
    <h2>' .
        js_string(esc($title)) .
        '</h2>
    ' .
        js_string(
            js_truth($text)
                ? '<p class="sec-head__text">' . js_string(esc($text)) . "</p>"
                : "",
        ) .
        '
  </div>';
}
define("a_layout_NAV", [
    ["href" => "/", "label" => "Home", "sw" => "Mwanzo"],
    [
        "href" => "/about",
        "label" => "About Us",
        "sw" => "Kuhusu Shule",
        "group" => "school",
    ],
    [
        "href" => "/academics",
        "label" => "Academics",
        "sw" => "Taaluma",
        "group" => "school",
    ],
    ["href" => "/admissions", "label" => "Admissions", "sw" => "Uandikishaji"],
    [
        "href" => "/computer-learning",
        "label" => "Computer Learning",
        "sw" => "TEHAMA",
        "group" => "community",
    ],
    [
        "href" => "/school-life",
        "label" => "School Life",
        "sw" => "Maisha ya Shule",
        "group" => "school",
    ],
    [
        "href" => "/parents",
        "label" => "Parents",
        "sw" => "Wazazi",
        "group" => "community",
    ],
    [
        "href" => "/students",
        "label" => "Students",
        "sw" => "Wanafunzi",
        "group" => "community",
    ],
    [
        "href" => "/news",
        "label" => "News & Events",
        "sw" => "Habari",
        "group" => "school",
    ],
    [
        "href" => "/gallery",
        "label" => "Gallery",
        "sw" => "Picha",
        "group" => "school",
    ],
    ["href" => "/contact", "label" => "Contact", "sw" => "Wasiliana"],
    [
        "href" => "/downloads",
        "label" => "Downloads",
        "sw" => "Nyaraka",
        "group" => "resources",
    ],
    ["href" => "/portal", "label" => "Portal Login", "sw" => "Ingia Portal"],
]);
define("a_layout_NAV_GROUPS", [
    "school" => ["label" => "Our School", "sw" => "Shule Yetu"],
    "community" => [
        "label" => "Families & Learning",
        "sw" => "Familia na Masomo",
    ],
    "resources" => ["label" => "Resources", "sw" => "Rasilimali"],
]);
function a_layout_header($ctx = null)
{
    $s = g(g($ctx, "db"), "settings");
    $path = js_or(
        jm(g($ctx, "path"), "replace", new JsRegex('^\\/sw(?=\\/|$)', ""), ""),
        function () {
            return "/";
        },
    );
    $sw = g($ctx, "lang") === "sw";
    $isActive = function ($href = null) use (&$path) {
        return js_truth($href === "/")
            ? $path === "/"
            : js_or($path === $href, function () use (&$path, &$href) {
                return jm($path, "startsWith", js_add($href, "/"));
            });
    };
    $visibleNav = js_truth(g(g($ctx, "config"), "publicPreview"))
        ? jm(a_layout_NAV, "filter", function ($item = null) {
            return g($item, "href") !== "/portal";
        })
        : a_layout_NAV;
    $navItem = function ($n = null) use (&$ctx, &$sw, &$isActive) {
        return '<li><a href="' .
            js_string(a_layout_localPath($ctx, g($n, "href"))) .
            '" ' .
            js_string(
                js_truth($isActive(g($n, "href"))) ? 'aria-current="page"' : "",
            ) .
            ">" .
            js_string(esc(js_truth($sw) ? g($n, "sw") : g($n, "label"))) .
            "</a></li>";
    };
    $renderedGroups = new JsSet();
    $links = jm(
        jm($visibleNav, "map", function ($item = null) use (
            &$sw,
            &$isActive,
            &$visibleNav,
            &$navItem,
            &$renderedGroups,
        ) {
            if (js_truth(!js_truth(g($item, "group")))) {
                return $navItem($item);
            }
            if (js_truth(jm($renderedGroups, "has", g($item, "group")))) {
                return "";
            }
            jm($renderedGroups, "add", g($item, "group"));
            $group = g(a_layout_NAV_GROUPS, g($item, "group"));
            $items = jm($visibleNav, "filter", function ($link = null) use (
                &$item,
            ) {
                return g($link, "group") === g($item, "group");
            });
            $current = jm($items, "some", function ($link = null) use (
                &$isActive,
            ) {
                return $isActive(g($link, "href"));
            });
            return '<li class="nav-dropdown"><details' .
                js_string(js_truth($current) ? ' data-current="true"' : "") .
                "><summary>" .
                js_string(
                    esc(js_truth($sw) ? g($group, "sw") : g($group, "label")),
                ) .
                '</summary><ul class="nav-dropdown__menu">' .
                js_string(jm(jm($items, "map", $navItem), "join", "")) .
                "</ul></details></li>";
        }),
        "join",
        "",
    );
    $mlinks = $links;
    $alternate = js_truth(
        jm(["/", "/admissions", "/contact"], "includes", $path),
    )
        ? $path
        : "/";
    $languageSwitch =
        '<div class="language-switch" aria-label="Language"><a href="' .
        js_string($alternate) .
        '" lang="en" ' .
        js_string(js_truth(!js_truth($sw)) ? 'aria-current="true"' : "") .
        '>EN</a><span>/</span><a href="/sw' .
        js_string(js_truth($alternate === "/") ? "" : $alternate) .
        '" lang="sw" ' .
        js_string(js_truth($sw) ? 'aria-current="true"' : "") .
        ">SW</a></div>";
    $wa =
        "https://wa.me/" .
        js_string(g(g($s, "whatsapp"), "href")) .
        "?text=" .
        js_string(
            js_encodeURIComponent(
                "Hello Alpha Adventist Pre & Primary School, I would like to enquire about the school.",
            ),
        ) .
        "";
    return '
<a class="skip-link" href="#main">Skip to main content</a>
<div class="topbar">
  <div class="container topbar__in">
    <ul class="topbar__list" aria-label="School contact information">
      <li>' .
        js_string(a_layout_icon("pin")) .
        "<span>" .
        js_string(esc(g($s, "locationText"))) .
        '</span></li>
      <li><a href="tel:' .
        js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
        '">' .
        js_string(a_layout_icon("phone")) .
        "<span>" .
        js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
        '</span></a></li>
      ' .
        js_string(
            js_truth(g($s, "emailPublished"))
                ? '<li class="topbar__hide-sm"><a href="mailto:' .
                    js_string(esc(g($s, "email"))) .
                    '">' .
                    js_string(a_layout_icon("mail")) .
                    "<span>" .
                    js_string(esc(g($s, "email"))) .
                    "</span></a></li>"
                : "",
        ) .
        '
    </ul>
    <p class="topbar__motto">' .
        js_string(esc(g($s, "motto"))) .
        " <span>•</span> " .
        js_string(esc(g($s, "conference"))) .
        '</p>
  </div>
</div>
<header class="site-head" id="top">
  <div class="container site-head__in">
    <a class="brand" href="' .
        js_string(a_layout_localPath($ctx, "/")) .
        '" aria-label="Alpha Adventist Pre & Primary School — Home">
      <img class="brand__logo" src="/img/logo-96.png" srcset="/img/logo-96.png 96w, /img/logo-160.png 160w" sizes="52px" alt="Alpha Adventist School crest with the motto Wisdom in Truth" width="52" height="51" decoding="async">
      <span class="brand__text">
        <strong>Alpha Adventist <span>Pre &amp; Primary School</span></strong>
        <small>' .
        js_string(esc(g($s, "affiliation"))) .
        " • " .
        js_string(esc(g($s, "conference"))) .
        '</small>
      </span>
    </a>
    <nav class="mainnav" aria-label="Main navigation">
      <ul>' .
        js_string($links) .
        '</ul>
    </nav>
    <div class="site-head__actions">
      ' .
        js_string($languageSwitch) .
        "" .
        js_string(
            js_truth(g(g($ctx, "config"), "publicPreview"))
                ? ""
                : '<a class="btn btn--primary btn--sm" href="' .
                    js_string(a_layout_localPath($ctx, "/admissions")) .
                    '#apply">' .
                    js_string(
                        js_truth($sw) ? "Omba Nafasi" : "Apply for Admission",
                    ) .
                    "</a>",
        ) .
        '
      <button class="navtoggle" type="button" aria-expanded="false" aria-controls="mobileNav" data-navtoggle>
        ' .
        js_string(a_layout_icon("menu")) .
        '<span class="sr-only">Open menu</span>
      </button>
    </div>
  </div>
  <div class="mobilenav" id="mobileNav" hidden>
    <nav aria-label="Mobile navigation">
      <ul>' .
        js_string($mlinks) .
        '</ul>
      <div class="mobilenav__ctas">
        ' .
        js_string(
            js_truth(g(g($ctx, "config"), "publicPreview"))
                ? ""
                : '<a class="btn btn--primary" href="' .
                    js_string(a_layout_localPath($ctx, "/admissions")) .
                    '#apply">' .
                    js_string(
                        js_truth($sw) ? "Omba Nafasi" : "Apply for Admission",
                    ) .
                    '</a><a class="btn btn--gold" href="/computer-learning#join">Join Computer Class</a>',
        ) .
        '
        <a class="btn btn--ghost" href="/contact">Contact Us</a>
      </div>
    </nav>
  </div>
</header>
<div class="quickbar" role="navigation" aria-label="Quick contact actions">
  <a href="' .
        js_string(esc($wa)) .
        '" target="_blank" rel="noopener">' .
        js_string(a_layout_icon("whatsapp")) .
        '<span>WhatsApp</span></a>
  <a href="tel:' .
        js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
        '">' .
        js_string(a_layout_icon("phone")) .
        "<span>" .
        js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
        '</span></a>
  ' .
        js_string(
            js_truth(g(g($ctx, "config"), "publicPreview"))
                ? ""
                : '<a href="' .
                    js_string(a_layout_localPath($ctx, "/admissions")) .
                    '#apply">' .
                    js_string(a_layout_icon("pencil")) .
                    "<span>" .
                    js_string(js_truth($sw) ? "Omba Nafasi" : "Apply") .
                    "</span></a>",
        ) .
        '
</div>
<div class="floating-contact" aria-label="Contact Alpha Adventist School">
  <a class="floating-contact__link floating-contact__link--whatsapp" href="' .
        js_string(esc($wa)) .
        '" target="_blank" rel="noopener" aria-label="Chat with the school on WhatsApp" title="Chat with us on WhatsApp">
    ' .
        js_string(a_layout_icon("whatsapp")) .
        '<span>Chat on WhatsApp</span>
  </a>
  <a class="floating-contact__link floating-contact__link--phone" href="tel:' .
        js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
        '" aria-label="Call the school office at ' .
        js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
        '" title="Call ' .
        js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
        '">
    ' .
        js_string(a_layout_icon("phone")) .
        "<span>" .
        js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
        '</span>
  </a>
</div>';
}
function a_layout_footer($ctx = null)
{
    $s = g(g($ctx, "db"), "settings");
    $wa = "https://wa.me/" . js_string(g(g($s, "whatsapp"), "href")) . "";
    $year = jm(new JsDate(), "getFullYear");
    $col = function ($title = null, $items = null) {
        return '<div class="footer__col"><h3>' .
            js_string(esc($title)) .
            "</h3><ul>" .
            js_string(
                jm(
                    jm($items, "map", function ($i = null) {
                        return '<li><a href="' .
                            js_string(g($i, 1)) .
                            '">' .
                            js_string(esc(g($i, 0))) .
                            "</a></li>";
                    }),
                    "join",
                    "",
                ),
            ) .
            "</ul></div>";
    };
    return '
<footer class="footer">
  <div class="container footer__grid">
    <div class="footer__col footer__brand">
      <img src="/img/logo-160.png" alt="Alpha Adventist School crest" width="72" height="70" loading="lazy" decoding="async">
      <p class="footer__name">Alpha Adventist Pre &amp; Primary School</p>
      <p class="footer__aff">' .
        js_string(esc(g($s, "affiliation"))) .
        "<br>" .
        js_string(esc(g($s, "conference"))) .
        '</p>
      <p class="footer__desc">A holistic Christian learning community in Kigoma, Tanzania, nurturing knowledgeable, disciplined, creative and spiritually grounded learners from KG I to Standard VII — in day and boarding life.</p>
      <p class="footer__identity">' .
        js_string(esc(g($s, "identityLine"))) .
        '</p>
    </div>
    ' .
        js_string(
            $col("Explore", [
                ["Home", "/"],
                ["About Us", "/about"],
                ["Academics", "/academics"],
                ["Admissions", "/admissions"],
                ["News & Events", "/news"],
                ["Gallery", "/gallery"],
                ["Downloads", "/downloads"],
                ["Contact", "/contact"],
            ]),
        ) .
        '
    ' .
        js_string(
            $col("Learning", [
                ["Computer Learning", "/computer-learning"],
                ["Faith & Spiritual Life", "/faith"],
                ["School Life", "/school-life"],
                ["Parent Corner", "/parents"],
                ["Alpha Kids Zone", "/students"],
                ["Book a School Visit", "/admissions#visit"],
            ]),
        ) .
        '
    <div class="footer__col">
      <h3>Contact</h3>
      <ul class="footer__contact">
        <li>' .
        js_string(a_layout_icon("pin")) .
        "<span>" .
        js_string(esc(g(g($s, "address"), "box"))) .
        ", " .
        js_string(esc(g(g($s, "address"), "city"))) .
        ", " .
        js_string(esc(g(g($s, "address"), "country"))) .
        "<br>" .
        js_string(esc(g($s, "locationText"))) .
        '</span></li>
        <li>' .
        js_string(a_layout_icon("phone")) .
        '<span><a href="tel:' .
        js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
        '">' .
        js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
        '</a><br><a href="tel:' .
        js_string(esc(g(g(g($s, "phones"), 1), "href"))) .
        '">' .
        js_string(esc(g(g(g($s, "phones"), 1), "number"))) .
        '</a></span></li>
        <li>' .
        js_string(a_layout_icon("whatsapp")) .
        '<span><a href="' .
        js_string(esc($wa)) .
        '" target="_blank" rel="noopener">WhatsApp: ' .
        js_string(esc(g(g($s, "whatsapp"), "number"))) .
        '</a></span></li>
        ' .
        js_string(
            js_truth(g($s, "emailPublished"))
                ? "<li>" .
                    js_string(a_layout_icon("mail")) .
                    '<span><a href="mailto:' .
                    js_string(esc(g($s, "email"))) .
                    '">' .
                    js_string(esc(g($s, "email"))) .
                    "</a></span></li>"
                : "",
        ) .
        '
      </ul>
      ' .
        js_string(
            js_truth(
                g(
                    js_or(g($s, "social"), function () {
                        return [];
                    }),
                    "length",
                ),
            )
                ? '<p class="footer__links">' .
                    js_string(
                        jm(
                            jm(g($s, "social"), "map", function (
                                $channel = null,
                            ) {
                                return '<a href="' .
                                    js_string(esc(g($channel, "url"))) .
                                    '" target="_blank" rel="noopener noreferrer">' .
                                    js_string(esc(g($channel, "name"))) .
                                    "</a>";
                            }),
                            "join",
                            " · ",
                        ),
                    ) .
                    "</p>"
                : "",
        ) .
        '
      <p class="footer__social-note">' .
        js_string(esc(g($s, "socialNote"))) .
        '</p>
    </div>
  </div>
  <div class="footer__bottom">
    <div class="container footer__bottom-in">
      <p>© ' .
        js_string($year) .
        ' Alpha Adventist Pre &amp; Primary School. All rights reserved.</p>
      <p class="footer__links"><a href="/privacy">Website Privacy &amp; Child Safeguarding Notice</a> <span>•</span> ' .
        js_string(
            js_truth(g(g($ctx, "config"), "publicPreview"))
                ? ""
                : '<a href="/portal">Staff portal</a> <span>•</span> ',
        ) .
        '<a href="/downloads">Downloads</a> <span>•</span> <a href="/safeguarding">Report a concern</a></p>
    </div>
  </div>
</footer>';
}
function a_layout_page($ctx = null, $body = null, $meta = [])
{
    $s = g(g($ctx, "db"), "settings");
    $title = js_or(g($meta, "title"), function () use (&$s) {
        return "" .
            js_string(g($s, "schoolName")) .
            " — " .
            js_string(g($s, "affiliation")) .
            ", Kigoma";
    });
    $desc = js_or(g($meta, "desc"), function () {
        return "Alpha Adventist Pre & Primary School is a Seventh-day Adventist educational institution within the Western Tanzania Conference – Kigoma, offering holistic Pre-Primary and Primary education for day and boarding pupils.";
    });
    $BASE_URL = a_layout_baseOf($ctx);
    $url = "" . js_string($BASE_URL) . "" . js_string(g($ctx, "path")) . "";
    $bilingualPath = js_or(
        jm(g($ctx, "path"), "replace", new JsRegex('^\\/sw(?=\\/|$)', ""), ""),
        function () {
            return "/";
        },
    );
    $alternateTags = js_truth(
        jm(["/", "/admissions", "/contact"], "includes", $bilingualPath),
    )
        ? '<link rel="alternate" hreflang="en" href="' .
            js_string(esc(js_add($BASE_URL, $bilingualPath))) .
            '"><link rel="alternate" hreflang="sw" href="' .
            js_string(
                esc(
                    js_add(
                        js_add($BASE_URL, "/sw"),
                        js_truth($bilingualPath === "/") ? "" : $bilingualPath,
                    ),
                ),
            ) .
            '"><link rel="alternate" hreflang="x-default" href="' .
            js_string(esc(js_add($BASE_URL, $bilingualPath))) .
            '">'
        : "";
    $jsonld = js_truth(g($meta, "jsonld"))
        ? '<script type="application/ld+json" nonce="' .
            js_string(esc(g($ctx, "nonce"))) .
            '">' .
            js_string(
                jm(
                    js_json_stringify(g($meta, "jsonld")),
                    "replace",
                    new JsRegex("<", "g"),
                    "\\u003c",
                ),
            ) .
            "</script>"
        : "";
    $preview = g(g($ctx, "config"), "publicPreview");
    $previewBody = js_truth($preview)
        ? jm(
            $body,
            "replace",
            new JsRegex(
                "<form\\b(?=[^>]*\\bdata-endpoint=)[^>]*>[\\s\\S]*?<\\/form>",
                "gi",
            ),
            '<div class="card form preview-form-disabled"><h3>' .
                js_string(
                    js_truth(g($ctx, "lang") === "sw")
                        ? "Fomu hazipokelewi kwenye onyesho hili"
                        : "Forms are disabled in this preview",
                ) .
                "</h3><p>" .
                js_string(
                    js_truth(g($ctx, "lang") === "sw")
                        ? "Tovuti hii ni ya kuonyesha tu. Tafadhali wasiliana na ofisi ya shule kwa simu au WhatsApp."
                        : "This is a public preview only. Contact the school office by phone or WhatsApp instead.",
                ) .
                "</p></div>",
        )
        : $body;
    $bodyClass = jm(
        jm(
            [g($meta, "bodyClass"), js_truth($preview) ? "public-preview" : ""],
            "filter",
            "js_truth",
        ),
        "join",
        " ",
    );
    return '<!DOCTYPE html>
<html lang="' .
        js_string(js_truth(g($ctx, "lang") === "sw") ? "sw" : "en") .
        '">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>' .
        js_string(esc($title)) .
        '</title>
<meta name="description" content="' .
        js_string(esc($desc)) .
        '">
<link rel="canonical" href="' .
        js_string(esc($url)) .
        '">
' .
        js_string($alternateTags) .
        '
<meta property="og:locale" content="' .
        js_string(js_truth(g($ctx, "lang") === "sw") ? "sw_TZ" : "en_TZ") .
        '">
<meta property="og:site_name" content="' .
        js_string(esc(g($s, "schoolName"))) .
        '">
<meta property="og:type" content="' .
        js_string(
            js_or(g($meta, "ogType"), function () {
                return "website";
            }),
        ) .
        '">
<meta property="og:title" content="' .
        js_string(esc($title)) .
        '">
<meta property="og:description" content="' .
        js_string(esc($desc)) .
        '">
<meta property="og:url" content="' .
        js_string(esc($url)) .
        '">
<meta property="og:image" content="' .
        js_string(esc($BASE_URL)) .
        '/img/og-image.png">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Alpha Adventist Pre & Primary School — Wisdom in Truth">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#0A1E59">
<link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32.png">
<link rel="apple-touch-icon" href="/img/apple-touch-180.png">
<link rel="manifest" href="/site.webmanifest">

<link rel="stylesheet" href="/fonts/fonts.css">
<link rel="stylesheet" href="/css/main.css?v=5"><link rel="stylesheet" href="/css/platform-public.css?v=3">
' .
        js_string($jsonld) .
        '</head>
<body class="' .
        js_string($bodyClass) .
        '">
' .
        js_string(
            js_truth(g($ctx, "path") === "/students")
                ? '<header class="kids-safe-head"><a href="/students"><img src="/img/logo-96.png" alt="School crest" width="38" height="38"> Alpha Kids Zone</a><a href="/">School home</a></header>'
                : a_layout_header($ctx),
        ) .
        '
' .
        js_string(
            js_truth($preview)
                ? '<aside class="public-preview-banner" role="status"><p>' .
                    js_string(
                        js_truth(g($ctx, "lang") === "sw")
                            ? "Onyesho la umma: maombi na portal ya watumishi hazitumiki."
                            : "Public preview only: submissions and staff access are disabled.",
                    ) .
                    "</p></aside>"
                : "",
        ) .
        '
<main id="main">' .
        js_string($previewBody) .
        '</main>
' .
        js_string(
            js_truth(g($ctx, "path") === "/students")
                ? '<footer class="kids-safe-footer">Learn safely. Ask a trusted adult for help. No ads, chat, accounts or saved scores.</footer>'
                : a_layout_footer($ctx),
        ) .
        '
<script src="/js/main.js?v=5" defer></script>
</body>
</html>';
}
function a_layout_schoolJsonLd($ctx = null)
{
    $s = g(g($ctx, "db"), "settings");
    $BASE_URL = a_layout_baseOf($ctx);
    return [
        "url" => $BASE_URL,
        "@context" => "https://schema.org",
        "@type" => "School",
        "name" => g($s, "schoolName"),
        "alternateName" => "Alpha Adventist Primary School",
        "description" =>
            "A Seventh-day Adventist educational institution within the Western Tanzania Conference – Kigoma, offering Pre-Primary and Primary education (KG I – Standard VII) for day and boarding pupils.",
        "email" => js_truth(g($s, "emailPublished")) ? g($s, "email") : null,
        "telephone" => jm(g($s, "phones"), "map", function ($p = null) {
            return g($p, "number");
        }),
        "address" => [
            "@type" => "PostalAddress",
            "postOfficeBoxNumber" => jm(
                g(g($s, "address"), "box"),
                "replace",
                "P.O. Box ",
                "",
            ),
            "addressLocality" => g(g($s, "address"), "city"),
            "addressCountry" => "TZ",
            "streetAddress" => g($s, "locationText"),
        ],
        "parentOrganization" => [
            "@type" => "Organization",
            "name" => "Western Tanzania Conference of Seventh-day Adventists",
        ],
        "motto" => g($s, "motto"),
        "logo" => "" . js_string($BASE_URL) . "/img/logo-256.png",
        "image" => "" . js_string($BASE_URL) . "/img/og-image.png",
    ];
}
