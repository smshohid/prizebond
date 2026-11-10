<?php $bn = \App\Core\Lang::locale() === 'bn'; ?>
<div class="container">
  <section class="hero p-4 p-md-5 mb-4">
    <div class="row align-items-center g-4">
      <div class="col-lg-7">
        <h1 class="display-6 fw-bold"><?= e(t('home.hero_title')) ?></h1>
        <p class="lead mb-0"><?= e(t('home.hero_sub')) ?></p>
        <div class="mt-3 d-flex gap-2 flex-wrap">
          <a class="btn btn-warning" href="<?= e(url('/register')) ?>"><i class="bi bi-bell"></i> <?= e(t('home.cta_register')) ?></a>
          <a class="btn btn-outline-light" href="<?= e(url('/results')) ?>"><?= e(t('nav.results')) ?></a>
        </div>
      </div>
      <div class="col-lg-5">
        <form class="bg-white text-dark rounded-3 p-3 shadow" method="get" action="<?= e(url('/check')) ?>">
          <h2 class="h6 mb-3"><i class="bi bi-search"></i> <?= e(t('home.quick_check')) ?></h2>
          <?php if (count($types) > 1): ?>
          <select class="form-select mb-2" name="bond_type" aria-label="<?= e(t('bond.type')) ?>">
            <?php foreach ($types as $tp): ?><option value="<?= (int) $tp['id'] ?>"><?= e($bn && $tp['name_bn'] ? $tp['name_bn'] : $tp['name']) ?></option><?php endforeach; ?>
          </select>
          <?php elseif ($types): ?><input type="hidden" name="bond_type" value="<?= (int) $types[0]['id'] ?>"><?php endif; ?>
          <div class="row g-2">
            <div class="col-4"><input class="form-control" name="series" maxlength="8" placeholder="<?= e(t('bond.series')) ?>" aria-label="<?= e(t('bond.series')) ?>" data-series></div>
            <div class="col-8"><input class="form-control mono" name="number" inputmode="numeric" maxlength="7" required placeholder="0000000" aria-label="<?= e(t('bond.number')) ?>" data-digits></div>
          </div>
          <button class="btn btn-primary w-100 mt-2"><?= e(t('home.check_btn')) ?></button>
          <div class="form-text"><?= e(t('home.check_hint')) ?></div>
        </form>
      </div>
    </div>
  </section>

  <section class="row g-3 mb-4" aria-label="Statistics">
    <?php foreach ([['draws', 'stats.draws', 'bi-trophy'], ['bonds', 'stats.bonds', 'bi-collection'], ['users', 'stats.users', 'bi-people'], ['matches', 'stats.matches', 'bi-bullseye']] as [$k, $lab, $ic]): ?>
      <div class="col-6 col-lg-3"><div class="card stat-card h-100"><div class="card-body"><div class="text-muted small"><i class="bi <?= e($ic) ?>"></i> <?= e(t($lab)) ?></div><div class="num"><?= e(number_format($stats[$k])) ?></div></div></div></div>
    <?php endforeach; ?>
  </section>

  <section class="mb-5">
    <h2 class="h4"><?= e(t('home.latest')) ?></h2>
    <?php if ($latest): $d = $latest['draw']; ?>
      <div class="card">
        <div class="card-header d-flex justify-content-between flex-wrap gap-2">
          <span><strong><?= e(t('draw.title', ['n' => $d['draw_number']])) ?></strong> · <?= e(fmt_date($d['draw_date'])) ?> · <?= e($d['bond_name']) ?></span>
          <span class="badge verified-badge"><i class="bi bi-patch-check"></i> <?= e(t('draw.verified')) ?></span>
        </div>
        <div class="card-body">
          <?php foreach ($latest['numbers'] as $tier): ?>
            <h3 class="h6 tier-title mt-3"><?= e($bn && $tier['name_bn'] ? $tier['name_bn'] : $tier['name']) ?> — <?= e(money($tier['amount'])) ?></h3>
            <div><?php foreach ($tier['numbers'] as $n): ?><span class="num-chip"><?= e($n) ?></span><?php endforeach; ?></div>
          <?php endforeach; ?>
          <a class="btn btn-sm btn-outline-primary mt-3" href="<?= e(url('/results/' . $d['id'])) ?>"><?= e(t('draw.details')) ?></a>
          <?php if ($d['source_url']): ?><a class="btn btn-sm btn-link mt-3" rel="noopener noreferrer" target="_blank" href="<?= e($d['source_url']) ?>"><i class="bi bi-box-arrow-up-right"></i> <?= e(t('draw.official_doc')) ?></a><?php endif; ?>
        </div>
      </div>
    <?php else: ?>
      <div class="alert alert-info"><?= e(t('home.no_results')) ?></div>
    <?php endif; ?>
  </section>

  <section class="mb-5">
    <h2 class="h4 mb-3"><?= e(t('page.how-it-works.title')) ?></h2>
    <div class="row g-3 text-center">
      <?php foreach ([['bi-person-plus', 'how.s1'], ['bi-envelope-check', 'how.s2'], ['bi-collection', 'how.s3'], ['bi-bell', 'how.s4']] as $i => [$ic, $k]): ?>
        <div class="col-6 col-md-3"><div class="step-icon mb-2"><i class="bi <?= e($ic) ?>"></i></div><h3 class="h6"><?= ($i + 1) ?>. <?= e(t($k)) ?></h3><p class="small text-muted"><?= e(t($k . '_d')) ?></p></div>
      <?php endforeach; ?>
    </div>
  </section>

  <section class="mb-5 row g-3">
    <div class="col-md-6"><div class="card h-100"><div class="card-body">
      <h2 class="h5"><?= e(t('home.benefits')) ?></h2>
      <ul class="mb-0"><li><?= e(t('home.b1')) ?></li><li><?= e(t('home.b2')) ?></li><li><?= e(t('home.b3')) ?></li><li><?= e(t('home.b4')) ?></li></ul>
    </div></div></div>
    <div class="col-md-6"><div class="card h-100"><div class="card-body">
      <h2 class="h5"><?= e(t('home.sources')) ?></h2>
      <p class="small text-muted"><?= e(t('home.sources_d')) ?></p>
      <ul class="mb-0">
        <li><a rel="noopener noreferrer" target="_blank" href="https://www.bb.org.bd/en/index.php/investfacility/prizebond">Bangladesh Bank</a> — <?= e(t('home.src_primary')) ?></li>
        <li><a rel="noopener noreferrer" target="_blank" href="https://prizebond.ird.gov.bd/">IRD PBRIS</a> — <?= e(t('home.src_secondary')) ?></li>
        <li><a rel="noopener noreferrer" target="_blank" href="https://nationalsavings.gov.bd/">National Savings Directorate</a></li>
      </ul>
    </div></div></div>
  </section>

  <section class="mb-4">
    <h2 class="h4"><?= e(t('nav.faq')) ?></h2>
    <div class="accordion" id="faq">
      <?php foreach ([1, 2, 3] as $i): ?>
        <div class="accordion-item"><h3 class="accordion-header"><button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#f<?= $i ?>"><?= e(t("faq.q$i")) ?></button></h3>
          <div id="f<?= $i ?>" class="accordion-collapse collapse" data-bs-parent="#faq"><div class="accordion-body faq-a"><?= e(t("faq.a$i")) ?></div></div></div>
      <?php endforeach; ?>
    </div>
    <a class="small" href="<?= e(url('/faq')) ?>"><?= e(t('home.more_faq')) ?> →</a>
  </section>

  <div class="alert alert-secondary small"><strong><?= e(t('common.disclaimer')) ?>:</strong> <?= e(t('footer.disclaimer')) ?></div>
</div>
