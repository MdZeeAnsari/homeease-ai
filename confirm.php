<?php
// Creates the booking. mode=book: price locked at the AI price, customer pays into escrow next.
// mode=negotiate: opens a negotiation thread with the chosen technician.
require_once __DIR__ . '/includes/functions.php';
$u = require_login('customer');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect('book.php');
csrf_check();

$d = $_SESSION['draft'] ?? null;
$tid = (int)($_POST['technician_id'] ?? 0);
if (!$d || !isset($d['scores'][$tid])) { flash('Please choose a technician again.', 'warning'); redirect('book.php'); }
$negotiate = ($_POST['mode'] ?? 'book') === 'negotiate';

$p = $d['price'];
$score = (int)$d['scores'][$tid];
$otp = new_otp();
$src = in_array($d['src'], ['text', 'photo'], true) ? $d['src'] : 'manual';
$status = $negotiate ? 'negotiating' : 'pending';
$locked = $negotiate ? 0 : 1;
$st = db()->prepare("INSERT INTO bookings (customer_id, service_id, technician_id, area, address, description, urgency, scheduled_at,
    est_min, est_max, price_base, price_urgency, price_peak, price_demand, price_zone, price_fee, price_total, price_locked, match_score, ai_source,
    status, escrow_status, otp, created_at)
    VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'unpaid',?, NOW())");
$st->bind_param('iiisssssiiiiiiiiiiisss', $u['id'], $d['service_id'], $tid, $d['area'], $d['address'], $d['description'], $d['urgency'],
    $d['scheduled_at'], $p['min'], $p['max'], $p['base'], $p['urgency'], $p['peak'], $p['demand'], $p['zone_bonus'], $p['fee'], $p['total'],
    $locked, $score, $src, $status, $otp);
$st->execute();
$id = db()->insert_id;
unset($_SESSION['draft']);

if ($negotiate) {
    $msg = 'AI smart price ' . inr($p['total']) . '. Fair range ' . inr($p['min']) . ' to ' . inr($p['max']) . '.';
    $st = db()->prepare("INSERT INTO price_negotiations (booking_id, sender, amount, fairness, message, status) VALUES (?, 'ai', ?, 'within', ?, 'info')");
    $st->bind_param('iis', $id, $p['total'], $msg);
    $st->execute();
    flash("Negotiation opened for booking #$id. Send your offer to the technician.");
    redirect("negotiate.php?id=$id");
}
flash("Booking #$id created at " . inr($p['total']) . ". Pay into escrow to send it to the technician.");
redirect("my_bookings.php#b$id");
