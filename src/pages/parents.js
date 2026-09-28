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
    const proposedCourses = db.courses.filter(course => course.status === 'proposed');
    const parentItems = [
      { icon: 'calendar', title: 'School Calendar', teaser: 'Term dates and approved school events', status: 'Sample preview · dates awaiting approval', content: '<p>This preview shows how the approved school calendar will be presented. Official dates will replace these placeholders when published.</p><dl class="parent-modal__facts"><div><dt>Term opening</dt><dd>To be announced</dd></div><div><dt>Term closing</dt><dd>To be announced</dd></div><div><dt>Approved school events</dt><dd>To be announced</dd></div><div><dt>Parent meeting dates</dt><dd>To be announced</dd></div></dl>' },
      { icon: 'mega', title: 'Announcements', teaser: 'Important official notices', status: 'Current school notices', content: announcementContent },
      { icon: 'clipboard', title: 'Examination Information', teaser: 'Approved assessment and examination schedules', status: 'Schedule preview · official dates pending', content: '<p>Approved assessment and examination information will be displayed here when released by the school.</p><div class="parent-modal__sample"><strong>Schedule preview</strong><dl class="parent-modal__facts"><div><dt>Class / level</dt><dd>Published in the approved notice</dd></div><div><dt>Examination dates</dt><dd>To be announced</dd></div><div><dt>Subjects and sessions</dt><dd>To be announced</dd></div></dl></div><p class="parent-modal__privacy">Individual pupil results and reports are private and are never displayed on this public page.</p>' },
      { icon: 'book', title: 'Academic Information', teaser: 'Homework and learning guidance when approved', status: 'Example · not a current assignment', content: '<div class="parent-modal__sample"><strong>Example learning note</strong><p>Read together for a few minutes, talk about the story, and encourage your child to explain one new idea in their own words.</p><small>This is an illustrative example, not an assigned homework task. Class-specific guidance will be posted when approved.</small></div><p>Explore the published <a href="/academics">academic programme</a> for information about learning levels and subjects.</p>' },
      { icon: 'users', title: 'Parent Meetings', teaser: 'Dates, notices and relevant information', status: 'Meeting notice preview · date pending', content: '<p>Approved parent meeting notices will appear here. This sample shows the information families can expect to see:</p><dl class="parent-modal__facts"><div><dt>Date and time</dt><dd>To be announced</dd></div><div><dt>Venue</dt><dd>To be announced</dd></div><div><dt>Meeting information</dt><dd>To be announced</dd></div></dl>' },
      { icon: 'shield', title: 'School Rules & Joining Information', teaser: 'Approved parent and pupil guidance', status: 'Guidance preview · official document pending', content: '<p>Approved school rules and joining guidance will be published or linked here when authorised. Information may include:</p><ul class="parent-modal__list"><li>Daily routines and attendance</li><li>School expectations and respectful conduct</li><li>Joining requirements for day and boarding pupils</li><li>Family communication and pupil wellbeing guidance</li></ul><p>For current admissions steps, see <a href="/admissions">Admissions</a>.</p>' },
      { icon: 'badge', title: 'Fee Information', teaser: 'Current, management-approved fee schedules only', status: 'Fee schedule preview · official amounts not published', content: '<p>Only a current fee schedule approved by school management will be published here. No sample amounts are shown.</p><dl class="parent-modal__facts"><div><dt>Pre-Primary / Primary fees</dt><dd>See approved schedule when published</dd></div><div><dt>Day / boarding charges</dt><dd>See approved schedule when published</dd></div><div><dt>Payment instructions</dt><dd>Published with the approved schedule</dd></div></dl><p class="parent-modal__privacy">Individual pupil balances, account details and payment records are confidential and will never appear on this public page.</p>' },
      { icon: 'download', title: 'Policies & Downloads', teaser: 'Documents approved for public distribution', status: 'Download area preview', content: '<p>Only documents approved by school management for public distribution will be listed here. No downloadable policy documents are currently published.</p><div class="parent-modal__sample"><strong>Public documents area</strong><ul class="parent-modal__list"><li>Approved school policies</li><li>Approved parent and pupil guidance</li><li>Public forms and information sheets</li></ul><small>Documents will appear here after approval and upload.</small></div>' },
      { icon: 'laptop', title: 'Computer Training Notices', teaser: 'Registration notices after programme approval', status: 'Proposed · not yet operational', content: `<p>Community computer training is proposed and is not yet operational. The following course ideas are under consideration:</p><ul class="parent-modal__list">${proposedCourses.map(course => `<li>${esc(course.title)}</li>`).join('')}</ul><p>Registration information will be posted after formal approval and launch. See <a href="/computer-learning#community">Computer Learning</a> for programme updates.</p>` },
      { icon: 'phone', title: 'Contact the School', teaser: 'Official phone, WhatsApp and email channels', status: 'Official contact channels', content: `<p>The school office responds during working hours on school days.</p><div class="parent-modal__contact"><a href="tel:${esc(s.phones[0].href)}">${icon('phone')}<span><strong>Call the school office</strong><small>${esc(s.phones[0].number)}</small></span></a><a href="${esc(wa)}" target="_blank" rel="noopener">${icon('whatsapp')}<span><strong>WhatsApp</strong><small>${esc(s.whatsapp.number)}</small></span></a><a href="mailto:${esc(s.email)}">${icon('mail')}<span><strong>Email</strong><small>${esc(s.email)}</small></span></a></div>` }
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
