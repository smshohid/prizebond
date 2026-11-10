<div class="container"><div class="row justify-content-center"><div class="col-md-6 col-lg-5">
  <h1 class="h3 mb-3"><?= e(t('auth.reset_title')) ?></h1>
  <form method="post" action="<?= e(url('/reset-password/' . $token)) ?>" class="card card-body">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="pw"><?= e(t('auth.password')) ?></label><input class="form-control" id="pw" type="password" name="password" required minlength="4" autocomplete="new-password"><div class="form-text"><?= e(t('auth.password_hint')) ?></div></div>
    <div class="mb-3"><label class="form-label" for="pw2"><?= e(t('auth.password_confirm')) ?></label><input class="form-control" id="pw2" type="password" name="password_confirm" required autocomplete="new-password"></div>
    <button class="btn btn-primary w-100"><?= e(t('auth.reset_title')) ?></button>
  </form>
</div></div></div>
