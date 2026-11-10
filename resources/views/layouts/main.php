<?php
use App\Core\Auth;
use App\Core\Lang;
use App\Core\Session;

$loc = Lang::locale();
$flashes = Session::pull('_flash', []);
$user = Auth::user();
$pageTitle = ($title ?? '') !== '' ? $title . ' | ' . t('site.name') : t('site.name');
$desc = $desc ?? t('meta.home');
$canon = isset($canonical) ? app_url($canonical) : null;
$noindex = !empty($robots);
?><!doctype html>
<html lang="<?= e($loc) ?>">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($pageTitle) ?></title>
<meta name="description" content="<?= e($desc) ?>">
<?php if ($noindex): ?><meta name="robots" content="noindex,nofollow"><?php endif; ?>
<?php if ($canon && !$noindex): ?><link rel="canonical" href="<?= e($canon) ?>"><?php endif; ?>
<meta property="og:type" content="website">
<meta property="og:site_name" content="<?= e(t('site.name')) ?>">
<meta property="og:title" content="<?= e($pageTitle) ?>">
<meta property="og:description" content="<?= e($desc) ?>">
<?php if ($canon): ?><meta property="og:url" content="<?= e($canon) ?>"><?php endif; ?>
<meta name="theme-color" content="#0b2447">
<link rel="icon" href="<?= e(asset('images/favicon.svg')) ?>" type="image/svg+xml">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Noto+Sans+Bengali:wght@400;600;700&amp;family=Inter:wght@400;600;700&amp;display=swap">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('vendor/bootstrap-icons.min.css')) ?>">
<link rel="stylesheet" href="<?= e(asset('css/app.css')) ?>">
</head>
<body class="pbm-body">
<a class="visually-hidden-focusable" href="#main"><?= e(t('common.skip')) ?></a>
<nav class="navbar navbar-expand-lg pbm-nav navbar-dark">
  <div class="container">
    <a class="navbar-brand fw-bold" href="<?= e(url('/')) ?>"><i class="bi bi-award-fill me-1"></i><?= e(t('site.name')) ?></a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#nav" aria-controls="nav" aria-expanded="false" aria-label="Menu"><span class="navbar-toggler-icon"></span></button>
    <div class="collapse navbar-collapse" id="nav">
      <ul class="navbar-nav me-auto">
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/check')) ?>"><?= e(t('nav.check')) ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/results')) ?>"><?= e(t('nav.results')) ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/how-it-works')) ?>"><?= e(t('nav.how')) ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/faq')) ?>"><?= e(t('nav.faq')) ?></a></li>
        <?php if ($user): ?>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/dashboard')) ?>"><?= e(t('nav.dashboard')) ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/bonds')) ?>"><?= e(t('nav.my_bonds')) ?></a></li>
        <li class="nav-item"><a class="nav-link" href="<?= e(url('/matches')) ?>"><?= e(t('nav.my_matches')) ?></a></li>
        <?php endif; ?>
      </ul>
      <div class="d-flex align-items-center gap-2 flex-wrap">
        <div class="btn-group btn-group-sm" role="group" aria-label="Language">
          <a class="btn <?= $loc === 'en' ? 'btn-light' : 'btn-outline-light' ?>" href="<?= e(url('/lang/en')) ?>" lang="en">EN</a>
          <a class="btn <?= $loc === 'bn' ? 'btn-light' : 'btn-outline-light' ?>" href="<?= e(url('/lang/bn')) ?>" lang="bn">বাংলা</a>
        </div>
        <?php if ($user): ?>
          <a class="btn btn-sm btn-outline-light" href="<?= e(url('/profile')) ?>"><i class="bi bi-person-circle"></i> <?= e($user['full_name']) ?></a>
          <form method="post" action="<?= e(url('/logout')) ?>" class="d-inline"><?= csrf_field() ?><button class="btn btn-sm btn-warning"><?= e(t('nav.logout')) ?></button></form>
        <?php else: ?>
          <a class="btn btn-sm btn-outline-light" href="<?= e(url('/login')) ?>"><?= e(t('nav.login')) ?></a>
          <a class="btn btn-sm btn-warning" href="<?= e(url('/register')) ?>"><?= e(t('nav.register')) ?></a>
        <?php endif; ?>
      </div>
    </div>
  </div>
</nav>

<main id="main" class="py-4">
  <div class="container">
    <?php foreach ($flashes as $f): ?>
      <div class="alert alert-<?= e($f['type']) ?> alert-dismissible fade show" role="alert"><?= e($f['msg']) ?><button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>
    <?php endforeach; ?>
  </div>
  <?= $content ?>
</main>

<footer class="pbm-footer mt-5 py-4">
  <div class="container">
    <div class="row g-4">
      <div class="col-md-5">
        <h2 class="h6 text-white"><?= e(t('site.name')) ?></h2>
        <p class="small mb-2"><?= e(t('footer.disclaimer')) ?></p>
      </div>
      <div class="col-6 col-md-3">
        <h2 class="h6 text-white"><?= e(t('footer.links')) ?></h2>
        <ul class="list-unstyled small">
          <li><a href="<?= e(url('/about')) ?>"><?= e(t('nav.about')) ?></a></li>
          <li><a href="<?= e(url('/contact')) ?>"><?= e(t('nav.contact')) ?></a></li>
          <li><a href="<?= e(url('/privacy')) ?>"><?= e(t('nav.privacy')) ?></a></li>
          <li><a href="<?= e(url('/terms')) ?>"><?= e(t('nav.terms')) ?></a></li>
        </ul>
      </div>
      <div class="col-6 col-md-4">
        <h2 class="h6 text-white"><?= e(t('footer.official')) ?></h2>
        <ul class="list-unstyled small">
          <li><a rel="noopener noreferrer" target="_blank" href="https://www.bb.org.bd/en/index.php/investfacility/prizebond">Bangladesh Bank</a></li>
          <li><a rel="noopener noreferrer" target="_blank" href="https://prizebond.ird.gov.bd/">IRD PBRIS</a></li>
          <li><a rel="noopener noreferrer" target="_blank" href="https://nationalsavings.gov.bd/">National Savings Directorate</a></li>
        </ul>
      </div>
    </div>
    <hr class="border-secondary">
    <p class="small mb-0">&copy; <?= e(gmdate('Y')) ?> <?= e(t('site.name')) ?>. <?= e(t('footer.notaffiliated')) ?></p>
  </div>
</footer>
<script src="<?= e(asset('vendor/bootstrap.bundle.min.js')) ?>"></script>
<script src="<?= e(asset('js/app.js')) ?>"></script>
</body>
</html>
