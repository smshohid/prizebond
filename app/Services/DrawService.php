<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\DB;

/** Transaction-safe publication and versioned correction of draws. */
final class DrawService
{
    public function __construct(private ?ResultValidator $validator = null)
    {
        $this->validator ??= new ResultValidator();
    }

    /** Publish a verified import. Never overwrites an existing draw. @return int draw id */
    public function publish(int $importId, ?int $adminId, string $ip = '', bool $system = false): int
    {
        return (int) DB::tx(function () use ($importId, $adminId, $ip, $system) {
            $imp = DB::one('SELECT * FROM result_imports WHERE id = ? FOR UPDATE', [$importId]);
            if (!$imp || $imp['status'] !== 'verified') {
                throw new \RuntimeException('Only verified imports can be published.');
            }
            $staged = json_decode((string) $imp['staged_data'], true);
            if (!$staged) {
                throw new \RuntimeException('Import has no staged data.');
            }
            $report = $this->validator->validate($staged, (int) $imp['bond_type_id'], array_filter(['draw_number' => $imp['draw_number']]));
            if (!$report['valid']) {
                throw new \RuntimeException('Staged data failed final validation: ' . implode('; ', $report['errors']));
            }
            $exists = DB::one('SELECT id, publication_status FROM prize_draws WHERE bond_type_id = ? AND draw_number = ? FOR UPDATE', [$imp['bond_type_id'], $staged['draw_number']]);
            if ($exists) {
                throw new \RuntimeException('Draw ' . $staged['draw_number'] . ' already exists. Published results are never overwritten - use the correction workflow.');
            }
            $now = DB::now();
            $drawId = DB::insert('prize_draws', [
                'bond_type_id' => $imp['bond_type_id'], 'draw_number' => $staged['draw_number'], 'draw_date' => $staged['draw_date'],
                'series_count' => $staged['series_count'] ?? null, 'source_id' => $imp['source_id'], 'import_status' => 'imported',
                'verification_status' => 'verified', 'publication_status' => 'published', 'lifecycle' => 'published',
                'source_url' => $imp['source_url'], 'source_file' => $imp['file_path'], 'source_checksum' => $imp['file_checksum'],
                'version' => 1, 'created_at' => $now, 'published_at' => $now, 'updated_at' => $now,
            ]);
            $this->insertNumbers($drawId, (int) $imp['bond_type_id'], $staged);
            DB::update('result_imports', ['status' => 'published', 'draw_id' => $drawId, 'updated_at' => $now], 'id = ?', [$importId]);
            Audit::log($adminId, 'draw.publish', 'prize_draw', $drawId, null, ['draw_number' => $staged['draw_number'], 'import_id' => $importId, 'numbers' => $report['stats']['total_numbers']], $ip, $system ? 'system' : 'admin');
            (new ImportService())->log($importId, 'info', "Published as draw #$drawId.");
            PublicCache::flush();
            return $drawId;
        });
    }

    private function insertNumbers(int $drawId, int $typeId, array $staged): void
    {
        $tiers = DB::all('SELECT id, code, amount FROM prize_tiers WHERE bond_type_id = ?', [$typeId]);
        $byCode = array_column($tiers, null, 'code');
        foreach ($staged['tiers'] as $code => $t) {
            $tier = $byCode[(string) $code] ?? null;
            if (!$tier) {
                throw new \RuntimeException("Unknown prize category $code");
            }
            foreach ($t['numbers'] as $n) {
                DB::exec('INSERT INTO winning_numbers (draw_id, prize_tier_id, number, series, amount, status, created_at) VALUES (?,?,?,?,?,?,?)',
                    [$drawId, $tier['id'], (string) $n, '', $tier['amount'], 'active', DB::now()]);
            }
        }
    }

    public function snapshot(int $drawId): array
    {
        $rows = DB::all("SELECT pt.code, wn.number FROM winning_numbers wn JOIN prize_tiers pt ON pt.id = wn.prize_tier_id
                         WHERE wn.draw_id = ? AND wn.status = 'active' ORDER BY pt.rank_order, wn.number", [$drawId]);
        $out = [];
        foreach ($rows as $r) {
            $out[$r['code']][] = $r['number'];
        }
        $d = DB::one('SELECT draw_number, draw_date, series_count, version FROM prize_draws WHERE id = ?', [$drawId]);
        return ['draw' => $d, 'tiers' => $out];
    }

    /**
     * Versioned correction of a PUBLISHED draw. Old numbers that disappear are voided (not deleted),
     * their matches are voided, and members who were already emailed about a now-void match get a retraction.
     * @param array<string,string[]> $tierNumbers code => numbers
     */
    public function correct(int $drawId, array $tierNumbers, string $reason, int $adminId, string $ip = ''): array
    {
        if (trim($reason) === '') {
            throw new \RuntimeException('A reason is required for a correction.');
        }
        return DB::tx(function () use ($drawId, $tierNumbers, $reason, $adminId, $ip) {
            $draw = DB::one('SELECT * FROM prize_draws WHERE id = ? FOR UPDATE', [$drawId]);
            if (!$draw || $draw['publication_status'] !== 'published') {
                throw new \RuntimeException('Only published draws can be corrected.');
            }
            $before = $this->snapshot($drawId);
            $tiers = DB::all('SELECT id, code, amount FROM prize_tiers WHERE bond_type_id = ? AND is_active = 1', [$draw['bond_type_id']]);
            $staged = ['draw_number' => (int) $draw['draw_number'], 'draw_date' => $draw['draw_date'], 'series_count' => $draw['series_count'], 'tiers' => []];
            foreach ($tiers as $t) {
                $raw = $tierNumbers[$t['code']] ?? [];
                $nums = is_array($raw) ? $raw : (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
                $staged['tiers'][$t['code']] = ['amount' => (int) $t['amount'], 'expected_count' => null, 'numbers' => array_map(fn($n) => \App\Helpers\BondNumber::asciiDigits(trim((string) $n)), $nums)];
            }
            $report = $this->validator->validate($staged, (int) $draw['bond_type_id']);
            if (!$report['valid']) {
                throw new \RuntimeException('Corrected data is invalid: ' . implode('; ', $report['errors']));
            }
            $newVersion = (int) $draw['version'] + 1;
            $now = DB::now();
            // Index existing rows
            $existing = DB::all('SELECT wn.id, wn.number, wn.status, pt.code FROM winning_numbers wn JOIN prize_tiers pt ON pt.id = wn.prize_tier_id WHERE wn.draw_id = ?', [$drawId]);
            $keep = [];
            foreach ($staged['tiers'] as $code => $t) {
                foreach ($t['numbers'] as $n) {
                    $keep[$code . '|' . $n] = true;
                }
            }
            $voided = 0;
            $retract = [];
            foreach ($existing as $w) {
                $k = $w['code'] . '|' . $w['number'];
                if (isset($keep[$k])) {
                    if ($w['status'] === 'void') {
                        DB::exec("UPDATE winning_numbers SET status = 'active' WHERE id = ?", [$w['id']]);
                    }
                    unset($keep[$k]);
                } elseif ($w['status'] === 'active') {
                    DB::exec("UPDATE winning_numbers SET status = 'void' WHERE id = ?", [$w['id']]);
                    foreach (DB::all("SELECT pm.id, pm.notification_status, b.user_id FROM prize_matches pm JOIN prize_bonds b ON b.id = pm.prize_bond_id WHERE pm.winning_number_id = ? AND pm.match_status = 'detected'", [$w['id']]) as $m) {
                        if ($m['notification_status'] === 'sent') {
                            $retract[(int) $m['user_id']] = true;
                        }
                        DB::exec("UPDATE prize_matches SET match_status = 'void', notification_status = 'not_required', updated_at = ? WHERE id = ?", [$now, $m['id']]);
                    }
                    $voided++;
                }
            }
            $byCode = array_column($tiers, null, 'code');
            $added = 0;
            foreach (array_keys($keep) as $k) {
                [$code, $n] = explode('|', $k, 2);
                DB::exec('INSERT INTO winning_numbers (draw_id, prize_tier_id, number, series, amount, status, created_at) VALUES (?,?,?,?,?,?,?)',
                    [$drawId, $byCode[$code]['id'], $n, '', $byCode[$code]['amount'], 'active', $now]);
                $added++;
            }
            $after = $this->snapshot($drawId);
            $after['draw']['version'] = $newVersion;
            DB::insert('draw_corrections', ['draw_id' => $drawId, 'version' => $newVersion, 'reason' => mb_substr($reason, 0, 500), 'previous_snapshot' => json_encode($before), 'new_snapshot' => json_encode($after), 'admin_id' => $adminId, 'created_at' => $now]);
            // Back to 'published' so the matching job re-runs (idempotent) and queues alerts for any NEW matches only.
            DB::update('prize_draws', ['version' => $newVersion, 'lifecycle' => 'published', 'updated_at' => $now], 'id = ?', [$drawId]);
            Audit::log($adminId, 'draw.correct', 'prize_draw', $drawId, $before, $after + ['reason' => $reason], $ip);

            $notify = new NotificationService();
            foreach (array_keys($retract) as $uid) {
                $u = DB::one("SELECT u.email, u.full_name, COALESCE(s.language,'en') AS language FROM users u LEFT JOIN user_settings s ON s.user_id = u.id WHERE u.id = ?", [$uid]);
                if ($u) {
                    $notify->enqueue('match_retracted', $u['email'], 'Correction to draw ' . $draw['draw_number'] . ' result', 'retraction', ['lang' => $u['language'], 'name' => $u['full_name'], 'draw_number' => $draw['draw_number'], 'reason' => $reason], "retract:$uid:$drawId:v$newVersion", $uid, $drawId);
                }
            }
            PublicCache::flush();
            return ['version' => $newVersion, 'added' => $added, 'voided' => $voided, 'retractions' => count($retract)];
        });
    }
}

/** Tiny file cache for public historical pages; flushed on publish/correction. */
final class PublicCache
{
    public static function remember(string $key, int $ttl, callable $fn): mixed
    {
        $dir = base_path('storage/cache');
        $f = $dir . '/' . md5($key) . '.cache';
        if (is_file($f) && filemtime($f) > time() - $ttl) {
            $v = @unserialize((string) file_get_contents($f), ['allowed_classes' => false]);
            if ($v !== false) {
                return $v;
            }
        }
        $v = $fn();
        if (is_dir($dir) && is_writable($dir)) {
            @file_put_contents($f, serialize($v), LOCK_EX);
        }
        return $v;
    }

    public static function flush(): void
    {
        foreach (glob(base_path('storage/cache') . '/*.cache') ?: [] as $f) {
            @unlink($f);
        }
    }
}
