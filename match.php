<?php
require_once __DIR__ . '/includes/functions.php';
$u = require_login('customer');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $sid = (int)($_POST['service_id'] ?? 0);
    $area = $_POST['area'] ?? '';
    $urg = $_POST['urgency'] ?? 'normal';
    $when = str_replace('T', ' ', $_POST['scheduled_at'] ?? '');
    $desc = trim($_POST['description'] ?? '');
    $addr = trim($_POST['address'] ?? '');
    if (!isset(services()[$sid]) || !isset(AREAS[$area]) || !in_array($urg, BOOKABLE_URGENCY, true) || !strtotime($when) || $desc === '' || $addr === '') {
        flash('Please fill in all booking details.', 'warning');
        redirect('book.php');
    }
    if (strtotime($when) < time() - 600) $when = date('Y-m-d H:i:s', time() + 3600); // never schedule in the past
    $_SESSION['draft'] = ['service_id' => $sid, 'area' => $area, 'urgency' => $urg, 'scheduled_at' => date('Y-m-d H:i:s', strtotime($when)),
        'description' => mb_substr($desc, 0, 500), 'address' => mb_substr($addr, 0, 200), 'src' => $_POST['src'] ?? 'manual'];
}
$d = $_SESSION['draft'] ?? null;
if (!$d) redirect('book.php');

$svc = services()[$d['service_id']];
$price = estimate_price($d['service_id'], $d['urgency'], $d['scheduled_at'], $d['area']);
$matches = match_technicians($d['service_id'], $d['area'], $d['urgency'], 3, $d['scheduled_at']);
$pool = count(match_technicians($d['service_id'], $d['area'], $d['urgency'], 99, $d['scheduled_at']));
$_SESSION['draft']['price'] = $price;
$_SESSION['draft']['scores'] = array_column($matches, 'score', 'id');
$cross = also_booked($d['service_id']);

$title = 'Choose your technician';
include __DIR__ . '/includes/header.php';
$colors = ['Skill' => '#C3EC2B', 'Availability' => '#0E0F0C', 'Location' => '#9DB8FF', 'Rating' => '#F4B942', 'Experience' => '#F0A3A6'];
$maxes = ['Skill' => 30, 'Availability' => 25, 'Location' => 20, 'Rating' => 15, 'Experience' => 10];
?>
<div class="container py-4">
  <div class="request-strip mb-3">
    <i class="bi bi-droplet text-danger"></i>
    <span class="flex-grow-1 text-truncate"><?= e($d['description']) ?> · <?= e($d['address']) ?> · <?= date('D g:i A', strtotime($d['scheduled_at'])) ?></span>
    <?php if ($d['urgency'] !== 'normal'): ?><span class="pill pill-pink"><?= e(URGENCY_LABEL[$d['urgency']]) ?></span><?php endif; ?>
    <a href="book.php?service=<?= $d['service_id'] ?>" class="btn btn-sm btn-soft rounded-pill">Edit</a>
  </div>
  <h1 class="fw-bold mb-1" style="font-size:2rem">Best technicians for your job</h1>
  <p class="text-muted">Ranked by AI from <?= $pool ?> online, document-verified <?= e($svc['name']) ?> pros, by skill, proximity, rating and arrival time.</p>

  <div class="row g-4">
    <div class="col-lg-7">
      <?php if (!$matches): ?><div class="alert alert-warning">No technicians are online for this service right now. Please try another time.</div><?php endif; ?>
      <?php if ($matches): $t = $matches[0]; ?>
        <div class="he-card tech-card best p-4 mb-4">
          <div class="d-flex flex-wrap gap-2 mb-3"><span class="pill pill-lime">Top Match</span><span class="pill" style="background:#fff"><i class="bi bi-shield-check"></i> Documents verified</span>
            <?php foreach ($t['badges'] as $bd) if ($bd !== 'Verified'): ?><span class="pill" style="background:#fff"><?= e($bd) ?></span><?php endif; ?></div>
          <div class="d-flex gap-3 align-items-center mb-3">
            <span class="avatar lg"><?= e(mb_substr($t['name'], 0, 1)) ?><span class="tick"><i class="bi bi-lightning-charge-fill"></i></span></span>
            <div><div class="fs-4 fw-bold"><?= e($t['name']) ?> <span class="pill ms-1"><?= (int)$t['experience_years'] ?> yrs exp</span></div>
              <div class="small"><i class="bi bi-star-fill text-warning"></i> <?= number_format((float)$t['rating'], 1) ?> (<?= (int)$t['jobs_done'] ?> jobs) · <span class="text-mint fw-semibold"><?= e($t['area']) ?></span> · <?= e(TIER_LABEL[$t['skill_tier']]) ?></div>
              <div class="small text-muted"><i class="bi bi-send"></i> <?= $t['km'] ?> km · ~<?= (int)$t['eta'] ?> min away</div></div>
          </div>
          <div class="inset p-3 mb-3">
            <div class="d-flex justify-content-between align-items-center mb-2"><span class="fw-semibold"><i class="bi bi-cpu"></i> AI Match Score</span><span class="score"><?= $t['score'] ?><small>/100</small></span></div>
            <div class="progress score-bar mb-3">
              <?php foreach ($t['parts'] as $k => $v): ?><div class="progress-bar" style="width:<?= $v ?>%;background:<?= $colors[$k] ?>"></div><?php endforeach; ?>
            </div>
            <div class="score-cols">
              <?php foreach ($t['parts'] as $k => $v): ?><div><small><?= $k === 'Availability' ? 'Slot avail' : $k ?></small><b><?= $v ?>/<?= $maxes[$k] ?></b></div><?php endforeach; ?>
            </div>
          </div>
          <div class="d-flex flex-wrap gap-1 mb-3"><?php foreach ($t['reasons'] as $r): ?><span class="reason"><?= e($r) ?></span><?php endforeach; ?></div>
          <form method="post" action="confirm.php" class="d-flex gap-2">
            <?= csrf_field() ?><input type="hidden" name="technician_id" value="<?= (int)$t['id'] ?>">
            <button name="mode" value="book" class="btn btn-coral btn-lg rounded-pill flex-grow-1 d-flex justify-content-between align-items-center"><span><i class="bi bi-calendar-check"></i> Book <?= e($t['name']) ?></span><span class="pill pill-lime"><?= inr($price['total']) ?></span></button>
            <button name="mode" value="negotiate" class="btn btn-soft btn-lg rounded-pill" title="Negotiate the price"><i class="bi bi-chat-left-dots"></i><span class="d-none d-md-inline"> Negotiate</span></button>
          </form>
        </div>
      <?php endif; ?>

      <?php if (count($matches) > 1): ?>
        <div class="d-flex justify-content-between align-items-center mb-2"><div class="fw-bold fs-5">Other Verified Technicians</div><small class="text-muted"><?= count($matches) - 1 ?> nearby</small></div>
        <?php foreach (array_slice($matches, 1) as $t): ?>
          <div class="he-card p-3 px-4 mb-3">
            <div class="d-flex gap-3 align-items-center">
              <span class="avatar"><?= e(mb_substr($t['name'], 0, 1)) ?><span class="tick"><i class="bi bi-check"></i></span></span>
              <div class="flex-grow-1"><div class="fw-bold"><?= e($t['name']) ?> <i class="bi bi-patch-check-fill text-mint"></i></div>
                <small class="text-muted"><?= e($t['area']) ?> · ~<?= (int)$t['eta'] ?> min away · <?= e(TIER_LABEL[$t['skill_tier']]) ?></small></div>
              <div class="text-end"><span class="pill"><i class="bi bi-star-fill text-warning"></i> <?= number_format((float)$t['rating'], 1) ?></span><div class="small fw-bold text-mint mt-1"><?= $t['score'] ?>% Match</div></div>
            </div>
            <div class="d-flex justify-content-between align-items-center mt-3">
              <div><b class="fs-5"><?= inr($price['total']) ?></b> <small class="text-muted">AI price</small></div>
              <form method="post" action="confirm.php" class="d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="technician_id" value="<?= (int)$t['id'] ?>">
                <button name="mode" value="negotiate" class="btn btn-sm btn-soft rounded-pill"><i class="bi bi-chat-left-dots"></i></button>
                <button name="mode" value="book" class="btn btn-sm btn-soft rounded-pill px-3">Select Pro</button></form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
      <details class="small text-muted mt-2"><summary>How is the match score calculated?</summary>
        <p class="mt-2 mb-0">Only online, document-verified technicians who offer the service are scored. Skill (24, +3 Experienced / +6 Certified) + Availability (25, minus 8 per job within ±3 hours of your slot) + Location (20 × (1 − km/25)) + Rating ((rating/5) × 15, scaled down for technicians with under 5 jobs) + Experience (years × 1.5, max 10). Emergencies boost Location and Experience by 1.25×.</p></details>
    </div>

    <div class="col-lg-5">
      <div class="he-card p-4 mb-3">
        <div class="d-flex justify-content-between align-items-start mb-3"><div class="fw-bold fs-5"><i class="bi bi-receipt"></i> Transparent Fare Breakdown</div><span class="pill pill-lime">AI Fair Price</span></div>
        <?php foreach ($price['lines'] as [$label, $amt]): ?><div class="fare-line"><span><?= e($label) ?></span><span><?= inr($amt) ?></span></div><?php endforeach; ?>
        <hr>
        <div class="d-flex justify-content-between align-items-center">
          <div><div class="price-big"><?= inr($price['total']) ?></div><small class="text-muted">Fair range <?= inr($price['min']) ?> – <?= inr($price['max']) ?></small></div>
          <span class="pill"><i class="bi bi-shield-lock"></i> Paid via escrow</span>
        </div>
      </div>
      <?php if ($cross): ?>
        <div class="he-card cross p-4">
          <div class="eyebrow-label mb-1"><i class="bi bi-stars"></i> Smart bundle deal</div>
          <div class="fw-bold fs-5 mb-1"><?= e($cross['name']) ?></div>
          <p class="small text-muted mb-3">Customers who book <?= e($svc['name']) ?> often add <?= e($cross['name']) ?>. Book both and save 10% on the second visit.</p>
          <a class="btn btn-sm btn-outline-dark rounded-pill px-3" href="book.php?service=<?= (int)$cross['id'] ?>">+ Add to booking</a>
        </div>
      <?php endif; ?>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
