<?php if (!empty($pg) && $pg['pages'] > 1):
    $qs = $_GET;
    unset($qs['page']);
    $mk = fn(int $p) => '?' . http_build_query($qs + ['page' => $p]);
?>
<nav aria-label="Pagination"><ul class="pagination pagination-sm flex-wrap">
  <li class="page-item <?= $pg['page'] <= 1 ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($mk(max(1, $pg['page'] - 1))) ?>">&laquo;</a></li>
  <?php for ($i = max(1, $pg['page'] - 3); $i <= min($pg['pages'], $pg['page'] + 3); $i++): ?>
    <li class="page-item <?= $i === $pg['page'] ? 'active' : '' ?>"><a class="page-link" href="<?= e($mk($i)) ?>"><?= $i ?></a></li>
  <?php endfor; ?>
  <li class="page-item <?= $pg['page'] >= $pg['pages'] ? 'disabled' : '' ?>"><a class="page-link" href="<?= e($mk(min($pg['pages'], $pg['page'] + 1))) ?>">&raquo;</a></li>
</ul><div class="small text-muted"><?= (int) $pg['total'] ?> total</div></nav>
<?php endif; ?>
