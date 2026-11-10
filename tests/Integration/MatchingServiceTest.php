<?php
declare(strict_types=1);

namespace Tests\Integration;

use App\Core\DB;
use App\Services\BondService;
use App\Services\MatchingService;
use Tests\TestCase;

final class MatchingServiceTest extends TestCase
{
    private function match(int $drawId): array
    {
        return (new MatchingService())->matchDraw($drawId);
    }

    private function rows(?int $drawId = null): array
    {
        return DB::all("SELECT pm.*, b.number, b.series, b.user_id FROM prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id" . ($drawId ? ' WHERE pm.draw_id = ' . $drawId : '') . ' ORDER BY pm.id');
    }

    public function testCorrectNumberMatchesWithPrizeSnapshot(): void
    {
        $u = $this->createUser();
        $this->addBond($u, '0923283', 'KK');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283'], '5' => ['0021355']]);
        $r = $this->match($d);
        $this->assertSame(1, $r['new']);
        $m = $this->rows($d);
        $this->assertCount(1, $m);
        $this->assertEquals(600000, $m[0]['amount_snapshot']);
        $this->assertSame('detected', $m[0]['match_status']);
    }

    public function testIncorrectNumberDoesNotMatch(): void
    {
        $this->addBond($this->createUser(), '0923284');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $this->assertSame(0, $this->match($d)['total']);
    }

    public function testLeadingZerosAreSignificant(): void
    {
        $u = $this->createUser();
        $a = $this->addBond($u, '0000123');
        $this->addBond($u, '1230000');
        $d = $this->publishDraw(125, '2026-11-01', ['5' => ['0000123']]);
        $this->match($d);
        $m = $this->rows($d);
        $this->assertCount(1, $m);
        $this->assertSame($a, (int) $m[0]['prize_bond_id']);
        $this->assertSame('0000123', $m[0]['number']);
    }

    public function testAllSeriesMatchBecauseSeriesDoesNotAffectTk100Draw(): void
    {
        $u = $this->createUser();
        $this->addBond($u, '0923283', 'KK');
        $this->addBond($u, '0923283', 'LB');
        $this->addBond($u, '0923283', '');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $this->match($d);
        $this->assertCount(3, $this->rows($d));
    }

    public function testSeriesIsEnforcedOnlyWhenBondTypeSaysSo(): void
    {
        DB::exec("UPDATE bond_types SET series_affects_matching = 1 WHERE code = 'BDT100'");
        $u = $this->createUser();
        $kk = $this->addBond($u, '0923283', 'KK');
        $this->addBond($u, '0923283', 'LB');
        $d = $this->publishDraw(125, '2026-11-01', []);
        $tier = DB::val("SELECT id FROM prize_tiers WHERE code = '1'");
        DB::insert('winning_numbers', ['draw_id' => $d, 'prize_tier_id' => $tier, 'number' => '0923283', 'series' => 'KK', 'amount' => 600000, 'status' => 'active', 'created_at' => DB::now()]);
        $this->match($d);
        $m = $this->rows($d);
        $this->assertCount(1, $m);
        $this->assertSame($kk, (int) $m[0]['prize_bond_id']);
    }

    public function testMultipleUsersWithSameBondAllMatch(): void
    {
        $a = $this->createUser('a@example.com');
        $b = $this->createUser('b@example.com');
        $this->addBond($a, '0923283');
        $this->addBond($b, '0923283');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $this->match($d);
        $this->assertSame([$a, $b], array_map(fn($r) => (int) $r['user_id'], $this->rows($d)));
    }

    public function testDuplicateRegistrationBySameUserIsRejected(): void
    {
        $u = $this->createUser();
        $svc = new BondService();
        $this->assertTrue($svc->add($u, $this->typeId(), 'KK', '0923283', null)['ok']);
        $dup = $svc->add($u, $this->typeId(), 'kk', '0923283', null);
        $this->assertFalse($dup['ok']);
        $this->assertSame('bond.err_duplicate', $dup['errors']['number']);
        $this->assertSame(1, (int) DB::val('SELECT COUNT(*) FROM prize_bonds'));
        // different user may register the same bond
        $v = $this->createUser('other@example.com');
        $this->assertTrue($svc->add($v, $this->typeId(), 'KK', '0923283', null)['ok']);
    }

    public function testNumberAppearingInSeveralPrizeTiersCreatesSeveralMatches(): void
    {
        $this->addBond($this->createUser(), '0555555');
        $d = $this->publishDraw(125, '2026-11-01', ['3' => ['0555555'], '5' => ['0555555']]);
        $this->match($d);
        $m = $this->rows($d);
        $this->assertCount(2, $m, 'schema supports more than one match per bond per draw');
        $this->assertEqualsCanonicalizing([100000.0, 10000.0], array_map(fn($x) => (float) $x['amount_snapshot'], $m));
    }

    public function testIneligiblePurchaseDateIsRecordedButNotNotified(): void
    {
        $u = $this->createUser();
        $this->addBond($u, '0923283', '', '2026-10-30');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $this->match($d);
        $m = $this->rows($d)[0];
        $this->assertSame('ineligible', $m['eligibility_status']);
        $q = (new \App\Services\NotificationService())->queueWinningNotifications($d);
        $this->assertSame(0, $q['queued']);
        $this->assertSame('not_required', $this->rows($d)[0]['notification_status']);
    }

    public function testUnknownPurchaseDateIsAPossibleMatchAndNotified(): void
    {
        $this->addBond($this->createUser(), '0923283');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $this->match($d);
        $this->assertSame('unknown', $this->rows($d)[0]['eligibility_status']);
        $this->assertSame(1, (new \App\Services\NotificationService())->queueWinningNotifications($d)['queued']);
    }

    public function testWrongBondTypeNeverMatches(): void
    {
        $now = DB::now();
        $other = DB::insert('bond_types', ['code' => 'OTHER500', 'name' => 'Other (disabled)', 'denomination' => 500, 'number_length' => 7, 'is_enabled' => 0, 'created_at' => $now, 'updated_at' => $now]);
        $u = $this->createUser();
        DB::insert('prize_bonds', ['user_id' => $u, 'bond_type_id' => $other, 'series' => '', 'number' => '0923283', 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $this->assertSame(0, $this->match($d)['total']);
    }

    public function testInactiveBondsAreIgnored(): void
    {
        $this->addBond($this->createUser(), '0923283', '', null, 'inactive');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $this->assertSame(0, $this->match($d)['total']);
    }

    public function testMatchingIsIdempotent(): void
    {
        $u = $this->createUser();
        $this->addBond($u, '0923283');
        $this->addBond($u, '0021355');
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283'], '5' => ['0021355']]);
        $first = $this->match($d);
        $second = $this->match($d);
        $third = $this->match($d);
        $this->assertSame(2, $first['new']);
        $this->assertSame(0, $second['new']);
        $this->assertSame(0, $third['new']);
        $this->assertCount(2, $this->rows($d));
    }

    public function testBatchingGivesSameResultAsSingleBatch(): void
    {
        \App\Core\Settings::set('matching.batch_size', '3');
        $u = $this->createUser();
        $numbers = [];
        for ($i = 0; $i < 40; $i++) {
            $numbers[] = str_pad((string) ($i * 1111 + 5), 7, '0', STR_PAD_LEFT);
            $this->addBond($u, $numbers[$i]);
        }
        $d = $this->publishDraw(125, '2026-11-01', ['5' => $numbers]);
        $this->assertSame(40, $this->match($d)['total']);
    }

    public function testRefusesUnpublishedOrUnverifiedDraw(): void
    {
        $d = $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        DB::exec("UPDATE prize_draws SET publication_status = 'unpublished' WHERE id = ?", [$d]);
        $this->expectException(\RuntimeException::class);
        $this->match($d);
    }

    public function testNewBondIsCheckedAgainstHistoryWithoutEmail(): void
    {
        $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $u = $this->createUser();
        $r = (new BondService())->add($u, $this->typeId(), 'KK', '0923283', null);
        $this->assertSame(1, $r['matches']);
        $m = $this->rows()[0];
        $this->assertSame('not_required', $m['notification_status'], 'historical matches are shown but not emailed');
    }

    public function testPublicCheckReturnsOnlyPublicData(): void
    {
        $this->publishDraw(125, '2026-11-01', ['1' => ['0923283']]);
        $rows = (new MatchingService())->checkNumber($this->typeId(), '0923283');
        $this->assertCount(1, $rows);
        $this->assertSame(['draw_id', 'draw_number', 'draw_date', 'tier_name', 'tier_name_bn', 'amount', 'series'], array_keys($rows[0]));
    }
}
