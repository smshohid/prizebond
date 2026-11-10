<?php
require __DIR__ . '/../bootstrap/app.php';
$p = new App\Services\PDFResultParser();
foreach (glob(__DIR__ . '/../tests/Fixtures/bb_*.pdf') as $f) {
    $r = $p->parseFile($f);
    $c = array_map(fn($t) => count($t['numbers']) . '/' . var_export($t['expected_count'], true) . '@' . $t['amount'], $r['tiers']);
    printf("%s draw=%s date=%s series=%s tiers=%s warn=%d\n", basename($f), $r['draw_number'], $r['draw_date'] ?? "d{$r['draw_day']}m{$r['draw_month']}y{$r['draw_year']}", $r['series_count'], implode(' ', $c), count($r['warnings']));
}
