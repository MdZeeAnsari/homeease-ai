<?php
// Premium AI chat (Gemini, with built-in AI fallback)
require_once __DIR__ . '/includes/functions.php';
if (!user()) { $_SESSION['after_login'] = 'chat.php'; flash('Log in to use the AI chat.', 'info'); redirect('login.php'); }
$u = require_login('customer');
if (!is_premium($u)) { flash('AI chat is part of HomeEase Premium.', 'info'); redirect('premium.php'); }
$history = $_SESSION['chat'] ?? [];
$title = 'AI chat';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-3" style="max-width:820px">
  <div class="d-flex align-items-center gap-2 mb-3">
    <span class="icon-circle lime"><i class="bi bi-robot"></i></span>
    <div class="flex-grow-1"><div class="fw-bold fs-5">HomeEase AI assistant <span class="pill pill-dark ms-1"><i class="bi bi-gem"></i> PRO</span></div>
      <small class="text-muted"><?= GEMINI_API_KEY !== '' ? 'Powered by Google Gemini' : 'Built-in HomeEase AI (add a Gemini key to upgrade answers)' ?> · knows your bookings and fair prices</small></div>
    <button class="btn btn-sm btn-soft rounded-pill" id="chat-reset" title="New chat"><i class="bi bi-arrow-counterclockwise"></i></button>
  </div>

  <div class="he-card p-3 p-md-4 mb-3" id="chat-box" style="min-height:360px;max-height:58vh;overflow-y:auto">
    <div class="chat-msg bot"><div class="bubble">Hi <?= e(strtok($u['name'], ' ')) ?>! 👋 I'm your HomeEase AI. Tell me what's wrong at home, in English or Hinglish, or ask about your bookings.</div></div>
    <?php foreach ($history as $h): ?>
      <div class="chat-msg <?= $h['role'] === 'user' ? 'me' : 'bot' ?>"><div class="bubble"><?= nl2br(e($h['text'])) ?></div></div>
    <?php endforeach; ?>
  </div>

  <div class="chips-scroll mb-2">
    <?php foreach (['Mere bathroom ka nal tapak raha hai', 'AC is making noise and not cooling', 'When is my technician coming?', 'Kitchen mein gas ki smell aa rahi hai', 'How much for deep cleaning a 2BHK?'] as $q): ?>
      <button type="button" class="chip chat-q"><?= e($q) ?></button>
    <?php endforeach; ?>
  </div>
  <form id="chat-form" class="d-flex gap-2">
    <input id="chat-input" class="form-control form-control-lg rounded-pill" placeholder="Ask HomeEase AI…" autocomplete="off" maxlength="600">
    <button class="btn btn-coral rounded-pill px-4"><i class="bi bi-send-fill"></i></button>
  </form>
</div>
<script>
const CSRF = '<?= csrf_token() ?>';
const box = document.getElementById('chat-box'), input = document.getElementById('chat-input');
const esc = s => s.replace(/[&<>"']/g, c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
const fmt = s => esc(s).replace(/\*\*(.+?)\*\*/g, '<b>$1</b>').replace(/\n/g, '<br>');
function add(role, html) {
  const d = document.createElement('div'); d.className = 'chat-msg ' + role; d.innerHTML = '<div class="bubble">' + html + '</div>';
  box.appendChild(d); box.scrollTop = box.scrollHeight; return d;
}
box.scrollTop = box.scrollHeight;
async function send(text) {
  if (!text.trim()) return;
  add('me', esc(text)); input.value = '';
  const typing = add('bot', '<span class="typing"><i></i><i></i><i></i></span>');
  try {
    const r = await fetch('api/chat.php', { method: 'POST', body: new URLSearchParams({ csrf: CSRF, message: text }) });
    const j = await r.json(); typing.remove();
    if (j.upgrade) { location.href = 'premium.php'; return; }
    if (j.error) { add('bot', '<span class="text-danger">' + esc(j.error) + '</span>'); return; }
    let html = fmt(j.reply);
    if (j.suggest) {
      const s = j.suggest, u = encodeURIComponent(text);
      html += `<div class="chat-card mt-2"><div class="d-flex justify-content-between align-items-center gap-2">
        <div><div class="fw-bold">${esc(s.service)}</div><small class="text-muted">Fair range ₹${s.min.toLocaleString('en-IN')}–₹${s.max.toLocaleString('en-IN')}</small></div>
        ${s.hazard ? `<a class="btn btn-sm btn-danger rounded-pill" href="sos.php?hazard=${s.hazard}">SOS</a>` : ''}
        <a class="btn btn-sm btn-coral rounded-pill" href="book.php?service=${s.service_id}&urgency=${s.urgency}&src=text&desc=${u}">Book</a></div></div>`;
    }
    html += `<div class="chat-src">${j.source === 'gemini' ? '✦ Gemini' : 'HomeEase AI' + (j.note ? ' · ' + esc(j.note) : '')}</div>`;
    add('bot', html);
  } catch (e) { typing.remove(); add('bot', '<span class="text-danger">Connection problem. Try again.</span>'); }
}
document.getElementById('chat-form').addEventListener('submit', e => { e.preventDefault(); send(input.value); });
document.querySelectorAll('.chat-q').forEach(b => b.onclick = () => send(b.textContent.trim()));
document.getElementById('chat-reset').onclick = async () => {
  await fetch('api/chat.php', { method: 'POST', body: new URLSearchParams({ csrf: CSRF, action: 'reset' }) });
  location.reload();
};
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
