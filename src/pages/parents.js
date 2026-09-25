'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon, pic, btn, sectionHead } = X;
const { phero } = require('./about');

module.exports = {
  render(ctx) {
    const db = ctx.db, s = db.settings;
    const wa = `https://wa.me/${s.whatsapp.href}?text=${encodeURIComponent('Hello Alpha Adventist Pre & Primary School, I am a parent/guardian and would like information.')}`;
    const body = `
${phero(ctx, 'Parent Corner', 'Parent Corner', 'Keeping Alpha families informed and connected — calendars, announcements, examinations, boarding information and direct lines to the school office.', 'graduation-group')}

<section class="sec">
  <div class="container">
    ${sectionHead('Information for Families', 'Everything in One Place', 'The Parent Corner gathers the information families ask for most. Documents approved for public distribution are published here; everything else is available directly from the school office.')}
    <div class="parent-grid">
      <div class="card rv"><div class="card__ico">${icon('calendar')}</div><h3>School Calendar</h3><p>Term dates, approved school events, parent meetings and the daily routine are formal governance items of the School Board. The current calendar is available from the school office and published here once approved for public distribution.</p><span class="badge-soon">Published by office</span></div>
      <div class="card rv"><div class="card__ico">${icon('clipboard')}</div><h3>Examinations</h3><p>Approved assessment and examination schedules, and guidance on how pupils are monitored and reported on, are provided to families by the school office.</p><span class="badge-soon">Published by office</span></div>
      <div class="card rv"><div class="card__ico">${icon('shield')}</div><h3>School Rules &amp; Joining Information</h3><p>Approved parent and pupil guidance — cleanliness, order, supervision, child welfare, positive relationships and appropriate use of technology — is shared with families during admissions.</p><span class="badge-soon">Provided on admission</span></div>
      <div class="card rv"><div class="card__ico">${icon('bed')}</div><h3>Boarding Information</h3><p>Boarding routines, supervision, evening study and current availability per class are confirmed by the school office during the admissions process.</p><a class="link-more" href="/admissions">Admissions information ${icon('arrow')}</a></div>
      <div class="card rv"><div class="card__ico">${icon('mega')}</div><h3>Important Notices</h3><p>Official announcements from the school office appear below and on the homepage.</p><a class="link-more" href="#announcements">Read notices ${icon('arrow')}</a></div>
      <div class="card rv"><div class="card__ico">${icon('phone')}</div><h3>Contact the School</h3><p>Call, WhatsApp or email the school office — the fastest way to reach your child's teachers and the administration.</p><a class="link-more" href="/contact">All contact channels ${icon('arrow')}</a></div>
    </div>
  </div>
</section>

<section class="sec sec--sand" id="announcements">
  <div class="container">
    ${sectionHead('Announcements', 'Official Notices from the School Office', '', { align: 'center' })}
    <div class="grid" style="grid-template-columns:1fr;max-width:860px;margin-inline:auto">
      ${db.announcements.map(a => `
      <div class="announce rv">
        <div class="news-meta"><span class="news-cat">${esc(a.tag)}</span><span>${esc(a.dateLabel)}</span></div>
        <h4>${esc(a.title)}</h4>
        <p>${esc(a.body)}</p>
      </div>`).join('') || '<p class="muted text-center">No current announcements. New notices from the school office will appear here.</p>'}
    </div>
  </div>
</section>

<section class="sec">
  <div class="container split">
    <div>
      ${sectionHead('Parent Communication', 'Partners in Your Child\'s Development', 'Alpha recognises parents and guardians as essential partners in the education, character formation and wellbeing of every learner. The school office is your first point of contact for anything concerning your child.')}
      <ul class="checklist">
        <li>${icon('check')}<span>Phone and WhatsApp lines answered during working hours on school days.</span></li>
        <li>${icon('check')}<span>Parent meetings and graduation weekend programmes bring families to campus.</span></li>
        <li>${icon('check')}<span>Official notices published in the Parent Corner once approved for distribution.</span></li>
      </ul>
      <div class="contact-grid" style="grid-template-columns:1fr 1fr;margin-top:20px">
        <a class="contact-card" href="tel:${esc(s.phones[0].href)}">${icon('phone')}<strong>Call</strong><span>${esc(s.phones[0].number)}</span></a>
        <a class="contact-card" href="${esc(wa)}" target="_blank" rel="noopener">${icon('whatsapp')}<strong>WhatsApp</strong><span>${esc(s.whatsapp.number)}</span></a>
      </div>
    </div>
    <div class="card rv" style="border:2px dashed var(--navy-100);background:var(--navy-050)">
      <div class="card__ico">${icon('lock')}</div>
      <h3>Secure Parent Portal — Future Phase</h3>
      <p>A secure, authenticated Parent Portal is planned for a later phase of this website. When launched, it will give individual families protected access to:</p>
      <ul class="checklist">
        <li>${icon('check')}<span>Student results &amp; academic reports</span></li>
        <li>${icon('check')}<span>Attendance and fee statements</span></li>
        <li>${icon('check')}<span>Notices and teacher communication</span></li>
      </ul>
      <p class="muted" style="font-size:.86rem">Because these involve children's records and family information, the portal will only be accessible through secure authentication and role-based access — never through public pages. No login exists on this website today, and no placeholder login is offered.</p>
      <span class="badge-soon">Phase 3 roadmap</span>
    </div>
  </div>
</section>

<section class="sec sec--sand">
  <div class="container">
    ${sectionHead('News for Families', 'Latest from Alpha', '', { align: 'center' })}
    <div class="grid grid--3">
      ${db.news.slice(0, 3).map(n => `
      <article class="news-card rv">
        <div class="news-card__media">${pic(n.image, n.alt || n.title, { widths: [480, 800], sizes: '(max-width: 900px) 92vw, 30vw' })}</div>
        <div class="news-card__in">
          <div class="news-meta"><span class="news-cat">${esc(n.category)}</span><span>${esc(n.dateLabel)}</span></div>
          <h3><a href="/news/${esc(n.slug)}">${esc(n.title)}</a></h3>
          <p>${esc(n.excerpt)}</p>
        </div>
      </article>`).join('')}
    </div>
    <p class="text-center" style="margin-top:24px">${btn('/news', 'All News & Events', 'ghost', 'arrow')}</p>
  </div>
</section>`;
    return {
      body,
      meta: {
        title: 'Parent Corner — Alpha Adventist Pre & Primary School, Kigoma',
        desc: 'The Parent Corner of Alpha Adventist Pre & Primary School: school calendar, announcements, examinations, school rules, boarding information, parent communication and contact channels for Alpha families in Kigoma.'
      }
    };
  }
};
