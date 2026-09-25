/* Alpha Adventist Pre & Primary School — front-end behaviours */
(function () {
  'use strict';
  var reduced = window.matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---------- sticky header shadow ---------- */
  var head = document.querySelector('.site-head');
  if (head) {
    var onScroll = function () { head.classList.toggle('is-stuck', window.scrollY > 8); };
    window.addEventListener('scroll', onScroll, { passive: true });
    onScroll();
  }

  /* ---------- mobile nav ---------- */
  var toggle = document.querySelector('[data-navtoggle]');
  var panel = document.getElementById('mobileNav');
  if (toggle && panel) {
    toggle.addEventListener('click', function () {
      var open = toggle.getAttribute('aria-expanded') === 'true';
      toggle.setAttribute('aria-expanded', String(!open));
      panel.hidden = open;
      toggle.innerHTML = open
        ? '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="M4 7h16M4 12h16M4 17h16"/></svg><span class="sr-only">Open menu</span>'
        : '<svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"><path d="m6 6 12 12M18 6 6 18"/></svg><span class="sr-only">Close menu</span>';
    });
    panel.addEventListener('click', function (e) {
      if (e.target.closest('a')) { toggle.click(); }
    });
  }

  /* ---------- hero slider ---------- */
  var hero = document.querySelector('[data-hero]');
  if (hero) {
    var slides = Array.prototype.slice.call(hero.querySelectorAll('.hero__slide'));
    var dots = Array.prototype.slice.call(hero.querySelectorAll('.hero__dots button'));
    var prevB = hero.querySelector('[data-hero-prev]');
    var nextB = hero.querySelector('[data-hero-next]');
    var idx = 0, timer = null;
    var show = function (i) {
      idx = (i + slides.length) % slides.length;
      slides.forEach(function (s, n) {
        s.classList.toggle('is-active', n === idx);
        s.setAttribute('aria-hidden', String(n !== idx));
        var img = s.querySelector('img');
        if (img && n === idx) img.loading = 'eager';
      });
      dots.forEach(function (d, n) { d.setAttribute('aria-current', String(n === idx)); });
    };
    var stop = function () { if (timer) { clearInterval(timer); timer = null; } };
    var play = function () {
      if (reduced || slides.length < 2) return;
      stop();
      timer = setInterval(function () { show(idx + 1); }, 7000);
    };
    dots.forEach(function (d, n) { d.addEventListener('click', function () { show(n); play(); }); });
    if (prevB) prevB.addEventListener('click', function () { show(idx - 1); play(); });
    if (nextB) nextB.addEventListener('click', function () { show(idx + 1); play(); });
    hero.addEventListener('mouseenter', stop);
    hero.addEventListener('mouseleave', play);
    hero.addEventListener('focusin', stop);
    hero.addEventListener('focusout', play);
    /* swipe */
    var x0 = null;
    hero.addEventListener('pointerdown', function (e) { x0 = e.clientX; }, { passive: true });
    hero.addEventListener('pointerup', function (e) {
      if (x0 === null) return;
      var dx = e.clientX - x0;
      if (Math.abs(dx) > 45) { show(idx + (dx < 0 ? 1 : -1)); play(); }
      x0 = null;
    }, { passive: true });
    /* keyboard */
    hero.addEventListener('keydown', function (e) {
      if (e.key === 'ArrowLeft') { show(idx - 1); play(); }
      if (e.key === 'ArrowRight') { show(idx + 1); play(); }
    });
    show(0); play();
  }

  /* ---------- reveal on scroll ---------- */
  var rvEls = document.querySelectorAll('.rv');
  if (rvEls.length) {
    if (reduced || !('IntersectionObserver' in window)) {
      rvEls.forEach(function (el) { el.classList.add('in'); });
    } else {
      var io = new IntersectionObserver(function (entries) {
        entries.forEach(function (en) {
          if (en.isIntersecting) { en.target.classList.add('in'); io.unobserve(en.target); }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -6% 0px' });
      rvEls.forEach(function (el) { io.observe(el); });
    }
  }

  /* ---------- gallery: filters + lightbox ---------- */
  var galGrid = document.querySelector('[data-gallery]');
  if (galGrid) {
    var items = Array.prototype.slice.call(galGrid.querySelectorAll('.gal-item'));
    var filterBtns = Array.prototype.slice.call(document.querySelectorAll('[data-gal-filter]'));
    filterBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        var cat = b.getAttribute('data-gal-filter');
        filterBtns.forEach(function (o) { o.setAttribute('aria-pressed', String(o === b)); });
        items.forEach(function (it) {
          it.style.display = (cat === 'all' || it.getAttribute('data-cat') === cat) ? '' : 'none';
        });
      });
    });
    /* lightbox */
    var lb = document.getElementById('lightbox');
    if (lb) {
      var lbImg = lb.querySelector('img');
      var lbCap = lb.querySelector('.lightbox__cap');
      var visible = function () { return items.filter(function (i) { return i.style.display !== 'none'; }); };
      var cur = 0;
      var openAt = function (item) {
        var vis = visible();
        cur = vis.indexOf(item);
        var img = item.querySelector('img');
        var src = img.getAttribute('data-full') || img.currentSrc || img.src;
        src = src.replace(/-(480|800|1200)\.webp$/, '-1600.webp').replace(/-(480|800|1200)\.jpg$/, '-1600.jpg');
        lbImg.src = src;
        lbImg.alt = img.alt;
        lbCap.textContent = item.getAttribute('data-caption') || '';
        lb.classList.add('is-open');
        document.body.style.overflow = 'hidden';
        lb.querySelector('.lightbox__close').focus();
      };
      var step = function (d) {
        var vis = visible();
        cur = (cur + d + vis.length) % vis.length;
        openAt(vis[cur]);
      };
      var close = function () {
        lb.classList.remove('is-open');
        document.body.style.overflow = '';
      };
      items.forEach(function (it) { it.addEventListener('click', function () { openAt(it); }); });
      lb.querySelector('.lightbox__close').addEventListener('click', close);
      lb.querySelector('.lightbox__prev').addEventListener('click', function () { step(-1); });
      lb.querySelector('.lightbox__next').addEventListener('click', function () { step(1); });
      lb.addEventListener('click', function (e) { if (e.target === lb) close(); });
      document.addEventListener('keydown', function (e) {
        if (!lb.classList.contains('is-open')) return;
        if (e.key === 'Escape') close();
        if (e.key === 'ArrowLeft') step(-1);
        if (e.key === 'ArrowRight') step(1);
      });
    }
  }

  /* ---------- news category filter ---------- */
  var newsGrid = document.querySelector('[data-news-grid]');
  if (newsGrid) {
    var nBtns = Array.prototype.slice.call(document.querySelectorAll('[data-news-filter]'));
    var nCards = Array.prototype.slice.call(newsGrid.querySelectorAll('.news-card'));
    nBtns.forEach(function (b) {
      b.addEventListener('click', function () {
        var cat = b.getAttribute('data-news-filter');
        nBtns.forEach(function (o) { o.setAttribute('aria-pressed', String(o === b)); });
        nCards.forEach(function (c) {
          c.style.display = (cat === 'all' || c.getAttribute('data-cat') === cat) ? '' : 'none';
        });
      });
    });
  }

  /* ---------- public forms ---------- */
  document.querySelectorAll('form[data-endpoint]').forEach(function (form) {
    form.addEventListener('submit', function (e) {
      e.preventDefault();
      var status = form.querySelector('.form__status');
      var hp = form.querySelector('[name="website_url"]');
      if (hp && hp.value) { return; } /* honeypot: silently drop bots */
      var data = {};
      new FormData(form).forEach(function (v, k) { data[k] = v; });
      var btn = form.querySelector('button[type="submit"]');
      if (btn) btn.disabled = true;
      fetch(form.getAttribute('data-endpoint'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify(data)
      }).then(function (r) { return r.json(); }).then(function (res) {
        if (status) {
          status.className = 'form__status ' + (res.ok ? 'ok' : 'err');
          status.textContent = res.message || (res.ok ? 'Thank you — your message has been received.' : 'Something went wrong. Please try again.');
        }
        if (res.ok) form.reset();
      }).catch(function () {
        if (status) { status.className = 'form__status err'; status.textContent = 'Network error — please try again or call the school office.'; }
      }).finally(function () { if (btn) btn.disabled = false; });
    });
  });

  /* ---------- kids zone: typing trainer ---------- */
  var typing = document.querySelector('[data-typing]');
  if (typing) {
    var words = ['alpha', 'school', 'wisdom', 'truth', 'keyboard', 'monitor', 'mouse', 'printer', 'file', 'folder', 'save', 'type', 'learn', 'faith', 'character', 'future', 'computer', 'screen', 'light', 'friend', 'teacher', 'study', 'kigoma', 'talent'];
    var wordEl = typing.querySelector('[data-word]');
    var input = typing.querySelector('input');
    var msg = typing.querySelector('[data-msg]');
    var scoreEl = typing.querySelector('[data-score]');
    var bestEl = typing.querySelector('[data-best]');
    var score = 0, best = 0, current = '';
    var next = function () {
      current = words[Math.floor(Math.random() * words.length)];
      wordEl.textContent = current;
      input.value = '';
      input.focus();
    };
    input.addEventListener('input', function () {
      var v = input.value.trim().toLowerCase();
      if (v === current) {
        score += 1;
        if (score > best) best = score;
        scoreEl.textContent = score;
        bestEl.textContent = best;
        msg.textContent = 'Excellent! Well done. ⭐';
        msg.className = 'game__msg good';
        next();
      } else if (current.indexOf(v) === 0) {
        msg.textContent = 'Keep going…';
        msg.className = 'game__msg';
      } else {
        msg.textContent = 'Look carefully at the word and try again.';
        msg.className = 'game__msg bad';
      }
    });
    next();
  }

  /* ---------- kids zone: maths quiz ---------- */
  var quiz = document.querySelector('[data-quiz]');
  if (quiz) {
    var qEl = quiz.querySelector('[data-q]');
    var qInput = quiz.querySelector('input');
    var qMsg = quiz.querySelector('[data-msg]');
    var qScore = quiz.querySelector('[data-score]');
    var levelSel = quiz.querySelector('[data-level]');
    var qs = 0, a = 0, b = 0, ans = 0;
    var newQ = function () {
      var lv = levelSel ? levelSel.value : '1';
      var max = lv === '1' ? 10 : lv === '2' ? 30 : 12;
      a = Math.floor(Math.random() * max) + 1;
      b = Math.floor(Math.random() * max) + 1;
      var op = lv === '3' ? '×' : (Math.random() < 0.5 ? '+' : '−');
      if (op === '×') { a = Math.floor(Math.random() * 9) + 2; b = Math.floor(Math.random() * 9) + 2; ans = a * b; }
      else if (op === '+') { ans = a + b; }
      else { if (b > a) { var t = a; a = b; b = t; } ans = a - b; }
      qEl.textContent = a + ' ' + op + ' ' + b + ' = ?';
      qInput.value = '';
      qInput.focus();
    };
    qInput.addEventListener('input', function () {
      if (qInput.value === '' ) return;
      if (parseInt(qInput.value, 10) === ans) {
        qs += 1;
        qScore.textContent = qs;
        qMsg.textContent = 'Correct! Great work. 🎉';
        qMsg.className = 'game__msg good';
        setTimeout(newQ, 650);
      } else if (qInput.value.length >= String(ans).length) {
        qMsg.textContent = 'Not yet — try again, you can do it!';
        qMsg.className = 'game__msg bad';
      }
    });
    if (levelSel) levelSel.addEventListener('change', newQ);
    newQ();
  }

  /* ---------- copy link share ---------- */
  document.querySelectorAll('[data-copy-link]').forEach(function (b) {
    b.addEventListener('click', function (e) {
      e.preventDefault();
      var url = window.location.href;
      if (navigator.clipboard) {
        navigator.clipboard.writeText(url).then(function () {
          b.textContent = 'Link copied ✓';
          setTimeout(function () { b.textContent = 'Copy link'; }, 2200);
        });
      }
    });
  });

  /* ---------- smooth anchor offset ---------- */
  document.querySelectorAll('a[href^="#"]').forEach(function (a) {
    a.addEventListener('click', function (e) {
      var id = a.getAttribute('href').slice(1);
      if (!id) return;
      var el = document.getElementById(id);
      if (!el) return;
      e.preventDefault();
      var y = el.getBoundingClientRect().top + window.scrollY - 92;
      window.scrollTo({ top: y, behavior: reduced ? 'auto' : 'smooth' });
    });
  });
})();
