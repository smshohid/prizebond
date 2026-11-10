<?php $bn = \App\Core\Lang::locale() === 'bn'; ?>
<div class="container">
  <h1 class="h3 mb-3"><?= e(t('bond.bulk')) ?></h1>
  <div class="row g-4">
    <div class="col-lg-5">
      <form method="post" action="<?= e(url('/bonds/import')) ?>" enctype="multipart/form-data" class="card card-body">
        <?= csrf_field() ?>
        <div class="mb-3"><label class="form-label" for="bt"><?= e(t('bond.type')) ?></label><select id="bt" name="bond_type" class="form-select"><?php foreach ($types as $tp): ?><option value="<?= (int) $tp['id'] ?>"><?= e($bn && $tp['name_bn'] ? $tp['name_bn'] : $tp['name']) ?></option><?php endforeach; ?></select></div>
        <div class="mb-3"><label class="form-label" for="csv"><?= e(t('bond.csv_file')) ?></label><input id="csv" type="file" name="csv" class="form-control" accept=".csv,.txt,text/csv,text/plain"></div>
        <div class="mb-3"><label class="form-label" for="bt2"><?= e(t('bond.paste')) ?></label><textarea id="bt2" name="bulk_text" rows="6" class="form-control numbers" placeholder="series,number,purchase_date&#10;KK,0046900,2025-01-15&#10;,0123456"></textarea></div>
        <button class="btn btn-primary"><?= e(t('bond.import_btn')) ?></button>
        <a class="btn btn-link" href="<?= e(url('/bonds/template')) ?>"><i class="bi bi-file-earmark-arrow-down"></i> <?= e(t('bond.template')) ?></a>
      </form>
      <p class="small text-muted mt-2"><?= e(t('bond.import_help')) ?></p>
    </div>
    <div class="col-lg-7">
      <?php if ($report): ?>
        <?php if (!empty($report['fatal'])): ?><div class="alert alert-danger"><?= e(t($report['fatal'])) ?></div><?php else: ?>
        <div class="alert alert-<?= $report['errors'] ? 'warning' : 'success' ?>"><?= e(t('bond.import_summary', ['ok' => $report['inserted'], 'dup' => $report['duplicates'], 'err' => $report['errors']])) ?></div>
        <div class="table-responsive"><table class="table table-sm">
          <thead><tr><th>#</th><th><?= e(t('bond.series')) ?></th><th><?= e(t('bond.number')) ?></th><th><?= e(t('bond.purchase_date')) ?></th><th><?= e(t('common.status')) ?></th></tr></thead>
          <tbody><?php foreach ($report['rows'] as $row): ?>
            <tr class="<?= $row['status'] === 'ok' ? '' : ($row['status'] === 'duplicate' ? 'table-warning' : 'table-danger') ?>">
              <td><?= (int) $row['line'] ?></td><td><?= e($row['series']) ?></td><td class="mono"><?= e($row['number']) ?></td><td><?= e($row['purchase_date']) ?></td><td><?= e($row['message'] ?: $row['status']) ?></td></tr>
          <?php endforeach; ?></tbody></table></div>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  </div>
</div>
