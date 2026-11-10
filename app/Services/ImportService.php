<?php
declare(strict_types=1);

namespace App\Services;

use App\Core\Audit;
use App\Core\DB;
use App\Core\Logger;
use App\Core\Settings;

/**
 * Import lifecycle: discovered -> fetched -> parsed -> staged -> (review_required | verified | disputed)
 *                   -> published   (publication itself lives in DrawService).
 */
final class ImportService
{
    public function __construct(private ?ResultValidator $validator = null, private ?PDFResultParser $parser = null)
    {
        $this->validator ??= new ResultValidator();
        $this->parser ??= new PDFResultParser();
    }

    public function log(?int $importId, string $level, string $msg, array $ctx = []): void
    {
        DB::insert('import_logs', ['import_id' => $importId, 'level' => $level, 'message' => $msg, 'context' => $ctx ? json_encode($ctx, JSON_UNESCAPED_UNICODE) : null, 'created_at' => DB::now()]);
    }

    /**
     * Store a fetched/uploaded document and create (or refresh) the import record.
     * @param array $m source_id?, bond_type_id, draw_number?, draw_date?, source_url?, body, format, origin, admin_id?
     * @return array{id:int,action:string}  action: created|unchanged|refreshed|changed_after_publish
     */
    public function registerDocument(array $m): array
    {
        $checksum = hash('sha256', $m['body']);
        $existing = $m['draw_number'] ? DB::one("SELECT * FROM result_imports WHERE bond_type_id = ? AND draw_number = ? AND status <> 'rejected' ORDER BY id DESC LIMIT 1", [$m['bond_type_id'], $m['draw_number']]) : null;

        if ($existing) {
            if ($existing['file_checksum'] === $checksum) {
                return ['id' => (int) $existing['id'], 'action' => 'unchanged'];
            }
            if ($existing['status'] === 'published') {
                $this->log((int) $existing['id'], 'warning', 'Source document changed after publication; NOT applied automatically.', ['old' => $existing['file_checksum'], 'new' => $checksum]);
                (new NotificationService())->alertAdmins('Official document changed after publication', "Draw {$m['draw_number']}: the source file checksum changed ($checksum). Review and use the correction workflow if needed.", 'chg:' . $m['draw_number'] . ':' . substr($checksum, 0, 12));
                return ['id' => (int) $existing['id'], 'action' => 'changed_after_publish'];
            }
        }
        $dir = base_path('storage/imports/' . preg_replace('/[^a-z_]/', '', $m['origin'] ?? 'auto'));
        if (!is_dir($dir)) {
            @mkdir($dir, 0750, true);
        }
        $ext = $m['format'] === 'pdf' ? 'pdf' : 'bin';
        $file = $dir . '/' . date('Ymd_His') . '_' . bin2hex(random_bytes(6)) . '.' . $ext;   // randomised, never user-controlled
        if (file_put_contents($file, $m['body']) === false) {
            throw new \RuntimeException('Cannot store source document.');
        }
        $rel = 'storage/imports/' . basename($dir) . '/' . basename($file);

        if ($existing) {
            DB::update('result_imports', [
                'file_path' => $rel, 'file_checksum' => $checksum, 'source_url' => $m['source_url'] ?? null, 'status' => 'fetched',
                'staged_data' => null, 'validation_report' => null, 'secondary_report' => null,
                'retrieved_at' => DB::now(), 'updated_at' => DB::now(),
            ], 'id = ?', [$existing['id']]);
            $this->log((int) $existing['id'], 'warning', 'Document changed before publication; re-fetched and reset to fetched.', ['checksum' => $checksum]);
            return ['id' => (int) $existing['id'], 'action' => 'refreshed'];
        }
        $id = DB::insert('result_imports', [
            'source_id' => $m['source_id'] ?? null, 'bond_type_id' => $m['bond_type_id'], 'draw_number' => $m['draw_number'] ?? null,
            'draw_date' => $m['draw_date'] ?? null, 'source_url' => $m['source_url'] ?? null, 'file_path' => $rel, 'file_checksum' => $checksum,
            'file_format' => $m['format'], 'status' => 'fetched', 'origin' => $m['origin'] ?? 'auto', 'admin_id' => $m['admin_id'] ?? null,
            'retrieved_at' => DB::now(), 'created_at' => DB::now(), 'updated_at' => DB::now(),
        ]);
        $this->log($id, 'info', 'Document stored.', ['checksum' => $checksum, 'bytes' => strlen($m['body']), 'url' => $m['source_url'] ?? null]);
        return ['id' => $id, 'action' => 'created'];
    }

    /** Record a discovered-but-not-yet-fetched draw. */
    public function discover(int $sourceId, int $bondTypeId, int $drawNumber, ?string $nominalDate, string $url): int
    {
        $row = DB::one('SELECT id FROM result_imports WHERE bond_type_id = ? AND draw_number = ? ORDER BY id DESC LIMIT 1', [$bondTypeId, $drawNumber]);
        if ($row) {
            return (int) $row['id'];
        }
        $id = DB::insert('result_imports', ['source_id' => $sourceId, 'bond_type_id' => $bondTypeId, 'draw_number' => $drawNumber, 'draw_date' => $nominalDate,
            'source_url' => $url, 'status' => 'discovered', 'origin' => 'auto', 'created_at' => DB::now(), 'updated_at' => DB::now()]);
        $this->log($id, 'info', "Draw $drawNumber discovered on source listing.");
        return $id;
    }

    /** Parse the stored document into staged data and validate it. */
    public function process(int $importId): array
    {
        $imp = DB::one('SELECT * FROM result_imports WHERE id = ?', [$importId]);
        if (!$imp || !in_array($imp['status'], ['fetched', 'parsed', 'failed', 'review_required', 'staged'], true)) {
            throw new \RuntimeException('Import is not in a processable state.');
        }
        try {
            $path = base_path($imp['file_path'] ?? '');
            if (!$imp['file_path'] || !is_file($path)) {
                throw new \RuntimeException('Stored source document is missing.');
            }
            if (($imp['file_format'] ?? 'pdf') !== 'pdf') {
                throw new \RuntimeException('Unsupported document format; use manual entry.');
            }
            $staged = $this->parser->parseFile($path);
            DB::update('result_imports', ['status' => 'parsed', 'parser_version' => PDFResultParser::VERSION, 'updated_at' => DB::now()], 'id = ?', [$importId]);
        } catch (\Throwable $e) {
            DB::update('result_imports', ['status' => 'failed', 'error_message' => mb_substr($e->getMessage(), 0, 500), 'updated_at' => DB::now()], 'id = ?', [$importId]);
            $this->log($importId, 'error', 'Parsing failed: ' . $e->getMessage());
            DB::insert('failed_jobs', ['job_name' => 'process_import', 'reference' => (string) $importId, 'error' => $e->getMessage(), 'created_at' => DB::now()]);
            (new NotificationService())->alertAdmins('Result import failed', "Import #$importId could not be parsed: " . $e->getMessage(), 'impfail:' . $importId);
            return ['status' => 'failed', 'error' => $e->getMessage()];
        }
        return $this->stage($importId, $staged, ['draw_number' => $imp['draw_number'], 'draw_date' => $imp['draw_date']]);
    }

    /** Validate staged data and move the import to staged/review_required. */
    public function stage(int $importId, array $staged, array $expected = []): array
    {
        $imp = DB::one('SELECT * FROM result_imports WHERE id = ?', [$importId]);
        $expected = array_filter($expected, fn($v) => $v !== null && $v !== '');
        if (!empty($staged['admin_confirmed'])) {
            unset($expected['draw_date']);
        }
        $report = $this->validator->validate($staged, (int) $imp['bond_type_id'], $expected);
        $status = $report['valid'] ? 'staged' : 'review_required';
        DB::update('result_imports', [
            'staged_data' => json_encode($staged, JSON_UNESCAPED_UNICODE),
            'validation_report' => json_encode($report, JSON_UNESCAPED_UNICODE),
            'status' => $status, 'imported_at' => DB::now(), 'error_message' => null, 'updated_at' => DB::now(),
            'draw_number' => $imp['draw_number'] ?: ($staged['draw_number'] ?? null),
        ], 'id = ?', [$importId]);
        $this->log($importId, $report['valid'] ? 'info' : 'warning', $report['valid'] ? 'Staged; validation passed.' : 'Staged; validation requires review.', ['errors' => $report['errors'], 'warnings' => $report['warnings']]);
        if (!$report['valid']) {
            (new NotificationService())->alertAdmins('Result import needs review', "Import #$importId (draw " . ($staged['draw_number'] ?? '?') . "): " . implode('; ', array_slice($report['errors'], 0, 5)), 'review:' . $importId . ':' . substr(md5(json_encode($report['errors'])), 0, 8));
        }
        return ['status' => $status, 'report' => $report];
    }

    /** Admin edit of staged numbers. $tiers = ['1' => [numbers...], ...] */
    public function updateStaged(int $importId, array $fields, array $tierNumbers, int $adminId, string $ip = ''): array
    {
        $imp = DB::one('SELECT * FROM result_imports WHERE id = ?', [$importId]);
        if (!$imp || in_array($imp['status'], ['published', 'rejected'], true)) {
            throw new \RuntimeException('This import can no longer be edited.');
        }
        $old = json_decode((string) $imp['staged_data'], true) ?: [];
        $new = $old;
        $new['draw_number'] = isset($fields['draw_number']) ? (int) $fields['draw_number'] : ($old['draw_number'] ?? null);
        $new['draw_date'] = $fields['draw_date'] ?? ($old['draw_date'] ?? null);
        $new['series_count'] = isset($fields['series_count']) && $fields['series_count'] !== '' ? (int) $fields['series_count'] : ($old['series_count'] ?? null);
        $new['admin_confirmed'] = true;
        $tiers = DB::all('SELECT code, amount FROM prize_tiers WHERE bond_type_id = ? AND is_active = 1', [$imp['bond_type_id']]);
        $new['tiers'] = [];
        foreach ($tiers as $t) {
            $raw = $tierNumbers[$t['code']] ?? [];
            $nums = is_array($raw) ? $raw : (preg_split('/[\s,;]+/', (string) $raw, -1, PREG_SPLIT_NO_EMPTY) ?: []);
            $nums = array_map(fn($n) => \App\Helpers\BondNumber::asciiDigits(trim((string) $n)), $nums);
            $new['tiers'][$t['code']] = [
                'amount' => (int) ($old['tiers'][$t['code']]['amount'] ?? $t['amount']),
                'expected_count' => $old['tiers'][$t['code']]['expected_count'] ?? null,
                'numbers' => array_values($nums),
            ];
        }
        $new['warnings'] = [];
        // Admin-provided dates/numbers are re-validated; the listing's nominal date is no longer enforced.
        Audit::log($adminId, 'import.staged_edit', 'result_import', $importId, ['staged' => $old], ['staged' => $new], $ip);
        DB::update('result_imports', ['status' => 'parsed', 'updated_at' => DB::now()], 'id = ?', [$importId]);
        return $this->stage($importId, $new, ['draw_number' => $new['draw_number']]);
    }

    /** Admin approval. Disputed imports require an explicit override reason. */
    public function verify(int $importId, int $adminId, ?string $overrideReason = null, string $ip = ''): void
    {
        $imp = DB::one('SELECT * FROM result_imports WHERE id = ?', [$importId]);
        if (!$imp || !in_array($imp['status'], ['staged', 'review_required', 'disputed'], true)) {
            throw new \RuntimeException('Import cannot be verified in its current state.');
        }
        $report = json_decode((string) $imp['validation_report'], true) ?: [];
        if (empty($report['valid'])) {
            throw new \RuntimeException('Validation errors must be resolved (edit the staged data) before verification.');
        }
        if ($imp['status'] === 'disputed' && trim((string) $overrideReason) === '') {
            throw new \RuntimeException('Sources disagree: an explicit override reason is required.');
        }
        DB::update('result_imports', ['status' => 'verified', 'admin_id' => $adminId, 'verified_at' => DB::now(), 'updated_at' => DB::now()], 'id = ?', [$importId]);
        Audit::log($adminId, 'import.verify', 'result_import', $importId, ['status' => $imp['status']], ['status' => 'verified', 'override_reason' => $overrideReason], $ip);
        $this->log($importId, 'info', 'Verified by administrator.', ['override' => $overrideReason]);
    }

    public function reject(int $importId, int $adminId, string $reason, string $ip = ''): void
    {
        $imp = DB::one('SELECT status FROM result_imports WHERE id = ?', [$importId]);
        if (!$imp || $imp['status'] === 'published') {
            throw new \RuntimeException('A published import cannot be rejected; use the correction workflow.');
        }
        DB::update('result_imports', ['status' => 'rejected', 'error_message' => mb_substr($reason, 0, 500), 'admin_id' => $adminId, 'updated_at' => DB::now()], 'id = ?', [$importId]);
        Audit::log($adminId, 'import.reject', 'result_import', $importId, ['status' => $imp['status']], ['status' => 'rejected', 'reason' => $reason], $ip);
        $this->log($importId, 'warning', 'Rejected by administrator: ' . $reason);
    }
}
