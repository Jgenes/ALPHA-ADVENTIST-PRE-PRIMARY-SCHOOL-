<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_pages_home_sw_render($ctx = null, $heroSection = null)
{
    $s = g(g($ctx, "db"), "settings");
    $academic = g(g($ctx, "db"), "academics");
    $news = jm(
        jm(g(g($ctx, "db"), "news"), "filter", function ($item = null) {
            return g($item, "language") === "sw";
        }),
        "slice",
        0,
        3,
    );
    $slides = [
        [
            "kicker" => "Karibu Alpha",
            "title" => "Karibu Shule ya Awali na Msingi Alpha Adventist",
            "text" =>
                "Taasisi ya elimu ya Waadventista Wasabato ndani ya Western Tanzania Conference – Kigoma, inayokuza maarifa, nidhamu, ubunifu, uwajibikaji na maisha ya kiroho.",
            "strap" =>
                "Elimu • Imani • Teknolojia • Vipaji • Tabia • Mustakabali",
            "image" => "campus",
            "alt" => "Majengo na ua wa Shule ya Alpha Adventist Kigoma",
            "cta" => [
                "label" => "Omba Nafasi ya Masomo",
                "href" => "/sw/admissions#apply",
            ],
            "cta2" => [
                "label" => "Panga Ziara ya Shule",
                "href" => "/sw/admissions#visit",
            ],
        ],
        [
            "kicker" => "Taaluma",
            "title" => "Msingi Imara wa Taaluma kwa Kila Mwanafunzi",
            "text" =>
                "Mafunzo yanayokuza maarifa, kujiamini, nidhamu na shauku ya kujifunza.",
            "image" => "classroom",
            "alt" => "Wanafunzi wakishiriki somo darasani Alpha",
            "cta" => ["label" => "Fahamu Masomo Yetu", "href" => "/academics"],
        ],
        [
            "kicker" => "Teknolojia",
            "title" => "Kuwaandaa Watoto kwa Mustakabali wa Kidijitali",
            "text" =>
                "Mafunzo ya kompyuta ya vitendo, ubunifu na matumizi salama ya teknolojia.",
            "image" => "exhibit-teacher",
            "alt" => "Mwalimu akiwaongoza wanafunzi katika shughuli ya vitendo",
            "cta" => [
                "label" => "Fahamu Mafunzo ya Kompyuta",
                "href" => "/computer-learning",
            ],
        ],
        [
            "kicker" => "Imani, Vipaji na Maisha ya Shule",
            "title" => "Kumkuza Mtoto kwa Ujumla",
            "text" =>
                "Imani, tabia, vipaji, ubunifu, ushirikiano na uongozi ni sehemu ya maisha ya Alpha.",
            "image" => "choir-green",
            "alt" => "Wanafunzi wa Alpha wakiimba katika ibada ya shule",
            "cta" => [
                "label" => "Gundua Maisha ya Shuleni",
                "href" => "/school-life",
            ],
        ],
    ];
    $pillars = [
        [
            "book",
            "Elimu",
            "Msingi imara wa maarifa na ujuzi wa kujifunza maisha yote.",
            "/academics",
        ],
        [
            "flame",
            "Imani",
            "Maadili ya Kikristo, uadilifu, heshima, uwajibikaji na huduma.",
            "/faith",
        ],
        [
            "chip",
            "Teknolojia",
            "Ujuzi wa vitendo na matumizi salama ya teknolojia.",
            "/computer-learning",
        ],
        [
            "star",
            "Vipaji",
            "Nafasi za kugundua na kukuza uwezo wa kila mtoto.",
            "/talents",
        ],
        [
            "shield",
            "Tabia",
            "Nidhamu, uwajibikaji na mahusiano yenye heshima.",
            "/faith#character",
        ],
        [
            "rocket",
            "Mustakabali",
            "Maarifa, tabia njema na kujiamini kwa hatua zinazofuata maishani.",
            "/computer-learning#future",
        ],
    ];
    $wa = js_add("https://wa.me/", g(g($s, "whatsapp"), "href"));
    return [
        "body" =>
            "" .
            js_string(
                $heroSection([...js_array($ctx), "homeSlides" => $slides]),
            ) .
            '
<section class="trust" aria-label="Kwa nini Alpha"><div class="container"><div class="trust__grid">' .
            js_string(
                jm(
                    jm($pillars, "map", function ($arg0) {
                        $ic = g($arg0, 0);
                        $name = g($arg0, 1);
                        $description = g($arg0, 2);
                        $href = g($arg0, 3);
                        return '<a class="trust__card rv" href="' .
                            js_string($href) .
                            '"><div class="trust__ico">' .
                            js_string(a_layout_icon($ic)) .
                            "</div><div><h3>" .
                            js_string($name) .
                            "</h3><p>" .
                            js_string($description) .
                            "</p></div></a>";
                    }),
                    "join",
                    "",
                ),
            ) .
            '</div></div></section>
<section class="sec" id="who-we-are"><div class="container split"><div class="media-stack"><div class="media-stack__main">' .
            js_string(a_layout_pic("", "", ["ratio" => "4/3"])) .
            "</div></div><div>" .
            js_string(
                a_layout_sectionHead(
                    "Sisi ni Nani",
                    "Jamii ya Kujifunza Inayojengwa kwa Hekima Katika Ukweli",
                    "Alpha Adventist ni shule ya Waadventista Wasabato iliyopo Msimba, Kigoma. Tunazingatia ukuaji wa mtoto kiakili, kiroho, kimwili na kijamii.",
                ),
            ) .
            '<ul class="checklist"><li>' .
            js_string(a_layout_icon("check")) .
            "<span>Msingi wa taaluma kuanzia KG I hadi Standard VII.</span></li><li>" .
            js_string(a_layout_icon("check")) .
            "<span>Imani na tabia njema kupitia ibada, kujifunza Biblia na huduma.</span></li><li>" .
            js_string(a_layout_icon("check")) .
            "<span>Ubunifu, vipaji na ujuzi wa kidijitali unaolingana na umri.</span></li></ul>" .
            js_string(
                a_layout_btn(
                    "/about",
                    "Fahamu Zaidi Kuhusu Alpha",
                    "primary",
                    "arrow",
                ),
            ) .
            '</div></div></section>
<section class="sec sec--sand" id="pillars"><div class="container">' .
            js_string(
                a_layout_sectionHead(
                    "Nguzo Sita za Alpha",
                    "Kumjenga Mtoto kwa Ujumla",
                    "Elimu yetu inakuza maarifa, imani, tabia na uwezo wa kila mwanafunzi.",
                    ["align" => "center"],
                ),
            ) .
            '<div class="pillars">' .
            js_string(
                jm(
                    jm($pillars, "map", function ($arg0, $i = null) {
                        $ic = g($arg0, 0);
                        $name = g($arg0, 1);
                        $description = g($arg0, 2);
                        $href = g($arg0, 3);
                        return '<article class="pillar rv"><span class="pillar__num" aria-hidden="true">0' .
                            js_string(js_add($i, 1)) .
                            '</span><div class="pillar__ico">' .
                            js_string(a_layout_icon($ic)) .
                            "</div><h3>" .
                            js_string($name) .
                            "</h3><p>" .
                            js_string($description) .
                            '</p><a class="link-more" href="' .
                            js_string($href) .
                            '">Fahamu zaidi ' .
                            js_string(a_layout_icon("arrow")) .
                            "</a></article>";
                    }),
                    "join",
                    "",
                ),
            ) .
            '</div></div></section>
<section class="sec" id="academics"><div class="container">' .
            js_string(
                a_layout_sectionHead(
                    "Madarasa Yetu",
                    "Kuanzia Hatua za Kwanza Hadi Darasa la Saba",
                    "Mafunzo ya awali na msingi kwa wanafunzi wa kutwa na bweni, kwa uongozi wa walimu.",
                ),
            ) .
            '<div class="levels"><div class="level-box"><h3>' .
            js_string(a_layout_icon("sun")) .
            ' Elimu ya Awali</h3><p>Lugha, hesabu, michezo na ugunduzi katika mazingira yenye upendo na imani.</p><ul class="level-chips">' .
            js_string(
                jm(
                    jm(g($academic, "prePrimary"), "map", function (
                        $name = null,
                    ) {
                        return "<li>" . js_string(esc($name)) . "</li>";
                    }),
                    "join",
                    "",
                ),
            ) .
            '</ul></div><div class="level-box"><h3>' .
            js_string(a_layout_icon("book")) .
            ' Elimu ya Msingi</h3><p>Kujenga maarifa, kujiamini na tabia njema kupitia mtaala wa elimu ya msingi.</p><ul class="level-chips">' .
            js_string(
                jm(
                    jm(g($academic, "primary"), "map", function ($name = null) {
                        return "<li>" . js_string(esc($name)) . "</li>";
                    }),
                    "join",
                    "",
                ),
            ) .
            '</ul></div></div><div class="grid grid--2" style="margin-top:22px"><article class="card"><div class="card__ico">' .
            js_string(a_layout_icon("home")) .
            '</div><h3>Kutwa</h3><p>Masomo, ibada na shughuli za kila siku katika mazingira ya shule.</p></article><article class="card"><div class="card__ico">' .
            js_string(a_layout_icon("bed")) .
            '</div><h3>Bweni</h3><p>Wasiliana na ofisi kuthibitisha nafasi za bweni na mahitaji ya darasa husika.</p></article></div></div></section>
<section class="sec techband" id="technology"><div class="container">' .
            js_string(
                a_layout_sectionHead(
                    "Kompyuta na Ujuzi wa Kidijitali",
                    "Kuwaandaa Wanafunzi kwa Mustakabali wa Kidijitali",
                    "Mafunzo ya vitendo, ubunifu na matumizi yenye kuwajibika ya teknolojia.",
                ),
            ) .
            '<div class="techgrid"><article class="techcard"><h3>' .
            js_string(a_layout_icon("laptop")) .
            ' Mafunzo kwa Wanafunzi</h3><p>Matumizi ya kompyuta, kibodi na kipanya, uandishi na usalama mtandaoni kulingana na umri.</p></article><article class="techcard"><h3>' .
            js_string(a_layout_icon("users")) .
            ' Ujuzi kwa Walimu na Wafanyakazi</h3><p>Kuendeleza ujuzi wa kidijitali kwa ufundishaji, mawasiliano na utunzaji salama wa taarifa.</p></article><article class="techcard"><h3>' .
            js_string(a_layout_icon("lock")) .
            " Portal ya Wafanyakazi</h3><p>Huduma salama kwa watumishi walioidhinishwa: likizo, nyaraka, taarifa na maombi.</p>" .
            js_string(a_layout_btn("/portal", "Ingia Portal", "gold")) .
            '</article></div><p style="margin-top:24px">' .
            js_string(
                a_layout_btn(
                    "/computer-learning",
                    "Fahamu Mafunzo ya Kompyuta",
                    "light",
                    "arrow",
                ),
            ) .
            '</p></div></section>
<section class="sec" id="school-life"><div class="container">' .
            js_string(
                a_layout_sectionHead(
                    "Maisha ya Alpha",
                    "Kujifunza, Imani, Urafiki na Ugunduzi",
                    "Ibada, muziki, huduma, miradi na teknolojia ni sehemu ya maisha ya shule.",
                ),
            ) .
            '<div class="tiles">' .
            js_string(
                jm(
                    jm(
                        [
                            ["music", "Muziki na Kwaya"],
                            ["flame", "Ibada na Imani"],
                            ["flask", "Miradi ya Taaluma"],
                            ["chip", "Ujuzi wa TEHAMA"],
                            ["badge", "Mafanikio"],
                            ["heart", "Huduma kwa Jamii"],
                        ],
                        "map",
                        function ($arg0) {
                            $ic = g($arg0, 0);
                            $title = g($arg0, 1);
                            return '<div class="tile">' .
                                js_string(a_layout_icon($ic)) .
                                "<strong>" .
                                js_string($title) .
                                "</strong></div>";
                        },
                    ),
                    "join",
                    "",
                ),
            ) .
            '</div></div></section>
<section class="sec sec--sand" id="admissions-cta"><div class="container"><div class="ctaband"><span class="kicker">Uandikishaji</span><h2>Anza Safari ya Mtoto Wako Alpha</h2><p>Ofisi itakueleza ada za sasa, mahitaji na nafasi zilizopo kabla ya kufanya uamuzi.</p><ol class="steps"><li>Ifahamu Alpha</li><li>Panga Ziara</li><li>Tuma Ombi</li><li>Tathmini Inapohitajika</li><li>Uthibitisho wa Nafasi</li></ol><div class="home-actions">' .
            js_string(
                a_layout_btn("/sw/admissions#apply", "Omba Nafasi", "gold"),
            ) .
            "" .
            js_string(
                a_layout_btn(
                    "/sw/admissions#visit",
                    "Panga Ziara",
                    "outline-light",
                ),
            ) .
            '</div></div></div></section>
' .
            js_string(
                js_truth(g($news, "length"))
                    ? '<section class="sec" id="news"><div class="container">' .
                        js_string(
                            a_layout_sectionHead(
                                "Habari na Matukio",
                                "Yanayojiri Alpha",
                            ),
                        ) .
                        '<div class="grid grid--3">' .
                        js_string(
                            jm(
                                jm($news, "map", function ($item = null) {
                                    return '<article class="news-card"><div class="news-card__in"><div class="news-meta"><span class="news-cat">' .
                                        js_string(esc(g($item, "category"))) .
                                        '</span></div><h3><a href="/news/' .
                                        js_string(esc(g($item, "slug"))) .
                                        '">' .
                                        js_string(esc(g($item, "title"))) .
                                        "</a></h3><p>" .
                                        js_string(esc(g($item, "excerpt"))) .
                                        "</p></div></article>";
                                }),
                                "join",
                                "",
                            ),
                        ) .
                        "</div></div></section>"
                    : "",
            ) .
            '
<section class="sec" id="parents"><div class="container">' .
            js_string(
                a_layout_sectionHead("Kwa Wazazi", "Familia na Shule Pamoja"),
            ) .
            '<div class="parent-grid"><article class="card"><div class="card__ico">' .
            js_string(a_layout_icon("download")) .
            "</div><h3>Nyaraka za Shule</h3><p>Nyaraka zilizoidhinishwa kwa matumizi ya umma.</p>" .
            js_string(a_layout_btn("/downloads", "Fungua Nyaraka", "ghost")) .
            '</article><article class="card"><div class="card__ico">' .
            js_string(a_layout_icon("users")) .
            "</div><h3>Mawasiliano na Ofisi</h3><p>Uliza kuhusu ada, kujiunga, kutwa na bweni.</p>" .
            js_string(a_layout_btn("/sw/contact", "Wasiliana Nasi", "ghost")) .
            '</article></div></div></section>
<section class="sec kids" id="kids"><div class="container">' .
            js_string(
                a_layout_sectionHead(
                    "Alpha Kids Zone",
                    "Jifunze • Gundua • Fanya Mazoezi • Buni",
                    "Michezo ya kujifunza na usalama mtandaoni, bila matangazo au mazungumzo na wageni.",
                    ["align" => "center"],
                ),
            ) .
            '<div class="kid-grid">' .
            js_string(
                jm(
                    jm(
                        [
                            ["keyboard", "Kuandika", "/students#typing"],
                            ["sparkle", "Hesabu", "/students#maths"],
                            ["laptop", "Kompyuta", "/students#computer"],
                            ["shield", "Usalama Mtandaoni", "/students#safety"],
                        ],
                        "map",
                        function ($arg0, $i = null) {
                            $ic = g($arg0, 0);
                            $title = g($arg0, 1);
                            $href = g($arg0, 2);
                            return '<a class="kid-card" href="' .
                                js_string($href) .
                                '"><span class="kid-card__ico kc-' .
                                js_string(js_add($i, 1)) .
                                '">' .
                                js_string(a_layout_icon($ic)) .
                                "</span><strong>" .
                                js_string($title) .
                                "</strong></a>";
                        },
                    ),
                    "join",
                    "",
                ),
            ) .
            '</div></div></section>
<section class="sec sec--sand" id="contact"><div class="container">' .
            js_string(
                a_layout_sectionHead(
                    "Wasiliana",
                    "Tupo Kukusikiliza",
                    "Tunapatikana eneo la Msimba, Kigoma. Wasiliana nasi kuhusu ziara, uandikishaji au maisha ya shule.",
                    ["align" => "center"],
                ),
            ) .
            '<div class="contact-grid"><a class="contact-card" href="tel:' .
            js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
            '">' .
            js_string(a_layout_icon("phone")) .
            "<strong>Ofisi ya Shule</strong><span>" .
            js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
            '</span></a><a class="contact-card" href="' .
            js_string(esc($wa)) .
            '" target="_blank" rel="noopener noreferrer">' .
            js_string(a_layout_icon("whatsapp")) .
            "<strong>WhatsApp</strong><span>" .
            js_string(esc(g(g($s, "whatsapp"), "number"))) .
            '</span></a><a class="contact-card" href="mailto:' .
            js_string(esc(g($s, "email"))) .
            '">' .
            js_string(a_layout_icon("mail")) .
            "<strong>Barua Pepe</strong><span>" .
            js_string(esc(g($s, "email"))) .
            '</span></a><a class="contact-card" href="/sw/contact">' .
            js_string(a_layout_icon("pin")) .
            "<strong>Mahali Tulipo</strong><span>" .
            js_string(esc(g($s, "locationText"))) .
            '</span></a></div><p class="text-center" style="margin-top:24px">' .
            js_string(
                a_layout_btn("/sw/contact", "Tuma Ujumbe", "primary", "send"),
            ) .
            "</p></div></section>",
        "meta" => [
            "title" =>
                "Shule ya Awali na Msingi Alpha Adventist — Kigoma, Tanzania",
            "desc" =>
                "Elimu ya awali na msingi kwa ukuaji wa mtoto kwa ujumla. KG I hadi Standard VII, kutwa na bweni, Kigoma.",
            "jsonld" => a_layout_schoolJsonLd($ctx),
        ],
    ];
}
