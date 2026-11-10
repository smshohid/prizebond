<h1 class="h3 mb-3">Roles &amp; Permissions</h1>
<div class="row g-3 mb-4">
  <?php foreach ($roles as $r): ?>
    <div class="col-lg-4"><div class="card h-100"><div class="card-body"><h2 class="h6"><span class="badge text-bg-primary"><?= e($r['role']) ?></span></h2><p class="small text-muted mb-0"><?= e($r['permissions'] ?: 'none') ?></p></div></div></div>
  <?php endforeach; ?>
</div>
<h2 class="h5">Administrators</h2>
<div class="table-responsive bg-white border rounded mb-4"><table class="table table-sm align-middle mb-0">
  <thead><tr><th>Name</th><th>Email</th><th>Last login</th><th colspan="2">Role / status</th></tr></thead>
  <tbody><?php foreach ($admins as $a): ?><tr>
    <td><?= e($a['full_name']) ?></td><td><?= e($a['email']) ?></td><td><?= e(fmt_dt($a['last_login_at'])) ?></td>
    <td colspan="2"><form method="post" class="d-flex gap-2" action="<?= e(url('/admin/roles/admins/' . $a['id'])) ?>"><?= csrf_field() ?>
      <select class="form-select form-select-sm w-auto" name="role"><?php foreach ($allRoles as $r): ?><option <?= $r['name'] === $a['role'] ? 'selected' : '' ?>><?= e($r['name']) ?></option><?php endforeach; ?></select>
      <select class="form-select form-select-sm w-auto" name="status"><option <?= $a['status'] === 'active' ? 'selected' : '' ?>>active</option><option <?= $a['status'] === 'disabled' ? 'selected' : '' ?>>disabled</option></select>
      <button class="btn btn-sm btn-outline-primary">Save</button></form></td>
  </tr><?php endforeach; ?></tbody></table></div>
<form method="post" action="<?= e(url('/admin/roles/admins')) ?>" class="card card-body" autocomplete="off">
  <?= csrf_field() ?><h2 class="h6">Add administrator</h2>
  <div class="row g-2">
    <div class="col-md-3"><input class="form-control" name="name" placeholder="Full name" required></div>
    <div class="col-md-3"><input class="form-control" type="email" name="email" placeholder="Email" required></div>
    <div class="col-md-3"><input class="form-control" type="password" name="password" placeholder="Password (12+ chars)" required autocomplete="new-password"></div>
    <div class="col-md-2"><select class="form-select" name="role"><?php foreach ($allRoles as $r): ?><option><?= e($r['name']) ?></option><?php endforeach; ?></select></div>
    <div class="col-md-1"><button class="btn btn-primary w-100">Add</button></div>
  </div>
</form>
