'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon, pic, btn, sectionHead } = X;
const { phero } = require('./about');

module.exports = {
  render(ctx) {
    const body = `
${phero(ctx, 'Faith & Spiritual Life', 'Faith & Spiritual Life at Alpha', 'As a Seventh-day Adventist educational institution, Alpha integrates worship, Bible learning and Christian service into the rhythm of school life.', 'choir-green')}

<section class="sec sec--navy">
  <div class="container">
    ${sectionHead('Spiritual Development', 'Growing in Faith and Character', 'Spiritual development is one of the strongest documented aspects of Alpha school life. The School Board agenda specifically identifies special worship services and school choir programmes — faith at Alpha is lived, not only taught.', { align: 'center' })}
    <div class="grid grid--3">
      <div class="techcard rv"><h3>${icon('cross')} Worship</h3><p>Special worship services and school worship programmes gather pupils and staff to praise, reflect and grow together.</p></div>
      <div class="techcard rv"><h3>${icon('book')} Bible Learning</h3><p>Bible-based learning helps pupils understand Christian teaching and apply it to daily choices and relationships.</p></div>
      <div class="techcard rv"><h3>${icon('flame')} Prayer</h3><p>Prayer opens and closes moments of school life, teaching pupils to bring their joys, concerns and gratitude to God.</p></div>
      <div class="techcard rv"><h3>${icon('heart')} Christian Service</h3><p>Service activities and Pathfinder participation encourage learners to use their abilities to help others.</p></div>
      <div class="techcard rv"><h3>${icon('music')} Choir</h3><p>The school choir expresses faith and talent through music, performing at school programmes and ceremonies.</p></div>
      <div class="techcard rv"><h3>${icon('shield')} Character Formation</h3><p>Faith shapes character: integrity, discipline, respect, responsibility, compassion, service and good citizenship.</p></div>
    </div>
  </div>
</section>

<section class="sec" id="character">
  <div class="container split">
    <div>
      ${sectionHead('Christian Principles in Practice', 'Values That Guide Our Community')}
      <p>Christian principles at Alpha influence every part of school life — how pupils speak, work, play and serve. These are the values the school seeks to nurture each day:</p>
      <div class="grid grid--2" style="gap:12px">
        <div class="card rv" style="padding:18px"><h4 style="margin:0 0 4px">Integrity</h4><p style="margin:0;font-size:.88rem">Honesty and doing what is right, even when no one is watching.</p></div>
        <div class="card rv" style="padding:18px"><h4 style="margin:0 0 4px">Discipline</h4><p style="margin:0;font-size:.88rem">Self-control, order and respect for school expectations.</p></div>
        <div class="card rv" style="padding:18px"><h4 style="margin:0 0 4px">Respect</h4><p style="margin:0;font-size:.88rem">Valuing every member of the school community.</p></div>
        <div class="card rv" style="padding:18px"><h4 style="margin:0 0 4px">Responsibility</h4><p style="margin:0;font-size:.88rem">Owning our actions, duties and contributions.</p></div>
        <div class="card rv" style="padding:18px"><h4 style="margin:0 0 4px">Compassion</h4><p style="margin:0;font-size:.88rem">Kindness and care for one another.</p></div>
        <div class="card rv" style="padding:18px"><h4 style="margin:0 0 4px">Service</h4><p style="margin:0;font-size:.88rem">Using knowledge and talents to help others.</p></div>
        <div class="card rv" style="padding:18px"><h4 style="margin:0 0 4px">Good Citizenship</h4><p style="margin:0;font-size:.88rem">Contributing positively to society — for this life and eternity.</p></div>
      </div>
    </div>
    <div class="media-stack rv">
      <div class="media-stack__main">${pic('choir-green', 'Alpha pupils singing during a worship programme on the school steps', { widths: [800, 1200], ratio: '4/3' })}</div>
      <div class="media-stack__float">${pic('choir-teal', 'The school choir performing during the graduation ceremony', { widths: [480, 800], ratio: '4/3' })}</div>
      <div class="media-stack__badge"><strong>Wisdom</strong>in Truth — the Alpha motto</div>
    </div>
  </div>
</section>

<section class="sec sec--sand">
  <div class="container">
    <div class="ctaband rv">
      <span class="kicker">A Welcoming Community</span>
      <h2>Faith, Learning and Family — Together</h2>
      <p>Alpha welcomes enquiries from all families who value Christian character and holistic education. Discover how faith, academics and talent work together at Alpha.</p>
      <div style="display:flex;gap:12px;flex-wrap:wrap">
        ${btn('/about', 'About Alpha', 'gold')}
        ${btn('/school-life', 'School Life', 'outline-light')}
        ${btn('/admissions#visit', 'Book a School Visit', 'outline-light')}
      </div>
    </div>
  </div>
</section>`;
    return {
      body,
      meta: {
        title: 'Faith & Spiritual Life — Alpha Adventist Pre & Primary School, Kigoma',
        desc: 'Spiritual life at Alpha Adventist Pre & Primary School: worship, Bible learning, prayer, Christian service, school choir and character formation within a Seventh-day Adventist educational institution in Kigoma.'
      }
    };
  }
};
