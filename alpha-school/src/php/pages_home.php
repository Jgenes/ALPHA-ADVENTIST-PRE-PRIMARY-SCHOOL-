<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
define(
    "a_pages_home_originalSlides",
    json_decode(
        file_get_contents(dirname(__DIR__) . "/content/home-slides.json"),
        true,
    ),
);
function a_pages_home_heroSection($ctx = null)
{
    $sw = g($ctx, "lang") === "sw";
    $banners = jm(
        js_or(g($ctx, "content"), function () {
            return [];
        }),
        "filter",
        function ($item = null) use (&$sw) {
            return js_and(g($item, "kind") === "banner", function () use (
                &$sw,
                &$item,
            ) {
                return g($item, "language") === (js_truth($sw) ? "sw" : "en");
            });
        },
    );
    $fallback = js_or(g($ctx, "homeSlides"), function () {
        return a_pages_home_originalSlides;
    });
    $slides = js_truth(g($banners, "length"))
        ? jm($banners, "map", function ($item = null) use (&$ctx, &$sw) {
            return [
                "kicker" => js_truth($sw)
                    ? "Habari za Shule"
                    : "School announcement",
                "title" => g($item, "title"),
                "text" => g($item, "excerpt"),
                "image" => js_truth(g($item, "mediaId"))
                    ? js_add("/media/", g($item, "mediaId"))
                    : "",
                "alt" => g($item, "title"),
                "cta" => [
                    "href" => js_add(
                        a_layout_localPath($ctx, "/admissions"),
                        "#apply",
                    ),
                    "label" => js_truth($sw)
                        ? "Omba Nafasi"
                        : "Apply for Admission",
                ],
                "cta2" => [
                    "href" => a_layout_localPath($ctx, "/contact"),
                    "label" => js_truth($sw)
                        ? "Wasiliana na Shule"
                        : "Contact the School",
                ],
            ];
        })
        : $fallback;
    $slideHtml = jm(
        jm($slides, "map", function ($s = null, $i = null) use (
            &$sw,
            &$slides,
        ) {
            return '
    <div class="hero__slide' .
                js_string(js_truth($i === 0) ? " is-active" : "") .
                '" role="group" aria-roledescription="slide" aria-label="' .
                js_string(js_truth($sw) ? "Picha" : "Slide") .
                " " .
                js_string(js_add($i, 1)) .
                " / " .
                js_string(g($slides, "length")) .
                ": " .
                js_string(esc(g($s, "kicker"))) .
                '" aria-hidden="' .
                js_string(js_truth($i === 0) ? "false" : "true") .
                '" ' .
                js_string(js_truth($i) ? "inert" : "") .
                '>
      <div class="hero__media">' .
                js_string(
                    a_layout_pic(g($s, "image"), g($s, "alt"), [
                        "widths" => [800, 1200, 1600],
                        "eager" => $i === 0,
                        "sizes" => "100vw",
                        "w" => 1600,
                        "h" => 1066,
                    ]),
                ) .
                '</div>
      <div class="container hero__in">
        <p class="hero__kicker">' .
                js_string(esc(g($s, "kicker"))) .
                '</p>
        ' .
                js_string(
                    js_truth(g($s, "strap"))
                        ? '<p class="hero__strap">' .
                            js_string(esc(g($s, "strap"))) .
                            "</p>"
                        : "",
                ) .
                '
        ' .
                js_string(
                    js_truth($i === 0)
                        ? '<h1 class="hero__title">' .
                            js_string(esc(g($s, "title"))) .
                            "</h1>"
                        : '<h2 class="hero__title">' .
                            js_string(esc(g($s, "title"))) .
                            "</h2>",
                ) .
                '
        <p class="hero__text">' .
                js_string(esc(g($s, "text"))) .
                '</p>
        <div class="hero__ctas">
          ' .
                js_string(
                    a_layout_btn(
                        g(g($s, "cta"), "href"),
                        g(g($s, "cta"), "label"),
                        "gold",
                    ),
                ) .
                '
          ' .
                js_string(
                    js_truth(g($s, "cta2"))
                        ? a_layout_btn(
                            g(g($s, "cta2"), "href"),
                            g(g($s, "cta2"), "label"),
                            "outline-light",
                        )
                        : "",
                ) .
                '
        </div>
      </div>
    </div>';
        }),
        "join",
        "",
    );
    $dots = jm(
        jm($slides, "map", function ($s = null, $i = null) use (&$sw) {
            return '<button type="button" aria-current="' .
                js_string($i === 0) .
                '" aria-label="' .
                js_string(js_truth($sw) ? "Chagua ukurasa" : "Go to slide") .
                " " .
                js_string(js_add($i, 1)) .
                ": " .
                js_string(esc(g($s, "kicker"))) .
                '"></button>';
        }),
        "join",
        "",
    );
    return '
  <section class="hero" data-hero aria-roledescription="carousel" aria-label="Welcome to Alpha Adventist Pre & Primary School">
    ' .
        js_string($slideHtml) .
        '
    <div class="hero__nav">
      <div class="container hero__nav-in">
        <div class="hero__dots" role="group" aria-label="Choose slide">' .
        js_string($dots) .
        '</div>
        <div class="hero__arrows">
          <button type="button" data-hero-prev aria-label="' .
        js_string(js_truth($sw) ? "Ukurasa uliotangulia" : "Previous slide") .
        '">' .
        js_string(a_layout_icon("arrow", "flip")) .
        '</button>
          <button type="button" data-hero-next aria-label="' .
        js_string(js_truth($sw) ? "Ukurasa unaofuata" : "Next slide") .
        '">' .
        js_string(a_layout_icon("arrow")) .
        '</button>
          <button type="button" data-hero-pause aria-pressed="false" aria-label="' .
        js_string(js_truth($sw) ? "Sitisha slaidi" : "Pause slideshow") .
        '">' .
        js_string(a_layout_icon("pause")) .
        '</button>
        </div>
      </div>
    </div>
    <p class="hero__tag">' .
        js_string(
            js_truth(
                jm($slides, "some", function ($slide = null) {
                    return g($slide, "image");
                }),
            )
                ? (js_truth($sw)
                    ? "Picha zilizoidhinishwa na shule · Kigoma, Tanzania"
                    : "School-approved media · Kigoma, Tanzania")
                : (js_truth($sw)
                    ? "Picha za shule zitaonekana baada ya uthibitisho wa ridhaa na uchapishaji."
                    : "School photographs appear after consent and publication approval."),
        ) .
        '</p>
  </section>';
}
function a_pages_home_render($ctx = null)
{
    if (js_truth(g($ctx, "lang") === "sw")) {
        return a_pages_home_sw_render($ctx, "a_pages_home_heroSection");
    }
    $db = g($ctx, "db");
    $s = g($db, "settings");
    $news = jm(g($db, "news"), "filter", function ($n = null) {
        return js_or(g($n, "language"), function () {
            return "en";
        }) === "en";
    });
    $featured = js_or(
        jm($news, "find", function ($n = null) {
            return g($n, "featured");
        }),
        function () use (&$news) {
            return g($news, 0);
        },
    );
    $others = jm(
        jm($news, "filter", function ($n = null) use (&$featured) {
            return $n !== $featured;
        }),
        "slice",
        0,
        2,
    );
    $wa =
        "https://wa.me/" .
        js_string(g(g($s, "whatsapp"), "href")) .
        "?text=" .
        js_string(
            js_encodeURIComponent(
                "Hello Alpha Adventist Pre & Primary School, I would like to enquire about admission.",
            ),
        ) .
        "";
    $body =
        '
' .
        js_string(a_pages_home_heroSection($ctx)) .
        '

<!-- 4 · Quick features from the official website content brief -->
<section class="trust" aria-label="Why choose Alpha">
  <div class="container">
    <div class="trust__grid">
      <a class="trust__card rv" href="/academics"><div class="trust__ico" aria-hidden="true">' .
        js_string(a_layout_icon("book")) .
        '</div><div><h3>Quality Education</h3><p>Structured learning that develops strong academic foundations, confidence, curiosity and lifelong learning skills.</p></div></a>
      <a class="trust__card rv" href="/faith"><div class="trust__ico" aria-hidden="true">' .
        js_string(a_layout_icon("flame")) .
        '</div><div><h3>Christian Values</h3><p>Christian education that promotes faith, integrity, respect, discipline, responsibility and service.</p></div></a>
      <a class="trust__card rv" href="/computer-learning"><div class="trust__ico" aria-hidden="true">' .
        js_string(a_layout_icon("chip")) .
        '</div><div><h3>Computer &amp; Digital Learning</h3><p>Practical digital learning that prepares pupils to use technology confidently, creatively, responsibly and safely.</p></div></a>
      <a class="trust__card rv" href="/school-life#talent"><div class="trust__ico" aria-hidden="true">' .
        js_string(a_layout_icon("star")) .
        '</div><div><h3>Talent Development</h3><p>Opportunities to discover and develop abilities through creativity, music, communication and technology.</p></div></a>
      <a class="trust__card rv" href="/privacy"><div class="trust__ico" aria-hidden="true">' .
        js_string(a_layout_icon("shield")) .
        '</div><div><h3>Supportive Learning Environment</h3><p>A disciplined, supportive and child-friendly environment that encourages learning, responsibility and positive relationships.</p></div></a>
      <a class="trust__card rv" href="/about#head-of-school"><div class="trust__ico" aria-hidden="true">' .
        js_string(a_layout_icon("users")) .
        '</div><div><h3>Dedicated Teachers</h3><p>Teachers committed to guiding learners through quality instruction, encouragement, care and professional responsibility.</p></div></a>
    </div>
  </div>
</section>

<!-- 5 · Who we are -->
<section class="sec" id="who-we-are">
  <div class="container split">
    <div class="media-stack rv">
      <div class="media-stack__main">' .
        js_string(
            a_layout_pic(
                "graduation-group",
                "Guests, board members, staff and pupils at the Alpha Adventist 9th Graduation Ceremony",
                ["widths" => [800, 1200, 1600], "ratio" => "4/3"],
            ),
        ) .
        '</div>
      <div class="media-stack__float">' .
        js_string(
            a_layout_pic(
                "garden-project",
                "Alpha pupils watering vegetables in the school garden",
                ["widths" => [480, 800], "ratio" => "4/3"],
            ),
        ) .
        '</div>
      <div class="media-stack__badge"><strong>9th</strong>Graduation Ceremony celebrated with parents &amp; guardians</div>
    </div>
    <div>
      ' .
        js_string(
            a_layout_sectionHead(
                "Who We Are",
                "A Learning Community Built on Wisdom in Truth",
                "Alpha Adventist Pre & Primary School is a Seventh-day Adventist educational institution within the Western Tanzania Conference – Kigoma. Guided by Christian principles, the school is committed to holistic education that supports the physical, mental, social and spiritual development of every learner.",
            ),
        ) .
        '
      <ul class="checklist">
        <li>' .
        js_string(a_layout_icon("check")) .
        '<span><strong>Academic growth</strong> — structured learning from KG I to Standard VII, with assessment and academic monitoring.</span></li>
        <li>' .
        js_string(a_layout_icon("check")) .
        '<span><strong>Faith &amp; character</strong> — worship, Bible learning and Christian values shaping integrity, discipline and service.</span></li>
        <li>' .
        js_string(a_layout_icon("check")) .
        '<span><strong>Creativity &amp; confidence</strong> — choir, academic exhibits, leadership and talent opportunities across school life.</span></li>
        <li>' .
        js_string(a_layout_icon("check")) .
        '<span><strong>Digital capability</strong> — age-appropriate computer learning and digital safety for the modern world.</span></li>
        <li>' .
        js_string(a_layout_icon("check")) .
        '<span><strong>Social responsibility</strong> — practical activities, teamwork and good citizenship within a caring community.</span></li>
      </ul>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        ' .
        js_string(
            a_layout_btn("/about", "More About Alpha", "primary", "arrow"),
        ) .
        '
        ' .
        js_string(
            a_layout_btn("/admissions#visit", "Book a School Visit", "ghost"),
        ) .
        '
      </div>
    </div>
  </div>
</section>

<!-- 6 · Six pillars -->
<section class="sec sec--sand" id="pillars">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "The Six Alpha Pillars",
                "Building the Whole Child",
                "At Alpha, education goes beyond classroom instruction. We seek to support the academic, spiritual, social, physical and personal development of every learner.",
                ["align" => "center"],
            ),
        ) .
        '
    <div class="pillars">
      ' .
        js_string(
            jm(
                jm(g($db, "pillars"), "map", function ($p = null, $i = null) {
                    return '
      <article class="pillar rv">
        <span class="pillar__num" aria-hidden="true">0' .
                        js_string(js_add($i, 1)) .
                        '</span>
        <div class="pillar__ico" aria-hidden="true">' .
                        js_string(
                            a_layout_icon(
                                js_truth(g($p, "key") === "education")
                                    ? "book"
                                    : (js_truth(g($p, "key") === "faith")
                                        ? "flame"
                                        : (js_truth(
                                            g($p, "key") === "technology",
                                        )
                                            ? "chip"
                                            : (js_truth(
                                                g($p, "key") === "talent",
                                            )
                                                ? "star"
                                                : (js_truth(
                                                    g($p, "key") ===
                                                        "character",
                                                )
                                                    ? "shield"
                                                    : "rocket")))),
                            ),
                        ) .
                        '</div>
        <h3>' .
                        js_string(esc(g($p, "title"))) .
                        '</h3>
        <p>' .
                        js_string(esc(g($p, "desc"))) .
                        '</p>
        <a class="link-more" href="' .
                        js_string(esc(g($p, "href"))) .
                        '">Learn more ' .
                        js_string(a_layout_icon("arrow")) .
                        '</a>
      </article>';
                }),
                "join",
                "",
            ),
        ) .
        '
    </div>
  </div>
</section>

<!-- 7 · Academic programmes -->
<section class="sec" id="academics">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "Academic Programmes",
                "From First Steps to Standard Seven",
                "A clear learning pathway across Pre-Primary and Primary, delivered in day and boarding arrangements with teacher guidance and continuous academic monitoring.",
            ),
        ) .
        '
    <div class="levels">
      <div class="level-box rv">
        <h3>' .
        js_string(a_layout_icon("sun")) .
        ' Pre-Primary</h3>
        <p class="muted" style="font-size:.92rem">Early learning that builds language, numbers, social skills, discipline and curiosity in a caring, faith-filled environment.</p>
        <ul class="level-chips">' .
        js_string(
            jm(
                jm(g(g($db, "academics"), "prePrimary"), "map", function (
                    $l = null,
                ) {
                    return "<li>" . js_string(esc($l)) . "</li>";
                }),
                "join",
                "",
            ),
        ) .
        '</ul>
      </div>
      <div class="level-box rv">
        <h3>' .
        js_string(a_layout_icon("book")) .
        ' Primary</h3>
        <p class="muted" style="font-size:.92rem">Progressive primary education through the national pathway, preparing learners for examinations, secondary education and life.</p>
        <ul class="level-chips">' .
        js_string(
            jm(
                jm(g(g($db, "academics"), "primary"), "map", function (
                    $l = null,
                ) {
                    return "<li>" . js_string(esc($l)) . "</li>";
                }),
                "join",
                "",
            ),
        ) .
        '</ul>
      </div>
    </div>
    <div class="grid grid--2" style="margin-top:22px">
      ' .
        js_string(
            jm(
                jm(g(g($db, "academics"), "arrangements"), "map", function (
                    $a = null,
                    $i = null,
                ) {
                    return '
      <div class="card rv" style="display:flex;gap:16px;align-items:flex-start">
        <div class="card__ico" style="margin:0">' .
                        js_string(
                            a_layout_icon(js_truth($i === 0) ? "home" : "bed"),
                        ) .
                        '</div>
        <div><h3>' .
                        js_string(esc(g($a, "title"))) .
                        "</h3><p>" .
                        js_string(esc(g($a, "desc"))) .
                        '</p></div>
      </div>';
                }),
                "join",
                "",
            ),
        ) .
        '
    </div>
    <p style="margin-top:26px">' .
        js_string(
            a_layout_btn("/academics", "Explore Academics", "primary", "arrow"),
        ) .
        '</p>
  </div>
</section>

<!-- 8 · Why choose Alpha -->
<section class="sec sec--sand" id="why-alpha">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "Why Choose Alpha",
                "Education Families Can Trust",
                'Every statement below reflects Alpha\'s documented philosophy, governance and programmes — not marketing promises.',
                ["align" => "center"],
            ),
        ) .
        '
    <div class="grid grid--3">
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("book")) .
        '</div><h3>Quality-Focused Education</h3><p>Academic development sits at the centre of school life, supported by formal academic reporting, timetables, daily routines and programme monitoring.</p></div>
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("flame")) .
        '</div><h3>Christian Character</h3><p>As a Seventh-day Adventist school, Alpha seeks to nurture faith, integrity, discipline, respect, responsibility and service in every learner.</p></div>
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("chip")) .
        '</div><h3>Practical Technology</h3><p>Pupil ICT lessons are clear, simple and practical — smartboard-supported teaching, Computer Lab activities and digital safety education.</p></div>
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("music")) .
        '</div><h3>Talent &amp; Participation</h3><p>Choir performances, Pathfinder activities and academic exhibits give pupils real stages to discover and develop their abilities.</p></div>
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("bed")) .
        '</div><h3>Day &amp; Boarding Care</h3><p>Day and boarding pupils learn within supervised routines, evening study and a caring residential community. Boarding availability per class is confirmed by the office.</p></div>
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("heart")) .
        '</div><h3>Partnership with Parents</h3><p>Parents and guardians are recognised as essential partners — through communication, cooperation and shared responsibility for every child.</p></div>
    </div>
  </div>
</section>

<!-- 9 · Head of School -->
<section class="sec" id="leadership">
  <div class="container leader">
    <div class="leader__photo rv">
      ' .
        js_string(
            js_truth(g(g($db, "leadership"), "photo"))
                ? '<img src="' .
                    js_string(esc(g(g($db, "leadership"), "photo"))) .
                    '" alt="' .
                    js_string(esc(g(g($db, "leadership"), "name"))) .
                    ", " .
                    js_string(esc(g(g($db, "leadership"), "title"))) .
                    '">'
                : '<div class="leader__placeholder">
             <img src="/img/logo-256.png" alt="" aria-hidden="true">
             <span class="lp-name">' .
                    js_string(esc(g(g($db, "leadership"), "name"))) .
                    '</span>
             <span class="lp-role">' .
                    js_string(esc(g(g($db, "leadership"), "title"))) .
                    '</span>
             <span class="lp-note">Official portrait to be published by the school office</span>
           </div>',
        ) .
        '
    </div>
    <div>
      ' .
        js_string(
            a_layout_sectionHead(
                "Welcome from the Head of School",
                "“Together, we educate for good citizenship and eternity.”",
            ),
        ) .
        '
      <p class="muted">' .
        js_string(esc(g(g($db, "leadership"), "shortMessage"))) .
        '</p>
      <p class="leader__quote">' .
        js_string(esc(g(g($db, "leadership"), "signature"))) .
        '</p>
      <p><strong class="leader__name">' .
        js_string(esc(g(g($db, "leadership"), "name"))) .
        '</strong><br><span class="leader__role">' .
        js_string(esc(g(g($db, "leadership"), "title"))) .
        '</span></p>
      <p>' .
        js_string(
            a_layout_btn(
                "/about#head-of-school",
                "Read Full Message",
                "primary",
                "arrow",
            ),
        ) .
        '</p>
    </div>
  </div>
</section>

<!-- 10 · Computer & digital learning -->
<section class="sec techband" id="technology">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "Computer & Digital Learning",
                "Preparing Learners for the Digital Future",
                "Practical computer learning, digital creativity and responsible technology use — from KG I to Standard VII, and beyond.",
            ),
        ) .
        '
    <div class="techgrid">
      <div class="techcard rv">
        <h3>' .
        js_string(a_layout_icon("laptop")) .
        ' Pupil Computer Learning <span class="badge-now">In school</span></h3>
        <p>Age-appropriate ICT lessons built on Alpha\'s “clear • simple • practical” approach, with smartboard support and Computer Lab activities.</p>
        <div class="skill-list">
          <ul class="skillchips"><li>Computer fundamentals</li></ul>
          <details class="skillchips-more">
            <summary><span class="skill-more-label">View more</span><span class="skill-less-label">View less</span></summary>
            <ul class="skillchips">
              <li>Keyboard &amp; mouse</li><li>Typing</li><li>Files &amp; folders</li><li>Word processing</li><li>Digital creativity</li><li>Internet awareness</li><li>Digital safety</li><li>Introductory coding</li>
            </ul>
          </details>
        </div>
      </div>
      <div class="techcard rv">
        <h3>' .
        js_string(a_layout_icon("users")) .
        ' Teacher &amp; Staff ICT <span class="badge-now">Ongoing</span></h3>
        <p>School Board documentation includes professional growth for staff. Alpha is progressively strengthening digital skills for teachers and workers — email, electronic filing, records and cyber-safety awareness.</p>
        <p style="margin-top:10px">' .
        js_string(
            a_layout_btn(
                "/computer-learning#staff",
                "Staff ICT Development",
                "outline-light",
            ),
        ) .
        '</p>
      </div>
      ' .
        js_string(
            js_truth(g(g($ctx, "config"), "publicPreview"))
                ? ""
                : '<div class="techcard rv">
        <h3>' .
                    js_string(a_layout_icon("lock")) .
                    ' Staff Portal <span class="badge-now">Available</span></h3>
        <p>A secure workspace for authorised staff — leave requests, approved documents, notices and school workflows.</p>
        <p style="margin-top:10px">' .
                    js_string(a_layout_btn("/portal", "Portal Login", "gold")) .
                    '</p>
      </div>',
        ) .
        '
    </div>
    <p style="margin-top:30px">' .
        js_string(
            a_layout_btn(
                "/computer-learning",
                "Explore Computer Learning",
                "light",
                "arrow",
            ),
        ) .
        '</p>
  </div>
</section>

<!-- 11 · School life -->
<section class="sec" id="school-life">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "Life at Alpha",
                "Learning, Faith, Friendship and Discovery",
                "School life at Alpha extends beyond ordinary classroom instruction — documented activities include worship, choir, Pathfinder, academic exhibitions and Computer Lab work.",
            ),
        ) .
        '
    <div class="life-grid">
      <a class="life-card rv" href="/faith"><span class="life-card__tag">Spiritual Life</span>' .
        js_string(
            a_layout_pic(
                "choir-green",
                "Alpha pupils singing during a worship programme",
                [
                    "widths" => [480, 800],
                    "sizes" => "(max-width: 980px) 92vw, 30vw",
                ],
            ),
        ) .
        '<span class="life-card__in"><h3>Worship &amp; Spiritual Growth</h3><p>Special worship services, prayer and Bible learning shape character and faith.</p></span></a>
      <a class="life-card rv" href="/school-life#music"><span class="life-card__tag">Music & Choir</span>' .
        js_string(
            a_layout_pic(
                "choir-teal",
                "Alpha school choir performing on stage in teal uniforms",
                [
                    "widths" => [480, 800],
                    "sizes" => "(max-width: 980px) 92vw, 30vw",
                ],
            ),
        ) .
        '<span class="life-card__in"><h3>School Choir</h3><p>Choir programmes documented in School Board agenda and graduation performances.</p></span></a>
      <a class="life-card rv" href="/school-life#pathfinder"><span class="life-card__tag">Pathfinder</span>' .
        js_string(
            a_layout_pic(
                "graduation-group",
                "Pathfinder and school community group photograph at graduation",
                [
                    "widths" => [480, 800],
                    "sizes" => "(max-width: 980px) 92vw, 30vw",
                ],
            ),
        ) .
        '<span class="life-card__in"><h3>Pathfinder &amp; Character</h3><p>Responsibility, service and teamwork through documented Pathfinder participation.</p></span></a>
      <a class="life-card rv" href="/computer-learning"><span class="life-card__tag">ICT & Digital</span>' .
        js_string(
            a_layout_pic(
                "exhibit-teacher",
                "Teacher guiding pupils in a practical hands-on activity",
                [
                    "widths" => [480, 800],
                    "sizes" => "(max-width: 980px) 92vw, 30vw",
                ],
            ),
        ) .
        '<span class="life-card__in"><h3>Computer Lab Activities</h3><p>Hands-on digital learning and practical demonstrations.</p></span></a>
      <a class="life-card rv" href="/school-life#exhibits"><span class="life-card__tag">Academic Exhibitions</span>' .
        js_string(
            a_layout_pic(
                "exhibit-pupils",
                "Pupils presenting academic exhibits at the graduation programme",
                [
                    "widths" => [480, 800],
                    "sizes" => "(max-width: 980px) 92vw, 30vw",
                ],
            ),
        ) .
        '<span class="life-card__in"><h3>Academic Exhibits</h3><p>Pupils showcase learning through projects, quizzes and demonstrations.</p></span></a>
      <a class="life-card rv" href="/school-life#service"><span class="life-card__tag">Service & Responsibility</span>' .
        js_string(
            a_layout_pic(
                "garden-project",
                "Pupils tending the school vegetable garden",
                [
                    "widths" => [480, 800],
                    "sizes" => "(max-width: 980px) 92vw, 30vw",
                ],
            ),
        ) .
        '<span class="life-card__in"><h3>Practical Service</h3><p>Working together in practical projects that build responsibility and care.</p></span></a>
    </div>
  </div>
</section>

<!-- 12 · Faith & character -->
<section class="sec sec--navy" id="faith">
  <div class="container">
    <div class="split split--rev">
      <div>
        ' .
        js_string(
            a_layout_sectionHead(
                "Faith & Character",
                "Christian Principles, Lifelong Values",
                "As a Seventh-day Adventist school, Alpha integrates worship, Bible learning and Christian service into the rhythm of school life.",
            ),
        ) .
        '
        <p style="color:#c6d0e8">Christian principles at Alpha are not slogans — they are practised daily through integrity, discipline, respect, responsibility, compassion, service and good citizenship.</p>
        <ul class="checklist" style="color:#d5ddf0">
          <li>' .
        js_string(a_layout_icon("check")) .
        '<span>Worship programmes and special worship services across the school year.</span></li>
          <li>' .
        js_string(a_layout_icon("check")) .
        '<span>Bible learning and prayer as part of the school\'s spiritual development.</span></li>
          <li>' .
        js_string(a_layout_icon("check")) .
        '<span>Christian service and character formation through Pathfinder and school activities.</span></li>
        </ul>
        <p>' .
        js_string(
            a_layout_btn(
                "/faith",
                "Explore Faith & Spiritual Life",
                "gold",
                "arrow",
            ),
        ) .
        '</p>
      </div>
      <div class="media-stack rv">
        <div class="media-stack__main">' .
        js_string(
            a_layout_pic(
                "choir-green",
                "Pupils singing with raised hands during worship",
                ["widths" => [800, 1200], "ratio" => "4/3"],
            ),
        ) .
        '</div>
        <div class="media-stack__float">' .
        js_string(
            a_layout_pic(
                "choir-teal",
                "School choir on stage during the graduation ceremony",
                ["widths" => [480, 800], "ratio" => "4/3"],
            ),
        ) .
        '</div>
      </div>
    </div>
  </div>
</section>

<!-- 13 · Talent -->
<section class="sec" id="talent">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "Talent Development",
                "Every Child Has a Talent Worth Developing",
                "Alpha seeks to give children opportunities to discover, develop and responsibly express their abilities. Confirmed showcase areas from school programmes appear below; further areas are added as the school confirms them.",
                ["align" => "center"],
            ),
        ) .
        '
    <div class="tiles">
      <div class="tile rv">' .
        js_string(a_layout_icon("music")) .
        '<strong>Music &amp; Choir</strong><span>Choir programmes &amp; performances</span></div>
      <div class="tile rv">' .
        js_string(a_layout_icon("shield")) .
        '<strong>Pathfinder Activities</strong><span>Service, teamwork &amp; character</span></div>
      <div class="tile rv">' .
        js_string(a_layout_icon("flask")) .
        '<strong>Academic Exhibitions</strong><span>Projects &amp; demonstrations</span></div>
      <div class="tile rv">' .
        js_string(a_layout_icon("chip")) .
        '<strong>ICT Learning</strong><span>Digital skills &amp; creativity</span></div>
      <div class="tile rv">' .
        js_string(a_layout_icon("badge")) .
        '<strong>Pupil Achievement</strong><span>Certificates &amp; recognition</span></div>
      <div class="tile rv">' .
        js_string(a_layout_icon("mic")) .
        '<strong>Communication</strong><span>Presenting &amp; participation</span></div>
    </div>
  </div>
</section>

<!-- 14 · Admissions CTA -->
<section class="sec sec--sand" id="admissions-cta">
  <div class="container">
    <div class="ctaband rv">
      <span class="kicker">Admissions ' .
        js_string(jm(new JsDate(), "getFullYear")) .
        '</span>
      <h2>Begin Your Child\'s Journey at Alpha</h2>
      <p>From first enquiry to the first day of class, the school office walks with your family through every step — for day and boarding places across KG I to Standard VII.</p>
      <ol class="steps">
        <li>Discover Alpha</li>
        <li>Book a School Visit</li>
        <li>Submit Application</li>
        <li>Assessment / Placement where applicable</li>
        <li>Admission Confirmation</li>
      </ol>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        ' .
        js_string(
            a_layout_btn("/admissions#apply", "Apply Now", "gold", "pencil"),
        ) .
        '
        ' .
        js_string(
            a_layout_btn(
                "/admissions#visit",
                "Book a School Visit",
                "outline-light",
            ),
        ) .
        '
        <a class="btn btn--outline-light" href="' .
        js_string(esc($wa)) .
        '" target="_blank" rel="noopener">' .
        js_string(a_layout_icon("whatsapp")) .
        ' WhatsApp Admissions</a>
      </div>
    </div>
  </div>
</section>

' .
        js_string(
            js_truth(g($news, "length"))
                ? '<!-- 15 · News & events -->
<section class="sec" id="news">
  <div class="container">
    ' .
                    js_string(
                        a_layout_sectionHead(
                            "News & Events",
                            "What Is Happening at Alpha",
                            "Official school news, celebrations and announcements from the school office.",
                        ),
                    ) .
                    '
    ' .
                    js_string(
                        js_truth($featured)
                            ? '
    <div class="news-feat">
      <a class="news-feat__media life-card" href="/news/' .
                                js_string(esc(g($featured, "slug"))) .
                                '" style="min-height:340px">
        <span class="life-card__tag">' .
                                js_string(esc(g($featured, "category"))) .
                                '</span>
        ' .
                                js_string(
                                    a_layout_pic(
                                        g($featured, "image"),
                                        js_or(
                                            g($featured, "alt"),
                                            function () use (&$featured) {
                                                return g($featured, "title");
                                            },
                                        ),
                                        [
                                            "widths" => [800, 1200, 1600],
                                            "sizes" =>
                                                "(max-width: 900px) 92vw, 55vw",
                                        ],
                                    ),
                                ) .
                                '
        <span class="life-card__in"><h3 style="font-size:1.5rem">' .
                                js_string(esc(g($featured, "title"))) .
                                "</h3><p>" .
                                js_string(esc(g($featured, "excerpt"))) .
                                '</p></span>
      </a>
      <div class="grid" style="grid-template-columns:1fr;gap:16px">
        ' .
                                js_string(
                                    jm(
                                        jm($others, "map", function (
                                            $n = null,
                                        ) {
                                            return '
        <article class="news-card" style="flex-direction:row;align-items:stretch">
          <div class="news-card__media" style="aspect-ratio:auto;width:38%;min-height:150px;flex:none">' .
                                                js_string(
                                                    a_layout_pic(
                                                        g($n, "image"),
                                                        js_or(
                                                            g($n, "alt"),
                                                            function () use (
                                                                &$n,
                                                            ) {
                                                                return g(
                                                                    $n,
                                                                    "title",
                                                                );
                                                            },
                                                        ),
                                                        [
                                                            "widths" => [
                                                                480,
                                                                800,
                                                            ],
                                                            "sizes" => "30vw",
                                                        ],
                                                    ),
                                                ) .
                                                '</div>
          <div class="news-card__in">
            <div class="news-meta"><span class="news-cat">' .
                                                js_string(
                                                    esc(g($n, "category")),
                                                ) .
                                                "</span><span>" .
                                                js_string(
                                                    esc(g($n, "dateLabel")),
                                                ) .
                                                '</span></div>
            <h3><a href="/news/' .
                                                js_string(esc(g($n, "slug"))) .
                                                '">' .
                                                js_string(esc(g($n, "title"))) .
                                                '</a></h3>
            <p>' .
                                                js_string(
                                                    esc(g($n, "excerpt")),
                                                ) .
                                                '</p>
          </div>
        </article>';
                                        }),
                                        "join",
                                        "",
                                    ),
                                ) .
                                '
        <p class="mb-0">' .
                                js_string(
                                    a_layout_btn(
                                        "/news",
                                        "All News & Events",
                                        "ghost",
                                        "arrow",
                                    ),
                                ) .
                                '</p>
      </div>
    </div>'
                            : "",
                    ) .
                    '
  </div>
</section>

'
                : "",
        ) .
        '

' .
        js_string(
            js_truth(g(g($db, "gallery"), "length"))
                ? '<!-- 16 · Gallery strip -->
<section class="sec sec--sand" id="gallery">
  <div class="container">
    ' .
                    js_string(
                        a_layout_sectionHead(
                            "Gallery",
                            "Real Alpha Moments",
                            "Approved photographs from school programmes, ceremonies and daily life at Alpha.",
                            ["align" => "center"],
                        ),
                    ) .
                    '
    <div class="gal-grid" style="columns:3">
      ' .
                    js_string(
                        jm(
                            jm(
                                jm(g($db, "gallery"), "slice", 0, 6),
                                "map",
                                function ($g = null) {
                                    return '
      <a class="gal-item" href="/gallery" aria-label="Open gallery: ' .
                                        js_string(esc(g($g, "caption"))) .
                                        '">
        ' .
                                        js_string(
                                            a_layout_pic(
                                                g($g, "img"),
                                                g($g, "alt"),
                                                [
                                                    "widths" => [480, 800],
                                                    "sizes" => "33vw",
                                                ],
                                            ),
                                        ) .
                                        '
        <figcaption>' .
                                        js_string(esc(g($g, "caption"))) .
                                        '</figcaption>
      </a>';
                                },
                            ),
                            "join",
                            "",
                        ),
                    ) .
                    '
    </div>
    <p class="text-center" style="margin-top:26px">' .
                    js_string(
                        a_layout_btn(
                            "/gallery",
                            "View Full Gallery",
                            "primary",
                            "camera",
                        ),
                    ) .
                    '</p>
  </div>
</section>

'
                : "",
        ) .
        '

<!-- 17 · Parent corner -->
<section class="sec" id="parents">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "Parent Corner",
                "Keeping Alpha Families Informed and Connected",
                "Everything parents and guardians need from the school office — in one place.",
            ),
        ) .
        '
    <div class="parent-grid">
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("calendar")) .
        '</div><h3>School Calendar</h3><p>Term dates, approved events and the daily routine are available from the school office and published here once approved for public distribution.</p></div>
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("mega")) .
        '</div><h3>Announcements</h3><p>Official notices from the school office, including examination information and parent meeting dates.</p><a class="link-more" href="/parents">Read announcements ' .
        js_string(a_layout_icon("arrow")) .
        '</a></div>
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("clipboard")) .
        '</div><h3>Examinations &amp; Rules</h3><p>Assessment schedules, school rules and joining information are provided to families by the school office.</p></div>
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("bed")) .
        '</div><h3>Boarding Information</h3><p>Boarding routines, supervision and current availability per class are confirmed during admissions.</p></div>
      <div class="card rv"><div class="card__ico">' .
        js_string(a_layout_icon("phone")) .
        '</div><h3>Contact the School</h3><p>Reach the office by phone, WhatsApp or email — the school responds during working hours on school days.</p><a class="link-more" href="/contact">Contact details ' .
        js_string(a_layout_icon("arrow")) .
        '</a></div>
    </div>
  </div>
</section>

<!-- 18 · Kids zone -->
<section class="sec kids" id="kids">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "Alpha Kids Zone",
                "Hello, Alpha Pupils! Learn • Explore • Practise • Create",
                "A friendly corner of the website just for you — with games, computer learning and internet safety tips.",
                ["align" => "center"],
            ),
        ) .
        '
    <div class="kid-grid">
      <a class="kid-card rv" href="/students#typing"><span class="kid-card__ico kc-1">' .
        js_string(a_layout_icon("keyboard")) .
        '</span><strong>Typing Game</strong><span>Practise typing Alpha words</span></a>
      <a class="kid-card rv" href="/students#maths"><span class="kid-card__ico kc-2">' .
        js_string(a_layout_icon("sparkle")) .
        '</span><strong>Maths Challenge</strong><span>Fun maths questions for every level</span></a>
      <a class="kid-card rv" href="/students#computer"><span class="kid-card__ico kc-3">' .
        js_string(a_layout_icon("laptop")) .
        '</span><strong>Computer Lessons</strong><span>Revise what you learn in class</span></a>
      <a class="kid-card rv" href="/students#safety"><span class="kid-card__ico kc-4">' .
        js_string(a_layout_icon("shield")) .
        '</span><strong>Internet Safety</strong><span>Stay safe and smart online</span></a>
    </div>
  </div>
</section>

<!-- 19 · Contact -->
<section class="sec sec--sand" id="contact">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "Contact",
                "We Would Love to Hear From You",
                "Call, WhatsApp, email or visit the school office in the Msimba area of Kigoma.",
                ["align" => "center"],
            ),
        ) .
        '
    <div class="contact-grid">
      <a class="contact-card rv" href="tel:' .
        js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
        '">' .
        js_string(a_layout_icon("phone")) .
        "<strong>Call</strong><span>" .
        js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
        '</span></a>
      <a class="contact-card rv" href="' .
        js_string(esc($wa)) .
        '" target="_blank" rel="noopener">' .
        js_string(a_layout_icon("whatsapp")) .
        "<strong>WhatsApp</strong><span>" .
        js_string(esc(g(g($s, "whatsapp"), "number"))) .
        '</span></a>
      <a class="contact-card rv" href="mailto:' .
        js_string(esc(g($s, "email"))) .
        '">' .
        js_string(a_layout_icon("mail")) .
        "<strong>Email</strong><span>" .
        js_string(esc(g($s, "email"))) .
        '</span></a>
      <a class="contact-card rv" href="' .
        js_string(
            esc(
                js_or(g($s, "mapUrl"), function () use (&$s) {
                    return js_add(
                        "https://www.google.com/maps/search/?api=1&query=",
                        js_encodeURIComponent(g($s, "mapQuery")),
                    );
                }),
            ),
        ) .
        '" target="_blank" rel="noopener">' .
        js_string(a_layout_icon("pin")) .
        "<strong>Get Directions</strong><span>" .
        js_string(esc(g($s, "locationText"))) .
        '</span></a>
    </div>
    <p class="text-center" style="margin-top:26px">' .
        js_string(
            a_layout_btn("/contact", "Send an Enquiry", "primary", "send"),
        ) .
        '</p>
  </div>
</section>';
    return [
        "body" => $body,
        "meta" => [
            "title" =>
                "Alpha Adventist Pre & Primary School — Seventh-day Adventist School in Kigoma, Tanzania",
            "desc" =>
                "Alpha Adventist Pre & Primary School is a Seventh-day Adventist educational institution within the Western Tanzania Conference – Kigoma, offering holistic Pre-Primary and Primary education (KG I – Standard VII), boarding and day life, computer learning, choir and Christian character formation.",
            "jsonld" => a_layout_schoolJsonLd($ctx),
        ],
    ];
}
