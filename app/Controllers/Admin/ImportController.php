<?php
declare(strict_types=1);

namespace App\Controllers\Admin;

use App\Controllers\Controller;
use App\Core\AdminAuth;
use App\Core\Audit;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\DrawService;
use App\Services\ImportService;
use App\Services\IRDVerifier;
use App\Services\Pipeline;
use App\Services\SourceMonitor;

/** Result imports, verification, publication, matching and corrections. */
final class ImportController extends Controller
{
    private function adminId(): int
    {
        return (int) AdminAuth::id();
    }

    private function adminView(string $v, array $d = []): Response
    {
        return $this->view($v, $d + ['robots' => 'noindex'], 'layouts/admin');
    }

    public function index(Request $r): Response
    {
        $status = $r->str('status');
        $where = '1=1';
        $params = [];
        if ($status !== '') {
            $where = 'i.status = ?';
            $params[] = $status;
        }
        $total = (int) DB::val("SELECT COUNT(*) FROM result_imports i WHERE $where", $params);
        $p = $this->pagination($r, $total, 25);
        $rows = DB::all("SELECT i.*, s.name AS source_name FROM result_imports i LEFT JOIN result_sources s ON s.id = i.source_id WHERE $where ORDER BY i.id DESC LIMIT ? OFFSET ?", [...$params, $p['per'], $p['offset']]);
        return $this->adminView('admin/imports', ['title' => 'Result Imports', 'rows' => $rows, 'pg' => $p, 'status' => $status, 'bondTypes' => $this->enabledTypes()]);
    }

    public function show(Request $r): Response
    {
        $imp = DB::one('SELECT i.*, bt.name AS bond_name, bt.number_length FROM result_imports i JOIN bond_types bt ON bt.id = i.bond_type_id WHERE i.id = ?', [(int) $r->params['id']]);
        if (!$imp) {
            $this->notFound();
        }
        $tiers = DB::all('SELECT * FROM prize_tiers WHERE bond_type_id = ? AND is_active = 1 ORDER BY rank_order', [$imp['bond_type_id']]);
        $logs = DB::all('SELECT * FROM import_logs WHERE import_id = ? ORDER BY id DESC LIMIT 50', [$imp['id']]);
        $draw = $imp['draw_id'] ? DB::one('SELECT * FROM prize_draws WHERE id = ?', [$imp['draw_id']]) : null;
        return $this->adminView('admin/import_show', [
            'title' => 'Import #' . $imp['id'], 'imp' => $imp, 'tiers' => $tiers, 'logs' => $logs, 'draw' => $draw,
            'staged' => json_decode((string) $imp['staged_data'], true), 'report' => json_decode((string) $imp['validation_report'], true),
            'secondary' => json_decode((string) $imp['secondary_report'], true),
        ]);
    }

    public function action(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $act = (string) $r->params['action'];
        $need = ['edit' => 'review_results', 'reprocess' => 'review_results', 'verify' => 'verify_results', 'reject' => 'verify_results', 'reverify' => 'verify_results', 'publish' => 'publish_results'][$act] ?? null;
        if (!$need || !AdminAuth::can($need)) {
            throw new \App\Core\HttpException(403, 'Your role does not permit this action.');
        }
        $svc = new ImportService();
        try {
            switch ($act) {
                case 'edit':
                    $tiers = [];
                    foreach (DB::all('SELECT code FROM prize_tiers WHERE bond_type_id = (SELECT bond_type_id FROM result_imports WHERE id = ?)', [$id]) as $t) {
                        $tiers[$t['code']] = (string) ($r->post['tier_' . $t['code']] ?? '');
                    }
                    $res = $svc->updateStaged($id, ['draw_number' => $r->str('draw_number'), 'draw_date' => $r->str('draw_date'), 'series_count' => $r->str('series_count')], $tiers, $this->adminId(), $r->ip());
                    $msg = $res['status'] === 'staged' ? 'Saved. Validation passed.' : 'Saved. Validation still reports problems.';
                    break;
                case 'reprocess':
                    $svc->process($id);
                    $msg = 'Document re-parsed.';
                    break;
                case 'verify':
                    $svc->verify($id, $this->adminId(), $r->str('override_reason') ?: null, $r->ip());
                    $msg = 'Import verified. It can now be published.';
                    break;
                case 'reject':
                    $svc->reject($id, $this->adminId(), $r->str('reason') ?: 'Rejected', $r->ip());
                    $msg = 'Import rejected.';
                    break;
                case 'reverify':
                    $rep = (new IRDVerifier())->verifyImport($id);
                    $msg = 'IRD cross-check: ' . $rep['status'] . " ({$rep['checked']} numbers checked, " . count($rep['mismatches']) . ' mismatches).';
                    break;
                case 'publish':
                    $drawId = (new DrawService())->publish($id, $this->adminId(), $r->ip());
                    $msg = "Published as draw #$drawId. Matching and notifications run on the next scheduled cycle, or use 'Run matching now'.";
                    return $this->redirect('/admin/draws/' . $drawId, 'success', $msg);
                default:
                    $this->notFound();
            }
        } catch (\RuntimeException $e) {
            return $this->redirect('/admin/imports/' . $id, 'danger', $e->getMessage());
        }
        return $this->redirect('/admin/imports/' . $id, 'success', $msg);
    }

    public function fetchNow(Request $r): Response
    {
        try {
            $s = (new SourceMonitor())->run(true);
            $p = (new Pipeline())->processImports();
            return $this->redirect('/admin/imports', 'success', "Source checked: discovered {$s['discovered']}, fetched {$s['fetched']}, changed {$s['changed']}. $p");
        } catch (\Throwable $e) {
            return $this->redirect('/admin/imports', 'danger', 'Source check failed: ' . \App\Core\Logger::scrub($e->getMessage()));
        }
    }

    public function upload(Request $r): Response
    {
        $f = $r->file('pdf');
        $typeId = $r->int('bond_type');
        if (!$f || $f['error'] !== UPLOAD_ERR_OK) {
            return $this->redirect('/admin/imports', 'danger', 'Upload failed.');
        }
        $tmpName = (string) ($f['tmp_name'] ?? '');
        if (!is_file($tmpName) || $f['size'] > 8 * 1024 * 1024 || (int) filesize($tmpName) > 8 * 1024 * 1024) {
            return $this->redirect('/admin/imports', 'danger', 'File missing or too large (max 8 MB).');
        }
        $body = @file_get_contents($tmpName);
        if ($body === false) {
            return $this->redirect('/admin/imports', 'danger', 'Upload could not be read.');
        }
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->buffer($body);
        if ($mime !== 'application/pdf' || !str_starts_with($body, '%PDF-')) {
            return $this->redirect('/admin/imports', 'danger', 'Only PDF documents are accepted.');
        }
        $type = DB::one('SELECT * FROM bond_types WHERE id = ? AND is_enabled = 1', [$typeId]);
        if (!$type) {
            return $this->redirect('/admin/imports', 'danger', 'Choose an enabled bond type.');
        }
        $svc = new ImportService();
        $reg = $svc->registerDocument(['bond_type_id' => $typeId, 'draw_number' => $r->int('draw_number') ?: null, 'source_url' => null, 'body' => $body, 'format' => 'pdf', 'origin' => 'manual_upload', 'admin_id' => $this->adminId()]);
        Audit::log($this->adminId(), 'import.upload', 'result_import', $reg['id'], null, ['action' => $reg['action']], $r->ip());
        $svc->process($reg['id']);
        return $this->redirect('/admin/imports/' . $reg['id'], 'success', 'Document uploaded and parsed. Review the extracted data before verifying.');
    }

    public function manual(Request $r): Response
    {
        $typeId = $r->int('bond_type');
        if (!DB::one('SELECT id FROM bond_types WHERE id = ? AND is_enabled = 1', [$typeId])) {
            return $this->redirect('/admin/imports', 'danger', 'Choose an enabled bond type.');
        }
        $n = $r->int('draw_number');
        if ($n < 1) {
            return $this->redirect('/admin/imports', 'danger', 'Draw number is required.');
        }
        if (DB::val("SELECT id FROM result_imports WHERE bond_type_id = ? AND draw_number = ? AND status NOT IN ('rejected','failed')", [$typeId, $n])) {
            return $this->redirect('/admin/imports', 'danger', "An active import for draw $n already exists. Open it and edit the staged data instead.");
        }
        $id = DB::insert('result_imports', ['bond_type_id' => $typeId, 'draw_number' => $n, 'draw_date' => $r->str('draw_date') ?: null, 'status' => 'parsed', 'origin' => 'manual_entry', 'admin_id' => $this->adminId(), 'created_at' => DB::now(), 'updated_at' => DB::now()]);
        $tiers = [];
        foreach (DB::all('SELECT code FROM prize_tiers WHERE bond_type_id = ?', [$typeId]) as $t) {
            $tiers[$t['code']] = (string) ($r->post['tier_' . $t['code']] ?? '');
        }
        (new ImportService())->updateStaged($id, ['draw_number' => $n, 'draw_date' => $r->str('draw_date'), 'series_count' => $r->str('series_count')], $tiers, $this->adminId(), $r->ip());
        Audit::log($this->adminId(), 'import.manual_entry', 'result_import', $id, null, ['draw_number' => $n], $r->ip());
        return $this->redirect('/admin/imports/' . $id, 'success', 'Manual entry staged. Review, verify, then publish.');
    }

    // ---- Draws -----------------------------------------------------------------

    public function draws(Request $r): Response
    {
        $total = (int) DB::val('SELECT COUNT(*) FROM prize_draws');
        $p = $this->pagination($r, $total, 25);
        $rows = DB::all("SELECT d.*, bt.name AS bond_name, (SELECT COUNT(*) FROM prize_matches m WHERE m.draw_id = d.id AND m.match_status = 'detected') AS matches
                         FROM prize_draws d JOIN bond_types bt ON bt.id = d.bond_type_id ORDER BY d.draw_date DESC, d.draw_number DESC LIMIT ? OFFSET ?", [$p['per'], $p['offset']]);
        return $this->adminView('admin/draws', ['title' => 'Prize Draws', 'rows' => $rows, 'pg' => $p]);
    }

    public function drawShow(Request $r): Response
    {
        $d = DB::one('SELECT d.*, bt.name AS bond_name FROM prize_draws d JOIN bond_types bt ON bt.id = d.bond_type_id WHERE d.id = ?', [(int) $r->params['id']]);
        if (!$d) {
            $this->notFound();
        }
        $tiers = (new \App\Controllers\HomeController())->numbersByTier((int) $d['id']);
        $tierRows = DB::all('SELECT * FROM prize_tiers WHERE bond_type_id = ? AND is_active = 1 ORDER BY rank_order', [$d['bond_type_id']]);
        $snap = (new DrawService())->snapshot((int) $d['id']);
        $corr = DB::all('SELECT c.*, a.full_name FROM draw_corrections c LEFT JOIN admin_users a ON a.id = c.admin_id WHERE c.draw_id = ? ORDER BY c.version DESC', [$d['id']]);
        $stats = DB::one("SELECT COUNT(*) AS total, SUM(notification_status = 'sent') AS sent, SUM(notification_status = 'queued') AS queued, SUM(notification_status = 'failed') AS failed FROM prize_matches WHERE draw_id = ? AND match_status = 'detected'", [$d['id']]);
        return $this->adminView('admin/draw_show', ['title' => 'Draw ' . $d['draw_number'], 'draw' => $d, 'tiers' => $tiers, 'tierRows' => $tierRows, 'snap' => $snap, 'corrections' => $corr, 'stats' => $stats]);
    }

    public function rematch(Request $r): Response
    {
        $id = (int) $r->params['id'];
        try {
            DB::exec("UPDATE prize_draws SET lifecycle = 'published' WHERE id = ? AND lifecycle IN ('matched','notifications_queued','completed')", [$id]);
            $msg = (new Pipeline())->runMatching($id);
            Audit::log($this->adminId(), 'draw.rematch', 'prize_draw', $id, null, ['result' => $msg], $r->ip());
            return $this->redirect('/admin/draws/' . $id, 'success', 'Matching complete (idempotent): ' . $msg);
        } catch (\Throwable $e) {
            return $this->redirect('/admin/draws/' . $id, 'danger', $e->getMessage());
        }
    }

    public function correct(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $tiers = [];
        foreach (DB::all('SELECT code FROM prize_tiers WHERE bond_type_id = (SELECT bond_type_id FROM prize_draws WHERE id = ?)', [$id]) as $t) {
            $tiers[$t['code']] = (string) ($r->post['tier_' . $t['code']] ?? '');
        }
        try {
            $res = (new DrawService())->correct($id, $tiers, $r->str('reason'), $this->adminId(), $r->ip());
            return $this->redirect('/admin/draws/' . $id, 'success', "Correction v{$res['version']} applied: {$res['added']} added, {$res['voided']} voided, {$res['retractions']} retraction notices queued. Re-run matching to refresh matches.");
        } catch (\RuntimeException $e) {
            return $this->redirect('/admin/draws/' . $id, 'danger', $e->getMessage());
        }
    }
}
