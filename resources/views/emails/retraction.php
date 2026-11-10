<p><?= e(t('mail.hello', ['name' => $name])) ?></p>
<p><b><?= e(t('mail.retract_body', ['n' => $draw_number])) ?></b></p>
<p><?= e(t('mail.retract_reason')) ?>: <?= e($reason) ?></p>
<p><a href="<?= e(app_url('matches')) ?>"><?= e(t('mail.win_dashboard')) ?></a></p>
