<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_pages_downloads_size($bytes = null)
{
    return js_truth($bytes >= 1048576)
        ? js_add(jm($bytes / 1048576, "toFixed", 1), " MB")
        : js_add(js_math_max(1, js_math_round($bytes / 1024)), " KB");
}
function a_pages_downloads_render($ctx = null)
{
    $documents = js_or(g($ctx, "downloads"), function () {
        return [];
    });
    $categories = [
        ...js_array(
            new JsSet(
                jm($documents, "map", function ($item = null) {
                    return g($item, "category");
                }),
            ),
        ),
    ];
    $selected = js_or(jm(g($ctx, "query"), "get", "category"), function () {
        return "";
    });
    $visible = js_truth($selected)
        ? jm($documents, "filter", function ($item = null) use (&$selected) {
            return g($item, "category") === $selected;
        })
        : $documents;
    return [
        "body" =>
            "" .
            js_string(
                a_pages_about_phero(
                    $ctx,
                    "Downloads",
                    "School information, at your fingertips.",
                    "Approved forms, calendars, family guidance and public policies — with a clear version and publication date.",
                    "campus",
                ),
            ) .
            '<section class="sec"><div class="container">' .
            js_string(
                a_layout_sectionHead(
                    "PUBLIC DOWNLOAD CENTRE",
                    "The current, approved version.",
                ),
            ) .
            "" .
            js_string(
                js_truth(g($categories, "length"))
                    ? '<form class="adsp-download-filter" method="get" action="/downloads"><label for="download-category">Category</label><select id="download-category" name="category"><option value="">All categories</option>' .
                        js_string(
                            jm(
                                jm($categories, "map", function (
                                    $category = null,
                                ) use (&$selected) {
                                    return "<option " .
                                        js_string(
                                            js_truth($selected === $category)
                                                ? "selected"
                                                : "",
                                        ) .
                                        ">" .
                                        js_string(esc($category)) .
                                        "</option>";
                                }),
                                "join",
                                "",
                            ),
                        ) .
                        '</select><button class="btn btn--ghost" type="submit">Filter</button></form>'
                    : "",
            ) .
            "" .
            js_string(
                js_truth(g($visible, "length"))
                    ? '<div class="adsp-download-grid">' .
                        js_string(
                            jm(
                                jm($visible, "map", function ($item = null) {
                                    return '<article class="card"><div class="adsp-download-icon">' .
                                        js_string(a_layout_icon("download")) .
                                        '</div><span class="news-cat">' .
                                        js_string(esc(g($item, "category"))) .
                                        "</span><h3>" .
                                        js_string(esc(g($item, "title"))) .
                                        "</h3><p>" .
                                        js_string(
                                            esc(g($item, "description")),
                                        ) .
                                        '</p><dl class="adsp-download-meta"><div><dt>Document</dt><dd>' .
                                        js_string(
                                            esc(g($item, "documentNumber")),
                                        ) .
                                        "</dd></div><div><dt>Version</dt><dd>" .
                                        js_string(g($item, "version")) .
                                        ".0</dd></div><div><dt>Published</dt><dd>" .
                                        js_string(
                                            esc(
                                                jm(
                                                    new JsDate(
                                                        g($item, "publishedAt"),
                                                    ),
                                                    "toLocaleDateString",
                                                    "en-GB",
                                                ),
                                            ),
                                        ) .
                                        "</dd></div><div><dt>Size</dt><dd>" .
                                        js_string(
                                            a_pages_downloads_size(
                                                g($item, "size"),
                                            ),
                                        ) .
                                        '</dd></div></dl><a class="btn btn--primary" href="/downloads/' .
                                        js_string(esc(g($item, "id"))) .
                                        '">' .
                                        js_string(a_layout_icon("download")) .
                                        " Download " .
                                        js_string(
                                            esc(
                                                jm(
                                                    jm(
                                                        jm(
                                                            g($item, "name"),
                                                            "split",
                                                            ".",
                                                        ),
                                                        "at",
                                                        -1,
                                                    ),
                                                    "toUpperCase",
                                                ),
                                            ),
                                        ) .
                                        "</a></article>";
                                }),
                                "join",
                                "",
                            ),
                        ) .
                        "</div>"
                    : '<div class="adsp-empty"><span>' .
                        js_string(a_layout_icon("book")) .
                        "</span><h3>" .
                        js_string(
                            js_truth($selected)
                                ? "No published documents in this category."
                                : "Need a school document?",
                        ) .
                        '</h3><p>Only approved, current documents appear here. Contact the school office for the current admissions form, fee schedule, uniform guidance or term dates.</p><a class="btn btn--primary" href="/contact">Contact the office ' .
                        js_string(a_layout_icon("arrow")) .
                        "</a></div>",
            ) .
            '<p class="note-strip" style="margin-top:28px">' .
            js_string(a_layout_icon("lock")) .
            '<span>Staff policies, employment contracts and family records are not public downloads. Authorised employees access private documents through the <a href="/portal/documents">staff portal</a>.</span></p></div></section>',
        "meta" => [
            "title" =>
                "Downloads — Approved School Documents | Alpha Adventist",
            "desc" =>
                "Download approved admission forms, calendars, family guidance and public policies from Alpha Adventist Pre & Primary School, Kigoma.",
        ],
    ];
}
