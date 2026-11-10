<?php
declare(strict_types=1);

namespace App\Controllers;

use App\Core\DB;
use App\Core\Lang;
use App\Core\Request;
use App\Core\Response;
use App\Helpers\BondNumber;
use App\Services\DrawService;
use App\Services\MatchingService;
use App\Services\PublicCache;

final class HomeController extends Controller
{
    public const PAGES = ['how-it-works', 'faq', 'about', 'contact', 'privacy', 'terms'];

    public function index(Request $r): Response
    {
        $stats = [
            'draws' => (int) DB::val("SELECT COUNT(*) FROM prize_draws WHERE publication_status = 'published'"),
            'bonds' => (int) DB::val('SELECT COUNT(*) FROM prize_bonds'),
            'users' => (int) DB::val("SELECT COUNT(*) FROM users WHERE email_verified_at IS NOT NULL AND status = 'active'"),
            'matches' => (int) DB::val("SELECT COUNT(*) FROM prize_matches WHERE match_status = 'detected'"),
        ];
        $latest = PublicCache::remember('home.latest', 300, function () {
            $d = DB::one("SELECT d.*, bt.name AS bond_name FROM prize_draws d JOIN bond_types bt ON bt.id = d.bond_type_id WHERE d.publication_status = 'published' ORDER BY d.draw_date DESC, d.draw_number DESC LIMIT 1");
            return $d ? ['draw' => $d, 'numbers' => $this->numbersByTier((int) $d['id'])] : null;
        });
        return $this->view('public/home', [
            'title' => t('site.tagline'), 'desc' => t('meta.home'), 'stats' => $stats, 'latest' => $latest, 'types' => $this->enabledTypes(), 'canonical' => '/',
        ]);
    }

    public function numbersByTier(int $drawId): array
    {
        $rows = DB::all("SELECT pt.id AS tier_id, pt.name, pt.name_bn, pt.rank_order, wn.amount, wn.number FROM winning_numbers wn
                         JOIN prize_tiers pt ON pt.id = wn.prize_tier_id WHERE wn.draw_id = ? AND wn.status = 'active' ORDER BY pt.rank_order, wn.number", [$drawId]);
        $out = [];
        foreach ($rows as $row) {
            $out[$row['tier_id']]['name'] = $row['name'];
            $out[$row['tier_id']]['name_bn'] = $row['name_bn'];
            $out[$row['tier_id']]['amount'] = $row['amount'];
            $out[$row['tier_id']]['numbers'][] = $row['number'];
        }
        return array_values($out);
    }

    public function check(Request $r): Response
    {
        $types = $this->enabledTypes();
        $result = null;
        $error = null;
        $number = $r->str('number');
        if ($number !== '' || $r->get('bond_type')) {
            $this->bondRateLimit($r, 'publiccheck', 40, 600);
            $typeId = $r->int('bond_type', (int) ($types[0]['id'] ?? 0));
            $type = null;
            foreach ($types as $t) {
                if ((int) $t['id'] === $typeId) {
                    $type = $t;
                }
            }
            if (!$type) {
                $error = 'bond.err_type';
            } else {
                [$n, $err] = BondNumber::number($number, (int) $type['number_length']);
                [$s, $serr] = BondNumber::series($r->str('series'));
                $error = $err ?? $serr;
                if (!$error) {
                    $result = ['number' => $n, 'series' => $s, 'type' => $type, 'wins' => (new MatchingService())->checkNumber((int) $type['id'], $n, $s),
                        'checked' => (int) DB::val("SELECT COUNT(*) FROM prize_draws WHERE bond_type_id = ? AND publication_status = 'published'", [$type['id']])];
                }
            }
        }
        return $this->view('public/check', ['title' => t('nav.check'), 'desc' => t('meta.check'), 'types' => $types, 'result' => $result, 'error' => $error, 'canonical' => '/check']);
    }

    public function results(Request $r): Response
    {
        $total = (int) DB::val("SELECT COUNT(*) FROM prize_draws WHERE publication_status = 'published'");
        $p = $this->pagination($r, $total, 20);
        $draws = PublicCache::remember('results.' . $p['page'], 600, fn() => DB::all(
            "SELECT d.id, d.draw_number, d.draw_date, d.series_count, bt.name AS bond_name, (SELECT COUNT(*) FROM winning_numbers w WHERE w.draw_id = d.id AND w.status = 'active') AS cnt
             FROM prize_draws d JOIN bond_types bt ON bt.id = d.bond_type_id WHERE d.publication_status = 'published'
             ORDER BY d.draw_date DESC, d.draw_number DESC LIMIT ? OFFSET ?", [$p['per'], $p['offset']]));
        return $this->view('public/results', ['title' => t('nav.results'), 'desc' => t('meta.results'), 'draws' => $draws, 'pg' => $p, 'canonical' => '/results']);
    }

    public function draw(Request $r): Response
    {
        $id = (int) $r->params['id'];
        $d = DB::one("SELECT d.*, bt.name AS bond_name FROM prize_draws d JOIN bond_types bt ON bt.id = d.bond_type_id WHERE d.id = ? AND d.publication_status = 'published'", [$id]);
        if (!$d) {
            $this->notFound();
        }
        $tiers = PublicCache::remember('draw.' . $id . '.v' . $d['version'], 3600, fn() => $this->numbersByTier($id));
        return $this->view('public/draw', ['title' => t('draw.title', ['n' => $d['draw_number']]), 'desc' => t('meta.draw', ['n' => $d['draw_number'], 'date' => fmt_date($d['draw_date'])]),
            'draw' => $d, 'tiers' => $tiers, 'canonical' => '/results/' . $id]);
    }

    public function page(Request $r): Response
    {
        $slug = ltrim($r->path, '/');
        if (!in_array($slug, self::PAGES, true)) {
            $this->notFound();
        }
        return $this->view('public/page_' . str_replace('-', '_', $slug), ['title' => t('page.' . $slug . '.title'), 'desc' => t('meta.' . $slug), 'canonical' => '/' . $slug]);
    }

    public function lang(Request $r): Response
    {
        Lang::set((string) $r->params['code']);
        if ($u = \App\Core\Auth::id()) {
            DB::exec('UPDATE user_settings SET language = ?, updated_at = ? WHERE user_id = ?', [Lang::locale(), DB::now(), $u]);
        }
        $back = (string) ($r->server['HTTP_REFERER'] ?? '');
        $host = parse_url($back, PHP_URL_HOST);
        $own = parse_url((string) env('APP_URL', ''), PHP_URL_HOST) ?: ($r->server['HTTP_HOST'] ?? '');
        $target = ($back !== '' && $host === $own) ? $back : '/';
        return Response::redirect($target);
    }

    public function sitemap(Request $r): Response
    {
        $urls = ['/', '/check', '/results', ...array_map(fn($p) => '/' . $p, self::PAGES)];
        foreach (DB::all("SELECT id, updated_at FROM prize_draws WHERE publication_status = 'published' ORDER BY draw_number DESC") as $d) {
            $urls[] = '/results/' . $d['id'];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $u) {
            $xml .= '  <url><loc>' . e(app_url($u)) . "</loc></url>\n";
        }
        return Response::text($xml . '</urlset>', 'application/xml');
    }

    public function robots(Request $r): Response
    {
        return Response::text("User-agent: *\nDisallow: /dashboard\nDisallow: /bonds\nDisallow: /matches\nDisallow: /profile\nDisallow: /admin\nDisallow: /login\nDisallow: /register\nDisallow: /forgot-password\nDisallow: /reset-password\nDisallow: /verify-email\nAllow: /\n\nSitemap: " . app_url('sitemap.xml') . "\n");
    }
}
