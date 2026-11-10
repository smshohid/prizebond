<?php
use App\Core\Session;

$flashes = Session::pull('_flash', []);
?><!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title><?= e($title ?? '') ?> | <?= e(t('site.name')) ?></title>
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="pbm-body">
<main class="container py-5">
  <?php foreach ($flashes as $f): ?>
    <div class="alert alert-<?= e($f['type']) ?>" role="alert"><?= e($f['msg']) ?></div>
  <?php endforeach; ?>
  <?= $content ?>
</main>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
