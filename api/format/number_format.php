<?php
/**
 * WealthDash — PHP Indian Number Formatting Helper
 * Task: t347 | Server-side Indian number formatting
 */

class WDNumberFormat
{
    public static function format(
        $num,
        int  $decimals = 2,
        bool $symbol   = false,
        bool $compact  = false,
        bool $signed   = false
    ): string {
        if ($num === null || !is_numeric($num)) return '—';

        $num    = (float) $num;
        $absVal = abs($num);
        $sign   = $num < 0 ? '-' : ($signed && $num > 0 ? '+' : '');
        $prefix = $symbol ? '₹' : '';

        if ($compact) {
            if ($absVal >= 1e7) return "{$sign}{$prefix}" . number_format($absVal / 1e7, 2) . 'Cr';
            if ($absVal >= 1e5) return "{$sign}{$prefix}" . number_format($absVal / 1e5, 2) . 'L';
            if ($absVal >= 1e3) return "{$sign}{$prefix}" . number_format($absVal / 1e3, 1) . 'K';
            // Below 1K — fall through to full Indian formatting below
        }

        return "{$sign}{$prefix}" . self::indianGrouping($absVal, $decimals);
    }

    public static function currency($num, int $decimals = 2): string
    {
        return self::format($num, $decimals, symbol: true);
    }

    public static function compact($num): string
    {
        return self::format($num, 2, symbol: true, compact: true);
    }

    public static function plain($num, int $decimals = 2): string
    {
        return self::format($num, $decimals);
    }

    public static function percent($num, int $decimals = 2, bool $signed = false): string
    {
        if ($num === null || !is_numeric($num)) return '—';
        $num  = (float) $num;
        $sign = $signed && $num > 0 ? '+' : '';
        return $sign . number_format($num, $decimals) . '%';
    }

    public static function units($num): string
    {
        return self::format($num, 3);
    }

    private static function indianGrouping(float $absVal, int $decimals): string
    {
        $fixed  = number_format($absVal, $decimals, '.', '');
        $parts  = explode('.', $fixed);
        $int    = $parts[0];
        $dec    = $parts[1] ?? null;

        $len = strlen($int);
        if ($len <= 3) {
            $grouped = $int;
        } else {
            $last3  = substr($int, -3);
            $remain = substr($int, 0, $len - 3);
            $chunks = [];
            while (strlen($remain) > 2) {
                array_unshift($chunks, substr($remain, -2));
                $remain = substr($remain, 0, -2);
            }
            if ($remain !== '') array_unshift($chunks, $remain);
            $grouped = implode(',', $chunks) . ',' . $last3;
        }

        return $dec !== null ? "{$grouped}.{$dec}" : $grouped;
    }

    /**
     * Batch-format rows from DB queries.
     * $fields = ['invested' => ['type'=>'currency'], 'returns_pct' => ['type'=>'percent','signed'=>true]]
     */
    public static function formatRow(array $row, array $fields): array
    {
        foreach ($fields as $key => $opts) {
            if (!array_key_exists($key, $row)) continue;
            $type     = $opts['type']     ?? 'plain';
            $decimals = $opts['decimals'] ?? 2;
            $signed   = $opts['signed']   ?? false;

            $row[$key . '_fmt'] = match ($type) {
                'currency' => self::currency($row[$key], $decimals),
                'compact'  => self::compact($row[$key]),
                'percent'  => self::percent($row[$key], $decimals, $signed),
                'units'    => self::units($row[$key]),
                default    => self::plain($row[$key], $decimals),
            };
        }
        return $row;
    }
}
