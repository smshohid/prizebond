<?php $err = $errors ?? []; $f = fn($k) => isset($err[$k]) ? '<div class="invalid-feedback d-block">' . e(t($err[$k])) . '</div>' : ''; ?>
<div class="container">
  <div class="row justify-content-center"><div class="col-md-7 col-lg-6">
    <h1 class="h3 mb-3"><?= e(t('nav.register')) ?></h1>
    <p class="text-muted"><?= e(t('auth.register_intro')) ?></p>
    <?php if (isset($err['_'])): ?><div class="alert alert-danger"><?= e(t($err['_'])) ?></div><?php endif; ?>
    <form method="post" action="<?= e(url('/register')) ?>" class="card card-body" novalidate>
      <?= csrf_field() ?>
      <div class="mb-3"><label class="form-label required" for="name"><?= e(t('auth.name')) ?></label><input class="form-control <?= isset($err['name']) ? 'is-invalid' : '' ?>" id="name" name="name" required maxlength="150" autocomplete="name" value="<?= e(old('name')) ?>"><?= $f('name') ?></div>
      <div class="mb-3"><label class="form-label required" for="email"><?= e(t('auth.email')) ?></label><input class="form-control <?= isset($err['email']) ? 'is-invalid' : '' ?>" id="email" type="email" name="email" required autocomplete="email" value="<?= e(old('email')) ?>"><?= $f('email') ?></div>
      <div class="mb-3"><label class="form-label" for="mobile"><?= e(t('auth.mobile')) ?> <span class="text-muted small">(<?= e(t('common.optional')) ?>)</span></label><input class="form-control <?= isset($err['mobile']) ? 'is-invalid' : '' ?>" id="mobile" name="mobile" inputmode="tel" autocomplete="tel" value="<?= e(old('mobile')) ?>"><?= $f('mobile') ?></div>
      <div class="mb-3"><label class="form-label required" for="pw"><?= e(t('auth.password')) ?></label><input class="form-control <?= isset($err['password']) ? 'is-invalid' : '' ?>" id="pw" type="password" name="password" required minlength="4" autocomplete="new-password" aria-describedby="pwh"><div id="pwh" class="form-text"><?= e(t('auth.password_hint')) ?></div><?= $f('password') ?></div>
      <div class="mb-3"><label class="form-label required" for="pw2"><?= e(t('auth.password_confirm')) ?></label><input class="form-control <?= isset($err['password_confirm']) ? 'is-invalid' : '' ?>" id="pw2" type="password" name="password_confirm" required autocomplete="new-password"><?= $f('password_confirm') ?></div>
      <p class="small text-muted"><?= e(t('auth.terms_note')) ?> <a href="<?= e(url('/terms')) ?>"><?= e(t('nav.terms')) ?></a>, <a href="<?= e(url('/privacy')) ?>"><?= e(t('nav.privacy')) ?></a>.</p>
      <button class="btn btn-primary w-100"><?= e(t('nav.register')) ?></button>
    </form>
  </div></div>
</div>
