<?php $bn = \App\Core\Lang::locale() === 'bn'; ?>
<div class="container narrow">
  <h1 class="h3"><?= e(t('nav.check')) ?></h1>
  <p class="text-muted"><?= e(t('check.intro')) ?></p>
  <form method="get" action="<?= e(url('/check')) ?>" class="card card-body mb-3">
    <div class="row g-3">
      <?php if (count($types) > 1): ?>
      <div class="col-12"><label class="form-label" for="bt"><?= e(t('bond.type')) ?></label>
        <select id="bt" class="form-select" name="bond_type"><?php foreach ($types as $tp): ?><option value="<?= (int) $tp['id'] ?>" <?= (int) ($_GET['bond_type'] ?? 0) === (int) $tp['id'] ? 'selected' : '' ?>><?= e($bn && $tp['name_bn'] ? $tp['name_bn'] : $tp['name']) ?></option><?php endforeach; ?></select></div>
      <?php elseif ($types): ?><input type="hidden" name="bond_type" value="<?= (int) $types[0]['id'] ?>"><?php endif; ?>
      <div class="col-5 col-md-3"><label class="form-label" for="series"><?= e(t('bond.series')) ?> <span class="text-muted small">(<?= e(t('common.optional')) ?>)</span></label>
        <input id="series" class="form-control" name="series" maxlength="8" value="<?= e($_GET['series'] ?? '') ?>" data-series></div>
      <div class="col-7 col-md-5"><label class="form-label" for="number"><?= e(t('bond.number')) ?></label>
        <input id="number" class="form-control mono" name="number" maxlength="7" inputmode="numeric" required value="<?= e($_GET['number'] ?? '') ?>" placeholder="0000000" data-digits aria-describedby="nh"></div>
      <div class="col-12 col-md-4 d-flex align-items-end"><button class="btn btn-primary w-100"><i class="bi bi-search"></i> <?= e(t('home.check_btn')) ?></button></div>
    </div>
    <div id="nh" class="form-text mt-2"><?= e(t('bond.number_hint')) ?></div>
  </form>

  <?php if ($error): ?><div class="alert alert-danger"><?= e(t($error)) ?></div><?php endif; ?>

  <?php if ($result): ?>
    <div class="card mb-3">
      <div class="card-body">
        <h2 class="h5"><?= e(t('check.result_for')) ?> <span class="num-chip"><?= e(trim($result['series'] . ' ' . $result['number'])) ?></span></h2>
        <?php if ($result['wins']): ?>
          <div class="alert alert-success"><i class="bi bi-trophy-fill"></i> <?= e(t('check.won', ['n' => count($result['wins'])])) ?></div>
          <div class="table-responsive"><table class="table table-sm align-middle">
            <thead><tr><th><?= e(t('draw.draw')) ?></th><th><?= e(t('draw.date')) ?></th><th><?= e(t('draw.prize')) ?></th><th class="text-end"><?= e(t('draw.amount')) ?></th></tr></thead>
            <tbody><?php foreach ($result['wins'] as $w): ?>
              <tr><td><a href="<?= e(url('/results/' . $w['draw_id'])) ?>">#<?= (int) $w['draw_number'] ?></a></td><td><?= e(fmt_date($w['draw_date'])) ?></td>
                  <td><?= e($bn && $w['tier_name_bn'] ? $w['tier_name_bn'] : $w['tier_name']) ?></td><td class="text-end"><?= e(money($w['amount'])) ?></td></tr>
            <?php endforeach; ?></tbody></table></div>
          <p class="small text-muted"><?= e(t('check.verify_official')) ?></p>
        <?php else: ?>
          <div class="alert alert-light border"><?= e(t('check.none', ['n' => $result['checked']])) ?></div>
        <?php endif; ?>
        <p class="small text-muted mb-0"><i class="bi bi-info-circle"></i> <?= e(t('check.series_note')) ?></p>
        <?php if (!\App\Core\Auth::user()): ?><p class="mt-3 mb-0"><a class="btn btn-warning btn-sm" href="<?= e(url('/register')) ?>"><?= e(t('check.cta')) ?></a></p><?php endif; ?>
      </div>
    </div>
  <?php endif; ?>
  <div class="alert alert-secondary small"><?= e(t('footer.disclaimer')) ?></div>
</div>
