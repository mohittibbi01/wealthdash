<?php
/**
 * WealthDash Format Helper — global convenience functions
 * Task: t347 | Include once in bootstrap/index.php
 */

require_once __DIR__ . '/number_format.php';

if (!function_exists('wd_currency')) {
    function wd_currency($num, int $decimals = 2): string {
        return WDNumberFormat::currency($num, $decimals);
    }
}
if (!function_exists('wd_compact')) {
    function wd_compact($num): string {
        return WDNumberFormat::compact($num);
    }
}
if (!function_exists('wd_plain')) {
    function wd_plain($num, int $decimals = 2): string {
        return WDNumberFormat::plain($num, $decimals);
    }
}
if (!function_exists('wd_percent')) {
    function wd_percent($num, int $decimals = 2, bool $signed = false): string {
        return WDNumberFormat::percent($num, $decimals, $signed);
    }
}
if (!function_exists('wd_units')) {
    function wd_units($num): string {
        return WDNumberFormat::units($num);
    }
}
if (!function_exists('wd_fmt_row')) {
    function wd_fmt_row(array $row, array $fields): array {
        return WDNumberFormat::formatRow($row, $fields);
    }
}
