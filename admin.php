<?php
require_once __DIR__ . '/includes/functions.php';
require_login('admin');
$db = db();
sos_watchdog();
shields_expire();
$q = fn(string $sql) => $db->query($sql)->fetch_all(MYSQLI_ASSOC);
$one = fn(string $sql) => $db->query($sql)->fetch_assoc();

// ---------------- KPIs ----------------
$k = $one("SELECT
    SUM(created_at >= NOW() - INTERVAL 30 DAY) b30,
    SUM(created_at >= NOW() - INTERVAL 7 DAY) b7,
    SUM(created_at < NOW() - INTERVAL 7 DAY AND created_at >= NOW() - INTERVAL 14 DAY) bprev7,
    COALESCE(SUM(CASE WHEN status='completed' AND created_at >= NOW() - INTERVAL 30 DAY THEN price_total END),0) rev,
    ROUND(100*SUM(status='completed')/NULLIF(SUM(status IN ('completed','cancelled')),0),1) completion,
    ROUND(AVG(rating),2) rating,
    ROUND(100*SUM(ai_source<>'manual')/COUNT(*),0) ai_share,
    ROUND(100*SUM(ai_source='photo')/COUNT(*),0) photo_share
    FROM bookings");
$growth = $k['bprev7'] > 0 ? round(100 * ($k['b7'] - $k['bprev7']) / $k['bprev7']) : null;
$techCount = $one("SELECT COUNT(*) n, SUM(available) online FROM technicians");
$escrow = $one("SELECT COALESCE(SUM(CASE WHEN escrow_status='held' THEN price_total END),0) held, SUM(escrow_status='disputed') disputed FROM bookings");

// ---------------- trust & safety ----------------
$sosBookings = $q("SELECT b.id, b.description, b.area, b.status, b.created_at, s.name service, c.name cust, c.phone cust_phone, tu.name tech
    FROM bookings b JOIN services s ON s.id = b.service_id JOIN users c ON c.id = b.customer_id
    LEFT JOIN technicians t ON t.id = b.technician_id LEFT JOIN users tu ON tu.id = t.user_id
    WHERE b.urgency = 'sos' AND b.status IN ('pending','accepted','in_progress') ORDER BY b.created_at DESC");
$techSos = $q("SELECT ss.booking_id, ss.policy_no, ss.activated_at, u.name tech, u.phone, t.emergency_phone, b.area
    FROM safety_shields ss JOIN technicians t ON t.id = ss.technician_id JOIN users u ON u.id = t.user_id JOIN bookings b ON b.id = ss.booking_id
    WHERE ss.status = 'sos' ORDER BY ss.id DESC LIMIT 5");
$shieldsActive = (int)$one("SELECT COUNT(*) c FROM safety_shields WHERE status IN ('outbound','on_job','return')")['c'];
$disputes = $q("SELECT b.id, b.price_total, b.description, s.name service, c.name cust, tu.name tech FROM bookings b JOIN services s ON s.id = b.service_id
    JOIN users c ON c.id = b.customer_id LEFT JOIN technicians t ON t.id = b.technician_id LEFT JOIN users tu ON tu.id = t.user_id WHERE b.escrow_status = 'disputed'");
$replacements = $q("SELECT r.*, pu.name prev_name, nu.name new_name FROM replacement_logs r
    LEFT JOIN technicians pt ON pt.id = r.prev_technician_id LEFT JOIN users pu ON pu.id = pt.user_id
    LEFT JOIN technicians nt ON nt.id = r.new_technician_id LEFT JOIN users nu ON nu.id = nt.user_id ORDER BY r.id DESC LIMIT 6");
$zoneMap = zones();

// ---------------- forecast ----------------
$fc = forecast_demand(7);
$histLabels = array_map(fn($d) => date('d M', strtotime($d)), array_keys(array_slice($fc['history'], -14, null, true)));
$histVals = array_values(array_slice($fc['history'], -14));
$fcLabels = array_map(fn($d) => date('d M', strtotime($d)), array_keys($fc['forecast']));
$fcVals = array_values($fc['forecast']);
$fcTotal = (int)round(array_sum($fcVals));
$peakDay = array_search(max($fcVals), $fc['forecast']);
$weatherDays = array_filter($fc['weather'], fn($w) => $w !== 'normal');
$fcBaseVals = array_values($fc['base']);

// per service: this week vs last week + next-week forecast
$svcRows = $q("SELECT s.id, s.name,
    SUM(b.created_at >= NOW() - INTERVAL 7 DAY) wk,
    SUM(b.created_at < NOW() - INTERVAL 7 DAY AND b.created_at >= NOW() - INTERVAL 14 DAY) prev,
    SUM(b.created_at >= NOW() - INTERVAL 30 DAY) m
    FROM services s LEFT JOIN bookings b ON b.service_id = s.id GROUP BY s.id ORDER BY m DESC");
foreach ($svcRows as &$r) {
    $r['change'] = $r['prev'] > 0 ? round(100 * ($r['wk'] - $r['prev']) / $r['prev']) : 0;
    $r['next7'] = (int)round(array_sum(forecast_demand(7, (int)$r['id'])['forecast']));
}
unset($r);
$topGrowth = $svcRows;
usort($topGrowth, fn($a, $b) => $b['change'] <=> $a['change']);
$topGrowth = $topGrowth[0];

// ---------------- areas & supply gap ----------------
$areaRows = $q("SELECT b.area, COUNT(*) c,
    (SELECT COUNT(*) FROM technicians t WHERE t.area = b.area) techs
    FROM bookings b WHERE b.created_at >= NOW() - INTERVAL 30 DAY GROUP BY b.area ORDER BY c DESC");
$gap = $areaRows;
usort($gap, fn($a, $b) => ($b['c'] / max(1, $b['techs'])) <=> ($a['c'] / max(1, $a['techs'])));
$gap = $gap[0];

// ---------------- hours ----------------
$hourRows = $q("SELECT HOUR(created_at) h, COUNT(*) c FROM bookings WHERE created_at >= NOW() - INTERVAL 30 DAY GROUP BY h ORDER BY h");
$hours = array_fill(7, 16, 0);
foreach ($hourRows as $r) if (isset($hours[(int)$r['h']])) $hours[(int)$r['h']] = (int)$r['c'];
arsort($hours);
$peakHours = array_slice(array_keys($hours), 0, 2);
ksort($hours);

// ---------------- technician utilization (last 7 days, capacity 3 jobs/day) ----------------
$util = $q("SELECT u.name, t.rating, COUNT(b.id) jobs, ROUND(100*COUNT(b.id)/21) pct,
    (SELECT ROUND(AVG(b2.rating),2) FROM bookings b2 WHERE b2.technician_id = t.id AND b2.rating IS NOT NULL AND b2.created_at >= NOW() - INTERVAL 30 DAY) r30
    FROM technicians t JOIN users u ON u.id = t.user_id
    LEFT JOIN bookings b ON b.technician_id = t.id AND b.created_at >= NOW() - INTERVAL 7 DAY AND b.status <> 'cancelled'
    GROUP BY t.id ORDER BY jobs DESC");
$under = array_filter($util, fn($t) => $t['pct'] < 15);
$low = array_filter($util, fn($t) => $t['r30'] !== null && $t['r30'] < 4.3);

// ---------------- auto insights ----------------
$insights = [];
if ($topGrowth['change'] > 0) $insights[] = ['bi-graph-up-arrow', 'coral', "<b>{$topGrowth['name']}</b> bookings are up <b>{$topGrowth['change']}%</b> this week vs last week. Expect about <b>{$topGrowth['next7']}</b> next week, so put more {$topGrowth['name']} technicians online."];
$insights[] = ['bi-calendar-week', 'teal', "Forecast: <b>$fcTotal bookings</b> in the next 7 days, peaking on <b>" . date('l', strtotime($peakDay)) . "</b> (~" . round(max($fcVals)) . "). Plan weekend shifts early."];
foreach (array_unique($weatherDays) as $wc) {
    [$wl, $wi, $wm, $wslug] = WEATHER[$wc];
    $wdates = implode(', ', array_map(fn($d) => date('D', strtotime($d)), array_keys(array_filter($weatherDays, fn($x) => $x === $wc))));
    $insights[] = [$wi, 'coral', "<b>$wl</b> expected on <b>$wdates</b>: " . service_by_slug($wslug)['name'] . " demand ×$wm. Forecast already adjusted, so keep extra " . service_by_slug($wslug)['name'] . " technicians online."];
}
$redZones = array_keys(array_filter($zoneMap, fn($z) => $z['zone'] === 'red'));
if ($redZones) $insights[] = ['bi-fire', 'coral', "Red surge zone: <b>" . e(implode(', ', $redZones)) . "</b>. Technicians get +₹150 per job there to pull in supply."];
$insights[] = ['bi-geo-alt', 'teal', "<b>{$gap['area']}</b> has the biggest supply gap: {$gap['c']} bookings this month for {$gap['techs']} local technician" . ($gap['techs'] == 1 ? '' : 's') . ". Recruit 1–2 more there to cut travel time."];
$insights[] = ['bi-clock', 'teal', "Peak booking hours are <b>" . implode(' and ', array_map(fn($h) => date('g A', mktime($h)), $peakHours)) . "</b>. Keep most technicians available then."];
if ($under) $insights[] = ['bi-person-dash', 'coral', count($under) . " technician" . (count($under) > 1 ? 's are' : ' is') . " under 15% utilised this week (" . e(implode(', ', array_slice(array_column($under, 'name'), 0, 3))) . "). Route nearby jobs to them first."];
if ($low) $insights[] = ['bi-exclamation-triangle', 'coral', "Quality alert: " . e(implode(', ', array_column($low, 'name'))) . " averaged below 4.3★ this month. Schedule a review."];
$insights[] = ['bi-magic', 'teal', "<b>{$k['ai_share']}%</b> of bookings used the AI assistant ({$k['photo_share']}% via photo diagnosis)."];

$recent = $q("SELECT b.id, b.status, b.price_total, b.created_at, b.area, b.match_score, s.name service, c.name cust, u.name tech
    FROM bookings b JOIN services s ON s.id = b.service_id JOIN users c ON c.id = b.customer_id
    LEFT JOIN technicians t ON t.id = b.technician_id LEFT JOIN users u ON u.id = t.user_id ORDER BY b.created_at DESC LIMIT 8");

$title = 'Analytics';
$live = true;
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mb-4">
    <div><h1 class="h3 fw-bold mb-0">Operations analytics</h1><small class="text-muted">Live from the bookings database · last 30 days unless noted</small></div>
    <span class="badge rounded-pill text-bg-light border"><i class="bi bi-arrow-repeat"></i> Updated <?= date('g:i A') ?></span>
  </div>

  <div class="row g-3 mb-4">
    <?php foreach ([
        ['bi-calendar-check', number_format((int)$k['b30']), 'Bookings', $growth !== null ? (($growth >= 0 ? '+' : '') . $growth . '% week on week') : ''],
        ['bi-currency-rupee', inr($k['rev']), 'Revenue (completed)', ''],
        ['bi-check2-all', $k['completion'] . '%', 'Completion rate', ''],
        ['bi-star-fill', $k['rating'], 'Avg customer rating', ''],
        ['bi-people', $techCount['online'] . '/' . $techCount['n'], 'Technicians online', ''],
        ['bi-shield-lock', inr($escrow['held']), 'Held in escrow', $escrow['disputed'] ? $escrow['disputed'] . ' disputed' : ''],
    ] as $c): ?>
      <div class="col-6 col-lg"><div class="card he-card p-3 kpi h-100"><i class="bi <?= $c[0] ?> text-mint"></i><div class="kpi-val"><?= $c[1] ?></div><small class="text-muted"><?= $c[2] ?></small><?php if ($c[3]): ?><small class="text-success fw-semibold"><?= $c[3] ?></small><?php endif; ?></div></div>
    <?php endforeach; ?>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-8">
      <div class="card he-card p-4 h-100">
        <div class="d-flex justify-content-between"><div class="fw-bold">Demand: last 14 days + 7-day forecast</div><small class="text-muted">Model: linear trend × weekday seasonality × weather<?= $fc['mape'] !== null ? ' · fit error ' . $fc['mape'] . '%' : '' ?></small></div>
        <div class="d-flex flex-wrap gap-2 my-2"><?php foreach ($fc['weather'] as $wd => $wc): [$wl, $wi, $wm] = WEATHER[$wc]; ?>
          <span class="badge rounded-pill <?= $wc === 'normal' ? 'text-bg-light border' : 'text-bg-warning' ?>"><i class="bi <?= $wi ?>"></i> <?= date('D', strtotime($wd)) ?> <?= $wc === 'normal' ? '' : e($wl) . ' ×' . $wm ?></span>
        <?php endforeach; ?></div>
        <div class="chart-box" style="height:470px"><canvas id="fcChart"></canvas></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="card he-card p-4 h-100 insights">
        <div class="fw-bold mb-3"><i class="bi bi-lightbulb-fill text-coral"></i> AI insights</div>
        <?php foreach ($insights as [$icon, $col, $text]): ?>
          <div class="d-flex gap-2 mb-3 small"><i class="bi <?= $icon ?> text-<?= $col ?> fs-5"></i><div><?= $text ?></div></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4" id="disputes">
    <div class="col-lg-6">
      <div class="card he-card p-4 h-100 <?= ($sosBookings || $techSos) ? 'border border-2 border-danger' : '' ?>">
        <div class="d-flex justify-content-between"><div class="fw-bold mb-3"><i class="bi bi-broadcast text-danger"></i> Live SOS monitor</div><small class="text-muted"><i class="bi bi-shield-check text-success"></i> <?= $shieldsActive ?> Safety Shields active</small></div>
        <?php if (!$sosBookings && !$techSos): ?><p class="small text-muted mb-0">No active emergencies.</p><?php endif; ?>
        <?php foreach ($sosBookings as $r): ?>
          <div class="d-flex gap-2 small mb-2"><span class="badge text-bg-danger align-self-start">Customer SOS</span>
            <div>#<?= $r['id'] ?> · <?= e($r['description']) ?> · <?= e($r['cust']) ?> (<?= e($r['cust_phone']) ?>), <?= e($r['area']) ?><br>
              <span class="text-muted"><?= $r['tech'] ? 'Dispatched: ' . e($r['tech']) . ' · ' . e(STATUS_LABEL[$r['status']]) : 'Unassigned, needs manual dispatch' ?> · <?= date('g:i A', strtotime($r['created_at'])) ?></span></div></div>
        <?php endforeach; ?>
        <?php foreach ($techSos as $r): ?>
          <div class="d-flex gap-2 small mb-2"><span class="badge text-bg-dark align-self-start">Technician SOS</span>
            <div><?= e($r['tech']) ?> (<?= e($r['phone']) ?>) on job #<?= $r['booking_id'] ?>, <?= e($r['area']) ?> · policy <?= e($r['policy_no']) ?><br>
              <span class="text-muted">Emergency contact <?= e($r['emergency_phone']) ?> alerted · nearest hospital: <?= e(HOSPITALS[$r['area']] ?? '-') ?></span></div></div>
        <?php endforeach; ?>
        <?php if ($disputes): ?><hr><div class="fw-bold small mb-2">Escrow disputes</div><?php endif; ?>
        <?php foreach ($disputes as $r): ?>
          <div class="d-flex flex-wrap gap-2 align-items-center small mb-2"><div class="flex-grow-1">#<?= $r['id'] ?> · <?= e($r['service']) ?> · <?= e($r['cust']) ?> vs <?= e($r['tech'] ?? '-') ?> · <b><?= inr($r['price_total']) ?></b></div>
            <form method="post" action="booking_action.php" class="d-flex gap-1"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $r['id'] ?>">
              <button name="action" value="refund" class="btn btn-sm btn-outline-secondary rounded-pill">Refund customer</button>
              <button name="action" value="release" class="btn btn-sm btn-success rounded-pill">Release to technician</button></form></div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="card he-card p-4 h-100">
        <div class="fw-bold mb-3"><i class="bi bi-map text-teal"></i> Surge zones (EarnMore)</div>
        <?php foreach ($zoneMap as $a => $z): ?>
          <div class="d-flex justify-content-between align-items-center small mb-1"><span><span class="badge rounded-pill text-bg-<?= ZONE_BADGE[$z['zone']] ?>">&nbsp;</span> <?= e($a) ?></span>
            <span class="text-muted"><?= $z['demand'] ?>/<?= $z['supply'] ?> · <?= $z['bonus'] ? '+' . inr($z['bonus']) : '–' ?></span></div>
        <?php endforeach; ?>
        <small class="text-muted d-block mt-2">bookings this week / online technicians</small>
      </div>
    </div>
    <div class="col-lg-3">
      <div class="card he-card p-4 h-100">
        <div class="fw-bold mb-3"><i class="bi bi-arrow-repeat text-teal"></i> AI auto-replacements</div>
        <?php if (!$replacements): ?><p class="small text-muted mb-0">None yet.</p><?php endif; ?>
        <?php foreach ($replacements as $r): ?>
          <div class="small mb-2">#<?= $r['booking_id'] ?> · <?= e($r['reason']) ?><br><span class="text-muted"><?= e($r['prev_name'] ?? '-') ?> → <?= e($r['new_name'] ?? 'unassigned') ?><?= $r['new_score'] ? ' (' . (int)$r['new_score'] . ')' : '' ?> · <?= date('g:i A', strtotime($r['created_at'])) ?></span></div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="row g-4 mb-4">
    <div class="col-lg-4"><div class="card he-card p-4 h-100"><div class="fw-bold mb-2">Bookings by service</div><div class="chart-box"><canvas id="svcChart"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card he-card p-4 h-100"><div class="fw-bold mb-2">Demand by area</div><div class="chart-box"><canvas id="areaChart"></canvas></div></div></div>
    <div class="col-lg-4"><div class="card he-card p-4 h-100"><div class="fw-bold mb-2">Bookings by hour of day</div><div class="chart-box"><canvas id="hourChart"></canvas></div></div></div>
  </div>

  <div class="row g-4">
    <div class="col-lg-5">
      <div class="card he-card p-4 h-100">
        <div class="fw-bold mb-3">Service trends</div>
        <table class="table table-sm small align-middle mb-0">
          <thead><tr><th>Service</th><th class="text-end">This week</th><th class="text-end">Change</th><th class="text-end">Next 7d (forecast)</th></tr></thead>
          <tbody><?php foreach ($svcRows as $r): ?>
            <tr><td><?= e($r['name']) ?></td><td class="text-end"><?= (int)$r['wk'] ?></td>
              <td class="text-end fw-semibold <?= $r['change'] >= 0 ? 'text-success' : 'text-danger' ?>"><?= ($r['change'] >= 0 ? '▲ ' : '▼ ') . abs($r['change']) ?>%</td>
              <td class="text-end"><?= $r['next7'] ?></td></tr>
          <?php endforeach; ?></tbody>
        </table>
      </div>
    </div>
    <div class="col-lg-7">
      <div class="card he-card p-4 h-100"><div class="d-flex justify-content-between"><div class="fw-bold mb-2">Technician utilisation (last 7 days)</div><small class="text-muted">capacity = 3 jobs/day</small></div><div class="chart-box"><canvas id="utilChart"></canvas></div></div>
    </div>
  </div>

  <div class="card he-card p-0 mt-4 overflow-hidden">
    <div class="d-flex justify-content-between align-items-center p-3"><div class="fw-bold">Latest bookings</div><a href="admin_bookings.php" class="small">View all →</a></div>
    <table class="table table-sm small mb-0 align-middle">
      <thead class="table-light"><tr><th class="ps-3">#</th><th>Service</th><th>Customer</th><th>Area</th><th>Technician</th><th>Match</th><th>Status</th><th class="text-end pe-3">Amount</th></tr></thead>
      <tbody><?php foreach ($recent as $r): ?>
        <tr><td class="ps-3"><?= $r['id'] ?></td><td><?= e($r['service']) ?></td><td><?= e($r['cust']) ?></td><td><?= e($r['area']) ?></td><td><?= e($r['tech'] ?? '-') ?></td><td><?= (int)$r['match_score'] ?></td>
          <td><span class="badge rounded-pill text-bg-<?= STATUS_BADGE[$r['status']] ?>"><?= e(STATUS_LABEL[$r['status']]) ?></span></td><td class="text-end pe-3"><?= inr($r['price_total']) ?></td></tr>
      <?php endforeach; ?></tbody>
    </table>
  </div>
</div>
<?php
$data = [
    'histLabels' => $histLabels, 'histVals' => $histVals, 'fcLabels' => $fcLabels, 'fcVals' => $fcVals, 'fcBaseVals' => $fcBaseVals,
    'svcLabels' => array_column($svcRows, 'name'), 'svcVals' => array_map('intval', array_column($svcRows, 'm')),
    'areaLabels' => array_column($areaRows, 'area'), 'areaVals' => array_map('intval', array_column($areaRows, 'c')),
    'hourLabels' => array_map(fn($h) => date('ga', mktime($h)), array_keys($hours)), 'hourVals' => array_values($hours),
    'utilLabels' => array_column($util, 'name'), 'utilVals' => array_map('intval', array_column($util, 'pct')),
];
$extraScripts = '<script src="assets/vendor/chartjs/chart.umd.min.js"></script>
<script>const D = ' . json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) . ';</script><script src="assets/js/admin.js"></script>';
include __DIR__ . '/includes/footer.php';
