<div class="container">
  <h1 class="h3"><?= e(t('nav.results')) ?></h1>
  <?php if (!$draws): ?>
    <div class="alert alert-info"><?= e(t('home.no_results')) ?></div>
  <?php else: ?>
  <div class="table-responsive"><table class="table table-hover align-middle">
    <thead><tr><th><?= e(t('draw.draw')) ?></th><th><?= e(t('draw.date')) ?></th><th><?= e(t('bond.type')) ?></th><th><?= e(t('draw.series')) ?></th><th><?= e(t('draw.numbers')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($draws as $d): ?>
      <tr><td><strong>#<?= (int) $d['draw_number'] ?></strong></td><td><?= e(fmt_date($d['draw_date'])) ?></td><td><?= e($d['bond_name']) ?></td>
          <td><?= $d['series_count'] ? (int) $d['series_count'] : '—' ?></td><td><?= (int) $d['cnt'] ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/results/' . $d['id'])) ?>"><?= e(t('draw.details')) ?></a></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php include base_path('resources/views/partials/pager.php'); ?>
  <?php endif; ?>
</div>
