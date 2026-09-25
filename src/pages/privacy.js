'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon, sectionHead } = X;
const { phero } = require('./about');

module.exports = {
  render(ctx) {
    const body = `
${phero(ctx, 'Privacy', 'Website Privacy & Child Safeguarding Notice', 'How this website protects the information of pupils, families and staff — and how photographs are handled.', 'staff-group')}
<section class="sec">
  <div class="container article" style="max-width:860px">
    ${sectionHead('Our Commitment', 'Children First')}
    <p>Alpha Adventist Pre & Primary School operates this website as its official digital platform. Because the school serves children, privacy and safeguarding are designed into every part of the website.</p>
    <h3>Information we collect</h3>
    <p>Enquiry, admission, school-visit and computer-training forms collect only the details needed for the school office to respond: names, telephone or WhatsApp number, an optional email address, the level of interest and your message. This information is stored securely for the school office and is <strong>never published</strong> on the website or shared with third parties.</p>
    <h3>Information we never publish</h3>
    <ul class="checklist">
      <li>${icon('check')}<span>Private student records, individual academic results or medical information.</span></li>
      <li>${icon('check')}<span>Parent or guardian private information, including fee account details.</span></li>
      <li>${icon('check')}<span>Staff confidential records or internal board documents.</span></li>
      <li>${icon('check')}<span>Sensitive safeguarding information of any kind.</span></li>
    </ul>
    <h3>Photographs of children</h3>
    <p>Only approved school photographs are published on this website, following the school's approval and consent process. Captions describe activities, programmes and events — they do not identify individual pupils or attach personal information to children's images.</p>
    <h3>Website security</h3>
    <ul class="checklist">
      <li>${icon('check')}<span>HTTPS delivery, secure administrator authentication and role-based permissions.</span></li>
      <li>${icon('check')}<span>Form protection (validation, honeypot and rate limiting) and input sanitisation.</span></li>
      <li>${icon('check')}<span>CSRF protection on all administrative actions and secure session cookies.</span></li>
      <li>${icon('check')}<span>Regular backups and protection against unauthorised access to administrative areas.</span></li>
    </ul>
    <h3>Children's area (Alpha Kids Zone)</h3>
    <p>The Kids Zone contains no advertising, no external links for children to follow, and no collection of personal information. Games run entirely inside your browser.</p>
    <h3>Future portals</h3>
    <p>Planned Parent, Student and Teacher portals will hold individual records only behind secure authentication with role-based access. No such portal is active on this website today, and no login is offered until one is securely implemented.</p>
    <h3>Questions</h3>
    <p>For any question about privacy or photographs on this website, contact the school office through the <a href="/contact">contact page</a>.</p>
  </div>
</section>`;
    return {
      body,
      meta: {
        title: 'Website Privacy & Child Safeguarding Notice — Alpha Adventist Pre & Primary School',
        desc: 'How Alpha Adventist Pre & Primary School protects pupil, family and staff information on its official website, and how approved photographs of children are handled.'
      }
    };
  }
};
