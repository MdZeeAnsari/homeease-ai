<?php
// Tiny "has anything changed?" check used for auto-refresh. Returns a fingerprint of the live data.
require_once __DIR__ . '/../includes/functions.php';
header('Content-Type: application/json');
header('Cache-Control: no-store');
if (!user()) { echo json_encode(['v' => '']); exit; }
sos_watchdog();
shields_expire();
$r = db()->query("SELECT
    (SELECT CONCAT(COUNT(*), '-', COALESCE(MAX(id),0), '-', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status, escrow_status, technician_id, price_total, rating, customer_confirmed))),0)) FROM bookings) b,
    (SELECT CONCAT(COUNT(*), '-', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status))),0)) FROM price_negotiations) n,
    (SELECT CONCAT(COUNT(*), '-', COALESCE(SUM(CRC32(CONCAT_WS('|', id, status))),0)) FROM safety_shields) s,
    (SELECT COUNT(*) FROM replacement_logs) r,
    (SELECT COALESCE(SUM(CRC32(CONCAT_WS('|', id, available, wallet_balance))),0) FROM technicians) t")->fetch_assoc();
echo json_encode(['v' => md5(implode('/', $r))]);
