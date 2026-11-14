<?php
require_once __DIR__ . '/functions.php';
$u = user();
$isPro = $u && $u['role'] === 'customer' && is_premium($u);
$page = basename($_SERVER['PHP_SELF']);
$subtitles = ['chat.php' => 'AI Chat · Premium', 'premium.php' => 'Premium', 'index.php' => 'Diagnose', 'book.php' => 'Match Pro', 'match.php' => 'Match Pro', 'negotiate.php' => 'Negotiation', 'my_bookings.php' => 'Bookings',
    'provider.php' => 'Provider', 'admin.php' => 'Analytics', 'admin_bookings.php' => 'All bookings', 'sos.php' => 'Emergency SOS', 'login.php' => 'Welcome', 'register.php' => 'Sign up'];
function nav_active($pages, $page) { return in_array($page, (array)$pages, true) ? ' active' : ''; }
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($title ?? 'HomeEase AI') ?> · HomeEase AI</title>
<link rel="icon" href="data:image/svg+xml,<svg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 100 100'><text y='.9em' font-size='90'>🏠</text></svg>">
<link href="assets/vendor/bootstrap/bootstrap.min.css" rel="stylesheet">
<link href="assets/vendor/bootstrap-icons/bootstrap-icons.min.css" rel="stylesheet">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link href="assets/css/style.css" rel="stylesheet">
<script>try{if(sessionStorage.getItem("he-noanim")){document.documentElement.classList.add("no-anim");sessionStorage.removeItem("he-noanim")}}catch(e){}</script>
</head>
<body>
<header class="he-top">
  <div class="container wrap">
    <a href="<?= $u ? home_for($u['role']) : 'index.php' ?>" class="d-flex align-items-center gap-2 text-decoration-none">
      <span class="brand-mark"><i class="bi bi-house-heart-fill"></i></span>
      <span><span class="brand-name d-block">HomeEase<span style="color:var(--lime-dark)">AI</span></span><span class="brand-sub"><?= e($subtitles[$page] ?? '') ?></span></span>
    </a>
    <div class="ms-auto d-flex align-items-center gap-2">
      <?php if (!$u || $u['role'] === 'customer'): ?><a class="btn btn-sm btn-danger rounded-pill px-3" href="sos.php"><i class="bi bi-exclamation-triangle-fill"></i> SOS</a><?php endif; ?>
      <?php if ($u && !empty($live)): ?><span class="pill pill-soft d-none d-sm-inline-flex" title="This page updates automatically"><span class="dot"></span> Live</span><?php endif; ?>
      <?php if ($u): ?>
        <span class="small text-muted d-none d-md-inline"><?= e($u['name']) ?> · <span class="text-capitalize"><?= e($u['role']) ?></span></span>
        <?php if ($isPro): ?><a href="premium.php" class="pro-badge text-decoration-none"><i class="bi bi-gem"></i> PRO</a><?php endif; ?>
        <span class="me-avatar" title="<?= e($u['name']) ?>"><?= e(mb_substr($u['name'], 0, 1)) ?></span>
        <a class="btn btn-sm btn-soft rounded-pill" href="logout.php" title="Log out"><i class="bi bi-box-arrow-right"></i></a>
      <?php else: ?>
        <a class="btn btn-sm btn-soft rounded-pill px-3" href="login.php">Log in</a>
        <a class="btn btn-sm btn-coral rounded-pill px-3" href="register.php">Sign up</a>
      <?php endif; ?>
    </div>
  </div>
</header>
<nav class="he-bottom">
  <?php if (!$u || $u['role'] === 'customer'): ?>
    <a href="index.php" class="<?= nav_active('index.php', $page) ?>"><i class="bi bi-stars"></i>Diagnose</a>
    <a href="book.php" class="<?= nav_active(['book.php', 'match.php', 'negotiate.php'], $page) ?>"><i class="bi bi-person-check"></i>Match Pro</a>
    <a href="my_bookings.php" class="<?= nav_active('my_bookings.php', $page) ?>"><i class="bi bi-calendar2-check"></i>Bookings<?php if ($u): ?><span class="nav-dot"></span><?php endif; ?></a>
    <a href="<?= $isPro ? 'chat.php' : 'premium.php' ?>" class="<?= nav_active(['chat.php', 'premium.php'], $page) ?>"><i class="bi bi-robot"></i>AI Chat</a>
    <a href="sos.php" class="sos<?= nav_active('sos.php', $page) ?>"><i class="bi bi-exclamation-triangle"></i>SOS</a>
  <?php elseif ($u['role'] === 'provider'): ?>
    <a href="provider.php" class="<?= nav_active('provider.php', $page) ?>"><i class="bi bi-lightning-charge"></i>Dispatch</a>
    <a href="provider.php#active" class=""><i class="bi bi-briefcase"></i>Active</a>
    <a href="provider.php#done" class=""><i class="bi bi-wallet2"></i>Earnings</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i>Log out</a>
  <?php else: ?>
    <a href="admin.php" class="<?= nav_active('admin.php', $page) ?>"><i class="bi bi-graph-up-arrow"></i>Analytics</a>
    <a href="admin.php#disputes"><i class="bi bi-broadcast"></i>SOS & Trust</a>
    <a href="admin_bookings.php" class="<?= nav_active('admin_bookings.php', $page) ?>"><i class="bi bi-list-check"></i>Bookings</a>
    <a href="logout.php"><i class="bi bi-box-arrow-right"></i>Log out</a>
  <?php endif; ?>
</nav>
<main class="pb-4">
<?php if ($f = flash()): ?>
  <div class="container mt-3"><div class="alert alert-<?= e($f[1]) ?> alert-dismissible fade show" role="alert"><?= e($f[0]) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div></div>
<?php endif; ?>
