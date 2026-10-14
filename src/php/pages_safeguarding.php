<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_pages_safeguarding_render($ctx = null)
{
    $settings = g(g($ctx, "db"), "settings");
    $name = js_or(g($settings, "safeguardingName"), function () {
        return "Head of School’s office — ask for the designated safeguarding officer";
    });
    $phone = js_or(g($settings, "safeguardingPhone"), function () use (
        &$settings,
    ) {
        return g(g(g($settings, "phones"), 1), "number");
    });
    return [
        "body" =>
            "" .
            js_string(
                a_pages_about_phero(
                    $ctx,
                    "Safeguarding",
                    "Every child deserves to feel safe.",
                    "A private route for parents, guardians and adults to raise a concern. Online messages are not an emergency service.",
                    "campus",
                ),
            ) .
            '<section class="sec"><div class="container split"><div><span class="kicker">SAFEGUARDING CONTACT</span><h2>' .
            js_string(esc($name)) .
            "</h2><p>" .
            js_string(esc($phone)) .
            '</p><div class="note-strip">' .
            js_string(a_layout_icon("shield")) .
            '<span>If a child is in immediate danger, contact local emergency services or a trusted safeguarding professional now. Do not wait for an online response.</span></div><p style="margin-top:22px">This form is routed to authorised safeguarding/privacy management, not the general communications inbox. Share only the minimum information needed for safe follow-up. Do not attach evidence, medical records or identity documents here.</p><p>Children: speak to a trusted adult or teacher in person. This form is intended for adult reporting and follow-up.</p><a href="/privacy">Read the privacy and safeguarding notice</a></div><form class="card form" data-endpoint="/api/safeguarding"><h2>Raise a concern</h2><div class="field"><label for="sg-name">Your name (optional)</label><input id="sg-name" name="name" maxlength="160" autocomplete="name"></div><div class="field"><label for="sg-phone">A safe contact number (optional)</label><input id="sg-phone" name="phone" type="tel" autocomplete="tel"></div><div class="field"><label for="sg-message">Briefly describe the concern *</label><textarea id="sg-message" name="message" required maxlength="2000"></textarea></div><label class="adsp-consent"><input type="checkbox" name="privacy_consent" value="yes" required><span>I understand how this information will be used to assess and respond to this concern, as described in the <a href="/privacy">privacy notice</a>.</span></label><div class="hp-field" aria-hidden="true"><input name="website_url" tabindex="-1" autocomplete="off" aria-label="Leave empty"></div><div class="form__status" role="status"></div><button class="btn btn--primary btn--block" type="submit">Send to authorised safeguarding team</button></form></div></section>',
        "meta" => [
            "title" => "Safeguarding — Raise a Concern | Alpha Adventist",
            "desc" =>
                "A private reporting route for safeguarding concerns at Alpha Adventist Pre & Primary School.",
        ],
    ];
}
