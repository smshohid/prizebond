<div class="container narrow">
  <h1 class="h3 mb-3"><?= e(t('page.faq.title')) ?></h1>
  <div class="accordion" id="faq">
    <?php for ($i = 1; $i <= 8; $i++): ?>
      <div class="accordion-item"><h2 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#f<?= $i ?>"><?= e(t("faq.q$i")) ?></button></h2>
        <div id="f<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#faq"><div class="accordion-body faq-a"><?= e(t("faq.a$i")) ?></div></div></div>
    <?php endfor; ?>
  </div>
  <div class="alert alert-secondary small mt-3"><?= e(t('footer.disclaimer')) ?></div>
</div>
