<?php
// Smart price negotiation between customer and technician, with the AI Fair Price guardrail.
require_once __DIR__ . '/includes/functions.php';
$u = require_login();
$id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
$b = load_booking($id);
$isCust = $b && $u['role'] === 'customer' && (int)$b['customer_id'] === $u['id'];
$isTech = $b && $u['role'] === 'provider' && (int)$b['technician_id'] === (int)$u['tech_id'];
if (!$b || !($isCust || $isTech || $u['role'] === 'admin')) { flash('Negotiation not found.', 'warning'); redirect(home_for($u['role'])); }
$me = $isCust ? 'customer' : ($isTech ? 'provider' : 'admin');
$other = $me === 'customer' ? 'provider' : 'customer';
$db = db();

function latest_offer(int $id, string $sender): ?array {
    $st = db()->prepare("SELECT * FROM price_negotiations WHERE booking_id = ? AND sender = ? AND status = 'proposed' ORDER BY id DESC LIMIT 1");
    $st->bind_param('is', $id, $sender);
    $st->execute();
    return $st->get_result()->fetch_assoc() ?: null;
}
function lock_price(array $b, int $amount, string $note): void {
    $st = db()->prepare("UPDATE bookings SET negotiated_price = ?, price_total = ?, price_locked = 1, status = 'pending' WHERE id = ?");
    $st->bind_param('iii', $amount, $amount, $b['id']);
    $st->execute();
    $st = db()->prepare("INSERT INTO price_negotiations (booking_id, sender, amount, fairness, message, status) VALUES (?, 'ai', ?, ?, ?, 'info')");
    $f = fairness($amount, (int)$b['est_max']);
    $st->bind_param('iiss', $b['id'], $amount, $f, $note);
    $st->execute();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $me !== 'admin') {
    csrf_check();
    if ($b['status'] !== 'negotiating') { flash('This price is already locked.', 'info'); redirect("negotiate.php?id=$id"); }
    $action = $_POST['action'] ?? '';
    $theirs = latest_offer($id, $other);
    if ($action === 'propose') {
        $amount = (int)($_POST['amount'] ?? 0);
        $msg = mb_substr(trim($_POST['message'] ?? ''), 0, 300) ?: null;
        if ($amount < 1 || $amount > 100000) { flash('Enter a valid amount.', 'warning'); redirect("negotiate.php?id=$id"); }
        if ($theirs) { $st = $db->prepare("UPDATE price_negotiations SET status = 'countered' WHERE id = ?"); $st->bind_param('i', $theirs['id']); $st->execute(); }
        // withdraw my own earlier open offer
        $st = $db->prepare("UPDATE price_negotiations SET status = 'countered' WHERE booking_id = ? AND sender = ? AND status = 'proposed'");
        $st->bind_param('is', $id, $me); $st->execute();
        $fair = $me === 'provider' ? fairness($amount, (int)$b['est_max']) : 'within';
        $st = $db->prepare("INSERT INTO price_negotiations (booking_id, sender, amount, fairness, message, status) VALUES (?,?,?,?,?,'proposed')");
        $st->bind_param('isiss', $id, $me, $amount, $fair, $msg);
        $st->execute();
        flash($fair === 'high' ? 'Offer sent. Note: the AI flagged it as unusually high for this job.' : 'Offer sent.', $fair === 'high' ? 'warning' : 'success');
    } elseif ($action === 'accept' && $theirs) {
        $st = $db->prepare("UPDATE price_negotiations SET status = 'accepted' WHERE id = ?"); $st->bind_param('i', $theirs['id']); $st->execute();
        lock_price($b, (int)$theirs['amount'], 'Price locked at ' . inr($theirs['amount']) . ' by mutual agreement.');
        flash('Price locked at ' . inr($theirs['amount']) . ($me === 'customer' ? '. Pay into escrow to confirm the booking.' : '. Waiting for the customer to pay into escrow.'));
        redirect($me === 'customer' ? "my_bookings.php#b$id" : 'provider.php');
    } elseif ($action === 'reject' && $theirs) {
        $st = $db->prepare("UPDATE price_negotiations SET status = 'rejected' WHERE id = ?"); $st->bind_param('i', $theirs['id']); $st->execute();
        flash('Offer rejected. You can send a counteroffer.', 'info');
    } elseif ($action === 'accept_ai' && $me === 'customer') {
        lock_price($b, (int)$b['price_total'], 'Customer chose the AI smart price ' . inr($b['price_total']) . '.');
        flash('Price locked at the AI price. Pay into escrow to confirm the booking.');
        redirect("my_bookings.php#b$id");
    }
    redirect("negotiate.php?id=$id");
}

$st = $db->prepare("SELECT * FROM price_negotiations WHERE booking_id = ? ORDER BY id");
$st->bind_param('i', $id);
$st->execute();
$thread = $st->get_result()->fetch_all(MYSQLI_ASSOC);
$theirs = latest_offer($id, $other);
$st = $db->prepare("SELECT s.name service, c.name cust, tu.name tech FROM bookings b JOIN services s ON s.id = b.service_id JOIN users c ON c.id = b.customer_id
    LEFT JOIN technicians t ON t.id = b.technician_id LEFT JOIN users tu ON tu.id = t.user_id WHERE b.id = ?");
$st->bind_param('i', $id);
$st->execute();
$info = $st->get_result()->fetch_assoc();
$open = $b['status'] === 'negotiating';
$suggest = (int)$b['est_max'];

$title = 'Price negotiation';
$live = true;
include __DIR__ . '/includes/header.php';
$who = ['customer' => $info['cust'], 'provider' => $info['tech'], 'ai' => 'HomeEase AI'];
?>
<div class="container py-5" style="max-width:860px">
  <a href="<?= $me === 'customer' ? 'my_bookings.php' : home_for($u['role']) ?>" class="small text-decoration-none"><i class="bi bi-arrow-left"></i> Back</a>
  <div class="d-flex flex-wrap justify-content-between align-items-end gap-2 mt-2 mb-3">
    <div><h1 class="h3 fw-bold mb-0">Price negotiation · #<?= $id ?></h1>
      <small class="text-muted"><?= e($info['service']) ?> · <?= e($b['description']) ?> · <?= e($info['cust']) ?> with <?= e($info['tech'] ?? 'technician') ?></small></div>
    <span class="badge rounded-pill text-bg-<?= $open ? 'dark' : ($b['status'] === 'cancelled' ? 'secondary' : 'success') ?> fs-6"><?= $open ? 'Negotiating' : ($b['status'] === 'cancelled' ? 'Cancelled' : 'Price locked ' . inr($b['price_total'])) ?></span>
  </div>

  <div class="card he-card p-3 mb-3">
    <div class="row text-center g-2">
      <div class="col"><small class="text-muted d-block">AI smart price</small><b class="fs-5"><?= inr($thread[0]['amount'] ?? $b['price_total']) ?></b></div>
      <div class="col"><small class="text-muted d-block">Fair range</small><b class="fs-5 text-teal"><?= inr($b['est_min']) ?> – <?= inr($b['est_max']) ?></b></div>
      <div class="col"><small class="text-muted d-block">Unusually high above</small><b class="fs-5 text-danger"><?= inr(round(1.15 * $b['est_max'])) ?></b></div>
    </div>
  </div>

  <?php if ($open && $me === 'customer' && $theirs && $theirs['fairness'] === 'high'): ?>
    <div class="alert alert-danger rounded-4 d-flex gap-3 align-items-center">
      <i class="bi bi-exclamation-octagon-fill fs-3"></i>
      <div class="flex-grow-1"><b>Unusually high quote.</b> <?= inr($theirs['amount']) ?> is more than 15% above the fair ceiling for this job.
        AI suggests countering at <b><?= inr($suggest) ?></b>.</div>
      <button class="btn btn-light rounded-pill" onclick="document.querySelector('[name=amount]').value=<?= $suggest ?>;document.querySelector('[name=amount]').focus()">Use <?= inr($suggest) ?></button>
    </div>
  <?php endif; ?>

  <div class="card he-card p-4 mb-3">
    <?php foreach ($thread as $n): $mine = $n['sender'] === $me; [$fl, $fc] = FAIRNESS_LABEL[$n['fairness']]; ?>
      <div class="d-flex <?= $n['sender'] === 'ai' ? 'justify-content-center' : ($mine ? 'justify-content-end' : '') ?> mb-3">
        <div class="neg-msg <?= $n['sender'] === 'ai' ? 'ai' : ($mine ? 'mine' : '') ?>">
          <div class="small fw-semibold mb-1"><?= $n['sender'] === 'ai' ? '<i class="bi bi-robot"></i> ' : '' ?><?= e($who[$n['sender']] ?? '') ?> · <span class="text-muted fw-normal"><?= date('g:i A', strtotime($n['created_at'])) ?></span></div>
          <?php if ($n['sender'] !== 'ai'): ?>
            <div class="fs-4 fw-bold"><?= inr($n['amount']) ?></div>
            <?php if ($n['sender'] === 'provider'): ?><span class="badge rounded-pill text-bg-<?= $fc ?>"><?= $fl ?></span><?php endif; ?>
            <span class="badge rounded-pill text-bg-light border"><?= ucfirst($n['status']) ?></span>
          <?php endif; ?>
          <?php if ($n['message']): ?><div class="small mt-1"><?= e($n['message']) ?></div><?php endif; ?>
        </div>
      </div>
    <?php endforeach; ?>

    <?php if ($open && $me !== 'admin'): ?>
      <?php if ($theirs): ?>
        <div class="d-flex flex-wrap gap-2 align-items-center border-top pt-3 mb-3">
          <span class="small">Latest offer from <?= $other === 'provider' ? 'technician' : 'customer' ?>: <b><?= inr($theirs['amount']) ?></b></span>
          <form method="post" class="ms-auto d-flex gap-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
            <button name="action" value="reject" class="btn btn-sm btn-outline-secondary rounded-pill">Reject</button>
            <button name="action" value="accept" class="btn btn-sm btn-success rounded-pill">Accept & lock <?= inr($theirs['amount']) ?></button>
          </form>
        </div>
      <?php else: ?>
        <p class="small text-muted border-top pt-3">Waiting for the <?= $other === 'provider' ? 'technician' : 'customer' ?> to respond. You can update your offer.</p>
      <?php endif; ?>
      <form method="post" class="row g-2 align-items-end"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>"><input type="hidden" name="action" value="propose">
        <div class="col-md-3"><label class="form-label small">Your offer (₹)</label><input type="number" name="amount" class="form-control" min="1" required value="<?= $me === 'customer' ? (int)$b['est_min'] : (int)$b['price_total'] ?>"></div>
        <div class="col-md-6"><label class="form-label small">Message (optional)</label><input name="message" class="form-control" maxlength="300" placeholder="<?= $me === 'customer' ? 'e.g. Small job, can you do it for this?' : 'e.g. Needs extra parts' ?>"></div>
        <div class="col-md-3"><button class="btn btn-coral w-100 rounded-pill"><?= $theirs ? 'Counteroffer' : 'Send offer' ?></button></div>
      </form>
      <?php if ($me === 'customer'): ?>
        <form method="post" class="mt-2"><?= csrf_field() ?><input type="hidden" name="id" value="<?= $id ?>">
          <button name="action" value="accept_ai" class="btn btn-link btn-sm p-0">Skip negotiation and book at the AI price <?= inr($thread[0]['amount'] ?? $b['price_total']) ?></button></form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
  <?php if (!$open && $me === 'customer'): ?>
    <a href="my_bookings.php#b<?= $id ?>" class="btn btn-coral rounded-pill">Go to booking<?= $b['escrow_status'] === 'unpaid' ? ' and pay into escrow' : '' ?></a>
  <?php endif; ?>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
