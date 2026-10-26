'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon, pic, sectionHead } = X;
const { phero } = require('./about');

const CATS = ['Campus', 'Classrooms', 'Computer Learning', 'Sports', 'Music & Choir', 'Worship', 'Talent', 'Graduation', 'School Events', 'School Life'];
const SCHOOL_PHOTOS = [
  { image: 'campus', cat: 'Campus', caption: 'Alpha school campus in Kigoma', alt: 'School buildings and courtyard at Alpha' },
  { image: 'gate', cat: 'Campus', caption: 'The school entrance', alt: 'Entrance gate and grounds at Alpha' },
  { image: 'classroom', cat: 'Classrooms', caption: 'Learning in the classroom', alt: 'Pupils taking part in a classroom lesson' },
  { image: 'exhibit-pupils', cat: 'School Life', caption: 'Pupils sharing a school project', alt: 'Pupils presenting a project at a school event' },
  { image: 'garden-project', cat: 'Talent', caption: 'Learning through the school garden', alt: 'Pupils caring for plants in the school garden' },
  { image: 'choir-green', cat: 'Worship', caption: 'Worship through music', alt: 'Pupils singing during a school worship programme' },
  { image: 'choir-teal', cat: 'Music & Choir', caption: 'The school choir performing', alt: 'School choir performing at a school event' },
  { image: 'sports', cat: 'School Life', caption: 'Pupils sharing a school event', alt: 'Pupils standing together during a school event' },
  { image: 'graduation-group', cat: 'Graduation', caption: 'Graduation day at Alpha', alt: 'Pupils and school community at a graduation ceremony' },
  { image: 'graduation-certificate', cat: 'School Events', caption: 'Celebrating pupil achievement', alt: 'A pupil receiving a certificate at a school ceremony' },
  { image: 'staff-group', cat: 'School Life', caption: 'School leadership and staff', alt: 'School leaders and staff at Alpha' },
  { image: 'school-community', cat: 'School Life', caption: 'The Alpha school community', alt: 'Pupils gathered for a school community photograph' }
];

module.exports = {
  render(ctx) {
    const db = ctx.db;
    const photos = [...SCHOOL_PHOTOS, ...db.gallery];
    const used = CATS.filter(c => photos.some(g => g.cat === c));
    const body = `
${phero(ctx, 'Gallery', 'Alpha in Pictures', 'Approved photographs from ceremonies, classrooms, choir, campus and daily life at Alpha Adventist Pre & Primary School.', 'choir-teal')}
<section class="sec">
  <div class="container">
    <p class="note-strip" style="margin-bottom:24px">${icon('lock')}<span>Photographs are published with school approval. Captions do not identify individual pupils. Request a withdrawal through our <a href="/privacy">privacy page</a>.</span></p>
    <div class="gal-filters" role="group" aria-label="Filter gallery by category">
      <button type="button" data-gal-filter="all" aria-pressed="true">All photographs</button>
      ${used.map(c => `<button type="button" data-gal-filter="${esc(c)}" aria-pressed="false">${esc(c)}</button>`).join('')}
    </div>
    <div class="gal-grid" data-gallery>
      ${photos.map(g => `
      <button class="gal-item" type="button" data-cat="${esc(g.cat)}" data-caption="${esc(g.caption)}" aria-label="View photograph: ${esc(g.caption)}">
        ${pic(g.image || g.img, g.alt, { widths: [480, 800, 1200], sizes: '(max-width: 600px) 92vw, (max-width: 980px) 46vw, 31vw' })}
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
