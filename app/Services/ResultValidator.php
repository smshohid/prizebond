<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;

/**
 * Validates STAGED result data before it can be verified/published.
 * Never treats an empty parse as "no winners".
 */
final class ResultValidator
{
    /**
     * @param array $staged   {draw_number, draw_date, series_count, tiers: {code: {amount, expected_count, numbers[]}}}
     * @param array $expected optional expectations from the source listing: draw_number, draw_date
     * @return array{valid:bool, errors:string[], warnings:string[], stats:array}
     */
    public function validate(array $staged, int $bondTypeId, array $expected = []): array
    {
        $errors = [];
        $warnings = $staged['warnings'] ?? [];
        $type = DB::one('SELECT * FROM bond_types WHERE id = ?', [$bondTypeId]);
        if (!$type) {
            return ['valid' => false, 'errors' => ['Unknown bond type.'], 'warnings' => [], 'stats' => []];
        }
        if (!$type['source_verified']) {
            $errors[] = 'Bond type has no verified official draw format; results cannot be imported for it.';
        }

        $dn = $staged['draw_number'] ?? null;
        if (!is_int($dn) || $dn < 1) {
            $errors[] = 'Draw number missing or invalid.';
        } elseif (isset($expected['draw_number']) && (int) $expected['draw_number'] !== $dn) {
            $errors[] = "Draw number in document ($dn) differs from source listing ({$expected['draw_number']}).";
        }

        $date = $staged['draw_date'] ?? null;
        if ($date !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $date)) {
            $errors[] = 'Draw date is not a valid YYYY-MM-DD date.';
            $date = null;
        } elseif ($date !== null) {
            [$y, $m, $d] = array_map('intval', explode('-', $date));
            if (!checkdate($m, $d, $y)) {
                $errors[] = 'Draw date is not a real calendar date.';
            }
        }
        if ($date === null) {
            $errors[] = 'Draw date missing.';
        }
        if ($date && isset($expected['draw_date']) && $expected['draw_date'] !== $date) {
            $errors[] = "Draw date in document ($date) differs from source listing ({$expected['draw_date']}).";
        }
        // Cross-check day/year digits even when the month could not be decoded.
        if (!empty($staged['draw_day']) && !empty($expected['draw_date'])) {
            $e = new \DateTimeImmutable($expected['draw_date']);
            if ((int) $e->format('j') !== (int) $staged['draw_day'] || (int) $e->format('Y') !== (int) ($staged['draw_year'] ?? 0)) {
                $errors[] = 'Day/year printed in document do not match the source listing date.';
            }
        }

        $dbTiers = DB::all('SELECT * FROM prize_tiers WHERE bond_type_id = ? AND is_active = 1 ORDER BY rank_order', [$bondTypeId]);
        $tiers = $staged['tiers'] ?? [];
        $seen = [];
        $total = 0;
        foreach ($dbTiers as $t) {
            if (!isset($tiers[$t['code']])) {
                $errors[] = "Prize category {$t['name']} is missing from the document.";
                continue;
            }
            $st = $tiers[$t['code']];
            if ((float) $st['amount'] !== (float) $t['amount']) {
                $errors[] = "Prize amount for {$t['name']} in document ({$st['amount']}) differs from configured amount ({$t['amount']}).";
            }
            $nums = $st['numbers'] ?? [];
            $cnt = count($nums);
            $total += $cnt;
            $expCount = $st['expected_count'] ?? $t['expected_count'];
            if ($cnt === 0) {
                $errors[] = "No winning numbers found for {$t['name']}.";
            }
            if ($expCount !== null && (int) $expCount !== $cnt) {
                $errors[] = "{$t['name']}: expected {$expCount} numbers but found $cnt.";
            }
            if ($t['expected_count'] !== null && $st['expected_count'] !== null && (int) $t['expected_count'] !== (int) $st['expected_count']) {
                $errors[] = "{$t['name']}: document states {$st['expected_count']} numbers but configured structure expects {$t['expected_count']}.";
            }
            foreach ($nums as $n) {
                if (!preg_match('/^\d{' . (int) $type['number_length'] . '}$/', (string) $n)) {
                    $errors[] = "Invalid winning number format '$n' in {$t['name']}.";
                }
                if (isset($seen[$n])) {
                    $errors[] = "Duplicate winning number $n (also in prize {$seen[$n]}).";
                }
                $seen[$n] = $t['code'];
            }
        }
        foreach (array_keys($tiers) as $code) {
            if (!in_array((string) $code, array_column($dbTiers, 'code'), true)) {
                $errors[] = "Document contains unknown prize category '$code'.";
            }
        }
        if ($total === 0) {
            $errors[] = 'No winning numbers parsed - an empty result is never published automatically.';
        }
        $sc = $staged['series_count'] ?? null;
        if ($sc === null) {
            $warnings[] = 'Series count not found in document.';
        } elseif ($sc < 1 || $sc > 200) {
            $errors[] = "Implausible series count ($sc).";
        }

        return [
            'valid' => !$errors,
            'errors' => array_values(array_unique($errors)),
            'warnings' => array_values(array_unique($warnings)),
            'stats' => ['total_numbers' => $total, 'tiers' => count($tiers)],
        ];
    }
}
