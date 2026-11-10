<div class="container">
  <div class="row justify-content-center"><div class="col-md-6 col-lg-5">
    <h1 class="h3 mb-3"><?= e(t('nav.login')) ?></h1>
    <form method="post" action="<?= e(url('/login')) ?>" class="card card-body" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label" for="email"><?= e(t('auth.email')) ?></label>
        <input class="form-control" id="email" type="email" name="email" required autocomplete="username" value="<?= e(old('email')) ?>"></div>
      <div class="mb-3"><label class="form-label" for="pw"><?= e(t('auth.password')) ?></label>
        <input class="form-control" id="pw" type="password" name="password" required autocomplete="current-password"></div>
      <button class="btn btn-primary w-100"><?= e(t('nav.login')) ?></button>
      <div class="d-flex justify-content-between mt-3 small"><a href="<?= e(url('/forgot-password')) ?>"><?= e(t('auth.forgot')) ?></a><a href="<?= e(url('/register')) ?>"><?= e(t('nav.register')) ?></a></div>
    </form>
    <form method="post" action="<?= e(url('/resend-verification')) ?>" class="mt-3 small">
      <?= csrf_field() ?><label class="form-label" for="re"><?= e(t('auth.resend_label')) ?></label>
      <div class="input-group input-group-sm"><input id="re" class="form-control" type="email" name="email" required><button class="btn btn-outline-secondary"><?= e(t('auth.resend')) ?></button></div>
    </form>
  </div></div>
</div>
