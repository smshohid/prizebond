<?php
declare(strict_types=1);

namespace App\Services;

use Smalot\PdfParser\Parser;

/**
 * Parses Bangladesh Bank Tk.100 prize bond draw documents.
 *
 * Observed official layout (draws 118-125): the PDFs use a legacy Bangla font (SutonnyMJ), so
 * Bangla words come out as ASCII-ish gibberish, but ALL digits, amounts and winning numbers are
 * plain ASCII. The parser therefore relies only on numeric structure:
 *   - "<ordinal> ... <amount>/-" (or "/=") header per prize rank (1..5)
 *   - 7-digit general numbers following each header
 *   - "<N> ... <amount> ..." summary lines giving the series count
 * Nothing here is source-authoritative: output is STAGED data and must pass ResultValidator.
 */
final class PDFResultParser
{
    public const VERSION = 'bb-pdf-1.0';

    /** Legacy-font month tokens observed in official PDFs (best effort; day/year are cross-checked separately). */
    private const MONTHS = [
        'Rvbyqvwi' => 1, '‡deªæqvwi' => 2, 'gvP©' => 3, 'GwcÖj' => 4, '‡g' => 5, 'Ryb' => 6,
        'RyjvB' => 7, 'AvM÷' => 8, '‡m‡Þ¤^i' => 9, 'A‡±vei' => 10, 'b‡f¤^i' => 11, 'wW‡m¤^i' => 12,
    ];

    public function parseFile(string $path): array
    {
        $text = (new Parser())->parseFile($path)->getText();
        return $this->parseText($text);
    }

    public function parseText(string $text): array
    {
        $text = str_replace(["\r\n", "\r"], "\n", $text);
        $warnings = [];
        $out = [
            'parser' => self::VERSION,
            'draw_number' => null,
            'draw_date' => null,
            'draw_day' => null,
            'draw_month' => null,
            'draw_year' => null,
            'series_count' => null,
            'tiers' => [],
            'warnings' => [],
        ];

        if (preg_match('/(\d{2,3})\s?Zg\b/u', $text, $m)) {
            $out['draw_number'] = (int) $m[1];
        }

        if (preg_match('/\/\s*(\d{1,2})\s+(\S+?),\s*(20\d{2})/u', $text, $m)) {
            $out['draw_day'] = (int) $m[1];
            $out['draw_year'] = (int) $m[3];
            $month = self::MONTHS[$m[2]] ?? null;
            $out['draw_month'] = $month;
            if ($month && checkdate($month, $out['draw_day'], $out['draw_year'])) {
                $out['draw_date'] = sprintf('%04d-%02d-%02d', $out['draw_year'], $month, $out['draw_day']);
            } else {
                $warnings[] = 'Draw month could not be decoded from the legacy-font text; confirm the date against the source listing.';
            }
        } else {
            $warnings[] = 'Draw date not found in document.';
        }

        $tiers = [];
        $current = null;
        $started = false;
        $lines = array_values(array_filter(array_map('trim', explode("\n", $text)), fn($l) => $l !== ''));
        // Layout variation (seen in draw 115): the prize heading wraps, e.g. "1g cyiÂ¯â€¹vi" / "Uvt 6,00,000/= 0721593".
        // Re-join a bare "<ordinal> <word>" heading with the following line before parsing.
        for ($i = 0; $i < count($lines) - 1; $i++) {
            if (preg_match('/^[1-9]\S{1,3}\s+[^\d\/]{1,25}$/u', $lines[$i]) && preg_match('/^\S{1,4}\s+\d{1,3}(?:,\d{2,3})+\s*\/[-=]/u', $lines[$i + 1])) {
                $lines[$i] .= ' ' . $lines[$i + 1];
                array_splice($lines, $i + 1, 1);
            }
        }
        foreach ($lines as $line) {
            if (preg_match('/^([1-9])\S{1,3}\s+\S+.*?(\d{1,3}(?:,\d{2,3})+)\s*\/[-=](.*)$/u', $line, $m)) {
                $code = $m[1];
                if (isset($tiers[$code])) {
                    $warnings[] = "Prize rank $code appears more than once.";
                }
                $started = true;
                $current = $code;
                $tiers[$code] = [
                    'amount' => (int) str_replace(',', '', $m[2]),
                    'expected_count' => null,
                    'numbers' => [],
                ];
                $rest = $m[3];
                if (preg_match('/(\d+)\s*wU\s*[|।]?\s*$/u', $rest, $c)) {
                    $tiers[$code]['expected_count'] = (int) $c[1];
                }
                preg_match_all('/(?<![\d,])\d{7}(?![\d,])/', $rest, $nums);
                array_push($tiers[$code]['numbers'], ...$nums[0]);
                continue;
            }
            if ($started && str_starts_with($line, '[')) {
                $current = null; // end of winning-number block
                continue;
            }
            if ($current !== null) {
                if (preg_match('/(\d+)\s*wU\s*[|।]?\s*$/u', $line, $c) && $tiers[$current]['expected_count'] === null && !preg_match('/\d{7}/', $line)) {
                    $tiers[$current]['expected_count'] = (int) $c[1];
                }
                preg_match_all('/(?<![\d,])\d{7}(?![\d,])/', $line, $nums);
                array_push($tiers[$current]['numbers'], ...$nums[0]);
            } elseif ($started && preg_match('/^(\d{1,3})\s+\S{1,4}\s+\S{1,4}\s+(\d{1,3}(?:,\d{2,3})+)\s/u', $line, $s)) {
                // Summary row, e.g. "86 wU Uvt 6,00,000 gvbvi ..." => number of series
                $first = $tiers['1']['amount'] ?? null;
                if ($first !== null && (int) str_replace(',', '', $s[2]) === $first && $out['series_count'] === null) {
                    $out['series_count'] = (int) $s[1];
                }
            }
        }
        ksort($tiers);
        $out['tiers'] = $tiers;
        if (!$tiers) {
            $warnings[] = 'No prize rank blocks recognised; document layout may have changed.';
        }
        $out['warnings'] = $warnings;
        return $out;
    }
}
