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
    const announcementContent = db.announcements.length
      ? `<div class="parent-modal__notices">${db.announcements.map(a => `<article class="announce"><div class="news-meta"><span class="news-cat">${esc(a.tag)}</span><span>${esc(a.dateLabel)}</span></div><h3>${esc(a.title)}</h3><p>${esc(a.body)}</p></article>`).join('')}</div>`
      : '<p class="muted">There are no current announcements. Official notices will appear here when published by the school.</p>';
    const news = db.news.filter(item => (item.language || 'en') === 'en');
    const events = db.events || [];
    const downloads = ctx.downloads || [];
    // Preserve the original clickable-card/dialog UI, using approved records
    // rather than sample calendars, proposed programmes or fee placeholders.
    const parentItems = [
      ...(events.length ? [{ icon: 'calendar', title: 'School Calendar', teaser: 'Published school dates and events', status: 'Published dates', content: events.map(item => `<article class="announce"><h3>${esc(item.title)}</h3><p>${esc(item.eventDate || item.dateLabel)} — ${esc(item.excerpt)}</p></article>`).join('') }] : []),
      ...(news.length ? [{ icon: 'mega', title: 'School News & Announcements', teaser: 'Published updates from the school', status: 'Published school updates', content: news.slice(0, 6).map(item => `<article class="announce"><h3><a href="/news/${esc(item.slug)}">${esc(item.title)}</a></h3><p>${esc(item.excerpt)}</p></article>`).join('') }] : []),
      { icon: 'download', title: 'Policies & Downloads', teaser: 'The current approved public documents', status: downloads.length ? 'Approved public documents' : 'Contact the office for current documents', content: downloads.length ? `<ul class="parent-modal__list">${downloads.map(item => `<li><a href="/downloads/${esc(item.id)}">${esc(item.title)}</a> · Version ${item.version}</li>`).join('')}</ul>` : '<p>The school office can help you obtain current joining information and approved guidance. Private pupil and employment records are never listed publicly.</p><p><a href="/downloads">Open the download centre</a></p>' },
      { icon: 'bed', title: 'Boarding Information', teaser: 'Confirm current arrangements with the school office', status: 'Office guidance', content: '<p>Contact the school office to confirm current boarding availability, supervision, routines and joining requirements for your child’s class.</p><p><a href="/admissions">Admissions and school visits</a></p>' },
      { icon: 'phone', title: 'Contact the School', teaser: 'Official phone, WhatsApp and email channels', status: 'School contact channels', content: `<p>${esc(s.officeHours)}</p><div class="parent-modal__contact"><a href="tel:${esc(s.phones[0].href)}">${icon('phone')}<span><strong>Call the school office</strong><small>${esc(s.phones[0].number)}</small></span></a><a href="${esc(wa)}" target="_blank" rel="noopener noreferrer">${icon('whatsapp')}<span><strong>WhatsApp</strong><small>${esc(s.whatsapp.number)}</small></span></a><a href="mailto:${esc(s.email)}">${icon('mail')}<span><strong>Email</strong><small>${esc(s.email)}</small></span></a></div>` }
    ];
    const body = `
${phero(ctx, 'Parent Corner', 'Parent Corner', 'Keeping Alpha families informed and connected — calendars, announcements, examinations, boarding information and direct lines to the school office.', 'graduation-group')}

<section class="sec">
  <div class="container">
    ${sectionHead('Information for Families', 'Everything in One Place', 'Select a card to open details. Documents approved for public distribution are published here; private pupil and family records are kept confidential.')}
    <div class="parent-grid">
      ${parentItems.map((item, i) => `
      <button class="parent-card rv" type="button" data-parent-modal="parent-modal-${i}" aria-haspopup="dialog" aria-controls="parent-modal-${i}">
        <span class="parent-card__summary"><span class="parent-card__icon">${icon(item.icon)}</span><span class="parent-card__heading"><strong>${esc(item.title)}</strong><small>${esc(item.teaser)}</small></span></span>
        <span class="parent-card__open">View details ${icon('arrow')}</span>
      </button>`).join('')}
    </div>
    ${parentItems.map((item, i) => `
    <dialog class="parent-modal" id="parent-modal-${i}" aria-labelledby="parent-modal-title-${i}">
      <div class="parent-modal__panel">
        <header class="parent-modal__header"><div><span class="kicker">Parent Corner</span><h2 id="parent-modal-title-${i}">${esc(item.title)}</h2><span class="parent-modal__status">${esc(item.status)}</span></div><button class="parent-modal__close" type="button" data-modal-close aria-label="Close ${esc(item.title)}">${icon('close')}</button></header>
        <div class="parent-modal__body">${item.content}</div>
      </div>
    </dialog>`).join('')}
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
    <div class="card rv">
      <div class="card__ico">${icon('shield')}</div><h3>Your Family’s Privacy Matters</h3>
      <p>Individual pupil results, attendance, fee balances and teacher messages are private. Family academic services are not active on this public website; the school office will explain the approved way to obtain your child’s information.</p>
      <p>${btn('/privacy', 'Privacy & Safeguarding', 'ghost', 'arrow')}</p>
    </div>
  </div>
</section>

`;
    return {
      body,
      meta: {
        title: 'Parent Corner — Alpha Adventist Pre & Primary School, Kigoma',
        desc: 'The Parent Corner of Alpha Adventist Pre & Primary School: school calendar, announcements, examinations, school rules, boarding information, parent communication and contact channels for Alpha families in Kigoma.'
      }
    };
  }
};
