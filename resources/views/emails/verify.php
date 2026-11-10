<p><?= e(t('mail.hello', ['name' => $name])) ?></p>
<p><?= e(t('mail.verify_body')) ?></p>
<p><a href="<?= e($link) ?>"><b><?= e(t('mail.verify_btn')) ?></b></a></p>
<p><?= e(t('mail.link_fallback')) ?><br><?= e($link) ?></p>
<p><?= e(t('mail.verify_expiry')) ?></p>
