<?php
/**
 * Prize Bond Monitor BD installer. Safe to re-run; never drops data.
 *   php bin/install.php [--create-db] [--skip-admin]
 */
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}

$ok = true;
function step(string $m, bool $good = true, string $detail = ''): void
{
    global $ok;
    echo ($good ? '[ OK ] ' : '[FAIL] ') . $m . ($detail ? " - $detail" : '') . "\n";
    if (!$good) {
        $ok = false;
    }
}

echo "== Prize Bond Monitor BD installer ==\n";
step('PHP >= 8.2 (found ' . PHP_VERSION . ')', version_compare(PHP_VERSION, '8.2.0', '>='));
if (version_compare(PHP_VERSION, '8.3.0', '<')) {
    echo "       note: PHP 8.3+ is recommended; 8.2 is supported.\n";
}
foreach (['pdo', 'pdo_mysql', 'mbstring', 'curl', 'openssl', 'json', 'fileinfo', 'ctype', 'dom', 'zlib'] as $x) {
    step("extension $x", extension_loaded($x));
}
if (!is_file(BASE_PATH . '/.env')) {
    step('.env present', false, 'copy .env.example to .env and edit it');
    exit(1);
}
step('.env present');
if (env('APP_ENV') === 'production' && strlen((string) env('APP_KEY', '')) < 32) {
    step('APP_KEY set (>=32 chars)', false, 'generate with: php -r "echo bin2hex(random_bytes(32));"');
}

if (in_array('--create-db', $argv, true)) {
    try {
        $pdo = new PDO(sprintf('mysql:host=%s;port=%s;charset=utf8mb4', env('DB_HOST', '127.0.0.1'), env('DB_PORT', '3306')), (string) env('DB_USERNAME'), (string) env('DB_PASSWORD', ''));
        $db = preg_replace('/[^A-Za-z0-9_]/', '', (string) env('DB_DATABASE'));
        $pdo->exec("CREATE DATABASE IF NOT EXISTS `$db` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        step("database $db ensured");
    } catch (Throwable $e) {
        step('create database', false, $e->getMessage());
    }
}
try {
    App\Core\DB::pdo()->query('SELECT 1');
    step('database connection');
} catch (Throwable $e) {
    step('database connection', false, App\Core\Logger::scrub($e->getMessage()));
    exit(1);
}
step('database server ' . (string) App\Core\DB::pdo()->query('SELECT VERSION()')->fetchColumn());

foreach (['storage/imports', 'storage/logs', 'storage/cache', 'storage/exports', 'storage/backups', 'storage/sessions', 'public/uploads'] as $d) {
    $p = BASE_PATH . '/' . $d;
    if (!is_dir($p)) {
        @mkdir($p, 0750, true);
    }
    step("directory $d writable", is_dir($p) && is_writable($p));
}
try {
    $applied = (new App\Services\Migrator())->migrate();
    step('migrations', true, $applied ? implode(', ', $applied) : 'already up to date');
} catch (Throwable $e) {
    step('migrations', false, $e->getMessage());
    exit(1);
}
step('default Tk.100 bond type + official prize structure seeded', (int) App\Core\DB::val("SELECT COUNT(*) FROM bond_types WHERE code = 'BDT100'") === 1);

if (!in_array('--skip-admin', $argv, true)) {
    $has = (int) App\Core\DB::val('SELECT COUNT(*) FROM admin_users');
    if ($has === 0) {
        echo "\nNo administrator exists. Create the first super administrator now.\n";
        passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg(__DIR__ . '/create-admin.php'), $rc);
        step('first administrator created', $rc === 0);
    } else {
        step("administrators present ($has)");
    }
}
echo $ok ? "\nInstallation checks passed.\n" : "\nInstallation finished with problems - see FAIL lines above.\n";
exit($ok ? 0 : 1);
