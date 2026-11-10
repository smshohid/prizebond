<?php
// Dev helper: look numbers up on IRD exactly as the verifier does. Usage: php scripts/ird_probe.php 0217419 0923283
require __DIR__ . '/../bootstrap/app.php';
$ird = new App\Services\IRDAdapter();
print_r($ird->lookup(array_slice($argv, 1)));
