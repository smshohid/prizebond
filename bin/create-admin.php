<?php
/**
 * Create an administrator.  Usage:
 *   php bin/create-admin.php            (interactive; you are prompted for the password)
 *   ADMIN_NAME=.. ADMIN_EMAIL=.. ADMIN_PASSWORD=.. [ADMIN_ROLE=super_admin] php bin/create-admin.php   (one-time, non-interactive)
 * No default credentials exist anywhere in this project.
 */
declare(strict_types=1);
require __DIR__ . '/../bootstrap/app.php';
if (PHP_SAPI !== 'cli') {
    exit(1);
}

function ask(string $q, bool $secret = false): string
{
    echo $q;
    $unix = DIRECTORY_SEPARATOR === '/';
    if ($secret && $unix) {
        shell_exec('stty -echo');
    }
    $v = trim((string) fgets(STDIN));
    if ($secret && $unix) {
        shell_exec('stty echo');
        echo "\n";
    } elseif ($secret) {
        echo "(note: input is visible on Windows consoles)\n";
    }
    return $v;
}

$name = getenv('ADMIN_NAME') ?: ask('Full name: ');
$email = getenv('ADMIN_EMAIL') ?: ask('Email: ');
$pass = getenv('ADMIN_PASSWORD') ?: ask('Password (min 12 chars, upper/lower/digit): ', true);
$role = getenv('ADMIN_ROLE') ?: 'super_admin';
try {
    $id = (new App\Services\AdminService())->create($name, $email, $pass, $role);
    echo "Administrator #$id created with role $role.\n";
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(1);
}
