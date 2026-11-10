<h1 class="h3 mb-3">Dashboard</h1>
<div class="row g-3 mb-4">
  <?php foreach ($stats as $label => $val): ?>
    <div class="col-6 col-md-4 col-xl-3"><div class="card stat-card h-100"><div class="card-body"><div class="text-muted small"><?= e($label) ?></div><div class="num"><?= e(number_format($val)) ?></div></div></div></div>
  <?php endforeach; ?>
</div>
<div class="row g-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h2 class="h6">Source monitoring</h2>
    <p class="mb-1">Last successful check: <strong><?= e(fmt_dt($src['last_success_at'] ?? null)) ?></strong></p>
    <?php if (!empty($src['last_error'])): ?><div class="alert alert-danger small mb-0">Last error: <?= e($src['last_error']) ?></div><?php endif; ?>
    <?php if (\App\Core\AdminAuth::can('import_results')): ?>
    <form method="post" action="<?= e(url('/admin/imports/fetch')) ?>" class="mt-2"><?= csrf_field() ?><button class="btn btn-sm btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Check Bangladesh Bank now</button></form>
    <?php endif; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body">
    <h2 class="h6">Needs attention</h2>
    <?php if (!$attention): ?><p class="text-muted mb-0">Nothing waiting.</p><?php else: ?>
    <ul class="list-unstyled mb-0"><?php foreach ($attention as $a): ?>
      <li><a href="<?= e(url('/admin/imports/' . $a['id'])) ?>">Import #<?= (int) $a['id'] ?> (draw <?= e($a['draw_number'] ?? '?') ?>)</a> <span class="badge text-bg-warning"><?= e($a['status']) ?></span></li>
    <?php endforeach; ?></ul><?php endif; ?>
  </div></div></div>
  <div class="col-12"><div class="card"><div class="card-body">
    <h2 class="h6">Recent cron runs</h2>
    <?php if (!$cron): ?><p class="text-muted mb-0">No cron runs recorded yet. See CRON_SETUP.md.</p><?php else: ?>
    <div class="table-responsive"><table class="table table-sm mb-0"><tbody><?php foreach ($cron as $c): ?>
      <tr><td><?= e($c['job_name']) ?></td><td><span class="badge text-bg-<?= $c['status'] === 'success' ? 'success' : ($c['status'] === 'failed' ? 'danger' : 'secondary') ?>"><?= e($c['status']) ?></span></td><td><?= e(fmt_dt($c['started_at'])) ?></td><td class="small"><?= e($c['message']) ?></td></tr>
    <?php endforeach; ?></tbody></table></div><?php endif; ?>
  </div></div></div>
</div>
