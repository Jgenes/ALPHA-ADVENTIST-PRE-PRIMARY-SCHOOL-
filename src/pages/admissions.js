'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon, pic, btn, sectionHead } = X;
const { phero } = require('./about');

module.exports = {
  render(ctx) {
    const db = ctx.db, s = db.settings;
    const wa = `https://wa.me/${s.whatsapp.href}?text=${encodeURIComponent('Hello Alpha Adventist Pre & Primary School, I would like to enquire about admission for my child.')}`;
    const body = `
${phero(ctx, 'Admissions', 'Admissions at Alpha', 'A simple, guided journey from first enquiry to your child\'s first day of class — for day and boarding places across KG I to Standard VII.', 'graduation-certificate')}

<section class="sec">
  <div class="container">
    ${sectionHead('The Admission Process', 'Five Clear Steps', 'Families typically begin by contacting the school to enquire about admission, available levels, boarding and joining information. The school office guides you through each step.')}
    <ol class="adsp-steps">
      <li><strong>Discover Alpha</strong><br>Explore this website, call or WhatsApp the school office.</li>
      <li><strong>Book a School Visit</strong><br>Meet the team and see the campus, classrooms and Computer Lab.</li>
      <li><strong>Submit Application</strong><br>Complete the application with the school office.</li>
      <li><strong>Assessment / Placement</strong><br>Where applicable, for the level your child is joining.</li>
      <li><strong>Admission Confirmation</strong><br>Receive confirmation and joining instructions, then report to school.</li>
    </ol>
    <div style="display:flex;gap:12px;flex-wrap:wrap">
      ${btn('#apply', 'Apply Now', 'primary', 'pencil')}
      ${btn('#visit', 'Book a School Visit', 'gold')}
      <a class="btn btn--ghost" href="${esc(wa)}" target="_blank" rel="noopener">${icon('whatsapp')} WhatsApp Admissions</a>
      <a class="btn btn--ghost" href="tel:${esc(s.phones[0].href)}">${icon('phone')} Call Admissions</a>
    </div>
  </div>
</section>

<section class="sec sec--sand">
  <div class="container">
    ${sectionHead('Available Levels & Arrangements', 'Where Can Your Child Join?', 'Based on Alpha\'s registration records, admissions are open across the following levels. The school office advises on current availability in each class.')}
    <div class="levels">
      <div class="level-box rv">
        <h3>${icon('sun')} Pre-Primary</h3>
        <ul class="level-chips">${db.academics.prePrimary.map(l => `<li>${esc(l)}</li>`).join('')}</ul>
        <p class="muted" style="margin-top:14px;font-size:.9rem">Early learning in a caring, supervised environment.</p>
      </div>
      <div class="level-box rv">
        <h3>${icon('book')} Primary</h3>
        <ul class="level-chips">${db.academics.primary.map(l => `<li>${esc(l)}</li>`).join('')}</ul>
        <p class="muted" style="margin-top:14px;font-size:.9rem">Through the national primary pathway to Form One selection.</p>
      </div>
    </div>
    <div class="grid grid--2" style="margin-top:24px">
      <div class="card rv" style="display:flex;gap:18px;align-items:flex-start"><div class="card__ico" style="margin:0">${icon('home')}</div><div><h3>Day School</h3><p>${esc(db.academics.arrangements[0].desc)}</p></div></div>
      <div class="card rv" style="display:flex;gap:18px;align-items:flex-start"><div class="card__ico" style="margin:0">${icon('bed')}</div><div><h3>Boarding</h3><p>${esc(db.academics.arrangements[1].desc)}</p></div></div>
    </div>
    <div class="note-strip" style="margin-top:22px">${icon('clipboard')}<span><strong>Admission requirements &amp; fees:</strong> the current, management-approved requirements checklist and fee schedule for each level are provided by the school office during enquiries, so that every family receives accurate and up-to-date information. Boarding families also receive boarding joining instructions.</span></div>
  </div>
</section>

<section class="sec" id="visit">
  <div class="container split">
    <div>
      ${sectionHead('Book a School Visit', 'See Alpha in Person')}
      <p>The best way to understand Alpha is to visit. During a school visit, families can meet the school team, see classrooms and the Computer Lab, ask about boarding and day life, and receive the current admissions checklist.</p>
      <ul class="checklist">
        <li>${icon('check')}<span>Tour the campus, classrooms and activity areas.</span></li>
        <li>${icon('check')}<span>Meet teachers and the school office team.</span></li>
        <li>${icon('check')}<span>Receive current fees, requirements and joining information.</span></li>
      </ul>
    </div>
    <form class="card form" data-endpoint="/api/visit" novalidate>
      <h3>School Visit Request</h3>
      <div class="form__row">
        <div class="field"><label for="v-name">Parent / Guardian Name <span class="req">*</span></label><input id="v-name" name="name" required autocomplete="name"></div>
        <div class="field"><label for="v-phone">Telephone / WhatsApp <span class="req">*</span></label><input id="v-phone" name="phone" type="tel" required autocomplete="tel"></div>
      </div>
      <div class="form__row">
        <div class="field"><label for="v-date">Preferred Visit Day</label><input id="v-date" name="preferred_day" type="text" placeholder="e.g. Saturday morning"></div>
        <div class="field"><label for="v-level">Level of Interest</label><select id="v-level" name="level"><option value="">Select…</option>${[...db.academics.prePrimary, ...db.academics.primary].map(l => `<option>${esc(l)}</option>`).join('')}</select></div>
      </div>
      <div class="field"><label for="v-msg">Questions for the School Office</label><textarea id="v-msg" name="message"></textarea></div>
      <div class="hp-field" aria-hidden="true"><label>Leave this field empty</label><input type="text" name="website_url" tabindex="-1" autocomplete="off"></div>
      <div class="field"><label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="privacy_consent" value="yes" required style="width:auto;margin-top:4px"><span>I have read the <a href="/privacy">privacy notice</a> and authorize the school to use these details to respond to my request.</span></label></div>
      <p class="form__note">${icon('lock')}<span>Your details are used only by the school office to arrange your visit. They are never published on the website.</span></p>
      <div class="form__status" role="status"></div>
      <button class="btn btn--primary btn--block" type="submit">${icon('calendar')} Request a Visit</button>
    </form>
  </div>
</section>

<section class="sec sec--sand" id="apply">
  <div class="container split split--rev">
    <form class="card form" data-endpoint="/api/apply" novalidate>
      <h3>Admission Application</h3>
      <p class="muted" style="font-size:.88rem">This form sends your application request directly to the school office, who will contact you with the next steps. Full online admissions will be introduced in a later phase of this website.</p>
      <div class="form__row">
        <div class="field"><label for="a-child">Child's Full Name <span class="req">*</span></label><input id="a-child" name="child_name" required></div>
        <div class="field"><label for="a-guardian">Parent / Guardian Name <span class="req">*</span></label><input id="a-guardian" name="guardian_name" required autocomplete="name"></div>
      </div>
      <div class="form__row">
        <div class="field"><label for="a-phone">Telephone / WhatsApp <span class="req">*</span></label><input id="a-phone" name="phone" type="tel" required autocomplete="tel"></div>
        <div class="field"><label for="a-email">Email (optional)</label><input id="a-email" name="email" type="email" autocomplete="email"></div>
      </div>
      <div class="form__row">
        <div class="field"><label for="a-level">Level Applying For <span class="req">*</span></label><select id="a-level" name="level" required><option value="">Select…</option>${[...db.academics.prePrimary, ...db.academics.primary].map(l => `<option>${esc(l)}</option>`).join('')}</select></div>
        <div class="field"><label for="a-arr">Arrangement <span class="req">*</span></label><select id="a-arr" name="arrangement" required><option value="">Select…</option><option>Day</option><option>Boarding</option></select></div>
      </div>
      <div class="field"><label for="a-msg">Message (optional)</label><textarea id="a-msg" name="message" placeholder="Previous school, transfer needs, questions…"></textarea></div>
      <div class="hp-field" aria-hidden="true"><label>Leave this field empty</label><input type="text" name="website_url" tabindex="-1" autocomplete="off"></div>
      <div class="field"><label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="privacy_consent" value="yes" required style="width:auto;margin-top:4px"><span>I am the parent or guardian, have read the <a href="/privacy">privacy notice</a>, and authorize the school to use these details to respond about this application request.</span></label></div>
      <p class="form__note">${icon('lock')}<span>Child privacy: application details are stored securely for the school office only and are never displayed publicly.</span></p>
      <div class="form__status" role="status"></div>
      <button class="btn btn--gold btn--block" type="submit">${icon('send')} Submit Application Request</button>
    </form>
    <div>
      ${sectionHead('Apply for Admission', 'Your Child\'s Place at Alpha')}
      <p>Alpha admits learners across KG I to Standard VII, in day and boarding arrangements. Submitting this request does not complete admission — the school office will contact you with the current requirements, assessment or placement where applicable, and confirmation steps.</p>
      <ul class="checklist">
        <li>${icon('check')}<span>The office responds during working hours on school days.</span></li>
        <li>${icon('check')}<span>Current fee schedule and requirements checklist provided on enquiry.</span></li>
        <li>${icon('check')}<span>Boarding availability per class confirmed by the office.</span></li>
      </ul>
      <div class="media-stack rv" style="margin-top:26px">
        <div class="media-stack__main">${pic('graduation-certificate', 'A pupil receiving a Primary School Leaving certificate at Alpha', { widths: [800, 1200], ratio: '4/3' })}</div>
      </div>
    </div>
  </div>
</section>

<section class="sec">
  <div class="container">
    ${sectionHead('Frequently Asked Questions', 'Admissions Answers', '', { align: 'center' })}
    <div class="faq" style="margin-inline:auto">
      ${db.faqs.map(f => `<details><summary>${esc(f.q)}${icon('chevron')}</summary><p>${esc(f.a)}</p></details>`).join('')}
    </div>
    <div class="contact-grid" style="margin-top:34px">
      <a class="contact-card" href="tel:${esc(s.phones[0].href)}">${icon('phone')}<strong>Call Admissions</strong><span>${esc(s.phones[0].number)}</span></a>
      <a class="contact-card" href="tel:${esc(s.phones[1].href)}">${icon('phone')}<strong>Head of School's Office</strong><span>${esc(s.phones[1].number)}</span></a>
      <a class="contact-card" href="${esc(wa)}" target="_blank" rel="noopener">${icon('whatsapp')}<strong>WhatsApp Admissions</strong><span>${esc(s.whatsapp.number)}</span></a>
      <a class="contact-card" href="/contact">${icon('mail')}<strong>Contact Page</strong><span>Enquiries &amp; directions</span></a>
    </div>
  </div>
</section>`;
    return {
      body,
      meta: {
        title: 'Admissions — Apply to Alpha Adventist Pre & Primary School, Kigoma',
        desc: 'Admissions information for Alpha Adventist Pre & Primary School in Kigoma: admission process, available levels KG I to Standard VII, day and boarding places, school visits, FAQs and how to apply.'
      }
    };
  }
};
