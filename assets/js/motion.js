// HomeEase motion layer: reveals, count-ups, headline words, OTP flips, confetti.
(function () {
  const root = document.documentElement;
  const calm = root.classList.contains('no-anim') || matchMedia('(prefers-reduced-motion: reduce)').matches;

  // 1. Staggered reveal for cards
  const sel = '.he-card, .glow-card, .glow-pink, .job-card, .stat-card, .tech-row, .radar, .kpi, .request-strip, .service-card, .sos-hero';
  const els = [...document.querySelectorAll(sel)].filter(el => !el.closest('.rv') || el.classList.contains('rv'));
  if (!calm && 'IntersectionObserver' in window) {
    root.classList.add('js-motion');
    let batch = 0, timer = null;
    const io = new IntersectionObserver(entries => {
      entries.forEach(en => {
        if (!en.isIntersecting) return;
        en.target.style.setProperty('--d', (Math.min(batch++, 8) * 0.06) + 's');
        en.target.classList.add('in');
        io.unobserve(en.target);
      });
      clearTimeout(timer); timer = setTimeout(() => batch = 0, 120);
    }, { threshold: 0.08 });
    els.forEach(el => { el.classList.add('rv'); io.observe(el); });
  }

  // 2. Headline word-by-word
  const h = document.querySelector('.hero-title');
  if (h && !calm) {
    let i = 0;
    const wrap = node => [...node.childNodes].forEach(n => {
      if (n.nodeType === 3) {
        const frag = document.createDocumentFragment();
        n.textContent.split(/(\s+)/).forEach(part => {
          if (!part.trim()) { frag.appendChild(document.createTextNode(part)); return; }
          const s = document.createElement('span'); s.className = 'w'; s.textContent = part; s.style.setProperty('--d', (i++ * 0.07) + 's'); frag.appendChild(s);
        });
        n.replaceWith(frag);
      } else if (n.nodeType === 1 && n.tagName !== 'BR') wrap(n);
    });
    wrap(h);
  }

  // 3. Count-up numbers (₹, %, decimals, "x/y" left side)
  const countUp = el => {
    const txt = el.firstChild && el.firstChild.nodeType === 3 ? el.firstChild : null;
    if (!txt) return;
    const m = txt.textContent.match(/^(\s*₹?)([\d,]+(?:\.\d+)?)(.*)$/s);
    if (!m) return;
    const target = parseFloat(m[2].replace(/,/g, '')), dec = (m[2].split('.')[1] || '').length, comma = m[2].includes(',') || target >= 1000;
    if (!isFinite(target) || target === 0) return;
    const t0 = performance.now(), dur = 900;
    const fmt = v => comma ? v.toLocaleString('en-IN', { minimumFractionDigits: dec, maximumFractionDigits: dec }) : v.toFixed(dec);
    const step = now => {
      const p = Math.min(1, (now - t0) / dur), e = 1 - Math.pow(1 - p, 3);
      txt.textContent = m[1] + fmt(target * e) + m[3];
      if (p < 1) requestAnimationFrame(step);
    };
    requestAnimationFrame(step);
  };
  if (!calm) document.querySelectorAll('.kpi-val, .stat-card .val, .score, .price-big').forEach(countUp);

  // 4. OTP digit flip order
  document.querySelectorAll('.otp-digits').forEach(g => [...g.children].forEach((s, i) => s.style.setProperty('--i', i)));

  // 5. Confetti on big wins (payment released, price locked, job accepted/completed, booking created)
  const win = [...document.querySelectorAll('.alert-success')].some(a => /released|locked|accepted|marked complete|created|started|confirmed/i.test(a.textContent));
  if (win && !calm) {
    const c = document.createElement('canvas'); c.id = 'he-confetti'; document.body.appendChild(c);
    const x = c.getContext('2d'); const W = c.width = innerWidth, H = c.height = innerHeight;
    const colors = ['#D2F54A', '#C3EC2B', '#0E0F0C', '#F4B942', '#9DB8FF', '#F0A3A6'];
    const ps = Array.from({ length: 110 }, () => ({ x: W / 2 + (Math.random() - .5) * 160, y: H * .35, vx: (Math.random() - .5) * 12, vy: -Math.random() * 13 - 4,
      r: Math.random() * 6 + 3, c: colors[Math.random() * colors.length | 0], a: Math.random() * 6, va: (Math.random() - .5) * .3 }));
    const t0 = performance.now();
    const draw = now => {
      x.clearRect(0, 0, W, H);
      ps.forEach(p => { p.vy += .35; p.vx *= .99; p.x += p.vx; p.y += p.vy; p.a += p.va;
        x.save(); x.translate(p.x, p.y); x.rotate(p.a); x.fillStyle = p.c; x.fillRect(-p.r, -p.r / 2, p.r * 2, p.r); x.restore(); });
      if (now - t0 < 2200) requestAnimationFrame(draw); else c.remove();
    };
    requestAnimationFrame(draw);
  }

  // 6. Ripple-free press feedback for links styled as buttons is handled in CSS.
})();
