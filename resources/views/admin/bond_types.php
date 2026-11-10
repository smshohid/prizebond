<h1 class="h3 mb-3">Bond Types &amp; Prize Tiers</h1>
<div class="alert alert-info small">Only bond types with a verified official draw format can be enabled. Prize amounts are configuration, not code; changes are audited and never alter already-published draws (amounts are snapshotted per winning number).</div>
<div class="table-responsive bg-white border rounded mb-4"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Code</th><th>Name</th><th>Denomination</th><th>Digits</th><th>Series affect matching</th><th>Official format verified</th><th>Enabled</th><th></th></tr></thead>
  <tbody><?php foreach ($types as $t): ?><tr>
    <td><?= e($t['code']) ?></td><td><?= e($t['name']) ?></td><td><?= e(money($t['denomination'])) ?></td><td><?= (int) $t['number_length'] ?></td>
    <td><?= $t['series_affects_matching'] ? 'yes' : 'no' ?></td><td><?= $t['source_verified'] ? '<span class="badge text-bg-success">yes</span>' : '<span class="badge text-bg-secondary">no</span>' ?></td>
    <td colspan="2"><form method="post" class="d-flex gap-2 align-items-center" action="<?= e(url('/admin/bond-types/' . $t['id'])) ?>"><?= csrf_field() ?>
      <div class="form-check form-switch mb-0"><input class="form-check-input" type="checkbox" name="is_enabled" value="1" id="en<?= (int) $t['id'] ?>" <?= $t['is_enabled'] ? 'checked' : '' ?> <?= $t['source_verified'] ? '' : 'disabled' ?>><label class="form-check-label small" for="en<?= (int) $t['id'] ?>">Enabled</label></div>
      <button class="btn btn-sm btn-outline-primary" <?= $t['source_verified'] ? '' : 'disabled' ?>>Save</button></form></td>
  </tr><?php endforeach; ?></tbody></table></div>
<h2 class="h5">Prize tiers</h2>
<div class="table-responsive bg-white border rounded"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Type</th><th>Rank</th><th>Name</th><th>Numbers per draw</th><th colspan="2">Amount (Tk.)</th></tr></thead>
  <tbody><?php foreach ($tiers as $t): ?><tr>
    <td><?= e($t['type_code']) ?></td><td><?= (int) $t['rank_order'] ?></td><td><?= e($t['name']) ?></td><td><?= e($t['expected_count'] ?? '—') ?></td>
    <td colspan="2"><form method="post" class="d-flex gap-2 flex-wrap" action="<?= e(url('/admin/prize-tiers/' . $t['id'])) ?>"><?= csrf_field() ?>
      <input class="form-control form-control-sm w-auto" name="amount" value="<?= e(rtrim(rtrim((string) $t['amount'], '0'), '.')) ?>" aria-label="Amount">
      <input class="form-control form-control-sm w-auto" name="reason" placeholder="Reason (if used by published draws)">
      <div class="form-check pt-1"><input class="form-check-input" type="checkbox" name="is_active" value="1" id="ta<?= (int) $t['id'] ?>" <?= $t['is_active'] ? 'checked' : '' ?>><label class="form-check-label small" for="ta<?= (int) $t['id'] ?>">Active</label></div>
      <button class="btn btn-sm btn-outline-primary">Save</button></form></td>
  </tr><?php endforeach; ?></tbody></table></div>
