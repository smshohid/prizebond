<?php $bn = \App\Core\Lang::locale() === 'bn'; ?>
<div class="container">
  <h1 class="h3 mb-3"><?= e(t('nav.my_matches')) ?></h1>
  <div class="alert alert-warning"><i class="bi bi-exclamation-triangle"></i> <?= e(t('match.disclaimer')) ?></div>
  <?php if (!$rows): ?><div class="alert alert-light border"><?= e(t('match.none')) ?></div><?php else: ?>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th><?= e(t('draw.draw')) ?></th><th><?= e(t('draw.date')) ?></th><th><?= e(t('bond.type')) ?></th><th><?= e(t('bond.series')) ?></th><th><?= e(t('bond.number')) ?></th><th><?= e(t('draw.prize')) ?></th><th class="text-end"><?= e(t('draw.amount')) ?></th><th><?= e(t('match.detected')) ?></th><th><?= e(t('match.notice')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $m): ?>
      <tr><td><a href="<?= e(url('/results/' . $m['draw_id'])) ?>">#<?= (int) $m['draw_number'] ?></a></td><td><?= e(fmt_date($m['draw_date'])) ?></td><td><?= e($m['bond_name']) ?></td>
        <td><?= e($m['series'] ?: '—') ?></td><td class="mono"><span class="num-chip win"><?= e($m['number']) ?></span></td>
        <td><?= e($bn && $m['tier_name_bn'] ? $m['tier_name_bn'] : $m['tier_name']) ?></td><td class="text-end"><?= e(money($m['amount_snapshot'])) ?></td><td><?= e(fmt_dt($m['created_at'])) ?></td>
        <td><span class="badge text-bg-secondary"><?= e(t('match.n_' . $m['notification_status'])) ?></span>
          <?php if ($m['eligibility_status'] !== 'eligible'): ?><div class="small text-<?= $m['eligibility_status'] === 'ineligible' ? 'danger' : 'warning' ?>"><?= e(t('match.e_' . $m['eligibility_status'])) ?></div><?php endif; ?></td>
        <td><?php if ($m['source_url']): ?><a rel="noopener noreferrer" target="_blank" href="<?= e($m['source_url']) ?>" title="<?= e(t('draw.official_doc')) ?>"><i class="bi bi-box-arrow-up-right"></i></a><?php endif; ?></td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php include base_path('resources/views/partials/pager.php'); endif; ?>
  <p class="small text-muted"><?= e(t('match.claim_note')) ?></p>
</div>
