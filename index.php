<?php
require_once __DIR__ . '/includes/functions.php';
$u = user();
if ($u && $u['role'] !== 'customer') redirect(home_for($u['role']));

$stats = db()->query("SELECT
    (SELECT COUNT(*) FROM technicians) techs,
    (SELECT COUNT(*) FROM bookings WHERE status='completed') done,
    (SELECT ROUND(AVG(rating),1) FROM bookings WHERE rating IS NOT NULL) rating")->fetch_assoc();

// Maintenance reminders for logged-in customers
$reminders = [];
if ($u) {
    $intervals = ['ac-servicing' => 90, 'cleaning' => 60];
    $st = db()->prepare("SELECT s.id, s.name, s.slug, MAX(b.completed_at) last
        FROM bookings b JOIN services s ON s.id = b.service_id
        WHERE b.customer_id = ? AND b.status = 'completed' GROUP BY s.id");
    $st->bind_param('i', $u['id']);
    $st->execute();
    foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) {
        $days = (int)floor((time() - strtotime($r['last'])) / 86400);
        if (isset($intervals[$r['slug']]) && $days >= $intervals[$r['slug']]) $reminders[] = $r + ['days' => $days];
    }
}
$title = 'Home services, made easy';
include __DIR__ . '/includes/header.php';
?>
<section class="pt-4 pb-2">
  <div class="container">
    <div class="row g-4 align-items-start">
      <div class="col-lg-6">
        <span class="pill pill-lime"><i class="bi bi-lightning-charge-fill"></i> AI-Powered Home Services · Bengaluru</span>
        <h1 class="hero-title mt-3 mb-4">Fix anything at home,<br><span>the smart way.</span></h1>
        <div class="glow-card p-3 p-md-4">
          <ul class="nav seg mb-3" role="tablist">
            <li class="nav-item flex-fill"><button class="nav-link active w-100" data-bs-toggle="pill" data-bs-target="#tab-text" type="button"><i class="bi bi-text-left"></i> Describe</button></li>
            <li class="nav-item flex-fill"><button class="nav-link w-100" id="photo-tab" data-bs-toggle="pill" data-bs-target="#tab-photo" type="button"><i class="bi bi-camera"></i> Photo Diagnosis <span class="text-danger">•</span></button></li>
          </ul>
          <div class="tab-content">
            <div class="tab-pane fade show active" id="tab-text">
              <form id="ask-form">
                <div class="ask-box mb-3">
                  <span class="pill" style="background:#fff"><i class="bi bi-translate"></i> English + Hinglish</span>
                  <textarea id="ask-text" class="form-control" rows="2" placeholder="e.g. Bathroom nal tapak raha hai, urgent"></textarea>
                </div>
                <div class="eyebrow-label mb-2">Common queries</div>
                <div class="chips-scroll mb-3">
                  <?php foreach ([['Bathroom nal tapak raha hai', '🚿'], ['AC not cooling', '❄️'], ['Switch board sparking!', '⚡'], ['Washing machine not spinning', '🧺'], ['Need deep cleaning before party', '🧹']] as [$ex, $em]): ?>
                    <button type="button" class="chip example" data-q="<?= e($ex) ?>"><?= e($ex) ?> <?= $em ?></button>
                  <?php endforeach; ?>
                </div>
                <button class="btn btn-coral btn-lg w-100 rounded-pill"><i class="bi bi-stars"></i> Diagnose with AI <i class="bi bi-arrow-right"></i></button>
              </form>
            </div>
            <div class="tab-pane fade" id="tab-photo">
              <label for="photo-input" class="dropzone d-block text-center p-4 mb-2" id="dropzone">
                <img id="photo-preview" class="d-none mb-2" alt="Uploaded problem photo">
                <div id="drop-hint"><i class="bi bi-camera fs-2"></i><div class="fw-semibold">Upload or take a photo of the problem</div><small class="text-muted">Tap, sink, fan, fridge, washing machine, sofa…</small></div>
              </label>
              <input type="file" id="photo-input" accept="image/*" class="d-none">
              <input id="photo-caption" class="form-control mb-2" placeholder="Add a few words (optional), e.g. leaking">
              <div id="cv-status" class="small text-muted mb-2"><i class="bi bi-cpu"></i> Computer vision model loads when you open this tab.</div>
              <div id="cv-preds" class="mb-2"></div>
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-6">
        <div id="ai-result"></div>
        <div id="ai-default">
          <div class="glow-pink p-4 mb-3">
            <span class="pill pill-lime">How it works</span>
            <div class="row g-3 mt-1">
              <?php foreach ([['bi-chat-dots', 'Describe', 'Type, or snap a photo'], ['bi-receipt', 'Fair price', 'AI range before you book'], ['bi-person-check', 'Best match', 'Explainable 100-pt score'], ['bi-shield-lock', 'Escrow + OTP', 'Pay only when fixed']] as $st): ?>
                <div class="col-6"><div class="d-flex gap-2 align-items-center"><span class="icon-circle" style="background:#fff"><i class="bi <?= $st[0] ?>"></i></span><div><div class="fw-bold small"><?= $st[1] ?></div><small class="text-muted"><?= $st[2] ?></small></div></div></div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="row g-3">
          <div class="col-6"><div class="he-card p-3 d-flex gap-2 align-items-center h-100"><span class="icon-circle"><i class="bi bi-shield-check"></i></span><div><div class="fw-bold small">Verified pros</div><small class="text-muted"><?= (int)$stats['techs'] ?> document-checked technicians</small></div></div></div>
          <div class="col-6"><div class="he-card p-3 d-flex gap-2 align-items-center h-100"><span class="icon-circle"><i class="bi bi-currency-rupee"></i></span><div><div class="fw-bold small">Zero hidden cost</div><small class="text-muted">Fair range shown upfront</small></div></div></div>
          <div class="col-6"><div class="he-card p-3 d-flex gap-2 align-items-center h-100"><span class="icon-circle"><i class="bi bi-star"></i></span><div><div class="fw-bold small"><?= e($stats['rating'] ?? '4.7') ?> ★ average</div><small class="text-muted"><?= number_format((int)$stats['done']) ?> jobs completed</small></div></div></div>
          <div class="col-6"><div class="he-card p-3 d-flex gap-2 align-items-center h-100"><span class="icon-circle"><i class="bi bi-patch-check"></i></span><div><div class="fw-bold small">30-day warranty</div><small class="text-muted">Free rework if it recurs</small></div></div></div>
        </div>
      </div>
    </div>
  </div>
</section>

<div class="container">
  <?php foreach ($reminders as $r): ?>
    <div class="card he-card reminder p-3 mt-4 d-flex flex-row align-items-center gap-3">
      <span class="icon-circle lime"><i class="bi bi-bell"></i></span>
      <div class="flex-grow-1"><b>Time for your <?= e($r['name']) ?>.</b> Your last one was <?= (int)$r['days'] ?> days ago. Regular servicing prevents costly breakdowns.</div>
      <a class="btn btn-coral rounded-pill" href="book.php?service=<?= (int)$r['id'] ?>&desc=<?= urlencode('Routine ' . $r['name']) ?>">Book again</a>
    </div>
  <?php endforeach; ?>

  <a href="<?= $u && is_premium($u) ? 'chat.php' : 'premium.php' ?>" class="d-block text-decoration-none mt-4">
    <div class="radar d-flex flex-wrap align-items-center gap-3">
      <span class="icon-circle" style="background:var(--lime)"><i class="bi bi-robot"></i></span>
      <div class="flex-grow-1"><div class="small fw-bold" style="letter-spacing:1.5px;color:var(--lime)"><i class="bi bi-gem"></i> HOMEEASE PREMIUM</div>
        <div class="h5 fw-bold mb-0 text-white">Chat with your AI home expert, powered by Google Gemini</div>
        <small class="muted"><?= $u && is_premium($u) ? 'You are Premium. Open your AI chat.' : 'Knows your bookings, quotes fair prices, books in one tap · ₹' . PREMIUM_PRICE . '/month' ?></small></div>
      <span class="btn btn-lime rounded-pill px-4"><?= $u && is_premium($u) ? 'Open chat' : 'Try Premium' ?> <i class="bi bi-arrow-right"></i></span>
    </div>
  </a>

  <h2 class="section-title mt-5">Our services</h2>
  <div class="row g-4">
    <?php foreach (services() as $s): ?>
      <div class="col-sm-6 col-lg">
        <a class="card he-card service-card h-100 p-4 text-decoration-none" href="book.php?service=<?= (int)$s['id'] ?>">
          <span class="icon-circle lime mb-3"><i class="bi <?= e($s['icon']) ?>"></i></span>
          <div class="fw-bold text-dark"><?= e($s['name']) ?></div>
          <small class="text-muted d-block mb-3"><?= e($s['description']) ?></small>
          <div class="mt-auto d-flex justify-content-between align-items-center"><span class="fw-bold">from <?= inr($s['base_price']) ?></span><span class="icon-circle" style="width:34px;height:34px;background:var(--ink);color:var(--lime)"><i class="bi bi-arrow-right"></i></span></div>
        </a>
      </div>
    <?php endforeach; ?>
  </div>

</div>
<?php
$extraScripts = '<script src="assets/js/assistant.js?v=' . @filemtime(__DIR__ . '/assets/js/assistant.js') . '"></script>';  // version stamp so phones never run an old cached copy
include __DIR__ . '/includes/footer.php';
