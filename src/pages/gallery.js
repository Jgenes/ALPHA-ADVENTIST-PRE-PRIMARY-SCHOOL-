'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon, pic, sectionHead } = X;
const { phero } = require('./about');

const CATS = ['Campus', 'Classrooms', 'Computer Learning', 'Sports', 'Music & Choir', 'Worship', 'Talent', 'Graduation', 'School Events', 'School Life'];

module.exports = {
  render(ctx) {
    const db = ctx.db;
    const used = CATS.filter(c => db.gallery.some(g => g.cat === c));
    const body = `
${phero(ctx, 'Gallery', 'Alpha in Pictures', 'Approved photographs from ceremonies, classrooms, choir, campus and daily life at Alpha Adventist Pre & Primary School.', 'choir-teal')}
<section class="sec">
  <div class="container">
    ${db.gallery.length ? '' : '<div class="adsp-empty"><span>♡</span><h3>School moments, shared with care.</h3><p>Photographs appear here only after current guardian consent and school approval. You can request a withdrawal through our <a href="/privacy">privacy page</a>.</p></div>'}
    <div class="gal-filters" role="group" aria-label="Filter gallery by category">
      <button type="button" data-gal-filter="all" aria-pressed="true">All photographs</button>
      ${used.map(c => `<button type="button" data-gal-filter="${esc(c)}" aria-pressed="false">${esc(c)}</button>`).join('')}
    </div>
    <div class="gal-grid" data-gallery>
      ${db.gallery.map(g => `
      <button class="gal-item" type="button" data-cat="${esc(g.cat)}" data-caption="${esc(g.caption)}" aria-label="View photograph: ${esc(g.caption)}">
        ${pic(g.img, g.alt, { widths: [480, 800, 1200], sizes: '(max-width: 600px) 92vw, (max-width: 980px) 46vw, 31vw' })}
        <figcaption>${esc(g.caption)}</figcaption>
      </button>`).join('')}
    </div>
    <p class="note-strip" style="margin-top:30px">${icon('lock')}<span><strong>Child privacy:</strong> only approved school photographs are published on this website. Captions describe activities and never identify individual pupils. Media is removed from public access when consent expires or is withdrawn.</span></p>
  </div>
</section>
<div class="lightbox" id="lightbox" role="dialog" aria-modal="true" aria-label="Photograph viewer">
  <button class="lightbox__btn lightbox__close" type="button" aria-label="Close viewer">${icon('close')}</button>
  <button class="lightbox__btn lightbox__prev" type="button" aria-label="Previous photograph">${icon('arrow', 'flip')}</button>
  <img src="" alt="">
  <button class="lightbox__btn lightbox__next" type="button" aria-label="Next photograph">${icon('arrow')}</button>
  <p class="lightbox__cap"></p>
</div>`;
    return {
      body,
      meta: {
        title: 'Gallery — Alpha Adventist Pre & Primary School, Kigoma',
        desc: 'Photograph gallery of Alpha Adventist Pre & Primary School, Kigoma: graduation ceremonies, school choir, campus, academic exhibitions and school life.'
      }
    };
  }
};
