<p><?= e(t('mail.hello', ['name' => $name])) ?></p>
<p><?= e(t('mail.reset_body')) ?></p>
<p><a href="<?= e($link) ?>"><b><?= e(t('mail.reset_btn')) ?></b></a></p>
<p><?= e(t('mail.link_fallback')) ?><br><?= e($link) ?></p>
<p><?= e(t('mail.reset_expiry')) ?></p>
