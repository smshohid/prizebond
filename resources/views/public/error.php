<div class="container text-center py-5">
  <h1 class="display-4"><?= (int) $status ?></h1>
  <p class="lead"><?= e($heading) ?></p>
  <?php if ($message !== ''): ?><p class="text-muted"><?= e($message) ?></p><?php endif; ?>
  <a class="btn btn-primary" href="<?= e(url('/')) ?>">Home</a>
</div>
