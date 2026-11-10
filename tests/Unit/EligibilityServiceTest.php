<?php
declare(strict_types=1);

namespace Tests\Unit;

use App\Services\EligibilityService as E;
use PHPUnit\Framework\TestCase;

final class EligibilityServiceTest extends TestCase
{
    private function svc(): E
    {
        return new E(60, 2);   // official: 60 days (BB draw doc) and 2 calendar months (IRD)
    }

    public function testUnknownPurchaseDateIsNeverRejected(): void
    {
        $r = $this->svc()->evaluate(null, '2026-11-01');
        $this->assertSame(E::UNKNOWN, $r['status']);
        $this->assertNotSame('', $r['note']);
    }

    public function testLongHeldBondIsEligible(): void
    {
        $this->assertSame(E::ELIGIBLE, $this->svc()->evaluate('2025-01-01', '2026-11-01')['status']);
    }

    public function testBoughtOnDrawDayOrDayBeforeIsIneligible(): void
    {
        $this->assertSame(E::INELIGIBLE, $this->svc()->evaluate('2026-11-01', '2026-11-01')['status']);
        $this->assertSame(E::INELIGIBLE, $this->svc()->evaluate('2026-10-31', '2026-11-01')['status']);
        $this->assertSame(E::INELIGIBLE, $this->svc()->evaluate('2026-10-15', '2026-11-01')['status']);
    }

    public function testFutureRelativeToDrawIsIneligible(): void
    {
        $this->assertSame(E::INELIGIBLE, $this->svc()->evaluate('2026-12-01', '2026-11-01')['status']);
    }

    public function testSixtyDaysIsNotTheSameAsTwoCalendarMonths(): void
    {
        // Draw 2026-04-30. 60 days before = 2026-03-01. 2 months before = 2026-02-28.
        // Bought 2026-03-01: satisfies the 60-day rule, and 2 months later is 2026-05-01 > draw -> months rule fails.
        $r = $this->svc()->evaluate('2026-03-01', '2026-04-30');
        $this->assertSame(E::UNCERTAIN, $r['status'], 'rules disagree -> uncertain, never a hard rejection');
        // Bought 2026-02-28: both rules satisfied.
        $this->assertSame(E::ELIGIBLE, $this->svc()->evaluate('2026-02-28', '2026-04-30')['status']);
        // Bought 2026-03-02: 59 days and < 2 months -> neither.
        $this->assertSame(E::INELIGIBLE, $this->svc()->evaluate('2026-03-02', '2026-04-30')['status']);
    }

    public function testExactBoundaries(): void
    {
        // Draw 2026-11-01: 60 days before = 2026-09-02 ; 2 months before = 2026-09-01
        $this->assertSame(E::ELIGIBLE, $this->svc()->evaluate('2026-09-01', '2026-11-01')['status']);
        $this->assertSame(E::UNCERTAIN, $this->svc()->evaluate('2026-09-02', '2026-11-01')['status']);
        $this->assertSame(E::INELIGIBLE, $this->svc()->evaluate('2026-09-03', '2026-11-01')['status']);
    }

    public function testAddMonthsClampsToMonthEnd(): void
    {
        $d = E::addMonths(new \DateTimeImmutable('2025-12-31'), 2);
        $this->assertSame('2026-02-28', $d->format('Y-m-d'));
        $this->assertSame('2024-02-29', E::addMonths(new \DateTimeImmutable('2023-12-31'), 2)->format('Y-m-d'));
    }

    public function testRulesAreConfigurable(): void
    {
        $daysOnly = new E(60, 0);
        $this->assertSame(E::ELIGIBLE, $daysOnly->evaluate('2026-03-01', '2026-04-30')['status']);
        $monthsOnly = new E(0, 2);
        $this->assertSame(E::INELIGIBLE, $monthsOnly->evaluate('2026-03-01', '2026-04-30')['status']);
        $none = new E(0, 0);
        $this->assertSame(E::UNKNOWN, $none->evaluate('2026-03-01', '2026-04-30')['status']);
    }
}
