<?php
// Customer Emergency SOS: one tap, life-safety instructions, SOS Critical dispatch of the nearest qualified technician.
require_once __DIR__ . '/includes/functions.php';
if (!user()) { $_SESSION['after_login'] = 'sos.php'; flash('Log in to send an Emergency SOS.', 'warning'); redirect('login.php'); }
$u = require_login('customer');
$db = db();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $hz = $_POST['hazard'] ?? '';
    $area = $_POST['area'] ?? '';
    $addr = mb_substr(trim($_POST['address'] ?? ''), 0, 200);
    if (!isset(SOS_HAZARDS[$hz], AREAS[$area]) || $addr === '') { flash('Choose the emergency and enter your address.', 'warning'); redirect('sos.php?hazard=' . urlencode($hz)); }
    [$label, $slug] = SOS_HAZARDS[$hz];
    $svc = service_by_slug($slug);
    $sid = (int)$svc['id'];
    $now = date('Y-m-d H:i:s');
    $p = estimate_price($sid, 'sos', $now, $area);
    $tech = match_technicians($sid, $area, 'sos', 1, $now)[0] ?? null;
    $tid = $tech ? (int)$tech['id'] : null;
    $score = $tech ? (int)$tech['score'] : null;
    $desc = 'SOS: ' . $label . (trim($_POST['details'] ?? '') !== '' ? ' - ' . mb_substr(trim($_POST['details']), 0, 200) : '');
    $otp = new_otp();
    $st = $db->prepare("INSERT INTO bookings (customer_id, service_id, technician_id, area, address, description, urgency, scheduled_at,
        est_min, est_max, price_base, price_urgency, price_peak, price_demand, price_zone, price_fee, price_total, price_locked, match_score, ai_source,
        status, escrow_status, otp) VALUES (?,?,?,?,?,?,'sos',?,?,?,?,?,?,?,?,?,?,1,?,'manual','pending','held',?)");
    $st->bind_param('iiissssiiiiiiiiiis', $u['id'], $sid, $tid, $area, $addr, $desc, $now, $p['min'], $p['max'], $p['base'], $p['urgency'],
        $p['peak'], $p['demand'], $p['zone_bonus'], $p['fee'], $p['total'], $score, $otp);
    $st->execute();
    redirect('sos.php?id=' . $db->insert_id);
}

$id = (int)($_GET['id'] ?? 0);
$b = null;
if ($id) {
    sos_watchdog();
    $st = $db->prepare("SELECT b.*, s.name service, tu.name tech, tu.phone tech_phone, t.area tech_area FROM bookings b JOIN services s ON s.id = b.service_id
        LEFT JOIN technicians t ON t.id = b.technician_id LEFT JOIN users tu ON tu.id = t.user_id WHERE b.id = ? AND b.customer_id = ? AND b.urgency = 'sos'");
    $st->bind_param('ii', $id, $u['id']);
    $st->execute();
    $b = $st->get_result()->fetch_assoc();
}
$hzKey = $_GET['hazard'] ?? '';
if ($b) foreach (SOS_HAZARDS as $k => $h) if (str_starts_with($b['description'], 'SOS: ' . $h[0])) $hzKey = $k;

$title = 'Emergency SOS';
$live = (bool)$b; // only on the live tracking view
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5" style="max-width:900px">
<?php if ($b): [$label, , $steps] = SOS_HAZARDS[$hzKey] ?? SOS_HAZARDS['electric']; ?>
  <div class="sos-hero p-4 rounded-4 text-white mb-4">
    <div class="d-flex align-items-center gap-3"><i class="bi bi-exclamation-triangle-fill display-5"></i>
      <div><div class="small text-uppercase fw-bold opacity-75">SOS Critical · #<?= $b['id'] ?></div><h1 class="h3 fw-bold mb-0"><?= e($label) ?></h1>
        <div class="small">HomeEase admin has been alerted. Your request skips the normal queue.</div></div></div>
  </div>
  <div class="row g-4">
    <div class="col-md-7">
      <div class="card he-card p-4 h-100">
        <div class="fw-bold mb-3 text-danger"><i class="bi bi-shield-exclamation"></i> Do this now</div>
        <ol class="sos-steps mb-0"><?php foreach ($steps as $s): ?><li><?= e($s) ?></li><?php endforeach; ?></ol>
        <div class="small text-muted mt-3">National emergency number: <b>112</b> · Fire: <b>101</b> · Ambulance: <b>108</b></div>
      </div>
    </div>
    <div class="col-md-5">
      <div class="card he-card p-4 h-100">
        <div class="fw-bold mb-3"><i class="bi bi-truck text-teal"></i> Technician dispatched</div>
        <?php if ($b['tech']): ?>
          <div class="d-flex align-items-center gap-3 mb-3"><span class="avatar"><?= e(mb_substr($b['tech'], 0, 1)) ?></span>
            <div><div class="fw-bold"><?= e($b['tech']) ?></div><small class="text-muted"><?= e($b['tech_area']) ?> · ~<?= max(20, (int)round(distance_km($b['area'], $b['tech_area']) * 4)) ?> min away</small></div></div>
          <div class="small mb-1"><i class="bi bi-telephone"></i> <?= e($b['tech_phone']) ?></div>
          <div class="small mb-2">Status: <span class="badge rounded-pill text-bg-<?= STATUS_BADGE[$b['status']] ?>"><?= STATUS_LABEL[$b['status']] ?></span></div>
        <?php else: ?>
          <div class="alert alert-warning small">All nearby technicians are busy. Admin is assigning one manually.</div>
        <?php endif; ?>
        <div class="otp mb-2">Start-job OTP: <b><?= e($b['otp']) ?></b></div>
        <div class="small text-muted">Emergency price <?= inr($b['price_total']) ?>, held in escrow and released only after you confirm. If the technician does not accept within 10 minutes, AI sends the next nearest one.</div>
        <a href="my_bookings.php#b<?= $b['id'] ?>" class="btn btn-outline-dark rounded-pill mt-3">Track in my bookings</a>
      </div>
    </div>
  </div>
<?php else: ?>
  <div class="sos-hero p-4 rounded-4 text-white mb-4">
    <h1 class="h3 fw-bold mb-1"><i class="bi bi-exclamation-triangle-fill"></i> Emergency SOS</h1>
    <div>Tap the emergency. We show safety steps right away and send the nearest qualified technician ahead of the queue.</div>
  </div>
  <form method="post" class="card he-card p-4">
    <?= csrf_field() ?>
    <div class="row g-3 mb-3">
      <div class="col-md-4"><label class="form-label fw-semibold">Area</label>
        <select class="form-select" name="area"><?php foreach (array_keys(AREAS) as $a): ?><option <?= ($u['area'] ?? '') === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-8"><label class="form-label fw-semibold">Address</label><input class="form-control" name="address" required placeholder="House no, street, landmark"></div>
      <div class="col-12"><input class="form-control" name="details" placeholder="Anything else? (optional)" maxlength="200"></div>
    </div>
    <div class="row g-3">
      <?php foreach (SOS_HAZARDS as $k => [$label]): ?>
        <div class="col-md-4"><button name="hazard" value="<?= $k ?>" class="btn btn-danger w-100 p-4 rounded-4 sos-btn <?= $hzKey === $k ? 'ring' : '' ?>">
          <i class="bi <?= ['gas' => 'bi-fire', 'electric' => 'bi-lightning-charge-fill', 'flood' => 'bi-water'][$k] ?> fs-2 d-block mb-1"></i><b><?= e($label) ?></b></button></div>
      <?php endforeach; ?>
    </div>
    <div class="small text-muted mt-3">For fire or injury, call <b>112</b> first.</div>
  </form>
<?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
