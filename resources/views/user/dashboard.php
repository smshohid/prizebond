<div class="container">
  <h1 class="h3 mb-3"><?= e(t('dash.welcome', ['name' => \App\Core\Auth::user()['full_name']])) ?></h1>
  <div class="row g-3 mb-4">
    <?php foreach ([['bonds', 'dash.bonds', 'bi-collection', '/bonds'], ['draws', 'dash.draws', 'bi-trophy', '/results'], ['wins', 'dash.wins', 'bi-bullseye', '/matches']] as [$k, $lab, $ic, $href]): ?>
      <div class="col-6 col-lg-3"><a class="text-decoration-none" href="<?= e(url($href)) ?>"><div class="card stat-card h-100 card-hover"><div class="card-body"><div class="text-muted small"><i class="bi <?= e($ic) ?>"></i> <?= e(t($lab)) ?></div><div class="num"><?= e(number_format($stats[$k])) ?></div></div></div></a></div>
    <?php endforeach; ?>
    <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="text-muted small"><i class="bi bi-envelope"></i> <?= e(t('dash.email_status')) ?></div>
      <div class="fw-bold mt-1"><?= $alerts ? '<span class="text-success">' . e(t('dash.alerts_on')) . '</span>' : '<span class="text-danger">' . e(t('dash.alerts_off')) . '</span>' ?></div>
      <?php if ($mailFailures): ?><div class="small text-danger"><?= e(t('dash.mail_problem', ['n' => $mailFailures])) ?></div><?php endif; ?></div></div></div>
  </div>
  <div class="card mb-4"><div class="card-body">
    <h2 class="h5"><?= e(t('dash.latest_draw')) ?></h2>
    <?php if ($latest): ?>
      <p class="mb-1"><?= e(t('draw.title', ['n' => $latest['draw_number']])) ?> · <?= e(fmt_date($latest['draw_date'])) ?></p>
      <p class="small text-muted mb-2"><?= e(t('dash.lifecycle_' . $latest['lifecycle'])) ?></p>
      <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/results/' . $latest['id'])) ?>"><?= e(t('draw.details')) ?></a>
    <?php else: ?><p class="mb-0 text-muted"><?= e(t('home.no_results')) ?></p><?php endif; ?>
  </div></div>
  <?php if ($stats['bonds'] === 0): ?><div class="alert alert-info"><?= e(t('dash.no_bonds')) ?> <a href="<?= e(url('/bonds')) ?>"><?= e(t('bond.add')) ?></a></div><?php endif; ?>
  <div class="alert alert-secondary small"><?= e(t('footer.disclaimer')) ?></div>
</div>
