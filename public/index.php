<?php
declare(strict_types=1);

define('BASE_PATH', dirname(__DIR__));
require BASE_PATH . '/bootstrap/app.php';

use App\Core\App;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;

// Production: force HTTPS (also honours reverse-proxy header).
if (is_production()) {
    $https = ($_SERVER['HTTPS'] ?? '') === 'on' || ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
    if (!$https) {
        $host = parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: ($_SERVER['HTTP_HOST'] ?? '');
        (new Response('', 301, ['Location' => 'https://' . $host . ($_SERVER['REQUEST_URI'] ?? '/')]))->emit();
        exit;
    }
}

Session::start();
App::handle(Request::capture())->emit();
