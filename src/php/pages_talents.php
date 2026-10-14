<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
define("a_pages_talents_categories", [
    ["music", "Choir, singing & instruments"],
    ["ball", "Sports & healthy movement"],
    ["palette", "Art & cultural expression"],
    ["mic", "Public speaking & debate"],
    ["chip", "ICT & computing"],
    ["flask", "Science projects & creativity"],
    ["badge", "Leadership & service"],
    ["book", "Academic competitions"],
]);
function a_pages_talents_render($ctx = null)
{
    return [
        "body" =>
            "" .
            js_string(
                a_pages_about_phero(
                    $ctx,
                    "Discover Alpha Talents",
                    "Every child has something to share.",
                    "We seek to nurture confidence, creativity, teamwork and the joy of discovering a new ability.",
                    "campus",
                ),
            ) .
            '<section class="sec"><div class="container">' .
            js_string(
                a_layout_sectionHead(
                    "WHOLE-CHILD DEVELOPMENT",
                    "Space for gifts to grow.",
                    "Music, academic exhibitions, digital learning and service are part of Alpha’s educational direction. Ask the school which clubs and activities are currently offered for your child’s class.",
                ),
            ) .
            '<div class="grid grid--4">' .
            js_string(
                jm(
                    jm(a_pages_talents_categories, "map", function ($arg0) {
                        $ic = g($arg0, 0);
                        $title = g($arg0, 1);
                        return '<article class="card"><div class="card__ico">' .
                            js_string(a_layout_icon($ic)) .
                            "</div><h3>" .
                            js_string(esc($title)) .
                            "</h3></article>";
                    }),
                    "join",
                    "",
                ),
            ) .
            '</div><div class="note-strip" style="margin-top:30px">' .
            js_string(a_layout_icon("shield")) .
            '<span>School-life photographs are published only after consent and institutional review. External videos and social embeds will be enabled only for verified official accounts and approved media.</span></div><p style="margin-top:30px">' .
            js_string(
                a_layout_btn(
                    "/gallery",
                    "Explore approved school moments",
                    "primary",
                    "camera",
                ),
            ) .
            " " .
            js_string(
                a_layout_btn("/contact", "Ask about activities", "ghost"),
            ) .
            "</p></div></section>",
        "meta" => [
            "title" => "Discover Alpha Talents — Music, Creativity & ICT",
            "desc" =>
                "Talent development at Alpha Adventist Pre & Primary School: choir, creativity, digital skills, leadership, service and whole-child growth.",
        ],
    ];
}
