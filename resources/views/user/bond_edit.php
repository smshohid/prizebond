<?php $err = $errors ?? []; $fe = fn($k) => isset($err[$k]) ? '<div class="invalid-feedback d-block">' . e(t($err[$k])) . '</div>' : ''; ?>
<div class="container narrow">
  <h1 class="h3 mb-3"><?= e(t('bond.edit')) ?></h1>
  <form method="post" action="<?= e(url('/bonds/' . $bond['id'] . '/edit')) ?>" class="card card-body" novalidate>
    <?= csrf_field() ?>
    <p class="text-muted"><?= e($bond['type_name']) ?></p>
    <div class="mb-3"><label class="form-label" for="s"><?= e(t('bond.series')) ?></label><input id="s" class="form-control" name="series" maxlength="8" value="<?= e(old('series', $bond['series'])) ?>" data-series><?= $fe('series') ?></div>
    <div class="mb-3"><label class="form-label" for="n"><?= e(t('bond.number')) ?></label><input id="n" class="form-control mono" name="number" maxlength="7" inputmode="numeric" required value="<?= e(old('number', $bond['number'])) ?>" data-digits><?= $fe('number') ?></div>
    <div class="mb-3"><label class="form-label" for="pd"><?= e(t('bond.purchase_date')) ?></label><input id="pd" type="date" class="form-control" name="purchase_date" value="<?= e(old('purchase_date', $bond['purchase_date'] ?? '')) ?>"><?= $fe('purchase_date') ?></div>
    <div class="d-flex gap-2"><button class="btn btn-primary"><?= e(t('common.save')) ?></button><a class="btn btn-outline-secondary" href="<?= e(url('/bonds')) ?>"><?= e(t('common.cancel')) ?></a></div>
  </form>
</div>
