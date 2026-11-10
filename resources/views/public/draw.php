<?php $bn = \App\Core\Lang::locale() === 'bn'; ?>
<div class="container">
  <nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url('/results')) ?>"><?= e(t('nav.results')) ?></a></li><li class="breadcrumb-item active">#<?= (int) $draw['draw_number'] ?></li></ol></nav>
  <div class="d-flex flex-wrap justify-content-between align-items-start gap-2">
    <div>
      <h1 class="h3"><?= e(t('draw.title', ['n' => $draw['draw_number']])) ?></h1>
      <p class="text-muted mb-1"><?= e(fmt_date($draw['draw_date'], 'l, d F Y')) ?> · <?= e($draw['bond_name']) ?><?= $draw['series_count'] ? ' · ' . e(t('draw.series_count', ['n' => $draw['series_count']])) : '' ?></p>
    </div>
    <span class="badge verified-badge fs-6"><i class="bi bi-patch-check"></i> <?= e(t('draw.verified')) ?></span>
  </div>
  <div class="alert alert-info small mt-2"><i class="bi bi-info-circle"></i> <?= e(t('check.series_note')) ?></div>
  <?php foreach ($tiers as $tier): ?>
    <section class="mb-4">
      <h2 class="h5 tier-title"><?= e($bn && $tier['name_bn'] ? $tier['name_bn'] : $tier['name']) ?> — <?= e(money($tier['amount'])) ?> <span class="text-muted small">(<?= count($tier['numbers']) ?>)</span></h2>
      <div><?php foreach ($tier['numbers'] as $n): ?><span class="num-chip"><?= e($n) ?></span><?php endforeach; ?></div>
    </section>
  <?php endforeach; ?>
  <p>
    <?php if ($draw['source_url']): ?><a class="btn btn-outline-primary btn-sm" rel="noopener noreferrer" target="_blank" href="<?= e($draw['source_url']) ?>"><i class="bi bi-file-earmark-pdf"></i> <?= e(t('draw.official_doc')) ?></a><?php endif; ?>
    <a class="btn btn-warning btn-sm" href="<?= e(url('/check')) ?>"><?= e(t('nav.check')) ?></a>
  </p>
  <p class="small text-muted"><?= e(t('draw.published_on', ['d' => fmt_dt($draw['published_at'])])) ?><?= (int) $draw['version'] > 1 ? ' · ' . e(t('draw.corrected', ['v' => $draw['version']])) : '' ?></p>
  <div class="alert alert-secondary small"><?= e(t('footer.disclaimer')) ?></div>
</div>
