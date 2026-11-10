<?php
// PHP syntax check for every .php file in the project (excluding vendor).
$root = dirname(__DIR__); $bad = 0; $n = 0;
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $f) {
    $p = $f->getPathname();
    if (!str_ends_with($p, '.php') || str_contains($p, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR) || str_contains($p, 'composer.phar')) continue;
    $n++; exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($p) . ' 2>&1', $o, $c);
    if ($c !== 0) { $bad++; echo implode("\n", $o) . "\n"; } $o = [];
}
echo "Checked $n files, $bad with errors\n"; exit($bad ? 1 : 0);
