'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon, pic, btn, sectionHead } = X;
const { phero } = require('./about');

module.exports = {
  render(ctx) {
    const body = `
${phero(ctx, 'School Life', 'Life at Alpha', 'Learning, faith, friendship and discovery — school life at Alpha extends far beyond classroom examinations.', 'choir-teal')}

<section class="sec">
  <div class="container">
    ${sectionHead('Documented School Activities', 'What Pupils Experience at Alpha', 'Alpha\'s own programmes and School Board documentation record these areas of school life — worship, choir, Pathfinder, ICT and academic exhibitions — alongside practical activities and community service.')}
    <div class="life-grid">
      <div class="life-card rv" id="worship"><span class="life-card__tag">Spiritual Life</span>${pic('choir-green', 'Pupils singing with raised hands during worship', { widths: [480, 800], sizes: '(max-width: 980px) 92vw, 30vw' })}<span class="life-card__in"><h3>Worship &amp; Spiritual Life</h3><p>Special worship services, prayer and Bible-based learning nurture faith and character.</p></span></div>
      <div class="life-card rv" id="music"><span class="life-card__tag">Music & Choir</span>${pic('choir-teal', 'Alpha choir performing on stage', { widths: [480, 800], sizes: '(max-width: 980px) 92vw, 30vw' })}<span class="life-card__in"><h3>School Choir</h3><p>Choir programmes documented in School Board agenda and graduation performances.</p></span></div>
      <div class="life-card rv" id="pathfinder"><span class="life-card__tag">Pathfinder</span>${pic('graduation-group', 'School community and Pathfinder participation at graduation', { widths: [480, 800], sizes: '(max-width: 980px) 92vw, 30vw' })}<span class="life-card__in"><h3>Pathfinder &amp; Character Activities</h3><p>Responsibility, service and teamwork through documented Pathfinder participation.</p></span></div>
      <div class="life-card rv" id="ict"><span class="life-card__tag">ICT & Digital</span>${pic('exhibit-teacher', 'Hands-on practical learning guided by a teacher', { widths: [480, 800], sizes: '(max-width: 980px) 92vw, 30vw' })}<span class="life-card__in"><h3>ICT &amp; Computer Lab</h3><p>Hands-on digital learning — clear, simple, practical.</p></span></div>
      <div class="life-card rv" id="exhibits"><span class="life-card__tag">Academic Exhibitions</span>${pic('exhibit-pupils', 'Pupils presenting academic exhibits', { widths: [480, 800], sizes: '(max-width: 980px) 92vw, 30vw' })}<span class="life-card__in"><h3>Academic Exhibits</h3><p>Projects, quizzes and demonstrations that showcase learning.</p></span></div>
      <div class="life-card rv" id="service"><span class="life-card__tag">Service & Responsibility</span>${pic('garden-project', 'Pupils watering the school vegetable garden', { widths: [480, 800], sizes: '(max-width: 980px) 92vw, 30vw' })}<span class="life-card__in"><h3>Practical Service</h3><p>Working together on practical projects that build responsibility and care for creation.</p></span></div>
    </div>
    <div class="note-strip" style="margin-top:26px">${icon('star')}<span>Additional activities such as drama, poetry, art clubs and specific sports teams are added to this page as the school confirms active programmes — keeping everything you see here truthful to Alpha.</span></div>
  </div>
</section>

<section class="sec sec--sand" id="talent">
  <div class="container">
    ${sectionHead('Talent & Innovation', 'Every Child Has a Talent Worth Developing', 'At Alpha, education should provide opportunities for children to discover, develop and responsibly express their abilities. Our talent programme celebrates pupil participation, creativity, academic work and developing abilities across school life.', { align: 'center' })}
    <div class="tiles">
      <div class="tile rv">${icon('music')}<strong>Music &amp; Choir</strong><span>Confirmed school programme</span></div>
      <div class="tile rv">${icon('shield')}<strong>Pathfinder Activities</strong><span>Confirmed school programme</span></div>
      <div class="tile rv">${icon('flask')}<strong>Academic Exhibitions</strong><span>Confirmed school programme</span></div>
      <div class="tile rv">${icon('chip')}<strong>ICT Learning</strong><span>Confirmed school programme</span></div>
      <div class="tile rv">${icon('badge')}<strong>Pupil Achievement</strong><span>Certificates &amp; recognition</span></div>
      <div class="tile rv">${icon('mic')}<strong>Communication</strong><span>Presenting &amp; participation</span></div>
    </div>
    <p class="text-center muted" style="margin-top:18px;font-size:.9rem">Real work. Real learners. Real achievement.</p>
  </div>
</section>

<section class="sec">
  <div class="container split">
    <div class="media-stack rv">
      <div class="media-stack__main">${pic('graduation-certificate', 'A pupil receiving a certificate on stage', { widths: [800, 1200, 1600], ratio: '4/3' })}</div>
      <div class="media-stack__float">${pic('choir-green', 'Pupils singing on the school steps', { widths: [480, 800], ratio: '4/3' })}</div>
    </div>
    <div>
      ${sectionHead('Leadership & Responsibility', 'Growing Citizens of Character')}
      <p>School programmes give pupils occasions to lead, present and serve — from choir and Pathfinder activities to academic exhibits and ceremony participation. Alpha seeks to develop learners who are disciplined, respectful, responsible and ready to contribute positively to society.</p>
      <ul class="checklist">
        <li>${icon('check')}<span>Participation in school programmes and ceremonies builds confidence.</span></li>
        <li>${icon('check')}<span>Pathfinder and service activities teach teamwork and responsibility.</span></li>
        <li>${icon('check')}<span>Positive relationships with teachers and peers shape character daily.</span></li>
      </ul>
      <p>${btn('/gallery', 'See School Life in Pictures', 'primary', 'camera')} ${btn('/faith', 'Faith & Spiritual Life', 'ghost')}</p>
    </div>
  </div>
</section>`;
    return {
      body,
      meta: {
        title: 'School Life — Alpha Adventist Pre & Primary School, Kigoma',
        desc: 'Life at Alpha Adventist Pre & Primary School: worship, school choir, Pathfinder, ICT and Computer Lab activities, academic exhibitions, practical service and talent development in Kigoma, Tanzania.'
      }
    };
  }
};
