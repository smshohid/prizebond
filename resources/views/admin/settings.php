<h1 class="h3 mb-3">System Settings</h1>
<div class="alert alert-info small">Secrets (database, SMTP credentials, APP_KEY) are never stored here; they live in the server's <code>.env</code> file.</div>
<form method="post" action="<?= e(url('/admin/settings')) ?>" class="card card-body">
  <?= csrf_field() ?>
  <?php foreach ($rows as $r): ?>
    <div class="row g-2 mb-2 align-items-center">
      <div class="col-md-4"><label class="form-label mb-0 mono small" for="s_<?= e($r['setting_key']) ?>"><?= e($r['setting_key']) ?></label><div class="form-text mt-0"><?= e($r['description']) ?></div></div>
      <div class="col-md-8"><input class="form-control form-control-sm" id="s_<?= e($r['setting_key']) ?>" name="s[<?= e($r['setting_key']) ?>]" value="<?= e($r['setting_value']) ?>"></div>
    </div>
  <?php endforeach; ?>
  <div><button class="btn btn-primary">Save settings</button></div>
</form>
