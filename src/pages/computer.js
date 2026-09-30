'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon, pic, btn, sectionHead } = X;
const { phero } = require('./about');

module.exports = {
  render(ctx) {
    const db = ctx.db, s = db.settings;
    const current = db.courses.filter(c => c.status === 'current');
    const proposed = db.courses.filter(c => c.status === 'proposed');
    const body = `
${phero(ctx, 'Computer Learning', 'Preparing Learners for the Digital Future', 'Practical computer learning, digital creativity and responsible technology use — for pupils today, for staff, and for the wider community tomorrow.', 'exhibit-teacher')}

<section class="sec sec--sand pupil-learning" id="pupils">
  <div class="container">
    ${sectionHead('Pupil Computer Learning', 'Clear • Simple • Practical', 'Alpha\'s pupil ICT lessons are built on hands-on practice, smartboard-supported teaching and Computer Lab activities — progressing with age from first touch of a keyboard to responsible internet use.')}
    <div class="techgrid techgrid--levels">
      <div class="techcard rv">
        <h3>${icon('sun')} Foundation — KG I &amp; II</h3>
        <ul>
          <li>${icon('check')}<span>What a computer is: hardware and software, input–process–output, storage.</span></li>
          <li>${icon('check')}<span>Computer parts: monitor, keyboard, mouse, printer, speakers, scanner.</span></li>
          <li>${icon('check')}<span>Starting and shutting down safely; caring for equipment.</span></li>
        </ul>
      </div>
      <div class="techcard rv">
        <h3>${icon('keyboard')} Lower Primary — Std I–III</h3>
        <ul>
          <li>${icon('check')}<span>Keyboard and mouse operation; typing practice for confidence.</span></li>
          <li>${icon('check')}<span>File and folder management; creating and saving work.</span></li>
          <li>${icon('check')}<span>Word processing basics and digital drawing / creativity.</span></li>
        </ul>
      </div>
      <div class="techcard rv">
        <h3>${icon('rocket')} Upper Primary — Std IV–VII</h3>
        <ul>
          <li>${icon('check')}<span>Word processing, presentations and spreadsheet fundamentals.</span></li>
          <li>${icon('check')}<span>Internet awareness, online research with teacher guidance.</span></li>
          <li>${icon('check')}<span>Digital safety, responsible use and introductory coding concepts.</span></li>
        </ul>
      </div>
    </div>
    <div class="skills-panel">
      <h3 style="margin-bottom:12px">Skills Alpha pupils practise</h3>
      <ul class="skillchips"><li>Computer fundamentals</li><li>Computer parts</li><li>Keyboard &amp; mouse</li><li>Typing</li><li>Files &amp; folders</li></ul>
      <details class="skillchips-more">
        <summary><span class="skill-more-label">View all 14 ICT skills</span><span class="skill-less-label">Show fewer skills</span></summary>
        <ul class="skillchips">
          <li>Word processing</li><li>Presentations</li><li>Spreadsheet fundamentals</li><li>Digital creativity</li><li>Internet awareness</li><li>Online research</li><li>Digital safety</li><li>Responsible use</li><li>Introductory coding</li>
        </ul>
      </details>
    </div>
    <p style="margin-top:30px">${btn('#join', 'Join Computer Class', 'gold', 'keyboard')}</p>
  </div>
</section>

<section class="sec" id="join">
  <div class="container split">
    <div>
      ${sectionHead('Join Computer Class', 'For Alpha Pupils', 'Computer learning is part of school life at Alpha. Use this form to enquire about your child\'s computer class placement, or about catching up on ICT lessons.')}
      <ul class="checklist">
        <li>${icon('check')}<span>Lessons follow Alpha's “clear • simple • practical” ICT approach.</span></li>
        <li>${icon('check')}<span>Smartboard-supported teaching and Computer Lab activities.</span></li>
        <li>${icon('check')}<span>Digital safety taught alongside every skill.</span></li>
      </ul>
      <div class="note-strip">${icon('sparkle')}<span><strong>Proposed Alpha Computer Club — Coming Soon.</strong> Typing challenges, computer quizzes, digital drawing, storytelling, cyber-safety activities and innovation challenges are proposed for pupils once curriculum, supervision and resources are approved.</span></div>
    </div>
    <form class="card form" data-endpoint="/api/computer-class" novalidate>
      <h3>Computer Class Enquiry</h3>
      <div class="form__row">
        <div class="field"><label for="c-name">Pupil / Parent Name <span class="req">*</span></label><input id="c-name" name="name" required></div>
        <div class="field"><label for="c-phone">Telephone / WhatsApp <span class="req">*</span></label><input id="c-phone" name="phone" type="tel" required></div>
      </div>
      <div class="field"><label for="c-level">Current Level</label><select id="c-level" name="level"><option value="">Select…</option>${[...db.academics.prePrimary, ...db.academics.primary].map(l => `<option>${esc(l)}</option>`).join('')}</select></div>
      <div class="field"><label for="c-msg">What would you like to learn or improve?</label><textarea id="c-msg" name="message"></textarea></div>
      <div class="hp-field" aria-hidden="true"><label>Leave this field empty</label><input type="text" name="website_url" tabindex="-1" autocomplete="off"></div>
      <div class="field"><label style="display:flex;gap:10px;align-items:flex-start"><input type="checkbox" name="privacy_consent" value="yes" required style="width:auto;margin-top:4px"><span>I have read the <a href="/privacy">privacy notice</a> and authorize the school to use these details to respond to my enquiry.</span></label></div>
      <div class="form__status" role="status"></div>
      <button class="btn btn--primary btn--block" type="submit">${icon('keyboard')} Join Computer Class</button>
    </form>
  </div>
</section>

<section class="sec sec--sand" id="staff">
  <div class="container">
    ${sectionHead('Teacher & Staff ICT Development', 'Growing Digital Confidence Across the School', 'Alpha\'s School Board documentation includes professional growth for staff. The school is progressively strengthening digital skills for teachers and school workers, supporting administration, records and teaching.')}
    <div class="grid grid--3">
      <div class="card rv"><div class="card__ico">${icon('mail')}</div><h3>Email &amp; Digital Communication</h3><p>Professional email communication, attachments, document sharing and inbox organisation for school correspondence.</p></div>
      <div class="card rv"><div class="card__ico">${icon('clipboard')}</div><h3>Digital Records &amp; Filing</h3><p>Electronic filing, reporting, records organisation, printing and scanning for administration and finance functions.</p></div>
      <div class="card rv"><div class="card__ico">${icon('shield')}</div><h3>Cyber-Safety Awareness</h3><p>Recognising suspicious emails, protecting school information and modelling safe digital behaviour for pupils.</p></div>
    </div>
    <p class="muted" style="margin-top:18px;font-size:.9rem">Staff development areas follow the school's documented direction and are delivered through internal professional growth sessions.</p>
  </div>
</section>

<section class="sec techband" id="community">
  <div class="container">
    <div class="sec-head">
      <span class="kicker">Community Computer Training</span>
      <h2>Alpha Computer &amp; Digital Learning Centre</h2>
      <p class="sec-head__text">Alpha proposes to open computer training for the wider Kigoma community — practical digital skills for learning, work and everyday life. <strong style="color:var(--gold)">The programme is not yet operational;</strong> contact the office to register interest in an initial computer-fundamentals course. Dates, fees and payment instructions will be shared only after approval.</p>
    </div>
    <form class="card form" data-endpoint="/api/computer-interest" style="margin-top:30px;background:rgba(255,255,255,.06);border-color:rgba(255,255,255,.18)" novalidate>
      <h3 style="color:#fff">Register Interest — Waiting List</h3>
      <p style="color:#b9c5e2;font-size:.9rem">Leave your details and the school office will contact you when approved registration opens. Registering interest does not enrol you in a course.</p>
      <div class="form__row">
        <div class="field"><label for="i-name" style="color:#dfe6f5">Full Name <span class="req">*</span></label><input id="i-name" name="name" required></div>
        <div class="field"><label for="i-phone" style="color:#dfe6f5">Telephone / WhatsApp <span class="req">*</span></label><input id="i-phone" name="phone" type="tel" required></div>
      </div>
      <div class="form__row">
        <div class="field"><label for="i-email" style="color:#dfe6f5">Email (optional)</label><input id="i-email" name="email" type="email"></div>
        <div class="field"><label for="i-cat" style="color:#dfe6f5">I am a…</label><select id="i-cat" name="category"><option>Parent / Guardian</option><option>Student</option><option>Teacher / Worker</option><option>Community Member</option><option>Business / Office Worker</option></select></div>
      </div>
      <div class="field"><label for="i-course" style="color:#dfe6f5">Course of Interest</label><select id="i-course" name="course"><option value="">Select…</option>${proposed.map(c => `<option>${esc(c.title)}</option>`).join('')}</select></div>
      <div class="hp-field" aria-hidden="true"><label>Leave this field empty</label><input type="text" name="website_url" tabindex="-1" autocomplete="off"></div>
      <div class="field"><label style="display:flex;gap:10px;align-items:flex-start;color:#dfe6f5"><input type="checkbox" name="privacy_consent" value="yes" required style="width:auto;margin-top:4px"><span>I have read the <a href="/privacy">privacy notice</a> and authorize the school to use these details to contact me if registration opens.</span></label></div>
      <div class="form__status" role="status"></div>
      <button class="btn btn--gold btn--block" type="submit">${icon('send')} Register Interest</button>
    </form>
  </div>
</section>

<section class="sec" id="future">
  <div class="container">
    ${sectionHead('Our Digital Learning Direction', 'Learn → Practise → Explore → Create', 'Alpha\'s digital learning grows step by step: a verified foundation in computer fundamentals and internet safety today, expanding toward office productivity, email, coding and community training as programmes are approved.', { align: 'center' })}
    <div class="grid grid--3">
      <div class="card rv" style="text-align:center"><span class="badge-now" style="margin-bottom:12px">Established</span><h3>Foundation</h3><p>Computer fundamentals, hardware &amp; software, safe computer use, internet-safety teaching, smartboard lessons, Computer Lab activities.</p></div>
      <div class="card rv" style="text-align:center"><span class="badge-now" style="margin-bottom:12px">Expanding</span><h3>Growth</h3><p>Typing, word processing, presentations, spreadsheets, online research and digital creativity across the primary levels.</p></div>
      <div class="card rv" style="text-align:center"><span class="badge-soon" style="margin-bottom:12px">Proposed</span><h3>Community</h3><p>Computer Training Centre courses, certificates of attendance on approved assessment, computer club and coding activities.</p></div>
    </div>
  </div>
</section>`;
    return {
      body,
      meta: {
        title: 'Computer & Digital Learning — Alpha Adventist Pre & Primary School, Kigoma',
        desc: 'Computer and digital learning at Alpha Adventist Pre & Primary School Kigoma: pupil ICT lessons from computer fundamentals to digital safety, staff ICT development, and the proposed community Computer Training Centre (coming soon).',
        bodyClass: 'page-tech'
      }
    };
  }
};
