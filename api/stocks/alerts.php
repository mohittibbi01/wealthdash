<?php
/**
 * WealthDash — t344/t345: Stock Price Alerts API
 * GET  ?action=stocks_alert_list
 * POST ?action=stocks_alert_save   { stock_id, symbol, company_name, alert_type, target_price, note, notify_browser, notify_email }
 * POST ?action=stocks_alert_delete { id }
 * POST ?action=stocks_alert_toggle { id }
 * GET  ?action=stocks_alert_check  — check current prices vs active alerts
 */
declare(strict_types=1);
defined('WEALTHDASH') or die('Direct access not allowed.');

$currentUser = require_auth();
$userId      = (int)$currentUser['id'];
$db          = DB::conn();
$action      = $_GET['action'] ?? $_POST['action'] ?? 'list';

/* ── ensure table exists ─────────────────────────────────── */
try {
    $db->query("SELECT 1 FROM stock_price_alerts LIMIT 1");
} catch (Exception $e) {
    $db->exec("CREATE TABLE IF NOT EXISTS `stock_price_alerts` (
      `id`              INT UNSIGNED  NOT NULL AUTO_INCREMENT,
      `user_id`         INT UNSIGNED  NOT NULL,
      `stock_id`        INT UNSIGNED  NOT NULL,
      `symbol`          VARCHAR(30)   NOT NULL,
      `company_name`    VARCHAR(200)  DEFAULT NULL,
      `alert_type`      ENUM('above','below','pct_up','pct_down') NOT NULL DEFAULT 'above',
      `target_price`    DECIMAL(12,2) NOT NULL,
      `note`            VARCHAR(300)  DEFAULT NULL,
      `is_active`       TINYINT(1)    NOT NULL DEFAULT 1,
      `triggered_at`    DATETIME      DEFAULT NULL,
      `triggered_price` DECIMAL(12,2) DEFAULT NULL,
      `notify_browser`  TINYINT(1)    NOT NULL DEFAULT 1,
      `notify_email`    TINYINT(1)    NOT NULL DEFAULT 0,
      `created_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP,
      `updated_at`      TIMESTAMP     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
      PRIMARY KEY (`id`),
      KEY `idx_spa_user`  (`user_id`, `is_active`),
      KEY `idx_spa_stock` (`stock_id`),
      KEY `idx_spa_symbol`(`symbol`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/* ── LIST ────────────────────────────────────────────────── */
if ($action === 'stocks_alert_list') {
    $rows = DB::fetchAll(
        "SELECT a.*, sm.latest_price AS current_price, sm.latest_price_date
         FROM stock_price_alerts a
         LEFT JOIN stock_master sm ON sm.id = a.stock_id
         WHERE a.user_id = ?
         ORDER BY a.is_active DESC, a.created_at DESC",
        [$userId]
    );
    // Add distance % from target
    foreach ($rows as &$r) {
        $cp = (float)($r['current_price'] ?? 0);
        $tp = (float)$r['target_price'];
        $r['current_price']   = $cp;
        $r['distance_pct']    = $cp > 0 ? round(($tp - $cp) / $cp * 100, 2) : null;
        $r['is_triggered']    = !empty($r['triggered_at']);
    }
    json_response(true, '', $rows);
}

/* ── SAVE (create / update) ──────────────────────────────── */
if ($action === 'stocks_alert_save') {
    $stockId     = (int)($_POST['stock_id'] ?? 0);
    $symbol      = strtoupper(trim(clean($_POST['symbol'] ?? '')));
    $company     = trim(clean($_POST['company_name'] ?? ''));
    $alertType   = clean($_POST['alert_type'] ?? 'above');
    $targetPrice = (float)($_POST['target_price'] ?? 0);
    $note        = trim(clean($_POST['note'] ?? ''));
    $browser     = (int)($_POST['notify_browser'] ?? 1);
    $email       = (int)($_POST['notify_email'] ?? 0);
    $editId      = (int)($_POST['id'] ?? 0);

    if (!$stockId || !$symbol || $targetPrice <= 0) {
        json_response(false, 'Stock, symbol and target price are required.');
    }
    if (!in_array($alertType, ['above','below','pct_up','pct_down'])) {
        json_response(false, 'Invalid alert type.');
    }

    if ($editId) {
        // Update existing
        $stmt = $db->prepare(
            "UPDATE stock_price_alerts
             SET alert_type=?, target_price=?, note=?, notify_browser=?, notify_email=?,
                 is_active=1, triggered_at=NULL, triggered_price=NULL
             WHERE id=? AND user_id=?"
        );
        $stmt->execute([$alertType, $targetPrice, $note ?: null, $browser, $email, $editId, $userId]);
        json_response(true, 'Alert updated.', ['id' => $editId]);
    }

    // Check duplicate active alert for same stock + type
    $dup = $db->prepare(
        "SELECT id FROM stock_price_alerts WHERE user_id=? AND stock_id=? AND alert_type=? AND is_active=1"
    );
    $dup->execute([$userId, $stockId, $alertType]);
    if ($dup->fetch()) {
        // update target instead of duplicate
        $upd = $db->prepare(
            "UPDATE stock_price_alerts
             SET target_price=?, note=?, notify_browser=?, notify_email=?, triggered_at=NULL
             WHERE user_id=? AND stock_id=? AND alert_type=? AND is_active=1"
        );
        $upd->execute([$targetPrice, $note ?: null, $browser, $email, $userId, $stockId, $alertType]);
        $id = (int)$db->lastInsertId() ?: (int)$dup->fetchColumn();
        json_response(true, 'Alert target updated.', ['id' => $id]);
    }

    $ins = $db->prepare(
        "INSERT INTO stock_price_alerts
         (user_id, stock_id, symbol, company_name, alert_type, target_price, note, notify_browser, notify_email)
         VALUES (?,?,?,?,?,?,?,?,?)"
    );
    $ins->execute([$userId, $stockId, $symbol, $company ?: null, $alertType, $targetPrice, $note ?: null, $browser, $email]);
    json_response(true, 'Alert created.', ['id' => (int)$db->lastInsertId()]);
}

/* ── DELETE ──────────────────────────────────────────────── */
if ($action === 'stocks_alert_delete') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'ID required.');
    $s = $db->prepare("DELETE FROM stock_price_alerts WHERE id=? AND user_id=?");
    $s->execute([$id, $userId]);
    json_response(true, 'Alert deleted.');
}

/* ── TOGGLE active/pause ─────────────────────────────────── */
if ($action === 'stocks_alert_toggle') {
    $id = (int)($_POST['id'] ?? 0);
    if (!$id) json_response(false, 'ID required.');
    $s = $db->prepare(
        "UPDATE stock_price_alerts SET is_active = 1 - is_active, triggered_at = NULL WHERE id=? AND user_id=?"
    );
    $s->execute([$id, $userId]);
    json_response(true, 'Alert toggled.');
}

/* ── CHECK — compare live prices vs alerts ───────────────── */
if ($action === 'stocks_alert_check') {
    $alerts = DB::fetchAll(
        "SELECT a.*, sm.latest_price, sm.latest_price_date
         FROM stock_price_alerts a
         JOIN stock_master sm ON sm.id = a.stock_id
         WHERE a.user_id = ? AND a.is_active = 1 AND a.triggered_at IS NULL",
        [$userId]
    );

    $triggered = [];
    foreach ($alerts as $a) {
        $cp = (float)$a['latest_price'];
        $tp = (float)$a['target_price'];
        if ($cp <= 0) continue;

        $hit = false;
        switch ($a['alert_type']) {
            case 'above':   $hit = $cp >= $tp; break;
            case 'below':   $hit = $cp <= $tp; break;
            case 'pct_up':  $hit = ($cp >= $a['base_price'] * (1 + $tp/100)); break;
            case 'pct_down':$hit = ($cp <= $a['base_price'] * (1 - $tp/100)); break;
        }

        if ($hit) {
            $db->prepare(
                "UPDATE stock_price_alerts SET triggered_at=NOW(), triggered_price=?, is_active=0 WHERE id=?"
            )->execute([$cp, $a['id']]);
            $triggered[] = [
                'id'            => $a['id'],
                'symbol'        => $a['symbol'],
                'company_name'  => $a['company_name'],
                'alert_type'    => $a['alert_type'],
                'target_price'  => $tp,
                'current_price' => $cp,
                'notify_browser'=> (bool)$a['notify_browser'],
                'notify_email'  => (bool)$a['notify_email'],
            ];
        }
    }

    json_response(true, count($triggered) . ' alert(s) triggered.', [
        'triggered' => $triggered,
        'checked'   => count($alerts),
    ]);
}

json_response(false, 'Unknown action.');
