<div class="row justify-content-center"><div class="col-md-5 col-lg-4">
  <h1 class="h4 mb-3 text-center"><i class="bi bi-shield-lock"></i> <?= e(t('site.name')) ?> — Admin</h1>
  <form method="post" action="<?= e(url('/admin/login')) ?>" class="card card-body">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="email">Email</label><input class="form-control" id="email" type="email" name="email" required autocomplete="username"></div>
    <div class="mb-3"><label class="form-label" for="pw">Password</label><input class="form-control" id="pw" type="password" name="password" required autocomplete="current-password"></div>
    <button class="btn btn-primary w-100">Sign in</button>
  </form>
</div></div>
