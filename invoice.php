<?php
require_once __DIR__ . '/includes/functions.php';
$u = require_login();
$id = (int)($_GET['id'] ?? 0);
$st = db()->prepare("SELECT b.*, s.name service, c.name cust, c.email cust_email, c.phone cust_phone, tu.name tech
    FROM bookings b JOIN services s ON s.id = b.service_id JOIN users c ON c.id = b.customer_id
    LEFT JOIN technicians t ON t.id = b.technician_id LEFT JOIN users tu ON tu.id = t.user_id WHERE b.id = ?");
$st->bind_param('i', $id);
$st->execute();
$b = $st->get_result()->fetch_assoc();
if (!$b || ($u['role'] === 'customer' && (int)$b['customer_id'] !== $u['id'])
    || ($u['role'] === 'provider' && (int)$b['technician_id'] !== (int)$u['tech_id'])
    || $b['status'] !== 'completed') exit('Invoice not available.');
$refunded = $b['escrow_status'] === 'refunded';
if ($b['parent_booking_id']) {
    $lines = [['Warranty follow-up for booking #' . $b['parent_booking_id'], 0]];
} elseif ($b['negotiated_price'] !== null) {
    $lines = [['Negotiated & locked price (AI fair range ' . inr($b['est_min']) . '–' . inr($b['est_max']) . ')', $b['price_total'] - $b['price_fee']], ['Platform fee', $b['price_fee']]];
} else {
    $lines = [['Base price: ' . $b['service'], $b['price_base']]];
    if ($b['price_urgency']) $lines[] = [URGENCY_LABEL[$b['urgency']] . ' charge', $b['price_urgency']];
    if ($b['price_peak']) $lines[] = ['Peak hours', $b['price_peak']];
    if ($b['price_demand']) $lines[] = ['High demand', $b['price_demand']];
    if ($b['price_zone']) $lines[] = ['Zone surge bonus (to technician)', $b['price_zone']];
    $lines[] = ['Platform fee', $b['price_fee']];
}
$wc = db()->query('SELECT code FROM warranties WHERE booking_id = ' . (int)$b['id'])->fetch_assoc()['code'] ?? null;
?><!doctype html>
<html><head><meta charset="utf-8"><title>Invoice HE-<?= str_pad((string)$b['id'], 5, '0', STR_PAD_LEFT) ?></title>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<style>body{background:#F2F7F6;font-family:system-ui}.inv{max-width:720px;margin:40px auto;background:#fff;border-radius:16px;padding:40px}.brand{color:#123C42;font-weight:800}.coral{color:#E8582F}@media print{.no-print{display:none}body{background:#fff}.inv{margin:0;box-shadow:none}}</style>
</head><body>
<div class="inv shadow-sm">
  <div class="d-flex justify-content-between mb-4">
    <div><div class="h3 brand mb-0">🏠 HomeEase<span class="coral">AI</span></div><small class="text-muted">Intelligent home services · Bengaluru</small></div>
    <div class="text-end"><div class="h5 mb-0">INVOICE</div><small>HE-<?= str_pad((string)$b['id'], 5, '0', STR_PAD_LEFT) ?><br><?= date('d M Y', strtotime($b['completed_at'])) ?></small></div>
  </div>
  <div class="row mb-4 small">
    <div class="col"><b>Billed to</b><br><?= e($b['cust']) ?><br><?= e($b['address']) ?><br><?= e($b['cust_phone']) ?></div>
    <div class="col text-end"><b>Service by</b><br><?= e($b['tech']) ?><br>Verified HomeEase professional</div>
  </div>
  <table class="table"><thead><tr><th>Description</th><th class="text-end">Amount</th></tr></thead><tbody>
    <?php foreach ($lines as [$l, $a]): ?><tr><td><?= e($l) ?></td><td class="text-end"><?= inr($a) ?></td></tr><?php endforeach; ?>
    <tr class="fw-bold"><td><?= $refunded ? 'Total (refunded to customer)' : 'Total paid' ?></td><td class="text-end"><?= inr($b['price_total']) ?></td></tr>
  </tbody></table>
  <p class="small text-muted">Job: <?= e($b['description']) ?><br><?= $refunded ? 'Dispute settled by HomeEase admin: escrow payment refunded to the customer.' : 'Paid via HomeEase escrow, released after customer confirmation.' ?><?= $wc ? '<br>30-day warranty: <b>' . e($wc) . '</b>' : '' ?></p>
  <button class="btn btn-dark rounded-pill no-print" onclick="window.print()">Download / Print PDF</button>
</div>
</body></html>
