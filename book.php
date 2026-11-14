<?php
require_once __DIR__ . '/includes/functions.php';
if (!user()) { $_SESSION['after_login'] = 'book.php?' . ($_SERVER['QUERY_STRING'] ?? ''); flash('Log in to book a service.', 'info'); redirect('login.php'); }
$u = require_login('customer');

$svcId = (int)($_GET['service'] ?? array_key_first(services()));
if (!isset(services()[$svcId])) $svcId = array_key_first(services());
$urg = $_GET['urgency'] ?? 'normal';
if (!in_array($urg, BOOKABLE_URGENCY, true)) $urg = 'normal';
$src = in_array($_GET['src'] ?? '', ['text', 'photo'], true) ? $_GET['src'] : 'manual';
$desc = $_GET['desc'] ?? '';
$when = date('Y-m-d\TH:00', strtotime($urg === 'emergency' ? '+1 hour' : ($urg === 'same_day' ? '+3 hours' : 'tomorrow 10:00')));

$title = 'Book a service';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
  <div class="row g-4">
    <div class="col-lg-8">
      <div class="card he-card p-4">
        <h1 class="h3 fw-bold mb-1">Book a service</h1>
        <p class="text-muted">Tell us what you need. We'll find the best technician near you.</p>
        <?php if ($src !== 'manual'): ?>
          <div class="alert alert-mint rounded-4 py-2 small"><i class="bi bi-magic"></i> Pre-filled by the AI assistant from your <?= $src === 'photo' ? 'photo' : 'description' ?>. You can change anything.</div>
        <?php endif; ?>
        <form method="post" action="match.php" id="book-form">
          <?= csrf_field() ?>
          <input type="hidden" name="src" value="<?= e($src) ?>">
          <label class="form-label fw-semibold">Service</label>
          <div class="row g-2 mb-4">
            <?php foreach (services() as $s): ?>
              <div class="col-6 col-md-4">
                <input type="radio" class="btn-check" name="service_id" id="s<?= $s['id'] ?>" value="<?= $s['id'] ?>" <?= $s['id'] == $svcId ? 'checked' : '' ?>>
                <label class="btn svc-option w-100 text-start p-3" for="s<?= $s['id'] ?>"><i class="bi <?= e($s['icon']) ?> me-1"></i> <?= e($s['name']) ?><br><small class="text-muted">from <?= inr($s['base_price']) ?></small></label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="mb-3"><label class="form-label fw-semibold">Describe the problem</label>
            <textarea class="form-control" name="description" rows="3" required placeholder="e.g. Kitchen tap leaking under the sink"><?= e($desc) ?></textarea></div>
          <div class="row g-3 mb-3">
            <div class="col-md-4"><label class="form-label fw-semibold">Area</label>
              <select class="form-select" name="area" required>
                <?php foreach (array_keys(AREAS) as $a): ?><option <?= ($u['area'] ?? '') === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
              </select></div>
            <div class="col-md-8"><label class="form-label fw-semibold">Address</label><input class="form-control" name="address" required placeholder="House no, street, landmark"></div>
          </div>
          <label class="form-label fw-semibold">How urgent?</label>
          <div class="row g-2 mb-3">
            <?php foreach (BOOKABLE_URGENCY as $k): $label = URGENCY_LABEL[$k]; ?>
              <div class="col-md-4">
                <input type="radio" class="btn-check" name="urgency" id="u-<?= $k ?>" value="<?= $k ?>" <?= $k === $urg ? 'checked' : '' ?>>
                <label class="btn svc-option w-100 p-2" for="u-<?= $k ?>"><?= e($label) ?><?= URGENCY_PCT[$k] ? ' <small class="text-muted">+' . URGENCY_PCT[$k] . '%</small>' : '' ?></label>
              </div>
            <?php endforeach; ?>
          </div>
          <div class="mb-4"><label class="form-label fw-semibold">Preferred time</label>
            <input type="datetime-local" class="form-control" name="scheduled_at" value="<?= $when ?>" min="<?= date('Y-m-d\TH:i') ?>" required></div>
          <button class="btn btn-coral btn-lg rounded-pill px-5"><i class="bi bi-cpu"></i> Find best technicians</button>
        </form>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card he-card p-4 sticky-lg-top" style="top:90px">
        <div class="d-flex align-items-center gap-2 mb-3"><span class="icon-circle"><i class="bi bi-receipt"></i></span><div class="fw-bold">Smart price estimate</div></div>
        <div id="price-lines" class="small"></div>
        <hr>
        <div class="d-flex justify-content-between align-items-center"><span class="fw-semibold">Estimated total</span><span class="h3 fw-bold text-teal mb-0" id="price-total">…</span></div>
        <div class="d-flex justify-content-between small mt-1"><span class="text-muted">AI fair price range</span><span class="fw-semibold" id="price-range">…</span></div>
        <small class="text-muted d-block mt-2">Updates live with service, urgency, time, demand and area surge. You can book at this price or negotiate with the technician within the fair range.</small>
      </div>
    </div>
  </div>
</div>
<script>
const form = document.getElementById('book-form');
async function updatePrice() {
  const fd = new FormData(form);
  const q = new URLSearchParams({ service_id: fd.get('service_id'), urgency: fd.get('urgency'), when: fd.get('scheduled_at'), area: fd.get('area') });
  const r = await (await fetch('api/estimate.php?' + q)).json();
  if (!r.ok) return;
  document.getElementById('price-lines').innerHTML = r.lines.map(l => `<div class="d-flex justify-content-between mb-1"><span>${l[0]}</span><span>₹${l[1].toLocaleString('en-IN')}</span></div>`).join('');
  document.getElementById('price-total').textContent = '₹' + r.total.toLocaleString('en-IN');
  document.getElementById('price-range').textContent = '₹' + r.min.toLocaleString('en-IN') + ' – ₹' + r.max.toLocaleString('en-IN');
}
form.addEventListener('change', updatePrice);
updatePrice();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
