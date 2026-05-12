<?php
/**
 * WealthDash — EPFO Passbook PDF Parser
 * t151: Parse text extracted from EPFO passbook PDF
 *
 * Input : plain text extracted from PDF (pdftotext or similar)
 * Output: array of passbook entry arrays
 *
 * Handles two known EPFO passbook formats:
 *   Format A — tabular with columns: Wage Month | Trrn Date | Description | EPF (E/ER) | EPS | Balance
 *   Format B — simpler row format from unified portal passbook download
 */
defined('WEALTHDASH') or die();

class EPFOPassbookParser
{
    /**
     * Parse raw PDF text into structured entries.
     * @return array{entries: array, meta: array, errors: array}
     */
    public static function parse(string $rawText): array
    {
        $lines   = preg_split('/\r?\n/', $rawText);
        $lines   = array_map('trim', $lines);
        $lines   = array_filter($lines, fn($l) => $l !== '');
        $lines   = array_values($lines);

        $meta    = self::extractMeta($lines);
        $entries = [];
        $errors  = [];

        // Detect format
        $format = self::detectFormat($lines);

        if ($format === 'A') {
            [$entries, $errors] = self::parseFormatA($lines);
        } else {
            [$entries, $errors] = self::parseFormatB($lines);
        }

        return compact('entries', 'meta', 'errors');
    }

    // ── Meta extraction ───────────────────────────────────────────────────────

    private static function extractMeta(array $lines): array
    {
        $meta = ['uan' => null, 'member_id' => null, 'name' => null, 'establishment' => null];
        foreach ($lines as $line) {
            if (preg_match('/UAN\s*[:\-]?\s*(\d{12})/i', $line, $m)) {
                $meta['uan'] = $m[1];
            }
            if (preg_match('/Member\s+ID\s*[:\-]?\s*([A-Z]{2}\/[A-Z]+\/\d+\/\d+\/\d+)/i', $line, $m)) {
                $meta['member_id'] = $m[1];
            }
            if (preg_match('/Name\s*[:\-]\s*(.+)/i', $line, $m)) {
                $meta['name'] = trim($m[1]);
            }
            if (preg_match('/Establishment\s*[:\-]\s*(.+)/i', $line, $m)) {
                $meta['establishment'] = trim($m[1]);
            }
        }
        return $meta;
    }

    // ── Format detection ──────────────────────────────────────────────────────

    private static function detectFormat(array $lines): string
    {
        foreach ($lines as $line) {
            if (stripos($line, 'Trrn Date') !== false || stripos($line, 'TRRN') !== false) {
                return 'A';
            }
        }
        return 'B';
    }

    // ── Format A parser ───────────────────────────────────────────────────────
    // Columns: Wage Month | Trrn Date | Description | EPF (Ee) | EPF (Er) | EPS (Er) | Balance

    private static function parseFormatA(array $lines): array
    {
        $entries = [];
        $errors  = [];
        $inTable = false;

        foreach ($lines as $i => $line) {
            // Detect table header row
            if (preg_match('/Wage\s+Month/i', $line)) {
                $inTable = true;
                continue;
            }
            if (!$inTable) continue;

            // Skip header separator lines
            if (preg_match('/^[-=]+$/', $line)) continue;

            // Match a data row: starts with a month/year or date pattern
            // Pattern: Apr-23 | 05/04/2023 | CONTRIBUTION | 1800.00 | 1258.00 | 541.00 | 25000.00
            if (!preg_match('/^([A-Za-z]{3}[-\/]\d{2,4}|\d{2}\/\d{2}\/\d{4})/', $line)) continue;

            // Split by 2+ spaces (PDF columns)
            $cols = preg_split('/\s{2,}/', $line);
            $cols = array_values(array_filter(array_map('trim', $cols)));

            if (count($cols) < 4) {
                $errors[] = "Skipped line ($i): too few columns — $line";
                continue;
            }

            try {
                $entry = self::mapFormatACols($cols);
                if ($entry) $entries[] = $entry;
            } catch (\Throwable $e) {
                $errors[] = "Parse error line $i: " . $e->getMessage();
            }
        }

        return [$entries, $errors];
    }

    private static function mapFormatACols(array $cols): ?array
    {
        // Col 0: wage month (Apr-23 or Apr-2023)
        $wageMonth = self::parseWageMonth($cols[0]);
        if (!$wageMonth) return null;

        // Col 1: transaction date (may be missing / merged with description)
        $txDate = null;
        $descIdx = 1;
        if (preg_match('/^\d{1,2}\/\d{1,2}\/\d{4}$/', $cols[1])) {
            $txDate  = date('Y-m-d', strtotime($cols[1]));
            $descIdx = 2;
        }

        $description = $cols[$descIdx] ?? 'CONTRIBUTION';

        // Numeric columns (last 4 or 3 values are amounts)
        $nums = [];
        for ($j = count($cols) - 1; $j >= $descIdx + 1; $j--) {
            if (preg_match('/^[\d,]+(\.\d{2})?$/', $cols[$j])) {
                array_unshift($nums, self::parseAmount($cols[$j]));
            } else break;
        }

        // Expect at least balance + one contribution
        if (count($nums) < 2) return null;

        $balance     = array_pop($nums);
        $epsEmployer = count($nums) >= 3 ? array_pop($nums) : 0.0;
        $epfEmployer = count($nums) >= 2 ? array_pop($nums) : 0.0;
        $epfEmployee = count($nums) >= 1 ? array_pop($nums) : 0.0;

        return [
            'wage_month'    => $wageMonth,
            'transaction_date' => $txDate,
            'description'   => strtoupper(trim($description)),
            'epf_employee'  => $epfEmployee,
            'epf_employer'  => $epfEmployer,
            'eps_employer'  => $epsEmployer,
            'interest'      => 0.0,
            'balance'       => $balance,
            'entry_type'    => self::inferType($description),
            'raw_ref'       => null,
            'source'        => 'pdf',
        ];
    }

    // ── Format B parser ───────────────────────────────────────────────────────

    private static function parseFormatB(array $lines): array
    {
        $entries = [];
        $errors  = [];

        foreach ($lines as $i => $line) {
            // Rows typically: 04/2023  CONTRIBUTION  1800  1258  541  25000
            if (!preg_match('/^(\d{2}\/\d{4})\s+(.+?)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)\s+([\d,]+\.?\d*)/', $line, $m)) {
                continue;
            }
            $wageMonth = self::parseWageMonth($m[1]);
            if (!$wageMonth) continue;

            $entries[] = [
                'wage_month'       => $wageMonth,
                'transaction_date' => null,
                'description'      => strtoupper(trim($m[2])),
                'epf_employee'     => self::parseAmount($m[3]),
                'epf_employer'     => self::parseAmount($m[4]),
                'eps_employer'     => self::parseAmount($m[5]),
                'interest'         => 0.0,
                'balance'          => self::parseAmount($m[6]),
                'entry_type'       => self::inferType($m[2]),
                'raw_ref'          => null,
                'source'           => 'pdf',
            ];
        }

        return [$entries, $errors];
    }

    // ── Helpers ───────────────────────────────────────────────────────────────

    private static function parseWageMonth(string $raw): ?string
    {
        $raw = trim($raw);
        // 04/2023
        if (preg_match('/^(\d{2})\/(\d{4})$/', $raw, $m)) {
            return $m[2] . '-' . $m[1] . '-01';
        }
        // Apr-23 or Apr-2023
        if (preg_match('/^([A-Za-z]{3})[-\/](\d{2,4})$/', $raw, $m)) {
            $year = strlen($m[2]) === 2 ? '20' . $m[2] : $m[2];
            $ts   = strtotime("01-{$m[1]}-{$year}");
            if ($ts) return date('Y-m-01', $ts);
        }
        return null;
    }

    private static function parseAmount(string $raw): float
    {
        return (float) str_replace(',', '', trim($raw));
    }

    private static function inferType(string $desc): string
    {
        $desc = strtoupper($desc);
        if (str_contains($desc, 'INTEREST'))    return 'interest';
        if (str_contains($desc, 'WITHDRAWAL'))  return 'withdrawal';
        if (str_contains($desc, 'TRANSFER'))    return 'transfer';
        if (str_contains($desc, 'OPENING'))     return 'opening';
        return 'contribution';
    }
}
