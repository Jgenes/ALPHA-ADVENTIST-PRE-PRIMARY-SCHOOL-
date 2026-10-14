<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
define("a_pages_gallery_CATS", [
    "Campus",
    "Classrooms",
    "Computer Learning",
    "Sports",
    "Music & Choir",
    "Worship",
    "Talent",
    "Graduation",
    "School Events",
    "School Life",
]);
define("a_pages_gallery_SCHOOL_PHOTOS", [
    [
        "image" => "campus",
        "cat" => "Campus",
        "caption" => "Alpha school campus in Kigoma",
        "alt" => "School buildings and courtyard at Alpha",
    ],
    [
        "image" => "gate",
        "cat" => "Campus",
        "caption" => "The school entrance",
        "alt" => "Entrance gate and grounds at Alpha",
    ],
    [
        "image" => "classroom",
        "cat" => "Classrooms",
        "caption" => "Learning in the classroom",
        "alt" => "Pupils taking part in a classroom lesson",
    ],
    [
        "image" => "exhibit-pupils",
        "cat" => "School Life",
        "caption" => "Pupils sharing a school project",
        "alt" => "Pupils presenting a project at a school event",
    ],
    [
        "image" => "garden-project",
        "cat" => "Talent",
        "caption" => "Learning through the school garden",
        "alt" => "Pupils caring for plants in the school garden",
    ],
    [
        "image" => "choir-green",
        "cat" => "Worship",
        "caption" => "Worship through music",
        "alt" => "Pupils singing during a school worship programme",
    ],
    [
        "image" => "choir-teal",
        "cat" => "Music & Choir",
        "caption" => "The school choir performing",
        "alt" => "School choir performing at a school event",
    ],
    [
        "image" => "sports",
        "cat" => "School Life",
        "caption" => "Pupils sharing a school event",
        "alt" => "Pupils standing together during a school event",
    ],
    [
        "image" => "graduation-group",
        "cat" => "Graduation",
        "caption" => "Graduation day at Alpha",
        "alt" => "Pupils and school community at a graduation ceremony",
    ],
    [
        "image" => "graduation-certificate",
        "cat" => "School Events",
        "caption" => "Celebrating pupil achievement",
        "alt" => "A pupil receiving a certificate at a school ceremony",
    ],
    [
        "image" => "staff-group",
        "cat" => "School Life",
        "caption" => "School leadership and staff",
        "alt" => "School leaders and staff at Alpha",
    ],
    [
        "image" => "school-community",
        "cat" => "School Life",
        "caption" => "The Alpha school community",
        "alt" => "Pupils gathered for a school community photograph",
    ],
]);
function a_pages_gallery_render($ctx = null)
{
    $db = g($ctx, "db");
    $photos = [
        ...js_array(a_pages_gallery_SCHOOL_PHOTOS),
        ...js_array(g($db, "gallery")),
    ];
    $used = jm(a_pages_gallery_CATS, "filter", function ($c = null) use (
        &$photos,
    ) {
        return jm($photos, "some", function ($g = null) use (&$c) {
            return g($g, "cat") === $c;
        });
    });
    $body =
        '
' .
        js_string(
            a_pages_about_phero(
                $ctx,
                "Gallery",
                "Alpha in Pictures",
                "Approved photographs from ceremonies, classrooms, choir, campus and daily life at Alpha Adventist Pre & Primary School.",
                "choir-teal",
            ),
        ) .
        '
<section class="sec">
  <div class="container">
    <p class="note-strip" style="margin-bottom:24px">' .
        js_string(a_layout_icon("lock")) .
        '<span>Photographs are published with school approval. Captions do not identify individual pupils. Request a withdrawal through our <a href="/privacy">privacy page</a>.</span></p>
    <div class="gal-filters" role="group" aria-label="Filter gallery by category">
      <button type="button" data-gal-filter="all" aria-pressed="true">All photographs</button>
      ' .
        js_string(
            jm(
                jm($used, "map", function ($c = null) {
                    return '<button type="button" data-gal-filter="' .
                        js_string(esc($c)) .
                        '" aria-pressed="false">' .
                        js_string(esc($c)) .
                        "</button>";
                }),
                "join",
                "",
            ),
        ) .
        '
    </div>
    <div class="gal-grid" data-gallery>
      ' .
        js_string(
            jm(
                jm($photos, "map", function ($g = null) {
                    return '
      <button class="gal-item" type="button" data-cat="' .
                        js_string(esc(g($g, "cat"))) .
                        '" data-caption="' .
                        js_string(esc(g($g, "caption"))) .
                        '" aria-label="View photograph: ' .
                        js_string(esc(g($g, "caption"))) .
                        '">
        ' .
                        js_string(
                            a_layout_pic(
                                js_or(g($g, "image"), function () use (&$g) {
                                    return g($g, "img");
                                }),
                                g($g, "alt"),
                                [
                                    "widths" => [480, 800, 1200],
                                    "sizes" =>
                                        "(max-width: 600px) 92vw, (max-width: 980px) 46vw, 31vw",
                                ],
                            ),
                        ) .
                        '
        <figcaption>' .
                        js_string(esc(g($g, "caption"))) .
                        '</figcaption>
      </button>';
                }),
                "join",
                "",
            ),
        ) .
        '
    </div>
    <p class="note-strip" style="margin-top:30px">' .
        js_string(a_layout_icon("lock")) .
        '<span><strong>Child privacy:</strong> only approved school photographs are published on this website. Captions describe activities and never identify individual pupils. Media is removed from public access when consent expires or is withdrawn.</span></p>
  </div>
</section>
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Photograph viewer">
  <button class="lightbox__btn lightbox__close" type="button" aria-label="Close viewer">' .
        js_string(a_layout_icon("close")) .
        '</button>
  <button class="lightbox__btn lightbox__prev" type="button" aria-label="Previous photograph">' .
        js_string(a_layout_icon("arrow", "flip")) .
        '</button>
  <img src="" alt="">
  <button class="lightbox__btn lightbox__next" type="button" aria-label="Next photograph">' .
        js_string(a_layout_icon("arrow")) .
        '</button>
  <p class="lightbox__cap"></p>
</div>';
    return [
        "body" => $body,
        "meta" => [
            "title" => "Gallery — Alpha Adventist Pre & Primary School, Kigoma",
            "desc" =>
                "Photograph gallery of Alpha Adventist Pre & Primary School, Kigoma: graduation ceremonies, school choir, campus, academic exhibitions and school life.",
        ],
    ];
}
