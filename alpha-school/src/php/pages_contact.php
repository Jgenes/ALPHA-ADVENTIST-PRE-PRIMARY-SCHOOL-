<?php
// PHP rendering and business logic; browser assets are intentionally unchanged.
function a_pages_contact_render($ctx = null)
{
    $s = g(g($ctx, "db"), "settings");
    $wa =
        "https://wa.me/" .
        js_string(g(g($s, "whatsapp"), "href")) .
        "?text=" .
        js_string(
            js_encodeURIComponent(
                "Hello Alpha Adventist Pre & Primary School, I have an enquiry.",
            ),
        ) .
        "";
    $maps = js_or(g($s, "mapUrl"), function () use (&$s) {
        return "https://www.google.com/maps/search/?api=1&query=" .
            js_string(js_encodeURIComponent(g($s, "mapQuery"))) .
            "";
    });
    $body =
        '
' .
        js_string(
            a_pages_about_phero(
                $ctx,
                "Contact",
                "Contact Alpha",
                "We would love to hear from you — call, WhatsApp, email or visit the school office in the Msimba area of Kigoma.",
                "leadership-guests",
            ),
        ) .
        '

<section class="sec">
  <div class="container">
    <div class="contact-grid">
      <a class="contact-card rv" href="tel:' .
        js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
        '">' .
        js_string(a_layout_icon("phone")) .
        "<strong>Call the School Office</strong><span>" .
        js_string(esc(g(g(g($s, "phones"), 0), "number"))) .
        '</span></a>
      <a class="contact-card rv" href="tel:' .
        js_string(esc(g(g(g($s, "phones"), 1), "href"))) .
        '">' .
        js_string(a_layout_icon("phone")) .
        '<strong>Head of School\'s Office</strong><span>' .
        js_string(esc(g(g(g($s, "phones"), 1), "number"))) .
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
    </div>
    <div class="grid grid--2" style="margin-top:clamp(28px,4vw,48px)">
      <div>
        ' .
        js_string(
            a_layout_sectionHead(
                "School Address & Office",
                "Find Alpha in Kigoma",
            ),
        ) .
        '
        <ul class="checklist">
          <li>' .
        js_string(a_layout_icon("pin")) .
        "<span><strong>Alpha Adventist Pre &amp; Primary School</strong><br>" .
        js_string(esc(g(g($s, "address"), "box"))) .
        ", " .
        js_string(esc(g(g($s, "address"), "city"))) .
        ", " .
        js_string(esc(g(g($s, "address"), "country"))) .
        "<br>" .
        js_string(esc(g($s, "locationText"))) .
        '</span></li>
          <li>' .
        js_string(a_layout_icon("clock")) .
        "<span>" .
        js_string(esc(g($s, "officeHours"))) .
        '</span></li>
          <li>' .
        js_string(a_layout_icon("badge")) .
        "<span>National examination centre: <strong>" .
        js_string(esc(g($s, "centreCode"))) .
        '</strong> (Alpha Adventist Primary School)</span></li>
        </ul>
        <div style="display:flex;gap:12px;flex-wrap:wrap;margin-top:18px">
          <a class="btn btn--primary" href="' .
        js_string(esc($maps)) .
        '" target="_blank" rel="noopener">' .
        js_string(a_layout_icon("pin")) .
        ' Get Directions</a>
          <a class="btn btn--ghost" href="tel:' .
        js_string(esc(g(g(g($s, "phones"), 0), "href"))) .
        '">' .
        js_string(a_layout_icon("phone")) .
        ' Call</a>
          <a class="btn btn--ghost" href="' .
        js_string(esc($wa)) .
        '" target="_blank" rel="noopener">' .
        js_string(a_layout_icon("whatsapp")) .
        ' WhatsApp</a>
          <a class="btn btn--ghost" href="mailto:' .
        js_string(esc(g($s, "email"))) .
        '">' .
        js_string(a_layout_icon("mail")) .
        ' Email</a>
        </div>
        <div class="note-strip" style="margin-top:22px">' .
        js_string(a_layout_icon("pin")) .
        "<span>" .
        js_string(esc(g($s, "mapNote"))) .
        '</span></div>
        <div class="card" style="margin-top:22px;padding:0;overflow:hidden">
          <div class="media-stack__main" style="border-radius:0">' .
        js_string(
            a_layout_pic(
                "staff-group",
                "Alpha Adventist Pre & Primary School building in Kigoma, with staff group photograph",
                ["widths" => [800, 1200], "ratio" => "16/8"],
            ),
        ) .
        '</div>
        </div>
      </div>
      <form class="card form" data-endpoint="/api/contact" novalidate>
        <h3>Send an Enquiry</h3>
        <div class="form__row">
          <div class="field"><label for="ct-name">Full Name <span class="req">*</span></label><input id="ct-name" name="name" required autocomplete="name"></div>
          <div class="field"><label for="ct-phone">Telephone / WhatsApp <span class="req">*</span></label><input id="ct-phone" name="phone" type="tel" required autocomplete="tel"></div>
        </div>
        <div class="field"><label for="ct-email">Email (optional)</label><input id="ct-email" name="email" type="email" autocomplete="email"></div>
        <div class="field"><label for="ct-topic">Topic</label><select id="ct-topic" name="topic"><option>General enquiry</option><option>Admissions</option><option>Boarding</option><option>Computer learning</option><option>Parent / guardian matter</option><option>Other</option></select></div>
        <div class="field"><label for="ct-msg">Your Message <span class="req">*</span></label><textarea id="ct-msg" name="message" required></textarea></div>
        <div class="hp-field" aria-hidden="true"><label>Leave this field empty</label><input type="text" name="website_url" tabindex="-1" autocomplete="off"></div>
        <div class="field"><label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="privacy_consent" value="yes" required style="width:auto;margin-top:4px"><span>I have read the <a href="/privacy">privacy notice</a> and authorize the school to use these details to respond to my enquiry.</span></label></div>
        <p class="form__note">' .
        js_string(a_layout_icon("lock")) .
        '<span>Your message is delivered to the school office only. Personal information submitted through this website is never published or shared.</span></p>
        <div class="form__status" role="status"></div>
        <button class="btn btn--primary btn--block" type="submit">' .
        js_string(a_layout_icon("send")) .
        ' Send Message</button>
      </form>
    </div>
  </div>
</section>

<section class="sec sec--sand">
  <div class="container">
    ' .
        js_string(
            a_layout_sectionHead(
                "Other Ways to Reach Us",
                "Quick Routes for Families",
                "",
                ["align" => "center"],
            ),
        ) .
        '
    <div class="grid grid--3">
      <div class="card rv" style="text-align:center"><div class="card__ico" style="margin-inline:auto">' .
        js_string(a_layout_icon("pencil")) .
        "</div><h3>Admissions Enquiries</h3><p>Start an application or book a school visit through the admissions page.</p>" .
        js_string(a_layout_btn("/admissions", "Admissions", "ghost")) .
        '</div>
      <div class="card rv" style="text-align:center"><div class="card__ico" style="margin-inline:auto">' .
        js_string(a_layout_icon("laptop")) .
        "</div><h3>Computer Learning</h3><p>Join a computer class or register interest for community training.</p>" .
        js_string(
            a_layout_btn("/computer-learning", "Computer Learning", "ghost"),
        ) .
        '</div>
      <div class="card rv" style="text-align:center"><div class="card__ico" style="margin-inline:auto">' .
        js_string(a_layout_icon("users")) .
        "</div><h3>Parent Corner</h3><p>Calendars, announcements and boarding information for families.</p>" .
        js_string(a_layout_btn("/parents", "Parent Corner", "ghost")) .
        '</div>
    </div>
    <p class="text-center muted" style="margin-top:26px;font-size:.88rem">' .
        js_string(esc(g($s, "socialNote"))) .
        '</p>
  </div>
</section>';
    return [
        "body" => $body,
        "meta" => [
            "title" => "Contact — Alpha Adventist Pre & Primary School, Kigoma",
            "desc" =>
                "Contact Alpha Adventist Pre & Primary School in Kigoma, Tanzania: P.O. Box 891 Kigoma, telephone +255 613 807 200 and +255 747 128 120, WhatsApp, email and enquiry form.",
            "jsonld" => a_layout_schoolJsonLd($ctx),
        ],
    ];
}
