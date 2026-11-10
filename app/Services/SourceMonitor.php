<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\DB;
use App\Core\Logger;
use App\Core\Settings;

/** Cross-checks staged numbers against IRD PBRIS (secondary source). Bangladesh Bank stays authoritative. */
final class IRDVerifier
{
    public function __construct(private ?IRDAdapter $ird = null, private int $chunk = 10, private int $pauseMs = 700)
    {
        $this->ird ??= new IRDAdapter();
    }

    /** @return array{status:string,checked:int,mismatches:array} status: agree|disputed|unavailable */
    public function verifyImport(int $importId): array
    {
        $imp = DB::one('SELECT * FROM result_imports WHERE id = ?', [$importId]);
        if (!$imp || !in_array($imp['status'], ['staged', 'verified', 'review_required', 'disputed'], true)) {
            throw new \RuntimeException('Import not eligible for secondary verification.');
        }
        $staged = json_decode((string) $imp['staged_data'], true) ?: [];
        $date = $staged['draw_date'] ?? null;
        $expected = [];                       // number => [tier, amount]
        foreach ($staged['tiers'] ?? [] as $code => $t) {
            foreach ($t['numbers'] as $n) {
                $expected[(string) $n] = ['tier' => (string) $code, 'amount' => (int) $t['amount']];
            }
        }
        $mismatches = [];
        $checked = 0;
        $absent = 0;      // numbers IRD knows nothing about (not just a different draw date)
        try {
            $chunks = array_chunk(array_keys($expected), $this->chunk);
            foreach ($chunks as $i => $nums) {
                $found = $this->ird->lookup($nums);
                foreach ($nums as $n) {
                    $rows = array_values(array_filter($found[$n] ?? [], fn($r) => $r['date'] === $date));
                    $checked++;
                    if (empty($found[$n])) {
                        $absent++;
                    }
                    if (!$rows) {
                        $mismatches[] = ['number' => $n, 'issue' => 'not listed by IRD for draw date ' . $date, 'bb' => $expected[$n], 'ird' => $found[$n] ?? []];
                        continue;
                    }
                    $r = $rows[0];
                    if ($r['tier'] !== $expected[$n]['tier'] || $r['amount'] !== $expected[$n]['amount']) {
                        $mismatches[] = ['number' => $n, 'issue' => 'prize rank/amount differs', 'bb' => $expected[$n], 'ird' => $r];
                    }
                }
                if ($i < count($chunks) - 1 && $this->pauseMs > 0) {
                    usleep($this->pauseMs * 1000);   // be polite to the government server
                }
            }
        } catch (\Throwable $e) {
            $report = ['status' => 'unavailable', 'checked' => $checked, 'mismatches' => [], 'error' => Logger::scrub($e->getMessage()), 'at' => DB::now()];
            DB::update('result_imports', ['secondary_report' => json_encode($report), 'updated_at' => DB::now()], 'id = ?', [$importId]);
            (new ImportService())->log($importId, 'warning', 'IRD cross-check unavailable: ' . $e->getMessage());
            return $report;
        }
        if ($expected && $absent === count($expected)) {
            // IRD has no record of ANY number of this draw (older draws are not in its database): that is lack of
            // coverage, not a disagreement. The import stays staged for manual verification.
            $report = ['status' => 'not_covered', 'checked' => $checked, 'mismatches' => [], 'note' => 'IRD returned no records for any number of this draw.', 'at' => DB::now()];
            DB::update('result_imports', ['secondary_report' => json_encode($report), 'updated_at' => DB::now()], 'id = ?', [$importId]);
            (new ImportService())->log($importId, 'warning', 'IRD does not cover this draw; manual verification required.');
            return $report;
        }
        $report = ['status' => $mismatches ? 'disputed' : 'agree', 'checked' => $checked, 'mismatches' => $mismatches, 'at' => DB::now()];
        $upd = ['secondary_report' => json_encode($report, JSON_UNESCAPED_UNICODE), 'updated_at' => DB::now()];
        $valid = !empty((json_decode((string) $imp['validation_report'], true) ?: [])['valid']);
        if ($mismatches) {
            $upd['status'] = 'disputed';
            (new NotificationService())->alertAdmins('Sources disagree - publication blocked', "Import #$importId: IRD and Bangladesh Bank differ on " . count($mismatches) . ' numbers.', 'dispute:' . $importId);
        } elseif ($valid && $imp['status'] === 'staged') {
            $upd['status'] = 'verified';
            $upd['verified_at'] = DB::now();
        }
        DB::update('result_imports', $upd, 'id = ?', [$importId]);
        (new ImportService())->log($importId, $mismatches ? 'error' : 'info', 'IRD cross-check: ' . $report['status'], ['checked' => $checked, 'mismatches' => count($mismatches)]);
        return $report;
    }
}

/** Monitors the primary source and creates import jobs. NEVER sends user email. */
final class SourceMonitor
{
    public function __construct(private ?BangladeshBankAdapter $adapter = null, private ?ImportService $imports = null)
    {
        $this->adapter ??= new BangladeshBankAdapter();
        $this->imports ??= new ImportService();
    }

    /** @return array{discovered:int,fetched:int,changed:int,skipped:int} */
    public function run(bool $force = false): array
    {
        $src = DB::one("SELECT * FROM result_sources WHERE code = 'bb' AND is_enabled = 1");
        $stats = ['discovered' => 0, 'fetched' => 0, 'changed' => 0, 'skipped' => 0];
        if (!$src) {
            return $stats;
        }
        $interval = (int) ($src['check_interval_minutes'] ?: Settings::int('sources.check_interval_minutes', 360));
        if (!$force && $src['last_checked_at'] && strtotime($src['last_checked_at'] . ' UTC') > time() - $interval * 60 + 60) {
            $stats['skipped'] = 1;
            return $stats;
        }
        DB::update('result_sources', ['last_checked_at' => DB::now()], 'id = ?', [$src['id']]);
        $typeId = (int) DB::val('SELECT id FROM bond_types WHERE code = ? AND is_enabled = 1', [BangladeshBankAdapter::BOND_TYPE]);
        if (!$typeId) {
            return $stats;
        }
        try {
            $list = $this->adapter->fetchLatest();
            $newestKnown = (int) DB::val('SELECT COALESCE(MAX(draw_number),0) FROM result_imports WHERE bond_type_id = ?', [$typeId]);
            foreach ($list as $i => $item) {
                $row = DB::one("SELECT id, status, file_checksum FROM result_imports WHERE bond_type_id = ? AND draw_number = ? AND status <> 'rejected' ORDER BY id DESC LIMIT 1", [$typeId, $item['draw_number']]);
                $isNew = !$row;
                // Re-download only (a) brand-new draws, (b) the newest listed draw to detect post-publication changes,
                // (c) discovered-but-unfetched rows. Older, known draws are never re-downloaded.
                $recheckNewest = ($i === 0 && $row && $row['status'] !== 'discovered');
                if (!$isNew && !$recheckNewest && $row['status'] !== 'discovered') {
                    continue;
                }
                if ($isNew && $item['draw_number'] < $newestKnown - 8 && $newestKnown > 0) {
                    continue;   // very old backlog entries are imported manually if wanted
                }
                if ($isNew) {
                    $this->imports->discover((int) $src['id'], $typeId, $item['draw_number'], $item['nominal_date'], $item['url']);
                    $stats['discovered']++;
                }
                $doc = $this->adapter->fetchDocument($item['url']);
                $r = $this->imports->registerDocument([
                    'source_id' => $src['id'], 'bond_type_id' => $typeId, 'draw_number' => $item['draw_number'], 'draw_date' => $item['nominal_date'],
                    'source_url' => $item['url'], 'body' => $doc['body'], 'format' => $doc['format'], 'origin' => 'auto',
                ]);
                if ($r['action'] === 'created' || $r['action'] === 'refreshed') {
                    $stats['fetched']++;
                } elseif ($r['action'] === 'changed_after_publish') {
                    $stats['changed']++;
                }
            }
            DB::update('result_sources', ['last_success_at' => DB::now(), 'last_error' => null], 'id = ?', [$src['id']]);
        } catch (\Throwable $e) {
            $msg = Logger::scrub($e->getMessage());
            DB::update('result_sources', ['last_error' => mb_substr($msg, 0, 500)], 'id = ?', [$src['id']]);
            Logger::error('monitor', $msg);
            DB::insert('failed_jobs', ['job_name' => 'monitor_sources', 'error' => $msg, 'created_at' => DB::now()]);
            (new NotificationService())->alertAdmins('Source monitoring failed', $msg, 'mon:' . gmdate('YmdH'));
            throw $e;
        }
        return $stats;
    }
}
