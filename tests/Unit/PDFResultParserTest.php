<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\PDFResultParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Parser tests run against REAL official Bangladesh Bank result PDFs (draws 118-125), downloaded from
 * https://www.bb.org.bd/investfacility/prizebond/ and stored unmodified in tests/Fixtures.
 */
final class PDFResultParserTest extends TestCase
{
    public static function draws(): array
    {
        // file, draw no, ISO date as printed in the document, series count (null = not parseable)
        return [
            ['bb_115thdraw.pdf', 115, '2024-04-30', null],   // older layout: prize heading wraps onto two lines
            ['bb_116thdraw.pdf', 116, '2024-07-31', 80],
            ['bb_117thdraw.pdf', 117, '2024-10-31', null],
            ['bb_118thdraw.pdf', 118, '2025-02-02', 81],
            ['bb_119thdraw.pdf', 119, '2025-04-30', null],
            ['bb_120thdraw.pdf', 120, '2025-07-31', 82],
            ['bb_121stdraw.pdf', 121, '2025-11-02', 83],
            ['bb_122nddraw.pdf', 122, '2026-02-01', 84],
            ['bb_123rddraw.pdf', 123, '2026-04-30', 84],
            ['bb_124thdraw.pdf', 124, '2026-08-02', 85],
            ['bb_125thdraw.pdf', 125, '2026-11-01', 86],
        ];
    }

    #[DataProvider('draws')]
    public function testRealOfficialDocumentsParse(string $file, int $no, string $date, ?int $series): void
    {
        $r = (new PDFResultParser())->parseFile(dirname(__DIR__) . '/Fixtures/' . $file);
        $this->assertSame($no, $r['draw_number']);
        $this->assertSame($date, $r['draw_date']);
        $this->assertSame($series, $r['series_count']);
        $this->assertSame(['1', '2', '3', '4', '5'], array_map('strval', array_keys($r['tiers'])));
        $this->assertSame([600000, 325000, 100000, 50000, 10000], array_column($r['tiers'], 'amount'));
        $this->assertSame([1, 1, 2, 2, 40], array_values(array_map(fn($t) => count($t['numbers']), $r['tiers'])));
        $all = array_merge(...array_column($r['tiers'], 'numbers'));
        $this->assertCount(46, $all);
        $this->assertCount(46, array_unique($all), 'no duplicate winning numbers');
        foreach ($all as $n) {
            $this->assertMatchesRegularExpression('/^\d{7}$/', $n);
        }
        // the "0000001 to 10,00,000" range statement must NOT be mistaken for a winning number
        $this->assertNotContains('0000001', $all);
    }

    public function testDraw125KnownWinners(): void
    {
        $r = (new PDFResultParser())->parseFile(dirname(__DIR__) . '/Fixtures/bb_125thdraw.pdf');
        $this->assertSame(['0923283'], $r['tiers']['1']['numbers']);
        $this->assertSame(['0842511'], $r['tiers']['2']['numbers']);
        $this->assertSame(['0873611', '0891956'], $r['tiers']['3']['numbers']);
        $this->assertSame(['0458658', '0924695'], $r['tiers']['4']['numbers']);
        $this->assertContains('0021355', $r['tiers']['5']['numbers'], 'leading zeros preserved');
        $this->assertContains('0904795', $r['tiers']['5']['numbers']);
    }

    public function testLeadingZerosNeverStrippedInAnyDraw(): void
    {
        foreach (self::draws() as [$file]) {
            $r = (new PDFResultParser())->parseFile(dirname(__DIR__) . '/Fixtures/' . $file);
            foreach ($r['tiers'] as $t) {
                foreach ($t['numbers'] as $n) {
                    $this->assertSame(7, strlen($n), "$file: $n");
                }
            }
        }
    }

    public function testGarbageTextYieldsNoTiersAndWarning(): void
    {
        $r = (new PDFResultParser())->parseText("Lorem ipsum\nnothing here 12345\n");
        $this->assertSame([], $r['tiers']);
        $this->assertNotEmpty($r['warnings']);
        $this->assertNull($r['draw_number']);
    }

    public function testEmptyTextIsNotInterpretedAsNoWinners(): void
    {
        $r = (new PDFResultParser())->parseText('');
        $this->assertSame([], $r['tiers']);
        $this->assertNotEmpty($r['warnings']);
    }
}
