<?php
use App\Core\AdminAuth as AA;
$editable = !in_array($imp['status'], ['published', 'rejected'], true);
?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url('/admin/imports')) ?>">Imports</a></li><li class="breadcrumb-item active">#<?= (int) $imp['id'] ?></li></ol></nav>
<div class="d-flex flex-wrap justify-content-between gap-2 mb-3">
  <h1 class="h3 mb-0">Import #<?= (int) $imp['id'] ?> — draw <?= e($imp['draw_number'] ?? '?') ?> <span class="badge text-bg-primary fs-6"><?= e($imp['status']) ?></span></h1>
  <?php if ($draw): ?><a class="btn btn-sm btn-success" href="<?= e(url('/admin/draws/' . $draw['id'])) ?>">View published draw</a><?php endif; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-lg-6"><div class="card h-100"><div class="card-body small">
    <h2 class="h6">Source record</h2>
    <dl class="row mb-0">
      <dt class="col-4">Origin</dt><dd class="col-8"><?= e($imp['origin']) ?></dd>
      <dt class="col-4">Source URL</dt><dd class="col-8 text-break"><?= $imp['source_url'] ? '<a rel="noopener noreferrer" target="_blank" href="' . e($imp['source_url']) . '">' . e($imp['source_url']) . '</a>' : '—' ?></dd>
      <dt class="col-4">Retrieved</dt><dd class="col-8"><?= e(fmt_dt($imp['retrieved_at'])) ?></dd>
      <dt class="col-4">SHA-256</dt><dd class="col-8 text-break mono"><?= e($imp['file_checksum'] ?? '—') ?></dd>
      <dt class="col-4">Format / parser</dt><dd class="col-8"><?= e(($imp['file_format'] ?? '—') . ' / ' . ($imp['parser_version'] ?? '—')) ?></dd>
      <dt class="col-4">Listing date</dt><dd class="col-8"><?= e($imp['draw_date'] ? fmt_date($imp['draw_date']) : '—') ?></dd>
    </dl>
    <?php if ($imp['error_message']): ?><div class="alert alert-danger mt-2 mb-0"><?= e($imp['error_message']) ?></div><?php endif; ?>
  </div></div></div>
  <div class="col-lg-6"><div class="card h-100"><div class="card-body small">
    <h2 class="h6">Validation</h2>
    <?php if (!$report): ?><p class="text-muted">Not validated yet.</p><?php else: ?>
      <?php if ($report['valid']): ?><div class="alert alert-success py-1">Passed (<?= (int) ($report['stats']['total_numbers'] ?? 0) ?> numbers).</div><?php else: ?><div class="alert alert-danger py-1">Failed — correct the staged data or reject.</div><?php endif; ?>
      <?php foreach ($report['errors'] as $m): ?><div class="text-danger"><i class="bi bi-x-circle"></i> <?= e($m) ?></div><?php endforeach; ?>
      <?php foreach ($report['warnings'] as $m): ?><div class="text-warning-emphasis"><i class="bi bi-exclamation-triangle"></i> <?= e($m) ?></div><?php endforeach; ?>
    <?php endif; ?>
    <h2 class="h6 mt-3">IRD cross-check (secondary source)</h2>
    <?php if (!$secondary): ?><p class="text-muted mb-1">Not run yet.</p><?php else: ?>
      <p class="mb-1">Status: <strong><?= e($secondary['status']) ?></strong> — <?= (int) $secondary['checked'] ?> numbers checked<?= isset($secondary['at']) ? ', ' . e(fmt_dt($secondary['at'])) : '' ?></p>
      <?php foreach (array_slice($secondary['mismatches'] ?? [], 0, 10) as $mm): ?><div class="text-danger mono"><?= e($mm['number']) ?>: <?= e($mm['issue']) ?></div><?php endforeach; ?>
      <?php if (!empty($secondary['error'])): ?><div class="text-warning-emphasis"><?= e($secondary['error']) ?></div><?php endif; ?>
    <?php endif; ?>
    <?php if (AA::can('verify_results') && $editable && $imp['status'] !== 'discovered'): ?>
    <form method="post" action="<?= e(url('/admin/imports/' . $imp['id'] . '/reverify')) ?>" class="mt-1" data-noload><?= csrf_field() ?><button class="btn btn-sm btn-outline-secondary">Run IRD cross-check</button></form>
    <?php endif; ?>
  </div></div></div>
</div>

<?php if ($staged): ?>
<form method="post" action="<?= e(url('/admin/imports/' . $imp['id'] . '/edit')) ?>" class="card mb-3">
  <?= csrf_field() ?>
  <div class="card-header">Staged data <?= $editable && AA::can('review_results') ? '(editable)' : '(read-only)' ?></div>
  <div class="card-body">
    <div class="row g-2 mb-3">
      <div class="col-md-3"><label class="form-label small">Draw number</label><input class="form-control" name="draw_number" value="<?= e($staged['draw_number'] ?? '') ?>" <?= $editable ? '' : 'readonly' ?>></div>
      <div class="col-md-3"><label class="form-label small">Draw date (Bangladesh local)</label><input class="form-control" type="date" name="draw_date" value="<?= e($staged['draw_date'] ?? '') ?>" <?= $editable ? '' : 'readonly' ?>></div>
      <div class="col-md-3"><label class="form-label small">Series in draw</label><input class="form-control" name="series_count" value="<?= e($staged['series_count'] ?? '') ?>" <?= $editable ? '' : 'readonly' ?>></div>
    </div>
    <?php foreach ($tiers as $t): $st = $staged['tiers'][$t['code']] ?? ['numbers' => [], 'amount' => $t['amount']]; ?>
      <div class="mb-3">
        <label class="form-label fw-semibold"><?= e($t['name']) ?> — <?= e(money($st['amount'])) ?>
          <span class="text-muted fw-normal">(<?= count($st['numbers']) ?> parsed<?= ($st['expected_count'] ?? $t['expected_count']) !== null ? ', expected ' . (int) ($st['expected_count'] ?? $t['expected_count']) : '' ?>)</span></label>
        <textarea class="form-control numbers" rows="<?= count($st['numbers']) > 10 ? 5 : 2 ?>" name="tier_<?= e($t['code']) ?>" <?= $editable ? '' : 'readonly' ?>><?= e(implode(' ', $st['numbers'])) ?></textarea>
      </div>
    <?php endforeach; ?>
    <?php if ($editable && AA::can('review_results')): ?><button class="btn btn-outline-primary">Save corrections &amp; re-validate</button><?php endif; ?>
  </div>
</form>
<?php else: ?><div class="alert alert-info">No staged data yet.<?php if ($imp['file_path'] && AA::can('review_results') && $editable): ?>
  <form method="post" class="d-inline" action="<?= e(url('/admin/imports/' . $imp['id'] . '/reprocess')) ?>"><?= csrf_field() ?><button class="btn btn-sm btn-primary ms-2">Parse now</button></form><?php endif; ?></div><?php endif; ?>

<?php if ($editable): ?>
<div class="row g-3 mb-4">
  <?php if (AA::can('review_results') && $imp['file_path']): ?>
  <div class="col-md-3"><form method="post" action="<?= e(url('/admin/imports/' . $imp['id'] . '/reprocess')) ?>" data-confirm="Discard staged edits and re-parse the original document?"><?= csrf_field() ?><button class="btn btn-outline-secondary w-100">Re-parse original</button></form></div>
  <?php endif; ?>
  <?php if (AA::can('verify_results') && in_array($imp['status'], ['staged', 'review_required', 'disputed'], true)): ?>
  <div class="col-md-5"><form method="post" action="<?= e(url('/admin/imports/' . $imp['id'] . '/verify')) ?>" class="card card-body">
    <?= csrf_field() ?>
    <?php if ($imp['status'] === 'disputed'): ?><label class="form-label small text-danger">Sources disagree. Override reason (required, audited):</label><input class="form-control mb-2" name="override_reason" required><?php endif; ?>
    <button class="btn btn-success"><i class="bi bi-check2-circle"></i> Approve / verify</button></form></div>
  <?php endif; ?>
  <?php if (AA::can('verify_results')): ?>
  <div class="col-md-4"><form method="post" action="<?= e(url('/admin/imports/' . $imp['id'] . '/reject')) ?>" class="card card-body" data-confirm="Reject this import?">
    <?= csrf_field() ?><input class="form-control mb-2" name="reason" placeholder="Reason" required><button class="btn btn-outline-danger">Reject</button></form></div>
  <?php endif; ?>
  <?php if (AA::can('publish_results') && $imp['status'] === 'verified'): ?>
  <div class="col-12"><form method="post" action="<?= e(url('/admin/imports/' . $imp['id'] . '/publish')) ?>" data-confirm="Publish draw <?= (int) ($staged['draw_number'] ?? 0) ?>? Published results are public and trigger matching and email alerts.">
    <?= csrf_field() ?><button class="btn btn-primary btn-lg"><i class="bi bi-megaphone"></i> Publish draw <?= (int) ($staged['draw_number'] ?? 0) ?></button></form></div>
  <?php endif; ?>
</div>
<?php endif; ?>

<div class="card"><div class="card-header">Import log</div><ul class="list-group list-group-flush small">
  <?php foreach ($logs as $l): ?><li class="list-group-item"><span class="badge text-bg-<?= $l['level'] === 'error' ? 'danger' : ($l['level'] === 'warning' ? 'warning' : 'secondary') ?>"><?= e($l['level']) ?></span> <?= e(fmt_dt($l['created_at'])) ?> — <?= e($l['message']) ?></li><?php endforeach; ?>
  <?php if (!$logs): ?><li class="list-group-item text-muted">No log entries.</li><?php endif; ?>
</ul></div>
