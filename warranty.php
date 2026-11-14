<?php
// Verifiable 30-day digital warranty certificate (public by code)
require_once __DIR__ . '/includes/functions.php';
$code = strtoupper(trim($_GET['code'] ?? ''));
$st = db()->prepare("SELECT w.*, s.name service, c.name cust, tu.name tech, b.description, b.completed_at FROM warranties w
    JOIN services s ON s.id = w.service_id JOIN users c ON c.id = w.customer_id JOIN bookings b ON b.id = w.booking_id
    LEFT JOIN technicians t ON t.id = w.technician_id LEFT JOIN users tu ON tu.id = t.user_id WHERE w.code = ?");
$st->bind_param('s', $code);
$st->execute();
$w = $st->get_result()->fetch_assoc();
$valid = $w && $w['status'] === 'active' && strtotime($w['valid_until']) >= strtotime('today');
?><!doctype html>
<html><head><meta charset="utf-8"><title>Warranty <?= e($code) ?></title>
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet"><link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<style>body{background:#F2F7F6;font-family:system-ui}.cert{max-width:680px;margin:40px auto;background:#fff;border-radius:20px;padding:44px;border:3px solid #2BB59A}.brand{color:#123C42;font-weight:800}.coral{color:#E8582F}@media print{.no-print{display:none}body{background:#fff}}</style>
</head><body>
<div class="cert shadow-sm text-center">
<?php if (!$w): ?>
  <h1 class="h4">Warranty not found</h1><p class="text-muted">Check the code and try again.</p>
<?php else: ?>
  <div class="h3 brand mb-0">🏠 HomeEase<span class="coral">AI</span></div>
  <div class="text-uppercase small fw-bold text-muted mt-1">Digital Service Warranty</div>
  <div class="display-6 fw-bold my-3" style="color:#123C42"><?= e($w['code']) ?></div>
  <span class="badge rounded-pill fs-6 text-bg-<?= $valid ? 'success' : ($w['status'] === 'claimed' ? 'info' : 'secondary') ?>"><?= $valid ? 'VALID' : strtoupper($w['status'] === 'active' ? 'expired' : $w['status']) ?></span>
  <table class="table mt-4 text-start small">
    <tr><th>Service</th><td><?= e($w['service']) ?> · <?= e($w['description']) ?></td></tr>
    <tr><th>Customer</th><td><?= e($w['cust']) ?></td></tr>
    <tr><th>Verified technician</th><td><?= e($w['tech'] ?? '-') ?></td></tr>
    <tr><th>Completed on</th><td><?= date('d M Y', strtotime($w['valid_from'])) ?></td></tr>
    <tr><th>Valid until</th><td><b><?= date('d M Y', strtotime($w['valid_until'])) ?></b></td></tr>
  </table>
  <p class="small text-muted">If the same problem comes back before the expiry date, book a free follow-up visit from My bookings.</p>
  <button class="btn btn-dark rounded-pill no-print" onclick="window.print()">Download / Print</button>
<?php endif; ?>
</div></body></html>
