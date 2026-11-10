<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Settings;

/**
 * Single home for purchase-date eligibility.
 *
 * Two published descriptions exist and are NOT identical:
 *   (A) Bangladesh Bank draw documents: bonds sold at least 60 days before the draw
 *       (sale date counted, draw date excluded)           -> purchase <= draw - 60 days
 *   (B) IRD PBRIS general information: at least 2 calendar months after the sale date
 *                                                          -> purchase + 2 months <= draw
 * Both rules are configurable (system_settings; 0 disables a rule).
 *
 * Result statuses:
 *   eligible   - satisfies every enabled rule
 *   ineligible - satisfies none of the enabled rules (definitely too recent)
 *   uncertain  - satisfies some but not all enabled rules; the official descriptions disagree for this date
 *   unknown    - no purchase date supplied; never rejected automatically
 */
final class EligibilityService
{
    public const ELIGIBLE = 'eligible';
    public const INELIGIBLE = 'ineligible';
    public const UNCERTAIN = 'uncertain';
    public const UNKNOWN = 'unknown';

    public function __construct(private ?int $minDays = null, private ?int $minMonths = null)
    {
    }

    public function minDays(): int
    {
        return $this->minDays ?? Settings::int('eligibility.min_days', 60);
    }

    public function minMonths(): int
    {
        return $this->minMonths ?? Settings::int('eligibility.min_calendar_months', 2);
    }

    /** @return array{status:string,note:string} */
    public function evaluate(?string $purchaseDate, string $drawDate): array
    {
        if ($purchaseDate === null || $purchaseDate === '') {
            return ['status' => self::UNKNOWN, 'note' => 'Purchase date not provided; eligibility must be verified manually.'];
        }
        $p = new \DateTimeImmutable($purchaseDate);
        $d = new \DateTimeImmutable($drawDate);
        $results = [];
        if ($this->minDays() > 0) {
            $results['days'] = $p <= $d->modify('-' . $this->minDays() . ' days');
        }
        if ($this->minMonths() > 0) {
            $results['months'] = self::addMonths($p, $this->minMonths()) <= $d;
        }
        if (!$results) {
            return ['status' => self::UNKNOWN, 'note' => 'No eligibility rule is enabled.'];
        }
        $pass = count(array_filter($results));
        if ($pass === count($results)) {
            return ['status' => self::ELIGIBLE, 'note' => ''];
        }
        if ($pass === 0) {
            return ['status' => self::INELIGIBLE, 'note' => 'Purchased too close to the draw date to be included.'];
        }
        return ['status' => self::UNCERTAIN, 'note' => 'Official descriptions of the minimum holding period differ for this purchase date; verify with Bangladesh Bank.'];
    }

    /** Calendar-month addition without overflow (31 Dec + 2m = last day of Feb). */
    public static function addMonths(\DateTimeImmutable $d, int $months): \DateTimeImmutable
    {
        $day = (int) $d->format('j');
        $first = $d->modify('first day of this month')->modify("+$months months");
        $last = (int) $first->format('t');
        return $first->setDate((int) $first->format('Y'), (int) $first->format('n'), min($day, $last));
    }
}
