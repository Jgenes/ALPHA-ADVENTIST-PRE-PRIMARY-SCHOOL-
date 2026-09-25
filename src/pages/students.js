'use strict';
const X = require('../layout');
const L = require('../lib');
const { esc } = L;
const { icon, pic, btn, sectionHead } = X;

module.exports = {
  render(ctx) {
    const body = `
<section class="kids" style="padding:clamp(48px,7vw,88px) 0 clamp(48px,7vw,88px)">
  <div class="container">
    <div class="sec-head sec-head--center">
      <span class="kicker">Alpha Kids Zone</span>
      <h1 style="font-size:clamp(2rem,4.4vw,3.2rem)">Hello, Alpha Pupil! 👋</h1>
      <p class="sec-head__text">This is your corner of the school website — learn, explore, practise and create. Play the games below, revise your computer lessons and learn how to stay safe online.</p>
    </div>
    <div class="kid-grid">
      <a class="kid-card" href="#typing"><span class="kid-card__ico kc-1">${icon('keyboard')}</span><strong>Typing Game</strong><span>How fast can you type Alpha words?</span></a>
      <a class="kid-card" href="#maths"><span class="kid-card__ico kc-2">${icon('sparkle')}</span><strong>Maths Challenge</strong><span>Practise addition, subtraction &amp; multiplication</span></a>
      <a class="kid-card" href="#computer"><span class="kid-card__ico kc-3">${icon('laptop')}</span><strong>Computer Lessons</strong><span>Revise what you learn in the Computer Lab</span></a>
      <a class="kid-card" href="#safety"><span class="kid-card__ico kc-4">${icon('shield')}</span><strong>Internet Safety</strong><span>Be smart and safe online</span></a>
    </div>
  </div>
</section>

<section class="sec" id="typing" style="background:#fff">
  <div class="container grid grid--2">
    <div class="game" data-typing>
      <h3>${icon('keyboard')} Typing Game</h3>
      <p class="muted" style="font-size:.9rem">Type the word exactly as you see it, then keep going! Each correct word scores one point.</p>
      <div class="game__word" data-word>alpha</div>
      <div class="game__stats"><span>Score: <b data-score>0</b></span><span>Best: <b data-best>0</b></span></div>
      <label class="sr-only" for="typing-input">Type the word shown</label>
      <input id="typing-input" autocomplete="off" autocapitalize="off" spellcheck="false" placeholder="Type the word here…">
      <p class="game__msg" data-msg></p>
    </div>
    <div class="game" data-quiz id="maths">
      <h3>${icon('sparkle')} Maths Challenge</h3>
      <p class="muted" style="font-size:.9rem">Choose your level, then type the answer. Correct answers score a point!</p>
      <div class="field" style="margin-bottom:14px">
        <label for="quiz-level">My level</label>
        <select id="quiz-level" data-level>
          <option value="1">Junior (KG – Std II): numbers to 10</option>
          <option value="2">Middle (Std III – V): numbers to 30</option>
          <option value="3">Senior (Std VI – VII): multiplication tables</option>
        </select>
      </div>
      <div class="quiz__q" data-q>2 + 2 = ?</div>
      <div class="game__stats"><span>Score: <b data-score>0</b></span></div>
      <label class="sr-only" for="quiz-input">Type your answer</label>
      <input id="quiz-input" inputmode="numeric" autocomplete="off" placeholder="Your answer…">
      <p class="game__msg" data-msg></p>
    </div>
  </div>
</section>

<section class="sec sec--sand" id="computer">
  <div class="container">
    ${sectionHead('Revise Your Computer Lessons', 'What Do You Remember?', 'These are the ideas from Alpha\'s ICT lessons — clear, simple and practical. Revise them here, then practise in the Computer Lab!', { align: 'center' })}
    <div class="grid grid--3">
      <div class="card rv"><div class="card__ico">${icon('laptop')}</div><h3>Hardware &amp; Software</h3><p><strong>Hardware</strong> is the physical parts of a computer you can touch — monitor, keyboard, mouse, printer. <strong>Software</strong> is the programs and applications that tell the computer what to do.</p></div>
      <div class="card rv"><div class="card__ico">${icon('grid')}</div><h3>Input → Process → Output</h3><p>A computer takes <strong>input</strong> (typing, clicking), <strong>processes</strong> it inside, and gives <strong>output</strong> — on screen, on paper or through speakers. Storage keeps your work safe for later.</p></div>
      <div class="card rv"><div class="card__ico">${icon('keyboard')}</div><h3>Keyboard &amp; Mouse</h3><p>Practise typing with both hands, use the mouse to point and click, and remember: save your work in the right folder before you close the computer safely.</p></div>
    </div>
    <div class="split" style="margin-top:clamp(28px,4vw,52px)">
      <div class="media-stack rv">
        <div class="media-stack__main">${pic('exhibit-teacher', 'Alpha pupils practising a hands-on activity with their teacher', { widths: [800, 1200], ratio: '4/3' })}</div>
      </div>
      <div>
        <h3>Practise in the Computer Lab</h3>
        <p>Computer Lab activities are part of school life at Alpha. Ask your teacher for extra practice time, and remember the golden rules: clean hands, gentle clicks, save often, shut down properly.</p>
        <p>${btn('/computer-learning', 'Computer Learning Page', 'primary', 'arrow')}</p>
      </div>
    </div>
  </div>
</section>

<section class="sec" id="safety">
  <div class="container">
    ${sectionHead('Internet Safety Corner', 'Be Smart. Be Safe. Be Kind.', 'Alpha teaches digital safety alongside every computer skill. These rules protect you everywhere you use technology.', { align: 'center' })}
    <div class="grid grid--4">
      <div class="card rv" style="text-align:center"><div class="card__ico" style="margin-inline:auto">${icon('lock')}</div><h3>Keep Secrets Secret</h3><p>Never share your password, home address, school details or photos with people online.</p></div>
      <div class="card rv" style="text-align:center"><div class="card__ico" style="margin-inline:auto">${icon('eye')}</div><h3>Ask Before Downloading</h3><p>Always ask a teacher or parent before downloading anything or clicking unknown links.</p></div>
      <div class="card rv" style="text-align:center"><div class="card__ico" style="margin-inline:auto">${icon('users')}</div><h3>No Stranger Chats</h3><p>Do not talk to strangers online or accept messages from people you do not know.</p></div>
      <div class="card rv" style="text-align:center"><div class="card__ico" style="margin-inline:auto">${icon('heart')}</div><h3>Be Kind Online</h3><p>Use approved websites, speak respectfully, and tell a trusted adult if anything feels wrong.</p></div>
    </div>
    <div class="note-strip" style="margin-top:26px;max-width:860px;margin-inline:auto">${icon('shield')}<span><strong>The Alpha Safety Pledge:</strong> I will use approved websites, ask before downloading, protect my passwords, keep personal information private, avoid strange messages, and never chat with strangers online.</span></div>
  </div>
</section>

<section class="sec kids">
  <div class="container text-center">
    <h2>Keep Exploring!</h2>
    <p class="muted" style="max-width:56ch;margin:0 auto 22px">Discover school news, see your classmates in the gallery, and learn about computer classes at Alpha.</p>
    <div style="display:flex;gap:12px;justify-content:center;flex-wrap:wrap">
      ${btn('/news', 'School News', 'primary')}
      ${btn('/gallery', 'Photo Gallery', 'gold', 'camera')}
      ${btn('/school-life', 'School Life', 'ghost')}
    </div>
  </div>
</section>`;
    return {
      body,
      meta: {
        title: 'Alpha Kids Zone — Games & Learning for Pupils | Alpha Adventist Pre & Primary School',
        desc: 'The Alpha Kids Zone: a friendly corner for pupils of Alpha Adventist Pre & Primary School with a typing game, maths challenge, computer lesson revision and internet safety tips.',
        bodyClass: 'page-kids'
      }
    };
  }
};
