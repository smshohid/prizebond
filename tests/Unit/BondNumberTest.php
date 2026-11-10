<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Helpers\BondNumber;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BondNumberTest extends TestCase
{
    public function testLeadingZerosArePreservedAsString(): void
    {
        [$n, $err] = BondNumber::number('0046900');
        $this->assertNull($err);
        $this->assertSame('0046900', $n);
        $this->assertIsString($n);
    }

    public function testBanglaDigitsAreConverted(): void
    {
        [$n] = BondNumber::number('০৯২৩২৮৩');
        $this->assertSame('0923283', $n);
    }

    public function testWhitespaceIsStripped(): void
    {
        [$n] = BondNumber::number(" 092 3283\u{200B}");
        $this->assertSame('0923283', $n);
    }

    #[DataProvider('badNumbers')]
    public function testInvalidNumbersAreRejected(string $raw, string $errKey): void
    {
        [$n, $err] = BondNumber::number($raw);
        $this->assertNull($n);
        $this->assertSame($errKey, $err);
    }

    public static function badNumbers(): array
    {
        return [
            'empty' => ['', 'bond.err_number_required'],
            'letters' => ['12AB567', 'bond.err_number_digits'],
            'too short (zeros dropped)' => ['46900', 'bond.err_number_length'],
            'too long' => ['00469001', 'bond.err_number_length'],
            'sql' => ["1' OR '1'='1", 'bond.err_number_digits'],
            'negative' => ['-123456', 'bond.err_number_digits'],
        ];
    }

    public function testSeriesNormalisation(): void
    {
        $this->assertSame(['KK', null], BondNumber::series(' k k '));
        $this->assertSame(['কক', null], BondNumber::series('কক'));
        $this->assertSame(['', null], BondNumber::series(''));
        $this->assertSame([null, 'bond.err_series_required'], BondNumber::series('', true));
        $this->assertSame([null, 'bond.err_series_invalid'], BondNumber::series('K<script>'));
        $this->assertSame([null, 'bond.err_series_invalid'], BondNumber::series('123'));
    }

    public function testDateParsing(): void
    {
        $this->assertSame(['2025-01-15', null], BondNumber::date('2025-01-15'));
        $this->assertSame(['2025-01-15', null], BondNumber::date('15/01/2025'));
        $this->assertSame([null, null], BondNumber::date(''));
        $this->assertSame([null, 'bond.err_date_invalid'], BondNumber::date('2025-02-30'));
        $this->assertSame([null, 'bond.err_date_invalid'], BondNumber::date('not a date'));
        $this->assertSame([null, 'bond.err_date_future'], BondNumber::date('2999-01-01'));
    }
}
