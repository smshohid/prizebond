<?php
$statuses = ['discovered', 'fetched', 'parsed', 'staged', 'review_required', 'verified', 'disputed', 'rejected', 'published', 'failed'];
$cls = ['published' => 'success', 'verified' => 'success', 'staged' => 'info', 'review_required' => 'warning', 'disputed' => 'danger', 'failed' => 'danger', 'rejected' => 'secondary'];
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0">Result Imports &amp; Verification</h1>
  <?php if (\App\Core\AdminAuth::can('import_results')): ?>
  <form method="post" action="<?= e(url('/admin/imports/fetch')) ?>" data-noload><?= csrf_field() ?><button class="btn btn-primary btn-sm"><i class="bi bi-arrow-repeat"></i> Check Bangladesh Bank now</button></form>
  <?php endif; ?>
</div>
<form method="get" class="mb-3 d-flex gap-2"><select name="status" class="form-select form-select-sm w-auto"><option value="">All statuses</option><?php foreach ($statuses as $s): ?><option <?= $status === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select><button class="btn btn-sm btn-outline-secondary">Filter</button></form>
<div class="table-responsive bg-white border rounded mb-4"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>ID</th><th>Draw</th><th>Date</th><th>Origin</th><th>Status</th><th>Source</th><th>Updated</th><th></th></tr></thead>
  <tbody><?php if (!$rows): ?><tr><td colspan="8" class="text-muted">No imports yet. Run a source check or upload a document below.</td></tr><?php endif; ?>
  <?php foreach ($rows as $i): ?>
    <tr><td><?= (int) $i['id'] ?></td><td><?= e($i['draw_number'] ?? '—') ?></td><td><?= e($i['draw_date'] ? fmt_date($i['draw_date']) : '—') ?></td><td><?= e($i['origin']) ?></td>
      <td><span class="badge text-bg-<?= e($cls[$i['status']] ?? 'secondary') ?>"><?= e($i['status']) ?></span></td><td><?= e($i['source_name'] ?? 'manual') ?></td><td><?= e(fmt_dt($i['updated_at'])) ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/imports/' . $i['id'])) ?>">Open</a></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php include base_path('resources/views/partials/pager.php'); ?>

<?php if (\App\Core\AdminAuth::can('import_results')): ?>
<div class="row g-3">
  <div class="col-lg-5"><form method="post" action="<?= e(url('/admin/imports/upload')) ?>" enctype="multipart/form-data" class="card card-body">
    <?= csrf_field() ?><h2 class="h6">Upload an official result PDF</h2>
    <select name="bond_type" class="form-select mb-2"><?php foreach ($bondTypes as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['name']) ?></option><?php endforeach; ?></select>
    <input class="form-control mb-2" name="draw_number" type="number" min="1" placeholder="Draw number (optional - checked against the document)">
    <input class="form-control mb-2" name="pdf" type="file" accept="application/pdf,.pdf" required>
    <button class="btn btn-outline-primary">Upload &amp; parse</button>
    <div class="form-text">Max 8 MB. The original file is preserved with its SHA-256 checksum.</div>
  </form></div>
  <div class="col-lg-7"><form method="post" action="<?= e(url('/admin/imports/manual')) ?>" class="card card-body">
    <?= csrf_field() ?><h2 class="h6">Manual entry (fallback)</h2>
    <div class="row g-2 mb-2">
      <div class="col-md-4"><select name="bond_type" class="form-select"><?php foreach ($bondTypes as $b): ?><option value="<?= (int) $b['id'] ?>"><?= e($b['code']) ?></option><?php endforeach; ?></select></div>
      <div class="col-md-4"><input class="form-control" name="draw_number" type="number" min="1" placeholder="Draw no." required></div>
      <div class="col-md-4"><input class="form-control" name="draw_date" type="date" required></div>
    </div>
    <?php foreach (\App\Core\DB::all('SELECT code, name, expected_count FROM prize_tiers WHERE is_active = 1 ORDER BY rank_order') as $t): ?>
      <label class="form-label small mb-0"><?= e($t['name']) ?> (<?= (int) $t['expected_count'] ?> number<?= $t['expected_count'] > 1 ? 's' : '' ?>)</label>
      <textarea class="form-control numbers mb-2" name="tier_<?= e($t['code']) ?>" rows="2" placeholder="7-digit numbers separated by spaces/commas"></textarea>
    <?php endforeach; ?>
    <button class="btn btn-outline-primary">Stage entry for review</button>
  </form></div>
</div>
<?php endif; ?>
