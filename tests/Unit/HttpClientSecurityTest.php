<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\HttpClient;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** SSRF protection of the outbound fetcher. */
final class HttpClientSecurityTest extends TestCase
{
    protected function tearDown(): void
    {
        HttpClient::fake(null);
    }

    public static function forbidden(): array
    {
        return [
            'plain http' => ['http://www.bb.org.bd/x'],
            'other host' => ['https://evil.example.com/x'],
            'loopback ip' => ['https://127.0.0.1/x'],
            'cloud metadata' => ['https://169.254.169.254/latest/meta-data'],
            'file scheme' => ['file:///etc/passwd'],
            'gopher' => ['gopher://www.bb.org.bd/'],
            'credentials in url' => ['https://user:pw@www.bb.org.bd/x'],
            'odd port' => ['https://www.bb.org.bd:8443/x'],
            'host suffix trick' => ['https://www.bb.org.bd.evil.com/x'],
            'no host' => ['https:///x'],
        ];
    }

    #[DataProvider('forbidden')]
    public function testForbiddenUrlsAreRejectedBeforeAnyRequest(string $url): void
    {
        $called = false;
        HttpClient::fake(function () use (&$called) {
            $called = true;
            return [200, 'x', []];
        });
        try {
            (new HttpClient(['www.bb.org.bd']))->get($url);
            $this->fail('should have been rejected');
        } catch (\RuntimeException) {
            $this->assertFalse($called);
        }
    }

    public function testRedirectToDisallowedHostIsBlocked(): void
    {
        HttpClient::fake(fn($m, $url) => str_contains($url, 'bb.org.bd')
            ? [302, '', ['location' => 'https://evil.example.com/steal']]
            : [200, 'pwned', []]);
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('allow-list');
        (new HttpClient(['www.bb.org.bd']))->get('https://www.bb.org.bd/a');
    }

    public function testRedirectWithinAllowListIsFollowedAndLimited(): void
    {
        $n = 0;
        HttpClient::fake(function ($m, $url) use (&$n) {
            $n++;
            return $n < 3 ? [301, '', ['location' => '/next' . $n]] : [200, 'ok', []];
        });
        $res = (new HttpClient(['www.bb.org.bd']))->get('https://www.bb.org.bd/a');
        $this->assertSame('ok', $res['body']);

        HttpClient::fake(fn() => [302, '', ['location' => '/loop']]);
        $this->expectExceptionMessage('Too many redirects');
        (new HttpClient(['www.bb.org.bd']))->get('https://www.bb.org.bd/a');
    }
}
