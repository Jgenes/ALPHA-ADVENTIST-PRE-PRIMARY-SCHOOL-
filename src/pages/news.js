'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc, paragraphs } = L;
const { icon, pic, btn, sectionHead } = X;
const { phero } = require('./about');

const CATS = ['School News', 'Academic News', 'Sports', 'Spiritual Events', 'ICT & Technology', 'Graduation', 'Announcements', 'Student Activities', 'Community Activities'];

function list(ctx) {
  const db = ctx.db;
  const body = `
${phero(ctx, 'News & Events', 'News & Events at Alpha', 'Official school news, celebrations, academic updates and announcements from the school office.', 'graduation-group')}
<section class="sec">
  <div class="container">
    <div class="gal-filters" role="group" aria-label="Filter news by category">
      <button type="button" data-news-filter="all" aria-pressed="true">All</button>
      ${CATS.filter(c => db.news.some(n => n.category === c)).map(c => `<button type="button" data-news-filter="${esc(c)}" aria-pressed="false">${esc(c)}</button>`).join('')}
    </div>
    <div class="grid grid--3" data-news-grid>
      ${db.news.map(n => `
      <article class="news-card rv" data-cat="${esc(n.category)}">
        <div class="news-card__media">${pic(n.image, n.alt || n.title, { widths: [480, 800], sizes: '(max-width: 900px) 92vw, 30vw' })}</div>
        <div class="news-card__in">
          <div class="news-meta"><span class="news-cat">${esc(n.category)}</span><span>${esc(n.dateLabel)}</span></div>
          <h3><a href="/news/${esc(n.slug)}">${esc(n.title)}</a></h3>
          <p>${esc(n.excerpt)}</p>
          <span class="link-more" style="margin-top:auto">Read article ${icon('arrow')}</span>
        </div>
      </article>`).join('')}
    </div>
    <div class="sec-head" style="margin-top:clamp(40px,6vw,70px)">
      <span class="kicker">Events</span>
      <h2>Upcoming Events</h2>
    </div>
    ${db.events.length ? db.events.map(e => `<div class="announce"><div class="news-meta"><span class="news-cat">${esc(e.category || 'Event')}</span><span>${esc(e.dateLabel)}</span></div><h4>${esc(e.title)}</h4><p>${esc(e.body)}</p></div>`).join('') : `
    <div class="note-strip">${icon('calendar')}<span>Approved upcoming events from the school calendar will be published here once released by the school office. For current term dates and events, please contact the school office.</span></div>`}
  </div>
</section>`;
  return { body, meta: { title: 'News & Events — Alpha Adventist Pre & Primary School, Kigoma', desc: 'Official news and events from Alpha Adventist Pre & Primary School in Kigoma: graduation, academic news, spiritual events, ICT updates and school announcements.' } };
}

function article(ctx, slug) {
  const db = ctx.db;
  const n = db.news.find(a => a.slug === slug);
  if (!n) return null;
  const related = db.news.filter(a => a !== n && a.category === n.category).concat(db.news.filter(a => a !== n && a.category !== n.category)).slice(0, 2);
  const shareText = encodeURIComponent(n.title + ' — Alpha Adventist Pre & Primary School');
  const shareUrl = encodeURIComponent(X.baseOf(ctx) + '/news/' + n.slug);
  const waShare = `https://wa.me/?text=${shareText}%20${shareUrl}`;
  const body = `
${phero(ctx, 'News & Events', n.title, n.excerpt, n.image)}
<section class="sec">
  <div class="container">
    <article class="article">
      <div class="news-meta" style="margin-bottom:18px"><span class="news-cat">${esc(n.category)}</span><span>${esc(n.dateLabel)}</span><span>By ${esc(n.author)}</span></div>
      <figure>
        ${pic(n.image, n.alt || n.title, { widths: [800, 1200, 1600], sizes: '(max-width: 820px) 92vw, 820px', eager: true })}
        <figcaption>${esc(n.alt || n.title)} — Alpha Adventist Pre &amp; Primary School</figcaption>
      </figure>
      <p class="article__lead">${esc(n.excerpt)}</p>
      ${paragraphs(n.content)}
      ${n.gallery && n.gallery.length > 1 ? `
      <h3 style="margin-top:30px">From this event</h3>
      <div class="gal-grid" style="columns:2">
        ${n.gallery.map(g => {
          const item = db.gallery.find(x => x.img === g);
          return `<figure class="gal-item" style="cursor:default">${pic(g, (item && item.alt) || n.title, { widths: [480, 800], sizes: '45vw' })}<figcaption>${esc((item && item.caption) || n.title)}</figcaption></figure>`;
        }).join('')}
      </div>` : ''}
      <div class="share">
        <span class="muted" style="font-size:.85rem;font-weight:700">Share:</span>
        <a href="${waShare}" target="_blank" rel="noopener">${icon('whatsapp')} WhatsApp</a>
        <a href="https://www.facebook.com/sharer/sharer.php?u=${shareUrl}" target="_blank" rel="noopener">${icon('share')} Facebook</a>
        <a href="#" data-copy-link>${icon('link')} Copy link</a>
      </div>
    </article>
    ${related.length ? `
    <div style="margin-top:clamp(40px,6vw,70px)">
      ${sectionHead('Related Articles', 'More from Alpha')}
      <div class="grid grid--2">
        ${related.map(r => `
        <article class="news-card" style="flex-direction:row">
          <div class="news-card__media" style="aspect-ratio:auto;width:36%;flex:none;min-height:140px">${pic(r.image, r.alt || r.title, { widths: [480, 800], sizes: '30vw' })}</div>
          <div class="news-card__in">
            <div class="news-meta"><span class="news-cat">${esc(r.category)}</span><span>${esc(r.dateLabel)}</span></div>
            <h3><a href="/news/${esc(r.slug)}">${esc(r.title)}</a></h3>
          </div>
        </article>`).join('')}
      </div>
    </div>` : ''}
  </div>
</section>`;
  return {
    body,
    meta: {
      title: `${n.title} — Alpha Adventist Pre & Primary School`,
      desc: n.excerpt,
      ogType: 'article',
      jsonld: {
        '@context': 'https://schema.org', '@type': 'NewsArticle',
        headline: n.title, description: n.excerpt, author: { '@type': 'Organization', name: n.author },
        datePublished: n.sortDate || undefined,
        image: X.baseOf(ctx) + (n.mediaId ? '/media/' + n.mediaId : '/img/og-image.png'),
        publisher: { '@type': 'Organization', name: 'Alpha Adventist Pre & Primary School' }
      }
    }
  };
}

module.exports = { list, article, CATS };
