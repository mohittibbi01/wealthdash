<?php
/**
 * WealthDash — Setup Download Handler
 * Direct access endpoint (NOT via router) — streams file downloads.
 *
 * Usage:
 *   /api/admin/db_setup_download.php?type=schema
 *   /api/admin/db_setup_download.php?type=seed
 *   /api/admin/db_setup_download.php?type=env
 *   /api/admin/db_setup_download.php?type=db_status  (JSON, for AJAX)
 */

define('WEALTHDASH', true);
require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
require_once APP_ROOT . '/includes/auth_check.php';

// ── Auth: admin only ──────────────────────────────────────────────────────────
if (!is_logged_in() || !is_admin()) {
    http_response_code(403);
    die(json_encode(['error' => 'Admin access required.']));
}

$type = trim($_GET['type'] ?? '');

match ($type) {
    'schema'    => downloadSchema(),
    'seed'      => downloadSeed(),
    'env'       => downloadEnvTemplate(),
    'db_status' => sendDbStatus(),
    default     => http_response_code(400) && die(json_encode(['error' => 'Unknown type.'])),
};

// ═════════════════════════════════════════════════════════════════════════════
// SCHEMA — dynamically generate CREATE TABLE SQL from live DB
// ═════════════════════════════════════════════════════════════════════════════
function downloadSchema(): void {
    $db     = DB::conn();
    $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
    $ts     = date('Y-m-d H:i:s');
    $fname  = 'wealthdash_schema_' . date('Ymd_His') . '.sql';

    $out  = "-- ════════════════════════════════════════════════════════════════\n";
    $out .= "-- WealthDash · Schema Backup\n";
    $out .= "-- Database  : {$dbName}\n";
    $out .= "-- Generated : {$ts}\n";
    $out .= "-- Server    : " . ($_SERVER['SERVER_NAME'] ?? 'localhost') . "\n";
    $out .= "-- ────────────────────────────────────────────────────────────────\n";
    $out .= "-- USAGE: phpMyAdmin → New DB 'wealthdash' → Import → this file\n";
    $out .= "-- Import seed.sql AFTER this file.\n";
    $out .= "-- ════════════════════════════════════════════════════════════════\n\n";

    $out .= "SET SQL_MODE = 'NO_AUTO_VALUE_ON_ZERO';\n";
    $out .= "SET FOREIGN_KEY_CHECKS = 0;\n";
    $out .= "SET NAMES utf8mb4;\n";
    $out .= "START TRANSACTION;\n\n";

    $out .= "CREATE DATABASE IF NOT EXISTS `{$dbName}`\n";
    $out .= "  DEFAULT CHARACTER SET utf8mb4\n";
    $out .= "  DEFAULT COLLATE utf8mb4_unicode_ci;\n";
    $out .= "USE `{$dbName}`;\n\n";

    // ── Tables ────────────────────────────────────────────────────────────────
    $tables = $db->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'
         ORDER BY TABLE_NAME"
    )->fetchAll(\PDO::FETCH_COLUMN);

    foreach ($tables as $tbl) {
        $out .= "-- ──────────────────────────────────────────────────────────\n";
        $out .= "-- Table: `{$tbl}`\n";
        $out .= "-- ──────────────────────────────────────────────────────────\n";
        $out .= "DROP TABLE IF EXISTS `{$tbl}`;\n";

        $row    = $db->query("SHOW CREATE TABLE `{$tbl}`")->fetch(\PDO::FETCH_NUM);
        $create = $row[1] ?? '';
        $out   .= $create . ";\n\n";
    }

    // ── Views ─────────────────────────────────────────────────────────────────
    $views = $db->query(
        "SELECT TABLE_NAME FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'VIEW'
         ORDER BY TABLE_NAME"
    )->fetchAll(\PDO::FETCH_COLUMN);

    if (!empty($views)) {
        $out .= "-- ──────────────────────────────────────────────────────────\n";
        $out .= "-- VIEWS\n";
        $out .= "-- ──────────────────────────────────────────────────────────\n";
        foreach ($views as $v) {
            $row  = $db->query("SHOW CREATE VIEW `{$v}`")->fetch(\PDO::FETCH_ASSOC);
            $out .= "DROP VIEW IF EXISTS `{$v}`;\n";
            $out .= ($row['Create View'] ?? '') . ";\n\n";
        }
    }

    $out .= "SET FOREIGN_KEY_CHECKS = 1;\n";
    $out .= "COMMIT;\n";

    streamDownload($fname, $out);
}

// ═════════════════════════════════════════════════════════════════════════════
// SEED — serve database/seed.sql directly
// ═════════════════════════════════════════════════════════════════════════════
function downloadSeed(): void {
    $path = APP_ROOT . '/database/seed.sql';
    if (!file_exists($path)) {
        http_response_code(404);
        die(json_encode(['error' => 'seed.sql not found at ' . $path]));
    }
    $content = file_get_contents($path);

    // Prepend a header note
    $header  = "-- ════════════════════════════════════════════════════════════════\n";
    $header .= "-- WealthDash · Seed Data\n";
    $header .= "-- Generated : " . date('Y-m-d H:i:s') . "\n";
    $header .= "-- USAGE     : Import AFTER schema.sql in the same DB.\n";
    $header .= "-- ════════════════════════════════════════════════════════════════\n\n";

    streamDownload('wealthdash_seed_' . date('Ymd') . '.sql', $header . $content);
}

// ═════════════════════════════════════════════════════════════════════════════
// ENV TEMPLATE — serve .env.example
// ═════════════════════════════════════════════════════════════════════════════
function downloadEnvTemplate(): void {
    $path = APP_ROOT . '/.env.example';
    if (!file_exists($path)) {
        http_response_code(404);
        die(json_encode(['error' => '.env.example not found.']));
    }
    streamDownload('wealthdash_env_template.txt', file_get_contents($path));
}

// ═════════════════════════════════════════════════════════════════════════════
// DB STATUS — JSON for AJAX status check
// ═════════════════════════════════════════════════════════════════════════════
function sendDbStatus(): void {
    header('Content-Type: application/json; charset=UTF-8');
    try {
        $db     = DB::conn();
        $dbName = $db->query("SELECT DATABASE()")->fetchColumn();
        $tables = $db->query("SHOW TABLES")->fetchAll(\PDO::FETCH_COLUMN);

        // Key tables row counts
        $keyTables = [
            'users', 'portfolios', 'mf_holdings', 'mf_transactions',
            'funds', 'stock_holdings', 'fd_accounts', 'nps_holdings',
        ];
        $counts = [];
        foreach ($keyTables as $t) {
            if (in_array($t, $tables)) {
                $counts[$t] = (int) $db->query("SELECT COUNT(*) FROM `{$t}`")->fetchColumn();
            }
        }

        // Seed status: fund_houses and nps_schemes are seed tables
        $fundHousesOk  = in_array('fund_houses', $tables)
            && (int)$db->query("SELECT COUNT(*) FROM `fund_houses`")->fetchColumn() > 0;
        $npsOk         = in_array('nps_schemes', $tables)
            && (int)$db->query("SELECT COUNT(*) FROM `nps_schemes`")->fetchColumn() > 0;
        $stockMasterOk = in_array('stock_master', $tables)
            && (int)$db->query("SELECT COUNT(*) FROM `stock_master`")->fetchColumn() > 0;

        echo json_encode([
            'ok'          => true,
            'db_name'     => $dbName,
            'table_count' => count($tables),
            'tables'      => $tables,
            'row_counts'  => $counts,
            'seed_status' => [
                'fund_houses'  => $fundHousesOk,
                'nps_schemes'  => $npsOk,
                'stock_master' => $stockMasterOk,
            ],
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ─── Helper ───────────────────────────────────────────────────────────────────
function streamDownload(string $filename, string $content): void {
    // Clear any buffered output (config.php may have buffered warnings)
    while (ob_get_level()) ob_end_clean();

    header('Content-Type: application/octet-stream');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($content));
    header('Cache-Control: no-cache, no-store, must-revalidate');
    header('Pragma: no-cache');
    header('Expires: 0');
    echo $content;
    exit;
}
