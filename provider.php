<?php
require_once __DIR__ . '/includes/functions.php';
$u = require_login('provider');
$tid = (int)$u['tech_id'];
sos_watchdog();
shields_expire();

$st = db()->prepare("SELECT t.*, GROUP_CONCAT(s.name SEPARATOR ', ') skills FROM technicians t
    JOIN technician_services ts ON ts.technician_id = t.id JOIN services s ON s.id = ts.service_id WHERE t.id = ? GROUP BY t.id");
$st->bind_param('i', $tid);
$st->execute();
$me = $st->get_result()->fetch_assoc();

$st = db()->prepare("SELECT
    SUM(status='completed' AND completed_at >= DATE_FORMAT(NOW(),'%Y-%m-01')) month_jobs,
    COALESCE(SUM(CASE WHEN escrow_status='held' AND status='completed' THEN price_total - price_fee END),0) pending_payout,
    SUM(status IN ('accepted','in_progress')) active,
    SUM(status = 'in_progress') busy
    FROM bookings WHERE technician_id = ?");
$st->bind_param('i', $tid);
$st->execute();
$k = $st->get_result()->fetch_assoc();

function jobs(int $tid, string $where, string $order = 'ASC', int $limit = 50): array {
    $st = db()->prepare("SELECT b.*, s.name service, s.icon, u.name cust, u.phone cust_phone,
        (SELECT ss.status FROM safety_shields ss WHERE ss.booking_id = b.id AND ss.technician_id = b.technician_id ORDER BY ss.id DESC LIMIT 1) shield,
        (SELECT ss.policy_no FROM safety_shields ss WHERE ss.booking_id = b.id AND ss.technician_id = b.technician_id ORDER BY ss.id DESC LIMIT 1) policy,
        (SELECT n.amount FROM price_negotiations n WHERE n.booking_id = b.id AND n.sender = 'customer' AND n.status = 'proposed' ORDER BY n.id DESC LIMIT 1) cust_offer
        FROM bookings b JOIN services s ON s.id = b.service_id JOIN users u ON u.id = b.customer_id
        WHERE b.technician_id = ? AND $where ORDER BY FIELD(b.urgency,'sos','emergency','same_day','normal'), b.scheduled_at $order LIMIT $limit");
    $st->bind_param('i', $tid);
    $st->execute();
    return $st->get_result()->fetch_all(MYSQLI_ASSOC);
}
$negs = jobs($tid, "b.status = 'negotiating'");
$new = jobs($tid, "b.status = 'pending'");
$active = jobs($tid, "b.status IN ('accepted','in_progress')");
$done = jobs($tid, "b.status = 'completed'", 'DESC', 8);
$myZone = zones()[$me['area']] ?? null;
// Demand radar: peak booking window for my trades + hot zones + urgent requests near me
$st = db()->prepare("SELECT HOUR(b.created_at) h, COUNT(*) c FROM bookings b JOIN technician_services ts ON ts.service_id = b.service_id AND ts.technician_id = ?
    WHERE b.created_at >= NOW() - INTERVAL 30 DAY GROUP BY h");
$st->bind_param('i', $tid); $st->execute();
$hrs = array_fill(0, 24, 0); foreach ($st->get_result()->fetch_all(MYSQLI_ASSOC) as $r) $hrs[(int)$r['h']] = (int)$r['c'];
$bestH = 9; $bestV = -1; for ($h = 7; $h <= 20; $h++) { $v = $hrs[$h] + $hrs[$h + 1] + $hrs[$h + 2]; if ($v > $bestV) { $bestV = $v; $bestH = $h; } }
$hot = array_keys(array_filter(zones(), fn($z) => $z['zone'] !== 'green'));
usort($hot, fn($a, $b) => distance_km($me['area'], $a) <=> distance_km($me['area'], $b));
$st = db()->prepare("SELECT b.area, b.urgency FROM bookings b JOIN technician_services ts ON ts.service_id = b.service_id AND ts.technician_id = ?
    WHERE b.status = 'pending' AND b.urgency <> 'normal'");
$st->bind_param('i', $tid); $st->execute();
$urgentNear = count(array_filter($st->get_result()->fetch_all(MYSQLI_ASSOC), fn($r) => distance_km($me['area'], $r['area']) <= 8));
$monthJobs = (int)$k['month_jobs'];
$reviews = (int)db()->query("SELECT COUNT(*) c FROM bookings WHERE technician_id = $tid AND rating IS NOT NULL")->fetch_assoc()['c'];
$sosInfo = $_SESSION['sos_info'] ?? null;
unset($_SESSION['sos_info']);

$title = 'My jobs';
$live = true;
include __DIR__ . '/includes/header.php';
$liveStatus = !$me['available'] ? ['Offline', 'btn-outline-secondary'] : ($k['busy'] ? ['Online · Currently busy', 'btn-warning'] : ['Online, taking jobs', 'btn-success']);
?>
<div class="container py-4">
  <?php if ($sosInfo): ?>
    <div class="alert alert-danger rounded-4">
      <div class="fw-bold fs-5"><i class="bi bi-exclamation-octagon-fill"></i> SOS active for job #<?= (int)$sosInfo['booking'] ?></div>
      <div>HomeEase admin and your emergency contact (<?= e($me['emergency_phone']) ?>) have been alerted. You are set offline.</div>
      <div>Nearest hospital: <b><?= e($sosInfo['hospital']) ?></b> · Ambulance: <b>108</b></div>
      <div class="small mt-1"><?= $sosInfo['replacement'] ? 'The customer has been sent a replacement: ' . e($sosInfo['replacement']) . '.' : 'Admin is assigning a replacement for the customer.' ?> Your Safety Shield insurance claim can be raised with admin.</div>
    </div>
  <?php endif; ?>

  <div class="d-flex flex-wrap justify-content-between align-items-start gap-3 mb-3">
    <div>
      <h1 class="fw-bold mb-0" style="font-size:2.1rem">Hi <?= e(strtok($u['name'], ' ')) ?>! 👋</h1>
      <small class="text-muted"><?= e($me['skills']) ?> Specialist · <?= e($me['area']) ?> · <?= (int)$me['experience_years'] ?> yrs exp</small>
      <div class="mt-1"><span class="pill" style="background:#fff"><i class="bi bi-award"></i> <?= e(TIER_LABEL[$me['skill_tier']]) ?> · quiz <?= (int)$me['quiz_score'] ?>%</span>
        <?php if ($me['doc_verified']): ?><span class="pill" style="background:#fff"><i class="bi bi-patch-check-fill text-mint"></i> Documents verified</span><?php endif; ?></div>
    </div>
    <form method="post" action="booking_action.php">
      <?= csrf_field() ?><input type="hidden" name="action" value="availability">
      <button class="btn rounded-pill bg-white shadow-sm fw-semibold px-3"><span class="dot me-1" style="<?= $me['available'] ? '' : 'background:#B8BCB2;box-shadow:none' ?><?= $me['available'] && $k['busy'] ? 'background:#F4B942' : '' ?>"></span> <?= $liveStatus[0] ?></button>
    </form>
  </div>

  <div class="row g-3 mb-3">
    <?php foreach ([
        ['bi-star', number_format((float)$me['rating'], 2), "Rating ($reviews reviews)", (float)$me['rating'] >= 4.7 ? 'Top Rated' : null, true],
        ['bi-patch-check', (int)$me['jobs_done'], 'Jobs completed', $monthJobs . ' this month', false],
        ['bi-briefcase', (int)$k['active'], 'Assigned tasks', $k['busy'] ? 'Busy' : 'Active', $k['active'] > 0],
        ['bi-wallet2', inr($me['wallet_balance']), 'Wallet (released payouts)', $k['pending_payout'] ? '+' . inr($k['pending_payout']) . ' pending' : null, true],
    ] as [$ic, $val, $lbl, $chip, $lime]): ?>
      <div class="col-6 col-lg-3"><div class="stat-card">
        <div class="d-flex justify-content-between align-items-start"><span class="icon-circle"><i class="bi <?= $ic ?>"></i></span>
          <?php if ($chip): ?><span class="pill <?= $lime ? 'pill-lime' : '' ?>"><?= e($chip) ?></span><?php endif; ?></div>
        <div class="val"><?= $val ?></div><small class="text-muted"><?= e($lbl) ?></small>
      </div></div>
    <?php endforeach; ?>
  </div>

  <div class="radar mb-4">
    <div class="d-flex justify-content-between align-items-center mb-2"><span class="small fw-bold" style="letter-spacing:1.5px"><i class="bi bi-broadcast-pin" style="color:var(--lime)"></i> DEMAND RADAR</span>
      <?php if ($myZone && $myZone['bonus']): ?><span class="pill pill-lime">+<?= inr($myZone['bonus']) ?> Surge</span><?php endif; ?></div>
    <div class="h4 fw-bold mb-1">Peak booking hours: <?= date('g:i A', mktime($bestH, 0)) ?> – <?= date('g:i A', mktime($bestH + 3, 0)) ?></div>
    <div class="muted small mb-2"><?= $hot ? 'High demand in ' . e(implode(' & ', array_slice($hot, 0, 2))) . ' (surge zones)' : 'Demand is normal across Bengaluru right now' ?>.
      <?= $myZone ? 'Your area ' . e($me['area']) . ' is a ' . ucfirst($myZone['zone']) . ' zone.' : '' ?></div>
    <div class="small"><i class="bi bi-lightning-charge-fill" style="color:var(--lime)"></i> <?= $urgentNear ?> urgent request<?= $urgentNear === 1 ? '' : 's' ?> active within 8 km</div>
  </div>

  <?php if ($negs): ?>
    <div class="d-flex align-items-center gap-2 mb-2"><div class="fw-bold fs-4">Price Negotiations</div><span class="pill pill-dark"><?= count($negs) ?></span></div>
    <?php foreach ($negs as $b): ?>
      <div class="job-card mb-3"><div class="d-flex flex-wrap justify-content-between gap-3 align-items-center">
        <div><div class="d-flex gap-2 align-items-center mb-1"><b>#<?= $b['id'] ?></b><span class="pill"><?= e($b['service']) ?></span></div>
          <div class="fw-bold fs-5"><?= e($b['description']) ?></div>
          <small class="text-muted"><i class="bi bi-person"></i> <?= e($b['cust']) ?> · <?= e($b['area']) ?> · AI fair range <?= inr($b['est_min']) ?>–<?= inr($b['est_max']) ?><?= $b['cust_offer'] ? ' · <b class="text-dark">customer offered ' . inr($b['cust_offer']) . '</b>' : '' ?></small></div>
        <a href="negotiate.php?id=<?= $b['id'] ?>" class="btn btn-coral rounded-pill px-4"><?= $b['cust_offer'] ? 'Respond' : 'View / update quote' ?></a>
      </div></div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div class="d-flex align-items-center gap-2 mb-2 mt-2"><div class="fw-bold fs-4">Live Dispatch Queue</div><span class="pill pill-dark"><?= count($new) ?> New</span>
    <small class="ms-auto fw-semibold"><span class="dot me-1"></span>Auto-matching</small></div>
  <?php if (!$new): ?><p class="text-muted small">No new requests right now.</p><?php endif; ?>
  <?php foreach ($new as $b): $paid = $b['escrow_status'] === 'held'; $km = distance_km($me['area'], $b['area']); ?>
    <div class="job-card mb-3 <?= $b['urgency'] === 'sos' ? 'sos' : '' ?>">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><b>#<?= $b['id'] ?></b><span class="pill"><?= e($b['service']) ?></span>
        <?php if ($b['parent_booking_id']): ?><span class="pill pill-soft">Warranty follow-up</span><?php endif; ?>
        <span class="ms-auto pill <?= in_array($b['urgency'], ['emergency', 'sos']) ? 'pill-pink' : '' ?>"><i class="bi <?= ['normal' => 'bi-clock', 'same_day' => 'bi-lightning-charge', 'emergency' => 'bi-alarm', 'sos' => 'bi-exclamation-triangle-fill'][$b['urgency']] ?>"></i> <?= e(URGENCY_LABEL[$b['urgency']]) ?></span></div>
      <div class="fw-bold fs-5 mb-1"><?= e($b['description']) ?></div>
      <div class="small text-muted mb-3"><i class="bi bi-person"></i> <?= e($b['cust']) ?> · <i class="bi bi-send"></i> <?= e($b['area']) ?> (<?= $km ?> km away) · <?= date('D d M, g:i A', strtotime($b['scheduled_at'])) ?></div>
      <div class="d-flex justify-content-between align-items-end mb-3">
        <div class="d-flex flex-wrap gap-2"><span class="match-chip"><i class="bi bi-lightning-charge-fill"></i> <?= (int)$b['match_score'] ?>/100 AI Match</span>
          <span class="pill <?= $paid ? '' : 'pill-pink' ?>"><i class="bi bi-shield-lock"></i> <?= $paid ? 'Paid into escrow' : 'Awaiting payment' ?></span></div>
        <div class="text-end"><div class="price-big"><?= inr(max(0, $b['price_total'] - $b['price_fee'])) ?></div><small class="text-muted">Net payout</small></div>
      </div>
      <div class="d-flex gap-2">
        <form method="post" action="booking_action.php" class="flex-fill"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="action" value="decline"><button class="btn btn-soft btn-lg rounded-pill w-100">Decline</button></form>
        <form method="post" action="booking_action.php" class="flex-fill" style="flex-grow:2!important"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="action" value="accept">
          <button class="btn btn-coral btn-lg rounded-pill w-100" <?= $paid ? '' : 'disabled' ?>>Accept Job <i class="bi bi-lightning-charge-fill"></i></button></form>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="fw-bold fs-4 mb-2 mt-4" id="active">Active Jobs</div>
  <?php if (!$active): ?><p class="text-muted small">Nothing in progress.</p><?php endif; ?>
  <?php foreach ($active as $b): ?>
    <div class="job-card mb-3">
      <div class="d-flex flex-wrap align-items-center gap-2 mb-2"><b>#<?= $b['id'] ?></b><span class="pill"><?= e($b['service']) ?></span>
        <span class="ms-auto badge rounded-pill text-bg-<?= STATUS_BADGE[$b['status']] ?>"><?= STATUS_LABEL[$b['status']] ?></span></div>
      <div class="fw-bold fs-5 mb-1"><?= e($b['description']) ?></div>
      <div class="small text-muted"><i class="bi bi-geo-alt"></i> <?= e($b['address']) ?> · <i class="bi bi-telephone"></i> <?= e($b['cust_phone']) ?> (<?= e($b['cust']) ?>)</div>
      <?php if ($b['shield']): ?><div class="small mt-1"><span class="pill pill-soft"><i class="bi bi-shield-check"></i> Safety Shield · <?= e(SHIELD_LABEL[$b['shield']]) ?></span> <small class="text-muted">policy <?= e($b['policy']) ?></small></div><?php endif; ?>
      <div class="d-flex flex-wrap gap-2 mt-3">
        <?php if ($b['status'] === 'accepted'): ?>
          <form method="post" action="booking_action.php" class="d-flex gap-2 flex-grow-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="action" value="start">
            <input class="form-control" name="otp" placeholder="Customer's 6-digit OTP" maxlength="6" inputmode="numeric" required><button class="btn btn-coral rounded-pill px-4 text-nowrap">Start job</button></form>
          <form method="post" action="booking_action.php" onsubmit="return confirm('Cancel this job? AI will hand it to another technician.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="action" value="cancel"><button class="btn btn-soft rounded-pill">Cancel</button></form>
        <?php else: ?>
          <form method="post" action="booking_action.php" class="flex-grow-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="action" value="complete"><button class="btn btn-lime rounded-pill w-100"><i class="bi bi-check2"></i> Mark complete</button></form>
        <?php endif; ?>
        <form method="post" action="booking_action.php" onsubmit="return confirm('Send SOS? Admin and your emergency contact will be alerted and the job will be reassigned.')"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $b['id'] ?>"><input type="hidden" name="action" value="sos"><button class="btn btn-danger rounded-pill"><i class="bi bi-exclamation-triangle-fill"></i> SOS</button></form>
      </div>
    </div>
  <?php endforeach; ?>

  <div class="fw-bold fs-4 mb-2 mt-4" id="done">Recently completed</div>
  <div class="he-card p-0 overflow-hidden">
    <table class="table table-sm mb-0 align-middle small">
      <thead class="table-light"><tr><th class="ps-3">#</th><th>Service</th><th>Customer</th><th>Completed</th><th>Rating</th><th>Payment</th><th class="text-end pe-3">Payout</th></tr></thead>
      <tbody>
      <?php foreach ($done as $b): [$el, $ec] = ESCROW_LABEL[$b['escrow_status']]; ?>
        <tr><td class="ps-3"><?= $b['id'] ?></td><td><?= e($b['service']) ?></td><td><?= e($b['cust']) ?></td><td><?= date('d M', strtotime($b['completed_at'])) ?></td>
          <td class="text-warning"><?= $b['rating'] ? str_repeat('★', (int)$b['rating']) : '<span class="text-muted">-</span>' ?></td>
          <td><span class="badge rounded-pill text-bg-<?= $ec ?>"><?= $el ?></span></td><td class="text-end pe-3"><?= inr(max(0, $b['price_total'] - $b['price_fee'])) ?></td></tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
