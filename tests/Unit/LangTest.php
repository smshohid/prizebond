<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Core\Lang;
use App\Core\Session;
use PHPUnit\Framework\TestCase;

final class LangTest extends TestCase
{
    protected function setUp(): void
    {
        Session::reset();
        Lang::$override = null;
    }

    public function testDefaultIsEnglish(): void
    {
        $this->assertSame('en', Lang::locale());
        $this->assertSame('Check a Bond', t('nav.check'));
    }

    public function testSwitchToBanglaAndBack(): void
    {
        Lang::set('bn');
        $this->assertSame('bn', Lang::locale());
        $this->assertSame('বন্ড যাচাই', t('nav.check'));
        Lang::set('en');
        $this->assertSame('Check a Bond', t('nav.check'));
    }

    public function testInvalidLocaleIsIgnored(): void
    {
        Lang::set('fr');
        $this->assertSame('en', Lang::locale());
        Session::put('lang', '../../etc/passwd');
        $this->assertSame('en', Lang::locale());
    }

    public function testMissingBanglaKeyFallsBackToEnglishAndUnknownKeyToKey(): void
    {
        Lang::set('bn');
        // meta.about exists only in English -> falls back to English text rather than showing a raw key
        $this->assertSame('About Prize Bond Monitor BD.', t('meta.about'));
        $this->assertSame('totally.unknown', t('totally.unknown'));
    }

    public function testReplacementsAndOverride(): void
    {
        $this->assertSame('Draw 125 results', t('draw.title', ['n' => 125]));
        Lang::$override = 'bn';
        $this->assertSame('১২৫ তম ড্র-এর ফলাফল', t('draw.title', ['n' => '১২৫']));
        Lang::$override = null;
        $this->assertSame('en', Lang::locale());
    }

    public function testBothLanguageFilesHaveTheSameKeys(): void
    {
        $en = array_keys(Lang::load('en'));
        $bn = array_keys(Lang::load('bn'));
        $missingInBn = array_diff($en, $bn);
        $unknownInBn = array_diff($bn, $en);
        $this->assertSame([], array_values($unknownInBn), 'Bangla file has keys English lacks');
        // A handful of long legal/meta strings may intentionally fall back; everything user-facing must be translated.
        $core = array_filter($missingInBn, fn($k) => !str_starts_with($k, 'meta.'));
        $this->assertSame([], array_values($core), 'untranslated keys: ' . implode(', ', $core));
    }
}
