<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Settings;

/**
 * Matching rule (derived from the official result structure, Bangladesh Bank draw documents + IRD PBRIS):
 *   The Tk.100 draw is a "single common-number system": every published 7-digit general number wins in
 *   ALL series of that draw. Therefore a registered bond matches when
 *       bond.bond_type == draw.bond_type  AND  bond.number == winning.number   (string compare, zero-padded)
 *   and, only for bond types flagged series_affects_matching, also bond.series == winning.series.
 * Eligibility (purchase date) is evaluated by EligibilityService and stored on the match; ineligible
 * matches are recorded but never notified. Matching is idempotent (unique bond+winning_number).
 */
final class MatchingService
{
    public function __construct(private ?EligibilityService $elig = null)
    {
        $this->elig ??= new EligibilityService();
    }

    /** @return array{winning_numbers:int,new:int,updated:int,total:int,duration_ms:int} */
    public function matchDraw(int $drawId): array
    {
        $t0 = microtime(true);
        $draw = DB::one('SELECT d.*, bt.series_affects_matching FROM prize_draws d JOIN bond_types bt ON bt.id = d.bond_type_id WHERE d.id = ?', [$drawId]);
        if (!$draw) {
            throw new \RuntimeException('Draw not found.');
        }
        if ($draw['publication_status'] !== 'published' || $draw['verification_status'] !== 'verified') {
            throw new \RuntimeException('Matching only runs against verified, published draws.');
        }
        $win = DB::all("SELECT id, number, series, amount FROM winning_numbers WHERE draw_id = ? AND status = 'active' ORDER BY id", [$drawId]);
        $batch = max(1, Settings::int('matching.batch_size', 500));
        $new = $upd = 0;
        $seriesRule = (bool) $draw['series_affects_matching'];

        foreach (array_chunk($win, $batch) as $chunk) {
            DB::tx(function () use ($chunk, $draw, $seriesRule, &$new, &$upd) {
                $byNumber = [];
                foreach ($chunk as $w) {
                    $byNumber[$w['number']][] = $w;
                }
                $numbers = array_keys($byNumber);
                $bonds = DB::all('SELECT id, user_id, series, number, purchase_date FROM prize_bonds
                                  WHERE bond_type_id = ? AND status = ? AND number IN (' . DB::placeholders(count($numbers)) . ')',
                    [$draw['bond_type_id'], 'active', ...array_map('strval', $numbers)]);
                foreach ($bonds as $b) {
                    foreach ($byNumber[$b['number']] ?? [] as $w) {
                        if ($seriesRule && $w['series'] !== '' && $w['series'] !== $b['series']) {
                            continue;
                        }
                        $e = $this->elig->evaluate($b['purchase_date'], $draw['draw_date']);
                        $now = DB::now();
                        // rowCount: 1 = inserted, 2 = existing row changed, 0 = unchanged
                        $rc = DB::exec("INSERT INTO prize_matches (prize_bond_id, draw_id, winning_number_id, amount_snapshot, eligibility_status, eligibility_note, match_status, notification_status, created_at, updated_at)
                            VALUES (?,?,?,?,?,?, 'detected', 'pending', ?, ?)
                            ON DUPLICATE KEY UPDATE eligibility_status = VALUES(eligibility_status), eligibility_note = VALUES(eligibility_note),
                              match_status = 'detected'",
                            [$b['id'], $draw['id'], $w['id'], $w['amount'], $e['status'], $e['note'] ?: null, $now, $now]);
                        $rc === 1 ? $new++ : ($rc === 2 ? $upd++ : null);
                    }
                }
            });
        }
        $total = (int) DB::val("SELECT COUNT(*) FROM prize_matches WHERE draw_id = ? AND match_status = 'detected'", [$drawId]);
        DB::exec("UPDATE prize_draws SET matched_at = ?, lifecycle = IF(lifecycle IN ('published','staged'), 'matched', lifecycle), updated_at = ? WHERE id = ?", [DB::now(), DB::now(), $drawId]);
        return ['winning_numbers' => count($win), 'new' => $new, 'updated' => $upd, 'total' => $total, 'duration_ms' => (int) ((microtime(true) - $t0) * 1000)];
    }

    /**
     * Check one newly-registered/edited bond against already-published draws. Historical matches are
     * visible to the user but do NOT trigger an email (notification_status = not_required).
     * @return int number of historical matches found
     */
    public function matchBondHistorically(int $bondId): int
    {
        $b = DB::one('SELECT b.*, bt.series_affects_matching FROM prize_bonds b JOIN bond_types bt ON bt.id = b.bond_type_id WHERE b.id = ?', [$bondId]);
        if (!$b || $b['status'] !== 'active') {
            return 0;
        }
        $rows = DB::all("SELECT wn.id AS wn_id, wn.amount, wn.series, d.id AS draw_id, d.draw_date
            FROM winning_numbers wn JOIN prize_draws d ON d.id = wn.draw_id
            WHERE d.bond_type_id = ? AND wn.number = ? AND wn.status = 'active' AND d.publication_status = 'published' AND d.verification_status = 'verified'",
            [$b['bond_type_id'], $b['number']]);
        $n = 0;
        foreach ($rows as $r) {
            if ($b['series_affects_matching'] && $r['series'] !== '' && $r['series'] !== $b['series']) {
                continue;
            }
            $e = $this->elig->evaluate($b['purchase_date'], $r['draw_date']);
            $now = DB::now();
            DB::exec("INSERT INTO prize_matches (prize_bond_id, draw_id, winning_number_id, amount_snapshot, eligibility_status, eligibility_note, match_status, notification_status, created_at, updated_at)
                      VALUES (?,?,?,?,?,?, 'detected', 'not_required', ?, ?)
                      ON DUPLICATE KEY UPDATE eligibility_status = VALUES(eligibility_status), eligibility_note = VALUES(eligibility_note)",
                [$b['id'], $r['draw_id'], $r['wn_id'], $r['amount'], $e['status'], $e['note'] ?: null, $now, $now]);
            $n++;
        }
        return $n;
    }

    /** Public, anonymous number check - returns only public draw data, never user data. */
    public function checkNumber(int $bondTypeId, string $number, ?string $series = null): array
    {
        return DB::all("SELECT d.id AS draw_id, d.draw_number, d.draw_date, pt.name AS tier_name, pt.name_bn AS tier_name_bn, wn.amount, wn.series
            FROM winning_numbers wn JOIN prize_draws d ON d.id = wn.draw_id JOIN prize_tiers pt ON pt.id = wn.prize_tier_id
            WHERE d.bond_type_id = ? AND wn.number = ? AND wn.status = 'active' AND d.publication_status = 'published' AND d.verification_status = 'verified'
            ORDER BY d.draw_date DESC", [$bondTypeId, $number]);
    }
}
