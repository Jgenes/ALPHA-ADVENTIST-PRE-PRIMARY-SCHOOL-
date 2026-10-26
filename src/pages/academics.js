'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon, pic, btn, sectionHead } = X;
const { phero } = require('./about');

module.exports = {
  render(ctx) {
    const db = ctx.db;
    const body = `
${phero(ctx, 'Academics', 'Our Academic Programme', 'Learning with Purpose. Growing with Confidence. Preparing for the Future.', 'exhibit-pupils')}

<section class="sec">
  <div class="container">
    ${sectionHead('Levels of Learning', 'A Clear Pathway from KG I to Standard VII', 'Alpha\'s registration records span Pre-Primary and Primary levels, with day and boarding enrolment categories. Each level builds on the last within the school\'s timetabled daily routine.')}
    <div class="levels">
      <div class="level-box rv">
        <h3>${icon('sun')} Pre-Primary</h3>
        <p class="muted" style="font-size:.94rem">Early years learning that develops language, numeracy, social skills, discipline and curiosity — in a warm, supervised and faith-filled environment where children feel safe to explore.</p>
        <ul class="level-chips">${db.academics.prePrimary.map(l => `<li>${esc(l)}</li>`).join('')}</ul>
      </div>
      <div class="level-box rv">
        <h3>${icon('book')} Primary</h3>
        <p class="muted" style="font-size:.94rem">Progressive primary education through the national pathway. Pupils develop knowledge, confidence, discipline and curiosity, preparing for national examinations and Form One selection.</p>
        <ul class="level-chips">${db.academics.primary.map(l => `<li>${esc(l)}</li>`).join('')}</ul>
      </div>
    </div>
    <div class="grid grid--2" style="margin-top:24px">
      ${db.academics.arrangements.map((a, i) => `
      <div class="card rv" style="display:flex;gap:18px;align-items:flex-start">
        <div class="card__ico" style="margin:0">${icon(i === 0 ? 'home' : 'bed')}</div>
        <div><h3>${esc(a.title)}</h3><p>${esc(a.desc)}</p></div>
      </div>`).join('')}
    </div>
  </div>
</section>

<section class="sec sec--sand">
  <div class="container">
    ${sectionHead('How Learning Happens', 'Teaching, Guidance and Monitoring', 'Alpha\'s documented governance shows formal attention to academics — timetables, daily routines, academic reporting and programme monitoring.', { align: 'center' })}
    <div class="grid grid--3">
      ${db.academics.features.map(f => `
      <div class="card rv"><div class="card__ico">${icon(f.icon)}</div><h3>${esc(f.title)}</h3><p>${esc(f.desc)}</p></div>`).join('')}
    </div>
    <div class="split" style="margin-top:clamp(30px,5vw,60px)">
      <div class="media-stack rv">
        <div class="media-stack__main">${pic('exhibit-pupils', 'Alpha pupils presenting academic exhibits at the 9th Graduation Ceremony', { widths: [800, 1200, 1600], ratio: '4/3' })}</div>
        <div class="media-stack__float">${pic('exhibit-teacher', 'A teacher guiding pupils during a practical demonstration', { widths: [480, 800], ratio: '4/3' })}</div>
      </div>
      <div>
        <h3>Learning by Thinking, Doing and Participating</h3>
        <p>Alpha's graduation programmes include academic exhibits and practical demonstrations — moments where pupils present what they have learned to parents, guests and judges. These activities bring classroom learning into the open and build confidence, communication and pride in work well done.</p>
        <p>Computer Lab activities form part of the school's documented activity locations, connecting classroom subjects with practical digital skills.</p>
        <ul class="checklist">
          <li>${icon('check')}<span>Classroom lessons within a structured timetable and daily routine.</span></li>
          <li>${icon('check')}<span>Assessment and academic reporting that inform teaching and support.</span></li>
          <li>${icon('check')}<span>Practical activities, exhibits and demonstrations across the school year.</span></li>
          <li>${icon('check')}<span>Teacher development through School Board professional growth agenda.</span></li>
        </ul>
        <p>${btn('/admissions', 'Enquire About a Place', 'primary', 'arrow')}</p>
      </div>
    </div>
  </div>
</section>

<section class="sec">
  <div class="container">
    <div class="ctaband rv">
      <span class="kicker">Academics at Alpha</span>
      <h2>Ready to See a Lesson in Action?</h2>
      <p>Book a school visit to observe classrooms, meet teachers and understand how Alpha's academic programme supports your child — from KG I to Standard VII.</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        ${btn('/admissions#visit', 'Book a School Visit', 'gold')}
        ${btn('/computer-learning', 'Computer Learning', 'outline-light', 'laptop')}
      </div>
    </div>
  </div>
</section>`;
    return {
      body,
      meta: {
        title: 'Academics — Pre-Primary & Primary Programmes | Alpha Adventist Pre & Primary School Kigoma',
        desc: 'Alpha Adventist Pre & Primary School offers Pre-Primary (KG I–KG II) and Primary (Standard I–VII) education in Kigoma, Tanzania, with day and boarding arrangements, assessment, teacher guidance and practical learning activities.'
      }
    };
  }
};
