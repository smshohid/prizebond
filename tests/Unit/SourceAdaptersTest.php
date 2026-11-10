<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\BangladeshBankAdapter;
use App\Services\HttpClient;
use App\Services\IRDAdapter;
use PHPUnit\Framework\TestCase;

/** Uses real captured responses from the official sites (tests/Fixtures/bb_listing.html, ird_result.html). */
final class SourceAdaptersTest extends TestCase
{
    protected function tearDown(): void
    {
        HttpClient::fake(null);
    }

    private function fx(string $f): string
    {
        return (string) file_get_contents(dirname(__DIR__) . '/Fixtures/' . $f);
    }

    public function testBangladeshBankListingParsing(): void
    {
        $list = (new BangladeshBankAdapter(new HttpClient(['www.bb.org.bd'])))->parseListing($this->fx('bb_listing.html'));
        $this->assertGreaterThanOrEqual(8, count($list));
        $this->assertSame(125, $list[0]['draw_number'], 'newest first');
        $this->assertSame('2026-11-01', $list[0]['nominal_date']);
        $this->assertSame('https://www.bb.org.bd/investfacility/prizebond/125thdraw.pdf', $list[0]['url']);
        $byNo = array_column($list, null, 'draw_number');
        $this->assertSame('2025-10-31', $byNo[121]['nominal_date'], 'listing nominal date (PDF itself says 2025-11-02)');
        $this->assertSame('2026-01-31', $byNo[122]['nominal_date']);
        $this->assertArrayNotHasKey(0, array_column($list, 'draw_number', 'draw_number'));
    }

    public function testListingWithoutRecognisedLinksFailsLoudly(): void
    {
        HttpClient::fake(fn() => [200, '<html><body>redesigned</body></html>', []]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('layout may have changed');
        (new BangladeshBankAdapter(new HttpClient(['www.bb.org.bd'])))->fetchLatest();
    }

    public function testFetchDocumentRejectsNonPdf(): void
    {
        HttpClient::fake(fn() => [200, '<html>not a pdf</html>', []]);
        $this->expectException(\RuntimeException::class);
        (new BangladeshBankAdapter(new HttpClient(['www.bb.org.bd'])))->fetchDocument('https://www.bb.org.bd/x.pdf');
    }

    public function testFetchDocumentComputesChecksum(): void
    {
        $pdf = (string) file_get_contents(dirname(__DIR__) . '/Fixtures/bb_125thdraw.pdf');
        HttpClient::fake(fn() => [200, $pdf, []]);
        $doc = (new BangladeshBankAdapter(new HttpClient(['www.bb.org.bd'])))->fetchDocument('https://www.bb.org.bd/investfacility/prizebond/125thdraw.pdf');
        $this->assertSame(hash('sha256', $pdf), $doc['checksum']);
        $a = new BangladeshBankAdapter(new HttpClient(['www.bb.org.bd']));
        $meta = $a->extractDrawMetadata($doc);
        $this->assertSame(125, $meta['draw_number']);
        $this->assertSame('2026-11-01', $meta['draw_date']);
    }

    public function testIrdResultParsing(): void
    {
        $r = (new IRDAdapter(new HttpClient(['prizebond.ird.gov.bd'])))->parseResults($this->fx('ird_result.html'));
        $this->assertSame(['0923283'], array_keys($r), 'only the winning number is listed (the second number lost)');
        $this->assertSame([['tier' => '1', 'amount' => 600000, 'date' => '2026-11-01']], $r['0923283']);
    }

    public function testIrdLookupPostsNumbers(): void
    {
        $seen = null;
        HttpClient::fake(function ($method, $url, $fields) use (&$seen) {
            $seen = [$method, $url, $fields];
            return [200, $this->fx('ird_result.html'), []];
        });
        $r = (new IRDAdapter(new HttpClient(['prizebond.ird.gov.bd'])))->lookup(['0923283', '1234567']);
        $this->assertSame('POST', $seen[0]);
        $this->assertSame(['from' => '0923283,1234567'], $seen[2]);
        $this->assertArrayHasKey('0923283', $r);
    }
}
