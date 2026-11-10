<?php
declare(strict_types=1);

use App\Controllers\Admin\AdminController as A;
use App\Controllers\Admin\ImportController as I;
use App\Core\Router;

return function (Router $r): void {
    $r->get('/admin/login', [A::class, 'showLogin']);
    $r->post('/admin/login', [A::class, 'login']);
    $r->post('/admin/logout', [A::class, 'logout'], ['admin']);

    $r->get('/admin', [A::class, 'dashboard'], ['perm:view_dashboard']);

    $r->get('/admin/users', [A::class, 'users'], ['perm:view_users']);
    $r->post('/admin/users/{id}/status', [A::class, 'userStatus'], ['perm:manage_users']);
    $r->get('/admin/bonds', [A::class, 'bonds'], ['perm:view_bonds']);

    $r->get('/admin/bond-types', [A::class, 'bondTypes'], ['perm:manage_bond_types']);
    $r->post('/admin/bond-types/{id}', [A::class, 'updateType'], ['perm:manage_bond_types']);
    $r->post('/admin/prize-tiers/{id}', [A::class, 'updateTier'], ['perm:manage_bond_types']);

    $r->get('/admin/draws', [I::class, 'draws'], ['perm:view_draws']);
    $r->get('/admin/draws/{id}', [I::class, 'drawShow'], ['perm:view_draws']);
    $r->post('/admin/draws/{id}/rematch', [I::class, 'rematch'], ['perm:run_matching']);
    $r->post('/admin/draws/{id}/correct', [I::class, 'correct'], ['perm:correct_results']);

    $r->get('/admin/imports', [I::class, 'index'], ['perm:review_results']);
    $r->post('/admin/imports/fetch', [I::class, 'fetchNow'], ['perm:import_results']);
    $r->post('/admin/imports/upload', [I::class, 'upload'], ['perm:import_results']);
    $r->post('/admin/imports/manual', [I::class, 'manual'], ['perm:import_results']);
    $r->get('/admin/imports/{id}', [I::class, 'show'], ['perm:review_results']);
    $r->post('/admin/imports/{id}/{action}', [I::class, 'action'], ['admin']);   // per-action permission enforced in controller

    $r->get('/admin/winning-numbers', [A::class, 'winningNumbers'], ['perm:view_draws']);
    $r->get('/admin/matches', [A::class, 'matches'], ['perm:view_matches']);
    $r->get('/admin/email-queue', [A::class, 'emailQueue'], ['perm:view_email_queue']);
    $r->post('/admin/email-queue/{id}', [A::class, 'queueAction'], ['perm:manage_email_queue']);
    $r->get('/admin/email-logs', [A::class, 'emailLogs'], ['perm:view_email_queue']);
    $r->get('/admin/sources', [A::class, 'sources'], ['perm:manage_sources']);
    $r->get('/admin/cron', [A::class, 'cron'], ['perm:view_cron']);
    $r->post('/admin/cron/run', [A::class, 'runJob'], ['perm:run_matching']);
    $r->get('/admin/audit', [A::class, 'audit'], ['perm:view_audit']);
    $r->get('/admin/roles', [A::class, 'roles'], ['perm:manage_roles']);
    $r->post('/admin/roles/admins', [A::class, 'createAdmin'], ['perm:manage_roles']);
    $r->post('/admin/roles/admins/{id}', [A::class, 'updateAdmin'], ['perm:manage_roles']);
    $r->get('/admin/settings', [A::class, 'settings'], ['perm:manage_settings']);
    $r->post('/admin/settings', [A::class, 'saveSettings'], ['perm:manage_settings']);
};
