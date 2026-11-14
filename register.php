<?php
require_once __DIR__ . '/includes/functions.php';
if (user()) redirect(home_for(user()['role']));

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim($_POST['name'] ?? '');
    $email = strtolower(trim($_POST['email'] ?? ''));
    $phone = preg_replace('/\D/', '', $_POST['phone'] ?? '');
    $area = $_POST['area'] ?? '';
    $pass = $_POST['password'] ?? '';
    if (strlen($name) < 2) $errors[] = 'Enter your name.';
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid email.';
    if (strlen($phone) !== 10) $errors[] = 'Enter a 10-digit phone number.';
    if (!isset(AREAS[$area])) $errors[] = 'Choose your area.';
    if (strlen($pass) < 6) $errors[] = 'Password must be at least 6 characters.';
    if (!$errors) {
        $st = db()->prepare('SELECT id FROM users WHERE email = ?');
        $st->bind_param('s', $email);
        $st->execute();
        if ($st->get_result()->num_rows) $errors[] = 'That email is already registered. Log in instead.';
    }
    if (!$errors) {
        $hash = password_hash($pass, PASSWORD_DEFAULT);
        $st = db()->prepare("INSERT INTO users (name, email, phone, password_hash, role, area) VALUES (?,?,?,?,'customer',?)");
        $st->bind_param('sssss', $name, $email, $phone, $hash, $area);
        $st->execute();
        session_regenerate_id(true);
        $_SESSION['user'] = ['id' => db()->insert_id, 'name' => $name, 'email' => $email, 'role' => 'customer', 'area' => $area, 'tech_id' => null];
        flash("Welcome to HomeEase AI, $name!");
        redirect('index.php');
    }
}
$title = 'Sign up';
include __DIR__ . '/includes/header.php';
?>
<div class="container py-5">
  <div class="row justify-content-center">
    <div class="col-md-7 col-lg-6">
      <div class="card he-card p-4 p-md-5">
        <h1 class="h3 fw-bold mb-1">Create your account</h1>
        <p class="text-muted mb-4">Book verified professionals in minutes.</p>
        <?php if ($errors): ?><div class="alert alert-danger rounded-4"><?= implode('<br>', array_map('e', $errors)) ?></div><?php endif; ?>
        <form method="post" class="row g-3">
          <?= csrf_field() ?>
          <div class="col-12"><label class="form-label">Full name</label><input class="form-control" name="name" required value="<?= e($_POST['name'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Email</label><input class="form-control" type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Phone</label><input class="form-control" name="phone" maxlength="10" required value="<?= e($_POST['phone'] ?? '') ?>"></div>
          <div class="col-md-6"><label class="form-label">Area</label>
            <select class="form-select" name="area" required>
              <option value="">Choose…</option>
              <?php foreach (array_keys(AREAS) as $a): ?><option <?= ($_POST['area'] ?? '') === $a ? 'selected' : '' ?>><?= e($a) ?></option><?php endforeach; ?>
            </select></div>
          <div class="col-md-6"><label class="form-label">Password</label><input class="form-control" type="password" name="password" minlength="6" required></div>
          <div class="col-12"><button class="btn btn-coral btn-lg w-100 rounded-pill mt-2">Create account</button></div>
        </form>
        <p class="mt-3 mb-0 small text-center">Already registered? <a href="login.php">Log in</a></p>
      </div>
    </div>
  </div>
</div>
<?php include __DIR__ . '/includes/footer.php'; ?>
