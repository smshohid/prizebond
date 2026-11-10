<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Helpers\BondNumber;

final class BondService
{
    public const MAX_USER_BONDS = 5000;
    public const MAX_IMPORT_ROWS = 2000;

    public function __construct(private ?MatchingService $matcher = null)
    {
        $this->matcher ??= new MatchingService();
    }

    public function enabledType(int $typeId): ?array
    {
        return DB::one('SELECT * FROM bond_types WHERE id = ? AND is_enabled = 1', [$typeId]);
    }

    /** @return array{ok:bool,errors:array<string,string>,id?:int,matches?:int} */
    public function add(int $userId, int $typeId, string $series, string $number, ?string $purchaseDate): array
    {
        $v = $this->validate($typeId, $series, $number, $purchaseDate);
        if ($v['errors']) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        if ((int) DB::val('SELECT COUNT(*) FROM prize_bonds WHERE user_id = ?', [$userId]) >= self::MAX_USER_BONDS) {
            return ['ok' => false, 'errors' => ['_' => 'bond.err_limit']];
        }
        try {
            $now = DB::now();
            $id = DB::insert('prize_bonds', ['user_id' => $userId, 'bond_type_id' => $typeId, 'series' => $v['series'], 'number' => $v['number'],
                'purchase_date' => $v['date'], 'status' => 'active', 'created_at' => $now, 'updated_at' => $now]);
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'errors' => ['number' => 'bond.err_duplicate']];
            }
            throw $e;
        }
        return ['ok' => true, 'errors' => [], 'id' => $id, 'matches' => $this->matcher->matchBondHistorically($id)];
    }

    /** Update a bond owned by $userId (ownership enforced in SQL). */
    public function update(int $userId, int $bondId, string $series, string $number, ?string $purchaseDate): array
    {
        $b = DB::one('SELECT * FROM prize_bonds WHERE id = ? AND user_id = ?', [$bondId, $userId]);
        if (!$b) {
            return ['ok' => false, 'errors' => ['_' => 'common.not_found']];
        }
        $v = $this->validate((int) $b['bond_type_id'], $series, $number, $purchaseDate);
        if ($v['errors']) {
            return ['ok' => false, 'errors' => $v['errors']];
        }
        try {
            DB::tx(function () use ($userId, $bondId, $v) {
                DB::update('prize_bonds', ['series' => $v['series'], 'number' => $v['number'], 'purchase_date' => $v['date'], 'updated_at' => DB::now()], 'id = ? AND user_id = ?', [$bondId, $userId]);
                // Number changed => old matches no longer apply
                DB::exec("DELETE pm FROM prize_matches pm JOIN winning_numbers wn ON wn.id = pm.winning_number_id WHERE pm.prize_bond_id = ? AND wn.number <> ?", [$bondId, $v['number']]);
            });
        } catch (\PDOException $e) {
            if ($e->getCode() === '23000') {
                return ['ok' => false, 'errors' => ['number' => 'bond.err_duplicate']];
            }
            throw $e;
        }
        $this->matcher->matchBondHistorically($bondId);
        return ['ok' => true, 'errors' => []];
    }

    public function delete(int $userId, int $bondId): bool
    {
        return DB::exec('DELETE FROM prize_bonds WHERE id = ? AND user_id = ?', [$bondId, $userId]) === 1;
    }

    public function toggle(int $userId, int $bondId): bool
    {
        $n = DB::exec("UPDATE prize_bonds SET status = IF(status = 'active','inactive','active'), updated_at = ? WHERE id = ? AND user_id = ?", [DB::now(), $bondId, $userId]);
        if ($n === 1) {
            $this->matcher->matchBondHistorically($bondId);
        }
        return $n === 1;
    }

    private function validate(int $typeId, string $series, string $number, ?string $purchaseDate): array
    {
        $errors = [];
        $type = $this->enabledType($typeId);
        if (!$type) {
            return ['errors' => ['bond_type' => 'bond.err_type'], 'series' => '', 'number' => '', 'date' => null];
        }
        [$n, $e] = BondNumber::number($number, (int) $type['number_length']);
        if ($e) {
            $errors['number'] = $e;
        }
        [$s, $e] = $type['series_applicable'] ? BondNumber::series($series, (bool) $type['series_required']) : ['', null];
        if ($e) {
            $errors['series'] = $e;
        }
        [$d, $e] = BondNumber::date($purchaseDate);
        if ($e) {
            $errors['purchase_date'] = $e;
        }
        return ['errors' => $errors, 'series' => $s, 'number' => $n, 'date' => $d];
    }

    /**
     * Bulk import from CSV text. Header: series,number,purchase_date (series & purchase_date optional).
     * Every row is validated and reported; valid rows are inserted in one transaction.
     * @return array{rows:array,inserted:int,errors:int,duplicates:int,fatal?:string}
     */
    public function importCsv(int $userId, int $typeId, string $csv): array
    {
        $type = $this->enabledType($typeId);
        if (!$type) {
            return ['rows' => [], 'inserted' => 0, 'errors' => 0, 'duplicates' => 0, 'fatal' => 'bond.err_type'];
        }
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $lines = preg_split('/\r\n|\r|\n/', trim($csv));
        $rows = [];
        $header = null;
        $seen = [];
        $line = 0;
        foreach ($lines as $raw) {
            $line++;
            if (trim($raw) === '') {
                continue;
            }
            $cells = str_getcsv($raw, ',', '"', '');
            if ($header === null) {
                $first = strtolower(trim((string) ($cells[0] ?? '')));
                if (in_array($first, ['series', 'number', 'bond_number'], true)) {
                    $header = array_map(fn($c) => strtolower(trim((string) $c)), $cells);
                    continue;
                }
                $header = ['series', 'number', 'purchase_date'];
            }
            if (count($rows) >= self::MAX_IMPORT_ROWS) {
                return ['rows' => $rows, 'inserted' => 0, 'errors' => 1, 'duplicates' => 0, 'fatal' => 'bond.err_too_many_rows'];
            }
            $map = [];
            foreach ($header as $i => $h) {
                $map[$h === 'bond_number' ? 'number' : $h] = trim((string) ($cells[$i] ?? ''));
            }
            $row = ['line' => $line, 'series' => $map['series'] ?? '', 'number' => $map['number'] ?? '', 'purchase_date' => $map['purchase_date'] ?? '', 'status' => 'ok', 'message' => ''];
            $v = $this->validate($typeId, $row['series'], $row['number'], $row['purchase_date']);
            if ($v['errors']) {
                $row['status'] = 'error';
                $row['message'] = implode('; ', array_map(fn($k) => t($k), array_values($v['errors'])));
            } else {
                $key = $v['series'] . '|' . $v['number'];
                if (isset($seen[$key])) {
                    $row['status'] = 'duplicate';
                    $row['message'] = t('bond.err_duplicate_in_file');
                } else {
                    $seen[$key] = true;
                    $row['norm'] = $v;
                }
            }
            $rows[] = $row;
            unset($row);
        }
        $inserted = 0;
        $dups = 0;
        $existingCount = (int) DB::val('SELECT COUNT(*) FROM prize_bonds WHERE user_id = ?', [$userId]);
        $newIds = [];
        DB::tx(function () use (&$rows, $userId, $typeId, &$inserted, &$dups, $existingCount, &$newIds) {
            foreach ($rows as &$r) {
                if ($r['status'] !== 'ok') {
                    continue;
                }
                if ($existingCount + $inserted >= self::MAX_USER_BONDS) {
                    $r['status'] = 'error';
                    $r['message'] = t('bond.err_limit');
                    continue;
                }
                $now = DB::now();
                $n = DB::exec('INSERT IGNORE INTO prize_bonds (user_id, bond_type_id, series, number, purchase_date, status, created_at, updated_at) VALUES (?,?,?,?,?,?,?,?)',
                    [$userId, $typeId, $r['norm']['series'], $r['norm']['number'], $r['norm']['date'], 'active', $now, $now]);
                if ($n === 1) {
                    $inserted++;
                    $r['message'] = t('bond.imported');
                    $newIds[] = (int) DB::pdo()->lastInsertId();
                } else {
                    $dups++;
                    $r['status'] = 'duplicate';
                    $r['message'] = t('bond.err_duplicate');
                }
                unset($r['norm']);
            }
        });
        foreach ($newIds as $id) {
            $this->matcher->matchBondHistorically($id);
        }
        foreach ($rows as &$r) {
            unset($r['norm']);
        }
        $errors = count(array_filter($rows, fn($r) => $r['status'] === 'error'));
        return ['rows' => $rows, 'inserted' => $inserted, 'errors' => $errors, 'duplicates' => count(array_filter($rows, fn($r) => $r['status'] === 'duplicate'))];
    }
}
