<?php
$badge = function (string $v): string {
    $map = ['active' => 'success', 'sent' => 'success', 'success' => 'success', 'eligible' => 'success', 'detected' => 'success', 'published' => 'success', 'primary' => 'primary',
        'pending' => 'warning', 'queued' => 'warning', 'sending' => 'warning', 'uncertain' => 'warning', 'unknown' => 'secondary', 'secondary' => 'secondary', 'reference' => 'secondary',
        'failed' => 'danger', 'review' => 'danger', 'suspended' => 'danger', 'void' => 'danger', 'ineligible' => 'danger', 'disabled' => 'danger'];
    return '<span class="badge text-bg-' . ($map[$v] ?? 'secondary') . '">' . e($v) . '</span>';
};
?>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-3">
  <h1 class="h3 mb-0"><?= e($title) ?></h1>
  <?php if (!empty($searchable) || !empty($statusFilter)): ?>
  <form method="get" class="d-flex gap-2">
    <?php if (!empty($statusFilter)): ?><select name="status" class="form-select form-select-sm"><option value="">All</option><?php foreach ($statusFilter as $s): ?><option <?= ($_GET['status'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option><?php endforeach; ?></select><?php endif; ?>
    <input class="form-control form-control-sm" name="q" placeholder="Search" value="<?= e($q ?? '') ?>"><button class="btn btn-sm btn-outline-secondary">Go</button>
  </form>
  <?php endif; ?>
</div>
<?php if (!empty($note)): ?><div class="alert alert-info small"><?= e($note) ?></div><?php endif; ?>
<?php if (!$rows): ?><div class="alert alert-light border">No records.</div><?php else: ?>
<div class="table-responsive bg-white border rounded"><table class="table table-sm table-hover align-middle mb-0">
  <thead><tr><?php foreach ($cols as $c): ?><th><?= e($c[1]) ?></th><?php endforeach; ?></tr></thead>
  <tbody><?php foreach ($rows as $row): ?><tr>
    <?php foreach ($cols as $c): $v = $row[$c[0]] ?? null; $kind = $c[2] ?? 'text'; ?>
      <td class="<?= $kind === 'text' && strlen((string) $v) > 60 ? 'small' : '' ?>"><?php
        switch ($kind) {
            case 'badge': echo $badge((string) $v); break;
            case 'dt': echo e(fmt_dt($v)); break;
            case 'money': echo e(money($v)); break;
            case 'bool': echo $v ? '<i class="bi bi-check-circle text-success"></i>' : '<i class="bi bi-x-circle text-secondary"></i>'; break;
            case 'user_action':
                if (\App\Core\AdminAuth::can('manage_users')) {
                    $to = $row['status'] === 'suspended' ? 'active' : 'suspended';
                    echo '<form method="post" class="d-inline" action="' . e(url('/admin/users/' . $row['id'] . '/status')) . '" data-confirm="' . e(($to === 'suspended' ? 'Suspend' : 'Reactivate') . ' this account?') . '">' . csrf_field() . '<input type="hidden" name="status" value="' . e($to) . '"><button class="btn btn-sm btn-outline-' . ($to === 'suspended' ? 'danger' : 'success') . '">' . ($to === 'suspended' ? 'Suspend' : 'Reactivate') . '</button></form>';
                }
                break;
            case 'queue_action':
                if (\App\Core\AdminAuth::can('manage_email_queue') && in_array($row['status'], ['failed', 'review', 'pending'], true)) {
                    if ($row['status'] !== 'pending') {
                        echo '<form method="post" class="d-inline" action="' . e(url('/admin/email-queue/' . $row['id'])) . '" data-confirm="Re-send this email? Make sure it was not delivered already.">' . csrf_field() . '<input type="hidden" name="do" value="retry"><button class="btn btn-sm btn-outline-primary">Retry</button></form> ';
                    }
                    echo '<form method="post" class="d-inline" action="' . e(url('/admin/email-queue/' . $row['id'])) . '" data-confirm="Cancel this email?">' . csrf_field() . '<input type="hidden" name="do" value="cancel"><button class="btn btn-sm btn-outline-secondary">Cancel</button></form>';
                }
                break;
            default: echo e($v);
        } ?></td>
    <?php endforeach; ?></tr><?php endforeach; ?></tbody></table></div>
<?php include base_path('resources/views/partials/pager.php'); endif; ?>
