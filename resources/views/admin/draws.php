<h1 class="h3 mb-3">Prize Draws</h1>
<?php if (!$rows): ?><div class="alert alert-light border">No draws yet. Publish a verified import to create one.</div><?php else: ?>
<div class="table-responsive bg-white border rounded"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><th>Draw</th><th>Date</th><th>Type</th><th>Series</th><th>Verification</th><th>Publication</th><th>Lifecycle</th><th>Ver.</th><th>Matches</th><th></th></tr></thead>
  <tbody><?php foreach ($rows as $d): ?>
    <tr><td><strong>#<?= (int) $d['draw_number'] ?></strong></td><td><?= e(fmt_date($d['draw_date'])) ?></td><td><?= e($d['bond_name']) ?></td><td><?= e($d['series_count'] ?? '—') ?></td>
      <td><span class="badge text-bg-<?= $d['verification_status'] === 'verified' ? 'success' : 'warning' ?>"><?= e($d['verification_status']) ?></span></td>
      <td><span class="badge text-bg-<?= $d['publication_status'] === 'published' ? 'success' : 'secondary' ?>"><?= e($d['publication_status']) ?></span></td>
      <td><?= e($d['lifecycle']) ?></td><td>v<?= (int) $d['version'] ?></td><td><?= (int) $d['matches'] ?></td>
      <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= e(url('/admin/draws/' . $d['id'])) ?>">Open</a></td></tr>
  <?php endforeach; ?></tbody></table></div>
<?php include base_path('resources/views/partials/pager.php'); endif; ?>
