'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc, paragraphs } = L;
const { icon, pic, btn, sectionHead } = X;

function phero(ctx, kicker, title, text, img) {
  return `
  <section class="phero" style="--ph-img:url('/img/${img}-1600.jpg')">
    <div class="container">
      <nav class="crumbs" aria-label="Breadcrumb"><a href="/">Home</a>${icon('chevron')}<span aria-current="page">${esc(kicker)}</span></nav>
      <h1>${esc(title)}</h1>
      <p>${esc(text)}</p>
    </div>
  </section>`;
}

module.exports = {
  phero,
  render(ctx) {
    const db = ctx.db, s = db.settings, lead = db.leadership;
    const body = `
${phero(ctx, 'About Us', 'About Alpha Adventist Pre & Primary School', 'A Seventh-day Adventist educational institution within the Western Tanzania Conference – Kigoma, committed to holistic education for good citizenry and eternity.', 'graduation-group')}

<section class="sec">
  <div class="container split">
    <div>
      ${sectionHead('Who We Are', 'Holistic Education in the Heart of Kigoma')}
      <p>Alpha Adventist Pre & Primary School is a Seventh-day Adventist educational institution within the Western Tanzania Conference – Kigoma. Guided by Christian principles, the school is committed to holistic education that supports the physical, mental, social and spiritual development of every learner.</p>
      <p>Through academic learning, faith, character development, technology, creativity, talent and responsible citizenship, Alpha seeks to prepare children for meaningful lives of learning, service and responsible participation in society.</p>
      <p>Alpha serves families through both <strong>day and boarding</strong> arrangements across <strong>KG I to Standard VII</strong>, within a supervised daily routine of lessons, worship and activities. The school operates under formal governance within the Western Tanzania Conference of the Seventh-day Adventist Church, with an active School Board and structured academic reporting.</p>
      <div class="note-strip" style="margin-top:18px">${icon('badge')}<span>In Tanzania's official 2026 Form One selection records, Alpha Adventist Primary School is registered under examination centre <strong>${esc(s.centreCode)}</strong> — a public confirmation of the school's place in the national education pathway.</span></div>
    </div>
    <div class="media-stack rv">
      <div class="media-stack__main">${pic('staff-group', 'Alpha Adventist staff group photograph in front of the school building', { widths: [800, 1200], ratio: '4/3' })}</div>
      <div class="media-stack__float">${pic('banner-conference', 'Official banner of the Western Tanzania Conference of Seventh-day Adventists with the Alpha school crest', { widths: [480, 800], ratio: '1000/182' })}</div>
    </div>
  </div>
</section>

<section class="sec sec--sand" id="statements">
  <div class="container">
    ${sectionHead('Official Institutional Statements', 'Mission • Vision • Philosophy', 'The following statements are Alpha\'s official institutional wording, preserved exactly as approved by the school.', { align: 'center' })}
    <div class="mvv">
      <div class="mvv__card mvv__card--mission rv"><span class="mvv__label">Our Mission</span><p class="mvv__quote">“${esc(db.statements.mission)}”</p><p class="mvv__note">In practice: academic learning joined with character, faith and service — preparing learners for responsible life and eternity.</p></div>
      <div class="mvv__card mvv__card--vision rv"><span class="mvv__label">Our Vision</span><p class="mvv__quote">“${esc(db.statements.vision)}”</p><p class="mvv__note">In practice: a school that seeks to model excellence in teaching, character and service — in Tanzania and beyond.</p></div>
      <div class="mvv__card mvv__card--phil rv"><span class="mvv__label">Our Philosophy</span><p class="mvv__quote">“${esc(db.statements.philosophy)}”</p><p class="mvv__note">In practice: harmonious development — body, mind, community and spirit — in every part of school life.</p></div>
    </div>
    <div class="grid grid--3" style="margin-top:26px">
      <div class="card rv" style="text-align:center"><div class="card__ico" style="margin-inline:auto">${icon('cross')}</div><h3>Christian Identity</h3><p>Worship, Bible learning and Christian service are woven into the school week, nurturing faith alongside academics.</p></div>
      <div class="card rv" style="text-align:center"><div class="card__ico" style="margin-inline:auto">${icon('users')}</div><h3>Formal Governance</h3><p>An active School Board oversees mission, academics, chaplaincy, professional growth, calendar and routines.</p></div>
      <div class="card rv" style="text-align:center"><div class="card__ico" style="margin-inline:auto">${icon('growth')}</div><h3>Continuous Development</h3><p>Academic monitoring, staff professional growth and progressive ICT development support continuous improvement.</p></div>
    </div>
  </div>
</section>

<section class="sec" id="head-of-school">
  <div class="container">
    ${sectionHead('Head of School Message', 'Welcome from the Head of School', '', { align: 'center' })}
    <div class="leader">
      <div class="leader__photo rv">
        ${lead.photo
          ? `<img src="${esc(lead.photo)}" alt="${esc(lead.name)}, ${esc(lead.title)}">`
          : `<div class="leader__placeholder">
               <img src="/img/logo-256.png" alt="" aria-hidden="true">
               <span class="lp-name">${esc(lead.name)}</span>
               <span class="lp-role">${esc(lead.title)}</span>
               <span class="lp-note">Official portrait to be published by the school office</span>
             </div>`}
      </div>
      <div class="article" style="margin:0">
        ${paragraphs(lead.fullMessage)}
        <p class="leader__quote">${esc(lead.signature)}</p>
        <p><strong class="leader__name">${esc(lead.name)}</strong><br><span class="leader__role">${esc(lead.title)}, Alpha Adventist Pre &amp; Primary School</span></p>
      </div>
    </div>
  </div>
</section>

<section class="sec sec--sand">
  <div class="container">
    ${sectionHead('Development of the Whole Learner', 'What Holistic Education Means at Alpha', '', { align: 'center' })}
    <div class="grid grid--4">
      <div class="card rv"><div class="card__ico">${icon('book')}</div><h3>Academic</h3><p>Strong foundations, assessment, teacher guidance and academic monitoring from KG I to Standard VII.</p></div>
      <div class="card rv"><div class="card__ico">${icon('flame')}</div><h3>Spiritual</h3><p>Worship, prayer, Bible learning and Christian character formation.</p></div>
      <div class="card rv"><div class="card__ico">${icon('ball')}</div><h3>Physical</h3><p>Physical development and practical activity within the school's holistic philosophy.</p></div>
      <div class="card rv"><div class="card__ico">${icon('heart')}</div><h3>Social</h3><p>Positive relationships, teamwork, service and responsible citizenship.</p></div>
      <div class="card rv"><div class="card__ico">${icon('palette')}</div><h3>Creative</h3><p>Choir, academic exhibits and creative projects that let abilities shine.</p></div>
      <div class="card rv"><div class="card__ico">${icon('shield')}</div><h3>Moral</h3><p>Integrity, discipline, respect and responsibility practised daily.</p></div>
      <div class="card rv"><div class="card__ico">${icon('mic')}</div><h3>Leadership</h3><p>Opportunities to lead, present and participate across school programmes.</p></div>
      <div class="card rv"><div class="card__ico">${icon('chip')}</div><h3>Digital</h3><p>Confident, creative, responsible and safe use of technology.</p></div>
    </div>
    <div class="ctaband rv" style="margin-top:34px">
      <h2>Discover Alpha for Yourself</h2>
      <p>The best way to understand Alpha is to visit. Meet the team, see the classrooms, the Computer Lab and the school community in person.</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        ${btn('/admissions#visit', 'Book a School Visit', 'gold')}
        ${btn('/admissions#apply', 'Apply for Admission', 'outline-light')}
        ${btn('/gallery', 'View Gallery', 'outline-light', 'camera')}
      </div>
    </div>
  </div>
</section>`;
    return {
      body,
      meta: {
        title: 'About Us — Alpha Adventist Pre & Primary School, Kigoma',
        desc: 'Learn about Alpha Adventist Pre & Primary School: a Seventh-day Adventist educational institution in Kigoma, Tanzania — our official mission, vision, philosophy, leadership and holistic education from KG I to Standard VII.'
      }
    };
  }
};
