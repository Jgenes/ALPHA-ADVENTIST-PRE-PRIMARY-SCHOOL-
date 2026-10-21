<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_pages_parents_render($ctx = null)
{
    $db = g($ctx, "db");
    $s = g($db, "settings");
    $wa =
        "https://wa.me/" .
        js_string(g(g($s, "whatsapp"), "href")) .
        "?text=" .
        js_string(
            js_encodeURIComponent(
                "Hello Alpha Adventist Pre & Primary School, I am a parent/guardian and would like information.",
            ),
        ) .
        "";
    $announcementContent = js_truth(g(g($db, "announcements"), "length"))
        ? '<div class="parent-modal__notices">' .
            js_string(
                jm(
                    jm(g($db, "announcements"), "map", function ($a = null) {
                        return '<article class="announce"><div class="news-meta"><span class="news-cat">' .
                            js_string(esc(g($a, "tag"))) .
                            "</span><span>" .
                            js_string(esc(g($a, "dateLabel"))) .
                            "</span></div><h3>" .
                            js_string(esc(g($a, "title"))) .
                            "</h3><p>" .
                            js_string(esc(g($a, "body"))) .
                            "</p></article>";
                    }),
                    "join",
                    "",
                ),
            ) .
            "</div>"
        : '<p class="muted">There are no current announcements. Official notices will appear here when published by the school.</p>';
    $news = jm(g($db, "news"), "filter", function ($item = null) {
        return js_or(g($item, "language"), function () {
            return "en";
        }) === "en";
    });
    $events = js_or(g($db, "events"), function () {
        return [];
    });
    $downloads = js_or(g($ctx, "downloads"), function () {
        return [];
    });
    $parentItems = [
        ...js_array(
            js_truth(g($events, "length"))
                ? [
                    [
                        "icon" => "calendar",
                        "title" => "School Calendar",
                        "teaser" => "Published school dates and events",
                        "status" => "Published dates",
                        "content" => jm(
                            jm($events, "map", function ($item = null) {
                                return '<article class="announce"><h3>' .
                                    js_string(esc(g($item, "title"))) .
                                    "</h3><p>" .
                                    js_string(
                                        esc(
                                            js_or(
                                                g($item, "eventDate"),
                                                function () use (&$item) {
                                                    return g(
                                                        $item,
                                                        "dateLabel",
                                                    );
                                                },
                                            ),
                                        ),
                                    ) .
                                    " — " .
                                    js_string(esc(g($item, "excerpt"))) .
                                    "</p></article>";
                            }),
                            "join",
                            "",
                        ),
                    ],
                ]
                : [],
        ),
        ...js_array(
            js_truth(g($news, "length"))
                ? [
                    [
                        "icon" => "mega",
                        "title" => "School News & Announcements",
                        "teaser" => "Published updates from the school",
                        "status" => "Published school updates",
                        "content" => jm(
                            jm(jm($news, "slice", 0, 6), "map", function (
                                $item = null,
                            ) {
                                return '<article class="announce"><h3><a href="/news/' .
                                    js_string(esc(g($item, "slug"))) .
                                    '">' .
                                    js_string(esc(g($item, "title"))) .
                                    "</a></h3><p>" .
                                    js_string(esc(g($item, "excerpt"))) .
                                    "</p></article>";
                            }),
                            "join",
                            "",
                        ),
                    ],
                ]
                : [],
        ),
        [
            "icon" => "download",
            "title" => "Policies & Downloads",
            "teaser" => "The current approved public documents",
            "status" => js_truth(g($downloads, "length"))
                ? "Approved public documents"
                : "Contact the office for current documents",
            "content" => js_truth(g($downloads, "length"))
                ? '<ul class="parent-modal__list">' .
                    js_string(
                        jm(
                            jm($downloads, "map", function ($item = null) {
                                return '<li><a href="/downloads/' .
                                    js_string(esc(g($item, "id"))) .
                                    '">' .
                                    js_string(esc(g($item, "title"))) .
                                    "</a> · Version " .
                                    js_string(g($item, "version")) .
                                    "</li>";
                            }),
                            "join",
                            "",
                        ),
                    ) .
                    "</ul>"
                : '<p>The school office can help you obtain current joining information and approved guidance. Private pupil and employment records are never listed publicly.</p><p><a href="/downloads">Open the download centre</a></p>',
        ],
        [
            "icon" => "bed",
            "title" => "Boarding Information",
            "teaser" => "Confirm current arrangements with the school office",
            "status" => "Office guidance",
            "content" =>
                '<p>Contact the school office to confirm current boarding availability, supervision, routines and joining requirements for your child’s class.</p><p><a href="/admissions">Admissions and school visits</a></p>',
        ],
        [
            "icon" => "phone",
            "title" => "Contact the School",
            "teaser" => "Official phone, WhatsApp and email channels",
            "status" => "School contact channels",
            "content" =>
                "<p>" .
                js_string(esc(g($s, "officeHours"))) .
                '</p><div class="parent-modal__contact"><a href="tel:' .
                js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
                '">' .
                js_string(a_layout_icon("phone")) .
                "<span><strong>Call the school office</strong><small>" .
                js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
                '</small></span></a><a href="' .
                js_string(esc($wa)) .
                '" target="_blank" rel="noopener noreferrer">' .
                js_string(a_layout_icon("whatsapp")) .
                "<span><strong>WhatsApp</strong><small>" .
                js_string(esc(g(g($s, "whatsapp"), "number"))) .
                '</small></span></a><a href="mailto:' .
                js_string(esc(g($s, "email"))) .
                '">' .
                js_string(a_layout_icon("mail")) .
                "<span><strong>Email</strong><small>" .
                js_string(esc(g($s, "email"))) .
                "</small></span></a></div>",
        ],
    ];
    $body =
        '
' .
        js_string(
            a_pages_about_phero(
                $ctx,
                "Parent Corner",
                "Parent Corner",
                "Keeping Alpha families informed and connected — calendars, announcements, examinations, boarding information and direct lines to the school office.",
                "graduation-group",
            ),
        ) .
        '

<section class="sec">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "Information for Families",
                "Everything in One Place",
                "Select a card to open details. Documents approved for public distribution are published here; private pupil and family records are kept confidential.",
            ),
        ) .
        '
    <div class="parent-grid">
      ' .
        js_string(
            jm(
                jm($parentItems, "map", function ($item = null, $i = null) {
                    return '
      <button class="parent-card rv" type="button" data-parent-modal="parent-modal-' .
                        js_string($i) .
                        '" aria-haspopup="dialog" aria-controls="parent-modal-' .
                        js_string($i) .
                        '">
        <span class="parent-card__summary"><span class="parent-card__icon">' .
                        js_string(a_layout_icon(g($item, "icon"))) .
                        '</span><span class="parent-card__heading"><strong>' .
                        js_string(esc(g($item, "title"))) .
                        "</strong><small>" .
                        js_string(esc(g($item, "teaser"))) .
                        '</small></span></span>
        <span class="parent-card__open">View details ' .
                        js_string(a_layout_icon("arrow")) .
                        '</span>
      </button>';
                }),
                "join",
                "",
            ),
        ) .
        '
    </div>
    ' .
        js_string(
            jm(
                jm($parentItems, "map", function ($item = null, $i = null) {
                    return '
    <dialog class="parent-modal" id="parent-modal-' .
                        js_string($i) .
                        '" aria-labelledby="parent-modal-title-' .
                        js_string($i) .
                        '">
      <div class="parent-modal__panel">
        <header class="parent-modal__header"><div><span class="kicker">Parent Corner</span><h2 id="parent-modal-title-' .
                        js_string($i) .
                        '">' .
                        js_string(esc(g($item, "title"))) .
                        '</h2><span class="parent-modal__status">' .
                        js_string(esc(g($item, "status"))) .
                        '</span></div><button class="parent-modal__close" type="button" data-modal-close aria-label="Close ' .
                        js_string(esc(g($item, "title"))) .
                        '">' .
                        js_string(a_layout_icon("close")) .
                        '</button></header>
        <div class="parent-modal__body">' .
                        js_string(g($item, "content")) .
                        '</div>
      </div>
    </dialog>';
                }),
                "join",
                "",
            ),
        ) .
        '
  </div>
</section>

<section class="sec">
  <div class="container split">
    <div>
      ' .
        js_string(
            a_layout_sectionHead(
                "Parent Communication",
                'Partners in Your Child\'s Development',
                "Alpha recognises parents and guardians as essential partners in the education, character formation and wellbeing of every learner. The school office is your first point of contact for anything concerning your child.",
            ),
        ) .
        '
      <ul class="checklist">
        <li>' .
        js_string(a_layout_icon("check")) .
        '<span>Phone and WhatsApp lines answered during working hours on school days.</span></li>
        <li>' .
        js_string(a_layout_icon("check")) .
        '<span>Parent meetings and graduation weekend programmes bring families to campus.</span></li>
        <li>' .
        js_string(a_layout_icon("check")) .
        '<span>Official notices published in the Parent Corner once approved for distribution.</span></li>
      </ul>
      <div class="contact-grid" style="grid-template-columns:1fr 1fr;margin-top:20px">
        <a class="contact-card" href="tel:' .
        js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
        '">' .
        js_string(a_layout_icon("phone")) .
        "<strong>Call</strong><span>" .
        js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
        '</span></a>
        <a class="contact-card" href="' .
        js_string(esc($wa)) .
        '" target="_blank" rel="noopener">' .
        js_string(a_layout_icon("whatsapp")) .
        "<strong>WhatsApp</strong><span>" .
        js_string(esc(g(g($s, "whatsapp"), "number"))) .
        '</span></a>
      </div>
    </div>
    <div class="card rv">
      <div class="card__ico">' .
        js_string(a_layout_icon("shield")) .
        '</div><h3>Your Family’s Privacy Matters</h3>
      <p>Individual pupil results, attendance, fee balances and teacher messages are private. Family academic services are not active on this public website; the school office will explain the approved way to obtain your child’s information.</p>
      <p>' .
        js_string(
            a_layout_btn(
                "/privacy",
                "Privacy & Safeguarding",
                "ghost",
                "arrow",
            ),
        ) .
        '</p>
    </div>
  </div>
</section>

';
    return [
        "body" => $body,
        "meta" => [
            "title" =>
                "Parent Corner — Alpha Adventist Pre & Primary School, Kigoma",
            "desc" =>
                "The Parent Corner of Alpha Adventist Pre & Primary School: school calendar, announcements, examinations, school rules, boarding information, parent communication and contact channels for Alpha families in Kigoma.",
        ],
    ];
}
