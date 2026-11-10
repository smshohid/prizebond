<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\Auth;
use App\Core\DB;
use App\Core\Request;
use App\Core\Response;
use App\Core\Session;
use App\Services\BondService;

final class BondController extends Controller
{
    private function svc(): BondService
    {
        return new BondService();
    }

    public function index(Request $r): Response
    {
        $uid = Auth::id();
        $where = 'b.user_id = ?';
        $params = [$uid];
        if ($q = $r->str('q')) {
            $where .= ' AND (b.number LIKE ? OR b.series LIKE ?)';
            $like = '%' . addcslashes(\App\Helpers\BondNumber::asciiDigits($q), '%_\\') . '%';
            array_push($params, $like, $like);
        }
        if (in_array($r->str('status'), ['active', 'inactive'], true)) {
            $where .= ' AND b.status = ?';
            $params[] = $r->str('status');
        }
        $total = (int) DB::val("SELECT COUNT(*) FROM prize_bonds b WHERE $where", $params);
        $p = $this->pagination($r, $total, 25);
        $bonds = DB::all("SELECT b.*, bt.name AS type_name, (SELECT COUNT(*) FROM prize_matches pm WHERE pm.prize_bond_id = b.id AND pm.match_status = 'detected') AS wins
                          FROM prize_bonds b JOIN bond_types bt ON bt.id = b.bond_type_id WHERE $where ORDER BY b.created_at DESC, b.id DESC LIMIT ? OFFSET ?", [...$params, $p['per'], $p['offset']]);
        return $this->view('user/bonds', ['title' => t('nav.my_bonds'), 'bonds' => $bonds, 'pg' => $p, 'types' => $this->enabledTypes(), 'errors' => Session::pull('_errors', []), 'robots' => 'noindex']);
    }

    public function add(Request $r): Response
    {
        $res = $this->svc()->add((int) Auth::id(), $r->int('bond_type'), $r->str('series'), $r->str('number'), $r->str('purchase_date') ?: null);
        if (!$res['ok']) {
            $this->withOld($r);
            Session::put('_errors', $res['errors']);
            return Response::redirect('/bonds');
        }
        $msg = t('bond.added') . ($res['matches'] > 0 ? ' ' . t('bond.historical_found', ['n' => $res['matches']]) : '');
        return $this->redirect('/bonds', $res['matches'] > 0 ? 'warning' : 'success', $msg);
    }

    private function own(Request $r): array
    {
        $b = DB::one('SELECT b.*, bt.name AS type_name FROM prize_bonds b JOIN bond_types bt ON bt.id = b.bond_type_id WHERE b.id = ? AND b.user_id = ?', [(int) $r->params['id'], (int) Auth::id()]);
        if (!$b) {
            $this->notFound();   // 404, not 403: do not reveal that another user's bond exists
        }
        return $b;
    }

    public function edit(Request $r): Response
    {
        return $this->view('user/bond_edit', ['title' => t('bond.edit'), 'bond' => $this->own($r), 'errors' => Session::pull('_errors', []), 'robots' => 'noindex']);
    }

    public function update(Request $r): Response
    {
        $b = $this->own($r);
        $res = $this->svc()->update((int) Auth::id(), (int) $b['id'], $r->str('series'), $r->str('number'), $r->str('purchase_date') ?: null);
        if (!$res['ok']) {
            $this->withOld($r);
            Session::put('_errors', $res['errors']);
            return Response::redirect('/bonds/' . $b['id'] . '/edit');
        }
        return $this->redirect('/bonds', 'success', t('bond.updated'));
    }

    public function delete(Request $r): Response
    {
        $b = $this->own($r);
        $this->svc()->delete((int) Auth::id(), (int) $b['id']);
        return $this->redirect('/bonds', 'success', t('bond.deleted'));
    }

    public function toggle(Request $r): Response
    {
        $b = $this->own($r);
        $this->svc()->toggle((int) Auth::id(), (int) $b['id']);
        return $this->redirect('/bonds', 'success', t('bond.toggled'));
    }

    public function showImport(Request $r): Response
    {
        return $this->view('user/bond_import', ['title' => t('bond.bulk'), 'types' => $this->enabledTypes(), 'report' => Session::pull('_import_report'), 'robots' => 'noindex']);
    }

    public function import(Request $r): Response
    {
        $this->bondRateLimit($r, 'bondimport', 20, 3600);
        $csv = trim((string) $r->post('bulk_text', ''));
        $f = $r->file('csv');
        if ($f) {
            if ($f['error'] !== UPLOAD_ERR_OK || $f['size'] > 1024 * 1024) {
                return $this->redirect('/bonds/import', 'danger', t('bond.err_upload'));
            }
            $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
            $ext = strtolower(pathinfo((string) $f['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['csv', 'txt'], true) || !in_array($mime, ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'], true)) {
                return $this->redirect('/bonds/import', 'danger', t('bond.err_upload_type'));
            }
            $csv = (string) file_get_contents($f['tmp_name']);     // read only; never stored or executed
        }
        if ($csv === '') {
            return $this->redirect('/bonds/import', 'danger', t('bond.err_upload'));
        }
        $report = $this->svc()->importCsv((int) Auth::id(), $r->int('bond_type'), $csv);
        Session::put('_import_report', $report);
        return Response::redirect('/bonds/import');
    }

    public function template(Request $r): Response
    {
        return Response::download("series,number,purchase_date\nKK,0046900,2025-01-15\nKL,0123456,\n,0000123,2024-12-01\n", 'prizebond_import_template.csv');
    }

    public function export(Request $r): Response
    {
        $rows = DB::all('SELECT bt.code, b.series, b.number, b.purchase_date, b.status FROM prize_bonds b JOIN bond_types bt ON bt.id = b.bond_type_id WHERE b.user_id = ? ORDER BY b.created_at, b.id', [(int) Auth::id()]);
        $out = "bond_type,series,number,purchase_date,status\n";
        foreach ($rows as $row) {
            // Prefix numbers so spreadsheet apps keep leading zeros and formula injection is neutralised.
            $out .= implode(',', [$row['code'], $this->csvCell($row['series']), '="' . $row['number'] . '"', $row['purchase_date'] ?? '', $row['status']]) . "\n";
        }
        return Response::download("\xEF\xBB\xBF" . $out, 'my_prize_bonds.csv');
    }

    private function csvCell(string $v): string
    {
        $v = str_replace('"', '""', $v);
        if ($v !== '' && in_array($v[0], ['=', '+', '-', '@'], true)) {
            $v = "'" . $v;
        }
        return '"' . $v . '"';
    }
}
