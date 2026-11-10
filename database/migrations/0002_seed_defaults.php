<?php
/**
 * Default configuration seed.
 *
 * Prize tiers are seeded ONLY from the structure published by Bangladesh Bank for the
 * Tk.100 bond (verified against official draw PDFs, draws 118-125: five prize ranks,
 * 46 general numbers per draw, each applying to every series).
 */
$now = gmdate('Y-m-d H:i:s');
$perms = [
  'view_dashboard' => 'View admin dashboard',
  'view_users' => 'View user accounts',
  'manage_users' => 'Suspend/reactivate user accounts',
  'view_bonds' => 'View registered bonds',
  'manage_bond_types' => 'Edit bond types and prize tiers',
  'view_draws' => 'View draws and winning numbers',
  'import_results' => 'Upload / enter / fetch results',
  'review_results' => 'Review and edit staged results',
  'verify_results' => 'Approve or reject staged results',
  'publish_results' => 'Publish verified results',
  'correct_results' => 'Correct published results (versioned)',
  'run_matching' => 'Run / re-run matching',
  'view_matches' => 'View prize matches',
  'view_email_queue' => 'View email queue and logs',
  'manage_email_queue' => 'Retry / cancel queued emails',
  'manage_sources' => 'Manage result sources',
  'view_cron' => 'View cron job monitor',
  'view_audit' => 'View audit logs',
  'manage_roles' => 'Manage admin users, roles and permissions',
  'manage_settings' => 'Edit system settings',
];
$roles = [
  'super_admin' => ['Full system access', array_keys($perms)],
  'result_manager' => ['Imports, verifies, publishes results and runs matching', [
      'view_dashboard','view_draws','import_results','review_results','verify_results','publish_results',
      'correct_results','run_matching','view_matches','view_cron','view_email_queue']],
  'support_admin' => ['Supports users; cannot touch official results', [
      'view_dashboard','view_users','view_bonds','view_matches','view_email_queue','view_draws']],
];
$settings = [
  ['site.name', 'Prize Bond Monitor BD', 'Public site name'],
  ['mail.batch_size', '50', 'Emails sent per send_notifications run'],
  ['mail.max_attempts', '5', 'Max delivery attempts per email'],
  ['mail.retry_backoff_minutes', '30', 'Base backoff between attempts (multiplied by attempt number)'],
  ['mail.admin_alert_email', '', 'Recipient for admin/import-failure alerts'],
  ['matching.batch_size', '500', 'Winning numbers matched per batch'],
  ['eligibility.min_days', '60', 'Official draw document: bonds sold >= 60 days before draw date (sale date counted, draw date excluded)'],
  ['eligibility.min_calendar_months', '2', 'IRD general information: minimum 2 months after sale date'],
  ['imports.auto_publish', '0', 'Auto-publish verified imports only when IRD cross-check fully agrees (0 = admin must publish)'],
  ['imports.irD_verification', '1', 'Cross-check staged results against IRD'],
  ['sources.check_interval_minutes', '360', 'Default source check interval (every 6 hours)'],
  ['security.login_max_attempts', '5', 'Failed logins before temporary lock'],
  ['security.login_window_minutes', '15', 'Login rate-limit window'],
  ['backup.retention_days', '14', 'Days to keep database backups'],
  ['maintenance.log_retention_days', '90', 'Days to keep cron/notification logs'],
  ['claims.period_note', 'Prize claims must be made within two years of the draw date (IRD general information). Tax may be deducted at source; rates are set by law and may change.', 'Informational claim note'],
];

$up = [];
foreach ($perms as $n => $d) {
    $up[] = ['INSERT INTO admin_permissions (name, description) VALUES (?, ?)', [$n, $d]];
}
foreach ($roles as $n => [$d, $plist]) {
    $up[] = ['INSERT INTO admin_roles (name, description) VALUES (?, ?)', [$n, $d]];
    foreach ($plist as $p) {
        $up[] = ['INSERT INTO admin_role_permissions (role_id, permission_id) SELECT r.id, p.id FROM admin_roles r, admin_permissions p WHERE r.name = ? AND p.name = ?', [$n, $p]];
    }
}
foreach ($settings as [$k, $v, $d]) {
    $up[] = ['INSERT INTO system_settings (setting_key, setting_value, description, updated_at) VALUES (?, ?, ?, ?)', [$k, $v, $d, $now]];
}
$up[] = ["INSERT INTO bond_types (code, name, name_bn, denomination, number_length, series_applicable, series_required, series_affects_matching, source_verified, is_enabled, notes, created_at, updated_at)
          VALUES ('BDT100', 'Bangladesh Tk. 100 Prize Bond', 'বাংলাদেশ ১০০ টাকা মূল্যমানের প্রাইজবন্ড', 100.00, 7, 1, 0, 0, 1, 1,
          'Single common-number system: each winning 7-digit number applies to every series (Bangladesh Bank draw documents, IRD PBRIS).', ?, ?)", [$now, $now]];
$tiers = [['1','1st Prize','১ম পুরস্কার',600000,1,1],['2','2nd Prize','২য় পুরস্কার',325000,2,1],['3','3rd Prize','৩য় পুরস্কার',100000,3,2],
          ['4','4th Prize','৪র্থ পুরস্কার',50000,4,2],['5','5th Prize','৫ম পুরস্কার',10000,5,40]];
foreach ($tiers as [$c,$n,$nb,$amt,$rank,$cnt]) {
    $up[] = ["INSERT INTO prize_tiers (bond_type_id, code, name, name_bn, amount, rank_order, expected_count, is_active, created_at, updated_at)
              SELECT id, ?, ?, ?, ?, ?, ?, 1, ?, ? FROM bond_types WHERE code='BDT100'", [$c,$n,$nb,$amt,$rank,$cnt,$now,$now]];
}
$up[] = ["INSERT INTO result_sources (code, name, base_url, authority, adapter, is_enabled, check_interval_minutes, created_at, updated_at) VALUES
          ('bb', 'Bangladesh Bank - Prize Bond', 'https://www.bb.org.bd/en/index.php/investfacility/prizebond', 'primary', 'BangladeshBankAdapter', 1, 360, ?, ?)", [$now, $now]];
$up[] = ["INSERT INTO result_sources (code, name, base_url, authority, adapter, is_enabled, check_interval_minutes, created_at, updated_at) VALUES
          ('ird', 'IRD Prize Bond Result Inquiry (PBRIS)', 'https://prizebond.ird.gov.bd/', 'secondary', 'IRDAdapter', 1, 360, ?, ?)", [$now, $now]];
$up[] = ["INSERT INTO result_sources (code, name, base_url, authority, adapter, is_enabled, check_interval_minutes, created_at, updated_at) VALUES
          ('nsd', 'National Savings Directorate (reference)', 'https://nationalsavings.gov.bd/', 'reference', 'None', 0, 1440, ?, ?)", [$now, $now]];

return ['up' => $up, 'down' => [
  'DELETE FROM result_sources', 'DELETE FROM prize_tiers', 'DELETE FROM bond_types', 'DELETE FROM system_settings',
  'DELETE FROM admin_role_permissions', 'DELETE FROM admin_roles', 'DELETE FROM admin_permissions',
]];
