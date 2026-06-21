<?php
/**
 * WealthDash — t490: CSV Importer v3 Extensions
 * Path: api/mutual_funds/csv_v3_ext.php
 *
 * Extends mf_import_csv_v3.php (which handles core detect/import actions)
 * This file adds:
 *   csv_v3_sessions          — list past import sessions
 *   csv_v3_session_detail    — get one session with error rows
 *   csv_v3_preset_list       — list saved column mapping presets
 *   csv_v3_preset_save       — save a column mapping as preset
 *   csv_v3_preset_delete     — delete a preset
 *   csv_v3_preset_apply      — get preset mapping for reuse
 *   csv_v3_formats           — list all supported formats (also in core file, kept here for router)
 *   csv_v3_retry             — retry failed rows from a session
 */

if (!defined('WEALTHDASH')) {
    define('WEALTHDASH', true);
    ob_start();
    require_once dirname(dirname(dirname(__FILE__))) . '/config/config.php';
    require_once APP_ROOT . '/includes/auth_check.php';
    require_once APP_ROOT . '/includes/helpers.php';
    header('Content-Type: application/json; charset=utf-8');
}

defined('WEALTHDASH') or die();

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = $_POST['action'] ?? $_GET['action'] ?? '';

// ── All supported formats (mirrors mf_import_csv_v3.php) ─────────────────────
const CSV_FORMATS_EXT = [
    'wealthdash'   => ['label' => 'WealthDash Export',    'broker' => false, 'has_folio' => true],
    'cams'         => ['label' => 'CAMS Statement',        'broker' => false, 'has_folio' => true],
    'kfintech'     => ['label' => 'KFintech CAS',          'broker' => false, 'has_folio' => true],
    'kfintech_csv' => ['label' => 'KFintech CSV Export',  'broker' => false, 'has_folio' => true],
    'groww'        => ['label' => 'Groww Export',          'broker' => true,  'has_folio' => false],
    'zerodha'      => ['label' => 'Zerodha Coin',          'broker' => true,  'has_folio' => false],
    'kuvera'       => ['label' => 'Kuvera Export',         'broker' => true,  'has_folio' => false],
    'mfcentral'    => ['label' => 'MF Central',            'broker' => false, 'has_folio' => true],
    'mprofit'      => ['label' => 'MProfit Export',        'broker' => false, 'has_folio' => false],
    'angel_one'    => ['label' => 'Angel One / ARQ',       'broker' => true,  'has_folio' => false],
    'indmoney'     => ['label' => 'INDmoney',              'broker' => true,  'has_folio' => false],
    'paytm'        => ['label' => 'Paytm Money',           'broker' => true,  'has_folio' => false],
    'generic'      => ['label' => 'Generic (auto-mapped)', 'broker' => false, 'has_folio' => false],
];

switch ($action) {

// ── FORMATS ──────────────────────────────────────────────────────────────────
case 'csv_v3_formats': {
    $list = array_map(fn($k, $v) => array_merge(['id' => $k], $v), array_keys(CSV_FORMATS_EXT), CSV_FORMATS_EXT);
    json_response(true, '', ['formats' => $list, 'count' => count($list)]);
    break;
}

// ── SESSION LIST ─────────────────────────────────────────────────────────────
case 'csv_v3_sessions': {
    $limit = min((int)($_GET['limit'] ?? 20), 100);
    $stmt  = $db->prepare("
        SELECT id, filename, detected_format, format_label, confidence,
               total_data_rows, imported, skipped, errors, status, created_at
        FROM csv_import_v3_sessions
        WHERE user_id=? ORDER BY created_at DESC LIMIT ?
    ");
    $stmt->execute([$userId, $limit]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Summary stats
    $totalImported = array_sum(array_column($sessions, 'imported'));
    $totalFiles    = count($sessions);

    json_response(true, '', [
        'sessions'       => $sessions,
        'total_files'    => $totalFiles,
        'total_imported' => $totalImported,
    ]);
    break;
}

// ── SESSION DETAIL ───────────────────────────────────────────────────────────
case 'csv_v3_session_detail': {
    $sid = (int)($_GET['id'] ?? 0);
    if (!$sid) json_response(false, 'id required');

    $stmt = $db->prepare("SELECT * FROM csv_import_v3_sessions WHERE id=? AND user_id=?");
    $stmt->execute([$sid, $userId]);
    $s = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$s) json_response(false, 'Session not found');

    $s['col_mapping_json'] = $s['col_mapping_json'] ? json_decode($s['col_mapping_json'], true) : null;
    $s['preview_json']     = $s['preview_json']     ? json_decode($s['preview_json'],     true) : [];
    $s['error_rows_json']  = $s['error_rows_json']  ? json_decode($s['error_rows_json'],  true) : [];

    json_response(true, '', ['session' => $s]);
    break;
}

// ── PRESET LIST ──────────────────────────────────────────────────────────────
case 'csv_v3_preset_list': {
    $stmt = $db->prepare("
        SELECT id, name, format_hint, use_count, last_used, created_at
        FROM csv_column_mapping_presets
        WHERE user_id=? ORDER BY use_count DESC, last_used DESC
    ");
    $stmt->execute([$userId]);
    json_response(true, '', ['presets' => $stmt->fetchAll(PDO::FETCH_ASSOC)]);
    break;
}

// ── PRESET SAVE ──────────────────────────────────────────────────────────────
case 'csv_v3_preset_save': {
    $name        = clean($_POST['name'] ?? '');
    $formatHint  = clean($_POST['format_hint'] ?? '');
    $mappingJson = $_POST['mapping_json'] ?? '';

    if (!$name || !$mappingJson) json_response(false, 'name and mapping_json required');

    // Validate JSON
    $mapping = json_decode($mappingJson, true);
    if (!is_array($mapping)) json_response(false, 'mapping_json must be valid JSON');

    // Check limit (max 20 presets per user)
    $count = (int)$db->prepare("SELECT COUNT(*) FROM csv_column_mapping_presets WHERE user_id=?")
        ->execute([$userId]) ? $db->query("SELECT FOUND_ROWS()")->fetchColumn() : 0;

    $existingCount = $db->prepare("SELECT COUNT(*) FROM csv_column_mapping_presets WHERE user_id=?");
    $existingCount->execute([$userId]);
    if ((int)$existingCount->fetchColumn() >= 20) {
        json_response(false, 'Maximum 20 presets allowed. Delete old ones first.');
    }

    $db->prepare("
        INSERT INTO csv_column_mapping_presets (user_id, name, format_hint, mapping_json)
        VALUES (?,?,?,?)
        ON DUPLICATE KEY UPDATE mapping_json=VALUES(mapping_json), format_hint=VALUES(format_hint)
    ")->execute([$userId, $name, $formatHint ?: null, json_encode($mapping)]);

    json_response(true, 'Preset saved', ['id' => (int)$db->lastInsertId()]);
    break;
}

// ── PRESET DELETE ────────────────────────────────────────────────────────────
case 'csv_v3_preset_delete': {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'id required');
    $db->prepare("DELETE FROM csv_column_mapping_presets WHERE id=? AND user_id=?")->execute([$id, $userId]);
    json_response(true, 'Preset deleted');
    break;
}

// ── PRESET APPLY ─────────────────────────────────────────────────────────────
case 'csv_v3_preset_apply': {
    $id = (int)($_GET['id'] ?? 0);
    if (!$id) json_response(false, 'id required');

    $stmt = $db->prepare("SELECT * FROM csv_column_mapping_presets WHERE id=? AND user_id=?");
    $stmt->execute([$id, $userId]);
    $preset = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$preset) json_response(false, 'Preset not found');

    // Increment use count
    $db->prepare("UPDATE csv_column_mapping_presets SET use_count=use_count+1, last_used=NOW() WHERE id=?")
       ->execute([$id]);

    $preset['mapping'] = json_decode($preset['mapping_json'], true);
    unset($preset['mapping_json']);

    json_response(true, '', ['preset' => $preset]);
    break;
}

// ── SAVE SESSION (called after detect or import) ──────────────────────────────
case 'csv_v3_save_session': {
    // Called by JS after csv_v3_detect or csv_v3_import to persist session data
    $portfolioId    = (int)($_POST['portfolio_id'] ?? 0);
    $filename       = clean($_POST['filename'] ?? '');
    $fileSize       = (int)($_POST['file_size'] ?? 0);
    $detectedFormat = clean($_POST['detected_format'] ?? 'generic');
    $formatLabel    = clean($_POST['format_label'] ?? '');
    $confidence     = (int)($_POST['confidence'] ?? 0);
    $colMapping     = $_POST['col_mapping_json'] ?? '{}';
    $headerRowIdx   = (int)($_POST['header_row_index'] ?? 0);
    $totalDataRows  = (int)($_POST['total_data_rows'] ?? 0);
    $previewJson    = $_POST['preview_json'] ?? '[]';
    $actionType     = clean($_POST['session_action'] ?? 'detect');
    $imported       = (int)($_POST['imported'] ?? 0);
    $skipped        = (int)($_POST['skipped'] ?? 0);
    $errors         = (int)($_POST['errors'] ?? 0);
    $errorRowsJson  = $_POST['error_rows_json'] ?? '[]';
    $status         = clean($_POST['status'] ?? 'detected');

    $db->prepare("
        INSERT INTO csv_import_v3_sessions
            (user_id, portfolio_id, filename, file_size, detected_format, format_label,
             confidence, col_mapping_json, header_row_index, total_data_rows, preview_json,
             action, imported, skipped, errors, error_rows_json, status)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ")->execute([
        $userId, $portfolioId ?: null, $filename, $fileSize,
        $detectedFormat, $formatLabel, $confidence,
        $colMapping, $headerRowIdx, $totalDataRows, $previewJson,
        $actionType, $imported, $skipped, $errors, $errorRowsJson, $status
    ]);

    json_response(true, 'Session saved', ['session_id' => (int)$db->lastInsertId()]);
    break;
}

// ── RETRY FAILED ROWS ────────────────────────────────────────────────────────
case 'csv_v3_retry': {
    $sid = (int)($_POST['session_id'] ?? 0);
    if (!$sid) json_response(false, 'session_id required');

    $stmt = $db->prepare("SELECT * FROM csv_import_v3_sessions WHERE id=? AND user_id=?");
    $stmt->execute([$sid, $userId]);
    $session = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$session) json_response(false, 'Session not found');

    $errorRows = json_decode($session['error_rows_json'] ?? '[]', true);
    if (empty($errorRows)) json_response(true, 'No error rows to retry', ['retried' => 0]);

    // Return the error rows so JS can re-show them
    json_response(true, '', [
        'error_rows'    => $errorRows,
        'count'         => count($errorRows),
        'col_mapping'   => json_decode($session['col_mapping_json'] ?? '{}', true),
        'format'        => $session['detected_format'],
        'instructions'  => 'Fix the errors in these rows and re-import',
    ]);
    break;
}

// ── STATS ────────────────────────────────────────────────────────────────────
case 'csv_v3_stats': {
    $stmt = $db->prepare("
        SELECT
            COUNT(*)            AS total_sessions,
            SUM(imported)       AS total_imported,
            SUM(errors)         AS total_errors,
            detected_format,
            COUNT(*)            AS format_count
        FROM csv_import_v3_sessions
        WHERE user_id=? AND status='imported'
        GROUP BY detected_format
        ORDER BY format_count DESC
    ");
    $stmt->execute([$userId]);
    $byFormat = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $totalStmt = $db->prepare("SELECT COUNT(*), SUM(imported), SUM(errors) FROM csv_import_v3_sessions WHERE user_id=?");
    $totalStmt->execute([$userId]);
    [$totalSessions, $totalImported, $totalErrors] = $totalStmt->fetch(PDO::FETCH_NUM);

    json_response(true, '', [
        'total_sessions' => (int)$totalSessions,
        'total_imported' => (int)$totalImported,
        'total_errors'   => (int)$totalErrors,
        'by_format'      => $byFormat,
    ]);
    break;
}

default:
    json_response(false, "Unknown action: {$action}");
}
