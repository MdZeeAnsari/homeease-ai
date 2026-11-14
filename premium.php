<?php
// HomeEase Premium: simulated ₹99/month subscription that unlocks the Gemini AI chat
require_once __DIR__ . '/includes/functions.php';
if (!user()) { $_SESSION['after_login'] = 'premium.php'; flash('Log in to see Premium.', 'info'); redirect('login.php'); }
$u = require_login('customer');
ensure_premium_schema();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (($_POST['action'] ?? '') === 'upgrade') {
        $st = db()->prepare("UPDATE users SET is_premium = 1, premium_until = CURDATE() + INTERVAL 30 DAY WHERE id = ?");
        $st->bind_param('i', $u['id']); $st->execute();
        flash('Welcome to HomeEase Premium! Your AI assistant is ready.');
        redirect('chat.php');
    }
    if (($_POST['action'] ?? '') === 'cancel') {
        $st = db()->prepare("UPDATE users SET is_premium = 0, premium_until = NULL WHERE id = ?");
        $st->bind_param('i', $u['id']); $st->execute();
        unset($_SESSION['chat']);
        flash('Premium cancelled. You can upgrade again anytime.', 'info');
        redirect('premium.php');
    }
}
$pro = is_premium($u);
$st = db()->prepare('SELECT premium_until FROM users WHERE id = ?'); $st->bind_param('i', $u['id']); $st->execute();
$until = $st->get_result()->fetch_assoc()['premium_until'] ?? null;
$title = 'Premium';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-4" style="max-width:920px">
  <div class="text-center mb-4">
    <span class="pill pill-lime"><i class="bi bi-gem"></i> HomeEase Premium</span>
    <h1 class="hero-title mt-3 mb-2" style="font-size:clamp(2rem,4vw,3rem)">Your personal<br><span>AI home expert.</span></h1>
    <p class="text-muted">Chat with an AI assistant powered by Google Gemini that knows your home, your bookings and fair prices in your area.</p>
  </div>
  <div class="row g-4 align-items-stretch">
    <div class="col-md-7">
      <div class="glow-pink p-4 h-100">
        <div class="fw-bold fs-5 mb-3">What you get</div>
        <?php foreach ([['bi-chat-heart', 'AI chat assistant, 24×7', 'Ask anything about a home problem in English, Hindi or Hinglish'],
                        ['bi-calendar2-check', 'Knows your bookings', '"When is my technician coming?" gets a real answer'],
                        ['bi-cash-coin', 'Fair price in every answer', 'Quotes only the AI fair range for your area'],
                        ['bi-lightning-charge', 'One-tap booking', 'Every diagnosis comes with a Book button'],
                        ['bi-shield-exclamation', 'Safety first', 'Spots gas, sparks and flooding and sends you to SOS']] as [$i, $t, $d]): ?>
          <div class="d-flex gap-3 mb-3"><span class="icon-circle" style="background:#fff"><i class="bi <?= $i ?>"></i></span>
            <div><div class="fw-bold"><?= $t ?></div><small class="text-muted"><?= $d ?></small></div></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-md-5">
      <div class="he-card p-4 h-100 d-flex flex-column">
        <div class="eyebrow-label">Premium plan</div>
        <div class="d-flex align-items-end gap-1 my-2"><span class="price-big" style="font-size:2.6rem">₹<?= PREMIUM_PRICE ?></span><span class="text-muted mb-1">/ month</span></div>
        <small class="text-muted mb-3">Cancel anytime. Demo payment, no real money is charged.</small>
        <?php if ($pro): ?>
          <div class="alert alert-mint mb-3"><i class="bi bi-patch-check-fill"></i> You're Premium<?= $until ? ' until ' . date('d M Y', strtotime($until)) : '' ?>.</div>
          <a href="chat.php" class="btn btn-coral btn-lg rounded-pill w-100 mb-2"><i class="bi bi-chat-dots"></i> Open AI chat</a>
          <form method="post" class="mt-auto"><?= csrf_field() ?><input type="hidden" name="action" value="cancel"><button class="btn btn-link btn-sm text-muted w-100">Cancel Premium</button></form>
        <?php else: ?>
          <form method="post" class="mt-auto"><?= csrf_field() ?><input type="hidden" name="action" value="upgrade">
            <button class="btn btn-coral btn-lg rounded-pill w-100"><i class="bi bi-gem"></i> Upgrade for ₹<?= PREMIUM_PRICE ?></button></form>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
