<?php use App\Core\AdminAuth as AA; ?>
<nav aria-label="breadcrumb"><ol class="breadcrumb"><li class="breadcrumb-item"><a href="<?= e(url('/admin/draws')) ?>">Draws</a></li><li class="breadcrumb-item active">#<?= (int) $draw['draw_number'] ?></li></ol></nav>
<div class="d-flex flex-wrap justify-content-between align-items-start gap-2 mb-3">
  <div><h1 class="h3 mb-1">Draw #<?= (int) $draw['draw_number'] ?> <span class="badge text-bg-success"><?= e($draw['publication_status']) ?></span> <span class="badge text-bg-secondary">v<?= (int) $draw['version'] ?></span></h1>
    <p class="text-muted mb-0"><?= e(fmt_date($draw['draw_date'])) ?> · <?= e($draw['bond_name']) ?> · lifecycle: <strong><?= e($draw['lifecycle']) ?></strong> · published <?= e(fmt_dt($draw['published_at'])) ?></p></div>
  <?php if (AA::can('run_matching') && $draw['publication_status'] === 'published'): ?>
  <form method="post" action="<?= e(url('/admin/draws/' . $draw['id'] . '/rematch')) ?>" data-confirm="Re-run matching for this draw? Safe to repeat: no duplicate matches or emails are created."><?= csrf_field() ?><button class="btn btn-outline-primary"><i class="bi bi-arrow-repeat"></i> Run matching now</button></form>
  <?php endif; ?>
</div>
<div class="row g-3 mb-3">
  <div class="col-md-3"><div class="card stat-card"><div class="card-body"><div class="small text-muted">Matches</div><div class="num"><?= (int) $stats['total'] ?></div></div></div></div>
  <div class="col-md-3"><div class="card stat-card"><div class="card-body"><div class="small text-muted">Emails sent</div><div class="num"><?= (int) $stats['sent'] ?></div></div></div></div>
  <div class="col-md-3"><div class="card stat-card"><div class="card-body"><div class="small text-muted">Queued</div><div class="num"><?= (int) $stats['queued'] ?></div></div></div></div>
  <div class="col-md-3"><div class="card stat-card"><div class="card-body"><div class="small text-muted">Failed</div><div class="num"><?= (int) $stats['failed'] ?></div></div></div></div>
</div>
<div class="card mb-3"><div class="card-body">
  <p class="small text-muted mb-2">Source: <?= $draw['source_url'] ? '<a rel="noopener noreferrer" target="_blank" href="' . e($draw['source_url']) . '">' . e($draw['source_url']) . '</a>' : 'manual' ?> · SHA-256 <span class="mono"><?= e($draw['source_checksum'] ?? '—') ?></span></p>
  <?php foreach ($tiers as $tier): ?>
    <h2 class="h6 tier-title mt-3"><?= e($tier['name']) ?> — <?= e(money($tier['amount'])) ?></h2>
    <div><?php foreach ($tier['numbers'] as $n): ?><span class="num-chip"><?= e($n) ?></span><?php endforeach; ?></div>
  <?php endforeach; ?>
</div></div>

<?php if (AA::can('correct_results') && $draw['publication_status'] === 'published'): ?>
<div class="card mb-3"><div class="card-header">Versioned correction</div><div class="card-body">
  <p class="small text-muted">Replaces the winning numbers with the values below as a new version. Removed numbers are voided (never deleted), their matches voided, and users already emailed receive a retraction notice. The reason is audited.</p>
  <form method="post" action="<?= e(url('/admin/draws/' . $draw['id'] . '/correct')) ?>" data-confirm="Apply this correction to a PUBLISHED draw?">
    <?= csrf_field() ?>
    <?php foreach ($tierRows as $t): ?>
      <label class="form-label small mb-0"><?= e($t['name']) ?></label>
      <textarea class="form-control numbers mb-2" rows="2" name="tier_<?= e($t['code']) ?>"><?= e(implode(' ', $snap['tiers'][$t['code']] ?? [])) ?></textarea>
    <?php endforeach; ?>
    <input class="form-control mb-2" name="reason" placeholder="Reason for correction (required)" required>
    <button class="btn btn-warning">Apply correction</button>
  </form>
</div></div>
<?php endif; ?>

<?php if ($corrections): ?>
<div class="card"><div class="card-header">Correction history</div><ul class="list-group list-group-flush small">
  <?php foreach ($corrections as $c): ?><li class="list-group-item">v<?= (int) $c['version'] ?> — <?= e(fmt_dt($c['created_at'])) ?> by <?= e($c['full_name'] ?? 'unknown') ?>: <?= e($c['reason']) ?></li><?php endforeach; ?>
</ul></div>
<?php endif; ?>
