'use strict';
const L = require('./lib');
const esc = L.esc;

/* ============ ICONS (inline SVG, 24x24 stroke) ============ */
const P = {
  book: '<path d="M4 5.5A2.5 2.5 0 0 1 6.5 3H20v15H6.5A2.5 2.5 0 0 0 4 20.5z"/><path d="M4 5.5V20.5"/><path d="M9 7.5h7M9 11h7"/>',
  flame: '<path d="M12 3c1 3-3 4.5-3 8a3.5 3.5 0 0 0 7 0c0-1.2-.4-2.2-1-3-.3 1-.9 1.6-1.6 2 .5-2.4-.2-5-1.4-7z"/><path d="M12 21a6 6 0 0 0 6-6c0-1.6-.5-3-1.3-4.2M12 21a6 6 0 0 1-6-6c0-1.6.5-3 1.3-4.2"/>',
  chip: '<rect x="7" y="7" width="10" height="10" rx="2"/><path d="M10 3v4M14 3v4M10 17v4M14 17v4M3 10h4M3 14h4M17 10h4M17 14h4"/>',
  star: '<path d="m12 3.6 2.5 5.1 5.6.8-4 4 .9 5.6-5-2.7-5 2.7.9-5.6-4-4 5.6-.8z"/>',
  shield: '<path d="M12 3 5 6v5c0 4.5 3 8.4 7 10 4-1.6 7-5.5 7-10V6z"/><path d="m9.3 11.8 2 2 3.6-4"/>',
  rocket: '<path d="M12 15c-2 0-3-1-3-3 0-4 2-7.5 3-9 1 1.5 3 5 3 9 0 2-1 3-3 3z"/><path d="M9 12c-2 .5-3.5 2-4 5 2.6-.3 4.2-1 5-2M15 12c2 .5 3.5 2 4 5-2.6-.3-4.2-1-5-2"/><circle cx="12" cy="10" r="1.4"/>',
  phone: '<path d="M6.8 3.5h2.4l1.3 3.4-1.8 1.4a12.6 12.6 0 0 0 5 5l1.4-1.8 3.4 1.3v2.4c0 1-.8 1.9-1.9 1.8C10 16.6 7.4 14 5 6.9c-.3-1.1.2-2.4 1.8-3.4z"/>',
  mail: '<rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3.5 7 8.5 6 8.5-6"/>',
  pin: '<path d="M12 21s7-6.1 7-11a7 7 0 1 0-14 0c0 4.9 7 11 7 11z"/><circle cx="12" cy="10" r="2.6"/>',
  whatsapp: '<path d="M12 3a9 9 0 0 0-7.8 13.5L3 21l4.6-1.2A9 9 0 1 0 12 3z"/><path d="M8.8 9.2c-.3 1.6 1 3.6 2.3 4.8 1.2 1.1 2.9 2 4.2 1.7l.5-1.5-2-1-.9.8c-.9-.4-2-1.4-2.4-2.3l.8-.9-1-2z"/>',
  clock: '<circle cx="12" cy="12" r="8.5"/><path d="M12 7.5V12l3 2"/>',
  menu: '<path d="M4 7h16M4 12h16M4 17h16"/>',
  close: '<path d="m6 6 12 12M18 6 6 18"/>',
  arrow: '<path d="M5 12h14M13 6l6 6-6 6"/>',
  arrowUp: '<path d="M7 17 17 7M9 7h8v8"/>',
  check: '<path d="m5 12.5 4.5 4.5L19 7.5"/>',
  camera: '<path d="M4 8h3l2-2.5h6L17 8h3a1 1 0 0 1 1 1v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V9a1 1 0 0 1 1-1z"/><circle cx="12" cy="13" r="3.4"/>',
  calendar: '<rect x="3.5" y="5" width="17" height="16" rx="2"/><path d="M3.5 10h17M8 3v4M16 3v4"/>',
  mega: '<path d="M4 10v4a1 1 0 0 0 1 1h2l8 4V5L7 9H5a1 1 0 0 0-1 1z"/><path d="M18.5 9.5a4 4 0 0 1 0 5"/>',
  users: '<circle cx="9" cy="8.5" r="3.2"/><path d="M3.5 19c.6-3.2 2.8-5 5.5-5s4.9 1.8 5.5 5"/><circle cx="16.8" cy="9.5" r="2.6"/><path d="M15.5 14.3c2.4.2 4.3 1.8 4.9 4.7"/>',
  laptop: '<rect x="4" y="5" width="16" height="10" rx="1.5"/><path d="M2.5 18.5h19"/>',
  music: '<path d="M9 18.5V6l10-2v12.5"/><circle cx="6.5" cy="18.5" r="2.5"/><circle cx="16.5" cy="16.5" r="2.5"/>',
  ball: '<circle cx="12" cy="12" r="8.5"/><path d="M12 3.5c2.5 2.4 2.5 14.6 0 17M3.8 9.5c4.6 2 11.8 2 16.4 0M3.8 14.5c4.6-2 11.8-2 16.4 0"/>',
  palette: '<path d="M12 3a9 9 0 1 0 0 18c1.6 0 2.2-1 1.6-2.2-.7-1.4.2-2.8 1.8-2.8H18a3.6 3.6 0 0 0 3-3.6C21 7 17 3 12 3z"/><circle cx="8" cy="9" r="1.1"/><circle cx="12" cy="7.5" r="1.1"/><circle cx="15.8" cy="9.6" r="1.1"/>',
  mic: '<rect x="9.5" y="3" width="5" height="10" rx="2.5"/><path d="M6 11a6 6 0 0 0 12 0M12 17v4M9 21h6"/>',
  leaf: '<path d="M5 19C5 9 12 4 20 4c0 9-5 15-13 15"/><path d="M5 19c2-5 6-9 10-11"/>',
  cross: '<path d="M12 4v16M7 9h10"/>',
  heart: '<path d="M12 20s-7.5-4.7-7.5-10A4.3 4.3 0 0 1 12 7.6 4.3 4.3 0 0 1 19.5 10c0 5.3-7.5 10-7.5 10z"/>',
  grid: '<rect x="4" y="4" width="7" height="7" rx="1.5"/><rect x="13" y="4" width="7" height="7" rx="1.5"/><rect x="4" y="13" width="7" height="7" rx="1.5"/><rect x="13" y="13" width="7" height="7" rx="1.5"/>',
  lock: '<rect x="5" y="10.5" width="14" height="10" rx="2"/><path d="M8 10.5V8a4 4 0 0 1 8 0v2.5"/>',
  clipboard: '<rect x="5" y="4.5" width="14" height="16.5" rx="2"/><path d="M9 4.5A1.5 1.5 0 0 1 10.5 3h3A1.5 1.5 0 0 1 15 4.5V6H9z"/><path d="M9 11h6M9 15h6"/>',
  flask: '<path d="M10 3v5.5L4.8 18a2 2 0 0 0 1.8 3h10.8a2 2 0 0 0 1.8-3L14 8.5V3"/><path d="M8.5 3h7M7.5 14.5h9"/>',
  growth: '<path d="M4 19h16M6 16v-4M10.5 16V8M15 16v-6M19.5 16V5"/>',
  target: '<circle cx="12" cy="12" r="8.5"/><circle cx="12" cy="12" r="4.5"/><circle cx="12" cy="12" r="1"/>',
  sun: '<circle cx="12" cy="12" r="4"/><path d="M12 2.5v2.5M12 19v2.5M2.5 12H5M19 12h2.5M5.3 5.3 7 7M17 17l1.7 1.7M18.7 5.3 17 7M7 17l-1.7 1.7"/>',
  home: '<path d="m4 11 8-7 8 7"/><path d="M6 9.5V20h12V9.5"/>',
  download: '<path d="M12 4v10M8 10.5l4 4 4-4M5 19h14"/>',
  share: '<circle cx="6" cy="12" r="2.5"/><circle cx="17" cy="6" r="2.5"/><circle cx="17" cy="18" r="2.5"/><path d="m8.3 10.8 6.4-3.6M8.3 13.2l6.4 3.6"/>',
  link: '<path d="M10 14a4 4 0 0 0 6 .4l2.5-2.5a4 4 0 0 0-5.6-5.6L11.5 7.7"/><path d="M14 10a4 4 0 0 0-6-.4L5.5 12.1a4 4 0 0 0 5.6 5.6l1.4-1.4"/>',
  sparkle: '<path d="M12 3.5 13.8 9l5.7 1.8-5.7 1.8L12 18.5 10.2 12.6 4.5 10.8 10.2 9z"/><path d="M19 3.5v3M17.5 5h3"/>',
  keyboard: '<rect x="3" y="7" width="18" height="10" rx="2"/><path d="M6.5 10.5h.01M10 10.5h.01M13.5 10.5h.01M17 10.5h.01M6.5 13.5h.01M17 13.5h.01M9.5 13.5h5"/>',
  globe: '<circle cx="12" cy="12" r="8.5"/><path d="M3.5 12h17M12 3.5c2.5 2.4 2.5 14.6 0 17M12 3.5c-2.5 2.4-2.5 14.6 0 17"/>',
  pencil: '<path d="m14.5 5.5 4 4L8 20H4v-4z"/><path d="m12.5 7.5 4 4"/>',
  badge: '<circle cx="12" cy="9" r="5.5"/><path d="m8.8 13.5-1.3 7 4.5-2.6 4.5 2.6-1.3-7"/>',
  bed: '<path d="M3 18v-8M3 14h18v4M3 14V7"/><path d="M7 11h4a3 3 0 0 1 3 3"/><circle cx="7" cy="10" r="0.6"/>',
  eye: '<path d="M2.5 12S6 5.8 12 5.8 21.5 12 21.5 12 18 18.2 12 18.2 2.5 12 2.5 12z"/><circle cx="12" cy="12" r="3"/>',
  send: '<path d="M21 3 3 10.5l7 3 3 7z"/><path d="M21 3 10 13.5"/>',
  quote: '<path d="M9 7c-3 1-4.5 3.4-4.5 6.5V17H10v-6H7.2C7.6 9.4 8.4 8.4 10 7.6zM19 7c-3 1-4.5 3.4-4.5 6.5V17H20v-6h-2.8c.4-1.6 1.2-2.6 2.8-3.4z"/>',
  chevron: '<path d="m8 10 4 4 4-4"/>'
};
function icon(name, cls = '') {
  const d = P[name] || P.star;
  return `<svg class="icon ${cls}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">${d}</svg>`;
}

/* ============ image helper ============ */
function pic(base, alt, opts = {}) {
  const widths = opts.widths || [480, 800, 1200, 1600];
  const sizes = opts.sizes || '(max-width: 700px) 92vw, (max-width: 1100px) 60vw, 1200px';
  const cls = opts.cls ? ` class="${opts.cls}"` : '';
  const eager = opts.eager ? '' : ' loading="lazy"';
  const decoding = ' decoding="async"';
  const webpSrc = widths.map(w => `/img/${base}-${w}.webp ${w}w`).join(', ');
  const jpgSrc = `/img/${base}-800.jpg 800w, /img/${base}-1600.jpg 1600w`;
  const fallback = widths.includes(800) ? `/img/${base}-800.jpg` : `/img/${base}-1600.jpg`;
  const ratio = opts.ratio ? ` style="aspect-ratio:${opts.ratio}"` : '';
  return `<picture><source type="image/webp" srcset="${webpSrc}" sizes="${sizes}"><img src="${fallback}" srcset="${jpgSrc}" sizes="${sizes}" alt="${esc(alt)}"${cls}${eager}${decoding}${ratio} width="${opts.w || 1600}" height="${opts.h || 1066}"></picture>`;
}

/* ============ small partials ============ */
function btn(href, label, variant = 'primary', ic = '') {
  const cls = `btn btn--${variant}`;
  const ico = ic ? icon(ic, 'btn__ico') : '';
  return `<a class="${cls}" href="${esc(href)}">${esc(label)}${ico}</a>`;
}
function sectionHead(kicker, title, text, opts = {}) {
  const align = opts.align === 'center' ? ' sec-head--center' : '';
  const tone = opts.tone ? ` sec-head--${opts.tone}` : '';
  return `<div class="sec-head${align}${tone}">
    ${kicker ? `<span class="kicker">${esc(kicker)}</span>` : ''}
    <h2>${esc(title)}</h2>
    ${text ? `<p class="sec-head__text">${esc(text)}</p>` : ''}
  </div>`;
}

/* ============ NAV ============ */
const NAV = [
  { href: '/', label: 'Home' },
  { href: '/about', label: 'About Us' },
  { href: '/academics', label: 'Academics' },
  { href: '/admissions', label: 'Admissions' },
  { href: '/computer-learning', label: 'Computer Learning' },
  { href: '/school-life', label: 'School Life' },
  { href: '/parents', label: 'Parents' },
  { href: '/students', label: 'Students' },
  { href: '/news', label: 'News & Events' },
  { href: '/gallery', label: 'Gallery' },
  { href: '/contact', label: 'Contact' }
];

function header(ctx) {
  const s = ctx.db.settings;
  const path = ctx.path;
  const isActive = href => (href === '/' ? path === '/' : path === href || path.startsWith(href + '/'));
  const links = NAV.map(n => `<li><a href="${n.href}" ${isActive(n.href) ? 'aria-current="page"' : ''}>${esc(n.label)}</a></li>`).join('');
  const mlinks = NAV.map(n => `<li><a href="${n.href}" ${isActive(n.href) ? 'aria-current="page"' : ''}>${esc(n.label)}</a></li>`).join('');
  const wa = `https://wa.me/${s.whatsapp.href}?text=${encodeURIComponent('Hello Alpha Adventist Pre & Primary School, I would like to enquire about the school.')}`;
  return `
<a class="skip-link" href="#main">Skip to main content</a>
<div class="topbar">
  <div class="container topbar__in">
    <ul class="topbar__list" aria-label="School contact information">
      <li>${icon('pin')}<span>${esc(s.locationText)}</span></li>
      <li><a href="tel:${esc(s.phones[0].href)}">${icon('phone')}<span>${esc(s.phones[0].number)}</span></a></li>
      ${s.emailPublished ? `<li class="topbar__hide-sm"><a href="mailto:${esc(s.email)}">${icon('mail')}<span>${esc(s.email)}</span></a></li>` : ''}
    </ul>
    <p class="topbar__motto">${esc(s.motto)} <span>•</span> ${esc(s.conference)}</p>
  </div>
</div>
<header class="site-head" id="top">
  <div class="container site-head__in">
    <a class="brand" href="/" aria-label="Alpha Adventist Pre & Primary School — Home">
      <img class="brand__logo" src="/img/logo-96.png" srcset="/img/logo-96.png 96w, /img/logo-160.png 160w" sizes="52px" alt="Alpha Adventist School crest with the motto Wisdom in Truth" width="52" height="51" decoding="async">
      <span class="brand__text">
        <strong>Alpha Adventist <span>Pre &amp; Primary School</span></strong>
        <small>${esc(s.affiliation)} • ${esc(s.conference)}</small>
      </span>
    </a>
    <nav class="mainnav" aria-label="Main navigation">
      <ul>${links}</ul>
    </nav>
    <div class="site-head__actions">
      <a class="btn btn--primary btn--sm" href="/admissions#apply">Apply for Admission</a>
      <button class="navtoggle" type="button" aria-expanded="false" aria-controls="mobileNav" data-navtoggle>
        ${icon('menu')}<span class="sr-only">Open menu</span>
      </button>
    </div>
  </div>
  <div class="mobilenav" id="mobileNav" hidden>
    <nav aria-label="Mobile navigation">
      <ul>${mlinks}</ul>
      <div class="mobilenav__ctas">
        <a class="btn btn--primary" href="/admissions#apply">Apply for Admission</a>
        <a class="btn btn--gold" href="/computer-learning#join">Join Computer Class</a>
        <a class="btn btn--ghost" href="/contact">Contact Us</a>
      </div>
    </nav>
  </div>
</header>
<div class="quickbar" role="navigation" aria-label="Quick contact actions">
  <a href="${esc(wa)}" target="_blank" rel="noopener">${icon('whatsapp')}<span>WhatsApp</span></a>
  <a href="tel:${esc(s.phones[0].href)}">${icon('phone')}<span>Call</span></a>
  <a href="/admissions#apply">${icon('pencil')}<span>Apply</span></a>
</div>`;
}

function footer(ctx) {
  const s = ctx.db.settings;
  const wa = `https://wa.me/${s.whatsapp.href}`;
  const year = new Date().getFullYear();
  const col = (title, items) => `<div class="footer__col"><h3>${esc(title)}</h3><ul>${items.map(i => `<li><a href="${i[1]}">${esc(i[0])}</a></li>`).join('')}</ul></div>`;
  return `
<footer class="footer">
  <div class="container footer__grid">
    <div class="footer__col footer__brand">
      <img src="/img/logo-160.png" alt="Alpha Adventist School crest" width="72" height="70" loading="lazy" decoding="async">
      <p class="footer__name">Alpha Adventist Pre &amp; Primary School</p>
      <p class="footer__aff">${esc(s.affiliation)}<br>${esc(s.conference)}</p>
      <p class="footer__desc">A holistic Christian learning community in Kigoma, Tanzania, nurturing knowledgeable, disciplined, creative and spiritually grounded learners from KG I to Standard VII — in day and boarding life.</p>
      <p class="footer__identity">${esc(s.identityLine)}</p>
    </div>
    ${col('Explore', [['Home', '/'], ['About Us', '/about'], ['Academics', '/academics'], ['Admissions', '/admissions'], ['News & Events', '/news'], ['Gallery', '/gallery'], ['Contact', '/contact']])}
    ${col('Learning', [['Computer Learning', '/computer-learning'], ['Faith & Spiritual Life', '/faith'], ['School Life', '/school-life'], ['Parent Corner', '/parents'], ['Alpha Kids Zone', '/students'], ['Book a School Visit', '/admissions#visit']])}
    <div class="footer__col">
      <h3>Contact</h3>
      <ul class="footer__contact">
        <li>${icon('pin')}<span>${esc(s.address.box)}, ${esc(s.address.city)}, ${esc(s.address.country)}<br>${esc(s.locationText)}</span></li>
        <li>${icon('phone')}<span><a href="tel:${esc(s.phones[0].href)}">${esc(s.phones[0].number)}</a><br><a href="tel:${esc(s.phones[1].href)}">${esc(s.phones[1].number)}</a></span></li>
        <li>${icon('whatsapp')}<span><a href="${esc(wa)}" target="_blank" rel="noopener">WhatsApp: ${esc(s.whatsapp.number)}</a></span></li>
        ${s.emailPublished ? `<li>${icon('mail')}<span><a href="mailto:${esc(s.email)}">${esc(s.email)}</a></span></li>` : ''}
      </ul>
      <p class="footer__social-note">${esc(s.socialNote)}</p>
    </div>
  </div>
  <div class="footer__bottom">
    <div class="container footer__bottom-in">
      <p>© ${year} Alpha Adventist Pre &amp; Primary School. All rights reserved.</p>
      <p class="footer__links"><a href="/privacy">Website Privacy &amp; Child Safeguarding Notice</a> <span>•</span> <a href="/admin">Staff sign in</a></p>
    </div>
  </div>
</footer>`;
}

/* ============ PAGE ============ */
function page(ctx, body, meta = {}) {
  const s = ctx.db.settings;
  const title = meta.title || `${s.schoolName} — ${s.affiliation}, Kigoma`;
  const desc = meta.desc || 'Alpha Adventist Pre & Primary School is a Seventh-day Adventist educational institution within the Western Tanzania Conference – Kigoma, offering holistic Pre-Primary and Primary education for day and boarding pupils.';
  const url = `https://alphaadventist.ac.tz${ctx.path}`;
  const jsonld = meta.jsonld ? `<script type="application/ld+json">${JSON.stringify(meta.jsonld)}</script>` : '';
  return `<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>${esc(title)}</title>
<meta name="description" content="${esc(desc)}">
<link rel="canonical" href="${esc(url)}">
<meta property="og:site_name" content="${esc(s.schoolName)}">
<meta property="og:type" content="${meta.ogType || 'website'}">
<meta property="og:title" content="${esc(title)}">
<meta property="og:description" content="${esc(desc)}">
<meta property="og:url" content="${esc(url)}">
<meta property="og:image" content="https://alphaadventist.ac.tz/img/og-image.jpg">
<meta property="og:image:width" content="1200"><meta property="og:image:height" content="630">
<meta property="og:image:alt" content="Alpha Adventist Pre & Primary School pupils at a school ceremony">
<meta name="twitter:card" content="summary_large_image">
<meta name="theme-color" content="#0A1E59">
<link rel="icon" type="image/png" sizes="32x32" href="/img/favicon-32.png">
<link rel="apple-touch-icon" href="/img/apple-touch-180.png">
<link rel="manifest" href="/site.webmanifest">
<link rel="preload" as="image" href="/img/${(ctx.db.hero[0] && ctx.db.hero[0].image) || 'choir-green'}-1600.webp" type="image/webp">
<link rel="stylesheet" href="/fonts/fonts.css">
<link rel="stylesheet" href="/css/main.css?v=2">
${jsonld}</head>
<body class="${meta.bodyClass || ''}">
${header(ctx)}
<main id="main">${body}</main>
${footer(ctx)}
<script src="/js/main.js" defer></script>
</body>
</html>`;
}

/* school structured data */
function schoolJsonLd(ctx) {
  const s = ctx.db.settings;
  return {
    '@context': 'https://schema.org',
    '@type': 'School',
    name: s.schoolName,
    alternateName: 'Alpha Adventist Primary School',
    description: 'A Seventh-day Adventist educational institution within the Western Tanzania Conference – Kigoma, offering Pre-Primary and Primary education (KG I – Standard VII) for day and boarding pupils.',
    email: s.emailPublished ? s.email : undefined,
    telephone: s.phones.map(p => p.number),
    address: {
      '@type': 'PostalAddress',
      postOfficeBoxNumber: s.address.box.replace('P.O. Box ', ''),
      addressLocality: s.address.city,
      addressCountry: 'TZ',
      streetAddress: s.locationText
    },
    parentOrganization: { '@type': 'Organization', name: 'Western Tanzania Conference of Seventh-day Adventists' },
    motto: s.motto,
    logo: 'https://alphaadventist.ac.tz/img/logo-256.png',
    image: 'https://alphaadventist.ac.tz/img/og-image.jpg'
  };
}

module.exports = { icon, pic, btn, sectionHead, header, footer, page, NAV, schoolJsonLd, ICONS: P };
