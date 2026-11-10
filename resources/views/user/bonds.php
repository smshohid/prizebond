<?php $err = $errors ?? []; $bn = \App\Core\Lang::locale() === 'bn'; $fe = fn($k) => isset($err[$k]) ? '<div class="invalid-feedback d-block">' . e(t($err[$k])) . '</div>' : ''; ?>
<div class="container">
  <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
    <h1 class="h3 mb-0"><?= e(t('nav.my_bonds')) ?></h1>
    <div class="d-flex gap-2 flex-wrap">
      <a class="btn btn-outline-primary btn-sm" href="<?= e(url('/bonds/import')) ?>"><i class="bi bi-upload"></i> <?= e(t('bond.bulk')) ?></a>
      <a class="btn btn-outline-secondary btn-sm" href="<?= e(url('/bonds/export')) ?>"><i class="bi bi-download"></i> <?= e(t('bond.export')) ?></a>
    </div>
  </div>
  <?php if (isset($err['_'])): ?><div class="alert alert-danger"><?= e(t($err['_'])) ?></div><?php endif; ?>
  <form method="post" action="<?= e(url('/bonds')) ?>" class="card card-body mb-4" novalidate>
    <?= csrf_field() ?>
    <h2 class="h6"><?= e(t('bond.add')) ?></h2>
    <div class="row g-2">
      <div class="col-12 col-md-3"><label class="form-label small" for="bt"><?= e(t('bond.type')) ?></label>
        <select id="bt" name="bond_type" class="form-select"><?php foreach ($types as $tp): ?><option value="<?= (int) $tp['id'] ?>"><?= e($bn && $tp['name_bn'] ? $tp['name_bn'] : $tp['name']) ?></option><?php endforeach; ?></select><?= $fe('bond_type') ?></div>
      <div class="col-4 col-md-2"><label class="form-label small" for="s"><?= e(t('bond.series')) ?></label><input id="s" class="form-control <?= isset($err['series']) ? 'is-invalid' : '' ?>" name="series" maxlength="8" value="<?= e(old('series')) ?>" data-series><?= $fe('series') ?></div>
      <div class="col-8 col-md-3"><label class="form-label small" for="n"><?= e(t('bond.number')) ?></label><input id="n" class="form-control mono <?= isset($err['number']) ? 'is-invalid' : '' ?>" name="number" inputmode="numeric" maxlength="7" required placeholder="0000000" value="<?= e(old('number')) ?>" data-digits><?= $fe('number') ?></div>
      <div class="col-8 col-md-3"><label class="form-label small" for="pd"><?= e(t('bond.purchase_date')) ?> <span class="text-muted">(<?= e(t('common.optional')) ?>)</span></label><input id="pd" type="date" class="form-control <?= isset($err['purchase_date']) ? 'is-invalid' : '' ?>" name="purchase_date" value="<?= e(old('purchase_date')) ?>"><?= $fe('purchase_date') ?></div>
      <div class="col-4 col-md-1 d-flex align-items-end"><button class="btn btn-primary w-100" aria-label="<?= e(t('bond.add')) ?>"><i class="bi bi-plus-lg"></i></button></div>
    </div>
    <div class="form-text"><?= e(t('bond.number_hint')) ?> <?= e(t('bond.purchase_hint')) ?></div>
  </form>

  <form method="get" class="row g-2 mb-3">
    <div class="col-8 col-md-4"><input class="form-control form-control-sm" name="q" placeholder="<?= e(t('common.search')) ?>" value="<?= e($_GET['q'] ?? '') ?>"></div>
    <div class="col-4 col-md-2"><select class="form-select form-select-sm" name="status"><option value=""><?= e(t('common.all')) ?></option><option value="active" <?= ($_GET['status'] ?? '') === 'active' ? 'selected' : '' ?>><?= e(t('bond.active')) ?></option><option value="inactive" <?= ($_GET['status'] ?? '') === 'inactive' ? 'selected' : '' ?>><?= e(t('bond.inactive')) ?></option></select></div>
    <div class="col-auto"><button class="btn btn-sm btn-outline-secondary"><?= e(t('common.filter')) ?></button></div>
  </form>

  <?php if (!$bonds): ?><div class="alert alert-light border"><?= e(t('bond.empty')) ?></div><?php else: ?>
  <div class="table-responsive"><table class="table align-middle">
    <thead><tr><th><?= e(t('bond.type')) ?></th><th><?= e(t('bond.series')) ?></th><th><?= e(t('bond.number')) ?></th><th><?= e(t('bond.purchase_date')) ?></th><th><?= e(t('common.status')) ?></th><th></th></tr></thead>
    <tbody><?php foreach ($bonds as $b): ?>
      <tr class="<?= $b['status'] === 'inactive' ? 'text-muted' : '' ?>">
        <td><?= e($b['type_name']) ?></td><td><?= e($b['series'] ?: '—') ?></td><td class="mono"><?= e($b['number']) ?><?= $b['wins'] > 0 ? ' <span class="badge verified-badge"><i class="bi bi-trophy"></i> ' . (int) $b['wins'] . '</span>' : '' ?></td>
        <td><?= e($b['purchase_date'] ? fmt_date($b['purchase_date']) : '—') ?></td>
        <td><span class="badge text-bg-<?= $b['status'] === 'active' ? 'success' : 'secondary' ?>"><?= e(t('bond.' . $b['status'])) ?></span></td>
        <td class="text-end text-nowrap">
          <a class="btn btn-sm btn-outline-primary" href="<?= e(url('/bonds/' . $b['id'] . '/edit')) ?>" aria-label="<?= e(t('bond.edit')) ?>"><i class="bi bi-pencil"></i></a>
          <form class="d-inline" method="post" action="<?= e(url('/bonds/' . $b['id'] . '/toggle')) ?>" data-noload><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary" aria-label="<?= e(t('bond.toggle')) ?>"><i class="bi bi-toggle-<?= $b['status'] === 'active' ? 'on' : 'off' ?>"></i></button></form>
          <form class="d-inline" method="post" action="<?= e(url('/bonds/' . $b['id'] . '/delete')) ?>" data-confirm="<?= e(t('bond.confirm_delete')) ?>" data-noload><?= csrf_field() ?><button class="btn btn-sm btn-outline-danger" aria-label="<?= e(t('common.delete')) ?>"><i class="bi bi-trash"></i></button></form>
        </td></tr>
    <?php endforeach; ?></tbody></table></div>
  <?php include base_path('resources/views/partials/pager.php'); endif; ?>
</div>
