<?php
declare(strict_types=1);

namespace App\Services;

use App\Helpers\BondNumber;

interface ResultSourceAdapter
{
    public function getSourceUrl(): string;

    /** @return array<int,array{draw_number:int,label:string,nominal_date:?string,url:string}> newest first */
    public function fetchLatest(): array;

    /** @return array{status:int,body:string,checksum:string,url:string,format:string} */
    public function fetchDocument(string $url): array;

    public function extractDrawMetadata(array $document): array;

    public function extractWinningNumbers(array $document): array;

    public function validateSource(): bool;
}

/** Bangladesh Bank - authoritative source for Tk.100 prize bond draw results (PDF per draw). */
final class BangladeshBankAdapter implements ResultSourceAdapter
{
    public const URL = 'https://www.bb.org.bd/en/index.php/investfacility/prizebond';
    public const BOND_TYPE = 'BDT100';

    public function __construct(private ?HttpClient $http = null, private ?PDFResultParser $parser = null)
    {
        $this->http ??= new HttpClient();
        $this->parser ??= new PDFResultParser();
    }

    public function getSourceUrl(): string
    {
        return self::URL;
    }

    public function fetchLatest(): array
    {
        $res = $this->http->get(self::URL);
        if ($res['status'] !== 200 || $res['body'] === '') {
            throw new \RuntimeException('Bangladesh Bank page returned HTTP ' . $res['status']);
        }
        $list = $this->parseListing($res['body']);
        if (!$list) {
            throw new \RuntimeException('No draw links recognised on the Bangladesh Bank page; page layout may have changed.');
        }
        return $list;
    }

    public function parseListing(string $html): array
    {
        $out = [];
        if (!preg_match_all('/<a\b[^>]*loadPDFDynamically\(["\']([^"\']+\.pdf)["\']\)[^>]*>(.*?)<\/a>/is', $html, $mm, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($mm as $m) {
            $url = html_entity_decode($m[1]);
            $label = trim(preg_replace('/\s+/', ' ', strip_tags($m[2])));
            if (!preg_match('/(\d{1,3})(?:st|nd|rd|th)draw\.pdf$/i', $url, $d)) {
                continue;
            }
            $n = (int) $d[1];
            $date = null;
            if (preg_match('/\((\d{1,2})(?:st|nd|rd|th)?\s+([A-Za-z]+)\s+(\d{4})\)/', $label, $dm)) {
                $ts = strtotime($dm[1] . ' ' . $dm[2] . ' ' . $dm[3] . ' UTC');
                $date = $ts ? gmdate('Y-m-d', $ts) : null;
            }
            $out[$n] = ['draw_number' => $n, 'label' => $label, 'nominal_date' => $date, 'url' => $url];
        }
        krsort($out);
        return array_values($out);
    }

    public function fetchDocument(string $url): array
    {
        $res = $this->http->get($url);
        if ($res['status'] !== 200) {
            throw new \RuntimeException("Document fetch failed with HTTP {$res['status']}: $url");
        }
        if (!str_starts_with($res['body'], '%PDF-')) {
            throw new \RuntimeException('Fetched document is not a PDF.');
        }
        return ['status' => 200, 'body' => $res['body'], 'checksum' => hash('sha256', $res['body']), 'url' => $url, 'format' => 'pdf'];
    }

    public function extractDrawMetadata(array $document): array
    {
        $s = $this->extractWinningNumbers($document);
        return ['draw_number' => $s['draw_number'], 'draw_date' => $s['draw_date'], 'series_count' => $s['series_count']];
    }

    public function extractWinningNumbers(array $document): array
    {
        $tmp = tempnam(sys_get_temp_dir(), 'pbr');
        try {
            file_put_contents($tmp, $document['body']);
            return $this->parser->parseFile($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    public function validateSource(): bool
    {
        try {
            return $this->fetchLatest() !== [];
        } catch (\Throwable) {
            return false;
        }
    }
}

/** IRD PBRIS - secondary verification (number look-up form; no bulk result listing exists). */
final class IRDAdapter
{
    public const URL = 'https://prizebond.ird.gov.bd/';
    private const ENDPOINT = 'https://prizebond.ird.gov.bd/hybrid_action_b2e.php';

    public function __construct(private ?HttpClient $http = null)
    {
        $this->http ??= new HttpClient();
    }

    public function getSourceUrl(): string
    {
        return self::URL;
    }

    /**
     * Look up general numbers. @return array<string,array<int,array{tier:?string,amount:int,date:string}>> number => rows
     */
    public function lookup(array $numbers): array
    {
        $res = $this->http->post(self::ENDPOINT, ['from' => implode(',', $numbers)]);
        if ($res['status'] !== 200) {
            throw new \RuntimeException('IRD returned HTTP ' . $res['status']);
        }
        return $this->parseResults($res['body']);
    }

    public function parseResults(string $html): array
    {
        $out = [];
        if (!preg_match_all('/<tr>\s*<td>([^<]+)<\/td>\s*<td>([^<]*)<\/td>\s*<td>([^<]*)<\/td>\s*<td>([^<]*)<\/td>/u', $html, $rows, PREG_SET_ORDER)) {
            return [];
        }
        foreach ($rows as $r) {
            $num = preg_replace('/\D/', '', BondNumber::asciiDigits(trim($r[1])));
            $tier = preg_match('/^\d/', BondNumber::asciiDigits(trim($r[2])), $t) ? $t[0] : null;
            $amount = (int) preg_replace('/\D/', '', BondNumber::asciiDigits($r[3]));
            $date = BondNumber::asciiDigits(trim($r[4]));
            if ($num === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                continue;
            }
            $out[$num][] = ['tier' => $tier, 'amount' => $amount, 'date' => $date];
        }
        return $out;
    }
}
