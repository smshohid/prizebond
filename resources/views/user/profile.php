<?php $u = \App\Core\Auth::user(); ?>
<div class="container">
  <h1 class="h3 mb-3"><?= e(t('nav.profile')) ?></h1>
  <div class="row g-4">
    <div class="col-lg-6"><form method="post" action="<?= e(url('/profile')) ?>" class="card card-body">
      <?= csrf_field() ?><h2 class="h5"><?= e(t('profile.details')) ?></h2>
      <div class="mb-3"><label class="form-label" for="name"><?= e(t('auth.name')) ?></label><input id="name" class="form-control" name="name" required maxlength="150" value="<?= e($u['full_name']) ?>"></div>
      <div class="mb-3"><label class="form-label" for="em"><?= e(t('auth.email')) ?></label><input id="em" class="form-control" value="<?= e($u['email']) ?>" disabled></div>
      <div class="mb-3"><label class="form-label" for="mb"><?= e(t('auth.mobile')) ?></label><input id="mb" class="form-control" name="mobile" value="<?= e($u['mobile'] ?? '') ?>"></div>
      <div class="mb-3"><label class="form-label" for="lg"><?= e(t('profile.language')) ?></label><select id="lg" class="form-select" name="language"><option value="en" <?= ($u['language'] ?? 'en') === 'en' ? 'selected' : '' ?>>English</option><option value="bn" <?= ($u['language'] ?? 'en') === 'bn' ? 'selected' : '' ?>>বাংলা</option></select></div>
      <div class="form-check form-switch mb-2"><input class="form-check-input" type="checkbox" id="ea" name="email_alerts" value="1" <?= $u['email_alerts'] ? 'checked' : '' ?>><label class="form-check-label" for="ea"><?= e(t('profile.alerts')) ?></label></div>
      <div class="form-check form-switch mb-3"><input class="form-check-input" type="checkbox" id="em2" name="email_marketing" value="1"><label class="form-check-label" for="em2"><?= e(t('profile.news')) ?></label></div>
      <button class="btn btn-primary"><?= e(t('common.save')) ?></button>
    </form></div>
    <div class="col-lg-6">
      <form method="post" action="<?= e(url('/profile/password')) ?>" class="card card-body mb-4">
        <?= csrf_field() ?><h2 class="h5"><?= e(t('profile.change_password')) ?></h2>
        <div class="mb-3"><label class="form-label" for="cp"><?= e(t('profile.current_password')) ?></label><input id="cp" type="password" class="form-control" name="current_password" required autocomplete="current-password"></div>
        <div class="mb-3"><label class="form-label" for="np"><?= e(t('profile.new_password')) ?></label><input id="np" type="password" class="form-control" name="new_password" required minlength="4" autocomplete="new-password"><div class="form-text"><?= e(t('auth.password_hint')) ?></div></div>
        <div class="mb-3"><label class="form-label" for="np2"><?= e(t('auth.password_confirm')) ?></label><input id="np2" type="password" class="form-control" name="new_password_confirm" required autocomplete="new-password"></div>
        <button class="btn btn-outline-primary"><?= e(t('profile.change_password')) ?></button>
      </form>
      <form method="post" action="<?= e(url('/profile/delete')) ?>" class="card card-body border-danger" data-confirm="<?= e(t('profile.confirm_delete')) ?>">
        <?= csrf_field() ?><h2 class="h5 text-danger"><?= e(t('profile.delete')) ?></h2><p class="small text-muted"><?= e(t('profile.delete_help')) ?></p>
        <div class="mb-3"><label class="form-label" for="dp"><?= e(t('profile.current_password')) ?></label><input id="dp" type="password" class="form-control" name="current_password" required></div>
        <button class="btn btn-outline-danger"><?= e(t('profile.delete')) ?></button>
      </form>
    </div>
  </div>
</div>
