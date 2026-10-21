<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
define("a_pages_news_CATS", [
    "School News",
    "Academic News",
    "Sports",
    "Spiritual Events",
    "ICT & Technology",
    "Graduation",
    "Announcements",
    "Student Activities",
    "Community Activities",
]);
function a_pages_news_list($ctx = null)
{
    $db = g($ctx, "db");
    $body =
        '
' .
        js_string(
            a_pages_about_phero(
                $ctx,
                "News & Events",
                "News & Events at Alpha",
                "Official school news, celebrations, academic updates and announcements from the school office.",
                "graduation-group",
            ),
        ) .
        '
<section class="sec">
  <div class="container">
    <div class="gal-filters" role="group" aria-label="Filter news by category">
      <button type="button" data-news-filter="all" aria-pressed="true">All</button>
      ' .
        js_string(
            jm(
                jm(
                    jm(a_pages_news_CATS, "filter", function ($c = null) use (
                        &$db,
                    ) {
                        return jm(g($db, "news"), "some", function (
                            $n = null,
                        ) use (&$c) {
                            return g($n, "category") === $c;
                        });
                    }),
                    "map",
                    function ($c = null) {
                        return '<button type="button" data-news-filter="' .
                            js_string(esc($c)) .
                            '" aria-pressed="false">' .
                            js_string(esc($c)) .
                            "</button>";
                    },
                ),
                "join",
                "",
            ),
        ) .
        '
    </div>
    <div class="grid grid--3" data-news-grid>
      ' .
        js_string(
            jm(
                jm(g($db, "news"), "map", function ($n = null) {
                    return '
      <article class="news-card rv" data-cat="' .
                        js_string(esc(g($n, "category"))) .
                        '">
        <div class="news-card__media">' .
                        js_string(
                            a_layout_pic(
                                g($n, "image"),
                                js_or(g($n, "alt"), function () use (&$n) {
                                    return g($n, "title");
                                }),
                                [
                                    "widths" => [480, 800],
                                    "sizes" => "(max-width: 900px) 92vw, 30vw",
                                ],
                            ),
                        ) .
                        '</div>
        <div class="news-card__in">
          <div class="news-meta"><span class="news-cat">' .
                        js_string(esc(g($n, "category"))) .
                        "</span><span>" .
                        js_string(esc(g($n, "dateLabel"))) .
                        '</span></div>
          <h3><a href="/news/' .
                        js_string(esc(g($n, "slug"))) .
                        '">' .
                        js_string(esc(g($n, "title"))) .
                        '</a></h3>
          <p>' .
                        js_string(esc(g($n, "excerpt"))) .
                        '</p>
          <span class="link-more" style="margin-top:auto">Read article ' .
                        js_string(a_layout_icon("arrow")) .
                        '</span>
        </div>
      </article>';
                }),
                "join",
                "",
            ),
        ) .
        '
    </div>
    <div class="sec-head" style="margin-top:clamp(40px,6vw,70px)">
      <span class="kicker">Events</span>
      <h2>Upcoming Events</h2>
    </div>
    ' .
        js_string(
            js_truth(g(g($db, "events"), "length"))
                ? jm(
                    jm(g($db, "events"), "map", function ($e = null) {
                        return '<div class="announce"><div class="news-meta"><span class="news-cat">' .
                            js_string(
                                esc(
                                    js_or(g($e, "category"), function () {
                                        return "Event";
                                    }),
                                ),
                            ) .
                            "</span><span>" .
                            js_string(esc(g($e, "dateLabel"))) .
                            "</span></div><h4>" .
                            js_string(esc(g($e, "title"))) .
                            "</h4><p>" .
                            js_string(esc(g($e, "body"))) .
                            "</p></div>";
                    }),
                    "join",
                    "",
                )
                : '
    <div class="note-strip">' .
                    js_string(a_layout_icon("calendar")) .
                    "<span>Approved upcoming events from the school calendar will be published here once released by the school office. For current term dates and events, please contact the school office.</span></div>",
        ) .
        '
  </div>
</section>';
    return [
        "body" => $body,
        "meta" => [
            "title" =>
                "News & Events — Alpha Adventist Pre & Primary School, Kigoma",
            "desc" =>
                "Official news and events from Alpha Adventist Pre & Primary School in Kigoma: graduation, academic news, spiritual events, ICT updates and school announcements.",
        ],
    ];
}
function a_pages_news_article($ctx = null, $slug = null)
{
    $db = g($ctx, "db");
    $n = jm(g($db, "news"), "find", function ($a = null) use (&$slug) {
        return g($a, "slug") === $slug;
    });
    if (js_truth(!js_truth($n))) {
        return null;
    }
    $related = jm(
        jm(
            jm(g($db, "news"), "filter", function ($a = null) use (&$n) {
                return js_and($a !== $n, function () use (&$n, &$a) {
                    return g($a, "category") === g($n, "category");
                });
            }),
            "concat",
            jm(g($db, "news"), "filter", function ($a = null) use (&$n) {
                return js_and($a !== $n, function () use (&$n, &$a) {
                    return g($a, "category") !== g($n, "category");
                });
            }),
        ),
        "slice",
        0,
        2,
    );
    $shareText = js_encodeURIComponent(
        js_add(g($n, "title"), " — Alpha Adventist Pre & Primary School"),
    );
    $shareUrl = js_encodeURIComponent(
        js_add(js_add(a_layout_baseOf($ctx), "/news/"), g($n, "slug")),
    );
    $waShare =
        "https://wa.me/?text=" .
        js_string($shareText) .
        "%20" .
        js_string($shareUrl) .
        "";
    $body =
        '
' .
        js_string(
            a_pages_about_phero(
                $ctx,
                "News & Events",
                g($n, "title"),
                g($n, "excerpt"),
                g($n, "image"),
            ),
        ) .
        '
<section class="sec">
  <div class="container">
    <article class="article">
      <div class="news-meta" style="margin-bottom:18px"><span class="news-cat">' .
        js_string(esc(g($n, "category"))) .
        "</span><span>" .
        js_string(esc(g($n, "dateLabel"))) .
        "</span><span>By " .
        js_string(esc(g($n, "author"))) .
        '</span></div>
      <figure>
        ' .
        js_string(
            a_layout_pic(
                g($n, "image"),
                js_or(g($n, "alt"), function () use (&$n) {
                    return g($n, "title");
                }),
                [
                    "widths" => [800, 1200, 1600],
                    "sizes" => "(max-width: 820px) 92vw, 820px",
                    "eager" => true,
                ],
            ),
        ) .
        '
        <figcaption>' .
        js_string(
            esc(
                js_or(g($n, "alt"), function () use (&$n) {
                    return g($n, "title");
                }),
            ),
        ) .
        ' — Alpha Adventist Pre &amp; Primary School</figcaption>
      </figure>
      <p class="article__lead">' .
        js_string(esc(g($n, "excerpt"))) .
        '</p>
      ' .
        js_string(paragraphs(g($n, "content"))) .
        '
      ' .
        js_string(
            js_truth(
                js_and(g($n, "gallery"), function () use (&$n) {
                    return g(g($n, "gallery"), "length") > 1;
                }),
            )
                ? '
      <h3 style="margin-top:30px">From this event</h3>
      <div class="gal-grid" style="columns:2">
        ' .
                    js_string(
                        jm(
                            jm(g($n, "gallery"), "map", function (
                                $g = null,
                            ) use (&$db, &$n) {
                                $item = jm(g($db, "gallery"), "find", function (
                                    $x = null,
                                ) use (&$g) {
                                    return g($x, "img") === $g;
                                });
                                return '<figure class="gal-item" style="cursor:default">' .
                                    js_string(
                                        a_layout_pic(
                                            $g,
                                            js_or(
                                                js_and($item, function () use (
                                                    &$item,
                                                ) {
                                                    return g($item, "alt");
                                                }),
                                                function () use (&$n) {
                                                    return g($n, "title");
                                                },
                                            ),
                                            [
                                                "widths" => [480, 800],
                                                "sizes" => "45vw",
                                            ],
                                        ),
                                    ) .
                                    "<figcaption>" .
                                    js_string(
                                        esc(
                                            js_or(
                                                js_and($item, function () use (
                                                    &$item,
                                                ) {
                                                    return g($item, "caption");
                                                }),
                                                function () use (&$n) {
                                                    return g($n, "title");
                                                },
                                            ),
                                        ),
                                    ) .
                                    "</figcaption></figure>";
                            }),
                            "join",
                            "",
                        ),
                    ) .
                    '
      </div>'
                : "",
        ) .
        '
      <div class="share">
        <span class="muted" style="font-size:.85rem;font-weight:700">Share:</span>
        <a href="' .
        js_string($waShare) .
        '" target="_blank" rel="noopener">' .
        js_string(a_layout_icon("whatsapp")) .
        ' WhatsApp</a>
        <a href="https://www.facebook.com/sharer/sharer.php?u=' .
        js_string($shareUrl) .
        '" target="_blank" rel="noopener">' .
        js_string(a_layout_icon("share")) .
        ' Facebook</a>
        <a href="#" data-copy-link>' .
        js_string(a_layout_icon("link")) .
        ' Copy link</a>
      </div>
    </article>
    ' .
        js_string(
            js_truth(g($related, "length"))
                ? '
    <div style="margin-top:clamp(40px,6vw,70px)">
      ' .
                    js_string(
                        a_layout_sectionHead(
                            "Related Articles",
                            "More from Alpha",
                        ),
                    ) .
                    '
      <div class="grid grid--2">
        ' .
                    js_string(
                        jm(
                            jm($related, "map", function ($r = null) {
                                return '
        <article class="news-card" style="flex-direction:row">
          <div class="news-card__media" style="aspect-ratio:auto;width:36%;flex:none;min-height:140px">' .
                                    js_string(
                                        a_layout_pic(
                                            g($r, "image"),
                                            js_or(
                                                g($r, "alt"),
                                                function () use (&$r) {
                                                    return g($r, "title");
                                                },
                                            ),
                                            [
                                                "widths" => [480, 800],
                                                "sizes" => "30vw",
                                            ],
                                        ),
                                    ) .
                                    '</div>
          <div class="news-card__in">
            <div class="news-meta"><span class="news-cat">' .
                                    js_string(esc(g($r, "category"))) .
                                    "</span><span>" .
                                    js_string(esc(g($r, "dateLabel"))) .
                                    '</span></div>
            <h3><a href="/news/' .
                                    js_string(esc(g($r, "slug"))) .
                                    '">' .
                                    js_string(esc(g($r, "title"))) .
                                    '</a></h3>
          </div>
        </article>';
                            }),
                            "join",
                            "",
                        ),
                    ) .
                    '
      </div>
    </div>'
                : "",
        ) .
        '
  </div>
</section>';
    return [
        "body" => $body,
        "meta" => [
            "title" =>
                "" .
                js_string(g($n, "title")) .
                " — Alpha Adventist Pre & Primary School",
            "desc" => g($n, "excerpt"),
            "ogType" => "article",
            "jsonld" => [
                "@context" => "https://schema.org",
                "@type" => "NewsArticle",
                "headline" => g($n, "title"),
                "description" => g($n, "excerpt"),
                "author" => [
                    "@type" => "Organization",
                    "name" => g($n, "author"),
                ],
                "datePublished" => js_or(g($n, "sortDate"), function () {
                    return null;
                }),
                "image" => js_add(
                    a_layout_baseOf($ctx),
                    js_truth(g($n, "mediaId"))
                        ? js_add("/media/", g($n, "mediaId"))
                        : "/img/og-image.png",
                ),
                "publisher" => [
                    "@type" => "Organization",
                    "name" => "Alpha Adventist Pre & Primary School",
                ],
            ],
        ],
    ];
}
