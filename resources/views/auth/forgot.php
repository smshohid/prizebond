<div class="container"><div class="row justify-content-center"><div class="col-md-6 col-lg-5">
  <h1 class="h3 mb-3"><?= e(t('auth.forgot')) ?></h1>
  <form method="post" action="<?= e(url('/forgot-password')) ?>" class="card card-body">
    <?= csrf_field() ?>
    <div class="mb-3"><label class="form-label" for="email"><?= e(t('auth.email')) ?></label><input class="form-control" id="email" type="email" name="email" required autocomplete="email"></div>
    <button class="btn btn-primary w-100"><?= e(t('auth.send_reset')) ?></button>
  </form>
</div></div></div>
