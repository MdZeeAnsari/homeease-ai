// HomeEase AI assistant: text NLP (server) + photo diagnosis (MobileNet in the browser)
const resultBox = document.getElementById('ai-result');
const URG = { normal: ['Standard', 'secondary'], same_day: ['Same day', 'warning'], emergency: ['Emergency', 'danger'] };

function renderResult(r, source, extra = '') {
  const def = document.getElementById('ai-default');
  if (!r.found) {
    resultBox.innerHTML = `<div class="he-card p-4 mb-3"><i class="bi bi-info-circle"></i> ${r.message}</div>`;
    return;
  }
  if (def) def.classList.add('d-none');
  const [ul] = URG[r.urgency] || URG.normal;
  const tags = (r.keywords || []).map(k => `<span class="tag">#${k.replace(/\s+/g, '')}</span>`).join('')
    + (r.urgency !== 'normal' ? `<span class="tag red">#${r.urgency === 'same_day' ? 'urgent' : 'emergency'}</span>` : '');
  const alts = (r.alternatives || []).map(a => a.name).join(', ');
  const desc = encodeURIComponent(document.getElementById(source === 'photo' ? 'photo-caption' : 'ask-text').value || '');
  const conf = Math.max(5, r.confidence);
  const level = r.confidence >= 85 ? 'High Precision' : r.confidence >= 60 ? 'Good' : 'Low, add detail';
  const dispatch = { normal: 'Standard Dispatch', same_day: 'Same Day Dispatch', emergency: 'Emergency Dispatch' }[r.urgency] || 'Standard Dispatch';
  const near = (r.nearby || []).map(t => `
      <div class="tech-row mb-2">
        <span class="avatar sm">${t.name[0]}<span class="tick"><i class="bi bi-check"></i></span></span>
        <div class="flex-grow-1"><div class="fw-bold">${t.name} <span class="pill pill-soft ms-1">${t.rating} ★</span></div>
          <small class="text-muted">${t.km} km away · <span class="text-mint fw-semibold">Free in ${t.eta} min</span> · ${t.tier}</small></div>
        <a class="icon-circle text-decoration-none" href="book.php?service=${r.service_id}&urgency=${r.urgency}&src=${source}&desc=${desc}" title="Book"><i class="bi bi-calendar-plus"></i></a>
      </div>`).join('');
  resultBox.innerHTML = `
    <div class="ai-result-card p-4 mb-3">
      <div class="d-flex align-items-center gap-2 mb-2"><span class="pill pill-lime">${dispatch}</span><small class="fw-semibold"><span class="dot me-1"></span>Live Match</small>
        <span class="ms-auto icon-circle" style="background:#fff"><i class="bi bi-${source === 'photo' ? 'camera' : 'tools'}"></i></span></div>
      <div class="h3 fw-bold mb-3">${r.service}</div>
      <div class="bg-white rounded-4 p-3 mb-3">
        <div class="d-flex justify-content-between small mb-2"><span class="text-muted">AI Match Confidence</span><b>${r.confidence}% ${level}</b></div>
        <div class="conf-bar mb-2"><div style="width:${conf}%"></div></div>
        <div>${tags}</div>
      </div>
      ${extra}
      ${alts ? `<div class="small text-muted mb-2">Could also be: ${alts}</div>` : ''}
      ${r.safety ? `<div class="alert alert-danger py-2 small mb-3 d-flex align-items-center gap-2"><i class="bi bi-exclamation-triangle"></i><span class="flex-grow-1">${r.safety}</span>${r.hazard ? `<a class="btn btn-sm btn-danger rounded-pill" href="sos.php?hazard=${r.hazard}">Emergency SOS</a>` : ''}</div>` : ''}
      <div class="d-flex justify-content-between align-items-end gap-3">
        <div><small class="text-muted">AI fair price range</small>
          <div class="h3 fw-bold mb-0 price-anim" data-min="${r.min}" data-max="${r.max}">₹${Number(r.min).toLocaleString('en-IN')}–${Number(r.max).toLocaleString('en-IN')}</div>
          <small class="text-mint fw-semibold"><i class="bi bi-check2"></i> 30-day rework warranty</small></div>
        <a class="btn btn-coral btn-lg rounded-pill px-4" href="book.php?service=${r.service_id}&urgency=${r.urgency}&src=${source}&desc=${desc}">Book Verified Pro <i class="bi bi-chevron-right"></i></a>
      </div>
    </div>
    ${near ? `<div class="d-flex justify-content-between align-items-center mb-2 mt-3 ai-in"><div class="fw-bold fs-5"><i class="bi bi-send"></i> Nearby Verified Technicians</div><small class="text-muted">${r.area} area</small></div>${near}` : ''}`;
}

async function ask(params) {
  const body = new URLSearchParams(params);
  const res = await fetch('api/assistant.php', { method: 'POST', body });
  return res.json();
}

// ---------- text ----------
document.querySelectorAll('.example').forEach(b => b.onclick = () => { document.getElementById('ask-text').value = b.dataset.q || b.textContent.trim(); document.getElementById('ask-form').requestSubmit(); });
document.getElementById('ask-form').addEventListener('submit', async e => {
  e.preventDefault();
  resultBox.innerHTML = '<div class="ai-skeleton mb-3"><span><i class="bi bi-stars"></i> AI is reading your problem…</span></div>';
  if (document.getElementById('ai-default')) document.getElementById('ai-default').classList.add('d-none');
  renderResult(await ask({ text: document.getElementById('ask-text').value }), 'text');
});

// ---------- photo (computer vision) ----------
// MobileNet (ImageNet, 1000 classes) runs in the browser from local files; its labels are mapped to our services.
const CV_MAP = {
  'plumbing': ['washbasin', 'toilet', 'bathtub', 'tub', 'shower', 'plunger', 'faucet', 'sink', 'water jug', 'bucket', 'soap dispenser'],
  'electrical': ['switch', 'electric fan', 'table lamp', 'lampshade', 'spotlight', 'power drill', 'plug', 'electric'],
  'ac-servicing': ['air conditioner', 'radiator', 'space heater', 'vent'],
  'appliance-repair': ['refrigerator', 'washer', 'dishwasher', 'microwave', 'television', 'monitor', 'crt screen', 'toaster',
                       'iron', 'stove', 'oven', 'crock pot', 'espresso maker', 'remote control', 'hand blower', 'rotisserie', 'sewing machine'],
  'cleaning': ['broom', 'swab', 'mop', 'vacuum', 'dishrag', 'paper towel', 'ashcan', 'studio couch', 'sofa', 'prayer rug', 'quilt', 'bath towel', 'shower curtain'],
};
let model = null, loading = null, modelKind = null, customLabels = [];
const cvStatus = document.getElementById('cv-status');

function loadScript(src) {
  return new Promise((ok, fail) => { const s = document.createElement('script'); s.src = src; s.onload = ok; s.onerror = fail; document.head.appendChild(s); });
}

// If our own Teachable Machine model exists in assets/model/, use it.
// Otherwise use the MobileNet bundled in assets/vendor/mobilenet (ImageNet, 1000 classes) - no internet needed.
async function loadModel() {
  if (model) return model;
  if (!loading) loading = (async () => {
    cvStatus.innerHTML = '<span class="spinner-border spinner-border-sm"></span> Loading computer vision model…';
    if (!window.tf) await loadScript('assets/vendor/tfjs/tf.min.js');
    try {
      const meta = await fetch('assets/model/metadata.json', { cache: 'no-store' });
      if (!meta.ok) throw new Error('no custom model');
      customLabels = (await meta.json()).labels || [];
      model = await tf.loadLayersModel('assets/model/model.json');
      modelKind = 'custom';
      cvStatus.innerHTML = `<i class="bi bi-check-circle text-success"></i> HomeEase vision model ready (trained by our team on ${customLabels.length} classes). Upload a photo.`;
    } catch (e) {
      const [m, labels] = await Promise.all([
        tf.loadLayersModel('assets/vendor/mobilenet/model.json'),
        fetch('assets/vendor/mobilenet/classes.json').then(r => r.json()),
      ]);
      model = m; customLabels = labels; modelKind = 'mobilenet';
      tf.tidy(() => model.predict(tf.zeros([1, 224, 224, 3])));   // warm-up so the first photo is fast
      cvStatus.innerHTML = '<i class="bi bi-check-circle text-success"></i> Vision model ready (MobileNet, runs on your device). Upload a photo.';
    }
    return model;
  })().catch(err => {
    console.error('Vision model failed to load', err);
    loading = null;
    cvStatus.innerHTML = '<i class="bi bi-exclamation-triangle text-danger"></i> Vision model could not load. Add a caption and we\'ll use text AI instead.';
    throw err;
  });
  return loading;
}
document.getElementById('photo-tab').addEventListener('shown.bs.tab', () => loadModel().catch(() => {}));

// Preprocessing for both models: centre-crop to square, resize to 224, scale pixels to [-1, 1]
// (Teachable Machine divides by 127, Keras MobileNet by 127.5)
async function classify(img, topK = 5) {
  const [, h, w] = model.inputs[0].shape;
  const div = modelKind === 'custom' ? 127 : 127.5;
  const probs = tf.tidy(() => {
    const px = tf.browser.fromPixels(img);
    const [ih, iw] = px.shape;
    const side = Math.min(ih, iw);
    const crop = px.slice([Math.floor((ih - side) / 2), Math.floor((iw - side) / 2), 0], [side, side, 3]);
    const x = tf.image.resizeBilinear(crop, [h || 224, w || 224]).toFloat().div(div).sub(1).expandDims(0);
    return model.predict(x).dataSync();
  });
  return Array.from(probs).map((p, i) => ({ className: customLabels[i] || ('Class ' + (i + 1)), probability: p }))
    .sort((a, b) => b.probability - a.probability).slice(0, topK);
}

// Phone photos can be 12 MP; draw onto a small canvas first (also fixes EXIF rotation in modern browsers)
async function toCanvas(file) {
  let src;
  try { src = await createImageBitmap(file, { imageOrientation: 'from-image' }); }
  catch (e) {
    src = new Image(); src.src = URL.createObjectURL(file);
    await src.decode();
  }
  const sw = src.width, sh = src.height, k = Math.min(1, 512 / Math.min(sw, sh));
  const c = document.createElement('canvas'); c.width = Math.round(sw * k); c.height = Math.round(sh * k);
  c.getContext('2d').drawImage(src, 0, 0, c.width, c.height);
  return c;
}

// Map a Teachable Machine class name to one of our services
function slugForLabel(label) {
  const l = label.toLowerCase();
  if (/plumb|tap|sink|pipe|leak/.test(l)) return 'plumbing';
  if (/electric|switch|fan|wiring|light/.test(l)) return 'electrical';
  if (/\bac\b|air ?con|a\.c/.test(l)) return 'ac-servicing';
  if (/applian|fridge|washing|tv|microwave/.test(l)) return 'appliance-repair';
  if (/clean|dust|dirty|sofa/.test(l)) return 'cleaning';
  return null;
}

function mapToService(preds) {
  const score = {};
  for (const p of preds) {
    const label = p.className.toLowerCase();
    for (const [slug, words] of Object.entries(CV_MAP)) {
      if (words.some(w => label.includes(w))) score[slug] = (score[slug] || 0) + p.probability;
    }
  }
  const best = Object.entries(score).sort((a, b) => b[1] - a[1])[0];
  if (!best || best[1] < 0.05) return null;
  // confidence = share of the model's top-5 probability mass that points to this service
  const mass = preds.reduce((s, p) => s + p.probability, 0) || 1;
  return { slug: best[0], conf: Math.min(99, Math.round(100 * best[1] / mass)) };
}

document.getElementById('photo-input').addEventListener('change', async e => {
  const file = e.target.files[0];
  e.target.value = '';              // so picking the same photo again still triggers a scan
  if (!file) return;
  const img = document.getElementById('photo-preview');
  img.src = URL.createObjectURL(file);
  img.classList.remove('d-none');
  document.getElementById('drop-hint').classList.add('d-none');
  resultBox.innerHTML = '<div class="ai-skeleton mb-3"><span><i class="bi bi-camera"></i> Computer vision is scanning your photo…</span></div>';
  document.getElementById('dropzone').classList.add('scanning');
  let preds = [], readErr = false;
  try {
    await loadModel();
    let pic;
    try { pic = await toCanvas(file); } catch (err) { readErr = true; throw err; }
    preds = await classify(pic, 5);
  } catch (err) { console.error(err); /* falls back to caption below */ }

  const predHtml = preds.slice(0, 3).map(p => `
    <div class="d-flex align-items-center gap-2 small mb-1">
      <span class="pred-label text-truncate">${p.className.split(',')[0]}</span>
      <div class="progress flex-grow-1" style="height:6px"><div class="progress-bar bg-teal" style="width:${Math.round(p.probability * 100)}%"></div></div>
      <span class="text-muted">${Math.round(p.probability * 100)}%</span>
    </div>`).join('');
  document.getElementById('cv-preds').innerHTML = preds.length ? `<div class="small fw-semibold mb-1">What the model sees</div>${predHtml}` : '';

  document.getElementById('dropzone').classList.remove('scanning');
  lastPhoto = { preds, readErr };
  await decide();
});

// photo result + caption -> final answer. Runs again when the caption is edited after the photo.
let lastPhoto = null;
async function decide() {
  const { preds, readErr } = lastPhoto;
  const caption = document.getElementById('photo-caption').value.trim();
  let hit = null;
  if (preds.length && modelKind === 'custom') {
    const slug = slugForLabel(preds[0].className);
    if (slug && preds[0].probability >= 0.4) hit = { slug, conf: Math.round(preds[0].probability * 100) };
  } else if (preds.length) hit = mapToService(preds);
  if (hit) {
    const r = await ask({ slug: hit.slug, conf: hit.conf, text: caption });
    renderResult(r, 'photo', `<div class="small mb-2"><i class="bi bi-camera"></i> Identified from your photo: <b>${preds[0].className.split(',')[0]}</b>${caption ? ` · <i class="bi bi-chat-text"></i> using your note "${caption.replace(/[&<>"]/g, '')}"` : ''}</div>`);
  } else if (caption) {
    renderResult(await ask({ text: caption }), 'photo', '<div class="small text-muted mb-2">Photo unclear, so we used your caption.</div>');
  } else if (readErr) {
    renderResult({ found: false, message: "This photo format can't be read by the browser (iPhone HEIC?). Take a screenshot of it or set the camera to JPG, or add a caption." }, 'photo');
  } else {
    renderResult({ found: false, message: "We couldn't identify a home-service item in this photo. Add a caption (e.g. 'leaking') or try the Describe tab." }, 'photo');
  }
}
let capTimer;
const capEl = document.getElementById('photo-caption');
capEl.addEventListener('input', () => { clearTimeout(capTimer); if (lastPhoto) capTimer = setTimeout(decide, 700); });
capEl.addEventListener('keydown', e => { if (e.key === 'Enter') { e.preventDefault(); clearTimeout(capTimer); if (lastPhoto) decide(); } });

// animate freshly rendered AI results: price count-up + staggered technician rows
new MutationObserver(() => {
  const el = resultBox.querySelector('.price-anim:not(.done)');
  if (!el || matchMedia('(prefers-reduced-motion: reduce)').matches) return;
  el.classList.add('done');
  const a = +el.dataset.min, b = +el.dataset.max, t0 = performance.now();
  const step = now => { const p = Math.min(1, (now - t0) / 800), e = 1 - Math.pow(1 - p, 3);
    el.textContent = '₹' + Math.round(a * e).toLocaleString('en-IN') + '–' + Math.round(b * e).toLocaleString('en-IN'); if (p < 1) requestAnimationFrame(step); };
  requestAnimationFrame(step);
  resultBox.querySelectorAll('.ai-result-card, .tech-row, .ai-in').forEach((n, i) => { n.style.animation = `he-up .55s ${i * 0.08}s cubic-bezier(.2,.8,.2,1) both`; });
}).observe(resultBox, { childList: true });
