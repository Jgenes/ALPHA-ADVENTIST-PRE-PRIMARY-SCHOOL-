<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
define(
    "a_pages_swahili_consent",
    '<div class="field"><label class="adsp-consent"><input type="checkbox" name="privacy_consent" value="yes" required><span>Nimesoma <a href="/privacy">taarifa ya faragha (Kiingereza)</a> na nakubali shule kutumia taarifa hizi kujibu ombi langu. Ridhaa hii si ruhusa ya kuchapisha picha za mtoto.</span></label></div>',
);
function a_pages_swahili_finish($label = null)
{
    return '<input type="hidden" name="language" value="sw"><div class="hp-field" aria-hidden="true"><label>Acha sehemu hii wazi</label><input name="website_url" tabindex="-1" autocomplete="off"></div>' .
        js_string(a_pages_swahili_consent) .
        '<div class="form__status" role="status"></div><button class="btn btn--primary btn--block" type="submit">' .
        js_string(a_layout_icon("send")) .
        " " .
        js_string($label) .
        "</button>";
}
function a_pages_swahili_namePhone(
    $prefix = null,
    $nameLabel = "Jina la mzazi au mlezi",
) {
    return '<div class="form__row"><div class="field"><label for="' .
        js_string($prefix) .
        '-name">' .
        js_string($nameLabel) .
        ' *</label><input id="' .
        js_string($prefix) .
        '-name" name="name" autocomplete="name" required maxlength="160"></div><div class="field"><label for="' .
        js_string($prefix) .
        '-phone">Simu / WhatsApp *</label><input id="' .
        js_string($prefix) .
        '-phone" name="phone" type="tel" autocomplete="tel" required maxlength="24"></div></div>';
}
function a_pages_swahili_admissions($ctx = null)
{
    $levels = [
        ...js_array(g(g(g($ctx, "db"), "academics"), "prePrimary")),
        ...js_array(g(g(g($ctx, "db"), "academics"), "primary")),
    ];
    return [
        "body" =>
            "" .
            js_string(
                a_pages_about_phero(
                    $ctx,
                    "Uandikishaji",
                    "Safari ya mtoto wako inaanza hapa.",
                    "Elimu ya awali na msingi, kuanzia KG I hadi Standard VII. Wasiliana nasi kuhusu nafasi za kutwa na bweni.",
                    "campus",
                ),
            ) .
            '
<section class="sec"><div class="container">' .
            js_string(
                a_layout_sectionHead(
                    "HATUA ZA KUJIUNGA",
                    "Hatua rahisi kwa familia yako.",
                ),
            ) .
            '<ol class="adsp-steps"><li><strong>Ifahamu Alpha</strong><br>Soma kuhusu shule au wasiliana na ofisi.</li><li><strong>Tembelea shule</strong><br>Kutana na walimu na ujionee mazingira.</li><li><strong>Tuma ombi</strong><br>Jaza taarifa chache hapa chini.</li><li><strong>Tathmini inapohitajika</strong><br>Ofisi itaeleza hatua zinazohusika.</li><li><strong>Uthibitisho wa nafasi</strong><br>Pokea maelekezo rasmi ya kujiunga.</li></ol><div class="note-strip">' .
            js_string(a_layout_icon("clipboard")) .
            '<span>Ofisi itakupatia ada za sasa, mahitaji, sare na nafasi zilizopo kwa darasa husika. Kutuma ombi hili hakumaanishi kuwa nafasi imethibitishwa.</span></div></div></section>
<section class="sec sec--sand" id="apply"><div class="container split"><div>' .
            js_string(
                a_layout_sectionHead(
                    "OMBA NAFASI",
                    "Tuanze mazungumzo.",
                    "Jaza taarifa za msingi za mtoto na mlezi. Taarifa hizi huonekana kwa maafisa walioidhinishwa tu. Utapewa namba ya kumbukumbu.",
                ),
            ) .
            '<ul class="checklist"><li>' .
            js_string(a_layout_icon("check")) .
            "<span>Awali: KG I na KG II</span></li><li>" .
            js_string(a_layout_icon("check")) .
            "<span>Msingi: Standard I hadi Standard VII</span></li><li>" .
            js_string(a_layout_icon("check")) .
            '<span>Kutwa na bweni, kulingana na nafasi zinazothibitishwa na ofisi.</span></li></ul><p>Usitume cheti cha kuzaliwa, taarifa za afya au nyaraka nyeti kupitia fomu hii. Ofisi itaeleza njia salama ikihitaji nyaraka.</p></div><form class="card form" data-endpoint="/api/apply"><h2>Ombi la kujiunga</h2><div class="form__row"><div class="field"><label for="sw-child">Jina kamili la mtoto *</label><input id="sw-child" name="child_name" required maxlength="160"></div><div class="field"><label for="sw-guardian">Jina la mzazi au mlezi *</label><input id="sw-guardian" name="guardian_name" autocomplete="name" required maxlength="160"></div></div><div class="form__row"><div class="field"><label for="sw-phone">Simu / WhatsApp *</label><input id="sw-phone" name="phone" type="tel" autocomplete="tel" required></div><div class="field"><label for="sw-email">Barua pepe (si lazima)</label><input id="sw-email" name="email" type="email" autocomplete="email"></div></div><div class="form__row"><div class="field"><label for="sw-level">Darasa *</label><select id="sw-level" name="level" required><option value="">Chagua darasa…</option>' .
            js_string(
                jm(
                    jm($levels, "map", function ($level = null) {
                        return '<option value="' .
                            js_string(esc($level)) .
                            '">' .
                            js_string(esc($level)) .
                            "</option>";
                    }),
                    "join",
                    "",
                ),
            ) .
            '</select></div><div class="field"><label for="sw-arrangement">Mpangilio *</label><select id="sw-arrangement" name="arrangement" required><option value="">Chagua…</option><option value="Day">Kutwa</option><option value="Boarding">Bweni</option></select></div></div><div class="field"><label for="sw-message">Swali au ujumbe (si lazima)</label><textarea id="sw-message" name="message" maxlength="2000"></textarea></div><p class="form__note">Kwa kutuma fomu, unathibitisha kuwa wewe ni mzazi au mlezi mwenye mamlaka ya kuwasilisha ombi hili.</p>' .
            js_string(a_pages_swahili_finish("Tuma ombi la kujiunga")) .
            '</form></div></section>
<section class="sec" id="visit"><div class="container split"><div>' .
            js_string(
                a_layout_sectionHead(
                    "TEMBELEA SHULE",
                    "Karibu Alpha.",
                    "Ziara ni nafasi ya kuuliza maswali, kukutana na timu na kupata maelekezo ya kujiunga. Ofisi itathibitisha siku na muda unaofaa.",
                ),
            ) .
            "<p>" .
            js_string(
                a_layout_btn("/sw/contact", "Wasiliana nasi", "ghost", "arrow"),
            ) .
            '</p></div><form class="card form" data-endpoint="/api/visit"><h2>Panga ziara</h2>' .
            js_string(a_pages_swahili_namePhone("sw-visit")) .
            '<div class="field"><label for="sw-day">Siku au muda unaopendelea</label><input id="sw-day" name="preferred_day" maxlength="160"></div><div class="field"><label for="sw-questions">Maswali kwa ofisi</label><textarea id="sw-questions" name="message" maxlength="2000"></textarea></div>' .
            js_string(a_pages_swahili_finish("Omba ziara ya shule")) .
            "</form></div></section>",
        "meta" => [
            "title" =>
                "Uandikishaji — Shule ya Awali na Msingi Alpha Adventist, Kigoma",
            "desc" =>
                "Omba nafasi katika Shule ya Alpha Adventist, Kigoma. Elimu ya awali na msingi, kutwa na bweni. Panga ziara na upate taarifa za ada na mahitaji.",
        ],
    ];
}
function a_pages_swahili_contact($ctx = null)
{
    $s = g(g($ctx, "db"), "settings");
    return [
        "body" =>
            "" .
            js_string(
                a_pages_about_phero(
                    $ctx,
                    "Wasiliana",
                    "Tupo kukusikiliza.",
                    "Uliza kuhusu masomo, kujiunga au ziara ya shule. Tunapatikana eneo la Msimba, Kigoma.",
                    "campus",
                ),
            ) .
            '
<section class="sec"><div class="container"><div class="contact-grid"><a class="contact-card" href="tel:' .
            js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
            '">' .
            js_string(a_layout_icon("phone")) .
            "<strong>Ofisi ya shule</strong><span>" .
            js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
            '</span></a><a class="contact-card" href="tel:' .
            js_string(esc(g(g(g($s, "phones"), 1), "href"))) .
            '">' .
            js_string(a_layout_icon("phone")) .
            "<strong>Ofisi ya mkuu wa shule</strong><span>" .
            js_string(esc(g(g(g($s, "phones"), 1), "number"))) .
            '</span></a><a class="contact-card" href="https://wa.me/' .
            js_string(esc(g(g($s, "whatsapp"), "href"))) .
            '" target="_blank" rel="noopener noreferrer">' .
            js_string(a_layout_icon("whatsapp")) .
            "<strong>WhatsApp</strong><span>" .
            js_string(esc(g(g($s, "whatsapp"), "number"))) .
            '</span></a><a class="contact-card" href="mailto:' .
            js_string(esc(g($s, "email"))) .
            '">' .
            js_string(a_layout_icon("mail")) .
            "<strong>Barua pepe</strong><span>" .
            js_string(esc(g($s, "email"))) .
            '</span></a></div><div class="split" style="margin-top:40px"><div>' .
            js_string(
                a_layout_sectionHead("ANWANI YETU", "Karibu Msimba, Kigoma."),
            ) .
            "<p>Alpha Adventist Pre &amp; Primary School<br>" .
            js_string(esc(g(g($s, "address"), "box"))) .
            ", Kigoma, Tanzania</p><p>Ofisi hujibu wakati wa kazi katika siku za shule. Piga simu kuthibitisha muda wa ziara na kupata maelekezo sahihi ya kufika shuleni.</p><p>Kituo cha mitihani: <strong>" .
            js_string(esc(g($s, "centreCode"))) .
            '</strong></p><p><a href="/safeguarding">Toa taarifa ya wasiwasi kuhusu usalama wa mtoto (Kiingereza)</a></p></div><form class="card form" data-endpoint="/api/contact"><h2>Tuma ujumbe</h2>' .
            js_string(a_pages_swahili_namePhone("sw-contact", "Jina lako")) .
            '<div class="field"><label for="sw-contact-email">Barua pepe (si lazima)</label><input id="sw-contact-email" name="email" type="email" autocomplete="email"></div><div class="field"><label for="sw-contact-message">Ujumbe wako *</label><textarea id="sw-contact-message" name="message" required maxlength="2000"></textarea></div>' .
            js_string(a_pages_swahili_finish("Tuma ujumbe")) .
            "</form></div></div></section>",
        "meta" => [
            "title" => "Wasiliana — Shule ya Alpha Adventist, Msimba Kigoma",
            "desc" =>
                "Wasiliana na Shule ya Awali na Msingi Alpha Adventist, Msimba Kigoma. Piga simu +255 613 807 200, tuma ujumbe au panga ziara.",
            "jsonld" => a_layout_schoolJsonLd($ctx),
        ],
    ];
}
