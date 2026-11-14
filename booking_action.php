<?php
// All booking state changes for customers, technicians and admins.
require_once __DIR__ . '/includes/functions.php';
$u = require_login();
if ($_SERVER['REQUEST_METHOD'] !== 'POST') redirect(home_for($u['role']));
csrf_check();

$action = $_POST['action'] ?? '';
$id = (int)($_POST['id'] ?? 0);
$db = db();

function set_status(int $id, string $status, string $extra = ''): void {
    $st = db()->prepare("UPDATE bookings SET status = ? $extra WHERE id = ?");
    $st->bind_param('si', $status, $id);
    $st->execute();
}
// Runs a guarded UPDATE and returns true only if this request changed the row.
// Makes every state change safe when two taps / two devices arrive at the same moment.
function claim(string $sql, string $types, ...$args): bool {
    $st = db()->prepare($sql);
    $st->bind_param($types, ...$args);
    $st->execute();
    return $st->affected_rows === 1;
}
function set_escrow(int $id, string $escrow): void {
    $st = db()->prepare('UPDATE bookings SET escrow_status = ? WHERE id = ?');
    $st->bind_param('si', $escrow, $id);
    $st->execute();
}

// ------------------------------------------------------------ customer
if ($u['role'] === 'customer') {
    $b = load_booking($id);
    if (!$b || (int)$b['customer_id'] !== $u['id']) redirect('my_bookings.php');
    switch ($action) {
        case 'pay': // simulated escrow deposit
            if (claim("UPDATE bookings SET escrow_status = 'held' WHERE id = ? AND status = 'pending' AND escrow_status = 'unpaid' AND price_locked = 1", 'i', $id)) {
                flash(inr($b['price_total']) . ' is held safely in escrow. The technician has been notified.');
            }
            break;
        case 'cancel':
            if (in_array($b['status'], ['negotiating', 'pending', 'accepted'], true)) {
                set_status($id, 'cancelled');
                if ($b['escrow_status'] === 'held') set_escrow($id, 'refunded');
                if ($b['technician_id']) shield_set($id, (int)$b['technician_id'], 'closed');
                flash("Booking #$id cancelled." . ($b['escrow_status'] === 'held' ? ' Your escrow payment has been refunded.' : ''), 'info');
            }
            break;
        case 'confirm': // customer confirms the job is done: escrow released, warranty issued, rating saved
            if ($b['status'] === 'completed' && !$b['customer_confirmed'] && $b['escrow_status'] === 'held' && release_escrow($b)) {
                $r = max(1, min(5, (int)($_POST['rating'] ?? 5)));
                $review = mb_substr(trim($_POST['review'] ?? ''), 0, 300) ?: null;
                $st = $db->prepare('UPDATE bookings SET rating = ?, review = ? WHERE id = ?');
                $st->bind_param('isi', $r, $review, $id);
                $st->execute();
                // rolling average; jobs_done already includes this job
                $st = $db->prepare('UPDATE technicians SET rating = ROUND((rating * GREATEST(jobs_done - 1, 0) + ?) / GREATEST(jobs_done, 1), 2) WHERE id = ?');
                $st->bind_param('ii', $r, $b['technician_id']);
                $st->execute();
                flash('Thanks! Payment released to the technician and your 30-day digital warranty is active.');
            }
            break;
        case 'dispute':
            if (claim("UPDATE bookings SET escrow_status = 'disputed' WHERE id = ? AND status = 'completed' AND customer_confirmed = 0 AND escrow_status = 'held'", 'i', $id)) {
                flash('Dispute raised. The payment is frozen until HomeEase admin reviews it.', 'warning');
            }
            break;
        case 'warranty_claim': // free follow-up booking within 30 days, sent to the original technician
            $st = $db->prepare("SELECT * FROM warranties WHERE booking_id = ? AND status = 'active' AND valid_until >= CURDATE()");
            $st->bind_param('i', $id);
            $st->execute();
            $w = $st->get_result()->fetch_assoc();
            if ($w && claim("UPDATE warranties SET status = 'claimed' WHERE id = ? AND status = 'active'", 'i', $w['id'])) {
                $desc = 'Warranty follow-up for #' . $id . ': ' . mb_substr(trim($_POST['details'] ?? '') ?: $b['description'], 0, 300);
                $otp = new_otp();
                $when = date('Y-m-d H:i:s', strtotime('tomorrow 10:00'));
                $st = $db->prepare("INSERT INTO bookings (customer_id, service_id, technician_id, area, address, description, urgency, scheduled_at,
                    est_min, est_max, price_base, price_fee, price_total, price_locked, match_score, ai_source, status, escrow_status, otp, parent_booking_id)
                    VALUES (?,?,?,?,?,?,'normal',?,0,0,0,0,0,1,?, 'manual','pending','held',?,?)");
                $st->bind_param('iiissssisi', $b['customer_id'], $b['service_id'], $b['technician_id'], $b['area'], $b['address'], $desc, $when,
                    $b['match_score'], $otp, $id);
                $st->execute();
                $new = $db->insert_id;
                flash("Free warranty follow-up #$new booked with the same technician for tomorrow 10 AM (₹0).");
                redirect("my_bookings.php#b$new");
            }
            break;
    }
    redirect("my_bookings.php#b$id");
}

// ------------------------------------------------------------ technician
if ($u['role'] === 'provider') {
    $tid = (int)$u['tech_id'];
    if ($action === 'availability') {
        $st = $db->prepare('UPDATE technicians SET available = 1 - available WHERE id = ?');
        $st->bind_param('i', $tid);
        $st->execute();
        flash('Availability updated. Your active jobs stay on your dashboard.', 'info');
        redirect('provider.php');
    }
    $b = load_booking($id);
    if (!$b || (int)$b['technician_id'] !== $tid) redirect('provider.php');

    switch ($action) {
        case 'accept':
            if (claim("UPDATE bookings SET status = 'accepted' WHERE id = ? AND technician_id = ? AND status = 'pending' AND escrow_status = 'held'", 'ii', $id, $tid)) {
                shield_activate($id, $tid);
                flash("Job #$id accepted. Safety Shield is active while you travel. Ask the customer for the 6-digit OTP on arrival.");
            } elseif ($b['status'] === 'pending') {
                flash('You can accept once the customer has paid into escrow.', 'warning');
            }
            break;
        case 'decline':
        case 'cancel':
            if (($action === 'decline' && $b['status'] === 'pending') || ($action === 'cancel' && $b['status'] === 'accepted')) {
                $new = replace_technician($b, $action === 'decline' ? 'Technician declined' : 'Technician cancelled', $action === 'cancel');
                flash($new ? "Job #$id reassigned by AI to {$new['name']} (score {$new['score']}). Price and escrow carried over."
                    : ($action === 'cancel' ? "Job #$id cancelled. No other technician is free yet, so admin has been alerted." : 'No other technician is available, so the job stays with you.'),
                    $new ? 'info' : 'warning');
            }
            break;
        case 'start':
            if ($b['status'] === 'accepted') {
                if (trim($_POST['otp'] ?? '') === $b['otp']) {
                    if (claim("UPDATE bookings SET status = 'in_progress', started_at = NOW() WHERE id = ? AND technician_id = ? AND status = 'accepted'", 'ii', $id, $tid)) {
                        shield_set($id, $tid, 'on_job');
                        flash("OTP verified. Job #$id started.");
                    }
                } else flash('Wrong OTP. Ask the customer for the 6-digit code.', 'danger');
            }
            break;
        case 'complete':
            if (claim("UPDATE bookings SET status = 'completed', completed_at = NOW() WHERE id = ? AND technician_id = ? AND status = 'in_progress'", 'ii', $id, $tid)) {
                shield_set($id, $tid, 'return');
                $st = $db->prepare('UPDATE technicians SET jobs_done = jobs_done + 1 WHERE id = ?');
                $st->bind_param('i', $tid);
                $st->execute();
                flash("Job #$id marked complete. Payment is released when the customer confirms. Safety Shield covers your return trip for 45 min.");
            }
            break;
        case 'sos': // technician emergency: shield flagged, admin alerted, customer gets a replacement
            if (in_array($b['status'], ['accepted', 'in_progress'], true)) {
                $st = $db->prepare("UPDATE safety_shields SET status = 'sos' WHERE booking_id = ? AND technician_id = ? AND status IN ('outbound','on_job','return')");
                $st->bind_param('ii', $id, $tid);
                $st->execute();
                $new = replace_technician($b, 'Technician SOS', true);
                $st = $db->prepare('UPDATE technicians SET available = 0 WHERE id = ?');
                $st->bind_param('i', $tid);
                $st->execute();
                $_SESSION['sos_info'] = ['booking' => $id, 'hospital' => HOSPITALS[$b['area']] ?? 'Nearest government hospital', 'replacement' => $new['name'] ?? null];
                flash('SOS sent. HomeEase admin and your emergency contact have been alerted.', 'danger');
            }
            break;
    }
    redirect('provider.php');
}

// ------------------------------------------------------------ admin: settle disputes
if ($u['role'] === 'admin') {
    $b = load_booking($id);
    if ($b && $b['escrow_status'] === 'disputed') {
        if ($action === 'release' && release_escrow($b)) flash("Dispute #$id settled: payment released to the technician.");
        if ($action === 'refund' && claim("UPDATE bookings SET escrow_status = 'refunded' WHERE id = ? AND escrow_status = 'disputed'", 'i', $id)) flash("Dispute #$id settled: customer refunded.", 'info');
    }
    redirect('admin.php#disputes');
}
redirect(home_for($u['role']));
