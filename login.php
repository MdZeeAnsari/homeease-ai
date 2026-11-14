<?php
require_once __DIR__ . '/includes/functions.php';
if (user()) redirect(home_for(user()['role']));

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $_SESSION['login_fails'] = array_values(array_filter($_SESSION['login_fails'] ?? [], fn($t) => $t > time() - 300));
    if (count($_SESSION['login_fails']) >= 5) {
        $error = 'Too many wrong attempts. Wait 5 minutes and try again.';
        goto render;
    }
    $email = strtolower(trim($_POST['email'] ?? ''));
    $st = db()->prepare("SELECT u.*, t.id AS tech_id FROM users u LEFT JOIN technicians t ON t.user_id = u.id WHERE u.email = ?");
    $st->bind_param('s', $email);
    $st->execute();
    $row = $st->get_result()->fetch_assoc();
    if ($row && password_verify($_POST['password'] ?? '', $row['password_hash'])) {
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => (int)$row['id'], 'name' => $row['name'], 'email' => $row['email'],
            'role' => $row['role'], 'area' => $row['area'], 'tech_id' => $row['tech_id'] ? (int)$row['tech_id'] : null];
        $next = $_SESSION['after_login'] ?? null;
        unset($_SESSION['after_login']);
        redirect($next && $row['role'] === 'customer' ? $next : home_for($row['role']));
    }
    $_SESSION['login_fails'][] = time();
    usleep(400000);
    $error = 'Wrong email or password.';
}
render:
$title = 'Log in';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-6 col-lg-5">
      <div class="card he-card p-4 p-md-5">
        <h1 class="h3 fw-bold mb-1">Welcome back</h1>
        <p class="text-muted mb-4">Log in to book and track your services.</p>
        <?php if ($error): ?><div class="alert alert-danger rounded-4"><?= e($error) ?></div><?php endif; ?>
        <form method="post">
          <?= csrf_field() ?>
          <div class="mb-3"><label class="form-label">Email</label><input class="form-control form-control-lg" type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>
          <div class="mb-4"><label class="form-label">Password</label><input class="form-control form-control-lg" type="password" name="password" required></div>
          <button class="btn btn-coral btn-lg w-100 rounded-pill">Log in</button>
        </form>
        <p class="mt-3 mb-0 small text-center">New here? <a href="register.php">Create an account</a></p>
      </div>
      <div class="card he-card p-3 mt-3 small">
        <div class="fw-semibold mb-2"><i class="bi bi-key"></i> Demo accounts (one click)</div>
        <div class="d-flex flex-wrap gap-2">
          <button class="btn btn-sm btn-light rounded-pill demo" data-e="priya@homeease.in" data-p="demo123">Customer · Priya</button>
          <button class="btn btn-sm btn-light rounded-pill demo" data-e="ravi@homeease.in" data-p="demo123">Technician · Ravi</button>
          <button class="btn btn-sm btn-light rounded-pill demo" data-e="admin@homeease.in" data-p="admin123">Admin</button>
        </div>
      </div>
    </div>
  </div>
</div>
<script>
document.querySelectorAll('.demo').forEach(b => b.onclick = () => {
  document.querySelector('[name=email]').value = b.dataset.e;
  document.querySelector('[name=password]').value = b.dataset.p;
  document.querySelector('form').submit();
});
</script>
<?php include __DIR__ . '/includes/footer.php'; ?>
