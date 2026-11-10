<?php
declare(strict_types=1);

if (!defined('BASE_PATH')) {
    define('BASE_PATH', dirname(__DIR__));
}
require_once BASE_PATH . '/vendor/autoload.php';

// Safety: refuse to run against anything that is not an obvious test database.
$db = (string) ($_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE'));
if (!str_contains($db, 'test')) {
    fwrite(STDERR, "Refusing to run tests against database '$db' (name must contain 'test').\n");
    exit(1);
}
if (is_file(BASE_PATH . '/.env')) {
    Dotenv\Dotenv::createImmutable(BASE_PATH)->safeLoad();
}
date_default_timezone_set('UTC');
mb_internal_encoding('UTF-8');
