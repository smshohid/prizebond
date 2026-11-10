<?php $bn = \App\Core\Lang::locale() === 'bn'; ?>
<p><?= e(t('mail.hello', ['name' => $name])) ?></p>
<p><b><?= e(t('mail.win_disclaimer')) ?></b></p>
<p><?= e(t('mail.win_draw', ['n' => $draw_number, 'date' => fmt_date($draw_date, 'd F Y')])) ?></p>
<table width="100%" cellpadding="6" cellspacing="0" border="1" bordercolor="#d7e0f3">
  <tr bgcolor="#eef2fa"><td><b><?= e(t('bond.series')) ?></b></td><td><b><?= e(t('bond.number')) ?></b></td><td><b><?= e(t('draw.prize')) ?></b></td><td align="right"><b><?= e(t('draw.amount')) ?></b></td></tr>
  <?php foreach ($matches as $m): ?>
  <tr><td><?= e($m['series'] ?: '—') ?></td><td><b><?= e($m['number']) ?></b></td><td><?= e($bn && $m['tier_bn'] ? $m['tier_bn'] : $m['tier']) ?></td><td align="right"><?= e(money($m['amount'])) ?></td></tr>
  <?php if (($m['eligibility'] ?? 'eligible') !== 'eligible'): ?>
  <tr><td colspan="4"><font size="2" color="#8a5a00"><?= e(t('match.e_' . $m['eligibility'])) ?></font></td></tr>
  <?php endif; ?>
  <?php endforeach; ?>
</table>
<p><a href="<?= e(app_url('matches')) ?>"><b><?= e(t('mail.win_dashboard')) ?></b></a><br>
<a href="<?= e($source_url) ?>"><?= e(t('mail.win_source')) ?></a></p>
<p><font size="2"><?= e(t('match.claim_note')) ?></font></p>
