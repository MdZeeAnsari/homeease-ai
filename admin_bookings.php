<?php
require_once __DIR__ . '/includes/functions.php';
require_login('admin');

$status = $_GET['status'] ?? '';
$svc = (int)($_GET['service'] ?? 0);
$where = [];
$types = '';
$args = [];
if (isset(STATUS_BADGE[$status])) { $where[] = 'b.status = ?'; $types .= 's'; $args[] = $status; }
if (isset(services()[$svc])) { $where[] = 'b.service_id = ?'; $types .= 'i'; $args[] = $svc; }
$sql = "SELECT b.*, s.name service, c.name cust, u.name tech FROM bookings b
    JOIN services s ON s.id = b.service_id JOIN users c ON c.id = b.customer_id
    LEFT JOIN technicians t ON t.id = b.technician_id LEFT JOIN users u ON u.id = t.user_id"
    . ($where ? ' WHERE ' . implode(' AND ', $where) : '') . " ORDER BY b.created_at DESC LIMIT 200";
$st = db()->prepare($sql);
if ($args) $st->bind_param($types, ...$args);
$st->execute();
$rows = $st->get_result()->fetch_all(MYSQLI_ASSOC);

$title = 'All bookings';
$live = true;
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
  <h1 class="h3 fw-bold mb-3">All bookings</h1>
  <form class="d-flex flex-wrap gap-2 mb-3">
    <select name="status" class="form-select form-select-sm" style="max-width:180px" onchange="this.form.submit()">
      <option value="">All statuses</option>
      <?php foreach (STATUS_LABEL as $s => $lbl): ?><option value="<?= $s ?>" <?= $s === $status ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
    </select>
    <select name="service" class="form-select form-select-sm" style="max-width:200px" onchange="this.form.submit()">
      <option value="0">All services</option>
      <?php foreach (services() as $s): ?><option value="<?= $s['id'] ?>" <?= $s['id'] == $svc ? 'selected' : '' ?>><?= e($s['name']) ?></option><?php endforeach; ?>
    </select>
    <span class="small text-muted align-self-center">Showing <?= count($rows) ?> (latest 200)</span>
  </form>
  <div class="card he-card p-0 overflow-hidden">
    <div class="table-responsive">
      <table class="table table-sm table-hover small mb-0 align-middle">
        <thead class="table-light"><tr><th class="ps-3">#</th><th>Created</th><th>Service</th><th>Customer</th><th>Area</th><th>Urgency</th><th>Technician</th><th>Match</th><th>AI</th><th>Status</th><th>Escrow</th><th>Rating</th><th class="text-end pe-3">Amount</th></tr></thead>
        <tbody><?php foreach ($rows as $r): ?>
          <tr><td class="ps-3"><?= $r['id'] ?></td><td><?= date('d M, g:i A', strtotime($r['created_at'])) ?></td><td><?= e($r['service']) ?></td><td><?= e($r['cust']) ?></td>
            <td><?= e($r['area']) ?></td><td><?= e(URGENCY_LABEL[$r['urgency']]) ?></td><td><?= e($r['tech'] ?? '-') ?></td><td><?= (int)$r['match_score'] ?></td>
            <td><?= $r['ai_source'] === 'manual' ? '-' : '<i class="bi bi-' . ($r['ai_source'] === 'photo' ? 'camera' : 'chat-dots') . '"></i>' ?></td>
            <td><span class="badge rounded-pill text-bg-<?= STATUS_BADGE[$r['status']] ?>"><?= e(STATUS_LABEL[$r['status']]) ?></span></td>
            <td><span class="badge rounded-pill text-bg-<?= ESCROW_LABEL[$r['escrow_status']][1] ?>"><?= ESCROW_LABEL[$r['escrow_status']][0] ?></span></td>
            <td class="text-warning"><?= $r['rating'] ? str_repeat('★', (int)$r['rating']) : '' ?></td><td class="text-end pe-3"><?= inr($r['price_total']) ?></td></tr>
        <?php endforeach; ?></tbody>
      </table>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
