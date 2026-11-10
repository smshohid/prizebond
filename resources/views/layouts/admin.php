<?php
use App\Core\AdminAuth;
use App\Core\Session;

$flashes = Session::pull('_flash', []);
$admin = AdminAuth::admin();
$menu = [
    ['/admin', 'Dashboard', 'bi-speedometer2', 'view_dashboard'],
    ['/admin/users', 'Users', 'bi-people', 'view_users'],
    ['/admin/bonds', 'Registered Bonds', 'bi-collection', 'view_bonds'],
    ['/admin/bond-types', 'Bond Types', 'bi-gem', 'manage_bond_types'],
    ['/admin/draws', 'Prize Draws', 'bi-trophy', 'view_draws'],
    ['/admin/imports', 'Result Imports & Verification', 'bi-cloud-download', 'review_results'],
    ['/admin/winning-numbers', 'Winning Numbers', 'bi-123', 'view_draws'],
    ['/admin/matches', 'Prize Matches', 'bi-bullseye', 'view_matches'],
    ['/admin/email-queue', 'Email Queue', 'bi-envelope-paper', 'view_email_queue'],
    ['/admin/email-logs', 'Email Logs', 'bi-envelope-check', 'view_email_queue'],
    ['/admin/sources', 'Result Sources', 'bi-globe2', 'manage_sources'],
    ['/admin/cron', 'Cron Job Monitor', 'bi-clock-history', 'view_cron'],
    ['/admin/audit', 'Audit Logs', 'bi-journal-text', 'view_audit'],
    ['/admin/roles', 'Roles & Permissions', 'bi-shield-lock', 'manage_roles'],
    ['/admin/settings', 'System Settings', 'bi-gear', 'manage_settings'],
];
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ?? 'Admin') ?> | Admin - <?= e(t('site.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="bg-light">
<nav class="navbar navbar-dark pbm-nav d-lg-none">
  <div class="container-fluid">
    <span class="navbar-brand"><i class="bi bi-award-fill"></i> Admin</span>
    <button class="navbar-toggler" data-bs-toggle="collapse" data-bs-target="#sidebar" aria-label="Menu"><span class="navbar-toggler-icon"></span></button>
  </div>
</nav>
<div class="container-fluid">
  <div class="row">
    <aside id="sidebar" class="col-lg-2 collapse d-lg-block pbm-side p-3">
      <a class="d-none d-lg-block text-white text-decoration-none fw-bold mb-3" href="<?= e(url('/admin')) ?>"><i class="bi bi-award-fill"></i> <?= e(t('site.name')) ?></a>
      <ul class="nav flex-column small">
        <?php foreach ($menu as [$href, $label, $icon, $perm]): if (!AdminAuth::can($perm)) { continue; } ?>
          <li class="nav-item"><a class="nav-link text-white-50" href="<?= e(url($href)) ?>"><i class="bi <?= e($icon) ?> me-2"></i><?= e($label) ?></a></li>
        <?php endforeach; ?>
      </ul>
      <hr class="border-secondary">
      <div class="small text-white-50 mb-2"><?= e($admin['full_name'] ?? '') ?><br><span class="badge text-bg-secondary"><?= e($admin['role_name'] ?? '') ?></span></div>
      <form method="post" action="<?= e(url('/admin/logout')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-outline-light w-100">Sign out</button></form>
    </aside>
    <main class="col-lg-10 p-3 p-lg-4">
      <?php foreach ($flashes as $f): ?>
        <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert"><?= e($f['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
      <?php endforeach; ?>
      <?= $content ?>
    </main>
  </div>
</div>
<script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
