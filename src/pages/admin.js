'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon } = X;

const IMG_POOL = ['banner-conference', 'garden-project', 'exhibit-pupils', 'exhibit-teacher', 'leadership-guests', 'graduation-group', 'graduation-certificate', 'choir-teal', 'staff-group', 'choir-green'];
const GAL_CATS = ['Campus', 'Classrooms', 'Computer Learning', 'Sports', 'Music & Choir', 'Worship', 'Talent', 'Graduation', 'School Events', 'School Life'];
const NEWS_CATS = ['School News', 'Academic News', 'Sports', 'Spiritual Events', 'ICT & Technology', 'Graduation', 'Announcements', 'Student Activities', 'Community Activities'];

function adminLayout(ctx, inner, active, title) {
  const u = ctx.session;
  const link = (href, ic, label, key) => `<a href="${href}" ${active === key ? 'aria-current="page"' : ''}>${icon(ic)} ${label}</a>`;
  return `<!DOCTYPE html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>${esc(title)} — Alpha CMS</title>
<link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32.png">
<link rel="stylesheet" href="/fonts/fonts.css"><link rel="stylesheet" href="/css/main.css">
</head><body>
<header class="site-head" style="position:static">
  <div class="container site-head__in">
    <a class="brand" href="/admin"><img class="brand__logo" src="/img/logo-96.png" alt=""><span class="brand__text"><strong>Alpha CMS</strong><small>Content management — ${esc(u ? u.role : '')}</small></span></a>
    <div class="site-head__actions" style="margin-left:auto">
      <a class="btn btn--ghost btn--sm" href="/">View website</a>
      <form method="post" action="/admin/logout" style="margin:0"><input type="hidden" name="_csrf" value="${esc(u ? u.csrf : '')}"><button class="btn btn--primary btn--sm" type="submit">Sign out</button></form>
    </div>
  </div>
</header>
<div class="admin-wrap">
  <nav class="admin-side" aria-label="CMS sections">
    ${link('/admin', 'grid', 'Dashboard', 'dash')}
    ${link('/admin/password', 'lock', 'Change password', 'pw')}
    ${link('/admin/news', 'mega', 'News & Events', 'news')}
    ${link('/admin/announcements', 'mega', 'Announcements', 'ann')}
    ${link('/admin/gallery', 'camera', 'Gallery', 'gal')}
    ${link('/admin/courses', 'chip', 'Computer Courses', 'courses')}
    ${link('/admin/hero', 'sparkle', 'Homepage Hero', 'hero')}
    ${link('/admin/statements', 'quote', 'Mission / Vision / Philosophy', 'stmt')}
    ${link('/admin/leadership', 'badge', 'Leadership', 'lead')}
    ${link('/admin/settings', 'pin', 'Contact & Settings', 'set')}
    ${link('/admin/submissions', 'mail', 'Form Submissions', 'sub')}
    ${link('/admin/admissions', 'clipboard', 'Admissions', 'admissions')}
    ${u && u.role === 'super' ? link('/admin/users', 'lock', 'Users & Roles', 'users') : ''}
  </nav>
  <main class="admin-main">${inner}</main>
</div>
</body></html>`;
}

function loginPage(ctx, error) {
  return `<!DOCTYPE html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title>Staff sign in — Alpha CMS</title>
<link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32.png">
<link rel="stylesheet" href="/fonts/fonts.css"><link rel="stylesheet" href="/css/main.css"></head>
<body class="sec--sand" style="min-height:100vh">
<main class="container">
  <form class="card form admin-login" method="post" action="/admin/login" novalidate>
    <div style="text-align:center;margin-bottom:8px">
      <img src="/img/logo-160.png" alt="Alpha Adventist School crest" width="64" style="margin:0 auto 10px;border-radius:10px">
      <h1 style="font-size:1.4rem;margin-bottom:4px">Alpha CMS</h1>
      <p class="muted" style="font-size:.86rem">Authorised school administrators only</p>
    </div>
    ${error ? `<p class="form__status err" style="display:block">${esc(error)}</p>` : ''}
    <div class="field"><label for="u">Username</label><input id="u" name="username" required autocomplete="username"></div>
    <div class="field"><label for="p">Password</label><input id="p" name="password" type="password" required autocomplete="current-password"></div>
    <div class="field"><label for="mfa">Authenticator code (if enabled)</label><input id="mfa" name="mfa_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" maxlength="6"></div>
    <input type="hidden" name="_csrf" value="${esc(ctx.publicCsrf || '')}">
    <button class="btn btn--primary btn--block" type="submit">${icon('lock')} Sign in</button>
    <p class="muted" style="font-size:.76rem;text-align:center;margin:10px 0 0">Sessions expire after 10 hours. Login attempts are rate-limited and security events are logged.</p>
  </form>
</main></body></html>`;
}

function passwordPage(ctx, message) {
  const inner = `
  <h1>Change password</h1>
  <p class="muted">Choose a unique password with at least 12 characters, including uppercase and lowercase letters, a number, and a symbol.</p>
  ${message ? `<p class="form__status err" style="display:block">${esc(message)}</p>` : ''}
  <div class="admin-card" style="max-width:620px"><form class="form" method="post" action="/admin/password">
    ${csrfInput(ctx)}
    <div class="field"><label for="current_password">Current password</label><input id="current_password" name="current_password" type="password" autocomplete="current-password" required></div>
    <div class="field"><label for="new_password">New password</label><input id="new_password" name="new_password" type="password" autocomplete="new-password" minlength="12" required></div>
    <div class="field"><label for="confirm_password">Confirm new password</label><input id="confirm_password" name="confirm_password" type="password" autocomplete="new-password" minlength="12" required></div>
    <button class="btn btn--primary" type="submit">Update password</button>
  </form></div>`;
  return adminLayout(ctx, inner, 'pw', 'Change password');
}

function mfaSetupPage(ctx, secret, message) {
  const inner = `
  <h1>Secure your administrator account</h1>
  <p class="muted">Add this time-based one-time password secret to an authenticator app. Alpha will require a six-digit code when you sign in.</p>
  ${message ? `<p class="form__status err" style="display:block">${esc(message)}</p>` : ''}
  <div class="admin-card" style="max-width:620px">
    <h2 style="font-size:1.15rem">Authenticator secret</h2>
    <p><code style="overflow-wrap:anywhere;user-select:all">${esc(secret)}</code></p>
    <p class="muted">Set the app to TOTP, SHA-1, six digits, and a 30-second interval. Keep this secret private.</p>
    <form class="form" method="post" action="/admin/mfa">
      ${csrfInput(ctx)}
      <div class="field"><label for="mfa_code">Current authenticator code</label><input id="mfa_code" name="mfa_code" inputmode="numeric" autocomplete="one-time-code" pattern="[0-9]{6}" minlength="6" maxlength="6" required></div>
      <button class="btn btn--primary" type="submit">Verify and enable MFA</button>
    </form>
  </div>`;
  return adminLayout(ctx, inner, 'mfa', 'Set up MFA');
}

const f = {
  text: (id, label, value, attrs = '') => `<div class="field"><label for="${id}">${label}</label><input id="${id}" name="${id}" value="${esc(value)}" ${attrs}></div>`,
  area: (id, label, value, rows = 5) => `<div class="field"><label for="${id}">${label}</label><textarea id="${id}" name="${id}" rows="${rows}">${esc(value)}</textarea></div>`,
  select: (id, label, options, value) => `<div class="field"><label for="${id}">${label}</label><select id="${id}" name="${id}">${options.map(o => `<option ${o === value ? 'selected' : ''}>${esc(o)}</option>`).join('')}</select></div>`,
  check: (id, label, on) => `<div class="field"><label style="flex-direction:row;display:flex;gap:10px;align-items:center"><input type="checkbox" id="${id}" name="${id}" ${on ? 'checked' : ''} style="width:auto"> ${label}</label></div>`
};
const csrfInput = ctx => `<input type="hidden" name="_csrf" value="${esc(ctx.session.csrf)}">`;

function dashboard(ctx) {
  const db = ctx.db;
  const counts = [
    ['News articles', db.news.length, '/admin/news'],
    ['Announcements', db.announcements.length, '/admin/announcements'],
    ['Gallery photos', db.gallery.length, '/admin/gallery'],
    ['Computer courses', db.courses.length, '/admin/courses'],
    ['Form submissions', db.submissions.length, '/admin/submissions'],
    ['Admission applications', db.admissionApplications.length, '/admin/admissions'],
    ['Hero slides', db.hero.filter(h => h.enabled).length, '/admin/hero']
  ];
  const inner = `
  <h1>Dashboard</h1>
  <p class="muted">Welcome, ${esc(ctx.session.username)}. Signed in as <span class="pill pill--navy">${esc(ctx.session.role)}</span></p>
  <div class="grid grid--3">
    ${counts.map(c => `<a class="card" href="${c[2]}" style="text-decoration:none"><h3 style="font-size:2rem;margin:0">${c[1]}</h3><p style="margin:0">${c[0]}</p></a>`).join('')}
  </div>
  <div class="admin-card" style="margin-top:22px">
    <h3>Latest submissions</h3>
    ${db.submissions.length ? `<table class="admin-table"><thead><tr><th>Reference</th><th>Type</th><th>Name</th><th>Contact</th><th>When</th></tr></thead><tbody>
      ${db.submissions.slice(-6).reverse().map(s => `<tr><td><strong>${esc(s.reference || '—')}</strong></td><td><span class="pill pill--gold">${esc(s.type)}</span></td><td>${esc(s.data.name || s.data.child_name || '—')}</td><td>${esc(s.data.phone || '—')}</td><td>${esc(new Date(s.at).toLocaleString())}</td></tr>`).join('')}
    </tbody></table>` : '<p class="muted">No submissions yet.</p>'}
  </div>
  <div class="admin-card">
    <h3>Content governance reminder</h3>
    <p class="muted" style="font-size:.9rem">Official Mission, Vision and Philosophy wording must remain exactly as approved by the school. Explanatory text may be added around them but never presented as the official statements. Do not publish fees, results, staff records or unapproved photographs. New social-media links may be published only after management verification.</p>
  </div>`;
  return adminLayout(ctx, inner, 'dash', 'Dashboard');
}

function newsPage(ctx, editItem, msg) {
  const db = ctx.db;
  const inner = `
  <h1>News & Events</h1>
  ${msg ? `<p class="form__status ok" style="display:block">${esc(msg)}</p>` : ''}
  <div class="admin-card">
    <table class="admin-table"><thead><tr><th>Title</th><th>Category</th><th>Date</th><th>Featured</th><th></th></tr></thead><tbody>
    ${db.news.map(n => `<tr><td><strong>${esc(n.title)}</strong></td><td>${esc(n.category)}</td><td>${esc(n.dateLabel)}</td><td>${n.featured ? '<span class="pill pill--green">Featured</span>' : ''}</td><td class="admin-actions"><a class="btn btn--ghost" href="/admin/news?edit=${esc(n.slug)}">Edit</a><form method="post" action="/admin/news/delete" onsubmit="return confirm('Delete this article?')">${csrfInput(ctx)}<input type="hidden" name="slug" value="${esc(n.slug)}"><button class="btn btn--primary" type="submit">Delete</button></form></td></tr>`).join('')}
    </tbody></table>
  </div>
  <div class="admin-card">
    <h3>${editItem ? 'Edit article' : 'New article'}</h3>
    <form class="form" method="post" action="/admin/news/save">
      ${csrfInput(ctx)}
      ${f.text('title', 'Title *', editItem ? editItem.title : '')}
      <div class="form__row">
        ${f.select('category', 'Category', NEWS_CATS, editItem ? editItem.category : NEWS_CATS[0])}
        ${f.text('dateLabel', 'Date label (e.g. "2026 Graduation Weekend")', editItem ? editItem.dateLabel : '')}
      </div>
      <div class="form__row">
        ${f.text('author', 'Author', editItem ? editItem.author : 'School Administration')}
        ${f.select('image', 'Featured image', IMG_POOL, editItem ? editItem.image : IMG_POOL[0])}
      </div>
      ${f.text('alt', 'Image alt text (describe the photograph)', editItem ? editItem.alt || '' : '')}
      ${f.area('excerpt', 'Excerpt / summary *', editItem ? editItem.excerpt : '', 3)}
      ${f.area('content', 'Article body — separate paragraphs with a blank line', editItem ? editItem.content.join('\n\n') : '', 10)}
      ${f.text('gallery', 'Extra gallery images (comma-separated keys, optional)', editItem && editItem.gallery ? editItem.gallery.join(', ') : '')}
      ${f.check('featured', 'Feature on homepage', editItem ? editItem.featured : false)}
      ${editItem ? `<input type="hidden" name="slug" value="${esc(editItem.slug)}">` : ''}
      <button class="btn btn--primary" type="submit">${icon('check')} Save article</button>
    </form>
  </div>`;
  return adminLayout(ctx, inner, 'news', 'News');
}

function announcementsPage(ctx, msg) {
  const db = ctx.db;
  const inner = `
  <h1>Announcements</h1>
  ${msg ? `<p class="form__status ok" style="display:block">${esc(msg)}</p>` : ''}
  <div class="admin-card">
    ${db.announcements.map((a, i) => `<div class="announce" style="margin-bottom:12px"><div class="news-meta"><span class="news-cat">${esc(a.tag)}</span><span>${esc(a.dateLabel)}</span></div><h4>${esc(a.title)}</h4><p>${esc(a.body)}</p>
      <form method="post" action="/admin/announcements/delete" style="margin-top:8px">${csrfInput(ctx)}<input type="hidden" name="index" value="${i}"><button class="btn btn--primary btn--sm" type="submit">Delete</button></form></div>`).join('') || '<p class="muted">No announcements.</p>'}
  </div>
  <div class="admin-card"><h3>New announcement</h3>
    <form class="form" method="post" action="/admin/announcements/save">${csrfInput(ctx)}
      ${f.text('title', 'Title *', '')}
      <div class="form__row">${f.select('tag', 'Tag', ['Parents', 'ICT & Technology', 'Gallery', 'Academics', 'Admissions', 'Spiritual Events'], 'Parents')}${f.text('dateLabel', 'Date label', String(new Date().getFullYear()))}</div>
      ${f.area('body', 'Notice text *', '', 3)}
      <button class="btn btn--primary" type="submit">${icon('check')} Publish announcement</button>
    </form>
  </div>`;
  return adminLayout(ctx, inner, 'ann', 'Announcements');
}

function galleryPage(ctx, editIndex, msg) {
  const db = ctx.db;
  const item = editIndex != null ? db.gallery[editIndex] : null;
  const inner = `
  <h1>Gallery</h1>
  ${msg ? `<p class="form__status ok" style="display:block">${esc(msg)}</p>` : ''}
  <p class="muted" style="font-size:.88rem">Only approved photographs may be published. Captions must describe activities — never identify individual pupils.</p>
  <div class="admin-card">
    <table class="admin-table"><thead><tr><th></th><th>Caption</th><th>Category</th><th></th></tr></thead><tbody>
    ${db.gallery.map((g, i) => `<tr><td style="width:90px"><img src="/img/${esc(g.img)}-480.webp" alt="" style="border-radius:8px;width:90px"></td><td>${esc(g.caption)}</td><td><span class="pill pill--navy">${esc(g.cat)}</span></td><td class="admin-actions"><a class="btn btn--ghost" href="/admin/gallery?edit=${i}">Edit</a><form method="post" action="/admin/gallery/delete">${csrfInput(ctx)}<input type="hidden" name="index" value="${i}"><button class="btn btn--primary" type="submit">Delete</button></form></td></tr>`).join('')}
    </tbody></table>
  </div>
  <div class="admin-card"><h3>${item ? 'Edit photograph' : 'Add approved photograph'}</h3>
    <form class="form" method="post" action="/admin/gallery/save">${csrfInput(ctx)}
      <div class="form__row">
        ${f.select('img', 'Image', IMG_POOL, item ? item.img : IMG_POOL[0])}
        ${f.select('cat', 'Category', GAL_CATS, item ? item.cat : GAL_CATS[0])}
      </div>
      ${f.text('caption', 'Caption *', item ? item.caption : '')}
      ${f.text('alt', 'Alt text (accessibility) *', item ? item.alt : '')}
      ${item ? `<input type="hidden" name="index" value="${editIndex}">` : ''}
      <button class="btn btn--primary" type="submit">${icon('check')} Save photograph</button>
    </form>
  </div>`;
  return adminLayout(ctx, inner, 'gal', 'Gallery');
}

function coursesPage(ctx, msg) {
  const db = ctx.db;
  const inner = `
  <h1>Computer Courses</h1>
  ${msg ? `<p class="form__status ok" style="display:block">${esc(msg)}</p>` : ''}
  <p class="muted" style="font-size:.88rem">Mark pupil ICT topics as <span class="pill pill--green">In school</span> only where documented. Community courses must remain <span class="pill pill--gold">Coming soon</span> until formal approval and launch.</p>
  <div class="admin-card">
    <table class="admin-table"><thead><tr><th>Course</th><th>Audience</th><th>Status</th><th></th></tr></thead><tbody>
    ${db.courses.map((c, i) => `<tr><td><strong>${esc(c.title)}</strong><br><small class="muted">${esc(c.blurb)}</small></td><td>${esc(c.audience)}</td><td>${c.status === 'current' ? '<span class="pill pill--green">In school</span>' : '<span class="pill pill--gold">Coming soon</span>'}</td><td class="admin-actions"><form method="post" action="/admin/courses/toggle">${csrfInput(ctx)}<input type="hidden" name="index" value="${i}"><button class="btn btn--ghost" type="submit">Toggle status</button></form><form method="post" action="/admin/courses/delete">${csrfInput(ctx)}<input type="hidden" name="index" value="${i}"><button class="btn btn--primary" type="submit">Delete</button></form></td></tr>`).join('')}
    </tbody></table>
  </div>
  <div class="admin-card"><h3>New course</h3>
    <form class="form" method="post" action="/admin/courses/save">${csrfInput(ctx)}
      ${f.text('title', 'Course title *', '')}
      ${f.area('blurb', 'Short description *', '', 2)}
      <div class="form__row">${f.select('audience', 'Audience', ['Pupils', 'Staff', 'Community'], 'Pupils')}${f.select('status', 'Status', ['current', 'proposed'], 'proposed')}</div>
      <button class="btn btn--primary" type="submit">${icon('check')} Save course</button>
    </form>
  </div>`;
  return adminLayout(ctx, inner, 'courses', 'Courses');
}

function heroPage(ctx, msg) {
  const db = ctx.db;
  const inner = `
  <h1>Homepage Hero Slides</h1>
  ${msg ? `<p class="form__status ok" style="display:block">${esc(msg)}</p>` : ''}
  ${db.hero.map((h, i) => `
  <div class="admin-card">
    <h3>Slide ${i + 1} — ${esc(h.kicker)} ${h.enabled ? '<span class="pill pill--green">Enabled</span>' : '<span class="pill pill--red">Hidden</span>'}</h3>
    <form class="form" method="post" action="/admin/hero/save">${csrfInput(ctx)}<input type="hidden" name="index" value="${i}">
      <div class="form__row">
        ${f.text('kicker', 'Kicker / eyebrow', h.kicker)}
        ${f.select('image', 'Photograph', IMG_POOL, h.image)}
      </div>
      ${f.text('title', 'Heading *', h.title)}
      ${f.text('strap', 'Strap line (optional)', h.strap || '')}
      ${f.area('text', 'Supporting text', h.text, 2)}
      <div class="form__row">
        ${f.text('cta_label', 'Primary button label', h.cta ? h.cta.label : '')}
        ${f.text('cta_href', 'Primary button link', h.cta ? h.cta.href : '')}
      </div>
      <div class="form__row">
        ${f.text('cta2_label', 'Secondary button label', h.cta2 ? h.cta2.label : '')}
        ${f.text('cta2_href', 'Secondary button link', h.cta2 ? h.cta2.href : '')}
      </div>
      ${f.text('alt', 'Photograph alt text', h.alt)}
      ${f.check('enabled', 'Show this slide', h.enabled)}
      <button class="btn btn--primary" type="submit">${icon('check')} Save slide</button>
    </form>
  </div>`).join('')}`;
  return adminLayout(ctx, inner, 'hero', 'Hero');
}

function statementsPage(ctx, msg) {
  const db = ctx.db;
  const inner = `
  <h1>Mission • Vision • Philosophy</h1>
  ${msg ? `<p class="form__status ok" style="display:block">${esc(msg)}</p>` : ''}
  <div class="note-strip" style="margin-bottom:18px">${icon('lock')}<span>These are the school's <strong>official institutional statements</strong>. Edit only when the School Board or Western Tanzania Conference formally approves new wording. Explanatory text on the website is generated separately and must not be confused with these statements.</span></div>
  <div class="admin-card"><form class="form" method="post" action="/admin/statements/save">${csrfInput(ctx)}
    ${f.area('mission', 'Official Mission', db.statements.mission, 2)}
    ${f.area('vision', 'Official Vision', db.statements.vision, 2)}
    ${f.area('philosophy', 'Educational Philosophy', db.statements.philosophy, 2)}
    <button class="btn btn--primary" type="submit">${icon('check')} Save statements</button>
  </form></div>`;
  return adminLayout(ctx, inner, 'stmt', 'Statements');
}

function leadershipPage(ctx, msg) {
  const l = ctx.db.leadership;
  const inner = `
  <h1>Leadership</h1>
  ${msg ? `<p class="form__status ok" style="display:block">${esc(msg)}</p>` : ''}
  <div class="admin-card"><form class="form" method="post" action="/admin/leadership/save">${csrfInput(ctx)}
    <div class="form__row">
      ${f.text('name', 'Name *', l.name)}
      ${f.text('title', 'Position title *', l.title)}
    </div>
    ${f.text('photo', 'Official portrait path (e.g. /img/head-of-school-800.jpg) — leave empty to show the crest placeholder', l.photo || '')}
    ${f.text('signature', 'Leadership signature line', l.signature)}
    ${f.area('shortMessage', 'Homepage short message', l.shortMessage, 5)}
    ${f.area('fullMessage', 'Full message — separate paragraphs with a blank line', l.fullMessage.join('\n\n'), 14)}
    <button class="btn btn--primary" type="submit">${icon('check')} Save leadership</button>
  </form></div>
  <p class="muted" style="font-size:.85rem">To publish a portrait, place the approved photograph in the website images folder (optimised sizes) and enter its path above. The CMS keeps this editable so leadership changes never require a redesign.</p>`;
  return adminLayout(ctx, inner, 'lead', 'Leadership');
}

function settingsPage(ctx, msg) {
  const s = ctx.db.settings;
  const inner = `
  <h1>Contact & Settings</h1>
  ${msg ? `<p class="form__status ok" style="display:block">${esc(msg)}</p>` : ''}
  <div class="admin-card"><form class="form" method="post" action="/admin/settings/save">${csrfInput(ctx)}
    <div class="form__row">${f.text('locationText', 'Location text', s.locationText)}${f.text('mapNote', 'Map note', s.mapNote)}</div>
    <div class="form__row">${f.text('phone1', 'Phone 1 (label|number|href)', `${s.phones[0].label}|${s.phones[0].number}|${s.phones[0].href}`)}${f.text('phone2', 'Phone 2 (label|number|href)', `${s.phones[1].label}|${s.phones[1].number}|${s.phones[1].href}`)}</div>
    <div class="form__row">${f.text('whatsapp', 'WhatsApp number (number|href)', `${s.whatsapp.number}|${s.whatsapp.href}`)}${f.text('email', 'Official email', s.email)}</div>
    ${f.check('emailPublished', 'Publish the email address on the website', s.emailPublished)}
    <div class="form__row">${f.text('box', 'P.O. Box', s.address.box)}${f.text('city', 'City', s.address.city)}</div>
    ${f.area('officeHours', 'Office hours note', s.officeHours, 2)}
    ${f.text('socialNote', 'Social media note', s.socialNote)}
    ${f.text('centreCode', 'National examination centre code', s.centreCode)}
    <button class="btn btn--primary" type="submit">${icon('check')} Save settings</button>
  </form></div>`;
  return adminLayout(ctx, inner, 'set', 'Settings');
}

function submissionsPage(ctx) {
  const db = ctx.db;
  const rows = db.submissions.slice().reverse();
  const inner = `
  <h1>Form Submissions</h1>
  <p class="muted" style="font-size:.88rem">Private — for the school office only. Never publish or share this information.</p>
  <div class="admin-card">
    ${rows.length ? `<table class="admin-table"><thead><tr><th>Reference</th><th>When</th><th>Type</th><th>Details</th><th></th></tr></thead><tbody>
    ${rows.map((s, ri) => {
      const i = db.submissions.length - 1 - ri;
      const d = s.data;
      const detail = Object.keys(d).filter(k => k !== 'website_url' && d[k]).map(k => `<strong>${esc(k)}:</strong> ${esc(d[k])}`).join('<br>');
      return `<tr><td><strong>${esc(s.reference || '—')}</strong></td><td>${esc(new Date(s.at).toLocaleString())}</td><td><span class="pill pill--gold">${esc(s.type)}</span></td><td>${detail}</td><td><form method="post" action="/admin/submissions/delete">${csrfInput(ctx)}<input type="hidden" name="index" value="${i}"><button class="btn btn--primary btn--sm" type="submit">Delete</button></form></td></tr>`;
    }).join('')}
    </tbody></table>` : '<p class="muted">No submissions yet. Public form entries will appear here.</p>'}
  </div>`;
  return adminLayout(ctx, inner, 'sub', 'Submissions');
}

function admissionsPage(ctx, msg, transitions) {
  const applications = ctx.db.admissionApplications.slice().sort((a, b) => new Date(b.createdAt) - new Date(a.createdAt));
  const rows = applications.map(application => {
    const guardian = application.guardians[0] || {};
    const nextStates = transitions[application.status] || [];
    return `<tr>
      <td><strong>${esc(application.applicationReference)}</strong><br><small class="muted">${esc(new Date(application.submittedAt || application.createdAt).toLocaleString())}</small></td>
      <td>${esc(application.applicant.fullName)}</td>
      <td>${esc(application.classApplyingFor)}<br><small>${esc(application.boardingStatus)}</small></td>
      <td>${esc(guardian.fullName || '—')}<br><a href="tel:${esc(guardian.phone || '')}">${esc(guardian.phone || '—')}</a>${guardian.email ? `<br><a href="mailto:${esc(guardian.email)}">${esc(guardian.email)}</a>` : ''}</td>
      <td><span class="pill pill--gold">${esc(application.status.replaceAll('_', ' '))}</span></td>
      <td>${nextStates.length ? `<form method="post" action="/admin/admissions/status" class="admin-actions">${csrfInput(ctx)}<input type="hidden" name="applicationReference" value="${esc(application.applicationReference)}"><select name="status" required><option value="">Next status</option>${nextStates.map(status => `<option value="${esc(status)}">${esc(status.replaceAll('_', ' '))}</option>`).join('')}</select><button class="btn btn--primary btn--sm" type="submit">Update</button></form>` : '<span class="muted">Final</span>'}</td>
    </tr>`;
  }).join('');
  const inner = `
  <h1>Admissions</h1>
  <p class="muted">Private applications for authorised school administrators only.</p>
  ${msg ? `<p class="form__status ok" style="display:block">${esc(msg)}</p>` : ''}
  <div class="admin-card">
    ${rows ? `<table class="admin-table"><thead><tr><th>Reference / submitted</th><th>Applicant</th><th>Class / arrangement</th><th>Primary guardian</th><th>Status</th><th>Review</th></tr></thead><tbody>${rows}</tbody></table>` : '<p class="muted">No admission applications have been received.</p>'}
  </div>`;
  return adminLayout(ctx, inner, 'admissions', 'Admissions');
}

function usersPage(ctx, msg) {
  const db = ctx.db;
  const inner = `
  <h1>Users & Roles</h1>
  ${msg ? `<p class="form__status ok" style="display:block">${esc(msg)}</p>` : ''}
  <div class="admin-card">
    <table class="admin-table"><thead><tr><th>Username</th><th>Name</th><th>Role</th><th></th></tr></thead><tbody>
    ${db.users.map((u, i) => `<tr><td><strong>${esc(u.username)}</strong></td><td>${esc(u.name)}</td><td><span class="pill pill--navy">${esc(u.role)}</span></td><td>${u.username !== ctx.session.username ? `<form method="post" action="/admin/users/delete">${csrfInput(ctx)}<input type="hidden" name="index" value="${i}"><button class="btn btn--primary btn--sm" type="submit">Remove</button></form>` : '<span class="muted">you</span>'}</td></tr>`).join('')}
    </tbody></table>
    <p class="muted" style="font-size:.82rem;margin-top:12px">Roles — <strong>super</strong>: everything incl. users · <strong>admin</strong>: all content &amp; settings · <strong>editor</strong>: news, announcements, gallery, courses · <strong>contributor</strong>: news &amp; announcements drafts.</p>
  </div>
  <div class="admin-card"><h3>Add user</h3>
    <form class="form" method="post" action="/admin/users/save">${csrfInput(ctx)}
      <div class="form__row">${f.text('username', 'Username *', '')}${f.text('name', 'Full name *', '')}</div>
      <div class="form__row">${f.select('role', 'Role', ['super', 'admin', 'editor', 'contributor'], 'editor')}${f.text('password', 'Temporary password *', '', 'type="password" autocomplete="new-password" minlength="12" required')}</div>
      <button class="btn btn--primary" type="submit">${icon('check')} Create user</button>
    </form>
  </div>`;
  return adminLayout(ctx, inner, 'users', 'Users');
}

module.exports = { adminLayout, loginPage, passwordPage, mfaSetupPage, dashboard, newsPage, announcementsPage, galleryPage, coursesPage, heroPage, statementsPage, leadershipPage, settingsPage, submissionsPage, usersPage, IMG_POOL, GAL_CATS, NEWS_CATS };
module.exports = { adminLayout, loginPage, passwordPage, mfaSetupPage, dashboard, newsPage, announcementsPage, galleryPage, coursesPage, heroPage, statementsPage, leadershipPage, settingsPage, submissionsPage, admissionsPage, usersPage, IMG_POOL, GAL_CATS, NEWS_CATS };
