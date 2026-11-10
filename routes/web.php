<?php
declare(strict_types=1);

use App\Controllers\AuthController;
use App\Controllers\BondController;
use App\Controllers\DashboardController;
use App\Controllers\HomeController;
use App\Controllers\ProfileController;
use App\Core\Router;

return function (Router $r): void {
    // Public
    $r->get('/', [HomeController::class, 'index']);
    $r->get('/check', [HomeController::class, 'check']);
    $r->get('/results', [HomeController::class, 'results']);
    $r->get('/results/{id}', [HomeController::class, 'draw']);
    foreach (HomeController::PAGES as $slug) {
        $r->add('GET', '/' . $slug, [HomeController::class, 'page'], []);
    }
    $r->get('/lang/{code}', [HomeController::class, 'lang']);
    $r->get('/sitemap.xml', [HomeController::class, 'sitemap']);
    $r->get('/robots.txt', [HomeController::class, 'robots']);

    // Authentication
    $r->get('/register', [AuthController::class, 'showRegister'], ['guest']);
    $r->post('/register', [AuthController::class, 'register'], ['guest']);
    $r->get('/login', [AuthController::class, 'showLogin'], ['guest']);
    $r->post('/login', [AuthController::class, 'login'], ['guest']);
    $r->post('/logout', [AuthController::class, 'logout']);
    $r->get('/verify-email/{token}', [AuthController::class, 'verify']);
    $r->post('/resend-verification', [AuthController::class, 'resendVerification']);
    $r->get('/forgot-password', [AuthController::class, 'showForgot'], ['guest']);
    $r->post('/forgot-password', [AuthController::class, 'forgot'], ['guest']);
    $r->get('/reset-password/{token}', [AuthController::class, 'showReset']);
    $r->post('/reset-password/{token}', [AuthController::class, 'reset']);

    // User area
    $r->get('/dashboard', [DashboardController::class, 'index'], ['auth']);
    $r->get('/matches', [DashboardController::class, 'matches'], ['auth']);
    $r->get('/bonds', [BondController::class, 'index'], ['auth']);
    $r->post('/bonds', [BondController::class, 'add'], ['auth']);
    $r->get('/bonds/import', [BondController::class, 'showImport'], ['auth']);
    $r->post('/bonds/import', [BondController::class, 'import'], ['auth']);
    $r->get('/bonds/template', [BondController::class, 'template'], ['auth']);
    $r->get('/bonds/export', [BondController::class, 'export'], ['auth']);
    $r->get('/bonds/{id}/edit', [BondController::class, 'edit'], ['auth']);
    $r->post('/bonds/{id}/edit', [BondController::class, 'update'], ['auth']);
    $r->post('/bonds/{id}/delete', [BondController::class, 'delete'], ['auth']);
    $r->post('/bonds/{id}/toggle', [BondController::class, 'toggle'], ['auth']);
    $r->get('/profile', [ProfileController::class, 'show'], ['auth']);
    $r->post('/profile', [ProfileController::class, 'update'], ['auth']);
    $r->post('/profile/password', [ProfileController::class, 'password'], ['auth']);
    $r->post('/profile/delete', [ProfileController::class, 'requestDeletion'], ['auth']);
};
