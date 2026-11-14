<?php
require_once __DIR__ . '/includes/functions.php';
$u = require_login('customer');
sos_watchdog();
shields_expire();

$st = db()->prepare("SELECT b.*, s.name service, s.icon, u.name tech_name, u.phone tech_phone, t.rating tech_rating, t.skill_tier,
        w.code w_code, w.valid_until w_until, w.status w_status,
        (SELECT ss.status FROM safety_shields ss WHERE ss.booking_id = b.id AND ss.technician_id = b.technician_id ORDER BY ss.id DESC LIMIT 1) shield,
        (SELECT COUNT(*) FROM replacement_logs r WHERE r.booking_id = b.id) replaced,
        (SELECT r.reason FROM replacement_logs r WHERE r.booking_id = b.id ORDER BY r.id DESC LIMIT 1) replaced_reason,
        (SELECT n.amount FROM price_negotiations n WHERE n.booking_id = b.id AND n.sender = 'provider' AND n.status = 'proposed' ORDER BY n.id DESC LIMIT 1) tech_offer,
        (SELECT n.fairness FROM price_negotiations n WHERE n.booking_id = b.id AND n.sender = 'provider' AND n.status = 'proposed' ORDER BY n.id DESC LIMIT 1) tech_offer_fair
    FROM bookings b JOIN services s ON s.id = b.service_id
    LEFT JOIN technicians t ON t.id = b.technician_id LEFT JOIN users u ON u.id = t.user_id
    LEFT JOIN warranties w ON w.booking_id = b.id
    WHERE b.customer_id = ?
    ORDER BY FIELD(b.status,'in_progress','accepted','pending','negotiating','completed','cancelled'), b.created_at DESC");
$st->bind_param('i', $u['id']);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$title = 'My bookings';
$live = true;
include __DIR__ . '/includes/header.php';
$steps = array_keys(STATUS_STEPS);
?>
<?php
$isActive = fn($b) => in_array($b['status'], ['negotiating', 'pending', 'accepted', 'in_progress'], true)
    || ($b['status'] === 'completed' && !$b['customer_confirmed'] && $b['escrow_status'] !== 'refunded');
$active = array_values(array_filter($rows, $isActive));
$history = array_values(array_filter($rows, fn($b) => !$isActive($b)));
$tips = ['plumbing' => 'Clear the area under the sink and keep a bucket handy for quick valve access.', 'electrical' => 'Switch off the MCB for that room before the technician arrives if it is safe to do so.',
    'cleaning' => 'Move fragile items off shelves and tables to speed up the deep clean.', 'ac-servicing' => 'Keep a stool ready and clear space under the indoor unit.',
    'appliance-repair' => 'Keep the model number and purchase bill ready if the appliance is under brand warranty.'];
$slugOf = []; foreach (services() as $sv) $slugOf[$sv['name']] = $sv['slug'];
?>
<div class="container py-4" style="max-width:980px">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="fw-bold mb-0" style="font-size:2rem">My Bookings</h1>
    <?php $inflight = count(array_filter($active, fn($b) => in_array($b['status'], ['accepted', 'in_progress']))); if ($inflight): ?><span class="pill pill-lime"><span class="dot" style="background:var(--ink)"></span> <?= $inflight ?> In-Flight</span><?php endif; ?>
  </div>
  <div class="d-flex flex-wrap justify-content-between gap-2 mb-4">
    <div class="tabs-soft"><button class="active" data-tab="active">Active<span class="count"><?= count($active) ?></span></button><button data-tab="history">Completed<span class="count"><?= count($history) ?></span></button></div>
    <a href="book.php" class="btn btn-coral rounded-pill px-3"><i class="bi bi-plus-lg"></i> New booking</a>
  </div>

  <div id="tab-active">
  <?php if (!$active): ?><div class="he-card p-5 text-center text-muted">No active bookings. <a href="index.php">Ask the AI assistant</a> to get started.</div><?php endif; ?>
  <?php foreach ($active as $b):
      [$el, $ec] = ESCROW_LABEL[$b['escrow_status']];
      $stepKeys = ['pending', 'accepted', 'in_progress', 'completed'];
      $cur = array_search($b['status'], $stepKeys, true);
      $isSos = $b['urgency'] === 'sos'; ?>
    <div class="glow-pink p-4 mb-3 <?= $isSos ? 'border border-2 border-danger' : '' ?>" id="b<?= $b['id'] ?>">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-2">
        <span class="pill <?= $isSos ? 'pill-pink' : 'pill-lime' ?>"><i class="bi <?= $isSos ? 'bi-exclamation-triangle-fill' : 'bi-lightning-charge-fill' ?>"></i> <?= e($b['service']) ?> #<?= $b['id'] ?></span>
        <?php if ($b['urgency'] !== 'normal' && !$isSos): ?><span class="pill pill-pink"><?= e(URGENCY_LABEL[$b['urgency']]) ?></span><?php endif; ?>
        <span class="ms-auto small fw-semibold"><span class="dot me-1"></span><?= $b['status'] === 'negotiating' ? 'Negotiating' : ($isSos ? 'SOS dispatch' : 'Live dispatch') ?> · <?= inr($b['price_total']) ?></span>
      </div>
      <div class="d-flex justify-content-between gap-3">
        <div><div class="h4 fw-bold mb-1"><?= e($b['description']) ?></div>
          <div class="small text-muted"><?= date('D, d M · g:i A', strtotime($b['scheduled_at'])) ?> · <?= e($b['area']) ?>
            <?php if ($b['negotiated_price'] !== null): ?> · negotiated & locked<?php elseif ($b['est_min'] && $b['status'] !== 'negotiating'): ?> · fair range <?= inr($b['est_min']) ?>–<?= inr($b['est_max']) ?><?php endif; ?></div></div>
        <span class="icon-circle" style="background:#fff"><i class="bi <?= e($b['icon']) ?>"></i></span>
      </div>

      <?php if ($b['status'] === 'negotiating'): ?>
        <div class="bg-white rounded-4 p-3 mt-3 d-flex flex-wrap align-items-center gap-2">
          <?php if ($b['tech_offer']): [$fl, $fc] = FAIRNESS_LABEL[$b['tech_offer_fair']]; ?>
            <span>Technician quoted <b><?= inr($b['tech_offer']) ?></b></span><span class="badge rounded-pill text-bg-<?= $fc ?>"><?= $fl ?></span>
          <?php else: ?><span class="text-muted">Waiting for the technician's quote.</span><?php endif; ?>
          <a class="btn btn-coral rounded-pill ms-auto" href="negotiate.php?id=<?= $b['id'] ?>"><i class="bi bi-chat-left-dots"></i> Open negotiation</a>
        </div>
      <?php else: ?>
        <div class="stepper mt-3">
          <?php foreach (['Requested', 'Accepted', 'In progress', 'Complete'] as $i => $label): ?>
            <div class="st <?= $i < $cur || ($b['status'] === 'completed') ? 'done' : ($i === $cur ? 'current' : '') ?>"><div class="circle"><i class="bi <?= $i === $cur && $b['status'] !== 'completed' ? ($i === 1 ? 'bi-bicycle' : 'bi-tools') : 'bi-check-lg' ?>"></i></div><?= $label ?></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if ($b['tech_name']): ?>
        <div class="bg-white rounded-pill p-2 ps-3 mt-3 d-flex align-items-center gap-3">
          <span class="avatar sm"><?= e(mb_substr($b['tech_name'], 0, 1)) ?></span>
          <div class="flex-grow-1"><div class="fw-bold"><?= e($b['tech_name']) ?> <i class="bi bi-patch-check-fill text-mint"></i></div>
            <small class="text-muted"><i class="bi bi-star-fill text-warning"></i> <?= number_format((float)$b['tech_rating'], 1) ?> · <?= e(TIER_LABEL[$b['skill_tier']]) ?><?= in_array($b['shield'], ['outbound', 'on_job', 'return'], true) ? ' · <i class="bi bi-shield-check text-mint"></i> Safety Shield' : '' ?></small></div>
          <?php if (in_array($b['status'], ['accepted', 'in_progress'])): ?>
            <a class="icon-circle text-decoration-none" style="background:#F1F2EE" href="tel:<?= e($b['tech_phone']) ?>" title="Call"><i class="bi bi-telephone"></i></a>
            <a class="icon-circle text-decoration-none" style="background:var(--ink);color:#fff" href="sms:<?= e($b['tech_phone']) ?>" title="Message"><i class="bi bi-chat-fill"></i></a>
          <?php endif; ?>
        </div>
      <?php elseif ($b['status'] !== 'completed'): ?>
        <div class="small text-danger mt-3"><i class="bi bi-hourglass-split"></i> AI is finding a replacement technician…</div>
      <?php endif; ?>
      <?php if ($b['replaced']): ?><div class="small mt-2"><i class="bi bi-arrow-repeat"></i> Auto-replaced by AI (<?= e($b['replaced_reason']) ?>). Price and escrow kept.</div><?php endif; ?>

      <div class="d-flex flex-wrap gap-2 align-items-center mt-3">
        <?php if ($b['status'] !== 'negotiating'): ?><span class="badge rounded-pill text-bg-<?= $ec ?>"><i class="bi bi-shield-lock"></i> <?= $el ?></span><?php endif; ?>
        <?php if ($b['ai_source'] !== 'manual'): ?><span class="badge rounded-pill text-bg-light"><i class="bi bi-magic"></i> AI <?= $b['ai_source'] ?> diagnosis</span><?php endif; ?>
        <div class="ms-auto d-flex gap-2">
          <?php if ($b['status'] === 'pending' && $b['escrow_status'] === 'unpaid'): ?>
            <form method="post" action="booking_action.php"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="action" value="pay">
              <button class="btn btn-coral rounded-pill"><i class="bi bi-lock-fill"></i> Pay <?= inr($b['price_total']) ?> into escrow</button></form>
          <?php endif; ?>
          <?php if (in_array($b['status'], ['negotiating', 'pending', 'accepted'], true)): ?>
            <form method="post" action="booking_action.php" onsubmit="return confirm('Cancel this booking?')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="action" value="cancel">
              <button class="btn btn-soft rounded-pill">Cancel</button></form>
          <?php endif; ?>
        </div>
      </div>

      <?php if ($b['status'] === 'completed' && $b['escrow_status'] === 'held'): ?>
        <div class="bg-white rounded-4 p-3 mt-3">
          <div class="fw-semibold mb-2"><i class="bi bi-check2-circle text-mint"></i> The technician marked this job complete. Is everything fixed?</div>
          <form method="post" action="booking_action.php" class="d-flex flex-wrap gap-2 align-items-center">
            <?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>">
            <div class="stars"><?php for ($s = 5; $s >= 1; $s--): ?><input type="radio" name="rating" id="r<?= $b['id'] . $s ?>" value="<?= $s ?>" <?= $s === 5 ? 'checked' : '' ?>><label for="r<?= $b['id'] . $s ?>">★</label><?php endfor; ?></div>
            <input class="form-control form-control-sm" style="max-width:240px" name="review" placeholder="Short review (optional)" maxlength="300">
            <button name="action" value="confirm" class="btn btn-sm btn-coral rounded-pill">Confirm & release payment</button>
            <button name="action" value="dispute" class="btn btn-sm btn-soft rounded-pill" onclick="return confirm('Raise a dispute? Payment stays frozen until admin reviews it.')">Raise dispute</button>
          </form>
        </div>
      <?php elseif ($b['escrow_status'] === 'disputed'): ?>
        <div class="small text-danger mt-3"><i class="bi bi-exclamation-octagon"></i> Dispute under admin review. Payment is frozen.</div>
      <?php endif; ?>
    </div>

    <?php if (in_array($b['status'], ['pending', 'accepted'], true) && $b['escrow_status'] === 'held'): ?>
      <div class="he-card otp-card p-4 mb-3">
        <div class="d-flex align-items-center gap-2 mb-3"><span class="icon-circle lime"><i class="bi bi-shield-check"></i></span><div class="fw-bold fs-5 flex-grow-1">Start-Job OTP Security</div>
          <button class="btn btn-sm btn-soft rounded-pill" onclick="navigator.clipboard && navigator.clipboard.writeText('<?= e($b['otp']) ?>'); this.innerHTML='<i class=\'bi bi-check2\'></i> Copied'"><i class="bi bi-copy"></i> Copy</button></div>
        <div class="otp-digits mb-3"><?php foreach (str_split($b['otp']) as $dg): ?><span><?= e($dg) ?></span><?php endforeach; ?></div>
        <div class="inset p-3 small d-flex gap-2"><i class="bi bi-lock"></i><span>Share this 6-digit code <b>only</b> when <?= e(strtok($b['tech_name'] ?? 'the technician', ' ')) ?> reaches your doorstep and is ready to start.</span></div>
      </div>
      <div class="he-card p-3 px-4 mb-4 d-flex gap-3 align-items-center">
        <span class="icon-circle lime"><i class="bi bi-lightbulb"></i></span>
        <div><div class="fw-bold">Quick Pro Tip</div><small class="text-muted"><?= e($tips[$slugOf[$b['service']] ?? ''] ?? 'Keep the work area clear for a faster visit.') ?></small></div>
      </div>
    <?php endif; ?>
  <?php endforeach; ?>
  </div>

  <div id="tab-history" class="d-none">
    <div class="d-flex justify-content-between mb-2"><div class="fw-bold fs-5">Service History</div><small class="text-muted"><?= count($history) ?> bookings</small></div>
    <?php foreach ($history as $b): $warrantyLive = $b['w_code'] && $b['w_status'] === 'active' && strtotime($b['w_until']) >= strtotime('today'); ?>
      <div class="he-card p-3 px-4 mb-3" id="b<?= $b['id'] ?>">
        <div class="d-flex align-items-center gap-3">
          <span class="icon-circle"><i class="bi <?= e($b['icon']) ?>"></i></span>
          <div class="flex-grow-1"><div class="fw-bold"><?= e($b['service']) ?> #<?= $b['id'] ?></div><small class="text-muted"><?= e($b['description']) ?></small></div>
          <div class="text-end"><div class="fw-bold fs-5"><?= inr($b['price_total']) ?></div><small class="text-muted"><?= date('d M', strtotime($b['completed_at'] ?? $b['created_at'])) ?></small></div>
        </div>
        <div class="d-flex flex-wrap gap-2 align-items-center mt-2">
          <?php if ($b['status'] === 'cancelled'): ?><span class="badge rounded-pill text-bg-secondary">Cancelled<?= $b['escrow_status'] === 'refunded' ? ' · refunded' : '' ?></span>
          <?php elseif ($b['escrow_status'] === 'refunded'): ?><span class="badge rounded-pill text-bg-secondary">Dispute settled · refunded</span><?php endif; ?>
          <?php if ($b['rating']): ?><span class="small"><i class="bi bi-emoji-smile"></i> <span class="text-warning"><?= str_repeat('★', (int)$b['rating']) ?></span><?= $b['review'] ? ' <i class="text-muted">“' . e($b['review']) . '”</i>' : '' ?></span><?php endif; ?>
          <?php if ($warrantyLive): ?><span class="pill pill-lime"><i class="bi bi-patch-check"></i> 30-Day Warranty Active</span>
          <?php elseif ($b['w_status'] === 'claimed'): ?><span class="pill">Warranty claimed</span><?php endif; ?>
          <div class="ms-auto d-flex gap-2">
            <?php if ($warrantyLive): ?>
              <form method="post" action="booking_action.php" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="action" value="warranty_claim">
                <input type="hidden" name="details" value="Problem came back">
                <button class="btn btn-sm btn-soft rounded-pill" onclick="return confirm('Book a free follow-up visit under warranty?')">Free rework</button></form>
              <a class="btn btn-sm btn-soft rounded-pill" href="warranty.php?code=<?= e($b['w_code']) ?>" target="_blank"><i class="bi bi-patch-check"></i> Warranty</a>
            <?php endif; ?>
            <?php if ($b['status'] === 'completed'): ?><a class="btn btn-sm btn-soft rounded-pill" href="invoice.php?id=<?= $b['id'] ?>" target="_blank"><i class="bi bi-receipt"></i> Invoice</a><?php endif; ?>
          </div>
        </div>
      </div>
    <?php endforeach; ?>
  </div>
</div>
<script>
document.querySelectorAll('.tabs-soft button').forEach(btn => btn.onclick = () => {
  document.querySelectorAll('.tabs-soft button').forEach(x => x.classList.toggle('active', x === btn));
  document.getElementById('tab-active').classList.toggle('d-none', btn.dataset.tab !== 'active');
  document.getElementById('tab-history').classList.toggle('d-none', btn.dataset.tab !== 'history');
});
const h = location.hash && document.querySelector(location.hash);
if (h && h.closest('#tab-history')) document.querySelector('[data-tab=history]').click();
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
