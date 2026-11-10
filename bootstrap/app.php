<?php
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/vendor/autoload.php';

if (is_file(BASE_PATH . '/.env')) {
    Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
}

date_default_timezone_set('UTC');   // storage timezone; display converts to Asia/Dhaka
mb_internal_encoding('UTF-8');

ini_set('display_errors', is_production() ? '0' : '1');
ini_set('log_errors', '1');
if (!is_dir(BASE_PATH . '/storage/logs')) {
    @mkdir(BASE_PATH . '/storage/logs', 0750, true);
}
ini_set('error_log', BASE_PATH . '/storage/logs/php-error.log');
