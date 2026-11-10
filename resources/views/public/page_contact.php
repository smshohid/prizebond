<div class="container narrow">
  <h1 class="h3 mb-3"><?= e(t('page.contact.title')) ?></h1>
  <p><?= e(t('page.contact.p1')) ?></p>
  <p><?= e(t('page.contact.p2')) ?></p>
  <p><i class="bi bi-envelope"></i> <?= e(env('CONTACT_EMAIL', 'contact@example.com')) ?></p>
  <div class="alert alert-secondary small"><?= e(t('footer.disclaimer')) ?></div>
</div>
